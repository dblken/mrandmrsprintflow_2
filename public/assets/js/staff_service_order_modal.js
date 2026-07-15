/**
 * Shared Alpine state + actions for staff service order detail modal.
 * Pages merge via spread: { ...printflowStaffServiceOrderModalMixin({ afterSvcMutation }) }.
 */
(function exposeStaffServiceOrderModalMixin(global) {
    function printflowStaffServiceOrderModalMixin(opts) {
        opts = opts || {};

        async function parseServiceOrderResponse(response, label) {
            var contentType = response.headers.get('content-type') || '';
            var text = await response.text();

            if (!response.ok) {
                console.error('[Customizations] ' + label + ' failed', {
                    status: response.status,
                    body: text.slice(0, 500)
                });
                return { success: false, error: label + ' failed: HTTP ' + response.status };
            }

            if (!contentType.includes('application/json')) {
                console.error('[Customizations] ' + label + ' returned non-JSON', text.slice(0, 500));
                return { success: false, error: label + ' returned a non-JSON response.' };
            }

            try {
                return JSON.parse(text);
            } catch (error) {
                console.error('[Customizations] ' + label + ' returned invalid JSON', error, text.slice(0, 500));
                return { success: false, error: label + ' returned invalid JSON.' };
            }
        }

        async function postOp(ctx, body) {
            var base = document.body.getAttribute('data-base-url') || '/printflow';
            var csrf = document.body.getAttribute('data-csrf') || '';
            body.csrf_token = csrf;
            var response = await fetch(base + '/staff/api/service_order_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            return parseServiceOrderResponse(response, 'Service order action');
        }

        return {
            showSvcModal: false,
            svcLoading: false,
            svcErr: '',
            svc: {},
            placementOpen: false,
            placementSrc: '',
            placementTitle: '',

            openSvcModal: async function (id) {
                if (!id) return;
                this.showSvcModal = true;
                this.svcLoading = true;
                this.svcErr = '';
                this.svc = {};
                document.body.style.overflow = 'hidden';
                var base = document.body.getAttribute('data-base-url') || '/printflow';
                try {
                    var response = await fetch(
                        base + '/staff/api/service_order_api.php?action=detail&id=' + encodeURIComponent(id),
                        { headers: { 'Accept': 'application/json' } }
                    );
                    var payload = await parseServiceOrderResponse(response, 'Service order detail');
                    if (!payload.success) {
                        this.svcErr = payload.error || 'Failed to load';
                        this.svcLoading = false;
                        return;
                    }
                    this.svc = payload.data;
                } catch (e) {
                    console.error('[Customizations] Service order detail request failed', e);
                    this.svcErr = 'Could not reach server';
                }
                this.svcLoading = false;
            },

            closeSvcModal: function () {
                this.showSvcModal = false;
                this.placementOpen = false;
                this.svc = {};
                this.svcErr = '';
                this.placementSrc = '';
                document.body.style.overflow = '';
            },

            svcApprove: async function () {
                if (!this.svc || !this.svc.id) return;
                if (!confirm('Approve this service order and start production?\n\nThis will deduct materials from inventory.')) return;
                var j = await postOp(this, { order_id: this.svc.id, op: 'approve' });
                if (!j.success) {
                    alert(j.error || 'Failed');
                    return;
                }
                this.svc = j.data;
                if (opts.afterSvcMutation) await opts.afterSvcMutation.call(this);
            },

            svcReject: async function () {
                if (!this.svc || !this.svc.id || !confirm('Request revision / reject this order?')) return;
                var j = await postOp(this, { order_id: this.svc.id, op: 'reject' });
                if (!j.success) {
                    alert(j.error || 'Failed');
                    return;
                }
                this.svc = j.data;
                if (opts.afterSvcMutation) await opts.afterSvcMutation.call(this);
            },

            svcCancelOrder: async function () {
                if (!this.svc || !this.svc.id || !confirm('Cancel this service order? It will be marked rejected.')) return;
                var j = await postOp(this, { order_id: this.svc.id, op: 'reject' });
                if (!j.success) {
                    alert(j.error || 'Failed');
                    return;
                }
                this.svc = j.data;
                if (opts.afterSvcMutation) await opts.afterSvcMutation.call(this);
            },

            svcOpenPlacement: function (url, title) {
                this.placementSrc = url || '';
                this.placementTitle = title || '';
                this.placementOpen = true;
            },

            closePlacement: function () {
                this.placementOpen = false;
                this.placementSrc = '';
            },

            onSvcEscape: function () {
                if (this.placementOpen) {
                    this.closePlacement();
                } else if (this.showSvcModal) {
                    this.closeSvcModal();
                }
            },
        };
    }

    global.printflowStaffServiceOrderModalMixin = printflowStaffServiceOrderModalMixin;
})(window);
