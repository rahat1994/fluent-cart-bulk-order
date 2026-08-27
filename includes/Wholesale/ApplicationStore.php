<?php

namespace FluentCartBulkOrder\Wholesale;

use FluentCartBulkOrder\AccessPolicy;

defined('ABSPATH') || exit;

/**
 * Where a wholesale application lives: two user meta keys, and nothing else.
 *
 * ---------------------------------------------------------------------------
 * WHY USER META AND NOT A TABLE
 * ---------------------------------------------------------------------------
 *
 * An applicant is ALWAYS a WordPress user — granting a role needs a user
 * account, so there is no such thing as an application without one. That makes
 * user meta the natural home rather than a compromise:
 *
 *   - Deleting the user deletes the application, for free and correctly. A
 *     custom table would leave orphan rows nobody ever cleans up.
 *   - Uninstall cleanup is two delete_metadata() calls
 *     (@see \FluentCartBulkOrder\Deactivator::removeSiteData()), not a DROP
 *     TABLE plus a dbDelta version option to maintain.
 *   - A custom table needs a schema, a migration path and a version check, all
 *     of which are review surface for the WordPress.org submission.
 *   - The volume is small. A store reviews wholesale applications by hand; if
 *     it has enough of them for a table to matter, it has bigger problems than
 *     this plugin.
 *
 * ---------------------------------------------------------------------------
 * WHY TWO KEYS FOR ONE RECORD
 * ---------------------------------------------------------------------------
 *
 * META_RECORD holds everything, serialized. Convenient to read, useless to
 * query — you cannot ask MySQL for "every user whose serialized array has
 * status pending" without a LIKE over the whole meta table, which is both slow
 * and wrong (it would match a company named "pending").
 *
 * META_STATUS therefore duplicates the one field the admin screen queries on,
 * as a plain string, so the review screen is an ordinary indexed `meta_query`.
 * The duplication is deliberate and one-directional: every write goes through
 * this class and updates both, and every read of the status prefers META_STATUS
 * with the record as the fallback. @see statusFor().
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS MEANS ON MULTISITE — a known, accepted limitation
 * ---------------------------------------------------------------------------
 *
 * User meta lives in ONE table shared by the whole network, and these two keys
 * are not site-prefixed. So an application is NETWORK-wide while the role it
 * grants is PER-SITE (WordPress prefixes the capabilities meta key per site).
 * A buyer approved on site A therefore holds the role only on site A, but their
 * application reads as approved on site B as well — where the form will tell
 * them they are already approved while the wholesale surfaces still refuse
 * them.
 *
 * Not fixed, and not an accident. Prefixing the keys would make the review
 * screen and the uninstall cleanup per-site too, and this plugin's existing
 * user meta (`fcbo_saved_lists`) already has exactly this shape — changing one
 * and not the other would be worse than either. A network selling wholesale
 * from more than one of its sites is the case that would justify the work, and
 * it deserves its own issue rather than being guessed at here.
 *
 * ---------------------------------------------------------------------------
 * TIMESTAMPS ARE UTC
 * ---------------------------------------------------------------------------
 *
 * Stored as `time()` — a Unix timestamp, which is UTC by definition — and never
 * as a site-local string. A site that changes timezone must not appear to have
 * changed when its applications were submitted, and comparing two records is
 * an integer comparison rather than a date parse. Formatting for a human is the
 * render site's job, through `wp_date()`, which applies the site's timezone at
 * display time.
 */
class ApplicationStore
{
    /**
     * The whole record, as one serialized array.
     *
     * Underscore-prefixed so WordPress treats it as protected meta and keeps it
     * out of the Custom Fields box on the user editor — an admin must not be
     * able to hand-edit a status into `approved` there, because that would
     * bypass the role grant and leave the two disagreeing.
     *
     * RENAMING THIS ALSO MEANS EDITING
     * \FluentCartBulkOrder\Deactivator::removeWholesaleApplications(), which
     * repeats the literal because uninstall.php must not load this class.
     */
    const META_RECORD = '_fcbo_wholesale_application';

    /**
     * The record's status, duplicated for querying. @see the class docblock.
     *
     * Same rename caveat as META_RECORD.
     */
    const META_STATUS = '_fcbo_wholesale_status';

    /**
     * The shape of a record, and the default for every key.
     *
     * Read through get(), which merges a stored record over this — so a record
     * written by an older version is missing keys, not broken.
     *
     * @var array<string, mixed>
     */
    const RECORD_DEFAULTS = [
        'status'       => ApplicationStatus::NONE,
        'fields'       => [],
        'submitted_at' => 0,
        'updated_at'   => 0,
        'reviewed_at'  => 0,
        'reviewer_id'  => 0,
        'note'         => '',
    ];

    /**
     * One user's application record, always in RECORD_DEFAULTS' shape.
     *
     * @param int $userId
     * @return array<string, mixed> A record with status NONE when there is none.
     */
    public static function get($userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return self::RECORD_DEFAULTS;
        }

        $stored = get_user_meta($userId, self::META_RECORD, true);

        if (!is_array($stored)) {
            return self::RECORD_DEFAULTS;
        }

        $record = array_merge(self::RECORD_DEFAULTS, $stored);

        // The stored status is normalised on read as well as on write. A record
        // whose status cannot be read is treated as "never applied", which lets
        // the user apply again — better than showing an admin a decision they
        // cannot act on. @see ApplicationStatus::normalize()
        $record['status'] = ApplicationStatus::normalize($record['status']);
        $record['fields'] = is_array($record['fields']) ? $record['fields'] : [];

        return $record;
    }

    /**
     * One user's status, without unserializing the whole record.
     *
     * Reads the indexed key first because that is the one the admin screen
     * queries on — if the two ever disagreed, the screen and the detail view
     * would show different things, so the query key is the authority and the
     * record is the fallback for a row written before it existed.
     *
     * @param int $userId
     * @return string An ApplicationStatus constant.
     */
    public static function statusFor($userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return ApplicationStatus::NONE;
        }

        $status = ApplicationStatus::normalize(get_user_meta($userId, self::META_STATUS, true));

        if ($status !== ApplicationStatus::NONE) {
            return $status;
        }

        return self::get($userId)['status'];
    }

    /**
     * Store a submitted application, creating or replacing the user's one record.
     *
     * The status is NOT a parameter. It comes from
     * ApplicationStatus::statusAfterApply(), which always returns PENDING —
     * there is deliberately no way for a caller on the front-end path to name
     * the status it wants. @see ApplicationStatus for the full reasoning.
     *
     * `submitted_at` is preserved across a re-submission so the admin sees when
     * the applicant FIRST asked, not when they last edited a typo. `updated_at`
     * carries the latest edit. A re-application after a rejection clears the
     * old reviewer and note, because they describe a decision that no longer
     * applies to what is now in front of the admin.
     *
     * @param int                  $userId
     * @param array<string, mixed> $values Validated field values.
     *                                     @see ApplicationInput::validate()
     * @return array<string, mixed>|null The stored record, or null when the
     *                                   user is not eligible to apply.
     */
    public static function saveApplication($userId, $values)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return null;
        }

        // statusFor(), not get()['status']: META_STATUS is the authority.
        // @see the class docblock, and review() for what reading the wrong one
        // costs.
        $current  = self::statusFor($userId);
        $existing = self::get($userId);

        if (!ApplicationStatus::canApply($current)) {
            return null;
        }

        $now = time();

        $record = [
            'status'       => ApplicationStatus::statusAfterApply(),
            'fields'       => self::mergeFields($existing['fields'], $values),
            'submitted_at' => $existing['submitted_at'] > 0 ? (int) $existing['submitted_at'] : $now,
            'updated_at'   => $now,
            'reviewed_at'  => 0,
            'reviewer_id'  => 0,
            'note'         => '',
        ];

        // Take the row before writing to it. Between the read above and this
        // line an admin can have approved the application: without the claim,
        // the applicant's in-flight submission writes `pending` back over the
        // decision, leaving a user who HOLDS the wholesale role with a pending
        // record — which the admin can then reject, taking the paperwork away
        // and leaving the role behind.
        if (!self::claimForApplicant($userId, $current)) {
            return null;
        }

        self::write($userId, $record);

        /**
         * Fires after an application is stored, before the applicant is
         * redirected. The seam the FluentCRM tagging and the admin notification
         * both hang off, so neither has to be called from the request handler.
         *
         * @param int                  $userId
         * @param array<string, mixed> $record
         * @param string               $outcome An ApplicationStatus::OUTCOME_* value.
         */
        do_action(
            'fcbo/wholesale/application_submitted',
            $userId,
            $record,
            ApplicationStatus::applyOutcome($existing['status'])
        );

        return $record;
    }

    /**
     * Record an admin decision and, for an approval, grant the role.
     *
     * ---------------------------------------------------------------------------
     * THIS METHOD DOES NOT AUTHORISE ANYTHING
     * ---------------------------------------------------------------------------
     *
     * It checks that the TRANSITION is legal — that the application is pending
     * and the target is one of the two decisions. It does NOT check that the
     * caller is an administrator or that a nonce was present; those belong to
     * the request handler, which is the only place that knows about a request.
     * @see \FluentCartBulkOrder\Wholesale\ReviewScreen::handleDecision()
     *
     * Do not call this from anywhere that has not already done both checks.
     *
     * @param int    $userId
     * @param string $status     ApplicationStatus::APPROVED or REJECTED.
     * @param int    $reviewerId The deciding admin's user id.
     * @param string $note       Sanitised note, shown to the applicant.
     * @return array<string, mixed>|null The updated record, or null when the
     *                                   transition was refused.
     */
    public static function review($userId, $status, $reviewerId, $note = '')
    {
        $userId = (int) $userId;
        $record = self::get($userId);

        // The transition is judged on META_STATUS, NOT on the record's own copy
        // of it. write() is two update_user_meta() calls and is not atomic, so
        // a lost second write leaves the record saying `approved` while the
        // query key still says `pending`. Gating on the record would then wedge
        // that application forever: it stays in the Pending tab, which queries
        // META_STATUS, while every retry is refused as "already decided".
        // Gating on the query key — the authority, per the class docblock and
        // statusFor() — makes the same failure self-healing on a retry.
        $current = self::statusFor($userId);

        if ($userId <= 0 || !ApplicationStatus::canTransition($current, $status)) {
            return null;
        }

        $user = get_userdata($userId);

        // The user could have been deleted between the review screen rendering
        // and the button being pressed. Writing meta for a missing user id
        // would create a row nothing ever reads or cleans up.
        if (!$user) {
            return null;
        }

        // CLAIM the decision before acting on it. canTransition() above read the
        // status a moment ago; two overlapping requests — a double-clicked
        // Approve, or two admins deciding at the same instant — both read
        // `pending` and both pass. Without a claim they would both send the
        // applicant an email, and a simultaneous approve and reject would
        // decide by whichever wrote last.
        if (!self::claim($userId, $status)) {
            return null;
        }

        // Past this line the decision is exclusively ours.
        $record['status']      = $status;
        $record['reviewed_at'] = time();
        $record['reviewer_id'] = (int) $reviewerId;
        $record['note']        = (string) $note;

        // The role is granted BEFORE the record is written, and a failed grant
        // hands the claim back. An approved record whose user does not hold the
        // role is a state no screen shows: the applicant is told they have
        // wholesale access, the review screen says Approved, and the state
        // machine then refuses every retry — so nobody can see it, let alone
        // fix it, without editing user meta by hand. Staying pending is a
        // recoverable outcome; that is not.
        if (ApplicationStatus::grantsRole($status) && !self::grantRole($user)) {
            self::releaseClaim($userId);

            return null;
        }

        self::write($userId, $record);

        /**
         * Fires after an application is decided. Where the applicant email and
         * the FluentCRM tagging hang off.
         *
         * @param int                  $userId
         * @param array<string, mixed> $record
         * @param string               $status The new status.
         */
        do_action('fcbo/wholesale/application_reviewed', $userId, $record, $status);

        return $record;
    }

    /**
     * Add the wholesale role to a user, keeping the roles they already have.
     *
     * `add_role()` rather than `set_role()`: set_role() REPLACES every role the
     * user holds. An admin approving their own test account, or a shop manager
     * applying, would be demoted to wholesale-customer and could lose access to
     * their own store. add_role() is a no-op when the user already holds it.
     *
     * @param \WP_User $user
     * @return bool Whether the user holds the role once this returns. The
     *              caller MUST check it — @see review() for what an unnoticed
     *              failure here would leave behind.
     */
    private static function grantRole($user)
    {
        if (in_array(AccessPolicy::WHOLESALE_ROLE, (array) $user->roles, true)) {
            return true;
        }

        // A site can remove the role after the plugin created it — a role
        // manager plugin, a migration, a half-finished uninstall. Nothing
        // re-creates it, because Activator only runs on activation. Adding an
        // unknown role slug writes a capability set of nothing, which looks
        // exactly like success and grants no access at all.
        if (!get_role(AccessPolicy::WHOLESALE_ROLE)) {
            return false;
        }

        $user->add_role(AccessPolicy::WHOLESALE_ROLE);

        // Re-read rather than trust: add_role() returns void, and a site can
        // veto the write from `user_has_cap` or by filtering the roles option.
        $fresh = get_userdata($user->ID);

        return $fresh && in_array(AccessPolicy::WHOLESALE_ROLE, (array) $fresh->roles, true);
    }

    /**
     * Keep answers to questions the owner has since removed.
     *
     * ---------------------------------------------------------------------------
     * WHY A MERGE AND NOT A REPLACE
     * ---------------------------------------------------------------------------
     *
     * ApplicationInput::validate() walks the CURRENT schema, so a key whose
     * question the owner deleted never appears in the new values. A plain
     * replace would therefore delete that answer — and both places that show an
     * application (the review screen and the admin's email) go out of their way
     * to keep showing such an answer under its raw key, on the stated grounds
     * that dropping it "would change the record in front of them without saying
     * so".
     *
     * The write path has to honour the same promise, because the applicant is
     * actively invited back: a pending application says "you can correct your
     * answers below". An applicant fixing a typo in their tax ID must not
     * silently erase the trade reference an admin read yesterday and is
     * part-way through judging.
     *
     * A question the owner still asks is always overwritten by the new answer;
     * only keys the schema no longer knows about survive.
     *
     * @param array<string, mixed> $existing Answers already stored.
     * @param mixed                $values   New, schema-validated answers.
     * @return array<string, mixed>
     */
    private static function mergeFields($existing, $values)
    {
        $values   = is_array($values) ? $values : [];
        $existing = is_array($existing) ? $existing : [];

        foreach ($existing as $key => $old) {
            if (!array_key_exists($key, $values)) {
                $values[$key] = $old;
            }
        }

        return $values;
    }

    /**
     * Take the applicant's own row before writing their submission to it.
     *
     * The mirror of claim(), for the other direction. An applicant is allowed
     * to write from NONE, PENDING or REJECTED, and the only interleave that
     * matters is an admin deciding in between — @see saveApplication().
     *
     * The NONE case needs no claim and cannot have one: there is no status row
     * yet, and review() only acts on a PENDING row, so there is nothing for a
     * first-time submission to race with.
     *
     * PENDING -> PENDING writes the same value, so MySQL reports zero rows
     * CHANGED even when the row matched — which is why this cannot simply trust
     * the affected-row count the way claim() does. It reads the value back
     * instead: whatever happened concurrently, the only state an applicant may
     * proceed from is one where the row still says `pending`. An admin decision
     * that landed first leaves `approved` or `rejected` there, and this refuses.
     *
     * @param int    $userId
     * @param string $from The status read a moment ago.
     * @return bool
     */
    private static function claimForApplicant($userId, $from)
    {
        if ($from === ApplicationStatus::NONE) {
            return true;
        }

        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->usermeta,
            ['meta_value' => ApplicationStatus::PENDING],
            [
                'user_id'    => $userId,
                'meta_key'   => self::META_STATUS,
                'meta_value' => $from,
            ],
            ['%s'],
            ['%d', '%s', '%s']
        );

        if ($updated === false) {
            return false;
        }

        wp_cache_delete($userId, 'user_meta');

        return self::statusFor($userId) === ApplicationStatus::PENDING;
    }

    /**
     * Take exclusive ownership of a pending application's decision.
     *
     * ---------------------------------------------------------------------------
     * WHY A CONDITIONAL UPDATE AND NOT A LOCK
     * ---------------------------------------------------------------------------
     *
     * The obvious mutex — write a lock option, do the work, delete it — has a
     * failure mode this cannot afford: a request that dies between the two
     * leaves a lock nothing ever clears, and the application becomes
     * permanently undecidable. There is no safe timeout to pick either, because
     * the work here sends mail and talks to a CRM.
     *
     * A single conditional UPDATE has no such state. `WHERE meta_value =
     * 'pending'` means the database itself picks one winner: exactly one
     * concurrent statement matches the row, the rest affect zero rows and back
     * out. Nothing is left behind if the winner then crashes — the status is
     * simply already decided, which is true.
     *
     * This is the one place the plugin writes user meta with $wpdb rather than
     * update_user_meta(), because update_user_meta() cannot express "only if it
     * still says pending". The object cache is cleared by hand for that reason.
     *
     * @param int    $userId
     * @param string $status The decided status to claim the row for.
     * @return bool True for the one caller that won the claim.
     */
    private static function claim($userId, $status)
    {
        global $wpdb;

        $claimed = $wpdb->update(
            $wpdb->usermeta,
            ['meta_value' => $status],
            [
                'user_id'    => $userId,
                'meta_key'   => self::META_STATUS,
                'meta_value' => ApplicationStatus::PENDING,
            ],
            ['%s'],
            ['%d', '%s', '%s']
        );

        // 0 means another request already moved the row; false means the query
        // failed. Neither is a claim.
        if ($claimed !== 1) {
            return false;
        }

        // $wpdb wrote behind update_user_meta()'s back, so the cached copy is
        // now stale. Without this, statusFor() in the same request would still
        // read `pending`.
        wp_cache_delete($userId, 'user_meta');

        return true;
    }

    /**
     * Hand a claim back, leaving the application pending for another try.
     *
     * @param int $userId
     * @return void
     */
    private static function releaseClaim($userId)
    {
        update_user_meta($userId, self::META_STATUS, ApplicationStatus::PENDING);
    }

    /**
     * Whether a user already holds the wholesale role, however they got it.
     *
     * Separate from the application status on purpose: a store owner can assign
     * the role by hand in wp-admin, which is how every site worked before this
     * feature existed. Such a user has no application record, and the form must
     * still tell them they already have access rather than inviting them to
     * apply for something they hold.
     *
     * @param \WP_User|int|null $user
     * @return bool
     */
    public static function userHasWholesaleRole($user = null)
    {
        // "Not supplied" and "supplied but did not resolve" must not collapse
        // into the same branch. They did, and the result was that asking about
        // a deleted or unknown user id silently answered about the CURRENT
        // user instead — so a reviewing admin's own roles could stand in for
        // the applicant's. Every caller today happens to pass the live user id,
        // but a "no" that turns into a "yes" on a role check is the wrong shape
        // of bug to leave for the next caller.
        if ($user === null) {
            $user = wp_get_current_user();
        } elseif (is_numeric($user)) {
            $user = get_userdata((int) $user);
        }

        if (!$user instanceof \WP_User) {
            return false;
        }

        return in_array(AccessPolicy::WHOLESALE_ROLE, (array) $user->roles, true);
    }

    /**
     * How many applications are sitting in one status.
     *
     * Used for the pending count on the admin menu, so it runs on every admin
     * page load. `'fields' => 'ID'` with `'number' => 1` keeps it to a COUNT
     * plus one row rather than hydrating every matching WP_User.
     *
     * @param string $status
     * @return int
     */
    public static function countByStatus($status)
    {
        if (!ApplicationStatus::isStorable($status)) {
            return 0;
        }

        $query = new \WP_User_Query([
            'meta_key'    => self::META_STATUS,
            'meta_value'  => $status,
            'fields'      => 'ID',
            'number'      => 1,
            'count_total' => true,
        ]);

        return (int) $query->get_total();
    }

    /**
     * One page of applicants, newest application first.
     *
     * Ordering is by META_RECORD rather than by anything on the users table:
     * "the newest APPLICATION" is not "the newest user". A serialized array
     * cannot be sorted meaningfully, so the sort falls back to user id
     * descending, which is a stable proxy — later ids applied later on any site
     * where users register before they apply.
     *
     * @param string $status  An ApplicationStatus constant, or '' for any
     *                        application whatever its status.
     * @param int    $page    1-based.
     * @param int    $perPage
     * @return array{users: \WP_User[], total: int}
     */
    public static function page($status, $page = 1, $perPage = 20)
    {
        $page    = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);

        $args = [
            'number'      => $perPage,
            'offset'      => ($page - 1) * $perPage,
            'orderby'     => 'ID',
            'order'       => 'DESC',
            'count_total' => true,
        ];

        if (ApplicationStatus::isStorable($status)) {
            $args['meta_key']   = self::META_STATUS;
            $args['meta_value'] = $status;
        } else {
            // "Any application" — the status key exists for every applicant and
            // for nobody else, so EXISTS is the whole filter.
            $args['meta_query'] = [
                [
                    'key'     => self::META_STATUS,
                    'compare' => 'EXISTS',
                ],
            ];
        }

        $query = new \WP_User_Query($args);

        return [
            'users' => (array) $query->get_results(),
            'total' => (int) $query->get_total(),
        ];
    }

    /**
     * The WordPress sanitisers ApplicationInput should use.
     *
     * Lives here rather than at each call site so the pure validator is handed
     * the same pair everywhere. @see ApplicationInput for why they are injected
     * rather than called directly.
     *
     * @return array{0: callable, 1: callable} [text, textarea]
     */
    public static function sanitizers()
    {
        return ['sanitize_text_field', 'sanitize_textarea_field'];
    }

    /**
     * Write both meta keys. The ONLY place either is written.
     *
     * Both writes together, in one method, because the whole design depends on
     * them agreeing — see the class docblock. A second write site is how they
     * would start to drift.
     *
     * @param int                  $userId
     * @param array<string, mixed> $record
     * @return void
     */
    private static function write($userId, array $record)
    {
        update_user_meta($userId, self::META_RECORD, $record);
        update_user_meta($userId, self::META_STATUS, $record['status']);
    }

    /**
     * Remove a user's application entirely.
     *
     * Not reachable from any screen — it exists for uninstall-adjacent tooling
     * and for tests, which need a way back to a clean state. Deleting an
     * application does NOT take the role away: that assignment is the user's
     * now, and removing it is a separate decision an admin makes in wp-admin.
     *
     * @param int $userId
     * @return void
     */
    public static function delete($userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return;
        }

        delete_user_meta($userId, self::META_RECORD);
        delete_user_meta($userId, self::META_STATUS);
    }
}
