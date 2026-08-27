<?php

namespace FluentCartBulkOrder\Export;

defined('ABSPATH') || exit;

/**
 * The one place order export attaches itself to WordPress — and the one place
 * that decides who is allowed to download somebody's order.
 *
 * ---------------------------------------------------------------------------
 * AN EXPORT URL IS A CUSTOMER'S ORDER. THREE THINGS GUARD IT.
 * ---------------------------------------------------------------------------
 *
 *   1. LOGGED IN. The action is registered on `admin_post_` with NO `nopriv`
 *      sibling, so a logged-out request never reaches any code of ours.
 *   2. A NONCE SCOPED TO THIS ORDER. Not one nonce for "export"; one per order
 *      id, so a link for order 12 cannot be replayed against order 13 by
 *      editing the query string.
 *   3. OWNERSHIP, CHECKED ON THE SERVER. Either the requester holds the
 *      capability, or the order's customer resolves to the requester's own
 *      WordPress user. Nothing about the URL is trusted.
 *
 * Note that (2) alone is not access control and is not treated as such: a WP
 * nonce is tied to the user it was created for, but it is also visible in every
 * browser history, server log and referrer header that a link produces. (3) is
 * what actually refuses the request, and it runs on every single export.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS ONE IS A GET WHEN THE QUOTE SCREEN'S BUTTONS ARE POSTS
 * ---------------------------------------------------------------------------
 *
 * QuoteReviewScreen argues at length that its actions must be POSTs, because a
 * prefetch or a link scanner following a URL would create an order nobody
 * decided to create. None of that applies here: an export CHANGES NOTHING. The
 * worst a prefetcher can do is generate a file it then throws away, and a
 * download has to be a plain link a browser can navigate to.
 *
 * The two rules are the same rule — a state-changing action needs a POST, a
 * read does not — and it is worth saying so out loud, because "the other admin
 * screen uses POST" is exactly the kind of consistency that gets applied
 * without checking whether the reason transfers.
 *
 * @see \FluentCartBulkOrder\Export\OrderReceiptView The PDF half.
 * @see \FluentCartBulkOrder\Export\OrderExportScreen The owner's list screen.
 */
class OrderExportFlow
{
    /**
     * The `action` value the export links carry.
     */
    const ACTION = 'fcbo_export_order';

    /**
     * The capability an owner needs to export an order that is not their own.
     *
     * `manage_options`, matching the plugin's settings page, the wholesale
     * review screen and the quote review screen. An order export carries a
     * customer's name, email and purchase history, so it does not get a weaker
     * gate than the screens that show the same data.
     */
    const CAPABILITY = 'manage_options';

    /**
     * The two things an order can be exported as.
     */
    const FORMAT_CSV = 'csv';
    const FORMAT_PDF = 'pdf';

    /**
     * Wire the export into WordPress.
     *
     * @return void
     */
    public static function register()
    {
        // NO `nopriv` sibling, deliberately. @see the class docblock.
        add_action('admin_post_' . self::ACTION, [self::class, 'handleExport']);

        // The buyer's two surfaces, both rendered in PHP: the thank-you page
        // they land on straight after paying, and the order in their own
        // account dashboard. Deliberately not the bulk-order plugin's saved
        // orders table — that surface is built by JavaScript, and putting the
        // links where FluentCart already renders an order means no new script,
        // no new client-side strings, and the links sit next to the order they
        // belong to rather than next to a re-order button.
        add_action('fluent_cart/receipt/thank_you/after_footer_buttons', [self::class, 'renderReceiptLinks']);
        add_filter('fluent_cart/customer/order_details_section_parts', [self::class, 'addToCustomerOrder'], 10, 2);

        if (is_admin()) {
            add_action('admin_menu', [self::class, 'registerScreen']);
        }
    }

    /**
     * The nonce action for ONE order.
     *
     * Scoped to the id so a link cannot be pointed at a different order.
     *
     * @param int $orderId
     * @return string
     */
    public static function nonceAction($orderId)
    {
        return self::ACTION . '_' . (int) $orderId;
    }

    /**
     * A ready-to-use export link.
     *
     * @param int    $orderId
     * @param string $format self::FORMAT_CSV or self::FORMAT_PDF.
     * @return string
     */
    public static function url($orderId, $format = self::FORMAT_CSV)
    {
        $url = add_query_arg(
            [
                'action' => self::ACTION,
                'order'  => (int) $orderId,
                'format' => $format === self::FORMAT_PDF ? self::FORMAT_PDF : self::FORMAT_CSV,
            ],
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, self::nonceAction($orderId));
    }

    /**
     * Serve one order as a file.
     *
     * Ends in a download or a wp_die(); never returns.
     *
     * @return void
     */
    public static function handleExport()
    {
        self::load();

        // `$_GET` and not `$_REQUEST`: url() builds a GET link and nothing else
        // is meant to reach here. `$_REQUEST` is assembled from PHP's
        // `request_order` ini setting, which on some hosts still includes
        // cookies — a needlessly wide door for a value that decides which
        // customer's order is about to be read.
        //
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified on the next line, and its action needs this id to be built first.
        $orderId = isset($_GET['order']) ? absint($_GET['order']) : 0;

        check_admin_referer(self::nonceAction($orderId));

        $order = OrderSnapshot::find($orderId);

        // A valid nonce says who sent the request. It says nothing about whose
        // order this is, which is why ownership is its own check.
        if (!$order || !self::canExport($order)) {
            wp_die(
                esc_html__('You do not have permission to export this order.', 'fluent-cart-bulk-order'),
                '',
                ['response' => 403]
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
        $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : self::FORMAT_CSV;

        if ($format === self::FORMAT_PDF) {
            OrderReceiptView::serve($order);

            exit;
        }

        self::serveCsv($order);

        exit;
    }

    /**
     * Whether the CURRENT user may export this order.
     *
     * @param object $order A FluentCart Order model.
     * @return bool
     */
    public static function canExport($order)
    {
        if (current_user_can(self::CAPABILITY)) {
            return true;
        }

        $userId = get_current_user_id();

        // 0 === 0 would make every logged-out request the owner of every
        // guest order, so the requester has to be a real user before the
        // comparison is worth making.
        if ($userId < 1) {
            return false;
        }

        return OrderSnapshot::ownerId($order) === $userId;
    }

    /**
     * Stream the CSV.
     *
     * @param object $order A FluentCart Order model.
     * @return void
     */
    private static function serveCsv($order)
    {
        $snapshot = OrderSnapshot::build($order);
        $body     = OrderCsv::render($snapshot);
        $filename = OrderCsv::filename($snapshot, 'csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));
        // The file is a download, not a page. Without this a browser is free to
        // sniff the bytes, decide they look like something else, and render
        // them — with a product title as the content.
        header('X-Content-Type-Options: nosniff');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this is a CSV file body, not HTML. OrderCsv quotes every field, doubles embedded quotes and neutralises formula cells; escaping it for HTML here would corrupt the file.
        echo $body;
    }

    /**
     * Add the buyer's export links to the thank-you page.
     *
     * ---------------------------------------------------------------------
     * A GUEST CHECKOUT SEES NO LINKS, AND THAT IS THE DECISION
     * ---------------------------------------------------------------------
     *
     * canExport() needs a WordPress user to compare the order's customer
     * against, so a shopper who bought without an account gets nothing here.
     * FluentCart's own thank-you page authorises a guest by the order's uuid
     * in the URL, and this plugin deliberately does not adopt that: a
     * link-is-the-password download of somebody's order is exactly what the
     * three checks on an export URL exist to avoid.
     *
     * The audience this feature is for has accounts by definition — a
     * wholesale buyer is a role on a user — so the trade is a narrow one. A
     * guest who wants the file signs in, or asks the store, which can export
     * any order from its own screen.
     *
     * @param mixed $config ['order' => Order, ...]
     * @return void
     */
    public static function renderReceiptLinks($config)
    {
        self::load();

        $order = is_array($config) && isset($config['order']) ? $config['order'] : null;

        if (!is_object($order) || empty($order->id) || !self::canExport($order)) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- linksHtml() escapes every value it interpolates and returns finished markup.
        echo self::linksHtml((int) $order->id);
    }

    /**
     * Add the buyer's export links to one order in their account dashboard.
     *
     * Appends rather than assigns, so another extension writing to the same
     * slot is not overwritten. @see \FluentCartBulkOrder\Checkout\PoField for
     * the same pattern on a different slot of the same filter.
     *
     * @param mixed $parts   Slot name => HTML.
     * @param mixed $context ['order' => Order, 'formattedData' => array]
     * @return array
     */
    public static function addToCustomerOrder($parts, $context)
    {
        self::load();

        $parts = is_array($parts) ? $parts : [];
        $order = is_array($context) && isset($context['order']) ? $context['order'] : null;

        if (!is_object($order) || empty($order->id) || !self::canExport($order)) {
            return $parts;
        }

        $existing = isset($parts['end_of_order']) && is_string($parts['end_of_order'])
            ? $parts['end_of_order']
            : '';

        $parts['end_of_order'] = $existing . self::linksHtml((int) $order->id);

        return $parts;
    }

    /**
     * The two links, as finished, escaped markup.
     *
     * The PDF link's LABEL depends on what the store can actually do. A store
     * with FluentCart's PDF stack installed gets a real PDF and the link says
     * "PDF"; a store without it gets a print-ready page and the link says
     * "Print / save as PDF", which is what will actually happen when they press
     * it. Promising a PDF and opening a print dialog is the kind of small lie
     * that makes a buyer distrust everything else on the page.
     *
     * @param int $orderId
     * @return string
     */
    public static function linksHtml($orderId)
    {
        return sprintf(
            '<p class="fcbo-order-export"><a class="fcbo-order-export-csv" href="%1$s">%2$s</a> '
            . '<a class="fcbo-order-export-pdf" href="%3$s">%4$s</a></p>',
            esc_url(self::url($orderId, self::FORMAT_CSV)),
            esc_html__('Download CSV', 'fluent-cart-bulk-order'),
            esc_url(self::url($orderId, self::FORMAT_PDF)),
            esc_html(
                OrderReceiptView::pdfAvailable()
                    ? __('Download PDF', 'fluent-cart-bulk-order')
                    : __('Print / save as PDF', 'fluent-cart-bulk-order')
            )
        );
    }

    /**
     * Add the owner's order screen to the Settings menu.
     *
     * @return void
     */
    public static function registerScreen()
    {
        // Only the screen class, not the whole feature. `admin_menu` fires on
        // EVERY wp-admin request for every logged-in user, and adding a menu
        // entry needs nothing but a slug and a capability. The rest is loaded
        // by render(), which runs on one page.
        require_once __DIR__ . '/OrderExportScreen.php';

        OrderExportScreen::addMenu();
    }

    /**
     * Load the classes the flow needs.
     *
     * @return void
     */
    public static function load()
    {
        require_once dirname(__DIR__) . '/Checkout/PoNumber.php';
        require_once dirname(__DIR__) . '/Checkout/PoSettings.php';
        require_once __DIR__ . '/OrderCsv.php';
        require_once __DIR__ . '/OrderSnapshot.php';
        require_once __DIR__ . '/OrderReceiptView.php';
        require_once __DIR__ . '/OrderExportScreen.php';
    }
}
