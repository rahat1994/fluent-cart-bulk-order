<?php

namespace FluentCartBulkOrder\Shortcodes;

defined('ABSPATH') || exit;

/**
 * `[fluent_cart_saved_orders]` — the current user's saved orders.
 *
 * Renders as an accordion: one summary row per saved order (name, created date,
 * item count, total) that expands to reveal its line items, with Reorder and
 * Delete actions. The rows come from the /saved-lists REST route, which is
 * owner-scoped — this surface never takes a user id from the page.
 *
 * Attributes:
 *   roles  Extra role slugs allowed to see this placement (widens Gate 1).
 *
 * @see \FluentCartBulkOrder\Shortcodes\AbstractShortcode For the gate order.
 */
class SavedOrders extends AbstractShortcode
{
    /**
     * @inheritDoc
     */
    protected function defaults()
    {
        return [
            'roles' => '',
        ];
    }

    /**
     * @inheritDoc
     */
    protected function loginNotice()
    {
        return __('Please log in to view your saved orders.', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    protected function accessDeniedNotice()
    {
        return __('You do not have permission to view saved orders.', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    protected function output(array $atts)
    {
        // Cart assets are needed so Reorder can add items via window.fluentCartCart.
        $this->loadFluentCartCartAssets();

        // Reuse the product table's table + accordion styles, then layer on
        // saved-order specifics. The dependency is declared on the handle below,
        // so load order holds even if the product table is also on the page.
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

        wp_localize_script('fcbo-saved-orders', 'fcboSoConfig', array_merge($this->restConfig(), [
            'currency_sign' => $this->currencySign(),
            'checkout_url'  => esc_url_raw($this->checkoutPageUrl()),
            // Shopper-facing sentences, translated server-side. The JS only
            // fills {placeholders}. @see fcbo_saved_orders_strings()
            'i18n'          => fcbo_saved_orders_strings(),
        ]));

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
}
