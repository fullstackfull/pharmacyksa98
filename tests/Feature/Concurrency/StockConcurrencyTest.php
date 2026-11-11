<?php

namespace Tests\Feature\Concurrency;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ChannelStockEvent;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryPool;
use App\Models\InventoryReservation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\Outbox\StockEventOutbox;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Inventory\PoolNet;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use App\Services\SalesChannels\Sync\SyncStateStore;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * The shared-stock code under several PHP processes at the same instant, against a real MySQL or MariaDB: one division however many ask for it, a reader never sees a hold half
 * written, two accounts publishing one pool together never show more than it has, one order read twice at once is one hold, the checkout's own locks and the division never
 * deadlock, and one resource of one account is run by one process at a time. Needs a real engine: SQLite in memory is private to one process.
 */
class StockConcurrencyTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 50;

    private SalesChannelAccount $first;

    private SalesChannelAccount $second;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Cache::flush();
        $this->first = $this->trendyolAccount(sellerId: '1234567');
        $this->second = $this->trendyolAccount(sellerId: '7654321');
        $this->storeProduct(self::PRODUCT);
        $this->providerFigure(self::PRODUCT, 20, now()->subMinutes(30));
    }

    private function key(int $product = self::PRODUCT): PoolKey
    {
        return new PoolKey($this->torodLocation()->id, $product);
    }

    /** @return array{location: int, product: int} */
    private function pool(int $product = self::PRODUCT): array
    {
        return ['location' => $this->torodLocation()->id, 'product' => $product];
    }

    /** @param list<array{ok: bool, result?: mixed, error?: string, message?: string}> $reports */
    private function assertAllWorkersSucceeded(array $reports): void
    {
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "worker {$index} failed: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
    }

    // ---- T1: one division however many ask for it ---------------------------------------------------------------------------------------------

    public function test_six_processes_dividing_one_pool_at_once_give_the_serial_result_and_one_version(): void
    {
        $this->storeOrder(1, self::PRODUCT, 3);
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->stockListing($this->second, self::PRODUCT, $this->torodLocation(), 'BAR-B');

        $reports = ConcurrentRunner::run('stock.allocate', array_fill(0, 6, $this->pool()));

        $this->assertAllWorkersSucceeded($reports);
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes ran at the same time');
        $results = array_column($reports, 'result');
        $this->assertCount(1, array_unique(array_map('json_encode', $results)), 'every process was given the same division');
        $this->assertSame(17, $results[0]['net']);
        $this->assertLessThanOrEqual(17, $results[0]['reserve'] + array_sum($results[0]['entitlements']), 'never more than the net');
        $this->assertSame(1, InventoryPool::count(), 'six processes made one pool row');
        $this->assertSame(1, (int) $this->poolRow()->version, 'the division changed once: from nothing to what it is');

        $serial = app(PoolAllocationService::class)->allocate($this->key());
        $this->assertSame([$results[0]['version'], $results[0]['net']], [$serial->version, $serial->net], 'the serial result is the concurrent one');
        $this->assertSame(array_map(static fn ($share): int => $share->publish(), $serial->shares), array_map('intval', $results[0]['publish']));
        $this->assertSame(1, SalesChannelProductMapping::where('listing_key', 'BAR-A')->firstOrFail()->allocation['version']);
    }

    private function poolRow(): InventoryPool
    {
        return InventoryPool::where($this->key()->columns())->firstOrFail();
    }

    // ---- T2: a hold is never read half written -------------------------------------------------------------------------------------------------

    public function test_allocations_made_beside_a_writer_of_holds_always_read_the_latest_holds_never_a_snapshot_from_before_the_lock(): void
    {
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->stockListing($this->second, self::PRODUCT, $this->torodLocation(), 'BAR-B');
        $writer = $this->pool() + ['times' => 12, 'quantity' => 1, 'account' => $this->first->id, 'pause_microseconds' => 60000];
        $reader = $this->pool() + ['times' => 14, 'pause_microseconds' => 40000];

        $reports = $this->runTogether([['stock.reserve_repeatedly', $writer], ['stock.allocate_repeatedly', $reader], ['stock.allocate_repeatedly', $reader], ['stock.allocate_repeatedly', $reader]]);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the writer and the readers ran at the same time');
        foreach (array_slice($reports, 1) as $index => $report) {
            $nets = array_column($report['result'], 'net');
            $versions = array_column($report['result'], 'version');
            $this->assertSame($nets, $this->sortedDescending($nets), "reader {$index}: holds only ever grow, so the net it computed never goes up: it always read the latest, not a snapshot from before the lock");
            $this->assertSame($versions, $this->sortedAscending($versions), "reader {$index}: versions only move forward");
            foreach ($nets as $net) {
                $this->assertGreaterThanOrEqual(8, $net, 'no more than 12 holds exist');
                $this->assertLessThanOrEqual(20, $net);
            }
        }
        $final = app(PoolAllocationService::class)->allocate($this->key());
        $this->assertSame(8, $final->net);
        $this->assertSame((int) $this->poolRow()->version, $final->version);
        $this->assertLessThanOrEqual(8, array_sum(array_map(static fn ($share): int => $share->entitlement, $final->shares)));
        $this->assertSame(12, (int) DB::table('inventory_reservations')->sum('quantity'));
    }

    public function test_a_division_waiting_for_the_pool_lock_reads_its_inputs_after_it_was_granted_it(): void
    {
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        app(PoolAllocationService::class)->allocate($this->key());
        $holder = $this->pool() + ['hold_microseconds' => 2500000];
        $waiting = $this->pool() + ['delay_microseconds' => 400000];
        $writer = $this->pool() + ['times' => 1, 'quantity' => 4, 'account' => $this->first->id, 'delay_microseconds' => 1200000];

        $reports = $this->runTogether([['stock.hold_pool_lock', $holder], ['stock.allocate', $waiting], ['stock.reserve_repeatedly', $writer]]);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertSame(16, $reports[1]['result']['net'], 'a hold of 4 units was committed while this process waited for the lock: what it divides is 20 - 4, not what was there when it began to wait');
        $this->assertSame(16, (int) $this->poolRow()->last_net);
        $this->assertSame(16, SalesChannelProductMapping::firstOrFail()->allocation['net']);
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $jobs different operations, started at the same instant */
    private function runTogether(array $jobs): array
    {
        // The harness runs one operation name for all its processes: a process of another operation is told what to run through its arguments.
        return ConcurrentRunner::run('stock.mixed', array_map(static fn (array $job): array => ['operation' => $job[0], 'arguments' => $job[1]], $jobs));
    }

    /** @param list<int|null> $values @return list<int|null> */
    private function sortedDescending(array $values): array
    {
        rsort($values);

        return $values;
    }

    /** @param list<int|null> $values @return list<int|null> */
    private function sortedAscending(array $values): array
    {
        sort($values);

        return $values;
    }

    // ---- T3: two accounts publishing one pool ----------------------------------------------------------------------------------------------------

    public function test_two_accounts_publishing_one_pool_at_once_show_no_more_in_total_than_the_net(): void
    {
        $this->storeOrder(1, self::PRODUCT, 3);
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->stockListing($this->second, self::PRODUCT, $this->torodLocation(), 'BAR-B');

        $reports = $this->runTogether([['stock.publish', ['account' => $this->first->id]], ['stock.publish', ['account' => $this->second->id]]]);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertTrue(ConcurrentRunner::overlapped($reports));
        $this->assertSame(['ok', 'ok'], array_column(array_column($reports, 'result'), 'result'));
        $sent = array_merge(...array_column(array_column($reports, 'result'), 'sent'));
        $this->assertSame(2, count($sent));
        $this->assertLessThanOrEqual(17, array_sum(array_column($sent, 'quantity')), '20 on the shelf, 3 held by a store order: at most 17 in total');
        $this->assertSame([8, 8], array_map(static fn ($listing): int => (int) $listing->last_published_quantity, SalesChannelProductMapping::orderBy('id')->get()->all()));
        $this->assertSame(1, (int) $this->poolRow()->version, 'both planned from the same division');
    }

    public function test_publishers_racing_a_writer_of_holds_settle_within_the_net_once_the_next_runs_have_gone_out(): void
    {
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->stockListing($this->second, self::PRODUCT, $this->torodLocation(), 'BAR-B');
        $writer = $this->pool() + ['times' => 10, 'quantity' => 1, 'account' => $this->first->id, 'pause_microseconds' => 30000];

        $reports = $this->runTogether([['stock.publish', ['account' => $this->first->id]], ['stock.publish', ['account' => $this->second->id]], ['stock.reserve_repeatedly', $writer]]);

        $this->assertAllWorkersSucceeded($reports);
        // The sale that arrived while they were sending is in the next cycle: the regular run lowers whatever was sent on an older division.
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-serial'])]);
        foreach ([$this->first, $this->second, $this->first, $this->second] as $account) {
            $this->assertSame('ok', app(StockPricePublisher::class)->publishStock($account)['result']);
        }
        $net = app(PoolNet::class)->for($this->torodLocation(), self::PRODUCT)->net();
        $shown = SalesChannelProductMapping::pluck('last_published_quantity')->map(fn ($quantity): int => (int) $quantity)->sum();
        $this->assertSame(10, $net);
        $this->assertLessThanOrEqual($net, $shown, 'what the channels are shown together is within what is left');
    }

    // ---- T4: one order read twice at once --------------------------------------------------------------------------------------------------------

    public function test_the_same_order_read_by_the_poller_and_the_webhook_at_once_is_one_order_and_one_hold(): void
    {
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $order = ['account' => $this->first->id, 'listing_key' => 'BAR-A', 'order_id' => 'ORD-9', 'quantity' => 2];

        $reports = ConcurrentRunner::run('stock.ingest', array_fill(0, 2, $order));

        $this->assertAllWorkersSucceeded($reports);
        $this->assertTrue(ConcurrentRunner::overlapped($reports));
        $outcomes = array_count_values(array_column($reports, 'result'));
        $this->assertSame(1, $outcomes['created'] ?? 0, 'exactly one process created the order: ' . json_encode($outcomes));
        $this->assertSame([1, 1], [ExternalOrderMapping::count(), ExternalOrderLine::count()]);
        $this->assertSame(1, InventoryReservation::count(), 'one hold for the one line, whoever got there first');
        $this->assertSame(2, (int) InventoryReservation::firstOrFail()->quantity);
        $this->assertSame(1, InventoryPool::count());
        $this->assertSame(18, app(PoolNet::class)->for($this->torodLocation(), self::PRODUCT)->net());
    }

    /**
     * Five readers at once is more than a poller and a webhook, and the engine may pick one of them as a deadlock victim more often than the three tries the ingestion makes
     * (an ingestion of a NEW order takes a gap lock and then inserts into it: with several contenders that is the textbook deadlock). That is allowed here, because the
     * redelivery recovers it; what is not allowed is a second order, a second hold, or any failure that is not a deadlock.
     */
    public function test_many_readers_of_one_new_order_may_lose_a_deadlock_but_never_make_a_second_order_or_a_second_hold(): void
    {
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $order = ['account' => $this->first->id, 'listing_key' => 'BAR-A', 'order_id' => 'ORD-9', 'quantity' => 2];

        $reports = ConcurrentRunner::run('stock.ingest', array_fill(0, 5, $order));

        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'] || ($report['error'] ?? '') === DeadlockException::class, "worker {$index} failed with something other than a deadlock: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
        $this->assertLessThanOrEqual(1, ExternalOrderMapping::count());
        $this->assertLessThanOrEqual(1, InventoryReservation::count());

        $redelivery = app(ChannelOrderIngestor::class)->ingest($this->first, $this->remoteOrder(orderId: 'ORD-9', lines: [$this->remoteLine('L1', 'BAR-A', 'BAR-A', 2, '200')]));
        $this->assertContains($redelivery->outcome, ['created', 'unchanged', 'updated']);
        $this->assertSame([1, 1, 1], [ExternalOrderMapping::count(), ExternalOrderLine::count(), InventoryReservation::count()], 'once the order has been read again it is one order, one line, one hold');
        $this->assertSame(18, app(PoolNet::class)->for($this->torodLocation(), self::PRODUCT)->net());
    }

    // ---- T5: the checkout's locks and the division ------------------------------------------------------------------------------------------------

    public function test_the_division_never_locks_products_and_never_deadlocks_with_a_checkout_that_does(): void
    {
        foreach ([101, 102] as $product) {
            $this->storeProduct($product, 'torod_point', 500);
            $this->providerFigure($product, 20, now()->subMinutes(30));
            $this->stockListing($this->first, $product, $this->torodLocation(), 'BAR-' . $product);
            $this->stockListing($this->second, $product, $this->torodLocation(), 'BAR-S-' . $product);
        }
        $checkout = ['products' => [102, 101], 'times' => 40];
        $allocate = fn (int $product): array => $this->pool($product) + ['times' => 25, 'pause_microseconds' => 3000];

        $reports = $this->runTogether([['stock.checkout', $checkout], ['stock.checkout', $checkout], ['stock.allocate_repeatedly', $allocate(101)], ['stock.allocate_repeatedly', $allocate(102)], ['stock.allocate_repeatedly', $allocate(101)]]);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertTrue(ConcurrentRunner::overlapped($reports));
        $this->assertSame([420, 420], [(int) DB::table('products')->where('id', 101)->value('current_stock'), (int) DB::table('products')->where('id', 102)->value('current_stock')], '80 checkouts took a unit each: none was lost or refused');

        $listing = ConcurrentRunner::run('stock.allocate_and_list_locks', [$this->pool(101)]);
        $this->assertAllWorkersSucceeded($listing);
        $this->assertSame(['inventory_pools'], $listing[0]['result']['locked'], 'the only row the division locks is the pool\'s own');
    }

    // ---- T6: leases --------------------------------------------------------------------------------------------------------------------------------

    public function test_one_resource_of_one_account_is_run_by_one_process_at_a_time(): void
    {
        $job = ['account' => $this->first->id, 'resource' => 'publish_stock', 'hold_microseconds' => 1500000];

        $reports = ConcurrentRunner::run('stock.hold_lease', array_fill(0, 6, $job));

        $this->assertAllWorkersSucceeded($reports);
        $granted = array_column($reports, 'result');
        $this->assertSame(1, count(array_keys($granted, true, true)), 'six processes asked at once; one was given the lease');
        $this->assertSame(5, count(array_keys($granted, false, true)));
        $this->assertTrue(app(SyncStateStore::class)->acquire($this->first->id, 'publish_stock') !== null, 'the lease is free again once the run is over');
    }

    public function test_a_publish_run_that_meets_a_run_in_progress_is_told_it_is_busy_and_sends_nothing(): void
    {
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $hold = ['account' => $this->first->id, 'resource' => 'publish_stock', 'hold_microseconds' => 2500000];

        $reports = $this->runTogether([['stock.hold_lease', $hold], ['stock.publish', ['account' => $this->first->id, 'delay_microseconds' => 800000]]]);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertTrue($reports[0]['result']);
        $this->assertSame('busy', $reports[1]['result']['result']);
        $this->assertSame([], $reports[1]['result']['sent']);
        $this->assertSame(0, InventoryPool::count(), 'a run that was not given the lease did not touch the pool either');
    }

    // ---- T8: the outbox under several workers -------------------------------------------------------------------------------------------------

    public function test_six_workers_claiming_the_outbox_at_once_each_get_their_own_events_and_none_is_taken_twice(): void
    {
        $this->givenEventsWaiting(30);

        $reports = ConcurrentRunner::run('stock.claim_events', array_fill(0, 6, ['limit' => 10]));

        $this->assertAllWorkersSucceeded($reports);
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes ran at the same time');
        $claimed = array_merge(...array_column($reports, 'result'));
        $this->assertSame(count($claimed), count(array_unique($claimed)), 'two workers were given the same event: it would be worked twice');
        $this->assertLessThanOrEqual(30, count($claimed));
        $this->assertSame(count($claimed), ChannelStockEvent::where('state', ChannelStockEvent::CLAIMED)->count());
    }

    public function test_six_workers_draining_the_outbox_at_once_leave_nothing_waiting_and_work_nothing_twice(): void
    {
        $this->givenEventsWaiting(24);

        $reports = ConcurrentRunner::run('stock.drain_events', array_fill(0, 6, ['limit' => 10]));

        $this->assertAllWorkersSucceeded($reports);
        $claimed = array_sum(array_column(array_column($reports, 'result'), 'claimed'));
        // Exactly 24, over however many passes the six of them needed. More than 24 would mean an event was worked twice;
        // fewer would mean one was left behind. Both are the real guarantees, and neither depends on how the passes
        // interleaved -- which is what made this assertion flaky when each worker made a single pass.
        $this->assertSame(24, $claimed, 'the workers between them took every event exactly once: ' . json_encode(array_column($reports, 'result')));
        $this->assertSame(0, ChannelStockEvent::where('state', ChannelStockEvent::PENDING)->count(), 'an event was left waiting');
        $this->assertSame(24, ChannelStockEvent::where('state', ChannelStockEvent::WORKED)->count());
    }

    /**
     * Six processes each holding units of the SAME product at the same instant. This is the test that found the defect: our own shop's number USED to be written inside each
     * hold's own transaction, and the reads that count what is promised therefore saw that transaction's snapshot — taken before the other five committed their holds. Each
     * process wrote "twenty minus my two", and the shop went on offering ten units that were gone.
     *
     * It is written after the commit now, in a transaction of its own, so each pass counts every hold that exists. What this pins: no deadlock from a wrong lock order, no
     * unit taken off twice (the number is RECOMPUTED, never decremented), and the final number right to the unit.
     */
    public function test_six_processes_holding_units_of_one_product_at_once_leave_our_own_number_right_and_never_deadlock(): void
    {
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        app(InventorySyncService::class)->applyToAssignedProducts(ProviderCode::TorodPoint, ['ext-' . self::PRODUCT => 20], now());
        $this->assertSame(20, $this->storefrontStock());

        // Not a static closure: it reads $this->first. (A static one has no $this, and SQLite skips this test, so only a real engine shows it.)
        $accountId = (int) $this->first->id;
        $reports = ConcurrentRunner::run('stock.ingest_many', array_map(static fn (int $worker): array => [
            'account' => $accountId, 'listing_key' => 'BAR-A', 'quantity' => 2, 'count' => 1, 'prefix' => 'CONC-' . $worker,
        ], range(1, 6)));

        $this->assertAllWorkersSucceeded($reports);
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes ran at the same time');
        $this->assertSame(0, array_sum(array_column(array_column($reports, 'result'), 'deadlocks')), 'a deadlock got through: ' . json_encode(array_column($reports, 'result')));
        $held = (int) InventoryReservation::where('product_id', self::PRODUCT)->where('state', InventoryReservation::RESERVED)->sum('quantity');
        $this->assertSame(12, $held, 'six orders of two units each are twelve units held');
        $this->assertSame(20 - $held, $this->storefrontStock(), 'our own number is the figure minus what is promised, once — not a decrement applied twice');
    }

    /**
     * The mechanism the fix above rests on, pinned on its own rather than argued in a comment: a storefront write that has to QUEUE for the product's row lock reads what is
     * promised AFTER it is granted it. Here a holder keeps the product locked for two and a half seconds; the write starts waiting almost at once; and a hold of four units
     * is committed a second later, while the write is still blocked. If its reservation count came from a snapshot taken when it began to wait, it would write twenty.
     *
     * This is the product-side twin of test_a_division_waiting_for_the_pool_lock_reads_its_inputs_after_it_was_granted_it, and it is what makes the write safe to call from
     * any caller: `writeStoreStock` takes the product's lock as the FIRST statement of its transaction, so the read view for the counts is created after the wait.
     */
    public function test_a_storefront_write_waiting_for_the_products_lock_counts_the_holds_committed_while_it_waited(): void
    {
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        app(InventorySyncService::class)->applyToAssignedProducts(ProviderCode::TorodPoint, ['ext-' . self::PRODUCT => 20], now());
        $this->assertSame(20, $this->storefrontStock());

        $holder = ['product' => self::PRODUCT, 'hold_microseconds' => 2500000];
        $waiting = ['product' => self::PRODUCT, 'delay_microseconds' => 400000];
        $writer = $this->pool() + ['times' => 1, 'quantity' => 4, 'account' => $this->first->id, 'delay_microseconds' => 1200000];

        $reports = $this->runTogether([['stock.hold_product_lock', $holder], ['stock.write_storefront', $waiting], ['stock.reserve_repeatedly', $writer]]);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertSame(16, $reports[1]['result']['current_stock'], 'a hold of 4 units was committed while this write waited for the product lock: what it wrote is 20 - 4, not what was promised when it began to wait');
        $this->assertSame(16, $this->storefrontStock());
        $this->assertSame(4, (int) InventoryReservation::where('product_id', self::PRODUCT)->where('state', InventoryReservation::RESERVED)->sum('quantity'));
    }

    private function storefrontStock(): int
    {
        return (int) DB::table('products')->where('id', self::PRODUCT)->value('current_stock');
    }

    /** `$count` changes waiting in the outbox, on as many pools, each recorded the way a real change records it. */
    private function givenEventsWaiting(int $count): void
    {
        $outbox = app(StockEventOutbox::class);
        for ($number = 1; $number <= $count; $number++) {
            $product = 400 + $number;
            $this->storeProduct($product);
            DB::transaction(fn (): int => $outbox->record([new PoolKey($this->torodLocation()->id, $product)], ChannelStockEvent::ORDER_IMPORTED));
        }
        $this->assertSame($count, ChannelStockEvent::where('state', ChannelStockEvent::PENDING)->count());
    }
}
