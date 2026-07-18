---
title: "per_page looked broken: it counted products while the table rendered one row per variant"
date: 2026-07-18
category: docs/solutions/ui-bugs
module: fluent-cart-bulk-order
problem_type: ui_bug
component: frontend_stimulus
symptoms:
  - "[fluent_cart_product_table per_page=\"3\"] showed a long list, not 3 rows"
  - "per_page appeared to be ignored for products that have variants"
root_cause: logic_error
resolution_type: code_fix
severity: medium
tags: [pagination, product-table, variants, per-page, accordion, shortcode]
related_components: [rest-api, shortcodes]
---

# per_page looked broken: it counted products while the table rendered one row per variant

## Problem

`[fluent_cart_product_table per_page="3"]` rendered far more than three rows, so `per_page`
looked broken. It was actually working — the `/catalog` endpoint paginated **products**, but
the table rendered **one row per variant**, so 3 products with 6/5/4 variants became 15 rows.

## Symptoms

- `per_page="3"` produced ~15 visible rows for multi-variant products; the store owner
  reported "the per_page attribute is not working."
- `columns` and `search="false"` worked, so the shortcode was clearly being applied — only
  the row count looked wrong.
- Direct check confirmed the mismatch: `fcbo_list_catalog` returned exactly 3 products, but
  each carried its full variant list, and `product-table.js` emitted a `<tr>` per variant.

## What Didn't Work

- **Assuming `per_page` was ignored.** It wasn't — it was passed through
  (`fcboPtConfig.per_page`) and the endpoint honored it. The unit was the surprise, not the
  plumbing.
- **A `variations="dropdown|separate"` display mode** (an earlier approach on PR #5's branch,
  later reverted). It added an inline `<select>` variant picker plus a second variant-paginated endpoint
  (`fcbo_list_catalog_variants()`). It was scrapped as more machinery than the UX needed and
  because a dropdown hides the per-variant add flow.
- **Client-side row capping** (render products, then trim to `per_page` rows) — would desync
  the rendered rows from the server's product-based `total_pages`, breaking pagination.

## Solution

Render **one row per product** (so `per_page` counts visible rows) and reveal variants in an
expandable accordion. Fix is in PR #5 (branch `feat/shortcode-attributes`), unmerged to
`development` as of this writing.

- `fcbo_list_catalog` keeps paginating **products** — `offset`/`limit` on the product query
  (`fluent-cart-bulk-order.php:630`) — so `per_page` now equals product rows.
- `product-table.js` `renderProducts()` (`assets/js/product-table.js:172`) emits:
  - a single addable row for a simple product (`simpleRow`, `:120`);
  - a clickable summary row (`productSummaryRow`, `:137`, showing `from <min price>` and the
    variant count) plus one **hidden** `variantRow` (`:157`) per variant for a variable
    product. `handleProductToggle()` (`:207`) toggles the `is-open` class to expand/collapse.
- New `expand_variants="true"` shortcode attribute (`fluent-cart-bulk-order.php:474`, parsed
  `:512`, localized `:549`) renders the accordions open by default, for owners who want every
  variant shown at once.

**Gotcha found while wiring the attribute:** `wp_localize_script` serializes a boolean as the
**string** `"0"`/`"1"`, and `!!"0"` is `true` in JavaScript. Reading the flag as
`!!CONFIG.expand_variants` left expand permanently on. The flag must be compared explicitly
(`assets/js/product-table.js:11`):

```js
// wrong — !!"0" === true
var EXPAND = !!CONFIG.expand_variants;
// right
var EXPAND = CONFIG.expand_variants === '1' || CONFIG.expand_variants === 1 || CONFIG.expand_variants === true;
```

## Why This Works

The pagination unit now matches the rendering unit: one product = one row = one unit of
`per_page`. Variants are revealed on demand, so the visible row count is predictable
(`per_page="3"` is always 3 rows) regardless of how many variants a product has. This also
matches the convention every comparable product-table plugin follows (Barn2's WooCommerce
Product Table, Wholesale Suite, and the DataTables library they build on): **pagination
counts visible rows, not underlying records.**

## Prevention

- **Server-side pagination must count the same unit the client renders.** If the endpoint
  pages records A but the UI renders one row per child-of-A, `per_page` will look broken. Pick
  one unit and keep both sides on it.
- **For product/variant tables, count rows, not products** — it's the ecosystem standard and
  the least-surprising behavior. See [[plugin-public-distribution]] (this plugin ships to
  third parties, so match conventions users already expect).
- **Never trust `!!value` for a boolean passed through `wp_localize_script`** — it arrives as
  the string `"0"`/`"1"`, and `"0"` is truthy. Compare against `'1'`/`1`/`true`, or send the
  value as a real JSON bool and confirm what the client receives.
- Quick manual check for this class of bug: request the list endpoint and compare
  `count(products)` against the number of `<tr>` the table actually paints.
