<?php

namespace FluentCartBulkOrder\Tests\Unit;

use FluentCartBulkOrder\StoreDefaults;
use PHPUnit\Framework\TestCase;

/**
 * The rule that stops saving one settings tab from wiping another's.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------------
 *
 * Five tabs share ONE option and ONE sanitizer, and that sanitizer rebuilds
 * the whole array from what was posted. That was right while the page was a
 * single form carrying every field: a key being absent meant the owner had
 * cleared it.
 *
 * Tabs break that reading. A tabbed form carries one tab's fields, so absent
 * now usually means "on another tab" — and a sanitizer that cannot tell the two
 * apart wipes four tabs to save one, silently, with a success notice on screen.
 * Saving Quote Requests would turn the purchase-order field off.
 *
 * The plugin has already shipped this exact bug once, in the FluentCart
 * integration feed. @see
 * docs/solutions/integration-issues/fluentcart-integration-feed-strips-undeclared-settings-keys.md
 *
 * The defence is that a form declares which keys it carries. These tests are
 * what make that claim checkable, and they are deliberately written against
 * StoreDefaults::sanitizeAgainst() — the pure half — so they need no database.
 *
 * @see \FluentCartBulkOrder\StoreDefaults::SUBMITTED_KEYS
 */
class SettingsTabsTest extends TestCase
{
    /**
     * A stored option with one recognisable value from each tab.
     *
     * @return array<string, mixed>
     */
    private function storedSettings()
    {
        return [
            'allowed_extra_roles'   => ['editor'],
            'table_per_page'        => 25,
            'table_search'          => true,
            'checkout_redirect'     => 'https://example.test/checkout',
            'po_mode'               => 'required',
            'quotes_enabled'        => true,
            'quotes_notify_admin'   => true,
            'wholesale_notify_admin' => true,
        ];
    }

    /**
     * The bug this whole mechanism exists to prevent, stated as an assertion.
     *
     * Saving the Quotes tab must not touch the PO number field, the checkout
     * redirect, the product table settings or the role list. Before the
     * declaration existed, every one of those came back as its fallback.
     */
    public function testSavingOneTabLeavesEveryOtherTabAlone()
    {
        $stored = $this->storedSettings();

        // What the Quotes tab actually posts: its own two keys, plus the
        // declaration naming exactly those two.
        $submission = [
            StoreDefaults::SUBMITTED_KEYS => ['quotes_enabled', 'quotes_notify_admin'],
            'quotes_enabled'              => '1',
            'quotes_notify_admin'         => '1',
        ];

        $saved = StoreDefaults::sanitizeAgainst($submission, $stored);

        $this->assertSame(['editor'], $saved['allowed_extra_roles'], 'Access tab was wiped');
        $this->assertSame(25, $saved['table_per_page'], 'Surfaces tab was wiped');
        $this->assertSame('https://example.test/checkout', $saved['checkout_redirect'], 'Checkout tab was wiped');
        $this->assertSame('required', $saved['po_mode'], 'PO number setting was wiped');
        $this->assertTrue($saved['wholesale_notify_admin'], 'Wholesale setting was wiped');
    }

    /**
     * The other half: a declared key IS rebuilt from the submission, including
     * the case where the submission says nothing about it.
     *
     * This is why "treat every absent key as unchanged" was not the fix. An
     * unticked checkbox posts NOTHING, so absence is exactly how an owner turns
     * one off. A blanket merge would make every checkbox on the page one-way —
     * tickable, and then never untickable again.
     */
    public function testAnUntickedCheckboxOnTheSubmittedTabIsStillTurnedOff()
    {
        $stored = $this->storedSettings();

        // The owner unticked "enable quotes". The browser posts no key for it.
        $submission = [
            StoreDefaults::SUBMITTED_KEYS => ['quotes_enabled', 'quotes_notify_admin'],
            'quotes_notify_admin'         => '1',
        ];

        $saved = StoreDefaults::sanitizeAgainst($submission, $stored);

        $this->assertFalse($saved['quotes_enabled'], 'an unticked box on the saved tab must turn off');
        $this->assertTrue($saved['quotes_notify_admin'], 'a ticked box on the saved tab must stay on');
        $this->assertSame('required', $saved['po_mode'], 'and another tab must still be untouched');
    }

    /**
     * A submission that declares nothing replaces the option outright, which is
     * what every save did before tabs existed.
     *
     * Keeps a programmatic update_option() and any older form working the way
     * they always have, rather than silently gaining merge semantics.
     */
    public function testASubmissionWithNoDeclarationStillReplacesEverything()
    {
        $saved = StoreDefaults::sanitizeAgainst(['table_per_page' => 10], $this->storedSettings());

        $this->assertSame(10, $saved['table_per_page']);
        $this->assertSame('', $saved['checkout_redirect'], 'undeclared save should fall back, not merge');
    }

    /**
     * A declaration naming a key no tab owns cannot smuggle it into the option.
     *
     * The list arrives from a form, so it is attacker-controlled in the same
     * way any other posted field is.
     */
    public function testADeclarationCannotNameAKeyThePluginDoesNotOwn()
    {
        $saved = StoreDefaults::sanitizeAgainst(
            [
                StoreDefaults::SUBMITTED_KEYS => ['quotes_enabled', 'evil_key'],
                'quotes_enabled'              => '1',
                'evil_key'                    => 'payload',
            ],
            $this->storedSettings()
        );

        $this->assertArrayNotHasKey('evil_key', $saved);
        $this->assertTrue($saved['quotes_enabled']);
    }

    /**
     * The declaration itself is never stored. It describes the submission; it
     * is not part of it, and leaving it behind would make it grow on every save.
     */
    public function testTheDeclarationIsNotStored()
    {
        $saved = StoreDefaults::sanitizeAgainst(
            [
                StoreDefaults::SUBMITTED_KEYS => ['quotes_enabled'],
                'quotes_enabled'              => '1',
            ],
            $this->storedSettings()
        );

        $this->assertArrayNotHasKey(StoreDefaults::SUBMITTED_KEYS, $saved);
    }

    /**
     * A junk declaration changes nothing rather than wiping everything. The
     * safe failure for a mechanism this destructive is "no effect".
     */
    public function testAnUnusableDeclarationIsTheSafeFailure()
    {
        $stored = $this->storedSettings();

        $saved = StoreDefaults::sanitizeAgainst(
            [
                StoreDefaults::SUBMITTED_KEYS => ['nothing_real'],
                'quotes_enabled'              => '',
            ],
            $stored
        );

        $this->assertSame('required', $saved['po_mode']);
        $this->assertTrue($saved['quotes_enabled'], 'an undeclared key keeps its stored value');
    }

    /**
     * Every key any tab declares has to be a key the sanitizer knows, or saving
     * that tab drops the field it was trying to save.
     *
     * Pinned against FALLBACKS rather than eyeballed, so a sixth tab that
     * invents a key fails here instead of in a store owner's settings.
     */
    public function testEveryDeclarableKeyIsAKeyTheSanitizerKnows()
    {
        $declared = [
            // AccessTab
            'allowed_extra_roles', 'wholesale_fields', 'wholesale_notify_admin',
            'wholesale_crm_tag_applied', 'wholesale_crm_tag_approved',
            // SurfacesTab
            'table_per_page', 'table_columns', 'table_search', 'table_expand_variants',
            // CheckoutTab
            'checkout_redirect', 'po_mode', 'po_roles',
            // QuotesTab
            'quotes_enabled', 'quotes_notify_admin',
        ];

        foreach ($declared as $key) {
            $saved = StoreDefaults::sanitizeAgainst(
                [StoreDefaults::SUBMITTED_KEYS => [$key]],
                $this->storedSettings()
            );

            $this->assertArrayHasKey($key, $saved, $key . ' is declared by a tab but unknown to the sanitizer');
        }
    }
}
