<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Cart\SubscriptionRule;
use PHPUnit\Framework\TestCase;

/**
 * The rules this plugin restates on FluentCart's behalf.
 *
 * Worth pinning because none of these rules is ours. Each one is a copy of a
 * refusal that lives in FluentCart's source, repeated here so our surfaces can
 * warn a shopper before the cart does. A copy that drifts is worse than no copy
 * at all: the surface would promise something the cart then refuses, which is
 * exactly the state issue #34 reported.
 *
 * @see \FluentCartBulkOrder\Cart\SubscriptionRule
 */
class SubscriptionRuleTest extends TestCase
{
    /**
     * The value FluentCart actually stores in
     * `wp_fct_product_variations.payment_type`.
     */
    public function testRecognisesTheStoredValue()
    {
        $this->assertTrue(SubscriptionRule::isRecurring('subscription'));
    }

    /**
     * Everything that is not that value is a one-time product, including the
     * fallback our own REST payloads substitute for an empty column.
     */
    public function testTreatsOnetimeAndFriendsAsNotRecurring()
    {
        $this->assertFalse(SubscriptionRule::isRecurring('onetime'));
        $this->assertFalse(SubscriptionRule::isRecurring('one_time'));
        $this->assertFalse(SubscriptionRule::isRecurring('signup_fee'));
    }

    /**
     * A missing column, a null model attribute and an empty payload value all
     * arrive here. None of them may read as recurring, or every ordinary
     * product would lose its quantity box.
     */
    public function testTreatsAnAbsentValueAsNotRecurring()
    {
        $this->assertFalse(SubscriptionRule::isRecurring(null));
        $this->assertFalse(SubscriptionRule::isRecurring(''));
        $this->assertFalse(SubscriptionRule::isRecurring([]));
    }

    /**
     * The value reaches this class from a model, from a REST payload the
     * browser sent back, and from a stored quote line. Case and padding are not
     * guaranteed across all three, and the direction of the mistake matters:
     * reading " Subscription" as one-time would let a shopper build a basket
     * the cart refuses.
     */
    public function testIgnoresCaseAndPadding()
    {
        $this->assertTrue(SubscriptionRule::isRecurring(' Subscription '));
        $this->assertTrue(SubscriptionRule::isRecurring('SUBSCRIPTION'));
    }

    /**
     * The copy of CartResource.php:63-65. Whatever the shopper typed, the line
     * is billed for one.
     */
    public function testRecurringQuantityIsAlwaysOne()
    {
        $this->assertSame(1, SubscriptionRule::settledQuantity('subscription', 5));
        $this->assertSame(1, SubscriptionRule::settledQuantity('subscription', 1));
        $this->assertSame(1, SubscriptionRule::settledQuantity('subscription', 0));
    }

    /**
     * A one-time line is handed straight back. Order Rules decide those, and
     * this class must not quietly become a second opinion about them.
     */
    public function testOnetimeQuantityIsLeftAlone()
    {
        $this->assertSame(12, SubscriptionRule::settledQuantity('onetime', 12));
    }

    /**
     * Zero and negatives still floor at one rather than passing through. A
     * caller asking what a line will be billed for is asking about a line that
     * exists.
     */
    public function testOnetimeQuantityFloorsAtOne()
    {
        $this->assertSame(1, SubscriptionRule::settledQuantity('onetime', 0));
        $this->assertSame(1, SubscriptionRule::settledQuantity('onetime', -4));
    }

    /**
     * The case the Product Table now refuses before the cart does — the copy of
     * Cart.php:443.
     */
    public function testMixingBothKindsIsRefused()
    {
        $this->assertTrue(SubscriptionRule::mixesTypes(['onetime', 'subscription']));
        $this->assertTrue(SubscriptionRule::mixesTypes(['subscription', 'onetime']));
    }

    /**
     * One kind on its own is not mixing, however many lines there are. Two
     * subscriptions together are also refused by FluentCart, but on their own
     * terms and one line at a time — that is not the failure a shopper can
     * assemble unwarned, so it is not this function's answer to give.
     */
    public function testOneKindAloneIsNotMixing()
    {
        $this->assertFalse(SubscriptionRule::mixesTypes(['onetime', 'onetime', 'onetime']));
        $this->assertFalse(SubscriptionRule::mixesTypes(['subscription', 'subscription']));
    }

    /**
     * An empty basket, and a single line, cannot mix with anything.
     */
    public function testAnEmptyOrSingleBasketNeverMixes()
    {
        $this->assertFalse(SubscriptionRule::mixesTypes([]));
        $this->assertFalse(SubscriptionRule::mixesTypes(['subscription']));
        $this->assertFalse(SubscriptionRule::mixesTypes('not an array'));
    }

    /**
     * An empty or missing payment type counts as a one-time line here, not as
     * an unknown to be skipped. It is a real line in the basket, and FluentCart
     * will treat it as one-time when it refuses the combination.
     */
    public function testAnAbsentTypeCountsAsOnetimeWhenMixing()
    {
        $this->assertTrue(SubscriptionRule::mixesTypes(['subscription', '']));
        $this->assertTrue(SubscriptionRule::mixesTypes(['subscription', null]));
    }
}
