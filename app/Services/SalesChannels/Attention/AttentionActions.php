<?php

namespace App\Services\SalesChannels\Attention;

use App\Models\ChannelAttentionReview;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * The only things a person may do to a row that is waiting for them — and the four things it keeps carefully apart.
 *
 * ## What was wrong before
 *
 * `acknowledge()` moved a return decision to `done`, a package split to `confirmed` and an Odoo posting to `posted`, on the
 * strength of a typed note. **A sentence is not proof that a customer was refunded, that a parcel was cut, or that a line is
 * in somebody's real ledger.** It was worse than optimistic: the writer silently dropped any column a table did not have,
 * and nine of the ten source tables have no review columns — so the note the screen calls "the record" was thrown away
 * together with the reviewer's name, leaving only an audit line whose writer swallows its own failures. The screen could
 * report a settled refund and keep nothing whatever about it.
 *
 * ## The four questions, now separate
 *
 *  1. **Did somebody look?** — a review row: who, when, against which reference.
 *  2. **What did they see?** — `outcome`, from a fixed vocabulary, never free text.
 *  3. **What can they show for it?** — `evidence`, the channel's own reference, where the channel publishes one at all.
 *  4. **What is the operation's state?** — **untouched.** It stays as true as it ever was. An alert closed by hand leaves a
 *     return decision reading `may_have_sent` for ever, because that is what happened.
 *
 * So nothing here writes `done`, `confirmed` or `posted`. A person reading somebody else's screen is good enough to stop an
 * alert nagging and is not a settlement; a settlement belongs to the pipeline that owns the operation and to its own
 * conditions, and for most of these the channel publishes no way to establish one at all. That is why they are here.
 *
 * ## And two people at once
 *
 * `(source, source_row_id)` is unique on the review table, so two pharmacists cannot record two conflicting outcomes for one
 * refund: the second is refused by the database and told who got there first. The one state change that does exist --
 * putting idempotent work back -- is a conditional update whose affected-row count is checked, so it cannot be applied twice
 * or applied to a row somebody else moved.
 *
 * The two actions therefore look the row up differently, and the difference is the point. A REVIEW asks only whether the
 * operation is still in an alert state: it changes nothing, so a second one can safely run as far as the unique key, and the
 * refusal is then able to name the finding the first person recorded. A PUT-BACK also asks whether anybody has already
 * closed the alert, because it moves `dead -> pending` before it writes its own review -- allowed as far as that write, it
 * would queue real work and then need a constraint violation to undo it.
 */
class AttentionActions
{
    public function __construct(
        private readonly AttentionRegistry $registry,
        private readonly AuditLogger $audit,
    ) {
    }

    /** The review table ships before its migration has to have run; until it has, no review is accepted at all. */
    public static function ready(): bool
    {
        return Schema::hasTable('channel_attention_reviews');
    }

    /**
     * Records what a person found at the channel. **Changes no operation's state.**
     *
     * The review row and its audit line are written in ONE transaction, and the call fails if either cannot be stored: an
     * action whose only durable trace is its record must not report success when that record was lost.
     *
     * @param string $outcome one of `ChannelAttentionReview::OUTCOMES`
     * @param bool $closeTheAlert take it off the screen. The operation's own state is untouched either way.
     * @return array{ok: bool, refusal: string|null}
     */
    public function review(
        string $sourceKey,
        int $id,
        int $adminId,
        string $outcome,
        string $note,
        ?string $evidence = null,
        bool $closeTheAlert = false,
    ): array {
        $source = $this->registry->find($sourceKey);
        if ($source === null || !$source->allows(AttentionSource::REVIEW)) {
            return ['ok' => false, 'refusal' => 'attention_action_not_allowed_for_this_source'];
        }
        if (!self::ready()) {
            return ['ok' => false, 'refusal' => 'attention_reviews_table_not_migrated'];
        }
        if (!in_array($outcome, ChannelAttentionReview::OUTCOMES, true)) {
            return ['ok' => false, 'refusal' => 'attention_outcome_is_not_one_we_recognise'];
        }
        if (trim($note) === '') {
            // The note is what a later reader has to go on. A recorded decision nobody can account for is worse than a row
            // still waiting.
            return ['ok' => false, 'refusal' => 'attention_acknowledgement_needs_words'];
        }
        if ($adminId < 1) {
            return ['ok' => false, 'refusal' => 'attention_review_needs_a_named_person'];
        }

        $row = $this->rowInAnAlertState($source, $id);
        if ($row === null) {
            return ['ok' => false, 'refusal' => 'attention_row_is_no_longer_waiting'];
        }

        try {
            DB::transaction(function () use ($source, $row, $id, $adminId, $outcome, $note, $evidence, $closeTheAlert): void {
                $review = ChannelAttentionReview::create([
                    'source' => $source->key,
                    'source_row_id' => $id,
                    // Copied now: the source row may change or go, and a review that cannot say what it was about is not a
                    // trail.
                    'reference' => $this->reference($source, $row),
                    'observed_state' => (string) $row->{$source->stateColumn},
                    'reviewed_by' => $adminId,
                    'reviewed_at' => now(),
                    'outcome' => $outcome,
                    'note' => mb_substr(trim($note), 0, 2000),
                    'evidence' => $evidence === null || trim($evidence) === '' ? null : mb_substr(trim($evidence), 0, 191),
                    'alert_state' => $closeTheAlert ? ChannelAttentionReview::CLOSED : ChannelAttentionReview::REVIEWED,
                ]);

                // **Required where the trail exists.** Inside the transaction, so a line that cannot be written takes the
                // review row with it rather than leaving a decision about somebody's money half recorded.
                //
                // The REVIEW ROW is the durable record this feature promises -- it carries who, when, what they saw and what
                // they can show for it, which is more than an audit line holds. `audit_logs` is optional on some
                // installations, so its absence does not block a review; its FAILURE does, because a trail that silently
                // lost a line is worse than one that never had it.
                $this->auditOf(action: 'sales_channel.attention_reviewed', subject: $row, context: [
                    'source' => $source->key,
                    'row' => $id,
                    'outcome' => $outcome,
                    'evidence' => $review->evidence,
                    'alert_state' => $review->alert_state,
                    // Said out loud in the trail: the operation was NOT settled by this.
                    'operation_state_unchanged' => (string) $row->{$source->stateColumn},
                    'by' => $adminId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two people with the screen open. The first review stands; this one was never made.
            $first = ChannelAttentionReview::where('source', $source->key)->where('source_row_id', $id)->first();

            return ['ok' => false, 'refusal' => 'attention_somebody_already_reviewed_this_' . ($first?->outcome ?? 'row')];
        } catch (QueryException | RuntimeException | Throwable) {
            // The review or its audit line could not be stored, so nothing is claimed. The row stays on the screen, which is
            // the honest outcome: a review nobody can read later did not happen.
            return ['ok' => false, 'refusal' => 'attention_review_could_not_be_recorded'];
        }

        return ['ok' => true, 'refusal' => null];
    }

    /**
     * Queues the work again. Refused unless the source is idempotent by construction.
     *
     * One source in the whole system qualifies: the stock outbox, whose publisher recomputes a quantity and compares it with
     * what it last wrote, so working an event again cannot take units off twice. **Every other source may already have sent
     * something**, and a retry there is a second refund, a second parcel, a duplicated ledger line or a second charge.
     *
     * @return array{ok: bool, refusal: string|null}
     */
    public function putBack(string $sourceKey, int $id, int $adminId, string $note): array
    {
        $source = $this->registry->find($sourceKey);
        if ($source === null || !$source->allows(AttentionSource::PUT_BACK)) {
            return ['ok' => false, 'refusal' => 'attention_action_not_allowed_for_this_source'];
        }
        if (!$source->retryable || $source->pendingState === null) {
            // Belt and braces, deliberately: a source could be declared with PUT_BACK among its actions and `retryable:
            // false` by mistake, and this is what makes that mistake an error instead of a second refund.
            return ['ok' => false, 'refusal' => 'attention_this_work_may_already_have_happened_and_is_never_sent_again'];
        }
        if (trim($note) === '' || $adminId < 1) {
            return ['ok' => false, 'refusal' => 'attention_acknowledgement_needs_words'];
        }
        if (!self::ready()) {
            return ['ok' => false, 'refusal' => 'attention_reviews_table_not_migrated'];
        }

        $row = $this->rowStillWaitingForSomebody($source, $id);
        if ($row === null) {
            return ['ok' => false, 'refusal' => 'attention_row_is_no_longer_waiting'];
        }
        $wasInState = (string) $row->{$source->stateColumn};

        try {
            $moved = DB::transaction(function () use ($source, $row, $id, $adminId, $note, $wasInState): int {
                // **The state is the condition and the affected-row count is checked.** A row somebody else moved between the
                // read above and this write must not be moved again: `whereKey` alone would have applied this to whatever
                // state the row had reached.
                $affected = $row->newQuery()
                    ->whereKey($id)
                    ->where($source->stateColumn, $wasInState)
                    ->update([
                        $source->stateColumn => $source->pendingState,
                        'attempts' => 0,
                        'available_at' => null,
                        'failure_code' => null,
                        'updated_at' => now(),
                    ]);
                if ($affected !== 1) {
                    throw new RuntimeException('the row moved under us');
                }

                ChannelAttentionReview::create([
                    'source' => $source->key,
                    'source_row_id' => $id,
                    'reference' => $this->reference($source, $row),
                    'observed_state' => $wasInState,
                    'reviewed_by' => $adminId,
                    'reviewed_at' => now(),
                    'outcome' => ChannelAttentionReview::NOT_DONE_AT_CHANNEL,
                    'note' => mb_substr(trim($note), 0, 2000),
                    'alert_state' => ChannelAttentionReview::CLOSED,
                ]);

                $this->auditOf(action: 'sales_channel.attention_put_back', subject: $row, context: [
                    'source' => $source->key, 'row' => $id, 'from_state' => $wasInState, 'to_state' => $source->pendingState, 'by' => $adminId,
                ]);

                return $affected;
            });
        } catch (UniqueConstraintViolationException) {
            return ['ok' => false, 'refusal' => 'attention_somebody_already_reviewed_this_row'];
        } catch (QueryException | RuntimeException | Throwable) {
            return ['ok' => false, 'refusal' => 'attention_row_is_no_longer_waiting'];
        }

        return $moved === 1 ? ['ok' => true, 'refusal' => null] : ['ok' => false, 'refusal' => 'attention_row_is_no_longer_waiting'];
    }

    /**
     * The audit line: demanded when the trail exists, skipped when the installation has none.
     *
     * @param array<string, mixed> $context
     * @throws RuntimeException when the line exists to be written and could not be
     */
    private function auditOf(string $action, mixed $subject, array $context): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        $this->audit->recordOrFail(action: $action, subject: $subject, context: $context);
    }

    /**
     * The row, if the operation is still in one of the states that put it on the screen.
     *
     * This is what a REVIEW needs, and deliberately no more. A review changes no state, so letting one through on a row
     * somebody has already dealt with costs nothing -- and it buys the better refusal: the unique key stops the second
     * review and the message can then name the outcome the first person recorded. "Somebody already looked and found the
     * refund was made" is worth more to a pharmacist than "this row is no longer waiting".
     *
     * Locked for update, so two reviews of one row serialise rather than racing. The unique key on the review table is still
     * the guarantee; this only makes the common case give a clear answer instead of a constraint violation.
     *
     * No engine check: on SQLite `lockForUpdate()` compiles to nothing at all -- the lock clause simply does not appear in
     * the SQL -- so asking for it unconditionally is correct everywhere. The first version checked the driver name through
     * `DB::connection()`, which this module's own guard reads as opening a connection of its own, and it was right to: a
     * module that reaches for the connection is a module that can reach for somebody else's table.
     */
    private function rowInAnAlertState(AttentionSource $source, int $id): ?Model
    {
        /** @var Model $model */
        $model = new $source->model();

        return $model->newQuery()->whereKey($id)->whereIn($source->stateColumn, $source->states)->lockForUpdate()->first();
    }

    /**
     * The row, if it is still waiting for somebody: in an alert state AND with no review that closed its alert.
     *
     * **What a PUT-BACK needs**, because a put-back is the one action here that changes an operation's state. It moves
     * `dead -> pending` BEFORE it writes its own review, so a put-back allowed as far as that write would queue real work
     * and then rely on a constraint violation to roll it back. Both halves are asked first instead, before anything moves.
     *
     * The closed-alert half lives on the review model, because the collector decides what is on the screen with exactly the
     * same question and the two must not drift into disagreeing.
     */
    private function rowStillWaitingForSomebody(AttentionSource $source, int $id): ?Model
    {
        /** @var Model $model */
        $model = new $source->model();
        $query = $model->newQuery()->whereKey($id)->whereIn($source->stateColumn, $source->states);
        $query->whereNotIn($model->getQualifiedKeyName(), ChannelAttentionReview::closedRowIdsFor($source->key));

        return $query->lockForUpdate()->first();
    }

    private function reference(AttentionSource $source, Model $row): ?string
    {
        $column = $source->column('reference');
        $value = $column === null ? null : ($row->{$column} ?? null);

        return $value === null ? null : mb_substr((string) $value, 0, 191);
    }
}
