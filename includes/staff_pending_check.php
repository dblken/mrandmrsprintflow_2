<?php
/**
 * Staff: sync session with database, then keep pending users on profile.
 *
 * Admin may activate an account after login — session must reflect users.status
 * or the UI keeps showing "Pending" while the DB is Activated.
 */
printflow_guard_archived_staff_manager();

$__pf_um_user_type = get_user_type();

if ($__pf_um_user_type === 'Manager') {
    return;
}

if ($__pf_um_user_type !== 'Staff') {
    return;
}

$user_id = get_user_id();
if ($user_id) {
    $check = db_query('SELECT status, branch_id FROM users WHERE user_id = ? LIMIT 1', 'i', [$user_id]);
    if (!empty($check)) {
        $_SESSION['user_status'] = $check[0]['status'];
        $bid = $check[0]['branch_id'] ?? null;
        $_SESSION['branch_id'] = $bid;
        if ($bid !== null && $bid !== '') {
            $_SESSION['selected_branch_id'] = (int) $bid;
        }
    }
}

$on_profile = basename($_SERVER['PHP_SELF'] ?? '') === 'profile.php';
if (isset($_SESSION['user_status']) && $_SESSION['user_status'] === 'Pending' && !$on_profile) {
    $base = defined('BASE_PATH') ? BASE_PATH : (defined('BASE_URL') ? BASE_URL : '/printflow');
    redirect($base . '/staff/profile.php');
    exit;
}
