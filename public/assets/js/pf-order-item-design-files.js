/**
 * Shared helpers for multi-design file display (dedupe, preview cap, expand/collapse grid).
 */
(function (global) {
    'use strict';

    var PREVIEW_LIMIT = 3;

    function designFileIdentity(entry) {
        if (!entry || typeof entry !== 'object') {
            return '';
        }
        var fileId = entry.file_id;
        if (fileId != null && String(fileId).trim() !== '' && String(fileId) !== '0') {
            return String(fileId);
        }
        return '';
    }

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
            var identity = designFileIdentity(entry);
            if (identity !== '') {
                if (seen.has(identity)) {
                    return;
                }
                seen.add(identity);
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
        var hasDbIds = out.some(function (entry) {
            return Number(entry && entry.file_id) > 0;
        });
        if (hasDbIds) {
            out = out.filter(function (entry) {
                return Number(entry && entry.file_id) > 0;
            });
        }
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

    function fileIdsMatch(a, b) {
        return String(a && a.file_id != null ? a.file_id : '') === String(b && b.file_id != null ? b.file_id : '');
    }

    function isInPreviewList(file, allFiles) {
        var preview = previewList(allFiles);
        for (var i = 0; i < preview.length; i++) {
            if (fileIdsMatch(preview[i], file)) {
                return true;
            }
        }
        return false;
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

    function setBlockExpanded(block, expanded) {
        if (!block) {
            return;
        }
        var moreBtn = block.querySelector('[data-pf-design-more]');
        var lessBtn = block.querySelector('[data-pf-design-less]');
        if (expanded) {
            block.classList.add('is-expanded');
            if (moreBtn) {
                moreBtn.hidden = true;
            }
            if (lessBtn) {
                lessBtn.hidden = false;
            }
            block.querySelectorAll('[data-pf-design-collapsible]').forEach(function (card) {
                card.hidden = false;
            });
        } else {
            block.classList.remove('is-expanded');
            if (moreBtn) {
                moreBtn.hidden = false;
            }
            if (lessBtn) {
                lessBtn.hidden = true;
            }
            block.querySelectorAll('[data-pf-design-collapsible]').forEach(function (card) {
                card.hidden = true;
            });
        }
    }

    function bindDesignFilesBlocks(root) {
        if (!global.document) {
            return;
        }
        var scope = root && root.querySelectorAll ? root : global.document;
        scope.querySelectorAll('[data-pf-design-files]').forEach(function (block) {
            if (block.dataset.pfDesignFilesBound === '1') {
                return;
            }
            block.dataset.pfDesignFilesBound = '1';
            var moreBtn = block.querySelector('[data-pf-design-more]');
            var lessBtn = block.querySelector('[data-pf-design-less]');
            if (moreBtn) {
                moreBtn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    setBlockExpanded(block, true);
                });
            }
            if (lessBtn) {
                lessBtn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    setBlockExpanded(block, false);
                });
            }
        });
    }

    /** @deprecated use inline expand via bindDesignFilesBlocks */
    function bindDesignGalleryButtons(root) {
        bindDesignFilesBlocks(root);
    }

    if (global.document && global.document.addEventListener) {
        global.document.addEventListener('DOMContentLoaded', function () {
            bindDesignFilesBlocks(global.document);
        });
    }

    global.PrintflowOrderItemDesignFiles = {
        PREVIEW_LIMIT: PREVIEW_LIMIT,
        designFileIdentity: designFileIdentity,
        dedupe: dedupeDesignFiles,
        formatFileSize: formatFileSize,
        fileTypeLabel: fileTypeLabel,
        previewList: previewList,
        hiddenCount: hiddenCount,
        showMoreLabel: showMoreLabel,
        fileIdsMatch: fileIdsMatch,
        isInPreviewList: isInPreviewList,
        zipDownloadUrl: zipDownloadUrl,
        fileUnavailable: fileUnavailable,
        bindDesignFilesBlocks: bindDesignFilesBlocks,
        bindDesignGalleryButtons: bindDesignGalleryButtons,
        setBlockExpanded: setBlockExpanded,
    };
})(typeof window !== 'undefined' ? window : globalThis);
