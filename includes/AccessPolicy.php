<?php

namespace FluentCartBulkOrder;

use FluentCartBulkOrder\Admin\Menu;

defined('ABSPATH') || exit;

/**
 * Every access gate in the plugin, in one place.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR GATES — an overall view
 * ---------------------------------------------------------------------------
 *
 * The plugin has FOUR independent role gates. They are separate on purpose,
 * they use DIFFERENT role lists, and they have DIFFERENT empty-list meanings.
 * Confusing one for another is the single most common source of "why can't my
 * customer see this?" bugs, so the whole set is documented together here.
 *
 *   GATE 1 — SURFACE ACCESS. "May this user open our UI at all?"
 *       Roles from  : self::BASELINE_ROLES, plus the roles stored in
 *                     StoreDefaults `allowed_extra_roles` (Settings > Bulk
 *                     Pricing), plus the `fcbo/allowed_roles` filter, in that
 *                     order. The baseline is merged in CODE, so the option and
 *                     the filter can only widen the set, never shrink it.
 *       Empty means : n/a, the baseline is never empty.
 *       Guards      : [fluent_cart_bulk_order], [fluent_cart_product_table],
 *                     [fluent_cart_saved_orders], and the REST routes
 *                     (/products, /catalog, saved orders).
 *       Entry points: allowedRoles(), currentUserCanAccess(), restPermissionCheck()
 *
 *   GATE 2 — BULK PRICING POLICY. "Does this user get tier prices?"
 *       Roles from  : the `fcbo_apply_to_roles` option (Settings > Bulk Pricing).
 *       Empty means : EVERYONE qualifies (the backward-compatible default).
 *       Guards      : the tier table on single product pages ('display' context)
 *                     and the actual cart discount ('cart' context).
 *       Entry points: bulkPricingRoles(), userQualifiesForBulkPricing()
 *
 *   GATE 3 — MINIMUM ORDER TOTAL. "Must this user spend a minimum?"
 *       Roles from  : the `fcbo_min_order_total_roles` option.
 *       Empty means : NOBODY is subject — the INVERSE of Gate 2. An
 *                     unconfigured minimum must never start blocking retail
 *                     checkouts on upgrade.
 *       Guards      : the checkout backstop (fcbo_validate_checkout_minimum).
 *       Entry points: minOrderTotalRoles(), userSubjectToMinOrder()
 *
 *   GATE 4 — PO NUMBER. "Is this shopper asked for a purchase-order number?"
 *       Roles from  : StoreDefaults `po_roles` (Settings > Purchase Orders).
 *       Empty means : EVERY shopper, guests included — like Gate 2, NOT Gate 3.
 *                     Read the note below before changing that.
 *       Guards      : the checkout field, its server-side backstop, and whether
 *                     the field is drawn at all.
 *       Entry points: poNumberRoles(), userSubjectToPoNumber()
 *
 *       WHY NOT GATE 3'S "EMPTY MEANS NOBODY" RULE. Gate 3 has no on/off
 *       switch of its own: a non-zero amount IS the switch, so its role list
 *       has to carry the "not configured yet" meaning or an upgrade would start
 *       refusing retail checkouts. Gate 4 has an explicit three-state mode that
 *       is OFF by default (@see \FluentCartBulkOrder\Checkout\PoNumber), so
 *       nothing here can fire until an owner deliberately turns it on. That
 *       frees the role list to mean the plainer thing — "no restriction" — and
 *       an owner who wants PO numbers from wholesale buyers only ticks the
 *       roles. The settings page states, in words, who the current combination
 *       actually binds. @see \FluentCartBulkOrder\Settings::renderPoAudienceField()
 *
 * ---------------------------------------------------------------------------
 * HOW THE GATES INTERACT — the traps
 * ---------------------------------------------------------------------------
 *
 *   - Gates 1 and 2 are ANDed on the shortcode surfaces in effect: a user must
 *     pass Gate 1 to see the bulk order form, and Gate 2 to be charged tier
 *     prices in it. Passing one is not passing the other.
 *
 *   - A feed's per-role tier-set (`role_tiers`, chosen by selectRoleTierSet())
 *     is NOT a gate — it only picks WHICH tier list applies once Gate 2 has
 *     already said yes. Targeting a role there that Gate 2 excludes produces
 *     tiers that can never apply. That mismatch is what
 *     rolesOutsidePricingPolicy() detects and the feed UI warns about.
 *
 *   - Gate 2 lets administrators preview tiers ('display') but NOT receive the
 *     discount ('cart'). Deliberate: an admin's real order must reflect the
 *     real policy. Do not "make it consistent".
 *
 * ---------------------------------------------------------------------------
 * COMPATIBILITY
 * ---------------------------------------------------------------------------
 *
 * The `fcbo_*` global functions in fluent-cart-bulk-order.php are now thin
 * delegates to this class. They stay because they are the documented extension
 * surface (docs/solutions, docs/plans) and site snippets may call them. Add new
 * logic here, not there. All three escape-hatch filters still fire from here,
 * so existing customizations keep working unchanged.
 */
class AccessPolicy
{
    // ---- Gate 1: surface access ----------------------------------------

    /**
     * Roles that may open the FCBO surfaces, before filtering.
     *
     * Not removable through the filter — `fcbo/allowed_roles` receives this as
     * its starting value, so a badly written callback can widen the set but the
     * store owner never loses their own access by accident.
     */
    const BASELINE_ROLES = ['administrator', self::WHOLESALE_ROLE];

    /**
     * The role this plugin itself creates, as opposed to the ones WordPress and
     * FluentCart already provide.
     *
     * Lives here, next to the option names, because this class is the single
     * source of truth for the slugs the plugin owns. Activator adds this role,
     * Deactivator removes it on uninstall, and Gate 1 above trusts it.
     */
    const WHOLESALE_ROLE = 'wholesale-customer';

    // ---- Gate 2 + 3: option names (single source of truth) --------------

    /**
     * Gate 2 role list. Empty array = bulk pricing applies to everyone.
     */
    const OPTION_BULK_PRICING_ROLES = 'fcbo_apply_to_roles';

    /**
     * Gate 3 amount, in integer cents. 0 = no minimum.
     */
    const OPTION_MIN_ORDER_TOTAL = 'fcbo_min_order_total';

    /**
     * Gate 3 role list. Empty array = nobody is subject (inverse of Gate 2).
     */
    const OPTION_MIN_ORDER_TOTAL_ROLES = 'fcbo_min_order_total_roles';

    /* =====================================================================
     * GATE 1 — SURFACE ACCESS
     * ===================================================================== */

    /**
     * Roles allowed to use the FCBO surfaces (bulk order form, product table,
     * saved orders, REST routes).
     *
     * @return string[] Role slugs.
     */
    public static function allowedRoles()
    {
        // Baseline first, THEN the stored extras: the baseline is merged in code
        // and never read from the option, so no stored value — however broken —
        // can lock a store owner out of their own surfaces.
        $roles = array_merge(
            self::BASELINE_ROLES,
            (array) StoreDefaults::get('allowed_extra_roles', [])
        );

        // The filter still receives the finished set and still runs last, so
        // existing `fcbo/allowed_roles` customizations compose on top of the
        // settings page rather than fighting it.
        return apply_filters('fcbo/allowed_roles', array_values(array_unique($roles)));
    }

    /**
     * Whether the current user may access FCBO surfaces.
     *
     * @param string[] $extraRoles Additional role slugs (e.g. from a shortcode
     *                             `roles` attribute) that EXTEND the baseline
     *                             for this render. Extra roles can only widen,
     *                             never replace, the security baseline.
     *
     * CAVEAT: extra roles only widen the SHORTCODE/UI gate for ONE placement.
     * restPermissionCheck() passes no extra roles, so the REST routes enforce
     * only the global set from allowedRoles(). A role granted access solely
     * through a per-shortcode `roles` attribute can render the UI but its AJAX
     * product calls will still be rejected.
     *
     * To widen BOTH surfaces and REST, widen the global set instead: check the
     * role under "Who may use the bulk order surfaces" on the settings page, or
     * add it through the `fcbo/allowed_roles` filter. That is the fix for the
     * mismatch above — the per-placement attribute is for the rarer case where
     * one page, and only one page, should be visible to an extra role.
     *
     * @return bool
     */
    public static function currentUserCanAccess($extraRoles = [])
    {
        if (!is_user_logged_in()) {
            return false;
        }

        // Super admins (and single-site administrators) always have access —
        // role-slug checks alone would lock out a multisite super admin who
        // isn't a member of the current subsite.
        if (is_super_admin()) {
            return true;
        }

        $allowed = self::allowedRoles();

        if (!empty($extraRoles)) {
            // Merge onto (never replace) the baseline so admin + wholesale remain.
            $allowed = array_merge($allowed, array_map('sanitize_key', (array) $extraRoles));
        }

        return (bool) array_intersect($allowed, wp_get_current_user()->roles);
    }

    /**
     * REST permission callback for the FCBO endpoints.
     *
     * Mirrors the shortcode access gate: unauthenticated requests get 401,
     * authenticated-but-unauthorized requests get 403.
     *
     * @return true|\WP_Error
     */
    public static function restPermissionCheck()
    {
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'fcbo_rest_unauthorized',
                __('You must be logged in to access this resource.', 'fluent-cart-bulk-order'),
                ['status' => 401]
            );
        }

        if (!self::currentUserCanAccess()) {
            return new \WP_Error(
                'fcbo_rest_forbidden',
                __('You do not have permission to access this resource.', 'fluent-cart-bulk-order'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Parse a comma-separated shortcode `roles` attribute into sanitized slugs.
     *
     * Each token is trimmed and passed through sanitize_key; empty tokens are
     * dropped. A malformed value such as " , ," degrades to an empty array
     * (baseline only).
     *
     * @param string $rolesAttr Raw attribute value.
     * @return string[] Sanitized role slugs.
     */
    public static function parseRolesAttr($rolesAttr)
    {
        if (empty($rolesAttr) || !is_string($rolesAttr)) {
            return [];
        }

        $roles = array_map('sanitize_key', array_map('trim', explode(',', $rolesAttr)));

        return array_values(array_filter($roles));
    }

    /* =====================================================================
     * GATE 2 — BULK PRICING POLICY
     * ===================================================================== */

    /**
     * Role slugs that bulk pricing is restricted to.
     *
     * An empty array means the policy is open to everyone — the default, which
     * preserves the pre-policy behavior on upgrade. Do not change this default.
     *
     * @return string[] Role slugs; empty array = everyone qualifies.
     */
    public static function bulkPricingRoles()
    {
        return (array) get_option(self::OPTION_BULK_PRICING_ROLES, []);
    }

    /**
     * Whether a user qualifies for bulk pricing under the stored role policy.
     *
     * Truth table:
     *   - Empty role list                   => true (everyone; default).
     *   - Context 'display' + administrator  => true (admins can always preview).
     *   - Otherwise                          => true iff the user holds an allowed role.
     *
     * The administrator display exception is intentionally NOT applied on the
     * 'cart' context: an admin's real order must reflect the real policy. Do not
     * "make it consistent" by extending the admin short-circuit to the cart path.
     *
     * The result passes through the `fcbo/user_qualifies_for_bulk_pricing`
     * filter so developers can implement custom logic (e.g. per-customer
     * overrides) without editing core.
     *
     * @param \WP_User|null $user    User to test; defaults to the current user.
     * @param string        $context 'cart' (enforcement) or 'display' (preview).
     * @return bool
     */
    public static function userQualifiesForBulkPricing($user = null, $context = 'cart')
    {
        if ($user === null) {
            $user = wp_get_current_user();
        }

        $roles     = self::bulkPricingRoles();
        $userRoles = isset($user->roles) ? (array) $user->roles : [];

        if (empty($roles)) {
            $qualifies = true;
        } elseif ($context === 'display' && in_array('administrator', $userRoles, true)) {
            $qualifies = true;
        } else {
            $qualifies = (bool) array_intersect($roles, $userRoles);
        }

        return (bool) apply_filters('fcbo/user_qualifies_for_bulk_pricing', $qualifies, $user, $context);
    }

    /* =====================================================================
     * GATE 3 — MINIMUM ORDER TOTAL
     * ===================================================================== */

    /**
     * The configured minimum order total, in integer cents.
     *
     * Zero (the default) means no floor for anyone.
     *
     * @return int Cents; 0 = no minimum.
     */
    public static function minOrderTotal()
    {
        return max(0, (int) get_option(self::OPTION_MIN_ORDER_TOTAL, 0));
    }

    /**
     * Roles the minimum order total applies to.
     *
     * Deliberately a SEPARATE option from the bulk-pricing policy: a store
     * commonly wants to require a minimum of its wholesale buyers without also
     * restricting who receives bulk discounts. Unlike Gate 2, an empty list here
     * means "nobody is subject".
     *
     * @return string[] Role slugs; empty = nobody is subject.
     */
    public static function minOrderTotalRoles()
    {
        return (array) get_option(self::OPTION_MIN_ORDER_TOTAL_ROLES, []);
    }

    /**
     * Whether a user must meet the minimum order total.
     *
     * Mirrors userQualifiesForBulkPricing()'s shape — including the escape-hatch
     * filter — but NOT its empty-list semantics; see minOrderTotalRoles().
     *
     * @param \WP_User|null $user Defaults to the current user.
     * @return bool
     */
    public static function userSubjectToMinOrder($user = null)
    {
        if ($user === null) {
            $user = wp_get_current_user();
        }

        $roles     = self::minOrderTotalRoles();
        $userRoles = isset($user->roles) ? (array) $user->roles : [];
        $subject   = !empty($roles) && (bool) array_intersect($roles, $userRoles);

        return (bool) apply_filters('fcbo/user_subject_to_min_order', $subject, $user);
    }

    /* =====================================================================
     * GATE 4 — PO NUMBER
     * ===================================================================== */

    /**
     * Roles the PO number field applies to.
     *
     * Stored inside the StoreDefaults array rather than as a top-level option,
     * because it is new: the three `fcbo_*` options exist only because they
     * predate that array, and adding a fourth would be copying a compatibility
     * decision as if it were a design one.
     *
     * An empty list means EVERY shopper. @see the Gate 4 note in this class'
     * docblock for why that is Gate 2's rule and not Gate 3's.
     *
     * @return string[] Role slugs; empty = no restriction.
     */
    public static function poNumberRoles()
    {
        return (array) StoreDefaults::get('po_roles', []);
    }

    /**
     * Whether a shopper is asked for a purchase-order number.
     *
     * Answers WHO only. Whether the field is shown at all, and whether an empty
     * one refuses the checkout, is the mode's business —
     * @see \FluentCartBulkOrder\Checkout\PoSettings, which ANDs the two.
     *
     * Note this is the one gate that must give a sensible answer for a
     * logged-out shopper, because a store can take a guest checkout.
     * wp_get_current_user() returns a user with no roles for a guest, so an
     * empty policy includes them and a role-scoped policy does not — which is
     * the right answer both ways round.
     *
     * @param \WP_User|null $user Defaults to the current user.
     * @return bool
     */
    public static function userSubjectToPoNumber($user = null)
    {
        if ($user === null) {
            $user = wp_get_current_user();
        }

        $roles     = self::poNumberRoles();
        $userRoles = isset($user->roles) ? (array) $user->roles : [];
        $subject   = empty($roles) || (bool) array_intersect($roles, $userRoles);

        return (bool) apply_filters('fcbo/user_subject_to_po_number', $subject, $user);
    }

    /* =====================================================================
     * TIER-SET SELECTION (not a gate — runs only after Gate 2 says yes)
     * ===================================================================== */

    /**
     * Pick the applicable tier-set within a resolved feed by the shopper's roles.
     *
     * The first of the shopper's roles that has a role-scoped list wins;
     * otherwise the feed's default `tiers` apply. Passing null/[] roles always
     * yields the default set, so callers that don't know the user get the
     * historical behavior.
     *
     * This is selection, NOT authorization: a role listed here still has to pass
     * Gate 2 before any of it is used. See rolesOutsidePricingPolicy().
     *
     * @param array         $feed      ['tiers' => array, 'role_tiers' => array]
     * @param string[]|null $userRoles Current user's role slugs.
     * @return array Tier list (may be empty).
     */
    public static function selectRoleTierSet($feed, $userRoles)
    {
        $roleTiers = isset($feed['role_tiers']) && is_array($feed['role_tiers']) ? $feed['role_tiers'] : [];

        if (!empty($roleTiers) && !empty($userRoles)) {
            foreach ((array) $userRoles as $role) {
                if (!empty($roleTiers[$role])) {
                    return $roleTiers[$role];
                }
            }
        }

        return isset($feed['tiers']) && is_array($feed['tiers']) ? $feed['tiers'] : [];
    }

    /* =====================================================================
     * CROSS-GATE CONSISTENCY (powers the feed UI warning)
     * ===================================================================== */

    /**
     * Whether a role receives bulk pricing at all under the current policy.
     *
     * The plain question the feed editor needs answered: if this is false, any
     * tier-set the owner writes for that role is dead configuration.
     *
     * Note this ignores the administrator 'display' exception on purpose — an
     * admin outside the policy can PREVIEW tiers but is not charged them, which
     * is a distinct situation the caller should describe differently. Use
     * adminPreviewOnly() to detect it.
     *
     * @param string $role Role slug.
     * @return bool
     */
    public static function roleReceivesBulkPricing($role)
    {
        $policy = self::bulkPricingRoles();

        // Empty policy = open to everyone, so nothing can be excluded.
        if (empty($policy)) {
            return true;
        }

        return in_array(sanitize_key($role), $policy, true);
    }

    /**
     * Whether a role sees tier tables but is never charged tier prices.
     *
     * True only for `administrator` outside a non-empty policy — the deliberate
     * asymmetry in userQualifiesForBulkPricing(). Worth its own warning, because
     * the symptom ("I can see it, so it must work") is actively misleading.
     *
     * @param string $role Role slug.
     * @return bool
     */
    public static function adminPreviewOnly($role)
    {
        return sanitize_key($role) === 'administrator' && !self::roleReceivesBulkPricing('administrator');
    }

    /**
     * Roles a feed targets that the bulk-pricing policy excludes.
     *
     * Returns the mismatch between a feed's `role_tiers` keys and Gate 2. An
     * empty result means every targeted role can actually receive its tiers.
     *
     * @param string[] $targetedRoles Role slugs a feed writes tiers for.
     * @return string[] Slugs whose tiers can never apply.
     */
    public static function rolesOutsidePricingPolicy($targetedRoles)
    {
        $policy = self::bulkPricingRoles();

        if (empty($policy)) {
            return [];
        }

        $outside = [];
        foreach ((array) $targetedRoles as $slug) {
            $slug = sanitize_key($slug);
            if ($slug !== '' && !in_array($slug, $policy, true)) {
                $outside[] = $slug;
            }
        }

        return array_values(array_unique($outside));
    }

    /**
     * Human-readable names of the roles bulk pricing is limited to.
     *
     * @return string Comma-separated role names; '' when the policy is open.
     */
    public static function pricingPolicyLabel()
    {
        $policy = self::bulkPricingRoles();

        if (empty($policy)) {
            return '';
        }

        $roles = self::editableRoles();
        $names = [];
        foreach ($policy as $slug) {
            $names[] = isset($roles[$slug]['name']) ? $roles[$slug]['name'] : $slug;
        }

        return implode(', ', $names);
    }

    /**
     * Admin URL of the page that owns the Gate 2 and Gate 3 settings.
     *
     * @return string
     */
    public static function settingsPageUrl()
    {
        return Menu::url(Settings::PAGE_SLUG);
    }

    /* =====================================================================
     * SHARED ROLE INFRASTRUCTURE
     * ===================================================================== */

    /**
     * Editable role slugs, with the admin include guaranteed.
     *
     * get_editable_roles() lives in wp-admin/includes/user.php, which is NOT
     * loaded on REST/AJAX requests — and integration feed saves run through one.
     * Without this guard the save path can fatal on an undefined function.
     *
     * @return array<string, array> Role slug => role details.
     */
    public static function editableRoles()
    {
        if (!function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        return (array) get_editable_roles();
    }

    /**
     * Sanitize a submitted role list against real, editable role slugs.
     *
     * Unknown/invalid slugs are dropped. A non-array (e.g. nothing submitted)
     * collapses to an empty array.
     *
     * @param mixed $value Raw submitted value.
     * @return string[] Clean, de-duplicated list of valid role slugs.
     */
    public static function sanitizeRoleList($value)
    {
        $value    = is_array($value) ? $value : [];
        $editable = array_keys(self::editableRoles());

        $clean = [];
        foreach ($value as $slug) {
            $slug = sanitize_key($slug);
            if ($slug !== '' && in_array($slug, $editable, true)) {
                $clean[] = $slug;
            }
        }

        return array_values(array_unique($clean));
    }
}
