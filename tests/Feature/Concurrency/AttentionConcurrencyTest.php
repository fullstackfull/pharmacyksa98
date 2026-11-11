<?php

namespace Tests\Feature\Concurrency;

use App\Models\ChannelAttentionReview;
use App\Models\ChannelReturnDecision;
use App\Models\ChannelStockEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * TWO PHARMACISTS DEALING WITH THE SAME ROW, at the same instant, against a real engine.
 *
 * Not an edge case: the attention screen is a list of things somebody has to deal with, so two people dealing with the same
 * row on a busy morning is the ordinary use of it. What must not happen is one refund carrying two contradictory findings —
 * "the channel shows it happened" and "the channel shows it did not" — or one piece of work being queued twice.
 *
 * ## Why this file exists and the unit tests were not enough
 *
 * The unit test for the stale write passed against the broken code. `putBack()` reads the row filtered by state, so once
 * another worker has moved it the read returns nothing and the refusal comes from the READ — which proves the read guard and
 * says nothing about the UPDATE's condition. Only a real interleaving, where both workers read a row that is still waiting
 * and then both write, exercises the conditional update and its affected-row count.
 *
 * Nothing here calls a channel. These actions send nothing by design, so `preventStrayRequests` is on and a request leaving
 * this host during these tests would be a defect of its own.
 *
 * Needs a real MySQL or MariaDB — SQLite in memory is private to one process, so there is nothing to race.
 */
class AttentionConcurrencyTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        $this->buildSalesChannelSchema();
        $this->buildAuditLogTable();
        Http::preventStrayRequests();
        Cache::flush();
        config(['sales_channels.enabled' => true]);
    }

    public function test_four_people_reviewing_one_refund_record_one_finding_between_them(): void
    {
        $id = $this->aFencedReturn();

        // Four people, four different findings, one row. Whichever lands first is the review; the rest are told so.
        $reports = ConcurrentRunner::run('attention.review', [
            ['source' => 'return_decisions', 'row' => $id, 'admin' => 1, 'outcome' => ChannelAttentionReview::CONFIRMED_AT_CHANNEL],
            ['source' => 'return_decisions', 'row' => $id, 'admin' => 2, 'outcome' => ChannelAttentionReview::NOT_DONE_AT_CHANNEL],
            ['source' => 'return_decisions', 'row' => $id, 'admin' => 3, 'outcome' => ChannelAttentionReview::STILL_UNKNOWN],
            ['source' => 'return_decisions', 'row' => $id, 'admin' => 4, 'outcome' => ChannelAttentionReview::NO_LONGER_RELEVANT],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, ChannelAttentionReview::count(), 'one refund carried more than one finding');
        $this->assertSame(1, $this->workersWhere($reports, static fn (array $r): bool => ($r['ok'] ?? false) === true), 'exactly one person recorded it');
        $this->assertSame(3, $this->workersWhere($reports, static fn (array $r): bool => str_starts_with((string) ($r['refusal'] ?? ''), 'attention_somebody_already_reviewed_this')), 'the other three were told who got there first');
        // And whatever they decided among themselves, the OPERATION is untouched. That is the whole design.
        $this->assertSame(ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::firstOrFail()->state);
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes really ran at the same time');
    }

    public function test_two_people_putting_back_one_stock_event_queue_it_once(): void
    {
        // **THE INTERLEAVING THE UNIT TEST COULD NOT REACH.** Both workers read a row that is still `dead`, both then write.
        // Without the state in the UPDATE's condition the second write lands on whatever the first produced, so an event
        // could be queued, worked, and queued again by a click that was already stale when it arrived.
        $id = $this->aDeadStockEvent();

        $reports = ConcurrentRunner::run('attention.put_back_after_reading', [
            ['row' => $id, 'admin' => 1, 'hold_microseconds' => 250000],
            ['row' => $id, 'admin' => 2, 'hold_microseconds' => 250000],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        // Both really did read it while it was still waiting: that is what makes this a race and not a sequence.
        $this->assertSame(2, $this->workersWhere($reports, static fn (array $r): bool => ($r['state_when_read'] ?? null) === ChannelStockEvent::DEAD));
        $this->assertSame(1, $this->workersWhere($reports, static fn (array $r): bool => ($r['ok'] ?? false) === true), 'both writes landed');
        $this->assertSame(1, ChannelAttentionReview::count(), 'two reviews for one put-back');
        $this->assertSame(ChannelStockEvent::PENDING, ChannelStockEvent::firstOrFail()->state);
        $this->assertSame(0, (int) ChannelStockEvent::firstOrFail()->attempts);
    }

    public function test_a_review_and_a_put_back_racing_for_one_row_do_not_both_land(): void
    {
        // **THE TWO ACTIONS THIS SCREEN OFFERS, ON ONE ROW, AT THE SAME INSTANT.** The stock outbox is the only source that
        // offers both -- dismiss the alert, or queue the work again -- so it is the only place this question can be asked.
        // They write different things and must still agree that ONE person dealt with it: either the alert was dismissed
        // and nothing was queued, or the work was queued and that is the record. Never both.
        $id = $this->aDeadStockEvent();

        $reports = ConcurrentRunner::run('attention.review', [
            ['source' => 'stock_events', 'row' => $id, 'admin' => 1, 'outcome' => ChannelAttentionReview::NO_LONGER_RELEVANT],
            ['op' => 'attention.put_back_after_reading', 'row' => $id, 'admin' => 2],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes really ran at the same time');
        $this->assertSame(1, $this->workersWhere($reports, static fn (array $r): bool => ($r['ok'] ?? false) === true), 'both actions landed on one row');
        $this->assertSame(1, ChannelAttentionReview::count(), 'one row carried two records of being dealt with');

        // And the operation's state agrees with whichever one won -- it was never left half moved by the loser.
        $review = ChannelAttentionReview::firstOrFail();
        $event = ChannelStockEvent::firstOrFail();
        if ($review->outcome === ChannelAttentionReview::NO_LONGER_RELEVANT) {
            $this->assertSame(ChannelStockEvent::DEAD, $event->state, 'the dismissal queued work it said was unnecessary');
            $this->assertSame(5, (int) $event->attempts, 'the dismissal reset a counter it does not own');
        } else {
            $this->assertSame(ChannelStockEvent::PENDING, $event->state);
            $this->assertSame(0, (int) $event->attempts);
        }
    }

    public function test_a_put_back_is_refused_once_somebody_has_dismissed_the_alert(): void
    {
        // The sequential half of the same rule, and the one that says WHY. A row whose alert a person closed is not waiting
        // for anybody, so the refusal is `row_is_no_longer_waiting` -- decided before anything moves -- and not a
        // uniqueness collision discovered after `dead -> pending` had already been written and had to be rolled back.
        $id = $this->aDeadStockEvent();

        $first = ConcurrentRunner::run('attention.review', [
            ['source' => 'stock_events', 'row' => $id, 'admin' => 1, 'outcome' => ChannelAttentionReview::NO_LONGER_RELEVANT],
        ]);
        $this->assertSame([], $this->problemsOf($first));
        $this->assertTrue((bool) ($first[0]['result']['ok'] ?? false), 'the dismissal itself was refused, so this test proves nothing');

        $later = ConcurrentRunner::run('attention.put_back_after_reading', [['row' => $id, 'admin' => 2]]);

        $this->assertSame([], $this->problemsOf($later));
        $this->assertFalse((bool) ($later[0]['result']['ok'] ?? true), 'the put-back landed on a row somebody had already dealt with');
        $this->assertSame('attention_row_is_no_longer_waiting', $later[0]['result']['refusal'] ?? null);
        $this->assertSame(1, ChannelAttentionReview::count());
        $this->assertSame(ChannelStockEvent::DEAD, ChannelStockEvent::firstOrFail()->state, 'the review closed the alert and left the operation alone');
        $this->assertSame(5, (int) ChannelStockEvent::firstOrFail()->attempts, 'the refused put-back still reset the counter');
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function aFencedReturn(): int
    {
        return (int) ChannelReturnDecision::create([
            'account_id' => 1, 'channel' => 'trendyol', 'claim_id' => 'CLAIM-RACE', 'claim_item_id' => 'ITEM-A',
            'external_order_id' => 'ORDER-RACE', 'decision' => ChannelReturnDecision::APPROVE,
            'state' => ChannelReturnDecision::MAY_HAVE_SENT, 'decided_by' => 1,
            'failure_code' => 'outcome_unknown_and_trendyol_offers_no_way_to_ask', 'sent_at' => now()->subHours(5),
        ])->id;
    }

    private function aDeadStockEvent(): int
    {
        return (int) ChannelStockEvent::create([
            'location_id' => 1, 'product_id' => 1, 'variant_key' => '', 'reason' => ChannelStockEvent::ORDER_IMPORTED,
            'state' => ChannelStockEvent::DEAD, 'attempts' => 5, 'failure_code' => 'the_provider_refused_five_times',
        ])->id;
    }

    /** @param list<array{ok: bool, error?: string, message?: string}> $reports */
    private function problemsOf(array $reports): array
    {
        $problems = [];
        foreach ($reports as $index => $report) {
            if (!($report['ok'] ?? false)) {
                $problems[] = "worker {$index}: " . (string) ($report['error'] ?? '?') . ' ' . (string) ($report['message'] ?? '');
            }
        }

        return $problems;
    }

    /**
     * @param list<array{ok: bool, result?: mixed}> $reports
     * @param callable(array<string, mixed>): bool $matches
     */
    private function workersWhere(array $reports, callable $matches): int
    {
        $count = 0;
        foreach ($reports as $report) {
            if (($report['ok'] ?? false) && is_array($report['result'] ?? null) && $matches($report['result'])) {
                $count++;
            }
        }

        return $count;
    }
}
