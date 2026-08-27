<?php

namespace FluentCartBulkOrder\Analytics;

defined('ABSPATH') || exit;

/**
 * Bulk-order revenue against everything else, as two numbers that add up.
 *
 * ---------------------------------------------------------------------------
 * NORMAL CHECKOUT IS A SUBTRACTION, NOT A SECOND QUERY
 * ---------------------------------------------------------------------------
 *
 * There is no such thing as a query for "orders that did not come through the
 * bulk surfaces". There is only the store's total and the part of it this
 * plugin can account for, and the rest is the remainder. Computing the
 * remainder with its own `NOT EXISTS` query would double the cost of the panel
 * and — worse — let the two halves stop summing to the total the moment the two
 * queries disagreed about anything.
 *
 * So: one query for the store's total, one for the attributed part, and one
 * subtraction. The panel can then say "these two make up the whole" and be
 * telling the truth by construction.
 *
 * ---------------------------------------------------------------------------
 * THE CLAMP IS NOT PARANOIA
 * ---------------------------------------------------------------------------
 *
 * The attributed figure can legitimately exceed the total. Both are measured
 * over the same window with the same payment-status filter, but they are two
 * round trips: an order that settles between them is counted by the second and
 * not the first. A negative "normal checkout" number on an owner's screen looks
 * like a broken plugin, so the remainder floors at zero and the share floors
 * with it.
 *
 * Pure: numbers in, numbers out. No database, no request state.
 */
class RevenueSplit
{
    /**
     * Build the panel's display model.
     *
     * Money is in integer cents throughout, exactly as FluentCart stores it —
     * @see \FluentCartBulkOrder\Analytics\Reports for why the figure is
     * `total_paid` and not `total_amount`. Formatting happens at the render
     * site, once.
     *
     * @param array{orders:int, revenue:int} $all  Every order in the window.
     * @param array{orders:int, revenue:int} $bulk The attributed part of it.
     * @return array{
     *     all: array{orders:int, revenue:int},
     *     bulk: array{orders:int, revenue:int, share:float},
     *     normal: array{orders:int, revenue:int, share:float}
     * }
     */
    public static function build($all, $bulk)
    {
        $allOrders   = max(0, (int) (isset($all['orders']) ? $all['orders'] : 0));
        $allRevenue  = max(0, (int) (isset($all['revenue']) ? $all['revenue'] : 0));
        $bulkOrders  = max(0, (int) (isset($bulk['orders']) ? $bulk['orders'] : 0));
        $bulkRevenue = max(0, (int) (isset($bulk['revenue']) ? $bulk['revenue'] : 0));

        // @see the class docblock — the attributed part is capped at the total
        // rather than allowed to produce a negative remainder.
        $bulkOrders  = min($bulkOrders, $allOrders);
        $bulkRevenue = min($bulkRevenue, $allRevenue);

        return [
            'all'    => [
                'orders'  => $allOrders,
                'revenue' => $allRevenue,
            ],
            'bulk'   => [
                'orders'  => $bulkOrders,
                'revenue' => $bulkRevenue,
                'share'   => self::share($bulkRevenue, $allRevenue),
            ],
            'normal' => [
                'orders'  => $allOrders - $bulkOrders,
                'revenue' => $allRevenue - $bulkRevenue,
                'share'   => self::share($allRevenue - $bulkRevenue, $allRevenue),
            ],
        ];
    }

    /**
     * One part as a percentage of a whole.
     *
     * A store with no revenue in the window gets 0.0 rather than a division by
     * zero — and 0.0 is also the right thing to print, because "0% of nothing"
     * is what the screen means to say.
     *
     * @param int $part
     * @param int $whole
     * @return float 0.0 to 100.0, rounded to one decimal place.
     */
    public static function share($part, $whole)
    {
        $whole = (int) $whole;

        if ($whole < 1) {
            return 0.0;
        }

        return round(((int) $part / $whole) * 100, 1);
    }

    /**
     * Rank the entry points, adding each one's share of the ATTRIBUTED total.
     *
     * The share is of the bulk figure and not of the store's whole revenue,
     * because that is the question this breakdown answers: of the orders this
     * plugin can account for, where did they come from. Sharing against store
     * revenue would give four small percentages that mean nothing next to each
     * other.
     *
     * @param array<int, array{source:string, orders:int, revenue:int}> $rows
     * @param int                                                       $bulkRevenue
     * @return array<int, array{source:string, orders:int, revenue:int, share:float}>
     */
    public static function bySource($rows, $bulkRevenue)
    {
        $out = [];

        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $revenue = max(0, (int) (isset($row['revenue']) ? $row['revenue'] : 0));

            $out[] = [
                'source'  => Surface::sanitize(isset($row['source']) ? $row['source'] : ''),
                'orders'  => max(0, (int) (isset($row['orders']) ? $row['orders'] : 0)),
                'revenue' => $revenue,
                'share'   => self::share($revenue, $bulkRevenue),
            ];
        }

        usort($out, function ($a, $b) {
            $diff = $b['revenue'] - $a['revenue'];

            // Stable on a tie, so the rows do not reshuffle between loads.
            return $diff !== 0 ? $diff : strcmp($a['source'], $b['source']);
        });

        return $out;
    }
}
