<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Http\Controllers\Controller;
use App\Models\ChannelAttentionReview;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Attention\AttentionActions;
use App\Services\SalesChannels\Attention\AttentionCollector;
use App\Services\SalesChannels\Attention\AttentionRegistry;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\Attention\AttentionSource;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Everything waiting for a person, in one place — and only the two things they may do about it.
 *
 * ## Why this screen exists
 *
 * Nine tables across five integrations can end in a state that means "no machine will touch this again": a return decision
 * whose answer was lost, a package that may have been cut, a journal line that may be in a real ledger, a payment taken for
 * the wrong amount, a refund the gateway refused. Each of those states is the right outcome and each one was invisible — a
 * pharmacist had to know to go looking in a table.
 *
 * ## What it will not do
 *
 * **It sends nothing.** Not one of these rows is retried from here. For every one of them the channel either publishes no way
 * to ask what happened, or a wrong guess costs somebody money: a customer refunded twice, a second parcel, a duplicated
 * entry, a second charge. So the actions are to go and look, and to write down what was found at the channel.
 *
 * The single exception is the stock outbox, which may be put back because its publisher recomputes a quantity and compares it
 * with what it last wrote. `AttentionActions` enforces that, not this controller: a source declared with the wrong flag is
 * refused in the service, where the rule belongs.
 *
 * Permission is the module's own (`sales_channels`), and every action is a POST so that no link on another site can take it.
 */
class ChannelAttentionController extends Controller
{
    public const ACTIONS = [AttentionSource::REVIEW, AttentionSource::PUT_BACK];

    public function __construct(
        private readonly AttentionCollector $collector,
        private readonly AttentionRegistry $registry,
        private readonly AttentionActions $actions,
        private readonly ChannelLabels $labels,
    ) {
    }

    public function index(Request $request): View
    {
        $source = requestString('source');
        $known = array_map(static fn (AttentionSource $one): string => $one->key, $this->registry->all());
        $source = in_array($source, $known, true) ? $source : null;

        return view('admin-views.sales-channels.attention', [
            'labels' => $this->labels,
            'summary' => $this->collector->summary(),
            'items' => $this->collector->items($source),
            'sources' => $this->registry->all(),
            'chosen' => $source,
            'accounts' => SalesChannelAccount::orderBy('name')->pluck('name', 'id'),
            'outcomes' => ChannelAttentionReview::OUTCOMES,
            'reviewsReady' => AttentionActions::ready(),
        ]);
    }

    /**
     * Records what a person found at the channel, or puts idempotent work back.
     *
     * The action comes from the route and is one of two; anything else never reaches here. The service checks that THIS
     * source sanctions it, so a mistake in the registry is a refusal rather than a second refund.
     *
     * **Neither action settles an operation.** A review records who looked, what they saw and what they can show for it, and
     * may take the alert off the screen; the operation's own state is left as true as it was. That separation is the whole
     * point of the rewrite: a typed note used to move a return decision to `done`.
     */
    public function act(Request $request, string $source, int $id, string $action): RedirectResponse
    {
        $note = (string) requestString('note');
        $adminId = (int) auth('admin')->id();

        $result = $action === AttentionSource::PUT_BACK
            ? $this->actions->putBack($source, $id, $adminId, $note)
            : $this->actions->review(
                sourceKey: $source,
                id: $id,
                adminId: $adminId,
                outcome: (string) requestString('outcome'),
                note: $note,
                evidence: requestString('evidence'),
                // Closing is a separate tick, because "I looked" and "stop showing me this" are different things and a
                // person who is still working on a refund should be able to say the first without the second.
                closeTheAlert: $request->boolean('close'),
            );

        // The module's own toast, like every other screen here, so a refusal is read in the same place as a success. Through
        // `SafeToast` because a refusal code is dynamic text: an unknown code would otherwise print whatever it contained.
        if ($result['ok']) {
            ToastMagic::success(translate('attention_recorded'));
        } else {
            SafeToast::error(translate((string) $result['refusal']));
        }

        return redirect()->route('admin.sales-channels.attention', ['source' => $source]);
    }
}
