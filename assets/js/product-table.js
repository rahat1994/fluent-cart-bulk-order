(function () {
    'use strict';

    var CONFIG = window.fcboPtConfig || {};
    var ALL_COLUMNS = ['id', 'title', 'price', 'qty', 'action'];
    var COLUMNS = (CONFIG.columns && CONFIG.columns.length) ? CONFIG.columns : ALL_COLUMNS;
    var COLSPAN = COLUMNS.length;
    // When true, variant accordions render open (variants shown separately by default).
    // wp_localize_script serializes the flag as the string "0"/"1", so compare explicitly
    // (!!"0" would be true).
    var EXPAND = CONFIG.expand_variants === '1' || CONFIG.expand_variants === 1 || CONFIG.expand_variants === true;
    var tbody = null;
    var currentPage = 1;
    var totalPages = 1;
    var searchTimer = null;
    var currentSearch = '';

    function init() {
        tbody = document.getElementById('fcbo-pt-tbody');
        if (!tbody) return;

        var searchInput = document.getElementById('fcbo-pt-search');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                var term = searchInput.value.trim();
                searchTimer = setTimeout(function () {
                    currentSearch = term;
                    currentPage = 1;
                    loadProducts();
                }, 300);
            });
        }

        document.getElementById('fcbo-pt-prev').addEventListener('click', function () {
            if (currentPage > 1) {
                currentPage--;
                loadProducts();
            }
        });

        document.getElementById('fcbo-pt-next').addEventListener('click', function () {
            if (currentPage < totalPages) {
                currentPage++;
                loadProducts();
            }
        });

        loadProducts();
    }

    function loadProducts() {
        tbody.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="fcbo-pt-loading">Loading products...</td></tr>';
        updatePaginationUI();

        var url = CONFIG.rest_url + 'catalog?page=' + currentPage + '&per_page=' + (CONFIG.per_page || 5);
        if (currentSearch.length >= 2) {
            url += '&search=' + encodeURIComponent(currentSearch);
        }
        if (CONFIG.category) {
            url += '&category=' + encodeURIComponent(CONFIG.category);
        }

        fetch(url, {
            headers: { 'X-WP-Nonce': CONFIG.nonce }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            totalPages = data.total_pages || 1;
            renderProducts(data.products || []);
            updatePaginationUI();
        })
        .catch(function () {
            tbody.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="fcbo-pt-loading">Failed to load products.</td></tr>';
        });
    }

    // Build one <td> for a column from a row "model" so the header and body stay
    // aligned (both driven by the same resolved COLUMNS allowlist).
    function renderCell(col, model) {
        switch (col) {
            case 'id':
                return '<td class="fcbo-pt-col-id">' + escapeHtml(model.idText || '') + '</td>';
            case 'title':
                return '<td class="fcbo-pt-col-title">' + model.titleHtml + '</td>';
            case 'price':
                return '<td class="fcbo-pt-col-price">' + escapeHtml(model.priceText) + '</td>';
            case 'qty':
                return '<td class="fcbo-pt-col-qty">' + (model.qtyHtml || '') + '</td>';
            case 'action':
                return '<td class="fcbo-pt-col-action">' + (model.actionHtml || '') + '</td>';
            default:
                return '';
        }
    }

    function assembleRow(trAttrs, model) {
        var cells = '';
        for (var c = 0; c < COLUMNS.length; c++) {
            cells += renderCell(COLUMNS[c], model);
        }
        return '<tr ' + trAttrs + '>' + cells + '</tr>';
    }

    function isOutOfStock(v) {
        return v.stock_status === 'out-of-stock' || (v.manage_stock && v.available <= 0);
    }

    function qtyInputHtml(outOfStock) {
        return '<input type="number" class="fcbo-pt-qty" value="1" min="1" step="1"' +
            (outOfStock ? ' disabled' : '') + ' />';
    }

    function addBtnHtml(outOfStock) {
        return '<button type="button" class="fcbo-pt-add-btn"' + (outOfStock ? ' disabled' : '') + '>' +
            (outOfStock ? 'Out of Stock' : 'Add to Cart') + '</button>';
    }

    // A simple product (single variant): one directly-addable row, no accordion.
    function simpleRow(p) {
        var v = p.variants[0];
        var oos = isOutOfStock(v);
        return assembleRow(
            'data-variant-id="' + v.id + '" data-product-id="' + p.id + '"' +
                (oos ? ' class="fcbo-pt-out-of-stock"' : ''),
            {
                idText: String(p.id),
                titleHtml: '<span class="fcbo-pt-title-text">' + escapeHtml(p.title) + '</span>',
                priceText: formatPrice(v.item_price),
                qtyHtml: qtyInputHtml(oos),
                actionHtml: addBtnHtml(oos)
            }
        );
    }

    // A variable product: a clickable summary row that toggles its variant accordion.
    function productSummaryRow(p, open) {
        var minPrice = p.variants.reduce(function (m, v) {
            return v.item_price < m ? v.item_price : m;
        }, p.variants[0].item_price);
        return assembleRow(
            'class="fcbo-pt-product-row' + (open ? ' is-open' : '') + '" data-product-id="' + p.id + '"',
            {
                idText: String(p.id),
                titleHtml: '<button type="button" class="fcbo-pt-toggle" aria-expanded="' + (open ? 'true' : 'false') + '">' +
                    '<span class="fcbo-pt-caret">' + (open ? '▾' : '▸') + '</span> ' +
                    escapeHtml(p.title) +
                    ' <span class="fcbo-pt-vcount">(' + p.variants.length + ')</span></button>',
                priceText: 'from ' + formatPrice(minPrice),
                qtyHtml: '',
                actionHtml: '<span class="fcbo-pt-choose">' + (open ? 'Hide' : 'Choose') + '</span>'
            }
        );
    }

    // One accordion row per variant of a variable product (hidden until expanded).
    function variantRow(p, v, open) {
        var oos = isOutOfStock(v);
        return assembleRow(
            'class="fcbo-pt-variant-row' + (open ? ' is-open' : '') + '"' +
                ' data-parent-product="' + p.id + '" data-variant-id="' + v.id + '"',
            {
                idText: '',
                titleHtml: '<span class="fcbo-pt-variant-name">' + escapeHtml(v.variation_title) + '</span>',
                priceText: formatPrice(v.item_price),
                qtyHtml: qtyInputHtml(oos),
                actionHtml: addBtnHtml(oos)
            }
        );
    }

    function renderProducts(products) {
        if (!products.length) {
            tbody.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="fcbo-pt-loading">No products found.</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < products.length; i++) {
            var p = products[i];
            if (!p.variants || !p.variants.length) continue;

            if (p.variants.length === 1) {
                html += simpleRow(p);
            } else {
                html += productSummaryRow(p, EXPAND);
                for (var j = 0; j < p.variants.length; j++) {
                    html += variantRow(p, p.variants[j], EXPAND);
                }
            }
        }

        tbody.innerHTML = html;

        var buttons = tbody.querySelectorAll('.fcbo-pt-add-btn:not([disabled])');
        for (var k = 0; k < buttons.length; k++) {
            buttons[k].addEventListener('click', handleAddToCart);
        }

        var productRows = tbody.querySelectorAll('.fcbo-pt-product-row');
        for (var m = 0; m < productRows.length; m++) {
            productRows[m].addEventListener('click', handleProductToggle);
        }
    }

    // Expand/collapse a variable product's variant accordion.
    function handleProductToggle() {
        var row = this;
        var pid = row.getAttribute('data-product-id');
        var open = !row.classList.contains('is-open');
        row.classList.toggle('is-open', open);

        var caret = row.querySelector('.fcbo-pt-caret');
        if (caret) { caret.textContent = open ? '▾' : '▸'; }
        var toggle = row.querySelector('.fcbo-pt-toggle');
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        var choose = row.querySelector('.fcbo-pt-choose');
        if (choose) { choose.textContent = open ? 'Hide' : 'Choose'; }

        var variantRows = tbody.querySelectorAll('.fcbo-pt-variant-row[data-parent-product="' + pid + '"]');
        for (var i = 0; i < variantRows.length; i++) {
            variantRows[i].classList.toggle('is-open', open);
        }
    }

    function handleAddToCart() {
        var btn = this;
        var row = btn.closest('tr');
        var variantId = row.dataset.variantId;
        // The qty column may be hidden via the `columns` attribute; default to 1 then.
        var qtyInput = row.querySelector('.fcbo-pt-qty');
        var qty = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;

        if (!window.fluentCartCart || typeof window.fluentCartCart.addProduct !== 'function') {
            showStatus('FluentCart cart is not available. Please refresh the page.', 'error');
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Adding...';

        try {
            var result = window.fluentCartCart.addProduct(variantId, qty, true);

            if (result && typeof result.then === 'function') {
                result.then(function () {
                    btn.textContent = 'Added!';
                    setTimeout(function () {
                        btn.textContent = 'Add to Cart';
                        btn.disabled = false;
                    }, 1500);
                }).catch(function (err) {
                    showStatus('Failed: ' + (err.message || 'Unknown error'), 'error');
                    btn.textContent = 'Add to Cart';
                    btn.disabled = false;
                });
            } else {
                setTimeout(function () {
                    btn.textContent = 'Added!';
                    setTimeout(function () {
                        btn.textContent = 'Add to Cart';
                        btn.disabled = false;
                    }, 1500);
                }, 300);
            }
        } catch (err) {
            showStatus('Failed: ' + (err.message || 'Unknown error'), 'error');
            btn.textContent = 'Add to Cart';
            btn.disabled = false;
        }
    }

    function updatePaginationUI() {
        var prevBtn = document.getElementById('fcbo-pt-prev');
        var nextBtn = document.getElementById('fcbo-pt-next');
        var pageInfo = document.getElementById('fcbo-pt-page-info');

        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
        pageInfo.textContent = 'Page ' + currentPage + ' of ' + totalPages;
    }

    function formatPrice(cents) {
        var amount = (cents / 100).toFixed(2);
        return (CONFIG.currency_sign || '$') + amount;
    }

    function showStatus(msg, type) {
        var el = document.getElementById('fcbo-pt-status');
        if (!el) return;
        el.textContent = msg;
        el.className = 'fcbo-pt-status fcbo-pt-status-' + type;
        el.style.display = 'block';
        setTimeout(function () { el.style.display = 'none'; }, 4000);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
