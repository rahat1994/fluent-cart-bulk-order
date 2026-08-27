<?php

namespace FluentCartBulkOrder\Cart;

defined('ABSPATH') || exit;

/**
 * The server-side backstop for Order Rules.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS GUARANTEES, AND WHAT IT DOES NOT
 * ---------------------------------------------------------------------------
 *
 * The ordering surfaces round an out-of-rule quantity up and say so, but they
 * are a convenience: anyone can edit the page. These callbacks are the
 * authority, and they refuse rather than adjust.
 *
 * That authority is not total, and the limit is worth stating plainly.
 * Enforcement is exercised through the host store's extension points, so a
 * purchase path FluentCart does not route through those points is
 * unconstrained. The guarantee is "every path the host offers for refusal is
 * used", not "no order can ever violate a rule".
 *
 * @see docs/solutions/architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout.md
 */
class RuleEnforcement
{
    /**
     * Reject an add-to-cart whose quantity violates the variant's order rules.
     *
     * Bound to `fluent_cart/variation/can_purchase_bundle`, which despite its name
     * fires inside the generic ProductVariation::canPurchase() and therefore covers
     * every add path (see the registration comment for the full rationale).
     *
     * @param mixed $result  Prior verdict: null (undecided), false, or WP_Error.
     * @param array $context ['variation' => object, 'quantity' => int]
     * @return mixed WP_Error to veto; the untouched $result otherwise.
     */
    public static function validateCartItem($result, $context)
    {
        // Never override a veto another party already cast (e.g. out of stock).
        if (is_wp_error($result) || $result === false) {
            return $result;
        }

        $variation = isset($context['variation']) ? $context['variation'] : null;
        $qty       = (int) ($context['quantity'] ?? 0);

        if (!$variation || empty($variation->id) || $qty < 1) {
            return $result;
        }

        $productId = (int) $variation->post_id;
        $variantId = (int) $variation->id;

        $rules = fcbo_resolve_order_rules(fcbo_get_all_bulk_pricing([$productId]), $productId, $variantId);

        if (!fcbo_order_rules_are_set($rules) || fcbo_qty_is_valid($qty, $rules)) {
            return $result;
        }

        return new \WP_Error('fcbo_order_rule', self::describeQtyViolation($qty, $rules));
    }

    /**
     * Shopper-facing explanation of why a quantity was refused.
     *
     * Always names the nearest acceptable quantity so the message is actionable
     * rather than merely a rejection.
     *
     * @param int   $qty   The rejected quantity.
     * @param array $rules Normalized rules.
     * @return string
     */
    public static function describeQtyViolation($qty, $rules)
    {
        $rules     = fcbo_normalize_order_rules($rules);
        $suggested = fcbo_normalize_qty($qty, $rules);

        if ($rules['min_qty'] > 0 && $rules['step'] > 1) {
            /* translators: 1: minimum quantity, 2: case-pack size, 3: nearest valid quantity */
            $format = __('This product has a minimum of %1$d and is sold in multiples of %2$d. Try %3$d.', 'fluent-cart-bulk-order');
            return sprintf($format, $rules['min_qty'], $rules['step'], $suggested);
        }

        if ($rules['step'] > 1) {
            /* translators: 1: case-pack size, 2: nearest valid quantity */
            $format = __('This product is sold in multiples of %1$d. Try %2$d.', 'fluent-cart-bulk-order');
            return sprintf($format, $rules['step'], $suggested);
        }

        /* translators: 1: minimum quantity */
        return sprintf(__('This product has a minimum order quantity of %1$d.', 'fluent-cart-bulk-order'), $rules['min_qty']);
    }

    /**
     * Block checkout when a subject shopper's cart is under the order minimum.
     *
     * Bound to `fluent_cart/checkout/validate_data`, which receives the resolved
     * cart and halts checkout when the returned error array is non-empty.
     *
     * The comparison basis is the items subtotal — bulk-discounted line prices,
     * before coupons, shipping, and tax. That deliberately matches what the Bulk
     * Order Form shows as its grand total, so the client-side warning and this gate
     * can never disagree about whether a cart clears the floor.
     *
     * @param array $errors  Accumulated validation errors (nested: field => code => msg).
     * @param array $context ['data' => array, 'cart' => object]
     * @return array
     */
    public static function validateCheckoutMinimum($errors, $context)
    {
        $minimum = fcbo_get_min_order_total();
        if ($minimum <= 0 || !fcbo_user_subject_to_min_order()) {
            return $errors;
        }

        $cart = isset($context['cart']) ? $context['cart'] : null;
        if (!$cart || !method_exists($cart, 'getItemsSubtotal')) {
            return $errors;
        }

        $subtotal = (int) $cart->getItemsSubtotal();
        if ($subtotal >= $minimum) {
            return $errors;
        }

        /* translators: 1: shortfall amount, 2: minimum order total */
        $format = __('Add %1$s more to reach the %2$s minimum order total.', 'fluent-cart-bulk-order');

        $errors['fcbo_min_order_total']['minimum'] = sprintf(
            $format,
            fcbo_format_money($minimum - $subtotal),
            fcbo_format_money($minimum)
        );

        return $errors;
    }
}
