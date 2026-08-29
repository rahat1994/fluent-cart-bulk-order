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
 * ---------------------------------------------------------------------------
 * WHEN FLUENTCART IS NOT THERE
 * ---------------------------------------------------------------------------
 *
 * The tags are registered anyway. An unregistered shortcode is not silent —
 * WordPress leaves its literal `[fluent_cart_bulk_order]` text in the post
 * content — so declining to register would put raw tag text in front of every
 * shopper on the page. That is the loudest possible failure aimed at exactly
 * the person who cannot act on it.
 *
 * So register() claims the tags unconditionally and renderTag() decides what a
 * tag without its host is allowed to show. The one thing that buys must be
 * protected: registration may not touch FluentCart, and it does not — the
 * per-tag classes load inside renderTag(), never in register().
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
     * Called from the `plugins_loaded` handler in the main plugin file BEFORE
     * the FluentCart-is-active check, and that order is load-bearing. Moving
     * this call back below the guard hands raw `[fluent_cart_bulk_order]` text
     * to shoppers on any page holding one of our tags the moment the host
     * plugin is deactivated. @see the class docblock for the full argument.
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
     * @return string Markup; '' for an unknown tag, and the hostMissingNotice()
     *                answer when FluentCart is not loaded.
     */
    public static function renderTag($tag, $atts = [])
    {
        $tag = (string) $tag;

        // Unknown tag: nothing sensible to render, and a visible error would be
        // worse than silence on a public storefront page. This check has to
        // stay ahead of the host check below, so a tag this plugin does not own
        // can never produce an FCBO message.
        if (!isset(self::SHORTCODES[$tag])) {
            return '';
        }

        // FluentCart is not loaded. Tested HERE rather than in register()
        // because render time is the only moment the answer is worth acting
        // on: registration runs early on `plugins_loaded`, and deciding there
        // would leave the tags permanently dead for the rest of a request in
        // which the host became available late.
        //
        // FLUENTCART_VERSION is the same constant the bootstrap guard in
        // fluent-cart-bulk-order.php tests. Keep the two in step — a render
        // that disagreed with the bootstrap would reach make() with none of the
        // FluentCart classes the surfaces call.
        if (!defined('FLUENTCART_VERSION')) {
            return self::hostMissingNotice();
        }

        $shortcode = self::make($tag);

        // The tag is ours and the host is here, so getting nothing back means
        // a broken install — a missing or unparsable class file. Nothing a
        // shopper could be told about it would help them.
        if (!$shortcode) {
            return '';
        }

        return $shortcode->render($atts);
    }

    /**
     * What one of our tags renders when FluentCart is missing.
     *
     * Two audiences, two answers, and the split is the point of the method.
     *
     * A shopper gets the empty string. Without the host plugin there is no
     * catalog, no cart and no price to show them, and a storefront page is not
     * where a site's plugin problems get reported — an empty region reads as
     * "nothing here", which is true, while any message reads as an error the
     * shopper caused.
     *
     * A user who can `manage_options` gets one sentence, because they are the
     * only person who can act on it and a silently empty page gives them
     * nothing to search for. It says who it is for, so an owner previewing the
     * page does not file it as something shoppers are seeing too.
     *
     * Deliberately NOT an admin_notices call — the bootstrap already prints one
     * of those on every admin screen. This one is in place, on the page, which
     * is the piece of information the admin notice cannot carry: WHERE the
     * broken surface is.
     *
     * @return string Escaped markup, or '' for everyone who is not an admin.
     */
    private static function hostMissingNotice()
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        return '<p class="fcbo-host-missing">' . esc_html__(
            'Fluent Cart Bulk Order needs the FluentCart plugin installed and active to show this content. Only site administrators can see this message.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Build the handler object for a tag.
     *
     * Takes the tag on trust: renderTag() is the only caller and has already
     * rejected anything outside SHORTCODES. Re-checking here would have to be
     * kept in step with a check that runs two lines earlier, for no gain.
     *
     * @param string $tag A key of self::SHORTCODES, already validated.
     * @return \FluentCartBulkOrder\Shortcodes\AbstractShortcode|null
     */
    private static function make($tag)
    {
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
