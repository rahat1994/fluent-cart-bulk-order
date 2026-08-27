<?php

namespace FluentCartBulkOrder\Quotes;

defined('ABSPATH') || exit;

/**
 * The four emails the Quote Request flow sends.
 *
 * ---------------------------------------------------------------------------
 * WHY PLAIN TEXT
 * ---------------------------------------------------------------------------
 *
 * `wp_mail()` sends text/plain unless something changes the content type, and
 * this class deliberately does not change it. An HTML email means owning a
 * template, an inliner, and a set of escaping rules for every value that goes
 * into it — for four transactional notices that are a paragraph and a table of
 * numbers.
 *
 * Plain text also means the owner's note and the product titles, which are the
 * free-form text in these messages, cannot carry markup anywhere. There is
 * nothing to escape because there is no markup context.
 *
 * ---------------------------------------------------------------------------
 * WHY EVERY MESSAGE IS FILTERABLE
 * ---------------------------------------------------------------------------
 *
 * A store's voice is its own. Rather than build a template editor into the
 * settings page, each message passes through a filter carrying the buyer and
 * the record, so a site can rewrite any of them in a snippet. Same escape-hatch
 * pattern as the wholesale flow's notifier.
 *
 * ---------------------------------------------------------------------------
 * A FAILED EMAIL MUST NOT UNDO A DECISION
 * ---------------------------------------------------------------------------
 *
 * These run on the `fcbo/quotes/*` actions, AFTER the record is written and,
 * for a conversion, after the order exists. If a store's mail is misconfigured
 * the order still stands — the buyer just was not told. The reverse, rolling
 * back an order because SMTP is down, would be worse and much harder to explain.
 *
 * @see \FluentCartBulkOrder\Wholesale\Notifier The same shape, for applications.
 */
class QuoteNotifier
{
    /**
     * How long one buyer's admin notice is suppressed for, in seconds.
     *
     * Fifteen minutes, matching the wholesale flow. @see claimAdminNotice() for
     * what this is actually defending.
     */
    const ADMIN_NOTICE_INTERVAL = 900;

    /**
     * A buyer asked for a quote — tell the site admin.
     *
     * @param int                  $quoteId
     * @param array<string, mixed> $record
     * @return void
     */
    public static function onRequested($quoteId, $record)
    {
        if (!QuoteSettings::notifyAdmin()) {
            return;
        }

        $user = get_userdata(isset($record['user_id']) ? (int) $record['user_id'] : 0);

        if (!$user) {
            return;
        }

        if (!self::claimAdminNotice($user->ID)) {
            return;
        }

        $to = get_option('admin_email');

        if (!$to || !is_email($to)) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: the store name, 2: the quote reference. */
            __('[%1$s] New quote request — %2$s', 'fluent-cart-bulk-order'),
            self::siteName(),
            QuoteStore::reference($quoteId)
        );

        $lines = [
            sprintf(
                /* translators: %s: the buyer's name. */
                __('%s has asked for a quote.', 'fluent-cart-bulk-order'),
                self::buyerName($user)
            ),
            '',
            sprintf(
                /* translators: %s: the buyer's email address. */
                __('Email: %s', 'fluent-cart-bulk-order'),
                $user->user_email
            ),
            '',
            self::lineTable($record, false),
        ];

        $lines = array_merge($lines, self::noteLines(
            __('What they asked for:', 'fluent-cart-bulk-order'),
            isset($record['buyer_note']) ? $record['buyer_note'] : ''
        ));

        $lines[] = '';
        $lines[] = __('Price it here:', 'fluent-cart-bulk-order');
        $lines[] = QuoteReviewScreen::pageUrl();

        self::send('admin_new_quote', $to, $subject, $lines, $user, $record);
    }

    /**
     * A decision was made — tell the buyer.
     *
     * @param int                  $quoteId
     * @param array<string, mixed> $record
     * @param string               $status
     * @return void
     */
    public static function onDecided($quoteId, $record, $status)
    {
        // A decision closes the loop, so whatever the buyer sends next is news
        // again. Safe against the flood the throttle defends: a loop of requests
        // never reaches a decision, so nothing ever clears the claim.
        if (!empty($record['user_id'])) {
            delete_transient(self::throttleKey((int) $record['user_id']));
        }

        if (!QuoteStatus::notifiesBuyer($status)) {
            return;
        }

        $user = get_userdata(isset($record['user_id']) ? (int) $record['user_id'] : 0);

        if (!$user || !is_email($user->user_email)) {
            return;
        }

        if ($status === QuoteStatus::QUOTED) {
            self::sendQuoted($quoteId, $user, $record);

            return;
        }

        if ($status === QuoteStatus::ACCEPTED) {
            self::sendConverted($quoteId, $user, $record);

            return;
        }

        self::sendDeclined($quoteId, $user, $record);
    }

    /**
     * "Your quote is ready." The one this feature exists to send.
     *
     * @param int                  $quoteId
     * @param \WP_User             $user
     * @param array<string, mixed> $record
     * @return void
     */
    private static function sendQuoted($quoteId, $user, array $record)
    {
        $totals = QuoteInput::totals(isset($record['lines']) ? $record['lines'] : []);

        $subject = sprintf(
            /* translators: 1: the store name, 2: the quote reference. */
            __('[%1$s] Your quote is ready — %2$s', 'fluent-cart-bulk-order'),
            self::siteName(),
            QuoteStore::reference($quoteId)
        );

        $lines = [
            sprintf(
                /* translators: %s: the buyer's name. */
                __('Hello %s,', 'fluent-cart-bulk-order'),
                self::buyerName($user)
            ),
            '',
            sprintf(
                /* translators: 1: the store name, 2: the quote reference. */
                __('Here is the price %1$s has quoted for %2$s.', 'fluent-cart-bulk-order'),
                self::siteName(),
                QuoteStore::reference($quoteId)
            ),
            '',
            self::lineTable($record, true),
        ];

        if ($totals['saving'] > 0) {
            $lines[] = '';
            $lines[] = sprintf(
                /* translators: %s: money amount saved against the catalogue price. */
                __('That is %s below the list price.', 'fluent-cart-bulk-order'),
                self::money($totals['saving'])
            );
        }

        $lines = array_merge($lines, self::noteLines(
            __('Note from the store:', 'fluent-cart-bulk-order'),
            isset($record['owner_note']) ? $record['owner_note'] : ''
        ));

        $lines[] = '';
        // Deliberately does not say "reply to this email". wp_mail() sends from
        // whatever address the site is configured with, which on a default
        // install is a `wordpress@` box nobody reads — promising a reply route
        // this class cannot guarantee is worse than naming none.
        $lines[] = __('To accept it, get in touch with the store and they will turn this quote into an order for you.', 'fluent-cart-bulk-order');

        self::send('buyer_quoted', $user->user_email, $subject, $lines, $user, $record);
    }

    /**
     * "Your order is waiting." Sent when the owner converts the quote.
     *
     * @param int                  $quoteId
     * @param \WP_User             $user
     * @param array<string, mixed> $record
     * @return void
     */
    private static function sendConverted($quoteId, $user, array $record)
    {
        $subject = sprintf(
            /* translators: 1: the store name, 2: the quote reference. */
            __('[%1$s] Your order from %2$s', 'fluent-cart-bulk-order'),
            self::siteName(),
            QuoteStore::reference($quoteId)
        );

        $lines = [
            sprintf(
                /* translators: %s: the buyer's name. */
                __('Hello %s,', 'fluent-cart-bulk-order'),
                self::buyerName($user)
            ),
            '',
            sprintf(
                /* translators: %s: the quote reference. */
                __('%s has been turned into an order at the quoted prices.', 'fluent-cart-bulk-order'),
                QuoteStore::reference($quoteId)
            ),
            '',
            self::lineTable($record, true),
        ];

        $lines = array_merge($lines, self::noteLines(
            __('Note from the store:', 'fluent-cart-bulk-order'),
            isset($record['owner_note']) ? $record['owner_note'] : ''
        ));

        $lines[] = '';
        // Deliberately no payment link. The order is created unpaid through
        // FluentCart's offline-payment method, and how an offline order gets
        // paid is the store's own arrangement — inventing a link here would
        // promise a checkout that may not exist.
        $lines[] = __('The store will be in touch about payment.', 'fluent-cart-bulk-order');

        self::send('buyer_converted', $user->user_email, $subject, $lines, $user, $record);
    }

    /**
     * "Not this time." The note is the useful part of this message.
     *
     * @param int                  $quoteId
     * @param \WP_User             $user
     * @param array<string, mixed> $record
     * @return void
     */
    private static function sendDeclined($quoteId, $user, array $record)
    {
        $subject = sprintf(
            /* translators: 1: the store name, 2: the quote reference. */
            __('[%1$s] About %2$s', 'fluent-cart-bulk-order'),
            self::siteName(),
            QuoteStore::reference($quoteId)
        );

        $lines = [
            sprintf(
                /* translators: %s: the buyer's name. */
                __('Hello %s,', 'fluent-cart-bulk-order'),
                self::buyerName($user)
            ),
            '',
            sprintf(
                /* translators: 1: the quote reference, 2: the store name. */
                __('Thank you for asking about %1$s. %2$s is not able to quote it at the moment.', 'fluent-cart-bulk-order'),
                QuoteStore::reference($quoteId),
                self::siteName()
            ),
        ];

        $lines = array_merge($lines, self::noteLines(
            __('Note from the store:', 'fluent-cart-bulk-order'),
            isset($record['owner_note']) ? $record['owner_note'] : ''
        ));

        $lines[] = '';
        $lines[] = __('You are welcome to send another request at any time.', 'fluent-cart-bulk-order');

        self::send('buyer_declined', $user->user_email, $subject, $lines, $user, $record);
    }

    /**
     * The quote's lines as plain text, one per line, with a total.
     *
     * @param array<string, mixed> $record
     * @param bool                 $quoted Whether to print the QUOTED price
     *                                     (what the owner decided) rather than
     *                                     the requested one (what the catalogue
     *                                     said when the buyer asked).
     * @return string
     */
    private static function lineTable(array $record, $quoted)
    {
        $lines = isset($record['lines']) && is_array($record['lines']) ? $record['lines'] : [];

        if (!$lines) {
            return __('(no items)', 'fluent-cart-bulk-order');
        }

        $out = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $unit = $quoted
                ? QuoteInput::effectivePrice($line)
                : max(0, isset($line['requested_price']) ? (int) $line['requested_price'] : 0);

            $qty = max(0, isset($line['qty']) ? (int) $line['qty'] : 0);

            $out[] = sprintf(
                /* translators: 1: product name, 2: quantity, 3: unit price, 4: line total. */
                __('- %1$s x %2$d @ %3$s = %4$s', 'fluent-cart-bulk-order'),
                self::lineLabel($line),
                $qty,
                self::money($unit),
                self::money($unit * $qty)
            );
        }

        $totals = QuoteInput::totals($lines);

        $out[] = '';
        $out[] = sprintf(
            /* translators: %s: the order total. */
            __('Total: %s', 'fluent-cart-bulk-order'),
            self::money($quoted ? $totals['quoted'] : $totals['requested'])
        );

        return implode("\n", $out);
    }

    /**
     * How one line names itself in an email.
     *
     * @param array<string, mixed> $line
     * @return string
     */
    private static function lineLabel(array $line)
    {
        $title   = isset($line['title']) ? trim((string) $line['title']) : '';
        $variant = isset($line['variation_title']) ? trim((string) $line['variation_title']) : '';
        $sku     = isset($line['sku']) ? trim((string) $line['sku']) : '';

        if ($title === '') {
            $title = __('(product no longer in the catalogue)', 'fluent-cart-bulk-order');
        }

        if ($variant !== '' && strcasecmp($variant, 'default') !== 0) {
            $title .= ' — ' . $variant;
        }

        if ($sku !== '') {
            $title .= sprintf(
                /* translators: %s: a product SKU. */
                __(' (SKU %s)', 'fluent-cart-bulk-order'),
                $sku
            );
        }

        return $title;
    }

    /**
     * A note as its own labelled paragraph, when there is one.
     *
     * @param string $label
     * @param mixed  $note
     * @return string[]
     */
    private static function noteLines($label, $note)
    {
        $note = is_scalar($note) ? trim((string) $note) : '';

        if ($note === '') {
            return [];
        }

        return ['', $label, $note];
    }

    /**
     * Cents as the store's own money string.
     *
     * The same currency sign the ordering surfaces use, through the plugin's
     * request-cached helper, so an email and the form the buyer filled in do
     * not disagree about the currency.
     *
     * @param int $cents
     * @return string
     */
    private static function money($cents)
    {
        return fcbo_get_currency_sign() . number_format_i18n(((int) $cents) / 100, 2);
    }

    /**
     * Whether this buyer is due an admin notice, claiming it if so.
     *
     * ---------------------------------------------------------------------------
     * THIS IS A SECURITY CONTROL, NOT TIDINESS
     * ---------------------------------------------------------------------------
     *
     * Asking for a quote is something any permitted user may do, and a REST
     * nonce is not single-use. Without a throttle, one buyer with one valid
     * nonce can POST the form in a loop and make the store's own server send one
     * email per request. That is outbound-mail amplification: it burns the
     * store's SMTP quota and can get the sending domain blocked, which takes the
     * order confirmations down with it.
     *
     * The suppressed notice costs the owner nothing real: every request still
     * lands on the review screen, which is where they are acted on. The email is
     * discovery, not the record. QuoteStore::openCountFor() is the other half of
     * this defence and bounds how many records a loop can create at all.
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
     * Transient name for one buyer's notice throttle.
     *
     * @param int $userId
     * @return string
     */
    private static function throttleKey($userId)
    {
        return 'fcbo_quote_notified_' . (int) $userId;
    }

    /**
     * Send one message, after giving the site a chance to rewrite it.
     *
     * @param string               $key     Short name of the message, used in
     *                                      the filter names.
     * @param string               $to
     * @param string               $subject
     * @param string[]             $lines   Body lines; joined with newlines.
     * @param \WP_User             $user    The buyer, always — even in the
     *                                      admin's copy, where they are the
     *                                      subject rather than the recipient.
     * @param array<string, mixed> $record
     * @return void
     */
    private static function send($key, $to, $subject, array $lines, $user, array $record)
    {
        $body = implode("\n", $lines);

        /**
         * Rewrite one quote notification's subject.
         *
         * @param string               $subject
         * @param \WP_User             $user    The buyer.
         * @param array<string, mixed> $record
         */
        $subject = (string) apply_filters('fcbo/quotes/email_subject/' . $key, $subject, $user, $record);

        /**
         * Rewrite one quote notification's body.
         *
         * Plain text. If a site returns HTML here it must also set the content
         * type through the `wp_mail_content_type` filter — this class will not.
         *
         * @param string               $body
         * @param \WP_User             $user    The buyer.
         * @param array<string, mixed> $record
         */
        $body = (string) apply_filters('fcbo/quotes/email_body/' . $key, $body, $user, $record);

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
     * How to address the buyer.
     *
     * @param \WP_User $user
     * @return string
     */
    private static function buyerName($user)
    {
        if (!empty($user->first_name)) {
            return $user->first_name;
        }

        return $user->display_name ? $user->display_name : $user->user_login;
    }
}
