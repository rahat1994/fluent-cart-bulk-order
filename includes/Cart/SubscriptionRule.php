<?php

namespace FluentCartBulkOrder\Cart;

defined('ABSPATH') || exit;

/**
 * What a subscription line is allowed to be, as FluentCart already decided.
 *
 * ---------------------------------------------------------------------------
 * THIS CLASS INVENTS NOTHING
 * ---------------------------------------------------------------------------
 *
 * Every rule here is a restatement of a refusal FluentCart already performs.
 * The host has an opinion about subscriptions and our surfaces have to agree
 * with it, not fight it or add a second one:
 *
 *   Quantity is one.       fluent-cart/api/Resource/FrontendResource/
 *                          CartResource.php:63-65 overwrites the requested
 *                          quantity with 1 for a subscription variation before
 *                          it builds the cart, and
 *                          fluent-cart/app/Models/ProductVariation.php:249
 *                          refuses canPurchase() outright above 1.
 *
 *   Never mixed.           fluent-cart/app/Models/Cart.php:443 refuses to add a
 *                          subscription to a cart that already holds anything,
 *                          or anything to a cart that already holds a
 *                          subscription.
 *
 * So the surfaces do not get to choose. They can only decide whether the
 * shopper learns the rule while they are building an order, or at the cart
 * after they have filled a whole table. This class is what lets them tell the
 * shopper first.
 *
 * ---------------------------------------------------------------------------
 * AND ONE RULE OF OURS, WHICH FOLLOWS FROM THEIRS
 * ---------------------------------------------------------------------------
 *
 * A Bulk Pricing Tier is a quantity discount. When the quantity can only ever
 * be 1, no tier above min_qty 1 can ever be reached, so a tier table on a
 * subscription is a price the store will never charge. It is worse than that:
 * this plugin does not even reach the subscription pricing path. Our discount
 * runs on `fluent_cart/cart/item_price`, and FluentCart builds subscription
 * plans for the gateways in ProductItemService::getItem()
 * (fluent-cart/app/Services/ProductItemService.php:68), which calls
 * `fluent_cart/cart/item_modify` and never reaches `item_price`.
 * @see \FluentCartBulkOrder\Cart\LinePricing for why that hook and not the other.
 *
 * Pure on purpose — no WordPress, no database, no FluentCart classes — so
 * tests/Unit/SubscriptionRuleTest.php can pin the rules without a store behind
 * them.
 */
class SubscriptionRule
{
    /**
     * The exact value FluentCart stores in `wp_fct_product_variations.payment_type`
     * for a recurring variant. Every comparison in this plugin goes through here
     * so a rename is one edit rather than a grep.
     */
    const RECURRING = 'subscription';

    /**
     * The value FluentCart uses for everything else, and what our payloads fall
     * back to when the column is empty.
     */
    const ONETIME = 'onetime';

    /**
     * Is this variant billed on a repeating schedule?
     *
     * Trimmed and lower-cased before comparing because the value arrives from
     * three places with three different amounts of care — a model attribute, a
     * REST payload the browser sent back, and a stored quote line — and a
     * stray " Subscription" must not read as one-time. That is the safe
     * direction of the two: mistaking a subscription for a one-time product
     * lets a shopper build an order the cart then refuses.
     *
     * @param mixed $paymentType Raw `payment_type` value.
     * @return bool
     */
    public static function isRecurring($paymentType)
    {
        return strtolower(trim((string) $paymentType)) === self::RECURRING;
    }

    /**
     * The quantity this line will actually be billed for.
     *
     * A restatement of CartResource.php:63-65, so a surface can show the
     * shopper the number the cart is going to use instead of the number they
     * typed. Non-recurring lines are handed straight back untouched — Order
     * Rules, not this class, decide those.
     *
     * @param mixed $paymentType Raw `payment_type` value.
     * @param mixed $quantity    Requested quantity.
     * @return int At least 1.
     */
    public static function settledQuantity($paymentType, $quantity)
    {
        if (self::isRecurring($paymentType)) {
            return 1;
        }

        return max(1, (int) $quantity);
    }

    /**
     * Would these lines together be a basket FluentCart refuses to hold?
     *
     * True only when BOTH kinds are present. A basket of two subscriptions is
     * not this function's business even though Cart.php:443 refuses that too —
     * the surfaces add one line at a time and the second subscription is
     * refused on its own terms, whereas mixing is the case a shopper can build
     * without ever being told.
     *
     * @param array $paymentTypes Raw `payment_type` values, one per line.
     * @return bool
     */
    public static function mixesTypes($paymentTypes)
    {
        $recurring = false;
        $onetime   = false;

        foreach (is_array($paymentTypes) ? $paymentTypes : [] as $paymentType) {
            if (self::isRecurring($paymentType)) {
                $recurring = true;
                continue;
            }

            $onetime = true;
        }

        return $recurring && $onetime;
    }
}
