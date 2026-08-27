<?php

namespace FluentCartBulkOrder\Shortcodes;

defined('ABSPATH') || exit;

/**
 * Which shortcode attributes an editor surface may set, and how a control value
 * becomes a shortcode attribute.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS AT ALL — THE PRECEDENCE TRAP
 * ---------------------------------------------------------------------------
 *
 * Precedence for these surfaces points one way and only one way:
 *
 *     shortcode attribute  >  stored store-wide default  >  hardcoded fallback
 *
 * That is not enforced by an if-statement anywhere. It is a property of HOW the
 * store-wide defaults are supplied: ProductTable::defaults() reads
 * \FluentCartBulkOrder\StoreDefaults and hands the result to shortcode_atts() as
 * the DEFAULTS layer. shortcode_atts() then lets any attribute that was actually
 * passed win. @see \FluentCartBulkOrder\StoreDefaults
 *
 * A Gutenberg block or an Elementor widget breaks that chain the moment it
 * passes an attribute the store owner never touched. `per_page => ''` is not
 * "no opinion" to shortcode_atts(); it is an explicit empty string that beats
 * the stored default and lands on the hardcoded fallback of 5. The owner then
 * sets "Rows per page: 20" in the plugin settings, sees the block still showing
 * 5, and has no way to tell why.
 *
 * So the rule this class exists to hold: AN UNTOUCHED CONTROL MUST NOT PRODUCE
 * AN ATTRIBUTE. toShortcodeAtts() returns only the keys the owner really set;
 * everything else is omitted so shortcode_atts() falls through to the store
 * default exactly as a hand-written `[fluent_cart_product_table]` with no
 * attributes does.
 *
 * ---------------------------------------------------------------------------
 * WHY NO WORDPRESS IN THIS FILE
 * ---------------------------------------------------------------------------
 *
 * Every function here is a pure map from array to array. That is deliberate:
 * this is the one place the precedence bug above could be reintroduced, and a
 * pure class is one the unit suite can pin without booting WordPress.
 * @see tests/Unit/AttributeSchemaTest.php, and tests/README.md for the rule.
 *
 * Sanitising and clamping stay OUT of here. The shortcode classes already
 * validate every one of these values (absint + clamp for per_page,
 * fcbo_parse_columns_attr() for columns, fcbo_sanitize_category_param() for
 * category, AccessPolicy::parseRolesAttr() for roles), and duplicating that
 * would give a block a second, drifting definition of "valid".
 */
class AttributeSchema
{
    /**
     * Free text, passed through as-is. Empty means "not set".
     */
    const TEXT = 'text';

    /**
     * A positive integer. Zero, blank and junk all mean "not set" — which is
     * better than passing them on, because ProductTable::resolvePerPage() turns
     * an unusable value into the HARDCODED default, skipping the stored one.
     */
    const NUMBER = 'number';

    /**
     * A comma-separated allowlist (currently only `columns`). Accepts an array
     * too, because Elementor's multi-select control hands back an array while a
     * block attribute keeps the comma string the shortcode itself uses.
     */
    const CSV = 'csv';

    /**
     * A three-state boolean: on, off, or "not set".
     *
     * Deliberately not a checkbox or an Elementor switcher. Those are two-state,
     * and a two-state control cannot express "leave the store default alone" —
     * its off position would have to mean either "off" or "unset", and either
     * choice silently takes an option away from the owner.
     */
    const TERNARY = 'ternary';

    /**
     * Shortcode tag => attribute name => control type.
     *
     * Scope note: `fluent_cart_saved_orders` is a registered tag
     * (@see \FluentCartBulkOrder\Shortcodes\ShortcodeHandler::SHORTCODES) but is
     * absent here on purpose — the editor wrappers cover the two browse/order
     * surfaces only. Adding it is one entry plus one block folder.
     *
     * The names and the order match BulkOrderForm::defaults() and
     * ProductTable::defaults(). They also have to match the `attributes` object
     * in each blocks/<name>/block.json, which static JSON cannot read from here;
     * @see \FluentCartBulkOrder\Blocks\BlockHandler::register() for the runtime
     * check that keeps the two honest.
     */
    const SCHEMA = [
        'fluent_cart_bulk_order' => [
            'roles'    => self::TEXT,
            'redirect' => self::TEXT,
            // Whether this placement offers "Request a quote". TERNARY rather
            // than a checkbox for the reason on self::TERNARY: an owner has to
            // be able to say "no quotes on THIS page" without that being the
            // same value as "follow the store setting".
            'quotes'   => self::TERNARY,
        ],
        'fluent_cart_product_table' => [
            'per_page'        => self::NUMBER,
            'columns'         => self::CSV,
            'search'          => self::TERNARY,
            'category'        => self::TEXT,
            'roles'           => self::TEXT,
            'expand_variants' => self::TERNARY,
        ],
    ];

    /**
     * Tags that have an editor wrapper.
     *
     * @return string[]
     */
    public static function tags()
    {
        return array_keys(self::SCHEMA);
    }

    /**
     * The controls one tag exposes, as name => type.
     *
     * @param string $tag A key of self::SCHEMA.
     * @return array<string, string> Empty for a tag with no editor wrapper.
     */
    public static function controls($tag)
    {
        $tag = (string) $tag;

        return isset(self::SCHEMA[$tag]) ? self::SCHEMA[$tag] : [];
    }

    /**
     * Turn editor control values into the attribute array to hand a shortcode.
     *
     * Two guarantees, both load-bearing, both pinned by the unit suite:
     *
     *   1. Only keys declared in self::SCHEMA for this tag come out. An editor
     *      cannot smuggle an attribute the shortcode never meant to accept.
     *   2. A key whose value resolves to "not set" is OMITTED, never emitted as
     *      ''. That omission is what preserves attribute > stored default.
     *
     * @param string               $tag    A key of self::SCHEMA.
     * @param array<string, mixed> $values Raw control values, keyed by attribute
     *                                     name. Unknown keys are ignored.
     * @return array<string, string> Attributes the caller genuinely set.
     */
    public static function toShortcodeAtts($tag, $values)
    {
        $controls = self::controls($tag);

        if (!$controls || !is_array($values)) {
            return [];
        }

        $atts = [];

        foreach ($controls as $name => $type) {
            if (!array_key_exists($name, $values)) {
                continue;
            }

            $resolved = self::normalize($type, $values[$name]);

            // null is the "not set" signal from normalize(). It has to be a
            // distinct value from '', because '' is a legitimate thing to pass
            // for a text attribute in a hand-written shortcode — it just is not
            // something an untouched control should ever say.
            if ($resolved === null) {
                continue;
            }

            $atts[$name] = $resolved;
        }

        return $atts;
    }

    /**
     * One control value to its shortcode-attribute string.
     *
     * @param string $type One of the type constants above.
     * @param mixed  $value Raw control value.
     * @return string|null The attribute value, or null for "not set".
     */
    private static function normalize($type, $value)
    {
        switch ($type) {
            case self::CSV:
                return self::normalizeCsv($value);

            case self::NUMBER:
                return self::normalizeNumber($value);

            case self::TERNARY:
                return self::normalizeTernary($value);

            default:
                return self::normalizeText($value);
        }
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function normalizeText($value)
    {
        // An object or array here means a control was wired to the wrong type.
        // Treating that as "not set" keeps the store default in play rather than
        // pushing "Array" into a shortcode attribute.
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function normalizeNumber($value)
    {
        if (is_bool($value) || !is_scalar($value) || !is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        // Zero and negatives are what an emptied number field reports, so they
        // mean "not set" rather than "zero rows".
        return $number > 0 ? (string) $number : null;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function normalizeCsv($value)
    {
        if (is_array($value)) {
            $parts = [];

            foreach ($value as $part) {
                if (!is_scalar($part)) {
                    continue;
                }

                $part = trim((string) $part);

                if ($part !== '') {
                    $parts[] = $part;
                }
            }

            $value = implode(',', $parts);
        }

        return self::normalizeText($value);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function normalizeTernary($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        // The vocabulary of every yes/no control that could reach this: a block
        // select ('true'/'false'), an Elementor switcher ('yes'/''), and the
        // literals filter_var(FILTER_VALIDATE_BOOLEAN) accepts on the far side.
        if (in_array($value, ['true', 'yes', 'on', '1'], true)) {
            return 'true';
        }

        if (in_array($value, ['false', 'no', 'off', '0'], true)) {
            return 'false';
        }

        // Anything else, '' included, is the "Use store default" option.
        return null;
    }
}
