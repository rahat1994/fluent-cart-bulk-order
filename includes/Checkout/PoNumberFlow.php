<?php

namespace FluentCartBulkOrder\Checkout;

use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * The one place the PO number field attaches itself to FluentCart.
 *
 * ---------------------------------------------------------------------------
 * A STORE THAT HAS NOT TURNED THIS ON PAYS FOR ONE OPTION READ
 * ---------------------------------------------------------------------------
 *
 * register() registers three cheap read-side hooks, then reads the stored mode
 * and returns when it is off — which is the default, so on almost every install
 * the entire cost of this feature is parsing THIS file, three add_action()
 * calls, and one array lookup in an option Gate 1 had already loaded. Not one
 * of the classes below is required until a hook actually fires.
 *
 * The read is deliberately RAW rather than through PoNumber::sanitizeMode().
 * Normalising here would mean loading PoNumber and PoSettings on every page
 * load in order to decide not to load them. Anything that is not exactly `off`
 * registers the checkout hooks, and every callback then asks PoSettings the
 * normalised question — so a corrupted option costs three more add_action()
 * calls and changes nothing a shopper sees.
 *
 * ---------------------------------------------------------------------------
 * EVERY CALLBACK IS A METHOD OF *THIS* CLASS
 * ---------------------------------------------------------------------------
 *
 * Same rule as QuoteFlow, and for the same reason: hooking PoField directly
 * would let one of these hooks fire before anything had required PoField.php,
 * and WordPress answers an uncallable callback with a fatal. A white screen at
 * checkout is worse than a missing field.
 *
 * @see \FluentCartBulkOrder\Checkout\PoField What each callback actually does.
 * @see \FluentCartBulkOrder\Export\OrderExportFlow The other half of the same
 *      roadmap item — exporting the order the PO number ends up on.
 */
class PoNumberFlow
{
    /**
     * Wire the field into FluentCart's checkout, receipt and order payload.
     *
     * Called from the `plugins_loaded` handler in the main plugin file, inside
     * the FluentCart-is-active guard — every hook below is the host's.
     *
     * @return void
     */
    public static function register()
    {
        // ---------------------------------------------------------------
        // READING A STORED PO NUMBER IS NOT CONDITIONAL ON THE MODE
        // ---------------------------------------------------------------
        //
        // These three run whether the field is on or off, and that is the
        // point: an owner who collects PO numbers for a year and then switches
        // the field off must not have last year's receipts silently stop
        // showing the reference the buyer paid against. The export screen and
        // the CSV read the stored value the same way, so gating these would
        // also mean one surface showing a PO number while another hides it.
        //
        // Each callback returns immediately when the order carries nothing, so
        // an order placed before the field existed renders exactly as it did.
        add_action('fluent_cart/receipt/thank_you/after_order_items', [self::class, 'renderOnReceipt']);
        add_filter('fluent_cart/customer/order_details_section_parts', [self::class, 'addToCustomerOrder'], 10, 2);

        // Into the host's own admin order payload. @see
        // PoField::addToOrderPayload() for what that does and does not achieve.
        add_filter('fluent_cart/order/view', [self::class, 'addToOrderPayload']);

        // ---------------------------------------------------------------
        // ASKING FOR ONE IS
        // ---------------------------------------------------------------
        //
        // The literal 'off' rather than PoNumber::MODE_OFF. @see the class
        // docblock, and StoreDefaults::FALLBACKS for the same trade-off.
        if (StoreDefaults::get('po_mode', 'off') === 'off') {
            return;
        }

        // The field itself. Unconditional within the checkout render, inside
        // the <form>, and fired by BOTH the full checkout page and the
        // instant/modal checkout — @see PoField for why the invitingly-named
        // b2b_extra_fields hook is the wrong one.
        add_action('fluent_cart/before_payment_methods', [self::class, 'renderField']);

        // The authority. Same veto-capable filter the order minimum uses.
        add_filter('fluent_cart/checkout/validate_data', [self::class, 'validate'], 10, 2);

        // Persist onto the order FluentCart has just drafted.
        add_action('fluent_cart/checkout/prepare_other_data', [self::class, 'store']);
    }

    /**
     * @return void
     */
    public static function renderField()
    {
        self::load();
        PoField::render();
    }

    /**
     * @param array $errors
     * @param array $context
     * @return array
     */
    public static function validate($errors, $context)
    {
        self::load();

        return PoField::validate($errors, $context);
    }

    /**
     * @param array $data
     * @return void
     */
    public static function store($data)
    {
        self::load();
        PoField::store($data);
    }

    /**
     * @param mixed $config
     * @return void
     */
    public static function renderOnReceipt($config)
    {
        self::load();
        PoField::renderOnReceipt($config);
    }

    /**
     * @param mixed $parts
     * @param mixed $context
     * @return array
     */
    public static function addToCustomerOrder($parts, $context)
    {
        self::load();

        return PoField::addToCustomerOrder($parts, $context);
    }

    /**
     * @param mixed $order
     * @return mixed
     */
    public static function addToOrderPayload($order)
    {
        self::load();

        return PoField::addToOrderPayload($order);
    }

    /**
     * Load the classes the flow needs.
     *
     * require_once throughout, so this is safe to call from every entry point
     * and harmless when the export screen has already loaded the same pair.
     *
     * @return void
     */
    public static function load()
    {
        require_once __DIR__ . '/PoNumber.php';
        require_once __DIR__ . '/PoSettings.php';
        require_once __DIR__ . '/PoField.php';
    }
}
