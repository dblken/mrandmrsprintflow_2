<?php
/**
 * Service Field Configuration
 * Admin interface to customize service form fields
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_field_config_helper.php';

require_role(['Admin', 'Manager']);

$service_id = (int)($_GET['service_id'] ?? $_GET['service-id'] ?? 0);
$error = '';
$success = '';

if ($service_id < 1) {
    header('Location: services_management.php');
    exit;
}

$service = db_query("SELECT * FROM services WHERE service_id = ?", 'i', [$service_id]);
if (empty($service)) {
    header('Location: services_management.php');
    exit;
}
$service = $service[0];

// Initialize config if not exists
if (!service_has_field_config($service_id)) {
    // Initialize with default fields or from customer_link if available
    $service_link = !empty($service['customer_link']) ? $service['customer_link'] : null;
    init_service_field_config($service_id, $service_link);
}

// Ensure branch field always exists
$branch_exists = db_query(
    "SELECT config_id FROM service_field_configs WHERE service_id = ? AND field_key = 'branch'",
    'i',
    [$service_id]
);

if (empty($branch_exists)) {
    // Add branch field as first field
    save_service_field_config($service_id, 'branch', [
        'label' => 'Branch',
        'type' => 'select',
        'options' => null,
        'visible' => true,
        'required' => true,
        'default' => null,
        'order' => 0
    ]);
    
    // Update order of other fields
    db_execute(
        "UPDATE service_field_configs SET display_order = display_order + 1 WHERE service_id = ? AND field_key != 'branch'",
        'i',
        [$service_id]
    );
}

// Ensure default bottom fields always exist (needed_date, quantity, notes)
$default_bottom_fields = [
    'needed_date' => ['label' => 'Needed Date', 'type' => 'date', 'required' => true],
    'quantity' => ['label' => 'Quantity', 'type' => 'quantity', 'required' => true],
    'notes' => ['label' => 'Notes', 'type' => 'textarea', 'required' => false]
];

foreach ($default_bottom_fields as $field_key => $field_data) {
    $field_exists = db_query(
        "SELECT config_id FROM service_field_configs WHERE service_id = ? AND field_key = ?",
        'is',
        [$service_id, $field_key]
    );
    
    if (empty($field_exists)) {
        // Get max order to add at the bottom
        $max_order_result = db_query(
            "SELECT MAX(display_order) as max_order FROM service_field_configs WHERE service_id = ?",
            'i',
            [$service_id]
        );
        $max_order = ($max_order_result[0]['max_order'] ?? 0) + 1;
        
        // Add the default field
        save_service_field_config($service_id, $field_key, [
            'label' => $field_data['label'],
            'type' => $field_data['type'],
            'options' => null,
            'visible' => true,
            'required' => $field_data['required'],
            'default' => null,
            'order' => $max_order
        ]);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    if (isset($_POST['save_config']) || isset($_POST['field_configs'])) {
        // Debug: Log what's being submitted
        error_log('POST data: ' . print_r($_POST, true));
        
        $configs = json_decode($_POST['field_configs'] ?? '[]', true);
        
        // Debug: Log the decoded configs
        error_log('Decoded configs: ' . print_r($configs, true));
        
        // Get existing field keys from database
        $existing_keys = [];
        $existing_fields = db_query("SELECT field_key FROM service_field_configs WHERE service_id = ?", 'i', [$service_id]);
        foreach ($existing_fields as $field) {
            $existing_keys[] = $field['field_key'];
        }
        
        // Get submitted field keys
        $submitted_keys = array_keys($configs);
        
        // Protect default fields from deletion
        $protected_keys = ['branch', 'needed_date', 'quantity', 'notes'];
        
        // Delete fields that are no longer in the submitted config (except protected fields)
        $keys_to_delete = array_diff($existing_keys, $submitted_keys);
        foreach ($keys_to_delete as $key_to_delete) {
            // Skip deletion of protected default fields
            if (in_array($key_to_delete, $protected_keys)) {
                continue;
            }
            db_execute(
                "DELETE FROM service_field_configs WHERE service_id = ? AND field_key = ?",
                'is',
                [$service_id, $key_to_delete]
            );
        }
        
        // Save or update remaining fields
        foreach ($configs as $field_key => $config) {
            error_log("Saving field {$field_key}: " . print_r($config, true));
            save_service_field_config($service_id, $field_key, $config);
        }
        
        $success = 'Field configuration saved successfully!';
        $field_configs = get_service_field_config($service_id);
    }
}

$field_configs = get_service_field_config($service_id);

$page_title = 'Configure Input Fields - ' . $service['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        /* Match Staff Dashboard Design */
        .orders-table { width:100%; border-collapse:collapse; font-size:13px; }
        .orders-table th { padding:12px 16px; font-weight:600; color:#6b7280; text-align:left; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
        .orders-table td { padding:12px 16px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        .orders-table tbody tr { transition:background 0.1s; }
        .orders-table tbody tr:hover { background:#f9fafb; }
        .btn-action { display:inline-flex; align-items:center; justify-content:center; padding:5px 12px; min-width:60px; border:1px solid transparent; background:transparent; border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; white-space:nowrap; transition:all 0.2s; }
        .btn-action.blue { color:#3b82f6; border-color:#3b82f6; } .btn-action.blue:hover { background:#3b82f6; color:#fff; }
        .btn-action.red { color:#ef4444; border-color:#ef4444; } .btn-action.red:hover { background:#ef4444; color:#fff; }
        .toolbar-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; font-size:13px; font-weight:500; color:#374151; cursor:pointer; white-space:nowrap; transition:all 0.2s; }
        .toolbar-btn:hover { border-color:#9ca3af; background:#f9fafb; }
        .section-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; margin-bottom:16px; overflow:hidden; }
        .section-header { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; background:#f9fafb; border-bottom:1px solid #e5e7eb; cursor:pointer; user-select:none; }
        .section-header:hover { background:#f3f4f6; }
        .section-title { font-size:15px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:10px; }
        .section-type { display:inline-block; padding:3px 8px; background:#e0e7ff; color:#4338ca; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; }
        .section-body { padding:20px; display:none; }
        .section-body.active { display:block; }
        .field-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
        .field-group { margin-bottom:16px; }
        .field-label { display:block; font-size:11px; font-weight:700; color:#6b7280; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px; }
        .field-input { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; background:#fff; transition:all 0.2s; }
        .field-input:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 3px rgba(13,148,136,0.1); }
        .toggle-switch { position:relative; display:inline-block; width:48px; height:26px; }
        .toggle-switch input { opacity:0; width:0; height:0; }
        .toggle-slider { position:absolute; cursor:pointer; inset:0; background:#cbd5e1; border-radius:26px; transition:0.3s; }
        .toggle-slider:before { position:absolute; content:""; height:20px; width:20px; left:3px; bottom:3px; background:white; border-radius:50%; transition:0.3s; box-shadow:0 2px 4px rgba(0,0,0,0.2); }
        .toggle-switch input:checked + .toggle-slider { background:#0d9488; }
        .toggle-switch input:checked + .toggle-slider:before { transform:translateX(22px); }
        .toggle-label { display:inline-flex; align-items:center; gap:10px; font-size:13px; color:#374151; font-weight:500; }
        .option-list { display:flex; flex-direction:column; gap:8px; }
        .option-item { display:flex; gap:8px; align-items:center; }
        .option-input { flex:1; padding:9px 12px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; transition:all 0.2s; }
        .option-input:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 2px rgba(13,148,136,0.1); }
        .btn-remove { padding:8px 12px; background:#fee2e2; color:#dc2626; border:none; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; transition:all 0.2s; }
        .btn-remove:hover { background:#fecaca; }
        .btn-add { padding:9px 16px; background:#f0fdfa; color:#0d9488; border:1px solid #0d9488; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; transition:all 0.2s; }
        .btn-add:hover { background:#ccfbf1; }
        .btn-save { padding:8px 16px; background:#0d9488; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:all 0.2s; }
        .btn-save:hover { background:#0f766e; }
        .btn-cancel { padding:8px 16px; background:#f3f4f6; color:#374151; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:all 0.2s; }
        .btn-cancel:hover { background:#e5e7eb; }
        .chevron { width:20px; height:20px; color:#9ca3af; transition:transform 0.2s; }
        .chevron.rotated { transform:rotate(180deg); }
        .info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 16px; margin-bottom:20px; }
        .info-box p { margin:0; font-size:13px; color:#1e40af; line-height:1.5; }
        
        /* Modal Styles */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:16px; }
        .modal-overlay.active { display:flex; }
        .modal-content { background:#fff; border-radius:12px; max-width:500px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 25px 50px rgba(0,0,0,0.25); }
        .modal-header { padding:18px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
        .modal-header h3 { margin:0; font-size:18px; font-weight:700; color:#1f2937; }
        .modal-body { padding:20px; }
        .modal-footer { padding:16px 20px; border-top:1px solid #e5e7eb; display:flex; gap:12px; justify-content:flex-end; }
        .btn-modal-cancel { padding:10px 20px; background:#f3f4f6; color:#374151; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:all 0.2s; }
        .btn-modal-cancel:hover { background:#e5e7eb; }
        .btn-modal-save { padding:10px 20px; background:#0d9488; color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:all 0.2s; }
        .btn-modal-save:hover { background:#0f766e; }
        .btn-close { background:none; border:none; cursor:pointer; color:#9ca3af; font-size:24px; line-height:1; transition:color 0.2s; }
        .btn-close:hover { color:#374151; }
        
        /* Card Styles - Match Staff Dashboard */
        .card { padding:20px; border-radius:16px; margin-bottom:16px; border:1px solid #f1f5f9; background:#fff; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <div class="main-content" style="padding:12px 12px 0;">
        <header>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                <button type="button" onclick="window.location.href='services_management.php'" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:none;background:transparent;cursor:pointer;padding:0;" onmouseover="this.querySelector('svg').style.color='#0d9488';" onmouseout="this.querySelector('svg').style.color='#6b7280';">
                    <svg style="width:24px;height:24px;color:#6b7280;transition:color 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </button>
                <h1 class="page-title" style="margin:0;">Configure Input Fields</h1>
            </div>
            <p style="color:#6b7280; font-size:14px; margin-top:8px;">
                Service: <strong><?php echo htmlspecialchars($service['name']); ?></strong>
            </p>
        </header>
        <main>
            <?php if ($success): ?>
                <div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
                    ✓ <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
                    ✗ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="info-box">
                <p><strong>?? Tip:</strong> Customize each input field below to control what customers see when ordering this service. Toggle visibility to show/hide fields, edit labels to change text, and modify options to update choices. <strong>Radio fields support nested fields</strong> - click the "? Nested" button on any radio option to add conditional fields that appear when that option is selected.</p>
            </div>

            <div class="card">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Input Fields</h3>
                    <button type="button" class="toolbar-btn" onclick="showAddFieldModal()" style="height:38px;border-color:#3b82f6;color:#3b82f6;">
                        + Add Field
                    </button>
                </div>
                
                <form method="POST" id="configForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="field_configs" id="fieldConfigsInput">
                    
                    <div id="sectionsContainer">
                        <?php if (empty($field_configs)): ?>
                            <div style="text-align:center; padding:60px 20px;">
                                <svg style="width:80px;height:80px;color:#d1d5db;margin:0 auto 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <h3 style="font-size:18px;font-weight:700;color:#374151;margin:0 0 8px;">No Fields Configured</h3>
                                <p style="color:#6b7280;margin:0;font-size:14px;">Use the "+ Add Field" button below to start building your custom service form.</p>
                            </div>
                        <?php else: ?>
                            <table class="orders-table">
                                <thead>
                                    <tr>
                                        <th style="width:25%;">Field Label</th>
                                        <th style="width:15%;">Type</th>
                                        <th style="width:15%;text-align:center;">Status</th>
                                        <th style="width:15%;text-align:center;">Required</th>
                                        <th style="width:15%;text-align:center;">Visibility</th>
                                        <th style="width:15%;text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                            <?php foreach ($field_configs as $key => $config): ?>
                                <tr onclick="toggleFieldRow('<?php echo htmlspecialchars($key); ?>')" style="cursor:pointer;">
                                    <td style="font-weight:500;color:#1f2937;"><?php echo htmlspecialchars($config['label']); ?></td>
                                    <td>
                                        <span style="display:inline-block;padding:3px 8px;background:#e0e7ff;color:#4338ca;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;"><?php echo htmlspecialchars($config['type']); ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if (in_array($key, ['branch', 'needed_date', 'quantity', 'notes'])): ?>
                                            <span style="display:inline-block;padding:3px 8px;background:#dcfce7;color:#166534;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;">DEFAULT</span>
                                        <?php else: ?>
                                            <span style="display:inline-block;padding:3px 8px;background:#f3f4f6;color:#6b7280;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;">CUSTOM</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if (in_array($key, ['branch', 'needed_date', 'quantity'])): ?>
                                            <span style="color:#059669;font-weight:600;font-size:12px;">✓ Yes</span>
                                        <?php elseif ($key === 'notes'): ?>
                                            <span style="color:#6b7280;font-weight:500;font-size:12px;">Optional</span>
                                        <?php else: ?>
                                            <span style="color:<?php echo $config['required'] ? '#059669' : '#6b7280'; ?>;font-weight:<?php echo $config['required'] ? '600' : '500'; ?>;font-size:12px;"><?php echo $config['required'] ? '✓ Yes' : 'Optional'; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <span style="color:#059669;font-weight:600;font-size:12px;">✓ Visible</span>
                                    </td>
                                    <td style="text-align:right;white-space:nowrap;" onclick="event.stopPropagation();">
                                        <?php if (!in_array($key, ['branch', 'needed_date', 'quantity', 'notes'])): ?>
                                            <button type="button" class="btn-action blue" onclick="showEditFieldModal('<?php echo htmlspecialchars($key); ?>')">Edit</button>
                                            <button type="button" class="btn-action red" onclick="deleteField(this)" data-field-key="<?php echo htmlspecialchars($key); ?>">Delete</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr id="field-detail-<?php echo htmlspecialchars($key); ?>" style="display:none;">
                                    <td colspan="6" style="background:#f9fafb;padding:0;">
                                        <div class="section-card" data-field-key="<?php echo htmlspecialchars($key); ?>" <?php echo in_array($key, ['branch', 'needed_date', 'quantity', 'notes']) ? 'data-is-default="true"' : ''; ?> style="border:none;border-radius:0;margin:0;">
                                            <div class="section-body active" style="display:block;">
                                                <?php if (in_array($key, ['branch', 'needed_date', 'quantity', 'notes'])): ?>
                                                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
                                                    <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.5;"><strong>ℹ️ Default Field:</strong> This field is required for all services and cannot be deleted. <?php echo $key === 'branch' ? 'It automatically displays all active branches.' : 'It will always appear at the bottom of the customer form.'; ?></p>
                                                </div>
                                                <?php endif; ?>
                                        
                                        <div class="field-row">
                                            <div class="field-group">
                                                <label class="field-label">Section Label</label>
                                                <input type="text" class="field-input label-input" value="<?php echo htmlspecialchars($config['label']); ?>" placeholder="e.g., Size, Finish, Design (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)" <?php echo in_array($key, ['branch', 'needed_date', 'quantity', 'notes']) ? 'readonly style="background:#f9fafb;cursor:not-allowed;"' : ''; ?>>
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label">Required Field</label>
                                                <?php if (in_array($key, ['branch', 'needed_date', 'quantity'])): ?>
                                                <div style="padding:10px 0;color:#6b7280;font-size:14px;font-weight:600;">✓ Always Required</div>
                                                <?php elseif ($key === 'notes'): ?>
                                                <div style="padding:10px 0;color:#6b7280;font-size:14px;font-weight:600;">Optional</div>
                                                <?php else: ?>
                                                <label class="toggle-label">
                                                    <label class="toggle-switch">
                                                        <input type="checkbox" class="required-toggle" <?php echo $config['required'] ? 'checked' : ''; ?>>
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                    <span>Customer must fill this</span>
                                                </label>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($config['type'] === 'radio'): ?>
                                            <div class="field-group">
                                                <label class="field-label">Options (Choices shown to customer)</label>
                                                <div class="option-list radio-options-list">
                                                    <?php foreach ($config['options'] ?? [] as $optIdx => $option): 
                                                        $optValue = is_array($option) ? ($option['value'] ?? '') : $option;
                                                        $optPrice = is_array($option) ? ($option['price'] ?? 0) : 0;
                                                        $nestedFields = is_array($option) ? ($option['nested_fields'] ?? []) : [];
                                                    ?>
                                                        <div class="option-item radio-option-item" data-option-index="<?php echo $optIdx; ?>" style="flex-direction:column;align-items:stretch;">
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="text" class="option-input" value="<?php echo htmlspecialchars($optValue); ?>" placeholder="Enter option (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)" style="flex:2;">
                                <input type="number" class="option-price-input" value="<?php echo $optPrice; ?>" placeholder="Price" min="0" step="0.01" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option">
                                <button type="button" class="btn-add" onclick="toggleNestedFieldPanel(this, '<?php echo htmlspecialchars($key); ?>', <?php echo $optIdx; ?>)" style="background:#10b981;color:white;border:none;padding:8px 12px;border-radius:6px;font-size:14px;font-weight:600;min-width:40px;" title="Add Nested Field">
                                    +
                                </button>
                                <button type="button" class="btn-remove" onclick="removeOption(this)">Remove</button>
                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <button type="button" class="btn-add" onclick="addOption(this)">+ Add Option</button>
                                            </div>
                                        <?php elseif ($config['type'] === 'select'): ?>
                                            <div class="field-group">
                                                <label class="field-label">Options (Choices shown to customer)</label>
                                                <div class="option-list">
                                                    <?php foreach ($config['options'] ?? [] as $option): 
                                                        $optValue = is_array($option) ? ($option['value'] ?? '') : $option;
                                                        $optPrice = is_array($option) ? ($option['price'] ?? 0) : 0;
                                                    ?>
                                                        <div class="option-item" style="display:flex;gap:8px;align-items:center;">
                                                            <input type="text" class="option-input" value="<?php echo htmlspecialchars($optValue); ?>" placeholder="Enter option (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)" style="flex:2;">
                                                            <input type="number" class="option-price-input" value="<?php echo $optPrice; ?>" placeholder="Price" min="0" step="0.01" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option">
                                                            <button type="button" class="btn-remove" onclick="removeOption(this)">Remove</button>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <button type="button" class="btn-add" onclick="addOption(this)">+ Add Option</button>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($config['type'] === 'dimension'): ?>
                                            <div class="field-group">
                                                <label class="field-label">Dimension Options (Width × Height + Price)</label>
                                                <div class="option-list dimension-options">
                                                    <?php 
                                                    $dimension_options = $config['options'] ?? [];
                                                    if (empty($dimension_options)) {
                                                        // Show one empty row if no options
                                                        echo '<div class="option-item" style="display: flex; gap: 8px; align-items: center;">';
                                                        echo '<input type="text" class="option-input dimension-w" value="" placeholder="Width" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,\'\'); checkDimensionDuplicates(this);">';
                                                        echo '<span style="color: #cbd5e1; font-weight: bold;">×</span>';
                                                        echo '<input type="text" class="option-input dimension-h" value="" placeholder="Height" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,\'\'); checkDimensionDuplicates(this);">';
                                                        echo '<input type="number" class="dimension-price-input" value="" placeholder="Price" min="0" step="0.01" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension">';
                                                        echo '<button type="button" class="btn-remove" onclick="removeDimensionOption(this)">Remove</button>';
                                                        echo '</div>';
                                                    } else {
                                                        foreach ($dimension_options as $option): 
                                                            // Handle both string and object formats
                                                            if (is_array($option)) {
                                                                $option_value = $option['value'] ?? '';
                                                                $option_price = $option['price'] ?? 0;
                                                            } else {
                                                                $option_value = $option;
                                                                $option_price = 0;
                                                            }
                                                            
                                                            // Handle multiple separator types
                                                            $option_clean = trim($option_value);
                                                            $parts = preg_split('/[×xX*\-\s]+/', $option_clean, 2);
                                                            $w = isset($parts[0]) && $parts[0] !== '' ? trim($parts[0]) : '';
                                                            $h = isset($parts[1]) && $parts[1] !== '' ? trim($parts[1]) : '';
                                                    ?>
                                                        <div class="option-item" style="display: flex; gap: 8px; align-items: center;">
                                                            <input type="text" class="option-input dimension-w" value="<?php echo htmlspecialchars($w); ?>" placeholder="Width" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,''); checkDimensionDuplicates(this);">
                                                            <span style="color: #cbd5e1; font-weight: bold;">×</span>
                                                            <input type="text" class="option-input dimension-h" value="<?php echo htmlspecialchars($h); ?>" placeholder="Height" maxlength="2" pattern="[0-9]*" style="flex: 1; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,''); checkDimensionDuplicates(this);">
                                                            <input type="number" class="dimension-price-input" value="<?php echo $option_price > 0 ? $option_price : ''; ?>" placeholder="Price" min="0" step="0.01" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension">
                                                            <button type="button" class="btn-remove" onclick="removeDimensionOption(this)">Remove</button>
                                                        </div>
                                                    <?php 
                                                        endforeach;
                                                    }
                                                    ?>
                                                </div>
                                                <button type="button" class="btn-add" onclick="addDimensionOption(this)">+ Add Dimension</button>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($config['type'] === 'dimension'): ?>
                                            <div class="field-group">
                                                <label class="field-label">Measurement Unit</label>
                                                <select class="field-input unit-select" onchange="updateUnitDisplay(this)">
                                                    <option value="ft" <?php echo ($config['unit'] ?? 'ft') === 'ft' ? 'selected' : ''; ?>>Feet (ft)</option>
                                                    <option value="in" <?php echo ($config['unit'] ?? 'ft') === 'in' ? 'selected' : ''; ?>>Inches (in)</option>
                                                </select>
                                            </div>
                                            <div class="field-group">
                                                <label class="toggle-label">
                                                    <label class="toggle-switch">
                                                        <input type="checkbox" class="allow-others-toggle" <?php echo ($config['allow_others'] ?? true) ? 'checked' : ''; ?>>
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                    <span>Allow "Others" (Custom Size Input)</span>
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:24px; padding-top:24px; border-top:1px solid #e5e7eb;">
                        <button type="button" class="btn-cancel" onclick="window.location.href='services_management.php'">Cancel</button>
                        <button type="submit" name="save_config" class="btn-save">Save Configuration</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<!-- View Field Modal -->
<div id="viewFieldModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>View Field Details</h3>
            <button type="button" class="btn-close" onclick="closeViewFieldModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="field-group">
                <label class="field-label">Field Label</label>
                <input type="text" id="view-field-label" class="field-input" readonly style="background:#f9fafb;cursor:default;">
            </div>
            
            <div class="field-group">
                <label class="field-label">Field Type</label>
                <input type="text" id="view-field-type" class="field-input" readonly style="background:#f9fafb;cursor:default;">
            </div>
            
            <div id="view-field-options-section" style="display:none;">
                <div class="field-group">
                    <label class="field-label">Options</label>
                    <div id="view-field-options-list" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;">
                    </div>
                </div>
            </div>
            
            <div id="view-field-dimension-section" style="display:none;">
                <div class="field-group">
                    <label class="field-label">Measurement Unit</label>
                    <input type="text" id="view-field-unit" class="field-input" readonly style="background:#f9fafb;cursor:default;">
                </div>
                <div class="field-group">
                    <label class="field-label">Allow Custom Size</label>
                    <input type="text" id="view-field-allow-others" class="field-input" readonly style="background:#f9fafb;cursor:default;">
                </div>
                <div class="field-group">
                    <label class="field-label">Dimension Options</label>
                    <div id="view-field-dimension-list" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;">
                    </div>
                </div>
            </div>
            
            <div class="field-group">
                <label class="field-label">Required Field</label>
                <input type="text" id="view-field-required" class="field-input" readonly style="background:#f9fafb;cursor:default;">
            </div>
            
            <div class="field-group">
                <label class="field-label">Status</label>
                <input type="text" id="view-field-status" class="field-input" readonly style="background:#f9fafb;cursor:default;">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeViewFieldModal()">Close</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteFieldModal" class="modal-overlay">
    <div class="modal-content" style="max-width:400px;">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button type="button" class="btn-close" onclick="closeDeleteFieldModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin:0;color:#374151;font-size:14px;line-height:1.6;">Are you sure you want to delete this field? This action cannot be undone.</p>
            <input type="hidden" id="delete-field-key">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeDeleteFieldModal()">Cancel</button>
            <button type="button" class="btn-modal-save" onclick="confirmDeleteField()" style="background:#ef4444;">Delete</button>
        </div>
    </div>
</div>

<!-- Edit Field Modal -->
<div id="editFieldModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Field</h3>
            <button type="button" class="btn-close" onclick="closeEditFieldModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="edit-field-key">
            
            <div class="field-group">
                <label class="field-label">Field Label *</label>
                <input type="text" id="edit-field-label" class="field-input" placeholder="e.g., Special Instructions (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)">
            </div>
            
            <div class="field-group">
                <label class="field-label">Field Type *</label>
                <input type="text" id="edit-field-type-display" class="field-input" readonly style="background:#f9fafb;cursor:not-allowed;">
                <input type="hidden" id="edit-field-type">
            </div>
            
            <div id="edit-field-options-section" style="display:none;">
                <div class="field-group">
                    <label class="toggle-label">
                        <label class="toggle-switch">
                            <input type="checkbox" id="edit-field-options-allow-others">
                            <span class="toggle-slider"></span>
                        </label>
                        <span>Allow "Others" (Custom Input)</span>
                    </label>
                </div>
                <div class="field-group">
                    <label class="field-label">Options (Choices shown to customer)</label>
                    <div id="edit-field-options-list" class="option-list"></div>
                    <button type="button" class="btn-add" onclick="addEditFieldOption()" style="margin-top:12px;">+ Add Option</button>
                </div>
            </div>
            
            <div id="edit-field-dimension-section" style="display:none;">
                <div class="field-group">
                    <label class="field-label">Measurement Unit</label>
                    <select id="edit-field-unit" class="field-input">
                        <option value="ft">Feet (ft)</option>
                        <option value="in">Inches (in)</option>
                    </select>
                </div>
                <div class="field-group">
                    <label class="toggle-label">
                        <label class="toggle-switch">
                            <input type="checkbox" id="edit-field-allow-others">
                            <span class="toggle-slider"></span>
                        </label>
                        <span>Allow "Others" (Custom Size Input)</span>
                    </label>
                </div>
                <div class="field-group">
                    <label class="field-label">Dimension Options (Width × Height + Price)</label>
                    <div id="edit-field-dimension-list" class="option-list"></div>
                    <button type="button" class="btn-add" onclick="addEditDimensionOption()" style="margin-top:12px;">+ Add Dimension</button>
                </div>
            </div>
            
            <div class="field-group">
                <label class="toggle-label">
                    <label class="toggle-switch">
                        <input type="checkbox" id="edit-field-required">
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Required Field</span>
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeEditFieldModal()">Cancel</button>
            <button type="button" class="btn-modal-save" onclick="saveEditField()">Save Changes</button>
        </div>
    </div>
</div>

<!-- Add Field Modal -->
<div id="addFieldModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Field</h3>
            <button type="button" class="btn-close" onclick="closeAddFieldModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="field-group">
                <label class="field-label">Field Label *</label>
                <input type="text" id="new-field-label" class="field-input" placeholder="e.g., Special Instructions (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)">
            </div>
            
            <div class="field-group">
                <label class="field-label">Field Type *</label>
                <select id="new-field-type" class="field-input" onchange="toggleNewFieldOptions()">
                    <option value="">-- Select Type --</option>
                    <option value="select">Select (Dropdown)</option>
                    <option value="dimension">Dimension (Size)</option>
                    <option value="radio">Radio Buttons</option>
                    <option value="file">File Upload</option>
                    <option value="textarea">Textarea (Multi-line)</option>
                </select>
            </div>
            
            <div id="new-field-options-section" style="display:none;">
                <div class="field-group">
                    <label class="toggle-label">
                        <label class="toggle-switch">
                            <input type="checkbox" id="new-field-options-allow-others">
                            <span class="toggle-slider"></span>
                        </label>
                        <span>Allow "Others" (Custom Input)</span>
                    </label>
                </div>
                <div class="field-group">
                    <label class="field-label">Options (Choices shown to customer)</label>
                    <div id="new-field-options-list" class="option-list">
                        <div class="option-item" style="display:flex;gap:8px;align-items:center;">
                            <input type="text" class="option-input" placeholder="e.g., Small, Red, Matte (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)" style="flex:2;">
                            <input type="number" class="option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option">
                            <button type="button" class="btn-add" onclick="toggleNewNestedFieldPanel(this, 0)" style="background:#10b981;color:white;border:none;padding:8px 12px;border-radius:6px;font-size:14px;font-weight:600;min-width:40px;" title="Add Nested Field">
                                +
                            </button>
                            <button type="button" class="btn-remove" onclick="removeNewFieldOption(this)">Remove</button>
                        </div>
                        <div class="option-item" style="display:flex;gap:8px;align-items:center;">
                            <input type="text" class="option-input" placeholder="e.g., Medium, Blue, Glossy (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)" style="flex:2;">
                            <input type="number" class="option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option">
                            <button type="button" class="btn-add" onclick="toggleNewNestedFieldPanel(this, 1)" style="background:#10b981;color:white;border:none;padding:8px 12px;border-radius:6px;font-size:14px;font-weight:600;min-width:40px;" title="Add Nested Field">
                                +
                            </button>
                            <button type="button" class="btn-remove" onclick="removeNewFieldOption(this)">Remove</button>
                        </div>
                        <div class="option-item" style="display:flex;gap:8px;align-items:center;">
                            <input type="text" class="option-input" placeholder="e.g., Large, Green, Vinyl (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)" style="flex:2;">
                            <input type="number" class="option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option">
                            <button type="button" class="btn-add" onclick="toggleNewNestedFieldPanel(this, 2)" style="background:#10b981;color:white;border:none;padding:8px 12px;border-radius:6px;font-size:14px;font-weight:600;min-width:40px;" title="Add Nested Field">
                                +
                            </button>
                            <button type="button" class="btn-remove" onclick="removeNewFieldOption(this)">Remove</button>
                        </div>
                    </div>
                    <button type="button" class="btn-add" onclick="addNewFieldOption()" style="margin-top:12px;">+ Add Option</button>
                </div>
            </div>
            
            <div id="new-field-dimension-section" style="display:none;">
                <div class="field-group">
                    <label class="field-label">Measurement Unit</label>
                    <select id="new-field-unit" class="field-input">
                        <option value="ft">Feet (ft)</option>
                        <option value="in">Inches (in)</option>
                    </select>
                </div>
                <div class="field-group">
                    <label class="toggle-label">
                        <label class="toggle-switch">
                            <input type="checkbox" id="new-field-allow-others" checked>
                            <span class="toggle-slider"></span>
                        </label>
                        <span>Allow "Others" (Custom Size Input)</span>
                    </label>
                </div>
                <div class="field-group">
                    <label class="field-label">Dimension Options (Width × Height + Price)</label>
                    <div id="new-field-dimension-list" class="option-list">
                        <div class="option-item" style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" class="dimension-width" placeholder="Width" maxlength="2" pattern="[0-9]*" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                            <span style="color: #cbd5e1; font-weight: bold;">×</span>
                            <input type="text" class="dimension-height" placeholder="Height" maxlength="2" pattern="[0-9]*" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                            <input type="number" class="dimension-price" placeholder="Price" min="0" step="0.01" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension">
                            <button type="button" class="btn-remove" onclick="removeNewDimensionOption(this)">Remove</button>
                        </div>
                        <div class="option-item" style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" class="dimension-width" placeholder="Width" maxlength="2" pattern="[0-9]*" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                            <span style="color: #cbd5e1; font-weight: bold;">×</span>
                            <input type="text" class="dimension-height" placeholder="Height" maxlength="2" pattern="[0-9]*" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                            <input type="number" class="dimension-price" placeholder="Price" min="0" step="0.01" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension">
                            <button type="button" class="btn-remove" onclick="removeNewDimensionOption(this)">Remove</button>
                        </div>
                        <div class="option-item" style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" class="dimension-width" placeholder="Width" maxlength="2" pattern="[0-9]*" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                            <span style="color: #cbd5e1; font-weight: bold;">×</span>
                            <input type="text" class="dimension-height" placeholder="Height" maxlength="2" pattern="[0-9]*" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                            <input type="number" class="dimension-price" placeholder="Price" min="0" step="0.01" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension">
                            <button type="button" class="btn-remove" onclick="removeNewDimensionOption(this)">Remove</button>
                        </div>
                    </div>
                    <button type="button" class="btn-add" onclick="addNewDimensionOption()" style="margin-top:12px;">+ Add Dimension</button>
                </div>
            </div>
            
            <div class="field-group">
                <label class="toggle-label">
                    <label class="toggle-switch">
                        <input type="checkbox" id="new-field-required" checked>
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Required Field</span>
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeAddFieldModal()">Cancel</button>
            <button type="button" class="btn-modal-save" onclick="addNewField()">Add Field</button>
        </div>
    </div>
</div>


<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php
    $nested_fields_js = __DIR__ . '/nested_field_functions.js';
    $nested_fields_ver = file_exists($nested_fields_js) ? filemtime($nested_fields_js) : time();
?>
<script src="nested_field_functions.js?v=<?php echo $nested_fields_ver; ?>"></script>
</body>
</html>


<script>
window.fieldConfigurations = <?php echo json_encode($field_configs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> || {};

window.showAddFieldModal = function() {
    document.getElementById('addFieldModal').classList.add('active');
    document.body.style.overflow = 'hidden';
};

window.closeAddFieldModal = function() {
    document.getElementById('addFieldModal').classList.remove('active');
    document.body.style.overflow = '';
};

window.showEditFieldModal = function(key) {
    const config = window.fieldConfigurations[key];
    if (!config) return;
    
    document.getElementById('edit-field-key').value = key;
    document.getElementById('edit-field-label').value = config.label;
    document.getElementById('edit-field-type').value = config.type;
    document.getElementById('edit-field-type-display').value = config.type.toUpperCase();
    document.getElementById('edit-field-required').checked = config.required;
    
    const optionsSection = document.getElementById('edit-field-options-section');
    const dimensionSection = document.getElementById('edit-field-dimension-section');
    
    if (config.type === 'select' || config.type === 'radio') {
        optionsSection.style.display = 'block';
        dimensionSection.style.display = 'none';
        document.getElementById('edit-field-options-allow-others').checked = !!config.allow_others;
        const list = document.getElementById('edit-field-options-list');
        list.innerHTML = '';
        (config.options || []).forEach(option => {
            const optValue = (typeof option === 'object' && option.value) ? option.value : option;
            const optPrice = (typeof option === 'object' && option.price) ? option.price : 0;
            const item = document.createElement('div');
            item.className = 'option-item';
            item.style.cssText = 'display:flex;gap:8px;align-items:center;';
            item.innerHTML = '<input type="text" class="option-input" value="' + optValue + '" maxlength="32" placeholder="Enter option (32 MAX CHARACTERS)" oninput="formatTextToTitleCase(this)" style="flex:2;"><input type="number" class="option-price-input" value="' + optPrice + '" placeholder="Price" min="0" step="0.01" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option"><button type="button" class="btn-remove" onclick="removeEditFieldOption(this)">Remove</button>';
            list.appendChild(item);
        });
    } else if (config.type === 'dimension') {
        optionsSection.style.display = 'none';
        dimensionSection.style.display = 'block';
        document.getElementById('edit-field-unit').value = config.unit || 'ft';
        document.getElementById('edit-field-allow-others').checked = config.allow_others !== false;
        const list = document.getElementById('edit-field-dimension-list');
        list.innerHTML = '';
        (config.options || []).forEach(option => {
            // Handle both string and object formats
            let dimValue, dimPrice;
            if (typeof option === 'object' && option.value) {
                dimValue = option.value;
                dimPrice = option.price || 0;
            } else {
                dimValue = option;
                dimPrice = 0;
            }
            
            const parts = dimValue.split('×');
            const w = parts[0] || '';
            const h = parts[1] || '';
            const item = document.createElement('div');
            item.className = 'option-item';
            item.style.cssText = 'display: flex; gap: 8px; align-items: center;';
            item.innerHTML = '<input type="text" class="dimension-width" value="' + w + '" placeholder="Width" maxlength="2" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;"><span style="color: #cbd5e1; font-weight: bold;">×</span><input type="text" class="dimension-height" value="' + h + '" placeholder="Height" maxlength="2" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;"><input type="number" class="dimension-price" value="' + (dimPrice > 0 ? dimPrice : '') + '" placeholder="Price" min="0" step="0.01" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension"><button type="button" class="btn-remove" onclick="removeEditDimensionOption(this)">Remove</button>';
            list.appendChild(item);
        });
    } else {
        optionsSection.style.display = 'none';
        dimensionSection.style.display = 'none';
    }
    
    document.getElementById('editFieldModal').classList.add('active');
    document.body.style.overflow = 'hidden';
};

window.closeEditFieldModal = function() {
    document.getElementById('editFieldModal').classList.remove('active');
    document.body.style.overflow = '';
};

window.deleteField = function(btn) {
    const key = btn.getAttribute('data-field-key');
    document.getElementById('delete-field-key').value = key;
    document.getElementById('deleteFieldModal').classList.add('active');
    document.body.style.overflow = 'hidden';
};

window.closeDeleteFieldModal = function() {
    document.getElementById('deleteFieldModal').classList.remove('active');
    document.body.style.overflow = '';
};

window.confirmDeleteField = function() {
    const key = document.getElementById('delete-field-key').value;
    delete window.fieldConfigurations[key];
    document.getElementById('fieldConfigsInput').value = JSON.stringify(window.fieldConfigurations);
    
    // Ensure save_config is set
    const form = document.getElementById('configForm');
    if (!form.querySelector('input[name="save_config"]')) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'save_config';
        input.value = '1';
        form.appendChild(input);
    }
    form.dataset.skipCollector = '1';
    form.submit();
};

window.toggleFieldRow = function(key) {
    const detailRow = document.getElementById('field-detail-' + key);
    if (detailRow) {
        detailRow.style.display = detailRow.style.display === 'none' ? 'table-row' : 'none';
    }
};

window.toggleNewFieldOptions = function() {
    const type = document.getElementById('new-field-type').value;
    const optionsSection = document.getElementById('new-field-options-section');
    const dimensionSection = document.getElementById('new-field-dimension-section');
    if (optionsSection) optionsSection.style.display = (type === 'select' || type === 'radio') ? 'block' : 'none';
    if (dimensionSection) dimensionSection.style.display = (type === 'dimension') ? 'block' : 'none';
};

window.addOption = function(btn) {
    const list = btn.previousElementSibling;
    const item = document.createElement('div');
    item.className = 'option-item';
    item.style.cssText = 'display:flex;gap:8px;align-items:center;';
    item.innerHTML = '<input type="text" class="option-input" placeholder="Enter option (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)" style="flex:2;"><input type="number" class="option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option"><button type="button" class="btn-remove" onclick="removeOption(this)">Remove</button>';
    list.appendChild(item);
};

window.removeOption = function(btn) {
    btn.closest('.option-item').remove();
};

window.addDimensionOption = function(btn) {
    const list = btn.previousElementSibling;
    const item = document.createElement('div');
    item.className = 'option-item';
    item.style.cssText = 'display: flex; gap: 8px; align-items: center;';
    item.innerHTML = '<input type="text" class="option-input dimension-w" placeholder="Width" maxlength="2" style="flex: 1; text-align: center;"><span style="color: #cbd5e1; font-weight: bold;">×</span><input type="text" class="option-input dimension-h" placeholder="Height" maxlength="2" style="flex: 1; text-align: center;"><input type="number" class="dimension-price-input" placeholder="Price" min="0" step="0.01" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension"><button type="button" class="btn-remove" onclick="removeDimensionOption(this)">Remove</button>';
    list.appendChild(item);
};

window.removeDimensionOption = function(btn) {
    btn.closest('.option-item').remove();
};

window.checkDimensionDuplicates = function(input) {};
window.updateUnitDisplay = function(select) {};

window.addNewFieldOption = function() {
    const list = document.getElementById('new-field-options-list');
    const item = document.createElement('div');
    item.className = 'option-item';
    item.style.cssText = 'display:flex;gap:8px;align-items:center;';
    item.innerHTML = '<input type="text" class="option-input" placeholder="Enter option (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)" style="flex:2;"><input type="number" class="option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option"><button type="button" class="btn-remove" onclick="removeNewFieldOption(this)">Remove</button>';
    list.appendChild(item);
};

window.toggleNewNestedFieldPanel = function(btn, optionIndex) {
    const optionItem = btn.closest('.option-item');
    let nestedPanel = optionItem.nextElementSibling;
    if (!nestedPanel || !nestedPanel.classList.contains('nested-field-panel')) {
        nestedPanel = document.createElement('div');
        nestedPanel.className = 'nested-field-panel';
        nestedPanel.style.cssText = 'margin-top:12px;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;';
        nestedPanel.innerHTML = '<label style="display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:8px;text-transform:uppercase;">Add Nested Fields (Optional)</label><div class="nested-fields-config" style="display:block;"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:8px 0;border-bottom:1px solid #e5e7eb;"><label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;">Nested Fields</label><button type="button" class="btn-add" onclick="addNestedFieldItem(this)" style="padding:4px 8px;font-size:11px;">+ Add Field</button></div><div class="nested-fields-list" style="display:flex;flex-direction:column;gap:12px;"></div></div>';
        optionItem.parentNode.insertBefore(nestedPanel, optionItem.nextSibling);
        btn.textContent = '-'; btn.style.background = '#ef4444';
    } else {
        nestedPanel.style.display = nestedPanel.style.display === 'none' ? 'block' : 'none';
        btn.textContent = nestedPanel.style.display === 'none' ? '+' : '-';
        btn.style.background = nestedPanel.style.display === 'none' ? '#10b981' : '#ef4444';
    }
};

window.handleNewNestedFieldTypeChange = function(select) {
    // This function is no longer needed as we directly show the multi-field interface
    // Keeping for backward compatibility
};

window.removeNewFieldOption = function(btn) {
    btn.closest('.option-item').remove();
};

window.addNewDimensionOption = function() {
    const list = document.getElementById('new-field-dimension-list');
    const item = document.createElement('div');
    item.className = 'option-item';
    item.style.cssText = 'display: flex; gap: 8px; align-items: center;';
    item.innerHTML = '<input type="text" class="dimension-width" placeholder="Width" maxlength="2" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;"><span style="color: #cbd5e1; font-weight: bold;">×</span><input type="text" class="dimension-height" placeholder="Height" maxlength="2" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;"><input type="number" class="dimension-price" placeholder="Price" min="0" step="0.01" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension"><button type="button" class="btn-remove" onclick="removeNewDimensionOption(this)">Remove</button>';
    list.appendChild(item);
};

window.removeNewDimensionOption = function(btn) {
    btn.closest('.option-item').remove();
};

window.addNewField = function() {
    const label = document.getElementById('new-field-label').value.trim();
    const type = document.getElementById('new-field-type').value;
    const required = document.getElementById('new-field-required').checked;
    
    if (!label || !type) {
        alert('Please fill in all required fields');
        return;
    }
    
    let baseKey = label.toLowerCase().replace(/[^a-z0-9]+/g, '_');
    if (!baseKey) baseKey = 'field';
    let key = baseKey;
    let counter = 1;
    while (window.fieldConfigurations[key]) {
        key = baseKey + '_' + counter;
        counter++;
    }
    
    const config = { label, type, required, visible: true, order: Object.keys(window.fieldConfigurations).length };
    
    if (type === 'select' || type === 'radio') {
        const allowOthers = document.getElementById('new-field-options-allow-others').checked;
        config.allow_others = allowOthers;
        const options = [];
        const optionItems = document.querySelectorAll('#new-field-options-list .option-item');
        
        optionItems.forEach((optionItem) => {
            const input = optionItem.querySelector('.option-input');
            const priceInput = optionItem.querySelector('.option-price-input');
            const optionValue = input.value.trim();
            const optionPrice = priceInput ? parseFloat(priceInput.value) || 0 : 0;
            if (!optionValue) return;
            
            // Check for nested fields (they are in the NEXT sibling div if it's radio)
            const nestedPanel = optionItem.nextElementSibling;
            if (nestedPanel && nestedPanel.classList.contains('nested-field-panel')) {
                const nestedFieldItems = nestedPanel.querySelectorAll('.nested-field-item');
                const nestedFields = [];
                
                nestedFieldItems.forEach(nItem => {
                    const nLabel = nItem.querySelector('.nested-label')?.value.trim();
                    const nTypeSelect = nItem.querySelector('.nested-type-select');
                    const nType = nTypeSelect ? nTypeSelect.value : '';
                    const nRequired = nItem.querySelector('.nested-required')?.checked || false;
                    
                    if (nLabel && nType) {
                        const nField = { label: nLabel, type: nType, required: nRequired };
                        
                        // Collect options for nested select/radio
                        if (['select', 'radio'].includes(nType)) {
                            const nOptions = [];
                            nItem.querySelectorAll('.nested-options-list .option-item').forEach(nOptRow => {
                                const inp = nOptRow.querySelector('.nested-option-input');
                                const nOptVal = inp ? inp.value.trim() : '';
                                if (!nOptVal) return;
                                const nPriceInput = nOptRow.querySelector('.nested-option-price-input');
                                const nPrice = nPriceInput ? parseFloat(nPriceInput.value) || 0 : 0;
                                nOptions.push({ value: nOptVal, price: nPrice });
                            });
                            if (nOptions.length > 0) nField.options = nOptions;
                        }
                        
                        // Collect dimensions for nested dimension
                        if (nType === 'dimension') {
                            const nUnitSelect = nItem.querySelector('.nested-unit-select');
                            const nAllowOthers = nItem.querySelector('.nested-allow-others')?.checked;
                            const nDims = [];
                            nItem.querySelectorAll('.nested-dimension-list .option-item').forEach(nDimItem => {
                                const w = nDimItem.querySelector('.dimension-w')?.value.trim();
                                const h = nDimItem.querySelector('.dimension-h')?.value.trim();
                                const priceInput = nDimItem.querySelector('.nested-dimension-price-input');
                                const price = priceInput ? parseFloat(priceInput.value) || 0 : 0;
                                if (w && h) nDims.push({ value: w + '×' + h, price });
                            });
                            if (nDims.length > 0) nField.options = nDims;
                            nField.unit = nUnitSelect ? nUnitSelect.value : 'ft';
                            nField.allow_others = nAllowOthers;
                        }
                        
                        nestedFields.push(nField);
                    }
                });
                
                if (nestedFields.length > 0) {
                    options.push({ value: optionValue, price: optionPrice, nested_fields: nestedFields });
                } else {
                    options.push({ value: optionValue, price: optionPrice });
                }
            } else {
                options.push({ value: optionValue, price: optionPrice });
            }
        });
        
        if (options.length === 0) {
            alert('Please add at least one option');
            return;
        }
        config.options = options;
    }
    
    if (type === 'dimension') {
        const unit = document.getElementById('new-field-unit').value;
        const allowOthers = document.getElementById('new-field-allow-others').checked;
        const dimensions = [];
        document.querySelectorAll('#new-field-dimension-list .option-item').forEach(item => {
            const w = item.querySelector('.dimension-width').value.trim();
            const h = item.querySelector('.dimension-height').value.trim();
            const price = item.querySelector('.dimension-price') ? parseFloat(item.querySelector('.dimension-price').value) || 0 : 0;
            if (w && h) {
                dimensions.push({
                    value: w + '×' + h,
                    price: price
                });
            }
        });
        if (dimensions.length === 0) {
            alert('Please add at least one dimension');
            return;
        }
        config.options = dimensions;
        config.unit = unit;
        config.allow_others = allowOthers;
    }
    
    window.fieldConfigurations[key] = config;
    document.getElementById('fieldConfigsInput').value = JSON.stringify(window.fieldConfigurations);
    
    // Add hidden input to tell PHP to save
    const form = document.getElementById('configForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'save_config';
    input.value = '1';
    form.appendChild(input);
    form.dataset.skipCollector = '1';
    form.submit();
};

window.addEditFieldOption = function() {
    const list = document.getElementById('edit-field-options-list');
    const item = document.createElement('div');
    item.className = 'option-item';
    item.style.cssText = 'display:flex;gap:8px;align-items:center;';
    item.innerHTML = '<input type="text" class="option-input" placeholder="Enter option (32 MAX CHARACTERS)" maxlength="32" oninput="formatTextToTitleCase(this)" style="flex:2;"><input type="number" class="option-price-input" placeholder="Price" min="0" step="0.01" value="0" style="flex:1;padding:9px 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;" title="Price for this option"><button type="button" class="btn-remove" onclick="removeEditFieldOption(this)">Remove</button>';
    list.appendChild(item);
};

window.removeEditFieldOption = function(btn) {
    btn.closest('.option-item').remove();
};

window.addEditDimensionOption = function() {
    const list = document.getElementById('edit-field-dimension-list');
    const item = document.createElement('div');
    item.className = 'option-item';
    item.style.cssText = 'display: flex; gap: 8px; align-items: center;';
    item.innerHTML = '<input type="text" class="dimension-width" placeholder="Width" maxlength="2" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;"><span style="color: #cbd5e1; font-weight: bold;">×</span><input type="text" class="dimension-height" placeholder="Height" maxlength="2" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; text-align: center;"><input type="number" class="dimension-price" placeholder="Price" min="0" step="0.01" style="flex: 1; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;" title="Price for this dimension"><button type="button" class="btn-remove" onclick="removeEditDimensionOption(this)">Remove</button>';
    list.appendChild(item);
};

window.removeEditDimensionOption = function(btn) {
    btn.closest('.option-item').remove();
};

window.saveEditField = function() {
    const key = document.getElementById('edit-field-key').value;
    const label = document.getElementById('edit-field-label').value.trim();
    const type = document.getElementById('edit-field-type').value;
    const required = document.getElementById('edit-field-required').checked;
    
    if (!label) {
        alert('Please enter a field label');
        return;
    }
    
    const config = window.fieldConfigurations[key] || {};
    config.label = label;
    config.required = required;
    config.type = type;
    config.visible = true;
    
    if (type === 'select' || type === 'radio') {
        config.allow_others = document.getElementById('edit-field-options-allow-others').checked;
        const options = [];
        const list = document.getElementById('edit-field-options-list');
        list.querySelectorAll('.option-item').forEach(item => {
            const input = item.querySelector('.option-input');
            const optionValue = input ? input.value.trim() : '';
            if (!optionValue) return;
            
            // Collect nested fields if this is a radio item and has a panel
            const nestedPanel = item.querySelector('.nested-field-panel'); // Check if panel is inside
            // In the edit modal, panels might be siblings or children. Based on toggleNestedFieldPanel, they are CHILDREN of radio-option-item.
            // But wait, in the edit modal, the logic might differ. 
            // Let's check editFieldModal HTML.
        });
        // Actually, let's just use the table collection logic which is more robust
    }
    
    // For Edit, we can just update window.fieldConfigurations and trigger the MAIN form collection
    // but the edit modal elements aren't in the main table yet.
    // Actually, let's keep it simple: just trigger a form submit and let the main listener handle it
    // if the changes were applied to the DOM.
    // BUT the edit modal doesn't update the DOM table yet.
    
    // Better: Collect from modal and update window.fieldConfigurations
    if (type === 'select' || type === 'radio') {
        const options = [];
        document.querySelectorAll('#edit-field-options-list .option-item').forEach(item => {
            const val = item.querySelector('.option-input')?.value.trim();
            const price = item.querySelector('.option-price-input') ? parseFloat(item.querySelector('.option-price-input').value) || 0 : 0;
            if (val) options.push({ value: val, price: price });
        });
        if (options.length === 0) {
            alert('Please add at least one option');
            return;
        }
        config.options = options;
    }
    
    if (type === 'dimension') {
        const unit = document.getElementById('edit-field-unit').value;
        const allowOthers = document.getElementById('edit-field-allow-others').checked;
        const dimensions = [];
        document.querySelectorAll('#edit-field-dimension-list .option-item').forEach(item => {
            const w = item.querySelector('.dimension-width').value.trim();
            const h = item.querySelector('.dimension-height').value.trim();
            const price = item.querySelector('.dimension-price') ? parseFloat(item.querySelector('.dimension-price').value) || 0 : 0;
            if (w && h) {
                dimensions.push({
                    value: w + '×' + h,
                    price: price
                });
            }
        });
        if (dimensions.length === 0) {
            alert('Please add at least one dimension');
            return;
        }
        config.options = dimensions;
        config.unit = unit;
        config.allow_others = allowOthers;
    }

    window.fieldConfigurations[key] = config;
    document.getElementById('fieldConfigsInput').value = JSON.stringify(window.fieldConfigurations);
    
    // Trigger save
    const form = document.getElementById('configForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'save_config';
    input.value = '1';
    form.appendChild(input);
    form.dataset.skipCollector = '1';
    form.submit();
};

document.getElementById('configForm')?.addEventListener('submit', function(e) {
    if (this.dataset.skipCollector === '1') {
        if (!this.querySelector('input[name="save_config"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'save_config';
            input.value = '1';
            this.appendChild(input);
        }
        return;
    }
    // Check if we are already submitting with a specific config
    const configsInput = document.getElementById('fieldConfigsInput');
    
    // If this was triggered by a manual click on "Save Configuration" button
    // we use the robust collector that handles nested fields
    if (typeof collectNestedFieldConfigurations === 'function') {
        const configs = collectNestedFieldConfigurations();
        configsInput.value = JSON.stringify(configs);
    } else {
        // Fallback for simple fields if nested_field_functions.js is missing
        const configs = {};
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
            
            const optionList = card.querySelector('.option-list:not(.dimension-options)');
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

            const prev = window.fieldConfigurations && window.fieldConfigurations[key]
                ? window.fieldConfigurations[key]
                : {};
            configs[key] = { ...prev, ...config };
        });
        configsInput.value = JSON.stringify(configs);
    }
    
    // Ensure save_config is set
    if (!this.querySelector('input[name="save_config"]')) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'save_config';
        input.value = '1';
        this.appendChild(input);
    }
});

window.formatTextToTitleCase = function(input) {
    let val = input.value;
    if (val.length > 0) {
        // Title Case: Capitalize first letter of each word, lowercase the rest
        input.value = val.toLowerCase().split(' ').map(word => {
            return word.charAt(0).toUpperCase() + word.slice(1);
        }).join(' ');
    }
};
</script>
</body>
</html>
