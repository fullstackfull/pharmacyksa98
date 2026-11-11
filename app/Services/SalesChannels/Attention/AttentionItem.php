<?php

namespace App\Services\SalesChannels\Attention;

use Carbon\CarbonInterface;

/**
 * One piece of work waiting for a person, in the shape a screen can show.
 *
 * It carries no buyer and no money figure: a reference, a state, a reason, and what may be done about it. Those are enough to
 * decide where to go and look, which is the whole job of the screen — the record's own page is where the detail lives, behind
 * whatever permission that page already demands.
 */
final readonly class AttentionItem
{
    /**
     * @param list<string> $actions the actions this row's source sanctions, and nothing beyond them
     */
    public function __construct(
        public string $source,
        public string $domain,
        public int $id,
        public string $state,
        public ?string $reference,
        public ?int $accountId,
        public ?string $reason,
        public ?string $detail,
        public ?CarbonInterface $since,
        public array $actions,
        public bool $retryable,
        public ?string $whyNotRetryable,
        public ?string $orderReference = null,
        // Who has already looked at it, and what they said they saw. A row with a review that did NOT close the alert is
        // still waiting -- somebody is working on it -- and the screen says so instead of two people starting over.
        public ?int $reviewedBy = null,
        public ?string $reviewOutcome = null,
    ) {
    }

    /** How long this has been waiting, in whole hours, or null when the source records no time. */
    public function waitingHours(): ?int
    {
        return $this->since?->diffInHours(now());
    }

    /**
     * Whether this has been waiting long enough to be called late.
     *
     * A fenced row is not an emergency on the hour it appears; it becomes one when nobody has looked for a day. The threshold
     * is a setting rather than a number here because what counts as late differs between a refund and a stock event.
     */
    public function isLate(): bool
    {
        $hours = $this->waitingHours();

        return $hours !== null && $hours >= max(1, (int) config('sales_channels.attention.late_after_hours', 24));
    }

    public function allows(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }
}
