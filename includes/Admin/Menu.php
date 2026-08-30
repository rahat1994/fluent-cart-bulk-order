<?php

namespace FluentCartBulkOrder\Admin;

defined('ABSPATH') || exit;

/**
 * The plugin's one top-level admin menu, and the single place that decides
 * where each of its five screens hangs.
 *
 * ---------------------------------------------------------------------------
 * WHY THE PARENT SLUG IS THE SETTINGS SLUG
 * ---------------------------------------------------------------------------
 *
 * A top-level menu IS a page: `add_menu_page()` registers `admin.php?page=` its
 * own slug, and that is where WordPress sends anyone who clicks the parent. By
 * making the parent slug the settings slug, the settings screen keeps the exact
 * slug it has always had (`fcbo-settings`) and becomes the menu's landing page
 * at the same time, so a bookmark saved when the screen lived under Settings
 * still resolves.
 *
 * It also solves the duplicate-entry problem for free. WordPress inserts a copy
 * of the parent as the first submenu item, but only when the first
 * `add_submenu_page()` for that parent uses a DIFFERENT slug:
 *
 *     if ( ! isset( $submenu[ $parent_slug ] ) && $menu_slug !== $parent_slug )
 *
 * So the settings screen registers itself with the parent's own slug, first,
 * and there is no doubled "Bulk Order" row. Registering it anywhere but first
 * brings the duplicate straight back.
 *
 * ---------------------------------------------------------------------------
 * WHY PRIORITIES AND NOT ONE REGISTRAR
 * ---------------------------------------------------------------------------
 *
 * Submenu order is registration order, and the five screens register from five
 * different classes, each hooked on `admin_menu` from its own flow class behind
 * its own guard. Calling them all from one registrar here would mean this class
 * repeating every one of those guards, and going stale when one changes.
 *
 * So each screen keeps its own registration and takes its POSITION from
 * SUBMENU_PRIORITIES below. The `admin_menu` priority is then the whole
 * ordering rule, and it does not care which order the flows happen to load in.
 *
 * ---------------------------------------------------------------------------
 * THE COUNT BUBBLES
 * ---------------------------------------------------------------------------
 *
 * Quotes and wholesale applications each show a pending count on their own
 * submenu row, as they did before this menu existed. That is no longer enough
 * on its own: submenus are hidden until the parent is hovered or current, so a
 * count only on a submenu is a count nobody sees. The parent therefore carries
 * the SUM, added at BUBBLE_PRIORITY once every screen has reported its number.
 *
 * The screens report what they already counted (@see countPending()), so the
 * parent bubble costs no extra query. The counts themselves are cached — see
 * cachedCount(), which exists because `admin_menu` fires on every single
 * wp-admin request.
 */
class Menu
{
    /*
     * Every screen's slug, repeated here as a literal.
     *
     * NOT aliased to each screen's own PAGE_SLUG, and that is the point: a flow
     * has to know its screen's menu POSITION on `plugins_loaded`, long before
     * `admin_menu` fires, and reading `QuoteReviewScreen::PAGE_SLUG` there
     * would load an admin-only class into every request the plugin runs in —
     * exactly the lazy loading each flow class exists to preserve.
     *
     * The screens remain the source of truth for their own slug. AdminMenuTest
     * asserts each constant below equals it, so the two cannot drift apart
     * silently.
     */

    /**
     * The top-level menu slug, which is also the settings screen's slug.
     */
    const PARENT_SLUG = 'fcbo-settings';

    /**
     * @see \FluentCartBulkOrder\Quotes\QuoteReviewScreen::PAGE_SLUG
     */
    const SLUG_QUOTES = 'fcbo-quotes';

    /**
     * @see \FluentCartBulkOrder\Wholesale\ReviewScreen::PAGE_SLUG
     */
    const SLUG_WHOLESALE = 'fcbo-wholesale-applications';

    /**
     * @see \FluentCartBulkOrder\Analytics\AnalyticsScreen::PAGE_SLUG
     */
    const SLUG_ANALYTICS = 'fcbo-analytics';

    /**
     * @see \FluentCartBulkOrder\Export\OrderExportScreen::PAGE_SLUG
     */
    const SLUG_EXPORTS = 'fcbo-order-exports';

    /**
     * Capability for the menu ENTRY. Every screen re-checks its own on render;
     * this one only decides who sees the row.
     */
    const CAPABILITY = 'manage_options';

    /**
     * Dashicon for the parent row.
     */
    const ICON = 'dashicons-cart';

    /**
     * Default sidebar position: directly under FluentCart, which sits at 3.
     *
     * A string decimal rather than an integer because whole numbers collide
     * with core's own menus and with other plugins. The plugin cannot function
     * without FluentCart, so beside its host is where an owner will look for
     * it; a store that has moved FluentCart's own menu can move this one with
     * the filter in addMenuPage().
     */
    const POSITION = '3.9';

    /**
     * `admin_menu` priority for the parent.
     *
     * Lower than every submenu priority, because `add_submenu_page()` needs the
     * parent to exist before it can attach anything to it.
     */
    const PARENT_PRIORITY = 9;

    /**
     * `admin_menu` priority per screen slug — this list IS the submenu order.
     *
     * Settings first because it is the parent's own slug and must register
     * before anything else (@see the class docblock). The rest run
     * highest-traffic first: the two screens that hold work waiting on the
     * owner, then the two read-only reports.
     */
    const SUBMENU_PRIORITIES = [
        self::PARENT_SLUG    => 10,
        self::SLUG_QUOTES    => 11,
        self::SLUG_WHOLESALE => 12,
        self::SLUG_ANALYTICS => 13,
        self::SLUG_EXPORTS   => 14,
    ];

    /**
     * `admin_menu` priority at which the parent's bubble is written.
     *
     * After every submenu priority above, because the number it prints is the
     * sum of what those registrations reported.
     */
    const BUBBLE_PRIORITY = 99;

    /**
     * Transient prefix for a cached menu count.
     */
    const COUNT_TRANSIENT_PREFIX = 'fcbo_menu_count_';

    /**
     * Cache key for the open-quote count.
     */
    const COUNT_QUOTES = 'quotes';

    /**
     * Cache key for the pending-application count.
     */
    const COUNT_APPLICATIONS = 'applications';

    /**
     * How long a cached menu count is trusted, in seconds.
     *
     * Short, because it is only a backstop: every event that changes one of
     * these numbers flushes the cache outright (@see register()). The TTL
     * covers the paths that do not fire one — a quote deleted straight from the
     * posts list, a user removed by another plugin.
     */
    const COUNT_TTL = 300;

    /**
     * Pending items reported by the screens during this request.
     *
     * @var int
     */
    private static $pending = 0;

    /**
     * Hook the top-level menu and the cache invalidation.
     *
     * @return void
     */
    public static function register()
    {
        add_action('admin_menu', [self::class, 'addMenuPage'], self::PARENT_PRIORITY);
        add_action('admin_menu', [self::class, 'addParentBubble'], self::BUBBLE_PRIORITY);

        // Every event that can change one of the two counts. Registered
        // outside `is_admin()` on purpose: a shopper requests a quote or sends
        // an application on the FRONT end, and that is exactly the moment the
        // owner's cached number stops being true.
        add_action('fcbo/quotes/requested', [self::class, 'flushCounts']);
        add_action('fcbo/quotes/decided', [self::class, 'flushCounts']);
        add_action('fcbo/wholesale/application_submitted', [self::class, 'flushCounts']);
        add_action('fcbo/wholesale/application_reviewed', [self::class, 'flushCounts']);
    }

    /**
     * Register the top-level menu row.
     *
     * The page callback is deliberately empty: the settings screen registers
     * itself as a submenu under this same slug and brings the callback with it.
     * Passing one here too would hang two callbacks on one hook and print the
     * settings form twice.
     *
     * @return void
     */
    public static function addMenuPage()
    {
        add_menu_page(
            __('Fluent Cart Bulk Order', 'fluent-cart-bulk-order'),
            __('Bulk Order', 'fluent-cart-bulk-order'),
            self::CAPABILITY,
            self::PARENT_SLUG,
            '',
            self::ICON,
            /**
             * Where the Bulk Order menu sits in the admin sidebar.
             *
             * @param string $position A numeric string. @see Menu::POSITION.
             */
            apply_filters('fcbo/admin_menu_position', self::POSITION)
        );
    }

    /**
     * The `admin_menu` priority a screen must hook at to land in the right slot.
     *
     * @param string $slug A screen's PAGE_SLUG.
     * @return int
     */
    public static function priority($slug)
    {
        // An unlisted screen goes last rather than first. A future screen that
        // forgets to add itself here appears at the bottom of the menu, which
        // is wrong but visible; landing above Settings would put the duplicate
        // parent row back and look like a WordPress bug.
        return isset(self::SUBMENU_PRIORITIES[$slug])
            ? self::SUBMENU_PRIORITIES[$slug]
            : self::BUBBLE_PRIORITY - 1;
    }

    /**
     * The five screen slugs, in the order they appear in the menu.
     *
     * @return string[]
     */
    public static function order()
    {
        $priorities = self::SUBMENU_PRIORITIES;
        asort($priorities);

        return array_keys($priorities);
    }

    /**
     * Base URL every screen of this menu hangs off.
     *
     * Named here so the ~dozen call sites that build a link or a redirect do
     * not each hardcode a parent file. When the menu moved out of
     * `options-general.php` and `users.php`, every one of them was a stale
     * redirect waiting to happen.
     *
     * @return string
     */
    public static function baseUrl()
    {
        return admin_url('admin.php');
    }

    /**
     * The admin URL of one screen.
     *
     * @param string $slug A screen's PAGE_SLUG.
     * @return string
     */
    public static function url($slug)
    {
        return add_query_arg('page', $slug, self::baseUrl());
    }

    /**
     * A menu title with WordPress's own pending-count bubble appended.
     *
     * The markup is core's — the same `awaiting-mod` span the Comments menu
     * uses — so it inherits the admin colour scheme instead of carrying CSS of
     * its own.
     *
     * @param string $title
     * @param int    $count Zero returns the plain title.
     * @return string
     */
    public static function bubbleTitle($title, $count)
    {
        $count = (int) $count;

        if ($count < 1) {
            return $title;
        }

        return $title
            . ' <span class="awaiting-mod"><span class="pending-count">'
            . $count
            . '</span></span>';
    }

    /**
     * Report pending items so the parent row can show the total.
     *
     * Called by a screen with the number it has already counted for its own
     * bubble, which is what keeps the parent bubble free of extra queries.
     *
     * @param int $count
     * @return void
     */
    public static function countPending($count)
    {
        self::$pending += max(0, (int) $count);
    }

    /**
     * Write the summed bubble onto the parent row.
     *
     * The `$menu` global is the only way in: `add_menu_page()` has already run
     * by the time the counts are known, and WordPress offers no API to retitle
     * a registered menu.
     *
     * @return void
     */
    public static function addParentBubble()
    {
        if (self::$pending < 1) {
            return;
        }

        global $menu;

        if (!is_array($menu)) {
            return;
        }

        foreach ($menu as $index => $item) {
            if (!isset($item[0], $item[2]) || $item[2] !== self::PARENT_SLUG) {
                continue;
            }

            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- retitling one already-registered row, not replacing the menu. @see the docblock.
            $menu[$index][0] = self::bubbleTitle($item[0], self::$pending);

            return;
        }
    }

    /**
     * A menu count, cached across requests.
     *
     * `admin_menu` fires on EVERY wp-admin request, so an uncached count here
     * is a query on every page an owner opens — a WP_Query with a meta lookup
     * for quotes, and a WP_User_Query with count_total (two real queries on a
     * site with no persistent object cache) for applications. Neither number
     * changes often, and both are flushed the moment one does.
     *
     * @param string   $key     Short identifier, appended to the transient name.
     * @param callable $counter Runs only on a miss; must return an int.
     * @return int
     */
    public static function cachedCount($key, $counter)
    {
        $name   = self::COUNT_TRANSIENT_PREFIX . $key;
        $cached = get_transient($name);

        // A stored 0 is a legitimate answer and must not re-run the query;
        // get_transient() returns false, not null, when there is nothing there.
        if ($cached !== false) {
            return (int) $cached;
        }

        $count = (int) call_user_func($counter);

        set_transient($name, $count, self::COUNT_TTL);

        return $count;
    }

    /**
     * Drop every cached menu count.
     *
     * Both, not the one that changed. Telling them apart would mean a callback
     * per event for the sake of saving one cheap query on an action an owner
     * takes a handful of times a day.
     *
     * @return void
     */
    public static function flushCounts()
    {
        foreach ([self::COUNT_QUOTES, self::COUNT_APPLICATIONS] as $key) {
            delete_transient(self::COUNT_TRANSIENT_PREFIX . $key);
        }
    }
}
