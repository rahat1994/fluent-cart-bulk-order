---
title: How to query a logged-in WordPress user's past FluentCart orders and the variants they purchased
date: 2026-07-18
category: architecture-patterns
module: fluentcart-integration
problem_type: architecture_pattern
component: database
severity: medium
applies_when:
  - Querying a logged-in WP user's FluentCart orders
  - Building one-click reorder from past purchases
  - Resolving purchased product variant ids from order items
  - Filtering paid/recent FluentCart orders
  - Mapping a FluentCart Customer to a WP user
tags:
  - fluentcart
  - order-model
  - customer
  - order-items
  - product-variation
  - wp-user
  - eloquent-relations
  - reorder
related_components:
  - FluentCart\App\Models\Order (fct_orders)
  - FluentCart\App\Models\Customer (fct_customers)
  - FluentCart\App\Models\OrderItem (fct_order_items)
  - FluentCart\App\Models\ProductVariation
---

# How to query a logged-in WordPress user's past FluentCart orders and the variants they purchased

## Context

The Bulk Order plugin needed a one-click "reorder" surface: show the current logged-in user their recent FluentCart purchases and let them re-add those exact product variants to the cart. The blocker was that FluentCart's order model is undocumented inside this plugin, and the data model is not the obvious one. Two questions had to be answered from the source before any query could be trusted:

1. How does a FluentCart `Order` connect back to a WordPress user? (It does **not** carry a `user_id`.)
2. Which order-item column holds the purchased **variant**? (It is **not** `post_id`.)

Getting either wrong does not throw — it silently returns the wrong rows or an empty set — so the answers were grounded against the model source and then verified against the live database.

## Guidance

> Source-path convention: paths under `fluent-cart/` refer to the **sibling FluentCart plugin** (`wp-content/plugins/fluent-cart/`) — a separate codebase this plugin integrates with, so those files are not in this repo. Paths shown as `fluent-cart-bulk-order.php` are this plugin's own entry file.

### The model mapping

**An Order has no WP user id. It goes Order → `customer_id` → Customer → `user_id` → WP user.**

- `Order` has a `customer_id` column (mass-assignable), not a `user_id` — `fluent-cart/app/Models/Order.php:75` (fillable) and cast to integer at `fluent-cart/app/Models/Order.php:126`.
- `Order::customer()` is `belongsTo(Customer::class, 'customer_id', 'id')` — `fluent-cart/app/Models/Order.php:249-252` (the `belongsTo` call is on line 251).
- `Customer` carries the WP `user_id` (mass-assignable) — `fluent-cart/app/Models/Customer.php:38`.
- `Customer::wpUser()` is `belongsTo(User::class, 'user_id')`, confirming `user_id` is the WordPress user foreign key — `fluent-cart/app/Models/Customer.php:391-394` (the `belongsTo` call is on line 393).

So "the current user's orders" is expressed by constraining through the customer relation:

```php
->whereHas('customer', function ($c) use ($userId) {
    $c->where('user_id', $userId);
})
```

where `$userId = get_current_user_id()`.

**An OrderItem's purchased variant is `object_id`; `quantity` is the qty; `post_id` is the product (not the variant).**

- `OrderItem` fillable includes `post_id` (`fluent-cart/app/Models/OrderItem.php:35`), `object_id` (`:40`), and `quantity` (`:42`).
- `OrderItem::variants()` is `belongsTo(ProductVariation::class, 'object_id', 'id')` — `fluent-cart/app/Models/OrderItem.php:149-152` (the `belongsTo` call is on line 151). This is the proof that `object_id` is the **variation** id.
- `post_id` is the **product** post: `OrderItem::product()` is `belongsTo(Product::class, 'post_id', 'ID')` — `fluent-cart/app/Models/OrderItem.php:142-146`.
- A second, `variation_id`-based relation exists (`createItem()` returns `belongsTo(ProductVariation::class, 'variation_id', 'id')` at `fluent-cart/app/Models/OrderItem.php:159-162`), but `variation_id` is **not** in the fillable list — `object_id` is the canonical, populated column. Use `object_id`.

**Recent paid orders.** Filter on `payment_status = 'paid'` and sort by `created_at` descending. `Order` exposes `scopeOfPaymentStatus($status)` which is just `where('payment_status', $status)` — `fluent-cart/app/Models/Order.php:339-342`; `created_at` is a real column (fillable at `fluent-cart/app/Models/Order.php:97`). `order_items()` is the `HasMany` to eager-load — `fluent-cart/app/Models/Order.php:149-152` (the `hasMany` call is on line 151).

### The verified query shape

```php
$userId = get_current_user_id();

$orders = \FluentCart\App\Models\Order::query()
    ->with(['order_items'])
    ->whereHas('customer', function ($c) use ($userId) {
        $c->where('user_id', $userId);
    })
    ->where('payment_status', 'paid')
    ->orderBy('created_at', 'desc')
    ->limit($limit)
    ->get();
```

### Turning order_items into `{variantId, qty}`

Iterate each order's `order_items` and read `object_id` (variant) and `quantity` (qty), guarding both to positive integers:

```php
foreach ($order->order_items as $it) {
    $vid = (int) $it->object_id;   // the purchased ProductVariation id
    $qty = (int) $it->quantity;
    if ($vid < 1 || $qty < 1) {
        continue;                  // skip custom/non-product or zero-qty lines
    }
    $items[] = ['variantId' => $vid, 'qty' => $qty];
}
```

To render, resolve those variant ids against the live catalog (the `product_variations` table). Ids that no longer match an active variant should be treated as unavailable rather than errored.

**Gotcha:** `object_id` can hold non-variant references for custom / non-product line items. Resolving `object_id` against the `product_variations` table naturally filters those out (an unmatched id simply resolves to nothing → mark unavailable), so the `variantId >= 1` and `qty >= 1` guard plus the resolve step is the safety net — never assume every `object_id` is a real variant.

## Why This Matters

Both mistakes fail **silently** — no exception, just wrong data:

1. **Assuming `Order` has a `user_id`.** There is no such column; the link runs through `Customer`. A query like `Order::where('user_id', get_current_user_id())` will either error on an unknown column or, worse in some query builders, quietly match nothing — you show the user an empty history and conclude "they have no orders." The correct path is always `whereHas('customer', fn ($c) => $c->where('user_id', $userId))`.

2. **Assuming the variant lives in `post_id`.** `post_id` is the **product** post; the purchased **variant** is `object_id`. Reorder built on `post_id` would re-add the product at some default/wrong variant (wrong price, wrong SKU, wrong stock), and it would look plausible enough to ship. Only `object_id` reconstructs the exact line the customer bought.

Because neither error throws, the only defense is grounding the column/relation choices in the model source (as cited above) and verifying against real data.

## When to Apply

Reach for this mapping any time you need, from PHP running inside WordPress, either:

- **A WP user's FluentCart purchase history** — reorder / "buy again", order lists in a customer dashboard, post-purchase upsell.
- **To map order line items back to product variants** — purchase-based content gating ("owns variant X"), entitlement/license checks, per-variant analytics or recommendations.

Anchor points: current user's orders = `whereHas('customer', user_id = get_current_user_id())`; each line's variant = `OrderItem.object_id`, qty = `OrderItem.quantity`, product = `OrderItem.post_id`.

## Examples

### Reference implementation

`fcbo_build_past_orders_response()` in `fluent-cart-bulk-order.php:1767` is the working end-to-end implementation:

- The owner-scoped paid-orders query: `fluent-cart-bulk-order.php:1775-1783`.
- The `object_id` → `variantId`, `quantity` → `qty` mapping with the `>= 1` guard: `fluent-cart-bulk-order.php:1789-1799`.
- Wired to REST via `fcbo_rest_get_past_orders()` (`:1837`), registered on the `fcbo/v1/past-orders` route at `fluent-cart-bulk-order.php:490-494`.
- Line items are resolved to current catalog data by `fcbo_resolve_variant_ids()` (`:1620`) and `fcbo_expand_saved_list()` (`:1677`), which flags unmatched variant ids as `available => false` — the "unavailable" handling for the `object_id` gotcha.

### Before / after (the trap)

```php
// WRONG — Order has no user_id column; silently wrong/empty.
$orders = Order::where('user_id', get_current_user_id())->get();
foreach ($order->order_items as $it) {
    $variantId = $it->post_id;   // WRONG — post_id is the PRODUCT, not the variant
}

// RIGHT — go through Customer, read object_id for the variant.
$orders = Order::query()
    ->with(['order_items'])
    ->whereHas('customer', fn ($c) => $c->where('user_id', get_current_user_id()))
    ->where('payment_status', 'paid')
    ->orderBy('created_at', 'desc')->limit(20)->get();
foreach ($order->order_items as $it) {
    $variantId = (int) $it->object_id;  // the purchased ProductVariation id
    $qty       = (int) $it->quantity;
}
```

### How it was verified

The relations were read from the model source (citations above), then the query was executed against the live DB with `wp eval`. It ran cleanly and returned 0 rows only because this store has no orders yet — no error, confirming the query shape and column names are valid.

wp-cli here floods stdout with PHP 8 "Deprecated:" notices, so the output was filtered:

```bash
wp eval '
$userId = 1;
$orders = \FluentCart\App\Models\Order::query()
    ->with(["order_items"])
    ->whereHas("customer", function($c) use ($userId){ $c->where("user_id",$userId); })
    ->where("payment_status","paid")
    ->orderBy("created_at","desc")->limit(20)->get();
foreach ($orders as $o) {
    foreach ($o->order_items as $it) {
        echo $it->object_id . " x " . $it->quantity . "\n";
    }
}
' 2>/dev/null | grep -aivE 'deprecated|dynamic property|longdesc|WP_CLI|phar://|^\s*$'
```

The `2>/dev/null | grep -aivE '...'` filter is what makes `wp eval` output readable in this environment; without it the deprecation notices bury the real result.

## Related

- [`security-issues/rest-endpoints-missing-permission-callback`](../security-issues/rest-endpoints-missing-permission-callback.md) — any past-orders/reorder REST route that surfaces a user's own orders must carry a real `permission_callback` **and** scope results to the current user (resolve `Order.customer_id → Customer.user_id === get_current_user_id()`). Reuse the plugin's `fcbo_rest_permission_check()` / `fcbo_current_user_can_access()` gate rather than trusting a client-supplied id.
- [`ui-bugs/per-page-counted-products-not-variant-rows`](../ui-bugs/per-page-counted-products-not-variant-rows.md) — shared variant-identity vocabulary: `OrderItem.object_id` is the same product-variation id the catalog/product-table renders as variant rows.
