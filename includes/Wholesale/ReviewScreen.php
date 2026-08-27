<?php

namespace FluentCartBulkOrder\Wholesale;

defined('ABSPATH') || exit;

/**
 * The admin screen where wholesale applications are approved or rejected.
 *
 * ---------------------------------------------------------------------------
 * WHY EVERY ACTION HERE IS A POST
 * ---------------------------------------------------------------------------
 *
 * Approving grants a privileged role. An "Approve" link — a GET with a nonce in
 * the query string — is triggerable by anything that follows a URL an admin's
 * browser has: a prefetch, a link scanner in an email client, a `<img src>` on
 * a page the admin visits, a browser extension warming links. None of those
 * involve the admin deciding anything.
 *
 * So each button is its own `<form method="post">`. A POST is not issued by any
 * of those, and the nonce travels in the body rather than in a URL that ends up
 * in browser history, server logs and referrer headers.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR CHECKS ON A DECISION, IN ORDER
 * ---------------------------------------------------------------------------
 *
 *   1. POST only.                        handleDecision()
 *   2. Valid nonce for THIS action.      check_admin_referer()
 *   3. `manage_options`.                 checked separately from the nonce,
 *                                        because a nonce proves who sent the
 *                                        request, not what they may do.
 *   4. A legal transition.               ApplicationStatus::canTransition()
 *
 * The screen itself repeats check 3 rather than trusting the menu capability.
 * add_users_page() hides the menu item from users without the capability but
 * does not stop a direct URL, and `admin.php?page=` dispatch has been the
 * source of enough plugin vulnerabilities to be worth two lines.
 *
 * This mirrors the standard the REST routes hold themselves to.
 * @see \FluentCartBulkOrder\Rest\Routes
 */
class ReviewScreen
{
    /**
     * Admin page slug.
     */
    const PAGE_SLUG = 'fcbo-wholesale-applications';

    /**
     * The capability required to see this screen and to decide anything on it.
     *
     * `manage_options`, matching the plugin's settings page. Granting a role is
     * at least as consequential as changing a pricing policy, so it does not
     * get a weaker gate.
     */
    const CAPABILITY = 'manage_options';

    /**
     * Applications per page.
     */
    const PER_PAGE = 20;

    /**
     * Add the menu entry.
     *
     * Under Users, not under Settings: an application is about a person, and
     * that is where an admin goes to look at people. The settings for the
     * feature stay on the plugin's own settings page.
     *
     * @return void
     */
    public static function addMenu()
    {
        $pending = ApplicationStore::countByStatus(ApplicationStatus::PENDING);

        $title = __('Wholesale Applications', 'fluent-cart-bulk-order');

        // The count bubble is the whole discovery mechanism for this screen. An
        // owner who never notices an application has a feature that does not
        // work, whatever the code does.
        $menuTitle = $pending > 0
            ? $title . ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pending . '</span></span>'
            : $title;

        add_users_page(
            $title,
            $menuTitle,
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Render the screen.
     *
     * @return void
     */
    public static function render()
    {
        // Not redundant with the menu capability. add_users_page() controls
        // visibility; this controls access.
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to review wholesale applications.', 'fluent-cart-bulk-order'));
        }

        $status = self::requestedStatus();
        $page   = self::requestedPage();
        $result = ApplicationStore::page($status, $page, self::PER_PAGE);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Wholesale Applications', 'fluent-cart-bulk-order') . '</h1>';

        self::renderResultNotice();
        self::renderTabs($status);

        if (!$result['users']) {
            echo '<p>' . esc_html__('No applications to show.', 'fluent-cart-bulk-order') . '</p>';
            echo '</div>';

            return;
        }

        self::renderTable($result['users']);
        self::renderPagination($status, $page, $result['total']);

        echo '</div>';
    }

    /**
     * Handle an approve or reject submission.
     *
     * Runs on `admin_post_`, NOT inside render(): a decision must not be
     * processed while a page is being drawn, or a refresh of the results page
     * replays it. Post-Redirect-Get here as well.
     *
     * @return void Always ends in a redirect or wp_die().
     */
    public static function handleDecision()
    {
        if (!isset($_SERVER['REQUEST_METHOD'])
            || strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) !== 'POST') {
            wp_die(
                esc_html__('This action must be submitted from the review screen.', 'fluent-cart-bulk-order'),
                '',
                ['response' => 405]
            );
        }

        check_admin_referer(WholesaleFlow::NONCE_REVIEW);

        // A valid nonce says the request came from this admin's browser. It
        // says nothing about what that admin may do, which is why the
        // capability is checked on its own.
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html__('You do not have permission to review wholesale applications.', 'fluent-cart-bulk-order'),
                '',
                ['response' => 403]
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $userId = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        $status = ApplicationStatus::statusForDecision($decision);

        if (!$userId || $status === null) {
            self::finish('error');
        }

        // Capped so a reviewer's note cannot make the applicant's user meta row
        // arbitrarily large. The note is also emailed, and shown on a public
        // page, so a bounded length is the friendlier answer anyway.
        $note = function_exists('mb_substr') ? mb_substr($note, 0, 1000) : substr($note, 0, 1000);

        $record = ApplicationStore::review($userId, $status, get_current_user_id(), $note);

        // Null means the state machine refused — the application was already
        // decided, or the user is gone. Not an error the admin can fix, and
        // deliberately NOT a fatal: a double-clicked button lands here.
        if (!$record) {
            self::finish('refused');
        }

        self::finish($status === ApplicationStatus::APPROVED ? 'approved' : 'rejected');
    }

    /**
     * The status tab the admin asked for.
     *
     * Defaults to pending, because that is the only status with anything to do.
     *
     * @return string An ApplicationStatus constant, or '' for "all".
     */
    private static function requestedStatus()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on a capability-checked screen.
        if (!isset($_GET['status'])) {
            return ApplicationStatus::PENDING;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status = sanitize_key(wp_unslash($_GET['status']));

        if ($status === 'all') {
            return '';
        }

        return ApplicationStatus::isStorable($status) ? $status : ApplicationStatus::PENDING;
    }

    /**
     * The current tab as it appears in a URL — 'all' rather than ''.
     *
     * @return string
     */
    private static function requestedStatusSlug()
    {
        $status = self::requestedStatus();

        return $status === '' ? 'all' : $status;
    }

    /**
     * @return int
     */
    private static function requestedPage()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    }

    /**
     * The outcome of the decision that just redirected here.
     *
     * @return void
     */
    private static function renderResultNotice()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['fcbo_review'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code = sanitize_key(wp_unslash($_GET['fcbo_review']));

        $messages = [
            'approved' => __('Application approved. The wholesale role has been granted and the applicant has been emailed.', 'fluent-cart-bulk-order'),
            'rejected' => __('Application rejected. The applicant has been emailed.', 'fluent-cart-bulk-order'),
            'refused'  => __('Nothing changed — that application had already been decided, or the user no longer exists.', 'fluent-cart-bulk-order'),
            'error'    => __('That decision could not be recorded. Please try again.', 'fluent-cart-bulk-order'),
        ];

        if (!isset($messages[$code])) {
            return;
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($code === 'approved' || $code === 'rejected' ? 'success' : 'warning'),
            esc_html($messages[$code])
        );
    }

    /**
     * The status filter tabs, with counts.
     *
     * @param string $current
     * @return void
     */
    private static function renderTabs($current)
    {
        $tabs = [
            ApplicationStatus::PENDING  => __('Pending', 'fluent-cart-bulk-order'),
            ApplicationStatus::APPROVED => __('Approved', 'fluent-cart-bulk-order'),
            ApplicationStatus::REJECTED => __('Rejected', 'fluent-cart-bulk-order'),
            'all'                       => __('All', 'fluent-cart-bulk-order'),
        ];

        echo '<ul class="subsubsub">';

        $last = array_key_last($tabs);

        foreach ($tabs as $slug => $label) {
            $isCurrent = $slug === 'all' ? $current === '' : $current === $slug;
            $count     = $slug === 'all' ? null : ApplicationStore::countByStatus($slug);

            printf(
                '<li><a href="%1$s"%2$s>%3$s%4$s</a>%5$s</li>',
                esc_url(add_query_arg(
                    ['page' => self::PAGE_SLUG, 'status' => $slug],
                    admin_url('users.php')
                )),
                $isCurrent ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                $count === null ? '' : ' <span class="count">(' . (int) $count . ')</span>',
                $slug === $last ? '' : ' | '
            );
        }

        echo '</ul>';
    }

    /**
     * The applications table.
     *
     * @param \WP_User[] $users
     * @return void
     */
    private static function renderTable($users)
    {
        $fields = ApplicationSettings::fields();

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th scope="col" style="width:22%;">' . esc_html__('Applicant', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Application', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col" style="width:28%;">' . esc_html__('Decision', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($users as $user) {
            $record = ApplicationStore::get($user->ID);

            echo '<tr>';
            self::renderApplicantCell($user, $record);
            self::renderAnswersCell($record, $fields);
            self::renderDecisionCell($user, $record);
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Who applied, and when.
     *
     * @param \WP_User             $user
     * @param array<string, mixed> $record
     * @return void
     */
    private static function renderApplicantCell($user, array $record)
    {
        echo '<td>';

        printf(
            '<strong><a href="%1$s">%2$s</a></strong><br />',
            esc_url(get_edit_user_link($user->ID)),
            esc_html($user->display_name ? $user->display_name : $user->user_login)
        );

        printf(
            '<a href="%1$s">%2$s</a><br />',
            esc_url('mailto:' . $user->user_email),
            esc_html($user->user_email)
        );

        printf(
            '<span class="description">%s</span>',
            esc_html(sprintf(
                /* translators: %s: date and time the application was sent. */
                __('Applied %s', 'fluent-cart-bulk-order'),
                self::formatDate($record['updated_at'] ? $record['updated_at'] : $record['submitted_at'])
            ))
        );

        echo '</td>';
    }

    /**
     * The answers, one row per configured question.
     *
     * A field the schema no longer declares is still shown, under its raw key,
     * rather than being hidden. An admin reviewing an application submitted
     * before the owner removed a question should see what was actually
     * answered — silently dropping it would change the record in front of them
     * without saying so.
     *
     * @param array<string, mixed>             $record
     * @param array<int, array<string, mixed>> $fields
     * @return void
     */
    private static function renderAnswersCell(array $record, array $fields)
    {
        echo '<td>';

        $values = $record['fields'];

        if (!$values) {
            echo '<em>' . esc_html__('No answers stored.', 'fluent-cart-bulk-order') . '</em></td>';

            return;
        }

        echo '<dl class="fcbo-wa-answers" style="margin:0;">';

        foreach ($values as $key => $value) {
            $field = ApplicationSchema::findField($fields, $key);

            // Owner-supplied label text on an admin page: escaped like anything
            // else. "It came from the settings page" is not a trust boundary.
            $label = $field ? $field['label'] : $key;

            printf(
                '<dt style="float:left;clear:left;margin:0 6px 0 0;font-weight:600;">%s:</dt><dd style="margin:0 0 4px;">%s</dd>',
                esc_html($label),
                self::formatAnswer($value)
            );
        }

        echo '</dl></td>';
    }

    /**
     * One stored answer, escaped and made readable.
     *
     * @param mixed $value
     * @return string Escaped HTML.
     */
    private static function formatAnswer($value)
    {
        if (is_bool($value)) {
            return $value
                ? esc_html__('Yes', 'fluent-cart-bulk-order')
                : esc_html__('No', 'fluent-cart-bulk-order');
        }

        if (!is_scalar($value) || trim((string) $value) === '') {
            return '<em>' . esc_html__('Not answered', 'fluent-cart-bulk-order') . '</em>';
        }

        // Escape first, then add the only markup intended.
        return nl2br(esc_html((string) $value));
    }

    /**
     * The decision controls, or the decision already made.
     *
     * @param \WP_User             $user
     * @param array<string, mixed> $record
     * @return void
     */
    private static function renderDecisionCell($user, array $record)
    {
        echo '<td>';

        if (!ApplicationStatus::canReview($record['status'])) {
            self::renderPastDecision($record);
            echo '</td>';

            return;
        }

        // ONE form, TWO submit buttons. The note textarea belongs to both, so
        // an admin can write a reason once and then choose. The decision
        // travels as the pressed button's value, which is why each button
        // carries name="decision".
        printf(
            '<form method="post" action="%s">',
            esc_url(admin_url('admin-post.php'))
        );

        printf('<input type="hidden" name="action" value="%s" />', esc_attr(WholesaleFlow::ACTION_REVIEW));
        printf('<input type="hidden" name="user_id" value="%d" />', (int) $user->ID);

        // Which tab the admin was looking at, carried so the redirect brings
        // them back to it. Read back through sanitize_key() and only ever used
        // to build a query arg, never printed.
        printf('<input type="hidden" name="status" value="%s" />', esc_attr(self::requestedStatusSlug()));

        wp_nonce_field(WholesaleFlow::NONCE_REVIEW);

        printf(
            '<label class="screen-reader-text" for="fcbo-note-%1$d">%2$s</label>
             <textarea id="fcbo-note-%1$d" name="note" rows="2" style="width:100%%;" placeholder="%3$s"></textarea>',
            (int) $user->ID,
            esc_attr__('Note to the applicant', 'fluent-cart-bulk-order'),
            esc_attr__('Optional note — the applicant will see this', 'fluent-cart-bulk-order')
        );

        printf(
            '<p style="margin:8px 0 0;">
                <button type="submit" name="decision" value="approve" class="button button-primary">%s</button>
                <button type="submit" name="decision" value="reject" class="button">%s</button>
             </p>',
            esc_html__('Approve', 'fluent-cart-bulk-order'),
            esc_html__('Reject', 'fluent-cart-bulk-order')
        );

        echo '</form></td>';
    }

    /**
     * A decision that has already been made.
     *
     * @param array<string, mixed> $record
     * @return void
     */
    private static function renderPastDecision(array $record)
    {
        $labels = [
            ApplicationStatus::APPROVED => __('Approved', 'fluent-cart-bulk-order'),
            ApplicationStatus::REJECTED => __('Rejected', 'fluent-cart-bulk-order'),
        ];

        $label = isset($labels[$record['status']])
            ? $labels[$record['status']]
            : __('No decision', 'fluent-cart-bulk-order');

        printf('<strong>%s</strong>', esc_html($label));

        if ($record['reviewed_at']) {
            $reviewer = $record['reviewer_id'] ? get_userdata((int) $record['reviewer_id']) : null;

            printf(
                '<br /><span class="description">%s</span>',
                esc_html(
                    $reviewer
                        ? sprintf(
                            /* translators: 1: reviewer name, 2: date and time. */
                            __('by %1$s on %2$s', 'fluent-cart-bulk-order'),
                            $reviewer->display_name,
                            self::formatDate($record['reviewed_at'])
                        )
                        : self::formatDate($record['reviewed_at'])
                )
            );
        }

        if (trim((string) $record['note']) !== '') {
            printf('<p style="margin:6px 0 0;">%s</p>', nl2br(esc_html($record['note'])));
        }
    }

    /**
     * Page links, in the standard admin shape.
     *
     * @param string $status
     * @param int    $page
     * @param int    $total
     * @return void
     */
    private static function renderPagination($status, $page, $total)
    {
        $pages = (int) ceil($total / self::PER_PAGE);

        if ($pages < 2) {
            return;
        }

        $base = add_query_arg(
            ['page' => self::PAGE_SLUG, 'status' => $status === '' ? 'all' : $status],
            admin_url('users.php')
        );

        echo '<div class="tablenav"><div class="tablenav-pages">';

        echo wp_kses_post(paginate_links([
            'base'      => add_query_arg('paged', '%#%', $base),
            'format'    => '',
            'total'     => $pages,
            'current'   => $page,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ]));

        echo '</div></div>';
    }

    /**
     * A stored UTC timestamp in the site's own timezone.
     *
     * `wp_date()`, not `date_i18n()`: the record stores UTC, and wp_date() is
     * the function that converts UTC to site time. date_i18n() would treat the
     * input as already-local and shift it by the offset.
     *
     * @param int $timestamp
     * @return string
     */
    private static function formatDate($timestamp)
    {
        $timestamp = (int) $timestamp;

        if ($timestamp <= 0) {
            return '';
        }

        return (string) wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            $timestamp
        );
    }

    /**
     * Redirect back to the screen with an outcome code, and stop.
     *
     * Returns to the tab the admin was on, so deciding an application does not
     * throw them back to "pending" from the middle of the approved list.
     *
     * @param string $code
     * @return void Never returns.
     */
    private static function finish($code)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handleDecision().
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : ApplicationStatus::PENDING;

        wp_safe_redirect(add_query_arg(
            [
                'page'        => self::PAGE_SLUG,
                'status'      => $status,
                'fcbo_review' => $code,
            ],
            admin_url('users.php')
        ));

        exit;
    }
}
