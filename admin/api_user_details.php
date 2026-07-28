<?php
/**
 * Admin User Details API
 * PrintFlow - Printing Shop PWA
 */
require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff_access.php';

require_role(['Admin', 'Manager']);
function printflow_admin_user_asset_candidates(?string $path, string $defaultDir): array
{
    $path = trim(str_replace('\\', '/', (string)$path));
    if ($path === '') return [];
    if (preg_match('#^https?://#i', $path)) return [$path];

    $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
    $path = preg_replace('#^(?:\.\./|\./)+#', '', $path);
    $path = preg_replace('#^[A-Za-z]:/#', '', $path);
    if (preg_match('#(?:^|/)uploads/(.+)$#i', $path, $matches)) {
        $path = 'uploads/' . ltrim($matches[1], '/');
    }

    $candidates = [];
    $add = static function (string $candidate) use (&$candidates, $base): void {
        $candidate = trim(str_replace('\\', '/', $candidate));
        if ($candidate === '') return;
        if (preg_match('#^https?://#i', $candidate)) {
            $candidates[] = $candidate;
            return;
        }
        $candidates[] = $base . '/' . ltrim($candidate, '/');
    };

    if (str_starts_with($path, '/') || str_contains($path, '/')) {
        $add($path);
    }

    $filename = basename($path);
    if ($filename !== '') {
        $encoded = rawurlencode($filename);
        $add(trim($defaultDir, '/') . '/' . $encoded);
        $add('uploads/ids/' . $encoded);
        $add('public/assets/uploads/ids/' . $encoded);
        $add('uploads/' . $encoded);
    }

    return array_values(array_unique($candidates));
}

function printflow_admin_user_asset_url(?string $path, string $defaultDir): string
{
    return printflow_admin_user_asset_candidates($path, $defaultDir)[0] ?? '';
}

$user_id = (int)($_GET['id'] ?? 0);
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

try {
    $cols = array_column(db_query("SHOW COLUMNS FROM users"), 'Field');
    if (!in_array('id_type', $cols, true)) {
        db_execute("ALTER TABLE users ADD COLUMN id_type VARCHAR(100) NULL AFTER id_validation_image");
    }
} catch (Throwable $e) {
    // Keep details loading even if this safe migration cannot run.
}

$user = db_query("
    SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.birthday as dob, u.gender,
           u.email, u.contact_number, u.address, u.role, u.position, u.profile_picture, u.id_type, u.id_validation_image,
           u.status, u.branch_id, b.branch_name, u.created_at
    FROM users u 
    LEFT JOIN branches b ON u.branch_id = b.id 
    WHERE u.user_id = ?
", 'i', [$user_id]);

if (empty($user)) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

if (($user[0]['role'] ?? '') === 'Admin' && ($user[0]['status'] ?? '') !== 'Archived' && ($user[0]['status'] ?? '') !== 'Activated') {
    try {
        db_execute("UPDATE users SET status = 'Activated', updated_at = NOW() WHERE user_id = ? AND role = 'Admin'", 'i', [$user_id]);
        $user[0]['status'] = 'Activated';
    } catch (Throwable $e) {
        $user[0]['status'] = 'Activated';
    }
}

$user[0]['id_validation_image_candidates'] = printflow_admin_user_asset_candidates($user[0]['id_validation_image'] ?? '', 'uploads/ids');
$user[0]['id_validation_image_url'] = $user[0]['id_validation_image_candidates'][0] ?? '';
$user[0]['profile_picture_url'] = printflow_admin_user_asset_url($user[0]['profile_picture'] ?? '', 'uploads/profiles');
$user[0]['role_display'] = printflow_staff_role_display_name($user[0]['role'] ?? '', $user[0]['position'] ?? null);
$user[0]['role_key'] = match ($user[0]['role'] ?? '') {
    'Staff' => printflow_detect_staff_access_role($user[0]['position'] ?? null) === 'pos'
        ? 'front_desk_staff'
        : 'online_production_staff',
    default => (string)($user[0]['role'] ?? ''),
};

echo json_encode(['success' => true, 'user' => $user[0]]);
