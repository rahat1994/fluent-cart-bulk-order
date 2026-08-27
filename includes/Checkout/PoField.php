<?php

namespace FluentCartBulkOrder\Checkout;

defined('ABSPATH') || exit;

/**
 * The PO number field: drawn at checkout, enforced on the server, written to
 * the order, and shown back wherever FluentCart lets us show it.
 *
 * ---------------------------------------------------------------------------
 * THE BROWSER IS NOT THE ENFORCEMENT
 * ---------------------------------------------------------------------------
 *
 * render() marks a required field `required`, which is a hint. FluentCart's
 * checkout script serialises the form itself, so whether the browser's own
 * validation ever runs is the host's business and not something this plugin
 * can promise. validate() is the authority: it runs on
 * `fluent_cart/checkout/validate_data` — the same veto-capable filter the order
 * minimum already uses — and refuses the checkout whatever the page did.
 *
 * @see docs/solutions/architecture-patterns/fluentcart-veto-capable-hooks-for-cart-and-checkout.md
 * @see \FluentCartBulkOrder\Cart\RuleEnforcement The other server backstop.
 *
 * ---------------------------------------------------------------------------
 * WHY `fluent_cart/before_payment_methods` AND NOT THE OBVIOUS HOOK
 * ---------------------------------------------------------------------------
 *
 * FluentCart has a hook called `fluent_cart/checkout/b2b_extra_fields`, which
 * is exactly what a PO number is and exactly the wrong place to put it. It
 * fires inside the B2B section that only renders once the shopper has ticked
 * "I am a business" (fluent-cart 1.5.5,
 * app/Services/Renderer/CheckoutRenderer.php:420, inside renderB2BToggle()), so
 * a REQUIRED field placed there would be invisible until the shopper found a
 * toggle, and the server would refuse a checkout over a field the page never
 * showed.
 *
 * `fluent_cart/before_payment_methods` is unconditional, inside the `<form>`,
 * and — the reason it wins outright — it is fired by BOTH renderers: the full
 * checkout page (CheckoutRenderer.php:223) and the instant/modal checkout
 * (ModalCheckoutRenderer.php:203). One registration covers every path a shopper
 * can reach, which is the same property that decided the order-rule hooks.
 *
 * The lesson is the one already written down for this plugin: a hook's name is
 * not evidence of what it does. Read the call site.
 *
 * ---------------------------------------------------------------------------
 * THE REQUEST ARRAY IS ALREADY UNSLASHED — DO NOT UNSLASH IT AGAIN
 * ---------------------------------------------------------------------------
 *
 * The checkout data these callbacks receive is not a superglobal. It arrives
 * through the host framework's Request, whose constructor runs every value of
 * `$_GET` and `$_POST` through `stripslashes_deep()` and `trim()` (fluent-cart
 * 1.5.5, vendor/wpfluent/framework/src/WPFluent/Http/Request/Request.php:119
 * and .../InteractsWithCleaningTrait.php:58).
 *
 * So `wp_unslash()` here would strip a level of slashes nobody added, quietly
 * mangling a PO number containing a backslash. Sanitising is still ours to do,
 * and PoNumber::sanitize() does it.
 */
class PoField
{
    /**
     * The error key the checkout refusal is reported under.
     *
     * Matches the field name so a host that maps validation errors back to
     * inputs by key can highlight the right box.
     */
    const ERROR_KEY = 'fcbo_po_number';

    /**
     * Draw the field inside the checkout form.
     *
     * Runs on every checkout render, including for a shopper Gate 4 excludes —
     * which is why the first thing it does is ask whether the field applies at
     * all. Nothing is emitted for a shopper who is not asked.
     *
     * @return void
     */
    public static function render()
    {
        if (!PoSettings::appliesTo()) {
            return;
        }

        $required = PoSettings::requiredFor();
        $label    = PoSettings::label();
        $name     = PoSettings::FIELD_NAME;

        if ($required) {
            $hint = __('Required. Your purchase-order reference is printed on the receipt and kept with the order.', 'fluent-cart-bulk-order');
        } else {
            $hint = __('Optional. Add your purchase-order reference and it is printed on the receipt and kept with the order.', 'fluent-cart-bulk-order');
        }

        // Written as escaped printf() calls broken up by literal echoes rather
        // than one interpolated string. The conditional fragment — the
        // `required` attribute — is a literal with nothing interpolated into
        // it, so it is echoed as it is; folding it into a printf() argument
        // would make it look like an unescaped variable to a reviewer and to
        // WordPress Plugin Check alike.
        //
        // There is deliberately no asterisk on the label. `required` on the
        // input and the word "Required." in the hint below say the same thing
        // to a screen reader and to a shopper, which a bare `*` does to
        // neither.
        printf(
            '<div class="fct_input_wrapper fcbo-po-field" id="%1$s_wrapper">',
            esc_attr($name)
        );

        printf(
            '<label for="%1$s" class="fcbo-po-label">%2$s</label>',
            esc_attr($name),
            esc_html($label)
        );

        printf(
            '<input type="text" name="%1$s" id="%1$s" class="fcbo-po-input" value="" autocomplete="off"'
            . ' maxlength="%2$s" placeholder="%3$s" aria-describedby="%1$s_hint"',
            esc_attr($name),
            esc_attr((string) PoNumber::MAX_LENGTH),
            esc_attr($label)
        );

        if ($required) {
            // A hint, not the enforcement. @see the class docblock.
            echo ' required aria-required="true"';
        }

        echo ' />';

        printf(
            '<p class="fcbo-po-hint description" id="%1$s_hint">%2$s</p>',
            esc_attr($name),
            esc_html($hint)
        );

        echo '</div>';
    }

    /**
     * Refuse a checkout that owes a PO number and has not given one.
     *
     * Bound to `fluent_cart/checkout/validate_data`, which halts checkout when
     * the returned error array is non-empty. Like the order-minimum callback
     * next door, this only ever ADDS a key and returns the incoming array on
     * every path, so it can never erase an error FluentCart or another
     * extension already recorded.
     *
     * @param array $errors  Accumulated validation errors (field => code => msg).
     * @param array $context ['data' => array, 'cart' => object]
     * @return array
     */
    public static function validate($errors, $context)
    {
        if (!PoSettings::requiredFor()) {
            return $errors;
        }

        $value = self::submitted($context);

        if (!PoNumber::isMissing($value)) {
            return $errors;
        }

        $errors = is_array($errors) ? $errors : [];

        $errors[self::ERROR_KEY]['required'] = sprintf(
            /* translators: %s: what the store calls the purchase-order field, e.g. "PO number". */
            __('Please enter your %s to complete this order.', 'fluent-cart-bulk-order'),
            PoSettings::label()
        );

        return $errors;
    }

    /**
     * Write the PO number onto the order that was just created.
     *
     * Bound to `fluent_cart/checkout/prepare_other_data`, which is the point at
     * which the draft order exists and the raw submission is still in hand —
     * and the same hook FluentCart's own tax module uses to put the buyer's
     * business details on an order (fluent-cart 1.5.5,
     * app/Modules/Tax/TaxModule.php:29 and :1921).
     *
     * An EMPTY value is not written. A store that switches the field off, or a
     * buyer who leaves an optional box blank, should leave no meta row behind —
     * "no PO number" is the absence of the key, not a key holding "".
     *
     * @param array $data ['cart' => object, 'order' => object, 'request_data' => array, ...]
     * @return void
     */
    public static function store($data)
    {
        if (!is_array($data)) {
            return;
        }

        $order = isset($data['order']) ? $data['order'] : null;

        if (!is_object($order) || !method_exists($order, 'updateMeta')) {
            return;
        }

        // Gate 4 is NOT re-checked here, deliberately. If a value arrived and
        // the order was allowed to be created, storing what the buyer typed is
        // strictly better than dropping it — a policy that changed between the
        // form render and the submission must not silently discard a reference
        // the buyer believes the store now has.
        if (!PoNumber::isOn(PoSettings::mode())) {
            return;
        }

        $value = self::submitted(['data' => isset($data['request_data']) ? $data['request_data'] : []]);

        if (PoNumber::isMissing($value)) {
            return;
        }

        $order->updateMeta(PoSettings::META_KEY, $value);
    }

    /**
     * Show the PO number on the buyer's thank-you page.
     *
     * ---------------------------------------------------------------------
     * ESCAPING IS NOT QUITE ENOUGH HERE, AND THE REASON IS THE HOST
     * ---------------------------------------------------------------------
     *
     * Everything this hook prints is captured into a buffer and the whole
     * finished page is then run through FluentCart's smartcode parser
     * (fluent-cart 1.5.5, app/Hooks/Handlers/ShortCodes/ReceiptHandler.php:164),
     * which substitutes any `{{...}}` it recognises. A PO number is text a
     * buyer typed, so one reading `{{settings.store_name}}` would come out of
     * that parser as the store's name instead of the reference the store
     * actually holds — escaping does not stop it, because braces are not HTML.
     *
     * Nothing is disclosed by it: every parser is scoped to this order, this
     * viewer or the public store settings. It is a correctness problem, not a
     * leak — but a receipt that prints something other than what is on the
     * order is exactly the thing this field exists to prevent.
     *
     * So the opening brace pair is entity-encoded AFTER escaping. The result
     * is already-escaped HTML, which is why the printf below carries an
     * annotation rather than a second esc_html().
     *
     * @param mixed $config ['order' => Order, ...]
     * @return void
     */
    public static function renderOnReceipt($config)
    {
        $order = is_array($config) && isset($config['order']) ? $config['order'] : null;
        $value = PoSettings::forOrder($order);

        if ($value === '') {
            return;
        }

        $safe = str_replace('{{', '{&#123;', esc_html($value));

        printf(
            '<p class="fcbo-po-receipt"><strong>%1$s:</strong> %2$s</p>',
            esc_html(PoSettings::label()),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $safe is esc_html() output with the smartcode brace pair additionally neutralised; escaping it twice would print the entity instead of the brace.
            $safe
        );
    }

    /**
     * Add the PO number to one order in the buyer's own dashboard.
     *
     * `fluent_cart/customer/order_details_section_parts` is a slot system: the
     * filter takes an array of named slots holding HTML, and the customer
     * dashboard prints whatever is in them (fluent-cart 1.5.5,
     * app/Http/Controllers/FrontendControllers/CustomerOrderController.php:335).
     * The value is APPENDED rather than assigned, so another extension using
     * the same slot is not overwritten.
     *
     * @param mixed $parts   Slot name => HTML.
     * @param mixed $context ['order' => Order, 'formattedData' => array]
     * @return array
     */
    public static function addToCustomerOrder($parts, $context)
    {
        $parts = is_array($parts) ? $parts : [];
        $order = is_array($context) && isset($context['order']) ? $context['order'] : null;
        $value = PoSettings::forOrder($order);

        if ($value === '') {
            return $parts;
        }

        $html = sprintf(
            '<p class="fcbo-po-order"><strong>%1$s:</strong> %2$s</p>',
            esc_html(PoSettings::label()),
            esc_html($value)
        );

        $existing = isset($parts['after_summary']) && is_string($parts['after_summary'])
            ? $parts['after_summary']
            : '';

        $parts['after_summary'] = $existing . $html;

        return $parts;
    }

    /**
     * Put the PO number in FluentCart's own single-order admin payload.
     *
     * ---------------------------------------------------------------------
     * WHAT THIS DOES AND DOES NOT ACHIEVE — worth being plain about
     * ---------------------------------------------------------------------
     *
     * FluentCart's wp-admin order screen is a Vue single-page app with no PHP
     * render hook and no JavaScript slot to put a panel in; the only extension
     * point on it registers whole new SPA ROUTES. So there is no supported way
     * for this plugin to draw a line on the existing order detail screen.
     *
     * `fluent_cart/order/view` is the one seam that exists (fluent-cart 1.5.5,
     * app/Http/Controllers/OrderController.php:608). Adding the value here does
     * not render anything by itself, and this file does not pretend otherwise —
     * what it buys is that the PO number travels with the order through the
     * host's own admin API, so a store's ERP integration or a custom SPA route
     * has it without knowing anything about this plugin.
     *
     * Where an owner actually READS a PO number is the plugin's own order
     * screen. @see \FluentCartBulkOrder\Export\OrderExportScreen
     *
     * @param mixed $order The order as an array.
     * @return mixed
     */
    public static function addToOrderPayload($order)
    {
        if (!is_array($order) || empty($order['id'])) {
            return $order;
        }

        if (!class_exists(\FluentCart\App\Models\Order::class)) {
            return $order;
        }

        $model = \FluentCart\App\Models\Order::query()->find((int) $order['id']);
        $value = PoSettings::forOrder($model);

        if ($value !== '') {
            $order[PoSettings::META_KEY] = $value;
        }

        return $order;
    }

    /**
     * The sanitised PO number out of a checkout submission.
     *
     * One reader for both the validation and the storage callbacks, so the
     * value that is judged is byte-for-byte the value that is stored. Two
     * readers would eventually differ by a trim.
     *
     * @param mixed $context ['data' => array]
     * @return string
     */
    private static function submitted($context)
    {
        $data = is_array($context) && isset($context['data']) && is_array($context['data'])
            ? $context['data']
            : [];

        if (!isset($data[PoSettings::FIELD_NAME])) {
            return '';
        }

        // No wp_unslash(). @see the class docblock — the host framework has
        // already run stripslashes_deep() over this array.
        return PoNumber::sanitize($data[PoSettings::FIELD_NAME], 'sanitize_text_field');
    }
}
