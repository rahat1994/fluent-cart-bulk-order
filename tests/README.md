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
- `includes/Wholesale/` — the three pure classes behind the wholesale
  application flow. `ApplicationStatus` is the state machine that decides
  whether a request may grant the `wholesale-customer` role; `ApplicationSchema`
  normalises the owner-configured extra fields; `ApplicationInput` validates a
  submission against that schema. All three fail quietly when they are wrong —
  a duplicate field key silently overwrites another answer, a required select
  with no options makes the form unsubmittable, and a validator that trusted the
  submission's keys instead of the schema's would let a crafted POST write
  fields nobody configured.
- `includes/Quotes/` — the two pure classes behind the request-a-quote flow.
  `QuoteStatus` is the state machine that decides whether a request may create a
  real FluentCart order at a hand-typed price; `QuoteInput` decides which of a
  submission's values are allowed near a stored quote and does the totals
  arithmetic. Both fail quietly when they are wrong — a price read from the
  browser instead of the catalogue lets a buyer name their own price, and an
  empty price box read as `0` turns "leave this line alone" into "give it away".

- `includes/Checkout/PoNumber.php` and `includes/Export/OrderCsv.php` — the two
  pure classes behind the B2B checkout extras. `PoNumber` decides whether a
  checkout is refused for a missing purchase-order number; `OrderCsv` decides
  what ends up in a file the store hands to a buyer's accounts department. Both
  fail quietly when they are wrong — an unreadable mode that defaulted to
  "required" would stop a store selling until somebody noticed, and a cell a
  spreadsheet runs as a formula downloads exactly like one that does not.

- `includes/Analytics/` — the five pure classes behind owner analytics.
  `Period` turns a named reporting window into a date boundary; `Surface` maps
  a marker a shopper can edit in their own URL onto a closed list; `TierSignature`
  decides when two Bulk Pricing Tiers are the same tier; `TierUsage` joins the
  tiers a store has configured against the tiers buyers actually reached; and
  `RevenueSplit` divides the store's revenue into bulk and normal. Every one of
  them fails quietly — a window boundary computed in the wrong timezone reports
  a different quarter with complete confidence, an unstable tier signature
  splits one tier's usage across several rows and makes a busy tier look dead,
  a usage merge that drops a key hides the unused tier the whole panel exists
  to surface, and a split that does not clamp prints negative revenue.

These classes are pure functions over arrays, which is exactly why they are the
ones worth pinning. `tests/bootstrap.php` does **not** load WordPress; it defines
`ABSPATH`, `DAY_IN_SECONDS`, and passthrough `__()`, `esc_html()` and
`number_format_i18n()` — nothing else. If a class ever needs more than those,
that is a signal it has stopped being pure — push the impure part outward rather
than widening the bootstrap.

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
