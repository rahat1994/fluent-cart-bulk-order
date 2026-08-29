---
title: "A wrapper around a shortcode must omit the attributes nobody set, not pass them as empty strings"
date: 2026-08-27
category: architecture-patterns
module: shortcode-wrappers
problem_type: architecture_pattern
component: value_object
severity: high
applies_when:
  - "A shortcode resolves an option through attribute > stored default > built-in fallback"
  - "A block, widget, or page-builder wrapper places that shortcode on the owner's behalf"
  - "The wrapper's controls always hold a value, including for controls the owner never touched"
  - "A boolean option needs a third state meaning 'follow the store setting'"
tags:
  - wordpress
  - shortcodes
  - gutenberg
  - elementor
  - store-defaults
  - precedence
  - silent-misconfiguration
related_components:
  - FluentCartBulkOrder\Shortcodes\AttributeSchema
  - FluentCartBulkOrder\Shortcodes\AbstractShortcode
  - FluentCartBulkOrder\Blocks\BlockHandler
  - FluentCartBulkOrder\Integrations\Elementor\ShortcodeWidget
  - FluentCartBulkOrder\StoreDefaults
---

# A wrapper around a shortcode must omit the attributes nobody set, not pass them as empty strings

## Context

Every ordering surface in this plugin resolves an option through three layers, in one direction:

1. an attribute written where the surface is placed,
2. the **Store Default** the owner set once for the whole store,
3. a built-in fallback for a store that has configured neither.

The mechanism is `shortcode_atts()`. A shortcode's `defaults()` seeds each key from the Store Default, and `shortcode_atts()` overlays whatever attributes were actually written. So the precedence is expressed by *supplying* the default, not by a check — there is no `if` anywhere that says "attribute wins".

That is compact and it reads well at the shortcode. It is also invisible at the place a surface is *placed*, which is where the danger is. A hand-written shortcode is naturally safe: an author who does not care about an option simply does not type it, so the key never reaches `shortcode_atts()` and the Store Default survives.

A wrapper is not naturally safe. A Gutenberg block's `attributes` object and an Elementor widget's settings array always hold a value for every control they declare, whether or not the owner touched it — normally `''`. A wrapper that passes its settings through as attributes therefore sends `quotes=""` for a control nobody looked at. `shortcode_atts()` cannot tell that apart from a deliberate choice. The empty value wins over the Store Default, falls through to the built-in fallback, and the owner's store-wide setting appears not to work — with no error and no symptom other than the feature being absent from a page.

## Guidance

**Route every wrapper through one function that decides what "set" means, and make that function omit the rest.**

In this codebase that is `AttributeSchema::toShortcodeAtts()`, and both wrappers call it — `BlockHandler` for the block, `ShortcodeWidget` for the Elementor widget. It makes two guarantees, and both are load-bearing:

1. Only keys declared in `AttributeSchema::SCHEMA` for that tag come out, so a wrapper cannot smuggle in an attribute the shortcode never meant to accept.
2. A key whose value resolves to "not set" is **omitted from the array entirely**, never emitted as `''`.

The second guarantee is what preserves the precedence. It rests on a small but important distinction inside `normalize()`: "not set" is signalled by `null`, which is a different value from `''`. Empty string has to stay legal, because `attr=""` is a legitimate thing for a human to write in a hand-authored shortcode — it just is not something an untouched control should ever say.

**Give a boolean option three states, not two.** A checkbox cannot express "leave the store default alone": its off position would have to mean either "off" or "unset", and either reading silently takes a choice away from the owner. That is why `AttributeSchema::TERNARY` exists, and why options like `quotes`, `search` and `expand_variants` use it. The control offers *on*, *off*, and an empty "follow the store setting" — and only the first two produce an attribute.

**Read the resolved value; never re-read the Store Default downstream.** By the time an attribute reaches the render method it has already been through all three layers. Consulting `StoreDefaults` again at that point reverses the precedence the arrangement exists to hold. `BulkOrderForm::quotesEnabled()` documents this at the call site.

**Parse booleans with `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, not truthiness.** The literal string `"false"` is truthy in PHP, so an author who writes `quotes="false"` would otherwise get the exact opposite of what they asked for.

## Why This Matters

This failure is silent in all three of the ways that make a bug expensive:

- **No error.** Nothing is malformed. An empty attribute is a perfectly valid attribute.
- **No symptom at the cause.** The wrapper looks right; the settings page looks right. Only the rendered page is wrong, and only for the option nobody touched.
- **It scales with success.** The more Store Defaults the plugin grows, the more options each wrapper silently overrides. Every new option added to a wrapped shortcode is another chance to reintroduce it.

The owner's experience is that a store-wide setting "does not work", which is the hardest class of report to act on.

## When to Apply

Apply this whenever something places a shortcode on the owner's behalf rather than a human typing it: blocks, page-builder widgets, theme template calls, importers, or a settings screen that writes shortcodes into post content.

The trigger to watch for is structural, not visual: **the placement mechanism always has a value for every option, but the option's meaning depends on whether the owner chose it.** Whenever those two facts are both true, an omission step has to exist somewhere, and it is worth having exactly one of them.

It does *not* apply to an option with no Store Default behind it. If the only layers are "attribute" and "built-in fallback", an empty value and an absent one mean the same thing and nothing is lost.

## Examples

The `quotes` attribute on the bulk order form is the full path.

`BulkOrderForm::defaults()` seeds the key from the Store Default, which is what places it in the defaults layer of `shortcode_atts()` and therefore what lets an explicit attribute beat it:

```php
protected function defaults()
{
    require_once FCBO_DIR . 'includes/Quotes/QuoteSettings.php';

    return [
        'roles'    => '',
        'redirect' => '',
        'quotes'   => QuoteSettings::enabled() ? 'true' : 'false',
    ];
}
```

The schema declares it as three-state rather than a checkbox:

```php
'fluent_cart_bulk_order' => [
    'roles'    => self::TEXT,
    'redirect' => self::TEXT,
    // TERNARY, so an owner can say "no quotes on THIS page" without that
    // being the same value as "follow the store setting".
    'quotes'   => self::TERNARY,
],
```

And the wrapper conversion drops anything that resolves to "not set", rather than passing it along as `''`:

```php
$resolved = self::normalize($type, $values[$name]);

// null is the "not set" signal from normalize(). It has to be a distinct
// value from '', because '' is a legitimate thing to pass for a text
// attribute in a hand-written shortcode — it just is not something an
// untouched control should ever say.
if ($resolved === null) {
    continue;
}

$atts[$name] = $resolved;
```

The block's `block.json` correspondingly defaults `quotes` to `""`, and the editor renders it with `ternaryOptions()` so the empty choice is a real, selectable state rather than an accident.

The behaviour is pinned by `tests/Unit/AttributeSchemaTest.php`. That matters more than usual here: because the failure is silent at runtime, a test is the only thing that will notice a regression.

## Related

- `CONCEPTS.md` — the **Store Default** entry defines the three-layer precedence in the project's own vocabulary.
- `docs/solutions/integration-issues/fluentcart-integration-feed-strips-undeclared-settings-keys.md` — the same family of failure from the other direction: a value that is submitted, accepted, and then silently discarded.
