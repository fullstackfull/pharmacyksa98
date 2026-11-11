<?php

namespace App\Http\Controllers\Admin\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\RoutingAction;

/**
 * The words the sales-channel screens use for codes the backend produces (reasons, results, states, statuses).
 *
 * Every code is translated through a FAMILY of keys (`order_reason_` + code). A code this version does not know is shown as it came, never passed to translate():
 * translate() writes an unknown key into the language files, so a new backend code would otherwise dirty the repository and show an untranslated phrase.
 *
 * TRANSLATION_KEYS is the complete list of the keys built here (and by WriteState and the capability ladder), for whoever maintains the language files. A test keeps it
 * identical to FAMILIES, so a family cannot grow a code without its key being listed.
 */
final class ChannelLabels
{
    public const NONE = '—';

    /** @var array<string, list<string>> key prefix => the codes that have a translation */
    public const FAMILIES = [
        // Why an order waits (a routing decision's reason code, and the review reason on the order).
        'order_reason_' => [
            'channel_fulfills_order', 'unknown_fulfillment_mode', 'order_not_releasable', 'no_active_lines', 'carrier_arranged_by_channel', 'cod_requires_review',
            'unmapped_lines', 'no_rule_matched', 'account_default_externally_managed', 'rule_requires_manual_review', 'rule_holds_order', 'rule_route_to_provider', 'rule_monitor_only', 'rule_channel_fulfills',
            'line_has_no_stock_location', 'stock_not_at_target_provider', 'managed_dispatch_not_available', 'split_replaced', 'modified_after_routing',
            'cancelled_after_dispatch', 'split_after_dispatch', 'ambiguous_shipment', 'shipment_linked_elsewhere', 'managed_shipment_failed', 'managed_shipment_cancelled', 'shipment_not_found', 'managed_cancel_not_confirmed',
        ],
        // Why nothing was sent to a channel (a switch that is off, an approval that is missing) or a shipment was not reported.
        'channel_reason_' => [
            'global_off', 'account_off', 'capability_off', 'setting_off', 'not_supported_by_channel', 'writes_off_environment', 'writes_off_account', 'not_approved', 'unregistered_operation', 'auth_needed',
            'no_linked_shipment_with_tracking', 'already_notified', 'fulfillment_dispatch_off', 'dry_run', 'channel_did_not_confirm', 'channel_refused_repeatedly', 'channel_notify_error',
            'order_pending', 'order_awaiting_review', 'order_delivered', 'order_cancelled', 'order_returned', 'order_failed',
            'rate_limited', 'buyer_data_off', 'setting_missing', 'another_attempt_running',
        ],
        /*
        | The Odoo accounting screens' own codes.
        |
        | They live here rather than in a class of their own because this is where the translation guard and the hygiene guard
        | already look, and a second home for label families is a second place to forget. Odoo is not a sales channel; its words
        | are simply kept with the others.
        */
        // What the outbox says about one fact. `may_have_posted` is the one that matters: a create may be in the customer's
        // books, so nothing creates from that row again.
        'odoo_posting_state_' => ['pending', 'may_have_posted', 'posted', 'failed', 'needs_review', 'dead'],
        // Which of Odoo's two external APIs an instance was DISCOVERED to speak. `OdooTransport::labelKey()` builds these.
        'odoo_transport_' => ['json2', 'xmlrpc', 'unknown'],
        // What a mapping row maps: one of our cost components, or the account that balances it.
        'odoo_fact_kind_' => ['cost_component', 'counterpart'],

        /*
        | What waits for a person. Three families, because the screen has to name three different things: WHERE a row is
        | waiting, WHICH part of the business it belongs to, and WHAT state it is in -- and the states come from nine tables
        | that were built separately and do not share a vocabulary.
        */
        'attention_source_' => [
            'return_decisions', 'package_splits', 'shipment_sends', 'dispatches', 'stock_events',
            'odoo_postings', 'amazon_reports', 'payment_mismatches', 'refunds', 'order_links',
        ],
        'attention_domain_' => ['returns', 'shipments', 'stock', 'accounting', 'finance', 'payments', 'orders'],
        // What a person SAW at the channel. A fixed vocabulary, never free text: a word nobody can group is not a record.
        'attention_outcome_' => ['confirmed_at_channel', 'not_done_at_channel', 'still_unknown', 'no_longer_relevant'],
        'attention_state_' => [
            'may_have_sent', 'needs_review', 'may_have_posted', 'dead', 'blocked', 'failed', 'unknown', 'mismatch', 'rejected',
        ],
        // Why a row cannot simply be sent again -- a fact about the integration, which is why it is a code and not a sentence
        // in the view. Eight of the nine sources have one.
        'attention_why_' => [
            'return_decisions', 'package_splits', 'shipment_sends', 'dispatches',
            'odoo_postings', 'amazon_reports', 'payment_mismatches', 'refunds', 'order_links',
        ],

        'sync_resource_' => ['orders', 'returns', 'listings', 'inventory', 'finances', 'publish_stock', 'publish_price'],
        'run_stat_' => [
            'pages', 'created', 'updated', 'unchanged', 'stale', 'needs_review', 'skipped', 'decisions', 'linked', 'suggested', 'conflicts', 'unseen', 'stored', 'unlinked',
            'new', 'duplicates', 'costs_recorded', 'considered', 'sent', 'accepted', 'failed', 'applied', 'unconfirmed', 'pending', 'unreadable', 'unknown_order',
        ],
        'publish_skip_' => [
            'fba_listing', 'fulfillment_mode_unknown', 'no_stock_source', 'location_not_for_this_channel', 'stock_not_confirmed', 'unchanged', 'awaiting_result', 'failed_repeatedly',
            'channel_owns_price', 'storefront_price_not_supported', 'no_manual_price', 'price_not_positive',
            'variant_key_required', 'storefront_owned_needs_owner_decision', 'order_sync_stale', 'deficit_block', 'awaiting_siblings', 'not_in_the_pool', 'not_worth_lowering_now', 'outside_the_pool', 'variant_figure_missing',
        ],
        'publish_state_' => ['queued', 'applied', 'failed', 'unconfirmed'],
        'listing_status_' => ['unmapped', 'suggested', 'mapped', 'ignored', 'conflict'],
        'match_method_' => ['ambiguous', 'already_linked_elsewhere', 'product_code', 'barcode', 'variant_sku', 'exact_product_code_auto', 'exact_barcode_auto', 'exact_variant_sku_auto', 'manual'],
        'shipment_link_state_' => ['unlinked', 'linked', 'needs_review', 'conflict', 'not_applicable'],
        'event_outcome_' => ['received', 'recorded_only', 'needs_review', 'applied', 'ignored_capability_off', 'ignored_invalid', 'failed', 'duplicate'],
        // Why an event is kept for a person: a statement row that may repeat a charge the API gave, a refund whose order or line is not (yet) found.
        'event_note_' => ['possible_overlap', 'order_not_held', 'line_not_matched', 'currency_differs'],
        'event_source_' => ['webhook', 'notification', 'poll', 'system', 'manual'],
        'stock_quality_' => ['confirmed', 'manual', 'stale', 'unknown'],
        'location_type_' => ['torod_warehouse', 'm5azn_supplier', 'amazon_fba', 'own_warehouse', 'other'],
        'location_source_' => ['provider', 'channel', 'manual', 'platform'],
        'price_source_' => ['internal', 'channel', 'manual'],
        'stock_share_mode_' => ['fixed', 'percent', 'priority'],
        'price_source_help_' => ['internal', 'channel', 'manual'],
        // Every approval a master admin can record, from all four integrations. Two of them were added on separate branches
        // and each is its own decision: `return_decisions` moves a customer's money, `accounting_posting` writes into
        // somebody's books, and neither belongs inside the other or inside `product_writes`.
        'approval_scope_' => ['product_writes', 'webhook_management', 'notification_management', 'buyer_data_transfer', 'return_decisions', 'accounting_posting'],
        'approval_scope_help_' => ['product_writes', 'webhook_management', 'notification_management', 'buyer_data_transfer', 'return_decisions', 'accounting_posting'],
        'dispatch_state_' => ['claimed', 'blocked', 'materialized', 'accepted', 'failed', 'cancelled'],
        'dispatch_reason_' => ['payment_method_unknown', 'database_error', 'orders_table_not_supported', 'managed_dispatch_off', 'buyer_data_transfer_off', 'account_off', 'dispatch_capability_off', 'buyer_data_approval_missing', 'shipments_are_created_by_the_provider_directly', 'shipment_creation_not_declared', 'already_dispatched', 'shipment_already_known', 'order_not_open', 'fulfilment_not_ours', 'cash_on_delivery_not_supported', 'destination_not_supported', 'currency_not_supported', 'no_routing_decision_to_one_provider', 'no_active_lines', 'line_without_product', 'product_not_physical', 'product_owned_by_other_provider', 'provider_mapping_not_approved', 'claim_lost', 'provider_changed', 'recipient_incomplete', 'audit_not_written', 'order_number_busy', 'attempts_exhausted', 'channel_cannot_give_the_recipient', 'order_not_found_at_channel', 'not_shipped_by_the_seller', 'recipient_not_permitted', 'channel_error', 'channel_buyer_data_off', 'channel_not_approved', 'channel_account_off', 'channel_capability_off', 'channel_global_off', 'channel_auth_needed', 'buyer_data_expired'],
        'dispatch_problem_' => ['name_missing', 'phone_missing', 'phone_not_a_saudi_mobile', 'address_missing', 'city_missing', 'country_not_supported'],
        'shipment_creation_' => ['unset', 'app', 'provider_direct'],
        'dispatch_mode_' => ['manual', 'auto'],
        'integration_capability_help_' => ['connection_test', 'read_only_sync', 'product_sync', 'inventory_publishing', 'price_publishing', 'order_import', 'fulfillment_dispatch', 'financial_reconciliation'],
        'ladder_blocker_' => ['requires', 'connection_not_proven', 'connection_stale', 'missing_permissions'],
        'write_state_' => ['global_off', 'account_disabled', 'rehearsal', 'environment_read_only', 'writes_not_allowed', 'live'],
        'write_state_help_' => ['global_off', 'account_disabled', 'rehearsal', 'environment_read_only', 'writes_not_allowed', 'live'],
        'environment_' => ['production', 'sandbox'],
        'carrier_payer_' => ['channel', 'seller'],
        'secret_' => ['lwa_client_id', 'lwa_client_secret', 'refresh_token', 'api_key', 'api_secret', 'webhook_api_key'],
        'response_language_' => ['ar', 'en', 'tr'],
        'trendyol_storefront_' => ['SA', 'AE', 'KW', 'none'],
        'amazon_region_' => ['na', 'eu', 'fe'],
        'torod_reference_' => ['order_number', 'package_id', 'tracking_number'],
        // What each connector says it can and cannot do (the notes under each name come from the connector and are shown as written).
        'capability_matrix_' => [
            'basic_authentication', 'product_v2', 'product_create_update', 'order_v2', 'shipment_packages', 'picking_and_tracking', 'cancel_unsupplied', 'split_packages',
            'invoice_upload', 'webhooks', 'claims_returns', 'current_account_statement', 'fulfilment_by_trendyol',
            'lwa_authentication', 'orders_v2026_01_01', 'fba_vs_fbm', 'listings_items', 'catalog_items', 'product_type_definitions', 'fba_inventory', 'finances_v2024_06_19',
            'feeds_json_listings', 'confirm_shipment', 'notifications', 'reports', 'sandbox',
        ],
    ];

    /** @var list<string> every key built from FAMILIES, in full */
    public const TRANSLATION_KEYS = [
        'order_reason_channel_fulfills_order', 'order_reason_unknown_fulfillment_mode', 'order_reason_order_not_releasable', 'order_reason_no_active_lines',
        'order_reason_carrier_arranged_by_channel', 'order_reason_cod_requires_review', 'order_reason_unmapped_lines', 'order_reason_no_rule_matched',
        'order_reason_account_default_externally_managed',
        'order_reason_rule_requires_manual_review', 'order_reason_rule_holds_order', 'order_reason_rule_route_to_provider', 'order_reason_rule_monitor_only',
        'order_reason_rule_channel_fulfills', 'order_reason_line_has_no_stock_location', 'order_reason_stock_not_at_target_provider',
        'order_reason_managed_dispatch_not_available', 'order_reason_split_replaced', 'order_reason_modified_after_routing', 'order_reason_cancelled_after_dispatch',
        'order_reason_split_after_dispatch', 'order_reason_ambiguous_shipment', 'order_reason_shipment_linked_elsewhere', 'order_reason_managed_shipment_failed', 'order_reason_managed_shipment_cancelled', 'order_reason_shipment_not_found', 'order_reason_managed_cancel_not_confirmed',
        'channel_reason_global_off', 'channel_reason_account_off', 'channel_reason_capability_off', 'channel_reason_setting_off', 'channel_reason_not_supported_by_channel', 'channel_reason_writes_off_environment',
        'channel_reason_writes_off_account', 'channel_reason_not_approved', 'channel_reason_unregistered_operation', 'channel_reason_auth_needed',
        'channel_reason_no_linked_shipment_with_tracking', 'channel_reason_already_notified', 'channel_reason_fulfillment_dispatch_off', 'channel_reason_dry_run',
        'channel_reason_channel_did_not_confirm', 'channel_reason_channel_refused_repeatedly', 'channel_reason_channel_notify_error', 'channel_reason_order_pending', 'channel_reason_order_awaiting_review',
        'channel_reason_order_delivered', 'channel_reason_order_cancelled', 'channel_reason_order_returned', 'channel_reason_order_failed',
        'channel_reason_rate_limited', 'channel_reason_buyer_data_off', 'channel_reason_setting_missing', 'channel_reason_another_attempt_running',
        'odoo_posting_state_pending', 'odoo_posting_state_may_have_posted', 'odoo_posting_state_posted', 'odoo_posting_state_failed',
        'odoo_posting_state_needs_review', 'odoo_posting_state_dead',
        'odoo_transport_json2', 'odoo_transport_xmlrpc', 'odoo_transport_unknown',
        'odoo_fact_kind_cost_component', 'odoo_fact_kind_counterpart',
        // What waits for a person: where it is waiting, which area it belongs to, what state it is in, and why it is
        // never simply sent again.
        'attention_source_return_decisions', 'attention_source_package_splits', 'attention_source_shipment_sends', 'attention_source_dispatches',
        'attention_source_stock_events', 'attention_source_odoo_postings', 'attention_source_amazon_reports', 'attention_source_payment_mismatches',
        'attention_source_refunds', 'attention_source_order_links',
        'attention_outcome_confirmed_at_channel', 'attention_outcome_not_done_at_channel', 'attention_outcome_still_unknown',
        'attention_outcome_no_longer_relevant',
        'attention_domain_returns', 'attention_domain_shipments', 'attention_domain_stock', 'attention_domain_accounting',
        'attention_domain_finance', 'attention_domain_payments', 'attention_domain_orders',
        'attention_state_may_have_sent', 'attention_state_needs_review', 'attention_state_may_have_posted', 'attention_state_dead',
        'attention_state_blocked', 'attention_state_failed', 'attention_state_unknown', 'attention_state_mismatch',
        'attention_state_rejected',
        'attention_why_return_decisions', 'attention_why_package_splits', 'attention_why_shipment_sends', 'attention_why_dispatches',
        'attention_why_odoo_postings', 'attention_why_amazon_reports', 'attention_why_payment_mismatches', 'attention_why_refunds',
        'attention_why_order_links',
        'sync_resource_orders', 'sync_resource_returns', 'sync_resource_listings', 'sync_resource_inventory', 'sync_resource_finances', 'sync_resource_publish_stock', 'sync_resource_publish_price',
        'run_stat_pages', 'run_stat_created', 'run_stat_updated', 'run_stat_unchanged', 'run_stat_stale', 'run_stat_needs_review', 'run_stat_skipped', 'run_stat_decisions',
        'run_stat_linked', 'run_stat_suggested', 'run_stat_conflicts', 'run_stat_unseen', 'run_stat_stored', 'run_stat_unlinked', 'run_stat_new', 'run_stat_duplicates',
        'run_stat_costs_recorded', 'run_stat_considered', 'run_stat_sent', 'run_stat_accepted', 'run_stat_failed', 'run_stat_applied', 'run_stat_unconfirmed', 'run_stat_pending', 'run_stat_unreadable', 'run_stat_unknown_order',
        'publish_skip_fba_listing', 'publish_skip_fulfillment_mode_unknown', 'publish_skip_no_stock_source', 'publish_skip_location_not_for_this_channel', 'publish_skip_stock_not_confirmed', 'publish_skip_unchanged',
        'publish_skip_awaiting_result', 'publish_skip_failed_repeatedly', 'publish_skip_channel_owns_price', 'publish_skip_storefront_price_not_supported',
        'publish_skip_no_manual_price', 'publish_skip_price_not_positive',
        'publish_skip_variant_key_required', 'publish_skip_storefront_owned_needs_owner_decision', 'publish_skip_order_sync_stale', 'publish_skip_deficit_block',
        'publish_skip_awaiting_siblings', 'publish_skip_not_in_the_pool', 'publish_skip_not_worth_lowering_now', 'publish_skip_outside_the_pool', 'publish_skip_variant_figure_missing',
        'publish_state_queued', 'publish_state_applied', 'publish_state_failed', 'publish_state_unconfirmed',
        'listing_status_unmapped', 'listing_status_suggested', 'listing_status_mapped', 'listing_status_ignored', 'listing_status_conflict',
        'match_method_ambiguous', 'match_method_already_linked_elsewhere', 'match_method_product_code', 'match_method_barcode', 'match_method_variant_sku',
        'match_method_exact_product_code_auto', 'match_method_exact_barcode_auto', 'match_method_exact_variant_sku_auto', 'match_method_manual',
        'shipment_link_state_unlinked', 'shipment_link_state_linked', 'shipment_link_state_needs_review', 'shipment_link_state_conflict', 'shipment_link_state_not_applicable',
        'event_outcome_received', 'event_outcome_recorded_only', 'event_outcome_needs_review', 'event_outcome_applied', 'event_outcome_ignored_capability_off',
        'event_outcome_ignored_invalid', 'event_outcome_failed', 'event_outcome_duplicate',
        'event_note_possible_overlap', 'event_note_order_not_held', 'event_note_line_not_matched', 'event_note_currency_differs',
        'event_source_webhook', 'event_source_notification', 'event_source_poll', 'event_source_system', 'event_source_manual',
        'stock_quality_confirmed', 'stock_quality_manual', 'stock_quality_stale', 'stock_quality_unknown',
        'location_type_torod_warehouse', 'location_type_m5azn_supplier', 'location_type_amazon_fba', 'location_type_own_warehouse', 'location_type_other',
        'location_source_provider', 'location_source_channel', 'location_source_manual', 'location_source_platform',
        'price_source_internal', 'price_source_channel', 'price_source_manual',
        'stock_share_mode_fixed', 'stock_share_mode_percent', 'stock_share_mode_priority',
        'price_source_help_internal', 'price_source_help_channel', 'price_source_help_manual',
        'approval_scope_product_writes', 'approval_scope_webhook_management', 'approval_scope_notification_management', 'approval_scope_buyer_data_transfer',
        'approval_scope_return_decisions', 'approval_scope_accounting_posting',
        'approval_scope_help_product_writes', 'approval_scope_help_webhook_management', 'approval_scope_help_notification_management', 'approval_scope_help_buyer_data_transfer',
        'approval_scope_help_return_decisions', 'approval_scope_help_accounting_posting',
        'dispatch_state_claimed',
        'dispatch_state_blocked',
        'dispatch_state_materialized',
        'dispatch_state_accepted',
        'dispatch_state_failed',
        'dispatch_state_cancelled',
        'dispatch_reason_payment_method_unknown',
        'dispatch_reason_database_error',
        'dispatch_reason_orders_table_not_supported',
        'dispatch_reason_managed_dispatch_off',
        'dispatch_reason_buyer_data_transfer_off',
        'dispatch_reason_account_off',
        'dispatch_reason_dispatch_capability_off',
        'dispatch_reason_buyer_data_approval_missing',
        'dispatch_reason_shipments_are_created_by_the_provider_directly',
        'dispatch_reason_shipment_creation_not_declared',
        'dispatch_reason_already_dispatched',
        'dispatch_reason_shipment_already_known',
        'dispatch_reason_order_not_open',
        'dispatch_reason_fulfilment_not_ours',
        'dispatch_reason_cash_on_delivery_not_supported',
        'dispatch_reason_destination_not_supported',
        'dispatch_reason_currency_not_supported',
        'dispatch_reason_no_routing_decision_to_one_provider',
        'dispatch_reason_no_active_lines',
        'dispatch_reason_line_without_product',
        'dispatch_reason_product_not_physical',
        'dispatch_reason_product_owned_by_other_provider',
        'dispatch_reason_provider_mapping_not_approved',
        'dispatch_reason_claim_lost',
        'dispatch_reason_provider_changed',
        'dispatch_reason_recipient_incomplete',
        'dispatch_reason_audit_not_written',
        'dispatch_reason_order_number_busy',
        'dispatch_reason_attempts_exhausted',
        'dispatch_reason_channel_cannot_give_the_recipient',
        'dispatch_reason_order_not_found_at_channel',
        'dispatch_reason_not_shipped_by_the_seller',
        'dispatch_reason_recipient_not_permitted',
        'dispatch_reason_channel_error',
        'dispatch_reason_channel_buyer_data_off',
        'dispatch_reason_channel_not_approved',
        'dispatch_reason_channel_account_off',
        'dispatch_reason_channel_capability_off',
        'dispatch_reason_channel_global_off',
        'dispatch_reason_channel_auth_needed',
        'dispatch_reason_buyer_data_expired',
        'dispatch_problem_name_missing',
        'dispatch_problem_phone_missing',
        'dispatch_problem_phone_not_a_saudi_mobile',
        'dispatch_problem_address_missing',
        'dispatch_problem_city_missing',
        'dispatch_problem_country_not_supported',
        'shipment_creation_unset',
        'shipment_creation_app',
        'shipment_creation_provider_direct',
        'dispatch_mode_manual',
        'dispatch_mode_auto',
        'integration_capability_help_connection_test', 'integration_capability_help_read_only_sync', 'integration_capability_help_product_sync',
        'integration_capability_help_inventory_publishing', 'integration_capability_help_price_publishing', 'integration_capability_help_order_import',
        'integration_capability_help_fulfillment_dispatch', 'integration_capability_help_financial_reconciliation',
        'ladder_blocker_requires', 'ladder_blocker_connection_not_proven', 'ladder_blocker_connection_stale', 'ladder_blocker_missing_permissions',
        'write_state_global_off', 'write_state_account_disabled', 'write_state_rehearsal', 'write_state_environment_read_only', 'write_state_writes_not_allowed', 'write_state_live',
        'write_state_help_global_off', 'write_state_help_account_disabled', 'write_state_help_rehearsal', 'write_state_help_environment_read_only',
        'write_state_help_writes_not_allowed', 'write_state_help_live',
        'environment_production', 'environment_sandbox',
        'carrier_payer_channel', 'carrier_payer_seller',
        'secret_lwa_client_id', 'secret_lwa_client_secret', 'secret_refresh_token', 'secret_api_key', 'secret_api_secret', 'secret_webhook_api_key',
        'response_language_ar', 'response_language_en', 'response_language_tr',
        'trendyol_storefront_SA', 'trendyol_storefront_AE', 'trendyol_storefront_KW', 'trendyol_storefront_none',
        'amazon_region_na', 'amazon_region_eu', 'amazon_region_fe',
        'torod_reference_order_number', 'torod_reference_package_id', 'torod_reference_tracking_number',
        'capability_matrix_basic_authentication', 'capability_matrix_product_v2', 'capability_matrix_product_create_update', 'capability_matrix_order_v2',
        'capability_matrix_shipment_packages', 'capability_matrix_picking_and_tracking', 'capability_matrix_cancel_unsupplied', 'capability_matrix_split_packages',
        'capability_matrix_invoice_upload', 'capability_matrix_webhooks', 'capability_matrix_claims_returns', 'capability_matrix_current_account_statement',
        'capability_matrix_fulfilment_by_trendyol', 'capability_matrix_lwa_authentication', 'capability_matrix_orders_v2026_01_01', 'capability_matrix_fba_vs_fbm',
        'capability_matrix_listings_items', 'capability_matrix_catalog_items', 'capability_matrix_product_type_definitions', 'capability_matrix_fba_inventory',
        'capability_matrix_finances_v2024_06_19', 'capability_matrix_feeds_json_listings', 'capability_matrix_confirm_shipment', 'capability_matrix_notifications',
        'capability_matrix_reports', 'capability_matrix_sandbox',
    ];

    /** What a routing decision decided; a value this version no longer knows is shown as stored. */
    public function routingAction(?string $action): string
    {
        $case = RoutingAction::tryFrom((string) $action);

        return $case === null ? ($action ?: self::NONE) : translate($case->labelKey());
    }

    public function fulfillmentMode(?string $mode): string
    {
        $case = FulfillmentMode::tryFrom((string) $mode);

        return $case === null ? ($mode ?: self::NONE) : translate($case->labelKey());
    }

    public function orderStatus(?string $status): string
    {
        $case = ChannelOrderStatus::tryFrom((string) $status);

        return $case === null ? ($status ?: self::NONE) : translate($case->labelKey());
    }

    /** Who pays the carrier of a package: the channel (it arranges the carrier too) or the seller. */
    public function carrierPayer(?string $payer): string
    {
        return $this->label('carrier_payer_', $payer);
    }

    /** How an order is being handled; null while nothing has been decided about it. */
    public function executionPolicy(?string $policy): string
    {
        $case = ExecutionPolicy::tryFrom((string) $policy);

        return $case === null ? ($policy ?: self::NONE) : translate($case->labelKey());
    }

    public function orderReason(?string $code): string
    {
        return $this->label('order_reason_', $code);
    }

    public function dispatchState(?string $state): string
    {
        return $this->label('dispatch_state_', $state);
    }

    public function shipmentCreation(?string $value): string
    {
        return $this->label('shipment_creation_', $value);
    }

    public function dispatchMode(?string $value): string
    {
        return $this->label('dispatch_mode_', $value);
    }

    /** Why a dispatch is blocked or waits: `recipient_incomplete:phone_missing` carries what the buyer's details lack. */
    public function dispatchReason(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return self::NONE;
        }
        [$code, $problems] = array_pad(explode(':', $reason, 2), 2, null);
        $missing = $problems === null ? [] : array_map(fn (string $problem): string => $this->label('dispatch_problem_', trim($problem)), explode(',', $problems));

        return $this->label('dispatch_reason_', $code) . ($missing === [] ? '' : ' (' . implode(', ', $missing) . ')');
    }

    /** The reason a channel call or a shipment report did not happen: a switch that is off, an approval that is missing, a shipment that is not ready. */
    public function channelReason(?string $code): string
    {
        return $this->label('channel_reason_', $code);
    }

    public function syncResource(string $resource): string
    {
        return $this->label('sync_resource_', $resource);
    }

    public function runStat(string $key): string
    {
        return $this->label('run_stat_', $key);
    }

    /** Why a listing was not sent: `stock_not_confirmed:stale` carries the quality of the figure that was not good enough. */
    public function publishSkip(string $reason): string
    {
        [$code, $detail] = array_pad(explode(':', $reason, 2), 2, null);

        return $detail === null ? $this->label('publish_skip_', $code) : $this->label('publish_skip_', $code) . ' (' . $this->stockQuality($detail) . ')';
    }

    public function publishState(?string $state): string
    {
        return $this->label('publish_state_', $state);
    }

    public function listingStatus(?string $status): string
    {
        return $this->label('listing_status_', $status);
    }

    public function matchMethod(?string $method): string
    {
        return $this->label('match_method_', $method);
    }

    public function shipmentLinkState(?string $state): string
    {
        return $this->label('shipment_link_state_', $state);
    }

    public function eventOutcome(?string $outcome): string
    {
        return $this->label('event_outcome_', $outcome);
    }

    /** Why an event is kept for a person; a note this version does not know is shown as it was stored. */
    public function eventNote(?string $note): string
    {
        return $this->label('event_note_', $note);
    }

    public function eventSource(?string $source): string
    {
        return $this->label('event_source_', $source);
    }

    public function stockQuality(?string $quality): string
    {
        return $this->label('stock_quality_', $quality);
    }

    public function locationType(?string $type): string
    {
        return $this->label('location_type_', $type);
    }

    /** Who is the reference for the figures held at a location. */
    public function locationSource(?string $source): string
    {
        return $this->label('location_source_', $source);
    }

    public function priceSource(?string $source): string
    {
        return $this->label('price_source_', $source);
    }

    /** How a listing takes its part of the stock it shares with other listings. */
    public function stockShareMode(?string $mode): string
    {
        return $this->label('stock_share_mode_', $mode);
    }

    public function priceSourceHelp(string $source): string
    {
        return $this->label('price_source_help_', $source);
    }

    /** An approval is recorded for a kind of write: either one of the write capabilities or one of the extra scopes. */
    public function approvalScope(string $scope): string
    {
        $capability = IntegrationCapability::tryFrom($scope);

        return $capability === null ? $this->label('approval_scope_', $scope) : translate($capability->labelKey());
    }

    public function approvalScopeHelp(string $scope): string
    {
        $capability = IntegrationCapability::tryFrom($scope);

        return $capability === null ? $this->label('approval_scope_help_', $scope) : $this->capabilityHelp($capability);
    }

    public function capabilityHelp(IntegrationCapability $capability): string
    {
        return $this->label('integration_capability_help_', $capability->value);
    }

    public function ladderBlocker(string $code): string
    {
        return $this->label('ladder_blocker_', $code);
    }

    public function environment(?string $environment): string
    {
        return $this->label('environment_', $environment);
    }

    /** The name of a stored credential, never its value. */
    public function secret(string $name): string
    {
        return $this->label('secret_', $name);
    }

    public function responseLanguage(string $code): string
    {
        return $this->label('response_language_', $code);
    }

    public function trendyolStorefront(?string $code): string
    {
        return $this->label('trendyol_storefront_', $code === null || $code === '' ? 'none' : $code);
    }

    public function amazonRegion(string $code): string
    {
        return $this->label('amazon_region_', $code);
    }

    public function torodReference(string $field): string
    {
        return $this->label('torod_reference_', $field);
    }

    public function connectorFeature(string $key): string
    {
        return $this->label('capability_matrix_', $key);
    }

    /**
     * A run's counts in one line. A count of zero says nothing and is left out; the map of reasons a listing was not sent is spelled out.
     *
     * @param array<string, mixed> $stats
     */
    public function statsLine(array $stats): string
    {
        $parts = [];
        foreach ($stats as $key => $value) {
            if ($key === 'dry_run' || $value === 0 || $value === [] || $value === null) {
                continue;
            }
            $parts[] = $this->runStat((string) $key) . ': ' . (is_array($value) ? $this->skippedMap($value) : $value);
        }

        return $parts === [] ? translate('run_stats_none') : implode(' · ', $parts);
    }

    /** @param array<string, int> $reasons */
    private function skippedMap(array $reasons): string
    {
        return implode(', ', array_map(fn (string $reason, int $count): string => $this->publishSkip($reason) . ' ×' . $count, array_keys($reasons), $reasons));
    }

    private function label(string $prefix, ?string $code): string
    {
        if ($code === null || $code === '') {
            return self::NONE;
        }

        return in_array($code, self::FAMILIES[$prefix], true) ? translate($prefix . $code) : $code;
    }
}
