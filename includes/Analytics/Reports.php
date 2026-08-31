<?php

namespace FluentCartBulkOrder\Analytics;

use FluentCartBulkOrder\AccessPolicy;

defined('ABSPATH') || exit;

/**
 * Every figure the analytics screen prints, aggregated in SQL.
 *
 * ---------------------------------------------------------------------------
 * NOTHING IS SUMMED IN PHP
 * ---------------------------------------------------------------------------
 *
 * Every method here returns numbers that MySQL computed. Not one of them loads
 * a list of orders, and that is the whole design constraint: on a store with
 * 100,000 orders, the difference between `SUM()` and a foreach is the
 * difference between a screen and a timeout. Where a list IS returned — the top
 * customers — it is a fixed twenty rows chosen by the database, not a filtered
 * subset of everything.
 *
 * ---------------------------------------------------------------------------
 * WHY THESE NUMBERS MATCH FLUENTCART'S OWN REPORTS, ON PURPOSE
 * ---------------------------------------------------------------------------
 *
 * An owner comparing this screen against FluentCart's Reports and finding two
 * different revenue figures has learned nothing except that one of them is
 * broken. So both choices below are copied from the host rather than decided
 * here:
 *
 *   WHICH ORDERS COUNT — `payment_status IN (paid, refunded, partially_paid,
 *       partially_refunded)`. That is Status::getReportStatuses() (fluent-cart
 *       1.5.5, app/Helpers/Status.php:339), which ReportHelper forces onto
 *       every core report (app/Services/Report/ReportHelper.php:145). Read from
 *       the host class when it is loadable, so an upgrade that changes the set
 *       moves this screen with it.
 *
 *   WHAT REVENUE MEANS — `SUM(total_paid)`, which is what FluentCart's own
 *       overview calls gross revenue
 *       (app/Http/Controllers/Reports/OverviewReportController.php:60).
 *       Deliberately NOT `total_amount`: that is what the order was billed,
 *       and an unpaid order billed for £4,000 is not £4,000 of revenue.
 *
 *   WHICH DATE — `created_at`, again matching the host's own reports, and
 *       stored in SITE local time. @see \FluentCartBulkOrder\Analytics\Period.
 *
 * Test-mode orders are NOT excluded, because the host's reports do not exclude
 * them either. An owner who has been testing sees the same inflated figure in
 * both places, which is a question they can answer, rather than two figures
 * that disagree, which is not.
 *
 * ---------------------------------------------------------------------------
 * CACHING, AND WHY IT DOES NOT INVALIDATE ON WRITE
 * ---------------------------------------------------------------------------
 *
 * Each period's answer is cached for fifteen minutes. Flushing that on every
 * new order would be the intuitive thing and the wrong one: the stores this
 * protects are the busy ones, and a busy store would flush the cache faster
 * than anybody could read it, leaving every admin page load paying the full
 * aggregate. Fifteen minutes stale is not a problem for a question about the
 * last ninety days, and the screen says so out loud.
 */
class Reports
{
    /**
     * Transient key prefix. The period is appended.
     */
    const CACHE_PREFIX = 'fcbo_analytics_';

    /**
     * How long a computed report is kept.
     */
    const CACHE_TTL = 900;

    /**
     * How many customers the top-spenders panel shows.
     *
     * A fixed, small number chosen by the database with `ORDER BY … LIMIT`, so
     * the size of this list does not grow with the size of the store.
     */
    const TOP_CUSTOMERS = 20;

    /**
     * How many distinct tiers the utilization panel will aggregate.
     *
     * Far beyond any real feed configuration; it exists so that a store which
     * has somehow accumulated thousands of distinct tier definitions renders a
     * long table instead of an out-of-memory error.
     */
    const MAX_TIERS = 200;

    /**
     * How many product-level Integration Feeds the "configured tiers" read
     * will open.
     *
     * A store can legitimately put a pricing feed on every product, so this
     * read is bounded and reports when it hit the bound — an owner told a tier
     * is unused because its feed was never opened would be told something
     * false.
     */
    const MAX_PRODUCT_FEEDS = 300;

    /**
     * The whole screen's data for one period, computed once and cached.
     *
     * One method rather than four the screen calls separately, so the four
     * figures are always measured over the same window at the same moment and
     * cannot be cached against each other.
     *
     * @param string $period One of Period::keys().
     * @return array<string, mixed>
     */
    public static function forPeriod($period)
    {
        $period = Period::sanitize($period);
        $key = self::CACHE_PREFIX . $period;

        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached;
        }

        $since = Period::since($period, (int) current_time('timestamp'));

        $all = self::revenue($since, false);
        $bulk = self::revenue($since, true);

        $data = [
            'period'     => $period,
            'generated'  => (int) current_time('timestamp'),
            'split'      => RevenueSplit::build($all, $bulk),
            'by_source'  => RevenueSplit::bySource(self::revenueBySource($since), $bulk['revenue']),
            'customers'  => self::topWholesaleCustomers($since),
            'tier_hits'  => self::tierHits($since),
        ];

        set_transient($key, $data, self::CACHE_TTL);

        return $data;
    }

    /**
     * Order count and revenue over a window — for the whole store, or only for
     * the orders this plugin attributed.
     *
     * The bulk half is an `EXISTS` sub-select and NOT a join. A join against a
     * table with one row per LINE would multiply `total_paid` by the number of
     * lines in the order, which is the classic way a revenue report comes out
     * four times too big and looks plausible.
     *
     * @param string|null $since MySQL datetime, or null for all time.
     * @param bool        $bulkOnly
     * @return array{orders:int, revenue:int}
     */
    private static function revenue($since, $bulkOnly)
    {
        global $wpdb;

        if ($bulkOnly && !AttributionStore::exists()) {
            return ['orders' => 0, 'revenue' => 0];
        }

        $orders = $wpdb->prefix . 'fct_orders';
        $attribution = AttributionStore::table();

        $where = ['o.payment_status IN (' . self::statusPlaceholders() . ')'];
        $args = self::reportStatuses();

        if ($since !== null) {
            $where[] = 'o.created_at >= %s';
            $args[] = $since;
        }

        if ($bulkOnly) {
            $where[] = "EXISTS (SELECT 1 FROM {$attribution} a WHERE a.order_id = o.id)";
        }

        $sql = "SELECT COUNT(*) AS orders, COALESCE(SUM(o.total_paid), 0) AS revenue
                FROM {$orders} o
                WHERE " . implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from $wpdb->prefix and class constants; every value is a %s bound on the next line, and the caller caches the result.
        $row = $wpdb->get_row($wpdb->prepare($sql, $args), ARRAY_A);

        return [
            'orders'  => (int) (isset($row['orders']) ? $row['orders'] : 0),
            'revenue' => (int) (isset($row['revenue']) ? $row['revenue'] : 0),
        ];
    }

    /**
     * Attributed revenue, broken down by the entry point it came from.
     *
     * The inner `GROUP BY order_id, source` collapses an order's lines back to
     * one row before the outer query touches `total_paid`, which is what stops
     * a five-line order counting its own revenue five times. Every line of one
     * order carries the same source — OrderAttribution writes them in a single
     * insert with one value — so that inner grouping yields exactly one row per
     * order and never splits one order across two entry points.
     *
     * @param string|null $since
     * @return array<int, array{source:string, orders:int, revenue:int}>
     */
    private static function revenueBySource($since)
    {
        global $wpdb;

        if (!AttributionStore::exists()) {
            return [];
        }

        $orders = $wpdb->prefix . 'fct_orders';
        $attribution = AttributionStore::table();

        $where = ['o.payment_status IN (' . self::statusPlaceholders() . ')'];
        $args = self::reportStatuses();

        if ($since !== null) {
            $where[] = 'o.created_at >= %s';
            $args[] = $since;
        }

        $sql = "SELECT src.source AS source, COUNT(*) AS orders, COALESCE(SUM(o.total_paid), 0) AS revenue
                FROM {$orders} o
                INNER JOIN (
                    SELECT order_id, source FROM {$attribution} GROUP BY order_id, source
                ) src ON src.order_id = o.id
                WHERE " . implode(' AND ', $where) . '
                GROUP BY src.source';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from $wpdb->prefix and class constants; every value is bound through prepare() on the next line, and the caller caches the result.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * The biggest-spending Wholesale Customers in the window.
     *
     * ---------------------------------------------------------------------
     * THIS PANEL IS RETROACTIVE, AND IT IS THE ONLY ONE THAT IS
     * ---------------------------------------------------------------------
     *
     * It needs nothing this plugin recorded. "Who spent the most" is answerable
     * from FluentCart's own orders and customers, and "which of them are
     * wholesale" is answerable from WordPress' own capabilities meta — both of
     * which already hold every order the store has ever taken. So a store that
     * installs this today sees its real history here, while the two panels
     * above it start from zero.
     *
     * The role test is the same `meta_value LIKE '%"role"%'` that
     * WP_User_Query builds for `role__in`, run as a join instead of as a
     * separate `get_users()` call. Doing it as a join matters: `get_users()`
     * would return every wholesale user in the store and then this query would
     * need `customer_id IN (…)` with that entire list inlined, which is both
     * unbounded and unprepared-statement-shaped. One join lets MySQL use the
     * `meta_key` index and hand back twenty rows.
     *
     * Administrators are excluded even though Gate 1 admits them. An
     * administrator is not a wholesale customer, and leaving them in would
     * usually put the store owner's own test orders at the top of a panel
     * called "top wholesale customers".
     *
     * @param string|null $since
     * @return array<int, array<string, mixed>>
     */
    private static function topWholesaleCustomers($since)
    {
        global $wpdb;

        $roles = self::wholesaleRoles();

        if (!$roles) {
            return [];
        }

        $orders = $wpdb->prefix . 'fct_orders';
        $customers = $wpdb->prefix . 'fct_customers';

        $where = ['o.payment_status IN (' . self::statusPlaceholders() . ')'];
        $args = self::reportStatuses();

        if ($since !== null) {
            $where[] = 'o.created_at >= %s';
            $args[] = $since;
        }

        // Each fragment is appended to $where and its value to $args in the
        // same breath. prepare() binds positionally, so the two lists have to
        // stay in lockstep — building them apart is how an IN() clause ends up
        // matching the wrong column.
        //
        // The capability meta key is site-prefixed on multisite, because a user
        // can legitimately hold the role on one site of a network and not
        // another. get_blog_prefix() is the accessor WP_User_Query itself uses.
        $where[] = 'um.meta_key = %s';
        $args[] = $wpdb->get_blog_prefix() . 'capabilities';

        $roleTests = [];

        foreach ($roles as $role) {
            $roleTests[] = 'um.meta_value LIKE %s';
            // The quotes are part of the pattern, matching how WordPress
            // serializes a role into the capabilities array. Without them,
            // a role named `customer` would also match `wholesale-customer`.
            $args[] = '%' . $wpdb->esc_like('"' . $role . '"') . '%';
        }

        $where[] = '(' . implode(' OR ', $roleTests) . ')';

        $sql = "SELECT
                    c.id AS customer_id,
                    c.user_id AS user_id,
                    c.email AS email,
                    c.first_name AS first_name,
                    c.last_name AS last_name,
                    COUNT(*) AS orders,
                    COALESCE(SUM(o.total_paid), 0) AS revenue,
                    MAX(o.created_at) AS last_order
                FROM {$orders} o
                INNER JOIN {$customers} c ON c.id = o.customer_id
                INNER JOIN {$wpdb->usermeta} um ON um.user_id = c.user_id
                WHERE " . implode(' AND ', $where) . '
                GROUP BY c.id, c.user_id, c.email, c.first_name, c.last_name
                ORDER BY revenue DESC
                LIMIT ' . (int) self::TOP_CUSTOMERS;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from $wpdb; the LIMIT is an int cast of a class constant; every remaining value is bound through prepare() on the next line, and the caller caches the result.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * How much each tier was actually used in the window.
     *
     * Grouped by the tier key — one indexed column — with its components
     * carried along by the GROUP BY so the screen can name the tier without a
     * second lookup, and without needing the tier to still exist in a feed.
     *
     * `tier_key <> ''` is what separates the two things this table holds: rows
     * with a key are tier hits, rows without one are lines of an attributed
     * order that no tier priced. @see
     * \FluentCartBulkOrder\Analytics\AttributionStore.
     *
     * @param string|null $since
     * @return array<int, array<string, mixed>>
     */
    private static function tierHits($since)
    {
        global $wpdb;

        if (!AttributionStore::exists()) {
            return [];
        }

        $orders = $wpdb->prefix . 'fct_orders';
        $attribution = AttributionStore::table();

        $where = [
            "a.tier_key <> ''",
            'o.payment_status IN (' . self::statusPlaceholders() . ')',
        ];
        $args = self::reportStatuses();

        if ($since !== null) {
            $where[] = 'o.created_at >= %s';
            $args[] = $since;
        }

        $sql = "SELECT
                    a.tier_key AS tier_key,
                    a.tier_scope AS tier_scope,
                    a.tier_product_id AS tier_product_id,
                    a.tier_role AS tier_role,
                    a.tier_min_qty AS tier_min_qty,
                    a.tier_max_qty AS tier_max_qty,
                    a.tier_type AS tier_type,
                    a.tier_value AS tier_value,
                    COUNT(DISTINCT a.order_id) AS orders,
                    COALESCE(SUM(a.quantity), 0) AS units,
                    COALESCE(SUM(a.line_total), 0) AS revenue,
                    COALESCE(SUM(a.saving), 0) AS saving
                FROM {$attribution} a
                INNER JOIN {$orders} o ON o.id = a.order_id
                WHERE " . implode(' AND ', $where) . '
                GROUP BY a.tier_key, a.tier_scope, a.tier_product_id, a.tier_role,
                         a.tier_min_qty, a.tier_max_qty, a.tier_type, a.tier_value
                ORDER BY units DESC
                LIMIT ' . (int) self::MAX_TIERS;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from $wpdb->prefix and class constants; the LIMIT is an int cast of a class constant; every remaining value is bound through prepare() on the next line, and the caller caches the result.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Every tier currently configured on the store's feeds.
     *
     * Read from the same Integration Feed rows the pricing path reads, so a
     * tier is "configured" here exactly when it would price a cart line.
     *
     * BOUNDED, and it has to be: a store with a per-product feed on every
     * product would otherwise load the whole catalog's meta to draw one table.
     * The bound is reported back so the screen can say the list is partial
     * instead of quietly claiming a tier is unused when its feed was never
     * read.
     *
     * Deliberately NOT cached, and deliberately not folded into forPeriod().
     * The configured set does not depend on the reporting window, and it is the
     * one thing on this screen an owner changes and then immediately comes back
     * to check: a tier added a minute ago must appear in "nobody reached this"
     * at once, not a quarter of an hour later. The cost is two bounded queries
     * on a single admin page.
     *
     * @param int|null $maxFeeds Product feeds to read at most; null for the
     *                            class default.
     * @return array{tiers: array<int, array<string, mixed>>, truncated: bool}
     */
    public static function configuredTiers($maxFeeds = null)
    {
        $maxFeeds = $maxFeeds === null ? self::MAX_PRODUCT_FEEDS : max(1, (int) $maxFeeds);

        if (!class_exists(\FluentCart\App\Models\Meta::class)) {
            return ['tiers' => [], 'truncated' => false];
        }

        $tiers = [];

        $global = \FluentCart\App\Models\Meta::query()
            ->where('object_type', 'order_integration')
            ->where('meta_key', 'fcbo_bulk_pricing')
            ->first();

        if ($global) {
            self::collectFeedTiers($tiers, $global->meta_value, TierSignature::SCOPE_GLOBAL, 0);
        }

        $truncated = false;

        if (class_exists(\FluentCart\App\Models\ProductMeta::class)) {
            $feeds = \FluentCart\App\Models\ProductMeta::query()
                ->where('object_type', 'product_integration')
                ->where('meta_key', 'fcbo_bulk_pricing')
                ->orderBy('object_id', 'asc')
                ->limit($maxFeeds + 1)
                ->get();

            $seen = 0;

            foreach ($feeds as $feed) {
                if ($seen >= $maxFeeds) {
                    $truncated = true;

                    break;
                }

                $seen++;

                self::collectFeedTiers(
                    $tiers,
                    $feed->meta_value,
                    TierSignature::SCOPE_PRODUCT,
                    (int) $feed->object_id
                );
            }
        }

        return ['tiers' => array_values($tiers), 'truncated' => $truncated];
    }

    /**
     * Add one feed's tiers — default set and every role-scoped set — to the list.
     *
     * A DISABLED feed contributes nothing. That matches what the pricing path
     * does (FeedResolver drops a feed whose `enabled` is not `yes`) and it is
     * also the answer an owner wants: a tier on a switched-off feed is not an
     * unused tier, it is a switched-off tier, and reporting it as "never
     * reached" would send them looking for a problem that is not there.
     *
     * @param array<string, array<string, mixed>> $tiers Accumulator, keyed by tier key.
     * @param mixed                               $feedData
     * @param string                              $scope
     * @param int                                 $productId
     * @return void
     */
    private static function collectFeedTiers(&$tiers, $feedData, $scope, $productId)
    {
        if (!is_array($feedData) || empty($feedData['enabled']) || $feedData['enabled'] !== 'yes') {
            return;
        }

        $sets = [
            '' => isset($feedData['tiers']) && is_array($feedData['tiers']) ? $feedData['tiers'] : [],
        ];

        if (!empty($feedData['role_tiers']) && is_array($feedData['role_tiers'])) {
            foreach ($feedData['role_tiers'] as $role => $list) {
                if (is_array($list) && $list) {
                    $sets[(string) $role] = $list;
                }
            }
        }

        foreach ($sets as $role => $list) {
            foreach ((array) $list as $tier) {
                if (!is_array($tier)) {
                    continue;
                }

                $columns = TierSignature::columns($tier, $scope, $productId, (string) $role);
                $tiers[$columns['tier_key']] = $columns;
            }
        }
    }

    /**
     * The roles that make a customer a Wholesale Customer, for this panel.
     *
     * Gate 1's role list, minus `administrator`. @see topWholesaleCustomers()
     * for why the administrator comes out.
     *
     * @return string[]
     */
    private static function wholesaleRoles()
    {
        $roles = array_filter(AccessPolicy::allowedRoles(), function ($role) {
            return $role !== 'administrator';
        });

        return array_values(array_unique(array_map('sanitize_key', $roles)));
    }

    /**
     * The payment statuses that count as revenue.
     *
     * Read from FluentCart when the host class is loadable, so this screen
     * tracks the host's own definition through an upgrade. The literal list is
     * the same set as of fluent-cart 1.5.5 and exists only for the window in
     * which the host is active but that class is not yet loaded.
     *
     * @return string[]
     */
    private static function reportStatuses()
    {
        if (class_exists(\FluentCart\App\Helpers\Status::class)
            && method_exists(\FluentCart\App\Helpers\Status::class, 'getReportStatuses')) {
            $statuses = \FluentCart\App\Helpers\Status::getReportStatuses();

            if (is_array($statuses) && $statuses) {
                return array_values(array_map('strval', $statuses));
            }
        }

        return ['paid', 'refunded', 'partially_paid', 'partially_refunded'];
    }

    /**
     * A `%s` placeholder per status, for the IN() clause.
     *
     * Built from the same call that supplies the values, so the two can never
     * be a different length — the way an IN() clause goes wrong.
     *
     * @return string
     */
    private static function statusPlaceholders()
    {
        return implode(', ', array_fill(0, count(self::reportStatuses()), '%s'));
    }

    /**
     * Forget every cached report.
     *
     * Not called on a write — @see the class docblock. It exists for the
     * uninstall path, and for a developer who wants to see a change
     * immediately.
     *
     * @return void
     */
    public static function flushCache()
    {
        foreach (Period::keys() as $period) {
            delete_transient(self::CACHE_PREFIX . $period);
        }
    }
}
