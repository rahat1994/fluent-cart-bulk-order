<?php

namespace FluentCartBulkOrder\Quotes;

defined('ABSPATH') || exit;

/**
 * Where a Quote Request lives: one private custom post per quote, plus two
 * meta keys, and nothing else.
 *
 * ---------------------------------------------------------------------------
 * WHY A POST TYPE, AND NOT USER META OR A CUSTOM TABLE
 * ---------------------------------------------------------------------------
 *
 * A wholesale application is user meta because a user holds AT MOST ONE of
 * them (@see \FluentCartBulkOrder\Wholesale\ApplicationStore). A quote is the
 * opposite: one buyer may have twenty, each with its own reference, its own
 * prices and its own decision. Packing a growing list of priced line sets into
 * one serialized user meta row would make every review-screen page load read
 * every quote that buyer ever sent.
 *
 * A custom table was the other candidate and was rejected for the same reasons
 * ApplicationStore rejects it: a schema, a dbDelta migration and a version
 * option to maintain, all of which are review surface for the WordPress.org
 * submission. A post type needs none of that and brings what this feature
 * actually needs for free:
 *
 *   - `post_author` IS the buyer. WordPress' own user-deletion flow already
 *     asks an admin what to do with a deleted user's posts, so there is no
 *     orphan-row problem to invent an answer for.
 *   - `post_date` is an indexed, sortable submission time, so "newest first"
 *     is an ordinary WP_Query rather than a sort over serialized arrays.
 *   - Paging, counting and status filtering are WP_Query, which is code
 *     nobody has to write or test.
 *
 * The type is `public => false` and `show_ui => false`: it has no archive, no
 * single view and no wp-admin list table. Every screen a human sees is
 * \FluentCartBulkOrder\Quotes\QuoteReviewScreen, which is capability-checked.
 * A quote carries a buyer's prices, so a URL that renders one on the front end
 * would be a trade-pricing leak.
 *
 * ---------------------------------------------------------------------------
 * WHY TWO KEYS FOR ONE RECORD
 * ---------------------------------------------------------------------------
 *
 * META_RECORD holds everything, serialized. Convenient to read, useless to
 * query — you cannot ask MySQL for "every quote whose serialized array has
 * status requested" without a LIKE over the whole meta table, which is both
 * slow and wrong (it would match a product named "requested").
 *
 * META_STATUS therefore holds the one field the admin screen queries on, as a
 * plain string, so the review screen is an ordinary indexed `meta_query`.
 *
 * THE STATUS IS NOT DUPLICATED INTO THE RECORD. That is the important half.
 * get() reads it from META_STATUS and writes it onto the array it returns, but
 * nothing ever stores it there, so there is only one authority and nothing to
 * keep in step. This is the same split ApplicationStore uses, for the same
 * reason: the version that stored it twice let a lost write leave a record
 * saying `quoted` while the query key still said `requested`.
 *
 * The post_status is deliberately NOT the quote status. Custom post statuses
 * would have to be registered before any query could use them, which couples
 * every read to `init` having run, and WordPress attaches meanings to the
 * built-in ones (`draft`, `pending`) that have nothing to do with a quote.
 *
 * ---------------------------------------------------------------------------
 * TIMESTAMPS ARE UTC
 * ---------------------------------------------------------------------------
 *
 * Stored as `time()` — a Unix timestamp, which is UTC by definition — and never
 * as a site-local string. Formatting for a human is the render site's job,
 * through `wp_date()`, which applies the site's timezone at display time.
 */
class QuoteStore
{
    /**
     * The post type every quote is stored as.
     *
     * RENAMING THIS ALSO MEANS EDITING
     * \FluentCartBulkOrder\Deactivator::removeQuotes(), which repeats the
     * literal because uninstall.php must not load this class.
     */
    const POST_TYPE = 'fcbo_quote';

    /**
     * The whole record, as one serialized array.
     *
     * Underscore-prefixed so WordPress treats it as protected meta. The post
     * type has no editor to expose it in, but the prefix also keeps it out of
     * the REST posts collection for any site that later registers one.
     */
    const META_RECORD = '_fcbo_quote';

    /**
     * The record's status, duplicated for querying. @see the class docblock.
     */
    const META_STATUS = '_fcbo_quote_status';

    /**
     * The shape of a record, and the default for every key.
     *
     * Read through get(), which merges a stored record over this — so a record
     * written by an older version is missing keys, not broken.
     *
     * @var array<string, mixed>
     */
    const RECORD_DEFAULTS = [
        // No `status` here on purpose — @see the class docblock. get() adds one
        // to what it returns, read from META_STATUS, but nothing stores it.
        'lines'         => [],
        'buyer_note'    => '',
        'owner_note'    => '',
        'requested_at'  => 0,
        'decided_at'    => 0,
        'reviewer_id'   => 0,
        'order_id'      => 0,
    ];

    /**
     * Register the post type. Hooked to `init` by QuoteFlow.
     *
     * No rewrite rules and no archive, so nothing here needs a flush on
     * activation — which is why \FluentCartBulkOrder\Activator says nothing
     * about quotes.
     *
     * @return void
     */
    public static function registerPostType()
    {
        register_post_type(self::POST_TYPE, [
            'labels'              => [
                'name'          => __('Quote Requests', 'fluent-cart-bulk-order'),
                'singular_name' => __('Quote Request', 'fluent-cart-bulk-order'),
            ],
            // Every one of these is a refusal, and each matters. `public` off
            // takes away the front-end single view and the archive; the three
            // that follow are set explicitly rather than left to inherit from
            // it, because a future WordPress default flipping would otherwise
            // publish a buyer's prices.
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'hierarchical'        => false,
            'supports'            => ['title', 'author'],
            // `do_not_allow` for every capability: nothing outside this plugin
            // may create, edit or delete a quote through the ordinary post
            // screens, because there are no ordinary post screens for it. The
            // review screen checks `manage_options` for itself.
            'capabilities'        => [
                'create_posts'       => 'do_not_allow',
                'edit_post'          => 'do_not_allow',
                'edit_posts'         => 'do_not_allow',
                'edit_others_posts'  => 'do_not_allow',
                'publish_posts'      => 'do_not_allow',
                'read_post'          => 'do_not_allow',
                'delete_post'        => 'do_not_allow',
                'delete_posts'       => 'do_not_allow',
            ],
            'map_meta_cap'        => false,
        ]);
    }

    /**
     * One quote record, always in RECORD_DEFAULTS' shape.
     *
     * @param int $quoteId
     * @return array<string, mixed>|null Null when there is no such quote.
     */
    public static function get($quoteId)
    {
        $quoteId = (int) $quoteId;

        if ($quoteId <= 0) {
            return null;
        }

        $post = get_post($quoteId);

        // The post type check is not decoration. Every id on the review screen
        // arrives in a POST body, and without this a crafted request could aim
        // a decision at an ordinary page — writing our meta onto it and, worse,
        // reading someone else's post as a quote.
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return null;
        }

        $stored = get_post_meta($quoteId, self::META_RECORD, true);
        $record = array_merge(self::RECORD_DEFAULTS, is_array($stored) ? $stored : []);

        // The status is READ FROM META_STATUS, never from the stored array —
        // even if an older row happens to carry one. There is one authority and
        // this is it. @see the class docblock.
        $record['status']   = self::statusFor($quoteId);
        $record['id']       = $quoteId;
        $record['user_id']  = (int) $post->post_author;
        $record['lines']    = is_array($record['lines']) ? $record['lines'] : [];

        if (!$record['requested_at']) {
            $record['requested_at'] = (int) get_post_time('U', true, $post);
        }

        return $record;
    }

    /**
     * One quote's status. The single authority, and the only place it is read.
     *
     * No fallback to the record, deliberately: a fallback would be a second
     * source of truth, which is the thing this design exists to avoid.
     *
     * @param int $quoteId
     * @return string A QuoteStatus constant.
     */
    public static function statusFor($quoteId)
    {
        $quoteId = (int) $quoteId;

        if ($quoteId <= 0) {
            return QuoteStatus::NONE;
        }

        return QuoteStatus::normalize(get_post_meta($quoteId, self::META_STATUS, true));
    }

    /**
     * Store a buyer's new request.
     *
     * The status is NOT a parameter. It comes from
     * QuoteStatus::statusAfterRequest(), which always returns REQUESTED —
     * there is deliberately no way for a caller on the front-end path to name
     * the status it wants. @see QuoteStatus for the full reasoning.
     *
     * @param int                              $userId
     * @param array<int, array<string, mixed>> $lines Built by
     *                                                QuoteInput::buildLines().
     * @param string                           $note  Sanitised buyer note.
     * @return array<string, mixed>|null The stored record, or null on failure.
     */
    public static function create($userId, $lines, $note = '')
    {
        $userId = (int) $userId;

        if ($userId <= 0 || !is_array($lines) || !$lines) {
            return null;
        }

        $now = time();

        // The title is a fixed, UNTRANSLATED marker for anyone reading the
        // posts table directly. The reference a human sees comes from
        // reference(), which is built from the id and translated at render
        // time — storing a translated title would freeze one locale into the
        // database for a record that outlives the admin who created it.
        //
        // `wp_slash` because wp_insert_post() unslashes what it is given; the
        // roundtrip has to be symmetrical.
        $quoteId = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'publish',
            'post_author' => $userId,
            'post_title'  => wp_slash('FCBO quote request'),
        ], true);

        if (is_wp_error($quoteId) || !$quoteId) {
            return null;
        }

        $quoteId = (int) $quoteId;

        $record = [
            'lines'        => $lines,
            'buyer_note'   => (string) $note,
            'owner_note'   => '',
            'requested_at' => $now,
            'decided_at'   => 0,
            'reviewer_id'  => 0,
            'order_id'     => 0,
        ];

        self::write($quoteId, $record, QuoteStatus::statusAfterRequest());

        $record = self::get($quoteId);

        /**
         * Fires after a quote request is stored, before the buyer is answered.
         *
         * The seam the admin notification hangs off, so it does not have to be
         * called from the REST handler.
         *
         * @param int                  $quoteId
         * @param array<string, mixed> $record
         */
        do_action('fcbo/quotes/requested', $quoteId, $record);

        return $record;
    }

    /**
     * Record an owner's decision on a quote.
     *
     * ---------------------------------------------------------------------------
     * THIS METHOD DOES NOT AUTHORISE ANYTHING
     * ---------------------------------------------------------------------------
     *
     * It checks that the TRANSITION is legal. It does NOT check that the caller
     * is an administrator or that a nonce was present; those belong to the
     * request handler, which is the only place that knows about a request.
     * @see \FluentCartBulkOrder\Quotes\QuoteReviewScreen::handleDecision()
     *
     * Do not call this from anywhere that has not already done both checks.
     *
     * ---------------------------------------------------------------------------
     * WHY THE SIDE EFFECT RUNS INSIDE THE CLAIM
     * ---------------------------------------------------------------------------
     *
     * Converting a quote creates a FluentCart order, which must happen exactly
     * once. Doing it BEFORE the claim means two overlapping requests both create
     * one; doing it AFTER the record is written means a failure leaves a quote
     * marked accepted with no order behind it, in a state no screen can act on.
     *
     * So the caller passes the work as $prepare. It runs once the claim is won
     * and before anything is written, and a WP_Error from it hands the claim
     * back — leaving the quote exactly where the owner found it.
     *
     * @param int           $quoteId
     * @param string        $status     A QuoteStatus constant.
     * @param int           $reviewerId The deciding admin's user id.
     * @param array         $changes    Extra record keys to write with the
     *                                  decision — `lines` for a pricing,
     *                                  `owner_note`, `order_id`.
     * @param callable|null $prepare    Optional. Receives the claimed record and
     *                                  returns either an array of further
     *                                  changes or a WP_Error to abort.
     * @return array<string, mixed>|\WP_Error|null The updated record, a WP_Error
     *                                   from $prepare, or null when the
     *                                   transition was refused.
     */
    public static function decide($quoteId, $status, $reviewerId, array $changes = [], $prepare = null)
    {
        $quoteId = (int) $quoteId;

        // The transition is judged on META_STATUS, NOT on the record's own copy
        // of it — @see the class docblock and statusFor(). Gating on the query
        // key makes a half-written record self-healing on a retry rather than
        // permanently undecidable.
        $current = self::statusFor($quoteId);

        if ($quoteId <= 0 || !QuoteStatus::canTransition($current, $status)) {
            return null;
        }

        // CLAIM the decision before acting on it. canTransition() above read the
        // status a moment ago; two overlapping requests — a double-clicked
        // Convert, or two admins deciding at the same instant — both read
        // `quoted` and both pass. Without a claim they would both create an
        // order and both email the buyer.
        if (!self::claim($quoteId, $current, $status)) {
            return null;
        }

        // Past this line the decision is exclusively ours, and only now is it
        // safe to read the record: read before the claim, it could be a copy
        // from before a concurrent write landed.
        $record = self::get($quoteId);

        if (!$record) {
            self::releaseClaim($quoteId, $current);

            return null;
        }

        if (is_callable($prepare)) {
            $extra = call_user_func($prepare, $record);

            if (is_wp_error($extra)) {
                // Nothing has been written yet, so handing the claim back really
                // does undo the whole decision. @see the docblock.
                self::releaseClaim($quoteId, $current);

                return $extra;
            }

            if (is_array($extra)) {
                $changes = array_merge($changes, $extra);
            }
        }

        if (isset($changes['lines']) && is_array($changes['lines'])) {
            $record['lines'] = $changes['lines'];
        }

        if (isset($changes['owner_note'])) {
            $record['owner_note'] = (string) $changes['owner_note'];
        }

        if (isset($changes['order_id'])) {
            $record['order_id'] = (int) $changes['order_id'];
        }

        $record['status']      = $status;
        $record['decided_at']  = time();
        $record['reviewer_id'] = (int) $reviewerId;

        // The claim owns the status row, so only the record is written here.
        self::write($quoteId, $record);

        $record = self::get($quoteId);

        /**
         * Fires after a quote is decided. Where the buyer's email hangs off.
         *
         * @param int                  $quoteId
         * @param array<string, mixed> $record
         * @param string               $status The new status.
         */
        do_action('fcbo/quotes/decided', $quoteId, $record, $status);

        return $record;
    }

    /**
     * The reference a human uses for a quote.
     *
     * The post id, prefixed. Not a random token: this string is printed on an
     * admin screen and in the buyer's email, and both sides need to be able to
     * say it out loud on a phone call. There is nothing secret in it — every
     * screen that shows a quote checks ownership or capability first, so the
     * reference is a label, never a key.
     *
     * @param int $quoteId
     * @return string
     */
    public static function reference($quoteId)
    {
        return sprintf(
            /* translators: %d: the quote's numeric reference. */
            __('Quote #%d', 'fluent-cart-bulk-order'),
            (int) $quoteId
        );
    }

    /**
     * How many quotes are sitting in one status.
     *
     * Used for the count bubble on the admin menu, so it runs on every admin
     * page load. `fields => ids` with `no_found_rows => false` keeps it to the
     * count query plus a thin id list rather than hydrating every WP_Post.
     *
     * @param string $status
     * @return int
     */
    public static function countByStatus($status)
    {
        if (!QuoteStatus::isStorable($status)) {
            return 0;
        }

        $query = new \WP_Query([
            'post_type'              => self::POST_TYPE,
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => 1,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- META_STATUS exists precisely so this is an indexed lookup rather than a LIKE over the serialized record. @see the class docblock.
            'meta_key'               => self::META_STATUS,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value'             => $status,
        ]);

        return (int) $query->found_posts;
    }

    /**
     * One page of quotes, newest request first.
     *
     * @param string $status  A QuoteStatus constant, or '' for every quote.
     * @param int    $page    1-based.
     * @param int    $perPage
     * @return array{quotes: \WP_Post[], total: int}
     */
    public static function page($status, $page = 1, $perPage = 20)
    {
        $page    = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);

        $args = [
            'post_type'              => self::POST_TYPE,
            'post_status'            => 'publish',
            'posts_per_page'         => $perPage,
            'paged'                  => $page,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'ignore_sticky_posts'    => true,
            'update_post_term_cache' => false,
        ];

        if (QuoteStatus::isStorable($status)) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            $args['meta_key']   = self::META_STATUS;
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $args['meta_value'] = $status;
        }

        $query = new \WP_Query($args);

        return [
            'quotes' => (array) $query->posts,
            'total'  => (int) $query->found_posts,
        ];
    }

    /**
     * How many open quotes one buyer already has.
     *
     * The rate limit behind the request endpoint. Submitting is something any
     * permitted user may do, and a quote costs the store an email and a row, so
     * one buyer must not be able to fill the review screen from a loop.
     *
     * @param int $userId
     * @return int
     */
    public static function openCountFor($userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return 0;
        }

        $query = new \WP_Query([
            'post_type'              => self::POST_TYPE,
            'post_status'            => 'publish',
            'author'                 => $userId,
            'fields'                 => 'ids',
            'posts_per_page'         => 1,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- META_STATUS exists precisely so this is an indexed lookup rather than a LIKE over the serialized record. @see the class docblock.
            'meta_query'             => [
                [
                    'key'     => self::META_STATUS,
                    'value'   => [QuoteStatus::REQUESTED, QuoteStatus::QUOTED],
                    'compare' => 'IN',
                ],
            ],
        ]);

        return (int) $query->found_posts;
    }

    /**
     * The WordPress sanitisers QuoteInput should use.
     *
     * Lives here rather than at each call site so the pure validator is handed
     * the same one everywhere. @see QuoteInput for why it is injected rather
     * than called directly.
     *
     * @return array{0: callable} [textarea]
     */
    public static function sanitizers()
    {
        return ['sanitize_textarea_field'];
    }

    /**
     * Take exclusive ownership of a quote's next decision.
     *
     * ---------------------------------------------------------------------------
     * WHY A CONDITIONAL UPDATE AND NOT A LOCK
     * ---------------------------------------------------------------------------
     *
     * The obvious mutex — write a lock option, do the work, delete it — has a
     * failure mode this cannot afford: a request that dies between the two
     * leaves a lock nothing ever clears, and the quote becomes permanently
     * undecidable. There is no safe timeout to pick either, because the work
     * here sends mail and creates an order.
     *
     * A single conditional UPDATE has no such state. `WHERE meta_value = $from`
     * means the database itself picks one winner: exactly one concurrent
     * statement matches the row, the rest affect zero rows and back out.
     *
     * This is the one place this feature writes post meta with $wpdb rather than
     * update_post_meta(), because update_post_meta() cannot express "only if it
     * still says requested". The object cache is cleared by hand for that reason.
     *
     * @param int    $quoteId
     * @param string $from The status read a moment ago.
     * @param string $to   The decided status to claim the row for.
     * @return bool True for the one caller that won the claim.
     */
    private static function claim($quoteId, $from, $to)
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery -- see the docblock: a conditional UPDATE is the atomic claim, and no meta API expresses one.
        $claimed = $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => $to],
            [
                'post_id'    => $quoteId,
                'meta_key'   => self::META_STATUS,
                'meta_value' => $from,
            ],
            ['%s'],
            ['%d', '%s', '%s']
        );

        // 0 means another request already moved the row; false means the query
        // failed. Neither is a claim. A count ABOVE one means duplicate status
        // rows for the quote, which is still an exclusive win — refusing it
        // would make that quote permanently undecidable.
        if (!$claimed) {
            return false;
        }

        // $wpdb wrote behind update_post_meta()'s back, so the cached copy is
        // now stale. Without this, statusFor() in the same request would still
        // read the old value.
        wp_cache_delete($quoteId, 'post_meta');
        // phpcs:enable

        return true;
    }

    /**
     * Hand a claim back, leaving the quote where it was for another try.
     *
     * @param int    $quoteId
     * @param string $to The status to restore.
     * @return void
     */
    public static function releaseClaim($quoteId, $to)
    {
        if (!QuoteStatus::isStorable($to)) {
            return;
        }

        update_post_meta((int) $quoteId, self::META_STATUS, $to);
    }

    /**
     * Write the record, and optionally the status. The ONLY place either key is
     * written outside the claim.
     *
     * @see the class docblock for why the status is not stored in the record.
     *
     * @param int                  $quoteId
     * @param array<string, mixed> $record
     * @param string|null          $status Status to write, or null when the
     *                                     caller already owns the status row
     *                                     through a claim.
     * @return void
     */
    private static function write($quoteId, array $record, $status = null)
    {
        // The status never travels inside the record. @see the class docblock:
        // one authority, so there is nothing to keep in step. `id` and `user_id`
        // go the same way — both are columns on the post, and a stale copy in
        // the meta row could disagree with the row it describes.
        unset($record['status'], $record['id'], $record['user_id']);

        update_post_meta($quoteId, self::META_RECORD, wp_slash($record));

        // Only the path that has NOT already claimed the row passes a status.
        if ($status !== null) {
            update_post_meta($quoteId, self::META_STATUS, $status);
        }
    }

    /**
     * Remove a quote entirely.
     *
     * Not reachable from any screen — it exists for uninstall-adjacent tooling
     * and for tests, which need a way back to a clean state. Deleting a quote
     * does NOT touch an order it was converted into: that order is FluentCart's
     * now, and a store's sales history is not ours to edit.
     *
     * @param int $quoteId
     * @return void
     */
    public static function delete($quoteId)
    {
        $quoteId = (int) $quoteId;

        if ($quoteId <= 0 || !self::get($quoteId)) {
            return;
        }

        wp_delete_post($quoteId, true);
    }
}
