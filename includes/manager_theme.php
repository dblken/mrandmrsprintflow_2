<?php

/**

 * Manager portal palette: primary #567881, accent #89a1ab.

 * Requires `html.printflow-manager` (script in admin_style.php for /manager/).

 */

?>

<style>

    html.printflow-manager {

        --accent-color: #89a1ab;

        --manager-primary: #567881;

        --manager-accent: #89a1ab;

    }



    /* Main area: focus rings */

    html.printflow-manager .input-field:focus,

    html.printflow-manager select:focus,

    html.printflow-manager input:focus {

        border-color: var(--manager-accent);

        box-shadow: 0 0 0 3px rgba(137, 161, 171, 0.22);

    }



    html.printflow-manager .btn-primary {

        background: #89a1ab;

        color: #fff;

    }

    html.printflow-manager .btn-primary:hover {

        background: #6f858f;

        box-shadow: 0 4px 14px rgba(137, 161, 171, 0.35);

    }



    /* Sidebar shell */

    html.printflow-manager .sidebar {

        background: linear-gradient(180deg, #061418 0%, #0a2026 28%, #123139 62%, #1b424c 100%);

        border-right: 1px solid rgba(137, 161, 171, 0.18);

        box-shadow: 4px 0 24px rgba(6, 20, 24, 0.32);

    }

    html.printflow-manager .sidebar-header {

        border-bottom: 1px solid rgba(137, 161, 171, 0.22);

    }

    html.printflow-manager .sidebar-header .logo img {

        border-color: rgba(137, 161, 171, 0.45) !important;

    }

    html.printflow-manager .logo-icon {

        background: linear-gradient(135deg, #567881, #89a1ab);

        border-color: rgba(137, 161, 171, 0.4);

    }

    html.printflow-manager .sidebar-collapse-btn {

        border-color: rgba(137, 161, 171, 0.3);

        color: #c5d4da;

    }

    html.printflow-manager .sidebar-collapse-btn:hover {

        border-color: rgba(137, 161, 171, 0.5);

        color: #fff;

    }



    html.printflow-manager #mobileBurger {

        background: linear-gradient(135deg, #2a4149, #567881);

        border-color: rgba(137, 161, 171, 0.35);

    }

    html.printflow-manager #mobileBurger:hover {

        background: linear-gradient(135deg, #567881, #89a1ab);

        border-color: rgba(137, 161, 171, 0.5);

    }



    html.printflow-manager .nav-section-title {

        color: rgba(197, 212, 218, 0.55);

    }

    html.printflow-manager .nav-item {

        color: rgba(236, 242, 245, 0.9);

    }

    html.printflow-manager .nav-item:hover {

        color: #f7fafb;

    }

    html.printflow-manager .nav-item.active {

        background: linear-gradient(135deg, #f7fafb 0%, #eef2f4 42%, #e4eaed 100%);

        color: #567881;

        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.9);

    }

    html.printflow-manager .nav-item.active .nav-icon {

        color: #567881;

        stroke: #567881;

    }

    html.printflow-manager .nav-item.active:hover {

        background: linear-gradient(135deg, #ffffff 0%, #f2f5f7 50%, #e8eef1 100%);

        color: #3a4f56;

    }



    html.printflow-manager .sidebar-footer {

        border-top: 1px solid rgba(137, 161, 171, 0.22);

    }

    html.printflow-manager .user-avatar {

        background: linear-gradient(135deg, #567881 0%, #89a1ab 100%);

        border-color: rgba(137, 161, 171, 0.45);

    }

    html.printflow-manager .logout-btn-footer {

        border-color: rgba(137, 161, 171, 0.22);

    }



    html.printflow-manager .sidebar.collapsed .nav-item.active {

        background: linear-gradient(135deg, #f7fafb 0%, #eef2f4 50%, #e4eaed 100%);

        color: #567881;

    }

    html.printflow-manager .sidebar.collapsed .nav-item.active .nav-icon {

        color: #567881;

        stroke: #567881;

    }

    html.printflow-manager .sidebar.collapsed .nav-section-title::after {

        color: rgba(137, 161, 171, 0.5);

    }



    html.printflow-manager .sidebar-nav {

        scrollbar-color: rgba(137, 161, 171, 0.35) transparent;

    }

    html.printflow-manager .sidebar-nav::-webkit-scrollbar-thumb {

        background: rgba(137, 161, 171, 0.28);

    }

    html.printflow-manager .sidebar-nav:hover::-webkit-scrollbar-thumb {

        background: rgba(137, 161, 171, 0.45);

    }



    /* KPI / stat accents */

    html.printflow-manager .kpi-card::before,

    html.printflow-manager .kpi-card.indigo::before,

    html.printflow-manager .kpi-card.emerald::before,

    html.printflow-manager .kpi-card.amber::before,

    html.printflow-manager .kpi-card.rose::before,

    html.printflow-manager .kpi-card.blue::before,

    html.printflow-manager .kpi-ind::before,

    html.printflow-manager .kpi-em::before,

    html.printflow-manager .kpi-amb::before,

    html.printflow-manager .kpi-vio::before {

        background: linear-gradient(90deg, #567881, #89a1ab) !important;

    }

    html.printflow-manager .kpi-label,

    html.printflow-manager .kpi-lbl {

        background: linear-gradient(90deg, #567881, #89a1ab) !important;

        -webkit-background-clip: text !important;

        background-clip: text !important;

        color: transparent !important;

        -webkit-text-fill-color: transparent !important;

    }



    html.printflow-manager .stats-grid .stat-card::before,

    html.printflow-manager .stat-card:not(.no-stat-accent)::before {

        background: linear-gradient(90deg, #567881, #89a1ab);

    }



    html.printflow-manager .stat-label {

        color: #89a1ab;

    }



    /* Branch selector (header) */

    html.printflow-manager .branch-selector-btn.open {

        border-color: #89a1ab;

        color: #567881;

        background: #f2f5f7;

    }

    html.printflow-manager .branch-dot {

        background: #89a1ab !important;

    }

    html.printflow-manager .branch-dropdown-item.active {

        color: #567881;

        background: #f2f5f7;

    }

    html.printflow-manager .branch-dropdown-item .check {

        color: #89a1ab;

    }



    /* Common teal controls on shared admin pages (manager uses same templates) */

    html.printflow-manager .toolbar-btn.active {

        border-color: #89a1ab !important;

        color: #567881 !important;

        background: #f2f5f7 !important;

    }

    html.printflow-manager .sort-option.selected {

        color: #567881 !important;

        background: #f2f5f7 !important;

    }

    html.printflow-manager .sort-option .check {

        color: #89a1ab !important;

    }

    html.printflow-manager .filter-btn-apply,

    html.printflow-manager .filter-badge {

        background: #89a1ab !important;

        border-color: #89a1ab !important;

    }

    html.printflow-manager .filter-reset-link,

    html.printflow-manager a.filter-reset-link {

        color: #89a1ab !important;

    }



    /* Form guard */

    html.printflow-manager .pf-fg-spinner {

        border-color: rgba(137, 161, 171, 0.35);

        border-top-color: #89a1ab;

    }

    html.printflow-manager .pf-fg-save-highlight {

        box-shadow: 0 0 0 2px rgba(137, 161, 171, 0.75) !important;

    }

    html.printflow-manager .pf-fg-btn--accent {

        background: #89a1ab;

        color: #fff;

        border-color: #567881;

        box-shadow: 0 2px 10px rgba(137, 161, 171, 0.35);

    }

    html.printflow-manager .pf-fg-btn--accent:hover:not(:disabled) {

        background: #6f858f;

    }

    html.printflow-manager .pf-fg-btn--discard {

        background: #567881;

        color: #d8e2e6;

        border-color: #567881;

    }

    html.printflow-manager .pf-fg-btn--discard:hover:not(:disabled) {

        background: #3a4f56;

        color: #eef2f4;

    }

    html.printflow-manager .pf-fg-btn--neutral {

        border-color: #89a1ab;

        color: #567881;

    }

    html.printflow-manager .pf-fg-btn--neutral:hover:not(:disabled) {

        background: rgba(137, 161, 171, 0.15);

    }

    html.printflow-manager .pf-fg-nav-modal__title,

    html.printflow-manager .pf-fg-nav-modal__sub {

        color: #567881;

    }

    html.printflow-manager .pf-fg-nav-modal__list {

        background: linear-gradient(135deg, rgba(137, 161, 171, 0.12), rgba(86, 120, 129, 0.06));

        border-color: rgba(137, 161, 171, 0.35);

        border-left-color: #89a1ab;

    }

    html.printflow-manager .pf-fg-nav-modal__list li::before {

        background: #89a1ab;

    }

</style>

