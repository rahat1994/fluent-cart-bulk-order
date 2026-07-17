---
title: "feat: Gate bulk pricing to configurable roles"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: feat
depth: standard
---

# feat: Gate bulk pricing to configurable roles

Roadmap Phase 1 · Item 2. Origin: `docs/value-roadmap.md`.

## Summary

Bulk pricing currently applies to **every** shopper: `fcbo_apply_cart_bulk_pricing()`
discounts any cart line whose quantity hits a tier, and the single-product tier table
renders publicly. For a wholesale plugin, discounts are usually meant for specific
audiences. This plan adds a stored "apply bulk pricing to" policy — everyone (default,
backward-compatible) or selected roles — and enforces it consistently in both the cart
discount and the public tier display, behind a filterable qualification helper.

---

## Problem Frame

- `fcbo_apply_cart_bulk_pricing()` (`fluent-cart-bulk-order.php:759`) rewrites
  `item_price` for any variant with matching tiers, regardless of the shopper's role.
- `fcbo_render_single_product_tiers()` (`:599`) is hooked on
  `fluent_cart/product/single/after_quantity_block` and shows tier tables and an order
  widget to all visitors.
- There is no way for a store owner to say "wholesale prices are for wholesale accounts."
  The `wholesale-customer` role exists but does not actually gate pricing.
- Any policy must default to today's behavior (everyone) so existing stores see no change
  on upgrade.

**Non-goals:** per-tier audiences, per-product audiences, a polished consolidated settings
page (that is Phase 2 Item 6 — here we add only the minimal control this policy needs).

---

## Requirements

- **R1.** A store owner can restrict bulk pricing to a chosen set of roles, or leave it open
  to everyone.
- **R2.** The policy governs **both** the cart discount and the public tier display —
  a non-qualifying shopper sees neither the discounted price nor the tier UI.
- **R3.** Default (no roles configured) = everyone, preserving current behavior on upgrade.
- **R4.** The qualification decision is filterable so developers can implement custom logic
  (e.g. per-customer overrides) without editing core.
- **R5.** Administrators can always see the tier display (so they can preview/verify),
  regardless of policy.

---

## Key Technical Decisions

- **KTD1 — Store the policy as a WP option, not in the integration feed.** A top-level
  option `fcbo_apply_to_roles` (array of role slugs; empty = everyone) is independent of any
  single pricing feed and is the natural home for a store-wide policy. The global pricing
  feed is per-tier data, not audience policy.
- **KTD2 — One qualification helper: `fcbo_user_qualifies_for_bulk_pricing($user = null)`.**
  Both enforcement points call it. Logic: resolve roles from the option; empty ⇒ `true`;
  otherwise `true` iff the user has an allowed role. Administrators short-circuit to `true`
  for the **display** path only (R5). Wrap the result in
  `apply_filters('fcbo/user_qualifies_for_bulk_pricing', $qualifies, $user)` (R4).
- **KTD3 — Minimal admin control now, real page later.** Register a single Settings-API
  section (a role checklist) rather than building the full settings page. Keep the field
  registration isolated in `includes/Settings.php` so Phase 2 can absorb it. Deferring the
  richer UI is explicit, not forgotten.
- **KTD4 — Fail open on the cart path only for administrators is *not* applied.** In the
  cart, the policy is authoritative for everyone including admins (an admin's real order
  should reflect real policy). R5's admin exception is display-only. This asymmetry is
  intentional and documented so it is not "fixed" into inconsistency later.

---

## Implementation Units

### U1. Policy storage and qualification helper

**Goal:** Central helper that answers "does this user get bulk pricing?"
**Requirements:** R2, R3, R4, R5.
**Dependencies:** none (composes with Item 1's role helpers if present, but does not require
them).
**Files:** `fluent-cart-bulk-order.php` (helper) or `includes/Settings.php` (co-located with
storage — implementer's call).
**Approach:** `fcbo_get_bulk_pricing_roles()` → `(array) get_option('fcbo_apply_to_roles',
[])`. `fcbo_user_qualifies_for_bulk_pricing($user = null, $context = 'cart')`: resolve
`$user` from `wp_get_current_user()` when null; if roles list empty return `true`; if
`$context === 'display'` and user is `administrator` return `true` (R5); else return
`(bool) array_intersect($roles, $user->roles)`. Return through the
`fcbo/user_qualifies_for_bulk_pricing` filter.
**Patterns to follow:** `fcbo_get_currency_sign()` static-cached helper style (`:503`).
**Test scenarios:**
- Option empty → returns `true` for guest, subscriber, wholesale, admin (backward compat, R3).
- Option `['wholesale-customer']`, context `cart` → `true` for wholesale, `false` for guest /
  subscriber / admin.
- Option `['wholesale-customer']`, context `display` → `true` for admin (R5), `false` for guest.
- Filter forcing `true` overrides a `false` result (R4).
**Verification:** helper returns the truth table above across all four principals × both
contexts.

### U2. Enforce policy in the cart discount

**Goal:** Non-qualifying shoppers are charged the normal price.
**Requirements:** R2.
**Dependencies:** U1.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** At the top of `fcbo_apply_cart_bulk_pricing()` (`:759`), after the existing
guard, `if (! fcbo_user_qualifies_for_bulk_pricing(null, 'cart')) { return $variation; }`
before resolving tiers.
**Patterns to follow:** the early-return guards already in that function (`:761`, `:772`).
**Test scenarios:**
- Policy = wholesale only; guest adds qualifying quantity → cart line keeps full price.
- Same policy; wholesale user adds qualifying quantity → discounted price applied (unchanged).
- Policy empty → discount applies to everyone (unchanged from today).
**Verification:** cart totals reflect the policy for each principal.

### U3. Enforce policy in the tier display

**Goal:** Non-qualifying visitors don't see the tier tables/order widget.
**Requirements:** R2, R5.
**Dependencies:** U1.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Early in `fcbo_render_single_product_tiers()` (`:599`), after the
`empty($args['product'])` guard, `if (! fcbo_user_qualifies_for_bulk_pricing(null,
'display')) { return; }`.
**Patterns to follow:** the existing early `return;` guards in the same function.
**Test scenarios:**
- Policy = wholesale only; guest on a product page → no `.fcbo-bp-wrap` rendered.
- Same policy; wholesale user → tier table renders (unchanged).
- Same policy; administrator → tier table renders (R5).
- Policy empty → renders for everyone (unchanged).
**Verification:** product page shows/hides the tier block correctly per principal.

### U4. Minimal admin control for the policy

**Goal:** Store owner can set the role list without touching code.
**Requirements:** R1.
**Dependencies:** U1.
**Files:** `includes/Settings.php` (new), `fluent-cart-bulk-order.php` (require + init).
**Approach:** Register a Settings-API page (or section) exposing a checklist of
`get_editable_roles()` bound to `fcbo_apply_to_roles`, with a "leave empty = everyone" hint.
Sanitize submitted values against real role slugs. Keep the class self-contained so Phase 2
can fold it into a unified settings page.
**Patterns to follow:** the Vue tier repeater in `includes/BulkPricingIntegration.php` shows
the plugin's existing admin-config style; this unit uses the simpler core Settings API.
**Test scenarios:**
- Saving two roles persists exactly those slugs in `fcbo_apply_to_roles`.
- Submitting an invalid/unknown role slug is dropped by sanitization.
- Saving with nothing checked stores an empty array (⇒ everyone).
**Verification:** setting a role, then reloading a product page as a non-listed user, hides
the discount and display (end-to-end with U2/U3).

---

## Scope Boundaries

### Deferred to Follow-Up Work
- Consolidated settings page absorbing this control (Phase 2 Item 6).
- Per-product or per-tier audience targeting.

### Outside this plan
- Automated test harness (none exists; verification is manual).

---

## Risks & Dependencies

- **Silent behavior change risk.** If the default were anything but "everyone," existing
  stores would lose discounts on upgrade. R3 + U1's empty-list default guard against this —
  do not change the default.
- **Consistency risk.** The cart/display split on the admin exception (KTD4) is deliberate;
  document it in code so a later "make it consistent" pass doesn't collapse it wrongly.

---

## Definition of Done

- A store owner can restrict bulk pricing to selected roles or leave it open.
- Cart discount and tier display both honor the policy; admin still previews the display.
- Fresh installs and upgrades with no policy set behave exactly as today.
