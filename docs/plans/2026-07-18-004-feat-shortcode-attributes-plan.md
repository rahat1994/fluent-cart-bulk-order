---
title: "feat: Configurable shortcode attributes"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: feat
depth: standard
---

# feat: Configurable shortcode attributes

Roadmap Phase 1 · Item 4. Origin: `docs/value-roadmap.md`.

## Summary

Both shortcodes are hardcoded: the product table always shows 5 rows, roles are fixed at
`administrator` + `wholesale-customer`, there is no category filter, and every column always
renders. This plan adds `shortcode_atts`-driven configuration so one plugin fits many stores,
e.g. `[fluent_cart_product_table per_page="20" category="widgets" roles="shop_manager"
columns="title,price,qty,action"]`.

---

## Problem Frame

- `fcbo_render_product_table()` (`fluent-cart-bulk-order.php:267`) takes no attributes;
  `per_page` is hardcoded to 5 in `fcboPtConfig` (`:312`), roles are a literal array
  (`:274`), all five columns are static markup (`:326-332`).
- `fcbo_render_shortcode()` (`:67`) likewise takes no attributes and hardcodes roles (`:75`).
- `fcbo_list_catalog()` (`:352`) has no category parameter, so a category filter has nowhere
  to bind.
- Store owners can't tune page size, restrict/extend access per placement, scope a table to a
  category, or hide columns they don't want.

**Non-goals:** column reordering, saved presets, a settings-page equivalent of these
attributes, sorting controls.

---

## Requirements

- **R1.** `fcbo_render_product_table` accepts `per_page`, `category`, `roles`, `columns`,
  and `search` (show/hide the search box); `fcbo_render_shortcode` accepts `roles` (and
  `redirect` for the checkout target).
- **R2.** `roles` **extends** the default allowed set for that placement; it never silently
  replaces the security baseline (admin + wholesale remain allowed).
- **R3.** `category` scopes the product table to one product category; `/catalog` gains a
  matching `category` parameter.
- **R4.** `columns` controls which of `id,title,price,qty,action` render; unknown tokens are
  ignored, empty/absent = all columns.
- **R5.** Invalid attribute values degrade safely to defaults (no fatals, no empty table from
  a typo'd category unless the category genuinely has no products).

---

## Key Technical Decisions

- **KTD1 — Reuse Item 1's `fcbo_get_allowed_roles()` seam.** The `roles` attribute passes
  extra slugs into the allowed set via the same helper/filter, so REST and UI gating stay in
  sync. If Item 1 has not landed, this plan introduces the helper (and Item 1 then reuses it);
  they must not create two copies.
- **KTD2 — Resolve `category` by slug or ID, filter via the taxonomy relation.** Accept a
  slug (author-friendly) or numeric term ID. `/catalog` filters using FluentCart's
  `product-categories` taxonomy (registered at `app/CPT/FluentProducts.php:278`) through the
  Product model's `whereHas('wpTerms'|taxonomy, …)` relation.
- **KTD3 — Columns are a server-rendered allowlist, enforced in JS too.** PHP renders only
  the requested `<th>`/`<td>` set and passes the list into `fcboPtConfig.columns`; the JS row
  builder reads that list so header and body stay aligned. Sanitize against the fixed token
  set (R4).
- **KTD4 — `search` and `redirect` are booleans/URLs parsed defensively.** `search="false"`
  hides the toolbar; `redirect` overrides the checkout URL only if it is a valid same-site
  URL, else fall back to the store checkout page (R5).

---

## Output Structure

No new directories. Changes are confined to `fluent-cart-bulk-order.php` and
`assets/js/product-table.js` (plus its config plumbing).

---

## Implementation Units

### U1. Allowed-roles extension via `roles` attribute

**Goal:** Both shortcodes honor a `roles` attribute that widens access.
**Requirements:** R1, R2.
**Dependencies:** Item 1 U1 (`fcbo_get_allowed_roles`) if present; otherwise introduce it here.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Parse `roles` with `shortcode_atts`, split on commas, sanitize each with
`sanitize_key`, and merge onto `fcbo_get_allowed_roles()` for the gate — either by passing
them into a `fcbo_current_user_can_access($extraRoles)` parameter or via a scoped
`fcbo/allowed_roles` filter for the duration of the render. Baseline roles always remain.
**Patterns to follow:** the existing `array_intersect($allowed_roles, $user->roles)` gate
(`:77`, `:276`).
**Test scenarios:**
- `roles="shop_manager"` lets a shop_manager render the table; admin and wholesale still can.
- A role not in the baseline or the attribute is still denied.
- Malformed `roles=" , ,"` degrades to the baseline set (R5).
**Verification:** each principal sees the form or the permission message per the merged set.

### U2. Product-table display attributes (`per_page`, `columns`, `search`)

**Goal:** Page size, visible columns, and search box are configurable.
**Requirements:** R1, R4, R5.
**Dependencies:** none.
**Files:** `fluent-cart-bulk-order.php`, `assets/js/product-table.js`.
**Approach:** `shortcode_atts(['per_page'=>5,'columns'=>'','search'=>'true',…])`. `absint`
+ clamp `per_page` (e.g. 1–100, matching the REST cap at `:355`) and feed it into
`fcboPtConfig.per_page`. Sanitize `columns` against `['id','title','price','qty','action']`;
render only requested `<th>` and pass the resolved list into `fcboPtConfig.columns`; update
`product-table.js` row rendering to emit only those cells. Hide the toolbar when
`search` is falsey.
**Patterns to follow:** existing `wp_localize_script('fcbo-product-table', 'fcboPtConfig', …)`
(`:308`).
**Test scenarios:**
- `per_page="20"` → 20 rows/page and pagination math reflects it; `per_page="9999"` clamps to
  the cap (R5); `per_page="abc"` falls back to default.
- `columns="title,price"` → only those header and body cells render, aligned.
- `columns="title,bogus"` → `bogus` ignored, `title` renders (R4).
- `search="false"` → no search input; table still loads and paginates.
**Verification:** rendered table matches each attribute combination; header/body columns stay
aligned.

### U3. Category filter (`category` attribute + `/catalog` param)

**Goal:** Scope the product table to a category.
**Requirements:** R3, R5.
**Dependencies:** U2 (shares `fcboPtConfig`).
**Files:** `fluent-cart-bulk-order.php`, `assets/js/product-table.js`.
**Approach:** Add a `category` arg to the `/catalog` route (`:178`) sanitized to slug-or-int;
in `fcbo_list_catalog()` resolve it to a `product-categories` term and constrain the query via
the taxonomy relation (`whereHas`), applied before `count()` so pagination is correct. Pass the
shortcode `category` into `fcboPtConfig` and include it as a query param in the JS fetch.
**Patterns to follow:** `Product::whereHas('taxonomy'|'wpTerms', …)` usage in
`fluent-cart/app/Models/Product.php:245,419`; the existing `get_the_terms($product->ID,
'product-categories')` call in this file (`:227`).
**Test scenarios:**
- `category="widgets"` → only products in that category; `total`/`total_pages` reflect it.
- `category` by numeric term ID resolves the same set.
- Unknown category slug → empty result set, no error (R5).
- No `category` attribute → unscoped catalog (unchanged).
**Verification:** table + pagination reflect the category scope; missing category degrades
gracefully.

### U4. Bulk-order `redirect` attribute

**Goal:** Per-placement checkout redirect override.
**Requirements:** R1, R5.
**Dependencies:** U1.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** `shortcode_atts(['redirect'=>''])` on `fcbo_render_shortcode`; if a valid
same-site URL is supplied, use it as `fcboConfig.checkout_url` instead of the store checkout
page (`:118`); otherwise keep the store default.
**Patterns to follow:** existing `checkout_url` resolution + `esc_url_raw` (`:102-118`).
**Test scenarios:**
- Valid `redirect` URL → checkout button navigates there.
- Empty/absent `redirect` → store checkout page used (unchanged).
- Off-site or malformed URL → rejected, store default used (R5).
**Verification:** checkout navigation targets the configured or default URL.

---

## Scope Boundaries

### Deferred to Follow-Up Work
- Exposing these same options through the Phase 2 settings page.
- Column reordering and sortable columns.

### Outside this plan
- Automated test harness (none exists; verification is manual).

---

## Risks & Dependencies

- **Shared-helper collision.** U1 depends on Item 1's `fcbo_get_allowed_roles()`. Coordinate
  landing order so only one copy exists; if this plan lands first, Item 1 reuses it.
- **Header/body column drift.** The columns allowlist must be applied in both PHP and JS from
  the same resolved list (KTD3) or cells misalign — covered by U2's alignment scenario.
- **Category taxonomy coupling.** Relies on the `product-categories` taxonomy staying
  registered by FluentCart; safe today (`app/CPT/FluentProducts.php:278`).

---

## Definition of Done

- Both shortcodes accept and correctly apply their documented attributes.
- `roles` extends (never replaces) the security baseline; `/catalog` supports `category`.
- Invalid values degrade to safe defaults with no fatals.
