<?php

namespace FluentCartBulkOrder\Shortcodes;

defined('ABSPATH') || exit;

/*
 * No autoloader in this plugin (@see the require_once calls in
 * fluent-cart-bulk-order.php), and every method below reads a constant off one
 * of these two classes. Required at file scope rather than inside each method
 * because a class constant cannot be lazily resolved the way a method call can:
 * one missing require here is a fatal on the settings screen, not a blank
 * section.
 */
require_once __DIR__ . '/ShortcodeHandler.php';
require_once __DIR__ . '/AttributeSchema.php';

/**
 * What a store owner needs to know about each shortcode this plugin registers.
 *
 * ---------------------------------------------------------------------------
 * THIS CLASS OWNS NO LIST OF TAGS
 * ---------------------------------------------------------------------------
 *
 * The tags come from \FluentCartBulkOrder\Shortcodes\ShortcodeHandler::SHORTCODES
 * and from nowhere else. That constant's docblock calls itself the single source
 * of truth and it means it: a second list here would let the admin screen and
 * the actual registration disagree, and the whole point of the screen is to tell
 * an owner what is really registered.
 *
 * So tags() iterates the registry, and everything below is a LOOKUP keyed by a
 * tag rather than a list of tags. A fifth shortcode added to the registry
 * appears on the screen immediately; what it will be missing is a sentence and
 * an attribute description, and ShortcodeCatalogTest fails until both exist.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE ATTRIBUTES COME FROM
 * ---------------------------------------------------------------------------
 *
 * \FluentCartBulkOrder\Shortcodes\AttributeSchema::SCHEMA already names every
 * attribute of the two tags that have an editor wrapper, together with its type.
 * That is read directly. Re-typing those names here would give the plugin a
 * second, drifting definition of "what may be passed to a product table".
 *
 * EXTRA_ATTRIBUTES exists only for attributes NO schema owns. Today that is one
 * entry: `[fluent_cart_saved_orders roles="..."]`. AttributeSchema is
 * deliberately scoped to editor wrappers (@see its SCHEMA docblock) and adding
 * saved orders to it would claim a block and an Elementor widget that do not
 * exist, so the attribute is declared here instead. ShortcodeCatalogTest asserts
 * the two never overlap, which is what stops this map from quietly growing into
 * the duplicate definition the paragraph above rules out.
 *
 * ---------------------------------------------------------------------------
 * WHY IT IS PURE
 * ---------------------------------------------------------------------------
 *
 * Nothing here touches WordPress beyond `__()`, and nothing touches the
 * database. That is what lets the unit suite prove every registered tag is
 * documented without standing up a site. The database half — "does a page
 * already use this tag" — lives in
 * \FluentCartBulkOrder\Admin\ShortcodePages, which is where the caching is too.
 */
class ShortcodeCatalog
{
    /**
     * Attributes that no editor schema owns, as tag => name => type.
     *
     * Keep this as small as it can be. @see the class docblock for the rule.
     */
    const EXTRA_ATTRIBUTES = [
        'fluent_cart_saved_orders' => [
            'roles' => AttributeSchema::TEXT,
        ],
    ];

    /**
     * Every tag this plugin registers, in registration order.
     *
     * @return string[]
     */
    public static function tags()
    {
        return array_keys(ShortcodeHandler::SHORTCODES);
    }

    /**
     * Short human titles, keyed by tag.
     *
     * These are also the block titles in blocks/<name>/block.json for the two
     * tags that have a block, and ShortcodeCatalogTest pins that. An owner who
     * reads "Product Table" here has to find "Product Table" in the inserter.
     *
     * @return array<string, string>
     */
    public static function labels()
    {
        return [
            'fluent_cart_bulk_order'            => __('Bulk Order Form', 'fluent-cart-bulk-order'),
            'fluent_cart_product_table'         => __('Product Table', 'fluent-cart-bulk-order'),
            'fluent_cart_saved_orders'          => __('Saved Orders', 'fluent-cart-bulk-order'),
            'fluent_cart_wholesale_application' => __('Wholesale Application', 'fluent-cart-bulk-order'),
        ];
    }

    /**
     * One sentence per tag, keyed by tag.
     *
     * One sentence on purpose. This screen answers "which of these do I want?",
     * and four paragraphs is a page an owner skims instead of reads.
     *
     * @return array<string, string>
     */
    public static function descriptions()
    {
        return [
            'fluent_cart_bulk_order' => __(
                'The bulk order form: a permitted buyer searches products by name or SKU, or pastes a SKU list, sets quantities line by line and sends the whole order to checkout in one action.',
                'fluent-cart-bulk-order'
            ),
            'fluent_cart_product_table' => __(
                'A paginated table of your catalogue with a quantity box on every row, so a buyer can add several products to the cart without opening a single product page.',
                'fluent-cart-bulk-order'
            ),
            'fluent_cart_saved_orders' => __(
                "A buyer's own saved orders, re-priced against the live catalogue every time they are opened, each with a one-click reorder.",
                'fluent-cart-bulk-order'
            ),
            'fluent_cart_wholesale_application' => __(
                'The form a signed-in shopper fills in to ask for a wholesale account, which you then approve or reject from the Wholesale Applications screen.',
                'fluent-cart-bulk-order'
            ),
        ];
    }

    /**
     * One sentence per ATTRIBUTE name, shared across the tags that accept it.
     *
     * Keyed by name and not by tag+name because `roles` means the same thing on
     * all three tags that take it, and writing it out three times is how the
     * three end up saying three different things.
     *
     * @return array<string, string>
     */
    public static function attributeHelp()
    {
        return [
            'roles' => __(
                'Comma-separated role slugs allowed to open this placement. Leave it out to use the store-wide access policy.',
                'fluent-cart-bulk-order'
            ),
            'redirect' => __(
                'Where the buyer is sent after the order goes to checkout. Leave it out to use the store-wide setting.',
                'fluent-cart-bulk-order'
            ),
            'quotes' => __(
                'Whether this placement offers "Request a quote". Leave it out to follow the Quotes setting.',
                'fluent-cart-bulk-order'
            ),
            'per_page' => __(
                'How many variant rows one page of the table shows. Leave it out to use the Surfaces setting.',
                'fluent-cart-bulk-order'
            ),
            'columns' => __(
                'Which columns to show, comma separated, from: id, title, price, qty, action. Leave it out to use the Surfaces setting.',
                'fluent-cart-bulk-order'
            ),
            'search' => __(
                'Whether the search box is shown above the table. Leave it out to use the Surfaces setting.',
                'fluent-cart-bulk-order'
            ),
            'category' => __(
                'Limit the table to one product category, given as its slug or its id.',
                'fluent-cart-bulk-order'
            ),
            'expand_variants' => __(
                'Whether variant rows start open. Leave it out to use the Surfaces setting.',
                'fluent-cart-bulk-order'
            ),
        ];
    }

    /**
     * A human name for each AttributeSchema type constant.
     *
     * @return array<string, string>
     */
    public static function typeLabels()
    {
        return [
            AttributeSchema::TEXT    => __('text', 'fluent-cart-bulk-order'),
            AttributeSchema::NUMBER  => __('number', 'fluent-cart-bulk-order'),
            AttributeSchema::CSV     => __('comma-separated list', 'fluent-cart-bulk-order'),
            AttributeSchema::TERNARY => __('true or false', 'fluent-cart-bulk-order'),
        ];
    }

    /**
     * The human title for one tag, falling back to the tag itself.
     *
     * A fall back rather than an empty string: a registered tag with no entry
     * here is a mistake the test suite catches, but if one ever ships, an owner
     * should still see a usable row instead of a heading-less block.
     *
     * @param string $tag A key of ShortcodeHandler::SHORTCODES.
     * @return string
     */
    public static function label($tag)
    {
        $labels = self::labels();

        return isset($labels[$tag]) ? $labels[$tag] : (string) $tag;
    }

    /**
     * The one-sentence description for one tag.
     *
     * @param string $tag A key of ShortcodeHandler::SHORTCODES.
     * @return string Empty when the tag has no entry.
     */
    public static function description($tag)
    {
        $descriptions = self::descriptions();

        return isset($descriptions[$tag]) ? $descriptions[$tag] : '';
    }

    /**
     * Every attribute one tag accepts, as name => [type, type_label, help].
     *
     * The schema's own order is kept, because it matches the order the
     * shortcode's defaults() declares them in and an owner reading both should
     * not have to re-sort.
     *
     * @param string $tag A key of ShortcodeHandler::SHORTCODES.
     * @return array<string, array<string, string>> Empty for a tag with no
     *                                              attributes at all.
     */
    public static function attributes($tag)
    {
        $tag = (string) $tag;

        $types = AttributeSchema::controls($tag);

        if (isset(self::EXTRA_ATTRIBUTES[$tag])) {
            // Union, not overwrite. The schema wins on any name it owns, which
            // is the invariant ShortcodeCatalogTest pins — this line is only
            // ever reached for names it does not.
            $types = array_merge(self::EXTRA_ATTRIBUTES[$tag], $types);
        }

        $help       = self::attributeHelp();
        $typeLabels = self::typeLabels();
        $attributes = [];

        foreach ($types as $name => $type) {
            $attributes[$name] = [
                'type'       => $type,
                'type_label' => isset($typeLabels[$type]) ? $typeLabels[$type] : $type,
                'help'       => isset($help[$name]) ? $help[$name] : '',
            ];
        }

        return $attributes;
    }

    /**
     * Whether this tag also ships as a Gutenberg block and an Elementor widget.
     *
     * Answered from AttributeSchema::tags() rather than from a list of block
     * folders, because that constant is already the definition of "has an
     * editor wrapper" — both wrappers read their controls from it, and
     * BlockHandler::register() fails loudly in development when a block.json
     * stops matching it. A tag that is in the schema has both wrappers; a tag
     * that is not has neither.
     *
     * @param string $tag A key of ShortcodeHandler::SHORTCODES.
     * @return bool
     */
    public static function hasEditorWrappers($tag)
    {
        return in_array((string) $tag, AttributeSchema::tags(), true);
    }

    /**
     * The example an owner copies: the tag with no attributes on it.
     *
     * Deliberately bare. Every attribute is optional and every one of them
     * overrides a store-wide default for that placement, so an example carrying
     * `per_page="20"` would hand out a snippet that quietly ignores the
     * Surfaces tab. @see \FluentCartBulkOrder\StoreDefaults for the precedence.
     *
     * @param string $tag A key of ShortcodeHandler::SHORTCODES.
     * @return string
     */
    public static function snippet($tag)
    {
        return '[' . (string) $tag . ']';
    }
}
