<?php

namespace FluentCartBulkOrder\Shortcodes;

defined('ABSPATH') || exit;

/**
 * The one place every FCBO shortcode is registered and dispatched.
 *
 * ---------------------------------------------------------------------------
 * WHY A REGISTRY AND NOT THREE add_shortcode() CALLS
 * ---------------------------------------------------------------------------
 *
 * SHORTCODES below is the single source of truth for "which tags does this
 * plugin own, and what renders them". Registration, the legacy `fcbo_render_*`
 * delegates, and any future tooling that needs to enumerate our tags all read
 * that one map, so a new shortcode is one line here plus one class file.
 *
 * ---------------------------------------------------------------------------
 * LOADING
 * ---------------------------------------------------------------------------
 *
 * The shortcode classes carry a lot of markup and most page loads contain no
 * FCBO shortcode at all, so a class file is required only when its tag actually
 * renders. Registration itself costs nothing beyond this file.
 *
 * The plugin has no autoloader on purpose (see the require_once calls in
 * fluent-cart-bulk-order.php), which is why load() resolves a file path from the
 * class name. That is the ONE convention this class depends on: every shortcode
 * class is a sibling file in this directory named exactly after the class.
 *
 * @see \FluentCartBulkOrder\Shortcodes\AbstractShortcode The shared render flow.
 */
class ShortcodeHandler
{
    /**
     * Shortcode tag => class name in this namespace.
     *
     * The tag lives here and nowhere else. Instances receive their tag through
     * the constructor rather than declaring it themselves, so the two can never
     * disagree — which matters because the tag is what names the
     * `shortcode_atts_{$tag}` filter a site may be hooking.
     */
    const SHORTCODES = [
        'fluent_cart_bulk_order'    => 'BulkOrderForm',
        'fluent_cart_product_table' => 'ProductTable',
        'fluent_cart_saved_orders'  => 'SavedOrders',

        // The odd one out: this is the only tag whose audience is users WITHOUT
        // wholesale access, so it opts out of Gate 1. It is still registered
        // here, and still renders through AbstractShortcode, so it cannot skip
        // the logged-in check by accident.
        // @see \FluentCartBulkOrder\Shortcodes\WholesaleApplication
        'fluent_cart_wholesale_application' => 'WholesaleApplication',
    ];

    /**
     * Register every shortcode with WordPress.
     *
     * Called from the `plugins_loaded` handler in the main plugin file, after
     * the FluentCart-is-active check — these surfaces are useless without the
     * host plugin, so the tags are deliberately absent rather than rendering an
     * error when it is missing. An unregistered shortcode leaves its literal
     * `[fluent_cart_bulk_order]` text in the page, which is the clearest signal
     * a site owner can get.
     *
     * @return void
     */
    public function register()
    {
        foreach (array_keys(self::SHORTCODES) as $tag) {
            // One callback for all three tags: WordPress passes the tag it
            // matched as the third argument to a shortcode callback
            // (wp-includes/shortcodes.php:434), so dispatch needs no closure per
            // tag and the callback stays a plain, inspectable array callable.
            add_shortcode($tag, [self::class, 'renderShortcode']);
        }
    }

    /**
     * add_shortcode() callback for every registered tag.
     *
     * @param array<string, mixed>|string $atts    Raw attributes from WordPress.
     * @param string|null                 $content Enclosed content; unused, all
     *                                             FCBO shortcodes are self-closing.
     * @param string                      $tag     The matched tag.
     * @return string
     */
    public static function renderShortcode($atts = [], $content = null, $tag = '')
    {
        return self::renderTag($tag, $atts);
    }

    /**
     * Render one tag by name.
     *
     * Also the seam the `fcbo_render_*` functions in the main plugin file call,
     * which is why it is public and takes the tag explicitly.
     *
     * @param string                      $tag  A key of self::SHORTCODES.
     * @param array<string, mixed>|string $atts Raw attributes.
     * @return string Markup, or '' for an unknown tag.
     */
    public static function renderTag($tag, $atts = [])
    {
        $shortcode = self::make($tag);

        // Unknown tag: nothing sensible to render, and a visible error would be
        // worse than silence on a public storefront page.
        if (!$shortcode) {
            return '';
        }

        return $shortcode->render($atts);
    }

    /**
     * Build the handler object for a tag.
     *
     * @param string $tag A key of self::SHORTCODES.
     * @return \FluentCartBulkOrder\Shortcodes\AbstractShortcode|null
     */
    private static function make($tag)
    {
        $tag = (string) $tag;

        if (!isset(self::SHORTCODES[$tag])) {
            return null;
        }

        $class = self::load(self::SHORTCODES[$tag]);

        return $class ? new $class($tag) : null;
    }

    /**
     * Load a shortcode class file and return its fully qualified name.
     *
     * @param string $name Class name, which is also its file name.
     * @return string|null FQCN, or null when the file or class is missing.
     */
    private static function load($name)
    {
        $fqcn = __NAMESPACE__ . '\\' . $name;

        if (class_exists($fqcn, false)) {
            return $fqcn;
        }

        $file = __DIR__ . '/' . $name . '.php';

        if (!file_exists($file)) {
            return null;
        }

        // The base class is not autoloaded either, and the subclass file cannot
        // be parsed without it.
        require_once __DIR__ . '/AbstractShortcode.php';
        require_once $file;

        return class_exists($fqcn, false) ? $fqcn : null;
    }
}
