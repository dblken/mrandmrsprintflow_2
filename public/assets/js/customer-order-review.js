(function () {
    'use strict';

    const cfg = window.PFReviewConfig || {};
    const baseUrl = String(cfg.baseUrl || (window.PFConfig && window.PFConfig.basePath) || '').replace(/\/$/, '');
    const apiUrl = String(cfg.apiUrl || (baseUrl ? baseUrl + '/customer/api_review_prompt.php' : ''));
    const csrfToken = String(cfg.csrfToken || '');

    let modalOpen = false;
    let activeOrderId = 0;
    let formBoundRoot = null;
    let lastFocusBeforeModal = null;
    let reviewSubmitInFlight = false;
    const promptedThisSession = new Set();
    const dismissCache = new Map();

    function rememberFocus(fallbackEl) {
        const candidate = fallbackEl || document.activeElement;
        if (candidate && typeof candidate.focus === 'function' && candidate !== document.body) {
            lastFocusBeforeModal = candidate;
        }
    }

    function restoreFocus() {
        const target = lastFocusBeforeModal;
        lastFocusBeforeModal = null;
        if (target && typeof target.focus === 'function' && document.contains(target)) {
            try {
                target.focus({ preventScroll: true });
            } catch (e) {
                target.focus();
            }
        }
    }

    function focusFirstInModal(modalEl, root) {
        const scope = root || modalEl;
        if (!scope) return;
        const preferred = scope.querySelector('.pf-rate-star, .pf-rate-message, #completedReviewSkipBtn, #completedReviewRateBtn, button, [href], input, textarea, select');
        if (preferred && typeof preferred.focus === 'function') {
            preferred.focus({ preventScroll: true });
        }
    }

    function setModalOpenState(modalEl, isOpen, options) {
        options = options || {};
        if (!modalEl) return;
        const dialog = modalEl.querySelector('[role="dialog"]');

        if (isOpen) {
            modalEl.classList.add('open');
            modalEl.setAttribute('aria-hidden', 'false');
            modalEl.style.display = 'flex';
            modalEl.style.visibility = 'visible';
            modalEl.style.pointerEvents = 'auto';
            if (dialog) {
                dialog.setAttribute('aria-modal', 'true');
            }
            requestAnimationFrame(function () {
                focusFirstInModal(modalEl, options.focusRoot || dialog);
            });
            return;
        }

        if (document.activeElement && modalEl.contains(document.activeElement) && typeof document.activeElement.blur === 'function') {
            document.activeElement.blur();
        }
        modalEl.classList.remove('open');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.style.display = 'none';
        modalEl.style.visibility = 'hidden';
        modalEl.style.pointerEvents = 'none';
        if (!options.skipRestoreFocus) {
            restoreFocus();
        }
    }

    function notify(message, isError) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, !!isError);
            return;
        }
        if (typeof window.showOrdersToast === 'function') {
            window.showOrdersToast(message, !!isError);
            return;
        }
        showFallbackToast(message, !!isError);
    }

    function showFallbackToast(message, isError) {
        let container = document.getElementById('pf-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'pf-toast-container';
            container.style.position = 'fixed';
            container.style.bottom = '24px';
            container.style.right = '24px';
            container.style.zIndex = '99999';
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '10px';
            container.style.maxWidth = '340px';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.style.background = '#ffffff';
        toast.style.border = '1px solid #e5e7eb';
        toast.style.borderLeft = '4px solid ' + (isError ? '#ef4444' : '#22c55e');
        toast.style.borderRadius = '8px';
        toast.style.boxShadow = '0 4px 16px rgba(0,0,0,.12)';
        toast.style.padding = '12px 16px';
        toast.style.fontSize = '.875rem';
        toast.style.color = isError ? '#b91c1c' : '#166534';
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(function () { if (toast.parentNode) toast.remove(); }, 6000);
    }

    function completedModal() {
        return document.getElementById('completedReviewModal');
    }

    function reviewModal() {
        return document.getElementById('orderReviewModal');
    }

    function closeCompletedModal() {
        const el = completedModal();
        setModalOpenState(el, false);
        modalOpen = false;
        activeOrderId = 0;
    }

    function closeReviewModal(options) {
        const el = reviewModal();
        setModalOpenState(el, false, options || {});
        const mount = document.getElementById('orderReviewFormMount');
        if (mount) mount.innerHTML = '';
        formBoundRoot = null;
        reviewSubmitInFlight = false;
    }

    async function fetchPromptStatus(orderId) {
        orderId = parseInt(orderId, 10) || 0;
        if (!orderId || !apiUrl) {
            return null;
        }
        if (dismissCache.has(orderId)) {
            return dismissCache.get(orderId);
        }
        try {
            const res = await fetch(apiUrl + '?order_id=' + encodeURIComponent(orderId), { credentials: 'include' });
            const data = await res.json();
            if (data && data.success) {
                dismissCache.set(orderId, data);
                return data;
            }
        } catch (e) {}
        return null;
    }

    async function persistDismiss(orderId) {
        orderId = parseInt(orderId, 10) || 0;
        if (!orderId || !apiUrl) return false;
        try {
            const res = await fetch(apiUrl, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    action: 'dismiss',
                    order_id: orderId,
                    csrf_token: csrfToken,
                }),
            });
            const data = await res.json();
            if (data && data.success) {
                dismissCache.set(orderId, { success: true, can_prompt: false, dismissed: true });
                return true;
            }
        } catch (e) {}
        return false;
    }

    function completionMessageScore(text) {
        const lower = String(text || '').toLowerCase();
        if (lower.indexOf('picked up') !== -1 || lower.indexOf('successfully picked') !== -1) return 3;
        if (lower.indexOf('order completed') !== -1) return 2;
        if (lower.indexOf('has been completed') !== -1 || lower.indexOf('your order has been completed') !== -1) return 1;
        return 0;
    }

    async function openCompletedPrompt(orderId, message, orderCode, serviceLabel) {
        orderId = parseInt(orderId, 10) || 0;
        if (!orderId || modalOpen || promptedThisSession.has(orderId)) {
            return;
        }

        const reviewEl = reviewModal();
        if (reviewEl && reviewEl.classList.contains('open')) {
            return;
        }

        const status = await fetchPromptStatus(orderId);
        if (!status || !status.can_prompt) {
            return;
        }

        orderCode = orderCode || status.order_code || '';
        serviceLabel = serviceLabel || status.service_label || '';
        message = message || status.message || 'Your order has been successfully picked up. We hope to see you again!';

        const el = completedModal();
        if (!el) return;

        activeOrderId = orderId;
        modalOpen = true;
        promptedThisSession.add(orderId);

        const msgEl = document.getElementById('completedReviewMessage');
        const metaEl = document.getElementById('completedReviewMeta');
        if (msgEl) msgEl.textContent = message;
        if (metaEl) {
            const parts = [];
            if (orderCode) parts.push('Order ' + orderCode);
            if (serviceLabel) parts.push(serviceLabel);
            metaEl.textContent = parts.join(' · ');
        }

        el.dataset.orderId = String(orderId);
        setModalOpenState(el, true, { focusRoot: el.querySelector('.pf-review-dialog') });
    }

    async function openReviewForm(orderId, triggerEl) {
        orderId = parseInt(orderId, 10) || 0;
        if (!orderId) return;

        rememberFocus(triggerEl);
        closeCompletedModal();

        const el = reviewModal();
        const mount = document.getElementById('orderReviewFormMount');
        if (!el || !mount) return;

        mount.innerHTML = '<div class="flex flex-col items-center justify-center py-10"><div class="w-8 h-8 border-4 border-slate-200 border-t-teal-400 rounded-full animate-spin"></div><p class="mt-3 text-slate-500 text-sm font-semibold">Loading review form...</p></div>';
        setModalOpenState(el, true, { focusRoot: el.querySelector('.pf-review-dialog') });

        try {
            const res = await fetch(baseUrl + '/customer/rate_order.php?order_id=' + encodeURIComponent(orderId) + '&fragment=1', {
                credentials: 'include',
                headers: { Accept: 'text/html' },
            });
            if (!res.ok) {
                const err = await res.json().catch(function () { return {}; });
                throw new Error(err.error || 'Unable to load the review form.');
            }
            const html = await res.text();
            mount.innerHTML = '<div class="pf-rate-surface">' + html + '</div>';
            const root = mount.querySelector('.pf-rate-form-root');
            bindReviewForm(root);
            focusFirstInModal(el, root);
        } catch (err) {
            mount.innerHTML = '<div class="rate-error" role="alert">' + String(err.message || err) + '</div>';
            focusFirstInModal(el, mount);
        }
    }

    function bindReviewForm(root) {
        if (!root || formBoundRoot === root) return;
        formBoundRoot = root;

        const form = root.querySelector('.pf-rate-order-form');
        const ratingInput = root.querySelector('.pf-rate-input');
        const stars = Array.from(root.querySelectorAll('.pf-rate-star'));
        const messageInput = root.querySelector('.pf-rate-message');
        const charCount = root.querySelector('.pf-rate-char-count');
        const imageInput = root.querySelector('.pf-rate-image-input');
        const imageGrid = root.querySelector('.pf-rate-image-grid');
        const addImageBtn = root.querySelector('.pf-rate-add-image');
        const videoInput = root.querySelector('.pf-rate-video-input');
        const videoPreviewArea = root.querySelector('.pf-rate-video-preview');
        const videoPreview = root.querySelector('.pf-rate-video-player');
        const addVideoBtn = root.querySelector('.pf-rate-add-video');
        const cancelBtn = root.querySelector('.pf-rate-cancel');
        const submitBtn = root.querySelector('.pf-rate-submit');

        let selectedFiles = [];

        stars.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const value = Number(this.dataset.value || 0);
                if (ratingInput) ratingInput.value = String(value);
                stars.forEach(function (s, idx) { s.classList.toggle('active', idx < value); });
            });
        });

        if (messageInput && charCount) {
            messageInput.addEventListener('input', function () {
                if (this.value.length > 500) this.value = this.value.slice(0, 500);
                const len = this.value.length;
                charCount.textContent = len + ' / 500';
                charCount.style.color = len >= 500 ? '#ef4444' : '#64748b';
            });
        }

        function updateFileInput() {
            if (!imageInput) return;
            const dt = new DataTransfer();
            selectedFiles.forEach(function (file) { dt.items.add(file); });
            imageInput.files = dt.files;
        }

        if (imageInput && imageGrid && addImageBtn) {
            imageInput.addEventListener('change', function () {
                const newFiles = Array.from(this.files || []);
                if (selectedFiles.length + newFiles.length > 5) {
                    notify('Maximum 5 images allowed.', true);
                    this.value = '';
                    return;
                }
                newFiles.forEach(function (file) {
                    if (!file.type.startsWith('image/')) return;
                    if (file.size > 5 * 1024 * 1024) {
                        notify(file.name + ' is too large. Max 5MB.', true);
                        return;
                    }
                    selectedFiles.push(file);
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        const div = document.createElement('div');
                        div.className = 'upload-box';
                        div.innerHTML = '<img src="' + e.target.result + '"><button type="button" class="remove-btn">&times;</button>';
                        div.querySelector('.remove-btn').onclick = function () {
                            const idx = selectedFiles.indexOf(file);
                            if (idx > -1) selectedFiles.splice(idx, 1);
                            div.remove();
                            addImageBtn.style.display = 'flex';
                            updateFileInput();
                        };
                        imageGrid.insertBefore(div, addImageBtn);
                        if (selectedFiles.length >= 5) addImageBtn.style.display = 'none';
                    };
                    reader.readAsDataURL(file);
                });
                updateFileInput();
            });
        }

        if (videoInput && videoPreviewArea && videoPreview && addVideoBtn) {
            videoInput.addEventListener('change', function () {
                const file = this.files && this.files[0];
                if (!file) return;
                if (file.type !== 'video/mp4') {
                    notify('Only MP4 videos are allowed.', true);
                    this.value = '';
                    return;
                }
                if (file.size > 15 * 1024 * 1024) {
                    notify('Video too large. Max 15MB.', true);
                    this.value = '';
                    return;
                }
                videoPreview.src = URL.createObjectURL(file);
                videoPreviewArea.style.display = 'block';
                addVideoBtn.style.display = 'none';
            });

            const removeVideoBtn = root.querySelector('.pf-rate-video-remove');
            if (removeVideoBtn) {
                removeVideoBtn.addEventListener('click', function () {
                    videoInput.value = '';
                    videoPreview.src = '';
                    videoPreviewArea.style.display = 'none';
                    addVideoBtn.style.display = 'flex';
                });
            }
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                rememberFocus(cancelBtn);
                closeReviewModal();
            });
        }

        if (form) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (reviewSubmitInFlight) {
                    return;
                }
                const rating = Number(ratingInput && ratingInput.value ? ratingInput.value : 0);
                if (rating < 1 || rating > 5) {
                    notify('Please select a star rating.', true);
                    return;
                }
                const msg = messageInput ? messageInput.value.trim() : '';
                if (msg.length < 5) {
                    notify('Please share at least 5 characters of feedback.', true);
                    return;
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Submitting...';
                }
                reviewSubmitInFlight = true;

                const fd = new FormData(form);
                fd.set('ajax', '1');

                try {
                    const res = await fetch(form.action, { method: 'POST', body: fd, credentials: 'include' });
                    const data = await res.json();
                    if (!data.success) {
                        throw new Error(data.error || 'Could not submit your review.');
                    }
                    notify(data.message || 'Your feedback has been submitted successfully.', false);
                    dismissCache.set(orderIdFromRoot(root), { success: true, can_prompt: false, dismissed: false });
                    closeReviewModal({ skipRestoreFocus: false });
                    if (typeof window.refreshOrdersList === 'function') {
                        window.refreshOrdersList();
                    }
                } catch (err) {
                    notify(String(err.message || err), true);
                    let inlineError = root.querySelector('.pf-rate-inline-error');
                    if (!inlineError) {
                        inlineError = document.createElement('div');
                        inlineError.className = 'rate-error pf-rate-inline-error';
                        inlineError.setAttribute('role', 'alert');
                        form.insertBefore(inlineError, form.firstChild);
                    }
                    inlineError.textContent = String(err.message || err);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Review';
                    }
                } finally {
                    reviewSubmitInFlight = false;
                }
            });
        }
    }

    function orderIdFromRoot(root) {
        return parseInt(root && root.dataset ? root.dataset.orderId : '0', 10) || 0;
    }

    function wireUi() {
        const completed = completedModal();
        if (completed) {
            completed.addEventListener('click', function (e) {
                if (e.target === completed) closeCompletedModal();
            });
            const skipBtn = document.getElementById('completedReviewSkipBtn');
            const rateBtn = document.getElementById('completedReviewRateBtn');
            if (skipBtn) {
                skipBtn.addEventListener('click', async function () {
                    rememberFocus(skipBtn);
                    const orderId = parseInt(completed.dataset.orderId || activeOrderId || '0', 10);
                    await persistDismiss(orderId);
                    closeCompletedModal();
                });
            }
            if (rateBtn) {
                rateBtn.addEventListener('click', function () {
                    const orderId = parseInt(completed.dataset.orderId || activeOrderId || '0', 10);
                    openReviewForm(orderId, rateBtn);
                });
            }
        }

        const review = reviewModal();
        if (review) {
            review.addEventListener('click', function (e) {
                if (e.target === review) closeReviewModal();
            });
            const closeBtn = document.getElementById('orderReviewCloseBtn');
            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    rememberFocus(closeBtn);
                    closeReviewModal();
                });
            }
        }

        document.addEventListener('click', function (e) {
            const trigger = e.target.closest('[data-pf-open-review]');
            if (!trigger) return;
            e.preventDefault();
            const orderId = parseInt(trigger.getAttribute('data-pf-open-review') || '0', 10);
            if (!orderId) return;
            openReviewForm(orderId, trigger);
        });
    }

    function isCompletedReviewNotice(item) {
        const msg = ((item && item.message) || '') + ' ' + ((item && item.title) || '');
        const lower = msg.toLowerCase();
        if (lower.indexOf('new message') !== -1 && lower.indexOf('completed') === -1 && lower.indexOf('picked up') === -1) {
            return false;
        }
        return lower.indexOf('picked up') !== -1
            || lower.indexOf('successfully picked') !== -1
            || lower.indexOf('order completed') !== -1
            || lower.indexOf('your order has been completed') !== -1
            || lower.indexOf('how was your experience') !== -1
            || lower.indexOf('leave a review') !== -1;
    }

    function pickBetterCompletionNotice(existing, candidate) {
        if (!existing) return candidate;
        const existingScore = completionMessageScore((existing.message || '') + ' ' + (existing.title || ''));
        const candidateScore = completionMessageScore((candidate.message || '') + ' ' + (candidate.title || ''));
        return candidateScore >= existingScore ? candidate : existing;
    }

    window.PFOrderReview = {
        openCompletedPrompt: openCompletedPrompt,
        openReviewForm: openReviewForm,
        closeCompletedModal: closeCompletedModal,
        closeReviewModal: closeReviewModal,
        isCompletedReviewNotice: isCompletedReviewNotice,
        pickBetterCompletionNotice: pickBetterCompletionNotice,
        completionMessageScore: completionMessageScore,
    };

    document.addEventListener('pf:completed-order', function (event) {
        const detail = event.detail || {};
        openCompletedPrompt(detail.orderId, detail.message, detail.orderCode, detail.serviceLabel);
    });

    function boot() {
        wireUi();
        if (cfg.initialPrompt && cfg.initialPrompt.order_id) {
            openCompletedPrompt(
                cfg.initialPrompt.order_id,
                cfg.initialPrompt.message,
                cfg.initialPrompt.order_code,
                cfg.initialPrompt.service_label
            );
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
