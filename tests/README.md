# Tests

Two suites, split by whether they need WordPress.

## Unit — `tests/Unit`, no WordPress

```bash
composer install
composer test
```

Runs in milliseconds. Covers:

- `includes/Pricing/` — the tier math, order-rule rounding, and feed precedence.
- `includes/Shortcodes/AttributeSchema.php` — how a Gutenberg or Elementor
  control value becomes a shortcode attribute. It is here because getting it
  wrong is invisible: a wrapper that passes an attribute the store owner never
  set overrides the store-wide defaults, and the only symptom is a table quietly
  ignoring the settings page.

These classes are pure functions over arrays, which is exactly why they are the
ones worth pinning. `tests/bootstrap.php` does **not** load WordPress; it defines
`ABSPATH` and a passthrough `__()`, and nothing else. If a class ever needs more
than those two stubs, that is a signal it has stopped being pure — push the
impure part outward rather than widening the bootstrap.

Why this code first: two of the plugin's three most recent bug fixes were pricing
bugs where the price quoted to a shopper and the price the cart charged
disagreed. That is the failure this suite exists to catch.

## Store checks — `tests/pricing-checks.php`, needs a booted store

```bash
wp eval-file wp-content/plugins/fluent-cart-bulk-order/tests/pricing-checks.php
```

A plain script, not a framework. It covers the two things the unit suite cannot:

- **Delegate parity** — every `fcbo_*` wrapper still returns what its class does.
  The wrappers exist because docs, site snippets and REST `sanitize_callback`s
  name them, so they have to keep agreeing with the classes behind them.
- **Feed precedence against real data** rather than literals.

## Adding to these

Put an assertion in `tests/Unit` if it can be made without WordPress. Only reach
for the store checks when it genuinely cannot.

Two shapes that have already caused false failures, worth knowing before you
write more:

- Feeds are keyed `global` and `product` (by product id), and a feed's variant
  restriction is `variant_ids`. Get those names wrong and every assertion falls
  through to the store-wide feed and passes for the wrong reason.
- `LinePricing::applyBulkPricing()` takes a context with a `variation` object,
  but `LinePricing::lineSaving()` takes a cart-line array with `object_id` and
  `post_id`. They are not interchangeable.

## Not covered yet

The cart layer, the REST controllers and the settings sanitiser all have
verification recorded on their pull requests but no automated test. They need a
booted store and, for the cart, a populated catalogue — worth doing, but a
larger piece of work than the arithmetic above.
