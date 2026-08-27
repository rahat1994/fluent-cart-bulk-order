<?php

namespace FluentCartBulkOrder\Quotes;

use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * The owner-facing settings behind the Quote Request flow, read in one place.
 *
 * Every value lives in the single `fcbo_store_defaults` option, alongside the
 * rest of the plugin's non-gate settings, and is validated by
 * StoreDefaults::sanitize(). This class is the READ side only — it exists so
 * the shortcode, the REST controller, the review screen and the notifier all
 * ask the same question the same way, rather than each reaching into
 * StoreDefaults with its own fallback.
 *
 * @see \FluentCartBulkOrder\Wholesale\ApplicationSettings The same idea for
 *      the wholesale application flow.
 */
class QuoteSettings
{
    /**
     * Whether the store offers "Request a quote" at all.
     *
     * The three-layer rule applies on top of this: a `quotes` attribute written
     * on a shortcode placement beats it, and this beats the built-in fallback.
     * @see \FluentCartBulkOrder\StoreDefaults for why that ordering is a
     *      property of how the value is supplied rather than an if-statement.
     *
     * @return bool
     */
    public static function enabled()
    {
        return (bool) StoreDefaults::get(
            'quotes_enabled',
            StoreDefaults::FALLBACKS['quotes_enabled']
        );
    }

    /**
     * Whether to email the site admin when a buyer asks for a quote.
     *
     * Defaults to ON. A quote nobody is told about is a lost sale, and unlike a
     * wholesale application there is no other place in wp-admin a buyer's
     * request would surface on its own.
     *
     * @return bool
     */
    public static function notifyAdmin()
    {
        return (bool) StoreDefaults::get(
            'quotes_notify_admin',
            StoreDefaults::FALLBACKS['quotes_notify_admin']
        );
    }
}
