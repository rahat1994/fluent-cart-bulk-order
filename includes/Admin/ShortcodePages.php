<?php

namespace FluentCartBulkOrder\Admin;

use FluentCartBulkOrder\Admin\Settings\Tabs;
use FluentCartBulkOrder\Shortcodes\ShortcodeCatalog;
use FluentCartBulkOrder\Shortcodes\ShortcodeHandler;

defined('ABSPATH') || exit;

/*
 * @see \FluentCartBulkOrder\Shortcodes\ShortcodeCatalog for why these are
 * required at file scope: no autoloader, and the constants below are read
 * directly off both classes.
 */
require_once dirname(__DIR__) . '/Shortcodes/ShortcodeCatalog.php';

/**
 * The two database questions the Shortcodes tab asks: "is this tag already on a
 * page?" and "make me a page with it on".
 *
 * ---------------------------------------------------------------------------
 * THIS IS THE FIRST THING IN THE ADMIN THAT CREATES CONTENT
 * ---------------------------------------------------------------------------
 *
 * Every other admin action in this plugin edits a record the owner already has.
 * This one calls wp_insert_post(). Four separate things guard it, and none of
 * them is redundant:
 *
 *   1. POST ONLY. handleCreate() refuses anything else with a 405 before it
 *      looks at a single field. A browser prefetcher, a link scanner, a
 *      security crawler or a `<img src>` in an email all issue GETs, and a
 *      create-on-GET would let any of them fill a site with draft pages.
 *   2. A NONCE, SCOPED TO THE TAG. check_admin_referer() on
 *      nonceAction($tag) — not one nonce for "create a page". A form for the
 *      product table cannot be replayed with the tag swapped, because the tag
 *      is part of what was signed.
 *   3. A CAPABILITY, CHECKED SEPARATELY. A valid nonce proves the request came
 *      from this user's browser. It says nothing about what that user may do.
 *      @see capability() for which one and why.
 *   4. POST-REDIRECT-GET. handleCreate() never returns; it ends in
 *      wp_safe_redirect() + exit. Refreshing the page an owner lands on
 *      re-issues a GET of the editor, not a second insert.
 *
 * The tag itself is checked against ShortcodeHandler::SHORTCODES before it is
 * used for anything, so the only content this action can ever write is one of
 * four tags this plugin registers.
 *
 * ---------------------------------------------------------------------------
 * WHY THE USAGE LOOKUP IS CACHED, AND WHAT THAT COSTS
 * ---------------------------------------------------------------------------
 *
 * "Does a page already use this tag" has no index behind it. The only way to
 * answer it is a LIKE over post_content, which is a full scan of the pages
 * table — and the tab would need FOUR of them, one per tag, on every single
 * render.
 *
 * Two things make that acceptable. First, scan() runs ONE query for all four
 * tags: it searches for the prefix every tag of ours shares and sorts the
 * handful of matches out in PHP with has_shortcode(). Second, the whole map is
 * cached in a transient for USAGE_TTL, the same pattern and the same five
 * minutes as Menu::cachedCount().
 *
 * The trade-off is deliberate and stated here so nobody "fixes" it: this
 * answer can be up to five minutes stale. An owner who publishes a page in
 * another browser tab may see "not used on any page yet" for a few minutes
 * afterwards, and the worst that costs is one extra draft page they can delete.
 * The alternative — an uncached scan — is a slow settings screen on every load
 * for every owner, forever. The common editing paths flush it anyway
 * (@see register()), so the TTL is only a backstop for the ones that do not,
 * such as a page imported by another plugin.
 */
class ShortcodePages
{
    /**
     * The `action` value the create-page form posts.
     */
    const ACTION = 'fcbo_create_shortcode_page';

    /**
     * Field name carrying the tag to create a page for.
     */
    const FIELD_TAG = 'fcbo_tag';

    /**
     * Query argument naming the outcome, read back by renderNotice().
     */
    const ARG_RESULT = 'fcbo_pages';

    /**
     * Query argument carrying the page the outcome is about.
     */
    const ARG_PAGE = 'fcbo_page';

    /**
     * Outcome: a page already held this tag, so nothing was created.
     */
    const RESULT_EXISTS = 'exists';

    /**
     * Outcome: wp_insert_post() refused.
     */
    const RESULT_ERROR = 'error';

    /**
     * Fallback capability. @see capability(), which prefers the page post
     * type's own registered capability so a site that has remapped it is
     * honoured.
     */
    const CAPABILITY = 'publish_pages';

    /**
     * Transient holding tag => page id for every tag in use.
     */
    const USAGE_TRANSIENT = 'fcbo_shortcode_pages';

    /**
     * How long the usage map is trusted, in seconds.
     *
     * Five minutes, matching Menu::COUNT_TTL. @see the class docblock for the
     * trade-off this number represents.
     */
    const USAGE_TTL = 300;

    /**
     * How many matching pages one scan will look inside.
     *
     * A bound, not a page size. The search below already narrows to pages
     * containing our tag prefix, so on a real site this is single digits; the
     * cap exists so a site that has somehow put the prefix on a thousand pages
     * cannot turn one settings render into a thousand get_post() calls.
     */
    const SCAN_LIMIT = 100;

    /**
     * The prefix every tag this plugin registers begins with.
     *
     * One LIKE for all four tags. Not derived from SHORTCODES on purpose: this
     * is a search NARROWING, and has_shortcode() below is what actually decides
     * which tag a page holds. A future tag that does not start with this prefix
     * would be missed here, which is why ShortcodeCatalogTest asserts every
     * registered tag begins with it.
     */
    const TAG_PREFIX = '[fluent_cart_';

    /**
     * Wire up the create action.
     *
     * Called only from the admin branch of the bootstrap, which is enough for
     * this half: admin-post.php is an admin request.
     *
     * The cache invalidation is NOT here. It hangs off `save_post_page` and
     * `deleted_post`, which both fire on requests that are not admin requests
     * — a block-editor save goes through the REST API — so it is registered
     * from the plugin bootstrap through a delegate that can load this file
     * on demand. @see fcbo_flush_shortcode_pages()
     *
     * @return void
     */
    public static function register()
    {
        // NO `admin_post_nopriv_` sibling, deliberately. A logged-out POST to
        // this action falls through to admin-post.php's own refusal and never
        // reaches code of ours.
        add_action('admin_post_' . self::ACTION, [self::class, 'handleCreate']);
    }

    /**
     * The capability required to create a page from this screen.
     *
     * `publish_pages`, resolved through the `page` post type object so a site
     * that has remapped page capabilities is honoured rather than second
     * guessed.
     *
     * WHY THIS ONE. The check has to match the ability the action exercises,
     * and the action creates a page. `edit_pages` would be the strictly minimal
     * answer for inserting a DRAFT, but the button exists to set up a page the
     * owner will publish, and handing somebody a draft they can never finish is
     * a worse failure than refusing the click. `publish_pages` is therefore the
     * one that matches what the button promises.
     *
     * Note what this check is NOT: it is not the gate on the screen. The
     * Shortcodes tab lives on the settings page, which requires
     * `manage_options` to render at all. This check exists for the request
     * itself, because an action is reachable by anyone who can construct the
     * POST — and it is scoped to the ability rather than to `manage_options` on
     * purpose. Anyone holding `publish_pages` can already create a page from
     * Pages → Add New, so this action hands out no power they did not have.
     *
     * @return string
     */
    public static function capability()
    {
        $type = get_post_type_object('page');

        if ($type && isset($type->cap->publish_posts) && is_string($type->cap->publish_posts)) {
            return $type->cap->publish_posts;
        }

        return self::CAPABILITY;
    }

    /**
     * The nonce action for ONE tag.
     *
     * Scoped so a signed form for one shortcode cannot be replayed with the tag
     * field edited to another.
     *
     * @param string $tag A key of ShortcodeHandler::SHORTCODES.
     * @return string
     */
    public static function nonceAction($tag)
    {
        return self::ACTION . '_' . (string) $tag;
    }

    /**
     * Create a draft page holding one shortcode, then open it in the editor.
     *
     * Never returns: every path ends in wp_safe_redirect() + exit, or wp_die().
     *
     * @return void
     */
    public static function handleCreate()
    {
        // Guard 1: POST only. Before anything else is read, because the whole
        // point is that a GET must not get as far as looking at fields.
        if (!isset($_SERVER['REQUEST_METHOD'])
            || strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) !== 'POST') {
            wp_die(
                esc_html__('This action must be submitted from the Shortcodes settings tab.', 'fluent-cart-bulk-order'),
                '',
                ['response' => 405]
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified on the next line, and it cannot be verified before the tag is read because it is scoped to the tag.
        $tag = isset($_POST[self::FIELD_TAG]) ? sanitize_key(wp_unslash($_POST[self::FIELD_TAG])) : '';

        // The tag decides which nonce is checked, so an unknown one is refused
        // here rather than reaching nonceAction(). Otherwise a junk tag would
        // simply produce a nonce mismatch, which is the right outcome by
        // accident and a confusing message on purpose.
        if (!isset(ShortcodeHandler::SHORTCODES[$tag])) {
            wp_die(
                esc_html__('That is not a shortcode this plugin registers.', 'fluent-cart-bulk-order'),
                '',
                ['response' => 400]
            );
        }

        // Guard 2: the nonce, scoped to the tag. Dies on failure, which is what
        // check_admin_referer() is for.
        check_admin_referer(self::nonceAction($tag));

        // Guard 3: the capability, checked on its own. @see capability().
        if (!current_user_can(self::capability())) {
            wp_die(
                esc_html__('You do not have permission to create pages on this site.', 'fluent-cart-bulk-order'),
                '',
                ['response' => 403]
            );
        }

        // Re-asked here rather than trusted from the form. The button is hidden
        // once a page holds the tag, but a stale tab still has the old form in
        // it, and this is the check that stops a second page being made.
        $existing = self::pageUsing($tag);

        if ($existing) {
            self::finish(self::RESULT_EXISTS, $existing);
        }

        $pageId = wp_insert_post(
            [
                'post_title'   => self::pageTitle($tag),
                'post_content' => self::pageContent($tag),
                'post_status'  => 'draft',
                'post_type'    => 'page',
                'post_author'  => get_current_user_id(),
            ],
            true
        );

        if (is_wp_error($pageId) || !$pageId) {
            self::finish(self::RESULT_ERROR, 0);
        }

        // The map this request just invalidated. Dropped rather than patched:
        // the next render rebuilds it, and a patched map that disagreed with
        // the database would be worse than a rebuild that costs one query.
        self::flush();

        // Guard 4: post-redirect-get. Straight into the editor, which is what
        // the owner asked for — the page is a draft with one shortcode in it,
        // and the next thing they want is to give it a title and publish.
        $edit = get_edit_post_link($pageId, 'raw');

        wp_safe_redirect($edit ? $edit : self::tabUrl());

        exit;
    }

    /**
     * Send the owner back to the tab with an outcome to report.
     *
     * @param string $code One of the RESULT_* constants.
     * @param int    $pageId Page the outcome is about; 0 when there is none.
     * @return void Never returns.
     */
    private static function finish($code, $pageId)
    {
        $args = [self::ARG_RESULT => $code];

        if ($pageId) {
            $args[self::ARG_PAGE] = (int) $pageId;
        }

        wp_safe_redirect(add_query_arg($args, self::tabUrl()));

        exit;
    }

    /**
     * The Shortcodes tab's own URL.
     *
     * @return string
     */
    public static function tabUrl()
    {
        require_once dirname(__DIR__) . '/Admin/Settings/Tabs.php';

        return Tabs::url(Tabs::SHORTCODES);
    }

    /**
     * The title a created page is given.
     *
     * The shortcode's human label, so a store that makes all four ends up with
     * four pages an owner can tell apart in the Pages list. They are drafts, so
     * the title is a starting point rather than something a shopper sees.
     *
     * @param string $tag A validated key of ShortcodeHandler::SHORTCODES.
     * @return string
     */
    public static function pageTitle($tag)
    {
        return ShortcodeCatalog::label($tag);
    }

    /**
     * The content a created page is given.
     *
     * Wrapped in a shortcode BLOCK when the site edits pages with the block
     * editor, and left as bare tag text when it does not. The wrapper matters:
     * a bare shortcode dropped into a block-editor page becomes a "Classic"
     * block, which is the one block an owner cannot configure, and the first
     * thing they would have to do is convert it.
     *
     * @param string $tag A validated key of ShortcodeHandler::SHORTCODES.
     * @return string
     */
    public static function pageContent($tag)
    {
        $snippet = ShortcodeCatalog::snippet($tag);

        // Defined in wp-admin/includes/post.php, which admin-post.php has
        // loaded. Checked anyway so this method stays safe to call from
        // anywhere.
        if (function_exists('use_block_editor_for_post_type')
            && !use_block_editor_for_post_type('page')) {
            return $snippet;
        }

        return "<!-- wp:shortcode -->\n" . $snippet . "\n<!-- /wp:shortcode -->";
    }

    /**
     * The id of a page already holding this tag, if there is one.
     *
     * @param string $tag A key of ShortcodeHandler::SHORTCODES.
     * @return int Page id, or 0.
     */
    public static function pageUsing($tag)
    {
        $usage = self::usage();

        return isset($usage[$tag]) ? (int) $usage[$tag] : 0;
    }

    /**
     * Tag => id of a page holding it, for every tag that is on a page.
     *
     * @return array<string, int>
     */
    public static function usage()
    {
        $cached = get_transient(self::USAGE_TRANSIENT);

        // An empty array is a legitimate answer — no page uses any tag — and
        // must not re-run the scan. get_transient() returns false, not null,
        // when there is nothing stored.
        if (is_array($cached)) {
            return $cached;
        }

        $usage = self::scan();

        set_transient(self::USAGE_TRANSIENT, $usage, self::USAGE_TTL);

        return $usage;
    }

    /**
     * Find, in one query, which pages hold which of our tags.
     *
     * WP_Query with `s` rather than a hand-written LIKE: it produces the same
     * scan, and it produces it through the API that already handles escaping,
     * statuses and the `posts_search` filters a site may be running. `sentence`
     * forces the whole prefix to be matched as one phrase instead of being
     * split on spaces.
     *
     * The search is a NARROWING only. has_shortcode() is what decides a page
     * really holds a tag, because a LIKE would also match the tag's name being
     * discussed in prose, or a tag of ours that is escaped as `[[…]]`.
     *
     * @return array<string, int> Tag => the id of the first page holding it.
     */
    private static function scan()
    {
        $query = new \WP_Query(
            [
                'post_type'      => 'page',
                // Every status a page can be worked on in. `trash` is left out:
                // a tag on a trashed page is not set up, and offering to open
                // it would send an owner to a page they have already thrown
                // away.
                'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
                's'              => self::TAG_PREFIX,
                'sentence'       => true,
                'posts_per_page' => self::SCAN_LIMIT,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                // Nothing here reads a term or a meta value, and no_found_rows
                // drops the SQL_CALC_FOUND_ROWS pass over the same scan.
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );

        $tags  = ShortcodeCatalog::tags();
        $usage = [];

        foreach ($query->posts as $postId) {
            $post = get_post($postId);

            if (!$post) {
                continue;
            }

            foreach ($tags as $tag) {
                // First page wins. Ordered by id, so "first" is the oldest —
                // stable across renders, which a relevance-ordered result would
                // not be.
                if (isset($usage[$tag])) {
                    continue;
                }

                if (has_shortcode($post->post_content, $tag)) {
                    $usage[$tag] = (int) $post->ID;
                }
            }
        }

        return $usage;
    }

    /**
     * Drop the cached usage map.
     *
     * @return void
     */
    public static function flush()
    {
        delete_transient(self::USAGE_TRANSIENT);
    }

    /**
     * Print the notice for whatever the last create attempt did.
     *
     * Rendered by the tab rather than on `admin_notices`, so it appears inside
     * the settings page's own layout next to the buttons it is about.
     *
     * @return void
     */
    public static function renderNotice()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the outcome of a redirect this class issued, to print a sentence. Nothing is changed, and the ids are re-resolved below rather than trusted.
        $code = isset($_GET[self::ARG_RESULT]) ? sanitize_key(wp_unslash($_GET[self::ARG_RESULT])) : '';

        if ($code === self::RESULT_ERROR) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__(
                    'That page could not be created. Check that your user can publish pages, then try again.',
                    'fluent-cart-bulk-order'
                )
            );

            return;
        }

        if ($code !== self::RESULT_EXISTS) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
        $pageId = isset($_GET[self::ARG_PAGE]) ? absint($_GET[self::ARG_PAGE]) : 0;
        $edit   = $pageId ? get_edit_post_link($pageId) : '';

        if (!$edit) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('Nothing was created — a page on this site already uses that shortcode.', 'fluent-cart-bulk-order')
            );

            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
            esc_html__('Nothing was created — a page on this site already uses that shortcode.', 'fluent-cart-bulk-order'),
            esc_url($edit),
            esc_html(
                sprintf(
                    /* translators: %s: the title of an existing page. */
                    __('Edit "%s" instead.', 'fluent-cart-bulk-order'),
                    get_the_title($pageId)
                )
            )
        );
    }
}
