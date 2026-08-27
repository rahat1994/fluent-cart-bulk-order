<?php

namespace FluentCartBulkOrder\Integrations\Elementor;

use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * Elementor widget for `[fluent_cart_product_table]`.
 *
 * Parses only with Elementor loaded — required from WidgetHandler::register()
 * and nowhere else. @see ShortcodeWidget for the shared render path and for why
 * every control has a "use store default" position.
 */
class ProductTableWidget extends ShortcodeWidget
{
    /**
     * @inheritDoc
     */
    protected function shortcodeTag()
    {
        return 'fluent_cart_product_table';
    }

    /**
     * @inheritDoc
     */
    public function get_name()
    {
        // Namespaced so it cannot collide with another plugin's widget. Changing
        // this name orphans every widget already placed in a page, so treat it
        // as permanent.
        return 'fcbo-product-table';
    }

    /**
     * @inheritDoc
     */
    public function get_title()
    {
        return esc_html__('Product Table', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    public function get_icon()
    {
        return 'eicon-table';
    }

    /**
     * @inheritDoc
     */
    public function get_keywords()
    {
        return ['catalogue', 'catalog', 'products', 'table', 'fluentcart'];
    }

    /**
     * @inheritDoc
     */
    protected function controlDefinitions()
    {
        return [
            'per_page' => [
                'label'       => esc_html__('Rows per page', 'fluent-cart-bulk-order'),
                'type'        => \Elementor\Controls_Manager::NUMBER,
                'min'         => StoreDefaults::MIN_PER_PAGE,
                'max'         => StoreDefaults::MAX_PER_PAGE,
                'description' => esc_html__('Leave blank to follow the store-wide setting.', 'fluent-cart-bulk-order'),
            ],
            'columns' => [
                'label'       => esc_html__('Columns', 'fluent-cart-bulk-order'),
                'type'        => \Elementor\Controls_Manager::SELECT2,
                'multiple'    => true,
                'options'     => self::columnOptions(),
                // An array default, because a multiple SELECT2 stores an array.
                // AttributeSchema joins it back into the comma string the
                // shortcode attribute uses, and an empty array is "not set".
                'default'     => [],
                'description' => esc_html__('Leave empty to follow the store-wide column choice. Order is fixed; picking columns only selects which ones appear.', 'fluent-cart-bulk-order'),
            ],
            'search' => [
                'label'   => esc_html__('Search box', 'fluent-cart-bulk-order'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => $this->ternaryOptions(
                    esc_html__('Show', 'fluent-cart-bulk-order'),
                    esc_html__('Hide', 'fluent-cart-bulk-order')
                ),
            ],
            'category' => [
                'label'       => esc_html__('Category', 'fluent-cart-bulk-order'),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'description' => esc_html__('Product category slug or term ID. Leave blank for the whole catalogue.', 'fluent-cart-bulk-order'),
            ],
            'roles' => $this->rolesControl(
                esc_html__('Comma-separated role slugs that may also see this table. Adds to the roles allowed in Settings; it never replaces them.', 'fluent-cart-bulk-order')
            ),
            'expand_variants' => [
                'label'   => esc_html__('Variants', 'fluent-cart-bulk-order'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => $this->ternaryOptions(
                    esc_html__('Expanded', 'fluent-cart-bulk-order'),
                    esc_html__('Collapsed', 'fluent-cart-bulk-order')
                ),
            ],
        ];
    }

    /**
     * The column picker's options, built from the canonical column list.
     *
     * Read from StoreDefaults::TABLE_COLUMNS rather than typed out, so a column
     * added there shows up here without anyone remembering this file. Only the
     * labels are local, because that list is slugs.
     *
     * @return array<string, string>
     */
    private static function columnOptions()
    {
        $labels = [
            'id'     => esc_html__('ID', 'fluent-cart-bulk-order'),
            'title'  => esc_html__('Title', 'fluent-cart-bulk-order'),
            'price'  => esc_html__('Price', 'fluent-cart-bulk-order'),
            'qty'    => esc_html__('Quantity', 'fluent-cart-bulk-order'),
            'action' => esc_html__('Action', 'fluent-cart-bulk-order'),
        ];

        $options = [];

        foreach (StoreDefaults::TABLE_COLUMNS as $column) {
            $options[$column] = isset($labels[$column]) ? $labels[$column] : $column;
        }

        return $options;
    }
}
