(function () {
    'use strict';

    if (window.PrintFlowUrgentCustomizations) {
        window.PrintFlowUrgentCustomizations.refresh();
        return;
    }

    const script = document.currentScript;
    const base = String(script?.dataset.baseUrl || window.PFConfig?.basePath || window.baseUrl || '').replace(/\/$/, '');
    const source = String(script?.dataset.source || 'online');
    let flight = null;
    let timer = null;
    let currentCount = 0;

    function render(count) {
        currentCount = Math.max(0, Number(count) || 0);
        document.querySelectorAll('[data-urgent-customizations-badge]').forEach(badge => {
            const visible = currentCount > 0;
            badge.textContent = visible ? (currentCount > 99 ? '99+' : String(currentCount)) : '';
            badge.setAttribute('aria-hidden', visible ? 'false' : 'true');
            if (badge.dataset.urgentBadgeMode === 'visibility') {
                badge.style.visibility = visible ? 'visible' : 'hidden';
            } else {
                badge.style.display = visible ? (badge.dataset.urgentBadgeDisplay || 'inline-flex') : 'none';
            }
        });
        window.dispatchEvent(new CustomEvent('printflow:urgent-customizations-count', {
            detail: { count: currentCount }
        }));
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(refresh, document.hidden ? 45000 : 20000);
    }

    async function refresh() {
        if (flight) return flight;
        const endpoint = base + '/admin/job_orders_api.php?action=customization_counts&source=' + encodeURIComponent(source);
        flight = fetch(endpoint, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        }).then(response => response.ok ? response.json() : null)
            .then(result => {
                if (result?.success && result.data) {
                    render(Number(result.data.URGENT || 0));
                }
            })
            .catch(() => {})
            .finally(() => {
                flight = null;
                schedule();
            });
        return flight;
    }

    window.PrintFlowUrgentCustomizations = { refresh, set: render, get: () => currentCount };
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
    });
    window.addEventListener('focus', refresh);
    render(Number(script?.dataset.initialCount || 0));
    refresh();
})();
