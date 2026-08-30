<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Rest\SearchMatch;
use PHPUnit\Framework\TestCase;

/**
 * The rule that decides which variants a catalogue search highlighted.
 *
 * Worth pinning because the rule has to agree with SQL that lives somewhere
 * else. Both endpoints select products with `sku LIKE %term%` OR
 * `variation_title LIKE %term%`; if this class drifts from that, a product
 * arrives in the result set with nothing marked on it and the shopper is shown
 * a match they cannot see.
 *
 * @see \FluentCartBulkOrder\Rest\SearchMatch
 */
class SearchMatchTest extends TestCase
{
    /**
     * The plain case the issue was reported about: a SKU typed in full.
     */
    public function testMatchesAnExactSku()
    {
        $this->assertTrue(SearchMatch::variantMatches('P2-M', 'Medium', 'P2-M'));
    }

    /**
     * Substring, because the SQL is a LIKE with wildcards on both sides. A
     * shopper who types half a SKU still gets the product, so the variant that
     * carries it still has to light up.
     */
    public function testMatchesAPartialSku()
    {
        $this->assertTrue(SearchMatch::variantMatches('P2-M', 'Medium', '2-M'));
    }

    /**
     * SKUs get typed in whatever case is at hand. MySQL's default collation is
     * case-insensitive, so the row comes back; stripos() keeps the marking in
     * step with it.
     */
    public function testMatchingIsCaseInsensitive()
    {
        $this->assertTrue(SearchMatch::variantMatches('P2-m', 'Medium', 'p2-M'));
    }

    /**
     * The variation title is the second half of the same OR in the SQL.
     */
    public function testMatchesOnVariationTitle()
    {
        $this->assertTrue(SearchMatch::variantMatches('', 'Large', 'larg'));
    }

    /**
     * A sibling variant of a matched product must NOT be marked. This is the
     * assertion that makes the feature a feature: the shopper sees the whole
     * product, with one row picked out.
     */
    public function testDoesNotMatchASiblingVariant()
    {
        $this->assertFalse(SearchMatch::variantMatches('P2-S', 'Small', 'P2-M'));
    }

    /**
     * Empty search matches nothing, not everything.
     *
     * This is the Product Table's default browse state. Marking every variant
     * there would be identical to marking none, but it would also paint the
     * whole catalogue in highlight styling.
     */
    public function testEmptySearchMatchesNothing()
    {
        $this->assertFalse(SearchMatch::variantMatches('P2-M', 'Medium', ''));
        $this->assertFalse(SearchMatch::variantMatches('P2-M', 'Medium', '   '));
    }

    /**
     * A variant with neither field filled cannot match anything. Guards against
     * '' being found inside '' and marking every empty variant on the page.
     */
    public function testEmptyVariantFieldsNeverMatch()
    {
        $this->assertFalse(SearchMatch::variantMatches('', '', 'anything'));
        $this->assertFalse(SearchMatch::variantMatches(null, null, 'anything'));
    }

    /**
     * Whitespace around a pasted SKU is the norm, not the exception.
     */
    public function testSearchTermIsTrimmed()
    {
        $this->assertTrue(SearchMatch::variantMatches('P2-M', 'Medium', '  P2-M  '));
    }

    /**
     * A one-character term is not a search, and must mark nothing.
     *
     * This is a regression test for a bug found reviewing #35. Both endpoints
     * refuse to filter below two characters — listCatalog() skips its WHERE
     * clause entirely and returns the whole catalogue — but the marking did not
     * know that. Typing "1" into the Product Table returned all 50 products,
     * correctly unfiltered, while still highlighting and reordering any variant
     * whose SKU happened to contain a "1".
     */
    public function testATermShorterThanTheMinimumMatchesNothing()
    {
        $this->assertFalse(SearchMatch::variantMatches('115523', 'Teal', '1'));
        $this->assertFalse(SearchMatch::variantMatches('P2-M', 'Medium', 'M'));
    }

    /**
     * Two characters is the floor, and it is inclusive — the endpoints filter
     * at exactly two, so the marking has to as well.
     */
    public function testTheMinimumLengthIsInclusive()
    {
        $this->assertSame(2, SearchMatch::MIN_LENGTH);
        $this->assertTrue(SearchMatch::variantMatches('115523', 'Teal', '11'));
    }

    /**
     * isSearching() decides whether the `search_match` KEY belongs in a payload
     * at all, which is a different question from whether one variant matched.
     * Absent means "this endpoint was not searching" — the honest answer for
     * resolve-skus, whose response shape must not change.
     */
    public function testIsSearchingDistinguishesBrowsingFromSearching()
    {
        $this->assertFalse(SearchMatch::isSearching(''));
        $this->assertFalse(SearchMatch::isSearching('   '));
        $this->assertFalse(SearchMatch::isSearching('a'));
        $this->assertTrue(SearchMatch::isSearching('ab'));
        $this->assertTrue(SearchMatch::isSearching('  ab  '));
    }

    /**
     * Matched variants lead; everything else follows.
     */
    public function testMatchedVariantsAreMovedToTheFront()
    {
        $ordered = SearchMatch::matchedFirst([
            ['id' => 1, 'search_match' => false],
            ['id' => 2, 'search_match' => true],
            ['id' => 3, 'search_match' => false],
        ]);

        $this->assertSame([2, 1, 3], array_column($ordered, 'id'));
    }

    /**
     * Order WITHIN each group survives, which is the reason matchedFirst() is
     * two passes and not a usort(). usort() is unstable before PHP 8.0 and this
     * plugin supports 7.4, so a comparator would scramble a store owner's
     * deliberate S / M / L ordering on exactly the older sites least likely to
     * report it clearly.
     */
    public function testOrderWithinEachGroupIsPreserved()
    {
        $ordered = SearchMatch::matchedFirst([
            ['id' => 1, 'search_match' => false],
            ['id' => 2, 'search_match' => true],
            ['id' => 3, 'search_match' => false],
            ['id' => 4, 'search_match' => true],
            ['id' => 5, 'search_match' => false],
        ]);

        $this->assertSame([2, 4, 1, 3, 5], array_column($ordered, 'id'));
    }

    /**
     * Nothing matched: the list comes back exactly as it went in, which is what
     * keeps the default browse state looking untouched.
     */
    public function testNoMatchesLeavesTheOrderAlone()
    {
        $input = [
            ['id' => 1, 'search_match' => false],
            ['id' => 2, 'search_match' => false],
        ];

        $this->assertSame([1, 2], array_column(SearchMatch::matchedFirst($input), 'id'));
    }

    /**
     * A payload with no `search_match` key at all counts as unmatched rather
     * than throwing, so an older cached response cannot break the sort.
     */
    public function testMissingFlagIsTreatedAsUnmatched()
    {
        $ordered = SearchMatch::matchedFirst([
            ['id' => 1],
            ['id' => 2, 'search_match' => true],
        ]);

        $this->assertSame([2, 1], array_column($ordered, 'id'));
    }

    /**
     * The result is a clean list, not a list with holes in its keys — it is
     * about to be JSON-encoded, and a gapped array becomes an object there,
     * which would change the payload's shape for the browser.
     */
    public function testResultIsSequentiallyIndexed()
    {
        $ordered = SearchMatch::matchedFirst([
            ['id' => 1, 'search_match' => false],
            ['id' => 2, 'search_match' => true],
        ]);

        $this->assertSame([0, 1], array_keys($ordered));
    }
}
