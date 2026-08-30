<?php
/**
 * Test bootstrap — deliberately does NOT load WordPress.
 *
 * The classes under test are pure functions over arrays. Standing up a whole
 * WordPress install to exercise integer arithmetic would make the suite slow,
 * fragile and dependent on a database's contents, which is the opposite of what
 * makes a test worth having. So the two things WordPress would otherwise
 * provide are stubbed here, in full view:
 *
 *   ABSPATH  every plugin file guards on it, so it must simply exist.
 *   __()     translation passthrough. The suite asserts on English source
 *            strings; what a translator does with them is not its business.
 *
 * If a class ever needs more than this, that is a signal it is no longer pure —
 * either push the impure part outward, or cover it in an integration test
 * instead of widening these stubs.
 */

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

// Stubbed for Menu::legacyArgs(), which maps it over a redirect's query string.
// A passthrough is honest here: the test is asking whether an argument SURVIVES
// the redirect, not how WordPress would scrub it. Stripping tags in the stub
// would test the stub.
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return is_scalar($str) ? trim((string) $str) : '';
    }
}

// StoreDefaults::sanitize() validates role lists through AccessPolicy, which
// calls get_editable_roles() — a wp-admin function AccessPolicy::editableRoles()
// has to require_once because it is absent outside wp-admin. There is no
// wp-admin here at all, so this stub stands in for the whole file.
//
// It returns the roles this plugin's own tests reason about, which is enough:
// what is under test is which KEYS survive a tabbed save, and a role list is
// only along for the ride as one of those keys' values.
// The sanitizers reach for these WordPress helpers. Passthrough-ish stubs: what
// is under test is which settings KEYS survive a save, not how WordPress scrubs
// a string, and a stub that reimplemented the scrubbing would be testing itself.
if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}

if (!function_exists('absint')) {
    function absint($n)
    {
        return abs((int) $n);
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return trim((string) $url);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('get_editable_roles')) {
    function get_editable_roles()
    {
        return [
            'administrator'      => ['name' => 'Administrator'],
            'editor'             => ['name' => 'Editor'],
            'author'             => ['name' => 'Author'],
            'subscriber'         => ['name' => 'Subscriber'],
            'customer'           => ['name' => 'Customer'],
            'wholesale-customer' => ['name' => 'Wholesale Customer'],
        ];
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

// WordPress composes this one out of the two above. Stubbed rather than
// skipped because the string it escapes is the ONE thing an admin sees when
// FluentCart is missing, and a test asserting on an unescaped string would pass
// while the shipped code emitted raw markup.
//
// It escapes directly instead of wrapping the __() stub above. The two are the
// same thing here — that stub is a passthrough — and calling __() with
// variables makes the i18n sniff read this file as real translation code and
// reject it. $domain is kept only so the signature matches what callers pass.
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        unset($domain);

        return esc_html($text);
    }
}

/*
 * Two stubs below are a different kind from the ones above: they are not
 * passthroughs, they are WordPress state that a test has to be able to SET.
 *
 * They exist for one reason — ShortcodeHandler must keep working when
 * FluentCart is not installed, and "works" there means two different answers
 * for two different viewers. Proving that needs a capability check whose answer
 * the test controls, and a shortcode registry the test can read back.
 *
 * Both read a global rather than taking an argument, because the code under
 * test calls them through WordPress's own signatures and cannot be handed a
 * double. Each test is responsible for resetting what it sets.
 */

// Which capabilities the "current user" holds. Empty means a logged-out
// shopper, which is the default a test should not have to opt into.
$GLOBALS['fcbo_test_caps'] = [];

if (!function_exists('current_user_can')) {
    function current_user_can($capability)
    {
        return in_array($capability, $GLOBALS['fcbo_test_caps'], true);
    }
}

// What add_shortcode() would have handed WordPress: tag => callback.
$GLOBALS['fcbo_test_shortcodes'] = [];

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback)
    {
        $GLOBALS['fcbo_test_shortcodes'][$tag] = $callback;
    }
}

// Number formatting, which WordPress localises and the suite does not need to.
// Same class of stub as __(): a passthrough that lets a pure class build a
// human-readable label without a locale behind it.
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0)
    {
        return number_format((float) $number, (int) $decimals);
    }
}

// A plain integer constant from wp-includes/default-constants.php, in the same
// category as ABSPATH: something that must simply exist, with no behaviour of
// its own to stub out.
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);

// The attribute schema behind the block and Elementor wrappers. It is here for
// the same reason the pricing classes are: it is a pure map from array to array,
// and it is the one place the shortcode-attribute precedence rule could be
// broken without anything visibly failing.
require_once dirname(__DIR__) . '/includes/Shortcodes/AttributeSchema.php';

// The three pure classes behind the wholesale application flow. They are here
// for the same reason: each is a map from array to array, and each guards
// something whose failure is silent. The status machine decides whether a
// request may hand out the `wholesale-customer` role; the schema decides what
// the form asks; the input validator decides which posted keys are allowed to
// reach a stored record at all.
require_once dirname(__DIR__) . '/includes/Wholesale/ApplicationStatus.php';
require_once dirname(__DIR__) . '/includes/Wholesale/ApplicationSchema.php';
require_once dirname(__DIR__) . '/includes/Wholesale/ApplicationInput.php';

// The two pure classes behind the request-a-quote flow, here for the same
// reason. QuoteStatus decides whether a request may create a real order at a
// hand-typed price; QuoteInput decides which of a submission's values are
// allowed anywhere near a stored quote — and it is the one place that knows an
// empty price box means "leave this line alone" rather than "free".
require_once dirname(__DIR__) . '/includes/Quotes/QuoteStatus.php';
require_once dirname(__DIR__) . '/includes/Quotes/QuoteInput.php';

// The two pure classes behind the B2B checkout extras. PoNumber decides
// whether a checkout is refused for a missing purchase-order number, and
// OrderCsv decides what ends up in a file the store hands to a buyer's
// accounts department. Both fail silently when they are wrong: an unreadable
// mode that defaulted to "required" would stop a store selling, and a cell a
// spreadsheet runs as a formula still downloads perfectly.
require_once dirname(__DIR__) . '/includes/Checkout/PoNumber.php';
require_once dirname(__DIR__) . '/includes/Export/OrderCsv.php';

// Pure, and deliberately extracted from ProductsController so it can be here.
// The rule it holds — which variants a search highlighted — has to agree with
// SQL written in a different file, and a unit test is the only place that
// agreement gets checked without a store behind it.
require_once dirname(__DIR__) . '/includes/Rest/SearchMatch.php';

// Not a pure class, and here anyway. ShortcodeHandler is the plugin's front
// door: it decides what a page holding one of our tags shows, and the case worth
// pinning is the one nobody exercises by hand — FluentCart absent. It qualifies
// for this suite because registration and the hostless render path touch no
// database and no FluentCart class, which is precisely the property under test.
require_once dirname(__DIR__) . '/includes/Shortcodes/ShortcodeHandler.php';

// The admin Shortcodes tab's data half, and the class that owns the create-page
// action's constants. Both are here for one reason: the tab is only useful if
// it cannot fall behind the registry above, and every way it CAN fall behind is
// silent. A tag with no description still renders a card; a tag the usage scan
// cannot find still shows a Create page button on a store that already made
// one. ShortcodeCatalog itself is pure — a map from a tag to sentences — and
// ShortcodePages is required only for the two constants the catalog is checked
// against, not for anything it does with the database.
require_once dirname(__DIR__) . '/includes/Shortcodes/ShortcodeCatalog.php';
require_once dirname(__DIR__) . '/includes/Admin/ShortcodePages.php';

// Pure, and here because none of the rules in it are ours. Each is a copy of a
// refusal FluentCart performs — quantity pinned to 1, a subscription never
// beside a one-time line — repeated so our surfaces can warn a shopper before
// the cart does. A copy that drifts from the original is the failure mode: the
// surface promises something the cart then refuses, which is issue #34.
require_once dirname(__DIR__) . '/includes/Cart/SubscriptionRule.php';

require_once dirname(__DIR__) . '/includes/Pricing/OrderRules.php';
require_once dirname(__DIR__) . '/includes/Pricing/Tiers.php';
require_once dirname(__DIR__) . '/includes/Pricing/FeedResolver.php';

// The four pure classes behind owner analytics. Every one of them decides
// something an owner then acts on, and every one of them fails quietly when it
// is wrong: a window boundary computed in the wrong timezone reports the wrong
// quarter, a tier signature that is not stable merges two different discounts
// into one row, a revenue split that does not clamp prints a negative
// "normal checkout", and a tier-usage merge that drops a key hides the unused
// tier the whole panel exists to surface.
require_once dirname(__DIR__) . '/includes/Analytics/Period.php';
require_once dirname(__DIR__) . '/includes/Analytics/Surface.php';
require_once dirname(__DIR__) . '/includes/Analytics/TierSignature.php';
require_once dirname(__DIR__) . '/includes/Analytics/TierUsage.php';
require_once dirname(__DIR__) . '/includes/Analytics/RevenueSplit.php';

// A display class, and here for one method. QuantityRules::sharedRules() decides
// whether the Order Rule hint on the single product page can be written in PHP at
// all — FluentCart picks the default variant inside its own renderer and does not
// pass that choice to the hook we render from, so the sentence is only safe when
// every variant agrees. It is a pure map from array to array like everything else
// above, and it fails silently: get it wrong and the page still renders, quoting
// a rule that does not apply to what the shopper is looking at.
require_once dirname(__DIR__) . '/includes/Display/QuantityRules.php';

// The admin menu, plus the five screen classes it parents. Menu is pure — a
// slug map, an ordering rule and a bubble string — and it is here because
// nothing it decides fails loudly: a slug that has drifted from its screen
// hangs a submenu off nothing, and an ordering mistake puts a duplicated parent
// row in the sidebar. The screens come along ONLY so the test can compare each
// slug against the class that owns it; none of them touches WordPress or
// FluentCart at include time.
require_once dirname(__DIR__) . '/includes/Admin/Menu.php';
require_once dirname(__DIR__) . '/includes/StoreDefaults.php';
require_once dirname(__DIR__) . '/includes/AccessPolicy.php';
require_once dirname(__DIR__) . '/includes/Settings.php';

// The settings tab registry. Loading it constructs nothing by itself; a test
// that calls Tabs::all() pulls in the tab classes, none of which touches
// WordPress until one of its render methods runs. It is here for the one thing
// about a tab that fails silently in the browser: a tab that stores nothing but
// still claims to be a form gets wrapped in the settings <form>, and every
// action button it draws then submits the settings instead of the action.
require_once dirname(__DIR__) . '/includes/Admin/Settings/Tabs.php';
require_once dirname(__DIR__) . '/includes/Quotes/QuoteReviewScreen.php';
require_once dirname(__DIR__) . '/includes/Wholesale/ReviewScreen.php';
require_once dirname(__DIR__) . '/includes/Analytics/AnalyticsScreen.php';
require_once dirname(__DIR__) . '/includes/Export/OrderExportScreen.php';
