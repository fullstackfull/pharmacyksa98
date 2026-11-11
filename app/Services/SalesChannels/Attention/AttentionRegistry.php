<?php

namespace App\Services\SalesChannels\Attention;

use App\Models\AmazonReportRun;
use App\Models\ChannelOrderDispatch;
use App\Models\ChannelPackageSplit;
use App\Models\ChannelReturnDecision;
use App\Models\ChannelShipmentSend;
use App\Models\ChannelStockEvent;
use App\Models\ExternalOrderMapping;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahRefund;
use App\Models\OdooPosting;
use Illuminate\Support\Facades\Schema;

/**
 * Every place in this system where work comes to rest waiting for a person.
 *
 * ## What this list is really a list of
 *
 * Each integration here was built so that an uncertain outcome STOPS rather than guesses: a return decision whose answer was
 * lost is never decided again, a package that may have been cut is never cut again, a journal entry that may have posted is
 * never posted again. That discipline is the right one and it has a cost nobody had paid: the rows pile up somewhere only the
 * person who wrote the code knows to look. This is the list that makes them one screen.
 *
 * ## Read the `retryable` column before anything else
 *
 * **Nine of the ten sources are not retryable, and that is the point.** For each of them the channel either publishes no
 * way to ask what became of the request, or the price of assuming wrong is somebody's money: a customer refunded twice, a
 * parcel shipped twice, a payment taken twice, an entry posted twice into a real ledger. So the only actions are to look and
 * to record what was found.
 *
 * The one exception is the stock outbox, and it is an exception for a reason that can be stated: the publisher recomputes a
 * quantity and compares it with what it last wrote, so working an event again cannot take units off twice. Idempotent by
 * construction, not by hope.
 *
 * A source whose migration has not run is skipped, not failed: these tables ship dormant.
 */
class AttentionRegistry
{
    /** @return list<AttentionSource> */
    public function all(): array
    {
        return array_values(array_filter($this->declared(), static fn (AttentionSource $source): bool => Schema::hasTable($source->table())));
    }

    public function find(string $key): ?AttentionSource
    {
        foreach ($this->all() as $source) {
            if ($source->key === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The whole list, whether or not its tables exist yet.
     *
     * @return list<AttentionSource>
     */
    public function declared(): array
    {
        return [
            // ---- returns ------------------------------------------------------------------------------------------
            // The worst of the ten. Approving refunds a customer and rejecting refuses them, Trendyol documents no
            // idempotency for either, and -- alone among everything here -- publishes NO WAY TO ASK what became of one
            // claim-item decision. So a fenced row has nothing a machine can do with it, ever.
            new AttentionSource(
                key: 'return_decisions',
                domain: 'returns',
                model: ChannelReturnDecision::class,
                states: [ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::NEEDS_REVIEW],
                columns: ['reference' => 'claim_id', 'account' => 'account_id', 'reason' => 'failure_code', 'detail' => 'failure_detail', 'since' => 'sent_at', 'order' => 'external_order_id'],
                actions: [AttentionSource::OPEN, AttentionSource::REVIEW],
                retryable: false,
            ),

            // ---- shipments ----------------------------------------------------------------------------------------
            // A cut that may have landed. The package's own status IS the answer, so a machine checks for a while; a row that
            // reaches here is one whose proof never came, and cutting again would be extra labels and extra courier charges.
            new AttentionSource(
                key: 'package_splits',
                domain: 'shipments',
                model: ChannelPackageSplit::class,
                states: [ChannelPackageSplit::NEEDS_REVIEW],
                columns: ['reference' => 'package_id', 'account' => 'account_id', 'reason' => 'failure_code', 'detail' => 'failure_detail', 'since' => 'sent_at', 'order' => 'external_order_id'],
                actions: [AttentionSource::OPEN, AttentionSource::REVIEW],
                retryable: false,
            ),
            // A waybill or a status that may already have reached the provider or the channel.
            new AttentionSource(
                key: 'shipment_sends',
                domain: 'shipments',
                model: ChannelShipmentSend::class,
                states: [ChannelShipmentSend::MAY_HAVE_SENT, ChannelShipmentSend::NEEDS_REVIEW],
                columns: ['reference' => 'reference', 'account' => 'account_id', 'reason' => 'reason_code', 'since' => 'sent_at', 'order' => 'external_order_id'],
                actions: [AttentionSource::OPEN, AttentionSource::REVIEW],
                retryable: false,
            ),
            // An order the dispatcher refused to carry, or stopped carrying. Nothing was sent, but the reason is a person's to
            // resolve (a missing address, a provider with no mapping, a changed order). No action here sends anything: the
            // dispatcher's own screen is where a person acts, so this one only points at it.
            new AttentionSource(
                key: 'dispatches',
                domain: 'shipments',
                model: ChannelOrderDispatch::class,
                states: ['blocked', 'failed'],
                columns: ['reference' => 'mapping_id', 'account' => 'account_id', 'reason' => 'reason_code', 'detail' => 'provider', 'since' => 'last_attempt_at'],
                actions: [AttentionSource::OPEN],
                retryable: false,
            ),

            // ---- stock --------------------------------------------------------------------------------------------
            // **The one retryable source.** A stock event recomputes a quantity and the publisher compares it with what it
            // last wrote, so working it again cannot take units off twice. Idempotent by construction, which is why putting
            // it back is offered here and nowhere else.
            new AttentionSource(
                key: 'stock_events',
                domain: 'stock',
                model: ChannelStockEvent::class,
                states: [ChannelStockEvent::DEAD],
                columns: ['reference' => 'reason', 'reason' => 'failure_code', 'detail' => 'publish_detail', 'since' => 'updated_at'],
                // REVIEW as well as PUT_BACK, and it is the only source that offers both. Without it the one way a dead
                // stock event could leave this screen was to queue the work again -- so a person who looked, found the
                // provider's number already right and concluded there was nothing to send was pushed into sending it
                // anyway. Dismissing an alert and re-doing the work are different findings and now have different buttons.
                actions: [AttentionSource::OPEN, AttentionSource::REVIEW, AttentionSource::PUT_BACK],
                retryable: true,
                pendingState: ChannelStockEvent::PENDING,
            ),

            // ---- accounting ---------------------------------------------------------------------------------------
            // A line that may already be in somebody's real ledger. Odoo documents no idempotency mechanism at all, and the
            // adopted policy is explicit: after an ambiguous result the entry is NEVER created again, even if a search comes
            // back empty, until the outcome is proven.
            new AttentionSource(
                key: 'odoo_postings',
                domain: 'accounting',
                model: OdooPosting::class,
                states: [OdooPosting::MAY_HAVE_POSTED, OdooPosting::NEEDS_REVIEW],
                columns: ['reference' => 'odoo_ref', 'reason' => 'failure_code', 'detail' => 'failure_detail', 'since' => 'needs_review_since', 'order' => 'source_id'],
                actions: [AttentionSource::OPEN, AttentionSource::REVIEW],
                retryable: false,
            ),

            // ---- finance reading ----------------------------------------------------------------------------------
            // A report request whose reply was lost. Amazon allows one `createReport` a minute, so asking again is both
            // expensive and wrong: the report may exist and the only honest recovery is discovery through `getReports`.
            new AttentionSource(
                key: 'amazon_reports',
                domain: 'finance',
                model: AmazonReportRun::class,
                states: [AmazonReportRun::UNKNOWN],
                columns: ['reference' => 'report_type', 'account' => 'account_id', 'reason' => 'failure_code', 'detail' => 'failure_detail', 'since' => 'requested_at'],
                actions: [AttentionSource::OPEN, AttentionSource::REVIEW],
                retryable: false,
            ),

            // ---- payments -----------------------------------------------------------------------------------------
            // The gateway took an amount that is not the amount we asked for. Nothing here re-charges anybody: a person reads
            // both figures and decides.
            new AttentionSource(
                key: 'payment_mismatches',
                domain: 'payments',
                model: MyFatoorahPaymentAttempt::class,
                states: ['mismatch'],
                columns: ['reference' => 'invoice_id', 'reason' => 'status', 'detail' => 'last_error_message', 'since' => 'last_verified_at'],
                actions: [AttentionSource::OPEN, AttentionSource::REVIEW],
                retryable: false,
            ),
            // A refund the gateway refused or failed to make. A customer is owed money and no machine tries again.
            new AttentionSource(
                key: 'refunds',
                domain: 'payments',
                model: MyFatoorahRefund::class,
                states: [MyFatoorahRefund::STATUS_FAILED, MyFatoorahRefund::STATUS_REJECTED],
                columns: ['reference' => 'myfatoorah_reference', 'reason' => 'status', 'detail' => 'last_error_message', 'since' => 'requested_at'],
                actions: [AttentionSource::OPEN, AttentionSource::REVIEW],
                retryable: false,
            ),

            // ---- orders -------------------------------------------------------------------------------------------
            // A channel order whose link to a shipment or a product nobody could settle. Linking is a person's judgement and
            // this only points at the order.
            new AttentionSource(
                key: 'order_links',
                domain: 'orders',
                // Its state lives in `shipment_link_state`, not `state`: this table's `state` means the ORDER's status.
                stateColumn: 'shipment_link_state',
                model: ExternalOrderMapping::class,
                states: [ExternalOrderMapping::LINK_NEEDS_REVIEW],
                columns: ['reference' => 'external_order_id', 'account' => 'account_id', 'reason' => 'review_reason', 'detail' => 'shipment_link_state', 'since' => 'updated_at'],
                actions: [AttentionSource::OPEN],
                retryable: false,
            ),
        ];
    }
}
