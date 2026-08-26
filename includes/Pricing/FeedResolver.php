<?php

namespace FluentCartBulkOrder\Pricing;

defined('ABSPATH') || exit;

/**
 * Which Integration Feed prices a variant, and what that feed says.
 *
 * ---------------------------------------------------------------------------
 * PRECEDENCE
 * ---------------------------------------------------------------------------
 *
 * A product-scoped feed always beats the store-wide one, and within a product
 * the first feed whose variant restriction matches wins. Resolving tiers and
 * resolving order rules therefore go through the SAME matchFeed() call: the feed
 * that prices a variant is always the feed that constrains it, and letting those
 * two drift apart would let a shopper be quoted a tier from one feed while being
 * held to another feed's case-pack.
 *
 * Role scoping picks WHICH tier list a qualifying shopper sees; it is not a gate.
 * Whether they qualify for bulk pricing at all is Gate 2 in AccessPolicy, and the
 * two are checked separately.
 */
class FeedResolver
{
    /**
     * Fetch all bulk pricing data in two batched queries.
     *
     * @param int[] $productIds
     * @return array{global: array, product: array<int, array>}
     */
    public static function allBulkPricing($productIds)
    {
        static $globalTiers = null;

        // 1. Global tiers (cached across calls within the same request)
        if ($globalTiers === null) {
            $globalTiers = [];
            $globalFeed = \FluentCart\App\Models\Meta::query()
                ->where('object_type', 'order_integration')
                ->where('meta_key', 'fcbo_bulk_pricing')
                ->first();

            if ($globalFeed) {
                $feedData     = $globalFeed->meta_value;
                $enabled      = !empty($feedData['enabled']) && $feedData['enabled'] === 'yes';
                $hasTiers     = !empty($feedData['tiers']);
                $hasRoleTiers = !empty($feedData['role_tiers']) && is_array($feedData['role_tiers']);
                // A feed carrying only order rules (no tiers at all) is still live
                // content — dropping it here would silently disable the rules.
                $rules        = OrderRules::normalize($feedData['order_rules'] ?? []);
                $hasRules     = OrderRules::areSet($rules);
                if ($enabled && ($hasTiers || $hasRoleTiers || $hasRules)) {
                    $globalTiers = [
                        'tiers'       => $hasTiers ? $feedData['tiers'] : [],
                        'role_tiers'  => $hasRoleTiers ? $feedData['role_tiers'] : [],
                        'order_rules' => $rules,
                    ];
                }
            }
        }

        // 2. Product-level tiers (batch query)
        $productFeeds = [];
        if (!empty($productIds)) {
            $feeds = \FluentCart\App\Models\ProductMeta::query()
                ->where('object_type', 'product_integration')
                ->where('meta_key', 'fcbo_bulk_pricing')
                ->whereIn('object_id', $productIds)
                ->get();

            foreach ($feeds as $feed) {
                $feedData     = $feed->meta_value;
                $hasTiers     = !empty($feedData['tiers']);
                $hasRoleTiers = !empty($feedData['role_tiers']) && is_array($feedData['role_tiers']);
                // As above: rules-only feeds must survive this filter.
                $rules        = OrderRules::normalize($feedData['order_rules'] ?? []);
                $hasRules     = OrderRules::areSet($rules);
                if (empty($feedData['enabled']) || $feedData['enabled'] !== 'yes' || (!$hasTiers && !$hasRoleTiers && !$hasRules)) {
                    continue;
                }

                $pid = (int) $feed->object_id;
                if (!isset($productFeeds[$pid])) {
                    $productFeeds[$pid] = [];
                }

                $variantIds = [];
                if (!empty($feedData['conditional_variation_ids']) && is_array($feedData['conditional_variation_ids'])) {
                    $variantIds = array_map('intval', $feedData['conditional_variation_ids']);
                }

                $productFeeds[$pid][] = [
                    'variant_ids' => $variantIds,
                    'tiers'       => $hasTiers ? $feedData['tiers'] : [],
                    'role_tiers'  => $hasRoleTiers ? $feedData['role_tiers'] : [],
                    'order_rules' => $rules,
                ];
            }
        }

        return [
            'global'  => $globalTiers,
            'product' => $productFeeds,
        ];
    }

    /**
     * Find the one feed that governs a variant: product-scoped beats global.
     *
     * The single home for feed precedence. resolveTiers() and resolveOrderRules()
     * both route through here so the two can never
     * disagree about which feed applies to a given variant.
     *
     * Precedence is winner-takes-all, matching the long-standing tier behavior: the
     * first matching product feed wins outright and the global feed is not consulted
     * for anything it left unset. A product feed therefore fully replaces the global
     * one rather than layering on top of it.
     *
     * @param array $pricingData From self::allBulkPricing().
     * @param int   $productId
     * @param int   $variantId
     * @return array|null The governing feed, or null when none applies.
     */
    public static function matchFeed($pricingData, $productId, $variantId)
    {
        // Check product-level feeds first
        if (!empty($pricingData['product'][$productId])) {
            foreach ($pricingData['product'][$productId] as $feed) {
                // Empty variant_ids means applies to all variants
                if (empty($feed['variant_ids']) || in_array((int) $variantId, $feed['variant_ids'], true)) {
                    return $feed;
                }
            }
        }

        // Fall back to the global feed
        if (!empty($pricingData['global'])) {
            return $pricingData['global'];
        }

        return null;
    }

    /**
     * Resolve the effective discount tiers for a specific product variant.
     *
     * Two-stage resolution:
     *   1. Feed precedence — a product-level feed wins over the global feed; the
     *      first feed whose variant scope matches applies (unchanged behavior).
     *   2. Role selection within that feed — if the feed carries role-scoped
     *      tier-sets, the first of the shopper's roles with a list wins; otherwise
     *      the feed's default `tiers` apply. See Tiers::selectRoleTierSet().
     *
     * $userRoles is optional: null/[] always yields the default set, so existing
     * call sites keep today's behavior until they opt in by passing roles (R6/R8).
     * Role selection composes with — never replaces — the Plan 002 qualification
     * gate, which still decides *whether* any bulk pricing applies.
     *
     * @param array         $pricingData From self::allBulkPricing()
     * @param int           $productId
     * @param int           $variantId
     * @param string[]|null $userRoles   Current user's role slugs (null = default set).
     * @return array Tier list (may be empty)
     */
    public static function resolveTiers($pricingData, $productId, $variantId, $userRoles = null)
    {
        $feed = self::matchFeed($pricingData, $productId, $variantId);

        return $feed ? Tiers::selectRoleTierSet($feed, $userRoles) : [];
    }

    /**
     * Resolve the effective order rules (minimum qty + case-pack step) for a variant.
     *
     * Shares self::matchFeed()'s precedence with self::resolveTiers(), so the feed
     * that prices a variant is always the feed that constrains its quantity. A
     * variant with no governing feed gets the no-op defaults.
     *
     * @param array $pricingData From self::allBulkPricing().
     * @param int   $productId
     * @param int   $variantId
     * @return array{min_qty:int, step:int}
     */
    public static function resolveOrderRules($pricingData, $productId, $variantId)
    {
        $feed = self::matchFeed($pricingData, $productId, $variantId);

        return OrderRules::normalize($feed['order_rules'] ?? []);
    }
}
