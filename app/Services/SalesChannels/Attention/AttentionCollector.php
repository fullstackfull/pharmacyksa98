<?php

namespace App\Services\SalesChannels\Attention;

use App\Models\ChannelAttentionReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Everything waiting for a person, across every integration, in one list.
 *
 * ## What it does not do
 *
 * It reads. It never sends, never retries, never settles anything. Every state it collects is one that some pipeline reached
 * deliberately because the outcome was not knowable, and the whole value of those states is that no machine touches them
 * again — a collector that "helpfully" retried would undo the one guarantee they exist to give.
 *
 * ## An alert is not an operation
 *
 * A row leaves this list when its ALERT is closed, which a person does by reviewing it. **The operation's own state is
 * untouched**: a return decision closed off the screen still reads `may_have_sent` in its own table, because that is what
 * happened. So this class filters by the review table, never by the operation's state.
 *
 * ## And `late` is counted, not sampled
 *
 * `late` used to be computed from `items()`, which is capped — so a hundredth late refund past the cap was invisible, and
 * the one number on the dashboard that says "somebody has not looked at this for a day" under-reported exactly when it
 * mattered most. It is a `COUNT` per source now, over the whole table.
 */
class AttentionCollector
{
    public function __construct(private readonly AttentionRegistry $registry)
    {
    }

    /**
     * How many rows are waiting, per source, plus the totals — all by COUNT, loading no row.
     *
     * @return array{total: int, late: int, by_source: array<string, int>, by_domain: array<string, int>}
     */
    public function summary(): array
    {
        $bySource = [];
        $byDomain = [];
        $total = 0;
        $late = 0;

        foreach ($this->registry->all() as $source) {
            $count = $this->query($source)->count();
            $bySource[$source->key] = $count;
            $byDomain[$source->domain] = ($byDomain[$source->domain] ?? 0) + $count;
            $total += $count;
            $late += $this->lateCount($source);
        }

        return [
            'total' => $total,
            'late' => $late,
            'by_source' => $bySource,
            'by_domain' => array_filter($byDomain),
        ];
    }

    /**
     * The rows themselves.
     *
     * @param string|null $onlySource one source's rows, or every source's
     * @return list<AttentionItem>
     */
    public function items(?string $onlySource = null, int $limit = 200): array
    {
        $items = [];
        foreach ($this->registry->all() as $source) {
            if ($onlySource !== null && $source->key !== $onlySource) {
                continue;
            }
            foreach ($this->query($source)->limit(max(1, $limit))->get() as $row) {
                $items[] = $this->itemOf($source, $row);
            }
        }

        // Oldest first. A row with no recorded time goes last rather than first: an unknown age is not an old age.
        usort($items, static function (AttentionItem $left, AttentionItem $right): int {
            return [$left->since === null, $left->since?->getTimestamp() ?? 0] <=> [$right->since === null, $right->since?->getTimestamp() ?? 0];
        });

        return $items;
    }

    public function item(string $sourceKey, int $id): ?AttentionItem
    {
        $source = $this->registry->find($sourceKey);
        if ($source === null) {
            return null;
        }
        $row = $this->query($source)->whereKey($id)->first();

        return $row === null ? null : $this->itemOf($source, $row);
    }

    /**
     * How many of this source's rows nobody has looked at for too long.
     *
     * A `COUNT` against the source's own "since" column, so it is the real number whatever the page size. A source that
     * records no time contributes nothing rather than everything: an unknown age is not a late one.
     */
    private function lateCount(AttentionSource $source): int
    {
        $column = $source->column('since');
        if ($column === null) {
            return 0;
        }
        $cutoff = now()->subHours(max(1, (int) config('sales_channels.attention.late_after_hours', 24)));

        return $this->query($source)->whereNotNull($column)->where($column, '<=', $cutoff)->count();
    }

    /**
     * This source's rows that are still waiting for somebody.
     *
     * The operation's state puts a row on the list; a CLOSED review takes it off. Both halves matter: filtering on the
     * operation's state alone would show a row for ever after a person dealt with it, and filtering on the review alone
     * would show rows the pipeline has since settled by itself.
     *
     * @return Builder<Model>
     */
    private function query(AttentionSource $source): Builder
    {
        /** @var Model $model */
        $model = new $source->model();
        $query = $model->newQuery()->whereIn($source->stateColumn, $source->states);

        if (Schema::hasTable('channel_attention_reviews')) {
            $query->whereNotIn($model->getQualifiedKeyName(), ChannelAttentionReview::closedRowIdsFor($source->key));
        }

        return $query->orderBy($model->getQualifiedKeyName());
    }

    private function itemOf(AttentionSource $source, Model $row): AttentionItem
    {
        $read = static function (?string $column) use ($row): mixed {
            return $column === null ? null : ($row->{$column} ?? null);
        };
        $since = $read($source->column('since'));
        $review = Schema::hasTable('channel_attention_reviews')
            ? ChannelAttentionReview::where('source', $source->key)->where('source_row_id', (int) $row->getKey())->first()
            : null;

        return new AttentionItem(
            source: $source->key,
            domain: $source->domain,
            id: (int) $row->getKey(),
            state: (string) $row->{$source->stateColumn},
            reference: ($value = $read($source->column('reference'))) === null ? null : (string) $value,
            accountId: ($account = $read($source->column('account'))) === null ? null : (int) $account,
            reason: ($reason = $read($source->column('reason'))) === null ? null : (string) $reason,
            detail: ($detail = $read($source->column('detail'))) === null ? null : (string) $detail,
            since: $since instanceof \Carbon\CarbonInterface ? $since : null,
            actions: $source->actions,
            retryable: $source->retryable,
            whyNotRetryable: $source->whyNotRetryable(),
            orderReference: ($order = $read($source->column('order'))) === null ? null : (string) $order,
            reviewedBy: $review === null ? null : (int) $review->reviewed_by,
            reviewOutcome: $review?->outcome,
        );
    }
}
