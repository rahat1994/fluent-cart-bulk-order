<?php

namespace FluentCartBulkOrder;

defined('ABSPATH') || exit;

/**
 * The plugin's one admin page: every store-wide default in a single place.
 *
 * ---------------------------------------------------------------------------
 * WHAT LIVES WHERE
 * ---------------------------------------------------------------------------
 *
 * This class owns the FORM only. The values behind it are split in two, and the
 * split is deliberate:
 *
 *   - The three role gates keep their own top-level options, named by
 *     AccessPolicy (`fcbo_apply_to_roles`, `fcbo_min_order_total`,
 *     `fcbo_min_order_total_roles`). They predate this page; renaming them would
 *     drop the saved policy of every store that already configured one.
 *
 *   - Everything else lives in one serialized array owned by StoreDefaults,
 *     which also holds the sanitizer and the precedence rule.
 *
 * The gate LOGIC is in AccessPolicy, which documents how the three gates differ.
 * Nothing here decides who gets what; it only stores what the owner chose.
 *
 * ---------------------------------------------------------------------------
 * ADDING A SECTION
 * ---------------------------------------------------------------------------
 *
 * registerSettings() is a list of section registrations, one private method per
 * section. To add one: add a key and its fallback to StoreDefaults::FALLBACKS,
 * validate it in StoreDefaults::sanitize(), then add a registerXSection() method
 * here and call it. No other file needs to change — the render sites read
 * through StoreDefaults::get().
 */
class Settings
{
    /**
     * Settings API group name (passed to settings_fields()).
     */
    const OPTION_GROUP = 'fcbo_settings';

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
            __('Fluent Cart Bulk Order', 'fluent-cart-bulk-order'),
            __('Bulk Order', 'fluent-cart-bulk-order'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Register every option, section, and field on the page.
     *
     * One method per section, in the order they appear. @see the class doc for
     * how to add another.
     */
    public function registerSettings()
    {
        $this->registerStoreDefaults();
        $this->registerSurfaceAccessSection();
        $this->registerPricingPolicySection();
        $this->registerMinOrderSection();
        $this->registerProductTableSection();
        $this->registerCheckoutSection();
    }

    /**
     * The single `fcbo_store_defaults` option behind every non-gate setting.
     *
     * One register_setting() with one array sanitizer, so validation for the
     * whole page has exactly one home. @see StoreDefaults::sanitize()
     */
    private function registerStoreDefaults()
    {
        register_setting(
            self::OPTION_GROUP,
            StoreDefaults::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [StoreDefaults::class, 'sanitize'],
                'default'           => [],
            ]
        );
    }

    /**
     * Gate 2 — who receives bulk pricing.
     */
    private function registerPricingPolicySection()
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

        add_settings_field(
            'fcbo_pricing_visibility_field',
            __('Who sees tier prices', 'fluent-cart-bulk-order'),
            [$this, 'renderPricingVisibilityField'],
            self::PAGE_SLUG,
            'fcbo_policy_section'
        );
    }

    /**
     * Gate 3 — the order-total floor and the roles it binds.
     */
    private function registerMinOrderSection()
    {
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
     * Gate 1 — who may open the surfaces at all.
     */
    private function registerSurfaceAccessSection()
    {
        add_settings_section(
            'fcbo_access_section',
            __('Surface Access', 'fluent-cart-bulk-order'),
            [$this, 'renderAccessIntro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'fcbo_allowed_extra_roles_field',
            __('Also allow these roles', 'fluent-cart-bulk-order'),
            [$this, 'renderAllowedExtraRolesField'],
            self::PAGE_SLUG,
            'fcbo_access_section'
        );
    }

    /**
     * Product-table display defaults.
     */
    private function registerProductTableSection()
    {
        add_settings_section(
            'fcbo_table_section',
            __('Product Table Defaults', 'fluent-cart-bulk-order'),
            [$this, 'renderTableIntro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'fcbo_table_per_page_field',
            __('Rows per page', 'fluent-cart-bulk-order'),
            [$this, 'renderTablePerPageField'],
            self::PAGE_SLUG,
            'fcbo_table_section'
        );

        add_settings_field(
            'fcbo_table_columns_field',
            __('Columns', 'fluent-cart-bulk-order'),
            [$this, 'renderTableColumnsField'],
            self::PAGE_SLUG,
            'fcbo_table_section'
        );

        add_settings_field(
            'fcbo_table_display_field',
            __('Display', 'fluent-cart-bulk-order'),
            [$this, 'renderTableDisplayField'],
            self::PAGE_SLUG,
            'fcbo_table_section'
        );
    }

    /**
     * Where the bulk order form sends a finished order.
     */
    private function registerCheckoutSection()
    {
        add_settings_section(
            'fcbo_checkout_section',
            __('Checkout', 'fluent-cart-bulk-order'),
            [$this, 'renderCheckoutIntro'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'fcbo_checkout_redirect_field',
            __('Send bulk orders to', 'fluent-cart-bulk-order'),
            [$this, 'renderCheckoutRedirectField'],
            self::PAGE_SLUG,
            'fcbo_checkout_section'
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
     */
    public function renderSectionIntro()
    {
        echo '<p>' . esc_html__(
            'Choose which user roles receive quantity-based bulk pricing. This governs two things at once: the discounted price charged in the cart, and whether the tier table is shown on public product pages.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Editable role slugs, with the admin include guaranteed.
     *
     * @return array<string, array> Role slug => role details.
     */
    private function getEditableRoles()
    {
        return AccessPolicy::editableRoles();
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

        $editable = $this->getEditableRoles();
        $names    = [];
        foreach ($roles as $slug) {
            $names[] = isset($editable[$slug]['name']) ? $editable[$slug]['name'] : $slug;
        }

        printf(
            '<p><strong>%s</strong></p>',
            esc_html(sprintf(
                /* translators: %s: comma-separated list of role names. */
                __('Only these roles see tier prices: %s.', 'fluent-cart-bulk-order'),
                implode(', ', $names)
            ))
        );

        echo '<p class="description">' . esc_html__(
            'Logged-out visitors do not. Administrators can always see the tier table so they can check it, but they are not given the discount at checkout — an administrator\'s own order is charged the same as any other shopper the policy excludes.',
            'fluent-cart-bulk-order'
        ) . '</p>';
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

    /* =====================================================================
     * Store-wide defaults — fields backed by the StoreDefaults option
     * ===================================================================== */

    /**
     * Name attribute for one key inside the StoreDefaults option array.
     *
     * Every field on this side of the page posts as `fcbo_store_defaults[key]`,
     * which is what lets one sanitizer see the whole submission at once.
     *
     * @param string $key Key within the option array.
     * @return string
     */
    private function defaultsName($key)
    {
        return StoreDefaults::OPTION . '[' . $key . ']';
    }

    /**
     * Section intro for Gate 1.
     */
    public function renderAccessIntro()
    {
        echo '<p>' . esc_html__(
            'Administrators and wholesale customers can always reach the bulk order form, the product table and saved orders. Add any other roles that should reach them too.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Checklist of roles that widen Gate 1 beyond the baseline.
     */
    public function renderAllowedExtraRolesField()
    {
        $selected = (array) StoreDefaults::get('allowed_extra_roles', []);
        $baseline = AccessPolicy::BASELINE_ROLES;
        $roles    = $this->getEditableRoles();

        // Hidden field so an all-unchecked submission still reaches the
        // sanitizer, which is what lets the owner clear the list.
        echo '<input type="hidden" name="' . esc_attr($this->defaultsName('allowed_extra_roles')) . '[]" value="" />';

        echo '<fieldset>';
        foreach ($roles as $slug => $details) {
            $label      = isset($details['name']) ? $details['name'] : $slug;
            $isBaseline = in_array($slug, $baseline, true);

            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s %4$s /> %5$s%6$s</label>',
                esc_attr($this->defaultsName('allowed_extra_roles')),
                esc_attr($slug),
                checked($isBaseline || in_array($slug, $selected, true), true, false),
                // The baseline is merged in code, so showing it unchecked would
                // be a lie and unchecking it would do nothing. Shown ticked and
                // disabled instead, which is the honest rendering of a floor.
                disabled($isBaseline, true, false),
                esc_html($label),
                $isBaseline ? ' <em>' . esc_html__('(always allowed)', 'fluent-cart-bulk-order') . '</em>' : ''
            );
        }
        echo '</fieldset>';

        echo '<p class="description">' . esc_html__(
            'This widens both the on-page surfaces and the REST endpoints behind them, so a role added here can actually load products. A shortcode "roles" attribute still widens one placement only, and does not reach the REST routes.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Section intro for the product-table defaults.
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

    /**
     * Section intro for the checkout target.
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
     * Render the settings page wrapper and form.
     */
    public function renderPage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Fluent Cart Bulk Order', 'fluent-cart-bulk-order') . '</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
