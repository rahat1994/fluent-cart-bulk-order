<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Wholesale\ApplicationInput;
use FluentCartBulkOrder\Wholesale\ApplicationSchema;
use PHPUnit\Framework\TestCase;

/**
 * Validating a submitted wholesale application against the configured schema.
 *
 * The assertion that matters most in this file is
 * testOnlyConfiguredFieldsSurvive(). validate() walks the SCHEMA and looks each
 * key up in the submission, never the other way round — so extra keys in the
 * POST body are not rejected, they are never read. That is what stops a crafted
 * request from smuggling `status`, `reviewer_id` or anything else into a record
 * that later grants a role.
 */
class ApplicationInputTest extends TestCase
{
    /**
     * A field list covering all four types, used by most tests below.
     *
     * @return array<int, array<string, mixed>>
     */
    private function schema()
    {
        return ApplicationSchema::fields([
            ['label' => 'Website', 'type' => 'text'],
            ['label' => 'About your business', 'type' => 'textarea'],
            ['label' => 'Industry', 'type' => 'select', 'options' => ['Retail', 'Export']],
            ['label' => 'I agree to the trade terms', 'key' => 'terms', 'type' => 'checkbox', 'required' => true],
        ]);
    }

    /**
     * THE security property. The schema is the allow-list; the submission only
     * supplies values.
     */
    public function testOnlyConfiguredFieldsSurvive()
    {
        $result = ApplicationInput::validate($this->schema(), [
            'company_name' => 'Acme Ltd',
            'tax_id'       => 'GB123456789',
            'website'      => 'https://acme.test',
            'about_your_business' => 'We resell widgets.',
            'industry'     => 'Retail',
            'terms'        => '1',

            // Everything below is what a crafted request would add.
            'status'       => 'approved',
            'reviewer_id'  => 1,
            'user_id'      => 1,
            'role'         => 'administrator',
            'submitted_at' => 0,
        ]);

        $this->assertSame(
            ['company_name', 'tax_id', 'website', 'about_your_business', 'industry', 'terms'],
            array_keys($result['values']),
            'no key outside the schema may reach the stored record'
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame('Acme Ltd', $result['values']['company_name']);
        $this->assertTrue($result['values']['terms']);
    }

    /**
     * Every declared field appears in the result, even when the shopper skipped
     * it — so the review screen can show "not answered" rather than guessing
     * whether the question existed at the time.
     */
    public function testEveryDeclaredFieldIsPresentInTheResult()
    {
        $result = ApplicationInput::validate($this->schema(), []);

        $this->assertSame(
            ['company_name', 'tax_id', 'website', 'about_your_business', 'industry', 'terms'],
            array_keys($result['values'])
        );

        $this->assertSame('', $result['values']['website']);
        $this->assertFalse($result['values']['terms']);
    }

    /**
     * Required fields that were left empty are reported, one error per key.
     */
    public function testRequiredFieldsAreReported()
    {
        $result = ApplicationInput::validate($this->schema(), [
            'company_name' => '   ',
            'website'      => 'https://acme.test',
        ]);

        $this->assertSame(
            [
                'company_name' => ApplicationInput::ERROR_REQUIRED,
                'tax_id'       => ApplicationInput::ERROR_REQUIRED,
                'terms'        => ApplicationInput::ERROR_REQUIRED,
            ],
            $result['errors']
        );
    }

    /**
     * A select value that is not one of the configured options is an error AND
     * is discarded — never stored as free text an admin would read as a choice.
     */
    public function testSelectValueMustBeOneOfTheConfiguredOptions()
    {
        $result = ApplicationInput::validate($this->schema(), [
            'company_name' => 'Acme',
            'tax_id'       => 'GB1',
            'terms'        => 'on',
            'industry'     => 'Arms dealing',
        ]);

        $this->assertSame(
            ['industry' => ApplicationInput::ERROR_INVALID_OPTION],
            $result['errors']
        );

        $this->assertSame('', $result['values']['industry'], 'a rejected choice must not be stored');
    }

    /**
     * An empty OPTIONAL select is fine and gets its own (absent) error, which
     * is why invalid_option and required are separate codes.
     */
    public function testEmptyOptionalSelectIsAccepted()
    {
        $result = ApplicationInput::validate($this->schema(), [
            'company_name' => 'Acme',
            'tax_id'       => 'GB1',
            'terms'        => '1',
            'industry'     => '',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('', $result['values']['industry']);
    }

    /**
     * A required select with no value reports `required`, not `invalid_option`.
     */
    public function testRequiredSelectLeftEmptyReportsRequired()
    {
        $fields = ApplicationSchema::normalizeFields([
            ['label' => 'Industry', 'type' => 'select', 'options' => ['Retail'], 'required' => true],
        ]);

        $result = ApplicationInput::validate($fields, ['industry' => '']);

        $this->assertSame(['industry' => ApplicationInput::ERROR_REQUIRED], $result['errors']);
    }

    /**
     * Checkbox reading. An unticked box is ABSENT from the post body, so a
     * missing key has to mean false.
     */
    public function testCheckboxReading()
    {
        foreach (['1', 'on', 'yes', 'true', 'anything', true] as $truthy) {
            $this->assertTrue(ApplicationInput::isChecked($truthy), var_export($truthy, true) . ' should read as ticked');
        }

        foreach ([null, '', '0', 'false', 'off', 'no', false, [], new \stdClass()] as $falsy) {
            $this->assertFalse(ApplicationInput::isChecked($falsy), var_export($falsy, true) . ' should read as unticked');
        }
    }

    /**
     * The injected sanitiser is genuinely applied — this is what the WordPress
     * caller relies on to get sanitize_text_field() onto every value.
     */
    public function testInjectedSanitizersAreApplied()
    {
        $fields = ApplicationSchema::fields([
            ['label' => 'Notes', 'type' => 'textarea'],
        ]);

        $result = ApplicationInput::validate(
            $fields,
            ['company_name' => 'acme', 'notes' => "line one\nline two"],
            function ($value) {
                return strtoupper($value);
            },
            function ($value) {
                return str_replace('line', 'LINE', $value);
            }
        );

        $this->assertSame('ACME', $result['values']['company_name'], 'text sanitiser must run');
        $this->assertSame("LINE one\nLINE two", $result['values']['notes'], 'textarea sanitiser must run');
    }

    /**
     * With no sanitiser supplied, markup still cannot be stored. A floor, not
     * the intended path — the WordPress caller passes the real functions.
     */
    public function testFallbackSanitizerStripsMarkup()
    {
        $result = ApplicationInput::validate(
            ApplicationSchema::builtInFields(),
            ['company_name' => '<script>alert(1)</script>Acme', 'tax_id' => 'GB1']
        );

        $this->assertSame('alert(1)Acme', $result['values']['company_name']);
    }

    /**
     * A single-line answer stays single-line whatever the sanitiser does, so a
     * pasted block cannot break the review screen's one-row-per-field layout.
     */
    public function testSingleLineFieldsCannotContainNewlines()
    {
        $result = ApplicationInput::validate(
            ApplicationSchema::builtInFields(),
            ['company_name' => "Acme\r\nLtd\tTrading", 'tax_id' => 'GB1'],
            function ($value) {
                return $value; // deliberately does nothing
            }
        );

        $this->assertSame('Acme Ltd Trading', $result['values']['company_name']);
    }

    /**
     * Textareas keep their line breaks, normalised to \n.
     */
    public function testTextareaKeepsNormalisedLineBreaks()
    {
        $fields = ApplicationSchema::normalizeFields([['label' => 'Notes', 'type' => 'textarea']]);

        $result = ApplicationInput::validate($fields, ['notes' => "one\r\ntwo\rthree"], null, function ($v) {
            return $v;
        });

        $this->assertSame("one\ntwo\nthree", $result['values']['notes']);
    }

    /**
     * An array where a string belongs collapses to '' rather than being
     * flattened to "Array". A required field then honestly reports as missing.
     */
    public function testArrayValuesCollapseToEmpty()
    {
        $result = ApplicationInput::validate(
            ApplicationSchema::builtInFields(),
            ['company_name' => ['Acme'], 'tax_id' => ['a' => 'b']]
        );

        $this->assertSame('', $result['values']['company_name']);
        $this->assertSame(
            [
                'company_name' => ApplicationInput::ERROR_REQUIRED,
                'tax_id'       => ApplicationInput::ERROR_REQUIRED,
            ],
            $result['errors']
        );
    }

    /**
     * One answer cannot be unbounded — the record is a single user meta row.
     */
    public function testValuesAreLengthCapped()
    {
        $result = ApplicationInput::validate(
            ApplicationSchema::builtInFields(),
            ['company_name' => str_repeat('a', ApplicationInput::MAX_VALUE_LENGTH + 500), 'tax_id' => 'GB1']
        );

        $this->assertSame(ApplicationInput::MAX_VALUE_LENGTH, strlen($result['values']['company_name']));
    }

    /**
     * Junk arguments produce an empty result rather than a fatal — this runs on
     * a public POST handler.
     */
    public function testJunkArgumentsDoNotFatal()
    {
        $this->assertSame(['values' => [], 'errors' => []], ApplicationInput::validate(null, null));
        $this->assertSame(['values' => [], 'errors' => []], ApplicationInput::validate('fields', 'values'));

        $result = ApplicationInput::validate([['no' => 'key'], 'junk'], ['a' => 'b']);
        $this->assertSame(['values' => [], 'errors' => []], $result);
    }
}
