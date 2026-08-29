(function() {
    var currencySign = (window.fcboBpConfig && window.fcboBpConfig.currency_sign) || '$';
    var I18N = (window.fcboBpConfig && window.fcboBpConfig.i18n) || {};

    function formatPrice(cents) {
        return currencySign + (cents / 100).toFixed(2);
    }

    // Fill {named} placeholders in a template from fcbo_savings_strings().
    // An unknown key is left alone rather than blanked, so a mistranslated
    // placeholder shows up as itself instead of silently vanishing.
    // Mirrors fill() in bulk-order.js.
    function fill(template, values) {
        return String(template || '').replace(/\{(\w+)\}/g, function(match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
        });
    }

    // The row "model" PHP wrote into data-fcbo-variant: id, price, tiers and
    // order_rules. One reader so the shape is described in exactly one place.
    function variantData(row) {
        return JSON.parse(row.getAttribute('data-fcbo-variant'));
    }

    // --- Order rules ---
    //
    // These mirror PHP OrderRules::normalize()/normalizeQty() and the copies in
    // product-table.js, bulk-order.js and single-product-qty.js. All of them must
    // change together: the server rejects any quantity these would have altered.
    function orderRules(data) {
        var raw = (data && data.order_rules) || {};

        return {
            min_qty: Math.max(0, parseInt(raw.min_qty, 10) || 0),
            step: Math.max(1, parseInt(raw.step, 10) || 1)
        };
    }

    // Rounds UP only, so a shopper never silently receives less than they asked
    // for — with one addition the other surfaces do not need: 0 is preserved
    // rather than raised to the minimum. On this widget every variant of the
    // product has a row whether or not the shopper wants it, and an empty row
    // means "not ordering this one", which no rule constrains. Raising it to the
    // minimum would put products in the cart nobody asked for.
    function normalizeQty(qty, rules) {
        qty = parseInt(qty, 10) || 0;

        if (qty <= 0) {
            return 0;
        }

        qty = Math.max(1, qty, rules.min_qty);

        if (rules.step > 1) {
            qty = Math.ceil(qty / rules.step) * rules.step;
        }

        return qty;
    }

    // Mirrors PHP fcbo_match_tier(): several tiers can match one quantity (an
    // open-ended "30+" still matches at 70 when a "60+" exists), so the most
    // specific match wins — the highest min_qty, not the first one found.
    function resolveTier(tiers, qty) {
        var best = null;
        var bestMin = -1;

        for (var i = 0; i < tiers.length; i++) {
            var t = tiers[i];
            var min = parseInt(t.min_qty, 10) || 0;
            var max = parseInt(t.max_qty, 10) || 0;

            if (qty < min || (max > 0 && qty > max)) {
                continue;
            }

            if (min >= bestMin) {
                best = t;
                bestMin = min;
            }
        }

        return best;
    }

    // Mirrors PHP fcbo_apply_tier_to_price(): the ONE effective-price formula for
    // this surface. Money tier values are stored in major units, so they convert to
    // cents here. Keep in lock-step with the PHP helper and bulk-order.js.
    function applyTierToPrice(priceCents, tier) {
        if (!tier) return priceCents;

        var type = tier.discount_type || 'percent';
        var value = parseFloat(tier.discount_value) || 0;
        var result;

        if (type === 'fixed_unit_price') {
            result = Math.round(value * 100);
        } else if (type === 'amount_off') {
            result = priceCents - Math.round(value * 100);
        } else {
            result = Math.round(priceCents * (1 - value / 100));
        }

        return result < 0 ? 0 : result;
    }

    // The next unlock still ahead of the shopper, or null when there is none.
    //
    // Only a tier boundary can change the price, so the candidate quantities are
    // exactly the min_qty values above the current one. Each candidate is priced
    // through resolveTier(), not read off the tier that named it — with
    // overlapping ranges the tier that WINS at that quantity may be a different
    // one, and quoting the loser would promise a discount the shopper will not
    // get. A candidate that does not actually beat today's price is skipped, so
    // a flat or worse tier is never advertised as an upgrade.
    //
    // Mirrors findNextUnlock() in bulk-order.js, minus the order-rule rounding
    // (this widget carries no order rules).
    function findNextUnlock(priceCents, qty, tiers) {
        if (!tiers || !tiers.length || priceCents <= 0) return null;

        var current = applyTierToPrice(priceCents, resolveTier(tiers, Math.max(1, qty)));

        var steps = [];
        for (var i = 0; i < tiers.length; i++) {
            var min = parseInt(tiers[i].min_qty, 10) || 0;
            if (min > qty) steps.push(min);
        }
        steps.sort(function(a, b) { return a - b; });

        for (var j = 0; j < steps.length; j++) {
            var tier = resolveTier(tiers, steps[j]);
            if (tier && applyTierToPrice(priceCents, tier) < current) {
                return { qty: steps[j], tier: tier };
            }
        }

        return null;
    }

    // Percent tiers can name their discount; the money types cannot without
    // mislabeling the unit, so those degrade to a generic promise.
    // Mirrors unlockText() in bulk-order.js.
    function unlockText(need, tier) {
        var type = (tier && tier.discount_type) || 'percent';
        var value = parseFloat(tier && tier.discount_value) || 0;

        if (type === 'percent' && value > 0) {
            // 10 not 10.00 — matching PHP fcbo_format_tier_discount_label().
            return fill(I18N.unlock_percent, { qty: need, percent: String(parseFloat(value.toFixed(2))) });
        }

        return fill(I18N.unlock_generic, { qty: need });
    }

    function recalcTable(table) {
        var rows = table.querySelectorAll('tbody tr[data-fcbo-variant]');
        var grandOriginal = 0;
        var grandDiscounted = 0;

        rows.forEach(function(row) {
            var data = variantData(row);
            var input = row.querySelector('.fcbo-bp-qty-input');
            var priceCell = row.querySelector('.fcbo-bp-price-cell');
            var qty = parseInt(input.value, 10) || 0;
            if (qty < 0) qty = 0;

            // Per-unit rounding then × qty, matching the cart filter (which prices
            // each unit) rather than rounding the line total once.
            var tier = resolveTier(data.tiers, qty);
            var effectiveUnit = applyTierToPrice(data.price, tier);
            var originalTotal = data.price * qty;
            var discountedTotal = effectiveUnit * qty;

            grandOriginal += originalTotal;
            grandDiscounted += discountedTotal;

            // The saving goes into the same innerHTML as the price it explains,
            // so the two are written in one assignment and cannot drift apart.
            // Reached only on the discounted branch, which is exactly when the
            // saving is above zero — no "You saved $0.00" is possible.
            var saving = originalTotal - discountedTotal;

            if (qty === 0) {
                priceCell.innerHTML = '<span class="fcbo-bp-muted">&mdash;</span>';
            } else if (discountedTotal < originalTotal) {
                priceCell.innerHTML = '<del class="fcbo-bp-original">' + formatPrice(originalTotal) + '</del> <span class="fcbo-bp-discount">' + formatPrice(discountedTotal) + '</span>' +
                    ' <span class="fcbo-bp-saving">' + escapeHtml(fill(I18N.saved, { amount: formatPrice(saving) })) + '</span>';
            } else {
                priceCell.innerHTML = formatPrice(originalTotal);
            }

            renderNudge(row, data.price, qty, data.tiers);
        });

        var totalCell = table.querySelector('.fcbo-bp-grand-total');
        if (totalCell) {
            if (grandOriginal === 0) {
                totalCell.innerHTML = '<span class="fcbo-bp-muted">&mdash;</span>';
            } else if (grandDiscounted < grandOriginal) {
                totalCell.innerHTML = '<del class="fcbo-bp-original">' + formatPrice(grandOriginal) + '</del> <span class="fcbo-bp-discount">' + formatPrice(grandDiscounted) + '</span>';
            } else {
                totalCell.innerHTML = formatPrice(grandOriginal);
            }
        }

        var grandSavingCell = table.querySelector('.fcbo-bp-grand-saving');
        if (grandSavingCell) {
            var grandSaving = grandOriginal - grandDiscounted;
            // textContent, not innerHTML: nothing here is markup, and the
            // template is translator-supplied.
            grandSavingCell.textContent = grandSaving > 0
                ? fill(I18N.saved, { amount: formatPrice(grandSaving) })
                : '';
        }
    }

    // Write (or clear) the "add N more" line under one row's quantity input.
    function renderNudge(row, priceCents, qty, tiers) {
        var el = row.querySelector('.fcbo-bp-nudge');
        if (!el) return;

        var unlock = findNextUnlock(priceCents, qty, tiers);

        el.textContent = unlock ? unlockText(unlock.qty - qty, unlock.tier) : '';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    document.addEventListener('input', function(e) {
        var input = e.target.closest('.fcbo-bp-qty-input');
        if (!input) return;
        var table = input.closest('.fcbo-bp-order-table');
        if (table) recalcTable(table);
    });

    // Correct a typed quantity once it is committed. 'change', not 'input', so a
    // half-typed "1" on the way to "12" is not snapped to the minimum
    // mid-keystroke — the same rule the product table follows.
    document.addEventListener('change', function(e) {
        var input = e.target.closest('.fcbo-bp-qty-input');
        if (!input) return;

        var row = input.closest('tr[data-fcbo-variant]');
        if (!row) return;

        var settled = normalizeQty(input.value, orderRules(variantData(row)));

        if (String(settled) !== String(input.value)) {
            input.value = settled;
        }

        var table = input.closest('.fcbo-bp-order-table');
        if (table) recalcTable(table);
    });

    // Every row's quantity, corrected in place, as [{id, qty}].
    //
    // Correcting the INPUT rather than quietly adding a different number is the
    // point: the server refuses an out-of-rule quantity outright
    // (includes/Cart/RuleEnforcement.php), so an uncorrected row would fail
    // mid-chain and leave a half-added order behind — and the shopper would
    // have no idea which line did it.
    function collectItems(table) {
        var items = [];
        var adjusted = false;

        table.querySelectorAll('tbody tr[data-fcbo-variant]').forEach(function(row) {
            var data = variantData(row);
            var input = row.querySelector('.fcbo-bp-qty-input');
            var qty = normalizeQty(input.value, orderRules(data));

            if (String(qty) !== String(input.value)) {
                input.value = qty;
                adjusted = true;
            }

            if (qty > 0) items.push({ id: data.id, qty: qty });
        });

        if (adjusted) recalcTable(table);

        return items;
    }

    // Add every line through FluentCart's own cart API, one after another.
    //
    // Sequential, not parallel: the cart endpoint returns the whole cart on each
    // call and the client overwrites its counters from that response, so two
    // in-flight adds race and the later reply can carry a cart that predates the
    // earlier add.
    //
    // `openCart` is the 4th argument of FluentCart's addProduct(item, qty,
    // byInput, openCart, isCustom) — it sets open_cart on the request, which
    // makes the drawer slide out. Only ever true on the LAST item, and never at
    // all when we are about to navigate away.
    function addAll(items, openCartAtEnd) {
        var chain = Promise.resolve();

        items.forEach(function(item, i) {
            chain = chain.then(function() {
                var openCart = openCartAtEnd && (i === items.length - 1);
                return window.fluentCartCart.addProduct(item.id, item.qty, false, openCart);
            });
        });

        return chain;
    }

    // Where "Bulk order now" goes.
    //
    // NOT FluentCart's own Buy Now route. That is
    // `?fluent-cart=instant_checkout&item_id=…&quantity=…`
    // (fluent-cart/app/Services/Renderer/ProductRenderer.php:1413), and it takes
    // a single item_id: WebRoutes.php:129 hands it to
    // CartResource::generateCartForInstantCheckout(), which builds a fresh
    // one-line cart. A four-variant bulk order sent through it would arrive as
    // one line. So we go to the destination that route itself redirects to
    // (WebRoutes.php:174 — the store's configured checkout page) after filling
    // the cart properly.
    //
    // AND WE PASS NO fct_cart_hash. Neither do bulk-order.js and
    // saved-orders.js any more — they did until issue #41, and both shipped
    // with an empty checkout because of it. All three surfaces now agree.
    // That parameter is Helper::INSTANT_CHECKOUT_URL_PARAM and it means
    // "instant checkout", not "here is my cart": when it is present,
    // CartResource::get() looks the hash up with
    // `->where('cart_group', 'instant')`
    // (fluent-cart/api/Resource/FrontendResource/CartResource.php:155-168) and
    // returns null for anything else. addProduct() builds a cart_group='global'
    // cart, so passing its hash finds NOTHING and checkout renders "Your cart
    // is empty" — verified on the local store with a real three-line cart.
    // Left off, FluentCart reads its own device cookie and finds it. The only
    // query argument this URL carries is the attribution marker PHP put on it.
    //
    // @return {boolean} whether the browser is actually leaving. PHP only
    //   renders the button when it has a URL to give, so false means the two
    //   sides disagreed — the caller re-enables the button rather than leaving
    //   a dead control on screen with the items already in the cart.
    function goToCheckout() {
        var url = (window.fcboBpConfig && window.fcboBpConfig.checkout_url) || '';
        if (!url) return false;

        window.location.href = url;

        return true;
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-fcbo-bp-action]');
        if (!btn || btn.classList.contains('fcbo-bp-loading')) return;
        if (!window.fluentCartCart) return;

        var wrap = btn.closest('.fcbo-bp-wrap');
        var table = wrap ? wrap.querySelector('.fcbo-bp-order-table') : null;
        if (!table) return;

        var items = collectItems(table);
        if (!items.length) return;

        // The two buttons add exactly the same lines; they differ only in where
        // the shopper ends up. Keeping that in one handler is what stops the two
        // paths drifting into adding different quantities.
        var toCheckout = btn.getAttribute('data-fcbo-bp-action') === 'checkout';

        btn.classList.add('fcbo-bp-loading');
        btn.disabled = true;

        // The cart drawer is for the shopper who is staying. Opening it a
        // moment before navigating away would only flash.
        addAll(items, !toCheckout).then(function() {
            // Deliberately left disabled once the browser is leaving: a button
            // that goes clickable again invites a second checkout trip that
            // would add every line twice.
            if (toCheckout && goToCheckout()) {
                return;
            }

            btn.classList.remove('fcbo-bp-loading');
            btn.disabled = false;
        }).catch(function() {
            btn.classList.remove('fcbo-bp-loading');
            btn.disabled = false;
        });
    });
})();
