<?php

namespace FluentCartBulkOrder;

defined('ABSPATH') || exit;

/**
 * Minimal admin control for the bulk pricing role policy.
 *
 * Registers a single WordPress Settings-API page that exposes a role checklist
 * bound to the `fcbo_apply_to_roles` option. Leaving every role unchecked stores
 * an empty array, which means "apply bulk pricing to everyone" (the default,
 * backward-compatible behavior).
 *
 * This class is deliberately self-contained so Phase 2 can fold it into a
 * consolidated settings page without untangling it from the rest of the plugin.
 */
class Settings
{
    /**
     * Settings API group name (passed to settings_fields()).
     */
    const OPTION_GROUP = 'fcbo_settings';

    /**
     * The stored option name (array of role slugs; empty = everyone).
     */
    const OPTION_NAME = 'fcbo_apply_to_roles';

    /**
     * Minimum order total, in integer cents. 0 = no minimum.
     */
    const OPTION_MIN_TOTAL = 'fcbo_min_order_total';

    /**
     * Roles the minimum order total applies to.
     *
     * NOTE the inverted default relative to OPTION_NAME: an empty list here
     * means "nobody is subject", so an unconfigured minimum can never start
     * blocking checkouts on upgrade.
     */
    const OPTION_MIN_TOTAL_ROLES = 'fcbo_min_order_total_roles';

    /**
     * Admin page slug.
     */
    const PAGE_SLUG = 'fcbo-settings';

    /**
     * Hook the settings page into the admin.
     */
    public function register()
    {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * Register the options page under the Settings menu.
     */
    public function addSettingsPage()
    {
        add_options_page(
            __('Bulk Pricing', 'fluent-cart-bulk-order'),
            __('Bulk Pricing', 'fluent-cart-bulk-order'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Register the option, section, and role-checklist field.
     */
    public function registerSettings()
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitizeRoles'],
                'default'           => [],
            ]
        );

        add_settings_section(
            'fcbo_policy_section',
            __('Bulk Pricing Access', 'fluent-cart-bulk-order'),
            [$this, 'renderSectionIntro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'fcbo_apply_to_roles_field',
            __('Apply bulk pricing to', 'fluent-cart-bulk-order'),
            [$this, 'renderRolesField'],
            self::PAGE_SLUG,
            'fcbo_policy_section'
        );

        // ---- Minimum order total (Plan 009 · U6) ----
        // Self-contained on purpose, so Plan 011's consolidated settings page can
        // absorb it without untangling it from the role policy above.

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_MIN_TOTAL,
            [
                'type'              => 'integer',
                'sanitize_callback' => [$this, 'sanitizeMinOrderTotal'],
                'default'           => 0,
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_MIN_TOTAL_ROLES,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitizeRoles'],
                'default'           => [],
            ]
        );

        add_settings_section(
            'fcbo_min_order_section',
            __('Minimum Order Total', 'fluent-cart-bulk-order'),
            [$this, 'renderMinOrderIntro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'fcbo_min_order_total_field',
            __('Minimum order total', 'fluent-cart-bulk-order'),
            [$this, 'renderMinOrderTotalField'],
            self::PAGE_SLUG,
            'fcbo_min_order_section'
        );

        add_settings_field(
            'fcbo_min_order_total_roles_field',
            __('Require it of', 'fluent-cart-bulk-order'),
            [$this, 'renderMinOrderRolesField'],
            self::PAGE_SLUG,
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
     * Unknown/invalid slugs are dropped. A non-array (e.g. nothing submitted)
     * collapses to an empty array — which means "everyone".
     *
     * @param mixed $value Raw submitted value.
     * @return string[] Clean, de-duplicated list of valid role slugs.
     */
    public function sanitizeRoles($value)
    {
        $value = is_array($value) ? $value : [];
        $editable = array_keys($this->getEditableRoles());

        $clean = [];
        foreach ($value as $slug) {
            $slug = sanitize_key($slug);
            if ($slug !== '' && in_array($slug, $editable, true)) {
                $clean[] = $slug;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Section intro text.
     */
    public function renderSectionIntro()
    {
        echo '<p>' . esc_html__(
            'Choose which user roles receive quantity-based bulk pricing — both the discounted cart price and the tier display on product pages. Leave every role unchecked to apply bulk pricing to everyone.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Editable role slugs, with the admin include guaranteed.
     *
     * get_editable_roles() lives in wp-admin/includes/user.php, which is not
     * loaded outside admin page loads. These callbacks only run in admin today,
     * but the guard costs nothing and removes a trap for whoever reuses them.
     *
     * @return array<string, array> Role slug => role details.
     */
    private function getEditableRoles()
    {
        if (!function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        return (array) get_editable_roles();
    }

    /**
     * Render a role checklist bound to an arbitrary option.
     *
     * @param string $option      Option name holding the selected slugs.
     * @param string $description Help text shown beneath the list.
     */
    private function renderRoleChecklist($option, $description)
    {
        $selected = (array) get_option($option, []);
        $roles = $this->getEditableRoles();

        // Hidden field guarantees the option is submitted even when nothing is
        // checked, so sanitizeRoles() runs and stores an empty array.
        echo '<input type="hidden" name="' . esc_attr($option) . '[]" value="" />';

        echo '<fieldset>';
        foreach ($roles as $slug => $details) {
            $label = isset($details['name']) ? $details['name'] : $slug;
            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr($option),
                esc_attr($slug),
                checked(in_array($slug, $selected, true), true, false),
                esc_html($label)
            );
        }
        echo '</fieldset>';

        echo '<p class="description">' . esc_html($description) . '</p>';
    }

    /**
     * Render the role checklist bound to fcbo_apply_to_roles.
     */
    public function renderRolesField()
    {
        $this->renderRoleChecklist(
            self::OPTION_NAME,
            __('Leave all unchecked = bulk pricing applies to everyone (default).', 'fluent-cart-bulk-order')
        );
    }

    /**
     * Section intro for the minimum order total.
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
     */
    public function renderMinOrderRolesField()
    {
        $this->renderRoleChecklist(
            self::OPTION_MIN_TOTAL_ROLES,
            __('Leave all unchecked = the minimum applies to nobody. Unlike the setting above, this one is opt-in.', 'fluent-cart-bulk-order')
        );
    }

    /**
     * Render the settings page wrapper and form.
     */
    public function renderPage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Bulk Pricing', 'fluent-cart-bulk-order') . '</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
