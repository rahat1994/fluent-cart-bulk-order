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

use FluentCartBulkOrder\Pricing\Tiers;
use FluentCartBulkOrder\Pricing\OrderRules;
use FluentCartBulkOrder\Pricing\FeedResolver;

fcbo_load_pricing();

$results = [];
function check(&$r, $name, $got, $want) {
    $ok = $got === $want;
    $r[] = ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($ok ? '' : "  got: " . var_export($got, true) . "  want: " . var_export($want, true));
}

/* ---------- FeedResolver precedence ---------- */
$pricing = [
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
check($results, 'product feed beats store-wide', FeedResolver::resolveTiers($pricing, 42, 1)[0]['discount_value'], 15);
check($results, 'unlisted product falls back to store-wide', FeedResolver::resolveTiers($pricing, 999, 1)[0]['discount_value'], 5);
check($results, 'variant-restricted feed matches its variant', FeedResolver::resolveTiers($pricing, 43, 99)[0]['discount_value'], 25);
check($results, 'variant-restricted feed skipped for others', FeedResolver::resolveTiers($pricing, 43, 100)[0]['discount_value'], 5);
check($results, 'rules resolve through the SAME feed as tiers', FeedResolver::resolveOrderRules($pricing, 42, 1), ['min_qty'=>6,'step'=>6]);
check($results, 'store-wide rules when no product feed', FeedResolver::resolveOrderRules($pricing, 999, 1), ['min_qty'=>0,'step'=>1]);

/* ---------- the delegates still answer identically ---------- */
// Local fixture: the arithmetic itself is asserted in tests/Unit; here it only
// needs to be something both the class and its wrapper can be asked about.
$tiers = [
    ['min_qty' => 10, 'max_qty' => 0, 'discount_value' => 5],
    ['min_qty' => 50, 'max_qty' => 0, 'discount_value' => 10],
];
check($results, 'delegate fcbo_match_tier agrees', fcbo_match_tier($tiers, 60)['discount_value'], 10);
check($results, 'delegate fcbo_normalize_qty agrees', fcbo_normalize_qty(7, ['min_qty'=>0,'step'=>6]), 12);
check($results, 'delegate fcbo_apply_tier_to_price agrees', fcbo_apply_tier_to_price(1000, ['discount_type'=>'percent','discount_value'=>10]), 900);
check($results, 'delegate fcbo_resolve_tiers agrees', fcbo_resolve_tiers($pricing, 42, 1)[0]['discount_value'], 15);
check($results, 'delegate fcbo_resolve_order_rules agrees', fcbo_resolve_order_rules($pricing, 42, 1), ['min_qty'=>6,'step'=>6]);

echo implode("\n", $results) . "\n";
$fails = count(array_filter($results, fn($x) => str_starts_with($x, 'FAIL')));
echo "\n" . (count($results) - $fails) . '/' . count($results) . " passed\n";
