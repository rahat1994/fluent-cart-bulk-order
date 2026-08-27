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

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
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
