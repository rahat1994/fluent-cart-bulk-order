<?php

namespace FluentCartBulkOrder\Shortcodes;

use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * `[fluent_cart_product_table]` — a paged, searchable browse table.
 *
 * The header is rendered in PHP and the body is filled by
 * assets/js/product-table.js from the /catalog REST route. That split is why
 * `columns` is resolved here AND passed to JS: both sides must agree on which
 * columns exist and in what order, or the header stops lining up with the rows.
 *
 * Attributes:
 *   per_page         Rows per page (1-100, default 5).
 *   columns          Comma-separated subset of id,title,price,qty,action.
 *   search           Show the search box (default true).
 *   category         Product category slug or term ID to restrict to.
 *   roles            Extra role slugs allowed to see this placement.
 *   expand_variants  Render variant accordions open by default.
 *
 * Two helpers stay as global functions in the main plugin file rather than moving
 * in here: fcbo_sanitize_category_param() is registered BY NAME as the /catalog
 * route's sanitize_callback, so the shortcode and the REST route must go on
 * sharing one implementation, and fcbo_parse_columns_attr() is named in
 * docs/plans as the column-resolution seam.
 *
 * @see \FluentCartBulkOrder\Shortcodes\AbstractShortcode For the gate order.
 */
class ProductTable extends AbstractShortcode
{
    /**
     * Canonical column order.
     *
     * Column REORDERING is a non-goal: `columns` selects a subset, and
     * fcbo_parse_columns_attr() intersects against this list so the resulting
     * order is always this one. That keeps the PHP header and the JS body
     * aligned without either side sorting.
     *
     * Aliases StoreDefaults::TABLE_COLUMNS, which the settings sanitizer also
     * validates against — the list is defined once, over there, because this
     * class only loads when FluentCart is active and the sanitizer runs at
     * admin_init regardless.
     */
    const ALL_COLUMNS = StoreDefaults::TABLE_COLUMNS;

    /**
     * Rows per page when the attribute is missing or unusable.
     */
    const DEFAULT_PER_PAGE = 5;

    /**
     * Upper bound on `per_page`, matching the /catalog route's own cap.
     */
    const MAX_PER_PAGE = 100;

    /**
     * @inheritDoc
     */
    protected function defaults()
    {
        // Store-wide defaults enter as shortcode_atts() DEFAULTS, never after
        // the attributes are read. That is what keeps precedence pointing one
        // way — attribute > stored default > the constants above — so an author
        // who wrote per_page="8" always gets 8, whatever the settings say.
        return [
            'per_page'        => StoreDefaults::get('table_per_page', self::DEFAULT_PER_PAGE),
            // Flattened to the comma string the attribute itself uses:
            // fcbo_parse_columns_attr() ignores anything that is not a string,
            // so handing it the stored array would drop the setting silently.
            'columns'         => implode(',', (array) StoreDefaults::get('table_columns', [])),
            'search'          => StoreDefaults::get('table_search', true) ? 'true' : 'false',
            'category'        => '',
            'roles'           => '',
            'expand_variants' => StoreDefaults::get('table_expand_variants', false) ? 'true' : 'false',
        ];
    }

    /**
     * @inheritDoc
     */
    protected function loginNotice()
    {
        return __('Please log in to access the product table.', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    protected function accessDeniedNotice()
    {
        return __('You do not have permission to access the product table.', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    protected function output(array $atts)
    {
        // Resolve display attributes (degrade safely to defaults on bad input).
        $per_page = $this->resolvePerPage($atts['per_page']);
        $columns  = fcbo_parse_columns_attr($atts['columns'], self::ALL_COLUMNS);

        $columnDefs = [
            'id'     => ['class' => 'fcbo-pt-col-id',     'label' => __('ID', 'fluent-cart-bulk-order')],
            'title'  => ['class' => 'fcbo-pt-col-title',  'label' => __('Title', 'fluent-cart-bulk-order')],
            'price'  => ['class' => 'fcbo-pt-col-price',  'label' => __('Price', 'fluent-cart-bulk-order')],
            'qty'    => ['class' => 'fcbo-pt-col-qty',    'label' => __('Quantity', 'fluent-cart-bulk-order')],
            'action' => ['class' => 'fcbo-pt-col-action', 'label' => __('Action', 'fluent-cart-bulk-order')],
        ];

        $colspan    = count($columns);
        $showSearch = filter_var($atts['search'], FILTER_VALIDATE_BOOLEAN);
        $category   = fcbo_sanitize_category_param($atts['category']);
        // When true, variant accordions render open by default (variants shown separately).
        $expandVariants = filter_var($atts['expand_variants'], FILTER_VALIDATE_BOOLEAN);

        // Single-product assets too, on top of the cart bundle: rows expand into
        // FluentCart's variant selection UI.
        $this->loadFluentCartCartAssets();
        $this->loadFluentCartSingleProductAssets();

        wp_enqueue_style(
            'fcbo-product-table',
            FCBO_URL . 'assets/css/product-table.css',
            [],
            FCBO_VERSION
        );

        wp_enqueue_script(
            'fcbo-product-table',
            FCBO_URL . 'assets/js/product-table.js',
            ['fluent-cart-app'],
            FCBO_VERSION,
            true
        );

        wp_localize_script('fcbo-product-table', 'fcboPtConfig', array_merge($this->restConfig(), [
            'currency_sign'   => $this->currencySign(),
            'per_page'        => $per_page,
            'columns'         => $columns,
            'category'        => $category,
            'expand_variants' => $expandVariants ? 1 : 0,
            // Shopper-facing sentences, translated server-side. The JS only
            // fills {placeholders}. @see fcbo_product_table_strings()
            'i18n'            => fcbo_product_table_strings(),
        ]));

        ob_start();
        ?>
        <div id="fcbo-product-table" class="fcbo-pt-wrap">
            <?php if ($showSearch) : ?>
            <div class="fcbo-pt-toolbar">
                <input type="text" id="fcbo-pt-search" class="fcbo-pt-search"
                       placeholder="<?php esc_attr_e('Search products...', 'fluent-cart-bulk-order'); ?>" />
            </div>
            <?php endif; ?>

            <div class="fcbo-pt-table-scroll">
                <table class="fcbo-pt-table">
                    <thead>
                        <tr>
                            <?php foreach ($columns as $col) : ?>
                            <th class="<?php echo esc_attr($columnDefs[$col]['class']); ?>"><?php echo esc_html($columnDefs[$col]['label']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="fcbo-pt-tbody">
                        <tr><td colspan="<?php echo esc_attr($colspan); ?>" class="fcbo-pt-loading"><?php esc_html_e('Loading products...', 'fluent-cart-bulk-order'); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="fcbo-pt-pagination">
                <button type="button" id="fcbo-pt-prev" class="fcbo-pt-page-btn" disabled>&laquo; <?php esc_html_e('Prev', 'fluent-cart-bulk-order'); ?></button>
                <span id="fcbo-pt-page-info" class="fcbo-pt-page-info"><?php esc_html_e('Page 1 of 1', 'fluent-cart-bulk-order'); ?></span>
                <button type="button" id="fcbo-pt-next" class="fcbo-pt-page-btn" disabled><?php esc_html_e('Next', 'fluent-cart-bulk-order'); ?> &raquo;</button>
            </div>

            <div id="fcbo-pt-status" class="fcbo-pt-status" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Clamp `per_page` into the range the /catalog route will actually honor.
     *
     * absint() turns non-numeric input into 0, which then falls back to the
     * default rather than rendering an empty table.
     *
     * @param mixed $value Raw attribute value.
     * @return int Between 1 and MAX_PER_PAGE.
     */
    private function resolvePerPage($value)
    {
        $perPage = absint($value);

        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE; // Non-numeric or zero → default.
        }

        return min(self::MAX_PER_PAGE, max(1, $perPage));
    }
}
