<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Admin\ShortcodePages;
use FluentCartBulkOrder\Shortcodes\AttributeSchema;
use FluentCartBulkOrder\Shortcodes\ShortcodeCatalog;
use FluentCartBulkOrder\Shortcodes\ShortcodeHandler;
use PHPUnit\Framework\TestCase;

/**
 * The Shortcodes admin tab cannot fall behind the shortcode registry.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------------
 *
 * The whole point of the Shortcodes tab is that it tells a store owner what is
 * really registered. A fifth shortcode added to ShortcodeHandler::SHORTCODES
 * appears on that screen automatically — the screen iterates the registry — but
 * it appears with no sentence explaining it and no attribute descriptions, and
 * nothing on screen says so. It just looks thin.
 *
 * That is the failure these tests exist to make loud: a tag with no
 * description, an attribute with no help text, a description left behind by a
 * tag that was removed, or a tag whose name the usage scan cannot find.
 *
 * @see \FluentCartBulkOrder\Shortcodes\ShortcodeCatalog
 * @see \FluentCartBulkOrder\Admin\Settings\ShortcodesTab
 */
class ShortcodeCatalogTest extends TestCase
{
    /**
     * The registry is the list, and nothing else is.
     *
     * If this ever fails it means somebody gave the catalog a list of its own,
     * which is the one thing ShortcodeHandler::SHORTCODES is documented to
     * forbid.
     */
    public function testTheCatalogListsExactlyTheRegisteredTags()
    {
        $this->assertSame(array_keys(ShortcodeHandler::SHORTCODES), ShortcodeCatalog::tags());
    }

    /**
     * Every registered tag has a title and a sentence, so it renders a real
     * card rather than a heading-shaped gap.
     */
    public function testEveryRegisteredTagHasALabelAndADescription()
    {
        foreach (ShortcodeCatalog::tags() as $tag) {
            $this->assertNotSame(
                $tag,
                ShortcodeCatalog::label($tag),
                $tag . ' has no human label, so the tab would print the raw tag as its heading'
            );

            $this->assertNotSame(
                '',
                ShortcodeCatalog::description($tag),
                $tag . ' has no description, so the tab would show an owner a tag and no idea what it does'
            );
        }
    }

    /**
     * And nothing is documented that is not registered.
     *
     * A description left behind by a removed shortcode is worse than a missing
     * one: it never renders, so nobody notices it is wrong, and the next
     * reader takes it as evidence the tag still exists.
     */
    public function testNothingIsDescribedThatIsNotRegistered()
    {
        $tags = ShortcodeCatalog::tags();

        $this->assertSame($tags, array_keys(ShortcodeCatalog::labels()));
        $this->assertSame($tags, array_keys(ShortcodeCatalog::descriptions()));
    }

    /**
     * Every attribute the tab will print carries a type an owner can read and
     * a sentence saying what it is for.
     */
    public function testEveryAttributeOfEveryTagIsDocumented()
    {
        foreach (ShortcodeCatalog::tags() as $tag) {
            foreach (ShortcodeCatalog::attributes($tag) as $name => $attribute) {
                $this->assertNotSame(
                    '',
                    $attribute['help'],
                    $tag . '[' . $name . '] has no help text'
                );

                $this->assertArrayHasKey(
                    $attribute['type'],
                    ShortcodeCatalog::typeLabels(),
                    $tag . '[' . $name . '] has a type with no human name, so the tab would print "ternary" at an owner'
                );
            }
        }
    }

    /**
     * Nothing in attributeHelp() describes an attribute no tag accepts.
     *
     * Same argument as the stale description above: help for an attribute that
     * does not exist reads, to the next person, as proof that it does.
     */
    public function testNoHelpTextDescribesAnAttributeNoTagAccepts()
    {
        $used = [];

        foreach (ShortcodeCatalog::tags() as $tag) {
            foreach (array_keys(ShortcodeCatalog::attributes($tag)) as $name) {
                $used[$name] = true;
            }
        }

        foreach (array_keys(ShortcodeCatalog::attributeHelp()) as $name) {
            $this->assertArrayHasKey($name, $used, $name . ' is documented but no shortcode accepts it');
        }
    }

    /**
     * The rule that keeps the attribute list single-sourced.
     *
     * AttributeSchema owns the attributes of the tags it covers. EXTRA_ATTRIBUTES
     * exists only for attributes NO schema owns, and the moment the two overlap
     * the plugin has two definitions of the same attribute — which is exactly
     * the drift the catalog was written to avoid.
     */
    public function testTheCatalogNeverRedeclaresAnAttributeTheSchemaOwns()
    {
        foreach (ShortcodeCatalog::EXTRA_ATTRIBUTES as $tag => $attributes) {
            $owned = AttributeSchema::controls($tag);

            foreach (array_keys($attributes) as $name) {
                $this->assertArrayNotHasKey(
                    $name,
                    $owned,
                    $tag . '[' . $name . '] is declared twice: once in AttributeSchema and again in the catalog'
                );
            }
        }
    }

    /**
     * EXTRA_ATTRIBUTES cannot invent a tag either.
     */
    public function testExtraAttributesOnlyCoverRegisteredTags()
    {
        foreach (array_keys(ShortcodeCatalog::EXTRA_ATTRIBUTES) as $tag) {
            $this->assertArrayHasKey($tag, ShortcodeHandler::SHORTCODES, $tag . ' is not a registered shortcode');
        }
    }

    /**
     * A tag is said to have a block and a widget only if the editor schema says
     * so, and every such tag is really registered.
     */
    public function testEditorWrappersAreClaimedOnlyForSchemaTags()
    {
        foreach (ShortcodeCatalog::tags() as $tag) {
            $this->assertSame(
                in_array($tag, AttributeSchema::tags(), true),
                ShortcodeCatalog::hasEditorWrappers($tag),
                $tag . ' disagrees with AttributeSchema about whether it has an editor wrapper'
            );
        }

        foreach (AttributeSchema::tags() as $tag) {
            $this->assertArrayHasKey(
                $tag,
                ShortcodeHandler::SHORTCODES,
                $tag . ' has an editor wrapper but is not a registered shortcode'
            );
        }
    }

    /**
     * The name the tab prints is the name an owner will type into the block
     * inserter.
     *
     * Read out of the block.json files rather than restated here. The tab tells
     * an owner "it is also a 'Product Table' block"; if the block is actually
     * called something else, that sentence sends them looking for a block that
     * does not exist and they conclude the feature is missing.
     */
    public function testTheLabelOfAWrappedTagMatchesItsBlockTitle()
    {
        $titles = [];

        foreach (glob(dirname(__DIR__, 2) . '/blocks/*/block.json') as $file) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a file that ships in this repository, from a suite that deliberately has no WordPress behind it. There is no wp_remote_get() here and no remote URL to fetch.
            $metadata = json_decode((string) file_get_contents($file), true);

            if (is_array($metadata) && isset($metadata['title'])) {
                $titles[] = $metadata['title'];
            }
        }

        $this->assertNotEmpty($titles, 'no block.json files were found, so this test proves nothing');

        $labels = [];

        foreach (ShortcodeCatalog::tags() as $tag) {
            if (ShortcodeCatalog::hasEditorWrappers($tag)) {
                $labels[] = ShortcodeCatalog::label($tag);
            }
        }

        sort($titles);
        sort($labels);

        $this->assertSame($titles, $labels, 'a block is called one thing in the inserter and another on the settings tab');
    }

    /**
     * The snippet an owner copies is the tag and nothing else.
     */
    public function testTheSnippetIsTheBareTag()
    {
        foreach (ShortcodeCatalog::tags() as $tag) {
            $this->assertSame('[' . $tag . ']', ShortcodeCatalog::snippet($tag));
        }
    }

    /**
     * Every registered tag is findable by the one query the usage lookup runs.
     *
     * ShortcodePages::scan() narrows with a single LIKE on the prefix all our
     * tags share. A future tag that did not share it would never be reported as
     * "already on a page", so the tab would offer to create a page that already
     * exists — silently, and only on sites that had set it up.
     */
    public function testEveryTagIsFoundByTheUsageScansPrefix()
    {
        foreach (ShortcodeCatalog::tags() as $tag) {
            $this->assertStringStartsWith(
                ShortcodePages::TAG_PREFIX,
                ShortcodeCatalog::snippet($tag),
                $tag . ' does not share the prefix the usage scan searches for'
            );
        }
    }
}
