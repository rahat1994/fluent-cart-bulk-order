<?php

namespace FluentCartBulkOrder\Analytics;

use FluentCartBulkOrder\Pricing\Tiers;

defined('ABSPATH') || exit;

/**
 * The identity of one Bulk Pricing Tier, and the name an owner recognises it by.
 *
 * ---------------------------------------------------------------------------
 * A TIER HAS NO ID, SO ITS DEFINITION IS ITS IDENTITY
 * ---------------------------------------------------------------------------
 *
 * Tiers are rows in an array stored on an Integration Feed. They carry no id,
 * no slug and no creation date — nothing that survives the owner deleting the
 * third row and adding a new one. So there is no key to record except the tier
 * itself: where it lives (store-wide, or on one product), whose price list it
 * belongs to (a role-scoped set, or the default one), and what it actually says
 * (the quantity range and the discount).
 *
 * That has a consequence worth stating plainly, because it looks like a bug
 * until you see why it is not:
 *
 *   EDITING A TIER CREATES A NEW ONE, as far as this screen is concerned.
 *
 * An owner who changes "50+ → 10% off" to "50+ → 12% off" gets two rows in
 * tier utilization, and that is the honest answer. The orders under the first
 * row were charged 10%; the orders under the second were charged 12%. Merging
 * them would report a discount rate no order was ever placed at, and would hide
 * exactly the thing an owner changing a tier wants to see — whether the change
 * moved anything.
 *
 * The same property is what lets the screen still NAME a tier the owner has
 * since deleted. The name is rebuilt from the recorded columns, not looked up
 * in a feed that may no longer contain it.
 *
 * ---------------------------------------------------------------------------
 * WHY THE KEY IS A HASH AND NOT THE COLUMNS THEMSELVES
 * ---------------------------------------------------------------------------
 *
 * The recorded row already holds every component in its own column, so the key
 * adds no information. What it adds is ONE indexed column to GROUP BY, instead
 * of a seven-column composite that MySQL would have to sort on every report.
 * The components stay beside it precisely so the label never has to be
 * un-hashed.
 *
 * Pure: array in, string out. No database, no request state.
 *
 * @see \FluentCartBulkOrder\Pricing\Tiers The tier maths this describes.
 * @see \FluentCartBulkOrder\Analytics\TierUsage Which tiers were used, and which never were.
 */
class TierSignature
{
    /**
     * Scope values — where the tier's feed lives.
     */
    const SCOPE_GLOBAL = 'global';
    const SCOPE_PRODUCT = 'product';

    /**
     * The stable identity of one tier, as a 32-character hex string.
     *
     * Every component that changes what a shopper is charged is in the hash,
     * and nothing else is. The discount value is normalised to four decimal
     * places first so that `10`, `10.0` and `"10.00"` — all of which a stored
     * feed can legitimately contain — are one tier and not three.
     *
     * @param array  $tier      ['min_qty' => int, 'max_qty' => int,
     *                           'discount_type' => string, 'discount_value' => float]
     * @param string $scope     self::SCOPE_GLOBAL or self::SCOPE_PRODUCT.
     * @param int    $productId The product whose feed holds the tier; 0 for a
     *                          store-wide feed.
     * @param string $role      The role-scoped tier set this tier belongs to;
     *                          '' for the feed's default set.
     * @return string 32 hex characters.
     */
    public static function key($tier, $scope, $productId, $role = '')
    {
        $parts = [
            self::scope($scope),
            (int) $productId,
            (string) $role,
            (int) (isset($tier['min_qty']) ? $tier['min_qty'] : 0),
            (int) (isset($tier['max_qty']) ? $tier['max_qty'] : 0),
            self::type($tier),
            number_format((float) (isset($tier['discount_value']) ? $tier['discount_value'] : 0), 4, '.', ''),
        ];

        // Not a security boundary — nothing is authorised by this value and
        // nothing is hidden by it. It is a grouping key, so md5 is chosen for
        // being short enough to index cheaply.
        return md5(implode('|', $parts));
    }

    /**
     * The tier's components, ready to be written as columns.
     *
     * Built here rather than at the call site so the key and the columns can
     * never describe two different tiers.
     *
     * @param array  $tier
     * @param string $scope
     * @param int    $productId
     * @param string $role
     * @return array<string, mixed>
     */
    public static function columns($tier, $scope, $productId, $role = '')
    {
        return [
            'tier_key'        => self::key($tier, $scope, $productId, $role),
            'tier_scope'      => self::scope($scope),
            'tier_product_id' => (int) $productId,
            'tier_role'       => (string) $role,
            'tier_min_qty'    => max(0, (int) (isset($tier['min_qty']) ? $tier['min_qty'] : 0)),
            'tier_max_qty'    => max(0, (int) (isset($tier['max_qty']) ? $tier['max_qty'] : 0)),
            'tier_type'       => self::type($tier),
            'tier_value'      => (float) (isset($tier['discount_value']) ? $tier['discount_value'] : 0),
        ];
    }

    /**
     * The quantity range, written the way the feed editor writes it.
     *
     * `max_qty` of 0 means open-ended, which is how the feed stores "and up" —
     * @see \FluentCartBulkOrder\Pricing\Tiers::match(). Printing "50 – 0" for
     * that would read as a broken row rather than as the commonest tier shape
     * there is.
     *
     * @param int $minQty
     * @param int $maxQty 0 = no upper limit.
     * @return string
     */
    public static function rangeLabel($minQty, $maxQty)
    {
        $minQty = max(0, (int) $minQty);
        $maxQty = max(0, (int) $maxQty);

        if ($maxQty < 1) {
            /* translators: %s: a minimum quantity, e.g. 50. Describes an open-ended tier. */
            return sprintf(__('%s or more', 'fluent-cart-bulk-order'), number_format_i18n($minQty));
        }

        return sprintf(
            /* translators: 1: lowest quantity in the tier; 2: highest quantity in the tier. */
            __('%1$s to %2$s', 'fluent-cart-bulk-order'),
            number_format_i18n($minQty),
            number_format_i18n($maxQty)
        );
    }

    /**
     * The discount, in the same words the feed editor and the shopper see.
     *
     * Delegates to Tiers::formatDiscountLabel() rather than repeating the
     * per-type formatting, so a tier reads identically on the analytics screen,
     * on the product page and in the cart.
     *
     * @param string $type  A discount type slug.
     * @param float  $value The stored discount value, in major currency units
     *                      for the money types.
     * @return string
     */
    public static function discountLabel($type, $value)
    {
        return Tiers::formatDiscountLabel([
            'discount_type'  => (string) $type,
            'discount_value' => (float) $value,
        ]);
    }

    /**
     * Normalise a scope to one of the two legal values.
     *
     * @param string $scope
     * @return string
     */
    private static function scope($scope)
    {
        return $scope === self::SCOPE_PRODUCT ? self::SCOPE_PRODUCT : self::SCOPE_GLOBAL;
    }

    /**
     * The tier's discount type, defaulted the same way Tiers does.
     *
     * `percent` is the fallback in Tiers::applyToPrice() and in both JS
     * mirrors, so a feed row with no type is a percent tier everywhere. It has
     * to be a percent tier here too or the same tier would hash two ways
     * depending on whether the key was present.
     *
     * @param array $tier
     * @return string
     */
    private static function type($tier)
    {
        $type = isset($tier['discount_type']) ? (string) $tier['discount_type'] : '';

        return $type !== '' ? $type : 'percent';
    }
}
