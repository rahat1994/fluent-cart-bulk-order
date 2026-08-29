---
title: "A host cookie and a host URL parameter shared a name but not a meaning, so echoing one back emptied the cart"
date: 2026-08-29
category: integration-issues
module: fluentcart-integration
problem_type: integration_issue
component: frontend_script
symptoms:
  - "A shopper who filled the Bulk Order Form arrives at checkout showing 'Your cart is empty.'"
  - "Reorder from Saved Orders lands on an empty checkout even though the items really did go into the cart"
  - "Removing ?fct_cart_hash=... from the checkout URL brings the whole order back, unchanged"
root_cause: wrong_api
resolution_type: code_fix
severity: high
tags:
  - fluent-cart
  - wordpress
  - checkout
  - cookies
  - url-parameters
  - host-plugin-internals
related_components:
  - assets/js/bulk-order.js
  - assets/js/saved-orders.js
  - assets/js/bulk-pricing-display.js
  - FluentCart\Api\Resource\FrontendResource\CartResource
  - FluentCart\App\Helpers\CartHelper
---

# A host cookie and a host URL parameter shared a name but not a meaning, so echoing one back emptied the cart

> **Source paths.** Paths beginning `fluent-cart/` refer to the **sibling FluentCart plugin** at `wp-content/plugins/fluent-cart/` — they are not part of this repository. Paths without a prefix are repo-relative to `fluent-cart-bulk-order`.
>
> **Stability warning.** FluentCart class names, call sites and line numbers are host implementation detail, not a public contract. Verified against the FluentCart source present alongside this repo on 2026-08-29. Prefer locating code by symbol.

## Problem

Both of this plugin's "go to checkout" redirects read the browser cookie
`fct_cart_hash` and appended its value to the checkout URL as a query argument
of the same name:

```js
// assets/js/bulk-order.js — removed in issue #41
var cartHash = getCookie('fct_cart_hash');
if (cartHash) {
    var separator = checkoutUrl.indexOf('?') !== -1 ? '&' : '?';
    checkoutUrl += separator + 'fct_cart_hash=' + encodeURIComponent(cartHash);
}
```

The name is the same on both sides. The meaning is not.

- **As a cookie**, `fct_cart_hash` identifies the shopper's ordinary cart — the
  one with `cart_group = 'global'`. `Cookie::getCartHashKey()`
  (`fluent-cart/api/Cookie/Cookie.php`) is the only key FluentCart ever writes a
  cart hash to, and the only cart it ever writes there is a global one
  (`CartResource::getOrSetCartForThisDevice()`, at its `Cookie::setCartHash()`
  call).
- **As a URL parameter**, `fct_cart_hash` is
  `Helper::INSTANT_CHECKOUT_URL_PARAM` (`fluent-cart/app/Helpers/Helper.php`) —
  it means "here is the id of an *instant checkout* cart", the throwaway
  one-line cart that FluentCart's own Buy Now route creates.

So the plugin was answering a question nobody asked, with the id of a cart the
answer is never looked for in.

## Symptoms

With two lines really present in the cart, the same cart and the same cookie:

| URL | What the shopper sees |
|---|---|
| `/checkout/?fcbo_src=bulk_order_form` | both lines, subtotal, **Place Order** |
| `/checkout/?fcbo_src=bulk_order_form&fct_cart_hash=<cookie value>` | **"Your cart is empty."** + Continue Shopping |

Identical for the Saved Orders reorder path with `fcbo_src=saved_orders`. The
items were in the database the whole time; only the lookup failed.

## Root cause

`CartResource::get()`
(`fluent-cart/api/Resource/FrontendResource/CartResource.php`):

```php
$autoCreate = Arr::get($params, 'create', false);
$cartHash   = Arr::get($params, 'hash');

if ($cartHash) {
    $cartQuery = static::getQuery()
        ->where('cart_hash', $cartHash)
        ->where('stage', '!=', 'completed')
        ->where('cart_group', 'instant');   // <-- instant only

    $tempCart = $cartQuery->first();
    $cart = $tempCart;

    if (!$autoCreate) {
        return $tempCart;                    // <-- null, and it is returned
    }
}

$cart = static::getOrSetCartForThisDevice($autoCreate);
```

Two facts turn that into an empty checkout.

1. **The hash comes from the URL for every caller, not just the instant one.**
   `CartHelper::getCart($hash = null, $create = false)` fills the hash from
   `App::request()->get(Helper::INSTANT_CHECKOUT_URL_PARAM)` whenever the caller
   passes none. Nearly every cart read on a rendered page goes through it.

2. **`$autoCreate` is false on every checkout render path**, so the early
   `return $tempCart` — the null — is the answer the renderer gets:
   - `fluent-cart/app/Hooks/Handlers/ShortCodes/Checkout/CheckoutPageHandler.php`
     — `CartHelper::getCart()`, then `if (!$cart …) return renderEmpty();`
     (the `[fluent_cart_checkout]` shortcode, the default checkout page)
   - `fluent-cart/app/Hooks/Handlers/BlockEditors/Checkout/InnerBlocks/InnerBlocks.php`
     — `CartHelper::getCart()` (block checkout)
   - `fluent-cart/app/Hooks/Handlers/ShortCodes/CartShortcode.php`
   - `fluent-cart/app/Hooks/Cart/CartLoader.php` and
     `MiniCartBlockEditor.php` — `CartHelper::getCart(null, false)`

   The `create => true` callers are the *write* paths (`CartResource::create()`,
   the REST add-to-cart), and they pass no hash at all. So the question
   "does this fail on every path?" has the blunt answer **yes, on every path
   that renders a cart with the parameter in the URL** — there is no read path
   that would fall through to `getOrSetCartForThisDevice()` and rescue it.

**The two meanings can never coincide.** `CartCookieHandler` mints the cookie on
`init` as a plain random device id, before any cart exists.
`CartResource::generateCartForInstantCheckout()` gives the instant cart a *fresh*
`cart_hash` and never writes it to the cookie — FluentCart's own instant route
carries that hash forward in PHP instead
(`fluent-cart/app/Http/Routes/WebRoutes.php`, where it sets
`$queryArray['fct_cart_hash'] = $cart->cart_hash` just before `wp_redirect`).
So a cookie value is never an instant hash. This was not a race, a cache, or a
version drift. It could not have worked.

## Why it was there

`git log -S 'fct_cart_hash'` puts the Bulk Order Form's copy in the **initial
commit** and the Saved Orders copy in the commit that added that feature — the
second is a copy of the first. There is no commit where it fixed anything, and
the comment above it said only "Append cart hash from cookie if available".

The likely source is FluentCart's own instant-checkout redirect (`WebRoutes.php`
above), which really does append `fct_cart_hash` before sending the browser to
the checkout page. Copying the shape of that URL without its precondition — that
the hash came from an instant cart PHP had just created — is the whole bug. It
survived because it is invisible in the common case: FluentCart also reads its
cookie, so on any store where the parameter happened to be dropped, or during
any test that watched the cart rather than the checkout page, everything looked
right.

## Solution

Delete the append from both redirects and send nothing.

```js
// assets/js/bulk-order.js and assets/js/saved-orders.js
window.location.href = checkoutUrl;   // the only query arg is fcbo_src, set by PHP
```

With no `hash` in `$params`, `CartResource::get()` skips the instant branch
entirely and falls through to `getOrSetCartForThisDevice()`, which reads the
cookie itself and finds the global cart these surfaces just filled.

`getCookie()` became unused in both files and was removed with it, so there is
nothing left to tempt a re-add. A long comment now sits at each redirect naming
the failure, and `assets/js/bulk-pricing-display.js` — the third surface, which
never sent the parameter — records that all three now agree.

## Prevention

**A shared name is not a shared contract.** Before echoing a host's value back
to the host, check the *reader*, not the writer. Here the cookie writer and the
URL reader were in different files, in different layers, with different scopes,
and the only thing they had in common was the string `fct_cart_hash`. One grep
for the constant (`INSTANT_CHECKOUT_URL_PARAM`) would have shown that the URL
side is named for instant checkout and the cookie side is not.

**Passing an identifier is a stronger claim than passing nothing.** Omitting the
parameter let FluentCart answer "which cart?" with its own, correct, mechanism.
Supplying it overrode that answer with a worse one. When a host already knows
something, telling it again can only ever be neutral or wrong. The same shape
appears in
[`architecture-patterns/wrapper-must-omit-unset-shortcode-attributes`](../architecture-patterns/wrapper-must-omit-unset-shortcode-attributes.md)
and in **CONCEPTS.md → Store Default**: passing a value nobody chose reads as a
deliberate choice.

**Verify the destination, not the departure.** Both surfaces had been tested by
checking that the lines landed in the cart. They did. The bug lived one page
later, in what the checkout page decided to render — which is why
`docs/testing/manual-test-plan.md` §4.13a and §6.5a now ask the tester to read
the checkout URL and confirm the lines are on screen, not just that the add
succeeded.

**The parameter has side effects beyond the lookup, so "harmless if ignored" was
never true either.** Its mere presence flips
`AssetLoader`'s `is_instant_checkout` flag, and it relaxes a guard in
`CartResource::validateShouldAddProduct()` that otherwise refuses subscription
items. A parameter that means "I am in instant checkout" should only be sent by
something that is.

## Related Issues

- [`integration-issues/cart-discount-priced-from-requested-not-settled-quantity`](./cart-discount-priced-from-requested-not-settled-quantity.md)
  — same family: the answer was in *when and how the host reads* a value, not in
  the value this plugin produced.
- [`architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout`](../architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout.md)
  — the habit of reading the host's call site before relying on it.
- `CONCEPTS.md` → **Bulk Order Attribution** — `fcbo_src` is the one query
  argument these redirects are supposed to carry, and it is added by PHP.
