---
title: "fix: Lock down FCBO REST API permissions"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: fix
depth: standard
---

# fix: Lock down FCBO REST API permissions

Roadmap Phase 1 · Item 1. Origin: `docs/value-roadmap.md`.

## Summary

Both custom REST routes register `permission_callback => '__return_true'`, so the full
product catalog **and wholesale discount tier data** are readable by anyone — including
logged-out visitors — even though the shortcodes that consume them are gated to
`administrator` and `wholesale-customer`. This plan closes that gap by adding a shared
permission callback that mirrors the existing UI role check, plus a single shared
allowed-roles helper the rest of Phase 1 can build on.

---

## Problem Frame

- `fcbo_register_routes()` (`fluent-cart-bulk-order.php:164`) registers `/fcbo/v1/products`
  and `/fcbo/v1/catalog` with `'permission_callback' => '__return_true'`
  (`fluent-cart-bulk-order.php:169`, `:181`).
- The shortcodes gate on `is_user_logged_in()` + `array_intersect(['administrator',
  'wholesale-customer'], $user->roles)` (`:70-79`, `:269-278`), but the REST layer does not.
- Anyone can `GET /wp-json/fcbo/v1/catalog` and enumerate every published product, its
  variants, prices, stock, and — via `/products` — the resolved bulk pricing tiers meant
  only for wholesale accounts. This is a confidentiality leak of commercially sensitive
  pricing.
- The allowed-roles list is duplicated in three places, so any future role change risks
  drifting the REST gate out of sync with the UI gate.

**Non-goals:** rate limiting, per-product visibility rules, nonce-strategy changes, and a
configurable-roles admin UI (roles remain the hardcoded default here; configurability is
Item 4).

---

## Requirements

- **R1.** `/products` and `/catalog` must reject requests from users who are not logged in
  or who lack an allowed role, with correct HTTP status (401 unauthenticated, 403
  authenticated-but-forbidden).
- **R2.** Allowed roles for the REST gate must be identical to the shortcode gate
  (`administrator`, `wholesale-customer`) and sourced from one place.
- **R3.** Authorized users (administrator, wholesale-customer) must see no behavior change —
  the existing UI and search continue to work.
- **R4.** The role set must be overridable by a filter so Item 4 (shortcode `roles`
  attribute) and Item 2 (role-based gating) can extend it without re-duplicating the list.

---

## Key Technical Decisions

- **KTD1 — Return `WP_Error` with explicit status, not bare `false`.** A `WP_Error` with
  `['status' => 401]` / `['status' => 403]` gives clients an accurate, debuggable response
  and distinguishes "log in" from "you lack permission." Bare `false` collapses both into a
  generic `rest_forbidden`.
- **KTD2 — Extract one shared helper, `fcbo_get_allowed_roles()`, and one check,
  `fcbo_current_user_can_access()`.** The permission callback, both shortcodes, and later
  Phase 1 items call the same helper. `fcbo_get_allowed_roles()` wraps its return in a
  filter (`fcbo/allowed_roles`) to satisfy R4. This is the seam the other four plans depend
  on — land it here.
- **KTD3 — Keep the callback string-referenced, not a closure.** Register
  `'permission_callback' => 'fcbo_rest_permission_check'` to match the existing
  `'callback' => 'fcbo_search_products'` style in this file.

---

## Implementation Units

### U1. Shared allowed-roles helper and access check

**Goal:** Introduce a single source of truth for who may use FCBO surfaces.
**Requirements:** R2, R4.
**Dependencies:** none.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Add `fcbo_get_allowed_roles()` returning
`apply_filters('fcbo/allowed_roles', ['administrator', 'wholesale-customer'])`, and
`fcbo_current_user_can_access()` returning `is_user_logged_in() &&
(bool) array_intersect(fcbo_get_allowed_roles(), wp_get_current_user()->roles)`. Refactor
the two shortcode guards (`:77`, `:276`) to call `fcbo_current_user_can_access()` so the
list stops being copy-pasted. Behavior is unchanged; this is a pure extraction.
**Patterns to follow:** existing procedural helpers `fcbo_get_currency_sign()` (`:503`) and
`fcbo_get_all_bulk_pricing()` (`:415`).
**Test scenarios:**
- Administrator and wholesale-customer both pass `fcbo_current_user_can_access()`; a plain
  subscriber and a logged-out visitor both fail.
- After refactor, both shortcodes render the form for allowed roles and the permission
  message for others (unchanged from today).
- `add_filter('fcbo/allowed_roles', fn($r) => [...$r, 'shop_manager'])` makes a shop_manager
  pass the check — proves R4.
**Verification:** Existing shortcode gating still behaves identically; the roles literal
appears exactly once in the file.

### U2. REST permission callback on both routes

**Goal:** Gate `/products` and `/catalog` behind the shared access check.
**Requirements:** R1, R2, R3.
**Dependencies:** U1.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Add `fcbo_rest_permission_check()`: return a `WP_Error('fcbo_rest_unauthorized',
…, ['status' => 401])` when `! is_user_logged_in()`, a `WP_Error('fcbo_rest_forbidden', …,
['status' => 403])` when logged in but `! fcbo_current_user_can_access()`, else `true`.
Replace `'__return_true'` on both route registrations (`:169`, `:181`) with
`'fcbo_rest_permission_check'`.
**Patterns to follow:** WordPress core `rest_forbidden` semantics; the callback signature
style already used in this file.
**Execution note:** Verify against a real logged-out request (`curl` without a cookie) —
this is an authz change, so prove the deny path, not just the allow path.
**Test scenarios:**
- Logged-out `GET /wp-json/fcbo/v1/catalog` → HTTP 401; `GET …/products?search=xx` → 401.
- Logged-in subscriber (no allowed role) → 403 on both routes.
- Administrator → 200 with the expected payload on both routes.
- Wholesale-customer → 200 with the expected payload on both routes.
- Nonce still validates for an authorized logged-in user (the shortcode issues
  `wp_create_nonce('wp_rest')`; confirm the JS fetch continues to succeed).
**Verification:** Manual `curl` matrix above returns the stated statuses; the bulk order
form and product table still load and search for an administrator.

---

## Risks & Dependencies

- **Authz regression risk (high-sensitivity area).** A wrong role slug would lock out
  legitimate wholesale users. Mitigate by testing all four principals (logged-out,
  subscriber, admin, wholesale) before shipping.
- **Downstream dependency.** U1's `fcbo_get_allowed_roles()` / `fcbo_current_user_can_access()`
  are reused by Items 2 and 4. Land this plan first so the others extend rather than
  re-create the helper.
- No automated test harness exists in this plugin; verification is manual (`curl` + browser).
  Standing up PHPUnit is out of scope (Phase 2+).

---

## Definition of Done

- Both routes reject unauthenticated (401) and unauthorized (403) requests and serve
  authorized ones (200).
- Allowed-roles literal exists in exactly one place, wrapped in the `fcbo/allowed_roles`
  filter.
- Shortcode gating behavior is unchanged for every principal.
