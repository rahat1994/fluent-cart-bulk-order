<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Pricing\OrderRules;
use PHPUnit\Framework\TestCase;

/**
 * Order Rules — minimum quantity and case-pack steps.
 *
 * The rule these tests exist to protect: quantities round UP, never down, and
 * never silently. Asking a shopper for more than they typed is recoverable —
 * they see it and can change their mind. Quietly shipping them fewer than they
 * asked for is not.
 */
class OrderRulesTest extends TestCase
{
    /**
     * @dataProvider malformedRules
     */
    public function testNormalizeAlwaysReturnsUsableRules($input, $expected, $why)
    {
        $this->assertSame($expected, OrderRules::normalize($input), $why);
    }

    public function malformedRules()
    {
        return [
            'empty array'        => [[], ['min_qty' => 0, 'step' => 1], 'no rules means no constraint'],
            'missing keys'       => [['other' => 1], ['min_qty' => 0, 'step' => 1], 'unknown keys are ignored'],
            'zero step'          => [['step' => 0], ['min_qty' => 0, 'step' => 1], 'a step of 0 would divide by zero downstream'],
            'negative step'      => [['step' => -6], ['min_qty' => 0, 'step' => 1], 'a negative step is meaningless'],
            'negative minimum'   => [['min_qty' => -5], ['min_qty' => 0, 'step' => 1], 'you cannot require fewer than none'],
            'numeric strings'    => [['min_qty' => '10', 'step' => '6'], ['min_qty' => 10, 'step' => 6], 'form input arrives as strings'],
            'not an array'       => ['nonsense', ['min_qty' => 0, 'step' => 1], 'a corrupt option must not fatal'],
            'null'               => [null, ['min_qty' => 0, 'step' => 1], 'an absent feed key must not fatal'],
        ];
    }

    public function testAreSetIsFalseOnlyWhenNothingConstrains()
    {
        $this->assertFalse(OrderRules::areSet(['min_qty' => 0, 'step' => 1]), 'the default constrains nothing');
        $this->assertTrue(OrderRules::areSet(['min_qty' => 6, 'step' => 1]), 'a minimum is a constraint');
        $this->assertTrue(OrderRules::areSet(['min_qty' => 0, 'step' => 6]), 'a case pack is a constraint');
        $this->assertTrue(OrderRules::areSet(['min_qty' => 6, 'step' => 6]), 'both is still a constraint');
    }

    /**
     * @dataProvider quantities
     */
    public function testNormalizeQtyRoundsUp($qty, array $rules, $expected, $why)
    {
        $this->assertSame($expected, OrderRules::normalizeQty($qty, $rules), $why);
    }

    public function quantities()
    {
        return [
            'below the minimum'      => [2,  ['min_qty' => 10, 'step' => 1], 10, 'raised to the minimum'],
            'already above it'       => [15, ['min_qty' => 10, 'step' => 1], 15, 'left alone when it conforms'],
            'exactly the minimum'    => [10, ['min_qty' => 10, 'step' => 1], 10, 'the boundary itself is valid'],
            'mid case pack'          => [7,  ['min_qty' => 0,  'step' => 6], 12, '7 rounds up to 12, not down to 6'],
            'one over a pack'        => [13, ['min_qty' => 0,  'step' => 6], 18, 'still up, never down'],
            'exact multiple'         => [12, ['min_qty' => 0,  'step' => 6], 12, 'an exact multiple does not move'],
            'minimum and pack'       => [3,  ['min_qty' => 10, 'step' => 6], 12, 'minimum first, then rounded to the pack'],
            'zero'                   => [0,  ['min_qty' => 0,  'step' => 1], 1,  'a line must be at least one unit'],
            'negative'               => [-5, ['min_qty' => 0,  'step' => 1], 1,  'a negative quantity floors at one'],
            'no rules at all'        => [7,  [],                             7,  'unconstrained quantities pass through'],
        ];
    }

    public function testQtyIsValidAgreesWithNormalizeQty()
    {
        $rules = ['min_qty' => 20, 'step' => 10];

        // The two must never disagree: qtyIsValid() is what the server refuses
        // on, and normalizeQty() is what the surfaces offer instead. If a
        // quantity were "invalid" but normalised to itself, the shopper would be
        // refused and then handed back the same number.
        foreach (range(1, 60) as $qty) {
            $settled = OrderRules::normalizeQty($qty, $rules);
            $this->assertSame(
                $qty === $settled,
                OrderRules::qtyIsValid($qty, $rules),
                "qty $qty: valid() and normalizeQty() must agree"
            );
        }
    }

    public function testNormalizeQtyIsIdempotent()
    {
        $rules = ['min_qty' => 20, 'step' => 10];

        // Imported rows are normalised on paste and again before checkout. A
        // second pass must not creep the quantity upward each time.
        foreach ([1, 7, 20, 21, 35, 100] as $qty) {
            $once  = OrderRules::normalizeQty($qty, $rules);
            $twice = OrderRules::normalizeQty($once, $rules);
            $this->assertSame($once, $twice, "normalising $qty twice must not move it again");
        }
    }

    /**
     * The sentence printed next to a quantity input.
     *
     * The case that matters is both-rules-set. Describing it with only the
     * minimum, or only the case pack, points a shopper at a quantity the server
     * will refuse — which is the whole failure this hint exists to prevent.
     *
     * @dataProvider ruleDescriptions
     */
    public function testDescribeNamesEveryRuleThatApplies($rules, $expected, $why)
    {
        $this->assertSame($expected, OrderRules::describe($rules, self::TEMPLATES), $why);
    }

    /**
     * Stand-ins for \FluentCartBulkOrder\Strings::orderRuleHints(). Deliberately
     * not the real ones: this asserts that describe() picks the right template
     * and fills the right placeholders, not what the English happens to say.
     */
    const TEMPLATES = [
        'rule_min_and_step' => 'MIN {min} STEP {step}',
        'rule_step'         => 'STEP {step}',
        'rule_min'          => 'MIN {min}',
    ];

    public function ruleDescriptions()
    {
        return [
            'nothing set' => [
                ['min_qty' => 0, 'step' => 1],
                '',
                'no constraint means no sentence, so callers render no element',
            ],
            'minimum only' => [
                ['min_qty' => 5, 'step' => 1],
                'MIN 5',
                'a step of 1 constrains nothing and must not be mentioned',
            ],
            'case pack only' => [
                ['min_qty' => 0, 'step' => 12],
                'STEP 12',
                'a minimum of 0 constrains nothing and must not be mentioned',
            ],
            'both set' => [
                ['min_qty' => 24, 'step' => 12],
                'MIN 24 STEP 12',
                'naming only one of two rules sends the shopper to a refused quantity',
            ],
            'malformed input' => [
                'nonsense',
                '',
                'a corrupt feed must render nothing, not fatal',
            ],
        ];
    }

    public function testDescribeShowsTheKeyWhenATemplateIsMissing()
    {
        // Same contract as the JS `t()` helpers: a key the PHP side forgot to
        // supply renders as itself, so the omission is obvious in review rather
        // than shipping a blank hint to a shopper.
        $this->assertSame(
            'rule_min',
            OrderRules::describe(['min_qty' => 5], []),
            'a missing template must be visible, not silent'
        );
    }
}
