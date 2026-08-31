<?php

namespace FluentCartBulkOrder\Analytics;

defined('ABSPATH') || exit;

/**
 * Where a Bulk Order Attribution is kept: this plugin's one custom table.
 *
 * ---------------------------------------------------------------------------
 * WHY A TABLE AND NOT ORDER META, WHICH EVERYTHING ELSE HERE USES
 * ---------------------------------------------------------------------------
 *
 * The PO number lives in FluentCart's `fct_order_meta`, and that is right for
 * it: it is read one order at a time, by an owner looking at that order.
 *
 * An attribution is never read one order at a time. Every question this feature
 * answers is an aggregate over every order in a window — "how much revenue",
 * "which tier, how often", "who spent the most". A JSON blob in a meta row can
 * only answer those by being loaded into PHP and summed there, which on a store
 * with 100,000 orders means loading 100,000 rows to produce four numbers. A
 * table with typed columns answers all of them in SQL, which is where
 * aggregation belongs.
 *
 * That is the whole argument. It is not "custom tables are better"; it is that
 * the access pattern is aggregate-only, and meta cannot serve an aggregate.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS NOT IN HERE, DELIBERATELY: MONEY THE ORDER OWNS
 * ---------------------------------------------------------------------------
 *
 * There is no order total, no payment status and no order date in this table,
 * even though every report needs all three. They are JOINed from `fct_orders`
 * at read time instead, for one reason that is not negotiable:
 *
 *   THE ROW IS WRITTEN BEFORE THE ORDER IS PAID.
 *
 * `fluent_cart/checkout/prepare_other_data` fires on a DRAFT order —
 * `status = 'on-hold'`, `payment_status = 'pending'`, `total_paid = 0`
 * (fluent-cart 1.5.5, app/Helpers/CheckoutProcessor.php:982,989). A total
 * copied at that moment would be zero forever. Worse, a copy would never learn
 * about the refund the store issues next week, so this screen would drift away
 * from FluentCart's own reports and nobody would be able to say which was
 * right.
 *
 * What IS stored is everything the order does NOT know: which tier priced each
 * line, what the line would have cost without it, and which surface the shopper
 * came from. That is the plugin's own knowledge, it is true at the moment of
 * pricing, and nothing later can recompute it.
 *
 * ---------------------------------------------------------------------------
 * ONE ROW PER LINE, AND AN EMPTY `tier_key` IS MEANINGFUL
 * ---------------------------------------------------------------------------
 *
 * The grain is the order LINE, because tier utilization is a per-line question.
 * A line whose price no tier changed still gets a row when the ORDER is
 * attributed — with `tier_key = ''` — because "this order came through the bulk
 * order form" is true of the whole order, and dropping the undiscounted lines
 * would make an order vanish from the revenue panel for the accident of nothing
 * having qualified.
 *
 * So: `tier_key <> ''` is the tier-utilization filter, and the mere existence
 * of a row for an order is the bulk-revenue filter. Two questions, one table,
 * no ambiguity.
 *
 * @see \FluentCartBulkOrder\Analytics\OrderAttribution What decides the contents.
 * @see \FluentCartBulkOrder\Analytics\Reports What reads it back.
 */
class AttributionStore
{
    /**
     * Table name, without the site prefix.
     */
    const TABLE = 'fcbo_order_attribution';

    /**
     * The installed schema version.
     *
     * Bump this when the columns change; register() compares it against the
     * stored option and runs dbDelta when they differ, which is what upgrades
     * an install that was already active when this feature shipped.
     */
    const SCHEMA_VERSION = '1';

    /**
     * Option holding the schema version this site has installed.
     *
     * Autoloaded like every other WordPress option, so the check on each page
     * load costs an array lookup rather than a query.
     */
    const VERSION_OPTION = 'fcbo_analytics_schema';

    /**
     * Transient set when a table creation FAILS, to stop the retry running on
     * every request. @see ensureInstalled().
     */
    const BACKOFF_TRANSIENT = 'fcbo_analytics_install_backoff';

    /**
     * How long to wait before trying to create the table again.
     */
    const BACKOFF_TTL = HOUR_IN_SECONDS;

    /**
     * Request memo for exists(). Null until the first check.
     *
     * @var bool|null
     */
    private static $tableExists = null;

    /**
     * The prefixed table name.
     *
     * `$wpdb->prefix` and NOT `base_prefix`: on multisite, orders belong to a
     * site, so an attribution about them does too. Deactivator drops this table
     * once per site for the same reason.
     *
     * @return string
     */
    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create or update the table if this site's schema is out of date.
     *
     * Cheap enough to call on every request: the common path is one comparison
     * against an autoloaded option. The expensive branch runs once per upgrade.
     *
     * Deliberately NOT limited to activation. A store that already had the
     * plugin active when this feature shipped never runs the activation hook
     * again, and would otherwise be left recording into a table that does not
     * exist.
     *
     * ---------------------------------------------------------------------
     * THE BACKOFF IS NOT BELT-AND-BRACES; IT IS THE POINT
     * ---------------------------------------------------------------------
     *
     * install() pulls in `wp-admin/includes/upgrade.php`, which drags
     * `admin.php` and `schema.php` behind it — the whole wp-admin API, on a
     * FRONT-END request. That is a fine price to pay once, on the first page
     * load after an upgrade.
     *
     * It is not a fine price to pay forever. Because the version is only
     * recorded on SUCCESS (so a fixable problem self-heals), a site whose
     * database user has no CREATE grant would otherwise bootstrap wp-admin and
     * fail a dbDelta on every single front-end request for the rest of its
     * life. The transient below caps that at one attempt an hour: still
     * self-healing, no longer a permanent tax on every page view.
     *
     * @return void
     */
    public static function ensureInstalled()
    {
        if (get_option(self::VERSION_OPTION) === self::SCHEMA_VERSION) {
            return;
        }

        if (get_transient(self::BACKOFF_TRANSIENT)) {
            return;
        }

        self::install();
    }

    /**
     * Create the table.
     *
     * dbDelta's formatting rules are strict and unforgiving — two spaces after
     * PRIMARY KEY, one field per line, KEY names spelled the same way MySQL
     * will report them — so the SQL below is written to them rather than to
     * taste. @see https://developer.wordpress.org/reference/functions/dbdelta/
     *
     * Every money column is BIGINT of integer cents, matching FluentCart's own
     * columns exactly (fluent-cart 1.5.5,
     * database/Migrations/OrdersMigrator.php:29-39). A DECIMAL here would mean
     * converting on the way in and back on the way out, which is where rounding
     * errors get in.
     *
     * @return void
     */
    public static function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(20) NOT NULL DEFAULT '',
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            variant_id bigint(20) unsigned NOT NULL DEFAULT 0,
            tier_key varchar(32) NOT NULL DEFAULT '',
            tier_scope varchar(10) NOT NULL DEFAULT '',
            tier_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            tier_role varchar(64) NOT NULL DEFAULT '',
            tier_min_qty int(10) unsigned NOT NULL DEFAULT 0,
            tier_max_qty int(10) unsigned NOT NULL DEFAULT 0,
            tier_type varchar(20) NOT NULL DEFAULT '',
            tier_value decimal(14,4) NOT NULL DEFAULT 0,
            quantity int(10) unsigned NOT NULL DEFAULT 0,
            list_price bigint(20) NOT NULL DEFAULT 0,
            unit_price bigint(20) NOT NULL DEFAULT 0,
            line_total bigint(20) NOT NULL DEFAULT 0,
            saving bigint(20) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY tier_key (tier_key),
            KEY created_at (created_at)
        ) {$collate};";

        dbDelta($sql);

        // The memoized answer from before the table existed would otherwise
        // keep the read paths believing it still does not.
        self::$tableExists = null;

        // The version is stored ONLY on success, so a site whose database user
        // cannot CREATE TABLE heals itself the moment the grant is fixed rather
        // than recording the attempt and giving up for good. The failure branch
        // sets a backoff instead, so that retry costs one attempt an hour and
        // not one per page view. @see ensureInstalled().
        if (self::exists()) {
            delete_transient(self::BACKOFF_TRANSIENT);
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, true);

            return;
        }

        set_transient(self::BACKOFF_TRANSIENT, 1, self::BACKOFF_TTL);
    }

    /**
     * Write one order's attribution.
     *
     * ONE multi-row INSERT, not one per line. A ten-line order is one round
     * trip, and it happens once in the life of an order — on the request that
     * created it, alongside the several dozen writes FluentCart is already
     * doing.
     *
     * Existing rows for the order are cleared first, so a retried checkout that
     * reaches this point twice for the same order id replaces its attribution
     * rather than doubling every figure the order contributes to.
     *
     * @param int                              $orderId
     * @param array<int, array<string, mixed>> $rows One per order line, shaped by
     *                                                \FluentCartBulkOrder\Analytics\OrderAttribution.
     * @return int Rows written.
     */
    public static function record($orderId, $rows)
    {
        global $wpdb;

        $orderId = (int) $orderId;

        if ($orderId < 1 || empty($rows)) {
            return 0;
        }

        self::ensureInstalled();

        // Belt as well as braces. ensureInstalled() can return with no table —
        // a database user without CREATE, most plainly — and writing into a
        // table that is not there would put a WordPress database error on a
        // shopper's checkout page for the sake of a report row.
        if (!self::exists()) {
            return 0;
        }

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- this plugin's own table; there is no WordPress API for it, and a write has nothing to cache.
        $wpdb->delete($table, ['order_id' => $orderId], ['%d']);

        // The site's own clock, matching how FluentCart stamps `fct_orders`
        // (its ORM's default timezone is wp_timezone()). The two columns are
        // compared in reports, so they have to be measured the same way.
        // @see \FluentCartBulkOrder\Analytics\Period
        $now = gmdate('Y-m-d H:i:s', (int) current_time('timestamp'));

        $values = [];
        $args = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $values[] = '(%d, %s, %d, %d, %s, %s, %d, %s, %d, %d, %s, %f, %d, %d, %d, %d, %d, %s)';

            $args[] = $orderId;
            $args[] = (string) (isset($row['source']) ? $row['source'] : '');
            $args[] = (int) (isset($row['product_id']) ? $row['product_id'] : 0);
            $args[] = (int) (isset($row['variant_id']) ? $row['variant_id'] : 0);
            $args[] = (string) (isset($row['tier_key']) ? $row['tier_key'] : '');
            $args[] = (string) (isset($row['tier_scope']) ? $row['tier_scope'] : '');
            $args[] = (int) (isset($row['tier_product_id']) ? $row['tier_product_id'] : 0);
            $args[] = (string) (isset($row['tier_role']) ? $row['tier_role'] : '');
            $args[] = (int) (isset($row['tier_min_qty']) ? $row['tier_min_qty'] : 0);
            $args[] = (int) (isset($row['tier_max_qty']) ? $row['tier_max_qty'] : 0);
            $args[] = (string) (isset($row['tier_type']) ? $row['tier_type'] : '');
            $args[] = (float) (isset($row['tier_value']) ? $row['tier_value'] : 0);
            $args[] = (int) (isset($row['quantity']) ? $row['quantity'] : 0);
            $args[] = (int) (isset($row['list_price']) ? $row['list_price'] : 0);
            $args[] = (int) (isset($row['unit_price']) ? $row['unit_price'] : 0);
            $args[] = (int) (isset($row['line_total']) ? $row['line_total'] : 0);
            $args[] = (int) (isset($row['saving']) ? $row['saving'] : 0);
            $args[] = $now;
        }

        if (!$values) {
            return 0;
        }

        $sql = "INSERT INTO {$table}
            (order_id, source, product_id, variant_id, tier_key, tier_scope, tier_product_id, tier_role,
             tier_min_qty, tier_max_qty, tier_type, tier_value, quantity, list_price, unit_price,
             line_total, saving, created_at)
            VALUES " . implode(', ', $values);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built only from the table name and a repeated literal placeholder group; every VALUE is bound through prepare() on the next line.
        $written = $wpdb->query($wpdb->prepare($sql, $args));

        // Deliberately does NOT invalidate the report cache. @see
        // \FluentCartBulkOrder\Analytics\Reports::CACHE_TTL — a busy store
        // would flush on every order and never keep a cached figure, which is
        // exactly backwards from what the cache is there to protect.

        return (int) $written;
    }

    /**
     * The earliest attribution this site holds, as a MySQL datetime.
     *
     * The screen prints it as "recording started on …", which is the one thing
     * that stops an empty panel reading as a broken panel. Null means nothing
     * has been recorded yet.
     *
     * Deliberately NOT cached, unlike everything else this screen reads.
     * `MIN()` on an indexed column is answered from the head of the index
     * without touching a row, so there is nothing to save — and a cached
     * version would leave a brand-new store still being told "nothing recorded
     * yet" for a quarter of an hour after its first bulk order.
     *
     * @return string|null
     */
    public static function recordingSince()
    {
        global $wpdb;

        if (!self::exists()) {
            return null;
        }

        $table = self::table();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- this plugin's own table, interpolated from $wpdb->prefix and a class constant; there are no user-supplied values in this statement, and MIN() on an indexed column needs no cache.
        $since = $wpdb->get_var("SELECT MIN(created_at) FROM {$table}");

        return $since ? (string) $since : null;
    }

    /**
     * Whether the table is actually there.
     *
     * Guards the read paths so a store whose table creation failed — a database
     * user without CREATE, most likely — sees an empty screen with its "nothing
     * recorded yet" message rather than a WordPress database error.
     *
     * @return bool
     */
    public static function exists()
    {
        global $wpdb;

        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema check on this plugin's own table, memoized for the request.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));

        self::$tableExists = ($found === $table);

        return self::$tableExists;
    }

    /**
     * Drop the table. Called only from the uninstall path.
     *
     * @see \FluentCartBulkOrder\Deactivator::uninstall() for why this goes and
     *      the PO numbers in FluentCart's own table stay.
     * @return void
     */
    public static function drop()
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE cannot take a bound table name; the value is built from $wpdb->prefix and a class constant, neither of which is user input. The schema change is the point of the method: it runs from uninstall only. @see \FluentCartBulkOrder\Deactivator::uninstall()
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        delete_option(self::VERSION_OPTION);
    }
}
