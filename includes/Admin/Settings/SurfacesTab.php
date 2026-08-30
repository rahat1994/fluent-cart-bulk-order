<?php

namespace FluentCartBulkOrder\Admin\Settings;

use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * Surfaces — how the ordering surfaces themselves are drawn.
 *
 * Everything here is a Store Default in the strict sense: a value that only
 * ever supplies the DEFAULT a shortcode attribute may override, never a rule
 * that overrides one. @see \FluentCartBulkOrder\StoreDefaults for the
 * precedence, which is one-directional and must stay that way.
 */
class SurfacesTab extends Tab
{
    /**
     * @inheritDoc
     */
    public function slug()
    {
        return Tabs::SURFACES;
    }

    /**
     * @inheritDoc
     */
    public function label()
    {
        return __('Surfaces', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    public function defaultsKeys()
    {
        return [
            'table_per_page',
            'table_columns',
            'table_search',
            'table_expand_variants',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerSections($page)
    {
        add_settings_section(
            'fcbo_table_section',
            __('Product Table Defaults', 'fluent-cart-bulk-order'),
            [$this, 'renderTableIntro'],
            $page
        );

        add_settings_field(
            'fcbo_table_per_page_field',
            __('Rows per page', 'fluent-cart-bulk-order'),
            [$this, 'renderTablePerPageField'],
            $page,
            'fcbo_table_section'
        );

        add_settings_field(
            'fcbo_table_columns_field',
            __('Columns', 'fluent-cart-bulk-order'),
            [$this, 'renderTableColumnsField'],
            $page,
            'fcbo_table_section'
        );

        add_settings_field(
            'fcbo_table_display_field',
            __('Display', 'fluent-cart-bulk-order'),
            [$this, 'renderTableDisplayField'],
            $page,
            'fcbo_table_section'
        );
    }

    /**
     * Section intro for the product-table defaults.
     *
     * @return void
     */
    public function renderTableIntro()
    {
        echo '<p>' . esc_html__(
            'Defaults for every [fluent_cart_product_table] on the site. A shortcode attribute always overrides what you set here, for that placement only.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Rows-per-page number input.
     *
     * @return void
     */
    public function renderTablePerPageField()
    {
        printf(
            '<input type="number" name="%1$s" value="%2$d" min="%3$d" max="%4$d" step="1" class="small-text" />',
            esc_attr($this->defaultsName('table_per_page')),
            (int) StoreDefaults::get('table_per_page', StoreDefaults::FALLBACKS['table_per_page']),
            (int) StoreDefaults::MIN_PER_PAGE,
            (int) StoreDefaults::MAX_PER_PAGE
        );

        printf(
            '<p class="description">%s</p>',
            esc_html(
                sprintf(
                    /* translators: 1: lowest allowed value, 2: highest allowed value, 3: the default. */
                    __('Between %1$d and %2$d. Default %3$d. Counts variant rows, not products.', 'fluent-cart-bulk-order'),
                    StoreDefaults::MIN_PER_PAGE,
                    StoreDefaults::MAX_PER_PAGE,
                    StoreDefaults::FALLBACKS['table_per_page']
                )
            )
        );
    }

    /**
     * Column checklist, in canonical order.
     *
     * @return void
     */
    public function renderTableColumnsField()
    {
        $selected = (array) StoreDefaults::get('table_columns', []);
        $labels   = [
            'id'     => __('ID', 'fluent-cart-bulk-order'),
            'title'  => __('Title', 'fluent-cart-bulk-order'),
            'price'  => __('Price', 'fluent-cart-bulk-order'),
            'qty'    => __('Quantity', 'fluent-cart-bulk-order'),
            'action' => __('Action', 'fluent-cart-bulk-order'),
        ];

        echo '<input type="hidden" name="' . esc_attr($this->defaultsName('table_columns')) . '[]" value="" />';

        echo '<fieldset>';
        foreach (StoreDefaults::TABLE_COLUMNS as $column) {
            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr($this->defaultsName('table_columns')),
                esc_attr($column),
                // An empty stored list means "all columns", so an unconfigured
                // store shows every box ticked rather than none.
                checked(!$selected || in_array($column, $selected, true), true, false),
                esc_html(isset($labels[$column]) ? $labels[$column] : $column)
            );
        }
        echo '</fieldset>';

        echo '<p class="description">' . esc_html__(
            'Columns always appear in this order. Selecting all of them is the same as selecting none: the table shows everything.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * The two display toggles.
     *
     * @return void
     */
    public function renderTableDisplayField()
    {
        printf(
            '<fieldset><label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr($this->defaultsName('table_search')),
            checked((bool) StoreDefaults::get('table_search', true), true, false),
            esc_html__('Show the search box', 'fluent-cart-bulk-order')
        );

        printf(
            '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></fieldset>',
            esc_attr($this->defaultsName('table_expand_variants')),
            checked((bool) StoreDefaults::get('table_expand_variants', false), true, false),
            esc_html__('Open variant rows by default', 'fluent-cart-bulk-order')
        );

        echo '<p class="description">' . esc_html__(
            'Opening variant rows suits small catalogues; leave it off when products have many variants.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }
}
