<?php
/**
 * Plugin Name: Fluent Cart Bulk Order
 * Description: Wholesale and B2B ordering for FluentCart: a multi-line bulk order form, a product table, quantity pricing tiers, order rules, quotes, PO numbers and a wholesale application flow.
 * Version: 1.1.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Requires Plugins: fluent-cart
 * Author: Rahat Baksh
 * Author URI: https://profiles.wordpress.org/rahatbaksh/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fluent-cart-bulk-order
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('FCBO_VERSION', '1.1.0');
define('FCBO_DIR', plugin_dir_path(__FILE__));
define('FCBO_URL', plugin_dir_url(__FILE__));

// Loaded unconditionally, and BEFORE plugins_loaded, on purpose. AccessPolicy
// holds every role gate in the plugin and the `fcbo_*` gate functions below are
// thin delegates to it — a theme or snippet may call one at any point in the
// request, including on a page load where FluentCart is inactive. None of these
// files touch FluentCart at include time. Settings comes along because
// AccessPolicy::settingsPageUrl() reads Settings::PAGE_SLUG, and StoreDefaults
// first because Gate 1 in AccessPolicy reads its stored role list.
require_once FCBO_DIR . 'includes/StoreDefaults.php';
require_once FCBO_DIR . 'includes/AccessPolicy.php';
require_once FCBO_DIR . 'includes/Settings.php';

// StoreDefaults caches its option for the request, and the settings page reads
// it again after saving. Hooked here rather than inside the class so the class
// stays a plain data reader with no side effects at include time.
add_action('update_option_' . \FluentCartBulkOrder\StoreDefaults::OPTION, [\FluentCartBulkOrder\StoreDefaults::class, 'flush']);

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

    // Editor wrappers over two of those tags, for owners who never touch a
    // shortcode. Both render THROUGH the shortcodes above, so they inherit the
    // gates and the store-wide defaults rather than reimplementing them.
    //
    // `init` because register_block_type() must not run earlier than that, and
    // `elementor/widgets/register` because that hook cannot fire unless
    // Elementor is installed - which is the whole of the guard Elementor needs.
    add_action('init', 'fcbo_register_blocks');
    add_action('elementor/widgets/register', 'fcbo_register_elementor_widgets');

    add_action('rest_api_init', 'fcbo_register_routes');

    // The wholesale application flow: the shopper's form POST, the admin review
    // screen, the notification emails and the FluentCRM tagging. Two lines here
    // because WholesaleFlow owns its own hook list and loads its classes only
    // when one of them fires. @see \FluentCartBulkOrder\Wholesale\WholesaleFlow
    //
    // The FORM is not registered here — it is a shortcode, and it lives in
    // ShortcodeHandler::SHORTCODES with the others.
    require_once FCBO_DIR . 'includes/Wholesale/WholesaleFlow.php';
    \FluentCartBulkOrder\Wholesale\WholesaleFlow::register();

    // The request-a-quote flow: the quote post type, the owner's review screen
    // and the notification emails. Two lines here for the same reason as above
    // — QuoteFlow owns its own hook list and loads its classes only when one of
    // them fires. @see \FluentCartBulkOrder\Quotes\QuoteFlow
    //
    // The BUTTON is not registered here — it is part of the bulk order form
    // shortcode. The endpoint it posts to is a REST route and lives with the
    // others in \FluentCartBulkOrder\Rest\Routes.
    require_once FCBO_DIR . 'includes/Quotes/QuoteFlow.php';
    \FluentCartBulkOrder\Quotes\QuoteFlow::register();

    // The PO number field at checkout: the field itself, the server-side
    // refusal when it is required, and every place a stored value is shown
    // back. Two lines here for the same reason as above — and PoNumberFlow
    // skips the checkout half when the owner has not turned the field on,
    // which is the default. @see \FluentCartBulkOrder\Checkout\PoNumberFlow
    require_once FCBO_DIR . 'includes/Checkout/PoNumberFlow.php';
    \FluentCartBulkOrder\Checkout\PoNumberFlow::register();

    // Exporting a single order as a CSV or a printable receipt: the download
    // endpoint, the buyer's links on their own receipt and dashboard, and the
    // owner's order screen. @see \FluentCartBulkOrder\Export\OrderExportFlow
    // for the three checks on an export URL, and OrderReceiptView for what the
    // PDF half does and does not promise.
    require_once FCBO_DIR . 'includes/Export/OrderExportFlow.php';
    \FluentCartBulkOrder\Export\OrderExportFlow::register();

    // Owner-side analytics: which orders came through bulk ordering, who is
    // buying, and which quantity tiers buyers actually reach. Two halves —
    // a recorder that stamps an order as it is created, and a screen that
    // reads it back. The recorder is the reason this is not a report that
    // could have been written against existing data.
    // @see \FluentCartBulkOrder\Analytics\AnalyticsFlow
    require_once FCBO_DIR . 'includes/Analytics/AnalyticsFlow.php';
    \FluentCartBulkOrder\Analytics\AnalyticsFlow::register();

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

/**
 * Load the block editor wrappers.
 *
 * Required on demand, like every other loader here: the block layer matters on
 * `init` and on a page that actually contains a block, and nowhere else.
 * AttributeSchema comes along because BlockHandler cannot map a single
 * attribute without it.
 *
 * @return void
 */
function fcbo_load_blocks()
{
    require_once FCBO_DIR . 'includes/Shortcodes/AttributeSchema.php';
    require_once FCBO_DIR . 'includes/Blocks/BlockHandler.php';
}

/**
 * Register the Gutenberg blocks for the bulk order form and the product table.
 *
 * @see \FluentCartBulkOrder\Blocks\BlockHandler::register()
 * @return void
 */
function fcbo_register_blocks()
{
    fcbo_load_blocks();

    \FluentCartBulkOrder\Blocks\BlockHandler::register();
}

/**
 * Register the Elementor widgets for the bulk order form and the product table.
 *
 * Only ever called from `elementor/widgets/register`. The widget classes extend
 * \Elementor\Widget_Base and so cannot even be parsed without Elementor, which
 * is why WidgetHandler - and not this function - is what requires them.
 *
 * @see \FluentCartBulkOrder\Integrations\Elementor\WidgetHandler::register()
 * @param mixed $widgetsManager Elementor's widgets manager.
 * @return void
 */
function fcbo_register_elementor_widgets($widgetsManager)
{
    require_once FCBO_DIR . 'includes/Shortcodes/AttributeSchema.php';
    require_once FCBO_DIR . 'includes/Integrations/Elementor/WidgetHandler.php';

    \FluentCartBulkOrder\Integrations\Elementor\WidgetHandler::register($widgetsManager);
}

/**
 * Load the REST layer.
 *
 * Required on demand rather than at the top of this file: these three classes
 * are dead weight on an ordinary front-end page load, and two of the helpers
 * below are also reached from the saved-orders surface, which is not always a
 * REST request. require_once is idempotent, so calling this from every delegate
 * costs one already-included check.
 *
 * @return void
 */
function fcbo_load_rest()
{
    require_once FCBO_DIR . 'includes/Rest/Routes.php';
    require_once FCBO_DIR . 'includes/Rest/ProductsController.php';
    require_once FCBO_DIR . 'includes/Rest/SavedOrdersController.php';
    require_once FCBO_DIR . 'includes/Rest/QuotesController.php';
}

/**
 * Register every fcbo/v1 REST route.
 *
 * @see \FluentCartBulkOrder\Rest\Routes::register()
 * @return void
 */
function fcbo_register_routes()
{
    fcbo_load_rest();

    \FluentCartBulkOrder\Rest\Routes::register();
}

/**
 * Sanitize the `skus` request param into a de-duplicated list of trimmed strings.
 *
 * Registered BY NAME as the /resolve-skus sanitize_callback, so this function
 * name is load-bearing — it cannot become a class method alone.
 *
 * @see \FluentCartBulkOrder\Rest\ProductsController::sanitizeSkusParam()
 * @param mixed $value Raw request value.
 * @return string[] Clean, de-duplicated SKU strings (max 500).
 */
function fcbo_sanitize_skus_param($value)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\ProductsController::sanitizeSkusParam($value);
}

/**
 * Category names for one product, ready to serialize.
 *
 * @see \FluentCartBulkOrder\Rest\ProductsController::buildCategoryList()
 * @param int $productId
 * @return array
 */
function fcbo_build_category_list($productId)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\ProductsController::buildCategoryList($productId);
}

/**
 * The variant payload every FCBO endpoint answers with.
 *
 * One shape for /products, /catalog and /resolve-skus, because the browser
 * reuses a single selectProduct() path for all three.
 *
 * @see \FluentCartBulkOrder\Rest\ProductsController::buildVariantPayload()
 * @param object     $product
 * @param object     $variant
 * @param array      $pricingData
 * @param array|null $userRoles Roles to resolve tiers against; current user when null.
 * @return array
 */
function fcbo_build_variant_payload($product, $variant, $pricingData, $userRoles = null)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\ProductsController::buildVariantPayload($product, $variant, $pricingData, $userRoles);
}

/**
 * GET /products — partial-match product and variant search.
 *
 * @see \FluentCartBulkOrder\Rest\ProductsController::searchProducts()
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response
 */
function fcbo_search_products(\WP_REST_Request $request)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\ProductsController::searchProducts($request);
}

/**
 * POST /resolve-skus — exact, batched SKU to variant resolution.
 *
 * @see \FluentCartBulkOrder\Rest\ProductsController::resolveSkus()
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response
 */
function fcbo_resolve_skus(\WP_REST_Request $request)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\ProductsController::resolveSkus($request);
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

/**
 * GET /catalog — the paged catalogue behind the product table.
 *
 * @see \FluentCartBulkOrder\Rest\ProductsController::listCatalog()
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response
 */
function fcbo_list_catalog(\WP_REST_Request $request)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\ProductsController::listCatalog($request);
}

/**
 * Load the bulk-pricing engine.
 *
 * Required on demand: tier and order-rule resolution is needed on the cart, the
 * checkout backstop, the product surfaces and the REST payloads, but not on an
 * ordinary page load. require_once is idempotent, so every delegate can call it.
 *
 * @return void
 */
function fcbo_load_pricing()
{
    require_once FCBO_DIR . 'includes/Pricing/OrderRules.php';
    require_once FCBO_DIR . 'includes/Pricing/Tiers.php';
    require_once FCBO_DIR . 'includes/Pricing/FeedResolver.php';
}

/**
 * Every bulk-pricing feed relevant to a set of products, store-wide included.
 *
 * @see \FluentCartBulkOrder\Pricing\FeedResolver::allBulkPricing()
 * @param int[] $productIds
 * @return array
 */
function fcbo_get_all_bulk_pricing($productIds)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\FeedResolver::allBulkPricing($productIds);
}

/**
 * The tier list that applies to one variant for one shopper.
 *
 * @see \FluentCartBulkOrder\Pricing\FeedResolver::resolveTiers()
 * @param array      $pricingData
 * @param int        $productId
 * @param int        $variantId
 * @param array|null $userRoles Roles to resolve against; current user when null.
 * @return array
 */
function fcbo_resolve_tiers($pricingData, $productId, $variantId, $userRoles = null)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\FeedResolver::resolveTiers($pricingData, $productId, $variantId, $userRoles);
}

/**
 * The feed that governs one variant, product-scoped beating store-wide.
 *
 * @see \FluentCartBulkOrder\Pricing\FeedResolver::matchFeed()
 * @param array $pricingData
 * @param int   $productId
 * @param int   $variantId
 * @return array|null
 */
function fcbo_match_feed($pricingData, $productId, $variantId)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\FeedResolver::matchFeed($pricingData, $productId, $variantId);
}

/**
 * The order rules that apply to one variant.
 *
 * Resolved through the SAME feed match as the tiers, so the feed that prices a
 * variant is always the feed that constrains it.
 *
 * @see \FluentCartBulkOrder\Pricing\FeedResolver::resolveOrderRules()
 * @param array $pricingData
 * @param int   $productId
 * @param int   $variantId
 * @return array{min_qty:int,step:int}
 */
function fcbo_resolve_order_rules($pricingData, $productId, $variantId)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\FeedResolver::resolveOrderRules($pricingData, $productId, $variantId);
}

/**
 * Coerce a raw order-rules array into the canonical shape.
 *
 * @see \FluentCartBulkOrder\Pricing\OrderRules::normalize()
 * @param mixed $rules
 * @return array{min_qty:int,step:int}
 */
function fcbo_normalize_order_rules($rules)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\OrderRules::normalize($rules);
}

/**
 * Whether a normalized rules array actually constrains anything.
 *
 * @see \FluentCartBulkOrder\Pricing\OrderRules::areSet()
 * @param array $rules
 * @return bool
 */
function fcbo_order_rules_are_set($rules)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\OrderRules::areSet($rules);
}

/**
 * Round a quantity UP to the nearest value the rules permit.
 *
 * @see \FluentCartBulkOrder\Pricing\OrderRules::normalizeQty()
 * @param int   $qty
 * @param array $rules
 * @return int
 */
function fcbo_normalize_qty($qty, $rules)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\OrderRules::normalizeQty($qty, $rules);
}

/**
 * Whether a quantity already satisfies the rules.
 *
 * @see \FluentCartBulkOrder\Pricing\OrderRules::qtyIsValid()
 * @param int   $qty
 * @param array $rules
 * @return bool
 */
function fcbo_qty_is_valid($qty, $rules)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\OrderRules::qtyIsValid($qty, $rules);
}

/**
 * The tier list a feed offers this shopper's roles, falling back to `tiers`.
 *
 * @see \FluentCartBulkOrder\Pricing\Tiers::selectRoleTierSet()
 * @param array      $feed
 * @param array|null $userRoles
 * @return array
 */
function fcbo_select_role_tier_set($feed, $userRoles)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\Tiers::selectRoleTierSet($feed, $userRoles);
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
 * Apply one tier's discount to a unit price, in integer cents.
 *
 * @see \FluentCartBulkOrder\Pricing\Tiers::applyToPrice()
 * @param int   $itemPriceCents
 * @param array $tier
 * @return int Discounted unit price, in cents.
 */
function fcbo_apply_tier_to_price($itemPriceCents, $tier)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\Tiers::applyToPrice($itemPriceCents, $tier);
}

/**
 * The most specific tier whose quantity range covers $qty.
 *
 * @see \FluentCartBulkOrder\Pricing\Tiers::match()
 * @param array $tiers
 * @param int   $qty
 * @return array|null
 */
function fcbo_match_tier($tiers, $qty)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\Tiers::match($tiers, $qty);
}

/**
 * Human-readable label for a tier's discount ("10% off", "$4.00 each", ...).
 *
 * @see \FluentCartBulkOrder\Pricing\Tiers::formatDiscountLabel()
 * @param array $tier
 * @return string
 */
function fcbo_format_tier_discount_label($tier)
{
    fcbo_load_pricing();

    return \FluentCartBulkOrder\Pricing\Tiers::formatDiscountLabel($tier);
}

/**
 * Load the translation tables.
 *
 * @return void
 */
function fcbo_load_strings()
{
    require_once FCBO_DIR . 'includes/Strings.php';
}

/**
 * Load the single-product tier display.
 *
 * @return void
 */
function fcbo_load_display()
{
    fcbo_load_strings();

    require_once FCBO_DIR . 'includes/Display/SingleProductTiers.php';
}

/**
 * Savings and next-tier sentences, shared by the order form and the tier table.
 *
 * @see \FluentCartBulkOrder\Strings::savings()
 * @return array<string,string>
 */
function fcbo_savings_strings()
{
    fcbo_load_strings();

    return \FluentCartBulkOrder\Strings::savings();
}

/**
 * Every sentence assets/js/bulk-order.js prints.
 *
 * @see \FluentCartBulkOrder\Strings::bulkOrder()
 * @return array<string,string>
 */
function fcbo_bulk_order_strings()
{
    fcbo_load_strings();

    return \FluentCartBulkOrder\Strings::bulkOrder();
}

/**
 * Every sentence assets/js/product-table.js prints.
 *
 * @see \FluentCartBulkOrder\Strings::productTable()
 * @return array<string,string>
 */
function fcbo_product_table_strings()
{
    fcbo_load_strings();

    return \FluentCartBulkOrder\Strings::productTable();
}

/**
 * Every sentence assets/js/saved-orders.js prints.
 *
 * @see \FluentCartBulkOrder\Strings::savedOrders()
 * @return array<string,string>
 */
function fcbo_saved_orders_strings()
{
    fcbo_load_strings();

    return \FluentCartBulkOrder\Strings::savedOrders();
}

/**
 * Enqueue the tier-display CSS and JS, once per request.
 *
 * @see \FluentCartBulkOrder\Display\SingleProductTiers::enqueueAssets()
 * @return void
 */
function fcbo_enqueue_bulk_pricing_assets()
{
    fcbo_load_display();

    \FluentCartBulkOrder\Display\SingleProductTiers::enqueueAssets();
}

/**
 * The per-variant order table rendered beneath the tier list.
 *
 * @see \FluentCartBulkOrder\Display\SingleProductTiers::renderOrderTable()
 * @param array  $variants
 * @param string $titleHeader
 * @return void
 */
function fcbo_render_order_table($variants, $titleHeader)
{
    fcbo_load_display();

    \FluentCartBulkOrder\Display\SingleProductTiers::renderOrderTable($variants, $titleHeader);
}

/**
 * Render the bulk-pricing tier table on a single product page.
 *
 * Hooked to `fluent_cart/product/single/after_quantity_block`.
 *
 * @see \FluentCartBulkOrder\Display\SingleProductTiers::render()
 * @param mixed $args Product context from FluentCart.
 * @return void
 */
function fcbo_render_single_product_tiers($args)
{
    fcbo_load_display();

    \FluentCartBulkOrder\Display\SingleProductTiers::render($args);
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
 * Load the cart layer.
 *
 * Required on demand: these run on cart and checkout requests, not on every
 * page load. require_once is idempotent, so every delegate can call it.
 *
 * @return void
 */
function fcbo_load_cart()
{
    require_once FCBO_DIR . 'includes/Cart/LinePricing.php';
    require_once FCBO_DIR . 'includes/Cart/SavingsDisplay.php';
    require_once FCBO_DIR . 'includes/Cart/RuleEnforcement.php';
}

/**
 * Price one cart line against its bulk tier.
 *
 * Hooked to `fluent_cart/cart/item_price` — the hook that sees the SETTLED
 * quantity. @see \FluentCartBulkOrder\Cart\LinePricing for why that matters.
 *
 * @param mixed $itemPrice Price FluentCart proposes, in cents.
 * @param mixed $context   Cart line context.
 * @return mixed Price to charge, in cents.
 */
function fcbo_apply_cart_bulk_pricing($itemPrice, $context)
{
    fcbo_load_cart();

    return \FluentCartBulkOrder\Cart\LinePricing::applyBulkPricing($itemPrice, $context);
}

/**
 * What one cart line saved against its undiscounted price, in cents.
 *
 * @see \FluentCartBulkOrder\Cart\LinePricing::lineSaving()
 * @param array $item Cart line.
 * @return int Saving in cents; 0 when no tier applies.
 */
function fcbo_cart_line_saving($item)
{
    fcbo_load_cart();

    return \FluentCartBulkOrder\Cart\LinePricing::lineSaving($item);
}

/**
 * Print the "you saved X" note under a cart line total.
 *
 * @see \FluentCartBulkOrder\Cart\SavingsDisplay::renderLineSaving()
 * @param array $eventInfo
 * @return void
 */
function fcbo_render_cart_line_saving($eventInfo)
{
    fcbo_load_cart();

    \FluentCartBulkOrder\Cart\SavingsDisplay::renderLineSaving($eventInfo);
}

/**
 * The same note, in FluentCart's line-meta slot.
 *
 * Two slots because which one a theme fires depends on its cart template.
 *
 * @see \FluentCartBulkOrder\Cart\SavingsDisplay::renderLineSavingMeta()
 * @param array $eventInfo
 * @return void
 */
function fcbo_render_cart_line_saving_meta($eventInfo)
{
    fcbo_load_cart();

    \FluentCartBulkOrder\Cart\SavingsDisplay::renderLineSavingMeta($eventInfo);
}

/**
 * Print the saving note's inline style, once per request.
 *
 * @see \FluentCartBulkOrder\Cart\SavingsDisplay::printStyle()
 * @return void
 */
function fcbo_print_cart_saving_style()
{
    fcbo_load_cart();

    \FluentCartBulkOrder\Cart\SavingsDisplay::printStyle();
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
 * Refuse a cart item whose quantity breaks its order rules.
 *
 * The server-side authority behind the surfaces' rounding.
 * @see \FluentCartBulkOrder\Cart\RuleEnforcement::validateCartItem()
 *
 * @param mixed $result  Verdict so far.
 * @param mixed $context Cart item context.
 * @return mixed True to allow, WP_Error to refuse.
 */
function fcbo_validate_cart_item_rules($result, $context)
{
    fcbo_load_cart();

    return \FluentCartBulkOrder\Cart\RuleEnforcement::validateCartItem($result, $context);
}

/**
 * The sentence explaining why a quantity was refused.
 *
 * @see \FluentCartBulkOrder\Cart\RuleEnforcement::describeQtyViolation()
 * @param int   $qty
 * @param array $rules
 * @return string
 */
function fcbo_describe_qty_violation($qty, $rules)
{
    fcbo_load_cart();

    return \FluentCartBulkOrder\Cart\RuleEnforcement::describeQtyViolation($qty, $rules);
}

/**
 * Refuse checkout when the order total is under this shopper's minimum.
 *
 * @see \FluentCartBulkOrder\Cart\RuleEnforcement::validateCheckoutMinimum()
 * @param mixed $errors  Errors so far.
 * @param mixed $context Checkout context, carrying the resolved cart.
 * @return mixed
 */
function fcbo_validate_checkout_minimum($errors, $context)
{
    fcbo_load_cart();

    return \FluentCartBulkOrder\Cart\RuleEnforcement::validateCheckoutMinimum($errors, $context);
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
            /* translators: %s: FluentCart order number. */
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
 * GET /saved-lists — the current user's saved orders.
 *
 * @see \FluentCartBulkOrder\Rest\SavedOrdersController::getSavedLists()
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response
 */
function fcbo_rest_get_saved_lists(\WP_REST_Request $request)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\SavedOrdersController::getSavedLists($request);
}

/**
 * GET /past-orders — the current user's recent paid orders.
 *
 * @see \FluentCartBulkOrder\Rest\SavedOrdersController::getPastOrders()
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response
 */
function fcbo_rest_get_past_orders(\WP_REST_Request $request)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\SavedOrdersController::getPastOrders($request);
}

/**
 * POST /saved-lists — create or replace one saved order.
 *
 * @see \FluentCartBulkOrder\Rest\SavedOrdersController::saveList()
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response|\WP_Error
 */
function fcbo_rest_save_list(\WP_REST_Request $request)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\SavedOrdersController::saveList($request);
}

/**
 * DELETE /saved-lists — remove one of the current user's saved orders.
 *
 * @see \FluentCartBulkOrder\Rest\SavedOrdersController::deleteSavedList()
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response|\WP_Error
 */
function fcbo_rest_delete_saved_list(\WP_REST_Request $request)
{
    fcbo_load_rest();

    return \FluentCartBulkOrder\Rest\SavedOrdersController::deleteSavedList($request);
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
