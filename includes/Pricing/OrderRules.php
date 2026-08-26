<?php

namespace FluentCartBulkOrder\Pricing;

defined('ABSPATH') || exit;

/**
 * Order Rules — the constraints on HOW MUCH may be ordered, as opposed to what
 * it costs.
 *
 * Two are per-variant (a minimum quantity and a case-pack step) and both are
 * unset by default, meaning no constraint. Rounding is always UPWARD and never
 * silent: a shopper who asks for 7 of something sold in 6s gets 12 and is told
 * so, because quietly reducing an order is worse than asking for more than they
 * typed.
 *
 * These are pure functions over a rules array — no database, no request, no
 * WordPress. That is deliberate: this is the arithmetic the surfaces and the
 * server-side backstop must agree on, so it is the part worth testing directly.
 *
 * @see \FluentCartBulkOrder\Pricing\FeedResolver::resolveOrderRules() for where
 *      a variant's rules come from.
 */
class OrderRules
{
    /**
     * Coerce a stored/raw order-rule pair into clamped integers.
     *
     * Mirrors BulkPricingIntegration::sanitizeOrderRules() so data that predates the
     * feature — or that was written by hand — still reads as the no-op default
     * rather than as a rule of 0 multiples.
     *
     * @param mixed $rules
     * @return array{min_qty:int, step:int}
     */
    public static function normalize($rules)
    {
        $rules = is_array($rules) ? $rules : [];

        return [
            'min_qty' => max(0, (int) ($rules['min_qty'] ?? 0)),
            'step'    => max(1, (int) ($rules['step'] ?? 1)),
        ];
    }

    /**
     * Whether a rule pair actually constrains anything.
     *
     * @param array $rules Normalized rules.
     * @return bool
     */
    public static function areSet($rules)
    {
        return ($rules['min_qty'] ?? 0) > 0 || ($rules['step'] ?? 1) > 1;
    }

    /**
     * Round a quantity up to the nearest value the rules permit.
     *
     * This is the ONE place in PHP the normalization formula lives; the JS surfaces
     * mirror it exactly and MUST change together with it. Rounding is always
     * upward — never downward — so a shopper is never silently given less than they
     * asked for.
     *
     * @param int   $qty
     * @param array $rules Normalized rules.
     * @return int Smallest permitted quantity >= $qty (always >= 1).
     */
    public static function normalizeQty($qty, $rules)
    {
        $rules = self::normalize($rules);
        $qty   = max(1, (int) $qty, $rules['min_qty']);

        if ($rules['step'] > 1) {
            $qty = (int) (ceil($qty / $rules['step']) * $rules['step']);
        }

        return $qty;
    }

    /**
     * Whether a quantity exactly satisfies the rules (server-side gate).
     *
     * Deliberately strict where self::normalizeQty() is forgiving: the client
     * corrects a typo in place, the server refuses anything that did not come
     * through that correction (KTD4).
     *
     * @param int   $qty
     * @param array $rules Normalized rules.
     * @return bool
     */
    public static function qtyIsValid($qty, $rules)
    {
        return (int) $qty === self::normalizeQty($qty, $rules);
    }
}
