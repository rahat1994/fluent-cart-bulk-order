(function () {
    'use strict';

    var CONFIG = window.fcboSoConfig || {};
    var tbody = null;
    var ORDERS = []; // last-fetched, resolved saved orders

    function init() {
        tbody = document.getElementById('fcbo-so-tbody');
        if (!tbody) return;
        loadOrders();
    }

    // --- Data ---

    function loadOrders() {
        tbody.innerHTML = '<tr><td colspan="5" class="fcbo-pt-loading">Loading saved orders...</td></tr>';

        // Saved orders and past orders share the same accordion; a failure in
        // either falls back to an empty list rather than breaking the page.
        Promise.all([
            fetchList('saved-lists', 'lists'),
            fetchList('past-orders', 'orders')
        ])
        .then(function (results) {
            ORDERS = results[0].concat(results[1]);
            renderOrders();
        })
        .catch(function () {
            tbody.innerHTML = '<tr><td colspan="5" class="fcbo-pt-loading">Failed to load saved orders.</td></tr>';
        });
    }

    function fetchList(path, key) {
        return fetch(CONFIG.rest_url + path, { headers: { 'X-WP-Nonce': CONFIG.nonce } })
            .then(function (res) { return res.json(); })
            .then(function (data) { return (data && data[key]) || []; })
            .catch(function () { return []; });
    }

    // --- Render ---

    function renderOrders() {
        if (!ORDERS.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="fcbo-pt-loading">You have no saved orders yet.</td></tr>';
            return;
        }

        var hasPast = ORDERS.some(function (o) { return o.source === 'past'; });
        var savedHeaderShown = false, pastHeaderShown = false;

        var html = '';
        for (var i = 0; i < ORDERS.length; i++) {
            var o = ORDERS[i];
            // Only label the groups when both kinds are present.
            if (o.source !== 'past' && hasPast && !savedHeaderShown) {
                html += dividerRow('Saved orders');
                savedHeaderShown = true;
            }
            if (o.source === 'past' && !pastHeaderShown) {
                html += dividerRow('Past orders');
                pastHeaderShown = true;
            }
            html += summaryRow(o, i);
            var items = o.items || [];
            for (var j = 0; j < items.length; j++) {
                html += itemRow(items[j], i);
            }
        }
        tbody.innerHTML = html;

        attachHandlers();
    }

    function dividerRow(label) {
        return '<tr class="fcbo-so-divider"><td colspan="5">' + escapeHtml(label) + '</td></tr>';
    }

    function summaryRow(order, index) {
        var count = order.item_count != null ? order.item_count : (order.items || []).length;
        var deleteBtn = order.deletable
            ? '<button type="button" class="fcbo-so-delete" data-order-name="' + escapeAttr(order.name) + '">Delete</button>'
            : '';
        return '<tr class="fcbo-pt-product-row fcbo-so-summary" data-order-index="' + index + '">' +
            '<td class="fcbo-so-col-name">' +
                '<button type="button" class="fcbo-pt-toggle" aria-expanded="false">' +
                    '<span class="fcbo-pt-caret">▸</span> ' + escapeHtml(order.name) +
                '</button>' +
            '</td>' +
            '<td class="fcbo-so-col-date">' + escapeHtml(order.created_at_formatted || '') + '</td>' +
            '<td class="fcbo-so-col-count">' + count + '</td>' +
            '<td class="fcbo-so-col-total">' + escapeHtml(formatPrice(order.subtotal || 0)) + '</td>' +
            '<td class="fcbo-so-col-actions">' +
                '<button type="button" class="fcbo-pt-add-btn fcbo-so-reorder" data-order-index="' + index + '">Reorder</button>' +
                deleteBtn +
            '</td>' +
        '</tr>';
    }

    function itemRow(item, parentIndex) {
        if (!item.available) {
            return '<tr class="fcbo-pt-variant-row fcbo-so-item fcbo-so-unavailable" data-parent-order="' + parentIndex + '">' +
                '<td class="fcbo-so-col-name"><span class="fcbo-pt-variant-name">Item no longer available</span></td>' +
                '<td class="fcbo-so-col-date">&mdash;</td>' +
                '<td class="fcbo-so-col-count">× ' + (parseInt(item.qty, 10) || 0) + '</td>' +
                '<td class="fcbo-so-col-total">&mdash;</td>' +
                '<td class="fcbo-so-col-actions">&mdash;</td>' +
            '</tr>';
        }

        var name = item.title || '';
        if (item.variation_title && item.variation_title !== 'Default') {
            name += ' — ' + item.variation_title;
        }
        var oos = item.stock_status === 'out-of-stock' || (item.manage_stock && item.available_qty <= 0);

        return '<tr class="fcbo-pt-variant-row fcbo-so-item' + (oos ? ' fcbo-so-oos' : '') + '" data-parent-order="' + parentIndex + '">' +
            '<td class="fcbo-so-col-name"><span class="fcbo-pt-variant-name">' + escapeHtml(name) +
                (oos ? ' <span class="fcbo-so-oos-tag">(out of stock)</span>' : '') + '</span></td>' +
            '<td class="fcbo-so-col-date fcbo-so-item-sku">' + escapeHtml(item.sku || '—') + '</td>' +
            '<td class="fcbo-so-col-count">× ' + (parseInt(item.qty, 10) || 0) + '</td>' +
            '<td class="fcbo-so-col-total">' + escapeHtml(formatPrice(item.line_total || 0)) + '</td>' +
            '<td class="fcbo-so-col-actions fcbo-so-unit">' + escapeHtml(formatPrice(item.item_price || 0)) + ' each</td>' +
        '</tr>';
    }

    // --- Handlers ---

    function attachHandlers() {
        var summaries = tbody.querySelectorAll('.fcbo-so-summary');
        for (var i = 0; i < summaries.length; i++) {
            summaries[i].addEventListener('click', handleToggle);
        }

        var reorderBtns = tbody.querySelectorAll('.fcbo-so-reorder');
        for (var r = 0; r < reorderBtns.length; r++) {
            reorderBtns[r].addEventListener('click', handleReorder);
        }

        var deleteBtns = tbody.querySelectorAll('.fcbo-so-delete');
        for (var d = 0; d < deleteBtns.length; d++) {
            deleteBtns[d].addEventListener('click', handleDelete);
        }
    }

    // Expand/collapse an order's line items (mirrors the product table accordion).
    function handleToggle(e) {
        // Ignore clicks on the action buttons inside the summary row.
        if (e.target.closest('.fcbo-so-col-actions')) return;

        var row = e.currentTarget;
        var index = row.getAttribute('data-order-index');
        var open = !row.classList.contains('is-open');
        row.classList.toggle('is-open', open);

        var caret = row.querySelector('.fcbo-pt-caret');
        if (caret) { caret.textContent = open ? '▾' : '▸'; }
        var toggle = row.querySelector('.fcbo-pt-toggle');
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }

        var itemRows = tbody.querySelectorAll('.fcbo-so-item[data-parent-order="' + index + '"]');
        for (var i = 0; i < itemRows.length; i++) {
            itemRows[i].classList.toggle('is-open', open);
        }
    }

    function handleReorder(e) {
        e.stopPropagation();
        var index = parseInt(e.currentTarget.getAttribute('data-order-index'), 10);
        var order = ORDERS[index];
        if (!order) return;

        var available = (order.items || []).filter(function (it) { return it.available; });
        var skipped = (order.items || []).length - available.length;

        if (!available.length) {
            showStatus('None of the items in this order are available anymore.', 'error');
            return;
        }

        var hasSubscription = false, hasOnetime = false;
        var items = [];
        for (var i = 0; i < available.length; i++) {
            var it = available[i];
            if (it.payment_type === 'subscription') {
                hasSubscription = true;
                items.push({ variantId: it.variantId, qty: 1 });
            } else {
                hasOnetime = true;
                items.push({ variantId: it.variantId, qty: parseInt(it.qty, 10) || 1 });
            }
        }

        if (hasSubscription && hasOnetime) {
            showStatus('This order mixes subscription and one-time products, which cannot be reordered together.', 'error');
            return;
        }

        if (!window.fluentCartCart || typeof window.fluentCartCart.addProduct !== 'function') {
            showStatus('FluentCart cart is not available. Please refresh the page and try again.', 'error');
            return;
        }

        var msg = skipped > 0 ? ('Adding ' + available.length + ' item(s); ' + skipped + ' unavailable skipped...') : 'Adding items to cart...';
        showStatus(msg, 'loading');
        e.currentTarget.disabled = true;

        addItemsSequentially(items, 0, e.currentTarget);
    }

    function addItemsSequentially(items, index, btn) {
        if (index >= items.length) {
            showStatus('Redirecting to checkout...', 'loading');
            setTimeout(function () {
                var checkoutUrl = CONFIG.checkout_url;
                if (!checkoutUrl) {
                    showStatus('Checkout page is not configured. Please check FluentCart settings.', 'error');
                    if (btn) btn.disabled = false;
                    return;
                }
                var cartHash = getCookie('fct_cart_hash');
                if (cartHash) {
                    var sep = checkoutUrl.indexOf('?') !== -1 ? '&' : '?';
                    checkoutUrl += sep + 'fct_cart_hash=' + encodeURIComponent(cartHash);
                }
                window.location.href = checkoutUrl;
            }, 500);
            return;
        }

        var item = items[index];
        try {
            var result = window.fluentCartCart.addProduct(item.variantId, item.qty, true);
            if (result && typeof result.then === 'function') {
                result.then(function () {
                    addItemsSequentially(items, index + 1, btn);
                }).catch(function (err) {
                    showStatus('Failed to add an item: ' + (err.message || 'Unknown error'), 'error');
                    if (btn) btn.disabled = false;
                });
            } else {
                setTimeout(function () { addItemsSequentially(items, index + 1, btn); }, 200);
            }
        } catch (err) {
            showStatus('Failed to add an item: ' + (err.message || 'Unknown error'), 'error');
            if (btn) btn.disabled = false;
        }
    }

    function handleDelete(e) {
        e.stopPropagation();
        var name = e.currentTarget.getAttribute('data-order-name');
        if (!name) return;
        if (!window.confirm('Delete saved order "' + name + '"?')) return;

        e.currentTarget.disabled = true;

        fetch(CONFIG.rest_url + 'saved-lists?name=' + encodeURIComponent(name), {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': CONFIG.nonce }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            ORDERS = data.lists || [];
            renderOrders();
            showStatus('Saved order deleted.', 'success');
        })
        .catch(function () {
            showStatus('Could not delete the saved order. Please try again.', 'error');
            e.currentTarget.disabled = false;
        });
    }

    // --- Helpers ---

    function formatPrice(cents) {
        var amount = ((parseInt(cents, 10) || 0) / 100).toFixed(2);
        return (CONFIG.currency_sign || '$') + amount;
    }

    function showStatus(msg, type) {
        var el = document.getElementById('fcbo-so-status');
        if (!el) return;
        el.textContent = msg;
        el.className = 'fcbo-pt-status fcbo-pt-status-' + type;
        el.style.display = 'block';
        if (type === 'success') {
            setTimeout(function () { el.style.display = 'none'; }, 4000);
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str == null ? '' : str));
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : '';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
