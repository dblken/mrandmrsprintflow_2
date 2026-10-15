(function () {
    'use strict';
    var active = null;
    var activeContainer = null;
    var branchChartsActive = null;
    window.pfDashCancelSecondary = function () {
        if (active) active.abort();
        if (activeContainer) activeContainer.dataset.loading = 'false';
        active = null;
        activeContainer = null;
    };
    window.pfDashCancelBranchCharts = function () {
        if (branchChartsActive) branchChartsActive.abort();
        branchChartsActive = null;
    };
    function pfDashMergeBranchSalesCharts(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var incoming = doc.getElementById('pf-dashboard-branch-chart-data');
        var target = document.getElementById('pf-dashboard-branch-chart-data');
        if (!incoming || !target) return false;
        var current = { products: [], services: [], statuses: [], locations: [] };
        var added = { products: [], services: [], statuses: [], locations: [] };
        try {
            current = JSON.parse(target.textContent || '{}');
        } catch (e) {}
        try {
            added = JSON.parse(incoming.textContent || '{}');
        } catch (e) {}
        if (!current || typeof current !== 'object') current = { products: [], services: [], statuses: [], locations: [] };
        current.products = added.products || [];
        current.services = added.services || [];
        target.textContent = JSON.stringify(current);
        return true;
    }
    window.pfDashLoadDeferredBranchCharts = function pfDashLoadDeferredBranchCharts(container) {
        var deferUrl = container && container.dataset ? container.dataset.deferBranchChartsUrl : '';
        if (!deferUrl || container.dataset.deferBranchChartsLoading === 'true') return;
        container.dataset.deferBranchChartsLoading = 'true';
        window.pfDashCancelBranchCharts();
        var controller = new AbortController();
        branchChartsActive = controller;
        fetch(deferUrl, { credentials: 'same-origin', signal: controller.signal })
            .then(function (response) {
                if (!response.ok || response.redirected) throw new Error('Branch chart details unavailable');
                return response.text();
            })
            .then(function (html) {
                if (controller.signal.aborted || !container.isConnected) return;
                if (!pfDashMergeBranchSalesCharts(html)) return;
                delete container.dataset.deferBranchChartsUrl;
                if (typeof window.printflowInitDashboardCharts === 'function') {
                    window.printflowInitDashboardCharts();
                }
            })
            .catch(function (error) {
                if (controller.signal.aborted || !container.isConnected) return;
                console.error('Deferred branch sales charts failed:', error);
            })
            .finally(function () {
                if (container.isConnected) container.dataset.deferBranchChartsLoading = 'false';
                if (branchChartsActive === controller) branchChartsActive = null;
            });
    };
    window.pfDashLoadSecondary = function (container) {
        if (!container || container.dataset.loading === 'true') return;
        window.pfDashCancelSecondary();
        window.pfDashCancelBranchCharts();
        var controller = new AbortController();
        active = controller;
        activeContainer = container;
        container.dataset.loading = 'true';
        // Allow the summary's first paint before starting secondary work.
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                if (controller.signal.aborted || !container.isConnected) return;
                fetch(container.dataset.url, { credentials: 'same-origin', signal: controller.signal })
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
                        if (typeof window.printflowInitDashboardCharts === 'function') {
                            window.printflowInitDashboardCharts();
                        }
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
