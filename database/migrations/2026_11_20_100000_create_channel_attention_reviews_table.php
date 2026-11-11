<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person found when they went and looked — kept here, permanently, and apart from the operation itself.
 *
 * ## The defect this exists to fix
 *
 * The attention screen used to settle a row on the strength of a typed note: `acknowledge()` moved a return decision to
 * `done`, a split to `confirmed`, an Odoo posting to `posted`. **A sentence is not proof that a customer was refunded, that a
 * parcel was cut, or that a line is in a real ledger.** And it was worse than merely optimistic: the writer dropped any
 * column the table did not have, and nine of the ten source tables have no review columns at all — so the note the screen
 * calls "the record" was thrown away, the reviewer's name with it, and the only remaining trace was an audit line whose
 * writer swallows its own failures.
 *
 * So the review is a record of its own, and it answers four different questions that were previously collapsed into one:
 *
 *  - **that somebody looked** — `reviewed_by`, `reviewed_at`
 *  - **what they saw** — `outcome`, from a fixed vocabulary, never free text
 *  - **what they can show for it** — `evidence`, the channel's own reference for what they saw, where one exists
 *  - **what is to happen to the alert** — `alert_state`
 *
 * And a fifth question it deliberately does NOT answer: what the operation's own state is. That stays on the operation's own
 * row, as true as it ever was. An alert closed administratively leaves a return decision reading `may_have_sent` for ever,
 * because that is what happened.
 *
 * ## One review per row, enforced here
 *
 * `(source, source_row_id)` is UNIQUE. Two pharmacists with the screen open is the ordinary case, and they must not be able
 * to record two conflicting outcomes for one refund. The second is refused by the database and told who got there first --
 * the same lesson as the package splits, where a read-then-insert let four processes cut one parcel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_attention_reviews')) {
            return;
        }

        Schema::create('channel_attention_reviews', function (Blueprint $table): void {
            $table->id();
            // Which list it came from (`AttentionRegistry` key) and which row of that source's table.
            $table->string('source', 40);
            $table->unsignedBigInteger('source_row_id');
            // The reference as the SCREEN showed it, copied at review time. The source row may be gone or changed later, and a
            // review that cannot say what it was about is not an audit trail.
            $table->string('reference', 191)->nullable();
            // The operation's own state when the review was made, kept so a later reader can see what was true then.
            $table->string('observed_state', 32)->nullable();

            $table->unsignedBigInteger('reviewed_by');
            $table->timestamp('reviewed_at');

            /*
            | WHAT THE PERSON SAW, from a fixed vocabulary.
            |
            | `confirmed_at_channel`  the channel shows the operation really happened
            | `not_done_at_channel`   the channel shows it did not happen
            | `still_unknown`         they looked and the channel does not say
            | `no_longer_relevant`    the order was cancelled, the account closed, the question moot
            |
            | None of these changes the operation's state by itself. `confirmed_at_channel` is a person's reading of somebody
            | else's screen, which is good enough to stop the alert nagging and not good enough to write into our books.
            */
            $table->string('outcome', 32);
            // Required by the service: a review with no words is a state change nobody can account for.
            $table->text('note');
            // The channel's own reference for what they saw -- a refund id, a package id, a journal entry number. Optional
            // because several channels publish nothing to quote, which is why these rows are stuck in the first place.
            $table->string('evidence', 191)->nullable();

            // open -> still on the screen; reviewed -> looked at, still shown; closed -> off the screen, operation untouched
            $table->string('alert_state', 16)->default('reviewed');
            $table->timestamps();

            // ONE review per source row. The guarantee, in the database rather than in a read.
            $table->unique(['source', 'source_row_id'], 'car_row_unique');
            $table->index(['alert_state', 'reviewed_at'], 'car_state_idx');
            $table->index('reviewed_by', 'car_reviewer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_attention_reviews');
    }
};
