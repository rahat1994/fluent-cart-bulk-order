(function () {
    'use strict';

    var CONFIG = window.fcboConfig || {};
    var I18N = CONFIG.i18n || {};
    var tbody = null;
    var rowCounter = 0;

    function init() {
        tbody = document.getElementById('fcbo-tbody');
        if (!tbody) return;

        document.getElementById('fcbo-add-row').addEventListener('click', addRow);
        document.getElementById('fcbo-checkout').addEventListener('click', handleCheckout);

        var saveBtn = document.getElementById('fcbo-save-order');
        if (saveBtn) { saveBtn.addEventListener('click', handleSaveOrder); }

        // ONE listener for every row's dropdown, attached once. addRow() used
        // to attach its own `document` click handler per row, so a 50-line
        // order left 50 listeners behind — each holding a closure over a row
        // that may already have been removed, and each running on every click
        // anywhere on the page.
        document.addEventListener('click', closeDropdownsOutsideClickedRow);

        initQuickOrder();
        addRow();
    }

    // Hide every search dropdown except the one in the row that was clicked.
    // Matches what the old per-row listeners did: a click anywhere inside a row
    // leaves that row's dropdown alone.
    function closeDropdownsOutsideClickedRow(e) {
        var clickedRow = e.target && e.target.closest ? e.target.closest('tr') : null;
        var dropdowns = tbody.querySelectorAll('.fcbo-dropdown');

        for (var i = 0; i < dropdowns.length; i++) {
            if (dropdowns[i].closest('tr') !== clickedRow) {
                dropdowns[i].style.display = 'none';
            }
        }
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
                // Filled by updateRowTotal(): the nudge sits under the input the
                // shopper types in, the saving under the total it changes.
                '<span class="fcbo-nudge"></span>' +
            '</td>' +
            '<td class="fcbo-col-total">' +
                '<span class="fcbo-total-text"></span>' +
                '<span class="fcbo-saving"></span>' +
            '</td>';
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

        // Normalize on 'change' rather than 'input': 'change' fires when the
        // value is committed (blur, Enter, spinner), so a half-typed "1" on the
        // way to "12" is not snapped out from under the shopper mid-keystroke.
        qtyInput.addEventListener('change', function () {
            normalizeRowQty(tr, true);
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

        return tr;
    }

    // The first row no product has been chosen in, or null when every row is
    // filled. A row counts as free purely by its missing variantId, which is
    // what selectProduct() sets — so a half-typed search term does not make a
    // row look taken.
    function firstEmptyRow() {
        var rows = tbody.querySelectorAll('tr');

        for (var i = 0; i < rows.length; i++) {
            if (!rows[i].dataset.variantId) {
                return rows[i];
            }
        }

        return null;
    }

    // Keep one free row waiting below the last filled one, so picking a product
    // never costs a trip to "+ Add Row".
    //
    // Appends only when nothing is free, which is what keeps the CSV import
    // sane: each imported line fills the row the previous line left behind, so
    // a 50-line paste still ends with ONE trailing row rather than fifty.
    function ensureTrailingRow() {
        if (!firstEmptyRow()) {
            addRow();
        }
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
        return firstEmptyRow() || addRow();
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
            var settledQty = qty;
            if (v.payment_type !== 'subscription') {
                var qtyInput = row.querySelector('.fcbo-qty-input');
                qtyInput.value = qty;
                // Imported rows go through the same normalization as typed ones
                // (R6). Silent here — the per-line report below carries the news.
                settledQty = normalizeRowQty(row, false);
                updateRowTotal(row);
            }

            added++;
            var label = data.title;
            if (v.variation_title && v.variation_title !== 'Default') {
                label += ' — ' + v.variation_title;
            }
            var shownQty = v.payment_type === 'subscription' ? 1 : settledQty;
            var detail = label + ' × ' + shownQty;
            if (settledQty !== qty && v.payment_type !== 'subscription') {
                detail += ' (adjusted from ' + qty + ' to meet order rules)';
            }
            report.push({ lineNo: r.lineNo, sku: r.sku, status: 'matched', detail: detail });
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
        row.dataset.orderRules = JSON.stringify(v.order_rules || {});

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

        // Qty — the resolved rule drives the spinner's own min/step, so the
        // browser's arrows already move in valid increments.
        var qtyInput = row.querySelector('.fcbo-qty-input');
        var rules = orderRulesFor(row);
        qtyInput.disabled = false;

        if (v.payment_type === 'subscription') {
            // Subscriptions are always a single unit, so order rules do not apply.
            qtyInput.min = 1;
            qtyInput.step = 1;
            qtyInput.value = 1;
            qtyInput.disabled = true;
        } else {
            qtyInput.min = Math.max(1, rules.min_qty || 0);
            qtyInput.step = rules.step;
            qtyInput.value = normalizeQty(1, rules);
        }

        updateRowTotal(row);
        updateGrandTotal();

        // Last, so the new row is appended below a row that is already fully
        // rendered. Both entry paths land here — the search dropdown and the
        // CSV import — so neither needs its own copy of this rule.
        ensureTrailingRow();
    }

    // --- Save order ---

    function handleSaveOrder() {
        // Store rule-valid quantities so a later reorder is not rejected by the
        // server gate for a violation introduced at save time. Silent: saving is
        // not the moment to interrupt with a rounding notice.
        normalizeAllRows(false);

        var rows = tbody.querySelectorAll('tr');

        // Collect non-empty rows, consolidating duplicate variants (as checkout does).
        var consolidated = {};
        var order = [];
        for (var i = 0; i < rows.length; i++) {
            var variantId = rows[i].dataset.variantId;
            if (!variantId) continue;
            var qty = parseInt(rows[i].querySelector('.fcbo-qty-input').value, 10) || 1;
            if (consolidated[variantId]) {
                consolidated[variantId].qty += qty;
            } else {
                consolidated[variantId] = { variantId: parseInt(variantId, 10), qty: qty };
                order.push(variantId);
            }
        }

        var items = order.map(function (k) { return consolidated[k]; });

        if (!items.length) {
            showStatus('Add at least one product before saving.', 'error');
            return;
        }

        var name = window.prompt('Name this saved order:');
        if (name === null) return; // cancelled
        name = name.trim();
        if (!name) {
            showStatus('Please enter a name for the saved order.', 'error');
            return;
        }

        var btn = document.getElementById('fcbo-save-order');
        if (btn) { btn.disabled = true; }
        showStatus('Saving order...', 'loading');

        fetch(CONFIG.rest_url + 'saved-lists', {
            method: 'POST',
            headers: { 'X-WP-Nonce': CONFIG.nonce, 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name, items: items })
        })
        .then(function (res) {
            return res.json().then(function (body) { return { ok: res.ok, body: body }; });
        })
        .then(function (r) {
            if (!r.ok) {
                showStatus((r.body && r.body.message) ? r.body.message : 'Could not save the order.', 'error');
            } else {
                showStatus('Saved order "' + name + '".', 'success');
            }
        })
        .catch(function () {
            showStatus('Could not save the order. Please try again.', 'error');
        })
        .then(function () {
            if (btn) { btn.disabled = false; }
        });
    }

    // --- Checkout ---

    function handleCheckout() {
        // Bring every row into rule first — including rows populated by CSV
        // import that the shopper never focused (R6). If anything moved, stop
        // and let them see the corrected totals before committing.
        if (normalizeAllRows(true)) {
            return;
        }

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

        // Order-total floor. CONFIG.min_order_total is already 0 for shoppers the
        // policy does not cover, so no role logic is needed here. The server
        // re-checks this at checkout — this is only to save a wasted round trip.
        var minOrderTotal = parseInt(CONFIG.min_order_total, 10) || 0;
        if (minOrderTotal > 0) {
            var grandTotal = computeGrandTotal();
            if (grandTotal < minOrderTotal) {
                showStatus(
                    'Add ' + formatPrice(minOrderTotal - grandTotal) + ' more to reach the ' +
                    formatPrice(minOrderTotal) + ' minimum order total.',
                    'error'
                );
                return;
            }
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

    // Mirrors PHP fcbo_apply_tier_to_price(): the ONE effective-price formula for
    // this surface. Money tier values are stored in major units, so they convert to
    // cents here. Keep in lock-step with the PHP helper and bulk-pricing-display.js.
    function applyTierToPrice(priceCents, tier) {
        var type = (tier && tier.discount_type) || 'percent';
        var value = parseFloat(tier && tier.discount_value) || 0;
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

    // Mirrors PHP fcbo_match_tier(): several tiers can match one quantity (an
    // open-ended "30+" still matches at 70 when a "60+" exists), so the most
    // specific match wins — the highest min_qty, not the first one found.
    function matchTier(tiers, qty) {
        var best = null;
        var bestMin = -1;

        for (var i = 0; i < tiers.length; i++) {
            var tier = tiers[i];
            var minQty = tier.min_qty || 0;
            var maxQty = tier.max_qty || 0;

            if (qty < minQty || (maxQty > 0 && qty > maxQty)) {
                continue;
            }

            if (minQty >= bestMin) {
                best = tier;
                bestMin = minQty;
            }
        }

        return best;
    }

    function getEffectivePrice(unitPriceCents, qty, tiers) {
        if (!tiers || !tiers.length || qty < 1) {
            return unitPriceCents;
        }

        var best = matchTier(tiers, qty);

        return best ? applyTierToPrice(unitPriceCents, best) : unitPriceCents;
    }

    // The next unlock still ahead of the shopper, or null when there is none.
    //
    // Only a tier boundary can change the price, so the candidate quantities are
    // exactly the min_qty values above the current one. Two rules keep the
    // promise honest:
    //
    //   1. Each candidate is priced through matchTier(), not read off the tier
    //      that named it. With overlapping ranges the tier that WINS at that
    //      quantity may be a different one, and quoting the loser would promise
    //      a discount the shopper will not get.
    //   2. A candidate that does not actually beat today's price is skipped, so
    //      a flat or worse tier is never advertised as an upgrade.
    //
    // Order rules are applied to the target quantity first: with a case-pack of
    // 12, "add 5 more" would name a quantity the shopper is not allowed to
    // order. Rounding up can overshoot a tier's max_qty, which is why the tier
    // is re-resolved at the rounded quantity rather than the raw boundary.
    //
    // Mirrors findNextUnlock() in bulk-pricing-display.js.
    function findNextUnlock(unitPriceCents, qty, tiers, rules) {
        if (!tiers || !tiers.length || unitPriceCents <= 0) {
            return null;
        }

        var current = getEffectivePrice(unitPriceCents, Math.max(1, qty), tiers);

        var steps = [];
        for (var i = 0; i < tiers.length; i++) {
            var minQty = parseInt(tiers[i].min_qty, 10) || 0;
            if (minQty > qty) {
                steps.push(minQty);
            }
        }
        steps.sort(function (a, b) { return a - b; });

        for (var j = 0; j < steps.length; j++) {
            var target = rules ? normalizeQty(steps[j], rules) : steps[j];
            var tier = matchTier(tiers, target);

            if (target > qty && tier && applyTierToPrice(unitPriceCents, tier) < current) {
                return { qty: target, tier: tier };
            }
        }

        return null;
    }

    // Percent tiers can name their discount; the money types cannot without
    // mislabeling the unit, so those degrade to a generic promise.
    // Mirrors unlockText() in bulk-pricing-display.js.
    function unlockText(need, tier) {
        var type = (tier && tier.discount_type) || 'percent';
        var value = parseFloat(tier && tier.discount_value) || 0;

        if (type === 'percent' && value > 0) {
            // 10 not 10.00 — matching PHP fcbo_format_tier_discount_label().
            return fill(I18N.unlock_percent, { qty: need, percent: String(parseFloat(value.toFixed(2))) });
        }

        return fill(I18N.unlock_generic, { qty: need });
    }

    function parseTiers(row) {
        try {
            return JSON.parse(row.dataset.bulkTiers || '[]');
        } catch (e) {
            return [];
        }
    }

    // --- Order rules ---

    // Mirrors PHP fcbo_normalize_order_rules(): coerce whatever the row carries
    // into a usable pair, defaulting to the no-op rule.
    function orderRulesFor(row) {
        var raw = {};
        try {
            raw = JSON.parse(row.dataset.orderRules || '{}') || {};
        } catch (e) {
            raw = {};
        }
        return {
            min_qty: Math.max(0, parseInt(raw.min_qty, 10) || 0),
            step: Math.max(1, parseInt(raw.step, 10) || 1)
        };
    }

    // Mirrors PHP fcbo_normalize_qty(). Rounds UP only — a shopper is never
    // silently given less than they asked for. Keep in lock-step with the PHP
    // helper; the server rejects anything this function would have changed.
    function normalizeQty(qty, rules) {
        qty = Math.max(1, parseInt(qty, 10) || 1, rules.min_qty);
        if (rules.step > 1) {
            qty = Math.ceil(qty / rules.step) * rules.step;
        }
        return qty;
    }

    // Correct a row's quantity in place, announcing the change only when one
    // actually happened. Returns the settled quantity.
    function normalizeRowQty(row, announce) {
        var input = row.querySelector('.fcbo-qty-input');
        if (!input || input.disabled || !row.dataset.variantId) {
            return parseInt(input && input.value, 10) || 1;
        }

        var rules = orderRulesFor(row);
        var entered = parseInt(input.value, 10) || 0;
        var settled = normalizeQty(entered, rules);

        if (settled !== entered) {
            input.value = settled;
            if (announce) {
                showStatus(describeQtyAdjustment(entered, settled, rules), 'error');
            }
        }

        return settled;
    }

    function describeQtyAdjustment(entered, settled, rules) {
        if (rules.step > 1 && rules.min_qty > 0 && entered < rules.min_qty) {
            return 'Minimum order is ' + rules.min_qty + ', in multiples of ' +
                rules.step + '. Quantity set to ' + settled + '.';
        }
        if (rules.step > 1) {
            return 'Sold in multiples of ' + rules.step + '. Quantity rounded up to ' + settled + '.';
        }
        return 'Minimum order quantity is ' + rules.min_qty + '. Quantity set to ' + settled + '.';
    }

    // Normalize every populated row — used before checkout and after a bulk
    // import, so rows the user never touched are still brought into rule (R6).
    function normalizeAllRows(announce) {
        var rows = tbody.querySelectorAll('tr');
        var changed = false;
        for (var i = 0; i < rows.length; i++) {
            var input = rows[i].querySelector('.fcbo-qty-input');
            var before = parseInt(input && input.value, 10) || 0;
            if (normalizeRowQty(rows[i], false) !== before) {
                changed = true;
            }
        }
        if (changed) {
            updateGrandTotal();
            if (announce) {
                showStatus('Some quantities were adjusted to meet this store\'s order rules.', 'error');
            }
        }
        return changed;
    }

    // --- Totals ---

    function updateRowTotal(row) {
        var unitPrice = parseInt(row.dataset.unitPrice, 10) || 0;
        var qty = parseInt(row.querySelector('.fcbo-qty-input').value, 10) || 0;

        if (!unitPrice) {
            row.querySelector('.fcbo-amount-text').innerHTML = '';
            row.querySelector('.fcbo-total-text').textContent = '';
            // An emptied row must drop its messaging too, or a stale saving from
            // the previous product survives underneath a blank total.
            setText(row, '.fcbo-saving', '');
            setText(row, '.fcbo-nudge', '');
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

        // Nothing saved renders nothing — never "You saved $0.00".
        var saving = (unitPrice - effectivePrice) * qty;
        setText(row, '.fcbo-saving', saving > 0 ? fill(I18N.saved, { amount: formatPrice(saving) }) : '');

        var unlock = findNextUnlock(unitPrice, qty, tiers, orderRulesFor(row));
        setText(row, '.fcbo-nudge', unlock ? unlockText(unlock.qty - qty, unlock.tier) : '');
    }

    // What the shopper pays and what the tiers took off, both in cents, from one
    // pass over the rows. Split out from updateGrandTotal() so the checkout gate
    // can compare against the same number the shopper sees. That basis matches
    // the server's Cart::getItemsSubtotal() — bulk-discounted line prices,
    // before coupons, shipping, and tax — so the two gates agree.
    function computeTotals() {
        var rows = tbody.querySelectorAll('tr');
        var total = 0;
        var saving = 0;

        for (var i = 0; i < rows.length; i++) {
            var unitPrice = parseInt(rows[i].dataset.unitPrice, 10) || 0;
            var qty = parseInt(rows[i].querySelector('.fcbo-qty-input').value, 10) || 0;
            var tiers = parseTiers(rows[i]);
            var effectivePrice = getEffectivePrice(unitPrice, qty, tiers);

            total += effectivePrice * qty;
            saving += (unitPrice - effectivePrice) * qty;
        }

        return { total: total, saving: saving };
    }

    function computeGrandTotal() {
        return computeTotals().total;
    }

    function updateGrandTotal() {
        var totals = computeTotals();

        document.getElementById('fcbo-grand-total').textContent = formatPrice(totals.total);

        var savingEl = document.getElementById('fcbo-grand-saving');
        if (savingEl) {
            savingEl.textContent = totals.saving > 0
                ? fill(I18N.saved, { amount: formatPrice(totals.saving) })
                : '';
        }
    }

    // --- Helpers ---

    function formatPrice(cents) {
        var amount = (cents / 100).toFixed(2);
        return (CONFIG.currency_sign || '$') + amount;
    }

    // Fill {named} placeholders in a template from fcbo_savings_strings().
    // An unknown key is left alone rather than blanked, so a mistranslated
    // placeholder shows up as itself instead of silently vanishing.
    // Mirrors fill() in bulk-pricing-display.js.
    function fill(template, values) {
        return String(template || '').replace(/\{(\w+)\}/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
        });
    }

    // Write text into an optional element inside a row, if it is there.
    function setText(row, selector, text) {
        var el = row.querySelector(selector);
        if (el) {
            el.textContent = text;
        }
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
