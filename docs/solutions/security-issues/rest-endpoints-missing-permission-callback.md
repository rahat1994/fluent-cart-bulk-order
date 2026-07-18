---
title: "Unprotected REST routes leaked catalog and wholesale pricing via __return_true"
date: 2026-07-18
category: security-issues
module: fluent-cart-bulk-order
problem_type: security_issue
component: authentication
symptoms:
  - "GET /wp-json/fcbo/v1/catalog returned 200 with the full product list to logged-out visitors"
  - "GET /wp-json/fcbo/v1/products returned 200 with wholesale discount tiers to anyone"
  - "UI was role-gated to administrators and wholesale customers, but the data behind it was public"
root_cause: missing_permission
resolution_type: code_fix
severity: high
tags: [rest-api, permission-callback, authorization, wordpress, access-control, wholesale-pricing]
related_components: [rest-api, shortcodes]
---

# Unprotected REST routes leaked catalog and wholesale pricing via __return_true

## Problem

The plugin's two custom REST routes (`/fcbo/v1/products`, `/fcbo/v1/catalog`) registered
`permission_callback => '__return_true'`, so anyone — including logged-out visitors — could
read the full product catalog and, on `/products`, the resolved wholesale discount tiers.
The shortcodes that consume these routes were already gated to `administrator` and
`wholesale-customer`, which created a false sense of protection: the *UI* was gated, but the
*data source* behind it was world-readable.

## Symptoms

- `curl http://site.test/wp-json/fcbo/v1/catalog` (no auth) returned `200` with every
  published product, its variants, prices, and stock.
- `curl '.../fcbo/v1/products?search=x'` (no auth) additionally returned `bulk_tiers` —
  the wholesale-only quantity discount data.
- The bulk order form and product table pages correctly showed a permission message to
  non-privileged users, masking the fact that their underlying API was open.

## What Didn't Work

These are the tempting partial fixes that do **not** fully close the hole — recorded so the
next person doesn't stop early:

- **Relying on the shortcode/UI gating.** The shortcodes check roles at render time, but the
  REST routes are independent HTTP endpoints. Gating the UI does nothing for a direct request
  to the route.
- **Only adding `is_user_logged_in()` to the callback.** That stops anonymous access but any
  logged-in `subscriber` (self-registration is common on WordPress) would still read the
  wholesale pricing. The gate has to check *role*, not just authentication.
- **Checking the REST nonce inside the permission callback.** Unnecessary and wrong layer:
  WordPress resolves the current user (cookie+nonce, application password, etc.) *before*
  `permission_callback` runs, so the callback only needs to inspect the resolved user.
- **Gating on role slugs alone.** `wp_get_current_user()->roles` omits a multisite super
  admin who isn't a member of the current subsite, locking them out with a 403 despite full
  network authority. The fix needs an `is_super_admin()` escape hatch.

## Solution

Add a shared, filterable role gate and a REST permission callback, and register it on both
routes. See `fluent-cart-bulk-order.php`.

Before (`fluent-cart-bulk-order.php`, route registration):

```php
register_rest_route('fcbo/v1', '/catalog', [
    'methods'             => 'GET',
    'callback'            => 'fcbo_list_catalog',
    'permission_callback' => '__return_true',   // anyone, including logged-out
    // ...
]);
```

After — shared helpers plus a callback that distinguishes 401 from 403
(`fluent-cart-bulk-order.php:112`, `:136`, `:260`):

```php
function fcbo_get_allowed_roles() {
    // single source of truth, filterable for extension (fluent-cart-bulk-order.php:114)
    return apply_filters('fcbo/allowed_roles', ['administrator', 'wholesale-customer']);
}

function fcbo_current_user_can_access() {
    if (!is_user_logged_in()) {
        return false;
    }
    if (is_super_admin()) {            // escape hatch: multisite super admins (:94)
        return true;
    }
    return (bool) array_intersect(fcbo_get_allowed_roles(), wp_get_current_user()->roles);
}

function fcbo_rest_permission_check() {
    if (!is_user_logged_in()) {
        return new \WP_Error('fcbo_rest_unauthorized', __('You must be logged in...'), ['status' => 401]);
    }
    if (!fcbo_current_user_can_access()) {
        return new \WP_Error('fcbo_rest_forbidden', __('You do not have permission...'), ['status' => 403]);
    }
    return true;
}
```

Both routes then use `'permission_callback' => 'fcbo_rest_permission_check'`, and the two
shortcode guards were refactored to call the same `fcbo_current_user_can_access()` so the
allowed-role list has one definition.

**The convention held as the surface grew.** This note was written when the plugin had two
REST routes. It now registers five (`/products`, `/catalog`, `/resolve-skus`, `/saved-lists`,
`/past-orders`) across seven method registrations, and all seven `permission_callback` entries
are `fcbo_rest_permission_check` — `__return_true` no longer appears in any PHP file in the
repo. That is the outcome this fix was meant to produce. Re-confirm it with the grep below
whenever new routes land rather than assuming it persists.

Note the message strings use `__()` (not `esc_html__()`): these serialize into a JSON REST
error body, where HTML-escaping would turn apostrophes into literal `&#039;` entities. The
shortcodes, which emit HTML, keep `esc_html__()`.

## Why This Works

`permission_callback` is WordPress's authoritative authorization gate for a REST route: the
REST server runs it *before* the route callback and short-circuits with the returned
`WP_Error` (never invoking `fcbo_list_catalog`/`fcbo_search_products`). Because the current
user is already resolved by the time it runs, a single `is_user_logged_in()` +
role-intersection check covers every authentication method (cookie+nonce, application
passwords) without route-specific nonce handling. Returning a `WP_Error` with an explicit
`status` yields a correct `401` (unauthenticated) vs `403` (authenticated, wrong role)
instead of a generic denial.

Verified live in WordPress across all four principals: logged-out → `401`, `subscriber` →
`403`, `administrator` and `wholesale-customer` → allowed, on both routes; and the
`fcbo/allowed_roles` filter correctly extends the set.

## Prevention

- **Every custom REST route needs an explicit `permission_callback`.** Treat `__return_true`
  as a red flag in review — it is only correct for genuinely public data. Grep for it before
  shipping: `grep -rn "__return_true" .`
- **Gate the data, not just the UI.** If a shortcode/page is role-restricted, its backing
  REST route or AJAX handler must enforce the *same* restriction independently.
- **When the operation belongs to the host plugin, there is no route to gate.** The two
  prevention checks above only ever inspect routes *this* plugin registers, so both pass
  clean while a host-owned mutation path sits completely ungated — FluentCart's cart-add,
  for instance, registers no REST route at all and runs through the host's own AJAX handler.
  Enforcement there moves from a `permission_callback` to a host filter whose return value
  the host actually checks; see
  [`architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout`](../architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout.md)
  for how to identify one and which FluentCart hooks qualify.
- **Authenticate *and* authorize.** `is_user_logged_in()` alone is not access control on a
  site that allows self-registration; check the role/capability.
- **Prefer `is_super_admin()` / capability checks over bare role-slug lists** where multisite
  or custom setups are possible, so a privileged user isn't accidentally locked out.
- Add a smoke check to any release checklist: `curl` each custom route unauthenticated and
  confirm a 401/403, not a 200.

## Related Issues

- Fixed in PR #2 (`fix/rest-api-permission-lockdown`), merged to `development`.
- Plan: `docs/plans/2026-07-18-001-fix-rest-api-permission-lockdown-plan.md`.
- **Formerly-adjacent gap — now governed, no longer unconditional.** This note originally
  recorded that the single-product bulk-pricing display rendered the same discount-tier data
  publicly on product pages. That is no longer accurate: `fcbo_render_single_product_tiers()`
  (`fluent-cart-bulk-order.php:1462`) now returns early unless
  `fcbo_user_qualifies_for_bulk_pricing(null, 'display')` passes (`:1470`), which is the
  gating Plan 002 tracked. Read the remaining exposure precisely: the mechanism exists and is
  enforced, but the policy's default is an empty role list meaning *everyone qualifies*, so a
  store that has not configured roles still renders tiers to anonymous visitors. The gap moved
  from "unclosable without code" to "closed by configuring the role policy" — worth knowing
  before treating this bullet as an open finding.
