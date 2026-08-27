<?php

namespace FluentCartBulkOrder\Wholesale;

use FluentCartBulkOrder\StoreDefaults;

defined('ABSPATH') || exit;

/**
 * The store owner's choices about the wholesale application, read in one place.
 *
 * Every value lives in the existing `fcbo_store_defaults` option — one more
 * key each, not one more option. @see \FluentCartBulkOrder\StoreDefaults for
 * why everything new goes there, and for the sanitiser that validates these
 * on save.
 *
 * This class is a reader, deliberately: it holds the names of the keys and the
 * normalising every caller would otherwise repeat, and it writes nothing. The
 * settings page owns the writing.
 *
 * @see \FluentCartBulkOrder\Settings::registerWholesaleSection() The form.
 */
class ApplicationSettings
{
    /**
     * Owner-configured extra fields, on top of the two built-ins.
     */
    const KEY_FIELDS = 'wholesale_fields';

    /**
     * Whether to email the site admin when someone applies.
     */
    const KEY_NOTIFY_ADMIN = 'wholesale_notify_admin';

    /**
     * FluentCRM tag id applied when someone submits an application. 0 = none.
     */
    const KEY_TAG_APPLIED = 'wholesale_crm_tag_applied';

    /**
     * FluentCRM tag id applied when an application is approved. 0 = none.
     */
    const KEY_TAG_APPROVED = 'wholesale_crm_tag_approved';

    /**
     * The complete field list the form renders and the validator judges.
     *
     * Always contains the two built-ins, so a store that has never opened the
     * settings page still gets a working application form.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fields()
    {
        return ApplicationSchema::fields(self::extraFields());
    }

    /**
     * Just the owner-added fields, normalised.
     *
     * Normalised on READ as well as on save. The saved value is already clean,
     * but a value that arrived any other way — a migration, `update_option()`
     * in a snippet, a hand-edited export — must not be able to put a select
     * with no options in front of a shopper.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function extraFields()
    {
        return ApplicationSchema::normalizeFields(StoreDefaults::get(self::KEY_FIELDS, []));
    }

    /**
     * Whether the site admin is emailed about a new application.
     *
     * Defaults to true. An owner who never finds the review screen is the
     * failure mode this exists to prevent — the whole feature is worthless if
     * applications sit unseen.
     *
     * @return bool
     */
    public static function notifyAdmin()
    {
        return (bool) StoreDefaults::get(self::KEY_NOTIFY_ADMIN, true);
    }

    /**
     * FluentCRM tag id to apply on submission. 0 means "do not tag".
     *
     * @return int
     */
    public static function tagOnApply()
    {
        return max(0, (int) StoreDefaults::get(self::KEY_TAG_APPLIED, 0));
    }

    /**
     * FluentCRM tag id to apply on approval. 0 means "do not tag".
     *
     * @return int
     */
    public static function tagOnApprove()
    {
        return max(0, (int) StoreDefaults::get(self::KEY_TAG_APPROVED, 0));
    }

    /**
     * Clean an owner-submitted extra-field list from the settings form.
     *
     * The form posts parallel arrays — one per column of the repeater — so this
     * zips them back into field definitions before handing them to the schema.
     * A row where every column is blank is the empty "add another" row and is
     * dropped by the schema's own no-label rule.
     *
     * Only the SHAPE is assembled here; every decision about what is a valid
     * field is ApplicationSchema's. @see ApplicationSchema::normalizeFields()
     *
     * @param mixed $value Raw `wholesale_fields` value from $_POST.
     * @return array<int, array<string, mixed>>
     */
    public static function sanitizeFields($value)
    {
        if (!is_array($value)) {
            return [];
        }

        // Already in list-of-definitions shape (a stored value being re-saved,
        // or a programmatic update). Hand it straight to the schema.
        if (!isset($value['label'])) {
            return ApplicationSchema::normalizeFields(array_map(
                [self::class, 'sanitizeFieldRow'],
                array_values(array_filter($value, 'is_array'))
            ));
        }

        $labels   = isset($value['label']) && is_array($value['label']) ? array_values($value['label']) : [];
        $keys     = isset($value['key']) && is_array($value['key']) ? array_values($value['key']) : [];
        $types    = isset($value['type']) && is_array($value['type']) ? array_values($value['type']) : [];
        $options  = isset($value['options']) && is_array($value['options']) ? array_values($value['options']) : [];
        $required = isset($value['required']) && is_array($value['required']) ? array_values($value['required']) : [];

        $rows = [];

        foreach ($labels as $index => $label) {
            $rows[] = self::sanitizeFieldRow([
                'label'    => $label,
                'key'      => isset($keys[$index]) ? $keys[$index] : '',
                'type'     => isset($types[$index]) ? $types[$index] : '',
                'options'  => isset($options[$index]) ? $options[$index] : '',
                // The checkbox column posts the row index as its value, so an
                // unticked row is simply absent from the array rather than
                // shifting every later row's answers up by one.
                'required' => in_array((string) $index, array_map('strval', $required), true),
            ]);
        }

        return ApplicationSchema::normalizeFields($rows);
    }

    /**
     * Apply the WordPress sanitisers to one raw settings row.
     *
     * ApplicationSchema is WordPress-free by design, so the `sanitize_*` calls
     * that owner text needs happen here, on the way in, before the schema
     * decides the row's shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sanitizeFieldRow($row)
    {
        return [
            'label'    => isset($row['label']) && is_scalar($row['label']) ? sanitize_text_field((string) $row['label']) : '',
            'key'      => isset($row['key']) && is_scalar($row['key']) ? sanitize_text_field((string) $row['key']) : '',
            'type'     => isset($row['type']) && is_scalar($row['type']) ? sanitize_text_field((string) $row['type']) : '',
            'required' => !empty($row['required']),
            'options'  => isset($row['options']) && is_scalar($row['options'])
                ? sanitize_textarea_field((string) $row['options'])
                : (isset($row['options']) && is_array($row['options']) ? array_map('sanitize_text_field', $row['options']) : []),
        ];
    }
}
