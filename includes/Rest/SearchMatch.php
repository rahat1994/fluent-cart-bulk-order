<?php

namespace FluentCartBulkOrder\Rest;

defined('ABSPATH') || exit;

/**
 * Which variants a catalogue search actually matched.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS ITS OWN CLASS
 * ---------------------------------------------------------------------------
 *
 * Two endpoints in ProductsController answer the same question and used to
 * answer it differently, which is the whole of issue #35:
 *
 *   searchProducts()  dropped every variant that did not match, so a SKU search
 *                     returned the exact variant — and hid its sibling sizes.
 *   listCatalog()     returned every variant with no marker at all, so a SKU
 *                     search returned the whole product and left the shopper to
 *                     find the one they typed.
 *
 * Neither is right. A shopper who types a SKU wants that variant AND the
 * context of the ones next to it. So both endpoints now return everything and
 * mark what matched, and the marking rule lives here so the two cannot drift
 * apart again.
 *
 * Pure on purpose — no WordPress, no database, no models. That is what lets
 * tests/Unit/SearchMatchTest.php pin the rule without a store behind it.
 *
 * ---------------------------------------------------------------------------
 * THE FIELD IS `search_match`, NOT `matched`
 * ---------------------------------------------------------------------------
 *
 * ProductsController::resolveSkus() already ships a key called `matched`, and
 * it means something else entirely: a STATUS STRING of 'matched' / 'ambiguous'
 * / 'unknown' describing how one pasted SKU resolved. Reusing that word for a
 * per-variant boolean would put two unrelated meanings behind one name in
 * payloads the same JavaScript file consumes. Hence `search_match`.
 */
class SearchMatch
{
    /**
     * Did this variant match the search term?
     *
     * The comparison mirrors the SQL that selected the product in the first
     * place: both endpoints query `sku LIKE %term%` OR `variation_title LIKE
     * %term%`, so the marking has to be the same substring test, case-insensitive.
     * If these two ever disagree, a product comes back from the database with
     * nothing marked on it and the shopper sees a result they cannot explain.
     *
     * The product TITLE is deliberately not consulted. A title match is why the
     * whole product is in the result set; it says nothing about which variant
     * the shopper meant, and marking every variant would be the same as marking
     * none.
     *
     * @param string|null $sku            The variant's SKU.
     * @param string|null $variationTitle The variant's title.
     * @param string      $search         Raw search term as typed.
     * @return bool
     */
    public static function variantMatches($sku, $variationTitle, $search)
    {
        $search = trim((string) $search);

        // An empty search matches nothing rather than everything. This is the
        // Product Table's default browse state, where there is no query to have
        // matched — every row must look ordinary.
        if ($search === '') {
            return false;
        }

        $sku = (string) $sku;
        if ($sku !== '' && stripos($sku, $search) !== false) {
            return true;
        }

        $variationTitle = (string) $variationTitle;

        return $variationTitle !== '' && stripos($variationTitle, $search) !== false;
    }

    /**
     * Move matched variants to the front, preserving order within each group.
     *
     * Stable by construction — two passes over the list rather than a sort with
     * a comparator. usort() is NOT stable before PHP 8.0, and this plugin
     * supports 7.4, so a comparator would silently shuffle same-group variants
     * on older sites: a store owner's deliberate S / M / L ordering would come
     * back scrambled on PHP 7.4 and correct on 8.0, which is the worst kind of
     * bug to be told about second-hand.
     *
     * @param array<int,array> $variants Variant payloads carrying `search_match`.
     * @return array<int,array> Re-indexed, matched first.
     */
    public static function matchedFirst(array $variants)
    {
        $matched = [];
        $rest    = [];

        foreach ($variants as $variant) {
            if (!empty($variant['search_match'])) {
                $matched[] = $variant;
                continue;
            }

            $rest[] = $variant;
        }

        return array_merge($matched, $rest);
    }
}
