<?php

namespace FluentCartBulkOrder\Pricing;

defined('ABSPATH') || exit;

/**
 * Bulk Pricing Tiers — the quantity-to-discount math.
 *
 * A tier maps a quantity range to one of three discounts: a percentage off, a
 * fixed per-unit price, or a flat amount off each unit. Everything here works in
 * integer cents and returns integer cents; rounding to a whole cent happens once,
 * at the end of applyToPrice(), so a percentage can never leak a fraction of a
 * cent into a total.
 *
 * Pure functions over tier arrays — no database and no request state.
 *
 * @see \FluentCartBulkOrder\Pricing\FeedResolver::resolveTiers() for which tier
 *      list applies to a given variant and shopper.
 */
class Tiers
{
    /**
     * Pick the applicable tier-set within a resolved feed by the shopper's roles.
     *
     * Selection, NOT authorization — Gate 2 has already decided whether any bulk
     * pricing applies at all.
     *
     * @see \FluentCartBulkOrder\AccessPolicy::selectRoleTierSet()
     * @param array         $feed      ['tiers' => array, 'role_tiers' => array]
     * @param string[]|null $userRoles Current user's role slugs.
     * @return array Tier list (may be empty).
     */
    public static function selectRoleTierSet($feed, $userRoles)
    {
        return \FluentCartBulkOrder\AccessPolicy::selectRoleTierSet($feed, $userRoles);
    }

    /**
     * Pick the tier that prices a given quantity.
     *
     * A tier matches when qty >= min_qty and (max_qty is 0 or qty <= max_qty).
     * Several tiers can match at once — an open-ended "30+" tier still matches at
     * qty 70 even when a "60+" tier exists — so the FIRST match is not the right
     * answer. The most specific one wins: the highest min_qty among the matches
     * (ties broken by the later tier, which is the one the admin added last).
     *
     * This is the ONE tier-matching rule in PHP; resolveTier() in
     * bulk-pricing-display.js and getEffectivePrice() in bulk-order.js mirror it
     * and MUST change together with it.
     *
     * @param array $tiers Sanitized tier list (any order).
     * @param int   $qty   Quantity being priced.
     * @return array|null The winning tier, or null when nothing matches.
     */
    public static function match($tiers, $qty)
    {
        $qty   = (int) $qty;
        $best  = null;
        $bestMin = -1;

        foreach ((array) $tiers as $tier) {
            $minQty = (int) ($tier['min_qty'] ?? 0);
            $maxQty = (int) ($tier['max_qty'] ?? 0);

            if ($qty < $minQty || ($maxQty > 0 && $qty > $maxQty)) {
                continue;
            }

            if ($minQty >= $bestMin) {
                $best    = $tier;
                $bestMin = $minQty;
            }
        }

        return $best;
    }

    /**
     * Compute the effective per-unit price (integer cents) for a matched tier.
     *
     * This is the ONE place in PHP the per-type discount formula lives; the two JS
     * live-total surfaces (bulk-order.js, bulk-pricing-display.js) mirror it exactly
     * and MUST change together with it. Money tier values are stored in major
     * currency units, so the major-units -> cents conversion happens here — the sole
     * conversion point on the PHP side. The result is always integer cents, clamped
     * to >= 0.
     *
     *   percent          -> round(price * (1 - value / 100))
     *   fixed_unit_price -> round(value * 100)              (absolute per-unit price)
     *   amount_off       -> price - round(value * 100)      (flat per-unit reduction)
     *
     * @param int   $itemPriceCents Original per-unit price in cents.
     * @param array $tier           ['discount_type' => string, 'discount_value' => float]
     * @return int Effective per-unit price in cents (>= 0).
     */
    public static function applyToPrice($itemPriceCents, $tier)
    {
        $type  = isset($tier['discount_type']) ? (string) $tier['discount_type'] : 'percent';
        $value = (float) ($tier['discount_value'] ?? 0);

        switch ($type) {
            case 'fixed_unit_price':
                $price = (int) round($value * 100);
                break;
            case 'amount_off':
                $price = (int) $itemPriceCents - (int) round($value * 100);
                break;
            case 'percent':
            default:
                $price = (int) round($itemPriceCents * (1 - $value / 100));
                break;
        }

        return max(0, $price);
    }

    /**
     * Human-readable label for a tier's discount, by type.
     *
     * Returns raw text — the caller escapes. Money types are formatted in major
     * units with the store currency sign; percent keeps the "% off" form.
     *
     * @param array $tier ['discount_type' => string, 'discount_value' => float]
     * @return string
     */
    public static function formatDiscountLabel($tier)
    {
        $type  = isset($tier['discount_type']) ? (string) $tier['discount_type'] : 'percent';
        $value = (float) ($tier['discount_value'] ?? 0);

        switch ($type) {
            case 'fixed_unit_price':
                // The currency sign is looked up inside the money branches, not
                // above the switch: it is the one thing in this class that
                // reaches out to the store, and a percent tier has no use for
                // it. Keeping it here leaves the percent path a pure function.
                // Money keeps 2 decimals (currency), matching the JS formatPrice() output.
                /* translators: %s: formatted unit price, e.g. $8.50 */
                return sprintf(__('%s/unit', 'fluent-cart-bulk-order'), fcbo_get_currency_sign() . number_format($value, 2));
            case 'amount_off':
                /* translators: %s: formatted amount, e.g. $2.00 */
                return sprintf(__('%s off', 'fluent-cart-bulk-order'), fcbo_get_currency_sign() . number_format($value, 2));
            case 'percent':
            default:
                /* translators: %s: percentage number, e.g. 10 */
                return sprintf(__('%s%% off', 'fluent-cart-bulk-order'), self::formatPercent($value));
        }
    }

    /**
     * One line summarising the best discount a whole tier set offers.
     *
     * This is the sentence on the collapsed Bulk Pricing accordion, and it is
     * the only thing a shopper sees before deciding whether the block is worth
     * opening — so it has to be worth clicking AND it has to be true.
     *
     * WHY A MONEY TIER CANNOT NAME ITS NUMBER
     * A percentage is the same quantity as "how much you save": 20% off IS a 20%
     * saving. The two money types are not. "$4.00/unit" is a price, not a
     * saving, and "$2.00 off" is a saving per unit whose share of the total
     * depends on a list price this sentence does not carry. Printing either
     * behind "save up to" would label a number as something it is not. So they
     * degrade to a generic phrase — exactly the trade unlockText() in
     * assets/js/bulk-order.js and assets/js/bulk-pricing-display.js already
     * makes when the next tier is a money tier.
     *
     * WHY ONE MONEY TIER DEGRADES THE WHOLE SET
     * "Save up to N%" claims N is the ceiling. In a set holding both a 20% tier
     * and a $4.00/unit tier, the real ceiling may well be the money tier, and
     * this function cannot tell without a list price. Naming 20 would be a
     * ceiling that is not the ceiling, so a mixed set degrades too.
     *
     * A tier with no type is a percent tier, matching self::applyToPrice().
     *
     * @param array                $tiers     Every tier the block will show, across all
     *                                        variants. Order does not matter.
     * @param array<string,string> $templates ['summary_percent' => '… {percent} …',
     *                                        'summary_generic' => '…']. Passed in
     *                                        rather than read from Strings so this
     *                                        stays a pure map from array to string.
     * @return string Ready to escape and print; '' when neither template is given.
     */
    public static function describeBestDiscount($tiers, $templates)
    {
        $bestPercent = 0.0;

        foreach ((array) $tiers as $tier) {
            $type = isset($tier['discount_type']) ? (string) $tier['discount_type'] : 'percent';

            if ($type !== 'percent') {
                $bestPercent = 0.0;
                break;
            }

            // Clamped at 100 because applyToPrice() clamps the price at zero: a
            // misconfigured 150% tier makes the item free, and "save up to 100%"
            // is the largest saving that can actually happen.
            $value = min(100.0, (float) ($tier['discount_value'] ?? 0));

            if ($value > $bestPercent) {
                $bestPercent = $value;
            }
        }

        if ($bestPercent > 0) {
            return str_replace(
                '{percent}',
                self::formatPercent($bestPercent),
                isset($templates['summary_percent']) ? (string) $templates['summary_percent'] : ''
            );
        }

        return isset($templates['summary_generic']) ? (string) $templates['summary_generic'] : '';
    }

    /**
     * A percentage as a shopper reads it: 10, not 10.00.
     *
     * Shared by formatDiscountLabel() and describeBestDiscount() so the tier
     * table and the summary line above it can never print the same discount two
     * different ways. unlockText() in the two JS surfaces mirrors this with
     * parseFloat(value.toFixed(2)).
     *
     * @param float $value
     * @return string
     */
    private static function formatPercent($value)
    {
        return rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    }
}
