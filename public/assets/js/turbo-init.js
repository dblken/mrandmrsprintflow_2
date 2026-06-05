/**
 * Hotwired Turbo Drive & Alpine.js Initialization
 * Handles re-initializing components after Turbo body swaps.
 */
(function () {
    /* ═══════════════════════════════════════════════════════════════════════
     * CRITICAL FIX: Patch Alpine.js to handle null/undefined gracefully
     * This prevents "Cannot convert undefined or null to object" errors
     * ═══════════════════════════════════════════════════════════════════════ */
    
    // Wait for Alpine to load, then patch it
    function patchAlpineForNullSafety() {
        if (typeof window.Alpine === 'undefined') {
            setTimeout(patchAlpineForNullSafety, 50);
            return;
        }
        
        // Store original Object.values
        const originalObjectValues = Object.values;
        
        // Override Object.values globally to handle null/undefined
        Object.values = function(obj) {
            if (obj == null) {
                console.debug('[Alpine Safety] Object.values called with null/undefined, returning empty array');
                return [];
            }
            return originalObjectValues.call(this, obj);
        };
        
        console.debug('[Alpine Safety] Patched Object.values for null safety');
    }
    
    // Start patching immediately
    patchAlpineForNullSafety();
    
    /* Turbo 8 intercepts POST and requires a redirecting response. This app mostly returns 200 + HTML after POST.
       Opt-in: only forms under an ancestor with data-turbo="true" use Turbo (those endpoints must redirect). */
    try {
        if (typeof Turbo !== 'undefined' && Turbo.config && Turbo.config.forms) {
            Turbo.config.forms.mode = 'optin';
        }
    } catch (e) { /* ignore */ }

    /* True after a Turbo body swap; skip duplicate Alpine.initTree on first full load (Alpine.start already ran). */
    var printflowAlpineNeedsReinit = false;

    /* ─── Helpers ─────────────────────────────────────────────────────────── */
    function normPath(href) {
        try {
            var u = new URL(href, window.location.href);
            var p = u.pathname.replace(/\/+$/, '') || '/';
            p = p.replace(/\.php$/i, '');
            return p;
        } catch (e) { return ''; }
    }

    /* ─── Sidebar active-state sync ──────────────────────────────────────── */
    function printflowSyncSidebarNavFromPath() {
        var live = document.getElementById('printflow-persistent-sidebar');
        if (!live) return;

        var current = normPath(window.location.href);
        var matchedTop = false;

        live.querySelectorAll('a.nav-item').forEach(function (a) {
            var isActive = normPath(a.href) === current;
            a.classList.toggle('active', isActive);
            if (isActive) matchedTop = true;
        });

        var customersGroup = live.querySelector('[data-nav-group="customers"]');
        if (customersGroup) {
            var anySubActive = false;
            customersGroup.querySelectorAll('a.nav-subitem').forEach(function (a) {
                var isActive = normPath(a.href) === current;
                a.classList.toggle('active', isActive);
                if (isActive) anySubActive = true;
            });

            var parent = customersGroup.querySelector('.nav-parent');
            if (parent) parent.classList.toggle('active', anySubActive);

            if (anySubActive) {
                customersGroup.classList.add('expanded');
                if (parent) parent.setAttribute('aria-expanded', 'true');
            }
        }
    }

    document.addEventListener('turbo:before-render', function (ev) {
        var nb = ev.detail && ev.detail.newBody;
        if (!nb) return;
        var incoming = nb.querySelector('#printflow-persistent-sidebar');
        if (!incoming) return;
        var live = document.getElementById('printflow-persistent-sidebar');
        if (!live) return;

        var newActiveSub = incoming.querySelector('a.nav-subitem.active');
        if (newActiveSub) {
            var wantSub = normPath(newActiveSub.href);
            live.querySelectorAll('a.nav-subitem').forEach(function (a) {
                a.classList.toggle('active', normPath(a.href) === wantSub);
            });
            var group = live.querySelector('[data-nav-group="customers"]');
            if (group) {
                group.classList.add('expanded');
                var parent = group.querySelector('.nav-parent');
                if (parent) {
                    parent.classList.add('active');
                    parent.setAttribute('aria-expanded', 'true');
                }
            }
            return;
        }

        var newActive = incoming.querySelector('a.nav-item.active');
        if (!newActive) return;
        var want = normPath(newActive.href);
        live.querySelectorAll('a.nav-item').forEach(function (a) {
            a.classList.toggle('active', normPath(a.href) === want);
        });
    });

    document.addEventListener('turbo:load', printflowSyncSidebarNavFromPath);

    /* ─── Alpine: tear down only the swapped main column (not the whole body) ─
     * destroyTree(document.body) broke persistent sidebar + raced inline <script>
     * that define x-data factories (customerModal, ordersPage, …) before initTree. */
    function pfDestroyAlpineTree(el) {
        if (!el || typeof window.Alpine === 'undefined' || typeof Alpine.destroyTree !== 'function') return;
        
        // Skip if no Alpine components exist in the element
        const hasAlpineComponents = el.querySelector('[x-data], [x-for], [x-if], [x-show], [x-bind], [x-on], [x-model], [x-text], [x-html], [x-cloak]');
        if (!hasAlpineComponents && !el.hasAttribute('x-data')) {
            console.debug('[turbo] No Alpine components found, skipping destroyTree');
            return;
        }
        
        try {
            /* Comprehensive fix: Initialize all Alpine internal properties that might be null/undefined
               to prevent "Cannot convert undefined or null to object" errors during destroyTree */
            
            // Fix templates first
            const templates = el.matches && el.matches('template[x-for]') ? [el] : [];
            const allTemplates = templates.concat(Array.from(el.querySelectorAll('template[x-for]')));
            
            allTemplates.forEach(function (tmpl) {
                if (tmpl) {
                    if (tmpl._x_lookup == null) tmpl._x_lookup = {};
                    if (tmpl._x_prevKeys == null) tmpl._x_prevKeys = [];
                    if (tmpl._x_keyExpression == null) tmpl._x_keyExpression = 'index';
                }
            });
            
            /* Fix all Alpine elements - ensure critical internal properties exist */
            const alpineEls = el.querySelectorAll('[x-data], [x-for], [x-if], [x-show], [x-bind], [x-on], [x-model], [x-text], [x-html]');
            alpineEls.forEach(function(alpineEl) {
                if (alpineEl) {
                    if (alpineEl._x_lookup == null) alpineEl._x_lookup = {};
                    if (alpineEl._x_dataStack == null) alpineEl._x_dataStack = [];
                    if (alpineEl._x_bindings == null) alpineEl._x_bindings = {};
                    if (alpineEl._x_refs == null) alpineEl._x_refs = {};
                    if (alpineEl._x_prevKeys == null) alpineEl._x_prevKeys = [];
                }
            });
            
            console.debug('[turbo] Destroying Alpine tree for', alpineEls.length, 'elements');
            Alpine.destroyTree(el);
            console.debug('[turbo] Alpine tree destroyed successfully');
        } catch (e) { 
            /* Alpine may throw while tearing partial trees; safe to ignore if it refers to the Object.values crash */ 
            if (e instanceof TypeError && (e.message.includes('undefined or null to object') || e.message.includes('Cannot convert'))) {
                console.debug('[turbo] Alpine.destroyTree caught expected error:', e.message);
                return;
            }
            console.debug('[turbo] Alpine.destroyTree safe-catch:', e);
        }
    }

    document.addEventListener('turbo:before-render', function () {
        printflowAlpineNeedsReinit = true;
        var mc = document.querySelector('.main-content');
        if (mc) {
            console.log('[turbo] Destroying Alpine tree before render');
            pfDestroyAlpineTree(mc);
        }
    });

    /* ─── Charts: tear down before swap/cache ────────────────────────────── */
    function printflowTeardownAllCharts() {
        try { if (typeof window.printflowTeardownReportsCharts === 'function')  window.printflowTeardownReportsCharts(); } catch (e) { console.error(e); }
        try { if (typeof window.printflowTeardownDashboardCharts === 'function') window.printflowTeardownDashboardCharts(); } catch (e) { console.error(e); }
    }
    document.addEventListener('turbo:before-render', function () { printflowTeardownAllCharts(); });
    document.addEventListener('turbo:before-cache', function () {
        printflowTeardownAllCharts();
        /* No longer calling pfDestroyAlpineTree here; consolidated to before-render to avoid double-cleanup crashes. */
    });

    /* ─── Charts: re-init after paint ────────────────────────────────────── */
    function printflowRunChartInitsForPage() {
        try {
            if (document.getElementById('reportsFilterForm') && typeof window.printflowInitReportsCharts === 'function') {
                window.printflowInitReportsCharts(); return;
            }
            if (document.getElementById('salesChart') && typeof window.printflowInitDashboardCharts === 'function') {
                window.printflowInitDashboardCharts();
            }
        } catch (e) { console.error(e); }
    }

    /* ─── Navigation progress (layout only; no full-screen loader) ───────── */
    document.addEventListener('turbo:before-visit', function (ev) {
        document.documentElement.classList.add('pf-turbo-nav');
        queueMicrotask(function () {
            if (ev.defaultPrevented) {
                document.documentElement.classList.remove('pf-turbo-nav');
            }
        });
    });

    /* ─── turbo:load — main re-init hook ─────────────────────────────────── */
    function printflowInitAll() {
        console.log('[turbo] printflowInitAll called, printflowAlpineNeedsReinit:', printflowAlpineNeedsReinit);
        
        function finishPageBoot() {
            printflowRunChartInitsForPage();
            try {
                console.log('[turbo] Dispatching printflow:page-init event');
                document.dispatchEvent(new CustomEvent('printflow:page-init', { bubbles: false }));
            } catch (e) { /* ignore */ }
            try {
                document.documentElement.dispatchEvent(new CustomEvent('printflow:turbo-page', { bubbles: true }));
            } catch (e) { /* ignore */ }
        }

        /* Inline <script> in the new body defines x-data factories; initTree must run after them.
           On first load: Alpine.start() handles initialization automatically.
           On Turbo navigation: We need to manually call initTree on the new content. */
        if (printflowAlpineNeedsReinit && typeof window.Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
            printflowAlpineNeedsReinit = false;
            console.log('[turbo] Re-initializing Alpine after navigation');
            setTimeout(function () {
                try {
                    var root = document.querySelector('.main-content') || document.body;
                    if (root) {
                        // Check if there are any Alpine components to initialize
                        const hasAlpineComponents = root.querySelector('[x-data], [x-for], [x-if], [x-show], [x-bind], [x-on], [x-model], [x-text], [x-html], [x-cloak]');
                        if (!hasAlpineComponents && !root.hasAttribute('x-data')) {
                            console.log('[turbo] No Alpine components found in', root, ', skipping initTree');
                            finishPageBoot();
                            return;
                        }
                        
                        console.log('[turbo] Found Alpine components, re-initializing...');
                        
                        // Ensure cleanup before re-init
                        pfDestroyAlpineTree(root);
                        
                        // Pre-initialize Alpine properties on new elements to prevent errors
                        const newAlpineEls = root.querySelectorAll('[x-data], [x-for], [x-if], [x-show]');
                        console.log('[turbo] Found', newAlpineEls.length, 'Alpine elements to initialize');
                        newAlpineEls.forEach(function(el) {
                            if (el && el._x_dataStack == null) {
                                el._x_dataStack = [];
                            }
                        });
                        
                        console.log('[turbo] Calling Alpine.initTree on root');
                        
                        // CRITICAL: Wait a bit longer to ensure inline scripts have executed
                        // Inline scripts in the new body define x-data factories like ordersPage()
                        setTimeout(function() {
                            try {
                                Alpine.initTree(root);
                                console.log('[turbo] Alpine.initTree completed successfully');
                            } catch (e) {
                                console.error('[turbo] Alpine.initTree error:', e);
                            }
                            finishPageBoot();
                        }, 50);
                    }
                } catch (e) { 
                    console.error('[turbo] Alpine setup error:', e);
                    finishPageBoot();
                }
            }, 150); // Increased delay to ensure inline scripts execute first
            return;
        }

        console.log('[turbo] Skipping Alpine re-init (first load or no reinit needed)');
        finishPageBoot();
    }

    document.addEventListener('turbo:load', function () {
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                document.documentElement.classList.remove('pf-turbo-nav');
                
                // Wait for Alpine to be fully loaded and ready
                function waitForAlpineAndInit() {
                    if (typeof window.Alpine !== 'undefined' && Alpine.version) {
                        console.debug('[turbo] Alpine detected, version:', Alpine.version);
                        printflowInitAll();
                    } else {
                        var retryCount = 0;
                        var retryTimer = setInterval(function() {
                            retryCount++;
                            if (typeof window.Alpine !== 'undefined' && Alpine.version) {
                                clearInterval(retryTimer);
                                console.debug('[turbo] Alpine loaded after', retryCount * 40, 'ms');
                                printflowInitAll();
                            } else if (retryCount > 100) {
                                clearInterval(retryTimer);
                                console.error('[turbo] Alpine.js failed to load within 4 seconds');
                                // Try to init anyway in case Alpine is partially loaded
                                printflowInitAll();
                            }
                        }, 40);
                    }
                }
                
                waitForAlpineAndInit();
            });
        });
    });

    /* Nav-link prefetch on hover */
    document.addEventListener('mouseenter', function (e) {
        var a = e.target && e.target.closest && e.target.closest('a.nav-item[href]');
        if (!a || a.getAttribute('href').charAt(0) === '#') return;
        if (a.dataset.pfPrefetched) return;
        if (a.target === '_blank' || a.hasAttribute('download')) return;
        try {
            var u = new URL(a.href, location.href);
            if (u.origin !== location.origin) return;
        } catch (err) { return; }
        a.dataset.pfPrefetched = '1';
        var l = document.createElement('link');
        l.rel = 'prefetch';
        l.href = a.href;
        document.head.appendChild(l);
    }, true);

    /* Stale row onclick from cached DOM can reference inv_items handlers on other admin pages. */
    document.addEventListener('turbo:load', function () {
        if (typeof window.openStockCard !== 'function') {
            window.openStockCard = function () {};
        }
        if (typeof window.viewTransaction !== 'function') {
            window.viewTransaction = function () {};
        }
    });
})();
