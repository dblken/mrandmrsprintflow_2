<?php
/**
 * Staff portal palette: primary #06A1A1, soft #9ED7C4.
 * Requires `html.printflow-staff` (script in admin_style.php or header.php for /staff/).
 */
?>
<style>
    html.printflow-staff {
        --accent-color: #06A1A1;
        --staff-primary: #06A1A1;
        --staff-soft: #9ED7C4;
        --staff-accent-rgb: 6, 161, 161;
        --staff-primary-strong: #058f8f;
        --staff-primary-deep: #023d3d;
        --staff-content-accent-grad: linear-gradient(90deg, #035f5f, #06A1A1, #9ED7C4);
        --staff-content-label-grad: linear-gradient(90deg, #023d3d, #06A1A1);
        --staff-toolbar-hover-bg: #f0fdfa;
        --staff-toolbar-active-bg: #f0fdfa;
        --staff-sort-active-bg: rgba(6, 161, 161, 0.08);
        --staff-sort-active-text: #058f8f;
        --staff-service-count-bg: #e6f6f6;
        --staff-loading-bar: linear-gradient(to right, transparent, #06A1A1, transparent);
        --staff-action-pill-bg: rgba(6, 161, 161, 0.12);
        --staff-action-pill-text: #058f8f;
        --staff-action-secondary-bg: rgba(124, 58, 237, 0.1);
        --staff-action-secondary-text: #7c3aed;
        --staff-filter-badge-bg: #06A1A1;
        --staff-pos-button-bg: linear-gradient(135deg, #0d9488 0%, #065f46 100%);
        --staff-pos-button-shadow: rgba(13, 148, 136, 0.2);
        --staff-chart-line: #06A1A1;
        --staff-chart-fill-start: rgba(6, 161, 161, 0.2);
        --staff-chart-fill-end: rgba(6, 161, 161, 0.05);
        --staff-chart-tooltip-bg: #013a3a;
        --staff-pagination-active-bg: #06A1A1;
        --staff-pagination-active-border: #06A1A1;
        --staff-sidebar-bg: linear-gradient(180deg, #011818 0%, #022a2a 24%, #033838 55%, #044040 100%);
        --staff-sidebar-border: rgba(6, 161, 161, 0.22);
        --staff-sidebar-shadow: rgba(0, 48, 48, 0.14);
        --staff-sidebar-header-border: rgba(158, 215, 196, 0.18);
        --staff-sidebar-logo-bg: linear-gradient(135deg, #035050, #06A1A1);
        --staff-sidebar-logo-border: rgba(158, 215, 196, 0.35);
        --staff-sidebar-button-border: rgba(6, 161, 161, 0.28);
        --staff-sidebar-button-text: #9ED7C4;
        --staff-sidebar-button-hover-border: rgba(158, 215, 196, 0.45);
        --staff-sidebar-mobile-bg: linear-gradient(135deg, #022e2e, #06A1A1);
        --staff-sidebar-mobile-hover-bg: linear-gradient(135deg, #035f5f, #09b5b5);
        --staff-sidebar-section-title: rgba(158, 215, 196, 0.55);
        --staff-sidebar-nav-text: rgba(220, 245, 238, 0.9);
        --staff-sidebar-nav-hover-text: #f6fffc;
        --staff-sidebar-active-bg: linear-gradient(135deg, #f7fefb 0%, #e5f9f2 42%, #d4f0e6 100%);
        --staff-sidebar-active-text: #023d3d;
        --staff-sidebar-active-hover-bg: linear-gradient(135deg, #ffffff 0%, #eefaf5 50%, #dff5ec 100%);
        --staff-sidebar-footer-border: rgba(6, 161, 161, 0.2);
        --staff-sidebar-avatar-bg: linear-gradient(135deg, #047676 0%, #06A1A1 55%, #9ED7C4 100%);
        --staff-sidebar-avatar-border: rgba(158, 215, 196, 0.45);
        --staff-sidebar-scrollbar: rgba(6, 161, 161, 0.35);
        --staff-sidebar-scrollbar-thumb: rgba(6, 161, 161, 0.28);
        --staff-sidebar-scrollbar-thumb-hover: rgba(6, 161, 161, 0.45);
    }

    html.printflow-staff.printflow-staff-pos {
        --accent-color: #3b82c4;
        --staff-primary: #3b82c4;
        --staff-soft: #d9e9fb;
        --staff-accent-rgb: 59, 130, 196;
        --staff-primary-strong: #2f6fae;
        --staff-primary-deep: #1e3a8a;
        --staff-content-accent-grad: linear-gradient(90deg, #1e3a8a, #3b82c4, #c7dcf7);
        --staff-content-label-grad: linear-gradient(90deg, #1e3a8a, #3b82c4);
        --staff-toolbar-hover-bg: #f6f9fd;
        --staff-toolbar-active-bg: #edf4fc;
        --staff-sort-active-bg: rgba(59, 130, 196, 0.12);
        --staff-sort-active-text: #2f6fae;
        --staff-service-count-bg: #e8f1fb;
        --staff-loading-bar: linear-gradient(to right, transparent, #3b82c4, transparent);
        --staff-action-pill-bg: rgba(59, 130, 196, 0.12);
        --staff-action-pill-text: #2f6fae;
        --staff-action-secondary-bg: rgba(30, 58, 138, 0.1);
        --staff-action-secondary-text: #1e3a8a;
        --staff-filter-badge-bg: #3b82c4;
        --staff-pos-button-bg: linear-gradient(135deg, #3b82c4 0%, #1e3a8a 100%);
        --staff-pos-button-shadow: rgba(30, 58, 138, 0.18);
        --staff-chart-line: #3b82c4;
        --staff-chart-fill-start: rgba(59, 130, 196, 0.18);
        --staff-chart-fill-end: rgba(59, 130, 196, 0.04);
        --staff-chart-tooltip-bg: #1e3a8a;
        --staff-pagination-active-bg: #3b82c4;
        --staff-pagination-active-border: #3b82c4;
        --staff-sidebar-bg: linear-gradient(180deg, #10264f 0%, #18376c 32%, #234d8b 72%, #2e5f9e 100%);
        --staff-sidebar-border: rgba(191, 219, 254, 0.18);
        --staff-sidebar-shadow: rgba(15, 23, 42, 0.18);
        --staff-sidebar-header-border: rgba(191, 219, 254, 0.16);
        --staff-sidebar-logo-bg: linear-gradient(135deg, #244a86, #4f86c6);
        --staff-sidebar-logo-border: rgba(219, 234, 254, 0.3);
        --staff-sidebar-button-border: rgba(191, 219, 254, 0.24);
        --staff-sidebar-button-text: #dbeafe;
        --staff-sidebar-button-hover-border: rgba(219, 234, 254, 0.42);
        --staff-sidebar-mobile-bg: linear-gradient(135deg, #18376c, #3b82c4);
        --staff-sidebar-mobile-hover-bg: linear-gradient(135deg, #234d8b, #5b96cf);
        --staff-sidebar-section-title: rgba(219, 234, 254, 0.64);
        --staff-sidebar-nav-text: rgba(241, 245, 249, 0.9);
        --staff-sidebar-nav-hover-text: #ffffff;
        --staff-sidebar-active-bg: linear-gradient(135deg, rgba(255, 255, 255, 0.2) 0%, rgba(233, 242, 253, 0.94) 52%, rgba(215, 228, 246, 0.96) 100%);
        --staff-sidebar-active-text: #16325f;
        --staff-sidebar-active-hover-bg: linear-gradient(135deg, rgba(255, 255, 255, 0.26) 0%, rgba(240, 246, 255, 0.98) 55%, rgba(224, 235, 250, 1) 100%);
        --staff-sidebar-footer-border: rgba(191, 219, 254, 0.18);
        --staff-sidebar-avatar-bg: linear-gradient(135deg, #21447e 0%, #3b82c4 55%, #c7dcf7 100%);
        --staff-sidebar-avatar-border: rgba(219, 234, 254, 0.42);
        --staff-sidebar-scrollbar: rgba(219, 234, 254, 0.28);
        --staff-sidebar-scrollbar-thumb: rgba(219, 234, 254, 0.24);
        --staff-sidebar-scrollbar-thumb-hover: rgba(219, 234, 254, 0.4);
    }

    html.printflow-staff.printflow-staff-online {
        --accent-color: #06A1A1;
        --staff-primary: #06A1A1;
        --staff-soft: #9ED7C4;
        --staff-accent-rgb: 6, 161, 161;
        --staff-primary-strong: #058f8f;
        --staff-primary-deep: #023d3d;
        --staff-content-accent-grad: linear-gradient(90deg, #035f5f, #06A1A1, #9ED7C4);
        --staff-content-label-grad: linear-gradient(90deg, #023d3d, #06A1A1);
    }

    /*
     * Admin-aligned Staff design system
     * ---------------------------------
     * Page-local Staff CSS is intentionally kept for feature-specific layouts.
     * These scoped rules normalize only the shared typography, spacing and
     * component geometry. Values come from the Admin shell/dashboard.
     */
    html.printflow-staff body,
    html.printflow-staff .main-content,
    html.printflow-staff .staff-sidebar {
        font-family: var(--pf-ui-font-sans);
    }

    html.printflow-staff button,
    html.printflow-staff input,
    html.printflow-staff select,
    html.printflow-staff textarea {
        font-family: inherit;
    }

    html.printflow-staff .page-title,
    html.printflow-staff .pv-title {
        font-family: var(--pf-ui-font-sans) !important;
        font-size: var(--pf-ui-page-title-size) !important;
        font-weight: var(--pf-ui-page-title-weight) !important;
        line-height: var(--pf-ui-page-title-line-height) !important;
        color: var(--text-main) !important;
        margin-bottom: 0;
    }

    html.printflow-staff .page-subtitle,
    html.printflow-staff .pv-subtitle,
    html.printflow-staff .pf-page-subtitle {
        margin-top: 4px !important;
        font-size: var(--pf-ui-page-subtitle-size) !important;
        font-weight: var(--pf-ui-page-subtitle-weight) !important;
        line-height: var(--pf-ui-page-subtitle-line-height) !important;
        color: #64748b !important;
    }

    @media (min-width: 769px) {
        html.printflow-staff .main-content > header:not(.mobile-chat-header),
        html.printflow-staff main.main-content > header:not(.mobile-chat-header) {
            padding: 24px var(--pf-ui-page-padding-x) !important;
            margin-bottom: 8px !important;
        }
    }

    html.printflow-staff .card,
    html.printflow-staff .stat-card,
    html.printflow-staff .chart-card {
        border: 1px solid var(--border-color) !important;
        border-radius: var(--pf-ui-card-radius) !important;
        padding: var(--pf-ui-card-padding) !important;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02) !important;
        margin-bottom: 24px;
    }

    html.printflow-staff .kpi-row,
    html.printflow-staff .pv-kpi-row,
    html.printflow-staff .cv2-kpis {
        gap: var(--pf-ui-dashboard-gap) !important;
        margin-bottom: 24px !important;
        align-items: stretch;
    }

    html.printflow-staff .kpi-card,
    html.printflow-staff .cv2-kpi {
        border: 1px solid #e5e7eb !important;
        border-radius: var(--pf-ui-dashboard-card-radius) !important;
        padding: 18px 20px 16px !important;
        min-height: 0 !important;
        box-shadow: 0 1px 3px rgba(0, 35, 43, 0.06) !important;
    }

    html.printflow-staff .kpi-card::before {
        height: 3px !important;
    }

    html.printflow-staff .cv2-kpi {
        position: relative;
        overflow: hidden;
    }

    html.printflow-staff .cv2-kpi::before {
        content: '';
        position: absolute;
        inset: 0 0 auto;
        height: 3px;
        background: var(--staff-content-accent-grad);
        pointer-events: none;
    }

    html.printflow-staff .kpi-label,
    html.printflow-staff .kpi-lbl,
    html.printflow-staff .cv2-kpi-title {
        font-family: var(--pf-ui-font-sans) !important;
        font-size: var(--pf-ui-kpi-label-size) !important;
        font-weight: var(--pf-ui-kpi-label-weight) !important;
        line-height: 1.25 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        margin-bottom: 6px !important;
    }

    html.printflow-staff .kpi-value,
    html.printflow-staff .cv2-kpi-num {
        font-family: var(--pf-ui-font-sans) !important;
        font-size: var(--pf-ui-kpi-value-size) !important;
        font-weight: var(--pf-ui-kpi-value-weight) !important;
        line-height: 1.15 !important;
        margin: 0 !important;
        font-variant-numeric: tabular-nums;
    }

    html.printflow-staff .kpi-sub,
    html.printflow-staff .cv2-kpi-sub {
        font-size: var(--pf-ui-kpi-sub-size) !important;
        font-weight: var(--pf-ui-kpi-sub-weight) !important;
        line-height: 1.35 !important;
        color: #6b7280 !important;
    }

    html.printflow-staff .kpi-card-cta {
        font-size: var(--pf-ui-kpi-cta-size) !important;
        font-weight: var(--pf-ui-kpi-cta-weight) !important;
        line-height: 1.2 !important;
        letter-spacing: 0.02em;
    }

    html.printflow-staff .section-title,
    html.printflow-staff .card-title,
    html.printflow-staff .chart-title,
    html.printflow-staff .dash-card-title,
    html.printflow-staff .staff-section-title,
    html.printflow-staff .cv2-listhead h2 {
        font-family: var(--pf-ui-font-sans) !important;
        font-size: var(--pf-ui-section-title-size) !important;
        font-weight: var(--pf-ui-section-title-weight) !important;
        line-height: 1.35 !important;
        color: var(--text-main) !important;
    }

    html.printflow-staff .notif-card-head h3,
    html.printflow-staff #chart-title,
    html.printflow-staff #top-sales-title,
    html.printflow-staff .staff-dashboard-section-title,
    html.printflow-staff .toolbar-group--title h3 {
        font-family: var(--pf-ui-font-sans) !important;
        font-size: var(--pf-ui-section-title-size) !important;
        font-weight: var(--pf-ui-section-title-weight) !important;
        line-height: 1.35 !important;
        color: var(--text-main) !important;
    }

    @media (min-width: 769px) {
        html.printflow-staff table:not(.receipt-items) th {
            padding: 12px 16px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            line-height: 1.35;
        }

        html.printflow-staff table:not(.receipt-items) td {
            padding: 16px !important;
            font-size: 14px !important;
            line-height: 1.45;
        }
    }

    html.printflow-staff .modal-panel,
    html.printflow-staff .modal-content,
    html.printflow-staff .modal-box,
    html.printflow-staff .cv2-modal,
    html.printflow-staff .pv-drawer,
    html.printflow-staff #view-product-modal {
        border-radius: 12px !important;
    }

    html.printflow-staff .modal-title,
    html.printflow-staff .modal-header h2,
    html.printflow-staff .cv2-modal-head h2,
    html.printflow-staff .products-view-modal-header h2 {
        font-family: var(--pf-ui-font-sans) !important;
        font-size: 18px !important;
        font-weight: 700 !important;
        line-height: 1.4 !important;
    }

    html.printflow-staff .modal-header,
    html.printflow-staff .cv2-modal-head,
    html.printflow-staff .products-view-modal-header,
    html.printflow-staff .pv-drawer-head {
        padding: 20px 24px !important;
    }

    html.printflow-staff .modal-body,
    html.printflow-staff .cv2-modal-body,
    html.printflow-staff .products-view-modal-body {
        padding: 20px 24px 24px !important;
    }

    html.printflow-staff .modal-footer,
    html.printflow-staff .cv2-modal-foot {
        padding: 16px 24px !important;
    }

    html.printflow-staff .btn-primary,
    html.printflow-staff .btn-secondary,
    html.printflow-staff .cv2-btn,
    html.printflow-staff .pv-button {
        min-height: 40px;
        padding: 10px 20px !important;
        border-radius: 8px !important;
        font-family: var(--pf-ui-font-sans) !important;
        font-size: 14px !important;
        font-weight: 500 !important;
        line-height: 1.2;
    }

    /* Main area: focus rings & links */
    html.printflow-staff .input-field:focus,
    html.printflow-staff select:focus,
    html.printflow-staff input:focus {
        border-color: var(--staff-primary);
        box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.18);
    }

    html.printflow-staff .btn-primary {
        background: var(--staff-primary);
        color: #fff;
    }

    html.printflow-staff .btn-primary:hover {
        background: var(--staff-primary-strong);
        box-shadow: 0 4px 14px rgba(var(--staff-accent-rgb), 0.35);
    }

    /* Sidebar shell */
    html.printflow-staff .sidebar {
        background: var(--staff-sidebar-bg);
        border-right: 1px solid var(--staff-sidebar-border);
        box-shadow: 4px 0 24px var(--staff-sidebar-shadow);
    }

    html.printflow-staff .sidebar-header {
        border-bottom: 1px solid var(--staff-sidebar-header-border);
    }

    html.printflow-staff .sidebar-header .logo img {
        border-color: var(--staff-sidebar-logo-border) !important;
    }

    html.printflow-staff .logo-icon {
        background: var(--staff-sidebar-logo-bg);
        border-color: var(--staff-sidebar-logo-border);
    }

    html.printflow-staff .sidebar-collapse-btn {
        border-color: var(--staff-sidebar-button-border);
        color: var(--staff-sidebar-button-text);
    }

    html.printflow-staff .sidebar-collapse-btn:hover {
        border-color: var(--staff-sidebar-button-hover-border);
        color: #fff;
    }

    html.printflow-staff #mobileBurger {
        background: var(--staff-sidebar-mobile-bg);
        border-color: var(--staff-sidebar-logo-border);
    }

    html.printflow-staff #mobileBurger:hover {
        background: var(--staff-sidebar-mobile-hover-bg);
        border-color: var(--staff-sidebar-button-hover-border);
    }

    html.printflow-staff .nav-section-title {
        color: var(--staff-sidebar-section-title);
    }

    html.printflow-staff .nav-item {
        color: var(--staff-sidebar-nav-text);
    }

    html.printflow-staff .nav-item:hover {
        color: var(--staff-sidebar-nav-hover-text);
    }

    html.printflow-staff .nav-item.active {
        background: var(--staff-sidebar-active-bg);
        color: var(--staff-sidebar-active-text);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14), inset 0 1px 0 rgba(255, 255, 255, 0.72);
    }

    html.printflow-staff .nav-item.active .nav-icon {
        color: var(--staff-sidebar-active-text);
        stroke: var(--staff-sidebar-active-text);
    }

    html.printflow-staff .nav-item.active:hover {
        background: var(--staff-sidebar-active-hover-bg);
        color: var(--staff-sidebar-active-text);
    }

    html.printflow-staff .sidebar-footer {
        border-top: 1px solid var(--staff-sidebar-footer-border);
    }

    html.printflow-staff .user-avatar {
        background: var(--staff-sidebar-avatar-bg);
        border-color: var(--staff-sidebar-avatar-border);
    }

    html.printflow-staff .sidebar.collapsed .nav-item.active .nav-icon {
        color: var(--staff-sidebar-active-text);
        stroke: var(--staff-sidebar-active-text);
    }

    html.printflow-staff .sidebar.collapsed .nav-section-title::after {
        color: var(--staff-sidebar-section-title);
    }

    html.printflow-staff .sidebar-nav {
        scrollbar-color: var(--staff-sidebar-scrollbar) transparent;
    }

    html.printflow-staff .sidebar-nav::-webkit-scrollbar-thumb {
        background: var(--staff-sidebar-scrollbar-thumb);
    }

    html.printflow-staff .sidebar-nav:hover::-webkit-scrollbar-thumb {
        background: var(--staff-sidebar-scrollbar-thumb-hover);
    }

    /* KPI / stat accents */
    html.printflow-staff .kpi-card::before,
    html.printflow-staff .kpi-card.indigo::before,
    html.printflow-staff .kpi-card.emerald::before,
    html.printflow-staff .kpi-card.amber::before,
    html.printflow-staff .kpi-card.rose::before,
    html.printflow-staff .kpi-card.blue::before,
    html.printflow-staff .kpi-ind::before,
    html.printflow-staff .kpi-em::before,
    html.printflow-staff .kpi-amb::before,
    html.printflow-staff .kpi-vio::before {
        background: var(--staff-content-accent-grad) !important;
    }

    html.printflow-staff .kpi-label,
    html.printflow-staff .kpi-lbl {
        background: var(--staff-content-label-grad) !important;
        -webkit-background-clip: text !important;
        background-clip: text !important;
        color: transparent !important;
        -webkit-text-fill-color: transparent !important;
    }

    html.printflow-staff .stats-grid .stat-card::before,
    html.printflow-staff .stat-card:not(.no-stat-accent)::before {
        background: var(--staff-content-accent-grad);
    }

    html.printflow-staff .stat-label {
        color: var(--staff-primary-strong);
    }

    /* Form guard (sidebar portal) */
    html.printflow-staff .pf-fg-spinner {
        border-color: rgba(var(--staff-accent-rgb), 0.3);
        border-top-color: var(--staff-primary);
    }

    html.printflow-staff .pf-fg-save-highlight {
        box-shadow: 0 0 0 2px rgba(var(--staff-accent-rgb), 0.85) !important;
    }

    html.printflow-staff .pf-fg-btn--accent {
        background: var(--staff-primary);
        color: #fff;
        border-color: var(--staff-primary-deep);
        box-shadow: 0 2px 10px rgba(var(--staff-accent-rgb), 0.35);
    }

    html.printflow-staff .pf-fg-btn--accent:hover:not(:disabled) {
        background: var(--staff-primary-strong);
    }

    html.printflow-staff .pf-fg-btn--discard {
        background: var(--staff-primary-deep);
        color: var(--staff-soft);
        border-color: var(--staff-primary-deep);
    }

    html.printflow-staff .pf-fg-btn--discard:hover:not(:disabled) {
        background: var(--staff-primary-strong);
        color: #ffffff;
    }

    html.printflow-staff .pf-fg-btn--neutral {
        border-color: var(--staff-primary);
        color: var(--staff-primary-deep);
    }

    html.printflow-staff .pf-fg-btn--neutral:hover:not(:disabled) {
        background: rgba(var(--staff-accent-rgb), 0.12);
    }

    html.printflow-staff .pf-fg-nav-modal__title,
    html.printflow-staff .pf-fg-nav-modal__sub {
        color: #023d3d;
    }

    html.printflow-staff .pf-fg-nav-modal__list {
        background: linear-gradient(135deg, rgba(var(--staff-accent-rgb), 0.16), rgba(var(--staff-accent-rgb), 0.08));
        border-color: rgba(var(--staff-accent-rgb), 0.35);
        border-left-color: var(--staff-primary);
    }

    html.printflow-staff .pf-fg-nav-modal__list li::before {
        background: var(--staff-primary);
    }

    /* Unified Table Action Buttons */
    html.printflow-staff .btn-action,
    html.printflow-staff .table-action-btn,
    html.printflow-staff .btn-staff-action,
    html.printflow-staff .cv2-view-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        height: 30px;
        min-height: 30px;
        padding: 0 12px !important;
        font-size: 12px !important;
        font-weight: 500 !important;
        line-height: 1 !important;
        text-transform: none;
        letter-spacing: normal;
        border-radius: 6px !important;
        transition: all 0.2s;
        text-decoration: none;
        cursor: pointer;
    }

    html.printflow-staff .btn-action-primary {
        background: var(--staff-action-pill-bg);
        color: var(--staff-action-pill-text);
    }

    html.printflow-staff .btn-action-primary:hover {
        background: var(--staff-primary);
        color: #ffffff;
        transform: translateY(-1px);
    }

    html.printflow-staff .btn-action-secondary {
        background: var(--staff-action-secondary-bg);
        color: var(--staff-action-secondary-text);
    }

    html.printflow-staff .btn-action-secondary:hover {
        background: #7c3aed;
        color: #ffffff;
        transform: translateY(-1px);
    }

    html.printflow-staff .btn-staff-action-blue {
        border-color: var(--staff-primary);
        color: var(--staff-primary) !important;
    }

    html.printflow-staff .btn-staff-action-blue:hover {
        background: var(--staff-primary);
        color: #ffffff !important;
    }

    html.printflow-staff .kpi-card-cta {
        color: var(--staff-primary) !important;
    }

    html.printflow-staff .btn-action-danger {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
    }

    html.printflow-staff .btn-action-danger:hover {
        background: #ef4444;
        color: #ffffff;
        transform: translateY(-1px);
    }

    /* Standardized Dropdowns & Panels (Sort/Filter) */
    html.printflow-staff .toolbar-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        height: var(--pf-ui-control-height) !important;
        min-height: var(--pf-ui-control-height);
        padding: 0 14px !important;
        background: #fff;
        border: 1px solid #e5e7eb !important;
        border-radius: var(--pf-ui-control-radius) !important;
        color: #374151;
        font-size: var(--pf-ui-control-font-size) !important;
        font-weight: var(--pf-ui-control-font-weight) !important;
        line-height: 1.2;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }

    html.printflow-staff .toolbar-btn--primary {
        font-weight: 700 !important;
    }

    html.printflow-staff .toolbar-btn svg {
        width: 15px;
        height: 15px;
        flex: 0 0 15px;
    }
    html.printflow-staff .toolbar-btn:hover {
        border-color: var(--staff-primary) !important;
        color: var(--staff-primary);
        background: var(--staff-toolbar-hover-bg);
    }
    html.printflow-staff .toolbar-btn.active {
        border-color: var(--staff-primary) !important;
        background: var(--staff-toolbar-active-bg);
        color: var(--staff-primary);
    }

    html.printflow-staff .dropdown-panel {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08), 0 4px 12px rgba(0, 0, 0, 0.03);
        z-index: 100;
        min-width: 220px;
        overflow: hidden;
        animation: pfDropdownIn 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    @keyframes pfDropdownIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    html.printflow-staff .sort-dropdown {
        padding: 8px;
    }
    html.printflow-staff .sort-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 9px 14px;
        font-size: 13px !important;
        font-weight: 500 !important;
        color: #475569;
        cursor: pointer;
        border-radius: 8px;
        transition: all 0.2s;
    }
    html.printflow-staff .sort-option:hover {
        background: #f8fafc;
        color: #1e293b;
    }
    html.printflow-staff .sort-option.active {
        background: var(--staff-sort-active-bg);
        color: var(--staff-sort-active-text);
        font-weight: 700;
    }

    html.printflow-staff .filter-panel {
        width: 320px;
        padding: 0;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
    }
    html.printflow-staff .filter-header {
        padding: 14px 18px;
        font-size: 14px;
        font-weight: 700;
        color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    html.printflow-staff .filter-close-btn {
        background: none;
        border: none;
        color: #94a3b8;
        font-size: 20px;
        cursor: pointer;
        padding: 4px;
        line-height: 1;
    }
    html.printflow-staff .filter-close-btn:hover {
        color: #475569;
    }
    html.printflow-staff .filter-section {
        padding: 14px 18px;
        border-bottom: 1px solid #f1f5f9;
    }
    html.printflow-staff .filter-section-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    html.printflow-staff .filter-label {
        font-size: 13px !important;
        font-weight: 600 !important;
        text-transform: none;
        letter-spacing: normal;
        color: #374151;
    }

    html.printflow-staff .filter-section-label {
        font-size: 13px !important;
        font-weight: 600 !important;
        line-height: 1.35;
        color: #374151 !important;
    }
    html.printflow-staff .filter-reset-link {
        font-size: 12px;
        font-weight: 600;
        color: var(--staff-primary);
        background: none;
        border: none;
        padding: 0;
        cursor: pointer;
    }
    html.printflow-staff .filter-select, 
    html.printflow-staff .filter-input {
        width: 100%;
        height: var(--pf-ui-filter-height) !important;
        min-height: var(--pf-ui-filter-height);
        background: #fff;
        border: 1px solid #e5e7eb !important;
        border-radius: var(--pf-ui-filter-radius) !important;
        padding: 0 10px !important;
        font-size: 13px !important;
        font-weight: 400;
        color: #1f2937;
        transition: all 0.2s;
    }

    html.printflow-staff .filter-search-input {
        width: 100%;
        height: var(--pf-ui-filter-height) !important;
        min-height: var(--pf-ui-filter-height);
        background: #fff;
        border: 1px solid #e5e7eb !important;
        border-radius: var(--pf-ui-filter-radius) !important;
        font-size: 13px !important;
        font-weight: 400;
        color: #1f2937;
        transition: all 0.2s;
    }
    html.printflow-staff .filter-select:focus,
    html.printflow-staff .filter-input:focus,
    html.printflow-staff .filter-search-input:focus {
        border-color: var(--staff-primary) !important;
        background: #fff;
        outline: none;
        box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.1);
    }
    html.printflow-staff .filter-footer {
        padding: 14px 18px;
        background: #f8fafc;
    }
    html.printflow-staff .filter-btn-reset {
        width: 100%;
        height: 36px !important;
        background: #fff;
        border: 1px solid #e5e7eb !important;
        border-radius: 8px !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        color: #374151;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }
    html.printflow-staff .filter-btn-reset:hover {
        border-color: #cbd5e1;
        color: #1e293b;
        background: #f1f5f9;
    }
    html.printflow-staff .filter-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        background: var(--staff-filter-badge-bg);
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        border-radius: 50%;
        margin-left: 4px;
    }

    /* Counter staff: force content chrome to follow the blue POS theme even on pages
       that still carry legacy teal page-local styles. */
    html.printflow-staff.printflow-staff-pos .toolbar-btn:hover,
    html.printflow-staff.printflow-staff-pos .toolbar-btn.active,
    html.printflow-staff.printflow-staff-pos .sort-option.active,
    html.printflow-staff.printflow-staff-pos .filter-reset-link,
    html.printflow-staff.printflow-staff-pos .filter-badge,
    html.printflow-staff.printflow-staff-pos .notif-dot,
    html.printflow-staff.printflow-staff-pos .notif-header-primary,
    html.printflow-staff.printflow-staff-pos .kpi-card-cta,
    html.printflow-staff.printflow-staff-pos .btn-staff-action-blue,
    html.printflow-staff.printflow-staff-pos .btn-action-primary,
    html.printflow-staff.printflow-staff-pos .service-count,
    html.printflow-staff.printflow-staff-pos .tp-sold,
    html.printflow-staff.printflow-staff-pos .om-value,
    html.printflow-staff.printflow-staff-pos .filter-select:focus,
    html.printflow-staff.printflow-staff-pos .filter-input:focus,
    html.printflow-staff.printflow-staff-pos .filter-search-input:focus,
    html.printflow-staff.printflow-staff-pos .input-field:focus,
    html.printflow-staff.printflow-staff-pos select:focus,
    html.printflow-staff.printflow-staff-pos input:focus {
        border-color: var(--staff-primary) !important;
        color: var(--staff-primary) !important;
    }

    html.printflow-staff.printflow-staff-pos .toolbar-btn:hover,
    html.printflow-staff.printflow-staff-pos .toolbar-btn.active {
        background: var(--staff-toolbar-active-bg) !important;
    }

    html.printflow-staff.printflow-staff-pos .sort-option.active {
        background: var(--staff-sort-active-bg) !important;
        color: var(--staff-sort-active-text) !important;
    }

    html.printflow-staff.printflow-staff-pos .filter-badge,
    html.printflow-staff.printflow-staff-pos .notif-dot,
    html.printflow-staff.printflow-staff-pos .notif-header-primary {
        background: var(--staff-primary) !important;
        color: #fff !important;
    }

    html.printflow-staff.printflow-staff-pos .notif-header-primary:hover,
    html.printflow-staff.printflow-staff-pos .btn-action-primary:hover,
    html.printflow-staff.printflow-staff-pos .btn-staff-action-blue:hover {
        background: var(--staff-primary-strong) !important;
        color: #fff !important;
    }

    html.printflow-staff.printflow-staff-pos .btn-staff-action-blue,
    html.printflow-staff.printflow-staff-pos .btn-action-primary,
    html.printflow-staff.printflow-staff-pos .table-action-btn,
    html.printflow-staff.printflow-staff-pos .btn-action.blue {
        border-color: var(--staff-primary) !important;
        color: var(--staff-primary) !important;
        background: rgba(var(--staff-accent-rgb), 0.08) !important;
    }

    html.printflow-staff.printflow-staff-pos .btn-action.blue:hover,
    html.printflow-staff.printflow-staff-pos .table-action-btn:hover {
        background: var(--staff-primary) !important;
        border-color: var(--staff-primary) !important;
        color: #fff !important;
    }

    html.printflow-staff.printflow-staff-pos .service-count,
    html.printflow-staff.printflow-staff-pos .notif-item:hover,
    html.printflow-staff.printflow-staff-pos .pill-tab.active,
    html.printflow-staff.printflow-staff-pos .pill-tab.active .tab-count,
    html.printflow-staff.printflow-staff-pos .notif-action-btn,
    html.printflow-staff.printflow-staff-pos .btn-primary,
    html.printflow-staff.printflow-staff-pos .filter-select:focus,
    html.printflow-staff.printflow-staff-pos .filter-input:focus,
    html.printflow-staff.printflow-staff-pos .filter-search-input:focus {
        box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.12) !important;
    }

    html.printflow-staff.printflow-staff-pos .service-count {
        background: var(--staff-service-count-bg) !important;
        color: var(--staff-primary) !important;
    }

    html.printflow-staff.printflow-staff-pos .pill-tab.active {
        background: linear-gradient(135deg, #f8fbff 0%, #edf4fc 52%, #dbe9f8 100%) !important;
        border-color: var(--staff-primary) !important;
        color: var(--staff-primary-deep) !important;
        box-shadow: 0 8px 18px rgba(var(--staff-accent-rgb), 0.12) !important;
    }

    html.printflow-staff.printflow-staff-pos .pill-tab.active .tab-count,
    html.printflow-staff.printflow-staff-pos .btn-primary,
    html.printflow-staff.printflow-staff-pos .notif-action-btn {
        background: var(--staff-primary) !important;
        border-color: var(--staff-primary) !important;
        color: #fff !important;
    }

    html.printflow-staff.printflow-staff-pos .btn-primary:hover,
    html.printflow-staff.printflow-staff-pos .notif-action-btn:hover {
        background: var(--staff-primary-strong) !important;
    }

    html.printflow-staff.printflow-staff-pos .loading-progress,
    html.printflow-staff.printflow-staff-pos .pf-fg-spinner,
    html.printflow-staff.printflow-staff-pos [style*="border-top-color:#06A1A1"],
    html.printflow-staff.printflow-staff-pos [style*="border-top-color: #06A1A1"] {
        border-top-color: var(--staff-primary) !important;
    }

    html.printflow-staff.printflow-staff-pos .chart-title,
    html.printflow-staff.printflow-staff-pos .page-title,
    html.printflow-staff.printflow-staff-pos .notif-card-head h3,
    html.printflow-staff.printflow-staff-pos .tp-name,
    html.printflow-staff.printflow-staff-pos .notif-msg,
    html.printflow-staff.printflow-staff-pos .om-value,
    html.printflow-staff.printflow-staff-pos [style*="color:#013a3a"],
    html.printflow-staff.printflow-staff-pos [style*="color: #013a3a"] {
        color: var(--staff-primary-deep) !important;
    }

    html.printflow-staff.printflow-staff-pos [style*="background:#06A1A1"],
    html.printflow-staff.printflow-staff-pos [style*="background: #06A1A1"],
    html.printflow-staff.printflow-staff-pos [style*="background:#0d9488"],
    html.printflow-staff.printflow-staff-pos [style*="background: #0d9488"],
    html.printflow-staff.printflow-staff-pos [style*="background:#058f8f"],
    html.printflow-staff.printflow-staff-pos [style*="background: #058f8f"],
    html.printflow-staff.printflow-staff-pos [style*="background:#047676"],
    html.printflow-staff.printflow-staff-pos [style*="background: #047676"],
    html.printflow-staff.printflow-staff-pos [style*="background:#035f5f"],
    html.printflow-staff.printflow-staff-pos [style*="background: #035f5f"],
    html.printflow-staff.printflow-staff-pos [style*="background:linear-gradient(135deg,#06A1A1,#047676)"],
    html.printflow-staff.printflow-staff-pos [style*="background: linear-gradient(135deg,#06A1A1,#047676)"],
    html.printflow-staff.printflow-staff-pos [style*="background:linear-gradient(135deg, #06A1A1 0%, #048888 100%)"],
    html.printflow-staff.printflow-staff-pos [style*="background: linear-gradient(135deg, #06A1A1 0%, #048888 100%)"] {
        background: var(--staff-primary) !important;
        border-color: var(--staff-primary) !important;
        color: #fff !important;
    }

    html.printflow-staff.printflow-staff-pos [style*="background:#f0fdfa"],
    html.printflow-staff.printflow-staff-pos [style*="background: #f0fdfa"],
    html.printflow-staff.printflow-staff-pos [style*="background:#e6f7f5"],
    html.printflow-staff.printflow-staff-pos [style*="background: #e6f7f5"],
    html.printflow-staff.printflow-staff-pos [style*="background:#f0fbfb"],
    html.printflow-staff.printflow-staff-pos [style*="background: #f0fbfb"],
    html.printflow-staff.printflow-staff-pos [style*="background:#ecfeff"],
    html.printflow-staff.printflow-staff-pos [style*="background: #ecfeff"] {
        background: var(--staff-toolbar-active-bg) !important;
        border-color: rgba(var(--staff-accent-rgb), 0.22) !important;
        color: var(--staff-primary-deep) !important;
    }

    html.printflow-staff.printflow-staff-pos [style*="color:#06A1A1"],
    html.printflow-staff.printflow-staff-pos [style*="color: #06A1A1"],
    html.printflow-staff.printflow-staff-pos [style*="color:#0d9488"],
    html.printflow-staff.printflow-staff-pos [style*="color: #0d9488"],
    html.printflow-staff.printflow-staff-pos [style*="color:#0f766e"],
    html.printflow-staff.printflow-staff-pos [style*="color: #0f766e"] {
        color: var(--staff-primary) !important;
    }

    html.printflow-staff.printflow-staff-pos [style*="border-color:#06A1A1"],
    html.printflow-staff.printflow-staff-pos [style*="border-color: #06A1A1"],
    html.printflow-staff.printflow-staff-pos [style*="border-color:#0d9488"],
    html.printflow-staff.printflow-staff-pos [style*="border-color: #0d9488"],
    html.printflow-staff.printflow-staff-pos [style*="border-color:#99f6e4"],
    html.printflow-staff.printflow-staff-pos [style*="border-color: #99f6e4"],
    html.printflow-staff.printflow-staff-pos [style*="border-color:#5eead4"],
    html.printflow-staff.printflow-staff-pos [style*="border-color: #5eead4"] {
        border-color: var(--staff-primary) !important;
    }

    /* Staff dashboard follows the tighter Admin dashboard card composition. */
    html.printflow-staff body.staff-dashboard-page .card {
        border-color: #e5e7eb !important;
        border-radius: var(--pf-ui-dashboard-card-radius) !important;
        padding: var(--pf-ui-dashboard-card-padding) !important;
        margin-bottom: 0 !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
    }

    html.printflow-staff body.staff-dashboard-page .grid-cols-3 {
        gap: var(--pf-ui-dashboard-gap) !important;
        margin-bottom: 24px !important;
    }

    html.printflow-staff body.staff-dashboard-page .chart-wrap {
        height: 250px !important;
        margin-top: 0 !important;
    }

    html.printflow-staff body.staff-dashboard-page .service-item {
        padding: 8px 0 !important;
    }

    html.printflow-staff body.staff-dashboard-page .service-info {
        font-size: 13px !important;
        font-weight: 600 !important;
    }

    html.printflow-staff body.staff-dashboard-page .service-count {
        font-size: 11px !important;
        font-weight: 700 !important;
        padding: 4px 10px !important;
    }

    html.printflow-staff body.staff-dashboard-page .table-responsive {
        margin: 0 -20px;
    }

    html.printflow-staff body.staff-dashboard-page .table-responsive table th:first-child,
    html.printflow-staff body.staff-dashboard-page .table-responsive table td:first-child {
        padding-left: 20px !important;
    }

    html.printflow-staff body.staff-dashboard-page .table-responsive table th:last-child,
    html.printflow-staff body.staff-dashboard-page .table-responsive table td:last-child {
        padding-right: 20px !important;
    }

    /* Staff-wide mobile hardening (parallel to admin shell behavior). */
    html.printflow-staff .truncate-ellipsis {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: block;
        max-width: 100%;
    }

    @media (max-width: 768px) {
        html.printflow-staff .content-area,
        html.printflow-staff main {
            padding-left: 14px !important;
            padding-right: 14px !important;
            padding-bottom: 18px !important;
        }

        html.printflow-staff .top-bar,
        html.printflow-staff header {
            padding: 12px 14px !important;
        }

        html.printflow-staff .page-title,
        html.printflow-staff h1,
        html.printflow-staff h2 {
            font-size: 20px !important;
            line-height: 1.25 !important;
        }

        html.printflow-staff .page-subtitle,
        html.printflow-staff .pv-subtitle,
        html.printflow-staff .pf-page-subtitle {
            font-size: 13px !important;
            line-height: 1.45 !important;
        }

        html.printflow-staff .toolbar-container,
        html.printflow-staff .toolbar-group,
        html.printflow-staff .table-header,
        html.printflow-staff .filter-actions {
            flex-wrap: wrap !important;
            gap: 8px !important;
            min-width: 0 !important;
        }

        html.printflow-staff .toolbar-container > *,
        html.printflow-staff .toolbar-group > * {
            min-width: 0 !important;
            max-width: 100% !important;
        }

        html.printflow-staff .pill-tab {
            max-width: 100% !important;
        }

        html.printflow-staff .pill-tab > :first-child {
            max-width: 96px !important;
        }

        html.printflow-staff .card,
        html.printflow-staff .stat-card,
        html.printflow-staff .chart-card {
            padding: 14px !important;
        }

        html.printflow-staff body.staff-dashboard-page .card {
            padding: 18px !important;
            border-radius: 10px !important;
        }

        html.printflow-staff .modal-header,
        html.printflow-staff .cv2-modal-head,
        html.printflow-staff .products-view-modal-header,
        html.printflow-staff .pv-drawer-head,
        html.printflow-staff .modal-body,
        html.printflow-staff .cv2-modal-body,
        html.printflow-staff .products-view-modal-body,
        html.printflow-staff .modal-footer,
        html.printflow-staff .cv2-modal-foot {
            padding: 18px !important;
        }

        html.printflow-staff .kpi-row {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 12px !important;
        }

        html.printflow-staff .pv-kpi-row,
        html.printflow-staff .cv2-kpis {
            gap: 12px !important;
        }

        html.printflow-staff .kpi-card,
        html.printflow-staff .kpi-card-v2,
        html.printflow-staff .stat-card,
        html.printflow-staff .cv2-kpi {
            min-width: 0 !important;
            width: 100% !important;
        }

        html.printflow-staff .kpi-card,
        html.printflow-staff .cv2-kpi {
            padding: 14px 12px !important;
            border-radius: 10px !important;
        }

        html.printflow-staff .kpi-label,
        html.printflow-staff .cv2-kpi-title {
            font-size: 10px !important;
        }

        html.printflow-staff .kpi-value,
        html.printflow-staff .stat-value,
        html.printflow-staff .cv2-kpi-num {
            font-size: 20px !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }

        html.printflow-staff .kpi-sub,
        html.printflow-staff .cv2-kpi-sub {
            font-size: 11px !important;
        }

        html.printflow-staff .overflow-x-auto,
        html.printflow-staff .table-responsive,
        html.printflow-staff .pf-table-scroll,
        html.printflow-staff .card:has(table),
        html.printflow-staff .chart-card:has(table) {
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            max-width: 100% !important;
        }

        html.printflow-staff table:not(.receipt-items):not(.orders-table):not(.products-table) {
            min-width: max(700px, 100%) !important;
        }

        html.printflow-staff .products-table {
            width: 100% !important;
            min-width: 0 !important;
        }

        html.printflow-staff table:not(.receipt-items):not(.orders-table):not(.products-table) th,
        html.printflow-staff table:not(.receipt-items):not(.orders-table):not(.products-table) td {
            white-space: nowrap !important;
        }

        html.printflow-staff .btn-action,
        html.printflow-staff .btn-action-primary,
        html.printflow-staff .btn-action-secondary,
        html.printflow-staff .btn-action-danger,
        html.printflow-staff .btn-staff-action {
            min-height: 34px !important;
            width: 100% !important;
        }

        /* .table-action-btn inside .action-cell must share width equally (View + Message) */
        html.printflow-staff .action-cell .table-action-btn {
            flex: 1 1 0 !important;
            min-width: 0 !important;
            width: auto !important;
            max-width: 50% !important;
            min-height: 34px !important;
        }

        /* standalone .table-action-btn outside action-cell stays full-width */
        html.printflow-staff .table-action-btn:not(.action-cell *) {
            min-height: 34px !important;
            width: 100% !important;
        }

        html.printflow-staff .filter-panel,
        html.printflow-staff .sort-dropdown {
            width: min(420px, calc(100vw - 24px)) !important;
            min-width: 0 !important;
            max-width: calc(100vw - 24px) !important;
        }

        html.printflow-staff .modal-panel,
        html.printflow-staff .modal-content,
        html.printflow-staff .modal-dialog,
        html.printflow-staff .modal-box,
        html.printflow-staff .chat-modal-shell {
            width: min(100%, 560px) !important;
            max-width: calc(100vw - 24px) !important;
            margin: 64px auto 20px !important;
            max-height: calc(100dvh - 88px) !important;
            overflow: auto !important;
        }
    }
</style>
