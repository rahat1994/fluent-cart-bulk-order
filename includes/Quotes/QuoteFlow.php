<?php

namespace FluentCartBulkOrder\Quotes;

use FluentCartBulkOrder\Admin\Menu;

defined('ABSPATH') || exit;

/**
 * The one place the Quote Request flow attaches itself to WordPress.
 *
 * ---------------------------------------------------------------------------
 * WHY A BOOTSTRAP CLASS RATHER THAN HOOKS IN THE MAIN PLUGIN FILE
 * ---------------------------------------------------------------------------
 *
 * fluent-cart-bulk-order.php is header, constants, hook wiring, loaders and
 * thin delegates, and it is that way after a deliberate cut (issue #22). This
 * feature needs five hooks and eight classes; wiring them there would put a
 * loader function per class in a file whose whole point is not to grow.
 *
 * So the main file gets two lines — require this, call register() — and this
 * class owns the rest. Same shape as WholesaleFlow and ShortcodeHandler: one
 * registry, read end to end, with the heavy classes loaded only when a hook
 * actually fires.
 *
 * ---------------------------------------------------------------------------
 * NOTHING HERE IS LOADED UNTIL IT IS NEEDED
 * ---------------------------------------------------------------------------
 *
 * register() adds hooks and does nothing else. Every callback below calls
 * load() first, so on an ordinary storefront page load with no bulk order form
 * on it, the cost of this whole feature is parsing THIS file plus registering
 * one post type.
 *
 * That is also why every callback is a method of THIS class rather than of the
 * class it delegates to. Hooking `QuoteNotifier` directly would mean the flow's
 * own actions could fire before anything had required QuoteNotifier.php, and
 * WordPress answers an uncallable callback with a fatal. @see register().
 *
 * The REQUEST endpoint is not registered here. It is a REST route, and it lives
 * in \FluentCartBulkOrder\Rest\Routes with the others, so there is still exactly
 * one table of the URLs this plugin answers.
 */
class QuoteFlow
{
    /**
     * The `action` value the admin pricing / convert / decline buttons post.
     *
     * The action name lives HERE, on the registry, and not on the handler class
     * — for the same reason the shortcode tags live on ShortcodeHandler rather
     * than on each shortcode. register() has to name it before any handler class
     * is loaded, so a constant on the handler would force this file to load the
     * class it was written to defer.
     */
    const ACTION_REVIEW = 'fcbo_quote_review';

    /**
     * Nonce action for an owner's decision.
     *
     * Deliberately its own string, shared with nothing. A nonce is scoped to its
     * action, so keeping this apart from the wholesale flow's nonces means one
     * cannot be replayed against the other.
     */
    const NONCE_REVIEW = 'fcbo_quote_review';

    /**
     * Wire the flow into WordPress.
     *
     * Called from the `plugins_loaded` handler in the main plugin file, inside
     * the FluentCart-is-active guard — a quote is a request to buy products the
     * host plugin owns, and converting one creates a FluentCart order.
     *
     * @return void
     */
    public static function register()
    {
        // The post type. Registered UNCONDITIONALLY, on every request, and that
        // is not an oversight: get_post() will not return a post of an
        // unregistered type in a way this plugin can trust, so a quote that
        // exists must be readable wherever a hook of ours can fire — including
        // a WP-Cron mail run or a WP-CLI command that never touches admin.
        add_action('init', [self::class, 'registerPostType']);

        // The owner's decision. NO `nopriv` sibling, deliberately: a logged-out
        // POST to this action must fall through to admin-post.php's own refusal
        // rather than reaching any code of ours.
        add_action('admin_post_' . self::ACTION_REVIEW, [self::class, 'handleReview']);

        // Notifications, on the flow's own two actions.
        //
        // ---------------------------------------------------------------
        // EVERY CALLBACK IS A METHOD OF *THIS* CLASS, AND THAT IS THE POINT
        // ---------------------------------------------------------------
        //
        // The obvious version hooks QuoteNotifier directly. It fatals.
        // `fcbo/quotes/requested` fires from inside QuoteStore, and a caller
        // that loaded QuoteStore without going through load() — a site snippet,
        // a WP-CLI script, a future entry point — makes WordPress try to call a
        // class that was never required. A white screen during a quote
        // submission is worse than a missing email.
        add_action('fcbo/quotes/requested', [self::class, 'notifyRequested'], 10, 2);
        add_action('fcbo/quotes/decided', [self::class, 'notifyDecided'], 10, 3);

        // The review screen. `is_admin()` keeps its class off every front-end
        // page load; the capability that actually protects it is checked twice
        // inside the class. @see QuoteReviewScreen
        if (is_admin()) {
            // The priority is the submenu position under Bulk Order.
            // @see \FluentCartBulkOrder\Admin\Menu
            add_action('admin_menu', [self::class, 'registerReviewScreen'], Menu::priority(Menu::SLUG_QUOTES));
        }
    }

    /**
     * Register the quote post type.
     *
     * @return void
     */
    public static function registerPostType()
    {
        require_once __DIR__ . '/QuoteStatus.php';
        require_once __DIR__ . '/QuoteStore.php';

        QuoteStore::registerPostType();
    }

    /**
     * Email the site admin about a new quote request.
     *
     * @param int                  $quoteId
     * @param array<string, mixed> $record
     * @return void
     */
    public static function notifyRequested($quoteId, $record)
    {
        self::load();
        QuoteNotifier::onRequested($quoteId, $record);
    }

    /**
     * Email the buyer about a decision.
     *
     * @param int                  $quoteId
     * @param array<string, mixed> $record
     * @param string               $status
     * @return void
     */
    public static function notifyDecided($quoteId, $record, $status)
    {
        self::load();
        QuoteNotifier::onDecided($quoteId, $record, $status);
    }

    /**
     * Owner decision POST.
     *
     * @return void
     */
    public static function handleReview()
    {
        self::load();
        QuoteReviewScreen::handleDecision();
    }

    /**
     * Add the review screen to the Settings menu.
     *
     * @return void
     */
    public static function registerReviewScreen()
    {
        self::load();
        QuoteReviewScreen::addMenu();
    }

    /**
     * Load the classes the flow needs.
     *
     * require_once throughout, so this is safe to call from every entry point
     * and harmless when the REST controller has already loaded the same set.
     *
     * @return void
     */
    public static function load()
    {
        require_once __DIR__ . '/QuoteStatus.php';
        require_once __DIR__ . '/QuoteInput.php';
        require_once __DIR__ . '/QuoteSettings.php';
        require_once __DIR__ . '/QuoteStore.php';
        require_once __DIR__ . '/QuoteOrder.php';
        require_once __DIR__ . '/QuoteReviewScreen.php';
        require_once __DIR__ . '/QuoteNotifier.php';
    }
}
