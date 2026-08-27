<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Shortcodes\AttributeSchema;
use PHPUnit\Framework\TestCase;

/**
 * The block / Elementor attribute map.
 *
 * The assertion that matters most in this file is the one about OMISSION. The
 * store-wide defaults in \FluentCartBulkOrder\StoreDefaults only win because
 * ProductTable::defaults() feeds them to shortcode_atts() as the defaults layer,
 * and shortcode_atts() lets any attribute that was actually passed override
 * them. So a wrapper that passes `per_page => ''` for a control the owner never
 * touched does not "pass nothing" — it passes an explicit empty string, beats
 * the stored default, and lands on the hardcoded fallback of 5.
 *
 * Nothing about that failure is visible: no error, no warning, just a table
 * showing 5 rows while the settings page says 20. These tests are what stops it
 * coming back.
 */
class AttributeSchemaTest extends TestCase
{
    /**
     * The whole point of the class, stated once as plainly as possible.
     */
    public function testUntouchedControlsProduceNoAttributesAtAll()
    {
        $blank = [
            'per_page'        => '',
            'columns'         => '',
            'search'          => '',
            'category'        => '',
            'roles'           => '',
            'expand_variants' => '',
        ];

        $this->assertSame(
            [],
            AttributeSchema::toShortcodeAtts('fluent_cart_product_table', $blank),
            'a block that saved every control as blank must pass NO attributes, or it overrides the store-wide defaults'
        );

        $this->assertSame(
            [],
            AttributeSchema::toShortcodeAtts('fluent_cart_bulk_order', ['roles' => '', 'redirect' => '']),
            'same rule for the bulk order form'
        );
    }

    /**
     * The other half: a control the owner did set has to survive.
     */
    public function testSetControlsArePassedThrough()
    {
        $this->assertSame(
            [
                'per_page'        => '20',
                'columns'         => 'id,title',
                'search'          => 'false',
                'category'        => 'gaskets',
                'roles'           => 'wholesale_customer,shop_manager',
                'expand_variants' => 'true',
            ],
            AttributeSchema::toShortcodeAtts('fluent_cart_product_table', [
                'per_page'        => '20',
                'columns'         => 'id,title',
                'search'          => 'false',
                'category'        => 'gaskets',
                'roles'           => 'wholesale_customer,shop_manager',
                'expand_variants' => 'true',
            ])
        );
    }

    /**
     * A half-filled block is the normal case: the two controls that were set
     * come through, the four that were not stay out of the way.
     */
    public function testAMixOfSetAndUntouchedControls()
    {
        $this->assertSame(
            ['columns' => 'title,price', 'search' => 'false'],
            AttributeSchema::toShortcodeAtts('fluent_cart_product_table', [
                'per_page'        => '',
                'columns'         => 'title,price',
                'search'          => 'false',
                'category'        => '',
                'roles'           => '',
                'expand_variants' => '',
            ])
        );
    }

    /**
     * No emitted value may ever be the empty string, whatever went in. This is
     * the invariant the precedence rule actually rests on, so it is asserted
     * over the whole schema rather than one attribute at a time.
     *
     * @dataProvider blankish
     */
    public function testNoAttributeIsEverEmitted($blank, $why)
    {
        foreach (AttributeSchema::tags() as $tag) {
            $values = [];

            foreach (array_keys(AttributeSchema::controls($tag)) as $name) {
                $values[$name] = $blank;
            }

            $this->assertSame([], AttributeSchema::toShortcodeAtts($tag, $values), $why);
        }
    }

    public function blankish()
    {
        return [
            'empty string'   => ['', 'the value every emptied control reports'],
            'whitespace'     => ['   ', 'a field the owner typed spaces into is still empty'],
            'null'           => [null, 'a control that was never rendered'],
            'empty array'    => [[], 'an emptied Elementor multi-select'],
            'array of blanks' => [['', ' '], 'a multi-select holding nothing usable'],
        ];
    }

    /**
     * Rows per page is the attribute where a wrong "not set" reading is most
     * visible to a store owner.
     *
     * @dataProvider perPageValues
     */
    public function testPerPage($value, $expected, $why)
    {
        $atts = AttributeSchema::toShortcodeAtts('fluent_cart_product_table', ['per_page' => $value]);

        $this->assertSame($expected, $atts, $why);
    }

    public function perPageValues()
    {
        return [
            'a number'    => ['20', ['per_page' => '20'], 'the owner set it, so it wins'],
            'an integer'  => [20, ['per_page' => '20'], 'a number control may report an int, not a string'],
            'zero'        => ['0', [], 'zero is what an emptied number field reports, not a request for zero rows'],
            'negative'    => ['-5', [], 'nothing sensible to pass on'],
            'not a number' => ['abc', [], 'junk must fall through to the store default, not to the hardcoded one'],
            'a float'     => ['12.7', ['per_page' => '12'], 'truncated, the same way absint() would read it'],
            'true'        => [true, [], 'a boolean is not a row count'],
        ];
    }

    /**
     * Yes/no settings are three-state on purpose: on, off, and "leave the store
     * default alone". Losing the third state is how a toggle would break this.
     *
     * @dataProvider ternaryValues
     */
    public function testTernary($value, $expected, $why)
    {
        $atts = AttributeSchema::toShortcodeAtts('fluent_cart_product_table', ['search' => $value]);

        $this->assertSame($expected, $atts, $why);
    }

    public function ternaryValues()
    {
        return [
            'true'            => ['true', ['search' => 'true'], 'explicitly on'],
            'false'           => ['false', ['search' => 'false'], 'explicitly off - NOT the same as unset'],
            'boolean true'    => [true, ['search' => 'true'], 'a real boolean still means explicitly on'],
            'boolean false'   => [false, ['search' => 'false'], 'and a real boolean false means explicitly off'],
            'elementor yes'   => ['yes', ['search' => 'true'], "Elementor's switcher vocabulary"],
            'elementor empty' => ['', [], 'and its off position, which cannot mean anything but unset'],
            'mixed case'      => ['TRUE', ['search' => 'true'], 'a hand-edited value should not be thrown away'],
            'nonsense'        => ['maybe', [], 'unreadable means unset, so the store default keeps applying'],
        ];
    }

    /**
     * Columns arrive as a comma string from a block and as an array from
     * Elementor's multi-select. Both have to reach the shortcode as the comma
     * string fcbo_parse_columns_attr() expects.
     *
     * @dataProvider columnValues
     */
    public function testColumns($value, $expected, $why)
    {
        $atts = AttributeSchema::toShortcodeAtts('fluent_cart_product_table', ['columns' => $value]);

        $this->assertSame($expected, $atts, $why);
    }

    public function columnValues()
    {
        return [
            'comma string' => ['id,title', ['columns' => 'id,title'], 'what a block saves'],
            'array'        => [['id', 'title'], ['columns' => 'id,title'], 'what Elementor saves'],
            'array with blanks' => [['id', '', 'title'], ['columns' => 'id,title'], 'a hole in the array is not a column'],
            'every column' => [
                ['id', 'title', 'price', 'qty', 'action'],
                ['columns' => 'id,title,price,qty,action'],
                'picking all five is how an owner says "all columns" - different from leaving it unset',
            ],
        ];
    }

    /**
     * An editor must not be able to reach a shortcode attribute the schema does
     * not declare. Elementor in particular hands over its own settings
     * (_element_id, _margin and so on) in the same array.
     */
    public function testOnlyDeclaredAttributesComeOut()
    {
        $this->assertSame(
            ['roles' => 'wholesale_customer'],
            AttributeSchema::toShortcodeAtts('fluent_cart_bulk_order', [
                'roles'       => 'wholesale_customer',
                '_element_id' => 'hero',
                'per_page'    => '99',
                'columns'     => 'id',
            ]),
            'per_page and columns belong to the product table, not to the bulk order form'
        );
    }

    /**
     * The saved-orders shortcode is a registered tag with no editor wrapper.
     * Asserted rather than assumed, so adding one is a deliberate act.
     */
    public function testTagsWithoutAnEditorWrapper()
    {
        $this->assertSame(
            ['fluent_cart_bulk_order', 'fluent_cart_product_table'],
            AttributeSchema::tags()
        );

        $this->assertSame([], AttributeSchema::controls('fluent_cart_saved_orders'));
        $this->assertSame([], AttributeSchema::toShortcodeAtts('fluent_cart_saved_orders', ['roles' => 'x']));
        $this->assertSame([], AttributeSchema::toShortcodeAtts('not_a_tag', ['roles' => 'x']));
    }

    /**
     * A value that is missing from the array entirely is the same as a blank
     * one — both mean "the owner has no opinion".
     */
    public function testMissingKeysAreNotInvented()
    {
        $this->assertSame(
            ['roles' => 'buyer'],
            AttributeSchema::toShortcodeAtts('fluent_cart_bulk_order', ['roles' => 'buyer'])
        );

        $this->assertSame([], AttributeSchema::toShortcodeAtts('fluent_cart_bulk_order', []));
        $this->assertSame([], AttributeSchema::toShortcodeAtts('fluent_cart_bulk_order', 'not an array'));
    }

    /**
     * Whitespace a store owner did not mean to type must not turn an unset
     * control into a set one.
     */
    public function testValuesAreTrimmed()
    {
        $this->assertSame(
            ['redirect' => 'https://example.test/quote'],
            AttributeSchema::toShortcodeAtts('fluent_cart_bulk_order', ['redirect' => '  https://example.test/quote  '])
        );
    }
}
