<?php

namespace Tests\Support\Concurrency;

use App\Models\ChannelStockEvent;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\StorefrontStockRefresher;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\Allocation\MemberShare;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocation;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\Outbox\StockEventDrain;
use App\Services\SalesChannels\Inventory\Outbox\StockEventOutbox;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Inventory\ReservationService;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use App\Services\SalesChannels\Sync\SyncStateStore;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsRemoteOrders;

/**
 * What a worker process does in the shared-stock concurrency tests. Each operation takes the arguments the test gave its process and returns something JSON-serialisable.
 * Everything here goes through the application's own services: what is being tested is how they behave when several processes use them at the same moment.
 */
final class StockOperations
{
    /**
     * Claims a batch of the stock outbox and says which event ids this process got. Two processes must never name the same id: that is what makes the outbox safe to work from
     * several workers, which is the whole point of having workers.
     *
     * @param array{limit?: int} $args
     * @return list<int>
     */
    public static function claimStockEvents(array $args): array
    {
        StockEventOutbox::$runId = '';

        return array_map(static fn (ChannelStockEvent $event): int => (int) $event->id, app(StockEventOutbox::class)->claim((int) ($args['limit'] ?? 50)));
    }

    /**
     * Works the outbox the way a queue worker really does: **until a pass takes nothing**, not once.
     *
     * It used to be a single pass, and that made the test above flaky for a reason worth writing down. Six workers each
     * claiming "up to ten of the oldest pending events" can all select the SAME ten; the claim is a compare-and-set so only
     * one wins each row, and the five losers end that single pass with almost nothing. Nobody looks again, and events are
     * left waiting -- so the assertion "between them they took every event" was really asserting that the six windows
     * happened not to overlap, which on a loaded runner they do. The observed failure was 20 of 24.
     *
     * Draining to empty is also what the real worker does: `channels:stock-events --passes` loops while a pass still claims
     * something. So this now tests the guarantee the code actually gives -- no event worked twice, and none left behind --
     * instead of the scheduling luck of one pass.
     *
     * @param array{limit?: int, passes?: int} $args
     */
    public static function drainStockEvents(array $args): array
    {
        StockEventOutbox::$runId = '';
        $limit = (int) ($args['limit'] ?? 50);
        $totals = ['claimed' => 0, 'pools' => 0, 'failed' => 0];

        // Bounded, so a bug that kept claiming for ever ends the test instead of the runner's patience.
        for ($pass = 0; $pass < max(1, (int) ($args['passes'] ?? 20)); $pass++) {
            $worked = app(StockEventDrain::class)->drain($limit);
            foreach (['claimed', 'pools', 'failed'] as $key) {
                $totals[$key] += (int) $worked[$key];
            }
            if ((int) $worked['claimed'] === 0) {
                break;
            }
        }

        return $totals;
    }

    /** One of several different operations started at the same instant: the harness runs one name for all its processes, so this one is told which to run. @param array{operation: string, arguments: array<string, mixed>} $args */
    public static function mixed(array $args): mixed
    {
        return Operations::run($args['operation'], $args['arguments'], 0);
    }

    /** @param array{location: int, product: int, variant?: string, delay_microseconds?: int} $args */
    public static function allocate(array $args): array
    {
        usleep((int) ($args['delay_microseconds'] ?? 0));

        return self::summary(app(PoolAllocationService::class)->allocate(self::key($args)));
    }

    /**
     * @param array{location: int, product: int, times: int, pause_microseconds?: int} $args
     * @return list<array{version: int, net: int|null}> what each computation of this process came to, in order
     */
    public static function allocateRepeatedly(array $args): array
    {
        $seen = [];
        for ($time = 0; $time < $args['times']; $time++) {
            $allocation = app(PoolAllocationService::class)->allocate(self::key($args));
            $seen[] = ['version' => $allocation->version, 'net' => $allocation->net];
            usleep((int) ($args['pause_microseconds'] ?? 0));
        }

        return $seen;
    }

    /**
     * @param array{location: int, product: int} $args
     * @return array{locked: list<string>, summary: array<string, mixed>} the tables a statement of this process locked rows of ("for update"), and the division
     */
    public static function allocateAndListLocks(array $args): array
    {
        $locked = [];
        DB::listen(static function ($query) use (&$locked): void {
            if (str_contains(strtolower($query->sql), ' for update') && preg_match('/from [`"]?(\w+)[`"]?/i', $query->sql, $table) === 1) {
                $locked[$table[1]] = true;
            }
        });

        $summary = self::allocate($args);

        return ['locked' => array_keys($locked), 'summary' => $summary];
    }

    /**
     * Promises `quantity` units at the pool `times` times, the way an imported order does (a hold, then the pool is marked).
     *
     * @param array{location: int, product: int, times: int, quantity: int, account: int, pause_microseconds?: int, delay_microseconds?: int} $args
     */
    public static function reserveRepeatedly(array $args): int
    {
        usleep((int) ($args['delay_microseconds'] ?? 0));
        for ($time = 1; $time <= $args['times']; $time++) {
            app(ReservationService::class)->reserve('concurrent-' . $time, $args['location'], $args['product'], '', $args['quantity'], accountId: $args['account']);
            app(DirtyPools::class)->mark([self::key($args)]);
            usleep((int) ($args['pause_microseconds'] ?? 0));
        }

        return $args['times'];
    }

    /** Takes the pool's row `FOR UPDATE` as the division does and keeps it for a while, so that a process that wants it has to wait. @param array{location: int, product: int, hold_microseconds: int} $args */
    /**
     * Holds the PRODUCT row locked for a while, so another process's storefront write has to queue behind it. The counterpart of holdPoolLock, for the product side.
     *
     * @param array{product: int, hold_microseconds: int} $args
     */
    public static function holdProductLock(array $args): bool
    {
        DB::transaction(function () use ($args): void {
            DB::table('products')->where('id', (int) $args['product'])->lockForUpdate()->first();
            usleep((int) $args['hold_microseconds']);
        });

        return true;
    }

    /**
     * Writes the storefront's own number the way a caller does it after its transaction has committed, and says what it came to. What it must come to is the figure minus
     * every hold that exists WHEN IT GETS THE LOCK — not the holds there were when it began to wait for it.
     *
     * @param array{product: int, delay_microseconds?: int} $args
     * @return array{current_stock: int}
     */
    public static function writeStorefront(array $args): array
    {
        usleep((int) ($args['delay_microseconds'] ?? 0));
        app(StorefrontStockRefresher::class)->writeFor([(int) $args['product']]);

        return ['current_stock' => (int) DB::table('products')->where('id', (int) $args['product'])->value('current_stock')];
    }

    public static function holdPoolLock(array $args): bool
    {
        DB::transaction(function () use ($args): void {
            DB::table('inventory_pools')->where(self::key($args)->columns())->lockForUpdate()->first();
            usleep($args['hold_microseconds']);
        });

        return true;
    }

    /**
     * One stock publish of an account against a channel that accepts everything.
     *
     * @param array{account: int, delay_microseconds?: int} $args
     * @return array{result: string, sent: list<array{barcode: string, quantity: int}>, skipped: array<string, int>}
     */
    public static function publish(array $args): array
    {
        usleep((int) ($args['delay_microseconds'] ?? 0));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-' . getmypid()])]);

        $outcome = app(StockPricePublisher::class)->publishStock(SalesChannelAccount::findOrFail($args['account']));
        $sent = Http::recorded(static fn (Request $request): bool => str_contains($request->url(), 'price-and-inventory'))
            ->flatMap(static fn (array $pair): array => $pair[0]->data()['items'])->map(static fn (array $item): array => ['barcode' => $item['barcode'], 'quantity' => $item['quantity']])->values()->all();

        return ['result' => $outcome['result'], 'sent' => $sent, 'skipped' => $outcome['stats']['skipped'] ?? []];
    }

    /** @param array{account: int, listing_key: string, order_id: string, quantity: int} $args the outcome of reading the order, as the poller and the webhook both do */
    public static function ingest(array $args): string
    {
        $builder = new class {
            use BuildsRemoteOrders;

            public function order(string $orderId, string $listingKey, int $quantity)
            {
                return $this->remoteOrder(orderId: $orderId, lines: [$this->remoteLine('L1', $listingKey, $listingKey, $quantity, (string) ($quantity * 100))]);
            }
        };

        return app(ChannelOrderIngestor::class)->ingest(SalesChannelAccount::findOrFail($args['account']), $builder->order($args['order_id'], $args['listing_key'], $args['quantity']))->outcome;
    }

    /**
     * Reads `count` DIFFERENT new orders of one listing one after the other, as a poller's page does, and says how many were new and what went wrong.
     *
     * @param array{account: int, listing_key: string, prefix: string, count: int, quantity: int} $args
     * @return array{created: int, deadlocks: int, other: list<string>}
     */
    public static function ingestMany(array $args): array
    {
        $report = ['created' => 0, 'deadlocks' => 0, 'other' => []];
        for ($number = 1; $number <= $args['count']; $number++) {
            try {
                $report['created'] += self::ingest($args + ['order_id' => $args['prefix'] . '-' . $number]) === 'created' ? 1 : 0;
            } catch (DeadlockException $deadlock) {
                $report['deadlocks']++;
                $report['other'][] = "order {$number}: " . mb_substr($deadlock->getMessage(), 0, 160);
            }
        }

        return $report;
    }

    /**
     * What the checkout does to stock: lock the product rows in id order and take a unit off each, in one transaction, with no retry (a deadlock must show).
     *
     * @param array{products: list<int>, times: int} $args
     */
    public static function checkout(array $args): int
    {
        $products = $args['products'];
        sort($products);
        for ($time = 0; $time < $args['times']; $time++) {
            DB::transaction(static function () use ($products): void {
                foreach ($products as $id) {
                    DB::table('products')->where('id', $id)->lockForUpdate()->first();
                }
                usleep(2000);
                foreach ($products as $id) {
                    DB::table('products')->where('id', $id)->decrement('current_stock');
                }
            }, attempts: 1);
        }

        return $args['times'];
    }

    /** Takes the right to run one resource of an account, keeps it for a while, and lets go as a finished run does. @param array{account: int, resource: string, hold_microseconds: int} $args */
    public static function holdLease(array $args): bool
    {
        $states = app(SyncStateStore::class);
        $lease = $states->acquire($args['account'], $args['resource']);
        if ($lease === null) {
            return false;
        }
        usleep($args['hold_microseconds']);
        $states->succeed($lease, null, []);

        return true;
    }

    /** @param array{account: int, resource: string} $args whether the lease was granted (and it is not given back) */
    public static function acquire(array $args): bool
    {
        return app(SyncStateStore::class)->acquire($args['account'], $args['resource']) !== null;
    }

    /** @param array<string, mixed> $args */
    private static function key(array $args): PoolKey
    {
        return new PoolKey((int) $args['location'], (int) $args['product'], (string) ($args['variant'] ?? ''));
    }

    /** @return array{version: int, net: int|null, reserve: int, entitlements: array<int, int>, publish: array<int, int>} */
    private static function summary(PoolAllocation $allocation): array
    {
        return [
            'version' => $allocation->version,
            'net' => $allocation->net,
            'reserve' => $allocation->storefrontReserve,
            'entitlements' => array_map(static fn (MemberShare $share): int => $share->entitlement, $allocation->shares),
            'publish' => array_map(static fn (MemberShare $share): int => $share->publish(), $allocation->shares),
        ];
    }
}
