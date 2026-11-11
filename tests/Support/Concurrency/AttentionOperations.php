<?php

namespace Tests\Support\Concurrency;

use App\Models\ChannelAttentionReview;
use App\Models\ChannelStockEvent;
use App\Services\SalesChannels\Attention\AttentionActions;

/**
 * What a worker process does in the attention-screen concurrency tests.
 *
 * Two pharmacists with the screen open is the ordinary case, not an edge one: the page is a list of things somebody has to
 * deal with, so two people dealing with the same row at the same moment is exactly what happens on a busy morning. What must
 * not happen is one refund carrying two contradictory findings, or one piece of work being queued twice.
 *
 * Nothing here calls a channel: the whole point of these actions is that they send nothing. So there is no HTTP fake, and a
 * request leaving this host during these tests would be a defect of its own.
 */
final class AttentionOperations
{
    /**
     * Records a review of one row, with a pause a test can use to put one worker inside the window another races through.
     *
     * @param array{source: string, row: int, admin: int, outcome: string, hold_microseconds?: int} $args
     * @return array{ok: bool, refusal: string|null, pid: int}
     */
    public static function review(array $args): array
    {
        config(['sales_channels.enabled' => true]);
        usleep((int) ($args['hold_microseconds'] ?? 0));

        $result = app(AttentionActions::class)->review(
            sourceKey: (string) $args['source'],
            id: (int) $args['row'],
            adminId: (int) $args['admin'],
            outcome: (string) ($args['outcome'] ?? ChannelAttentionReview::STILL_UNKNOWN),
            note: 'worker ' . getmypid() . ' looked at the channel',
            evidence: 'EVIDENCE-' . getmypid(),
            closeTheAlert: true,
        );

        return ['ok' => $result['ok'], 'refusal' => $result['refusal'], 'pid' => getmypid()];
    }

    /**
     * Puts one stock event back, the one action on this screen that queues work again.
     *
     * The interleaving this exists for: both workers READ a row that is still `dead`, and then both write. Without the state
     * in the UPDATE's condition the second write lands on whatever the first produced, so an event could be queued, worked,
     * and queued again by a click that was already stale when it arrived.
     *
     * @param array{row: int, admin: int, hold_microseconds?: int} $args
     * @return array{ok: bool, refusal: string|null, state_when_read: string|null, pid: int}
     */
    public static function putBackAfterReading(array $args): array
    {
        config(['sales_channels.enabled' => true]);

        // The READ half, as the service does it, before the window.
        $seen = ChannelStockEvent::find((int) $args['row']);
        usleep((int) ($args['hold_microseconds'] ?? 0));

        // ... and the WRITE half, after the world has moved on.
        $result = app(AttentionActions::class)->putBack('stock_events', (int) $args['row'], (int) $args['admin'], 'worker ' . getmypid() . ' says the provider is answering');

        return [
            'ok' => $result['ok'],
            'refusal' => $result['refusal'],
            'state_when_read' => $seen?->state,
            'pid' => getmypid(),
        ];
    }
}
