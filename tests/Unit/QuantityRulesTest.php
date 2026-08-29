<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Display\QuantityRules;
use PHPUnit\Framework\TestCase;

/**
 * The one decision on the single product page that is made without knowing which
 * variant is on screen.
 *
 * FluentCart picks the default variant inside its own renderer and does not pass
 * that choice to the hook we render from, so the rule hint can only be written
 * server-side when the answer is the same for every variant. Getting this wrong
 * is silent: the page renders, looks right, and tells a shopper looking at an
 * unconstrained variant that there is a minimum — or, worse, tells a shopper
 * looking at a constrained one that there is none.
 */
class QuantityRulesTest extends TestCase
{
    public function testSharedRulesReturnsTheRulesWhenEveryVariantAgrees()
    {
        $rules = ['min_qty' => 5, 'step' => 5];

        $this->assertSame(
            $rules,
            QuantityRules::sharedRules(['11' => $rules, '12' => $rules], 2),
            'identical rules on every variant can be stated without knowing the default'
        );
    }

    public function testSharedRulesRefusesWhenVariantsDisagree()
    {
        $this->assertNull(
            QuantityRules::sharedRules(
                ['11' => ['min_qty' => 5, 'step' => 1], '12' => ['min_qty' => 10, 'step' => 1]],
                2
            ),
            'two different minimums cannot be summarised as one sentence'
        );
    }

    /**
     * The case a naive implementation gets wrong.
     *
     * collect() omits unconstrained variants entirely, so a map whose entries all
     * match can still describe only part of the product. If the default variant is
     * one of the missing ones, the sentence would name a rule that does not apply
     * to it.
     */
    public function testSharedRulesRefusesWhenTheMapDoesNotCoverEveryVariant()
    {
        $rules = ['min_qty' => 5, 'step' => 5];

        $this->assertNull(
            QuantityRules::sharedRules(['11' => $rules], 3),
            'an omitted variant is an unconstrained variant, which is a disagreement'
        );
    }

    public function testSharedRulesRefusesWhenThereAreNoVariants()
    {
        $this->assertNull(
            QuantityRules::sharedRules([], 0),
            'nothing to describe, and count([]) === 0 must not read as agreement'
        );
    }

    public function testSharedRulesAcceptsASingleVariantProduct()
    {
        $rules = ['min_qty' => 0, 'step' => 12];

        $this->assertSame(
            $rules,
            QuantityRules::sharedRules(['11' => $rules], 1),
            'a simple product has exactly one variant, which is always the default'
        );
    }
}
