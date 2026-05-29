<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/product_field_config_helper.php';

require_role(['Admin', 'Manager']);

$product_id = (int)($_GET['product_id'] ?? 0);
if ($product_id < 1) {
    header('Location: products_management.php');
    exit;
}

$product_rows = db_query("SELECT * FROM products WHERE product_id = ? LIMIT 1", 'i', [$product_id]) ?: [];
if (empty($product_rows)) {
    header('Location: products_management.php');
    exit;
}

$product = $product_rows[0];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $submitted = json_decode((string)($_POST['field_configs'] ?? '[]'), true);
    if (!is_array($submitted)) {
        $error = 'Invalid field configuration payload.';
    } else {
        $existing = array_keys(get_product_field_config($product_id));
        $incoming = array_keys($submitted);
        foreach (array_diff($existing, $incoming) as $field_key) {
            delete_product_field_config($product_id, (string)$field_key);
        }

        foreach ($submitted as $field_key => $config) {
            if (!is_array($config)) {
                continue;
            }
            $label = trim((string)($config['label'] ?? ''));
            $type = trim((string)($config['type'] ?? ''));
            if ($label === '' || !in_array($type, ['select', 'radio', 'file', 'textarea', 'dimension'], true)) {
                continue;
            }

            $payload = [
                'label' => $label,
                'type' => $type,
                'visible' => !empty($config['visible']),
                'required' => !empty($config['required']),
                'default' => null,
                'unit' => (string)($config['unit'] ?? 'ft'),
                'allow_others' => !array_key_exists('allow_others', $config) || !empty($config['allow_others']),
                'order' => (int)($config['order'] ?? 0),
                'options' => printflow_product_field_normalize_option_rows((array)($config['options'] ?? []), $type),
            ];

            save_product_field_config($product_id, (string)$field_key, $payload);
        }

        $success = 'Product field configuration saved successfully.';
    }
}

$field_configs = get_product_field_config($product_id);
$page_title = 'Configure Product Input Fields - ' . $product['name'];
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
        .orders-table { width:100%; border-collapse:collapse; font-size:13px; }
        .orders-table th { padding:12px 16px; font-weight:600; color:#6b7280; text-align:left; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
        .orders-table td { padding:12px 16px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        .btn-action { display:inline-flex; align-items:center; justify-content:center; padding:5px 12px; min-width:60px; border:1px solid transparent; background:transparent; border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; white-space:nowrap; transition:all 0.2s; }
        .btn-action.blue { color:#3b82f6; border-color:#3b82f6; }
        .btn-action.red { color:#ef4444; border-color:#ef4444; }
        .btn-action.blue:hover { background:#3b82f6; color:#fff; }
        .btn-action.red:hover { background:#ef4444; color:#fff; }
        .toolbar-btn, .btn-save, .btn-cancel, .btn-add, .btn-modal-save, .btn-modal-cancel { border-radius:8px; font-weight:600; transition:all 0.2s; }
        .toolbar-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border:1px solid #3b82f6; color:#3b82f6; background:#fff; }
        .card { padding:20px; border-radius:16px; margin-bottom:16px; border:1px solid #f1f5f9; background:#fff; }
        .info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 16px; margin-bottom:20px; }
        .field-group { margin-bottom:16px; }
        .field-label { display:block; font-size:11px; font-weight:700; color:#6b7280; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px; }
        .field-input, .option-input { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; }
        .option-item { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
        .btn-add { padding:9px 16px; background:#f0fdfa; color:#0d9488; border:1px solid #0d9488; }
        .btn-save, .btn-modal-save { padding:10px 16px; background:#0d9488; color:#fff; border:none; }
        .btn-cancel, .btn-modal-cancel { padding:10px 16px; background:#f3f4f6; color:#374151; border:none; }
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:16px; }
        .modal-overlay.active { display:flex; }
        .modal-content { background:#fff; border-radius:12px; max-width:560px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 25px 50px rgba(0,0,0,0.25); }
        .modal-header, .modal-footer { padding:18px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
        .modal-footer { border-top:1px solid #e5e7eb; border-bottom:none; gap:12px; justify-content:flex-end; }
        .modal-body { padding:20px; }
        .btn-close { background:none; border:none; font-size:24px; color:#9ca3af; cursor:pointer; }
        .status-pill { display:inline-block; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; }
        .toggle-row { display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
        .inline-check { display:flex; align-items:center; gap:8px; font-size:14px; color:#374151; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <div class="main-content" style="padding:12px 12px 0;">
        <header>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                <button type="button" onclick="window.location.href='products_management.php'" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:none;background:transparent;cursor:pointer;padding:0;">
                    <svg style="width:24px;height:24px;color:#6b7280;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </button>
                <h1 class="page-title" style="margin:0;">Configure Product Input Fields</h1>
            </div>
            <p style="color:#6b7280;font-size:14px;margin-top:8px;">Product: <strong><?php echo htmlspecialchars($product['name']); ?></strong></p>
        </header>
        <main>
            <?php if ($success !== ''): ?><div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;padding:12px 16px;border-radius:8px;margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <div class="info-box">
                <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.5;">
                    Configure flexible customer choices for this product such as size, color, material, finish, print layout, or design uploads.
                </p>
            </div>

            <div class="card">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Input Fields</h3>
                    <button type="button" class="toolbar-btn" onclick="openFieldModal()">+ Add Field</button>
                </div>

                <form method="POST" id="configForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="field_configs" id="fieldConfigsInput">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Field Label</th>
                                <th>Field Type</th>
                                <th style="text-align:center;">Required</th>
                                <th style="text-align:center;">Visibility</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fieldsTableBody">
                            <?php if (empty($field_configs)): ?>
                                <tr><td colspan="5" style="padding:40px;text-align:center;color:#9ca3af;">No custom product fields configured yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($field_configs as $key => $config): ?>
                                    <tr>
                                        <td style="font-weight:500;color:#1f2937;"><?php echo htmlspecialchars($config['label']); ?></td>
                                        <td><span class="status-pill" style="background:#e0e7ff;color:#4338ca;"><?php echo htmlspecialchars($config['type']); ?></span></td>
                                        <td style="text-align:center;color:<?php echo !empty($config['required']) ? '#059669' : '#6b7280'; ?>;"><?php echo !empty($config['required']) ? 'Required' : 'Optional'; ?></td>
                                        <td style="text-align:center;color:<?php echo !empty($config['visible']) ? '#059669' : '#6b7280'; ?>;"><?php echo !empty($config['visible']) ? 'Visible' : 'Hidden'; ?></td>
                                        <td style="text-align:right;white-space:nowrap;">
                                            <button type="button" class="btn-action blue" onclick="openFieldModal('<?php echo htmlspecialchars($key, ENT_QUOTES); ?>')">Edit</button>
                                            <button type="button" class="btn-action red" onclick="deleteField('<?php echo htmlspecialchars($key, ENT_QUOTES); ?>')">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:24px;padding-top:24px;border-top:1px solid #e5e7eb;">
                        <button type="button" class="btn-cancel" onclick="window.location.href='products_management.php'">Back</button>
                        <button type="submit" class="btn-save">Save Configuration</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<div id="fieldModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="fieldModalTitle" style="margin:0;font-size:18px;font-weight:700;color:#1f2937;">Add New Field</h3>
            <button type="button" class="btn-close" onclick="closeFieldModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="modal-field-key">
            <div class="field-group">
                <label class="field-label">Field Label</label>
                <input type="text" id="modal-field-label" class="field-input" maxlength="32" placeholder="e.g., Size">
            </div>
            <div class="field-group">
                <label class="field-label">Field Type</label>
                <select id="modal-field-type" class="field-input" onchange="toggleModalOptions()">
                    <option value="">-- Select Type --</option>
                    <option value="select">Select (Dropdown)</option>
                    <option value="dimension">Dimension (Size)</option>
                    <option value="radio">Radio Buttons</option>
                    <option value="file">File Upload</option>
                    <option value="textarea">Textarea (Multi-line)</option>
                </select>
            </div>
            <div class="field-group" id="modalUnitGroup" style="display:none;">
                <label class="field-label">Measurement Unit</label>
                <select id="modal-field-unit" class="field-input">
                    <option value="ft">Feet (ft)</option>
                    <option value="in">Inches (in)</option>
                    <option value="cm">Centimeters (cm)</option>
                </select>
            </div>
            <div class="field-group" id="modalOptionsGroup" style="display:none;">
                <div class="toggle-row" style="margin-bottom:12px;">
                    <label class="inline-check"><input type="checkbox" id="modal-allow-others" checked> Allow "Others"</label>
                </div>
                <label class="field-label">Field Options</label>
                <div id="modalOptionsList"></div>
                <button type="button" class="btn-add" onclick="addOptionRow()">+ Add Option</button>
            </div>
            <div class="field-group">
                <div class="toggle-row">
                    <label class="inline-check"><input type="checkbox" id="modal-field-required" checked> Required</label>
                    <label class="inline-check"><input type="checkbox" id="modal-field-visible" checked> Visible</label>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeFieldModal()">Cancel</button>
            <button type="button" class="btn-modal-save" onclick="saveFieldFromModal()">Save Field</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
const fieldConfigurations = <?php echo json_encode($field_configs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> || {};

function optionRowHtml(value = '', price = '0') {
    return `<div class="option-item">
        <input type="text" class="option-input option-value" maxlength="32" placeholder="Option label" value="${value.replace(/"/g, '&quot;')}">
        <input type="number" class="field-input option-price" min="0" step="0.01" style="max-width:120px;" placeholder="Price" value="${price}">
        <button type="button" class="btn-action red" onclick="this.parentNode.remove()">Remove</button>
    </div>`;
}

function addOptionRow(value = '', price = '0') {
    const list = document.getElementById('modalOptionsList');
    list.insertAdjacentHTML('beforeend', optionRowHtml(value, price));
}

function toggleModalOptions() {
    const type = document.getElementById('modal-field-type').value;
    document.getElementById('modalOptionsGroup').style.display = (type === 'select' || type === 'radio' || type === 'dimension') ? 'block' : 'none';
    document.getElementById('modalUnitGroup').style.display = type === 'dimension' ? 'block' : 'none';
    document.getElementById('modal-allow-others').checked = true;
}

function openFieldModal(fieldKey = '') {
    document.getElementById('fieldModal').classList.add('active');
    document.getElementById('modal-field-key').value = fieldKey;
    document.getElementById('modalOptionsList').innerHTML = '';

    if (fieldKey && fieldConfigurations[fieldKey]) {
        const config = fieldConfigurations[fieldKey];
        document.getElementById('fieldModalTitle').textContent = 'Edit Field';
        document.getElementById('modal-field-label').value = config.label || '';
        document.getElementById('modal-field-type').value = config.type || '';
        document.getElementById('modal-field-unit').value = config.unit || 'ft';
        document.getElementById('modal-field-required').checked = !!config.required;
        document.getElementById('modal-field-visible').checked = !!config.visible;
        document.getElementById('modal-allow-others').checked = config.allow_others !== false;
        toggleModalOptions();
        (config.options || []).forEach(option => addOptionRow(option.value || '', option.price || '0'));
    } else {
        document.getElementById('fieldModalTitle').textContent = 'Add New Field';
        document.getElementById('modal-field-label').value = '';
        document.getElementById('modal-field-type').value = '';
        document.getElementById('modal-field-unit').value = 'ft';
        document.getElementById('modal-field-required').checked = true;
        document.getElementById('modal-field-visible').checked = true;
        document.getElementById('modal-allow-others').checked = true;
        toggleModalOptions();
        addOptionRow();
    }
}

function closeFieldModal() {
    document.getElementById('fieldModal').classList.remove('active');
}

function deleteField(fieldKey) {
    if (!confirm('Delete this field?')) {
        return;
    }
    delete fieldConfigurations[fieldKey];
    syncFieldConfigs();
}

function saveFieldFromModal() {
    const label = document.getElementById('modal-field-label').value.trim();
    const type = document.getElementById('modal-field-type').value;
    if (!label || !type) {
        alert('Please complete the field label and type.');
        return;
    }

    let key = document.getElementById('modal-field-key').value.trim();
    if (!key) {
        key = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'field';
        let suffix = 1;
        const base = key;
        while (fieldConfigurations[key]) {
            key = `${base}_${suffix++}`;
        }
    }

    const options = [];
    if (type === 'select' || type === 'radio' || type === 'dimension') {
        document.querySelectorAll('#modalOptionsList .option-item').forEach(row => {
            const value = row.querySelector('.option-value').value.trim();
            const price = parseFloat(row.querySelector('.option-price').value || '0') || 0;
            if (value) {
                options.push({ value, price });
            }
        });
        if (options.length === 0) {
            alert('Please add at least one option.');
            return;
        }
    }

    fieldConfigurations[key] = {
        label,
        type,
        options,
        required: document.getElementById('modal-field-required').checked,
        visible: document.getElementById('modal-field-visible').checked,
        allow_others: document.getElementById('modal-allow-others').checked,
        unit: document.getElementById('modal-field-unit').value,
        order: Object.keys(fieldConfigurations).indexOf(key) >= 0 ? fieldConfigurations[key].order : Object.keys(fieldConfigurations).length
    };

    closeFieldModal();
    syncFieldConfigs();
}

function syncFieldConfigs() {
    const ordered = {};
    Object.keys(fieldConfigurations).forEach((key, index) => {
        ordered[key] = { ...fieldConfigurations[key], order: index };
    });
    document.getElementById('fieldConfigsInput').value = JSON.stringify(ordered);
    document.getElementById('configForm').submit();
}

document.getElementById('configForm').addEventListener('submit', function() {
    const ordered = {};
    Object.keys(fieldConfigurations).forEach((key, index) => {
        ordered[key] = { ...fieldConfigurations[key], order: index };
    });
    document.getElementById('fieldConfigsInput').value = JSON.stringify(ordered);
});
</script>
</body>
</html>
