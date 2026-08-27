<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Wholesale\ApplicationSchema;
use PHPUnit\Framework\TestCase;

/**
 * The owner-configured extra-field list.
 *
 * The failures this file exists to catch are all quiet ones. A duplicate key
 * silently overwrites another field's answer. A select with no options renders
 * an empty dropdown that, if required, makes the form permanently
 * unsubmittable. A field that shadows `company_name` replaces a required
 * built-in question with an optional one, and an admin then reviews an
 * application with no company on it. None of those produce an error anywhere.
 */
class ApplicationSchemaTest extends TestCase
{
    /**
     * The two built-in questions are always present, always first, always
     * required. They are what an admin actually decides on.
     */
    public function testBuiltInFieldsAreAlwaysFirstAndRequired()
    {
        $fields = ApplicationSchema::fields([
            ['label' => 'Trade reference', 'type' => 'text'],
        ]);

        $this->assertSame(ApplicationSchema::KEY_COMPANY, $fields[0]['key']);
        $this->assertSame(ApplicationSchema::KEY_TAX_ID, $fields[1]['key']);
        $this->assertSame('trade_reference', $fields[2]['key']);

        $this->assertTrue($fields[0]['required']);
        $this->assertTrue($fields[1]['required']);
        $this->assertTrue($fields[0]['built_in']);
        $this->assertFalse($fields[2]['built_in']);
    }

    /**
     * An owner cannot redefine a built-in question by reusing its key.
     */
    public function testReservedKeysCannotBeOverridden()
    {
        $fields = ApplicationSchema::normalizeFields([
            ['label' => 'Your company', 'key' => 'company_name', 'type' => 'text'],
            ['label' => 'VAT', 'key' => 'tax_id', 'required' => false],
            ['label' => 'Website', 'type' => 'text'],
        ]);

        $this->assertCount(1, $fields, 'both reserved keys must be dropped');
        $this->assertSame('website', $fields[0]['key']);
    }

    /**
     * One key, one answer. The second field claiming a key is dropped rather
     * than being allowed to overwrite the first's stored value.
     */
    public function testDuplicateKeysAreDropped()
    {
        $fields = ApplicationSchema::normalizeFields([
            ['label' => 'Website', 'type' => 'text'],
            ['label' => 'Web site', 'key' => 'website', 'type' => 'textarea'],
            ['label' => 'Website', 'type' => 'text'],
        ]);

        $this->assertCount(1, $fields);
        $this->assertSame('text', $fields[0]['type'], 'the first definition wins');
    }

    /**
     * A select with nothing to select is dropped, not repaired.
     *
     * The dangerous case is the REQUIRED one: rendered, it is a dropdown with
     * no valid choice, and the form can never be submitted again.
     */
    public function testSelectWithNoOptionsIsDropped()
    {
        $fields = ApplicationSchema::normalizeFields([
            ['label' => 'Industry', 'type' => 'select', 'options' => [], 'required' => true],
            ['label' => 'Region', 'type' => 'select', 'options' => ['  ', '']],
            ['label' => 'Size', 'type' => 'select', 'options' => ['Small', 'Large']],
        ]);

        $this->assertCount(1, $fields);
        $this->assertSame('size', $fields[0]['key']);
        $this->assertSame(['Small', 'Large'], $fields[0]['options']);
    }

    /**
     * An unrecognised type falls back to text instead of being dropped — the
     * label is the owner's real intent and text can hold any answer.
     */
    public function testUnknownTypeFallsBackToText()
    {
        $fields = ApplicationSchema::normalizeFields([
            ['label' => 'Fax', 'type' => 'file'],
            ['label' => 'Notes', 'type' => 'TEXTAREA'],
            ['label' => 'Agree', 'type' => ['checkbox']],
        ]);

        $this->assertSame('text', $fields[0]['type']);
        $this->assertSame('textarea', $fields[1]['type'], 'type reading is case-insensitive');
        $this->assertSame('text', $fields[2]['type'], 'a non-scalar type is not a type');
    }

    /**
     * Options only exist on a select. A text field carrying leftover options
     * from a type change must not keep them, or the validator would start
     * enforcing a list the form never renders.
     */
    public function testOptionsAreDiscardedForNonSelectTypes()
    {
        $fields = ApplicationSchema::normalizeFields([
            ['label' => 'Notes', 'type' => 'textarea', 'options' => ['a', 'b']],
        ]);

        $this->assertSame([], $fields[0]['options']);
    }

    /**
     * The settings textarea posts options as one block of text; the stored
     * value is an array. Both have to arrive at the same list.
     */
    public function testOptionsAcceptBothATextBlockAndAnArray()
    {
        $this->assertSame(
            ['Retail', 'Wholesale', 'Export'],
            ApplicationSchema::normalizeOptions("Retail\r\nWholesale\n\n  Export  \n")
        );

        $this->assertSame(
            ['Retail', 'Wholesale'],
            ApplicationSchema::normalizeOptions(['Retail', ' Wholesale ', 'Retail', '', ['nope']])
        );
    }

    /**
     * A field with no label is not a question.
     */
    public function testFieldsWithoutALabelAreDropped()
    {
        $fields = ApplicationSchema::normalizeFields([
            ['label' => '   ', 'type' => 'text'],
            ['key' => 'orphan', 'type' => 'text'],
            'not an array',
            ['label' => 'Real', 'type' => 'text'],
        ]);

        $this->assertCount(1, $fields);
        $this->assertSame('real', $fields[0]['key']);
    }

    /**
     * A supplied key wins over one derived from the label, because renaming a
     * label must not orphan the answers already stored under the old key.
     */
    public function testSuppliedKeyWinsOverTheLabel()
    {
        $fields = ApplicationSchema::normalizeFields([
            ['label' => 'Company registration number', 'key' => 'reg_no'],
        ]);

        $this->assertSame('reg_no', $fields[0]['key']);
    }

    /**
     * Key derivation: lower case, ASCII only, single underscores, no numeric
     * keys (PHP would silently cast those to int and break === comparisons).
     */
    public function testKeyDerivation()
    {
        $this->assertSame('trade_reference', ApplicationSchema::keyFromLabel('Trade Reference'));
        $this->assertSame('vat_eu', ApplicationSchema::keyFromLabel('  VAT / EU ??  '));
        $this->assertSame('', ApplicationSchema::keyFromLabel('123'));
        $this->assertSame('', ApplicationSchema::keyFromLabel('!!!'));
        $this->assertSame('', ApplicationSchema::keyFromLabel(''));
        $this->assertSame('a_b', ApplicationSchema::keyFromLabel('a---b'));
    }

    /**
     * A label that yields no usable key is dropped rather than stored under ''.
     */
    public function testFieldWithUnusableKeyIsDropped()
    {
        $fields = ApplicationSchema::normalizeFields([
            ['label' => '???', 'type' => 'text'],
            ['label' => '2024', 'type' => 'text'],
        ]);

        $this->assertSame([], $fields);
    }

    /**
     * The field count is capped, because every applicant's record is one
     * serialized user meta row.
     */
    public function testExtraFieldCountIsCapped()
    {
        $raw = [];
        for ($i = 0; $i < ApplicationSchema::MAX_EXTRA_FIELDS + 5; $i++) {
            $raw[] = ['label' => 'Field ' . $i, 'key' => 'f' . $i];
        }

        $this->assertCount(ApplicationSchema::MAX_EXTRA_FIELDS, ApplicationSchema::normalizeFields($raw));
    }

    /**
     * Labels and options are length-capped so one paste accident cannot fill a
     * review-screen table cell with a page of text.
     */
    public function testLabelsAndOptionsAreLengthCapped()
    {
        $long = str_repeat('x', ApplicationSchema::MAX_LABEL_LENGTH + 50);

        $fields = ApplicationSchema::normalizeFields([
            ['label' => $long, 'key' => 'long', 'type' => 'select', 'options' => [$long]],
        ]);

        $this->assertSame(ApplicationSchema::MAX_LABEL_LENGTH, strlen($fields[0]['label']));
        $this->assertSame(ApplicationSchema::MAX_LABEL_LENGTH, strlen($fields[0]['options'][0]));
    }

    /**
     * Junk in, empty list out — never a fatal, because this runs on both the
     * settings save path and every form render.
     */
    public function testJunkInputYieldsAnEmptyList()
    {
        $this->assertSame([], ApplicationSchema::normalizeFields(null));
        $this->assertSame([], ApplicationSchema::normalizeFields('fields'));
        $this->assertSame([], ApplicationSchema::normalizeFields(42));

        // The built-ins survive regardless, so the form is never empty.
        $this->assertCount(2, ApplicationSchema::fields('rubbish'));
    }

    /**
     * findField() is what the review screen uses to label a stored answer.
     */
    public function testFindField()
    {
        $fields = ApplicationSchema::fields([['label' => 'Website']]);

        $this->assertSame('Website', ApplicationSchema::findField($fields, 'website')['label']);
        $this->assertNull(ApplicationSchema::findField($fields, 'nope'));
        $this->assertNull(ApplicationSchema::findField('not a list', 'website'));
    }
}
