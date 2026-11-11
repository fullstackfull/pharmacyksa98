<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Services\Accounting\Odoo\OdooClient;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsNoCredentialWasLogged;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * **A credential never reaches `sales_channel_api_logs`** — checked by making real calls, per channel, and reading the log.
 *
 * ## The leak this is the durable form of
 *
 * Odoo's XML-RPC is POSITIONAL: `execute_kw(db, uid, key, model, method, args, kwargs)` carries the API key as its third
 * unnamed `<string>`. The transport logged a raw body as itself, and both redactors work by KEY — `password`,
 * `authorization`, `api_key` — so there was no key to match, nothing could tell the credential from the database name, and
 * every Odoo read and write wrote the key in clear text into a table kept for weeks and read by people who are not allowed to
 * hold it.
 *
 * It was found by reading one new code path by hand. That does not repeat by itself, and the next channel with an unusual
 * encoding would reintroduce it. So the finding is turned into a test that makes the calls and looks.
 *
 * ## Why the secrets are discovered and not listed
 *
 * The guard reads each account's stored secrets back through the same store the connectors use, so it covers whatever a test
 * configured — and a credential field added tomorrow is covered the day it exists. A hard-coded list of fixture strings would
 * go stale on the first new field and quietly stop guarding.
 *
 * ## And why the fakes answer everything
 *
 * What is under test is what leaves this host and what gets written down, not what a channel answers. So every endpoint
 * answers plausibly and the assertions are entirely about the log.
 */
class CredentialsNeverReachTheLogTest extends TestCase
{
    use AssertsNoCredentialWasLogged;
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        config([
            'sales_channels.enabled' => true,
            'sales_channels.external_writes_enabled' => true,
            'odoo.enabled' => true,
        ]);
    }

    /**
     * One distinctive secret per field, so a failure names the field that leaked.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, mixed>}>
     */
    public static function channels(): array
    {
        return [
            'amazon' => ['amazon', [
                'lwa_client_id' => 'amzn1.application-oa2-client.LEAKCANARY-CLIENTID',
                'lwa_client_secret' => 'amzn1.oa2-cs.v1.LEAKCANARY-CLIENTSECRET',
                'refresh_token' => 'Atzr|LEAKCANARY-REFRESHTOKEN-0123456789',
            ], ['external_account_id' => 'A1SELLERLEAK', 'market' => 'SA', '_force' => ['marketplace_ids' => ['A17E79C6D8DWNP']]]],
            'trendyol' => ['trendyol', [
                'api_key' => 'LEAKCANARY-TRENDYOL-APIKEY-0123',
                'api_secret' => 'LEAKCANARY-TRENDYOL-SECRET-4567',
            ], ['external_account_id' => '123456', 'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub']]],
            // noon's `private_key` SIGNS the sign-in and is never transmitted, so a canary string there would simply fail to
            // sign and leave an empty log for this guard to pass over. A real generated key is used instead -- and it is still
            // a canary: long, unique, and the redactor carries a PEM rule of its own that this exercises.
            'noon' => ['noon', [
                'key_id' => 'LEAKCANARY-NOON-KEYID-89ab',
                'private_key' => '@generate-a-real-rsa-key',
                'project_code' => 'LEAKCANARY-NOON-PROJECT-ghij',
                'channel_identifier' => 'noon',
            ], ['external_account_id' => 'noon-seller-leak', 'settings' => ['warehouse_code' => 'WH-RUH-01', 'country_code' => 'sa']]],
        ];
    }

    /**
     * @param array<string, string> $secrets
     * @param array<string, mixed> $extra
     */
    #[DataProvider('channels')]
    public function test_a_connection_test_writes_no_credential_into_the_log(string $channel, array $secrets, array $extra): void
    {
        $account = $this->account($channel, $secrets, $extra);
        $this->fakeEverything();

        app(ChannelConnectorRegistry::class)->for($account)->testConnection($account);

        // **A GUARD THAT MADE NO CALL IS NOT A GUARD.** A connector that refused at a gate, or a fixture missing a setting,
        // would leave an empty log and this test would report success over nothing. So the proof is in two parts: a request
        // really left this host for THIS channel, and the credentials it carried are nowhere in the log.
        $this->assertNotEmpty($this->requestsSent(), 'no request left this host, so there was nothing for the guard to look at');
        $this->assertNotEmpty(SalesChannelApiLog::all(), 'the call was not logged, so the log cannot be the thing under test');
        $this->assertSecretsTravelled($secrets);
        $this->assertNoCredentialWasLogged();
    }

    public function test_an_odoo_object_call_writes_no_credential_into_the_log(): void
    {
        // The call the leak was in, and the reason this test file exists. `execute_kw` carries the API key as an unnamed
        // positional argument, which no key-based redactor can see.
        // `ChannelAccountService::create()` makes marketplace accounts and refuses anything else, so the accounting
        // connection is built the way the Odoo suite builds it -- and given its own canary key.
        $account = $this->odooConnection();
        $store = app(ChannelCredentialStore::class);
        $store->save($account, ['api_key' => 'LEAKCANARY-ODOO-APIKEY-klmn']);
        // `save()` replaces the stored blob, so what the fixture had LEARNED about the instance goes back in afterwards.
        // Without it the transport reads Unknown and nothing is sent, and the test would pass over an empty log.
        $store->putRuntime($account, 'transport', 'xmlrpc');
        $store->putRuntime($account, 'uid', '7');
        $this->fakeEverything();

        app(OdooClient::class)->searchRead($account->fresh(), 'account.account', [['code', '=', '4000']], ['id', 'name']);

        $this->assertNotEmpty($this->requestsSent(), 'no request left this host, so there was nothing for the guard to look at');
        $this->assertNotEmpty(SalesChannelApiLog::all());
        // The key really was in the body that left. Without this the test would pass just as well against a client that had
        // stopped sending the credential at all -- which is not what is being proved here.
        $this->assertSecretsTravelled(['api_key' => 'LEAKCANARY-ODOO-APIKEY-klmn']);
        $this->assertNoCredentialWasLogged();
    }

    public function test_the_guard_itself_fails_when_a_credential_is_written_down(): void
    {
        // **A guard that cannot fail is not a guard.** A log row is written by hand with a secret in it, and the assertion is
        // expected to catch it -- otherwise a change to the redactors, the columns scanned, or the minimum length would leave
        // this file passing over a real leak.
        $account = $this->account('trendyol', ['api_key' => 'LEAKCANARY-TRENDYOL-APIKEY-0123', 'api_secret' => 'LEAKCANARY-TRENDYOL-SECRET-4567'], [
            'external_account_id' => '123456',
        ]);
        SalesChannelApiLog::create([
            'account_id' => $account->id,
            'channel' => 'trendyol',
            'operation' => 'trendyol.brands',
            'correlation_id' => 'test',
            'method' => 'GET',
            'url' => 'https://apigw.trendyol.com/integration/product/brands',
            'request_summary' => ['body' => ['raw' => 'key=LEAKCANARY-TRENDYOL-APIKEY-0123']],
            'response_status' => 200,
            'success' => true,
        ]);

        try {
            $this->assertNoCredentialWasLogged();
        } catch (\PHPUnit\Framework\AssertionFailedError $caught) {
            $this->assertStringContainsString('trendyol.api_key', $caught->getMessage());
            $this->assertStringContainsString('trendyol.brands', $caught->getMessage());
            // The row was planted to be caught, and it has been. It goes now, because the SAME guard is armed for teardown
            // across every suite built on `BuildsSalesChannelSchema` -- leaving the plant behind would have this test fail on
            // its way out for the very reason it was written to prove.
            SalesChannelApiLog::query()->delete();

            return;
        }

        $this->fail('The guard passed over a credential written straight into the log, so it is not guarding anything.');
    }

    public function test_every_channel_with_stored_secrets_is_covered_by_this_file(): void
    {
        // The data provider above is a list, and a list goes stale. A channel that can store a secret and is not exercised
        // here is a channel whose leaks nobody would see, so the omission fails rather than passing quietly.
        $exercised = array_merge(array_keys(self::channels()), ['odoo']);

        foreach (array_keys(ChannelCredentialStore::SECRET_KEYS) as $channel) {
            $this->assertContains(
                $channel,
                $exercised,
                "'{$channel}' can store credentials and no test here makes a call with them, so nothing would notice if it logged one.",
            );
        }
    }

    /**
     * The requests that really left this host.
     *
     * @return list<\Illuminate\Http\Client\Request>
     */
    private function requestsSent(): array
    {
        return Http::recorded()->values()->map(static fn (array $pair): \Illuminate\Http\Client\Request => $pair[0])->all();
    }

    /**
     * At least one of the credentials this account holds really did travel in a request.
     *
     * This is what makes the guard mean something. If a connector sent no credential at all, the log would be clean for an
     * uninteresting reason and the test would pass while proving nothing about redaction. A credential that only signs and is
     * never transmitted (noon's private key) is not expected here, so ONE match is enough rather than all of them.
     *
     * @param array<string, string> $secrets
     */
    private function assertSecretsTravelled(array $secrets): void
    {
        $wire = '';
        foreach ($this->requestsSent() as $request) {
            $wire .= $request->url() . ' ' . $request->body() . ' ' . json_encode($request->headers());
        }

        // **A credential does not always travel as itself.** Trendyol sends `Authorization: Basic base64(key:secret)`, which
        // encodes the two values TOGETHER -- so neither one's own base64 appears either, and guessing at encodings per value
        // does not find it. The wire is decoded instead: every base64-looking run in it is decoded and appended, so a
        // credential is found however it was packed, by one rule rather than a list of special cases.
        $wire .= ' ' . $this->decodedFormsIn($wire);

        $travelled = array_filter(
            $secrets,
            static fn (string $value): bool => $value !== '@generate-a-real-rsa-key' && str_contains($wire, $value),
        );
        $this->assertNotEmpty(
            $travelled,
            'not one of this account\'s credentials appeared in anything that left this host, so a clean log proves nothing: '
            . 'either the fixture is wrong or the connector is authenticating some other way than this test believes.',
        );
    }

    /**
     * Everything in this text that decodes from base64, decoded.
     *
     * `Authorization: Basic …` is the reason: it packs several values into one token, so no per-value encoding guess finds
     * them. Decoding what is on the wire works whatever the scheme packs together.
     */
    private function decodedFormsIn(string $wire): string
    {
        preg_match_all('/[A-Za-z0-9+\/]{16,}={0,2}/', $wire, $tokens);
        $decoded = '';
        foreach (array_unique($tokens[0]) as $token) {
            $plain = base64_decode($token, true);
            if (is_string($plain) && $plain !== '') {
                $decoded .= ' ' . $plain;
            }
        }

        return $decoded;
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $secrets
     * @param array<string, mixed> $extra
     */
    private function account(string $channel, array $secrets, array $extra): SalesChannelAccount
    {
        $force = (array) ($extra['_force'] ?? []);
        unset($extra['_force']);
        foreach ($secrets as $key => $value) {
            if ($value === '@generate-a-real-rsa-key') {
                $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
                openssl_pkey_export($pair, $pem);
                $secrets[$key] = (string) $pem;
            }
        }

        $account = app(ChannelAccountService::class)->create(
            ['channel' => $channel, 'name' => ucfirst($channel) . ' leak canary', 'currency' => 'SAR'] + $extra,
            $secrets,
            adminId: 1,
        );
        $account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import', 'financial_reconciliation'],
        ] + $force)->save();

        return $account->fresh();
    }

    /**
     * Every endpoint answers something plausible. What is under test is what gets written down, not what a channel says, so a
     * single catch-all keeps this file about the log and not about five sets of response shapes.
     */
    private function fakeEverything(): void
    {
        Http::fake([
            // noon's sign-in, which answers a session cookie rather than a body.
            '*/identity/public/v1/api/login' => Http::response('', 200, ['Set-Cookie' => 'noon_session=LEAKCANARY-SESSION; Path=/']),
            // Odoo's XML-RPC, which answers XML and nothing else can parse.
            '*/xmlrpc/2/*' => Http::response(
                '<?xml version="1.0"?><methodResponse><params><param><value><array><data>'
                . '<value><struct><member><name>id</name><value><int>4</int></value></member></struct></value>'
                . '</data></array></value></param></params></methodResponse>',
                200,
                ['Content-Type' => 'text/xml'],
            ),
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'Atza|LEAKCANARY-ACCESS', 'expires_in' => 3600, 'token_type' => 'bearer']),
            '*' => Http::response(['payload' => [], 'content' => [], 'brands' => [], 'items' => [], 'marketplaceParticipations' => []]),
        ]);
    }
}
