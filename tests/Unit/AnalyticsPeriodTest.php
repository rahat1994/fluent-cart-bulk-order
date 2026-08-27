<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Analytics\Period;
use PHPUnit\Framework\TestCase;

/**
 * The reporting window.
 *
 * Small class, and one of the easiest to get quietly wrong: every figure on the
 * analytics screen is filtered by what since() returns, so a boundary that is
 * off by a day or by a timezone offset does not fail — it reports a different
 * quarter with complete confidence.
 */
class AnalyticsPeriodTest extends TestCase
{
    /**
     * A fixed moment, so the assertions do not move with the clock.
     * 2026-08-27 12:00:00.
     */
    const NOW = 1787832000;

    public function testDefaultIsNinetyDays()
    {
        $this->assertSame(Period::LAST_90_DAYS, Period::DEFAULT_PERIOD);
    }

    /**
     * A window nobody offered must not become "all time" — that would silently
     * turn a bounded query into a full table scan.
     */
    public function testUnknownValueFallsBackToTheDefaultAndNotToAllTime()
    {
        $this->assertSame(Period::DEFAULT_PERIOD, Period::sanitize('last-week'));
        $this->assertSame(Period::DEFAULT_PERIOD, Period::sanitize(''));
        $this->assertSame(Period::DEFAULT_PERIOD, Period::sanitize(null));
        $this->assertSame(Period::DEFAULT_PERIOD, Period::sanitize(['30days']));
        $this->assertNotSame(Period::ALL_TIME, Period::sanitize('nonsense'));
    }

    public function testEveryOfferedKeySurvivesSanitizing()
    {
        foreach (Period::keys() as $key) {
            $this->assertSame($key, Period::sanitize($key));
        }
    }

    public function testSinceIsExactlyTheWindowBeforeTheGivenMoment()
    {
        $this->assertSame('2026-07-28 12:00:00', Period::since(Period::LAST_30_DAYS, self::NOW));
        $this->assertSame('2026-05-29 12:00:00', Period::since(Period::LAST_90_DAYS, self::NOW));
        $this->assertSame('2025-08-27 12:00:00', Period::since(Period::LAST_12_MONTHS, self::NOW));
    }

    /**
     * All time has no lower bound at all. Null, and not a very old date: the
     * query builder drops the whole clause on null, and a sentinel date would
     * silently exclude a store that back-dated an order.
     */
    public function testAllTimeHasNoLowerBound()
    {
        $this->assertNull(Period::since(Period::ALL_TIME, self::NOW));
    }

    /**
     * An unrecognised key must not produce an unbounded query by accident.
     */
    public function testUnknownKeyStillProducesABoundedWindow()
    {
        $this->assertSame(
            Period::since(Period::DEFAULT_PERIOD, self::NOW),
            Period::since('made-up', self::NOW)
        );
    }

    /**
     * The boundary is formatted from the timestamp it was handed, with no
     * further offset applied. This is what keeps a site-local `current_time()`
     * comparable with FluentCart's site-local `created_at`.
     */
    public function testSinceAppliesNoTimezoneOffsetOfItsOwn()
    {
        $expected = gmdate('Y-m-d H:i:s', self::NOW - (30 * 86400));

        $this->assertSame($expected, Period::since(Period::LAST_30_DAYS, self::NOW));
    }

    public function testEveryKeyHasItsOwnLabel()
    {
        $labels = [];

        foreach (Period::keys() as $key) {
            $labels[] = Period::label($key);
        }

        $this->assertCount(count(Period::keys()), array_unique($labels));
        $this->assertNotContains('', $labels);
    }
}
