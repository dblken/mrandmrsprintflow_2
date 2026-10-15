(function () {
    'use strict';
    var active = null;
    var activeContainer = null;
    window.pfDashCancelSecondary = function () {
        if (active) active.abort();
        if (activeContainer) activeContainer.dataset.loading = 'false';
        active = null;
        activeContainer = null;
    };
    window.pfDashLoadSecondary = function (container) {
        if (!container || container.dataset.loading === 'true') return;
        window.pfDashCancelSecondary();
        var controller = new AbortController();
        active = controller;
        activeContainer = container;
        container.dataset.loading = 'true';
        // Allow the summary's first paint before starting secondary work.
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                if (controller.signal.aborted || !container.isConnected) return;
                fetch(container.dataset.url, {credentials: 'same-origin', signal: controller.signal})
                    .then(function (response) {
                        if (!response.ok || response.redirected) throw new Error('Dashboard details unavailable');
                        return response.text();
                    })
                    .then(function (html) {
                        if (controller.signal.aborted || !container.isConnected) return;
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        if (!doc.getElementById('pf-dashboard-branch-chart-data')) throw new Error('Invalid dashboard details');
                        container.innerHTML = html;
                        container.dataset.loaded = 'true';
                        container.setAttribute('aria-busy', 'false');
                        window.printflowInitDashboardCharts();
                    })
                    .catch(function (error) {
                        if (controller.signal.aborted || !container.isConnected) return;
                        container.textContent = 'Dashboard details could not be loaded. ';
                        var retry = document.createElement('button');
                        retry.type = 'button';
                        retry.textContent = 'Retry';
                        retry.onclick = function () { window.pfDashLoadSecondary(container); };
                        container.appendChild(retry);
                        container.setAttribute('aria-busy', 'false');
                    })
                    .finally(function () {
                        if (active === controller) {
                            container.dataset.loading = 'false';
                            active = null;
                            activeContainer = null;
                        }
                    });
            });
        });
    };
})();
