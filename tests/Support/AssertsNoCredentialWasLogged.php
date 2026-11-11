<?php

namespace Tests\Support;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use Illuminate\Support\Facades\Schema;

/**
 * Asserts that no credential a test configured ever reached `sales_channel_api_logs`.
 *
 * ## Why this is a trait on the existing suites and not a matrix of its own
 *
 * The Odoo API key was written in clear text into the call log on every read and every write, and nothing noticed. The
 * redactors work by KEY -- `password`, `authorization`, `api_key` -- and Odoo's XML-RPC is POSITIONAL, so the key arrived as
 * an unnamed `<string>` with no key to match. A credential leak is never a property of the redactor alone: it is a property of
 * what a particular connector puts where, which only a real call can show.
 *
 * Writing a matrix that exercises every operation of five channels would mean faking every endpoint twice over. But the
 * channel suites ALREADY make hundreds of real calls against faked endpoints, with real secrets in the store. So the cheapest
 * honest guard is to read the verdict off the calls those tests already make: at the end of a test, every account's stored
 * secrets are fetched and every log row is searched for their values.
 *
 * **It discovers the secrets rather than being told them.** A fixed list of fixture strings would go stale the first time
 * somebody added a credential; this reads whatever the test actually configured, through the same store the connectors use, so
 * a new secret field is covered the day it exists.
 *
 * A value shorter than `MEANINGFUL_LENGTH` is skipped: a two-character fixture secret would match half the log by accident and
 * the guard would be useless noise rather than a guard.
 */
trait AssertsNoCredentialWasLogged
{
    /** Below this length a fixture value matches too much to mean anything. */
    private const MEANINGFUL_LENGTH = 6;

    /**
     * Every column of the log a credential could reach. `request_summary` is the one that carried the Odoo key; the rest are
     * here because a leak that moved to another column would otherwise pass this guard unchanged.
     */
    private const COLUMNS = ['url', 'request_summary', 'response_summary', 'error_message', 'operation'];

    /**
     * Arms the guard for the rest of this test, through `beforeApplicationDestroyed` so that no suite has to remember to call
     * anything at the end.
     *
     * It is wired into `BuildsSalesChannelSchema` and `BuildsOdooWorld`, so **every suite that builds a channel or an
     * accounting world is guarded** -- fifty-odd files and hundreds of real calls against faked endpoints, which is coverage
     * no purpose-built matrix would match.
     *
     * A failure here lands during teardown, which is the honest place for it: the call already happened and the row is already
     * written, so there is nothing earlier to catch it at.
     */
    protected function guardAgainstLoggedCredentials(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            $this->assertNoCredentialWasLogged();
        });
    }

    protected function assertNoCredentialWasLogged(): void
    {
        // **The cheap question first, and it matters.** This runs after every test of fifty-odd suites. Most of them make no
        // channel call at all, and reading every account's secrets means decrypting each one -- so asking "is there even a log
        // row?" before any of that is the difference between a guard and a tax on the whole suite.
        if (!Schema::hasTable('sales_channel_api_logs') || !SalesChannelApiLog::query()->exists()) {
            return;
        }

        $secrets = $this->storedSecrets();
        if ($secrets === []) {
            return;
        }

        $rows = SalesChannelApiLog::all();
        foreach ($rows as $row) {
            $written = (string) json_encode(array_map(static fn (string $column) => $row->{$column}, self::COLUMNS), JSON_INVALID_UTF8_SUBSTITUTE);
            foreach ($secrets as $name => $value) {
                $this->assertStringNotContainsString(
                    $value,
                    $written,
                    "The credential '{$name}' reached the call log of '{$row->operation}'. A log is read by people who are not "
                    . 'allowed to hold this value, and it is kept for weeks. If the body cannot be redacted by key -- a raw '
                    . 'protocol payload, a positional argument -- it must not be logged as itself.',
                );
            }
        }
    }

    /**
     * Every secret value stored against every account in this test, keyed by a name for the failure message.
     *
     * @return array<string, string>
     */
    private function storedSecrets(): array
    {
        if (!Schema::hasTable('sales_channel_accounts')) {
            return [];
        }

        $store = app(ChannelCredentialStore::class);
        $found = [];
        foreach (SalesChannelAccount::all() as $account) {
            $credentials = $store->get($account);
            foreach (ChannelCredentialStore::SECRET_KEYS[(string) $account->channel] ?? [] as $key) {
                $value = (string) ($credentials->secret($key) ?? '');
                if (strlen($value) >= self::MEANINGFUL_LENGTH) {
                    $found[$account->channel . '.' . $key] = $value;
                }
            }
        }

        return $found;
    }
}
