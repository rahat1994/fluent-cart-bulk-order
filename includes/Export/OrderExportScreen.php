<?php

namespace FluentCartBulkOrder\Export;

use FluentCartBulkOrder\Checkout\PoSettings;

defined('ABSPATH') || exit;

/**
 * The owner's screen: recent orders, their PO numbers, and a file for each.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS SCREEN EXISTS AT ALL
 * ---------------------------------------------------------------------------
 *
 * It would be better if this were a column on FluentCart's own order list and a
 * line on its own order detail. It cannot be. That admin is a Vue single-page
 * app with no PHP render hook and no JavaScript slot to place a panel in — the
 * only extension point it offers registers whole new SPA routes (fluent-cart
 * 1.5.5, assets/admin_hooks.js, `fluent_cart_routes`). Building a second SPA
 * inside the host's, in a plugin with no build step, to show one field, is not
 * a proportionate answer.
 *
 * So the PO number travels into the host's own order payload where it can
 * (@see \FluentCartBulkOrder\Checkout\PoField::addToOrderPayload()), and the
 * place an owner READS it is here — beside the export links, which is also
 * where they would go looking to send a buyer their paperwork. The two halves
 * of this roadmap item want the same screen, which is why they share one.
 *
 * ---------------------------------------------------------------------------
 * READ-ONLY, WHICH IS WHY THERE IS NO NONCE ON THE SCREEN ITSELF
 * ---------------------------------------------------------------------------
 *
 * Nothing here changes anything: it lists orders and links to downloads. The
 * capability is checked twice — once for menu visibility and once on render,
 * because add_options_page() hides a menu item but does not stop a direct URL —
 * and the search term is only ever used as a bound query parameter and escaped
 * on the way back out. The DOWNLOADS each carry their own per-order nonce;
 * @see \FluentCartBulkOrder\Export\OrderExportFlow.
 */
class OrderExportScreen
{
    /**
     * Admin page slug.
     */
    const PAGE_SLUG = 'fcbo-order-exports';

    /**
     * Orders per page.
     */
    const PER_PAGE = 20;

    /**
     * Add the menu entry, beside the plugin's other screens.
     *
     * @return void
     */
    public static function addMenu()
    {
        $title = __('Order Exports', 'fluent-cart-bulk-order');

        add_options_page(
            $title,
            $title,
            OrderExportFlow::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * This screen's URL.
     *
     * @return string
     */
    public static function pageUrl()
    {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG);
    }

    /**
     * Render the screen.
     *
     * @return void
     */
    public static function render()
    {
        // Not redundant with the menu capability. add_options_page() controls
        // visibility; this controls access.
        if (!current_user_can(OrderExportFlow::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to export orders.', 'fluent-cart-bulk-order'));
        }

        // The menu entry loads only this file; everything the table reads is
        // pulled in here, on the one request that draws it.
        OrderExportFlow::load();

        $search = self::requestedSearch();
        $page   = self::requestedPage();
        $result = self::page($search, $page);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Order Exports', 'fluent-cart-bulk-order') . '</h1>';

        printf(
            '<p>%s</p>',
            esc_html__(
                'Every order in the store, newest first, with the purchase-order number the buyer gave at checkout. Download one as a CSV a buyer can file, or as a printable receipt.',
                'fluent-cart-bulk-order'
            )
        );

        self::renderSearchBox($search);

        if (!$result['orders']) {
            printf(
                '<p>%s</p>',
                esc_html(
                    $search === ''
                        ? __('There are no orders yet.', 'fluent-cart-bulk-order')
                        : __('No order matches that reference or PO number.', 'fluent-cart-bulk-order')
                )
            );

            echo '</div>';

            return;
        }

        self::renderTable($result['orders']);
        self::renderPagination($search, $page, $result['total']);

        echo '</div>';
    }

    /**
     * One page of orders, plus the total the pagination needs.
     *
     * @param string $search
     * @param int    $page
     * @return array{orders: array, total: int}
     */
    private static function page($search, $page)
    {
        if (!class_exists(\FluentCart\App\Models\Order::class)) {
            return ['orders' => [], 'total' => 0];
        }

        $query = \FluentCart\App\Models\Order::query()->with(['customer']);

        if ($search !== '') {
            self::applySearch($query, $search);
        }

        $total = (int) $query->count();

        $orders = $query
            ->orderBy('created_at', 'desc')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get();

        return ['orders' => $orders, 'total' => $total];
    }

    /**
     * Narrow the query to one reference, or one PO number.
     *
     * Wrapped in its own closure group so the OR branches cannot escape and
     * widen an outer condition a future caller adds — the classic way a search
     * box quietly starts returning everything.
     *
     * The term is passed as a BOUND value, never interpolated. `esc_like()`
     * neutralises the `%` and `_` a buyer's reference can legitimately contain,
     * which would otherwise turn one order's PO number into a wildcard.
     *
     * @param object $query
     * @param string $search
     * @return void
     */
    private static function applySearch($query, $search)
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($search) . '%';

        $query->where(function ($outer) use ($search, $like) {
            $outer->where('invoice_no', 'LIKE', $like);

            if (ctype_digit($search)) {
                $outer->orWhere('id', (int) $search);
            }

            $outer->orWhereHas('orderMeta', function ($meta) use ($like) {
                $meta->where('meta_key', PoSettings::META_KEY)
                    ->where('meta_value', 'LIKE', $like);
            });
        });
    }

    /**
     * The search form.
     *
     * A plain GET form, because the result is a bookmarkable list and not a
     * change to anything.
     *
     * @param string $search
     * @return void
     */
    private static function renderSearchBox($search)
    {
        printf(
            '<form method="get" action="%s"><p class="search-box">',
            esc_url(admin_url('options-general.php'))
        );

        printf('<input type="hidden" name="page" value="%s" />', esc_attr(self::PAGE_SLUG));

        printf(
            '<label class="screen-reader-text" for="fcbo-order-search">%s</label>',
            esc_html__('Search orders', 'fluent-cart-bulk-order')
        );

        printf(
            '<input type="search" id="fcbo-order-search" name="s" value="%1$s" placeholder="%2$s" />',
            esc_attr($search),
            esc_attr__('Order number or PO number', 'fluent-cart-bulk-order')
        );

        printf(
            '<input type="submit" class="button" value="%s" />',
            esc_attr__('Search', 'fluent-cart-bulk-order')
        );

        echo '</p></form>';
    }

    /**
     * The order table.
     *
     * @param iterable $orders
     * @return void
     */
    private static function renderTable($orders)
    {
        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Order', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Date', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Customer', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html(PoSettings::label()) . '</th>';
        echo '<th scope="col">' . esc_html__('Total', 'fluent-cart-bulk-order') . '</th>';
        echo '<th scope="col">' . esc_html__('Export', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($orders as $order) {
            self::renderRow($order);
        }

        echo '</tbody></table>';
    }

    /**
     * One order.
     *
     * @param object $order
     * @return void
     */
    private static function renderRow($order)
    {
        $orderId  = (int) $order->id;
        $po       = PoSettings::forOrder($order);
        $customer = isset($order->customer) ? $order->customer : null;

        echo '<tr>';

        printf(
            '<td><a href="%1$s">%2$s</a></td>',
            esc_url(self::hostOrderUrl($orderId)),
            esc_html(OrderSnapshot::reference($order))
        );

        printf('<td>%s</td>', esc_html(OrderSnapshot::date($order)));

        printf(
            '<td>%s</td>',
            esc_html($customer && isset($customer->full_name) ? (string) $customer->full_name : '')
        );

        // An em dash rather than an empty cell, so "this order has no PO number"
        // reads as an answer instead of a rendering bug.
        printf(
            '<td>%s</td>',
            esc_html($po !== '' ? $po : '—')
        );

        printf(
            '<td>%s</td>',
            esc_html(self::money(isset($order->total_amount) ? $order->total_amount : 0))
        );

        printf(
            '<td><a href="%1$s">%2$s</a> | <a href="%3$s">%4$s</a></td>',
            esc_url(OrderExportFlow::url($orderId, OrderExportFlow::FORMAT_CSV)),
            esc_html__('CSV', 'fluent-cart-bulk-order'),
            esc_url(OrderExportFlow::url($orderId, OrderExportFlow::FORMAT_PDF)),
            esc_html(
                OrderReceiptView::pdfAvailable()
                    ? __('PDF', 'fluent-cart-bulk-order')
                    : __('Print', 'fluent-cart-bulk-order')
            )
        );

        echo '</tr>';
    }

    /**
     * The order's own page inside FluentCart.
     *
     * FluentCart's admin is a single-page app behind `admin.php?page=fluent-cart`
     * with a hash route, and there is no accessor for the URL, so it is built by
     * hand — the same way QuoteOrder does it, and for the same reason.
     *
     * @param int $orderId
     * @return string
     */
    private static function hostOrderUrl($orderId)
    {
        return admin_url('admin.php?page=fluent-cart#/orders/' . (int) $orderId);
    }

    /**
     * The search term the admin asked for.
     *
     * @return string
     */
    private static function requestedSearch()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on a capability-checked screen.
        if (!isset($_GET['s'])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = sanitize_text_field(wp_unslash($_GET['s']));

        // Bounded so a pasted essay cannot become a LIKE pattern scanned across
        // the whole order-meta table.
        return substr(trim($search), 0, 100);
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
     * Page links, in the standard admin shape.
     *
     * @param string $search
     * @param int    $page
     * @param int    $total
     * @return void
     */
    private static function renderPagination($search, $page, $total)
    {
        $pages = (int) ceil($total / self::PER_PAGE);

        if ($pages < 2) {
            return;
        }

        $args = ['page' => self::PAGE_SLUG];

        if ($search !== '') {
            $args['s'] = $search;
        }

        $base = add_query_arg($args, admin_url('options-general.php'));

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
     * Cents as the store's own money string.
     *
     * @param mixed $cents
     * @return string
     */
    private static function money($cents)
    {
        $sign = function_exists('fcbo_get_currency_sign') ? fcbo_get_currency_sign() : '';

        return $sign . number_format_i18n(((int) $cents) / 100, 2);
    }
}
