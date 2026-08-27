<?php

namespace FluentCartBulkOrder\Blocks;

use FluentCartBulkOrder\Shortcodes\AttributeSchema;
use FluentCartBulkOrder\Shortcodes\ShortcodeHandler;

defined('ABSPATH') || exit;

/**
 * The block editor wrappers for the FCBO shortcodes.
 *
 * ---------------------------------------------------------------------------
 * THIN ON PURPOSE
 * ---------------------------------------------------------------------------
 *
 * Every block here is a dynamic block whose render_callback does exactly two
 * things: turn block attributes into shortcode attributes
 * (@see AttributeSchema) and hand them to ShortcodeHandler::renderTag(). There
 * is no markup in this file and there must never be any — the moment a block
 * grows its own template, the block and the shortcode start drifting and a
 * store owner gets a different surface depending on which one they placed.
 *
 * The saved post content is empty for both blocks. Nothing is baked into the
 * database, so a change to a shortcode class immediately reaches every existing
 * block placement.
 *
 * ---------------------------------------------------------------------------
 * NO BUILD STEP
 * ---------------------------------------------------------------------------
 *
 * assets/js/blocks-editor.js is hand-written ES5 against wp.element.createElement
 * — no JSX, no imports, no bundler. This plugin has no npm toolchain and is
 * headed for the WordPress.org directory, where a compiled asset means shipping
 * and justifying its sources. A block wrapper is not worth that, so the editor
 * script stays readable as-is.
 *
 * Each blocks/<name>/block.json names `fcbo-blocks-editor` as its editorScript.
 * That is a script HANDLE, not a path: WordPress only treats an asset field as a
 * file when it is written `file:./...`, so registering the handle here first is
 * what makes the metadata resolve. One handle serves both blocks; WordPress
 * enqueues it once.
 *
 * ---------------------------------------------------------------------------
 * WHY `multiple: false` AND NO className SUPPORT
 * ---------------------------------------------------------------------------
 *
 * Both surfaces address themselves through fixed element ids (#fcbo-bulk-order,
 * #fcbo-tbody, #fcbo-product-table, #fcbo-pt-search) and their scripts localise
 * one config object per page. Two copies on one page would fight over both, so
 * the editor refuses the second insertion rather than shipping a half-working
 * page.
 *
 * className/customClassName are off because render_callback returns the
 * shortcode's markup byte for byte, with no wrapper element to hang a class on.
 * Offering a control that silently does nothing is worse than not offering it.
 *
 * @see \FluentCartBulkOrder\Shortcodes\AttributeSchema Why an untouched control
 *      must not produce an attribute.
 */
class BlockHandler
{
    /**
     * Editor script handle, referenced by name from both block.json files.
     */
    const EDITOR_HANDLE = 'fcbo-blocks-editor';

    /**
     * Block name (without the `fluent-cart-bulk-order/` prefix) => shortcode tag.
     *
     * The directory under blocks/ is named after the key, so this map is also
     * the list of metadata folders to register.
     */
    const BLOCKS = [
        'bulk-order-form' => 'fluent_cart_bulk_order',
        'product-table'   => 'fluent_cart_product_table',
    ];

    /**
     * Register both blocks. Hooked to `init` from the main plugin file, inside
     * the FluentCart-is-active guard: without the host plugin the shortcode tags
     * are not registered either, and a block that renders nothing is worse than
     * a block that is not offered.
     *
     * @return void
     */
    public static function register()
    {
        self::registerEditorScript();

        foreach (self::BLOCKS as $name => $tag) {
            $dir = FCBO_DIR . 'blocks/' . $name;

            self::assertMetadataMatchesSchema($dir, $tag);

            register_block_type($dir, [
                // block.json cannot carry a PHP callable, so the one thing that
                // makes these dynamic blocks is passed here.
                'render_callback' => function ($attributes) use ($tag) {
                    return self::render($tag, $attributes);
                },
            ]);
        }
    }

    /**
     * Render one block through its shortcode.
     *
     * @param string               $tag        Shortcode tag from self::BLOCKS.
     * @param array<string, mixed> $attributes Block attributes, already merged
     *                                         with the block.json defaults by
     *                                         WP_Block_Type::prepare_attributes_for_render().
     * @return string
     */
    public static function render($tag, $attributes)
    {
        // Required rather than assumed: a block can be rendered from a REST
        // request or a template part on a page where nothing else pulled the
        // shortcode layer in yet. require_once costs one included-file check.
        require_once FCBO_DIR . 'includes/Shortcodes/ShortcodeHandler.php';

        return ShortcodeHandler::renderTag(
            $tag,
            AttributeSchema::toShortcodeAtts($tag, (array) $attributes)
        );
    }

    /**
     * Register the shared editor script and its translations.
     *
     * @return void
     */
    private static function registerEditorScript()
    {
        wp_register_script(
            self::EDITOR_HANDLE,
            FCBO_URL . 'assets/js/blocks-editor.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            FCBO_VERSION,
            true
        );

        // No path argument on purpose. The plugin ships no languages/ directory
        // and is headed for WordPress.org, whose translate.wordpress.org builds
        // land in WP_LANG_DIR/plugins — which is exactly where this looks when
        // no path is given.
        wp_set_script_translations(self::EDITOR_HANDLE, 'fluent-cart-bulk-order');
    }

    /**
     * Fail loudly, in development only, when a block.json stops matching
     * AttributeSchema.
     *
     * The schema is the single source of truth for which attributes exist, but
     * block.json is static JSON and cannot read it. That leaves one way for the
     * two to drift: someone adds a control to the schema and forgets the JSON,
     * and the block silently drops that attribute on save because WordPress only
     * persists attributes the block type declares. It is a quiet failure with no
     * error anywhere, so this turns it into a visible notice for the developer
     * who caused it.
     *
     * Debug builds only — a live store gains nothing from the notice, and
     * re-reading two JSON files on every `init` is not something to spend a
     * production request on.
     *
     * @param string $dir Block metadata directory.
     * @param string $tag Shortcode tag the block wraps.
     * @return void
     */
    private static function assertMetadataMatchesSchema($dir, $tag)
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $metadata = json_decode(file_get_contents($dir . '/block.json'), true);
        $declared = isset($metadata['attributes']) ? array_keys((array) $metadata['attributes']) : [];
        $expected = array_keys(AttributeSchema::controls($tag));

        sort($declared);
        sort($expected);

        if ($declared === $expected) {
            return;
        }

        _doing_it_wrong(
            __METHOD__,
            sprintf(
                /* translators: 1: block.json path, 2: shortcode tag. */
                esc_html__('%1$s declares different attributes than AttributeSchema does for %2$s. The two must list the same names or the block will drop settings on save.', 'fluent-cart-bulk-order'),
                esc_html($dir . '/block.json'),
                esc_html($tag)
            ),
            'FCBO'
        );
    }
}
