<?php
/**
 * Admin Inventory — Monthly View (Weekly Tabs)
 * PrintFlow - Dynamic Inventory Module
 * Interactive monthly stock table with week-based navigation + AJAX inline editing
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}
$current_user = get_logged_in_user();

// Get categories for dropdown
$categories = db_query("SELECT * FROM inv_categories ORDER BY name ASC") ?: [];

$page_title = 'Monthly Inventory - Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        /* ─── Filter Bar ─── */
        .filter-bar { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:24px; padding:20px; background:#f9fafb; border-radius:12px; border:1px solid #e5e7eb; }
        .filter-group { display:flex; flex-direction:column; gap:4px; }
        .filter-group label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; }
        .filter-group select { padding:8px 14px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:#fff; min-width:140px; cursor:pointer; }
        .filter-group select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15); }

        /* ─── Week Tabs ─── */
        .week-tabs { display:flex; gap:4px; margin-bottom:16px; flex-wrap:wrap; }
        .week-tab { padding:10px 20px; border:2px solid #e5e7eb; background:#fff; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; color:#6b7280; transition:all .2s; white-space:nowrap; }
        .week-tab:hover { border-color:#c7d2fe; color:#4f46e5; background:#eef2ff; }
        .week-tab.active { border-color:#6366f1; background:#eef2ff; color:#4f46e5; box-shadow:0 2px 8px rgba(99,102,241,.15); }
        .week-tab .week-range { font-size:11px; font-weight:400; color:#9ca3af; display:block; margin-top:2px; }
        .week-tab.active .week-range { color:#818cf8; }

        /* ─── Monthly Table ─── */
        .monthly-table-wrap { overflow-x:auto; border-radius:12px; border:1px solid #e5e7eb; }
        .monthly-table { width:100%; border-collapse:collapse; }
        .monthly-table th { padding:12px 14px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:#6b7280; background:#f9fafb; border-bottom:2px solid #e5e7eb; text-align:center; white-space:nowrap; }
        .monthly-table th:first-child { text-align:left; min-width:140px; }
        .monthly-table th:nth-child(2) { min-width:80px; }
        .monthly-table th.day-col { min-width:72px; }
        .monthly-table th.total-col { background:#eef2ff; color:#4f46e5; min-width:100px; }
        .monthly-table td { padding:8px 6px; border-bottom:1px solid #f3f4f6; text-align:center; font-size:13px; }
        .monthly-table td:first-child { text-align:left; padding-left:14px; font-weight:600; color:#1f2937; white-space:nowrap; }
        .monthly-table td:nth-child(2) { font-weight:700; color:#6366f1; }
        .monthly-table td.total-cell { background:#eef2ff; font-weight:800; font-size:15px; color:#4f46e5; }
        .monthly-table tr:hover td { background:#f9fafb; }
        .monthly-table tr:hover td.total-cell { background:#e0e7ff; }

        /* ─── Day Input ─── */
        .day-input { width:64px; padding:8px 4px; text-align:center; border:1.5px solid #e5e7eb; border-radius:8px; font-size:13px; font-weight:500; font-variant-numeric:tabular-nums; background:#fff; transition:all .15s; }
        .day-input:hover { border-color:#c7d2fe; }
        .day-input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15); }
        .day-input.saving { background:#fef3c7; border-color:#f59e0b; }
        .day-input.saved { background:#d1fae5; border-color:#10b981; }
        .day-input.error { background:#fee2e2; border-color:#ef4444; }
        .day-input.has-value { color:#1f2937; font-weight:700; }
        .day-input:not(.has-value) { color:#d1d5db; }

        /* ─── Day Header ─── */
        .day-header { display:flex; flex-direction:column; align-items:center; gap:1px; }
        .day-header .day-name { font-size:9px; color:#9ca3af; font-weight:600; }
        .day-header .day-num { font-size:13px; }

        /* ─── States ─── */
        .loading-overlay { position:absolute; inset:0; background:rgba(255,255,255,.85); display:flex; align-items:center; justify-content:center; border-radius:12px; z-index:5; }
        .spinner { width:32px; height:32px; border:3px solid #e5e7eb; border-top-color:#6366f1; border-radius:50%; animation:spin .6s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .empty-state { text-align:center; padding:60px 20px; color:#9ca3af; }
        .unit-badge { font-size:10px; color:#9ca3af; font-weight:400; display:block; }

        /* ─── Summary Cards ─── */
        .inv-summary { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:20px; }
        .inv-summary-card { padding:14px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; text-align:center; }
        .inv-summary-card .label { font-size:11px; color:#9ca3af; text-transform:uppercase; font-weight:600; letter-spacing:.3px; }
        .inv-summary-card .value { font-size:22px; font-weight:800; margin-top:4px; }

        /* ─── Dark Pill Link ─── */
        .nav-row { display:flex; align-items:center; justify-content:flex-end; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
        .pill-link-dark { display:inline-flex; align-items:center; gap:8px; padding:12px 28px; background:#1f2937; color:#fff; border-radius:50px; font-weight:600; font-size:13px; text-decoration:none; border:2px solid #1f2937; transition:all .25s ease; white-space:nowrap; }
        .pill-link-dark:hover { background:#374151; border-color:#374151; }
        .pill-link-dark .pill-icon { width:16px; height:16px; flex-shrink:0; }

        @media (max-width: 768px) {
            .filter-bar { flex-direction:column; }
            .week-tabs { gap:6px; }
            .week-tab { padding:8px 14px; font-size:12px; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <header>
            <h1 class="page-title">Monthly Inventory</h1>
        </header>

        <main>
            <!-- Nav Row: Manage Materials pill on the right -->
            <div class="nav-row">
                <a href="inv_items_management" class="pill-link-dark">
                    <svg class="pill-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Manage Materials
                </a>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Category</label>
                    <select id="filterCategory">
                        <option value="">— Select Category —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Month</label>
                    <select id="filterMonth">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == date('n') ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Year</label>
                    <select id="filterYear">
                        <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button onclick="loadMonthlyData()" class="btn-primary" style="height:38px; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Load Data
                </button>
            </div>

            <!-- Summary Cards -->
            <div id="summaryCards" class="inv-summary" style="display:none;"></div>

            <!-- Week Tabs -->
            <div id="weekTabs" class="week-tabs" style="display:none;"></div>

            <!-- Table Container -->
            <div class="card" style="position:relative; padding:0; overflow:hidden;">
                <div id="tableContainer">
                    <div class="empty-state">
                        <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin:0 auto 12px; opacity:.4; display:block;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <p style="font-weight:600;">Select a category and click "Load Data"</p>
                        <p style="font-size:13px;">The monthly inventory table will appear here.</p>
                    </div>
                </div>
                <div id="loadingOverlay" class="loading-overlay" style="display:none;">
                    <div class="spinner"></div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
/* var: Turbo re-runs this script on visits; let/const would throw "already been declared". */
var API_URL = '<?php echo $base_path; ?>/admin/inventory_api.php';
var PF_INVENTORY_MONTHLY_CSRF = <?php echo json_encode(generate_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var DAY_NAMES = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

var monthlyData = null;   // cached data from API
var activeWeek  = 0;      // 0-indexed week tab

// ─── Load Monthly Data ──────────────────────────────────
async function loadMonthlyData() {
    const catId = document.getElementById('filterCategory').value;
    const month = document.getElementById('filterMonth').value;
    const year  = document.getElementById('filterYear').value;

    if (!catId) { alert('Please select a category.'); return; }

    document.getElementById('loadingOverlay').style.display = 'flex';

    try {
        const res = await fetch(`${API_URL}?action=get_monthly_data&category_id=${catId}&month=${month}&year=${year}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load');

        monthlyData = data;

        if (data.materials.length === 0) {
            document.getElementById('tableContainer').innerHTML = `
                <div class="empty-state">
                    <p style="font-weight:600;">No materials in this category</p>
                    <p style="font-size:13px;"><a href="inventory_management" style="color:#6366f1;">Add materials</a> to start tracking.</p>
                </div>`;
            document.getElementById('summaryCards').style.display = 'none';
            document.getElementById('weekTabs').style.display = 'none';
            return;
        }

        renderWeekTabs(data);
        activeWeek = 0;
        renderTable(data, 0);
        renderSummary(data);
    } catch (err) {
        document.getElementById('tableContainer').innerHTML = `<div class="empty-state"><p style="color:#ef4444;font-weight:600;">Error: ${err.message}</p></div>`;
    } finally {
        document.getElementById('loadingOverlay').style.display = 'none';
    }
}

// ─── Build Week Definitions ────────────────────────────
function getWeeks(daysInMonth) {
    const weeks = [];
    let start = 1;
    while (start <= daysInMonth) {
        const end = Math.min(start + 6, daysInMonth);
        weeks.push({ start, end });
        start = end + 1;
    }
    return weeks;
}

// ─── Render Week Tabs ──────────────────────────────────
function renderWeekTabs(data) {
    const weeks = getWeeks(data.days_in_month);
    const container = document.getElementById('weekTabs');
    const monthNames = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const mon = monthNames[data.month];

    let html = '';
    weeks.forEach((w, i) => {
        html += `<button class="week-tab${i === 0 ? ' active' : ''}" onclick="switchWeek(${i})">
            Week ${i + 1}
            <span class="week-range">${mon} ${w.start}–${w.end}</span>
        </button>`;
    });

    container.innerHTML = html;
    container.style.display = 'flex';
}

// ─── Switch Week ───────────────────────────────────────
function switchWeek(weekIdx) {
    activeWeek = weekIdx;
    document.querySelectorAll('.week-tab').forEach((el, i) => {
        el.classList.toggle('active', i === weekIdx);
    });
    renderTable(monthlyData, weekIdx);
}

// ─── Render Table for a Specific Week ──────────────────
function renderTable(data, weekIdx) {
    const { materials, days_in_month, month, year } = data;
    const weeks = getWeeks(days_in_month);
    const week = weeks[weekIdx];
    const container = document.getElementById('tableContainer');

    // Determine day-of-week names
    const firstDate = new Date(year, month - 1, 1);

    let html = '<div class="monthly-table-wrap"><table class="monthly-table"><thead><tr>';
    html += '<th style="text-align:left;">Material</th>';
    html += '<th>Opening</th>';

    for (let d = week.start; d <= week.end; d++) {
        const dow = new Date(year, month - 1, d).getDay();
        html += `<th class="day-col"><div class="day-header"><span class="day-name">${DAY_NAMES[dow]}</span><span class="day-num">${d}</span></div></th>`;
    }

    html += '<th class="total-col">Total Stock</th>';
    html += '</tr></thead><tbody>';

    materials.forEach(mat => {
        html += '<tr>';
        html += `<td>${escHtml(mat.material_name)}<span class="unit-badge">${escHtml(mat.unit)}</span></td>`;
        html += `<td>${parseFloat(mat.opening_stock).toFixed(1)}</td>`;

        for (let d = week.start; d <= week.end; d++) {
            const val = mat.days[d] !== undefined ? mat.days[d] : '';
            const dateStr = `${year}-${String(month).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            const displayVal = val !== '' ? Math.abs(val) : '';
            const hasVal = val !== '';
            html += `<td><input type="number" step="0.1" class="day-input${hasVal ? ' has-value' : ''}" 
                        data-material-id="${mat.material_id}" 
                        data-date="${dateStr}"
                        data-day="${d}"
                        value="${displayVal}"
                        placeholder="—"
                        onblur="saveMovement(this)"
                        title="Day ${d}: Enter amount used"></td>`;
        }

        html += `<td class="total-cell" id="total-${mat.material_id}">${parseFloat(mat.total_stock).toFixed(1)}</td>`;
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
}

// ─── Render Summary Cards ──────────────────────────────
function renderSummary(data) {
    const cards = document.getElementById('summaryCards');
    const totalMaterials = data.materials.length;
    let totalOpening = 0, totalCurrent = 0, totalMovements = 0;

    data.materials.forEach(mat => {
        totalOpening += mat.opening_stock;
        totalCurrent += mat.total_stock;
        totalMovements += Object.keys(mat.days).length;
    });

    cards.innerHTML = `
        <div class="inv-summary-card">
            <div class="label">Materials</div>
            <div class="value" style="color:#6366f1;">${totalMaterials}</div>
        </div>
        <div class="inv-summary-card">
            <div class="label">Total Opening</div>
            <div class="value" style="color:#059669;">${totalOpening.toFixed(1)}</div>
        </div>
        <div class="inv-summary-card">
            <div class="label">Total Current</div>
            <div class="value" style="color:${totalCurrent < totalOpening * 0.2 ? '#ef4444' : '#1f2937'};">${totalCurrent.toFixed(1)}</div>
        </div>
        <div class="inv-summary-card">
            <div class="label">Entries This Month</div>
            <div class="value" style="color:#f59e0b;">${totalMovements}</div>
        </div>
    `;
    cards.style.display = 'grid';
}

// ─── Save Movement via AJAX ────────────────────────────
async function saveMovement(input) {
    const mid  = input.dataset.materialId;
    const date = input.dataset.date;
    const day  = parseInt(input.dataset.day);
    const rawVal = parseFloat(input.value) || 0;
    const qty  = rawVal > 0 ? -rawVal : 0;

    input.classList.remove('saved', 'error');
    input.classList.add('saving');
    input.classList.toggle('has-value', rawVal > 0);

    try {
        const formData = new FormData();
        formData.append('action', 'save_movement');
        formData.append('material_id', mid);
        formData.append('movement_date', date);
        formData.append('quantity_change', qty);
        formData.append('notes', 'Manual entry');
        formData.append('csrf_token', PF_INVENTORY_MONTHLY_CSRF || '');

        const res = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);

        // Update cached data
        const mat = monthlyData.materials.find(m => m.material_id == mid);
        if (mat) {
            if (rawVal > 0) {
                mat.days[day] = qty;
            } else {
                delete mat.days[day];
            }
            mat.total_stock = data.new_total;
        }

        // Update total column
        const totalCell = document.getElementById('total-' + mid);
        if (totalCell) {
            totalCell.textContent = parseFloat(data.new_total).toFixed(1);
            totalCell.style.transition = 'color .3s';
            totalCell.style.color = '#059669';
            setTimeout(() => totalCell.style.color = '#4f46e5', 800);
        }

        input.classList.remove('saving');
        input.classList.add('saved');
        setTimeout(() => input.classList.remove('saved'), 1200);

        // Update summary
        renderSummary(monthlyData);
    } catch (err) {
        input.classList.remove('saving');
        input.classList.add('error');
        console.error('Save error:', err);
        setTimeout(() => input.classList.remove('error'), 2000);
    }
}

// ─── Helpers ───────────────────────────────────────────
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function printflowInitMonthlyInvPage() {
    const catSelect = document.getElementById('filterCategory');
    if (!catSelect) return;
    
    // Auto-load if only one category (and not already loaded)
    if (catSelect.options.length === 2 && !monthlyData) {
        catSelect.selectedIndex = 1;
        loadMonthlyData();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', printflowInitMonthlyInvPage);
} else {
    printflowInitMonthlyInvPage();
}
document.addEventListener('printflow:page-init', printflowInitMonthlyInvPage);
</script>

</body>
</html>
