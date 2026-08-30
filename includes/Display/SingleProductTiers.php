<?php

namespace FluentCartBulkOrder\Display;

use FluentCartBulkOrder\Analytics\Surface;
use FluentCartBulkOrder\Cart\SubscriptionRule;
use FluentCartBulkOrder\Pricing\OrderRules;
use FluentCartBulkOrder\Pricing\Tiers;
use FluentCartBulkOrder\Strings;

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
            // Where "Bulk order now" sends the shopper. '' when FluentCart
            // cannot name a checkout page — renderOrderTable() then omits the
            // button entirely rather than shipping a dead link.
            'checkout_url'  => esc_url_raw(self::checkoutUrl()),
            'i18n'          => fcbo_savings_strings(),
        ]);
    }

    /**
     * The marked checkout URL "Bulk order now" hands the shopper to.
     *
     * FluentCart's own Buy Now goes through `?fluent-cart=instant_checkout`
     * (fluent-cart/app/Http/Routes/WebRoutes.php:78-210), and that route is not
     * usable here: it takes ONE `item_id` and calls
     * `CartResource::generateCartForInstantCheckout()` (:129), which builds a
     * fresh single-line cart. A bulk order of four variants sent through it
     * would arrive at checkout as one line. So this surface does what the Bulk
     * Order Form does instead — add every line through FluentCart's own cart
     * API, then go to FluentCart's own checkout page — which is the same
     * destination the instant_checkout route redirects to anyway (:174).
     *
     * Marked so an order that reached checkout from this block can be told
     * apart on the analytics screen.
     *
     * @return string Checkout URL, or '' when FluentCart cannot supply one.
     */
    private static function checkoutUrl()
    {
        return Surface::mark(fcbo_checkout_page_url(), Surface::SINGLE_PRODUCT_TIERS);
    }

    /**
     * Render the order table rows for variants.
     *
     * Each row has: title, quantity input, price cell (updated by JS).
     * Footer row has the grand total, and under the table the Add to Cart /
     * Bulk order now pair.
     *
     * @param array  $variants [{id, title, price, tiers, order_rules}]
     * @param string $titleHeader Column header for the first column
     */
    public static function renderOrderTable($variants, $titleHeader)
    {
        $ruleTemplates = Strings::orderRuleHints();

        echo '<table class="fcbo-bp-order-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html($titleHeader) . '</th>';
        echo '<th>' . esc_html__('Quantity', 'fluent-cart-bulk-order') . '</th>';
        echo '<th>' . esc_html__('Total', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($variants as $v) {
            $rules = OrderRules::normalize(isset($v['order_rules']) ? $v['order_rules'] : []);

            $variantData = wp_json_encode([
                'id'          => (int) $v['id'],
                'price'       => (int) $v['price'],
                'tiers'       => $v['tiers'],
                // Carried on the row so bulk-pricing-display.js can correct a typed
                // quantity the same way every other ordering surface does. Without
                // it this widget was the one place a shopper could assemble an
                // order the server would refuse line by line.
                'order_rules' => $rules,
            ]);

            // min stays 0, NOT the rule minimum, and that is deliberate: on this
            // widget an empty row means "not ordering this variant", and a
            // case-pack rule has nothing to say about ordering none. The rule
            // lives in `step` plus the two data- attributes, and the JS applies it
            // only once the quantity is above zero.
            //
            // The two empty spans are filled by bulk-pricing-display.js as the
            // quantity changes: the nudge toward the next tier sits under the input
            // the shopper is typing in, the line saving under the price it changes.
            printf(
                '<tr data-fcbo-variant="%s"><td>%s</td>'
                    . '<td><input type="number" class="fcbo-bp-qty-input" value="0" min="0" step="%d"'
                    . ' data-min-qty="%d" data-step="%d" />',
                esc_attr($variantData),
                esc_html($v['title']),
                (int) $rules['step'],
                (int) $rules['min_qty'],
                (int) $rules['step']
            );

            $hint = OrderRules::describe($rules, $ruleTemplates);
            if ($hint !== '') {
                echo '<span class="fcbo-bp-qty-hint">' . esc_html($hint) . '</span>';
            }

            echo '<span class="fcbo-bp-nudge"></span></td>'
                . '<td class="fcbo-bp-price-cell"><span class="fcbo-bp-muted">&mdash;</span></td></tr>';
        }

        echo '</tbody><tfoot><tr>';
        echo '<td><strong>' . esc_html__('Total', 'fluent-cart-bulk-order') . '</strong></td>';
        echo '<td class="fcbo-bp-grand-saving"></td>';
        echo '<td class="fcbo-bp-grand-total"><span class="fcbo-bp-muted">&mdash;</span></td>';
        echo '</tr></tfoot></table>';

        // Two actions, matching what the bulk order form offers: stay here, or
        // go and pay. Both add exactly the same quantities — the only
        // difference is where the shopper lands afterwards, which is why they
        // share one JS handler keyed on data-fcbo-bp-action.
        //
        // Classes mirror .fcbo-btn / .fcbo-btn-primary / .fcbo-btn-secondary in
        // assets/css/bulk-order.css rather than reusing them: the two files
        // define their own palette variables on their own root element
        // (.fcbo-wrap vs .fcbo-bp-wrap), so a shared class would inherit
        // nothing here. Same shape, same hues, separate declaration.
        echo '<div class="fcbo-bp-actions">';
        printf(
            '<button type="button" class="fcbo-bp-btn fcbo-bp-btn-secondary" data-fcbo-bp-action="add">%s</button>',
            // "Add to Cart", capital C, because that is the exact string the
            // product table's button already uses (@see Strings::productTable()).
            // One wording of one action across the plugin's surfaces.
            esc_html__('Add to Cart', 'fluent-cart-bulk-order')
        );

        // Omitted, not disabled, when FluentCart cannot name a checkout page: a
        // button that cannot do its job is worse than no button, and the
        // shopper still has "Add to Cart" and the store's own checkout link.
        if (self::checkoutUrl() !== '') {
            printf(
                '<button type="button" class="fcbo-bp-btn fcbo-bp-btn-primary" data-fcbo-bp-action="checkout">%s</button>',
                esc_html__('Bulk order now', 'fluent-cart-bulk-order')
            );
        }
        echo '</div>';
    }

    /**
     * Open the Bulk Pricing accordion — wrapper, disclosure and summary line.
     *
     * ---------------------------------------------------------------------------
     * WHY <details>/<summary> RATHER THAN A BUTTON AND A HIDDEN PANEL
     * ---------------------------------------------------------------------------
     *
     * The bulk order form's Quick Order panel uses the button pattern
     * (includes/Shortcodes/BulkOrderForm.php:128), so this is a deliberate
     * departure and there are two reasons for it.
     *
     * 1. It stays open-able when the script does not run. The button pattern
     *    ships the panel with `hidden` and only JavaScript takes it off, so a
     *    blocked or errored bundle shuts this block permanently. Everything
     *    inside it that a shopper reads — the tier ranges and their discounts —
     *    is rendered here in PHP and is worth reading on its own, so it must not
     *    depend on a script to be reachable. `<details>` is opened by the
     *    browser itself.
     *
     * 2. It is the a11y bar of plan 005 met with less to get wrong. That plan
     *    hand-wrote `aria-expanded`/`aria-activedescendant` for the product
     *    search because no native element is a combobox. A disclosure IS a
     *    native element: `<summary>` is focusable, operated by Enter and Space,
     *    and exposed as an expandable to a screen reader with no attributes and
     *    no JavaScript to keep in sync. Re-implementing that by hand would only
     *    add a state that can drift from the DOM.
     *
     * Collapsed by default is simply the absence of `open`, which is server
     * rendered — so there is no moment where the block is expanded before a
     * script gets round to closing it.
     *
     * @param array $tiers Every tier this block will show, across all variants —
     *                     what the summary line's claim has to be true of.
     * @return void
     */
    private static function openAccordion($tiers)
    {
        printf(
            '<div class="fcbo-bp-wrap"><details class="fcbo-bp-accordion">'
                . '<summary class="fcbo-bp-summary">'
                // The caret is decoration: <summary> already announces its own
                // expanded state, and a screen reader reading "▸" on top of that
                // would be noise.
                . '<span class="fcbo-bp-caret" aria-hidden="true">&#9656;</span>'
                . '<span class="fcbo-bp-summary-text">%s</span>'
                . '</summary><div class="fcbo-bp-panel">',
            esc_html(Tiers::describeBestDiscount($tiers, Strings::bulkPricingSummary()))
        );
    }

    /**
     * Close what self::openAccordion() opened.
     *
     * @return void
     */
    private static function closeAccordion()
    {
        echo '</div></details></div>';
    }

    /**
     * Render bulk pricing tiers on the single product page.
     *
     * The whole block is a closed accordion: a summary line naming the best
     * discount on offer, and behind it the tier info, an order table with
     * quantity inputs and live totals, and the Add to Cart / Bulk order now
     * pair.
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

            // -----------------------------------------------------------------
            // SUBSCRIPTION VARIANTS GET NO BLOCK AT ALL — NOT EVEN A READ-ONLY ONE
            // -----------------------------------------------------------------
            //
            // The other option considered was showing the tier table as pure
            // information with the quantity input removed. It was rejected,
            // because the information would not be true.
            //
            // A tier says "buy 10, save 20%". A shopper cannot buy 10 of a
            // subscription: FluentCart overwrites the quantity with 1
            // (fluent-cart/api/Resource/FrontendResource/CartResource.php:63-65)
            // and refuses the purchase outright above 1
            // (fluent-cart/app/Models/ProductVariation.php:249). And even at a
            // min_qty of 1 the discount would not arrive — FluentCart builds
            // subscription plans through `fluent_cart/cart/item_modify` in
            // ProductItemService::getItem()
            // (fluent-cart/app/Services/ProductItemService.php:68), which never
            // reaches the `fluent_cart/cart/item_price` filter this plugin
            // prices on. @see \FluentCartBulkOrder\Cart\LinePricing
            //
            // So a tier table here would advertise a quantity the store refuses
            // at a price it will never charge. An empty space is a smaller lie.
            // The owner is told the same thing where they configure the tiers
            // (@see \FluentCartBulkOrder\Settings) and in readme.txt, so the
            // absence is documented rather than mysterious. Issue #34.
            if (SubscriptionRule::isRecurring($variant->payment_type)) {
                return;
            }

            $tiers = fcbo_resolve_tiers($pricingData, $product->ID, $variant->id, $userRoles);
            if (empty($tiers)) {
                return;
            }

            self::enqueueAssets();

            self::openAccordion($tiers);
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
                    // Resolved through the same feed match as the tiers, so the
                    // feed that prices this variant is the feed that constrains it.
                    'order_rules' => fcbo_resolve_order_rules($pricingData, $product->ID, $variant->id),
                ],
            ], __('Product', 'fluent-cart-bulk-order'));

            self::closeAccordion();
            return;
        }

        // Variable product: collect variants that have tiers
        $variantsWithTiers = [];
        foreach ($product->variants as $variant) {
            // Per VARIANT, not per product, and that is the whole reason this is
            // a skip rather than an early return: one product can sell a
            // one-time pack and a monthly plan side by side. Dropping the
            // recurring variant leaves the block standing for the variants it is
            // true of, and an all-subscription product falls out of the
            // `empty($variantsWithTiers)` check below with no block at all —
            // which is the same answer the simple branch gives, reached the same
            // way. @see the block comment in the simple branch for why nothing
            // is shown rather than a read-only tier table.
            if (SubscriptionRule::isRecurring($variant->payment_type)) {
                continue;
            }

            $tiers = fcbo_resolve_tiers($pricingData, $product->ID, $variant->id, $userRoles);
            if (empty($tiers)) {
                continue;
            }
            $variantsWithTiers[] = [
                'id'    => $variant->id,
                'title' => $variant->variation_title ?: 'Default',
                'price' => (int) $variant->item_price,
                'tiers' => $tiers,
                // Per-variant, not per-product: two variants of the same product
                // can be sold in different case sizes.
                'order_rules' => fcbo_resolve_order_rules($pricingData, $product->ID, $variant->id),
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

        // Every variant's tiers in one flat list. The summary line claims a
        // ceiling ("save up to 20%") over the WHOLE block, so it has to see
        // every tier the block will show — reading only the first variant's set
        // would understate a better discount sitting two rows down.
        $allTiers = [];
        foreach ($variantsWithTiers as $entry) {
            foreach ($entry['tiers'] as $tier) {
                $allTiers[] = $tier;
            }
        }

        self::openAccordion($allTiers);

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

        self::closeAccordion();
    }
}
