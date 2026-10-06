<div id="completedReviewModal" class="pf-rate-surface" aria-hidden="true">
    <div class="pf-review-dialog" role="dialog" aria-modal="true" aria-labelledby="completedReviewTitle" onclick="event.stopPropagation()">
        <h2 id="completedReviewTitle">Order completed</h2>
        <p id="completedReviewMessage">Your order has been successfully picked up. We hope to see you again!</p>
        <p id="completedReviewMeta" style="font-size:0.82rem;margin-top:-0.75rem;"></p>
        <div class="pf-review-dialog-actions">
            <button type="button" class="rate-btn-secondary" id="completedReviewSkipBtn">Skip for now</button>
            <button type="button" class="rate-btn-primary" id="completedReviewRateBtn">Rate Us</button>
        </div>
    </div>
</div>

<div id="orderReviewModal" class="pf-rate-surface" aria-hidden="true">
    <div class="pf-review-dialog pf-review-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="orderReviewTitle" onclick="event.stopPropagation()">
        <div class="pf-review-modal-head">
            <h2 id="orderReviewTitle">Rate your order</h2>
            <button type="button" class="pf-review-modal-close" id="orderReviewCloseBtn" aria-label="Close">&times;</button>
        </div>
        <div id="orderReviewFormMount"></div>
    </div>
</div>
