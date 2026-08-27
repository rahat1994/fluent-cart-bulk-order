<?php

namespace FluentCartBulkOrder\Rest;

use FluentCartBulkOrder\Quotes\QuoteFlow;
use FluentCartBulkOrder\Quotes\QuoteInput;
use FluentCartBulkOrder\Quotes\QuoteSettings;
use FluentCartBulkOrder\Quotes\QuoteStore;

defined('ABSPATH') || exit;

/**
 * The one endpoint a buyer reaches: "quote this basket instead of buying it".
 *
 * ---------------------------------------------------------------------------
 * WHY REST AND NOT admin-post.php
 * ---------------------------------------------------------------------------
 *
 * The wholesale application form is a plain HTML form with no script, so it
 * posts to admin-post.php. This is the opposite: the bulk order form is built
 * entirely by JavaScript, its rows exist only in the DOM, and it already talks
 * to `fcbo/v1/saved-lists` with exactly this payload. A second transport for
 * the same array would be a second thing to keep in step.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS ENDPOINT REFUSES TO TAKE FROM THE REQUEST
 * ---------------------------------------------------------------------------
 *
 *   The buyer.   `post_author` is get_current_user_id(). No user id is accepted,
 *                the same rule the saved-order routes hold to.
 *   The prices.  Every price on the stored record comes from the SERVER's own
 *                catalogue lookup. The browser sends what it displayed, and it
 *                is ignored. @see QuoteInput for why that is the security
 *                property of this feature.
 *   The status.  Comes from QuoteStatus::statusAfterRequest(), which always
 *                returns `requested`.
 *
 * So the widest thing a crafted request can do is ask for products and
 * quantities — which is what the form is for.
 */
class QuotesController
{
    /**
     * How many quotes one buyer may have open at once.
     *
     * The record-side half of the flood defence; QuoteNotifier throttles the
     * email. Ten is far more than a real buyer needs open at one time and far
     * fewer than a loop would create in a second.
     */
    const MAX_OPEN_PER_USER = 10;

    /**
     * REST: POST — turn the current user's filled table into a quote request.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function requestQuote(\WP_REST_Request $request)
    {
        // `dirname(__DIR__)` rather than FCBO_DIR: this file locates its own
        // siblings, exactly as QuoteFlow::load() does. It also means the class
        // can be exercised from a script that never defined the constant.
        require_once dirname(__DIR__) . '/Quotes/QuoteFlow.php';
        QuoteFlow::load();

        // The store-wide switch. The button is hidden when quotes are off, but
        // a hidden button is a UI decision and this is the gate — a form cached
        // from before the owner turned the feature off must not still be able to
        // create records.
        if (!QuoteSettings::enabled()) {
            return new \WP_Error(
                'fcbo_quotes_disabled',
                __('This store is not taking quote requests.', 'fluent-cart-bulk-order'),
                ['status' => 403]
            );
        }

        $userId = get_current_user_id();

        // Gate 1 already ran in the permission callback, so this is belt and
        // braces — but a quote is stored against a user id, and 0 is not one.
        if ($userId <= 0) {
            return new \WP_Error(
                'fcbo_not_logged_in',
                __('You must be logged in to request a quote.', 'fluent-cart-bulk-order'),
                ['status' => 401]
            );
        }

        if (QuoteStore::openCountFor($userId) >= self::MAX_OPEN_PER_USER) {
            return new \WP_Error(
                'fcbo_quotes_too_many',
                __('You already have several quote requests waiting. Please wait for those before sending another.', 'fluent-cart-bulk-order'),
                ['status' => 429]
            );
        }

        $items = QuoteInput::sanitizeItems($request->get_param('items'));

        if (!$items) {
            return new \WP_Error(
                QuoteInput::ERROR_NO_LINES,
                __('Add at least one product before requesting a quote.', 'fluent-cart-bulk-order'),
                ['status' => 400]
            );
        }

        // The SERVER decides what each line is worth. fcbo_resolve_variant_ids()
        // is the same resolver the saved-order surfaces use, so a quote's line
        // snapshot and a saved order's re-priced line come from one place.
        $resolved = fcbo_resolve_variant_ids(array_column($items, 'variantId'));
        $lines    = QuoteInput::buildLines($items, $resolved);

        if (!$lines) {
            return new \WP_Error(
                'fcbo_quotes_unavailable',
                __('None of those products are available any more, so there is nothing to quote.', 'fluent-cart-bulk-order'),
                ['status' => 400]
            );
        }

        $note = QuoteInput::sanitizeNote(
            $request->get_param('note'),
            QuoteStore::sanitizers()[0]
        );

        $record = QuoteStore::create($userId, $lines, $note);

        if (!$record) {
            return new \WP_Error(
                'fcbo_quotes_failed',
                __('Your request could not be saved. Please try again.', 'fluent-cart-bulk-order'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response([
            'reference' => QuoteStore::reference($record['id']),
            'lines'     => count($record['lines']),
            // How many of the buyer's rows did NOT survive the catalogue check.
            // Reported rather than hidden: a buyer who asked for six products
            // and got a quote for five deserves to know before the owner
            // answers one they did not mean.
            'skipped'   => max(0, count($items) - count($record['lines'])),
        ], 201);
    }
}
