<?php

namespace FluentCartBulkOrder\Export;

use FluentCartBulkOrder\Checkout\PoSettings;

defined('ABSPATH') || exit;

/**
 * The PDF half of "export an order as CSV or PDF" — and an honest account of
 * what it can and cannot do.
 *
 * ---------------------------------------------------------------------------
 * THIS PLUGIN DOES NOT CONTAIN A PDF RENDERER, AND WILL NOT
 * ---------------------------------------------------------------------------
 *
 * Turning HTML into PDF bytes needs a rendering engine. The smallest credible
 * one — Dompdf, mPDF, TCPDF — is several megabytes of vendored third-party
 * code with its own font handling and its own security history. This plugin has
 * NO runtime composer dependencies at all, and it is headed for the WordPress
 * .org directory, where every one of those megabytes is reviewed, downloaded
 * and updated by every store that installs it. Adding one so that a button can
 * say "PDF" is not a trade worth making.
 *
 * So there are two paths, and which one a store gets depends on the store:
 *
 *   1. THE HOST'S OWN PDF, WHEN THE STORE HAS IT. FluentCart delegates receipt
 *      PDF generation through a filter, `fluent_cart/pdf/generate_receipt`,
 *      which is answered when FluentCart Pro and the Fluent PDF plugin (which
 *      bundles mPDF) are both installed — its own gate for that is
 *      `OrderService::canGenerateReceiptPdf()` (fluent-cart 1.5.5,
 *      app/Services/OrderService.php:651). Where that is true, this class asks
 *      the host and streams a real PDF. The store already paid for the engine;
 *      shipping a second one would be absurd.
 *
 *   2. A PRINT-READY PAGE EVERYWHERE ELSE. A self-contained, print-styled
 *      receipt that opens the browser's print dialog, where "Save as PDF" is
 *      one press on every current browser and operating system.
 *
 * The link's LABEL follows the truth — @see OrderExportFlow::linksHtml(), which
 * says "Download PDF" only when case 1 applies and "Print / save as PDF"
 * otherwise. A button that promises a PDF and opens a print dialog is a small
 * lie that costs more trust than the feature is worth.
 */
class OrderReceiptView
{
    /**
     * Whether the host can produce a real PDF on this install.
     *
     * Every step is guarded, because all of it is host implementation detail
     * rather than a published contract: the class may not exist, the method may
     * be renamed, and the filter may be answered by nothing. A store where any
     * of that is true gets the print view, which always works.
     *
     * @return bool
     */
    public static function pdfAvailable()
    {
        $service = '\FluentCart\App\Services\OrderService';

        if (!class_exists($service) || !method_exists($service, 'canGenerateReceiptPdf')) {
            return false;
        }

        return (bool) call_user_func([$service, 'canGenerateReceiptPdf']);
    }

    /**
     * Send the order as a PDF if the host can make one, and as a print-ready
     * page otherwise.
     *
     * @param object $order A FluentCart Order model.
     * @return void
     */
    public static function serve($order)
    {
        if (self::pdfAvailable() && self::servePdf($order)) {
            return;
        }

        self::renderPrintable($order);
    }

    /**
     * Ask FluentCart for a receipt PDF and stream it.
     *
     * Returns FALSE rather than dying when the host declines, so serve() can
     * fall through to the print view. That matters: `canGenerateReceiptPdf()`
     * can be true while the generation itself still fails — a missing temp
     * directory, a font problem, a template the store has emptied — and a buyer
     * pressing a download link deserves their receipt rather than a blank page.
     *
     * @param object $order
     * @return bool Whether a file was sent.
     */
    private static function servePdf($order)
    {
        $path = apply_filters('fluent_cart/pdf/generate_receipt', null, [
            'order'       => $order,
            'template_id' => 'order_receipt',
        ]);

        if (!is_string($path) || $path === '' || !file_exists($path) || !is_readable($path)) {
            return false;
        }

        $filename = OrderCsv::filename(OrderSnapshot::build($order), 'pdf');

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');

        // A temp file the host generated for this request, read straight to the
        // browser. Not WP_Filesystem: that API is for writing into the site's
        // own directories under credentials, not for streaming a byte range to
        // stdout, and buffering the whole file into memory to echo it would be
        // worse in every way.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile($path);

        // The host's own receipt handler deletes the temp file after streaming
        // it, and so do we — an unswept temp directory is the store's problem
        // and we asked for the file. wp_delete_file() rather than unlink(): it
        // is WordPress' own wrapper, it does not warn on a file that is already
        // gone, and it gives a host filesystem layer a say.
        wp_delete_file($path);

        return true;
    }

    /**
     * Render the printable receipt.
     *
     * A COMPLETE, self-contained HTML document rather than a themed page, and
     * deliberately: this is a document a buyer files and an owner sends, and
     * a theme's header, navigation, cookie banner and footer are all things
     * that end up on the printed page or in the saved PDF. Everything it needs
     * is inline, so it prints the same on every theme.
     *
     * @param object $order A FluentCart Order model.
     * @return void
     */
    private static function renderPrintable($order)
    {
        $snapshot = OrderSnapshot::build($order);

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $title = sprintf(
            /* translators: %s: the order's reference, e.g. an invoice number. */
            __('Order %s', 'fluent-cart-bulk-order'),
            $snapshot['number']
        );

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo esc_html($title); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1d2327; margin: 0; padding: 32px; }
        .fcbo-receipt { max-width: 760px; margin: 0 auto; }
        .fcbo-receipt h1 { font-size: 22px; margin: 0 0 4px; }
        .fcbo-receipt .fcbo-store { font-size: 14px; color: #646970; margin: 0 0 24px; }
        .fcbo-meta { width: 100%; border-collapse: collapse; margin: 0 0 24px; }
        .fcbo-meta th { text-align: left; width: 40%; padding: 4px 8px 4px 0; font-weight: 600; vertical-align: top; }
        .fcbo-meta td { padding: 4px 0; vertical-align: top; }
        .fcbo-lines { width: 100%; border-collapse: collapse; margin: 0 0 16px; font-size: 14px; }
        .fcbo-lines th, .fcbo-lines td { border-bottom: 1px solid #dcdcde; padding: 8px 6px; text-align: left; }
        .fcbo-lines th { background: #f6f7f7; }
        .fcbo-num { text-align: right; }
        .fcbo-totals { width: 100%; border-collapse: collapse; font-size: 14px; }
        .fcbo-totals th { text-align: right; padding: 4px 6px; font-weight: 400; }
        .fcbo-totals td { text-align: right; padding: 4px 6px; width: 140px; }
        .fcbo-totals tr:last-child th, .fcbo-totals tr:last-child td { font-weight: 700; border-top: 2px solid #1d2327; }
        .fcbo-actions { margin: 28px 0 0; }
        .fcbo-actions button { font: inherit; padding: 8px 16px; cursor: pointer; }
        @media print { .fcbo-actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="fcbo-receipt">
    <h1><?php echo esc_html($title); ?></h1>
    <p class="fcbo-store"><?php echo esc_html(get_bloginfo('name')); ?></p>

    <table class="fcbo-meta">
        <tbody>
        <?php
        foreach (self::metaRows($snapshot) as $label => $value) {
            printf(
                '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
                esc_html($label),
                esc_html($value)
            );
        }
        ?>
        </tbody>
    </table>

    <table class="fcbo-lines">
        <thead>
        <tr>
            <th scope="col"><?php esc_html_e('Product', 'fluent-cart-bulk-order'); ?></th>
            <th scope="col"><?php esc_html_e('SKU', 'fluent-cart-bulk-order'); ?></th>
            <th scope="col" class="fcbo-num"><?php esc_html_e('Quantity', 'fluent-cart-bulk-order'); ?></th>
            <th scope="col" class="fcbo-num"><?php esc_html_e('Unit price', 'fluent-cart-bulk-order'); ?></th>
            <th scope="col" class="fcbo-num"><?php esc_html_e('Line total', 'fluent-cart-bulk-order'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($snapshot['lines'])) : ?>
            <tr><td colspan="5"><?php esc_html_e('This order has no line items.', 'fluent-cart-bulk-order'); ?></td></tr>
        <?php else : ?>
            <?php foreach ($snapshot['lines'] as $line) : ?>
                <tr>
                    <td>
                        <?php echo esc_html($line['title']); ?>
                        <?php
                        // A variant literally called "Default" is what FluentCart
                        // shows for a product with only one, and repeating it under
                        // every row is noise. Same rule as the quote review screen.
                        if ($line['variation_title'] !== '' && strcasecmp((string) $line['variation_title'], 'default') !== 0) {
                            echo '<br /><small>' . esc_html($line['variation_title']) . '</small>';
                        }
                        ?>
                    </td>
                    <td><?php echo esc_html($line['sku']); ?></td>
                    <td class="fcbo-num"><?php echo esc_html((string) (int) $line['qty']); ?></td>
                    <td class="fcbo-num"><?php echo esc_html(self::money($line['unit_price'])); ?></td>
                    <td class="fcbo-num"><?php echo esc_html(self::money($line['line_total'])); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <table class="fcbo-totals">
        <tbody>
        <?php
        foreach (self::totalRows($snapshot) as $label => $amount) {
            printf(
                '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
                esc_html($label),
                esc_html(self::money($amount))
            );
        }
        ?>
        </tbody>
    </table>

    <div class="fcbo-actions">
        <button type="button" id="fcbo-print"><?php esc_html_e('Print or save as PDF', 'fluent-cart-bulk-order'); ?></button>
    </div>
</div>
<?php
// Four lines, and the only script on the document. An `onclick` attribute would
// be shorter and would be the one thing on this page a Content-Security-Policy
// header refuses. There is no text in here to translate — the button's label is
// rendered above, in PHP, like every other string in this plugin.
?>
<script>
    document.getElementById('fcbo-print').addEventListener('click', function () {
        window.print();
    });
</script>
</body>
</html>
        <?php
    }

    /**
     * The order's own facts, as label => value.
     *
     * The PO number is included only when the order carries one, so an order
     * placed before the field existed does not show an empty row.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, string>
     */
    private static function metaRows(array $snapshot)
    {
        $rows = [
            __('Order', 'fluent-cart-bulk-order') => (string) $snapshot['number'],
            __('Date', 'fluent-cart-bulk-order')  => (string) $snapshot['date'],
        ];

        if ((string) $snapshot['po_number'] !== '') {
            $rows[PoSettings::label()] = (string) $snapshot['po_number'];
        }

        if ((string) $snapshot['customer'] !== '') {
            $rows[__('Customer', 'fluent-cart-bulk-order')] = (string) $snapshot['customer'];
        }

        if ((string) $snapshot['email'] !== '') {
            $rows[__('Email', 'fluent-cart-bulk-order')] = (string) $snapshot['email'];
        }

        $rows[__('Order status', 'fluent-cart-bulk-order')]   = (string) $snapshot['status'];
        $rows[__('Payment status', 'fluent-cart-bulk-order')] = (string) $snapshot['payment_status'];

        return $rows;
    }

    /**
     * The totals block, as label => cents.
     *
     * Zero rows are dropped except the subtotal and the total, which are always
     * shown — a receipt with no "Total" line is not a receipt.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, int>
     */
    private static function totalRows(array $snapshot)
    {
        $rows = [
            __('Subtotal', 'fluent-cart-bulk-order') => (int) $snapshot['subtotal'],
        ];

        $optional = [
            __('Discount', 'fluent-cart-bulk-order') => (int) $snapshot['discount'],
            __('Shipping', 'fluent-cart-bulk-order') => (int) $snapshot['shipping'],
            __('Tax', 'fluent-cart-bulk-order')      => (int) $snapshot['tax'],
        ];

        foreach ($optional as $label => $amount) {
            if ($amount !== 0) {
                $rows[$label] = $amount;
            }
        }

        $rows[__('Total', 'fluent-cart-bulk-order')] = (int) $snapshot['total'];

        return $rows;
    }

    /**
     * Cents as the store's own money string.
     *
     * The human-readable formatting, unlike OrderCsv::money(), which has to
     * stay machine-readable. @see \FluentCartBulkOrder\Export\OrderCsv::money()
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
