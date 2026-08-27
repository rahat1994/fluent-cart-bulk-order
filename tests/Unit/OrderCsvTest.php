<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Export\OrderCsv;
use PHPUnit\Framework\TestCase;

/**
 * The order export's file format.
 *
 * Two of these assertions are the reason the class exists at all: a cell that a
 * spreadsheet would run as a formula, and a filename built from a reference the
 * store owner controls. Both fail silently — the file downloads either way —
 * so neither is caught by opening the export and looking at it.
 */
class OrderCsvTest extends TestCase
{
    /**
     * A minimal but complete order, as OrderSnapshot would hand it over.
     *
     * @return array<string, mixed>
     */
    private function order(array $overrides = [])
    {
        return array_merge([
            'id'             => 12,
            'number'         => '1042',
            'date'           => '2026-08-27 14:05',
            'status'         => 'completed',
            'payment_status' => 'paid',
            'po_number'      => 'PO-4711',
            'customer'       => 'Ada Lovelace',
            'email'          => 'ada@example.com',
            'currency'       => 'USD',
            'subtotal'       => 10000,
            'discount'       => 500,
            'shipping'       => 0,
            'tax'            => 190,
            'total'          => 9690,
            'lines'          => [
                [
                    'sku'             => 'WID-1',
                    'title'           => 'Widget',
                    'variation_title' => 'Large',
                    'qty'             => 4,
                    'unit_price'      => 2500,
                    'line_total'      => 10000,
                ],
            ],
        ], $overrides);
    }

    /**
     * One row per line item, plus the header — and the order-level columns
     * repeated on each row, which is what makes the file one table a
     * spreadsheet can pivot rather than two sections it cannot.
     */
    public function testOneRowPerLinePlusHeader()
    {
        $order = $this->order();
        $order['lines'][] = [
            'sku'        => 'WID-2',
            'title'      => 'Other widget',
            'qty'        => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
        ];

        $rows = OrderCsv::rows($order);

        $this->assertCount(3, $rows);
        $this->assertSame(OrderCsv::headers(), $rows[0]);
        $this->assertSame(count(OrderCsv::headers()), count($rows[1]));

        // The order reference and the PO number appear on BOTH item rows.
        $this->assertSame('1042', $rows[1][0]);
        $this->assertSame('1042', $rows[2][0]);
        $this->assertSame('PO-4711', $rows[1][4]);
        $this->assertSame('PO-4711', $rows[2][4]);
    }

    /**
     * An order with nothing readable on it still produces a row.
     *
     * A zero-byte download tells a buyer nothing. A row saying "this order, no
     * line items" tells them what they actually have.
     */
    public function testOrderWithNoLinesStillProducesARow()
    {
        $rows = OrderCsv::rows($this->order(['lines' => []]));

        $this->assertCount(2, $rows);
        $this->assertSame('1042', $rows[1][0]);
        // Item columns blank rather than zeroes: there is no item to describe.
        $this->assertSame('', $rows[1][7]);
        $this->assertSame('', $rows[1][10]);
        // Order totals are still there, because they are still true.
        $this->assertSame('96.90', $rows[1][17]);
    }

    /**
     * A line that is not an array is skipped, and an order left with none of
     * them falls back to the same single row rather than a header-only file.
     */
    public function testUnusableLinesFallBackToTheOrderRow()
    {
        $rows = OrderCsv::rows($this->order(['lines' => ['nonsense', 42]]));

        $this->assertCount(2, $rows);
        $this->assertSame('', $rows[1][8]);
    }

    /**
     * The formula guard. A product title or a PO number beginning `=`, `+`,
     * `-` or `@` is prefixed so the spreadsheet reads it as text.
     */
    public function testFormulaCellsAreNeutralised()
    {
        $this->assertSame("'=1+1", OrderCsv::escapeCell('=1+1'));
        $this->assertSame("'+SUM(A1)", OrderCsv::escapeCell('+SUM(A1)'));
        $this->assertSame("'@import", OrderCsv::escapeCell('@import'));
        $this->assertSame("'-cmd|' /c calc'!A0", OrderCsv::escapeCell("-cmd|' /c calc'!A0"));
        $this->assertSame("'\tsneaky", OrderCsv::escapeCell("\tsneaky"));
    }

    /**
     * Plain numbers are exempt on purpose.
     *
     * Every money column is a number, a negative total is ordinary after a
     * refund, and quoting `-12.50` would turn a figure the buyer needs to sum
     * into text their spreadsheet refuses to add up.
     */
    public function testPlainNumbersAreNotQuoted()
    {
        $this->assertSame('-12.50', OrderCsv::escapeCell('-12.50'));
        $this->assertSame('0', OrderCsv::escapeCell('0'));
        $this->assertSame('1042', OrderCsv::escapeCell('1042'));
        $this->assertSame('', OrderCsv::escapeCell(''));
    }

    /**
     * The guard reaches values that arrive through a real order, not only
     * through escapeCell() called directly.
     */
    public function testFormulaGuardAppliesToRenderedFile()
    {
        $order = $this->order(['po_number' => '=cmd|calc']);

        $this->assertStringContainsString('"\'=cmd|calc"', OrderCsv::render($order));
    }

    /**
     * Every field is quoted and an embedded quote is doubled, so a product name
     * containing a comma or a quote cannot shift the columns.
     */
    public function testQuotingIsUnconditionalAndDoubles()
    {
        $order = $this->order();
        $order['lines'][0]['title'] = 'Widget, 12" long';

        $csv = OrderCsv::render($order);

        $this->assertStringContainsString('"Widget, 12"" long"', $csv);
    }

    /**
     * The file opens with a BOM and uses CRLF, which is what makes it readable
     * in the spreadsheet most buyers actually open it in.
     */
    public function testFileStartsWithBomAndUsesCrlf()
    {
        $csv = OrderCsv::render($this->order());

        $this->assertSame(OrderCsv::BOM, substr($csv, 0, 3));
        // One header row and one line row, each terminated.
        $this->assertSame(2, substr_count($csv, "\r\n"));
    }

    /**
     * Money is a plain decimal string. A thousands separator or a comma decimal
     * point would split one number across two columns.
     */
    public function testMoneyIsMachineReadable()
    {
        $this->assertSame('0.00', OrderCsv::money(0));
        $this->assertSame('12.50', OrderCsv::money(1250));
        $this->assertSame('12345.67', OrderCsv::money(1234567));
        $this->assertSame('-5.00', OrderCsv::money(-500));
    }

    /**
     * The filename is built from the buyer-facing reference and the date.
     */
    public function testFilenameUsesTheReferenceAndDate()
    {
        $this->assertSame('order-1042-2026-08-27.csv', OrderCsv::filename($this->order()));
    }

    /**
     * A store's invoice prefix can contain a slash, and a slash in a filename
     * is a path. Everything outside the allowlist becomes a hyphen.
     */
    public function testFilenameCannotCarryAPath()
    {
        $name = OrderCsv::filename($this->order(['number' => '../../etc/passwd']));

        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringEndsWith('.csv', $name);
    }

    /**
     * No usable reference at all still yields a filename, and an unreadable
     * date is dropped rather than guessed at.
     */
    public function testFilenameDegradesGracefully()
    {
        $this->assertSame(
            'order-12.csv',
            OrderCsv::filename($this->order(['number' => '', 'date' => 'not a date']))
        );

        $this->assertSame(
            'order.pdf',
            OrderCsv::filename(['id' => 0, 'number' => '', 'date' => ''], 'pdf')
        );
    }
}
