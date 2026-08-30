<?php

namespace FluentCartBulkOrder\Quotes;

use FluentCartBulkOrder\Admin\Menu;

defined('ABSPATH') || exit;

/**
 * The admin screen where quote requests are priced, sent, converted or declined.
 *
 * ---------------------------------------------------------------------------
 * WHY EVERY ACTION HERE IS A POST
 * ---------------------------------------------------------------------------
 *
 * Converting a quote creates a real order at a price the owner typed. A
 * "Convert" link — a GET with a nonce in the query string — is triggerable by
 * anything that follows a URL an admin's browser has: a prefetch, a link
 * scanner in an email client, an `<img src>` on a page the admin visits, a
 * browser extension warming links. None of those involve the admin deciding
 * anything.
 *
 * So each button is inside a `<form method="post">`. A POST is not issued by any
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
 *   4. A legal transition.               QuoteStatus::canTransition(), claimed
 *                                        atomically by QuoteStore::decide().
 *
 * The screen itself repeats check 3 rather than trusting the menu capability.
 * add_submenu_page() hides the menu item from users without the capability but
 * does not stop a direct URL, and `admin.php?page=` dispatch has been the source
 * of enough plugin vulnerabilities to be worth two lines.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SCREEN LIVES UNDER THE PLUGIN'S OWN MENU
 * ---------------------------------------------------------------------------
 *
 * Under Bulk Order, beside the plugin's own settings page, and NOT under
 * FluentCart's menu. That menu is a single-page app whose entries FluentCart
 * builds by hand into the `$submenu` global and gates behind its own permission
 * manager, so hanging a page off it would mean our screen silently disappearing
 * for an administrator the host's gate happens not to recognise. A menu this
 * plugin registers itself always exists for anyone holding `manage_options`,
 * which is the capability this screen needs anyway.
 * @see \FluentCartBulkOrder\Admin\Menu
 */
class QuoteReviewScreen
{
    /**
     * Admin page slug.
     */
    const PAGE_SLUG = 'fcbo-quotes';

    /**
     * The capability required to see this screen and to decide anything on it.
     *
     * `manage_options`, matching the plugin's settings page and the wholesale
     * review screen. Setting a price and creating an order is at least as
     * consequential as changing a pricing policy, so it does not get a weaker
     * gate.
     */
    const CAPABILITY = 'manage_options';

    /**
     * Quotes per page.
     */
    const PER_PAGE = 20;

    /**
     * Add the menu entry.
     *
     * @return void
     */
    public static function addMenu()
    {
        $title = __('Quote Requests', 'fluent-cart-bulk-order');

        // The capability check is NOT redundant with add_submenu_page() below,
        // and it is not about security here — it is about cost. `admin_menu`
        // fires on EVERY wp-admin request for EVERY logged-in user, a
        // subscriber opening profile.php included, and countByStatus() is a
        // WP_Query with a meta lookup. The number is only ever SHOWN to a user
        // who holds the capability, so counting for anyone else buys nothing.
        // Menu::cachedCount() keeps even that one off most requests.
        $open = current_user_can(self::CAPABILITY)
            ? Menu::cachedCount(Menu::COUNT_QUOTES, function () {
                return QuoteStore::countByStatus(QuoteStatus::REQUESTED);
            })
            : 0;

        // The count bubble is the whole discovery mechanism for this screen. An
        // owner who never notices a quote has a feature that does not work,
        // whatever the code does. Reported to the parent as well, because a
        // submenu row is hidden until the Bulk Order menu is open.
        Menu::countPending($open);

        add_submenu_page(
            Menu::PARENT_SLUG,
            $title,
            Menu::bubbleTitle($title, $open),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * This screen's URL. Named here so the notifier does not repeat it.
     *
     * @return string
     */
    public static function pageUrl()
    {
        return Menu::url(self::PAGE_SLUG);
    }

    /**
     * Render the screen.
     *
     * @return void
     */
    public static function render()
    {
        // Not redundant with the menu capability. add_submenu_page() controls
        // visibility; this controls access.
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to review quote requests.', 'fluent-cart-bulk-order'));
        }

        $status = self::requestedStatus();
        $page   = self::requestedPage();
        $result = QuoteStore::page($status, $page, self::PER_PAGE);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Quote Requests', 'fluent-cart-bulk-order') . '</h1>';

        self::renderResultNotice();
        self::renderTabs($status);

        if (!$result['quotes']) {
            echo '<p>' . esc_html__('No quote requests to show.', 'fluent-cart-bulk-order') . '</p>';
            echo '</div>';

            return;
        }

        foreach ($result['quotes'] as $post) {
            self::renderQuote($post);
        }

        self::renderPagination($status, $page, $result['total']);

        echo '</div>';
    }

    /**
     * Handle a price / convert / decline submission.
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
                esc_html__('This action must be submitted from the quote review screen.', 'fluent-cart-bulk-order'),
                '',
                ['response' => 405]
            );
        }

        check_admin_referer(QuoteFlow::NONCE_REVIEW);

        // A valid nonce says the request came from this admin's browser. It says
        // nothing about what that admin may do, which is why the capability is
        // checked on its own.
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html__('You do not have permission to review quote requests.', 'fluent-cart-bulk-order'),
                '',
                ['response' => 403]
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $quoteId = isset($_POST['quote_id']) ? absint($_POST['quote_id']) : 0;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $note = isset($_POST['owner_note']) ? sanitize_textarea_field(wp_unslash($_POST['owner_note'])) : '';

        $status = QuoteStatus::statusForDecision($decision);
        $record = $quoteId ? QuoteStore::get($quoteId) : null;

        if (!$record || $status === null) {
            self::finish('error');
        }

        $note = QuoteInput::sanitizeNote($note, QuoteStore::sanitizers()[0]);

        $changes = ['owner_note' => $note];
        $prepare = null;

        if ($status === QuoteStatus::QUOTED) {
            // Prices are read against the STORED lines, never against the shape
            // of the POST — a price for a variant this quote does not carry is
            // ignored, and a line the buyer never sent cannot appear by being
            // typed into the form. @see QuoteInput::applyPrices()
            $changes['lines'] = QuoteInput::applyPrices($record['lines'], self::submittedPrices());
        }

        if ($status === QuoteStatus::ACCEPTED) {
            // Runs INSIDE the claim, so the order is created exactly once and a
            // failure hands the quote back as `quoted` rather than leaving it
            // accepted with nothing behind it. @see QuoteStore::decide()
            $prepare = function ($claimed) {
                $orderId = QuoteOrder::create($claimed);

                if (is_wp_error($orderId)) {
                    return $orderId;
                }

                return ['order_id' => (int) $orderId];
            };
        }

        $result = QuoteStore::decide($quoteId, $status, get_current_user_id(), $changes, $prepare);

        if (is_wp_error($result)) {
            self::finish('order_failed', $result->get_error_message());
        }

        // Null means the state machine refused — the quote was already decided,
        // or it is gone. Not an error the admin can fix, and deliberately NOT a
        // fatal: a double-clicked button lands here.
        if (!$result) {
            self::finish('refused');
        }

        self::finish(self::codeForStatus($status));
    }

    /**
     * The prices the owner typed, keyed by variant id.
     *
     * Read raw and handed to QuoteInput::applyPrices(), which is the one place
     * that decides what a price value means — including that '' is "leave this
     * line alone" rather than "free".
     *
     * @return array<int, mixed>
     */
    private static function submittedPrices()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handleDecision().
        if (!isset($_POST['price']) || !is_array($_POST['price'])) {
            return [];
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handleDecision(); every value is turned into an integer number of cents by QuoteInput::toPrice(), and every key by absint() below.
        $raw = wp_unslash($_POST['price']);
        // phpcs:enable

        $prices = [];

        foreach ($raw as $variantId => $value) {
            $variantId = absint($variantId);

            if ($variantId > 0 && is_scalar($value)) {
                $prices[$variantId] = $value;
            }
        }

        return $prices;
    }

    /**
     * The outcome code for a decision that succeeded.
     *
     * @param string $status
     * @return string
     */
    private static function codeForStatus($status)
    {
        if ($status === QuoteStatus::QUOTED) {
            return 'quoted';
        }

        return $status === QuoteStatus::ACCEPTED ? 'converted' : 'declined';
    }

    /**
     * The status tab the admin asked for.
     *
     * Defaults to `requested`, because that is the only status with anything
     * waiting to be done.
     *
     * @return string A QuoteStatus constant, or '' for "all".
     */
    private static function requestedStatus()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on a capability-checked screen.
        if (!isset($_GET['status'])) {
            return QuoteStatus::REQUESTED;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status = sanitize_key(wp_unslash($_GET['status']));

        if ($status === 'all') {
            return '';
        }

        return QuoteStatus::isStorable($status) ? $status : QuoteStatus::REQUESTED;
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
        if (empty($_GET['fcbo_quote'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code = sanitize_key(wp_unslash($_GET['fcbo_quote']));

        $messages = [
            'quoted'    => __('Quote sent. The buyer has been emailed the prices you set.', 'fluent-cart-bulk-order'),
            'converted' => __('Order created at the quoted prices, and the buyer has been emailed. It is waiting for payment in FluentCart.', 'fluent-cart-bulk-order'),
            'declined'  => __('Quote declined. The buyer has been emailed.', 'fluent-cart-bulk-order'),
            'refused'   => __('Nothing changed — that quote had already been decided, or it no longer exists.', 'fluent-cart-bulk-order'),
            'error'     => __('That decision could not be recorded. Please try again.', 'fluent-cart-bulk-order'),
            'order_failed' => __('No order was created and the quote is unchanged.', 'fluent-cart-bulk-order'),
        ];

        if (!isset($messages[$code])) {
            return;
        }

        $success = in_array($code, ['quoted', 'converted', 'declined'], true);

        // The reason a conversion failed comes back in the query string because
        // it is the ONE piece of feedback here that is not a fixed sentence —
        // FluentCart writes it, and the admin cannot act without it. It is
        // sanitised on the way out and escaped on the way in, and it is never
        // anything but text.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $detail = isset($_GET['fcbo_reason']) ? sanitize_text_field(wp_unslash($_GET['fcbo_reason'])) : '';

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s %3$s</p></div>',
            esc_attr($success ? 'success' : ($code === 'refused' ? 'warning' : 'error')),
            esc_html($messages[$code]),
            esc_html($detail)
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
            QuoteStatus::REQUESTED => __('Waiting', 'fluent-cart-bulk-order'),
            QuoteStatus::QUOTED    => __('Quoted', 'fluent-cart-bulk-order'),
            QuoteStatus::ACCEPTED  => __('Converted', 'fluent-cart-bulk-order'),
            QuoteStatus::DECLINED  => __('Declined', 'fluent-cart-bulk-order'),
            'all'                  => __('All', 'fluent-cart-bulk-order'),
        ];

        echo '<ul class="subsubsub">';

        $last = array_key_last($tabs);

        foreach ($tabs as $slug => $label) {
            $isCurrent = $slug === 'all' ? $current === '' : $current === $slug;
            $count     = $slug === 'all' ? null : QuoteStore::countByStatus($slug);

            printf(
                '<li><a href="%1$s"%2$s>%3$s%4$s</a>%5$s</li>',
                esc_url(add_query_arg(
                    ['page' => self::PAGE_SLUG, 'status' => $slug],
                    Menu::baseUrl()
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
     * One quote, as a card: who asked, what for, and what can be done about it.
     *
     * @param \WP_Post $post
     * @return void
     */
    private static function renderQuote($post)
    {
        $record = QuoteStore::get($post->ID);

        if (!$record) {
            return;
        }

        $user   = get_userdata((int) $record['user_id']);
        $totals = QuoteInput::totals($record['lines']);
        $open   = QuoteStatus::canPrice($record['status']);

        echo '<div class="fcbo-quote-card" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0;">';

        self::renderQuoteHeader($post, $record, $user);

        // ONE form per quote, with every button inside it. The price inputs and
        // the note belong to all of them, so an owner can price the lines, write
        // a reason and then choose what to do — without the page having to keep
        // three copies of the same fields in step.
        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));

        printf('<input type="hidden" name="action" value="%s" />', esc_attr(QuoteFlow::ACTION_REVIEW));
        printf('<input type="hidden" name="quote_id" value="%d" />', (int) $post->ID);

        // Which tab the admin was looking at, carried so the redirect brings
        // them back to it. Read back through sanitize_key() and only ever used
        // to build a query arg, never printed.
        printf('<input type="hidden" name="status" value="%s" />', esc_attr(self::requestedStatusSlug()));

        wp_nonce_field(QuoteFlow::NONCE_REVIEW);

        self::renderLines($record, $open);
        self::renderTotals($record, $totals);
        self::renderNotes($record);
        self::renderActions($record);

        echo '</form>';
        echo '</div>';
    }

    /**
     * Who asked, when, and what state the quote is in.
     *
     * @param \WP_Post             $post
     * @param array<string, mixed> $record
     * @param \WP_User|false       $user
     * @return void
     */
    private static function renderQuoteHeader($post, array $record, $user)
    {
        printf(
            '<h2 style="margin:0 0 4px;">%1$s <span class="fcbo-quote-state" style="font-weight:400;color:#646970;">— %2$s</span></h2>',
            esc_html(QuoteStore::reference($post->ID)),
            esc_html(self::statusLabel($record['status']))
        );

        if ($user) {
            printf(
                '<p style="margin:0 0 12px;"><a href="%1$s">%2$s</a> &lt;<a href="%3$s">%4$s</a>&gt; · <span class="description">%5$s</span></p>',
                esc_url(get_edit_user_link($user->ID)),
                esc_html($user->display_name ? $user->display_name : $user->user_login),
                esc_url('mailto:' . $user->user_email),
                esc_html($user->user_email),
                esc_html(sprintf(
                    /* translators: %s: date and time the quote was requested. */
                    __('Requested %s', 'fluent-cart-bulk-order'),
                    self::formatDate($record['requested_at'])
                ))
            );
        } else {
            printf(
                '<p style="margin:0 0 12px;"><em>%s</em></p>',
                esc_html__('The buyer no longer has an account on this site.', 'fluent-cart-bulk-order')
            );
        }

        if ($record['order_id']) {
            printf(
                '<p style="margin:0 0 12px;"><a href="%1$s">%2$s</a></p>',
                esc_url(QuoteOrder::adminUrl($record['order_id'])),
                esc_html(sprintf(
                    /* translators: %d: the FluentCart order id. */
                    __('Open order #%d in FluentCart', 'fluent-cart-bulk-order'),
                    (int) $record['order_id']
                ))
            );
        }
    }

    /**
     * The line items, with a price box each while the quote is still open.
     *
     * @param array<string, mixed> $record
     * @param bool                 $editable
     * @return void
     */
    private static function renderLines(array $record, $editable)
    {
        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Product', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col" style="width:10%;">' . esc_html__('SKU', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col" style="width:8%;">' . esc_html__('Qty', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col" style="width:14%;">' . esc_html__('List price', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col" style="width:16%;">' . esc_html__('Your price', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col" style="width:14%;">' . esc_html__('Line total', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($record['lines'] as $line) {
            if (!is_array($line)) {
                continue;
            }

            $line      = array_merge(QuoteInput::LINE_DEFAULTS, $line);
            $variantId = (int) $line['variant_id'];
            $qty       = max(0, (int) $line['qty']);
            $unit      = QuoteInput::effectivePrice($line);

            echo '<tr>';

            echo '<td>';
            echo esc_html($line['title'] !== '' ? $line['title'] : __('(product no longer in the catalogue)', 'fluent-cart-bulk-order'));

            // A variant literally called "Default" is what FluentCart shows for a
            // product with only one, and repeating it under every row is noise.
            if ($line['variation_title'] !== '' && strcasecmp((string) $line['variation_title'], 'default') !== 0) {
                printf('<br /><span class="description">%s</span>', esc_html($line['variation_title']));
            }

            echo '</td>';

            printf('<td>%s</td>', esc_html($line['sku']));
            printf('<td>%d</td>', (int) $qty);
            printf('<td>%s</td>', esc_html(self::money((int) $line['requested_price'])));

            if ($editable) {
                printf(
                    '<td><label class="screen-reader-text" for="fcbo-price-%1$d">%2$s</label>
                     <input type="number" id="fcbo-price-%1$d" name="price[%1$d]" value="%3$s" min="0" step="1" style="width:100%%;" /></td>',
                    (int) $variantId,
                    esc_attr__('Unit price in cents', 'fluent-cart-bulk-order'),
                    esc_attr((string) $unit)
                );
            } else {
                printf('<td>%s</td>', esc_html(self::money($unit)));
            }

            printf('<td>%s</td>', esc_html(self::money($unit * $qty)));

            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($editable) {
            printf(
                '<p class="description" style="margin:6px 0 0;">%s</p>',
                esc_html(sprintf(
                    /* translators: %s: an example price in minor units, e.g. 1250. */
                    __('Prices are in the smallest currency unit — %s means 12.50. Leave a box as it is to quote the list price.', 'fluent-cart-bulk-order'),
                    '1250'
                ))
            );
        }
    }

    /**
     * The quote's totals.
     *
     * @param array<string, mixed> $record
     * @param array<string, int>   $totals
     * @return void
     */
    private static function renderTotals(array $record, array $totals)
    {
        printf(
            '<p style="margin:10px 0 0;"><strong>%1$s</strong> %2$s',
            esc_html__('Quote total:', 'fluent-cart-bulk-order'),
            esc_html(self::money($totals['quoted']))
        );

        if ($totals['saving'] > 0) {
            printf(
                ' <span class="description">%s</span>',
                esc_html(sprintf(
                    /* translators: 1: list-price total, 2: amount saved. */
                    __('(list %1$s, saving %2$s)', 'fluent-cart-bulk-order'),
                    self::money($totals['requested']),
                    self::money($totals['saving'])
                ))
            );
        }

        echo '</p>';

        if (QuoteInput::hasSubscription($record['lines'])) {
            printf(
                '<p class="notice notice-warning inline" style="margin:10px 0 0;padding:8px 12px;">%s</p>',
                esc_html__('This quote contains a subscription product. FluentCart cannot put one into a manually created order, so this quote cannot be converted here — price it, send it, and place the subscription part through FluentCart.', 'fluent-cart-bulk-order')
            );
        }
    }

    /**
     * The buyer's note, and the owner's reply box.
     *
     * The reply box is shown for any OPEN quote, not only a pricable one: the
     * owner writes it once when they price the quote and again when they
     * convert or withdraw it, and each message is the one the buyer receives
     * with that decision. That is why this takes no `$editable` flag — prices
     * and notes stop being editable at different moments.
     *
     * @param array<string, mixed> $record
     * @return void
     */
    private static function renderNotes(array $record)
    {
        if (trim((string) $record['buyer_note']) !== '') {
            printf(
                '<p style="margin:12px 0 0;"><strong>%1$s</strong><br />%2$s</p>',
                esc_html__('What the buyer asked for:', 'fluent-cart-bulk-order'),
                nl2br(esc_html($record['buyer_note']))
            );
        }

        if (!QuoteStatus::isOpen($record['status'])) {
            if (trim((string) $record['owner_note']) !== '') {
                printf(
                    '<p style="margin:12px 0 0;"><strong>%1$s</strong><br />%2$s</p>',
                    esc_html__('Your note:', 'fluent-cart-bulk-order'),
                    nl2br(esc_html($record['owner_note']))
                );
            }

            return;
        }

        printf(
            '<p style="margin:12px 0 0;">
                <label for="fcbo-note-%1$d"><strong>%2$s</strong></label><br />
                <textarea id="fcbo-note-%1$d" name="owner_note" rows="3" style="width:100%%;max-width:600px;" placeholder="%3$s">%4$s</textarea>
             </p>',
            (int) $record['id'],
            esc_html__('Note to the buyer', 'fluent-cart-bulk-order'),
            esc_attr__('Optional — the buyer will see this in their email', 'fluent-cart-bulk-order'),
            esc_textarea($record['owner_note'])
        );
    }

    /**
     * The buttons this quote's state allows.
     *
     * @param array<string, mixed> $record
     * @return void
     */
    private static function renderActions(array $record)
    {
        if (!QuoteStatus::isOpen($record['status'])) {
            printf(
                '<p style="margin:12px 0 0;"><span class="description">%s</span></p>',
                esc_html(self::decidedDescription($record))
            );

            return;
        }

        echo '<p style="margin:12px 0 0;">';

        if (QuoteStatus::canPrice($record['status'])) {
            printf(
                '<button type="submit" name="decision" value="quote" class="button button-primary">%s</button> ',
                esc_html__('Send quote to buyer', 'fluent-cart-bulk-order')
            );
        }

        if (QuoteStatus::canConvert($record['status'])) {
            printf(
                '<button type="submit" name="decision" value="convert" class="button button-primary">%s</button> ',
                esc_html__('Convert to order', 'fluent-cart-bulk-order')
            );
        }

        printf(
            '<button type="submit" name="decision" value="decline" class="button">%s</button>',
            esc_html__('Decline', 'fluent-cart-bulk-order')
        );

        echo '</p>';
    }

    /**
     * One sentence about a decision already made.
     *
     * @param array<string, mixed> $record
     * @return string
     */
    private static function decidedDescription(array $record)
    {
        $reviewer = $record['reviewer_id'] ? get_userdata((int) $record['reviewer_id']) : null;
        $when     = self::formatDate($record['decided_at']);

        if (!$reviewer) {
            return $when === ''
                ? self::statusLabel($record['status'])
                : sprintf(
                    /* translators: 1: status word, 2: date and time. */
                    __('%1$s on %2$s', 'fluent-cart-bulk-order'),
                    self::statusLabel($record['status']),
                    $when
                );
        }

        return sprintf(
            /* translators: 1: status word, 2: reviewer name, 3: date and time. */
            __('%1$s by %2$s on %3$s', 'fluent-cart-bulk-order'),
            self::statusLabel($record['status']),
            $reviewer->display_name,
            $when
        );
    }

    /**
     * A status as a word a human reads.
     *
     * @param string $status
     * @return string
     */
    private static function statusLabel($status)
    {
        $labels = [
            QuoteStatus::REQUESTED => __('Waiting for a price', 'fluent-cart-bulk-order'),
            QuoteStatus::QUOTED    => __('Quoted', 'fluent-cart-bulk-order'),
            QuoteStatus::ACCEPTED  => __('Converted to an order', 'fluent-cart-bulk-order'),
            QuoteStatus::DECLINED  => __('Declined', 'fluent-cart-bulk-order'),
        ];

        return isset($labels[$status]) ? $labels[$status] : __('Unknown', 'fluent-cart-bulk-order');
    }

    /**
     * Cents as the store's own money string.
     *
     * @param int $cents
     * @return string
     */
    private static function money($cents)
    {
        return fcbo_get_currency_sign() . number_format_i18n(((int) $cents) / 100, 2);
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
            Menu::baseUrl()
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
     * Returns to the tab the admin was on, so deciding a quote does not throw
     * them back to "waiting" from the middle of the converted list.
     *
     * @param string $code
     * @param string $reason Optional detail, shown after the fixed sentence.
     * @return void Never returns.
     */
    private static function finish($code, $reason = '')
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handleDecision().
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : QuoteStatus::REQUESTED;

        $args = [
            'page'       => self::PAGE_SLUG,
            'status'     => $status,
            'fcbo_quote' => $code,
        ];

        if ($reason !== '') {
            // Bounded: a query string that a host or a proxy truncates would
            // hand the admin half a sentence, and the fixed part of the notice
            // already carries the outcome.
            $args['fcbo_reason'] = sanitize_text_field(
                function_exists('mb_substr') ? mb_substr($reason, 0, 200) : substr($reason, 0, 200)
            );
        }

        wp_safe_redirect(add_query_arg($args, Menu::baseUrl()));

        exit;
    }
}
