/**
 * Service Form Validation - Real-time validation for Add/Edit Service modal
 * PrintFlow - Admin Services Management
 */
(function() {
    const ERRORS = {
        nameRequired: 'Service name is required.',
        nameMinLength: 'Service name must be at least 2 characters.',
        nameMaxLength: 'Service name must not exceed 150 characters.',
        nameLeadingSpace: 'Leading spaces are not allowed.',
        categoryRequired: 'Please select a category.',
        descriptionRequired: 'Description is required.',
        descriptionMax: 'Description must not exceed 2000 characters.',
        photosRequired: 'Please provide at least one service photo.',
        statusRequired: 'Please select a status.'
    };

    let touchedFields = new Set();
    let submitValidationActive = false;

    function el(id) { return document.getElementById(id); }

    function isCreateMode() {
        return el('modal-mode-input')?.name === 'create_service';
    }

    function isEditMode() {
        return el('modal-mode-input')?.name === 'update_service';
    }

    function showError(fieldId, msg, forceShow) {
        const shouldShow = !!msg && (forceShow || submitValidationActive || touchedFields.has(fieldId));
        if (fieldId === 'photos') {
            const err = el('err-photos');
            const group = el('fg-photos');
            if (err) {
                err.textContent = msg || '';
                err.style.display = shouldShow ? 'block' : 'none';
            }
            if (group) group.classList.toggle('has-error', shouldShow);
            return;
        }

        const inp = el('modal-' + fieldId);
        const err = el('err-' + fieldId);
        const group = inp?.closest('.form-group');
        if (err) {
            err.textContent = msg || '';
            err.style.display = shouldShow ? 'block' : 'none';
        }
        if (group) {
            const valForSuccess = (inp && inp.type !== 'file') ? (inp.value || '').trim() : '';
            group.classList.toggle('has-error', shouldShow);
            group.classList.toggle('has-success', !msg && (forceShow || touchedFields.has(fieldId)) && !!valForSuccess);
        }
    }

    function clearAllServiceErrors() {
        document.querySelectorAll('#service-form .field-error').forEach(function(errEl) {
            errEl.textContent = '';
            errEl.style.display = 'none';
        });
        document.querySelectorAll('#service-form .form-group.has-error').forEach(function(groupEl) {
            groupEl.classList.remove('has-error');
        });
    }

    function markAllFieldsTouched() {
        ['name', 'category', 'description', 'photos'].forEach(function(k) { touchedFields.add(k); });
        if (isEditMode()) touchedFields.add('status');
    }

    function getVal(fieldId) {
        return (el('modal-' + fieldId)?.value || '').trim();
    }

    function formatServiceName(val) {
        if (!val) return '';
        val = val.replace(/\s+/g, ' ');
        if (val.startsWith(' ')) val = val.trimStart();
        return val.replace(/\b\w/g, function(c) { return c.toUpperCase(); });
    }

    function validateName() {
        const raw = (el('modal-name')?.value || '');
        const val = raw.trim();
        if (val.startsWith(' ') || raw !== val && raw.startsWith(' ')) return ERRORS.nameLeadingSpace;
        if (!val) return ERRORS.nameRequired;
        if (val.length < 2) return ERRORS.nameMinLength;
        if (val.length > 150) return ERRORS.nameMaxLength;
        return '';
    }

    function validateCategory() {
        const v = getVal('category');
        if (!v || v === '-- Select Category --') return ERRORS.categoryRequired;
        return '';
    }

    function validateDescription() {
        const raw = (el('modal-description')?.value || '');
        const val = raw.trim();
        if (!val) return ERRORS.descriptionRequired;
        if (val.length > 2000) return ERRORS.descriptionMax;
        return '';
    }

    function validatePhotos() {
        const existingPhotos = (el('modal-display-image')?.value || '')
            .split(',')
            .map(function(v) { return v.trim(); })
            .filter(Boolean);
        const stagedCount = (typeof window.uploadedPhotoFiles !== 'undefined' && Array.isArray(window.uploadedPhotoFiles))
            ? window.uploadedPhotoFiles.length
            : 0;
        const inputCount = el('modal-photo-files')?.files?.length || 0;
        if (existingPhotos.length === 0 && stagedCount === 0 && inputCount === 0) {
            return ERRORS.photosRequired;
        }
        return '';
    }

    function validateStatus() {
        if (isCreateMode()) return '';
        const v = getVal('status');
        if (!v) return ERRORS.statusRequired;
        return '';
    }

    function runValidation(forceShow) {
        const errors = {
            name: validateName(),
            category: validateCategory(),
            description: validateDescription(),
            photos: validatePhotos(),
            status: validateStatus()
        };
        Object.keys(errors).forEach(function(k) { showError(k, errors[k], forceShow); });
        return Object.values(errors).every(function(e) { return !e; });
    }

    function validateAllFields(forceShow) {
        if (forceShow) {
            markAllFieldsTouched();
            clearAllServiceErrors();
        }
        return runValidation(!!forceShow);
    }

    function scrollToFirstServiceError() {
        var first = document.querySelector('#service-form .form-group.has-error')
            || document.querySelector('#service-form .field-error[style*="block"]');
        if (first) {
            (first.closest('.form-group') || first).scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function handleServiceFormSubmit(e) {
        submitValidationActive = true;
        try {
            if (typeof window.pfStageServiceMediaForSubmit === 'function') {
                window.pfStageServiceMediaForSubmit();
            }
            if (!validateAllFields(true)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                scrollToFirstServiceError();
                return;
            }
        } finally {
            submitValidationActive = false;
        }
    }

    function setupServiceNameInput() {
        const inp = el('modal-name');
        if (!inp) return;
        inp.addEventListener('input', function() {
            let v = this.value;
            if (v.startsWith(' ')) v = v.trimStart();
            v = v.replace(/\s+/g, ' ');
            v = formatServiceName(v);
            if (v !== this.value) this.value = v;
            runValidation(false);
        });
        inp.addEventListener('keydown', function(e) {
            if (e.key === ' ' && (this.selectionStart === 0 || this.value.trim() === '' && this.value === ' ')) {
                e.preventDefault();
            }
        });
        inp.addEventListener('blur', function() {
            this.value = formatServiceName(this.value);
            if (submitValidationActive) return;
            touchedFields.add('name');
            showError('name', validateName(), false);
        });
    }

    function setupDescriptionInput() {
        const inp = el('modal-description');
        if (!inp) return;
        inp.addEventListener('input', function() {
            touchedFields.add('description');
            runValidation(false);
        });
        inp.addEventListener('blur', function() {
            if (submitValidationActive) return;
            touchedFields.add('description');
            showError('description', validateDescription(), false);
        });
    }

    function setupValidation() {
        ['modal-name', 'modal-category', 'modal-status'].forEach(function(id) {
            const elm = el(id);
            if (elm) {
                elm.addEventListener('input', function() {
                    touchedFields.add(id.replace('modal-', ''));
                    runValidation(false);
                });
                elm.addEventListener('change', function() {
                    touchedFields.add(id.replace('modal-', ''));
                    runValidation(false);
                });
                elm.addEventListener('blur', function() {
                    if (submitValidationActive) return;
                    touchedFields.add(id.replace('modal-', ''));
                    runValidation(false);
                });
            }
        });

        setupServiceNameInput();
        setupDescriptionInput();
    }

    function setServiceModalMode(mode) {
        const modal = el('service-modal');
        const statusGroup = el('fg-modal-status');
        const statusSelect = el('modal-status');
        const isCreate = mode === 'create';
        if (modal) modal.classList.toggle('service-modal--create', isCreate);
        if (statusGroup) statusGroup.style.display = isCreate ? 'none' : '';
        if (statusSelect && isCreate) statusSelect.value = 'Activated';
    }

    function initServiceFormValidation() {
        const form = document.getElementById('service-form');
        if (!form) {
            window.printflowServiceFormValidationRun = function() {};
            window.pfSetServiceModalMode = function() {};
            return;
        }

        if (form.getAttribute('data-pf-service-validation') !== '1') {
            form.setAttribute('data-pf-service-validation', '1');

            form.addEventListener('mousedown', function(e) {
                if (e.target.closest('button[type="submit"], .btn-save')) {
                    submitValidationActive = true;
                }
            }, true);

            form.addEventListener('submit', handleServiceFormSubmit, true);
            setupValidation();
        }

        window.printflowServiceFormValidationRun = validateAllFields;
        window.pfSetServiceModalMode = setServiceModalMode;
        validateAllFields(false);
    }

    document.addEventListener('pf-service-modal-shown', function() {
        submitValidationActive = false;
        touchedFields.clear();
        clearAllServiceErrors();
        validateAllFields(false);
        const btn = el('modal-submit-btn');
        if (btn) {
            btn.disabled = false;
            btn.removeAttribute('disabled');
        }
    });

    function bootServiceFormValidation() {
        initServiceFormValidation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootServiceFormValidation);
    } else {
        bootServiceFormValidation();
    }
    document.addEventListener('printflow:page-init', bootServiceFormValidation);
})();
