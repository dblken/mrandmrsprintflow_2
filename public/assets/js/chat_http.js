(function () {
    'use strict';

    const cfg = window.PrintFlowChatConfig || {};
    if (!cfg.role) return;

    const base = String(cfg.baseUrl || '').replace(/\/$/, '');
    const isStaff = cfg.role === 'staff';
    const ids = isStaff ? {
        list: 'convList', search: 'searchInput', messages: 'messagesArea', input: 'msgInput', send: 'btnSend',
        file: 'mediaInput', preview: 'imgPreviewArea', reply: 'replyPreviewBox', replyText: 'replyPreviewText',
        welcome: 'welcomeScreen', chat: 'chatInterface', name: 'activeName', meta: 'activeMeta', avatar: 'activeAvatar',
        online: 'partnerStatus', pinned: 'pinnedBar', pinnedText: 'pinnedCountText', lightbox: 'staffLightbox',
        lightboxImage: 'staffLightboxImg', lightboxDownload: 'staffLightboxDownload', gallery: 'mediaGallery', galleryGrid: 'mediaGrid'
    } : {
        list: 'convList', search: 'convSearch', messages: 'messagesArea', input: 'customerMsgInput', send: 'customerSendBtn',
        file: 'customerMediaInput', preview: 'customerImgPreview', reply: 'replyBox', replyText: 'replyPreviewTxt',
        welcome: 'welcome', chat: 'chatInterface', name: 'hName', meta: 'hMeta', avatar: 'hAvatar', online: 'hOnline',
        pinned: 'pinnedBar', pinnedText: 'pinnedTxt', lightbox: 'chatLightbox', lightboxImage: 'lightboxImg',
        lightboxDownload: 'lightboxDownload', gallery: 'galleryPanel', galleryGrid: 'galleryGrid'
    };

    const el = name => document.getElementById(ids[name] || name);
    const state = {
        activeId: 0, generation: 0, lastId: 0, firstId: 0, hasOlder: false, loadingOlder: false,
        messageFlight: false, listFlight: false, sendFlight: false, seenFlight: false, lastMarkedSeen: 0,
        messageAbort: null, listAbort: null, messageTimer: null, listTimer: null,
        reply: null, files: [], messages: new Map(), reactions: new Map(), pinned: [], nearBottom: true,
        initialHandled: false
    };

    const reactionEmoji = { like: '👍', love: '❤️', haha: '😂', wow: '😮', sad: '😢', angry: '😡' };

    function toast(message) {
        const node = document.createElement('div');
        node.className = 'pf-toast';
        node.textContent = message || 'Something went wrong';
        document.body.appendChild(node);
        setTimeout(() => node.remove(), 3200);
    }

    async function request(path, options = {}) {
        const opts = { credentials: 'same-origin', ...options };
        if (opts.method === 'POST' && opts.body instanceof FormData && !opts.body.has('csrf_token')) {
            opts.body.append('csrf_token', cfg.csrf || '');
        }
        const response = await fetch(base + path, opts);
        let data;
        try { data = await response.json(); } catch (_) { data = { success: false, error: `Invalid server response (${response.status})` }; }
        if (!response.ok || !data.success) {
            const error = new Error(data.error || `Request failed (${response.status})`);
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function safeUrl(value) {
        const path = String(value || '').trim();
        if (!path || /^data:/i.test(path) || /^(javascript|vbscript):/i.test(path)) return '';
        if (/^https?:\/\//i.test(path)) return path;
        if (path.startsWith(base + '/')) return path;
        return base + '/' + path.replace(/^\/+/, '');
    }

    function formatAgo(value) {
        if (!value) return '';
        const date = new Date(String(value).replace(/-/g, '/'));
        if (Number.isNaN(date.getTime())) return '';
        const seconds = Math.max(0, (Date.now() - date.getTime()) / 1000);
        if (seconds < 60) return 'now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)}h`;
        return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    function nearBottom() {
        const box = el('messages');
        return !box || box.scrollHeight - box.scrollTop - box.clientHeight < 110;
    }

    function scrollBottom(smooth = false) {
        const box = el('messages');
        if (!box) return;
        box.scrollTo({ top: box.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        hideNewMessages();
    }

    function newMessagesButton() {
        let button = document.getElementById('pfNewMessages');
        if (!button) {
            button = document.createElement('button');
            button.id = 'pfNewMessages';
            button.type = 'button';
            button.className = 'pf-new-messages';
            button.textContent = 'New messages';
            button.addEventListener('click', () => scrollBottom(true));
            (el('chat') || document.body).appendChild(button);
        }
        return button;
    }

    function showNewMessages() { newMessagesButton().style.display = 'block'; }
    function hideNewMessages() { const button = document.getElementById('pfNewMessages'); if (button) button.style.display = 'none'; }

    function createConversationCard(conversation) {
        const card = document.createElement('button');
        card.type = 'button';
        card.className = 'conv-card' + (Number(conversation.order_id) === state.activeId ? ' active' : '');
        const name = isStaff ? (conversation.customer_name || 'Customer') : (conversation.staff_name || 'PrintFlow Team');
        const avatarPath = isStaff ? conversation.customer_avatar : conversation.staff_avatar;

        const avatar = document.createElement('div');
        avatar.className = isStaff ? 'conv-avatar' : 'conv-av';
        if (avatarPath) {
            const image = document.createElement('img');
            image.src = safeUrl(avatarPath);
            image.alt = '';
            image.loading = 'lazy';
            image.addEventListener('error', () => image.remove());
            avatar.appendChild(image);
        }
        if (!avatar.firstChild) avatar.textContent = String(name).trim().charAt(0).toUpperCase() || '?';

        const body = document.createElement('div');
        body.className = 'conv-info';
        const top = document.createElement('div');
        top.className = isStaff ? 'conv-name-row' : 'conv-top';
        const title = document.createElement('span');
        title.className = 'conv-name';
        title.textContent = name;
        const time = document.createElement('span');
        time.className = 'conv-time';
        time.textContent = formatAgo(conversation.last_message_at || conversation.order_date);
        top.append(title, time);
        const bottom = document.createElement('div');
        bottom.className = isStaff ? 'conv-preview' : 'conv-prev';
        const preview = document.createElement('span');
        preview.className = isStaff ? 'conv-preview-text' : '';
        preview.textContent = conversation.last_message || conversation.product_name || 'No messages yet';
        bottom.appendChild(preview);
        if (Number(conversation.has_pinned)) {
            const pin = document.createElement('i');
            pin.className = 'bi bi-pin-angle-fill pf-conv-pin';
            pin.title = 'Has pinned message';
            bottom.appendChild(pin);
        }
        if (Number(conversation.unread_count) > 0) {
            const unread = document.createElement('span');
            unread.className = 'unread-badge';
            unread.textContent = String(conversation.unread_count);
            bottom.appendChild(unread);
        }
        body.append(top, bottom);
        card.append(avatar, body);
        card.addEventListener('click', () => openConversation(conversation));
        return card;
    }

    async function loadConversations(immediate = false) {
        clearTimeout(state.listTimer);
        if (state.listFlight) return scheduleConversationPoll();
        state.listFlight = true;
        const controller = new AbortController();
        state.listAbort = controller;
        try {
            const search = encodeURIComponent((el('search')?.value || '').trim());
            const data = await request(`/public/api/chat/list_conversations.php?q=${search}`, { signal: controller.signal });
            const list = el('list');
            if (!list) return;
            list.replaceChildren();
            const rows = data.conversations || [];
            if (!rows.length) {
                const empty = document.createElement('div');
                empty.className = 'pf-empty';
                empty.textContent = 'No conversations found.';
                list.appendChild(empty);
            } else {
                rows.forEach(row => list.appendChild(createConversationCard(row)));
            }
            if (!state.initialHandled && cfg.initialOrderId) {
                state.initialHandled = true;
                const match = rows.find(row => Number(row.order_id) === Number(cfg.initialOrderId));
                if (match) openConversation(match);
            }
        } catch (error) {
            if (error.name !== 'AbortError' && immediate) toast(error.message);
        } finally {
            if (state.listAbort === controller) state.listAbort = null;
            state.listFlight = false;
            scheduleConversationPoll();
        }
    }

    function scheduleConversationPoll() {
        clearTimeout(state.listTimer);
        state.listTimer = setTimeout(loadConversations, document.hidden ? 30000 : 8000);
    }

    function showConversationThread() {
        if (!isStaff) return;
        const app = document.getElementById('chatApp');
        if (app) app.classList.remove('mobile-list-view');
        if (app) app.classList.add('mobile-thread-view');
        window.staffUiOpened = true;
        const back = document.getElementById('mobileBackBtn');
        if (back) back.style.display = window.matchMedia('(max-width: 767px)').matches ? 'inline-flex' : '';
    }

    function showConversationList() {
        if (!isStaff) return;
        const app = document.getElementById('chatApp');
        if (app) app.classList.remove('mobile-thread-view');
        if (app) app.classList.add('mobile-list-view');
        window.staffUiOpened = false;
    }

    function openConversation(conversation) {
        const orderId = Number(conversation.order_id || conversation.id || conversation);
        if (!orderId) return;
        state.generation += 1;
        state.activeId = orderId;
        state.lastId = 0;
        state.firstId = 0;
        state.hasOlder = false;
        state.messages.clear();
        state.reactions.clear();
        state.reply = null;
        state.files = [];
        state.lastMarkedSeen = 0;
        state.messageAbort?.abort();
        clearTimeout(state.messageTimer);
        cancelReply();
        renderPreviews();

        const name = isStaff ? (conversation.customer_name || conversation.name || 'Customer') : (conversation.staff_name || conversation.name || 'PrintFlow Team');
        const meta = conversation.product_name || conversation.meta || `Order #${orderId}`;
        if (el('name')) el('name').textContent = name;
        if (el('meta')) el('meta').textContent = `${meta} · Order #${orderId}`;
        const avatar = el('avatar');
        const avatarPath = isStaff ? conversation.customer_avatar : conversation.staff_avatar;
        if (avatar) {
            avatar.replaceChildren();
            if (avatarPath) {
                const image = document.createElement('img'); image.src = safeUrl(avatarPath); image.alt = ''; image.loading = 'lazy';
                image.addEventListener('error', () => { avatar.textContent = name.charAt(0).toUpperCase(); });
                avatar.appendChild(image);
            } else avatar.textContent = name.charAt(0).toUpperCase();
        }
        if (el('welcome')) el('welcome').style.display = 'none';
        if (el('chat')) el('chat').style.display = 'flex';
        const box = el('messages');
        if (box) { box.classList.add('pf-chat-messages'); box.replaceChildren(); }
        if (isStaff) showConversationThread(); else if (window.matchMedia('(max-width: 768px)').matches) document.getElementById('chat-root')?.classList.add('chat-open');
        loadMessages('initial', true);
    }

    function messageSummary(message) {
        if (message.message) return String(message.message).slice(0, 160);
        if (message.message_type === 'image') return 'Photo';
        if (message.message_type === 'voice') return 'Voice message';
        if (message.message_type === 'video') return 'Video';
        return 'Message';
    }

    function createReplyQuote(message) {
        if (!message.reply_id) return null;
        const quote = document.createElement('button');
        quote.type = 'button';
        quote.className = 'pf-reply-quote';
        const label = document.createElement('span'); label.className = 'pf-reply-label'; label.textContent = 'Replying to a message';
        const text = document.createElement('span'); text.className = 'pf-reply-text'; text.textContent = message.reply_message || (message.reply_image ? 'Photo' : 'Original message');
        quote.append(label, text);
        quote.addEventListener('click', () => goToMessage(Number(message.reply_id)));
        return quote;
    }

    function createMedia(message) {
        const type = String(message.message_type || message.file_type || '').toLowerCase();
        const source = safeUrl(message.image_path || message.message_file || message.file_path);
        if (type === 'image' && source) {
            const image = document.createElement('img');
            image.className = 'pf-message-image'; image.src = source; image.alt = message.file_name || 'Chat image'; image.loading = 'lazy'; image.decoding = 'async';
            image.addEventListener('click', () => zoomImg(source));
            return image;
        }
        if (type === 'voice' && source) {
            const audio = document.createElement('audio'); audio.className = 'pf-history-media'; audio.controls = true; audio.preload = 'metadata'; audio.src = source; return audio;
        }
        if (type === 'video' && source) {
            const video = document.createElement('video'); video.className = 'pf-history-media'; video.controls = true; video.preload = 'metadata'; video.src = source; return video;
        }
        return null;
    }

    function actionButton(icon, title, handler) {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'pf-action'; button.title = title;
        const i = document.createElement('i'); i.className = `bi ${icon}`; button.appendChild(i); button.addEventListener('click', handler); return button;
    }

    function createMessageRow(message) {
        const row = document.createElement('div');
        row.className = `pf-message-row ${message.is_self ? 'self' : 'other'}`;
        row.id = `message-${message.id}`;
        row.dataset.messageId = String(message.id);
        const stack = document.createElement('div'); stack.className = 'pf-message-stack';
        const bubble = document.createElement('div'); bubble.className = 'pf-message-bubble';
        const quote = createReplyQuote(message); if (quote) bubble.appendChild(quote);
        const media = createMedia(message); if (media) bubble.appendChild(media);
        if (message.message) {
            const text = document.createElement('div'); text.className = 'pf-message-text';
            text.textContent = String(message.message_type) === 'order_update' && String(message.message).trim().startsWith('{') ? 'Order update' : String(message.message);
            bubble.appendChild(text);
        }
        if (message.is_pinned) {
            const pin = document.createElement('i'); pin.className = 'bi bi-pin-angle-fill'; pin.title = 'Pinned'; pin.style.cssText = 'position:absolute;top:-7px;right:-7px;color:#ef4444;background:#fff;border-radius:50%;padding:3px;'; bubble.appendChild(pin);
        }
        const reactions = document.createElement('div'); reactions.className = 'pf-reactions'; reactions.dataset.reactionsFor = String(message.id);
        const time = document.createElement('div'); time.className = 'pf-message-time'; time.textContent = message.created_at || '';
        const seen = document.createElement('div'); seen.className = 'pf-seen'; seen.dataset.seenFor = String(message.id);
        const actions = document.createElement('div'); actions.className = 'pf-message-actions';
        actions.append(
            actionButton('bi-reply', 'Reply', () => beginReply(message)),
            actionButton('bi-emoji-smile', 'React', event => openReactionPicker(message.id, event.currentTarget)),
            actionButton('bi-pin-angle', message.is_pinned ? 'Unpin' : 'Pin', () => pinMessage(message.id))
        );
        stack.append(bubble, reactions, time, seen, actions);
        row.appendChild(stack);

        let hold;
        const start = () => { hold = setTimeout(() => row.classList.add('actions-open'), 450); };
        const stop = () => clearTimeout(hold);
        bubble.addEventListener('touchstart', start, { passive: true });
        bubble.addEventListener('touchend', stop); bubble.addEventListener('touchmove', stop); bubble.addEventListener('touchcancel', stop);
        return row;
    }

    function renderReactionState() {
        document.querySelectorAll('[data-reactions-for]').forEach(container => {
            const messageId = Number(container.dataset.reactionsFor);
            container.replaceChildren();
            const grouped = new Map();
            (state.reactions.get(messageId) || []).forEach(reaction => grouped.set(reaction.reaction_type, (grouped.get(reaction.reaction_type) || 0) + 1));
            grouped.forEach((count, type) => {
                const chip = document.createElement('button'); chip.type = 'button'; chip.className = 'pf-reaction-chip'; chip.textContent = `${reactionEmoji[type] || ''}${count > 1 ? ` ${count}` : ''}`;
                chip.addEventListener('click', () => reactMessage(messageId, type)); container.appendChild(chip);
            });
        });
    }

    function renderSeen(lastSeenId) {
        document.querySelectorAll('.pf-seen').forEach(node => { node.textContent = ''; });
        const outgoing = [...state.messages.values()].filter(message => message.is_self && Number(message.id) <= Number(lastSeenId));
        const latest = outgoing[outgoing.length - 1];
        if (latest) {
            const node = document.querySelector(`[data-seen-for="${latest.id}"]`);
            if (node) node.textContent = 'Seen';
        }
    }

    function updatePinned(messages) {
        state.pinned = messages || [];
        const bar = el('pinned'); const text = el('pinnedText');
        if (!bar || !text) return;
        if (!state.pinned.length) { bar.style.display = 'none'; bar.onclick = null; return; }
        bar.style.display = 'flex';
        const latest = state.pinned[0];
        text.textContent = `Pinned: ${messageSummary(latest)}`;
        text.classList.add('pf-pinned-summary');
        bar.onclick = () => goToMessage(Number(latest.id));
    }

    function mergeResponse(data, mode) {
        const box = el('messages'); if (!box) return;
        const wasNearBottom = nearBottom();
        const oldHeight = box.scrollHeight;
        const fragment = document.createDocumentFragment();
        const incoming = data.messages || [];
        incoming.forEach(message => {
            const id = Number(message.id);
            if (!id || state.messages.has(id)) return;
            state.messages.set(id, message);
            fragment.appendChild(createMessageRow(message));
        });
        if (mode === 'older') box.prepend(fragment); else box.appendChild(fragment);
        const idsLoaded = [...state.messages.keys()].sort((a, b) => a - b);
        state.firstId = idsLoaded[0] || 0;
        state.lastId = idsLoaded[idsLoaded.length - 1] || state.lastId;
        state.hasOlder = Boolean(data.pagination?.has_more_older);

        const reactionMap = new Map();
        (data.reaction_message_ids || []).forEach(id => state.reactions.delete(Number(id)));
        (data.reactions || []).forEach(reaction => {
            const id = Number(reaction.message_id);
            if (!reactionMap.has(id)) reactionMap.set(id, []);
            reactionMap.get(id).push(reaction);
        });
        reactionMap.forEach((value, key) => state.reactions.set(key, value));
        renderReactionState();
        updatePinned(data.pinned_messages || []);
        renderSeen(data.last_seen_message_id);
        if (el('online')) el('online').style.display = data.partner?.is_online ? 'inline-block' : 'none';

        if (mode === 'initial') requestAnimationFrame(() => scrollBottom(false));
        else if (mode === 'older') box.scrollTop = box.scrollHeight - oldHeight;
        else if (incoming.length && wasNearBottom) requestAnimationFrame(() => scrollBottom(true));
        else if (incoming.length) showNewMessages();
        if (state.lastId) markSeen(state.lastId);
    }

    async function loadMessages(mode = 'incremental', notifyError = false) {
        clearTimeout(state.messageTimer);
        if (!state.activeId || state.messageFlight) return scheduleMessagePoll();
        if (mode === 'older' && (!state.hasOlder || state.loadingOlder)) return;
        const generation = state.generation;
        const conversationId = state.activeId;
        const controller = new AbortController();
        state.messageAbort = controller;
        state.messageFlight = true;
        if (mode === 'older') state.loadingOlder = true;
        const query = new URLSearchParams({ order_id: String(conversationId), limit: '40' });
        if (mode === 'incremental' && state.lastId) query.set('after_id', String(state.lastId));
        if (mode === 'older' && state.firstId) query.set('before_id', String(state.firstId));
        try {
            const data = await request(`/public/api/chat/fetch_messages.php?${query}`, { signal: controller.signal });
            if (generation !== state.generation || conversationId !== state.activeId) return;
            mergeResponse(data, mode);
        } catch (error) {
            if (error.name !== 'AbortError' && notifyError) toast(error.message);
        } finally {
            if (state.messageAbort === controller) state.messageAbort = null;
            state.messageFlight = false;
            state.loadingOlder = false;
            scheduleMessagePoll();
        }
    }

    function scheduleMessagePoll() {
        clearTimeout(state.messageTimer);
        if (!state.activeId) return;
        state.messageTimer = setTimeout(() => loadMessages('incremental'), document.hidden ? 15000 : 3000);
    }

    async function markSeen(upToId) {
        if (document.hidden || state.seenFlight || upToId <= state.lastMarkedSeen || !state.activeId) return;
        state.seenFlight = true;
        const conversationId = state.activeId;
        const body = new FormData(); body.append('order_id', String(conversationId)); body.append('up_to_id', String(upToId));
        try {
            await request('/public/api/chat/mark_seen.php', { method: 'POST', body });
            if (conversationId === state.activeId) state.lastMarkedSeen = upToId;
        } catch (_) { /* next visible poll retries; no noisy console loop */ }
        finally { state.seenFlight = false; }
    }

    function beginReply(message) {
        state.reply = { id: Number(message.id), summary: messageSummary(message) };
        const box = el('reply'); const text = el('replyText');
        if (text) text.textContent = state.reply.summary;
        if (box) box.style.display = 'flex';
        el('input')?.focus();
    }

    function cancelReply() {
        state.reply = null;
        const box = el('reply'); if (box) box.style.display = 'none';
    }

    function renderPreviews() {
        const preview = el('preview'); if (!preview) return;
        preview.replaceChildren();
        preview.style.display = state.files.length ? 'flex' : 'none';
        state.files.forEach((file, index) => {
            const wrap = document.createElement('div'); wrap.className = 'pf-image-preview';
            const image = document.createElement('img'); image.src = URL.createObjectURL(file); image.alt = file.name;
            image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'pf-image-remove'; remove.textContent = '×'; remove.title = 'Remove image';
            remove.addEventListener('click', () => { state.files.splice(index, 1); renderPreviews(); });
            wrap.append(image, remove); preview.appendChild(wrap);
        });
    }

    function handleFiles(fileList) {
        const candidates = [...fileList];
        if (candidates.length > 4) return toast('Choose up to 4 images at a time.');
        for (const file of candidates) {
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return toast('Only JPG, PNG, and WebP images are allowed.');
            if (file.size > 8 * 1024 * 1024) return toast('Each image must be 8 MB or smaller.');
        }
        state.files = candidates; renderPreviews();
    }

    function clientToken() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }

    async function sendMessage() {
        const input = el('input'); const button = el('send');
        const text = (input?.value || '').trim();
        if (!state.activeId || state.sendFlight || (!text && !state.files.length)) return;
        state.sendFlight = true;
        if (button) button.disabled = true;
        const body = new FormData();
        body.append('order_id', String(state.activeId)); body.append('client_token', clientToken());
        if (text) body.append('message', text);
        if (state.reply) body.append('reply_id', String(state.reply.id));
        state.files.forEach(file => body.append('image[]', file));
        try {
            await request('/public/api/chat/send_message.php', { method: 'POST', body });
            if (input) { input.value = ''; input.style.height = 'auto'; }
            state.files = []; renderPreviews(); cancelReply();
            await loadMessages('incremental', true);
            loadConversations();
        } catch (error) { toast(error.message); }
        finally { state.sendFlight = false; if (button) button.disabled = false; input?.focus(); }
    }

    function closePicker() { document.querySelectorAll('.pf-reaction-picker').forEach(node => node.remove()); }
    function openReactionPicker(messageId, trigger) {
        closePicker();
        const picker = document.createElement('div'); picker.className = 'pf-reaction-picker open';
        Object.entries(reactionEmoji).forEach(([type, emoji]) => {
            const button = document.createElement('button'); button.type = 'button'; button.textContent = emoji; button.title = type;
            button.addEventListener('click', () => { closePicker(); reactMessage(messageId, type); }); picker.appendChild(button);
        });
        document.body.appendChild(picker);
        const rect = trigger.getBoundingClientRect(); const width = picker.offsetWidth;
        picker.style.left = `${Math.max(10, Math.min(window.innerWidth - width - 10, rect.left + rect.width / 2 - width / 2))}px`;
        picker.style.top = `${Math.max(10, rect.top - picker.offsetHeight - 8)}px`;
    }

    async function reactMessage(messageId, type) {
        const body = new FormData(); body.append('message_id', String(messageId)); body.append('reaction_type', type);
        try { await request('/public/api/chat/react_message.php', { method: 'POST', body }); await loadMessages('incremental'); }
        catch (error) { toast(error.message); }
    }

    async function pinMessage(messageId) {
        const body = new FormData(); body.append('message_id', String(messageId));
        try { await request('/public/api/chat/pin_message.php', { method: 'POST', body }); await refreshCurrentWindow(); }
        catch (error) { toast(error.message); }
    }

    async function refreshCurrentWindow() {
        if (!state.activeId) return;
        const generation = state.generation; const conversationId = state.activeId;
        try {
            const data = await request(`/public/api/chat/fetch_messages.php?order_id=${conversationId}&limit=50`);
            if (generation !== state.generation || conversationId !== state.activeId) return;
            (data.messages || []).forEach(message => {
                state.messages.set(Number(message.id), message);
                const existing = document.getElementById(`message-${message.id}`);
                if (existing) existing.replaceWith(createMessageRow(message));
            });
            mergeResponse({ ...data, messages: [] }, 'incremental');
        } catch (error) { toast(error.message); }
    }

    async function goToMessage(messageId) {
        let target = document.getElementById(`message-${messageId}`);
        let attempts = 0;
        while (!target && state.hasOlder && attempts < 12) {
            await loadMessages('older', true); attempts += 1; target = document.getElementById(`message-${messageId}`);
        }
        if (!target) return toast('The referenced message is outside the available history.');
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.animate([{ backgroundColor: 'rgba(14,165,233,.25)' }, { backgroundColor: 'transparent' }], { duration: 1200 });
    }

    async function openGallery() {
        if (!state.activeId) return;
        try {
            const data = await request(`/public/api/chat/fetch_media.php?order_id=${state.activeId}`);
            const grid = el('galleryGrid'); if (!grid) return;
            grid.replaceChildren();
            (data.media || []).forEach(item => {
                const wrap = document.createElement('div'); wrap.className = isStaff ? 'gallery-item' : 'gal-item';
                const image = document.createElement('img'); image.src = safeUrl(item.message_file); image.alt = 'Shared image'; image.loading = 'lazy';
                image.addEventListener('click', () => zoomImg(image.src)); wrap.appendChild(image); grid.appendChild(wrap);
            });
            if (el('gallery')) el('gallery').classList.add(isStaff ? 'active' : 'show');
        } catch (error) { toast(error.message); }
    }

    function closeGallery() { const gallery = el('gallery'); gallery?.classList.remove('show', 'active'); }
    function zoomImg(source) {
        const lightbox = el('lightbox'); const image = el('lightboxImage'); const download = el('lightboxDownload');
        if (image) { image.src = source; image.style.display = 'block'; }
        if (download) download.href = source;
        if (lightbox) lightbox.style.display = 'flex';
    }
    function closeLightbox() { const lightbox = el('lightbox'); const image = el('lightboxImage'); if (lightbox) lightbox.style.display = 'none'; if (image) image.src = ''; }

    function closeChatMobile() { document.getElementById('chat-root')?.classList.remove('chat-open'); }
    function toggleMenu(event) { event?.stopPropagation(); document.getElementById(isStaff ? 'chatDropdown' : 'hDropdown')?.classList.toggle('show'); }
    function toggleMediaGallery(show) { if (show) openGallery(); else closeGallery(); }
    function openDetails(orderId) {
        const id = Number(orderId || state.activeId);
        if (!id) return;
        window.location.href = base + (isStaff ? `/staff/order_details.php?id=${id}` : `/customer/orders.php?order_id=${id}`);
    }

    function bind() {
        const messages = el('messages');
        messages?.addEventListener('scroll', () => {
            state.nearBottom = nearBottom();
            if (state.nearBottom) hideNewMessages();
            if (messages.scrollTop < 100 && state.hasOlder) loadMessages('older');
        }, { passive: true });
        el('file')?.addEventListener('change', event => handleFiles(event.target.files || []));
        el('send')?.addEventListener('click', sendMessage);
        el('input')?.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); sendMessage(); }
        });
        el('input')?.addEventListener('input', event => {
            event.target.style.height = 'auto'; event.target.style.height = `${Math.min(120, event.target.scrollHeight)}px`;
            const counter = document.getElementById(isStaff ? 'charCount' : 'customerCharCount'); if (counter) counter.textContent = `${event.target.value.length}/500`;
        });
        let searchTimer;
        el('search')?.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadConversations(true), 300); });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) { loadConversations(); if (state.activeId) loadMessages('incremental'); }
            else { scheduleConversationPoll(); scheduleMessagePoll(); }
        });
        document.addEventListener('click', event => {
            if (!event.target.closest('.pf-reaction-picker,.pf-action')) closePicker();
            if (!event.target.closest('.unified-menu,.h-menu-wrap')) document.getElementById(isStaff ? 'chatDropdown' : 'hDropdown')?.classList.remove('show');
        });
        window.addEventListener('beforeunload', () => { state.messageAbort?.abort(); state.listAbort?.abort(); });
        loadConversations(true);
    }

    window.showConversationList = showConversationList;
    window.showConversationThread = showConversationThread;
    window.closeChatMobile = closeChatMobile;
    window.cancelReply = cancelReply;
    window.sendMsg = sendMessage;
    window.onImgSelected = () => handleFiles(el('file')?.files || []);
    window.openGallery = openGallery;
    window.closeGallery = closeGallery;
    window.toggleMediaGallery = toggleMediaGallery;
    window.switchGalleryTab = () => {};
    window.toggleMenu = toggleMenu;
    window.toggleHMenu = toggleMenu;
    window.zoomImg = zoomImg;
    window.closeLightbox = closeLightbox;
    window.goToMessage = goToMessage;
    window.openDetails = openDetails;
    window.openOrderDetails = openDetails;
    window.openChat = (id, name, meta, avatar) => openConversation({ order_id: id, customer_name: name, staff_name: name, product_name: meta, customer_avatar: avatar, staff_avatar: avatar });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind, { once: true });
    else bind();
})();
