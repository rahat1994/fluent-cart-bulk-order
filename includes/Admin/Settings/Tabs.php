<?php

namespace FluentCartBulkOrder\Admin\Settings;

use FluentCartBulkOrder\Admin\Menu;
use FluentCartBulkOrder\Settings;

defined('ABSPATH') || exit;

/**
 * The tab list, and the three names every tab needs derived from its slug.
 *
 * ---------------------------------------------------------------------------
 * ONE OPTION GROUP PER TAB, AND WHY IT IS NOT OPTIONAL
 * ---------------------------------------------------------------------------
 *
 * wp-admin/options.php does NOT skip an option that was not posted. It walks
 * every option registered into the submitted group and writes each one:
 *
 *     $value = null;
 *     if ( isset( $_POST[ $option ] ) ) { $value = ...; }
 *     update_option( $option, $value );
 *
 * So with one group for the whole page, opening the Quotes tab and pressing
 * Save would hand `null` to the bulk-pricing role sanitizer, which reads a
 * non-array as "no roles" and empties a policy the owner set on another tab.
 * Nothing would say so; the page would report "Settings saved".
 *
 * Giving each tab its own group is the fix, and it is the group argument's
 * actual job: options.php only ever iterates the group that was posted, so an
 * option belonging to another tab is not merely left alone, it is never
 * reached. That handles the top-level options.
 *
 * It cannot handle the store-defaults option, because four tabs share that one
 * option and post partial copies of it. That half is solved in the sanitizer.
 * @see \FluentCartBulkOrder\StoreDefaults::SUBMITTED_KEYS
 *
 * ---------------------------------------------------------------------------
 * THE OLD `fcbo_settings` GROUP IS DELIBERATELY GONE
 * ---------------------------------------------------------------------------
 *
 * It is not re-registered as an alias. A browser tab left open across the
 * upgrade would post it, and an alias would accept that stale submission —
 * every option in it, every tab at once, with only one tab's fields present.
 * Unregistered, options.php refuses it with its own "not in the allowed options
 * list" error, which is a wasted click instead of a wiped configuration.
 */
class Tabs
{
    /**
     * The query argument that chooses a tab.
     */
    const QUERY_ARG = 'tab';

    /**
     * Who gets bulk pricing, and the order-total floor.
     */
    const PRICING = 'pricing';

    /**
     * Who may open the surfaces, and how they ask to be let in.
     */
    const ACCESS = 'access';

    /**
     * How the surfaces themselves are drawn.
     */
    const SURFACES = 'surfaces';

    /**
     * What happens between the surface and the payment.
     */
    const CHECKOUT = 'checkout';

    /**
     * Asking for a price instead of buying.
     */
    const QUOTES = 'quotes';

    /**
     * Every tag the plugin registers, and a page to put one on.
     */
    const SHORTCODES = 'shortcodes';

    /**
     * Slug => class, in the order the tabs are drawn.
     *
     * The FIRST entry is the default tab: it is what an owner lands on from the
     * menu, and what an unknown `?tab=` falls back to. Pricing holds that spot
     * because it is the one thing a store cannot use this plugin without
     * setting.
     *
     * Shortcodes is LAST, and it is the one tab that stores nothing: it is a
     * reference an owner reads once while placing the surfaces, not a setting
     * they come back to. @see ShortcodesTab, and Tab::isForm() for what a tab
     * with nothing to save does differently.
     */
    const CLASSES = [
        self::PRICING    => PricingTab::class,
        self::ACCESS     => AccessTab::class,
        self::SURFACES   => SurfacesTab::class,
        self::CHECKOUT   => CheckoutTab::class,
        self::QUOTES     => QuotesTab::class,
        self::SHORTCODES => ShortcodesTab::class,
    ];

    /**
     * Instantiated tabs, built once per request.
     *
     * @var array<string, Tab>|null
     */
    private static $tabs = null;

    /**
     * Every tab, keyed by slug, in display order.
     *
     * The tab files are required here rather than by the plugin bootstrap.
     * Settings.php is loaded on EVERY page load — AccessPolicy reads its page
     * slug — while these tab classes are only ever needed on `admin_init` and
     * on one admin screen.
     *
     * @return array<string, Tab>
     */
    public static function all()
    {
        if (self::$tabs !== null) {
            return self::$tabs;
        }

        require_once __DIR__ . '/Tab.php';

        self::$tabs = [];

        foreach (self::CLASSES as $slug => $class) {
            require_once __DIR__ . '/' . self::fileName($class) . '.php';

            self::$tabs[$slug] = new $class();
        }

        return self::$tabs;
    }

    /**
     * The class name without its namespace, which is also its file name.
     *
     * @param string $class Fully qualified class name.
     * @return string
     */
    private static function fileName($class)
    {
        $parts = explode('\\', $class);

        return (string) end($parts);
    }

    /**
     * One tab, or null.
     *
     * @param string $slug
     * @return Tab|null
     */
    public static function get($slug)
    {
        $tabs = self::all();

        return isset($tabs[$slug]) ? $tabs[$slug] : null;
    }

    /**
     * The slug of the tab a request is asking for.
     *
     * Anything unrecognised becomes the default rather than an error. A `?tab=`
     * value comes from a URL, so it can be a typo, a stale bookmark, a tab that
     * a later version removed, or an attempt at something worse — and every one
     * of those should show the owner a working page. Rendering an empty page
     * for an unknown tab is the failure this guards.
     *
     * @param mixed $requested Raw value from the query string.
     * @return string A slug that is guaranteed to exist.
     */
    public static function current($requested)
    {
        $requested = is_scalar($requested) ? sanitize_key((string) $requested) : '';
        $tabs      = self::all();

        return isset($tabs[$requested]) ? $requested : self::defaultSlug();
    }

    /**
     * The tab shown when none was asked for.
     *
     * @return string
     */
    public static function defaultSlug()
    {
        $slugs = array_keys(self::CLASSES);

        return (string) reset($slugs);
    }

    /**
     * The option group one tab's form posts to.
     *
     * @param string $slug
     * @return string
     */
    public static function group($slug)
    {
        return Settings::OPTION_GROUP . '_' . $slug;
    }

    /**
     * The Settings API page bucket one tab's sections are registered against.
     *
     * A bucket key, not a URL: `add_settings_section()` takes an arbitrary
     * string and `do_settings_sections()` asks for the same one back. The page
     * an owner actually visits is still `fcbo-settings`, unchanged, which is
     * what keeps every existing bookmark and every emailed link working.
     *
     * @param string $slug
     * @return string
     */
    public static function sectionPage($slug)
    {
        return Settings::PAGE_SLUG . '-' . $slug;
    }

    /**
     * The admin URL of one tab.
     *
     * @param string $slug
     * @return string
     */
    public static function url($slug)
    {
        return add_query_arg(self::QUERY_ARG, $slug, Menu::url(Settings::PAGE_SLUG));
    }
}
