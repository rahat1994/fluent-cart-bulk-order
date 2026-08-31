<?php
/**
 * WordPress-dependent checks — run with:
 *
 *     wp eval-file wp-content/plugins/fluent-cart-bulk-order/tests/pricing-checks.php
 *
 * DIVISION OF LABOUR. The arithmetic itself now lives in tests/Unit and runs
 * under PHPUnit with no WordPress at all. What is left here is the part that
 * genuinely needs a booted store:
 *
 *   - delegate parity: each fcbo_* wrapper still returns what its class does,
 *     which cannot be checked without the main plugin file loaded;
 *   - feed precedence against a real, populated store rather than literals.
 *
 * Keep it that way. Anything that can be asserted without WordPress belongs in
 * tests/Unit, where it runs in milliseconds and cannot be broken by whatever a
 * particular store happens to have configured.
 */

// Safe here, and deliberately absent from tests/bootstrap.php: this file only
// ever runs through `wp eval-file`, which boots WordPress first, so ABSPATH is
// defined by the time it is reached. The PHPUnit bootstrap has no WordPress
// behind it at all and the same line would end the test run.
defined('ABSPATH') || exit;

use FluentCartBulkOrder\Pricing\Tiers;
use FluentCartBulkOrder\Pricing\OrderRules;
use FluentCartBulkOrder\Pricing\FeedResolver;

fcbo_load_pricing();

$fcbo_results = [];
function fcbo_check(&$r, $name, $got, $want) {
    $ok = $got === $want;
    // wp_json_encode(), not var_export(): the same information in one line,
    // and var_export() is flagged everywhere as debug code that should not be
    // shipped. This file is not shipped, but there is no reason for it to be
    // the one place that reads as if it might be.
    $r[] = ($ok ? 'PASS  ' : 'FAIL  ') . $name
        . ($ok ? '' : '  got: ' . wp_json_encode($got) . '  want: ' . wp_json_encode($want));
}

/* ---------- FeedResolver precedence ---------- */
$fcbo_pricing = [
  'global' => ['tiers'=>[['min_qty'=>10,'discount_value'=>5]], 'role_tiers'=>[], 'order_rules'=>['min_qty'=>0,'step'=>1]],
  'product' => [
    42 => [
      ['variant_ids'=>[], 'tiers'=>[['min_qty'=>10,'discount_value'=>15]], 'role_tiers'=>[], 'order_rules'=>['min_qty'=>6,'step'=>6]],
    ],
    43 => [
      ['variant_ids'=>[99], 'tiers'=>[['min_qty'=>10,'discount_value'=>25]], 'role_tiers'=>[], 'order_rules'=>['min_qty'=>0,'step'=>1]],
    ],
  ],
];
fcbo_check($fcbo_results, 'product feed beats store-wide', FeedResolver::resolveTiers($fcbo_pricing, 42, 1)[0]['discount_value'], 15);
fcbo_check($fcbo_results, 'unlisted product falls back to store-wide', FeedResolver::resolveTiers($fcbo_pricing, 999, 1)[0]['discount_value'], 5);
fcbo_check($fcbo_results, 'variant-restricted feed matches its variant', FeedResolver::resolveTiers($fcbo_pricing, 43, 99)[0]['discount_value'], 25);
fcbo_check($fcbo_results, 'variant-restricted feed skipped for others', FeedResolver::resolveTiers($fcbo_pricing, 43, 100)[0]['discount_value'], 5);
fcbo_check($fcbo_results, 'rules resolve through the SAME feed as tiers', FeedResolver::resolveOrderRules($fcbo_pricing, 42, 1), ['min_qty'=>6,'step'=>6]);
fcbo_check($fcbo_results, 'store-wide rules when no product feed', FeedResolver::resolveOrderRules($fcbo_pricing, 999, 1), ['min_qty'=>0,'step'=>1]);

/* ---------- the delegates still answer identically ---------- */
// Local fixture: the arithmetic itself is asserted in tests/Unit; here it only
// needs to be something both the class and its wrapper can be asked about.
$fcbo_tiers = [
    ['min_qty' => 10, 'max_qty' => 0, 'discount_value' => 5],
    ['min_qty' => 50, 'max_qty' => 0, 'discount_value' => 10],
];
fcbo_check($fcbo_results, 'delegate fcbo_match_tier agrees', fcbo_match_tier($fcbo_tiers, 60)['discount_value'], 10);
fcbo_check($fcbo_results, 'delegate fcbo_normalize_qty agrees', fcbo_normalize_qty(7, ['min_qty'=>0,'step'=>6]), 12);
fcbo_check($fcbo_results, 'delegate fcbo_apply_tier_to_price agrees', fcbo_apply_tier_to_price(1000, ['discount_type'=>'percent','discount_value'=>10]), 900);
fcbo_check($fcbo_results, 'delegate fcbo_resolve_tiers agrees', fcbo_resolve_tiers($fcbo_pricing, 42, 1)[0]['discount_value'], 15);
fcbo_check($fcbo_results, 'delegate fcbo_resolve_order_rules agrees', fcbo_resolve_order_rules($fcbo_pricing, 42, 1), ['min_qty'=>6,'step'=>6]);

// WP_CLI::log() rather than echo. This file only runs under `wp eval-file`, so
// WP_CLI is always present — and it is the honest answer to "all output must be
// escaped", which means nothing for a terminal: esc_html() on a CLI report would
// mangle it rather than protect anyone.
WP_CLI::log(implode("\n", $fcbo_results));

// A closure, not an arrow function, and strpos() rather than str_starts_with():
// this plugin supports PHP 7.4 and str_starts_with() is PHP 8.0, so the old
// version fatally errored on exactly the interpreter the header promises.
$fcbo_fails = count(array_filter($fcbo_results, function ($x) {
    return strpos($x, 'FAIL') === 0;
}));

WP_CLI::log('');
WP_CLI::log((count($fcbo_results) - $fcbo_fails) . '/' . count($fcbo_results) . ' passed');

// A failing check has to fail the COMMAND, not just print the word FAIL.
//
// Without this the script printed its failures and exited 0, so anything that
// ran it — a release step, a pre-push hook, a person reading the tail of a long
// log — was told everything passed. A test that cannot fail its runner is not a
// test, it is a report nobody reads.
//
// WP_CLI::error() prints to stderr and exits non-zero, which is what
// `wp eval-file` propagates.
if ($fcbo_fails > 0) {
    WP_CLI::error($fcbo_fails . ' check(s) failed.');
}
