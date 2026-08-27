<?php

namespace FluentCartBulkOrder\Display;

defined('ABSPATH') || exit;

/**
 * The bulk-pricing tier table shown on a single product page.
 *
 * Rendered after FluentCart's quantity block, this is where a shopper first
 * learns that ordering more costs less per unit.
 *
 * Visibility is Gate 2 in the 'display' context, which is deliberately NOT the
 * same as the 'cart' context: an administrator may preview the tiers here but
 * is not given the discount at checkout, because an admin's real order has to
 * reflect the real policy. @see \FluentCartBulkOrder\AccessPolicy
 */
class SingleProductTiers
{
    /**
     * Enqueue CSS and JS for the bulk pricing display.
     */
    public static function enqueueAssets()
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
    public static function renderOrderTable($variants, $titleHeader)
    {
        echo '<table class="fcbo-bp-order-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html($titleHeader) . '</th>';
        echo '<th>' . esc_html__('Quantity', 'fluent-cart-bulk-order') . '</th>';
        echo '<th>' . esc_html__('Total', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($variants as $v) {
            $variantData = wp_json_encode([
                'id'    => (int) $v['id'],
                'price' => (int) $v['price'],
                'tiers' => $v['tiers'],
            ]);

            // The two empty spans are filled by bulk-pricing-display.js as the
            // quantity changes: the nudge toward the next tier sits under the input
            // the shopper is typing in, the line saving under the price it changes.
            printf(
                '<tr data-fcbo-variant="%s"><td>%s</td><td><input type="number" class="fcbo-bp-qty-input" value="0" min="0" /><span class="fcbo-bp-nudge"></span></td><td class="fcbo-bp-price-cell"><span class="fcbo-bp-muted">&mdash;</span></td></tr>',
                esc_attr($variantData),
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
    public static function render($args)
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

            self::enqueueAssets();

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
                    /* translators: %s: quantity range the tier covers, e.g. "10 – 24" or "25+". */
                    '<li>' . esc_html__('Buy %s:', 'fluent-cart-bulk-order') . ' <span class="fcbo-bp-discount">%s</span></li>',
                    esc_html($range),
                    esc_html(fcbo_format_tier_discount_label($tier))
                );
            }
            echo '</ul></div>';

            self::renderOrderTable([
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

        self::enqueueAssets();

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

        self::renderOrderTable($variantsWithTiers, __('Variant', 'fluent-cart-bulk-order'));

        echo '</div>';
    }
}
