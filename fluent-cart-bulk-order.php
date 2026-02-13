<?php
/**
 * Plugin Name: Fluent Cart Bulk Order
 * Description: Adds a [fluent_cart_bulk_order] shortcode that renders an interactive bulk order table for FluentCart stores.
 * Version: 1.0.0
 * Author: WPManageNinja
 * Requires PHP: 7.4
 * Text Domain: fluent-cart-bulk-order
 */

defined('ABSPATH') || exit;

define('FCBO_VERSION', '1.0.0');
define('FCBO_DIR', plugin_dir_path(__FILE__));
define('FCBO_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Fluent Cart Bulk Order requires the FluentCart plugin to be installed and activated.', 'fluent-cart-bulk-order');
            echo '</p></div>';
        });
        return;
    }

    add_shortcode('fluent_cart_bulk_order', 'fcbo_render_shortcode');
    add_action('rest_api_init', 'fcbo_register_routes');

    add_action('fluent_cart/init', function () {
        require_once FCBO_DIR . 'includes/BulkPricingIntegration.php';
        (new \FluentCartBulkOrder\BulkPricingIntegration())->register();
    }, 20);

    // Apply bulk pricing discount when items are added/updated in FluentCart's cart
    add_filter('fluent_cart/cart/item_modify', 'fcbo_apply_cart_bulk_pricing', 10, 2);
});

function fcbo_render_shortcode()
{
    // Load FluentCart's cart assets so window.fluentCartCart is available
    if (class_exists(\FluentCart\App\Modules\Templating\AssetLoader::class)) {
        \FluentCart\App\Modules\Templating\AssetLoader::loadCartAssets();
    }

    wp_enqueue_style(
        'fcbo-bulk-order',
        FCBO_URL . 'assets/css/bulk-order.css',
        [],
        FCBO_VERSION
    );

    wp_enqueue_script(
        'fcbo-bulk-order',
        FCBO_URL . 'assets/js/bulk-order.js',
        ['fluent-cart-app'],
        FCBO_VERSION,
        true
    );

    // Build config for JS
    $checkout_url = '';
    if (class_exists(\FluentCart\Api\StoreSettings::class)) {
        $checkout_url = (new \FluentCart\Api\StoreSettings())->getCheckoutPage();
    }

    $currency_sign = '$';
    if (class_exists(\FluentCart\Api\CurrencySettings::class)) {
        $currency = \FluentCart\Api\CurrencySettings::get();
        if (!empty($currency['currency_sign'])) {
            $currency_sign = $currency['currency_sign'];
        }
    }

    wp_localize_script('fcbo-bulk-order', 'fcboConfig', [
        'rest_url'      => esc_url_raw(rest_url('fcbo/v1/')),
        'nonce'         => wp_create_nonce('wp_rest'),
        'checkout_url'  => esc_url_raw($checkout_url),
        'currency_sign' => $currency_sign,
    ]);

    ob_start();
    ?>
    <div id="fcbo-bulk-order" class="fcbo-wrap">
        <div class="fcbo-table-scroll">
            <table class="fcbo-table">
                <thead>
                    <tr>
                        <th class="fcbo-col-remove"></th>
                        <th class="fcbo-col-product"><?php esc_html_e('Product', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-col-sku"><?php esc_html_e('SKU', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-col-categories"><?php esc_html_e('Categories', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-col-image"><?php esc_html_e('Image', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-col-amount"><?php esc_html_e('Amount', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-col-qty"><?php esc_html_e('Qty', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-col-total"><?php esc_html_e('Total', 'fluent-cart-bulk-order'); ?></th>
                    </tr>
                </thead>
                <tbody id="fcbo-tbody"></tbody>
                <tfoot>
                    <tr>
                        <td colspan="7"></td>
                        <td class="fcbo-col-total fcbo-grand-total" id="fcbo-grand-total"><?php echo esc_html($currency_sign); ?>0.00</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="fcbo-actions">
            <button type="button" id="fcbo-add-row" class="fcbo-btn fcbo-btn-secondary">
                <?php esc_html_e('+ Add Row', 'fluent-cart-bulk-order'); ?>
            </button>
            <button type="button" id="fcbo-checkout" class="fcbo-btn fcbo-btn-primary">
                <?php esc_html_e('Proceed to Checkout', 'fluent-cart-bulk-order'); ?>
            </button>
        </div>

        <div id="fcbo-status" class="fcbo-status" style="display:none;"></div>
    </div>
    <?php
    return ob_get_clean();
}

function fcbo_register_routes()
{
    register_rest_route('fcbo/v1', '/products', [
        'methods'             => 'GET',
        'callback'            => 'fcbo_search_products',
        'permission_callback' => '__return_true',
        'args'                => [
            'search' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);
}

function fcbo_search_products(\WP_REST_Request $request)
{
    $search = $request->get_param('search');

    if (strlen($search) < 2) {
        return new \WP_REST_Response(['products' => []], 200);
    }

    $productModel = new \FluentCart\App\Models\Product();

    $products = $productModel::published()
        ->with(['detail', 'variants' => function ($query) {
            $query->where('item_status', 'active');
        }])
        ->where('post_title', 'LIKE', '%' . $GLOBALS['wpdb']->esc_like($search) . '%')
        ->limit(20)
        ->get();

    $productIds = [];
    foreach ($products as $product) {
        $productIds[] = $product->ID;
    }

    $pricingData = fcbo_get_all_bulk_pricing($productIds);

    $results = [];

    foreach ($products as $product) {
        $categories = get_the_terms($product->ID, 'product-categories');
        $catList = [];
        if ($categories && !is_wp_error($categories)) {
            foreach ($categories as $cat) {
                $catList[] = [
                    'term_id' => $cat->term_id,
                    'name'    => $cat->name,
                ];
            }
        }

        $variants = [];
        if ($product->variants) {
            foreach ($product->variants as $variant) {
                $variants[] = [
                    'id'              => $variant->id,
                    'variation_title' => $variant->variation_title ?: 'Default',
                    'item_price'      => (int) $variant->item_price,
                    'sku'             => $variant->sku ?: '',
                    'stock_status'    => $variant->stock_status ?: 'in-stock',
                    'payment_type'    => $variant->payment_type ?: 'onetime',
                    'manage_stock'    => (int) ($variant->manage_stock ?? 0),
                    'available'       => (int) ($variant->available ?? 0),
                    'bulk_tiers'      => fcbo_resolve_tiers($pricingData, $product->ID, $variant->id),
                ];
            }
        }

        $results[] = [
            'id'         => $product->ID,
            'title'      => $product->post_title,
            'thumbnail'  => $product->thumbnail ?: '',
            'categories' => $catList,
            'variants'   => $variants,
        ];
    }

    return new \WP_REST_Response(['products' => $results], 200);
}

/**
 * Fetch all bulk pricing data in two batched queries.
 *
 * @param int[] $productIds
 * @return array{global: array, product: array<int, array>}
 */
function fcbo_get_all_bulk_pricing($productIds)
{
    static $globalTiers = null;

    // 1. Global tiers (cached across calls within the same request)
    if ($globalTiers === null) {
        $globalTiers = [];
        $globalFeed = \FluentCart\App\Models\Meta::query()
            ->where('object_type', 'order_integration')
            ->where('meta_key', 'fcbo_bulk_pricing')
            ->first();

        if ($globalFeed) {
            $feedData = $globalFeed->meta_value;
            if (!empty($feedData['enabled']) && $feedData['enabled'] === 'yes' && !empty($feedData['tiers'])) {
                $globalTiers = $feedData['tiers'];
            }
        }
    }

    // 2. Product-level tiers (batch query)
    $productFeeds = [];
    if (!empty($productIds)) {
        $feeds = \FluentCart\App\Models\ProductMeta::query()
            ->where('object_type', 'product_integration')
            ->where('meta_key', 'fcbo_bulk_pricing')
            ->whereIn('object_id', $productIds)
            ->get();

        foreach ($feeds as $feed) {
            $feedData = $feed->meta_value;
            if (empty($feedData['enabled']) || $feedData['enabled'] !== 'yes' || empty($feedData['tiers'])) {
                continue;
            }

            $pid = (int) $feed->object_id;
            if (!isset($productFeeds[$pid])) {
                $productFeeds[$pid] = [];
            }

            $variantIds = [];
            if (!empty($feedData['conditional_variation_ids']) && is_array($feedData['conditional_variation_ids'])) {
                $variantIds = array_map('intval', $feedData['conditional_variation_ids']);
            }

            $productFeeds[$pid][] = [
                'variant_ids' => $variantIds,
                'tiers'       => $feedData['tiers'],
            ];
        }
    }

    return [
        'global'  => $globalTiers,
        'product' => $productFeeds,
    ];
}

/**
 * Resolve the effective discount tiers for a specific product variant.
 * Product-specific feeds take precedence over global tiers.
 *
 * @param array $pricingData From fcbo_get_all_bulk_pricing()
 * @param int   $productId
 * @param int   $variantId
 * @return array Tier list (may be empty)
 */
function fcbo_resolve_tiers($pricingData, $productId, $variantId)
{
    // Check product-level feeds first
    if (!empty($pricingData['product'][$productId])) {
        foreach ($pricingData['product'][$productId] as $feed) {
            // Empty variant_ids means applies to all variants
            if (empty($feed['variant_ids']) || in_array((int) $variantId, $feed['variant_ids'], true)) {
                return $feed['tiers'];
            }
        }
    }

    // Fall back to global tiers
    return $pricingData['global'] ?? [];
}

/**
 * FluentCart filter callback: apply bulk pricing discount to cart item price.
 *
 * Fires when an item is added or its quantity is updated in the cart.
 * The variation is loaded fresh from the DB each time, so item_price is always the original.
 *
 * @param object $variation The variant model object
 * @param array  $context   ['item_id' => int, 'quantity' => int]
 * @return object Modified variation
 */
function fcbo_apply_cart_bulk_pricing($variation, $context)
{
    if (!$variation || empty($context['quantity'])) {
        return $variation;
    }

    $qty       = (int) $context['quantity'];
    $productId = (int) $variation->post_id;
    $variantId = (int) $variation->id;

    $pricingData = fcbo_get_all_bulk_pricing([$productId]);
    $tiers       = fcbo_resolve_tiers($pricingData, $productId, $variantId);

    if (empty($tiers)) {
        return $variation;
    }

    foreach ($tiers as $tier) {
        $minQty = (int) ($tier['min_qty'] ?? 0);
        $maxQty = (int) ($tier['max_qty'] ?? 0);

        if ($qty >= $minQty && ($maxQty === 0 || $qty <= $maxQty)) {
            $discountValue = (float) ($tier['discount_value'] ?? 0);
            $variation->item_price = (int) round($variation->item_price * (1 - $discountValue / 100));
            break;
        }
    }

    return $variation;
}
