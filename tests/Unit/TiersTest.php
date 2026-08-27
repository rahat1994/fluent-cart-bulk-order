<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Pricing\Tiers;
use PHPUnit\Framework\TestCase;

/**
 * Bulk Pricing Tiers — the quantity-to-discount math.
 *
 * Everything here is integer cents in, integer cents out. Two of this plugin's
 * three most recent bug fixes were pricing bugs, so these are the assertions
 * most worth keeping honest.
 */
class TiersTest extends TestCase
{
    /**
     * @dataProvider discounts
     */
    public function testApplyToPrice($priceCents, array $tier, $expected, $why)
    {
        $this->assertSame($expected, Tiers::applyToPrice($priceCents, $tier), $why);
    }

    public function discounts()
    {
        return [
            'percent off' => [
                1000, ['discount_type' => 'percent', 'discount_value' => 10], 900,
                '10% off 1000c is 900c',
            ],
            'percent rounds to a whole cent' => [
                999, ['discount_type' => 'percent', 'discount_value' => 10], 899,
                'a fraction of a cent must never reach a total',
            ],
            'percent is the default type' => [
                1000, ['discount_value' => 25], 750,
                'a tier saved before discount types existed is still a percentage',
            ],
            'percent of everything' => [
                1000, ['discount_type' => 'percent', 'discount_value' => 100], 0,
                '100% off is free, not negative',
            ],
            'percent over 100 clamps' => [
                1000, ['discount_type' => 'percent', 'discount_value' => 150], 0,
                'a misconfigured tier must never pay the shopper',
            ],
            'fixed unit price' => [
                1000, ['discount_type' => 'fixed_unit_price', 'discount_value' => 4], 400,
                'the tier sets the price outright',
            ],
            'fixed unit price ignores the original' => [
                50, ['discount_type' => 'fixed_unit_price', 'discount_value' => 4], 400,
                'even when it is higher than the list price - that is the owner\'s call',
            ],
            'amount off' => [
                1000, ['discount_type' => 'amount_off', 'discount_value' => 2.50], 750,
                'a flat amount off each unit',
            ],
            'amount off never goes negative' => [
                100, ['discount_type' => 'amount_off', 'discount_value' => 99], 0,
                'taking more off than the price leaves zero, not a refund',
            ],
            'unknown type falls back to percent' => [
                1000, ['discount_type' => 'nonsense', 'discount_value' => 10], 900,
                'a corrupt type must still produce a sane price',
            ],
            'missing value is no discount' => [
                1000, ['discount_type' => 'percent'], 1000,
                'an incomplete tier charges full price rather than guessing',
            ],
        ];
    }

    /**
     * The bug fixed in d378b36: the FIRST matching tier used to win, so a
     * 60-unit order matched the 10+ tier and was under-discounted.
     */
    public function testMatchPicksTheMostSpecificTierNotTheFirst()
    {
        $tiers = [
            ['min_qty' => 10,  'max_qty' => 0, 'discount_value' => 5],
            ['min_qty' => 50,  'max_qty' => 0, 'discount_value' => 10],
            ['min_qty' => 100, 'max_qty' => 0, 'discount_value' => 20],
        ];

        $this->assertNull(Tiers::match($tiers, 5), 'below every tier there is no discount');
        $this->assertSame(5, Tiers::match($tiers, 10)['discount_value'], 'the boundary qualifies');
        $this->assertSame(5, Tiers::match($tiers, 49)['discount_value'], 'just under the next tier');
        $this->assertSame(10, Tiers::match($tiers, 60)['discount_value'], 'the 50+ tier, not the 10+ one listed first');
        $this->assertSame(20, Tiers::match($tiers, 1000)['discount_value'], 'the highest tier keeps applying above it');
    }

    public function testMatchRespectsCappedBands()
    {
        $tiers = [
            ['min_qty' => 10, 'max_qty' => 20, 'discount_value' => 5],
            ['min_qty' => 21, 'max_qty' => 0,  'discount_value' => 15],
        ];

        $this->assertSame(5, Tiers::match($tiers, 20)['discount_value'], 'the top of a band is inside it');
        $this->assertSame(15, Tiers::match($tiers, 21)['discount_value'], 'one over moves to the next band');
        $this->assertNull(Tiers::match($tiers, 9), 'under the first band there is nothing');
    }

    public function testMatchToleratesRubbish()
    {
        $this->assertNull(Tiers::match([], 50), 'no tiers configured');
        $this->assertNull(Tiers::match(null, 50), 'a feed with no tier key at all');
    }

    /**
     * A blank tier row DOES match — it reads as "from quantity 0, no cap".
     *
     * That is worth pinning rather than tidying away, because it looks alarming
     * and is not: the row carries no discount, so it charges the list price,
     * which is what happens with no tier at all. A real tier alongside it still
     * wins wherever it applies. Nobody is mispriced, so the behaviour is left
     * alone and recorded here instead.
     */
    public function testABlankTierRowIsHarmless()
    {
        $this->assertSame(1000, Tiers::applyToPrice(1000, Tiers::match([[]], 50)), 'a blank row charges full price');

        $withReal = [
            [],
            ['min_qty' => 10, 'max_qty' => 0, 'discount_type' => 'percent', 'discount_value' => 10],
        ];

        $this->assertSame(900, Tiers::applyToPrice(1000, Tiers::match($withReal, 50)), 'the real tier still wins above its minimum');
        $this->assertSame(1000, Tiers::applyToPrice(1000, Tiers::match($withReal, 5)), 'and below it, full price');
    }

    /**
     * The invariant behind "You saved X": whatever the tier does, the saving the
     * shopper is shown is the difference the cart actually charged. These are
     * the two halves of that sum, so they must be computed from one call.
     */
    public function testSavingIsAlwaysTheDifferenceCharged()
    {
        $tiers = [['min_qty' => 10, 'max_qty' => 0, 'discount_type' => 'percent', 'discount_value' => 12]];
        $list  = 1290;

        foreach ([10, 30, 100] as $qty) {
            $tier    = Tiers::match($tiers, $qty);
            $charged = Tiers::applyToPrice($list, $tier);
            $saving  = ($list - $charged) * $qty;

            $this->assertSame(1135, $charged, 'a 12% discount on 1290c');
            $this->assertSame(155 * $qty, $saving, "the saving at qty $qty is the per-unit difference times qty");
            $this->assertGreaterThan(0, $saving, 'a matched tier always saves something');
        }
    }
}
