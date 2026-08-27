<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Quotes\QuoteInput;
use PHPUnit\Framework\TestCase;

/**
 * What reaches a stored quote, and what a quote comes to.
 *
 * Two failures this suite exists to catch, both silent:
 *
 *   1. A price the BROWSER sent ending up on the record. Every price on a
 *      stored quote must come from the server's own catalogue lookup, or a
 *      buyer can name their own price and the owner reviews a number the buyer
 *      chose.
 *
 *   2. '' being read as 0 in the owner's price form. An empty box means "leave
 *      this line alone"; zero means "give it away". Collapsing the two makes
 *      the one price an owner most wants to be deliberate about the one they
 *      cannot express.
 */
class QuoteInputTest extends TestCase
{
    /**
     * The catalogue payload fcbo_resolve_variant_ids() returns, in miniature.
     *
     * @param int    $variantId
     * @param int    $price
     * @param string $paymentType
     * @return array<string, mixed>
     */
    private function resolvedVariant($variantId, $price, $paymentType = 'onetime')
    {
        return [
            'productId'  => 900 + $variantId,
            'title'      => 'Widget ' . $variantId,
            'thumbnail'  => '',
            'categories' => [],
            'variant'    => [
                'id'              => $variantId,
                'variation_title' => 'Large',
                'item_price'      => $price,
                'sku'             => 'SKU-' . $variantId,
                'payment_type'    => $paymentType,
            ],
        ];
    }

    /**
     * Only variant ids and quantities survive a buyer's submission.
     *
     * The `price` key below is what a tampered request would carry. It is not
     * rejected with an error — it is simply never read, which is why there is no
     * allow-list to keep in sync.
     */
    public function testBuyerPricesAreIgnored()
    {
        $items = QuoteInput::sanitizeItems([
            ['variantId' => 7, 'qty' => 3, 'price' => 1, 'quoted_price' => 1],
        ]);

        $this->assertSame([['variantId' => 7, 'qty' => 3]], $items);
    }

    /**
     * A stored line's prices come from the catalogue, never from the request.
     */
    public function testStoredPriceComesFromTheCatalogue()
    {
        $lines = QuoteInput::buildLines(
            [['variantId' => 7, 'qty' => 2]],
            [7 => $this->resolvedVariant(7, 4999)]
        );

        $this->assertCount(1, $lines);
        $this->assertSame(4999, $lines[0]['requested_price']);
        $this->assertSame(QuoteInput::PRICE_UNSET, $lines[0]['quoted_price']);
        $this->assertSame(907, $lines[0]['product_id']);
        $this->assertSame('SKU-7', $lines[0]['sku']);
        $this->assertSame('onetime', $lines[0]['payment_type']);
    }

    /**
     * Junk lines are dropped rather than stored as something.
     */
    public function testUnusableLinesAreDropped()
    {
        $items = QuoteInput::sanitizeItems([
            ['variantId' => 0, 'qty' => 5],
            ['variantId' => 3, 'qty' => 0],
            ['variantId' => -4, 'qty' => 2],
            ['variantId' => 5, 'qty' => -2],
            ['variantId' => 'abc', 'qty' => 1],
            'not an array',
            ['variantId' => 9, 'qty' => 1],
        ]);

        $this->assertSame([['variantId' => 9, 'qty' => 1]], $items);
    }

    /**
     * A negative quantity is refused, not turned positive.
     *
     * absint() would make -2 into 2 and quietly quote for two of something the
     * request asked for a negative number of.
     */
    public function testNegativeQuantityIsNotFlipped()
    {
        $this->assertSame([], QuoteInput::sanitizeItems([['variantId' => 5, 'qty' => -2]]));
    }

    /**
     * The same variant twice is one line, not two.
     *
     * The form consolidates before checkout and before saving; a quote that did
     * not would show the owner two rows for one product and invite them to
     * price the same thing twice.
     */
    public function testDuplicateVariantsAreMerged()
    {
        $items = QuoteInput::sanitizeItems([
            ['variantId' => 4, 'qty' => 2],
            ['variantId' => 8, 'qty' => 1],
            ['variantId' => 4, 'qty' => 5],
        ]);

        $this->assertSame(
            [
                ['variantId' => 4, 'qty' => 7],
                ['variantId' => 8, 'qty' => 1],
            ],
            $items
        );
    }

    /**
     * A submission cannot grow a record without bound.
     */
    public function testLineCountIsCapped()
    {
        $items = [];

        for ($i = 1; $i <= QuoteInput::MAX_LINES + 50; $i++) {
            $items[] = ['variantId' => $i, 'qty' => 1];
        }

        $this->assertCount(QuoteInput::MAX_LINES, QuoteInput::sanitizeItems($items));
    }

    /**
     * A line whose variant no longer resolves never becomes a stored line.
     *
     * A quote is a thing an owner is asked to price, and a line that cannot be
     * bought is not something to price.
     */
    public function testUnresolvedVariantsAreNotStored()
    {
        $lines = QuoteInput::buildLines(
            [
                ['variantId' => 7, 'qty' => 1],
                ['variantId' => 8, 'qty' => 1],
            ],
            [7 => $this->resolvedVariant(7, 1000)]
        );

        $this->assertCount(1, $lines);
        $this->assertSame(7, $lines[0]['variant_id']);
    }

    /**
     * An empty price box leaves the line alone; a typed 0 makes it free.
     *
     * This is the distinction the whole PRICE_UNSET sentinel exists for.
     */
    public function testEmptyPriceIsNotZero()
    {
        $lines = QuoteInput::buildLines(
            [
                ['variantId' => 1, 'qty' => 1],
                ['variantId' => 2, 'qty' => 1],
            ],
            [
                1 => $this->resolvedVariant(1, 2500),
                2 => $this->resolvedVariant(2, 3000),
            ]
        );

        $priced = QuoteInput::applyPrices($lines, [1 => '', 2 => '0']);

        // Nothing typed for line 1, and it was never priced, so it falls back
        // to the catalogue price the buyer was shown.
        $this->assertSame(2500, $priced[0]['quoted_price']);

        // A deliberate zero is kept as a zero.
        $this->assertSame(0, $priced[1]['quoted_price']);
    }

    /**
     * A price for a variant the quote does not carry cannot add a line.
     *
     * applyPrices() walks the STORED lines and looks each up in the submission,
     * never the other way round, so an owner (or a crafted POST) cannot type a
     * product into an order the buyer never asked for.
     */
    public function testPricesForUnknownVariantsAreIgnored()
    {
        $lines = QuoteInput::buildLines(
            [['variantId' => 1, 'qty' => 1]],
            [1 => $this->resolvedVariant(1, 500)]
        );

        $priced = QuoteInput::applyPrices($lines, [1 => 400, 99 => 100, 'junk' => 50]);

        $this->assertCount(1, $priced);
        $this->assertSame(1, $priced[0]['variant_id']);
        $this->assertSame(400, $priced[0]['quoted_price']);
    }

    /**
     * Re-pricing an already priced line does not reset it when the box is blank.
     */
    public function testAlreadyPricedLineKeepsItsPriceWhenNothingIsTyped()
    {
        $lines = QuoteInput::applyPrices(
            [
                array_merge(QuoteInput::LINE_DEFAULTS, [
                    'variant_id'      => 1,
                    'qty'             => 1,
                    'requested_price' => 2500,
                    'quoted_price'    => 1800,
                ]),
            ],
            [1 => '']
        );

        $this->assertSame(1800, $lines[0]['quoted_price']);
    }

    /**
     * A negative or unreadable price is refused, not clamped.
     *
     * A minus sign in a price field is a typo, not an instruction — and a
     * clamped -500 would silently become a free line.
     */
    public function testPriceParsing()
    {
        $this->assertSame(0, QuoteInput::toPrice('0'));
        $this->assertSame(1250, QuoteInput::toPrice('1250'));
        $this->assertSame(1250, QuoteInput::toPrice(' 1250 '));
        $this->assertSame(1250, QuoteInput::toPrice(1249.7));
        $this->assertSame(QuoteInput::MAX_UNIT_PRICE, QuoteInput::toPrice(QuoteInput::MAX_UNIT_PRICE * 10));

        $this->assertNull(QuoteInput::toPrice(''));
        $this->assertNull(QuoteInput::toPrice(null));
        $this->assertNull(QuoteInput::toPrice('-500'));
        $this->assertNull(QuoteInput::toPrice('free'));
        $this->assertNull(QuoteInput::toPrice(true));
        $this->assertNull(QuoteInput::toPrice([1250]));
    }

    /**
     * Totals are integer cents, and a saving is never negative.
     *
     * Quoting ABOVE the list price is a legitimate thing an owner may do — a
     * small-batch surcharge — and calling that a negative saving reads as a bug
     * to everyone who sees it.
     */
    public function testTotals()
    {
        $lines = [
            array_merge(QuoteInput::LINE_DEFAULTS, [
                'variant_id' => 1, 'qty' => 10, 'requested_price' => 1000, 'quoted_price' => 800,
            ]),
            array_merge(QuoteInput::LINE_DEFAULTS, [
                'variant_id' => 2, 'qty' => 3, 'requested_price' => 500, 'quoted_price' => 500,
            ]),
        ];

        $totals = QuoteInput::totals($lines);

        $this->assertSame(11500, $totals['requested']);
        $this->assertSame(9500, $totals['quoted']);
        $this->assertSame(2000, $totals['saving']);
        $this->assertSame(13, $totals['units']);
        $this->assertSame(2, $totals['lines']);

        $surcharged = QuoteInput::totals([
            array_merge(QuoteInput::LINE_DEFAULTS, [
                'variant_id' => 1, 'qty' => 1, 'requested_price' => 100, 'quoted_price' => 150,
            ]),
        ]);

        $this->assertSame(150, $surcharged['quoted']);
        $this->assertSame(0, $surcharged['saving']);
    }

    /**
     * An unpriced line still totals as something the owner can read.
     */
    public function testUnpricedLinesTotalAtTheListPrice()
    {
        $totals = QuoteInput::totals([
            array_merge(QuoteInput::LINE_DEFAULTS, [
                'variant_id' => 1, 'qty' => 2, 'requested_price' => 750,
            ]),
        ]);

        $this->assertSame(1500, $totals['requested']);
        $this->assertSame(1500, $totals['quoted']);
    }

    /**
     * One line, one price — the same answer everywhere it is asked for.
     */
    public function testEffectivePrice()
    {
        $unpriced = array_merge(QuoteInput::LINE_DEFAULTS, ['requested_price' => 999]);
        $priced   = array_merge(QuoteInput::LINE_DEFAULTS, ['requested_price' => 999, 'quoted_price' => 0]);

        $this->assertSame(999, QuoteInput::effectivePrice($unpriced));
        $this->assertSame(0, QuoteInput::effectivePrice($priced));
        $this->assertSame(0, QuoteInput::effectivePrice('not a line'));
    }

    /**
     * A subscription anywhere in the quote is reported.
     *
     * The converter asks this because FluentCart refuses a subscription line in
     * a manually created order, and the owner deserves to be told why rather
     * than watching a conversion fail.
     */
    public function testSubscriptionDetection()
    {
        $onetime = QuoteInput::buildLines(
            [['variantId' => 1, 'qty' => 1]],
            [1 => $this->resolvedVariant(1, 100)]
        );

        $mixed = QuoteInput::buildLines(
            [
                ['variantId' => 1, 'qty' => 1],
                ['variantId' => 2, 'qty' => 1],
            ],
            [
                1 => $this->resolvedVariant(1, 100),
                2 => $this->resolvedVariant(2, 100, 'subscription'),
            ]
        );

        $this->assertFalse(QuoteInput::hasSubscription($onetime));
        $this->assertTrue(QuoteInput::hasSubscription($mixed));
        $this->assertFalse(QuoteInput::hasSubscription('not lines'));
    }

    /**
     * The injected sanitiser is actually applied, and the note is capped.
     *
     * The fallback matters too: a caller that forgets to pass one must still
     * not be able to store markup.
     */
    public function testNoteSanitizing()
    {
        $seen = null;

        $note = QuoteInput::sanitizeNote("  hello\r\nthere  ", function ($value) use (&$seen) {
            $seen = $value;

            return strtoupper($value);
        });

        $this->assertSame("  hello\r\nthere  ", $seen);
        $this->assertSame("HELLO\nTHERE", $note);

        $this->assertSame('alert(1)', QuoteInput::sanitizeNote('<script>alert(1)</script>'));
        $this->assertSame('', QuoteInput::sanitizeNote(['array']));

        $long = str_repeat('x', QuoteInput::MAX_NOTE_LENGTH + 100);
        $this->assertSame(QuoteInput::MAX_NOTE_LENGTH, strlen(QuoteInput::sanitizeNote($long)));
    }
}
