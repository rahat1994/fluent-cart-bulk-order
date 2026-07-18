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
        $editable = array_keys(get_editable_roles());

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
     * Render the role checklist bound to fcbo_apply_to_roles.
     */
    public function renderRolesField()
    {
        $selected = (array) get_option(self::OPTION_NAME, []);
        $roles = get_editable_roles();

        // Hidden field guarantees the option is submitted even when nothing is
        // checked, so sanitizeRoles() runs and stores an empty array (everyone).
        echo '<input type="hidden" name="' . esc_attr(self::OPTION_NAME) . '[]" value="" />';

        echo '<fieldset>';
        foreach ($roles as $slug => $details) {
            $label = isset($details['name']) ? $details['name'] : $slug;
            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr(self::OPTION_NAME),
                esc_attr($slug),
                checked(in_array($slug, $selected, true), true, false),
                esc_html($label)
            );
        }
        echo '</fieldset>';

        echo '<p class="description">' . esc_html__(
            'Leave all unchecked = bulk pricing applies to everyone (default).',
            'fluent-cart-bulk-order'
        ) . '</p>';
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
