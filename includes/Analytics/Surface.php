<?php

namespace FluentCartBulkOrder\Analytics;

defined('ABSPATH') || exit;

/**
 * Which of the plugin's surfaces an order started on — and the honest limits of
 * knowing that at all.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS BEST-EFFORT AND SAYS SO
 * ---------------------------------------------------------------------------
 *
 * Both ordering surfaces put items in the cart by calling FluentCart's own
 * browser API — `window.fluentCartCart.addProduct()` — which posts to the
 * host's cart endpoint. No PHP of ours runs on that request with any knowledge
 * of the page the shopper was looking at, so there is no server-side moment at
 * which "this line came from the product table" is a fact we hold.
 *
 * What IS knowable is the handoff. The Bulk Order Form, the Saved Orders table
 * and the Bulk Pricing block's "Bulk order now" button all send the shopper to
 * a checkout URL that THIS PLUGIN builds in PHP, so that URL can carry a
 * marker, and a hidden field on the checkout form carries it into the order.
 * The Product Table has no such handoff: it adds to the cart and leaves the
 * shopper on the catalog page to check out however they like. There is nothing
 * to mark — and the same is true of every plain "add to cart" button this
 * plugin renders, including the one beside "Bulk order now".
 *
 * So the entry point is recorded when it is known and left empty when it is
 * not, and the screen prints "Entry point not recorded" for the empty case
 * rather than inventing a surface or quietly folding those orders into one.
 * @see \FluentCartBulkOrder\Analytics\AnalyticsScreen
 *
 * The MEASURE of bulk-order revenue does not depend on any of this. An order
 * counts as a bulk order because a Bulk Pricing Tier priced one of its lines —
 * a fact computed on the server, in the same request that charged the shopper.
 * The surface is extra colour on a number that stands without it.
 * @see \FluentCartBulkOrder\Analytics\OrderAttribution
 *
 * Pure: a closed list of slugs and their labels. `__()` is a passthrough in the
 * unit suite.
 */
class Surface
{
    /**
     * The query argument the marker travels in, and the name of the hidden
     * checkout field that carries it into the order.
     *
     * Deliberately one string for both, the same trade PoSettings makes: the
     * checkout form is posted as one FormData, so the field name IS what
     * arrives in the request array, and one name is one fewer thing to keep in
     * step.
     */
    const PARAM = 'fcbo_src';

    /**
     * The surfaces that can be marked.
     */
    const BULK_ORDER_FORM = 'bulk_order_form';
    const SAVED_ORDERS = 'saved_orders';

    /**
     * The Bulk Pricing block on a single product page, via its "Bulk order now"
     * button — which builds a checkout URL in this plugin's PHP exactly the way
     * the two surfaces above do, and so can be marked the same way.
     *
     * Its plain "Add to cart" button is NOT this surface and is not marked: it
     * leaves the shopper on the product page to check out however they like,
     * which is the Product Table's situation described above. There is nothing
     * to mark on that path, so those orders stay unattributed rather than being
     * credited to a button the shopper did not press.
     *
     * @see \FluentCartBulkOrder\Display\SingleProductTiers
     */
    const SINGLE_PRODUCT_TIERS = 'single_product_tiers';

    /**
     * Not a surface a shopper clicks: an order the owner created by converting
     * an accepted Quote Request. It never passes through checkout at all, so it
     * is stamped where it is made rather than by a marker.
     *
     * @see \FluentCartBulkOrder\Quotes\QuoteOrder
     */
    const QUOTE = 'quote';

    /**
     * Every recordable entry point.
     *
     * @return string[]
     */
    public static function keys()
    {
        return [self::BULK_ORDER_FORM, self::SAVED_ORDERS, self::SINGLE_PRODUCT_TIERS, self::QUOTE];
    }

    /**
     * Map any submitted value onto a known surface, or to ''.
     *
     * The empty string is a real, meaningful answer here — "this order was
     * attributed, but not to a named entry point" — so an unknown value
     * degrades to it rather than to a default surface. A marker a shopper
     * hand-edited into their URL must not be able to name a surface they were
     * never on.
     *
     * @param mixed $value Raw request value.
     * @return string One of self::keys(), or ''.
     */
    public static function sanitize($value)
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, self::keys(), true) ? $value : '';
    }

    /**
     * What an owner calls this entry point.
     *
     * The names match the shortcodes as CONCEPTS.md names them, because that is
     * what the owner sees in the settings page and in their page content. An
     * unknown or empty value gets the sentence that says so, which is a real
     * row on the screen and not a fallback for a bug.
     *
     * @param string $key
     * @return string
     */
    public static function label($key)
    {
        switch (self::sanitize($key)) {
            case self::BULK_ORDER_FORM:
                return __('Bulk Order Form', 'fluent-cart-bulk-order');
            case self::SAVED_ORDERS:
                return __('Saved Orders', 'fluent-cart-bulk-order');
            case self::SINGLE_PRODUCT_TIERS:
                return __('Bulk Pricing block', 'fluent-cart-bulk-order');
            case self::QUOTE:
                return __('Converted quote', 'fluent-cart-bulk-order');
            default:
                return __('Entry point not recorded', 'fluent-cart-bulk-order');
        }
    }

    /**
     * Put the marker on a checkout URL.
     *
     * Returns the URL unchanged when there is nothing to mark, so a store whose
     * checkout page cannot be resolved is not handed the string `?fcbo_src=…`
     * as its checkout link.
     *
     * @param string $url Checkout URL, possibly ''.
     * @param string $key One of self::keys().
     * @return string
     */
    public static function mark($url, $key)
    {
        $url = (string) $url;
        $key = self::sanitize($key);

        if ($url === '' || $key === '') {
            return $url;
        }

        return add_query_arg(self::PARAM, $key, $url);
    }
}
