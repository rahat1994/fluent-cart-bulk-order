---
title: "feat: Savings messaging and next-tier nudges"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: feat
depth: standard
---

# feat: Savings messaging and next-tier nudges

Roadmap Phase 2 · Item 5. Origin: `docs/value-roadmap.md`.

## Summary

The plugin already resolves the discounted (effective) price against every Bulk Pricing
Tier — on the Bulk Order Form (`getEffectivePrice()`,
`assets/js/bulk-order.js:317`), on the single-product order widget
(`resolveDiscount()` + `recalcTable()`, `assets/js/bulk-pricing-display.js:8,20`), and in
the cart (`fcbo_apply_cart_bulk_pricing()`, `fluent-cart-bulk-order.php:1113`). But it never
tells the buyer **how much they saved** or **how close they are to the next tier**. Both are
already-computed deltas the code throws away. This plan surfaces two messages: a
"You saved $X" line (per row and grand total) on the plugin's own surfaces, and an
"Add N more to unlock X% off" nudge derived from the next tier by `min_qty` — the small
prompts that turn a static discount table into an active up-sell.

The cart/checkout "You saved $X" line is contingent on a FluentCart render hook that is not
yet confirmed to exist; U4 makes discovering it an explicit first step and defines the
fallback if it does not.

---

## Problem Frame

- On the Bulk Order Form, `updateRowTotal()` (`assets/js/bulk-order.js:346`) already renders
  original vs. discounted unit price side by side (`:360-366`) and `updateGrandTotal()`
  (`:372`) sums the discounted line totals — but neither states the **saving** (the
  `unitPrice - effectivePrice` delta), and there is no prompt toward the next tier.
- On the single-product order widget, `recalcTable()` computes both `grandOriginal` and
  `grandDiscounted` (`assets/js/bulk-pricing-display.js:36-37`) and strikes through the
  original (`:42,:53`) — again the delta is on hand but never labeled as a saving, and there
  is no next-tier nudge.
- The tier list needed for a nudge is already present on each surface: on the form via
  `row.dataset.bulkTiers` (`parseTiers()`, `assets/js/bulk-order.js:336`); on the widget via
  the per-row `data-fcbo-variant` payload embedded by `fcbo_render_order_table()`
  (`fluent-cart-bulk-order.php:862-866`), parsed at `assets/js/bulk-pricing-display.js:26`.
- In the cart, `fcbo_apply_cart_bulk_pricing()` lowers `item_price`
  (`fluent-cart-bulk-order.php:1142`); the original-vs-discounted delta is the per-line
  saving, but the plugin renders nothing in FluentCart's cart/checkout UI today — it has no
  render hook wired there.
- Net effect: buyers see a discounted number but are never told the discount is a *saving*,
  and never nudged to add the few units that would unlock the next Bulk Pricing Tier.

**Non-goals:** store-wide savings banners; savings totals in order-confirmation emails or
receipts; gamified progress bars or animations beyond a single line of nudge text; changing
how discounts are *computed* (that is Plan 008); adding new tier audiences or rules.

---

## Requirements

- **R1.** On the Bulk Order Form, each product row with an active discount shows a
  "You saved $X" line, and the footer shows the summed grand-total saving.
- **R2.** On the single-product order widget, the same per-row and grand-total saving is
  shown, consistent in wording and formatting with R1.
- **R3.** Where a higher Bulk Pricing Tier exists above the current quantity, show an
  "Add N more to unlock X% off" nudge (N = `nextTier.min_qty - qty`); hide it when no higher
  tier exists or quantity already reaches the top tier.
- **R4.** Savings and nudges read the effective price from the **shared** effective-price
  function, never a re-hardcoded percent formula, so non-percent tier types (Plan 008)
  render correctly; when the tier type is not percent, the unlock phrase degrades to a
  generic "unlock a better price" wording.
- **R5.** All new user-facing strings are i18n-ready (localized/translatable), consistent
  with the Phase 1 i18n polish (Plan 005); zero savings (`delta === 0`) renders nothing, not
  "You saved $0.00".
- **R6.** A cart/checkout "You saved $X" line is delivered **only if** a suitable FluentCart
  cart/checkout render or totals hook exists (U4); if none does, that surface is explicitly
  deferred and the plugin's own surfaces (R1, R2) still ship.

---

## Key Technical Decisions

- **KTD1 — Reuse the resolved effective price; never recompute the discount.** Both surfaces
  already resolve the discounted price (`getEffectivePrice()`,
  `assets/js/bulk-order.js:317`; `resolveDiscount()`,
  `assets/js/bulk-pricing-display.js:8`). The saving is `(unitPrice - effectivePrice) * qty`
  summed over rows — pure subtraction on values already in hand. When Plan 008 lands a shared
  type-aware effective-price helper, these call sites consume it (R4); this plan must not
  fork a second percent-only formula.
- **KTD2 — "Next tier" is the lowest tier whose `min_qty` exceeds the current qty.** Tiers
  are already stored sorted ascending by `min_qty` (server sorts them in
  `BulkPricingIntegration::validateFeedData()`, `includes/BulkPricingIntegration.php:96`), so
  the first tier with `min_qty > qty` is the next unlock; `N = tier.min_qty - qty`. If the
  current qty is at/above the highest tier's `min_qty`, there is no next tier and the nudge is
  suppressed (R3).
- **KTD3 — Nudge wording is type-aware but data-driven.** For a percent tier, phrase
  "Add N more to unlock X% off" using `discount_value`. For any other `discount_type`
  introduced by Plan 008 (fixed unit price, amount off), phrase generically —
  "Add N more to unlock a better price" — rather than mislabeling the unit (R4). The nudge
  reads `discount_type`/`discount_value` off the same tier object it already parses.
- **KTD4 — Savings on the plugin's own surfaces is unconditional; cart/checkout is gated on
  hook discovery.** R1/R2 touch only the plugin's own DOM and ship regardless. The
  cart/checkout line (R6) depends on a FluentCart hook whose existence is unverified; U4
  discovers it first and either wires a minimal render or documents the deferral — it must not
  block U1–U3.
- **KTD5 — Presentation reuses existing formatters and the discount styling already present.**
  Money uses `formatPrice()` (`assets/js/bulk-order.js:387`;
  `assets/js/bulk-pricing-display.js:4`) and, server-side, `fcbo_get_currency_sign()`
  (`fluent-cart-bulk-order.php:797`). Savings/nudge text sits in new, class-scoped elements so
  the existing original-vs-discounted markup (`assets/js/bulk-order.js:360-366`;
  `assets/js/bulk-pricing-display.js:42,:53`) is left intact.

---

## Output Structure

No new directories. Changes are confined to `assets/js/bulk-order.js`,
`assets/js/bulk-pricing-display.js`, their CSS
(`assets/css/bulk-order.css`, `assets/css/bulk-pricing-display.css`), and — only if U4
confirms a hook — a small render callback plus enqueue in `fluent-cart-bulk-order.php`.

---

## Implementation Units

### U1. Shared saving + next-tier computation

**Goal:** One place computes "amount saved" and "next tier to unlock" for a
(unitPrice, qty, tiers) triple, so both surfaces stay consistent.
**Requirements:** R3, R4, R5.
**Dependencies:** Plan 008's shared effective-price function if present; otherwise wraps the
existing per-surface resolver.
**Files:** `assets/js/bulk-order.js`, `assets/js/bulk-pricing-display.js`.
**Approach:** Add two small pure helpers usable by both files (duplicated per file or shared
if a shared module exists): `savingCents(unitPrice, qty, tiers)` =
`(unitPrice - effective(unitPrice, qty, tiers)) * qty` using the surface's existing resolver
(`getEffectivePrice()` `:317` / `resolveDiscount()` `:8`); and
`nextTier(qty, tiers)` returning the first tier with `min_qty > qty` (tiers already ascending,
KTD2) or `null`. Both return the tier object so callers read `discount_type`/`discount_value`
for wording (KTD3).
**Patterns to follow:** the existing tier-iteration shape in `getEffectivePrice()`
(`assets/js/bulk-order.js:322-332`) and `resolveDiscount()`
(`assets/js/bulk-pricing-display.js:9-17`).
**Test scenarios:**
- qty inside a tier → `savingCents` = positive delta; qty below all tiers → `0`.
- qty below a higher tier → `nextTier` returns that tier; qty at top tier → `null` (R3).
- non-percent tier object → helper still returns it; wording decision deferred to caller (R4).
**Verification:** helper outputs match hand-computed deltas and next-tier picks across the
tier fixtures.

### U2. Savings + nudge on the Bulk Order Form

**Goal:** Per-row "You saved $X" and next-tier nudge, plus a grand-total saving in the footer.
**Requirements:** R1, R3, R4, R5.
**Dependencies:** U1.
**Files:** `assets/js/bulk-order.js`, `assets/css/bulk-order.css`.
**Approach:** In `updateRowTotal()` (`:346`), after the existing original-vs-discounted render
(`:360-366`), compute `savingCents()` and `nextTier()` for the row and write a
`.fcbo-saving` / `.fcbo-nudge` element; render the saving only when `> 0` (R5) and the nudge
only when a higher tier exists (R3). In `updateGrandTotal()` (`:372`), accumulate the summed
saving alongside the existing grand-total loop (`:375-381`) and write it near
`#fcbo-grand-total` (`:382`). Money via `formatPrice()` (`:387`); strings wrapped for i18n
(R5, Plan 005). Nudge wording per KTD3.
**Patterns to follow:** the discounted-price branch already in `updateRowTotal()`
(`assets/js/bulk-order.js:360-366`); `formatPrice()` (`:387`).
**Test scenarios:**
- Row at a discounted qty → "You saved $X" with X = delta; footer sums all row savings (R1).
- Row one unit below a higher tier → "Add 1 more to unlock 10% off" (R3).
- Row with no discount / qty 0 → no saving line, no `$0.00` (R5).
- Non-percent tier (Plan 008) → generic unlock wording, correct saving amount (R4).
**Verification:** as qty changes, saving and nudge update live and the footer total-saving
equals the sum of visible row savings.

### U3. Savings + nudge on the single-product order widget

**Goal:** The same per-row and grand-total saving and nudge on the product-page widget.
**Requirements:** R2, R3, R4, R5.
**Dependencies:** U1.
**Files:** `assets/js/bulk-pricing-display.js`, `assets/css/bulk-pricing-display.css`.
**Approach:** In `recalcTable()` (`:20`), reuse `grandOriginal`/`grandDiscounted` already
accumulated (`:36-37`): grand saving = `grandOriginal - grandDiscounted`, rendered near the
grand-total cell (`.fcbo-bp-grand-total`, `:48`) only when `> 0` (R5). Per row, compute the
row saving from the already-derived `originalTotal`/`discountedTotal` (`:32,:34`) and the
next-tier nudge from `data.tiers` (`:26`) via U1's helpers, written into a new element in/near
the price cell (`:28`) without disturbing the existing `<del>`/discount markup (`:42`).
Money via `formatPrice()` (`:4`); i18n per R5.
**Patterns to follow:** the grand-total render branch (`assets/js/bulk-pricing-display.js:50-56`)
and the per-row discount branch (`:41-45`).
**Test scenarios:**
- Variant at a discounted qty → per-row saving; multiple variants → grand saving sums (R2).
- Qty below a higher tier → nudge shown; at top tier → nudge hidden (R3).
- Qty 0 / no discount → no saving line (R5).
**Verification:** widget saving and nudge track quantity changes and stay consistent with the
Bulk Order Form wording (R2).

### U4. Cart/checkout "You saved $X" line (hook-gated)

**Goal:** Surface the per-line/order saving inside FluentCart's cart/checkout — if a hook
allows it.
**Requirements:** R6.
**Dependencies:** none on U1–U3 (independent surface).
**Files:** `fluent-cart-bulk-order.php` (only if a hook is confirmed).
**Approach:** **First, discover the hook.** Search FluentCart for a cart/checkout render,
line-item, or totals action/filter analogous to the existing
`fluent_cart/product/single/after_quantity_block` (used at `fluent-cart-bulk-order.php:61`)
and `fluent_cart/cart/item_modify` (`:64`) — e.g. a cart-summary or order-totals render hook.
If one exists, compute the saving as the delta between the variant's original `item_price`
and the tier-discounted price (`fcbo_apply_cart_bulk_pricing()` logic,
`fluent-cart-bulk-order.php:1136-1145`) and render a "You saved $X" line using
`fcbo_get_currency_sign()` (`:797`), gated on `fcbo_user_qualifies_for_bulk_pricing()`
(`:1083`) so excluded shoppers see nothing. If **no** suitable hook exists, do not force it:
record the deferral (R6) — the plugin's own surfaces (U2, U3) still deliver the value.
**Patterns to follow:** the existing display-hook registration
(`fluent-cart-bulk-order.php:61`) and the cart discount computation (`:1136-1145`).
**Test scenarios:**
- Hook found → cart/checkout shows the correct summed saving for a qualifying buyer; excluded
  buyer sees nothing (R6).
- Hook absent → no cart/checkout change; U2/U3 unaffected and still shipping (R6, KTD4).
**Verification:** either a saving line appears in FluentCart's cart/checkout with the right
amount, or the deferral is documented with the reason no hook was available.

---

## Scope Boundaries

### Deferred to Follow-Up Work
- Cart/checkout savings line if U4 finds no FluentCart hook (revisit when FluentCart exposes
  one).
- Savings totals in order-confirmation emails/receipts.
- A toggle for whether savings/nudges display (belongs to the consolidated settings page,
  Plan 011).

### Outside this plan
- How discounts are computed and the introduction of non-percent tier types (Plan 008); this
  plan only *reads* the effective price.
- Order-rule inline messaging (minimums, step/case-pack) — adjacent, but owned by Plan 009.
- Automated test harness (none exists; verification is manual).

---

## Risks & Dependencies

- **FluentCart cart/checkout hook may not exist (R6).** U4 is explicitly gated on discovery;
  if absent, cart/checkout savings is deferred without blocking the shipping surfaces (KTD4).
- **Effective-price coupling with Plan 008.** If Plan 008 lands first, U1 must consume its
  shared type-aware helper rather than the percent path; if this plan lands first, the helpers
  wrap today's resolvers and Plan 008 swaps the implementation underneath (KTD1, R4).
- **Markup drift.** Savings/nudge elements must be added *around* the existing
  original-vs-discounted markup (`assets/js/bulk-order.js:360-366`;
  `assets/js/bulk-pricing-display.js:42,:53`), not by rewriting it, or the two surfaces
  diverge — covered by U2/U3 consistency scenarios.
- **String freeze with Plan 005.** New strings must go through the same i18n mechanism Plan 005
  establishes; landing order should avoid two localization patterns (R5).

---

## Definition of Done

- Bulk Order Form and single-product widget each show per-row and grand-total "You saved $X",
  computed from the shared effective price, with zero-saving rendering nothing.
- A next-tier "Add N more to unlock …" nudge appears wherever a higher Bulk Pricing Tier
  exists, with percent-aware and generic (non-percent) wording.
- All new strings are i18n-ready.
- Cart/checkout savings ships if a FluentCart hook exists; otherwise the deferral is
  documented and the own-surface messaging is unaffected.
