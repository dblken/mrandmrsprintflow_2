(function () {
    'use strict';

    const cfg = window.PFReviewConfig || {};
    const baseUrl = String(cfg.baseUrl || '').replace(/\/$/, '');
    const dismissedKey = (orderId) => 'pf_review_skip_' + orderId;
    const promptedKey = (orderId) => 'pf_review_prompted_' + orderId;

    let modalOpen = false;
    let activeOrderId = 0;
    let formBoundRoot = null;

    function isOrdersPage() {
        return /\/customer\/orders\.php/i.test(window.location.pathname);
    }

    function isDismissed(orderId) {
        if (!orderId) return true;
        try {
            return sessionStorage.getItem(dismissedKey(orderId)) === '1';
        } catch (e) {
            return false;
        }
    }

    function markDismissed(orderId) {
        if (!orderId) return;
        try {
            sessionStorage.setItem(dismissedKey(orderId), '1');
        } catch (e) {}
    }

    function wasPrompted(orderId) {
        if (!orderId) return true;
        try {
            return sessionStorage.getItem(promptedKey(orderId)) === '1';
        } catch (e) {}
        return false;
    }

    function markPrompted(orderId) {
        if (!orderId) return;
        try {
            sessionStorage.setItem(promptedKey(orderId), '1');
        } catch (e) {}
    }

    function notify(message, isError) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, !!isError);
            return;
        }
        if (typeof window.showOrdersToast === 'function') {
            window.showOrdersToast(message, !!isError);
        }
    }

    function completedModal() {
        return document.getElementById('completedReviewModal');
    }

    function reviewModal() {
        return document.getElementById('orderReviewModal');
    }

    function closeCompletedModal() {
        const el = completedModal();
        if (el) el.classList.remove('open');
        modalOpen = false;
        activeOrderId = 0;
    }

    function closeReviewModal() {
        const el = reviewModal();
        if (el) el.classList.remove('open');
        const mount = document.getElementById('orderReviewFormMount');
        if (mount) mount.innerHTML = '';
        formBoundRoot = null;
    }

    function openCompletedPrompt(orderId, message, orderCode, serviceLabel) {
        orderId = parseInt(orderId, 10) || 0;
        if (!orderId || modalOpen || isDismissed(orderId) || wasPrompted(orderId)) {
            return;
        }

        const reviewEl = reviewModal();
        if (reviewEl && reviewEl.classList.contains('open')) {
            return;
        }

        const el = completedModal();
        if (!el) return;

        activeOrderId = orderId;
        modalOpen = true;
        markPrompted(orderId);

        const msgEl = document.getElementById('completedReviewMessage');
        const metaEl = document.getElementById('completedReviewMeta');
        if (msgEl) {
            msgEl.textContent = message || 'Your order has been successfully picked up. We hope to see you again!';
        }
        if (metaEl) {
            const parts = [];
            if (orderCode) parts.push('Order ' + orderCode);
            if (serviceLabel) parts.push(serviceLabel);
            metaEl.textContent = parts.join(' · ');
        }

        el.dataset.orderId = String(orderId);
        el.classList.add('open');
    }

    async function openReviewForm(orderId) {
        orderId = parseInt(orderId, 10) || 0;
        if (!orderId) return;

        closeCompletedModal();

        const el = reviewModal();
        const mount = document.getElementById('orderReviewFormMount');
        if (!el || !mount) return;

        mount.innerHTML = '<div class="flex flex-col items-center justify-center py-10"><div class="w-8 h-8 border-4 border-slate-200 border-t-teal-400 rounded-full animate-spin"></div><p class="mt-3 text-slate-500 text-sm font-semibold">Loading review form...</p></div>';
        el.classList.add('open');

        try {
            const res = await fetch(baseUrl + '/customer/rate_order.php?order_id=' + encodeURIComponent(orderId) + '&fragment=1', {
                credentials: 'include',
                headers: { Accept: 'text/html' },
            });
            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                throw new Error(err.error || 'Unable to load the review form.');
            }
            const html = await res.text();
            mount.innerHTML = '<div class="pf-rate-surface">' + html + '</div>';
            bindReviewForm(mount.querySelector('.pf-rate-form-root'));
        } catch (err) {
            mount.innerHTML = '<div class="rate-error">' + String(err.message || err) + '</div>';
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

        stars.forEach((btn) => {
            btn.addEventListener('click', function () {
                const value = Number(this.dataset.value || 0);
                if (ratingInput) ratingInput.value = String(value);
                stars.forEach((s, idx) => s.classList.toggle('active', idx < value));
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
            selectedFiles.forEach((file) => dt.items.add(file));
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
                newFiles.forEach((file) => {
                    if (!file.type.startsWith('image/')) return;
                    if (file.size > 5 * 1024 * 1024) {
                        notify(file.name + ' is too large. Max 5MB.', true);
                        return;
                    }
                    selectedFiles.push(file);
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const div = document.createElement('div');
                        div.className = 'upload-box';
                        div.innerHTML = '<img src="' + e.target.result + '"><button type="button" class="remove-btn">&times;</button>';
                        div.querySelector('.remove-btn').onclick = () => {
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
                const url = URL.createObjectURL(file);
                videoPreview.src = url;
                videoPreviewArea.style.display = 'block';
                addVideoBtn.style.display = 'none';
            });

            const removeVideoBtn = root.querySelector('.pf-rate-video-remove');
            if (removeVideoBtn) {
                removeVideoBtn.addEventListener('click', () => {
                    videoInput.value = '';
                    videoPreview.src = '';
                    videoPreviewArea.style.display = 'none';
                    addVideoBtn.style.display = 'flex';
                });
            }
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                const orderId = parseInt(root.dataset.orderId || '0', 10);
                markDismissed(orderId);
                closeReviewModal();
            });
        }

        if (form) {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
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

                const fd = new FormData(form);
                fd.set('ajax', '1');

                try {
                    const res = await fetch(form.action, { method: 'POST', body: fd, credentials: 'include' });
                    const data = await res.json();
                    if (!data.success) {
                        throw new Error(data.error || 'Could not submit your review.');
                    }
                    notify(data.message || 'Thank you! Your review has been submitted.', false);
                    closeReviewModal();
                    if (typeof window.refreshOrdersList === 'function') {
                        window.refreshOrdersList();
                    } else {
                        window.location.reload();
                    }
                } catch (err) {
                    notify(String(err.message || err), true);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Review';
                    }
                }
            });
        }
    }

    function wireUi() {
        const completed = completedModal();
        if (completed) {
            completed.addEventListener('click', (e) => {
                if (e.target === completed) {
                    markDismissed(parseInt(completed.dataset.orderId || '0', 10));
                    closeCompletedModal();
                }
            });
            const skipBtn = document.getElementById('completedReviewSkipBtn');
            const rateBtn = document.getElementById('completedReviewRateBtn');
            if (skipBtn) {
                skipBtn.addEventListener('click', () => {
                    markDismissed(parseInt(completed.dataset.orderId || activeOrderId || '0', 10));
                    closeCompletedModal();
                });
            }
            if (rateBtn) {
                rateBtn.addEventListener('click', () => {
                    const orderId = parseInt(completed.dataset.orderId || activeOrderId || '0', 10);
                    openReviewForm(orderId);
                });
            }
        }

        const review = reviewModal();
        if (review) {
            review.addEventListener('click', (e) => {
                if (e.target === review) closeReviewModal();
            });
            const closeBtn = document.getElementById('orderReviewCloseBtn');
            if (closeBtn) closeBtn.addEventListener('click', closeReviewModal);
        }

        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-pf-open-review]');
            if (!trigger) return;
            e.preventDefault();
            const orderId = parseInt(trigger.getAttribute('data-pf-open-review') || '0', 10);
            if (!orderId) return;
            openReviewForm(orderId);
        });
    }

    window.PFOrderReview = {
        openCompletedPrompt,
        openReviewForm,
        closeCompletedModal,
        closeReviewModal,
        isCompletedReviewNotice: function (item) {
            const msg = ((item && item.message) || '') + ' ' + ((item && item.title) || '');
            const lower = msg.toLowerCase();
            return lower.indexOf('picked up') !== -1
                || lower.indexOf('successfully picked') !== -1
                || (lower.indexOf('order completed') !== -1)
                || (lower.indexOf('how was your experience') !== -1);
        },
    };

    document.addEventListener('pf:completed-order', (event) => {
        if (!isOrdersPage()) return;
        const detail = event.detail || {};
        openCompletedPrompt(
            detail.orderId,
            detail.message,
            detail.orderCode,
            detail.serviceLabel
        );
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
