<?php

namespace FluentCartBulkOrder\Wholesale;

defined('ABSPATH') || exit;

/**
 * Turns what a shopper posted into what gets stored — judged against the
 * configured schema, never against the shape of the request.
 *
 * ---------------------------------------------------------------------------
 * THE ONE RULE
 * ---------------------------------------------------------------------------
 *
 * THE SCHEMA DECIDES WHAT EXISTS. The submission only supplies values.
 *
 * validate() walks the FIELD LIST and looks each key up in the submission, and
 * never the other way round. A posted key that no field declares is not
 * rejected with an error, it is simply never read — so an attacker adding
 * `status=approved`, `user_id=1` or a hundred junk keys to the form post gets a
 * record containing exactly the fields the owner configured and nothing else.
 * There is no allow-list to keep in sync, because iterating the schema IS the
 * allow-list.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SANITISERS ARE ARGUMENTS
 * ---------------------------------------------------------------------------
 *
 * This file has no WordPress in it, which is what lets the unit suite pin the
 * rules above without a database. But the values genuinely do need
 * `sanitize_text_field()` and `sanitize_textarea_field()` — they are shopper
 * input heading for storage and, later, an admin screen.
 *
 * So the sanitisers are INJECTED. The WordPress caller passes the real ones
 * (@see \FluentCartBulkOrder\Wholesale\ApplicationStore::sanitizers()); the
 * tests pass their own and assert that whatever is passed is actually applied.
 * The fallback when none is given strips tags and control characters, so a
 * caller that forgets still cannot store markup — but it is a floor, not the
 * intended path.
 *
 * Escaping is NOT done here. Values are stored raw-ish and escaped at every
 * render site, which is the WordPress rule: sanitize on the way in, escape on
 * the way out. Sanitising twice would corrupt an apostrophe in a company name.
 *
 * @see \FluentCartBulkOrder\Wholesale\ApplicationSchema The field list side.
 */
class ApplicationInput
{
    /**
     * Error code: a required field was left empty.
     */
    const ERROR_REQUIRED = 'required';

    /**
     * Error code: a select value was not one of that field's options.
     *
     * Distinct from ERROR_REQUIRED on purpose. An empty optional select is
     * fine; a select carrying a value the owner never configured means the
     * posted form did not come from the rendered page, and the applicant
     * deserves to be told something different from "you missed a field".
     */
    const ERROR_INVALID_OPTION = 'invalid_option';

    /**
     * How long a single submitted answer may be, in characters.
     *
     * The record is one serialized user meta row. Without a cap, one submission
     * can make that row megabytes wide, and every review-screen page load then
     * reads all of them.
     */
    const MAX_VALUE_LENGTH = 2000;

    /**
     * Validate and clean a submission against a field list.
     *
     * @param array<int, array<string, mixed>> $fields     Normalized field list.
     *                                                     @see ApplicationSchema::fields()
     * @param mixed                            $submitted  Raw posted values,
     *                                                     keyed by field key.
     * @param callable|null                    $sanitizeText     Applied to text,
     *                                                     select and checkbox
     *                                                     labels.
     * @param callable|null                    $sanitizeTextarea Applied to
     *                                                     textarea values, which
     *                                                     must keep newlines.
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     *         `values` holds one entry per declared field, always — a field the
     *         shopper skipped is stored as '' or false rather than omitted, so
     *         the review screen can show "not answered" instead of guessing
     *         whether the question existed at the time.
     */
    public static function validate($fields, $submitted, $sanitizeText = null, $sanitizeTextarea = null)
    {
        $fields    = is_array($fields) ? $fields : [];
        $submitted = is_array($submitted) ? $submitted : [];

        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['key'])) {
                continue;
            }

            $key      = (string) $field['key'];
            $type     = isset($field['type']) ? $field['type'] : ApplicationSchema::TYPE_TEXT;
            $required = !empty($field['required']);
            $raw      = array_key_exists($key, $submitted) ? $submitted[$key] : null;

            if ($type === ApplicationSchema::TYPE_CHECKBOX) {
                $checked = self::isChecked($raw);

                // "Required" on a tick box means "you must agree", which is the
                // only thing a single checkbox can meaningfully require — a
                // terms box, a confirmation that the details are true.
                if ($required && !$checked) {
                    $errors[$key] = self::ERROR_REQUIRED;
                }

                $values[$key] = $checked;
                continue;
            }

            if ($type === ApplicationSchema::TYPE_SELECT) {
                $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
                $value   = self::asText($raw, $sanitizeText);

                if ($value === '') {
                    if ($required) {
                        $errors[$key] = self::ERROR_REQUIRED;
                    }

                    $values[$key] = '';
                    continue;
                }

                // Compared against the CONFIGURED options, not against anything
                // the request carried. A value that is not on the list is
                // discarded rather than stored, so a tampered select can never
                // put free text into a field an admin reads as a fixed choice.
                if (!in_array($value, $options, true)) {
                    $errors[$key]  = self::ERROR_INVALID_OPTION;
                    $values[$key] = '';
                    continue;
                }

                $values[$key] = $value;
                continue;
            }

            $value = $type === ApplicationSchema::TYPE_TEXTAREA
                ? self::asMultilineText($raw, $sanitizeTextarea)
                : self::asText($raw, $sanitizeText);

            if ($required && $value === '') {
                $errors[$key] = self::ERROR_REQUIRED;
            }

            $values[$key] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Whether a raw checkbox value means "ticked".
     *
     * An unticked box posts NOTHING — it is absent from $_POST entirely — so
     * null has to mean false. The literal string '0' means false as well,
     * because that is what a hidden companion input posts on some themes.
     *
     * @param mixed $raw
     * @return bool
     */
    public static function isChecked($raw)
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if ($raw === null || is_array($raw) || is_object($raw)) {
            return false;
        }

        $raw = strtolower(trim((string) $raw));

        return $raw !== '' && !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * One raw value as a clean single-line string.
     *
     * An array or object here means the request was hand-built (a form input
     * named `company_name[]`, say). That collapses to '' rather than being
     * flattened: a required field then reports as missing, which is the honest
     * outcome, instead of storing "Array".
     *
     * @param mixed         $raw
     * @param callable|null $sanitizer
     * @return string
     */
    private static function asText($raw, $sanitizer)
    {
        if (!is_scalar($raw)) {
            return '';
        }

        $value = self::applySanitizer((string) $raw, $sanitizer);

        // Newlines are stripped AFTER sanitising: sanitize_text_field() already
        // does this, but a caller passing a different sanitiser must not be able
        // to smuggle a line break into a single-line answer, where it would
        // break the review screen's one-row-per-field layout.
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);

        return self::cap(trim((string) $value));
    }

    /**
     * One raw value as a clean multi-line string.
     *
     * @param mixed         $raw
     * @param callable|null $sanitizer
     * @return string
     */
    private static function asMultilineText($raw, $sanitizer)
    {
        if (!is_scalar($raw)) {
            return '';
        }

        $value = self::applySanitizer((string) $raw, $sanitizer);

        // Normalise line endings so a Windows browser and a Mac one produce the
        // same stored bytes for the same typed answer.
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return self::cap(trim($value));
    }

    /**
     * Run the injected sanitiser, or the built-in floor when none was given.
     *
     * @param string        $value
     * @param callable|null $sanitizer
     * @return string
     */
    private static function applySanitizer($value, $sanitizer)
    {
        if (is_callable($sanitizer)) {
            $result = call_user_func($sanitizer, $value);

            return is_scalar($result) ? (string) $result : '';
        }

        return self::fallbackSanitize($value);
    }

    /**
     * The floor a caller gets when it supplies no sanitiser.
     *
     * Strips tags and every control character except tab and newline. Not a
     * replacement for `sanitize_text_field()` — it does not touch invalid UTF-8
     * or octet sequences — which is exactly why the WordPress caller passes the
     * real thing. This exists so a forgotten argument cannot store markup.
     *
     * @param string $value
     * @return string
     */
    private static function fallbackSanitize($value)
    {
        $value = strip_tags($value);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    /**
     * Cap one answer's length without splitting a multi-byte character.
     *
     * @param string $value
     * @return string
     */
    private static function cap($value)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
        }

        return substr($value, 0, self::MAX_VALUE_LENGTH);
    }
}
