<?php
/**
 * Staff: Job Orders Management
 * Production tracking & material assignment.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role('Staff');
$page_title = 'Production Workflow - PrintFlow';

$staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);
$joBranchSql = ' AND COALESCE(jo.branch_id, (SELECT o2.branch_id FROM orders o2 WHERE o2.order_id = jo.order_id LIMIT 1)) = ?';
$jT = 'i';
$jP = [$staffBranchId];

// Get statistics for KPIs (this branch only)
$total_jobs = db_query("SELECT COUNT(*) as count FROM job_orders jo WHERE 1=1" . $joBranchSql, $jT, $jP)[0]['count'];
$pending_jobs = db_query("SELECT COUNT(*) as count FROM job_orders jo WHERE status = 'PENDING'" . $joBranchSql, $jT, $jP)[0]['count'];
$approval_jobs = db_query("SELECT COUNT(*) as count FROM job_orders jo WHERE status = 'APPROVED'" . $joBranchSql, $jT, $jP)[0]['count'];
$in_production = db_query("SELECT COUNT(*) as count FROM job_orders jo WHERE status = 'IN_PRODUCTION'" . $joBranchSql, $jT, $jP)[0]['count'];
$completed_jobs = db_query("SELECT COUNT(*) as count FROM job_orders jo WHERE status = 'COMPLETED'" . $joBranchSql, $jT, $jP)[0]['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/css/output.css'); ?>">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        /* Standardized classes used from admin_style.php */

        /* Action Button Style — matches customers_management.php */
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-action.teal { color: #14b8a6; border-color: #14b8a6; }
        .btn-action.teal:hover { background: #14b8a6; color: white; }
        .btn-action.blue { color: #06A1A1; border-color: #06A1A1; }
        .btn-action.blue:hover { background: #06A1A1; color: white; }
        .btn-action.red { color: #ef4444; border-color: #ef4444; }
        .btn-action.red:hover { background: #ef4444; color: white; }
        .btn-action.amber { color: #f59e0b; border-color: #f59e0b; }
        .btn-action.amber:hover { background: #f59e0b; color: white; }
        .btn-action.emerald { color: #059669; border-color: #059669; }
        .btn-action.emerald:hover { background: #059669; color: white; }

        /* Refined Enterprise Table Styles (Uniform with Orders Page) */
        .pill-tab { 
            padding: 8px 16px; 
            font-weight: 600; 
            font-size: 13px; 
            color: #6b7280; 
            border-radius: 9999px; 
            transition: all 0.2s; 
            display: flex; 
            align-items: center; 
            gap: 8px;
            background: transparent;
        }
        .pill-tab:hover { background: #f3f4f6; color: #111827; }
        .pill-tab.active { background: #e6f7f5; color: #4f46e5; border: 1px solid #4f46e5; }
        .tab-count { 
            background: #4f46e5; 
            color: white; 
            font-size: 10px; 
            padding: 1px 6px; 
            border-radius: 9999px; 
            font-weight: 600;
        }
        .pill-tab:not(.active) .tab-count { background: #e5e7eb; color: #6b7280; }

        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        /* Status Colors to match system standard */
        .badge-fulfilled { background: #dcfce7; color: #15803d; }
        .badge-confirmed { background: #e0f2fe; color: #0369a1; }
        .badge-partial { background: #fef3c7; color: #a16207; }
        .badge-cancelled { background: #fee2e2; color: #b91c1c; }

        /* Unified Table Typography */
        .table-text-main { font-size: 13px; color: #111827; font-weight: 500; }
        .table-text-sub { font-size: 11px; color: #6b7280; font-weight: 400; }
        
        thead th { 
            font-size: 11px; 
            font-weight: 600; 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            color: #6b7280;
            background: #f9fafb;
            border-bottom: 2px solid #f3f4f6;
        }

        .row-indicator {
            position: absolute;
            left: 0;
            top: 2px;
            bottom: 2px;
            width: 3px;
            background: #4f46e5;
            border-radius: 0 4px 4px 0;
            opacity: 0;
            transition: opacity 0.2s;
        }
        tr:hover .row-indicator { opacity: 1; }

        .modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:9999; }
        .modal-panel { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); width:100%; max-width:560px; max-height:88vh; overflow-y:auto; margin:16px; position:relative; }
        @keyframes spin { to { transform: rotate(360deg); } }
        [x-cloak] { display: none !important; }

        /* ── Order Detail Modal (imported from orders.php) ─────────────────────────────────── */
        .om-backdrop {
            position: absolute; inset: 0;
            background: rgba(15,23,42,0.55);
            backdrop-filter: blur(4px);
            transition: opacity 0.25s ease;
        }

        .om-panel {
            position: relative; z-index: 1;
            background: #fff;
            border-radius: 20px;
            width: 100%; max-width: 1400px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.25);
            transform: translateY(0) scale(1);
        }

        .om-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 24px 28px 20px;
            border-bottom: 1px solid #f1f5f9;
            position: sticky; top: 0; background: #fff; border-radius: 20px 20px 0 0; z-index: 2;
        }
        .om-title { font-size: 1.35rem; font-weight: 800; color: #0f172a; }
        .om-subtitle { font-size: 0.78rem; color: #94a3b8; margin-top: 2px; }
        .om-close {
            width: 36px; height: 36px; border-radius: 50%;
            border: none; background: #f1f5f9; color: #64748b;
            cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; justify-content: center;
            transition: background 0.15s, color 0.15s;
        }
        .om-close:hover { background: #e2e8f0; color: #0f172a; }

        .om-body { padding: 24px 28px 28px; }
        .om-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 700px) { .om-grid { grid-template-columns: 1fr; } }

        .om-card {
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 20px;
        }
        .om-card-title {
            font-size: 0.7rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.07em; color: #94a3b8; margin-bottom: 14px;
        }
        .om-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13.5px;
        }
        .om-row:last-child { border-bottom: none; }
        .om-label { color: #6b7280; }
        .om-value { font-weight: 600; color: #1e293b; text-align: right; }

        .om-notes {
            margin-top: 14px; padding: 14px 16px;
            background: linear-gradient(135deg,#fffbeb,#fef3c7);
            border: 1px solid #fde68a; border-radius: 12px;
            max-height: 120px; overflow-y: auto;
        }
        .om-notes-title { font-size: 12px; font-weight: 800; color: #92400e; margin-bottom: 6px; }
        .om-notes-text { font-size: 13px; color: #b45309; line-height: 1.6; overflow-wrap: anywhere; word-break: break-word; }

        .om-cust-header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .om-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg,#667eea,#764ba2);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 16px; flex-shrink: 0;
        }

        .om-items-section { margin-top: 20px; }
    </style>
</head>
<body x-data="joManager('ALL')" data-csrf="<?php echo htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<div class="dashboard-container">
    <?php 
    if ($_SESSION['user_type'] === 'Staff') {
        include __DIR__ . '/../includes/staff_sidebar.php';
    } else {
        include __DIR__ . '/../includes/admin_sidebar.php';
    }
    ?>
    <div class="main-content">
        <header>
            <div>
                <h1 class="page-title">Production Workflow</h1>
                <p class="page-subtitle">Track and manage active print jobs and material tasks</p>
            </div>
        </header>

        <main>
            <!-- Standardized KPI Row -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Total Jobs</span>
                        <span class="kpi-value"><?php echo number_format($total_jobs); ?></span>
                        <span class="kpi-sub"><?php echo $completed_jobs; ?> completed</span>
                    </span>
                </div>
                <div class="kpi-card amber">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Pending Review</span>
                        <span class="kpi-value"><?php echo $pending_jobs; ?></span>
                        <span class="kpi-sub">Awaiting production start</span>
                    </span>
                </div>
                <div class="kpi-card blue">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Approved Jobs</span>
                        <span class="kpi-value"><?php echo $approval_jobs; ?></span>
                        <span class="kpi-sub">Ready for material assignment</span>
                    </span>
                </div>
                <div class="kpi-card emerald">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">In Production</span>
                        <span class="kpi-value"><?php echo $in_production; ?></span>
                        <span class="kpi-sub">Actively printing / processing</span>
                    </span>
                </div>
            </div>

            <!-- Standardized Toolbar -->
            <div class="card overflow-visible">
                <div class="toolbar-container" style="flex-wrap: wrap; gap: 16px;">
                    <div style="display:flex; gap:8px; overflow-x: auto; padding-bottom: 4px;">
                        <template x-for="st in statuses">
                            <button 
                                @click="activeStatus = st" 
                                :class="activeStatus === st ? 'active' : ''"
                                class="pill-tab"
                                style="white-space: nowrap;"
                            >
                                <span x-text="st"></span>
                                <span class="tab-count" x-text="getStatusCount(st)"></span>
                            </button>
                        </template>
                    </div>

                    <div class="toolbar-group" style="margin-left: auto;">
                        <!-- Search Bar -->
                        <div class="toolbar-search">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" x-model="search" placeholder="Search...">
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto -mx-6 px-6">
                    <table class="w-full text-sm text-left border-separate border-spacing-0">
                        <thead class="bg-gray-50/50">
                            <tr>
                                <th class="pl-6 pr-4 py-4 w-[12%] border-b border-gray-100">Order #</th>
                                <th class="px-4 py-4 w-[30%] border-b border-gray-100">Job Information</th>
                                <th class="px-4 py-4 w-[18%] border-b border-gray-100 text-center">Status</th>
                                <th class="px-4 py-4 w-[20%] border-b border-gray-100">Customer</th>
                                <th class="px-4 py-4 w-[15%] border-b border-gray-100 text-right">Created</th>
                                <th class="px-4 py-4 w-[10%] border-b border-gray-100 text-center uppercase tracking-widest text-[10px]">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="jo in filteredOrders" :key="jo.id">
                                <tr @click="viewDetails(jo.id)" class="group transition-all hover:bg-gray-50/50 relative cursor-pointer">
                                    <td class="pl-6 pr-4 py-4 relative">
                                        <div class="row-indicator"></div>
                                        <span class="table-text-main" x-text="'#JO-' + jo.id.toString().padStart(5, '0')"></span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex flex-col gap-0 min-w-0">
                                                <div class="table-text-main truncate" x-text="jo.job_title || jo.service_type"></div>
                                                <div class="table-text-sub uppercase tracking-wider"><span x-text="jo.width_ft"></span>'×<span x-text="jo.height_ft"></span>' • <span x-text="jo.quantity"></span> pcs</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <div :class="{
                                            'badge-fulfilled': jo.readiness === 'READY' || jo.status === 'COMPLETED' || jo.status === 'TO_RECEIVE',
                                            'badge-confirmed': jo.status === 'APPROVED' || jo.status === 'IN_PRODUCTION' || jo.status === 'TO_PAY',
                                            'badge-partial': jo.readiness === 'LOW' || jo.status === 'PENDING',
                                            'badge-cancelled': jo.readiness === 'MISSING' || jo.status === 'CANCELLED'
                                        }" class="status-pill" x-text="jo.status === 'COMPLETED' ? 'Fulfilled' : 
                                           (jo.status === 'APPROVED' ? 'Approved' : 
                                           (jo.status === 'TO_PAY' ? 'To Pay' : 
                                           (jo.status === 'IN_PRODUCTION' ? 'Processing' : 
                                           (jo.status === 'TO_RECEIVE' ? 'To Receive' : jo.status))))">
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="table-text-main" x-text="jo.first_name + ' ' + (jo.last_name || '')"></div>
                                        <div style="margin-top:4px;">
                                            <span style="font-size:10px; font-weight:500;" class="status-pill" :class="jo.customer_type === 'NEW' ? 'badge-confirmed' : 'badge-fulfilled'" x-text="jo.customer_type"></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <div class="table-text-main" x-text="jo.created_at ? new Date(jo.created_at).toLocaleDateString(undefined, {month:'long', day:'numeric', year:'numeric'}) : ''"></div>
                                        <div class="table-text-sub uppercase" x-text="jo.due_date ? 'Due ' + new Date(jo.due_date).toLocaleDateString() : ''"></div>
                                    </td>
                                    <td class="px-4 py-4 text-center space-x-1">
                                        <button @click.stop="viewDetails(jo.id)" class="btn-action blue">View</button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="filteredOrders.length === 0">
                                <td colspan="6" class="px-6 py-24 text-center">
                                    <span class="table-text-sub uppercase tracking-widest">No matching jobs in this stage</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

    <!-- No more materials modal - integrated into details -->

<!-- Image Preview Lightbox -->
<div x-show="previewFile" x-cloak style="position:fixed; inset:0; background:rgba(0,0,0,0.9); z-index:10000; display:flex; align-items:center; justify-content:center; padding:40px;">
    <button @click="previewFile = null" style="position:fixed; top:20px; right:25px; background:rgba(255,255,255,0.1); border:none; color:white; font-size:40px; width:50px; height:50px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">&times;</button>
    <div style="max-width:100%; max-height:100%; position:relative;">
        <img :src="previewFile" style="max-width:100%; max-height:85vh; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.1);">
        <div style="margin-top:20px; text-align:center;">
            <a :href="previewFile" download style="background:white; color:#1f2937; padding:10px 24px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:600; display:inline-flex; align-items:center; gap:8px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Download Artwork
            </a>
        </div>
    </div>
</div>

<!-- Job Details Modal — matching orders.php style -->
<div x-show="showDetailsModal" x-cloak style="position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;">
    <!-- Backdrop -->
    <div class="om-backdrop" @click="showDetailsModal = false"></div>
    
    <!-- Modal Panel -->
    <div class="om-panel" @click.stop x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
        
        <!-- Header -->
        <div class="om-header">
            <div>
                <div class="om-title" x-text="currentJo.id ? 'Job #JO-' + currentJo.id : 'Order Details'"></div>
                <div class="om-subtitle" x-text="currentJo.service_type || 'Loading...'"></div>
            </div>
            <button class="om-close" @click="showDetailsModal = false" aria-label="Close">✕</button>
        </div>

        <!-- Body -->
        <div class="om-body">
            
            <!-- Loading State -->
            <div x-show="loadingDetails" class="om-loader">
                <div class="om-spinner"></div>
                <div style="color:#94a3b8; font-size:14px;">Fetching order details…</div>
            </div>

            <!-- Content -->
            <div x-show="!loadingDetails && currentJo.id">

                <div class="om-grid">
                    
                    <!-- Left Column: Order Information -->
                    <div class="om-card">
                        <div class="om-card-title">Order Information</div>
                        <div class="om-row">
                            <span class="om-label">Service Type</span>
                            <span class="om-value" x-text="currentJo.service_type"></span>
                        </div>
                        <div class="om-row">
                            <span class="om-label">Status</span>
                            <span class="om-value">
                                <span class="status-pill" :class="{
                                    'badge-fulfilled': currentJo.status === 'COMPLETED' || currentJo.status === 'TO_RECEIVE',
                                    'badge-confirmed': currentJo.status === 'APPROVED' || currentJo.status === 'IN_PRODUCTION' || currentJo.status === 'TO_PAY',
                                    'badge-partial': currentJo.status === 'PENDING',
                                    'badge-cancelled': currentJo.status === 'CANCELLED'
                                }" x-text="currentJo.status"></span>
                            </span>
                        </div>
                        <div class="om-row">
                            <span class="om-label">Dimensions</span>
                            <span class="om-value" x-text="currentJo.width_ft + '\' × ' + currentJo.height_ft + '\''"></span>
                        </div>
                        <div class="om-row">
                            <span class="om-label">Quantity</span>
                            <span class="om-value" x-text="currentJo.quantity + ' pcs'"></span>
                        </div>
                        <div class="om-row">
                            <span class="om-label">Estimated Total</span>
                            <span class="om-value" style="color:#06A1A1; font-size:15px;" x-text="'₱' + Number(currentJo.estimated_total || 0).toLocaleString()"></span>
                        </div>
                        <div class="om-row">
                            <span class="om-label">Amount Paid</span>
                            <span class="om-value" x-text="'₱' + Number(currentJo.amount_paid || 0).toLocaleString()"></span>
                        </div>
                        <div class="om-row">
                            <span class="om-label">Priority</span>
                            <span class="om-value" :style="currentJo.priority === 'HIGH' ? 'color:#ef4444' : 'color:#1f2937'" x-text="currentJo.priority"></span>
                        </div>
                        <div class="om-row">
                            <span class="om-label">Due Date</span>
                            <span class="om-value" :style="isOverdue(currentJo.due_date) ? 'color:#ef4444;' : ''" x-text="currentJo.due_date || 'Not set'"></span>
                        </div>
                        <div class="om-row">
                            <span class="om-label">Date Ordered</span>
                            <span class="om-value" x-text="currentJo.created_at ? new Date(currentJo.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'N/A'"></span>
                        </div>
                        <div class="om-row">
                            <span class="om-label">Payment Method</span>
                            <span class="om-value" x-text="currentJo.payment_method || 'Cash'"></span>
                        </div>

                        <!-- Notes -->
                        <div x-show="currentJo.notes" class="om-notes">
                            <div class="om-notes-title">📝 Customer Notes / Instructions</div>
                            <div class="om-notes-text" x-text="currentJo.notes"></div>
                        </div>

                        <!-- Customization Details -->
                        <div x-show="currentJo.customization && Object.keys(currentJo.customization).length > 0" class="om-card" style="margin-top:20px; border:1px solid #e2e8f0; background:#f8fafc; padding:16px;">
                            <div class="om-card-title" style="font-size:13px; margin-bottom:12px; border-bottom:2px solid #e2e8f0; padding-bottom:8px;">Customization Details</div>
                            <div style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px;">
                                <template x-for="(value, key) in (currentJo.customization || {})" :key="key">
                                    <template x-if="!['unit', 'product_type', 'dimensions', 'service_type', 'branch_id'].includes(key) && value !== '' && key.toLowerCase() !== 'notes' && !key.toLowerCase().includes('description')">
                                        <div style="padding:6px 0;">
                                            <div style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase; letter-spacing:0.05em;" x-text="key.replace(/_/g, ' ')"></div>
                                            <div style="font-size:15px; font-weight:700; color:#1e293b;" x-text="value"></div>
                                        </div>
                                    </template>
                                </template>
                            </div>
                            <!-- Large Text Blocks for custom descriptions -->
                            <template x-for="(value, key) in (currentJo.customization || {})" :key="key + '_large'">
                                <template x-if="(key.toLowerCase().includes('description') || key.toLowerCase() === 'notes') && value !== '' && value !== currentJo.notes">
                                    <div style="margin-top:8px; padding:12px; background:#fffbeb; border:1px solid #fef3c7; border-radius:8px;">
                                        <div style="font-size:12px; font-weight:800; color:#92400e; text-transform:uppercase; margin-bottom:6px;" x-text="'📝 ' + key.replace(/_/g, ' ')"></div>
                                        <div style="font-size:14px; color:#b45309; line-height:1.5; font-weight:500;" x-html="String(value).replace(/\n/g,'<br>')"></div>
                                    </div>
                                </template>
                            </template>
                        </div>

                        <!-- Production Materials -->
                        <div style="margin-top:20px;">
                            <div class="om-card-title">Production Materials</div>
                            <div style="display:flex;flex-direction:column;gap:10px;">
                                <template x-if="currentJo.status === 'APPROVED'">
                                    <div style="background:#f5f3ff; border:1px solid #ddd6fe; padding:16px; border-radius:12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                        <div style="font-weight:700; font-size:12px; color:#4f46e5; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                            Assign Production Materials
                                        </div>

                                        <!-- Pending Materials Queue -->
                                        <template x-if="pendingMaterials.length > 0">
                                            <div style="margin-bottom:12px;">
                                                <div style="font-size:10px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">To Be Added:</div>
                                                <template x-for="(pm, idx) in pendingMaterials" :key="idx">
                                                    <div style="display:flex; align-items:center; justify-content:space-between; background:white; border:1px solid #e0e7ff; border-radius:8px; padding:8px 12px; margin-bottom:4px; font-size:12px;">
                                                        <span style="font-weight:600; color:#1f2937;" x-text="pm.name"></span>
                                                        <span style="color:#6b7280;" x-text="'× ' + pm.qty + ' ' + (pm.uom === 'l' ? 'Liter (L)' : pm.uom)"></span>
                                                        <button @click="pendingMaterials.splice(idx,1)" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px;">×</button>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        <div style="display:flex; flex-direction:column; gap:12px;">
                                            <!-- Item Selection -->
                                            <div>
                                                <select x-model="newMaterialId" @change="newMaterialId = $event.target.value; newMaterialRollId = ''; availableRollsList = []; if(isRollTracked(newMaterialId)) loadAvailableRolls(newMaterialId);" style="width:100%; padding:8px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:white;">
                                                    <option value="">-- Select Material / Item --</option>
                                                    <template x-for="item in filteredMaterials" :key="item.id">
                                                        <option :value="item.id" x-text="item.name"></option>
                                                    </template>
                                                </select>
                                            </div>

                                            <template x-if="newMaterialId">
                                                <div style="display:flex; flex-direction:column; gap:12px; animation: slideDown 0.2s ease-out;">
                                                    <!-- Dynamic Fields: Tarpaulin -->
                                                    <template x-if="isTarpaulin(newMaterialId)">
                                                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                                                            <div>
                                                                <label style="font-size:10px; font-weight:700; color:#6b7280; display:block; margin-bottom:4px;">Height (ft)</label>
                                                                <input type="number" x-model.number="newMaterialHeight" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px;" placeholder="Height">
                                                            </div>
                                                            <div>
                                                                <label style="font-size:10px; font-weight:700; color:#6b7280; display:block; margin-bottom:4px;">Quantity (pcs)</label>
                                                                <input type="number" x-model.number="newMaterialQty" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px;" placeholder="Qty">
                                                            </div>
                                                            <div style="grid-column: span 2;">
                                                                <label style="font-size:10px; font-weight:700; color:#6b7280; display:block; margin-bottom:4px;">Finishing (Optional)</label>
                                                                <input type="text" x-model="newMaterialMetadata.finishing" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px;" placeholder="e.g. Eyelets, Rope, Hemming">
                                                            </div>
                                                        </div>
                                                    </template>

                                                    <!-- Dynamic Fields: Printed Sticker -->
                                                    <template x-if="isSticker(newMaterialId)">
                                                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                                                            <div>
                                                                <label style="font-size:10px; font-weight:700; color:#6b7280; display:block; margin-bottom:4px;">Quantity (pcs)</label>
                                                                <input type="number" x-model.number="newMaterialQty" min="1" step="1" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px;" placeholder="e.g. 10 pcs">
                                                            </div>
                                                            <div>
                                                                <label style="font-size:10px; font-weight:700; color:#6b7280; display:block; margin-bottom:4px;">Lamination</label>
                                                                <select x-model="newMaterialMetadata.lamination" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:white;">
                                                                    <option value="">None</option>
                                                                    <option value="GLOSS">Gloss</option>
                                                                    <option value="MATTE">Matte</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </template>

                                                    <!-- Dynamic Fields: Plate / Generic -->
                                                    <template x-if="!isTarpaulin(newMaterialId) && !isSticker(newMaterialId)">
                                                        <div style="display:flex; gap:10px; align-items:flex-end;">
                                                            <div style="flex:1;">
                                                                <label style="font-size:10px; font-weight:700; color:#6b7280; display:block; margin-bottom:4px;">Quantity</label>
                                                                <input type="number" x-model.number="newMaterialQty" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px;" placeholder="Qty">
                                                            </div>
                                                        </div>
                                                    </template>

                                                    <!-- Roll Selector (for roll-tracked items) -->
                                                    <template x-if="isRollTracked(newMaterialId)">
                                                        <div>
                                                            <label style="font-size:10px; font-weight:700; color:#6b7280; display:block; margin-bottom:4px;">Select Roll <span style="font-weight:400;color:#9ca3af;">(optional — auto-picks oldest if skipped)</span></label>
                                                            <select x-model="newMaterialRollId" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:white;">
                                                                <option value="">— Auto-pick oldest open roll —</option>
                                                                <template x-for="roll in availableRollsList" :key="roll.id">
                                                                    <option :value="roll.id" x-text="(roll.roll_code || '#'+roll.id) + ' — ' + roll.remaining_length_ft + ' ft left'"></option>
                                                                </template>
                                                            </select>
                                                        </div>
                                                    </template>

                                                    <!-- Notes -->
                                                    <div>
                                                        <label style="font-size:10px; font-weight:700; color:#6b7280; display:block; margin-bottom:4px;">Production Notes</label>
                                                        <textarea x-model="newMaterialNotes" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; font-size:12px; min-height:40px;" placeholder="Notes for this material..."></textarea>
                                                    </div>

                                                    <!-- Add to Queue button -->
                                                    <button @click="addMaterialToQueue()" :disabled="!newMaterialId" style="width:100%; padding:9px; background:#4f46e5; color:white; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">+ Add to Material List</button>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    
                                    <!-- Ink Used Section -->
                                    <div style="margin-top: 16px;">
                                        <label style="font-size:11px; font-weight:600; color:#9ca3af; text-transform:uppercase; display:block; margin-bottom:8px;">Ink Used</label>
                                        <select x-model="selectedInkId" style="width:100%; padding:10px 14px; border:1px solid #e5e7eb; border-radius:10px; font-size:13px; background:white; font-weight:500;">
                                            <option value="">-- Select Ink Type --</option>
                                            <template x-for="ink in filteredInks" :key="ink.id">
                                                <option :value="ink.id" x-text="ink.name"></option>
                                            </template>
                                        </select>
                                    </div>
                                </template>
                            </div>

                            <!-- List assigned materials -->
                            <div style="margin-top:16px;" x-show="currentJo.materials && currentJo.materials.length > 0">
                                <template x-for="mat in (currentJo.materials || [])" :key="mat.id">
                                    <div style="display:flex; justify-content:space-between; align-items:center; border: 1px solid #f1f5f9; padding:10px; border-radius: 8px; margin-bottom:8px; background: #fff;">
                                        <div>
                                            <div style="font-weight:700; font-size:13px;" x-text="mat.item_name"></div>
                                            <div style="font-size:11px; color:#64748b;" x-text="mat.quantity + ' ' + mat.uom"></div>
                                        </div>
                                        <button x-show="currentJo.status === 'APPROVED'" @click="removeMaterial(mat.id)" style="background:none; border:none; color:#ef4444; font-size:12px; font-weight:600; cursor:pointer;">Remove</button>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Set Price Form -->
                        <template x-if="currentJo.status === 'APPROVED'">
                            <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:16px; border-radius:12px; margin-top:20px;">
                                <div style="font-weight:700; font-size:12px; color:#166534; margin-bottom:12px;">Finalize Job Price</div>
                                <div style="display:flex; gap:10px; align-items:flex-end;">
                                    <div style="flex:1;">
                                        <label style="font-size:10px; font-weight:700; color:#166534; display:block; margin-bottom:4px;">Total Price (₱)</label>
                                        <input type="number" x-model.number="jobPriceInput" style="width:100%; padding:8px; border:1px solid #bbf7d0; border-radius:8px; font-size:13px;" placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                        </template>

                    </div>


                    <!-- Right Column: Customer Info & Artwork -->
                    <div style="display:flex; flex-direction:column; gap:20px;">
                        
                        <!-- Customer Info -->
                        <div class="om-card">
                            <div class="om-card-title">Customer Information</div>
                            <div class="om-cust-header">
                                <div class="om-avatar" x-text="currentJo.customer_full_name ? currentJo.customer_full_name[0].toUpperCase() : '?'"></div>
                                <div>
                                    <div style="font-weight:700;font-size:15px;color:#1e293b;" x-text="currentJo.customer_full_name"></div>
                                    <div style="font-size:12px;color:#9ca3af;">
                                        <span class="status-pill" :class="currentJo.customer_type === 'NEW' ? 'badge-confirmed' : 'badge-fulfilled'" x-text="currentJo.customer_type"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="om-row">
                                <span class="om-label">Contact Number</span>
                                <span class="om-value" x-text="currentJo.customer_contact"></span>
                            </div>
                        </div>
                        
                        <!-- Artwork / Design Files -->
                        <div class="om-card" x-show="currentJo.files && currentJo.files.length > 0">
                            <div class="om-card-title">Artwork Files</div>
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 12px;">
                                <template x-for="file in (currentJo.files || [])" :key="file.id">
                                    <div style="width:100%; margin-bottom:12px; text-align: center;">
                                        <a :href="(window.PF_BASE_PATH || '') + '/' + file.file_path.replace(/^\/+/, '')" target="_blank" style="display: block; border-radius: 12px; overflow: hidden; border: 2px solid #f1f5f9; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);">
                                            <img :src="(window.PF_BASE_PATH || '') + '/' + file.file_path.replace(/^\/+/, '')" alt="Design" style="width: 100%; max-height: 250px; object-fit: cover; display: block;">
                                        </a>
                                        <a :href="(window.PF_BASE_PATH || '') + '/' + file.file_path.replace(/^\/+/, '')" target="_blank" style="display: inline-block; font-size: 12px; color: #06A1A1; margin-top: 8px; font-weight: 700; text-decoration: none; background: #e6f7f5; padding: 4px 10px; border-radius: 6px;">↗ View Full</a>
                                    </div>
                                </template>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Modal Actions Buttons -->
                <div style="margin-top:24px; display:flex; justify-content:flex-end; gap:8px;">
                    <button @click="showDetailsModal = false" class="btn-secondary">Close</button>
                    
                    <template x-if="currentJo.status === 'PENDING'">
                        <button @click="updateStatus(currentJo.id, 'APPROVED'); showDetailsModal = false;" class="btn-action blue" style="padding: 10px 20px;">Approve Job</button>
                    </template>
                    <template x-if="currentJo.status === 'APPROVED'">
                        <button @click="submitToPay()" class="btn-action amber" style="padding: 10px 20px;">Submit to Pay</button>
                    </template>
                    <template x-if="currentJo.status === 'TO_PAY'">
                        <button @click="updateStatus(currentJo.id, 'IN_PRODUCTION'); showDetailsModal = false;" class="btn-action blue" style="padding: 10px 20px;">Start Production</button>
                    </template>
                    <template x-if="currentJo.status === 'IN_PRODUCTION'">
                        <button @click="updateStatus(currentJo.id, 'TO_RECEIVE'); showDetailsModal = false;" class="btn-action amber" style="padding: 10px 20px;">To Receive</button>
                    </template>
                    <template x-if="currentJo.status === 'TO_RECEIVE'">
                        <button @click="completeOrder(currentJo.id); showDetailsModal = false;" class="btn-action emerald" style="padding: 10px 20px;">Mark Complete</button>
                    </template>

                    <template x-if="currentJo.status !== 'CANCELLED' && currentJo.status !== 'COMPLETED'">
                        <button @click="updateStatus(currentJo.id, 'CANCELLED'); showDetailsModal = false;" class="btn-action red" style="padding: 10px 20px;">Cancel Job</button>
                    </template>
                </div>
                
            </div>
        </div>
    </div>
</div>

<script>
    function joManager(defaultStatus = 'PENDING') {
        return {
            statuses: ['ALL', 'PENDING', 'APPROVED', 'TO_PAY', 'IN_PRODUCTION', 'TO_RECEIVE', 'COMPLETED', 'CANCELLED'],
            activeStatus: defaultStatus || 'ALL',
            orders: [],
            machines: [],
            showDetailsModal: false,
            loadingDetails: false,
            previewFile: null,
            currentJo: {},
            availableRolls: {},
            allInventoryItems: [],
            newMaterialId: '',
            newMaterialQty: 1,
            newMaterialHeight: 0,
            newMaterialRollId: '',
            newMaterialNotes: '',
            newMaterialMetadata: {},
            pendingMaterials: [],
            availableRollsList: [],
            impactPreview: null,
            search: '',
            jobPriceInput: 0,
            selectedInkId: '',

            async init() {
                await this.loadOrders();
                await this.loadMachines();
                await this.loadAllInventoryItems();
            },

            async loadOrders() {
                const res = await (await fetch('../admin/job_orders_api.php?action=list_orders')).json();
                if(res.success) {
                    this.orders = res.data;
                }
            },

            async loadMachines() {
                const res = await (await fetch('../admin/job_orders_api.php?action=list_machines')).json();
                this.machines = res.success ? res.data : [];
            },

            get filteredOrders() {
                return this.orders.filter(jo => {
                    const matchStatus = this.activeStatus === 'ALL' || jo.status === this.activeStatus;
                    const searchLower = this.search.toLowerCase();
                    const matchSearch = !this.search || 
                        (jo.job_title && jo.job_title.toLowerCase().includes(searchLower)) ||
                        (jo.service_type && jo.service_type.toLowerCase().includes(searchLower)) ||
                        ((jo.first_name + ' ' + (jo.last_name || '')).toLowerCase().includes(searchLower)) ||
                        (jo.id.toString().includes(searchLower));
                    return matchStatus && matchSearch;
                });
            },

            getStatusCount(status) {
                if (status === 'ALL') return this.orders.length;
                return this.orders.filter(o => o.status === status).length;
            },

            get filteredMaterials() {
                return this.allInventoryItems.filter(i => {
                    const name = i.name.toLowerCase();
                    return !name.includes('carbon') && !name.includes('transfer tape');
                });
            },

            get filteredInks() {
                return this.allInventoryItems.filter(i => {
                    const name = i.name.toLowerCase();
                    return name.includes('ink');
                });
            },

            async viewDetails(id) {
                this.showDetailsModal = true;
                this.loadingDetails = true;
                this.currentJo = {};
                const res = await (await fetch(`../admin/job_orders_api.php?action=get_order&id=${id}`)).json();
                this.loadingDetails = false;
                if(res.success) {
                    this.currentJo = res.data;
                    this.jobPriceInput = this.currentJo.estimated_total || 0;
                    this.selectedInkId = this.currentJo.ink_id || '';
                    this.resetMaterialForm();
                    // Auto-load available rolls for relevant materials
                    for(const m of (this.currentJo.materials || [])) {
                        if(m.track_by_roll == 1) this.loadAvailableRolls(m.item_id);
                    }
                } else {
                    this.showDetailsModal = false;
                    alert(res.error || 'Could not load job details.');
                }
            },

            isOverdue(date) {
                if(!date) return false;
                return new Date(date) < new Date() && this.activeStatus !== 'COMPLETED' && this.activeStatus !== 'CANCELLED';
            },

            async loadAvailableRolls(itemId) {
                if(this.availableRolls[itemId]) {
                    this.availableRollsList = this.availableRolls[itemId];
                    return;
                }
                const res = await (await fetch(`../admin/inventory_rolls_api.php?action=list_rolls&item_id=${itemId}`)).json();
                if(res.success) {
                    this.availableRolls[itemId] = res.data;
                    this.availableRollsList = res.data;
                }
            },

            isRollTracked(itemId) {
                const item = this.allInventoryItems.find(i => i.id == itemId);
                return item && item.track_by_roll == 1;
            },

            async assignRoll(jomId, rollId) {
                const fd = new FormData();
                fd.append('action', 'assign_roll');
                fd.append('jom_id', jomId);
                fd.append('roll_id', rollId);
                const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                if(res.success) {
                    await this.loadOrders();
                    await this.refreshMaterials();
                } else {
                    alert(res.error);
                }
            },

            async updateStatus(id, status, machineId = null) {
                const fd = new FormData();
                fd.append('action', 'update_status');
                fd.append('id', id);
                fd.append('status', status);
                if(machineId) fd.append('machine_id', machineId);
                
                const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                if(res.success) {
                    await this.loadOrders();
                    // If we were viewing details, refresh them
                    if (this.currentJo.id === id) {
                        await this.viewDetails(id);
                    }
                } else {
                    alert(res.error);
                }
            },

            async setJobPrice(id) {
                if(this.jobPriceInput < 0) return;
                const fd = new FormData();
                fd.append('action', 'set_price');
                fd.append('csrf_token', document.body.getAttribute('data-csrf') || '');
                fd.append('id', id);
                fd.append('price', this.jobPriceInput);
                fd.append('ink_id', this.selectedInkId);
                const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                if(!res.success) {
                    alert(res.error);
                    throw new Error(res.error);
                }
            },

            addMaterialToQueue() {
                if (!this.newMaterialId) return;
                const item = this.allInventoryItems.find(i => i.id == this.newMaterialId);
                if (!item) return;
                let meta = {};
                if (this.isTarpaulin(this.newMaterialId)) {
                    meta.height_ft = this.newMaterialHeight;
                    meta.finishing = this.newMaterialMetadata.finishing || '';
                } else if (this.isSticker(this.newMaterialId)) {
                    meta.lamination = this.newMaterialMetadata.lamination || '';
                }
                this.pendingMaterials.push({
                    item_id: this.newMaterialId,
                    name: item.name,
                    qty: this.newMaterialQty,
                    uom: this.isSticker(this.newMaterialId) ? 'pcs' : (item.unit_of_measure || 'pcs'),
                    roll_id: this.newMaterialRollId || '',
                    notes: this.newMaterialNotes,
                    metadata: meta
                });
                // Reset form
                this.newMaterialId = '';
                this.newMaterialQty = 1;
                this.newMaterialHeight = 0;
                this.newMaterialRollId = '';
                this.newMaterialNotes = '';
                this.newMaterialMetadata = {};
            },

            async submitToPay() {
                // Save all pending materials from the queue
                for (const pm of this.pendingMaterials) {
                    const fd = new FormData();
                    fd.append('action', 'add_material');
                    fd.append('order_id', this.currentJo.id);
                    fd.append('item_id', pm.item_id);
                    fd.append('quantity', pm.qty);
                    fd.append('uom', pm.uom);
                    fd.append('roll_id', pm.roll_id);
                    fd.append('notes', pm.notes);
                    fd.append('metadata', JSON.stringify(pm.metadata));
                    const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                    if (!res.success) { alert('Failed to save material: ' + res.error); return; }
                }
                this.pendingMaterials = [];

                // Also save the current form if something is still selected
                if (this.newMaterialId) {
                    await this.addMaterial();
                }

                // Re-fetch to get latest materials
                await this.viewDetails(this.currentJo.id);

                if (!this.currentJo.materials || this.currentJo.materials.length === 0) {
                    alert('Please add at least one production material before submitting to pay.');
                    return;
                }

                await this.setJobPrice(this.currentJo.id);
                await this.updateStatus(this.currentJo.id, 'TO_PAY');
            },

            async loadAllInventoryItems() {
                const res = await (await fetch('../admin/inventory_items_api.php?action=get_items&active_only=1')).json();
                if(res.success) this.allInventoryItems = res.data;
            },

            async addMaterial() {
                if(!this.newMaterialId || !this.newMaterialQty) return;
                const item = this.allInventoryItems.find(i => i.id == this.newMaterialId);
                const fd = new FormData();
                fd.append('action', 'add_material');
                fd.append('order_id', this.currentJo.id);
                fd.append('item_id', this.newMaterialId);
                fd.append('quantity', this.newMaterialQty);
                fd.append('uom', this.isSticker(this.newMaterialId) ? 'pcs' : (item.unit_of_measure || 'pcs'));
                fd.append('roll_id', this.newMaterialRollId);
                fd.append('notes', this.newMaterialNotes);
                
                // Construct metadata based on category
                let meta = {};
                if (this.isTarpaulin(this.newMaterialId)) {
                    meta.height_ft = this.newMaterialHeight;
                    meta.finishing = this.newMaterialMetadata.finishing || '';
                } else if (this.isSticker(this.newMaterialId)) {
                    meta.lamination = this.newMaterialMetadata.lamination || '';
                }
                fd.append('metadata', JSON.stringify(meta));

                const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                if(res.success) {
                    this.resetMaterialForm();
                    await this.refreshMaterials();
                } else {
                    alert(res.error);
                }
            },

            resetMaterialForm() {
                this.newMaterialId = '';
                this.newMaterialQty = this.currentJo.quantity || 1;
                this.newMaterialHeight = this.currentJo.height_ft || 0;
                this.newMaterialRollId = '';
                this.newMaterialNotes = '';
                this.newMaterialMetadata = {};
            },

            isTarpaulin(itemId) {
                const item = this.allInventoryItems.find(i => i.id == itemId);
                return item && item.category_id == 2; // confirmed from schema check
            },

            isSticker(itemId) {
                const item = this.allInventoryItems.find(i => i.id == itemId);
                return item && item.category_id == 3;
            },

            isPlate(itemId) {
                const item = this.allInventoryItems.find(i => i.id == itemId);
                return item && item.category_id == 1;
            },

            async removeMaterial(jomId) {
                if(!confirm('Remove this material?')) return;
                const fd = new FormData();
                fd.append('action', 'remove_material');
                fd.append('id', jomId);
                const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                if(res.success) {
                    await this.refreshMaterials();
                } else {
                    alert(res.error);
                }
            },

            async refreshMaterials() {
                const res = await (await fetch(`../admin/job_orders_api.php?action=get_order&id=${this.currentJo.id}`)).json();
                if(res.success) {
                    this.currentJo = res.data;
                    for(const m of (this.currentJo.materials || [])) {
                        if(m.track_by_roll == 1) this.loadAvailableRolls(m.item_id);
                    }
                }
            },

            async completeOrder(id, machineId = null) {
                if(!confirm('This will permanently deduct materials from inventory. Confirm?')) return;
                this.updateStatus(id, 'COMPLETED', machineId);
            }
        }
    }
</script>
</body>
</html>
