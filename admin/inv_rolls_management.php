<?php
/**
 * Global Roll Management
 * View and manage all inventory rolls.
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
$page_title = 'Roll Management - Admin';

// Get items that are roll-tracked
$rollItems = db_query("SELECT id, name FROM inv_items WHERE track_by_roll = 1 AND status = 'ACTIVE' ORDER BY name ASC") ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .roll-summary { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
        .summary-card { flex: 1; min-width: 140px; background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px 20px; text-align: center; }
        .summary-card .sc-val { font-size: 28px; font-weight: 800; color: #111827; line-height: 1.1; }
        .summary-card .sc-label { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px; }

        .roll-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .roll-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); transition: transform 0.2s, border-color 0.2s; position: relative; }
        .roll-card:hover { transform: translateY(-4px); border-color: #6366f1; }
        .roll-card.finished { opacity: 0.55; }
        .roll-card.voided { opacity: 0.35; }
        .roll-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
        .roll-item-name { font-size: 14px; font-weight: 800; color: #111827; }
        .roll-code { font-family: monospace; font-size: 12px; font-weight: 700; color: #4f46e5; background: #eef2ff; padding: 3px 8px; border-radius: 6px; display: inline-block; margin-top: 4px; }

        .roll-status { font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
        .roll-status.st-open { background: #ecfdf5; color: #059669; }
        .roll-status.st-finished { background: #f3f4f6; color: #6b7280; }
        .roll-status.st-void { background: #fef2f2; color: #ef4444; }

        .progress-track { height: 14px; background: #f3f4f6; border-radius: 14px; overflow: hidden; margin: 14px 0 6px; position: relative; }
        .progress-fill { height: 100%; border-radius: 14px; transition: width 0.6s ease-out; position: relative; }
        .progress-fill::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.25) 50%, transparent 100%); }
        .progress-blocks { position: absolute; inset: 0; display: flex; gap: 2px; padding: 2px; }
        .progress-block { flex: 1; border-radius: 3px; }
        .progress-block.filled { background: rgba(255,255,255,0.3); }
        .progress-block.empty { background: rgba(0,0,0,0.04); }

        .roll-stats { display: flex; justify-content: space-between; align-items: center; font-size: 12px; font-weight: 700; color: #4b5563; }
        .roll-remaining { font-size: 18px; font-weight: 800; line-height: 1; }
        .roll-pct { font-size: 20px; font-weight: 800; }
        .roll-meta { font-size: 11px; color: #9ca3af; margin-top: 10px; display: flex; gap: 12px; }
        .roll-meta span { display: flex; align-items: center; gap: 3px; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background: #fff; padding: 32px; border-radius: 20px; width: 400px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .btn-void { background: #fef2f2; color: #ef4444; border: 1px solid #fee2e2; padding: 4px 8px; border-radius: 6px; font-size: 10px; font-weight: 700; cursor: pointer; }
        .btn-void:hover { background: #ef4444; color: #fff; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>
    <div class="main-content">
        <header style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;">
            <div>
                <h1 class="page-title">Roll Tracking Dashboard</h1>
                <p style="color:#6b7280; font-size:14px;">Monitor real-time remaining length for all stock rolls.</p>
            </div>
            <button onclick="document.getElementById('addRollModal').style.display='flex'" class="btn-primary">Add New Roll</button>
        </header>

        <div style="margin-bottom: 24px; display: flex; gap: 12px;">
            <select id="filterItem" onchange="loadRolls()" class="p-2 border rounded-lg text-sm bg-white">
                <option value="">All Materials</option>
                <?php foreach ($rollItems as $item): ?>
                    <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterStatus" onchange="loadRolls()" class="p-2 border rounded-lg text-sm bg-white">
                <option value="OPEN">Open Only</option>
                <option value="FINISHED">Finished Only</option>
                <option value="VOID">Void Only</option>
                <option value="">All Statuses</option>
            </select>
        </div>

        <div id="rollSummary" class="roll-summary"></div>

        <div id="rollGrid" class="roll-grid">
            <!-- Dynamic rolls -->
        </div>
    </div>
</div>

<div id="addRollModal" class="modal">
    <div class="modal-content">
        <h3 class="font-bold text-xl mb-6">Add New Physical Roll</h3>
        <form id="addRollForm" onsubmit="saveRoll(event)">
            <input type="hidden" name="action" value="add_roll">
            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Item / Material</label>
                <select name="item_id" required class="w-full p-3 border rounded-xl bg-gray-50">
                    <?php foreach ($rollItems as $item): ?>
                        <option value="<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Total Length (FT)</label>
                <input type="number" step="0.01" name="total_length" value="164" required class="w-full p-3 border rounded-xl">
            </div>
            <div class="mb-6">
                <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Roll Code / Label</label>
                <input type="text" name="roll_code" placeholder="e.g. BATCH-001" class="w-full p-3 border rounded-xl">
            </div>
            <div class="flex gap-4">
                <button type="button" onclick="this.closest('.modal').style.display='none'" class="flex-1 p-3 bg-gray-100 rounded-xl font-bold">Cancel</button>
                <button type="submit" class="flex-1 p-3 bg-indigo-600 text-white rounded-xl font-bold">Save Roll</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.PF_INVENTORY_CSRF = <?php echo json_encode(generate_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function rollColor(pct) {
        if (pct >= 60) return '#10b981';   // green
        if (pct >= 30) return '#f59e0b';   // amber
        if (pct > 0)  return '#ef4444';    // red
        return '#d1d5db';                  // gray (empty)
    }

    function renderSummary(rolls) {
        const total = rolls.length;
        const open = rolls.filter(r => r.status === 'OPEN').length;
        const finished = rolls.filter(r => r.status === 'FINISHED').length;
        const voided = rolls.filter(r => r.status === 'VOID').length;
        const totalRemaining = rolls.reduce((s, r) => s + parseFloat(r.remaining_length_ft || 0), 0);

        document.getElementById('rollSummary').innerHTML = `
            <div class="summary-card"><div class="sc-val">${total}</div><div class="sc-label">Total Rolls</div></div>
            <div class="summary-card" style="border-color:#d1fae5;"><div class="sc-val" style="color:#059669;">${open}</div><div class="sc-label">Open</div></div>
            <div class="summary-card" style="border-color:#e5e7eb;"><div class="sc-val" style="color:#6b7280;">${finished}</div><div class="sc-label">Finished</div></div>
            <div class="summary-card" style="border-color:#fee2e2;"><div class="sc-val" style="color:#ef4444;">${voided}</div><div class="sc-label">Void</div></div>
            <div class="summary-card" style="border-color:#c7d2fe;"><div class="sc-val" style="color:#4f46e5;">${totalRemaining.toFixed(1)}</div><div class="sc-label">FT Remaining</div></div>
        `;
    }

    function buildBlocks(pct) {
        const blocks = 20;
        const filled = Math.round(pct / 100 * blocks);
        let html = '';
        for (let i = 0; i < blocks; i++) {
            html += `<div class="progress-block ${i < filled ? 'filled' : 'empty'}"></div>`;
        }
        return html;
    }

    async function loadRolls() {
        const itemId = document.getElementById('filterItem').value;
        const status = document.getElementById('filterStatus').value;
        const grid = document.getElementById('rollGrid');

        try {
            const params = new URLSearchParams({ action: 'list_rolls' });
            if (itemId) params.set('item_id', itemId);
            if (status) params.set('status', status);

            const res = await fetch('inventory_rolls_api.php?' + params.toString());
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Load failed');

            const rolls = data.data || [];
            renderSummary(rolls);

            if (!rolls.length) {
                grid.innerHTML = '<p style="color:#9ca3af;text-align:center;grid-column:1/-1;padding:40px 0;">No rolls found.</p>';
                return;
            }

            grid.innerHTML = '';
            rolls.forEach(roll => {
                const total = parseFloat(roll.total_length_ft) || 1;
                const remaining = parseFloat(roll.remaining_length_ft) || 0;
                const pct = Math.min(100, (remaining / total) * 100);
                const color = rollColor(pct);
                const statusCls = roll.status === 'FINISHED' ? 'finished' : (roll.status === 'VOID' ? 'voided' : '');
                const stCls = 'st-' + roll.status.toLowerCase();
                const receivedDate = roll.received_at ? new Date(roll.received_at).toLocaleDateString() : '';
                const finishedDate = roll.finished_at ? new Date(roll.finished_at).toLocaleDateString() : '';
                const voidBtn = roll.status === 'OPEN' ? `<button onclick="voidRoll(${roll.id})" class="btn-void">VOID</button>` : '';

                grid.innerHTML += `
                    <div class="roll-card ${statusCls}">
                        <div class="roll-header">
                            <div>
                                <div class="roll-item-name">${escHtml(roll.item_name || 'Material')}</div>
                                <div class="roll-code">${escHtml(roll.roll_code || 'ROLL-' + roll.id)}</div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="roll-status ${stCls}">${roll.status}</span>
                                ${voidBtn}
                            </div>
                        </div>
                        <div class="progress-track" style="${roll.status !== 'OPEN' ? 'opacity:0.5;' : ''}">
                            <div class="progress-fill" style="width:${pct}%;background:${color};">
                                <div class="progress-blocks">${buildBlocks(pct)}</div>
                            </div>
                        </div>
                        <div class="roll-stats">
                            <div>
                                <div class="roll-remaining" style="color:${color};">${remaining.toFixed(1)} <span style="font-size:12px;font-weight:600;">FT</span></div>
                                <div style="font-size:11px;color:#9ca3af;font-weight:400;">of ${total.toFixed(0)} FT total</div>
                            </div>
                            <div class="roll-pct" style="color:${color};">${Math.round(pct)}%</div>
                        </div>
                        <div class="roll-meta">
                            <span>\u{1F4E6} ${receivedDate}</span>
                            ${finishedDate ? '<span>\u2705 ' + finishedDate + '</span>' : ''}
                        </div>
                    </div>
                `;
            });
        } catch(e) { grid.innerHTML = '<p style="color:#ef4444;text-align:center;grid-column:1/-1;padding:40px;">Error: ' + escHtml(e.message) + '</p>'; }
    }

    async function saveRoll(e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.set('csrf_token', window.PF_INVENTORY_CSRF || '');
        const res = await fetch('inventory_rolls_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if(data.success) {
            e.target.closest('.modal').style.display = 'none';
            loadRolls();
        } else alert(data.error);
    }

    async function voidRoll(id) {
        if(!confirm('Are you sure you want to VOID this roll? It will be removed from active stock.')) return;
        const fd = new FormData();
        fd.append('action', 'void_roll');
        fd.append('roll_id', id);
        fd.set('csrf_token', window.PF_INVENTORY_CSRF || '');
        const res = await fetch('inventory_rolls_api.php', { method: 'POST', body: fd });
        if((await res.json()).success) loadRolls();
    }

    function printflowInitRollsPage() {
        if (!document.getElementById('rollGrid')) return;
        loadRolls();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', printflowInitRollsPage);
    } else {
        printflowInitRollsPage();
    }
    document.addEventListener('printflow:page-init', printflowInitRollsPage);
</script>
</body>
</html>
