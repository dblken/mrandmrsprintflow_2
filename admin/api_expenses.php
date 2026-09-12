<?php
/**
 * Admin/Manager Expense Management API
 */

require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/expense_management.php';

require_role(['Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$input = str_contains($contentType, 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_POST;

if (!verify_csrf_token($input['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'code' => 'csrf_mismatch',
        'error' => 'Your session expired. Please refresh and try again.',
        'csrf_token' => generate_csrf_token(),
    ]);
    exit;
}

pf_ensure_expenses_table();

$action = trim((string)($input['action'] ?? ''));
$userId = (int)(get_user_id() ?? 0);

if ($action === 'create') {
    $validated = pf_expense_validate_payload($input, false);
    if (!$validated['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'errors' => $validated['errors']]);
        exit;
    }

    $d = $validated['data'];
    $paidAt = ($d['status'] === 'Paid') ? date('Y-m-d H:i:s') : null;
    $notes = $d['notes'];
    $createdBy = $userId > 0 ? $userId : null;

    $result = db_execute(
        "INSERT INTO expenses (expense_name, category, branch_id, amount, expense_date, status, payment_method, notes, paid_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'ssidsssssi',
        [
            $d['expense_name'],
            $d['category'],
            $d['branch_id'],
            $d['amount'],
            $d['expense_date'],
            $d['status'],
            $d['payment_method'],
            $notes,
            $paidAt,
            $createdBy,
        ]
    );

    if ($result === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save expense.']);
        exit;
    }

    $newId = (int)(is_numeric($result) ? $result : 0);
    if ($newId <= 0) {
        $newId = (int)($GLOBALS['conn']->insert_id ?? 0);
    }

    echo json_encode([
        'success' => true,
        'expense_id' => $newId,
        'csrf_token' => generate_csrf_token(),
    ]);
    exit;
}

if ($action === 'update') {
    $validated = pf_expense_validate_payload($input, true);
    if (!$validated['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'errors' => $validated['errors']]);
        exit;
    }

    $d = $validated['data'];
    $existing = pf_expense_get_row($d['expense_id']);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Expense not found.']);
        exit;
    }

    if ((string)($existing['status'] ?? '') === 'Archived') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Archived expenses cannot be edited. Restore it first.']);
        exit;
    }

    if (!pf_expense_user_can_manage_row($existing)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You cannot modify expenses for this branch.']);
        exit;
    }

    $paidAt = ($d['status'] === 'Paid')
        ? ((string)($existing['status'] ?? '') === 'Paid' && !empty($existing['paid_at']) ? $existing['paid_at'] : date('Y-m-d H:i:s'))
        : null;

    $notes = $d['notes'];

    $updated = db_execute(
        "UPDATE expenses
         SET expense_name = ?, category = ?, branch_id = ?, amount = ?, expense_date = ?,
             status = ?, payment_method = ?, notes = ?, paid_at = ?
         WHERE expense_id = ? AND status IN ('Paid', 'To Be Paid')",
        'ssidsssssi',
        [
            $d['expense_name'],
            $d['category'],
            $d['branch_id'],
            $d['amount'],
            $d['expense_date'],
            $d['status'],
            $d['payment_method'],
            $notes,
            $paidAt,
            $d['expense_id'],
        ]
    );

    if ($updated === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update expense.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'expense_id' => $d['expense_id'],
        'csrf_token' => generate_csrf_token(),
    ]);
    exit;
}

if ($action === 'archive') {
    $expenseId = (int)($input['expense_id'] ?? 0);
    if ($expenseId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid expense record.']);
        exit;
    }

    $existing = pf_expense_get_row($expenseId);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Expense not found.']);
        exit;
    }

    if (!pf_expense_user_can_manage_row($existing)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You cannot archive expenses for this branch.']);
        exit;
    }

    if ((string)($existing['status'] ?? '') === 'Archived') {
        echo json_encode(['success' => true, 'csrf_token' => generate_csrf_token()]);
        exit;
    }

    $updated = db_execute(
        "UPDATE expenses SET status = 'Archived', archived_at = NOW() WHERE expense_id = ? AND status IN ('Paid', 'To Be Paid')",
        'i',
        [$expenseId]
    );

    if ($updated === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to archive expense.']);
        exit;
    }

    echo json_encode(['success' => true, 'csrf_token' => generate_csrf_token()]);
    exit;
}

if ($action === 'restore') {
    $expenseId = (int)($input['expense_id'] ?? 0);
    if ($expenseId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid expense record.']);
        exit;
    }

    $existing = pf_expense_get_row($expenseId);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Expense not found.']);
        exit;
    }

    if (!pf_expense_user_can_manage_row($existing)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You cannot restore expenses for this branch.']);
        exit;
    }

    if ((string)($existing['status'] ?? '') !== 'Archived') {
        echo json_encode(['success' => true, 'csrf_token' => generate_csrf_token()]);
        exit;
    }

    $restoreStatus = !empty($existing['paid_at']) ? 'Paid' : 'To Be Paid';
    $updated = db_execute(
        "UPDATE expenses SET status = ?, archived_at = NULL WHERE expense_id = ? AND status = 'Archived'",
        'si',
        [$restoreStatus, $expenseId]
    );

    if ($updated === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to restore expense.']);
        exit;
    }

    echo json_encode(['success' => true, 'csrf_token' => generate_csrf_token()]);
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
exit;
