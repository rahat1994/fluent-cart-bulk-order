<?php

namespace FluentCartBulkOrder\Shortcodes;

use FluentCartBulkOrder\AccessPolicy;

defined('ABSPATH') || exit;

/**
 * Shared shape for every FCBO shortcode.
 *
 * ---------------------------------------------------------------------------
 * THE ORDER OF OPERATIONS IS THE POINT
 * ---------------------------------------------------------------------------
 *
 * All three shortcodes did the same four things in the same order, and that
 * order is load-bearing:
 *
 *   1. shortcode_atts() — with the real tag, so the
 *      `shortcode_atts_{$tag}` filter keeps firing under its existing name.
 *   2. Logged-in check.
 *   3. Gate 1 role check (AccessPolicy), widened by the `roles` attribute.
 *   4. Only THEN enqueue assets and build markup.
 *
 * Steps 2 and 3 must come before step 4. A refused render must not leave a
 * stylesheet and a localized config object in the page for a user who is not
 * allowed to use the surface. render() below fixes that order so a new
 * shortcode cannot get it wrong by accident.
 *
 * Step 3 is skippable, step 2 is not. requiresSurfaceAccess() exists for the
 * wholesale application form, whose whole audience is the users Gate 1 keeps
 * out; nothing may skip the logged-in check, because every surface here is
 * about a specific user.
 *
 * ---------------------------------------------------------------------------
 * WHAT `roles` MEANS
 * ---------------------------------------------------------------------------
 *
 * The `roles` attribute EXTENDS the baseline administrator + wholesale-customer
 * set for one placement; it never replaces it. It widens the UI gate only — the
 * REST routes behind these surfaces still enforce the global
 * AccessPolicy::allowedRoles(). A page-level `roles` attribute therefore lets a
 * user SEE a table whose data endpoint may still refuse them.
 *
 * @see \FluentCartBulkOrder\Shortcodes\ShortcodeHandler The tag -> class map.
 * @see \FluentCartBulkOrder\AccessPolicy Gate 1, and why `roles` only widens.
 */
abstract class AbstractShortcode
{
    /**
     * The tag this instance was registered under.
     *
     * Injected rather than declared as a class constant so ShortcodeHandler's map
     * stays the single source of truth — a tag cannot drift between the map and
     * the class that serves it.
     *
     * @var string
     */
    protected $tag;

    /**
     * @param string $tag Shortcode tag, from ShortcodeHandler::SHORTCODES.
     */
    public function __construct($tag)
    {
        $this->tag = (string) $tag;
    }

    /**
     * Attribute defaults, exactly as passed to shortcode_atts().
     *
     * @return array<string, mixed>
     */
    abstract protected function defaults();

    /**
     * Message shown to a logged-out visitor.
     *
     * Return the translated string — the base class escapes it and wraps it in a
     * paragraph. Keep the literal inside __() so it stays scannable by the
     * translation tooling.
     *
     * @return string
     */
    abstract protected function loginNotice();

    /**
     * Message shown to a logged-in user whose roles fail Gate 1.
     *
     * @return string
     */
    abstract protected function accessDeniedNotice();

    /**
     * Enqueue this surface's assets and return its markup.
     *
     * Only called once the gates have passed.
     *
     * @param array<string, mixed> $atts Resolved attributes.
     * @return string
     */
    abstract protected function output(array $atts);

    /**
     * Entry point — the callback behind add_shortcode().
     *
     * @param array<string, mixed>|string $atts Raw attributes from WordPress.
     * @return string Markup, or a notice when a gate refuses.
     */
    public function render($atts = [])
    {
        $atts = shortcode_atts($this->defaults(), $atts, $this->tag);

        if (!is_user_logged_in()) {
            return $this->loginNoticeHtml();
        }

        if ($this->requiresSurfaceAccess() && !AccessPolicy::currentUserCanAccess($this->extraRoles($atts))) {
            return $this->notice($this->accessDeniedNotice());
        }

        return $this->output($atts);
    }

    /**
     * Whether this surface is behind Gate 1.
     *
     * True for every ordering surface, and it must stay that way — those show
     * wholesale prices, so a shortcode that opted out would leak trade pricing
     * onto a public page.
     *
     * The ONE exception is the wholesale application form, whose audience is by
     * definition the users Gate 1 excludes: someone asking to BECOME a wholesale
     * customer does not hold the role yet. Gating it would mean only people who
     * already have wholesale access could apply for wholesale access.
     *
     * Overriding this is a deliberate act, not a convenience. A surface that
     * returns false here must have no wholesale data on it at all.
     *
     * @return bool
     * @see \FluentCartBulkOrder\Shortcodes\WholesaleApplication
     */
    protected function requiresSurfaceAccess()
    {
        return true;
    }

    /**
     * The full markup shown to a logged-out visitor.
     *
     * Defaults to loginNotice() wrapped in a paragraph, which is what every
     * ordering surface wants. Override when the notice needs more than a
     * sentence — the application form points at the login and registration
     * pages, because "log in to apply" without a link is a dead end for someone
     * who has never had an account on the store.
     *
     * @return string
     */
    protected function loginNoticeHtml()
    {
        return $this->notice($this->loginNotice());
    }

    /**
     * Role slugs from the `roles` attribute, if this shortcode has one.
     *
     * @param array<string, mixed> $atts
     * @return string[]
     */
    protected function extraRoles(array $atts)
    {
        return AccessPolicy::parseRolesAttr(isset($atts['roles']) ? $atts['roles'] : '');
    }

    /**
     * Wrap a gate message in the markup the shortcodes have always returned.
     *
     * @param string $message Translated, unescaped text.
     * @return string
     */
    protected function notice($message)
    {
        return '<p>' . esc_html($message) . '</p>';
    }

    /**
     * The `rest_url` + `nonce` pair every one of these surfaces localizes.
     *
     * Merge subclass keys on top:
     *   array_merge($this->restConfig(), ['currency_sign' => ...])
     *
     * @return array<string, string>
     */
    protected function restConfig()
    {
        return [
            'rest_url' => esc_url_raw(rest_url('fcbo/v1/')),
            'nonce'    => wp_create_nonce('wp_rest'),
        ];
    }

    /**
     * Store currency sign, defaulting to '$' when FluentCart is unreachable.
     *
     * Delegates to the request-cached fcbo_get_currency_sign() rather than
     * repeating the CurrencySettings lookup, which is what each shortcode used
     * to do inline.
     *
     * @return string
     */
    protected function currencySign()
    {
        return fcbo_get_currency_sign();
    }

    /**
     * The store's checkout page URL, or '' when FluentCart cannot supply one.
     *
     * Delegates to fcbo_checkout_page_url() for the same reason currencySign()
     * delegates: the single-product Bulk Pricing block also hands shoppers to
     * checkout and is not a shortcode, so the lookup could not stay behind a
     * protected method on this base class.
     *
     * @return string
     */
    protected function checkoutPageUrl()
    {
        return fcbo_checkout_page_url();
    }

    /**
     * Load FluentCart's cart bundle, which is what puts `window.fluentCartCart`
     * on the page. Every surface that adds items to the cart needs it.
     *
     * class_exists() guarded: these shortcodes only register when FluentCart is
     * active, but the AssetLoader class is internal to the host plugin and may
     * move between versions. A missing loader must degrade, not fatal.
     *
     * @return void
     */
    protected function loadFluentCartCartAssets()
    {
        if (class_exists(\FluentCart\App\Modules\Templating\AssetLoader::class)) {
            \FluentCart\App\Modules\Templating\AssetLoader::loadCartAssets();
        }
    }

    /**
     * Load FluentCart's single-product bundle — needed where variant selection
     * UI is rendered.
     *
     * @return void
     */
    protected function loadFluentCartSingleProductAssets()
    {
        if (class_exists(\FluentCart\App\Modules\Templating\AssetLoader::class)) {
            \FluentCart\App\Modules\Templating\AssetLoader::loadSingleProductAssets();
        }
    }
}
