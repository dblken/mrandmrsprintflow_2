<?php
// Dashboard-only placeholder; no business data or queries.
if (!isset($dashSecondaryRequest)) { http_response_code(404); exit; }
?>
<style>
.pf-dash-skeleton .pf-skel-line,.pf-dash-skeleton .pf-skel-bar{background:linear-gradient(100deg,#edf2f5 25%,#f8fafb 45%,#edf2f5 65%);background-size:250% 100%;animation:pf-dash-shimmer 1.6s ease-in-out infinite;border-radius:6px}
.pf-dash-skeleton .pf-skel-line{height:12px;width:55%;margin-bottom:18px}
.pf-dash-skeleton .pf-skel-title{width:210px;height:16px;margin-bottom:0}
.pf-dash-skeleton .pf-skel-revenue{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:24px}
.pf-dash-skeleton .pf-skel-chart{height:520px;display:flex;align-items:flex-end;justify-content:space-around;gap:16px;padding:42px 28px 30px;border-bottom:1px solid #e5edf1;background:repeating-linear-gradient(to top,transparent 0,transparent 99px,#f1f5f8 100px)}
.pf-dash-skeleton .pf-skel-bar{width:18%;height:var(--bar-height)}
.pf-dash-skeleton .pf-skel-stats{padding:20px;border:1px solid #edf2f5;border-radius:12px}
.pf-dash-skeleton .pf-skel-value{height:24px;width:70%;margin-bottom:30px}
.pf-dash-skeleton .pf-skel-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}
.pf-dash-skeleton .pf-skel-detail{height:240px}
@keyframes pf-dash-shimmer{to{background-position:-150% 0}}
@media(max-width:1023px){.pf-dash-skeleton .pf-skel-revenue{grid-template-columns:1fr}.pf-dash-skeleton .pf-skel-stats{display:none}}
@media(max-width:640px){.pf-dash-skeleton .pf-skel-grid{grid-template-columns:1fr}.pf-dash-skeleton .pf-skel-chart{height:340px;padding:32px 16px}}
@media(prefers-reduced-motion:reduce){.pf-dash-skeleton .pf-skel-line,.pf-dash-skeleton .pf-skel-bar{animation:none}}
</style>
<div class="pf-dash-skeleton" role="status" aria-label="Loading dashboard details">
    <div aria-hidden="true">
        <div class="ana-card dash-full" style="margin-bottom:28px">
            <div class="ana-hd"><div class="pf-skel-line pf-skel-title"></div></div>
            <div class="ana-bd pf-skel-revenue">
                <div class="pf-skel-chart">
                    <div class="pf-skel-bar" style="--bar-height:48%"></div>
                    <div class="pf-skel-bar" style="--bar-height:78%"></div>
                    <div class="pf-skel-bar" style="--bar-height:61%"></div>
                </div>
                <div class="pf-skel-stats">
                    <?php for ($sk = 0; $sk < 4; $sk++): ?>
                    <div class="pf-skel-line"></div><div class="pf-skel-line pf-skel-value"></div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        <div class="pf-skel-grid">
            <?php for ($sk = 0; $sk < 2; $sk++): ?>
            <div class="ana-card pf-skel-detail"><div class="ana-hd"><div class="pf-skel-line pf-skel-title"></div></div><div class="ana-bd"><div class="pf-skel-line"></div><div class="pf-skel-line"></div><div class="pf-skel-line"></div></div></div>
            <?php endfor; ?>
        </div>
    </div>
</div>
