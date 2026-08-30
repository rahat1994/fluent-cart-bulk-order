<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\Admin\Menu;
use FluentCartBulkOrder\Analytics\AnalyticsScreen;
use FluentCartBulkOrder\Export\OrderExportScreen;
use FluentCartBulkOrder\Quotes\QuoteReviewScreen;
use FluentCartBulkOrder\Settings;
use FluentCartBulkOrder\Wholesale\ReviewScreen;
use PHPUnit\Framework\TestCase;

/**
 * The admin menu's ordering rule and its count bubbles.
 *
 * Everything asserted here fails silently in a browser rather than loudly in a
 * log. A slug that has drifted from the screen that owns it hangs the submenu
 * off nothing and 404s; a settings priority that is no longer the lowest puts a
 * duplicated "Bulk Order" row back at the top of the menu, which reads as a
 * WordPress bug; a bubble that renders at zero tells an owner there is work
 * waiting when there is none.
 */
class AdminMenuTest extends TestCase
{
    /**
     * Menu repeats every screen slug as a literal so a flow can ask for its
     * position without loading an admin-only class. That is only safe while the
     * copies agree with the originals.
     */
    public function testEverySlugMatchesTheScreenThatOwnsIt()
    {
        $this->assertSame(Settings::PAGE_SLUG, Menu::PARENT_SLUG);
        $this->assertSame(QuoteReviewScreen::PAGE_SLUG, Menu::SLUG_QUOTES);
        $this->assertSame(ReviewScreen::PAGE_SLUG, Menu::SLUG_WHOLESALE);
        $this->assertSame(AnalyticsScreen::PAGE_SLUG, Menu::SLUG_ANALYTICS);
        $this->assertSame(OrderExportScreen::PAGE_SLUG, Menu::SLUG_EXPORTS);
    }

    /**
     * The order the issue asked for: settings, then the two screens holding
     * work, then the two reports.
     */
    public function testSubmenusAreOrderedSettingsQuotesWholesaleAnalyticsExport()
    {
        $this->assertSame(
            [
                Menu::PARENT_SLUG,
                Menu::SLUG_QUOTES,
                Menu::SLUG_WHOLESALE,
                Menu::SLUG_ANALYTICS,
                Menu::SLUG_EXPORTS,
            ],
            Menu::order()
        );
    }

    /**
     * WordPress inserts a copy of the parent as the first submenu row unless
     * the FIRST add_submenu_page() for that parent uses the parent's own slug.
     * The settings screen is that call, so it has to register before the rest.
     */
    public function testSettingsRegistersFirstSoNoDuplicateParentRowAppears()
    {
        $this->assertSame(Menu::PARENT_SLUG, Menu::order()[0]);

        foreach (Menu::SUBMENU_PRIORITIES as $slug => $priority) {
            if ($slug === Menu::PARENT_SLUG) {
                continue;
            }

            $this->assertGreaterThan(
                Menu::priority(Menu::PARENT_SLUG),
                $priority,
                $slug . ' must register after the settings screen'
            );
        }
    }

    /**
     * add_submenu_page() cannot attach to a parent that does not exist yet, and
     * the parent's bubble is the sum of numbers the submenus report, so it can
     * only be written once they have all run.
     */
    public function testTheParentIsRegisteredBeforeEverySubmenuAndBubbledAfter()
    {
        foreach (Menu::SUBMENU_PRIORITIES as $slug => $priority) {
            $this->assertGreaterThan(Menu::PARENT_PRIORITY, $priority, $slug);
            $this->assertLessThan(Menu::BUBBLE_PRIORITY, $priority, $slug);
        }
    }

    /**
     * A screen added later that forgets to list itself must land at the bottom,
     * never above settings — see the duplicate-row test above for what "above
     * settings" costs.
     */
    public function testAnUnlistedScreenSortsLastAndStillBeforeTheBubble()
    {
        $priority = Menu::priority('fcbo-something-new');

        $this->assertGreaterThan(max(Menu::SUBMENU_PRIORITIES), $priority);
        $this->assertLessThan(Menu::BUBBLE_PRIORITY, $priority);
    }

    /**
     * A slug missing from this map is a screen whose old bookmarks die with
     * "Cannot load" — WordPress refuses a plugin page requested under a parent
     * file it was not registered on.
     */
    public function testEveryScreenHasAnOldAddressThatStillResolves()
    {
        $redirected = array_merge(...array_values(Menu::LEGACY_PARENTS));

        sort($redirected);
        $expected = Menu::order();
        sort($expected);

        $this->assertSame($expected, $redirected);
        $this->assertSame(
            [Menu::SLUG_WHOLESALE],
            Menu::LEGACY_PARENTS['users.php'],
            'only the wholesale screen ever lived under Users'
        );
    }

    public function testABubbleIsAppendedOnlyWhenSomethingIsWaiting()
    {
        $this->assertSame('Quote Requests', Menu::bubbleTitle('Quote Requests', 0));
        $this->assertSame('Quote Requests', Menu::bubbleTitle('Quote Requests', -3));

        $this->assertSame(
            'Quote Requests <span class="awaiting-mod"><span class="pending-count">4</span></span>',
            Menu::bubbleTitle('Quote Requests', 4)
        );
    }

    /**
     * The count reaches this method from a database query, and the result is
     * echoed into the sidebar as raw markup by WordPress itself. Casting is the
     * whole defence.
     */
    public function testTheCountIsCastToAnIntegerBeforeItReachesTheMarkup()
    {
        $this->assertSame(
            'Applications <span class="awaiting-mod"><span class="pending-count">2</span></span>',
            Menu::bubbleTitle('Applications', '2<script>')
        );

        $this->assertSame('Applications', Menu::bubbleTitle('Applications', 'none'));
    }
}
