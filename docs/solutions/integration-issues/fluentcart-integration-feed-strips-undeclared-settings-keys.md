---
title: "FluentCart integration feed silently strips settings keys not declared in getSettingsFields()"
date: 2026-07-19
module: bulk-pricing-integration
problem_type: integration_issue
component: service_object
severity: high
category: integration-issues
symptoms:
  - "Per-role price lists (role_tiers) vanish after reload while default discount tiers persist correctly"
  - "Save request returns success with no PHP error, console error, or debug.log entry"
  - "Admin repeater UI accepts and renders the role-scoped input normally before saving"
  - "Reproduced identically on both the product-scoped and the global (store-wide) settings screens"
root_cause: wrong_api
resolution_type: code_fix
tags:
  - fluent-cart
  - wordpress
  - integration-feed
  - settings-persistence
  - silent-data-loss
  - whitelist
  - user-roles
  - rest-api
related_components:
  - FluentCart\App\Modules\Integrations\IntegrationHelper
  - FluentCart\App\Modules\Integrations\BaseIntegrationManager
  - FluentCart\App\Modules\Integrations\GlobalIntegrationSettings
  - FluentCartBulkOrder\BulkPricingIntegration
---

# FluentCart integration feed silently strips settings keys not declared in getSettingsFields()

> **Source paths.** Paths beginning `fluent-cart/` refer to the **sibling FluentCart plugin** at `wp-content/plugins/fluent-cart/` — they are not part of this repository. Paths without a prefix (e.g. `includes/BulkPricingIntegration.php`) are repo-relative to `fluent-cart-bulk-order`.

## Problem

Per-role bulk pricing (`role_tiers`) configured in the plugin's FluentCart integration-feed admin screen silently failed to persist. The admin repeater accepted the input, the save request returned success, and the default `tiers` saved correctly — but `role_tiers` was simply absent when the screen reloaded. FluentCart hard-whitelists the feed payload against the integration's *declared* settings fields **before** any third-party validation filter runs, and `role_tiers` had never been declared as a field of its own: its UI had been embedded inside the existing `tiers` field's render template.

## Symptoms

- Adding a per-role price list (e.g. "Wholesale Customer, 12%"), saving, and reloading showed the role section empty again — the row never made it into the product meta.
- Default (all-customers) tiers on the exact same feed saved and re-hydrated correctly. Only role pricing vanished, which is what made the failure read as a UI bug rather than a persistence bug.
- No PHP fatal, no JS console error, and nothing in `wp-content/debug.log` despite `WP_DEBUG_LOG` being enabled in `wp-config.php`. The save endpoint returned a success response.
- Reproduced identically in both the product-scoped and the global (store-wide) settings screens.

## What Didn't Work

- **Blaming Vue reactivity.** The first hypothesis was that the repeater never sent `role_tiers` at all — that assigning a dynamically added key on a reactive object wasn't tracked (the classic Vue 2 `Vue.set` trap). Rejected: the FluentCart admin is Vue 3 + Element Plus, where adding a new property to a reactive object *is* reactive, and the outgoing request payload was confirmed to contain `role_tiers`. The data loss was entirely server-side.
- **Blaming `get_editable_roles()` fataling mid-save.** The second hypothesis was that calling `get_editable_roles()` on a REST request threw a fatal that aborted the write. `wp-content/debug.log` recorded no such fatal. This turned out to be a real latent defect worth fixing (see Solution), but it was **not** the cause of this symptom.
- **Reading only our own plugin's code.** No amount of staring at `includes/BulkPricingIntegration.php` could have found this. The stripping happens inside FluentCart's helper, in code our plugin never calls directly, on a line that executes before our filter is even attached to the pipeline. Diagnosis required reading the host plugin.

## Solution

Declare `role_tiers` as its own settings field, with its own render template, in `getSettingsFields()` — `includes/BulkPricingIntegration.php:82-87` (the method begins at `:60`):

```php
'role_tiers' => [
    'key'             => 'role_tiers',
    'label'           => __('Role-specific Pricing', 'fluent-cart-bulk-order'),
    'component'       => 'custom_component',
    'render_template' => $this->getRoleTiersTemplate(),
],
```

That single declaration does two jobs: it whitelists `role_tiers` so the key survives the save pipeline, and it gives the role groups a properly labelled section of their own instead of squatting inside the `tiers` template.

**Secondary defect fixed in the same change.** `get_editable_roles()` is defined in `wp-admin/includes/user.php`, which is *not* loaded on REST or AJAX requests — and both `getSettingsFields()` and `validateFeedData()` run on the save path. Every call now routes through a guarded wrapper at `includes/BulkPricingIntegration.php:245`:

```php
private function getEditableRoles()
{
    if (!function_exists('get_editable_roles')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }

    return (array) get_editable_roles();
}
```

Diagnosed and verified in PR #8.

## Why This Works

`IntegrationHelper::validateAndFormatIntegrationFeedSettings()` in `fluent-cart/app/Modules/Integrations/IntegrationHelper.php` runs a fixed sequence on every feed save:

1. `:27` — collects the integration's declared fields via `fluent_cart/integration/get_integration_settings_fields_{provider}`.
2. `:31` — seeds `$validKeys = ['enabled', 'conditional_variation_ids']`.
3. `:40` — appends `$validKeys[] = $key;` for each declared field's `key`.
4. `:60` — `$validatedData = Arr::only($integration, $validKeys);` — **every undeclared key is discarded here, silently, with no error and no log entry.**
5. `:62` — *only now* applies `fluent_cart/integration/integration_saving_data_{provider}`.

Our `validateFeedData()` is bound to that last filter by `fluent-cart/app/Modules/Integrations/BaseIntegrationManager.php:57`, so it receives data that has *already* been stripped. With `getSettingsFields()` declaring only `name` and `tiers`, `role_tiers` was never in `$validKeys`. The browser sent it; step 4 dropped it; our validator at `includes/BulkPricingIntegration.php:105` found nothing under `role_tiers` and correctly concluded "no role pricing configured". `tiers` survived for the one reason that matters: it *was* declared. The global scope failed the same way because `fluent-cart/app/Modules/Integrations/GlobalIntegrationSettings.php:320` calls the identical helper.

**The asymmetry is the durable insight.** Because the whitelist runs *before* the filter:

- Keys you expect to arrive **from the client** must be declared fields — otherwise they are stripped before you ever see them.
- Keys you **add inside `validateFeedData()`** persist just fine — the whitelist has already run and nothing re-filters the return value.

Nothing in the API signals this asymmetry; both directions look like "keys in the settings array". We now deliberately exploit the second half: `includes/BulkPricingIntegration.php:126` sets `$data['event_trigger'] = $this->describeVariationScope($data);` purely for admin display, and it persists without ever being a declared field.

## Prevention

- **The rule:** any key you expect to receive *from the client* must be a declared field in `getSettingsFields()` with a matching `key`. If you only need a value persisted for display or bookkeeping, add it inside `validateFeedData()` instead — do not declare a phantom field for it.
- **A declared field is the whitelist, not just the UI.** Never bury a new settings key inside another field's `render_template`. If it round-trips through the browser, it earns its own entry in `getSettingsFields()`. The comment at `includes/BulkPricingIntegration.php:77-81` states this at the site where it's easiest to get wrong.
- **Prove it in one command instead of guessing.** Round-trip a payload through the real helper with `wp eval-file` and diff input keys against output keys:

  ```php
  $out = \FluentCart\App\Modules\Integrations\IntegrationHelper::validateAndFormatIntegrationFeedSettings([
      'enabled' => 'yes', 'name' => 'Repro',
      'tiers' => [...],
      'role_tiers' => ['administrator' => [...]],
  ], ['provider' => 'fcbo_bulk_pricing', 'scope' => 'product', 'product_id' => 363, 'integration_id' => 246]);
  ```

  Before the fix: `INPUT keys: enabled, name, tiers, role_tiers` / `OUTPUT keys: enabled, name, tiers`. After: `role_tiers` is present. Any key that goes in and doesn't come out is undeclared.

  wp-cli on this machine floods stdout with PHP 8 deprecation notices; filter with
  `2>/dev/null | grep -aivE 'deprecated|dynamic property|longdesc|WP_CLI|phar://|^\s*$'`.

- **Treat `wp-admin/includes/*` functions as unavailable by default.** `get_editable_roles()`, and anything else from `wp-admin/includes/`, is not loaded on REST/AJAX/cron. Guard with `function_exists()` + `require_once ABSPATH . 'wp-admin/includes/…'` before calling it from any code reachable on a save path — as at `includes/BulkPricingIntegration.php:245`. An absent fatal in `debug.log` proves the current request context happened to have it loaded, not that the call is safe.
- **When a host-plugin integration silently loses data, read the host's pipeline before your own.** Find where the host filters or validates your payload and confirm the ordering of its hooks relative to yours. "Success response, missing field, empty log" is the signature of a whitelist, not of your own validation.

## Related

- [`architecture-patterns/fluentcart-order-model-user-orders-and-variants`](../architecture-patterns/fluentcart-order-model-user-orders-and-variants.md) — the read-side counterpart. That note documents undocumented FluentCart *read* internals (the `Order` → `Customer` → WP user chain and `OrderItem.object_id`); this one documents the *write* side, how FluentCart's feed-save pipeline constrains what this plugin may persist. Both depend on FluentCart behavior that is not a public contract and can shift on upgrade, so treat them as a pair.
- [`security-issues/rest-endpoints-missing-permission-callback`](../security-issues/rest-endpoints-missing-permission-callback.md) — shares the non-admin request context that motivates the `get_editable_roles()` guard here: `wp-admin/includes/*` is absent on exactly the REST requests that doc argues must enforce role checks independently of the admin UI. Its closing note about publicly rendered discount-tier data also now understates the exposure, since `role_tiers` widens that payload with per-role price lists.
- PR #8 — declares `role_tiers` as a settings field and guards `get_editable_roles()`. Verified end-to-end in wp-admin (a "Wholesale Customer" 12% list saved to product meta and re-hydrated on reload). Also checked against an ad hoc 32-assertion PHP verification script run through `wp eval-file`; note that script was scratch tooling and was never committed, so this repo has no re-runnable test suite to confirm that count against.
- [`docs/plans/2026-07-18-008-feat-richer-pricing-rules-plan.md`](../../plans/2026-07-18-008-feat-richer-pricing-rules-plan.md) — the plan this work implemented (Part B, per-role price lists).
