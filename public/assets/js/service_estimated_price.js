/**
 * Shared Estimated Price calculation for service dynamic fields (Customer + POS).
 * Uses data-price on options; does not parse option label text.
 */
(function (window) {
    'use strict';

    function parsePrice(value) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : 0;
    }

    function formatPeso(amount) {
        const n = Number.isFinite(amount) ? amount : 0;
        return '₱' + n.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function isDesignFeeField(field) {
        if (!field) return false;
        if (field.hasAttribute && field.hasAttribute('data-design-fee-field')) return true;

        // The Admin field key/label is authoritative. Keep layout options out
        // of per-unit option totals even if an older/custom renderer omitted
        // the presentation marker.
        const key = String(
            (field.getAttribute && (field.getAttribute('data-field-key') || field.getAttribute('name'))) || ''
        ).toLowerCase();
        if (key.includes('layout')) return true;

        const row = field.closest && field.closest('.shopee-form-row');
        const label = row && row.querySelector && row.querySelector('.pf-field-label-text, .shopee-form-label');
        return !!(label && String(label.textContent || label.innerText || '').toLowerCase().includes('layout'));
    }

    function calculateOptionsTotal(scope) {
        let optionsTotal = 0;
        if (!scope) {
            return optionsTotal;
        }

        scope.querySelectorAll('input[type="radio"].pricing-field:checked').forEach(function (radio) {
            if (isDesignFeeField(radio)) return;
            optionsTotal += parsePrice(radio.getAttribute('data-price') || 0);
        });

        scope.querySelectorAll('select.pricing-field').forEach(function (select) {
            if (isDesignFeeField(select)) return;
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.value) {
                optionsTotal += parsePrice(selectedOption.getAttribute('data-price') || 0);
            }
        });

        const activeDimensionBtn = scope.querySelector('button.shopee-opt-btn.pricing-field.active[data-price]');
        if (activeDimensionBtn) {
            optionsTotal += parsePrice(activeDimensionBtn.getAttribute('data-price') || 0);
        }

        scope.querySelectorAll('.nested-fields-container').forEach(function (container) {
            const cs = window.getComputedStyle(container);
            if (cs.display === 'none' || cs.visibility === 'hidden') {
                return;
            }
            if (!container.offsetParent) {
                return;
            }

            container.querySelectorAll('select').forEach(function (sel) {
                if (isDesignFeeField(sel)) return;
                const opt = sel.options[sel.selectedIndex];
                if (opt && opt.value) {
                    optionsTotal += parsePrice(opt.getAttribute('data-price') || 0);
                }
            });
            container.querySelectorAll('input[type="radio"]:checked').forEach(function (radio) {
                if (isDesignFeeField(radio)) return;
                optionsTotal += parsePrice(radio.getAttribute('data-price') || 0);
            });
            container.querySelectorAll('.shopee-opt-group').forEach(function (grp) {
                const btn = grp.querySelector('button.shopee-opt-btn.active[data-price]');
                if (btn) {
                    optionsTotal += parsePrice(btn.getAttribute('data-price') || 0);
                }
            });
        });

        return optionsTotal;
    }

    function calculateDesignFee(scope) {
        if (!scope) return 0;
        const fields = scope.querySelectorAll('select, input[type="radio"]');
        for (const field of fields) {
            if (!isDesignFeeField(field)) continue;
            let selectedValue = '';
            let selectedOption = null;
            if (field.matches('select')) {
                selectedOption = field.options[field.selectedIndex] || null;
                selectedValue = selectedOption ? String(selectedOption.value || '') : '';
            } else if (field.matches('input[type="radio"]:checked')) {
                selectedValue = String(field.value || '');
                selectedOption = field;
            }
            if (!selectedValue) continue;
            const normalized = selectedValue.toLowerCase().replace(/[^a-z0-9]+/g, '');
            if (normalized.includes('withlayout') && !normalized.includes('without')) return 0;
            if (!normalized.includes('withoutlayout')) continue;
            // A missing configured value is a zero fee. The server remains
            // authoritative at cart/checkout; the browser never invents a
            // fallback amount.
            return selectedOption && selectedOption.hasAttribute('data-price')
                ? Math.max(0, parsePrice(selectedOption.getAttribute('data-price')))
                : 0;
        }
        return 0;
    }

    /**
     * @param {HTMLElement} container Root scope for field queries
     * @param {object} options
     * @returns {{recalculate: Function, destroy: Function}}
     */
    function printflowInitServiceEstimatedPrice(container, options) {
        options = options || {};
        const scope = options.form || container;
        const basePrice = parsePrice(options.basePrice || 0);
        const estimatedTotalEl = options.estimatedTotalEl || (scope ? scope.querySelector('#estimated-total') : null);
        const qtyDisplayEl = options.qtyDisplayEl || (scope ? scope.querySelector('#qty-display') : null);
        const unitPriceInputEl = options.unitPriceInputEl || (scope ? scope.querySelector('#calculated-unit-price') : null);
        const estimatedPriceInputEl = options.estimatedPriceInputEl || (scope ? scope.querySelector('#calculated-estimated-price') : null);
        const designFeeInputEl = options.designFeeInputEl || (scope ? scope.querySelector('#calculated-design-fee') : null);
        const ac = new AbortController();

        function recalculate() {
            const optionsTotal = calculateOptionsTotal(scope);
            const designFee = calculateDesignFee(scope);
            const qtyInput = scope ? scope.querySelector('.pf-service-quantity-input') : null;
            const rawQuantity = qtyInput ? String(qtyInput.value) : '1';
            const quantity = /^[0-9]+$/.test(rawQuantity) ? Number(rawQuantity) : NaN;
            const quantityValid = Number.isSafeInteger(quantity) && quantity >= 1 && quantity <= 100;
            const unitPrice = basePrice + optionsTotal;

            if (!quantityValid) {
                // An empty or malformed edit is temporary UI state, not quantity 1.
                // Keep the estimate visibly pending and clear the submitted total
                // until the user enters a valid quantity.
                if (estimatedTotalEl) estimatedTotalEl.textContent = '—';
                if (qtyDisplayEl) qtyDisplayEl.textContent = '';
                if (unitPriceInputEl) unitPriceInputEl.value = unitPrice.toFixed(2);
                if (estimatedPriceInputEl) estimatedPriceInputEl.value = '';
                if (designFeeInputEl) designFeeInputEl.value = designFee.toFixed(2);
                return { unitPrice: unitPrice, estimatedTotal: null, quantity: null, designFee: designFee };
            }

            const safeQty = quantity;
            const estimatedTotal = Math.round((unitPrice * safeQty + designFee) * 100) / 100;

            if (estimatedTotalEl) {
                estimatedTotalEl.textContent = formatPeso(estimatedTotal);
            }
            if (qtyDisplayEl) {
                qtyDisplayEl.textContent = String(safeQty);
            }
            if (unitPriceInputEl) {
                unitPriceInputEl.value = unitPrice.toFixed(2);
            }
            if (estimatedPriceInputEl) {
                estimatedPriceInputEl.value = estimatedTotal.toFixed(2);
            }
            if (designFeeInputEl) designFeeInputEl.value = designFee.toFixed(2);

            return {
                unitPrice: unitPrice,
                estimatedTotal: estimatedTotal,
                quantity: safeQty,
                designFee: designFee
            };
        }

        window.calculateEstimatedPrice = recalculate;

        if (scope) {
            scope.addEventListener('change', recalculate, { signal: ac.signal });
            scope.addEventListener('input', function (e) {
                if (e.target && e.target.classList && e.target.classList.contains('pf-service-quantity-input')) {
                    recalculate();
                }
            }, { signal: ac.signal });
        }

        recalculate();

        return {
            recalculate: recalculate,
            destroy: function () {
                ac.abort();
                if (window.calculateEstimatedPrice === recalculate) {
                    window.calculateEstimatedPrice = null;
                }
            }
        };
    }

    window.printflowInitServiceEstimatedPrice = printflowInitServiceEstimatedPrice;
    window.printflowCalculateServiceOptionsTotal = calculateOptionsTotal;
    window.printflowCalculateServiceDesignFee = calculateDesignFee;
    window.printflowFormatServiceEstimatedPeso = formatPeso;
})(window);
