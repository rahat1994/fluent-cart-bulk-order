<?php

namespace FluentCartBulkOrder\Export;

defined('ABSPATH') || exit;

/**
 * One order, as a CSV file — and the two rules that keep that file safe.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE HAS NO WORDPRESS IN IT
 * ---------------------------------------------------------------------------
 *
 * Same reason as QuoteInput and PoNumber: everything here is a map from an
 * array to a string, and the two rules worth pinning — the formula guard and
 * the filename — both fail SILENTLY when they are wrong. A cell that opens a
 * spreadsheet's formula parser still downloads fine; a filename carrying a
 * slash still produces a file, just not the one anyone meant. Neither shows up
 * in manual testing.
 *
 * ---------------------------------------------------------------------------
 * RULE 1 — A CELL IS NOT A FORMULA
 * ---------------------------------------------------------------------------
 *
 * Excel, LibreOffice and Google Sheets all treat a cell beginning `=`, `+`, `-`
 * or `@` as a formula, and a formula in a downloaded file runs on the machine
 * of whoever opens it. The values in an order export are a buyer's own product
 * names, SKUs and PO numbers — all of them attacker-typed from the store's
 * point of view, and all of them opened later by an accountant who trusts the
 * file because the store sent it.
 *
 * escapeCell() therefore prefixes any such cell with a single quote, which the
 * spreadsheet consumes as "this is text". @see escapeCell() for why plain
 * numbers are deliberately exempt.
 *
 * ---------------------------------------------------------------------------
 * RULE 2 — ONE TABLE, NOT TWO SECTIONS
 * ---------------------------------------------------------------------------
 *
 * The obvious layout is an order block, a blank line, then the items. It reads
 * nicely and it is useless: no spreadsheet can sort, filter or pivot it, and no
 * importer can parse it without special-casing this plugin.
 *
 * So the file is ONE table, one row per line item, with the order-level columns
 * repeated on every row. Repetition is the price of a file that a buyer's
 * accounts system can actually read, and it is the shape every order export
 * worth importing uses.
 *
 * @see \FluentCartBulkOrder\Export\OrderSnapshot Which builds the array this
 *      class formats, and which is the half that talks to FluentCart.
 */
class OrderCsv
{
    /**
     * The byte-order mark, written first.
     *
     * Without it Excel on Windows reads a UTF-8 file as the system code page,
     * and every product name with an accent in it arrives as mojibake. Every
     * other reader ignores it. Three bytes is a cheap price for the one
     * spreadsheet most buyers actually open the file in.
     */
    const BOM = "\xEF\xBB\xBF";

    /**
     * Row separator.
     *
     * CRLF, per RFC 4180. Excel accepts both; some older importers do not
     * accept a bare LF, and nothing rejects CRLF.
     */
    const EOL = "\r\n";

    /**
     * The shape of the order array this class formats, and the default for
     * every key.
     *
     * Money is in integer cents throughout, matching the rest of the plugin.
     * `date` arrives already formatted, because formatting a timestamp needs
     * the site's timezone and that is WordPress' business, not this file's.
     *
     * @var array<string, mixed>
     */
    const ORDER_DEFAULTS = [
        'id'             => 0,
        'number'         => '',
        'date'           => '',
        'status'         => '',
        'payment_status' => '',
        'po_number'      => '',
        'customer'       => '',
        'email'          => '',
        'currency'       => '',
        'subtotal'       => 0,
        'discount'       => 0,
        'shipping'       => 0,
        'tax'            => 0,
        'total'          => 0,
        'lines'          => [],
    ];

    /**
     * The shape of one line, and the default for every key.
     *
     * @var array<string, mixed>
     */
    const LINE_DEFAULTS = [
        'sku'             => '',
        'title'           => '',
        'variation_title' => '',
        'qty'             => 0,
        'unit_price'      => 0,
        'line_total'      => 0,
    ];

    /**
     * The characters a spreadsheet reads as "a formula starts here".
     *
     * Tab and carriage return are in the list because a cell beginning with one
     * of them can smuggle the next character into the formula position in some
     * readers. A PO number can never contain either — PoNumber::sanitize()
     * collapses them — but a product title imported from elsewhere can.
     *
     * @var string[]
     */
    const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * The column headings, in the order the rows use them.
     *
     * Translated, because the person opening this file is a buyer or a store
     * owner rather than a machine. A store that needs stable machine-readable
     * headings can filter them; a buyer who cannot read the column they are
     * looking for has an export that does not work.
     *
     * @return string[]
     */
    public static function headers()
    {
        return [
            __('Order', 'fluent-cart-bulk-order'),
            __('Date', 'fluent-cart-bulk-order'),
            __('Order status', 'fluent-cart-bulk-order'),
            __('Payment status', 'fluent-cart-bulk-order'),
            __('PO number', 'fluent-cart-bulk-order'),
            __('Customer', 'fluent-cart-bulk-order'),
            __('Email', 'fluent-cart-bulk-order'),
            __('SKU', 'fluent-cart-bulk-order'),
            __('Product', 'fluent-cart-bulk-order'),
            __('Variant', 'fluent-cart-bulk-order'),
            __('Quantity', 'fluent-cart-bulk-order'),
            __('Unit price', 'fluent-cart-bulk-order'),
            __('Line total', 'fluent-cart-bulk-order'),
            __('Order subtotal', 'fluent-cart-bulk-order'),
            __('Order discount', 'fluent-cart-bulk-order'),
            __('Order shipping', 'fluent-cart-bulk-order'),
            __('Order tax', 'fluent-cart-bulk-order'),
            __('Order total', 'fluent-cart-bulk-order'),
            __('Currency', 'fluent-cart-bulk-order'),
        ];
    }

    /**
     * Every row of the file, header included, as plain arrays.
     *
     * Separate from render() so the tests can assert on cells rather than on a
     * quoted string, and so a future XLSX writer would have somewhere to start.
     *
     * An order with no readable lines still produces ONE row, with the item
     * columns blank. A zero-byte download tells a buyer nothing; a row saying
     * "this order, no line items" tells them what they actually have.
     *
     * @param array<string, mixed> $order @see self::ORDER_DEFAULTS
     * @return array<int, array<int, string>>
     */
    public static function rows(array $order)
    {
        $order = array_merge(self::ORDER_DEFAULTS, $order);
        $lines = is_array($order['lines']) ? $order['lines'] : [];

        $rows = [self::headers()];

        if (!$lines) {
            $rows[] = self::row($order, self::LINE_DEFAULTS, false);

            return $rows;
        }

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $rows[] = self::row($order, array_merge(self::LINE_DEFAULTS, $line), true);
        }

        // Every line was unusable, which is not the same as there being none.
        // Still emit the order row rather than a header-only file.
        if (count($rows) === 1) {
            $rows[] = self::row($order, self::LINE_DEFAULTS, false);
        }

        return $rows;
    }

    /**
     * The finished file: BOM, header row, one row per line.
     *
     * @param array<string, mixed> $order @see self::ORDER_DEFAULTS
     * @return string
     */
    public static function render(array $order)
    {
        $out = self::BOM;

        foreach (self::rows($order) as $row) {
            $out .= self::line($row);
        }

        return $out;
    }

    /**
     * A safe download filename for an order.
     *
     * Built from the order's own number rather than its database id, because
     * that is the reference on the buyer's paperwork — but a store can set an
     * invoice prefix containing a slash, and a slash in a filename is a path.
     * Everything outside a conservative allowlist becomes a hyphen.
     *
     * @param array<string, mixed> $order     @see self::ORDER_DEFAULTS
     * @param string               $extension Without the dot.
     * @return string e.g. "order-INV-1042-2026-08-27.csv"
     */
    public static function filename(array $order, $extension = 'csv')
    {
        $order = array_merge(self::ORDER_DEFAULTS, $order);

        $reference = (string) $order['number'];

        if (trim($reference) === '') {
            // The id only stands in when there IS one. A zero would put a
            // meaningless "-0" in the filename of an order the reader is
            // already going to have trouble identifying.
            $reference = (int) $order['id'] > 0 ? (string) (int) $order['id'] : '';
        }

        // The date part is the first ten characters of an ISO-ish date string,
        // which is what OrderSnapshot writes. A `date` in any other shape is
        // simply dropped rather than guessed at.
        $date = '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $order['date'], $match)) {
            $date = $match[1];
        }

        $parts = ['order', $reference, $date];
        $slug  = self::slug(implode('-', array_filter($parts, 'strlen')));

        if ($slug === '') {
            $slug = 'order';
        }

        $extension = self::slug($extension);

        return $extension === '' ? $slug : $slug . '.' . $extension;
    }

    /**
     * Neutralise a cell a spreadsheet would otherwise run as a formula.
     *
     * Plain numbers are deliberately EXEMPT. Every money column in this file is
     * produced by self::money(), a negative total is an ordinary thing after a
     * refund, and prefixing `-12.50` with a quote would turn a number a buyer
     * needs to sum into text their spreadsheet will not add up. A value that is
     * entirely `-?digits[.digits]` cannot be a formula, so exempting it costs
     * nothing and keeps the file usable.
     *
     * @param mixed $value
     * @return string
     */
    public static function escapeCell($value)
    {
        $value = is_scalar($value) ? (string) $value : '';

        if ($value === '' || preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return $value;
        }

        return in_array($value[0], self::FORMULA_PREFIXES, true) ? "'" . $value : $value;
    }

    /**
     * Integer cents as a decimal string.
     *
     * Never number_format_i18n(): a CSV cell is read by a spreadsheet, and a
     * thousands separator or a comma decimal point turns one number into two
     * columns. The human-readable formatting belongs on the receipt view.
     *
     * @param mixed $cents
     * @return string
     */
    public static function money($cents)
    {
        return number_format(((int) $cents) / 100, 2, '.', '');
    }

    /**
     * One data row.
     *
     * @param array<string, mixed> $order    Merged over ORDER_DEFAULTS.
     * @param array<string, mixed> $line     Merged over LINE_DEFAULTS.
     * @param bool                 $hasLine  False when the item columns should
     *                                       be blank rather than zeroes.
     * @return array<int, string>
     */
    private static function row(array $order, array $line, $hasLine)
    {
        return [
            (string) ($order['number'] !== '' ? $order['number'] : $order['id']),
            (string) $order['date'],
            (string) $order['status'],
            (string) $order['payment_status'],
            (string) $order['po_number'],
            (string) $order['customer'],
            (string) $order['email'],
            $hasLine ? (string) $line['sku'] : '',
            $hasLine ? (string) $line['title'] : '',
            $hasLine ? (string) $line['variation_title'] : '',
            $hasLine ? (string) (int) $line['qty'] : '',
            $hasLine ? self::money($line['unit_price']) : '',
            $hasLine ? self::money($line['line_total']) : '',
            self::money($order['subtotal']),
            self::money($order['discount']),
            self::money($order['shipping']),
            self::money($order['tax']),
            self::money($order['total']),
            (string) $order['currency'],
        ];
    }

    /**
     * One row as a CSV line.
     *
     * Every field is quoted, unconditionally. fputcsv() would quote only what
     * it thinks needs it, and its `$escape` argument has changed behaviour
     * across PHP versions in ways that put a stray backslash in a product name.
     * Quoting everything is RFC 4180, is the same on every version this plugin
     * supports, and makes the expected output of a test something a reader can
     * write down.
     *
     * @param array<int, string> $row
     * @return string
     */
    private static function line(array $row)
    {
        $cells = [];

        foreach ($row as $cell) {
            $cells[] = '"' . str_replace('"', '""', self::escapeCell($cell)) . '"';
        }

        return implode(',', $cells) . self::EOL;
    }

    /**
     * Reduce a string to filename-safe characters.
     *
     * The DOT is not in the allowlist, and that is the point. The only dot this
     * filename is allowed is the one filename() adds before the extension, so
     * neither a `..` walked out of a reference nor a second extension can
     * survive a store's invoice prefix.
     *
     * @param string $value
     * @return string
     */
    private static function slug($value)
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $value);
        $value = preg_replace('/-{2,}/', '-', (string) $value);

        return trim((string) $value, '-_');
    }
}
