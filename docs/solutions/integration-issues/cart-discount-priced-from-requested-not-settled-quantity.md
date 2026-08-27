---
title: "A transform hook that fires before the host settles its inputs prices the wrong quantity"
date: 2026-07-26
category: integration-issues
module: fluentcart-integration
problem_type: integration_issue
component: service_object
symptoms:
  - "Adding the same product twice leaves the combined quantity priced at the first add's tier — 5 then 5 more gives 10 units at the 5-unit price"
  - "The cart drawer's + button wipes a discount the line had already earned, because it posts an increment of 1"
  - "A tier boundary is never crossed when quantity is built up incrementally, only when it is set in one go"
root_cause: wrong_api
resolution_type: code_fix
severity: high
tags:
  - fluent-cart
  - wordpress
  - hooks
  - filter-timing
  - bulk-pricing
  - host-plugin-internals
related_components:
  - FluentCart\App\Models\Cart
  - FluentCart\App\Helpers\CartHelper
  - fcbo_apply_cart_bulk_pricing
---

# A transform hook that fires before the host settles its inputs prices the wrong quantity

> **Source paths.** Paths beginning `fluent-cart/` refer to the **sibling FluentCart plugin** at `wp-content/plugins/fluent-cart/` — they are not part of this repository. Paths without a prefix are repo-relative to `fluent-cart-bulk-order`.
>
> **Stability warning.** FluentCart hook names, call sites, and line numbers are host implementation detail, not a public contract. Line numbers were verified against the FluentCart source present alongside this repo on 2026-07-26. Prefer locating code by symbol.

## Problem

The plugin's quantity-based discount was bound to `fluent_cart/cart/item_modify`, which receives the quantity from the **request**. That is not always the quantity the cart line ends up with: when FluentCart is told to *add to* a line rather than *set* it, it folds the existing quantity in afterwards. The tier was therefore chosen for one quantity while the line was billed for a larger one.

## Symptoms

- Adding 5 units, then 5 more, left **10 units priced at the 5-unit tier** — no discount, because 5 matched no tier.
- The cart drawer's **+ button removed a discount the shopper had already earned**. It posts an increment of `1`, so a 10-unit line was re-priced as if it were a single unit.
- Tier boundaries were only ever crossed when a quantity was set in one action. Built up incrementally, the shopper never reached them.

The Bulk Order Form was **not** affected — it passes the third `addProduct` argument as `true` (`assets/js/bulk-order.js`, in `addItemsSequentially()`), which sets `by_input` and makes the quantity absolute. The single-product widget passes `false` (`assets/js/bulk-pricing-display.js`, in the checkout-button handler), which is what surfaced the bug.

## What Didn't Work

- **Assuming the reported symptom and this bug were the same.** The user reported that the Bulk Order Form's quoted price did not survive checkout. Tracing `by_input` showed the form sets quantity absolutely, so the fold could not explain it — that report had a different cause (an eligibility gate applied to the charge path but not the quote path). Chasing one explanation for two symptoms would have "fixed" the wrong thing and left both bugs live.

- **`fluent_cart/cart/item_dynamic_discount` as the repricing point.** It looks ideal: applied in `Cart::getEstimatedTotal()`, it receives the whole `cart_data` with final quantities. But its filtered result is assigned to a local and used only to compute the total — it is never written back to `$this->cart_data` and never persisted. Repricing there would have changed the order total while leaving every displayed line item at the old price. A hook that sees the right data is still the wrong hook if its output does not reach the state you need to change.

- **Keeping `item_modify` and adding `item_price` alongside it.** `item_modify` mutates the variation that is *then* handed to `generateCartItemFromVariation()`, so the second hook would receive an already-discounted base. Ten percent off applied twice is nineteen percent off. Any fix here had to be a move, not an addition.

- **Computing the final quantity inside `item_modify`.** The filter receives only `item_id` and `quantity`; it cannot tell an absolute set from an increment, so it cannot know whether to add the existing line quantity. The `by_input` flag that decides this is read by the caller, not passed to the filter.

## Solution

Move the discount to `fluent_cart/cart/item_price`, which fires *after* the fold:

```php
// fluent-cart-bulk-order.php, in the plugins_loaded handler
add_filter('fluent_cart/cart/item_price', 'fcbo_apply_cart_bulk_pricing', 10, 2);
```

The callback signature changes from `($variation, $context)` returning a variation, to `($itemPrice, $context)` returning integer cents:

```php
function fcbo_apply_cart_bulk_pricing($itemPrice, $context)
{
    $variation = isset($context['variation']) ? $context['variation'] : null;
    $qty       = (int) ($context['quantity'] ?? 0);   // the SETTLED quantity

    if (!$variation || empty($variation->id) || $qty < 1) {
        return $itemPrice;
    }
    // ... role gate, tier resolution ...
    $tier = fcbo_match_tier($tiers, $qty);

    return $tier ? fcbo_apply_tier_to_price((int) $itemPrice, $tier) : $itemPrice;
}
```

Two details worth copying:

- The base is `$itemPrice` — the value as filtered so far — not `$variation->item_price`. An earlier filter's adjustment is preserved rather than discarded.
- Every non-matching path returns `$itemPrice` untouched, so the callback is transparent when its rule does not fire.

## Why This Works

The relevant sequence inside `Cart::addByVariation()`:

```php
if ($prevItem) {
    if (!$byInput) {
        $quantity += (int)Arr::get($prevItem, 'quantity', 1);   // Cart.php:406
    }
    // ...
}
// ...
$item = CartHelper::generateCartItemFromVariation($variation, $quantity);  // Cart.php:449
```

and inside `CartHelper::generateCartItemFromVariation()` (`CartHelper.php:28`):

```php
$itemPrice = apply_filters('fluent_cart/cart/item_price', $variation->item_price, [
    'variation' => $variation,
    'quantity'  => $quantity,          // CartHelper.php:34 — post-fold
]);
$itemPrice = max(0, (int)$itemPrice);
// ...
'unit_price' => $itemPrice,            // CartHelper.php:48 — lands in persisted state
```

`item_modify` runs in the caller, before the fold. `item_price` runs after it, and its return value becomes the line's persisted `unit_price` directly. Between the two hooks sits the one statement that changes the number the price depends on.

Coverage is also wider, not narrower. Every path that builds a cart line **from a product variant** reaches `generateCartItemFromVariation()`: normal add and quantity update (`Cart.php:449`), the +/− buttons (same path, different increment), instant checkout (`CartHelper.php:782`), and the checkout order bump. Custom items take a separate constructor (`Cart::addByCustom()`) and are priced by whoever injected them — out of scope for tiers either way.

**One path is deliberately left uncovered.** `ProductItemService::getItem()` (`fluent-cart/app/Services/ProductItemService.php:68`) calls `item_modify` to build subscription plans for the payment gateways and never reaches `item_price`. Subscriptions are single-quantity throughout FluentCart and a bulk tier is a quantity feature, so a tier has nothing to match there unless a store sets `min_qty` to 1. This is a real gap, chosen over the double-discount hazard of binding both hooks.

## Prevention

**Classify a transform hook by lifecycle position, not just by whether its return value is used.** The sibling audit in [`architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout`](../architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout.md) established one axis — read the call site to see whether a filter can *refuse* or only *transform*. That axis correctly classified `item_modify` as transform-capable, which is all the bulk-pricing feature needed, so the hook passed the audit and shipped. This is the second axis, and it only matters once you are already transforming:

> Does the host mutate the inputs my computation depends on **after** my hook returns?

Concretely, for any value you compute inside a host filter:

1. Identify the state your filtered value lands in (here, `cart_data[].unit_price`).
2. Read every statement between your hook and that landing point.
3. If any of them changes an input your computation used — a quantity, a currency, a customer, a date — you are on the wrong hook. Move to the one closest to where the value is committed.

**Both axes are needed, and neither implies the other.** `item_modify` can transform but sees provisional inputs. `item_price` sees settled inputs but cannot refuse. A hook audit that asks only "can this hook do what I need?" and never "does it see what I need?" will keep producing bugs of exactly this shape.

**Test against the host's arithmetic, not your callback in isolation.** A test that calls the filter directly with a quantity proves nothing about which quantity the host will pass. Replay the host's real sequence — request quantity into `item_modify`, then the `!$byInput` fold, then `item_price` with the result — and assert on the resulting unit price and line total. The regression test built this way fails **6 of its 13 checks** against the pre-fix callback, which is what makes it a regression test rather than a restatement of the code.

**Watch for a hook that is a filter but not a settable one.** `item_dynamic_discount` is a genuine `apply_filters` whose result is discarded into a local. Confirm the write-back, not just the hook type.

## Related Issues

- [`architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout`](../architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout.md) — the complementary axis (veto vs transform), and the source of the audit method reused here. **Contains a claim this change invalidates:** it records `fluent_cart/cart/item_modify` as "where bulk pricing rewrites price" and cites the registration line. That is no longer true, and `fluent_cart/cart/item_price` is absent from its hook tables entirely — the audit bounded its search to veto capability and never catalogued transform hooks by timing.
- [`integration-issues/fluentcart-integration-feed-strips-undeclared-settings-keys`](./fluentcart-integration-feed-strips-undeclared-settings-keys.md) — the same shape from the settings-save side: the answer was in the *ordering* of the host's pipeline, not in this plugin's code.
- `CONCEPTS.md` → **Bulk Pricing Tier** — the entry asserting that the resolved discount applies to both the displayed price and the cart line price. This bug was one of two ways that assertion was false in practice.
- The eligibility-gate half of the same session's work — the Bulk Order Form quoted tier prices to shoppers the pricing policy excluded from receiving them — is a separate learning with a distinct root cause, not yet documented.

**Merge state:** the fix is uncommitted on branch `feat/order-rules` as of this writing. Locate the code by symbol (`fcbo_apply_cart_bulk_pricing`, `add_filter('fluent_cart/cart/item_price'`) rather than by line number.
