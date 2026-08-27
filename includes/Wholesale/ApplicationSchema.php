<?php

namespace FluentCartBulkOrder\Wholesale;

defined('ABSPATH') || exit;

/**
 * What the wholesale application form asks for: two built-in questions plus
 * whatever else the store owner added.
 *
 * ---------------------------------------------------------------------------
 * A LIST OF FIELDS, NOT A FORM BUILDER
 * ---------------------------------------------------------------------------
 *
 * A store owner can add fields with a label, a key, a type, a required flag and
 * — for a select — a list of options. That is the whole vocabulary, on purpose.
 * No conditional logic, no file uploads, no reordering UI. A wholesale
 * application asks a handful of business questions once; a form builder is a
 * product of its own, and this plugin already has one place (FluentCart) that
 * owns complicated form-shaped problems.
 *
 * Company name and tax/VAT ID are built in and cannot be removed. They are what
 * the feature is FOR — an admin reviewing an application with neither has
 * nothing to decide on.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS NO WORDPRESS IN THIS FILE
 * ---------------------------------------------------------------------------
 *
 * Everything here is a pure map from array to array, which is what lets the
 * unit suite pin it without booting WordPress. It matters because the failure
 * mode is quiet: a schema that normalises a stored field badly does not error,
 * it renders a form the shopper cannot submit, or — worse — a select whose
 * options no longer match what the validator will accept.
 *
 * The rule about the split: STRUCTURE is decided here, ESCAPING is not. Labels
 * and options come back exactly as the owner typed them, and every render site
 * escapes them (they are owner-supplied text landing on a public page).
 * @see \FluentCartBulkOrder\Wholesale\ApplicationInput for the matching
 *      submitted-value side, which takes its sanitisers as arguments for the
 *      same reason.
 */
class ApplicationSchema
{
    /**
     * Single-line free text.
     */
    const TYPE_TEXT = 'text';

    /**
     * Multi-line free text.
     */
    const TYPE_TEXTAREA = 'textarea';

    /**
     * A single tick box. Stored as a bool.
     */
    const TYPE_CHECKBOX = 'checkbox';

    /**
     * A one-of-many choice from `options`.
     */
    const TYPE_SELECT = 'select';

    /**
     * Field key of the built-in company name question.
     */
    const KEY_COMPANY = 'company_name';

    /**
     * Field key of the built-in tax / VAT ID question.
     */
    const KEY_TAX_ID = 'tax_id';

    /**
     * How long a label or an option may be.
     *
     * Not a security control — the render sites escape, and the storage layer
     * sanitises. It stops a paste accident from putting a page of text into a
     * table cell on the review screen, and keeps one field's stored record a
     * sane size in user meta.
     */
    const MAX_LABEL_LENGTH = 120;

    /**
     * How many extra fields an owner may add.
     *
     * A ceiling rather than a judgement about the right number. The record for
     * every applicant is one serialized user meta row, and an unbounded field
     * list makes that row unbounded too.
     */
    const MAX_EXTRA_FIELDS = 20;

    /**
     * Every type an extra field may declare.
     *
     * @return string[]
     */
    public static function types()
    {
        return [self::TYPE_TEXT, self::TYPE_TEXTAREA, self::TYPE_CHECKBOX, self::TYPE_SELECT];
    }

    /**
     * The two questions every wholesale application asks.
     *
     * Both required: an application without a company name and a tax ID is not
     * an application, it is a blank form with a user id attached.
     *
     * Labels are returned translated, which is the one WordPress-shaped thing
     * this file does — `__()` is stubbed as a passthrough in the unit
     * bootstrap, so it stays testable. @see tests/bootstrap.php
     *
     * @return array<int, array<string, mixed>> Canonical field definitions.
     */
    public static function builtInFields()
    {
        return [
            [
                'key'      => self::KEY_COMPANY,
                'label'    => __('Company name', 'fluent-cart-bulk-order'),
                'type'     => self::TYPE_TEXT,
                'required' => true,
                'options'  => [],
                'built_in' => true,
            ],
            [
                'key'      => self::KEY_TAX_ID,
                'label'    => __('Tax / VAT ID', 'fluent-cart-bulk-order'),
                'type'     => self::TYPE_TEXT,
                'required' => true,
                'options'  => [],
                'built_in' => true,
            ],
        ];
    }

    /**
     * The keys an owner may not reuse.
     *
     * @return string[]
     */
    public static function reservedKeys()
    {
        return [self::KEY_COMPANY, self::KEY_TAX_ID];
    }

    /**
     * The complete field list the form renders and the validator judges.
     *
     * Built-ins always first, so the two questions an admin actually decides on
     * are at the top of the form and at the top of the review screen without
     * either having to sort.
     *
     * @param mixed $storedExtras Raw owner-configured extra fields, as stored.
     * @return array<int, array<string, mixed>>
     */
    public static function fields($storedExtras)
    {
        return array_merge(self::builtInFields(), self::normalizeFields($storedExtras));
    }

    /**
     * Clean an owner-submitted (or stored) extra-field list into canonical form.
     *
     * ---------------------------------------------------------------------------
     * WHAT IS DROPPED, AND WHY DROPPING BEATS REPAIRING
     * ---------------------------------------------------------------------------
     *
     * Every rule below throws a field away rather than guessing at a fix,
     * because a half-repaired field is worse than a missing one: the owner
     * sees SOMETHING on the settings page and assumes it works.
     *
     *   - No usable label            A field with no question is not a question.
     *   - Duplicate key              The second one would overwrite the first's
     *                                answer in the stored record — one key, one
     *                                answer, always.
     *   - Reserved key               `company_name` / `tax_id` belong to the
     *                                built-ins; an override would shadow a
     *                                required question with an optional one.
     *   - A select with no options   Nothing to choose. A REQUIRED one would
     *                                make the form permanently unsubmittable,
     *                                which is a shop-breaking outcome from a
     *                                typo on a settings page.
     *   - Beyond MAX_EXTRA_FIELDS    See the constant.
     *
     * An unknown type falls back to text instead of being dropped — the label
     * is the owner's real intent, the type is a detail, and text can hold any
     * answer. That is the one place repairing is better than dropping.
     *
     * @param mixed $raw Raw list, as stored or as submitted.
     * @return array<int, array<string, mixed>> Canonical definitions.
     */
    public static function normalizeFields($raw)
    {
        if (!is_array($raw)) {
            return [];
        }

        $fields = [];
        $seen   = self::reservedKeys();

        foreach ($raw as $field) {
            if (count($fields) >= self::MAX_EXTRA_FIELDS) {
                break;
            }

            if (!is_array($field)) {
                continue;
            }

            $label = self::trimText(isset($field['label']) ? $field['label'] : '');

            if ($label === '') {
                continue;
            }

            // The key is derived from the label when the owner did not supply
            // one, so the common case is "type a label, press save". A supplied
            // key wins because renaming a label must not orphan the answers
            // already stored under the old key.
            $key = self::sanitizeKey(isset($field['key']) ? $field['key'] : '');

            if ($key === '') {
                $key = self::keyFromLabel($label);
            }

            if ($key === '' || in_array($key, $seen, true)) {
                continue;
            }

            $type = self::normalizeType(isset($field['type']) ? $field['type'] : '');

            $options = $type === self::TYPE_SELECT
                ? self::normalizeOptions(isset($field['options']) ? $field['options'] : [])
                : [];

            if ($type === self::TYPE_SELECT && !$options) {
                continue;
            }

            $seen[] = $key;

            $fields[] = [
                'key'      => $key,
                'label'    => $label,
                'type'     => $type,
                'required' => !empty($field['required']),
                'options'  => $options,
                'built_in' => false,
            ];
        }

        return $fields;
    }

    /**
     * Look one field up by key in a normalized field list.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param string                           $key
     * @return array<string, mixed>|null
     */
    public static function findField($fields, $key)
    {
        if (!is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (is_array($field) && isset($field['key']) && $field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Derive a storage key from a label.
     *
     * Deliberately NOT sanitize_title(): this file has no WordPress, and the
     * result is an array key in a serialized record rather than a URL. Lower
     * case, ASCII letters, digits and underscores; everything else collapses to
     * a single underscore.
     *
     * A label with no ASCII letters or digits at all (a wholly non-Latin label,
     * for instance) yields '' — the caller then drops the field rather than
     * storing an answer under an empty key. Owners on such a site supply the
     * key themselves, which is why the key field exists on the settings page.
     *
     * @param string $label
     * @return string Possibly ''.
     */
    public static function keyFromLabel($label)
    {
        $key = strtolower(self::trimText($label));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim((string) $key, '_');

        // A key that is only digits would still be a valid array key, but PHP
        // silently casts a numeric string key to an int, which breaks the
        // strict === comparisons this class and ApplicationInput rely on.
        if ($key === '' || ctype_digit($key)) {
            return '';
        }

        return substr($key, 0, 60);
    }

    /**
     * Clean an owner-supplied key.
     *
     * @param mixed $key
     * @return string Possibly ''.
     */
    public static function sanitizeKey($key)
    {
        if (!is_scalar($key)) {
            return '';
        }

        return self::keyFromLabel((string) $key);
    }

    /**
     * Coerce a declared type into one this plugin can render.
     *
     * @param mixed $type
     * @return string One of the TYPE_* constants; TYPE_TEXT when unrecognised.
     */
    public static function normalizeType($type)
    {
        if (!is_scalar($type)) {
            return self::TYPE_TEXT;
        }

        $type = strtolower(trim((string) $type));

        return in_array($type, self::types(), true) ? $type : self::TYPE_TEXT;
    }

    /**
     * Clean a select field's option list.
     *
     * Accepts the newline-separated string the settings textarea posts as well
     * as an array, because the stored value is an array and the form value is
     * a block of text — and both reach this method.
     *
     * Options are de-duplicated and blank lines dropped. The stored option
     * string is BOTH the label the shopper reads and the value stored in the
     * record; splitting those into a value/label pair is form-builder territory
     * and buys nothing here.
     *
     * @param mixed $options
     * @return string[]
     */
    public static function normalizeOptions($options)
    {
        if (is_string($options)) {
            $options = preg_split('/\r\n|\r|\n/', $options);
        }

        if (!is_array($options)) {
            return [];
        }

        $clean = [];

        foreach ($options as $option) {
            if (!is_scalar($option)) {
                continue;
            }

            $option = self::trimText($option);

            if ($option !== '' && !in_array($option, $clean, true)) {
                $clean[] = $option;
            }
        }

        return $clean;
    }

    /**
     * Trim, collapse inner whitespace, and length-cap one piece of owner text.
     *
     * ---------------------------------------------------------------------------
     * WHY THE WHITESPACE COLLAPSE IS LOAD-BEARING, NOT TIDINESS
     * ---------------------------------------------------------------------------
     *
     * A select option is stored on the settings side and compared against on the
     * submission side, and until this line existed the two sides used different
     * rules:
     *
     *   settings save    sanitize_textarea_field() — keeps runs of spaces and
     *                    tabs, because it is told to keep newlines and
     *                    WordPress skips the whole whitespace collapse when it is
     *   submission       sanitize_text_field() — collapses [\r\n\t ]+ to ONE space
     *
     * So an owner who typed "Retail  Shops" (two spaces, or a tab pasted from a
     * spreadsheet) stored `Retail  Shops` and the shopper posted back
     * `Retail Shops`. ApplicationInput compares with `in_array(..., true)`, so
     * the only option on the list never matched — and because HTML collapses
     * whitespace when it RENDERS, the owner and the shopper both saw "Retail
     * Shops" and nothing looked wrong. A required select in that state makes the
     * form permanently unsubmittable for every shopper on the site, which is
     * precisely the outcome the empty-options rule above exists to prevent.
     *
     * Collapsing here fixes both sides at once, because this is the one function
     * both the rendered `<option value>` and the validator's option list come
     * through. ApplicationSettings::extraFields() re-normalises on READ, so it
     * also repairs options already in the database with no migration.
     *
     * normalizeOptions() splits on newlines BEFORE calling this, so collapsing
     * `\n` here cannot merge two options into one.
     *
     * mb_substr where available so a cap never cuts a multi-byte character in
     * half — a truncated UTF-8 sequence is what turns a long label into a black
     * diamond on the storefront.
     *
     * @param mixed $text
     * @return string
     */
    private static function trimText($text)
    {
        if (!is_scalar($text)) {
            return '';
        }

        $text = trim((string) preg_replace('/[\r\n\t ]+/', ' ', (string) $text));

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, self::MAX_LABEL_LENGTH);
        }

        return substr($text, 0, self::MAX_LABEL_LENGTH);
    }
}
