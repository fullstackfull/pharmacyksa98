<?php

namespace App\Services;

use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\AuditLog;
use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Support\Facades\Schema;

/**
 * The one way anything in the system records who did what (Phase 3 — spec item 84).
 *
 * Every module records through this rather than growing its own log. It resolves the actor from
 * whichever guard is authenticated, captures their name so a later deletion does not erase the
 * trail, and stamps the request's IP — none of which a caller should have to remember.
 *
 * `record()` never throws into the caller. An audit write failing must not fail the action being audited:
 * the worst outcome of a logging problem is a missing line, which is far better than a settlement or
 * a payout rolled back because the note about it could not be saved.
 *
 * **`recordOrFail()` is for the opposite case, and it exists because of a real one.** Some actions ARE the record: a person
 * settling an ambiguous refund by hand has produced nothing else -- no request, no reply, no channel state change -- so an
 * action reported as done whose only trace silently failed to save is a lie told to whoever reads the trail next. Such a
 * caller asks for the line and is told if it could not be written.
 */
class AuditLogger
{
    /**
     * Record an action.
     *
     * @param string $action dotted name whose prefix is the module, e.g. 'settlement.approved'
     * @param array{type: string, id: int|string}|object|null $subject the record acted on
     */
    public function record(
        string $action,
        array|object|null $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
    ): ?AuditLog {
        try {
            if (!Schema::hasTable('audit_logs')) {
                return null;
            }

            [$subjectType, $subjectId] = $this->resolveSubject($subject);
            [$actorType, $actorId, $actorName] = $this->resolveActor();

            return AuditLog::create([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'before' => $before,
                'after' => $after,
                'context' => $context,
                'ip_address' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
            ]);
        } catch (\Throwable) {
            // A missing audit line is never worth failing the audited action.
            return null;
        }
    }

    /**
     * Record an action, and **throw if the line cannot be written**.
     *
     * For an action whose only durable trace IS the audit line. `record()` swallows a failure because a missing note is
     * better than a rolled-back payout; here the reverse holds: there is nothing to roll back and nothing else to show, so a
     * silent failure would leave somebody's hand-made decision about a customer's money with no author and no date.
     *
     * A host whose `audit_logs` table has not been migrated is told so by name rather than passing silently.
     *
     * @param array{type: string, id: int|string}|object|null $subject
     * @throws \RuntimeException when the line could not be stored
     */
    public function recordOrFail(
        string $action,
        array|object|null $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
    ): AuditLog {
        if (!Schema::hasTable('audit_logs')) {
            throw new \RuntimeException('audit_logs has not been migrated, so this decision cannot be recorded: ' . $action);
        }

        $line = $this->record($action, $subject, $before, $after, $context);
        if ($line === null) {
            throw new \RuntimeException('The audit line for this decision could not be written: ' . $action);
        }

        return $line;
    }

    /**
     * @return array{0: ?string, 1: int|string|null}
     */
    private function resolveSubject(array|object|null $subject): array
    {
        if ($subject === null) {
            return [null, null];
        }

        if (is_array($subject)) {
            return [$subject['type'] ?? null, $subject['id'] ?? null];
        }

        // An Eloquent model, or anything with a key: record its class and id.
        return [get_class($subject), $subject->getKey() ?? ($subject->id ?? null)];
    }

    /**
     * @return array{0: ?string, 1: int|null, 2: ?string}
     */
    private function resolveActor(): array
    {
        // Checked in order of privilege so an admin acting is recorded as an admin even if another
        // guard also happens to resolve.
        $admin = $this->fromGuard('admin');
        if ($admin !== null) {
            return $admin;
        }

        // The seller app does not log a guard in — it carries a token the middleware resolves to a
        // principal. Without this every action taken from the app was recorded as the system doing
        // it, which is the one thing an audit trail must never say.
        $principal = $this->sellerPrincipal();
        if ($principal !== null) {
            return $this->fromPrincipal($principal);
        }

        foreach (['seller', 'customer'] as $guard) {
            $actor = $this->fromGuard($guard);
            if ($actor !== null) {
                return $actor;
            }
        }

        return ['system', null, 'System'];
    }

    /**
     * @return array{0: string, 1: ?int, 2: ?string}|null
     */
    private function fromGuard(string $guard): ?array
    {
        try {
            $user = auth($guard)->user();
        } catch (\Throwable) {
            return null;
        }

        return $user ? [$guard, (int) $user->getKey(), $this->nameOf($user)] : null;
    }

    private function sellerPrincipal(): ?SellerPrincipal
    {
        try {
            $principal = request()->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);
        } catch (\Throwable) {
            return null;
        }

        return $principal instanceof SellerPrincipal ? $principal : null;
    }

    /**
     * The credential, not the shop.
     *
     * A staff member and a key are recorded as themselves rather than as the owner, because "who
     * could have done this" is the question the trail exists to answer.
     *
     * @return array{0: string, 1: ?int, 2: ?string}
     */
    private function fromPrincipal(SellerPrincipal $principal): array
    {
        return [$principal->actorType(), $principal->actorId(), $principal->actorLabel()];
    }

    private function nameOf(object $user): ?string
    {
        foreach (['name', 'f_name', 'email'] as $attribute) {
            if (!empty($user->{$attribute})) {
                return (string) $user->{$attribute};
            }
        }

        return null;
    }

    private function clientIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function userAgent(): ?string
    {
        try {
            return substr((string) request()->userAgent(), 0, 255) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
