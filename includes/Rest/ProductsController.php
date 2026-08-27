<?php

namespace FluentCartBulkOrder\Rest;

defined('ABSPATH') || exit;

/**
 * The catalogue-reading endpoints: /products, /catalog, /resolve-skus.
 *
 * All three answer with the SAME variant payload shape (buildVariantPayload()),
 * because the browser reuses one selectProduct() path for a search pick, a table
 * row and a pasted SKU. Changing the shape for one endpoint silently breaks the
 * other two.
 *
 * Tier data in those payloads is already resolved against the caller's roles
 * before it is serialized, so no role logic is shipped to the browser and a
 * shopper never receives a price list that is not theirs.
 */
class ProductsController
{
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
    public static function sanitizeSkusParam($value)
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
    public static function buildCategoryList($productId)
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
     * @param array         $pricingData From FeedResolver::allBulkPricing().
     * @param string[]|null $userRoles   Viewer's role slugs, so bulk_tiers is
     *                                   role-resolved server-side (the client never
     *                                   sees other roles' pricing). null = default set.
     * @return array
     */
    public static function buildVariantPayload($product, $variant, $pricingData, $userRoles = null)
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

    public static function searchProducts(\WP_REST_Request $request)
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
            $catList = self::buildCategoryList($product->ID);

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

                    $variants[] = self::buildVariantPayload($product, $variant, $pricingData, $userRoles);
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
    public static function resolveSkus(\WP_REST_Request $request)
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

            $catList = self::buildCategoryList($product->ID);

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
                    'variant'    => self::buildVariantPayload($product, $variant, $pricingData, $userRoles),
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

    public static function listCatalog(\WP_REST_Request $request)
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
}
