<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Analytics\Surface;
use PHPUnit\Framework\TestCase;

/**
 * The entry-point marker.
 *
 * It arrives in a URL a shopper can edit, so the assertion that matters is that
 * nothing outside the closed list can ever be stored: a marker that passed
 * through would put a shopper's own text into an attribution row and then onto
 * an owner's report.
 */
class SurfaceTest extends TestCase
{
    public function testOnlyKnownSurfacesSurviveSanitizing()
    {
        foreach (Surface::keys() as $key) {
            $this->assertSame($key, Surface::sanitize($key));
        }
    }

    /**
     * The empty string is a real answer here — "attributed, but not to a named
     * entry point" — so anything unknown becomes that, never a default surface.
     */
    public function testAnythingUnknownBecomesTheUnrecordedValue()
    {
        $this->assertSame('', Surface::sanitize('product_table'));
        $this->assertSame('', Surface::sanitize('<script>'));
        $this->assertSame('', Surface::sanitize(''));
        $this->assertSame('', Surface::sanitize(null));
        $this->assertSame('', Surface::sanitize(['bulk_order_form']));
        $this->assertSame('', Surface::sanitize(0));
    }

    /**
     * A shopper who edits the query string must not be able to name a surface
     * they were never on by getting close to one.
     */
    public function testNearMissesDoNotMatch()
    {
        $this->assertSame('', Surface::sanitize('BULK_ORDER_FORM'));
        $this->assertSame('', Surface::sanitize(' bulk_order_form'));
        $this->assertSame('', Surface::sanitize('bulk_order_form2'));
    }

    public function testEverySurfaceHasItsOwnLabel()
    {
        $labels = [];

        foreach (Surface::keys() as $key) {
            $labels[] = Surface::label($key);
        }

        $labels[] = Surface::label('');

        $this->assertCount(count(Surface::keys()) + 1, array_unique($labels));
        $this->assertNotContains('', $labels);
    }

    /**
     * The empty value has a sentence of its own. It is a row an owner reads,
     * not a blank cell.
     */
    public function testTheUnrecordedValueHasItsOwnSentence()
    {
        $this->assertNotSame('', Surface::label(''));
        $this->assertNotSame('', Surface::label('anything-else'));
        $this->assertSame(Surface::label(''), Surface::label('anything-else'));
    }

    /**
     * The field name and the query argument are deliberately one string, so a
     * checkout form posted as one FormData carries the marker under the name
     * the recorder reads.
     */
    public function testTheParameterNameIsPrefixed()
    {
        $this->assertSame('fcbo_src', Surface::PARAM);
    }
}
