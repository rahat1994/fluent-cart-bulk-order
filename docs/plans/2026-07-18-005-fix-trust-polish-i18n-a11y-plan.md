---
title: "fix: Trust polish — JS i18n, dropdown a11y, listener leak"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: fix
depth: standard
---

# fix: Trust polish — JS i18n, dropdown a11y, listener leak

Roadmap Phase 1 · Item 5. Origin: `docs/value-roadmap.md`.

## Summary

Three credibility fixes that make the plugin feel finished: (1) the JS files contain
hardcoded English user-facing strings that can't be translated, (2) the product search
dropdown is mouse-only with no ARIA, and (3) `addRow()` attaches a fresh
`document`-level click listener on every row that is never removed, leaking closures. Each is
small; together they raise the quality bar buyers judge the plugin by.

---

## Problem Frame

- **i18n gap.** `assets/js/bulk-order.js`, `assets/js/product-table.js`, and
  `assets/js/bulk-pricing-display.js` embed English literals ("No products found", "Search
  failed", "Adding items to cart...", "Cannot mix subscription and one-time products…",
  etc.). PHP strings are already wrapped in `esc_html__`, but the JS strings bypass
  translation entirely. There is no `languages/` directory and no `load_plugin_textdomain`
  call.
- **a11y gap.** The `bulk-order.js` product search (`:64`, `:101`) renders a `.fcbo-dropdown`
  that responds only to mouse clicks — no keyboard navigation, no `role`/`aria-*`, no focus
  management. Keyboard and screen-reader users can't operate it.
- **Listener leak.** `addRow()` (`:20`) calls `document.addEventListener('click', …)` inside
  every row's setup (`:77`). Each added row installs a new global listener capturing that
  row's `tr`; removing the row (`:52`) never removes the listener, so handlers and detached
  DOM references accumulate for the life of the page.

**Non-goals:** a full translation (`.po`/`.mo`) shipment, a build toolchain for
`@wordpress/i18n`, WCAG audit beyond the search widget, restyling.

---

## Requirements

- **R1.** All user-facing JS strings are translatable and sourced from PHP, not hardcoded in
  JS.
- **R2.** The plugin loads its text domain so translations resolve.
- **R3.** The search dropdown is fully keyboard-operable (Up/Down to move, Enter to select,
  Escape to close) with correct ARIA (`combobox`/`listbox`/`option`, `aria-activedescendant`,
  `aria-expanded`).
- **R4.** Exactly one document-level click-outside handler exists regardless of how many rows
  are added or removed; removed rows leave no residual listeners or references.
- **R5.** No behavior regression: search, selection, totals, and checkout still work by mouse.

---

## Key Technical Decisions

- **KTD1 — Deliver JS i18n via a localized strings map, not `wp.i18n`.** The plugin has no
  build step; adopting `@wordpress/i18n` would add one. Instead pass a `strings` object
  (values wrapped in PHP `__()`) through the existing `wp_localize_script` config
  (`fcboConfig`, `fcboPtConfig`, `fcboBpConfig`) and have JS read `CONFIG.strings.key`. Add
  `wp_set_script_translations` as well so future JS-native strings are covered, but the map is
  the mechanism that works today.
- **KTD2 — One delegated document listener installed at `init()`.** Replace the per-row
  listener with a single handler bound once in `init()` that closes any open dropdown whose
  `.fcbo-search-wrap` does not contain the event target. This is both the leak fix (R4) and
  simpler code.
- **KTD3 — Progressive-enhancement ARIA, no widget rewrite.** Add roles/attributes and
  keydown handling to the existing dropdown markup rather than swapping in a library. Track
  the active option index in row state and reflect it with `aria-activedescendant` + a visual
  `.fcbo-dd-active` class.
- **KTD4 — Load the text domain from a `languages/` dir.** Add `load_plugin_textdomain` on
  `plugins_loaded` (or `init`) pointing at a new `languages/` folder and declare
  `Domain Path` in the header, so `__()`/`esc_html__` (PHP and the localized JS map) resolve.

---

## Implementation Units

### U1. Load the text domain

**Goal:** Translations resolve for PHP and the JS strings map.
**Requirements:** R2.
**Dependencies:** none.
**Files:** `fluent-cart-bulk-order.php`, `languages/` (new, empty placeholder).
**Approach:** Add `load_plugin_textdomain('fluent-cart-bulk-order', false, dirname(plugin_basename(__FILE__)) . '/languages')`
early in the `plugins_loaded` callback; add `* Domain Path: /languages` to the plugin header.
**Patterns to follow:** the existing `Text Domain: fluent-cart-bulk-order` header (`:8`).
**Test expectation:** none behavioral — verify by dropping a test `.mo` and confirming a PHP
string translates. Non-feature scaffolding for U2.
**Verification:** with a sample translation present, an existing PHP `esc_html__` string
renders translated.

### U2. Localize JS strings and consume them

**Goal:** No user-facing English literal remains in JS.
**Requirements:** R1, R5.
**Dependencies:** U1.
**Files:** `fluent-cart-bulk-order.php`, `assets/js/bulk-order.js`,
`assets/js/product-table.js`, `assets/js/bulk-pricing-display.js`.
**Approach:** Build a `strings` array (each value `__('…', 'fluent-cart-bulk-order')`) and add
it to each `wp_localize_script` config (`fcboConfig` at `:115`, `fcboPtConfig` at `:308`,
`fcboBpConfig` at `:544`). Replace every hardcoded literal in the three JS files with
`CONFIG.strings.<key>` (fallback to the English default if a key is missing). Also call
`wp_set_script_translations` on each handle for forward-compatibility.
**Patterns to follow:** existing `wp_localize_script` usage in this file; `formatPrice`
already reads `CONFIG.currency_sign`.
**Test scenarios:**
- Each previously-hardcoded message ("No products found", "Search failed", "Adding items to
  cart...", the subscription-mix error, product-table loading/empty states) renders from the
  config map.
- A translation override of one key changes the rendered text.
- Missing key falls back to the English default (no `undefined` shown).
- Search, select, totals, checkout flow unchanged by mouse (R5 regression guard).
**Verification:** grep of the three JS files shows no remaining user-facing English literals;
UI text renders from config.

### U3. Keyboard + ARIA for the search dropdown

**Goal:** The product search is fully keyboard/screen-reader operable.
**Requirements:** R3, R5.
**Dependencies:** U2 (uses localized strings for any new announcements).
**Files:** `assets/js/bulk-order.js`, `assets/css/bulk-order.css`.
**Approach:** On the search input set `role="combobox"`, `aria-expanded`, `aria-controls`;
on the dropdown `role="listbox"`; on each item `role="option"` with a unique `id`. Add a
`keydown` handler: Down/Up move an active index (reflected via `aria-activedescendant` and a
`.fcbo-dd-active` style), Enter selects the active option (reusing `selectProduct`), Escape
closes and restores focus to the input. Update `aria-expanded` when the dropdown shows/hides.
Add a `.fcbo-dd-active` style to `bulk-order.css`.
**Patterns to follow:** the existing `renderDropdown` click-selection path (`:145`) — Enter
reuses the same `selectProduct(row, data)` call.
**Test scenarios:**
- Type a query, press Down → first option becomes active (`aria-activedescendant` set, visible
  highlight); Down again advances; Up retreats; wrap behavior is defined and consistent.
- Enter on the active option selects it exactly as a mouse click would (row populates, totals
  update).
- Escape closes the dropdown and returns focus to the input.
- Disabled (out-of-stock) options are skipped by keyboard navigation, matching the mouse path
  (`:145` excludes `.fcbo-dd-disabled`).
- Mouse selection still works unchanged (R5).
**Verification:** full keyboard-only run of search→select; a screen reader announces the
combobox and options.

### U4. Single delegated click-outside listener

**Goal:** No per-row listener accumulation; removed rows leave no residue.
**Requirements:** R4, R5.
**Dependencies:** none (can land independently; ordered after U3 to avoid churn on the same
file).
**Files:** `assets/js/bulk-order.js`.
**Approach:** Remove the `document.addEventListener('click', …)` from inside `addRow()`
(`:77`). In `init()` (`:8`), bind one document click handler that iterates open dropdowns (or
finds the `.fcbo-search-wrap` containing the target) and hides any dropdown whose wrap does not
contain `e.target`. Row removal (`:52`) then needs no listener teardown.
**Patterns to follow:** the single-bind style already used for `#fcbo-add-row` and
`#fcbo-checkout` in `init()`.
**Test scenarios:**
- Add 10 rows, remove them; only one document click listener is registered (verify via
  behavior + a one-off instrumentation check), and no detached `tr` is retained.
- Clicking outside any open dropdown closes it; clicking inside a row's search area does not
  close that row's dropdown.
- Two rows open in sequence: clicking outside closes the open one without affecting the other.
- Mouse and keyboard selection still work (R5).
**Verification:** repeated add/remove leaves listener count flat; click-outside behavior
matches today's UX.

---

## Scope Boundaries

### Deferred to Follow-Up Work
- Shipping actual `.po`/`.mo` translation files.
- Adopting a JS build pipeline / `@wordpress/i18n` package.
- Broader WCAG audit of the bulk order and product tables beyond the search widget.

### Outside this plan
- Automated JS test harness (none exists; verification is manual/behavioral).

---

## Risks & Dependencies

- **String-key drift.** Missing a `strings` key would render `undefined`; the fallback-to-
  English rule (U2) prevents that — keep it.
- **a11y regression on mouse path.** Adding keydown/ARIA must not break click selection;
  the R5 guard is repeated in U2/U3/U4 for that reason.
- No automated tests; all verification is manual, including a screen-reader pass for U3.

---

## Definition of Done

- No user-facing English literal remains hardcoded in the three JS files; the text domain
  loads.
- The search dropdown is operable by keyboard with correct ARIA, mouse behavior intact.
- Exactly one document click-outside listener exists across any number of row add/removes.
