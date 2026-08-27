<?php

namespace FluentCartBulkOrder\Wholesale;

defined('ABSPATH') || exit;

/**
 * The three emails the wholesale application flow sends.
 *
 * ---------------------------------------------------------------------------
 * WHY PLAIN TEXT
 * ---------------------------------------------------------------------------
 *
 * `wp_mail()` sends text/plain unless something changes the content type, and
 * this class deliberately does not change it. An HTML email means owning a
 * template, an inliner, and a set of escaping rules for every value that goes
 * into it — for three transactional notices that are four sentences long.
 *
 * Plain text also means the reviewer's note, which is the one piece of
 * free-form text in these messages, cannot carry markup anywhere. There is
 * nothing to escape because there is no markup context.
 *
 * ---------------------------------------------------------------------------
 * WHY EVERY MESSAGE IS FILTERABLE
 * ---------------------------------------------------------------------------
 *
 * A store's voice is its own. Rather than build a template editor into the
 * settings page, each message passes through a filter carrying the applicant
 * and the record, so a site can rewrite any of them in a snippet. That is the
 * same escape-hatch pattern the access gates use.
 *
 * ---------------------------------------------------------------------------
 * A FAILED EMAIL MUST NOT UNDO A DECISION
 * ---------------------------------------------------------------------------
 *
 * These run on the `fcbo/wholesale/*` actions, AFTER the record is written and
 * the role granted. If a store's mail is misconfigured, the approval still
 * stands and the buyer still has their role — they just were not told. The
 * reverse (rolling back a decision because SMTP is down) would be worse and
 * much harder to explain.
 */
class Notifier
{
    /**
     * How long one applicant's admin notice is suppressed for, in seconds.
     *
     * Fifteen minutes. Long enough to make a flood pointless, short enough that
     * a genuine "I forgot to mention" resubmission an hour later still reaches
     * the owner. @see claimAdminNotice() for what this is actually defending.
     */
    const ADMIN_NOTICE_INTERVAL = 900;

    /**
     * Whether this applicant is due an admin notice, claiming it if so.
     *
     * ---------------------------------------------------------------------------
     * THIS IS A SECURITY CONTROL, NOT TIDINESS
     * ---------------------------------------------------------------------------
     *
     * Submitting an application is something ANY logged-in user may do, and a
     * WordPress nonce is not single-use — the same one is accepted for about a
     * day. Without a throttle, a subscriber with one valid nonce can POST the
     * form in a loop and make the store's own server send one email per
     * request. That is outbound-mail amplification: it burns the store's SMTP
     * quota and can get the sending domain blocked, which takes the order
     * confirmations down with it.
     *
     * The suppressed notice costs the owner nothing real, because the CALLER
     * only consults this for an EDIT — an application the owner has already
     * been told about — and the review screen always shows the latest answers.
     * A genuinely new application, including a re-application after a
     * rejection, is never suppressed. @see onSubmitted().
     *
     * A transient rather than user meta so it expires on its own and leaves
     * nothing to clean up on uninstall.
     *
     * @param int $userId
     * @return bool True when the caller may send.
     */
    private static function claimAdminNotice($userId)
    {
        $key = self::throttleKey($userId);

        if (get_transient($key)) {
            return false;
        }

        set_transient($key, 1, self::ADMIN_NOTICE_INTERVAL);

        return true;
    }

    /**
     * Transient name for one applicant's notice throttle.
     *
     * @param int $userId
     * @return string
     */
    private static function throttleKey($userId)
    {
        return 'fcbo_wholesale_notified_' . (int) $userId;
    }

    /**
     * Someone applied — tell the site admin, if the owner wants to be told.
     *
     * @param int                  $userId
     * @param array<string, mixed> $record
     * @param string               $outcome ApplicationStatus::OUTCOME_*
     * @return void
     */
    public static function onSubmitted($userId, $record, $outcome)
    {
        if (!ApplicationSettings::notifyAdmin()) {
            return;
        }

        $user = get_userdata((int) $userId);

        if (!$user) {
            return;
        }

        // Throttle EDITS only. A re-application after a rejection is a new
        // application the owner has not been told about, and suppressing that
        // would lose information rather than repeat it. This is still airtight
        // against the flood, because the first submission moves the applicant
        // to pending and every further one is therefore an update.
        if ($outcome === ApplicationStatus::OUTCOME_UPDATED && !self::claimAdminNotice($userId)) {
            return;
        }

        $to = get_option('admin_email');

        if (!$to || !is_email($to)) {
            return;
        }

        $subject = sprintf(
            /* translators: %s: the store name. */
            __('[%s] New wholesale application', 'fluent-cart-bulk-order'),
            self::siteName()
        );

        $lines = [
            $outcome === ApplicationStatus::OUTCOME_UPDATED
                ? sprintf(
                    /* translators: %s: the applicant's name. */
                    __('%s has updated their wholesale application.', 'fluent-cart-bulk-order'),
                    self::applicantName($user)
                )
                : sprintf(
                    /* translators: %s: the applicant's name. */
                    __('%s has applied for a wholesale account.', 'fluent-cart-bulk-order'),
                    self::applicantName($user)
                ),
            '',
            sprintf(
                /* translators: %s: the applicant's email address. */
                __('Email: %s', 'fluent-cart-bulk-order'),
                $user->user_email
            ),
            '',
            __('Their answers:', 'fluent-cart-bulk-order'),
            self::answerLines($record),
            '',
            __('Review it here:', 'fluent-cart-bulk-order'),
            admin_url('users.php?page=' . ReviewScreen::PAGE_SLUG),
        ];

        self::send('admin_new_application', $to, $subject, $lines, $user, $record);
    }

    /**
     * A decision was made — tell the applicant.
     *
     * @param int                  $userId
     * @param array<string, mixed> $record
     * @param string               $status
     * @return void
     */
    public static function onReviewed($userId, $record, $status)
    {
        // A decision closes the loop, so whatever the applicant sends next is
        // news again. Without this, a rejected applicant who re-applies within
        // the throttle window is silently not reported — and that is a genuinely
        // NEW application, not a repeat of one the owner already saw.
        //
        // Safe against the flood it defends: a loop of submissions never
        // reaches a decision, so nothing ever clears the claim.
        delete_transient(self::throttleKey($userId));

        $user = get_userdata((int) $userId);

        if (!$user || !is_email($user->user_email)) {
            return;
        }

        if ($status === ApplicationStatus::APPROVED) {
            self::sendApproved($user, $record);

            return;
        }

        if ($status === ApplicationStatus::REJECTED) {
            self::sendRejected($user, $record);
        }
    }

    /**
     * "You have a wholesale account now."
     *
     * @param \WP_User             $user
     * @param array<string, mixed> $record
     * @return void
     */
    private static function sendApproved($user, array $record)
    {
        $subject = sprintf(
            /* translators: %s: the store name. */
            __('[%s] Your wholesale account is approved', 'fluent-cart-bulk-order'),
            self::siteName()
        );

        $lines = [
            sprintf(
                /* translators: %s: the applicant's name. */
                __('Hello %s,', 'fluent-cart-bulk-order'),
                self::applicantName($user)
            ),
            '',
            sprintf(
                /* translators: %s: the store name. */
                __('Your application for a wholesale account at %s has been approved.', 'fluent-cart-bulk-order'),
                self::siteName()
            ),
            '',
            __('Sign in and you will see your wholesale prices and the bulk ordering tools.', 'fluent-cart-bulk-order'),
            '',
            wp_login_url(),
        ];

        $lines = array_merge($lines, self::noteLines($record));

        self::send('applicant_approved', $user->user_email, $subject, $lines, $user, $record);
    }

    /**
     * "Not this time." The note is the useful part of this message.
     *
     * @param \WP_User             $user
     * @param array<string, mixed> $record
     * @return void
     */
    private static function sendRejected($user, array $record)
    {
        $subject = sprintf(
            /* translators: %s: the store name. */
            __('[%s] About your wholesale application', 'fluent-cart-bulk-order'),
            self::siteName()
        );

        $lines = [
            sprintf(
                /* translators: %s: the applicant's name. */
                __('Hello %s,', 'fluent-cart-bulk-order'),
                self::applicantName($user)
            ),
            '',
            sprintf(
                /* translators: %s: the store name. */
                __('Thank you for applying for a wholesale account at %s. We are not able to approve it at the moment.', 'fluent-cart-bulk-order'),
                self::siteName()
            ),
        ];

        $lines = array_merge($lines, self::noteLines($record));

        $lines[] = '';
        $lines[] = __('You are welcome to apply again.', 'fluent-cart-bulk-order');

        self::send('applicant_rejected', $user->user_email, $subject, $lines, $user, $record);
    }

    /**
     * The reviewer's note, as its own paragraph, when there is one.
     *
     * @param array<string, mixed> $record
     * @return string[]
     */
    private static function noteLines(array $record)
    {
        $note = isset($record['note']) ? trim((string) $record['note']) : '';

        if ($note === '') {
            return [];
        }

        return ['', __('Note from the store:', 'fluent-cart-bulk-order'), $note];
    }

    /**
     * The applicant's answers, one per line, for the admin's copy.
     *
     * @param array<string, mixed> $record
     * @return string
     */
    private static function answerLines(array $record)
    {
        $values = isset($record['fields']) && is_array($record['fields']) ? $record['fields'] : [];

        if (!$values) {
            return __('(no answers stored)', 'fluent-cart-bulk-order');
        }

        $fields = ApplicationSettings::fields();
        $lines  = [];

        foreach ($values as $key => $value) {
            $field = ApplicationSchema::findField($fields, $key);
            $label = $field ? $field['label'] : $key;

            if (is_bool($value)) {
                $value = $value
                    ? __('Yes', 'fluent-cart-bulk-order')
                    : __('No', 'fluent-cart-bulk-order');
            }

            $lines[] = '- ' . $label . ': ' . (is_scalar($value) ? (string) $value : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Send one message, after giving the site a chance to rewrite it.
     *
     * @param string               $key     Short name of the message, used in
     *                                      the filter names.
     * @param string               $to
     * @param string               $subject
     * @param string[]             $lines   Body lines; joined with newlines.
     * @param \WP_User             $user    The applicant, always — even in the
     *                                      admin's copy, where they are the
     *                                      subject rather than the recipient.
     * @param array<string, mixed> $record
     * @return void
     */
    private static function send($key, $to, $subject, array $lines, $user, array $record)
    {
        $body = implode("\n", $lines);

        /**
         * Rewrite one wholesale notification's subject.
         *
         * @param string               $subject
         * @param \WP_User             $user    The applicant.
         * @param array<string, mixed> $record
         */
        $subject = (string) apply_filters('fcbo/wholesale/email_subject/' . $key, $subject, $user, $record);

        /**
         * Rewrite one wholesale notification's body.
         *
         * Plain text. If a site returns HTML here it must also set the content
         * type through the `wp_mail_content_type` filter — this class will not.
         *
         * @param string               $body
         * @param \WP_User             $user    The applicant.
         * @param array<string, mixed> $record
         */
        $body = (string) apply_filters('fcbo/wholesale/email_body/' . $key, $body, $user, $record);

        if (trim($subject) === '' || trim($body) === '') {
            return;
        }

        // Return value deliberately ignored. @see the class docblock: a mail
        // failure must not undo a decision that has already been recorded.
        wp_mail($to, $subject, $body);
    }

    /**
     * The store's name, decoded.
     *
     * `get_bloginfo('name')` returns the raw option; a site called
     * "Bob&#039;s Widgets" would otherwise put the entity into a plain-text
     * email, where nothing decodes it.
     *
     * @return string
     */
    private static function siteName()
    {
        return wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    }

    /**
     * How to address the applicant.
     *
     * @param \WP_User $user
     * @return string
     */
    private static function applicantName($user)
    {
        if (!empty($user->first_name)) {
            return $user->first_name;
        }

        return $user->display_name ? $user->display_name : $user->user_login;
    }
}
