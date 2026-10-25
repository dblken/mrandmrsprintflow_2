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

    function calculateOptionsTotal(scope) {
        let optionsTotal = 0;
        if (!scope) {
            return optionsTotal;
        }

        scope.querySelectorAll('input[type="radio"].pricing-field:checked').forEach(function (radio) {
            if (radio.hasAttribute('data-design-fee-field')) return;
            optionsTotal += parsePrice(radio.getAttribute('data-price') || 0);
        });

        scope.querySelectorAll('select.pricing-field').forEach(function (select) {
            if (select.hasAttribute('data-design-fee-field')) return;
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
                const opt = sel.options[sel.selectedIndex];
                if (opt && opt.value) {
                    optionsTotal += parsePrice(opt.getAttribute('data-price') || 0);
                }
            });
            container.querySelectorAll('input[type="radio"]:checked').forEach(function (radio) {
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
        const fields = scope.querySelectorAll('[data-design-fee-field]');
        for (const field of fields) {
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
            if (selectedOption && selectedOption.hasAttribute('data-design-fee-configured')) {
                return Math.max(0, parsePrice(selectedOption.getAttribute('data-price')));
            }
            return 200;
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
            const quantity = parseInt(qtyInput && qtyInput.value ? qtyInput.value : '1', 10);
            const safeQty = Number.isFinite(quantity) && quantity > 0 ? quantity : 1;
            const unitPrice = basePrice + optionsTotal;
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
