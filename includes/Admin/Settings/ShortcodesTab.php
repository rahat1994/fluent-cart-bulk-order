<?php

namespace FluentCartBulkOrder\Admin\Settings;

use FluentCartBulkOrder\Admin\ShortcodePages;
use FluentCartBulkOrder\Shortcodes\ShortcodeCatalog;

defined('ABSPATH') || exit;

/**
 * Shortcodes — every tag this plugin registers, what it does, and a page to put
 * it on.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS SCREEN EXISTS
 * ---------------------------------------------------------------------------
 *
 * The plugin registers four shortcodes and, before this tab, wp-admin named
 * two of them — both in passing, inside a section intro about something else.
 * The two an owner most needs, `[fluent_cart_bulk_order]` and
 * `[fluent_cart_saved_orders]`, appeared nowhere at all. The only complete list
 * was in readme.txt, which is the one file a store owner never opens.
 *
 * ---------------------------------------------------------------------------
 * THE FIRST TAB THAT IS NOT A FORM
 * ---------------------------------------------------------------------------
 *
 * isForm() returns false, and that is load-bearing rather than cosmetic. The
 * settings page normally wraps a tab in one `<form action="options.php">`, and
 * the Create page buttons here are themselves forms posting somewhere else —
 * admin-post.php. HTML has no nested forms: a browser silently drops the inner
 * one, so every Create page button would post the SETTINGS form instead, and
 * the owner would get "Settings saved" and no page.
 *
 * @see \FluentCartBulkOrder\Admin\Settings\Tab::isForm()
 * @see \FluentCartBulkOrder\Admin\ShortcodePages for the create action and the
 *      cached "is it already on a page" lookup.
 * @see \FluentCartBulkOrder\Shortcodes\ShortcodeCatalog for the list itself,
 *      which is derived from the shortcode registry and never typed out here.
 */
class ShortcodesTab extends Tab
{
    /**
     * @inheritDoc
     */
    public function slug()
    {
        return Tabs::SHORTCODES;
    }

    /**
     * @inheritDoc
     */
    public function label()
    {
        return __('Shortcodes', 'fluent-cart-bulk-order');
    }

    /**
     * This tab stores nothing, so it gets no form and no Save button.
     *
     * @inheritDoc
     */
    public function isForm()
    {
        return false;
    }

    /**
     * One section holding the whole reference.
     *
     * A section per shortcode was the obvious alternative and is worse: the
     * list has to be driven by the registry, and registering sections in a loop
     * would put a `do_settings_sections()` heading between every card while
     * still leaving one callback per card to write.
     *
     * @inheritDoc
     */
    public function registerSections($page)
    {
        add_settings_section(
            'fcbo_shortcodes_section',
            __('Shortcodes', 'fluent-cart-bulk-order'),
            [$this, 'renderReference'],
            $page
        );
    }

    /**
     * The whole reference: an intro, then one card per registered tag.
     *
     * @return void
     */
    public function renderReference()
    {
        require_once dirname(__DIR__) . '/ShortcodePages.php';

        ShortcodePages::renderNotice();

        echo '<p>' . esc_html__(
            'Paste one of these into any page, post or widget to place a surface. Every attribute is optional: leave it out and the shortcode follows the store-wide settings on the other tabs.',
            'fluent-cart-bulk-order'
        ) . '</p>';

        // One cached lookup for all four cards. @see ShortcodePages::usage().
        $usage = ShortcodePages::usage();

        foreach (ShortcodeCatalog::tags() as $tag) {
            $this->renderCard($tag, isset($usage[$tag]) ? (int) $usage[$tag] : 0);
        }
    }

    /**
     * One shortcode: what it is, what it takes, where it already is, and a
     * button that puts it on a page.
     *
     * @param string $tag    A key of ShortcodeHandler::SHORTCODES.
     * @param int    $pageId Id of a page already holding it, or 0.
     * @return void
     */
    private function renderCard($tag, $pageId)
    {
        echo '<div class="fcbo-shortcode-card">';

        printf('<h3 class="fcbo-shortcode-title">%s</h3>', esc_html(ShortcodeCatalog::label($tag)));

        printf('<p>%s</p>', esc_html(ShortcodeCatalog::description($tag)));

        $this->renderSnippet($tag);
        $this->renderAttributes($tag);
        $this->renderEditors($tag);
        $this->renderUsage($tag, $pageId);

        echo '</div>';
    }

    /**
     * The tag itself, beside a button that copies it.
     *
     * The tag is printed in full whether or not the button works. That is the
     * fallback: a `<code>` an owner can select with a mouse needs no JavaScript,
     * no clipboard permission and no secure context, and it is what remains on
     * a browser where the copy button cannot function at all.
     *
     * @param string $tag
     * @return void
     */
    private function renderSnippet($tag)
    {
        $snippet = ShortcodeCatalog::snippet($tag);

        echo '<p class="fcbo-shortcode-snippet">';

        printf('<code>%s</code> ', esc_html($snippet));

        printf(
            '<button type="button" class="button fcbo-copy-button" data-fcbo-copy="%1$s" data-fcbo-copied="%2$s" data-fcbo-manual="%3$s">%4$s</button>',
            esc_attr($snippet),
            esc_attr__('Copied', 'fluent-cart-bulk-order'),
            // Both shortcuts named: this is the last-resort branch, shown when
            // neither the clipboard API nor execCommand worked, and telling a Mac
            // owner to press a key that does nothing turns a fallback into a
            // dead end. Platform-sniffing to pick one would be a user-agent
            // guess in aid of four characters.
            esc_attr__('Press Ctrl+C or Command+C to copy', 'fluent-cart-bulk-order'),
            esc_html__('Copy shortcode', 'fluent-cart-bulk-order')
        );

        // aria-live so the "Copied" confirmation is announced. Without it the
        // only feedback the button gives is a word that appears silently.
        echo ' <span class="fcbo-copy-feedback" aria-live="polite"></span>';

        echo '</p>';
    }

    /**
     * The attributes this tag accepts, or a line saying it accepts none.
     *
     * @param string $tag
     * @return void
     */
    private function renderAttributes($tag)
    {
        $attributes = ShortcodeCatalog::attributes($tag);

        if (!$attributes) {
            echo '<p class="description">' . esc_html__(
                'This shortcode takes no attributes.',
                'fluent-cart-bulk-order'
            ) . '</p>';

            return;
        }

        echo '<table class="widefat striped fcbo-shortcode-attributes"><thead><tr>';
        printf('<th scope="col">%s</th>', esc_html__('Attribute', 'fluent-cart-bulk-order'));
        printf('<th scope="col">%s</th>', esc_html__('Value', 'fluent-cart-bulk-order'));
        printf('<th scope="col">%s</th>', esc_html__('What it does', 'fluent-cart-bulk-order'));
        echo '</tr></thead><tbody>';

        foreach ($attributes as $name => $attribute) {
            printf(
                '<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td></tr>',
                esc_html($name),
                esc_html($attribute['type_label']),
                esc_html($attribute['help'])
            );
        }

        echo '</tbody></table>';
    }

    /**
     * Point owners who never touch a shortcode at the block and the widget.
     *
     * Only for the tags that have them. Saying nothing for the other two is
     * deliberate — "no block available" is a sentence that invites an owner to
     * go looking for one.
     *
     * @param string $tag
     * @return void
     */
    private function renderEditors($tag)
    {
        if (!ShortcodeCatalog::hasEditorWrappers($tag)) {
            return;
        }

        printf(
            '<p class="description">%s</p>',
            esc_html(
                sprintf(
                    /* translators: %s: the name of the block and Elementor widget, e.g. "Product Table". */
                    __('You do not need the shortcode for this one: it is also a "%s" block in the block editor and an Elementor widget of the same name, with these attributes as visual controls. Both render through the shortcode, so nothing behaves differently.', 'fluent-cart-bulk-order'),
                    ShortcodeCatalog::label($tag)
                )
            )
        );
    }

    /**
     * Whether a page already carries this tag, and the button that acts on it.
     *
     * Two mutually exclusive states on purpose. Offering "Create page" beside
     * "this is already on a page" is how a store ends up with two bulk order
     * pages and no idea which one is linked from the menu. ShortcodePages
     * refuses the second one on the server too, because a stale tab still has
     * the old button in it.
     *
     * @param string $tag
     * @param int    $pageId Id of a page already holding it, or 0.
     * @return void
     */
    private function renderUsage($tag, $pageId)
    {
        if (!$pageId) {
            printf('<p>%s</p>', esc_html__('No page on this site uses this shortcode yet.', 'fluent-cart-bulk-order'));

            $this->renderCreateForm($tag);

            return;
        }

        $edit = get_edit_post_link($pageId);

        printf(
            '<p><strong>%1$s</strong> %2$s</p>',
            esc_html__('Already set up.', 'fluent-cart-bulk-order'),
            esc_html(
                sprintf(
                    /* translators: %s: the title of an existing page. */
                    __('The page "%s" already uses this shortcode.', 'fluent-cart-bulk-order'),
                    get_the_title($pageId)
                )
            )
        );

        echo '<p>';

        if ($edit) {
            printf(
                '<a class="button" href="%1$s">%2$s</a> ',
                esc_url($edit),
                esc_html__('Open that page in the editor', 'fluent-cart-bulk-order')
            );
        }

        $view = get_permalink($pageId);

        if ($view) {
            printf(
                '<a class="button-link" href="%1$s">%2$s</a>',
                esc_url($view),
                esc_html__('View it on the site', 'fluent-cart-bulk-order')
            );
        }

        echo '</p>';
    }

    /**
     * The Create page button: a real form, a real POST, its own nonce.
     *
     * Not a link. A link would make this a GET, and a GET that inserts a post
     * is a page created by every prefetcher and link scanner that touches the
     * admin. @see \FluentCartBulkOrder\Admin\ShortcodePages for the four guards
     * on the other end.
     *
     * @param string $tag
     * @return void
     */
    private function renderCreateForm($tag)
    {
        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));

        printf(
            '<input type="hidden" name="action" value="%s" />',
            esc_attr(ShortcodePages::ACTION)
        );

        printf(
            '<input type="hidden" name="%1$s" value="%2$s" />',
            esc_attr(ShortcodePages::FIELD_TAG),
            esc_attr($tag)
        );

        wp_nonce_field(ShortcodePages::nonceAction($tag));

        printf(
            '<button type="submit" class="button button-secondary">%s</button>',
            esc_html__('Create a draft page with this shortcode', 'fluent-cart-bulk-order')
        );

        echo '</form>';
    }
}
