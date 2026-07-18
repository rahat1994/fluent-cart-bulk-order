---
title: "feat: Order rules — min qty, case-pack steps, min order total"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: feat
depth: standard
---

# feat: Order rules — min qty, case-pack steps, min order total

Roadmap Phase 2 · Item 4. Origin: `docs/value-roadmap.md`.

## Summary

Wholesale sales run on rules: "sold in cases of 12," "minimum 24 units," "no order under
$500." Today the plugin enforces none of these — every quantity input starts at `1` with
`step="1"` (`assets/js/bulk-order.js:43`, `assets/js/product-table.js:110`) and checkout
adds whatever is typed with no floor. This plan adds three composable **order rules** —
**minimum quantity** per product/variant, **quantity step / case-pack multiples**, and a
**minimum order total** scoped to wholesale roles — each surfaced with clear inline messaging
on the ordering surfaces and, critically, each enforced server-side so a crafted request
cannot bypass them.

---

## Problem Frame

- Quantity inputs are hardcoded to `value="1" min="1" step="1"` on both the Bulk Order Form
  (`assets/js/bulk-order.js:43`) and the Product Table (`assets/js/product-table.js:110`,
  built by `qtyInputHtml()` `:109`). There is no per-product minimum and no notion of a
  case-pack multiple.
- `handleCheckout()` (`assets/js/bulk-order.js:207`) collects rows, blocks only the
  empty-selection and mixed subscription/one-time cases, then adds items — there is no
  order-total floor. `handleAddToCart()` in the Product Table (`assets/js/product-table.js:226`)
  adds a single row with the same absence of rules.
- The only confirmed server-side integration point is the cart price filter
  `fcbo_apply_cart_bulk_pricing()` on `fluent_cart/cart/item_modify` (registered
  `fluent-cart-bulk-order.php:64`, fn `:1113`). That hook **modifies price**; it is **not a
  validation gate** and cannot reject a line. Enforcing min-qty / step / min-order-total on the
  server therefore requires a FluentCart cart-or-checkout **validation** hook that is not yet
  identified in this codebase (see KTD1 and Risks).
- The pricing feed already carries per-product / per-variant configuration
  (`includes/BulkPricingIntegration.php`: `getSettingsFields()` `:45`, `validateFeedData()`
  `:69`, the Vue tier repeater `:110`) and already speaks the `min_qty` / `max_qty` vocabulary
  in each tier (`:74-93`) — so per-product rule fields have a natural, consistent home.

**Non-goals:** maximum-quantity caps, per-category rules, tax/shipping-based thresholds,
backorder / stock-reservation logic, and a polished consolidated settings UI (the store-wide
minimum-order-total control is registered minimally here and folded into Plan 011).

---

## Requirements

- **R1.** A store owner can set a **minimum quantity** per product (and per targeted variant),
  below which a line cannot be ordered. Default (unset / `0`) preserves today's behavior.
- **R2.** A store owner can set a **quantity step (case-pack multiple)** per product/variant,
  so quantities are constrained to multiples of that step (e.g. 6, 12). Default (unset / `1`)
  preserves today's behavior.
- **R3.** A store owner can set a **minimum order total**, enforced only for a configured set
  of roles (the wholesale audience). Default (unset) = no floor for anyone.
- **R4.** Every rule is surfaced with **clear inline messaging** at the point of violation —
  the offending row/qty for R1/R2, and a checkout-level message stating how far short the cart
  is for R3 — reusing the existing status affordances.
- **R5.** Every rule is **enforced server-side** as the authoritative check; the client checks
  are UX only and are assumed bypassable. A request that skips the UI must still be rejected.
- **R6.** Rules **compose with paste/CSV-populated rows** (Plan 006): rows created by import
  are validated by the identical client and server rules, not a parallel path.

---

## Key Technical Decisions

- **KTD1 — Server enforcement hinges on discovering FluentCart's validation hook; this is
  Unit 1 and a hard dependency.** `fluent_cart/cart/item_modify` (`:64`, `:1113`) can only
  rewrite a variation, not veto it, so it cannot enforce a minimum or a step. Before any
  server-side rule is written, the implementer must locate FluentCart's cart-item-add /
  cart-validate / pre-checkout validation hook (or REST endpoint filter) that can return an
  error and halt the add/checkout. If no such hook exists, R5 degrades to "enforced at the
  plugin's own add-to-cart entry points" and the gap is documented — it is **not** silently
  dropped to client-only. This discovery is the first unit precisely because everything
  server-side depends on its outcome.
- **KTD2 — Per-product rules live on the existing pricing feed; the min-order-total lives in a
  WP option.** Min-qty and step are per-product/per-variant facts, so they belong beside the
  tiers on the `fcbo_bulk_pricing` feed (`includes/BulkPricingIntegration.php:45`,
  validated in `:69`), reachable through the same `fcbo_get_all_bulk_pricing()` /
  `fcbo_resolve_tiers()` plumbing (`fluent-cart-bulk-order.php:709`, `:776`) that already
  resolves per-variant data. The minimum **order** total is store-wide policy, not per-product,
  so it is a top-level option (`fcbo_min_order_total` + a role list), mirroring how the role
  policy is stored as `fcbo_apply_to_roles` (`:1058`). Rationale: keep per-line rules where
  per-line data already resolves, and store-wide policy where store-wide policy already lives.
- **KTD3 — One resolver, two consumers, shared vocabulary.** Add
  `fcbo_resolve_order_rules($pricingData, $productId, $variantId)` returning
  `['min_qty' => int, 'step' => int]` next to `fcbo_resolve_tiers()` (`:776`), reusing the
  same product-over-global precedence. Both the client (via a data attribute / config) and the
  server validator read from this single resolver so the qty vocabulary stays consistent with
  the tier `min_qty` / `max_qty` fields (`includes/BulkPricingIntegration.php:74-93`).
- **KTD4 — Client normalizes, server rejects.** On the client, the qty input carries the
  resolved `min` and `step` so the browser's number spinner and `updateRowTotal()` /
  `updateGrandTotal()` math already respect them; a `blur`/pre-submit pass rounds up to the
  nearest valid multiple ≥ min and messages the change (R2 "round-up, don't silently drop").
  The server treats an out-of-rule quantity as a hard error (R5). The asymmetry — client is
  forgiving, server is strict — is intentional so a legitimate typo is corrected in place while
  a forged request is refused.
- **KTD5 — Min-order-total role scope reuses the qualification seam.** The audience for R3 is
  resolved through the existing role machinery — either `fcbo_user_qualifies_for_bulk_pricing()`
  (`:1083`) / `fcbo_get_bulk_pricing_roles()` (`:1058`) if the store wants the *same* audience
  as bulk pricing, or a parallel `fcbo_min_order_total_roles` option resolved by a sibling
  helper if it should be independent. Default to a **parallel option** so a store can require a
  minimum order of wholesale buyers without also gating discounts — but expose the same
  `array_intersect($roles, $userRoles)` shape and a `fcbo/user_subject_to_min_order` filter for
  parity (mirrors `:1097`, `:1100`).

---

## Output Structure

No new top-level directories. Changes are confined to:
- `fluent-cart-bulk-order.php` — rule resolver, server validator wiring, min-order-total
  option + helper, config plumbing into both `wp_localize_script` blocks (`:314`, `:570`).
- `includes/BulkPricingIntegration.php` — per-product `min_qty` / `step` fields on the feed
  (`getSettingsFields()` `:45`, `validateFeedData()` `:69`, repeater template `:110`).
- `includes/Settings.php` — minimal min-order-total + role-scope controls (later absorbed by
  Plan 011).
- `assets/js/bulk-order.js`, `assets/js/product-table.js` — qty input `min`/`step`, inline
  normalization + messaging, checkout/add gates.

---

## Implementation Units

### U1. Discover and wrap the FluentCart server-side validation hook

**Goal:** Establish the authoritative server seam that can reject an out-of-rule add/checkout.
**Requirements:** R5 (prerequisite for R1, R2, R3 server enforcement).
**Dependencies:** none — this is the gating first step.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Search FluentCart for a cart-item-add or checkout-validation hook/filter that can
short-circuit with a `WP_Error`-style result (candidates to confirm: a `fluent_cart/cart/*`
add/validate filter, or a REST `add-to-cart` / checkout validation callback). Register a thin
FCBO validator on it that, given a variant id + quantity, loads rules via KTD3 and returns an
error when violated. Document the exact hook name and signature in a code comment. If none
exists, record the limitation and fall back to validating at the plugin's own add-to-cart
call sites, and raise it in Risks — do not pretend R5 is met.
**Patterns to follow:** the existing hook registration for `fluent_cart/cart/item_modify`
(`fluent-cart-bulk-order.php:64`) as the template for wiring; the early-return guard style in
`fcbo_apply_cart_bulk_pricing()` (`:1115`, `:1121`, `:1132`).
**Test scenarios:**
- A direct request adding a below-minimum quantity is rejected server-side (R5).
- A direct request adding a non-multiple quantity is rejected server-side (R5).
- A valid quantity passes through unchanged.
**Verification:** the hook fires and blocks a hand-crafted request that bypasses the UI; the
chosen hook name is documented in code.

### U2. Per-product min-qty and step fields on the pricing feed

**Goal:** Store owner can configure minimum quantity and case-pack step per product/variant.
**Requirements:** R1, R2.
**Dependencies:** none (composes with U3's resolver).
**Files:** `includes/BulkPricingIntegration.php`.
**Approach:** Add `min_qty` and `step` fields to `getSettingsFields()` (`:45`) — either as
top-level feed fields or as a small "Order rules" group alongside `tiers` — and sanitize them in
`validateFeedData()` (`:69`) next to the tier loop (`:74-93`): `min_qty = max(0, intval(...))`,
`step = max(1, intval(...))`, with `min_qty` normalized up to the nearest multiple of `step`
when both are set. Extend the Vue repeater/template (`:110`) with the two inputs, mirroring the
`el-input-number` controls already used for tier fields (`:119-127`). Persisted shape sits on
the same `meta_value` the feed already stores.
**Patterns to follow:** the tier sanitize/clamp loop (`:74-93`); the `el-input-number` fields
in `getTierRepeaterTemplate()` (`:119-127`).
**Test scenarios:**
- Saving `min_qty=24, step=12` persists both on the feed; `min_qty` normalizes to a multiple of
  `step` (24 stays 24; 20 → 24).
- Unset/blank fields persist as `min_qty=0, step=1` (no-op defaults, R1/R2).
- `step=0` or negative degrades to `1`; `min_qty` negative degrades to `0`.
**Verification:** feed round-trips the two fields with the documented defaults and clamps.

### U3. Rule resolver + client config plumbing

**Goal:** One resolver feeds both surfaces; qty inputs carry `min` and `step`.
**Requirements:** R1, R2, R3 (config transport).
**Dependencies:** U2 (fields exist).
**Files:** `fluent-cart-bulk-order.php`, `assets/js/bulk-order.js`, `assets/js/product-table.js`.
**Approach:** Add `fcbo_resolve_order_rules($pricingData, $productId, $variantId)` beside
`fcbo_resolve_tiers()` (`:776`), returning `['min_qty' => int, 'step' => int]` with
product-over-global precedence (reuse the `fcbo_get_all_bulk_pricing()` structure `:709`).
Surface per-variant rules to the client the same way tiers are surfaced today: include them in
the `/products` and `/catalog` variant payloads (`:467`, `:677`) and, for the Bulk Order Form,
store them on the row dataset next to `bulkTiers` (`assets/js/bulk-order.js:173`). Add
`min_order_total` + `subject_to_min_order` to both `wp_localize_script` configs (`:314`,
`:570`). In the JS row builders, set the qty input's `min` and `step` from the resolved rule
(`assets/js/bulk-order.js:43`, `qtyInputHtml()` `assets/js/product-table.js:109`).
**Patterns to follow:** `fcbo_resolve_tiers()` precedence (`:776-790`); `row.dataset.bulkTiers`
transport (`assets/js/bulk-order.js:173`, `parseTiers()` `:336`); the two existing
`wp_localize_script` blocks (`:314`, `:570`).
**Test scenarios:**
- A product with `min_qty=24, step=12` renders its qty input with `min="24" step="12"` on both
  surfaces.
- A product with no rule renders `min="1" step="1"` (unchanged).
- Product-level rule overrides a global rule for the same variant (precedence).
**Verification:** inspected qty inputs and row datasets carry the resolved rule on both surfaces.

### U4. Client-side normalization and inline messaging (min-qty + step)

**Goal:** Typed quantities are corrected up to a valid value with a clear message; violations
are visible before checkout.
**Requirements:** R1, R2, R4, R6.
**Dependencies:** U3.
**Files:** `assets/js/bulk-order.js`, `assets/js/product-table.js`.
**Approach:** On qty change/blur, round the entered quantity up to the nearest multiple of
`step` that is `≥ min_qty`, write it back to the input, and message the adjustment inline —
reuse `showStatus()` (`assets/js/bulk-order.js:392`) and, for per-row context, the amount/total
cells updated in `updateRowTotal()` (`:346`). Apply the same normalization inside `handleAddToCart()`
(`assets/js/product-table.js:226`) before the add call. Because Plan 006's paste/CSV import
writes the same rows, its populated quantities pass through this identical normalization (R6).
**Patterns to follow:** `updateRowTotal()` / `updateGrandTotal()` recalgermin flow (`:346`, `:372`);
`showStatus()` messaging (`:392`); the qty `input` listener (`:57`).
**Test scenarios:**
- Entering `20` where `step=12, min=24` normalizes to `24` with a "rounded up to a case of 12"
  message.
- Entering `24` where `step=12` stays `24` (already valid, no message).
- A CSV-imported row of `10` for a `min=24` product is normalized identically (R6).
**Verification:** qty edits and imports settle on valid multiples with a visible, accurate message.

### U5. Minimum-order-total gate (checkout) with role scope

**Goal:** Configured-role buyers cannot check out below the minimum order total, and are told
how far short they are.
**Requirements:** R3, R4, R5, R6.
**Dependencies:** U1 (server gate), U3 (config transport).
**Files:** `fluent-cart-bulk-order.php`, `includes/Settings.php`, `assets/js/bulk-order.js`.
**Approach:** Store `fcbo_min_order_total` (integer cents) and `fcbo_min_order_total_roles`
(role slugs) as options; add `fcbo_get_min_order_total()` and
`fcbo_user_subject_to_min_order($user = null)` helpers modeled on
`fcbo_get_bulk_pricing_roles()` (`:1058`) and `fcbo_user_qualifies_for_bulk_pricing()`
(`:1083`), the latter wrapped in a `fcbo/user_subject_to_min_order` filter (mirrors `:1100`).
Client: in `handleCheckout()` (`assets/js/bulk-order.js:207`), before adding items, if the
config marks the user subject and the live grand total (`updateGrandTotal()` `:372`) is below
the minimum, block and `showStatus()` the shortfall ("Add $X more to reach the $Y minimum",
R4). Server: in the U1 validator's checkout path, recompute the cart total and reject when a
subject user is below the minimum (R5) — the client message is convenience only. Rows created
by Plan 006 import contribute to the same grand total, so the gate covers them (R6).
**Patterns to follow:** option + helper + filter shape of the role policy (`:1058`, `:1083`,
`:1100`); `updateGrandTotal()` (`:372`); `showStatus()` (`:392`); Settings-API registration in
`includes/Settings.php` (`registerSettings()` `:61`, `sanitizeRoles()` `:98`).
**Test scenarios:**
- Subject-role user with a cart below the minimum is blocked at checkout with a shortfall
  message; a forged direct checkout is likewise rejected server-side (R5).
- Same cart, non-subject user (or no minimum set) checks out normally (default preserved).
- Cart at or above the minimum proceeds; the message clears.
**Verification:** checkout blocks/permits per role and total on both the client and a
UI-bypassing request.

### U6. Minimal admin control for the min-order-total policy

**Goal:** Store owner sets the minimum order total and its role scope without touching code.
**Requirements:** R3.
**Dependencies:** U5.
**Files:** `includes/Settings.php`.
**Approach:** Register a min-order-total amount field and a role checklist bound to
`fcbo_min_order_total` / `fcbo_min_order_total_roles`, alongside the existing role-policy
section (`add_settings_section()` `:73`, `renderRolesField()` `:128`). Sanitize the amount to a
non-negative integer (cents) and the roles against `get_editable_roles()` exactly as
`sanitizeRoles()` does (`:98`). Keep it self-contained for Plan 011 to absorb.
**Patterns to follow:** the whole `includes/Settings.php` registration/sanitize/render flow
(`registerSettings()` `:61`, `sanitizeRoles()` `:98`, `renderRolesField()` `:128`).
**Test scenarios:**
- Saving `$500` + `wholesale-customer` persists `50000` cents and the one slug.
- Blank amount stores `0` / unset (⇒ no minimum, default).
- An invalid role slug is dropped by sanitization.
**Verification:** setting the amount + role, then checking out under it as that role, blocks
end-to-end with U5.

---

## Scope Boundaries

### Deferred to Follow-Up Work
- Folding the min-order-total controls into the consolidated settings page (Plan 011).
- "Add N more to unlock" *upsell* nudges beyond the shortfall message (overlaps Plan 010's
  savings messaging).
- Per-category or per-cart-mix rules.

### Outside this plan
- Automated test harness (none exists; verification is manual).

---

## Risks & Dependencies

- **Server validation hook may not exist (highest risk).** All of R5 depends on U1 finding a
  FluentCart hook that can *reject* an add/checkout. `fluent_cart/cart/item_modify` (`:64`,
  `:1113`) cannot — it only rewrites price. If discovery fails, server enforcement is limited to
  the plugin's own entry points and client checks remain bypassable; this must be stated in the
  shipped docs, not glossed. Resolve U1 before committing to R1/R2/R3 server semantics.
- **Client-only enforcement is not enforcement.** The qty `min`/`step` attributes and the
  checkout gate are UX; a scripted request ignores them (KTD4). The plan treats the server
  validator as the source of truth precisely to avoid a false sense of safety.
- **Rule/tier data coupling.** Placing `min_qty`/`step` on the pricing feed (KTD2) couples order
  rules to the `fcbo_bulk_pricing` feed lifecycle; a store with no pricing feed for a product
  has no per-product rule (falls back to global/defaults) — acceptable and consistent with how
  `fcbo_resolve_tiers()` already falls back (`:788`).
- **Role-scope divergence.** R3's audience is a separate option from the bulk-pricing role
  policy by default (KTD5); a store expecting them to be the same must set both. The parallel
  filter makes unifying them a one-liner if desired.
- **Cross-plan composition.** Plan 006 (paste/CSV) rows and Plan 010 (savings/nudge messaging)
  both touch the same qty inputs and grand total; land the shared resolver (U3) first so those
  plans consume it rather than duplicating rule logic.

---

## Definition of Done

- Store owners can set per-product minimum quantity and case-pack step, and a store-wide
  minimum order total scoped to chosen roles.
- Both ordering surfaces normalize quantities to valid multiples ≥ minimum with clear inline
  messaging, and checkout is blocked with a shortfall message when a subject user is below the
  minimum order total.
- Every rule is enforced server-side (or the exact enforcement gap is documented per U1), so a
  UI-bypassing request is rejected.
- Unset rules preserve today's behavior exactly (`min_qty=0`, `step=1`, no order-total floor).
