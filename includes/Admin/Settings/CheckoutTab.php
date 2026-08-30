<?php

namespace FluentCartBulkOrder\Admin\Settings;

use FluentCartBulkOrder\AccessPolicy;
use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * Checkout — what happens between the surface and the payment.
 *
 * Where a finished bulk order is sent, and what the buyer is asked for on the
 * way. Both sections change a page every shopper sees, which is why both are
 * off or empty by default.
 */
class CheckoutTab extends Tab
{
    /**
     * @inheritDoc
     */
    public function slug()
    {
        return Tabs::CHECKOUT;
    }

    /**
     * @inheritDoc
     */
    public function label()
    {
        return __('Checkout', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    public function defaultsKeys()
    {
        return [
            'checkout_redirect',
            'po_mode',
            'po_roles',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerSections($page)
    {
        $this->registerCheckoutSection($page);
        $this->registerPoNumberSection($page);
    }

    /**
     * Where the bulk order form sends a finished order.
     *
     * @param string $page Settings API page bucket.
     * @return void
     */
    private function registerCheckoutSection($page)
    {
        add_settings_section(
            'fcbo_checkout_section',
            __('Checkout', 'fluent-cart-bulk-order'),
            [$this, 'renderCheckoutIntro'],
            $page
        );

        add_settings_field(
            'fcbo_checkout_redirect_field',
            __('Send bulk orders to', 'fluent-cart-bulk-order'),
            [$this, 'renderCheckoutRedirectField'],
            $page,
            'fcbo_checkout_section'
        );
    }

    /**
     * The purchase-order field at checkout, and who is asked for one.
     *
     * No register_setting() call of its own — both values live in the single
     * `fcbo_store_defaults` option, which Settings registers into this tab's
     * group, and are validated by StoreDefaults::sanitize().
     *
     * @param string $page Settings API page bucket.
     * @return void
     */
    private function registerPoNumberSection($page)
    {
        add_settings_section(
            'fcbo_po_section',
            __('Purchase Orders', 'fluent-cart-bulk-order'),
            [$this, 'renderPoIntro'],
            $page
        );

        add_settings_field(
            'fcbo_po_mode_field',
            __('PO number field', 'fluent-cart-bulk-order'),
            [$this, 'renderPoModeField'],
            $page,
            'fcbo_po_section'
        );

        add_settings_field(
            'fcbo_po_roles_field',
            __('Ask these roles', 'fluent-cart-bulk-order'),
            [$this, 'renderPoRolesField'],
            $page,
            'fcbo_po_section'
        );

        add_settings_field(
            'fcbo_po_audience_field',
            __('Who this binds', 'fluent-cart-bulk-order'),
            [$this, 'renderPoAudienceField'],
            $page,
            'fcbo_po_section'
        );
    }

    /**
     * Section intro for the checkout target.
     *
     * @return void
     */
    public function renderCheckoutIntro()
    {
        echo '<p>' . esc_html__(
            'By default a finished bulk order goes to the store checkout page. Point it somewhere else if wholesale orders use their own checkout.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Same-site checkout redirect URL.
     *
     * @return void
     */
    public function renderCheckoutRedirectField()
    {
        printf(
            '<input type="url" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr($this->defaultsName('checkout_redirect')),
            esc_attr((string) StoreDefaults::get('checkout_redirect', '')),
            esc_attr(home_url('/wholesale-checkout/'))
        );

        echo '<p class="description">' . esc_html__(
            'Leave blank to use the store checkout page. Must be on this site — an address anywhere else is discarded on save. A "redirect" attribute on the shortcode still wins for that placement.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Load the purchase-order classes this tab reads.
     *
     * Lazily, and only here, for the same reason AccessTab::loadWholesale() is:
     * these fields render on one tab of one admin screen.
     *
     * @return void
     */
    private function loadPoNumber()
    {
        require_once dirname(__DIR__, 2) . '/Checkout/PoNumber.php';
        require_once dirname(__DIR__, 2) . '/Checkout/PoSettings.php';
        require_once dirname(__DIR__, 2) . '/Export/OrderExportFlow.php';
        require_once dirname(__DIR__, 2) . '/Export/OrderExportScreen.php';
    }

    /**
     * Section intro.
     *
     * @return void
     */
    public function renderPoIntro()
    {
        $this->loadPoNumber();

        printf(
            '<p>%s</p>',
            esc_html__(
                'Purchasing departments buy against a purchase-order number and need it on the paperwork. Turn this on and the field appears at checkout, is saved with the order, and is printed on the receipt and in every export.',
                'fluent-cart-bulk-order'
            )
        );

        printf(
            '<p><a href="%1$s">%2$s</a></p>',
            esc_url(\FluentCartBulkOrder\Export\OrderExportScreen::pageUrl()),
            esc_html__('Open the order exports screen', 'fluent-cart-bulk-order')
        );
    }

    /**
     * The three states, as radio buttons.
     *
     * Radios and not a checkbox pair, because the three states are one value.
     * @see \FluentCartBulkOrder\Checkout\PoNumber for why a boolean "enabled"
     * plus a boolean "required" would admit a fourth combination that has no
     * meaning.
     *
     * @return void
     */
    public function renderPoModeField()
    {
        $this->loadPoNumber();

        $current = \FluentCartBulkOrder\Checkout\PoSettings::mode();
        $name    = $this->defaultsName('po_mode');

        $labels = [
            \FluentCartBulkOrder\Checkout\PoNumber::MODE_OFF      => __('Off — do not ask (default)', 'fluent-cart-bulk-order'),
            \FluentCartBulkOrder\Checkout\PoNumber::MODE_OPTIONAL => __('Optional — show the field, allow an empty one', 'fluent-cart-bulk-order'),
            \FluentCartBulkOrder\Checkout\PoNumber::MODE_REQUIRED => __('Required — refuse the order without one', 'fluent-cart-bulk-order'),
        ];

        echo '<fieldset>';

        foreach (\FluentCartBulkOrder\Checkout\PoNumber::modes() as $mode) {
            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
                esc_attr($name),
                esc_attr($mode),
                checked($current, $mode, false),
                esc_html(isset($labels[$mode]) ? $labels[$mode] : $mode)
            );
        }

        echo '</fieldset>';

        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                /* translators: %d: the maximum number of characters a PO number may have. */
                __('"Required" is enforced on the server, so it holds even if the checkout page is edited. Up to %d characters; anything longer is shortened rather than refused.', 'fluent-cart-bulk-order'),
                \FluentCartBulkOrder\Checkout\PoNumber::MAX_LENGTH
            ))
        );
    }

    /**
     * Checklist of the roles the field binds.
     *
     * @return void
     */
    public function renderPoRolesField()
    {
        $this->renderRoleChecklist(
            $this->defaultsName('po_roles'),
            (array) StoreDefaults::get('po_roles', []),
            __('Leave all unchecked and every shopper is asked, including logged-out ones. Tick the wholesale roles to ask only your trade buyers.', 'fluent-cart-bulk-order')
        );
    }

    /**
     * Say, in plain words, who the current combination actually binds.
     *
     * ---------------------------------------------------------------------------
     * A PROJECTION, NOT A SECOND SETTING
     * ---------------------------------------------------------------------------
     *
     * Stores nothing. It reads the same two values the fields above write and
     * states their effect, exactly as PricingTab::renderPricingVisibilityField()
     * does for the bulk-pricing policy — and for the same reason. The pair of
     * controls does not answer the question an owner actually has, which is "am
     * I about to stop retail customers checking out?" On an empty role list with
     * the mode set to Required, the honest answer is yes, and it should be said
     * before they press Save rather than discovered afterwards.
     *
     * @return void
     */
    public function renderPoAudienceField()
    {
        $this->loadPoNumber();

        $mode  = \FluentCartBulkOrder\Checkout\PoSettings::mode();
        $roles = AccessPolicy::poNumberRoles();

        if (!\FluentCartBulkOrder\Checkout\PoNumber::isOn($mode)) {
            printf(
                '<p><strong>%s</strong></p>',
                esc_html__('Nobody. The field does not appear at checkout.', 'fluent-cart-bulk-order')
            );

            return;
        }

        $required = \FluentCartBulkOrder\Checkout\PoNumber::isRequired($mode);

        if (!$roles) {
            printf(
                '<p><strong>%s</strong></p>',
                esc_html(
                    $required
                        ? __('EVERY shopper, including logged-out ones, must enter a PO number to complete an order.', 'fluent-cart-bulk-order')
                        : __('Every shopper, including logged-out ones, is offered the field. Nobody is refused for leaving it blank.', 'fluent-cart-bulk-order')
                )
            );

            if ($required) {
                printf(
                    '<p class="description">%s</p>',
                    esc_html__('That includes your retail customers. If you only want it from trade buyers, tick their roles above.', 'fluent-cart-bulk-order')
                );
            }

            return;
        }

        if ($required) {
            /* translators: %s: comma-separated list of role names. */
            $format = __('Only these roles are asked, and they must answer: %s.', 'fluent-cart-bulk-order');
        } else {
            /* translators: %s: comma-separated list of role names. */
            $format = __('Only these roles are offered the field: %s.', 'fluent-cart-bulk-order');
        }

        printf(
            '<p><strong>%s</strong></p>',
            esc_html(sprintf($format, implode(', ', $this->roleNames($roles))))
        );

        printf(
            '<p class="description">%s</p>',
            esc_html__('Everyone else checks out exactly as they do today.', 'fluent-cart-bulk-order')
        );
    }
}
