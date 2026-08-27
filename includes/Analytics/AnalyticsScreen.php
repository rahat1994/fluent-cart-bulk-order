<?php

namespace FluentCartBulkOrder\Analytics;

defined('ABSPATH') || exit;

/**
 * The owner's screen: is bulk ordering working, who is using it, and which
 * tiers are dead weight.
 *
 * ---------------------------------------------------------------------------
 * AN EMPTY SCREEN MUST READ AS AN ANSWER, NOT AS A BUG
 * ---------------------------------------------------------------------------
 *
 * Two of the three panels can only report on orders placed AFTER this feature
 * was installed, because nothing recorded the data before it existed —
 * @see \FluentCartBulkOrder\Analytics\OrderAttribution. A store that installs
 * this today and opens the screen tomorrow will see zeros, and there is exactly
 * one way to handle that honestly: say so, on the screen, next to the zeros.
 *
 * So every panel carries a one-line note saying which orders it can see. The
 * revenue and tier panels say "recording started on <date>" — or "no bulk
 * orders recorded yet; this fills in as orders come in" before there is a date
 * to give. The customers panel says the opposite, because it is genuinely
 * retroactive and an owner should know that its numbers include history the
 * other two cannot.
 *
 * Shipping this without those notes would be shipping a screen that shows zeros
 * forever and looks broken while doing it.
 *
 * ---------------------------------------------------------------------------
 * READ-ONLY, SO IT IS A GET AND HAS NO NONCE
 * ---------------------------------------------------------------------------
 *
 * The period selector changes nothing; it picks which numbers to display. The
 * same rule OrderExportFlow states: a state-changing action needs a POST, a
 * read does not. The capability, by contrast, is checked on EVERY render and
 * not just on the menu entry — this screen prints named customers and what they
 * spent, which is exactly the sort of thing that must not be reachable by
 * typing a URL.
 *
 * ---------------------------------------------------------------------------
 * NO JAVASCRIPT, AND NO CHART
 * ---------------------------------------------------------------------------
 *
 * Every number here is rendered server-side into a plain table, the way the
 * order export screen is. A bar chart of four figures would need a charting
 * library, a build step this plugin does not have, and a second set of strings
 * to translate — to show what a percentage column already says.
 */
class AnalyticsScreen
{
    /**
     * Admin page slug.
     */
    const PAGE_SLUG = 'fcbo-analytics';

    /**
     * The capability required to see this screen.
     *
     * `manage_options`, matching the plugin's other owner screens. This one
     * shows customer names, email addresses and lifetime spend, so it does not
     * get a weaker gate than the screens showing the same data one order at a
     * time.
     */
    const CAPABILITY = 'manage_options';

    /**
     * Add the menu entry, beside the plugin's other screens.
     *
     * @return void
     */
    public static function addMenu()
    {
        $title = __('Bulk Order Analytics', 'fluent-cart-bulk-order');

        add_options_page(
            $title,
            $title,
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * This screen's URL.
     *
     * @return string
     */
    public static function pageUrl()
    {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG);
    }

    /**
     * Render the screen.
     *
     * @return void
     */
    public static function render()
    {
        // Not redundant with the menu capability. add_options_page() controls
        // visibility; this controls access.
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view bulk order analytics.', 'fluent-cart-bulk-order'));
        }

        // Everything the panels read is pulled in here, on the one request that
        // draws them. @see AnalyticsFlow::registerScreen().
        AnalyticsFlow::load();

        $period = Period::sanitize(self::requestedPeriod());
        $data = Reports::forPeriod($period);
        $since = AttributionStore::recordingSince();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Bulk Order Analytics', 'fluent-cart-bulk-order') . '</h1>';

        printf(
            '<p>%s</p>',
            esc_html__(
                'How much of the store\'s revenue comes through bulk ordering, who is buying, and which quantity tiers are actually being reached.',
                'fluent-cart-bulk-order'
            )
        );

        self::renderPeriodNav($period);

        self::renderRevenuePanel($data, $period, $since);
        self::renderCustomersPanel($data);
        self::renderTierPanel($data, $period, $since);

        self::renderFootnote($data);

        echo '</div>';
    }

    /**
     * The period switcher, as the standard admin subsubsub list.
     *
     * Plain links, because the result is a bookmarkable report and not a change
     * to anything.
     *
     * @param string $current
     * @return void
     */
    private static function renderPeriodNav($current)
    {
        echo '<ul class="subsubsub">';

        $keys = Period::keys();
        $last = count($keys) - 1;

        foreach ($keys as $i => $key) {
            echo '<li>';

            if ($key === $current) {
                // The current view is not a link to itself. `current` is the
                // class core's own list tables use, so it picks up the admin
                // theme rather than needing one of ours.
                printf(
                    '<span class="current" aria-current="page">%s</span>',
                    esc_html(Period::label($key))
                );
            } else {
                printf(
                    '<a href="%1$s">%2$s</a>',
                    esc_url(self::periodUrl($key)),
                    esc_html(Period::label($key))
                );
            }

            if ($i !== $last) {
                echo ' |';
            }

            echo '</li>';
        }

        echo '</ul><div class="clear"></div>';
    }

    /**
     * PANEL 1 — bulk order revenue against normal checkout.
     *
     * @param array       $data
     * @param string      $period
     * @param string|null $since
     * @return void
     */
    private static function renderRevenuePanel($data, $period, $since)
    {
        $split = $data['split'];

        echo '<h2>' . esc_html__('Where the revenue came from', 'fluent-cart-bulk-order') . '</h2>';

        self::renderForwardOnlyNote($since);

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Revenue', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Orders', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Amount', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Share', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        self::renderRevenueRow(
            __('Bulk orders', 'fluent-cart-bulk-order'),
            $split['bulk'],
            true
        );

        self::renderRevenueRow(
            __('Normal checkout', 'fluent-cart-bulk-order'),
            $split['normal'],
            false
        );

        printf(
            '<tr><td><strong>%1$s</strong></td><td><strong>%2$s</strong></td><td><strong>%3$s</strong></td><td>&nbsp;</td></tr>',
            esc_html(
                sprintf(
                    /* translators: %s: the reporting window, e.g. "Last 90 days". */
                    __('All orders (%s)', 'fluent-cart-bulk-order'),
                    Period::label($period)
                )
            ),
            esc_html(number_format_i18n((int) $split['all']['orders'])),
            esc_html(self::money($split['all']['revenue']))
        );

        echo '</tbody></table>';

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'An order counts as a bulk order when a Bulk Pricing Tier set the price of at least one of its lines, when the buyer reached checkout from a bulk-order surface, or when it was created from an accepted quote. Everything else is normal checkout.',
                'fluent-cart-bulk-order'
            )
        );

        self::renderSourceBreakdown($data['by_source']);
    }

    /**
     * One row of the revenue table.
     *
     * @param string $label
     * @param array  $figures
     * @param bool   $strong
     * @return void
     */
    private static function renderRevenueRow($label, $figures, $strong)
    {
        printf(
            '<tr><td>%1$s%2$s%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
            $strong ? '<strong>' : '',
            esc_html($label),
            $strong ? '</strong>' : '',
            esc_html(number_format_i18n((int) $figures['orders'])),
            esc_html(self::money($figures['revenue'])),
            esc_html(self::percent($figures['share']))
        );
    }

    /**
     * The entry-point breakdown under panel 1.
     *
     * @param array $rows
     * @return void
     */
    private static function renderSourceBreakdown($rows)
    {
        if (!$rows) {
            return;
        }

        echo '<h3>' . esc_html__('Where those bulk orders started', 'fluent-cart-bulk-order') . '</h3>';

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Entry point', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Orders', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Amount', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Share of bulk revenue', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            printf(
                '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
                esc_html(Surface::label($row['source'])),
                esc_html(number_format_i18n((int) $row['orders'])),
                esc_html(self::money($row['revenue'])),
                esc_html(self::percent($row['share']))
            );
        }

        echo '</tbody></table>';

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'The Product Table adds items to the cart and leaves the shopper on the catalog page, so there is no handoff to mark and its orders appear under "Entry point not recorded". Only the Bulk Order Form and Saved Orders send the buyer to checkout themselves.',
                'fluent-cart-bulk-order'
            )
        );
    }

    /**
     * PANEL 2 — top wholesale customers by spend.
     *
     * @param array $data
     * @return void
     */
    private static function renderCustomersPanel($data)
    {
        echo '<h2>' . esc_html__('Top wholesale customers', 'fluent-cart-bulk-order') . '</h2>';

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'These figures include your whole order history, not only orders placed since this screen was installed — they are read from FluentCart\'s own orders and from each buyer\'s role.',
                'fluent-cart-bulk-order'
            )
        );

        $rows = $data['customers'];

        if (!$rows) {
            printf(
                '<p>%s</p>',
                esc_html__(
                    'No wholesale customer has a paid order in this period.',
                    'fluent-cart-bulk-order'
                )
            );

            return;
        }

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Customer', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Email', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Orders', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Spend', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Last order', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $name = trim(
                (string) (isset($row['first_name']) ? $row['first_name'] : '')
                . ' '
                . (string) (isset($row['last_name']) ? $row['last_name'] : '')
            );

            printf(
                '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
                // An em dash rather than an empty cell, so "this customer has
                // no name on file" reads as an answer and not as a broken row.
                esc_html($name !== '' ? $name : '—'),
                esc_html((string) (isset($row['email']) ? $row['email'] : '')),
                esc_html(number_format_i18n((int) $row['orders'])),
                esc_html(self::money($row['revenue'])),
                esc_html(self::date(isset($row['last_order']) ? $row['last_order'] : ''))
            );
        }

        echo '</tbody></table>';
    }

    /**
     * PANEL 3 — tier utilization, and the tiers nobody ever reaches.
     *
     * @param array       $data
     * @param string      $period
     * @param string|null $since
     * @return void
     */
    private static function renderTierPanel($data, $period, $since)
    {
        echo '<h2>' . esc_html__('Tier utilization', 'fluent-cart-bulk-order') . '</h2>';

        self::renderForwardOnlyNote($since);

        $configured = Reports::configuredTiers();
        $usage = TierUsage::merge($data['tier_hits'], $configured['tiers']);

        // Every product-scoped tier row is named by its product, and naming it
        // one row at a time is a query per row — up to a few hundred of them on
        // a store with per-product feeds everywhere. One priming pass turns
        // that into a single query for the lot.
        self::primeProductTitles([$usage['used'], $usage['unused'], $usage['retired']]);

        if ($configured['truncated']) {
            printf(
                '<div class="notice notice-info inline"><p>%s</p></div>',
                esc_html__(
                    'This store has more product-level pricing feeds than this screen reads at once, so the list of configured tiers below is partial. Tiers that were actually used are all shown.',
                    'fluent-cart-bulk-order'
                )
            );
        }

        if (!$configured['tiers'] && !$usage['used'] && !$usage['retired']) {
            printf(
                '<p>%s</p>',
                esc_html__(
                    'No bulk pricing tiers are configured yet. Add them to a Bulk Pricing feed and this table will show which ones buyers actually reach.',
                    'fluent-cart-bulk-order'
                )
            );

            return;
        }

        // The headline, and the reason this panel is the valuable one: how many
        // of the store's tiers earn their place.
        printf(
            '<p>%s</p>',
            esc_html(
                sprintf(
                    /* translators: 1: number of tiers reached; 2: number of tiers configured; 3: the reporting window, e.g. "Last 90 days". */
                    __('%1$s of %2$s configured tiers were reached in this period (%3$s).', 'fluent-cart-bulk-order'),
                    number_format_i18n((int) $usage['used_count']),
                    number_format_i18n((int) $usage['configured_count']),
                    Period::label($period)
                )
            )
        );

        self::renderTierTable(
            __('Tiers buyers reached', 'fluent-cart-bulk-order'),
            $usage['used'],
            __('None of the configured tiers were reached in this period.', 'fluent-cart-bulk-order'),
            true
        );

        // WITHOUT the usage columns. Every one of them is zero by definition on
        // this table, and four columns of zeros per row is noise an owner has
        // to read past to reach the one thing the row says: this tier exists
        // and nobody has ever got to it.
        self::renderTierTable(
            __('Tiers nobody reached', 'fluent-cart-bulk-order'),
            $usage['unused'],
            __('Every configured tier was reached at least once. Nothing is going unused.', 'fluent-cart-bulk-order'),
            false
        );

        if ($usage['retired']) {
            self::renderTierTable(
                __('Tiers that no longer exist', 'fluent-cart-bulk-order'),
                $usage['retired'],
                '',
                true
            );

            printf(
                '<p class="description">%s</p>',
                esc_html__(
                    'These priced real orders in this period but are no longer on any feed — they were edited or removed. Editing a tier makes a new one here, because the orders under the old one were charged the old price.',
                    'fluent-cart-bulk-order'
                )
            );
        }
    }

    /**
     * One tier table.
     *
     * @param string $heading
     * @param array  $rows
     * @param string $emptyMessage '' to render nothing when empty.
     * @param bool   $withUsage    Whether to print the four aggregate columns.
     * @return void
     */
    private static function renderTierTable($heading, $rows, $emptyMessage, $withUsage)
    {
        echo '<h3>' . esc_html($heading) . '</h3>';

        if (!$rows) {
            if ($emptyMessage !== '') {
                printf('<p>%s</p>', esc_html($emptyMessage));
            }

            return;
        }

        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Quantity', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Discount', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Applies to', 'fluent-cart-bulk-order') . '</th>';

        if ($withUsage) {
            echo '<th scope="col">' . esc_html__('Orders', 'fluent-cart-bulk-order') . '</th>';
            echo '<th scope="col">' . esc_html__('Units', 'fluent-cart-bulk-order') . '</th>';
            echo '<th scope="col">' . esc_html__('Line revenue', 'fluent-cart-bulk-order') . '</th>';
            echo '<th scope="col">' . esc_html__('Discount given', 'fluent-cart-bulk-order') . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';

            printf(
                '<td>%1$s</td><td>%2$s</td><td>%3$s</td>',
                esc_html(TierSignature::rangeLabel($row['tier_min_qty'], $row['tier_max_qty'])),
                esc_html(TierSignature::discountLabel($row['tier_type'], $row['tier_value'])),
                esc_html(self::tierScopeLabel($row))
            );

            if ($withUsage) {
                printf(
                    '<td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td>',
                    esc_html(number_format_i18n((int) $row['orders'])),
                    esc_html(number_format_i18n((int) $row['units'])),
                    esc_html(self::money($row['revenue'])),
                    esc_html(self::money($row['saving']))
                );
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Load every product a tier table is about, in one query.
     *
     * `_prime_post_caches()` is core's own bulk loader — it is what WP_Query
     * uses for exactly this. Guarded by function_exists() because it is an
     * underscore-prefixed internal: if a future core release retires it, the
     * screen falls back to a query per row rather than to a fatal.
     *
     * @param array<int, array<int, array<string, mixed>>> $tables
     * @return void
     */
    private static function primeProductTitles($tables)
    {
        if (!function_exists('_prime_post_caches')) {
            return;
        }

        $ids = [];

        foreach ($tables as $rows) {
            foreach ($rows as $row) {
                $id = (int) (isset($row['tier_product_id']) ? $row['tier_product_id'] : 0);

                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        if (!$ids) {
            return;
        }

        // No term or meta caches: this screen reads a post title and nothing
        // else, and priming the other two would be three queries where one
        // will do.
        _prime_post_caches(array_keys($ids), false, false);
    }

    /**
     * What a tier applies to, in the owner's own terms.
     *
     * A product-scoped tier is named by its product, so an owner can go and
     * find it. `get_the_title()` rather than a join, because there are at most
     * a couple of hundred rows on this table and WordPress has already cached
     * most of the posts behind them.
     *
     * @param array $row
     * @return string
     */
    private static function tierScopeLabel($row)
    {
        $productId = (int) (isset($row['tier_product_id']) ? $row['tier_product_id'] : 0);
        $role = (string) (isset($row['tier_role']) ? $row['tier_role'] : '');

        if ($productId > 0) {
            $title = get_the_title($productId);
            $scope = $title !== ''
                ? $title
                /* translators: %d: a product id whose product has been deleted. */
                : sprintf(__('Product #%d', 'fluent-cart-bulk-order'), $productId);
        } else {
            $scope = __('Every product', 'fluent-cart-bulk-order');
        }

        if ($role === '') {
            return $scope;
        }

        return sprintf(
            /* translators: 1: what the tier applies to, e.g. a product name; 2: a user role name. */
            __('%1$s — %2$s only', 'fluent-cart-bulk-order'),
            $scope,
            self::roleLabel($role)
        );
    }

    /**
     * A role's display name, falling back to its slug.
     *
     * A role can be removed after tiers were scoped to it, and a report about
     * the past still has to name it.
     *
     * @param string $role
     * @return string
     */
    private static function roleLabel($role)
    {
        // Read once for the whole screen. wp_roles() is a singleton, but
        // get_names() rebuilds an array on every call and this runs once per
        // table row.
        static $names = null;

        if ($names === null) {
            $names = wp_roles()->get_names();
        }

        return isset($names[$role]) ? translate_user_role($names[$role]) : $role;
    }

    /**
     * The note that says which orders a forward-only panel can see.
     *
     * @param string|null $since
     * @return void
     */
    private static function renderForwardOnlyNote($since)
    {
        if ($since === null) {
            printf(
                '<div class="notice notice-info inline"><p>%s</p></div>',
                esc_html__(
                    'No bulk orders have been recorded yet. This panel fills in as orders come in — it can only see orders placed after this version of the plugin was installed, because nothing recorded which tier priced a line before then.',
                    'fluent-cart-bulk-order'
                )
            );

            return;
        }

        printf(
            '<p class="description">%s</p>',
            esc_html(
                sprintf(
                    /* translators: %s: a date, e.g. 2026-08-27. */
                    __('Counts orders placed since %s, when recording started. Orders older than that are not included here.', 'fluent-cart-bulk-order'),
                    self::date($since)
                )
            )
        );
    }

    /**
     * When these numbers were computed, and how stale they may be.
     *
     * @param array $data
     * @return void
     */
    private static function renderFootnote($data)
    {
        printf(
            '<p class="description">%s</p>',
            esc_html(
                sprintf(
                    /* translators: 1: a time, e.g. 14:05; 2: number of minutes. */
                    __('Figures computed at %1$s and refreshed every %2$s minutes.', 'fluent-cart-bulk-order'),
                    gmdate(
                        // `?:` and not the get_option() default: an option that
                        // exists but is EMPTY skips the default and would hand
                        // gmdate() a blank format, printing nothing at all.
                        (string) get_option('time_format') ?: 'H:i',
                        (int) (isset($data['generated']) ? $data['generated'] : current_time('timestamp'))
                    ),
                    number_format_i18n((int) round(Reports::CACHE_TTL / 60))
                )
            )
        );
    }

    /**
     * This screen's URL for one period.
     *
     * @param string $period
     * @return string
     */
    private static function periodUrl($period)
    {
        return add_query_arg(
            ['page' => self::PAGE_SLUG, Period::PARAM => $period],
            admin_url('options-general.php')
        );
    }

    /**
     * The period the admin asked for.
     *
     * @return string
     */
    private static function requestedPeriod()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on a capability-checked screen. @see the class docblock.
        if (!isset($_GET[Period::PARAM])) {
            return Period::DEFAULT_PERIOD;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = wp_unslash($_GET[Period::PARAM]);

        // `?period[]=x` makes this an ARRAY, and sanitize_key() handed an array
        // is a TypeError — a fatal on the admin screen from a hand-edited URL.
        // Period::sanitize() would have caught the value, but only if it ever
        // reached it.
        return is_scalar($raw) ? sanitize_key((string) $raw) : Period::DEFAULT_PERIOD;
    }

    /**
     * Cents as the store's own money string.
     *
     * Identical to OrderExportScreen::money(), and deliberately not shared with
     * it: that class is loaded by the export feature and this one by the
     * analytics feature, and making either require the other to print a number
     * would be a worse trade than five duplicated lines.
     *
     * @param mixed $cents
     * @return string
     */
    private static function money($cents)
    {
        $sign = function_exists('fcbo_get_currency_sign') ? fcbo_get_currency_sign() : '';

        return $sign . number_format_i18n(((int) $cents) / 100, 2);
    }

    /**
     * A share as a percentage string.
     *
     * @param float $share
     * @return string
     */
    private static function percent($share)
    {
        /* translators: %s: a percentage number, e.g. 42.5 */
        return sprintf(__('%s%%', 'fluent-cart-bulk-order'), number_format_i18n((float) $share, 1));
    }

    /**
     * A stored datetime, trimmed to the day.
     *
     * Printed as stored rather than converted, for the same reason
     * OrderSnapshot::date() gives: FluentCart writes these in the site's own
     * timezone, and re-converting them would show an owner a date that differs
     * from the one on the same order in FluentCart's admin.
     *
     * @param mixed $value
     * @return string
     */
    private static function date($value)
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? '' : substr($value, 0, 10);
    }
}
