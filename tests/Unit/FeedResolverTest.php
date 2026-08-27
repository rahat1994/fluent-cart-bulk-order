<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Pricing\FeedResolver;
use PHPUnit\Framework\TestCase;

/**
 * Feed precedence — which Integration Feed governs a variant.
 *
 * Only the pure half is covered here. allBulkPricing() reads two database
 * tables and belongs in an integration test; matchFeed() and resolveOrderRules()
 * take the array it produces and decide from it, which is where the rules that
 * matter actually live.
 *
 * A note for anyone writing more of these: the store-wide feed sits under the
 * `global` key and product feeds under `product`, keyed by product id, with
 * their variant restriction in `variant_ids`. Getting those names wrong makes
 * every assertion silently fall through to the store-wide feed and pass for the
 * wrong reason.
 */
class FeedResolverTest extends TestCase
{
    /**
     * Two products: 42 has an unrestricted feed, 43 has one pinned to variant 99.
     */
    private function pricingData()
    {
        return [
            'global' => [
                'tiers'       => [['min_qty' => 10, 'discount_value' => 5]],
                'role_tiers'  => [],
                'order_rules' => ['min_qty' => 0, 'step' => 1],
            ],
            'product' => [
                42 => [
                    [
                        'variant_ids' => [],
                        'tiers'       => [['min_qty' => 10, 'discount_value' => 15]],
                        'role_tiers'  => [],
                        'order_rules' => ['min_qty' => 6, 'step' => 6],
                    ],
                ],
                43 => [
                    [
                        'variant_ids' => [99],
                        'tiers'       => [['min_qty' => 10, 'discount_value' => 25]],
                        'role_tiers'  => [],
                        'order_rules' => ['min_qty' => 12, 'step' => 12],
                    ],
                ],
            ],
        ];
    }

    public function testProductFeedBeatsStoreWide()
    {
        $feed = FeedResolver::matchFeed($this->pricingData(), 42, 1);

        $this->assertSame(15, $feed['tiers'][0]['discount_value'], 'the product feed wins');
    }

    public function testUnlistedProductFallsBackToStoreWide()
    {
        $feed = FeedResolver::matchFeed($this->pricingData(), 999, 1);

        $this->assertSame(5, $feed['tiers'][0]['discount_value'], 'no product feed means the store-wide one');
    }

    public function testVariantRestrictionIsHonoured()
    {
        $data = $this->pricingData();

        $this->assertSame(
            25,
            FeedResolver::matchFeed($data, 43, 99)['tiers'][0]['discount_value'],
            'the restricted feed applies to the variant it names'
        );
        $this->assertSame(
            5,
            FeedResolver::matchFeed($data, 43, 100)['tiers'][0]['discount_value'],
            'and not to any other variant of the same product'
        );
    }

    public function testEmptyVariantRestrictionMeansEveryVariant()
    {
        $data = $this->pricingData();

        foreach ([1, 2, 12345] as $variantId) {
            $this->assertSame(
                15,
                FeedResolver::matchFeed($data, 42, $variantId)['tiers'][0]['discount_value'],
                "an unrestricted product feed covers variant $variantId"
            );
        }
    }

    public function testNoFeedsAtAllResolvesToNothing()
    {
        $this->assertNull(FeedResolver::matchFeed([], 42, 1), 'a store with no feeds has no pricing');
        $this->assertNull(FeedResolver::matchFeed(['product' => []], 42, 1), 'nor one with an empty product map');
    }

    /**
     * The pairing that must never drift: the feed that PRICES a variant is the
     * feed that CONSTRAINS it. If tiers and rules ever resolved separately, a
     * shopper could be quoted one feed's discount while held to another feed's
     * case pack.
     */
    public function testRulesResolveThroughTheSameFeedAsTiers()
    {
        $data = $this->pricingData();

        foreach ([[42, 1, 6, 6], [43, 99, 12, 12], [999, 1, 0, 1]] as [$product, $variant, $min, $step]) {
            $feed  = FeedResolver::matchFeed($data, $product, $variant);
            $rules = FeedResolver::resolveOrderRules($data, $product, $variant);

            $this->assertSame(['min_qty' => $min, 'step' => $step], $rules);
            $this->assertSame(
                $feed['order_rules']['min_qty'],
                $rules['min_qty'],
                'the rules returned came from the feed that matched'
            );
        }
    }

    public function testResolveOrderRulesNormalisesWhateverTheFeedHolds()
    {
        $data = [
            'global' => ['tiers' => [], 'role_tiers' => [], 'order_rules' => ['step' => 0]],
        ];

        $this->assertSame(
            ['min_qty' => 0, 'step' => 1],
            FeedResolver::resolveOrderRules($data, 1, 1),
            'a broken stored rule cannot reach the arithmetic'
        );
    }
}
