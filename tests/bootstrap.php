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

// The attribute schema behind the block and Elementor wrappers. It is here for
// the same reason the pricing classes are: it is a pure map from array to array,
// and it is the one place the shortcode-attribute precedence rule could be
// broken without anything visibly failing.
require_once dirname(__DIR__) . '/includes/Shortcodes/AttributeSchema.php';

require_once dirname(__DIR__) . '/includes/Pricing/OrderRules.php';
require_once dirname(__DIR__) . '/includes/Pricing/Tiers.php';
require_once dirname(__DIR__) . '/includes/Pricing/FeedResolver.php';
