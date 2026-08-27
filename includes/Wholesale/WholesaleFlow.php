<?php

namespace FluentCartBulkOrder\Wholesale;

defined('ABSPATH') || exit;

/**
 * The one place the wholesale application flow attaches itself to WordPress.
 *
 * ---------------------------------------------------------------------------
 * WHY A BOOTSTRAP CLASS RATHER THAN HOOKS IN THE MAIN PLUGIN FILE
 * ---------------------------------------------------------------------------
 *
 * fluent-cart-bulk-order.php is header, constants, hook wiring, loaders and
 * thin delegates, and it is that way after a deliberate 41% cut. This feature
 * needs eight hooks and seven classes; wiring them there would put a loader
 * function per class in a file whose whole point is not to grow.
 *
 * So the main file gets two lines — require this, call register() — and this
 * class owns the rest. Same shape as ShortcodeHandler: one registry, read end
 * to end, with the heavy classes loaded only when a hook actually fires.
 *
 * ---------------------------------------------------------------------------
 * NOTHING HERE IS LOADED UNTIL IT IS NEEDED
 * ---------------------------------------------------------------------------
 *
 * register() adds hooks and does nothing else. Every callback below calls
 * load() first, so on an ordinary storefront page load — no form, no admin, no
 * submission — the cost of this whole feature is parsing THIS file.
 *
 * That is also why every callback is a method of THIS class rather than of the
 * class it delegates to. Hooking `Notifier` directly would mean the flow's own
 * actions could fire before anything had required Notifier.php, and WordPress
 * answers an uncallable callback with a fatal. @see register().
 *
 * The shortcode is not registered here. It lives in
 * ShortcodeHandler::SHORTCODES with the other tags, so there is still exactly
 * one list of the shortcodes this plugin owns.
 */
class WholesaleFlow
{
    /**
     * The `action` value the front-end form posts.
     *
     * The action names live HERE, on the registry, and not on the handler
     * classes — for the same reason the shortcode tags live on ShortcodeHandler
     * rather than on each shortcode. register() has to name them before any
     * handler class is loaded, so a constant on the handler would force this
     * file to load every class it was written to defer.
     */
    const ACTION_APPLY = 'fcbo_wholesale_apply';

    /**
     * Nonce action for the front-end form.
     *
     * Deliberately a different string from NONCE_REVIEW. A nonce is scoped to
     * its action, so keeping the two apart means an applicant's own valid apply
     * nonce can never be replayed against the admin decision endpoint.
     */
    const NONCE_APPLY = 'fcbo_wholesale_apply';

    /**
     * The `action` value the admin approve/reject buttons post.
     */
    const ACTION_REVIEW = 'fcbo_wholesale_review';

    /**
     * Nonce action for an admin decision. @see NONCE_APPLY for why it differs.
     */
    const NONCE_REVIEW = 'fcbo_wholesale_review';

    /**
     * The FluentCRM bridge, named as a STRING rather than with `::class`.
     *
     * `ContactTagger::class` is resolved at parse time and would pull the file
     * into every request just to name it. A string names it without loading it.
     * @see \FluentCartBulkOrder\Integrations\FluentCrm\ContactTagger
     */
    const CRM_TAGGER = 'FluentCartBulkOrder\\Integrations\\FluentCrm\\ContactTagger';

    /**
     * Wire the flow into WordPress.
     *
     * Called from the `plugins_loaded` handler in the main plugin file, inside
     * the FluentCart-is-active guard — this is a FluentCart wholesale feature,
     * and a store without FluentCart has no wholesale prices for the role to
     * unlock.
     *
     * @return void
     */
    public static function register()
    {
        // The applicant's own submission. Two registrations because
        // admin-post.php dispatches logged-in and logged-out requests to
        // different hook names, and a missing `nopriv` sibling answers a
        // logged-out POST with a blank page rather than a reason.
        add_action('admin_post_' . self::ACTION_APPLY, [self::class, 'handleApply']);
        add_action('admin_post_nopriv_' . self::ACTION_APPLY, [self::class, 'refuseLoggedOut']);

        // The admin decision. NO `nopriv` sibling, deliberately: a logged-out
        // POST to this action must fall through to admin-post.php's own refusal
        // rather than reaching any code of ours.
        add_action('admin_post_' . self::ACTION_REVIEW, [self::class, 'handleReview']);

        // Notifications and FluentCRM tagging, on the flow's own two actions.
        //
        // ---------------------------------------------------------------
        // EVERY CALLBACK IS A METHOD OF *THIS* CLASS, AND THAT IS THE POINT
        // ---------------------------------------------------------------
        //
        // The obvious version hooks Notifier and ContactTagger directly. It
        // fatals. `fcbo/wholesale/application_submitted` fires from inside
        // ApplicationStore, and a caller that loaded ApplicationStore without
        // going through load() — a site snippet, a WP-CLI script, a future
        // entry point — makes WordPress try to call a class that was never
        // required. "Undefined class" during an application submission is a
        // white screen, not a missing email.
        //
        // Routing through this class removes the assumption entirely: every
        // callback below loads first and delegates second, exactly like the
        // request handlers do. That is also why the callbacks are separate
        // rather than one per action — a site can still remove_action() the
        // email without losing the CRM tag, or the other way round.
        add_action('fcbo/wholesale/application_submitted', [self::class, 'notifySubmitted'], 10, 3);
        add_action('fcbo/wholesale/application_reviewed', [self::class, 'notifyReviewed'], 10, 3);

        // FluentCRM tagging runs AFTER the emails, and is registered
        // UNCONDITIONALLY even on the overwhelming majority of stores that have
        // no FluentCRM. Checking here would be checking at the wrong moment:
        // FLUENTCRM is defined when FluentCRM's main file is parsed, but its
        // `FluentCrmApi()` helper may not be loaded yet on `plugins_loaded`, so
        // a check now can be a false negative that silently disables the
        // integration for the whole request. The guard belongs at CALL time and
        // lives on ContactTagger, which exits before loading or querying
        // anything when FluentCRM is absent or no tag has been chosen.
        add_action('fcbo/wholesale/application_submitted', [self::class, 'tagSubmitted'], 20, 3);
        add_action('fcbo/wholesale/application_reviewed', [self::class, 'tagReviewed'], 20, 3);

        // The review screen. `is_admin()` keeps its class off every front-end
        // page load; the capability that actually protects it is checked twice
        // inside the class. @see ReviewScreen
        if (is_admin()) {
            add_action('admin_menu', [self::class, 'registerReviewScreen']);
        }
    }

    /**
     * Email the site admin about a new application.
     *
     * @param int                  $userId
     * @param array<string, mixed> $record
     * @param string               $outcome
     * @return void
     */
    public static function notifySubmitted($userId, $record, $outcome)
    {
        self::load();
        Notifier::onSubmitted($userId, $record, $outcome);
    }

    /**
     * Email the applicant about a decision.
     *
     * @param int                  $userId
     * @param array<string, mixed> $record
     * @param string               $status
     * @return void
     */
    public static function notifyReviewed($userId, $record, $status)
    {
        self::load();
        Notifier::onReviewed($userId, $record, $status);
    }

    /**
     * Tag the applicant's FluentCRM contact on submission.
     *
     * The class name is a STRING constant rather than a `::class` reference,
     * so naming it here does not drag the file into a page load that will
     * never reach this method. load() has required it by the time the call is
     * made. @see self::CRM_TAGGER
     *
     * @param int                  $userId
     * @param array<string, mixed> $record
     * @param string               $outcome
     * @return void
     */
    public static function tagSubmitted($userId, $record, $outcome)
    {
        self::load();
        call_user_func([self::CRM_TAGGER, 'onSubmitted'], $userId, $record, $outcome);
    }

    /**
     * Tag the applicant's FluentCRM contact on approval.
     *
     * @param int                  $userId
     * @param array<string, mixed> $record
     * @param string               $status
     * @return void
     */
    public static function tagReviewed($userId, $record, $status)
    {
        self::load();
        call_user_func([self::CRM_TAGGER, 'onReviewed'], $userId, $record, $status);
    }

    /**
     * Admin decision POST.
     *
     * @return void
     */
    public static function handleReview()
    {
        self::load();
        ReviewScreen::handleDecision();
    }

    /**
     * Add the review screen to the Users menu.
     *
     * @return void
     */
    public static function registerReviewScreen()
    {
        self::load();
        ReviewScreen::addMenu();
    }

    /**
     * Front-end submission.
     *
     * @return void
     */
    public static function handleApply()
    {
        self::load();
        ApplicationController::handle();
    }

    /**
     * Logged-out submission. @see ApplicationController::refuseLoggedOut()
     *
     * @return void
     */
    public static function refuseLoggedOut()
    {
        self::load();
        ApplicationController::refuseLoggedOut();
    }

    /**
     * Load the classes the flow needs.
     *
     * require_once throughout, so this is safe to call from every entry point
     * and harmless when the shortcode has already loaded the same set.
     *
     * @return void
     */
    private static function load()
    {
        require_once __DIR__ . '/ApplicationStatus.php';
        require_once __DIR__ . '/ApplicationSchema.php';
        require_once __DIR__ . '/ApplicationInput.php';
        require_once __DIR__ . '/ApplicationSettings.php';
        require_once __DIR__ . '/ApplicationStore.php';
        require_once __DIR__ . '/ApplicationController.php';
        require_once __DIR__ . '/ReviewScreen.php';
        require_once __DIR__ . '/Notifier.php';

        // The FluentCRM bridge loads with the rest, because the actions it
        // listens on fire from ApplicationStore on exactly the paths that call
        // load(). The file names no FluentCRM class at parse time, so loading
        // it on a store without FluentCRM is safe and costs one parse.
        require_once dirname(__DIR__) . '/Integrations/FluentCrm/ContactTagger.php';
    }
}
