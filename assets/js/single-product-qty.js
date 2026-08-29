/**
 * Make FluentCart's own quantity box on the single product page obey this
 * store's Order Rules.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * FluentCart hardcodes quantity 1 in four places of its single-product template
 * and offers no filter for any of them (see the PHP side,
 * includes/Display/QuantityRules.php, for the exact file:line list and for why a
 * server-side fix is impossible). A product with a minimum of 5 therefore opened
 * at 1, and Buy Now sent 1 to a server that correctly refuses it.
 *
 * Everything here is a *display* correction. The authority is still
 * includes/Cart/RuleEnforcement.php, which refuses an out-of-rule quantity no
 * matter what this file did or failed to do. So every step below is written to
 * fail quiet: a selector that finds nothing simply does nothing.
 *
 * WHAT FLUENTCART DOES, VERIFIED AGAINST ITS SHIPPED BUNDLE
 * --------------------------------------------------------
 * Read from fluent-cart/assets/SingleProduct.js and
 * fluent-cart/assets/FluentCartApp.js (both minified). These are the facts this
 * file is built on; re-check them after a FluentCart upgrade:
 *
 *   1. The quantity input is NOT the source of truth at click time. Add To Cart
 *      reads `data-quantity` off its own button; Buy Now is a plain anchor, so
 *      the `&quantity=` inside `href` is what reaches the server (and modal
 *      checkout re-parses that same href). The input only *pushes* its value
 *      onto those two via FluentCart's own `input` listener.
 *   2. That listener clamps to [1, 10000] and ignores `min`, `max` and `step`.
 *      The + and − buttons do their own `q+1` / `q-1` with a hard floor of 1 and
 *      never read `step`. So setting min/step on the input does NOT make the
 *      spinner move in valid increments — this file has to re-target it.
 *   3. `data-cart-id` on `[data-fluent-cart-product-quantity-container]` is
 *      written once by PHP and never updated. After a variation switch it is
 *      stale. The attribute that IS kept current lives on the Buy Now anchor and
 *      the Add To Cart button.
 *   4. Variation changes announce themselves as a `CustomEvent` on `window`,
 *      `fluentCartSingleProductVariationChanged`, carrying
 *      `detail.variationId`. It fires from selectVariation() BEFORE the buttons
 *      and the input are rewritten, so a listener must defer.
 *
 * The three normalization helpers below mirror PHP
 * OrderRules::normalize()/normalizeQty()/describe() and the copies in
 * product-table.js and bulk-order.js. All of them must change together: the
 * server rejects any quantity these would have altered.
 */
(function () {
    'use strict';

    var CONFIG = window.fcboSpqConfig || {};
    // Keyed by variant id. Only CONSTRAINED variants are in here — the PHP side
    // omits the rest, so an id that is missing means "no rule", which is exactly
    // what DEFAULTS below says.
    var VARIANTS = CONFIG.variants || {};
    var I18N = CONFIG.i18n || {};

    var DEFAULTS = { min_qty: 0, step: 1 };

    var input = null;
    var hint = null;
    var container = null;
    var rules = DEFAULTS;
    var appliedCartId = null;

    // --- Order rules -------------------------------------------------------

    function rulesFor(cartId) {
        // wp_localize_script serializes every scalar as a string, so parseInt is
        // not optional here even though PHP sent integers.
        var raw = (cartId && VARIANTS[String(cartId)]) || {};

        return {
            min_qty: Math.max(0, parseInt(raw.min_qty, 10) || 0),
            step: Math.max(1, parseInt(raw.step, 10) || 1)
        };
    }

    // Rounds UP only, so a shopper never silently receives less than they asked
    // for. Mirrors PHP OrderRules::normalizeQty().
    function normalizeQty(qty, r) {
        qty = Math.max(1, parseInt(qty, 10) || 1, r.min_qty);

        if (r.step > 1) {
            qty = Math.ceil(qty / r.step) * r.step;
        }

        return qty;
    }

    function smallestValid(r) {
        return normalizeQty(1, r);
    }

    // The largest permitted quantity strictly below `qty`, for the − button.
    //
    // Permitted quantities are exactly smallestValid + k*step for k >= 0: with
    // step > 1 they are the multiples of step at or above the minimum, and with
    // step === 1 they are every integer at or above it. That single description
    // is what makes stepping down expressible without re-deriving the rule.
    function previousValid(qty, r) {
        var floorQty = smallestValid(r);
        var below = qty - 1;

        if (below <= floorQty) {
            return floorQty;
        }

        return floorQty + Math.floor((below - floorQty) / r.step) * r.step;
    }

    // Mirrors PHP OrderRules::describe(). Both-set gets its own sentence rather
    // than two joined ones, because "Min 10" alone hides a case-pack rule and
    // "Sold in 10s" alone hides a minimum — either omission points the shopper at
    // a quantity the server will refuse.
    function describeRule(r) {
        if (r.min_qty > 0 && r.step > 1) {
            return fill(t('rule_min_and_step'), { min: r.min_qty, step: r.step });
        }
        if (r.step > 1) {
            return fill(t('rule_step'), { step: r.step });
        }
        if (r.min_qty > 0) {
            return fill(t('rule_min'), { min: r.min_qty });
        }

        return '';
    }

    // --- Reading and writing FluentCart's markup ---------------------------

    function purchaseControls() {
        return document.querySelectorAll(
            '[data-fluent-cart-direct-checkout-button], [data-fluent-cart-add-to-cart-button]'
        );
    }

    // Which variant is on screen right now.
    //
    // The buttons first and the container last, which is the opposite of the
    // obvious order: FluentCart rewrites `data-cart-id` on the buttons on every
    // variation change but never on the container, so the container's copy is
    // only trustworthy before the first switch. It stays in the chain as the
    // fallback for the very first read, when the buttons may not have been
    // touched yet.
    function currentCartId(preferred) {
        if (preferred) {
            return String(preferred);
        }

        var controls = purchaseControls();
        for (var i = 0; i < controls.length; i++) {
            var id = controls[i].getAttribute('data-cart-id');
            if (id) {
                return id;
            }
        }

        return (container && container.getAttribute('data-cart-id')) || '';
    }

    // Put a quantity everywhere FluentCart will read one from.
    //
    // We write `data-quantity` and the href ourselves AND dispatch `input` so
    // FluentCart writes them too. That is not redundant paranoia in both
    // directions: dispatching alone fails if FluentCart's bundle has not
    // initialised yet (this script may run first), and writing alone leaves the
    // host's internal idea of the quantity untouched.
    function pushQuantity(qty) {
        var controls = purchaseControls();

        for (var i = 0; i < controls.length; i++) {
            controls[i].setAttribute('data-quantity', String(qty));
            rewriteHrefQuantity(controls[i], qty);
        }

        if (input && String(input.value) !== String(qty)) {
            input.value = qty;
        }

        if (input) {
            // Not a bubbling event on purpose — FluentCart binds its listener
            // directly on the input, which is also why we dispatch on the input
            // rather than delegating from document.
            input.dispatchEvent(new Event('input'));
        }
    }

    // The Buy Now anchor is a real <a>. With FluentCart's JS unavailable the
    // browser just follows href, so the quantity baked into the URL by
    // ProductRenderer.php:1413 is the one that reaches the server.
    function rewriteHrefQuantity(el, qty) {
        var href = el.getAttribute('href');

        // Out-of-stock anchors have their href removed by FluentCart; nothing to
        // correct, and nothing to throw over.
        if (!href) {
            return;
        }

        el.setAttribute('href', href.replace(/([?&]quantity=)\d+/, '$1' + qty));
    }

    function renderHint() {
        if (!hint) {
            return;
        }

        // textContent, not innerHTML: the sentence is translator-supplied and
        // carries no markup.
        hint.textContent = describeRule(rules);
    }

    // --- The correction ----------------------------------------------------

    /**
     * Point the whole quantity UI at one variant's rules.
     *
     * A quantity the shopper already typed is kept when it satisfies the new
     * rules and replaced when it does not, rather than always resetting: a
     * variation switch on a product whose variants share a rule should not
     * silently throw away an order of 50.
     *
     * @param {string} [preferredCartId] Variant id from a variation-change event,
     *        which is more reliable than the DOM at the moment that event fires.
     */
    function apply(preferredCartId) {
        if (!input) {
            return;
        }

        var cartId = currentCartId(preferredCartId);
        appliedCartId = cartId;
        rules = rulesFor(cartId);

        // Set anyway even though FluentCart's own handlers ignore them: they are
        // what the browser's native spinner and validation use, and what an
        // assistive technology announces.
        input.setAttribute('min', String(Math.max(1, rules.min_qty)));
        input.setAttribute('step', String(rules.step));

        var typed = parseInt(input.value, 10) || 0;
        var settled = normalizeQty(typed, rules);

        pushQuantity(typed === settled ? typed : smallestValid(rules));
        renderHint();
    }

    // A quantity typed by hand, corrected once the shopper is done typing.
    // 'change' rather than 'input' so a half-typed "1" on the way to "12" is not
    // snapped to 5 mid-keystroke. The visible jump is the feedback; the hint
    // directly beneath says why it jumped.
    function onQtyChange() {
        pushQuantity(normalizeQty(parseInt(input.value, 10) || 0, rules));
    }

    /**
     * Re-target FluentCart's +1 / −1 to +step / −step.
     *
     * FluentCart's own handlers move the value by exactly one and never read
     * `step`, so on a product sold in 5s the + button walks a shopper straight to
     * 6. This deliberately does NOT stop their handler: it lets it run, then
     * corrects the result one tick later.
     *
     * Letting it run is the point. FluentCart's + button also enforces the stock
     * cap and the `max` attribute and shows its own message when it refuses — if
     * we swallowed the click we would have to reimplement all of that. When it
     * refuses, the value does not move, this sees no change, and it does nothing.
     *
     * The cost is one frame where the intermediate value (6) is on screen.
     */
    function onStepButton(direction) {
        var before = parseInt(input.value, 10) || 0;

        setTimeout(function () {
            var after = parseInt(input.value, 10) || 0;

            if (after === before) {
                return;
            }

            pushQuantity(
                direction > 0
                    ? normalizeQty(before + 1, rules)
                    : previousValid(normalizeQty(before, rules), rules)
            );
        }, 0);
    }

    function init() {
        container = document.querySelector('[data-fluent-cart-product-quantity-container]');
        input = document.getElementById('fct-product-qty-input');
        hint = document.querySelector('[data-fcbo-qty-hint]');

        // No quantity box means FluentCart decided this product has none (a
        // sold-individually or subscription-only product). Nothing to correct.
        if (!input) {
            return;
        }

        input.addEventListener('change', onQtyChange);

        bindStepButton('[data-fluent-cart-product-qty-increase-button]', 1);
        bindStepButton('[data-fluent-cart-product-qty-decrease-button]', -1);

        // The guarantee, independent of everything above.
        //
        // FluentCart reads the quantity in a click handler bound on `document`,
        // and it resets `data-quantity` back to "1" immediately after each
        // add-to-cart. A capture-phase listener runs before any of that, so
        // whatever drifted in between is corrected in the moment that matters —
        // the moment the quantity is read.
        document.addEventListener('click', onPurchaseClick, true);

        window.addEventListener('fluentCartSingleProductVariationChanged', onVariationChanged);
        watchVariationAttributes();

        apply();

        // Once more after every asset has loaded, because this script and
        // FluentCart's bundle have no dependency relationship and either order is
        // possible. apply() replaces a quantity only when it is invalid, so a
        // second run cannot undo a valid one the shopper typed in between.
        window.addEventListener('load', function () { apply(); });
    }

    function bindStepButton(selector, direction) {
        var btn = document.querySelector(selector);
        if (!btn) {
            return;
        }

        btn.addEventListener('click', function () { onStepButton(direction); });
    }

    function onPurchaseClick(e) {
        // A click can originate on a node with no closest() (a text node inside a
        // shadow boundary, document itself). This listener is on document and
        // sees every click on the page, so it must never be the thing that breaks
        // an unrelated one.
        if (!e.target || typeof e.target.closest !== 'function') {
            return;
        }

        var control = e.target.closest(
            '[data-fluent-cart-direct-checkout-button], [data-fluent-cart-add-to-cart-button]'
        );

        if (!control) {
            return;
        }

        pushQuantity(normalizeQty(parseInt(input.value, 10) || 0, rules));
    }

    function onVariationChanged(e) {
        var detail = e && e.detail;

        // Deferred on purpose. FluentCart dispatches this from selectVariation()
        // BEFORE it rewrites data-cart-id on the buttons and BEFORE it resets the
        // quantity to 1, so anything done synchronously here is overwritten a few
        // statements later. A 0ms timeout lands after the whole click handler.
        setTimeout(function () {
            apply(detail && detail.variationId);
        }, 0);
    }

    /**
     * Second route to the same signal, for variation switches that never
     * announce themselves.
     *
     * A MutationObserver rather than more event listeners because the thing worth
     * watching is a fact about the page (which variant the purchase controls now
     * point at) rather than a notification someone chose to send. Themes and
     * future FluentCart versions can change the selection without dispatching
     * fluentCartSingleProductVariationChanged; none of them can change it without
     * writing data-cart-id, because that attribute is what the purchase actually
     * uses.
     *
     * It watches the BUTTONS, not the quantity container the container's own
     * data-cart-id would suggest: FluentCart writes that container attribute once
     * from PHP and never updates it, so an observer there would never fire.
     *
     * No re-entrancy risk — apply() writes data-quantity and href, never
     * data-cart-id — and the id guard makes a redundant notification free.
     */
    function watchVariationAttributes() {
        if (typeof window.MutationObserver !== 'function') {
            return;
        }

        var observer = new MutationObserver(function () {
            if (currentCartId() === appliedCartId) {
                return;
            }
            apply();
        });

        var controls = purchaseControls();
        for (var i = 0; i < controls.length; i++) {
            observer.observe(controls[i], {
                attributes: true,
                attributeFilter: ['data-cart-id']
            });
        }
    }

    // --- Small shared helpers ---------------------------------------------

    // A translated sentence by key. I18N comes from wp_localize_script, so a
    // missing key means the PHP side was not updated — render the key itself so
    // that shows up in review instead of shipping an empty hint.
    function t(key) {
        return Object.prototype.hasOwnProperty.call(I18N, key) ? I18N[key] : key;
    }

    function fill(template, values) {
        return String(template || '').replace(/\{(\w+)\}/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
