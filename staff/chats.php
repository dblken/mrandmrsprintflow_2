<?php
/**
 * Staff Chat Dashboard - Professional Enterprise UI (Fixed)
 * High-end communication interface for staff members.
 */
require_once __DIR__ . '/../includes/auth.php'; // Test

require_once __DIR__ . '/../includes/functions.php';
// Load environment config first so BASE_URL/BASE_PATH are correct for production
if (!defined('BASE_URL') && file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
if (!defined('BASE_URL')) define('BASE_URL', '');

require_role(['Staff', 'Admin', 'Manager']);
printflow_require_staff_module('chats');

$page_title = 'Chats - PrintFlow';
$current_user = get_logged_in_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/assets/css/output.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/assets/css/bootstrap-icons.min.css">
    <?php
        $__pf_chat_actions_css = __DIR__ . '/../public/assets/css/chat_actions_fix.css';
        $__pf_chat_actions_ver = is_file($__pf_chat_actions_css) ? (string) filemtime($__pf_chat_actions_css) : '1';
    ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/assets/css/chat_actions_fix.css?v=<?php echo $__pf_chat_actions_ver; ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/assets/css/chat_http.css?v=<?php echo filemtime(__DIR__ . '/../public/assets/css/chat_http.css'); ?>">
    
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .hidden { display: none !important; }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        /* Full View Chat App - No White Spaces */
        body, html { height: 100% !important; margin: 0 !important; padding: 0 !important; overflow: hidden !important; background: #fff !important; }
        .dashboard-container { height: 100% !important; min-height: 100% !important; }
        .main-content { padding: 0 !important; height: 100% !important; margin: 0 0 0 var(--sidebar-w-expanded) !important; overflow: hidden !important; display: flex !important; flex-direction: column !important; }
        body.sidebar-collapsed .main-content { margin-left: var(--sidebar-w-collapsed) !important; }
        main.content-area, .content-area, main { padding: 0 !important; height: 100% !important; margin: 0 !important; display: flex !important; flex-direction: column !important; flex: 1 !important; }

        .chat-app { 
            display: grid; grid-template-columns: 350px 1fr; gap: 0; 
            height: 100%; width: 100%; border-radius: 0; overflow: hidden; 
            border: none; background: #fff; box-shadow: none;
            position: relative; flex: 1;
        }

        /* Sidebar / Conv List */
        .chat-sidebar { 
            display: flex; flex-direction: column; background: #fafafa; border-right: 1px solid #e2e8f0; 
            height: 100%; min-height: 0;
        }
        .sidebar-top { padding: 1.5rem; border-bottom: 1px solid #f1f5f9; flex-shrink: 0; }
        .sidebar-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 1rem; }
        
        .search-box { position: relative; }
        .search-box input { 
            width: 100%; padding: 0.65rem 1rem 0.65rem 2.5rem; background: #fff; border: 1px solid #e2e8f0; 
            border-radius: 12px; font-size: 0.9rem; transition: all 0.2s;
        }
        .search-box input:focus { border-color: #0a2530; box-shadow: 0 0 0 3px rgba(10,37,48,0.1); outline: none; }
        .search-box svg { position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: #94a3b8; }

        .sidebar-tabs { display: flex; padding: 0 1rem 0.75rem; border-bottom: 1px solid #f1f5f9; gap: 1rem; flex-shrink: 0; margin-top: 0.5rem; }
        .tab-btn { 
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #64748b; 
            cursor: pointer; padding-bottom: 0.5rem; border-bottom: 2px solid transparent; transition: all 0.2s;
        }
        .tab-btn.active { color: #0a2530; border-bottom-color: #0a2530; }

        .conv-scroll { flex: 1; overflow-y: auto; padding: 0.5rem; scroll-behavior: smooth; }
        .conv-scroll::-webkit-scrollbar { width: 5px; }
        .conv-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        
        .conv-card { 
            display: flex; gap: 12px; padding: 12px 16px; border-radius: 16px; margin-bottom: 4px;
            text-decoration: none; color: inherit; transition: all 0.15s; border: 1px solid transparent;
            cursor: pointer;
        }
        .conv-card:hover { background: #f1f5f9; }
        .conv-card.active { background: #fff; border-color: #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }

        .conv-avatar { 
            width: 48px; height: 48px; border-radius: 14px; background: #f1f5f9; display: flex; 
            align-items: center; justify-content: center; font-weight: 700; color: #475569; position: relative; flex-shrink: 0;
        }
        .dot-online { position: absolute; bottom: -2px; right: -2px; width: 14px; height: 14px; background: #22c55e; border-radius: 50%; border: 3px solid #fff; display: none; }
        .dot-online.active { display: block; }
        .dot-online.busy { display: block; background: #f59e0b; }
        
        .conv-info { flex: 1; min-width: 0; overflow: hidden; }
        .conv-name-row { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; min-width: 0; }
        .conv-name { font-weight: 700; font-size: 0.95rem; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .conv-time { font-size: 0.7rem; color: #94a3b8; font-weight: 600; flex-shrink: 0; }
        .conv-sub { font-size: 0.75rem; color: #1e293b; font-weight: 700; text-transform: capitalize; letter-spacing: 0.02em; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .conv-preview { font-size: 0.8rem; color: #64748b; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: flex; align-items: center; gap: 4px; min-width: 0; width: 100%; max-width: 100%; }
        .conv-preview-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; width: 0; flex: 1 1 auto; display: block; max-width: 100%; }

        /* Main Window */
        .chat-window { display: flex; flex-direction: column; background: #fff; overflow: hidden; height: 100%; min-height: 0; position: relative; }
        .window-header { 
            padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 1rem; flex-shrink: 0;
            background: #fff; z-index: 20;
        }
        .window-title-area { flex: 1; min-width: 0; }
        .window-title { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .window-meta { font-size: 0.85rem; color: #1e293b; margin: 0; text-transform: capitalize; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        
        .header-actions { display: flex; gap: 8px; }
        .h-btn { 
            width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; 
            border: 1px solid #e2e8f0; color: #64748b; transition: all 0.2s; cursor: pointer; background: transparent;
        }
        .h-btn:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }

        #messagesArea { flex: 1; overflow-y: auto; padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; background: #f8fafc; min-height: 0; }
        #messagesArea::-webkit-scrollbar { width: 5px; }
        #messagesArea::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }

        /* Bubbles - Full-width rows with justify-content for L/R alignment */
        .bubble-row { display: flex; width: 100%; position: relative; margin-bottom: 8px; }
        .bubble-row.self { flex-direction: row-reverse; gap: 8px; align-items: flex-end; }
        .bubble-row.other { align-items: flex-end; gap: 8px; }
        .bubble-row.system { justify-content: flex-start; } /* Default to left for any remaining system msgs */

        .bubble { 
            padding: 5px 14px; border-radius: 18px; font-size: 0.9rem; font-weight: 500; line-height: 1.2; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); transition: all 0.2s ease;
            display: inline-block; width: auto; max-width: 100%; position: relative;
            word-break: break-word; overflow-wrap: break-word;
        }
        .bubble span {
            display: block; max-width: 100%;
            white-space: normal; word-break: break-word; overflow-wrap: break-word; line-height: 1.2;
        }
        .bubble:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
        .bubble-row.self .bubble { background: #0a2530; color: #fff; border-radius: 18px 18px 4px 18px; }
        .bubble-row.other .bubble { background: #fff; color: #1e293b; border: 1px solid #e2e8f0; border-radius: 18px 18px 18px 4px; }
        .bubble-row.system .bubble { background: #fff; color: #1e293b; border: 1px solid #e2e8f0; border-radius: 18px 18px 18px 4px; font-size: 0.92rem; font-weight: 500; padding: 6px 12px; }

        .bubble-meta { font-size: 0.65rem; color: #94a3b8; font-weight: 700; margin-top: 2px; display: flex; align-items: center; gap: 6px; line-height: 1.1; }
        .bubble-row.self .bubble-meta { justify-content: flex-end; }

        /* Order Update Message Styles */
        .bubble-row.order-update.staff-view { margin: 10px 0; }
        .bubble-row.order-update.staff-view.other { justify-content: flex-start !important; }
        .bubble-row.order-update.staff-view.self { justify-content: flex-end !important; }
        .order-update-bubble.staff {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            width: min(100%, 420px);
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            border: 1px solid #d9e6ee;
            border-radius: 18px 18px 18px 6px;
            padding: 12px;
            position: relative;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .bubble-row.self.order-update.staff-view .order-update-bubble.staff {
            border-radius: 18px 18px 6px 18px;
            background: linear-gradient(180deg, #f3fbff 0%, #e8f7ff 100%);
        }
        .order-update-bubble.staff:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.1);
            border-color: #7dd3d8;
        }
        .order-update-bubble.staff:active {
            transform: translateY(0);
        }
        .order-thumb-wrap {
            width: 58px;
            height: 58px;
            border-radius: 14px;
            overflow: hidden;
            background: #eaf2f7;
            border: 1px solid #d9e6ee;
            flex-shrink: 0;
        }
        .order-thumb {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .order-text {
            flex: 1;
            min-width: 0;
        }
        .order-update-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 9px;
            border-radius: 999px;
            background: #e6f8f7;
            color: #0f766e;
            font-size: 0.62rem;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .order-update-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .order-update-head .order-update-badge { margin-bottom: 0; }
        .order-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.66rem;
            font-weight: 900;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }
        .order-status-pill.tone-pending { background: #fff7ed; color: #c2410c; }
        .order-status-pill.tone-approved { background: #eff6ff; color: #1d4ed8; }
        .order-status-pill.tone-payment { background: #eef2ff; color: #4338ca; }
        .order-status-pill.tone-production { background: #ecfeff; color: #0f766e; }
        .order-status-pill.tone-ready { background: #ecfccb; color: #3f6212; }
        .order-status-pill.tone-complete { background: #dcfce7; color: #166534; }
        .order-status-pill.tone-alert { background: #fef2f2; color: #b91c1c; }
        .order-status-pill.tone-neutral { background: #f1f5f9; color: #475569; }
        .order-title {
            font-size: 0.9rem;
            font-weight: 900;
            color: #0f172a;
            margin-bottom: 4px;
            line-height: 1.2;
        }
        .order-message {
            font-size: 0.8rem;
            color: #475569;
            line-height: 1.45;
            word-break: break-word;
        }
        .order-update-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .order-update-time {
            font-size: 0.68rem;
            color: #94a3b8;
            font-weight: 800;
        }
        .order-update-cta {
            font-size: 0.68rem;
            font-weight: 900;
            color: #0891b2;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        /* --- Messenger Layout --- */
        .msg-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: #e2e8f0; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; font-weight: 800; color: #475569; flex-shrink: 0; }
        
        /* msg-content-col: use GRID for self (right-aligns to max-content, not min-content)
           This prevents the letter-stacking bug that flex align-items:flex-end causes */
        .msg-content-col { position: relative; min-width: 0; max-width: 75%; }
        .bubble-row.self .msg-content-col { display: grid; justify-items: end; width: auto; max-width: 75%; }
        .bubble-row.other .msg-content-col { display: flex; flex-direction: column; align-items: flex-start; }
        
        .msg-sender-info { font-size: 0.72rem; color: #94a3b8; margin-bottom: 4px; padding: 0 4px; font-weight: 600; }
        .role-badge { display: inline-block; padding: 1px 5px; border-radius: 4px; background: #f1f5f9; color: #64748b; font-size: 0.6rem; font-weight: 700; margin-left: 4px; text-transform: uppercase; }
        
        /* Message Grouping */
        .bubble-row.grouped-msg-next { margin-bottom: 2px; }
        .bubble-row.grouped-msg { margin-bottom: 2px; }
        .bubble-row.grouped-msg .msg-avatar { visibility: hidden; }
        .bubble-row.grouped-msg .bubble-meta { display: none; }
        .bubble-row.grouped-msg-next .msg-sender-info { display: none; }
        /* Make grouped bubbles have tighter corner radius for a 'chain' effect */
        .bubble-row.grouped-msg-next.other .bubble { border-radius: 4px 18px 18px 4px; }
        .bubble-row.grouped-msg.other .bubble { border-radius: 18px 18px 4px 4px; }
        .bubble-row.grouped-msg-next.self .bubble { border-radius: 18px 4px 4px 18px; }
        .bubble-row.grouped-msg.self .bubble { border-radius: 18px 18px 4px 18px; }
        
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .reaction-btn { 
            width: 28px; height: 28px; font-size: 1.1rem; border: none; background: transparent; 
            cursor: pointer; transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
            display: flex; align-items: center; justify-content: center;
        }
        .reaction-btn:hover { transform: scale(1.4); }
        
        .reaction-display { 
            position: absolute; bottom: -12px; background: #fff; border: 1px solid #e2e8f0; 
            border-radius: 999px; padding: 2px 6px; font-size: 0.68rem; display: flex; align-items: center; gap: 3px; 
            z-index: 10; box-shadow: 0 4px 10px rgba(0,0,0,0.08); cursor: pointer; white-space: nowrap; transition: all 0.2s;
            min-height: 20px;
        }
        .reaction-display:hover { transform: scale(1.03); background: #f8fafc; box-shadow: 0 6px 15px rgba(0,0,0,0.12); }
        .bubble-row.self .reaction-display { right: 8px; }
        .bubble-row.other .reaction-display { left: 8px; }
        
        /* Fixed Media Sizing */
        .chat-image-wrap { 
            max-width: 280px; 
            max-height: 420px; 
            border-radius: 12px; 
            overflow: hidden; 
            margin-bottom: 4px; 
            cursor: pointer; 
            border: 1px solid #e0e0e0; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            background: #f8fafc;
        }
        .chat-image-wrap img { 
            width: 100%; 
            height: 100%; 
            max-height: 420px;
            object-fit: cover; 
            display: block; 
        }
        .order-card {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 12px;
            border-radius: 16px;
            border: 1px solid #d9e6ee;
            background: #ffffff;
            cursor: pointer;
            transition: 0.2s;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        }
        .order-card:hover {
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .order-img {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 12px;
            background: #f1f5f9;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
        }
        .order-info {
            min-width: 0;
        }
        .order-info strong {
            display: block;
            color: #0f172a;
            font-size: 0.95rem;
            line-height: 1.25;
        }
        .order-info p {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 0.82rem;
            line-height: 1.35;
        }

        /* --- Premium Toast System --- */
        #staff-toast-container {
            position: fixed;
            top: 32px;
            left: 0;
            width: 100%;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            pointer-events: none;
        }
        .staff-toast-item {
            pointer-events: auto;
            min-width: 320px;
            max-width: 420px;
            background: #ffffff !important;
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(15, 37, 48, 0.15);
            border-radius: 20px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 20px 40px rgba(15, 37, 48, 0.15);
            animation: toast-in 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        .staff-toast-item.exit { animation: toast-out 0.3s ease forwards; }
        .toast-icon {
            width: 40px; height: 40px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 1.25rem;
        }
        .toast-content { flex: 1; }
        .toast-title { font-size: 0.95rem; font-weight: 900; color: #0a2530; margin-bottom: 2px; }
        .toast-message { font-size: 0.82rem; color: #64748b; font-weight: 600; line-height: 1.4; }
        .toast-progress { position: absolute; bottom: 0; left: 0; height: 3px; background: rgba(0,0,0,0.03); width: 100%; }
        .toast-progress-bar { height: 100%; width: 0%; transition: width linear; }
        
        .toast-error .toast-icon { background: #fee2e2 !important; color: #ef4444 !important; }
        .toast-error .toast-progress-bar { background: #ef4444 !important; }
        .toast-success .toast-icon { background: #dcfce7 !important; color: #22c55e !important; }
        .toast-success .toast-progress-bar { background: #22c55e !important; }
        .toast-warning .toast-icon { background: #fef3c7 !important; color: #f59e0b !important; }
        .toast-warning .toast-progress-bar { background: #f59e0b !important; }

        @keyframes toast-in {
            from { opacity: 0; transform: translateY(-40px) scale(0.9); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes toast-out {
            from { opacity: 1; transform: translateY(0) scale(1); }
            to { opacity: 0; transform: translateY(-20px) scale(0.95); }
        }
        
        .reply-preview-bubble { 
            background: rgba(0,0,0,0.05); border-left: 3px solid rgba(0,0,0,0.2); border-radius: 4px; padding: 6px 10px; 
            font-size: 0.8rem; margin-bottom: 6px; cursor: pointer; color: inherit; opacity: 0.85; max-height: 40px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; text-decoration: none; 
        }
        .reply-preview-bubble:hover { opacity: 1; }
        
        /* Messenger Style Action Bar & Reaction Picker */
        .bubble-row:hover .msg-action-bar, .bubble-row.has-active-menu .msg-action-bar { opacity: 1; pointer-events: auto; }
        .msg-action-bar {
            opacity: 0; pointer-events: none;
            display: flex; align-items: center; gap: 4px;
            padding: 4px 6px; border-radius: 999px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: opacity 0.15s ease;
            position: absolute; top: 50%; transform: translateY(-50%);
            z-index: 50;
        }
        .bubble-row.other .msg-action-bar { left: calc(100% + 8px); }
        .bubble-row.self .msg-action-bar { right: calc(100% + 8px); flex-direction: row-reverse; }

        .m-action-btn {
            width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; color: #94a3b8; cursor: pointer;
            transition: all 0.2s; font-size: 1rem;
        }
        .m-action-btn:hover { background: #f1f5f9; color: #0a2530; }

        .reaction-picker {
            display: none; position: absolute; bottom: 100%; left: 50%;
            transform: translateX(-50%); background: #ffffff;
            padding: 6px 12px; border-radius: 999px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.15); z-index: 500;
            gap: 6px; border: 1px solid #e2e8f0;
            width: max-content; min-width: auto; pointer-events: auto;
            align-items: center; justify-content: center;
            margin-bottom: 48px; height: 50px;
            white-space: nowrap;
        }
        .reaction-picker.active { display: flex; animation: pickerPop 0.2s cubic-bezier(0.34, 1.56, 0.64, 1); }
        .reaction-btn {
            font-size: 1.6rem; padding: 4px; border-radius: 50%; transition: all 0.2s;
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; border: none; background: transparent; cursor: pointer;
        }
        .reaction-btn:hover { background: #f1f5f9; transform: scale(1.2) translateY(-2px); }

        /* More Actions Menu - Open Downward to avoid overlap */
        .m-more-menu {
            display: none; position: absolute; top: 100%; right: 0;
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 12px; padding: 6px 0; width: 160px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.08); z-index: 400;
            margin-top: 10px;
        }
        .m-more-menu.active { display: block; animation: menuFade 0.2s ease; }
        .m-menu-item {
            padding: 8px 16px; font-size: 0.85rem; font-weight: 700; color: #475569;
            display: flex; align-items: center; gap: 10px; cursor: pointer; transition: all 0.2s;
        }
        .m-menu-item:hover { background: #f1f5f9; color: #0a2530; }
        .m-menu-item i { font-size: 1rem; opacity: 0.7; }
        .m-menu-item.danger { color: #0f172a; }

        /* Character Counter */
        .char-counter {
            font-size: 0.65rem;
            color: #64748b;
            font-weight: 700;
            position: absolute;
            right: 12px;
            bottom: 6px;
            pointer-events: none;
            opacity: 0.8;
        }
        .char-counter.limit-near { color: #f59e0b; }
        .char-counter.limit-reached { color: #ef4444; }

        /* Hide global elements that overlap */
        #floatingChatButton, .floating-chat-trigger, .floating-chat-circle, .chat-floating-button, 
        [id*="floatingChat"], [class*="floating-chat"], .messenger-bubble, .floating-bubble { 
            display: none !important; visibility: hidden !important; opacity: 0 !important; pointer-events: none !important;
        }

        .chat-input {
            background: transparent; border: none; outline: none;
            flex: 1; color: #1e293b; font-size: 0.95rem;
            padding: 10px 0; width: 100%; min-width: 0;
            resize: none; max-height: 120px; line-height: 1.5;
            overflow-y: auto; font-family: inherit;
        }

        @keyframes menuFade { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .pinned-badge {
            position: absolute; top: -10px; right: -10px;
            width: 22px; height: 22px; background: #ef4444;
            color: #fff; border-radius: 50%; display: flex;
            align-items: center; justify-content: center; font-size: 11px;
            border: 2px solid #fff; box-shadow: 0 4px 12px rgba(239,68,68,0.4);
            z-index: 10; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .pinned-badge i { transform: rotate(45deg); }
        .pin-bar-active { background: rgba(14,165,233,0.06) !important; color: #0369a1 !important; }
        @keyframes pickerPop { from { opacity: 0; transform: translateX(-50%) scale(0.8) translateY(10px); } to { opacity: 1; transform: translateX(-50%) scale(1) translateY(0); } }

        .reaction-btn {
            background: none; border: none; font-size: 1.75rem; cursor: pointer;
            transition: transform 0.2s; padding: 4px; line-height: 1;
            display: flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 50%;
        }
        .reaction-btn:hover { transform: scale(1.2); background: #f8fafc; }

        .reaction-display-container {
            margin-top: 2px;
            min-height: 18px;
            display: none;
            z-index: 12;
            position: relative;
        }
        .bubble-row.self .reaction-display-container { align-self: flex-end; margin-right: 10px; }
        .bubble-row.other .reaction-display-container { align-self: flex-start; margin-left: 10px; }
        .reaction-display {
            display: inline-flex; align-items: center; gap: 2px; flex-wrap: nowrap;
            position: relative; z-index: 12;
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 999px; padding: 1px 5px;
            box-shadow: 0 6px 14px rgba(15,23,42,0.18);
            cursor: default; white-space: nowrap; width: max-content; max-width: 100%; min-height: 18px;
            margin-top: 0;
        }
        .reaction-bubble {
            display: inline-flex; align-items: center; gap: 2px;
            background: transparent; border: none;
            border-radius: 999px; padding: 0; font-size: 0.88rem;
            box-shadow: none; color: #0f172a;
            cursor: pointer; transition: all 0.2s;
            line-height: 1;
            min-height: 18px;
        }
        .reaction-bubble:hover { transform: scale(1.03); }
        .reaction-count {
            font-size: 0.62rem; font-weight: 800; color: #475569;
            margin-left: 1px;
        }

        /* Seen Indicators (Messenger Style) */
        .seen-wrapper { display:flex; width:100%; margin-top:0; min-height:12px; align-items:center; }
        .bubble-row.self .seen-wrapper { justify-content: flex-end; }
        .seen-avatar { width: 14px; height: 14px; border-radius: 50%; object-fit: cover; border: 1px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

        /* Grouping */
        .bubble-row.grouped-msg { margin-bottom: 2px !important; }
        .bubble-row.grouped-msg-next .bubble-meta { display: none !important; }
        .bubble-row.grouped-msg-next .msg-avatar { visibility: hidden; }

        .bubble-row.grouped-msg.other .bubble { border-radius: 18px 18px 4px 4px; }
        .bubble-row.grouped-msg-next.other .bubble { border-radius: 4px 18px 18px 4px; }
        .bubble-row.grouped-msg.self .bubble { border-radius: 18px 18px 4px 4px; }
        .bubble-row.grouped-msg-next.self .bubble { border-radius: 18px 4px 4px 18px; }

        /* Input Reply Area */
        #replyPreviewBox { 
            display: none; background: #f8fafc; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9;
            padding: 10px 1.5rem; justify-content: space-between; align-items: center; gap: 10px;
        }
        .reply-content-box { border-left: 3px solid #0f172a; padding-left: 10px; }
        .reply-heading { font-size: 0.75rem; font-weight: 700; color: #64748b; margin-bottom: 2px; }
        .reply-text-preview { font-size: 0.85rem; color: #334155; max-height: 20px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cancel-reply-btn { color: #94a3b8; cursor: pointer; border: none; background: transparent; padding: 4px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .cancel-reply-btn:hover { color: #ef4444; background: #fee2e2; }

        /* Window Footer - Improved "Fixed" Bottom Style */
        .window-footer { 
            padding: 1rem 1.25rem; border-top: 1px solid #f1f5f9; background: #fff; 
            flex-shrink: 0; position: relative; z-index: 10; margin-top: auto;
            width: 100%; max-width: 900px; margin-left: auto; margin-right: auto;
        }
        .chat-input-area { 
            display: flex; align-items: center; gap: 12px;
        }
        .chat-interface-wrapper { height: 100%; display: flex; flex-direction: column; overflow: hidden; }
        .input-bar { 
            flex: 1;
            display: flex; align-items: center; gap: 10px; background: #f1f5f9; border-radius: 16px; 
            padding: 4px 4px 4px 12px; border: 2px solid transparent; transition: all 0.2s;
        }
        .input-bar:focus-within { background: #fff; border-color: #0a2530; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); }
        .input-bar input { flex: 1; background: transparent; border: none; outline: none; padding: 10px 0; font-size: 0.95rem; font-weight: 500; }
        
        .footer-action-btn { 
            width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
            color: #64748b; cursor: pointer; transition: all 0.15s; background: transparent;
        }
        .footer-action-btn:hover { background: rgba(10,37,48,0.05); color: #0a2530; }
        .btn-send { 
            background: #0a2530; color: #fff; border: none; width: 44px; height: 44px; border-radius: 14px; 
            display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;
            flex-shrink: 0;
        }
        .btn-send:hover { opacity: 0.9; transform: scale(1.05); box-shadow: 0 4px 12px rgba(10,37,48,0.2); }
        .btn-send:disabled { background: #cbd5e1; cursor: not-allowed; transform: none; box-shadow: none; }
        .details-main-heading { position: sticky; top: 0; z-index: 2; background: #fff; padding: 0 0 1rem; font-size: 9px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1rem; }
        .details-items { display: flex; flex-direction: column; gap: 1rem; }
        .detail-order-card { background: #fff; border: 1px solid #f1f5f9; border-radius: 20px; padding: 1rem; box-shadow: 0 12px 32px rgba(15,23,42,0.04); }
        .detail-order-top { display: grid; grid-template-columns: 112px minmax(0, 1fr); gap: 1rem; align-items: start; }
        .detail-order-thumb { width: 112px; height: 112px; border-radius: 16px; background: #f8fafc; border: 1px solid #f1f5f9; overflow: hidden; display: flex; align-items: center; justify-content: center; }
        .detail-order-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .detail-order-body { min-width: 0; display: flex; flex-direction: column; gap: 0.9rem; }
        .detail-order-summary { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .detail-order-title { font-size: 1.05rem; font-weight: 900; color: #1e293b; line-height: 1.2; word-break: break-word; }
        .detail-order-meta { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .detail-order-chip { background: #f1f5f9; color: #475569; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.02em; }
        .detail-order-chip.category { background: #ecfeff; color: #0f766e; text-transform: uppercase; }
        .detail-order-price { min-width: 120px; text-align: right; }
        .detail-order-price .pf-spec-key { margin-bottom: 2px; font-size: 9px; }
        .detail-order-price strong { display: block; font-size: 1.05rem; font-weight: 900; color: #06A1A1; line-height: 1.2; word-break: break-word; overflow-wrap:break-word; white-space:normal; }

        /* Responsive */
        .mobile-chat-header { display: none; }
        .mobile-thread-back { display: none; }
        @media (max-width: 1023px) {
            .mobile-chat-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 12px 16px 12px 60px;
                background: #fff;
                border-bottom: 1px solid #f1f5f9;
                min-height: 60px;
                flex-shrink: 0;
                position: relative;
                z-index: 10;
            }
            .mobile-chat-header .header-left {
                display: flex;
                align-items: center;
                gap: 12px;
                min-width: 0;
                flex: 1;
            }
            .mobile-chat-header .page-title {
                margin: 0;
                font-size: 1.1rem;
                font-weight: 700;
                color: #0f172a;
            }
            .main-content { margin-left: 0 !important; }
            .chat-app { grid-template-columns: 1fr; border-radius: 0; height: calc(100dvh - 60px); }
            .chat-sidebar {
                position: relative;
                inset: auto;
                top: auto;
                z-index: 3;
                transform: none;
                transition: none;
                border-right: none;
                height: 100%;
                width: 100%;
                background: #fff;
            }
            .chat-window {
                display: none;
                height: 100%;
                min-height: 0;
                width: 100%;
            }
            .chat-app.mobile-list-view .chat-sidebar { display: flex !important; }
            .chat-app.mobile-list-view .chat-window { display: none !important; }
            .chat-app.mobile-thread-view .chat-sidebar { display: none !important; }
            .chat-app.mobile-thread-view .chat-window { display: flex !important; }
            .mobile-thread-back {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            #welcomeScreen {
                display: none !important;
            }
            .window-header {
                padding: 0.875rem 1rem;
                gap: 0.75rem;
                align-items: center;
            }
            .window-title-area {
                display: none;
            }
            .window-header #activeAvatar {
                width: 38px;
                height: 38px;
                border-radius: 12px;
            }
            .header-actions {
                gap: 6px;
                flex-shrink: 0;
                margin-left: auto;
            }
            .h-btn {
                width: 36px;
                height: 36px;
                border-radius: 12px;
            }
            #messagesArea {
                padding: 1rem 0.85rem 1.25rem;
            }
            #pinnedBar {
                padding: 8px 0.85rem !important;
            }
            .window-footer {
                padding: 0.75rem;
            }
            .msg-content-col {
                max-width: 100% !important;
                overflow: visible;
            }
            .msg-action-bar {
                position: fixed;
                top: 0;
                left: 0;
                transform: translateY(-50%);
                margin: 0;
                opacity: 0;
                pointer-events: none;
                display: none;
                flex-wrap: nowrap;
                z-index: 1300;
                padding: 4px 6px;
                gap: 4px;
                transition: opacity 0.15s ease;
            }
            .bubble-row.has-active-menu .msg-action-bar {
                display: flex;
                opacity: 1;
                pointer-events: auto;
            }
            .reaction-picker {
                position: fixed;
                left: 50%;
                right: auto;
                top: auto;
                bottom: auto;
                transform: translateX(-50%) !important;
                margin: 0;
                padding: 10px 12px;
                gap: 8px;
                height: auto;
                width: min(300px, calc(100vw - 24px));
                max-width: calc(100vw - 24px);
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
                border-radius: 22px;
                -webkit-overflow-scrolling: touch;
                z-index: 1200;
            }
            .bubble-row.self .reaction-picker {
                left: 50%;
                right: auto;
            }
            .m-more-menu {
                position: fixed;
                left: 50%;
                right: auto;
                top: auto;
                bottom: auto;
                transform: translateX(-50%) !important;
                width: min(190px, calc(100vw - 32px));
                max-width: calc(100vw - 32px);
                border-radius: 18px;
                padding: 8px 0;
                margin-top: 0;
                box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
                z-index: 1200;
            }
            .m-menu-item {
                min-height: 44px;
                padding: 10px 16px;
                font-size: 0.9rem;
            }
            .reaction-btn {
                flex: 0 0 auto;
            }
            .conv-preview {
                display: flex;
                align-items: center;
                width: 100%;
                min-width: 0;
                max-width: 100%;
                overflow: hidden;
            }
            .conv-preview-text {
                max-width: 100%;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .reaction-display-container {
                margin-top: 6px;
            }
            .details-modal-overlay {
                padding: 0.75rem;
            }
            .details-modal-panel {
                max-height: calc(100dvh - 1.5rem);
                border-radius: 24px;
                overflow: hidden;
            }
            .details-modal-header {
                padding: 1rem 1.1rem;
                align-items: flex-start;
                gap: 12px;
            }
            .details-modal-content {
                display: flex;
                flex-direction: column;
                grid-template-columns: 1fr;
                overflow-y: auto;
                overflow-x: hidden;
            }
            .details-sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #f1f5f9;
                padding: 1rem;
                overflow: visible;
            }
            .details-main {
                width: 100%;
                padding: 1rem;
                overflow: visible;
            }
            .details-main-heading {
                padding-bottom: 0.75rem;
                margin-bottom: 0.85rem;
                border-bottom: 1px solid #f1f5f9;
            }
            .detail-order-top {
                grid-template-columns: 1fr;
            }
            .detail-order-thumb {
                width: 100%;
                max-width: 240px;
                height: auto;
                aspect-ratio: 1 / 1;
            }
            .detail-order-price {
                min-width: 0;
                width: 100%;
                text-align: left;
            }
            .details-modal-content,
            .details-sidebar,
            .details-main,
            .detail-order-card,
            .detail-order-body,
            .detail-order-summary,
            .pf-spec-grid {
                min-width: 0;
            }
            .gallery-panel {
                position: fixed !important;
                inset: 60px 0 0 0 !important;
                width: auto !important;
                max-width: none !important;
                z-index: 1200 !important;
            }
            .m-toggle { display: flex !important; margin-right: 0.5rem; }
        }

        /* Ensure single-column order details on small mobile (match customer view) */
        @media (max-width: 768px) {
            .details-modal-overlay { padding: .75rem; align-items: flex-end; }
            .details-modal-panel { max-height: calc(100dvh - 1.5rem); border-radius: 24px; overflow: hidden; }
            .details-modal-content { display: flex !important; flex-direction: column !important; grid-template-columns: 1fr !important; flex: 1 1 auto !important; overflow-y: auto !important; -webkit-overflow-scrolling: touch !important; }
            .details-modal-content > .details-sidebar, .details-modal-content > .details-main { min-height: 0; overflow: visible !important; }
            .details-sidebar { width: 100% !important; border-right: none !important; border-bottom: 1px solid #eef2f7 !important; padding: 1rem !important; overflow: visible !important; }
            .details-main { width: 100% !important; padding: 1rem !important; overflow: visible !important; }
            .details-main-heading { padding-bottom: .75rem; margin-bottom: .85rem; border-bottom: 1px solid #f1f5f9; }
            .detail-order-top { grid-template-columns: 1fr !important; }
            .detail-order-thumb { width: 100% !important; max-width: 240px !important; height: auto !important; aspect-ratio: 1 / 1; }
            .detail-order-price { min-width: 0 !important; width: 100% !important; text-align: left !important; }
        }
        /* Modal Explicit States & Premium Layout */
        .details-modal-overlay { display: none !important; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); z-index: 10000; align-items: center; justify-content: center; padding: 1.5rem; backdrop-filter: blur(8px); transition: all 0.3s; }
        .details-modal-overlay.active { display: flex !important; }
        .details-modal-panel { background: #fff; border-radius: 32px; width: min(100%, 1100px); max-height: min(88vh, 920px); overflow: hidden; box-shadow: 0 40px 80px -15px rgba(0, 0, 0, 0.4); position: relative; border: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column; }
        /* Modal font: match app font for cleaner, consistent UI */
        .details-modal-panel, .details-modal-header, .details-main, .details-sidebar {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #0f172a;
        }
        .details-modal-header { padding: 1.25rem 2rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; background: #fff; z-index: 10; flex-shrink: 0; }
        .details-modal-content { display: grid; grid-template-columns: 260px minmax(0, 1fr); flex: 1; overflow: hidden; }
        .details-sidebar { background: #f8fafc; border-right: 1px solid #f1f5f9; padding: 1.25rem; overflow-y: auto; display: flex; flex-direction: column; gap: 0.75rem; padding-bottom: 2.5rem; }
        .details-main { padding: 1.5rem; overflow-y: auto; background: #fff; }
        
        /* High-Density Components */
        .pf-mini-card { background: #fff; border-radius: 20px; padding: 1.25rem; border: 1px solid #eef2f6; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
        .pf-spec-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.5rem; margin-top: 0.75rem; }
        .pf-spec-box { background: #f8fafc; border: 1px solid #f1f5f9; padding: 8px 10px; border-radius: 12px; overflow: hidden; min-width: 0; }
        .pf-spec-key { font-size: 8px; font-weight: 900; color: #94a3b8; text-transform: uppercase; margin-bottom: 3px; letter-spacing: 0.05em; }
        .pf-spec-val { font-size: 10.5px; font-weight: 800; color: #334155; line-height: 1.3; overflow-wrap: break-word; color: #1e293b; }

        /* Media Gallery Panel */
        .gallery-panel { 
            position: absolute; right: 0; top: 0; bottom: 0; width: 320px; 
            background: #fff; border-left: 1px solid #f1f5f9; z-index: 50; 
            display: flex; flex-direction: column; transform: translateX(100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: -10px 0 30px rgba(0,0,0,0.05);
        }
        .gallery-panel.active { transform: translateX(0); }
        .gallery-header { padding: 1.25rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
        .gallery-title { font-size: 0.95rem; font-weight: 800; color: #0f172a; }
        
        .gallery-panel {
            position: absolute; right: 0; top: 0; bottom: 0; width: 340px;
            background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(30px);
            border-left: 1px solid rgba(0,0,0,0.06); z-index: 1000;
            display: none; flex-direction: column; 
            box-shadow: -15px 0 40px rgba(0,0,0,0.12);
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            transform: translateX(100%);
        }
        .gallery-panel.active { display: flex; transform: translateX(0); }
        .gallery-header { padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; background: transparent; }
        .gallery-title { font-weight: 800; font-size: 1.1rem; color: #0f172a; }
        .gallery-tabs { display: flex; padding: 0.75rem 1rem; gap: 8px; border-bottom: 1px solid rgba(0,0,0,0.04); background: transparent; }
        .g-tab { 
            flex: 1; padding: 8px; font-size: 0.75rem; font-weight: 700; text-align: center; 
            border-radius: 12px; cursor: pointer; transition: all 0.2s; color: #64748b; border: 1px solid transparent;
        }
        .g-tab.active { background: #fff; color: #0a2530; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        
        .gallery-content { flex: 1; overflow-y: auto; padding: 1.25rem; }
        .gallery-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; align-content: flex-start; }
        .gallery-item { 
            aspect-ratio: 1; border-radius: 16px; overflow: hidden; background: #f8fafc; cursor: pointer; 
            transition: all 0.25s; position: relative; border: 1px solid rgba(0,0,0,0.04);
        }
        .gallery-item:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.12); border-color: var(--pf-cyan); }
        .gallery-item img, .gallery-item video { width: 100%; height: 100%; object-fit: cover; }
        .gallery-item .vid-icon { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; background: rgba(0,0,0,0.2); }
        .gallery-item .vid-icon svg { width: 32px; height: 32px; fill: #fff; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3)); }

        /* Unified Action Menu */
        .unified-menu { position: relative; }
        .dropdown-menu { 
            position: absolute; right: 0; top: 100%; width: 180px; 
            background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; 
            display: none; flex-direction: column; padding: 0.5rem 0; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1000;
            animation: fadeIn 0.2s ease-out; margin-top: 8px;
        }
        .dropdown-menu.show { display: flex; }
        .dropdown-item { 
            padding: 0.75rem 1.25rem; font-size: 0.9rem; font-weight: 600; color: #334155; 
            cursor: pointer; display: flex; align-items: center; gap: 12px; transition: all 0.2s;
        }
        .dropdown-item:hover { background: #f5f5f5; color: #0d6efd; }
        .dropdown-item i { 
            font-size: 1.1rem; 
            width: 24px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            flex-shrink: 0;
        }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }

        /* Modern Voice Player UI */
        .voice-bubble-player {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 14px;
            border-radius: 20px;
            min-width: 250px;
            margin: 4px 0;
        }
        .bubble-row.self .voice-bubble-player { background: rgba(255,255,255,0.1); color: #fff; }
        .bubble-row.other .voice-bubble-player { background: #f1f5f9; color: #1e293b; }

        .play-pause-btn {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: #0a2530;
            border: none;
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            cursor: pointer;
            transition: transform 0.2s, background 0.2s;
            flex-shrink: 0;
        }
        .bubble-row.self .play-pause-btn { background: #fff; color: #0a2530; }
        .play-pause-btn:hover { transform: scale(1.1); opacity: 0.9; }

        .v-waveform-container {
            flex: 1;
            height: 30px;
            position: relative;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        .v-waveform-canvas {
            width: 100%;
            height: 100%;
            display: block;
        }
        .v-duration {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            min-width: 35px;
            text-align: right;
        }
        .bubble-row.self .v-duration { color: rgba(255,255,255,0.8); }

        @keyframes pulse-rec {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.3); }
        }

        .hidden { display: none !important; }
        #msgInput { border: none !important; background: transparent !important; }
        .bi { font-size: 1.1rem; }
        @keyframes highlightStaffMsg {
            0% { background: rgba(0, 35, 43, 0.1); transform: scale(1.02); }
            100% { background: transparent; transform: scale(1); }
        }
            /* Rich Order Card V2 (Messenger Style) */
        .order-card-v2 {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            width: min(100%, 300px);
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(15,23,42,0.06);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            display: flex;
            flex-direction: column;
            margin: 4px 0;
            text-align: left;
        }
        .order-card-v2:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(15,23,42,0.12);
            border-color: #cbd5e1;
        }
        .oc-header {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fbff;
            border-bottom: 1px solid #f1f5f9;
        }
        .oc-badge {
            font-size: 0.6rem;
            font-weight: 900;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .oc-status {
            font-size: 0.65rem;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 999px;
            text-transform: capitalize;
        }
        .oc-body {
            padding: 12px 14px;
            display: flex;
            gap: 12px;
        }
        .oc-thumb {
            width: 54px;
            height: 54px;
            border-radius: 10px;
            object-fit: cover;
            background: #f1f5f9;
            flex-shrink: 0;
            border: 1px solid #f1f5f9;
        }
        .oc-content {
            flex: 1;
            min-width: 0;
        }
        .oc-title {
            font-size: 0.88rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .oc-desc {
            font-size: 0.78rem;
            color: #475569;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .oc-footer {
            padding: 8px 12px 12px;
        }
        .oc-btn {
            width: 100%;
            padding: 9px;
            background: #0a2530;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 4px 12px rgba(10,37,48,0.15);
        }
        .oc-btn:hover {
            background: #0f172a;
            transform: scale(1.02);
        }
        
        .oc-status.tone-pending { background: #fff7ed; color: #c2410c; }
        .oc-status.tone-approved { background: #eff6ff; color: #1d4ed8; }
        .oc-status.tone-payment { background: #eef2ff; color: #4338ca; }
        .oc-status.tone-production { background: #ecfeff; color: #0f766e; }
        .oc-status.tone-ready { background: #ecfccb; color: #3f6212; }
        .oc-status.tone-complete { background: #dcfce7; color: #166534; }
        .oc-status.tone-alert { background: #fef2f2; color: #b91c1c; }
        .oc-status.tone-neutral { background: #f1f5f9; color: #475569; }
    </style>

</head>
<body class="bg-slate-50" data-turbo="false">

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <div class="main-content">
        <header class="mobile-chat-header">
            <div class="header-left">
                <button type="button" class="h-btn mobile-thread-back" id="mobileBackBtn" onclick="showConversationList()" aria-label="Back to conversations">
                    <i class="bi bi-arrow-left"></i>
                </button>
                <h1 class="page-title" id="mobilePageTitle">Chats</h1>
            </div>
        </header>
        <div class="chat-app mobile-list-view" id="chatApp">
            <!-- Sidebar -->
            <aside class="chat-sidebar" id="sidebar">
                <div class="sidebar-top">
                    <div class="sidebar-title">Conversations</div>
                    <div class="search-box">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z" stroke-width="2.5"/></svg>
                        <input type="text" id="searchInput" placeholder="Search customer or order..." autocomplete="off">
                    </div>
                </div>
                
                <div class="conv-scroll" id="convList">
                    <div class="p-8 text-center text-slate-400">Loading conversations...</div>
                </div>
            </aside>

            <!-- Main Window -->
            <main class="chat-window">
                <div id="welcomeScreen" class="flex-1 flex items-center justify-center text-center p-12 bg-slate-50">
                    <div>
                        <div class="text-6xl mb-4 opacity-50 text-slate-400">
                            <i class="bi bi-chat-left-dots"></i>
                        </div>
                        <h3 class="text-xl font-bold text-slate-700">Inbound Messages</h3>
                        <p class="text-slate-500 max-w-xs mx-auto mt-2">Select a conversation from the sidebar to provide support.</p>
                    </div>
                </div>

                <div id="chatInterface" class="chat-interface-wrapper" style="display:none;">
                    <!-- Header -->
                    <header class="window-header">
                        <div class="conv-avatar cursor-pointer" id="activeAvatar" onclick="openDetails()">?</div>
                        <div class="window-title-area cursor-pointer" onclick="openDetails()">
                            <h3 class="window-title">
                                <span id="activeName">—</span>
                                <span id="partnerStatus" class="inline-block w-2.5 h-2.5 bg-green-500 rounded-full ml-1" style="display:none;" title="Online"></span>
                            </h3>
                            <p class="window-meta" id="activeMeta">—</p>
                        </div>
                        <div class="header-actions">
                             <div class="unified-menu">
                                 <button class="h-btn" onclick="toggleMenu(event)" id="threeDots" title="More Options">
                                     <i class="bi bi-three-dots-vertical"></i>
                                 </button>
                                 <div class="dropdown-menu" id="chatDropdown">
                                     <div class="dropdown-item" onclick="toggleMediaGallery(true)">
                                         <i class="bi bi-images"></i> Shared Media
                                     </div>
                                     <div class="dropdown-item" onclick="openDetails()">
                                         <i class="bi bi-info-circle"></i> Order Details
                                     </div>
                                 </div>
                             </div>
                        </div>
                    </header>

                    <!-- Pinned Messages Bar -->
                    <div id="pinnedBar" style="display:none; position:sticky; top:0; z-index:15; background:rgba(255,255,255,0.95); backdrop-filter:blur(10px); border-bottom:1px solid #f1f5f9; padding:8px 1.5rem; align-items:center; justify-content:space-between; cursor:pointer; transition:all 0.2s;">
                        <div style="display:flex; align-items:center; gap:10px; flex:1; min-width:0;">
                            <i class="bi bi-pin-angle-fill" style="color:#0a2530; font-size:0.9rem;"></i>
                            <span id="pinnedCountText" style="font-size:0.75rem; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">0 pinned messages</span>
                        </div>
                        <i class="bi bi-chevron-right" style="color:#94a3b8; font-size:0.8rem;"></i>
                    </div>

                    <!-- Messages -->
                    <div id="messagesArea"></div>

                    <!-- Shared Media Gallery Panel -->
                    <div id="mediaGallery" class="gallery-panel">
                        <div class="gallery-header">
                            <h4 class="gallery-title">Shared Media</h4>
                            <button onclick="toggleMediaGallery(false)" class="h-btn" style="border:none;">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke-width="2.5"/></svg>
                            </button>
                        </div>
                        <div class="gallery-tabs">
                            <div class="g-tab active" id="gTabImages" onclick="switchGalleryTab('image')">Images</div>
                        </div>
                        <div class="gallery-content" id="galleryContent">
                            <div class="gallery-grid" id="mediaGrid">
                                <!-- Media items here -->
                            </div>
                        </div>
                    </div>

                    <!-- Previews -->
                    <div id="imgPreviewArea" style="display:none; padding: 10px 1.5rem; border-top:1px solid #f1f5f9; gap:10px; background: #fff;"></div>

                    <div id="replyPreviewBox">
                        <div class="reply-content-box overflow-hidden">
                            <div class="reply-heading">Replying to message</div>
                            <div class="reply-text-preview" id="replyPreviewText"></div>
                        </div>
                        <button type="button" class="cancel-reply-btn" onclick="cancelReply()">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke-width="2.5"/></svg>
                        </button>
                    </div>

                    <!-- Input Area Alternative -->
                    <footer class="window-footer">
                        <div class="chat-input-area">
                             <div class="input-bar flex-1" id="inputBarMain" style="position:relative; display:flex; align-items:flex-end; gap:10px;">
                                 <label class="footer-action-btn" title="Send images" style="margin-bottom:6px !important;">
                                      <input type="file" id="mediaInput" accept="image/jpeg,image/png,image/webp" multiple class="hidden">
                                      <i class="bi bi-image"></i>
                                 </label>
                                 <textarea id="msgInput" class="chat-input" placeholder="Type a message..." autocomplete="off" maxlength="2000" rows="1"></textarea>
                                 <span id="charCount" class="char-counter">0/2000</span>
                             </div>

                             <button class="btn-send" id="btnSend" onclick="sendMsg()">
                                 <i class="bi bi-send-fill"></i>
                             </button>
                        </div>
                    </footer>
                </div>
            </main>
        </div>
    </div>
</div>

<!-- Lightbox -->
<div id="staffLightbox" onclick="closeLightbox()" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,0.97);z-index:9000;align-items:center;justify-content:center;padding:2rem;cursor:pointer;">
    <div style="position:relative; max-width:95vw; max-height:95vh;display:flex;flex-direction:column;align-items:center;" onclick="event.stopPropagation()">
        <img id="staffLightboxImg" src="" style="max-width:100%;max-height:80vh;border-radius:1rem;box-shadow:0 0 60px rgba(0,0,0,0.6);display:none;object-fit:contain;">
        <video id="staffLightboxVideo" controls style="max-width:100%;max-height:80vh;border-radius:1rem;box-shadow:0 0 60px rgba(0,0,0,0.6);display:none;background:#000;outline:none;" preload="metadata"></video>
        <div style="display:flex; justify-content:center; gap:1.5rem; margin-top:1.5rem;">
            <a id="staffLightboxDownload" href="" download class="h-btn bg-white" style="width:auto; padding:0 20px; font-weight:700;">Download</a>
            <button onclick="closeLightbox()" class="h-btn bg-white" style="width:auto; padding:0 20px; font-weight:700;">Close</button>
        </div>
    </div>
</div>

<script>
window.PrintFlowChatConfig = <?= json_encode([
    'role' => 'staff',
    'baseUrl' => BASE_URL,
    'csrf' => generate_csrf_token(),
    'initialOrderId' => $_GET['order_id'] ?? null,
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?php echo BASE_URL; ?>/public/assets/js/chat_http.js?v=<?php echo filemtime(__DIR__ . '/../public/assets/js/chat_http.js'); ?>" defer></script></body>
</html>


