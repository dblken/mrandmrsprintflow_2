<?php
/**
 * Admin User & Staff Management
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ensure_account_creation_guard.php';
printflow_ensure_account_creation_guard();

require_role('Admin');
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

try {
    db_execute("UPDATE users SET status = 'Activated', updated_at = NOW() WHERE role = 'Admin' AND status NOT IN ('Activated','Archived')");
} catch (Throwable $e) {
    // ignore migration failures
}

$current_user = get_logged_in_user();

$error = '';
$success = '';

// Realtime email check (same rules as create_staff: users + customers table)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_email'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $raw = trim((string)($_GET['email'] ?? ''));
    if ($raw === '') {
        echo json_encode(['ok' => true, 'available' => null]);
        exit;
    }
    if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $raw)) {
        echo json_encode(['ok' => true, 'available' => null, 'invalid_format' => true]);
        exit;
    }
    $email = trim($raw);
    $existing_user     = db_query('SELECT user_id FROM users WHERE email = ?', 's', [$email]);
    $existing_customer = db_query('SELECT customer_id FROM customers WHERE email = ?', 's', [$email]);
    $taken = !empty($existing_user) || !empty($existing_customer);
    echo json_encode(['ok' => true, 'available' => !$taken, 'taken' => $taken]);
    exit;
}

// Ensure columns exist (safe migration) — skip after first successful run (saves SHOW COLUMNS every request)
$__pfUsersSchemaOk = __DIR__ . '/../tmp/.printflow_users_schema_ok';
if (!is_file($__pfUsersSchemaOk)) {
    try {
        $uc = array_column(db_query("SHOW COLUMNS FROM users"), 'Field');
        if (!in_array('middle_name', $uc)) db_execute("ALTER TABLE users ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name");
        if (!in_array('birthday', $uc)) db_execute("ALTER TABLE users ADD COLUMN birthday DATE NULL AFTER last_name");
        if (!in_array('profile_completion_token', $uc)) db_execute("ALTER TABLE users ADD COLUMN profile_completion_token VARCHAR(64) NULL");
        if (!in_array('profile_completion_expires', $uc)) db_execute("ALTER TABLE users ADD COLUMN profile_completion_expires DATETIME NULL");
        if (!in_array('profile_completion_fields_to_clear', $uc)) db_execute("ALTER TABLE users ADD COLUMN profile_completion_fields_to_clear TEXT NULL");
        if (!in_array('id_validation_image', $uc)) db_execute("ALTER TABLE users ADD COLUMN id_validation_image VARCHAR(255) NULL");
        if (!in_array('id_type', $uc)) db_execute("ALTER TABLE users ADD COLUMN id_type VARCHAR(100) NULL AFTER id_validation_image");
        @file_put_contents($__pfUsersSchemaOk, '1');
    } catch (Throwable $e) { /* ignore */ }
}
unset($__pfUsersSchemaOk);

// Allow Archived status on users (safe one-time ENUM widen)
$__pfUsersArchivedEnum = __DIR__ . '/../tmp/.printflow_users_archived_status_enum';
if (!is_file($__pfUsersArchivedEnum)) {
    try {
        $col = db_query("SHOW COLUMNS FROM users LIKE 'status'");
        $type = strtolower((string)(($col[0]['Type'] ?? '')));
        if ($type !== '' && strpos($type, 'archived') === false) {
            db_execute("ALTER TABLE users MODIFY COLUMN `status` ENUM('Activated','Pending','Deactivated','Archived') NOT NULL DEFAULT 'Pending'");
        }
        @file_put_contents($__pfUsersArchivedEnum, '1');
    } catch (Throwable $e) { /* ignore */ }
}
unset($__pfUsersArchivedEnum);

// Handle staff creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_staff']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $first_name  = sanitize($_POST['first_name']);
    $middle_name = sanitize($_POST['middle_name'] ?? '');
    $last_name   = sanitize($_POST['last_name']);
    $email      = sanitize($_POST['email']);
    $birthday   = sanitize($_POST['birthday'] ?? '');
    $password   = $_POST['password'];
    $role       = $_POST['role']; // 'Admin', 'Manager', or 'Staff'

    // Default password: email + birthday (MMDDYYYY) when not supplied by client
    if (empty($password) && !empty($birthday)) {
        $d = DateTime::createFromFormat('Y-m-d', $birthday);
        $password = $d ? ($email . $d->format('mdY')) : $email;
    }

    // Parse branch_id safely
    $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    if ($role === 'Admin') $branch_id = null; // Admins have global access

    $valid_roles = ['Manager', 'Staff']; // Admin creation removed
    if (!in_array($role, $valid_roles)) $role = 'Staff';

    // Name validation: letters only, single space between words (block 2+ consecutive spaces)
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = 'All required fields must be filled in';
    } elseif (preg_match('/\s{2,}/', trim($first_name)) || preg_match('/\s{2,}/', trim($last_name))) {
        $error = 'Names cannot have more than one space in a row.';
    } elseif (!preg_match("/^[A-Za-z]+( [A-Za-z]+)*$/", trim($first_name)) || !preg_match("/^[A-Za-z]+( [A-Za-z]+)*$/", trim($last_name))) {
        $error = 'Names must contain only letters.';
    } elseif ($middle_name !== '' && preg_match('/\s{2,}/', trim($middle_name))) {
        $error = 'Middle name cannot have more than one space in a row.';
    } elseif ($middle_name !== '' && !preg_match("/^[A-Za-z]+( [A-Za-z]+)*$/", trim($middle_name))) {
        $error = 'Middle name must contain only letters.';
    } elseif (strlen(trim($first_name)) < 2 || strlen(trim($first_name)) > 50 || strlen(trim($last_name)) < 2 || strlen(trim($last_name)) > 50) {
        $error = 'Names must be between 2 and 50 characters.';
    } elseif ($middle_name !== '' && (strlen(trim($middle_name)) < 2 || strlen(trim($middle_name)) > 50)) {
        $error = 'Middle name must be between 2 and 50 characters.';
    } elseif (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', trim($email))) {
        $error = 'Invalid email address.';
    } elseif (!empty($birthday)) {
        $bday_date = DateTime::createFromFormat('Y-m-d', $birthday);
        $bday_errors = DateTime::getLastErrors();
        if (!$bday_date || ($bday_errors['warning_count'] ?? 0) > 0 || ($bday_errors['error_count'] ?? 0) > 0) {
            $error = 'Invalid birthday format';
        } else {
            $today = new DateTime();
            $age = $today->diff($bday_date)->y;
            if ($bday_date > $today) {
                $error = 'Birthday cannot be a future date';
            } elseif ($age < 18) {
                $error = 'User must be at least 18 years old';
            } elseif ($age > 70) {
                $error = 'User must be 70 years old or younger';
            }
        }
    }

    if (!$error && strlen($password) < 8) {
        $error = 'Password must be at least 8 characters';
    } elseif ($role !== 'Admin' && empty($branch_id)) {
        $error = 'Please assign a branch for this role';
    } else {
        // Check if email exists in users or customers
        $existing_user = db_query("SELECT user_id FROM users WHERE email = ?", 's', [$email]);
        $existing_customer = db_query("SELECT customer_id FROM customers WHERE email = ?", 's', [$email]);

        if (!empty($existing_user) || !empty($existing_customer)) {
            $error = 'Email already exists';
        } else {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $bday_val = !empty($birthday) ? $birthday : null;
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

            printflow_run_guarded_account_insert(function() use ($first_name, $middle_name, $last_name, $bday_val, $email, $password_hash, $token, $expires, $role, $branch_id) {
                return db_execute(
                    "INSERT INTO users (first_name, middle_name, last_name, birthday, email, password_hash, profile_completion_token, profile_completion_expires, role, status, branch_id, created_by_system, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, 1, NOW(), NOW())",
                    'sssssssssi',
                    [$first_name, $middle_name, $last_name, $bday_val, $email, $password_hash, $token, $expires, $role, $branch_id]
                );
            });

            $site_url = defined('SITE_URL')
                ? SITE_URL
                : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (defined('BASE_PATH') ? BASE_PATH : '/printflow'));
            $complete_link = rtrim($site_url, '/') . '/public/complete_profile.php?token=' . urlencode($token);

            require_once __DIR__ . '/../includes/profile_completion_mailer.php';

            $sendInviteMail = function (string $to, string $first, string $link): array {
                try {
                    return send_profile_completion_email($to, $first, $link);
                } catch (Throwable $e) {
                    error_log('Profile completion email: ' . $e->getMessage());
                    return ['success' => false, 'message' => $e->getMessage()];
                }
            };

            /*
             * Email delivery:
             * - PHP-FPM / FastCGI: respond first, then send (fast UI; same process = SMTP still works).
             * - Apache mod_php / typical XAMPP: background CLI spawn is unreliable (wrong PHP_BINARY, php not in PATH),
             *   so send synchronously before redirect so the invite actually reaches the inbox.
             */
            if (function_exists('fastcgi_finish_request')) {
                $_SESSION['um_staff_create_success'] = $role . ' account created for ' . htmlspecialchars($email) . '. A profile completion email is being sent to their inbox.';
                session_write_close();
                header('Location: user_staff_management.php', true, 303);
                ignore_user_abort(true);
                fastcgi_finish_request();
                $mail_res = $sendInviteMail($email, $first_name, $complete_link);
                if (empty($mail_res['success'])) {
                    error_log('Profile completion email (post-response): ' . ($mail_res['message'] ?? 'unknown failure'));
                }
                exit;
            }

            $mail_res = $sendInviteMail($email, $first_name, $complete_link);
            if (!empty($mail_res['success'])) {
                $_SESSION['um_staff_create_success'] = $role . ' account created! A profile completion link has been sent to ' . htmlspecialchars($email) . '.';
            } else {
                $_SESSION['um_staff_create_success'] = $role . ' account created. The invitation email could not be sent'
                    . (!empty($mail_res['message']) ? ': ' . htmlspecialchars($mail_res['message']) : '')
                    . '. Share this link with them: ' . htmlspecialchars($complete_link);
            }
            session_write_close();
            header('Location: user_staff_management.php', true, 303);
            exit;
        }
    }
}

// Get all users with filters/sort
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$search        = trim($_GET['search'] ?? '');
$role_filter   = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort          = $_GET['sort'] ?? 'newest';
$dir           = $_GET['dir'] ?? 'DESC';
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to'] ?? '';

$sort_col_sql = match($sort) {
    'oldest' => 'u.created_at ASC',
    'az'     => 'u.first_name ASC',
    'za'     => 'u.first_name DESC',
    default  => 'u.created_at DESC',
};

$sql_base = "FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.status != 'Archived'";
$params = []; $types = '';

if (!empty($search)) {
    $like = '%'.$search.'%';
    $sql_base .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}
if (!empty($role_filter)) {
    $sql_base .= " AND u.role = ?";
    $params[] = $role_filter;
    $types .= 's';
}
if (!empty($status_filter)) {
    $sql_base .= " AND u.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if (!empty($date_from)) {
    $sql_base .= " AND DATE(u.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if (!empty($date_to)) {
    $sql_base .= " AND DATE(u.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$total_users = db_query("SELECT COUNT(*) as total $sql_base", $types ?: null, $params ?: null)[0]['total'] ?? 0;
$total_pages = max(1, ceil($total_users / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$users = db_query("SELECT u.*, b.branch_name $sql_base ORDER BY $sort_col_sql LIMIT $per_page OFFSET $offset", $types ?: null, $params ?: null) ?: [];

// Fetch available branches for the creation dropdown
$branches = db_query("SELECT id, branch_name FROM branches WHERE status != 'Archived' ORDER BY id ASC");

// Summary statistics (exclude archived from active directory counts)
$stat_total    = db_query("SELECT COUNT(*) as c FROM users WHERE status != 'Archived'")[0]['c'];
$stat_admins   = db_query("SELECT COUNT(*) as c FROM users WHERE role = 'Admin' AND status != 'Archived'")[0]['c'];
$stat_managers = db_query("SELECT COUNT(*) as c FROM users WHERE role = 'Manager' AND status != 'Archived'")[0]['c'];
$stat_staff    = db_query("SELECT COUNT(*) as c FROM users WHERE role = 'Staff' AND status != 'Archived'")[0]['c'];
$stat_active   = db_query("SELECT COUNT(*) as c FROM users WHERE status = 'Activated'")[0]['c'];
$stat_archived = (int)(db_query("SELECT COUNT(*) as c FROM users WHERE status = 'Archived'")[0]['c'] ?? 0);

// Sort helpers
$build_sort_url = function(string $col) use ($sort, $dir, $search, $role_filter, $status_filter): string {
    $p = array_filter(['sort'=>$col,'dir'=>($sort===$col&&$dir==='ASC')?'DESC':'ASC','search'=>$search,'role'=>$role_filter,'status'=>$status_filter], function($v) { return $v !== null && $v !== ''; });
    return '?'.http_build_query($p);
};
$sort_icon = fn(string $col): string => $sort===$col?($dir==='ASC'?' ▲':' ▼'):'';

// Flash message from process_create_manager.php
$manager_created = $_SESSION['cm_success'] ?? '';
unset($_SESSION['cm_success']);

if (!empty($_SESSION['um_staff_create_success'])) {
    $success = $_SESSION['um_staff_create_success'];
    unset($_SESSION['um_staff_create_success']);
}

$max_birthday = date('Y-m-d', strtotime('-18 years'));
$min_birthday = date('Y-m-d', strtotime('-70 years'));

$page_title = 'Team Management - Admin';

// Archived accounts (modal AJAX)
if (isset($_GET['get_archived'])) {
    header('Content-Type: application/json; charset=utf-8');
    $archived = db_query(
        "SELECT u.user_id, u.first_name, u.last_name, u.email, u.role, u.status, u.updated_at, b.branch_name
         FROM users u
         LEFT JOIN branches b ON u.branch_id = b.id
         WHERE u.status = 'Archived'
         ORDER BY u.updated_at DESC"
    ) ?: [];
    $csrf = $_SESSION['csrf_token'] ?? '';
    $html = '<table class="w-full text-sm" style="width:100%;border-collapse:collapse;">';
    $html .= '<thead><tr style="border-bottom:2px solid #e5e7eb;"><th class="text-left py-3">Name</th><th class="text-left py-3">Email</th><th class="text-left py-3">Role</th><th class="text-left py-3">Branch</th><th class="text-right py-3">Actions</th></tr></thead><tbody>';
    if (empty($archived)) {
        $html .= '<tr><td colspan="5" style="padding:40px;text-align:center;color:#9ca3af;">No archived accounts.</td></tr>';
    } else {
        foreach ($archived as $u) {
            $name = htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
            $email = htmlspecialchars(strtolower((string)($u['email'] ?? '')), ENT_QUOTES, 'UTF-8');
            $role = htmlspecialchars((string)($u['role'] ?? ''), ENT_QUOTES, 'UTF-8');
            $branch = htmlspecialchars((string)($u['branch_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $uid = (int)$u['user_id'];
            $html .= '<tr style="border-bottom:1px solid #f3f4f6;">';
            $html .= '<td class="py-3">' . $name . '</td>';
            $html .= '<td class="py-3">' . $email . '</td>';
            $html .= '<td class="py-3">' . $role . '</td>';
            $html .= '<td class="py-3">' . (($u['role'] ?? '') === 'Admin' ? '<span style="color:#9ca3af;">All Branches</span>' : ($branch !== '' ? $branch : '—')) . '</td>';
            $html .= '<td class="py-3 text-right">';
            $html .= '<button type="button" class="btn-action teal pf-restore-archived-user" data-user-id="' . $uid . '" data-csrf="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">Restore</button>';
            $html .= '</td></tr>';
        }
    }
    $html .= '</tbody></table>';
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}

// ── AJAX handler
if (isset($_GET['ajax'])) {
    ob_start();
    ?>
    <table class="w-full text-sm users-table">
        <thead><tr class="border-b-2">
            <th class="text-left py-3">ID</th>
            <th class="text-left py-3">Name</th>
            <th class="text-left py-3">Email</th>
            <th class="text-left py-3">Role</th>
            <th class="text-left py-3">Branch</th>
            <th class="text-left py-3">Status</th>
            <th class="text-right py-3">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php
            $um_disp_status = (($user['role'] ?? '') === 'Admin' && ($user['status'] ?? '') !== 'Archived')
                ? 'Activated'
                : ($user['status'] ?? '');
            ?>
            <tr class="border-b" onclick="window._viewUser && _viewUser(<?php echo $user['user_id']; ?>)">
                <td class="py-3"><?php echo $user['user_id']; ?></td>
                <td class="py-3 font-medium"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                <td class="py-3" style="text-transform:lowercase;"><?php echo htmlspecialchars(strtolower((string)($user['email'] ?? ''))); ?></td>
                <td class="py-3"><?php
                    $rs = match($user['role']) { 'Admin' => 'background:#fee2e2;color:#991b1b;', 'Manager' => 'background:#ede9fe;color:#5b21b6;', default => 'background:#dbeafe;color:#1e40af;' };
                    ?><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;<?php echo $rs; ?>"><?php echo $user['role']; ?></span></td>
                <td class="py-3"><?php echo $user['role']==='Admin' ? '<span class="text-gray-500 italic">All Branches</span>' : htmlspecialchars($user['branch_name'] ?? 'Unassigned'); ?></td>
                <td class="py-3"><?php
                    $sc = match($um_disp_status) {
                        'Activated' => 'background:#dcfce7;color:#166534;',
                        'Deactivated' => 'background:#fee2e2;color:#991b1b;',
                        'Archived' => 'background:#f3f4f6;color:#374151;',
                        default => 'background:#fef9c3;color:#854d0e;'
                    };
                    ?><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;<?php echo $sc; ?>"><?php echo htmlspecialchars($um_disp_status); ?></span></td>
                <td class="py-3 text-right" onclick="event.stopPropagation();">
                    <button type="button" class="btn-action blue" style="margin-right:4px;" onclick="window._viewUser && _viewUser(<?php echo $user['user_id']; ?>)">View</button>
                    <button type="button" class="btn-action teal" style="margin-right:4px;" onclick="window._editUser && _editUser(<?php echo $user['user_id']; ?>)">Edit</button>
                    <?php if (($user['status'] ?? '') === 'Deactivated'): ?>
                    <?php if (($user['role'] ?? '') !== 'Admin'): ?>
                    <button type="button" class="btn-action gray" style="margin-right:4px;" onclick="window._archiveUser && window._archiveUser(<?php echo $user['user_id']; ?>)">Archive</button>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
            <tr><td colspan="7" class="py-8 text-center text-gray-500">No users found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php
    $table_html = ob_get_clean();
    ob_start();
    $pp = array_filter(['search'=>$search,'role'=>$role_filter,'status'=>$status_filter,'sort'=>$sort,'dir'=>$dir,'date_from'=>$_GET['date_from']??'','date_to'=>$_GET['date_to']??''], function($v) { return $v !== null && $v !== ''; });
    echo render_pagination($page, $total_pages, $pp);
    $pagination_html = ob_get_clean();
    echo json_encode(['success'=>true,'table'=>$table_html,'pagination'=>$pagination_html,'count'=>number_format($total_users),'badge'=>count(array_filter([$search,$role_filter,$status_filter,$_GET['date_from']??'',$_GET['date_to']??''], function($v) { return $v !== null && $v !== ''; }))]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        [x-cloak] { display: none !important; }
        
        /* Prevent layout shift on initial load */
        .kpi-row, .card, .main-content { opacity: 1; }
        body:not(.alpine-loaded) [x-data] { visibility: hidden; }
        body.alpine-loaded [x-data] { visibility: visible; }

        /* KPI Cards – matching dashboard */
        .kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; min-width:0; }
        @media(max-width:900px) { .kpi-row { grid-template-columns:repeat(2,1fr); } }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; min-width:0; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.rose::before { background:linear-gradient(90deg,#e11d48,#fb7185); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .kpi-value { font-size:28px; font-weight:700; color:#111827; line-height:1.2; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

        /* Action Buttons (match branches page) */
        .btn-action { display:inline-flex; align-items:center; justify-content:center; padding:5px 12px; min-width:70px; border:1px solid transparent; background:transparent; border-radius:6px; font-size:12px; font-weight:500; transition:all 0.2s; cursor:pointer; text-decoration:none; white-space:nowrap; }
        .btn-action.blue { color:#3b82f6; border-color:#3b82f6; }
        .btn-action.blue:hover { background:#3b82f6; color:white; }
        .btn-action.teal { color:#0d9488; border-color:#0d9488; }
        .btn-action.teal:hover { background:#0d9488; color:white; }
        .btn-action.red { color:#dc2626; border-color:#dc2626; }
        .btn-action.red:hover { background:#dc2626; color:white; }
        .btn-action.gray { color:#6b7280; border-color:#d1d5db; }
        .btn-action.gray:hover { background:#f3f4f6; color:#374151; }

        /* ===== VIEW MODAL - CUSTOMIZATION STYLE ===== */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9900; align-items:center; justify-content:center; padding:16px; }
        .modal-overlay.is-open { display:flex; }
        .modal-box { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); width:100%; max-width:640px; max-height:calc(100vh - 32px); display:flex; flex-direction:column; position:relative; overflow:hidden; }
        .modal-hdr { display:flex; align-items:center; justify-content:space-between; padding:18px 24px; border-bottom:1px solid #f3f4f6; flex-shrink:0; }
        .modal-hdr h2 { font-size:18px; font-weight:700; color:#1f2937; margin:0; }
        .modal-hdr button { background:transparent; border:none; font-size:20px; color:#6b7280; cursor:pointer; line-height:1; padding:2px 6px; }
        .modal-hdr button:hover { color:#374151; }
        .modal-bdy { padding:24px; overflow-y:auto; flex:1; max-height: calc(100vh - 200px); }
        
        /* Detail Blocks */
        .detail-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
        .detail-block { flex:1; min-width:140px; background:#f9fafb; border-radius:8px; padding:12px 14px; }
        .detail-block label { font-size:11px; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:.4px; display:block; margin-bottom:4px; }
        .detail-block span { font-size:13px; font-weight:400; color:#1f2937; word-wrap:break-word; overflow-wrap:break-word; }
        .mf-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
        .mf-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:14px; }
        .mf-full { grid-column:1/-1; }
        .mf-group { display: flex; flex-direction: column; }
        .mf-group label { display:block; font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
        .mf-group input, .mf-group select, .mf-group textarea { width:100%; padding:9px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; color:#111827; background:#fafafa; outline:none; transition:border-color .15s; box-sizing:border-box; }
        .mf-group input:focus, .mf-group select:focus, .mf-group textarea:focus { border-color:#6366f1; background:#fff; }
        .mf-group input:disabled { background:#f3f4f6; color:#9ca3af; cursor:not-allowed; }
        .mf-group textarea { resize:none; }
        .mf-divider { border:none; border-top:1px solid #f3f4f6; margin:16px 0; }
        .mf-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; border-top:1px solid #f3f4f6; flex-wrap:wrap; flex-shrink:0; }
        .mf-btn-cancel { padding:7px 14px; min-width:70px; border:1px solid #e5e7eb; background:#fff; border-radius:6px; font-size:12px; font-weight:500; color:#374151; cursor:pointer; transition:all 0.2s; }
        .mf-btn-cancel:hover { background:#f9fafb; }
        .mf-btn-save { padding:7px 14px; min-width:70px; border:none; border-radius:8px; background:#4f46e5; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
        .mf-btn-save:disabled { opacity:.5; cursor:not-allowed; }
        /* Modal action buttons – outline style (match table actions) */
        .mf-btn-outline { display:inline-flex; align-items:center; justify-content:center; padding:5px 12px; min-width:70px; border:1px solid transparent; background:transparent; border-radius:6px; font-size:12px; font-weight:500; transition:all 0.2s; cursor:pointer; }
        .mf-btn-outline.blue { color:#3b82f6; border-color:#3b82f6; }
        .mf-btn-outline.blue:hover { background:#3b82f6; color:white; }
        .mf-btn-outline.teal { color:#0d9488; border-color:#0d9488; }
        .mf-btn-outline.teal:hover { background:#0d9488; color:white; }
        .mf-btn-outline.red { color:#dc2626; border-color:#dc2626; }
        .mf-btn-outline.red:hover { background:#dc2626; color:white; }
        .mf-btn-outline.gray { color:#6b7280; border-color:#d1d5db; }
        .mf-btn-outline.gray:hover { background:#f3f4f6; color:#374151; }
        .mf-btn-outline:disabled { opacity:.5; cursor:not-allowed; }
        .mf-alert { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px; }
        .mf-alert.ok { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
        .mf-alert.err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
        .pf-submit-overlay { position:fixed; inset:0; z-index:12000; display:none; align-items:center; justify-content:center; background:rgba(1,22,28,0.52); backdrop-filter:blur(2px); }
        .pf-submit-overlay.is-open { display:flex; }
        .pf-submit-spinner { width:48px; height:48px; border-radius:50%; border:5px solid rgba(217,243,240,0.35); border-top-color:#0d9488; animation:pf-submit-spin 0.72s linear infinite; }
        @keyframes pf-submit-spin { to { transform: rotate(360deg); } }
        
        /* Validation States */
        .mf-group.is-invalid input, .mf-group.is-invalid select, .mf-group.is-invalid textarea {
            border-color: #ef4444 !important;
            background-color: #fff9f9 !important;
        }
        .mf-group.is-valid input, .mf-group.is-valid select, .mf-group.is-valid textarea {
            border-color: #10b981 !important;
        }
        .error-message {
            color: #ef4444;
            font-size: 11px;
            margin-top: 4px;
            display: none;
            font-weight: 500;
        }
        .mf-group.is-invalid .error-message {
            display: block;
        }
        @media(max-width:520px) { .mf-row { grid-template-columns:1fr; } }

        /* ─── Standardized Toolbar Styles ─── */
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
        .toolbar-btn.active { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
        .toolbar-btn svg { flex-shrink: 0; }

        /* Table hover + clickable rows (inventory-style) */
        .users-table tbody tr { cursor: pointer; transition: background 0.1s; }
        .users-table tbody tr:hover td { background: #f9fafb; }

        .toolbar-btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            height: 38px;
            border: 1px solid #3b82f6;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #3b82f6;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
            box-sizing: border-box;
        }
        .toolbar-btn-primary:hover {
            background: #eff6ff;
            border-color: #2563eb;
            color: #2563eb;
        }

        /* ── Filter Panel ─── */
        .filter-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 320px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 100;
            overflow: hidden;
        }
        .filter-panel-header {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }
        .filter-section {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
        }
        .filter-section:last-of-type { border-bottom: none; }
        .filter-section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .filter-section-label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .filter-reset-link {
            font-size: 12px;
            font-weight: 600;
            color: #0d9488;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        .filter-reset-link:hover { text-decoration: underline; }
        .filter-input {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            box-sizing: border-box;
            transition: border-color 0.15s;
        }
        .filter-input:focus { outline: none; border-color: #0d9488; }
        .filter-select {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            background: #fff;
            box-sizing: border-box;
            cursor: pointer;
        }
        .filter-select:focus { outline: none; border-color: #0d9488; }
        .filter-search-input {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 12px;
            color: #1f2937;
            box-sizing: border-box;
        }
        .filter-search-input:focus { outline: none; border-color: #0d9488; }
        .filter-actions {
            display: flex;
            gap: 8px;
            padding: 14px 18px;
            border-top: 1px solid #f3f4f6;
        }
        .filter-btn-reset {
            flex: 1;
            height: 36px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
        }
        .filter-btn-reset:hover { background: #f9fafb; }

        /* ── Sort Dropdown ─── */
        .sort-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 200px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 200;
            padding: 6px 0;
            overflow: hidden;
        }
        .sort-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            font-size: 13px;
            color: #374151;
            cursor: pointer;
            transition: background 0.1s;
        }
        .sort-option:hover { background: #f9fafb; }
        .sort-option.selected { color: #0d9488; font-weight: 600; background: #f0fdfa; }
        .sort-option .check { margin-left: auto; color: #0d9488; }

        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: #0d9488;
            color: #fff;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 700;
        }

        .filter-date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .filter-date-label { font-size: 11px; color: #6b7280; margin-bottom: 4px; }

        /* Create-user modal */
        #user-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9800; justify-content:center; align-items:center; padding:16px; }
        #user-modal-backdrop.is-open { display:flex; }
        #user-modal-box { background:#fff; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,0.15); max-width:680px; width:100%; max-height:90vh; overflow-y:auto; }
        #user-modal-box .modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px 16px; border-bottom:1px solid #f3f4f6; }
        #user-modal-box .modal-title { font-size:16px; font-weight:700; color:#111827; margin:0; }
        #user-modal-box .modal-close-x { background:none; border:none; font-size:20px; color:#9ca3af; cursor:pointer; }
        #user-modal-box .modal-close-x:hover { color:#374151; }
        #user-modal-box .modal-body { padding: 20px 24px; }
        #user-modal-box .form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        #user-modal-box .form-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
        #user-modal-box .form-group { margin-bottom:14px; }
        #user-modal-box .form-group label { display:block; font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
        #user-modal-box .form-group input, #user-modal-box .form-group select { width:100%; padding:9px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; color:#1f2937; background:#fafafa; outline:none; box-sizing:border-box; transition:border-color .15s; }
        #user-modal-box .form-group input:focus, #user-modal-box .form-group select:focus { border-color:#4f46e5; background:#fff; }
        #user-modal-box .form-group.is-invalid input, #user-modal-box .form-group.is-invalid select { border-color:#ef4444; background:#fff9f9; }
        #user-modal-box .form-hint { font-size:11px; color:#9ca3af; margin-top:4px; }
        #user-modal-box .modal-actions { display:flex; gap:10px; padding:16px 24px; border-top:1px solid #f3f4f6; }
        #user-modal-box .modal-btn { flex:1; padding:10px 16px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; border:none; }
        #user-modal-box .modal-btn-cancel { background:#f3f4f6; color:#4b5563; border:1px solid #e5e7eb; }
        #user-modal-box .modal-btn-submit { background:#0d9488; color:#fff; }
        #user-modal-box .modal-btn-submit:hover { background:#0f766e; }
        @media(max-width:520px) { #user-modal-box .form-row { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <header>
            <h1 class="page-title">Team Management</h1>
        </header>

        <main x-data="userManagement()" x-init="$nextTick(() => { if (typeof loadProvinces === 'function') loadProvinces(); })" style="min-height:400px;">
            <!-- KPI Summary Cards (matching dashboard) -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <div class="kpi-label">Total Users</div>
                    <div class="kpi-value"><?php echo (int)$stat_total; ?></div>
                    <div class="kpi-sub">Excluding archived</div>
                </div>
                <div class="kpi-card rose">
                    <div class="kpi-label">Admins</div>
                    <div class="kpi-value"><?php echo $stat_admins; ?></div>
                    <div class="kpi-sub">Administrator roles</div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-label">Managers</div>
                    <div class="kpi-value"><?php echo $stat_managers; ?></div>
                    <div class="kpi-sub">Branch managers</div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-label">Active Accounts</div>
                    <div class="kpi-value"><?php echo $stat_active; ?></div>
                    <div class="kpi-sub">Activated users</div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success || $manager_created): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
                    <?php echo htmlspecialchars($success ?: $manager_created); ?>
                </div>
            <?php endif; ?>

            <!-- Users Table -->
            <div class="card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px;" x-data="filterPanel()">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Team Members List</h3>

                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap;">
                        <!-- Add User Button -->
                        <button type="button" id="btn-open-user-modal" class="toolbar-btn-primary" style="height:38px;">
                            Add Team Member
                        </button>
                        <button type="button" id="btn-open-archived-users" class="toolbar-btn" style="height:38px;" onclick="window.openArchivedUsersModal && window.openArchivedUsersModal()">
                            Archived<?php if ($stat_archived > 0): ?> <span style="display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;background:#e5e7eb;color:#374151;border-radius:999px;font-size:11px;font-weight:700;"><?php echo (int)$stat_archived; ?></span><?php endif; ?>
                        </button>

                        <!-- Sort Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{ active: sortOpen || activeSort !== 'newest' }" @click="sortOpen = !sortOpen; filterOpen = false" id="sortBtn" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/>
                                </svg>
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <?php
                                $sorts = [
                                    'newest' => 'Newest to Oldest',
                                    'oldest' => 'Oldest to Newest',
                                    'az'     => 'A → Z',
                                    'za'     => 'Z → A',
                                ];
                                foreach ($sorts as $key => $label): ?>
                                <div class="sort-option" 
                                     :class="{ 'selected': activeSort === '<?php echo $key; ?>' }"
                                     @click="applySortFilter('<?php echo $key; ?>')">
                                    <?php echo htmlspecialchars($label); ?>
                                    <svg x-show="activeSort === '<?php echo $key; ?>'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Filter Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{ active: filterOpen || hasActiveFilters }" @click="filterOpen = !filterOpen; sortOpen = false" id="filterBtn" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                Filter
                                <span id="filterBadgeContainer">
                                    <?php
                                    $active_filters = array_filter([$role_filter, $status_filter, $search, $date_from, $date_to], function($v) { return $v !== null && $v !== ''; });
                                    if (count($active_filters) > 0): ?>
                                    <span class="filter-badge"><?php echo count($active_filters); ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>

                            <!-- Filter Panel -->
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false" id="filterPanel">
                                <div class="filter-panel-header">Filter</div>

                                <!-- Date Range -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Date range</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['date_from','date_to'])">Reset</button>
                                    </div>
                                    <div class="filter-date-row">
                                        <div>
                                            <div class="filter-date-label">From:</div>
                                            <input type="date" id="fp_date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                                        </div>
                                        <div>
                                            <div class="filter-date-label">To:</div>
                                            <input type="date" id="fp_date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                                        </div>
                                    </div>
                                </div>

                                <!-- Role -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Role</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['role'])">Reset</button>
                                    </div>
                                    <select id="fp_role" class="filter-select">
                                        <option value="">All roles</option>
                                        <option value="Admin"   <?php echo $role_filter === 'Admin'   ? 'selected' : ''; ?>>Admin</option>
                                        <option value="Manager" <?php echo $role_filter === 'Manager' ? 'selected' : ''; ?>>Manager</option>
                                        <option value="Staff"   <?php echo $role_filter === 'Staff'   ? 'selected' : ''; ?>>Staff</option>
                                    </select>
                                </div>

                                <!-- Status -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Status</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['status'])">Reset</button>
                                    </div>
                                    <select id="fp_status" class="filter-select">
                                        <option value="">All statuses</option>
                                        <option value="Activated"   <?php echo $status_filter === 'Activated'   ? 'selected' : ''; ?>>Activated</option>
                                        <option value="Deactivated" <?php echo $status_filter === 'Deactivated' ? 'selected' : ''; ?>>Deactivated</option>
                                    </select>
                                </div>

                                <!-- Keyword Search -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <div class="filter-search-wrap">
                                        <input type="text" id="fp_search" class="filter-search-input" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" style="width: 100%;" onclick="applyFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="usersTableContainer">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm users-table">
                        <thead>
                            <tr class="border-b-2">
                                <th class="text-left py-3">ID</th>
                                <th class="text-left py-3">Name</th>
                                <th class="text-left py-3">Email</th>
                                <th class="text-left py-3">Role</th>
                                <th class="text-left py-3">Branch</th>
                                <th class="text-left py-3">Status</th>
                                <th class="text-right py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <?php
                                $um_disp_status = (($user['role'] ?? '') === 'Admin' && ($user['status'] ?? '') !== 'Archived')
                                    ? 'Activated'
                                    : ($user['status'] ?? '');
                                ?>
                                <tr class="border-b" @click="viewUser(<?php echo $user['user_id']; ?>)" style="cursor:pointer;">
                                    <td class="py-3"><?php echo $user['user_id']; ?></td>
                                    <td class="py-3 font-medium">
                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                    </td>
                                    <td class="py-3" style="text-transform:lowercase;"><?php echo htmlspecialchars(strtolower((string)($user['email'] ?? ''))); ?></td>
                                    <td class="py-3">
                                        <?php
                                            $role_style = match($user['role']) {
                                                'Admin'   => 'background:#fee2e2;color:#991b1b;',
                                                'Manager' => 'background:#ede9fe;color:#5b21b6;',
                                                default   => 'background:#dbeafe;color:#1e40af;'
                                            };
                                        ?>
                                        <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;<?php echo $role_style; ?>">
                                            <?php echo $user['role']; ?>
                                        </span>
                                    </td>
                                    <td class="py-3">
                                        <?php if ($user['role'] === 'Admin'): ?>
                                            <span class="text-gray-500 italic">All Branches</span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($user['branch_name'] ?? 'Unassigned'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3">
                                        <?php
                                            $sc = match($um_disp_status) {
                                                'Activated'   => 'background:#dcfce7;color:#166534;',
                                                'Deactivated' => 'background:#fee2e2;color:#991b1b;',
                                                'Archived'    => 'background:#f3f4f6;color:#374151;',
                                                default       => 'background:#fef9c3;color:#854d0e;'
                                            };
                                        ?>
                                        <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;<?php echo $sc; ?>">
                                            <?php echo htmlspecialchars($um_disp_status); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 text-right" @click.stop>
                                        <button type="button" @click="viewUser(<?php echo $user['user_id']; ?>)" class="btn-action blue" style="margin-right:4px;">View</button>
                                        <button type="button" @click="editUser(<?php echo $user['user_id']; ?>)" class="btn-action teal" style="margin-right:4px;">Edit</button>
                                        <?php if (($user['status'] ?? '') === 'Deactivated'): ?>
                                        <?php if (($user['role'] ?? '') !== 'Admin'): ?>
                                        <button type="button" @click="showArchiveConfirm(<?php echo $user['user_id']; ?>)" class="btn-action gray" style="margin-right:4px;">Archive</button>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
                <div id="usersPagination">
                    <?php
                    $pagination_params = array_filter(['search'=>$search,'role'=>$role_filter,'status'=>$status_filter,'sort'=>$sort,'dir'=>$dir,'date_from'=>$date_from,'date_to'=>$date_to], function($v) { return $v !== null && $v !== ''; });
                    echo render_pagination($page, $total_pages, $pagination_params);
                    ?>
                </div>
                </div><!-- /usersTableContainer -->
            </div><!-- /card -->

            <!-- Modals: inside main so Alpine sees userManagement() scope (view/edit/confirm/resend) -->
            <!-- View User Modal (Read-only) -->
            <div x-show="viewModal.isOpen" x-cloak class="modal-overlay" :class="{'is-open': viewModal.isOpen}" @click.self="viewModal.isOpen = false">
    <div class="modal-box" @click.stop>
        <div class="modal-hdr">
            <div>
                <h2 x-text="'Team Member #' + (viewModal.user?.user_id || '')"></h2>
                <div x-show="viewModal.user" style="margin-top:4px;">
                    <span :style="viewModal.user?.status === 'Activated' ? 'background:#dcfce7;color:#166534;' : (viewModal.user?.status === 'Deactivated' ? 'background:#fee2e2;color:#991b1b;' : (viewModal.user?.status === 'Archived' ? 'background:#f3f4f6;color:#374151;' : 'background:#fef9c3;color:#854d0e;'))" style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;" x-text="viewModal.user?.status || '—'"></span>
                </div>
            </div>
            <button @click="viewModal.isOpen = false">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div x-show="viewModal.loading" style="padding:48px;text-align:center;">
            <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
            <p style="color:#6b7280;font-size:14px;">Loading details...</p>
        </div>
        <template x-if="viewModal.user && !viewModal.loading">
            <div class="modal-bdy">
                <!-- Personal Information -->
                <div style="margin-bottom:18px;">
                    <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:8px;">Personal Information</p>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>First Name</label>
                            <span x-text="viewModal.user?.first_name || '—'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Middle Name</label>
                            <span x-text="viewModal.user?.middle_name || '—'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Last Name</label>
                            <span x-text="viewModal.user?.last_name || '—'"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Email Address</label>
                            <span x-text="viewModal.user?.email || '—'" style="word-break:break-all;"></span>
                        </div>
                        <div class="detail-block">
                            <label>Contact Number</label>
                            <span x-text="viewModal.user?.contact_number || '—'"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Date of Birth</label>
                            <span x-text="viewModal.user?.dob ? new Date(viewModal.user.dob).toLocaleDateString('en-US', {month:'long',day:'numeric',year:'numeric'}) : '—'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Gender</label>
                            <span x-text="viewModal.user?.gender || '—'"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block" style="flex:1 1 100%;">
                            <label>Address</label>
                            <span x-text="viewModal.user?.address || '—'" style="display:block;"></span>
                        </div>
                    </div>
                </div>

                <!-- Work Information -->
                <div style="margin-bottom:18px;">
                    <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:8px;">Work Information</p>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Role</label>
                            <span x-text="viewModal.user?.role || '—'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Branch</label>
                            <span x-text="viewModal.user?.role === 'Admin' ? 'All Branches' : (viewModal.user?.branch_name || 'Unassigned')"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Member Since</label>
                            <span x-text="viewModal.user?.created_at ? new Date(viewModal.user.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'}) : '—'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Account Status</label>
                            <span :style="viewModal.user?.status === 'Activated' ? 'background:#dcfce7;color:#166534;' : (viewModal.user?.status === 'Deactivated' ? 'background:#fee2e2;color:#991b1b;' : (viewModal.user?.status === 'Archived' ? 'background:#f3f4f6;color:#374151;' : 'background:#fef9c3;color:#854d0e;'))" style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;" x-text="viewModal.user?.status || '—'"></span>
                        </div>
                    </div>
                </div>

                <!-- ID Validation -->
                <div x-show="viewModal.user?.id_validation_image" style="margin-bottom:18px;">
                    <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:8px;">ID Validation</p>
                    <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:12px;">
                        <p x-show="viewModal.user?.id_type" style="font-size:13px; color:#374151; margin:0 0 8px 0;">
                            <strong>ID Type:</strong> <span x-text="viewModal.user?.id_type"></span>
                        </p>
                        <p style="font-size:11px; color:#6b7280; margin:0 0 8px 0;">Reference guide:</p>
                        <img src="<?php echo $base_path; ?>/uploads/id_validation.png" alt="Valid vs Invalid ID" style="max-width:100%; height:auto; border-radius:6px; margin-bottom:8px;">
                        <template x-if="viewModal.user?.id_validation_image">
                            <div>
                                <img :src="'<?php echo $base_path; ?>/uploads/ids/' + viewModal.user.id_validation_image" alt="Uploaded ID" style="max-width:100%; height:auto; border-radius:6px; border:1px solid #e5e7eb;">
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>
        <div x-show="viewModal.user && !viewModal.loading" class="mf-footer">
            <button type="button" @click="viewModal.isOpen = false" class="mf-btn-outline blue">Close</button>
            <template x-if="viewModal.user?.status === 'Pending'">
                <button type="button" @click="showActivateConfirm(viewModal.user.user_id)" class="mf-btn-outline teal">Activate Account</button>
            </template>
            <template x-if="viewModal.user?.status === 'Pending'">
                <button type="button" @click="openResendModal(viewModal.user.user_id)" class="mf-btn-outline teal">Resend Link</button>
            </template>
            <template x-if="viewModal.user?.status === 'Activated' && viewModal.user?.role !== 'Admin'">
                <button type="button" @click="showDeactivateConfirm(viewModal.user.user_id)" class="mf-btn-outline teal">Deactivate Account</button>
            </template>
            <template x-if="viewModal.user?.status === 'Deactivated' && viewModal.user?.role !== 'Admin'">
                <button type="button" @click="showArchiveConfirm(viewModal.user.user_id)" class="mf-btn-outline gray">Archive Account</button>
            </template>
            <button type="button" @click="viewModal.isOpen = false; editUser(viewModal.user?.user_id)" class="mf-btn-outline teal">Edit</button>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div x-show="editModal.isOpen" x-cloak class="modal-overlay" :class="{'is-open': editModal.isOpen}" @click.self="editModal.isOpen = false">
    <div class="modal-box" @click.stop>
        <div class="modal-hdr">
            <h2>Edit Team Member</h2>
            <button @click="editModal.isOpen = false">&times;</button>
        </div>
        <div x-show="editModal.loading" style="padding:48px;text-align:center;">
            <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
            <p style="color:#6b7280;font-size:14px;">Loading details...</p>
        </div>
        <div x-show="!editModal.loading">
            <div class="modal-bdy">
                <div x-show="editModal.error" class="mf-alert err" x-text="editModal.error"></div>
                <div x-show="editModal.success" class="mf-alert ok" x-text="editModal.success"></div>
                <form @submit.prevent="saveUserChanges">
                    <div class="mf-row-3">
                        <div class="mf-group" :class="{'is-invalid': errors.first_name, 'is-valid': editModal.user.first_name && !errors.first_name}">
                            <label>First Name *</label>
                            <input type="text" x-model="editModal.user.first_name" @input="validateField('first_name')" @keydown="if (/\d/.test($event.key)) $event.preventDefault(); if ($event.key.length === 1 && !/[a-zA-Z ]/.test($event.key)) $event.preventDefault(); if ($event.key === ' ' && ($event.target.selectionStart === 0 || $event.target.value.endsWith(' '))) $event.preventDefault(); if ($event.target.value.length >= 50 && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab'].includes($event.key) && $event.key.length === 1) $event.preventDefault();" required>
                            <div class="error-message" x-text="errors.first_name"></div>
                        </div>
                        <div class="mf-group" :class="{'is-invalid': errors.middle_name, 'is-valid': editModal.user.middle_name && !errors.middle_name}">
                            <label>Middle Name</label>
                            <input type="text" x-model="editModal.user.middle_name" @input="validateField('middle_name')" @keydown="if (/\d/.test($event.key)) $event.preventDefault(); if ($event.key.length === 1 && !/[a-zA-Z ]/.test($event.key)) $event.preventDefault(); if ($event.key === ' ' && ($event.target.selectionStart === 0 || $event.target.value.endsWith(' '))) $event.preventDefault(); if ($event.target.value.length >= 50 && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab'].includes($event.key) && $event.key.length === 1) $event.preventDefault();">
                            <div class="error-message" x-text="errors.middle_name"></div>
                        </div>
                        <div class="mf-group" :class="{'is-invalid': errors.last_name, 'is-valid': editModal.user.last_name && !errors.last_name}">
                            <label>Last Name *</label>
                            <input type="text" x-model="editModal.user.last_name" @input="validateField('last_name')" @keydown="if (/\d/.test($event.key)) $event.preventDefault(); if ($event.key.length === 1 && !/[a-zA-Z ]/.test($event.key)) $event.preventDefault(); if ($event.key === ' ' && ($event.target.selectionStart === 0 || $event.target.value.endsWith(' '))) $event.preventDefault(); if ($event.target.value.length >= 50 && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab'].includes($event.key) && $event.key.length === 1) $event.preventDefault();" required>
                            <div class="error-message" x-text="errors.last_name"></div>
                        </div>
                    </div>
                    <div class="mf-row">
                        <div class="mf-group"><label>Email Address</label><input type="email" :value="editModal.user.email || ''" disabled></div>
                        <div class="mf-group" :class="{'is-invalid': errors.contact_number, 'is-valid': editModal.user.contact_number && !errors.contact_number}">
                            <label>Contact Number *</label>
                            <input type="text" x-model="editModal.user.contact_number" @input="validateField('contact_number')" placeholder="e.g. 09171234567" maxlength="11" required>
                            <div class="error-message" x-text="errors.contact_number"></div>
                        </div>
                    </div>
                    <div class="mf-row">
                        <div class="mf-group" :class="{'is-invalid': errors.dob, 'is-valid': editModal.user.dob && !errors.dob}">
                            <label>Date of Birth *</label>
                            <input type="date" x-model="editModal.user.dob" @change="validateField('dob')" required min="<?php echo $min_birthday; ?>" max="<?php echo $max_birthday; ?>">
                            <div class="error-message" x-text="errors.dob"></div>
                        </div>
                        <div class="mf-group"><label>Gender</label>
                            <select x-model="editModal.user.gender">
                                <option value="">-- Select --</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mf-row">
                        <div class="mf-group">
                            <label>Province *</label>
                            <select x-model="editModal.user.address_province" @change="loadCities()" :disabled="!addressProvinces || !addressProvinces.length">
                                <option value="">Select province</option>
                                <template x-for="p in (addressProvinces || [])" :key="p.code">
                                    <option :value="p.name" x-text="p.name"></option>
                                </template>
                            </select>
                        </div>
                        <div class="mf-group">
                            <label>City / Municipality *</label>
                            <select x-model="editModal.user.address_city" @change="loadBarangays()" :disabled="!editModal.user.address_province || loadingCities">
                                <option value="" x-text="loadingCities ? 'Loading...' : 'Select city/municipality'"></option>
                                <template x-for="c in (addressCities || [])" :key="c.code">
                                    <option :value="c.name" x-text="c.name"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    <div class="mf-row">
                        <div class="mf-group">
                            <label>Barangay *</label>
                            <select x-model="editModal.user.address_barangay" @change="buildAddress()" :disabled="!editModal.user.address_city || loadingBarangays">
                                <option value="" x-text="loadingBarangays ? 'Loading...' : 'Select barangay'"></option>
                                <template x-for="b in (addressBarangays || [])" :key="b.code">
                                    <option :value="b.name" x-text="b.name"></option>
                                </template>
                            </select>
                        </div>
                        <div class="mf-group">
                            <label>Street / House No. (Optional)</label>
                            <input type="text" x-model="editModal.user.address_line" @input="buildAddress()" @keydown="if ($event.key === ' ' && ($event.target.selectionStart === 0 || $event.target.value.endsWith(' '))) $event.preventDefault(); if ($event.target.value.length >= 120 && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab'].includes($event.key) && $event.key.length === 1) $event.preventDefault();" maxlength="120" placeholder="e.g. 123 Rizal St.">
                        </div>
                    </div>
                    <div class="mf-row">
                        <div class="mf-group mf-full" :class="{'is-invalid': errors.address, 'is-valid': editModal.user.address && !errors.address}">
                            <label>Address Preview</label>
                            <textarea x-model="editModal.user.address" rows="2" readonly placeholder="Select province, city, and barangay"></textarea>
                            <div class="error-message" x-text="errors.address"></div>
                        </div>
                    </div>
                    <hr class="mf-divider">
                    <div class="mf-row">
                        <div class="mf-group">
                            <label>Role *</label>
                            <select x-model="editModal.user.role" required @change="if (editModal.user.role === 'Admin') editModal.user.status = 'Activated'">
                                <option value="Staff">Staff</option>
                                <option value="Manager">Manager</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>
                        <div class="mf-group" x-show="editModal.user.role === 'Staff' || editModal.user.role === 'Manager'">
                            <label>Branch Assignment</label>
                            <select x-model="editModal.user.branch_id">
                                <option value="">-- No Branch --</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mf-group" x-show="editModal.user.role === 'Admin'"><label>Branch</label><input type="text" value="All Branches" disabled></div>
                    </div>
                    <div class="mf-row">
                        <div class="mf-group">
                            <label>Account Status</label>
                            <template x-if="editModal.user.role === 'Admin'">
                                <input type="text" value="Activated" disabled style="width:100%;padding:10px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;color:#374151;">
                            </template>
                            <template x-if="editModal.user.role !== 'Admin'">
                                <select x-model="editModal.user.status">
                                    <option value="Activated">Activated</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Deactivated">Deactivated</option>
                                </select>
                            </template>
                        </div>
                        <div class="mf-group"><label>Member Since</label><input type="text" :value="editModal.user.created_at ? new Date(editModal.user.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'}) : ''" disabled></div>
                    </div>
                    <div class="mf-footer">
                        <button type="button" @click="editModal.isOpen = false" class="mf-btn-outline blue">Cancel</button>
                        <button type="submit" class="mf-btn-outline teal" :disabled="editModal.saving || !isEditFormValid" x-text="editModal.saving ? 'Saving...' : 'Save Changes'"></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Activate Account Confirmation Modal -->
<div x-show="activateConfirm.isOpen" x-cloak class="modal-overlay" :class="{'is-open': activateConfirm.isOpen}" @click.self="activateConfirm.isOpen = false">
    <div class="modal-box" style="max-width:400px;" @click.stop>
        <div class="modal-hdr">
            <h2>Activate Account</h2>
            <button @click="activateConfirm.isOpen = false">&times;</button>
        </div>
        <div class="modal-bdy">
            <p style="margin:0 0 20px 0; color:#374151;">Are you sure you want to activate this account? The staff will be notified via email and can log in.</p>
            <div class="mf-footer" style="border:none; padding:0;">
                <button type="button" @click="activateConfirm.isOpen = false" class="mf-btn-outline blue">Cancel</button>
                <button type="button" @click="confirmActivateUser()" class="mf-btn-outline teal">Activate</button>
            </div>
        </div>
    </div>
</div>

<!-- Deactivate Account Confirmation Modal -->
<div x-show="deactivateConfirm.isOpen" x-cloak class="modal-overlay" :class="{'is-open': deactivateConfirm.isOpen}" @click.self="deactivateConfirm.isOpen = false">
    <div class="modal-box" style="max-width:400px;" @click.stop>
        <div class="modal-hdr">
            <h2>Deactivate Account</h2>
            <button @click="deactivateConfirm.isOpen = false">&times;</button>
        </div>
        <div class="modal-bdy">
            <p style="margin:0 0 20px 0; color:#374151;">Are you sure you want to deactivate this account? The user will no longer be able to log in.</p>
            <div class="mf-footer" style="border:none; padding:0;">
                <button type="button" @click="deactivateConfirm.isOpen = false" class="mf-btn-outline blue">Cancel</button>
                <button type="button" @click="confirmDeactivateUser()" class="mf-btn-outline teal">Deactivate</button>
            </div>
        </div>
    </div>
</div>

<!-- Archive Account Confirmation Modal -->
<div x-show="archiveConfirm.isOpen" x-cloak class="modal-overlay" :class="{'is-open': archiveConfirm.isOpen}" @click.self="archiveConfirm.isOpen = false">
    <div class="modal-box" style="max-width:400px;" @click.stop>
        <div class="modal-hdr">
            <h2>Archive Account</h2>
            <button @click="archiveConfirm.isOpen = false">&times;</button>
        </div>
        <div class="modal-bdy">
            <p style="margin:0 0 20px 0; color:#374151;">Move this deactivated account to archive? It will be hidden from the team list until restored.</p>
            <div class="mf-footer" style="border:none; padding:0;">
                <button type="button" @click="archiveConfirm.isOpen = false" class="mf-btn-outline blue">Cancel</button>
                <button type="button" @click="confirmArchiveUser()" class="mf-btn-outline gray" :disabled="archiveConfirm.archiving" x-text="archiveConfirm.archiving ? 'Archiving...' : 'Archive'"></button>
            </div>
        </div>
    </div>
</div>

<!-- Resend Completion Link Modal (with admin notes) -->
<div x-show="resendModal.isOpen" x-cloak class="modal-overlay" :class="{'is-open': resendModal.isOpen}" @click.self="resendModal.isOpen = false">
    <div class="modal-box" style="max-width:420px;" @click.stop>
        <div class="modal-hdr">
            <h2>Send Completion Link Again</h2>
            <button @click="resendModal.isOpen = false">&times;</button>
        </div>
        <div class="modal-bdy">
            <p style="margin:0 0 16px 0; font-size:13px; color:#6b7280;">Select what needs to be fixed so the staff is aware:</p>
            <div x-show="resendModal.error" x-cloak class="mf-alert err" x-text="resendModal.error"></div>
            <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px;">
                    <input type="checkbox" x-model="resendModal.notes.address"> Address
                </label>
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px;">
                    <input type="checkbox" x-model="resendModal.notes.idImage"> ID Image
                </label>
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px;">
                    <input type="checkbox" x-model="resendModal.notes.contact"> Contact Number
                </label>
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px;">
                    <input type="checkbox" x-model="resendModal.notes.other"> Other
                </label>
                <div x-show="resendModal.notes.other" x-cloak style="margin-left:24px;">
                    <input type="text" x-model="resendModal.notes.otherText" placeholder="Specify..." style="width:100%; padding:8px 12px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;">
                </div>
            </div>
            <div class="mf-footer" style="border:none; padding:0;">
                <button type="button" @click="resendModal.isOpen = false" class="mf-btn-outline blue" :disabled="resendModal.sending">Cancel</button>
                <button type="button" @click="sendResendLink()" class="mf-btn-outline teal" :disabled="resendModal.sending" x-text="resendModal.sending ? 'Sending...' : 'Send Link'"></button>
            </div>
        </div>
    </div>
</div>

<div class="pf-submit-overlay" :class="{'is-open': pageSubmitting}" x-cloak>
    <div class="pf-submit-spinner"></div>
</div>

<!-- Image Viewer Modal - REMOVED -->

        </main>
    </div>
</div>

<!-- Archived team accounts (outside Alpine main — opened via toolbar) -->
<div id="um-archive-overlay" role="dialog" aria-modal="true" aria-labelledby="um-archive-title" onclick="if (event.target === this) window.closeArchivedUsersModal && window.closeArchivedUsersModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10090;align-items:center;justify-content:center;padding:16px;">
    <div onclick="event.stopPropagation()" style="background:white;border-radius:16px;width:100%;max-width:900px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 25px 50px rgba(0,0,0,0.25);">
        <div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h3 id="um-archive-title" style="font-size:18px;font-weight:700;margin:0;color:#1f2937;">Archived Accounts</h3>
                <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Deactivated accounts moved to archive. Restore returns them as deactivated.</p>
            </div>
            <button type="button" onclick="window.closeArchivedUsersModal && window.closeArchivedUsersModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:22px;line-height:1;">✕</button>
        </div>
        <div style="padding:0;overflow-y:auto;flex:1;">
            <div id="archived-users-container" style="min-height:160px;padding:16px 24px;">
                <p style="text-align:center;color:#9ca3af;">Loading…</p>
            </div>
        </div>
        <div style="padding:16px 24px;border-top:1px solid #e5e7eb;text-align:right;">
            <button type="button" class="mf-btn-cancel" onclick="window.closeArchivedUsersModal && window.closeArchivedUsersModal()">Close</button>
        </div>
    </div>
</div>

<!-- Add User/Staff Modal Popup -->
<div id="user-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
    <div id="user-modal-box">
        <div class="modal-header">
            <h3 class="modal-title" id="user-modal-title">Add New Team Member</h3>
            <button type="button" class="modal-close-x" id="btn-close-user-modal-x" aria-label="Close">✕</button>
        </div>
        <form method="POST" action="" id="user-create-form" onsubmit="return validateUserCreateForm(event)">
            <div class="modal-body">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="create_staff" value="1">
                
                <div class="form-row-3">
                <div class="form-group" id="um-group-first_name">
                    <label>First Name <span style="color:#ef4444">*</span></label>
                    <input type="text" name="first_name" id="um-first_name" required placeholder="e.g. Juan" autocomplete="given-name" maxlength="50">
                    <div id="um-error-first_name" class="error-message" style="display:none; color:#ef4444; font-size:11px; margin-top:4px;"></div>
                </div>
                <div class="form-group" id="um-group-middle_name">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" id="um-middle_name" placeholder="e.g. Santos" autocomplete="additional-name" maxlength="50">
                    <div id="um-error-middle_name" class="error-message" style="display:none; color:#ef4444; font-size:11px; margin-top:4px;"></div>
                </div>
                <div class="form-group" id="um-group-last_name">
                    <label>Last Name <span style="color:#ef4444">*</span></label>
                    <input type="text" name="last_name" id="um-last_name" required placeholder="e.g. Dela Cruz" autocomplete="family-name" maxlength="50">
                    <div id="um-error-last_name" class="error-message" style="display:none; color:#ef4444; font-size:11px; margin-top:4px;"></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" id="um-group-email">
                    <label>Email Address <span style="color:#ef4444">*</span></label>
                    <input type="email" name="email" id="um-email" required placeholder="staff@printflow.com" autocomplete="email">
                    <div id="um-error-email" class="error-message" style="display:none; color:#ef4444; font-size:11px; margin-top:4px;"></div>
                    <div id="um-email-availability-hint" style="display:none; font-size:11px; margin-top:4px; color:#6b7280;">Checking availability…</div>
                </div>
                <div class="form-group">
                    <label>Birthday <span style="color:#ef4444">*</span></label>
                    <input type="date" name="birthday" id="um-birthday" required min="<?php echo $min_birthday; ?>" max="<?php echo $max_birthday; ?>">
                    <div id="um-birthday-error" class="error-message" style="display:none; color:#ef4444; font-size:11px; margin-top:4px; font-weight:500;"></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Role <span style="color:#ef4444">*</span></label>
                    <select name="role" id="user-role-select" required>
                        <option value="Staff">Staff</option>
                        <option value="Manager">Manager</option>
                    </select>
                </div>
                <div class="form-group" id="branch-select-group">
                    <label>Branch Assignment <span style="color:#ef4444">*</span></label>
                    <select name="branch_id" id="user-branch-select">
                        <option value="">-- Select Branch --</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Staff members only see data for their assigned branch.</p>
                </div>
            </div>

            <div class="form-group">
                <label>Default Password</label>
                <div style="position:relative;">
                    <input type="text" name="password" id="um-password" minlength="8" readonly
                           placeholder="Auto-filled from email + birthday"
                           style="padding-right:80px;background:#f9fafb;color:#374151;">
                    <span id="um-pw-label"
                          style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:11px;color:#9ca3af;font-weight:600;pointer-events:none;">AUTO</span>
                </div>
                <p class="form-hint">Format: <em>email</em> + <em>MMDDYYYY</em> &mdash; e.g. <code>juan@store.com01151990</code></p>
            </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="btn-close-user-modal">Cancel</button>
                <button type="submit" class="modal-btn modal-btn-submit">Add Member</button>
            </div>
        </form>
    </div>
</div>

<script>
function printflowInitUserStaffModal() {
    var backdrop = document.getElementById('user-modal-backdrop');
    var btnOpen = document.getElementById('btn-open-user-modal');
    var btnClose = document.getElementById('btn-close-user-modal');
    var btnCloseX = document.getElementById('btn-close-user-modal-x');
    if (!backdrop || !btnOpen) return;
    if (backdrop.dataset.pfUmModalInit) return;
    backdrop.dataset.pfUmModalInit = '1';

    var umEmailCheckTimer = null;
    var umEmailCheckSeq = 0;
    window._umEmailDbState = 'idle';

    function setEmailAvailabilityHint(visible) {
        var hint = document.getElementById('um-email-availability-hint');
        if (hint) hint.style.display = visible ? 'block' : 'none';
    }

    function scheduleEmailAvailabilityCheck() {
        var input = document.getElementById('um-email');
        var group = document.getElementById('um-group-email');
        var errEl = document.getElementById('um-error-email');
        if (!input || !group || !errEl) return;
        var val = (input.value || '').trim().replace(/\s/g, '');
        if (umEmailCheckTimer) clearTimeout(umEmailCheckTimer);
        window._umEmailDbState = 'idle';
        setEmailAvailabilityHint(false);
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
            return;
        }
        window._umEmailDbState = 'checking';
        setEmailAvailabilityHint(true);
        umEmailCheckTimer = setTimeout(function () {
            var seq = ++umEmailCheckSeq;
            fetch('user_staff_management.php?check_email=1&email=' + encodeURIComponent(val), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (seq !== umEmailCheckSeq) return;
                    setEmailAvailabilityHint(false);
                    if (!data || data.ok !== true) {
                        window._umEmailDbState = 'idle';
                        return;
                    }
                    if (data.taken) {
                        window._umEmailDbState = 'taken';
                        group.classList.add('is-invalid');
                        errEl.style.color = '#ef4444';
                        errEl.textContent = 'This email is already used by a staff or manager account, or by a customer.';
                        errEl.style.display = 'block';
                    } else if (data.available === true) {
                        window._umEmailDbState = 'available';
                        errEl.textContent = '';
                        errEl.style.display = 'none';
                        group.classList.remove('is-invalid');
                    } else {
                        window._umEmailDbState = 'idle';
                    }
                })
                .catch(function () {
                    if (seq !== umEmailCheckSeq) return;
                    setEmailAvailabilityHint(false);
                    window._umEmailDbState = 'idle';
                });
        }, 280);
    }

    function openModal() {
        if (umEmailCheckTimer) clearTimeout(umEmailCheckTimer);
        umEmailCheckTimer = null;
        umEmailCheckSeq++;
        window._umEmailDbState = 'idle';
        setEmailAvailabilityHint(false);
        backdrop.style.display = 'flex';
        // Trigger reflow then add class for animation
        void backdrop.offsetWidth;
        backdrop.classList.add('is-open');
        var firstInput = backdrop.querySelector('input[type="text"]');
        if (firstInput) setTimeout(function() { firstInput.focus(); }, 150);
        var emEl = document.getElementById('um-email');
        if (emEl && (emEl.value || '').trim()) validateUserCreateField('email');
    }

    function closeModal() {
        backdrop.classList.remove('is-open');
        setTimeout(function() {
            if (!backdrop.classList.contains('is-open')) {
                backdrop.style.display = 'none';
            }
        }, 260);
    }

    btnOpen.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCloseX) btnCloseX.addEventListener('click', closeModal);

    backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) closeModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal();
    });

    // Create form validation (profile name + register/branch email)
    function formatNameInput(el) {
        var v = el.value;
        v = v.replace(/\d/g, '');  // block numbers
        v = v.replace(/[^a-zA-Z ]/g, '');  // block special characters, allow only letters and spaces
        v = v.replace(/\s{2,}/g, ' ');  // collapse 2+ consecutive spaces to 1
        // Capitalize words but preserve trailing space for typing
        var hasTrailingSpace = v.endsWith(' ');
        v = v.split(' ').map(function(w) { return w ? w.charAt(0).toUpperCase() + w.slice(1).toLowerCase() : ''; }).filter(Boolean).join(' ');
        if (hasTrailingSpace && v.length > 0) v += ' ';
        el.value = v;
    }
    function validateUserCreateField(id) {
        var input = document.getElementById('um-' + id);
        var group = document.getElementById('um-group-' + id);
        var errEl = document.getElementById('um-error-' + id);
        if (!input || !group || !errEl) return true;
        var val = (input.value || '').trim();
        var err = '';
        if (id === 'first_name' || id === 'last_name') {
            if (!val) err = id === 'first_name' ? 'First name is required.' : 'Last name is required.';
            else if (/\s{2,}/.test(val)) err = 'Names cannot have more than one space in a row.';
            else if (/[0-9]/.test(val)) err = 'Names must not contain numbers.';
            else if (!/^[A-Za-z]+( [A-Za-z]+)*$/.test(val)) err = 'Names must contain only letters.';
            else if (val.length < 2 || val.length > 50) err = 'Names must be between 2 and 50 characters.';
        } else if (id === 'middle_name') {
            if (val && /\s{2,}/.test(val)) err = 'Middle name cannot have more than one space in a row.';
            else if (val && /[0-9]/.test(val)) err = 'Middle name must not contain numbers.';
            else if (val && !/^[A-Za-z]+( [A-Za-z]+)*$/.test(val)) err = 'Middle name must contain only letters.';
            else if (val && (val.length < 2 || val.length > 50)) err = 'Middle name must be between 2 and 50 characters.';
        } else if (id === 'email') {
            if (!val) err = 'Email is required.';
            else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) err = 'Please enter a valid email address.';
        }
        if (err) {
            window._umEmailDbState = 'idle';
            setEmailAvailabilityHint(false);
            if (umEmailCheckTimer) clearTimeout(umEmailCheckTimer);
            group.classList.add('is-invalid');
            errEl.style.color = '#ef4444';
            errEl.textContent = err;
            errEl.style.display = 'block';
            return false;
        }
        if (id === 'email') {
            errEl.style.color = '#ef4444';
            if (window._umEmailDbState === 'taken') {
                group.classList.add('is-invalid');
                errEl.textContent = 'This email is already used by a staff or manager account, or by a customer.';
                errEl.style.display = 'block';
                return false;
            }
            group.classList.remove('is-invalid');
            errEl.textContent = '';
            errEl.style.display = 'none';
            scheduleEmailAvailabilityCheck();
            return true;
        }
        group.classList.remove('is-invalid');
        errEl.textContent = '';
        errEl.style.display = 'none';
        return true;
    }
    function validateUserCreateForm(e) {
        var ok = validateUserCreateField('first_name') && validateUserCreateField('last_name') && validateUserCreateField('middle_name') && validateUserCreateField('email');
        if (!ok) {
            e.preventDefault();
            return false;
        }
        if (window._umEmailDbState === 'taken') {
            e.preventDefault();
            return false;
        }
        return true;
    }
    window.validateUserCreateForm = validateUserCreateForm;
    ['first_name', 'last_name', 'middle_name'].forEach(function(id) {
        var el = document.getElementById('um-' + id);
        if (el) {
            el.addEventListener('blur', function() { validateUserCreateField(id); });
            el.addEventListener('input', function() {
                formatNameInput(this);
                validateUserCreateField(id);
            });
            el.addEventListener('keydown', function(e) {
                // Block numbers
                if (/\d/.test(e.key)) e.preventDefault();
                // Block special characters (allow only letters and space)
                if (e.key.length === 1 && !/[a-zA-Z ]/.test(e.key)) e.preventDefault();
                // Block space only at the start (leading space)
                if (e.key === ' ' && this.selectionStart === 0) {
                    e.preventDefault();
                }
                // Block input if max length reached (except backspace, delete, arrow keys)
                if (this.value.length >= 50 && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key) && e.key.length === 1) {
                    e.preventDefault();
                }
            });
        }
    });
    var emailEl = document.getElementById('um-email');
    if (emailEl) {
        emailEl.addEventListener('keydown', function(e) { if (e.key === ' ') e.preventDefault(); });
        emailEl.addEventListener('input', function() {
            this.value = this.value.replace(/\s/g, '');
            window._umEmailDbState = 'idle';
            validateUserCreateField('email');
        });
        emailEl.addEventListener('blur', function() { validateUserCreateField('email'); });
    }

    // Auto-open modal if there was a validation error
    <?php if ($error): ?>
    openModal();
    <?php endif; ?>

    // Toggle Branch Select based on Role
    var roleSelect  = document.getElementById('user-role-select');
    var branchGroup = document.getElementById('branch-select-group');
    if (roleSelect && branchGroup) {
        roleSelect.addEventListener('change', function() {
            var needsBranch = (this.value !== 'Admin');
            branchGroup.style.display = needsBranch ? 'block' : 'none';
            document.getElementById('user-branch-select').required = needsBranch;
        });
        roleSelect.dispatchEvent(new Event('change'));
    }

    // Auto-fill default password from email + birthday (MMDDYYYY)
    var emailInput = document.getElementById('um-email');
    var bdayInput  = document.getElementById('um-birthday');
    var pwInput    = document.getElementById('um-password');

    function buildDefaultPassword() {
        var em = emailInput ? emailInput.value.trim() : '';
        var bd = bdayInput  ? bdayInput.value : '';
        if (em && bd) {
            var parts = bd.split('-'); // [YYYY, MM, DD]
            if (parts.length === 3) {
                pwInput.value = em + parts[1] + parts[2] + parts[0];
            }
        } else {
            pwInput.value = '';
        }
    }

    if (emailInput) emailInput.addEventListener('input', buildDefaultPassword);
    if (bdayInput)  bdayInput.addEventListener('change', buildDefaultPassword);

    // Birthday validation for creation
    if (bdayInput) {
        bdayInput.addEventListener('change', function() {
            var val = this.value;
            var errDiv = document.getElementById('um-birthday-error');
            var submitBtn = backdrop.querySelector('.modal-btn-submit');
            if (!val) return;
            
            var bday = new Date(val);
            var today = new Date();
            var age = today.getFullYear() - bday.getFullYear();
            var m = today.getMonth() - bday.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < bday.getDate())) age--;
            
            if (bday > today) {
                errDiv.textContent = "Cannot be a future date.";
                errDiv.style.display = 'block';
                submitBtn.disabled = true;
                this.classList.add('is-invalid');
            } else if (age < 18) {
                errDiv.textContent = "Must be at least 18 years old.";
                errDiv.style.display = 'block';
                submitBtn.disabled = true;
                this.classList.add('is-invalid');
            } else if (age > 70) {
                errDiv.textContent = "Must be 70 years old or younger.";
                errDiv.style.display = 'block';
                submitBtn.disabled = true;
                this.classList.add('is-invalid');
            } else {
                errDiv.style.display = 'none';
                submitBtn.disabled = false;
                this.classList.remove('is-invalid');
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', printflowInitUserStaffModal);
} else {
    printflowInitUserStaffModal();
}
document.addEventListener('printflow:page-init', printflowInitUserStaffModal);

// Turbo navigation support
if (typeof Turbo !== 'undefined') {
    document.addEventListener('turbo:load', printflowInitUserStaffModal);
    document.addEventListener('turbo:render', printflowInitUserStaffModal);
}
</script>

<script>
// ── Filter & Sort JS (user_staff_management.php) ────────────────────────────
/* var: Turbo re-runs inline scripts; let would throw "already been declared". */
var activeSort = '<?php echo $sort ?? "newest"; ?>';
var searchDebounceTimer = null;

function filterPanel() {
    return {
        sortOpen: false,
        filterOpen: false,
        activeSort: activeSort,
        hasActiveFilters: <?php echo count(array_filter([$role_filter, $status_filter, $search, $date_from, $date_to])) > 0 ? 'true' : 'false'; ?>,
    };
}

function buildFilterURL(overrides = {}, includeAjax = false) {
    const params = new URLSearchParams(window.location.search);
    
    // Default current values
    const current = {
        role:      document.getElementById('fp_role')?.value || '',
        status:    document.getElementById('fp_status')?.value || '',
        date_from: document.getElementById('fp_date_from')?.value || '',
        date_to:   document.getElementById('fp_date_to')?.value || '',
        search:    document.getElementById('fp_search')?.value || '',
        sort:      activeSort
    };

    const combined = { ...current, ...overrides };

    const finalParams = new URLSearchParams();
    if (combined.page)      finalParams.set('page', combined.page);
    if (combined.role)      finalParams.set('role', combined.role);
    if (combined.status)    finalParams.set('status', combined.status);
    if (combined.date_from) finalParams.set('date_from', combined.date_from);
    if (combined.date_to)   finalParams.set('date_to', combined.date_to);
    if (combined.search)    finalParams.set('search', combined.search);
    if (combined.sort && combined.sort !== 'newest') finalParams.set('sort', combined.sort);
    
    if (includeAjax) finalParams.set('ajax', '1');
    
    return '?' + finalParams.toString();
}

async function fetchUpdatedTable(overrides = {}) {
    try {
        const url = buildFilterURL(overrides, true);
        const resp = await fetch(url);
        const data = await resp.json();
        
        if (data.success) {
            const container = document.getElementById('usersTableContainer');
            if (container) {
                container.innerHTML = data.table + '<div id="usersPagination">' + data.pagination + '</div>';
                
                // Re-initialize Alpine for the updated container
                if (typeof Alpine !== 'undefined') {
                    try {
                        Alpine.initTree(container);
                    } catch (e) {
                        console.error('Alpine initTree error:', e);
                    }
                }
                
                // Re-initialize bridge after table update
                setTimeout(initAlpineGlobalBridge, 50);
            }
            
            // Update badge
            const badgeCont = document.getElementById('filterBadgeContainer');
            if (badgeCont) {
                badgeCont.innerHTML = data.badge > 0 ? `<span class="filter-badge">${data.badge}</span>` : '';
            }
            
            // Update Alpine hasActiveFilters
            const alpineEl = document.querySelector('[x-data="filterPanel()"]');
            if (alpineEl && alpineEl._x_dataStack) {
                alpineEl._x_dataStack[0].hasActiveFilters = data.badge > 0;
            }

            // Update URL bar
            const displayUrl = buildFilterURL(overrides, false);
            window.history.replaceState({ path: displayUrl }, '', displayUrl);
        }
    } catch (e) {
        console.error('Error updating table:', e);
    }
}

function applyFilters(resetAll = false) {
    if (resetAll) {
        const base = window.location.pathname;
        window.location.href = base;
    } else {
        fetchUpdatedTable();
    }
}

function applySortFilter(sortKey) {
    activeSort = sortKey;
    // Update Alpine state
    const alpineEl = document.querySelector('[x-data="filterPanel()"]');
    if (alpineEl && alpineEl._x_dataStack) {
        const data = alpineEl._x_dataStack[0];
        data.activeSort = sortKey;
        data.sortOpen = false;
    }
    
    fetchUpdatedTable({ sort: sortKey });
}

function resetFilterField(fields) {
    fields.forEach(f => {
        const el = document.getElementById('fp_' + f);
        if (el) el.value = '';
    });
    fetchUpdatedTable();
}

function userManagement() {
    const data = {
        pageSubmitting: false,
        viewModal: {
            isOpen: false,
            loading: false,
            user: {
                user_id: 0,
                first_name: '',
                middle_name: '',
                last_name: '',
                email: '',
                contact_number: '',
                dob: '',
                gender: '',
                address: '',
                role: '',
                branch_name: '',
                status: '',
                created_at: '',
                id_type: '',
                id_validation_image: ''
            }
        },
        activateConfirm: {
            isOpen: false,
            userId: 0
        },
        deactivateConfirm: {
            isOpen: false,
            userId: 0
        },
        archiveConfirm: {
            isOpen: false,
            userId: 0,
            archiving: false
        },
        resendModal: {
            isOpen: false,
            userId: 0,
            sending: false,
            error: '',
            notes: {
                name: false,
                address: false,
                idImage: false,
                contact: false,
                other: false,
                otherText: ''
            }
        },
        editModal: {
            isOpen: false,
            loading: false,
            saving: false,
            error: '',
            success: '',
            user: {
                user_id: 0,
                first_name: '',
                middle_name: '',
                last_name: '',
                email: '',
                contact_number: '',
                dob: '',
                gender: '',
                address: '',
                address_province: '',
                address_city: '',
                address_barangay: '',
                address_line: '',
                role: '',
                branch_id: '',
                status: '',
                created_at: ''
            }
        },
        errors: {
            first_name: '',
            middle_name: '',
            last_name: '',
            contact_number: '',
            address: '',
            dob: ''
        },
        addressProvinces: [],
        addressCities: [],
        addressBarangays: [],
        loadingCities: false,
        loadingBarangays: false,
        
        get isEditFormValid() {
            if (!this.editModal.user) return false;
            return this.editModal.user.first_name && 
                   this.editModal.user.last_name && 
                   this.editModal.user.contact_number && 
                   this.editModal.user.address && 
                   this.editModal.user.dob &&
                   !this.errors.first_name && 
                   !this.errors.last_name && 
                   !this.errors.contact_number && 
                   !this.errors.address &&
                   !this.errors.dob;
        },

        formatName(name) {
            if (!name) return '';
            // Remove numbers and special characters, allow only letters and spaces
            let formatted = name.replace(/\d/g, '').replace(/[^a-zA-Z ]/g, '');
            formatted = formatted.replace(/\s{2,}/g, ' ');
            // Capitalize first letter of each word, preserve spaces
            const words = formatted.split(' ');
            const capitalized = words.map(w => w ? w.charAt(0).toUpperCase() + w.slice(1).toLowerCase() : '');
            return capitalized.join(' ');
        },

        validateField(id) {
            const user = this.editModal.user;
            if (!user) return;
            let val = user[id] || '';
            
            if (id === 'first_name' || id === 'last_name' || id === 'middle_name') {
                user[id] = this.formatName(val);
                val = user[id];
            }
            const trimVal = val.trim();

            if (id === 'first_name' || id === 'last_name') {
                if (!trimVal) this.errors[id] = "Required.";
                else if (/\s{2,}/.test(trimVal)) this.errors[id] = "Names cannot have more than one space in a row.";
                else if (/[0-9]/.test(trimVal)) this.errors[id] = "Names must not contain numbers.";
                else if (!/^[A-Za-z]+( [A-Za-z]+)*$/.test(trimVal)) this.errors[id] = "Names must contain only letters.";
                else if (trimVal.length < 2 || trimVal.length > 50) this.errors[id] = "Names must be between 2 and 50 characters.";
                else this.errors[id] = '';
            }
            else if (id === 'middle_name') {
                if (trimVal && /\s{2,}/.test(trimVal)) this.errors[id] = "Middle name cannot have more than one space in a row.";
                else if (trimVal && /[0-9]/.test(trimVal)) this.errors[id] = "Middle name must not contain numbers.";
                else if (trimVal && !/^[A-Za-z]+( [A-Za-z]+)*$/.test(trimVal)) this.errors[id] = "Middle name must contain only letters.";
                else if (trimVal && (trimVal.length < 2 || trimVal.length > 50)) this.errors[id] = "Middle name must be between 2 and 50 characters.";
                else this.errors[id] = '';
            }
            else if (id === 'contact_number') {
                if (!trimVal) this.errors[id] = "Required.";
                else if (!/^\d+$/.test(trimVal)) this.errors[id] = "Digits only.";
                else if (!trimVal.startsWith('09')) this.errors[id] = "Must start with 09.";
                else if (trimVal.length !== 11) this.errors[id] = "Must be 11 digits.";
                else this.errors[id] = '';
            }
            else if (id === 'address') {
                const addr = user.address || '';
                if (!addr.trim()) this.errors.address = "Required.";
                else if (addr.length < 5) this.errors.address = "Min 5 chars.";
                else if (addr.length > 200) this.errors.address = "Max 200 chars.";
                else this.errors.address = '';
            }
            else if (id === 'dob') {
                if (!val) {
                    this.errors.dob = "Required.";
                    return;
                }
                const bday = new Date(val);
                const today = new Date();
                let age = today.getFullYear() - bday.getFullYear();
                const m = today.getMonth() - bday.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < bday.getDate())) age--;
                
                if (bday > today) this.errors.dob = "Cannot be future.";
                else if (age < 18) this.errors.dob = "Min 18 years old.";
                else if (age > 70) this.errors.dob = "Max 70 years old.";
                else this.errors.dob = '';
            }
        },

        normalizeLocationName(value) {
            return (value || '')
                .toLowerCase()
                .replace(/\b(barangay|brgy|pob\.?|poblacion)\b/gi, '')
                .replace(/[^a-z0-9]+/g, ' ')
                .trim();
        },

        async loadProvinces() {
            try {
                const r = await fetch('<?php echo $base_path; ?>/admin/api_address.php?address_action=provinces');
                const d = await r.json();
                if (d.success && d.data) this.addressProvinces = d.data;
                return d.data || [];
            } catch (e) { console.error('Address load failed:', e); return []; }
        },
        async loadCities(skipBuildAddress = false) {
            const pName = this.editModal.user?.address_province || '';
            let p = this.addressProvinces.find(x => x.name.toLowerCase() === pName.toLowerCase());
            if (!p && pName) {
                const pNorm = this.normalizeLocationName(pName);
                p = this.addressProvinces.find(x => this.normalizeLocationName(x.name) === pNorm);
                if (p) this.editModal.user.address_province = p.name;
            }
            const code = p?.code || '';
            if (!code) { this.addressCities = []; this.addressBarangays = []; return; }
            this.loadingCities = true;
            try {
                const r = await fetch('<?php echo $base_path; ?>/admin/api_address.php?address_action=cities&province_code=' + encodeURIComponent(code));
                const d = await r.json();
                if (d.success && d.data) this.addressCities = d.data;
                this.addressBarangays = [];
                if (this.editModal.user?.address_city) {
                    const cName = this.editModal.user.address_city;
                    let c = this.addressCities.find(x => x.name.toLowerCase() === cName.toLowerCase());
                    if (!c) {
                        const cNorm = this.normalizeLocationName(cName);
                        c = this.addressCities.find(x => this.normalizeLocationName(x.name) === cNorm);
                    }
                    if (c) this.editModal.user.address_city = c.name;
                }
                if (!skipBuildAddress) this.buildAddress();
            } catch (e) { console.error('Cities load failed:', e); }
            finally { this.loadingCities = false; }
        },
        async loadBarangays(skipBuildAddress = false) {
            const cName = this.editModal.user?.address_city || '';
            let c = this.addressCities.find(x => x.name.toLowerCase() === cName.toLowerCase());
            if (!c && cName) {
                const cNorm = this.normalizeLocationName(cName);
                c = this.addressCities.find(x => this.normalizeLocationName(x.name) === cNorm);
                if (c) this.editModal.user.address_city = c.name;
            }
            const code = c?.code || '';
            if (!code) { this.addressBarangays = []; if (!skipBuildAddress) this.buildAddress(); return; }
            this.loadingBarangays = true;
            try {
                const r = await fetch('<?php echo $base_path; ?>/admin/api_address.php?address_action=barangays&city_code=' + encodeURIComponent(code));
                const d = await r.json();
                if (d.success && d.data) this.addressBarangays = d.data;
                if (this.editModal.user?.address_barangay) {
                    const bName = this.editModal.user.address_barangay;
                    let b = this.addressBarangays.find(x => x.name.toLowerCase() === bName.toLowerCase());
                    if (!b) {
                        const bNorm = this.normalizeLocationName(bName);
                        b = this.addressBarangays.find(x => this.normalizeLocationName(x.name) === bNorm);
                    }
                    if (b) this.editModal.user.address_barangay = b.name;
                }
                if (!skipBuildAddress) this.buildAddress();
            } catch (e) { console.error('Barangays load failed:', e); }
            finally { this.loadingBarangays = false; }
        },
        buildAddress() {
            const u = this.editModal.user;
            if (!u) return;
            const p = [(u.address_line || '').trim(), u.address_barangay ? 'Brgy. ' + u.address_barangay : '', u.address_city || '', u.address_province || ''].filter(Boolean);
            u.address = p.length ? p.join(', ') + ', Philippines' : '';
            this.validateField('address');
        },
        parseAddressFromString(addr) {
            const raw = (addr || '').trim();
            const parts = raw.split(',').map(p => p.trim()).filter(Boolean);
            if (!parts.length) {
                return { address_province: '', address_city: '', address_barangay: '', address_line: '' };
            }

            const hasCountry = parts[parts.length - 1].toLowerCase() === 'philippines';
            const core = hasCountry ? parts.slice(0, -1) : parts.slice();

            if (core.length >= 4) {
                const province = core[core.length - 1] || '';
                const city = core[core.length - 2] || '';
                const barangayRaw = core[core.length - 3] || '';
                const barangay = barangayRaw.replace(/^Brgy\.?\s*/i, '').trim();
                const addressLine = core.slice(0, -3).join(', ').trim();
                return { address_province: province, address_city: city, address_barangay: barangay, address_line: addressLine };
            }

            if (core.length === 3) {
                const province = core[2] || '';
                const city = core[1] || '';
                const first = core[0] || '';
                const isBarangay = /^Brgy\.?\s*/i.test(first);
                const barangay = isBarangay ? first.replace(/^Brgy\.?\s*/i, '').trim() : '';
                const addressLine = isBarangay ? '' : first;
                return { address_province: province, address_city: city, address_barangay: barangay, address_line: addressLine };
            }

            if (core.length === 2) {
                const province = core[1] || '';
                const city = core[0] || '';
                return { address_province: province, address_city: city, address_barangay: '', address_line: '' };
            }

            return { address_province: '', address_city: '', address_barangay: '', address_line: core[0] || '' };
        },
        
        async viewUser(userId) {
            this.viewModal.isOpen = true;
            this.viewModal.loading = true;
            this.viewModal.user = {};
            this.editModal.isOpen = false;

            try {
                const res = await fetch('<?php echo $base_path; ?>/admin/api_user_details.php?id=' + userId);
                const data = await res.json();
                if (data.success) {
                    this.viewModal.user = data.user;
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.viewModal.loading = false;
            }
        },

        async editUser(userId) {
            this.editModal.isOpen = true;
            this.editModal.loading = true;
            this.editModal.error = '';
            this.editModal.success = '';
            this.editModal.user = {};
            this.viewModal.isOpen = false;
            this.errors = { first_name: '', middle_name: '', last_name: '', contact_number: '', address: '', dob: '' };

            try {
                if (!this.addressProvinces.length) await this.loadProvinces();
                const res = await fetch('<?php echo $base_path; ?>/admin/api_user_details.php?id=' + userId);
                const data = await res.json();
                if (data.success) {
                    const u = data.user;
                    // Store original address before parsing
                    const originalAddress = u.address || '';
                    const parsed = this.parseAddressFromString(originalAddress);
                    u.address_province = parsed.address_province;
                    u.address_city = parsed.address_city;
                    u.address_barangay = parsed.address_barangay;
                    u.address_line = parsed.address_line;
                    // Keep the original full address
                    u.address = originalAddress;
                    // Ensure branch_id is properly set (convert to string for select binding)
                    u.branch_id = u.branch_id ? String(u.branch_id) : '';
                    if (u.role === 'Admin') {
                        u.status = 'Activated';
                    }
                    this.editModal.user = u;
                    if (!this.editModal.user.address && (u.address_line || u.address_barangay || u.address_city || u.address_province)) {
                        this.buildAddress();
                    }
                    // Load cities and barangays without rebuilding address
                    if (parsed.address_province) {
                        await this.loadCities(true); // Pass true to skip buildAddress
                    }
                    if (parsed.address_city) {
                        await this.loadBarangays(true); // Pass true to skip buildAddress
                    }
                } else {
                    this.editModal.error = data.error || 'Failed to load user.';
                }
            } catch (e) {
                this.editModal.error = 'Network error.';
            } finally {
                this.editModal.loading = false;
            }
        },

        showActivateConfirm(userId) {
            this.activateConfirm.userId = userId;
            this.activateConfirm.isOpen = true;
        },
        showDeactivateConfirm(userId) {
            this.deactivateConfirm.userId = userId;
            this.deactivateConfirm.isOpen = true;
        },
        showArchiveConfirm(userId) {
            this.archiveConfirm.userId = userId;
            this.archiveConfirm.isOpen = true;
        },
        async confirmArchiveUser() {
            const userId = this.archiveConfirm.userId;
            if (!userId || this.archiveConfirm.archiving) return;
            this.archiveConfirm.archiving = true;
            try {
                const res = await fetch('<?php echo $base_path; ?>/admin/api_update_user_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'archive_user',
                        user_id: userId,
                        csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>'
                    })
                });
                const data = await res.json();
                if (data.success) {
                    this.archiveConfirm.isOpen = false;
                    this.viewModal.isOpen = false;
                    location.reload();
                } else {
                    alert(data.error || 'Failed to archive.');
                }
            } catch (e) {
                alert('Network error.');
            } finally {
                this.archiveConfirm.archiving = false;
            }
        },
        async confirmActivateUser() {
            const userId = this.activateConfirm.userId;
            if (!userId) return;
            this.activateConfirm.isOpen = false;
            this.pageSubmitting = true;
            try {
                const res = await fetch('<?php echo $base_path; ?>/admin/api_update_user_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'activate_account',
                        user_id: userId,
                        csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>'
                    })
                });
                const data = await res.json();
                if (data.success) {
                    this.viewModal.isOpen = false;
                    location.reload();
                } else {
                    alert(data.error || 'Failed to activate.');
                }
            } catch (e) {
                alert('Network error.');
            }
        },
        async confirmDeactivateUser() {
            const userId = this.deactivateConfirm.userId;
            if (!userId) return;
            this.deactivateConfirm.isOpen = false;
            try {
                const res = await fetch('<?php echo $base_path; ?>/admin/api_update_user_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle_status',
                        user_id: userId,
                        current_status: 'Activated',
                        csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>'
                    })
                });
                const data = await res.json();
                if (data.success) {
                    this.viewModal.isOpen = false;
                    location.reload();
                } else {
                    alert(data.error || 'Failed to deactivate.');
                }
            } catch (e) {
                alert('Network error.');
            } finally {
                this.pageSubmitting = false;
            }
        },
        openResendModal(userId) {
            this.resendModal.userId = userId;
            this.resendModal.error = '';
            this.resendModal.notes = {
                name: false,
                address: false,
                idImage: false,
                contact: false,
                other: false,
                otherText: ''
            };
            this.resendModal.isOpen = true;
        },
        hasResendSelection() {
            const n = this.resendModal.notes;
            return !!(n.name || n.address || n.idImage || n.contact || n.other);
        },
        async sendResendLink() {
            const userId = this.resendModal.userId;
            if (!userId) return;
            if (!this.hasResendSelection()) {
                this.resendModal.error = 'Please select at least one item before sending the link.';
                return;
            }
            this.resendModal.error = '';
            this.resendModal.sending = true;
            this.pageSubmitting = true;
            const n = this.resendModal.notes;
            const admin_notes = [];
            if (n.name) admin_notes.push('Name');
            if (n.address) admin_notes.push('Address');
            if (n.idImage) admin_notes.push('ID Image');
            if (n.contact) admin_notes.push('Contact Number');
            if (n.other && n.otherText.trim()) admin_notes.push('Other: ' + n.otherText.trim());
            else if (n.other) admin_notes.push('Other');
            try {
                const res = await fetch('<?php echo $base_path; ?>/admin/api_update_user_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'resend_completion_link',
                        user_id: userId,
                        admin_notes: admin_notes,
                        csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>'
                    })
                });
                const data = await res.json();
                if (data.success) {
                    alert(data.message);
                    this.resendModal.isOpen = false;
                    this.resendModal.sending = false;
                    this.viewModal.isOpen = false;
                    location.reload();
                } else {
                    alert(data.error || 'Failed to send.');
                }
            } catch (e) {
                alert('Network error.');
            } finally {
                this.resendModal.sending = false;
                this.pageSubmitting = false;
            }
        },
        async saveUserChanges() {
            if (!this.editModal.user) return;
            this.buildAddress();
            if (!this.editModal.user.address || this.editModal.user.address.length < 5) {
                this.errors.address = 'Please complete the address (province, city, barangay).';
                return;
            }
            this.editModal.saving = true;
            this.editModal.error = '';
            this.editModal.success = '';

            try {
                const payload = {
                    action: 'update_info',
                    user_id: this.editModal.user.user_id,
                    first_name: this.editModal.user.first_name,
                    middle_name: this.editModal.user.middle_name || '',
                    last_name: this.editModal.user.last_name,
                    contact_number: this.editModal.user.contact_number || '',
                    address: this.editModal.user.address || '',
                    gender: this.editModal.user.gender || '',
                    dob: this.editModal.user.dob || '',
                    role: this.editModal.user.role,
                    branch_id: this.editModal.user.branch_id || '',
                    status: this.editModal.user.role === 'Admin' ? 'Activated' : this.editModal.user.status,
                    csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>'
                };

                const res = await fetch('<?php echo $base_path; ?>/admin/api_update_user_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                
                if (data.success) {
                    this.editModal.success = data.message;
                    setTimeout(() => location.reload(), 1000);
                } else {
                    this.editModal.error = data.error || 'Update failed.';
                }
            } catch (e) {
                this.editModal.error = 'Network error.';
            } finally {
                this.editModal.saving = false;
            }
        }
    };
    return data;
}
window.userManagement = userManagement;

function printflowInitUserStaffPage() {
    // Initialize Alpine global bridge first
    initAlpineGlobalBridge();
    
    // Re-initialize Alpine if needed
    if (typeof Alpine !== 'undefined') {
        const main = document.querySelector('main[x-data="userManagement()"]');
        if (main && !main.__x && !main._x_dataStack) {
            try {
                Alpine.initTree(main);
            } catch (e) {
                console.error('Alpine init error:', e);
            }
        }
    }

    // Filter listeners (idempotent)
    const inputs = ['fp_role', 'fp_status', 'fp_date_from', 'fp_date_to'];
    inputs.forEach(id => {
        const el = document.getElementById(id);
        if (el && !el._pf_bound) {
            el._pf_bound = true;
            el.addEventListener('change', () => fetchUpdatedTable());
        }
    });

    const searchInput = document.getElementById('fp_search');
    if (searchInput && !searchInput._pf_bound) {
        searchInput._pf_bound = true;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                fetchUpdatedTable();
            }, 500);
        });
    }
    
    // Ensure bridge is ready after a short delay
    setTimeout(initAlpineGlobalBridge, 100);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', printflowInitUserStaffPage);
} else {
    printflowInitUserStaffPage();
}
document.addEventListener('printflow:page-init', printflowInitUserStaffPage);

// Turbo navigation support
if (typeof Turbo !== 'undefined') {
    document.addEventListener('turbo:load', printflowInitUserStaffPage);
    document.addEventListener('turbo:render', printflowInitUserStaffPage);
}

(function () {
    var UM_API = <?php echo json_encode($base_path . '/admin/api_update_user_status.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.fetchArchivedUsersList = async function () {
        var c = document.getElementById('archived-users-container');
        if (!c) return;
        c.innerHTML = '<p style="text-align:center;color:#9ca3af;">Loading…</p>';
        try {
            var path = window.location.pathname || '';
            var sep = path.indexOf('?') >= 0 ? '&' : '?';
            var r = await fetch(path + sep + 'get_archived=1');
            var data = await r.json();
            if (data.success) {
                c.innerHTML = data.html;
            } else {
                c.innerHTML = '<p style="text-align:center;color:#ef4444;">Failed to load archived accounts.</p>';
            }
        } catch (e) {
            c.innerHTML = '<p style="text-align:center;color:#ef4444;">Error loading archive.</p>';
        }
    };
    window.openArchivedUsersModal = function () {
        var el = document.getElementById('um-archive-overlay');
        if (!el) return;
        el.style.display = 'flex';
        window.fetchArchivedUsersList();
    };
    window.closeArchivedUsersModal = function () {
        var el = document.getElementById('um-archive-overlay');
        if (el) el.style.display = 'none';
    };
    if (!window._pfArchivedUsersRestoreBound) {
        window._pfArchivedUsersRestoreBound = true;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.pf-restore-archived-user');
            if (!btn) return;
            var uid = parseInt(btn.getAttribute('data-user-id') || '0', 10);
            var csrf = btn.getAttribute('data-csrf') || '';
            if (!(uid > 0)) return;
            e.preventDefault();
            e.stopPropagation();
            if (!window.confirm('Restore this account? It will return as deactivated.')) return;
            btn.disabled = true;
            fetch(UM_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'restore_archived_user', user_id: uid, csrf_token: csrf })
            }).then(function (r) { return r.json(); }).then(function (data) {
                btn.disabled = false;
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Restore failed.');
                }
            }).catch(function () {
                btn.disabled = false;
                alert('Network error.');
            });
        }, true);
    }
})();

// Global expose to bridge AJAX table clicks to userManagement Alpine component
function initAlpineGlobalBridge() {
    const getData = () => {
        const el = document.querySelector('[x-data="userManagement()"]');
        return (el && el.__x && el.__x.$data) ? el.__x.$data : (el && el._x_dataStack ? el._x_dataStack[0] : null);
    };
    window._viewUser = (id) => { const d = getData(); if (d) d.viewUser(id); };
    window._editUser = (id) => { const d = getData(); if (d) d.editUser(id); };
    window._archiveUser = (id) => { const d = getData(); if (d) d.showArchiveConfirm(id); };
}

// Initialize immediately and on all page events
initAlpineGlobalBridge();
document.addEventListener('alpine:init', initAlpineGlobalBridge);
document.addEventListener('alpine:initialized', initAlpineGlobalBridge);
document.addEventListener('printflow:page-init', initAlpineGlobalBridge);
if (typeof Turbo !== 'undefined') {
    document.addEventListener('turbo:load', initAlpineGlobalBridge);
    document.addEventListener('turbo:render', initAlpineGlobalBridge);
}

/** Open user detail modal when arriving from a notification (?open_user=id). */
function pfConsumeOpenUserFromQuery() {
    if (!document.querySelector('[x-data="userManagement()"]')) return;
    var p = new URLSearchParams(window.location.search);
    var raw = p.get('open_user');
    if (!raw) return;
    var uid = parseInt(raw, 10);
    if (!(uid > 0)) return;
    var stripParam = function () {
        p.delete('open_user');
        var qs = p.toString();
        window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
    };
    var attempt = 0;
    var t = setInterval(function () {
        attempt++;
        if (typeof window._viewUser === 'function') {
            window._viewUser(uid);
            stripParam();
            clearInterval(t);
        } else if (attempt > 50) {
            clearInterval(t);
        }
    }, 50);
}

document.addEventListener('printflow:page-init', pfConsumeOpenUserFromQuery);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', pfConsumeOpenUserFromQuery);
} else {
    pfConsumeOpenUserFromQuery();
}
if (typeof Turbo !== 'undefined') {
    document.addEventListener('turbo:load', pfConsumeOpenUserFromQuery);
    document.addEventListener('turbo:render', pfConsumeOpenUserFromQuery);
}

// Mark Alpine as loaded to prevent layout shift
if (typeof Alpine !== 'undefined') {
    document.addEventListener('alpine:init', function() {
        document.body.classList.add('alpine-loaded');
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            document.body.classList.add('alpine-loaded');
        }, 100);
    });
} else {
    document.body.classList.add('alpine-loaded');
}

// Trigger page init event after Turbo navigation
if (typeof Turbo !== 'undefined') {
    document.addEventListener('turbo:load', function() {
        document.dispatchEvent(new Event('printflow:page-init'));
    });
    document.addEventListener('turbo:render', function() {
        document.dispatchEvent(new Event('printflow:page-init'));
    });
}
</script>
</body>
</html>
