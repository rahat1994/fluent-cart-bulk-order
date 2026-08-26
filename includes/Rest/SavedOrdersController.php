<?php

namespace FluentCartBulkOrder\Rest;

defined('ABSPATH') || exit;

/**
 * The saved-order endpoints: list, create-or-replace, delete, and past orders.
 *
 * Every one of these is owner-scoped inside the handler and NO user id is ever
 * accepted from the request — a saved order belongs to exactly one user, and
 * passing an id in would be the obvious way to read someone else's. The Gate 1
 * permission check says "may this person use the surfaces at all"; the ownership
 * scoping below is what says "and only their own data".
 *
 * The handlers are thin on purpose: the storage and re-pricing logic lives in
 * the fcbo_* helpers in the main plugin file, which the shortcodes also use.
 */
class SavedOrdersController
{
    /**
     * REST: GET the current user's saved orders (resolved).
     */
    public static function getSavedLists(\WP_REST_Request $request)
    {
        return new \WP_REST_Response(['lists' => fcbo_build_saved_orders_response()], 200);
    }

    /**
     * REST: GET the current user's recent past orders (resolved).
     */
    public static function getPastOrders(\WP_REST_Request $request)
    {
        return new \WP_REST_Response(['orders' => fcbo_build_past_orders_response()], 200);
    }

    /**
     * REST: POST — create or replace a saved order for the current user.
     */
    public static function saveList(\WP_REST_Request $request)
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
    public static function deleteSavedList(\WP_REST_Request $request)
    {
        fcbo_delete_saved_list($request->get_param('name'));

        return new \WP_REST_Response(['lists' => fcbo_build_saved_orders_response()], 200);
    }
}
