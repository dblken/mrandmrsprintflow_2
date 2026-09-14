(function () {
    'use strict';

    var MODAL_SELECTORS = [
        '.modal-overlay',
        '.modal-backdrop',
        '.pf-staff-modal-backdrop',
        '[data-pf-staff-modal="true"]'
    ].join(',');

    function overlayVisible(el) {
        if (!el || !el.isConnected) {
            return false;
        }
        var style = window.getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
            return false;
        }
        return el.getClientRects().length > 0;
    }

    function hasOpenModal() {
        var nodes = document.querySelectorAll(MODAL_SELECTORS);
        for (var i = 0; i < nodes.length; i++) {
            if (overlayVisible(nodes[i])) {
                return true;
            }
        }
        return false;
    }

    function syncBodyModalState() {
        var open = hasOpenModal();
        document.body.classList.toggle('pf-modal-open', open);
        document.documentElement.classList.toggle('pf-modal-open', open);
        if (open) {
            document.body.style.overflow = document.body.dataset.pfModalPrevOverflow || 'hidden';
        } else if (document.body.dataset.pfModalPrevOverflow !== undefined) {
            document.body.style.overflow = document.body.dataset.pfModalPrevOverflow;
        }
    }

    function rememberOverflow() {
        if (document.body.dataset.pfModalPrevOverflow === undefined) {
            document.body.dataset.pfModalPrevOverflow = document.body.style.overflow || '';
        }
    }

    function init() {
        rememberOverflow();
        syncBodyModalState();

        if (window.MutationObserver) {
            var observer = new MutationObserver(function () {
                syncBodyModalState();
            });
            observer.observe(document.body, {
                subtree: true,
                childList: true,
                attributes: true,
                attributeFilter: ['style', 'class', 'x-cloak']
            });
        }

        window.addEventListener('resize', syncBodyModalState);
        document.addEventListener('click', function () {
            window.requestAnimationFrame(syncBodyModalState);
        }, true);
        setInterval(syncBodyModalState, 500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
