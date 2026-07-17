# Fluent Cart Bulk Order — Value Maximization Roadmap

> A phased plan for how this plugin can deliver maximum value to its customers.
> Customers are **B2B/wholesale store owners on FluentCart** and, indirectly, **their buyers**.
> "Maximum value" therefore means: help buyers place large orders faster, and give
> owners control over who gets what price.

## Where the plugin stands today

The plugin already covers a solid core:

- Two shortcodes: a role-gated **bulk order form** (`[fluent_cart_bulk_order]`) with live
  tier-discount previews, and a paginated **product catalog table** (`[fluent_cart_product_table]`).
- A **Bulk Pricing integration** with admin-configurable quantity tiers, both global and per-product
  (with per-variant targeting).
- **Single-product tier display** rendered after the quantity block.
- **Server-side discount application** in the cart via the `fluent_cart/cart/item_modify` filter.
- A dedicated **`wholesale-customer` role**, registered on activation.

The gaps below are ordered by leverage: protect what exists, then add the features wholesale
buyers judge the experience by, then differentiate.

---

## Phase 1 — Protect the value that already exists

Quick, high-impact, well-contained fixes.

### 1. Lock down the REST API
Both `/products` and `/catalog` use `permission_callback => '__return_true'`
(`fluent-cart-bulk-order.php:169,181`), so anyone — logged out included — can pull the full
catalog **with wholesale tier data**, even though the UI is role-gated. This is the single most
important fix: mirror the `administrator`/`wholesale-customer` check in the permission callback.

### 2. Make discount gating consistent
`fcbo_apply_cart_bulk_pricing` applies tiers to *every* shopper, regardless of role. The
single-product tier display is likewise public. Add a setting: "Apply bulk pricing to:
everyone / selected roles only," and gate both the cart filter and the public tier display on it.

### 3. Search products by SKU
B2B buyers order by SKU, but search only matches `post_title`. A small query change with
outsized daily value.

### 4. Shortcode attributes
`per_page` is hardcoded to 5, roles are hardcoded, there is no category filter and no column
toggles. Support attributes like
`[fluent_cart_product_table per_page="20" category="widgets" roles="shop_manager"]`
so one plugin fits many stores.

### 5. Polish for trust
- Translate the hardcoded English strings in the JS (i18n via `wp_localize_script` / `wp.i18n`).
- Add keyboard navigation and ARIA to the search dropdown.
- Fix the per-row `document` click listeners that accumulate as rows are added/removed.

---

## Phase 2 — The features that define a bulk-order plugin

What wholesale buyers actually judge the experience by.

1. **Quick order via paste/CSV.** Paste `SKU, qty` lines or upload a CSV to populate the table in
   one action. The highest-leverage feature you can add for large orders.
2. **Saved lists and one-click reorder.** Store named order templates per user; offer "reorder"
   from past FluentCart orders.
3. **Richer pricing rules.** Tiers are percent-only (hardcoded in `validateFeedData`). Add fixed
   unit price and fixed amount-off tiers, then **per-role price lists** — the feature that turns
   this from "bulk discounts" into a true wholesale plugin.
4. **Order rules.** Minimum quantity per product, quantity steps/multiples (case packs of 6, 12…),
   and minimum order total for wholesale roles, with clear inline messaging.
5. **Savings messaging.** Show "You saved $X" in cart/checkout and "Add 3 more to unlock 10% off"
   nudges in the order table.
6. **A real settings page** consolidating roles, redirect target, columns, and guest tier
   visibility, instead of hardcoded values.

---

## Phase 3 — Differentiation and ecosystem play

1. **Gutenberg block + Elementor widget** wrappers for both shortcodes.
2. **Wholesale application flow.** A registration/request form → owner approves → user gets the
   `wholesale-customer` role. Pair with **FluentCRM integration** (tagging, automations) to stay
   inside the Fluent ecosystem.
3. **Request-a-quote (RFQ).** Submit the filled table as a quote instead of checking out; owner
   reviews and converts to an order. Also sidesteps the current "can't mix subscription and
   one-time products" dead end.
4. **Checkout extras B2B buyers expect.** PO number field, export order as CSV/PDF.
5. **Owner-side analytics.** Bulk-order revenue, top wholesale customers, tier utilization.

---

## Suggested order of attack

If forced to pick three things first:

1. REST permission fix (protects existing customers).
2. SKU search (felt every day).
3. Paste/CSV quick order (felt every day).
