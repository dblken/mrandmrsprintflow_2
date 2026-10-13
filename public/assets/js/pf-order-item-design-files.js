/**
 * Shared helpers for multi-design file display (dedupe, preview cap, metadata).
 */
(function (global) {
    'use strict';

    var PREVIEW_LIMIT = 3;

    function dedupeDesignFiles(raw) {
        if (!Array.isArray(raw)) {
            return [];
        }
        var seen = new Set();
        var out = [];
        raw.forEach(function (entry) {
            if (!entry || typeof entry !== 'object') {
                return;
            }
            var fileId = Number(entry.file_id || 0);
            if (fileId > 0) {
                if (seen.has(fileId)) {
                    return;
                }
                seen.add(fileId);
            }
            out.push(entry);
        });
        out.sort(function (a, b) {
            var sortDelta = Number(a.upload_sort || 0) - Number(b.upload_sort || 0);
            if (sortDelta !== 0) {
                return sortDelta;
            }
            return Number(a.file_id || 0) - Number(b.file_id || 0);
        });
        return out;
    }

    function formatFileSize(bytes) {
        var n = Number(bytes || 0);
        if (!n || n < 0) {
            return '—';
        }
        if (n < 1024) {
            return n + ' B';
        }
        if (n < 1024 * 1024) {
            return (n / 1024).toFixed(n >= 10240 ? 0 : 1) + ' KB';
        }
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function fileTypeLabel(file) {
        if (!file || typeof file !== 'object') {
            return 'File';
        }
        var mime = String(file.mime_type || '').trim();
        if (mime) {
            return mime;
        }
        var name = String(file.display_name || '');
        var dot = name.lastIndexOf('.');
        if (dot > -1 && dot < name.length - 1) {
            return name.slice(dot + 1).toUpperCase();
        }
        return 'File';
    }

    function previewList(files) {
        var list = dedupeDesignFiles(files);
        if (list.length <= PREVIEW_LIMIT) {
            return list;
        }
        return list.slice(0, PREVIEW_LIMIT);
    }

    function hiddenCount(files) {
        var list = dedupeDesignFiles(files);
        return Math.max(0, list.length - PREVIEW_LIMIT);
    }

    function showMoreLabel(files) {
        var n = hiddenCount(files);
        if (n <= 0) {
            return '';
        }
        return 'Show more images (' + n + ' more)';
    }

    function zipDownloadUrl(orderItemId, baseUrl) {
        var id = Number(orderItemId || 0);
        if (id <= 0) {
            return '';
        }
        var base = String(baseUrl || '').replace(/\/$/, '');
        if (global.document && global.document.body) {
            var fromBody = global.document.body.getAttribute('data-base-url');
            if (!base && fromBody) {
                base = String(fromBody).replace(/\/$/, '');
            }
        }
        return base + '/public/download_order_item_design_files_zip.php?order_item_id=' + id;
    }

    function fileUnavailable(file) {
        return file && file.exists === false;
    }

    function ensureGalleryModal() {
        if (!global.document) {
            return null;
        }
        var existing = global.document.getElementById('pf-design-files-gallery-modal');
        if (existing) {
            return existing;
        }
        var wrap = global.document.createElement('div');
        wrap.id = 'pf-design-files-gallery-modal';
        wrap.setAttribute('hidden', 'hidden');
        wrap.innerHTML = ''
            + '<div class="pf-dfg-backdrop" data-pf-dfg-close="1"></div>'
            + '<div class="pf-dfg-panel" role="dialog" aria-modal="true">'
            + '<button type="button" class="pf-dfg-close" data-pf-dfg-close="1" aria-label="Close">&times;</button>'
            + '<h3 class="pf-dfg-title"></h3>'
            + '<div class="pf-dfg-zip-wrap"></div>'
            + '<div class="pf-dfg-list"></div>'
            + '</div>';
        wrap.style.cssText = 'position:fixed;inset:0;z-index:120000;display:none;align-items:flex-start;justify-content:center;padding:4vh 12px 12px;';
        var backdrop = wrap.querySelector('.pf-dfg-backdrop');
        if (backdrop) {
            backdrop.style.cssText = 'position:absolute;inset:0;background:rgba(15,23,42,0.55);';
        }
        var panel = wrap.querySelector('.pf-dfg-panel');
        if (panel) {
            panel.style.cssText = 'position:relative;background:#fff;border-radius:14px;padding:18px 18px 12px;max-height:88vh;overflow:auto;width:min(720px,100%);box-shadow:0 24px 48px rgba(0,0,0,0.25);border:1px solid #e5e7eb;';
        }
        var closeBtn = wrap.querySelector('.pf-dfg-close');
        if (closeBtn) {
            closeBtn.style.cssText = 'position:absolute;top:8px;right:8px;border:none;background:#f3f4f6;width:34px;height:34px;border-radius:50%;cursor:pointer;font-size:22px;line-height:1;';
        }
        global.document.body.appendChild(wrap);
        wrap.addEventListener('click', function (ev) {
            var t = ev.target;
            if (t && t.getAttribute && t.getAttribute('data-pf-dfg-close') === '1') {
                closeGalleryModal();
            }
        });
        return wrap;
    }

    function closeGalleryModal() {
        var modal = global.document && global.document.getElementById('pf-design-files-gallery-modal');
        if (!modal) {
            return;
        }
        modal.style.display = 'none';
        modal.setAttribute('hidden', 'hidden');
    }

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function openGalleryModal(payload) {
        if (!global.document) {
            return;
        }
        var data = payload && typeof payload === 'object' ? payload : {};
        var files = dedupeDesignFiles(data.files || []);
        var modal = ensureGalleryModal();
        if (!modal) {
            return;
        }
        var titleEl = modal.querySelector('.pf-dfg-title');
        if (titleEl) {
            titleEl.textContent = String(data.title || 'Uploaded designs');
            titleEl.style.cssText = 'margin:0 36px 12px 0;font-size:16px;font-weight:700;color:#1f2937;';
        }
        var zipWrap = modal.querySelector('.pf-dfg-zip-wrap');
        if (zipWrap) {
            var orderItemId = Number(data.orderItemId || 0);
            var zipUrl = orderItemId > 0 ? zipDownloadUrl(orderItemId, data.baseUrl || '') : '';
            zipWrap.innerHTML = files.length > 1 && zipUrl
                ? '<a href="' + escapeHtml(zipUrl) + '" style="display:inline-flex;margin-bottom:12px;font-size:12px;font-weight:700;color:#4f46e5;">Download all (ZIP)</a>'
                : '';
        }
        var list = modal.querySelector('.pf-dfg-list');
        if (list) {
            list.innerHTML = files.map(function (file) {
                var name = escapeHtml(file.display_name || 'Uploaded design');
                var meta = escapeHtml(fileTypeLabel(file)) + (file.file_size ? ' · ' + escapeHtml(formatFileSize(file.file_size)) : '');
                if (fileUnavailable(file)) {
                    return '<div style="margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;">'
                        + '<div style="font-weight:600;margin-bottom:4px;">' + name + '</div>'
                        + '<div style="font-size:11px;color:#6b7280;margin-bottom:8px;">' + meta + '</div>'
                        + '<div style="padding:10px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:12px;font-weight:600;">File unavailable</div>'
                        + '</div>';
                }
                var url = String(file.preview_url || file.download_url || '');
                var img = file.is_previewable_image && url
                    ? '<img src="' + escapeHtml(url) + '" alt="" style="max-width:280px;max-height:220px;object-fit:contain;border-radius:8px;border:1px solid #e2e8f0;margin:8px 0;display:block;">'
                    : '';
                var dl = url ? '<a href="' + escapeHtml(url) + '" download target="_blank" rel="noopener noreferrer" style="font-size:12px;font-weight:700;color:#047676;margin-right:10px;">Download</a>' : '';
                return '<div style="margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;">'
                    + '<div style="font-weight:600;margin-bottom:4px;">' + name + '</div>'
                    + '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;">' + meta + '</div>'
                    + img + '<div>' + dl + '</div></div>';
            }).join('');
        }
        modal.style.display = 'flex';
        modal.removeAttribute('hidden');
    }

    function bindDesignGalleryButtons(root) {
        if (!global.document) {
            return;
        }
        var scope = root && root.querySelectorAll ? root : global.document;
        scope.querySelectorAll('[data-pf-design-gallery]').forEach(function (btn) {
            if (btn.dataset.pfDesignGalleryBound === '1') {
                return;
            }
            btn.dataset.pfDesignGalleryBound = '1';
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                try {
                    var raw = btn.getAttribute('data-pf-design-gallery') || '{}';
                    var payload = JSON.parse(raw);
                    openGalleryModal(payload);
                } catch (e) {
                    /* ignore */
                }
            });
        });
    }

    if (global.document && global.document.addEventListener) {
        global.document.addEventListener('DOMContentLoaded', function () {
            bindDesignGalleryButtons(global.document);
        });
    }

    global.PrintflowOrderItemDesignFiles = {
        PREVIEW_LIMIT: PREVIEW_LIMIT,
        dedupe: dedupeDesignFiles,
        formatFileSize: formatFileSize,
        fileTypeLabel: fileTypeLabel,
        previewList: previewList,
        hiddenCount: hiddenCount,
        showMoreLabel: showMoreLabel,
        zipDownloadUrl: zipDownloadUrl,
        fileUnavailable: fileUnavailable,
        openGalleryModal: openGalleryModal,
        closeGalleryModal: closeGalleryModal,
        bindDesignGalleryButtons: bindDesignGalleryButtons,
    };
})(typeof window !== 'undefined' ? window : globalThis);
