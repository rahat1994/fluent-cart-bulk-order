<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Analytics\TierSignature;
use PHPUnit\Framework\TestCase;

/**
 * The identity of a Bulk Pricing Tier.
 *
 * This is what tier utilization groups by, and every way it can be wrong is
 * silent. A key that is not stable across two identical tiers splits one tier
 * into several rows, each with a fraction of the real usage, and an owner reads
 * that as "nobody reaches this tier". A key that is TOO stable merges a 10%
 * tier with the 12% tier that replaced it and reports a discount rate no order
 * was ever placed at.
 */
class TierSignatureTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function tier(array $overrides = [])
    {
        return array_merge([
            'min_qty'        => 50,
            'max_qty'        => 99,
            'discount_type'  => 'percent',
            'discount_value' => 10,
        ], $overrides);
    }

    public function testTheSameTierAlwaysHashesTheSameWay()
    {
        $a = TierSignature::key($this->tier(), TierSignature::SCOPE_GLOBAL, 0);
        $b = TierSignature::key($this->tier(), TierSignature::SCOPE_GLOBAL, 0);

        $this->assertSame($a, $b);
        $this->assertSame(32, strlen($a));
    }

    /**
     * The stored feed can hold a discount value as an int, a float or a
     * numeric string depending on how it was saved. All three are one tier.
     */
    public function testNumericFormattingOfTheDiscountDoesNotChangeTheIdentity()
    {
        $int = TierSignature::key($this->tier(['discount_value' => 10]), TierSignature::SCOPE_GLOBAL, 0);
        $float = TierSignature::key($this->tier(['discount_value' => 10.0]), TierSignature::SCOPE_GLOBAL, 0);
        $string = TierSignature::key($this->tier(['discount_value' => '10.00']), TierSignature::SCOPE_GLOBAL, 0);

        $this->assertSame($int, $float);
        $this->assertSame($int, $string);
    }

    /**
     * A missing type is a percent tier everywhere else in the plugin, so it has
     * to be one here — otherwise the same tier hashes two ways depending on
     * whether the key happened to be present in the stored feed.
     */
    public function testAMissingDiscountTypeIsTheSameTierAsAnExplicitPercent()
    {
        $implicit = $this->tier();
        unset($implicit['discount_type']);

        $this->assertSame(
            TierSignature::key($this->tier(['discount_type' => 'percent']), TierSignature::SCOPE_GLOBAL, 0),
            TierSignature::key($implicit, TierSignature::SCOPE_GLOBAL, 0)
        );
    }

    /**
     * Editing the discount makes a different tier. This is the behaviour the
     * class docblock argues for at length: the orders under the old value were
     * charged the old value.
     */
    public function testChangingAnythingThatChangesThePriceChangesTheIdentity()
    {
        $base = TierSignature::key($this->tier(), TierSignature::SCOPE_GLOBAL, 0);

        $this->assertNotSame($base, TierSignature::key($this->tier(['discount_value' => 12]), TierSignature::SCOPE_GLOBAL, 0));
        $this->assertNotSame($base, TierSignature::key($this->tier(['min_qty' => 40]), TierSignature::SCOPE_GLOBAL, 0));
        $this->assertNotSame($base, TierSignature::key($this->tier(['max_qty' => 199]), TierSignature::SCOPE_GLOBAL, 0));
        $this->assertNotSame($base, TierSignature::key($this->tier(['discount_type' => 'amount_off']), TierSignature::SCOPE_GLOBAL, 0));
    }

    /**
     * The same numbers on a store-wide feed and on one product's feed are two
     * different tiers, because an owner sees them in two different places.
     */
    public function testScopeAndProductArePartOfTheIdentity()
    {
        $global = TierSignature::key($this->tier(), TierSignature::SCOPE_GLOBAL, 0);
        $product = TierSignature::key($this->tier(), TierSignature::SCOPE_PRODUCT, 7);
        $otherProduct = TierSignature::key($this->tier(), TierSignature::SCOPE_PRODUCT, 8);

        $this->assertNotSame($global, $product);
        $this->assertNotSame($product, $otherProduct);
    }

    /**
     * A per-role price list is a separate price list, so its tiers are separate
     * tiers even when the numbers match.
     */
    public function testTheRoleScopedSetIsPartOfTheIdentity()
    {
        $everyone = TierSignature::key($this->tier(), TierSignature::SCOPE_GLOBAL, 0, '');
        $wholesale = TierSignature::key($this->tier(), TierSignature::SCOPE_GLOBAL, 0, 'wholesale-customer');

        $this->assertNotSame($everyone, $wholesale);
    }

    public function testColumnsAndKeyAlwaysDescribeTheSameTier()
    {
        $columns = TierSignature::columns($this->tier(), TierSignature::SCOPE_PRODUCT, 7, 'wholesale-customer');

        $this->assertSame(
            TierSignature::key($this->tier(), TierSignature::SCOPE_PRODUCT, 7, 'wholesale-customer'),
            $columns['tier_key']
        );
        $this->assertSame(TierSignature::SCOPE_PRODUCT, $columns['tier_scope']);
        $this->assertSame(7, $columns['tier_product_id']);
        $this->assertSame('wholesale-customer', $columns['tier_role']);
        $this->assertSame(50, $columns['tier_min_qty']);
        $this->assertSame(99, $columns['tier_max_qty']);
        $this->assertSame('percent', $columns['tier_type']);
        $this->assertSame(10.0, $columns['tier_value']);
    }

    /**
     * An unrecognised scope must not become a third scope. Anything that is not
     * a product feed is the store-wide one.
     */
    public function testAnUnknownScopeCollapsesToGlobal()
    {
        $columns = TierSignature::columns($this->tier(), 'somewhere-else', 0);

        $this->assertSame(TierSignature::SCOPE_GLOBAL, $columns['tier_scope']);
    }

    /**
     * `max_qty` of 0 is how the feed stores "and up". Printing it as a range
     * ending in zero would read as a broken row.
     */
    public function testAnOpenEndedTierReadsAsOrMore()
    {
        $this->assertSame('500 or more', TierSignature::rangeLabel(500, 0));
        $this->assertSame('50 to 99', TierSignature::rangeLabel(50, 99));
    }

    public function testANegativeQuantityDoesNotLeakIntoTheLabel()
    {
        $this->assertSame('0 or more', TierSignature::rangeLabel(-5, -1));
    }
}
