<?php

namespace FluentCartBulkOrder\Quotes;

defined('ABSPATH') || exit;

/**
 * Turning an accepted quote into a real FluentCart order.
 *
 * ---------------------------------------------------------------------------
 * THE HOST CREATES THE ORDER, NOT US
 * ---------------------------------------------------------------------------
 *
 * There is exactly one line in this file that writes an order, and it hands the
 * whole job to FluentCart:
 *
 *     \FluentCart\Api\Resource\OrderResource::updatedPlaceOrder($data)
 *
 * That is the SAME function FluentCart's own "create order" admin screen calls
 * — `FluentCart\App\Http\Controllers\OrderController::store()` does nothing else
 * with the request but sanitise it and pass it there (fluent-cart 1.5.5,
 * app/Http/Controllers/OrderController.php:101). Everything a manual order
 * needs happens inside it: the draft order and its items, the pending
 * transaction, the order addresses, tax, and the offline-payment gateway hand-off.
 *
 * Rebuilding any of that here — inserting into `fct_orders` and `fct_order_items`
 * by hand, computing `subtotal`/`total_amount`, inventing a transaction row —
 * would be an extension guessing at a host's invariants, and it would rot on the
 * host's next release. A store owner would find out through an order that looks
 * right in the list and is wrong in the reports.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE ORDER LOOKS LIKE, AND WHY
 * ---------------------------------------------------------------------------
 *
 * An order created this way lands as FluentCart's ordinary manual order:
 * status `on-hold`, payment method `offline_payment`, with a PENDING
 * transaction for the full amount. It is deliberately NOT marked paid. The
 * whole point of a quote is that the buyer has not paid yet — the owner marks
 * it paid from FluentCart's own order screen when the money arrives, exactly as
 * they would for any other offline order.
 *
 * ---------------------------------------------------------------------------
 * SUBSCRIPTIONS ARE REFUSED HERE, ON PURPOSE
 * ---------------------------------------------------------------------------
 *
 * FluentCart does not support a subscription line in a manual order — its own
 * controller returns an error for one unless a site opts in through
 * `fluent_cart/order/is_subscription_allowed_in_manual_order`
 * (OrderController.php:86-96). This class asks the SAME filter before it tries,
 * so the owner gets a sentence explaining why instead of a failed conversion.
 *
 * That is a narrower limit than the quote REQUEST has, and that asymmetry is
 * the roadmap's point: the bulk order form refuses to send a mixed
 * subscription/one-time basket to checkout at all, so before quotes a buyer
 * with one of each had nowhere to go. They can now ask for the whole thing in
 * one request and the owner can answer it — the owner just has to place the
 * subscription part through FluentCart rather than through this button.
 */
class QuoteOrder
{
    /**
     * Create a FluentCart order from a quote's stored lines.
     *
     * Does NOT change the quote's status and does NOT check any capability.
     * The caller does both — @see QuoteReviewScreen::handleDecision(), which
     * claims the transition through QuoteStore::decide() and only then asks for
     * an order, so a failed creation leaves the quote where it was rather than
     * marking it accepted with nothing behind it.
     *
     * @param array<string, mixed> $record A record from QuoteStore::get().
     * @return int|\WP_Error The new FluentCart order id.
     */
    public static function create(array $record)
    {
        if (!class_exists(\FluentCart\Api\Resource\OrderResource::class)) {
            return new \WP_Error(
                'fcbo_quote_no_fluentcart',
                __('FluentCart is not available, so no order could be created.', 'fluent-cart-bulk-order')
            );
        }

        $lines = isset($record['lines']) && is_array($record['lines']) ? $record['lines'] : [];

        if (!$lines) {
            return new \WP_Error(
                'fcbo_quote_no_lines',
                __('This quote has no items, so there is nothing to order.', 'fluent-cart-bulk-order')
            );
        }

        if (QuoteInput::hasSubscription($lines) && !self::subscriptionsAllowed($lines)) {
            return new \WP_Error(
                'fcbo_quote_subscription',
                __('FluentCart cannot put a subscription product into a manually created order. Place the subscription part through FluentCart, then convert the rest of this quote.', 'fluent-cart-bulk-order')
            );
        }

        $customerId = self::customerIdFor(isset($record['user_id']) ? (int) $record['user_id'] : 0);

        if (is_wp_error($customerId)) {
            return $customerId;
        }

        $items = self::orderItems($lines);

        if (!$items) {
            return new \WP_Error(
                'fcbo_quote_no_items',
                __('None of the items on this quote could be matched to a product, so no order was created.', 'fluent-cart-bulk-order')
            );
        }

        // FluentCart's own validator throws — rather than returning — for an
        // unavailable or out-of-stock product, and it does so OUTSIDE
        // updatedPlaceOrder()'s try/catch. A catalogue that changed between the
        // quote and the conversion is an ordinary thing, not a fatal.
        try {
            $order = \FluentCart\Api\Resource\OrderResource::updatedPlaceOrder([
                'customer_id' => $customerId,
                'order_items' => $items,
                // FluentCart's controller sets this from the item payment types
                // and passes it through; AdminOrderProcessor recomputes the
                // authoritative value from the same items, so this is a label.
                'type'        => 'payment',
                // REQUIRED, even though it is zero and even though the host's
                // signature makes it look optional. updatedPlaceOrder() forwards
                // `Arr::get($data, 'shipping_total', [])` — an ARRAY default —
                // into AdminOrderProcessor, which then adds it to an integer
                // subtotal (fluent-cart 1.5.5,
                // app/Helpers/AdminOrderProcessor.php:282). Omitting it is a
                // fatal TypeError, not a zero. FluentCart's own admin screen
                // always sends a number, so the host never hits it.
                //
                // Shipping on a quote is out of scope: this plugin has no way to
                // ask the buyer for an address, and FluentCart's shipping rates
                // need one. An owner who charges for delivery adds it to the
                // order in FluentCart before taking payment.
                'shipping_total' => 0,
            ]);
        } catch (\Exception $e) {
            return new \WP_Error(
                'fcbo_quote_order_failed',
                sprintf(
                    /* translators: %s: the error FluentCart reported. */
                    __('FluentCart could not create the order: %s', 'fluent-cart-bulk-order'),
                    $e->getMessage()
                )
            );
        }

        if (is_wp_error($order)) {
            return $order;
        }

        if (!is_object($order) || empty($order->id)) {
            return new \WP_Error(
                'fcbo_quote_order_failed',
                __('FluentCart did not return an order. Nothing was charged; please try again.', 'fluent-cart-bulk-order')
            );
        }

        return (int) $order->id;
    }

    /**
     * The admin URL of a FluentCart order.
     *
     * FluentCart's admin is a single-page app behind `admin.php?page=fluent-cart`
     * with a hash route, which is how its own menu links to orders
     * (fluent-cart 1.5.5, app/Hooks/Handlers/MenuHandler.php:246-252). Built by
     * hand rather than read from the host, because there is no accessor for it
     * and a missing one must not stop a quote being converted.
     *
     * @param int $orderId
     * @return string
     */
    public static function adminUrl($orderId)
    {
        $orderId = (int) $orderId;

        if ($orderId <= 0) {
            return '';
        }

        return admin_url('admin.php?page=fluent-cart#/orders/' . $orderId);
    }

    /**
     * Whether this site has opted in to subscriptions in manual orders.
     *
     * The same filter FluentCart's own controller consults, with the same
     * default (false) and the same argument shape, so a site that has already
     * enabled manual subscription orders does not have to enable them twice.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return bool
     */
    private static function subscriptionsAllowed($lines)
    {
        /** This filter is documented in FluentCart's OrderController::store(). */
        return (bool) apply_filters(
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCart's hook, not ours. An `fcbo/` name here would ask a question nobody answers; the whole point is to read the site's answer to the HOST's question, so a store that has already allowed manual subscription orders does not have to allow them twice.
            'fluent_cart/order/is_subscription_allowed_in_manual_order',
            false,
            ['order_items' => self::orderItems($lines)]
        );
    }

    /**
     * Turn stored quote lines into FluentCart order_items.
     *
     * The key names are FluentCart's, and each is load-bearing:
     *
     *   post_id     the PRODUCT post. Not the variant.
     *   object_id   the PRODUCT VARIATION. This is the one that decides what
     *               was actually bought — `variation_id` exists on the model
     *               but is not in its fillable list, so `object_id` is the
     *               canonical column. @see docs/solutions/architecture-patterns/
     *               fluentcart-order-model-user-orders-and-variants.md
     *   unit_price  integer MINOR units (cents), which is what
     *               AdminOrderProcessor casts it to and multiplies by quantity.
     *
     * A line whose variant or product id did not survive is dropped rather than
     * sent with a zero id, which FluentCart would reject as unavailable with a
     * message naming a product the owner cannot find.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private static function orderItems($lines)
    {
        $items = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $variantId = isset($line['variant_id']) ? (int) $line['variant_id'] : 0;
            $productId = isset($line['product_id']) ? (int) $line['product_id'] : 0;
            $qty       = isset($line['qty']) ? (int) $line['qty'] : 0;

            if ($variantId < 1 || $productId < 1 || $qty < 1) {
                continue;
            }

            $paymentType = isset($line['payment_type']) ? (string) $line['payment_type'] : 'onetime';

            $items[] = [
                'post_id'      => $productId,
                'object_id'    => $variantId,
                'quantity'     => $qty,
                'unit_price'   => QuoteInput::effectivePrice($line),
                'post_title'   => isset($line['title']) ? (string) $line['title'] : '',
                'title'        => isset($line['variation_title']) ? (string) $line['variation_title'] : '',
                'payment_type' => $paymentType,
                'other_info'   => ['payment_type' => $paymentType],
            ];
        }

        return $items;
    }

    /**
     * The FluentCart customer id for a WordPress user, creating one if needed.
     *
     * ---------------------------------------------------------------------------
     * WHY THIS IS NOT `CustomerResource::getCurrentCustomer()`
     * ---------------------------------------------------------------------------
     *
     * That helper answers about whoever is logged in, and the person logged in
     * here is the ADMIN converting the quote. Using it would put the store
     * owner's own customer record on the buyer's order — a mistake that
     * produces a perfectly valid-looking order billed to the wrong person.
     *
     * The lookup below is the same shape as the host's own
     * (fluent-cart 1.5.5, api/Resource/CustomerResource.php:398-435): match on
     * `user_id` first, fall back to the email, adopt the user id when a customer
     * exists under that address but was never linked, and create one otherwise.
     *
     * @param int $userId WordPress user id of the buyer.
     * @return int|\WP_Error
     */
    private static function customerIdFor($userId)
    {
        $userId = (int) $userId;
        $user   = $userId > 0 ? get_userdata($userId) : null;

        if (!$user) {
            return new \WP_Error(
                'fcbo_quote_no_buyer',
                __('The buyer who sent this quote no longer has an account, so no order could be created for them.', 'fluent-cart-bulk-order')
            );
        }

        if (!class_exists(\FluentCart\App\Models\Customer::class)) {
            return new \WP_Error(
                'fcbo_quote_no_fluentcart',
                __('FluentCart is not available, so no order could be created.', 'fluent-cart-bulk-order')
            );
        }

        $model = \FluentCart\App\Models\Customer::class;

        $customer = $model::query()->where('user_id', $userId)->first();

        if (!$customer && $user->user_email) {
            $customer = $model::query()->where('email', $user->user_email)->first();

            // A customer record created by a guest checkout under the same
            // address, never linked to the account. Adopting it here is what the
            // host does, and it keeps the buyer's history in one place instead
            // of splitting it across two customer rows.
            if ($customer && (int) $customer->user_id !== $userId) {
                $customer->user_id = $userId;
                $customer->save();
            }
        }

        if (!$customer) {
            if (!$user->user_email || !is_email($user->user_email)) {
                return new \WP_Error(
                    'fcbo_quote_no_email',
                    __('The buyer has no usable email address, so FluentCart cannot create a customer for them.', 'fluent-cart-bulk-order')
                );
            }

            $customer = $model::query()->create([
                'first_name' => (string) $user->first_name,
                'last_name'  => (string) $user->last_name,
                'email'      => $user->user_email,
                'user_id'    => $userId,
            ]);
        }

        if (!$customer || empty($customer->id)) {
            return new \WP_Error(
                'fcbo_quote_no_customer',
                __('FluentCart could not find or create a customer for this buyer.', 'fluent-cart-bulk-order')
            );
        }

        return (int) $customer->id;
    }
}
