<?php

namespace FluentCartBulkOrder;

use FluentCart\App\Modules\Integrations\BaseIntegrationManager;

defined('ABSPATH') || exit;

class BulkPricingIntegration extends BaseIntegrationManager
{
    /**
     * Allowed discount types. `percent` is a percentage (0..100); the two money
     * types are entered and stored in MAJOR currency units (e.g. dollars) — the
     * single major-units -> cents conversion lives in the effective-price helpers
     * (fcbo_apply_tier_to_price + its two JS mirrors), never in storage. See the
     * "Cents vs major units" note in the feature plan for why storage stays major.
     */
    const DISCOUNT_TYPES = ['percent', 'fixed_unit_price', 'amount_off'];

    public function __construct()
    {
        parent::__construct(
            __('Bulk Pricing', 'fluent-cart-bulk-order'),
            'fcbo_bulk_pricing',
            20
        );

        $this->description = __('Configure quantity-based discount tiers for the bulk order form.', 'fluent-cart-bulk-order');
        $this->category = 'core';
        $this->disableGlobalSettings = true;
        $this->scopes = ['global', 'product'];
        $this->logo = FCBO_URL . 'assets/images/bulk-pricing-icon.svg';
    }

    public function isConfigured()
    {
        return true;
    }

    public function getApiSettings()
    {
        return ['status' => true];
    }

    public function getIntegrationDefaults($settings)
    {
        return [
            'enabled'    => 'yes',
            'name'       => '',
            'tiers'      => [],
            // Keyed map of role-slug => tier-set (Part B). Defaulted to an object so
            // `settings.role_tiers` always exists on the Vue model — reassigning it in
            // the repeater is then reactive under both Vue 2 and Vue 3. Empty groups
            // are dropped at save time (see validateFeedData), so a feed the owner
            // never scoped to a role persists the legacy `{tiers: [...]}` shape.
            'role_tiers' => (object) [],
        ];
    }

    public function getSettingsFields($settings, $args = [])
    {
        return [
            'fields' => [
                'name' => [
                    'key'         => 'name',
                    'label'       => __('Feed Name', 'fluent-cart-bulk-order'),
                    'required'    => true,
                    'placeholder' => __('e.g. Default Bulk Pricing', 'fluent-cart-bulk-order'),
                    'component'   => 'text',
                ],
                'tiers' => [
                    'key'             => 'tiers',
                    'label'           => __('Discount Tiers', 'fluent-cart-bulk-order'),
                    'component'       => 'custom_component',
                    'render_template' => $this->getTierRepeaterTemplate(),
                ],
                // MUST be declared as its own field: FluentCart persists only keys
                // that appear here. IntegrationHelper::validateAndFormatIntegrationFeedSettings()
                // builds $validKeys from these field keys and runs
                // Arr::only($integration, $validKeys) BEFORE validateFeedData() is
                // filtered in — so an undeclared key is silently dropped on save.
                'role_tiers' => [
                    'key'             => 'role_tiers',
                    'label'           => __('Role-specific Pricing', 'fluent-cart-bulk-order'),
                    'component'       => 'custom_component',
                    'render_template' => $this->getRoleTiersTemplate(),
                ],
            ],
            'button_require_list'                  => false,
            'should_hide_product_variation_selector' => false,
            'integration_title'                    => __('Bulk Pricing', 'fluent-cart-bulk-order'),
        ];
    }

    public function validateFeedData($data, $args = [])
    {
        // Default tier-set (everyone).
        $data['tiers'] = $this->sanitizeTiers(isset($data['tiers']) ? $data['tiers'] : []);

        // Part B: per-role tier-sets. Each group is validated with the exact same
        // per-tier path as the default set, keyed by a sanitized, editable role slug.
        // Unknown/empty slugs and groups that sanitize to zero tiers are dropped, so a
        // feed with no configured role pricing persists today's `{tiers: [...]}` shape.
        $roleTiers = [];
        $submitted = isset($data['role_tiers']) ? (array) $data['role_tiers'] : [];
        if (!empty($submitted)) {
            $editable = array_keys($this->getEditableRoles());
            foreach ($submitted as $slug => $tiers) {
                $slug = sanitize_key($slug);
                if ($slug === '' || !in_array($slug, $editable, true)) {
                    continue;
                }
                $clean = $this->sanitizeTiers(is_array($tiers) ? $tiers : []);
                if (!empty($clean)) {
                    $roleTiers[$slug] = $clean;
                }
            }
        }

        if (!empty($roleTiers)) {
            $data['role_tiers'] = $roleTiers;
        } else {
            unset($data['role_tiers']);
        }

        $data['event_trigger'] = $this->describeVariationScope($data);

        return $data;
    }

    /**
     * Human-readable labels for the variations this feed is scoped to.
     *
     * The product Integrations list renders its "Triggers" column directly from
     * `feed.event_trigger`. Bulk pricing has no order-event triggers, so that
     * column is otherwise blank — while the thing that actually decides when this
     * feed applies IS the variation selection. So we surface those names there.
     *
     * Reusing this key is safe: both dispatch paths that read it
     * (IntegrationEventListener and GlobalNotificationHandler::triggerNotification)
     * test it with in_array($hook, $triggers) against full hook strings such as
     * "fluent_cart/order_paid", which a variation title will never equal — and
     * processAction() is a no-op for this integration in any case.
     *
     * Note: this is computed at save time, so an existing feed shows its
     * variations in the list only after it is next saved.
     *
     * @param array $data Feed data (post-whitelist, so conditional_variation_ids is present).
     * @return string[] Labels for the Triggers column.
     */
    private function describeVariationScope($data)
    {
        $ids = [];
        if (!empty($data['conditional_variation_ids']) && is_array($data['conditional_variation_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $data['conditional_variation_ids'])));
        }

        // Empty selection means the feed runs for every variation.
        if (empty($ids)) {
            return [__('All variations', 'fluent-cart-bulk-order')];
        }

        $variants = \FluentCart\App\Models\ProductVariation::query()
            ->whereIn('id', $ids)
            ->get();

        $names = [];
        foreach ($variants as $variant) {
            $title = $variant->variation_title;
            $names[] = ($title === null || $title === '') ? '#' . $variant->id : $title;
        }

        // A variation that no longer exists simply drops out of the list.
        sort($names);

        return $names;
    }

    /**
     * Sanitize a single tier-set (the default set or one role group).
     *
     * Type-appropriate validation: percent stays bounded 0..100; money types
     * accept any non-negative amount, stored in major currency units. Values are
     * kept in the unit the owner entered — see DISCOUNT_TYPES.
     *
     * @param mixed $tiers Raw tier array from the repeater.
     * @return array Sanitized, min_qty-sorted tier list.
     */
    private function sanitizeTiers($tiers)
    {
        $sanitized = [];

        foreach ((array) $tiers as $tier) {
            $minQty        = max(1, intval($tier['min_qty'] ?? 0));
            $maxQty        = max(0, intval($tier['max_qty'] ?? 0));
            $discountValue = floatval($tier['discount_value'] ?? 0);

            $discountType = isset($tier['discount_type']) ? (string) $tier['discount_type'] : 'percent';
            if (!in_array($discountType, self::DISCOUNT_TYPES, true)) {
                $discountType = 'percent';
            }

            if ($discountType === 'percent') {
                if ($discountValue < 0 || $discountValue > 100) {
                    continue;
                }
            } elseif ($discountValue < 0) {
                // Money types: any non-negative amount is valid.
                continue;
            }

            if ($maxQty > 0 && $maxQty < $minQty) {
                continue;
            }

            $sanitized[] = [
                'min_qty'        => $minQty,
                'max_qty'        => $maxQty,
                'discount_type'  => $discountType,
                'discount_value' => round($discountValue, 2),
            ];
        }

        usort($sanitized, function ($a, $b) {
            return $a['min_qty'] - $b['min_qty'];
        });

        return $sanitized;
    }

    public function processAction($order, $eventData)
    {
        // No order-event processing needed
    }

    /**
     * Editable role slugs, with the admin include guaranteed.
     *
     * get_editable_roles() lives in wp-admin/includes/user.php, which is NOT
     * loaded on REST/AJAX requests — and feed saves run through one. Without this
     * guard the save path can fatal on an undefined function.
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

    private function getTierRepeaterTemplate()
    {
        return '<div class="fcbo-tier-repeater">
            <p class="fcbo-tier-hint">' . esc_html__('Applies to every qualifying customer unless a role-specific list below overrides it.', 'fluent-cart-bulk-order') . '</p>'
            . $this->getTierRowsMarkup('settings.tiers', 'default')
            . '</div>';
    }

    /**
     * Vue markup for one tier-set editor bound to a JS collection expression.
     *
     * Reused by the default set and every role group so the row fields stay in
     * lock-step. `$collection` is the JS expression for the tier array (e.g.
     * `settings.tiers` or `settings.role_tiers['wholesale-vip']`); `$keyPrefix`
     * disambiguates the v-for keys between editors.
     *
     * @param string $collection JS expression resolving to the tier array.
     * @param string $keyPrefix  Unique prefix for :key.
     * @return string
     */
    private function getTierRowsMarkup($collection, $keyPrefix)
    {
        $newTier = "{min_qty:1,max_qty:0,discount_type:'percent',discount_value:0}";

        return '
            <div v-if="!' . $collection . ' || !' . $collection . '.length" class="fcbo-tier-empty">
                ' . esc_html__('No discount tiers configured. Click "Add Tier" to create one.', 'fluent-cart-bulk-order') . '
            </div>
            <div v-for="(tier, index) in ' . $collection . '" :key="\'' . $keyPrefix . '-\'+index" class="fcbo-tier-row">
                <div class="fcbo-tier-field">
                    <label>' . esc_html__('Min Qty', 'fluent-cart-bulk-order') . '</label>
                    <el-input-number v-model="tier.min_qty" :min="1" :step="1" size="small" />
                </div>
                <div class="fcbo-tier-field">
                    <label>' . esc_html__('Max Qty (0 = no limit)', 'fluent-cart-bulk-order') . '</label>
                    <el-input-number v-model="tier.max_qty" :min="0" :step="1" size="small" />
                </div>
                <div class="fcbo-tier-field">
                    <label>' . esc_html__('Type', 'fluent-cart-bulk-order') . '</label>
                    <el-select v-model="tier.discount_type" size="small" style="width:150px">
                        <el-option label="' . esc_attr__('Percent off (%)', 'fluent-cart-bulk-order') . '" value="percent"></el-option>
                        <el-option label="' . esc_attr__('Fixed unit price', 'fluent-cart-bulk-order') . '" value="fixed_unit_price"></el-option>
                        <el-option label="' . esc_attr__('Amount off', 'fluent-cart-bulk-order') . '" value="amount_off"></el-option>
                    </el-select>
                </div>
                <div class="fcbo-tier-field">
                    <label v-if="tier.discount_type === \'percent\'">' . esc_html__('Discount %', 'fluent-cart-bulk-order') . '</label>
                    <label v-else-if="tier.discount_type === \'fixed_unit_price\'">' . esc_html__('Unit price', 'fluent-cart-bulk-order') . '</label>
                    <label v-else>' . esc_html__('Amount off', 'fluent-cart-bulk-order') . '</label>
                    <el-input-number v-if="tier.discount_type === \'percent\'" v-model="tier.discount_value" :min="0" :max="100" :step="1" :precision="2" size="small" />
                    <el-input-number v-else v-model="tier.discount_value" :min="0" :step="0.5" :precision="2" size="small" />
                </div>
                <el-button type="danger" size="small" class="fcbo-tier-remove" @click="' . $collection . '.splice(index, 1)">&times;</el-button>
            </div>
            <el-button type="primary" size="small" @click="' . $collection . '.push(' . $newTier . ')">
                ' . esc_html__('+ Add Tier', 'fluent-cart-bulk-order') . '
            </el-button>';
    }

    /**
     * Vue markup for the optional per-role tier-sets (Part B).
     *
     * One collapsible group per editable role, generated server-side so the role
     * slugs are known at build time (no dynamic Vue data property is needed).
     * Enable/remove reassign `settings.role_tiers` to a fresh object — a single
     * expression that is reactive under Vue 2 and Vue 3 alike — while tier edits
     * use array push/splice on the pre-existing group.
     *
     * @return string
     */
    private function getRoleTiersTemplate()
    {
        $roles = $this->getEditableRoles();
        if (empty($roles)) {
            return '';
        }

        $newTier = "{min_qty:1,max_qty:0,discount_type:'percent',discount_value:0}";

        $groups = '';
        foreach ($roles as $slug => $details) {
            $slug  = sanitize_key($slug);
            if ($slug === '') {
                continue;
            }
            $name  = isset($details['name']) ? $details['name'] : $slug;
            $col   = "settings.role_tiers['" . $slug . "']";
            $has   = "settings.role_tiers && settings.role_tiers['" . $slug . "']";
            // Enable: add the slug with one starter tier, preserving existing groups.
            $enable = "settings.role_tiers = Object.assign({}, settings.role_tiers, {'" . $slug . "':[" . $newTier . "]})";
            // Remove: rebuild the map without this slug (single-expression, Vue 2/3 safe).
            $remove = "settings.role_tiers = Object.keys(settings.role_tiers || {}).reduce(function(o,k){ if(k!=='" . $slug . "'){ o[k]=settings.role_tiers[k]; } return o; }, {})";

            $groups .= '
                <div class="fcbo-role-group">
                    <div class="fcbo-role-group-head">
                        <strong>' . esc_html($name) . '</strong>
                        <el-button v-if="!(' . $has . ')" size="small" @click="' . $enable . '">'
                            . esc_html__('+ Add role pricing', 'fluent-cart-bulk-order') . '</el-button>
                        <el-button v-else type="danger" plain size="small" @click="' . $remove . '">'
                            . esc_html__('Remove', 'fluent-cart-bulk-order') . '</el-button>
                    </div>
                    <div v-if="' . $has . '" class="fcbo-role-group-body">
                        ' . $this->getTierRowsMarkup($col, 'role-' . $slug) . '
                    </div>
                </div>';
        }

        return '
            <div class="fcbo-tier-repeater fcbo-role-tiers">
                <p class="fcbo-tier-hint">' . esc_html__('Optional. Give specific roles their own price list. A shopper falls back to the default Discount Tiers above when their role has no list.', 'fluent-cart-bulk-order') . '</p>'
                . $groups
                . '</div>';
    }
}
