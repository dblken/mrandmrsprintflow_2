<style>
.pf-rate-surface {
    --pf-accent: #53c5e0;
    --pf-accent-dark: #32a1c4;
    --pf-border: #e2e8f0;
    --pf-muted: #64748b;
    --pf-soft: #f8fafc;
}
.pf-rate-surface .rate-info-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.9rem; margin-bottom: 1.5rem; }
.pf-rate-surface .rate-info-item { background: var(--pf-soft); border: 1px solid var(--pf-border); border-radius: 10px; padding: 0.9rem 1rem; }
.pf-rate-surface .rate-info-label { display: block; font-size: 0.72rem; color: var(--pf-muted); font-weight: 800; text-transform: uppercase; margin-bottom: 0.25rem; }
.pf-rate-surface .rate-info-value { font-size: 1rem; color: #0f172a; font-weight: 800; }
.pf-rate-surface .rate-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start; }
.pf-rate-surface .rate-panel { border: 1px solid var(--pf-border); border-radius: 12px; background: #ffffff; padding: 1.25rem; }
.pf-rate-surface .rate-stars { display: flex; gap: 10px; margin-bottom: 1.25rem; flex-wrap: wrap; }
.pf-rate-surface .rate-star-btn { width: 52px; height: 52px; border: 1px solid #cbd5e1; border-radius: 0.85rem; background: #ffffff; color: #cbd5e1; font-size: 32px; line-height: 1; cursor: pointer; transition: all 0.24s; display: flex; align-items: center; justify-content: center; padding-bottom: 4px; font-family: inherit; }
.pf-rate-surface .rate-star-btn:hover { border-color: #f59e0b; color: #f59e0b; background: #fff7ed; transform: translateY(-2px); }
.pf-rate-surface .rate-star-btn.active { border-color: #f59e0b; background: #fff7ed; color: #f59e0b; box-shadow: 0 6px 15px rgba(245, 158, 11, 0.16); }
.pf-rate-surface .rate-label { display: block; font-size: 0.8rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--pf-muted); margin-bottom: 0.6rem; font-family: inherit; }
.pf-rate-surface .rate-textarea { width: 100%; min-height: 180px; border: 1px solid #cbd5e1; border-radius: 12px; background: #ffffff; color: #0f172a; padding: 1rem 1.05rem; font-size: 0.98rem; font-family: inherit; resize: vertical; outline: none; transition: all 0.2s; line-height: 1.6; box-sizing: border-box; }
.pf-rate-surface .rate-textarea:focus { border-color: var(--pf-accent); box-shadow: 0 0 0 4px rgba(83, 197, 224, 0.18); }
.pf-rate-surface .upload-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 10px; margin-top: 10px; }
.pf-rate-surface .upload-box { position: relative; aspect-ratio: 1; border: 2px dashed #cbd5e1; border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--pf-muted); cursor: pointer; transition: all 0.2s; overflow: hidden; background: var(--pf-soft); font-family: inherit; }
.pf-rate-surface .upload-box:hover { border-color: var(--pf-accent); background: #f0f9ff; color: #0369a1; }
.pf-rate-surface .upload-box img, .pf-rate-surface .upload-box video { width: 100%; height: 100%; object-fit: cover; }
.pf-rate-surface .upload-box .remove-btn { position: absolute; top: 4px; right: 4px; background: rgba(220, 38, 38, 0.92); color: white; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; border: none; cursor: pointer; font-family: inherit; }
.pf-rate-surface .rate-actions { margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; }
.pf-rate-surface .rate-btn-primary { background: linear-gradient(135deg, var(--pf-accent), var(--pf-accent-dark)); color: #ffffff !important; border: none; border-radius: 10px; padding: 0.95rem 1.5rem; font-weight: 800; font-size: 0.95rem; font-family: inherit; cursor: pointer; transition: all 0.25s; box-shadow: 0 6px 18px rgba(50, 161, 196, 0.22); }
.pf-rate-surface .rate-btn-primary:hover:not(:disabled) { background: linear-gradient(135deg, var(--pf-accent-dark), #2788a8); transform: translateY(-2px); }
.pf-rate-surface .rate-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.pf-rate-surface .rate-btn-secondary { background: #ffffff; color: #334155; border: 1px solid var(--pf-border); border-radius: 10px; padding: 0.9rem 1.35rem; font-weight: 700; font-size: 0.95rem; font-family: inherit; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
.pf-rate-surface .rate-btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }
.pf-rate-surface .rate-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 1rem 1.1rem; margin-bottom: 1rem; font-size: 0.95rem; font-weight: 700; font-family: inherit; }

#completedReviewModal, #orderReviewModal {
    position: fixed; inset: 0; z-index: 100003;
    display: flex; align-items: center; justify-content: center;
    padding: 16px; background: rgba(15, 23, 42, 0.55);
    opacity: 0; pointer-events: none; transition: opacity .2s ease;
}
#completedReviewModal.open, #orderReviewModal.open { opacity: 1; pointer-events: auto; }
.pf-review-dialog {
    width: 100%; max-width: min(420px, calc(100vw - 32px));
    background: #fff; border-radius: 20px; border: 1px solid #e2e8f0;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.18);
    padding: 1.75rem 1.5rem 1.5rem; box-sizing: border-box;
}
.pf-review-dialog--wide { max-width: min(920px, calc(100vw - 24px)); max-height: calc(100vh - 32px); overflow: auto; padding: 1.25rem; }
.pf-review-dialog h2 { margin: 0 0 0.5rem; font-size: 1.25rem; font-weight: 800; color: #0f172a; }
.pf-review-dialog p { margin: 0 0 1.25rem; color: #64748b; font-size: 0.92rem; line-height: 1.55; }
.pf-review-dialog-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.pf-review-dialog-actions .rate-btn-primary,
.pf-review-dialog-actions .rate-btn-secondary { flex: 1 1 140px; min-height: 48px; }
.pf-review-modal-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.75rem; }
.pf-review-modal-close { border: none; background: transparent; color: #64748b; font-size: 1.35rem; line-height: 1; cursor: pointer; padding: 0.25rem; }
@media (max-width: 768px) {
    .pf-rate-surface .rate-columns, .pf-rate-surface .rate-info-grid { grid-template-columns: 1fr; }
    .pf-rate-surface .rate-stars { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0.55rem; }
    .pf-rate-surface .rate-star-btn { width: 100%; min-width: 0; height: 3rem; font-size: 1.7rem; }
    .pf-rate-surface .rate-actions { flex-direction: column-reverse; align-items: stretch; }
    .pf-rate-surface .rate-btn-primary, .pf-rate-surface .rate-btn-secondary { width: 100%; min-height: 48px; }
    .pf-review-dialog-actions { flex-direction: column-reverse; }
}
</style>
