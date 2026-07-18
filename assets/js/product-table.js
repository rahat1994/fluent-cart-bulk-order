(function () {
    'use strict';

    var CONFIG = window.fcboPtConfig || {};
    var ALL_COLUMNS = ['id', 'title', 'price', 'qty', 'action'];
    var COLUMNS = (CONFIG.columns && CONFIG.columns.length) ? CONFIG.columns : ALL_COLUMNS;
    var COLSPAN = COLUMNS.length;
    // 'dropdown' = one row per product (variant picked inline); 'separate' = one row per variant.
    var MODE = (CONFIG.variations === 'separate') ? 'separate' : 'dropdown';
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

        var url = CONFIG.rest_url + 'catalog?page=' + currentPage + '&per_page=' + (CONFIG.per_page || 5) + '&variations=' + MODE;
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

    // Build a single <td> for the given column so the JS body stays aligned with the
    // server-rendered header (both driven by the same resolved COLUMNS allowlist).
    function buildCell(col, p, v, title, outOfStock, withSelect) {
        switch (col) {
            case 'id':
                return '<td class="fcbo-pt-col-id">' + escapeHtml(String(p.id)) + '</td>';
            case 'title':
                var cell = '<td class="fcbo-pt-col-title">' +
                    '<a href="#" class="fcbo-pt-title-link" data-product-id="' + p.id + '">' +
                    escapeHtml(title) + '</a>';
                // Dropdown mode: inline variant picker when the product has >1 variant.
                if (withSelect && p.variants.length > 1) {
                    cell += '<select class="fcbo-pt-variant-select" data-variants=\'' +
                        escapeAttr(JSON.stringify(p.variants)) + '\'>';
                    for (var s = 0; s < p.variants.length; s++) {
                        cell += '<option value="' + s + '">' +
                            escapeHtml(p.variants[s].variation_title) + '</option>';
                    }
                    cell += '</select>';
                }
                return cell + '</td>';
            case 'price':
                return '<td class="fcbo-pt-col-price">' + escapeHtml(formatPrice(v.item_price)) + '</td>';
            case 'qty':
                return '<td class="fcbo-pt-col-qty">' +
                    '<input type="number" class="fcbo-pt-qty" value="1" min="1" step="1"' +
                    (outOfStock ? ' disabled' : '') + ' />' +
                    '</td>';
            case 'action':
                return '<td class="fcbo-pt-col-action">' +
                    '<button type="button" class="fcbo-pt-add-btn"' +
                    (outOfStock ? ' disabled' : '') + '>' +
                    (outOfStock ? 'Out of Stock' : 'Add to Cart') +
                    '</button>' +
                    '</td>';
            default:
                return '';
        }
    }

    function buildRow(p, v, withSelect) {
        var outOfStock = v.stock_status === 'out-of-stock' || (v.manage_stock && v.available <= 0);
        var title = p.title;
        // Separate mode labels each row with its variant; dropdown mode uses the picker.
        if (!withSelect && p.variants.length > 1) {
            title += ' — ' + v.variation_title;
        }

        var cells = '';
        for (var c = 0; c < COLUMNS.length; c++) {
            cells += buildCell(COLUMNS[c], p, v, title, outOfStock, withSelect);
        }

        return '<tr data-variant-id="' + v.id + '" data-product-id="' + p.id + '"' +
            (outOfStock ? ' class="fcbo-pt-out-of-stock"' : '') + '>' +
            cells +
        '</tr>';
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

            if (MODE === 'separate') {
                for (var j = 0; j < p.variants.length; j++) {
                    html += buildRow(p, p.variants[j], false);
                }
            } else {
                // Dropdown mode: one row per product, defaulting to the first variant.
                html += buildRow(p, p.variants[0], true);
            }
        }

        tbody.innerHTML = html;

        var buttons = tbody.querySelectorAll('.fcbo-pt-add-btn:not([disabled])');
        for (var k = 0; k < buttons.length; k++) {
            buttons[k].addEventListener('click', handleAddToCart);
        }

        var titleLinks = tbody.querySelectorAll('.fcbo-pt-title-link');
        for (var m = 0; m < titleLinks.length; m++) {
            titleLinks[m].addEventListener('click', handleTitleClick);
        }

        var selects = tbody.querySelectorAll('.fcbo-pt-variant-select');
        for (var n = 0; n < selects.length; n++) {
            selects[n].addEventListener('change', handleVariantChange);
        }
    }

    // Dropdown mode: switch the row's active variant when the picker changes.
    function handleVariantChange() {
        var select = this;
        var row = select.closest('tr');
        var variants;
        try {
            variants = JSON.parse(select.dataset.variants);
        } catch (e) {
            return;
        }
        var v = variants[parseInt(select.value, 10) || 0];
        if (!v) return;

        row.dataset.variantId = v.id;

        var priceCell = row.querySelector('.fcbo-pt-col-price');
        if (priceCell) priceCell.textContent = formatPrice(v.item_price);

        var outOfStock = v.stock_status === 'out-of-stock' || (v.manage_stock && v.available <= 0);
        var addBtn = row.querySelector('.fcbo-pt-add-btn');
        if (addBtn) {
            addBtn.disabled = outOfStock;
            addBtn.textContent = outOfStock ? 'Out of Stock' : 'Add to Cart';
        }
        var qtyInput = row.querySelector('.fcbo-pt-qty');
        if (qtyInput) qtyInput.disabled = outOfStock;
    }

    function handleTitleClick(e) {
        e.preventDefault();
        var productId = this.dataset.productId;

        if (window.FluentCartSingleProductModal && typeof window.FluentCartSingleProductModal.openModal === 'function') {
            window.FluentCartSingleProductModal.openModal(productId, this);
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

    function escapeAttr(str) {
        return String(str).replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
