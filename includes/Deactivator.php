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
     *   - No rewrite rules, cron events, custom tables or transients are
     *     registered, so there is nothing to flush or unschedule either. (If a
     *     future change adds any of those, unscheduling belongs HERE, not in
     *     uninstall — a deactivated plugin must not leave cron jobs behind.)
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
     *   REMOVED  the `wholesale-customer` role definition
     *   KEPT     `fcbo_bulk_pricing` post meta — the per-product tier tables a
     *            store owner may have spent hours entering. Reinstalling the
     *            plugin picks them straight back up.
     *   KEPT     `fcbo_saved_lists` user meta — customers' own saved order
     *            lists. Deleting another person's data on an admin's uninstall
     *            click is not ours to do.
     *
     * Users who hold `wholesale-customer` keep the assignment in their user
     * meta. WordPress treats an unknown role as no capabilities, so they lose
     * wholesale access (correct — the plugin is gone) without being edited, and
     * reinstalling restores them exactly.
     *
     * NOTE — single site only. `delete_option()` and `remove_role()` act on the
     * current site, so on a multisite network a network-wide delete cleans up
     * only the site that ran it. Left as-is rather than half-implemented: doing
     * it properly means iterating sites with switch_to_blog(), which needs
     * testing on a real network.
     *
     * @return void
     */
    public static function uninstall()
    {
        delete_option(AccessPolicy::OPTION_BULK_PRICING_ROLES);
        delete_option(AccessPolicy::OPTION_MIN_ORDER_TOTAL);
        delete_option(AccessPolicy::OPTION_MIN_ORDER_TOTAL_ROLES);

        remove_role(AccessPolicy::WHOLESALE_ROLE);
    }
}
