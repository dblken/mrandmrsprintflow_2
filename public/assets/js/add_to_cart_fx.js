/**
 * Add-to-cart fly animation (customer catalog pages only).
 * Uses live DOM positions; respects prefers-reduced-motion.
 */
(function (global) {
    'use strict';

    var STYLE_ID = 'pf-add-to-cart-fx-styles';
    var pendingKeys = new Set();
    var confirmTimer = null;

    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) {
            return;
        }
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = [
            '.pf-fly-clone{position:fixed;z-index:10050;pointer-events:none;border-radius:8px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.28);object-fit:cover;background:#f1f5f9;will-change:left,top,width,height,opacity}',
            '.pf-cart-icon-bounce{animation:pfCartBounce 420ms cubic-bezier(.34,1.56,.64,1)}',
            '@keyframes pfCartBounce{0%{transform:scale(1)}35%{transform:scale(1.18)}70%{transform:scale(.94)}100%{transform:scale(1)}}',
            '.pf-cart-confirm{position:fixed;z-index:10051;padding:6px 12px;border-radius:999px;background:rgba(15,23,42,.92);color:#fff;font-size:12px;font-weight:700;letter-spacing:.02em;pointer-events:none;opacity:0;transform:translate(-50%,6px);transition:opacity .22s ease,transform .22s ease;white-space:nowrap;max-width:min(92vw,260px);text-overflow:ellipsis;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.22)}',
            '.pf-cart-confirm.is-visible{opacity:1;transform:translate(-50%,0)}',
            '.shopee-btn-cart.is-pending{opacity:.72;transform:scale(.96);pointer-events:none;transition:opacity .15s ease,transform .15s ease}',
            '.pos-catalog-card.is-selecting{outline:2px solid rgba(13,148,136,.45);outline-offset:2px;transform:translateY(-2px)}',
            '.pf-pos-cart-panel.is-receiving{box-shadow:inset 0 0 0 2px rgba(13,148,136,.35);transition:box-shadow .25s ease}'
        ].join('');
        document.head.appendChild(style);
    }

    function prefersReducedMotion() {
        return !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function getCartIconEl(opts) {
        opts = opts || {};
        if (opts.cartTargetSelector) {
            var target = document.querySelector(opts.cartTargetSelector);
            if (target) {
                return target.querySelector('.pf-pos-cart-icon, .pf-cart-icon') || target;
            }
        }
        return document.querySelector('.pf-pos-cart-icon')
            || document.querySelector('.pf-cart-icon')
            || document.querySelector('a[href*="cart.php"] svg');
    }

    function getCartAnchorEl(opts) {
        opts = opts || {};
        if (opts.cartTargetSelector) {
            var panel = document.querySelector(opts.cartTargetSelector);
            if (panel) {
                return panel;
            }
        }
        var icon = getCartIconEl(opts);
        if (icon && icon.closest) {
            var anchor = icon.closest('a');
            if (anchor) {
                return anchor;
            }
            if (icon.classList && (icon.classList.contains('pf-pos-cart-icon') || icon.classList.contains('pf-cart-icon'))) {
                return icon.closest('.pos-cart-header') || icon;
            }
        }
        return document.querySelector('a[href*="cart.php"]');
    }

    function findCatalogCard(el, opts) {
        if (!el || !el.closest) {
            return null;
        }
        var selector = (opts && opts.cardSelector) ? opts.cardSelector : '.shopee-card, .pos-catalog-card';
        return el.closest(selector);
    }

    function getFlySourceFromCard(card) {
        if (!card) {
            return null;
        }
        var img = card.querySelector(
            '.pos-catalog-card__media img, .shopee-img-wrap img.shopee-img, img.shopee-img, video.shopee-img'
        );
        if (img) {
            return img;
        }
        return card.querySelector('.pos-catalog-card__media, .shopee-img-wrap') || card;
    }

    function createClone(sourceEl) {
        var rect = sourceEl.getBoundingClientRect();
        var clone;
        if (sourceEl.tagName === 'IMG') {
            clone = document.createElement('img');
            clone.src = sourceEl.currentSrc || sourceEl.src || '';
            clone.alt = '';
        } else if (sourceEl.tagName === 'VIDEO') {
            clone = document.createElement('div');
            clone.style.background = 'linear-gradient(135deg,#0f3441 0%,#53c5e0 100%)';
        } else {
            clone = document.createElement('div');
            clone.style.background = 'linear-gradient(135deg,#e2e8f0 0%,#cbd5e1 100%)';
        }
        clone.className = 'pf-fly-clone';
        var size = Math.max(44, Math.min(Math.max(rect.width, rect.height), 84));
        clone.style.width = size + 'px';
        clone.style.height = size + 'px';
        clone.style.left = (rect.left + rect.width / 2 - size / 2) + 'px';
        clone.style.top = (rect.top + rect.height / 2 - size / 2) + 'px';
        document.body.appendChild(clone);
        return {
            clone: clone,
            size: size,
            startX: rect.left + rect.width / 2,
            startY: rect.top + rect.height / 2
        };
    }

    function animateFly(clone, size, startX, startY, destRect, duration, done) {
        var endX = destRect.left + destRect.width / 2;
        var endY = destRect.top + destRect.height / 2;
        var arcLift = Math.min(140, Math.max(48, Math.abs(startY - endY) * 0.35));
        var midX = (startX + endX) / 2;
        var midY = Math.min(startY, endY) - arcLift;
        var start = performance.now();

        function frame(now) {
            var t = Math.min(1, (now - start) / duration);
            var eased = 1 - Math.pow(1 - t, 3);
            var u = 1 - eased;
            var x = (u * u * startX) + (2 * u * eased * midX) + (eased * eased * endX);
            var y = (u * u * startY) + (2 * u * eased * midY) + (eased * eased * endY);
            var scale = 1 - (eased * 0.75);
            var draw = size * scale;
            clone.style.left = (x - draw / 2) + 'px';
            clone.style.top = (y - draw / 2) + 'px';
            clone.style.width = draw + 'px';
            clone.style.height = draw + 'px';
            clone.style.opacity = String(Math.max(0.15, 1 - eased * 0.2));
            if (t < 1) {
                global.requestAnimationFrame(frame);
            } else {
                clone.remove();
                if (typeof done === 'function') {
                    done();
                }
            }
        }
        global.requestAnimationFrame(frame);
    }

    function bounceCart(opts) {
        opts = opts || {};
        var icon = getCartIconEl(opts);
        if (!icon) {
            return;
        }
        icon.classList.remove('pf-cart-icon-bounce');
        void icon.offsetWidth;
        icon.classList.add('pf-cart-icon-bounce');
        if (opts.cartTargetSelector) {
            var panel = document.querySelector(opts.cartTargetSelector);
            if (panel) {
                panel.classList.add('is-receiving');
                global.setTimeout(function () {
                    panel.classList.remove('is-receiving');
                }, 520);
            }
        }
        global.setTimeout(function () {
            icon.classList.remove('pf-cart-icon-bounce');
        }, 450);
    }

    function showCartConfirm(message, opts) {
        opts = opts || {};
        ensureStyles();
        var anchor = getCartAnchorEl(opts);
        if (!anchor) {
            if (typeof global.showToast === 'function') {
                global.showToast(message);
            }
            return;
        }
        var el = document.getElementById('pf-cart-confirm');
        if (!el) {
            el = document.createElement('div');
            el.id = 'pf-cart-confirm';
            el.className = 'pf-cart-confirm';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        var rect = anchor.getBoundingClientRect();
        el.textContent = message;
        el.style.left = Math.max(12, Math.min(global.innerWidth - 12, rect.left + rect.width / 2)) + 'px';
        el.style.top = Math.min(global.innerHeight - 12, rect.bottom + 8) + 'px';
        el.classList.remove('is-visible');
        void el.offsetWidth;
        el.classList.add('is-visible');
        global.clearTimeout(confirmTimer);
        confirmTimer = global.setTimeout(function () {
            el.classList.remove('is-visible');
        }, 2200);
    }

    function run(sourceButton, opts) {
        opts = opts || {};
        ensureStyles();
        var card = findCatalogCard(sourceButton, opts);
        var source = getFlySourceFromCard(card);
        var cartIcon = getCartIconEl(opts);
        var message = opts.message || 'Added to cart ✓';

        function finish() {
            bounceCart(opts);
            showCartConfirm(message, opts);
            if (typeof opts.onComplete === 'function') {
                opts.onComplete();
            }
        }

        if (!cartIcon || !source || prefersReducedMotion()) {
            finish();
            return;
        }

        var dest = cartIcon.getBoundingClientRect();
        if (!dest.width || !dest.height) {
            finish();
            return;
        }

        var pack = createClone(source);
        animateFly(
            pack.clone,
            pack.size,
            pack.startX,
            pack.startY,
            dest,
            typeof opts.duration === 'number' ? opts.duration : 780,
            finish
        );
    }

    function withLock(key, button, fn) {
        if (pendingKeys.has(key)) {
            return Promise.resolve(false);
        }
        pendingKeys.add(key);
        if (button && button.classList) {
            button.classList.add('is-pending');
        }
        return Promise.resolve(fn()).finally(function () {
            pendingKeys.delete(key);
            if (button && button.classList) {
                button.classList.remove('is-pending');
            }
        });
    }

    global.PFAddToCartFx = {
        run: run,
        bounceCart: bounceCart,
        showCartConfirm: showCartConfirm,
        withLock: withLock,
        isPending: function (key) {
            return pendingKeys.has(key);
        }
    };
}(window));
