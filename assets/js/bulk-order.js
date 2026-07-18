(function () {
    'use strict';

    var CONFIG = window.fcboConfig || {};
    var tbody = null;
    var rowCounter = 0;

    function init() {
        tbody = document.getElementById('fcbo-tbody');
        if (!tbody) return;

        document.getElementById('fcbo-add-row').addEventListener('click', addRow);
        document.getElementById('fcbo-checkout').addEventListener('click', handleCheckout);

        initQuickOrder();
        addRow();
    }

    // --- Row Management ---

    function addRow() {
        rowCounter++;
        var rowId = 'fcbo-row-' + rowCounter;
        var tr = document.createElement('tr');
        tr.id = rowId;
        tr.dataset.variantId = '';
        tr.dataset.paymentType = '';
        tr.dataset.bulkTiers = '[]';
        tr.innerHTML =
            '<td class="fcbo-col-remove">' +
                '<button type="button" class="fcbo-remove-btn" title="Remove">&times;</button>' +
            '</td>' +
            '<td class="fcbo-col-product">' +
                '<div class="fcbo-search-wrap">' +
                    '<input type="text" class="fcbo-search-input" placeholder="Search products..." autocomplete="off" />' +
                    '<div class="fcbo-dropdown" style="display:none;"></div>' +
                '</div>' +
            '</td>' +
            '<td class="fcbo-col-sku"><span class="fcbo-sku-text"></span></td>' +
            '<td class="fcbo-col-categories"><span class="fcbo-cat-text"></span></td>' +
            '<td class="fcbo-col-image"><span class="fcbo-img-wrap"></span></td>' +
            '<td class="fcbo-col-amount"><span class="fcbo-amount-text"></span></td>' +
            '<td class="fcbo-col-qty">' +
                '<input type="number" class="fcbo-qty-input" value="1" min="1" step="1" disabled />' +
            '</td>' +
            '<td class="fcbo-col-total"><span class="fcbo-total-text"></span></td>';
        tbody.appendChild(tr);

        var removeBtn = tr.querySelector('.fcbo-remove-btn');
        var searchInput = tr.querySelector('.fcbo-search-input');
        var dropdown = tr.querySelector('.fcbo-dropdown');

        removeBtn.addEventListener('click', function () {
            tr.remove();
            updateGrandTotal();
        });

        var qtyInput = tr.querySelector('.fcbo-qty-input');
        qtyInput.addEventListener('input', function () {
            updateRowTotal(tr);
            updateGrandTotal();
        });

        var debounceTimer = null;
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var term = searchInput.value.trim();
            if (term.length < 2) {
                dropdown.style.display = 'none';
                return;
            }
            debounceTimer = setTimeout(function () {
                fetchProducts(term, dropdown, tr);
            }, 300);
        });

        // Close dropdown on outside click
        document.addEventListener('click', function (e) {
            if (!tr.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });

        return tr;
    }

    // --- Quick Order (paste / CSV) ---

    function initQuickOrder() {
        var toggle = document.getElementById('fcbo-quick-toggle');
        var panel = document.getElementById('fcbo-quick-panel');
        var fileInput = document.getElementById('fcbo-quick-file');
        var addBtn = document.getElementById('fcbo-quick-add');
        var textarea = document.getElementById('fcbo-quick-input');
        if (!toggle || !panel || !addBtn || !textarea) return;

        toggle.addEventListener('click', function () {
            var open = panel.hasAttribute('hidden');
            if (open) {
                panel.removeAttribute('hidden');
            } else {
                panel.setAttribute('hidden', '');
            }
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                var file = fileInput.files && fileInput.files[0];
                if (!file) return;
                var reader = new FileReader();
                reader.onload = function () {
                    textarea.value = String(reader.result || '');
                };
                reader.onerror = function () {
                    renderQuickReport([], 0, 'Could not read the file. Please try again.');
                };
                reader.readAsText(file);
            });
        }

        addBtn.addEventListener('click', function () {
            processQuickOrder(textarea.value || '');
        });
    }

    // Split a CSV-ish line on , ; or tab, respecting double-quoted fields.
    function splitFields(line) {
        var result = [];
        var cur = '';
        var inQuotes = false;
        for (var i = 0; i < line.length; i++) {
            var ch = line.charAt(i);
            if (inQuotes) {
                if (ch === '"') {
                    if (line.charAt(i + 1) === '"') { cur += '"'; i++; }
                    else { inQuotes = false; }
                } else {
                    cur += ch;
                }
            } else if (ch === '"') {
                inQuotes = true;
            } else if (ch === ',' || ch === ';' || ch === '\t') {
                result.push(cur);
                cur = '';
            } else {
                cur += ch;
            }
        }
        result.push(cur);
        return result;
    }

    function parseQuickOrderLines(text) {
        var lines = String(text).split(/\r\n|\r|\n/);
        var records = [];
        for (var i = 0; i < lines.length; i++) {
            var raw = lines[i];
            if (!raw || !raw.trim()) continue; // skip blank lines
            var fields = splitFields(raw);
            records.push({
                lineNo: i + 1,
                sku: (fields[0] || '').trim(),
                qtyRaw: (fields[1] || '').trim()
            });
        }
        return records;
    }

    // A valid quantity is a positive integer; anything else returns null.
    function parseQty(qtyRaw) {
        if (!/^\d+$/.test(qtyRaw)) return null;
        var n = parseInt(qtyRaw, 10);
        return n >= 1 ? n : null;
    }

    function processQuickOrder(text) {
        var records = parseQuickOrderLines(text);
        if (!records.length) {
            renderQuickReport([], 0, 'Nothing to import. Paste some "SKU, quantity" lines first.');
            return;
        }

        // Skip a header row: if the first of several lines has a non-numeric
        // quantity, treat it as a header. A lone bad line is kept and reported.
        if (records.length > 1 && records[0].sku && parseQty(records[0].qtyRaw) === null) {
            records.shift();
        }

        // Distinct SKUs (case-insensitive) for a single batched resolve.
        var seen = {};
        var skus = [];
        for (var i = 0; i < records.length; i++) {
            var sku = records[i].sku;
            if (!sku) continue;
            var key = sku.toLowerCase();
            if (seen[key]) continue;
            seen[key] = true;
            skus.push(sku);
        }

        if (!skus.length) {
            var missing = [];
            for (var m = 0; m < records.length; m++) {
                missing.push({ lineNo: records[m].lineNo, sku: '', status: 'invalid', detail: 'Missing SKU' });
            }
            renderQuickReport(missing, 0);
            return;
        }

        var addBtn = document.getElementById('fcbo-quick-add');
        if (addBtn) { addBtn.disabled = true; }

        fetch(CONFIG.rest_url + 'resolve-skus', {
            method: 'POST',
            headers: {
                'X-WP-Nonce': CONFIG.nonce,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ skus: skus })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            populateFromResolved(records, data.resolved || {});
        })
        .catch(function () {
            renderQuickReport([], 0, 'Could not resolve SKUs. Please try again.');
        })
        .then(function () {
            if (addBtn) { addBtn.disabled = false; }
        });
    }

    // Reuse the first empty row, else append a new one.
    function ensureRow() {
        var rows = tbody.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            if (!rows[i].dataset.variantId) {
                return rows[i];
            }
        }
        return addRow();
    }

    function populateFromResolved(records, resolved) {
        var report = [];
        var added = 0;

        for (var i = 0; i < records.length; i++) {
            var r = records[i];

            if (!r.sku) {
                report.push({ lineNo: r.lineNo, sku: '', status: 'invalid', detail: 'Missing SKU' });
                continue;
            }

            var entry = resolved[r.sku.toLowerCase()];

            if (!entry || entry.status === 'unknown') {
                report.push({ lineNo: r.lineNo, sku: r.sku, status: 'unknown', detail: 'No matching product' });
                continue;
            }

            if (entry.status === 'ambiguous') {
                var count = (entry.candidates || []).length;
                report.push({
                    lineNo: r.lineNo, sku: r.sku, status: 'ambiguous',
                    detail: 'Matches ' + count + ' variants — add it manually'
                });
                continue;
            }

            var qty = parseQty(r.qtyRaw);
            if (qty === null) {
                report.push({ lineNo: r.lineNo, sku: r.sku, status: 'invalid', detail: 'Invalid quantity "' + r.qtyRaw + '"' });
                continue;
            }

            var data = entry.product;
            var v = data.variant;
            var row = ensureRow();
            selectProduct(row, data);

            // selectProduct locks subscription rows to qty 1; only override otherwise.
            if (v.payment_type !== 'subscription') {
                var qtyInput = row.querySelector('.fcbo-qty-input');
                qtyInput.value = qty;
                updateRowTotal(row);
            }

            added++;
            var label = data.title;
            if (v.variation_title && v.variation_title !== 'Default') {
                label += ' — ' + v.variation_title;
            }
            var shownQty = v.payment_type === 'subscription' ? 1 : qty;
            report.push({ lineNo: r.lineNo, sku: r.sku, status: 'matched', detail: label + ' × ' + shownQty });
        }

        updateGrandTotal();
        renderQuickReport(report, added);
    }

    function renderQuickReport(report, added, errorMsg) {
        var el = document.getElementById('fcbo-quick-report');
        if (!el) return;

        if (errorMsg) {
            el.innerHTML = '<div class="fcbo-quick-report-summary">' + escapeHtml(errorMsg) + '</div>';
            el.style.display = 'block';
            return;
        }

        var skipped = 0;
        for (var s = 0; s < report.length; s++) {
            if (report[s].status !== 'matched') skipped++;
        }

        var summary = added + (added === 1 ? ' item added' : ' items added');
        if (skipped) { summary += ', ' + skipped + ' skipped'; }

        var html = '<div class="fcbo-quick-report-summary">' + escapeHtml(summary) + '</div>';
        html += '<ul class="fcbo-quick-report-list">';
        for (var i = 0; i < report.length; i++) {
            var r = report[i];
            html += '<li class="fcbo-quick-report-' + r.status + '">' +
                '<span class="fcbo-quick-line">' + escapeHtml('Line ' + r.lineNo) + '</span>' +
                '<span>' + escapeHtml((r.sku ? r.sku + ' — ' : '') + r.detail) + '</span>' +
            '</li>';
        }
        html += '</ul>';

        el.innerHTML = html;
        el.style.display = 'block';
    }

    // --- Product Search ---

    function fetchProducts(term, dropdown, row) {
        var url = CONFIG.rest_url + 'products?search=' + encodeURIComponent(term);
        fetch(url, {
            headers: { 'X-WP-Nonce': CONFIG.nonce }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            renderDropdown(data.products || [], dropdown, row);
        })
        .catch(function () {
            dropdown.innerHTML = '<div class="fcbo-dd-empty">Search failed</div>';
            dropdown.style.display = 'block';
        });
    }

    function renderDropdown(products, dropdown, row) {
        if (!products.length) {
            dropdown.innerHTML = '<div class="fcbo-dd-empty">No products found</div>';
            dropdown.style.display = 'block';
            return;
        }

        var html = '';
        for (var i = 0; i < products.length; i++) {
            var p = products[i];
            if (!p.variants || !p.variants.length) continue;

            for (var j = 0; j < p.variants.length; j++) {
                var v = p.variants[j];
                var outOfStock = v.stock_status === 'out-of-stock' || (v.manage_stock && v.available <= 0);
                var label = p.title;
                if (v.variation_title && v.variation_title !== 'Default') {
                    label += ' — ' + v.variation_title;
                }
                var price = formatPrice(v.item_price);
                var skuLabel = v.sku ? '  ·  SKU ' + v.sku : '';
                var stockLabel = outOfStock ? ' (Out of stock)' : '';

                html += '<div class="fcbo-dd-item' + (outOfStock ? ' fcbo-dd-disabled' : '') + '"' +
                    ' data-product=\'' + escapeAttr(JSON.stringify({
                        productId: p.id,
                        title: p.title,
                        thumbnail: v.thumbnail || p.thumbnail,
                        categories: p.categories,
                        variant: v
                    })) + '\'>' +
                    '<span class="fcbo-dd-title">' + escapeHtml(label) + '</span>' +
                    '<span class="fcbo-dd-meta">' + escapeHtml(price + skuLabel + stockLabel) + '</span>' +
                '</div>';
            }
        }

        if (!html) {
            dropdown.innerHTML = '<div class="fcbo-dd-empty">No available variants</div>';
        } else {
            dropdown.innerHTML = html;
        }
        dropdown.style.display = 'block';

        // Attach click handlers
        var items = dropdown.querySelectorAll('.fcbo-dd-item:not(.fcbo-dd-disabled)');
        for (var k = 0; k < items.length; k++) {
            items[k].addEventListener('click', function () {
                var data = JSON.parse(this.dataset.product);
                selectProduct(row, data);
                dropdown.style.display = 'none';
            });
        }
    }

    // --- Row Population ---

    function selectProduct(row, data) {
        var v = data.variant;

        // Set search input to product name
        var searchInput = row.querySelector('.fcbo-search-input');
        var label = data.title;
        if (v.variation_title && v.variation_title !== 'Default') {
            label += ' — ' + v.variation_title;
        }
        searchInput.value = label;

        // Store variant data on the row
        row.dataset.variantId = v.id;
        row.dataset.paymentType = v.payment_type;
        row.dataset.unitPrice = v.item_price;
        row.dataset.bulkTiers = JSON.stringify(v.bulk_tiers || []);

        // SKU
        row.querySelector('.fcbo-sku-text').textContent = v.sku || '—';

        // Categories
        var catNames = (data.categories || []).map(function (c) { return c.name; });
        row.querySelector('.fcbo-cat-text').textContent = catNames.join(', ') || '—';

        // Image
        var imgWrap = row.querySelector('.fcbo-img-wrap');
        if (data.thumbnail) {
            imgWrap.innerHTML = '<img src="' + escapeAttr(data.thumbnail) + '" alt="" class="fcbo-thumb" />';
        } else {
            imgWrap.innerHTML = '—';
        }

        // Qty
        var qtyInput = row.querySelector('.fcbo-qty-input');
        qtyInput.disabled = false;

        if (v.payment_type === 'subscription') {
            qtyInput.value = 1;
            qtyInput.disabled = true;
        } else {
            qtyInput.value = 1;
        }

        updateRowTotal(row);
        updateGrandTotal();
    }

    // --- Checkout ---

    function handleCheckout() {
        var rows = tbody.querySelectorAll('tr');
        var items = [];
        var hasSubscription = false;
        var hasOnetime = false;

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var variantId = row.dataset.variantId;
            if (!variantId) continue;

            var qty = parseInt(row.querySelector('.fcbo-qty-input').value, 10) || 1;
            var paymentType = row.dataset.paymentType;

            if (paymentType === 'subscription') {
                hasSubscription = true;
                qty = 1;
            } else {
                hasOnetime = true;
            }

            items.push({ variantId: variantId, qty: qty });
        }

        if (!items.length) {
            showStatus('Please select at least one product.', 'error');
            return;
        }

        if (hasSubscription && hasOnetime) {
            showStatus('Cannot mix subscription and one-time products in the same order. Please remove one type before proceeding.', 'error');
            return;
        }

        // Consolidate duplicate variants
        var consolidated = {};
        for (var j = 0; j < items.length; j++) {
            var key = items[j].variantId;
            if (consolidated[key]) {
                if (!hasSubscription) {
                    consolidated[key].qty += items[j].qty;
                }
            } else {
                consolidated[key] = { variantId: key, qty: items[j].qty };
            }
        }

        var finalItems = Object.values(consolidated);

        if (!window.fluentCartCart || typeof window.fluentCartCart.addProduct !== 'function') {
            showStatus('FluentCart cart is not available. Please refresh the page and try again.', 'error');
            return;
        }

        showStatus('Adding items to cart...', 'loading');
        disableCheckout(true);

        addItemsSequentially(finalItems, 0);
    }

    function addItemsSequentially(items, index) {
        if (index >= items.length) {
            showStatus('Redirecting to checkout...', 'loading');
            // Small delay to ensure cart cookie is fully set before redirect
            setTimeout(function () {
                var checkoutUrl = CONFIG.checkout_url;
                if (!checkoutUrl) {
                    showStatus('Checkout page is not configured. Please check FluentCart settings.', 'error');
                    disableCheckout(false);
                    return;
                }
                // Append cart hash from cookie if available
                var cartHash = getCookie('fct_cart_hash');
                if (cartHash) {
                    var separator = checkoutUrl.indexOf('?') !== -1 ? '&' : '?';
                    checkoutUrl += separator + 'fct_cart_hash=' + encodeURIComponent(cartHash);
                }
                window.location.href = checkoutUrl;
            }, 500);
            return;
        }

        var item = items[index];
        showStatus('Adding item ' + (index + 1) + ' of ' + items.length + '...', 'loading');

        try {
            var result = window.fluentCartCart.addProduct(item.variantId, item.qty, true);

            // Handle both promise and non-promise returns
            if (result && typeof result.then === 'function') {
                result.then(function () {
                    addItemsSequentially(items, index + 1);
                }).catch(function (err) {
                    showStatus('Failed to add item: ' + (err.message || 'Unknown error'), 'error');
                    disableCheckout(false);
                });
            } else {
                // If not a promise, proceed after a short delay to let state settle
                setTimeout(function () {
                    addItemsSequentially(items, index + 1);
                }, 200);
            }
        } catch (err) {
            showStatus('Failed to add item: ' + (err.message || 'Unknown error'), 'error');
            disableCheckout(false);
        }
    }

    // --- Bulk Pricing ---

    function getEffectivePrice(unitPriceCents, qty, tiers) {
        if (!tiers || !tiers.length || qty < 1) {
            return unitPriceCents;
        }

        for (var i = 0; i < tiers.length; i++) {
            var tier = tiers[i];
            var minQty = tier.min_qty || 0;
            var maxQty = tier.max_qty || 0;
            var discountValue = tier.discount_value || 0;

            if (qty >= minQty && (maxQty === 0 || qty <= maxQty)) {
                return Math.round(unitPriceCents * (1 - discountValue / 100));
            }
        }

        return unitPriceCents;
    }

    function parseTiers(row) {
        try {
            return JSON.parse(row.dataset.bulkTiers || '[]');
        } catch (e) {
            return [];
        }
    }

    // --- Totals ---

    function updateRowTotal(row) {
        var unitPrice = parseInt(row.dataset.unitPrice, 10) || 0;
        var qty = parseInt(row.querySelector('.fcbo-qty-input').value, 10) || 0;

        if (!unitPrice) {
            row.querySelector('.fcbo-amount-text').innerHTML = '';
            row.querySelector('.fcbo-total-text').textContent = '';
            return;
        }

        var tiers = parseTiers(row);
        var effectivePrice = getEffectivePrice(unitPrice, qty, tiers);
        var amountEl = row.querySelector('.fcbo-amount-text');

        if (effectivePrice < unitPrice) {
            amountEl.innerHTML =
                '<span class="fcbo-price-original">' + escapeHtml(formatPrice(unitPrice)) + '</span> ' +
                '<span class="fcbo-price-discounted">' + escapeHtml(formatPrice(effectivePrice)) + '</span>';
        } else {
            amountEl.textContent = formatPrice(unitPrice);
        }

        var total = effectivePrice * qty;
        row.querySelector('.fcbo-total-text').textContent = formatPrice(total);
    }

    function updateGrandTotal() {
        var rows = tbody.querySelectorAll('tr');
        var grandTotal = 0;
        for (var i = 0; i < rows.length; i++) {
            var unitPrice = parseInt(rows[i].dataset.unitPrice, 10) || 0;
            var qty = parseInt(rows[i].querySelector('.fcbo-qty-input').value, 10) || 0;
            var tiers = parseTiers(rows[i]);
            var effectivePrice = getEffectivePrice(unitPrice, qty, tiers);
            grandTotal += effectivePrice * qty;
        }
        document.getElementById('fcbo-grand-total').textContent = formatPrice(grandTotal);
    }

    // --- Helpers ---

    function formatPrice(cents) {
        var amount = (cents / 100).toFixed(2);
        return (CONFIG.currency_sign || '$') + amount;
    }

    function showStatus(msg, type) {
        var el = document.getElementById('fcbo-status');
        el.textContent = msg;
        el.className = 'fcbo-status fcbo-status-' + type;
        el.style.display = 'block';
    }

    function disableCheckout(disabled) {
        var btn = document.getElementById('fcbo-checkout');
        btn.disabled = disabled;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : '';
    }

    // --- Init ---
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
