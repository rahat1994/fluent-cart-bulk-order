<?php

namespace FluentCartBulkOrder;

defined('ABSPATH') || exit;

/**
 * Store-wide defaults — the values a shop owner sets once instead of repeating
 * on every shortcode placement.
 *
 * ---------------------------------------------------------------------------
 * PRECEDENCE — one direction, never reversed
 * ---------------------------------------------------------------------------
 *
 *     shortcode attribute  >  stored default  >  hardcoded fallback
 *
 * The stored default is fed in as the `shortcode_atts()` DEFAULT, which is what
 * makes this one-directional by construction: an attribute the author wrote
 * always wins, and a store that has never opened the settings page behaves
 * exactly as it did before this option existed.
 *
 * Read this order the other way round and per-placement overrides break, which
 * is why no render site is allowed to consult a stored default *after* it has
 * resolved an attribute.
 *
 * ---------------------------------------------------------------------------
 * WHY A SEPARATE OPTION FROM THE ROLE GATES
 * ---------------------------------------------------------------------------
 *
 * The role gates that PREDATE this option keep their own top-level ones (see
 * AccessPolicy; Gate 4 was added after it and lives in here, as everything new
 * should).
 * Folding them in here would rename live options and silently drop the policy
 * of every store that already saved one. Everything genuinely NEW lives in this
 * single serialized array instead, so later work adds a key and a line to
 * sanitize() rather than another top-level option.
 *
 * The option name deliberately differs from Settings::OPTION_GROUP: that group
 * is already called `fcbo_settings`, and giving the group and one of its
 * options the same name is legal but reads like a bug.
 */
class StoreDefaults
{
    /**
     * Option holding every store-wide default, as one associative array.
     */
    const OPTION = 'fcbo_store_defaults';

    /**
     * The key a submitted form uses to declare WHICH keys it is submitting.
     *
     * ---------------------------------------------------------------------------
     * WHY A FORM HAS TO SAY WHAT IT IS SUBMITTING
     * ---------------------------------------------------------------------------
     *
     * Every key below shares ONE option and ONE sanitizer, and the sanitizer
     * rebuilds the whole array from what was posted. That was correct while the
     * settings page was a single form carrying every field: absent meant the
     * owner had cleared it.
     *
     * The page is tabbed now, so a form carries one tab's fields. Absent no
     * longer means cleared — it usually means "on another tab" — and a
     * sanitizer that cannot tell the two apart wipes four tabs to save one.
     * Saving Quote Requests would silently turn the purchase-order field off.
     * @see docs/solutions/integration-issues/fluentcart-integration-feed-strips-undeclared-settings-keys.md
     *
     * Two fixes were available, and the other one does not work here. "Treat an
     * absent key as unchanged" reads well until you meet a checkbox: an
     * unticked box posts NOTHING AT ALL, so absence is exactly how the owner
     * turns `quotes_enabled` off. That reading would make every checkbox on the
     * page one-way — tickable, never untickable.
     *
     * So the form declares its keys instead. A declared key is rebuilt from the
     * submission, absent-means-off included; an undeclared key keeps its stored
     * value untouched. The list is rendered as hidden inputs by the settings
     * page, one per key the visible tab owns.
     *
     * A submission with no declaration at all is treated as the whole option,
     * which is what it was before tabs existed — so a programmatic
     * `update_option()` and an old form both still replace outright.
     *
     * @see \FluentCartBulkOrder\Admin\Settings\Tab::defaultsKeys()
     */
    const SUBMITTED_KEYS = '__submitted_keys';

    /**
     * Canonical product-table columns, in their canonical order.
     *
     * Lives here rather than on the shortcode class because the settings
     * sanitizer needs it at `admin_init`, and the shortcode classes load only
     * when FluentCart is active. ProductTable::ALL_COLUMNS aliases this, so
     * there is still exactly one list.
     */
    const TABLE_COLUMNS = ['id', 'title', 'price', 'qty', 'action'];

    /**
     * Rows per page allowed range, matching the /catalog route's own cap.
     */
    const MIN_PER_PAGE = 1;
    const MAX_PER_PAGE = 100;

    /**
     * The hardcoded fallback for every key — what the plugin did before this
     * option existed, and what an unset or unusable stored value falls back to.
     *
     * An empty `table_columns` means "every column", matching what the empty
     * `columns` shortcode attribute has always meant.
     *
     * @var array<string, mixed>
     */
    const FALLBACKS = [
        'allowed_extra_roles'   => [],
        'checkout_redirect'     => '',
        'table_per_page'        => 5,
        'table_columns'         => [],
        'table_search'          => true,
        'table_expand_variants' => false,

        // Wholesale application flow. `wholesale_fields` holds the owner's
        // EXTRA questions only — company name and tax ID are built into
        // \FluentCartBulkOrder\Wholesale\ApplicationSchema and are not stored,
        // so an owner cannot delete the two questions the review screen exists
        // to show. The two tag ids are FluentCRM tag ids; 0 means "do not tag",
        // which is also what a store without FluentCRM always reads.
        'wholesale_fields'           => [],
        'wholesale_notify_admin'     => true,
        'wholesale_crm_tag_applied'  => 0,
        'wholesale_crm_tag_approved' => 0,

        // Request-a-quote. OFF by default, and deliberately: a store that has
        // never opened the settings page behaves exactly as it did before this
        // feature existed, and an owner who is not watching for quote requests
        // must not be quietly given a button that collects them.
        // @see \FluentCartBulkOrder\Quotes\QuoteSettings
        'quotes_enabled'      => false,
        'quotes_notify_admin' => true,

        // The purchase-order field at checkout. OFF by default for the same
        // reason quotes are: an existing store's checkout must look exactly the
        // same after an upgrade as it did before it. `po_roles` is Gate 4 and an
        // empty list there means EVERY shopper — safe only because the mode
        // above is off until an owner turns it on.
        // @see \FluentCartBulkOrder\Checkout\PoNumber
        // @see \FluentCartBulkOrder\AccessPolicy for the Gate 4 note.
        //
        // The literal 'off' rather than PoNumber::MODE_OFF, deliberately. This
        // array is read on EVERY page load (Gate 1 goes through all()), and a
        // class constant here would make that read pull in a file this one has
        // no reason to load. Same rule Deactivator follows for the wholesale
        // meta keys. The two must not drift; PoNumber::sanitizeMode() maps
        // anything unrecognised back to 'off', so the worst a drift can do is
        // land on this same value.
        'po_mode'  => 'off',
        'po_roles' => [],
    ];

    /**
     * Request cache for all(). Null until the first read.
     *
     * @var array<string, mixed>|null
     */
    private static $cache = null;

    /**
     * Every stored default, merged over the fallbacks.
     *
     * Cached for the request: the shortcodes, the REST permission callback and
     * Gate 1 all read through here, so an uncached version would hit the options
     * table repeatedly on a single page load.
     *
     * @return array<string, mixed>
     */
    public static function all()
    {
        if (self::$cache === null) {
            $stored = get_option(self::OPTION, []);
            self::$cache = array_merge(self::FALLBACKS, is_array($stored) ? $stored : []);
        }

        return self::$cache;
    }

    /**
     * One stored default.
     *
     * @param string $key      A key of self::FALLBACKS.
     * @param mixed  $fallback Overrides the built-in fallback. Pass this when the
     *                         caller already owns the "what did this do before"
     *                         constant, so the two cannot drift.
     * @return mixed
     */
    public static function get($key, $fallback = null)
    {
        $all = self::all();

        if (array_key_exists($key, $all) && $all[$key] !== null) {
            return $all[$key];
        }

        return $fallback !== null ? $fallback : null;
    }

    /**
     * Drop the request cache.
     *
     * Hooked to this option's own update, so the settings page re-reads what it
     * just saved instead of the copy it read while rendering the form.
     *
     * @return void
     */
    public static function flush()
    {
        self::$cache = null;
    }

    /**
     * Validate a submitted settings array against the same allowlists the render
     * paths use.
     *
     * Registered as the `sanitize_callback`, so it must be public and must never
     * fatal: a bad value is replaced by its fallback, not rejected. An option
     * that cannot produce a broken surface is the whole point.
     *
     * @param mixed $value Raw submitted value.
     * @return array<string, mixed>
     */
    public static function sanitize($value)
    {
        // Read BEFORE the write, which is safe here and only here: update_option()
        // sanitizes first and reads the old value second, so this is the value
        // currently in the table, not the one being saved.
        $stored = get_option(self::OPTION, []);

        return self::sanitizeAgainst($value, is_array($stored) ? $stored : []);
    }

    /**
     * The same validation, against a stored value handed in rather than read.
     *
     * Split out of sanitize() for one reason: it is the whole of the rule that
     * decides whether saving one tab keeps another tab's settings, and a rule
     * that dangerous should be provable without a database behind it.
     * @see \FluentCartBulkOrder\Tests\Unit\SettingsTabsTest
     *
     * @param mixed                $value  Raw submitted value.
     * @param array<string, mixed> $stored The option as it stands today.
     * @return array<string, mixed>
     */
    public static function sanitizeAgainst($value, array $stored)
    {
        $value     = is_array($value) ? $value : [];
        $submitted = self::submittedKeys($value);

        // Never stored: it describes the submission, it is not part of it.
        unset($value[self::SUBMITTED_KEYS]);

        $clean = [];

        // Widens Gate 1 for BOTH the surfaces and the REST routes. The baseline
        // is merged in code by AccessPolicy, so nothing stored here can remove
        // an administrator's own access.
        $clean['allowed_extra_roles'] = AccessPolicy::sanitizeRoleList(
            isset($value['allowed_extra_roles']) ? $value['allowed_extra_roles'] : []
        );

        // Same-site only, matching the `redirect` attribute's own guard. An
        // off-site URL collapses to '' and the store checkout page is used.
        $redirect = isset($value['checkout_redirect']) ? trim((string) $value['checkout_redirect']) : '';
        $clean['checkout_redirect'] = $redirect === ''
            ? ''
            : (string) wp_validate_redirect(esc_url_raw($redirect), '');

        $clean['table_per_page'] = self::sanitizePerPage(
            isset($value['table_per_page']) ? $value['table_per_page'] : null
        );

        $clean['table_columns'] = self::sanitizeColumns(
            isset($value['table_columns']) ? $value['table_columns'] : []
        );

        $clean['table_search'] = !empty($value['table_search']);
        $clean['table_expand_variants'] = !empty($value['table_expand_variants']);

        $clean = array_merge($clean, self::sanitizeWholesale($value));

        // Two plain checkboxes, so `!empty()` is the whole rule — an unticked
        // box posts nothing at all, which is why this cannot be written as
        // isset() with a fallback to the stored value.
        $clean['quotes_enabled']      = !empty($value['quotes_enabled']);
        $clean['quotes_notify_admin'] = !empty($value['quotes_notify_admin']);

        $clean = array_merge($clean, self::sanitizePoNumber($value));

        // Nothing declared: the submission is the whole option. @see SUBMITTED_KEYS.
        if ($submitted === null) {
            return $clean;
        }

        // Declared keys come from the submission; everything else is left as it
        // was found. The stored side is filtered through FALLBACKS so an
        // unknown key that reached the option some other way is still dropped,
        // exactly as a full replace used to drop it.
        return array_merge(
            array_intersect_key($stored, self::FALLBACKS),
            array_intersect_key($clean, array_flip($submitted))
        );
    }

    /**
     * The keys a submission says it carries, or null if it did not say.
     *
     * Intersected with FALLBACKS rather than trusted: this arrives from a form,
     * and a key that is not one of ours has nowhere to go. A declaration that
     * survives as an empty list is the safe failure — it changes nothing.
     *
     * @param array<string, mixed> $value Raw submitted value.
     * @return string[]|null
     */
    private static function submittedKeys($value)
    {
        if (!isset($value[self::SUBMITTED_KEYS])) {
            return null;
        }

        $declared = is_array($value[self::SUBMITTED_KEYS])
            ? $value[self::SUBMITTED_KEYS]
            : [$value[self::SUBMITTED_KEYS]];

        $declared = array_map('strval', array_filter($declared, 'is_scalar'));

        return array_values(array_intersect($declared, array_keys(self::FALLBACKS)));
    }

    /**
     * Validate the purchase-order settings.
     *
     * Split out for the same reason sanitizeWholesale() is: the mode is judged
     * by \FluentCartBulkOrder\Checkout\PoNumber, which owns every rule about
     * what a legal mode is — including that anything unreadable becomes OFF
     * rather than REQUIRED, so a corrupted option cannot stop a store selling.
     *
     * The file is required lazily. This branch only runs when an admin presses
     * Save, while StoreDefaults itself is parsed on every page load.
     *
     * @param array<string, mixed> $value Raw submitted value.
     * @return array<string, mixed>
     */
    private static function sanitizePoNumber($value)
    {
        require_once __DIR__ . '/Checkout/PoNumber.php';

        return [
            'po_mode'  => \FluentCartBulkOrder\Checkout\PoNumber::sanitizeMode(
                isset($value['po_mode']) ? $value['po_mode'] : self::FALLBACKS['po_mode']
            ),
            // Gate 4. Empty means every shopper, so an all-unchecked submission
            // has to reach here — which is what the hidden field on the
            // settings page guarantees.
            'po_roles' => AccessPolicy::sanitizeRoleList(
                isset($value['po_roles']) ? $value['po_roles'] : []
            ),
        ];
    }

    /**
     * Validate the wholesale application settings.
     *
     * Split out because it is the one part of this sanitiser that needs another
     * class: the field list is judged by
     * \FluentCartBulkOrder\Wholesale\ApplicationSchema, which owns every rule
     * about what a valid field is. Duplicating any of that here would give the
     * settings page a second, drifting definition of "valid field".
     *
     * The Wholesale files are required lazily rather than at the top of this
     * one. StoreDefaults is loaded on EVERY page load (Gate 1 reads it), and
     * this branch only runs when an admin presses Save on the settings page.
     *
     * The two FluentCRM tag ids are stored as plain integers with no check that
     * the tag exists. Deliberate: FluentCRM may be inactive at save time, and a
     * tag can be deleted at any point afterwards, so a tag id is validated
     * where it is USED and never where it is stored. @see
     * \FluentCartBulkOrder\Integrations\FluentCrm\ContactTagger::tagUser()
     *
     * @param array<string, mixed> $value Raw submitted value.
     * @return array<string, mixed>
     */
    private static function sanitizeWholesale($value)
    {
        require_once __DIR__ . '/Wholesale/ApplicationSchema.php';
        require_once __DIR__ . '/Wholesale/ApplicationSettings.php';

        return [
            'wholesale_fields' => \FluentCartBulkOrder\Wholesale\ApplicationSettings::sanitizeFields(
                isset($value['wholesale_fields']) ? $value['wholesale_fields'] : []
            ),
            'wholesale_notify_admin'     => !empty($value['wholesale_notify_admin']),
            'wholesale_crm_tag_applied'  => isset($value['wholesale_crm_tag_applied']) ? absint($value['wholesale_crm_tag_applied']) : 0,
            'wholesale_crm_tag_approved' => isset($value['wholesale_crm_tag_approved']) ? absint($value['wholesale_crm_tag_approved']) : 0,
        ];
    }

    /**
     * Clamp rows-per-page into the range the /catalog route accepts.
     *
     * @param mixed $value Raw submitted value.
     * @return int
     */
    private static function sanitizePerPage($value)
    {
        // Deliberately NOT absint(): that turns -3 into 3, quietly giving the
        // owner a page size they never asked for. Anything that is not already a
        // positive number is a typo, and a typo should fall back, not be guessed.
        if (!is_numeric($value) || (int) $value < self::MIN_PER_PAGE) {
            return self::FALLBACKS['table_per_page'];
        }

        return min(self::MAX_PER_PAGE, (int) $value);
    }

    /**
     * Keep only real column keys, in canonical order.
     *
     * Order is imposed, not accepted: the PHP header and the JS body both walk
     * this list, so a stored order would only let the two disagree. Selecting
     * every column stores an empty array, which is what "no restriction" has
     * always meant.
     *
     * @param mixed $value Raw submitted value.
     * @return string[]
     */
    private static function sanitizeColumns($value)
    {
        if (!is_array($value)) {
            return self::FALLBACKS['table_columns'];
        }

        $submitted = array_map('sanitize_key', $value);
        $clean = array_values(array_intersect(self::TABLE_COLUMNS, $submitted));

        // Nothing valid, or everything: both mean "no restriction".
        if (!$clean || count($clean) === count(self::TABLE_COLUMNS)) {
            return [];
        }

        return $clean;
    }
}
