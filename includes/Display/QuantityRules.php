<?php

namespace FluentCartBulkOrder\Display;

use FluentCartBulkOrder\Pricing\OrderRules;
use FluentCartBulkOrder\Strings;

defined('ABSPATH') || exit;

/**
 * Teach FluentCart's own quantity box about this store's Order Rules.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS AT ALL
 * ---------------------------------------------------------------------------
 *
 * A product with a minimum order quantity of 5 used to render FluentCart's
 * quantity box at 1. A shopper who pressed Buy Now without touching anything
 * sent quantity 1, RuleEnforcement::validateCartItem() correctly refused it, and
 * FluentCart rendered that refusal on a full page titled "Product Not Found"
 * (fluent-cart/app/Http/Routes/WebRoutes.php:131-140). Nothing was wrong with
 * the product; the page had simply offered a quantity the store does not sell.
 *
 * The server-side refusal is not the bug and is not touched. The bug is that the
 * page proposes an invalid quantity in the first place.
 *
 * ---------------------------------------------------------------------------
 * WHY THE CORRECTION IS CLIENT-SIDE, WHICH LOOKS LIKE THE WRONG ANSWER
 * ---------------------------------------------------------------------------
 *
 * Quantity 1 is a literal in four places of FluentCart's single-product
 * template, and none of them is filterable
 * (fluent-cart/app/Services/Renderer/ProductRenderer.php):
 *
 *   :1330  min="1"                on #fct-product-qty-input
 *   :1341  value="1"              on the same input
 *   :1408  'data-quantity' => '1' on the Buy Now anchor
 *   :1413  href = ...&quantity=1  on the same anchor
 *
 * The only extension points in that method are
 * `fluent_cart/product/single/before_quantity_block` (:1309) and
 * `after_quantity_block` (:1358), and BOTH fire *outside* the container `<div>`
 * opened at :1313. So a PHP hook cannot reach those attributes: there is no seam
 * between "FluentCart decided the quantity" and "FluentCart printed it". The
 * markup can only be corrected after it exists, which means in the browser.
 *
 * This is a display correction, not enforcement. Enforcement stays where it was
 * — @see \FluentCartBulkOrder\Cart\RuleEnforcement, which still refuses anything
 * that reaches the server out of rule.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS NOT PART OF SingleProductTiers
 * ---------------------------------------------------------------------------
 *
 * They share a hook and nothing else. The Bulk Pricing block is a *preview of a
 * discount* and is gated on Gate 2 in the 'display' context, so a shopper the
 * pricing policy excludes never sees it. Order Rules bind every shopper
 * regardless of role — RuleEnforcement applies no role check — so hiding this
 * correction behind that gate would leave exactly the excluded shoppers stuck on
 * the "Product Not Found" page. Different audience, different class.
 */
class QuantityRules
{
    /**
     * Render the rule hint and hand the per-variant rules to the browser.
     *
     * Hooked on `fluent_cart/product/single/after_quantity_block` at priority 9,
     * i.e. one step ahead of the Bulk Pricing block, so the hint lands directly
     * under the quantity box it describes rather than below a pricing table.
     *
     * @param array $args Product context from FluentCart: ['product' => Product, 'scope' => string].
     * @return void
     */
    public static function render($args)
    {
        if (empty($args['product'])) {
            return;
        }

        $product = $args['product'];

        if (empty($product->ID) || empty($product->variants)) {
            return;
        }

        $rulesByVariant = self::collect($product);

        // The overwhelmingly common case: no variant of this product is
        // constrained. Emit nothing and enqueue nothing, so a store that does not
        // use Order Rules pays no bytes for this feature.
        if (empty($rulesByVariant)) {
            return;
        }

        self::enqueueAssets($rulesByVariant);

        $shared = self::sharedRules($rulesByVariant, count($product->variants));

        // Server-rendered only when every variant resolves to the SAME rules,
        // because that is the only case where the sentence is provably right
        // without knowing which variant FluentCart picked as the default — that
        // choice happens in ProductRenderer (:125-139) and is not passed to this
        // hook. Otherwise the element ships empty and single-product-qty.js fills
        // it from the container's data-cart-id, which is the authority on screen.
        printf(
            '<p class="fcbo-spq-hint" data-fcbo-qty-hint>%s</p>',
            esc_html($shared ? OrderRules::describe($shared, Strings::orderRuleHints()) : '')
        );
    }

    /**
     * The Order Rules of every variant of a product that actually has some.
     *
     * Unconstrained variants are left OUT rather than emitted as the no-op
     * default. The JS falls back to the default for any id it does not find, so
     * omission and "min 0, step 1" mean the same thing to the reader — and on a
     * product where one variant of forty is sold in cases, the payload is one
     * entry instead of forty.
     *
     * @param object $product FluentCart Product with `variants` loaded.
     * @return array<string,array{min_qty:int,step:int}> Keyed by variant id.
     */
    private static function collect($product)
    {
        $pricingData = fcbo_get_all_bulk_pricing([$product->ID]);
        $map         = [];

        foreach ($product->variants as $variant) {
            if (empty($variant->id)) {
                continue;
            }

            $rules = fcbo_resolve_order_rules($pricingData, $product->ID, $variant->id);

            if (!fcbo_order_rules_are_set($rules)) {
                continue;
            }

            // String keys: this array becomes a JSON object, whose keys are
            // strings either way. Making that explicit here means the PHP and the
            // JS agree on the lookup key instead of relying on json_encode's
            // int-key coercion.
            $map[(string) (int) $variant->id] = $rules;
        }

        return $map;
    }

    /**
     * The one rule pair that governs this product no matter which variant is
     * selected, or null when that cannot be said.
     *
     * "Cannot be said" covers two cases and both must return null: variants that
     * disagree, and a map that does not cover every variant (an uncovered variant
     * is unconstrained, which is itself a disagreement). Getting this wrong would
     * print a minimum next to a variant that has none.
     *
     * @param array $rulesByVariant Output of self::collect().
     * @param int   $variantCount   How many variants the product has in total.
     * @return array{min_qty:int,step:int}|null
     */
    public static function sharedRules($rulesByVariant, $variantCount)
    {
        $variantCount = (int) $variantCount;

        if ($variantCount < 1 || count($rulesByVariant) !== $variantCount) {
            return null;
        }

        $first = null;

        foreach ($rulesByVariant as $rules) {
            if ($first === null) {
                $first = $rules;
                continue;
            }

            if ($rules !== $first) {
                return null;
            }
        }

        return $first;
    }

    /**
     * Enqueue the rewrite script with this product's rules attached.
     *
     * @param array $rulesByVariant Output of self::collect().
     * @return void
     */
    private static function enqueueAssets($rulesByVariant)
    {
        // FluentCart renders exactly one quantity block per single-product page,
        // so a second call would be a template surprise, not a second product.
        // Bail rather than localize twice: two `fcboSpqConfig` assignments means
        // the last one wins, and it would silently be the wrong product's rules.
        static $enqueued = false;
        if ($enqueued) {
            return;
        }
        $enqueued = true;

        wp_enqueue_style(
            'fcbo-single-product-qty',
            FCBO_URL . 'assets/css/single-product-qty.css',
            [],
            FCBO_VERSION
        );

        wp_enqueue_script(
            'fcbo-single-product-qty',
            FCBO_URL . 'assets/js/single-product-qty.js',
            [],
            FCBO_VERSION,
            true
        );

        wp_localize_script('fcbo-single-product-qty', 'fcboSpqConfig', [
            'variants' => $rulesByVariant,
            'i18n'     => Strings::orderRuleHints(),
        ]);
    }
}
