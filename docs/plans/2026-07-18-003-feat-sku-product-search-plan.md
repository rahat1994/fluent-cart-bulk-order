---
title: "feat: Search products by SKU"
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
execution: code
product_contract_source: ce-plan-bootstrap
origin: docs/value-roadmap.md
date: 2026-07-18
type: feat
depth: lightweight
---

# feat: Search products by SKU

Roadmap Phase 1 · Item 3. Origin: `docs/value-roadmap.md`.

## Summary

B2B buyers order by SKU, but both FCBO search endpoints match only `post_title`. This plan
extends `/products` and `/catalog` to also match a variant's SKU (and its variation title),
mirroring the grouped `where(...)->orWhereHas('variants', ...)` pattern FluentCart already
uses in its own `ProductController`. Small query change, high daily value.

---

## Problem Frame

- `fcbo_search_products()` (`fluent-cart-bulk-order.php:199`) filters with
  `->where('post_title', 'LIKE', '%'.esc_like($search).'%')` (`:213`) — SKU is never
  consulted.
- `fcbo_list_catalog()` (`:352`) does the same (`:366`).
- SKU lives on the variant: `ProductVariation` model, table `fct_product_variations`, column
  `sku` (fillable). A product must therefore match when **any** of its variants' SKUs match.
- FluentCart's `ProductController` already models the exact shape we need
  (`app/Http/Controllers/ProductController.php:1078`): a grouped closure combining
  `post_title` LIKE with `orWhereHas('variants', …)`.

**Non-goals:** fuzzy/typo-tolerant search, relevance ranking, returning only the matched
variant (matched products still return all their active variants), searching categories.

---

## Requirements

- **R1.** A search term matching a variant SKU (exact or partial) returns that product on
  both `/products` and `/catalog`.
- **R2.** Existing title search continues to work; a term is matched if it hits title **or**
  SKU **or** variation title.
- **R3.** SKU matching respects the existing `esc_like` escaping and the active-variant
  scoping already applied to the eager-loaded `variants` relation.
- **R4.** No change to response shape — same JSON the JS already consumes.

---

## Key Technical Decisions

- **KTD1 — Group the OR inside a single `where(function …)` closure.** Wrapping
  `post_title` and `orWhereHas` in one closure keeps the OR from leaking past other query
  constraints (e.g. `published()`), exactly as `ProductController` does. A bare top-level
  `orWhere` would widen results incorrectly.
- **KTD2 — Match SKU and variation title together.** Adding `variation_title` alongside
  `sku` in the same `orWhereHas` costs nothing and matches FluentCart's own UX, where a
  buyer might type a variant name.
- **KTD3 — Keep the eager-load closure's `item_status = active` filter.** The `whereHas`
  used for *matching* is separate from the `with` used for *loading*; leave the existing
  `with(['variants' => fn($q) => $q->where('item_status','active')])` untouched so payloads
  are unchanged (R4).

---

## Implementation Units

### U1. SKU-aware matching in `/products`

**Goal:** `fcbo_search_products` matches title or variant SKU/variation title.
**Requirements:** R1, R2, R3, R4.
**Dependencies:** none.
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Replace the single `->where('post_title', 'LIKE', …)` (`:213`) with:
```
->where(function ($q) use ($search) {
    $like = '%' . $GLOBALS['wpdb']->esc_like($search) . '%';
    $q->where('post_title', 'LIKE', $like)
      ->orWhereHas('variants', function ($vq) use ($like) {
          $vq->where('item_status', 'active')
             ->where(function ($inner) use ($like) {
                 $inner->where('sku', 'LIKE', $like)
                       ->orWhere('variation_title', 'LIKE', $like);
             });
      });
})
```
(directional — mirror `ProductController:1078`). The rest of the function (tier resolution,
result mapping) is unchanged.
**Patterns to follow:** `app/Http/Controllers/ProductController.php:1078` in the FluentCart
plugin.
**Test scenarios:**
- Search an exact variant SKU → the owning product is returned with its active variants.
- Search a partial SKU substring → same product returned.
- Search a title fragment (no SKU match) → still returned (R2 regression guard).
- Search a term matching neither title nor SKU → empty `products` array.
- SKU that belongs to an *inactive* variant only → not returned (active scoping, R3).
- Search on a variable product where one variant's SKU matches → product returned once, not
  duplicated.
**Verification:** manual REST calls (authorized user) for each scenario return the expected
product set; the bulk order form's product search finds items by SKU.

### U2. Same matching in `/catalog`

**Goal:** Parity for the paginated product table.
**Requirements:** R1, R2, R3, R4.
**Dependencies:** U1 (reuse the same closure shape).
**Files:** `fluent-cart-bulk-order.php`.
**Approach:** Apply the identical grouped closure in `fcbo_list_catalog()`, replacing the
`if ($search && strlen($search) >= 2) { $query->where('post_title', 'LIKE', …); }` block
(`:365`). Keep the `strlen >= 2` guard. Pagination `count()` and `offset/limit` operate on
the widened query unchanged.
**Patterns to follow:** U1; keep the min-length guard already present here.
**Test scenarios:**
- Catalog search by SKU returns the product and paginates correctly (`total`,
  `total_pages` reflect SKU matches).
- Title search unchanged.
- 1-character search term is ignored (min-length guard intact).
- Total count matches the number of distinct products across pages for a SKU query.
**Verification:** product table search box finds products by SKU; pagination counts are
correct.

---

## Risks & Dependencies

- **Duplicate-row risk.** `whereHas` matches at the product level, so a product with several
  matching variants still returns once — confirmed by the pattern FluentCart itself uses. The
  variable-product test scenario guards this explicitly.
- **Performance.** `LIKE '%term%'` on `sku` is unindexed-scan territory, but result sets are
  capped (`limit(20)` / `per_page`) and this matches FluentCart's own approach; acceptable for
  Phase 1.
- No automated test harness; verification is manual REST + UI.

---

## Definition of Done

- Both endpoints return products matched by variant SKU (exact and partial) and by title.
- Response shape, active-variant scoping, and pagination counts are unchanged except for the
  wider match set.
