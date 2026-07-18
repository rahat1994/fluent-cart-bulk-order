---
title: "Auditing a host plugin's hooks for veto capability before promising server-side enforcement"
date: 2026-07-19
category: architecture-patterns
module: fluentcart-integration
problem_type: architecture_pattern
component: service_object
severity: high
applies_when:
  - "Extending a host plugin or framework you cannot modify, where enforcement must survive a crafted request"
  - "The extension has no server-side entry point of its own because the host owns the request path"
  - "A rule must reject an operation rather than transform its inputs"
  - "Choosing between several plausible-sounding host hooks for the same enforcement point"
tags:
  - fluent-cart
  - wordpress
  - hooks
  - server-side-validation
  - host-plugin-internals
  - veto-hooks
  - order-rules
related_components:
  - FluentCart\App\Models\ProductVariation
  - FluentCart\App\Models\Cart
  - FluentCart\Api\Checkout\CheckoutApi
  - fcbo_validate_cart_item_rules
  - fcbo_validate_checkout_minimum
---

# Auditing a host plugin's hooks for veto capability before promising server-side enforcement

> **Source paths.** Paths beginning `fluent-cart/` refer to the **sibling FluentCart plugin** at `wp-content/plugins/fluent-cart/` — they are not part of this repository. Paths without a prefix (e.g. `fluent-cart-bulk-order.php`) are repo-relative to `fluent-cart-bulk-order`.
>
> **Stability warning.** Everything below about FluentCart's internals — hook names, call sites, line numbers, and especially *how each call site consumes the filter's return value* — is host-plugin implementation detail, not a documented public contract. None of it is guaranteed across a FluentCart upgrade. Re-run the verification recipe at the end of this note after any host upgrade rather than trusting these line numbers indefinitely.

## Context

The plugin needed to enforce three "order rules" server-side: a per-variant minimum quantity, a per-variant case-pack step (quantities must be multiples of N), and a store-wide minimum order total. The requirement was specifically that a request crafted to bypass the UI still be refused — client-side quantity correction alone was explicitly not the deliverable.

Two facts made this the highest-risk item in the plan, and the reason it was sequenced as the gating first work item:

**1. The only FluentCart integration point the plugin already used cannot reject anything.** `fluent_cart/cart/item_modify` is what the existing bulk-pricing feature binds to (`fluent-cart-bulk-order.php:65`). It hands you the variation object so you can *rewrite the line's price*. Its return value is consumed as the variation to proceed with — there is no path by which returning an error from it stops the add. It transforms; it does not veto.

**2. This plugin has no server-side add-to-cart entry point of its own.** The front-end calls `window.fluentCartCart.addProduct()` — FluentCart's own JS, hitting FluentCart's own handler. There is no in-house REST route, no in-house controller, no in-house call site at which a "validate before adding" check could have been bolted on as a fallback. If no veto-capable host hook existed, server-side enforcement was simply not achievable without forking the host or intercepting its AJAX action wholesale.

So the entire feature hinged on a question that could only be answered by reading the host's source: **does FluentCart expose any filter whose return value is actually honored as a refusal?**

**Nobody had asked this before, and that itself is informative.** (session history) Earlier sessions on this plugin traced FluentCart's cart write path — identifying the `fluent_cart_cart_update` AJAX action and confirming the plugin's discount filter fires on it — but always framed as *"where does our price transformation apply?"*, never *"can we abort here?"*. The one existing cart-path gate, the Plan 002 role policy around `fcbo_apply_cart_bulk_pricing`, gates *whether a discount is applied*, not whether the item may enter the cart: a non-qualifying shopper simply pays full price. So the plugin's entire accumulated understanding of its own integration surface was transform-shaped, and it silently carried the assumption that transformation was all the host offered. That assumption was never tested until a requirement demanded refusal.

A related discovery, worth stating early because it costs people time: **FluentCart does not register a REST route for cart operations.** There is an explicit comment at `fluent-cart/app/Http/Routes/frontend_routes.php:27` — "add_item removed — cart updates go through the WordPress AJAX handler … not this REST route" — sitting inside an otherwise empty `cart` route group. Cart updates arrive at `wp_ajax_fluent_cart_checkout_routes` / `wp_ajax_nopriv_fluent_cart_checkout_routes` (`fluent-cart/app/Hooks/Cart/WebCheckoutHandler.php:40-41`), dispatching on an `fc_checkout_action` request parameter (`:73`). Anyone who starts this investigation by looking for a REST route to wrap will find nothing and may wrongly conclude there is no interception point at all.

## Guidance

### The transferable method

When you need to enforce something inside a host you do not control, do not search the host for hook *names* that sound like validation. Search for hook *call sites* and classify each by how its return value is consumed. The name tells you what the author was thinking about; only the call site tells you what the hook can do.

The audit, concretely:

1. **Bound the search by request path, not by name.** Grep `apply_filters(` and `do_action(` across only the files that participate in the operation you want to gate — for this work, the host's cart model, cart resource, checkout API, and the AJAX handler. A repo-wide grep for "validate" would have surfaced dozens of irrelevant admin-side hooks and missed the one that mattered, which is named for bundles.

2. **Discard every `do_action` immediately.** WordPress actions have no return value. An action can log, can enqueue, can mutate an object passed by reference — it can never refuse. This alone eliminated most candidates in FluentCart's cart path.

3. **For each `apply_filters`, read the lines *after* the call, not the lines before.** This is the whole technique. Three possible verdicts:
   - The returned value is tested for a refusal shape (`is_wp_error(...)`, `=== false`, a non-empty error array) and an early `return` / `wp_send_json(..., 4xx)` follows → **can veto**.
   - The returned value is assigned onward and used as data → **transform only**.
   - The returned value is not assigned at all → **ignored**; the hook is informational despite being a filter.

4. **Record what the refusal shape is, precisely.** "Can veto" is not enough. FluentCart's `canPurchase()` honors both `WP_Error` and bare `false`, but treats them differently — see Examples. A hook that only honors one shape will silently swallow the other.

5. **Record what the hook receives.** A whole-cart rule is unimplementable at a hook that only receives a single variation, no matter how effectively that hook can veto. This is what decided the order-total rule between two otherwise-equivalent checkout hooks.

6. **Map coverage — which call paths reach this hook.** A hook inside a shared model method covers every caller of that method. A hook behind a config flag covers only callers that set the flag. Determine this by finding the callers, not by assuming.

7. **Prove the veto against the host's real code**, not against a mock of your own filter. Step 3 establishes intent from reading; only invoking the host's own method establishes behavior. The recipe is in Examples.

### FluentCart hook reference — cart and checkout paths

Verified against the FluentCart source present alongside this repo on 2026-07-19.

#### Can veto

| Hook | Call site | Refusal shape honored | Receives | Coverage |
| --- | --- | --- | --- | --- |
| `fluent_cart/variation/can_purchase_bundle` | `fluent-cart/app/Models/ProductVariation.php:270` | `WP_Error` (returned verbatim, `:274`); bare `false` also vetoes but the message is **replaced** with FluentCart's generic out-of-stock text (`:276`) | `['variation' => ProductVariation, 'quantity' => int]` | Widest. Fires inside the generic `ProductVariation::canPurchase()` (`:243`), so it reaches every caller of that method — see coverage notes below. |
| `fluent_cart/cart/can_purchase` | `fluent-cart/app/Models/Cart.php:429` | `WP_Error` (`:434-435`, returned out of `addByVariation`) | `['cart' => Cart, 'variation' => ProductVariation, 'quantity' => int]` | Narrow. Guarded by `$validate = Arr::get($config, 'will_validate', false)` (`:393`, branch at `:427`). Only the live cart-update path sets it (`fluent-cart/api/Resource/FrontendResource/CartResource.php:363`). Not reached by `addByCustom` (`Cart.php:458`). |
| `fluent_cart/checkout/validate_data` | `fluent-cart/api/Checkout/CheckoutApi.php:1039` | non-empty returned `$errors` array → `WP_Error('validation_error', …, $errors)` at `:1045` | `['data' => array, 'cart' => Cart]` | Checkout only, but **receives the resolved cart** — the only audited veto hook that does. |
| `fluent_cart/checkout/validate_before_process` | `fluent-cart/api/Checkout/CheckoutApi.php:114` | `WP_Error` → `wp_send_json(..., 403)` at `:117`, which dies | `$data` only | Checkout only, and **no cart**. A cart-total rule bound here would have to re-fetch the cart itself. |

#### Cannot veto

| Hook | Call site(s) | Why not |
| --- | --- | --- |
| `fluent_cart/cart/item_modify` | `CartResource.php:48`, `CartResource.php:328`, `fluent-cart/app/Services/ProductItemService.php:68` | Return value is assigned back as `$variation` and used. Transforms the line (this is where bulk pricing rewrites price); cannot refuse it. |
| `fluent_cart/item_max_quantity` | `CartResource.php:58` | Return value is assigned back as `$quantity`. Can clamp a quantity; cannot reject the add. |
| `fluent_cart/cart/item_added` | `Cart.php:322` | `do_action` — no return value. |
| `fluent_cart/cart/item_removed` | `Cart.php:362` | `do_action`. |
| `fluent_cart/cart/cart_data_items_updated` | `Cart.php:327`, `:374`, `:602`, `:650`, `:695` | `do_action`. |
| `fluent_cart/checkout/cart_amount_updated` | `Cart.php:369`, `:597`, `:645`, `:691`, `WebCheckoutHandler.php:671`, `:1115` | `do_action`. |
| `fluent_cart/cart/before_totals_calculation` | `Cart.php:1172` | `do_action`. |
| `fluent_cart/cart/after_totals_calculation` | `Cart.php:1222` | `do_action`. |
| `fluent_cart/order/after_items_calculated` | `CheckoutApi.php:269` | `do_action`, and it fires after the order is being built — too late regardless. |
| `fluent_cart/checkout/prepare_other_data` | `CheckoutApi.php:276` | `do_action`. |

#### Coverage notes for `can_purchase_bundle`

Because the filter lives inside `ProductVariation::canPurchase()`, its reach is the set of callers of that method. The audited callers:

- **Normal add-to-cart** — `Cart::addByVariation()` calls `$variation->canPurchase($quantity)` at `fluent-cart/app/Models/Cart.php:428`, immediately before applying `fluent_cart/cart/can_purchase` at `:429`. This is why `can_purchase_bundle` **subsumes** `cart/can_purchase` for per-line rules: on this path anything the latter would catch, the former already saw one line earlier. Note carefully that on *this* path both hooks are equally gated — line `:428` sits inside the `if ($validate) {` block opened at `:427`, so `can_purchase_bundle` is behind `will_validate` here too. Its wider reach comes entirely from its **other two callers**, which `cart/can_purchase` does not have, not from being ungated on this one.
- **Instant checkout** — `CartResource::generateCartForInstantCheckout()` (`fluent-cart/api/Resource/FrontendResource/CartResource.php:28`) calls `canPurchase($quantity)` at `:68` and returns the `WP_Error` at `:69-70`. Note this call is skipped for `is_custom` items (`:67`).
- **Order bump / variation upgrade** — `WebCheckoutHandler::handleOrderBumpRequest()` (`fluent-cart/app/Hooks/Cart/WebCheckoutHandler.php:1032`) calls it at `:1065`. **The veto does not take effect here**, for two independent reasons; see the correction below.

**Correction worth carrying: the order-bump path does not honor the veto.** Line 1065 reads `if (!$productVariation || !$productVariation->canPurchase())`. A `WP_Error` object is *truthy* in PHP, so `!$wpError` evaluates to `false` and the guard passes — the refusal is discarded. Separately, the call passes no quantity (defaulting to 1) and the subsequent `addByVariation` at `:1070` does not set `will_validate`, so a quantity rule would have nothing meaningful to judge there anyway. The filter *fires* on this path; it does not *block* on it. Treat the order-bump/upgrade flow as uncovered by quantity rules. This is a host-side bug from our perspective, not something the extension can fix from a filter.

**How a veto surfaces to the browser.** For cart operations, `WebCheckoutHandler::globalCheckoutRouteHandler()` funnels every handler's return through a single `is_wp_error` check and emits HTTP **422** with the error message (`fluent-cart/app/Hooks/Cart/WebCheckoutHandler.php:116-120`). So a `WP_Error` raised deep inside `canPurchase()` reaches the front-end as a 422 with your message intact.

**Checkout has two front doors, one funnel.** There is a REST route `place-order` (`fluent-cart/app/Http/Routes/frontend_routes.php:36`) and an AJAX twin, but both reach `CheckoutApi::placeOrder()`. Hooking the API-level filters covers both; hooking a route would not.

### What was chosen, and why

- **Per-line quantity rules → `fluent_cart/variation/can_purchase_bundle`.** Widest coverage of the veto-capable options: it subsumes `cart/can_purchase` on the normal cart path and additionally reaches instant checkout, which `cart/can_purchase` never sees (see coverage notes). Registered at `fluent-cart-bulk-order.php:91`.
- **Store-wide order minimum → `fluent_cart/checkout/validate_data`.** Chosen over `validate_before_process` for one reason: it receives the resolved `$cart`, and a whole-cart total rule needs the cart. Registered at `fluent-cart-bulk-order.php:97`.

Both registrations carry an in-code comment (`fluent-cart-bulk-order.php:67-91`) recording the rationale — specifically warning the next reader not to "correct" the misleadingly named hook to a more obvious-sounding one, because the obvious ones cannot veto, and recording the order-bump gap below.

## Why This Matters

**Client-only "enforcement" is not enforcement.** If the case-pack rule lives only in JavaScript, the rule is a suggestion. Anyone replaying the AJAX request with an arbitrary quantity gets an order the fulfillment side cannot pick — a store selling in cases of 12 receives an order for 5. The distinction between "the UI nudges you to a valid quantity" and "the server refuses an invalid one" is the whole feature, and it is decided entirely by whether a veto-capable hook was found. Had the audit come back empty, the honest outcome would have been to descope the promise, not to ship a client-side check and call it enforcement.

**A hook's name is not evidence of what it does.** `fluent_cart/variation/can_purchase_bundle` is the single most useful hook in the host's cart path for this purpose, and its name actively misdirects: it is not gated on the product being a bundle, and reads as bundle-feature-specific. The generic bundle check that *does* exist sits above it on separate lines (`fluent-cart/app/Models/ProductVariation.php:265-268`); the filter is simply a general extension point that happens to have been named after the feature that motivated it. Conversely, `fluent_cart/item_max_quantity` sounds like a limit-enforcement hook and can only clamp a number. Name-based searching would have found the wrong hook in both directions.

**Coverage differs per hook, so "it vetoes" is only half an answer.** `cart/can_purchase` genuinely vetoes and is genuinely unusable as the sole gate, because it only fires when the caller opted into `will_validate` — which the live cart path does and instant checkout does not. Picking it would have produced enforcement that worked in manual testing (which goes through the cart) and silently failed for instant checkout. Two hooks can both be "veto-capable" and still differ by an entire request path.

**The host can honor a veto shape and still discard your message.** Returning `false` from `can_purchase_bundle` blocks the purchase and tells the shopper the product is out of stock — which is false and unactionable when the real reason is a case-pack rule. Same veto, wrong outcome.

**And a host can fire a hook on a path where it ignores the result.** The order-bump call site proves the audit cannot stop at "does the hook fire here". `!$variation->canPurchase()` looks like a check and is not one, because of PHP's truthiness of objects. Reading the consumption is what caught it.

## When to Apply

Run this audit whenever **all** of the following hold:

- You are extending a host plugin, framework, or platform whose source you can read but should not modify.
- The behavior you need is **rejection**, not transformation — you must stop an operation, not adjust its inputs.
- The enforcement must hold against a request that did not come from your UI (security-relevant rules, commercial constraints, data-integrity invariants).
- Your extension does not own the request path, so there is no in-house call site to validate at as a fallback.

Also apply the narrower parts of it when:

- Several host hooks plausibly fit the same enforcement point and you must choose. Classify by return-value consumption, refusal shape, payload, and coverage — then pick, and write the reasoning into the registration site.
- You are about to commit to a plan item that assumes a hook exists. Make the audit the gating first task, as the order-rules plan did: if the hook does not exist, everything downstream needs rescoping and it is cheapest to learn that first.

Do **not** reach for this when the host already exposes a documented validation API, or when your extension owns its own endpoint — validate at your own call site and skip the archaeology.

## Examples

### `WP_Error` vs bare `false` — same veto, different shopper

The relevant lines at `fluent-cart/app/Models/ProductVariation.php:270-278`:

```php
$bundleCheck = apply_filters('fluent_cart/variation/can_purchase_bundle', null, [
    'variation' => $this,
    'quantity'  => (int)$quantity
]);
if (is_wp_error($bundleCheck)) {
    return $bundleCheck;                       // your message survives verbatim
} elseif ($bundleCheck === false) {
    return new \WP_Error('insufficient_stock', // your reason is discarded and
        __('Sorry, this product is currently out of stock.', 'fluent-cart'));
}
```

Return `false` and the shopper is told the product is out of stock. Return a `WP_Error` and they are told what they actually need to do. The implementation returns a `WP_Error` whose message always names the nearest acceptable quantity — `fcbo_describe_qty_violation()` at `fluent-cart-bulk-order.php:1836` produces e.g. "This product is sold in multiples of 12. Try 24." rather than a bare refusal.

### Never override a veto someone else already cast

A filter that ignores its incoming `$result` will happily overwrite another party's refusal. FluentCart's own stock module binds to this same hook (`fluent-cart/app/Modules/StockManagement/StockManagement.php:56`), so this is not hypothetical. The guard is the first thing in the callback, `fluent-cart-bulk-order.php:1800-1805`:

```php
function fcbo_validate_cart_item_rules($result, $context)
{
    // Never override a veto another party already cast (e.g. out of stock).
    if (is_wp_error($result) || $result === false) {
        return $result;
    }
    // …
```

Every non-veto return path in the function returns `$result` untouched rather than `null` or `true` (`:1811`, `:1820`), so the callback is transparent whenever its own rule does not fire. The same discipline applies to the checkout callback: `fcbo_validate_checkout_minimum()` (`fluent-cart-bulk-order.php:1872`) *adds* a key to the incoming `$errors` array (`:1892`) and returns the array on every early-exit path (`:1876`, `:1881`, `:1886`), so it can never erase validation errors FluentCart or another extension already accumulated.

### Client forgiving, server strict

The two helpers are deliberately asymmetric. `fcbo_normalize_qty()` (`fluent-cart-bulk-order.php:1236`) rounds a quantity **up** to the nearest permitted value — always upward, so a shopper is never silently given less than they asked for. `fcbo_qty_is_valid()` (`:1259`) is one line built on it:

```php
return (int) $qty === fcbo_normalize_qty($qty, $rules);
```

The client corrects a typo in place; the server refuses anything that did not come through that correction. Because validity is defined *as* "already normalized", the two can never disagree about which quantities are acceptable — there is one formula, not two.

### Verification recipe — re-prove veto capability against the host

Reading the call site establishes intent. Only invoking the host's own method establishes behavior, and this is what to re-run after a FluentCart upgrade. Configure real order rules on a real product (e.g. min 12, step 12), then call FluentCart's own `ProductVariation::canPurchase()` — not your filter — and observe which quantities come back as `WP_Error`:

```php
// scratch.php — run with: wp eval-file scratch.php
$variation = \FluentCart\App\Models\ProductVariation::query()->find( VARIATION_ID );

foreach ([5, 12, 20, 24, 30, 36] as $qty) {
    $result = $variation->canPurchase($qty);
    printf(
        "qty %-3d => %s\n",
        $qty,
        is_wp_error($result) ? 'VETOED: ' . $result->get_error_message() : 'allowed'
    );
}
```

```
wp eval-file scratch.php 2>/dev/null | grep -aivE 'deprecated|dynamic property|longdesc|WP_CLI|phar://|^\s*$'
```

(wp-cli on this machine floods stdout with PHP 8 deprecation notices; the filter above is what makes the output readable.)

A passing run shows the veto firing **inside the host's own code path**, which a test of your callback in isolation cannot demonstrate. If a future FluentCart release moves or removes the filter, every quantity will come back `allowed` and this recipe will say so in one command.

**Scope of verification actually performed — stated plainly.** Two things were done, and neither is a test suite:

1. A scratch PHP script run through `wp eval-file` exercised the plugin's own helpers (`fcbo_normalize_qty`, `fcbo_qty_is_valid`, and the rule resolution around them). This was ad hoc scratch tooling and was never committed. **This repo has no automated test harness** — there is no `tests/` directory and no `phpunit.xml`, and the plan states outright that verification is manual (`docs/plans/2026-07-18-009-feat-order-rules-plan.md:288`).
2. A live check of the form above configured real order rules on a real product feed and called `ProductVariation::canPurchase($qty)` directly: quantities 5, 20, and 30 returned `WP_Error`; 24 and 36 were allowed. That is the evidence that the veto works through the host, and it is the claim this note actually rests on.

Nothing here was verified for the order-bump/upgrade path, which the source reading shows is not covered (see the correction under coverage notes).

## Related

- [`integration-issues/fluentcart-integration-feed-strips-undeclared-settings-keys`](../integration-issues/fluentcart-integration-feed-strips-undeclared-settings-keys.md) — the same lesson from the settings-save side: the answer was not in this plugin's code and could only be found by reading the host's pipeline and the *ordering* of its hooks. That note establishes the `fluent-cart/` path convention used here. Together they cover both halves of the host relationship: what FluentCart lets this plugin *persist*, and what it lets this plugin *refuse*.
- [`architecture-patterns/fluentcart-order-model-user-orders-and-variants`](./fluentcart-order-model-user-orders-and-variants.md) — undocumented FluentCart read-side internals. Like this note, it depends on host behavior that is not a public contract; re-verify all three after a FluentCart upgrade.
- [`security-issues/rest-endpoints-missing-permission-callback`](../security-issues/rest-endpoints-missing-permission-callback.md) — the other half of the enforcement map, and the reason to read them together: enforcement lives in **two** places, not one. Operations this plugin owns are gated by a `permission_callback` on its own REST routes; operations FluentCart owns — cart-add via the host's AJAX handler, and checkout — expose no route to gate and must be refused through a host veto filter instead. That doc's prevention checklist ("grep for `__return_true`", "curl each custom route unauthenticated") only ever inspects routes this plugin registers, so it passes clean while a host-owned mutation path sits ungated. Both notes also rely on the same `WP_Error`-carries-the-message property, there on a permission callback and here on a filter.
- [`docs/plans/2026-07-18-009-feat-order-rules-plan.md`](../../plans/2026-07-18-009-feat-order-rules-plan.md) — the plan this implements. It named the hook audit its highest risk and sequenced it first, which is why the risk was retired before any dependent work was built.
- The implementing work landed on branch `feat/order-rules`, **unmerged and with no PR open as of this writing** — so no PR number can be cited yet, and the branch's commit SHAs may be rewritten by a squash or rebase merge. Locate the code by symbol rather than by revision: `fcbo_validate_cart_item_rules()` and `fcbo_validate_checkout_minimum()` in `fluent-cart-bulk-order.php`, plus the two `add_filter` registrations and their rationale comment near the top of the same file.
