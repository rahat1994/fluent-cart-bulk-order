<?php

namespace FluentCartBulkOrder\Analytics;

use FluentCartBulkOrder\AccessPolicy;
use FluentCartBulkOrder\Pricing\FeedResolver;
use FluentCartBulkOrder\Pricing\Tiers;

defined('ABSPATH') || exit;

/**
 * The recording half of owner analytics: what a new order is attributed to, and
 * which Bulk Pricing Tier priced each of its lines.
 *
 * ---------------------------------------------------------------------------
 * AN ANALYTICS SCREEN CAN ONLY REPORT ON DATA SOMEBODY RECORDED
 * ---------------------------------------------------------------------------
 *
 * Nothing in this plugin used to write down which tier priced a line.
 * LinePricing resolved one, applied it, and dropped it — the discount survived
 * in the charged price and the reason for it did not. So "which quantity tiers
 * actually get hit" was not a hard query on existing data; it was a query
 * against data that did not exist.
 *
 * This class is what makes it exist, and it can only do so from the moment it
 * ships. There is no back-fill and there cannot be one: a past order's
 * `unit_price` tells you what was charged, not which of several overlapping
 * tiers produced it, and the feed it came from may since have been edited or
 * deleted. Guessing would be worse than nothing, because it would look like
 * history.
 *
 * The screen therefore says so, in words, on the panels it applies to.
 * @see \FluentCartBulkOrder\Analytics\AnalyticsScreen
 *
 * ---------------------------------------------------------------------------
 * WHY THE TIER IS RE-RESOLVED HERE RATHER THAN CAPTURED IN LinePricing
 * ---------------------------------------------------------------------------
 *
 * The obvious design is for LinePricing::applyBulkPricing() to push each match
 * into a request-scoped list that this class reads. It does not work, for a
 * plain reason: the cart is priced in the ADD-TO-CART request and the order is
 * created in the CHECKOUT request. They are different HTTP requests, often
 * minutes apart, so nothing kept in memory during the first is available in the
 * second.
 *
 * So the tier is resolved again, here, through the SAME functions LinePricing
 * uses — FeedResolver::resolveTiers() and Tiers::match() — over the same feed
 * data and the quantity the order actually carries. That is deterministic: same
 * inputs, same pure functions, same answer.
 *
 * And it is CHECKED rather than assumed. A resolved tier is only recorded when
 * the price it produces equals the `unit_price` the order was actually written
 * with. If an owner edited the feed between the shopper filling their cart and
 * paying, or another extension repriced the line, the two disagree — and this
 * records no tier for that line rather than a tier the shopper was never
 * charged. The order still counts as a bulk order if it was attributed some
 * other way; it just does not vote in tier utilization on a false premise.
 *
 * That check is why the pre-discount price is read from ProductVariation
 * directly: that query does NOT run `fluent_cart/cart/item_price`, so it is the
 * last untouched copy of the list price. The same reasoning, and the same
 * source, as LinePricing::lineSaving().
 *
 * @see \FluentCartBulkOrder\Cart\LinePricing The pricing this describes.
 * @see \FluentCartBulkOrder\Analytics\AttributionStore Where the rows go.
 */
class OrderAttribution
{
    /**
     * Record one order created through FluentCart's checkout.
     *
     * Bound to `fluent_cart/checkout/prepare_other_data`, the same hook the PO
     * number uses and for the same reason: the order exists, its line items
     * exist, and the raw submission — carrying the surface marker — is still in
     * hand (fluent-cart 1.5.5, api/Checkout/CheckoutApi.php:276).
     *
     * @param array $data ['order' => Order, 'request_data' => array, ...]
     * @return void
     */
    public static function recordCheckout($data)
    {
        $order = is_array($data) && isset($data['order']) ? $data['order'] : null;

        if (!is_object($order) || empty($order->id)) {
            return;
        }

        $request = is_array($data) && isset($data['request_data']) && is_array($data['request_data'])
            ? $data['request_data']
            : [];

        // NOT wp_unslash()ed. This array reached us through FluentCart's own
        // Request, whose constructor has already run every value through
        // stripslashes_deep() (fluent-cart 1.5.5,
        // vendor/wpfluent/framework/src/WPFluent/Http/Request/Request.php:119).
        // Unslashing again would corrupt values nobody added slashes to — the
        // same trap PoField documents at length.
        $source = Surface::sanitize(isset($request[Surface::PARAM]) ? $request[Surface::PARAM] : '');

        self::record($order, $source);
    }

    /**
     * Record an order the owner created by converting an accepted quote.
     *
     * Bound to `fcbo/quotes/decided`. A converted quote never passes through
     * FluentCart's checkout — QuoteOrder goes through the host's admin order
     * path, which does not fire `prepare_other_data` at all — so without this it
     * would be invisible to the revenue panel despite being the most
     * unambiguously wholesale order a store can take.
     *
     * Its lines carry prices the OWNER typed, not tier prices, so the check in
     * lineRows() will match no tier for them. That is correct: a quote is
     * bulk-order revenue and it is not tier utilization.
     *
     * @param int   $quoteId Unused; the signature is the hook's.
     * @param mixed $record  The stored quote, after the decision.
     * @param mixed $status  The status it moved to.
     * @return void
     */
    public static function recordQuoteOrder($quoteId, $record, $status)
    {
        if (!is_array($record) || empty($record['order_id'])) {
            return;
        }

        if (!class_exists(\FluentCart\App\Models\Order::class)) {
            return;
        }

        $order = \FluentCart\App\Models\Order::query()->find((int) $record['order_id']);

        if (!$order) {
            return;
        }

        self::record($order, Surface::QUOTE);
    }

    /**
     * Work out one order's attribution and store it.
     *
     * Writes NOTHING when the order is neither marked with a surface nor priced
     * by a tier. That silence is the definition of "normal checkout": the
     * revenue panel measures normal checkout as the store's total minus what is
     * in this table, so an unattributed order needs no row to be counted
     * correctly — and a store whose shoppers never touch these surfaces pays
     * nothing at all for this feature beyond one query per checkout.
     *
     * @param object $order  A FluentCart Order model.
     * @param string $source One of Surface::keys(), or ''.
     * @return void
     */
    private static function record($order, $source)
    {
        $rows = self::lineRows($order, $source);

        if (!$rows) {
            return;
        }

        AttributionStore::record((int) $order->id, $rows);
    }

    /**
     * One row per order line, or an empty array when the order is not ours.
     *
     * @param object $order
     * @param string $source
     * @return array<int, array<string, mixed>>
     */
    private static function lineRows($order, $source)
    {
        $items = self::items($order);

        if (!$items) {
            return [];
        }

        $tiers = self::tiersForItems($items, $order);

        // Nothing to say about this order: no surface, and no line this
        // plugin priced.
        if ($source === '' && !$tiers) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            $variantId = (int) (isset($item->object_id) ? $item->object_id : 0);
            $qty = (int) (isset($item->quantity) ? $item->quantity : 0);
            $unitPrice = (int) (isset($item->unit_price) ? $item->unit_price : 0);

            // Keyed by the LINE and not by the variant. An order can carry the
            // same variant on two lines at different quantities — a one-time
            // line beside a subscription line, most plainly — and those two
            // lines can legitimately land on different tiers. Keying by variant
            // would give both of them whichever tier was resolved last.
            $lineId = (int) (isset($item->id) ? $item->id : 0);
            $hit = isset($tiers[$lineId]) ? $tiers[$lineId] : null;

            $row = array_merge(
                [
                    'source'          => $source,
                    'product_id'      => (int) (isset($item->post_id) ? $item->post_id : 0),
                    'variant_id'      => $variantId,
                    'tier_key'        => '',
                    'tier_scope'      => '',
                    'tier_product_id' => 0,
                    'tier_role'       => '',
                    'tier_min_qty'    => 0,
                    'tier_max_qty'    => 0,
                    'tier_type'       => '',
                    'tier_value'      => 0,
                    'quantity'        => $qty,
                    'list_price'      => $unitPrice,
                    'unit_price'      => $unitPrice,
                    'line_total'      => (int) (isset($item->line_total) ? $item->line_total : 0),
                    'saving'          => 0,
                ],
                $hit ? $hit['columns'] : []
            );

            if ($hit) {
                $row['list_price'] = (int) $hit['list_price'];
                $row['saving'] = max(0, ((int) $hit['list_price'] - $unitPrice) * $qty);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The order's line items, read once.
     *
     * Queried rather than taken off the relation, because at
     * `prepare_other_data` the order model was built by the checkout processor
     * and its `order_items` relation has not necessarily been loaded — reading
     * an unloaded relation would either lazy-load it anyway or come back empty
     * depending on the model's state, and "empty" here means silently recording
     * nothing.
     *
     * @param object $order
     * @return array<int, object>
     */
    private static function items($order)
    {
        if (!class_exists(\FluentCart\App\Models\OrderItem::class)) {
            return [];
        }

        $items = \FluentCart\App\Models\OrderItem::query()
            ->where('order_id', (int) $order->id)
            ->get();

        $out = [];

        foreach ($items as $item) {
            // `object_id` is the VARIANT and `post_id` is the product — the
            // reverse of what the names suggest. @see
            // docs/solutions/architecture-patterns/fluentcart-order-model-user-orders-and-variants.md
            if (is_object($item) && !empty($item->object_id) && !empty($item->post_id)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Resolve, and confirm, the tier behind each line — keyed by variant id.
     *
     * Three batched queries at most, whatever the size of the order: one for
     * the store-wide feed, one for every product feed the order touches (both
     * inside FeedResolver::allBulkPricing()), and one for every variation's
     * list price. No per-line query, deliberately: a 200-line bulk order is
     * precisely the case this feature exists to measure, and it must not be the
     * case that makes checkout slow.
     *
     * @param array<int, object> $items
     * @param object             $order
     * @return array<int, array{columns: array, list_price: int}> Keyed by ORDER
     *         ITEM id, not by variant id. @see lineRows() for why.
     */
    private static function tiersForItems($items, $order)
    {
        // The same gate the cart applied. A shopper the bulk-pricing policy
        // excluded was never discounted, so there is no tier to record — and
        // asking here keeps this class from reporting a discount the shopper
        // did not receive. @see AccessPolicy Gate 2.
        //
        // The roles are the BUYER's, resolved from the order's own customer
        // rather than from the current user: recordQuoteOrder() runs while an
        // administrator is logged in, and an admin's roles are not the roles
        // that priced the buyer's cart.
        $userId = self::buyerUserId($order);
        $user = $userId > 0 ? get_user_by('id', $userId) : false;

        // An explicit empty WP_User rather than null for a guest. Passing null
        // means "the CURRENT user", and on the quote path the current user is
        // the administrator pressing Convert — whose roles are emphatically not
        // the roles that priced the buyer's cart.
        $user = $user ?: new \WP_User(0);

        if (!AccessPolicy::userQualifiesForBulkPricing($user, 'cart')) {
            return [];
        }

        $userRoles = isset($user->roles) ? (array) $user->roles : [];

        $productIds = [];
        $variantIds = [];

        foreach ($items as $item) {
            $productIds[(int) $item->post_id] = true;
            $variantIds[] = (int) $item->object_id;
        }

        $pricingData = FeedResolver::allBulkPricing(array_keys($productIds));

        if (empty($pricingData['global']) && empty($pricingData['product'])) {
            return [];
        }

        $listPrices = self::listPrices($variantIds);
        $out = [];

        foreach ($items as $item) {
            $productId = (int) $item->post_id;
            $variantId = (int) $item->object_id;
            $qty = (int) $item->quantity;

            if ($qty < 1 || !isset($listPrices[$variantId])) {
                continue;
            }

            $feed = FeedResolver::matchFeed($pricingData, $productId, $variantId);

            if (!$feed) {
                continue;
            }

            $tierList = AccessPolicy::selectRoleTierSet($feed, $userRoles);
            $tier = $tierList ? Tiers::match($tierList, $qty) : null;

            if (!$tier) {
                continue;
            }

            $listPrice = (int) $listPrices[$variantId];

            // The confirmation described in the class docblock. A tier whose
            // price does not match what the line was written with did not price
            // this line, whatever the feed says today.
            if (Tiers::applyToPrice($listPrice, $tier) !== (int) $item->unit_price) {
                continue;
            }

            $scope = self::scopeOf($pricingData, $feed);

            $out[(int) $item->id] = [
                'columns' => TierSignature::columns(
                    $tier,
                    $scope,
                    // A store-wide tier has no product of its own; a
                    // product-scoped one is only the same tier within the
                    // product whose feed defines it.
                    $scope === TierSignature::SCOPE_PRODUCT ? $productId : 0,
                    self::roleOf($feed, $userRoles)
                ),
                'list_price' => $listPrice,
            ];
        }

        return $out;
    }

    /**
     * Whether the governing feed is the store-wide one or a product's own.
     *
     * Decided by asking whether the matched feed is the global one, rather than
     * by re-running the precedence rule — FeedResolver::matchFeed() owns that
     * rule and this must not grow a second copy of it.
     *
     * @param array      $pricingData
     * @param array|null $feed
     * @return string
     */
    private static function scopeOf($pricingData, $feed)
    {
        $global = isset($pricingData['global']) ? $pricingData['global'] : null;

        return ($global && $feed === $global)
            ? TierSignature::SCOPE_GLOBAL
            : TierSignature::SCOPE_PRODUCT;
    }

    /**
     * Which role-scoped tier set inside a feed applied to this buyer.
     *
     * MIRRORS AccessPolicy::selectRoleTierSet() and must change with it. That
     * method returns the chosen LIST and not the role that chose it, and adding
     * a return value to a method four other call sites depend on, to serve a
     * label on a report, is not a trade worth making. '' means the feed's
     * default set, which is also what a feed with no role scoping always gives.
     *
     * @param array    $feed
     * @param string[] $userRoles
     * @return string
     */
    private static function roleOf($feed, $userRoles)
    {
        $roleTiers = isset($feed['role_tiers']) && is_array($feed['role_tiers']) ? $feed['role_tiers'] : [];

        if (empty($roleTiers) || empty($userRoles)) {
            return '';
        }

        foreach ($userRoles as $role) {
            if (!empty($roleTiers[$role])) {
                return (string) $role;
            }
        }

        return '';
    }

    /**
     * Every variant's UNDISCOUNTED unit price, in one query.
     *
     * A direct ProductVariation read is the right source precisely because it
     * does not pass through `fluent_cart/cart/item_price` — it is the last copy
     * of the price before this plugin touched it. Same source, same reason, as
     * LinePricing::lineSaving().
     *
     * @param int[] $variantIds
     * @return array<int, int> Variant id => cents.
     */
    private static function listPrices($variantIds)
    {
        $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds))));

        if (!$variantIds || !class_exists(\FluentCart\App\Models\ProductVariation::class)) {
            return [];
        }

        $rows = \FluentCart\App\Models\ProductVariation::query()
            ->whereIn('id', $variantIds)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            if (is_object($row) && !empty($row->id)) {
                $out[(int) $row->id] = (int) $row->item_price;
            }
        }

        return $out;
    }

    /**
     * The WordPress user the order belongs to, or 0.
     *
     * An Order carries no `user_id`: the link is Order -> `customer_id` ->
     * Customer -> `user_id`. @see \FluentCartBulkOrder\Export\OrderSnapshot,
     * which documents the same trap for the same reason.
     *
     * @param object $order
     * @return int
     */
    private static function buyerUserId($order)
    {
        if (empty($order->customer_id) || !class_exists(\FluentCart\App\Models\Customer::class)) {
            return 0;
        }

        $customer = \FluentCart\App\Models\Customer::query()->find((int) $order->customer_id);

        return $customer && !empty($customer->user_id) ? (int) $customer->user_id : 0;
    }
}
