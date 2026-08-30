<?php

namespace FluentCartBulkOrder\Admin\Settings;

use FluentCartBulkOrder\AccessPolicy;
use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * Access — who may open the surfaces, and how a shopper asks to be let in.
 *
 * The two sections here are the two halves of one question. "Surface Access" is
 * the standing answer: the roles that can reach the bulk order form today.
 * "Wholesale Applications" is the door: what a shopper is asked before an owner
 * grants them the Wholesale Customer role, which is the usual way a name gets
 * added to the first list. Reading either one without the other leaves the
 * owner guessing how someone actually becomes a trade buyer.
 */
class AccessTab extends Tab
{
    /**
     * How many blank rows the extra-questions table offers.
     *
     * Three, because there is no JavaScript here to add a row: the owner fills
     * what they need, saves, and the table comes back with three fresh blanks.
     * That is one page reload per three questions, which is a fair trade for
     * not shipping a repeater widget to a page with no build step.
     */
    const WHOLESALE_BLANK_ROWS = 3;

    /**
     * @inheritDoc
     */
    public function slug()
    {
        return Tabs::ACCESS;
    }

    /**
     * @inheritDoc
     */
    public function label()
    {
        return __('Access', 'fluent-cart-bulk-order');
    }

    /**
     * @inheritDoc
     */
    public function defaultsKeys()
    {
        return [
            'allowed_extra_roles',
            'wholesale_fields',
            'wholesale_notify_admin',
            'wholesale_crm_tag_applied',
            'wholesale_crm_tag_approved',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerSections($page)
    {
        $this->registerSurfaceAccessSection($page);
        $this->registerWholesaleSection($page);
    }

    /**
     * Gate 1 — who may open the surfaces at all.
     *
     * @param string $page Settings API page bucket.
     * @return void
     */
    private function registerSurfaceAccessSection($page)
    {
        add_settings_section(
            'fcbo_access_section',
            __('Surface Access', 'fluent-cart-bulk-order'),
            [$this, 'renderAccessIntro'],
            $page
        );

        add_settings_field(
            'fcbo_allowed_extra_roles_field',
            __('Also allow these roles', 'fluent-cart-bulk-order'),
            [$this, 'renderAllowedExtraRolesField'],
            $page,
            'fcbo_access_section'
        );
    }

    /**
     * The wholesale application form: what it asks, and what it tells.
     *
     * No register_setting() call of its own — every value lives in the single
     * `fcbo_store_defaults` option, which Settings registers into this tab's
     * group, and is validated by StoreDefaults::sanitize().
     *
     * @param string $page Settings API page bucket.
     * @return void
     */
    private function registerWholesaleSection($page)
    {
        add_settings_section(
            'fcbo_wholesale_section',
            __('Wholesale Applications', 'fluent-cart-bulk-order'),
            [$this, 'renderWholesaleIntro'],
            $page
        );

        add_settings_field(
            'fcbo_wholesale_fields_field',
            __('Extra questions', 'fluent-cart-bulk-order'),
            [$this, 'renderWholesaleFieldsField'],
            $page,
            'fcbo_wholesale_section'
        );

        add_settings_field(
            'fcbo_wholesale_notify_field',
            __('Notifications', 'fluent-cart-bulk-order'),
            [$this, 'renderWholesaleNotifyField'],
            $page,
            'fcbo_wholesale_section'
        );

        add_settings_field(
            'fcbo_wholesale_crm_field',
            __('FluentCRM tags', 'fluent-cart-bulk-order'),
            [$this, 'renderWholesaleCrmField'],
            $page,
            'fcbo_wholesale_section'
        );
    }

    /**
     * Section intro for Gate 1.
     *
     * @return void
     */
    public function renderAccessIntro()
    {
        echo '<p>' . esc_html__(
            'Administrators and wholesale customers can always reach the bulk order form, the product table and saved orders. Add any other roles that should reach them too.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Checklist of roles that widen Gate 1 beyond the baseline.
     *
     * Not renderRoleChecklist(): the baseline roles are merged in code, so this
     * one has rows the owner cannot untick, and saying so is the point.
     *
     * @return void
     */
    public function renderAllowedExtraRolesField()
    {
        $selected = (array) StoreDefaults::get('allowed_extra_roles', []);
        $baseline = AccessPolicy::BASELINE_ROLES;
        $roles    = $this->editableRoles();

        // Hidden field so an all-unchecked submission still reaches the
        // sanitizer, which is what lets the owner clear the list.
        echo '<input type="hidden" name="' . esc_attr($this->defaultsName('allowed_extra_roles')) . '[]" value="" />';

        echo '<fieldset>';
        foreach ($roles as $slug => $details) {
            $label      = isset($details['name']) ? $details['name'] : $slug;
            $isBaseline = in_array($slug, $baseline, true);

            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s %4$s /> %5$s%6$s</label>',
                esc_attr($this->defaultsName('allowed_extra_roles')),
                esc_attr($slug),
                checked($isBaseline || in_array($slug, $selected, true), true, false),
                // The baseline is merged in code, so showing it unchecked would
                // be a lie and unchecking it would do nothing. Shown ticked and
                // disabled instead, which is the honest rendering of a floor.
                disabled($isBaseline, true, false),
                esc_html($label),
                $isBaseline ? ' <em>' . esc_html__('(always allowed)', 'fluent-cart-bulk-order') . '</em>' : ''
            );
        }
        echo '</fieldset>';

        echo '<p class="description">' . esc_html__(
            'This widens both the on-page surfaces and the REST endpoints behind them, so a role added here can actually load products. A shortcode "roles" attribute still widens one placement only, and does not reach the REST routes.',
            'fluent-cart-bulk-order'
        ) . '</p>';
    }

    /**
     * Load the wholesale classes this tab reads.
     *
     * Lazily, and only here: these fields render on one tab of one admin
     * screen, and two of the three classes below are otherwise never needed in
     * wp-admin at all.
     *
     * @return void
     */
    private function loadWholesale()
    {
        require_once dirname(__DIR__, 2) . '/Wholesale/ApplicationSchema.php';
        require_once dirname(__DIR__, 2) . '/Wholesale/ApplicationSettings.php';
        require_once dirname(__DIR__, 2) . '/Integrations/FluentCrm/ContactTagger.php';
    }

    /**
     * Section intro.
     *
     * @return void
     */
    public function renderWholesaleIntro()
    {
        printf(
            '<p>%s</p>',
            esc_html__(
                'Put [fluent_cart_wholesale_application] on a page and signed-in customers can ask for a wholesale account. Applications arrive under Bulk Order > Wholesale Applications, and approving one grants the Wholesale Customer role.',
                'fluent-cart-bulk-order'
            )
        );

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'The form always asks for a company name and a tax / VAT ID. Anything below is asked as well.',
                'fluent-cart-bulk-order'
            )
        );
    }

    /**
     * The extra-questions table.
     *
     * A plain HTML table of parallel inputs, no JavaScript. Each column posts
     * as its own array, and ApplicationSettings::sanitizeFields() zips them
     * back into field definitions. @see
     * \FluentCartBulkOrder\Wholesale\ApplicationSettings::sanitizeFields()
     *
     * @return void
     */
    public function renderWholesaleFieldsField()
    {
        $this->loadWholesale();

        $fields = \FluentCartBulkOrder\Wholesale\ApplicationSettings::extraFields();
        $name   = $this->defaultsName('wholesale_fields');

        $types = [
            \FluentCartBulkOrder\Wholesale\ApplicationSchema::TYPE_TEXT     => __('Single line', 'fluent-cart-bulk-order'),
            \FluentCartBulkOrder\Wholesale\ApplicationSchema::TYPE_TEXTAREA => __('Paragraph', 'fluent-cart-bulk-order'),
            \FluentCartBulkOrder\Wholesale\ApplicationSchema::TYPE_SELECT   => __('Choose one', 'fluent-cart-bulk-order'),
            \FluentCartBulkOrder\Wholesale\ApplicationSchema::TYPE_CHECKBOX => __('Tick box', 'fluent-cart-bulk-order'),
        ];

        echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
        echo '<th>' . esc_html__('Question', 'fluent-cart-bulk-order') . '</th>';
        echo '<th style="width:16%;">' . esc_html__('Type', 'fluent-cart-bulk-order') . '</th>';
        echo '<th style="width:26%;">' . esc_html__('Choices', 'fluent-cart-bulk-order') . '</th>';
        echo '<th style="width:14%;">' . esc_html__('Required', 'fluent-cart-bulk-order') . '</th>';
        echo '</tr></thead><tbody>';

        // Only offer blank rows the schema would actually accept. Appending
        // three unconditionally means an owner already at the cap is invited to
        // fill in rows that are silently discarded on save.
        $spare = max(0, \FluentCartBulkOrder\Wholesale\ApplicationSchema::MAX_EXTRA_FIELDS - count($fields));
        $rows  = array_merge($fields, array_fill(0, min(self::WHOLESALE_BLANK_ROWS, $spare), null));

        foreach ($rows as $index => $field) {
            $this->renderWholesaleFieldRow($name, (int) $index, $field, $types);
        }

        echo '</tbody></table>';

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'Clear a question to remove it. "Choices" is only used by "Choose one" — put one choice on each line. A "Choose one" question with no choices is dropped, because nobody could answer it.',
                'fluent-cart-bulk-order'
            )
        );

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'Answers already given are stored under the question\'s key. Renaming a question keeps its key, so past applications keep their answers; changing the key starts a new question.',
                'fluent-cart-bulk-order'
            )
        );

        // The three rules that quietly reject a question, said out loud. Each
        // one is a drop with no error, and an owner who does not know them sees
        // "Settings saved" and a question that is not there.
        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                /* translators: %d: the maximum number of extra questions. */
                __('A key is worked out from the question when you leave it blank, using only Latin letters and digits — so if your question is written in another script, type a key yourself. company_name and tax_id are taken by the two built-in questions. At most %d extra questions.', 'fluent-cart-bulk-order'),
                \FluentCartBulkOrder\Wholesale\ApplicationSchema::MAX_EXTRA_FIELDS
            ))
        );

        if ($spare === 0) {
            printf(
                '<p class="description"><strong>%s</strong></p>',
                esc_html__('You have reached the limit. Clear a question to make room for another.', 'fluent-cart-bulk-order')
            );
        }
    }

    /**
     * One row of the extra-questions table.
     *
     * @param string                    $name  Base name attribute.
     * @param int                       $index Row index.
     * @param array<string, mixed>|null $field An existing field, or null for a
     *                                         blank row.
     * @param array<string, string>     $types Type slug => label.
     * @return void
     */
    private function renderWholesaleFieldRow($name, $index, $field, array $types)
    {
        $label    = $field ? $field['label'] : '';
        $key      = $field ? $field['key'] : '';
        $type     = $field ? $field['type'] : \FluentCartBulkOrder\Wholesale\ApplicationSchema::TYPE_TEXT;
        $options  = $field && !empty($field['options']) ? implode("\n", $field['options']) : '';
        $required = $field && !empty($field['required']);

        echo '<tr><td>';

        printf(
            '<input type="text" name="%1$s[label][]" value="%2$s" class="regular-text" placeholder="%3$s" /><br />',
            esc_attr($name),
            esc_attr($label),
            esc_attr__('e.g. Trade reference', 'fluent-cart-bulk-order')
        );

        printf(
            '<label class="description">%1$s <input type="text" name="%2$s[key][]" value="%3$s" placeholder="%4$s" /></label>',
            esc_html__('Key', 'fluent-cart-bulk-order'),
            esc_attr($name),
            esc_attr($key),
            esc_attr__('auto', 'fluent-cart-bulk-order')
        );

        echo '</td><td>';

        printf('<select name="%s[type][]">', esc_attr($name));
        foreach ($types as $slug => $typeLabel) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($slug),
                selected($type, $slug, false),
                esc_html($typeLabel)
            );
        }
        echo '</select>';

        echo '</td><td>';

        printf(
            '<textarea name="%1$s[options][]" rows="3" style="width:100%%;" placeholder="%2$s">%3$s</textarea>',
            esc_attr($name),
            esc_attr__("Retail\nWholesale", 'fluent-cart-bulk-order'),
            esc_textarea($options)
        );

        echo '</td><td>';

        // The checkbox carries the ROW INDEX as its value rather than "1". An
        // unticked checkbox posts nothing at all, so a value-less array would
        // shift every later row's required flag up by one — the classic
        // parallel-arrays bug. @see ApplicationSettings::sanitizeFields()
        printf(
            '<label><input type="checkbox" name="%1$s[required][]" value="%2$d"%3$s /> %4$s</label>',
            esc_attr($name),
            (int) $index,
            checked($required, true, false),
            esc_html__('Must answer', 'fluent-cart-bulk-order')
        );

        echo '</td></tr>';
    }

    /**
     * The admin notification toggle.
     *
     * @return void
     */
    public function renderWholesaleNotifyField()
    {
        printf(
            '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr($this->defaultsName('wholesale_notify_admin')),
            checked((bool) StoreDefaults::get('wholesale_notify_admin', true), true, false),
            esc_html__('Email me when someone applies', 'fluent-cart-bulk-order')
        );

        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                /* translators: %s: the site's admin email address. */
                __('Sent to %s. Applicants are always emailed when you approve or reject them.', 'fluent-cart-bulk-order'),
                (string) get_option('admin_email')
            ))
        );
    }

    /**
     * The two FluentCRM tag pickers.
     *
     * Existing tags only — this plugin does not create tags. A tag it invented
     * would survive uninstall (FluentCRM's data is FluentCRM's), and an owner
     * would find something in their CRM they never made.
     *
     * @return void
     */
    public function renderWholesaleCrmField()
    {
        $this->loadWholesale();

        $available = \FluentCartBulkOrder\Integrations\FluentCrm\ContactTagger::isAvailable();
        $tags      = $available ? \FluentCartBulkOrder\Integrations\FluentCrm\ContactTagger::tagOptions() : [];

        // An empty tag list still deserves a picker when an id is stored: that
        // is the one state where the id is guaranteed dead, and the picker is
        // the only place the owner can clear it. Hiding it there would pin the
        // dead id forever, which is the silent failure the "no longer in
        // FluentCRM" option exists to expose.
        $stored = (int) StoreDefaults::get('wholesale_crm_tag_applied', 0)
            + (int) StoreDefaults::get('wholesale_crm_tag_approved', 0);

        if (!$available || (!$tags && !$stored)) {
            $this->preserveTagChoices();

            printf(
                '<p class="description">%s</p>',
                $available
                    ? esc_html__('FluentCRM has no tags yet. Create one in FluentCRM first, then choose it here.', 'fluent-cart-bulk-order')
                    : esc_html__('FluentCRM is not active on this site, so there is nothing to tag. Activate it and these options appear.', 'fluent-cart-bulk-order')
            );

            return;
        }

        $this->renderTagSelect(
            'wholesale_crm_tag_applied',
            __('When someone applies', 'fluent-cart-bulk-order'),
            $tags
        );

        $this->renderTagSelect(
            'wholesale_crm_tag_approved',
            __('When you approve them', 'fluent-cart-bulk-order'),
            $tags
        );

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'The applicant\'s contact is tagged so you can run FluentCRM automations from it. A contact is created if they do not have one; an existing contact is never changed beyond the tag.',
                'fluent-cart-bulk-order'
            )
        );
    }

    /**
     * Carry the stored tag ids through a page load that cannot draw the pickers.
     *
     * ---------------------------------------------------------------------------
     * WITHOUT THIS, SAVING ANY OTHER FIELD ON THIS TAB ERASES THE OWNER'S TAGS
     * ---------------------------------------------------------------------------
     *
     * This tab DECLARES both tag keys as its own (@see defaultsKeys()), which is
     * what gives it permission to rewrite them — and permission cuts both ways.
     * StoreDefaults::sanitizeWholesale() reads an absent tag key as 0, meaning
     * "do not tag". So on any page load where the two `<select>`s are not
     * rendered — FluentCRM deactivated for an update, its tag list temporarily
     * unreadable — pressing Save on an unrelated field of THIS tab silently
     * discards a tag configuration that took the owner a trip to FluentCRM to
     * set up. Reactivating FluentCRM does not bring it back, and nothing says
     * what happened.
     *
     * The tab split does not fix this and was never going to: it protects keys
     * the form did not claim, and this form claims these two. A hidden input is
     * the same trick `allowed_extra_roles` and `table_columns` already use for
     * their all-unchecked case — make sure the key is always submitted, so
     * "absent" can keep its one meaning.
     *
     * @return void
     */
    private function preserveTagChoices()
    {
        foreach (['wholesale_crm_tag_applied', 'wholesale_crm_tag_approved'] as $key) {
            printf(
                '<input type="hidden" name="%1$s" value="%2$d" />',
                esc_attr($this->defaultsName($key)),
                (int) StoreDefaults::get($key, 0)
            );
        }
    }

    /**
     * One labelled tag dropdown.
     *
     * @param string             $key   StoreDefaults key.
     * @param string             $label Visible label.
     * @param array<int, string> $tags  Tag id => title.
     * @return void
     */
    private function renderTagSelect($key, $label, array $tags)
    {
        $selected = (int) StoreDefaults::get($key, 0);

        printf('<p><label>%s<br />', esc_html($label));
        printf('<select name="%s">', esc_attr($this->defaultsName($key)));
        printf('<option value="0">%s</option>', esc_html__('— no tag —', 'fluent-cart-bulk-order'));

        // A tag the owner chose and then deleted inside FluentCRM is no longer
        // in the list, so the select would fall back to showing "— no tag —" —
        // which is a lie: the dead id is still stored and still handed to
        // attachTags(), where it succeeds and tags nothing. Showing it says
        // what is actually configured, and lets the owner fix it.
        if ($selected > 0 && !isset($tags[$selected])) {
            printf(
                '<option value="%1$d" selected>%2$s</option>',
                absint($selected),
                esc_html(sprintf(
                    /* translators: %d: a FluentCRM tag id that no longer exists. */
                    __('Tag #%d — no longer in FluentCRM', 'fluent-cart-bulk-order'),
                    $selected
                ))
            );
        }

        foreach ($tags as $id => $title) {
            printf(
                '<option value="%1$d"%2$s>%3$s</option>',
                (int) $id,
                selected($selected, (int) $id, false),
                esc_html($title)
            );
        }

        echo '</select></label></p>';
    }
}
