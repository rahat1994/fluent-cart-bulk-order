<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Quotes\QuoteStatus;
use PHPUnit\Framework\TestCase;

/**
 * The quote request state machine.
 *
 * This is the class that decides whether a request may create a real FluentCart
 * order at a hand-typed price, so the assertions worth reading first are the
 * negative ones: a quote nobody has been sent cannot be converted, a converted
 * one cannot be converted again, and no path a buyer can reach ever produces
 * anything but REQUESTED.
 */
class QuoteStatusTest extends TestCase
{
    /**
     * A buyer's submission always lands on REQUESTED. There is no other option.
     */
    public function testSubmissionAlwaysLandsOnRequested()
    {
        $this->assertSame(QuoteStatus::REQUESTED, QuoteStatus::statusAfterRequest());
    }

    /**
     * Prices are editable in exactly one state.
     *
     * Once the buyer has been emailed a price, the owner must not be able to
     * change it silently — the order they accept has to be the one they were
     * quoted.
     */
    public function testOnlyRequestedCanBePriced()
    {
        $this->assertTrue(QuoteStatus::canPrice(QuoteStatus::REQUESTED));

        $this->assertFalse(QuoteStatus::canPrice(QuoteStatus::QUOTED));
        $this->assertFalse(QuoteStatus::canPrice(QuoteStatus::ACCEPTED));
        $this->assertFalse(QuoteStatus::canPrice(QuoteStatus::DECLINED));
        $this->assertFalse(QuoteStatus::canPrice(QuoteStatus::NONE));
        $this->assertFalse(QuoteStatus::canPrice(''));
        $this->assertFalse(QuoteStatus::canPrice(null));
    }

    /**
     * Only a quote the buyer has actually been sent may become an order.
     *
     * This is the guard that makes the convert action idempotent: a replayed
     * POST, a double-clicked button, or a hand-crafted request naming an
     * already-converted quote all land here and stop.
     */
    public function testOnlyQuotedCanBeConverted()
    {
        $this->assertTrue(QuoteStatus::canConvert(QuoteStatus::QUOTED));

        $this->assertFalse(QuoteStatus::canConvert(QuoteStatus::REQUESTED));
        $this->assertFalse(QuoteStatus::canConvert(QuoteStatus::ACCEPTED));
        $this->assertFalse(QuoteStatus::canConvert(QuoteStatus::DECLINED));
        $this->assertFalse(QuoteStatus::canConvert(QuoteStatus::NONE));
        $this->assertFalse(QuoteStatus::canConvert('approved'));
        $this->assertFalse(QuoteStatus::canConvert(null));
    }

    /**
     * The complete transition table, stated as a table.
     *
     * Four legal moves and nothing else — including no same-state move, so a
     * double-clicked button cannot re-send the buyer's email or create a second
     * order, and no move backwards, so a converted quote cannot be re-priced.
     */
    public function testTransitionTable()
    {
        $legal = [
            [QuoteStatus::REQUESTED, QuoteStatus::QUOTED],
            [QuoteStatus::REQUESTED, QuoteStatus::DECLINED],
            [QuoteStatus::QUOTED, QuoteStatus::ACCEPTED],
            [QuoteStatus::QUOTED, QuoteStatus::DECLINED],
        ];

        $illegal = [
            // No same-state moves.
            [QuoteStatus::REQUESTED, QuoteStatus::REQUESTED],
            [QuoteStatus::QUOTED, QuoteStatus::QUOTED],
            [QuoteStatus::ACCEPTED, QuoteStatus::ACCEPTED],
            [QuoteStatus::DECLINED, QuoteStatus::DECLINED],

            // No skipping the buyer: a quote they never saw cannot become an
            // order.
            [QuoteStatus::REQUESTED, QuoteStatus::ACCEPTED],

            // No going backwards.
            [QuoteStatus::QUOTED, QuoteStatus::REQUESTED],
            [QuoteStatus::ACCEPTED, QuoteStatus::QUOTED],
            [QuoteStatus::ACCEPTED, QuoteStatus::DECLINED],
            [QuoteStatus::DECLINED, QuoteStatus::QUOTED],
            [QuoteStatus::DECLINED, QuoteStatus::ACCEPTED],

            // Nothing moves out of, or into, "no record".
            [QuoteStatus::NONE, QuoteStatus::QUOTED],
            [QuoteStatus::NONE, QuoteStatus::ACCEPTED],
            [QuoteStatus::REQUESTED, QuoteStatus::NONE],
            [QuoteStatus::QUOTED, QuoteStatus::NONE],
        ];

        foreach ($legal as $move) {
            $this->assertTrue(
                QuoteStatus::canTransition($move[0], $move[1]),
                $move[0] . ' -> ' . $move[1] . ' should be allowed'
            );
        }

        foreach ($illegal as $move) {
            $this->assertFalse(
                QuoteStatus::canTransition($move[0], $move[1]),
                $move[0] . ' -> ' . $move[1] . ' should be refused'
            );
        }
    }

    /**
     * A target the class does not recognise is never a legal move.
     *
     * The review screen maps a decision word to a status before it gets here,
     * but nothing stops a future caller passing a raw request value.
     */
    public function testUnknownTargetIsRefused()
    {
        $this->assertFalse(QuoteStatus::canTransition(QuoteStatus::REQUESTED, 'approved'));
        $this->assertFalse(QuoteStatus::canTransition(QuoteStatus::REQUESTED, ''));
        $this->assertFalse(QuoteStatus::canTransition(QuoteStatus::REQUESTED, null));
        $this->assertFalse(QuoteStatus::canTransition(QuoteStatus::QUOTED, ['accepted']));
    }

    /**
     * Anything unreadable fails closed, to "no record".
     *
     * An unreadable status must not be guessed as REQUESTED: that would put a
     * record the plugin cannot read in front of an owner asking them to price it.
     */
    public function testNormalizeFailsClosed()
    {
        $this->assertSame(QuoteStatus::REQUESTED, QuoteStatus::normalize('requested'));
        $this->assertSame(QuoteStatus::QUOTED, QuoteStatus::normalize('  QUOTED '));

        $this->assertSame(QuoteStatus::NONE, QuoteStatus::normalize(''));
        $this->assertSame(QuoteStatus::NONE, QuoteStatus::normalize(null));
        $this->assertSame(QuoteStatus::NONE, QuoteStatus::normalize(false));
        $this->assertSame(QuoteStatus::NONE, QuoteStatus::normalize(['quoted']));
        $this->assertSame(QuoteStatus::NONE, QuoteStatus::normalize('pending'));
        $this->assertSame(QuoteStatus::NONE, QuoteStatus::normalize('none'));
    }

    /**
     * NONE is never storable — it is the absence of a stored value.
     */
    public function testNoneIsNotStorable()
    {
        $this->assertNotContains(QuoteStatus::NONE, QuoteStatus::storable());
        $this->assertFalse(QuoteStatus::isStorable(QuoteStatus::NONE));

        foreach (QuoteStatus::storable() as $status) {
            $this->assertTrue(QuoteStatus::isStorable($status));
        }
    }

    /**
     * The decision words the buttons carry map to exactly three statuses.
     *
     * A mistyped decision has to become null rather than a stored status.
     */
    public function testDecisionWords()
    {
        $this->assertSame(QuoteStatus::QUOTED, QuoteStatus::statusForDecision('quote'));
        $this->assertSame(QuoteStatus::ACCEPTED, QuoteStatus::statusForDecision('convert'));
        $this->assertSame(QuoteStatus::DECLINED, QuoteStatus::statusForDecision('decline'));
        $this->assertSame(QuoteStatus::DECLINED, QuoteStatus::statusForDecision('  DECLINE '));

        $this->assertNull(QuoteStatus::statusForDecision('accept'));
        $this->assertNull(QuoteStatus::statusForDecision('approve'));
        $this->assertNull(QuoteStatus::statusForDecision('quoted'));
        $this->assertNull(QuoteStatus::statusForDecision(''));
        $this->assertNull(QuoteStatus::statusForDecision(null));
        $this->assertNull(QuoteStatus::statusForDecision(['convert']));
    }

    /**
     * An order is implied by exactly one status.
     */
    public function testOnlyAcceptedImpliesAnOrder()
    {
        $this->assertTrue(QuoteStatus::createsOrder(QuoteStatus::ACCEPTED));

        $this->assertFalse(QuoteStatus::createsOrder(QuoteStatus::REQUESTED));
        $this->assertFalse(QuoteStatus::createsOrder(QuoteStatus::QUOTED));
        $this->assertFalse(QuoteStatus::createsOrder(QuoteStatus::DECLINED));
        $this->assertFalse(QuoteStatus::createsOrder(QuoteStatus::NONE));
    }

    /**
     * The buyer hears about every decision, and never about their own submission.
     */
    public function testBuyerIsToldAboutDecisionsOnly()
    {
        $this->assertTrue(QuoteStatus::notifiesBuyer(QuoteStatus::QUOTED));
        $this->assertTrue(QuoteStatus::notifiesBuyer(QuoteStatus::ACCEPTED));
        $this->assertTrue(QuoteStatus::notifiesBuyer(QuoteStatus::DECLINED));

        $this->assertFalse(QuoteStatus::notifiesBuyer(QuoteStatus::REQUESTED));
        $this->assertFalse(QuoteStatus::notifiesBuyer(QuoteStatus::NONE));
        $this->assertFalse(QuoteStatus::notifiesBuyer('nonsense'));
    }

    /**
     * "Open" is exactly the two states with something left to do.
     */
    public function testOpenStates()
    {
        $this->assertTrue(QuoteStatus::isOpen(QuoteStatus::REQUESTED));
        $this->assertTrue(QuoteStatus::isOpen(QuoteStatus::QUOTED));

        $this->assertFalse(QuoteStatus::isOpen(QuoteStatus::ACCEPTED));
        $this->assertFalse(QuoteStatus::isOpen(QuoteStatus::DECLINED));
        $this->assertFalse(QuoteStatus::isOpen(QuoteStatus::NONE));
    }
}
