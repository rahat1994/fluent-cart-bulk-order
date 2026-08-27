<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Wholesale\ApplicationStatus;
use PHPUnit\Framework\TestCase;

/**
 * The wholesale application state machine.
 *
 * This is the class that decides whether a request may hand someone the
 * `wholesale-customer` role, so the assertions worth reading first are the
 * negative ones: an approved application cannot be approved again, a rejected
 * one cannot be approved without a fresh submission, and no path an applicant
 * can reach ever produces APPROVED.
 */
class ApplicationStatusTest extends TestCase
{
    /**
     * Only a pending application is decidable. Everything else refuses.
     *
     * This is the guard that makes the admin approve action idempotent: a
     * replayed POST, a double-clicked button, or a hand-crafted request naming
     * an already-approved user all land here and stop.
     */
    public function testOnlyPendingCanBeReviewed()
    {
        $this->assertTrue(ApplicationStatus::canReview(ApplicationStatus::PENDING));

        $this->assertFalse(ApplicationStatus::canReview(ApplicationStatus::APPROVED));
        $this->assertFalse(ApplicationStatus::canReview(ApplicationStatus::REJECTED));
        $this->assertFalse(ApplicationStatus::canReview(ApplicationStatus::NONE));
        $this->assertFalse(ApplicationStatus::canReview(''));
        $this->assertFalse(ApplicationStatus::canReview(null));
    }

    /**
     * The complete transition table, stated as a table.
     *
     * Two legal moves and nothing else — including no same-state move, so
     * approving twice cannot re-grant the role, re-send the email and re-fire
     * the CRM tag.
     */
    public function testTransitionTable()
    {
        $legal = [
            [ApplicationStatus::PENDING, ApplicationStatus::APPROVED],
            [ApplicationStatus::PENDING, ApplicationStatus::REJECTED],
        ];

        $illegal = [
            [ApplicationStatus::PENDING, ApplicationStatus::PENDING],
            [ApplicationStatus::PENDING, ApplicationStatus::NONE],
            [ApplicationStatus::APPROVED, ApplicationStatus::REJECTED],
            [ApplicationStatus::APPROVED, ApplicationStatus::APPROVED],
            [ApplicationStatus::APPROVED, ApplicationStatus::PENDING],
            [ApplicationStatus::REJECTED, ApplicationStatus::APPROVED],
            [ApplicationStatus::REJECTED, ApplicationStatus::REJECTED],
            [ApplicationStatus::NONE, ApplicationStatus::APPROVED],
            [ApplicationStatus::NONE, ApplicationStatus::REJECTED],
        ];

        foreach ($legal as $move) {
            $this->assertTrue(
                ApplicationStatus::canTransition($move[0], $move[1]),
                $move[0] . ' -> ' . $move[1] . ' must be allowed'
            );
        }

        foreach ($illegal as $move) {
            $this->assertFalse(
                ApplicationStatus::canTransition($move[0], $move[1]),
                $move[0] . ' -> ' . $move[1] . ' must be refused'
            );
        }
    }

    /**
     * Revoking an approval is deliberately not modelled here.
     *
     * Pinned as its own test because it looks like an omission. It is a
     * decision: the one state that grants a privilege is reachable from exactly
     * one direction. Taking the role back is wp-admin's job.
     */
    public function testAnApprovedApplicationIsTerminal()
    {
        foreach ([ApplicationStatus::PENDING, ApplicationStatus::REJECTED, ApplicationStatus::NONE] as $target) {
            $this->assertFalse(
                ApplicationStatus::canTransition(ApplicationStatus::APPROVED, $target),
                'nothing may move an approved application to ' . $target
            );
        }
    }

    /**
     * Re-applying while pending updates the one record; it never creates a
     * second. Decision 3 of the feature, stated as an assertion.
     */
    public function testReapplyingWhilePendingUpdatesInPlace()
    {
        $this->assertSame(
            ApplicationStatus::OUTCOME_UPDATED,
            ApplicationStatus::applyOutcome(ApplicationStatus::PENDING)
        );
    }

    /**
     * A rejected applicant may try again, and their record is reused.
     */
    public function testRejectedUserMayApplyAgain()
    {
        $this->assertTrue(ApplicationStatus::canApply(ApplicationStatus::REJECTED));

        $this->assertSame(
            ApplicationStatus::OUTCOME_UPDATED,
            ApplicationStatus::applyOutcome(ApplicationStatus::REJECTED)
        );
    }

    /**
     * A first-time applicant creates a record.
     */
    public function testFirstApplicationCreates()
    {
        $this->assertTrue(ApplicationStatus::canApply(ApplicationStatus::NONE));

        $this->assertSame(
            ApplicationStatus::OUTCOME_CREATED,
            ApplicationStatus::applyOutcome(ApplicationStatus::NONE)
        );
    }

    /**
     * An approved user has nothing to apply for.
     */
    public function testApprovedUserCannotApplyAgain()
    {
        $this->assertFalse(ApplicationStatus::canApply(ApplicationStatus::APPROVED));

        $this->assertSame(
            ApplicationStatus::OUTCOME_ALREADY_APPROVED,
            ApplicationStatus::applyOutcome(ApplicationStatus::APPROVED)
        );
    }

    /**
     * Whatever the applicant's current state, a submission lands on pending.
     *
     * The security property this whole class exists for: no route an applicant
     * can drive ends anywhere near APPROVED.
     */
    public function testEverySubmissionLandsOnPending()
    {
        $this->assertSame(ApplicationStatus::PENDING, ApplicationStatus::statusAfterApply());

        foreach ([ApplicationStatus::NONE, ApplicationStatus::PENDING, ApplicationStatus::REJECTED] as $from) {
            $this->assertNotSame(
                ApplicationStatus::OUTCOME_ALREADY_APPROVED,
                ApplicationStatus::applyOutcome($from),
                'applying from ' . $from . ' should proceed'
            );
        }
    }

    /**
     * An unreadable stored status fails closed to "never applied", not to
     * "pending" and certainly not to "approved".
     */
    public function testUnknownStatusesNormalizeToNone()
    {
        $junk = ['', 'Approved!', 'approve', 'granted', null, 0, false, [], new \stdClass()];

        foreach ($junk as $value) {
            $this->assertSame(
                ApplicationStatus::NONE,
                ApplicationStatus::normalize($value),
                'unreadable status must read as none'
            );
        }
    }

    /**
     * Case and stray whitespace in a stored value are tolerated, because a
     * hand-edited meta row should not orphan an application.
     */
    public function testStoredStatusesAreReadLeniently()
    {
        $this->assertSame(ApplicationStatus::PENDING, ApplicationStatus::normalize(' Pending '));
        $this->assertSame(ApplicationStatus::APPROVED, ApplicationStatus::normalize('APPROVED'));
        $this->assertSame(ApplicationStatus::REJECTED, ApplicationStatus::normalize("rejected\n"));
    }

    /**
     * `none` is never written to the database — the absence of the meta row is
     * what "never applied" means.
     */
    public function testNoneIsNotStorable()
    {
        $this->assertFalse(ApplicationStatus::isStorable(ApplicationStatus::NONE));
        $this->assertSame(
            [ApplicationStatus::PENDING, ApplicationStatus::APPROVED, ApplicationStatus::REJECTED],
            ApplicationStatus::storable()
        );
    }

    /**
     * The decision word the button posts maps to exactly two statuses.
     */
    public function testDecisionMapping()
    {
        $this->assertSame(ApplicationStatus::APPROVED, ApplicationStatus::statusForDecision('approve'));
        $this->assertSame(ApplicationStatus::REJECTED, ApplicationStatus::statusForDecision('reject'));
        $this->assertSame(ApplicationStatus::APPROVED, ApplicationStatus::statusForDecision(' Approve '));

        foreach (['approved', 'grant', '', 'delete', null, ['approve']] as $junk) {
            $this->assertNull(
                ApplicationStatus::statusForDecision($junk),
                'only approve/reject may become a status'
            );
        }
    }

    /**
     * Exactly one status grants the role.
     */
    public function testOnlyApprovedGrantsTheRole()
    {
        $this->assertTrue(ApplicationStatus::grantsRole(ApplicationStatus::APPROVED));

        foreach ([ApplicationStatus::PENDING, ApplicationStatus::REJECTED, ApplicationStatus::NONE, 'anything'] as $status) {
            $this->assertFalse(ApplicationStatus::grantsRole($status));
        }
    }
}
