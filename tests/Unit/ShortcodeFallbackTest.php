<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Shortcodes\ShortcodeHandler;
use PHPUnit\Framework\TestCase;

/**
 * What a page holding one of this plugin's tags does when FluentCart is gone.
 *
 * This is the one path nobody exercises by hand — you have to deactivate the
 * host plugin on a live store to see it — and it is also the path a store owner
 * is most likely to hit, because deactivating a plugin is a normal thing to do
 * while debugging something else. Before this was pinned, the tags were simply
 * not registered and WordPress printed `[fluent_cart_bulk_order]` into the page
 * for every shopper on it.
 *
 * The whole suite runs with FLUENTCART_VERSION undefined, so "FluentCart is
 * absent" needs no setup here: it is the ambient state, and that is deliberate.
 * Defining the constant to test the other half is not possible — a constant
 * cannot be undefined again, so it would leak into every test that ran
 * afterwards. The FluentCart-present path belongs to the manual test plan.
 *
 * @see \FluentCartBulkOrder\Shortcodes\ShortcodeHandler
 */
class ShortcodeFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['fcbo_test_caps']       = [];
        $GLOBALS['fcbo_test_shortcodes'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['fcbo_test_caps']       = [];
        $GLOBALS['fcbo_test_shortcodes'] = [];
    }

    /**
     * Registration is the entire fix for the raw-tag-text problem, and it has
     * to happen with no host plugin behind it.
     *
     * Driven off SHORTCODES rather than a literal list of tags. That constant is
     * documented as the single source of truth for which tags this plugin owns,
     * so a fifth tag added there is covered here on the day it is added — a
     * hand-written list would quietly stop covering the newest surface, which is
     * the one most likely to be wrong.
     */
    public function testEveryOwnedTagIsRegisteredEvenWithNoFluentCart()
    {
        (new ShortcodeHandler())->register();

        $this->assertSame(
            array_keys(ShortcodeHandler::SHORTCODES),
            array_keys($GLOBALS['fcbo_test_shortcodes'])
        );
    }

    /**
     * Registering is only half of it: WordPress has to be given something it
     * can actually call, and it calls the tag it matched back as the third
     * argument. Dispatching through the stored callback rather than through
     * renderTag() is the point — it covers the wiring, not just the renderer.
     */
    public function testTheRegisteredCallbackIsCallableAndDispatchesByTag()
    {
        (new ShortcodeHandler())->register();

        foreach ($GLOBALS['fcbo_test_shortcodes'] as $tag => $callback) {
            $this->assertIsCallable($callback);
            $this->assertSame('', call_user_func($callback, [], null, $tag));
        }
    }

    /**
     * The acceptance criterion, stated as an assertion: nothing a shopper sees
     * may contain the tag text. An empty region reads as "nothing here", which
     * is true without a catalog behind it; `[fluent_cart_bulk_order]` reads as a
     * broken store.
     */
    public function testAShopperSeesNothingRatherThanRawTagText()
    {
        foreach (array_keys(ShortcodeHandler::SHORTCODES) as $tag) {
            $html = ShortcodeHandler::renderTag($tag, []);

            $this->assertSame('', $html, $tag . ' leaked output to a shopper');
            $this->assertStringNotContainsString('[' . $tag . ']', $html);
        }
    }

    /**
     * A logged-in customer is still a shopper. The gate is the capability, not
     * the session, because a wholesale customer viewing their own saved orders
     * can do nothing about a missing plugin either.
     */
    public function testANonAdminWithOtherCapabilitiesStillSeesNothing()
    {
        $GLOBALS['fcbo_test_caps'] = ['read', 'edit_posts'];

        foreach (array_keys(ShortcodeHandler::SHORTCODES) as $tag) {
            $this->assertSame('', ShortcodeHandler::renderTag($tag, []));
        }
    }

    /**
     * The other half of the split. An owner gets told, in place, because the
     * admin notice on the dashboard cannot say WHICH page lost a surface.
     */
    public function testAnAdminGetsAnInPlaceExplanation()
    {
        $GLOBALS['fcbo_test_caps'] = ['manage_options'];

        foreach (array_keys(ShortcodeHandler::SHORTCODES) as $tag) {
            $html = ShortcodeHandler::renderTag($tag, []);

            $this->assertNotSame('', $html, $tag . ' told the admin nothing');
            $this->assertStringContainsString('FluentCart', $html);
            $this->assertStringNotContainsString('[' . $tag . ']', $html);
        }
    }

    /**
     * The message is the only string this plugin prints on a public page with
     * no host plugin loaded, so it is the only one that cannot rely on any
     * other layer to escape it.
     *
     * Asserting "no angle bracket survives inside the paragraph" rather than
     * comparing against an expected sentence: the wording is free to change,
     * the escaping is not, and a future edit that interpolated a site value in
     * here unescaped would fail this and pass a string comparison.
     */
    public function testTheAdminMessageIsEscapedAndSelfContained()
    {
        $GLOBALS['fcbo_test_caps'] = ['manage_options'];

        $html = ShortcodeHandler::renderTag('fluent_cart_bulk_order', []);

        $this->assertSame(1, preg_match('~^<p class="[a-z-]+">(.*)</p>$~s', $html, $m));
        $this->assertNotSame('', trim($m[1]));
        $this->assertStringNotContainsString('<', $m[1]);
        $this->assertStringNotContainsString('>', $m[1]);
    }

    /**
     * A tag this plugin does not own must fall through untouched, admin or not.
     * renderTag() is public and the `fcbo_render_*` helpers call it by name, so
     * a typo there must not start printing an FCBO message under someone else's
     * shortcode.
     */
    public function testATagThisPluginDoesNotOwnRendersNothing()
    {
        $GLOBALS['fcbo_test_caps'] = ['manage_options'];

        $this->assertSame('', ShortcodeHandler::renderTag('gallery', []));
        $this->assertSame('', ShortcodeHandler::renderTag('', []));
        $this->assertSame('', ShortcodeHandler::renderTag('fluent_cart_bulk_orders', []));
    }

    /**
     * The property that makes it safe to register on the wrong side of the
     * bootstrap guard: neither registering nor rendering without a host may
     * pull in a shortcode class, because every one of those classes calls
     * FluentCart once it renders.
     *
     * If this ever fails, the fix is not to load the class anyway — it is that
     * something started resolving the class before the FLUENTCART_VERSION
     * check, and the next step after that is a fatal on a storefront page.
     */
    public function testNoShortcodeClassIsLoadedWhileTheHostIsMissing()
    {
        $GLOBALS['fcbo_test_caps'] = ['manage_options'];

        (new ShortcodeHandler())->register();

        foreach (ShortcodeHandler::SHORTCODES as $tag => $class) {
            ShortcodeHandler::renderTag($tag, []);

            $this->assertFalse(
                class_exists('FluentCartBulkOrder\\Shortcodes\\' . $class, false),
                $class . ' was loaded with no FluentCart behind it'
            );
        }
    }
}
