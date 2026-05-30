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
        statusRequired: 'Please select a status.'
    };

    let touchedFields = new Set();

    function el(id) { return document.getElementById(id); }

    function showError(fieldId, msg) {
        const inp = el('modal-' + fieldId);
        const err = el('err-' + fieldId);
        const group = inp?.closest('.form-group');
        if (err) {
            const isTouched = touchedFields.has(fieldId);
            err.textContent = msg || '';
            err.style.display = (msg && isTouched) ? 'block' : 'none';
        }
        if (group) {
            const isTouched = touchedFields.has(fieldId);
            var valForSuccess = (inp?.value || '').trim();
            group.classList.toggle('has-error', !!msg && isTouched);
            group.classList.toggle('has-success', !msg && isTouched && !!valForSuccess);
        }
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

    function validateStatus() {
        const v = getVal('status');
        if (!v) return ERRORS.statusRequired;
        return '';
    }

    function runValidation() {
        const errors = {
            name: validateName(),
            category: validateCategory(),
            description: validateDescription(),
            status: validateStatus()
        };
        Object.keys(errors).forEach(function(k) { showError(k, errors[k]); });
        const valid = Object.values(errors).every(function(e) { return !e; });
        const btn = el('modal-submit-btn');
        if (btn) btn.disabled = !valid;
        return valid;
    }

    function setupServiceNameInput() {
        const inp = el('modal-name');
        if (!inp) return;
        inp.addEventListener('input', function() {
            let v = this.value;
            if (v.startsWith(' ')) v = v.trimStart();
            v = v.replace(/\s+/g, ' ');
            v = formatServiceName(v);
            if (v !== this.value) {
                this.value = v;
            }
            runValidation();
        });
        inp.addEventListener('keydown', function(e) {
            if (e.key === ' ' && (this.selectionStart === 0 || this.value.trim() === '' && this.value === ' ')) {
                e.preventDefault();
            }
        });
        inp.addEventListener('blur', function() {
            this.value = formatServiceName(this.value);
            runValidation();
        });
    }

    function setupDescriptionInput() {
        const inp = el('modal-description');
        if (!inp) return;
        inp.addEventListener('blur', function() {
            runValidation();
        });
    }

    function setupValidation() {
        ['modal-name', 'modal-category', 'modal-status'].forEach(function(id) {
            const elm = el(id);
            if (elm) {
                elm.addEventListener('input', runValidation);
                elm.addEventListener('change', function() {
                    touchedFields.add(id.replace('modal-', ''));
                    runValidation();
                });
                elm.addEventListener('blur', function() {
                    touchedFields.add(id.replace('modal-', ''));
                    runValidation();
                });
            }
        });

        const descElm = el('modal-description');
        if (descElm) {
            descElm.addEventListener('change', function() {
                touchedFields.add('description');
                runValidation();
            });
            descElm.addEventListener('blur', function() {
                touchedFields.add('description');
                runValidation();
            });
        }

        setupServiceNameInput();
        setupDescriptionInput();
    }

    function initServiceFormValidation() {
        const form = document.getElementById('service-form');
        if (!form) {
            window.printflowServiceFormValidationRun = function() {};
            return;
        }

        if (form.getAttribute('data-pf-service-validation') !== '1') {
            form.setAttribute('data-pf-service-validation', '1');
            form.addEventListener('submit', function(e) {
                ['name', 'category', 'description', 'status'].forEach(function(k) {
                    touchedFields.add(k);
                });
                if (!runValidation()) e.preventDefault();
            });
            setupValidation();
        }

        window.printflowServiceFormValidationRun = runValidation;
        runValidation();
    }

    document.addEventListener('pf-service-modal-shown', function() {
        setTimeout(function() {
            touchedFields.clear();
            ['name', 'category', 'description', 'status'].forEach(function(k) {
                showError(k, '');
            });
            runValidation();
        }, 50);
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
