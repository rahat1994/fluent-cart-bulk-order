<?php

namespace FluentCartBulkOrder\Rest;

defined('ABSPATH') || exit;

/**
 * The fcbo/v1 route table.
 *
 * Declaration only — every callback lives on a controller in this namespace, so
 * this class stays a map of "which URL reaches which handler" that can be read
 * end to end without scrolling past any logic.
 *
 * Each route's permission_callback is AccessPolicy's Gate 1 check, reached
 * through fcbo_rest_permission_check(). There is deliberately no route without
 * one: an FCBO endpoint answers wholesale prices, so a missing check is a
 * catalogue leak, not a convenience. @see docs/solutions/security-issues/
 */
class Routes
{
    public static function register()
    {
        register_rest_route('fcbo/v1', '/products', [
            'methods'             => 'GET',
            'callback'            => [ProductsController::class, 'searchProducts'],
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
            'callback'            => [ProductsController::class, 'listCatalog'],
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
            'callback'            => [ProductsController::class, 'resolveSkus'],
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
                'callback'            => [SavedOrdersController::class, 'getSavedLists'],
                'permission_callback' => 'fcbo_rest_permission_check',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [SavedOrdersController::class, 'saveList'],
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
                'callback'            => [SavedOrdersController::class, 'deleteSavedList'],
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
            'callback'            => [SavedOrdersController::class, 'getPastOrders'],
            'permission_callback' => 'fcbo_rest_permission_check',
        ]);

        // Request a quote instead of checking out. Write-only from the buyer's
        // side: there is deliberately no GET here, because a quote is read on
        // the capability-checked admin screen and delivered to the buyer by
        // email — an endpoint that returned one would be a second place a
        // buyer's negotiated prices could leak from.
        //
        // `items` takes the same {variantId, qty} array the /saved-lists route
        // does, so the form sends one shape for both. Any price the browser
        // includes is ignored; the server prices the lines itself.
        // @see \FluentCartBulkOrder\Quotes\QuoteInput
        register_rest_route('fcbo/v1', '/quotes', [
            'methods'             => 'POST',
            'callback'            => [QuotesController::class, 'requestQuote'],
            'permission_callback' => 'fcbo_rest_permission_check',
            'args'                => [
                'items' => [
                    'required' => true,
                    'type'     => 'array',
                ],
                'note'  => [
                    'default'           => '',
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);
    }
}
