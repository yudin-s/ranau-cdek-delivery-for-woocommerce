(function (window, document) {
    'use strict';

    var config = window.RanauCdekDelivery || {};
    var adapterFactory = window.RanauCdekDeliveryCommitV1;
    if (!adapterFactory || typeof adapterFactory.create !== 'function') {
        return;
    }
    var commitAdapter = adapterFactory.create({
        methodId: 'ranau_cdek_pickup',
        namespace: config.storeApiNamespace || 'ranau-cdek-delivery-for-woocommerce',
        commitUrl: config.commitUrl || ((config.restUrl || '') + '/commit'),
        nonce: config.restNonce || '',
        packageFingerprint: config.packageFingerprint || ''
    });
    var state = {
        point: config.selectedPoint || {},
        quote: config.quote || {},
        modal: null,
        renderQueued: false,
        loading: false
    };

    function escapeHtml(value) {
        var node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function cssEscape(value) {
        return window.CSS && typeof window.CSS.escape === 'function'
            ? window.CSS.escape(String(value || ''))
            : String(value || '').replace(/["\\]/g, '\\$&');
    }

    function fieldValue(selectors) {
        for (var index = 0; index < selectors.length; index += 1) {
            var field = document.querySelector(selectors[index]);
            if (field && String(field.value || '').trim()) {
                return String(field.value).trim();
            }
        }
        return '';
    }

    function currentCity() {
        return fieldValue(['#shipping-city', '#shipping_city', 'input[name="shipping_city"]', '.wc-block-components-address-form__city input', 'input[autocomplete="address-level2"]']) || config.defaultCity || 'Москва';
    }

    function currentPostcode() {
        return fieldValue(['#shipping-postcode', '#shipping_postcode', 'input[name="shipping_postcode"]', 'input[autocomplete="postal-code"]']);
    }

    function isReady() {
        return Boolean(state.point && state.point.code && state.point._ranau_committed && state.quote && state.quote.ok);
    }

    function priceLabel() {
        if (!isReady()) {
            return state.point && state.point.code ? 'Стоимость пока недоступна' : 'Рассчитаем после выбора ПВЗ';
        }
        return state.quote.free_shipping ? 'Бесплатно' : Number(state.quote.price || 0).toLocaleString('ru-RU', {maximumFractionDigits: 2}) + ' ₽';
    }

    function summary() {
        if (!state.point || !state.point.code) {
            return 'Пункт выдачи пока не выбран';
        }
        var text = [state.point.code, state.point.address, priceLabel()].filter(Boolean).join(' · ');
        if (isReady() && Number(state.quote.period_max || 0)) {
            text += ' · до ' + Number(state.quote.period_max) + ' дн.';
        }
        return text;
    }

    function selectorHtml(rateValue) {
        return [
            '<div class="ranau-cdek-selector ranau-cdek-blocks-selector" data-ranau-cdek-rate="' + escapeHtml(rateValue) + '" data-ranau-delivery-method="ranau_cdek_pickup" data-ranau-delivery-complete="0">',
            '<div class="ranau-cdek-selector__head"><div><strong>ПУНКТ ВЫДАЧИ CDEK</strong><span>Выберите ПВЗ или постамат из актуального справочника CDEK.</span></div>',
            '<button type="button" class="ranau-cdek-open">Выбрать ПВЗ</button></div>',
            '<div class="ranau-cdek-selected" aria-live="polite">Пункт выдачи пока не выбран</div>',
            '<button type="button" class="ranau-cdek-clear" hidden>Сбросить выбор</button></div>'
        ].join('');
    }

    function updateSelector(root) {
        if (!root) {
            return;
        }
        root.setAttribute('data-ranau-delivery-complete', isReady() ? '1' : '0');
        var selected = root.querySelector('.ranau-cdek-selected');
        var open = root.querySelector('.ranau-cdek-open');
        var clear = root.querySelector('.ranau-cdek-clear');
        if (selected) {
            selected.textContent = summary();
            selected.classList.toggle('is-ready', isReady());
        }
        if (open) {
            open.textContent = state.point && state.point.code ? 'Изменить ПВЗ' : 'Выбрать ПВЗ';
        }
        if (clear) {
            clear.hidden = !(state.point && state.point.code);
        }
    }

    function renderSelectors() {
        state.renderQueued = false;
        document.querySelectorAll('.ranau-cdek-blocks-selector').forEach(function (selector) {
            var value = selector.getAttribute('data-ranau-cdek-rate') || '';
            var radio = document.querySelector('input[type="radio"][value="' + cssEscape(value) + '"]');
            if (!radio || !radio.checked) {
                selector.remove();
            }
        });
        document.querySelectorAll('input[type="radio"][value^="ranau_cdek_pickup"]').forEach(function (radio) {
            var option = radio.closest('label.wc-block-components-radio-control__option, label');
            if (!option || !radio.checked) {
                return;
            }
            var selector = document.querySelector('.ranau-cdek-blocks-selector[data-ranau-cdek-rate="' + cssEscape(radio.value || '') + '"]');
            if (!selector) {
                option.insertAdjacentHTML('afterend', selectorHtml(radio.value || ''));
                selector = option.nextElementSibling;
            }
            updateSelector(selector);
            var price = option.querySelector('.wc-block-components-radio-control__secondary-label');
            if (price) {
                price.setAttribute('data-ranau-cdek-rate-label', priceLabel());
            }
        });
        document.querySelectorAll('[data-ranau-cdek-classic]').forEach(function (selector) {
            var row = selector.closest('li, tr') || document;
            var radio = row.querySelector('input.shipping_method[value^="ranau_cdek_pickup"]');
            selector.classList.toggle('is-active', Boolean(radio && radio.checked));
            updateSelector(selector);
        });
    }

    function scheduleRender() {
        if (!state.renderQueued) {
            state.renderQueued = true;
            window.requestAnimationFrame(renderSelectors);
        }
    }

    function ensureModal() {
        if (state.modal) {
            return state.modal;
        }
        var modal = document.createElement('div');
        modal.className = 'ranau-cdek-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', 'Выберите пункт выдачи CDEK');
        modal.innerHTML = [
            '<div class="ranau-cdek-dialog">',
            '<div class="ranau-cdek-toolbar"><strong>Пункты выдачи CDEK</strong><button type="button" class="ranau-cdek-close" aria-label="Закрыть">×</button></div>',
            '<form class="ranau-cdek-search"><label><span>Город</span><input class="ranau-cdek-city" type="text" autocomplete="address-level2"></label><label><span>Индекс</span><input class="ranau-cdek-postcode" type="text" inputmode="numeric" autocomplete="postal-code"></label><button type="submit">Найти ПВЗ</button></form>',
            '<div class="ranau-cdek-status" role="status" aria-live="polite"></div>',
            '<div class="ranau-cdek-points" role="list"></div>',
            '</div>'
        ].join('');
        document.body.appendChild(modal);
        state.modal = modal;
        modal.querySelector('.ranau-cdek-city').value = currentCity();
        modal.querySelector('.ranau-cdek-postcode').value = currentPostcode();
        return modal;
    }

    function setStatus(message, error) {
        var status = state.modal && state.modal.querySelector('.ranau-cdek-status');
        if (status) {
            status.textContent = message || '';
            status.classList.toggle('is-error', Boolean(error));
        }
    }

    function loadPoints() {
        if (state.loading) {
            return;
        }
        var modal = ensureModal();
        var city = String(modal.querySelector('.ranau-cdek-city').value || '').trim();
        var postcode = String(modal.querySelector('.ranau-cdek-postcode').value || '').trim();
        var list = modal.querySelector('.ranau-cdek-points');
        state.loading = true;
        list.innerHTML = '';
        setStatus('Загружаем актуальные пункты выдачи…', false);
        var url = (config.restUrl || '') + '/points?city=' + encodeURIComponent(city) + '&postcode=' + encodeURIComponent(postcode);
        fetch(url, {credentials: 'same-origin', headers: {'X-WP-Nonce': config.restNonce || ''}})
            .then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok) {
                        throw new Error(body.message || 'points_failed');
                    }
                    return body;
                });
            })
            .then(function (body) {
                var points = Array.isArray(body.points) ? body.points : [];
                if (!points.length) {
                    setStatus('В этом городе не найдено доступных ПВЗ.', false);
                    return;
                }
                setStatus('Найдено пунктов: ' + points.length, false);
                list.innerHTML = points.map(function (point) {
                    return '<button type="button" class="ranau-cdek-point" role="listitem" data-code="' + escapeHtml(point.code) + '"><strong>' + escapeHtml(point.name || point.code) + '</strong><span>' + escapeHtml(point.address) + '</span><small>' + escapeHtml(point.work_time || '') + '</small></button>';
                }).join('');
            })
            .catch(function (error) {
                setStatus(error.message === 'points_failed' ? 'Не удалось загрузить ПВЗ.' : error.message, true);
            })
            .finally(function () {
                state.loading = false;
            });
    }

    function selectPoint(code, button) {
        var selection = {code: code};
        var calculation;
        try {
            calculation = commitAdapter.begin(selection);
        } catch (error) {
            setStatus('Сначала выберите способ доставки CDEK.', true);
            return;
        }
        if (button) {
            button.disabled = true;
        }
        setStatus('Проверяем ПВЗ и рассчитываем стоимость…', false);
        fetch((config.restUrl || '') + '/selection', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.restNonce || ''},
            body: JSON.stringify(selection)
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error(body.message || 'selection_failed');
                }
                return body;
            });
        }).then(function (body) {
            return commitAdapter.commit(calculation, body.commit, body.quote).then(function () {
                state.point = body.point || {};
                state.point._ranau_committed = true;
                state.quote = body.quote || {};
                closeModal();
                scheduleRender();
            });
        }).catch(function (error) {
            setStatus(error.message === 'selection_failed' ? 'Не удалось выбрать ПВЗ.' : error.message, true);
        }).finally(function () {
            if (button) {
                button.disabled = false;
            }
        });
    }

    function clearSelection() {
        fetch((config.restUrl || '') + '/selection', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.restNonce || ''},
            body: JSON.stringify({code: ''})
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('clear_failed');
            }
            state.point = {};
            state.quote = {};
            if (window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function') {
                return window.wc.blocksCheckout.extensionCartUpdate({namespace: config.storeApiNamespace, data: {}});
            }
            if (window.jQuery) {
                window.jQuery(document.body).trigger('update_checkout');
            }
            return null;
        }).then(scheduleRender).catch(function () {});
    }

    function openModal() {
        var modal = ensureModal();
        modal.classList.add('is-open');
        document.body.classList.add('ranau-cdek-modal-open');
        loadPoints();
    }

    function closeModal() {
        if (state.modal) {
            state.modal.classList.remove('is-open');
        }
        document.body.classList.remove('ranau-cdek-modal-open');
    }

    document.addEventListener('click', function (event) {
        var open = event.target.closest('.ranau-cdek-open');
        var close = event.target.closest('.ranau-cdek-close');
        var clear = event.target.closest('.ranau-cdek-clear');
        var point = event.target.closest('.ranau-cdek-point');
        if (open) { event.preventDefault(); openModal(); }
        if (close) { event.preventDefault(); closeModal(); }
        if (clear) { event.preventDefault(); clearSelection(); }
        if (point) { event.preventDefault(); selectPoint(point.getAttribute('data-code') || '', point); }
        if (state.modal && event.target === state.modal) { closeModal(); }
    });

    document.addEventListener('submit', function (event) {
        if (event.target.matches('.ranau-cdek-search')) {
            event.preventDefault();
            loadPoints();
        }
    });
    document.addEventListener('change', scheduleRender, true);
    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', scheduleRender);
    }
    if (typeof MutationObserver === 'function') {
        new MutationObserver(scheduleRender).observe(document.body, {childList: true, subtree: true});
    }
    scheduleRender();
}(window, document));
