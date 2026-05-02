<?php
/**
 * Admin Branch API
 * PrintFlow - Printing Shop PWA
 * Handles Create/Update operations for Branches & Auto-Inventory Initialization
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Access Control: ONLY Owner or Admin
require_role(['Owner', 'Admin']);

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Ensure payload exists
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

// Basic CSRF check (Assuming the dashboard provides `csrf_token` via session or header)
if (!isset($data['csrf_token']) || $data['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $data['action'] ?? '';

try {
    global $conn;
    
    // ==========================================
    // ACTION: CHECK EMAIL EXISTENCE
    // ==========================================
    if ($action === 'check_email') {
        $email = sanitize($data['email'] ?? '');
        $exclude_id = (int)($data['exclude_id'] ?? 0);

        if (empty($email)) {
            echo json_encode(['success' => true, 'exists' => false]);
            exit;
        }

        $query = "SELECT id FROM branches WHERE email = ? AND status != 'Archived'";
        $params = [$email];
        $types = "s";

        if ($exclude_id > 0) {
            $query .= " AND id != ?";
            $params[] = $exclude_id;
            $types .= "i";
        }

        $result = db_query($query, $types, $params);
        echo json_encode(['success' => true, 'exists' => !empty($result)]);
        exit;
    }

    // ==========================================
    // ACTION: GET ARCHIVED BRANCHES
    // ==========================================
    if ($action === 'get_archived') {
        $page = max(1, (int)($data['page'] ?? 1));
        $per_page = 7;
        
        $total = db_query("SELECT COUNT(*) as c FROM branches WHERE status = 'Archived'")[0]['c'] ?? 0;
        $total_pages = max(1, (int)ceil($total / $per_page));
        $page = min($page, $total_pages);
        $offset = ($page - 1) * $per_page;
        
        $archived = db_query("
            SELECT b.*, (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.role = 'Staff') as staff_count
            FROM branches b
            WHERE b.status = 'Archived'
            ORDER BY b.created_at DESC
            LIMIT $per_page OFFSET $offset
        ");
        
        $html = '<table class="branches-table" style="width:100%; min-width:600px;">';
        $html .= '<thead><tr><th>ID</th><th>Branch Name</th><th>Email</th><th style="text-align:right;">Actions</th></tr></thead>';
        $html .= '<tbody>';
        
        if (empty($archived)) {
            $html .= '<tr><td colspan="4" style="padding:40px;text-align:center;color:#9ca3af;">No archived branches found.</td></tr>';
        } else {
            foreach ($archived as $b) {
                $viewData = [
                    'id' => $b['id'],
                    'name' => $b['branch_name'],
                    'email' => $b['email'] ?? '',
                    'address' => $b['address'] ?? '',
                    'address_line' => $b['address_line'] ?? '',
                    'address_barangay' => $b['barangay'] ?? '',
                    'address_city' => $b['city'] ?? '',
                    'address_province' => $b['province'] ?? '',
                    'contact' => $b['contact_number'] ?? '',
                    'status' => $b['status'],
                    'staff_count' => $b['staff_count'] ?? 0
                ];
                $viewDataAttr = htmlspecialchars(json_encode($viewData), ENT_QUOTES, 'UTF-8');
                $html .= '<tr>';
                $html .= '<td>' . $b['id'] . '</td>';
                $html .= '<td style="font-weight:500; max-width:200px; word-break:break-word;">' . htmlspecialchars($b['branch_name']) . '</td>';
                $html .= '<td style="max-width:220px; word-break:break-all;">' . htmlspecialchars($b['email'] ?? '—') . '</td>';
                $html .= '<td style="text-align:right;white-space:nowrap;">';
                $html .= '<button type="button" data-action="view" data-branch="' . $viewDataAttr . '" class="btn-action blue" style="margin-right:4px;">View</button>';
                $html .= '<button type="button" data-action="restore" data-id="' . (int)$b['id'] . '" data-name="' . htmlspecialchars($b['branch_name'], ENT_QUOTES) . '" class="btn-action teal">Restore</button>';
                $html .= '</td></tr>';
            }
        }
        $html .= '</tbody></table>';
        
        // Pagination (JS-triggered, same style as main page)
        $pagination = '';
        if ($total_pages > 1) {
            $base_btn = 'display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border-radius:6px;border:1px solid #e5e7eb;background:white;color:#374151;text-decoration:none;font-size:13px;font-weight:500;cursor:pointer;';
            $active_btn = 'display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border-radius:6px;border:1px solid #0d9488;background:#0d9488;color:white;text-decoration:none;font-size:13px;font-weight:600;cursor:pointer;';
            $pagination = '<div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-top:20px;padding-top:16px;border-top:1px solid #f3f4f6;">';
            if ($page > 1) {
                $pagination .= '<a href="#" data-archive-page="' . ($page - 1) . '" style="' . $base_btn . '"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></a>';
            }
            $window = 2;
            $pages = array_unique([1, max(1, $page - $window), $page, min($total_pages, $page + $window), $total_pages]);
            sort($pages);
            $prev = null;
            foreach ($pages as $p) {
                if ($prev !== null && $p - $prev > 1) {
                    $pagination .= '<span style="display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;font-size:13px;color:#9ca3af;">···</span>';
                }
                $style = ($p === $page) ? $active_btn : $base_btn;
                $pagination .= '<a href="#" data-archive-page="' . $p . '" style="' . $style . '">' . $p . '</a>';
                $prev = $p;
            }
            if ($page < $total_pages) {
                $pagination .= '<a href="#" data-archive-page="' . ($page + 1) . '" style="' . $base_btn . '"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></a>';
            }
            $pagination .= '</div>';
        }
        
        echo json_encode(['success' => true, 'html' => $html, 'pagination' => $pagination]);
        exit;
    }

    // ==========================================
    // ACTION: CREATE BRANCH
    // ==========================================
    if ($action === 'create') {
        $branch_name      = normalize_branch_name($data['branch_name'] ?? '');
        $email            = sanitize($data['email'] ?? '');
        $contact_number   = sanitize($data['contact_number'] ?? '09');
        $address          = sanitize($data['address'] ?? '');
        $address_line     = sanitize($data['address_line'] ?? '');
        $barangay         = sanitize($data['address_barangay'] ?? '');
        $city             = sanitize($data['address_city'] ?? '');
        $province         = sanitize($data['address_province'] ?? '');
        
        // Auto-append "Branch" if missing (user should not type "branch" — we add it)
        if (!empty($branch_name) && !preg_match('/\bBranch$/i', $branch_name)) {
            $branch_name .= ' Branch';
        }
        
        if (empty($branch_name)) {
            echo json_encode(['success' => false, 'error' => 'Branch Name is required']);
            exit;
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid Email Address format']);
            exit;
        }

        // Check Unique Name (Ignore Archived)
        $existing = db_query("SELECT id FROM branches WHERE branch_name = ? AND status != 'Archived'", 's', [$branch_name]);
        if (!empty($existing)) {
            echo json_encode(['success' => false, 'error' => 'A Branch with that name already exists']);
            exit;
        }

        $conn->begin_transaction();
        
        // 1. Insert new Branch
        $new_branch_id = db_execute(
            "INSERT INTO branches (branch_name, email, address, address_line, barangay, city, province, contact_number, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())",
            'ssssssss', [$branch_name, $email, $address, $address_line, $barangay, $city, $province, $contact_number] // 'status' defaults to Active
        );
        
        if (!$new_branch_id) {
            throw new Exception("Failed to insert branch record.");
        }

        // 2. Initialize Inventory automatically mapping all native materials explicitly to this branch (qty 0)
        // INSERT IGNORE will safely skip constraints if somehow already exists
        $init_success = db_execute("
            INSERT IGNORE INTO branch_inventory (branch_id, material_id, stock_quantity, last_updated)
            SELECT ?, material_id, 0.00, NOW() 
            FROM materials
        ", 'i', [$new_branch_id]);
        
        if ($init_success === false) {
             throw new Exception("Failed to initialize inventory for new branch.");
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => "Branch '{$branch_name}' successfully created.", 'branch_id' => $new_branch_id]);
        exit;
    } 
    
    // ==========================================
    // ACTION: UPDATE BRANCH
    // ==========================================
    elseif ($action === 'update') {
        $branch_id      = (int)($data['branch_id'] ?? 0);
        $branch_name    = normalize_branch_name($data['branch_name'] ?? '');
        $email            = sanitize($data['email'] ?? '');
        $address          = sanitize($data['address'] ?? '');
        $address_line     = sanitize($data['address_line'] ?? '');
        $barangay         = sanitize($data['address_barangay'] ?? '');
        $city             = sanitize($data['address_city'] ?? '');
        $province         = sanitize($data['address_province'] ?? '');
        $contact_number = sanitize($data['contact_number'] ?? '');
        $status         = sanitize($data['status'] ?? 'Active');
        
        // Auto-append "Branch" if missing
        if (!empty($branch_name) && !preg_match('/\bBranch$/i', $branch_name)) {
            $branch_name .= ' Branch';
        }
        
        if (!$branch_id) {
            echo json_encode(['success' => false, 'error' => 'Branch ID is required']);
            exit;
        }
        
        if (empty($branch_name)) {
            echo json_encode(['success' => false, 'error' => 'Branch Name cannot be empty']);
            exit;
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid Email Address format']);
            exit;
        }
        
        // Ensure status is valid ENUM
        if (!in_array($status, ['Active', 'Inactive'])) {
            $status = 'Active';
        }

        // Verify the ID belongs to an existing branch
        $check = db_query("SELECT id FROM branches WHERE id = ?", 'i', [$branch_id]);
        if (empty($check)) {
            echo json_encode(['success' => false, 'error' => 'Branch not found']);
            exit;
        }
        
        // Make sure a name collision wasn't triggered (Ignore Archived)
        $collision = db_query("SELECT id FROM branches WHERE branch_name = ? AND id != ? AND status != 'Archived'", 'si', [$branch_name, $branch_id]);
        if (!empty($collision)) {
            echo json_encode(['success' => false, 'error' => 'Another Branch is already using that Name']);
            exit;
        }
        
        $ok = db_execute(
            "UPDATE branches SET branch_name = ?, email = ?, address = ?, address_line = ?, barangay = ?, city = ?, province = ?, contact_number = ?, status = ? WHERE id = ?",
            'sssssssssi',
            [$branch_name, $email, $address, $address_line, $barangay, $city, $province, $contact_number, $status, $branch_id]
        );
        
        if ($ok) {
            echo json_encode(['success' => true, 'message' => "Branch successfully updated."]);
        } else {
            echo json_encode(['success' => false, 'error' => "No changes were made."]);
        }
        exit;
    } 

    elseif ($action === 'archive') {
        $branch_id = (int)($data['branch_id'] ?? 0);
        if (!$branch_id) {
            echo json_encode(['success' => false, 'error' => 'Branch ID is required']);
            exit;
        }

        $result = db_execute("UPDATE branches SET status = 'Archived' WHERE id = ?", 'i', [$branch_id]);
        echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Branch archived successfully.' : 'Failed to archive branch.']);
        exit;
    }

    elseif ($action === 'restore') {
        $branch_id = (int)($data['branch_id'] ?? 0);
        if (!$branch_id) {
            echo json_encode(['success' => false, 'error' => 'Branch ID is required']);
            exit;
        }

        $result = db_execute("UPDATE branches SET status = 'Active' WHERE id = ?", 'i', [$branch_id]);
        echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Branch restored successfully.' : 'Failed to restore branch.']);
        exit;
    }

    elseif ($action === 'delete_permanent') {
        echo json_encode(['success' => false, 'error' => 'Permanent deletion of archived branches has been disabled. Restore a branch if you need it active again.']);
        exit;
    }

    else {
        echo json_encode(['success' => false, 'error' => 'Unknown Action requested: ' . $action]);
        exit;
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    error_log("API Branch Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An unexpected server error occurred.']);
}
