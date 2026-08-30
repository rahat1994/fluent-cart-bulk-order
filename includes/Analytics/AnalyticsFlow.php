<?php

namespace FluentCartBulkOrder\Analytics;

use FluentCartBulkOrder\Admin\Menu;

defined('ABSPATH') || exit;

/**
 * The one place owner analytics attaches itself to WordPress.
 *
 * ---------------------------------------------------------------------------
 * A STORE THAT NEVER OPENS THE SCREEN PAYS FOR THREE add_action() CALLS
 * ---------------------------------------------------------------------------
 *
 * register() wires three hooks and reads one autoloaded option. Nothing else
 * loads until something actually fires: the recorder is required when a
 * checkout completes, and the screen is required by the one admin request that
 * draws it. Not one report query runs anywhere but on that page.
 *
 * Those two loads are separate methods and not one, because a checkout must not
 * pay for the admin screen. @see loadRecorder().
 *
 * The one unconditional cost is AttributionStore::ensureInstalled(), which is a
 * comparison against an autoloaded option — no query on the ordinary path. It
 * is here rather than only in the activation hook because a store that already
 * had this plugin active when analytics shipped never runs activation again,
 * and would otherwise record into a table that does not exist.
 *
 * ---------------------------------------------------------------------------
 * EVERY CALLBACK IS A METHOD OF *THIS* CLASS
 * ---------------------------------------------------------------------------
 *
 * Same rule as QuoteFlow and PoNumberFlow, for the same reason: hooking
 * OrderAttribution directly would let a hook fire before anything had required
 * OrderAttribution.php, and WordPress answers an uncallable callback with a
 * fatal. A white screen at checkout is worse than a missing statistic — and
 * this feature is a statistic.
 *
 * That is also why recordCheckout() swallows its own failures. Analytics must
 * never be the reason a sale does not complete.
 *
 * @see \FluentCartBulkOrder\Analytics\OrderAttribution The recording half.
 * @see \FluentCartBulkOrder\Analytics\AnalyticsScreen The reporting half.
 */
class AnalyticsFlow
{
    /**
     * Wire analytics into WordPress and FluentCart.
     *
     * Called from the `plugins_loaded` handler in the main plugin file, inside
     * the FluentCart-is-active guard.
     *
     * @return void
     */
    public static function register()
    {
        // The only file this feature loads on an ordinary page load, and the
        // smallest one. It has no dependencies of its own precisely so that
        // this line does not drag the rest of the namespace in with it.
        require_once __DIR__ . '/AttributionStore.php';

        AttributionStore::ensureInstalled();

        // The same hook the PO number writes on, and for the same reason: the
        // order and its items exist, and the raw submission carrying the
        // surface marker is still in hand.
        add_action('fluent_cart/checkout/prepare_other_data', [self::class, 'recordCheckout']);

        // A converted quote never passes through checkout, so it is caught
        // where this plugin already announces the decision.
        // @see \FluentCartBulkOrder\Quotes\QuoteStore::decide()
        add_action('fcbo/quotes/decided', [self::class, 'recordQuoteOrder'], 10, 3);

        // The marker on the checkout form. Registered unconditionally because
        // it costs one array lookup on a request that is already rendering a
        // checkout, and emits nothing at all when there is no marker to carry.
        add_action('fluent_cart/before_payment_methods', [self::class, 'renderSurfaceField']);

        if (is_admin()) {
            // The priority is the submenu position under Bulk Order.
            // @see \FluentCartBulkOrder\Admin\Menu
            add_action('admin_menu', [self::class, 'registerScreen'], Menu::priority(Menu::SLUG_ANALYTICS));
        }
    }

    /**
     * Record an order created at checkout.
     *
     * @param mixed $data ['order' => Order, 'request_data' => array, ...]
     * @return void
     */
    public static function recordCheckout($data)
    {
        self::loadRecorder();

        // A failed statistic must never fail a sale. This runs mid-checkout,
        // between FluentCart creating the draft order and finalising it, so an
        // exception here — a missing table, a database that has gone away —
        // would surface to the shopper as a broken checkout for the sake of a
        // row on a report nobody is looking at yet.
        // \Throwable and not \Exception: PHP 7 splits errors from exceptions,
        // and a TypeError from a host upgrade changing a payload shape is an
        // Error. Catching only Exception would let exactly the failure mode
        // this guard exists for through.
        try {
            OrderAttribution::recordCheckout($data);
        } catch (\Throwable $e) {
            self::logFailure($e);
        }
    }

    /**
     * Record an order created by converting an accepted quote.
     *
     * @param mixed $quoteId
     * @param mixed $record
     * @param mixed $status
     * @return void
     */
    public static function recordQuoteOrder($quoteId, $record, $status)
    {
        self::loadRecorder();

        try {
            OrderAttribution::recordQuoteOrder($quoteId, $record, $status);
        } catch (\Throwable $e) {
            self::logFailure($e);
        }
    }

    /**
     * Carry the surface marker from the checkout URL into the checkout POST.
     *
     * A hidden field rather than anything cleverer, and rendered on the same
     * unconditional, both-renderers hook the PO number field uses. Nothing is
     * emitted when the URL carries no recognised marker, so an ordinary
     * checkout is byte-for-byte what it was.
     *
     * @return void
     */
    public static function renderSurfaceField()
    {
        // Only the one small pure class, not self::load(). This fires on every
        // checkout render, and pulling in the report queries and the admin
        // screen to decide whether to print a hidden input would be a real cost
        // on the store's most important page.
        require_once __DIR__ . '/Surface.php';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reading a display-only marker on a public checkout page; it authorises nothing, and it is sanitized two lines down after the is_scalar() guard, because sanitize_key() handed `?fcbo_src[]=x` would fatal.
        $raw = isset($_GET[Surface::PARAM]) ? wp_unslash($_GET[Surface::PARAM]) : '';

        // This one IS a superglobal, unlike the checkout payload the recorder
        // reads, so it does need wp_unslash(). Surface::sanitize() then maps it
        // onto a closed list, so nothing a shopper can type reaches the field.
        $source = Surface::sanitize(is_scalar($raw) ? sanitize_key((string) $raw) : '');

        if ($source === '') {
            return;
        }

        printf(
            '<input type="hidden" name="%1$s" value="%2$s" />',
            esc_attr(Surface::PARAM),
            esc_attr($source)
        );
    }

    /**
     * Add the analytics screen to the Settings menu.
     *
     * @return void
     */
    public static function registerScreen()
    {
        // Only the screen class, not the whole feature. `admin_menu` fires on
        // EVERY wp-admin request for every logged-in user, and adding a menu
        // entry needs nothing but a slug and a capability. Every query this
        // feature runs is loaded by render(), on the one page that draws them.
        require_once __DIR__ . '/AnalyticsScreen.php';

        AnalyticsScreen::addMenu();
    }

    /**
     * Load only what RECORDING an order needs.
     *
     * Split from load() deliberately. Recording runs on every FluentCart
     * checkout — a shopper's request, on the store's most important page — and
     * it never touches the report queries or the admin screen. Requiring those
     * two files there would put tens of kilobytes of admin-only code into every
     * checkout to decide, most of the time, that there is nothing to record.
     *
     * The report side is loaded by load(), from the one admin request that
     * draws the screen.
     *
     * @return void
     */
    public static function loadRecorder()
    {
        require_once dirname(__DIR__) . '/Pricing/OrderRules.php';
        require_once dirname(__DIR__) . '/Pricing/Tiers.php';
        require_once dirname(__DIR__) . '/Pricing/FeedResolver.php';
        require_once __DIR__ . '/Surface.php';
        require_once __DIR__ . '/TierSignature.php';
        require_once __DIR__ . '/AttributionStore.php';
        require_once __DIR__ . '/OrderAttribution.php';
    }

    /**
     * Load everything the SCREEN needs, recorder included.
     *
     * require_once throughout, so every entry point can call it and calling it
     * after loadRecorder() costs nothing.
     *
     * @return void
     */
    public static function load()
    {
        self::loadRecorder();

        require_once __DIR__ . '/Period.php';
        require_once __DIR__ . '/TierUsage.php';
        require_once __DIR__ . '/RevenueSplit.php';
        require_once __DIR__ . '/Reports.php';
        require_once __DIR__ . '/AnalyticsScreen.php';
    }

    /**
     * Note a swallowed failure where a developer can find it.
     *
     * Only when the site has debug logging on, because a store owner does not
     * need a PHP notice about a report row and a shared host does not need the
     * disk write.
     *
     * @param \Throwable $e
     * @return void
     */
    private static function logFailure($e)
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- guarded by WP_DEBUG; this is the diagnostic for a deliberately swallowed failure.
        error_log('FCBO analytics: could not record order attribution — ' . $e->getMessage());
    }
}
