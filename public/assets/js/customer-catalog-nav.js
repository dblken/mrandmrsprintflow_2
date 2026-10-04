(function () {
    'use strict';

    if (!document.body || !document.body.classList.contains('pf-catalog-nav-page')) {
        return;
    }

    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    var canPrefetch = !(conn && (conn.saveData || (conn.effectiveType && /(^2g$|slow-2g)/i.test(conn.effectiveType))));

    var prefetched = new Set();

    function prefetchDocument(path) {
        if (!path || prefetched.has(path)) {
            return;
        }
        prefetched.add(path);
        var link = document.createElement('link');
        link.rel = 'prefetch';
        link.as = 'document';
        link.href = path;
        document.head.appendChild(link);
    }

    function catalogDestination(href) {
        try {
            var url = new URL(href, window.location.href);
            return url.origin === window.location.origin
                && /^\/customer\/(services|products|product_group|order_create|order_service_dynamic|order_[a-z0-9_]+|cart|checkout|order_review|payment)\.php$|^\/customer\/order\//i.test(url.pathname)
                ? url
                : null;
        } catch (e) {
            return null;
        }
    }

    function navigationSkeleton(url) {
        var path = url.pathname.toLowerCase();
        if (/\/(services|products)\.php$/.test(path)) {
            return '<div class="pf-nav-skeleton-grid" aria-hidden="true">' + Array.from({length: 8}, function () {
                return '<div class="pf-nav-skeleton-card"><div class="pf-nav-skeleton-media"></div><div class="pf-nav-skeleton-line wide"></div><div class="pf-nav-skeleton-line"></div><div class="pf-nav-skeleton-line short"></div></div>';
            }).join('') + '</div>';
        }
        if (/\/product_group\.php$/.test(path)) {
            return '<div class="pf-nav-skeleton-detail" aria-hidden="true"><div class="pf-nav-skeleton-copy"><div class="pf-nav-skeleton-line wide"></div><div class="pf-nav-skeleton-options">' + Array.from({length: 8}, function () { return '<div class="pf-nav-skeleton-option"></div>'; }).join('') + '</div></div><div class="pf-nav-skeleton-copy"><div class="pf-nav-skeleton-line wide"></div><div class="pf-nav-skeleton-media"></div><div class="pf-nav-skeleton-line"></div><div class="pf-nav-skeleton-actions"><div></div><div></div></div></div></div>';
        }
        return '<div class="pf-nav-skeleton-detail" aria-hidden="true"><div class="pf-nav-skeleton-copy"><div class="pf-nav-skeleton-media tall"></div><div class="pf-nav-skeleton-line wide"></div><div class="pf-nav-skeleton-line"></div></div><div class="pf-nav-skeleton-copy"><div class="pf-nav-skeleton-line wide"></div><div class="pf-nav-skeleton-line"></div>' + Array.from({length: 6}, function () { return '<div class="pf-nav-skeleton-field"></div>'; }).join('') + '<div class="pf-nav-skeleton-actions"><div></div><div></div></div></div></div>';
    }

    var skeletonStyle = document.createElement('style');
    skeletonStyle.textContent = '@keyframes pf-nav-skeleton-pulse{50%{opacity:.48}}#main-content.pf-nav-loading{position:relative;min-height:360px}#main-content.pf-nav-loading>:not(.pf-nav-skeleton){visibility:hidden!important}.pf-nav-skeleton{position:absolute;inset:0;z-index:20;padding:24px;background:#f8fafc;visibility:visible}.pf-nav-skeleton-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;max-width:1100px;margin:0 auto}.pf-nav-skeleton-card,.pf-nav-skeleton-copy{min-width:0}.pf-nav-skeleton-card{padding:10px;border:1px solid #e5e7eb;border-radius:16px;background:white}.pf-nav-skeleton-media,.pf-nav-skeleton-line,.pf-nav-skeleton-option,.pf-nav-skeleton-field,.pf-nav-skeleton-actions>div{background:linear-gradient(90deg,#e8edf1,#f4f6f8,#e8edf1);background-size:200% 100%;animation:pf-nav-skeleton-pulse 1.2s ease-in-out infinite;border-radius:10px}.pf-nav-skeleton-media{height:210px;margin-bottom:14px}.pf-nav-skeleton-media.tall{height:300px}.pf-nav-skeleton-line{height:14px;width:72%;margin:10px 0}.pf-nav-skeleton-line.wide{width:92%;height:20px}.pf-nav-skeleton-line.short{width:42%}.pf-nav-skeleton-detail{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;max-width:1100px;margin:0 auto}.pf-nav-skeleton-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:18px}.pf-nav-skeleton-option,.pf-nav-skeleton-field{height:46px}.pf-nav-skeleton-field{margin:14px 0}.pf-nav-skeleton-actions{display:flex;gap:10px;margin-top:20px}.pf-nav-skeleton-actions>div{height:48px;flex:1}@media(max-width:800px){.pf-nav-skeleton-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pf-nav-skeleton-detail{grid-template-columns:1fr}.pf-nav-skeleton-media{height:168px}.pf-nav-skeleton-media.tall{height:220px}}@media(prefers-reduced-motion:reduce){.pf-nav-skeleton-media,.pf-nav-skeleton-line,.pf-nav-skeleton-option,.pf-nav-skeleton-field,.pf-nav-skeleton-actions>div{animation:none}}';
    document.head.appendChild(skeletonStyle);

    function showNavigationSkeleton(url) {
        var main = document.getElementById('main-content');
        if (!main || main.querySelector('.pf-nav-skeleton')) return;
        var skeleton = document.createElement('div');
        skeleton.className = 'pf-nav-skeleton';
        skeleton.setAttribute('role', 'status');
        skeleton.setAttribute('aria-live', 'polite');
        skeleton.setAttribute('aria-label', 'Loading selected product or service');
        skeleton.innerHTML = navigationSkeleton(url);
        main.classList.add('pf-nav-loading');
        main.setAttribute('aria-busy', 'true');
        main.appendChild(skeleton);

        window.setTimeout(function () {
            if (!skeleton.isConnected) return;
            skeleton.removeAttribute('aria-hidden');
            skeleton.innerHTML = '<p class="pf-nav-skeleton-message">This is taking longer than expected.</p><button type="button" class="pf-nav-skeleton-retry">Retry</button>';
            skeleton.querySelector('.pf-nav-skeleton-retry').addEventListener('click', function () {
                window.location.assign(url.href);
            });
        }, 12000);
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        var target = event.target && event.target.closest ? event.target : null;
        if (!target || target.closest('button, input, select, textarea, [contenteditable="true"]')) return;
        var href = '';
        var anchor = target.closest('a[href]');
        if (anchor) {
            if (anchor.target && anchor.target !== '_self' || anchor.hasAttribute('download')) return;
            href = anchor.href;
        } else {
            var card = target.closest('.shopee-card[onclick]');
            var match = card && card.getAttribute('onclick').match(/(?:window\.)?location(?:\.href)?\s*=\s*['"]([^'"]+)['"]/i);
            if (match) href = new URL(match[1], window.location.href).href;
        }
        var url = catalogDestination(href);
        if (!url || (url.pathname === window.location.pathname && url.search === window.location.search)) return;
        showNavigationSkeleton(url);
    }, true);

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        var card = event.target && event.target.closest ? event.target.closest('.shopee-card[role="link"][onclick]') : null;
        if (!card) return;
        var match = card.getAttribute('onclick').match(/(?:window\.)?location(?:\.href)?\s*=\s*['"]([^'"]+)['"]/i);
        if (!match) return;
        var url = catalogDestination(new URL(match[1], window.location.href).href);
        if (url && (url.pathname !== window.location.pathname || url.search !== window.location.search)) {
            showNavigationSkeleton(url);
        }
    }, true);

    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        var main = document.getElementById('main-content');
        var skeleton = main && main.querySelector('.pf-nav-skeleton');
        if (skeleton) skeleton.remove();
        if (main) {
            main.classList.remove('pf-nav-loading');
            main.removeAttribute('aria-busy');
        }
    });

    function siblingCatalogPath() {
        var path = window.location.pathname || '';
        if (/\/customer\/services\.php$/i.test(path)) {
            return path.replace(/services\.php$/i, 'products.php');
        }
        if (/\/customer\/products\.php$/i.test(path)) {
            return path.replace(/products\.php$/i, 'services.php');
        }
        return '';
    }

    function maybePrefetchHref(href) {
        try {
            var url = new URL(href, window.location.origin);
            if (url.origin !== window.location.origin) {
                return;
            }
            if (!/\/customer\/(services|products)\.php$/i.test(url.pathname)) {
                return;
            }
            prefetchDocument(url.pathname + url.search);
        } catch (e) {
            // ignore invalid URLs
        }
    }

    var sibling = canPrefetch ? siblingCatalogPath() : '';
    if (sibling) {
        var warmSibling = function () {
            prefetchDocument(sibling);
        };
        if ('requestIdleCallback' in window) {
            requestIdleCallback(warmSibling, { timeout: 2500 });
        } else {
            setTimeout(warmSibling, 1800);
        }
    }

    if (canPrefetch) document.addEventListener('mouseover', function (event) {
        var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (anchor) {
            maybePrefetchHref(anchor.href);
        }
    }, { passive: true });
})();
