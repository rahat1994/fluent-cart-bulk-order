<?php

namespace FluentCartBulkOrder\Admin\Settings;

use FluentCartBulkOrder\AccessPolicy;
use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * One tab of the settings page: the sections it draws and the values it writes.
 *
 * ---------------------------------------------------------------------------
 * WHY A CLASS PER TAB AND NOT A METHOD PER TAB
 * ---------------------------------------------------------------------------
 *
 * A tab is not just a heading. It is a set of sections, the option group those
 * sections post to, and — the part that bites — the exact list of keys it is
 * allowed to overwrite inside the one shared store-defaults option. Those three
 * facts have to agree with each other, and the only way to keep them agreeing
 * is to keep them in one file that a reviewer can read top to bottom.
 *
 * Splitting by tab rather than by "sections" / "renderers" / "sanitizers" is
 * deliberate for the same reason: a horizontal split would put the three facts
 * about Quotes in three different files, which is how they drift.
 *
 * ---------------------------------------------------------------------------
 * THE TWO KINDS OF VALUE A TAB CAN OWN
 * ---------------------------------------------------------------------------
 *
 *   registerOptions()  top-level options of its own, registered into the tab's
 *                      own group so that saving another tab cannot touch them.
 *   defaultsKeys()     keys inside the single `fcbo_store_defaults` array,
 *                      declared on the form so the shared sanitizer knows which
 *                      ones this submission is entitled to rewrite.
 *                      @see \FluentCartBulkOrder\StoreDefaults::SUBMITTED_KEYS
 *
 * A tab may own either, both, or (for a future read-only tab) neither.
 */
abstract class Tab
{
    /**
     * The tab's slug — what appears in `?tab=` and in its option group name.
     *
     * @return string
     */
    abstract public function slug();

    /**
     * The label drawn on the tab itself. Translated.
     *
     * @return string
     */
    abstract public function label();

    /**
     * Register this tab's sections and fields.
     *
     * @param string $page The Settings API page bucket for this tab.
     *                     @see Tabs::sectionPage()
     * @return void
     */
    abstract public function registerSections($page);

    /**
     * Register any top-level options this tab owns.
     *
     * @param string $group This tab's option group. @see Tabs::group()
     * @return void
     */
    public function registerOptions($group)
    {
        unset($group);
    }

    /**
     * Keys inside the shared store-defaults option that this tab's fields write.
     *
     * This list IS the permission to overwrite. A key missing from here is
     * rendered by a field nobody can save; a key listed here that the tab does
     * not actually render is reset to its fallback every time the tab is saved.
     * SettingsTabsTest pins the union of all five lists against
     * StoreDefaults::FALLBACKS so neither mistake can be made quietly.
     *
     * @return string[]
     */
    public function defaultsKeys()
    {
        return [];
    }

    /**
     * Name attribute for one key inside the store-defaults option array.
     *
     * Every field on that side of the page posts as `fcbo_store_defaults[key]`,
     * which is what lets one sanitizer see the whole submission at once.
     *
     * @param string $key Key within the option array.
     * @return string
     */
    protected function defaultsName($key)
    {
        return StoreDefaults::OPTION . '[' . $key . ']';
    }

    /**
     * Editable role slugs, with the admin include guaranteed.
     *
     * @return array<string, array> Role slug => role details.
     */
    protected function editableRoles()
    {
        return AccessPolicy::editableRoles();
    }

    /**
     * Render a plain role checklist under an arbitrary name attribute.
     *
     * Shared because three tabs draw the same control over three different
     * stores — two top-level options and one key inside the defaults array — and
     * the hidden-input rule below is the part that must not be forgotten in any
     * of them.
     *
     * @param string   $name        Full name attribute, without the `[]`.
     * @param string[] $selected    Currently chosen role slugs.
     * @param string   $description Help text shown beneath the list.
     * @return void
     */
    protected function renderRoleChecklist($name, array $selected, $description)
    {
        // Hidden field guarantees the list is submitted even when nothing is
        // checked, so the sanitizer runs and stores an empty array. Without it
        // an all-unchecked list is indistinguishable from a list that was never
        // on screen, and the owner can never clear one.
        echo '<input type="hidden" name="' . esc_attr($name) . '[]" value="" />';

        echo '<fieldset>';
        foreach ($this->editableRoles() as $slug => $details) {
            $label = isset($details['name']) ? $details['name'] : $slug;
            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr($name),
                esc_attr($slug),
                checked(in_array($slug, $selected, true), true, false),
                esc_html($label)
            );
        }
        echo '</fieldset>';

        echo '<p class="description">' . esc_html($description) . '</p>';
    }

    /**
     * Turn a list of role slugs into the role names an owner would recognise.
     *
     * @param string[] $slugs
     * @return string[]
     */
    protected function roleNames(array $slugs)
    {
        $editable = $this->editableRoles();
        $names    = [];

        foreach ($slugs as $slug) {
            $names[] = isset($editable[$slug]['name']) ? $editable[$slug]['name'] : $slug;
        }

        return $names;
    }
}
