<?php
/**
 * Complete Profile - New staff completes their profile via email link
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';
$success = '';
$token = trim($_GET['token'] ?? '');

// Preserve form data
$form_data = [
    'contact_number' => $_POST['contact_number'] ?? '',
    'address_province' => $_POST['address_province'] ?? '',
    'address_city' => $_POST['address_city'] ?? '',
    'address_barangay' => $_POST['address_barangay'] ?? '',
    'address_line' => $_POST['address_line'] ?? '',
    'gender' => $_POST['gender'] ?? '',
    'id_type' => $_POST['id_type'] ?? '',
    'id_filename' => ''
];

$id_type_options = [
    'Philippine Passport',
    "Driver's License",
    'SSS ID',
    'PhilHealth ID',
    'Postal ID',
    "Voter's ID",
    'PRC ID',
    'National ID (PhilSys)',
    'UMID',
    'Senior Citizen ID',
    'PWD ID',
    'Barangay ID',
];

// Preserve uploaded file name across form submissions
if (!empty($_FILES['id_image']['name'])) {
    $form_data['id_filename'] = $_FILES['id_image']['name'];
    // Store in session for persistence
    $_SESSION['temp_id_filename'] = $_FILES['id_image']['name'];
} elseif (!empty($_SESSION['temp_id_filename'])) {
    $form_data['id_filename'] = $_SESSION['temp_id_filename'];
}

if (empty($token)) {
    $error = 'Invalid or missing link. Please use the link from your email.';
    $user = null;
} else {
    // First, ensure the column exists
    try {
        $columns = db_query("SHOW COLUMNS FROM users LIKE 'profile_completion_fields_to_clear'");
        if (empty($columns)) {
            db_execute("ALTER TABLE users ADD COLUMN profile_completion_fields_to_clear TEXT NULL AFTER profile_completion_expires");
        }
        $idTypeColumn = db_query("SHOW COLUMNS FROM users LIKE 'id_type'");
        if (empty($idTypeColumn)) {
            db_execute("ALTER TABLE users ADD COLUMN id_type VARCHAR(100) NULL AFTER id_validation_image");
        }
    } catch (Exception $e) {
        // Column might already exist or error adding it
    }
    
    $user = db_query("SELECT user_id, first_name, middle_name, last_name, email, contact_number, address, gender, id_type, id_validation_image, profile_completion_token, profile_completion_expires, profile_completion_fields_to_clear, status FROM users WHERE profile_completion_token = ?", 's', [$token]);
    $user = $user[0] ?? null;

    if (!$user) {
        $error = 'Invalid or expired link. Please contact your administrator.';
    } elseif (strtotime($user['profile_completion_expires'] ?? '') < time()) {
        $error = 'This link has expired. Please contact your administrator for a new link.';
    } else {
        // Load existing data and clear only specified fields
        $fields_to_clear = [];
        if (!empty($user['profile_completion_fields_to_clear'])) {
            $fields_to_clear = json_decode($user['profile_completion_fields_to_clear'], true) ?: [];
        }
        
        // Pre-fill form data from database, clearing only checked fields
        if (empty($_POST)) {
            // Parse address
            $existingAddr = trim($user['address'] ?? '');
            if (!in_array('address', $fields_to_clear) && $existingAddr) {
                $parts = array_values(array_filter(array_map('trim', explode(',', $existingAddr)), static fn($p) => $p !== ''));
                if (count($parts) >= 4 && strcasecmp(end($parts), 'Philippines') === 0) {
                    $form_data['address_province'] = $parts[count($parts) - 2] ?? '';
                    $form_data['address_city'] = $parts[count($parts) - 3] ?? '';
                    $form_data['address_barangay'] = preg_replace('/^Brgy\.?\s*/i', '', (string)($parts[count($parts) - 4] ?? ''));
                    $form_data['address_line'] = implode(', ', array_slice($parts, 0, -4));
                }
            }
            
            // Pre-fill contact number if not marked for clearing
            if (!in_array('contact', $fields_to_clear) && !empty($user['contact_number'])) {
                $form_data['contact_number'] = $user['contact_number'];
            }
            
            // Pre-fill gender
            if (!empty($user['gender'])) {
                $form_data['gender'] = $user['gender'];
            }

            if (!empty($user['id_type'])) {
                $form_data['id_type'] = $user['id_type'];
            }
            
            // Keep ID image if not marked for clearing
            if (!in_array('id_image', $fields_to_clear) && !empty($user['id_validation_image'])) {
                $form_data['id_filename'] = $user['id_validation_image'];
                $_SESSION['temp_id_filename'] = $user['id_validation_image'];
            }
        }
    }
}

$max_birthday = date('Y-m-d', strtotime('-18 years'));

$province_options = [];
$province_cache = __DIR__ . '/../tmp/psgc_provinces_fallback.json';
if (is_file($province_cache)) {
    $cached_provinces = json_decode((string)file_get_contents($province_cache), true);
    if (is_array($cached_provinces)) {
        $province_options = $cached_provinces;
    }
}

if (empty($province_options)) {
    $province_options = [
        ['code' => '140100000', 'name' => 'Abra'],
        ['code' => '160200000', 'name' => 'Agusan Del Norte'],
        ['code' => '160300000', 'name' => 'Agusan Del Sur'],
        ['code' => '060400000', 'name' => 'Aklan'],
        ['code' => '050500000', 'name' => 'Albay'],
        ['code' => '060600000', 'name' => 'Antique'],
        ['code' => '148100000', 'name' => 'Apayao'],
        ['code' => '037700000', 'name' => 'Aurora'],
        ['code' => '150700000', 'name' => 'Basilan'],
        ['code' => '030800000', 'name' => 'Bataan'],
        ['code' => '020900000', 'name' => 'Batanes'],
        ['code' => '041000000', 'name' => 'Batangas'],
        ['code' => '141100000', 'name' => 'Benguet'],
        ['code' => '087800000', 'name' => 'Biliran'],
        ['code' => '071200000', 'name' => 'Bohol'],
        ['code' => '101300000', 'name' => 'Bukidnon'],
        ['code' => '031400000', 'name' => 'Bulacan'],
        ['code' => '021500000', 'name' => 'Cagayan'],
        ['code' => '051600000', 'name' => 'Camarines Norte'],
        ['code' => '051700000', 'name' => 'Camarines Sur'],
        ['code' => '101800000', 'name' => 'Camiguin'],
        ['code' => '061900000', 'name' => 'Capiz'],
        ['code' => '052000000', 'name' => 'Catanduanes'],
        ['code' => '042100000', 'name' => 'Cavite'],
        ['code' => '072200000', 'name' => 'Cebu'],
        ['code' => '124700000', 'name' => 'Cotabato'],
        ['code' => '118200000', 'name' => 'Davao De Oro'],
        ['code' => '112300000', 'name' => 'Davao Del Norte'],
        ['code' => '112400000', 'name' => 'Davao Del Sur'],
        ['code' => '118600000', 'name' => 'Davao Occidental'],
        ['code' => '112500000', 'name' => 'Davao Oriental'],
        ['code' => '168500000', 'name' => 'Dinagat Islands'],
        ['code' => '082600000', 'name' => 'Eastern Samar'],
        ['code' => '067900000', 'name' => 'Guimaras'],
        ['code' => '142700000', 'name' => 'Ifugao'],
        ['code' => '012800000', 'name' => 'Ilocos Norte'],
        ['code' => '012900000', 'name' => 'Ilocos Sur'],
        ['code' => '063000000', 'name' => 'Iloilo'],
        ['code' => '023100000', 'name' => 'Isabela'],
        ['code' => '143200000', 'name' => 'Kalinga'],
        ['code' => '013300000', 'name' => 'La Union'],
        ['code' => '043400000', 'name' => 'Laguna'],
        ['code' => '103500000', 'name' => 'Lanao Del Norte'],
        ['code' => '153600000', 'name' => 'Lanao Del Sur'],
        ['code' => '083700000', 'name' => 'Leyte'],
        ['code' => '153800000', 'name' => 'Maguindanao'],
        ['code' => '174000000', 'name' => 'Marinduque'],
        ['code' => '054100000', 'name' => 'Masbate'],
        ['code' => '104200000', 'name' => 'Misamis Occidental'],
        ['code' => '104300000', 'name' => 'Misamis Oriental'],
        ['code' => '144400000', 'name' => 'Mountain Province'],
        ['code' => '064500000', 'name' => 'Negros Occidental'],
        ['code' => '074600000', 'name' => 'Negros Oriental'],
        ['code' => '084800000', 'name' => 'Northern Samar'],
        ['code' => '034900000', 'name' => 'Nueva Ecija'],
        ['code' => '025000000', 'name' => 'Nueva Vizcaya'],
        ['code' => '175100000', 'name' => 'Occidental Mindoro'],
        ['code' => '175200000', 'name' => 'Oriental Mindoro'],
        ['code' => '175300000', 'name' => 'Palawan'],
        ['code' => '035400000', 'name' => 'Pampanga'],
        ['code' => '015500000', 'name' => 'Pangasinan'],
        ['code' => '045600000', 'name' => 'Quezon'],
        ['code' => '025700000', 'name' => 'Quirino'],
        ['code' => '045800000', 'name' => 'Rizal'],
        ['code' => '175900000', 'name' => 'Romblon'],
        ['code' => '086000000', 'name' => 'Samar'],
        ['code' => '128000000', 'name' => 'Sarangani'],
        ['code' => '076100000', 'name' => 'Siquijor'],
        ['code' => '056200000', 'name' => 'Sorsogon'],
        ['code' => '126300000', 'name' => 'South Cotabato'],
        ['code' => '086400000', 'name' => 'Southern Leyte'],
        ['code' => '126500000', 'name' => 'Sultan Kudarat'],
        ['code' => '156600000', 'name' => 'Sulu'],
        ['code' => '166700000', 'name' => 'Surigao Del Norte'],
        ['code' => '166800000', 'name' => 'Surigao Del Sur'],
        ['code' => '036900000', 'name' => 'Tarlac'],
        ['code' => '157000000', 'name' => 'Tawi-Tawi'],
        ['code' => '037100000', 'name' => 'Zambales'],
        ['code' => '097200000', 'name' => 'Zamboanga Del Norte'],
        ['code' => '097300000', 'name' => 'Zamboanga Del Sur'],
        ['code' => '098300000', 'name' => 'Zamboanga Sibugay'],
    ];
}

// Parse existing address for edit
$addressProvince = $addressCity = $addressBarangay = $addressLine = '';
if ($user && !empty($_POST)) {
    $existingAddr = trim($_POST['address'] ?? '');
    if ($existingAddr) {
        $parts = array_values(array_filter(array_map('trim', explode(',', $existingAddr)), static fn($p) => $p !== ''));
        if (count($parts) >= 4 && strcasecmp(end($parts), 'Philippines') === 0) {
            $addressProvince = $parts[count($parts) - 2] ?? '';
            $addressCity = $parts[count($parts) - 3] ?? '';
            $addressBarangay = preg_replace('/^Brgy\.?\s*/i', '', (string)($parts[count($parts) - 4] ?? ''));
            $addressLine = implode(', ', array_slice($parts, 0, -4));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    // Ensure column exists before POST processing
    try {
        $columns = db_query("SHOW COLUMNS FROM users LIKE 'profile_completion_fields_to_clear'");
        if (empty($columns)) {
            db_execute("ALTER TABLE users ADD COLUMN profile_completion_fields_to_clear TEXT NULL AFTER profile_completion_expires");
        }
        $idTypeColumn = db_query("SHOW COLUMNS FROM users LIKE 'id_type'");
        if (empty($idTypeColumn)) {
            db_execute("ALTER TABLE users ADD COLUMN id_type VARCHAR(100) NULL AFTER id_validation_image");
        }
    } catch (Exception $e) {
        // Column might already exist
    }
    
    $submit_token = trim($_POST['token'] ?? $_GET['token'] ?? '');
    if (empty($submit_token)) {
        $error = 'Invalid or expired link. Please use the link from your email.';
        $user = null;
    } else {
        $user = db_query("SELECT user_id, first_name, middle_name, last_name, email, profile_completion_token, profile_completion_expires FROM users WHERE profile_completion_token = ?", 's', [$submit_token]);
        $user = $user[0] ?? null;
        if (!$user) {
            $error = 'Invalid or expired link. Please contact your administrator for a new link.';
        } elseif (strtotime($user['profile_completion_expires'] ?? '') < time()) {
            $error = 'This link has expired. Please contact your administrator for a new link.';
            $user = null;
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $contact_number = preg_replace('/[^0-9]/', '', trim($_POST['contact_number'] ?? ''));
    $address_province = trim($_POST['address_province'] ?? '');
    $address_city = trim($_POST['address_city'] ?? '');
    $address_barangay = trim($_POST['address_barangay'] ?? '');
    $address_line = trim($_POST['address_line'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $id_type = trim($_POST['id_type'] ?? '');

    $addressParts = [];
    if ($address_line !== '') $addressParts[] = $address_line;
    if ($address_barangay !== '') $addressParts[] = 'Brgy. ' . $address_barangay;
    if ($address_city !== '') $addressParts[] = $address_city;
    if ($address_province !== '') $addressParts[] = $address_province;
    $addressParts[] = 'Philippines';
    $address = implode(', ', $addressParts);

    if (empty($contact_number) || !preg_match('/^09\d{9}$/', $contact_number)) {
        $error = 'Valid contact number required (09XXXXXXXXX).';
    } elseif (strlen($address) < 10) {
        $error = 'Please complete the address (province, city, barangay).';
    } elseif ($id_type === '' || !in_array($id_type, $id_type_options, true)) {
        $error = 'Please select a valid ID type.';
    } else {
        // Check if ID image is uploaded, or reuse an existing real saved ID file.
        $hasIdImage = !empty($_FILES['id_image']['tmp_name']) && $_FILES['id_image']['error'] === UPLOAD_ERR_OK;
        $previousIdFilename = trim((string)($user['id_validation_image'] ?? ''));
        $previousIdPath = $previousIdFilename !== '' ? __DIR__ . '/../uploads/ids/' . basename($previousIdFilename) : '';
        $hasPreviousId = $previousIdFilename !== ''
            && !preg_match('/^pending_\d+\.jpg$/i', $previousIdFilename)
            && is_file($previousIdPath);
        
        if (!$hasIdImage && !$hasPreviousId) {
            unset($_SESSION['temp_id_filename']);
            $error = 'Please upload your ID image.';
        } else {
            // Use newly uploaded file or skip validation if already uploaded
            if ($hasIdImage) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['id_image']['tmp_name']);
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($mime, $allowed)) {
                    $error = 'ID image must be JPG, PNG, GIF, or WEBP.';
                } elseif ($_FILES['id_image']['size'] > 5 * 1024 * 1024) {
                    $error = 'ID image must be under 5MB.';
                }
            }
            
            if (!$error && contact_phone_in_use_across_accounts($contact_number, null, (int)$user['user_id'])) {
                $error = 'This phone number is already used by another account.';
            }
            
            if (!$error) {
                // Process file upload only if new file is provided
                if ($hasIdImage) {
                    $ext = pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION) ?: 'jpg';
                    $filename = 'id_user_' . $user['user_id'] . '_' . time() . '.' . $ext;
                    $upload_dir = __DIR__ . '/../uploads/ids/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $filepath = $upload_dir . $filename;

                    if (!move_uploaded_file($_FILES['id_image']['tmp_name'], $filepath)) {
                        $error = 'Failed to save ID image. Please try again.';
                    }
                } else {
                    $filename = basename($previousIdFilename);
                }
                
                if (!$error) {
                    db_execute(
                        "UPDATE users SET contact_number=?, address=?, gender=?, id_type=?, id_validation_image=?, profile_completion_token=NULL, profile_completion_expires=NULL, status='Pending', updated_at=NOW() WHERE user_id=?",
                        'sssssi',
                        [$contact_number, $address, $gender, $id_type, $filename, $user['user_id']]
                    );

                    $full_name = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $msg = $full_name . ' (' . $user['email'] . ') has completed their profile and is ready for admin review.';
                    $admins = db_query("SELECT user_id, role FROM users WHERE role = 'Admin' AND status = 'Activated'");
                    foreach ($admins as $a) {
                        $recipType = $a['role'] ?? 'Admin';
                        create_notification((int)$a['user_id'], $recipType, $msg, 'System', true, false, (int)$user['user_id']);
                    }

                    $success = 'Profile submitted successfully! An admin will review your information and activate your account. You will be notified once your account is activated.';
                    $user = null;
                    // Clear session temp data on success
                    unset($_SESSION['temp_id_filename']);
                }
            }
        }
    }
}

$page_title = 'Complete Your Profile - PrintFlow';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php include __DIR__ . '/../includes/favicon_links.php'; ?>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <script src="<?php echo $base_path; ?>/public/assets/js/alpine.min.js" defer></script>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 560px; width: 100%; overflow: hidden; }
        .card-header { background: linear-gradient(135deg, #0d9488, #0f766e); color: #fff; padding: 24px; text-align: center; }
        .card-header h1 { font-size: 20px; font-weight: 700; margin: 0 0 4px 0; }
        .card-header p { font-size: 14px; opacity: 0.9; margin: 0; }
        .card-body { padding: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #0d9488; }
        .form-group.is-invalid input, .form-group.is-invalid select { border-color: #ef4444; }
        .error-msg { font-size: 12px; color: #ef4444; margin-top: 4px; display: none; }
        .form-group.is-invalid .error-msg { display: block; }
        .btn { display: block; width: 100%; padding: 12px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; text-align: center; transition: all 0.2s; position: relative; }
        .btn-primary { background: #0d9488; color: #fff; }
        .btn-primary:hover:not(:disabled) { background: #0f766e; }
        .btn-primary:disabled { background: #9ca3af; cursor: not-allowed; opacity: 0.7; }
        .btn-loading { pointer-events: none; }
        .btn-loading .btn-text { opacity: 0; }
        .btn-loading .spinner { display: inline-block; }
        .spinner { display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 20px; height: 20px; border: 3px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: translate(-50%, -50%) rotate(360deg); } }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .id-reference { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .id-reference h3 { font-size: 13px; font-weight: 600; color: #374151; margin: 0 0 8px 0; }
        .id-reference img { max-width: 100%; height: auto; border-radius: 6px; }
        .id-upload { border: 2px dashed #e5e7eb; border-radius: 8px; padding: 24px; text-align: center; cursor: pointer; transition: border-color 0.2s; }
        .id-upload:hover { border-color: #0d9488; }
        .id-upload input { display: none; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h1>Complete Your Profile</h1>
        <p><?php echo $user ? 'Welcome, ' . htmlspecialchars($user['first_name']) . '!' : 'PrintFlow Staff'; ?></p>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <p style="text-align:center; margin-top:16px; color:#6b7280; font-size:14px;">You can close this page. We will notify you when your account is activated.</p>
        <?php elseif ($user): ?>
        <form method="POST" enctype="multipart/form-data" id="completeForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($user['profile_completion_token'] ?? $token); ?>">

            <div class="form-group">
                <label>Contact Number *</label>
                <input type="text" name="contact_number" id="contact_number" placeholder="e.g. 09171234567" required maxlength="11" value="<?php echo htmlspecialchars($form_data['contact_number'] ?: '09'); ?>">
                <div class="error-msg" id="err_contact">Valid 11-digit number starting with 09.</div>
            </div>

            <div class="form-group">
                <label>Province *</label>
                <select name="address_province" id="address_province" required data-selected="<?php echo htmlspecialchars($form_data['address_province']); ?>">
                    <option value="">Select province</option>
                    <?php foreach ($province_options as $province): ?>
                        <?php
                            $province_name = (string)($province['name'] ?? '');
                            $province_code = (string)($province['code'] ?? '');
                        ?>
                        <?php if ($province_name !== '' && $province_code !== ''): ?>
                        <option value="<?php echo htmlspecialchars($province_name); ?>" data-code="<?php echo htmlspecialchars($province_code); ?>" <?php echo strcasecmp($province_name, $form_data['address_province']) === 0 ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($province_name); ?>
                        </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>City / Municipality *</label>
                <select name="address_city" id="address_city" required disabled data-selected="<?php echo htmlspecialchars($form_data['address_city']); ?>">
                    <option value="">Select city/municipality</option>
                </select>
            </div>
            <div class="form-group">
                <label>Barangay *</label>
                <select name="address_barangay" id="address_barangay" required disabled data-selected="<?php echo htmlspecialchars($form_data['address_barangay']); ?>">
                    <option value="">Select barangay</option>
                </select>
            </div>
            <div class="form-group">
                <label>Street / House No. (Optional)</label>
                <input type="text" name="address_line" id="address_line" maxlength="120" placeholder="e.g. 123 Rizal St." value="<?php echo htmlspecialchars($form_data['address_line']); ?>">
            </div>
            <input type="hidden" name="address" id="address" value="">

            <div class="form-group">
                <label>Gender</label>
                <select name="gender">
                    <option value="">-- Select --</option>
                    <option value="Male" <?php echo $form_data['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo $form_data['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                </select>
            </div>

            <div class="id-reference">
                <h3>ID Photo Reference – Upload a clear, valid ID (not blurred)</h3>
                <img src="<?php echo $base_path; ?>/uploads/id_validation.png" alt="Valid vs Invalid ID" style="max-width:100%;">
            </div>

            <div class="form-group">
                <label>ID Type *</label>
                <select name="id_type" id="id_type" required>
                    <option value="">-- Select ID Type --</option>
                    <?php foreach ($id_type_options as $idt): ?>
                    <option value="<?php echo htmlspecialchars($idt); ?>" <?php echo $form_data['id_type'] === $idt ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($idt); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Upload Valid ID *</label>
                <label class="id-upload" for="id_image">
                    <input type="file" name="id_image" id="id_image" accept="image/*" required>
                    <span id="id_label"><?php echo !empty($form_data['id_filename']) ? htmlspecialchars($form_data['id_filename']) : 'Click to select ID image (JPG, PNG, max 5MB)'; ?></span>
                </label>
                <?php if (!empty($form_data['id_filename'])): ?>
                <input type="hidden" id="previous_filename" value="<?php echo htmlspecialchars($form_data['id_filename']); ?>">
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary" id="submitBtn" style="margin-top:20px;">
                <span class="btn-text">Complete Profile</span>
                <span class="spinner"></span>
            </button>
        </form>
        <?php elseif (!$success): ?>
        <p style="text-align:center; color:#6b7280; margin-bottom:16px;">Please use the link from your email to complete your profile.</p>
        <p style="text-align:center; font-size:14px; color:#374151; margin-bottom:12px;">If your link has expired, you can log in with your email and default password (email + birthday MMDDYYYY) to complete your profile from the staff portal.</p>
        <p style="text-align:center;">
            <a href="<?php echo $base_path; ?>/?auth_modal=login" style="display:inline-block; padding:12px 24px; background:#0d9488; color:#fff; text-decoration:none; border-radius:8px; font-weight:600;">Go to Login</a>
        </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($user): ?>
<script>
(function() {
    const addrApi = 'api_address_public.php';
    const prov = document.getElementById('address_province');
    const city = document.getElementById('address_city');
    const brgy = document.getElementById('address_barangay');
    const line = document.getElementById('address_line');
    const addrHidden = document.getElementById('address');
    let provincesData = [];

    function buildAddress() {
        const p = [line.value.trim(), brgy.value ? 'Brgy. ' + brgy.value : '', city.value, prov.value].filter(Boolean);
        addrHidden.value = p.length ? p.join(', ') + ', Philippines' : '';
    }

    async function loadProvinces() {
        try {
            const r = await fetch(addrApi + '?address_action=provinces');
            const d = await r.json();
            if (d.success && d.data) {
                provincesData = d.data;
                prov.innerHTML = '<option value="">Select province</option>' + d.data.map(x => '<option value="' + x.name + '" data-code="' + x.code + '">' + x.name + '</option>').join('');

                // Restore selected province
                const selectedProv = prov.getAttribute('data-selected');
                if (selectedProv) {
                    prov.value = selectedProv;
                    const opt = prov.options[prov.selectedIndex];
                    const code = opt && opt.value ? opt.getAttribute('data-code') : '';
                    if (code) await loadCities(code);
                }
            } else {
                prov.innerHTML = '<option value="">Unable to load provinces</option>';
            }
        } catch (error) {
            console.error('Address API error:', error);
            prov.innerHTML = '<option value="">Unable to load provinces</option>';
        }
    }
    async function loadCities(provinceCode) {
        if (!provinceCode) { city.innerHTML = '<option value="">Select city/municipality</option>'; city.disabled = true; brgy.innerHTML = '<option value="">Select barangay</option>'; brgy.disabled = true; buildAddress(); return; }
        const r = await fetch(addrApi + '?address_action=cities&province_code=' + encodeURIComponent(provinceCode));
        const d = await r.json();
        if (d.success && d.data) {
            city.innerHTML = '<option value="">Select city/municipality</option>' + d.data.map(x => '<option value="' + x.name + '" data-code="' + x.code + '">' + x.name + '</option>').join('');
            city.disabled = false;
            brgy.innerHTML = '<option value="">Select barangay</option>';
            brgy.disabled = true;
            
            // Restore selected city
            const selectedCity = city.getAttribute('data-selected');
            if (selectedCity) {
                city.value = selectedCity;
                const opt = city.options[city.selectedIndex];
                const code = opt && opt.value ? opt.getAttribute('data-code') : '';
                if (code) await loadBarangays(code);
            }
        }
        buildAddress();
    }
    async function loadBarangays(cityCode) {
        if (!cityCode) { brgy.innerHTML = '<option value="">Select barangay</option>'; brgy.disabled = true; buildAddress(); return; }
        const r = await fetch(addrApi + '?address_action=barangays&city_code=' + encodeURIComponent(cityCode));
        const d = await r.json();
        if (d.success && d.data) {
            brgy.innerHTML = '<option value="">Select barangay</option>' + d.data.map(x => '<option value="' + x.name + '">' + x.name + '</option>').join('');
            brgy.disabled = false;
            
            // Restore selected barangay
            const selectedBrgy = brgy.getAttribute('data-selected');
            if (selectedBrgy) {
                brgy.value = selectedBrgy;
            }
        }
        buildAddress();
    }

    loadProvinces();

    prov.addEventListener('change', function() {
        const opt = prov.options[prov.selectedIndex];
        const code = opt && opt.value ? opt.getAttribute('data-code') : '';
        loadCities(code);
    });
    city.addEventListener('change', function() {
        const opt = city.options[city.selectedIndex];
        const code = opt && opt.value ? opt.getAttribute('data-code') : '';
        loadBarangays(code);
    });
    brgy.addEventListener('change', buildAddress);
    line.addEventListener('input', buildAddress);

    const contactInput = document.getElementById('contact_number');
    contactInput.addEventListener('input', function() {
        let v = this.value.replace(/\D/g, '');
        if (v.length > 0 && !v.startsWith('09')) v = '09' + v.replace(/^0+/, '');
        this.value = v.slice(0, 11);
    });

    document.getElementById('id_image').addEventListener('change', function() {
        document.getElementById('id_label').textContent = this.files[0] ? this.files[0].name : 'Click to select ID image (JPG, PNG, max 5MB)';
    });

    // Show previously selected filename if exists
    const prevFilename = document.getElementById('previous_filename');
    if (prevFilename && prevFilename.value) {
        document.getElementById('id_label').textContent = prevFilename.value;
        // Make the file input not required if there was a previous upload
        document.getElementById('id_image').removeAttribute('required');
    }

    document.getElementById('completeForm').addEventListener('submit', function(e) {
        let ok = true;
        const c = contactInput.value.trim();
        if (!/^09\d{9}$/.test(c)) {
            document.getElementById('err_contact').parentElement.classList.add('is-invalid');
            ok = false;
        } else {
            document.getElementById('err_contact').parentElement.classList.remove('is-invalid');
        }
        buildAddress();
        if (!ok) {
            e.preventDefault();
        } else {
            // Show loading state
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.classList.add('btn-loading');
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>