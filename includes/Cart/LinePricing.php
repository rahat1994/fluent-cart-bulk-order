<?php

namespace FluentCartBulkOrder\Cart;

defined('ABSPATH') || exit;

/**
 * What the shopper is actually charged for a cart line.
 *
 * ---------------------------------------------------------------------------
 * THE HOOK CHOICE IS THE WHOLE POINT
 * ---------------------------------------------------------------------------
 *
 * applyBulkPricing() runs on `fluent_cart/cart/item_price`, NOT on `item_modify`.
 * item_modify fires with the quantity the REQUEST carried, so adding 5 more of
 * something already in the cart priced the line as a 5-unit order rather than as
 * the settled total. item_price fires after FluentCart has settled the line, so
 * the tier is chosen against the quantity the shopper is billed for.
 *
 * Do not "simplify" this back onto the earlier hook, and do not use both.
 * @see docs/solutions/integration-issues/cart-discount-priced-from-requested-not-settled-quantity.md
 *
 * This is also one half of a pair: the ordering surfaces quote a price before
 * purchase and this prices the line actually charged. They are separate
 * computations that must agree, which they only do while both apply the same
 * eligibility policy and both resolve against the same settled quantity.
 */
class LinePricing
{
    /**
     * FluentCart filter callback: price one cart line against its bulk tier.
     *
     * ---------------------------------------------------------------------------
     * WHY `item_price` AND NOT `item_modify`
     * ---------------------------------------------------------------------------
     *
     * This used to run on `fluent_cart/cart/item_modify`, which is handed the
     * quantity from the REQUEST. That is not always the quantity the line ends up
     * with. When FluentCart is told to ADD to a line rather than SET it (the
     * `by_input` flag is absent), Cart::addByVariation() folds the existing
     * quantity in afterwards:
     *
     *     if (!$byInput) { $quantity += $prevItem['quantity']; }   // Cart.php:406
     *
     * So the tier was chosen for the increment while the line was billed for the
     * total. Adding 5 and then 5 more left 10 units priced at the 5-unit tier, and
     * the cart drawer's + button — which posts an increment of 1 — re-priced a
     * 10-unit line as if it were a single unit, wiping a discount the shopper had
     * already earned.
     *
     * `fluent_cart/cart/item_price` fires inside
     * CartHelper::generateCartItemFromVariation() (CartHelper.php:34), AFTER that
     * fold, and its value becomes the line's `unit_price` directly. Every path that
     * builds a cart line goes through it: add, quantity update, the +/- buttons,
     * instant checkout, and the checkout order bump. Pricing there means the tier
     * always matches the quantity actually billed.
     *
     * The two hooks must never BOTH be used: `item_modify` mutates the variation
     * that is then passed to generateCartItemFromVariation(), so a discount applied
     * in both places would compound — 10% off twice is 19% off.
     *
     * Not covered: ProductItemService::getItem(), which builds subscription plans
     * for the payment gateways and calls `item_modify` without ever reaching
     * `item_price`. Subscriptions are single-quantity everywhere in FluentCart, and
     * a bulk tier is a quantity feature, so there is nothing for a tier to match
     * there unless a store sets min_qty to 1.
     *
     * @param int   $itemPrice Per-unit price in cents, as filtered so far. Used as
     *                         the base rather than $variation->item_price so an
     *                         earlier filter's adjustment is not discarded.
     * @param array $context   ['variation' => object, 'quantity' => int] — the
     *                         SETTLED quantity for this line.
     * @return int Per-unit price in cents.
     */
    public static function applyBulkPricing($itemPrice, $context)
    {
        $variation = isset($context['variation']) ? $context['variation'] : null;
        $qty       = (int) ($context['quantity'] ?? 0);

        if (!$variation || empty($variation->id) || $qty < 1) {
            return $itemPrice;
        }

        // Gate the discount by the stored role policy. Non-qualifying shoppers keep
        // the full price. Admins are NOT exempt on the cart path (KTD4) — which is
        // why fcbo_build_variant_payload() withholds tiers from them too, so the
        // bulk order form cannot quote a total this function will not honour.
        if (!fcbo_user_qualifies_for_bulk_pricing(null, 'cart')) {
            return $itemPrice;
        }

        $productId = (int) $variation->post_id;
        $variantId = (int) $variation->id;

        $pricingData = fcbo_get_all_bulk_pricing([$productId]);
        // Role selects which tier-set prices this line (composes with the gate above).
        $userRoles   = (array) wp_get_current_user()->roles;
        $tiers       = fcbo_resolve_tiers($pricingData, $productId, $variantId, $userRoles);

        if (empty($tiers)) {
            return $itemPrice;
        }

        $tier = fcbo_match_tier($tiers, $qty);

        return $tier ? fcbo_apply_tier_to_price((int) $itemPrice, $tier) : $itemPrice;
    }

    /**
     * What the bulk tiers took off one cart line, in cents.
     *
     * The cart item's own `unit_price` is already the discounted figure — this
     * plugin lowered it through `fluent_cart/cart/item_price` as the line was
     * built — so the original has to come back from the database. A direct
     * ProductVariation query is the right source precisely because it does NOT run
     * that filter: it is the last untouched copy of the pre-discount price.
     *
     * The saving is then recomputed the same way the cart filter computed the
     * price, rather than subtracting `unit_price`, so a line another extension also
     * repriced does not get that other extension's discount reported as ours.
     *
     * @param array $item One entry of the cart's `cart_data` items.
     * @return int Saving in cents; 0 when this line has none.
     */
    public static function lineSaving($item)
    {
        // Same gate as the cart price itself: a shopper the policy excludes was
        // never discounted, so there is nothing to report. Admins are not exempt
        // here — the 'cart' context deliberately excludes them (KTD4).
        if (!fcbo_user_qualifies_for_bulk_pricing(null, 'cart')) {
            return 0;
        }

        $qty       = (int) ($item['quantity'] ?? 0);
        $variantId = (int) ($item['object_id'] ?? 0);
        $productId = (int) ($item['post_id'] ?? 0);

        // Custom items are priced by whoever injected them, not from a variation row.
        if ($qty < 1 || !$variantId || !$productId || !empty($item['is_custom'])) {
            return 0;
        }

        // The cart re-renders this line on every quantity change and the drawer can
        // hold many lines, so the per-variant answer is memoized for the request.
        static $cache = [];
        $key = $variantId . ':' . $qty;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $cache[$key] = 0;

        $tiers = fcbo_resolve_tiers(
            fcbo_get_all_bulk_pricing([$productId]),
            $productId,
            $variantId,
            (array) wp_get_current_user()->roles
        );

        $tier = $tiers ? fcbo_match_tier($tiers, $qty) : null;
        if (!$tier) {
            return 0;
        }

        $variation = \FluentCart\App\Models\ProductVariation::query()->find($variantId);
        if (!$variation) {
            return 0;
        }

        $original  = (int) $variation->item_price;
        $effective = fcbo_apply_tier_to_price($original, $tier);

        $cache[$key] = max(0, ($original - $effective) * $qty);

        return $cache[$key];
    }
}
