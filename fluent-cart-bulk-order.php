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
    add_shortcode('fluent_cart_saved_orders', 'fcbo_render_saved_orders');
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

    // ---- Server-side order-rule enforcement (Plan 009 · R5) ----
    //
    // These two filters are the authoritative gate. Everything the JS does about
    // min-qty, case-pack steps, and the order minimum is convenience only and is
    // assumed bypassable; a crafted request is stopped here.
    //
    // `fluent_cart/variation/can_purchase_bundle` is named for bundles but is NOT
    // bundle-specific — it fires inside the generic ProductVariation::canPurchase()
    // (fluent-cart/app/Models/ProductVariation.php:270), so it reaches every caller
    // of that method. Do not "correct" it to a more obvious-sounding hook — the
    // obvious ones cannot veto. Returning a WP_Error preserves our message;
    // returning bare `false` would be replaced with FluentCart's generic
    // out-of-stock text.
    //
    // Covered: normal add-to-cart (via Cart::addByVariation) and instant checkout
    // (CartResource::generateCartForInstantCheckout, which tests is_wp_error).
    //
    // NOT covered — order bump / variation upgrade. WebCheckoutHandler.php:1065
    // guards with `!$productVariation->canPurchase()`, and a WP_Error object is
    // truthy in PHP, so our refusal is discarded and the item is added anyway.
    // That path also passes no quantity, so there is nothing for a quantity rule
    // to judge. This is a host-side defect an extension cannot fix from a filter;
    // it is documented rather than worked around. See
    // docs/solutions/architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout.md
    add_filter('fluent_cart/variation/can_purchase_bundle', 'fcbo_validate_cart_item_rules', 10, 2);

    // Checkout backstop for the order minimum. Chosen over
    // `fluent_cart/checkout/validate_before_process` because this one receives the
    // resolved $cart, which a whole-cart total rule needs
    // (fluent-cart/api/Checkout/CheckoutApi.php:1039).
    add_filter('fluent_cart/checkout/validate_data', 'fcbo_validate_checkout_minimum', 10, 2);

    // Admin settings page for the "apply bulk pricing to roles" policy.
    require_once FCBO_DIR . 'includes/Settings.php';
    (new \FluentCartBulkOrder\Settings())->register();
});

/**
 * Roles allowed to use the FCBO surfaces (bulk order form, product table, REST routes).
 *
 * Single source of truth for access control. Filterable so other features can
 * extend the set without re-duplicating the list.
 *
 * @return string[] Role slugs.
 */
function fcbo_get_allowed_roles()
{
    return apply_filters('fcbo/allowed_roles', ['administrator', 'wholesale-customer']);
}

/**
 * Whether the current user may access FCBO surfaces.
 *
 * @param string[] $extraRoles Additional role slugs (e.g. from a shortcode `roles`
 *                             attribute) that EXTEND the baseline allowed set for
 *                             this render. The baseline from fcbo_get_allowed_roles()
 *                             always remains — extra roles can only widen, never
 *                             replace, the security baseline.
 *
 * CAVEAT: extra roles passed here only widen the SHORTCODE/UI gate. The REST routes
 * (`/products`, `/catalog`) call fcbo_current_user_can_access() with NO extra roles
 * (via fcbo_rest_permission_check), so they enforce only the GLOBAL set from
 * fcbo_get_allowed_roles(). A role granted access solely through a per-shortcode
 * `roles` attribute can render the UI but its AJAX product calls will still be
 * rejected. To widen REST access too, add the role via the global `fcbo/allowed_roles`
 * filter.
 *
 * @return bool
 */
function fcbo_current_user_can_access($extraRoles = [])
{
    if (!is_user_logged_in()) {
        return false;
    }

    // Super admins (and single-site administrators) always have access — role-slug
    // checks alone would lock out a multisite super admin who isn't a member of the
    // current subsite.
    if (is_super_admin()) {
        return true;
    }

    $allowed = fcbo_get_allowed_roles();

    if (!empty($extraRoles)) {
        // Merge onto (never replace) the baseline so admin + wholesale always remain.
        $allowed = array_merge($allowed, array_map('sanitize_key', (array) $extraRoles));
    }

    return (bool) array_intersect($allowed, wp_get_current_user()->roles);
}

/**
 * Parse a comma-separated shortcode `roles` attribute into sanitized role slugs.
 *
 * Each token is trimmed and passed through sanitize_key; empty tokens are dropped.
 * A malformed value such as " , ," degrades to an empty array (baseline only).
 *
 * @param string $rolesAttr Raw attribute value.
 * @return string[] Sanitized role slugs.
 */
function fcbo_parse_roles_attr($rolesAttr)
{
    if (empty($rolesAttr) || !is_string($rolesAttr)) {
        return [];
    }

    $roles = array_map('sanitize_key', array_map('trim', explode(',', $rolesAttr)));

    return array_values(array_filter($roles));
}

/**
 * Sanitize a `category` value to a slug or a numeric term ID.
 *
 * @param mixed $value Raw attribute or request value.
 * @return string Sanitized slug or numeric ID as a string; '' when empty.
 */
function fcbo_sanitize_category_param($value)
{
    if (is_string($value)) {
        $value = trim($value);
    }

    if ($value === '' || $value === null) {
        return '';
    }

    if (is_numeric($value)) {
        return (string) absint($value);
    }

    return sanitize_title((string) $value);
}

/**
 * Resolve a slug-or-ID category value to a `product-categories` WP term.
 *
 * @param string $category Slug or numeric term ID.
 * @return \WP_Term|null The resolved term, or null when it does not exist.
 */
function fcbo_resolve_category_term($category)
{
    if ($category === '' || $category === null) {
        return null;
    }

    if (is_numeric($category)) {
        $term = get_term((int) $category, 'product-categories');
    } else {
        $term = get_term_by('slug', $category, 'product-categories');
    }

    if (!$term || is_wp_error($term)) {
        return null;
    }

    return $term;
}

/**
 * Resolve a shortcode `columns` attribute to an ordered allowlist of columns.
 *
 * Unknown tokens are ignored; an empty/absent value yields all columns. Ordering is
 * fixed to the canonical column order (column reordering is a non-goal) so the PHP
 * header and the JS body stay aligned.
 *
 * @param string   $columnsAttr Raw attribute value.
 * @param string[] $allColumns  Canonical, ordered column list.
 * @return string[] Resolved column list (never empty).
 */
function fcbo_parse_columns_attr($columnsAttr, $allColumns)
{
    if (empty($columnsAttr) || !is_string($columnsAttr)) {
        return $allColumns;
    }

    $requested = array_map('sanitize_key', array_map('trim', explode(',', strtolower($columnsAttr))));

    // Intersect in canonical order so header/body order is deterministic.
    $resolved = array_values(array_intersect($allColumns, $requested));

    return empty($resolved) ? $allColumns : $resolved;
}

/**
 * REST permission callback for the FCBO endpoints.
 *
 * Mirrors the shortcode access gate: unauthenticated requests get 401,
 * authenticated-but-unauthorized requests get 403.
 *
 * @return true|\WP_Error
 */
function fcbo_rest_permission_check()
{
    if (!is_user_logged_in()) {
        return new \WP_Error(
            'fcbo_rest_unauthorized',
            __('You must be logged in to access this resource.', 'fluent-cart-bulk-order'),
            ['status' => 401]
        );
    }

    if (!fcbo_current_user_can_access()) {
        return new \WP_Error(
            'fcbo_rest_forbidden',
            __('You do not have permission to access this resource.', 'fluent-cart-bulk-order'),
            ['status' => 403]
        );
    }

    return true;
}

function fcbo_render_shortcode($atts = [])
{
    $atts = shortcode_atts([
        'roles'    => '',
        'redirect' => '',
    ], $atts, 'fluent_cart_bulk_order');

    // `roles` EXTENDS (never replaces) the baseline admin + wholesale set for this
    // placement. See the caveat on fcbo_current_user_can_access(): this only widens the
    // UI gate, not the REST routes.
    $extraRoles = fcbo_parse_roles_attr($atts['roles']);

    // Only administrators, wholesale customers, and any extra roles can access the form
    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('Please log in to access the bulk order form.', 'fluent-cart-bulk-order') . '</p>';
    }

    if (!fcbo_current_user_can_access($extraRoles)) {
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

    // Optional `redirect` override: only honored when it is a valid same-site URL.
    // wp_validate_redirect() returns the fallback ('') for off-site or malformed URLs,
    // so an invalid value degrades safely to the store checkout page.
    $redirect = trim((string) $atts['redirect']);
    if ($redirect !== '') {
        $validated = wp_validate_redirect($redirect, '');
        if ($validated !== '') {
            $checkout_url = $validated;
        }
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
        // Order-total floor for this shopper. Sent as 0 when they are not
        // subject, so the client never has to reason about role policy.
        'min_order_total' => fcbo_user_subject_to_min_order() ? fcbo_get_min_order_total() : 0,
    ]);

    ob_start();
    ?>
    <div id="fcbo-bulk-order" class="fcbo-wrap">
        <div class="fcbo-quick-order">
            <button type="button" id="fcbo-quick-toggle" class="fcbo-quick-toggle"
                    aria-expanded="false" aria-controls="fcbo-quick-panel">
                <span class="fcbo-quick-caret" aria-hidden="true">&#9656;</span>
                <?php esc_html_e('Quick order (paste or CSV)', 'fluent-cart-bulk-order'); ?>
            </button>
            <div id="fcbo-quick-panel" class="fcbo-quick-panel" hidden>
                <p class="fcbo-quick-help">
                    <?php esc_html_e('Paste one "SKU, quantity" per line, or upload a CSV. An optional header row is ignored.', 'fluent-cart-bulk-order'); ?>
                </p>
                <textarea id="fcbo-quick-input" class="fcbo-quick-input" rows="6"
                          placeholder="SKU, Qty&#10;ABC-123, 10&#10;XYZ-9, 5"></textarea>
                <div class="fcbo-quick-controls">
                    <label class="fcbo-quick-file-label">
                        <?php esc_html_e('Upload CSV', 'fluent-cart-bulk-order'); ?>
                        <input type="file" id="fcbo-quick-file" class="fcbo-quick-file" accept=".csv,text/csv" />
                    </label>
                    <button type="button" id="fcbo-quick-add" class="fcbo-btn fcbo-btn-primary">
                        <?php esc_html_e('Add to order', 'fluent-cart-bulk-order'); ?>
                    </button>
                </div>
                <div id="fcbo-quick-report" class="fcbo-quick-report" style="display:none;"></div>
            </div>
        </div>

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
            <div class="fcbo-actions-left">
                <button type="button" id="fcbo-add-row" class="fcbo-btn fcbo-btn-secondary">
                    <?php esc_html_e('+ Add Row', 'fluent-cart-bulk-order'); ?>
                </button>
                <button type="button" id="fcbo-save-order" class="fcbo-btn fcbo-btn-secondary">
                    <?php esc_html_e('Save order', 'fluent-cart-bulk-order'); ?>
                </button>
            </div>
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
        'permission_callback' => 'fcbo_rest_permission_check',
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
        'permission_callback' => 'fcbo_rest_permission_check',
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
            'category' => [
                'default'           => '',
                'sanitize_callback' => 'fcbo_sanitize_category_param',
            ],
        ],
    ]);

    // Exact, batched SKU -> variant resolver for the paste/CSV quick-order feature.
    // Kept separate from /products (partial-match autocomplete) so neither regresses:
    // this route resolves each SKU to exactly one active variant, or reports it as
    // ambiguous / unknown.
    register_rest_route('fcbo/v1', '/resolve-skus', [
        'methods'             => 'POST',
        'callback'            => 'fcbo_resolve_skus',
        'permission_callback' => 'fcbo_rest_permission_check',
        'args'                => [
            'skus' => [
                'required'          => true,
                'type'              => 'array',
                'items'             => ['type' => 'string'],
                'sanitize_callback' => 'fcbo_sanitize_skus_param',
            ],
        ],
    ]);

    // Per-user saved orders: list / create-or-replace / delete. All owner-scoped
    // to the current user inside the callbacks; no user id is accepted from the
    // request.
    register_rest_route('fcbo/v1', '/saved-lists', [
        [
            'methods'             => 'GET',
            'callback'            => 'fcbo_rest_get_saved_lists',
            'permission_callback' => 'fcbo_rest_permission_check',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'fcbo_rest_save_list',
            'permission_callback' => 'fcbo_rest_permission_check',
            'args'                => [
                'name'  => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'items' => [
                    'required' => true,
                    'type'     => 'array',
                ],
            ],
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'fcbo_rest_delete_saved_list',
            'permission_callback' => 'fcbo_rest_permission_check',
            'args'                => [
                'name' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ],
    ]);

    // Read-only: the current user's recent paid orders, for one-click reorder.
    register_rest_route('fcbo/v1', '/past-orders', [
        'methods'             => 'GET',
        'callback'            => 'fcbo_rest_get_past_orders',
        'permission_callback' => 'fcbo_rest_permission_check',
    ]);
}

/**
 * Sanitize the `skus` request param into a de-duplicated list of trimmed strings.
 *
 * Non-scalar and blank entries are dropped; duplicates are removed
 * case-insensitively (first spelling wins). The list is capped to bound the
 * resolver query for pathological pastes.
 *
 * @param mixed $value Raw request value.
 * @return string[] Clean, de-duplicated SKU strings (max 500).
 */
function fcbo_sanitize_skus_param($value)
{
    if (!is_array($value)) {
        return [];
    }

    $clean = [];
    $seen  = [];

    foreach ($value as $sku) {
        if (!is_scalar($sku)) {
            continue;
        }

        $sku = trim(sanitize_text_field((string) $sku));
        if ($sku === '') {
            continue;
        }

        $key = strtolower($sku);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $clean[]    = $sku;

        if (count($clean) >= 500) {
            break;
        }
    }

    return $clean;
}

/**
 * Build the category list payload for a product.
 *
 * @param int $productId
 * @return array<int, array{term_id:int, name:string}>
 */
function fcbo_build_category_list($productId)
{
    $categories = get_the_terms($productId, 'product-categories');
    $catList = [];

    if ($categories && !is_wp_error($categories)) {
        foreach ($categories as $cat) {
            $catList[] = [
                'term_id' => $cat->term_id,
                'name'    => $cat->name,
            ];
        }
    }

    return $catList;
}

/**
 * Build the per-variant payload shared by the search and resolve-skus endpoints.
 *
 * Keeping this in one place guarantees both surfaces (and the client code that
 * consumes them via selectProduct()) see an identical variant shape, including
 * the resolved bulk tiers.
 *
 * @param object        $product     Product model (needs ID + thumbnail).
 * @param object        $variant     Variant model.
 * @param array         $pricingData From fcbo_get_all_bulk_pricing().
 * @param string[]|null $userRoles   Viewer's role slugs, so bulk_tiers is
 *                                   role-resolved server-side (the client never
 *                                   sees other roles' pricing). null = default set.
 * @return array
 */
function fcbo_build_variant_payload($product, $variant, $pricingData, $userRoles = null)
{
    return [
        'id'              => $variant->id,
        'variation_title' => $variant->variation_title ?: 'Default',
        'item_price'      => (int) $variant->item_price,
        'sku'             => $variant->sku ?: '',
        'stock_status'    => $variant->stock_status ?: 'in-stock',
        'payment_type'    => $variant->payment_type ?: 'onetime',
        'manage_stock'    => (int) ($variant->manage_stock ?? 0),
        'available'       => (int) ($variant->available ?? 0),
        'thumbnail'       => $variant->thumbnail ?: ($product->thumbnail ?: ''),
        'bulk_tiers'      => fcbo_resolve_tiers($pricingData, $product->ID, $variant->id, $userRoles),
        'order_rules'     => fcbo_resolve_order_rules($pricingData, $product->ID, $variant->id),
    ];
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
            $query->where('item_status', 'active')->with('media');
        }])
        ->where(function ($q) use ($search) {
            $like = '%' . $GLOBALS['wpdb']->esc_like($search) . '%';
            $q->where('post_title', 'LIKE', $like)
              ->orWhereHas('variants', function ($vq) use ($like) {
                  $vq->where('item_status', 'active')
                     ->where(function ($inner) use ($like) {
                         $inner->where('sku', 'LIKE', $like)
                               ->orWhere('variation_title', 'LIKE', $like);
                     });
              });
        })
        ->limit(20)
        ->get();

    $productIds = [];
    foreach ($products as $product) {
        $productIds[] = $product->ID;
    }

    $pricingData = fcbo_get_all_bulk_pricing($productIds);
    // Serialize role-resolved tiers to the (authenticated) searcher — no role logic
    // ships to the browser.
    $userRoles   = (array) wp_get_current_user()->roles;

    $results = [];

    foreach ($products as $product) {
        $catList = fcbo_build_category_list($product->ID);

        // If the product matched on its title, show all variants (name search).
        // If it matched only through a variant SKU / variation title, surface just
        // the matching variant(s) so a SKU search returns the exact variant instead
        // of every variant of the product.
        $titleMatches = stripos($product->post_title, $search) !== false;

        $variants = [];
        if ($product->variants) {
            foreach ($product->variants as $variant) {
                $variantMatches = ($variant->sku && stripos($variant->sku, $search) !== false)
                    || ($variant->variation_title && stripos($variant->variation_title, $search) !== false);

                if (!$titleMatches && !$variantMatches) {
                    continue;
                }

                $variants[] = fcbo_build_variant_payload($product, $variant, $pricingData, $userRoles);
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
 * Resolve a batch of SKUs to their exact active variant in a single query.
 *
 * Powers the paste/CSV quick-order feature. Each requested SKU is classified:
 *   - matched   => exactly one active variant carries the SKU (payload included)
 *   - ambiguous => more than one active variant carries the SKU (candidates included)
 *   - unknown   => no active variant carries the SKU
 *
 * The response is keyed by the case-normalized SKU so the client can look each
 * pasted line up directly. Matched/candidate payloads use the same shape as
 * fcbo_search_products() so the client can reuse selectProduct() unchanged.
 *
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response
 */
function fcbo_resolve_skus(\WP_REST_Request $request)
{
    $skus = (array) $request->get_param('skus');

    if (empty($skus)) {
        return new \WP_REST_Response(['resolved' => (object) []], 200);
    }

    // Map normalized (lowercase) SKU => the spelling the client sent, so every
    // requested SKU gets a status entry even when it matches nothing.
    $requested = [];
    foreach ($skus as $sku) {
        $requested[strtolower($sku)] = $sku;
    }

    $productModel = new \FluentCart\App\Models\Product();

    // One query: products having an active variant whose SKU is in the batch.
    $products = $productModel::published()
        ->with(['detail', 'variants' => function ($query) {
            $query->where('item_status', 'active')->with('media');
        }])
        ->whereHas('variants', function ($vq) use ($skus) {
            $vq->where('item_status', 'active')->whereIn('sku', $skus);
        })
        ->get();

    $productIds = [];
    foreach ($products as $product) {
        $productIds[] = $product->ID;
    }

    $pricingData = fcbo_get_all_bulk_pricing($productIds);
    // Role-resolve tiers for the authenticated requester (see fcbo_build_variant_payload).
    $userRoles   = (array) wp_get_current_user()->roles;

    // Collect every active variant whose SKU was requested, grouped by normalized
    // SKU. A product loaded above carries all its active variants, so filter to
    // only the ones actually asked for.
    $bySku = [];
    foreach ($products as $product) {
        if (!$product->variants) {
            continue;
        }

        $catList = fcbo_build_category_list($product->ID);

        foreach ($product->variants as $variant) {
            if (!$variant->sku) {
                continue;
            }

            $key = strtolower(trim($variant->sku));
            if (!isset($requested[$key])) {
                continue;
            }

            $bySku[$key][] = [
                'productId'  => $product->ID,
                'title'      => $product->post_title,
                'thumbnail'  => $variant->thumbnail ?: ($product->thumbnail ?: ''),
                'categories' => $catList,
                'variant'    => fcbo_build_variant_payload($product, $variant, $pricingData, $userRoles),
            ];
        }
    }

    $resolved = [];
    foreach ($requested as $key => $original) {
        if (empty($bySku[$key])) {
            $resolved[$key] = ['status' => 'unknown'];
        } elseif (count($bySku[$key]) === 1) {
            $resolved[$key] = [
                'status'  => 'matched',
                'product' => $bySku[$key][0],
            ];
        } else {
            $resolved[$key] = [
                'status'     => 'ambiguous',
                'candidates' => $bySku[$key],
            ];
        }
    }

    return new \WP_REST_Response(['resolved' => (object) $resolved], 200);
}

function fcbo_render_product_table($atts = [])
{
    $atts = shortcode_atts([
        'per_page'        => 5,
        'columns'         => '',
        'search'          => 'true',
        'category'        => '',
        'roles'           => '',
        'expand_variants' => 'false',
    ], $atts, 'fluent_cart_product_table');

    // `roles` EXTENDS (never replaces) the baseline admin + wholesale set for this
    // placement. See the caveat on fcbo_current_user_can_access(): this only widens the
    // UI gate — the /catalog REST route still enforces the global fcbo_get_allowed_roles().
    $extraRoles = fcbo_parse_roles_attr($atts['roles']);

    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('Please log in to access the product table.', 'fluent-cart-bulk-order') . '</p>';
    }

    if (!fcbo_current_user_can_access($extraRoles)) {
        return '<p>' . esc_html__('You do not have permission to access the product table.', 'fluent-cart-bulk-order') . '</p>';
    }

    // Resolve display attributes (degrade safely to defaults on bad input).
    $per_page = absint($atts['per_page']);
    if ($per_page < 1) {
        $per_page = 5; // Non-numeric or zero → default.
    }
    $per_page = min(100, max(1, $per_page)); // Clamp to the REST cap.

    $allColumns = ['id', 'title', 'price', 'qty', 'action'];
    $columns    = fcbo_parse_columns_attr($atts['columns'], $allColumns);

    $columnDefs = [
        'id'     => ['class' => 'fcbo-pt-col-id',     'label' => __('ID', 'fluent-cart-bulk-order')],
        'title'  => ['class' => 'fcbo-pt-col-title',  'label' => __('Title', 'fluent-cart-bulk-order')],
        'price'  => ['class' => 'fcbo-pt-col-price',  'label' => __('Price', 'fluent-cart-bulk-order')],
        'qty'    => ['class' => 'fcbo-pt-col-qty',    'label' => __('Quantity', 'fluent-cart-bulk-order')],
        'action' => ['class' => 'fcbo-pt-col-action', 'label' => __('Action', 'fluent-cart-bulk-order')],
    ];

    $colspan    = count($columns);
    $showSearch = filter_var($atts['search'], FILTER_VALIDATE_BOOLEAN);
    $category   = fcbo_sanitize_category_param($atts['category']);
    // When true, variant accordions render open by default (variants shown separately).
    $expandVariants = filter_var($atts['expand_variants'], FILTER_VALIDATE_BOOLEAN);

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
        'per_page'        => $per_page,
        'columns'         => $columns,
        'category'        => $category,
        'expand_variants' => $expandVariants ? 1 : 0,
    ]);

    ob_start();
    ?>
    <div id="fcbo-product-table" class="fcbo-pt-wrap">
        <?php if ($showSearch) : ?>
        <div class="fcbo-pt-toolbar">
            <input type="text" id="fcbo-pt-search" class="fcbo-pt-search"
                   placeholder="<?php esc_attr_e('Search products...', 'fluent-cart-bulk-order'); ?>" />
        </div>
        <?php endif; ?>

        <div class="fcbo-pt-table-scroll">
            <table class="fcbo-pt-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $col) : ?>
                        <th class="<?php echo esc_attr($columnDefs[$col]['class']); ?>"><?php echo esc_html($columnDefs[$col]['label']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="fcbo-pt-tbody">
                    <tr><td colspan="<?php echo esc_attr($colspan); ?>" class="fcbo-pt-loading"><?php esc_html_e('Loading products...', 'fluent-cart-bulk-order'); ?></td></tr>
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
    $category = $request->get_param('category');

    $productModel = new \FluentCart\App\Models\Product();

    $query = $productModel::published()
        ->with(['variants' => function ($q) {
            $q->where('item_status', 'active');
        }]);

    if ($search && strlen($search) >= 2) {
        $query->where(function ($q) use ($search) {
            $like = '%' . $GLOBALS['wpdb']->esc_like($search) . '%';
            $q->where('post_title', 'LIKE', $like)
              ->orWhereHas('variants', function ($vq) use ($like) {
                  $vq->where('item_status', 'active')
                     ->where(function ($inner) use ($like) {
                         $inner->where('sku', 'LIKE', $like)
                               ->orWhere('variation_title', 'LIKE', $like);
                     });
              });
        });
    }

    // Category filter (separate block, kept out of the search `if` above). Constrain via
    // the FluentCart Product `wpTerms` taxonomy relation, mirroring scopeFilterByTaxonomy.
    // Applied before count() so pagination reflects the scoped set.
    if ($category !== '' && $category !== null) {
        $term = fcbo_resolve_category_term($category);
        if ($term) {
            $termId = (int) $term->term_id;
            $query->whereHas('wpTerms', function ($q) use ($termId) {
                // Unqualified `term_id` (matches Product::scopeFilterByTaxonomy). It is
                // unambiguous: the joined term_relationships table has no term_id column.
                $q->where('term_id', $termId);
            });
        } else {
            // Unknown category → empty result set, no error (R5).
            $query->where('ID', 0);
        }
    }

    $total = $query->count();
    $totalPages = max(1, (int) ceil($total / $per_page));

    $products = $query
        ->orderBy('ID', 'DESC')
        ->offset(($page - 1) * $per_page)
        ->limit($per_page)
        ->get();

    // One batched lookup for the whole page, so per-variant rule resolution below
    // costs no extra queries (same pattern as the /products search endpoint).
    $productIds = [];
    foreach ($products as $product) {
        $productIds[] = $product->ID;
    }
    $pricingData = fcbo_get_all_bulk_pricing($productIds);

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
                    'order_rules'     => fcbo_resolve_order_rules($pricingData, $product->ID, $variant->id),
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
            $feedData     = $globalFeed->meta_value;
            $enabled      = !empty($feedData['enabled']) && $feedData['enabled'] === 'yes';
            $hasTiers     = !empty($feedData['tiers']);
            $hasRoleTiers = !empty($feedData['role_tiers']) && is_array($feedData['role_tiers']);
            // A feed carrying only order rules (no tiers at all) is still live
            // content — dropping it here would silently disable the rules.
            $rules        = fcbo_normalize_order_rules($feedData['order_rules'] ?? []);
            $hasRules     = fcbo_order_rules_are_set($rules);
            if ($enabled && ($hasTiers || $hasRoleTiers || $hasRules)) {
                $globalTiers = [
                    'tiers'       => $hasTiers ? $feedData['tiers'] : [],
                    'role_tiers'  => $hasRoleTiers ? $feedData['role_tiers'] : [],
                    'order_rules' => $rules,
                ];
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
            $feedData     = $feed->meta_value;
            $hasTiers     = !empty($feedData['tiers']);
            $hasRoleTiers = !empty($feedData['role_tiers']) && is_array($feedData['role_tiers']);
            // As above: rules-only feeds must survive this filter.
            $rules        = fcbo_normalize_order_rules($feedData['order_rules'] ?? []);
            $hasRules     = fcbo_order_rules_are_set($rules);
            if (empty($feedData['enabled']) || $feedData['enabled'] !== 'yes' || (!$hasTiers && !$hasRoleTiers && !$hasRules)) {
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
                'tiers'       => $hasTiers ? $feedData['tiers'] : [],
                'role_tiers'  => $hasRoleTiers ? $feedData['role_tiers'] : [],
                'order_rules' => $rules,
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
 *
 * Two-stage resolution:
 *   1. Feed precedence — a product-level feed wins over the global feed; the
 *      first feed whose variant scope matches applies (unchanged behavior).
 *   2. Role selection within that feed — if the feed carries role-scoped
 *      tier-sets, the first of the shopper's roles with a list wins; otherwise
 *      the feed's default `tiers` apply. See fcbo_select_role_tier_set().
 *
 * $userRoles is optional: null/[] always yields the default set, so existing
 * call sites keep today's behavior until they opt in by passing roles (R6/R8).
 * Role selection composes with — never replaces — the Plan 002 qualification
 * gate, which still decides *whether* any bulk pricing applies.
 *
 * @param array         $pricingData From fcbo_get_all_bulk_pricing()
 * @param int           $productId
 * @param int           $variantId
 * @param string[]|null $userRoles   Current user's role slugs (null = default set).
 * @return array Tier list (may be empty)
 */
function fcbo_resolve_tiers($pricingData, $productId, $variantId, $userRoles = null)
{
    $feed = fcbo_match_feed($pricingData, $productId, $variantId);

    return $feed ? fcbo_select_role_tier_set($feed, $userRoles) : [];
}

/**
 * Find the one feed that governs a variant: product-scoped beats global.
 *
 * The single home for feed precedence. Tiers (fcbo_resolve_tiers) and order
 * rules (fcbo_resolve_order_rules) both route through here so the two can never
 * disagree about which feed applies to a given variant.
 *
 * Precedence is winner-takes-all, matching the long-standing tier behavior: the
 * first matching product feed wins outright and the global feed is not consulted
 * for anything it left unset. A product feed therefore fully replaces the global
 * one rather than layering on top of it.
 *
 * @param array $pricingData From fcbo_get_all_bulk_pricing().
 * @param int   $productId
 * @param int   $variantId
 * @return array|null The governing feed, or null when none applies.
 */
function fcbo_match_feed($pricingData, $productId, $variantId)
{
    // Check product-level feeds first
    if (!empty($pricingData['product'][$productId])) {
        foreach ($pricingData['product'][$productId] as $feed) {
            // Empty variant_ids means applies to all variants
            if (empty($feed['variant_ids']) || in_array((int) $variantId, $feed['variant_ids'], true)) {
                return $feed;
            }
        }
    }

    // Fall back to the global feed
    if (!empty($pricingData['global'])) {
        return $pricingData['global'];
    }

    return null;
}

/**
 * Resolve the effective order rules (minimum qty + case-pack step) for a variant.
 *
 * Shares fcbo_match_feed()'s precedence with fcbo_resolve_tiers(), so the feed
 * that prices a variant is always the feed that constrains its quantity. A
 * variant with no governing feed gets the no-op defaults.
 *
 * @param array $pricingData From fcbo_get_all_bulk_pricing().
 * @param int   $productId
 * @param int   $variantId
 * @return array{min_qty:int, step:int}
 */
function fcbo_resolve_order_rules($pricingData, $productId, $variantId)
{
    $feed = fcbo_match_feed($pricingData, $productId, $variantId);

    return fcbo_normalize_order_rules($feed['order_rules'] ?? []);
}

/**
 * Coerce a stored/raw order-rule pair into clamped integers.
 *
 * Mirrors BulkPricingIntegration::sanitizeOrderRules() so data that predates the
 * feature — or that was written by hand — still reads as the no-op default
 * rather than as a rule of 0 multiples.
 *
 * @param mixed $rules
 * @return array{min_qty:int, step:int}
 */
function fcbo_normalize_order_rules($rules)
{
    $rules = is_array($rules) ? $rules : [];

    return [
        'min_qty' => max(0, (int) ($rules['min_qty'] ?? 0)),
        'step'    => max(1, (int) ($rules['step'] ?? 1)),
    ];
}

/**
 * Whether a rule pair actually constrains anything.
 *
 * @param array $rules Normalized rules.
 * @return bool
 */
function fcbo_order_rules_are_set($rules)
{
    return ($rules['min_qty'] ?? 0) > 0 || ($rules['step'] ?? 1) > 1;
}

/**
 * Round a quantity up to the nearest value the rules permit.
 *
 * This is the ONE place in PHP the normalization formula lives; the JS surfaces
 * mirror it exactly and MUST change together with it. Rounding is always
 * upward — never downward — so a shopper is never silently given less than they
 * asked for.
 *
 * @param int   $qty
 * @param array $rules Normalized rules.
 * @return int Smallest permitted quantity >= $qty (always >= 1).
 */
function fcbo_normalize_qty($qty, $rules)
{
    $rules = fcbo_normalize_order_rules($rules);
    $qty   = max(1, (int) $qty, $rules['min_qty']);

    if ($rules['step'] > 1) {
        $qty = (int) (ceil($qty / $rules['step']) * $rules['step']);
    }

    return $qty;
}

/**
 * Whether a quantity exactly satisfies the rules (server-side gate).
 *
 * Deliberately strict where fcbo_normalize_qty() is forgiving: the client
 * corrects a typo in place, the server refuses anything that did not come
 * through that correction (KTD4).
 *
 * @param int   $qty
 * @param array $rules Normalized rules.
 * @return bool
 */
function fcbo_qty_is_valid($qty, $rules)
{
    return (int) $qty === fcbo_normalize_qty($qty, $rules);
}

/**
 * Pick the applicable tier-set within a resolved feed by the shopper's roles.
 *
 * The first of the shopper's roles that has a role-scoped list wins; otherwise
 * the feed's default `tiers` apply. Passing null/[] roles always yields the
 * default set, so callers that don't know the user get today's behavior.
 *
 * @param array         $feed      ['tiers' => array, 'role_tiers' => array]
 * @param string[]|null $userRoles Current user's role slugs.
 * @return array Tier list (may be empty).
 */
function fcbo_select_role_tier_set($feed, $userRoles)
{
    $roleTiers = isset($feed['role_tiers']) && is_array($feed['role_tiers']) ? $feed['role_tiers'] : [];

    if (!empty($roleTiers) && !empty($userRoles)) {
        foreach ((array) $userRoles as $role) {
            if (!empty($roleTiers[$role])) {
                return $roleTiers[$role];
            }
        }
    }

    return isset($feed['tiers']) && is_array($feed['tiers']) ? $feed['tiers'] : [];
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
 * Compute the effective per-unit price (integer cents) for a matched tier.
 *
 * This is the ONE place in PHP the per-type discount formula lives; the two JS
 * live-total surfaces (bulk-order.js, bulk-pricing-display.js) mirror it exactly
 * and MUST change together with it. Money tier values are stored in major
 * currency units, so the major-units -> cents conversion happens here — the sole
 * conversion point on the PHP side. The result is always integer cents, clamped
 * to >= 0.
 *
 *   percent          -> round(price * (1 - value / 100))
 *   fixed_unit_price -> round(value * 100)              (absolute per-unit price)
 *   amount_off       -> price - round(value * 100)      (flat per-unit reduction)
 *
 * @param int   $itemPriceCents Original per-unit price in cents.
 * @param array $tier           ['discount_type' => string, 'discount_value' => float]
 * @return int Effective per-unit price in cents (>= 0).
 */
function fcbo_apply_tier_to_price($itemPriceCents, $tier)
{
    $type  = isset($tier['discount_type']) ? (string) $tier['discount_type'] : 'percent';
    $value = (float) ($tier['discount_value'] ?? 0);

    switch ($type) {
        case 'fixed_unit_price':
            $price = (int) round($value * 100);
            break;
        case 'amount_off':
            $price = (int) $itemPriceCents - (int) round($value * 100);
            break;
        case 'percent':
        default:
            $price = (int) round($itemPriceCents * (1 - $value / 100));
            break;
    }

    return max(0, $price);
}

/**
 * Human-readable label for a tier's discount, by type.
 *
 * Returns raw text — the caller escapes. Money types are formatted in major
 * units with the store currency sign; percent keeps the "% off" form.
 *
 * @param array $tier ['discount_type' => string, 'discount_value' => float]
 * @return string
 */
function fcbo_format_tier_discount_label($tier)
{
    $type  = isset($tier['discount_type']) ? (string) $tier['discount_type'] : 'percent';
    $value = (float) ($tier['discount_value'] ?? 0);
    $sign  = fcbo_get_currency_sign();

    switch ($type) {
        case 'fixed_unit_price':
            // Money keeps 2 decimals (currency), matching the JS formatPrice() output.
            /* translators: %s: formatted unit price, e.g. $8.50 */
            return sprintf(__('%s/unit', 'fluent-cart-bulk-order'), $sign . number_format($value, 2));
        case 'amount_off':
            /* translators: %s: formatted amount, e.g. $2.00 */
            return sprintf(__('%s off', 'fluent-cart-bulk-order'), $sign . number_format($value, 2));
        case 'percent':
        default:
            // Percent strips trailing zeros (10% not 10.00%) — unchanged from before.
            $num = rtrim(rtrim(number_format($value, 2), '0'), '.');
            /* translators: %s: percentage number, e.g. 10 */
            return sprintf(__('%s%% off', 'fluent-cart-bulk-order'), $num);
    }
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

    // Hide the tier tables/order widget from shoppers the policy excludes.
    // Administrators can always preview the display (R5).
    if (!fcbo_user_qualifies_for_bulk_pricing(null, 'display')) {
        return;
    }

    $product = $args['product'];
    $pricingData = fcbo_get_all_bulk_pricing([$product->ID]);
    // Resolve tiers against the viewer's roles so the preview matches their cart price.
    $userRoles = (array) wp_get_current_user()->roles;
    $isSimple = isset($product->detail->variation_type) && $product->detail->variation_type === 'simple';

    if ($isSimple) {
        $variant = $product->variants->first();
        if (!$variant) {
            return;
        }

        $tiers = fcbo_resolve_tiers($pricingData, $product->ID, $variant->id, $userRoles);
        if (empty($tiers)) {
            return;
        }

        fcbo_enqueue_bulk_pricing_assets();

        echo '<div class="fcbo-bp-wrap">';
        echo '<h4 class="fcbo-bp-heading">' . esc_html__('Bulk Pricing', 'fluent-cart-bulk-order') . '</h4>';
        echo '<div class="fcbo-bp-simple"><ul>';
        foreach ($tiers as $tier) {
            $minQty = (int) ($tier['min_qty'] ?? 0);
            $maxQty = (int) ($tier['max_qty'] ?? 0);

            $range = $maxQty > 0
                ? sprintf('%d – %d', $minQty, $maxQty)
                : sprintf('%d+', $minQty);

            printf(
                '<li>' . esc_html__('Buy %s:', 'fluent-cart-bulk-order') . ' <span class="fcbo-bp-discount">%s</span></li>',
                esc_html($range),
                esc_html(fcbo_format_tier_discount_label($tier))
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
        $tiers = fcbo_resolve_tiers($pricingData, $product->ID, $variant->id, $userRoles);
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
            $minQty = (int) ($tier['min_qty'] ?? 0);
            $maxQty = (int) ($tier['max_qty'] ?? 0);
            $range  = $maxQty > 0 ? sprintf('%d – %d', $minQty, $maxQty) : sprintf('%d+', $minQty);

            printf(
                '<tr><td>%s</td><td class="fcbo-bp-discount">%s</td></tr>',
                esc_html($range),
                esc_html(fcbo_format_tier_discount_label($tier))
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
                $minQty = (int) ($tier['min_qty'] ?? 0);
                $maxQty = (int) ($tier['max_qty'] ?? 0);
                $range  = $maxQty > 0 ? sprintf('%d – %d', $minQty, $maxQty) : sprintf('%d+', $minQty);

                echo '<tr>';
                if ($idx === 0) {
                    printf(
                        '<td rowspan="%d">%s</td>',
                        count($entry['tiers']),
                        esc_html($entry['title'])
                    );
                }
                printf(
                    '<td>%s</td><td class="fcbo-bp-discount">%s</td>',
                    esc_html($range),
                    esc_html(fcbo_format_tier_discount_label($tier))
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
 * Role slugs that bulk pricing is restricted to.
 *
 * Stored as the top-level option `fcbo_apply_to_roles`. An empty array means the
 * policy is open to everyone — the default, which preserves the pre-policy
 * behavior on upgrade. Do not change this default.
 *
 * @return string[] Role slugs; empty array = everyone qualifies.
 */
function fcbo_get_bulk_pricing_roles()
{
    return (array) get_option('fcbo_apply_to_roles', []);
}

/**
 * Whether a user qualifies for bulk pricing under the stored role policy.
 *
 * Truth table:
 *   - Empty role list                      => true (everyone; default, R3).
 *   - Context 'display' + administrator    => true (admins can always preview, R5).
 *   - Otherwise                            => true iff the user holds an allowed role.
 *
 * The administrator display exception is intentionally NOT applied on the 'cart'
 * context: an admin's real order must reflect the real policy (KTD4). Do not
 * "make it consistent" by extending the admin short-circuit to the cart path.
 *
 * The result passes through the `fcbo/user_qualifies_for_bulk_pricing` filter so
 * developers can implement custom logic (e.g. per-customer overrides) without
 * editing core (R4).
 *
 * @param \WP_User|null $user    User to test; defaults to the current user.
 * @param string        $context 'cart' (enforcement) or 'display' (preview).
 * @return bool
 */
function fcbo_user_qualifies_for_bulk_pricing($user = null, $context = 'cart')
{
    if ($user === null) {
        $user = wp_get_current_user();
    }

    $roles = fcbo_get_bulk_pricing_roles();
    $userRoles = isset($user->roles) ? (array) $user->roles : [];

    if (empty($roles)) {
        $qualifies = true;
    } elseif ($context === 'display' && in_array('administrator', $userRoles, true)) {
        $qualifies = true;
    } else {
        $qualifies = (bool) array_intersect($roles, $userRoles);
    }

    return (bool) apply_filters('fcbo/user_qualifies_for_bulk_pricing', $qualifies, $user, $context);
}

/**
 * The configured minimum order total, in integer cents.
 *
 * Stored as `fcbo_min_order_total`. Zero (the default) means no floor for
 * anyone, which preserves pre-Plan-009 behavior on upgrade.
 *
 * @return int Cents; 0 = no minimum.
 */
function fcbo_get_min_order_total()
{
    return max(0, (int) get_option('fcbo_min_order_total', 0));
}

/**
 * Roles the minimum order total applies to.
 *
 * Deliberately a SEPARATE option from `fcbo_apply_to_roles` (KTD5): a store
 * commonly wants to require a minimum of its wholesale buyers without also
 * restricting who receives bulk discounts. Unlike the bulk-pricing policy, an
 * empty list here means "nobody is subject" rather than "everyone" — an
 * unconfigured minimum must never start blocking retail checkouts on upgrade.
 *
 * @return string[] Role slugs; empty = nobody is subject.
 */
function fcbo_get_min_order_total_roles()
{
    return (array) get_option('fcbo_min_order_total_roles', []);
}

/**
 * Whether a user must meet the minimum order total.
 *
 * Mirrors fcbo_user_qualifies_for_bulk_pricing()'s shape — including the
 * escape-hatch filter — but NOT its empty-list semantics; see
 * fcbo_get_min_order_total_roles() for why the defaults invert.
 *
 * @param \WP_User|null $user Defaults to the current user.
 * @return bool
 */
function fcbo_user_subject_to_min_order($user = null)
{
    if ($user === null) {
        $user = wp_get_current_user();
    }

    $roles     = fcbo_get_min_order_total_roles();
    $userRoles = isset($user->roles) ? (array) $user->roles : [];
    $subject   = !empty($roles) && (bool) array_intersect($roles, $userRoles);

    return (bool) apply_filters('fcbo/user_subject_to_min_order', $subject, $user);
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

    // Gate the discount by the stored role policy. Non-qualifying shoppers keep
    // the full price. Admins are NOT exempt on the cart path (KTD4).
    if (!fcbo_user_qualifies_for_bulk_pricing(null, 'cart')) {
        return $variation;
    }

    $qty       = (int) $context['quantity'];
    $productId = (int) $variation->post_id;
    $variantId = (int) $variation->id;

    $pricingData = fcbo_get_all_bulk_pricing([$productId]);
    // Role selects which tier-set prices this line (composes with the gate above).
    $userRoles   = (array) wp_get_current_user()->roles;
    $tiers       = fcbo_resolve_tiers($pricingData, $productId, $variantId, $userRoles);

    if (empty($tiers)) {
        return $variation;
    }

    foreach ($tiers as $tier) {
        $minQty = (int) ($tier['min_qty'] ?? 0);
        $maxQty = (int) ($tier['max_qty'] ?? 0);

        if ($qty >= $minQty && ($maxQty === 0 || $qty <= $maxQty)) {
            $variation->item_price = fcbo_apply_tier_to_price((int) $variation->item_price, $tier);
            break;
        }
    }

    return $variation;
}

/* -------------------------------------------------------------------------
 * Order rules — server-side enforcement (Plan 009 · R5)
 *
 * The authoritative gate. The JS equivalents in bulk-order.js / product-table.js
 * exist to correct honest mistakes in place; these two callbacks exist to refuse
 * everything else. Keep the two in lock-step: fcbo_normalize_qty() is the shared
 * formula, mirrored in JS as normalizeQty().
 * ---------------------------------------------------------------------- */

/**
 * Format an integer-cents amount for display in a shopper-facing message.
 *
 * @param int $cents
 * @return string
 */
function fcbo_format_money($cents)
{
    return fcbo_get_currency_sign() . number_format(((int) $cents) / 100, 2);
}

/**
 * Reject an add-to-cart whose quantity violates the variant's order rules.
 *
 * Bound to `fluent_cart/variation/can_purchase_bundle`, which despite its name
 * fires inside the generic ProductVariation::canPurchase() and therefore covers
 * every add path (see the registration comment for the full rationale).
 *
 * @param mixed $result  Prior verdict: null (undecided), false, or WP_Error.
 * @param array $context ['variation' => object, 'quantity' => int]
 * @return mixed WP_Error to veto; the untouched $result otherwise.
 */
function fcbo_validate_cart_item_rules($result, $context)
{
    // Never override a veto another party already cast (e.g. out of stock).
    if (is_wp_error($result) || $result === false) {
        return $result;
    }

    $variation = isset($context['variation']) ? $context['variation'] : null;
    $qty       = (int) ($context['quantity'] ?? 0);

    if (!$variation || empty($variation->id) || $qty < 1) {
        return $result;
    }

    $productId = (int) $variation->post_id;
    $variantId = (int) $variation->id;

    $rules = fcbo_resolve_order_rules(fcbo_get_all_bulk_pricing([$productId]), $productId, $variantId);

    if (!fcbo_order_rules_are_set($rules) || fcbo_qty_is_valid($qty, $rules)) {
        return $result;
    }

    return new \WP_Error('fcbo_order_rule', fcbo_describe_qty_violation($qty, $rules));
}

/**
 * Shopper-facing explanation of why a quantity was refused.
 *
 * Always names the nearest acceptable quantity so the message is actionable
 * rather than merely a rejection.
 *
 * @param int   $qty   The rejected quantity.
 * @param array $rules Normalized rules.
 * @return string
 */
function fcbo_describe_qty_violation($qty, $rules)
{
    $rules     = fcbo_normalize_order_rules($rules);
    $suggested = fcbo_normalize_qty($qty, $rules);

    if ($rules['min_qty'] > 0 && $rules['step'] > 1) {
        /* translators: 1: minimum quantity, 2: case-pack size, 3: nearest valid quantity */
        $format = __('This product has a minimum of %1$d and is sold in multiples of %2$d. Try %3$d.', 'fluent-cart-bulk-order');
        return sprintf($format, $rules['min_qty'], $rules['step'], $suggested);
    }

    if ($rules['step'] > 1) {
        /* translators: 1: case-pack size, 2: nearest valid quantity */
        $format = __('This product is sold in multiples of %1$d. Try %2$d.', 'fluent-cart-bulk-order');
        return sprintf($format, $rules['step'], $suggested);
    }

    /* translators: 1: minimum quantity */
    return sprintf(__('This product has a minimum order quantity of %1$d.', 'fluent-cart-bulk-order'), $rules['min_qty']);
}

/**
 * Block checkout when a subject shopper's cart is under the order minimum.
 *
 * Bound to `fluent_cart/checkout/validate_data`, which receives the resolved
 * cart and halts checkout when the returned error array is non-empty.
 *
 * The comparison basis is the items subtotal — bulk-discounted line prices,
 * before coupons, shipping, and tax. That deliberately matches what the Bulk
 * Order Form shows as its grand total, so the client-side warning and this gate
 * can never disagree about whether a cart clears the floor.
 *
 * @param array $errors  Accumulated validation errors (nested: field => code => msg).
 * @param array $context ['data' => array, 'cart' => object]
 * @return array
 */
function fcbo_validate_checkout_minimum($errors, $context)
{
    $minimum = fcbo_get_min_order_total();
    if ($minimum <= 0 || !fcbo_user_subject_to_min_order()) {
        return $errors;
    }

    $cart = isset($context['cart']) ? $context['cart'] : null;
    if (!$cart || !method_exists($cart, 'getItemsSubtotal')) {
        return $errors;
    }

    $subtotal = (int) $cart->getItemsSubtotal();
    if ($subtotal >= $minimum) {
        return $errors;
    }

    /* translators: 1: shortfall amount, 2: minimum order total */
    $format = __('Add %1$s more to reach the %2$s minimum order total.', 'fluent-cart-bulk-order');

    $errors['fcbo_min_order_total']['minimum'] = sprintf(
        $format,
        fcbo_format_money($minimum - $subtotal),
        fcbo_format_money($minimum)
    );

    return $errors;
}

/* -------------------------------------------------------------------------
 * Saved orders (Phase 2 · Item 2)
 *
 * A user's saved orders live in the user_meta key `fcbo_saved_lists` as an
 * array of { name, created_at, updated_at, items:[{variantId, qty}] }. All
 * access is scoped to the current user; ownership is never a request field.
 * ---------------------------------------------------------------------- */

/**
 * The user-meta key that stores a user's saved orders.
 */
const FCBO_SAVED_LISTS_META = 'fcbo_saved_lists';

/**
 * Normalize a raw saved-lists meta value into a clean, typed structure.
 *
 * @param mixed $raw
 * @return array<int, array{name:string, created_at:int, updated_at:int, items:array}>
 */
function fcbo_normalize_saved_lists($raw)
{
    if (!is_array($raw)) {
        return [];
    }

    $lists = [];
    foreach ($raw as $list) {
        if (empty($list['name']) || !is_string($list['name'])) {
            continue;
        }

        $items = [];
        if (!empty($list['items']) && is_array($list['items'])) {
            foreach ($list['items'] as $item) {
                $variantId = isset($item['variantId']) ? absint($item['variantId']) : 0;
                $qty       = isset($item['qty']) ? absint($item['qty']) : 0;
                if ($variantId < 1 || $qty < 1) {
                    continue;
                }
                $items[] = ['variantId' => $variantId, 'qty' => $qty];
            }
        }

        $lists[] = [
            'name'       => (string) $list['name'],
            'created_at' => isset($list['created_at']) ? (int) $list['created_at'] : 0,
            'updated_at' => isset($list['updated_at']) ? (int) $list['updated_at'] : 0,
            'items'      => $items,
        ];
    }

    return $lists;
}

/**
 * Get the current (or given) user's saved orders.
 *
 * @param int|null $userId Defaults to the current user.
 * @return array
 */
function fcbo_get_saved_lists($userId = null)
{
    $userId = $userId ?: get_current_user_id();
    if (!$userId) {
        return [];
    }

    return fcbo_normalize_saved_lists(get_user_meta($userId, FCBO_SAVED_LISTS_META, true));
}

/**
 * Sanitize a raw items list to [{variantId:int, qty:int}], dropping invalid rows.
 *
 * @param mixed $items
 * @return array
 */
function fcbo_sanitize_saved_items($items)
{
    $clean = [];
    if (!is_array($items)) {
        return $clean;
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $variantId = isset($item['variantId']) ? absint($item['variantId']) : 0;
        $qty       = isset($item['qty']) ? absint($item['qty']) : 0;
        if ($variantId < 1 || $qty < 1) {
            continue;
        }
        $clean[] = ['variantId' => $variantId, 'qty' => $qty];
        if (count($clean) >= 200) {
            break; // defensive cap
        }
    }

    return $clean;
}

/**
 * Create or replace (upsert by name, case-insensitive) a saved order for the
 * current user.
 *
 * @param string $name
 * @param mixed  $items
 * @param int|null $userId
 * @return array|\WP_Error The updated saved-list set, or a WP_Error on bad input.
 */
function fcbo_save_list($name, $items, $userId = null)
{
    $userId = $userId ?: get_current_user_id();
    if (!$userId) {
        return new \WP_Error('fcbo_not_logged_in', __('You must be logged in.', 'fluent-cart-bulk-order'), ['status' => 401]);
    }

    $name = sanitize_text_field(trim((string) $name));
    if ($name === '') {
        return new \WP_Error('fcbo_empty_name', __('Please enter a name for this order.', 'fluent-cart-bulk-order'), ['status' => 400]);
    }

    $cleanItems = fcbo_sanitize_saved_items($items);
    if (empty($cleanItems)) {
        return new \WP_Error('fcbo_empty_items', __('There are no valid items to save.', 'fluent-cart-bulk-order'), ['status' => 400]);
    }

    $lists = fcbo_get_saved_lists($userId);
    $now   = time();
    $found = false;

    foreach ($lists as &$list) {
        if (strcasecmp($list['name'], $name) === 0) {
            $list['name']       = $name; // adopt the latest spelling
            $list['items']      = $cleanItems;
            $list['updated_at'] = $now;
            if (empty($list['created_at'])) {
                $list['created_at'] = $now;
            }
            $found = true;
            break;
        }
    }
    unset($list);

    if (!$found) {
        $lists[] = [
            'name'       => $name,
            'created_at' => $now,
            'updated_at' => $now,
            'items'      => $cleanItems,
        ];
    }

    update_user_meta($userId, FCBO_SAVED_LISTS_META, $lists);

    return $lists;
}

/**
 * Delete a saved order by name (case-insensitive) for the current user.
 *
 * @param string $name
 * @param int|null $userId
 * @return array The updated saved-list set.
 */
function fcbo_delete_saved_list($name, $userId = null)
{
    $userId = $userId ?: get_current_user_id();
    if (!$userId) {
        return [];
    }

    $name  = sanitize_text_field(trim((string) $name));
    $lists = fcbo_get_saved_lists($userId);

    $filtered = [];
    foreach ($lists as $list) {
        if (strcasecmp($list['name'], $name) === 0) {
            continue;
        }
        $filtered[] = $list;
    }

    update_user_meta($userId, FCBO_SAVED_LISTS_META, $filtered);

    return $filtered;
}

/**
 * Resolve a batch of variant IDs to current catalog payloads in one query.
 *
 * Mirrors fcbo_resolve_skus() but keys on the variant ID (saved orders store
 * IDs, not SKUs). Reuses the shared payload builders so the shape matches the
 * search / resolve-skus endpoints.
 *
 * @param int[] $variantIds
 * @return array<int, array> Map of variantId => payload {productId,title,thumbnail,categories,variant}.
 */
function fcbo_resolve_variant_ids($variantIds)
{
    $variantIds = array_values(array_unique(array_filter(array_map('absint', (array) $variantIds))));
    if (empty($variantIds)) {
        return [];
    }

    $productModel = new \FluentCart\App\Models\Product();

    $products = $productModel::published()
        ->with(['detail', 'variants' => function ($query) {
            $query->where('item_status', 'active')->with('media');
        }])
        ->whereHas('variants', function ($vq) use ($variantIds) {
            $vq->where('item_status', 'active')->whereIn('id', $variantIds);
        })
        ->get();

    $productIds = [];
    foreach ($products as $product) {
        $productIds[] = $product->ID;
    }

    $pricingData = fcbo_get_all_bulk_pricing($productIds);
    // Saved/past orders render for the authenticated owner — role-resolve their tiers.
    $userRoles   = (array) wp_get_current_user()->roles;

    $byId = [];
    foreach ($products as $product) {
        if (!$product->variants) {
            continue;
        }
        $catList = fcbo_build_category_list($product->ID);
        foreach ($product->variants as $variant) {
            $vid = (int) $variant->id;
            if (!in_array($vid, $variantIds, true)) {
                continue;
            }
            $byId[$vid] = [
                'productId'  => $product->ID,
                'title'      => $product->post_title,
                'thumbnail'  => $variant->thumbnail ?: ($product->thumbnail ?: ''),
                'categories' => $catList,
                'variant'    => fcbo_build_variant_payload($product, $variant, $pricingData, $userRoles),
            ];
        }
    }

    return $byId;
}

/**
 * Expand a stored saved order into a display payload: line items resolved to
 * current data, unavailable variants flagged, with a computed subtotal.
 *
 * @param array $list        A normalized saved list.
 * @param array $resolvedMap From fcbo_resolve_variant_ids().
 * @return array
 */
function fcbo_expand_saved_list($list, $resolvedMap)
{
    $items    = [];
    $subtotal = 0;

    foreach ($list['items'] as $item) {
        $vid = (int) $item['variantId'];
        $qty = (int) $item['qty'];

        if (isset($resolvedMap[$vid])) {
            $p = $resolvedMap[$vid];
            $v = $p['variant'];
            $lineTotal = (int) $v['item_price'] * $qty;
            $subtotal += $lineTotal;

            $items[] = [
                'variantId'       => $vid,
                'qty'             => $qty,
                'available'       => true,
                'title'           => $p['title'],
                'variation_title' => $v['variation_title'],
                'sku'             => $v['sku'],
                'item_price'      => (int) $v['item_price'],
                'line_total'      => $lineTotal,
                'payment_type'    => $v['payment_type'],
                'stock_status'    => $v['stock_status'],
                'manage_stock'    => $v['manage_stock'],
                'available_qty'   => $v['available'],
                'thumbnail'       => $p['thumbnail'],
            ];
        } else {
            $items[] = [
                'variantId' => $vid,
                'qty'       => $qty,
                'available' => false,
            ];
        }
    }

    $dateFormat = get_option('date_format') ?: 'M j, Y';

    return [
        'name'                 => $list['name'],
        'created_at'           => $list['created_at'],
        'updated_at'           => $list['updated_at'],
        'created_at_formatted' => $list['created_at'] ? wp_date($dateFormat, $list['created_at']) : '',
        'item_count'           => count($items),
        'subtotal'             => $subtotal,
        'items'                => $items,
    ];
}

/**
 * Build the full saved-orders response for the current user: every saved order
 * with its line items resolved to current catalog data.
 *
 * @return array
 */
function fcbo_build_saved_orders_response()
{
    $lists = fcbo_get_saved_lists();

    $allIds = [];
    foreach ($lists as $list) {
        foreach ($list['items'] as $item) {
            $allIds[] = (int) $item['variantId'];
        }
    }

    $resolved = fcbo_resolve_variant_ids($allIds);

    $out = [];
    foreach ($lists as $list) {
        $expanded = fcbo_expand_saved_list($list, $resolved);
        $expanded['deletable'] = true;
        $expanded['source']    = 'saved';
        $out[] = $expanded;
    }

    return $out;
}

/**
 * Build a "past orders" response for the current user: their recent paid
 * FluentCart orders, shaped like saved orders (name, date, resolved items),
 * so the same accordion renders both. Not deletable.
 *
 * @param int $limit
 * @return array
 */
function fcbo_build_past_orders_response($limit = 20)
{
    $userId = get_current_user_id();
    if (!$userId || !class_exists(\FluentCart\App\Models\Order::class)) {
        return [];
    }

    // Owner scope: only orders whose customer is linked to this WP user.
    $orders = \FluentCart\App\Models\Order::query()
        ->with(['order_items'])
        ->whereHas('customer', function ($c) use ($userId) {
            $c->where('user_id', $userId);
        })
        ->where('payment_status', 'paid')
        ->orderBy('created_at', 'desc')
        ->limit($limit)
        ->get();

    // Shape each order like a saved list; OrderItem.object_id is the variant id
    // (OrderItem::belongsTo(ProductVariation, 'object_id', 'id')), quantity the qty.
    $pseudoLists = [];
    $allIds = [];
    foreach ($orders as $order) {
        $items = [];
        foreach ($order->order_items as $it) {
            $vid = (int) $it->object_id;
            $qty = (int) $it->quantity;
            if ($vid < 1 || $qty < 1) {
                continue;
            }
            $items[] = ['variantId' => $vid, 'qty' => $qty];
            $allIds[] = $vid;
        }
        if (empty($items)) {
            continue;
        }

        $created = $order->created_at ? strtotime((string) $order->created_at) : 0;
        $pseudoLists[] = [
            'name'       => sprintf(__('Order #%s', 'fluent-cart-bulk-order'), $order->id),
            'created_at' => $created ?: 0,
            'updated_at' => $created ?: 0,
            'items'      => $items,
        ];
    }

    $resolved = fcbo_resolve_variant_ids($allIds);

    $out = [];
    foreach ($pseudoLists as $list) {
        $expanded = fcbo_expand_saved_list($list, $resolved);
        $expanded['deletable'] = false;
        $expanded['source']    = 'past';
        $out[] = $expanded;
    }

    return $out;
}

/**
 * REST: GET the current user's saved orders (resolved).
 */
function fcbo_rest_get_saved_lists(\WP_REST_Request $request)
{
    return new \WP_REST_Response(['lists' => fcbo_build_saved_orders_response()], 200);
}

/**
 * REST: GET the current user's recent past orders (resolved).
 */
function fcbo_rest_get_past_orders(\WP_REST_Request $request)
{
    return new \WP_REST_Response(['orders' => fcbo_build_past_orders_response()], 200);
}

/**
 * REST: POST — create or replace a saved order for the current user.
 */
function fcbo_rest_save_list(\WP_REST_Request $request)
{
    $result = fcbo_save_list($request->get_param('name'), $request->get_param('items'));
    if (is_wp_error($result)) {
        return $result;
    }

    return new \WP_REST_Response(['lists' => fcbo_build_saved_orders_response()], 200);
}

/**
 * REST: DELETE a saved order by name for the current user.
 */
function fcbo_rest_delete_saved_list(\WP_REST_Request $request)
{
    fcbo_delete_saved_list($request->get_param('name'));

    return new \WP_REST_Response(['lists' => fcbo_build_saved_orders_response()], 200);
}

/**
 * Shortcode: [fluent_cart_saved_orders]
 *
 * Renders the current user's saved orders as an accordion (reusing the product
 * table's accordion styles): one summary row per saved order (name, created
 * date, item count, total) that expands to reveal its line items, with Reorder
 * and Delete actions.
 *
 * @param array $atts
 * @return string
 */
function fcbo_render_saved_orders($atts = [])
{
    $atts = shortcode_atts([
        'roles' => '',
    ], $atts, 'fluent_cart_saved_orders');

    // `roles` EXTENDS (never replaces) the baseline admin + wholesale set, matching
    // the other shortcodes.
    $extraRoles = fcbo_parse_roles_attr($atts['roles']);

    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('Please log in to view your saved orders.', 'fluent-cart-bulk-order') . '</p>';
    }

    if (!fcbo_current_user_can_access($extraRoles)) {
        return '<p>' . esc_html__('You do not have permission to view saved orders.', 'fluent-cart-bulk-order') . '</p>';
    }

    // Cart assets are needed so Reorder can add items via window.fluentCartCart.
    if (class_exists(\FluentCart\App\Modules\Templating\AssetLoader::class)) {
        \FluentCart\App\Modules\Templating\AssetLoader::loadCartAssets();
    }

    // Reuse the product table's table + accordion styles, then layer on
    // saved-order specifics.
    wp_enqueue_style(
        'fcbo-product-table',
        FCBO_URL . 'assets/css/product-table.css',
        [],
        FCBO_VERSION
    );
    wp_enqueue_style(
        'fcbo-saved-orders',
        FCBO_URL . 'assets/css/saved-orders.css',
        ['fcbo-product-table'],
        FCBO_VERSION
    );

    wp_enqueue_script(
        'fcbo-saved-orders',
        FCBO_URL . 'assets/js/saved-orders.js',
        ['fluent-cart-app'],
        FCBO_VERSION,
        true
    );

    $checkout_url = '';
    if (class_exists(\FluentCart\Api\StoreSettings::class)) {
        $checkout_url = (new \FluentCart\Api\StoreSettings())->getCheckoutPage();
    }

    wp_localize_script('fcbo-saved-orders', 'fcboSoConfig', [
        'rest_url'      => esc_url_raw(rest_url('fcbo/v1/')),
        'nonce'         => wp_create_nonce('wp_rest'),
        'currency_sign' => fcbo_get_currency_sign(),
        'checkout_url'  => esc_url_raw($checkout_url),
    ]);

    ob_start();
    ?>
    <div id="fcbo-saved-orders" class="fcbo-so-wrap">
        <div class="fcbo-pt-table-scroll">
            <table class="fcbo-pt-table fcbo-so-table">
                <thead>
                    <tr>
                        <th class="fcbo-so-col-name"><?php esc_html_e('Order', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-so-col-date"><?php esc_html_e('Created', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-so-col-count"><?php esc_html_e('Items', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-so-col-total"><?php esc_html_e('Total', 'fluent-cart-bulk-order'); ?></th>
                        <th class="fcbo-so-col-actions"><?php esc_html_e('Actions', 'fluent-cart-bulk-order'); ?></th>
                    </tr>
                </thead>
                <tbody id="fcbo-so-tbody">
                    <tr><td colspan="5" class="fcbo-pt-loading"><?php esc_html_e('Loading saved orders...', 'fluent-cart-bulk-order'); ?></td></tr>
                </tbody>
            </table>
        </div>

        <div id="fcbo-so-status" class="fcbo-pt-status" style="display:none;"></div>
    </div>
    <?php
    return ob_get_clean();
}
