<?php

namespace FluentCartBulkOrder\Quotes;

defined('ABSPATH') || exit;

/**
 * Turns what a buyer submitted, and what an owner priced, into what gets
 * stored — and adds the line up.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS NO `QuoteSchema` BESIDE THIS
 * ---------------------------------------------------------------------------
 *
 * The wholesale application has an ApplicationSchema because the OWNER decides
 * what the form asks. A quote line has no such freedom: it is a product
 * variant, a quantity, and a price, because that is what an order line is.
 * Inventing a schema class for a fixed three-field shape would be ceremony, so
 * the shape lives in LINE_DEFAULTS below and the validation lives here.
 *
 * ---------------------------------------------------------------------------
 * THE TWO SIDES, AND WHY THEY ARE DIFFERENT FUNCTIONS
 * ---------------------------------------------------------------------------
 *
 *   validateRequest()  what the BUYER may set: variant ids and quantities.
 *                      Prices in this payload are IGNORED — read that again,
 *                      because it is the security property of this file. The
 *                      buyer's browser sends a price so the owner can see what
 *                      the form quoted, but the stored `requested_price` comes
 *                      from the server's own catalogue lookup, never from the
 *                      request. A buyer who edits the POST changes nothing.
 *
 *   applyPrices()      what the OWNER may set: one unit price per line, in
 *                      cents. Quantities and variants are NOT re-read from
 *                      that submission — the owner prices the lines the buyer
 *                      asked for, and a line the buyer never sent cannot
 *                      appear by being typed into the admin form.
 *
 * Both walk the STORED line list and look each key up in the submission, never
 * the other way round. There is no allow-list to keep in sync, because
 * iterating the stored lines IS the allow-list.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SANITISER IS AN ARGUMENT
 * ---------------------------------------------------------------------------
 *
 * This file has no WordPress in it, which is what lets the unit suite pin the
 * rules above without a database. The buyer's note genuinely does need
 * `sanitize_textarea_field()`, so it is INJECTED — the WordPress caller passes
 * the real one (@see QuoteStore::sanitizers()); the tests pass their own and
 * assert that whatever is passed is applied. The fallback when none is given
 * strips tags and control characters, so a caller that forgets still cannot
 * store markup — but it is a floor, not the intended path.
 *
 * Escaping is NOT done here. Values are stored raw-ish and escaped at every
 * render site, which is the WordPress rule: sanitize on the way in, escape on
 * the way out.
 *
 * @see \FluentCartBulkOrder\Quotes\QuoteStatus The state machine side.
 */
class QuoteInput
{
    /**
     * Error code: the submission contained no line the catalogue could use.
     */
    const ERROR_NO_LINES = 'no_lines';

    /**
     * How many lines one quote may carry.
     *
     * The record is one serialized post meta row, and the review screen renders
     * every line of every quote on the page. Matches the 200-item cap
     * `fcbo_sanitize_saved_items()` already applies to a Saved Order, so a
     * shopper cannot assemble something on the form that the quote path then
     * silently truncates differently.
     */
    const MAX_LINES = 200;

    /**
     * How many units one line may ask for.
     *
     * Not a business rule — Order Rules own those. This only stops a pasted
     * typo from producing a quote total that overflows into nonsense on the
     * review screen.
     */
    const MAX_QTY = 1000000;

    /**
     * The most a single unit may be priced at, in cents.
     *
     * Ten million (100,000.00 in a two-decimal currency). A ceiling rather than
     * a judgement: quote totals are summed as PHP integers, and an owner who
     * fat-fingers an extra six zeros should get a refusal, not an order.
     */
    const MAX_UNIT_PRICE = 1000000000;

    /**
     * How long the buyer's note may be, in characters.
     *
     * The note is stored, shown on an admin screen and emailed. A bounded
     * length is the friendlier answer on all three.
     */
    const MAX_NOTE_LENGTH = 2000;

    /**
     * The `quoted_price` value that means "the owner has not priced this yet".
     *
     * NOT 0. A store may legitimately quote a line at zero — a free sample, a
     * replacement, a bundled extra — and collapsing that into "unpriced" would
     * make the one price an owner most wants to be deliberate about the one
     * they cannot express.
     *
     * Declared BEFORE LINE_DEFAULTS, which references it. PHP resolves class
     * constants lazily so the order does not strictly matter, but a constant
     * that reads as undefined above its own use is the kind of thing a future
     * reader stops to check.
     */
    const PRICE_UNSET = -1;

    /**
     * The shape of one stored line, and the default for every key.
     *
     * `requested_price` is what the catalogue said when the buyer asked —
     * a snapshot, so the owner can see what the buyer expected to pay.
     * `quoted_price` is what the owner decided; -1 means "not priced yet",
     * because 0 is a legitimate price (a free sample line).
     *
     * @var array<string, mixed>
     */
    const LINE_DEFAULTS = [
        'variant_id'      => 0,
        'product_id'      => 0,
        'qty'             => 1,
        'title'           => '',
        'variation_title' => '',
        'sku'             => '',
        'payment_type'    => 'onetime',
        'requested_price' => 0,
        'quoted_price'    => self::PRICE_UNSET,
    ];

    /**
     * Clean a buyer's submitted line list down to {variantId, qty} pairs.
     *
     * Deliberately the same shape and the same guards as
     * `fcbo_sanitize_saved_items()`: the bulk order form sends one array for
     * both "save this order" and "quote this order", and two definitions of a
     * valid line would let the two disagree about what the shopper assembled.
     *
     * PRICES ARE NOT READ HERE. @see the class docblock.
     *
     * @param mixed $items Raw items from the request.
     * @return array<int, array{variantId: int, qty: int}> Consolidated, capped.
     */
    public static function sanitizeItems($items)
    {
        if (!is_array($items)) {
            return [];
        }

        $byVariant = [];
        $order     = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $variantId = isset($item['variantId']) ? self::toPositiveInt($item['variantId']) : 0;
            $qty       = isset($item['qty']) ? self::toPositiveInt($item['qty']) : 0;

            if ($variantId < 1 || $qty < 1) {
                continue;
            }

            $qty = min($qty, self::MAX_QTY);

            // Duplicate variants are MERGED rather than kept as separate lines.
            // The form already consolidates before checkout and before saving,
            // so a quote that did not would show the owner two rows for one
            // product and invite them to price the same thing twice.
            if (isset($byVariant[$variantId])) {
                $byVariant[$variantId]['qty'] = min(
                    self::MAX_QTY,
                    $byVariant[$variantId]['qty'] + $qty
                );

                continue;
            }

            $byVariant[$variantId] = ['variantId' => $variantId, 'qty' => $qty];
            $order[]               = $variantId;

            if (count($order) >= self::MAX_LINES) {
                break;
            }
        }

        $clean = [];

        foreach ($order as $variantId) {
            $clean[] = $byVariant[$variantId];
        }

        return $clean;
    }

    /**
     * Build the stored line list from cleaned items plus resolved catalogue data.
     *
     * The catalogue map is what the SERVER looked up, so every price, title and
     * SKU in the stored record came from the store's own data. An item whose
     * variant no longer resolves is DROPPED rather than stored as unavailable:
     * a quote is a thing an owner is asked to price, and a line that cannot be
     * bought is not something to price.
     *
     * @param array<int, array{variantId: int, qty: int}> $items    From sanitizeItems().
     * @param array<int, array<string, mixed>>            $resolved variantId => payload,
     *                                                              shaped like
     *                                                              fcbo_resolve_variant_ids().
     * @return array<int, array<string, mixed>> Lines in LINE_DEFAULTS' shape.
     */
    public static function buildLines($items, $resolved)
    {
        $items    = is_array($items) ? $items : [];
        $resolved = is_array($resolved) ? $resolved : [];

        $lines = [];

        foreach ($items as $item) {
            $variantId = isset($item['variantId']) ? (int) $item['variantId'] : 0;

            if ($variantId < 1 || !isset($resolved[$variantId]) || !is_array($resolved[$variantId])) {
                continue;
            }

            $product = $resolved[$variantId];
            $variant = isset($product['variant']) && is_array($product['variant']) ? $product['variant'] : [];

            $lines[] = array_merge(self::LINE_DEFAULTS, [
                'variant_id'      => $variantId,
                'product_id'      => isset($product['productId']) ? (int) $product['productId'] : 0,
                'qty'             => isset($item['qty']) ? (int) $item['qty'] : 1,
                'title'           => isset($product['title']) ? (string) $product['title'] : '',
                'variation_title' => isset($variant['variation_title']) ? (string) $variant['variation_title'] : '',
                'sku'             => isset($variant['sku']) ? (string) $variant['sku'] : '',
                'payment_type'    => isset($variant['payment_type']) ? (string) $variant['payment_type'] : 'onetime',
                'requested_price' => isset($variant['item_price']) ? max(0, (int) $variant['item_price']) : 0,
            ]);
        }

        return $lines;
    }

    /**
     * Apply an owner's typed prices to the lines already stored.
     *
     * Walks the STORED lines and looks each variant id up in the submission —
     * never the reverse. A price posted for a variant this quote does not carry
     * is ignored; a line the owner left blank keeps whatever it had, which for
     * a fresh quote means it falls back to the requested price rather than
     * silently becoming free.
     *
     * @param mixed $lines  Stored lines.
     * @param mixed $prices variantId => raw price in MINOR units (cents).
     * @return array<int, array<string, mixed>> The lines, priced.
     */
    public static function applyPrices($lines, $prices)
    {
        $lines  = is_array($lines) ? $lines : [];
        $prices = is_array($prices) ? $prices : [];

        $priced = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $line      = array_merge(self::LINE_DEFAULTS, $line);
            $variantId = (int) $line['variant_id'];

            $raw = array_key_exists($variantId, $prices) ? $prices[$variantId] : null;
            $set = self::toPrice($raw);

            if ($set !== null) {
                $line['quoted_price'] = $set;
            } elseif ((int) $line['quoted_price'] === self::PRICE_UNSET) {
                // Never priced and nothing usable typed: the catalogue price the
                // buyer was shown is the honest default. An owner who means
                // "free" types 0, which toPrice() accepts.
                $line['quoted_price'] = max(0, (int) $line['requested_price']);
            }

            $priced[] = $line;
        }

        return $priced;
    }

    /**
     * What a set of lines comes to.
     *
     * Integer cents throughout, matching every other total in this plugin. The
     * quoted total falls back to the requested price for a line nobody priced,
     * so a half-filled review screen still shows a number that means something.
     *
     * @param mixed $lines Stored lines.
     * @return array{requested: int, quoted: int, saving: int, units: int, lines: int}
     *         `saving` is what the buyer is being offered off the catalogue
     *         price; it is never negative, because a quote priced ABOVE the
     *         catalogue is a legitimate thing an owner may do (a small-batch
     *         surcharge) and calling that a negative saving reads as a bug.
     */
    public static function totals($lines)
    {
        $lines = is_array($lines) ? $lines : [];

        $requested = 0;
        $quoted    = 0;
        $units     = 0;
        $count     = 0;

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $line = array_merge(self::LINE_DEFAULTS, $line);
            $qty  = max(0, (int) $line['qty']);

            $unitRequested = max(0, (int) $line['requested_price']);
            $unitQuoted    = (int) $line['quoted_price'];

            if ($unitQuoted === self::PRICE_UNSET || $unitQuoted < 0) {
                $unitQuoted = $unitRequested;
            }

            $requested += $unitRequested * $qty;
            $quoted    += $unitQuoted * $qty;
            $units     += $qty;
            $count++;
        }

        return [
            'requested' => $requested,
            'quoted'    => $quoted,
            'saving'    => max(0, $requested - $quoted),
            'units'     => $units,
            'lines'     => $count,
        ];
    }

    /**
     * The unit price one line should actually be charged at.
     *
     * One method rather than the `PRICE_UNSET ? requested : quoted` ternary at
     * each call site, so the review screen, the buyer's email and the order
     * converter cannot disagree about what a line costs.
     *
     * @param mixed $line One stored line.
     * @return int Cents, never negative.
     */
    public static function effectivePrice($line)
    {
        $line = array_merge(self::LINE_DEFAULTS, is_array($line) ? $line : []);

        $quoted = (int) $line['quoted_price'];

        if ($quoted === self::PRICE_UNSET || $quoted < 0) {
            return max(0, (int) $line['requested_price']);
        }

        return $quoted;
    }

    /**
     * Whether any line in this quote is a subscription.
     *
     * FluentCart refuses subscription items in an admin-created order
     * (`fluent_cart/order/is_subscription_allowed_in_manual_order` defaults to
     * false), so the converter has to ask before it tries. Pure, so the rule is
     * testable without a store.
     *
     * @param mixed $lines Stored lines.
     * @return bool
     */
    public static function hasSubscription($lines)
    {
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (!is_array($line)) {
                continue;
            }

            $type = isset($line['payment_type']) ? strtolower(trim((string) $line['payment_type'])) : '';

            if ($type === 'subscription') {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean a free-text note.
     *
     * @param mixed         $note
     * @param callable|null $sanitizer Applied first; a multi-line sanitiser.
     * @return string
     */
    public static function sanitizeNote($note, $sanitizer = null)
    {
        if (!is_scalar($note)) {
            return '';
        }

        $note = self::applySanitizer((string) $note, $sanitizer);

        // Normalise line endings so a Windows browser and a Mac one produce the
        // same stored bytes for the same typed note.
        $note = str_replace(["\r\n", "\r"], "\n", $note);

        return self::cap(trim($note), self::MAX_NOTE_LENGTH);
    }

    /**
     * One raw price value as integer cents, or null when it says nothing.
     *
     * '' and null are "the owner did not type here" and must NOT collapse to 0
     * — that is the difference between "leave this line alone" and "give it
     * away". A negative number is refused rather than clamped, because a minus
     * sign in a price field is a typo, not an instruction.
     *
     * @param mixed $raw Value in MINOR units (cents).
     * @return int|null Cents, or null for "not set".
     */
    public static function toPrice($raw)
    {
        if (is_bool($raw) || !is_scalar($raw)) {
            return null;
        }

        $raw = trim((string) $raw);

        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $cents = (int) round((float) $raw);

        if ($cents < 0) {
            return null;
        }

        return min($cents, self::MAX_UNIT_PRICE);
    }

    /**
     * A raw value as a positive integer, refusing anything that is not a number.
     *
     * Deliberately NOT absint(): that turns "-3" into 3, quietly ordering three
     * of something the request asked for a negative number of.
     *
     * @param mixed $raw
     * @return int 0 when the value is unusable.
     */
    private static function toPositiveInt($raw)
    {
        if (is_bool($raw) || !is_scalar($raw) || !is_numeric($raw)) {
            return 0;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : 0;
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

        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this file is deliberately WordPress-free so the unit suite can pin it without a database; wp_strip_all_tags() is what the INJECTED sanitiser brings, and this is only the floor for a caller that supplies none.
        $value = strip_tags($value);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    /**
     * Cap a string's length without splitting a multi-byte character.
     *
     * @param string $value
     * @param int    $limit
     * @return string
     */
    private static function cap($value, $limit)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit);
        }

        return substr($value, 0, $limit);
    }
}
