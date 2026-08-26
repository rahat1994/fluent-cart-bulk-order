<?php
/**
 * Bulk-pricing engine checks — run with:
 *
 *     wp eval-file wp-content/plugins/fluent-cart-bulk-order/tests/pricing-checks.php
 *
 * INTERIM HARNESS. This is not a test framework; it is a plain script that
 * asserts the pure arithmetic in includes/Pricing/ and prints a pass/fail line
 * per case. It exists because that arithmetic is where this plugin has actually
 * broken before — two of the three most recent bug fixes were pricing bugs where
 * the quoted price and the charged price disagreed — and because it needs no
 * setup beyond a working WordPress.
 *
 * Port these cases to PHPUnit when #21 lands a real suite, then delete this file.
 * Everything below is deliberately dependency-free: no fixtures, no database
 * writes, no network. The feed shapes are literals so the expected values can be
 * read straight off the page.
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

/* ---------- Tiers::applyToPrice — integer cents in, integer cents out ---------- */
check($results, 'percent 10% off 1000c', Tiers::applyToPrice(1000, ['discount_type'=>'percent','discount_value'=>10]), 900);
check($results, 'percent default type', Tiers::applyToPrice(1000, ['discount_value'=>25]), 750);
check($results, 'percent rounds to whole cent', Tiers::applyToPrice(999, ['discount_type'=>'percent','discount_value'=>10]), 899);
check($results, 'percent 100% -> 0', Tiers::applyToPrice(1000, ['discount_type'=>'percent','discount_value'=>100]), 0);
check($results, 'percent over 100 clamps at 0', Tiers::applyToPrice(1000, ['discount_type'=>'percent','discount_value'=>150]), 0);
check($results, 'fixed unit price', Tiers::applyToPrice(1000, ['discount_type'=>'fixed_unit_price','discount_value'=>4]), 400);
check($results, 'fixed unit price ignores original', Tiers::applyToPrice(50, ['discount_type'=>'fixed_unit_price','discount_value'=>4]), 400);
check($results, 'amount off', Tiers::applyToPrice(1000, ['discount_type'=>'amount_off','discount_value'=>2.50]), 750);
check($results, 'amount off never goes negative', Tiers::applyToPrice(100, ['discount_type'=>'amount_off','discount_value'=>99]), 0);
check($results, 'unknown type falls back to percent', Tiers::applyToPrice(1000, ['discount_type'=>'nonsense','discount_value'=>10]), 900);
check($results, 'missing value = no discount', Tiers::applyToPrice(1000, ['discount_type'=>'percent']), 1000);

/* ---------- Tiers::match — most specific range wins (the d378b36 bug) ---------- */
$tiers = [
    ['min_qty'=>10, 'max_qty'=>0,  'discount_value'=>5],
    ['min_qty'=>50, 'max_qty'=>0,  'discount_value'=>10],
    ['min_qty'=>100,'max_qty'=>0,  'discount_value'=>20],
];
check($results, 'qty below every tier -> null', Tiers::match($tiers, 5), null);
check($results, 'qty 10 picks the 10+ tier', Tiers::match($tiers, 10)['discount_value'], 5);
check($results, 'qty 60 picks 50+, not the first match', Tiers::match($tiers, 60)['discount_value'], 10);
check($results, 'qty 1000 picks the highest tier', Tiers::match($tiers, 1000)['discount_value'], 20);
$capped = [['min_qty'=>10,'max_qty'=>20,'discount_value'=>5], ['min_qty'=>21,'max_qty'=>0,'discount_value'=>15]];
check($results, 'max_qty is respected', Tiers::match($capped, 25)['discount_value'], 15);
check($results, 'inside a capped band', Tiers::match($capped, 20)['discount_value'], 5);
check($results, 'empty tier list -> null', Tiers::match([], 50), null);
check($results, 'non-array tiers -> null', Tiers::match(null, 50), null);

/* ---------- OrderRules ---------- */
check($results, 'normalize fills defaults', OrderRules::normalize([]), ['min_qty'=>0,'step'=>1]);
check($results, 'normalize floors step at 1', OrderRules::normalize(['step'=>0]), ['min_qty'=>0,'step'=>1]);
check($results, 'normalize rejects negative min', OrderRules::normalize(['min_qty'=>-5]), ['min_qty'=>0,'step'=>1]);
check($results, 'normalize handles non-array', OrderRules::normalize('nope'), ['min_qty'=>0,'step'=>1]);
check($results, 'areSet false when unset', OrderRules::areSet(['min_qty'=>0,'step'=>1]), false);
check($results, 'areSet true with a minimum', OrderRules::areSet(['min_qty'=>6,'step'=>1]), true);
check($results, 'areSet true with a step', OrderRules::areSet(['min_qty'=>0,'step'=>6]), true);

// Rounding is always UP, never down.
check($results, 'qty below minimum rounds up to it', OrderRules::normalizeQty(2, ['min_qty'=>10,'step'=>1]), 10);
check($results, 'case pack of 6: 7 -> 12', OrderRules::normalizeQty(7, ['min_qty'=>0,'step'=>6]), 12);
check($results, 'case pack exact stays put', OrderRules::normalizeQty(12, ['min_qty'=>0,'step'=>6]), 12);
check($results, 'minimum AND step together', OrderRules::normalizeQty(3, ['min_qty'=>10,'step'=>6]), 12);
check($results, 'zero becomes at least 1', OrderRules::normalizeQty(0, ['min_qty'=>0,'step'=>1]), 1);
check($results, 'negative becomes at least 1', OrderRules::normalizeQty(-5, ['min_qty'=>0,'step'=>1]), 1);
check($results, 'no rules leaves qty alone', OrderRules::normalizeQty(7, []), 7);
check($results, 'qtyIsValid true when conforming', OrderRules::qtyIsValid(12, ['min_qty'=>0,'step'=>6]), true);
check($results, 'qtyIsValid false when not', OrderRules::qtyIsValid(7, ['min_qty'=>0,'step'=>6]), false);

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
check($results, 'delegate fcbo_match_tier agrees', fcbo_match_tier($tiers, 60)['discount_value'], 10);
check($results, 'delegate fcbo_normalize_qty agrees', fcbo_normalize_qty(7, ['min_qty'=>0,'step'=>6]), 12);
check($results, 'delegate fcbo_apply_tier_to_price agrees', fcbo_apply_tier_to_price(1000, ['discount_type'=>'percent','discount_value'=>10]), 900);
check($results, 'delegate fcbo_resolve_tiers agrees', fcbo_resolve_tiers($pricing, 42, 1)[0]['discount_value'], 15);
check($results, 'delegate fcbo_resolve_order_rules agrees', fcbo_resolve_order_rules($pricing, 42, 1), ['min_qty'=>6,'step'=>6]);

echo implode("\n", $results) . "\n";
$fails = count(array_filter($results, fn($x) => str_starts_with($x, 'FAIL')));
echo "\n" . (count($results) - $fails) . '/' . count($results) . " passed\n";
