<?php

namespace FluentCartBulkOrder;

defined('ABSPATH') || exit;

/**
 * Everything the plugin does when it is ACTIVATED.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A CLASS AND NOT A CLOSURE IN THE MAIN FILE
 * ---------------------------------------------------------------------------
 *
 * Activation work has rules that normal request-time code does not:
 *
 *   - It runs ONCE, in wp-admin, with no FluentCart guarantee. `plugins_loaded`
 *     has already fired by the time the activation hook runs, so the
 *     "is FluentCart active?" check in the main file has NOT protected this
 *     code. Never call a FluentCart class or hook from here.
 *   - It must be idempotent. A user can deactivate and reactivate any number of
 *     times, and every step below has to be safe to repeat.
 *   - It must not be destructive. Teardown lives in \FluentCartBulkOrder\Deactivator.
 *
 * The file is only loaded from inside the activation hook (see
 * fluent-cart-bulk-order.php), so it costs nothing on a normal page load.
 *
 * @see \FluentCartBulkOrder\Deactivator The matching teardown half.
 */
class Activator
{
    /**
     * Single entry point for the `register_activation_hook` callback.
     *
     * Keep this as a list of small, named, idempotent steps — that shape is what
     * makes it safe to add step 2 later without re-reading step 1.
     *
     * @return void
     */
    public static function activate()
    {
        self::registerWholesaleRole();
    }

    /**
     * Create the `wholesale-customer` role if the site does not have it.
     *
     * The role is the whole point of Gate 1: AccessPolicy::BASELINE_ROLES lists
     * it, so without this step the bulk-order surfaces are administrator-only
     * and the store owner has no role to hand to a wholesale buyer.
     *
     * The role is NOT removed on deactivation, and on uninstall it is removed
     * without touching the users who hold it — see Deactivator for why.
     *
     * Capabilities are copied from `customer` (FluentCart's buyer role) and fall
     * back to `subscriber` when FluentCart has not created its role yet, which
     * is the normal case when both plugins are activated in the same request.
     *
     * @return void
     */
    private static function registerWholesaleRole()
    {
        if (get_role(AccessPolicy::WHOLESALE_ROLE)) {
            return;
        }

        // `?:` rather than a ternary on get_role('customer') twice: on a site
        // where neither role exists, the old version dereferenced null and
        // fataled during activation. An empty capability set is a recoverable
        // outcome; a white screen on activate is not.
        $template = get_role('customer') ?: get_role('subscriber');

        add_role(
            AccessPolicy::WHOLESALE_ROLE,
            __('Wholesale Customer', 'fluent-cart-bulk-order'),
            $template ? $template->capabilities : []
        );
    }
}
