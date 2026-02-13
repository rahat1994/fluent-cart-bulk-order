<?php

namespace FluentCartBulkOrder;

use FluentCart\App\Modules\Integrations\BaseIntegrationManager;

defined('ABSPATH') || exit;

class BulkPricingIntegration extends BaseIntegrationManager
{
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
            'enabled' => 'yes',
            'name'    => '',
            'tiers'   => [],
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
            ],
            'button_require_list'                  => false,
            'should_hide_product_variation_selector' => false,
            'integration_title'                    => __('Bulk Pricing', 'fluent-cart-bulk-order'),
        ];
    }

    public function validateFeedData($data, $args = [])
    {
        $tiers = isset($data['tiers']) ? $data['tiers'] : [];
        $sanitized = [];

        foreach ($tiers as $tier) {
            $minQty        = max(1, intval($tier['min_qty'] ?? 0));
            $maxQty        = max(0, intval($tier['max_qty'] ?? 0));
            $discountValue = floatval($tier['discount_value'] ?? 0);
            $discountType  = 'percent';

            if ($discountValue < 0 || $discountValue > 100) {
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

        $data['tiers'] = $sanitized;

        return $data;
    }

    public function processAction($order, $eventData)
    {
        // No order-event processing needed
    }

    private function getTierRepeaterTemplate()
    {
        return '<div class="fcbo-tier-repeater">
            <div v-if="!settings.tiers || !settings.tiers.length" style="color:#999;margin-bottom:10px;">
                No discount tiers configured. Click "Add Tier" to create one.
            </div>
            <div v-for="(tier, index) in settings.tiers" :key="index"
                 style="display:flex;gap:10px;align-items:center;margin-bottom:8px;padding:10px;background:#f9f9f9;border-radius:4px;border:1px solid #eee;">
                <div style="flex:1;">
                    <label style="display:block;font-size:12px;color:#666;margin-bottom:2px;">Min Qty</label>
                    <el-input-number v-model="tier.min_qty" :min="1" :step="1" size="small" style="width:100%;" />
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:12px;color:#666;margin-bottom:2px;">Max Qty (0 = no limit)</label>
                    <el-input-number v-model="tier.max_qty" :min="0" :step="1" size="small" style="width:100%;" />
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:12px;color:#666;margin-bottom:2px;">Discount %</label>
                    <el-input-number v-model="tier.discount_value" :min="0" :max="100" :step="1" :precision="2" size="small" style="width:100%;" />
                </div>
                <el-button type="danger" size="small" icon="el-icon-delete" circle
                    @click="settings.tiers.splice(index, 1)"
                    style="margin-top:16px;" />
            </div>
            <el-button type="primary" size="small" @click="settings.tiers.push({min_qty:1,max_qty:0,discount_type:\'percent\',discount_value:0})">
                + Add Tier
            </el-button>
        </div>';
    }
}
