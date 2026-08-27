<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Analytics\RevenueSplit;
use FluentCartBulkOrder\Analytics\Surface;
use PHPUnit\Framework\TestCase;

/**
 * Bulk-order revenue against normal checkout.
 *
 * Two of these assertions are the reason the class exists: the two halves have
 * to add up to the whole, and neither may go negative. Both figures come from
 * separate round trips to the database, so an order that settles between them
 * can legitimately make the attributed part larger than the total — and the
 * screen must print zero rather than "-£120 of normal checkout revenue", which
 * reads as a broken plugin.
 */
class RevenueSplitTest extends TestCase
{
    public function testTheTwoHalvesAddUpToTheWhole()
    {
        $split = RevenueSplit::build(
            ['orders' => 100, 'revenue' => 500000],
            ['orders' => 30, 'revenue' => 200000]
        );

        $this->assertSame(500000, $split['all']['revenue']);
        $this->assertSame(200000, $split['bulk']['revenue']);
        $this->assertSame(300000, $split['normal']['revenue']);
        $this->assertSame(
            $split['all']['revenue'],
            $split['bulk']['revenue'] + $split['normal']['revenue']
        );

        $this->assertSame(100, $split['all']['orders']);
        $this->assertSame(30, $split['bulk']['orders']);
        $this->assertSame(70, $split['normal']['orders']);
    }

    public function testSharesAreOfTheStoreTotal()
    {
        $split = RevenueSplit::build(
            ['orders' => 4, 'revenue' => 1000],
            ['orders' => 1, 'revenue' => 250]
        );

        $this->assertSame(25.0, $split['bulk']['share']);
        $this->assertSame(75.0, $split['normal']['share']);
    }

    /**
     * The clamp described in the class docblock. Two queries a moment apart can
     * disagree; a negative remainder must never reach the screen.
     */
    public function testAnAttributedFigureLargerThanTheTotalNeverGoesNegative()
    {
        $split = RevenueSplit::build(
            ['orders' => 3, 'revenue' => 1000],
            ['orders' => 5, 'revenue' => 1800]
        );

        $this->assertSame(0, $split['normal']['revenue']);
        $this->assertSame(0, $split['normal']['orders']);
        $this->assertSame(1000, $split['bulk']['revenue']);
        $this->assertSame(3, $split['bulk']['orders']);
        $this->assertSame(0.0, $split['normal']['share']);
    }

    /**
     * A store with no orders in the window is an ordinary state, not a division
     * by zero.
     */
    public function testAStoreWithNoRevenueGetsZeroSharesRatherThanAnError()
    {
        $split = RevenueSplit::build(
            ['orders' => 0, 'revenue' => 0],
            ['orders' => 0, 'revenue' => 0]
        );

        $this->assertSame(0.0, $split['bulk']['share']);
        $this->assertSame(0.0, $split['normal']['share']);
        $this->assertSame(0, $split['normal']['revenue']);
    }

    public function testMissingKeysAreTreatedAsZeroAndNotAsAFatal()
    {
        $split = RevenueSplit::build([], []);

        $this->assertSame(0, $split['all']['revenue']);
        $this->assertSame(0, $split['bulk']['orders']);
    }

    public function testShareRoundsToOneDecimalPlace()
    {
        $this->assertSame(33.3, RevenueSplit::share(1, 3));
        $this->assertSame(0.0, RevenueSplit::share(5, 0));
        $this->assertSame(100.0, RevenueSplit::share(7, 7));
    }

    /**
     * The entry-point breakdown shares against the ATTRIBUTED total, not the
     * store's whole revenue — otherwise the rows are four small percentages
     * that mean nothing beside one another.
     */
    public function testSourceSharesAreOfTheBulkTotal()
    {
        $rows = RevenueSplit::bySource(
            [
                ['source' => Surface::BULK_ORDER_FORM, 'orders' => 2, 'revenue' => 750],
                ['source' => '', 'orders' => 1, 'revenue' => 250],
            ],
            1000
        );

        $this->assertSame(75.0, $rows[0]['share']);
        $this->assertSame(25.0, $rows[1]['share']);
    }

    public function testSourceRowsAreRankedByRevenue()
    {
        $rows = RevenueSplit::bySource(
            [
                ['source' => Surface::SAVED_ORDERS, 'orders' => 1, 'revenue' => 100],
                ['source' => Surface::QUOTE, 'orders' => 1, 'revenue' => 900],
            ],
            1000
        );

        $this->assertSame(Surface::QUOTE, $rows[0]['source']);
        $this->assertSame(Surface::SAVED_ORDERS, $rows[1]['source']);
    }

    /**
     * A stored source that is no longer a recognised surface must read as
     * "not recorded", not as an unknown slug printed at an owner.
     */
    public function testAnUnrecognisedSourceCollapsesToTheUnrecordedRow()
    {
        $rows = RevenueSplit::bySource(
            [['source' => 'some_old_surface', 'orders' => 1, 'revenue' => 10]],
            10
        );

        $this->assertSame('', $rows[0]['source']);
    }
}
