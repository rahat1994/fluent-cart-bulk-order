<?php

namespace FluentCartBulkOrder\Shortcodes;

use FluentCartBulkOrder\AccessPolicy;

defined('ABSPATH') || exit;

/**
 * `[fluent_cart_bulk_order]` — the bulk order form.
 *
 * An empty table that assets/js/bulk-order.js fills in: rows are added by SKU
 * autocomplete or by the quick-order paste/CSV panel, quantities are typed, and
 * the running total is computed client-side before handing off to checkout.
 *
 * Attributes:
 *   roles     Extra role slugs allowed to see this placement (widens Gate 1).
 *   redirect  Same-site URL to send the shopper to instead of the store
 *             checkout page.
 *
 * @see \FluentCartBulkOrder\Shortcodes\AbstractShortcode For the gate order.
 */
class BulkOrderForm extends AbstractShortcode
{
    /**
     * @inheritDoc
     */
    protected function defaults()
    {
        return [
            'roles'    => '',
            'redirect' => '',
        ];
    }

    /**
     * @inheritDoc
     */
    protected function loginNotice()
    {
        return __('Please log in to access the bulk order form.', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    protected function accessDeniedNotice()
    {
        return __('You do not have permission to access the bulk order form.', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    protected function output(array $atts)
    {
        // FluentCart's cart bundle first — the checkout button hands off to
        // window.fluentCartCart, which it provides.
        $this->loadFluentCartCartAssets();

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

        $currency_sign = $this->currencySign();

        wp_localize_script('fcbo-bulk-order', 'fcboConfig', array_merge($this->restConfig(), [
            'checkout_url'  => esc_url_raw($this->resolveCheckoutUrl($atts['redirect'])),
            'currency_sign' => $currency_sign,
            // Order-total floor for this shopper. Sent as 0 when they are not
            // subject, so the client never has to reason about role policy.
            'min_order_total' => AccessPolicy::userSubjectToMinOrder()
                ? AccessPolicy::minOrderTotal()
                : 0,
            // Every shopper-facing sentence, translated server-side. The JS only
            // fills {placeholders}. @see fcbo_savings_strings(),
            // @see fcbo_bulk_order_strings()
            'i18n'            => array_merge(fcbo_savings_strings(), fcbo_bulk_order_strings()),
        ]));

        ob_start();
        ?>
        <div id="fcbo-bulk-order" class="fcbo-wrap">
            <?php echo $this->pricingPolicyNotice(); ?>

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
                            <!-- The grand saving rides in the spanned cell so it lands
                                 beside the total without the total cell having to hold
                                 two values. Filled by bulk-order.js. -->
                            <td colspan="7" class="fcbo-grand-saving-cell"><span id="fcbo-grand-saving" class="fcbo-grand-saving"></span></td>
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

    /**
     * Tell a store owner why this form is quoting retail prices.
     *
     * Only for users who can reach the settings — it is a diagnostic, not a
     * shopper message. A retail customer who does not qualify for bulk pricing
     * should simply see retail prices, not be told about wholesale rates they
     * cannot have.
     *
     * It exists because of a deliberate asymmetry: administrators may PREVIEW
     * tiers but are never charged them (@see AccessPolicy, Gate 2). Before this
     * notice, an admin testing the form saw discounted totals here and full
     * prices at checkout, which reads as a broken plugin rather than as the
     * policy working. Now the form quotes the real price and says why.
     *
     * @return string Markup, or '' when there is nothing to explain.
     */
    private function pricingPolicyNotice()
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        if (AccessPolicy::userQualifiesForBulkPricing(null, 'cart')) {
            return '';
        }

        $link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(AccessPolicy::settingsPageUrl()),
            esc_html__('Settings → Bulk Pricing', 'fluent-cart-bulk-order')
        );

        return '<div class="fcbo-policy-notice">'
            . esc_html__('Bulk pricing does not apply to your account, so this form is showing retail prices — the same prices you would be charged at checkout.', 'fluent-cart-bulk-order')
            . ' ' . sprintf(
                /* translators: %s: link to the Bulk Pricing settings page. */
                esc_html__('Add your role under %s to see and receive the discounts.', 'fluent-cart-bulk-order'),
                $link
            )
            . '</div>';
    }

    /**
     * Where the checkout button sends the shopper.
     *
     * The store checkout page, unless the `redirect` attribute supplies a valid
     * SAME-SITE URL. wp_validate_redirect() returns the fallback ('') for
     * off-site or malformed values, so a bad attribute degrades to the store
     * page instead of becoming an open redirect.
     *
     * @param string $redirect Raw `redirect` attribute value.
     * @return string URL, or '' when neither source can supply one.
     */
    private function resolveCheckoutUrl($redirect)
    {
        $redirect = trim((string) $redirect);

        if ($redirect !== '') {
            $validated = wp_validate_redirect($redirect, '');
            if ($validated !== '') {
                return $validated;
            }
        }

        return $this->checkoutPageUrl();
    }
}
