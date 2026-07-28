<?php
/**
 * Manager - Payment
 * Branch-scoped payment verification view for the manager's assigned branch.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('Manager');

$manager_user = get_logged_in_user();
$manager_branch_id = (int)($manager_user['branch_id'] ?? 0);

if ($manager_branch_id <= 0) {
    die('<div style="padding:40px;text-align:center;"><h2>No Branch Assigned</h2><p>Your account is not assigned to a branch. Please contact an administrator.</p></div>');
}

$_SESSION['selected_branch_id'] = $manager_branch_id;
$_GET['branch_id'] = $manager_branch_id;

define('MANAGER_PANEL', true);
require __DIR__ . '/../admin/payment.php';