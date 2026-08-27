<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Checkout\PoNumber;
use PHPUnit\Framework\TestCase;

/**
 * The purchase-order field's own rules.
 *
 * This is the class that decides whether a checkout is refused, so the
 * assertions worth reading first are the ones about failing safe: an
 * unrecognised mode is OFF and never REQUIRED, and a value that could break the
 * file or the email it later lands in is flattened before it is ever stored.
 */
class PoNumberTest extends TestCase
{
    /**
     * The default is off, and a broken value falls back to off — never to
     * required. A store whose option is corrupted must keep selling.
     */
    public function testUnknownModeFallsBackToOff()
    {
        $this->assertSame(PoNumber::MODE_OFF, PoNumber::sanitizeMode('nonsense'));
        $this->assertSame(PoNumber::MODE_OFF, PoNumber::sanitizeMode(''));
        $this->assertSame(PoNumber::MODE_OFF, PoNumber::sanitizeMode(null));
        $this->assertSame(PoNumber::MODE_OFF, PoNumber::sanitizeMode(['required']));
        $this->assertSame(PoNumber::MODE_OFF, PoNumber::sanitizeMode('REQUIRE'));
    }

    /**
     * The three legal modes survive, including with stray case and whitespace —
     * a value hand-written into the database still works.
     */
    public function testLegalModesSurvive()
    {
        $this->assertSame(PoNumber::MODE_OFF, PoNumber::sanitizeMode('off'));
        $this->assertSame(PoNumber::MODE_OPTIONAL, PoNumber::sanitizeMode('optional'));
        $this->assertSame(PoNumber::MODE_REQUIRED, PoNumber::sanitizeMode('required'));
        $this->assertSame(PoNumber::MODE_REQUIRED, PoNumber::sanitizeMode('  Required '));
    }

    /**
     * The two questions the checkout asks, answered from one stored value.
     */
    public function testOnAndRequiredAreDifferentQuestions()
    {
        $this->assertFalse(PoNumber::isOn(PoNumber::MODE_OFF));
        $this->assertTrue(PoNumber::isOn(PoNumber::MODE_OPTIONAL));
        $this->assertTrue(PoNumber::isOn(PoNumber::MODE_REQUIRED));

        $this->assertFalse(PoNumber::isRequired(PoNumber::MODE_OFF));
        $this->assertFalse(PoNumber::isRequired(PoNumber::MODE_OPTIONAL));
        $this->assertTrue(PoNumber::isRequired(PoNumber::MODE_REQUIRED));

        // An unreadable mode is off, so it is also not required.
        $this->assertFalse(PoNumber::isOn('banana'));
        $this->assertFalse(PoNumber::isRequired('banana'));
    }

    /**
     * The injected sanitiser is actually applied.
     *
     * The WordPress callers pass sanitize_text_field(); this proves the value
     * goes through whatever is handed in rather than through a hardcoded path.
     */
    public function testInjectedSanitizerIsApplied()
    {
        $called = 0;

        $result = PoNumber::sanitize('PO-1', function ($value) use (&$called) {
            $called++;

            return 'replaced';
        });

        $this->assertSame(1, $called);
        $this->assertSame('replaced', $result);
    }

    /**
     * With no sanitiser passed, markup still cannot reach a stored value.
     *
     * The floor, not the intended path — but a caller that forgets must not be
     * able to store a script tag.
     */
    public function testFallbackStripsMarkup()
    {
        $this->assertSame('alert(1) PO-9', PoNumber::sanitize('<script>alert(1)</script> PO-9'));
    }

    /**
     * A PO number is one line. Newlines, tabs and control characters collapse
     * to spaces, so nothing downstream — a CSV row, an email header — has to
     * defend against them.
     */
    public function testControlCharactersCollapseToOneLine()
    {
        $this->assertSame('PO 4711 B', PoNumber::sanitize("PO\r\n4711\tB"));
        $this->assertSame('PO 4711', PoNumber::sanitize("PO\x0B4711"));
    }

    /**
     * Two spellings of the same reference become one.
     */
    public function testWhitespaceIsCollapsedAndTrimmed()
    {
        $this->assertSame('PO 4711', PoNumber::sanitize('  PO   4711  '));
        $this->assertSame('', PoNumber::sanitize('     '));
    }

    /**
     * Too long is truncated, not refused — the buyer still gets their order and
     * the store still gets a reference.
     */
    public function testOverlongValueIsTruncatedNotRejected()
    {
        $long = str_repeat('A', PoNumber::MAX_LENGTH + 40);

        $this->assertSame(PoNumber::MAX_LENGTH, strlen(PoNumber::sanitize($long)));
    }

    /**
     * The cap counts characters, not the spaces that were squeezed out of the
     * value on the way — that is why trimming happens before truncating.
     */
    public function testTruncationHappensAfterCollapsing()
    {
        $value = '  ' . str_repeat('B', PoNumber::MAX_LENGTH) . '  ';

        $this->assertSame(str_repeat('B', PoNumber::MAX_LENGTH), PoNumber::sanitize($value));
    }

    /**
     * Anything that is not a scalar is not a PO number.
     */
    public function testNonScalarsBecomeEmpty()
    {
        $this->assertSame('', PoNumber::sanitize(['PO-1']));
        $this->assertSame('', PoNumber::sanitize(null));
    }

    /**
     * "Missing" has one definition, so the form hint and the checkout veto can
     * never disagree about whether a value passes.
     */
    public function testMissingHasOneDefinition()
    {
        $this->assertTrue(PoNumber::isMissing(''));
        $this->assertTrue(PoNumber::isMissing('   '));
        $this->assertFalse(PoNumber::isMissing('PO-1'));
        $this->assertFalse(PoNumber::isMissing('0'));
    }
}
