(function () {
    'use strict';

    var CONFIG = window.fcboPtConfig || {};
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
        tbody.innerHTML = '<tr><td colspan="5" class="fcbo-pt-loading">Loading products...</td></tr>';
        updatePaginationUI();

        var url = CONFIG.rest_url + 'catalog?page=' + currentPage + '&per_page=' + (CONFIG.per_page || 5);
        if (currentSearch.length >= 2) {
            url += '&search=' + encodeURIComponent(currentSearch);
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
            tbody.innerHTML = '<tr><td colspan="5" class="fcbo-pt-loading">Failed to load products.</td></tr>';
        });
    }

    function renderProducts(products) {
        if (!products.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="fcbo-pt-loading">No products found.</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < products.length; i++) {
            var p = products[i];
            if (!p.variants || !p.variants.length) continue;

            for (var j = 0; j < p.variants.length; j++) {
                var v = p.variants[j];
                var outOfStock = v.stock_status === 'out-of-stock' || (v.manage_stock && v.available <= 0);
                var title = p.title;
                if (p.variants.length > 1) {
                    title += ' — ' + v.variation_title;
                }

                html += '<tr data-variant-id="' + v.id + '" data-product-id="' + p.id + '"' +
                    (outOfStock ? ' class="fcbo-pt-out-of-stock"' : '') + '>' +
                    '<td class="fcbo-pt-col-id">' + escapeHtml(String(p.id)) + '</td>' +
                    '<td class="fcbo-pt-col-title">' +
                        '<a href="#" class="fcbo-pt-title-link" data-product-id="' + p.id + '">' +
                        escapeHtml(title) + '</a>' +
                    '</td>' +
                    '<td class="fcbo-pt-col-price">' + escapeHtml(formatPrice(v.item_price)) + '</td>' +
                    '<td class="fcbo-pt-col-qty">' +
                        '<input type="number" class="fcbo-pt-qty" value="1" min="1" step="1"' +
                        (outOfStock ? ' disabled' : '') + ' />' +
                    '</td>' +
                    '<td class="fcbo-pt-col-action">' +
                        '<button type="button" class="fcbo-pt-add-btn"' +
                        (outOfStock ? ' disabled' : '') + '>' +
                        (outOfStock ? 'Out of Stock' : 'Add to Cart') +
                        '</button>' +
                    '</td>' +
                '</tr>';
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
        var qty = parseInt(row.querySelector('.fcbo-pt-qty').value, 10) || 1;

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
