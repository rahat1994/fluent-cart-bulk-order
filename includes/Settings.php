<?php

namespace FluentCartBulkOrder;

use FluentCartBulkOrder\Admin\Menu;
use FluentCartBulkOrder\Admin\Settings\Tab;
use FluentCartBulkOrder\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

/**
 * The plugin's one admin page: every store-wide default, behind five tabs.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS CLASS IS NOW
 * ---------------------------------------------------------------------------
 *
 * The shell, and only the shell: the menu entry, the tab strip, the form
 * element, and the loop that asks each tab to register itself. Every section,
 * field, sanitizer and piece of help text lives on the tab that owns it, under
 * includes/Admin/Settings/. @see \FluentCartBulkOrder\Admin\Settings\Tab for
 * why the split runs vertically, one class per tab, rather than horizontally.
 *
 * ---------------------------------------------------------------------------
 * WHAT LIVES WHERE
 * ---------------------------------------------------------------------------
 *
 * The values behind the page are split in two, and the split is deliberate:
 *
 *   - Three of the four role gates keep their own top-level options, named by
 *     AccessPolicy (`fcbo_apply_to_roles`, `fcbo_min_order_total`,
 *     `fcbo_min_order_total_roles`). They predate this page; renaming them would
 *     drop the saved policy of every store that already configured one. All
 *     three are on the Pricing tab.
 *
 *   - Everything else lives in one serialized array owned by StoreDefaults,
 *     which also holds the sanitizer and the precedence rule. Gate 4, the PO
 *     number policy, is there rather than in an option of its own — the three
 *     above are a compatibility decision, not a pattern to copy.
 *
 * The gate LOGIC is in AccessPolicy, which documents how the gates differ.
 * Nothing here decides who gets what; it only stores what the owner chose.
 *
 * ---------------------------------------------------------------------------
 * TABS ARE NOT ONLY A LAYOUT
 * ---------------------------------------------------------------------------
 *
 * A tabbed form posts one tab's fields. That single fact is what makes this
 * page dangerous, because both stores behind it read "absent" as "cleared":
 *
 *   the top-level options  wp-admin/options.php writes EVERY option in the
 *                          submitted group, absent ones included, as null.
 *                          Fixed by giving each tab its own group. @see Tabs
 *
 *   the shared array       one sanitizer rebuilds the whole option from what
 *                          was posted. Fixed by having the form declare which
 *                          keys it carries.
 *                          @see \FluentCartBulkOrder\StoreDefaults::SUBMITTED_KEYS
 *
 * Neither failure raises an error. Both report "Settings saved" and quietly
 * undo work the owner did on another tab, which is why SettingsTabsTest exists.
 *
 * ---------------------------------------------------------------------------
 * ADDING A SECTION
 * ---------------------------------------------------------------------------
 *
 * Add it to the tab it belongs to. If it stores something new: add a key and
 * its fallback to StoreDefaults::FALLBACKS, validate it in
 * StoreDefaults::sanitize(), and list the key in that tab's defaultsKeys().
 * Miss the last step and the field renders, saves nothing, and says it saved.
 *
 * Adding a whole tab is one class extending Tab plus one line in Tabs::CLASSES.
 */
class Settings
{
    /**
     * Settings API group PREFIX. Each tab posts to `<prefix>_<tab slug>`.
     *
     * Not a group in its own right any more, and nothing registers it. @see
     * \FluentCartBulkOrder\Admin\Settings\Tabs for why an alias group would be
     * worse than the error a stale form now gets.
     */
    const OPTION_GROUP = 'fcbo_settings';

    /**
     * Admin page slug. Unchanged, and the tab lives in `?tab=` beside it, so
     * every bookmark and every emailed link still resolves.
     */
    const PAGE_SLUG = 'fcbo-settings';

    /**
     * Hook the settings page into the admin.
     *
     * @return void
     */
    public function register()
    {
        // The priority is the menu position. @see \FluentCartBulkOrder\Admin\Menu
        add_action('admin_menu', [$this, 'addSettingsPage'], Menu::priority(self::PAGE_SLUG));
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * Register the settings screen as the first submenu of the Bulk Order menu.
     *
     * Its slug IS the parent's slug, so this screen is what the parent row
     * opens and no duplicated "Bulk Order" entry appears above it — the whole
     * reason it must register before the other four. Menu::addMenuPage() passes
     * no callback of its own precisely so this one is the only renderer.
     * @see \FluentCartBulkOrder\Admin\Menu
     *
     * @return void
     */
    public function addSettingsPage()
    {
        add_submenu_page(
            Menu::PARENT_SLUG,
            __('Fluent Cart Bulk Order', 'fluent-cart-bulk-order'),
            __('Settings', 'fluent-cart-bulk-order'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Register every option, section and field, for every tab.
     *
     * EVERY tab, not the visible one. This runs on `admin_init`, which is also
     * the hook wp-admin/options.php runs under — and options.php has no idea
     * which tab was on screen. Registering only the current tab would leave the
     * submitted group unregistered at save time, and options.php would refuse
     * the save outright.
     *
     * @return void
     */
    public function registerSettings()
    {
        $this->loadTabs();

        foreach (Tabs::all() as $slug => $tab) {
            $group = Tabs::group($slug);

            $this->registerStoreDefaults($tab, $group);

            $tab->registerOptions($group);
            $tab->registerSections(Tabs::sectionPage($slug));
        }
    }

    /**
     * Register the shared `fcbo_store_defaults` option into one tab's group.
     *
     * Once per tab that actually writes into it, and not at all for a tab that
     * does not. A tab with no keys of its own must not have the option in its
     * group: options.php would then hand the sanitizer a null on every save of
     * that tab, and the whole array would fall back to its defaults.
     *
     * @param Tab    $tab
     * @param string $group This tab's option group.
     * @return void
     */
    private function registerStoreDefaults(Tab $tab, $group)
    {
        if (!$tab->defaultsKeys()) {
            return;
        }

        register_setting(
            $group,
            StoreDefaults::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [StoreDefaults::class, 'sanitize'],
                'default'           => [],
            ]
        );
    }

    /**
     * Render the settings page: tab strip, then the visible tab's form.
     *
     * @return void
     */
    public function renderPage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->loadTabs();

        $slug = Tabs::current($this->requestedTab());
        $tab  = Tabs::get($slug);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Fluent Cart Bulk Order', 'fluent-cart-bulk-order') . '</h1>';

        $this->renderTabs($slug);

        // Printed here because nothing else will. WordPress only auto-prints
        // these on a screen whose parent file is options-general.php, and this
        // page moved under its own top-level menu. Without this call an owner
        // gets no "Settings saved" confirmation, and — worse — never sees the
        // warning that a wholesale question they typed was dropped on save.
        // @see \FluentCartBulkOrder\Wholesale\ApplicationSettings
        settings_errors();

        // A tab that stores nothing is drawn bare — no form, no Save button.
        // Not cosmetic: such a tab carries its own action buttons, and those
        // are forms of their own posting to admin-post.php. A browser drops a
        // nested form, so wrapping them here would make every one of them
        // submit the settings instead. @see Tab::isForm()
        if ($tab instanceof Tab && !$tab->isForm()) {
            do_settings_sections(Tabs::sectionPage($slug));
            echo '</div>';

            return;
        }

        echo '<form action="options.php" method="post">';
        settings_fields(Tabs::group($slug));
        $this->renderSubmittedKeys($tab);
        do_settings_sections(Tabs::sectionPage($slug));
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Load the tab registry.
     *
     * This file is required on EVERY page load — AccessPolicy reads its page
     * slug — while the registry and the five tab classes behind it are only
     * ever needed on `admin_init` and on this one screen. Both callers below
     * are admin-only, and require_once is idempotent.
     *
     * @return void
     */
    private function loadTabs()
    {
        require_once __DIR__ . '/Admin/Settings/Tabs.php';
    }

    /**
     * The raw `?tab=` value, if the request carried a usable one.
     *
     * Tabs::current() is what decides whether it names a real tab; this only
     * gets it out of the URL safely. A non-string (`?tab[]=x`) is dropped here
     * rather than cast, because sanitize_key() on an array is a fatal.
     *
     * @return string
     */
    private function requestedTab()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing which tab to DRAW. This is a GET on a read-only render; the save path is options.php, which checks its own nonce.
        if (!isset($_GET[Tabs::QUERY_ARG]) || !is_string($_GET[Tabs::QUERY_ARG])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
        return sanitize_key(wp_unslash($_GET[Tabs::QUERY_ARG]));
    }

    /**
     * The tab strip, in core's own markup.
     *
     * `nav-tab-wrapper` / `nav-tab-active` so it inherits the admin colour
     * scheme and looks like every other WordPress settings page. Plain links in
     * an `<h2>`, which is what core uses, and what makes them reachable and
     * operable from the keyboard with no JavaScript at all.
     *
     * `aria-current="page"` is added on top of core's markup: `nav-tab-active`
     * is a colour and a border, so on its own it tells a screen reader nothing
     * about which tab is open.
     *
     * @param string $current The visible tab's slug.
     * @return void
     */
    private function renderTabs($current)
    {
        echo '<h2 class="nav-tab-wrapper wp-clearfix">';

        foreach (Tabs::all() as $slug => $tab) {
            if ($slug === $current) {
                printf(
                    '<a href="%1$s" class="nav-tab nav-tab-active" aria-current="page">%2$s</a>',
                    esc_url(Tabs::url($slug)),
                    esc_html($tab->label())
                );

                continue;
            }

            printf(
                '<a href="%1$s" class="nav-tab">%2$s</a>',
                esc_url(Tabs::url($slug)),
                esc_html($tab->label())
            );
        }

        echo '</h2>';
    }

    /**
     * Declare, in the form itself, which shared keys this tab is submitting.
     *
     * This is the whole defence against saving one tab wiping another's
     * settings, and it is three lines because the thinking is in the sanitizer.
     * @see \FluentCartBulkOrder\StoreDefaults::SUBMITTED_KEYS
     *
     * @param Tab|null $tab
     * @return void
     */
    private function renderSubmittedKeys($tab)
    {
        if (!$tab instanceof Tab) {
            return;
        }

        $name = StoreDefaults::OPTION . '[' . StoreDefaults::SUBMITTED_KEYS . '][]';

        foreach ($tab->defaultsKeys() as $key) {
            printf(
                '<input type="hidden" name="%1$s" value="%2$s" />',
                esc_attr($name),
                esc_attr($key)
            );
        }
    }
}
