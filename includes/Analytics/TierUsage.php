<?php

namespace FluentCartBulkOrder\Analytics;

defined('ABSPATH') || exit;

/**
 * Which Bulk Pricing Tiers actually get hit, and — the useful half — which
 * never do.
 *
 * ---------------------------------------------------------------------------
 * THE ANSWER AN OWNER IS LOOKING FOR IS A ZERO
 * ---------------------------------------------------------------------------
 *
 * A table of "tiers, ranked by how often they were used" is nearly worthless on
 * its own. Every row in it is a tier that works. The row an owner needs is the
 * one that is NOT in that table: the 500-unit tier nobody has ever reached,
 * sitting on a feed since the day the store launched, quietly doing nothing.
 *
 * So this class does not rank usage. It JOINS two lists — the tiers currently
 * configured on the store's feeds, and the tiers that priced a real order — and
 * reports three groups:
 *
 *   USED         configured, and hit. The tiers that are earning their place.
 *   NEVER USED   configured, and never hit in this window. The answer.
 *   RETIRED      hit in this window, but no longer configured. Orders were
 *                placed at a price the store no longer offers, which is a
 *                legitimate thing to see on a report about the past — and
 *                dropping those rows would make the revenue in this panel stop
 *                agreeing with the revenue in the panel above it.
 *
 * ---------------------------------------------------------------------------
 * "NEVER USED" IS ALWAYS RELATIVE TO A WINDOW
 * ---------------------------------------------------------------------------
 *
 * A tier that is unused over 30 days may be a seasonal tier used every
 * December. The screen says which window it is reporting on, next to the
 * number, for exactly this reason — and the window's floor is 90 days by
 * default rather than 30. @see \FluentCartBulkOrder\Analytics\Period
 *
 * Pure: two arrays in, one array out. No database, no request state.
 *
 * @see \FluentCartBulkOrder\Analytics\TierSignature How a tier is identified.
 */
class TierUsage
{
    /**
     * The aggregate columns a never-hit tier gets, so every row has the same
     * shape and no renderer needs an isset() per cell.
     *
     * @var array<string, int>
     */
    const ZERO_USAGE = [
        'orders'  => 0,
        'units'   => 0,
        'revenue' => 0,
        'saving'  => 0,
    ];

    /**
     * Merge configured tiers with recorded hits.
     *
     * Both inputs are keyed by tier key, so a tier appears in exactly one of
     * the three groups and the maths cannot double-count.
     *
     * Each USED and RETIRED row carries its own recorded components rather than
     * the configured ones. That is deliberate: for a RETIRED tier there are no
     * configured components to carry, and using the recorded ones everywhere
     * means one code path for naming a tier instead of two.
     *
     * @param array<int, array<string, mixed>> $hits       Recorded usage, one row per
     *                                                     distinct tier. Each needs at
     *                                                     least `tier_key`; the aggregate
     *                                                     columns are passed through
     *                                                     untouched.
     * @param array<int, array<string, mixed>> $configured Tiers currently on the store's
     *                                                     feeds, each with `tier_key` and
     *                                                     its components.
     * @return array{used: array, unused: array, retired: array, configured_count: int, used_count: int}
     */
    public static function merge($hits, $configured)
    {
        $hitsByKey = self::index($hits);
        $confByKey = self::index($configured);

        $used = [];
        $unused = [];
        $retired = [];

        foreach ($confByKey as $key => $tier) {
            if (isset($hitsByKey[$key])) {
                // The recorded row wins, with the configured row's own fields
                // filling any gap — so a hit row that only carried a key still
                // comes out nameable.
                $used[] = array_merge($tier, $hitsByKey[$key]);

                continue;
            }

            $unused[] = array_merge($tier, self::ZERO_USAGE);
        }

        foreach ($hitsByKey as $key => $row) {
            if (!isset($confByKey[$key])) {
                $retired[] = $row;
            }
        }

        return [
            'used'             => self::sortByUnits($used),
            // Ordered the way an owner reads them: the biggest quantity
            // requirement first, because the tier least likely to be reachable
            // is the one they most need to see.
            'unused'           => self::sortByMinQty($unused),
            'retired'          => self::sortByUnits($retired),
            'configured_count' => count($confByKey),
            'used_count'       => count($used),
        ];
    }

    /**
     * Re-key a list of rows by their tier key, dropping anything without one.
     *
     * Later rows win on a duplicate key. That cannot happen from the aggregate
     * query, which groups by the key, and on the configured side it is the
     * right answer anyway: two feeds carrying the identical tier ARE one tier.
     *
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private static function index($rows)
    {
        $out = [];

        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = isset($row['tier_key']) ? (string) $row['tier_key'] : '';

            if ($key === '') {
                continue;
            }

            $out[$key] = $row;
        }

        return $out;
    }

    /**
     * Busiest first, measured in units rather than in orders.
     *
     * Units, because that is what a tier is about. A tier hit once for 4,000
     * units matters more than one hit forty times for 50, and ordering by order
     * count would put them the other way round.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function sortByUnits($rows)
    {
        usort($rows, function ($a, $b) {
            $diff = (int) $b['units'] - (int) $a['units'];

            // A stable tie-break, so two equally-used tiers do not swap places
            // between page loads and make the screen look like it is changing.
            return $diff !== 0 ? $diff : strcmp((string) $a['tier_key'], (string) $b['tier_key']);
        });

        return $rows;
    }

    /**
     * Hardest to reach first.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function sortByMinQty($rows)
    {
        usort($rows, function ($a, $b) {
            $diff = (int) $b['tier_min_qty'] - (int) $a['tier_min_qty'];

            return $diff !== 0 ? $diff : strcmp((string) $a['tier_key'], (string) $b['tier_key']);
        });

        return $rows;
    }
}
