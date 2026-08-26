<?php
/**
 * Plugin Name: Fluent Cart Bulk Order
 * Description: Adds a [fluent_cart_bulk_order] shortcode that renders an interactive bulk order table for FluentCart stores.
 * Version: 1.0.1
 * Author: Rahat Baksh
 * Requires PHP: 7.4
 * Text Domain: fluent-cart-bulk-order
 */

defined('ABSPATH') || exit;

define('FCBO_VERSION', '1.0.1');
define('FCBO_DIR', plugin_dir_path(__FILE__));
define('FCBO_URL', plugin_dir_url(__FILE__));

// Loaded unconditionally, and BEFORE plugins_loaded, on purpose. AccessPolicy
// holds every role gate in the plugin and the `fcbo_*` gate functions below are
// thin delegates to it — a theme or snippet may call one at any point in the
// request, including on a page load where FluentCart is inactive. Neither file
// touches FluentCart at include time. Settings comes along because
// AccessPolicy::settingsPageUrl() reads Settings::PAGE_SLUG.
require_once FCBO_DIR . 'includes/AccessPolicy.php';
require_once FCBO_DIR . 'includes/Settings.php';

/*
 * ---------------------------------------------------------------------------
 * Lifecycle — activate / deactivate / delete
 * ---------------------------------------------------------------------------
 *
 * Setup lives in \FluentCartBulkOrder\Activator, teardown in
 * \FluentCartBulkOrder\Deactivator. Read those classes before changing what
 * happens here; each documents rules that request-time code does not have
 * (activation runs after `plugins_loaded` with no FluentCart guarantee;
 * deactivation must not delete anything a reactivation would need).
 *
 * Deleting the plugin is NOT wired here — WordPress loads uninstall.php in the
 * plugin root for that, which calls Deactivator::uninstall().
 *
 * Both files are required inside the hooks, not at the top of this file: they
 * are needed twice in a plugin's lifetime and would otherwise be parsed on
 * every page load.
 */
register_activation_hook(__FILE__, function () {
    require_once FCBO_DIR . 'includes/Activator.php';
    \FluentCartBulkOrder\Activator::activate();
});

register_deactivation_hook(__FILE__, function () {
    require_once FCBO_DIR . 'includes/Deactivator.php';
    \FluentCartBulkOrder\Deactivator::deactivate();
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

    // Every shortcode tag this plugin owns, registered from one registry. The
    // per-tag classes under includes/Shortcodes/ load only when a tag actually
    // renders — see ShortcodeHandler.
    require_once FCBO_DIR . 'includes/Shortcodes/ShortcodeHandler.php';
    (new \FluentCartBulkOrder\Shortcodes\ShortcodeHandler())->register();

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

    // Price each cart line against its bulk tier.
    //
    // `item_price`, NOT `item_modify`: this one fires after FluentCart has
    // settled the line's final quantity, so an "add 5 more" never gets priced
    // as if it were a 5-unit order. The full reasoning, and why the two hooks
    // must not both be used, is on the callback.
    add_filter('fluent_cart/cart/item_price', 'fcbo_apply_cart_bulk_pricing', 10, 2);

    // Tell the shopper what that discount was worth.
    //
    // Two registrations because FluentCart has three line-item renderers and no
    // single hook reaches all of them:
    //
    //   after_total  CartItemRenderer.php:87 (the checkout order summary) and
    //                ModalCheckoutRenderer.php:265 (instant checkout). Fires
    //                inside the price wrapper, right under the line total.
    //   line_meta    CartRenderer.php:187 (the cart drawer and cart page). That
    //                file exposes exactly ONE do_action, in the details block —
    //                so the saving sits under the product title there, not by
    //                the price. It is the only seam available.
    //
    // CartItemRenderer fires BOTH, which is why the line_meta callback bails on
    // it. See fcbo_render_cart_line_saving_meta() for how it tells them apart.
    add_action('fluent_cart/cart/line_item/after_total', 'fcbo_render_cart_line_saving', 10, 1);
    add_action('fluent_cart/cart/line_item/line_meta', 'fcbo_render_cart_line_saving_meta', 10, 1);

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
    // The file itself is required at the top of this plugin file.
    (new \FluentCartBulkOrder\Settings())->register();
});

/*
 * ---------------------------------------------------------------------------
 * Access gates — delegates to \FluentCartBulkOrder\AccessPolicy
 * ---------------------------------------------------------------------------
 *
 * All three role gates (surface access, bulk pricing policy, minimum order
 * total) now live together in includes/AccessPolicy.php, which documents how
 * they differ and how they interact. The functions here remain as the stable,
 * documented extension surface — site snippets, docs/solutions and docs/plans
 * refer to them by name. Put new logic in the class, not in these wrappers.
 */

/**
 * Gate 1 — roles allowed to use the FCBO surfaces (bulk order form, product
 * table, saved orders, REST routes).
 *
 * @see \FluentCartBulkOrder\AccessPolicy::allowedRoles()
 * @return string[] Role slugs.
 */
function fcbo_get_allowed_roles()
{
    return \FluentCartBulkOrder\AccessPolicy::allowedRoles();
}

/**
 * Gate 1 — whether the current user may access FCBO surfaces.
 *
 * @see \FluentCartBulkOrder\AccessPolicy::currentUserCanAccess()
 * @param string[] $extraRoles Extra role slugs that widen (never replace) the baseline.
 * @return bool
 */
function fcbo_current_user_can_access($extraRoles = [])
{
    return \FluentCartBulkOrder\AccessPolicy::currentUserCanAccess($extraRoles);
}

/**
 * Parse a comma-separated shortcode `roles` attribute into sanitized role slugs.
 *
 * @see \FluentCartBulkOrder\AccessPolicy::parseRolesAttr()
 * @param string $rolesAttr Raw attribute value.
 * @return string[] Sanitized role slugs.
 */
function fcbo_parse_roles_attr($rolesAttr)
{
    return \FluentCartBulkOrder\AccessPolicy::parseRolesAttr($rolesAttr);
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
 * Gate 1 — REST permission callback for the FCBO endpoints.
 *
 * @see \FluentCartBulkOrder\AccessPolicy::restPermissionCheck()
 * @return true|\WP_Error
 */
function fcbo_rest_permission_check()
{
    return \FluentCartBulkOrder\AccessPolicy::restPermissionCheck();
}

/**
 * Shortcode: [fluent_cart_bulk_order]
 *
 * Renders the bulk order form: an empty table that JS fills by SKU
 * autocomplete or the quick-order paste/CSV panel, then hands off to checkout.
 *
 * Thin delegate. The surface itself lives in
 * \FluentCartBulkOrder\Shortcodes\BulkOrderForm, registered through
 * ShortcodeHandler. This wrapper stays because docs/plans and site snippets name
 * it, and because a theme may call it directly to place the surface outside
 * post content. Put new logic in the class, not here.
 *
 * @see \FluentCartBulkOrder\Shortcodes\BulkOrderForm
 * @param array $atts
 * @return string
 */
function fcbo_render_shortcode($atts = [])
{
    require_once FCBO_DIR . 'includes/Shortcodes/ShortcodeHandler.php';

    return \FluentCartBulkOrder\Shortcodes\ShortcodeHandler::renderTag('fluent_cart_bulk_order', $atts);
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
    // Gate 2, in the CART context — see below for why not 'display'.
    $tiers = fcbo_user_qualifies_for_bulk_pricing(null, 'cart')
        ? fcbo_resolve_tiers($pricingData, $product->ID, $variant->id, $userRoles)
        : [];

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
        // Gated on 'cart', NOT 'display'. This payload drives live line totals
        // and a grand total for an order the shopper is about to place, so it
        // must quote what fcbo_apply_cart_bulk_pricing() will actually charge.
        // Sending tiers to someone Gate 2 excludes made the form show a
        // discounted total that the cart then ignored — the shopper watched the
        // price go back up at checkout.
        //
        // This is why the 'display' escape hatch does not belong here.
        // Administrators are allowed to PREVIEW tiers, and the place for that
        // is the product page (fcbo_render_single_product_tiers), which shows
        // the tier table without quoting an order total. A transactional
        // surface has to state the real price; a wrong total is worse than no
        // preview. Non-qualifying store managers get told why by the notice in
        // BulkOrderForm::output().
        'bulk_tiers'      => $tiers,
        // NOT gated: min-qty and case-pack rules are store rules that bind
        // every shopper, whatever their pricing policy. The server enforces
        // them for everyone (fcbo_validate_cart_item_rules), so the form has to
        // show them for everyone or it would let people build carts that get
        // refused at checkout.
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

/**
 * Shortcode: [fluent_cart_product_table]
 *
 * Renders a paged, searchable browse table whose header is built in PHP and
 * whose rows are filled by JS from the /catalog REST route.
 *
 * Thin delegate. The surface itself lives in
 * \FluentCartBulkOrder\Shortcodes\ProductTable, registered through
 * ShortcodeHandler. This wrapper stays because docs/plans and site snippets name
 * it, and because a theme may call it directly to place the surface outside
 * post content. Put new logic in the class, not here.
 *
 * @see \FluentCartBulkOrder\Shortcodes\ProductTable
 * @param array $atts
 * @return string
 */
function fcbo_render_product_table($atts = [])
{
    require_once FCBO_DIR . 'includes/Shortcodes/ShortcodeHandler.php';

    return \FluentCartBulkOrder\Shortcodes\ShortcodeHandler::renderTag('fluent_cart_product_table', $atts);
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
 * Selection, NOT authorization — Gate 2 has already decided whether any bulk
 * pricing applies at all.
 *
 * @see \FluentCartBulkOrder\AccessPolicy::selectRoleTierSet()
 * @param array         $feed      ['tiers' => array, 'role_tiers' => array]
 * @param string[]|null $userRoles Current user's role slugs.
 * @return array Tier list (may be empty).
 */
function fcbo_select_role_tier_set($feed, $userRoles)
{
    return \FluentCartBulkOrder\AccessPolicy::selectRoleTierSet($feed, $userRoles);
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
 * Pick the tier that prices a given quantity.
 *
 * A tier matches when qty >= min_qty and (max_qty is 0 or qty <= max_qty).
 * Several tiers can match at once — an open-ended "30+" tier still matches at
 * qty 70 even when a "60+" tier exists — so the FIRST match is not the right
 * answer. The most specific one wins: the highest min_qty among the matches
 * (ties broken by the later tier, which is the one the admin added last).
 *
 * This is the ONE tier-matching rule in PHP; resolveTier() in
 * bulk-pricing-display.js and getEffectivePrice() in bulk-order.js mirror it
 * and MUST change together with it.
 *
 * @param array $tiers Sanitized tier list (any order).
 * @param int   $qty   Quantity being priced.
 * @return array|null The winning tier, or null when nothing matches.
 */
function fcbo_match_tier($tiers, $qty)
{
    $qty   = (int) $qty;
    $best  = null;
    $bestMin = -1;

    foreach ((array) $tiers as $tier) {
        $minQty = (int) ($tier['min_qty'] ?? 0);
        $maxQty = (int) ($tier['max_qty'] ?? 0);

        if ($qty < $minQty || ($maxQty > 0 && $qty > $maxQty)) {
            continue;
        }

        if ($minQty >= $bestMin) {
            $best    = $tier;
            $bestMin = $minQty;
        }
    }

    return $best;
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
 * Translatable savings/nudge strings handed to the two live-total surfaces.
 *
 * The JS files have no translation layer of their own — the plugin does not load
 * wp.i18n and has no script translations yet (roadmap Phase 1 · item 5). Passing
 * the finished sentences through wp_localize_script() keeps every shopper-facing
 * string inside the PHP .pot file, which is where the rest of the plugin's text
 * already lives. When script translations land, these move to wp.i18n and this
 * helper goes away.
 *
 * Placeholders are named ({amount}, {qty}, {percent}) rather than positional, so
 * a translator can reorder them freely. Whole sentences, not fragments: a
 * translator needs the full clause to get agreement and word order right.
 *
 * @return array<string, string> Templates keyed for the JS `fill()` helpers.
 */
function fcbo_savings_strings()
{
    return [
        /* translators: {amount}: money amount, e.g. $12.50. Keep {amount} as-is. */
        'saved'          => __('You saved {amount}', 'fluent-cart-bulk-order'),
        /* translators: {qty}: how many more units; {percent}: discount percentage. Keep both as-is. */
        'unlock_percent' => __('Add {qty} more to unlock {percent}% off', 'fluent-cart-bulk-order'),
        /* translators: {qty}: how many more units. Keep {qty} as-is. Used when the next tier is a money amount, not a percentage. */
        'unlock_generic' => __('Add {qty} more to unlock a better price', 'fluent-cart-bulk-order'),
    ];
}

/**
 * Every shopper-facing sentence in assets/js/bulk-order.js.
 *
 * Same contract as fcbo_savings_strings(): the sentence is translated here,
 * whole, and the JS only fills the {placeholders}. Building a sentence by
 * concatenating fragments in JS cannot be translated — word order differs
 * between languages, so "Add 5 more" is not "Add" + qty + "more" everywhere.
 *
 * @return array<string,string>
 */
function fcbo_bulk_order_strings()
{
    return [
        // Row controls
        'remove_row'         => __('Remove', 'fluent-cart-bulk-order'),
        'search_placeholder' => __('Search products...', 'fluent-cart-bulk-order'),

        // Search dropdown
        'search_results' => __('Product search results', 'fluent-cart-bulk-order'),
        'search_failed' => __('Search failed', 'fluent-cart-bulk-order'),
        'no_products'   => __('No products found', 'fluent-cart-bulk-order'),
        'no_variants'   => __('No available variants', 'fluent-cart-bulk-order'),
        'out_of_stock'  => __('(Out of stock)', 'fluent-cart-bulk-order'),
        /* translators: {sku}: a product SKU. Keep {sku} as-is. */
        'sku_label'     => __('SKU {sku}', 'fluent-cart-bulk-order'),

        // Saving an order
        'save_need_product'  => __('Add at least one product before saving.', 'fluent-cart-bulk-order'),
        'save_name_prompt'   => __('Name this saved order:', 'fluent-cart-bulk-order'),
        'save_need_name'     => __('Please enter a name for the saved order.', 'fluent-cart-bulk-order'),
        'saving'             => __('Saving order...', 'fluent-cart-bulk-order'),
        'save_failed'        => __('Could not save the order.', 'fluent-cart-bulk-order'),
        'save_failed_retry'  => __('Could not save the order. Please try again.', 'fluent-cart-bulk-order'),
        /* translators: {name}: the name the shopper gave the saved order. Keep {name} as-is. */
        'save_succeeded'     => __('Saved order "{name}".', 'fluent-cart-bulk-order'),

        // Checkout
        'checkout_need_product'   => __('Please select at least one product.', 'fluent-cart-bulk-order'),
        'checkout_mixed_types'    => __('Cannot mix subscription and one-time products in the same order. Please remove one type before proceeding.', 'fluent-cart-bulk-order'),
        /* translators: {amount}: money still needed; {minimum}: the required order total. Keep both as-is. */
        'checkout_below_minimum'  => __('Add {amount} more to reach the {minimum} minimum order total.', 'fluent-cart-bulk-order'),
        'checkout_cart_missing'   => __('FluentCart cart is not available. Please refresh the page and try again.', 'fluent-cart-bulk-order'),
        'checkout_adding'         => __('Adding items to cart...', 'fluent-cart-bulk-order'),
        'checkout_redirecting'    => __('Redirecting to checkout...', 'fluent-cart-bulk-order'),
        'checkout_not_configured' => __('Checkout page is not configured. Please check FluentCart settings.', 'fluent-cart-bulk-order'),
        /* translators: {index}: which item is being added; {total}: how many there are. Keep both as-is. */
        'checkout_adding_item'    => __('Adding item {index} of {total}...', 'fluent-cart-bulk-order'),
        /* translators: {error}: the error the cart reported. Keep {error} as-is. */
        'checkout_add_failed'     => __('Failed to add item: {error}', 'fluent-cart-bulk-order'),
        'unknown_error'           => __('Unknown error', 'fluent-cart-bulk-order'),

        // Order rules (@see describeQtyAdjustment)
        /* translators: {min}: minimum quantity; {step}: case-pack multiple; {qty}: the quantity now set. Keep all three as-is. */
        'qty_min_and_step'    => __('Minimum order is {min}, in multiples of {step}. Quantity set to {qty}.', 'fluent-cart-bulk-order'),
        /* translators: {step}: case-pack multiple; {qty}: the quantity now set. Keep both as-is. */
        'qty_step'            => __('Sold in multiples of {step}. Quantity rounded up to {qty}.', 'fluent-cart-bulk-order'),
        /* translators: {min}: minimum quantity; {qty}: the quantity now set. Keep both as-is. */
        'qty_min'             => __('Minimum order quantity is {min}. Quantity set to {qty}.', 'fluent-cart-bulk-order'),
        'qty_adjusted_many'   => __('Some quantities were adjusted to meet this store\'s order rules.', 'fluent-cart-bulk-order'),

        // Quick order (paste / CSV)
        'file_read_failed' => __('Could not read the file. Please try again.', 'fluent-cart-bulk-order'),
        'sku_missing'      => __('Missing SKU', 'fluent-cart-bulk-order'),
        'sku_unknown'      => __('No matching product', 'fluent-cart-bulk-order'),
        /* translators: {count}: how many variants share the SKU. Keep {count} as-is. */
        'sku_ambiguous'    => __('Matches {count} variants — add it manually', 'fluent-cart-bulk-order'),
        /* translators: {qty}: the unreadable value the shopper pasted. Keep {qty} as-is. */
        'qty_invalid'      => __('Invalid quantity "{qty}"', 'fluent-cart-bulk-order'),
        /* translators: {count}: how many rows were added. Keep {count} as-is. */
        'report_added_one' => __('{count} item added', 'fluent-cart-bulk-order'),
        /* translators: {count}: how many rows were added. Keep {count} as-is. */
        'report_added'     => __('{count} items added', 'fluent-cart-bulk-order'),
        /* translators: {count}: how many pasted lines were not added. Keep {count} as-is. */
        'report_skipped'   => __('{count} skipped', 'fluent-cart-bulk-order'),
        /* translators: {line}: the line number in the pasted text or CSV. Keep {line} as-is. */
        'report_line'      => __('Line {line}', 'fluent-cart-bulk-order'),
        /* translators: {label}: product name; {qty}: quantity added. Keep both as-is. */
        'report_item'      => __('{label} × {qty}', 'fluent-cart-bulk-order'),
        /* translators: {qty}: the quantity originally asked for. Keep {qty} as-is. */
        'report_adjusted'  => __('(adjusted from {qty} to meet order rules)', 'fluent-cart-bulk-order'),
    ];
}

/**
 * Every shopper-facing sentence in assets/js/product-table.js.
 *
 * @see fcbo_bulk_order_strings() for why whole sentences are translated
 *      here rather than assembled from fragments in JS.
 *
 * @return array<string,string>
 */
function fcbo_product_table_strings()
{
    return [
        'loading'      => __('Loading products...', 'fluent-cart-bulk-order'),
        'load_failed'  => __('Failed to load products.', 'fluent-cart-bulk-order'),
        'no_products'  => __('No products found.', 'fluent-cart-bulk-order'),

        // Add-to-cart button, through its whole cycle
        'add_to_cart'  => __('Add to Cart', 'fluent-cart-bulk-order'),
        'out_of_stock' => __('Out of Stock', 'fluent-cart-bulk-order'),
        'adding'       => __('Adding...', 'fluent-cart-bulk-order'),
        'added'        => __('Added!', 'fluent-cart-bulk-order'),

        // Order rules, shown next to the quantity input
        /* translators: {min}: minimum quantity; {step}: case-pack multiple. Keep both as-is. */
        'rule_min_and_step' => __('Min {min}, in {step}s', 'fluent-cart-bulk-order'),
        /* translators: {step}: case-pack multiple. Keep {step} as-is. */
        'rule_step'         => __('Sold in {step}s', 'fluent-cart-bulk-order'),
        /* translators: {min}: minimum quantity. Keep {min} as-is. */
        'rule_min'          => __('Min {min}', 'fluent-cart-bulk-order'),
        /* translators: {qty}: the quantity now set. Keep {qty} as-is. */
        'qty_adjusted'      => __('Quantity adjusted to {qty} to meet this product\'s order rules.', 'fluent-cart-bulk-order'),

        'cart_missing'  => __('FluentCart cart is not available. Please refresh the page.', 'fluent-cart-bulk-order'),
        /* translators: {error}: the error the cart reported. Keep {error} as-is. */
        'add_failed'    => __('Failed: {error}', 'fluent-cart-bulk-order'),
        'unknown_error' => __('Unknown error', 'fluent-cart-bulk-order'),

        /* translators: {current}: current page number; {total}: how many pages there are. Keep both as-is. */
        'page_of' => __('Page {current} of {total}', 'fluent-cart-bulk-order'),
    ];
}

/**
 * Every shopper-facing sentence in assets/js/saved-orders.js.
 *
 * @see fcbo_bulk_order_strings() for why whole sentences are translated
 *      here rather than assembled from fragments in JS.
 *
 * @return array<string,string>
 */
function fcbo_saved_orders_strings()
{
    return [
        'divider_saved' => __('Saved orders', 'fluent-cart-bulk-order'),
        'divider_past'  => __('Past orders', 'fluent-cart-bulk-order'),

        'cart_missing'      => __('FluentCart cart is not available. Please refresh the page and try again.', 'fluent-cart-bulk-order'),
        'nothing_available' => __('None of the items in this order are available anymore.', 'fluent-cart-bulk-order'),
        'mixed_types'       => __('This order mixes subscription and one-time products, which cannot be reordered together.', 'fluent-cart-bulk-order'),

        'adding'  => __('Adding items to cart...', 'fluent-cart-bulk-order'),
        /* translators: {count}: items being added; {skipped}: items no longer available. Keep both as-is. */
        'adding_some' => __('Adding {count} item(s); {skipped} unavailable skipped...', 'fluent-cart-bulk-order'),
        'redirecting' => __('Redirecting to checkout...', 'fluent-cart-bulk-order'),
        'checkout_not_configured' => __('Checkout page is not configured. Please check FluentCart settings.', 'fluent-cart-bulk-order'),
        /* translators: {error}: the error the cart reported. Keep {error} as-is. */
        'add_failed'    => __('Failed to add an item: {error}', 'fluent-cart-bulk-order'),
        'unknown_error' => __('Unknown error', 'fluent-cart-bulk-order'),

        /* translators: {name}: the name the shopper gave the saved order. Keep {name} as-is. */
        'delete_confirm' => __('Delete saved order "{name}"?', 'fluent-cart-bulk-order'),
        'delete_done'    => __('Saved order deleted.', 'fluent-cart-bulk-order'),
        'delete_failed'  => __('Could not delete the saved order. Please try again.', 'fluent-cart-bulk-order'),
    ];
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
        'i18n'          => fcbo_savings_strings(),
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

        // The two empty spans are filled by bulk-pricing-display.js as the
        // quantity changes: the nudge toward the next tier sits under the input
        // the shopper is typing in, the line saving under the price it changes.
        printf(
            '<tr data-fcbo-variant="%s"><td>%s</td><td><input type="number" class="fcbo-bp-qty-input" value="0" min="0" /><span class="fcbo-bp-nudge"></span></td><td class="fcbo-bp-price-cell"><span class="fcbo-bp-muted">&mdash;</span></td></tr>',
            $dataAttr,
            esc_html($v['title'])
        );
    }

    echo '</tbody><tfoot><tr>';
    echo '<td><strong>' . esc_html__('Total', 'fluent-cart-bulk-order') . '</strong></td>';
    echo '<td class="fcbo-bp-grand-saving"></td>';
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
 * Gate 2 — role slugs that bulk pricing is restricted to.
 *
 * @see \FluentCartBulkOrder\AccessPolicy::bulkPricingRoles()
 * @return string[] Role slugs; empty array = everyone qualifies.
 */
function fcbo_get_bulk_pricing_roles()
{
    return \FluentCartBulkOrder\AccessPolicy::bulkPricingRoles();
}

/**
 * Gate 2 — whether a user qualifies for bulk pricing under the stored policy.
 *
 * @see \FluentCartBulkOrder\AccessPolicy::userQualifiesForBulkPricing()
 * @param \WP_User|null $user    User to test; defaults to the current user.
 * @param string        $context 'cart' (enforcement) or 'display' (preview).
 * @return bool
 */
function fcbo_user_qualifies_for_bulk_pricing($user = null, $context = 'cart')
{
    return \FluentCartBulkOrder\AccessPolicy::userQualifiesForBulkPricing($user, $context);
}

/**
 * Gate 3 — the configured minimum order total, in integer cents.
 *
 * @see \FluentCartBulkOrder\AccessPolicy::minOrderTotal()
 * @return int Cents; 0 = no minimum.
 */
function fcbo_get_min_order_total()
{
    return \FluentCartBulkOrder\AccessPolicy::minOrderTotal();
}

/**
 * Gate 3 — roles the minimum order total applies to.
 *
 * Note the INVERTED empty-list meaning versus Gate 2: empty here means "nobody
 * is subject". @see \FluentCartBulkOrder\AccessPolicy::minOrderTotalRoles()
 *
 * @return string[] Role slugs; empty = nobody is subject.
 */
function fcbo_get_min_order_total_roles()
{
    return \FluentCartBulkOrder\AccessPolicy::minOrderTotalRoles();
}

/**
 * Gate 3 — whether a user must meet the minimum order total.
 *
 * @see \FluentCartBulkOrder\AccessPolicy::userSubjectToMinOrder()
 * @param \WP_User|null $user Defaults to the current user.
 * @return bool
 */
function fcbo_user_subject_to_min_order($user = null)
{
    return \FluentCartBulkOrder\AccessPolicy::userSubjectToMinOrder($user);
}

/**
 * FluentCart filter callback: price one cart line against its bulk tier.
 *
 * ---------------------------------------------------------------------------
 * WHY `item_price` AND NOT `item_modify`
 * ---------------------------------------------------------------------------
 *
 * This used to run on `fluent_cart/cart/item_modify`, which is handed the
 * quantity from the REQUEST. That is not always the quantity the line ends up
 * with. When FluentCart is told to ADD to a line rather than SET it (the
 * `by_input` flag is absent), Cart::addByVariation() folds the existing
 * quantity in afterwards:
 *
 *     if (!$byInput) { $quantity += $prevItem['quantity']; }   // Cart.php:406
 *
 * So the tier was chosen for the increment while the line was billed for the
 * total. Adding 5 and then 5 more left 10 units priced at the 5-unit tier, and
 * the cart drawer's + button — which posts an increment of 1 — re-priced a
 * 10-unit line as if it were a single unit, wiping a discount the shopper had
 * already earned.
 *
 * `fluent_cart/cart/item_price` fires inside
 * CartHelper::generateCartItemFromVariation() (CartHelper.php:34), AFTER that
 * fold, and its value becomes the line's `unit_price` directly. Every path that
 * builds a cart line goes through it: add, quantity update, the +/- buttons,
 * instant checkout, and the checkout order bump. Pricing there means the tier
 * always matches the quantity actually billed.
 *
 * The two hooks must never BOTH be used: `item_modify` mutates the variation
 * that is then passed to generateCartItemFromVariation(), so a discount applied
 * in both places would compound — 10% off twice is 19% off.
 *
 * Not covered: ProductItemService::getItem(), which builds subscription plans
 * for the payment gateways and calls `item_modify` without ever reaching
 * `item_price`. Subscriptions are single-quantity everywhere in FluentCart, and
 * a bulk tier is a quantity feature, so there is nothing for a tier to match
 * there unless a store sets min_qty to 1.
 *
 * @param int   $itemPrice Per-unit price in cents, as filtered so far. Used as
 *                         the base rather than $variation->item_price so an
 *                         earlier filter's adjustment is not discarded.
 * @param array $context   ['variation' => object, 'quantity' => int] — the
 *                         SETTLED quantity for this line.
 * @return int Per-unit price in cents.
 */
function fcbo_apply_cart_bulk_pricing($itemPrice, $context)
{
    $variation = isset($context['variation']) ? $context['variation'] : null;
    $qty       = (int) ($context['quantity'] ?? 0);

    if (!$variation || empty($variation->id) || $qty < 1) {
        return $itemPrice;
    }

    // Gate the discount by the stored role policy. Non-qualifying shoppers keep
    // the full price. Admins are NOT exempt on the cart path (KTD4) — which is
    // why fcbo_build_variant_payload() withholds tiers from them too, so the
    // bulk order form cannot quote a total this function will not honour.
    if (!fcbo_user_qualifies_for_bulk_pricing(null, 'cart')) {
        return $itemPrice;
    }

    $productId = (int) $variation->post_id;
    $variantId = (int) $variation->id;

    $pricingData = fcbo_get_all_bulk_pricing([$productId]);
    // Role selects which tier-set prices this line (composes with the gate above).
    $userRoles   = (array) wp_get_current_user()->roles;
    $tiers       = fcbo_resolve_tiers($pricingData, $productId, $variantId, $userRoles);

    if (empty($tiers)) {
        return $itemPrice;
    }

    $tier = fcbo_match_tier($tiers, $qty);

    return $tier ? fcbo_apply_tier_to_price((int) $itemPrice, $tier) : $itemPrice;
}

/**
 * What the bulk tiers took off one cart line, in cents.
 *
 * The cart item's own `unit_price` is already the discounted figure — this
 * plugin lowered it through `fluent_cart/cart/item_price` as the line was
 * built — so the original has to come back from the database. A direct
 * ProductVariation query is the right source precisely because it does NOT run
 * that filter: it is the last untouched copy of the pre-discount price.
 *
 * The saving is then recomputed the same way the cart filter computed the
 * price, rather than subtracting `unit_price`, so a line another extension also
 * repriced does not get that other extension's discount reported as ours.
 *
 * @param array $item One entry of the cart's `cart_data` items.
 * @return int Saving in cents; 0 when this line has none.
 */
function fcbo_cart_line_saving($item)
{
    // Same gate as the cart price itself: a shopper the policy excludes was
    // never discounted, so there is nothing to report. Admins are not exempt
    // here — the 'cart' context deliberately excludes them (KTD4).
    if (!fcbo_user_qualifies_for_bulk_pricing(null, 'cart')) {
        return 0;
    }

    $qty       = (int) ($item['quantity'] ?? 0);
    $variantId = (int) ($item['object_id'] ?? 0);
    $productId = (int) ($item['post_id'] ?? 0);

    // Custom items are priced by whoever injected them, not from a variation row.
    if ($qty < 1 || !$variantId || !$productId || !empty($item['is_custom'])) {
        return 0;
    }

    // The cart re-renders this line on every quantity change and the drawer can
    // hold many lines, so the per-variant answer is memoized for the request.
    static $cache = [];
    $key = $variantId . ':' . $qty;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $cache[$key] = 0;

    $tiers = fcbo_resolve_tiers(
        fcbo_get_all_bulk_pricing([$productId]),
        $productId,
        $variantId,
        (array) wp_get_current_user()->roles
    );

    $tier = $tiers ? fcbo_match_tier($tiers, $qty) : null;
    if (!$tier) {
        return 0;
    }

    $variation = \FluentCart\App\Models\ProductVariation::query()->find($variantId);
    if (!$variation) {
        return 0;
    }

    $original  = (int) $variation->item_price;
    $effective = fcbo_apply_tier_to_price($original, $tier);

    $cache[$key] = max(0, ($original - $effective) * $qty);

    return $cache[$key];
}

/**
 * Print "You saved $X" under a discounted cart line.
 *
 * Bound to `fluent_cart/cart/line_item/after_total` — the checkout order
 * summary and instant checkout. @see the registration for the full hook map.
 *
 * @param array $eventInfo ['item' => array, 'cart' => object, ...]
 * @return void
 */
function fcbo_render_cart_line_saving($eventInfo)
{
    $saving = fcbo_cart_line_saving(isset($eventInfo['item']) ? (array) $eventInfo['item'] : []);

    if ($saving <= 0) {
        return;
    }

    fcbo_print_cart_saving_style();

    printf(
        '<span class="fcbo-cart-saving">%s</span>',
        esc_html(sprintf(
            /* translators: %s: money amount, e.g. $12.50 */
            __('You saved %s', 'fluent-cart-bulk-order'),
            fcbo_format_money($saving)
        ))
    );
}

/**
 * Same line, printed from the cart drawer's only hook.
 *
 * `line_meta` fires in two renderers. CartRenderer is the drawer, and it is the
 * one that needs this. CartItemRenderer fires it too, but that renderer also
 * fires `after_total`, which is a better spot (beside the price) and is already
 * covered — so its `line_meta` pass must stay silent or the saving prints twice
 * on the checkout summary.
 *
 * The two are told apart by the cart object: CartRenderer::getEventInfo() hard-
 * codes `'cart' => null` (CartRenderer.php:170), while every CartItemRenderer
 * that fires hooks is constructed with the cart (CartSummaryRender.php:95). If a
 * future FluentCart release starts passing a cart from the drawer, the drawer
 * simply stops showing the line — a cosmetic loss, not a duplicate or an error.
 *
 * @param array $eventInfo ['item' => array, 'cart' => object|null, ...]
 * @return void
 */
function fcbo_render_cart_line_saving_meta($eventInfo)
{
    if (!empty($eventInfo['cart'])) {
        return;
    }

    fcbo_render_cart_line_saving($eventInfo);
}

/**
 * Emit the one style rule the cart saving line needs, once per request.
 *
 * Deliberately inline rather than an enqueued stylesheet. FluentCart re-renders
 * these line items into AJAX fragments when a quantity changes, and a fragment
 * response carries no <head> and runs no enqueue pass — an enqueued file would
 * simply not be there. Travelling with the markup is the only delivery that
 * works on both the full page render and the fragment.
 *
 * @return void
 */
function fcbo_print_cart_saving_style()
{
    static $printed = false;

    if ($printed) {
        return;
    }
    $printed = true;

    echo '<style>.fcbo-cart-saving{display:block;font-size:12px;font-weight:600;color:#16a34a;}</style>';
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
 * Thin delegate. The surface itself lives in
 * \FluentCartBulkOrder\Shortcodes\SavedOrders, registered through
 * ShortcodeHandler. This wrapper stays because docs/plans and site snippets name
 * it, and because a theme may call it directly to place the surface outside
 * post content. Put new logic in the class, not here.
 *
 * @see \FluentCartBulkOrder\Shortcodes\SavedOrders
 * @param array $atts
 * @return string
 */
function fcbo_render_saved_orders($atts = [])
{
    require_once FCBO_DIR . 'includes/Shortcodes/ShortcodeHandler.php';

    return \FluentCartBulkOrder\Shortcodes\ShortcodeHandler::renderTag('fluent_cart_saved_orders', $atts);
}
