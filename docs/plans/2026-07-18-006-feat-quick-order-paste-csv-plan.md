---
title: "feat: Quick order via paste/CSV"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: feat
depth: standard
---

# feat: Quick order via paste/CSV

Roadmap Phase 2 · Item 1. Origin: `docs/value-roadmap.md`.

## Summary

B2B buyers arrive with a list — usually `SKU, qty` lines from a spreadsheet or procurement
system — and today the only way to enter it into the **Bulk Order Form** is one row at a time:
type in the search box, wait for the debounced `/products` call, click the right variant, set
the quantity, repeat (`assets/js/bulk-order.js:64`, `:158`). For a 40-line order that is 40
manual searches. This plan adds a **paste box and CSV upload** that parse `SKU, qty` lines and
populate the form in one action, backed by a new **exact** batched SKU→variant resolver REST
route. It reuses the existing row-population and checkout contract rather than forking a second
order model, and reports per-line what matched, what didn't, and why. This is the
highest-leverage feature for large orders named in the roadmap.

---

## Problem Frame

- The Bulk Order Form renders an empty table with a single starter row and an "+ Add Row"
  button (`fluent-cart-bulk-order.php:323-358`, actions bar `:348-355`); every line is entered
  by hand through `addRow()` (`assets/js/bulk-order.js:20`) and `selectProduct()` (`:158`).
- The only search endpoint, `fcbo_search_products()` (`fluent-cart-bulk-order.php:402`), does
  **partial** `LIKE` matching across title, SKU, and variation title (`:416-425`) and is tuned
  for interactive autocomplete: when a product matches on title it returns *all* variants, and
  only when the match is variant-only does it narrow to the matching variant (`:451-480`). That
  is wrong for a bulk paste: a pasted SKU must resolve to exactly **one** variant, deterministically,
  and a partial `LIKE` on `ABC` would also match `ABC-1`, `ABC-2`, etc. There is no bulk/exact
  resolver.
- There is no client-side parser for pasted text or CSV, and no per-line feedback surface.

**Non-goals:** XLSX/Excel binary import, a column-mapping wizard (we assume `SKU, qty` order),
server-side file upload storage, and fuzzy SKU correction/suggestions.

---

## Requirements

- **R1.** A permitted user can paste multiple `SKU, qty` lines **or** upload a `.csv` file and
  populate the Bulk Order Form in one action.
- **R2.** Each pasted SKU resolves to **exactly one** variant by exact SKU match; a SKU that
  matches no active variant, or more than one, is not silently added.
- **R3.** SKU resolution happens in a **single batched** server round-trip regardless of line
  count (no per-line request fan-out).
- **R4.** Populated rows are indistinguishable from hand-entered rows: same `dataset` contract,
  same bulk-tier previews, and they flow through the existing consolidation + checkout path.
- **R5.** The user sees a per-line **results report**: matched (with resolved product/variant),
  unknown SKU, ambiguous SKU (one SKU → multiple variants), and invalid/blank quantity — nothing
  fails silently.
- **R6.** Parsing tolerates real-world input: an optional header row, quoted fields, `,`/`;`/tab
  delimiters, surrounding whitespace, and blank lines.
- **R7.** The new REST route enforces the same access gate as the rest of the plugin (no new
  public surface).

---

## Key Technical Decisions

- **KTD1 — New `/resolve-skus` route for exact batched resolution; do not overload
  `/products`.** `fcbo_search_products()` (`:402`) is a partial-match autocomplete and returning
  all-variants-on-title-match (`:451-480`) is correct *for search* — bending it toward exact
  bulk lookup would regress the search UX. Add `register_rest_route('fcbo/v1', '/resolve-skus',
  …)` in `fcbo_register_routes()` (`:363`) accepting a list of SKUs, doing one
  `whereHas('variants', … whereIn('sku', $skus))` query, and returning each variant keyed by its
  exact SKU. It builds directly on the variant-SKU querying introduced in Plan 003
  (`:419-424`, `:460-461`), reusing the same `esc_like`/relation style but with `whereIn` + exact
  equality instead of `LIKE`.
- **KTD2 — Exact match is case-insensitively normalized but 1:1.** Resolve on a trimmed SKU;
  treat the DB's collation as the authority for case (SKUs are typically stored case-insensitive).
  If a normalized SKU maps to **more than one** active variant, return it as **ambiguous** rather
  than guessing (R2, R5) — the response carries the candidate list so the report can name them.
- **KTD3 — Reuse the `selectProduct()` population contract; render is server-shaped like
  `/products`.** `/resolve-skus` returns the same per-variant shape `fcbo_search_products()` emits
  (`:467-478`: `id, variation_title, item_price, sku, stock_status, payment_type, manage_stock,
  available, thumbnail, bulk_tiers`) plus its parent `title`/`categories`/`thumbnail`
  (`:482-488`), so the client can call a lightly-refactored `selectProduct(row, data)` (`:158`)
  unchanged. The pricing/tier plumbing is the existing `fcbo_get_all_bulk_pricing()` (`:709`) +
  `fcbo_resolve_tiers()` (`:776`), exactly as `/products` uses it (`:435`, `:477`).
- **KTD4 — Parsing and reporting live entirely client-side.** The paste box and file reader use
  the browser `FileReader` for `.csv` text; there is **no** server-side upload (a Non-goal). The
  parser yields `{sku, qty, lineNo}` records; blank/malformed lines become report entries, never
  silent drops (R5, R6).
- **KTD5 — One row per resolved line, then reuse consolidation.** Populate N form rows (creating
  rows via the existing `addRow()` (`:20`) as needed), each through the `selectProduct()`
  contract, then let the existing `handleCheckout()` consolidation + subscription/one-time guard
  (`:207`, `:236`, `:242-254`) handle duplicates and mixed-type protection unchanged — we do not
  re-implement any of it.

---

## Output Structure

No new directories. A new REST route + resolver callback in `fluent-cart-bulk-order.php`, a new
paste/upload UI block in `fcbo_render_shortcode()`'s markup (`:323`), and a new parsing/population
module in `assets/js/bulk-order.js` (or a small sibling file enqueued alongside it at `:281`).

---

## Implementation Units

### U1. `/resolve-skus` exact batched resolver route

**Goal:** One server call turns a list of SKUs into exact variant matches (or ambiguity/miss
markers).
**Requirements:** R2, R3, R7.
**Dependencies:** none (composes with Plan 003's variant-SKU query work).
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Register `/resolve-skus` (GET or POST) in `fcbo_register_routes()` (`:363`) with
`permission_callback => 'fcbo_rest_permission_check'` (`:227`) and a `skus` arg sanitized to a
de-duplicated array of trimmed strings (cap the count, e.g. ≤500, to bound the query). In the
callback, run one query: `Product::published()->with(['detail','variants' => active+media])
->whereHas('variants', fn($q)=>$q->where('item_status','active')->whereIn('sku',$skus))` mirroring
the relation style at `:412-425`. Batch-load pricing via `fcbo_get_all_bulk_pricing($productIds)`
(`:709`) and resolve tiers with `fcbo_resolve_tiers()` (`:776`). Build a map `sku => [variant
payloads]` (payload shape per `:467-478` plus parent `title`/`categories`/`thumbnail`
`:482-488`); a SKU with exactly one entry is `matched`, with >1 is `ambiguous`, absent is
`unknown`. Return `{ resolved: { SKU: {status, product, variant|candidates} } }`.
**Patterns to follow:** `fcbo_search_products()` query + payload assembly (`:402-491`); the exact
`whereIn` idiom already used for batched meta in `fcbo_get_all_bulk_pricing()` (`:735`).
**Test scenarios:**
- A list of 3 real SKUs returns 3 `matched` entries in one query (verify via query log — R3).
- A SKU on an inactive variant → `unknown` (active filter respected).
- A SKU shared by two active variants → `ambiguous` with both candidates (R2, R5).
- Unauthenticated / non-permitted request → 401 / 403 via `fcbo_rest_permission_check` (R7).
**Verification:** each SKU class (matched/ambiguous/unknown) returns its documented status; the
route runs a single catalog query for any list size.

### U2. Paste/upload UI in the Bulk Order Form

**Goal:** A visible entry point above the order table for pasting lines or choosing a CSV.
**Requirements:** R1, R6.
**Dependencies:** none.
**Files:** `fluent-cart-bulk-order.php` (markup in `fcbo_render_shortcode()`), `assets/css/bulk-order.css`.
**Approach:** Add a collapsible "Quick order (paste or CSV)" panel above `.fcbo-table-scroll`
(`:324`): a `<textarea>` with placeholder `SKU, qty` example text, a `<input type="file"
accept=".csv,text/csv">`, and an "Add to order" button, plus an empty results-report container.
Keep it inside `#fcbo-bulk-order` so existing enqueues (`:281`) cover it; add i18n strings via the
same `esc_html_e` pattern used throughout the render (`:329-354`).
**Patterns to follow:** existing actions bar markup (`:348-355`) and status div (`:357`).
**Test scenarios:**
- Panel renders for a permitted user; absent for logged-out/denied (inherits the existing gate at
  `:261-267`).
- File input accepts `.csv` and rejects non-CSV in the picker.
**Verification:** the panel appears on the form and its controls are wired to U3.

### U3. Client-side parser + populate + report

**Goal:** Turn pasted text / CSV into resolved, populated rows with a per-line report.
**Requirements:** R1, R4, R5, R6.
**Dependencies:** U1 (resolver), U2 (UI).
**Files:** `assets/js/bulk-order.js`.
**Approach:** Parse input into `{sku, qty, lineNo}` records — split on newlines, drop blank lines,
detect and skip a header row (first line where the qty field is non-numeric), split each line on
`,`/`;`/tab, strip surrounding quotes/whitespace (R6). Collect distinct SKUs and POST them to
`/resolve-skus` (U1) in one `fetch` reusing the `X-WP-Nonce` header pattern (`:88-89`). For each
input record: if the SKU is `matched`, ensure a row exists (call `addRow()` `:20`, or reuse the
empty starter row), then call `selectProduct(row, data)` (`:158`) and set the quantity input
(`:191`, respecting the subscription lock at `:194`); if `ambiguous`/`unknown`/invalid-qty, add a
report line instead (R5). Refactor `selectProduct()` only enough to accept the resolver payload
shape (it already consumes `{title, thumbnail, categories, variant}` — `:159`). Render the report
in the U2 container: counts + per-line status. Rely on the existing `handleCheckout()`
consolidation (`:242-254`) for duplicate SKUs across lines (R4) — do not de-dupe in the parser.
**Patterns to follow:** `fetchProducts()` fetch + nonce (`:86-99`); `selectProduct()` row
population (`:158-203`); `handleCheckout()` consolidation and mixed-type guard (`:236`, `:242`).
**Test scenarios:**
- Paste `SKU1, 5` / `SKU2, 3` → two populated rows with tier previews (`:356-369`) and quantities
  5 and 3 (R4).
- CSV with a `SKU,Quantity` header row → header skipped, data rows populated (R6).
- Quoted/`;`-delimited/tab-delimited lines parse identically (R6).
- Unknown SKU, ambiguous SKU, and `SKU, abc` (bad qty) each produce a distinct report line and add
  no row (R5).
- Same SKU on two pasted lines → two rows populate, then checkout consolidates to one line item
  (R4) — unless a subscription variant, where the existing guard applies (`:221-239`).
**Verification:** a mixed paste populates exactly the matched lines, reports the rest, and reaches
checkout through the unchanged consolidation path.

---

## Scope Boundaries

### Deferred to Follow-Up Work
- Applying **Order rules** (min qty, quantity steps/case packs, min order total — Plan 009) to
  paste-populated rows: the same validation must run on rows created here, so U3 should route
  populated rows through whatever validation hook Plan 009 introduces rather than bypassing it.
- Saving a pasted list as a reusable template (**Saved lists / reorder** — Plan 007): the parsed
  `{sku, qty}` record set is the natural payload to hand to that feature.
- XLSX import and a column-mapping wizard (Non-goals) if demand appears.

### Outside this plan
- Automated test harness (none exists; verification is manual).

---

## Risks & Dependencies

- **Search-route regression risk.** The exact resolver must be a *separate* route (KTD1);
  repurposing `fcbo_search_products()` (`:402`) would regress interactive autocomplete's
  title-match-returns-all-variants behavior (`:451-480`). Keep them distinct.
- **Ambiguous SKUs are real.** Stores sometimes reuse a SKU across variants; guessing would ship
  the wrong item. R2/KTD2 make ambiguity a first-class reported outcome, not a silent pick.
- **Row-model drift.** Populated rows must use the same `dataset` contract as `selectProduct()`
  (`:170-173`) or bulk-tier previews and checkout consolidation break; U3 reuses that function
  rather than building a parallel populate path (KTD3, KTD5).
- **Depends on Plan 003's variant-SKU querying** landing (it has: `:419-424`, `:460-461`); the
  resolver reuses that relation shape with exact `whereIn` matching.

---

## Definition of Done

- Pasting `SKU, qty` lines or uploading a CSV populates the Bulk Order Form in one action, with
  tier previews, via a single batched resolver call.
- Every input line is accounted for: matched rows populate; unknown, ambiguous, and bad-quantity
  lines are reported and add nothing.
- Populated rows are indistinguishable from hand-entered rows and reach checkout through the
  existing consolidation and subscription/one-time guard, unchanged.
