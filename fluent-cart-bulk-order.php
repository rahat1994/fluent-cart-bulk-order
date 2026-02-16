<?php
/**
 * Plugin Name: Fluent Cart Bulk Order
 * Description: Adds a [fluent_cart_bulk_order] shortcode that renders an interactive bulk order table for FluentCart stores.
 * Version: 1.0.0
 * Author: Rahat Baksh
 * Requires PHP: 7.4
 * Text Domain: fluent-cart-bulk-order
 */

defined('ABSPATH') || exit;

define('FCBO_VERSION', '1.0.0');
define('FCBO_DIR', plugin_dir_path(__FILE__));
define('FCBO_URL', plugin_dir_url(__FILE__));

// Register wholesale-customer role on activation
register_activation_hook(__FILE__, function () {
    if (!get_role('wholesale-customer')) {
        add_role(
            'wholesale-customer',
            __('Wholesale Customer', 'fluent-cart-bulk-order'),
            get_role('customer') ? get_role('customer')->capabilities : get_role('subscriber')->capabilities
        );
    }
});

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
    add_shortcode('fluent_cart_product_table', 'fcbo_render_product_table');
    add_action('rest_api_init', 'fcbo_register_routes');

    add_action('fluent_cart/init', function () {
        require_once FCBO_DIR . 'includes/BulkPricingIntegration.php';
        (new \FluentCartBulkOrder\BulkPricingIntegration())->register();
    }, 20);

    // Enqueue admin CSS on FluentCart admin pages
    add_action('admin_enqueue_scripts', function ($hook) {
        if (strpos($hook, 'fluent-cart') === false) {
            return;
        }
        wp_enqueue_style(
            'fcbo-admin-bulk-pricing',
            FCBO_URL . 'assets/css/admin-bulk-pricing.css',
            [],
            FCBO_VERSION
        );
    });

    // Display bulk pricing tiers on single product page
    add_action('fluent_cart/product/single/after_quantity_block', 'fcbo_render_single_product_tiers', 10, 1);

    // Apply bulk pricing discount when items are added/updated in FluentCart's cart
    add_filter('fluent_cart/cart/item_modify', 'fcbo_apply_cart_bulk_pricing', 10, 2);
});

function fcbo_render_shortcode()
{
    // Only administrators and wholesale customers can access the bulk order form
    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('Please log in to access the bulk order form.', 'fluent-cart-bulk-order') . '</p>';
    }

    $user = wp_get_current_user();
    $allowed_roles = ['administrator', 'wholesale-customer'];

    if (!array_intersect($allowed_roles, $user->roles)) {
        return '<p>' . esc_html__('You do not have permission to access the bulk order form.', 'fluent-cart-bulk-order') . '</p>';
    }

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

    register_rest_route('fcbo/v1', '/catalog', [
        'methods'             => 'GET',
        'callback'            => 'fcbo_list_catalog',
        'permission_callback' => '__return_true',
        'args'                => [
            'page' => [
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'default'           => 20,
                'sanitize_callback' => 'absint',
            ],
            'search' => [
                'default'           => '',
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

function fcbo_render_product_table()
{
    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('Please log in to access the product table.', 'fluent-cart-bulk-order') . '</p>';
    }

    $user = wp_get_current_user();
    $allowed_roles = ['administrator', 'wholesale-customer'];

    if (!array_intersect($allowed_roles, $user->roles)) {
        return '<p>' . esc_html__('You do not have permission to access the product table.', 'fluent-cart-bulk-order') . '</p>';
    }

    if (class_exists(\FluentCart\App\Modules\Templating\AssetLoader::class)) {
        \FluentCart\App\Modules\Templating\AssetLoader::loadCartAssets();
        \FluentCart\App\Modules\Templating\AssetLoader::loadSingleProductAssets();
    }

    wp_enqueue_style(
        'fcbo-product-table',
        FCBO_URL . 'assets/css/product-table.css',
        [],
        FCBO_VERSION
    );

    wp_enqueue_script(
        'fcbo-product-table',
        FCBO_URL . 'assets/js/product-table.js',
        ['fluent-cart-app'],
        FCBO_VERSION,
        true
    );

    $currency_sign = '$';
    if (class_exists(\FluentCart\Api\CurrencySettings::class)) {
        $currency = \FluentCart\Api\CurrencySettings::get();
        if (!empty($currency['currency_sign'])) {
            $currency_sign = $currency['currency_sign'];
        }
    }

    wp_localize_script('fcbo-product-table', 'fcboPtConfig', [
        'rest_url'      => esc_url_raw(rest_url('fcbo/v1/')),
        'nonce'         => wp_create_nonce('wp_rest'),
        'currency_sign' => $currency_sign,
        'per_page'      => 5,
    ]);

    ob_start();
    ?>
    <div id="fcbo-product-table" class="fcbo-pt-wrap">
        <div class="fcbo-pt-toolbar">
            <input type="text" id="fcbo-pt-search" class="fcbo-pt-search"
                   placeholder="<?php esc_attr_e('Search products...', 'fluent-cart-bulk-order'); ?>" />
        </div>

        <div class="fcbo-pt-table-scroll">
            <table class="fcbo-pt-table">
                <thead>
                    <tr>
                        <th class="fcbo-pt-col-id"><?php esc_html_e('ID', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-pt-col-title"><?php esc_html_e('Title', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-pt-col-price"><?php esc_html_e('Price', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-pt-col-qty"><?php esc_html_e('Quantity', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-pt-col-action"><?php esc_html_e('Action', 'fluent-cart-bulk-order'); ?></th>
                    </tr>
                </thead>
                <tbody id="fcbo-pt-tbody">
                    <tr><td colspan="5" class="fcbo-pt-loading"><?php esc_html_e('Loading products...', 'fluent-cart-bulk-order'); ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="fcbo-pt-pagination">
            <button type="button" id="fcbo-pt-prev" class="fcbo-pt-page-btn" disabled>&laquo; <?php esc_html_e('Prev', 'fluent-cart-bulk-order'); ?></button>
            <span id="fcbo-pt-page-info" class="fcbo-pt-page-info"><?php esc_html_e('Page 1 of 1', 'fluent-cart-bulk-order'); ?></span>
            <button type="button" id="fcbo-pt-next" class="fcbo-pt-page-btn" disabled><?php esc_html_e('Next', 'fluent-cart-bulk-order'); ?> &raquo;</button>
        </div>

        <div id="fcbo-pt-status" class="fcbo-pt-status" style="display:none;"></div>
    </div>
    <?php
    return ob_get_clean();
}

function fcbo_list_catalog(\WP_REST_Request $request)
{
    $page     = max(1, $request->get_param('page'));
    $per_page = min(100, max(1, $request->get_param('per_page')));
    $search   = $request->get_param('search');

    $productModel = new \FluentCart\App\Models\Product();

    $query = $productModel::published()
        ->with(['variants' => function ($q) {
            $q->where('item_status', 'active');
        }]);

    if ($search && strlen($search) >= 2) {
        $query->where('post_title', 'LIKE', '%' . $GLOBALS['wpdb']->esc_like($search) . '%');
    }

    $total = $query->count();
    $totalPages = max(1, (int) ceil($total / $per_page));

    $products = $query
        ->orderBy('ID', 'DESC')
        ->offset(($page - 1) * $per_page)
        ->limit($per_page)
        ->get();

    $results = [];
    foreach ($products as $product) {
        $variants = [];
        if ($product->variants) {
            foreach ($product->variants as $variant) {
                $variants[] = [
                    'id'              => $variant->id,
                    'variation_title' => $variant->variation_title ?: 'Default',
                    'item_price'      => (int) $variant->item_price,
                    'stock_status'    => $variant->stock_status ?: 'in-stock',
                    'manage_stock'    => (int) ($variant->manage_stock ?? 0),
                    'available'       => (int) ($variant->available ?? 0),
                ];
            }
        }

        $results[] = [
            'id'       => $product->ID,
            'title'    => $product->post_title,
            'variants' => $variants,
        ];
    }

    return new \WP_REST_Response([
        'products'    => $results,
        'total'       => $total,
        'total_pages' => $totalPages,
        'page'        => $page,
    ], 200);
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
 * Get the store currency sign.
 *
 * @return string
 */
function fcbo_get_currency_sign()
{
    static $sign = null;
    if ($sign === null) {
        $sign = '$';
        if (class_exists(\FluentCart\Api\CurrencySettings::class)) {
            $currency = \FluentCart\Api\CurrencySettings::get();
            if (!empty($currency['currency_sign'])) {
                $sign = $currency['currency_sign'];
            }
        }
    }
    return $sign;
}

/**
 * Enqueue CSS and JS for the bulk pricing display.
 */
function fcbo_enqueue_bulk_pricing_assets()
{
    static $enqueued = false;
    if ($enqueued) {
        return;
    }
    $enqueued = true;

    wp_enqueue_style(
        'fcbo-bulk-pricing-display',
        FCBO_URL . 'assets/css/bulk-pricing-display.css',
        [],
        FCBO_VERSION
    );

    wp_enqueue_script(
        'fcbo-bulk-pricing-display',
        FCBO_URL . 'assets/js/bulk-pricing-display.js',
        [],
        FCBO_VERSION,
        true
    );

    wp_localize_script('fcbo-bulk-pricing-display', 'fcboBpConfig', [
        'currency_sign' => fcbo_get_currency_sign(),
    ]);
}

/**
 * Render the order table rows for variants.
 *
 * Each row has: title, quantity input, price cell (updated by JS).
 * Footer row has: grand total + Add to Cart button.
 *
 * @param array  $variants [{id, title, price, tiers}]
 * @param string $titleHeader Column header for the first column
 */
function fcbo_render_order_table($variants, $titleHeader)
{
    echo '<table class="fcbo-bp-order-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html($titleHeader) . '</th>';
    echo '<th>' . esc_html__('Quantity', 'fluent-cart-bulk-order') . '</th>';
    echo '<th>' . esc_html__('Total', 'fluent-cart-bulk-order') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($variants as $v) {
        $dataAttr = esc_attr(wp_json_encode([
            'id'    => (int) $v['id'],
            'price' => (int) $v['price'],
            'tiers' => $v['tiers'],
        ]));

        printf(
            '<tr data-fcbo-variant="%s"><td>%s</td><td><input type="number" class="fcbo-bp-qty-input" value="0" min="0" /></td><td class="fcbo-bp-price-cell"><span class="fcbo-bp-muted">&mdash;</span></td></tr>',
            $dataAttr,
            esc_html($v['title'])
        );
    }

    echo '</tbody><tfoot><tr>';
    echo '<td><strong>' . esc_html__('Total', 'fluent-cart-bulk-order') . '</strong></td>';
    echo '<td></td>';
    echo '<td class="fcbo-bp-grand-total"><span class="fcbo-bp-muted">&mdash;</span></td>';
    echo '</tr></tfoot></table>';
    echo '<div class="fcbo-bp-checkout-row">';
    echo '<button type="button" class="fcbo-bp-checkout-btn">' . esc_html__('Add to Cart', 'fluent-cart-bulk-order') . '</button>';
    echo '</div>';
}

/**
 * Render bulk pricing tiers on the single product page.
 *
 * Shows tier info followed by an order table with quantity inputs, live totals,
 * and a single Add to Cart button.
 *
 * @param array $args ['product' => Product, 'scope' => string]
 */
function fcbo_render_single_product_tiers($args)
{
    if (empty($args['product'])) {
        return;
    }

    $product = $args['product'];
    $pricingData = fcbo_get_all_bulk_pricing([$product->ID]);
    $isSimple = isset($product->detail->variation_type) && $product->detail->variation_type === 'simple';

    if ($isSimple) {
        $variant = $product->variants->first();
        if (!$variant) {
            return;
        }

        $tiers = fcbo_resolve_tiers($pricingData, $product->ID, $variant->id);
        if (empty($tiers)) {
            return;
        }

        fcbo_enqueue_bulk_pricing_assets();

        echo '<div class="fcbo-bp-wrap">';
        echo '<h4 class="fcbo-bp-heading">' . esc_html__('Bulk Pricing', 'fluent-cart-bulk-order') . '</h4>';
        echo '<div class="fcbo-bp-simple"><ul>';
        foreach ($tiers as $tier) {
            $minQty   = (int) ($tier['min_qty'] ?? 0);
            $maxQty   = (int) ($tier['max_qty'] ?? 0);
            $discount = (float) ($tier['discount_value'] ?? 0);

            $range = $maxQty > 0
                ? sprintf('%d – %d', $minQty, $maxQty)
                : sprintf('%d+', $minQty);

            printf(
                '<li>' . esc_html__('Buy %s:', 'fluent-cart-bulk-order') . ' <span class="fcbo-bp-discount">%s%% ' . esc_html__('off', 'fluent-cart-bulk-order') . '</span></li>',
                esc_html($range),
                esc_html(rtrim(rtrim(number_format($discount, 2), '0'), '.'))
            );
        }
        echo '</ul></div>';

        fcbo_render_order_table([
            [
                'id'    => $variant->id,
                'title' => $product->post_title,
                'price' => (int) $variant->item_price,
                'tiers' => $tiers,
            ],
        ], __('Product', 'fluent-cart-bulk-order'));

        echo '</div>';
        return;
    }

    // Variable product: collect variants that have tiers
    $variantsWithTiers = [];
    foreach ($product->variants as $variant) {
        $tiers = fcbo_resolve_tiers($pricingData, $product->ID, $variant->id);
        if (empty($tiers)) {
            continue;
        }
        $variantsWithTiers[] = [
            'id'    => $variant->id,
            'title' => $variant->variation_title ?: 'Default',
            'price' => (int) $variant->item_price,
            'tiers' => $tiers,
        ];
    }

    if (empty($variantsWithTiers)) {
        return;
    }

    fcbo_enqueue_bulk_pricing_assets();

    // Check if all variants share identical tiers — collapse if so
    $allSame = true;
    $firstTiers = $variantsWithTiers[0]['tiers'];
    for ($i = 1, $len = count($variantsWithTiers); $i < $len; $i++) {
        if ($variantsWithTiers[$i]['tiers'] !== $firstTiers) {
            $allSame = false;
            break;
        }
    }

    echo '<div class="fcbo-bp-wrap">';
    echo '<h4 class="fcbo-bp-heading">' . esc_html__('Bulk Pricing', 'fluent-cart-bulk-order') . '</h4>';

    // Tier info table
    echo '<table class="fcbo-bp-table">';
    if ($allSame) {
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Qty Range', 'fluent-cart-bulk-order') . '</th>';
        echo '<th>' . esc_html__('Discount', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($firstTiers as $tier) {
            $minQty   = (int) ($tier['min_qty'] ?? 0);
            $maxQty   = (int) ($tier['max_qty'] ?? 0);
            $discount = (float) ($tier['discount_value'] ?? 0);
            $range = $maxQty > 0 ? sprintf('%d – %d', $minQty, $maxQty) : sprintf('%d+', $minQty);

            printf(
                '<tr><td>%s</td><td class="fcbo-bp-discount">%s%% %s</td></tr>',
                esc_html($range),
                esc_html(rtrim(rtrim(number_format($discount, 2), '0'), '.')),
                esc_html__('off', 'fluent-cart-bulk-order')
            );
        }
    } else {
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Variant', 'fluent-cart-bulk-order') . '</th>';
        echo '<th>' . esc_html__('Qty Range', 'fluent-cart-bulk-order') . '</th>';
        echo '<th>' . esc_html__('Discount', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($variantsWithTiers as $entry) {
            foreach ($entry['tiers'] as $idx => $tier) {
                $minQty   = (int) ($tier['min_qty'] ?? 0);
                $maxQty   = (int) ($tier['max_qty'] ?? 0);
                $discount = (float) ($tier['discount_value'] ?? 0);
                $range = $maxQty > 0 ? sprintf('%d – %d', $minQty, $maxQty) : sprintf('%d+', $minQty);

                echo '<tr>';
                if ($idx === 0) {
                    printf(
                        '<td rowspan="%d">%s</td>',
                        count($entry['tiers']),
                        esc_html($entry['title'])
                    );
                }
                printf(
                    '<td>%s</td><td class="fcbo-bp-discount">%s%% %s</td>',
                    esc_html($range),
                    esc_html(rtrim(rtrim(number_format($discount, 2), '0'), '.')),
                    esc_html__('off', 'fluent-cart-bulk-order')
                );
                echo '</tr>';
            }
        }
    }
    echo '</tbody></table>';

    fcbo_render_order_table($variantsWithTiers, __('Variant', 'fluent-cart-bulk-order'));

    echo '</div>';
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
