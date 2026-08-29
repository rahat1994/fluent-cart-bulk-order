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

    /**
     * The one-line summary printed next to a quantity input ("Min 5, in 5s").
     *
     * The templates are passed in rather than translated here so this class stays
     * free of WordPress — see the class docblock. Callers hand it
     * \FluentCartBulkOrder\Strings::orderRuleHints(), which is the single wording
     * of these three sentences for every surface.
     *
     * The three-way branch is not cosmetic. "Min 10" on a product sold in 10s
     * hides the case-pack rule, and "Sold in 10s" on a product with a minimum of
     * 30 hides the minimum — either omission sends a shopper to a quantity the
     * server will refuse. So both-set gets its own sentence rather than two
     * concatenated ones, which is also what makes it translatable (@see
     * \FluentCartBulkOrder\Strings).
     *
     * assets/js/single-product-qty.js and assets/js/product-table.js each carry a
     * mirror of this branch. All three must change together.
     *
     * @param array                 $rules     Raw or normalized rules.
     * @param array<string,string>  $templates Keyed rule_min_and_step / rule_step / rule_min.
     * @return string Empty string when nothing constrains — callers render nothing.
     */
    public static function describe($rules, $templates)
    {
        $rules     = self::normalize($rules);
        $templates = is_array($templates) ? $templates : [];

        if ($rules['min_qty'] > 0 && $rules['step'] > 1) {
            $key = 'rule_min_and_step';
        } elseif ($rules['step'] > 1) {
            $key = 'rule_step';
        } elseif ($rules['min_qty'] > 0) {
            $key = 'rule_min';
        } else {
            return '';
        }

        // A missing key renders as the key itself, matching what the JS `t()`
        // helpers do: an obvious placeholder in review beats "" shipped silently.
        $template = isset($templates[$key]) ? (string) $templates[$key] : $key;

        return str_replace(
            ['{min}', '{step}'],
            [(string) $rules['min_qty'], (string) $rules['step']],
            $template
        );
    }
}
