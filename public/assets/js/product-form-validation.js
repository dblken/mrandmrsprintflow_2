/**
 * Product Form Validation - Real-time validation for Add/Edit Product modal
 * PrintFlow - Admin Products Management
 */
(function() {
    console.log('[PrintFlow] Product Validation Loaded v2.2');
    const ERRORS = {
        nameRequired: 'Product name is required.',
        nameMinLength: 'Product name must be at least 2 characters.',
        nameMaxLength: 'Product name must not exceed 100 characters.',
        nameOnlyNumbers: 'Product name cannot contain only numbers.',
        nameLeadingSpace: 'Leading spaces are not allowed.',
        categoryRequired: 'Please select a category.',
        priceRequired: 'Price is required.',
        priceInvalid: 'Price must be a valid number.',
        priceMin: 'Price must be greater than 0.',
        priceRange: 'Price must be between ₱1.00 and ₱1,000,000.00.',
        descriptionMax: 'Description must not exceed 500 characters.',
        photoRequired: 'Product photo is required.',
        photoType: 'Invalid file type. Only JPG, PNG and GIF are allowed.',
        photoSize: 'File size must not exceed 5MB.',
        quantityRequired: 'Quantity is required.',
        quantityWhole: 'Quantity must be a whole number.',
        quantityNegative: 'Quantity must be a non-negative number.',
        quantityBelowLowStock: 'Quantity cannot be lower than reorder level.',
        criticalBelowReorder: 'Critical Level must be lower than Reorder Level.',
        lowStockExceed: 'Low stock level cannot exceed quantity.',
        lowStockWhole: 'Low stock level must be a whole number.',
        lowStockNegative: 'Low stock level must be a non-negative number.'
    };

    const ALLOWED_PHOTO_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    const MAX_PHOTO_SIZE = 5 * 1024 * 1024; // 5MB

    let touchedFields = new Set();

    function el(id) { return document.getElementById(id); }

    /**
     * Manager branch-stock UI: page has #modal-stock-mgr; admins do not.
     * Use open product modal + that id — not input.disabled (timing / browser quirks broke Save).
     */
    function isBranchStockModalOpen() {
        if (!el('modal-stock-mgr')) return false;
        var ov = el('product-modal-overlay');
        return !!(ov && ov.classList.contains('active') && el('modal-mode-input')?.name === 'update_product');
    }

    function isStockOnlyModalOpen() {
        var ov = el('product-modal-overlay');
        return !!(ov && ov.classList.contains('active') && el('modal-mode-input')?.name === 'add_product_stock');
    }

    function isProductEditMode() {
        return !!document.getElementById('product-modal')?.classList.contains('product-modal--edit');
    }

    function resolveFieldUi(fieldId) {
        if (fieldId === 'stock') {
            if (isStockOnlyModalOpen()) return { inputId: 'stock-modal-add-qty', errId: 'err-add-stock' };
            if (isBranchStockModalOpen()) return { inputId: 'modal-stock-mgr', errId: 'err-stock-mgr' };
            return { inputId: 'modal-stock', errId: 'err-stock' };
        }
        if (fieldId === 'low-stock') {
            if (isStockOnlyModalOpen()) return { inputId: 'stock-modal-low-level', errId: 'err-stock-only-low' };
            if (isBranchStockModalOpen()) return { inputId: 'modal-low-mgr', errId: 'err-low-mgr' };
            return { inputId: 'modal-low-stock', errId: 'err-low-stock' };
        }
        if (fieldId === 'critical-stock') {
            return { inputId: 'modal-critical-stock', errId: 'err-critical-stock' };
        }
        var inputId = fieldId === 'photo' ? 'modal-photo' : 'modal-' + fieldId;
        return { inputId: inputId, errId: 'err-' + fieldId };
    }

    function showError(fieldId, msg) {
        const ui = resolveFieldUi(fieldId);
        const inp = el(ui.inputId);
        const err = el(ui.errId);
        const group = inp?.closest('.form-group');
        if (err) {
            const isTouched = touchedFields.has(fieldId);
            err.textContent = msg || '';
            err.style.display = (msg && isTouched) ? 'block' : 'none';
        }
        if (group) {
            const isTouched = touchedFields.has(fieldId);
            var valForSuccess = '';
            if (inp && inp.type !== 'file') valForSuccess = (inp.value || '').trim();
            group.classList.toggle('has-error', !!msg && isTouched);
            group.classList.toggle('has-success', !msg && isTouched && !!valForSuccess);
        }
    }
    function getVal(fieldId) {
        if (fieldId === 'stock') return (el(resolveFieldUi('stock').inputId)?.value || '').trim();
        if (fieldId === 'low-stock') return (el(resolveFieldUi('low-stock').inputId)?.value || '').trim();
        if (fieldId === 'critical-stock') return (el('modal-critical-stock')?.value || '').trim();
        return (el('modal-' + fieldId)?.value || '').trim();
    }
    function getPhoto() { return el('modal-photo'); }
    function isCreateMode() { return el('modal-mode-input')?.name === 'create_product'; }
    function hasExistingPhoto() { return el('photo-preview-img')?.style?.display === 'block'; }

    function formatProductName(val) {
        if (!val) return '';
        // Collapse multiple spaces to one
        val = val.replace(/\s+/g, ' ');
        // If it still starts with space (should be handled by input listener but for safety)
        if (val.startsWith(' ')) val = val.trimStart();
        return val.replace(/\b\w/g, function(c) { return c.toUpperCase(); });
    }

    function validateName() {
        const raw = (el('modal-name')?.value || '');
        const val = raw.trim();
        if (val.startsWith(' ') || raw !== val && raw.startsWith(' ')) return ERRORS.nameLeadingSpace;
        if (!val) return ERRORS.nameRequired;
        if (val.length < 2) return ERRORS.nameMinLength;
        if (val.length > 100) return ERRORS.nameMaxLength;
        if (/^\d+$/.test(val)) return ERRORS.nameOnlyNumbers;
        return '';
    }

    function validateCategory() {
        const v = getVal('category');
        if (!v || v === '-- Select Category --') return ERRORS.categoryRequired;
        return '';
    }

    function validatePrice() {
        const v = getVal('price');
        if (!v) return ERRORS.priceRequired;
        const num = parseFloat(v);
        if (isNaN(num)) return ERRORS.priceInvalid;
        if (num < 1) return ERRORS.priceMin;
        if (num > 1000000) return ERRORS.priceRange;
        const dec = v.split('.')[1];
        if (dec && dec.length > 2) return ERRORS.priceRange;
        return '';
    }

    function validateDescription() {
        const v = (el('modal-description')?.value || '').trim();
        if (v.length > 500) return ERRORS.descriptionMax;
        return '';
    }

    function validatePhoto() {
        if (!isCreateMode() && hasExistingPhoto()) return '';
        const file = getPhoto()?.files?.[0];
        if (!file) return ERRORS.photoRequired;
        if (!ALLOWED_PHOTO_TYPES.includes(file.type)) return ERRORS.photoType;
        if (file.size > MAX_PHOTO_SIZE) return ERRORS.photoSize;
        return '';
    }

    function validateQuantity() {
        const v = getVal('stock');
        if (!v && v !== '0') return ERRORS.quantityRequired;
        const num = parseFloat(v);
        if (isNaN(num) || num < 0) return ERRORS.quantityNegative;
        if (num !== Math.floor(num)) return ERRORS.quantityWhole;
        if (isStockOnlyModalOpen() && num < 1) return ERRORS.quantityRequired;

        if (!isStockOnlyModalOpen() && !isBranchStockModalOpen() && !isProductEditMode()) {
            const lowStockVal = getVal('low-stock');
            if (lowStockVal && !/not set/i.test(lowStockVal)) {
                const lowStock = parseInt(lowStockVal, 10);
                if (!isNaN(lowStock) && num < lowStock) {
                    return ERRORS.quantityBelowLowStock;
                }
            }
        }

        return '';
    }

    function validateLowStock() {
        const v = getVal('low-stock');
        if (!v || /not set/i.test(v)) {
            return isCreateMode() ? 'Set initial quantity to calculate reorder level.' : '';
        }
        let qty = parseInt((el(resolveFieldUi('stock').inputId)?.value || '0'), 10);
        if (isProductEditMode()) {
            qty = parseInt((el('modal-current-stock')?.value || '0'), 10);
        }
        if (isStockOnlyModalOpen()) {
            const current = parseInt((el('stock-modal-current')?.value || '0'), 10);
            qty = current + (isNaN(qty) ? 0 : qty);
        }
        const num = parseFloat(v);
        if (isNaN(num) || num < 0) return ERRORS.lowStockNegative;
        if (num !== Math.floor(num)) return ERRORS.lowStockWhole;
        if (!isProductEditMode() && num > qty) return ERRORS.lowStockExceed;
        return '';
    }

    function validateCriticalStock() {
        const v = getVal('critical-stock');
        if (!v || /not set/i.test(v)) {
            return isCreateMode() ? 'Set initial quantity to calculate critical level.' : '';
        }
        const reorderVal = getVal('low-stock');
        const reorder = parseFloat(reorderVal);
        const num = parseFloat(v);
        if (isNaN(num) || num < 0) return ERRORS.lowStockNegative;
        if (num !== Math.floor(num)) return ERRORS.lowStockWhole;
        if (!isNaN(reorder) && reorder > 0 && num >= reorder) return ERRORS.criticalBelowReorder;
        return '';
    }

    function runValidation() {
        var errors;
        if (isStockOnlyModalOpen()) {
            errors = {
                name: '',
                category: '',
                price: '',
                description: '',
                photo: '',
                stock: validateQuantity(),
                'low-stock': validateLowStock(),
                'critical-stock': ''
            };
        } else if (isBranchStockModalOpen()) {
            errors = {
                name: '',
                category: '',
                price: '',
                description: '',
                photo: '',
                stock: validateQuantity(),
                'low-stock': validateLowStock(),
                'critical-stock': ''
            };
        } else {
            errors = {
                name: validateName(),
                category: validateCategory(),
                price: validatePrice(),
                description: validateDescription(),
                photo: validatePhoto(),
                stock: isProductEditMode() ? '' : validateQuantity(),
                'low-stock': validateLowStock(),
                'critical-stock': validateCriticalStock()
            };
        }
        Object.keys(errors).forEach(function(k) { showError(k, errors[k]); });
        const valid = (errors && typeof errors === 'object') ? Object.values(errors).every(function(e) { return !e; }) : false;
        const btn = el('modal-submit-products-mgr') || el('modal-submit-btn');
        /* Stock-only and branch-stock modals must remain clickable; submit handler and backend still block invalid values. */
        if (btn) btn.disabled = (isBranchStockModalOpen() || isStockOnlyModalOpen()) ? false : !valid;
        return valid;
    }

    function setupProductNameInput() {
        const inp = el('modal-name');
        if (!inp) return;
        inp.addEventListener('input', function() {
            let v = this.value;
            // Block leading space
            if (v.startsWith(' ')) v = v.trimStart();
            // Collapse multiple spaces
            v = v.replace(/\s+/g, ' ');
            
            v = formatProductName(v);
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
            this.value = formatProductName(this.value);
            runValidation();
        });
    }

    function setupValidation() {
        ['modal-name', 'modal-category', 'modal-price', 'modal-description', 'modal-stock', 'modal-low-stock', 'modal-critical-stock', 'modal-stock-mgr', 'modal-low-mgr', 'stock-modal-add-qty', 'stock-modal-low-level'].forEach(function(id) {
            const elm = el(id);
            if (elm) {
                elm.addEventListener('input', function() {
                    // Mark as touched on input
                    if (id === 'modal-stock-mgr' || id === 'stock-modal-add-qty') touchedFields.add('stock');
                    else if (id === 'modal-low-stock' || id === 'modal-low-mgr' || id === 'stock-modal-low-level') touchedFields.add('low-stock');
                    else if (id === 'modal-critical-stock') touchedFields.add('critical-stock');
                    else touchedFields.add(id.replace('modal-', ''));
                    runValidation();
                });
                elm.addEventListener('change', function() {
                    if (id === 'modal-stock-mgr' || id === 'stock-modal-add-qty') touchedFields.add('stock');
                    else if (id === 'modal-low-stock' || id === 'modal-low-mgr' || id === 'stock-modal-low-level') touchedFields.add('low-stock');
                    else if (id === 'modal-critical-stock') touchedFields.add('critical-stock');
                    else touchedFields.add(id.replace('modal-', ''));
                    runValidation();
                });
                elm.addEventListener('blur', function() {
                    if (id === 'modal-stock-mgr' || id === 'stock-modal-add-qty') touchedFields.add('stock');
                    else if (id === 'modal-low-stock' || id === 'modal-low-mgr' || id === 'stock-modal-low-level') touchedFields.add('low-stock');
                    else if (id === 'modal-critical-stock') touchedFields.add('critical-stock');
                    else touchedFields.add(id.replace('modal-', ''));
                    runValidation();
                });
            }
        });
        const photoInput = getPhoto();
        if (photoInput) photoInput.addEventListener('change', runValidation);
        setupProductNameInput();
    }

    function initProductFormValidation() {
        const form = document.getElementById('product-form');
        if (!form) {
            window.printflowProductFormValidationRun = function() {};
            return;
        }

        if (form.getAttribute('data-pf-product-validation') !== '1') {
            form.setAttribute('data-pf-product-validation', '1');
            form.addEventListener('submit', function(e) {
                if (isStockOnlyModalOpen() || isBranchStockModalOpen()) {
                    ['stock', 'low-stock'].forEach(function(k) { touchedFields.add(k); });
                } else {
                    ['name', 'category', 'price', 'description', 'photo', 'stock', 'low-stock'].forEach(function(k) {
                        touchedFields.add(k);
                    });
                }
                if (!runValidation()) e.preventDefault();
            });
            setupValidation();
        }

        window.printflowProductFormValidationRun = runValidation;
        runValidation();
    }

    document.addEventListener('pf-product-modal-shown', function() {
        setTimeout(function() {
            touchedFields.clear();
            ['name', 'category', 'price', 'description', 'photo', 'stock', 'low-stock'].forEach(function(k) {
                showError(k, '');
            });
            runValidation();
        }, 50);
    });

    function bootProductFormValidation() {
        initProductFormValidation();
    }

    // -------------------------------------------------------------------------
    // EVENT DELEGATION FOR NUMERIC RESTRICTIONS (Quantity & Low Stock)
    // -------------------------------------------------------------------------
    // We use delegation on the document level to ensure listeners persist
    // even if Alpine.js or other scripts re-render the modal content.
    const NUMERIC_INPUT_IDS = ['modal-stock', 'modal-stock-mgr', 'modal-low-stock', 'modal-critical-stock', 'modal-low-mgr', 'stock-modal-add-qty', 'stock-modal-low-level'];

    // Global KeyDown: Block invalid keystrokes immediately
    document.addEventListener('keydown', function(e) {
        if (!NUMERIC_INPUT_IDS.includes(e.target.id)) return;
        
        const el = e.target;
        const isNumberKey = (e.key >= '0' && e.key <= '9');
        const isModifier = e.ctrlKey || e.metaKey || e.altKey;
        
        // 1. Block '0' as first character
        if (e.key === '0' && (el.value === '' || el.value === '0')) {
            e.preventDefault();
            return;
        }

        // 2. Limit to 5 characters
        if (el.value.length >= 5 && isNumberKey && !isModifier) {
            e.preventDefault();
            return;
        }
    }, true);

    // Global Input: Strip invalid characters/length (handles spinners & mouse interaction)
    document.addEventListener('input', function(e) {
        if (!NUMERIC_INPUT_IDS.includes(e.target.id)) return;
        
        const el = e.target;
        let val = el.value;
        let changed = false;

        // 1. Remove any leading zeros (forces 01 -> 1, and 0 alone -> empty)
        if (val.length > 0 && val.startsWith('0')) {
            val = val.replace(/^0+/, '');
            changed = true;
        }

        // 2. Enforce 5 character limit
        if (val.length > 5) {
            val = val.substring(0, 5);
            changed = true;
        }

        if (changed) {
            // Using a tiny timeout can help break out of browser-controlled input loops in type="number"
            setTimeout(function() {
                el.value = val;
                // Re-trigger input for validation logic to pick up the change
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }, 0);
        }
    }, true);

    // Global Blur: Final cleanup just in case
    document.addEventListener('blur', function(e) {
        if (!NUMERIC_INPUT_IDS.includes(e.target.id)) return;
        
        const el = e.target;
        let val = el.value;
        if (val.length > 0 && val.startsWith('0')) {
            el.value = val.replace(/^0+/, '');
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, true);

    // Global Paste: Sanitize data before it enters
    document.addEventListener('paste', function(e) {
        if (!NUMERIC_INPUT_IDS.includes(e.target.id)) return;
        
        e.preventDefault();
        const el = e.target;
        let pastedText = (e.clipboardData || window.clipboardData).getData('text');
        
        // Strip non-digits and leading zeros
        pastedText = pastedText.replace(/\D/g, '').replace(/^0+/, '');
        
        // Limit to 5 digits
        if (pastedText.length > 5) {
            pastedText = pastedText.substring(0, 5);
        }
        
        el.value = pastedText;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootProductFormValidation);
    } else {
        bootProductFormValidation();
    }
    document.addEventListener('printflow:page-init', bootProductFormValidation);
    document.addEventListener('pf-product-modal-shown', bootProductFormValidation);
})();
