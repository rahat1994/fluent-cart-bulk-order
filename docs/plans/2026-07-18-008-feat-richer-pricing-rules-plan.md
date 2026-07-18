---
title: "feat: Richer pricing rules — discount types and per-role price lists"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: feat
depth: deep
---

# feat: Richer pricing rules — discount types and per-role price lists

Roadmap Phase 2 · Item 3. Origin: `docs/value-roadmap.md`.

## Summary

Every Bulk Pricing Tier in the plugin today is a **percentage discount** and applies the
same to every qualifying shopper. `discount_type` is hardcoded to `'percent'` in
`validateFeedData()` (`includes/BulkPricingIntegration.php:78`), the repeater only exposes a
"Discount %" input (`:127`), and the cart math is a literal
`item_price * (1 - discountValue/100)` (`fluent-cart-bulk-order.php:1142`) mirrored in three
more places on the display side. This plan generalizes pricing in two escalating parts:

- **Part A — discount types.** Add `fixed_unit_price` (set an absolute per-unit price) and
  `amount_off` (subtract a flat amount) alongside `percent`, end to end: admin config,
  validation, cart application, and every display surface.
- **Part B — per-role price lists.** Let a tier-set be scoped to a role, so a
  `wholesale-customer` and a `wholesale-vip` can see different prices for the same product.
  This is the feature that turns "bulk discounts" into a true wholesale price-list plugin.

Both parts preserve today's behavior on upgrade: an existing percent-only feed with no role
scoping keeps working unchanged, and an empty role policy still means "everyone."

> **CONCEPTS.md note.** The **Bulk Pricing Tier** glossary entry currently defines the
> discount as "a percentage." Part A generalizes that to a typed discount; the concept entry
> should be updated when this lands (percentage becomes one of three types).

---

## Problem Frame

- **One discount type.** `validateFeedData()` sets `$discountType = 'percent'` unconditionally
  (`includes/BulkPricingIntegration.php:78`) and range-checks `discount_value` against
  `0..100` (`:80`) — a bound that is only meaningful for percentages. The Vue repeater offers a
  single "Discount %" `el-input-number` capped at 100 (`:127`); there is no way to enter "$8.50
  per unit at 100+" or "$2 off each."
- **Percent math is hardcoded in four places** that must all agree:
  1. Cart: `fcbo_apply_cart_bulk_pricing()` — `item_price * (1 - discountValue/100)`
     (`fluent-cart-bulk-order.php:1142`).
  2. Bulk order form JS: `getEffectivePrice()` (`assets/js/bulk-order.js:329`), consumed by
     `updateRowTotal()` (`:346`).
  3. Single-product order widget JS: `resolveDiscount()` + `recalcTable()`
     (`assets/js/bulk-pricing-display.js:8-18`, math at `:34`).
  4. Single-product tier **labels**: the `%% off` strings in
     `fcbo_render_single_product_tiers()` (`fluent-cart-bulk-order.php:935`, `:1004`, `:1035`).
- **Pricing is audience-blind past the on/off gate.** Plan 002 added a *qualification* gate —
  `fcbo_user_qualifies_for_bulk_pricing()` (`fluent-cart-bulk-order.php:1083`) resolving the
  `fcbo_apply_to_roles` option (`:1058`) — which decides **whether** bulk pricing applies. But
  once qualified, `fcbo_resolve_tiers()` (`:776`) returns the same tier-set to everyone; there
  is no notion of **which** prices a given role sees.
- **Tier data reaches the client pre-resolved**, so role logic must live server-side: the
  search REST payload embeds `bulk_tiers` per variant (`:477`) which the form stores as
  `row.dataset.bulkTiers` (`assets/js/bulk-order.js:173`), and the single-product widget embeds
  `tiers` in the row `data-fcbo-variant` attribute (`fluent-cart-bulk-order.php:862`). The
  browser never re-resolves; it renders whatever tiers the server serialized.

**Non-goals:** per-customer (per-account) price overrides; combined quantity×role matrices
beyond a simple role→tier-set mapping; currency-specific price lists; changing the tier
*resolution precedence* (product feed over global, first matching quantity range wins) — that
stays as-is (`:779`).

---

## Requirements

- **R1.** A tier can carry a `discount_type` of `percent` (today), `fixed_unit_price` (absolute
  per-unit price), or `amount_off` (flat per-unit reduction). Missing/unknown type ⇒ `percent`.
- **R2.** Validation is type-appropriate: percent stays `0..100`; money types accept any
  non-negative amount, stored in **integer cents** to match `item_price`.
- **R3.** The effective per-unit price is computed identically everywhere (one canonical
  formula per type), always in integer cents, and clamped to `>= 0`.
- **R4.** Every display surface (single-product tier tables, both JS live-total widgets) shows a
  type-appropriate label and strike-through, not a hardcoded `% off`.
- **R5.** A feed can define role-scoped tier-sets plus a default set; the shopper's role selects
  the set, falling back to the default. A feed with no role scoping behaves exactly as today.
- **R6.** Role resolution happens **server-side** for both enforcement (cart) and every
  serialized-to-client payload, so the browser needs no role knowledge.
- **R7.** Per-role price lists **compose with** — never replace — the Plan 002 qualification
  gate: the gate decides *if* pricing applies; role lists decide *which* tiers once it does.
- **R8.** Full backward compatibility: existing percent-only, role-less feeds and an empty
  `fcbo_apply_to_roles` policy produce identical prices before and after this change.

---

## Key Technical Decisions

- **KTD1 — One `discount_type` field, one `discount_value`, interpreted by type.** Rather than
  add parallel value fields, keep the existing `discount_value` and read it through
  `discount_type`: a percentage for `percent`, integer cents for `fixed_unit_price` and
  `amount_off`. `validateFeedData()` normalizes money entries to cents at save time so
  everything downstream (cart, display, serialized payloads) is already in cents — mirroring how
  `item_price` is handled as cents throughout (`:1142`, `:862`). Resolution
  (`fcbo_resolve_tiers()` `:776`) returns the tier array unchanged and needs no type awareness.
- **KTD2 — A single canonical effective-price helper in PHP, mirrored once per JS surface.**
  Introduce `fcbo_apply_tier_to_price($itemPriceCents, $tier): int` as the *only* place the
  per-type formula lives in PHP; `fcbo_apply_cart_bulk_pricing()` calls it instead of inlining
  the percent math (`:1142`). The two JS surfaces have no shared module (they duplicate the
  formula today — `getEffectivePrice()` `:329` vs `resolveDiscount()`/`recalcTable()` `:34`), so
  each gets the same typed `switch`, and the plan flags that all three copies must change
  together. Formulas: `percent` → `round(price * (1 - value/100))`; `fixed_unit_price` →
  `max(0, value)`; `amount_off` → `max(0, price - value)`.
- **KTD3 — Role dimension lives *inside* the feed's `meta_value`, not in new feed rows.**
  Storage stays one feed per scope: `fcbo_get_all_bulk_pricing()` still fetches a single global
  feed via `->first()` (`:716`) and batches one product feed per product (`:732`). We only grow
  the `meta_value` shape from `{tiers: [...]}` to a role map, e.g.
  `{tiers: [...] /* legacy default */, role_tiers: { 'wholesale-vip': [...] }}`. A legacy feed
  with only `tiers` is read as the default set for everyone (R8). This localizes the change to
  the repeater UI, `validateFeedData()`, and `fcbo_resolve_tiers()`; it avoids reworking how
  `BaseIntegrationManager` persists feeds. (Rejected: per-role *feeds* — multiple meta rows
  keyed by role — which would force `fcbo_get_all_bulk_pricing()` to fan out its `->first()`
  and batch queries per role and complicate product-vs-global precedence.)
- **KTD4 — `fcbo_resolve_tiers()` gains an optional role argument; callers pass the current
  user's roles.** Signature becomes
  `fcbo_resolve_tiers($pricingData, $productId, $variantId, $userRoles = null)`. After it picks
  the applicable feed by the existing product-over-global precedence (`:779`), it selects the
  tier-set within that feed: the first of `$userRoles` with a matching `role_tiers` entry wins,
  else the default `tiers`. Passing `null`/`[]` yields the default set — so every existing
  call site keeps working until it opts in by passing roles. This composes with the Plan 002
  gate (KTD in R7): `fcbo_user_qualifies_for_bulk_pricing()` (`:1083`) is unchanged and still
  runs first; role resolution only chooses *which* qualified tiers apply.
- **KTD5 — Clients receive role-resolved tiers; role logic never ships to the browser.** The
  REST search endpoint resolves against the authenticated user before embedding `bulk_tiers`
  (`:477`), and the single-product widget resolves against the current user before writing the
  `data-fcbo-variant` attribute (`:862`). Because both REST routes are auth-gated to logged-in
  allowed roles (`:368`, `fcbo_rest_permission_check`), the user is always known at
  serialization time. The `/catalog` route (`:617`) embeds **no** tiers in its variant payload
  (`:676-685`), so the product table has no client-side tier preview and needs no Part B change
  beyond the server-side cart filter that already prices its add-to-cart actions.

---

## Output Structure

No new files. Changes are confined to:

- `includes/BulkPricingIntegration.php` — validation + repeater template.
- `fluent-cart-bulk-order.php` — the `fcbo_apply_tier_to_price()` helper, cart application,
  role-aware `fcbo_resolve_tiers()`, display labels, and server-side serialization.
- `assets/js/bulk-order.js` and `assets/js/bulk-pricing-display.js` — typed effective-price
  math and labels.

---

## Implementation Units

### Part A — Discount types (percent / fixed unit price / amount off)

### U1. Per-tier `discount_type` in admin config and validation

**Goal:** Store owners can choose a discount type per tier and enter money amounts safely.
**Requirements:** R1, R2, R8.
**Dependencies:** none.
**Files:** `includes/BulkPricingIntegration.php`.
**Approach:** In `validateFeedData()` (`:69`) replace the hardcoded `$discountType = 'percent'`
(`:78`) with a whitelist read from `$tier['discount_type']` (`percent`, `fixed_unit_price`,
`amount_off`; unknown ⇒ `percent`, R1). Branch the value validation: keep `0..100` for
`percent` (`:80`); for the money types require `discount_value >= 0` and normalize to integer
cents (`round($value * 100)`) so downstream reads cents (KTD1, R2). Preserve the existing
`max_qty >= min_qty` guard (`:84`) and the `usort` by `min_qty` (`:96`). In
`getTierRepeaterTemplate()` (`:110`) add a type selector (`el-select`) per tier row and show a
"%"-labelled input for `percent` vs a currency-labelled input (major units) for the money types;
extend the "+ Add Tier" default object (`:131`) with `discount_type:'percent'`. Leave
`getIntegrationDefaults()` (`:36`) and `getSettingsFields()` (`:45`) structurally unchanged.
**Patterns to follow:** the existing per-tier sanitize/guard loop and `usort` in
`validateFeedData()` (`:74-98`); the `el-input-number` binding style in the repeater (`:119-127`).
**Test scenarios:**
- A `percent` tier of `10` and a `fixed_unit_price` tier of `8.50` save as `discount_value: 10`
  and `discount_value: 850` (cents) with the right `discount_type`.
- `amount_off` of `2` saves as `discount_value: 200`, type `amount_off`.
- A negative money value or a percent `> 100` is dropped, matching today's skip behavior (`:80`).
- A legacy tier posted with no `discount_type` saves as `percent` (R8).
**Verification:** the persisted feed `meta_value.tiers` shows correct type + cents/percent for
each entry.

### U2. Canonical type-aware price helper + cart application

**Goal:** The cart charges the correct per-unit price for every discount type.
**Requirements:** R1, R3.
**Dependencies:** U1.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Add `fcbo_apply_tier_to_price($itemPriceCents, $tier): int` implementing the three
formulas (KTD2), each returning integer cents clamped `>= 0`. In
`fcbo_apply_cart_bulk_pricing()` replace the inline percent math (`:1142`) with a call to the
helper for the first matching tier; the surrounding quantity-range match (`:1136-1145`) and the
Plan 002 gate at the top (`:1121`) are unchanged.
**Patterns to follow:** the existing tier loop and `(int) round(...)` cents handling in
`fcbo_apply_cart_bulk_pricing()` (`:1136-1145`).
**Test scenarios:**
- `percent 10%` on a $10.00 item at qualifying qty → 900 cents (unchanged from today).
- `fixed_unit_price 8.50` → 850 cents per unit regardless of original.
- `amount_off 2.00` on a $10.00 item → 800 cents; `amount_off 15.00` on a $10.00 item → 0
  (clamped, R3).
**Verification:** cart line unit price equals the helper output for each type.

### U3. Type-aware math and labels across display surfaces

**Goal:** Every preview and tier table reflects the actual type, not "% off."
**Requirements:** R3, R4.
**Dependencies:** U1 (data), U2 (canonical formula to mirror).
**Files:** `fluent-cart-bulk-order.php`, `assets/js/bulk-order.js`,
`assets/js/bulk-pricing-display.js`.
**Approach:** Mirror `fcbo_apply_tier_to_price()` into `getEffectivePrice()`
(`assets/js/bulk-order.js:317`, replacing the percent return at `:329`) and into
`bulk-pricing-display.js` (`resolveDiscount()`/`recalcTable()`, replacing the `1 - discount/100`
at `:34`); both must read `discount_type` and treat `discount_value` as cents for money types
(KTD2). `updateRowTotal()` (`:346`) already keys its strike-through off
`effectivePrice < unitPrice`, so it works for any price-lowering type once the effective price is
correct. In PHP, make the tier-table labels type-aware where they currently print `%% off`: the
simple-product list (`:935`), the all-variants-same table (`:1004`), and the per-variant table
(`:1035`) — render "10% off" / "$8.50/unit" / "$2.00 off" per type. Keep the qty-range
formatting (`min – max` / `min+`) intact.
**Patterns to follow:** the existing strike-through markup in `updateRowTotal()` (`:360-366`)
and `recalcTable()` (`:41-45`); the `number_format`/`rtrim` label formatting already used at
`:937`.
**Test scenarios:**
- A `fixed_unit_price` tier: form row and single-product widget both show the set price and a
  struck-through original at qualifying qty; below the range, full price.
- An `amount_off` tier: preview subtracts the flat amount and clamps at 0.
- A `percent` tier renders identically to today (regression guard, R8).
- Tier tables label each type correctly (no stray `%` on money types).
**Verification:** the three JS/PHP surfaces agree with U2's cart price for the same qty and type.

### Part B — Per-role price lists

### U4. Role-scoped tier-sets: storage shape, repeater, and validation

**Goal:** A feed can carry a default tier-set plus role-specific tier-sets.
**Requirements:** R5, R8.
**Dependencies:** U1.
**Files:** `includes/BulkPricingIntegration.php`.
**Approach:** Adopt the in-feed role map (KTD3): `meta_value` keeps `tiers` (the default,
everyone) and gains optional `role_tiers` keyed by role slug. Extend the repeater
(`getTierRepeaterTemplate()` `:110`) to let the owner add a role-scoped group (role selector
sourced from editable roles) whose rows reuse the U1 tier fields. Extend `validateFeedData()`
(`:69`) to validate each role group the same way as the default set (reusing the U1 per-tier
sanitize path), keying by `sanitize_key`'d role slug and dropping unknown/empty slugs; a feed
submitted without any role group persists exactly today's `{tiers: [...]}` shape (R8).
**Patterns to follow:** the role-slug sanitize-against-editable-roles pattern in
`Settings::sanitizeRoles()` (`includes/Settings.php:98-112`); the existing per-tier validation
loop reused per group (`BulkPricingIntegration.php:74-98`).
**Test scenarios:**
- Saving a default set + one `wholesale-vip` set persists both under `tiers` and
  `role_tiers['wholesale-vip']`.
- An unknown role slug in a group is dropped (mirrors `sanitizeRoles()`).
- Saving with no role group yields the legacy `{tiers: [...]}` shape (R8).
**Verification:** persisted `meta_value` matches the configured default + role groups.

### U5. Role-aware resolution wired into cart and display

**Goal:** The shopper's role selects which tier-set prices their order and their preview.
**Requirements:** R5, R6, R7, R8.
**Dependencies:** U4.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Extend `fcbo_resolve_tiers()` (`:776`) with `$userRoles = null` (KTD4): after the
unchanged product-over-global feed precedence (`:779`), pick the tier-set — first matching role
in `role_tiers`, else the default `tiers`; `null`/`[]` ⇒ default. Pass
`wp_get_current_user()->roles` at the cart call site (`fcbo_apply_cart_bulk_pricing()` `:1130`)
and at the display call sites in `fcbo_render_single_product_tiers()` (`:915`, `:958`). The
Plan 002 qualification gate (`:901`, `:1121`) is untouched and still runs first (R7).
**Patterns to follow:** the current-user role reads in `fcbo_current_user_can_access()`
(`:123`) and `fcbo_user_qualifies_for_bulk_pricing()` (`:1090`); the untouched precedence loop
in `fcbo_resolve_tiers()` (`:779-789`).
**Test scenarios:**
- Feed has default 5% + `wholesale-vip` 12%: a VIP's cart line uses 12%, a plain wholesale
  customer uses 5%, and (per Plan 002 gate) a non-qualifying guest gets neither.
- Feed with no `role_tiers`: every qualifying role gets the default set (R8).
- `fcbo_resolve_tiers()` called with `null` roles returns the default set (call-site safety).
**Verification:** cart price and product-page tier table both reflect the role-selected set.

### U6. Server-side role-resolved serialization to clients

**Goal:** The browser receives already-role-resolved tiers and needs no role logic.
**Requirements:** R6, R8.
**Dependencies:** U5.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Pass the current user's roles into the `fcbo_resolve_tiers()` call that builds the
search payload's `bulk_tiers` (`:477`) and into the calls that build the order-widget
`data-fcbo-variant` tiers for `fcbo_render_order_table()` (`:915`, `:958` feeding `:862`). Both
run under an authenticated request/render, so the user is known (KTD5). Confirm `/catalog`
(`:617`) still embeds no tiers (`:676-685`) and therefore needs no change — its add-to-cart is
priced by the server cart filter (U5).
**Patterns to follow:** the existing `fcbo_resolve_tiers()` usage that populates `bulk_tiers`
(`:477`) and the order-table data attribute (`:862-866`).
**Test scenarios:**
- A VIP searching in the bulk order form gets `bulk_tiers` = the VIP set; a plain wholesale
  customer gets the default set; the form's live totals (U3) match their cart price (U5).
- The single-product widget's `data-fcbo-variant` tiers match the viewer's role set.
- The product table (`/catalog`) still previews no tiers and adds to cart at the role-correct
  price via the cart filter (R6).
**Verification:** the serialized tiers for two different roles differ as configured and match
the server cart price.

---

## Scope Boundaries

### Deferred to Follow-Up Work
- Surfacing the per-role price-list configuration through the consolidated settings page
  (Plan 011) — this plan keeps configuration in the existing Bulk Pricing feed repeater.
- Savings messaging that reads the resolved, type-aware effective price ("You saved $X",
  "add N more to unlock the next tier") is Plan 010 and depends on U2/U3's canonical price.

### Outside this plan
- Per-customer (per-account) price overrides, quantity×role matrices, and currency-specific
  price lists (see Non-goals).
- Automated test harness (none exists; verification is manual).

---

## Risks & Dependencies

- **Four-copy formula drift.** The effective-price formula lives in one PHP helper (KTD2) but is
  duplicated in two JS surfaces (`bulk-order.js:329`, `bulk-pricing-display.js:34`) and echoed by
  PHP label code (`:935`, `:1004`, `:1035`). U2/U3 must land together; a mismatch shows the
  shopper one price and charges another. Mitigated by U3's cross-surface verification.
- **Cents vs. major units at the UI boundary.** Money tier values are entered in major units but
  must persist as integer cents to match `item_price` (`:1142`, `:862`). U1 normalizes at save;
  if that conversion is skipped, money discounts are off by 100×. Covered by U1's cents assertion.
- **Gate/role-list conflation.** Per-role lists must compose with, not duplicate, the Plan 002
  qualification gate (R7, KTD4). Keep `fcbo_user_qualifies_for_bulk_pricing()` (`:1083`) as the
  sole "if," and `role_tiers` as the sole "which."
- **Legacy feed shape.** Every read path must treat a feed with only `tiers` (no
  `role_tiers`, no `discount_type`) as "default set, percent" (R8). This is the single most
  important regression guard; it appears in U1, U4, and U5 test scenarios.
- **FluentCart coupling.** Relies on the feed storage via `Meta`/`ProductMeta`
  (`:716`, `:732`) and `BaseIntegrationManager` keeping the single-feed-per-scope model; KTD3
  deliberately avoids changing that model.

---

## Definition of Done

- Tiers support `percent`, `fixed_unit_price`, and `amount_off`, validated per type and stored
  in integer cents for money types.
- One canonical PHP helper computes the effective price; the cart and all three display surfaces
  agree with it for every type.
- A feed can define role-scoped tier-sets plus a default; the shopper's role selects the set
  server-side for both the cart and every client payload, composing with the Plan 002 gate.
- Existing percent-only, role-less feeds and an empty role policy produce identical prices to
  today.
