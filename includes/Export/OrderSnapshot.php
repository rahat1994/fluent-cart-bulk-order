<?php

namespace FluentCartBulkOrder\Export;

use FluentCartBulkOrder\Checkout\PoSettings;

defined('ABSPATH') || exit;

/**
 * One FluentCart order, read once into a plain array.
 *
 * ---------------------------------------------------------------------------
 * WHY A SNAPSHOT RATHER THAN PASSING THE MODEL AROUND
 * ---------------------------------------------------------------------------
 *
 * Three things render an order in this feature — the CSV writer, the printable
 * receipt and the owner's list screen — and none of them should have to know
 * that the buyer's name lives two relations away, that the purchased variant is
 * `object_id` and not `post_id`, or that the SKU is on the variation rather
 * than on the line. Reading all of that once, here, is what keeps those rules
 * in ONE place and lets OrderCsv stay a pure function that the unit suite can
 * pin without a database.
 *
 * @see docs/solutions/architecture-patterns/fluentcart-order-model-user-orders-and-variants.md
 *      for the Order -> Customer -> WP user path and the object_id trap, both
 *      of which this class depends on and neither of which fails loudly.
 */
class OrderSnapshot
{
    /**
     * Load an order with everything this feature reads.
     *
     * Eager-loads in one go: the buyer (for the name, the email and the
     * ownership check) and the line items with their variations (for the SKU,
     * which is on the variation and not on the line).
     *
     * @param int $orderId
     * @return object|null A FluentCart Order model.
     */
    public static function find($orderId)
    {
        $orderId = (int) $orderId;

        if ($orderId < 1 || !class_exists(\FluentCart\App\Models\Order::class)) {
            return null;
        }

        $order = \FluentCart\App\Models\Order::query()
            ->with(['customer', 'order_items', 'order_items.variants'])
            ->find($orderId);

        return $order ?: null;
    }

    /**
     * The WordPress user an order belongs to, or 0.
     *
     * An Order carries no `user_id`: the link runs Order -> `customer_id` ->
     * Customer -> `user_id`. Getting that wrong does not throw, it silently
     * matches nobody — which on an ownership check would mean refusing every
     * buyer their own order.
     *
     * @param object|null $order
     * @return int
     */
    public static function ownerId($order)
    {
        if (!is_object($order) || empty($order->customer) || empty($order->customer->user_id)) {
            return 0;
        }

        return (int) $order->customer->user_id;
    }

    /**
     * Turn a loaded order into the array every renderer here consumes.
     *
     * @param object|null $order A FluentCart Order model.
     * @return array<string, mixed> @see \FluentCartBulkOrder\Export\OrderCsv::ORDER_DEFAULTS
     */
    public static function build($order)
    {
        if (!is_object($order)) {
            return OrderCsv::ORDER_DEFAULTS;
        }

        $customer = isset($order->customer) ? $order->customer : null;

        return array_merge(OrderCsv::ORDER_DEFAULTS, [
            'id'             => (int) $order->id,
            'number'         => self::reference($order),
            'date'           => self::date($order),
            'status'         => (string) (isset($order->status) ? $order->status : ''),
            'payment_status' => (string) (isset($order->payment_status) ? $order->payment_status : ''),
            'po_number'      => PoSettings::forOrder($order),
            'customer'       => $customer && isset($customer->full_name) ? (string) $customer->full_name : '',
            'email'          => $customer && isset($customer->email) ? (string) $customer->email : '',
            'currency'       => (string) (isset($order->currency) ? $order->currency : ''),
            'subtotal'       => (int) (isset($order->subtotal) ? $order->subtotal : 0),
            // Two separate columns on the orders table, and a buyer reconciling
            // a total does not care which of them a discount came from.
            'discount'       => (int) (isset($order->manual_discount_total) ? $order->manual_discount_total : 0)
                                + (int) (isset($order->coupon_discount_total) ? $order->coupon_discount_total : 0),
            'shipping'       => (int) (isset($order->shipping_total) ? $order->shipping_total : 0),
            'tax'            => (int) (isset($order->tax_total) ? $order->tax_total : 0),
            'total'          => (int) (isset($order->total_amount) ? $order->total_amount : 0),
            'lines'          => self::lines($order),
        ]);
    }

    /**
     * The reference a buyer recognises.
     *
     * The store's invoice number when it has one, and the database id when it
     * does not — an order placed before the store configured invoice numbering
     * still has to be identifiable on the paperwork.
     *
     * @param object $order
     * @return string
     */
    public static function reference($order)
    {
        $invoice = isset($order->invoice_no) ? trim((string) $order->invoice_no) : '';

        return $invoice !== '' ? $invoice : (string) (int) $order->id;
    }

    /**
     * The order date, as stored.
     *
     * Deliberately NOT converted through wp_date() or date_i18n(). FluentCart's
     * own order export writes `$order->created_at` verbatim (fluent-cart 1.5.5,
     * app/Hooks/Handlers/ExportHandler.php:110), so writing the same value here
     * means a store owner comparing our file with theirs sees the same
     * timestamps rather than two that differ by an offset nobody can explain.
     *
     * Trimmed to minutes because seconds on an order date are noise.
     *
     * @param object $order
     * @return string 'Y-m-d H:i', or '' when the column is empty.
     */
    public static function date($order)
    {
        $created = isset($order->created_at) ? trim((string) $order->created_at) : '';

        if ($created === '') {
            return '';
        }

        return substr($created, 0, 16);
    }

    /**
     * The order's line items, in this feature's own shape.
     *
     * @param object $order
     * @return array<int, array<string, mixed>>
     */
    private static function lines($order)
    {
        $items = isset($order->order_items) ? $order->order_items : [];
        $lines = [];

        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            $lines[] = array_merge(OrderCsv::LINE_DEFAULTS, [
                // The SKU is on the VARIATION, not on the line. A custom or
                // non-product line has no variation and simply has no SKU.
                'sku'             => isset($item->variants->sku) ? (string) $item->variants->sku : '',
                // `post_title` is the product and `title` is the variation —
                // the reverse of what the names suggest to most readers
                // (fluent-cart 1.5.5, app/Helpers/AdminOrderProcessor.php:127).
                'title'           => (string) (isset($item->post_title) ? $item->post_title : ''),
                'variation_title' => (string) (isset($item->title) ? $item->title : ''),
                'qty'             => (int) (isset($item->quantity) ? $item->quantity : 0),
                'unit_price'      => (int) (isset($item->unit_price) ? $item->unit_price : 0),
                'line_total'      => (int) (isset($item->line_total) ? $item->line_total : 0),
            ]);
        }

        return $lines;
    }
}
