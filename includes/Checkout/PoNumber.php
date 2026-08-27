<?php

namespace FluentCartBulkOrder\Checkout;

defined('ABSPATH') || exit;

/**
 * What a purchase-order number is allowed to be, and when one is demanded.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE HAS NO WORDPRESS IN IT
 * ---------------------------------------------------------------------------
 *
 * Same reason as QuoteInput: the rules below decide whether a checkout is
 * REFUSED, and a rule that decides a refusal is worth pinning without a
 * database behind it. So the sanitiser WordPress would normally supply is
 * INJECTED — the WordPress callers pass `sanitize_text_field`, the tests pass
 * their own and assert that whatever is passed is applied. The fallback strips
 * tags itself, so a caller that forgets still cannot store markup, but it is a
 * floor rather than the intended path.
 *
 * Escaping is not done here. A PO number is stored close to what the buyer
 * typed and escaped at every render site — sanitize on the way in, escape on
 * the way out.
 *
 * ---------------------------------------------------------------------------
 * THE THREE STATES ARE ONE VALUE, NOT TWO BOOLEANS
 * ---------------------------------------------------------------------------
 *
 * "Off", "optional" and "required" are stored as one string rather than an
 * enabled flag plus a required flag. Two booleans admit a fourth combination —
 * off but required — that has no meaning, and every reader of the checkout code
 * would then have to rule it out for themselves.
 *
 * @see \FluentCartBulkOrder\Checkout\PoSettings The stored-value side.
 * @see \FluentCartBulkOrder\AccessPolicy Gate 4, which decides WHO the mode
 *      applies to.
 */
class PoNumber
{
    /**
     * No PO field at checkout at all.
     *
     * The default, and it must stay the default: an existing store's checkout
     * has to look exactly the same after an upgrade as it did before it.
     */
    const MODE_OFF = 'off';

    /**
     * The field is shown, and an empty one still checks out.
     */
    const MODE_OPTIONAL = 'optional';

    /**
     * The field is shown and checkout is refused without it — on the server,
     * not only in the browser.
     */
    const MODE_REQUIRED = 'required';

    /**
     * How long a PO number may be, in characters.
     *
     * Sixty-four: long enough for the references purchasing systems actually
     * issue, short enough to fit an order screen column, an email line and a
     * CSV cell without wrapping. A longer value is TRUNCATED rather than
     * refused — a buyer who pastes something with trailing junk should still
     * get their order, and the store still gets the reference.
     */
    const MAX_LENGTH = 64;

    /**
     * Every legal mode, in the order the settings page offers them.
     *
     * @return string[]
     */
    public static function modes()
    {
        return [self::MODE_OFF, self::MODE_OPTIONAL, self::MODE_REQUIRED];
    }

    /**
     * Force any submitted value to a legal mode.
     *
     * Anything unrecognised becomes OFF, never REQUIRED. A corrupted option, a
     * hand-edited row, or a future rename must fail towards "the checkout keeps
     * working", not towards "nobody can buy anything".
     *
     * @param mixed $mode Raw value.
     * @return string One of self::modes().
     */
    public static function sanitizeMode($mode)
    {
        $mode = is_scalar($mode) ? strtolower(trim((string) $mode)) : '';

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_OFF;
    }

    /**
     * Whether the field is shown at all under this mode.
     *
     * @param mixed $mode
     * @return bool
     */
    public static function isOn($mode)
    {
        return self::sanitizeMode($mode) !== self::MODE_OFF;
    }

    /**
     * Whether checkout must be refused when the field is empty.
     *
     * @param mixed $mode
     * @return bool
     */
    public static function isRequired($mode)
    {
        return self::sanitizeMode($mode) === self::MODE_REQUIRED;
    }

    /**
     * Clean a submitted PO number down to something storable.
     *
     * The order of operations matters and is not arbitrary:
     *
     *   1. The injected sanitiser runs FIRST, on the raw value, because it is
     *      the one step that knows about WordPress — `sanitize_text_field()`
     *      strips tags and invalid UTF-8.
     *   2. Every control character and line break collapses to a space. A PO
     *      number is one line by definition, and a value carrying a newline
     *      would split a CSV row and could forge an email header. OrderCsv does
     *      not have to defend against that because this does.
     *   3. Runs of whitespace collapse to one and the ends are trimmed, so
     *      "PO  4711 " and "PO 4711" are one reference rather than two.
     *   4. Only THEN is the length capped, so the cap counts characters a buyer
     *      would recognise rather than whitespace they cannot see.
     *
     * @param mixed         $value     Raw submitted value.
     * @param callable|null $sanitizer WordPress' sanitiser; injected.
     * @return string Possibly empty; never longer than self::MAX_LENGTH.
     */
    public static function sanitize($value, $sanitizer = null)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = (string) $value;

        if (is_callable($sanitizer)) {
            $value = (string) call_user_func($sanitizer, $value);
        } else {
            // The floor for a caller that passed nothing. Deliberately NOT a
            // substitute for sanitize_text_field(); it only guarantees that
            // markup cannot reach a stored value through this function.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this file is deliberately WordPress-free so the unit suite can pin it without a database; wp_strip_all_tags() is what the INJECTED sanitiser brings, and this is only the floor for a caller that supplies none.
            $value = strip_tags($value);
        }

        // Control characters, including the newline and tab a paste can carry.
        // preg_replace() returns null on invalid UTF-8 rather than throwing, and
        // a null would silently become an empty string further down — so each
        // result is checked and the pre-replacement value kept on failure.
        $stripped = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value    = $stripped === null ? $value : $stripped;

        $collapsed = preg_replace('/\s+/u', ' ', $value);
        $value     = trim($collapsed === null ? $value : $collapsed);

        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($value, 0, self::MAX_LENGTH)
            : substr($value, 0, self::MAX_LENGTH);
    }

    /**
     * Whether an already-sanitised value fails a REQUIRED field.
     *
     * Its own function rather than `=== ''` at three call sites, because "what
     * counts as missing" is exactly the rule that drifts: the browser hint, the
     * checkout veto and the order screen must all agree, or a buyer is refused
     * for a value the form told them was fine.
     *
     * @param string $value An already-sanitised PO number.
     * @return bool
     */
    public static function isMissing($value)
    {
        return trim((string) $value) === '';
    }
}
