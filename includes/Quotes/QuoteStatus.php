<?php

namespace FluentCartBulkOrder\Quotes;

defined('ABSPATH') || exit;

/**
 * The four states a Quote Request can be in, and every move between them that
 * is allowed.
 *
 * ---------------------------------------------------------------------------
 * WHY THE STATE MACHINE IS ITS OWN, WORDPRESS-FREE CLASS
 * ---------------------------------------------------------------------------
 *
 * Deciding a quote ends in a real FluentCart order being created and charged
 * for, at a price the store owner typed by hand. The rule that says whether a
 * move is legal therefore has to be readable on its own, without a database, a
 * request, or a logged-in user anywhere near it — so it can be pinned by the
 * unit suite and re-read in one sitting.
 *
 * Nothing in this file touches WordPress. @see tests/Unit/QuoteStatusTest.php
 * and tests/README.md for the rule that keeps it that way. This mirrors
 * \FluentCartBulkOrder\Wholesale\ApplicationStatus deliberately: the two flows
 * have the same shape (a buyer asks, an owner decides, the buyer is emailed),
 * so a reader who knows one already knows the other.
 *
 * ---------------------------------------------------------------------------
 * THE STATES
 * ---------------------------------------------------------------------------
 *
 *   NONE       No quote record exists. A real state, not a null: the review
 *              screen, the store and the transition table all need something
 *              to talk about, and inventing a second spelling of "no record"
 *              at each call site is how the two drift apart.
 *
 *   REQUESTED  The buyer sent their filled table and is waiting for a price.
 *              The only state where the owner may set prices.
 *
 *   QUOTED     The owner priced it and the buyer has been emailed. The only
 *              state a conversion may act on — a price nobody has seen must
 *              not become an order.
 *
 *   ACCEPTED   Converted into a FluentCart order. Terminal here: this class
 *              will not move an accepted quote anywhere else, because the
 *              order is now the record and its own lifecycle belongs to
 *              FluentCart.
 *
 *   DECLINED   The owner will not quote it. Terminal. NOT the same as a
 *              rejected wholesale application: a declined quote is not
 *              re-opened, because the buyer simply sends a new request — every
 *              request is its own record, unlike an application, of which a
 *              user holds at most one.
 *
 * ---------------------------------------------------------------------------
 * THE THREE RULES THAT MATTER MOST
 * ---------------------------------------------------------------------------
 *
 *   1. A BUYER NEVER CHOOSES A STATE. statusAfterRequest() is the only entry
 *      point the front end reaches, and it always returns REQUESTED. There is
 *      deliberately no method here that moves anything to QUOTED or ACCEPTED
 *      except canTransition(), which is consulted only by the
 *      capability-checked admin handler.
 *
 *   2. ONLY `quoted` MAY BE CONVERTED. canConvert() is false for every other
 *      state, so a replayed convert POST for an already converted quote
 *      creates nothing. That guard lives here rather than in the request
 *      handler so a second call site cannot forget it.
 *
 *   3. PRICES ARE EDITABLE IN EXACTLY ONE STATE. canPrice() is true only for
 *      REQUESTED. Once the buyer has been sent a price, changing it silently
 *      would mean the order they accept is not the one they were quoted.
 *
 * Re-opening a quote is out of scope on purpose. "Send a corrected price" is a
 * new quote with a new reference, which is what a buyer can actually reason
 * about; mutating a price a buyer has already been emailed is the shape that
 * breeds disputes.
 */
class QuoteStatus
{
    /**
     * No record. Not stored — this is what a missing meta value reads as.
     */
    const NONE = 'none';

    /**
     * Sent by the buyer, waiting for the owner to price it.
     */
    const REQUESTED = 'requested';

    /**
     * Priced by the owner and emailed to the buyer.
     */
    const QUOTED = 'quoted';

    /**
     * Converted into a FluentCart order.
     */
    const ACCEPTED = 'accepted';

    /**
     * The owner will not quote it.
     */
    const DECLINED = 'declined';

    /**
     * Every status that can be stored, in the order a human reads them.
     *
     * NONE is absent: it is the ABSENCE of a stored value, and writing it to
     * the database would create a second spelling of "no quote" that the
     * review screen's queries would then have to exclude by hand.
     *
     * @return string[]
     */
    public static function storable()
    {
        return [self::REQUESTED, self::QUOTED, self::ACCEPTED, self::DECLINED];
    }

    /**
     * Read an untrusted status value into one this class understands.
     *
     * Anything unrecognised — a null meta read, a truncated string, a value
     * from a future version of the plugin — becomes NONE. Failing closed means
     * an unreadable record is treated as "no quote", which no screen offers an
     * action on. Guessing REQUESTED would put a record the plugin cannot read
     * in front of an owner asking them to price it.
     *
     * @param mixed $status Raw value, typically straight from post meta.
     * @return string One of the class constants.
     */
    public static function normalize($status)
    {
        if (!is_string($status)) {
            return self::NONE;
        }

        $status = strtolower(trim($status));

        return in_array($status, self::storable(), true) ? $status : self::NONE;
    }

    /**
     * Whether a status is one this class knows how to store.
     *
     * @param mixed $status
     * @return bool
     */
    public static function isStorable($status)
    {
        return is_string($status) && in_array($status, self::storable(), true);
    }

    /**
     * The status a new request lands on. Always REQUESTED, and that is the point.
     *
     * A method rather than a literal at each call site so there is exactly one
     * place a future change could make a submission land anywhere else — and
     * one place a reviewer has to read to be sure it cannot.
     *
     * @return string
     */
    public static function statusAfterRequest()
    {
        return self::REQUESTED;
    }

    /**
     * Whether the owner may still set line prices on this quote.
     *
     * @param mixed $current
     * @return bool True only for REQUESTED.
     */
    public static function canPrice($current)
    {
        return self::normalize($current) === self::REQUESTED;
    }

    /**
     * Whether this quote may still be turned into a FluentCart order.
     *
     * @param mixed $current
     * @return bool True only for QUOTED.
     */
    public static function canConvert($current)
    {
        return self::normalize($current) === self::QUOTED;
    }

    /**
     * Whether the owner still has any decision left to make on this quote.
     *
     * Drives whether the review screen shows controls or a summary, so the two
     * cannot disagree about what is actionable.
     *
     * @param mixed $current
     * @return bool
     */
    public static function isOpen($current)
    {
        $current = self::normalize($current);

        return $current === self::REQUESTED || $current === self::QUOTED;
    }

    /**
     * Whether a move from one status to another is allowed.
     *
     * The complete table — there are exactly four legal moves:
     *
     *     requested -> quoted     the owner priced it
     *     requested -> declined   the owner will not quote it
     *     quoted    -> accepted   the owner converted it to an order
     *     quoted    -> declined   the owner withdrew the quote
     *     anything else           no
     *
     * Same-state moves are refused too, so a double-clicked button cannot
     * re-send the buyer's email or create a second order.
     *
     * @param mixed $from Current status.
     * @param mixed $to   Requested status.
     * @return bool
     */
    public static function canTransition($from, $to)
    {
        $from = self::normalize($from);

        if (!self::isStorable($to)) {
            return false;
        }

        if ($from === self::REQUESTED) {
            return $to === self::QUOTED || $to === self::DECLINED;
        }

        if ($from === self::QUOTED) {
            return $to === self::ACCEPTED || $to === self::DECLINED;
        }

        return false;
    }

    /**
     * Turn an owner's decision word into the status it means.
     *
     * The request carries `quote`/`convert`/`decline` because that is what the
     * button says; the record stores `quoted`/`accepted`/`declined`. Mapping in
     * one place keeps a mistyped decision from becoming a stored status.
     *
     * @param mixed $decision Raw decision from the request.
     * @return string|null The target status, or null when the decision is not
     *                     one of the three the review screen offers.
     */
    public static function statusForDecision($decision)
    {
        if (!is_string($decision)) {
            return null;
        }

        switch (strtolower(trim($decision))) {
            case 'quote':
                return self::QUOTED;

            case 'convert':
                return self::ACCEPTED;

            case 'decline':
                return self::DECLINED;

            default:
                return null;
        }
    }

    /**
     * Whether reaching this status means a FluentCart order must exist.
     *
     * The single source of truth for "does this state imply an order". The
     * convert handler asks this before it creates one, so the state and the
     * side effect can never be decided by two different expressions.
     *
     * @param mixed $status
     * @return bool
     */
    public static function createsOrder($status)
    {
        return self::normalize($status) === self::ACCEPTED;
    }

    /**
     * Whether reaching this status means the buyer should be emailed.
     *
     * Every DECISION the owner makes is news to the buyer — a price to accept,
     * an order to pay, or a "not this time". A buyer who is never told has a
     * feature that does not work, whatever the code does.
     *
     * REQUESTED is excluded, and that is the whole content of this method: the
     * buyer is the one who caused it, so mailing them their own submission back
     * is noise. The owner is told about that one instead.
     * @see \FluentCartBulkOrder\Quotes\QuoteNotifier
     *
     * @param mixed $status
     * @return bool
     */
    public static function notifiesBuyer($status)
    {
        $status = self::normalize($status);

        return $status === self::QUOTED
            || $status === self::ACCEPTED
            || $status === self::DECLINED;
    }
}
