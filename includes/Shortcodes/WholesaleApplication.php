<?php

namespace FluentCartBulkOrder\Shortcodes;

use FluentCartBulkOrder\Wholesale\ApplicationController;
use FluentCartBulkOrder\Wholesale\ApplicationInput;
use FluentCartBulkOrder\Wholesale\ApplicationSchema;
use FluentCartBulkOrder\Wholesale\ApplicationSettings;
use FluentCartBulkOrder\Wholesale\ApplicationStatus;
use FluentCartBulkOrder\Wholesale\ApplicationStore;
use FluentCartBulkOrder\Wholesale\WholesaleFlow;

defined('ABSPATH') || exit;

/*
 * No autoloader in this plugin, on purpose (@see fluent-cart-bulk-order.php).
 * ShortcodeHandler requires AbstractShortcode and this file; the `use`
 * statements above load nothing on their own, so the seven Wholesale classes this
 * shortcode calls have to be named here. Doing it at the top of the file rather
 * than inside a method keeps it to one place a reader can check against the
 * `use` list.
 *
 * WholesaleFlow requires the same set for the POST handlers. require_once makes
 * that harmless — whichever path runs first pays for the parse.
 */
require_once FCBO_DIR . 'includes/Wholesale/ApplicationStatus.php';
require_once FCBO_DIR . 'includes/Wholesale/ApplicationSchema.php';
require_once FCBO_DIR . 'includes/Wholesale/ApplicationInput.php';
require_once FCBO_DIR . 'includes/Wholesale/ApplicationSettings.php';
require_once FCBO_DIR . 'includes/Wholesale/ApplicationStore.php';
require_once FCBO_DIR . 'includes/Wholesale/ApplicationController.php';
require_once FCBO_DIR . 'includes/Wholesale/WholesaleFlow.php';

/**
 * `[fluent_cart_wholesale_application]` — where a shopper asks for a wholesale
 * account.
 *
 * ---------------------------------------------------------------------------
 * THE ONE SURFACE THAT IS NOT BEHIND GATE 1
 * ---------------------------------------------------------------------------
 *
 * Every other FCBO shortcode is for people who already have wholesale access.
 * This one is for the people who do not — so requiresSurfaceAccess() returns
 * false. Gating it would mean only wholesale customers could apply to become
 * wholesale customers.
 *
 * That is safe because there is no wholesale DATA on this page. It renders the
 * owner's questions and the applicant's own answers, nothing else: no prices,
 * no catalogue, no other applicant's record.
 *
 * The logged-in check still applies, and is not optional. An application has to
 * belong to a user account, because approving it grants that account a role.
 * A logged-out visitor is sent to log in or register rather than being offered
 * a form whose submission could not be attached to anyone.
 *
 * ---------------------------------------------------------------------------
 * FOUR STATES, ONE SHORTCODE
 * ---------------------------------------------------------------------------
 *
 *   never applied   the form
 *   pending         "we have it", plus the form again so they can correct an
 *                   answer — a re-submission replaces the record rather than
 *                   creating a second one
 *   rejected        the admin's note, plus the form so they can apply again
 *   approved        a confirmation, and no form. Also what a user given the
 *                   role by hand in wp-admin sees, even though they have no
 *                   application record at all.
 *
 * ---------------------------------------------------------------------------
 * NO JAVASCRIPT
 * ---------------------------------------------------------------------------
 *
 * A plain HTML form posting to admin-post.php. Nothing here needs a script, and
 * not having one means it works with JavaScript off, inside a cached page, and
 * on a site whose security plugin has closed the REST API.
 * @see \FluentCartBulkOrder\Wholesale\ApplicationController
 *
 * Attributes: none. The questions come from the settings page, deliberately —
 * two placements of this shortcode asking different things would give an admin
 * two incompatible records to review.
 */
class WholesaleApplication extends AbstractShortcode
{
    /**
     * @inheritDoc
     */
    protected function defaults()
    {
        return [];
    }

    /**
     * This surface's whole audience is the users Gate 1 excludes.
     *
     * @inheritDoc
     */
    protected function requiresSurfaceAccess()
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function loginNotice()
    {
        return __('Please log in to apply for a wholesale account.', 'fluent-cart-bulk-order');
    }

    /**
     * Logged-out visitors get links, not just a sentence.
     *
     * "Log in to apply" with nothing to click is a dead end for someone who has
     * never had an account here. The registration link is only offered when the
     * site actually accepts registrations — pointing at a disabled sign-up page
     * would be worse than saying nothing.
     *
     * @inheritDoc
     */
    protected function loginNoticeHtml()
    {
        $this->enqueueStyles();

        // The current URL, so logging in returns the shopper to the application
        // page rather than dropping them on the dashboard.
        $return = $this->currentUrl();

        $html  = '<div class="fcbo-wa-wrap"><div class="fcbo-wa-panel fcbo-wa-panel-info">';
        $html .= '<p>' . esc_html($this->loginNotice()) . '</p>';
        $html .= '<p><a class="fcbo-wa-button" href="' . esc_url(wp_login_url($return)) . '">'
            . esc_html__('Log in', 'fluent-cart-bulk-order') . '</a>';

        if (get_option('users_can_register')) {
            $html .= ' <a class="fcbo-wa-link" href="' . esc_url(wp_registration_url()) . '">'
                . esc_html__('Create an account', 'fluent-cart-bulk-order') . '</a>';
        }

        $html .= '</p></div></div>';

        return $html;
    }

    /**
     * @inheritDoc
     */
    protected function accessDeniedNotice()
    {
        // Unreachable: requiresSurfaceAccess() is false, so render() never
        // consults Gate 1 for this shortcode. Implemented because the base
        // class requires it, and worth a sentence rather than an empty string
        // in case a subclass ever re-enables the gate.
        return __('You do not have permission to apply for a wholesale account.', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    protected function output(array $atts)
    {
        $this->enqueueStyles();

        $userId   = get_current_user_id();
        $record   = ApplicationStore::get($userId);
        $feedback = ApplicationController::takeFeedback($userId);

        // The role, not the record, decides whether someone already has access:
        // a store owner can assign wholesale-customer by hand, and that user has
        // no application at all.
        $approved = ApplicationStore::userHasWholesaleRole($userId)
            || ApplicationStatus::grantsRole($record['status']);

        ob_start();

        echo '<div class="fcbo-wa-wrap">';

        $this->renderResultNotice();

        if ($approved) {
            $this->renderApprovedPanel($record);
            echo '</div>';

            return ob_get_clean();
        }

        $this->renderStatusPanel($record);
        $this->renderForm($record, $feedback);

        echo '</div>';

        return ob_get_clean();
    }

    /**
     * The stylesheet. Plain CSS, no build step, matching the rest of assets/.
     *
     * @return void
     */
    private function enqueueStyles()
    {
        wp_enqueue_style(
            'fcbo-wholesale-application',
            FCBO_URL . 'assets/css/wholesale-application.css',
            [],
            FCBO_VERSION
        );
    }

    /**
     * The one-line outcome of the submission that just redirected here.
     *
     * The sentence is chosen from a fixed list by an allow-listed code, so
     * nothing from the query string is ever printed. @see
     * \FluentCartBulkOrder\Wholesale\ApplicationController::resultCode()
     *
     * @return void
     */
    private function renderResultNotice()
    {
        $code = ApplicationController::resultCode();

        if ($code === '') {
            return;
        }

        $messages = [
            'submitted'        => __('Thank you. Your application has been sent for review.', 'fluent-cart-bulk-order'),
            'updated'          => __('Your application has been updated and is waiting for review.', 'fluent-cart-bulk-order'),
            'invalid'          => __('Please check the highlighted answers and send the form again.', 'fluent-cart-bulk-order'),
            'error'            => __('Your application could not be saved. Please try again.', 'fluent-cart-bulk-order'),
            'already_approved' => __('You already have a wholesale account.', 'fluent-cart-bulk-order'),
        ];

        if (!isset($messages[$code])) {
            return;
        }

        $isError = in_array($code, ['invalid', 'error'], true);

        printf(
            '<div class="fcbo-wa-notice %1$s" role="status">%2$s</div>',
            esc_attr($isError ? 'fcbo-wa-notice-error' : 'fcbo-wa-notice-success'),
            esc_html($messages[$code])
        );
    }

    /**
     * What an approved wholesale customer sees instead of a form.
     *
     * @param array<string, mixed> $record
     * @return void
     */
    private function renderApprovedPanel(array $record)
    {
        echo '<div class="fcbo-wa-panel fcbo-wa-panel-approved">';
        echo '<h3>' . esc_html__('Your wholesale account is active', 'fluent-cart-bulk-order') . '</h3>';
        echo '<p>' . esc_html__('You now see wholesale pricing and the bulk ordering tools when you are signed in.', 'fluent-cart-bulk-order') . '</p>';

        $this->renderNote($record);

        echo '</div>';
    }

    /**
     * The "where your application stands" panel, for a pending or rejected one.
     *
     * @param array<string, mixed> $record
     * @return void
     */
    private function renderStatusPanel(array $record)
    {
        if ($record['status'] === ApplicationStatus::PENDING) {
            echo '<div class="fcbo-wa-panel fcbo-wa-panel-pending">';
            echo '<h3>' . esc_html__('Your application is being reviewed', 'fluent-cart-bulk-order') . '</h3>';

            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    /* translators: %s: the date the application was sent. */
                    __('We received it on %s. You can correct your answers below until we review it — sending the form again replaces what you sent, it does not start a second application.', 'fluent-cart-bulk-order'),
                    $this->formatDate($record['submitted_at'])
                ))
            );

            echo '</div>';

            return;
        }

        if ($record['status'] === ApplicationStatus::REJECTED) {
            echo '<div class="fcbo-wa-panel fcbo-wa-panel-rejected">';
            echo '<h3>' . esc_html__('Your application was not approved', 'fluent-cart-bulk-order') . '</h3>';

            $this->renderNote($record);

            echo '<p>' . esc_html__('You are welcome to apply again below.', 'fluent-cart-bulk-order') . '</p>';
            echo '</div>';
        }
    }

    /**
     * The reviewer's note, if they left one.
     *
     * Admin-written text landing on a public page, so it is escaped even though
     * it was sanitised on the way in. `nl2br(esc_html())` in that order: escape
     * first, then add the only markup we intend.
     *
     * @param array<string, mixed> $record
     * @return void
     */
    private function renderNote(array $record)
    {
        $note = isset($record['note']) ? (string) $record['note'] : '';

        if (trim($note) === '') {
            return;
        }

        echo '<div class="fcbo-wa-note">';
        echo '<strong>' . esc_html__('Note from the store', 'fluent-cart-bulk-order') . '</strong>';
        echo '<p>' . nl2br(esc_html($note)) . '</p>';
        echo '</div>';
    }

    /**
     * The form itself.
     *
     * @param array<string, mixed> $record   The user's current application.
     * @param array{errors: array, values: array} $feedback From the last failed
     *                                                      submission, if any.
     * @return void
     */
    private function renderForm(array $record, array $feedback)
    {
        $fields = ApplicationSettings::fields();
        $errors = $feedback['errors'];

        // Precedence: what they just typed and got rejected > what is already
        // stored > empty. Anything else would silently discard the answers of
        // someone who missed one required field.
        $values = $feedback['values'] ? $feedback['values'] : $record['fields'];

        $isUpdate = $record['status'] === ApplicationStatus::PENDING;

        echo '<form class="fcbo-wa-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';

        printf('<input type="hidden" name="action" value="%s" />', esc_attr(WholesaleFlow::ACTION_APPLY));
        printf('<input type="hidden" name="fcbo_redirect_to" value="%s" />', esc_url($this->currentUrl()));

        wp_nonce_field(WholesaleFlow::NONCE_APPLY);

        foreach ($fields as $field) {
            $this->renderField($field, $values, $errors);
        }

        printf(
            '<p class="fcbo-wa-submit"><button type="submit" class="fcbo-wa-button">%s</button></p>',
            esc_html(
                $isUpdate
                    ? __('Update my application', 'fluent-cart-bulk-order')
                    : __('Send my application', 'fluent-cart-bulk-order')
            )
        );

        echo '</form>';
    }

    /**
     * One field, whichever of the four types it is.
     *
     * Every label and option here is text the STORE OWNER typed on the settings
     * page and is being printed on a public page, so all of it is escaped —
     * `esc_html()` for text nodes, `esc_attr()` for attributes. Owner-supplied
     * does not mean trustworthy: an owner can be phished, and a settings page
     * is a common place for a compromised admin session to leave something
     * behind.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     * @return void
     */
    private function renderField(array $field, array $values, array $errors)
    {
        $key      = $field['key'];
        $id       = 'fcbo-wa-' . $key;
        $name     = 'fcbo_field[' . $key . ']';
        $required = !empty($field['required']);
        $error    = isset($errors[$key]) ? $errors[$key] : '';
        $value    = isset($values[$key]) ? $values[$key] : '';

        printf(
            '<div class="fcbo-wa-field%s">',
            $error ? ' fcbo-wa-field-error' : ''
        );

        if ($field['type'] === ApplicationSchema::TYPE_CHECKBOX) {
            printf(
                '<label class="fcbo-wa-checkbox" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s /> <span>%5$s%6$s</span></label>',
                esc_attr($id),
                esc_attr($name),
                checked(!empty($value), true, false),
                $required ? ' required' : '',
                esc_html($field['label']),
                $required ? ' <span class="fcbo-wa-required">*</span>' : ''
            );

            $this->renderFieldError($error);
            echo '</div>';

            return;
        }

        printf(
            '<label for="%1$s">%2$s%3$s</label>',
            esc_attr($id),
            esc_html($field['label']),
            $required ? ' <span class="fcbo-wa-required">*</span>' : ''
        );

        switch ($field['type']) {
            case ApplicationSchema::TYPE_TEXTAREA:
                printf(
                    // maxlength matches ApplicationInput::MAX_VALUE_LENGTH so the
                    // browser stops at the same number the server does. Without
                    // it a long answer is truncated on save with a "thank you,
                    // your application has been sent" message, and neither the
                    // applicant nor the admin who reads half a sentence is told.
                    '<textarea id="%1$s" name="%2$s" rows="4" maxlength="%3$d"%4$s>%5$s</textarea>',
                    esc_attr($id),
                    esc_attr($name),
                    // Cast, not escaped: it is a class constant integer landing
                    // on a %d. absint() states that for a static analyser that
                    // cannot see the format string.
                    absint(ApplicationInput::MAX_VALUE_LENGTH),
                    $required ? ' required' : '',
                    esc_textarea(is_scalar($value) ? (string) $value : '')
                );
                break;

            case ApplicationSchema::TYPE_SELECT:
                printf(
                    '<select id="%1$s" name="%2$s"%3$s>',
                    esc_attr($id),
                    esc_attr($name),
                    $required ? ' required' : ''
                );

                printf(
                    '<option value="">%s</option>',
                    esc_html__('Please choose', 'fluent-cart-bulk-order')
                );

                foreach ($field['options'] as $option) {
                    printf(
                        '<option value="%1$s"%2$s>%3$s</option>',
                        esc_attr($option),
                        selected($value, $option, false),
                        esc_html($option)
                    );
                }

                echo '</select>';
                break;

            default:
                printf(
                    '<input type="text" id="%1$s" name="%2$s" value="%3$s" maxlength="%4$d"%5$s />',
                    esc_attr($id),
                    esc_attr($name),
                    esc_attr(is_scalar($value) ? (string) $value : ''),
                    absint(ApplicationInput::MAX_VALUE_LENGTH),
                    $required ? ' required' : ''
                );
        }

        $this->renderFieldError($error);

        echo '</div>';
    }

    /**
     * The message under a field that failed validation.
     *
     * The error CODE picks a sentence from a fixed list; the code itself is
     * never printed. @see \FluentCartBulkOrder\Wholesale\ApplicationInput
     *
     * @param string $error
     * @return void
     */
    private function renderFieldError($error)
    {
        if ($error === '') {
            return;
        }

        $messages = [
            ApplicationInput::ERROR_REQUIRED       => __('Please answer this question.', 'fluent-cart-bulk-order'),
            ApplicationInput::ERROR_INVALID_OPTION => __('Please choose one of the listed options.', 'fluent-cart-bulk-order'),
        ];

        $message = isset($messages[$error])
            ? $messages[$error]
            : __('Please check this answer.', 'fluent-cart-bulk-order');

        printf('<span class="fcbo-wa-error">%s</span>', esc_html($message));
    }

    /**
     * A stored UTC timestamp, formatted in the SITE's timezone.
     *
     * `wp_date()` rather than `date_i18n()`: the record stores UTC (see
     * ApplicationStore), and wp_date() is the function that converts UTC to the
     * site's timezone. date_i18n() assumes its input is already site-local and
     * would shift the date by the timezone offset.
     *
     * @param int $timestamp
     * @return string
     */
    private function formatDate($timestamp)
    {
        $timestamp = (int) $timestamp;

        if ($timestamp <= 0) {
            return '';
        }

        return (string) wp_date(get_option('date_format'), $timestamp);
    }

    /**
     * The URL of the page this shortcode is rendering on.
     *
     * Used for the form's return address and the login redirect. Built from the
     * queried object rather than from `$_SERVER['REQUEST_URI']` so a cached or
     * proxied request cannot put someone else's URL in the form — and so the
     * result never carries the `?fcbo_wholesale=` arg from a previous
     * submission, which would leave a stale notice on the page after the next.
     *
     * `is_singular()` FIRST, and it is not decoration. get_queried_object_id()
     * returns a TERM id on a taxonomy archive and a USER id on an author
     * archive, so a bare `if ($id)` guard would hand `get_permalink(5)` a
     * number that is not a post id — and get_permalink() would happily resolve
     * it to whatever post happens to have that id. The applicant would then be
     * redirected to an unrelated page and never see the "we have your
     * application" notice, which reads as the form having done nothing. Reached
     * whenever the shortcode sits in a widget, a template part, or a term
     * description rather than on a page of its own.
     *
     * @return string
     */
    private function currentUrl()
    {
        if (is_singular()) {
            $permalink = get_permalink(get_queried_object_id());

            if ($permalink) {
                return $permalink;
            }
        }

        return home_url('/');
    }
}
