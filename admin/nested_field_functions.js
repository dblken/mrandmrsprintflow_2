// Nested Field Management Functions

// Toggle nested field panel when + button is clicked
window.toggleNestedFieldPanel = function(btn, fieldKey, optionIndex) {
    const optionItem = btn.closest('.radio-option-item');
    let nestedPanel = optionItem.querySelector('.nested-field-panel');
    
    if (!nestedPanel) {
        // Create nested panel if it doesn't exist
        nestedPanel = document.createElement('div');
        nestedPanel.className = 'nested-field-panel';
        nestedPanel.style.cssText = 'margin-top:12px;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;';
        nestedPanel.innerHTML = `
            <label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:8px;text-transform:uppercase;">Add Nested Fields (Optional)</label>
            <div class="nested-fields-config" id="nested-config-${fieldKey}-${optionIndex}" style="display:block;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:8px 0;border-bottom:1px solid #e5e7eb;">
                    <label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;">Nested Fields</label>
                    <button type="button" class="btn-add" onclick="addNestedFieldItem(this)" style="padding:4px 8px;font-size:11px;">+ Add Field</button>
                </div>
                <div class="nested-fields-list" style="display:flex;flex-direction:column;gap:12px;"></div>
            </div>
        `;
        optionItem.appendChild(nestedPanel);
        btn.textContent = '−'; // Change to minus
        btn.style.background = '#ef4444'; // Red color
        btn.title = 'Remove Nested Field';
    } else {
        // Toggle visibility
        if (nestedPanel.style.display === 'none') {
            nestedPanel.style.display = 'block';
            btn.textContent = '−';
            btn.style.background = '#ef4444';
            btn.title = 'Remove Nested Field';
        } else {
            nestedPanel.style.display = 'none';
            btn.textContent = '+';
            btn.style.background = '#10b981';
            btn.title = 'Add Nested Field';
        }
    }
};

// Handle nested field type change from dropdown - now simplified for multi-field support
window.handleNestedFieldTypeChange = function(select, fieldKey, optionIndex) {
    // This function is no longer needed as we directly show the multi-field interface
    // Keeping for backward compatibility
};

// Add a new nested field item
window.addNestedFieldItem = function(btn) {
    const container = btn.closest('.nested-fields-config');
    const fieldsList = container.querySelector('.nested-fields-list');
    
    const item = document.createElement('div');
    item.className = 'nested-field-item';
    item.style.cssText = 'padding:12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;position:relative;';
    
    item.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;">Field Configuration</label>
            <button type="button" onclick="removeNestedFieldItem(this)" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">Remove</button>
        </div>
        
        <div style="margin-bottom:16px;padding-top:8px;">
            <label class="field-label">Field Label *</label>
            <input type="text" class="nested-label field-input" placeholder="e.g., Special Instructions" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;background:#fff;transition:all 0.2s;">
        </div>
        
        <div style="margin-bottom:16px;">
            <label class="field-label">Field Type *</label>
            <select class="nested-type-select field-input" onchange="updateNestedFieldType(this)">
                <option value="">-- Select Type --</option>
                <option value="select">Select (Dropdown)</option>
                <option value="radio">Radio Buttons</option>
                <option value="dimension">Dimension (Size)</option>
                <option value="file">File Upload</option>
                <option value="textarea">Textarea</option>
            </select>
        </div>
        
        <div class="nested-options-container" style="display:none;margin-bottom:16px;">
            <label class="field-label">Options (Choices shown to customer)</label>
            <div class="nested-options-list option-list">
                <div class="option-item" style="display:flex;gap:8px;align-items:center;">
                    <input type="text" class="nested-option-input option-input" placeholder="e.g., Small, Red, Matte (32 MAX)" maxlength="32" style="flex:2;">
                    <input type="number" class="nested-option-price-input option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option">
                    <button type="button" class="btn-remove" onclick="this.parentElement.remove()">Remove</button>
                </div>
                <div class="option-item" style="display:flex;gap:8px;align-items:center;">
                    <input type="text" class="nested-option-input option-input" placeholder="e.g., Medium, Blue, Glossy" maxlength="32" style="flex:2;">
                    <input type="number" class="nested-option-price-input option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option">
                    <button type="button" class="btn-remove" onclick="this.parentElement.remove()">Remove</button>
                </div>
                <div class="option-item" style="display:flex;gap:8px;align-items:center;">
                    <input type="text" class="nested-option-input option-input" placeholder="e.g., Large, Green, Vinyl" maxlength="32" style="flex:2;">
                    <input type="number" class="nested-option-price-input option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option">
                    <button type="button" class="btn-remove" onclick="this.parentElement.remove()">Remove</button>
                </div>
            </div>
            <button type="button" class="btn-add" onclick="addNestedOption(this)" style="margin-top:12px;">+ Add Option</button>
        </div>
        
        <div class="nested-dimension-container" style="display:none;margin-bottom:16px;">
            <div class="field-group">
                <label class="field-label">Measurement Unit</label>
                <select class="field-input nested-unit-select">
                    <option value="ft">Feet (ft)</option>
                    <option value="in">Inches (in)</option>
                </select>
            </div>
            <div class="field-group">
                <label class="field-label">Dimension Options (Width × Height)</label>
                <div class="nested-dimension-list option-list">
                    <div class="option-item" style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" class="nested-dimension-width option-input dimension-w" placeholder="Width" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                        <span style="color: #cbd5e1; font-weight: bold;">×</span>
                        <input type="text" class="nested-dimension-height option-input dimension-h" placeholder="Height" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                        <input type="number" class="nested-dimension-price-input dimension-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension">
                        <button type="button" class="btn-remove" onclick="this.parentElement.remove()">Remove</button>
                    </div>
                    <div class="option-item" style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" class="nested-dimension-width option-input dimension-w" placeholder="Width" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                        <span style="color: #cbd5e1; font-weight: bold;">×</span>
                        <input type="text" class="nested-dimension-height option-input dimension-h" placeholder="Height" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                        <input type="number" class="nested-dimension-price-input dimension-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension">
                        <button type="button" class="btn-remove" onclick="this.parentElement.remove()">Remove</button>
                    </div>
                    <div class="option-item" style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" class="nested-dimension-width option-input dimension-w" placeholder="Width" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                        <span style="color: #cbd5e1; font-weight: bold;">×</span>
                        <input type="text" class="nested-dimension-height option-input dimension-h" placeholder="Height" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                        <input type="number" class="nested-dimension-price-input dimension-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension">
                        <button type="button" class="btn-remove" onclick="this.parentElement.remove()">Remove</button>
                    </div>
                </div>
                <button type="button" class="btn-add" onclick="addNestedDimensionOption(this)" style="margin-top:12px;">+ Add Dimension</button>
            </div>
            <div style="margin-top:16px;">
                <label class="toggle-label">
                    <label class="toggle-switch">
                        <input type="checkbox" class="nested-allow-others" checked>
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Allow "Others" (Custom Size Input)</span>
                </label>
            </div>
        </div>
        
        <div style="margin-top:16px;">
            <label class="toggle-label">
                <label class="toggle-switch">
                    <input type="checkbox" class="nested-required">
                    <span class="toggle-slider"></span>
                </label>
                <span>Required Field</span>
            </label>
        </div>
    `;
    
    fieldsList.appendChild(item);
};

// Remove nested field item
window.removeNestedFieldItem = function(btn) {
    btn.closest('.nested-field-item').remove();
};

// Update nested field type-specific options
window.updateNestedFieldType = function(select) {
    const item = select.closest('.nested-field-item');
    const optionsContainer = item.querySelector('.nested-options-container');
    const dimensionContainer = item.querySelector('.nested-dimension-container');
    const type = select.value;
    
    // Hide all containers first
    if (optionsContainer) optionsContainer.style.display = 'none';
    if (dimensionContainer) dimensionContainer.style.display = 'none';
    
    // Show appropriate container based on type
    if (['select', 'radio'].includes(type)) {
        if (optionsContainer) optionsContainer.style.display = 'block';
    } else if (type === 'dimension') {
        if (dimensionContainer) dimensionContainer.style.display = 'block';
    }
};

window.addNestedOption = function(btn) {
    const list = btn.previousElementSibling;
    const div = document.createElement('div');
    div.className = 'option-item';
    div.style.cssText = 'display:flex;gap:8px;align-items:center;';
    div.innerHTML = `
        <input type="text" class="nested-option-input option-input" placeholder="Enter option" maxlength="32" style="flex:2;">
        <input type="number" class="nested-option-price-input option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option">
        <button type="button" class="btn-remove" onclick="this.parentElement.remove()">Remove</button>
    `;
    list.appendChild(div);
};

window.addNestedDimensionOption = function(btn) {
    const list = btn.previousElementSibling;
    const div = document.createElement('div');
    div.className = 'option-item';
    div.style.cssText = 'display: flex; gap: 8px; align-items: center;';
    div.innerHTML = `
        <input type="text" class="nested-dimension-width option-input dimension-w" placeholder="Width" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
        <span style="color: #cbd5e1; font-weight: bold;">×</span>
        <input type="text" class="nested-dimension-height option-input dimension-h" placeholder="Height" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
        <input type="number" class="nested-dimension-price-input dimension-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension">
        <button type="button" class="btn-remove" onclick="this.parentElement.remove()">Remove</button>
    `;
    list.appendChild(div);
};

// Enhanced form submission to collect nested fields
window.collectNestedFieldConfigurations = function() {
    const configs = {};
    
    // Collect configurations from each field
    document.querySelectorAll('.section-card').forEach((card, index) => {
        const key = card.getAttribute('data-field-key');
        if (!key) return;
        
        const isDefault = card.getAttribute('data-is-default') === 'true';
        const labelInput = card.querySelector('.label-input');
        const requiredToggle = card.querySelector('.required-toggle');
        
        const config = {
            label: labelInput ? labelInput.value : window.fieldConfigurations[key]?.label || key,
            type: window.fieldConfigurations[key]?.type || 'text',
            visible: true,
            required: isDefault ? (key === 'notes' ? false : true) : (requiredToggle ? requiredToggle.checked : false),
            order: index
        };
        
        // Handle radio fields with nested fields
        if (config.type === 'radio') {
            const radioOptionsList = card.querySelector('.radio-options-list');
            if (radioOptionsList) {
                const options = [];
                radioOptionsList.querySelectorAll('.radio-option-item').forEach(optionItem => {
                    const optionValue = optionItem.querySelector('.option-input').value.trim();
                    if (!optionValue) return;
                    const priceInput = optionItem.querySelector('.option-price-input');
                    const optionPrice = priceInput ? parseFloat(priceInput.value) || 0 : 0;
                    
                    // Check if this option has a nested field panel
                    const nestedPanel = optionItem.querySelector('.nested-field-panel');
                    
                    if (nestedPanel) {
                        // Has nested field panel - collect all nested fields directly
                        const nestedFieldItems = nestedPanel.querySelectorAll('.nested-field-item');
                        const nestedFields = [];
                        
                        nestedFieldItems.forEach(nestedFieldItem => {
                            const nLabel = nestedFieldItem.querySelector('.nested-label')?.value.trim();
                            const nTypeSelect = nestedFieldItem.querySelector('.nested-type-select');
                            const nType = nTypeSelect ? nTypeSelect.value : '';
                            const nRequired = nestedFieldItem.querySelector('.nested-required')?.checked || false;
                            
                            if (nLabel && nType) {
                                const nField = {
                                    label: nLabel,
                                    type: nType,
                                    required: nRequired
                                };
                                
                                if (['select', 'radio'].includes(nType)) {
                                    const nOptions = [];
                                    nestedFieldItem.querySelectorAll('.nested-options-list .option-item').forEach(nOptRow => {
                                        const nOptInput = nOptRow.querySelector('.nested-option-input');
                                        const nOptVal = nOptInput ? nOptInput.value.trim() : '';
                                        if (!nOptVal) return;
                                        const nPriceInput = nOptRow.querySelector('.nested-option-price-input');
                                        const nPrice = nPriceInput ? parseFloat(nPriceInput.value) || 0 : 0;
                                        nOptions.push({ value: nOptVal, price: nPrice });
                                    });
                                    if (nOptions.length > 0) nField.options = nOptions;
                                }

                                if (nType === 'dimension') {
                                    const nUnitSelect = nestedFieldItem.querySelector('.nested-unit-select');
                                    const nAllowOthers = nestedFieldItem.querySelector('.nested-allow-others');
                                    const nDims = [];
                                    nestedFieldItem.querySelectorAll('.nested-dimension-list .option-item').forEach(nDimItem => {
                                        const wEl = nDimItem.querySelector('.nested-dimension-width') || nDimItem.querySelector('.dimension-w');
                                        const hEl = nDimItem.querySelector('.nested-dimension-height') || nDimItem.querySelector('.dimension-h');
                                        const w = wEl ? wEl.value.trim() : '';
                                        const h = hEl ? hEl.value.trim() : '';
                                        const priceInput = nDimItem.querySelector('.nested-dimension-price-input');
                                        const price = priceInput ? parseFloat(priceInput.value) || 0 : 0;
                                        if (w && h) nDims.push({ value: w + '×' + h, price });
                                    });
                                    if (nDims.length > 0) nField.options = nDims;
                                    nField.unit = nUnitSelect ? nUnitSelect.value : 'ft';
                                    nField.allow_others = nAllowOthers ? nAllowOthers.checked : true;
                                }
                                
                                nestedFields.push(nField);
                            }
                        });
                        
                        if (nestedFields.length > 0) {
                            options.push({
                                value: optionValue,
                                price: optionPrice,
                                nested_fields: nestedFields
                            });
                        } else {
                            options.push({ value: optionValue, price: optionPrice });
                        }
                    } else {
                        // No nested field panel
                        options.push({ value: optionValue, price: optionPrice });
                    }
                });
                
                if (options.length > 0) config.options = options;
            }
        }
        // Handle select fields (no nested support)
        else if (config.type === 'select') {
            const optionList = card.querySelector('.option-list:not(.radio-options-list)');
            if (optionList) {
                const options = [];
                optionList.querySelectorAll('.option-item').forEach(item => {
                    const input = item.querySelector('.option-input');
                    const val = input ? input.value.trim() : '';
                    if (!val) return;
                    const priceInput = item.querySelector('.option-price-input');
                    const optionPrice = priceInput ? parseFloat(priceInput.value) || 0 : 0;
                    options.push({ value: val, price: optionPrice });
                });
                if (options.length > 0) config.options = options;
            }
        }
        // Handle dimension fields
        else {
            const dimensionList = card.querySelector('.dimension-options');
            if (dimensionList) {
                const dimensions = [];
                dimensionList.querySelectorAll('.option-item').forEach(item => {
                    const w = item.querySelector('.dimension-w')?.value.trim();
                    const h = item.querySelector('.dimension-h')?.value.trim();
                    if (!w || !h) return;
                    const priceInput = item.querySelector('.dimension-price-input');
                    const price = priceInput ? parseFloat(priceInput.value) || 0 : 0;
                    dimensions.push({ value: w + '×' + h, price: price });
                });
                if (dimensions.length > 0) config.options = dimensions;
                
                const unitSelect = card.querySelector('.unit-select');
                if (unitSelect) config.unit = unitSelect.value;
                
                const allowOthersToggle = card.querySelector('.allow-others-toggle');
                if (allowOthersToggle) config.allow_others = allowOthersToggle.checked;
            }
        }

        // Merge with client-side state so fields only editable in the modal (e.g. select/radio
        // "Allow Others") are not dropped when saving from the main form — otherwise PHP
        // defaults missing allow_others to ON.
        const prev = window.fieldConfigurations && window.fieldConfigurations[key]
            ? window.fieldConfigurations[key]
            : {};
        configs[key] = { ...prev, ...config };
    });
    
    return configs;
};
