---
title: "feat: Saved orders shortcode and one-click reorder"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: feat
depth: standard
---

# feat: Saved orders shortcode and one-click reorder

Roadmap Phase 2 · Item 2. Origin: `docs/value-roadmap.md`.

## Summary

Wholesale buyers reorder the same basket repeatedly, and a buyer typically keeps **several**
named baskets ("Weekly restock", "Monthly bulk", "Store B order"). This plan lets a permitted
user **save the current Bulk Order Form as a named saved order**, then browse all of their saved
orders on a **dedicated `[fluent_cart_saved_orders]` shortcode** rendered as an **accordion** —
one row per saved order showing its **name, creation date, item count, and total**, expanding on
click to reveal the line items. From there they can **reorder** (add every line to the cart in
one action) or **delete** the saved order. The same accordion surface also powers **one-click
reorder from a past FluentCart order**.

The accordion deliberately **reuses the variant-accordion pattern already built for the product
table** (`assets/js/product-table.js:137,157,207`; `assets/css/product-table.css:227-277`), so
saved orders look and behave like the catalog the buyer already knows. A saved order is stored as
the `{variantId, qty}[]` shape the checkout path already builds
(`assets/js/bulk-order.js:213-229`), plus a name and timestamps, so persistence is a thin layer
over an existing contract; the work is storage, authenticated per-user CRUD, resolving stored IDs
to current catalog data, and rendering that through the shared accordion.

---

## Problem Frame

- The Bulk Order Form has no memory. `handleCheckout()` assembles `items = [{variantId, qty}]`
  (`assets/js/bulk-order.js:213-229`) and discards it after redirect; nothing persists the basket,
  and there is no surface anywhere to review previously assembled orders.
- Rows are populated only through live search: `addRow()` (`assets/js/bulk-order.js:20`) creates a
  blank row and `selectProduct(row, data)` (`assets/js/bulk-order.js:158`) fills it from a search
  result carrying full variant data (`item_price`, `sku`, `stock_status`, `payment_type`,
  `bulk_tiers`, thumbnail, categories — the `fcbo_build_variant_payload()` shape, extracted in
  Plan 006). A saved order holds only IDs and quantities, so displaying **or** loading it must
  **re-resolve** each variant ID to current data first.
- The plugin already has an accordion, but only for the product table: a clickable summary row
  (`productSummaryRow()`, `assets/js/product-table.js:137`) toggles hidden detail rows
  (`variantRow()` `:157`) via `handleProductToggle()` (`:207`), styled by
  `.fcbo-pt-product-row` / `.fcbo-pt-variant-row.is-open` (`assets/css/product-table.css:227-277`).
  Nothing reuses it outside that shortcode yet.
- The REST layer exposes only read routes — `/products`, `/catalog`, and Plan 006's
  `/resolve-skus` (`fluent-cart-bulk-order.php`), all gated by `fcbo_rest_permission_check`
  (`fluent-cart-bulk-order.php:227`). There is no authenticated **per-user write** surface, and no
  route resolves a batch of **variant IDs** (Plan 006 resolves by SKU).
- There is no established use of FluentCart's **Order** model in this plugin — only the **Product**
  model (`\FluentCart\App\Models\Product`, `fluent-cart-bulk-order.php:549`). Reorder-from-past-order
  needs the current user's orders and their line items; that model's class name and relations are
  **not yet confirmed** in this codebase.

**Non-goals:** sharing saved orders between users, admin-curated/store-wide lists, scheduled or
recurring orders, editing a saved order's individual line items in place (save / replace / delete
only), renaming in place, and any cross-device sync beyond per-user WordPress storage.

---

## Requirements

- **R1.** A permitted user (administrator or Wholesale Customer — the set from
  `fcbo_get_allowed_roles()`, `fluent-cart-bulk-order.php:79`) can save the current Bulk Order Form
  as a **named** saved order from the form itself.
- **R2.** A `[fluent_cart_saved_orders]` shortcode renders that user's saved orders as an
  **accordion**: each collapsed row shows the order **name**, **creation date**, **item count**,
  and **total**; expanding a row reveals its **line items** (product/variant title, SKU, quantity,
  unit price, line total).
- **R3.** From a saved-order row the user can **reorder** (add all its line items to the cart in
  one action) and **delete** the saved order.
- **R4.** Saved orders are **strictly per user**: a user can only read, write, or delete their own.
  The owner is always the authenticated current user; no client-supplied user ID is ever trusted.
- **R5.** Displaying, loading, or reordering re-resolves stored variant IDs to **current** title,
  price, SKU, stock, payment type, and tiers, so what the buyer sees and reorders reflects today's
  catalog — never a stale snapshot frozen at save time.
- **R6.** Stale entries degrade gracefully: a variant that no longer exists is shown as
  unavailable and excluded from reorder with a visible notice; an out-of-stock variant is shown
  and flagged, never silently dropped.
- **R7.** One-click **reorder from a past FluentCart order** repopulates the cart from an order
  belonging to the current user, through the same resolve → reorder path as R5, and (optionally)
  surfaces past orders in the same accordion.

---

## Key Technical Decisions

- **KTD1 — A dedicated `[fluent_cart_saved_orders]` shortcode is the browse surface; the accordion
  is reused, not reinvented.** Register the shortcode alongside the existing two
  (`add_shortcode` calls, `fluent-cart-bulk-order.php:38-39`) and gate it with
  `fcbo_current_user_can_access()` (`fluent-cart-bulk-order.php:103`) exactly like
  `fcbo_render_product_table()`. Its markup is a table whose rows reuse the product table's
  accordion mechanic: a summary row per saved order (mirroring `productSummaryRow()`,
  `assets/js/product-table.js:137`) that toggles hidden line-item rows (mirroring `variantRow()`
  `:157`) via the same `is-open` toggle logic as `handleProductToggle()` (`:207`). To keep the two
  visually identical without drift, factor the accordion CSS block
  (`assets/css/product-table.css:227-277`) into a shared stylesheet both shortcodes enqueue, and
  reuse the same class names (or a shared `fcbo-acc-*` alias). This directly answers "how does a
  user browse multiple saved orders": they scan named accordion rows with date/count/total and
  expand the one they want.
- **KTD2 — Store saved orders in user meta, with timestamps.** Persist to `user_meta` key
  `fcbo_saved_lists` as an array of `{name, created_at, updated_at, items:[{variantId, qty}]}` on
  the current user. Per-user meta makes R4 structural — the record is keyed by user ID
  server-side, so ownership is never a request parameter. `created_at` is stamped once (shown in
  the accordion per R2) and `updated_at` on each replace; both via `current_time('timestamp')`.
  This mirrors the plugin's preference for WordPress-native storage (the role policy uses a plain
  option, `get_option('fcbo_apply_to_roles')`, `fluent-cart-bulk-order.php:1058-1060`). Name is
  the per-user unique key: saving an existing name upserts (replace), which is why in-place rename
  is a Non-goal.
- **KTD3 — Resolve line items server-side by variant ID, reusing Plan 006's payload builders.** A
  saved order stores IDs + quantities only, never prices, so every view reflects today's catalog
  (R5). Add a **batched variant-ID resolver** — one query
  `Product::published()->with(['variants' => active])->whereHas('variants', whereIn('id', $ids))`
  mirroring `fcbo_search_products` (`fluent-cart-bulk-order.php:551-567`) — that returns each
  variant via the **already-extracted** `fcbo_build_variant_payload()` and
  `fcbo_build_category_list()` helpers (added in Plan 006 for `/resolve-skus`). Plan 006 resolves
  by **SKU**; this resolves by **ID**; both share those payload builders so the row shape stays
  identical and the client renders both the same way. The `GET` list route returns each saved
  order with its items already resolved, so the client renders the accordion without a second
  round-trip.
- **KTD4 — Reorder and save reuse existing client contracts, not parallel ones.** "Save current
  order" reads non-empty rows the way `handleCheckout()` already does
  (`assets/js/bulk-order.js:213-229`). "Reorder" adds a saved order's resolved items to the cart
  through the exact sequential add + consolidation + redirect path `handleCheckout()` /
  `addItemsSequentially()` already implement (`assets/js/bulk-order.js:242-285`), including the
  subscription/one-time guard (`:236`). No second cart-add path is written.
- **KTD5 — The Order model is confirmed before it is used, not assumed.** Reorder-from-past-order
  (R7) is the only piece reaching beyond Product. The FluentCart Order/OrderItem class names and
  relations are unconfirmed here; U5 opens with an explicit discovery step (grep FluentCart's
  `app/Models` for the Order model and its line-items relation, likely `\FluentCart\App\Models\Order`
  with an order-items relation). No route calls an Order method until that step confirms the
  surface. Tracked as a risk, not baked in.

---

## Output Structure

No new directories. Changes:
- `fluent-cart-bulk-order.php` — register `[fluent_cart_saved_orders]`; the
  `fcbo_render_saved_orders()` render function; saved-order storage helpers; authenticated CRUD +
  ID-resolver routes; the "Save current order" control's config plumbing.
- New `assets/js/saved-orders.js` — fetch + accordion render + reorder/delete for the new
  shortcode.
- New `assets/css/saved-orders.css` — saved-order-specific styles.
- Shared accordion CSS extracted from `assets/css/product-table.css:227-277` into a small
  stylesheet enqueued by both the product table and saved-orders shortcodes (KTD1).
- `assets/js/bulk-order.js` + the Bulk Order Form markup in `fcbo_render_shortcode()`
  (`fluent-cart-bulk-order.php:322-361`) — the "Save current order" control.

---

## Implementation Units

### U1. Per-user storage + timestamps + sanitization helpers

**Goal:** One trustworthy read/write surface for a user's saved orders, with the metadata the
accordion displays.
**Requirements:** R1, R2, R4.
**Dependencies:** none.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** `fcbo_get_saved_lists($userId = null)` → `(array) get_user_meta($userId ?:
get_current_user_id(), 'fcbo_saved_lists', true)`, normalized to
`{name, created_at:int, updated_at:int, items:[{variantId:int, qty:int}]}`. `fcbo_save_list($name,
$items)` sanitizes: trim + `sanitize_text_field` the name (reject empty), coerce each item's
`variantId`/`qty` with `absint`, drop non-positive quantities, cap list length defensively; upsert
by name (existing name → replace, preserving original `created_at`, bumping `updated_at`; new name
→ stamp both via `current_time('timestamp')`). `fcbo_delete_saved_list($name)` removes by name. All
operate on `get_current_user_id()` only.
**Patterns to follow:** the single-purpose accessor style of `fcbo_get_bulk_pricing_roles()` and
the option accessor (`fluent-cart-bulk-order.php:1058-1060`).
**Test scenarios:**
- Save `{name:"Weekly", items:[{variantId:12, qty:5}]}`, read back → exact round-trip with a
  `created_at`.
- Re-save "Weekly" with different items → replaced, `created_at` unchanged, `updated_at` bumped,
  not duplicated.
- Save with `qty:0` / `variantId:0` items → those items dropped; empty-name save rejected.
- Read as a different user → sees none of the first user's saved orders (R4).
**Verification:** user meta holds only sanitized, owner-scoped, timestamped records across
save / replace / delete.

### U2. Authenticated CRUD + ID-resolver REST routes

**Goal:** The client can list saved orders (with resolved, current line items), create, and delete
them.
**Requirements:** R1, R2, R3, R4, R5.
**Dependencies:** U1; Plan 006's `fcbo_build_variant_payload()` / `fcbo_build_category_list()`.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** In `fcbo_register_routes()` (`fluent-cart-bulk-order.php:363`) add
`fcbo/v1/saved-lists`: `GET` → each saved order plus its items **resolved by ID** through the KTD3
batched resolver (so the accordion shows current title/SKU/price/stock and per-line/aggregate
totals); `POST` (args `name`, `items`) → `fcbo_save_list()`; `DELETE` (arg `name`) →
`fcbo_delete_saved_list()`. All use `permission_callback => 'fcbo_rest_permission_check'`
(`fluent-cart-bulk-order.php:227`) and `get_current_user_id()` for ownership. Items the resolver
can't return are marked unavailable in the payload (R6). Return the updated set on write so the
client re-renders without a second fetch.
**Patterns to follow:** the existing `register_rest_route` blocks with `args` + `sanitize_callback`
(`fluent-cart-bulk-order.php:365-399`) and Plan 006's `/resolve-skus` callback (query + payload
assembly).
**Test scenarios:**
- Logged-out → 401; logged-in non-permitted role → 403 (inherited from the gate).
- `POST` a valid order → 200; it appears in `GET` with items resolved to current data.
- A saved item whose variant was deleted → returned flagged unavailable, not omitted silently (R6).
- `DELETE` by name → gone from `GET`. A crafted `POST` carrying `user_id` → ignored; order lands on
  the caller (R4).
**Verification:** CRUD works end-to-end for the owner, line items reflect the live catalog, and
the routes are inaccessible to others.

### U3. `[fluent_cart_saved_orders]` shortcode + accordion browse + reorder/delete

**Goal:** A dedicated page where the user browses saved orders as accordions and reorders or
deletes them.
**Requirements:** R2, R3, R5, R6.
**Dependencies:** U2; the shared accordion (KTD1).
**Files:** `fluent-cart-bulk-order.php` (register + `fcbo_render_saved_orders()`),
`assets/js/saved-orders.js`, `assets/css/saved-orders.css`, shared accordion CSS.
**Approach:** Register `[fluent_cart_saved_orders]` (`fluent-cart-bulk-order.php:38-39`) and render
a gated table shell (reuse the `fcbo_current_user_can_access()` guard and the login/permission
messages from `fcbo_render_product_table()`, `fluent-cart-bulk-order.php:510-516`). Load FluentCart
cart assets (for reorder) and localize `rest_url` + `nonce` like the other shortcodes
(`fluent-cart-bulk-order.php:570-578`). `saved-orders.js` fetches `GET /saved-lists` and builds
rows reusing the product-table accordion: a **summary row** per saved order (name + caret + item
count, plus creation-date and total cells; mirrors `productSummaryRow()`,
`assets/js/product-table.js:137`) that toggles hidden **line-item rows** (product/variant title,
SKU, qty, unit price, line total; mirrors `variantRow()` `:157`) via the same `is-open` /
`data-parent` toggle as `handleProductToggle()` (`:207`). Each summary row carries **Reorder** and
**Delete** actions. **Reorder:** collect the order's resolved `{variantId, qty}`, skip unavailable
items with a notice (R6), and add to cart via the shared sequential-add path (U4/KTD4).
**Delete:** `DELETE /saved-lists`, then re-render from the returned set. Empty state ("You have no
saved orders yet") when the user has none.
**Patterns to follow:** `assembleRow()` / `renderProducts()` accordion construction
(`assets/js/product-table.js:97,172-204`); the toggle handler (`:207-224`); `showStatus()`
messaging (`assets/js/product-table.js:288`).
**Test scenarios:**
- User with three saved orders → three collapsed rows, each showing name, creation date, item
  count, total; clicking one reveals its line items (R2).
- Reorder a saved order → all available items added to cart and redirected to checkout (R3),
  reflecting current prices (R5).
- A saved order containing a since-deleted variant → that line shown as unavailable and excluded
  from reorder with a notice; others reorder fine (R6).
- Delete a saved order → row removed; remaining rows intact.
**Verification:** the shortcode lists saved orders as browsable accordions with the required
metadata, and reorder/delete work against the live catalog.

### U4. "Save current order" control in the Bulk Order Form

**Goal:** The user can name and save the basket they just assembled.
**Requirements:** R1, R4.
**Dependencies:** U2.
**Files:** `fluent-cart-bulk-order.php` (control markup + `fcboConfig`), `assets/js/bulk-order.js`.
**Approach:** Add a "Save current order" control near the existing actions block
(`fluent-cart-bulk-order.php:348-355`); the route base is already in `fcboConfig`
(`fluent-cart-bulk-order.php:314`, `rest_url` + `nonce` present). On click, read non-empty rows the
way `handleCheckout()` does (`assets/js/bulk-order.js:213-229`), prompt for a name, `POST
/saved-lists`, and confirm via `showStatus()` (`assets/js/bulk-order.js:392`). Optionally link to
the saved-orders page. Reorder/loading back happens on the U3 surface (KTD4), so the form only
needs to save.
**Patterns to follow:** `handleCheckout()` row collection (`assets/js/bulk-order.js:213-229`);
fetch-with-nonce (`assets/js/bulk-order.js:87-90`); `showStatus()` (`:392`).
**Test scenarios:**
- Fill three rows, Save as "Weekly" → success message; "Weekly" appears on the saved-orders
  shortcode with three items (end-to-end with U2/U3).
- Save with an empty table → blocked with a clear message.
- Re-save "Weekly" after changing rows → replaces (not duplicated), per U1 upsert.
**Verification:** a basket assembled in the form persists and shows up on the saved-orders page.

### U5. One-click reorder from a past FluentCart order

**Goal:** Reorder directly from one of the user's previous FluentCart orders.
**Requirements:** R7, R5, R6, R4.
**Dependencies:** U3 (shared accordion + reorder path); KTD5 discovery step.
**Files:** `fluent-cart-bulk-order.php` (route + Order query), `assets/js/saved-orders.js`.
**Approach:** **First**, confirm the Order model surface (KTD5): locate FluentCart's Order model
and its line-items relation (grep `app/Models`; expected `\FluentCart\App\Models\Order` with an
order-items relation exposing variant IDs and quantities) — do not write the query until verified.
Then add `GET fcbo/v1/past-orders` (the current user's recent orders: id, date, item count, total,
scoped to `get_current_user_id()` — R4) and `GET fcbo/v1/past-orders/{id}/items` (that order's line
items as `{variantId, qty}[]`, after asserting the order belongs to the caller). Surface past
orders in the **same accordion** on the saved-orders shortcode (a second section), and reuse U3's
resolve → reorder path so R5/R6 behavior is identical.
**Patterns to follow:** the Product query in `fcbo_search_products`
(`fluent-cart-bulk-order.php:551-567`) as the model-usage template; U3's accordion + reorder.
**Test scenarios:**
- User with a past order → it appears in the past-orders accordion; reorder repopulates the cart
  with current pricing (R5).
- Another user's order requested by id → rejected, no items returned (R4).
- A line item whose variant was since deleted → excluded with a notice; out-of-stock flagged (R6).
- User with no past orders → friendly empty state, no error.
**Verification:** a real past order reorders through the same accordion + hydration path as saved
orders, strictly owner-scoped.

---

## Scope Boundaries

### Deferred to Follow-Up Work
- Editing a saved order's individual line items in place, and renaming a saved order (this plan
  does save-as / replace / delete).
- Loading a saved order back into the Bulk Order Form for editing (this plan reorders to cart from
  the saved-orders surface; editable load-into-form is a natural follow-up).
- Surfacing saved-order management in the Phase 2 settings page (Plan 011).
- Applying order rules (minimums, case-pack multiples — Plan 009) at reorder time; quantities are
  taken as-is here and validated by whatever Plan 009 adds at checkout.

### Outside this plan
- Automated test harness (none exists; verification is manual).

---

## Risks & Dependencies

- **Unconfirmed Order model (highest risk).** Reorder-from-past-order (U5) depends on FluentCart's
  Order/OrderItem class and relation names, unestablished in this codebase (only Product is, at
  `fluent-cart-bulk-order.php:549`). KTD5 makes confirming them U5's first step; if the surface
  differs, U5 adjusts while U1–U4 (saved orders) ship independently.
- **Accordion reuse vs. drift.** KTD1 shares the product-table accordion. Extract the accordion CSS
  (`assets/css/product-table.css:227-277`) into one shared stylesheet rather than copying it, or
  the two surfaces drift. The toggle logic (`assets/js/product-table.js:207`) is small enough to
  port or share; either way both shortcodes must exercise the same `is-open` mechanic.
- **ID-resolver overlap with Plan 006.** Plan 006 resolves by SKU, this by variant ID; both must
  reuse the same `fcbo_build_variant_payload()` / `fcbo_build_category_list()` builders (already
  extracted in Plan 006) so row shapes stay identical. Add the ID query, not a second payload
  shape.
- **Stored-ID staleness.** Saved orders store IDs, not snapshots, so catalogs change under them; R5
  + R6 make this visible (unavailable/out-of-stock flags) rather than silently wrong. A deliberate
  trade (freshness over frozen prices), not a defect.
- **Ownership leakage.** Every route must derive the owner from `get_current_user_id()`, never a
  request field (KTD2/KTD5, R4). A single route trusting a client id would cross-tenant leak; the
  U2/U5 scenarios cover this explicitly.

---

## Definition of Done

- A permitted user can save the current Bulk Order Form as a named saved order.
- `[fluent_cart_saved_orders]` lists that user's saved orders as an accordion showing name,
  creation date, item count, and total; expanding a row reveals its line items.
- From a saved-order row the user can reorder (add all lines to cart) and delete it.
- Displaying and reordering re-resolve stored variant IDs to current price/title/stock/tiers via
  the shared ID resolver and Plan 006's payload builders.
- The accordion reuses the product-table accordion pattern (shared CSS + `is-open` toggle), not a
  parallel implementation.
- All saved-order and past-order routes are authenticated and strictly owner-scoped; no
  client-supplied user ID is trusted.
- Stale (deleted/out-of-stock) entries degrade gracefully with a visible notice.
- The FluentCart Order model surface is confirmed before any reorder-from-past-order query is
  written.
