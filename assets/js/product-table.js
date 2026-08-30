(function () {
    'use strict';

    var CONFIG = window.fcboPtConfig || {};
    var I18N = CONFIG.i18n || {};
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
    // What this table has already handed to the cart, so a basket FluentCart
    // will refuse can be refused here first — before the shopper has clicked
    // through several pages of the catalogue building it.
    //
    // Deliberately NOT reset when the page or search changes: the cart does not
    // empty itself when a shopper pages forward, so neither may this. It is
    // also deliberately not the whole truth — it knows nothing about items
    // added on another page or in an earlier session, and there is no read-only
    // cart-contents API to ask. FluentCart's own refusal
    // (fluent-cart/app/Models/Cart.php:443) remains the authority; this only
    // moves the common case earlier, which is the whole of issue #34's third
    // complaint.
    var addedRecurring = false;
    var addedOnetime = false;

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
        tbody.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="fcbo-pt-loading">' + escapeHtml(t('loading')) + '</td></tr>';
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
            tbody.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="fcbo-pt-loading">' + escapeHtml(t('load_failed')) + '</td></tr>';
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

    // Is this variant billed on a repeating schedule?
    //
    // Mirrors bulk-order.js, which has read `payment_type` since it shipped;
    // this table could not, because listCatalog() did not send the field.
    // @see includes/Cart/SubscriptionRule.php for the rules that follow from a
    // true answer, and for the FluentCart file:line each of them restates.
    function isRecurring(v) {
        return !!v && v.payment_type === 'subscription';
    }

    // --- Order rules ---
    //
    // These three mirror the PHP helpers of the same intent
    // (fcbo_normalize_order_rules / fcbo_normalize_qty) and the copies in
    // bulk-order.js. All three must change together: the server rejects any
    // quantity these would have altered.

    function orderRules(v) {
        // A subscription has no order rules, whatever the feed says. Its
        // quantity is 1 and cannot be anything else, so a minimum of 5 or a
        // case pack of 12 is not a constraint the shopper can satisfy — it is
        // just a product they can no longer buy. Neutralised HERE, at the one
        // place rules are read, so the spinner attributes, the hint sentence
        // and handleAddToCart() all inherit the same answer instead of each
        // remembering to ask.
        //
        // The server agrees: RuleEnforcement::validateCartItem() skips
        // recurring variations for the same reason.
        if (isRecurring(v)) {
            return { min_qty: 0, step: 1 };
        }

        var raw = (v && v.order_rules) || {};
        return {
            min_qty: Math.max(0, parseInt(raw.min_qty, 10) || 0),
            step: Math.max(1, parseInt(raw.step, 10) || 1)
        };
    }

    // Rounds UP only, so a shopper never silently receives less than they asked for.
    function normalizeQty(qty, rules) {
        qty = Math.max(1, parseInt(qty, 10) || 1, rules.min_qty);
        if (rules.step > 1) {
            qty = Math.ceil(qty / rules.step) * rules.step;
        }
        return qty;
    }

    function describeRule(rules) {
        if (rules.min_qty > 0 && rules.step > 1) {
            return fill(t('rule_min_and_step'), { min: rules.min_qty, step: rules.step });
        }
        if (rules.step > 1) {
            return fill(t('rule_step'), { step: rules.step });
        }
        if (rules.min_qty > 0) {
            return fill(t('rule_min'), { min: rules.min_qty });
        }
        return '';
    }

    function qtyInputHtml(outOfStock, v) {
        var rules = orderRules(v);
        var recurring = isRecurring(v);
        var start = normalizeQty(1, rules);
        // The Order Rule hint is replaced, not merely dropped, on a recurring
        // row: a disabled box reading "1" with nothing beside it looks broken.
        // orderRules() has already flattened the rules to nothing above, so
        // describeRule() would return '' here anyway.
        var hint = recurring ? t('recurring_note') : describeRule(rules);

        // Disabled and pinned to 1, exactly as bulk-order.js:681 does it, and
        // for the host's reason rather than ours: FluentCart overwrites the
        // quantity with 1 before it builds the cart
        // (fluent-cart/api/Resource/FrontendResource/CartResource.php:63-65)
        // and refuses the purchase above 1
        // (fluent-cart/app/Models/ProductVariation.php:249). An editable
        // spinner would be offering a number the store then silently changes.
        return '<input type="number" class="fcbo-pt-qty" value="' + start + '"' +
            ' min="' + Math.max(1, rules.min_qty) + '" step="' + rules.step + '"' +
            ' data-min-qty="' + rules.min_qty + '" data-step="' + rules.step + '"' +
            (outOfStock || recurring ? ' disabled' : '') + ' />' +
            (hint ? '<span class="fcbo-pt-qty-hint">' + escapeHtml(hint) + '</span>' : '');
    }

    // The visible "this repeats" badge for a row title.
    //
    // Visible text rather than an icon or a colour, because it is the answer to
    // "why is the quantity box greyed out" and that question is asked by
    // everyone, including the shoppers a colour cue never reaches. Same bar the
    // search-match marker is held to.
    function recurringBadgeHtml(v) {
        if (!isRecurring(v)) {
            return '';
        }

        return ' <span class="fcbo-pt-recurring">' + escapeHtml(t('recurring_label')) + '</span>';
    }

    // Row attributes carrying what the click handler needs to know later.
    //
    // A flag, not the payment_type string bulk-order.js keeps in
    // row.dataset.paymentType. That file assigns through the dataset property,
    // which cannot break out of an attribute; this file builds an HTML string,
    // and "1" or nothing is a value that needs no escaping to be safe.
    function recurringAttr(v) {
        return isRecurring(v) ? ' data-recurring="1"' : '';
    }

    function addBtnHtml(outOfStock) {
        return '<button type="button" class="fcbo-pt-add-btn"' + (outOfStock ? ' disabled' : '') + '>' +
            escapeHtml(outOfStock ? t('out_of_stock') : t('add_to_cart')) + '</button>';
    }

    // A simple product (single variant): one directly-addable row, no accordion.
    //
    // Marked on a SKU match like any other row. The first cut skipped this,
    // reasoning that a single-variant product IS the match so there is no
    // sibling to tell it apart from — true in isolation, wrong on a page. A
    // result list mixing marked variant rows with unmarked simple rows reads as
    // "these matched and those did not", which is a false statement about rows
    // the search did find.
    function simpleRow(p) {
        var v = p.variants[0];
        var oos = isOutOfStock(v);
        var classes = [];
        if (oos) classes.push('fcbo-pt-out-of-stock');
        if (v.search_match) classes.push('is-search-match');
        var matchNote = v.search_match
            ? '<span class="fcbo-sr-only">' + escapeHtml(t('search_match')) + '</span>'
            : '';
        return assembleRow(
            'data-variant-id="' + v.id + '" data-product-id="' + p.id + '"' + recurringAttr(v) +
                (classes.length ? ' class="' + classes.join(' ') + '"' : ''),
            {
                idText: String(p.id),
                titleHtml: '<span class="fcbo-pt-title-text">' + escapeHtml(p.title) + '</span>' +
                    recurringBadgeHtml(v) + matchNote,
                priceText: formatPrice(v.item_price),
                qtyHtml: qtyInputHtml(oos, v),
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

    // Did the search that produced this page find one of the product's variants?
    //
    // Drives whether the accordion is forced open below. Without that, a store
    // with "expand variants" off would mark the matched row and then leave it
    // collapsed — the shopper searches a SKU, the product appears, and the row
    // they typed is behind a click they have no reason to make.
    function hasSearchMatch(p) {
        if (!p.variants) return false;
        for (var i = 0; i < p.variants.length; i++) {
            if (p.variants[i].search_match) return true;
        }
        return false;
    }

    // One accordion row per variant of a variable product (hidden until expanded).
    function variantRow(p, v, open) {
        var oos = isOutOfStock(v);
        // The visually hidden sentence is the accessible half of the highlight.
        // Everything else about a matched row is colour and weight, which a
        // screen reader cannot convey and a colour-blind shopper may not see —
        // the a11y bar this repo set in plan 005 is that no state is signalled
        // by colour alone.
        var matchNote = v.search_match
            ? '<span class="fcbo-sr-only">' + escapeHtml(t('search_match')) + '</span>'
            : '';
        return assembleRow(
            'class="fcbo-pt-variant-row' + (open ? ' is-open' : '') +
                (v.search_match ? ' is-search-match' : '') + '"' +
                ' data-parent-product="' + p.id + '" data-variant-id="' + v.id + '"' + recurringAttr(v),
            {
                idText: '',
                titleHtml: '<span class="fcbo-pt-variant-name">' + escapeHtml(v.variation_title) + '</span>' +
                    recurringBadgeHtml(v) + matchNote,
                priceText: formatPrice(v.item_price),
                qtyHtml: qtyInputHtml(oos, v),
                actionHtml: addBtnHtml(oos)
            }
        );
    }

    function renderProducts(products) {
        if (!products.length) {
            tbody.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="fcbo-pt-loading">' + escapeHtml(t('no_products')) + '</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < products.length; i++) {
            var p = products[i];
            if (!p.variants || !p.variants.length) continue;

            if (p.variants.length === 1) {
                html += simpleRow(p);
            } else {
                // Forced open when the search found a variant in here, whatever
                // the store's expand_variants setting says. The setting is about
                // the default browse experience; a search result that hides the
                // row the shopper searched for is just a wrong answer.
                var open = EXPAND || hasSearchMatch(p);
                html += productSummaryRow(p, open);
                for (var j = 0; j < p.variants.length; j++) {
                    html += variantRow(p, p.variants[j], open);
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

        // Correct a typed quantity when it is committed ('change', not 'input',
        // so a half-typed value is not snapped mid-keystroke).
        var qtyInputs = tbody.querySelectorAll('.fcbo-pt-qty:not([disabled])');
        for (var n = 0; n < qtyInputs.length; n++) {
            qtyInputs[n].addEventListener('change', handleQtyChange);
        }
    }

    function handleQtyChange() {
        var rules = {
            min_qty: Math.max(0, parseInt(this.dataset.minQty, 10) || 0),
            step: Math.max(1, parseInt(this.dataset.step, 10) || 1)
        };
        var requested = parseInt(this.value, 10) || 0;
        var settled = normalizeQty(requested, rules);

        if (settled !== requested) {
            this.value = settled;
            showStatus(fill(t('qty_adjusted'), { qty: settled }), 'error');
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
        // The rules are read back off the input's data attributes so a hidden qty
        // column still starts from a rule-valid quantity rather than a bare 1.
        var qtyInput = row.querySelector('.fcbo-pt-qty');
        var rules = {
            min_qty: Math.max(0, parseInt(qtyInput && qtyInput.dataset.minQty, 10) || 0),
            step: Math.max(1, parseInt(qtyInput && qtyInput.dataset.step, 10) || 1)
        };
        var requested = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;
        var recurring = row.dataset.recurring === '1';
        // Pinned rather than normalised, mirroring handleCheckout() in
        // bulk-order.js. Sending anything else would be sending a number
        // CartResource.php:63-65 immediately throws away.
        var qty = recurring ? 1 : normalizeQty(requested, rules);

        // Correct in place and say so, rather than adding a different quantity
        // than the one on screen. Never fires on a recurring row: its input is
        // disabled at 1 and its rules were flattened in orderRules().
        if (qty !== requested) {
            if (qtyInput) { qtyInput.value = qty; }
            showStatus(fill(t('qty_adjusted'), { qty: qty }), 'error');
        }

        // The refusal the shopper used to meet at the cart, moved to the click
        // that causes it. Same sentence the Bulk Order Form uses, from
        // Strings::subscriptionNotices() — one refusal, one wording.
        if ((recurring && addedOnetime) || (!recurring && addedRecurring)) {
            showStatus(t('checkout_mixed_types'), 'error');
            return;
        }

        if (!window.fluentCartCart || typeof window.fluentCartCart.addProduct !== 'function') {
            showStatus(t('cart_missing'), 'error');
            return;
        }

        btn.disabled = true;
        btn.textContent = t('adding');

        try {
            var result = window.fluentCartCart.addProduct(variantId, qty, true);

            if (result && typeof result.then === 'function') {
                result.then(function () {
                    rememberAddedType(recurring);
                    btn.textContent = t('added');
                    setTimeout(function () {
                        btn.textContent = t('add_to_cart');
                        btn.disabled = false;
                    }, 1500);
                }).catch(function (err) {
                    showStatus(fill(t('add_failed'), { error: err.message || t('unknown_error') }), 'error');
                    btn.textContent = t('add_to_cart');
                    btn.disabled = false;
                });
            } else {
                // No promise to wait on, so the add is taken on trust here the
                // same way the "Added!" label below already is.
                rememberAddedType(recurring);
                setTimeout(function () {
                    btn.textContent = t('added');
                    setTimeout(function () {
                        btn.textContent = t('add_to_cart');
                        btn.disabled = false;
                    }, 1500);
                }, 300);
            }
        } catch (err) {
            showStatus(fill(t('add_failed'), { error: err.message || t('unknown_error') }), 'error');
            btn.textContent = t('add_to_cart');
            btn.disabled = false;
        }
    }

    // Record what kind of line just reached the cart.
    //
    // Only on a SUCCESSFUL add, never on the attempt: a rejected add left the
    // cart as it was, and remembering it would lock the shopper out of the
    // other kind for the rest of the visit over something that never happened.
    function rememberAddedType(recurring) {
        if (recurring) {
            addedRecurring = true;
            return;
        }

        addedOnetime = true;
    }

    function updatePaginationUI() {
        var prevBtn = document.getElementById('fcbo-pt-prev');
        var nextBtn = document.getElementById('fcbo-pt-next');
        var pageInfo = document.getElementById('fcbo-pt-page-info');

        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
        pageInfo.textContent = fill(t('page_of'), { current: currentPage, total: totalPages });
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

    // A translated sentence by key, with {placeholders} filled. Both mirror
    // the helpers in bulk-order.js — I18N comes from wp_localize_script, so a
    // missing key means the PHP side was not updated.
    function t(key) {
        return Object.prototype.hasOwnProperty.call(I18N, key) ? I18N[key] : key;
    }

    function fill(template, values) {
        return String(template || '').replace(/\{(\w+)\}/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
        });
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
