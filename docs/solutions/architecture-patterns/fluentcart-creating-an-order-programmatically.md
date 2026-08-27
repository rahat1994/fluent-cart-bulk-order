---
title: How to create a FluentCart order from PHP without reimplementing checkout
date: 2026-08-27
category: architecture-patterns
module: fluentcart-integration
problem_type: architecture_pattern
component: service_object
applies_when:
  - Creating a FluentCart order from an extension (quote conversion, import, manual order)
  - Needing an unpaid order a store owner can mark paid later
  - Mapping a WordPress user to the FluentCart customer an order must belong to
  - Deciding whether a subscription line can go into a programmatically created order
tags:
  - fluentcart
  - order-creation
  - admin-order
  - offline-payment
  - customer-mapping
  - subscriptions
related_components:
  - FluentCart\Api\Resource\OrderResource (updatedPlaceOrder)
  - FluentCart\App\Helpers\AdminOrderProcessor
  - FluentCart\App\Models\Customer
  - FluentCartBulkOrder\Quotes\QuoteOrder
---

# How to create a FluentCart order from PHP without reimplementing checkout

## Context

Issue #18 (request-a-quote) needed an admin action that turns a priced quote into a real
FluentCart order. The obvious approach — insert rows into `fct_orders` and `fct_order_items`
and compute the totals — is wrong in a way that does not fail loudly: the order appears in
the list, looks plausible, and is missing a transaction, a currency, a mode, a fulfillment
type and whatever the host's next release adds. Reports and payment screens then disagree
with the order.

FluentCart already has a complete entry point for exactly this, and it is not in the
obviously-named place.

## Guidance

> Source-path convention: paths under `fluent-cart/` refer to the **sibling FluentCart plugin**
> (`wp-content/plugins/fluent-cart/`), a separate codebase this plugin integrates with. Versions
> cited are fluent-cart 1.5.5.

### Use `OrderResource::updatedPlaceOrder()`

```php
$order = \FluentCart\Api\Resource\OrderResource::updatedPlaceOrder([
    'customer_id'    => $fluentCartCustomerId,   // NOT a WP user id
    'order_items'    => $items,
    'type'           => 'payment',
    'shipping_total' => 0,                       // REQUIRED — see below
]);
```

This is the same function FluentCart's own "create order" admin screen calls. `OrderController::store()`
sanitises the request and does nothing else with it before passing it there
(`fluent-cart/app/Http/Controllers/OrderController.php:101`). Everything a manual order needs
happens inside: `AdminOrderProcessor::createDraftOrder()` writes the order and its items, creates
the pending `OrderTransaction`, and then `updatedPlaceOrder()` adds order meta, addresses, tax and
the payment-gateway hand-off (`fluent-cart/api/Resource/OrderResource.php:236-296`).

**The obviously-named alternatives are traps.** `FluentCart\Api\Orders::create()` calls
`(new Order())->store($data)` — a method that does not exist on the model — and nothing in
FluentCart calls it, so it is dead code.

### What the resulting order looks like

`AdminOrderProcessor::prepareOrderData()` (`fluent-cart/app/Helpers/AdminOrderProcessor.php:230+`) fixes:

- `status` = `on-hold` (`Status::ORDER_ON_HOLD`)
- `payment_method` = `offline_payment`, hardcoded in `updatedPlaceOrder()` at line 240
- a transaction with `status` = `pending` for the full amount

So it is an **unpaid** order the owner marks paid from FluentCart's own screen. That is the right
shape for a quote; if you need a paid order you need a different path.

### `shipping_total` is required even when it is zero

Omitting it is a **fatal `TypeError`**, not a zero:

```
Unsupported operand types: int + array
  fluent-cart/app/Helpers/AdminOrderProcessor.php:282
```

`updatedPlaceOrder()` forwards `Arr::get($data, 'shipping_total', [])` into the processor's args
(`OrderResource.php:263`) — note the **array** default. `AdminOrderProcessor::prepareOrderData()`
then reads it back with `Arr::get($this->args, 'shipping_total', 0)`, which returns the array
because the key now exists, and adds it to the integer subtotal (`AdminOrderProcessor.php:282`).

FluentCart's own admin screen always sends a number, so the host never reaches it. An extension
that reads the signature and concludes the key is optional gets a white screen mid-action.

Pass `0` unless the order really has shipping. Nothing else in the payload has this shape — it is
worth checking every default before trusting one.

### Two error shapes, and only one is caught for you

`updatedPlaceOrder()` calls `OrderService::validateProducts($items)` **outside** its own
try/catch (`OrderResource.php:244`). That method **throws** `\Exception` for an unavailable or
out-of-stock product (`fluent-cart/app/Services/OrderService.php:123-147`). Everything after it
returns a `WP_Error` instead. So a caller has to do both:

```php
try {
    $order = OrderResource::updatedPlaceOrder($data);
} catch (\Exception $e) { /* unavailable product */ }

if (is_wp_error($order)) { /* everything else */ }
```

### `customer_id` is a FluentCart customer, not a WP user

`updatedPlaceOrder()` resolves it with `CustomerResource::find($data['customer_id'])` and
dereferences the result, so a missing customer is a fatal, not a refusal. Resolve or create one
first. The host's own logic (`fluent-cart/api/Resource/CustomerResource.php:398-435`) is:
match `Customer.user_id`, else match `Customer.email` and adopt the user id, else create.

**Do not use `CustomerResource::getCurrentCustomer()` for this.** It answers about whoever is
logged in — which, for an admin action on someone else's order, is the store owner. The result
is a valid-looking order billed to the wrong person.

### Subscriptions are refused, and the refusal is filterable

`OrderController::store()` rejects any item with `payment_type == 'subscription'` unless the site
returns true from `fluent_cart/order/is_subscription_allowed_in_manual_order`, which defaults to
false (`OrderController.php:82-96`). `updatedPlaceOrder()` itself does **not** check, so an
extension that calls it directly will happily create a manual subscription order the host says it
does not support. Ask the same filter, with the same default, before calling.

### Item keys

`AdminOrderProcessor::prepareOrderItems()` reads:

| key          | meaning                                                  |
|--------------|----------------------------------------------------------|
| `post_id`    | the **product** post id                                  |
| `object_id`  | the **product variation** id — this is what was bought   |
| `quantity`   | units                                                    |
| `unit_price` | integer **minor units** (cents); multiplied by quantity  |
| `other_info.payment_type` | `default` / `subscription` / `signup_fee`   |

`post_title` and `title` are looked up from the product and variation when omitted, so passing
them is a snapshot convenience rather than a requirement. `object_id` versus `post_id` is the same
distinction documented in
[`fluentcart-order-model-user-orders-and-variants`](fluentcart-order-model-user-orders-and-variants.md).

### How this was verified

Against the live dev store with `wp eval-file`, converting a real quote:

```
order: status=on-hold payment_status=pending method=offline_payment total=2400 customer=1
  item object_id=1 post_id=25 qty=3 unit=800 line_total=2400
```

The quoted unit price (800) times the quantity (3) is the order total, the order is unpaid, a
pending transaction exists, and the FluentCart customer resolves back to the buyer's WP user.
The `shipping_total` fatal above was found by exactly this run — a code reading of the signature
had not predicted it, which is the argument for running the conversion once against real data
before shipping it.

wp-cli here floods stdout with PHP 8 deprecation notices, so filter them:

```bash
wp eval-file check.php 2>&1 | grep -aiv 'deprecated|dynamic property|longdesc'
```

## Why This Matters

Every failure mode here is silent:

- Hand-rolled inserts produce an order with no transaction row, so FluentCart's payment screens
  have nothing to act on and the order can never be marked paid through the UI.
- `getCurrentCustomer()` produces an order attached to the admin. It is a valid order. Nothing
  errors. The buyer never sees it in their account and the store's customer stats are wrong.
- A thrown `\Exception` from `validateProducts()` is an uncaught fatal in an `admin_post_` handler,
  which the admin sees as a white screen mid-action with no idea whether the order was created.

## When to Apply

Any time an extension has to create a FluentCart order without a shopper going through checkout:
quote conversion, a CSV order importer, a "reorder for this customer" admin action, a migration
from another cart.

If instead the shopper is present and paying now, that is checkout — use the cart and
`CheckoutApi`, not this.

## Examples

`\FluentCartBulkOrder\Quotes\QuoteOrder` (`includes/Quotes/QuoteOrder.php`) is the working
implementation: `create()` holds the guard order (FluentCart present → lines exist → subscription
filter → customer resolved → items built → call), `orderItems()` holds the key mapping, and
`customerIdFor()` holds the user-to-customer lookup.

## Related

- [`fluentcart-order-model-user-orders-and-variants`](fluentcart-order-model-user-orders-and-variants.md)
  — the read side of the same model: `Order → customer_id → Customer → user_id`, and
  `OrderItem.object_id` as the purchased variant.
- [`fluentcart-veto-capable-hooks-for-cart-and-checkout`](fluentcart-veto-capable-hooks-for-cart-and-checkout.md)
  — order rules are enforced on the cart path, which a programmatically created order does not
  travel; anything created this way is the extension's own responsibility to validate.
