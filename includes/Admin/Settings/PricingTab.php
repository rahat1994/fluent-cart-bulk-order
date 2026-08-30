<?php

namespace FluentCartBulkOrder\Admin\Settings;

use FluentCartBulkOrder\AccessPolicy;
use FluentCartBulkOrder\Strings;

defined('ABSPATH') || exit;

/**
 * Pricing — who receives bulk pricing, and the order-total floor.
 *
 * The one tab whose values are NOT in the store-defaults array. All three
 * options here predate that array and keep their own top-level names, because
 * renaming them would drop the saved policy of every store that already
 * configured one. @see \FluentCartBulkOrder\AccessPolicy for the gate logic
 * itself; nothing here decides who gets what, it only stores what was chosen.
 *
 * That is also why this tab is the only one that registers options of its own,
 * and the reason each tab needs a group to itself. Before tabs, an absent
 * `fcbo_apply_to_roles` in a submission meant "the owner unticked everything".
 * With tabs it would mean "the owner is on the Quotes tab", and options.php
 * cannot tell the difference. @see Tabs
 */
class PricingTab extends Tab
{
    /*
     * The option names are aliases of the AccessPolicy constants, not second
     * copies of the strings. AccessPolicy is the single source of truth: the
     * gates read these options, so a rename there must not be able to drift
     * away from the page that writes them.
     */

    /**
     * Gate 2 role list (array of role slugs; empty = everyone).
     */
    const OPTION_NAME = AccessPolicy::OPTION_BULK_PRICING_ROLES;

    /**
     * Gate 3 amount, in integer cents. 0 = no minimum.
     */
    const OPTION_MIN_TOTAL = AccessPolicy::OPTION_MIN_ORDER_TOTAL;

    /**
     * Gate 3 role list.
     *
     * NOTE the inverted default relative to OPTION_NAME: an empty list here
     * means "nobody is subject", so an unconfigured minimum can never start
     * blocking checkouts on upgrade.
     */
    const OPTION_MIN_TOTAL_ROLES = AccessPolicy::OPTION_MIN_ORDER_TOTAL_ROLES;

    /**
     * @inheritDoc
     */
    public function slug()
    {
        return Tabs::PRICING;
    }

    /**
     * @inheritDoc
     */
    public function label()
    {
        return __('Pricing', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    public function registerOptions($group)
    {
        register_setting(
            $group,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitizeRoles'],
                'default'           => [],
            ]
        );

        register_setting(
            $group,
            self::OPTION_MIN_TOTAL,
            [
                'type'              => 'integer',
                'sanitize_callback' => [$this, 'sanitizeMinOrderTotal'],
                'default'           => 0,
            ]
        );

        register_setting(
            $group,
            self::OPTION_MIN_TOTAL_ROLES,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitizeRoles'],
                'default'           => [],
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function registerSections($page)
    {
        $this->registerPricingPolicySection($page);
        $this->registerMinOrderSection($page);
    }

    /**
     * Gate 2 — who receives bulk pricing.
     *
     * @param string $page Settings API page bucket.
     * @return void
     */
    private function registerPricingPolicySection($page)
    {
        add_settings_section(
            'fcbo_policy_section',
            __('Bulk Pricing Access', 'fluent-cart-bulk-order'),
            [$this, 'renderSectionIntro'],
            $page
        );

        add_settings_field(
            'fcbo_apply_to_roles_field',
            __('Apply bulk pricing to', 'fluent-cart-bulk-order'),
            [$this, 'renderRolesField'],
            $page,
            'fcbo_policy_section'
        );

        add_settings_field(
            'fcbo_pricing_visibility_field',
            __('Who sees tier prices', 'fluent-cart-bulk-order'),
            [$this, 'renderPricingVisibilityField'],
            $page,
            'fcbo_policy_section'
        );
    }

    /**
     * Gate 3 — the order-total floor and the roles it binds.
     *
     * @param string $page Settings API page bucket.
     * @return void
     */
    private function registerMinOrderSection($page)
    {
        add_settings_section(
            'fcbo_min_order_section',
            __('Minimum Order Total', 'fluent-cart-bulk-order'),
            [$this, 'renderMinOrderIntro'],
            $page
        );

        add_settings_field(
            'fcbo_min_order_total_field',
            __('Minimum order total', 'fluent-cart-bulk-order'),
            [$this, 'renderMinOrderTotalField'],
            $page,
            'fcbo_min_order_section'
        );

        add_settings_field(
            'fcbo_min_order_total_roles_field',
            __('Require it of', 'fluent-cart-bulk-order'),
            [$this, 'renderMinOrderRolesField'],
            $page,
            'fcbo_min_order_section'
        );
    }

    /**
     * Convert the entered amount (major units, e.g. 500.00) to integer cents.
     *
     * The owner types currency the way they think about it; the whole plugin
     * compares totals in cents, so the conversion happens once, here. Blank or
     * junk input collapses to 0, which means "no minimum".
     *
     * @param mixed $value Raw submitted value.
     * @return int Cents (>= 0).
     */
    public function sanitizeMinOrderTotal($value)
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '' || !is_numeric($value)) {
            return 0;
        }

        return max(0, (int) round(((float) $value) * 100));
    }

    /**
     * Sanitize the submitted role list against real, editable role slugs.
     *
     * Stays a public method because it is registered as a `sanitize_callback`.
     *
     * @param mixed $value Raw submitted value.
     * @return string[] Clean, de-duplicated list of valid role slugs.
     */
    public function sanitizeRoles($value)
    {
        return AccessPolicy::sanitizeRoleList($value);
    }

    /**
     * Section intro text.
     *
     * @return void
     */
    public function renderSectionIntro()
    {
        echo '<p>' . esc_html__(
            'Choose which user roles receive quantity-based bulk pricing. This governs two things at once: the discounted price charged in the cart, and whether the tier table is shown on public product pages.',
            'fluent-cart-bulk-order'
        ) . '</p>';

        // Said here as well as in the tier editor, in the same words. An owner
        // reading a page headed "Bulk Pricing Access" is asking who gets bulk
        // pricing, and "subscription products, nobody" is part of that answer.
        fcbo_load_strings();
        echo '<p class="description">' . esc_html(Strings::subscriptionOwnerNotice()) . '</p>';
    }

    /**
     * Render the role checklist bound to fcbo_apply_to_roles.
     *
     * @return void
     */
    public function renderRolesField()
    {
        $this->renderRoleChecklist(
            self::OPTION_NAME,
            (array) get_option(self::OPTION_NAME, []),
            __('Leave all unchecked and bulk pricing applies to everyone — including logged-out visitors. See the summary below for what that means today.', 'fluent-cart-bulk-order')
        );
    }

    /**
     * Say, in plain words, who can currently see tier prices on product pages.
     *
     * ---------------------------------------------------------------------------
     * A PROJECTION, NOT A SECOND SETTING
     * ---------------------------------------------------------------------------
     *
     * This field stores nothing. It reads the SAME `fcbo_apply_to_roles` option
     * the checklist above writes, and states its effect. A separate
     * "show tiers to guests" option would be a second source of truth for one
     * behaviour, and the two would eventually disagree.
     *
     * It exists because the checklist alone does not answer the question a
     * wholesale owner actually has — "can the public see my trade prices?" — and
     * the honest answer on a fresh install is yes. An empty list means everyone,
     * which is the backward-compatible default and also the most surprising one.
     *
     * @return void
     */
    public function renderPricingVisibilityField()
    {
        $roles = AccessPolicy::bulkPricingRoles();

        if (!$roles) {
            echo '<p><strong>' . esc_html__(
                'Everyone, including logged-out visitors, can see tier prices on your product pages.',
                'fluent-cart-bulk-order'
            ) . '</strong></p>';

            echo '<p class="description">' . esc_html__(
                'This is the default. To keep trade prices off the public storefront, tick the roles above that should see them — anyone else, signed in or not, then sees only your normal price.',
                'fluent-cart-bulk-order'
            ) . '</p>';

            return;
        }

        printf(
            '<p><strong>%s</strong></p>',
            esc_html(sprintf(
                /* translators: %s: comma-separated list of role names. */
                __('Only these roles see tier prices: %s.', 'fluent-cart-bulk-order'),
                implode(', ', $this->roleNames($roles))
            ))
        );

        echo '<p class="description">' . esc_html__(
            'Logged-out visitors do not. Administrators can always see the tier table so they can check it, but they are not given the discount at checkout — an administrator\'s own order is charged the same as any other shopper the policy excludes.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Section intro for the minimum order total.
     *
     * @return void
     */
    public function renderMinOrderIntro()
    {
        echo '<p>' . esc_html__(
            'Require a minimum order value from specific roles. This is enforced at checkout on the server, so it cannot be bypassed by editing the page.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Render the amount input. Stored in cents, shown in major units.
     *
     * @return void
     */
    public function renderMinOrderTotalField()
    {
        $cents = (int) get_option(self::OPTION_MIN_TOTAL, 0);
        $shown = $cents > 0 ? number_format($cents / 100, 2, '.', '') : '';

        printf(
            '<input type="number" name="%1$s" value="%2$s" min="0" step="0.01" class="regular-text" placeholder="0.00" /> <span>%3$s</span>',
            esc_attr(self::OPTION_MIN_TOTAL),
            esc_attr($shown),
            esc_html(function_exists('fcbo_get_currency_sign') ? fcbo_get_currency_sign() : '')
        );

        echo '<p class="description">' . esc_html__(
            'Leave blank or 0 for no minimum (default). Compared against the order subtotal, before shipping and tax.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Render the role checklist that scopes the minimum order total.
     *
     * @return void
     */
    public function renderMinOrderRolesField()
    {
        $this->renderRoleChecklist(
            self::OPTION_MIN_TOTAL_ROLES,
            (array) get_option(self::OPTION_MIN_TOTAL_ROLES, []),
            __('Leave all unchecked = the minimum applies to nobody. Unlike the setting above, this one is opt-in.', 'fluent-cart-bulk-order')
        );
    }
}
