<?php

namespace FluentCartBulkOrder\Integrations\Elementor;

defined('ABSPATH') || exit;

/**
 * Elementor widget for `[fluent_cart_bulk_order]`.
 *
 * Parses only with Elementor loaded — required from WidgetHandler::register()
 * and nowhere else. @see ShortcodeWidget for the shared render path and for why
 * every control has a "use store default" position.
 */
class BulkOrderFormWidget extends ShortcodeWidget
{
    /**
     * @inheritDoc
     */
    protected function shortcodeTag()
    {
        return 'fluent_cart_bulk_order';
    }

    /**
     * @inheritDoc
     */
    public function get_name()
    {
        // Namespaced so it cannot collide with another plugin's widget. Changing
        // this name orphans every widget already placed in a page, so treat it
        // as permanent.
        return 'fcbo-bulk-order-form';
    }

    /**
     * @inheritDoc
     */
    public function get_title()
    {
        return esc_html__('Bulk Order Form', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    public function get_icon()
    {
        return 'eicon-form-horizontal';
    }

    /**
     * @inheritDoc
     */
    public function get_keywords()
    {
        return ['bulk', 'wholesale', 'order', 'fluentcart'];
    }

    /**
     * @inheritDoc
     */
    protected function controlDefinitions()
    {
        return [
            'roles' => $this->rolesControl(
                esc_html__('Comma-separated role slugs that may also see this form. Adds to the roles allowed in Settings; it never replaces them.', 'fluent-cart-bulk-order')
            ),
            'redirect' => [
                'label'       => esc_html__('Redirect URL', 'fluent-cart-bulk-order'),
                'type'        => \Elementor\Controls_Manager::URL,
                'description' => esc_html__('Send the shopper here instead of the store checkout page. Same site only.', 'fluent-cart-bulk-order'),
                // The URL control stores an array, not a string, so its
                // default has to be array-shaped too. normalizeSettings()
                // below unwraps it before it reaches the shortcode.
                'default'     => ['url' => ''],
            ],
        ];
    }

    /**
     * Flatten Elementor's URL control back to a plain string.
     *
     * The URL control is the right control for the job - it gives a store owner
     * the link picker they expect - but it stores
     * `['url' => ..., 'is_external' => ..., 'nofollow' => ...]`. The `redirect`
     * shortcode attribute is a bare URL string.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected function normalizeSettings(array $settings)
    {
        if (isset($settings['redirect']) && is_array($settings['redirect'])) {
            $settings['redirect'] = isset($settings['redirect']['url'])
                ? $settings['redirect']['url']
                : '';
        }

        return $settings;
    }
}
