<?php

/*
|--------------------------------------------------------------------------
| Sales channels (Amazon, Trendyol)
|--------------------------------------------------------------------------
|
| Deployment-level defaults only. Credentials are NEVER read from this file or from the environment: they are entered in the admin panel and stored
| encrypted on the channel account. Everything ships OFF.
|
| Outbound traffic needs three independent switches, all of which must be on for a write to leave this host:
|   1. SALES_CHANNELS_ENABLED               - the global kill switch: off means no call of any kind, not even a connection test.
|   2. SALES_CHANNELS_EXTERNAL_WRITES_ENABLED - off means READ-ONLY: only GETs and sign-ins leave the host, whatever the admin panel says (a request for an Amazon report is a POST classed
|      as a read, and nothing calls it).
|   3. the account's own switches (enabled, write enabled, dry run, and the capability being used with its approval).
|
|   4. SALES_CHANNELS_MANAGED_DISPATCH_ENABLED - off means no channel order is ever turned into a provider shipment by this application.
|   5. SALES_CHANNELS_BUYER_DATA_TRANSFER_ENABLED - off means the buyer's name, phone and address are never read from a channel (they are needed only to ship an order through a provider).
|
| The environment values are read with FILTER_VALIDATE_BOOLEAN, not a (bool) cast: the cast turns "off", "no" and "disabled" into TRUE.
*/
return [
    'enabled' => filter_var(env('SALES_CHANNELS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'external_writes_enabled' => filter_var(env('SALES_CHANNELS_EXTERNAL_WRITES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Classes whose static operations() list the calls each connector may make (see ChannelOperations).
    'operation_providers' => [
        \App\Services\SalesChannels\Trendyol\TrendyolOperations::class,
        \App\Services\SalesChannels\Amazon\AmazonOperations::class,
        \App\Services\SalesChannels\Noon\NoonOperations::class,
        // Odoo is not a sales channel. It is registered here so that writing into somebody's books goes through the same host
        // switch, account switch, rehearsal mode, recorded approval and redacted audit log as every other outbound call.
        \App\Services\Accounting\Odoo\OdooOperations::class,
    ],

    'http' => [
        'connect_timeout' => 5,
        'timeout' => 30,
        // Reads (and writes that set an absolute value, so that repeating them is harmless) are retried this many times on a rate limit, a
        // 5xx or a dropped connection, with exponential back-off and the channel's own Retry-After when it sends one.
        'retries' => 3,
        'retry_base_milliseconds' => 500,
        'retry_max_seconds' => 30,
    ],

    // Deciding a customer's return at the channel. One decision per claim item ever (`channel_return_decisions`), recorded
    // before it is sent, because Trendyol documents no idempotency for either decision and publishes no way to ask what became
    // of one.
    'returns' => [
        // How long a run that claimed a decision and never finished holds it before another may take it. Short, because the
        // decision itself is already recorded: what is being handed over is only the sending.
        'claim_minutes' => 10,
    ],

    // Cutting a shipment package into several (`channel_package_splits`). Trendyol documents no idempotency for any of its
    // four split endpoints, says nothing about a repeated identical request, and creates the new packages ASYNCHRONOUSLY -- so
    // the intent is recorded before the request leaves and the outcome is then read off the order rather than assumed.
    'splits' => [
        // How long a run that claimed a split and never finished holds it before another may take it.
        'claim_minutes' => 10,
        // How long to wait before reading the order again, looking for the proof that the cut landed. The split is
        // asynchronous: a package that has not changed yet is not a package that was never cut.
        'check_seconds' => 60,
        // How many such reads before the row is handed to a person. Nothing is ever sent again either way: what runs out here
        // is the patience to keep asking, not the willingness to retry.
        'max_checks' => 8,
    ],

    // What waits for a person, gathered from nine tables into one screen (`AttentionCollector`). It reads and sends
    // NOTHING: every state it shows is one a pipeline reached because the outcome was not knowable, and the value of those
    // states is that no machine touches them again. So there is one number here and it is only about how the screen looks.
    'attention' => [
        // After this long with nobody looking, a row is shown as late. A fenced refund is not an emergency in its first hour
        // and is one after a day.
        'late_after_hours' => 24,
    ],

    'logs' => [
        // `channels:prune` (daily) deletes call logs older than this and clears the stored text of old webhook deliveries.
        'retention_days' => 60,
        'max_body_bytes' => 8192,
    ],

    // Our own pacing: at or a little under each channel's published limit (equal for Amazon listings, FBA inventory, transactions and confirmShipment; lower for order search, feeds and the Trendyol default),
    // so the channel's 429 is the exception. A fixed window: `max` calls per `per_seconds`.
    // `bucket` makes operations that the channel counts together share one allowance (the connection test's order probe uses the order search's).
    'rate_limits' => [
        'trendyol' => [
            // Trendyol: 50 requests per 10 seconds to the same endpoint, then HTTP 429; no Retry-After is sent.
            'default' => ['max' => 40, 'per_seconds' => 10],
            'operations' => [
                'trendyol.orders_list' => ['max' => 25, 'per_seconds' => 60],
                'trendyol.order_recipient' => ['max' => 25, 'per_seconds' => 60],
                'trendyol.orders_stream' => ['max' => 10, 'per_seconds' => 60],
                'trendyol.probe_orders' => ['max' => 10, 'per_seconds' => 60, 'bucket' => 'trendyol.orders_stream'],
                'trendyol.probe_finance' => ['max' => 80, 'per_seconds' => 60, 'bucket' => 'trendyol.finance_settlements'],
                'trendyol.claims_list' => ['max' => 100, 'per_seconds' => 60],
                'trendyol.finance_settlements' => ['max' => 80, 'per_seconds' => 60],
                'trendyol.finance_other' => ['max' => 80, 'per_seconds' => 60],
                // İade Servisleri: approve and reject are each 5 requests a minute, which is the tightest limit Trendyol
                // publishes anywhere. A screen that let somebody click through a page of returns would hit it, so the pacing
                // is here rather than in a person's hands.
                'trendyol.claims_approve' => ['max' => 5, 'per_seconds' => 60],
                'trendyol.claims_reject' => ['max' => 5, 'per_seconds' => 60],
                'trendyol.price_inventory_update' => ['max' => 300, 'per_seconds' => 60],
                'trendyol.products_create' => ['max' => 150, 'per_seconds' => 60],
                'trendyol.products_update' => ['max' => 150, 'per_seconds' => 60],
                'trendyol.orders_picking' => ['max' => 250, 'per_seconds' => 60],
                'trendyol.orders_tracking' => ['max' => 250, 'per_seconds' => 60],
            ],
        ],
        'amazon' => [
            'default' => ['max' => 2, 'per_seconds' => 1],
            'operations' => [
                'amazon.lwa_token' => ['max' => 10, 'per_seconds' => 10],
                // searchOrders: 0.0056 requests/second, burst 20.
                'amazon.orders_search' => ['max' => 18, 'per_seconds' => 3600],
                'amazon.probe_orders' => ['max' => 18, 'per_seconds' => 3600, 'bucket' => 'amazon.orders_search'],
                'amazon.order_get' => ['max' => 25, 'per_seconds' => 60],
                'amazon.order_recipient' => ['max' => 25, 'per_seconds' => 60],
                'amazon.order_confirm_shipment' => ['max' => 2, 'per_seconds' => 1],
                'amazon.listings_search' => ['max' => 5, 'per_seconds' => 1],
                'amazon.probe_listings' => ['max' => 5, 'per_seconds' => 1, 'bucket' => 'amazon.listings_search'],
                'amazon.listing_get' => ['max' => 5, 'per_seconds' => 1],
                'amazon.listings_validate' => ['max' => 5, 'per_seconds' => 1],
                'amazon.listings_patch_price' => ['max' => 5, 'per_seconds' => 1],
                'amazon.listings_patch_quantity' => ['max' => 5, 'per_seconds' => 1],
                'amazon.catalog_search' => ['max' => 2, 'per_seconds' => 1],
                'amazon.product_type_definition' => ['max' => 5, 'per_seconds' => 1],
                'amazon.fba_inventory' => ['max' => 2, 'per_seconds' => 1],
                'amazon.probe_fba_inventory' => ['max' => 2, 'per_seconds' => 1, 'bucket' => 'amazon.fba_inventory'],
                // listTransactions: 0.5 requests/second, burst 10.
                'amazon.finances_transactions' => ['max' => 10, 'per_seconds' => 20],
                'amazon.probe_finances' => ['max' => 10, 'per_seconds' => 20, 'bucket' => 'amazon.finances_transactions'],
                'amazon.finances_groups' => ['max' => 10, 'per_seconds' => 20],
                // getMarketplaceParticipations: 0.016 requests/second, burst 15.
                'amazon.probe_participations' => ['max' => 10, 'per_seconds' => 900],
                // createReport: 0.0167 requests/second, burst 15 -- one a minute, so a retry loop against it would spend the
                // whole account's budget. Ours is deliberately under it.
                'amazon.reports_create' => ['max' => 10, 'per_seconds' => 900],
                // getReport: 2 requests/second, burst 15. The one report call that may be made freely, which is why polling is
                // how a run waits and every other step is recorded rather than repeated.
                'amazon.reports_get' => ['max' => 2, 'per_seconds' => 1],
                // getReports: 0.0222 requests/second, burst 10.
                'amazon.reports_list' => ['max' => 10, 'per_seconds' => 900],
                // getReportDocument: 0.0167 requests/second, burst 15.
                'amazon.reports_document' => ['max' => 10, 'per_seconds' => 900],
                // The presigned download is S3, not SP-API, and Amazon publishes no limit for it. Paced anyway: a document is
                // fetched once per report and the URL is good for five minutes.
                'amazon.report_download' => ['max' => 10, 'per_seconds' => 60],
                'amazon.feeds_create' => ['max' => 2, 'per_seconds' => 300],
                'amazon.feeds_create_document' => ['max' => 10, 'per_seconds' => 20],
            ],
        ],
    ],

    'amazon' => [
        'lwa_url' => 'https://api.amazon.com/auth/o2/token',
        'endpoints' => [
            'production' => ['na' => 'https://sellingpartnerapi-na.amazon.com', 'eu' => 'https://sellingpartnerapi-eu.amazon.com', 'fe' => 'https://sellingpartnerapi-fe.amazon.com'],
            'sandbox' => ['na' => 'https://sandbox.sellingpartnerapi-na.amazon.com', 'eu' => 'https://sandbox.sellingpartnerapi-eu.amazon.com', 'fe' => 'https://sandbox.sellingpartnerapi-fe.amazon.com'],
        ],
        // The User-Agent Amazon requires on every call: application, version, language.
        'user_agent' => ['application' => 'PharmacyChannelHub', 'version' => '1.0'],
        // The largest report document this version will read. The HTTP client buffers a whole body, so this bounds what gets
        // PARSED and names the refusal; it does not bound what gets received. A catalogue that outgrows it needs the streaming
        // download, which is a declared gap rather than something half-done (docs/sales-channels/api-reference.md).
        'report_max_bytes' => 33554432,
        // A fresh access token is fetched when the one held has less than this many seconds left (tokens last 3600).
        'token_refresh_margin_seconds' => 300,
        // Everything searchOrders is asked to include. BUYER is requested only when the account asks for buyer data; names, addresses and phones are never stored on the channel order record.
        'orders_included_data' => ['FULFILLMENT', 'PROCEEDS', 'PACKAGES', 'CANCELLATION', 'TAX', 'RECIPIENT', 'PAYMENT'],
        // Amazon's financial breakdown types are an open set. These patterns decide what a type means for our books; anything that matches none is kept as an event for a person, never as a cost or as revenue.
        'finance_map' => [
            'fee' => [
                '/commission|referral/i' => 'marketplace_commission',
                '/^fba[ _-]?fees?$/i' => 'fba_fulfillment',
                '/fba.*(fulfil|pick|pack|weight|handling|delivery|order|unit)/i' => 'fba_fulfillment',
                '/fba.*(storage)|storage/i' => 'fba_storage',
                '/fba.*return|return.*fee|restocking/i' => 'fba_return',
                '/closing|per.?item|subscription|service.?fee|fee/i' => 'marketplace_service_fee',
            ],
            'sale' => ['/^(sales?|product charges?|principal|tax|shipping|gift.?wrap|discount|promotion|goodwill|regulatory)/i'],
            'payout' => ['/transfer|disburse|payout|settlement/i'],
            'refund' => ['/refund/i'],
            'adjustment' => ['/reimburs|adjust|claim|chargeback|liquidation|retro/i'],
        ],
        // What each amount inside a REFUND gives back to the buyer, by its breakdown type. The first part that matches wins (so "Shipping tax" is tax); an amount that matches none is kept
        // for a person and never counted. The goods, the shipping and a promotion that was taken off reduce the revenue of the order line (without tax); the tax is recorded beside it.
        'refund_parts' => [
            'tax' => ['/tax|vat/i'],
            'promotion' => ['/promotion|discount|coupon/i'],
            'shipping' => ['/shipping|postage|delivery/i'],
            'principal' => ['/^(product charges?|principal|item price|sales?)$/i'],
        ],
    ],

    'trendyol' => [
        'bases' => ['production' => 'https://apigw.trendyol.com', 'sandbox' => 'https://stageapigw.trendyol.com'],
        // The documentation says `orderDate` is "GMT+3" while every other timestamp is true UTC; the sample arithmetic shows it shifted by three hours. Verify on a stage order, then adjust if needed.
        'order_date_offset_milliseconds' => 10800000,
        // Largest date range Trendyol accepts per call (orders: 14 days, finance: 15 days).
        'orders_window_days' => 14,
        'finance_window_days' => 15,
        // Each financial transaction type is read with its own request in the international (Gulf) API.
        'finance_settlement_types' => ['Sale', 'Return'],
        'finance_other_types' => ['DeductionInvoices', 'CreditNote', 'CommissionInvoice', 'PaymentOrder'],
        // A DeductionInvoices row only has a free-text description: these patterns decide what the deduction is. A description that matches none stays unclassified for a person.
        'finance_map' => [
            'deduction' => [
                '/cargo|shipping|carrier|freight|kargo|شحن/iu' => 'marketplace_shipping',
                '/platform|service[ _-]?fee|hizmet|خدمة/iu' => 'marketplace_service_fee',
                '/\bads?\b|advert|reklam|إعلان/iu' => 'marketplace_service_fee',
            ],
        ],
        // The largest body a webhook delivery may have before it is refused (a package with many lines is a few kilobytes).
        'webhook_max_bytes' => 262144,
    ],

    /*
    |--------------------------------------------------------------------------
    | noon (partners.noon.com)
    |--------------------------------------------------------------------------
    | Paths and hosts follow the official partner documentation as read on 2026-11-08. The request and response SHAPES of every call but the sign-in are not published in a
    | readable form, so this version signs in and asks who it is, and nothing else: see NoonOperations.
    */
    'noon' => [
        'bases' => ['production' => 'https://noon-api-gateway.noon.partners', 'sandbox' => 'https://noon-sandbox-api-gateway.noon.partners'],
        // The sign-in ALWAYS goes to production, even for a sandbox account: the documentation says to keep the login call on the production host and point only the other
        // calls at the sandbox. A sandbox that answered a sign-in would hand out a session that production does not know.
        'login_always_production' => true,
        // noon requires a User-Agent on every request.
        'user_agent' => ['application' => 'PharmacyChannelHub', 'version' => '1.0'],
        // A session lasts 30 days; it is renewed once less than this is left of it, so a run never starts with a session about to lapse.
        'session_refresh_margin_hours' => 24,
        // The JWT noon is sent is rejected if it is more than five minutes away from its clock, so it is made per sign-in and never cached.
        'jwt_lifetime_seconds' => 120,
    ],

    'stock' => [
        // A quantity the source has not confirmed for this long is stale and is not published.
        'stale_after_minutes' => 180,

        // One physical stock offered by several listings (of one account or of several) is divided between them, so that what all of them MAY be shown adds up to no more than what
        // is left (see StockAllocator and operations.md for the conditions the channels' actual quantities need). Every value can be set in the environment; nothing here switches
        // publishing on.
        'allocation' => [
            // For rolling back only: off, every listing is offered the pool's whole net instead of its part of a division (the net itself, and the refusals, stay what PoolNet says).
            // The division can only offer LESS than that.
            'enabled' => filter_var(env('SALES_CHANNELS_STOCK_ALLOCATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            // What the storefront keeps out of the channels' reach when a pool names no reserve of its own: `percent` of the pool or a number of `fixed` units. 0 keeps nothing, which is
            // what every pool was offered before; the suggestion, while the storefront sells the product from the same stock, is 50 percent (an owner decision, see operations.md).
            'storefront_reserve_mode' => (string) env('SALES_CHANNELS_STOCK_STOREFRONT_RESERVE_MODE', 'percent'),
            'storefront_reserve_value' => (int) env('SALES_CHANNELS_STOCK_STOREFRONT_RESERVE_VALUE', 0),
            // A quantity is only RAISED above what the channel shows while every account that offers the pool has finished a COMPLETE sweep of its orders this recently (an order not
            // imported yet is stock sold twice). An account whose orders are not imported has no such sweep: a quantity of a pool it offers is never raised.
            'order_fresh_minutes' => (int) env('SALES_CHANNELS_STOCK_ORDER_FRESH_MINUTES', 15),
            // Units a channel is expected to sell before we hear of them can be held back from it: its sales of the last `lag_rate_minutes`, scaled to a window of at least
            // `lag_window_minutes` (and longer when the last order import is older, or the publish interval is). 0 holds nothing back, which is what every listing was offered
            // before the margin existed; 20 is the suggested value once the owner has decided to trade some quantity for safety. `publish_interval_minutes` is the schedule's.
            'lag_window_minutes' => (int) env('SALES_CHANNELS_STOCK_LAG_WINDOW_MINUTES', 0),
            'lag_rate_minutes' => (int) env('SALES_CHANNELS_STOCK_LAG_RATE_MINUTES', 120),
            'publish_interval_minutes' => (int) env('SALES_CHANNELS_STOCK_PUBLISH_INTERVAL_MINUTES', 15),
            // After a pool was promised more than it holds, a quantity is not raised again until this many computations have come back clean.
            'deficit_block_cycles' => (int) env('SALES_CHANNELS_STOCK_DEFICIT_BLOCK_CYCLES', 2),
            // The quick run after an order import sends only what LOWERS a quantity, and leaves a small lowering of a large quantity to the regular run (not to burn a channel's
            // rate limits): a drop is sent at once when it is at least this percent of the quantity shown, or when the new quantity is at most `reduction_always_below_units`.
            'reduction_dead_band_percent' => (int) env('SALES_CHANNELS_STOCK_REDUCTION_DEAD_BAND_PERCENT', 10),
            'reduction_always_below_units' => (int) env('SALES_CHANNELS_STOCK_REDUCTION_ALWAYS_BELOW_UNITS', 10),
            // A reservation of an order that is still open is listed on the hub as held for days after this many.
            'aged_hold_days' => (int) env('SALES_CHANNELS_STOCK_AGED_HOLD_DAYS', 7),
            // Old released reservations are deleted by `channels:prune` after this many days.
            'released_reservation_days' => (int) env('SALES_CHANNELS_STOCK_RELEASED_RESERVATION_DAYS', 30),
        ],

        // The outbox: every change that could change what a channel should be shown is recorded in the same transaction as the change, and worked at once instead of at the
        // next scheduled run. It is not a switch that lets anything out: a drain hands its pools to the division and the publisher, so the host's switches, the account's
        // capability and its recorded approval all still decide whether a single quantity leaves this host.
        'outbox' => [
            // For rolling back only. Off, the module behaves exactly as it did before this table existed: the marks and the scheduled runs, with the gap the table closes
            // (a change written while the process died, and a host with the division switched off, both leave a quantity on offer until the next full sweep).
            'enabled' => filter_var(env('SALES_CHANNELS_STOCK_OUTBOX_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            // Whether a queued job is asked for as soon as something is recorded. Off, nothing is lost: `channels:stock-events` works the table on the schedule instead, so a
            // host with no queue worker turns this off and loses only the seconds.
            'nudge_enabled' => filter_var(env('SALES_CHANNELS_STOCK_OUTBOX_NUDGE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            // At most one nudge per this many seconds: a drain takes everything that is due, so an order import that changed four hundred orders does not need four hundred jobs.
            'nudge_seconds' => (int) env('SALES_CHANNELS_STOCK_OUTBOX_NUDGE_SECONDS', 5),
            // How many events one pass works.
            'batch' => (int) env('SALES_CHANNELS_STOCK_OUTBOX_BATCH', 200),
            // A batch that failed is tried again after `retry_seconds` times the number of tries so far; after `attempts` tries the events are kept as dead for a person to
            // look at (`channels:stock-events --status`, and the hub), never dropped and never called done.
            'attempts' => (int) env('SALES_CHANNELS_STOCK_OUTBOX_ATTEMPTS', 5),
            'retry_seconds' => (int) env('SALES_CHANNELS_STOCK_OUTBOX_RETRY_SECONDS', 60),
            // A run that died leaves its batch claimed; after this many minutes another run takes it back. Longer than a drain can reasonably take.
            'claim_timeout_minutes' => (int) env('SALES_CHANNELS_STOCK_OUTBOX_CLAIM_TIMEOUT_MINUTES', 10),
            // Whether the storefront's own number is written again when a channel's units are held or given back, from the figure the provider already gave (no provider is
            // asked, and the number is recomputed rather than decremented, so it cannot be taken off twice).
            //  - `_inline`: in the same REQUEST as the hold, straight after its transaction commits (not inside it: there the reads that count the promises would see that
            //    transaction's own snapshot and miss every hold another process committed meanwhile). So our own shop stops offering the units before the ingest returns,
            //    with no queue and no worker. Off, the row is left for the worker instead.
            //  - the other: in the worker, which re-verifies what the request wrote — the request's write is not atomic with the hold, so a process that died in between
            //    would leave the number uncorrected — and which is the only writer for the changes no transaction can cover (a provider figure read by a sweep, a setting
            //    saved on a screen) and for a host with the inline write off. Off as well, the storefront waits for the provider's next figure, as it did before any of this.
            'rewrite_storefront_stock_inline' => filter_var(env('SALES_CHANNELS_STOCK_OUTBOX_REWRITE_STOREFRONT_INLINE', true), FILTER_VALIDATE_BOOLEAN),
            'rewrite_storefront_stock' => filter_var(env('SALES_CHANNELS_STOCK_OUTBOX_REWRITE_STOREFRONT', true), FILTER_VALIDATE_BOOLEAN),
            // Worked events are deleted by `channels:prune` after this many days; a dead one is kept until a person has dealt with it.
            'done_event_days' => (int) env('SALES_CHANNELS_STOCK_OUTBOX_DONE_EVENT_DAYS', 7),
            // The hub says the workers are behind once the oldest waiting change is this old. A few seconds is the arrangement working; three minutes is somebody's to look at.
            'behind_seconds' => (int) env('SALES_CHANNELS_STOCK_OUTBOX_BEHIND_SECONDS', 180),
        ],
    ],

    /*
    | Putting a product of the store ON a channel (creating or replacing a listing there). A draft can be prepared, checked against what the channel itself says it requires and
    | previewed with this OFF — nothing leaves the host. Switching it on is not enough either: the account needs the `product_sync` capability and a recorded `product_writes`
    | approval, and the channel's connector has to be able to create a listing at all.
    */
    'listing' => [
        'publish_enabled' => filter_var(env('SALES_CHANNELS_LISTING_PUBLISH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        /*
        | The channels' own attribute names that would give a listing a price, an offer or a quantity. The publisher WITHHOLDS every one of them when it creates or changes a
        | listing: a new listing must not be sellable because a draft happened to carry a price, and a quantity may only reach a channel through stock publishing, which is a
        | capability of its own with an approval of its own. Withheld fields are recorded on the draft.
        */
        'offer_attributes' => [
            'amazon' => ['purchasable_offer', 'list_price', 'fulfillment_availability', 'offer', 'price', 'quantity', 'main_offer_image_locator'],
            'trendyol' => ['listPrice', 'salePrice', 'quantity', 'stockCode'],
            // noon keeps price and quantity in services of their own, so its content call has no field for either. These names are listed because `attributes{}` is a
            // free-form map of a category's attributes: a noon category that happened to carry one of them must not deliver it through the one call no price switch guards.
            'noon' => ['price', 'sale_price', 'msrp', 'stock', 'quantity', 'qty', 'is_active', 'processing_time'],
        ],
        /*
        | What the shop wants of its OWN product before it is offered anywhere (ProductListingReadiness): nothing here fetches an image or judges its pixels — it checks that the
        | product has what a channel will ask for. A name, a description, a barcode or code, a category, a thumbnail and this many images are always required; a brand only when
        | the owner says so.
        */
        'product_data' => [
            'images_at_least' => (int) env('SALES_CHANNELS_LISTING_IMAGES_AT_LEAST', 1),
            // How many photographs a channel is sent. Trendyol documents "Bir barkod için maksimum 8 adet görsel" -- a maximum
            // of eight per barcode -- and sending a ninth would have the whole batch refused for a reason a person then has to
            // decipher from a batch result.
            'images_at_most' => (int) env('SALES_CHANNELS_LISTING_IMAGES_AT_MOST', 8),
            'brand_required' => filter_var(env('SALES_CHANNELS_LISTING_BRAND_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),
        ],
        // How long the channel's own answer about a product type's required fields is trusted before it is asked again.
        'requirements_fresh_hours' => (int) env('SALES_CHANNELS_LISTING_REQUIREMENTS_FRESH_HOURS', 24),
        // How long the channel CATALOGUE's answer about a barcode is trusted. Longer than the requirements, because an ASIN for a barcode does not move: what moves is the
        // required fields of its product type. An `unknown` answer is never trusted for any length of time -- it is asked again at once, since it is the answer that stops a
        // person from listing anything and freezing it for a week would be the worst of both.
        'catalog_fresh_hours' => (int) env('SALES_CHANNELS_LISTING_CATALOG_FRESH_HOURS', 168),
        /*
        | A pharmacy's catalogue. The code does not and cannot decide which of the shop's categories need a licence, so these are the OWNER's rules, and the gate FAILS CLOSED
        | when it is on: a product with no category recorded at all is refused, because "we could not tell" is not "it is fine".
        |
        |   mode `off` (how it ships): the gate stops nothing. A deliberate choice, not an accident of empty lists.
        |   mode `strict`: an unclassifiable product is refused, and a product these rules name may be listed only with a RECORDED clearance on the draft (a reference and the
        |                  person who recorded it, who must hold the `sales_channels` module). An admin id on a request is not a clearance.
        |
        | `channels` carries the same four keys per channel, because what Amazon closes and what Trendyol closes are different lists; a channel's mode overrides the global one
        | and its lists are added to the global ones.
        */
        'regulated' => [
            'mode' => in_array(env('SALES_CHANNELS_LISTING_REGULATED_MODE', 'off'), ['off', 'strict'], true) ? env('SALES_CHANNELS_LISTING_REGULATED_MODE', 'off') : 'off',
            'category_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('SALES_CHANNELS_LISTING_REGULATED_CATEGORY_IDS', ''))))),
            'name_patterns' => array_values(array_filter(array_map('trim', explode(',', (string) env('SALES_CHANNELS_LISTING_REGULATED_NAME_PATTERNS', ''))))),
            'brand_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('SALES_CHANNELS_LISTING_REGULATED_BRAND_IDS', ''))))),
            'channels' => [
                'amazon' => ['mode' => null, 'category_ids' => [], 'name_patterns' => [], 'brand_ids' => []],
                'trendyol' => ['mode' => null, 'category_ids' => [], 'name_patterns' => [], 'brand_ids' => []],
            ],
        ],
    ],

    'inventory' => [
        // Where the own warehouse's quantities come from. `manual`: balances a person typed (nothing writes them today, so the own warehouse offers nothing). `platform`: read
        // through from the storefront's own stock (`products.current_stock` and the variants' quantities), never copied and never written. Choosing `platform` changes nothing by
        // itself: the own warehouse's row is switched by the audited command `channels:stock-use-platform-for-own-warehouse`, and stock the store owns is not offered to a
        // channel until the owner decides how channel units leave it.
        'own_warehouse_source' => in_array(env('SALES_CHANNELS_OWN_WAREHOUSE_SOURCE', 'manual'), ['manual', 'platform'], true) ? env('SALES_CHANNELS_OWN_WAREHOUSE_SOURCE', 'manual') : 'manual',
        // Whether a unit a shipped channel order took off the own warehouse's shelf stops counting once the storefront's stock has been lowered for it (the reservation's
        // `applied_to_platform_at`). Nothing in this version lowers it: with this off, which is the only supported state, such a unit counts until its order is released.
        'apply_channel_orders_to_platform_stock' => filter_var(env('SALES_CHANNELS_APPLY_CHANNEL_ORDERS_TO_PLATFORM_STOCK', false), FILTER_VALIDATE_BOOLEAN),
        // The storefront and the channels sell ONE provider figure between them (Torod Point's warehouse and M5AZN's supplier stock are both
        // storefront-sellable and publishable). With this ON, the units open channel orders hold at a provider's location come off the stock the
        // provider sync writes to the storefront, exactly as store orders the provider has not taken on yet already do, so the same unit is not
        // promised twice. It is not an integration switch and sends nothing anywhere: with no channel orders imported it subtracts nothing. OFF
        // restores the earlier behaviour, in which the storefront's figure ignores what the channels have sold.
        'hold_channel_units_from_storefront' => filter_var(env('SALES_CHANNELS_HOLD_CHANNEL_UNITS_FROM_STOREFRONT', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // Creating a provider shipment for a CHANNEL order: the application makes a minimal internal order for it and lets the existing Torod Point / M5AZN fulfilment do the rest (all of that
    // module's own switches, dry run and approvals still apply). Off: a routing rule that asks for it is recorded as wanted and the order waits for a person.
    'managed_dispatch_enabled' => filter_var(env('SALES_CHANNELS_MANAGED_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Reading the buyer's name, phone and address from a channel so that a provider can ship the order. Independent of the writes switch and of managed dispatch, off by default, and
    // it also needs a master admin's recorded approval on the account (`buyer_data_transfer`).
    'buyer_data_transfer_enabled' => filter_var(env('SALES_CHANNELS_BUYER_DATA_TRANSFER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Finding the shipment a provider created straight from a channel order (Trendyol connected to Torod Point), so that it is linked and never sent a second time. OFF until the owner has seen a real
    | sample of such a shipment: nothing in the repository says which Torod field carries the channel's order number. Only an exact match on that one field links; anything else waits and is handed to a person.
    */
    'shipment_discovery' => [
        'enabled' => filter_var(env('SALES_CHANNELS_SHIPMENT_DISCOVERY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Hours an order may go without a shipment before it is handed to a person (measured from the channel's purchase time).
        'window_hours' => 36,
        'torod' => [
            // What Torod holds in the invoice number of such an order is the account's `torod_reference_field` setting (order number, package id or tracking number).
            // The order list has no search: this many pages of 50 are read, newest first as far as Torod lists them; an order further back is not seen.
            'max_pages' => 3,
            'cache_seconds' => 120,
        ],
    ],

    'dispatch' => [
        // An attempt that claimed an order and never finished (a crash) is given up after this long, and the order may be claimed again.
        'claim_minutes' => 10,
        // Waiting times (minutes) before the next attempt when the buyer's details could not be read or were incomplete; the last one repeats until max_attempts.
        'retry_minutes' => [5, 15, 60],
        'max_attempts' => 5,
        // How the internal order is marked. `default_type` is the only value every storefront and admin page understands; see operations.md before changing it.
        'order_type' => 'default_type',
        'payment_method' => 'marketplace_prepaid',
        // Buyer details are removed from the internal order this long after the provider holds the shipment (or the channel order was cancelled).
        'retain_after_accept_hours' => 24,
        // An order nothing has been sent for (a rehearsal in dry run, or a send that keeps failing) keeps them no longer than this; it is then closed and the channel order waits to be dispatched again.
        'rehearsal_buyer_data_hours' => 72,
        // The most any order keeps them, whatever state it is in (a send whose outcome was never settled included).
        'max_buyer_data_days' => 30,
    ],

    /*
    | Telling a channel that goods shipped. One claimed row per shipment reference (`channel_shipment_sends`) decides which process owns the attempt, because asking the channel
    | first does not stop two processes both being told "no such shipment" and both sending. A send that may have reached the channel is never repeated automatically.
    */
    'shipment_sends' => [
        // An attempt that claimed a shipment reference and never finished (a crash) is given up after this long, and the reference may be claimed again. Taking it over does
        // NOT grant permission to send: the row still says whether a send may already have left.
        'claim_minutes' => 10,
    ],

    // The class that speaks to each channel. A new channel is a connector class added here.
    'connectors' => [
        'amazon' => \App\Services\SalesChannels\Amazon\AmazonConnector::class,
        'trendyol' => \App\Services\SalesChannels\Trendyol\TrendyolConnector::class,
        'noon' => \App\Services\SalesChannels\Noon\NoonConnector::class,
    ],

    'sync' => [
        // One run reads at most this many pages and then pauses with its place saved, so a large backlog never holds the lease for hours.
        'max_pages_per_run' => 25,
        // A read whose page tokens do not outlive the run (the stock a channel holds for us) is read in one go, up to this many pages.
        'max_pages_one_go' => 400,
        // Fees and corrections reach a statement days after the sale: each finance run re-reads this far back.
        'finance_overlap_days' => 3,
        'finance_backfill_days' => 30,
        // A statement is read up to a few minutes ago (Amazon refuses a range that ends later than two minutes ago) and a window is never longer than this many days
        // (Amazon answers nothing for a span over 180): a longer gap is read window by window over several runs.
        'finance_end_lag_minutes' => 3,
        'finance_max_window_days' => 180,
        // A run re-reads this far before its watermark so that an order changed while the previous run was reading is not missed.
        'overlap_minutes' => 10,
        // A run that crashed loses its lease after this long.
        'lease_minutes' => 20,
        // After repeated failures a resource backs off for this many minutes per consecutive failure, up to the cap.
        'backoff_minutes_per_failure' => 5,
        'backoff_cap_minutes' => 120,
        // How often each resource is due, in minutes.
        'intervals' => [
            'orders' => 5,
            'returns' => 30,
            'shipments' => 30,
            'inventory' => 30,
            'listings' => 360,
            'finances' => 720,
        ],
        'order_backfill_days' => 30,
    ],
];
