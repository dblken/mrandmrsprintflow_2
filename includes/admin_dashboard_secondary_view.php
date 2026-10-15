<?php
// Rendered only after the dashboard's authorization and branch checks.
if (!isset($dashSecondaryRequest) || !$dashSecondaryRequest) { http_response_code(404); exit; }
$dashBranchSalesChartsPending = !empty($dashDeferBranchSalesCharts) && $branchId === 'all';
?>
<?php if ($dashDiagnostics): ?><script type="application/json" id="pf-dashboard-secondary-timing"><?php echo $dashDiagnosticsJson; ?></script><?php endif; ?>
            <script type="application/json" id="pf-dashboard-branch-chart-data"><?php echo htmlspecialchars($dashboard_branch_chart_json, ENT_NOQUOTES, 'UTF-8'); ?></script>
            <!-- Sales Revenue (Full Width) -->
            <div class="ana-card dash-full dash-sales-revenue-card" style="margin-bottom:28px;">
                    <div class="ana-hd chart-header-row" style="margin-bottom:0;">
                        <h3 class="chart-title-nowrap">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                            <?php echo htmlspecialchars($dashboard_sales_revenue_title); ?>
                            <span class="chart-badge"><?php echo htmlspecialchars($dashboard_filter_label); ?></span>
                        </h3>
                    </div>
                    <div class="ana-bd">
                    <div class="pf-branch-revenue-layout">
                        <div class="pf-branch-revenue-main">
                            <div class="chart-wrap ch-box" id="dash-sales-chart-wrap" style="height:520px;" data-branch-revenue="<?php echo htmlspecialchars($dashboard_branch_revenue_json, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="chart-loading" id="dash-sales-loading">
                                    <div class="chart-loading-spinner"></div>
                                </div>
                                <div class="chart-nodata" id="dash-sales-nodata">
                                    <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24" opacity="0.5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                    <span>No branch revenue data for this period</span>
                                </div>
                                <div class="pf-wide-chart-canvas pf-wide-chart-canvas--branch"><canvas id="dashSalesChart"></canvas></div>
                            </div>
                            <div class="pf-branch-footnote">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <?php echo htmlspecialchars($dashboard_sales_revenue_footnote); ?>
                            </div>
                        </div>
                        <aside class="pf-branch-revenue-sidebar" id="pfBranchRevenueSidebar" data-sidebar-mode="<?php echo htmlspecialchars($dashboard_branch_sidebar_mode); ?>">
                            <h4 class="pf-branch-summary-title"><?php echo htmlspecialchars($dashboard_branch_sidebar_title); ?></h4>
                            <div class="pf-branch-summary-grid">
                                <div class="pf-branch-stat pf-branch-stat-total">
                                    <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .672-3 1.5S10.343 11 12 11s3 .672 3 1.5S13.657 14 12 14m0-6V6m0 8v2m9-4a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                                    <div class="pf-branch-stat-copy">
                                        <div class="pf-branch-stat-label">Total Combined Revenue</div>
                                        <div class="pf-branch-stat-value">₱<?php echo number_format($dashboard_branch_total_revenue, 0); ?></div>
                                        <div class="pf-branch-stat-sub neu"><?php echo (int)$dashboard_branch_count; ?> branches compared</div>
                                    </div>
                                </div>
                                <div class="pf-branch-stat pf-branch-stat-top">
                                    <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81H7.03a1 1 0 00.95-.69l1.07-3.292z"/></svg></div>
                                    <div class="pf-branch-stat-copy">
                                        <div class="pf-branch-stat-label">Top Performing Branch</div>
                                        <div class="pf-branch-stat-value"><?php echo htmlspecialchars((string)($dashboard_top_branch['branch_name'] ?? '-')); ?></div>
                                        <div class="pf-branch-stat-sub pos"><?php $topPct = $dashboard_branch_total_revenue > 0 && $dashboard_top_branch ? (((float)$dashboard_top_branch['revenue'] / $dashboard_branch_total_revenue) * 100) : 0; echo $dashboard_top_branch ? ('₱' . number_format((float)$dashboard_top_branch['revenue'], 0) . ' (' . number_format($topPct, 1) . '%)') : 'No data'; ?></div>
                                    </div>
                                </div>
                                <?php if ($dashboard_branch_count > 1): ?>
                                <div class="pf-branch-stat pf-branch-stat-low">
                                    <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/></svg></div>
                                    <div class="pf-branch-stat-copy">
                                        <div class="pf-branch-stat-label">Lowest Performing Branch</div>
                                        <div class="pf-branch-stat-value"><?php echo htmlspecialchars((string)($dashboard_low_branch['branch_name'] ?? '-')); ?></div>
                                        <div class="pf-branch-stat-sub neg"><?php $lowPct = $dashboard_branch_total_revenue > 0 && $dashboard_low_branch ? (((float)$dashboard_low_branch['revenue'] / $dashboard_branch_total_revenue) * 100) : 0; echo $dashboard_low_branch ? ('₱' . number_format((float)$dashboard_low_branch['revenue'], 0) . ' (' . number_format($lowPct, 1) . '%)') : 'No data'; ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="pf-branch-stat pf-branch-stat-avg">
                                    <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 11V5a1 1 0 012 0v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6z"/></svg></div>
                                    <div class="pf-branch-stat-copy">
                                        <div class="pf-branch-stat-label">Average Revenue per Branch</div>
                                        <div class="pf-branch-stat-value">₱<?php echo number_format($dashboard_avg_branch_revenue, 0); ?></div>
                                        <div class="pf-branch-stat-sub neu">Across visible branches</div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($dashboard_branch_count > 1): ?>
                            <div class="pf-branch-toplist">
                                <h4 class="pf-branch-section-title pf-branch-toplist-title">Top Branch Rankings</h4>
                                <div class="pf-branch-toplist-items">
                                    <?php foreach ($dashboard_top_branches as $index => $branch): ?>
                                        <?php $branchPct = $dashboard_branch_total_revenue > 0 ? (((float)$branch['revenue'] / $dashboard_branch_total_revenue) * 100) : 0; ?>
                                        <?php $barPct = $dashboard_top_branch && (float)$dashboard_top_branch['revenue'] > 0 ? (((float)$branch['revenue'] / (float)$dashboard_top_branch['revenue']) * 100) : 0; ?>
                                        <div class="pf-branch-toprow">
                                            <div class="pf-branch-rank"><?php echo $index + 1; ?></div>
                                            <div class="pf-branch-toprow-copy">
                                                <div class="pf-branch-toprow-name"><?php echo htmlspecialchars((string)$branch['branch_name']); ?></div>
                                                <div class="pf-branch-toprow-bar"><span style="width:<?php echo max(6, min(100, $barPct)); ?>%"></span></div>
                                            </div>
                                            <div class="pf-branch-toprow-value">₱<?php echo number_format((float)$branch['revenue'], 0); ?> (<?php echo number_format($branchPct, 1); ?>%)</div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($dashboard_branch_total_revenue > 0): ?>
                            <div class="pf-branch-breakdown">
                                <h4 class="pf-branch-section-title">Revenue Contribution Breakdown</h4>
                                <div class="pf-branch-breakdown-row">
                                    <div class="pf-branch-breakdown-meta"><span>Product Revenue</span><strong>₱<?php echo number_format($dashboard_total_product_revenue, 0); ?> · <?php echo number_format($dashboard_product_pct, 1); ?>%</strong></div>
                                    <div class="pf-branch-breakdown-bar pf-branch-breakdown-bar--product"><span style="width:<?php echo max(0, min(100, $dashboard_product_pct)); ?>%"></span></div>
                                </div>
                                <div class="pf-branch-breakdown-row">
                                    <div class="pf-branch-breakdown-meta"><span>Service Revenue</span><strong>₱<?php echo number_format($dashboard_total_service_revenue, 0); ?> · <?php echo number_format($dashboard_service_pct, 1); ?>%</strong></div>
                                    <div class="pf-branch-breakdown-bar pf-branch-breakdown-bar--service"><span style="width:<?php echo max(0, min(100, $dashboard_service_pct)); ?>%"></span></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </aside>
                    </div>
                    </div>
                </div>

            <!-- Order Status + Inventory Alerts -->
            <div class="dash-grid">
                <!-- Order Status Breakdown -->
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Order Status Breakdown
                    </div>
                    <div id="dash-status-single-chart" class="dash-single-chart-wrap <?php echo ($branchId === 'all' && !empty($dashboard_branch_chart_payload['statuses'])) ? 'is-hidden' : ''; ?>" style="position:relative; height:240px; margin-bottom:16px; display:flex; align-items:center; justify-content:center;">
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div id="status-legend" class="dash-single-chart-legend <?php echo ($branchId === 'all' && !empty($dashboard_branch_chart_payload['statuses'])) ? 'is-hidden' : ''; ?>" style="font-size:12px; display:flex; flex-wrap:wrap; justify-content:flex-start; gap:12px; padding:0 10px;"></div>
                    <div id="dash-status-empty" class="dash-empty-state dash-empty-state--compact" style="display:none;">No order status data for this period.</div>
                    <div id="dash-status-branch-charts" class="dash-branch-chart-grid is-status <?php echo ($branchId === 'all' && !empty($dashboard_branch_chart_payload['statuses'])) ? '' : 'is-hidden'; ?>"></div>
                </div>

                <!-- Inventory Alerts -->
                <div class="dash-card">
                    <div class="dash-card-title" style="justify-content: space-between;">
                        <span style="display: flex; align-items: center; gap: 8px;">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            Inventory Alerts
                        </span>
                        <?php if ($branchId !== 'all' && (!empty($low_stock) || !empty($low_stock_by_branch))):
                            $has_critical = false;
                            $has_out_of_stock = false;
                            $alertRowsForLink = ($branchId === 'all' && !empty($low_stock_by_branch)) ? array_merge(...array_map(static fn($b) => $b['rows'] ?? [], $low_stock_by_branch)) : $low_stock;
                            foreach ($alertRowsForLink as $ls) {
                                $sk = $ls['stock_status']['key'] ?? '';
                                if ($sk === 'critical') $has_critical = true;
                                if ($sk === 'out') $has_out_of_stock = true;
                            }
                            $stock_filter = $has_out_of_stock ? 'out' : ($has_critical ? 'critical' : 'low');
                        ?>
                        <a href="<?php echo pf_admin_url('inv_items_management.php', ['stock_status' => $stock_filter]); ?>" style="font-size:13px; font-weight:600; color:#0d9488; text-decoration:none;">See all &rarr;</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($branchId === 'all' && !empty($low_stock_by_branch)): ?>
                    <div class="dash-branch-chart-grid dash-inventory-branch-grid">
                        <?php foreach ($low_stock_by_branch as $stockBranch): ?>
                            <?php
                                $stockBranchRows = $stockBranch['rows'] ?? [];
                                $stockBranchFilter = 'low';
                                foreach ($stockBranchRows as $lsLink) {
                                    $sk = $lsLink['stock_status']['key'] ?? '';
                                    if ($sk === 'out') { $stockBranchFilter = 'out'; break; }
                                    if ($sk === 'critical') { $stockBranchFilter = 'critical'; }
                                }
                            ?>
                            <div class="dash-branch-chart-card dash-inventory-branch-card">
                                <div class="dash-branch-title-row"><div class="dash-branch-chart-title"><?php echo htmlspecialchars((string)($stockBranch['branch_name'] ?? 'Branch')); ?></div><a class="dash-branch-see-all" href="<?php echo pf_admin_url('inv_items_management.php', ['branch_id' => (int)($stockBranch['branch_id'] ?? 0), 'stock_status' => $stockBranchFilter]); ?>">See all &rarr;</a></div>
                                <?php if (empty($stockBranchRows)): ?>
                                    <div class="dash-empty-state dash-empty-state--compact">No inventory alerts for this branch.</div>
                                <?php endif; ?>
                                <?php foreach ($stockBranchRows as $ls): ?>
                                    <?php
                                        $stock = (float)$ls['current_stock'];
                                        $limit = (float)$ls['low_limit'];
                                        $pct = $limit > 0 ? ($stock / $limit) * 100 : 0;
                                        $st = $ls['stock_status'] ?? printflow_resolve_stock_status($stock, $limit, printflow_item_critical_level($ls));
                                        $barClass = $st['key'] === 'out' ? 'danger' : ($st['key'] === 'critical' ? 'warning' : ($st['key'] === 'low' ? 'warning' : 'good'));
                                        $statusText = strtoupper($st['label']);
                                        $statusColor = $st['text_color'];
                                        $stockColor = $st['text_color'];
                                    ?>
                                    <div class="inventory-alert-row">
                                        <div class="inventory-alert-main">
                                            <div class="inventory-alert-name" title="<?php echo htmlspecialchars($ls['material_name']); ?>"><?php echo htmlspecialchars($ls['material_name']); ?></div>
                                            <div class="inventory-alert-sub"><?php echo htmlspecialchars($ls['category_name'] ?: 'General'); ?></div>
                                        </div>
                                        <div class="inventory-alert-stock" style="color:<?php echo $stockColor; ?>;"><?php echo number_format($stock, 1); ?> <small><?php echo htmlspecialchars($ls['unit']); ?></small></div>
                                        <div class="inventory-alert-status">
                                            <div class="stock-bar"><div class="stock-bar-fill <?php echo $barClass; ?>" style="width:<?php echo min(100, max($pct, 10)); ?>%;"></div></div>
                                            <span style="color:<?php echo $statusColor; ?>;"><?php echo $statusText; ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif (!empty($low_stock)): ?>
                    <table class="mini-table">
                        <thead><tr><th>Material</th><th>Stock</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($low_stock as $ls):
                                $stock = (float)$ls['current_stock'];
                                $limit = (float)$ls['low_limit'];
                                $pct = $limit > 0 ? ($stock / $limit) * 100 : 0;
                                $st = $ls['stock_status'] ?? printflow_resolve_stock_status($stock, $limit, printflow_item_critical_level($ls));
                                $barClass = $st['key'] === 'out' ? 'danger' : ($st['key'] === 'critical' ? 'warning' : ($st['key'] === 'low' ? 'warning' : 'good'));
                                $statusText = strtoupper($st['label']);
                                $statusColor = $st['text_color'];
                                $stockColor = $st['text_color'];
                            ?>
                            <tr>
                                <td style="font-weight:600;" title="<?php echo htmlspecialchars($ls['material_name']); ?>">
                                    <?php echo mb_strlen($ls['material_name']) > 15 ? htmlspecialchars(mb_substr($ls['material_name'], 0, 15)) . '...' : htmlspecialchars($ls['material_name']); ?>
                                    <div style="font-size:10px; color:#9ca3af;"><?php echo htmlspecialchars($ls['category_name'] ?: 'General'); ?></div>
                                </td>
                                <td style="color:<?php echo $stockColor; ?>; font-weight:700; white-space:nowrap;">
                                    <?php echo number_format($stock, 1); ?> <small><?php echo htmlspecialchars($ls['unit']); ?></small>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <div class="stock-bar" style="width:50px;"><div class="stock-bar-fill <?php echo $barClass; ?>" style="width:<?php echo min(100, max($pct, 10)); ?>%;"></div></div>
                                        <span style="font-size:10px; font-weight:700; color:<?php echo $statusColor; ?>;">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="dash-empty-state dash-empty-state--compact">No inventory alerts for this filter.</div>
                    <?php endif; ?>
                </div>

            </div>



            <!-- Best Selling Services + Top Location -->
            <div class="dash-grid">
                <!-- Best Selling Services -->
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14"/></svg>
                        Best Selling Services
                    </div>
                    <?php if ($dashBranchSalesChartsPending || !empty($dashboard_sales_bar) || !empty($dashboard_branch_chart_payload['services'])): ?>
                    <div id="dash-service-single-chart" class="products-chart <?php echo ($branchId === 'all' && ($dashBranchSalesChartsPending || !empty($dashboard_branch_chart_payload['services']))) ? 'is-hidden' : ''; ?>" data-service-bar-labels="<?php echo htmlspecialchars(json_encode($dashboard_sales_bar_labels, JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>" data-service-bar-values="<?php echo htmlspecialchars(json_encode($dashboard_sales_bar_values, JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>"><div id="productsChart"></div></div>
                    <div id="dash-service-branch-charts" class="dash-branch-chart-grid <?php echo ($branchId === 'all' && ($dashBranchSalesChartsPending || !empty($dashboard_branch_chart_payload['services']))) ? '' : 'is-hidden'; ?>"<?php echo $dashBranchSalesChartsPending ? ' aria-busy="true"' : ''; ?>><?php if ($dashBranchSalesChartsPending): ?><div class="chart-loading" style="min-height:120px;"><div class="chart-loading-spinner"></div></div><?php endif; ?></div>
                    <?php else: ?>
                    <div class="dash-empty-state">No service sales data yet</div>
                    <?php endif; ?>
                </div>

                <!-- Top Customer Locations -->
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Top Customer Locations
                    </div>
                    <?php if ($branchId === 'all' && !empty($dashboard_branch_chart_payload['locations'])): ?>
                    <div class="dash-branch-chart-grid dash-location-branch-grid">
                        <?php foreach ($dashboard_branch_chart_payload['locations'] as $locBranch): ?>
                            <?php
                                $locRows = array_slice($locBranch['rows'] ?? [], 0, 5);
                                $locMax = 0;
                                foreach ($locRows as $locRow) {
                                    $locMax = max($locMax, (int)($locRow['value'] ?? 0));
                                }
                            ?>
                            <div class="dash-branch-chart-card dash-location-branch-card">
                                <div class="dash-branch-chart-title"><?php echo htmlspecialchars((string)($locBranch['branch_name'] ?? 'Branch')); ?></div>
                                <?php if (empty($locRows)): ?>
                                    <div class="dash-empty-state dash-empty-state--compact">No customer location data for this branch.</div>
                                <?php endif; ?>
                                <div class="loc-list loc-list--branch">
                                    <?php foreach ($locRows as $index => $loc): ?>
                                        <?php $pct = $locMax > 0 ? (((int)($loc['value'] ?? 0) / $locMax) * 100) : 0; ?>
                                        <div class="loc-row">
                                            <div class="loc-header">
                                                <div class="loc-name">
                                                    <span class="loc-rank">#<?php echo $index + 1; ?></span>
                                                    <span class="loc-city"><?php echo htmlspecialchars((string)($loc['label'] ?? 'Unknown')); ?></span>
                                                </div>
                                                <div class="loc-value"><?php echo (int)($loc['value'] ?? 0); ?></div>
                                            </div>
                                            <div class="loc-bar-wrap">
                                                <div class="loc-bar" style="width:<?php echo max(3, min(100, $pct)); ?>%;"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <?php if (empty($customer_locations)): ?>
                    <div class="dash-empty-state dash-empty-state--compact">No customer location data for this period.</div>
                    <?php else: ?>
                    <?php $max_orders = max(array_column($customer_locations, 'orders')); ?>
                    <div class="loc-list">
                        <?php foreach (array_slice($customer_locations, 0, 5) as $index => $loc):
                            $pct = $max_orders > 0 ? ($loc['orders'] / $max_orders) * 100 : 0;
                        ?>
                        <div class="loc-row">
                            <div class="loc-header">
                                <div class="loc-name">
                                    <span class="loc-rank">#<?php echo $index + 1; ?></span>
                                    <span class="loc-city"><?php echo htmlspecialchars(trim($loc['city'])); ?></span>
                                </div>
                                <div class="loc-value"><?php echo $loc['orders']; ?></div>
                            </div>
                            <div class="loc-bar-wrap">
                                <div class="loc-bar" style="width:<?php echo $pct; ?>%;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Category Sales + Top Performers -->
            <div class="dash-grid">
                <!-- Sales by Product -->
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        Sales by Product
                    </div>
                    <?php if ($dashBranchSalesChartsPending || !empty($category_sales) || !empty($dashboard_branch_chart_payload['products'])): ?>
                    <div id="dash-product-single-chart" class="dash-single-chart-wrap <?php echo ($branchId === 'all' && ($dashBranchSalesChartsPending || !empty($dashboard_branch_chart_payload['products']))) ? 'is-hidden' : ''; ?>" style="position:relative; height:240px; margin-bottom:16px; display:flex; align-items:center; justify-content:center;" data-category-labels="<?php echo htmlspecialchars(json_encode(array_map(static fn($c) => trim((string)($c['category'] ?? '')) !== '' ? trim((string)$c['category']) : 'Uncategorized product', $category_sales), JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>" data-category-totals="<?php echo htmlspecialchars(json_encode(array_map($dashboard_chart_value, $category_sales), JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>"><canvas id="categoryChart"></canvas></div>
                    <div id="category-legend" class="dash-single-chart-legend <?php echo ($branchId === 'all' && ($dashBranchSalesChartsPending || !empty($dashboard_branch_chart_payload['products']))) ? 'is-hidden' : ''; ?>" style="font-size:12px; display:flex; flex-wrap:wrap; justify-content:flex-start; gap:12px; padding:0 10px;"></div>
                    <div id="dash-product-branch-charts" class="dash-branch-chart-grid <?php echo ($branchId === 'all' && ($dashBranchSalesChartsPending || !empty($dashboard_branch_chart_payload['products']))) ? '' : 'is-hidden'; ?>"<?php echo $dashBranchSalesChartsPending ? ' aria-busy="true"' : ''; ?>><?php if ($dashBranchSalesChartsPending): ?><div class="chart-loading" style="min-height:120px;"><div class="chart-loading-spinner"></div></div><?php endif; ?></div>
                    <?php else: ?>
                    <div style="text-align:center; color:#9ca3af; padding:40px 0; font-size:13px;">No official product sales data yet</div>
                    <?php endif; ?>
                </div>

                <!-- Sales by Service Category -->
                <div class="dash-card">
                    <div class="dash-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4-4 4 4 4-8 4 8"/></svg>
                        Sales by Service Category
                    </div>
                    <?php if ($dashBranchSalesChartsPending || !empty($service_category_sales) || !empty($dashboard_branch_chart_payload['services'])): ?>
                    <div id="dash-service-category-single-chart" class="dash-single-chart-wrap <?php echo ($branchId === 'all' && ($dashBranchSalesChartsPending || !empty($dashboard_branch_chart_payload['services']))) ? 'is-hidden' : ''; ?>" style="position:relative; height:240px; margin-bottom:16px; display:flex; align-items:center; justify-content:center;" data-service-category-labels="<?php echo htmlspecialchars(json_encode(array_map(static fn($c) => trim((string)($c['category'] ?? '')) !== '' ? trim((string)$c['category']) : 'Customization', $service_category_sales), JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>" data-service-category-totals="<?php echo htmlspecialchars(json_encode(array_map($dashboard_chart_value, $service_category_sales), JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>"><canvas id="serviceCategoryChart"></canvas></div>
                    <div id="service-category-legend" class="dash-single-chart-legend <?php echo ($branchId === 'all' && ($dashBranchSalesChartsPending || !empty($dashboard_branch_chart_payload['services']))) ? 'is-hidden' : ''; ?>" style="font-size:12px; display:flex; flex-wrap:wrap; justify-content:flex-start; gap:12px; padding:0 10px;"></div>
                    <div id="dash-service-category-branch-charts" class="dash-branch-chart-grid <?php echo ($branchId === 'all' && ($dashBranchSalesChartsPending || !empty($dashboard_branch_chart_payload['services']))) ? '' : 'is-hidden'; ?>"<?php echo $dashBranchSalesChartsPending ? ' aria-busy="true"' : ''; ?>><?php if ($dashBranchSalesChartsPending): ?><div class="chart-loading" style="min-height:120px;"><div class="chart-loading-spinner"></div></div><?php endif; ?></div>
                    <?php else: ?>
                    <div class="dash-empty-state">No service category data for this filter.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Orders (Full Width) -->
            <div class="dash-card dash-full">
                <div class="dash-card-title">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    Recent Orders
                </div>
                <?php if (!empty($recent_orders)): ?>
                <table class="mini-table">
                    <thead><tr><th>ID</th><th>Customer</th><th>Status</th><th style="text-align:right;">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_orders as $ro):
                            $sBadge = match($ro['status']) {
                                'Completed' => 'badge-green',
                                'Processing' => 'badge-blue',
                                'Pending' => 'badge-yellow',
                                'Ready for Pickup' => 'badge-blue',
                                'Cancelled' => 'badge-red',
                                default => 'badge-gray'
                            };
                        ?>
                        <tr>
                            <td style="font-weight:700; color:#00232b;"><?php echo $ro['order_id']; ?></td>
                            <td style="font-weight:500;"><?php echo htmlspecialchars($ro['customer_name'] ?? 'N/A'); ?></td>
                            <td><span class="badge <?php echo $sBadge; ?>"><?php echo $ro['status']; ?></span></td>
                            <td style="text-align:right; font-weight:700;">₱<?php echo number_format((float)$ro['total_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div style="text-align:center; color:#9ca3af; padding:40px 0; font-size:13px;">No orders yet</div>
                <?php endif; ?>
            </div>
