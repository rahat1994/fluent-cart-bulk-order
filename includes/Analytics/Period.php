<?php

namespace FluentCartBulkOrder\Analytics;

defined('ABSPATH') || exit;

/**
 * The date window an analytics figure is measured over.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A CLOSED LIST AND NOT A DATE PICKER
 * ---------------------------------------------------------------------------
 *
 * Every value here ends up inside a bound `WHERE created_at >= %s` against a
 * table that can hold hundreds of thousands of rows, and every distinct value
 * is a separate cached result. A free date picker would multiply both: an
 * unbounded set of cache keys, each paid for with a full aggregate. Four named
 * windows answer the questions an owner actually asks — "is this working now",
 * "is it working this quarter", "is it working this year", "has it ever" — and
 * keep the cache small enough to be worth having.
 *
 * ---------------------------------------------------------------------------
 * THE TIMEZONE, WHICH IS NOT A DETAIL
 * ---------------------------------------------------------------------------
 *
 * FluentCart writes `fct_orders.created_at` in the SITE's timezone, not in UTC:
 * its ORM stamps rows through DateTime::now(), whose default zone is
 * `wp_timezone()` (fluent-cart 1.5.5,
 * vendor/wpfluent/framework/src/WPFluent/Support/DateTime.php:203 and
 * .../Database/Orm/Concerns/HasTimestamps.php:114).
 *
 * So a boundary computed in UTC would be wrong by the site's offset — on a
 * UTC+13 store, "the last 30 days" would silently start half a day late. The
 * caller therefore passes `current_time('timestamp')`, which is WordPress'
 * site-local clock, and since() formats it with gmdate() so no second offset is
 * applied on the way out. That pairing is the standard WordPress idiom for
 * "site wall clock as a MySQL string", and it is deliberate on both halves.
 *
 * Pure: no database, no options, no request state. `__()` is the only
 * WordPress call, and it is a passthrough in the unit suite.
 */
class Period
{
    /**
     * The query argument this travels in.
     */
    const PARAM = 'period';

    /**
     * The four windows, as slugs.
     */
    const LAST_30_DAYS = '30days';
    const LAST_90_DAYS = '90days';
    const LAST_12_MONTHS = '12months';
    const ALL_TIME = 'all';

    /**
     * The window a store owner lands on.
     *
     * 90 days rather than 30: bulk orders are infrequent by nature — a
     * wholesale buyer who orders monthly contributes one data point in 30 days
     * — and a screen whose whole job is to say "this tier is never used" must
     * not say it because the window was too short to see it used.
     */
    const DEFAULT_PERIOD = self::LAST_90_DAYS;

    /**
     * How far back each window reaches, in seconds. Absent = no lower bound.
     *
     * A month is taken as 30 days and a year as 365. Neither is calendar-exact
     * and neither needs to be: these are "roughly the last quarter" windows for
     * comparing two numbers measured over the SAME window, not accounting
     * periods that have to reconcile with anything.
     *
     * @var array<string, int>
     */
    const SPANS = [
        self::LAST_30_DAYS   => 30 * DAY_IN_SECONDS,
        self::LAST_90_DAYS   => 90 * DAY_IN_SECONDS,
        self::LAST_12_MONTHS => 365 * DAY_IN_SECONDS,
    ];

    /**
     * Every window, in the order they are offered.
     *
     * @return string[]
     */
    public static function keys()
    {
        return [
            self::LAST_30_DAYS,
            self::LAST_90_DAYS,
            self::LAST_12_MONTHS,
            self::ALL_TIME,
        ];
    }

    /**
     * Map any submitted value onto a real window.
     *
     * Anything unrecognised becomes the default rather than an error. A
     * reporting screen reached with a mangled query string should show the
     * default report, not a wp_die().
     *
     * @param mixed $value Raw request value.
     * @return string One of self::keys().
     */
    public static function sanitize($value)
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::keys(), true) ? $value : self::DEFAULT_PERIOD;
    }

    /**
     * What this window is called on screen.
     *
     * @param string $key One of self::keys().
     * @return string
     */
    public static function label($key)
    {
        switch (self::sanitize($key)) {
            case self::LAST_30_DAYS:
                return __('Last 30 days', 'fluent-cart-bulk-order');
            case self::LAST_12_MONTHS:
                return __('Last 12 months', 'fluent-cart-bulk-order');
            case self::ALL_TIME:
                return __('All time', 'fluent-cart-bulk-order');
            case self::LAST_90_DAYS:
            default:
                return __('Last 90 days', 'fluent-cart-bulk-order');
        }
    }

    /**
     * The earliest moment this window includes, as a MySQL datetime.
     *
     * @param string $key Window slug.
     * @param int    $now Site-local timestamp, i.e. `current_time('timestamp')`.
     *                    @see the class docblock for why it must be that and
     *                    not `time()`.
     * @return string|null 'Y-m-d H:i:s', or null for all time (no lower bound).
     */
    public static function since($key, $now)
    {
        $key = self::sanitize($key);

        if (!isset(self::SPANS[$key])) {
            return null;
        }

        // gmdate() and not date(): $now is already shifted into site-local
        // wall clock by current_time(), so applying the server's own offset
        // on top would move the boundary a second time.
        return gmdate('Y-m-d H:i:s', (int) $now - self::SPANS[$key]);
    }
}
