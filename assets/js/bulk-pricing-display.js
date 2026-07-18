(function() {
    var currencySign = (window.fcboBpConfig && window.fcboBpConfig.currency_sign) || '$';

    function formatPrice(cents) {
        return currencySign + (cents / 100).toFixed(2);
    }

    function resolveTier(tiers, qty) {
        for (var i = 0; i < tiers.length; i++) {
            var t = tiers[i];
            var min = parseInt(t.min_qty, 10) || 0;
            var max = parseInt(t.max_qty, 10) || 0;
            if (qty >= min && (max === 0 || qty <= max)) {
                return t;
            }
        }
        return null;
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

    function recalcTable(table) {
        var rows = table.querySelectorAll('tbody tr[data-fcbo-variant]');
        var grandOriginal = 0;
        var grandDiscounted = 0;

        rows.forEach(function(row) {
            var data = JSON.parse(row.getAttribute('data-fcbo-variant'));
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

            if (qty === 0) {
                priceCell.innerHTML = '<span class="fcbo-bp-muted">&mdash;</span>';
            } else if (discountedTotal < originalTotal) {
                priceCell.innerHTML = '<del class="fcbo-bp-original">' + formatPrice(originalTotal) + '</del> <span class="fcbo-bp-discount">' + formatPrice(discountedTotal) + '</span>';
            } else {
                priceCell.innerHTML = formatPrice(originalTotal);
            }
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
    }

    document.addEventListener('input', function(e) {
        var input = e.target.closest('.fcbo-bp-qty-input');
        if (!input) return;
        var table = input.closest('.fcbo-bp-order-table');
        if (table) recalcTable(table);
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.fcbo-bp-checkout-btn');
        if (!btn || btn.classList.contains('fcbo-bp-loading')) return;
        if (!window.fluentCartCart) return;

        var wrap = btn.closest('.fcbo-bp-wrap');
        var table = wrap ? wrap.querySelector('.fcbo-bp-order-table') : null;
        if (!table) return;
        var rows = table.querySelectorAll('tbody tr[data-fcbo-variant]');
        var items = [];
        rows.forEach(function(row) {
            var data = JSON.parse(row.getAttribute('data-fcbo-variant'));
            var qty = parseInt(row.querySelector('.fcbo-bp-qty-input').value, 10) || 0;
            if (qty > 0) items.push({ id: data.id, qty: qty });
        });

        if (!items.length) return;

        btn.classList.add('fcbo-bp-loading');
        btn.disabled = true;

        var chain = Promise.resolve();
        items.forEach(function(item, i) {
            chain = chain.then(function() {
                var openCart = (i === items.length - 1);
                return window.fluentCartCart.addProduct(item.id, item.qty, false, openCart);
            });
        });

        chain.then(function() {
            btn.classList.remove('fcbo-bp-loading');
            btn.disabled = false;
        }).catch(function() {
            btn.classList.remove('fcbo-bp-loading');
            btn.disabled = false;
        });
    });
})();
