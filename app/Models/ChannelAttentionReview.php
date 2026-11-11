<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's review of one piece of work that was waiting for them.
 *
 * It records that somebody looked, what they saw, what they can show for it, and what is to happen to the ALERT. It records
 * nothing about the operation's own state, which stays on the operation's own row: an alert closed by hand leaves a return
 * decision reading `may_have_sent` for ever, because that is what happened and a typed note does not change it.
 *
 * `(source, source_row_id)` is unique, so two people cannot record two conflicting outcomes for one refund.
 *
 * @property string $source
 * @property int $source_row_id
 * @property string $outcome
 * @property string $alert_state
 */
class ChannelAttentionReview extends Model
{
    /** The channel shows the operation really happened. A person's reading of somebody else's screen — enough to stop the alert, not enough to write into our books. */
    public const CONFIRMED_AT_CHANNEL = 'confirmed_at_channel';

    /** The channel shows it did not happen. */
    public const NOT_DONE_AT_CHANNEL = 'not_done_at_channel';

    /** They looked and the channel does not say. The honest outcome for most of these, and the reason several of them exist. */
    public const STILL_UNKNOWN = 'still_unknown';

    /** The order was cancelled, the account closed, the question moot. */
    public const NO_LONGER_RELEVANT = 'no_longer_relevant';

    /** Looked at, and still shown: somebody is working on it. */
    public const REVIEWED = 'reviewed';

    /** Off the screen. **The operation's own state is untouched** — this closes the alert, not the question. */
    public const CLOSED = 'closed';

    public const OUTCOMES = [self::CONFIRMED_AT_CHANNEL, self::NOT_DONE_AT_CHANNEL, self::STILL_UNKNOWN, self::NO_LONGER_RELEVANT];

    public const ALERT_STATES = [self::REVIEWED, self::CLOSED];

    protected $table = 'channel_attention_reviews';

    protected $guarded = [];

    protected $casts = [
        'source_row_id' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /** Whether this review takes the row off the screen. */
    public function closesTheAlert(): bool
    {
        return $this->alert_state === self::CLOSED;
    }

    /**
     * The ids of one source's rows whose alert a person has already closed — as a subquery, so a caller excludes them
     * without loading them.
     *
     * It lives here because both halves of the feature have to agree on it: the collector uses it to keep a dealt-with row
     * off the screen, and the actions use it to refuse a second action on that row. Two copies of this predicate would
     * eventually disagree, and the shape of that disagreement is a row a person has dealt with that still accepts an
     * action — which for the one retryable source means queueing real work from a click that was already stale.
     *
     * @return Builder<self>
     */
    public static function closedRowIdsFor(string $source): Builder
    {
        return self::query()->select('source_row_id')->where('source', $source)->where('alert_state', self::CLOSED);
    }
}
