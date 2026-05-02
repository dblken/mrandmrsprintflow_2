<?php
/**
 * Branch UI Components
 * PrintFlow - Multi-Branch System
 *
 * Renders the branch selector dropdown (for admin page headers)
 * and shared branch-related CSS/JS.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/branch_ui.php';
 *   render_branch_selector($branchCtx);
 *   render_branch_css();       // call once inside <style> or include before </head>
 */

require_once __DIR__ . '/branch_context.php';

/** ─────────────────────────────────────────────────────
 *  render_branch_css()
 *  Outputs all CSS needed by branch UI widgets.
 *  Call once, anywhere before </head>.
 * ──────────────────────────────────────────────────── */
function render_branch_css(): void { ?>
<style>
/* ── Branch selector ─────────────────────────────── */
.branch-selector-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
}
.branch-selector-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
    border: 1px solid #e5e7eb; background: #fff; border-radius: 8px;
    font-size: 13px; font-weight: 500; color: #374151; cursor: pointer;
    transition: all 0.15s; white-space: nowrap; min-width: 160px;
    justify-content: space-between;
}
.branch-selector-btn:hover { border-color: #9ca3af; background: #f9fafb; }
.branch-selector-btn.open { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }

.branch-selector-wrap--locked .branch-selector-static {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 14px;
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    color: #6b7280;
    cursor: default;
    user-select: none;
    white-space: nowrap;
}

.branch-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #0d9488;
    flex-shrink: 0;
}

.branch-dropdown {
    position: absolute; top: calc(100% + 6px); right: 0;
    min-width: 220px; background: #fff; border: 1px solid #e5e7eb;
    border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    z-index: 9999; padding: 6px 0; overflow: hidden;
    display: none;
}
.branch-dropdown.open { display: block; }

.branch-dropdown-item {
    display: flex; align-items: center; gap: 8px; padding: 9px 16px;
    font-size: 13px; color: #374151; cursor: pointer;
    transition: background 0.1s; text-decoration: none;
}
.branch-dropdown-item:hover { background: #f9fafb; }
.branch-dropdown-item.active {
    color: #0d9488; font-weight: 600; background: #f0fdfa;
}
.branch-dropdown-item .check { margin-left: auto; color: #0d9488; }
.branch-dropdown-item .item-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
}
.branch-dropdown-divider { height: 1px; background: #f3f4f6; margin: 6px 0; }
.branch-dropdown-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: #9ca3af; padding: 6px 16px 2px;
}

/* ── Branch context banner ───────────────────────── */
.branch-context-banner {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 7px;
    padding: 4px 12px;
    margin-bottom: 12px;
}
.branch-context-banner svg { color: #6366f1; }

/* ── Branch badge ────────────────────────────────── */
.branch-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .3px;
    white-space: nowrap;
}

/* ── No-branch warning toast ─────────────────────── */
.branch-required-toast {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fffbeb;
    border: 1.5px solid #fde68a;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 13px;
    font-weight: 500;
    color: #78350f;
    margin-bottom: 18px;
}
.branch-required-toast svg { color: #d97706; flex-shrink: 0; }
</style>
<?php }

/** ─────────────────────────────────────────────────────
 *  render_branch_selector($branchCtx)
 *
 *  Outputs the dropdown HTML + inline JS.
 *  $branchCtx = result of init_branch_context()
 *
 *  Pass the current page URL (without the branch_id param)
 *  as the second argument if you want GET-based switching.
 * ──────────────────────────────────────────────────── */
function render_branch_selector(array $branchCtx, ?string $base_url = null): void {
    $selected  = $branchCtx['selected_branch_id'];
    $allowed   = $branchCtx['allowed_branches'];
    $branches  = $branchCtx['branches_list'];
    $name      = htmlspecialchars($branchCtx['branch_name']);
    $is_admin  = ($allowed === 'all');
    $single_branch_locked = ($allowed !== 'all' && is_array($allowed) && count($allowed) === 1);

    // Build current URL without branch_id param
    if ($base_url === null) {
        $base_url = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        $query = $_GET;
        unset($query['branch_id']);
        $base_url .= $query ? ('?' . http_build_query($query)) : '';
    }
    $sep = (strpos($base_url, '?') !== false) ? '&' : '?';

    // Dot colour
    $dot_color = '#0d9488'; // Fixed teal
    ?>
    <?php if ($single_branch_locked): ?>
    <div class="branch-selector-wrap branch-selector-wrap--locked" title="Your assigned branch">
        <div class="branch-selector-static" aria-readonly="true">
            <span class="branch-dot"></span>
            <span><?php echo $name; ?></span>
        </div>
    </div>
    <?php else: ?>
    <div class="branch-selector-wrap" id="branchSelectorWrap">
        <button class="branch-selector-btn"
                id="branchSelectorBtn"
                type="button"
                onclick="toggleBranchDropdown(event)"
                title="Switch branch">
            <span style="display:flex;align-items:center;gap:8px;">
                <span class="branch-dot"></span>
                <span id="branchSelectorLabel"><?php echo $name; ?></span>
            </span>
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div class="branch-dropdown" id="branchDropdown">
            <?php if ($is_admin): ?>
            <div class="branch-dropdown-label">View Mode</div>
            <a class="branch-dropdown-item <?php echo printflow_branch_value_is_all($selected) ? 'active' : ''; ?>"
               href="<?php echo $base_url . $sep; ?>branch_id=all">
                <span class="item-dot" style="background:#0d9488;"></span>
                All Branches
                <?php if (printflow_branch_value_is_all($selected)): ?>
                    <svg class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <?php endif; ?>
            </a>
            <div class="branch-dropdown-divider"></div>
            <div class="branch-dropdown-label">Specific Branch</div>
            <?php endif; ?>

            <?php foreach ($branches as $idx => $b):
                $bid = (int)$b['id'];
                $bname = htmlspecialchars($b['branch_name']);
                $is_active = (!printflow_branch_value_is_all($selected) && (int)$selected === $bid);
                if ($is_admin === false && !in_array($bid, array_map('intval', $allowed), true)) continue;
                $colours = ['#6366f1','#10b981','#f59e0b','#7c3aed','#ef4444','#3b82f6','#14b8a6','#f97316','#ec4899','#84cc16'];
                $dot = $colours[$idx % count($colours)];
            ?>
            <a class="branch-dropdown-item <?php echo $is_active ? 'active' : ''; ?>"
               href="<?php echo $base_url . $sep; ?>branch_id=<?php echo $bid; ?>">
                <span class="item-dot" style="background:<?php echo $dot; ?>;"></span>
                <?php echo $bname; ?>
                <?php if ($is_active): ?>
                    <svg class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    function toggleBranchDropdown(e) {
        e.stopPropagation();
        var dd = document.getElementById('branchDropdown');
        var btn = document.getElementById('branchSelectorBtn');
        var isOpen = dd.classList.contains('open');
        
        // Close others if they exist (unlikely in header, but for safety)
        dd.classList.toggle('open');
        btn.classList.toggle('open');
    }
    document.addEventListener('click', function(e) {
        var wrap = document.getElementById('branchSelectorWrap');
        if (wrap && !wrap.contains(e.target)) {
            var dd = document.getElementById('branchDropdown');
            var btn = document.getElementById('branchSelectorBtn');
            if (dd) dd.classList.remove('open');
            if (btn) btn.classList.remove('open');
        }
    });
    </script>
    <?php endif; ?>
    <?php
}

/** ─────────────────────────────────────────────────────
 *  render_branch_required_warning($branches, $current_url)
 *
 *  Shows a warning when an operational page needs a branch
 *  but "All Branches" is currently selected by an admin.
 * ──────────────────────────────────────────────────── */
function render_branch_required_warning(array $branches, string $current_url = ''): void {
    if (!$current_url) $current_url = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $sep = (strpos($current_url, '?') !== false) ? '&' : '?';
    ?>
    <div class="branch-required-toast">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <span>Select a specific branch to view operational data:</span>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-left:4px;">
            <?php foreach ($branches as $b): ?>
                <a href="<?php echo $current_url . $sep; ?>branch_id=<?php echo (int)$b['id']; ?>"
                   style="background:#fbbf24;color:#1f2937;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;">
                    <?php echo htmlspecialchars($b['branch_name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
