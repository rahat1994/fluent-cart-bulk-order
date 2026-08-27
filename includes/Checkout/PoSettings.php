<?php

namespace FluentCartBulkOrder\Checkout;

use FluentCartBulkOrder\AccessPolicy;
use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * The owner-facing settings behind the PO number field, read in one place —
 * and the one place that knows where a stored PO number lives.
 *
 * Every value lives in the single `fcbo_store_defaults` option alongside the
 * rest of the plugin's non-gate settings, and is validated by
 * StoreDefaults::sanitize(). This class is the READ side only, so the checkout
 * field, its server backstop, the receipt, the export and the admin screen all
 * ask the same question the same way.
 *
 * @see \FluentCartBulkOrder\Quotes\QuoteSettings The same idea for quotes.
 * @see \FluentCartBulkOrder\Checkout\PoNumber The rules; this is the storage.
 */
class PoSettings
{
    /**
     * The key the PO number is stored under, in FluentCart's own order meta
     * table (`fct_order_meta`, through `Order::updateMeta()`).
     *
     * Prefixed with the plugin's own namespace because that table is shared
     * with the host and with every other extension — FluentCart itself stores
     * `business_info` and `store_business_info` there, unprefixed.
     */
    const META_KEY = 'fcbo_po_number';

    /**
     * The `name` of the input on the checkout form.
     *
     * Deliberately the same string as the meta key. FluentCart posts the whole
     * checkout form as one FormData, so this name is what arrives in the
     * request array, and one name for one value is one fewer thing to keep in
     * step.
     */
    const FIELD_NAME = 'fcbo_po_number';

    /**
     * The configured mode.
     *
     * Always passed through PoNumber::sanitizeMode(), so a stored value that
     * has been corrupted or hand-edited reads as OFF rather than as REQUIRED.
     * A store must not be stopped from selling by a bad row.
     *
     * @return string One of PoNumber::modes().
     */
    public static function mode()
    {
        return PoNumber::sanitizeMode(
            StoreDefaults::get('po_mode', StoreDefaults::FALLBACKS['po_mode'])
        );
    }

    /**
     * Whether a given shopper is shown the field at all.
     *
     * Two independent questions ANDed: is the feature on (the mode), and does
     * it bind this shopper (Gate 4). Neither is sufficient alone, and keeping
     * them apart is what lets an owner ask wholesale buyers for a PO number
     * without asking retail ones.
     *
     * @param \WP_User|null $user Defaults to the current user.
     * @return bool
     */
    public static function appliesTo($user = null)
    {
        return PoNumber::isOn(self::mode()) && AccessPolicy::userSubjectToPoNumber($user);
    }

    /**
     * Whether a given shopper's checkout must be refused without one.
     *
     * @param \WP_User|null $user Defaults to the current user.
     * @return bool
     */
    public static function requiredFor($user = null)
    {
        return PoNumber::isRequired(self::mode()) && AccessPolicy::userSubjectToPoNumber($user);
    }

    /**
     * What the field is called on the form, the receipt and the export.
     *
     * Filterable, because "PO number" is the common name for it and not the
     * only one — plenty of purchasing departments call it a requisition or a
     * reference, and a store that does should not have to translate its own
     * language into ours.
     *
     * @return string
     */
    public static function label()
    {
        return (string) apply_filters(
            'fcbo/po_number_label',
            __('PO number', 'fluent-cart-bulk-order')
        );
    }

    /**
     * The PO number stored against a FluentCart order.
     *
     * ---------------------------------------------------------------------
     * WHY THIS IS NOT `return $order->getMeta(self::META_KEY, '')`
     * ---------------------------------------------------------------------
     *
     * FluentCart's OrderMeta model JSON-DECODES every string on the way out
     * (fluent-cart 1.5.5, app/Models/OrderMeta.php:38 —
     * `$decoded = json_decode($value, true); return $decoded ?: $value;`).
     * That is there so an array stored as meta comes back as an array, and it
     * has a consequence nobody storing a plain string expects:
     *
     *   "4711"      comes back as the INTEGER 4711
     *   "true"      comes back as the BOOLEAN true
     *   "[1,2]"     comes back as an ARRAY
     *
     * A numeric PO number is the ordinary case, not an edge case, so a caller
     * that assumed a string would be handing an integer to esc_html(), to
     * str_* functions, and into a CSV cell. None of that throws; it just
     * behaves oddly in one place and not another.
     *
     * So every read goes through here, and here casts. Anything that did not
     * come back as a scalar was never a PO number this plugin wrote.
     *
     * @param object|null $order A FluentCart Order model.
     * @return string Possibly empty.
     */
    public static function forOrder($order)
    {
        if (!is_object($order) || !method_exists($order, 'getMeta')) {
            return '';
        }

        $value = $order->getMeta(self::META_KEY, '');

        return is_scalar($value) ? (string) $value : '';
    }
}
