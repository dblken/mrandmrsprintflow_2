<?php
/**
 * Admin FAQ & support chat management
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Admin');
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();

$error = '';
$success = '';

// Legacy table (kept for migration fallback)
db_execute("CREATE TABLE IF NOT EXISTS chatbot_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(100) DEFAULT 'Guest',
    customer_email VARCHAR(150) DEFAULT NULL,
    question TEXT NOT NULL,
    admin_reply TEXT DEFAULT NULL,
    status ENUM('pending','answered') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    replied_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// New conversation-based tables + migration
db_execute("CREATE TABLE IF NOT EXISTS chatbot_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT DEFAULT NULL,
    guest_id VARCHAR(64) DEFAULT NULL,
    customer_name VARCHAR(100) DEFAULT 'Guest',
    customer_email VARCHAR(150) DEFAULT NULL,
    last_message_preview VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','answered','expired') DEFAULT 'pending',
    is_archived TINYINT(1) DEFAULT 0,
    last_activity_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_customer (customer_id), KEY idx_guest (guest_id), KEY idx_status (status), KEY idx_activity (last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db_execute("CREATE TABLE IF NOT EXISTS chatbot_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_type ENUM('customer','admin') NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_conv (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// One-time migration from old inquiries to conversations
$migrated = db_query("SELECT value FROM settings WHERE key_name = 'chatbot_migrated_v2'");
if (empty($migrated)) {
    $old = db_query("SELECT * FROM chatbot_inquiries ORDER BY id ASC") ?: [];
    foreach ($old as $o) {
        $preview = mb_strlen($o['question']) > 100 ? mb_substr($o['question'], 0, 100) . '...' : $o['question'];
        $cid = db_execute("INSERT INTO chatbot_conversations (customer_name, customer_email, last_message_preview, status, last_activity_at, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            'ssssss', [$o['customer_name'] ?: 'Guest', $o['customer_email'], $preview, $o['status'], $o['created_at'], $o['created_at']]);
        if ($cid === true) { global $conn; $cid = (int)($conn->insert_id ?? 0); }
        if ($cid) {
            db_execute("INSERT INTO chatbot_messages (conversation_id, sender_type, message, created_at) VALUES (?, 'customer', ?, ?)", 'iss', [$cid, $o['question'], $o['created_at']]);
            if (!empty($o['admin_reply'])) {
                db_execute("INSERT INTO chatbot_messages (conversation_id, sender_type, message, created_at) VALUES (?, 'admin', ?, ?)", 'iss', [$cid, $o['admin_reply'], $o['replied_at'] ?? $o['created_at']]);
            }
        }
    }
    db_execute("INSERT INTO settings (key_name, value) VALUES ('chatbot_migrated_v2', '1')");
}

// Success from redirect
if (isset($_GET['replied'])) $success = 'Reply sent successfully!';
if (isset($_GET['deleted'])) $success = 'Conversation archived!';

// Handle FAQ creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_faq']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $question = sanitize($_POST['question']);
    $answer   = sanitize($_POST['answer']);
    $status   = $_POST['status'];
    db_execute("INSERT INTO faq (question, answer, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())", 'sss', [$question, $answer, $status]);
    $success = 'FAQ created successfully!';
}

// Handle FAQ update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_faq']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $faq_id   = (int)$_POST['faq_id'];
    $question = sanitize($_POST['question']);
    $answer   = sanitize($_POST['answer']);
    $status   = $_POST['status'];
    db_execute("UPDATE faq SET question = ?, answer = ?, status = ?, updated_at = NOW() WHERE faq_id = ?", 'sssi', [$question, $answer, $status, $faq_id]);
    $success = 'FAQ updated successfully!';
}

// Handle FAQ delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_faq']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $faq_id = (int)$_POST['faq_id'];
    db_execute("DELETE FROM faq WHERE faq_id = ?", 'i', [$faq_id]);
    $success = 'FAQ deleted successfully!';
}

// Handle inquiry reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_inquiry']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $inq_id = (int)$_POST['inquiry_id'];
    $reply  = sanitize($_POST['admin_reply']);
    if (!empty($reply)) {
        db_execute("UPDATE chatbot_inquiries SET admin_reply = ?, status = 'answered', replied_at = NOW() WHERE id = ?", 'si', [$reply, $inq_id]);
        header('Location: faq_chatbot_management.php?tab=inquiries&replied=1');
        exit;
    }
}

// Handle inquiry delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_inquiry']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $inq_id = (int)$_POST['inquiry_id'];
    db_execute("DELETE FROM chatbot_inquiries WHERE id = ?", 'i', [$inq_id]);
    header('Location: faq_chatbot_management.php?tab=inquiries&deleted=1');
    exit;
}

$faqs = db_query("SELECT * FROM faq ORDER BY created_at DESC");
$stat_total      = count($faqs);
$stat_active     = count(array_filter($faqs, fn($f) => $f['status'] === 'Activated'));
$stat_inactive   = $stat_total - $stat_active;

// Pagination for Responses tab
$faq_page = 1;
$faq_per_page = 5;
$faq_total_pages = 1;
$faq_paginated = $faqs;
$active_tab = $_GET['tab'] ?? 'responses';
if ($active_tab === 'responses') {
    $faq_page = max(1, (int)($_GET['page'] ?? 1));
    $faq_per_page = 5;
    $faq_total_count = count($faqs);
    $faq_total_pages = (int)ceil(max(1, $faq_total_count) / $faq_per_page);
    $faq_page = min($faq_page, $faq_total_pages);
    $faq_offset = ($faq_page - 1) * $faq_per_page;
    $faq_paginated = array_slice($faqs, $faq_offset, $faq_per_page);
}

// Fetch conversation counts (active inbox only, excludes archived)
$chatbotCustomerScope = printflow_customer_exclusion_customer_scope_sql('c.customer_id');
$conv_counts = db_query("SELECT c.status, COUNT(*) as cnt FROM chatbot_conversations c WHERE " . $chatbotCustomerScope . " AND (c.is_archived = 0 OR c.is_archived IS NULL) GROUP BY c.status") ?: [];
$inq_pending  = 0;
$inq_answered = 0;
foreach ($conv_counts as $r) {
    if ($r['status'] === 'pending') $inq_pending = (int)$r['cnt'];
    if ($r['status'] === 'answered') $inq_answered = (int)$r['cnt'];
}
$conv_total = db_query("SELECT COUNT(*) as cnt FROM chatbot_conversations c WHERE " . $chatbotCustomerScope) ?: [];
$inq_total  = (int)($conv_total[0]['cnt'] ?? 0);

// Server-side: fetch conversations when on inquiries tab (shows data immediately, no JS fetch needed for initial load)
$inq_conversations = [];
$inq_filter = 'all';
$inq_page = 1;
$inq_per_page = 15;
$inq_total_count = 0;
$inq_total_pages = 1;
$inq_search = '';
if ($active_tab === 'inquiries') {
$inq_filter = $_GET['filter'] ?? 'all';
$inq_page = max(1, (int)($_GET['page'] ?? 1));
$inq_per_page = 5;
$inq_offset = ($inq_page - 1) * $inq_per_page;
$inq_search = trim($_GET['search'] ?? '');
$inq_where = ["1=1"];
$inq_where[] = printflow_customer_exclusion_customer_scope_sql('c.customer_id');
$inq_params = [];
$inq_types = '';
if ($inq_filter === 'pending') $inq_where[] = "c.status = 'pending' AND (c.is_archived = 0 OR c.is_archived IS NULL)";
elseif ($inq_filter === 'answered') $inq_where[] = "c.status = 'answered' AND (c.is_archived = 0 OR c.is_archived IS NULL)";
elseif ($inq_filter === 'archived') $inq_where[] = "c.is_archived = 1";
else $inq_where[] = "(c.is_archived = 0 OR c.is_archived IS NULL)";
if ($inq_search) {
    $inq_where[] = "(c.customer_name LIKE ? OR c.customer_email LIKE ? OR c.last_message_preview LIKE ?)";
    $t = '%' . $inq_search . '%';
    $inq_params = [$t, $t, $t];
    $inq_types = 'sss';
}
$inq_where_sql = implode(' AND ', $inq_where);
$inq_total_count = (int)(db_query("SELECT COUNT(*) as cnt FROM chatbot_conversations c WHERE $inq_where_sql", $inq_types, $inq_params)[0]['cnt'] ?? 0);
$inq_conversations = db_query(
    "SELECT c.id, c.customer_id, c.guest_id, c.customer_name, c.customer_email, c.last_message_preview, c.status, c.last_activity_at,
        COALESCE(CONCAT(cm.first_name, ' ', cm.last_name), c.customer_name, 'Guest') as display_name
     FROM chatbot_conversations c LEFT JOIN customers cm ON c.customer_id = cm.customer_id
     WHERE $inq_where_sql ORDER BY c.last_activity_at DESC LIMIT ? OFFSET ?",
    $inq_types . 'ii', array_merge($inq_params, [$inq_per_page, $inq_offset])
) ?: [];
$inq_total_pages = (int)ceil(max(1, $inq_total_count) / $inq_per_page);
}
$page_title = 'Support Chat Management - Admin';
$inq_api_url = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base_path . '/admin/api_chatbot_conversations.php';
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
        /* Tab Navigation */
        .cb-tabs { display:flex; gap:0; margin-bottom:24px; border-bottom:2px solid #e5e7eb; }
        .cb-tab { padding:12px 24px; font-size:14px; font-weight:600; color:#6b7280; background:none; border:none; cursor:pointer; position:relative; transition:all .2s; }
        .cb-tab:hover { color:#00232b; }
        .cb-tab.active { color:#00232b; }
        .cb-tab.active::after { content:''; position:absolute; bottom:-2px; left:0; right:0; height:2px; background:#00232b; border-radius:2px 2px 0 0; }
        .cb-tab .tab-badge { display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 6px; border-radius:10px; font-size:11px; font-weight:700; margin-left:6px; }
        .cb-tab .tab-badge.pending { background:#fef3c7; color:#92400e; }
        .cb-tab-content { display:none; }
        .cb-tab-content.active { display:block; }

        /* KPI Cards */
        .kpi-row { display:grid; grid-template-columns:repeat(2,1fr); gap:16px; margin-bottom:24px; }
        @media(max-width:768px) { .kpi-row { grid-template-columns:1fr 1fr; } }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-card.rose::before { background:linear-gradient(90deg,#e11d48,#fb7185); }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:4px; }

        /* FAQ Card */
        .faq-item { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px 24px; margin-bottom:12px; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; transition:box-shadow .15s; }
        .faq-item:hover { box-shadow:0 2px 8px rgba(0,0,0,0.07); }
        .faq-question { font-size:15px; font-weight:600; color:#111827; margin-bottom:6px; }
        .faq-answer { font-size:14px; color:#6b7280; line-height:1.6; margin-bottom:10px; }
        .faq-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .faq-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:12px; font-weight:600; }
        .faq-badge.active { background:#dcfce7; color:#166534; }
        .faq-badge.inactive { background:#fee2e2; color:#991b1b; }
        .faq-actions { display:flex; gap:8px; flex-shrink:0; }
        .btn-edit { padding:6px 14px; border:1.5px solid #6366f1; color:#6366f1; background:transparent; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; transition:all .18s; }
        .btn-edit:hover { background:#6366f1; color:#fff; }
        .btn-del { padding:6px 14px; border:1.5px solid #e11d48; color:#e11d48; background:transparent; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; transition:all .18s; }
        .btn-del:hover { background:#e11d48; color:#fff; }

        /* Inquiry Card */
        .inq-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px 24px; margin-bottom:12px; transition:box-shadow .15s; }
        .inq-card:hover { box-shadow:0 2px 8px rgba(0,0,0,0.07); }
        .inq-card.pending { border-left:3px solid #f59e0b; }
        .inq-card.answered { border-left:3px solid #059669; }
        .inq-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:10px; }
        .inq-status { display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700; }
        .inq-status.pending { background:#fef3c7; color:#92400e; }
        .inq-status.answered { background:#dcfce7; color:#166534; }
        .inq-question { font-size:15px; font-weight:600; color:#111827; line-height:1.5; margin-bottom:6px; }
        .inq-meta { font-size:12px; color:#9ca3af; margin-bottom:12px; }
        .inq-reply-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-top:10px; }
        .inq-reply-label { font-size:11px; font-weight:700; color:#059669; text-transform:uppercase; letter-spacing:.4px; margin-bottom:6px; }
        .inq-reply-text { font-size:14px; color:#374151; line-height:1.6; }

        /* Modal */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9900; align-items:center; justify-content:center; padding:16px; }
        .modal-overlay.open { display:flex; }
        .modal-box { background:#fff; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,0.15); width:100%; max-width:560px; max-height:90vh; overflow-y:auto; }
        .modal-hdr { display:flex; align-items:center; justify-content:space-between; padding:20px 24px 16px; border-bottom:1px solid #f3f4f6; }
        .modal-hdr h2 { font-size:16px; font-weight:700; color:#111827; margin:0; }
        .modal-hdr button { background:none; border:none; font-size:20px; color:#9ca3af; cursor:pointer; }
        .modal-hdr button:hover { color:#374151; }
        .modal-bdy { padding:20px 24px; }
        .f-group { margin-bottom:16px; }
        .f-group label { display:block; font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
        .f-group input, .f-group select, .f-group textarea { width:100%; padding:9px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; color:#111827; background:#fafafa; outline:none; transition:border-color .15s; box-sizing:border-box; }
        .f-group input:focus, .f-group select:focus, .f-group textarea:focus { border-color:#6366f1; background:#fff; }
        .f-group textarea { resize:vertical; min-height:100px; }
        .modal-ftr { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; border-top:1px solid #f3f4f6; }
        .btn-cancel { padding:9px 18px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; font-size:14px; font-weight:600; color:#374151; cursor:pointer; }
        .btn-submit { padding:9px 22px; border:none; border-radius:8px; background:#00232b; color:#fff; font-size:14px; font-weight:600; cursor:pointer; }
        .btn-submit:hover { opacity:.88; }

        /* Inbox table */
        .inbox-toolbar { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:16px; }
        .inbox-search { flex:1; min-width:200px; padding:10px 14px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; }
        .inbox-filters { display:flex; gap:4px; }
        .inbox-filter { padding:8px 16px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; color:#6b7280; transition:all .2s; }
        .inbox-filter:hover { border-color:#00232b; color:#00232b; }
        .inbox-filter.active { background:#00232b; border-color:#00232b; color:#fff; }
        .inbox-table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08); }
        .inbox-table th { padding:14px 16px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; background:#f9fafb; border-bottom:1px solid #e5e7eb; }
        .inbox-table td { padding:14px 16px; border-bottom:1px solid #f3f4f6; font-size:14px; color:#374151; }
        .inbox-table tr:hover { background:#f9fafb; }
        .inbox-table tr { cursor:pointer; }
        .inbox-table .status-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
        .inbox-table .status-badge.pending { background:#fef3c7; color:#92400e; }
        .inbox-table .status-badge.answered { background:#dcfce7; color:#166534; }
        .inbox-table .status-badge.expired { background:#f3f4f6; color:#6b7280; }
        .inbox-pagination { display:flex; align-items:center; justify-content:space-between; margin-top:16px; flex-wrap:wrap; gap:12px; }
        .inbox-empty { text-align:center; padding:48px 24px; color:#9ca3af; }

        /* Conversation modal — Messenger / support-chat style */
        /* Match products_management.php modal overlay (no backdrop blur) */
        #modal-conversation.modal-overlay {
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(0, 0, 0, 0.5);
        }
        #modal-conversation .chat-modal-shell {
            width: 100%;
            max-width: min(420px, calc(100vw - 32px));
            max-height: min(85vh, 760px);
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        #modal-conversation .chat-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px;
            flex-shrink: 0;
            border-bottom: none;
            background: linear-gradient(135deg, #00232b, #1a5a6f);
        }
        #modal-conversation .chat-modal-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            flex: 1;
        }
        #modal-conversation .chat-modal-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #001018 0%, #00232b 100%);
            box-shadow: 0 4px 14px rgba(0, 35, 43, 0.35);
            letter-spacing: 0.02em;
        }
        #modal-conversation .chat-modal-header-text { min-width: 0; }
        #modal-conversation .chat-modal-title {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.25;
            letter-spacing: -0.02em;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #modal-conversation .chat-modal-email {
            display: block;
            margin-top: 2px;
            font-size: 13px;
            font-weight: 500;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
        }
        #modal-conversation .chat-modal-email:hover { color: #ffffff; text-decoration: underline; }
        #modal-conversation .chat-modal-email-muted {
            display: block;
            margin-top: 2px;
            font-size: 13px;
            color: rgba(255,255,255,0.72);
            font-style: italic;
        }
        #modal-conversation .chat-modal-live {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            margin-top: 8px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(34, 197, 94, 0.16);
            color: #dcfce7;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        #modal-conversation .chat-modal-live::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #22c55e;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.14);
        }
        #modal-conversation .chat-modal-close {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 12px;
            background: transparent;
            color: rgba(255,255,255,0.72);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s, color 0.15s;
        }
        #modal-conversation .chat-modal-close:hover {
            background: rgba(255,255,255,0.08);
            color: #ffffff;
        }
        #modal-conversation .chat-modal-close svg { display: block; }
        #modal-conversation .chat-modal-messages {
            flex: 1;
            min-height: 260px;
            max-height: min(50vh, 460px);
            overflow-y: auto;
            overflow-x: hidden;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            padding: 16px;
            background: linear-gradient(to bottom, white, #f8fafb);
        }
        #modal-conversation .chat-modal-messages::-webkit-scrollbar { width: 8px; }
        #modal-conversation .chat-modal-messages::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 8px;
        }
        #modal-conversation .chat-loading,
        #modal-conversation .chat-error {
            text-align: center;
            padding: 48px 24px;
            font-size: 14px;
            color: #64748b;
        }
        #modal-conversation .chat-error { color: #dc2626; }
        #modal-conversation .chat-date-sep {
            display: flex;
            justify-content: center;
            margin: 16px 0 12px;
        }
        #modal-conversation .chat-date-sep span {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            background: rgba(255, 255, 255, 0.85);
            padding: 5px 14px;
            border-radius: 999px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        }
        #modal-conversation .chat-msg-row {
            display: flex;
            margin-bottom: 8px;
        }
        #modal-conversation .chat-msg-row.chat-msg-group-gap { margin-top: 16px; }
        #modal-conversation .chat-msg-row.customer { justify-content: flex-start; }
        #modal-conversation .chat-msg-row.admin { justify-content: flex-end; }
        #modal-conversation .chat-msg-stack {
            max-width: 85%;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        #modal-conversation .chat-msg-row.customer .chat-msg-stack { align-items: flex-start; }
        #modal-conversation .chat-msg-row.admin .chat-msg-stack { align-items: flex-end; }
        #modal-conversation .chat-bubble {
            font-size: 14px;
            line-height: 1.55;
            padding: 11px 16px;
            border-radius: 14px;
            word-wrap: break-word;
            overflow-wrap: break-word;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        #modal-conversation .chat-msg-row.customer .chat-bubble {
            background: #f0f0f0;
            color: #333;
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border-bottom-left-radius: 4px;
        }
        #modal-conversation .chat-msg-row.admin .chat-bubble {
            background: #53C5E0;
            color: #fff;
            border: none;
            box-shadow: 0 1px 3px rgba(83,197,224,0.25);
            border-bottom-right-radius: 4px;
        }
        #modal-conversation .chat-msg-row .chat-bubble:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
        }
        #modal-conversation .chat-msg-row.admin .chat-bubble:hover {
            box-shadow: 0 6px 20px rgba(0, 35, 43, 0.35);
        }
        #modal-conversation .chat-meta {
            font-size: 11px;
            line-height: 1.3;
            color: #94a3b8;
            margin-top: 5px;
            padding: 0 6px;
            letter-spacing: 0.01em;
        }
        #modal-conversation .chat-msg-row.admin .chat-meta { text-align: right; }
        #modal-conversation .chat-delivered { font-weight: 500; color: #cbd5e1; }
        #modal-conversation .chat-typing-row {
            flex-shrink: 0;
            min-height: 22px;
            padding: 0 20px 6px;
            font-size: 12px;
            font-weight: 500;
            color: #64748b;
            font-style: italic;
        }
        #modal-conversation .chat-input-area {
            flex-shrink: 0;
            padding: 14px 16px 16px;
            border-top: 1px solid #e5e5e5;
            background: #fff;
        }
        #modal-conversation .chat-input-row {
            display: flex;
            align-items: stretch;
            gap: 10px;
        }
        #modal-conversation .chat-input-field {
            flex: 1;
            min-width: 0;
            min-height: 48px;
            max-height: 140px;
            padding: 12px 14px;
            border: 2px solid #e5e5e5;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            line-height: 1.45;
            color: #0f172a;
            background: #fff;
            resize: none;
            outline: none;
            transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
        }
        #modal-conversation .chat-input-field::placeholder { color: #94a3b8; }
        #modal-conversation .chat-input-field:focus {
            border-color: #53C5E0;
            background: #f8fcfd;
            box-shadow: 0 0 0 3px rgba(83,197,224,0.15);
        }
        #modal-conversation .chat-send-btn {
            flex-shrink: 0;
            min-width: 90px;
            padding: 0 18px;
            min-height: 48px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #53C5E0, #32a1c4);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: transform 0.12s, box-shadow 0.15s, opacity 0.15s, background 0.15s;
            box-shadow: 0 2px 8px rgba(83,197,224,0.2);
        }
        #modal-conversation .chat-send-btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(83,197,224,0.4);
        }
        #modal-conversation .chat-send-btn:active:not(:disabled) { transform: translateY(0); }
        #modal-conversation .chat-send-btn:disabled {
            opacity: 0.42;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }
        #modal-conversation .chat-input-hint {
            margin: 8px 0 0;
            font-size: 11px;
            color: #94a3b8;
        }
        @media (max-width: 480px) {
            #modal-conversation.modal-overlay { padding: 0; align-items: flex-end; }
            #modal-conversation .chat-modal-shell {
                max-width: 100%;
                max-height: 92vh;
                border-radius: 16px 16px 0 0;
            }
            #modal-conversation .chat-msg-stack { max-width: 85%; }
        }
        .status-pending { background:#fef3c7 !important; color:#92400e !important; }
        .status-answered { background:#dcfce7 !important; color:#166534 !important; }
        .status-expired { background:#f3f4f6 !important; color:#6b7280 !important; }

        /* Match products_management.php — toolbar, filter, table, pagination */
        [x-cloak] { display: none !important; }
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
        .toolbar-btn.active { border-color: #00232b; color: #00232b; background: #e8f1f3; }
        .toolbar-btn svg { flex-shrink: 0; }
        .filter-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 320px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 200;
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
            color: #00232b;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        .filter-reset-link:hover { text-decoration: underline; }
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
        .filter-select:focus { outline: none; border-color: #00232b; }
        .filter-search-wrap { position: relative; }
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
        .filter-search-input:focus { outline: none; border-color: #00232b; }
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
        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: #00232b;
            color: #fff;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 700;
        }
        .orders-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: auto; }
        .orders-table th { padding: 12px 16px; font-size: 13px; font-weight: 600; color: #6b7280; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .orders-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #374151; }
        .orders-table tbody tr { cursor: pointer; transition: background 0.1s; }
        .orders-table tbody tr:hover { background: #f9fafb; }
        .orders-table tbody tr:last-child td { border-bottom: none; }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 12px;
            min-width: 80px;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-action.blue { color: #00232b; border-color: #00232b; }
        .btn-action.blue:hover { background: #00232b; color: white; }
        /* Add Response — same accent as active tab */
        .main-content header .btn-primary#btn-add-faq {
            background: #00232b;
            color: #fff;
            border: none;
        }
        .main-content header .btn-primary#btn-add-faq:hover {
            background: #00151a;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 35, 43, 0.25);
        }
        #btn-add-faq .pf-add-mobile { display: none; }
        @media (max-width: 768px) {
            #btn-add-faq .pf-add-wide { display: none; }
            #btn-add-faq .pf-add-mobile { display: inline; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <header>
            <h1 class="page-title">Support Chat <span class="pf-mobile-title-extra">Management</span></h1>
            <button id="btn-add-faq" class="btn-primary" style="<?php echo $active_tab === 'inquiries' ? 'display:none;' : ''; ?>"><span class="pf-add-wide">+ Add Response</span><span class="pf-add-mobile">Add</span></button>
        </header>

        <main>
            <?php if ($success): ?>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="cb-tabs">
                <a href="?tab=responses" class="cb-tab <?php echo $active_tab === 'responses' ? 'active' : ''; ?>">Responses</a>
                <a href="?tab=inquiries" class="cb-tab <?php echo $active_tab === 'inquiries' ? 'active' : ''; ?>">
                    Support inbox
                    <?php if ($inq_pending > 0): ?>
                        <span class="tab-badge pending"><?php echo $inq_pending; ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- ═══════ TAB 1: RESPONSES ═══════ -->
            <div class="cb-tab-content <?php echo $active_tab === 'responses' ? 'active' : ''; ?>">
                <script>
                function printflowInitFaqPage() {
                    const btnAdd = document.getElementById('btn-add-faq');
                    if (!btnAdd || btnAdd._pf_bound) return;
                    btnAdd._pf_bound = true;
                    btnAdd.addEventListener('click', () => { document.getElementById('modal-add').classList.add('open'); });
                    ['modal-add', 'modal-edit'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) {
                            el.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });
                        }
                    });
                }
                if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', printflowInitFaqPage); }
                else { printflowInitFaqPage(); }
                document.addEventListener('printflow:page-init', printflowInitFaqPage);

                function openEdit(id, question, answer, status) {
                    document.getElementById('edit-faq-id').value = id;
                    document.getElementById('edit-question').value = question;
                    document.getElementById('edit-answer').value = answer;
                    document.getElementById('edit-status').value = status;
                    document.getElementById('modal-edit').classList.add('open');
                }
                </script>
                <!-- KPI Row -->
                <div class="kpi-row">
                    <div class="kpi-card indigo">
                        <div class="kpi-label">Total Responses</div>
                        <div class="kpi-value"><?php echo $stat_total; ?></div>
                        <div class="kpi-sub">All entries</div>
                    </div>
                    <div class="kpi-card emerald">
                        <div class="kpi-label">Active / Public</div>
                        <div class="kpi-value"><?php echo $stat_active; ?></div>
                        <div class="kpi-sub">Shown to customers</div>
                    </div>
                </div>

                <!-- FAQ List -->
                <?php if (empty($faq_paginated)): ?>
                    <div class="card" style="text-align:center;padding:48px 24px;color:#9ca3af;">
                        <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin:0 auto 12px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p style="font-size:15px;font-weight:600;margin-bottom:4px;">No responses yet</p>
                        <p style="font-size:13px;">Add your first quick response to help customers get answers quickly.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($faq_paginated as $faq): ?>
                        <div class="faq-item">
                            <div style="flex:1;">
                                <div class="faq-question"><?php echo htmlspecialchars($faq['question']); ?></div>
                                <div class="faq-answer"><?php echo nl2br(htmlspecialchars($faq['answer'])); ?></div>
                                <div class="faq-meta">
                                    <span class="faq-badge <?php echo $faq['status'] === 'Activated' ? 'active' : 'inactive'; ?>">
                                        <?php echo $faq['status'] === 'Activated' ? 'Public' : 'Hidden'; ?>
                                    </span>
                                    <span style="font-size:12px;color:#9ca3af;">Updated <?php echo format_date($faq['updated_at']); ?></span>
                                </div>
                            </div>
                            <div class="faq-actions">
                                <button class="btn-edit" onclick="openEdit(<?php echo $faq['faq_id']; ?>, <?php echo htmlspecialchars(json_encode($faq['question'])); ?>, <?php echo htmlspecialchars(json_encode($faq['answer'])); ?>, '<?php echo $faq['status']; ?>')">Edit</button>
                                <form method="POST" onsubmit="return confirm('Delete this response?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="faq_id" value="<?php echo $faq['faq_id']; ?>">
                                    <button type="submit" name="delete_faq" class="btn-del">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div id="faq-pagination" style="margin-top:20px;">
                        <?php echo render_pagination($faq_page, $faq_total_pages, ['tab' => 'responses']); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ═══════ TAB 2: SUPPORT INBOX ═══════ -->
            <div class="cb-tab-content <?php echo $active_tab === 'inquiries' ? 'active' : ''; ?>">
                <script>
                /* Always define: inquiries tab markup stays in DOM (hidden on Responses); Alpine.initTree needs inqFilterPanel. */
                function inqFilterPanel() {
                    return {
                        filterOpen: false,
                        get hasActiveFilters() {
                            var f = document.getElementById('inq_fp_filter');
                            var s = document.getElementById('inq_fp_search');
                            var fv = f ? f.value : 'all';
                            var sv = s ? (s.value || '').trim() : '';
                            return fv !== 'all' || sv.length > 0;
                        }
                    };
                }
                function buildInqFilterURL(page) {
                    var p = new URLSearchParams();
                    p.set('tab', 'inquiries');
                    var ff = document.getElementById('inq_fp_filter');
                    p.set('filter', ff ? ff.value : 'all');
                    var si = document.getElementById('inq_fp_search');
                    var q = si ? (si.value || '').trim() : '';
                    if (q) p.set('search', q);
                    if (page && page > 1) p.set('page', String(page));
                    return '?' + p.toString();
                }
                function inqNavigateFilters(page) { window.location.href = buildInqFilterURL(page || 1); }
                function inqResetAllFilters() { window.location.href = '?tab=inquiries'; }
                function inqResetField(which) {
                    if (which === 'filter') { var el = document.getElementById('inq_fp_filter'); if (el) el.value = 'all'; }
                    if (which === 'search') { var el2 = document.getElementById('inq_fp_search'); if (el2) el2.value = ''; }
                    inqNavigateFilters(1);
                }
                window.inqFilterPanel = inqFilterPanel;
                </script>
                <!-- KPI Row -->
                <div class="kpi-row" style="grid-template-columns:repeat(3,1fr);">
                    <div class="kpi-card amber">
                        <div class="kpi-label">Pending</div>
                        <div class="kpi-value"><?php echo $inq_pending; ?></div>
                        <div class="kpi-sub">Awaiting reply</div>
                    </div>
                    <div class="kpi-card emerald">
                        <div class="kpi-label">Answered</div>
                        <div class="kpi-value"><?php echo $inq_answered; ?></div>
                        <div class="kpi-sub">Replied</div>
                    </div>
                    <div class="kpi-card indigo">
                        <div class="kpi-label">Total conversations</div>
                        <div class="kpi-value"><?php echo $inq_total; ?></div>
                        <div class="kpi-sub">All time</div>
                    </div>
                </div>

                <?php
                $inq_filter_badge = ($inq_filter !== 'all' ? 1 : 0) + ($inq_search !== '' ? 1 : 0);
                $inq_pagination_params = ['tab' => 'inquiries', 'filter' => $inq_filter];
                if ($inq_search !== '') {
                    $inq_pagination_params['search'] = $inq_search;
                }
                ?>
                <div class="card">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px;" x-data="inqFilterPanel()">
                        <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Support inbox</h3>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div style="position:relative;">
                                <button type="button" class="toolbar-btn" :class="{active: filterOpen || hasActiveFilters}" @click="filterOpen = !filterOpen" style="height:38px;">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                    </svg>
                                    Filter
                                    <span id="inqFilterBadgeContainer">
                                        <?php if ($inq_filter_badge > 0): ?>
                                        <span class="filter-badge"><?php echo $inq_filter_badge; ?></span>
                                        <?php endif; ?>
                                    </span>
                                </button>
                                <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                    <div class="filter-panel-header">Filter</div>
                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-section-label">Status</span>
                                            <button type="button" class="filter-reset-link" onclick="inqResetField('filter')">Reset</button>
                                        </div>
                                        <select id="inq_fp_filter" class="filter-select">
                                            <option value="all" <?php echo $inq_filter === 'all' ? 'selected' : ''; ?>>All conversations</option>
                                            <option value="pending" <?php echo $inq_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="answered" <?php echo $inq_filter === 'answered' ? 'selected' : ''; ?>>Answered</option>
                                            <option value="archived" <?php echo $inq_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                        </select>
                                    </div>
                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-section-label">Keyword search</span>
                                            <button type="button" class="filter-reset-link" onclick="inqResetField('search')">Reset</button>
                                        </div>
                                        <div class="filter-search-wrap">
                                            <input type="text" id="inq_fp_search" class="filter-search-input" placeholder="Search by name or message..." value="<?php echo htmlspecialchars($inq_search); ?>">
                                        </div>
                                    </div>
                                    <div class="filter-actions">
                                        <button type="button" class="filter-btn-reset" style="width:100%;" onclick="inqResetAllFilters()">Reset all filters</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="inqTableContainer">
                        <div class="overflow-x-auto">
                            <table class="orders-table" id="inq-table">
                                <thead>
                                    <tr>
                                        <th>Customer Name</th>
                                        <th>Last Message</th>
                                        <th>Status</th>
                                        <th>Last activity</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="inq-tbody">
<?php
$inq_sc = ['pending'=>'pending','answered'=>'answered','expired'=>'expired'];
$inq_sl = ['pending'=>'Pending','answered'=>'Answered','expired'=>'Expired (24h inactive)'];
foreach ($inq_conversations as $c):
    $name = $c['customer_id'] ? (trim($c['display_name']??'') ?: trim($c['customer_name']??'') ?: 'Guest') : ('Guest #'.$c['id']);
    $preview = $c['last_message_preview'] ?? '';
    if (mb_strlen($preview) > 50) $preview = mb_substr($preview, 0, 50) . '...';
    $sc = $inq_sc[$c['status']] ?? '';
    $sl = $inq_sl[$c['status']] ?? $c['status'];
    $date = $c['last_activity_at'] ? date('M j, Y g:i A', strtotime($c['last_activity_at'])) : '';
    $st_bg = match($c['status'] ?? '') {
        'pending' => 'background:#fef3c7;color:#92400e;',
        'answered' => 'background:#dcfce7;color:#166534;',
        'expired' => 'background:#f3f4f6;color:#6b7280;',
        default => 'background:#f3f4f6;color:#374151;'
    };
?>
                                    <tr class="inbox-row" data-id="<?php echo (int)$c['id']; ?>">
                                        <td style="font-weight:500;color:#1f2937;"><?php echo htmlspecialchars($name); ?></td>
                                        <td style="max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($preview); ?></td>
                                        <td>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $st_bg; ?>"><?php echo htmlspecialchars($sl); ?></span>
                                        </td>
                                        <td style="white-space:nowrap;color:#6b7280;font-size:13px;"><?php echo htmlspecialchars($date); ?></td>
                                        <td style="text-align:right;white-space:nowrap;" onclick="event.stopPropagation();">
                                            <button type="button" class="btn-action blue btn-open" data-id="<?php echo (int)$c['id']; ?>">View</button>
                                        </td>
                                    </tr>
<?php endforeach; ?>
<?php if (empty($inq_conversations)): ?>
                                    <tr>
                                        <td colspan="5" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">
                                            <?php echo $inq_search ? 'No conversations match your search.' : 'No conversations yet. Customer messages from support chat will appear here.'; ?>
                                        </td>
                                    </tr>
<?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="inq-pagination">
                            <?php echo render_pagination($inq_page, $inq_total_pages, $inq_pagination_params); ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Conversation Modal (Messenger / support-chat style) -->
<div id="modal-conversation" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-conv-name">
    <div class="chat-modal-shell">
        <header class="chat-modal-header">
            <div class="chat-modal-header-left">
                <div class="chat-modal-avatar" id="modal-conv-avatar" aria-hidden="true">?</div>
                <div class="chat-modal-header-text">
                    <h2 id="modal-conv-name" class="chat-modal-title">Conversation</h2>
                    <span class="chat-modal-live" id="modal-conv-live">Online</span>
                    <a href="#" id="modal-conv-email" class="chat-modal-email" style="display:none;"></a>
                    <span id="modal-conv-email-none" class="chat-modal-email-muted" style="display:none;">No email on file</span>
                </div>
            </div>
            <button type="button" class="chat-modal-close modal-close" aria-label="Close conversation">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </header>
        <div id="modal-conv-messages" class="chat-modal-messages" role="log" aria-live="polite" aria-relevant="additions">
            <!-- Messages loaded via JS -->
        </div>
        <div id="modal-conv-typing" class="chat-typing-row" aria-live="polite"></div>
        <footer class="chat-input-area">
            <div class="chat-input-row">
                <label for="modal-reply-input" class="sr-only">Message</label>
                <textarea id="modal-reply-input" class="chat-input-field" placeholder="Type a message…" rows="1" maxlength="8000" autocomplete="off"></textarea>
                <button type="button" id="modal-reply-btn" class="chat-send-btn" disabled>Send</button>
            </div>
            <p class="chat-input-hint"><kbd style="font-size:10px;padding:1px 4px;border:1px solid #e2e8f0;border-radius:4px;background:#f8fafc;">Enter</kbd> to send · <kbd style="font-size:10px;padding:1px 4px;border:1px solid #e2e8f0;border-radius:4px;background:#f8fafc;">Shift</kbd>+<kbd style="font-size:10px;padding:1px 4px;border:1px solid #e2e8f0;border-radius:4px;background:#f8fafc;">Enter</kbd> new line</p>
        </footer>
    </div>
</div>

<!-- Add FAQ Modal -->
<div id="modal-add" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-hdr">
            <h2>Add New Response</h2>
            <button onclick="document.getElementById('modal-add').classList.remove('open')">&times;</button>
        </div>
        <div class="modal-bdy">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="create_faq" value="1">
                <div class="f-group">
                    <label>Question *</label>
                    <input type="text" name="question" required placeholder="e.g. What are your operating hours?">
                </div>
                <div class="f-group">
                    <label>Answer *</label>
                    <textarea name="answer" required placeholder="Write the answer here..."></textarea>
                </div>
                <div class="f-group">
                    <label>Visibility</label>
                    <select name="status">
                        <option value="Activated">Public (Active)</option>
                        <option value="Deactivated">Hidden (Inactive)</option>
                    </select>
                </div>
                <div class="modal-ftr">
                    <button type="button" class="btn-cancel" onclick="document.getElementById('modal-add').classList.remove('open')">Cancel</button>
                    <button type="submit" class="btn-submit">Create Response</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit FAQ Modal -->
<div id="modal-edit" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-hdr">
            <h2>Edit Response</h2>
            <button onclick="document.getElementById('modal-edit').classList.remove('open')">&times;</button>
        </div>
        <div class="modal-bdy">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="update_faq" value="1">
                <input type="hidden" name="faq_id" id="edit-faq-id">
                <div class="f-group">
                    <label>Question *</label>
                    <input type="text" name="question" id="edit-question" required>
                </div>
                <div class="f-group">
                    <label>Answer *</label>
                    <textarea name="answer" id="edit-answer" required></textarea>
                </div>
                <div class="f-group">
                    <label>Visibility</label>
                    <select name="status" id="edit-status">
                        <option value="Activated">Public (Active)</option>
                        <option value="Deactivated">Hidden (Inactive)</option>
                    </select>
                </div>
                <div class="modal-ftr">
                    <button type="button" class="btn-cancel" onclick="document.getElementById('modal-edit').classList.remove('open')">Cancel</button>
                    <button type="submit" class="btn-submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* Inbox modal + filters: always load (inquiries markup stays in DOM on Responses tab; Turbo needs openModal). */
function inqFilterPanel() {
    return {
        filterOpen: false,
        get hasActiveFilters() {
            var f = document.getElementById('inq_fp_filter');
            var s = document.getElementById('inq_fp_search');
            var fv = f ? f.value : 'all';
            var sv = s ? (s.value || '').trim() : '';
            return fv !== 'all' || sv.length > 0;
        }
    };
}
function buildInqFilterURL(page) {
    var p = new URLSearchParams();
    p.set('tab', 'inquiries');
    var ff = document.getElementById('inq_fp_filter');
    p.set('filter', ff ? ff.value : 'all');
    var si = document.getElementById('inq_fp_search');
    var q = si ? (si.value || '').trim() : '';
    if (q) p.set('search', q);
    if (page && page > 1) p.set('page', String(page));
    return '?' + p.toString();
}
function inqNavigateFilters(page) {
    window.location.href = buildInqFilterURL(page || 1);
}
function inqResetAllFilters() {
    window.location.href = '?tab=inquiries';
}
function inqResetField(which) {
    if (which === 'filter') {
        var el = document.getElementById('inq_fp_filter');
        if (el) el.value = 'all';
    }
    if (which === 'search') {
        var el2 = document.getElementById('inq_fp_search');
        if (el2) el2.value = '';
    }
    inqNavigateFilters(1);
}
var inqSearchTimer = null;
function printflowInitFaqChatbotPageFilters() {
    if (!document.getElementById('inq_fp_filter') && !document.getElementById('inq-tbody')) return;
    var sel = document.getElementById('inq_fp_filter');
    if (sel && !sel._pf_bound) {
        sel._pf_bound = true;
        sel.addEventListener('change', function() { inqNavigateFilters(1); });
    }
    var inp = document.getElementById('inq_fp_search');
    if (inp && !inp._pf_bound) {
        inp._pf_bound = true;
        inp.addEventListener('input', function() {
            clearTimeout(inqSearchTimer);
            inqSearchTimer = setTimeout(function() { inqNavigateFilters(1); }, 500);
        });
    }
}
function initInbox() {
    var API = <?php echo json_encode($inq_api_url); ?>;
    var modal = document.getElementById('modal-conversation');
    var modalName = document.getElementById('modal-conv-name');
    var modalAvatar = document.getElementById('modal-conv-avatar');
    var modalEmail = document.getElementById('modal-conv-email');
    var modalEmailNone = document.getElementById('modal-conv-email-none');
    var modalMessages = document.getElementById('modal-conv-messages');
    var modalTyping = document.getElementById('modal-conv-typing');
    var modalReplyInput = document.getElementById('modal-reply-input');
    var modalReplyBtn = document.getElementById('modal-reply-btn');
    var tableBody = document.getElementById('inq-tbody');
    if (!modal) return;
    if (!tableBody) return;

    var loadedMessages = [];
    var conversationPollTimer = null;
    var conversationPollBusy = false;

    function escapeHtml(t) {
        var d = document.createElement('div');
        d.textContent = t || '';
        return d.innerHTML;
    }
    function initials(name) {
        var n = (name || 'Guest').trim();
        if (!n) return 'G';
        var parts = n.split(/\s+/).filter(Boolean);
        if (parts.length >= 2) {
            return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
        }
        return n.slice(0, 2).toUpperCase();
    }
    function dayKey(iso) {
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
    }
    function formatDateDivider(iso) {
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        var now = new Date();
        var today0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var msg0 = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        if (msg0.getTime() === today0.getTime()) return 'Today';
        var y = new Date(today0);
        y.setDate(y.getDate() - 1);
        if (msg0.getTime() === y.getTime()) return 'Yesterday';
        var opts = { month: 'long', day: 'numeric' };
        if (d.getFullYear() !== now.getFullYear()) opts.year = 'numeric';
        return d.toLocaleDateString(undefined, opts);
    }
    function formatMsgTime(iso) {
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        var now = new Date();
        var today0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var msg0 = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        var diffDays = Math.round((today0 - msg0) / 864e5);
        var t = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
        if (diffDays === 0) return t;
        if (diffDays === 1) return 'Yesterday · ' + t;
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' · ' + t;
    }
    function setHeader(conv) {
        if (modalName) modalName.textContent = conv.customer_name || 'Guest';
        if (modalAvatar) {
            if (conv.profile_picture) {
                modalAvatar.innerHTML = '<img src="' + escapeHtml(conv.profile_picture) + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
            } else {
                modalAvatar.textContent = initials(conv.customer_name);
            }
        }
        var em = (conv.customer_email || '').trim();
        if (em && modalEmail) {
            modalEmail.style.display = 'block';
            modalEmail.href = 'mailto:' + em;
            modalEmail.textContent = em;
            if (modalEmailNone) modalEmailNone.style.display = 'none';
        } else {
            if (modalEmail) modalEmail.style.display = 'none';
            if (modalEmailNone) modalEmailNone.style.display = 'block';
        }
    }
    function updateSendDisabled() {
        if (!modalReplyBtn || !modalReplyInput) return;
        modalReplyBtn.disabled = !modalReplyInput.value.trim();
    }
    function autoResizeReply() {
        if (!modalReplyInput) return;
        modalReplyInput.style.height = 'auto';
        modalReplyInput.style.height = Math.min(modalReplyInput.scrollHeight, 140) + 'px';
    }
    function scrollChatToBottom() {
        if (!modalMessages) return;
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                modalMessages.scrollTop = modalMessages.scrollHeight;
            });
        });
    }
    function renderMessages(msgs) {
        if (!modalMessages) return;
        var parts = [];
        var prevDay = null;
        var prevSender = null;
        for (var i = 0; i < msgs.length; i++) {
            var m = msgs[i];
            var dk = dayKey(m.created_at);
            if (dk && dk !== prevDay) {
                prevDay = dk;
                parts.push('<div class="chat-date-sep" role="presentation"><span>' + escapeHtml(formatDateDivider(m.created_at)) + '</span></div>');
            }
            var isC = m.sender_type === 'customer';
            var senderKey = isC ? 'c' : 'a';
            var groupGap = prevSender !== null && prevSender !== senderKey ? ' chat-msg-group-gap' : '';
            prevSender = senderKey;
            var rowCls = 'chat-msg-row ' + (isC ? 'customer' : 'admin') + groupGap;
            var timeStr = escapeHtml(formatMsgTime(m.created_at));
            var metaInner = isC
                ? timeStr
                : timeStr + ' · <span class="chat-delivered">Delivered</span>';
            parts.push(
                '<div class="' + rowCls + '"><div class="chat-msg-stack"><div class="chat-bubble">' +
                escapeHtml(m.message) +
                '</div><div class="chat-meta">' + metaInner + '</div></div></div>'
            );
        }
        modalMessages.innerHTML = parts.join('');
        scrollChatToBottom();
    }
    function loadedMessageMap() {
        var map = {};
        for (var i = 0; i < loadedMessages.length; i++) {
            map[String(loadedMessages[i].id)] = true;
        }
        return map;
    }
    function stopConversationPolling() {
        if (conversationPollTimer) {
            clearInterval(conversationPollTimer);
            conversationPollTimer = null;
        }
    }
    function syncOpenConversation() {
        if (!modal.classList.contains('open') || conversationPollBusy) return;
        var id = parseInt(modal.dataset.convId || '0', 10);
        if (!id) return;
        conversationPollBusy = true;
        fetch(API + '?id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.conversation) return;
                setHeader(data.conversation);
                var latest = Array.isArray(data.messages) ? data.messages : [];
                var seen = loadedMessageMap();
                var hasNew = false;
                for (var i = 0; i < latest.length; i++) {
                    if (!seen[String(latest[i].id)]) {
                        hasNew = true;
                        break;
                    }
                }
                if (hasNew || latest.length !== loadedMessages.length) {
                    loadedMessages = latest.slice();
                    if (loadedMessages.length === 0) {
                        if (modalMessages) modalMessages.innerHTML = '<div class="chat-loading">No messages yet.</div>';
                    } else {
                        renderMessages(loadedMessages);
                    }
                }
            })
            .catch(function () {})
            .finally(function () {
                conversationPollBusy = false;
            });
    }
    function startConversationPolling() {
        stopConversationPolling();
        conversationPollTimer = setInterval(function () {
            syncOpenConversation();
        }, 3000);
    }
    function openModal(id) {
        if (!modal) return;
        stopConversationPolling();
        loadedMessages = [];
        modal.dataset.convId = id;
        if (modalReplyInput) {
            modalReplyInput.value = '';
            modalReplyInput.style.height = '';
        }
        updateSendDisabled();
        if (modalTyping) modalTyping.textContent = '';
        if (modalName) modalName.textContent = 'Loading…';
        if (modalAvatar) modalAvatar.textContent = '…';
        if (modalEmail) modalEmail.style.display = 'none';
        if (modalEmailNone) modalEmailNone.style.display = 'none';
        if (modalMessages) modalMessages.innerHTML = '<div class="chat-loading">Loading conversation…</div>';
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        fetch(API + '?id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.conversation) {
                    if (modalName) modalName.textContent = 'Couldn’t load';
                    if (modalAvatar) modalAvatar.textContent = '!';
                    if (modalMessages) modalMessages.innerHTML = '<div class="chat-error">Failed to load this conversation.</div>';
                    return;
                }
                var conv = data.conversation;
                var msgs = data.messages || [];
                loadedMessages = msgs.slice();
                setHeader(conv);
                if (msgs.length === 0) {
                    if (modalMessages) modalMessages.innerHTML = '<div class="chat-loading">No messages yet.</div>';
                } else {
                    renderMessages(loadedMessages);
                }
                scrollChatToBottom();
                if (modalReplyInput) {
                    setTimeout(function () {
                        modalReplyInput.focus();
                    }, 100);
                }
                startConversationPolling();
            })
            .catch(function () {
                loadedMessages = [];
                if (modalName) modalName.textContent = 'Error';
                if (modalAvatar) modalAvatar.textContent = '!';
                if (modalMessages) modalMessages.innerHTML = '<div class="chat-error">Network error. Try again.</div>';
            });
    }
    function truncatePreviewText(s, len) {
        s = String(s || '').replace(/\s+/g, ' ').trim();
        if (s.length <= len) return s;
        return s.slice(0, len) + '...';
    }
    function updateInquiryRowAfterSend(convId, previewSource) {
        var row = tableBody.querySelector('tr.inbox-row[data-id="' + convId + '"]');
        if (!row) return;
        var cells = row.querySelectorAll('td');
        if (cells.length < 4) return;
        cells[1].textContent = truncatePreviewText(previewSource, 50);
        var statusSpan = cells[2].querySelector('span');
        if (statusSpan) {
            statusSpan.textContent = 'Answered';
            statusSpan.setAttribute(
                'style',
                'display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#dcfce7;color:#166534;'
            );
        }
        var now = new Date();
        cells[3].textContent = now.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }
    function closeModal() {
        stopConversationPolling();
        modal.classList.remove('open');
        document.body.style.overflow = '';
        if (modalTyping) modalTyping.textContent = '';
    }
    function sendReply() {
        var id = parseInt(modal.dataset.convId, 10);
        var msg = (modalReplyInput ? modalReplyInput.value : '').trim();
        if (!id || !msg) return;
        if (modalReplyBtn) modalReplyBtn.disabled = true;
        if (modalTyping) modalTyping.textContent = 'Sending…';
        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ conversation_id: id, message: msg }),
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (modalTyping) modalTyping.textContent = '';
                if (modalReplyBtn) modalReplyBtn.disabled = false;
                updateSendDisabled();
                if (data.success) {
                    if (data.message) {
                        loadedMessages.push(data.message);
                        renderMessages(loadedMessages);
                    }
                    if (modalReplyInput) modalReplyInput.value = '';
                    autoResizeReply();
                    updateSendDisabled();
                    updateInquiryRowAfterSend(id, msg);
                    if (modalReplyInput) modalReplyInput.focus();
                    syncOpenConversation();
                } else {
                    alert(data.error || 'Failed to send');
                }
            })
            .catch(function () {
                if (modalTyping) modalTyping.textContent = '';
                if (modalReplyBtn) modalReplyBtn.disabled = false;
                updateSendDisabled();
                alert('Network error');
            });
    }

    tableBody.querySelectorAll('.inbox-row').forEach(function (row) {
        if (row._pf_bound) return;
        row._pf_bound = true;
        row.addEventListener('click', function () {
            openModal(parseInt(row.getAttribute('data-id'), 10));
        });
    });
    tableBody.querySelectorAll('.btn-open').forEach(function (btn) {
        if (btn._pf_bound) return;
        btn._pf_bound = true;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            openModal(parseInt(btn.getAttribute('data-id'), 10));
        });
    });

    if (!modal._pf_faqChromeBound) {
        modal._pf_faqChromeBound = true;
        var chatShell = modal.querySelector('.chat-modal-shell');
        if (chatShell) {
            chatShell.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }
        var closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }
    if (modalReplyBtn && !modalReplyBtn._pf_bound) {
        modalReplyBtn._pf_bound = true;
        modalReplyBtn.addEventListener('click', sendReply);
    }
    if (modalReplyInput && !modalReplyInput._pf_bound) {
        modalReplyInput._pf_bound = true;
        modalReplyInput.addEventListener('input', function () {
            updateSendDisabled();
            autoResizeReply();
        });
        modalReplyInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendReply();
            }
        });
    }
    if (window._pf_faqKeydown) {
        document.removeEventListener('keydown', window._pf_faqKeydown);
    }
    window._pf_faqKeydown = function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) {
            closeModal();
        }
    };
    document.addEventListener('keydown', window._pf_faqKeydown);
}

function printflowInitFaqChatbotPage() {
    printflowInitFaqChatbotPageFilters();
    initInbox();
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', printflowInitFaqChatbotPage);
} else {
    printflowInitFaqChatbotPage();
}
document.addEventListener('printflow:page-init', printflowInitFaqChatbotPage);
window.inqFilterPanel = inqFilterPanel;
</script>
</body>
</html>
