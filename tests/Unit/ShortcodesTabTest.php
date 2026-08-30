<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Admin\Settings\Tab;
use FluentCartBulkOrder\Admin\Settings\Tabs;
use PHPUnit\Framework\TestCase;

/**
 * The Shortcodes tab's two structural claims, both of which fail silently.
 *
 * The tab is the first one on the settings page that stores nothing and draws
 * action buttons of its own. Those buttons are forms posting to admin-post.php,
 * and HTML has no nested forms — a browser drops the inner one. So a tab that
 * said it was a form would produce a screen where every Create page button
 * quietly saves the settings and reports "Settings saved", with no page made
 * and nothing to suggest anything went wrong.
 *
 * @see \FluentCartBulkOrder\Admin\Settings\Tab::isForm()
 * @see \FluentCartBulkOrder\Admin\Settings\ShortcodesTab
 */
class ShortcodesTabTest extends TestCase
{
    /**
     * The tab is actually on the settings page.
     */
    public function testTheShortcodesTabIsRegistered()
    {
        $this->assertArrayHasKey(Tabs::SHORTCODES, Tabs::all());
    }

    /**
     * It is not wrapped in the settings form, and its buttons therefore reach
     * their own action.
     */
    public function testTheShortcodesTabIsNotAForm()
    {
        $this->assertFalse(Tabs::get(Tabs::SHORTCODES)->isForm());
    }

    /**
     * Every OTHER tab still is one. isForm() defaulting to true is what keeps
     * the five settings tabs saving; a change to that default would take the
     * Save button off all of them.
     */
    public function testEveryTabThatStoresSomethingIsStillAForm()
    {
        foreach (Tabs::all() as $slug => $tab) {
            if ($slug === Tabs::SHORTCODES) {
                continue;
            }

            $this->assertTrue($tab->isForm(), $slug . ' stopped being a form and lost its Save button');
        }
    }

    /**
     * A tab with nothing to save must declare no shared-option keys.
     *
     * Settings::registerStoreDefaults() reads this list to decide whether to
     * register the store-defaults option into the tab's group. A read-only tab
     * that named a key would put that option into a group nothing ever posts,
     * which is the shape of the wipe-on-save bug SettingsTabsTest exists for.
     */
    public function testTheShortcodesTabOwnsNoStoredKeys()
    {
        $this->assertSame([], Tabs::get(Tabs::SHORTCODES)->defaultsKeys());
    }

    /**
     * A non-form tab is a deliberate exception, so there is exactly one.
     *
     * Not a style rule. Every other tab on this page is where an owner changes
     * something, and a second reference tab would be a sign the settings page
     * had started collecting documentation instead of settings.
     */
    public function testOnlyOneTabOptsOutOfTheForm()
    {
        $readOnly = [];

        foreach (Tabs::all() as $slug => $tab) {
            $this->assertInstanceOf(Tab::class, $tab);

            if (!$tab->isForm()) {
                $readOnly[] = $slug;
            }
        }

        $this->assertSame([Tabs::SHORTCODES], $readOnly);
    }
}
