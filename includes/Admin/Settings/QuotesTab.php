<?php

namespace FluentCartBulkOrder\Admin\Settings;

use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * Quotes — asking for a price instead of buying.
 *
 * The smallest tab on the page, and it stays a tab of its own rather than
 * joining Checkout. A quote request is the buyer NOT checking out: it takes a
 * different route, produces a different record, and is reviewed on a different
 * screen. Folding two opposite journeys under one heading would save a click
 * and cost the owner the distinction.
 */
class QuotesTab extends Tab
{
    /**
     * @inheritDoc
     */
    public function slug()
    {
        return Tabs::QUOTES;
    }

    /**
     * @inheritDoc
     */
    public function label()
    {
        return __('Quotes', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    public function defaultsKeys()
    {
        return [
            'quotes_enabled',
            'quotes_notify_admin',
        ];
    }

    /**
     * Request a quote: whether the bulk order form offers it at all.
     *
     * No register_setting() call of its own — every value lives in the single
     * `fcbo_store_defaults` option, which Settings registers into this tab's
     * group, and is validated by StoreDefaults::sanitize().
     *
     * @inheritDoc
     */
    public function registerSections($page)
    {
        add_settings_section(
            'fcbo_quotes_section',
            __('Quote Requests', 'fluent-cart-bulk-order'),
            [$this, 'renderQuotesIntro'],
            $page
        );

        add_settings_field(
            'fcbo_quotes_enabled_field',
            __('Request a quote', 'fluent-cart-bulk-order'),
            [$this, 'renderQuotesEnabledField'],
            $page,
            'fcbo_quotes_section'
        );

        add_settings_field(
            'fcbo_quotes_notify_field',
            __('Notifications', 'fluent-cart-bulk-order'),
            [$this, 'renderQuotesNotifyField'],
            $page,
            'fcbo_quotes_section'
        );
    }

    /**
     * Section intro for the quote-request settings.
     *
     * @return void
     */
    public function renderQuotesIntro()
    {
        // Lazily, and only here — this is the one screen that links to the
        // review screen, and the class is admin-only.
        require_once dirname(__DIR__, 2) . '/Quotes/QuoteReviewScreen.php';

        printf(
            '<p>%1$s</p><p><a href="%2$s">%3$s</a></p>',
            esc_html__(
                'Lets a buyer send you their filled bulk order table and ask for a price instead of checking out. You review it, set the prices, and turn it into a FluentCart order — which also lets a buyer ask for a subscription product and a one-time product together, something the cart cannot do.',
                'fluent-cart-bulk-order'
            ),
            esc_url(\FluentCartBulkOrder\Quotes\QuoteReviewScreen::pageUrl()),
            esc_html__('Open the quote requests screen', 'fluent-cart-bulk-order')
        );
    }

    /**
     * Whether the bulk order form offers a "Request a quote" button.
     *
     * @return void
     */
    public function renderQuotesEnabledField()
    {
        printf(
            '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr($this->defaultsName('quotes_enabled')),
            checked((bool) StoreDefaults::get('quotes_enabled', false), true, false),
            esc_html__('Show a "Request a quote" button on the bulk order form', 'fluent-cart-bulk-order')
        );

        echo '<p class="description">' . esc_html__(
            'A "quotes" attribute on the shortcode still wins for that placement, so you can offer quotes on one page and not another.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Whether to email the site admin when a quote request arrives.
     *
     * @return void
     */
    public function renderQuotesNotifyField()
    {
        printf(
            '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr($this->defaultsName('quotes_notify_admin')),
            checked((bool) StoreDefaults::get('quotes_notify_admin', true), true, false),
            esc_html__('Email me when a buyer asks for a quote', 'fluent-cart-bulk-order')
        );

        echo '<p class="description">' . esc_html__(
            'Sent to the site admin email. Repeat requests from the same buyer within fifteen minutes are not emailed again, but every one of them still appears on the quote requests screen.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }
}
