---
title: "feat: Consolidated settings page for store-wide defaults"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: feat
depth: standard
---

# feat: Consolidated settings page for store-wide defaults

Roadmap Phase 2 · Item 6. Origin: `docs/value-roadmap.md`.

## Summary

Today the plugin's behavior is spread across one minimal options page (the role policy in
`includes/Settings.php`) and a scatter of hardcoded literals in `fluent-cart-bulk-order.php`:
allowed roles are filter-only, the product table defaults to 5 rows with a fixed column set,
and the checkout redirect is resolved from the store setting unless a shortcode overrides it.
Plan 002 built the role-policy page as a deliberately self-contained stopgap and explicitly
deferred the consolidation to "Phase 2 Item 6" (`docs/plans/2026-07-18-002-feat-role-based-discount-gating-plan.md`
KTD3, Scope Boundaries). This plan is that consolidation: it grows `includes/Settings.php`
into a single **store-wide defaults** page covering allowed roles, checkout redirect target,
product-table display defaults (per-page, columns, search, expand-variants), and guest tier
visibility — while keeping every existing option name and behavior intact for backward
compatibility, and preserving per-placement shortcode attributes as one-directional overrides.

Crucially, wiring `fcbo_get_allowed_roles()` to a stored option finally realizes the
CONCEPTS.md promise that the permitted-roles set is "a single, extensible policy shared by
both the on-page surfaces and the REST layer" — closing the documented gap where a shortcode
`roles` attribute widens only the UI gate but not the REST routes.

---

## Problem Frame

- **The role policy already has a page, but nothing else does.** `includes/Settings.php`
  registers one Settings-API page (`add_options_page`, `manage_options`,
  `includes/Settings.php:47-55`) with a single field — the `fcbo_apply_to_roles` role
  checklist (`registerSettings()` :61, `renderRolesField()` :128, `sanitizeRoles()` :98).
  Its constants (`OPTION_GROUP`/`OPTION_NAME`/`PAGE_SLUG`, :23-33) and `renderPage()` (:159)
  are the scaffold to extend, not replace.
- **Allowed roles are filter-only with no stored option.** `fcbo_get_allowed_roles()`
  (`fluent-cart-bulk-order.php:79`) returns a hardcoded `['administrator', 'wholesale-customer']`
  through the `fcbo/allowed_roles` filter — a store owner cannot widen access without writing
  PHP. Worse, the per-shortcode `roles` attribute only widens the UI gate: the caveat at
  `fluent-cart-bulk-order.php:84-99` (and restated at :505-507) documents that
  `fcbo_current_user_can_access($extraRoles)` extends the UI check but `fcbo_rest_permission_check()`
  (:227) calls it with no extras, so `/products` and `/catalog` still enforce only the global
  set. A stored option read by `fcbo_get_allowed_roles()` fixes both surfaces at once.
- **Product-table display defaults are literals.** `fcbo_render_product_table()` hardcodes
  `per_page => 5`, `columns => ''`, `search => 'true'`, `expand_variants => 'false'` in its
  `shortcode_atts` (`fluent-cart-bulk-order.php:496-503`); the resolved values feed
  `fcboPtConfig` (:570-578). A store with a large catalog must repeat `per_page="20"` on every
  placement.
- **The checkout redirect has no store-wide default.** `fcbo_render_shortcode()` reads the
  `redirect` attribute (:250-253) and otherwise resolves `checkout_url` from
  `StoreSettings::getCheckoutPage()` (:290-304). There is no plugin-level way to point every
  bulk-order form at a dedicated wholesale checkout without a shortcode attribute on each.
- **Guest tier visibility is implicit.** Whether logged-out/low-privilege shoppers see the
  single-product tier display is governed by `fcbo_user_qualifies_for_bulk_pricing(null, 'display')`
  (`fluent-cart-bulk-order.php:1083`, gate at :901) reading `fcbo_get_bulk_pricing_roles()`
  (:1058, option `fcbo_apply_to_roles`). This is real, working behavior but it is buried in the
  same role checklist; the roadmap calls for surfacing it as a first-class, named control.

**Non-goals:** a Vue/React settings SPA (the existing WP Settings-API pattern stays);
import/export of settings; multisite network-level settings; moving or reworking the
`BulkPricingIntegration` per-feed tier UI (`includes/BulkPricingIntegration.php`); building the
per-role price-list UI (Plan 008), order-rule config (Plan 009), or savings/nudge toggle
(Plan 010) — this plan only provides the extensible page shell plus the four consolidations the
roadmap names, and marks where those later plans plug in.

---

## Requirements

- **R1.** A single admin page (still under Settings, `manage_options`) exposes: allowed roles,
  checkout redirect target, product-table display defaults (per-page, columns, search,
  expand-variants), and guest tier visibility.
- **R2.** Stored values are **store-wide defaults**. A per-placement shortcode attribute always
  **overrides** the stored default for that placement; the precedence is one-directional
  (attribute > stored default > hardcoded fallback) and never reversed.
- **R3.** Allowed roles are backed by a stored option read by `fcbo_get_allowed_roles()`, so the
  **UI gate and the REST routes stay in sync** (the shortcode-only caveat is retired or narrowed
  to a note that attributes remain per-placement). The security baseline —
  `administrator` + `wholesale-customer` — is a non-removable floor: the option can only widen,
  never shrink it.
- **R4.** The existing `fcbo_apply_to_roles` option keeps its name, storage, and semantics
  (empty = everyone); upgrades preserve any saved value and unset options fall back to today's
  hardcoded behavior (R2's "hardcoded fallback" tier).
- **R5.** Every stored value is sanitized on save against the same allowlists the render paths
  already use (role slugs via `get_editable_roles()`, columns against the canonical set,
  per-page clamped to the REST cap, redirect validated same-site), so a bad stored value can
  never produce a fatal or a broken surface.
- **R6.** The page is structured so Plans 008/009/010 can add sections/fields without
  re-scaffolding — a documented extension seam, not a monolith.

---

## Key Technical Decisions

- **KTD1 — One `fcbo_settings` option array for the NEW settings; keep `fcbo_apply_to_roles`
  as-is.** The role policy already ships as its own top-level option (`fcbo_apply_to_roles`,
  `includes/Settings.php:28`) and is read in three places (`fcbo_get_bulk_pricing_roles()`
  :1058, plus the display/cart gates). Renaming it would risk silent data loss on upgrade, so it
  stays a standalone option (R4). Everything *new* (allowed-roles extras, redirect default,
  table defaults, guest-visibility) goes into a single serialized option `fcbo_settings` (an
  associative array) registered once via the Settings API. Rationale: one `register_setting`
  with one array `sanitize_callback` keeps validation centralized (R5) and gives Plans 008/009/010
  a natural home (add a key, extend the sanitizer) without a proliferation of top-level options.
- **KTD2 — `fcbo_get_allowed_roles()` merges a hard baseline with the stored extras.** Refactor
  the helper (`fluent-cart-bulk-order.php:79`) to `array_values(array_unique(array_merge(['administrator',
  'wholesale-customer'], $storedExtraRoles)))` and *then* pass through the existing
  `fcbo/allowed_roles` filter, so the filter still works and the baseline is never removable
  (R3). Because `fcbo_rest_permission_check()` (:227) and both shortcodes already call through
  this helper, REST and UI converge automatically — this is the CONCEPTS.md "single, extensible
  policy shared by on-page surfaces and REST" realized. The per-shortcode `roles` attribute
  (:258, :508) is unchanged and remains an additional per-placement UI widening; update the
  caveat comment (:84-99, :505-507) to say the *global* widening now flows through settings.
- **KTD3 — Typed getters are the single read path; render sites call them instead of literals.**
  Introduce small accessors — e.g. `fcbo_setting('table_per_page', 5)` (or per-key helpers) —
  that read `fcbo_settings` with the hardcoded value as the final fallback. `shortcode_atts` in
  `fcbo_render_product_table()` (:496-503) and `fcbo_render_shortcode()` (:250-253) then use the
  stored default as their default argument, so an explicit attribute still overrides (R2) and an
  absent option still yields today's literal (R4). This keeps precedence one-directional and in
  exactly one place per value.
- **KTD4 — Guest tier visibility is a labeled projection of the role policy, not a second store.**
  The display gate already exists (`fcbo_user_qualifies_for_bulk_pricing(..., 'display')` :1083,
  :901). Rather than add a competing option, the settings page surfaces a clearly-labeled
  "Who sees bulk pricing" control that reads/writes the *same* `fcbo_apply_to_roles` option, with
  helper text naming the guest/everyone case explicitly. This avoids two sources of truth for one
  behavior while satisfying the roadmap's "first-class control" ask.
- **KTD5 — Reuse the existing sanitizers; don't fork validation.** Save-time sanitization reuses
  the render-path allowlists: role slugs via `get_editable_roles()` (as `sanitizeRoles()` already
  does, `includes/Settings.php:98`), columns via `fcbo_parse_columns_attr()`
  (`fluent-cart-bulk-order.php:205`) against `['id','title','price','qty','action']`, per-page via
  `absint` + `min(100, max(1, …))` (mirroring :519-523 and the REST cap :620), redirect via
  `wp_validate_redirect()` (mirroring :298-304). One allowlist per value, shared by store and
  render (R5).

---

## Output Structure

No new directories. Changes are confined to `includes/Settings.php` (grown into the
consolidated page) and `fluent-cart-bulk-order.php` (the getters, the `fcbo_get_allowed_roles()`
refactor, and the render sites that adopt stored defaults).

---

## Implementation Units

### U1. `fcbo_settings` option, registration, and typed getters

**Goal:** One sanitized settings store plus the read accessors every render site will use.
**Requirements:** R1, R2, R4, R5, R6.
**Dependencies:** none.
**Files:** `includes/Settings.php`, `fluent-cart-bulk-order.php`.
**Approach:** Add a second `register_setting(self::OPTION_GROUP, 'fcbo_settings', [...])`
alongside the existing role-policy registration (`includes/Settings.php:61-71`), with a single
array `sanitize_callback` that validates each key by the KTD5 allowlists. Add
`fcbo_get_settings()` (reads `get_option('fcbo_settings', [])`, static-cached like
`fcbo_get_currency_sign()` at `fluent-cart-bulk-order.php:797`) and a `fcbo_setting($key,
$fallback)` accessor. Default keys: `allowed_extra_roles` (array), `checkout_redirect` (string
URL), `table_per_page` (int), `table_columns` (string/array), `table_search` (bool),
`table_expand_variants` (bool). Absent option / absent key ⇒ `$fallback` = today's literal (R4).
**Patterns to follow:** `register_setting` + `sanitize_callback` in `includes/Settings.php:61`;
static-cache accessor style at `fluent-cart-bulk-order.php:797-810`.
**Test scenarios:**
- Fresh install (no `fcbo_settings`) → every `fcbo_setting()` returns its hardcoded fallback.
- Saving `table_per_page = 20` → `fcbo_setting('table_per_page', 5)` returns 20.
- Saving `table_per_page = 9999` → sanitizer clamps to 100; `= 'abc'` → falls back to default.
- Saving `table_columns = 'title,bogus'` → stored as `['title']` (bogus dropped, R5).
**Verification:** option round-trips through save→read with each value sanitized to the allowlist.

### U2. Refactor `fcbo_get_allowed_roles()` to merge baseline + stored extras

**Goal:** Allowed roles come from settings; REST and UI gates stay in sync; baseline is a floor.
**Requirements:** R3.
**Dependencies:** U1.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Rewrite `fcbo_get_allowed_roles()` (:79) to merge the non-removable baseline
`['administrator','wholesale-customer']` with `fcbo_setting('allowed_extra_roles', [])`
(sanitized in U1), de-dupe, then pass through the existing `fcbo/allowed_roles` filter so
downstream filters still compose. No change needed at the call sites —
`fcbo_current_user_can_access()` (:103), `fcbo_rest_permission_check()` (:227), and both
shortcodes already read through this helper. Update the caveat comments (:84-99, :505-507): the
*global* set now widens via settings (covering REST); the shortcode `roles` attribute remains a
per-placement UI-only widening.
**Patterns to follow:** existing `apply_filters('fcbo/allowed_roles', …)` (:81); the
`array_merge` + `sanitize_key` widening already done for extra roles at :118-123.
**Test scenarios:**
- Settings add `shop_manager` → a shop_manager can render BOTH surfaces AND their `/catalog`,
  `/products` calls succeed (REST now in sync).
- Removing all extras → only admin + wholesale allowed (baseline floor holds).
- An attempt to store a value that would drop `administrator` → baseline still present (floor
  is not a stored value; it is always merged in).
- `fcbo/allowed_roles` filter still observed on top of the merged set.
**Verification:** the same principal is accepted or rejected identically by the UI gate and the
REST permission callback.

### U3. Product-table + checkout render sites adopt stored defaults

**Goal:** Store-wide defaults drive the product table and checkout redirect; attributes override.
**Requirements:** R2, R4.
**Dependencies:** U1.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** In `fcbo_render_product_table()` (:496-503), replace the literal `shortcode_atts`
defaults with `fcbo_setting()` reads: `'per_page' => fcbo_setting('table_per_page', 5)`,
`'columns' => fcbo_setting('table_columns', '')`, `'search' => fcbo_setting('table_search',
'true')`, `'expand_variants' => fcbo_setting('table_expand_variants', 'false')`. Because
`shortcode_atts` uses these as *defaults*, an explicit attribute still wins (R2); the existing
clamp/allowlist resolution (:519-540) is unchanged and re-validates. In `fcbo_render_shortcode()`
(:290-304), when no `redirect` attribute is supplied, seed `$checkout_url` from
`fcbo_setting('checkout_redirect', '')` (validated via `wp_validate_redirect()` like :298-304)
before falling back to `StoreSettings::getCheckoutPage()`. Attribute > stored default > store
page, one-directional.
**Patterns to follow:** existing `shortcode_atts` default pattern (:496, :250); the
`wp_validate_redirect()` guard (:298-304); per-page clamp (:519-523).
**Test scenarios:**
- Stored `table_per_page = 20`, shortcode with no `per_page` → 20 rows; with `per_page="8"` → 8
  (attribute overrides, R2).
- No `fcbo_settings` at all → table behaves exactly as today (5 rows, all columns) (R4).
- Stored `checkout_redirect` set, form with no `redirect` attr → checkout button targets it;
  `redirect="…"` attr present → attr wins; neither → store checkout page (unchanged).
- Off-site stored redirect → rejected by `wp_validate_redirect`, store page used (R5).
**Verification:** each surface reflects stored default when no attribute, the attribute when
present, and the hardcoded fallback when the option is absent.

### U4. Consolidated settings page UI (sections, fields, extension seam)

**Goal:** One `manage_options` page rendering all four consolidations, ready for later plans.
**Requirements:** R1, R5, R6.
**Dependencies:** U1, U2, U3.
**Files:** `includes/Settings.php`.
**Approach:** Grow `registerSettings()` (:61) to add settings sections/fields for: **Access**
(the existing `fcbo_apply_to_roles` checklist — `renderRolesField()` :128 — kept verbatim, plus
the new `allowed_extra_roles` checklist for surface/REST access, clearly separated from the
pricing-audience checklist); **Checkout** (`checkout_redirect` URL field); **Product Table**
(`table_per_page` number, `table_columns` checkbox group over the canonical set, `table_search`
+ `table_expand_variants` toggles); **Bulk Pricing Visibility** (KTD4 labeled projection of the
role policy, naming the guest/everyone case). Keep `renderPage()` (:159), `settings_fields()`,
and the `add_options_page` capability/nonce flow (:47-55, :167-168) exactly as they are. Add a
short doc-comment marking where Plans 008/009/010 attach their sections (R6).
**Patterns to follow:** `add_settings_section` + `add_settings_field` (:73-86); the hidden-field
+ checklist idiom in `renderRolesField()` (:135-148); `settings_fields()`/`do_settings_sections()`
in `renderPage()` (:167-169).
**Test scenarios:**
- Saving the full form persists role extras, redirect, and all four table defaults; reload shows
  the saved state checked/filled.
- The pricing-audience checklist and the surface-access checklist are visibly distinct and write
  to their respective options (`fcbo_apply_to_roles` vs `fcbo_settings[allowed_extra_roles]`).
- Submitting an unknown role slug in either checklist is dropped (reuses `get_editable_roles()`
  allowlist, R5).
- A non-admin (`manage_options` false) cannot reach the page (`renderPage()` guard :161).
**Verification:** end-to-end — set each control, then confirm the corresponding surface (table
rows, checkout target, REST access, tier display) reflects it, with a shortcode attribute still
able to override the per-placement ones.

---

## Scope Boundaries

### Deferred to Follow-Up Work
- Per-role price-list configuration UI (Plan 008) — attaches as a new section here.
- Store-wide order-rule config: min order total + role scope (Plan 009) — attaches here.
- Savings-message / unlock-nudge visibility toggle (Plan 010) — attaches here.
- Import/export of settings; multisite network settings; a Vue/React settings SPA.

### Outside this plan
- Automated test harness (none exists; verification is manual).
- The `BulkPricingIntegration` per-feed tier UI (`includes/BulkPricingIntegration.php`) — the
  per-feed tier data model is unchanged; only store-wide defaults live on this page.

---

## Risks & Dependencies

- **Backward-compat on the role policy.** `fcbo_apply_to_roles` MUST keep its name and empty =
  everyone semantics (`includes/Settings.php:28`, `fluent-cart-bulk-order.php:1058-1061`).
  Folding it into `fcbo_settings` would silently drop existing stores' saved policy — KTD1
  keeps it standalone specifically to avoid this.
- **Security floor must never be storable-away.** The baseline `administrator` +
  `wholesale-customer` is merged in code, not read from the option (KTD2/U2), so no stored value
  or malformed option can lock admins out of their own surfaces (R3).
- **Precedence drift.** If any render site reads the stored default *after* resolving the
  attribute, per-placement overrides would break. KTD3 fixes precedence at the `shortcode_atts`
  default layer so it is one-directional by construction (R2).
- **Caveat coupling.** U2 changes the meaning of the caveat comments at
  `fluent-cart-bulk-order.php:84-99` and :505-507; they must be updated in the same change so the
  code and its documentation don't diverge.
- **Plan 002 dependency.** This plan assumes Plan 002 landed (`includes/Settings.php` exists). If
  it has not, U4 subsumes 002's page creation rather than extending it.

---

## Definition of Done

- One `manage_options` Settings page consolidates allowed roles, checkout redirect, product-table
  display defaults, and guest tier visibility.
- Stored values act as store-wide defaults; shortcode attributes still override per placement,
  one-directionally; absent options reproduce today's hardcoded behavior exactly.
- `fcbo_get_allowed_roles()` reads a stored option so the UI gate and the REST routes widen
  together, with the admin + wholesale baseline as a non-removable floor.
- The existing `fcbo_apply_to_roles` option is untouched in name and semantics; upgrades preserve
  saved policy.
- Every stored value is sanitized on save against the same allowlists the render paths use; no
  bad value can fatal or break a surface.
- The page exposes a documented extension seam for Plans 008/009/010.
