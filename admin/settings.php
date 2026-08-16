<?php
/**
 * Admin Settings Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/runtime_config.php';
require_once __DIR__ . '/../includes/team_photo_helper.php';
require_once __DIR__ . '/../includes/pos_receipt_printer.php';

require_role('Admin');
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$current_user = get_logged_in_user();

$success = '';
$error   = '';

$saved_messages = [
    'payment_methods' => 'Payment methods updated!',
    'general' => 'General settings saved!',
    'footer' => 'Footer info saved!',
    'about' => 'About page content saved!',
    'receipt_printer' => 'Receipt printer settings saved!',
    'receipt_printer_key' => 'Printer API key regenerated. Copy the new key below.',
    'receipt_test' => '58mm test receipt queued.',
    'receipt_retry' => 'Receipt print job queued for retry.',
];
if (isset($_GET['saved']) && isset($saved_messages[$_GET['saved']])) {
    $success = $saved_messages[$_GET['saved']];
}

// Directories
$qr_dir   = __DIR__ . '/../public/assets/uploads/qr/';
$logo_dir = __DIR__ . '/../public/assets/uploads/';
if (!is_dir($qr_dir)) mkdir($qr_dir, 0755, true);
printflow_ensure_team_upload_dir();

function cfg_text($input) {
    return trim(str_replace(["\r\n", "\r"], "\n", (string)$input));
}

$payment_cfg = printflow_load_runtime_config('payment_methods', $qr_dir . 'payment_methods.json');
$shop_cfg   = printflow_load_runtime_config('shop', $logo_dir . 'shop_config.json');
$footer_cfg = printflow_load_runtime_config('footer', $logo_dir . 'footer_config.json');
$about_cfg  = printflow_load_runtime_config('about', $logo_dir . 'about_config.json');

// Load branches for address selector (archived branches omitted)
$branches = db_query("SELECT id, branch_name AS name FROM branches WHERE status != 'Archived' ORDER BY branch_name") ?: [];
// Per-branch addresses stored in footer_cfg['branch_addresses'] = [['branch_id'=>1,'address'=>'...']]

function settings_redirect_after_save($savedKey) {
    $query = ['saved' => $savedKey];
    header('Location: settings.php?' . http_build_query($query));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {

    if (isset($_POST['save_receipt_printer'])) {
        $result = printflow_receipt_printer_create_or_update($_POST, (int)get_user_id());
        if (!empty($result['ok'])) {
            if (!empty($result['api_key'])) {
                $_SESSION['receipt_printer_api_key'] = (string)$result['api_key'];
            }
            settings_redirect_after_save('receipt_printer');
        }
        $error = 'Receipt printer settings could not be saved.';
    }

    if (isset($_POST['regenerate_receipt_printer_key'])) {
        $apiKey = printflow_receipt_printer_regenerate_key((int)($_POST['printer_id'] ?? 0));
        if ($apiKey !== null) {
            $_SESSION['receipt_printer_api_key'] = $apiKey;
            settings_redirect_after_save('receipt_printer_key');
        }
        $error = 'The printer API key could not be regenerated.';
    }

    if (isset($_POST['queue_receipt_test'])) {
        $result = printflow_receipt_enqueue_test_print((int)($_POST['printer_id'] ?? 0), (int)get_user_id());
        if (!empty($result['ok'])) settings_redirect_after_save('receipt_test');
        $error = (string)($result['message'] ?? 'The test receipt could not be queued.');
    }

    if (isset($_POST['retry_receipt_job'])) {
        if (printflow_receipt_retry_job((int)($_POST['job_id'] ?? 0))) {
            settings_redirect_after_save('receipt_retry');
        }
        $error = 'The selected print job could not be retried.';
    }

    // Save Payment Methods
    if (isset($_POST['save_payment_methods'])) {
        $pm_cfg = [];
        $providers = $_POST['pm_provider'] ?? [];
        $labels    = $_POST['pm_label'] ?? [];
        $enabled   = $_POST['pm_enabled'] ?? [];
        $cropped_imgs = $_POST['pm_cropped_img'] ?? [];
        $existing     = $_POST['pm_existing_file'] ?? [];

        foreach ($providers as $index => $provider) {
            $provider = sanitize($provider);
            $label    = sanitize($labels[$index] ?? '');
            $is_en    = (int)($enabled[$index] ?? 1);
            $file     = sanitize($existing[$index] ?? '');

            if ($provider !== '' || $label !== '') {
                // handle cropped base64 image
                $b64 = $cropped_imgs[$index] ?? '';
                if (!empty($b64) && strpos($b64, 'data:image') === 0) {
                    $parts = explode(',', $b64);
                    if (count($parts) === 2) {
                        $data = base64_decode($parts[1]);
                        $ext = 'png';
                        if (strpos($parts[0], 'jpeg') !== false) $ext = 'jpg';
                        elseif (strpos($parts[0], 'webp') !== false) $ext = 'webp';
                        
                        $fname = 'pm_' . time() . '_' . $index . '_crop.' . $ext;
                        file_put_contents($qr_dir . $fname, $data);
                        $file = $fname;
                    }
                } 
                // fallback to regular file
                elseif (!empty($_FILES['pm_file']['name'][$index])) {
                    $ext = strtolower(pathinfo($_FILES['pm_file']['name'][$index], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                        $fname = 'pm_' . time() . '_' . $index . '.' . $ext;
                        move_uploaded_file($_FILES['pm_file']['tmp_name'][$index], $qr_dir . $fname);
                        $file = $fname;
                    }
                }
                $pm_cfg[] = [
                    'provider' => $provider,
                    'label' => $label,
                    'enabled' => $is_en,
                    'file' => $file
                ];
            }
        }
        if (printflow_save_runtime_config('payment_methods', $pm_cfg)) {
            settings_redirect_after_save('payment_methods');
        } else {
            $error = 'Payment methods could not be saved. Please try again.';
        }
    }

    // Save general + logo
    if (isset($_POST['save_general'])) {
        $shop_cfg['name']  = sanitize($_POST['shop_name'] ?? 'PrintFlow');
        if (!empty($_FILES['shop_logo']['name'])) {
            $ext = strtolower(pathinfo($_FILES['shop_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','svg'])) {
                $fname = 'shop_logo_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['shop_logo']['tmp_name'], $logo_dir . $fname);
                $shop_cfg['logo'] = $fname;
            }
        }
        if ($error === '' && printflow_save_runtime_config('shop', $shop_cfg)) {
            settings_redirect_after_save('general');
        } elseif ($error === '') {
            $error = 'General settings could not be saved. Please try again.';
        }
    }

    // Save footer
    if (isset($_POST['save_footer'])) {
        $footer_cfg['tagline'] = cfg_text($_POST['footer_tagline'] ?? '');
        $footer_cfg['hours']   = cfg_text($_POST['footer_hours'] ?? '');
        $footer_cfg['email']   = cfg_text($_POST['footer_email'] ?? '');
        $footer_cfg['phone']   = cfg_text($_POST['footer_phone'] ?? '');

        // Per-branch addresses
        $ba_ids  = $_POST['ba_branch_id'] ?? [];
        $ba_addrs = $_POST['ba_address'] ?? [];
        $branch_addresses = [];
        foreach ($ba_ids as $bi => $bid) {
            $bid = (int)$bid;
            $addr = trim($ba_addrs[$bi] ?? '');
            if ($bid > 0 && $addr !== '') {
                $branch_addresses[] = ['branch_id' => $bid, 'address' => cfg_text($addr)];
            }
        }
        $footer_cfg['branch_addresses'] = $branch_addresses;

        // Services: raw textarea, one per line
        $svcs_raw = $_POST['footer_services'] ?? '';
        $footer_cfg['services'] = array_values(array_filter(array_map('cfg_text', explode("\n", str_replace("\r", '', $svcs_raw)))));

        // Social links: URL only — name auto-detected by frontend
        $social_urls = $_POST['social_url'] ?? [];
        $socials = [];
        foreach ($social_urls as $u) {
            $u = cfg_text($u);
            if ($u !== '') $socials[] = ['url' => $u];
        }
        $footer_cfg['social_links'] = $socials;

        if (printflow_save_runtime_config('footer', $footer_cfg)) {
            settings_redirect_after_save('footer');
        } else {
            $error = 'Footer info could not be saved. Please try again.';
        }
    }

    // Save About Page Config
    if (isset($_POST['save_about'])) {
        $values = [];
        $v_titles = $_POST['about_value_title'] ?? [];
        $v_descs  = $_POST['about_value_desc'] ?? [];
        $v_icons  = $_POST['about_value_icon'] ?? [];
        foreach ($v_titles as $i => $vt) {
            $vt = cfg_text($vt);
            if ($vt !== '') {
                $values[] = ['title' => $vt, 'desc' => cfg_text($v_descs[$i] ?? ''), 'icon' => cfg_text($v_icons[$i] ?? 'star')];
            }
        }
        $team = [];
        $t_names  = $_POST['about_team_name'] ?? [];
        $t_roles  = $_POST['about_team_role'] ?? [];
        $t_photos = $_POST['about_team_photo'] ?? [];
        $teamUploads = $_FILES['about_team_photo_upload'] ?? null;
        foreach ($t_names as $i => $tn) {
            $tn = cfg_text($tn);
            if ($tn !== '') {
                $photo = printflow_team_photo_normalize(cfg_text($t_photos[$i] ?? ''));
                $uploaded = printflow_save_team_photo_upload(is_array($teamUploads) ? $teamUploads : null, (int)$i);
                if ($uploaded !== null) {
                    $photo = $uploaded;
                }
                $team[] = ['name' => $tn, 'role' => cfg_text($t_roles[$i] ?? ''), 'photo' => $photo];
            }
        }
        $about_cfg = [
            'tagline'       => cfg_text($_POST['about_tagline'] ?? ''),
            'hero_subtitle' => cfg_text($_POST['about_hero_subtitle'] ?? ''),
            'mission'       => cfg_text($_POST['about_mission'] ?? ''),
            'vision'        => cfg_text($_POST['about_vision'] ?? ''),
            'founding_year' => cfg_text($_POST['about_founding_year'] ?? ''),
            'team_size'     => cfg_text($_POST['about_team_size'] ?? ''),
            'projects_done' => cfg_text($_POST['about_projects_done'] ?? ''),
            'happy_clients' => cfg_text($_POST['about_happy_clients'] ?? ''),
            'values'        => $values,
            'team_members'  => $team,
        ];
        if (printflow_save_runtime_config('about', $about_cfg)) {
            settings_redirect_after_save('about');
        } else {
            $error = 'About page content could not be saved. Please try again.';
        }
    }
}

$receipt_printers = printflow_receipt_printer_list();
$recent_receipt_jobs = db_query(
    "SELECT j.id, j.receipt_number, j.status, j.attempts, j.max_attempts, j.error_message,
            j.created_at, j.printed_at, p.name AS printer_name
     FROM receipt_print_jobs j
     INNER JOIN receipt_printers p ON p.id = j.printer_id
     ORDER BY j.id DESC LIMIT 15"
) ?: [];
$generated_printer_api_key = (string)($_SESSION['receipt_printer_api_key'] ?? '');
unset($_SESSION['receipt_printer_api_key']);
$request_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$request_host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
$printer_poll_url = ($request_host !== '' ? $request_scheme . '://' . $request_host : '')
    . rtrim((string)$base_path, '/') . '/public/api/printer/jobs.php';
$pushprinter_api_base_url = ($request_host !== '' ? $request_scheme . '://' . $request_host : '')
    . rtrim((string)$base_path, '/');
$pushy_configured = printflow_receipt_pushy_secret() !== '';

$page_title = 'Settings - Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .about-team-photo-preview {
            width:80px;
            height:80px;
            border-radius:50%;
            overflow:hidden;
            margin:0 auto 12px;
            border:2px solid #e5e7eb;
            background:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .about-team-photo-preview img {
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }
        .about-team-photo-fallback {
            width:100%;
            height:100%;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#eef2ff;
            color:#4338ca;
            font-size:22px;
            font-weight:700;
        }
        @media(max-width:960px) { .settings-grid { grid-template-columns:1fr; } }
        .settings-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:24px; position:relative; overflow:hidden; }
        .settings-card::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #00232b, #53C5E0); }
        .settings-card-title { font-size:15px; font-weight:700; color:#111827; margin-bottom:18px; padding-bottom:12px; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .settings-card-title svg { width:18px; height:18px; color:#6366f1; flex-shrink:0; }
        .f-group { margin-bottom:16px; }
        .f-group label { display:block; font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
        .f-group input, .f-group select, .f-group textarea { width:100%; padding:9px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; color:#111827; background:#fafafa; outline:none; transition:border-color .15s; box-sizing:border-box; }
        .f-group input:focus, .f-group select:focus, .f-group textarea:focus { border-color:#6366f1; background:#fff; }
        .f-group input[type="file"] { background:#fff; padding:7px 12px; }
        .f-group textarea { resize:vertical; min-height:70px; }
        .f-group.is-invalid input, .f-group.is-invalid select, .f-group.is-invalid textarea {
            border-color: #ef4444 !important;
            background-color: #fef2f2;
        }
        .error-message {
            color: #ef4444;
            font-size: 11px;
            margin-top: 4px;
            display: none;
            font-weight: 500;
        }
        .f-group.is-invalid .error-message {
            display: block;
        }
        .toggle-row { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:#f8fafc; border-radius:10px; border:1px solid #e5e7eb; margin-bottom:12px; gap:10px; }
        .toggle-label { font-size:14px; font-weight:600; color:#111827; }
        .toggle-sub { font-size:12px; color:#9ca3af; }
        .toggle-switch { position:relative; display:inline-block; width:44px; height:24px; flex-shrink:0; }
        .toggle-switch input { opacity:0; width:0; height:0; }
        .toggle-slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#e5e7eb; transition:.3s; border-radius:24px; }
        .toggle-slider:before { position:absolute; content:""; height:18px; width:18px; left:3px; bottom:3px; background:white; transition:.3s; border-radius:50%; }
        .toggle-switch input:checked + .toggle-slider { background:#6366f1; }
        .toggle-switch input:checked + .toggle-slider:before { transform:translateX(20px); }
        .qr-preview { width:100px; height:100px; object-fit:contain; border:2px dashed #e5e7eb; border-radius:10px; padding:6px; display:block; margin-bottom:10px; background:#f9fafb; }
        .qr-no-img { width:100px; height:100px; border:2px dashed #e5e7eb; border-radius:10px; display:flex; align-items:center; justify-content:center; margin-bottom:10px; background:#f9fafb; color:#d1d5db; font-size:11px; text-align:center; }
        .qr-pair { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media(max-width:600px) { .qr-pair { grid-template-columns:1fr; } }
        .qr-slot { background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:14px; }
        .qr-slot-title { font-size:12px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; margin-bottom:10px; }
        .badge-enabled { display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700; background:#dcfce7; color:#166534; }
        .badge-disabled { display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700; background:#fee2e2; color:#991b1b; }
        .section-save { display:flex; flex-wrap:wrap; justify-content:flex-end; margin-top:16px; }
        .btn-save-sm { padding:9px 22px; border:none; border-radius:8px; background:#00232b; color:#fff; font-size:14px; font-weight:600; cursor:pointer; transition:background .15s; }
        .btn-save-sm:hover { background:#003a47; }
        .logo-preview { max-height:60px; max-width:180px; object-fit:contain; border:1px solid #e5e7eb; border-radius:8px; padding:6px; background:#fafafa; margin-bottom:10px; display:block; }
        .f-group textarea { resize:vertical; min-height:70px; font-family:inherit; font-size:14px; line-height:1.5; }
        .printer-api-key { padding:12px; border:1px solid #86efac; background:#f0fdf4; border-radius:8px; margin-bottom:16px; }
        .printer-api-key code { display:block; margin-top:6px; padding:9px; background:#fff; border:1px solid #bbf7d0; border-radius:6px; overflow-wrap:anywhere; user-select:all; }
        .printer-table-wrap { overflow-x:auto; border:1px solid #e5e7eb; border-radius:8px; }
        .printer-table { width:100%; border-collapse:collapse; font-size:12px; }
        .printer-table th, .printer-table td { padding:9px 10px; border-bottom:1px solid #e5e7eb; text-align:left; vertical-align:middle; }
        .printer-table th { color:#6b7280; background:#f8fafc; font-size:10px; text-transform:uppercase; }
        .printer-table tr:last-child td { border-bottom:0; }
        .printer-actions { display:flex; gap:6px; flex-wrap:wrap; }
        .btn-printer-secondary { padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; background:#fff; color:#374151; font-size:12px; cursor:pointer; }
        
        /* Cropper Modal */
        .cropper-modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); display:none; align-items:center; justify-content:center; z-index:9999; }
        .cropper-modal-panel { background:#fff; border-radius:12px; padding:20px; width:90%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.2); }
        .cropper-container-box { width:100%; height:300px; background:#f3f4f6; margin:15px 0; border:1px dashed #cbd5e1; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        @media (max-width: 768px) {
            .settings-grid { gap:14px; }
            .settings-card {
                padding:18px !important;
                border-radius:10px !important;
                overflow:visible;
            }
            .settings-card form > div[style*="grid-template-columns"],
            .pm-row > div[style*="grid-template-columns"],
            .about-value-row {
                grid-template-columns:1fr !important;
            }
            .branch-addr-row > div,
            .social-row,
            .toggle-row {
                align-items:stretch !important;
            }
            .branch-addr-row > div,
            .social-row {
                flex-wrap:nowrap;
            }
            .about-value-row button {
                width:100%;
                margin-top:0 !important;
            }
            #about-team-list {
                grid-template-columns:1fr !important;
            }
            .about-team-photo-preview {
                width:72px !important;
                height:72px !important;
            }
            .section-save {
                justify-content:stretch;
            }
            .btn-save-sm,
            #add-branch-addr,
            #add-social,
            #add-about-value,
            #add-about-team {
                width:100%;
            }
            .cropper-modal-panel {
                width:calc(100vw - 24px);
                max-height:calc(100dvh - 48px);
                overflow-y:auto;
                padding:16px;
            }
            .cropper-container-box {
                height:260px;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <header>
            <h1 class="page-title">Settings</h1>
        </header>

        <main>
            <?php if ($success): ?>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="settings-grid">

                <!-- General Settings -->
                <div class="settings-card">
                    <div class="settings-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
                        General Settings
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <!-- Logo -->
                        <div class="f-group">
                            <label>Shop Logo</label>
                            <?php if (!empty($shop_cfg['logo'])): ?>
                                <img src="<?php echo $base_path; ?>/public/assets/uploads/<?php echo htmlspecialchars($shop_cfg['logo']); ?>?t=<?php echo time(); ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #e5e7eb;display:block;margin-bottom:10px;" alt="Shop Logo">
                            <?php endif; ?>
                            <input type="file" name="shop_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                            <p style="font-size:11px;color:#9ca3af;margin-top:4px;">🔵 Recommended: <strong>500×500 px</strong> square image (PNG/WebP with transparent background). Displayed as a circle.</p>
                        </div>
                        <div class="f-group" id="group_shop_name">
                            <label>Shop Name</label>
                            <input type="text" name="shop_name" id="shop_name" value="<?php echo htmlspecialchars($shop_cfg['name'] ?? 'PrintFlow'); ?>" maxlength="50" required>
                            <div class="error-message" id="error_shop_name">Shop name must contain only letters and single spaces between words.</div>
                        </div>
                        <div class="f-group">
                            <label>Currency</label>
                            <input type="text" value="Philippine Peso (₱)" disabled style="background:#f3f4f6;color:#9ca3af;">
                            <p style="font-size:11px;color:#9ca3af;margin-top:4px;">Currency is fixed to PHP.</p>
                        </div>
                        <div class="section-save">
                            <button type="submit" name="save_general" class="btn-save-sm">Save Settings</button>
                        </div>
                    </form>
                </div>

                <!-- 58mm Receipt Printer -->
                <div class="settings-card" style="grid-column:1/-1;">
                    <div class="settings-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-12-4h12v8H6v-8z"/></svg>
                        XP-58H Receipt Printing
                    </div>

                    <?php if ($generated_printer_api_key !== ''): ?>
                        <div class="printer-api-key">
                            <strong>Printer API key (shown once)</strong>
                            <div style="font-size:12px;color:#166534;margin-top:3px;">Enter this in the Windows PushPrinter agent. Regenerating it immediately invalidates the old key.</div>
                            <code id="generated-printer-api-key"><?php echo htmlspecialchars($generated_printer_api_key); ?></code>
                            <button type="button" class="btn-printer-secondary" style="margin-top:8px;" onclick="navigator.clipboard.writeText(document.getElementById('generated-printer-api-key').textContent);this.textContent='Copied';">Copy API Key</button>
                        </div>
                    <?php endif; ?>

                    <div style="display:grid;grid-template-columns:minmax(280px,1fr) minmax(320px,1.35fr);gap:22px;align-items:start;">
                        <form method="POST" id="receipt-printer-form">
                            <?php echo csrf_field(); ?>
                            <div class="f-group">
                                <label>Configuration</label>
                                <select name="printer_id" id="receipt-printer-select">
                                    <option value="0">Create new printer</option>
                                    <?php foreach ($receipt_printers as $printer): ?>
                                        <option value="<?php echo (int)$printer['id']; ?>"><?php echo htmlspecialchars((string)$printer['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="f-group">
                                <label>Printer Name</label>
                                <input type="text" name="name" id="receipt-printer-name" value="XP-58H Receipt Printer" maxlength="120" required>
                            </div>
                            <div class="f-group">
                                <label>Branch</label>
                                <select name="branch_id" id="receipt-printer-branch">
                                    <option value="0">All branches / fallback</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo (int)$branch['id']; ?>"><?php echo htmlspecialchars((string)$branch['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="f-group">
                                <label>Windows Printer / Driver Name</label>
                                <input type="text" name="printer_driver_name" id="receipt-printer-driver" value="XP-58H" maxlength="190" required>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="f-group">
                                    <label>Characters Per Line</label>
                                    <select name="columns_count" id="receipt-printer-columns">
                                        <option value="32">32 (recommended)</option>
                                        <option value="42">42 (condensed)</option>
                                    </select>
                                </div>
                                <div class="f-group">
                                    <label>Copies</label>
                                    <input type="number" name="copies" id="receipt-printer-copies" value="1" min="1" max="5">
                                </div>
                            </div>
                            <div class="toggle-row">
                                <div><div class="toggle-label">Automatic printing</div><div class="toggle-sub">Queue after a successful POS transaction</div></div>
                                <label class="toggle-switch"><input type="checkbox" name="auto_print" id="receipt-printer-auto" value="1" checked><span class="toggle-slider"></span></label>
                            </div>
                            <div class="toggle-row">
                                <div><div class="toggle-label">Default printer</div><div class="toggle-sub">Fallback when a branch printer is unavailable</div></div>
                                <label class="toggle-switch"><input type="checkbox" name="is_default" id="receipt-printer-default" value="1"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="f-group">
                                <label>Status</label>
                                <select name="status" id="receipt-printer-status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                            </div>
                            <div class="section-save"><button type="submit" name="save_receipt_printer" class="btn-save-sm">Save Printer</button></div>
                        </form>

                        <div>
                            <div class="f-group">
                                <label>PushPrinter API Base URL</label>
                                <input type="text" readonly value="<?php echo htmlspecialchars($pushprinter_api_base_url); ?>" onclick="this.select()">
                                <p style="font-size:11px;color:#6b7280;margin-top:5px;">Set this as <code>appApiUrl</code> in PushPrinter's Windows config. Push notifications: <strong style="color:<?php echo $pushy_configured ? '#166534' : '#b91c1c'; ?>;"><?php echo $pushy_configured ? 'configured' : 'PUSHY_API_SECRET missing'; ?></strong>.</p>
                            </div>
                            <div class="f-group">
                                <label>Polling Fallback URL</label>
                                <input type="text" readonly value="<?php echo htmlspecialchars($printer_poll_url); ?>" onclick="this.select()">
                            </div>
                            <div class="printer-table-wrap" style="margin-bottom:16px;">
                                <table class="printer-table">
                                    <thead><tr><th>Printer</th><th>Branch</th><th>Layout</th><th>Agent</th><th>Actions</th></tr></thead>
                                    <tbody>
                                    <?php if (empty($receipt_printers)): ?>
                                        <tr><td colspan="5">No receipt printer configured yet.</td></tr>
                                    <?php else: foreach ($receipt_printers as $printer): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars((string)$printer['name']); ?></strong><br><span style="color:#6b7280;"><?php echo htmlspecialchars((string)$printer['printer_driver_name']); ?></span></td>
                                            <td><?php echo htmlspecialchars((string)($printer['branch_name'] ?: 'Fallback')); ?></td>
                                            <td>58mm / <?php echo (int)$printer['columns_count']; ?> cols</td>
                                            <td><?php echo $printer['last_seen_at'] ? htmlspecialchars((string)$printer['last_seen_at']) : 'Not connected'; ?><br><span style="color:#6b7280;">Key <?php echo htmlspecialchars((string)$printer['api_key_prefix']); ?>...<?php echo htmlspecialchars((string)$printer['api_key_last4']); ?></span></td>
                                            <td><div class="printer-actions">
                                                <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="printer_id" value="<?php echo (int)$printer['id']; ?>"><button class="btn-printer-secondary" name="queue_receipt_test" type="submit">Test Print</button></form>
                                                <form method="POST" onsubmit="return confirm('Regenerate this printer API key? The old key will stop working.');"><?php echo csrf_field(); ?><input type="hidden" name="printer_id" value="<?php echo (int)$printer['id']; ?>"><button class="btn-printer-secondary" name="regenerate_receipt_printer_key" type="submit">New API Key</button></form>
                                            </div></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="printer-table-wrap">
                                <table class="printer-table">
                                    <thead><tr><th>Recent Job</th><th>Printer</th><th>Status</th><th>Attempts</th><th></th></tr></thead>
                                    <tbody>
                                    <?php if (empty($recent_receipt_jobs)): ?>
                                        <tr><td colspan="5">No receipt print jobs yet.</td></tr>
                                    <?php else: foreach ($recent_receipt_jobs as $job): ?>
                                        <tr>
                                            <td>#<?php echo (int)$job['id']; ?> <?php echo htmlspecialchars((string)$job['receipt_number']); ?><br><span style="color:#6b7280;"><?php echo htmlspecialchars((string)$job['created_at']); ?></span></td>
                                            <td><?php echo htmlspecialchars((string)$job['printer_name']); ?></td>
                                            <td><strong><?php echo htmlspecialchars(ucfirst((string)$job['status'])); ?></strong><?php if (!empty($job['error_message'])): ?><br><span style="color:#b91c1c;"><?php echo htmlspecialchars((string)$job['error_message']); ?></span><?php endif; ?></td>
                                            <td><?php echo (int)$job['attempts']; ?>/<?php echo (int)$job['max_attempts']; ?></td>
                                            <td><?php if (in_array((string)$job['status'], ['failed', 'claimed'], true)): ?><form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="job_id" value="<?php echo (int)$job['id']; ?>"><button class="btn-printer-secondary" name="retry_receipt_job" type="submit">Retry</button></form><?php endif; ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods (Dynamic) -->
                <div class="settings-card">
                    <div class="settings-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        Payment Methods (Dynamic)
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <div id="pm-list" style="display:flex;flex-direction:column;gap:16px;">
                            <?php
                            if (empty($payment_cfg)) $payment_cfg = [['provider'=>'GCash','label'=>'','enabled'=>1,'file'=>'']];
                            foreach ($payment_cfg as $pm):
                            ?>
                            <div class="pm-row" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px;position:relative;">
                                <button type="button" onclick="this.closest('.pm-row').remove()" style="position:absolute;top:10px;right:10px;padding:5px 9px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:6px;cursor:pointer;font-size:12px;z-index:10;">✕</button>
                                
                                <div class="toggle-row" style="margin-bottom:10px;">
                                    <div>
                                        <div class="toggle-label">Show Payment Option</div>
                                        <div class="toggle-sub"><?php echo ($pm['enabled'] ?? 1) ? 'Enabled' : 'Disabled'; ?></div>
                                    </div>
                                    <select name="pm_enabled[]" style="padding:4px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;background:#fff;">
                                        <option value="1" <?php echo ($pm['enabled'] ?? 1) ? 'selected' : ''; ?>>Enabled</option>
                                        <option value="0" <?php echo !($pm['enabled'] ?? 1) ? 'selected' : ''; ?>>Disabled</option>
                                    </select>
                                </div>

                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;">
                                    <div class="f-group" style="margin-bottom:0;">
                                        <label>Provider Name</label>
                                        <input type="text" name="pm_provider[]" class="pm-provider-input" value="<?php echo htmlspecialchars($pm['provider'] ?? ''); ?>" placeholder="e.g. GCash" required maxlength="50">
                                    </div>
                                    <div class="f-group" style="margin-bottom:0;">
                                        <label>Account Name / Label</label>
                                        <input type="text" name="pm_label[]" class="pm-label-input" value="<?php echo htmlspecialchars($pm['label'] ?? ''); ?>" placeholder="e.g. Main Account" maxlength="50">
                                    </div>
                                </div>
                                <div class="f-group" style="margin-bottom:0;">
                                    <label>Upload QR Image <span style="font-weight:400;color:#9ca3af;">(Auto-crops to square)</span></label>
                                    <input type="file" name="pm_file[]" accept="image/*" class="pm-file-input">
                                    <input type="hidden" name="pm_existing_file[]" value="<?php echo htmlspecialchars($pm['file'] ?? ''); ?>">
                                    <input type="hidden" name="pm_cropped_img[]" value="">
                                    <div style="margin-top:8px;">
                                        <img src="<?php echo !empty($pm['file']) ? $base_path . '/public/assets/uploads/qr/' . htmlspecialchars($pm['file']) . '?t=' . time() : ''; ?>"
                                             class="pm-preview-img" 
                                             style="height:80px; width:80px; object-fit:cover; border-radius:8px; border:2px solid #e5e7eb; background:#fff; display:<?php echo !empty($pm['file']) ? 'block' : 'none'; ?>;" alt="QR">
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-pm" style="margin-top:12px;padding:7px 14px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;cursor:pointer;color:#374151;width:100%;">+ Add Payment Method</button>

                        <div class="section-save" style="margin-top:16px;">
                            <button type="submit" name="save_payment_methods" class="btn-save-sm">Save Payment Methods</button>
                        </div>
                    </form>
                </div>

                <!-- Footer Info -->
                <div class="settings-card" style="grid-column:1/-1;">
                    <div class="settings-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>
                        Footer Information
                    </div>
                    <form method="POST" data-turbo="false">
                        <?php echo csrf_field(); ?>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;">

                            <!-- Col 1: Info -->
                            <div>
                                <div class="f-group" id="group_footer_tagline">
                                    <label>Tagline / Short Description</label>
                                    <input type="text" name="footer_tagline" id="footer_tagline" value="<?php echo htmlspecialchars($footer_cfg['tagline'] ?? ''); ?>" placeholder="e.g. Your trusted printing partner" maxlength="100">
                                </div>
                                <div class="f-group" id="group_footer_hours">
                                    <label>Business Hours</label>
                                    <input type="text" name="footer_hours" id="footer_hours" value="<?php echo htmlspecialchars($footer_cfg['hours'] ?? ''); ?>" placeholder="e.g. Mon–Sat, 8AM–6PM" maxlength="100">
                                </div>
                                <div class="f-group" id="group_footer_email">
                                    <label>Contact Email</label>
                                    <input type="email" name="footer_email" id="footer_email" value="<?php echo htmlspecialchars($footer_cfg['email'] ?? ''); ?>" placeholder="e.g. support@yourshop.com" maxlength="100">
                                    <div class="error-message" id="error_footer_email">Please enter a valid email address with at least 2 characters after the dot (e.g., .com, .org).</div>
                                </div>
                                <div class="f-group" id="group_footer_phone">
                                    <label>Contact Phone</label>
                                    <input type="tel" name="footer_phone" id="footer_phone" value="<?php echo htmlspecialchars($footer_cfg['phone'] ?? ''); ?>" placeholder="e.g. 09171234567" maxlength="11">
                                    <div class="error-message" id="error_footer_phone">Contact number must be exactly 11 digits and start with 09.</div>
                                </div>
                            </div>

                            <!-- Col 2: Branch Addresses -->
                            <div>
                                <div class="f-group">
                                    <label>Branch Addresses <span style="font-weight:400;color:#9ca3af;">(per branch)</span></label>
                                    <div id="branch-addr-list" style="display:flex;flex-direction:column;gap:10px;margin-bottom:8px;">
                                        <?php
                                        $saved_bas = $footer_cfg['branch_addresses'] ?? [];
                                        if (empty($saved_bas)) $saved_bas = [['branch_id'=>'','address'=>'']];
                                        foreach ($saved_bas as $ba):
                                            $ba_bid  = $ba['branch_id'] ?? '';
                                            $ba_addr = $ba['address'] ?? '';
                                        ?>
                                        <div class="branch-addr-row" style="display:flex;flex-direction:column;gap:6px;background:#f9fafb;padding:10px;border-radius:8px;border:1px solid #e5e7eb;">
                                            <div style="display:flex;gap:8px;align-items:center;">
                                                <select name="ba_branch_id[]" style="flex:1;padding:8px 10px;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;background:#fff;color:#111827;">
                                                    <option value="">-- Select Branch --</option>
                                                    <?php foreach ($branches as $br): ?>
                                                    <option value="<?php echo (int)$br['id']; ?>" <?php echo $ba_bid == $br['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($br['name']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" onclick="this.closest('.branch-addr-row').remove()" style="padding:5px 9px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:6px;cursor:pointer;font-size:13px;flex-shrink:0;">✕</button>
                                            </div>
                                            <textarea name="ba_address[]" rows="2" placeholder="Full address for this branch" class="ba-address-input" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;font-family:inherit;resize:vertical;background:#fff;color:#111827;"><?php echo htmlspecialchars($ba_addr); ?></textarea>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" id="add-branch-addr" style="padding:7px 14px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;cursor:pointer;color:#374151;">+ Add Branch Address</button>
                                </div>
                            </div>

                            <!-- Col 3: Services + Socials -->
                            <div>
                                <div class="f-group">
                                    <label>Our Services <span style="font-weight:400;color:#9ca3af;">(one per line)</span></label>
                                    <textarea name="footer_services" id="footer_services" rows="5" placeholder="Tarpaulin Printing
T-shirt Printing
Stickers &amp; Decals"><?php
                                        echo htmlspecialchars(implode("\n", $footer_cfg['services'] ?? []));
                                    ?></textarea>
                                    <p style="font-size:11px;color:#9ca3af;margin-top:4px;">Each line becomes a ✓ item in the footer.</p>
                                </div>
                                <div class="f-group">
                                    <label>Social Media Links <span style="font-weight:400;color:#9ca3af;">(URL only — icon auto-detected)</span></label>
                                    <div id="social-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:8px;">
                                        <?php
                                        $existing_socials = $footer_cfg['social_links'] ?? [];
                                        if (empty($existing_socials)) $existing_socials = [['url'=>'']];
                                        foreach ($existing_socials as $sl):
                                        ?>
                                        <div class="social-row" style="display:flex;gap:8px;align-items:center;">
                                            <input type="url" name="social_url[]" class="social-url-input" value="<?php echo htmlspecialchars($sl['url'] ?? ''); ?>" placeholder="https://facebook.com/yourpage" style="flex:1;" maxlength="200">
                                            <button type="button" onclick="this.closest('.social-row').remove()" style="padding:6px 10px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:6px;cursor:pointer;font-size:13px;">✕</button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" id="add-social" style="padding:7px 14px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;cursor:pointer;color:#374151;">+ Add Social Link</button>
                                </div>
                            </div>

                        </div>
                        <div class="section-save">
                            <button type="submit" name="save_footer" class="btn-save-sm">Save Footer Info</button>
                        </div>
                    </form>
                </div>

                <!-- About Page Content -->
                <div class="settings-card" style="grid-column:1/-1;">
                    <div class="settings-card-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/></svg>
                        About Page Content
                    </div>
                    <form method="POST" enctype="multipart/form-data" data-turbo="false">
                        <?php echo csrf_field(); ?>

                        <!-- Hero -->
                        <p style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px;">Hero Section</p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                            <div class="f-group" style="margin-bottom:0;">
                                <label>Tagline (Hero Heading)</label>
                                <input type="text" name="about_tagline" id="about_tagline" class="about-text-input" value="<?php echo htmlspecialchars($about_cfg['tagline'] ?? ''); ?>" placeholder="Your Trusted Printing Partner Since Day One" maxlength="150">
                            </div>
                            <div class="f-group" style="margin-bottom:0;">
                                <label>Hero Subtitle</label>
                                <input type="text" name="about_hero_subtitle" id="about_hero_subtitle" class="about-text-input" value="<?php echo htmlspecialchars($about_cfg['hero_subtitle'] ?? ''); ?>" placeholder="Short description under the tagline" maxlength="200">
                            </div>
                        </div>

                        <!-- Stats -->
                        <p style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px;">Stats Bar</p>
                        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;">
                            <div class="f-group" style="margin-bottom:0;">
                                <label>Founding Year</label>
                                <input type="text" name="about_founding_year" id="about_founding_year" class="about-text-input" value="<?php echo htmlspecialchars($about_cfg['founding_year'] ?? ''); ?>" placeholder="e.g. 2018" maxlength="50">
                            </div>
                            <div class="f-group" style="margin-bottom:0;">
                                <label>Team Size</label>
                                <input type="text" name="about_team_size" id="about_team_size" class="about-text-input" value="<?php echo htmlspecialchars($about_cfg['team_size'] ?? ''); ?>" placeholder="e.g. 25+" maxlength="50">
                            </div>
                            <div class="f-group" style="margin-bottom:0;">
                                <label>Projects Done</label>
                                <input type="text" name="about_projects_done" id="about_projects_done" class="about-text-input" value="<?php echo htmlspecialchars($about_cfg['projects_done'] ?? ''); ?>" placeholder="e.g. 10,000+" maxlength="50">
                            </div>
                            <div class="f-group" style="margin-bottom:0;">
                                <label>Happy Clients</label>
                                <input type="text" name="about_happy_clients" id="about_happy_clients" class="about-text-input" value="<?php echo htmlspecialchars($about_cfg['happy_clients'] ?? ''); ?>" placeholder="e.g. 5,000+" maxlength="50">
                            </div>
                        </div>

                        <!-- Mission & Vision -->
                        <p style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px;">Mission & Vision</p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                            <div class="f-group" style="margin-bottom:0;">
                                <label>Mission Statement</label>
                                <textarea name="about_mission" id="about_mission" class="about-textarea-input" rows="4" placeholder="Our mission is to..."><?php echo htmlspecialchars($about_cfg['mission'] ?? ''); ?></textarea>
                            </div>
                            <div class="f-group" style="margin-bottom:0;">
                                <label>Vision Statement</label>
                                <textarea name="about_vision" id="about_vision" class="about-textarea-input" rows="4" placeholder="Our vision is to..."><?php echo htmlspecialchars($about_cfg['vision'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <!-- Values -->
                        <p style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px;">Core Values <span style="font-weight:400;color:#9ca3af;">(up to 6)</span></p>
                        <div id="about-values-list" style="display:flex;flex-direction:column;gap:12px;margin-bottom:10px;">
                            <?php
                            $ab_values = $about_cfg['values'] ?? [['title'=>'','desc'=>'','icon'=>'star']];
                            foreach ($ab_values as $av):
                            ?>
                            <div class="about-value-row" style="display:grid;grid-template-columns:1fr 2fr auto;gap:10px;align-items:start;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;">
                                <div class="f-group" style="margin-bottom:0;">
                                    <label>Title</label>
                                    <input type="text" name="about_value_title[]" class="about-value-title-input" value="<?php echo htmlspecialchars($av['title']??''); ?>" placeholder="e.g. Quality First" maxlength="50">
                                    <input type="hidden" name="about_value_icon[]" value="<?php echo htmlspecialchars($av['icon']??'star'); ?>">
                                </div>
                                <div class="f-group" style="margin-bottom:0;">
                                    <label>Description</label>
                                    <input type="text" name="about_value_desc[]" class="about-value-desc-input" value="<?php echo htmlspecialchars($av['desc']??''); ?>" placeholder="Short description" maxlength="150">
                                </div>
                                <button type="button" onclick="this.closest('.about-value-row').remove()" style="margin-top:20px;padding:7px 10px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:6px;cursor:pointer;font-size:13px;flex-shrink:0;">✕</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-about-value" style="padding:7px 14px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;cursor:pointer;color:#374151;margin-bottom:20px;">+ Add Value</button>

                        <!-- Team Members -->
                        <p style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px;">Team Members <span style="font-weight:400;color:#9ca3af;">(optional)</span></p>
                        <div id="about-team-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:10px;">
                            <?php
                            $ab_team = $about_cfg['team_members'] ?? [];
                            foreach ($ab_team as $i => $tm):
                                $teamPhotoUrl = printflow_team_photo_public_url($tm['photo'] ?? '');
                                $teamInitials = printflow_team_photo_initials((string)($tm['name'] ?? ''));
                            ?>
                            <div class="about-team-row" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;position:relative;">
                                <button type="button" onclick="this.closest('.about-team-row').remove()" style="position:absolute;top:8px;right:8px;padding:4px 8px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:5px;cursor:pointer;font-size:11px;z-index:2;">✕</button>
                                <div class="about-team-photo-preview" data-team-photo-preview>
                                    <?php if ($teamPhotoUrl): ?>
                                        <img src="<?php echo htmlspecialchars($teamPhotoUrl, ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($tm['name'] ?? 'Team member', ENT_QUOTES); ?>" data-team-photo-img onerror="this.style.display='none';var f=this.parentElement.querySelector('[data-team-photo-fallback]');if(f){f.style.display='flex';}">
                                        <div class="about-team-photo-fallback" data-team-photo-fallback style="display:none;"><?php echo htmlspecialchars($teamInitials, ENT_QUOTES); ?></div>
                                    <?php else: ?>
                                        <div class="about-team-photo-fallback" data-team-photo-fallback><?php echo htmlspecialchars($teamInitials, ENT_QUOTES); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="f-group">
                                    <label>Full Name</label>
                                    <input type="text" name="about_team_name[]" class="about-team-name-input" value="<?php echo htmlspecialchars($tm['name']??''); ?>" placeholder="e.g. Maria Santos" maxlength="100">
                                </div>
                                <div class="f-group">
                                    <label>Role / Position</label>
                                    <input type="text" name="about_team_role[]" class="about-team-role-input" value="<?php echo htmlspecialchars($tm['role']??''); ?>" placeholder="e.g. Founder & CEO" maxlength="100">
                                </div>
                                <div class="f-group" style="margin-bottom:0;">
                                    <label>Photo <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
                                    <input type="file" name="about_team_photo_upload[]" class="about-team-photo-input" accept="image/jpeg,image/png,image/webp,image/jpg">
                                    <input type="hidden" name="about_team_photo[]" class="about-team-photo-hidden" value="<?php echo htmlspecialchars(printflow_team_photo_normalize($tm['photo']??''), ENT_QUOTES); ?>">
                                    <small style="display:block;margin-top:6px;color:#9ca3af;font-size:11px;">JPG, PNG, or WebP. Shown on the About page.</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-about-team" style="padding:7px 14px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;cursor:pointer;color:#374151;margin-bottom:4px;">+ Add Team Member</button>

                        <div class="section-save" style="margin-top:20px;">
                            <button type="submit" name="save_about" class="btn-save-sm">Save About Page</button>
                        </div>
                    </form>
                </div>

            </div>
        </main>
    </div>
</div>

<!-- Cropper Modal -->
<div id="cropperModal" class="cropper-modal-overlay">
    <div class="cropper-modal-panel">
        <h3 style="margin-top:0; font-size:18px; color:#111827;">Crop QR Code</h3>
        <p style="font-size:13px; color:#6b7280; margin-bottom:0;">Please crop the image to a perfect square.</p>
        <div class="cropper-container-box">
            <img id="imageToCrop" style="max-width:100%; display:block;">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
            <button type="button" onclick="closeCropper()" style="padding:8px 16px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; cursor:pointer; color:#374151;">Cancel</button>
            <button type="button" id="btnCrop" style="padding:8px 16px; border:none; border-radius:6px; background:#00232b; color:#fff; cursor:pointer;">Crop & Apply</button>
        </div>
    </div>
</div>

<script>
function printflowInitSettingsPage() {
    const receiptPrinterData = <?php echo json_encode($receipt_printers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const receiptPrinterSelect = document.getElementById('receipt-printer-select');
    if (receiptPrinterSelect && receiptPrinterSelect.dataset.pfBound !== '1') {
        receiptPrinterSelect.dataset.pfBound = '1';
        receiptPrinterSelect.addEventListener('change', function() {
            const selected = receiptPrinterData.find(function(printer) {
                return Number(printer.id) === Number(receiptPrinterSelect.value);
            });
            const values = selected || {
                name: 'XP-58H Receipt Printer', branch_id: 0, printer_driver_name: 'XP-58H',
                columns_count: 32, copies: 1, auto_print: 1, is_default: 0, status: 'active'
            };
            document.getElementById('receipt-printer-name').value = values.name || '';
            document.getElementById('receipt-printer-branch').value = Number(values.branch_id || 0);
            document.getElementById('receipt-printer-driver').value = values.printer_driver_name || 'XP-58H';
            document.getElementById('receipt-printer-columns').value = Number(values.columns_count || 32);
            document.getElementById('receipt-printer-copies').value = Number(values.copies || 1);
            document.getElementById('receipt-printer-auto').checked = Number(values.auto_print) === 1;
            document.getElementById('receipt-printer-default').checked = Number(values.is_default) === 1;
            document.getElementById('receipt-printer-status').value = values.status || 'active';
        });
    }

    // Block non-letters in name fields
    function blockNonLetters(event) {
        if ([8, 9, 27, 13, 46, 37, 38, 39, 40].indexOf(event.keyCode) !== -1 ||
            (event.keyCode === 65 && event.ctrlKey === true) ||
            (event.keyCode === 67 && event.ctrlKey === true) ||
            (event.keyCode === 86 && event.ctrlKey === true) ||
            (event.keyCode === 88 && event.ctrlKey === true) ||
            (event.keyCode >= 35 && event.keyCode <= 36)) {
            return true;
        }
        
        var char = event.key;
        
        if (char && char.length === 1 && !/^[a-zA-Z ]$/.test(char)) {
            event.preventDefault();
            return false;
        }
        
        if (char === ' ') {
            var input = event.target;
            var value = input.value;
            var cursorPos = input.selectionStart;
            
            if (cursorPos === 0) {
                event.preventDefault();
                return false;
            }
            
            if (value.charAt(cursorPos - 1) === ' ') {
                event.preventDefault();
                return false;
            }
        }
        
        return true;
    }
    
    function removeNonLetters(input) {
        var value = input.value;
        var cursorPos = input.selectionStart;
        
        var cleaned = value.replace(/[^a-zA-Z ]/g, '');
        cleaned = cleaned.replace(/  +/g, ' ');
        cleaned = cleaned.replace(/^ +/, '');
        
        cleaned = cleaned.split(' ').map(function(word) {
            if (word.length === 0) return word;
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        }).join(' ');
        
        if (cleaned.length > 50) {
            cleaned = cleaned.substring(0, 50);
        }
        
        if (value !== cleaned) {
            input.value = cleaned;
            if (cursorPos !== null) {
                input.setSelectionRange(cursorPos, cursorPos);
            }
        }
    }
    
    // Shop Name validation
    const shopName = document.getElementById('shop_name');
    if (shopName && !shopName.dataset.pfValidationBound) {
        shopName.dataset.pfValidationBound = '1';
        shopName.addEventListener('keydown', blockNonLetters);
        shopName.addEventListener('input', function() { removeNonLetters(this); });
    }
    
    // Payment Method Provider and Label validation
    function attachPaymentFieldValidation() {
        document.querySelectorAll('.pm-provider-input, .pm-label-input').forEach(function(input) {
            if (!input.dataset.pfValidationBound) {
                input.dataset.pfValidationBound = '1';
                input.addEventListener('keydown', blockNonLetters);
                input.addEventListener('input', function() { removeNonLetters(this); });
            }
        });
    }
    
    attachPaymentFieldValidation();
    
    // Footer fields space validation (allow any characters, just block consecutive/leading spaces)
    function blockConsecutiveSpaces(event) {
        if ([8, 9, 27, 13, 46, 37, 38, 39, 40].indexOf(event.keyCode) !== -1 ||
            (event.ctrlKey === true && [65, 67, 86, 88, 90].indexOf(event.keyCode) !== -1) ||
            (event.keyCode >= 35 && event.keyCode <= 36)) {
            return true;
        }
        
        var char = event.key;
        
        if (char === ' ') {
            var input = event.target;
            var value = input.value;
            var cursorPos = input.selectionStart;
            
            if (cursorPos === 0) {
                event.preventDefault();
                return false;
            }
            
            if (value.charAt(cursorPos - 1) === ' ') {
                event.preventDefault();
                return false;
            }
        }
        
        return true;
    }
    
    function blockAllSpaces(event) {
        if ([8, 9, 27, 13, 46, 37, 38, 39, 40].indexOf(event.keyCode) !== -1 ||
            (event.ctrlKey === true && [65, 67, 86, 88, 90].indexOf(event.keyCode) !== -1) ||
            (event.keyCode >= 35 && event.keyCode <= 36)) {
            return true;
        }
        
        var char = event.key;
        
        if (char === ' ') {
            event.preventDefault();
            return false;
        }
        
        return true;
    }
    
    function removeConsecutiveSpaces(input) {
        var value = input.value;
        var cursorPos = input.selectionStart;
        
        var cleaned = value.replace(/  +/g, ' ');
        cleaned = cleaned.replace(/^ +/, '');
        
        var maxLen = parseInt(input.getAttribute('maxlength')) || 100;
        if (cleaned.length > maxLen) {
            cleaned = cleaned.substring(0, maxLen);
        }
        
        if (value !== cleaned) {
            input.value = cleaned;
            if (cursorPos !== null) {
                input.setSelectionRange(cursorPos, cursorPos);
            }
        }
    }
    
    function removeAllSpaces(input) {
        var value = input.value;
        var cursorPos = input.selectionStart;
        
        var cleaned = value.replace(/\s/g, '');
        
        var maxLen = parseInt(input.getAttribute('maxlength')) || 100;
        if (cleaned.length > maxLen) {
            cleaned = cleaned.substring(0, maxLen);
        }
        
        if (value !== cleaned) {
            input.value = cleaned;
            if (cursorPos !== null) {
                input.setSelectionRange(cursorPos, cursorPos);
            }
        }
    }
    
    function formatFooterPhone(input) {
        var value = input.value;
        var cleaned = value.replace(/\D/g, '');
        
        if (cleaned.length === 0) {
            input.value = '09';
            return;
        }
        
        if (!cleaned.startsWith('09')) {
            if (cleaned.startsWith('9')) {
                cleaned = '0' + cleaned;
            } else {
                cleaned = '09' + cleaned;
            }
        }
        
        if (cleaned.length > 11) {
            cleaned = cleaned.substring(0, 11);
        }
        
        input.value = cleaned;
    }
    
    function validateFooterPhone(fieldId) {
        const input = document.getElementById(fieldId);
        const group = document.getElementById('group_' + fieldId);
        const error = document.getElementById('error_' + fieldId);
        
        if (!input || !group || !error) return true;
        
        const value = input.value.trim();
        
        if (value === '' || value === '09') {
            group.classList.remove('is-invalid');
            return true;
        }
        
        if (!/^09\d{9}$/.test(value)) {
            group.classList.add('is-invalid');
            return false;
        }
        
        group.classList.remove('is-invalid');
        return true;
    }
    
    const footerTagline = document.getElementById('footer_tagline');
    if (footerTagline && !footerTagline.dataset.pfValidationBound) {
        footerTagline.dataset.pfValidationBound = '1';
        footerTagline.addEventListener('keydown', blockConsecutiveSpaces);
        footerTagline.addEventListener('input', function() { removeConsecutiveSpaces(this); });
    }
    
    const footerHours = document.getElementById('footer_hours');
    if (footerHours && !footerHours.dataset.pfValidationBound) {
        footerHours.dataset.pfValidationBound = '1';
        footerHours.addEventListener('keydown', blockConsecutiveSpaces);
        footerHours.addEventListener('input', function() { removeConsecutiveSpaces(this); });
    }
    
    const footerPhone = document.getElementById('footer_phone');
    if (footerPhone && !footerPhone.dataset.pfValidationBound) {
        footerPhone.dataset.pfValidationBound = '1';
        if (!footerPhone.value || footerPhone.value.trim() === '') {
            footerPhone.value = '09';
        }
        footerPhone.addEventListener('input', function() { 
            formatFooterPhone(this); 
            validateFooterPhone('footer_phone');
        });
        footerPhone.addEventListener('blur', function() { validateFooterPhone('footer_phone'); });
    }
    
    // Branch address textarea validation
    function attachBranchAddressValidation() {
        document.querySelectorAll('.ba-address-input').forEach(function(textarea) {
            if (!textarea.dataset.pfValidationBound) {
                textarea.dataset.pfValidationBound = '1';
                textarea.addEventListener('keydown', blockConsecutiveSpaces);
                textarea.addEventListener('input', function() { removeConsecutiveSpaces(this); });
            }
        });
    }
    
    attachBranchAddressValidation();
    
    // Footer services textarea validation
    const footerServices = document.getElementById('footer_services');
    if (footerServices && !footerServices.dataset.pfValidationBound) {
        footerServices.dataset.pfValidationBound = '1';
        footerServices.addEventListener('keydown', blockConsecutiveSpaces);
        footerServices.addEventListener('input', function() { removeConsecutiveSpaces(this); });
    }
    
    // Social URL validation
    function attachSocialUrlValidation() {
        document.querySelectorAll('.social-url-input').forEach(function(input) {
            if (!input.dataset.pfValidationBound) {
                input.dataset.pfValidationBound = '1';
                input.addEventListener('keydown', blockConsecutiveSpaces);
                input.addEventListener('input', function() { removeConsecutiveSpaces(this); });
            }
        });
    }
    
    attachSocialUrlValidation();
    
    // About Page fields validation
    function attachAboutFieldsValidation() {
        document.querySelectorAll('.about-text-input').forEach(function(input) {
            if (!input.dataset.pfValidationBound) {
                input.dataset.pfValidationBound = '1';
                input.addEventListener('keydown', blockConsecutiveSpaces);
                input.addEventListener('input', function() { removeConsecutiveSpaces(this); });
            }
        });
        
        document.querySelectorAll('.about-textarea-input').forEach(function(textarea) {
            if (!textarea.dataset.pfValidationBound) {
                textarea.dataset.pfValidationBound = '1';
                textarea.addEventListener('keydown', blockConsecutiveSpaces);
                textarea.addEventListener('input', function() { removeConsecutiveSpaces(this); });
            }
        });
        
        document.querySelectorAll('.about-value-title-input, .about-value-desc-input').forEach(function(input) {
            if (!input.dataset.pfValidationBound) {
                input.dataset.pfValidationBound = '1';
                input.addEventListener('keydown', blockConsecutiveSpaces);
                input.addEventListener('input', function() { removeConsecutiveSpaces(this); });
            }
        });
        
        document.querySelectorAll('.about-team-name-input, .about-team-role-input').forEach(function(input) {
            if (!input.dataset.pfValidationBound) {
                input.dataset.pfValidationBound = '1';
                input.addEventListener('keydown', blockConsecutiveSpaces);
                input.addEventListener('input', function() { removeConsecutiveSpaces(this); });
            }
        });
    }
    
    attachAboutFieldsValidation();
    
    // Email validation
    function validateEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/.test(email);
    }
    
    function validateEmailField(fieldId) {
        const input = document.getElementById(fieldId);
        const group = document.getElementById('group_' + fieldId);
        const error = document.getElementById('error_' + fieldId);
        
        if (!input || !group || !error) return true;
        
        const value = input.value.trim();
        
        if (value === '') {
            group.classList.remove('is-invalid');
            return true;
        }
        
        if (!validateEmail(value)) {
            group.classList.add('is-invalid');
            return false;
        }
        
        group.classList.remove('is-invalid');
        return true;
    }
    
    const footerEmail = document.getElementById('footer_email');
    if (footerEmail && !footerEmail.dataset.pfValidationBound) {
        footerEmail.dataset.pfValidationBound = '1';
        footerEmail.addEventListener('keydown', blockAllSpaces);
        footerEmail.addEventListener('input', function() { 
            removeAllSpaces(this);
            validateEmailField('footer_email');
        });
        footerEmail.addEventListener('blur', () => validateEmailField('footer_email'));
    }
    
    // Add social link row
    const addSocialBtn = document.getElementById('add-social');
    if (addSocialBtn && !addSocialBtn.dataset.pfBound) {
        addSocialBtn.dataset.pfBound = '1';
        addSocialBtn.addEventListener('click', function() {
            var list = document.getElementById('social-list');
            if (!list) return;
            var row = document.createElement('div');
            row.className = 'social-row';
            row.style.cssText = 'display:flex;gap:8px;align-items:center;';
            row.innerHTML = '<input type="url" name="social_url[]" class="social-url-input" placeholder="https://facebook.com/yourpage" style="flex:1;" maxlength="200">' +
                '<button type="button" onclick="this.closest(\'.social-row\').remove()" style="padding:6px 10px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:6px;cursor:pointer;font-size:13px;">✕</button>';
            list.appendChild(row);
            row.querySelector('input').focus();
            attachSocialUrlValidation();
        });
    }

    // Add branch address row
    const addBranchBtn = document.getElementById('add-branch-addr');
    if (addBranchBtn && !addBranchBtn.dataset.pfBound) {
        addBranchBtn.dataset.pfBound = '1';
        var branchOptions = <?php
            $opts = [];
            foreach ($branches as $br) {
                $opts[] = '<option value="' . (int)$br['id'] . '">' . htmlspecialchars($br['name']) . '</option>';
            }
            echo json_encode(implode('', $opts));
        ?>;
        addBranchBtn.addEventListener('click', function() {
            var list = document.getElementById('branch-addr-list');
            if (!list) return;
            var row = document.createElement('div');
            row.className = 'branch-addr-row';
            row.style.cssText = 'display:flex;flex-direction:column;gap:6px;background:#f9fafb;padding:10px;border-radius:8px;border:1px solid #e5e7eb;';
            row.innerHTML =
                '<div style="display:flex;gap:8px;align-items:center;">' +
                    '<select name="ba_branch_id[]" style="flex:1;padding:8px 10px;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;background:#fff;color:#111827;">' +
                        '<option value="">-- Select Branch --</option>' + branchOptions +
                    '</select>' +
                    '<button type="button" onclick="this.closest(\'.branch-addr-row\').remove()" style="padding:5px 9px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:6px;cursor:pointer;font-size:13px;flex-shrink:0;">✕</button>' +
                '</div>' +
                '<textarea name="ba_address[]" rows="2" placeholder="Full address for this branch" class="ba-address-input" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;font-family:inherit;resize:vertical;background:#fff;color:#111827;box-sizing:border-box;"></textarea>';
            list.appendChild(row);
            row.querySelector('select').focus();
            attachBranchAddressValidation();
        });
    }

    const addPmBtn = document.getElementById('add-pm');
    if (addPmBtn && !addPmBtn.dataset.pfBound) {
        addPmBtn.dataset.pfBound = '1';
        addPmBtn.addEventListener('click', function() {
            var list = document.getElementById('pm-list');
            if (!list) return;
            var row = document.createElement('div');
            row.className = 'pm-row';
            row.style.cssText = 'background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px;position:relative;';
            row.innerHTML = `<button type="button" onclick="this.closest('.pm-row').remove()" style="position:absolute;top:10px;right:10px;padding:5px 9px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:6px;cursor:pointer;font-size:12px;z-index:10;">✕</button>
                <div class="toggle-row" style="margin-bottom:10px;">
                    <div>
                        <div class="toggle-label">Show Payment Option</div>
                        <div class="toggle-sub">Enabled</div>
                    </div>
                    <select name="pm_enabled[]" style="padding:4px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;background:#fff;">
                        <option value="1" selected>Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;">
                    <div class="f-group" style="margin-bottom:0;">
                        <label>Provider Name</label>
                        <input type="text" name="pm_provider[]" class="pm-provider-input" placeholder="e.g. GCash" required maxlength="50">
                    </div>
                    <div class="f-group" style="margin-bottom:0;">
                        <label>Account Name / Label</label>
                        <input type="text" name="pm_label[]" class="pm-label-input" placeholder="e.g. Main Account" maxlength="50">
                    </div>
                </div>
                <div class="f-group" style="margin-bottom:0;">
                    <label>Upload QR Image <span style="font-weight:400;color:#9ca3af;">(Auto-crops to square)</span></label>
                    <input type="file" name="pm_file[]" accept="image/*" class="pm-file-input">
                    <input type="hidden" name="pm_existing_file[]" value="">
                    <input type="hidden" name="pm_cropped_img[]" value="">
                    <div style="margin-top:8px;">
                        <img src="" class="pm-preview-img" style="height:80px; width:80px; object-fit:cover; border-radius:8px; border:2px solid #e5e7eb; background:#fff; display:none;" alt="QR">
                    </div>
                </div>`;
            list.appendChild(row);
            attachPaymentFieldValidation();
        });
    }

    // Cropper Logic — use event delegation on document body to avoid duplicate listeners after Turbo
    if (!document.body.dataset.pfSettingsBound) {
        document.body.dataset.pfSettingsBound = '1';

        // Make cropper accessible globally for cleanup
        window.currentCropper = null;
        let currentFileInput = null;
        let currentPreviewImg = null;
        let currentHiddenInput = null;

        document.body.addEventListener('change', function(e) {
            if (e.target.matches('.pm-file-input')) {
                const file = e.target.files[0];
                if (file) {
                    const row = e.target.closest('.pm-row');
                    currentFileInput = e.target;
                    currentHiddenInput = row.querySelector('input[name="pm_cropped_img[]"]');
                    currentPreviewImg = row.querySelector('.pm-preview-img');
                    
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        const img = document.getElementById('imageToCrop');
                        if (!img) return;
                        img.src = event.target.result;
                        const modal = document.getElementById('cropperModal');
                        if (modal) modal.style.display = 'flex';
                        
                        if (window.currentCropper) {
                            window.currentCropper.destroy();
                        }
                        
                        window.currentCropper = new Cropper(img, {
                            aspectRatio: 1, // perfect square!
                            viewMode: 1,
                            autoCropArea: 0.8
                        });
                    };
                    reader.readAsDataURL(file);
                }
            }
        });

        window.closeCropper = function() {
            const modal = document.getElementById('cropperModal');
            if (modal) modal.style.display = 'none';
            if (window.currentCropper) {
                window.currentCropper.destroy();
                window.currentCropper = null;
            }
            if (currentFileInput && currentHiddenInput && !currentHiddenInput.value) {
                currentFileInput.value = ''; // Reset input if they cancelled
            }
        };

        const cropBtn = document.getElementById('btnCrop');
        if (cropBtn) {
            cropBtn.addEventListener('click', function() {
                if (window.currentCropper) {
                    const canvas = window.currentCropper.getCroppedCanvas({ width: 500, height: 500 });
                    const dataUrl = canvas.toDataURL('image/png');
                    if (currentHiddenInput) {
                        currentHiddenInput.value = dataUrl;
                        currentHiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    
                    if (currentPreviewImg) {
                        currentPreviewImg.src = dataUrl;
                        currentPreviewImg.style.display = 'block';
                    }
                    window.closeCropper();
                }
            });
        }
    }

    // About Page — Add Value Row
    const addValBtn = document.getElementById('add-about-value');
    if (addValBtn && !addValBtn.dataset.pfBound) {
        addValBtn.dataset.pfBound = '1';
        addValBtn.addEventListener('click', function() {
            var list = document.getElementById('about-values-list');
            if (!list) return;
            var row = document.createElement('div');
            row.className = 'about-value-row';
            row.style.cssText = 'display:grid;grid-template-columns:1fr 2fr auto;gap:10px;align-items:start;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;';
            row.innerHTML = '<div class="f-group" style="margin-bottom:0;"><label>Title</label><input type="text" name="about_value_title[]" class="about-value-title-input" placeholder="e.g. Quality First" maxlength="50"><input type="hidden" name="about_value_icon[]" value="star"></div>' +
                '<div class="f-group" style="margin-bottom:0;"><label>Description</label><input type="text" name="about_value_desc[]" class="about-value-desc-input" placeholder="Short description" maxlength="150"></div>' +
                '<button type="button" onclick="this.closest(\'.about-value-row\').remove()" style="margin-top:20px;padding:7px 10px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:6px;cursor:pointer;font-size:13px;flex-shrink:0;">✕</button>';
            list.appendChild(row);
            row.querySelector('input[type="text"]').focus();
            attachAboutFieldsValidation();
        });
    }

    function pfTeamPhotoInitials(name) {
        name = String(name || '').trim();
        if (!name) return '?';
        var parts = name.split(/\s+/).filter(Boolean);
        if (parts.length >= 2) return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
        return name.substring(0, 2).toUpperCase();
    }

    function pfRenderTeamPhotoPreview(row) {
        if (!row) return;
        var preview = row.querySelector('[data-team-photo-preview]');
        var nameInput = row.querySelector('.about-team-name-input');
        if (!preview) return;
        var hidden = row.querySelector('.about-team-photo-hidden');
        var existing = hidden ? String(hidden.value || '').trim() : '';
        var img = preview.querySelector('[data-team-photo-img]');
        var fallback = preview.querySelector('[data-team-photo-fallback]');
        if (existing && img) {
            if (fallback) fallback.style.display = 'none';
            img.style.display = 'block';
            return;
        }
        if (!fallback) {
            fallback = document.createElement('div');
            fallback.className = 'about-team-photo-fallback';
            fallback.setAttribute('data-team-photo-fallback', '');
            preview.appendChild(fallback);
        }
        if (img) img.style.display = 'none';
        fallback.textContent = pfTeamPhotoInitials(nameInput ? nameInput.value : '');
        fallback.style.display = 'flex';
    }

    function pfBindTeamPhotoInputs(scope) {
        (scope || document).querySelectorAll('.about-team-photo-input').forEach(function(input) {
            if (input.dataset.pfBound === '1') return;
            input.dataset.pfBound = '1';
            input.addEventListener('change', function() {
                var row = input.closest('.about-team-row');
                var preview = row ? row.querySelector('[data-team-photo-preview]') : null;
                var file = input.files && input.files[0] ? input.files[0] : null;
                if (!preview || !file) return;
                var reader = new FileReader();
                reader.onload = function(ev) {
                    var img = preview.querySelector('[data-team-photo-img]');
                    if (!img) {
                        img = document.createElement('img');
                        img.setAttribute('data-team-photo-img', '');
                        img.alt = 'Team member photo preview';
                        preview.appendChild(img);
                    }
                    var fallback = preview.querySelector('[data-team-photo-fallback]');
                    if (fallback) fallback.style.display = 'none';
                    img.src = ev.target.result;
                    img.style.display = 'block';
                };
                reader.readAsDataURL(file);
            });
        });
        (scope || document).querySelectorAll('.about-team-name-input').forEach(function(input) {
            if (input.dataset.pfTeamNameBound === '1') return;
            input.dataset.pfTeamNameBound = '1';
            input.addEventListener('input', function() {
                var row = input.closest('.about-team-row');
                if (!row || row.querySelector('[data-team-photo-img]')) return;
                pfRenderTeamPhotoPreview(row);
            });
        });
    }

    pfBindTeamPhotoInputs(document);
    document.querySelectorAll('.about-team-row').forEach(pfRenderTeamPhotoPreview);

    // About Page — Add Team Member Row
    const addTeamBtn = document.getElementById('add-about-team');
    if (addTeamBtn && !addTeamBtn.dataset.pfBound) {
        addTeamBtn.dataset.pfBound = '1';
        addTeamBtn.addEventListener('click', function() {
            var list = document.getElementById('about-team-list');
            if (!list) return;
            var row = document.createElement('div');
            row.className = 'about-team-row';
            row.style.cssText = 'background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;position:relative;';
            row.innerHTML = '<button type="button" onclick="this.closest(\'.about-team-row\').remove()" style="position:absolute;top:8px;right:8px;padding:4px 8px;border:1px solid #fee2e2;background:#fef2f2;color:#b91c1c;border-radius:5px;cursor:pointer;font-size:11px;z-index:2;">✕</button>' +
                '<div class="about-team-photo-preview" data-team-photo-preview><div class="about-team-photo-fallback" data-team-photo-fallback>?</div></div>' +
                '<div class="f-group"><label>Full Name</label><input type="text" name="about_team_name[]" class="about-team-name-input" placeholder="e.g. Maria Santos" maxlength="100"></div>' +
                '<div class="f-group"><label>Role / Position</label><input type="text" name="about_team_role[]" class="about-team-role-input" placeholder="e.g. Founder & CEO" maxlength="100"></div>' +
                '<div class="f-group" style="margin-bottom:0;"><label>Photo <span style="font-weight:400;color:#9ca3af;">(optional)</span></label><input type="file" name="about_team_photo_upload[]" class="about-team-photo-input" accept="image/jpeg,image/png,image/webp,image/jpg"><input type="hidden" name="about_team_photo[]" class="about-team-photo-hidden" value=""><small style="display:block;margin-top:6px;color:#9ca3af;font-size:11px;">JPG, PNG, or WebP. Shown on the About page.</small></div>';
            list.appendChild(row);
            pfBindTeamPhotoInputs(row);
            row.querySelector('input[type="text"]').focus();
            attachAboutFieldsValidation();
        });
    }
}

// Initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', printflowInitSettingsPage);
} else {
    printflowInitSettingsPage();
}

// Re-initialize after Turbo navigation
document.addEventListener('printflow:page-init', printflowInitSettingsPage);

// Cleanup before Turbo caches/navigates away
document.addEventListener('turbo:before-cache', function() {
    // Destroy cropper instance if exists
    if (window.currentCropper) {
        try {
            window.currentCropper.destroy();
            window.currentCropper = null;
        } catch(e) {}
    }
    // Hide modal
    const modal = document.getElementById('cropperModal');
    if (modal) modal.style.display = 'none';
});
</script>

</body>
</html>
