(function () {
    'use strict';

    function sanitize(raw) {
        var v = String(raw || '').replace(/[^\d.]/g, '');
        var parts = v.split('.');
        if (parts.length > 2) {
            v = parts.shift() + '.' + parts.join('');
        }
        if (v.indexOf('.') === 0) {
            v = '0' + v;
        }
        return v;
    }

    function panelMax(panel) {
        if (!panel) return 100;
        var max = parseFloat(panel.getAttribute('data-dimension-max') || '100');
        return isNaN(max) ? 100 : max;
    }

    function panelUnit(panel) {
        if (!panel) return 'ft';
        return panel.getAttribute('data-dimension-unit') || 'ft';
    }

    function validatePanel(panel, showError) {
        if (!panel || panel.style.display === 'none') {
            return { ok: true };
        }
        var wEl = panel.querySelector('.custom-dim-width, .pf-custom-size-width');
        var hEl = panel.querySelector('.custom-dim-height, .pf-custom-size-height');
        var w = sanitize(wEl ? wEl.value : '');
        var h = sanitize(hEl ? hEl.value : '');
        var errEl = panel.querySelector('.pf-custom-size-error');
        var max = panelMax(panel);
        var unitShort = panelUnit(panel);
        var message = '';
        if (!w || !h) {
            message = 'Please enter width and height for your custom size.';
        } else if (parseFloat(w) <= 0 || parseFloat(h) <= 0) {
            message = 'Width and height must be positive numbers.';
        } else if (parseFloat(w) > max || parseFloat(h) > max) {
            message = 'Maximum allowed size is ' + max + ' ' + unitShort + '.';
        }
        if (message && showError && errEl) {
            errEl.textContent = message;
            errEl.hidden = false;
        } else if (errEl) {
            errEl.textContent = '';
            errEl.hidden = true;
        }
        return message ? { ok: false, message: message } : { ok: true };
    }

    function bindPanel(panel) {
        if (!panel || panel.dataset.pfCustomSizeBound === '1') return;
        panel.dataset.pfCustomSizeBound = '1';
        panel.querySelectorAll('.custom-dim-width, .custom-dim-height, .pf-custom-size-width, .pf-custom-size-height').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = sanitize(input.value);
                validatePanel(panel, true);
                if (typeof panel.pfOnInput === 'function') {
                    panel.pfOnInput();
                }
            });
        });
    }

    function init(root) {
        (root || document).querySelectorAll('.pf-custom-size-panel').forEach(bindPanel);
    }

    window.pfCustomSize = {
        sanitize: sanitize,
        validatePanel: validatePanel,
        bindPanel: bindPanel,
        init: init
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
})();
