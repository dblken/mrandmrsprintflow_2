(function () {
    'use strict';

    if (window.PrintFlowChatUnread) {
        window.PrintFlowChatUnread.refresh();
        return;
    }

    const script = document.currentScript;
    const base = String(script?.dataset.baseUrl || window.PFConfig?.basePath || window.baseUrl || '').replace(/\/$/, '');
    let flight = null;
    let timer = null;
    let currentCount = 0;

    function render(count) {
        currentCount = Math.max(0, Number(count) || 0);
        document.querySelectorAll('[data-chat-unread-badge]').forEach(badge => {
            const visible = currentCount > 0;
            badge.textContent = visible ? (currentCount > 99 ? '99+' : String(currentCount)) : '';
            badge.setAttribute('aria-hidden', visible ? 'false' : 'true');
            if (badge.dataset.chatBadgeMode === 'visibility') {
                badge.style.visibility = visible ? 'visible' : 'hidden';
            } else {
                badge.style.display = visible ? (badge.dataset.chatBadgeDisplay || 'inline-flex') : 'none';
            }
        });
    }

    function schedule() {
        clearTimeout(timer);
        if (window.PrintFlowChatConfig?.role) return;
        timer = setTimeout(refresh, document.hidden ? 45000 : 15000);
    }

    async function refresh() {
        if (flight) return flight;
        flight = fetch(base + '/public/api/chat/unread_count.php', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        }).then(response => response.ok ? response.json() : null)
          .then(data => { if (data?.success) render(data.unread_count); })
          .catch(() => {})
          .finally(() => { flight = null; schedule(); });
        return flight;
    }

    window.PrintFlowChatUnread = { refresh, set: render, get: () => currentCount };
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
        else schedule();
    });
    window.addEventListener('focus', refresh);
    render(Number(script?.dataset.initialCount || 0));
    schedule();
})();
