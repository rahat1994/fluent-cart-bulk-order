<?php

namespace FluentCartBulkOrder;

defined('ABSPATH') || exit;

/**
 * Teardown — both halves of it, on purpose.
 *
 * ---------------------------------------------------------------------------
 * DEACTIVATE vs DELETE — the distinction this class exists to keep straight
 * ---------------------------------------------------------------------------
 *
 * WordPress gives a plugin two very different teardown moments, and treating
 * them as one is the classic way to destroy a store's data:
 *
 *   DEACTIVATE — "turn it off for now". Extremely common as a debugging step
 *       ("deactivate every plugin, then activate them one by one"). The user
 *       expects to flip it back on and find their setup intact. Therefore
 *       deactivate() removes NOTHING.
 *
 *   DELETE / UNINSTALL — "I am done with this plugin". Only then may stored
 *       data go away, and only the data that is ours to remove.
 *
 * Both live in one class because they are one decision: what we keep and what
 * we drop. Splitting them across two files is how the two lists drift apart.
 *
 * @see \FluentCartBulkOrder\Activator The matching setup half.
 */
class Deactivator
{
    /**
     * Runs on plugin deactivation. Deliberately does nothing.
     *
     * Not an oversight, and not a stub waiting to be filled — this is the
     * behavior. Nothing this plugin creates needs unwinding when it is switched
     * off:
     *
     *   - Settings (the three `fcbo_*` options) must survive, so reactivating
     *     restores the store's pricing policy instead of silently resetting it
     *     to "bulk pricing for everyone".
     *   - The `wholesale-customer` role must survive. Removing it would strip
     *     the role from every user holding it, and re-adding it on reactivation
     *     would NOT give those users their role back.
     *   - Product tier meta and users' saved lists are store content, not
     *     plugin scaffolding.
     *   - The analytics attribution table must survive, for exactly the same
     *     reason the settings do. It is a record of orders the store has
     *     already taken, it cannot be rebuilt once dropped — nothing else
     *     anywhere knows which tier priced a line — and a debugging
     *     deactivate-and-reactivate must not silently erase a store's whole
     *     reporting history. Dropping it belongs in uninstall(), and it is
     *     there. @see \FluentCartBulkOrder\Analytics\AttributionStore
     *   - No rewrite rules or cron events are registered, so there is nothing
     *     to flush or unschedule either. (If a future change adds either,
     *     unscheduling belongs HERE, not in uninstall — a deactivated plugin
     *     must not leave cron jobs behind.)
     *   - The transients the plugin sets all expire on their own and need no
     *     teardown: `fcbo_wholesale_feedback_{user_id}` lives 60 seconds and
     *     is read-and-deleted on the next page view, and
     *     `fcbo_wholesale_notified_{user_id}` and the four
     *     `fcbo_analytics_{period}` report caches live 15 minutes, and the two
     *     `fcbo_menu_count_*` admin-menu bubbles live 5. Core's
     *     `delete_expired_transients()` sweep collects anything left by a
     *     visitor who never came back. A LONGER-LIVED transient added later
     *     would need deleting, and that too belongs HERE.
     *
     * The hook is still wired in the main plugin file so this reasoning has a
     * home, and so the next person adding cron or rewrite rules has an obvious
     * place to put the cleanup.
     *
     * @return void
     */
    public static function deactivate()
    {
        // Intentionally empty. See the docblock above before adding anything.
    }

    /**
     * Runs when the user DELETES the plugin. Called from uninstall.php.
     *
     * Scope is deliberately narrow — plugin settings and the role we created,
     * nothing else:
     *
     *   REMOVED  the three `fcbo_*` options (Gate 2 + Gate 3 policy)
     *   REMOVED  `fcbo_store_defaults` (every other setting, including the
     *            wholesale application's questions, its FluentCRM tag ids, and
     *            the purchase-order field's mode and role list)
     *   REMOVED  the `wholesale-customer` role definition
     *   REMOVED  the two wholesale application user meta keys, for every user.
     *            @see removeWholesaleApplications() for why these are
     *            scaffolding rather than customer content.
     *   REMOVED  every `fcbo_quote` post and its meta. @see removeQuotes().
     *   REMOVED  the analytics attribution table and its schema-version
     *            option. @see removeAnalytics() for why this one goes while
     *            the PO numbers below stay.
     *   KEPT     `fcbo_bulk_pricing` post meta — the per-product tier tables a
     *            store owner may have spent hours entering. Reinstalling the
     *            plugin picks them straight back up.
     *   KEPT     `fcbo_saved_lists` user meta — customers' own saved order
     *            lists. Deleting another person's data on an admin's uninstall
     *            click is not ours to do.
     *   KEPT     `fcbo_po_number` rows in FluentCart's `fct_order_meta` table.
     *            A purchase-order number is part of a completed sale: it is the
     *            reference the buyer's accounts department paid against, and it
     *            sits in the HOST's table beside the order it belongs to. This
     *            plugin put it there, but deleting it would edit the store's own
     *            accounting record — the same line that keeps a converted
     *            quote's FluentCart order. Reinstalling reads the existing
     *            values straight back.
     *   KEPT     Anything inside FluentCRM. The tags an owner pointed us at are
     *            their tags, in their CRM, and the contacts we tagged are
     *            contacts they already had. This plugin never created a tag
     *            precisely so that uninstall has nothing to argue about.
     *
     * Users who hold `wholesale-customer` keep the assignment in their user
     * meta. WordPress treats an unknown role as no capabilities, so they lose
     * wholesale access (correct — the plugin is gone) without being edited, and
     * reinstalling restores them exactly.
     *
     * That is also why deleting the APPLICATION records below does not touch
     * the role: the record is our paperwork, the role assignment is the user's.
     *
     * On multisite the per-site cleanup runs once per site in the network — see
     * removeSiteData() and eachSiteId() below for why that loop is necessary and
     * what it assumes. The user meta is network-wide, so it is removed once,
     * before the loop rather than inside it.
     *
     * @return void
     */
    public static function uninstall()
    {
        // ONCE, and outside the loop. `wp_usermeta` is one table for the whole
        // network and these keys are not site-prefixed, so the first pass
        // already removes them everywhere. Calling it per site would re-scan
        // the biggest table on the install for every site in the network and
        // delete nothing after the first — and eachSiteId() already warns that
        // a large network's real risk here is max_execution_time.
        self::removeWholesaleApplications();

        if (!is_multisite()) {
            self::removeSiteData();

            return;
        }

        foreach (self::eachSiteId() as $siteId) {
            switch_to_blog($siteId);
            self::removeSiteData();
            restore_current_blog();
        }
    }

    /**
     * Delete this plugin's data for ONE site — whichever site is current.
     *
     * Every call is per-site by nature, which is the whole reason uninstall()
     * has to loop on a network:
     *
     *   - `delete_option()` writes to the current site's options table.
     *   - `remove_role()` edits the current site's `{prefix}user_roles` option.
     *
     * The wholesale application meta is deliberately NOT here. It is
     * network-wide by nature, so uninstall() removes it once, before the loop.
     * @see removeWholesaleApplications()
     *
     * Safe to call on a site that never activated the plugin, and safe to call
     * twice: delete_option() on a missing option and remove_role() on a missing
     * role both no-op. That idempotency is what makes a timed-out delete
     * recoverable — see eachSiteId().
     *
     * @return void
     */
    private static function removeSiteData()
    {
        delete_option(AccessPolicy::OPTION_BULK_PRICING_ROLES);
        delete_option(AccessPolicy::OPTION_MIN_ORDER_TOTAL);
        delete_option(AccessPolicy::OPTION_MIN_ORDER_TOTAL_ROLES);

        // Every setting that is not one of the three role gates, including the
        // wholesale application's questions and its FluentCRM tag ids. It was
        // missing from this list before the wholesale flow existed, which left
        // a `fcbo_store_defaults` row behind on every uninstall.
        delete_option(StoreDefaults::OPTION);

        remove_role(AccessPolicy::WHOLESALE_ROLE);

        // Quotes are posts, and posts are per-site, so this belongs INSIDE the
        // per-site loop rather than beside the network-wide user meta above.
        self::removeQuotes();

        // The attribution table is named with `$wpdb->prefix`, which is the
        // CURRENT site's prefix — so this too is per-site and belongs in here.
        self::removeAnalytics();
    }

    /**
     * Drop the analytics attribution table for the current site.
     *
     * ---------------------------------------------------------------------------
     * WHY THIS GOES AND THE PO NUMBERS STAY
     * ---------------------------------------------------------------------------
     *
     * A PO number lives in FluentCart's OWN table, beside the order, and it is
     * part of a completed sale — the reference the buyer's accounts department
     * paid against. Deleting it would edit the store's accounting record.
     *
     * An attribution is the opposite on both counts. It lives in a table this
     * plugin created, in a shape only this plugin can read, and it is not a
     * fact about the sale — it is this plugin's note about its own part in the
     * sale. With the plugin gone, nothing will ever read it and nothing will
     * ever offer to clean it up, so leaving it behind means an orphan table on
     * the store's database forever.
     *
     * The orders themselves are untouched, which means nothing about the sale
     * is lost. Only the note about which tier priced it, which had no meaning
     * without the tier.
     *
     * The report transients are deleted alongside, so a reinstall does not read
     * a cached figure computed from a table that no longer exists. They would
     * expire on their own within fifteen minutes; deleting them here costs four
     * calls and removes the window entirely.
     *
     * The class is required lazily, from inside the method. uninstall.php
     * deliberately loads as little as it can, and neither of these two classes
     * touches FluentCart at include time.
     *
     * @return void
     */
    private static function removeAnalytics()
    {
        require_once __DIR__ . '/Analytics/Period.php';
        require_once __DIR__ . '/Analytics/AttributionStore.php';
        require_once __DIR__ . '/Analytics/Reports.php';

        \FluentCartBulkOrder\Analytics\Reports::flushCache();
        \FluentCartBulkOrder\Analytics\AttributionStore::drop();
    }

    /**
     * Delete every quote request stored on the current site.
     *
     * ---------------------------------------------------------------------------
     * WHY THIS GOES AND `fcbo_saved_lists` STAYS
     * ---------------------------------------------------------------------------
     *
     * Same line as the wholesale applications. A saved order is something a
     * CUSTOMER made for themselves. A quote is this plugin's paperwork about a
     * negotiation an OWNER conducted, in a post type nothing else can read: with
     * the plugin gone the type is never registered again, so the rows become
     * invisible in wp-admin and no screen will ever offer to clean them up.
     *
     * What does NOT go is any FluentCart order a quote was converted into. That
     * order is the store's sales history, it lives in FluentCart's own tables,
     * and it is not ours to delete.
     *
     * `get_posts()` in batches with `fields => ids`, and `wp_delete_post()` with
     * force so nothing lands in the trash for a plugin that is being removed.
     * wp_delete_post() also removes the post's meta, which is why the two meta
     * keys are not deleted separately.
     *
     * The post type slug is hardcoded rather than read from QuoteStore. That
     * class pulls in the whole Quotes namespace, and uninstall.php deliberately
     * loads as little as it can get away with — @see uninstall.php. The trade is
     * one string that must not drift; QuoteStore::POST_TYPE names this method so
     * the next person renaming it finds this.
     *
     * @return void
     */
    private static function removeQuotes()
    {
        // A store with thousands of quotes must not try to load them all at
        // once. Each pass takes the next 200 ids and deletes them, and because
        // deleting removes them from the result set, the offset stays at 0.
        //
        // The pass counter is the loop's only exit guarantee. If a site filters
        // `pre_delete_post` to veto the deletion, the same 200 ids come back
        // forever — an infinite loop inside an uninstall, which the admin sees
        // as a hung Delete button. 500 passes is 100,000 quotes, far beyond any
        // real store, and stopping early only leaves rows behind.
        $passes = 0;

        do {
            $ids = get_posts([
                'post_type'              => 'fcbo_quote',
                'post_status'            => 'any',
                // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- a deliberate delete batch on uninstall, not a front-end query; the loop above explains why the batch is capped at all.
                'posts_per_page'         => 200,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);

            foreach ($ids as $id) {
                wp_delete_post((int) $id, true);
            }

            $passes++;
        } while (count($ids) === 200 && $passes < 500);
    }

    /**
     * Delete every wholesale application record.
     *
     * ---------------------------------------------------------------------------
     * WHY THIS GOES AND `fcbo_saved_lists` STAYS
     * ---------------------------------------------------------------------------
     *
     * A saved order is something a CUSTOMER made for themselves — a basket they
     * assembled and named, useful to them and to nobody else. Deleting it on an
     * admin's uninstall click is not ours to do.
     *
     * An application record is the opposite: it is this plugin's paperwork
     * about a decision an ADMIN made, and it has no meaning without the plugin
     * that reads it. Leaving it behind would put two orphan meta rows on every
     * applicant, invisible in wp-admin (both keys are underscore-prefixed and
     * therefore protected), that nothing will ever clean up.
     *
     * What does NOT go is the role assignment. A user who was approved keeps
     * `wholesale-customer` in their capabilities, exactly like every other user
     * who holds it — see the docblock on uninstall(). Deleting the record does
     * not revoke anything; it deletes the note about when it was granted.
     *
     * `delete_metadata()` with `$delete_all = true` is the one call that
     * removes a meta key for every user in one query, rather than paging
     * through the user table. The `$object_id` and `$meta_value` arguments are
     * ignored when that flag is set, which is why they are 0 and ''.
     *
     * The key names are hardcoded rather than read from ApplicationStore. That
     * class pulls in AccessPolicy and the whole Wholesale namespace, and
     * uninstall.php deliberately loads as little as it can get away with —
     * @see uninstall.php. The trade is two strings that must not drift; the
     * constants on ApplicationStore name this method so the next person
     * renaming one finds it.
     *
     * @return void
     */
    private static function removeWholesaleApplications()
    {
        delete_metadata('user', 0, '_fcbo_wholesale_application', '', true);
        delete_metadata('user', 0, '_fcbo_wholesale_status', '', true);
    }

    /**
     * Every site ID in the network, read in batches.
     *
     * ---------------------------------------------------------------------------
     * WHY BATCHES
     * ---------------------------------------------------------------------------
     *
     * `get_sites()` defaults to `'number' => 100`
     * (wp-includes/class-wp-site-query.php:194), so the obvious one-line version
     * of this loop silently cleans the first 100 sites and leaves the rest
     * behind — passing on a large network while quietly doing nothing for site
     * 101 onward. Paging with an explicit `number`/`offset` avoids that without
     * loading a 20,000-row site list into memory at once.
     *
     * Offset paging is safe here because nothing in this loop creates or deletes
     * sites, so the ordered result set does not shift under us. `orderby` is
     * pinned to make that ordering explicit rather than relying on MySQL.
     *
     * The query defaults leave archived, spam and deleted sites IN the result
     * set (each of those filters is skipped when its query var is `''`), which
     * is what we want — an archived site still has our options in its table.
     *
     * ---------------------------------------------------------------------------
     * WHAT THIS ASSUMES
     * ---------------------------------------------------------------------------
     *
     * That `init` has fired. `switch_to_blog()` does not repoint the roles
     * object itself; core does it on the `switch_blog` action via
     * wp_switch_roles_and_user(), which returns early when `init` has not run
     * (wp-includes/ms-blogs.php:700). Without it, `remove_role()` would keep
     * writing to the ORIGINAL site's role option and remove nothing anywhere
     * else. Every real delete path satisfies this — WordPress calls
     * uninstall_plugin() from delete_plugins() inside an admin request
     * (wp-admin/includes/plugin.php:970), long after `init`.
     *
     * A very large network can still exhaust max_execution_time partway through
     * (four queries per site). That is survivable rather than guarded against:
     * a timeout leaves the plugin files in place, so the admin's retry runs
     * uninstall again, and removeSiteData() is idempotent — repeated runs
     * converge instead of double-deleting.
     *
     * @return \Generator|int[]
     */
    private static function eachSiteId()
    {
        $batchSize = 100;
        $offset = 0;

        do {
            $siteIds = get_sites([
                'fields'                 => 'ids',
                'number'                 => $batchSize,
                'offset'                 => $offset,
                'orderby'                => 'id',
                'order'                  => 'ASC',
                // Nothing here reads site or site-meta objects, so priming those
                // caches for the whole network would be pure overhead.
                'update_site_cache'      => false,
                'update_site_meta_cache' => false,
            ]);

            foreach ($siteIds as $siteId) {
                yield (int) $siteId;
            }

            $offset += $batchSize;
        } while (count($siteIds) === $batchSize);
    }
}
