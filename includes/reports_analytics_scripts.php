<?php
/**
 * Reports & Analytics — chart bootstrap (include before </body> so Turbo Drive re-executes it).
 * Expects the same variables as admin/reports.php in local scope.
 */
if (!isset($branch_empty)) {
    return;
}
?>
<script>
window.__pfReportsApexCharts = window.__pfReportsApexCharts || [];
window.__pfReportsData = <?php echo json_encode($dashData); ?>;
var __pfReportsChartRootIds = ['salesChart','ch-forecast','ch-products','ch-donut','ch-custom','ch-status'];
window.printflowDisconnectReportsChartLayoutHooks = function () {
    if (window.__pfReportsRevealIO) {
        try { window.__pfReportsRevealIO.disconnect(); } catch (e) {}
        window.__pfReportsRevealIO = null;
    }
    if (window.__pfReportsChartIO) {
        try { window.__pfReportsChartIO.disconnect(); } catch (e) {}
        window.__pfReportsChartIO = null;
    }
    if (window.__pfReportsLayoutResizeHandler) {
        try { window.removeEventListener('resize', window.__pfReportsLayoutResizeHandler); } catch (e) {}
        window.__pfReportsLayoutResizeHandler = null;
    }
    if (window.__pfReportsScrollSettledHandler) {
        var mc2 = document.querySelector('.main-content');
        if (mc2) {
            try { mc2.removeEventListener('scroll', window.__pfReportsScrollSettledHandler); } catch (e) {}
        }
        window.__pfReportsScrollSettledHandler = null;
    }
    if (window.__pfReportsMainRO) {
        try { window.__pfReportsMainRO.disconnect(); } catch (e) {}
        window.__pfReportsMainRO = null;
    }
    if (window.__pfReportsLayoutTimer) {
        try { clearTimeout(window.__pfReportsLayoutTimer); } catch (e) {}
        window.__pfReportsLayoutTimer = null;
    }
    if (window.__pfReportsScrollSettleTimer) {
        try { clearTimeout(window.__pfReportsScrollSettleTimer); } catch (e) {}
        window.__pfReportsScrollSettleTimer = null;
    }
    if (window.__pfReportsProductsRO) {
        try { window.__pfReportsProductsRO.disconnect(); } catch (e) {}
        window.__pfReportsProductsRO = null;
    }
};
window.printflowResizeAllReportsCharts = function () {
    (window.__pfReportsApexCharts || []).forEach(function (c) {
        try {
            if (c && typeof c.resize === 'function') c.resize();
        } catch (e) {}
    });
    ;['__pfReportsProductCategoryChart', '__pfReportsServiceCategoryChart', '__pfDashSalesChart'].forEach(function (ref) {
        var ch = window[ref];
        if (ch && typeof ch.resize === 'function') {
            try { ch.resize(); } catch (e2) {}
        }
    });
};
window.printflowAttachReportsChartLayoutHooks = function () {
    if (!document.getElementById('reportsFilterForm')) return;
    window.printflowDisconnectReportsChartLayoutHooks();
    function debouncedLayoutResize() {
        if (window.__pfReportsLayoutTimer) clearTimeout(window.__pfReportsLayoutTimer);
        window.__pfReportsLayoutTimer = setTimeout(function () {
            window.__pfReportsLayoutTimer = null;
            window.printflowResizeAllReportsCharts();
        }, 240);
    }
    window.__pfReportsLayoutResizeHandler = debouncedLayoutResize;
    window.addEventListener('resize', debouncedLayoutResize);
    var mainEl = document.querySelector('.main-content');
    if (mainEl && typeof ResizeObserver !== 'undefined') {
        window.__pfReportsMainRO = new ResizeObserver(function () {
            debouncedLayoutResize();
        });
        window.__pfReportsMainRO.observe(mainEl);
    }
    pfWireReportsScrollReveal();
};
window.printflowTeardownReportsCharts = function () {
    window.printflowDisconnectReportsChartLayoutHooks();
    if (window.__pfReportsTrendChart) {
        try { window.__pfReportsTrendChart.destroy(); } catch (e) {}
        window.__pfReportsTrendChart = null;
    }
    // Destroy dashboard sales chart
    if (window.__pfDashSalesChart) {
        try { window.__pfDashSalesChart.destroy(); } catch (e) {}
        window.__pfDashSalesChart = null;
    }
    if (window.__pfReportsProductCategoryChart) {
        try { window.__pfReportsProductCategoryChart.destroy(); } catch (e) {}
        window.__pfReportsProductCategoryChart = null;
    }
    if (window.__pfReportsServiceCategoryChart) {
        try { window.__pfReportsServiceCategoryChart.destroy(); } catch (e) {}
        window.__pfReportsServiceCategoryChart = null;
    }
    window.__pfReportsChartQueue = [];
    document.querySelectorAll('.ch-box[data-pf-chart-revealed]').forEach(function (b) {
        b.removeAttribute('data-pf-chart-revealed');
    });
    document.querySelectorAll('.ch-box.pf-chart-reveal-done').forEach(function (b) {
        b.classList.remove('pf-chart-reveal-done');
    });
    (window.__pfReportsApexCharts || []).forEach(function (c) {
        try {
            if (c && typeof c.destroy === 'function') c.destroy();
        } catch (e) {}
    });
    window.__pfReportsApexCharts = [];
    __pfReportsChartRootIds.forEach(function (id) {
        var n = document.getElementById(id);
        if (n) {
            n.innerHTML = '';
            n.dataset.pfApexInitialized = '0';
        }
    });
    // Clear dashboard sales chart canvas
    var dashCanvas = document.getElementById('dashSalesChart');
    if (dashCanvas) {
        dashCanvas.dataset.pfChartInitialized = '0';
        var ctx = dashCanvas.getContext('2d');
        if (ctx) ctx.clearRect(0, 0, dashCanvas.width, dashCanvas.height);
    }
    document.querySelectorAll('.ch-box.pf-chart-loading').forEach(function (box) {
        box.classList.remove('pf-chart-loading');
        box.removeAttribute('aria-busy');
    });
};
window.__pfReportsChartQueue = window.__pfReportsChartQueue || [];
function pfNormalizeApexChartOptions(opts) {
    if (!opts || typeof opts !== 'object') return opts;
    opts.tooltip = opts.tooltip || {};
    opts.tooltip.fixed = Object.assign({ enabled: true }, opts.tooltip.fixed || {});
    return opts;
}
function pfExecuteApexReveal(entry, delayMs) {
    if (!entry || entry.rendered) return;
    var host = entry.host;
    var el = entry.el;
    var options = pfNormalizeApexChartOptions(entry.options);
    var run = function () {
        if (!el || !el.isConnected) {
            if (host && host.isConnected) {
                host.removeAttribute('data-pf-chart-revealed');
                host.classList.remove('pf-chart-loading');
                host.removeAttribute('aria-busy');
            }
            return;
        }
        var ch;
        try {
            ch = new ApexCharts(el, options);
        } catch (e) {
            if (host && host.isConnected) {
                host.removeAttribute('data-pf-chart-revealed');
                host.classList.remove('pf-chart-loading');
                host.removeAttribute('aria-busy');
            }
            return;
        }
        entry.rendered = true;
        window.__pfReportsApexCharts.push(ch);
        var done = function () {
            if (!el.isConnected) {
                try {
                    if (ch && typeof ch.destroy === 'function') ch.destroy();
                } catch (e2) {}
                return;
            }
            if (host && host.isConnected) {
                host.classList.remove('pf-chart-loading');
                host.removeAttribute('aria-busy');
                host.classList.add('pf-chart-reveal-done');
            }
            requestAnimationFrame(function () {
                try {
                    if (el.isConnected && ch && typeof ch.resize === 'function') ch.resize();
                } catch (e3) {}
            });
        };
        try {
            var pr = ch.render();
            if (pr && typeof pr.then === 'function') {
                pr.then(done).catch(done);
            } else {
                requestAnimationFrame(done);
            }
        } catch (e) {
            done();
        }
    };
    if (delayMs > 0) {
        setTimeout(run, delayMs);
    } else {
        requestAnimationFrame(run);
    }
}
function pfWireReportsScrollReveal() {
    var q = window.__pfReportsChartQueue || [];
    if (!q.length) return;
    var pendingByHost = new Map();
    var orphan = [];
    var stagger = 0;
    q.forEach(function (item) {
        if (item.rendered) return;
        if (item.host) {
            pendingByHost.set(item.host, item);
        } else {
            orphan.push(item);
        }
    });
    orphan.forEach(function (item) {
        pfExecuteApexReveal(item, stagger);
        stagger += 130;
    });
    if (!pendingByHost.size) {
        return;
    }
    if (typeof IntersectionObserver === 'undefined') {
        pendingByHost.forEach(function (item) {
            pfExecuteApexReveal(item, stagger);
            stagger += 130;
        });
        return;
    }
    var scrollRoot = document.querySelector('.main-content');
    if (window.__pfReportsRevealIO) {
        try { window.__pfReportsRevealIO.disconnect(); } catch (e) {}
    }
    window.__pfReportsRevealIO = new IntersectionObserver(function (entries) {
        var local = 0;
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            var host = entry.target;
            if (!host || !host.isConnected) return;
            if (host.getAttribute('data-pf-chart-revealed') === '1') return;
            var item = pendingByHost.get(host);
            if (!item || item.rendered || !item.el || !item.el.isConnected) return;
            host.setAttribute('data-pf-chart-revealed', '1');
            pfExecuteApexReveal(item, local * 135);
            local += 1;
        });
    }, { root: scrollRoot || null, threshold: 0, rootMargin: '0px 0px -6% 0px' });
    pendingByHost.forEach(function (item, host) {
        window.__pfReportsRevealIO.observe(host);
    });
}
function pfPushApexChart(el, options) {
    if (!el || !el.isConnected) return;
    
    // Prevent multiple initializations
    if (el.dataset.pfApexInitialized === '1') {
        console.log('[PrintFlow] ApexChart already initialized for element:', el.id);
        return;
    }
    
    var host = el.closest('.ch-box');
    if (host) {
        host.classList.add('pf-chart-loading');
        host.setAttribute('aria-busy', 'true');
    }
    el.innerHTML = '';
    // Render immediately — no IntersectionObserver delay for independent charts
    requestAnimationFrame(function() {
        if (!el.isConnected) return;
        
        // Double-check initialization flag inside requestAnimationFrame
        if (el.dataset.pfApexInitialized === '1') {
            console.log('[PrintFlow] ApexChart initialization prevented (already initialized):', el.id);
            if (host) { host.classList.remove('pf-chart-loading'); host.removeAttribute('aria-busy'); }
            return;
        }
        
        var ch;
        try {
            var normalizedOpts = pfNormalizeApexChartOptions(options);
            ch = new ApexCharts(el, normalizedOpts);
            el.dataset.pfApexInitialized = '1';
        } catch(e) {
            if (host) { host.classList.remove('pf-chart-loading'); host.removeAttribute('aria-busy'); }
            return;
        }
        window.__pfReportsApexCharts.push(ch);
        var done = function() {
            if (host && host.isConnected) {
                host.classList.remove('pf-chart-loading');
                host.removeAttribute('aria-busy');
                host.classList.add('pf-chart-reveal-done');
            }
            requestAnimationFrame(function() {
                try { if (el.isConnected && ch && typeof ch.resize === 'function') ch.resize(); } catch(e2) {}
            });
        };
        try {
            var pr = ch.render();
            if (pr && typeof pr.then === 'function') { pr.then(done).catch(done); } else { requestAnimationFrame(done); }
        } catch(e) { done(); }
    });
}

window.__pfReportsUpdateTimer = null;
let isDashboardFetching = false;
window.fetchUpdatedDashboard = async function(overrides = {}) {
    if (isDashboardFetching) return;
    
    const container = document.getElementById('pf-reports-dashboard-container');
    const summary   = document.getElementById('pf-reports-toolbar-summary');
    const form      = document.getElementById('reportsFilterForm');
    if (!container || !form) return;

    isDashboardFetching = true;
    // Show loading state
    container.style.opacity = '0.5';
    container.style.pointerEvents = 'none';

    try {
        const formData = new FormData(form);
        const params = new URLSearchParams(formData);
        
        // Ensure ajax=1 is set
        params.set('ajax', '1');
        
        // Apply overrides if any (this allows Sort and Txn Tabs to pass their values)
        for (const [k, v] of Object.entries(overrides)) {
            if (v === null) params.delete(k);
            else {
                params.set(k, v);
                // Also update the hidden form fields if they exist so the next 'Apply' pick them up
                if (form.elements[k]) {
                    form.elements[k].value = v;
                }
            }
        }
        
        // Add cache buster
        params.set('_', Date.now());

        const url = window.location.pathname + '?' + params.toString();
        console.log('[PrintFlow] Fetching dashboard update:', url);
        
        const resp = await fetch(url, { credentials: 'same-origin' });
        const data = await resp.json();
        console.log('[PrintFlow] Dashboard data received:', data);

        if (data.success) {
            // Teardown old charts
            if (window.printflowTeardownReportsCharts) {
                window.printflowTeardownReportsCharts();
            }

            // Replace HTML
            container.innerHTML = data.html;
            
            // Update Summary (All Branches · Date Range)
            if (summary && data.summary) {
                summary.innerHTML = data.summary;
            }

            // Update URL without reload
            const cleanUrl = url.replace('&ajax=1', '').replace('ajax=1&', '').replace('ajax=1', '');
            window.history.replaceState({}, '', cleanUrl);

            // Synchronize Alpine state if data-panel exists
            const panelEl = document.querySelector('[x-data^="reportsFilterPanel"]');
            if (panelEl && window.Alpine) {
                const alpineData = window.Alpine.$data(panelEl);
                if (alpineData) {
                    if (data.activePreset !== undefined) alpineData.selectedPreset = data.activePreset;
                }
            }

            // Update global data for chart re-init (Single Source of Truth)
            if (data.dashData) {
                window.__pfReportsData = data.dashData;
            }

            // Re-init charts
            if (window.printflowInitReportsCharts) {
                window.printflowInitReportsCharts();
            }
            
            // Re-bind incidental JS (tooltip, etc)
            if (window.printflowInitReportsPage) {
                window.printflowInitReportsPage();
            }
            
            // Scroll to transactions if specified
            if (overrides.txn_pay !== undefined) {
                const ts = document.getElementById('recent-transactions');
                if (ts) ts.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    } catch (e) {
        console.error('[PrintFlow] Dashboard update failed:', e);
    } finally {
        container.style.opacity = '1';
        container.style.pointerEvents = 'auto';
        isDashboardFetching = false;
    }
};

window.debouncedUpdateDashboard = function(delay = 500) {
    if (window.__pfReportsUpdateTimer) clearTimeout(window.__pfReportsUpdateTimer);
    window.__pfReportsUpdateTimer = setTimeout(() => {
        window.fetchUpdatedDashboard();
    }, delay);
};

function reportsFilterPanel(initialPreset = '') {
    const defFrom = '<?php echo date('Y-m-d', strtotime('-30 days')); ?>';
    const defTo   = '<?php echo date('Y-m-d'); ?>';
    return {
        filterOpen: false,
        sortOpen: false,
        selectedPreset: initialPreset,
        get hasActiveFilters() {
            const f = document.getElementById('fp_from')?.value || '';
            const t = document.getElementById('fp_to')?.value || '';
            
            // Calculate dynamic defaults
            const now = new Date();
            const d30 = new Date(now);
            d30.setDate(d30.getDate() - 30);
            const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
            
            const defFrom = fmt(d30);
            const defTo   = fmt(now);

            // Active if dates differ from 30-day default OR if not empty (empty usually means All Time, which is also an 'active' filter vs default)
            if (f === '' && t === '') return true; // All Time is active
            return (f !== defFrom) || (t !== defTo);
        },
        get filterCount() {
            return this.hasActiveFilters ? 1 : 0;
        },
        resetDateRange() {
            this.resetFilters();
        },
        resetFilters() {
            // Restore to system 30-day default
            const now = new Date();
            const d30 = new Date(now);
            d30.setDate(d30.getDate() - 30);
            const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
            
            const f = document.getElementById('fp_from');
            const t = document.getElementById('fp_to');
            if (f) f.value = fmt(d30);
            if (t) t.value = fmt(now);
            this.selectedPreset = 'last_30';
            
            // Reset hidden preference fields
            const form = document.getElementById('reportsFilterForm');
            if (form) {
                if (form.chart_sort) form.chart_sort.value = 'value_desc';
                if (form.trend_metric) form.trend_metric.value = 'both';
                if (form.txn_pay) form.txn_pay.value = 'all';
                window.fetchUpdatedDashboard();
            }
        },
        setPreset(preset) {
            const today = new Date();
            let from, to;
            if (preset === 'last_7') {
                to = new Date(today);
                from = new Date(today);
                from.setDate(from.getDate() - 7);
            } else if (preset === 'last_30') {
                to = new Date(today);
                from = new Date(today);
                from.setDate(from.getDate() - 30);
            } else if (preset === 'this_month') {
                from = new Date(today.getFullYear(), today.getMonth(), 1);
                to = new Date(today);
            } else if (preset === 'last_3') {
                to = new Date(today);
                from = new Date(today);
                from.setMonth(from.getMonth() - 3);
            } else if (preset === 'last_6') {
                to = new Date(today);
                from = new Date(today);
                from.setMonth(from.getMonth() - 6);
            } else if (preset === 'last_12') {
                to = new Date(today);
                from = new Date(today);
                from.setMonth(from.getMonth() - 12);
            } else return;
            const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
            const f = document.getElementById('fp_from');
            const t = document.getElementById('fp_to');
            if (f) f.value = fmt(from);
            if (t) t.value = fmt(to);
            this.selectedPreset = preset;
            window.debouncedUpdateDashboard(300);
        }
    };
}

window.printflowInitReportsCharts = function () {
    // NOTE: No guard on reportsFilterForm — independent charts must work without filter interaction
    console.log('[PrintFlow] Attempting to init charts...');
    
    var PF_HEATMAP_API = <?php echo json_encode(rtrim(AUTH_REDIRECT_BASE, '/') . '/admin/api_reports_heatmap.php'); ?>;
    
    if (typeof window.ApexCharts === 'undefined' || typeof window.Chart === 'undefined') {
        window.__pfApexLoadAttempts = (window.__pfApexLoadAttempts || 0) + 1;
        if (window.__pfApexLoadAttempts < 80) {
            window.setTimeout(function () {
                if (typeof window.printflowInitReportsCharts === 'function') window.printflowInitReportsCharts();
            }, 50);
        } else {
            console.error('[PrintFlow] ApexCharts failed to load.');
        }
        return;
    }
    window.__pfApexLoadAttempts = 0;
    window.__pfReportsChartsReady = true;
    window.printflowTeardownReportsCharts();

    // ── INDEPENDENT CHARTS INITIALIZATION (All-Time Data) ──
    // These charts load immediately with all-time data and are NOT affected by global filters
    console.log('[PrintFlow] Initializing independent charts with all-time data...');

    const PF_PRIMARY = '#00232b';
    const PF_SECONDARY = '#53C5E0';
    const PF_PAL = ['#00232b','#53C5E0','#0F4C5C','#3498DB','#6C5CE7','#3A86A8','#8ED6E6','#6B7C85','#F39C12','#2ECC71'];
    const PF_BAR_RANK = ['#00232b','#0F4C5C','#3A86A8','#2B6CB0','#276749','#2C5282','#234E52','#1A365D'];
    const PF_LINE_DARK = ['#00232b','#0F4C5C','#3A86A8','#3498DB','#6C5CE7','#6B7C85'];
    const PF_LINE_FORE = ['#8ED6E6','#53C5E0','#8ED6E6','#E5EEF2','#C4B5FD','#B8C5CC'];
    const PF_OPT = {
        toolbar:{show:false},
        redrawOnParentResize:false,
        redrawOnWindowResize:true,
        animations:{
            enabled:true,
            easing:'easeinout',
            speed:1850,
            animateGradually:{enabled:true,delay:95},
            dynamicAnimation:{enabled:true,speed:780}
        },
        fontFamily:'inherit'
    };

    // ── FILTER-DEPENDENT CHART (Sales Revenue - Responds to Global Filter) ──
    console.log('[PrintFlow] Initializing filter-dependent Sales Revenue chart...');
    
    // ── DASHBOARD SALES REVENUE (Single Source of Truth) ──
    (function initDashSalesChart() {
        var canvas = document.getElementById('dashSalesChart');
        if (!canvas || typeof Chart === 'undefined') {
            console.log('[PrintFlow] Dashboard sales chart: Canvas or Chart.js not found');
            return;
        }
        
        // Destroy existing chart first
        if (window.__pfDashSalesChart) {
            try {
                window.__pfDashSalesChart.destroy();
                console.log('[PrintFlow] Dashboard sales chart: Destroyed existing instance');
            } catch(e) {
                console.warn('[PrintFlow] Error destroying existing chart:', e);
            }
            window.__pfDashSalesChart = null;
        }
        
        // Prevent multiple initializations
        if (canvas.dataset.pfChartInitialized === '1') {
            console.log('[PrintFlow] Dashboard sales chart: Already initialized, skipping');
            return;
        }
        
        var rData = window.__pfReportsData || {};
        var branchRows = Array.isArray(rData.salesByBranch) ? rData.salesByBranch.slice() : [];
        branchRows = branchRows.map(function (row) {
            return {
                branch_name: String(row.branch_name || 'Unknown Branch'),
                revenue: Number(row.revenue) || 0,
                orders_store: Number(row.orders_store) || 0,
                orders_jobs: Number(row.orders_jobs) || 0,
                prev_revenue: typeof row.prev_revenue === 'number' ? row.prev_revenue : (row.prev_revenue ? Number(row.prev_revenue) : null),
                growth_pct: typeof row.growth_pct === 'number' ? row.growth_pct : (row.growth_pct ? Number(row.growth_pct) : null)
            };
        }).filter(function (row) {
            return row.revenue > 0;
        }).sort(function (a, b) {
            return b.revenue - a.revenue;
        });

        var dsLabels = branchRows.map(function (row) { return row.branch_name; });
        var dsRevBranch = branchRows.map(function (row) { return row.revenue; });
        var dsGrowth = branchRows.map(function (row) { return row.growth_pct; });
        var dsPrevRev = branchRows.map(function (row) { return row.prev_revenue; });
        var totalRevenue = dsRevBranch.reduce(function (sum, value) { return sum + value; }, 0);
        var currencyFmt = new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: 'PHP',
            maximumFractionDigits: 0
        });
        var compactCurrencyFmt = new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: 'PHP',
            notation: 'compact',
            maximumFractionDigits: 1
        });
        var chartWrap = document.getElementById('dash-sales-chart-wrap');
        var chartCanvasWrap = canvas.parentElement;
        var widthPerBar = branchRows.length > 8 ? 92 : 104;
        var computedWidth = Math.max(760, branchRows.length * widthPerBar);
        if (chartWrap) {
            chartWrap.style.height = '520px';
        }
        if (chartCanvasWrap) {
            chartCanvasWrap.style.width = computedWidth + 'px';
            chartCanvasWrap.style.minWidth = computedWidth + 'px';
        }
        
        console.log('[PrintFlow] Dashboard sales chart data:', {
            labels: dsLabels.length,
            revBranch: dsRevBranch.length,
            sampleLabel: dsLabels[0],
            sampleRevBranch: dsRevBranch[0],
            totalRevenue: totalRevenue
        });
        
        var noDataEl = document.getElementById('dash-sales-nodata');
        
        // Check if we have meaningful data
        var hasData = dsLabels.length > 0;
        var hasRevenue = totalRevenue > 0;

        if (!hasData || !hasRevenue) {
            console.log('[PrintFlow] Dashboard sales chart: No meaningful data found', {
                hasData: hasData,
                hasRevenue: hasRevenue,
                labelsLength: dsLabels.length,
                totalBranchRev: totalRevenue
            });
            if (noDataEl) {
                noDataEl.style.display = 'flex';
                var span = noDataEl.querySelector('span');
                if (span) {
                    if (!hasData) {
                        span.textContent = 'No branch revenue data for this period';
                    } else {
                        span.textContent = 'No paid branch revenue found for the selected period';
                    }
                }
            }
            return;
        }
        
        console.log('[PrintFlow] Dashboard sales chart: Rendering with data');
        if (noDataEl) noDataEl.style.display = 'none';
        
        // Destroy existing chart if it exists
        // (This is now handled above before the initialization check)
        
        try {
            canvas.dataset.pfChartInitialized = '1';
            var barFill = dsRevBranch.map(function (_, index) {
                return index === 0 ? '#0F4C5C' : 'rgba(0,35,43,0.82)';
            });
            var barBorder = dsRevBranch.map(function (_, index) {
                return index === 0 ? '#53C5E0' : '#00232b';
            });
            var tickLabels = dsLabels.map(function (label) {
                var shortLabel = String(label || '').replace(/\s+Branch$/i, '').trim();
                return [shortLabel, 'Branch'];
            });
            window.__pfDashSalesChart = new Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: { 
                    labels: tickLabels, 
                    datasets: [
                        {
                            label: 'Total sales revenue',
                            data: dsRevBranch,
                            backgroundColor: barFill,
                            borderColor: barBorder,
                            borderWidth: 1.5,
                            borderRadius: 10,
                            borderSkipped: false,
                            barPercentage: 0.58, // thinner bars
                            categoryPercentage: 0.68 // more space between bars
                        }
                    ]
                },
                options: {
                    responsive: true, 
                    maintainAspectRatio: false,
                    layout: {
                        padding: { top: 32, right: 18, bottom: 0, left: 8 }
                    },
                    animation: { duration: 1100, easing: 'easeOutCubic' },
                    interaction: { mode: 'nearest', intersect: true },
                    hover: { mode: 'index', intersect: false, animationDuration: 400 },
                    plugins: {
                        legend: { 
                            display: true,
                            position: 'bottom',
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                                color: '#00232b',
                                font: { size: 11, weight: '700' }
                            }
                        },
                        tooltip: { 
                            animation: { duration: 180 }, 
                            padding: 12, 
                            cornerRadius: 8, 
                            displayColors: false,
                            callbacks: { 
                                title: function(items) {
                                    if (!items[0]) return '';
                                    var raw = branchRows[items[0].dataIndex];
                                    return raw ? raw.branch_name : '';
                                },
                                label: function(ctx) {
                                    var idx = ctx.dataIndex;
                                    var prev = dsPrevRev[idx];
                                    var growth = dsGrowth[idx];
                                    var label = 'Total sales revenue: ' + currencyFmt.format(Number(ctx.parsed.y) || 0);
                                    if (prev !== null && !isNaN(prev)) {
                                        label += '\nPrev: ' + currencyFmt.format(prev);
                                    }
                                    if (growth !== null && !isNaN(growth)) {
                                        label += '\nGrowth: ' + (growth > 0 ? '+' : '') + growth.toFixed(1) + '%';
                                    }
                                    return label;
                                },
                                afterLabel: function(ctx) {
                                    var pct = totalRevenue > 0 ? ((Number(ctx.parsed.y) || 0) / totalRevenue) * 100 : 0;
                                    return 'Contribution: ' + pct.toFixed(1) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y:  { 
                            beginAtZero: true, 
                            ticks: { 
                                font: { size: 11 }, 
                                callback: function(v){ 
                                    return compactCurrencyFmt.format(Number(v) || 0); 
                                } 
                            }, 
                            grid: { color: '#f3f4f6' } 
                        },
                        x:  { 
                            ticks: {
                                color: '#334155',
                                font: { size: 11, weight: '600' },
                                maxRotation: 0,
                                minRotation: 0
                            },
                            grid: { display: false } 
                        }
                    }
                },
                plugins: [{
                    id: 'pfBranchRevenueLabels',
                    afterDatasetsDraw: function(chart) {
                        var ctx = chart.ctx;
                        var meta = chart.getDatasetMeta(0);
                        var dataset = chart.data.datasets[0];
                        ctx.save();
                        ctx.font = '600 11px sans-serif';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        meta.data.forEach(function(bar, index) {
                            var value = Number(dataset.data[index]) || 0;
                            var growth = dsGrowth[index];
                            var growthLabel = '';
                            if (growth !== null && !isNaN(growth)) {
                                growthLabel = (growth > 0 ? '+' : '') + growth.toFixed(1) + '%';
                            }
                            ctx.fillStyle = index === 0 ? '#0F172A' : '#334155';
                            ctx.fillText(currencyFmt.format(value), bar.x, bar.y - 6);
                            if (growthLabel) {
                                ctx.save();
                                ctx.font = 'bold 10px sans-serif';
                                ctx.fillStyle = growth > 0 ? '#059669' : (growth < 0 ? '#dc2626' : '#64748b');
                                ctx.fillText(growthLabel, bar.x, bar.y - 22);
                                ctx.restore();
                            }
                        });
                        ctx.restore();
                    }
                }]
            });
            console.log('[PrintFlow] Dashboard sales chart: Successfully created');
        } catch(e) {
            console.error('[PrintFlow] Dashboard sales chart creation error:', e);
            canvas.dataset.pfChartInitialized = '0';
            if (noDataEl) {
                noDataEl.style.display = 'flex';
                noDataEl.querySelector('span').textContent = 'Error loading chart data';
            }
        }
    })();

    // ── INDEPENDENT CHARTS (All-Time Data - Not Affected by Global Filters) ──
    console.log('[PrintFlow] Initializing independent charts (12-month trend, forecast, products, etc.)...');

    // ── 12-MONTH SALES TREND (Independent - Dashboard Parity) ──
    (function initTrendChart(){
        try {
            const rData = window.__pfReportsData || {};
            const trend = rData.trend12 || {};
            const ctx = document.getElementById('salesChart');
            if (!ctx) return;

            if (!trend.labels || trend.labels.length === 0) {
                const trendCard = ctx.closest('.ana-card');
                if (trendCard) trendCard.classList.add('hidden');
                return;
            }

            const revStore = trend.revStore || [];
            const revCustom = trend.revCustom || [];
            const revenues = trend.revenues || [];
            const forecast = trend.forecast || {};
            const combined = (revenues.length > 0)
                ? revenues.map(function (v) { return Number(v) || 0; })
                : revStore.map(function (v, i) { return Number(v || 0) + Number(revCustom[i] || 0); });

            const histLabels = [...(trend.labels || [])];
            const histCombined = combined.slice();
            const forecastStartIndex = histLabels.length - 1;

            const forecastRevBranch = new Array(histLabels.length).fill(null);
            let forecastVal = (forecast.revenue != null && forecast.revenue !== '')
                ? Number(forecast.revenue)
                : (Number(forecast.revStore || 0) + Number(forecast.revCustom || 0));

            if (forecast.label && forecastVal > 0) {
                if (histCombined.length > 0) {
                    forecastRevBranch[histCombined.length - 1] = histCombined[histCombined.length - 1];
                }
                histLabels.push(forecast.label);
                forecastRevBranch.push(forecastVal);
                histCombined.push(null);
            }

            var trendDatasets = [
                {
                    label: 'Branch revenue (₱)',
                    data: histCombined,
                    borderColor: '#00232b',
                    backgroundColor: 'rgba(0,35,43,.08)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#00232b',
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    yAxisID: 'y'
                },
                {
                    label: 'Branch revenue forecast (₱)',
                    data: forecastRevBranch,
                    borderColor: '#6366F1',
                    backgroundColor: 'transparent',
                    borderWidth: 2.5,
                    borderDash: [8, 6],
                    fill: false,
                    tension: 0.35,
                    pointBackgroundColor: '#6366F1',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    yAxisID: 'y',
                    spanGaps: true
                }
            ];

            // Create vertical line annotation for forecast separator
            const forecastSeparatorPlugin = {
                id: 'forecastSeparator',
                afterDraw: function(chart) {
                    if (forecast.label && forecastStartIndex >= 0) {
                        const ctx = chart.ctx;
                        const chartArea = chart.chartArea;
                        const xScale = chart.scales.x;
                        
                        // Get x position for the forecast separator (between last historical and forecast point)
                        const xPos = xScale.getPixelForValue(forecastStartIndex + 0.5);
                        
                        if (xPos >= chartArea.left && xPos <= chartArea.right) {
                            ctx.save();
                            
                            // Draw vertical dashed line
                            ctx.setLineDash([8, 4]);
                            ctx.strokeStyle = '#94a3b8';
                            ctx.lineWidth = 2;
                            ctx.globalAlpha = 0.8;
                            
                            ctx.beginPath();
                            ctx.moveTo(xPos, chartArea.top);
                            ctx.lineTo(xPos, chartArea.bottom);
                            ctx.stroke();
                            
                            ctx.restore();
                        }
                    }
                }
            };

            if (typeof Chart === 'undefined') return;
            if (window.__pfReportsTrendChart) {
                try { window.__pfReportsTrendChart.destroy(); } catch(e) {}
                window.__pfReportsTrendChart = null;
            }
            requestAnimationFrame(function() {
                window.__pfReportsTrendChart = new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: { labels: histLabels, datasets: trendDatasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 1500, easing: 'easeOutQuart' },
                        interaction: { mode: 'index', intersect: false },
                        layout: {
                            padding: {
                                top: 10 // Reduced padding since no label
                            }
                        },
                        plugins: {
                            legend: { 
                                display: true, 
                                position: 'top', 
                                labels: { 
                                    boxWidth: 12, 
                                    font: { size: 11 },
                                    filter: function(legendItem, chartData) {
                                        // Hide all forecast legend items
                                        return !legendItem.text.includes('Forecast');
                                    }
                                } 
                            },
                            tooltip: {
                                animation: { duration: 180 }, padding: 10, cornerRadius: 8, displayColors: true,
                                callbacks: {
                                    label: function (c) {
                                        var lab = c.dataset && c.dataset.label ? String(c.dataset.label) : '';
                                        return lab + ': ₱' + Number(c.parsed.y).toLocaleString(undefined,{minimumFractionDigits:0});
                                    },
                                    afterLabel: function(c) {
                                        // Show forecast indicator for forecast datasets
                                        if (c.dataset.label && c.dataset.label.includes('Forecast') && c.parsed.y !== null) {
                                            return 'Forecast based on 12-month trend';
                                        }
                                        return '';
                                    },
                                    title: function(tooltipItems) {
                                        const index = tooltipItems[0].dataIndex;
                                        const label = histLabels[index];
                                        if (index > forecastStartIndex) {
                                            return label + ' (Forecast)';
                                        }
                                        return label;
                                    }
                                }
                            }
                        },
                        scales: {
                            y:  { beginAtZero: true, ticks: { font: { size: 11 }, callback: function (v) { return '₱' + v.toLocaleString(); } }, grid: { color: '#f3f4f6' } },
                            x:  { ticks: { font: { size: 10 }, maxRotation: 45 }, grid: { display: false } }
                        }
                    },
                    plugins: [forecastSeparatorPlugin]
                });
            });
        } catch(e) { console.error('TrendChart error:', e); }
    })();


    // ── PRODUCT DEMAND FORECAST (Independent - All-Time) ──
    (function initForecastChart(){
        const rData = window.__pfReportsData || {};
        const fc = rData.forecastChart || {};
        if (!fc.can_forecast || !fc.series || fc.series.length === 0) {
            const fcSection = document.getElementById('pf-forecast-section');
            if (fcSection) fcSection.classList.add('hidden');
            return;
        }

        const allLabels = fc.all_labels || [];
        const fcHistCount = fc.hist_count || 0;
        const fcData = fc.series || [];

        function pfFcShortLabel(lb) {
            var t = String(lb == null ? '' : lb).trim();
            var parts = t.split(/\s+/).filter(Boolean);
            if (parts.length >= 2) {
                var mo = parts[0];
                if (mo.length > 3) mo = mo.slice(0, 3);
                var y = String(parts[parts.length - 1]).replace(/[^0-9]/g, '');
                if (y.length >= 2) y = y.slice(-2);
                return mo + "'" + y;
            }
            return t.length > 9 ? t.slice(0, 9) : t;
        }
        const shortCats = allLabels.map(function (lb) { return pfFcShortLabel(lb); });
        const fcastStart = shortCats[fcHistCount] || shortCats[0];
        const series = [];
        const colors = [];
        const dashes = [];
        let idx = 0;

        fcData.forEach(function(p){
            const cAct = PF_LINE_DARK[idx % PF_LINE_DARK.length];
            const cFore = PF_LINE_FORE[idx % PF_LINE_FORE.length];
            const histData = [...p.hist, ...new Array(p.fore.length).fill(null)];
            const foreData = [...new Array(p.hist.length - 1).fill(null), p.hist[p.hist.length - 1], ...p.fore];
            series.push({ name: p.name + ' (actual)', data: histData });
            series.push({ name: p.name + ' (forecast)', data: foreData });
            colors.push(cAct, cFore);
            dashes.push(0, 6);
            idx++;
        });

        const fcMount = document.getElementById('ch-forecast');
        if (!fcMount || !fcMount.parentElement) return;
        fcMount.innerHTML = '';
        var wrap = fcMount.parentElement;
        var h = wrap.clientHeight || wrap.getBoundingClientRect().height;
        if (h < 160) h = 320;
        var fcChartH = Math.max(288, Math.min(480, Math.round(h)));

        pfPushApexChart(fcMount, {
            chart: {
                ...PF_OPT, 
                type: 'line', 
                height: fcChartH,
                toolbar: { show: false },
                animations: { enabled: true, easing: 'easeinout', speed: 800 },
                zoom: { enabled: false },
                width: '100%'
            },
            dataLabels: { enabled: false },
            series: series,
            xaxis: {
                categories: shortCats,
                tickPlacement: 'on',
                range: (shortCats.length - 1),
                labels: {
                    style: { fontSize: '11px', fontWeight: 700, colors: '#1f2937' },
                    rotate: -45,
                    rotateAlways: true,
                    trim: false,
                    hideOverlappingLabels: false,
                    offsetY: 6
                },
                axisBorder: { show: true, color: '#d1d5db', height: 1.5 },
                axisTicks: { show: true, color: '#e5e7eb', height: 4 }
            },
            yaxis: {
                show: true,
                labels: {
                    formatter: function (v) { return v != null ? Math.round(v) : ''; },
                    offsetX: -8,
                    style: { fontSize: '11px', fontWeight: 600, colors: '#374151' }
                },
                axisBorder: { show: true, color: '#e5e7eb' },
                axisTicks: { show: true, color: '#e5e7eb' }
            },
            colors: colors,
            stroke: { curve: 'smooth', width: 3, dashArray: dashes },
            markers: { size: 0 },
            tooltip:{
                shared: true,
                intersect: false,
                theme: 'dark',
                style: { fontSize: '12px', fontFamily: 'inherit' },
                x: {
                    show: true,
                    formatter: function (val, opts) {
                        var i = opts && typeof opts.dataPointIndex === 'number' ? opts.dataPointIndex : -1;
                        if (i >= 0 && allLabels[i] != null) {
                            var label = String(allLabels[i]);
                            if (i >= fcHistCount) return label + ' (forecast)';
                            return label;
                        }
                        return val;
                    }
                },
                y: { 
                    formatter: function(v) { return v != null && v !== 0 ? Math.round(v) + ' orders' : null; },
                    title: { formatter: function(sName) { return sName.replace(/\s*\((actual|forecast)\)\s*/gi, '').trim(); } }
                },
                custom: function({ series, seriesIndex, dataPointIndex, w }) {
                    if (dataPointIndex < 0) return '';
                    var monthLabel = allLabels[dataPointIndex] || '';
                    var isForecast = dataPointIndex >= fcHistCount;
                    var productMap = {};
                    var seriesNames = w.config.series || [];
                    seriesNames.forEach(function(s, idx) {
                        if (!s || !s.data || s.data[dataPointIndex] == null) return;
                        var value = s.data[dataPointIndex];
                        if (value === null || value === 0) return;
                        var productName = (s.name || '').replace(/\s*\((actual|forecast)\)\s*/gi, '').trim();
                        if (!productName) return;
                        var isForecastSeries = /\(forecast\)/i.test(s.name);
                        if (isForecast && !isForecastSeries) return;
                        if (!isForecast && isForecastSeries) return;
                        if (!productMap[productName]) {
                            productMap[productName] = { value: Math.round(value), color: w.config.colors[idx] || '#94a3b8' };
                        }
                    });
                    var html = '<div style="padding:10px 12px;min-width:180px;max-width:280px;">';
                    html += '<div style="font-weight:700;font-size:13px;color:#f1f5f9;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,0.1);">';
                    html += pfEscHtml(monthLabel) + (isForecast ? ' <span style="color:#93c5fd;font-size:11px;font-weight:600;">(forecast)</span>' : '') + '</div>';
                    var products = Object.keys(productMap);
                    if (products.length === 0) html += '<div style="color:#94a3b8;font-size:11px;font-style:italic;">No data</div>';
                    else {
                        products.forEach(function(productName, idx) {
                            var data = productMap[productName];
                            var isLast = idx === products.length - 1;
                            html += '<div style="display:flex;align-items:center;gap:8px;padding:5px 0;' + (!isLast ? 'border-bottom:1px solid rgba(255,255,255,0.05);' : '') + '">';
                            html += '<span style="width:8px;height:8px;border-radius:50%;background:' + data.color + ';flex-shrink:0;"></span>';
                            html += '<div style="flex:1;display:flex;justify-content:space-between;align-items:center;gap:12px;min-width:0;">';
                            var displayName = productName.length > 28 ? productName.substring(0, 28) + '...' : productName;
                            html += '<span style="color:#e2e8f0;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + pfEscHtml(productName) + '">' + pfEscHtml(displayName) + '</span>';
                            html += '<span style="color:#fff;font-weight:700;font-size:12px;white-space:nowrap;">' + data.value.toLocaleString() + '</span>';
                            html += '</div></div>';
                        });
                    }
                    html += '</div>';
                    return html;
                }
            },
            annotations: { xaxis: [{ x: fcastStart, borderColor: '#0ea5e9', strokeDashArray: 4 }] },
            legend: { show: false },
            grid: { borderColor: '#e5e7eb', strokeDashArray: 0, padding: { left: 20, right: 20, top: 15, bottom: 25 } }
        });
    })();
    // ── CUSTOMIZATION USAGE CHART (Independent - All-Time) ──
    (function initCustomUsageChart(){
        try {
            const rData = window.__pfReportsData || {};
            const usage = rData.customUsage || [];
            const el = document.getElementById('ch-custom');
            if (!el || usage.length === 0) return;

            const cats = usage.map(u => u.product);
            const valCust = usage.map(u => u.custom_count);
            const valTemp = usage.map(u => u.template_count);

            pfPushApexChart(el, {
                chart: { ...PF_OPT, type: 'bar', height: 300, stacked: false },
                colors: [PF_SECONDARY, PF_PRIMARY],
                plotOptions: { bar: { horizontal: true, barHeight: '60%', borderRadius: 2, dataLabels: { position: 'top' } } },
                series: [{ name: 'Custom Design', data: valCust }, { name: 'Template Ready', data: valTemp }],
                xaxis: { categories: cats, labels: { style: { fontSize: '10px', fontWeight: 500, colors: '#6b7280' } } },
                yaxis: { labels: { maxWidth: 140, style: { fontSize: '10px', fontWeight: 600, colors: '#374151' }, formatter: v => (v && v.length > 20 ? v.substring(0, 20) + '...' : v) } },
                legend: { position: 'top', horizontalAlign: 'right', fontSize: '11px', fontWeight: 500, markers: { width: 10, height: 10, radius: 2 } },
                dataLabels: { enabled: true, style: { fontSize: '10px', fontWeight: 600, colors: ['#ffffff'] }, formatter: v => (v > 0 ? v.toString() : '') },
                tooltip: { shared: true, intersect: false, theme: 'dark', style: { fontSize: '11px' }, y: { formatter: v => v + ' orders' } },
                grid: { borderColor: '#f3f4f6', strokeDashArray: 1, padding: { left: 8, right: 12, top: 8, bottom: 8 }, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } }
            });
        } catch(e) { console.error('CustomUsage error:', e); }
    })();

    // ── HEATMAP YEAR NAVIGATION (Independent - All-Time) ──
    
    // ── CUSTOMIZATION REVENUE CHART (New dedicated chart) ──
    (function initCustomizationRevenueChart(){
        try {
            const rData = window.__pfReportsData || {};
            const customRev = rData.customizationRevenue || {};
            const labels = customRev.labels || [];
            const revenue = customRev.revenue || [];
            const canvas = document.getElementById('customizationRevenueChart');
            if (!canvas || labels.length === 0 || typeof Chart === 'undefined') return;

            new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Customization Revenue (₱)',
                        data: revenue,
                        borderColor: '#6366F1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#6366F1',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        pointHoverBackgroundColor: '#6366F1',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 1400, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: true, position: 'top', labels: { boxWidth: 12, font: { size: 11, weight: 600 }, color: '#374151' } },
                        tooltip: {
                            animation: { duration: 200 }, padding: 12, cornerRadius: 8, displayColors: true,
                            backgroundColor: 'rgba(30, 41, 59, 0.95)', titleColor: '#e2e8f0', bodyColor: '#f8fafc', borderColor: '#475569', borderWidth: 1,
                            callbacks: { label: ctx => 'Revenue: ₱' + Number(ctx.parsed.y || 0).toLocaleString() }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { font: { size: 11, weight: 600 }, color: '#64748b', callback: v => '₱' + Number(v).toLocaleString() }, grid: { color: '#f1f5f9', drawBorder: false } },
                        x: { ticks: { font: { size: 10, weight: 600 }, color: '#64748b', maxRotation: 45 }, grid: { display: false } }
                    }
                }
            });
        } catch(e) { console.error('CustomizationRevenue error:', e); }
    })();

    function pfEscHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * Calculate tier based on percentage of max value (matches PHP logic).
     * @param {number} v - Current value
     * @param {number} maxV - Maximum value in dataset
     * @returns {string} 'low'|'med'|'high'
     */
    function pfHmValueTier(v, maxV) {
        v = Number(v) || 0;
        maxV = Number(maxV) || 0;
        if (v <= 0 || maxV <= 0) return 'low';
        var pct = (v / maxV) * 100;
        if (pct <= 25) return 'low';
        if (pct <= 65) return 'med';
        return 'high';
    }

    /**
     * Build HTML/CSS heatmap into mount (replaces innerHTML).
     * series = [{ name, data: [{ x, y, kind: future|empty|value }] }]
     * meta = { serverYear, serverMonth, year } for month-header styling when kind omitted.
     */
    function pfReportsMountHeatmapFromApi(mount, series, meta) {
        if (!mount || !series || !series.length) return;
        meta = meta || {};
        var serverYear = Number(meta.serverYear);
        var serverMonth = Number(meta.serverMonth);
        if (!serverYear) serverYear = new Date().getFullYear();
        if (!serverMonth) serverMonth = new Date().getMonth() + 1;
        var displayYear = Number(meta.year);
        if (!displayYear) displayYear = serverYear;

        // Calculate global max value for dynamic tier thresholds
        var maxValue = 0;
        series.forEach(function(row) {
            var pts = row.data || [];
            pts.forEach(function(pt) {
                if (pt && typeof pt.y !== 'undefined') {
                    var v = Number(pt.y) || 0;
                    if (v > maxValue) maxValue = v;
                }
            });
        });

        var fallbackM = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var headMonths = fallbackM;
        var firstPts = series[0] && series[0].data ? series[0].data : [];
        if (firstPts.length === 12) {
            headMonths = firstPts.map(function (p, i) {
                return p && p.x != null ? String(p.x) : fallbackM[i];
            });
        }
        mount.innerHTML = '';
        var outer = document.createElement('div');
        outer.className = 'pf-hm-outer';
        var root = document.createElement('div');
        root.className = 'pf-hm-root';
        root.id = 'pf-hm-root';
        var grid = document.createElement('div');
        grid.className = 'pf-hm-grid';
        grid.setAttribute('role', 'grid');
        grid.setAttribute('aria-label', 'Seasonal demand by service and month');
        var corner = document.createElement('div');
        corner.className = 'pf-hm-corner';
        corner.setAttribute('aria-hidden', 'true');
        var monthRow = document.createElement('div');
        monthRow.className = 'pf-hm-months';
        monthRow.setAttribute('role', 'row');
        headMonths.forEach(function (m, idx) {
            var th = document.createElement('div');
            var mi = idx + 1;
            th.className = 'pf-hm-month' + (displayYear === serverYear && mi > serverMonth ? ' pf-hm-month--future' : '');
            th.setAttribute('role', 'columnheader');
            th.textContent = m;
            monthRow.appendChild(th);
        });
        grid.appendChild(corner);
        grid.appendChild(monthRow);
        series.forEach(function (row) {
            var svc = row.name != null ? String(row.name) : '';
            var labelCol = document.createElement('div');
            labelCol.className = 'pf-hm-label-col';
            var span = document.createElement('span');
            span.className = 'pf-hm-label-text';
            span.textContent = svc;
            span.setAttribute('title', svc);
            labelCol.appendChild(span);
            var tiles = document.createElement('div');
            tiles.className = 'pf-hm-tiles';
            tiles.setAttribute('role', 'row');
            var pts = row.data || [];
            headMonths.forEach(function (m, idx) {
                var pt = pts[idx];
                var v = pt && typeof pt.y !== 'undefined' ? Number(pt.y) || 0 : 0;
                var moLabel = pt && pt.x != null ? String(pt.x) : m;
                var kind = pt && pt.kind ? String(pt.kind) : '';
                if (!kind) {
                    if (displayYear === serverYear && idx + 1 > serverMonth) kind = 'future';
                    else kind = v > 0 ? 'value' : 'empty';
                }
                var cell = document.createElement('div');
                var val = document.createElement('span');
                val.className = 'pf-hm-val';
                if (kind === 'future') {
                    cell.className = 'pf-hm-cell pf-hm-cell--future';
                    cell.setAttribute('role', 'gridcell');
                    cell.setAttribute('aria-disabled', 'true');
                    cell.setAttribute('title', svc + ' · ' + moLabel + ' — No data yet');
                } else if (kind === 'empty') {
                    cell.className = 'pf-hm-cell pf-hm-cell--nodata';
                    cell.setAttribute('role', 'gridcell');
                    cell.setAttribute('tabindex', '0');
                    cell.setAttribute('title', svc + ' · ' + moLabel + ' — No transactions');
                } else {
                    // Pass maxValue to pfHmValueTier for dynamic thresholds
                    cell.className = 'pf-hm-cell pf-hm-cell--' + pfHmValueTier(v, maxValue);
                    cell.setAttribute('role', 'gridcell');
                    cell.setAttribute('tabindex', '0');
                    cell.setAttribute('title', svc + ' · ' + moLabel + ' · ' + v + ' units');
                    val.textContent = String(v);
                }
                cell.appendChild(val);
                tiles.appendChild(cell);
            });
            grid.appendChild(labelCol);
            grid.appendChild(tiles);
        });
        root.appendChild(grid);
        outer.appendChild(root);
        mount.appendChild(outer);
    }
    window.pfReportsMountHeatmapFromApi = pfReportsMountHeatmapFromApi;

    window.pfDestroyReportsHeatmapChart = function () {};


    // ── BEST SELLING SERVICES (Independent - All-Time) ──
    (function initTopServicesChart(){
        try {
            const rData = window.__pfReportsData || {};
            const top_products = rData.topServices || [];
            const productsMount = document.getElementById('ch-products');
            if (!productsMount) return;

            if (top_products.length === 0) {
                const card = productsMount.closest('.ana-card');
                if (card) card.classList.add('hidden');
                return;
            }

            const displayCount = Math.min(8, top_products.length);
            const displayQtys = top_products.slice(0, displayCount).map(p => p.qty);
            const displayNames = top_products.slice(0, displayCount).map(p => p.name);
            const displayRevenues = top_products.slice(0, displayCount).map(p => p.revenue);
            const prevQtys = top_products.slice(0, displayCount).map(p => p.prev_qty);
            
            var maxQty = Math.max(...displayQtys);
            var xMax = Math.ceil(maxQty * 1.05);
            
            const topQty = displayQtys[0] || 1;
            const percentages = displayQtys.map(q => Math.round((q / topQty) * 100));
            
            const shortNames = displayNames.map(function(nm) {
                var t = String(nm || '').trim();
                return t.length > 32 ? (t.substring(0, 32) + '...') : t;
            });
            
            const barColors = ['#00232b', '#0F4C5C', '#3A86A8', '#2B6CB0', '#276749', '#2C5282', '#234E52', '#1A365D'];
            const productSeriesData = displayQtys.map((qty, i) => ({ x: '', y: qty || 0, fillColor: barColors[i] || '#94a3b8' }));
            
            var productsWrap = productsMount.closest('.ch-box');
            var productsWrapH = productsWrap ? Math.max(360, Math.round(productsWrap.getBoundingClientRect().height || productsWrap.clientHeight || 0)) : 420;
            
            pfPushApexChart(productsMount, {
                chart:{ ...PF_OPT, id:'pf-ch-products-bar', redrawOnParentResize:true, type:'bar', height: productsWrapH },
                plotOptions:{ bar:{ horizontal:true, borderRadius:6, barHeight:'70%', distributed:true, dataLabels:{ position:'center' } } },
                series:[{name:'Units Sold', data:productSeriesData}],
                xaxis:{
                    min: 0, max: xMax, tickAmount: 5,
                    labels:{ style:{fontSize:'11px', fontWeight:600, colors:'#64748b'}, formatter:v => Number(v || 0).toLocaleString() },
                    axisBorder: { show: true, color: '#e5e7eb' },
                    axisTicks: { show: true, color: '#e5e7eb' }
                },
                yaxis:{ labels:{ show: false } },
                colors: barColors, legend:{show:false},
                dataLabels:{
                    enabled:true, offsetX: 0, textAnchor: 'middle', distributed: false,
                    style:{ fontSize:'12px', colors:['#ffffff'], fontWeight: 700 },
                    dropShadow: { enabled: true, top: 1, left: 1, blur: 3, color: '#000', opacity: 0.45 },
                    formatter:(v, opts) => {
                        var i = opts.dataPointIndex, q = Number(v || 0), pct = percentages[i], name = shortNames[i] || '';
                        return i < 3 ? (name + ' \u2022 ' + q.toLocaleString() + ' (' + pct + '%)') : (name + ' \u2022 ' + q.toLocaleString());
                    }
                },
                tooltip:{
                    theme:'dark', custom:function (ctx) {
                        var i = ctx.dataPointIndex; if (i < 0) return '';
                        var nm = displayNames[i] || '', q = displayQtys[i], rev = displayRevenues[i], pct = percentages[i], prev = prevQtys[i];
                        var trend = '', trendIcon = '', trendColor = '#94a3b8';
                        if (typeof prev === 'number' && prev > 0) {
                            var chg = Math.round(((q - prev) / prev) * 100);
                            if (chg > 0) { trendIcon = '\u2191'; trendColor = '#10b981'; trend = trendIcon + ' +' + chg + '% vs prior'; }
                            else if (chg < 0) { trendIcon = '\u2193'; trendColor = '#ef4444'; trend = trendIcon + ' ' + chg + '% vs prior'; }
                            else { trendIcon = '\u2192'; trend = trendIcon + ' No change'; }
                        }
                        var h = '<div style="padding:12px;min-width:220px;">';
                        h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;"><span style="background:'+(barColors[i]||'#94a3b8')+';color:#fff;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">#'+(i+1)+'</span><span style="font-weight:700;color:#f1f5f9;font-size:13px;">'+pfEscHtml(nm)+'</span></div>';
                        h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px;">';
                        h += '<div style="background:rgba(83,197,224,0.1);padding:6px;border-radius:6px;"><div style="font-size:9px;color:#94a3b8;">Units</div><div style="font-size:15px;font-weight:800;color:#53C5E0;">'+q.toLocaleString()+'</div></div>';
                        h += '<div style="background:rgba(16,185,129,0.1);padding:6px;border-radius:6px;"><div style="font-size:9px;color:#94a3b8;">Revenue</div><div style="font-size:15px;font-weight:800;color:#10b981;">\u20b1'+rev.toLocaleString(undefined,{maximumFractionDigits:0})+'</div></div>';
                        h += '</div>';
                        if(trend) h += '<div style="font-size:11px;color:'+trendColor+';font-weight:600;">'+pfEscHtml(trend)+'</div>';
                        h += '</div>'; return h;
                    }
                },
                grid:{ borderColor:'#f1f5f9', strokeDashArray:3, xaxis:{lines:{show:true}}, yaxis:{lines:{show:false}} }
            });
        } catch(e) { console.error('TopServices error:', e); }
    })();

    // ── REVENUE DISTRIBUTION DONUT (Independent - All-Time) ──
    (function initRevenueDonutChart(){
        try {
            const rData = window.__pfReportsData || {};
            const revData = rData.revenueDonut || [];
            const mount = document.getElementById('ch-donut');
            if (!mount) return;

            if (revData.length === 0) {
                const card = mount.closest('.ana-card');
                if (card) card.classList.add('hidden');
                return;
            }

            // Build dynamic legend
            const legendMount = document.getElementById('pf-rev-donut-legend');
            if (legendMount) {
                legendMount.innerHTML = '';
                const totalRev = revData.reduce((a, b) => a + (b.revenue || 0), 0);
                revData.forEach((rd, i) => {
                    const col = PF_PAL[i % PF_PAL.length];
                    const amt = Number(rd.revenue || 0);
                    const pct = totalRev > 0 ? ((amt / totalRev) * 100).toFixed(1) : '0';
                    const li = document.createElement('li');
                    li.innerHTML = `
                        <span class="rev-donut-swatch" style="background:${col};"></span>
                        <div class="rev-donut-legend-txt">
                            ${pfEscHtml(rd.name)}
                            <span class="rev-donut-legend-meta">₱${Math.round(amt).toLocaleString()} · ${pct}%</span>
                        </div>
                    `;
                    legendMount.appendChild(li);
                });
            }

            const vals = revData.map(p => p.revenue), total = vals.reduce((a,b) => a+b, 0), labels = revData.map(p => p.name);
            pfPushApexChart(mount, {
                chart:{...PF_OPT, type:'donut', height:240},
                series:vals, labels:labels, colors:PF_PAL,
                plotOptions:{ pie:{ donut:{ size:'68%', labels:{ show:true, name:{show:false}, value:{show:false}, total:{ show:true, showAlways:true, label:'Total Revenue', color:'#6B7C85', fontSize:'11px', fontWeight:600, formatter:() => '\u20b1'+Math.round(total).toLocaleString() } } } } },
                tooltip:{ theme:'dark', y:{ formatter:v => { var pct = total > 0 ? ((Number(v)/total)*100).toFixed(1) : '0'; return '\u20b1'+Number(v).toLocaleString()+' ('+pct+'%)'; } } },
                legend:{show:false}, dataLabels:{enabled:false}
            });
        } catch(e) { console.error('RevenueDonut error:', e); }
    })();

    // ── SALES BY PRODUCT CATEGORY & SERVICE CATEGORY (filtered date range + branch) ──
    (function initReportsCategoryDonuts() {
        try {
            var rData = window.__pfReportsData || {};
            if (typeof Chart === 'undefined') return;

            var catColors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#F39C12', '#2ECC71'];

            function pfReportsMountCategoryChartWhenVisible(chartElWrap, fn) {
                if (!chartElWrap || typeof fn !== 'function') return;
                var fired = false;
                function once() {
                    if (fired) return;
                    fired = true;
                    fn();
                }
                if (typeof IntersectionObserver === 'undefined') {
                    requestAnimationFrame(once);
                    return;
                }
                var root = document.querySelector('.main-content');
                var io = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (!entry.isIntersecting) return;
                        try { io.disconnect(); } catch (e0) {}
                        once();
                    });
                }, { root: root || null, threshold: 0, rootMargin: '120px 0px 180px 0px' });
                io.observe(chartElWrap);
                requestAnimationFrame(function () {
                    try {
                        var r = chartElWrap.getBoundingClientRect();
                        var vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
                        var vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
                        if (r.width > 0 && r.height > 0 && r.bottom > -80 && r.top < vh + 160 && r.right > 0 && r.left < vw + 80) {
                            try { io.disconnect(); } catch (e1) {}
                            once();
                        }
                    } catch (e2) {}
                });
            }

            function destroyChart(ref) {
                if (window[ref]) {
                    try { window[ref].destroy(); } catch (e2) {}
                    window[ref] = null;
                }
            }

            function fillLegend(elId, labels, colors) {
                var el = document.getElementById(elId);
                if (!el) return;
                var html = '';
                labels.forEach(function (label, i) {
                    var color = colors[i % colors.length];
                    html += '<div style="display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">';
                    html += '<span style="width:10px;height:10px;border-radius:50%;background:' + color + ';"></span>';
                    html += '<span style="font-weight:600;color:#374151;">' + pfEscHtml(label) + '</span>';
                    html += '</div>';
                });
                el.innerHTML = html;
            }

            function buildDoughnut(canvasId, legendId, rows, chartRef, extraTooltip) {
                destroyChart(chartRef);
                var canvas = document.getElementById(canvasId);
                if (!canvas || !rows || rows.length === 0) return;
                var labels = rows.map(function (r) { return r.category || '\u2014'; });
                var data = rows.map(function (r) { return Number(r.revenue) || 0; });
                var colors = labels.map(function (_, i) { return catColors[i % catColors.length]; });
                fillLegend(legendId, labels, colors);

                window[chartRef] = new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: colors,
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        animation: { duration: 900, easing: 'easeOutQuart' },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var v = Number(ctx.parsed) || 0;
                                        var row = rows[ctx.dataIndex] || {};
                                        var suffix = '';
                                        if (extraTooltip === 'items' && row.items != null) suffix = ' \u00b7 ' + row.items + ' items';
                                        if (extraTooltip === 'jobs' && row.jobs != null) suffix = ' \u00b7 ' + row.jobs + ' jobs';
                                        return ctx.label + ': \u20b1' + v.toLocaleString(undefined, { maximumFractionDigits: 0 }) + suffix;
                                    }
                                }
                            }
                        }
                    }
                });
                requestAnimationFrame(function () {
                    var ch = window[chartRef];
                    if (ch && typeof ch.resize === 'function') {
                        try { ch.resize(); } catch (e3) {}
                    }
                });
            }

            var pcCanvas = document.getElementById('reportsProductCategoryChart');
            pfReportsMountCategoryChartWhenVisible(pcCanvas ? pcCanvas.parentElement : null, function () {
                buildDoughnut('reportsProductCategoryChart', 'reports-product-cat-legend', rData.productCategorySales || [], '__pfReportsProductCategoryChart', 'items');
            });

            var scCanvas = document.getElementById('reportsServiceCategoryChart');
            pfReportsMountCategoryChartWhenVisible(scCanvas ? scCanvas.parentElement : null, function () {
                buildDoughnut('reportsServiceCategoryChart', 'reports-service-cat-legend', rData.serviceCategorySales || [], '__pfReportsServiceCategoryChart', 'jobs');
            });
        } catch (e) { console.error('ReportsCategoryDonuts error:', e); }
    })();

    // ── ORDER STATUS BREAKDOWN (Independent - All-Time) ──
    (function initOrderStatusChart(){
        try {
            const rData = window.__pfReportsData || {};
            const statusData = rData.orderStatus || [];
            const mount = document.getElementById('ch-status');
            if (!mount) return;

            if (statusData.length === 0) {
                const card = mount.closest('.ana-card');
                if (card) card.classList.add('hidden');
                return;
            }

            // Use the same color palette as dashboard
            const statusColors = ['#00232b', '#53C5E0', '#0F4C5C', '#3498DB', '#6C5CE7', '#3A86A8', '#F39C12', '#2ECC71'];
            const labels = statusData.map(d => d.status);
            const vals = statusData.map(d => parseInt(d.cnt) || 0);
            const total = vals.reduce((a, b) => a + b, 0);
            const colors = labels.map((_, i) => statusColors[i % statusColors.length]);

            pfPushApexChart(mount, {
                chart:{...PF_OPT, type:'donut', height:300, animations:{enabled:true, easing:'easeinout', speed:600}},
                series:vals, labels:labels, colors:colors,
                plotOptions:{ pie:{ donut:{ size:'62%', labels:{ show:true, name:{show:false}, value:{show:false}, total:{ show:true, showAlways:true, label:'Total orders', color:'#64748b', fontSize:'12px', fontWeight:600, formatter:() => total.toLocaleString() } } } } },
                legend:{position:'bottom', fontSize:'11px', fontWeight:600, itemMargin:{vertical:4}},
                dataLabels:{enabled:false},
                tooltip:{ theme:'dark', fillSeriesColor:false, style:{fontSize:'12px'}, y:{ formatter:v => v + ' orders' } }
            });
        } catch(e) { console.error('OrderStatus error:', e); }
    })();

    // ── CUSTOMIZATION USAGE CHART (Independent - All-Time) ──

    (function heatmapYearNav() {
        var sel = document.getElementById('pf-heatmap-year');
        if (!sel || sel.dataset.pfReportsBound === '1') return;
        sel.dataset.pfReportsBound = '1';
        var mount = document.getElementById('ch-heatmap-mount');
        var box = document.getElementById('pf-heatmap-chbox');
        var loadEl = document.getElementById('pf-heatmap-ajax-loading');
        var yearChip = document.getElementById('pf-heatmap-year-display');
        
        // Legend toggle functionality
        function initHeatmapLegendToggle() {
            var legend = document.getElementById('pf-heatmap-legend');
            if (!legend || legend.dataset.pfBound === '1') return;
            legend.dataset.pfBound = '1';
            
            var items = legend.querySelectorAll('.pf-hm-legend-item');
            items.forEach(function(item) {
                item.addEventListener('click', function() {
                    var kind = this.getAttribute('data-kind');
                    if (!kind) return;
                    
                    // Toggle hidden state on legend item
                    this.classList.toggle('pf-hm-hidden');
                    var isHidden = this.classList.contains('pf-hm-hidden');
                    
                    // Toggle all cells of this kind
                    var cells = document.querySelectorAll('.pf-hm-cell--' + kind);
                    cells.forEach(function(cell) {
                        if (isHidden) {
                            cell.classList.add('pf-hm-hidden');
                        } else {
                            cell.classList.remove('pf-hm-hidden');
                        }
                    });
                });
                
                // Keyboard support
                item.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.click();
                    }
                });
            });
        }
        
        // Initialize legend toggle on page load
        initHeatmapLegendToggle();
        
        function showLoading(on) {
            if (loadEl) loadEl.classList.toggle('hidden', !on);
            if (box) {
                box.classList.toggle('pf-heatmap-loading', !!on);
                if (on) box.setAttribute('aria-busy', 'true');
                else box.removeAttribute('aria-busy');
            }
        }
        function showHeatmapEmpty(yr, msg) {
            if (!mount) return;
            var text = msg && String(msg).trim() ? String(msg) : 'No data available for selected year';
            mount.innerHTML = '<div class="ch-empty pf-heatmap-empty" role="status"><svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>' + pfEscHtml(text) + '</div>';
        }
        sel.addEventListener('change', function () {
            var year = this.value;
            if (!year) return;
            
            if (yearChip) yearChip.textContent = year;
            showLoading(true);
            
            // Build URL with current branch context from the form
            var url = PF_HEATMAP_API + '?year=' + encodeURIComponent(year);
            var f = document.getElementById('reportsFilterForm');
            var branchId = f ? (f.elements['branch_id'] ? f.elements['branch_id'].value : 'all') : 'all';
            if (branchId && branchId !== 'all') {
                url += '&branch_id=' + encodeURIComponent(branchId);
            }
            
            fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { 
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json(); 
                })
                .then(function (data) {
                    if (!data || !data.ok) {
                        throw new Error(data && data.message ? data.message : 'Invalid response');
                    }
                    if (!data.yearValid || data.empty || !data.series || !data.series.length) {
                        showHeatmapEmpty(data.year || year, data.message);
                        showLoading(false);
                        return;
                    }
                    if (!mount || typeof window.pfReportsMountHeatmapFromApi !== 'function') {
                        showLoading(false);
                        return;
                    }
                    window.pfReportsMountHeatmapFromApi(mount, data.series, {
                        serverYear: data.serverYear,
                        serverMonth: data.serverMonth,
                        year: data.year
                    });
                    showLoading(false);
                    if (box) box.classList.add('pf-chart-reveal-done');
                    
                    // Re-initialize legend toggle after new content is loaded
                    setTimeout(function() {
                        var legend = document.getElementById('pf-heatmap-legend');
                        if (legend) legend.dataset.pfBound = '';
                        initHeatmapLegendToggle();
                    }, 100);
                })
                .catch(function (err) {
                    console.error('Heatmap fetch error:', err);
                    showHeatmapEmpty(year, 'Failed to load heatmap data. Please try again.');
                    showLoading(false);
                });
        });
    })();

// Intercept clicks on AJAX-enabled filters/sorts/pagination
document.addEventListener('click', function(e) {
    const ajaxLink = e.target.closest('a[href*="chart_sort="], a[href*="txn_pay="], a[href*="txn_page="]');
    if (ajaxLink && !ajaxLink.closest('.no-ajax') && !e.ctrlKey && !e.metaKey) {
        // Only if we are on the reports page
        if (document.getElementById('pf-reports-dashboard-container')) {
            e.preventDefault();
            const url = new URL(ajaxLink.href);
            const params = Object.fromEntries(url.searchParams.entries());
            window.fetchUpdatedDashboard(params);
            
            // Special handling for transaction filters to scroll to section
            if (ajaxLink.dataset.txnFilter) {
                setTimeout(() => {
                    const el = document.getElementById('recent-transactions');
                    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
        }
    }
});



    window.printflowAttachReportsChartLayoutHooks();
};

window.addEventListener('turbo:before-render', function() {
    if (typeof window.printflowTeardownReportsCharts === 'function') {
        window.printflowTeardownReportsCharts();
    }
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(window.printflowInitReportsCharts, 10);
    });
} else {
    setTimeout(window.printflowInitReportsCharts, 10);
}
// Support Turbo/Custom events
document.addEventListener('printflow:page-init', window.printflowInitReportsCharts);
document.addEventListener('turbo:load', window.printflowInitReportsCharts);
</script>
