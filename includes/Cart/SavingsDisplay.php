<?php

namespace FluentCartBulkOrder\Cart;

defined('ABSPATH') || exit;

/**
 * "You saved X" on a cart line.
 *
 * Presentation only — every number shown here comes from LinePricing, so the
 * figure the shopper reads is the one the cart charged rather than a second
 * calculation that could disagree with it.
 *
 * Two entry points because FluentCart offers two slots and which one a theme
 * fires depends on its cart template; both render the same markup, and the
 * inline style prints once per request.
 */
class SavingsDisplay
{
    /**
     * Print "You saved $X" under a discounted cart line.
     *
     * Bound to `fluent_cart/cart/line_item/after_total` — the checkout order
     * summary and instant checkout. @see the registration for the full hook map.
     *
     * @param array $eventInfo ['item' => array, 'cart' => object, ...]
     * @return void
     */
    public static function renderLineSaving($eventInfo)
    {
        $saving = LinePricing::lineSaving(isset($eventInfo['item']) ? (array) $eventInfo['item'] : []);

        if ($saving <= 0) {
            return;
        }

        self::printStyle();

        printf(
            '<span class="fcbo-cart-saving">%s</span>',
            esc_html(sprintf(
                /* translators: %s: money amount, e.g. $12.50 */
                __('You saved %s', 'fluent-cart-bulk-order'),
                fcbo_format_money($saving)
            ))
        );
    }

    /**
     * Same line, printed from the cart drawer's only hook.
     *
     * `line_meta` fires in two renderers. CartRenderer is the drawer, and it is the
     * one that needs this. CartItemRenderer fires it too, but that renderer also
     * fires `after_total`, which is a better spot (beside the price) and is already
     * covered — so its `line_meta` pass must stay silent or the saving prints twice
     * on the checkout summary.
     *
     * The two are told apart by the cart object: CartRenderer::getEventInfo() hard-
     * codes `'cart' => null` (CartRenderer.php:170), while every CartItemRenderer
     * that fires hooks is constructed with the cart (CartSummaryRender.php:95). If a
     * future FluentCart release starts passing a cart from the drawer, the drawer
     * simply stops showing the line — a cosmetic loss, not a duplicate or an error.
     *
     * @param array $eventInfo ['item' => array, 'cart' => object|null, ...]
     * @return void
     */
    public static function renderLineSavingMeta($eventInfo)
    {
        if (!empty($eventInfo['cart'])) {
            return;
        }

        self::renderLineSaving($eventInfo);
    }

    /**
     * Emit the one style rule the cart saving line needs, once per request.
     *
     * Deliberately inline rather than an enqueued stylesheet. FluentCart re-renders
     * these line items into AJAX fragments when a quantity changes, and a fragment
     * response carries no <head> and runs no enqueue pass — an enqueued file would
     * simply not be there. Travelling with the markup is the only delivery that
     * works on both the full page render and the fragment.
     *
     * @return void
     */
    public static function printStyle()
    {
        static $printed = false;

        if ($printed) {
            return;
        }
        $printed = true;

        echo '<style>.fcbo-cart-saving{display:block;font-size:12px;font-weight:600;color:#16a34a;}</style>';
    }
}
