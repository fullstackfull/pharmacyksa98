<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * The inferred core schema, the fulfillment tables, and this feature's own tables - all built from the REAL migrations - for tests of
 * the sales-channel, routing and operational-cost modules. Two extra roles exist for the permission tests: admin 4 holds only
 * `sales_channels`, admin 5 only `operational_costs`.
 */
trait BuildsSalesChannelSchema
{
    use AssertsNoCredentialWasLogged;
    use BuildsFulfillmentSchema;

    protected function buildSalesChannelSchema(): void
    {
        // **Every suite built on this trait is guarded against logging a credential.** The Odoo API key reached
        // `sales_channel_api_logs` on every read and every write and nothing noticed, because a leak is a property of what a
        // particular connector puts where -- only a real call shows it. These suites already make hundreds of real calls
        // against faked endpoints with real secrets in the store, so the verdict is read off the calls they already make.
        $this->guardAgainstLoggedCredentials();

        $this->buildCoreSchema();
        $this->runFulfillmentMigrations();
        $this->runSalesChannelMigrations();

        DB::table('admin_roles')->insert([
            ['id' => 4, 'name' => 'Sales channels', 'module_access' => json_encode(['sales_channels']), 'status' => 1],
            ['id' => 5, 'name' => 'Operational costs', 'module_access' => json_encode(['operational_costs']), 'status' => 1],
        ]);
        DB::table('admins')->insert([
            ['id' => 4, 'name' => 'Channels admin', 'email' => 'channels@example.test', 'admin_role_id' => 4],
            ['id' => 5, 'name' => 'Costs admin', 'email' => 'costs@example.test', 'admin_role_id' => 5],
        ]);
    }

    /** Run the REAL migrations of the operational-cost, sales-channel and inventory/routing tables. */
    protected function runSalesChannelMigrations(): void
    {
        foreach ([
            '2026_11_07_100000_create_operational_cost_tables.php',
            '2026_11_07_100100_create_sales_channel_tables.php',
            '2026_11_07_100200_create_inventory_and_routing_tables.php',
            '2026_11_08_100000_add_state_before_dispute_to_operational_cost_entries.php',
            '2026_11_09_100000_add_refund_columns_to_external_order_lines.php',
            '2026_11_09_100100_add_charge_matching_to_sales_channel_events.php',
            '2026_11_09_100200_create_operational_cost_return_decisions_table.php',
            '2026_11_09_100300_add_basis_to_operational_cost_reconciliations.php',
            '2026_11_09_200000_create_channel_order_dispatches_table.php',
            '2026_11_10_100000_add_stock_allocation.php',
            '2026_11_11_100000_add_purchase_order_sources_to_cost_tables.php',
            '2026_11_12_100000_create_channel_listing_drafts_table.php',
            '2026_11_13_100000_create_channel_stock_events_table.php',
            '2026_11_14_100000_add_catalog_match_to_channel_listing_drafts.php',
            '2026_11_16_100000_create_channel_shipment_sends_table.php',
            '2026_11_17_100000_create_amazon_report_runs_table.php',
            '2026_11_17_200000_create_channel_return_decisions_table.php',
            '2026_11_18_100000_create_channel_package_splits_table.php',
            '2026_11_20_100000_create_channel_attention_reviews_table.php',
        ] as $file) {
            (require database_path('migrations/' . $file))->up();
        }
    }
}
