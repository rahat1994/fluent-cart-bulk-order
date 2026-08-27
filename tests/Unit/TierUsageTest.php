<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Analytics\TierUsage;
use PHPUnit\Framework\TestCase;

/**
 * Which tiers were reached, and — the point of the panel — which never were.
 *
 * The failure this class exists to prevent is a tier quietly falling out of
 * BOTH lists. A configured tier that goes missing from "never reached" is an
 * owner never being told their 500-unit tier is dead, which is the single
 * insight the whole feature was asked for.
 */
class TierUsageTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function tier($key, $minQty, array $overrides = [])
    {
        return array_merge([
            'tier_key'        => $key,
            'tier_scope'      => 'global',
            'tier_product_id' => 0,
            'tier_role'       => '',
            'tier_min_qty'    => $minQty,
            'tier_max_qty'    => 0,
            'tier_type'       => 'percent',
            'tier_value'      => 10.0,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function hit($key, $units, array $overrides = [])
    {
        return array_merge([
            'tier_key' => $key,
            'orders'   => 1,
            'units'    => $units,
            'revenue'  => 1000,
            'saving'   => 100,
        ], $overrides);
    }

    public function testAConfiguredTierWithNoHitsIsReportedAsUnused()
    {
        $result = TierUsage::merge([], [$this->tier('a', 500)]);

        $this->assertCount(0, $result['used']);
        $this->assertCount(1, $result['unused']);
        $this->assertSame('a', $result['unused'][0]['tier_key']);
        $this->assertSame(0, $result['unused'][0]['orders']);
        $this->assertSame(0, $result['unused'][0]['units']);
    }

    /**
     * Every configured tier lands in exactly one group. A tier that appears in
     * neither is the bug this whole panel would hide.
     */
    public function testEveryConfiguredTierLandsInExactlyOneGroup()
    {
        $configured = [
            $this->tier('a', 10),
            $this->tier('b', 50),
            $this->tier('c', 500),
        ];

        $result = TierUsage::merge([$this->hit('a', 40)], $configured);

        $keys = array_merge(
            array_column($result['used'], 'tier_key'),
            array_column($result['unused'], 'tier_key')
        );

        sort($keys);

        $this->assertSame(['a', 'b', 'c'], $keys);
        $this->assertSame(3, $result['configured_count']);
        $this->assertSame(1, $result['used_count']);
    }

    /**
     * A hit for a tier no feed carries any more is still real revenue, so it
     * gets its own group rather than disappearing.
     */
    public function testAHitWithNoConfiguredTierIsRetiredAndNotDropped()
    {
        $result = TierUsage::merge([$this->hit('gone', 12)], [$this->tier('a', 10)]);

        $this->assertCount(1, $result['retired']);
        $this->assertSame('gone', $result['retired'][0]['tier_key']);
        $this->assertCount(0, $result['used']);
        $this->assertCount(1, $result['unused']);
    }

    public function testAUsedTierCarriesBothItsDefinitionAndItsNumbers()
    {
        $result = TierUsage::merge(
            [$this->hit('a', 400, ['orders' => 3, 'revenue' => 25000, 'saving' => 2500])],
            [$this->tier('a', 50, ['tier_value' => 15.0])]
        );

        $row = $result['used'][0];

        $this->assertSame(50, $row['tier_min_qty']);
        $this->assertSame(15.0, $row['tier_value']);
        $this->assertSame(3, $row['orders']);
        $this->assertSame(400, $row['units']);
        $this->assertSame(25000, $row['revenue']);
        $this->assertSame(2500, $row['saving']);
    }

    /**
     * Units, not orders. A tier hit once for 4,000 units matters more than one
     * hit forty times for 50, and ordering by order count says the opposite.
     */
    public function testUsedTiersAreRankedByUnitsAndNotByOrderCount()
    {
        $result = TierUsage::merge(
            [
                $this->hit('small', 50, ['orders' => 40]),
                $this->hit('big', 4000, ['orders' => 1]),
            ],
            [$this->tier('small', 10), $this->tier('big', 1000)]
        );

        $this->assertSame('big', $result['used'][0]['tier_key']);
        $this->assertSame('small', $result['used'][1]['tier_key']);
    }

    /**
     * Hardest-to-reach first: the tier an owner most needs to look at is the
     * one with the highest quantity requirement.
     */
    public function testUnusedTiersAreRankedByHardestToReachFirst()
    {
        $result = TierUsage::merge([], [
            $this->tier('low', 10),
            $this->tier('high', 500),
            $this->tier('mid', 100),
        ]);

        $this->assertSame(
            ['high', 'mid', 'low'],
            array_column($result['unused'], 'tier_key')
        );
    }

    /**
     * Two equally-used tiers must not swap places between page loads, which
     * would make a static report look like it was changing under the owner.
     */
    public function testTiesAreBrokenDeterministically()
    {
        $first = TierUsage::merge(
            [$this->hit('bbb', 100), $this->hit('aaa', 100)],
            [$this->tier('aaa', 10), $this->tier('bbb', 10)]
        );

        $second = TierUsage::merge(
            [$this->hit('aaa', 100), $this->hit('bbb', 100)],
            [$this->tier('bbb', 10), $this->tier('aaa', 10)]
        );

        $this->assertSame(
            array_column($first['used'], 'tier_key'),
            array_column($second['used'], 'tier_key')
        );
    }

    /**
     * A row with no key cannot be counted against a tier, so it is dropped
     * rather than becoming a phantom tier with an empty name.
     */
    public function testRowsWithoutAKeyAreIgnored()
    {
        $result = TierUsage::merge(
            [['tier_key' => '', 'orders' => 1, 'units' => 5, 'revenue' => 1, 'saving' => 0]],
            [['tier_min_qty' => 10]]
        );

        $this->assertSame(0, $result['configured_count']);
        $this->assertCount(0, $result['used']);
        $this->assertCount(0, $result['unused']);
        $this->assertCount(0, $result['retired']);
    }

    public function testNothingConfiguredAndNothingRecordedIsAnEmptyResultAndNotAnError()
    {
        $result = TierUsage::merge([], []);

        $this->assertSame([], $result['used']);
        $this->assertSame([], $result['unused']);
        $this->assertSame([], $result['retired']);
        $this->assertSame(0, $result['configured_count']);
        $this->assertSame(0, $result['used_count']);
    }
}
