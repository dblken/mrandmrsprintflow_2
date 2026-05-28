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
    }

    html.printflow-staff.printflow-staff-pos {
        --accent-color: #2563eb;
        --staff-primary: #2563eb;
        --staff-soft: #bfdbfe;
    }

    html.printflow-staff.printflow-staff-online {
        --accent-color: #7c3aed;
        --staff-primary: #7c3aed;
        --staff-soft: #ddd6fe;
    }

    /* Main area: focus rings & links */
    html.printflow-staff .input-field:focus,
    html.printflow-staff select:focus,
    html.printflow-staff input:focus {
        border-color: var(--staff-primary);
        box-shadow: 0 0 0 3px rgba(6, 161, 161, 0.18);
    }

    html.printflow-staff .btn-primary {
        background: #06A1A1;
        color: #fff;
    }

    html.printflow-staff .btn-primary:hover {
        background: #058f8f;
        box-shadow: 0 4px 14px rgba(6, 161, 161, 0.35);
    }

    /* Sidebar shell */
    html.printflow-staff .sidebar {
        background: linear-gradient(180deg, #011818 0%, #022a2a 24%, #033838 55%, #044040 100%);
        border-right: 1px solid rgba(6, 161, 161, 0.22);
        box-shadow: 4px 0 24px rgba(0, 48, 48, 0.14);
    }

    html.printflow-staff .sidebar-header {
        border-bottom: 1px solid rgba(158, 215, 196, 0.18);
    }

    html.printflow-staff .sidebar-header .logo img {
        border-color: rgba(158, 215, 196, 0.4) !important;
    }

    html.printflow-staff .logo-icon {
        background: linear-gradient(135deg, #035050, #06A1A1);
        border-color: rgba(158, 215, 196, 0.35);
    }

    html.printflow-staff .sidebar-collapse-btn {
        border-color: rgba(6, 161, 161, 0.28);
        color: #9ED7C4;
    }

    html.printflow-staff .sidebar-collapse-btn:hover {
        border-color: rgba(158, 215, 196, 0.45);
        color: #fff;
    }

    html.printflow-staff #mobileBurger {
        background: linear-gradient(135deg, #022e2e, #06A1A1);
        border-color: rgba(158, 215, 196, 0.35);
    }

    html.printflow-staff #mobileBurger:hover {
        background: linear-gradient(135deg, #035f5f, #09b5b5);
        border-color: rgba(158, 215, 196, 0.5);
    }

    html.printflow-staff .nav-section-title {
        color: rgba(158, 215, 196, 0.55);
    }

    html.printflow-staff .nav-item {
        color: rgba(220, 245, 238, 0.9);
    }

    html.printflow-staff .nav-item:hover {
        color: #f6fffc;
    }

    html.printflow-staff .nav-item.active {
        background: linear-gradient(135deg, #f7fefb 0%, #e5f9f2 42%, #d4f0e6 100%);
        color: #023d3d;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.9);
    }

    html.printflow-staff .nav-item.active .nav-icon {
        color: #023d3d;
        stroke: #023d3d;
    }

    html.printflow-staff .nav-item.active:hover {
        background: linear-gradient(135deg, #ffffff 0%, #eefaf5 50%, #dff5ec 100%);
        color: #012828;
    }

    html.printflow-staff .sidebar-footer {
        border-top: 1px solid rgba(6, 161, 161, 0.2);
    }

    html.printflow-staff .user-avatar {
        background: linear-gradient(135deg, #047676 0%, #06A1A1 55%, #9ED7C4 100%);
        border-color: rgba(158, 215, 196, 0.45);
    }

    html.printflow-staff .sidebar.collapsed .nav-item.active .nav-icon {
        color: #023d3d;
        stroke: #023d3d;
    }

    html.printflow-staff .sidebar.collapsed .nav-section-title::after {
        color: rgba(158, 215, 196, 0.5);
    }

    html.printflow-staff .sidebar-nav {
        scrollbar-color: rgba(6, 161, 161, 0.35) transparent;
    }

    html.printflow-staff .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(6, 161, 161, 0.28);
    }

    html.printflow-staff .sidebar-nav:hover::-webkit-scrollbar-thumb {
        background: rgba(6, 161, 161, 0.45);
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
        background: linear-gradient(90deg, #035f5f, #06A1A1, #9ED7C4) !important;
    }

    html.printflow-staff .kpi-label,
    html.printflow-staff .kpi-lbl {
        background: linear-gradient(90deg, #023d3d, #06A1A1) !important;
        -webkit-background-clip: text !important;
        background-clip: text !important;
        color: transparent !important;
        -webkit-text-fill-color: transparent !important;
    }

    html.printflow-staff .stats-grid .stat-card::before,
    html.printflow-staff .stat-card:not(.no-stat-accent)::before {
        background: linear-gradient(90deg, #035f5f, #06A1A1, #9ED7C4);
    }

    html.printflow-staff .stat-label {
        color: #047676;
    }

    /* Form guard (sidebar portal) */
    html.printflow-staff .pf-fg-spinner {
        border-color: rgba(6, 161, 161, 0.3);
        border-top-color: #06A1A1;
    }

    html.printflow-staff .pf-fg-save-highlight {
        box-shadow: 0 0 0 2px rgba(6, 161, 161, 0.85) !important;
    }

    html.printflow-staff .pf-fg-btn--accent {
        background: #06A1A1;
        color: #fff;
        border-color: #023d3d;
        box-shadow: 0 2px 10px rgba(6, 161, 161, 0.35);
    }

    html.printflow-staff .pf-fg-btn--accent:hover:not(:disabled) {
        background: #058f8f;
    }

    html.printflow-staff .pf-fg-btn--discard {
        background: #023d3d;
        color: #9ED7C4;
        border-color: #023d3d;
    }

    html.printflow-staff .pf-fg-btn--discard:hover:not(:disabled) {
        background: #035050;
        color: #c8efe0;
    }

    html.printflow-staff .pf-fg-btn--neutral {
        border-color: #06A1A1;
        color: #023d3d;
    }

    html.printflow-staff .pf-fg-btn--neutral:hover:not(:disabled) {
        background: rgba(158, 215, 196, 0.25);
    }

    html.printflow-staff .pf-fg-nav-modal__title,
    html.printflow-staff .pf-fg-nav-modal__sub {
        color: #023d3d;
    }

    html.printflow-staff .pf-fg-nav-modal__list {
        background: linear-gradient(135deg, rgba(158, 215, 196, 0.2), rgba(6, 161, 161, 0.08));
        border-color: rgba(6, 161, 161, 0.35);
        border-left-color: #06A1A1;
    }

    html.printflow-staff .pf-fg-nav-modal__list li::before {
        background: #06A1A1;
    }

    /* Unified Table Action Buttons */
    html.printflow-staff .btn-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 12px;
        min-height: 28px;
        font-size: 12px;
        font-weight: 700;
        border-radius: 6px;
        transition: all 0.2s;
        text-decoration: none;
        border: none;
        cursor: pointer;
    }

    html.printflow-staff .btn-action-primary {
        background: rgba(6, 161, 161, 0.12);
        color: #058f8f;
    }

    html.printflow-staff .btn-action-primary:hover {
        background: #06A1A1;
        color: #ffffff;
        transform: translateY(-1px);
    }

    html.printflow-staff .btn-action-secondary {
        background: rgba(124, 58, 237, 0.1);
        color: #7c3aed;
    }

    html.printflow-staff .btn-action-secondary:hover {
        background: #7c3aed;
        color: #ffffff;
        transform: translateY(-1px);
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
        gap: 8px;
        height: 38px;
        padding: 0 14px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        color: #475569;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }
    html.printflow-staff .toolbar-btn:hover {
        border-color: #06A1A1;
        color: #06A1A1;
        background: #f0fdfa;
    }
    html.printflow-staff .toolbar-btn.active {
        border-color: #06A1A1;
        background: #f0fdfa;
        color: #06A1A1;
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
        padding: 10px 14px;
        font-size: 14px;
        font-weight: 500;
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
        background: rgba(6, 161, 161, 0.08);
        color: #058f8f;
        font-weight: 700;
    }

    html.printflow-staff .filter-panel {
        width: 320px;
        padding: 0;
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
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.025em;
        color: #64748b;
    }
    html.printflow-staff .filter-reset-link {
        font-size: 12px;
        font-weight: 600;
        color: #06A1A1;
        background: none;
        border: none;
        padding: 0;
        cursor: pointer;
    }
    html.printflow-staff .filter-select, 
    html.printflow-staff .filter-input {
        width: 100%;
        height: 38px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0 12px;
        font-size: 14px;
        color: #1e293b;
        transition: all 0.2s;
    }
    html.printflow-staff .filter-select:focus,
    html.printflow-staff .filter-input:focus {
        border-color: #06A1A1;
        background: #fff;
        outline: none;
        box-shadow: 0 0 0 3px rgba(6, 161, 161, 0.1);
    }
    html.printflow-staff .filter-footer {
        padding: 14px 18px;
        background: #f8fafc;
    }
    html.printflow-staff .filter-btn-reset {
        width: 100%;
        height: 38px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        color: #64748b;
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
        background: #06A1A1;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        border-radius: 50%;
        margin-left: 4px;
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

        html.printflow-staff .kpi-row {
            grid-template-columns: repeat(2, 1fr) !important;
        }

        html.printflow-staff .kpi-card,
        html.printflow-staff .kpi-card-v2,
        html.printflow-staff .stat-card {
            min-width: 0 !important;
            width: 100% !important;
        }

        html.printflow-staff .kpi-value,
        html.printflow-staff .stat-value {
            font-size: 20px !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
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

        html.printflow-staff table {
            min-width: max(700px, 100%) !important;
        }

        html.printflow-staff table th,
        html.printflow-staff table td {
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
