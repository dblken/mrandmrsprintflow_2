(function () {
    'use strict';

    if (!document.body || !document.body.classList.contains('pf-catalog-nav-page')) {
        return;
    }

    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn && (conn.saveData || (conn.effectiveType && /(^2g$|slow-2g)/i.test(conn.effectiveType)))) {
        return;
    }

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

    var sibling = siblingCatalogPath();
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

    document.addEventListener('mouseover', function (event) {
        var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (anchor) {
            maybePrefetchHref(anchor.href);
        }
    }, { passive: true });
})();
