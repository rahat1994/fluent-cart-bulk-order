<?php

namespace FluentCartBulkOrder\Wholesale;

defined('ABSPATH') || exit;

/**
 * The four states a wholesale application can be in, and every move between
 * them that is allowed.
 *
 * ---------------------------------------------------------------------------
 * WHY THE STATE MACHINE IS ITS OWN, WORDPRESS-FREE CLASS
 * ---------------------------------------------------------------------------
 *
 * Approving an application hands a shopper the `wholesale-customer` role, which
 * is the most privileged thing this plugin can do. The rule that decides
 * whether a move is legal therefore has to be readable on its own, without a
 * database, a request, or a logged-in user anywhere near it — so it can be
 * pinned by the unit suite and re-read in one sitting.
 *
 * Nothing in this file touches WordPress. @see tests/Unit/ApplicationStatusTest.php
 * and tests/README.md for the rule that keeps it that way.
 *
 * ---------------------------------------------------------------------------
 * THE STATES
 * ---------------------------------------------------------------------------
 *
 *   NONE      No application record exists. The user has never applied. This is
 *             a real state, not a null: the form, the review screen and the
 *             transition table all need something to talk about, and inventing
 *             a fourth spelling of "no record" at each call site is how the
 *             three drift apart.
 *
 *   PENDING   Submitted, waiting for an admin. The ONLY state an admin decision
 *             may act on.
 *
 *   APPROVED  An admin said yes. The role has been granted. Terminal here: this
 *             class will not move an approved application anywhere else.
 *
 *   REJECTED  An admin said no. NOT terminal — the applicant may apply again,
 *             which is the whole reason a rejection is stored rather than the
 *             record being deleted. The stored note is what the applicant is
 *             shown so they know what to fix.
 *
 * ---------------------------------------------------------------------------
 * THE TWO RULES THAT MATTER MOST
 * ---------------------------------------------------------------------------
 *
 *   1. ONLY `pending` CAN BE DECIDED. canReview() is false for every other
 *      state, so a replayed or hand-crafted approve POST for an already
 *      approved (or already rejected) user changes nothing. This is the guard
 *      that makes the admin action idempotent, and it lives here rather than in
 *      the request handler so it cannot be forgotten by a second call site.
 *
 *   2. AN APPLICANT NEVER CHOOSES THEIR OWN NEXT STATE. applyOutcome() is the
 *      only entry point the front-end form uses, and every branch of it lands
 *      on PENDING or refuses. There is deliberately no method here that lets a
 *      caller move anything TO approved except reviewTo(), which is reached
 *      only from the capability-checked admin handler.
 *
 * Revoking an approved application is out of scope on purpose. Taking a role
 * back is a different, rarer act with different consequences (the buyer may
 * have live subscriptions priced against it), and wp-admin's own user editor
 * already does it. Modelling it as "reject an approved application" would make
 * the one state that grants a privilege reachable in two directions, which is
 * exactly the shape that breeds bugs.
 */
class ApplicationStatus
{
    /**
     * No record. Not stored — this is what a missing meta value reads as.
     */
    const NONE = 'none';

    /**
     * Submitted, awaiting an admin decision.
     */
    const PENDING = 'pending';

    /**
     * Approved. The `wholesale-customer` role has been granted.
     */
    const APPROVED = 'approved';

    /**
     * Rejected. The applicant may submit again.
     */
    const REJECTED = 'rejected';

    /**
     * Outcome of applyOutcome(): a first-time application, record created.
     */
    const OUTCOME_CREATED = 'created';

    /**
     * Outcome of applyOutcome(): an existing record was replaced in place.
     *
     * Covers BOTH re-submitting while pending and re-applying after a
     * rejection. One pending application per user is a hard rule
     * (@see applyOutcome()), so neither case may create a second record.
     */
    const OUTCOME_UPDATED = 'updated';

    /**
     * Outcome of applyOutcome(): refused, the user is already approved.
     *
     * Not an error the applicant caused — they simply have nothing to apply
     * for — so the form shows their approved state rather than a failure.
     */
    const OUTCOME_ALREADY_APPROVED = 'already_approved';

    /**
     * Every status that can be stored, in the order a human reads them.
     *
     * NONE is absent: it is the ABSENCE of a stored value, and writing it to
     * the database would create a second spelling of "never applied" that the
     * pending-application query would then have to exclude by hand.
     *
     * @return string[]
     */
    public static function storable()
    {
        return [self::PENDING, self::APPROVED, self::REJECTED];
    }

    /**
     * Read an untrusted status value into one this class understands.
     *
     * Anything unrecognised — a null meta read, a truncated string, a value
     * from a future version of the plugin — becomes NONE. Failing closed here
     * means an unreadable record is treated as "has not applied", which lets
     * the user apply again; the alternative, guessing PENDING, would put a
     * record the plugin cannot read in front of an admin asking for a decision.
     *
     * @param mixed $status Raw value, typically straight from user meta.
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
     * What happens when the user submits the front-end form.
     *
     * The whole "one pending application per user" decision is this method. A
     * user who applies twice does not get two rows in an admin's queue; the
     * second submission replaces the first, because the second is the one they
     * mean. A rejected user re-applying takes the same path — their record
     * moves back to PENDING, carrying the new answers, and the admin sees one
     * application rather than a history to page through.
     *
     * @param mixed $current Current status; anything unreadable is NONE.
     * @return string One of the OUTCOME_* constants.
     */
    public static function applyOutcome($current)
    {
        $current = self::normalize($current);

        if ($current === self::APPROVED) {
            return self::OUTCOME_ALREADY_APPROVED;
        }

        return $current === self::NONE ? self::OUTCOME_CREATED : self::OUTCOME_UPDATED;
    }

    /**
     * Whether an applicant in this state may submit the form at all.
     *
     * True for everything except APPROVED. The form still renders for an
     * approved user — it just shows them their approved state instead of
     * inputs. @see \FluentCartBulkOrder\Shortcodes\WholesaleApplication
     *
     * @param mixed $current
     * @return bool
     */
    public static function canApply($current)
    {
        return self::applyOutcome($current) !== self::OUTCOME_ALREADY_APPROVED;
    }

    /**
     * The status a submission lands on. Always PENDING, and that is the point.
     *
     * A method rather than a literal at each call site so there is exactly one
     * place a future change could make a submission land anywhere else — and
     * one place a reviewer has to read to be sure it cannot.
     *
     * @return string
     */
    public static function statusAfterApply()
    {
        return self::PENDING;
    }

    /**
     * Whether an admin decision may act on this application.
     *
     * @param mixed $current
     * @return bool True only for PENDING.
     */
    public static function canReview($current)
    {
        return self::normalize($current) === self::PENDING;
    }

    /**
     * Whether a move from one status to another is allowed.
     *
     * The complete table — there are exactly two legal decisions:
     *
     *     pending -> approved   yes
     *     pending -> rejected   yes
     *     anything else         no
     *
     * Same-state moves are refused too, so a double-clicked Approve button
     * cannot re-run the grant, re-send the email and re-fire the CRM tag.
     *
     * @param mixed $from Current status.
     * @param mixed $to   Requested status.
     * @return bool
     */
    public static function canTransition($from, $to)
    {
        if (!self::canReview($from)) {
            return false;
        }

        return $to === self::APPROVED || $to === self::REJECTED;
    }

    /**
     * Turn an admin's decision word into the status it means.
     *
     * The request carries `approve`/`reject` because that is what the button
     * says; the record stores `approved`/`rejected`. Mapping in one place keeps
     * a mistyped decision from becoming a stored status.
     *
     * @param mixed $decision Raw decision from the request.
     * @return string|null The target status, or null when the decision is not
     *                     one of the two the review screen offers.
     */
    public static function statusForDecision($decision)
    {
        if (!is_string($decision)) {
            return null;
        }

        switch (strtolower(trim($decision))) {
            case 'approve':
                return self::APPROVED;

            case 'reject':
                return self::REJECTED;

            default:
                return null;
        }
    }

    /**
     * Whether holding this status means the user should hold the wholesale role.
     *
     * The single source of truth for "does this record grant a privilege". The
     * approve handler asks this before calling add_role(), so the privilege and
     * the state can never be decided by two different expressions.
     *
     * @param mixed $status
     * @return bool
     */
    public static function grantsRole($status)
    {
        return self::normalize($status) === self::APPROVED;
    }
}
