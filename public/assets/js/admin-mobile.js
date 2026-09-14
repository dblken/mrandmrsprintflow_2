/**
 * Admin Mobile Menu Handler
 * PrintFlow - Mobile burger menu and sidebar toggle
 * ONLY runs on admin/staff/manager pages with .dashboard-container
 */

(function() {
    'use strict';
    
    // Check if this is an admin page with dashboard container
    function isAdminPage() {
        const hasDashboardContainer = document.querySelector('.dashboard-container') !== null;
        const path = window.location.pathname;
        const isAdminPath = path.includes('/admin/') || path.includes('/staff/') || path.includes('/manager/');
        return hasDashboardContainer && isAdminPath;
    }
    
    // Only run on admin pages
    if (!isAdminPage()) {
        console.log('[Admin Mobile] Not an admin page, skipping initialization');
        return;
    }
    
    console.log('[Admin Mobile] Initializing admin mobile menu');
    
    // Only run on mobile
    function isMobile() {
        return window.innerWidth <= 768;
    }
    
    function initMobileMenu() {
        if (!isMobile()) {
            // Clean up mobile elements on desktop
            const burger = document.getElementById('mobileBurger');
            const overlay = document.getElementById('sidebarOverlay');
            const sidebar = document.querySelector('.sidebar');
            
            if (burger) burger.style.display = 'none';
            if (overlay) {
                overlay.classList.remove('active');
                overlay.style.display = 'none';
            }
            if (sidebar) {
                sidebar.classList.remove('active');
                sidebar.style.transform = '';
            }
            document.body.style.overflow = '';
            return;
        }
        
        // Don't interfere with customer burger menu
        const customerBurger = document.querySelector('[data-pf-mobile-toggle]');
        if (customerBurger) {
            console.log('[Admin Mobile] Customer burger menu detected, skipping');
            return;
        }
        
        // Create burger button if it doesn't exist
        let burger = document.getElementById('mobileBurger');
        if (!burger) {
            burger = document.createElement('button');
            burger.id = 'mobileBurger';
            burger.innerHTML = '<svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>';
            burger.setAttribute('aria-label', 'Toggle menu');
            burger.style.display = 'flex';
            document.body.appendChild(burger);
        } else {
            burger.style.display = 'flex';
        }
        burger.removeAttribute('onclick');
        const pageHeader = document.querySelector('.main-content header, .main-content .top-bar');
        if (pageHeader && !pageHeader.contains(burger)) {
            pageHeader.insertBefore(burger, pageHeader.firstChild);
            pageHeader.classList.add('pf-mobile-shell-header');
            document.body.classList.add('pf-burger-in-header');
        }
        
        // Create overlay if it doesn't exist
        let overlay = document.getElementById('sidebarOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'sidebarOverlay';
            document.body.appendChild(overlay);
        }
        overlay.style.display = 'block';
        overlay.removeAttribute('onclick');
        
        const sidebar = document.querySelector('.sidebar');
        
        if (!sidebar) return;
        
        // Create close button inside sidebar if it doesn't exist
        let closeBtn = sidebar.querySelector('.sidebar-close-btn');
        if (!closeBtn) {
            closeBtn = document.createElement('button');
            closeBtn.className = 'sidebar-close-btn';
            closeBtn.innerHTML = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
            closeBtn.setAttribute('aria-label', 'Close menu');
            closeBtn.style.display = 'flex';
            sidebar.insertBefore(closeBtn, sidebar.firstChild);
        } else {
            closeBtn.style.display = 'flex';
        }
        
        // Toggle sidebar
        function toggleSidebar(e) {
            if (e) e.stopPropagation();
            const isActive = sidebar.classList.contains('active');
            
            if (isActive) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }
        
        // Open sidebar
        function openSidebar() {
            sidebar.classList.add('active');
            overlay.classList.add('active');
            burger.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }
        
        // Close sidebar
        function closeSidebar() {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            burger.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }
        
        // Remove old event listeners by cloning
        const newBurger = burger.cloneNode(true);
        newBurger.removeAttribute('onclick');
        burger.parentNode.replaceChild(newBurger, burger);
        burger = newBurger;
        
        const newOverlay = overlay.cloneNode(true);
        newOverlay.removeAttribute('onclick');
        overlay.parentNode.replaceChild(newOverlay, overlay);
        overlay = newOverlay;
        
        const newCloseBtn = closeBtn.cloneNode(true);
        closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
        closeBtn = newCloseBtn;
        
        // Burger click
        burger.addEventListener('click', toggleSidebar);
        
        // Overlay click
        overlay.addEventListener('click', closeSidebar);
        
        // Close button click
        closeBtn.addEventListener('click', closeSidebar);
        
        // Close on navigation
        const navLinks = sidebar.querySelectorAll('a');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                setTimeout(closeSidebar, 100);
            });
        });
        
        // Close on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('active')) {
                closeSidebar();
            }
        });
    }
    
    // Add data-label attributes to table cells for mobile view
    function enhanceTables() {
        if (!isMobile()) return;
        
        const tables = document.querySelectorAll('table');
        tables.forEach(table => {
            const scrollWrapperSelector = '.pf-table-scroll, .overflow-x-auto, .table-responsive, [id$="TableContainer"], [class*="table-wrap"]';
            if (!table.closest(scrollWrapperSelector) && table.parentNode) {
                const wrapper = document.createElement('div');
                wrapper.className = 'pf-table-scroll';
                table.parentNode.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            }

            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                cells.forEach((cell, index) => {
                    if (headers[index] && !cell.getAttribute('data-label')) {
                        cell.setAttribute('data-label', headers[index]);
                    }
                });
            });
        });
    }

    function getAdminScroller() {
        return document.querySelector('.main-content') || window;
    }

    function getScrollerTop(scroller) {
        if (scroller === window) {
            return window.scrollY || document.documentElement.scrollTop || document.body.scrollTop || 0;
        }
        return scroller.scrollTop || 0;
    }

    function scrollScrollerToTop(scroller) {
        if (scroller === window) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        scroller.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function initAdminScrollTop() {
        let button = document.getElementById('adminScrollTop');

        if (!isMobile()) {
            if (button) button.classList.add('pf-admin-scroll-top-hidden');
            return;
        }

        if (!button) {
            button = document.createElement('button');
            button.id = 'adminScrollTop';
            button.className = 'pf-admin-scroll-top pf-admin-scroll-top-hidden';
            button.type = 'button';
            button.setAttribute('aria-label', 'Scroll to top');
            button.setAttribute('aria-hidden', 'true');
            button.innerHTML = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/></svg>';
            document.body.appendChild(button);
        }

        const scroller = getAdminScroller();
        const hide = () => {
            button.classList.add('pf-admin-scroll-top-hidden');
            button.setAttribute('aria-hidden', 'true');
        };
        const showWhileScrolling = () => {
            window.clearTimeout(button._pfHideTimer);
            if (getScrollerTop(scroller) > 180) {
                button.classList.remove('pf-admin-scroll-top-hidden');
                button.setAttribute('aria-hidden', 'false');
                button._pfHideTimer = window.setTimeout(hide, 1200);
            } else {
                hide();
            }
        };

        if (button._pfScrollTarget && button._pfScrollHandler) {
            button._pfScrollTarget.removeEventListener('scroll', button._pfScrollHandler);
        }

        button._pfScrollTarget = scroller;
        button._pfScrollHandler = showWhileScrolling;
        scroller.addEventListener('scroll', showWhileScrolling, { passive: true });

        button.onclick = (e) => {
            e.preventDefault();
            scrollScrollerToTop(scroller);
            hide();
        };

        hide();
    }

    function closeFilterPanel(panel) {
        let node = panel;

        while (node) {
            if (Array.isArray(node._x_dataStack)) {
                const filterState = node._x_dataStack.find((state) => (
                    state && Object.prototype.hasOwnProperty.call(state, 'filterOpen')
                ));

                if (filterState) {
                    filterState.filterOpen = false;
                    return;
                }
            }

            node = node.parentElement;
        }

        panel.style.display = 'none';
    }

    function enhanceFilterPanels(root = document) {
        const panels = root.querySelectorAll ? root.querySelectorAll('.filter-panel') : [];

        panels.forEach(panel => {
            if (panel.querySelector(':scope > .pf-filter-close') || panel.querySelector('.filter-close-btn')) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pf-filter-close';
            button.setAttribute('aria-label', 'Close filter panel');
            button.setAttribute('title', 'Close filter panel');
            button.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                closeFilterPanel(panel);
            });

            panel.insertBefore(button, panel.firstChild);
        });
    }

    let filterObserverStarted = false;

    function initFilterPanelCloseButtons() {
        enhanceFilterPanels();

        if (filterObserverStarted) return;
        filterObserverStarted = true;

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType !== Node.ELEMENT_NODE) return;

                    if (node.matches && node.matches('.filter-panel')) {
                        enhanceFilterPanels(node.parentElement || document);
                    } else {
                        enhanceFilterPanels(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }
    
    // Initialize on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initMobileMenu();
            enhanceTables();
            initAdminScrollTop();
            initFilterPanelCloseButtons();
        });
    } else {
        initMobileMenu();
        enhanceTables();
        initAdminScrollTop();
        initFilterPanelCloseButtons();
    }
    
    // Re-initialize on window resize
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (isMobile()) {
                initMobileMenu();
                enhanceTables();
                initAdminScrollTop();
            } else {
                // Clean up mobile elements on desktop
                const burger = document.getElementById('mobileBurger');
                const overlay = document.getElementById('sidebarOverlay');
                const sidebar = document.querySelector('.sidebar');
                const scrollTop = document.getElementById('adminScrollTop');
                
                if (burger) burger.style.display = 'none';
                if (overlay) overlay.classList.remove('active');
                if (sidebar) sidebar.classList.remove('active');
                if (scrollTop) scrollTop.classList.add('pf-admin-scroll-top-hidden');
                document.body.style.overflow = '';
            }
        }, 250);
    });
    
    // Support for Turbo/dynamic content
    document.addEventListener('turbo:load', () => {
        initMobileMenu();
        enhanceTables();
        initAdminScrollTop();
        initFilterPanelCloseButtons();
    });
    
})();
