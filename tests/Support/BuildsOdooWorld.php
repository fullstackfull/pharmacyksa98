<?php

namespace Tests\Support;

use App\Models\OdooAccountMapping;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooInstance;
use App\Services\Accounting\Odoo\OdooPreflight;
use App\Services\Accounting\Odoo\OdooTransport;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelOperations;

/**
 * The world an Odoo posting test needs: the three Odoo tables, an accounting connection, a mapped chart of accounts, and a
 * preflight standing in for what a real instance reported.
 *
 * The connection is a `SalesChannelAccount` row whose channel is `odoo` — **and `odoo` is deliberately not in the `SalesChannel`
 * enum**, so it can never appear as a source of sales or orders. What it buys by living in that table is the whole protection
 * engine: the switches, the rehearsal mode, the recorded approvals, the redacted credential store.
 *
 * The XML helpers build answers in XML-RPC's own shape from PHP values, so a test says what Odoo returned rather than how it
 * spelled it.
 */
trait BuildsOdooWorld
{
    use AssertsNoCredentialWasLogged;

    protected const ODOO_HOST = 'https://books.example.test';

    protected const ODOO_COMMON = self::ODOO_HOST . '/xmlrpc/2/common';

    protected const ODOO_OBJECT = self::ODOO_HOST . '/xmlrpc/2/object';

    protected const ODOO_COMPANY = 1;

    protected const ODOO_CURRENCY_ID = 3;

    /** What the company's own currency is called, which is also what proves the currency list is the list it looks like. */
    protected const ODOO_CURRENCY_CODE = 'SAR';

    protected const ODOO_FOREIGN_CURRENCY_ID = 2;

    protected const ODOO_EXPENSE_ACCOUNT = 41;

    protected const ODOO_COUNTERPART_ACCOUNT = 52;

    protected const ODOO_JOURNAL = 7;

    /** The Odoo tables are their own migration: nothing in the sales-channel module needs them. */
    protected function buildOdooSchema(): void
    {
        // Odoo is where the credential leak was: `execute_kw` carries the API key as an unnamed POSITIONAL argument, which no
        // key-based redactor can see. Guarded here as well as in the channel builder, because an accounting suite may build
        // this world without building a channel one.
        $this->guardAgainstLoggedCredentials();

        (require database_path('migrations/2026_11_15_100000_create_odoo_accounting_tables.php'))->up();
    }

    /**
     * The ACCOUNTING connection, kept apart from any marketing account: it holds only the reconciliation capability and only the
     * posting approval, so it cannot publish a price, read an order or create a listing.
     */
    protected function odooConnection(bool $signedIn = true, OdooTransport $transport = OdooTransport::XmlRpc): SalesChannelAccount
    {
        $account = SalesChannelAccount::create([
            'channel' => 'odoo',
            'name' => 'Odoo accounting',
            'enabled' => true,
            'write_enabled' => true,
            'dry_run' => false,
            'accounting_destination' => 'odoo',
            'settings' => ['base_url' => self::ODOO_HOST, 'database' => 'books', 'login' => 'bot@example.test'],
            'capabilities' => ['connection_test', 'financial_reconciliation'],
            'capability_approvals' => [ChannelOperations::SCOPE_ACCOUNTING_POSTING => ['by' => 1, 'at' => '2026-11-09T00:00:00Z']],
        ]);
        $store = app(ChannelCredentialStore::class);
        $store->save($account, ['api_key' => 'key-abc']);
        if ($signedIn) {
            $store->putRuntime($account, 'transport', $transport->value);
            if ($transport === OdooTransport::XmlRpc) {
                $store->putRuntime($account, 'uid', '7');
            }
        }

        return $account->fresh();
    }

    /**
     * A component mapped to an expense account, with the counterpart that balances it. Both, because one alone is unpostable.
     *
     * The counterpart is the general one, which a customer maps ONCE for everything, so mapping a second component must not try
     * to write it again -- the table's unique key is there precisely to stop two rows claiming different accounts for one fact.
     */
    protected function mapOdooComponent(string $component, ?int $taxId = null, ?int $analyticId = null): void
    {
        $this->odooMapping(OdooAccountMapping::KIND_COST_COMPONENT, $component, self::ODOO_EXPENSE_ACCOUNT, taxId: $taxId, analyticId: $analyticId);
        $counterpartExists = OdooAccountMapping::where('odoo_company_id', self::ODOO_COMPANY)
            ->where('fact_kind', OdooAccountMapping::KIND_COUNTERPART)
            ->where('fact_code', OdooAccountMapping::CODE_DEFAULT)
            ->exists();
        if (!$counterpartExists) {
            $this->odooMapping(OdooAccountMapping::KIND_COUNTERPART, OdooAccountMapping::CODE_DEFAULT, self::ODOO_COUNTERPART_ACCOUNT);
        }
    }

    protected function odooMapping(
        string $kind,
        string $code,
        int $account = self::ODOO_EXPENSE_ACCOUNT,
        string $channel = OdooAccountMapping::ANY,
        string $currency = OdooAccountMapping::ANY,
        ?int $taxId = null,
        ?int $analyticId = null,
    ): OdooAccountMapping {
        return OdooAccountMapping::create([
            'seller_id' => 0,
            'odoo_company_id' => self::ODOO_COMPANY,
            'fact_kind' => $kind,
            'fact_code' => $code,
            'sales_channel' => $channel,
            'currency' => $currency,
            'odoo_account_id' => $account,
            'odoo_journal_id' => self::ODOO_JOURNAL,
            'odoo_tax_id' => $taxId,
            'odoo_analytic_account_id' => $analyticId,
            'approved_by' => 1,
            'approved_at' => now(),
            'verified_at' => now(),
        ]);
    }

    /**
     * What a real instance reported, as a preflight.
     *
     * @param list<string>|null $moveFields
     * @param list<string>|null $lineFields
     * @param array<string, mixed>|null $company
     * @param array<string, int>|null $currencies Odoo's own currency ids by code; `[]` is an instance whose list was not usable
     */
    protected function odooPreflight(?array $moveFields = null, ?array $lineFields = null, ?array $company = null, ?array $currencies = null): OdooPreflight
    {
        $move = $moveFields ?? [...OdooPreflight::REQUIRED_FIELDS['account.move'], ...OdooPreflight::OPTIONAL_FIELDS['account.move']];
        $line = $lineFields ?? [...OdooPreflight::REQUIRED_FIELDS['account.move.line'], 'tax_ids', 'currency_id', 'amount_currency', 'analytic_distribution'];

        return new OdooPreflight(
            instance: new OdooInstance(true, true, OdooTransport::XmlRpc, '18.0', 18, 7, 'books'),
            models: array_fill_keys(OdooPreflight::MODELS, true),
            fields: [
                'account.move' => array_fill_keys($move, ['type' => 'char', 'label' => null]),
                'account.move.line' => array_fill_keys($line, ['type' => 'char', 'label' => null]),
            ],
            company: $company ?? ['id' => self::ODOO_COMPANY, 'currency_id' => self::ODOO_CURRENCY_ID, 'currency_name' => self::ODOO_CURRENCY_CODE],
            currencies: $currencies ?? [self::ODOO_CURRENCY_CODE => self::ODOO_CURRENCY_ID, 'USD' => self::ODOO_FOREIGN_CURRENCY_ID],
        );
    }

    /** One XML-RPC `methodResponse` carrying a value. */
    protected function odooXml(mixed $value): string
    {
        return '<?xml version="1.0"?><methodResponse><params><param><value>' . $this->odooValue($value) . '</value></param></params></methodResponse>';
    }

    protected function odooFault(int $code, string $message): string
    {
        return '<?xml version="1.0"?><methodResponse><fault><value>'
            . $this->odooValue(['faultCode' => $code, 'faultString' => $message])
            . '</value></fault></methodResponse>';
    }

    protected function odooValue(mixed $value): string
    {
        if (is_int($value)) {
            return '<int>' . $value . '</int>';
        }
        if (is_bool($value)) {
            return '<boolean>' . ($value ? '1' : '0') . '</boolean>';
        }
        if (is_array($value) && array_is_list($value)) {
            $xml = '<array><data>';
            foreach ($value as $item) {
                $xml .= '<value>' . $this->odooValue($item) . '</value>';
            }

            return $xml . '</data></array>';
        }
        if (is_array($value)) {
            $xml = '<struct>';
            foreach ($value as $name => $item) {
                $xml .= '<member><name>' . $name . '</name><value>' . $this->odooValue($item) . '</value></member>';
            }

            return $xml . '</struct>';
        }

        return '<string>' . htmlspecialchars((string) $value, ENT_XML1) . '</string>';
    }
}
