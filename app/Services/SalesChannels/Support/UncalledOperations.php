<?php

namespace App\Services\SalesChannels\Support;

/**
 * Every operation this application has REGISTERED but never calls — each with the reason, in one place.
 *
 * ## Why a registered operation that nothing calls is worth a file of its own
 *
 * Registering an operation is a promise: it names a path, a method, the capabilities it needs and the approval it relies on,
 * and the transport will carry it. Twenty of the hundred and five registered here are never named by any code. Read charitably
 * that is forward planning; read honestly it is the difference between "this integration does that" and "this integration
 * could". The gap matrix kept saying the first about several of the second, and the only cure is a list that the tests check.
 *
 * ## The four kinds, and they are not interchangeable
 *
 *  - `DECIDED` — built deliberately no further. Somebody weighed it and the answer was no. Cancelling an order's lines locks
 *    the product at the channel; sending a patient's invoice needs a public URL this pharmacy will not publish.
 *  - `NOT_BUILT` — ordinary unfinished work. No obstacle, nobody has done it. This is the honest label for most of the list
 *    and the one the matrix used to blur.
 *  - `NEEDS_AN_ACCOUNT` — the code could be written but could not be verified without a real seller account or credentials
 *    nobody here holds, so writing it would be writing against a guess.
 *  - `PARTLY_BUILT` — some of the pieces exist and the operation itself is still not made. Amazon's feeds are the case:
 *    the submission document and the result reader are built and tested, and not one of the five calls is wired.
 *
 * `ChannelOperationHygieneTest` compares this list against a scan of the code. An operation that stops being called without
 * being listed here fails; one listed here that something starts calling fails too. So the list cannot rot in either
 * direction, which is what went wrong before.
 */
final class UncalledOperations
{
    public const DECIDED = 'decided';

    public const NOT_BUILT = 'not_built';

    public const NEEDS_AN_ACCOUNT = 'needs_an_account';

    public const PARTLY_BUILT = 'partly_built';

    /**
     * @return array<string, array{kind: string, why: string}>
     */
    public static function all(): array
    {
        return [
            // ---- Amazon feeds: the pieces exist, the calls do not -------------------------------------------------
            // The submission document (`AmazonListingsFeed`) and the processing-report reader (`AmazonFeedResult`) are built
            // and tested against Amazon's published schemas. **Nothing sends one.** What is missing is the four-call flow
            // (createFeedDocument, the PUT upload, createFeed, poll getFeed, getFeedDocument), somewhere to keep the
            // `feedId` and the messageId-to-SKU map between those calls, and the fence that stops one feed being submitted
            // twice. Amazon also states feeds "can take up to eight hours to process" and are "processed sequentially",
            // against 25,000 Listings Items PATCHes at 5 a second -- so the feed is the cheaper path, not the faster one.
            'amazon.feeds_create_document' => ['kind' => self::PARTLY_BUILT, 'why' => 'amazon_feed_submission_is_not_wired'],
            'amazon.feeds_upload' => ['kind' => self::PARTLY_BUILT, 'why' => 'amazon_feed_submission_is_not_wired'],
            'amazon.feeds_create' => ['kind' => self::PARTLY_BUILT, 'why' => 'amazon_feed_submission_is_not_wired'],
            'amazon.feeds_get' => ['kind' => self::PARTLY_BUILT, 'why' => 'amazon_feed_submission_is_not_wired'],
            'amazon.feeds_document' => ['kind' => self::PARTLY_BUILT, 'why' => 'amazon_feed_submission_is_not_wired'],

            // ---- Amazon notifications: no documentation problem, an infrastructure one ----------------------------
            // There is NO `ORDER_CREATED` notification type at all; `ORDER_CHANGE` is "order status changes and buyer
            // requested cancelations", about an order that already exists. So notifications can only ever make the order
            // cycle faster and never replace the polling that finds an order. And reading the queue needs the seller's own
            // AWS account and a standard SQS queue whose policy grants `arn:aws:iam::437568002678:root`.
            'amazon.notifications_read' => ['kind' => self::NEEDS_AN_ACCOUNT, 'why' => 'amazon_notifications_need_the_sellers_own_aws_queue'],
            'amazon.notifications_write' => ['kind' => self::NEEDS_AN_ACCOUNT, 'why' => 'amazon_notifications_need_the_sellers_own_aws_queue'],

            // Financial event GROUPS. The statements themselves are read and reconciled through `finances_v2024_06_19`;
            // the group listing would let a settlement period be found by its own identity rather than by a date window.
            // Ordinary unfinished work, and the only thing it would improve is how a period is located.
            'amazon.finances_groups' => ['kind' => self::NOT_BUILT, 'why' => 'amazon_finance_groups_would_only_change_how_a_period_is_found'],

            // ---- Trendyol: lookups nothing asks for yet ----------------------------------------------------------
            // Category attributes ARE read (that is `category_attributes`); the category TREE is not, because a listing is
            // created against a category a person chose on the product screen rather than browsed here.
            'trendyol.categories' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_category_tree_is_not_browsed_here'],
            // The long value lists behind an attribute. The attribute call already returns the values Trendyol embeds, and
            // this is the endpoint for the ones too long to embed -- so a category whose values are paged is a real gap.
            'trendyol.category_attribute_values' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_long_attribute_value_lists_are_not_paged'],
            // Trendyol's own carrier codes. Today the mapping is configured per account by hand, which is why a wrong code
            // is a refusal with a sentence rather than a list a person can pick from.
            'trendyol.cargo_providers' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_carrier_codes_are_configured_by_hand'],
            // The seller's own warehouse and return addresses. Needed to create a listing with a shipment address, which
            // this version does not set.
            'trendyol.addresses' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_seller_addresses_are_not_read'],
            // Reading back price and stock as Trendyol holds them. The publisher compares with what IT last wrote, so this
            // would be a reconciliation of the two -- worth having and not built.
            'trendyol.products_inventory_price' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_price_and_stock_are_not_read_back_for_reconciliation'],
            'trendyol.product_by_barcode' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_single_barcode_lookup_is_not_used'],
            // Archiving and unlocking a product. Both change a listing's life and neither is reachable from a screen yet.
            'trendyol.products_archive' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_archiving_has_no_screen'],
            'trendyol.products_unlock' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_unlocking_has_no_screen'],
            // **A DECISION, and the owner has taken it.** Invoices stay with the Trendyol seller panel: the contract is
            // verified, and what is missing is a URL reachable without a login for ten years in Saudi Arabia. Publishing one
            // would put a customer's name, address, telephone and medicines behind a link anybody holding it could read.
            'trendyol.orders_invoice_link' => ['kind' => self::DECIDED, 'why' => 'trendyol_invoices_stay_with_the_seller_panel'],
            // **A DECISION.** Cancelling lines cancels a customer's order and locks the product at Trendyol.
            'trendyol.orders_unsupplied' => ['kind' => self::DECIDED, 'why' => 'trendyol_cancelling_lines_locks_the_product'],
            // Payment orders: when Trendyol actually paid out. The statements are read; this is the bank side of them.
            'trendyol.finance_payment_orders' => ['kind' => self::NOT_BUILT, 'why' => 'trendyol_payout_dates_are_not_read'],

            // ---- Odoo -------------------------------------------------------------------------------------------
            // Listing the instance's models. The preflight asks about the models it needs BY NAME and reports what is
            // missing, which is the narrower and safer question.
            'odoo.model_list' => ['kind' => self::NOT_BUILT, 'why' => 'odoo_preflight_asks_about_named_models_instead'],
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /** @return list<string> */
    public static function ofKind(string $kind): array
    {
        return array_keys(array_filter(self::all(), static fn (array $entry): bool => $entry['kind'] === $kind));
    }

    public static function kindOf(string $operation): ?string
    {
        return self::all()[$operation]['kind'] ?? null;
    }

    public static function whyOf(string $operation): ?string
    {
        return self::all()[$operation]['why'] ?? null;
    }
}
