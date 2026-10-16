<?php
/**
 * Fast Reports shell. The shell deliberately contains no analytics queries.
 * Section data is fetched from admin/reports_data.php after the page is usable.
 */
function pf_reports_render_emergency_shell(array $ctx): void
{
    $basePath = rtrim((string)($ctx['base_path'] ?? ''), '/');
    $branchCtx = $ctx['branch_ctx'];
    $branchName = (string)$ctx['branch_name'];
    $branchId = $ctx['branch_id'];
    $from = (string)$ctx['from'];
    $to = (string)$ctx['to'];
    $reportsPerf = !empty($ctx['reports_perf']);
    $shellMs = (float)($ctx['shell_ms'] ?? 0);
    $branchValue = printflow_branch_value_is_all($branchId) ? 'all' : (string)(int)$branchId;
    $money = static fn($value): string => '&#8369;' . number_format((float)$value, 0);
    $kpis = [
        ['label' => 'Sales Revenue', 'value' => $ctx['revenue'], 'class' => 'kpi-cyan', 'note' => 'Total sales for selected period'],
        ['label' => 'Estimated Gross Profit', 'value' => $ctx['gross_profit'], 'class' => 'kpi-green', 'note' => 'After estimated material costs'],
        ['label' => 'Operating Expenses', 'value' => $ctx['operating_expenses'], 'class' => 'kpi-rose', 'note' => 'Paid operating costs'],
        ['label' => 'Net Operating Profit (Est.)', 'value' => $ctx['net_profit'], 'class' => 'kpi-purple', 'note' => 'After material + operating costs'],
    ];
    $sections = [
        ['trend', '12-Month Sales Trend'],
        ['forecast', 'Product Demand Forecast'],
        ['heatmap', 'Seasonal Demand Heatmap'],
        ['locations', 'Customer Reach'],
        ['status', 'Order Status Breakdown'],
        ['products', 'Best Selling Products'],
        ['services', 'Best Selling Services'],
        ['distribution', 'Revenue Distribution'],
        ['branches', 'Branch Comparison Analytics'],
        ['insights', 'Business Insights'],
    ];
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Reports &amp; Analytics - Admin</title>
        <?php require __DIR__ . '/admin_style.php'; render_branch_css(); ?>
        <style>
            .pf-fast-wrap{max-width:1500px;margin:0 auto;padding:28px 32px 60px}.pf-fast-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:22px}.pf-fast-head h1{margin:0;font-size:28px;color:#0f3555}.pf-fast-filter{display:flex;flex-wrap:wrap;align-items:end;gap:10px;padding:16px;background:#fff;border:1px solid #dbe5ec;border-radius:10px;margin-bottom:18px}.pf-fast-filter label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:#496276}.pf-fast-filter input,.pf-fast-filter button{height:38px;border:1px solid #bfd0dc;border-radius:7px;padding:0 10px;font:inherit}.pf-fast-filter button{background:#086b7d;color:#fff;font-weight:700;cursor:pointer}.pf-fast-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}.pf-fast-kpi{background:#fff;border:1px solid #dbe5ec;border-top:3px solid #54bfd5;border-radius:10px;padding:18px}.pf-fast-kpi h2{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#17647d;margin:0 0 12px}.pf-fast-kpi strong{display:block;font-size:27px;color:#102d48}.pf-fast-kpi span{display:block;color:#718397;font-size:12px;margin-top:7px}.pf-fast-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.pf-fast-section{min-height:150px;background:#fff;border:1px solid #dbe5ec;border-radius:10px;padding:18px}.pf-fast-section h2{font-size:17px;margin:0 0 16px;color:#123957}.pf-fast-loading{color:#8295a5;font-size:13px}.pf-fast-error{color:#9c2c2c;background:#fff1f1;border:1px solid #f2c4c4;border-radius:7px;padding:12px;font-size:13px}.pf-fast-retry{margin-top:9px;border:1px solid #a63737;background:#fff;color:#8a2424;border-radius:6px;padding:6px 10px;cursor:pointer}.pf-fast-table{width:100%;border-collapse:collapse;font-size:13px}.pf-fast-table th,.pf-fast-table td{text-align:left;padding:7px;border-bottom:1px solid #edf2f5}.pf-fast-table th{color:#537084}.pf-fast-note{font-size:12px;color:#718397}.pf-fast-perf{font:11px ui-monospace,monospace;color:#738394;margin-top:10px}.pf-fast-branch{font-size:13px;color:#537084}@media(max-width:900px){.pf-fast-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.pf-fast-grid{grid-template-columns:1fr}.pf-fast-wrap{padding:20px 14px}}@media(max-width:520px){.pf-fast-kpis{grid-template-columns:1fr}}
        </style>
    </head>
    <body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/' . (($ctx['current_user']['role'] ?? '') === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>
        <div class="main-content">
            <div class="pf-fast-wrap" id="pf-fast-reports" data-endpoint="<?php echo htmlspecialchars($basePath . '/admin/reports_data.php', ENT_QUOTES, 'UTF-8'); ?>" data-branch="<?php echo htmlspecialchars($branchValue, ENT_QUOTES, 'UTF-8'); ?>" data-from="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>" data-to="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($reportsPerf): ?><script type="application/json" id="pf-reports-timing"><?php echo json_encode(['section' => 'shell', 'php_ms' => round($shellMs, 1)], JSON_HEX_TAG | JSON_HEX_AMP); ?></script><?php endif; ?>
                <div class="pf-fast-head"><div><h1>Reports &amp; Analytics</h1><div class="pf-fast-branch"><?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?></div></div><?php render_branch_selector($branchCtx); ?></div>
                <form class="pf-fast-filter" method="get">
                    <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($branchValue, ENT_QUOTES, 'UTF-8'); ?>">
                    <label>From<input type="date" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>"></label>
                    <label>To<input type="date" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>"></label>
                    <button type="submit">Apply filter</button>
                </form>
                <div class="pf-fast-kpis">
                    <?php foreach ($kpis as $kpi): ?><div class="pf-fast-kpi <?php echo htmlspecialchars($kpi['class'], ENT_QUOTES, 'UTF-8'); ?>"><h2><?php echo htmlspecialchars($kpi['label'], ENT_QUOTES, 'UTF-8'); ?></h2><strong><?php echo $money($kpi['value']); ?></strong><span><?php echo htmlspecialchars($kpi['note'], ENT_QUOTES, 'UTF-8'); ?></span></div><?php endforeach; ?>
                </div>
                <div class="pf-fast-grid">
                    <?php foreach ($sections as [$key, $label]): ?><section class="pf-fast-section" data-section="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><h2><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></h2><div class="pf-fast-loading" role="status">Loading report section...</div></section><?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var root=document.getElementById('pf-fast-reports'); if(!root) return;
        var endpoint=root.dataset.endpoint, params={branch_id:root.dataset.branch,from:root.dataset.from,to:root.dataset.to};
        var queue=[].slice.call(root.querySelectorAll('[data-section]')), active=0, max=2;
        function run(card){
            active++; var section=card.dataset.section, title=card.querySelector('h2') ? card.querySelector('h2').textContent : section, q=new URLSearchParams(params); q.set('section',section);
            <?php if (($_GET['reports_perf'] ?? '') === '1'): ?>q.set('reports_perf','1');<?php endif; ?>
            var controller=window.AbortController?new AbortController():null, timer=setTimeout(function(){if(controller)controller.abort();},8000);
            fetch(endpoint+'?'+q.toString(),{credentials:'same-origin',signal:controller?controller.signal:undefined}).then(function(r){if(!r.ok)throw new Error('request');return r.json();}).then(function(payload){if(!payload.success)throw new Error('section');card.innerHTML='<h2>'+title+'</h2>'+(payload.html||'<p class="pf-fast-note">No data available.</p>');if(payload.diagnostics){var d=document.createElement('div');d.className='pf-fast-perf';d.textContent='Section '+payload.diagnostics.section+' | SQL '+payload.diagnostics.query_count+' | PHP '+payload.diagnostics.php_ms+' ms';card.appendChild(d);}}).catch(function(){card.innerHTML='<h2>'+title+'</h2><div class="pf-fast-error">Unable to load this report section. Retry.<br><button class="pf-fast-retry" type="button">Retry</button></div>';card.querySelector('button').onclick=function(){card.innerHTML='<h2>'+title+'</h2><div class="pf-fast-loading">Loading report section...</div>';queue.push(card);pump();};}).finally(function(){clearTimeout(timer);active--;pump();});
        }
        function pump(){while(active<max&&queue.length)run(queue.shift());}
        pump();
    }());
    </script>
    </body></html>
    <?php
}
