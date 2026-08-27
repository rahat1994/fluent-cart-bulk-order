<?php

namespace FluentCartBulkOrder\Wholesale;

defined('ABSPATH') || exit;

/**
 * The front-end submission handler: one POST, one redirect, no JavaScript.
 *
 * ---------------------------------------------------------------------------
 * WHY admin-post.php AND NOT REST OR ADMIN-AJAX
 * ---------------------------------------------------------------------------
 *
 * This form is a plain HTML form. It has no live search, no dynamic rows and
 * nothing to update without a page load — so it needs no script at all, and a
 * surface with no script is a surface that works with JavaScript disabled, in
 * a page cache, and behind a security plugin that blocks the REST API.
 *
 * `admin-post.php` is the WordPress endpoint for exactly this: an authenticated
 * form POST that ends in a redirect. Post-Redirect-Get, so a browser refresh
 * after submitting does not re-submit.
 *
 * The REST routes in this plugin exist because their callers are JavaScript.
 * This one has no JavaScript, so it does not.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR THINGS THIS HANDLER CHECKS, IN ORDER
 * ---------------------------------------------------------------------------
 *
 *   1. The request is a POST. GET is refused outright — a request that can be
 *      made by following a link can be made by a prefetch, an image tag or a
 *      link in an email.
 *   2. A valid nonce for this action and this user.
 *   3. A logged-in user. There is no `admin_post_nopriv_` sibling that does
 *      any work: a logged-out submission cannot be attached to anyone, and
 *      building public registration into this form is a spam surface that
 *      deserves its own decision. @see refuseLoggedOut()
 *   4. The state machine allows this user to apply at all.
 *
 * NONE of these check what the applicant asked for. The status a submission
 * lands on comes from ApplicationStatus, not from the request, and the fields
 * that reach storage come from the owner's schema, not from the post body.
 * @see ApplicationInput::validate()
 */
class ApplicationController
{
    /**
     * Query arg carrying the outcome back to the page the form is on.
     */
    const RESULT_ARG = 'fcbo_wholesale';

    /**
     * How long the redirect's feedback survives, in seconds.
     *
     * Field errors and the applicant's own typing have to cross a redirect, and
     * a query string cannot carry them (they are shopper text, and a URL is
     * shared, logged and cached). A short-lived transient can.
     *
     * Sixty seconds is one page load's worth. Long enough that a slow redirect
     * still finds it, short enough that it cannot show up on a later visit and
     * confuse someone with errors from a form they already fixed.
     */
    const FEEDBACK_TTL = 60;

    /**
     * Handle a submission.
     *
     * @return void Always ends in a redirect or wp_die().
     */
    public static function handle()
    {
        if (!self::isPost()) {
            wp_die(
                esc_html__('This page cannot be opened directly.', 'fluent-cart-bulk-order'),
                '',
                ['response' => 405]
            );
        }

        // check_admin_referer() dies on a bad or missing nonce, which is the
        // right outcome: there is no partial success to redirect to, and a
        // silent redirect would hide a CSRF attempt from the site owner.
        check_admin_referer(WholesaleFlow::NONCE_APPLY);

        $userId = get_current_user_id();

        if (!$userId) {
            self::refuseLoggedOut();
        }

        $redirect = self::redirectTarget();

        // Someone who already holds the role, however they got it, has nothing
        // to apply for. Checked before the state machine because a user given
        // the role by hand in wp-admin has no application record at all.
        if (ApplicationStore::userHasWholesaleRole($userId)) {
            self::finish($redirect, 'already_approved');
        }

        $status = ApplicationStore::statusFor($userId);

        if (!ApplicationStatus::canApply($status)) {
            self::finish($redirect, 'already_approved');
        }

        $fields     = ApplicationSettings::fields();
        $sanitizers = ApplicationStore::sanitizers();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $submitted = isset($_POST['fcbo_field']) && is_array($_POST['fcbo_field'])
            ? wp_unslash($_POST['fcbo_field'])
            : [];

        $result = ApplicationInput::validate($fields, $submitted, $sanitizers[0], $sanitizers[1]);

        if (!empty($result['errors'])) {
            // The applicant's own answers go back with the errors so a missing
            // tax ID does not cost them everything else they typed.
            self::rememberFeedback($userId, [
                'errors' => $result['errors'],
                'values' => $result['values'],
            ]);

            self::finish($redirect, 'invalid');
        }

        $outcome = ApplicationStatus::applyOutcome($status);
        $record  = ApplicationStore::saveApplication($userId, $result['values']);

        if (!$record) {
            self::finish($redirect, 'error');
        }

        self::finish(
            $redirect,
            $outcome === ApplicationStatus::OUTCOME_UPDATED ? 'updated' : 'submitted'
        );
    }

    /**
     * What a logged-out POST gets.
     *
     * Registered on `admin_post_nopriv_` so the action exists rather than
     * falling through to admin-post.php's blank 400 — but it deliberately does
     * no work. Public registration is out of scope for this feature: a form
     * that creates accounts is a spam and enumeration surface with its own
     * design questions (email confirmation, moderation, rate limiting), and
     * bolting it onto a role-granting form is how those questions get skipped.
     *
     * @return void Never returns.
     */
    public static function refuseLoggedOut()
    {
        wp_die(
            esc_html__('Please log in before applying for a wholesale account.', 'fluent-cart-bulk-order'),
            '',
            ['response' => 401]
        );
    }

    /**
     * Feedback stashed for one user by the last submission, then cleared.
     *
     * Read-and-delete in one call so a refresh after reading shows the clean
     * form rather than yesterday's errors.
     *
     * @param int $userId
     * @return array{errors: array<string, string>, values: array<string, mixed>}
     */
    public static function takeFeedback($userId)
    {
        $empty = ['errors' => [], 'values' => []];
        $userId = (int) $userId;

        if ($userId <= 0) {
            return $empty;
        }

        $key  = self::feedbackKey($userId);
        $data = get_transient($key);

        if (!is_array($data)) {
            return $empty;
        }

        delete_transient($key);

        return [
            'errors' => isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [],
            'values' => isset($data['values']) && is_array($data['values']) ? $data['values'] : [],
        ];
    }

    /**
     * The outcome code in the current request's query string, if any.
     *
     * Read-only and allow-listed: the value is only ever compared against the
     * codes this class emits, never printed. A crafted `?fcbo_wholesale=<img>`
     * therefore has nothing to land in.
     *
     * @return string One of the known codes, or ''.
     */
    public static function resultCode()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display state.
        if (empty($_GET[self::RESULT_ARG])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code = sanitize_key(wp_unslash($_GET[self::RESULT_ARG]));

        $known = ['submitted', 'updated', 'invalid', 'error', 'already_approved'];

        return in_array($code, $known, true) ? $code : '';
    }

    /**
     * Stash the feedback for the redirect that follows.
     *
     * @param int   $userId
     * @param array $data
     * @return void
     */
    private static function rememberFeedback($userId, array $data)
    {
        set_transient(self::feedbackKey($userId), $data, self::FEEDBACK_TTL);
    }

    /**
     * Transient name for one user's feedback.
     *
     * Keyed by user id, so two people applying at once cannot read each other's
     * half-typed answers back out of a shared key.
     *
     * @param int $userId
     * @return string
     */
    private static function feedbackKey($userId)
    {
        return 'fcbo_wholesale_feedback_' . (int) $userId;
    }

    /**
     * Whether this really is a POST request.
     *
     * @return bool
     */
    private static function isPost()
    {
        return isset($_SERVER['REQUEST_METHOD'])
            && strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) === 'POST';
    }

    /**
     * Where to send the applicant back to.
     *
     * The form carries the page it was rendered on, because the application
     * shortcode can sit on any page and admin-post.php has no idea which. The
     * value is passed through `wp_validate_redirect()` with the site home as
     * the fallback, so a tampered field can only ever redirect within this
     * site — an open redirect on a form that names the store would be a
     * ready-made phishing hop.
     *
     * @return string
     */
    private static function redirectTarget()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handle().
        $raw = isset($_POST['fcbo_redirect_to']) ? esc_url_raw(wp_unslash($_POST['fcbo_redirect_to'])) : '';

        return wp_validate_redirect($raw, home_url('/'));
    }

    /**
     * Redirect back with the outcome code, and stop.
     *
     * @param string $redirect
     * @param string $code
     * @return void Never returns.
     */
    private static function finish($redirect, $code)
    {
        wp_safe_redirect(add_query_arg(self::RESULT_ARG, $code, $redirect));
        exit;
    }
}
