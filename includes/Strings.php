<?php

namespace FluentCartBulkOrder;

defined('ABSPATH') || exit;

/**
 * Every shopper-facing sentence the JavaScript prints.
 *
 * ---------------------------------------------------------------------------
 * WHY WHOLE SENTENCES LIVE HERE AND NOT IN THE JS
 * ---------------------------------------------------------------------------
 *
 * A sentence is translated here, complete, and the JS only fills its
 * {placeholders}. Building one by concatenating fragments in JavaScript cannot
 * be translated even in principle: "Add 5 more" is not "Add" + n + "more" in
 * every language, because word order differs. So the rule is one __() call per
 * finished sentence, and the client does nothing but substitution.
 *
 * Each table is handed to its script through wp_localize_script(). A key used in
 * JS that is missing here renders as the key itself rather than "undefined",
 * which makes the mistake obvious in review instead of shipping to a shopper.
 */
class Strings
{
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
    public static function savings()
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
     * Same contract as self::savings(): the sentence is translated here,
     * whole, and the JS only fills the {placeholders}. Building a sentence by
     * concatenating fragments in JS cannot be translated — word order differs
     * between languages, so "Add 5 more" is not "Add" + qty + "more" everywhere.
     *
     * @return array<string,string>
     */
    public static function bulkOrder()
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

            // Request a quote. In this table rather than one of their own,
            // because the button is part of the bulk order form and its script
            // already receives exactly one i18n object.
            'quote_need_product' => __('Add at least one product before requesting a quote.', 'fluent-cart-bulk-order'),
            'quote_sending'      => __('Sending your quote request...', 'fluent-cart-bulk-order'),
            /* translators: {reference}: the quote's reference, e.g. "Quote #41". Keep {reference} as-is. */
            'quote_sent'         => __('Request sent as {reference}. The store will email you a price.', 'fluent-cart-bulk-order'),
            /* translators: {reference}: the quote's reference; {skipped}: how many products are no longer available. Keep both as-is. */
            'quote_sent_partial' => __('Request sent as {reference}, but {skipped} item(s) are no longer available and were left out.', 'fluent-cart-bulk-order'),
            'quote_failed'       => __('Your quote request could not be sent.', 'fluent-cart-bulk-order'),
            'quote_failed_retry' => __('Your quote request could not be sent. Please try again.', 'fluent-cart-bulk-order'),
        ];
    }

    /**
     * The three ways an Order Rule is summarised next to a quantity input.
     *
     * Their own table because THREE surfaces print them and none of them owns
     * the sentences: the product table (assets/js/product-table.js), the single
     * product page's rewrite of FluentCart's own quantity box
     * (assets/js/single-product-qty.js), and the Bulk Pricing block, which
     * renders its copy in PHP through OrderRules::describe().
     *
     * A shopper can meet all three on one page, so a second English wording of
     * the same rule would read as two different rules. One table, one wording.
     *
     * @see \FluentCartBulkOrder\Pricing\OrderRules::describe() the PHP formatter
     *      these templates are fed to; the JS surfaces mirror it.
     * @return array<string,string>
     */
    public static function orderRuleHints()
    {
        return [
            /* translators: {min}: minimum quantity; {step}: case-pack multiple. Keep both as-is. */
            'rule_min_and_step' => __('Min {min}, in {step}s', 'fluent-cart-bulk-order'),
            /* translators: {step}: case-pack multiple. Keep {step} as-is. */
            'rule_step'         => __('Sold in {step}s', 'fluent-cart-bulk-order'),
            /* translators: {min}: minimum quantity. Keep {min} as-is. */
            'rule_min'          => __('Min {min}', 'fluent-cart-bulk-order'),
        ];
    }

    /**
     * The two forms of the collapsed Bulk Pricing accordion's summary line.
     *
     * The one sentence a shopper reads before deciding whether to open the
     * block at all, so it is a whole sentence with a {percent} slot rather than
     * "save up to " + n + "%" — the same rule the JS tables above follow, for
     * the same reason: word order differs between languages.
     *
     * Their own table (rather than a pair of inline esc_html__() calls in the
     * renderer) because Tiers::describeBestDiscount() is a pure function that
     * takes its templates as an argument, the way OrderRules::describe() does.
     * That is what keeps the "which discount may name its number" rule unit
     * testable without WordPress.
     *
     * @see \FluentCartBulkOrder\Pricing\Tiers::describeBestDiscount()
     * @return array<string,string>
     */
    public static function bulkPricingSummary()
    {
        return [
            /* translators: {percent}: the largest percentage discount on offer, e.g. 20. Keep {percent} as-is. */
            'summary_percent' => __('Bulk pricing — save up to {percent}%', 'fluent-cart-bulk-order'),
            // Used when the tiers are money amounts rather than percentages, where
            // naming a number would mislabel the unit.
            'summary_generic' => __('Bulk pricing — buy more, pay less', 'fluent-cart-bulk-order'),
        ];
    }

    /**
     * Every shopper-facing sentence in assets/js/product-table.js.
     *
     * @see self::bulkOrder() for why whole sentences are translated
     *      here rather than assembled from fragments in JS.
     *
     * @return array<string,string>
     */
    public static function productTable()
    {
        // The rule hints are merged rather than repeated: product-table.js reads
        // them by the same keys the other two surfaces do, and one wording of a
        // rule is the whole point of self::orderRuleHints().
        return self::orderRuleHints() + [
            'loading'      => __('Loading products...', 'fluent-cart-bulk-order'),
            'load_failed'  => __('Failed to load products.', 'fluent-cart-bulk-order'),
            'no_products'  => __('No products found.', 'fluent-cart-bulk-order'),

            // Add-to-cart button, through its whole cycle
            'add_to_cart'  => __('Add to Cart', 'fluent-cart-bulk-order'),
            'out_of_stock' => __('Out of Stock', 'fluent-cart-bulk-order'),
            'adding'       => __('Adding...', 'fluent-cart-bulk-order'),
            'added'        => __('Added!', 'fluent-cart-bulk-order'),

            // Order rules, shown next to the quantity input.
            // rule_min / rule_step / rule_min_and_step arrive from
            // self::orderRuleHints() above.
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
     * @see self::bulkOrder() for why whole sentences are translated
     *      here rather than assembled from fragments in JS.
     *
     * @return array<string,string>
     */
    public static function savedOrders()
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
}
