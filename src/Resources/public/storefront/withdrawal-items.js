/*
 * Shopproof item picker for Shopware's withdrawal form, as in the WooCommerce plugin and the
 * Shopify app. Once the customer has entered the order number and email (or opened the form from
 * the signed link in an order email), it asks the shop for that order's items and lists them with
 * their photos to tick. The ticked items go into a hidden field as lines of text ("2 x Shirt");
 * nothing ticked means the whole order. If no order fits, the form stays as Shopware made it.
 * Plain script, no build step; it only touches the element Shopproof added to the form.
 */
(function () {
    'use strict';

    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    var HEX = /^[0-9a-f]{32}$/;

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (attrs[key] !== null && attrs[key] !== undefined && attrs[key] !== false) {
                node.setAttribute(key, attrs[key] === true ? '' : String(attrs[key]));
            }
        });
        (children || []).forEach(function (child) {
            if (child !== null && child !== undefined) {
                node.append(typeof child === 'string' ? document.createTextNode(child) : child);
            }
        });
        return node;
    }

    function safeImage(url) {
        return typeof url === 'string' && /^https?:\/\//i.test(url) ? url : '';
    }

    function linkKeys() {
        var query = new URLSearchParams(window.location.search);
        var order = query.get('spOrder') || '';
        var token = query.get('spToken') || '';
        return HEX.test(order) && HEX.test(token) ? { spOrder: order, spToken: token } : null;
    }

    function attach(box) {
        var form = box.closest('form');
        var texts;
        try {
            texts = JSON.parse(box.getAttribute('data-texts') || '{}');
        } catch (e) {
            return;
        }
        var url = box.getAttribute('data-url');
        if (!form || !url) {
            return;
        }
        var order = form.querySelector('[name="contractNumber"]');
        var email = form.querySelector('[name="email"]');
        var field = form.querySelector('[name="shopproofItems"]');
        var status = box.querySelector('.shopproof-picker-status');
        if (!order || !email || !field || !status) {
            return;
        }
        var keys = linkKeys();
        var list = null;
        var last = '';
        var seq = 0;

        function clear() {
            if (list) {
                list.remove();
            }
            list = null;
            field.value = '';
        }

        function show(items, note) {
            clear();
            var rows = items.map(function (item) {
                return { item: item, picked: 0 };
            });
            function sync() {
                field.value = rows.filter(function (row) {
                    return row.picked > 0;
                }).map(function (row) {
                    return row.picked + ' x ' + row.item.name;
                }).join('\n');
            }
            var hintId = 'shopproof-picker-hint';
            var entries = rows.map(function (row, index) {
                var id = 'shopproof-pick-' + index;
                var nameId = id + '-name';
                var excludedId = row.item.excluded ? id + '-excluded' : null;
                var check = el('input', { type: 'checkbox', id: id, class: 'form-check-input', 'aria-describedby': excludedId });
                var qty = null;
                var image = safeImage(row.item.image);
                var parts = [
                    check,
                    el('label', { for: id, class: 'form-check-label d-flex align-items-center gap-2' }, [
                        image ? el('img', { src: image, alt: '', width: 64, height: 64, loading: 'lazy', style: 'object-fit:cover' }) : null,
                        el('span', { id: nameId }, [row.item.quantity > 1 ? row.item.quantity + ' x ' + row.item.name : row.item.name]),
                    ]),
                ];
                if (row.item.quantity > 1) {
                    var qid = id + '-qty';
                    var options = [];
                    for (var n = 1; n <= row.item.quantity; n++) {
                        options.push(el('option', { value: String(n), selected: n === row.item.quantity }, [String(n)]));
                    }
                    qty = el('select', { id: qid, class: 'form-select form-select-sm w-auto d-inline-block', disabled: true, 'aria-describedby': nameId }, options);
                    qty.addEventListener('change', function () {
                        row.picked = check.checked ? Number(qty.value) : 0;
                        sync();
                    });
                    parts.push(el('span', { class: 'd-flex align-items-center gap-2 ms-4 mt-1' }, [el('label', { for: qid }, [texts.quantity]), qty]));
                }
                if (excludedId) {
                    parts.push(el('div', { id: excludedId, class: 'form-text ms-4' }, [(texts.excluded + ' ' + (note || texts.defaultNote)).trim()]));
                }
                check.addEventListener('change', function () {
                    row.picked = check.checked ? Number(qty ? qty.value : row.item.quantity) : 0;
                    if (qty) {
                        qty.disabled = !check.checked;
                    }
                    sync();
                });
                return el('li', { class: 'form-check mb-3' }, parts);
            });
            list = el('fieldset', { class: 'shopproof-picker-list', 'aria-describedby': hintId }, [
                el('legend', { class: 'form-label fs-6' }, [texts.legend]),
                el('div', { id: hintId, class: 'form-text mb-2' }, [texts.hint]),
                el('ul', { class: 'list-unstyled mb-0', role: 'list' }, entries),
            ]);
            box.append(list);
        }

        function lookup() {
            var number = order.value.trim();
            var mail = email.value.trim();
            if (!keys && (!number || !EMAIL.test(mail))) {
                return;
            }
            var key = number + '\n' + mail + '\n' + (keys ? keys.spToken : '');
            if (key === last) {
                return;
            }
            last = key;
            var mine = ++seq;
            status.textContent = texts.looking;
            var body = { contractNumber: number, email: mail };
            if (keys) {
                body.spOrder = keys.spOrder;
                body.spToken = keys.spToken;
            }
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            }).then(function (res) {
                return res.ok ? res.json() : null;
            }).catch(function () {
                return null;
            }).then(function (data) {
                if (mine !== seq) {
                    return;
                }
                var items = data && Array.isArray(data.items) ? data.items.filter(function (item) {
                    return item && typeof item.name === 'string' && item.name.trim() !== '';
                }).map(function (item) {
                    return {
                        name: item.name.trim(),
                        quantity: Math.max(1, Math.floor(Number(item.quantity)) || 1),
                        image: item.image,
                        excluded: item.excluded === true,
                    };
                }) : [];
                if (items.length > 0) {
                    show(items, data && typeof data.excludedNote === 'string' ? data.excludedNote.trim() : '');
                    status.textContent = texts.found;
                } else if (keys) {
                    // A link that no longer fits: try the number and email instead.
                    keys = null;
                    last = '';
                    lookup();
                } else {
                    clear();
                    status.textContent = texts.none;
                }
            });
        }

        order.addEventListener('change', lookup);
        email.addEventListener('change', lookup);
        if (keys || order.value.trim()) {
            lookup();
        }
    }

    function start() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-shopproof-picker]'), attach);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
