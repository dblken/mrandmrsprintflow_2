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
        $__pf_voice_fix_js = __DIR__ . '/../public/assets/js/voice_duration_fix.js';
        $__pf_voice_fix_ver = is_file($__pf_voice_fix_js) ? (string) filemtime($__pf_voice_fix_js) : '1';
    ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/assets/css/chat_actions_fix.css?v=<?php echo $__pf_chat_actions_ver; ?>">
    
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

        /* Call Log Bubbles (Messenger Style) */
        .call-log-bubble {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            border-radius: 22px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: default;
            user-select: none;
            transition: all 0.2s;
            min-width: 180px;
            position: relative;
        }
        .bubble-row.other .call-log-bubble {
            background: #ffffff;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }
        .bubble-row.self .call-log-bubble {
            background: #ffffff;
            color: #0f172a;
            border: 1px solid rgba(0,0,0,0.08);
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }
        .call-log-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.15rem;
        }
        .call-log-icon.missed { background: #fff1f2 !important; color: #e11d48 !important; }
        .call-log-icon.ended { background: #f0fdfa !important; color: #0d9488 !important; }
        
        .call-log-details { display: flex; flex-direction: column; gap: 0px; flex: 1; }
        .call-log-title { font-weight: 800; font-size: 0.92rem; line-height: 1.2; }
        .call-log-status { font-size: 0.75rem; font-weight: 700; opacity: 0.5; line-height: 1.2; }

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

        /* Voice Recording Styles */
        .mic-btn.recording { 
            background: #fee2e2 !important; color: #ef4444 !important; border-color: #fecaca !important;
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.2); animation: pulse-rec-staff 1.5s infinite;
        }
        @keyframes pulse-rec-staff { 0%{box-shadow:0 0 0 0 rgba(239,68,68,.3)} 70%{box-shadow:0 0 0 8px rgba(239,68,68,0)} 100%{box-shadow:0 0 0 0 rgba(239,68,68,0)} }

        .recording-panel {
            flex: 1; display: flex; align-items: center; gap: 10px; background: #fef2f2;
            border: 1px solid #fee2e2; border-radius: 12px; padding: 4px 12px;
        }
        .rec-pulse-dot { width: 8px; height: 8px; background: #ef4444; border-radius: 50%; animation: pulse-dot 1s infinite; }
        @keyframes pulse-dot { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(1.2); } }

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
        .mic-btn {
            width: 40px; height: 40px;
            border-radius: 12px;
            background: #f1f5f9;
            border: none;
            color: #64748b;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 1.1rem;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .mic-btn:hover { background: #e2e8f0; color: #0f172a; }
        .mic-btn.recording { background: #fee2e2; border-color: #fecaca; color: #ef4444; }
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
        
        /* Ready States */
        .call-btns { transition: all 0.2s; }
        .call-btns.pf-not-ready { opacity: 0.3 !important; cursor: not-allowed !important; filter: grayscale(1); pointer-events: none; }
        
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

        .recording-panel {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(239, 68, 68, 0.05);
            border: 1px solid rgba(239, 68, 68, 0.1);
            border-radius: 12px;
            padding: 2px 10px;
            margin: 0 4px;
            overflow: hidden;
        }
        .rec-timer { font-family: 'JetBrains Mono', monospace; font-weight: 800; color: #ef4444; font-size: 0.85rem; min-width: 35px; }
        .rec-pulse { width: 8px; height: 8px; background: #ef4444; border-radius: 50%; animation: pulse-rec 1s infinite; flex-shrink: 0; }
        #recordingCanvas {
            flex: 1;
            height: 30px;
            background: transparent;
        }
        
        #voicePreviewArea {
            display: none;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 6px 12px;
            margin: 0 4px;
            flex: 1;
        }

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
                
                <div class="sidebar-tabs">
                    <div class="tab-btn active" id="tabActive" onclick="switchMainTab(false)">Active</div>
                    <div class="tab-btn" id="tabArchived" onclick="switchMainTab(true)">Archived</div>
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
                        <div class="conv-avatar cursor-pointer" id="activeAvatar" onclick="if(activeId) openDetails(activeId)">?</div>
                        <div class="window-title-area cursor-pointer" onclick="if(activeId) openDetails(activeId)">
                            <h3 class="window-title">
                                <span id="activeName">—</span>
                                <span id="partnerStatus" class="inline-block w-2.5 h-2.5 bg-green-500 rounded-full ml-1" style="display:none;" title="Online"></span>
                            </h3>
                            <p class="window-meta" id="activeMeta">—</p>
                        </div>
                        <div id="pfCallStatus" class="pf-call-status" style="margin-right: 15px; font-size: 0.75rem; font-weight: 700; color: #94a3b8; display: flex; align-items: center; gap: 5px;">
                            <span class="status-dot" style="width: 8px; height: 8px; border-radius: 50%; background: #94a3b8;"></span>
                            <span class="status-text">Connecting...</span>
                        </div>
                        <div class="header-actions">
                             <!-- Call Actions -->
                             <button class="h-btn call-btns" onclick="initiateCall('voice')" title="Voice Call" style="display:none;">
                                 <i class="bi bi-telephone"></i>
                             </button>
                             <button class="h-btn call-btns" onclick="initiateCall('video')" title="Video Call" style="display:none;">
                                 <i class="bi bi-camera-video"></i>
                             </button>

                             <div class="unified-menu">
                                 <button class="h-btn" onclick="toggleMenu(event)" id="threeDots" title="More Options">
                                     <i class="bi bi-three-dots-vertical"></i>
                                 </button>
                                 <div class="dropdown-menu" id="chatDropdown">
                                     <div class="dropdown-item" onclick="toggleMediaGallery(true)">
                                         <i class="bi bi-images"></i> Shared Media
                                     </div>
                                     <div class="dropdown-item" id="archiveLabel" onclick="if(activeId) toggleArchStatus(activeId, !currentArchivedState)">
                                         <i class="bi bi-archive"></i> Archive
                                     </div>
                                     <div class="dropdown-item" onclick="if(activeId) openDetails(activeId)">
                                         <i class="bi bi-info-circle"></i> Order Details
                                     </div>
                                 </div>
                             </div>
                        </div>
                    </header>

                    <!-- Sync Notice -->
                    <div id="archivedNotice" style="display:none; padding:8px; background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:center; font-size:0.75rem; font-weight:700; color:#64748b;">
                        <i class="bi bi-archive-fill mr-1"></i> This conversation is archived
                    </div>

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
                            <div class="g-tab" id="gTabVideos" onclick="switchGalleryTab('video')">Videos</div>
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
                    <button class="mic-btn" id="micBtnMain" title="Hold to Record">
                        <i class="bi bi-mic" id="micIconMain"></i>
                    </button>

                             <div class="input-bar flex-1" id="inputBarMain" style="position:relative; display:flex; align-items:flex-end; gap:10px;">
                                 <label class="footer-action-btn" title="Send Image or Video" style="margin-bottom:6px !important;">
                                      <input type="file" id="mediaInput" accept="image/*,video/mp4,video/webm,video/quicktime" multiple class="hidden">
                                      <i class="bi bi-image"></i>
                                 </label>
                                 <textarea id="msgInput" class="chat-input" placeholder="Type a message..." autocomplete="off" maxlength="500" rows="1"></textarea>
                                 <span id="charCount" class="char-counter">0/500</span>
                             </div>

                             <div class="recording-panel hidden" id="recordStatusMain">
                                 <div class="rec-pulse-dot"></div>
                                 <canvas id="recordingCanvasMain" style="flex:1; height:30px;"></canvas>
                                 <span class="rec-timer" id="timerMain" style="font-family:monospace; font-weight:700; color:#ef4444;">0:00</span>
                             </div>

                             <div id="voicePreviewAreaMain" style="display:none; align-items:center; gap:10px; background:#f1f5f9; border-radius:14px; padding:6px 12px; flex:1;">
                                 <button type="button" class="play-pause-btn" onclick="togglePreviewPlayback()" style="width:32px; height:32px; border-radius:50%; background:#0a2530; color:#fff; border:none; display:flex; align-items:center; justify-content:center;">
                                     <i class="bi bi-play-fill" id="previewPlayIconMain"></i>
                                 </button>
                                 <div class="v-waveform-container" style="flex:1; height:24px;">
                                     <canvas id="previewWaveformCanvasMain" class="v-waveform-canvas" style="width:100%; height:100%;"></canvas>
                                 </div>
                                 <span class="v-duration" id="previewDurationMain" style="font-size:11px; font-weight:700; color:#64748b;">0:00</span>
                                 <button class="footer-action-btn" onclick="cancelRecording()" style="color:#ef4444; border:none; background:transparent;"><i class="bi bi-trash3"></i></button>
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

<!-- Details Modal -->
<div id="detailsModal" class="details-modal-overlay" onclick="closeDetailsModal()">
    <div class="details-modal-panel" onclick="event.stopPropagation()">
        <div class="details-modal-header">
            <div>
                <h2 style="font-size:1.1rem; font-weight:700; color:#1e293b; margin:0;">Customer Order Overview</h2>
                <p style="font-size:9px; font-weight:800; text-transform:uppercase; color:#94a3b8; letter-spacing:0.12em; margin:2px 0 0;">Production Specifications</p>
            </div>
            <button type="button" onclick="closeDetailsModal()" class="h-btn" style="border:none; background:transparent;">
                 <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke-width="2.5"/></svg>
            </button>
        </div>
        <div class="details-modal-content" id="detailsBody">
             <!-- Horizontal Content Grid -->
        </div>
    </div>
</div>

<!-- Global Call System is now initialized via admin_style.php -->
<script src="<?php echo BASE_URL; ?>/public/assets/js/voice_duration_fix.js?v=<?php echo $__pf_voice_fix_ver; ?>" defer></script>
<script>
window.baseUrl = <?= json_encode(BASE_URL); ?>;
const DEFAULT_PROFILE_IMAGE = `${window.baseUrl}/public/assets/uploads/profiles/default.png`;
const PROFILE_IMAGE_ONERROR = `this.onerror=null;this.src='${DEFAULT_PROFILE_IMAGE}'`;
const CURRENT_USER_TYPE = 'staff';
let activeId = null;
let isArchivedView = false;
let currentArchivedState = false;
let partnerAvatarUrl = null;
let lastId = 0;
let pollId = null;
let listId = null;
let uploadFiles = [];
let replyToMessageId = null;
let currentReactions = [];

function resolveAppUrl(path, fallback = '') {
    if (!path || path === 'null' || path === 'undefined') return fallback;
    let value = String(path).trim();
    if (!value) return fallback;
    if (value.startsWith('/')) value = value.substring(1);
    
    // Fix for misplaced uploads/ directory requests
    if (value.startsWith('uploads/products') || value.startsWith('uploads/profiles') || value.startsWith('uploads/services') || value.startsWith('uploads/orders')) {
        value = 'public/assets/' + value;
    }
    
    if (/^(https?:)?\/\//i.test(value) || value.startsWith('data:') || value.startsWith('blob:')) return value;
    if (value.startsWith(window.baseUrl + '/')) return value;
    if (value.startsWith('printflow/')) return '/' + value;
    return `${window.baseUrl}/${value.replace(/^\/+/, '')}`;
}

function resolveProfileUrl(path) {
    if (!path || path === 'null' || path === 'undefined') return DEFAULT_PROFILE_IMAGE;
    const value = String(path).trim();
    if (!value) return DEFAULT_PROFILE_IMAGE;
    if (/^(https?:)?\/\//i.test(value) || value.startsWith('data:') || value.startsWith('blob:')) return value;
    if (value.startsWith(window.baseUrl + '/')) return value;
    if (value.startsWith('/')) return value;
    if (value.startsWith('printflow/')) return '/' + value;
    if (value.startsWith('public/') || value.startsWith('assets/')) {
        return `${window.baseUrl}/${value.replace(/^\/+/, '')}`;
    }
    return `${window.baseUrl}/public/assets/uploads/profiles/${value.replace(/^\/+/, '')}`;
}

function getCanvasContext(id) {
    const canvas = typeof id === 'string' ? document.getElementById(id) : id;
    if (!canvas) return { canvas: null, ctx: null };
    const ctx = typeof canvas.getContext === 'function' ? canvas.getContext('2d') : null;
    return { canvas, ctx };
}

function closeAudioContextSafely(context) {
    if (context && context.state !== 'closed') {
        context.close().catch(() => {});
    }
}

const REACTION_EMOJIS = {
    'like': '👍', 'love': '❤️', 'haha': '😂', 'wow': '😮', 'sad': '😢', 'angry': '😡'
};


// Call system singleton is accessed via window.PFCall

function initCallSystem() {
    if (window.__pfCallInitDone) return;
    if (!window.PFCall || typeof window.PFCall.startCall !== 'function') {
        setTimeout(initCallSystem, 100);
        return;
    }
    window.__pfCallInitDone = true;

    // Ensure activeId is synced for call events
    if (!window.PFCallState) window.PFCallState = {};
    window.PFCallState.activeId = activeId;

    // Real-time status updates
    const pfCallDebug = (...args) => {
        if (!window.PF_CALL_DEBUG || !window.console || typeof console.log !== 'function') return;
        console.log(...args);
    };
    const pfCallWarn = (...args) => {
        if (!window.PF_CALL_DEBUG || !window.console || typeof console.warn !== 'function') return;
        console.warn(...args);
    };

    window.PFCall.socket.on('user-status-change', (data) => {
        pfCallDebug('[PFCall][UI] Status changed:', data);
        // Find the conversation card and update the dot
        const cards = document.querySelectorAll('.conv-card');
        cards.forEach(card => {
            // This is a bit tricky because card doesn't explicitly store userId
            // But we can check if it matches the active chat or re-fetch convs if needed
            // For now, the simplest is to just trigger a re-fetch of convs for real-time feel
            // or we could store userId on the card.
        });
        loadConvs(); // Simple and effective
    });
}

// Enable/Disable call buttons based on connection
window.addEventListener('PFCallConnected', () => {
    if (window.PF_CALL_DEBUG && window.console && typeof console.log === 'function') {
        console.log('[PFCall][UI] Socket connected, enabling call UI');
    }
    document.querySelectorAll('.call-btns').forEach(btn => {
        btn.classList.remove('pf-not-ready');
        btn.disabled = false;
        btn.title = btn.getAttribute('data-orig-title') || btn.title;
    });
});

window.addEventListener('PFCallDisconnected', () => {
    if (typeof window.pfCallWarn === 'function') {
        window.pfCallWarn('[PFCall][UI] Socket disconnected, disabling call UI');
    } else if (typeof console !== 'undefined' && typeof console.warn === 'function') {
        console.warn('[PFCall][UI] Socket disconnected, disabling call UI');
    }
    document.querySelectorAll('.call-btns').forEach(btn => {
        btn.classList.add('pf-not-ready');
        btn.disabled = true;
        if (!btn.getAttribute('data-orig-title')) btn.setAttribute('data-orig-title', btn.title);
        btn.title = 'Reconnecting to call server...';
    });
});

function initiateCall(type) {
    if (!activeId) return;
    // activeId is synced via window.PFCallState
    if (window.PFCallState) window.PFCallState.activeId = activeId;

    // Check if system is ready
    if (!window.PFCall || typeof window.PFCall.startCall !== 'function' || !window.PFCall.userId) {
        if (typeof window.pfCallWarn === 'function') {
            window.pfCallWarn('[PFCall] System not ready, waiting for initialization before starting call...');
        } else if (typeof console !== 'undefined' && typeof console.warn === 'function') {
            console.warn('[PFCall] System not ready, waiting for initialization before starting call...');
        }
        
        // Attempt manual recovery if possible
        if (window.PFCall && typeof initCallSystem === 'function') {
            initCallSystem();
        }

        const handler = () => {
            pfCallDebug('[PFCall] System ready, retrying call...');
            initiateCall(type);
        };
        window.addEventListener('PFCallGlobalReady', handler, { once: true });
        return;
    }

    // Double check the singleton
    if (typeof window.PFCall.startCall !== 'function') {
        console.error('[PFCall] startCall method not found');
        showToast('Call system error. Please refresh.', 'error');
        return;
    }

    const fd = new FormData();
    fd.append('order_id', activeId);
    api('/public/api/chat/status.php', 'POST', fd)
        .then(res => {
            if (!res.partner) { showToast("Customer is unavailable.", "error"); return; }
            const pId = res.partner.id;
            const pName = res.partner.name;
            const pAvatar = resolveProfileUrl(res.partner.avatar);
            if (!window.PFCallState) window.PFCallState = {};
            window.PFCallState.activePartner = {
                id: pId,
                type: 'Customer',
                name: pName || document.getElementById('activeName')?.textContent || 'Customer',
                avatar: pAvatar || partnerAvatarUrl || ''
            };
            
            if (window.PFCall && typeof window.PFCall.startCall === 'function') {
                window.PFCall.startCall(pId, 'Customer', pName, pAvatar, type);
            } else {
                console.error('[PFCall] PFCall or startCall method is missing');
                showToast('Calling system error. Please refresh the page.', 'error');
            }
        });
}

// --- API Logic ---
async function api(url, method = 'GET', body = null) {
    const opts = { credentials: 'same-origin', method };
    if (body) opts.body = (body instanceof FormData) ? body : JSON.stringify(body);
    try {
        const r = await fetch(window.baseUrl + url, opts);
        if (!r.ok) throw new Error('Request failed with status ' + r.status);
        const text = await r.text();
        const jsonStart = text.indexOf('{');
        if (jsonStart !== -1) {
            return JSON.parse(text.substring(jsonStart));
        }
        return JSON.parse(text);
    } catch (e) {
        if (e.message.includes('JSON') || e.message.includes('Unexpected token')) {
            return { success: false, error: 'File upload exceeded server limits. Please select fewer or smaller files.' };
        }
        return { success: false, error: e.message };
    }
}

// --- Conversations ---
function loadConvs() {
    const searchVal = document.getElementById('searchInput') ? document.getElementById('searchInput').value : '';
    api(`/public/api/chat/list_conversations.php?archived=${isArchivedView?1:0}&q=${encodeURIComponent(searchVal)}`)
        .then(data => {
            const list = document.getElementById('convList');
            if (!data.success) {
                list.innerHTML = `<div class="p-8 text-center text-red-500 text-sm">Error: ${data.error || 'Check server connection'}</div>`;
                return;
            }
            if (!data.conversations.length) {
                list.innerHTML = `<div class="p-8 text-center text-slate-400 text-sm">No conversations found</div>`;
                return;
            }
            const normalizedConversations = data.conversations.map((c, index) => {
                const normalized = {
                    ...c,
                    customer_name: typeof c?.customer_name === 'string' && c.customer_name.trim() ? c.customer_name : 'Customer',
                    product_name: typeof c?.product_name === 'string' && c.product_name.trim()
                        ? c.product_name
                        : (typeof c?.service_name === 'string' && c.service_name.trim() ? c.service_name : 'Order'),
                    customer_avatar: typeof c?.customer_avatar === 'string' ? c.customer_avatar : '',
                    online_status: typeof c?.online_status === 'string'
                        ? c.online_status
                        : (typeof c?.customer_status === 'string' ? c.customer_status : 'offline')
                };

                if (!c || typeof c !== 'object') {
                    console.warn('[Staff Chat] Invalid conversation entry received from API', { index, conversation: c });
                    return normalized;
                }

                const missingFields = ['order_id', 'customer_name', 'product_name'].filter(field => {
                    const value = normalized[field];
                    return value === undefined || value === null || value === '';
                });

                if (missingFields.length) {
                    console.warn('[Staff Chat] Conversation missing expected fields', {
                        index,
                        orderId: c.order_id ?? null,
                        missingFields,
                        rawConversation: c
                    });
                }

                if (c.service_name && !c.product_name) {
                    console.debug('[Staff Chat] Using service_name fallback for conversation meta', {
                        orderId: c.order_id ?? null,
                        service_name: c.service_name
                    });
                }

                return normalized;
            });
            list.innerHTML = normalizedConversations.map(c => {
                const active = activeId === c.order_id ? 'active' : '';
                const online = c.online_status === 'online' ? 'active' : '';
                const busy = c.online_status === 'in-call' ? 'busy' : '';
                // Fallback values and safe escaping for onclick parameters
                const safeCustName = (c.customer_name || 'Customer').replace(/'/g, "\\'");
                const safeProdName = (c.product_name || 'Order').replace(/'/g, "\\'");
                const safeAvatar   = (c.customer_avatar || '').replace(/'/g, "\\'");
                
                return `
                <div class="conv-card ${active}" onclick="openChat(${c.order_id}, '${safeCustName}', '${safeProdName}', ${c.is_archived}, '${safeAvatar}')">
                    <div class="conv-avatar" style="overflow: hidden;">
                        ${c.customer_avatar ? `<img src="${resolveProfileUrl(c.customer_avatar)}" style="width:100%;height:100%;object-fit:cover;" onerror="${PROFILE_IMAGE_ONERROR}">` : ((c.customer_name || '?')[0] || '?').toUpperCase()}
                        <div class="dot-online ${online} ${busy}"></div>
                    </div>
                    <div class="conv-info">
                        <div class="conv-name-row">
                            <span class="conv-name truncate-ellipsis" title="${escapeHtml(c.customer_name || 'Customer')}">${escapeHtml(c.customer_name || 'Customer')}</span>
                            <span class="conv-time">${formatTime(c.last_message_at)}</span>
                        </div>
                        <div class="conv-sub">${escapeHtml(c.product_name || '').toLowerCase()}</div>
                        <div class="conv-preview">
                            ${c.unread_count > 0 ? `<span class="bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full font-black">${c.unread_count}</span>` : ''}
                            <span class="conv-preview-text">${escapeHtml(c.last_message || 'No messages yet')}</span>
                        </div>
                    </div>
                </div>`;
            }).join('');
            
            // Auto open if deep-linked via URL but UI state isn't synced
            const urlParams = new URLSearchParams(window.location.search);
            const rawId = urlParams.get('order_id');
            if (rawId && !window.staffUiOpened && normalizedConversations.length) {
                const c = normalizedConversations.find(x => x.order_id == rawId);
                if (c) {
                    openChat(c.order_id, c.customer_name, c.product_name, c.is_archived, c.customer_avatar || '');
                } else {
                    console.warn('[Staff Chat] Deep-linked conversation not found in API response', {
                        orderId: rawId,
                        conversationCount: normalizedConversations.length
                    });
                }
            }
        });
}

function switchMainTab(arch) {
    isArchivedView = arch;
    document.getElementById('tabActive').classList.toggle('active', !arch);
    document.getElementById('tabArchived').classList.toggle('active', arch);
    document.getElementById('convList').innerHTML = '<div class="p-8 text-center text-slate-400">Switching view...</div>';
    loadConvs();
}

function isMobileChatView() {
    return window.innerWidth < 1024;
}

function updateMobileChatChrome() {
    const app = document.getElementById('chatApp');
    const title = document.getElementById('mobilePageTitle');
    const backButtons = document.querySelectorAll('.mobile-thread-back');
    if (!app || !title || !backButtons.length) return;

    const inThread = app.classList.contains('mobile-thread-view');
    title.textContent = inThread && activeId
        ? (document.getElementById('activeName')?.textContent || 'Conversation')
        : 'Chats';

    backButtons.forEach(btn => {
        btn.style.display = isMobileChatView() && inThread ? 'inline-flex' : 'none';
    });
}

function showConversationList() {
    const app = document.getElementById('chatApp');
    if (!app || !isMobileChatView()) return;
    app.classList.remove('mobile-thread-view');
    app.classList.add('mobile-list-view');
    updateMobileChatChrome();
}

function showConversationThread() {
    const app = document.getElementById('chatApp');
    if (!app || !isMobileChatView()) return;
    app.classList.remove('mobile-list-view');
    app.classList.add('mobile-thread-view');
    updateMobileChatChrome();
}

// --- Unified Menu ---
function toggleMenu(e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('chatDropdown');
    if (menu) menu.classList.toggle('show');
}
if (!window.__pfStaffChatMenuCloseBound) {
    window.__pfStaffChatMenuCloseBound = true;
    window.addEventListener('click', () => {
        const menu = document.getElementById('chatDropdown');
        if (menu) menu.classList.remove('show');
    });
}

// --- Chat Window ---
function openChat(id, name, meta, archived, avatar = '') {
    const safeName = typeof name === 'string' && name.trim() ? name : 'Customer';
    const safeMeta = typeof meta === 'string' && meta.trim() ? meta : 'Order';
    const safeAvatar = typeof avatar === 'string' ? avatar : '';

    console.debug('[Staff Chat] openChat called', {
        id,
        name,
        meta,
        archived,
        avatar,
        nameType: typeof name,
        metaType: typeof meta,
        avatarType: typeof avatar
    });

    if (typeof meta !== 'string') {
        console.warn('[Staff Chat] openChat received non-string meta value', {
            id,
            rawMeta: meta,
            fallbackMeta: safeMeta
        });
    }

    activeId = id;
    lastId = 0;
    partnerAvatarUrl = safeAvatar ? resolveProfileUrl(safeAvatar) : null;
    if (!window.PFCallState) window.PFCallState = {};
    window.PFCallState.activeId = id;
    window.PFCallState.activePartner = {
        id: null,
        type: 'Customer',
        name: safeName,
        avatar: partnerAvatarUrl || ''
    };
    window.staffUiOpened = true;
    const welcomeEl = document.getElementById('welcomeScreen');
    const chatEl = document.getElementById('chatInterface');
    if (welcomeEl) welcomeEl.style.display = 'none';
    if (chatEl) chatEl.style.display = 'flex';
    
    const nameEl = document.getElementById('activeName');
    if (nameEl) nameEl.textContent = safeName;
    
    const metaEl = document.getElementById('activeMeta');
    if (metaEl) metaEl.textContent = safeMeta.toLowerCase();
    
    const avatarEl = document.getElementById('activeAvatar');
    if (avatarEl) {
        avatarEl.style.overflow = 'hidden';
        if (safeAvatar) {
            avatarEl.innerHTML = `<img src="${resolveProfileUrl(safeAvatar)}" style="width:100%;height:100%;object-fit:cover;" onerror="${PROFILE_IMAGE_ONERROR}">`;
        } else {
            avatarEl.textContent = (safeName && safeName[0] ? safeName[0].toUpperCase() : '?');
        }
    }
    
    const msgsArea = document.getElementById('messagesArea');
    if (msgsArea) msgsArea.innerHTML = '<div class="p-8 text-center text-slate-400">Loading history...</div>';
    
    // Set initial archive UI
    updateArchiveUI(!!archived);

    // Show Call Buttons
    document.querySelectorAll('.call-btns').forEach(el => el.style.display = 'flex');
    // Close gallery and dropdown on chat switch
    toggleMediaGallery(false);
    const menu = document.getElementById('chatDropdown');
    if (menu) menu.classList.remove('show');

    loadMsgs();
    clearInterval(pollId);
    pollId = setInterval(loadMsgs, 2000);
    loadConvs();
    showConversationThread();
}

function updateArchiveUI(isArchived) {
    currentArchivedState = isArchived;
    const notice = document.getElementById('archivedNotice');
    const label = document.getElementById('archiveLabel');
    if (notice) notice.style.display = isArchived ? 'block' : 'none';
    if (label) {
        label.innerHTML = isArchived ? '<i class="bi bi-arrow-up-circle"></i> Unarchive' : '<i class="bi bi-archive"></i> Archive';
    }
}

function toggleArchStatus(id, st) {
    const fd = new FormData();
    fd.append('order_id', id);
    fd.append('archive', st ? 1 : 0);
    api('/public/api/chat/set_archived.php', 'POST', fd).then(res => {
        if (res.success) {
            updateArchiveUI(st);
            loadConvs();
        }
    });
}

function loadMsgs() {
    if (!activeId) return;
    const box = document.getElementById('messagesArea');
    if (!box) {
        if (pollId) { clearInterval(pollId); pollId = null; }
        return;
    }
    api(`/public/api/chat/fetch_messages.php?order_id=${activeId}&last_id=${lastId}&is_active=1`)
        .then(data => {
            if (!data.success) {
                clearInterval(pollId); // STOP LOOP IF ERROR
                if (lastId === 0) {
                    box.innerHTML = '<div class="p-8 text-center text-slate-400 text-sm">Unable to load messages right now.</div>';
                }
                return;
            }
            const isInitialLoad = (lastId === 0);
            if (isInitialLoad) box.innerHTML = '';
            data.messages.forEach(m => {
                appendMsgUI(m);
                lastId = Math.max(lastId, m.id);
            });
            
            if (data.reactions) {
                currentReactions = data.reactions;
                renderAllReactions();
            }
            
            const partnerStatus = document.getElementById('partnerStatus');
            if (partnerStatus) {
                partnerStatus.style.display = data.partner.is_online ? 'inline-block' : 'none';
                partnerStatus.className = 'dot-online'; // reset
                if (data.partner.online_status === 'online') partnerStatus.classList.add('active');
                if (data.partner.online_status === 'in-call') partnerStatus.classList.add('busy');
            }
            partnerAvatarUrl = (data.partner && data.partner.avatar) ? resolveProfileUrl(data.partner.avatar) : partnerAvatarUrl;
            if (data.is_archived !== undefined) updateArchiveUI(data.is_archived);
            if (data.messages.length) {
                if (isInitialLoad) {
                    // Instant jump to bottom for professional feel
                    box.style.scrollBehavior = 'auto';
                    box.scrollTop = box.scrollHeight;
                    requestAnimationFrame(() => { box.scrollTop = box.scrollHeight; box.style.scrollBehavior = 'smooth'; });
                    // Backup for slow rendering media
                    setTimeout(() => { box.scrollTop = box.scrollHeight; }, 100);
                } else {
                    scrollToBottom(true);
                }
            }
            
            if (data.last_seen_message_id !== undefined) {
                updateStaffSeenIndicators(data.last_seen_message_id);
            }

            // Update Pinned Bar
            updatePinnedBar(data.pinned_messages || []);
        });
}

function updatePinnedBar(pinned) {
    const bar = document.getElementById('pinnedBar');
    const text = document.getElementById('pinnedCountText');
    if (!bar || !text) return;
    if (!pinned || pinned.length === 0) {
        bar.style.display = 'none';
        bar.classList.remove('pin-bar-active');
        return;
    }
    bar.style.display = 'flex';
    bar.classList.add('pin-bar-active');
    text.textContent = pinned.length === 1 ? '1 pinned message' : `${pinned.length} pinned messages`;
    bar.onclick = () => openPinnedModal(pinned);
}

function openPinnedModal(pinned) {
    if (!document.getElementById('pinnedModal')) {
        const div = document.createElement('div');
        div.id = 'pinnedModal';
        div.className = 'details-modal-overlay';
        div.innerHTML = `
            <div class="details-modal-panel" style="max-width:450px;">
                <div class="details-modal-header">
                    <h2 style="font-size:1.1rem; font-weight:900; color:#1e293b; margin:0;">Pinned Messages</h2>
                    <button type="button" onclick="document.getElementById('pinnedModal').classList.remove('active')" class="h-btn" style="border:none; background:transparent;">
                         <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke-width="2.5"/></svg>
                    </button>
                </div>
                <div id="pinnedList" style="padding:1.5rem; max-height:500px; overflow-y:auto; display:flex; flex-direction:column; gap:10px;"></div>
            </div>
        `;
        document.body.appendChild(div);
    }
    const modal = document.getElementById('pinnedModal');
    modal.classList.add('active');
    const list = document.getElementById('pinnedList');
    
    list.innerHTML = pinned.map(m => {
        let mediaHtml = '';
        if (m.message_type === 'voice') {
            const src = resolveAppUrl(m.message_file || m.file_path || m.image_path);
            mediaHtml = `<div style="margin-top:8px; background:#e2e8f0; padding:8px; border-radius:12px; display:flex; align-items:center; gap:10px;">
                <audio controls src="${src}" style="height:30px; width:100%; outline:none;"></audio>
            </div>`;
        } else if (m.message_type === 'video' || m.file_type === 'video') {
            const src = resolveAppUrl(m.message_file || m.file_path || m.image_path);
            mediaHtml = `<div style="margin-top:8px; border-radius:12px; overflow:hidden; background:#000;">
                <video src="${src}" controls style="width:100%; max-height:200px; display:block;"
                    onerror="this.insertAdjacentHTML('afterend', '<div style=\'padding:10px; background:#f1f5f9; border-radius:8px; font-size:0.8rem; color:#64748b; text-align:center;\'><i class=\'bi bi-exclamation-triangle-fill\'></i> Video unavailable</div>'); this.style.display=\'none\';">
                </video>
            </div>`;
        } else if (m.message_type === 'image' || m.image_path) {
            const src = resolveAppUrl(m.image_path || m.message_file || m.file_path);
            mediaHtml = `<div style="margin-top:8px; border-radius:12px; overflow:hidden; background:#f1f5f9;">
                <img src="${src}" style="max-width:100%; max-height:200px; object-fit:contain; display:block;">
            </div>`;
        }

        return `
        <div style="padding:12px; border-radius:12px; background:#f8fafc; border:1px solid #e2e8f0; cursor:pointer; transition:all 0.2s;" onclick="goToMessage(${m.id}); document.getElementById('pinnedModal').classList.remove('active')">
            <div style="font-size:0.7rem; color:#000000; font-weight:800; margin-bottom:4px;">${m.sender_name} • ${formatTime(m.created_at)}</div>
            ${m.message ? `<div style="font-size:0.95rem; color:#000000; line-height:1.4; word-break:break-word; overflow-wrap:anywhere;">${escapeHtml(m.message)}</div>` : ''}
            ${mediaHtml}
        </div>`;
    }).join('');
}

function goToMessage(id) {
    const el = document.getElementById(`ms-${id}`);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.style.animation = 'highlightStaffMsg 2s ease';
    }
}

function getOrderUpdateActionLabel() {
    return 'Open order';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function normalizeSenderType(value) {
    const senderType = String(value || '').toLowerCase();
    return senderType === 'customer' || senderType === 'staff' ? senderType : '';
}

function getMessageSide(message) {
    const senderType = normalizeSenderType(message?.sender_type);
    if (senderType) {
        return (senderType === CURRENT_USER_TYPE) ? 'self' : 'other';
    }
    // Final fallback
    return (message?.is_self) ? 'self' : 'other';
}

function getMessageSenderKey(message) {
    const senderType = normalizeSenderType(message?.sender_type);
    if (senderType) {
        return senderType;
    }
    if (message?.is_system && message?.message_type !== 'order_update') {
        return 'system';
    }
    return String(message?.sender || '').toLowerCase() || (message?.is_self ? 'self' : 'other');
}

function getOrderStatusTone(statusText) {
    const normalized = String(statusText || '').toLowerCase();
    if (normalized.includes('cancel') || normalized.includes('reject')) return 'alert';
    if (normalized.includes('complete')) return 'complete';
    if (normalized.includes('pickup') || normalized.includes('receive') || normalized.includes('ready')) return 'ready';
    if (normalized.includes('production')) return 'production';
    if (normalized.includes('pay') || normalized.includes('verify')) return 'payment';
    if (normalized.includes('approved')) return 'approved';
    if (normalized.includes('pending') || normalized.includes('review') || normalized.includes('revision')) return 'pending';
    return 'neutral';
}

function getOrderCardData(m) {
    const orderUpdate = m.order_update || {};
    let meta = {};
    try { meta = JSON.parse(m.meta_json || '{}'); } catch (e) {}

    let imagePath = orderUpdate.thumbnail || m.image || m.image_path || m.file_path || m.thumbnail || meta.image || meta.thumbnail || '';
    if (imagePath) imagePath = resolveAppUrl(imagePath);
    if (!imagePath) imagePath = '/public/default.png';

    const serviceName = orderUpdate.product_name || meta.product_name || m.service_name || m.message || 'Order update';
    const customerName = m.customer_name || meta.customer_name || m.sender_name || 'Customer';

    return {
        orderId: Number(orderUpdate.order_id || meta.order_id || activeId || 0),
        productName: serviceName,
        statusLabel: orderUpdate.status || meta.order_status || orderUpdate.payment_status || meta.payment_status || 'Status updated',
        thumbnail: imagePath,
        image: imagePath,
        service_name: serviceName,
        customer_name: customerName,
        messageText: m.message || meta.message || `${customerName} sent an inquiry for ${serviceName}`,
        buttonLabel: m.button_label || meta.button_label || 'Manage Order',
        actionUrl: (meta.action_url ?? '') || (m.action_url ?? ''),
        amount: (meta.amount ?? null) || (orderUpdate.amount ?? null),
        step: meta.step || ''
    };
}

function renderOrderCard(m) {
    const data = getOrderCardData(m);
    const statusTone = getOrderStatusTone(data.statusLabel);
    
    return `
        <div class="msg-content-col">
            <div class="order-card-v2" onclick="openOrderModal(${data.orderId})">
                <div class="oc-header">
                    <div class="oc-badge">Order Update</div>
                    <div class="oc-status tone-${statusTone}">${escapeHtml(data.statusLabel)}</div>
                </div>
                <div class="oc-body">
                    ${data.thumbnail ? `<img src="${resolveAppUrl(data.thumbnail)}" class="oc-thumb" onerror="this.src='/public/default.png'">` : `<div class="oc-thumb" style="display:flex;align-items:center;justify-content:center;font-size:1.5rem;background:#f8fafc;">📦</div>`}
                    <div class="oc-content">
                        <div class="oc-title">${escapeHtml(data.productName)}</div>
                        <div class="oc-desc">${escapeHtml(data.messageText)}</div>
                    </div>
                </div>
                <div class="oc-footer">
                    ${data.amount ? `<div class="oc-price">Final price: ${formatCurrency ? formatCurrency(data.amount) : ('$' + Number(data.amount).toFixed(2))}</div>` : ''}
                    ${data.actionUrl ? `<a href="${data.actionUrl}" class="oc-btn" onclick="event.stopPropagation();">${escapeHtml(data.buttonLabel)} <i class="bi bi-chevron-right"></i></a>` : `<button class="oc-btn">${escapeHtml(data.buttonLabel)} <i class="bi bi-chevron-right"></i></button>`}
                </div>
            </div>
        </div>`;
}

function openOrderModal(orderId) {
    if (!orderId) return;
    openDetails(orderId);
}

function appendMsgUI(m) {
    const box = document.getElementById('messagesArea');
    if (document.getElementById(`ms-${m.id}`)) return;

    // Messenger Grouping Logic (Standardized)
    const prevRow = box.lastElementChild;
    const messageTimeKey = m.created_at_full || m.created_at;
    const currentMin = getMinute(messageTimeKey);
    const prevMin = prevRow ? getMinute(prevRow.getAttribute('data-time')) : null;

    const isCallLog = m.message_type === 'call_log' || m.message_type === 'call_event' || /voice call|video call|missed|declined|busy/i.test(m.message);
    const rowSide = getMessageSide(m);
    const senderKey = getMessageSenderKey(m);
    const rowClass = rowSide;
    const isSelf = rowClass === 'self';

    if (m.message_type === 'order_update' || m.message_type === 'order_card') {
        const row = document.createElement('div');
        row.id = `ms-${m.id}`;
        row.className = `bubble-row order-update staff-view ${rowSide === 'system' ? 'other' : rowSide}`;
        row.setAttribute('data-sender', senderKey);
        row.setAttribute('data-time', messageTimeKey);
        row.innerHTML = renderOrderCard(m);
        box.appendChild(row);
        return;
    }

    const isGrouped = prevRow && !prevRow.classList.contains('order-update') && rowClass !== 'system' &&
                      prevRow.getAttribute('data-sender') === senderKey &&
                      currentMin === prevMin;

    const row = document.createElement('div');
    row.id = `ms-${m.id}`;
    row.className = `bubble-row ${rowClass}`;
    row.setAttribute('data-sender', senderKey);
    row.setAttribute('data-time', messageTimeKey);

    if (isGrouped) {
        prevRow.classList.add('grouped-msg');
        row.classList.add('grouped-msg-next');
    }

    if (m.is_system && !isCallLog) {
        const avHtml = `<div class="msg-avatar" style="background:#f1f5f9; color:#94a3b8; font-size:11px; font-weight:900;">SYS</div>`;
        row.innerHTML = `${avHtml}<div class="msg-content-col"><div class="bubble" style="background:#f8fafc; color:#64748b; border:1px dashed #e2e8f0; font-style:italic;">${escapeHtml(m.message)}</div></div>`;
        box.appendChild(row); return;
    }

    let avatarHtml = '';
    if (!isSelf) {
        const initial = (m.sender_name || 'C')[0].toUpperCase();
        avatarHtml = `<div class="msg-avatar">${m.sender_avatar ? `<img src="${resolveProfileUrl(m.sender_avatar)}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" onerror="${PROFILE_IMAGE_ONERROR}">` : `<span>${initial}</span>`}</div>`;
    }

    const isCallMsg = (m.message && m.message.includes('📞'));
    let colHtml = `<div class="msg-content-col" style="${isCallMsg ? 'max-width:none;' : ''}">`;
    
    if (!isSelf && !isGrouped) {
        const roleBadge = m.sender_role ? `<span class="role-badge">${m.sender_role}</span>` : '';
        colHtml += `<div class="msg-sender-info">${escapeHtml(m.sender_name || m.sender)} ${roleBadge}</div>`;
    }

    const msgB64 = safeBase64Encode(m.message || '');
    const hasImg = (m.image_path || m.message_file) ? '1' : '0';

    colHtml += `
        <div class="msg-action-bar">
            <div class="m-action-btn" onclick="togglePicker(${m.id}, event, this); return false;" style="position:relative;" role="button" tabindex="0">
                <i class="bi bi-emoji-smile"></i>
                <div class="reaction-picker" id="picker-${m.id}">
                    ${Object.entries(REACTION_EMOJIS).map(([type, emoji]) => `<button type="button" class="reaction-btn" onclick="toggleReaction(${m.id}, '${type}'); return false;">${emoji}</button>`).join('')}
                </div>
            </div>
            <div class="m-action-btn" onclick="initReply(${m.id}, '${msgB64}', '${hasImg}')">
                <i class="bi bi-reply-fill"></i>
            </div>
            <div class="m-action-btn" onclick="toggleMoreMenu(${m.id}, event, this); return false;" style="position:relative;" role="button" tabindex="0">
                <i class="bi bi-three-dots"></i>
                <div class="m-more-menu" id="more-${m.id}">
                    <div class="m-menu-item" onclick="pinMessage(${m.id}); return false;">
                        <i class="bi ${m.is_pinned == 1 ? 'bi-pin-angle-fill' : 'bi-pin-angle'}"></i> ${m.is_pinned == 1 ? 'Unpin' : 'Pin'}
                    </div>
                    <div class="m-menu-item" onclick="initForward(${m.id}, '${msgB64}', '${m.message_type}'); return false;">
                        <i class="bi bi-arrow-right-short"></i> Forward
                    </div>
                </div>
            </div>
        </div>
        <div class="bubble" style="position:relative; ${isCallMsg ? 'max-width:none;' : ''}" id="bubble-${m.id}">
            ${m.is_pinned == 1 ? `<div class="pinned-badge" title="Pinned Message"><i class="bi bi-pin-fill"></i></div>` : ''}
            ${m.is_forwarded ? `<div style="font-size:0.65rem; color:var(--pf-dim); margin-bottom:4px; font-style:italic; display:flex; align-items:center; gap:3px;"><i class="bi bi-arrow-90deg-right"></i> Forwarded</div>` : ''}
            ${m.reply_id ? `<a href="javascript:void(0)" onclick="document.getElementById('ms-${m.reply_id}')?.scrollIntoView({behavior: 'smooth', block: 'center'})" class="reply-preview-bubble">↳ Replying: ${m.reply_image ? 'Photo' : (m.reply_message ? escapeHtml(m.reply_message) : 'Message')}</a>` : ''}
    `;

    if (m.message_type === 'order_card') {
        const data = getOrderCardData(m);
        data.order_id = m.order_id || m.orderId || m.order || data.orderId || null;
        if (!data.image) {
            data.image = '/public/default.png';
        }
        console.log("Order Card Rendered:", data);
        console.log("Image path:", data.image);
        colHtml += `
            <div class="msg-content-col">
                <div class="order-card" data-order-id="${data.order_id}">
                    <img src="${data.image}" class="order-img" onerror="this.onerror=null;this.src='/public/default.png'">
                    <div class="order-info">
                        <strong>${escapeHtml(data.service_name)}</strong>
                        <p>${escapeHtml(data.customer_name)} sent an inquiry</p>
                    </div>
                </div>
            </div>
        `;
    } else if (false && m.message_type === 'order_card') {
        const orderIdForCard = m.order_id || m.orderId || m.order || null;
        const thumb = m.image_path || m.file_path || '';
        colHtml += `
            <div class="order-card" data-order-id="${orderIdForCard}" style="display:flex;gap:10px;align-items:center;cursor:pointer;">
                <div style="width:64px;height:64px;flex-shrink:0;border-radius:8px;overflow:hidden;background:#f3f4f6;display:flex;align-items:center;justify-content:center;border:1px solid #e6eef2;">
                    ${thumb ? `<img src="${resolveAppUrl(thumb)}" style="width:100%;height:100%;object-fit:cover;">` : '<span style="font-size:24px;">🛒</span>'}
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:800;color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(m.service_name || m.message || 'New Order')}</div>
                    <div style="font-size:0.85rem;color:#6b7280; margin-top:4px;">${escapeHtml(m.message || (m.customer_name ? 'New order from ' + m.customer_name : 'New order received'))}</div>
                </div>
            </div>
        `;
    } else if (isCallLog) {
        const isVideo = m.message.toLowerCase().includes('video');
        const isMissed = m.message.toLowerCase().includes('missed') || m.message.toLowerCase().includes('declined') || m.message.toLowerCase().includes('busy') || m.message.toLowerCase().includes('no answer');
        const icon = isVideo ? '<i class="bi bi-camera-video-fill"></i>' : '<i class="bi bi-telephone-fill"></i>';
        const statusText = isSelf ? 'Outgoing' : 'Incoming';

        colHtml += `
            <div class="call-log-bubble">
                <div class="call-log-icon ${isMissed ? 'missed' : 'ended'}">${icon}</div>
                <div class="call-log-details">
                    <div class="call-log-title" style="${isMissed ? 'color: #e11d48;' : 'color: #0d9488;'}">${escapeHtml(m.message)}</div>
                    <div class="call-log-status">${statusText}</div>
                </div>
            </div>
        `;
    } else if (m.message_type === 'voice') {
        const _audioBase = resolveAppUrl(m.message_file || m.file_path || m.image_path);
        const audioSrc = _audioBase + (_audioBase.includes('?') ? '&' : '?') + 'v=' + Date.now();
        colHtml += `
        <div class="voice-bubble-player" id="voice-p-${m.id}">
            <button class="play-pause-btn" onclick="toggleVoicePlayer(${m.id}, '${audioSrc}')">
                <i class="bi bi-play-fill" id="v-icon-${m.id}" style="font-size: 1.2rem; margin-left: 2px;"></i>
            </button>
            <div class="v-waveform-container" onclick="seekVoice(${m.id}, event)">
                <canvas class="v-waveform-canvas" id="v-canvas-${m.id}"></canvas>
            </div>
            <span class="v-duration" id="v-dur-${m.id}">${m.duration > 0 ? formatAudioTime(m.duration) : '0:00'}</span>
            <audio id="v-audio-${m.id}" src="${audioSrc}" ontimeupdate="updateVoiceProgress(${m.id})" onended="resetVoicePlayer(${m.id})" onloadedmetadata="initVoiceDuration(${m.id})" onerror="handleVoiceAudioError(${m.id})"></audio>
        </div>`;
        setTimeout(() => drawWaveformFromUrl(audioSrc, `v-canvas-${m.id}`, isSelf ? 'rgba(255,255,255,0.7)' : '#64748b', m.id), 50);
    } else if (m.message_type === 'video' || m.file_type === 'video') {
        const videoSrc = resolveAppUrl(m.message_file || m.file_path || m.image_path);
        colHtml += `<div class="chat-video-wrapper" onclick="zoomVideo('${videoSrc.replace(/'/g, "\\'")}')" style="position:relative;cursor:pointer;border-radius:12px;overflow:hidden;max-width:280px;background:#000;margin-bottom:4px;">
            <video src="${videoSrc}" style="width:100%;max-width:280px;display:block;border-radius:12px;" preload="metadata" muted playsinline
                onerror="this.insertAdjacentHTML('afterend', '<div style=\'padding:20px; color:#fff; font-size:0.8rem; text-align:center;\'><i class=\'bi bi-play-btn\'></i><br>Video unavailable</div>'); this.style.display=\'none\';">
            </video>
            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;">
                <div style="width:48px;height:48px;background:rgba(0,0,0,0.55);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                    <svg width="20" height="20" fill="white" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                </div>
            </div>
        </div>`;
    } else if (m.message_type === 'image' || m.image_path) {
        const imgSrc = resolveAppUrl(m.image_path || m.message_file || m.file_path);
        colHtml += `<div class="chat-image-wrap" onclick="zoomImg('${imgSrc.replace(/'/g, "\\'")}')"><img src="${imgSrc}" onload="this.closest('.brow')?.parentElement?.scrollTo({top:this.closest('.brow').parentElement.scrollHeight});"></div>`; 
    }
    if (m.message && !isCallLog && m.message_type !== 'voice') colHtml += `<span>${escapeHtml(m.message)}</span>`;
    if (!m.is_system) colHtml += `<div class="reaction-display-container" id="reactions-for-${m.id}" style="display:none;"></div>`;
    colHtml += `</div><div class="bubble-meta">${m.created_at || formatTime(messageTimeKey)}</div>`;
    if (isSelf) colHtml += `<div class="seen-wrapper" id="seen-container-${m.id}"></div>`;
    colHtml += `</div>`;
    
    row.innerHTML = avatarHtml + colHtml;
    row.setAttribute('data-is-self', isSelf ? '1' : '0');
    row.setAttribute('data-status', m.status);
    box.appendChild(row);
    bindMobileMessageHold(row);

    if ((m.image_path || m.message_file) && document.getElementById('mediaGallery')?.classList.contains('active')) loadMedia();
}

function getMinute(d) {
    if (!d) return null;
    const raw = String(d);
    let date = new Date(raw.replace(/-/g, '/'));
    if (isNaN(date) && (raw.includes('AM') || raw.includes('PM'))) {
        date = new Date(`${new Date().toDateString()} ${raw}`);
    }
    if (isNaN(date)) return null;
    return date.getFullYear() + '-' + (date.getMonth() + 1) + '-' + date.getDate() + ' ' + date.getHours() + ':' + date.getMinutes();
}

function renderAllReactions() {
    const grouped = {};
    currentReactions.forEach(r => {
        if (!grouped[r.message_id]) grouped[r.message_id] = [];
        grouped[r.message_id].push(r);
    });

    document.querySelectorAll('.reaction-display-container').forEach(el => {
        const msgId = parseInt(el.id.replace('reactions-for-', ''));
        const rx = grouped[msgId];
        if (!rx || rx.length === 0) {
            el.style.display = 'none';
            return;
        }

        const counts = {};
        const reactorsByType = {};
        rx.forEach(r => {
            counts[r.reaction_type] = (counts[r.reaction_type] || 0) + 1;
            if (!reactorsByType[r.reaction_type]) reactorsByType[r.reaction_type] = [];
            reactorsByType[r.reaction_type].push(r.reactor_name);
        });

        const reactionBubbles = Object.entries(counts).map(([type, count]) => {
            const emoji = REACTION_EMOJIS[type] || type;
            const reactors = reactorsByType[type].join(', ');
            return `<span class="reaction-bubble" title="${reactors}" onclick="toggleReaction(${msgId}, '${type}')">
                ${emoji}
                ${count > 1 ? `<span class="reaction-count">${count}</span>` : ''}
            </span>`;
        }).join('');
        
        el.innerHTML = `<div class="reaction-display">${reactionBubbles}</div>`;
        el.style.display = 'block';
    });
}

function togglePicker(msgId, e, triggerEl) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const picker = document.getElementById('picker-'+msgId);
    if (!picker) return;
    const isActive = picker.classList.contains('active');
    closeAllMenus();
    if (!isActive) {
        picker.classList.add('active');
        const row = document.getElementById(`ms-${msgId}`);
        setActiveMessageRow(row);

        const trigger = triggerEl || e?.currentTarget || e?.target?.closest('.m-action-btn') || picker.parentElement;
        requestAnimationFrame(() => positionFloatingMenu(picker, trigger, { preferred: 'top', mobileWidth: 300, gap: 12 }));
    }
}

function toggleMoreMenu(msgId, e, triggerEl) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const menu = document.getElementById('more-'+msgId);
    if (!menu) return;
    const isActive = menu.classList.contains('active');
    closeAllMenus();
    if (!isActive) {
        menu.classList.add('active');
        const row = document.getElementById(`ms-${msgId}`);
        setActiveMessageRow(row);
        const trigger = triggerEl || e?.currentTarget || e?.target?.closest('.m-action-btn') || menu.parentElement;
        requestAnimationFrame(() => positionFloatingMenu(menu, trigger, { preferred: 'bottom', mobileWidth: 190, gap: 10 }));
    }
}

function positionMobileActionBar(row) {
    if (!row || !window.matchMedia('(max-width: 1023px)').matches) return;
    const actionBar = row.querySelector('.msg-action-bar');
    const bubble = row.querySelector('.bubble, .voice-bubble-player, .call-log-bubble, .order-update-bubble');
    if (!actionBar || !bubble) return;

    const bubbleRect = bubble.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const messagesArea = document.getElementById('messagesArea');
    const messagesRect = messagesArea ? messagesArea.getBoundingClientRect() : { top: 0, bottom: window.innerHeight, left: 0, right: viewportWidth };
    
    actionBar.style.display = 'flex';
    actionBar.style.visibility = 'hidden';
    const actionWidth = actionBar.offsetWidth || 120;
    const actionHeight = actionBar.offsetHeight || 40;
    
    // Horizontal center on bubble
    let left = bubbleRect.left + (bubbleRect.width / 2) - (actionWidth / 2);
    // Keep in bounds
    left = Math.max(8, Math.min(left, viewportWidth - actionWidth - 8));

    // Vertical position: Prefer ABOVE the bubble, like Messenger
    const gap = 12;
    let top = bubbleRect.top - actionHeight - gap;
    
    // If no space above, show below
    if (top < messagesRect.top + 8) {
        top = bubbleRect.bottom + gap;
    }

    actionBar.style.position = 'fixed';
    actionBar.style.left = `${left}px`;
    actionBar.style.top = `${top}px`;
    actionBar.style.transform = 'none';
    actionBar.style.margin = '0';
    actionBar.style.visibility = 'visible';
    actionBar.style.opacity = '1';
    actionBar.style.pointerEvents = 'auto';
    actionBar.style.zIndex = '1100';
}

function setActiveMessageRow(row) {
    if (!row) return;
    const wasActive = row.classList.contains('has-active-menu');
    document.querySelectorAll('.bubble-row').forEach(r => {
        if (r !== row) r.classList.remove('has-active-menu');
    });
    
    if (!wasActive) {
        row.classList.add('has-active-menu');
        requestAnimationFrame(() => positionMobileActionBar(row));
    } else {
        row.classList.remove('has-active-menu');
        closeAllMenus();
    }
}

function positionFloatingMenu(menu, trigger, options = {}) {
    if (!menu || !trigger) return;
    const gap = options.gap ?? 8;
    const preferred = options.preferred || 'bottom';

    // Measure menu dimensions
    const isVisible = menu.classList.contains('active') || menu.classList.contains('show');
    if (!isVisible) {
        menu.style.display = 'flex';
        menu.style.visibility = 'hidden';
    }
    
    reparentMenuToBody(menu);
    const triggerRect = trigger.getBoundingClientRect();
    const bubble = trigger.closest('.msg-content-col')?.querySelector('.bubble, .voice-bubble-player, .call-log-bubble, .order-update-bubble');
    const anchorRect = bubble ? bubble.getBoundingClientRect() : triggerRect;
    const menuRect = menu.getBoundingClientRect();
    
    if (!isVisible) {
        menu.style.display = '';
        menu.style.visibility = '';
    }

    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const messagesArea = document.getElementById('messagesArea');
    const messagesRect = messagesArea ? messagesArea.getBoundingClientRect() : { top: 0, bottom: viewportHeight, left: 0, right: viewportWidth };

    const menuHeight = menuRect.height || (options.preferred === 'top' ? 54 : 100);
    const menuWidth = Math.min(menuRect.width || options.mobileWidth || 220, viewportWidth - 24);

    // Determine vertical position
    const spaceAbove = anchorRect.top - messagesRect.top;
    const spaceBelow = messagesRect.bottom - anchorRect.bottom;
    const shouldOpenAbove = preferred === 'top' ? (spaceAbove > menuHeight + gap || spaceAbove > spaceBelow) : (spaceBelow < menuHeight + gap && spaceAbove > spaceBelow);
    
    let top;
    if (shouldOpenAbove) {
        top = anchorRect.top - menuHeight - gap;
        // Keep within messages area
        if (top < messagesRect.top + 8) top = messagesRect.top + 8;
    } else {
        top = anchorRect.bottom + gap;
        // Keep within messages area
        if (top + menuHeight > messagesRect.bottom - 8) top = messagesRect.bottom - menuHeight - 8;
    }

    // Horizontal center on the anchor (bubble)
    let left = anchorRect.left + (anchorRect.width / 2) - (menuWidth / 2);
    
    // Keep within viewport bounds
    left = Math.max(8, Math.min(left, viewportWidth - menuWidth - 8));

    top = Math.max(8, Math.min(top, viewportHeight - menuHeight - 8));

    // Apply positioning
    menu.style.position = 'fixed';
    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
    menu.style.width = 'auto';
    menu.style.transform = 'none';
    menu.style.right = 'auto';
    menu.style.bottom = 'auto';
    menu.style.zIndex = '9999';
    menu.style.visibility = 'visible';
    menu.style.opacity = '1';
}

// Move menu DOM node to document.body to avoid being clipped by parent stacking contexts
function reparentMenuToBody(menu) {
    if (!menu || menu.dataset.reparented === '1') return;
    try {
        const parent = menu.parentElement;
        if (!parent) return;
        const placeholder = document.createElement('div');
        placeholder.style.display = 'none';
        parent.insertBefore(placeholder, menu);
        menu.__pf_original_parent = parent;
        menu.__pf_placeholder = placeholder;
        document.body.appendChild(menu);
        menu.dataset.reparented = '1';
        menu.style.zIndex = 9999;
    } catch (err) {
        console.warn('reparentMenuToBody failed', err);
    }
}

// Restore menus back to their original parent when closing
function restoreReparentedMenus() {
    document.querySelectorAll('[data-reparented="1"]').forEach(menu => {
        try {
            const placeholder = menu.__pf_placeholder;
            const parent = menu.__pf_original_parent;
            if (placeholder && parent) {
                placeholder.parentElement.insertBefore(menu, placeholder);
                placeholder.remove();
                delete menu.__pf_placeholder;
                delete menu.__pf_original_parent;
                delete menu.dataset.reparented;
                menu.style.zIndex = '';
            }
        } catch (err) { console.warn('restoreReparentedMenus failed', err); }
    });
}

function closeAllMenus() {
    document.querySelectorAll('.reaction-picker').forEach(p => {
        p.classList.remove('active');
        p.style.cssText = '';
    });
    document.querySelectorAll('.m-more-menu').forEach(m => {
        m.classList.remove('active');
        m.style.cssText = '';
    });
    document.querySelectorAll('.bubble-row').forEach(r => r.classList.remove('has-active-menu'));
    document.querySelectorAll('.msg-action-bar').forEach(bar => {
        bar.style.cssText = '';
    });
    restoreReparentedMenus();
}

document.addEventListener('click', (e) => {
    if (e.target.closest('.msg-action-bar, .m-action-btn, .reaction-picker, .m-more-menu, .reaction-display, .reaction-display-container')) {
        return;
    }
    closeAllMenus();
});

async function pinMessage(msgId) {
    const fd = new FormData();
    fd.append('message_id', msgId);
    api('/public/api/chat/pin_message.php', 'POST', fd).then(res => {
        if (res.success) {
            lastId = 0; // Force full refresh to update pin indicators
            loadMsgs();
            closeAllMenus();
        } else {
            showToast(res.error || "Pin failed", "error");
        }
    });
}

function toggleReaction(msgId, reactionType) {
    const fd = new FormData();
    fd.append('message_id', msgId);
    fd.append('reaction_type', reactionType);
    api('/public/api/chat/react_message.php', 'POST', fd)
        .then(res => {
            if (res.success) {
                loadMsgs(); 
                closeAllMenus();
            }
        });
}

var forwardMsgData = null;
var selectedForwardTargets = [];

function initForward(msgId, b64, type) {
    forwardMsgData = { msgId, text: safeBase64Decode(b64), type };
    openForwardModal();
    closeAllMenus();
}

function initReply(msgId, b64, hasImage) {
    replyToMessageId = msgId;
    const text = safeBase64Decode(b64);
    const preview = document.getElementById('replyPreviewBox');
    const previewText = document.getElementById('replyPreviewText');
    if (preview && previewText) {
        preview.style.display = 'flex'; // Use flex so it aligns with button
        previewText.textContent = hasImage == '1' ? '📸 Attachment' : (text || 'Message');
        const msgInput = document.getElementById('msgInput');
        if (msgInput) {
            msgInput.focus();
            scrollToBottom(true, true);
        }
    }
    closeAllMenus();
}

function cancelReply() {
    replyToMessageId = null;
    const preview = document.getElementById('replyPreviewBox');
    if (preview) preview.style.display = 'none';
}




function openForwardModal() {
    if (!document.getElementById('forwardModal')) {
        const div = document.createElement('div');
        div.id = 'forwardModal';
        div.className = 'details-modal-overlay';
        div.innerHTML = `
            <div class="details-modal-panel" style="max-width:450px; background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 20px 50px rgba(0,0,0,0.1);">
                <div class="details-modal-header" style="border-bottom: 1px solid #f1f5f9; padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="font-size:1.1rem; font-weight:900; color:#0f172a; margin:0;">Forward Message</h2>
                    <button type="button" onclick="closeForwardModal()" class="h-btn" style="border:none; background:transparent; color:#64748b;">
                         <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke-width="2.5"/></svg>
                    </button>
                </div>
                <div style="padding:1rem; border-bottom: 1px solid #f1f5f9;">
                    <div style="position:relative;">
                        <i class="bi bi-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
                        <input type="text" id="forwardSearch" placeholder="Search customer or order..." oninput="debounceForwardSearch(this.value)" style="width:100%; padding-left:36px; border-radius:12px; border:1px solid #e2e8f0; background:#f8fafc; height:40px; font-size:0.85rem; color:#1e293b;">
                    </div>
                </div>
                <div style="padding:0.75rem 1rem; background:#f8fafc; border-bottom: 1px solid #f1f5f9;">
                    <div style="font-size:0.65rem; color:#94a3b8; font-weight:800; text-transform:uppercase; margin-bottom:4px;">Preview</div>
                    <div id="forwardPreview" style="font-size:0.85rem; color:#1e293b; opacity:0.8; max-height:40px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></div>
                </div>
                <div id="forwardList" style="padding:1rem; max-height:350px; overflow-y:auto; display:flex; flex-direction:column; gap:8px;"></div>
                <div style="padding:1rem; border-top: 1px solid #f1f5f9; display:flex; justify-content:flex-end; gap:10px;">
                    <button onclick="closeForwardModal()" style="padding: 0 16px; height: 40px; border-radius: 12px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.85rem; font-weight: 700; color: #64748b; cursor: pointer;">Cancel</button>
                    <button id="forwardSendBtn" class="btn-send" style="width:auto; height:40px; padding:0 24px; font-weight:700; border-radius:12px; background:#0a2530; color: #fff;" onclick="processForward()" disabled>
                        Send <i class="bi bi-send-fill ml-2"></i>
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(div);
    }
    const modal = document.getElementById('forwardModal');
    modal.classList.add('active');
    if (forwardMsgData.text) {
        document.getElementById('forwardPreview').textContent = forwardMsgData.text;
    } else {
        const labels = { image: '📸 Image', video: '🎥 Video', voice: '🎤 Voice Message' };
        document.getElementById('forwardPreview').textContent = labels[forwardMsgData.type] || '📸 Attachment';
    }
    selectedForwardTargets = [];
    updateForwardBtn();
    loadForwardList();
}

function closeForwardModal() {
    const modal = document.getElementById('forwardModal');
    if (modal) modal.classList.remove('active');
    forwardMsgData = null;
}

function loadForwardList(q = '') {
    api(`/public/api/chat/list_conversations.php?archived=0&q=${encodeURIComponent(q)}`).then(data => {
        const list = document.getElementById('forwardList');
        if (!list) return;
        if (!data.success || !data.conversations.length) {
            list.innerHTML = '<p class="p-8 text-center opacity-40 text-sm">No active conversations</p>';
            return;
        }
        list.innerHTML = data.conversations.map(c => {
            const isSelected = selectedForwardTargets.includes(c.order_id);
            const avatarChar = (c.customer_name || 'C')[0].toUpperCase();
            return `
            <div onclick="toggleForwardTarget(${c.order_id})" style="padding:10px 14px; border-radius:14px; background:${isSelected ? '#f1f5f9' : '#fff'}; display:flex; align-items:center; gap:12px; cursor:pointer; transition:all 0.15s; border:1px solid ${isSelected ? '#e2e8f0' : '#f1f5f9'};">
                <div class="conv-avatar" style="width:36px; height:36px; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; overflow:hidden;">
                    ${c.customer_avatar ? `<img src="${resolveProfileUrl(c.customer_avatar)}" style="width:100%;height:100%;object-fit:cover;" onerror="${PROFILE_IMAGE_ONERROR}">` : avatarChar}
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:0.88rem; font-weight:800; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(c.customer_name || 'Customer')}</div>
                    <div style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:lowercase;">${escapeHtml(c.product_name || 'Order')}</div>
                </div>
                <div style="width:20px; height:20px; border-radius:50%; border:2px solid ${isSelected ? '#0a2530' : '#cbd5e1'}; background:${isSelected ? '#0a2530' : 'transparent'}; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    ${isSelected ? '<i class="bi bi-check" style="color:#fff; font-size:14px;"></i>' : ''}
                </div>
            </div>`;
        }).join('');
    });
}

function toggleForwardTarget(id) {
    if (selectedForwardTargets.includes(id)) {
        selectedForwardTargets = selectedForwardTargets.filter(x => x !== id);
    } else {
        selectedForwardTargets.push(id);
    }
    loadForwardList(document.getElementById('forwardSearch').value);
    updateForwardBtn();
}

function updateForwardBtn() {
    const btn = document.getElementById('forwardSendBtn');
    btn.disabled = selectedForwardTargets.length === 0;
    btn.innerHTML = `Send to ${selectedForwardTargets.length} <i class="bi bi-send-fill ml-2"></i>`;
}

async function processForward() {
    if (!forwardMsgData || !selectedForwardTargets.length) return;
    
    const btn = document.getElementById('forwardSendBtn');
    btn.disabled = true;
    const oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-hourglass-split animate-spin mr-2"></i> Sending...';

    let successCount = 0;
    for (const targetId of selectedForwardTargets) {
        const fd = new FormData();
        fd.append('order_id', targetId);
        fd.append('message_id', forwardMsgData.msgId);

        const res = await api('/public/api/chat/forward_message.php', 'POST', fd);
        if (res.success) successCount++;
    }

    closeForwardModal();
    if (successCount > 0) {
        showToast(`Successfully forwarded to ${successCount} conversation(s).`, "success");
        loadConvs();
    } else {
        btn.disabled = false;
        btn.innerHTML = oldHtml;
    }
}

function renderPreviews() {
    const a = document.getElementById('imgPreviewArea');
    a.style.display = uploadFiles.length ? 'flex' : 'none';
    a.innerHTML = uploadFiles.map((f, i) => {
        const isVideo = f.type.startsWith('video/');
        const objUrl = URL.createObjectURL(f);
        const sizeMb = (f.size / 1048576).toFixed(1);
        if (isVideo) {
            return `<div style="position:relative;" title="${f.name} (${sizeMb}MB)">
                <div style="width:52px;height:52px;border-radius:10px;background:#0f172a;overflow:hidden;display:flex;align-items:center;justify-content:center;border:1.5px solid #334155;">
                    <svg width="20" height="20" fill="white" viewBox="0 0 24 24" style="opacity:0.85"><path d="M8 5v14l11-7z"/></svg>
                </div>
                <div style="position:absolute;bottom:0;left:0;right:0;text-align:center;font-size:8px;font-weight:800;color:#94a3b8;white-space:nowrap;overflow:hidden;">${sizeMb}MB</div>
                <button type="button" onclick="uploadFiles.splice(${i},1);renderPreviews()" style="position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;width:16px;height:16px;border-radius:50%;font-size:10px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;">×</button>
            </div>`;
        }
        return `<div style="position:relative;" title="${f.name} (${sizeMb}MB)">
            <img src="${objUrl}" style="width:52px;height:52px;border-radius:10px;object-fit:cover;border:1.5px solid #e2e8f0;display:block;">
            <button type="button" onclick="uploadFiles.splice(${i},1);renderPreviews()" style="position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;width:16px;height:16px;border-radius:50%;font-size:10px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;">×</button>
        </div>`;
    }).join('');
}

// --- Init Event Listeners ---
document.getElementById('msgInput').oninput = (e) => {
    const el = e.target;
    el.style.height = 'auto';
    el.style.height = (el.scrollHeight) + 'px';
    
    const len = el.value.length;
    const cnt = document.getElementById('charCount');
    if (cnt) {
        cnt.textContent = `${len}/500`;
        cnt.classList.remove('limit-near', 'limit-reached');
        if (len >= 500) cnt.classList.add('limit-reached');
        else if (len >= 450) cnt.classList.add('limit-near');
    }
};

document.getElementById('msgInput').onkeydown = (e) => { 
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMsg();
    }
};
document.getElementById('btnSend').onclick = sendMsg;

document.getElementById('mediaInput').onchange = function() {
    if (this.files.length + uploadFiles.length > 10) {
        showToast("You can only send up to 10 images at a time!", "warning");
        this.value = '';
        return;
    }
    for (let f of this.files) {
        const isVideo = f.type.startsWith('video/');
        const maxMb = isVideo ? 50 : 10;
        if (f.size > maxMb * 1048576) { showToast(`"${f.name}" exceeds the ${maxMb}MB limit.`, "error"); continue; }
        uploadFiles.push(f);
    }
    renderPreviews(); this.value='';
};
function openDetails(id) {
    const modal = document.getElementById('detailsModal');
    const body = document.getElementById('detailsBody');

    modal.classList.add('active');
    body.innerHTML = `
        <div style="grid-column:1/-1; text-align:center; padding:3rem 0;">
            <div style="display:inline-block; width:32px; height:32px; border:3px solid #f1f5f9; border-top-color:#06A1A1; border-radius:50%; animation:spin .8s linear infinite;"></div>
            <p style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-top:1rem; letter-spacing:.1em;">Analyzing Workflow...</p>
        </div>`;

    api(`/public/api/chat/order_details.php?order_id=${id}`).then(data => {
        if (!data.success) {
            body.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:5rem; color:#ef4444; font-weight:800;">Access Denied: ${escapeHtml(data.error || 'Unknown')}</div>`;
            return;
        }

        const c = data.customer || {};
        const o = data.order || {};
        const it = data.items || [];
        const compact = window.matchMedia('(max-width: 768px)').matches;

        const actionUrl = o.manage_url || `${window.baseUrl}/staff/customizations.php?order_id=${o.order_id}`;
        const actionLabel = o.manage_url ? 'MANAGE ORDER' : 'MANAGE ORDER';

        let h = `
            <div class="details-sidebar" style="gap:1rem; ${compact ? 'border-right:none;border-bottom:1px solid #f1f5f9;padding:1rem;' : ''}">
                <div class="pf-mini-card" style="padding:.75rem;">
                    <div class="pf-spec-key" style="margin-bottom:6px; font-size:9px;">Customer Profile</div>
                    <div style="display:flex; align-items:center; gap:.75rem;">
                        <div style="width:52px; height:52px; border-radius:14px; background:#06A1A1; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:1rem; overflow:hidden; flex-shrink:0;">
                            ${c.profile_picture ? `<img src="${c.profile_picture}" style="width:100%;height:100%;object-fit:cover;" onerror="${PROFILE_IMAGE_ONERROR}">` : escapeHtml((c.full_name || '?').charAt(0).toUpperCase())}
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:.85rem; font-weight:900; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(c.full_name || 'Guest')}</div>
                            <div style="font-size:11px; font-weight:700; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(c.email || '')}</div>
                        </div>
                    </div>
                </div>

                <div class="pf-mini-card" style="padding:.75rem;">
                    <div class="pf-spec-key" style="margin-bottom:6px; font-size:9px;">Order Status</div>
                    <div style="display:flex; align-items:center; justify-content:space-between; background:#f8fafc; padding:6px 10px; border-radius:8px; border:1px solid #f1f5f9;">
                         <div style="font-size:10px; font-weight:900; color:#1e293b;">${escapeHtml(o.status || 'Pending')}</div>
                         <span style="width:10px; height:10px; border-radius:50%; background:${(o.status || '').toLowerCase().includes('complete') ? '#10b981' : '#3b82f6'};"></span>
                    </div>
                </div>

                <div class="pf-mini-card" style="padding:.75rem;">
                    <div class="pf-spec-key" style="margin-bottom:6px; font-size:9px;">Payment Summary</div>
                    <div style="display:flex; align-items:center; justify-content:space-between; background:#f8fafc; padding:6px 10px; border-radius:8px; border:1px solid #f1f5f9;">
                         <div style="font-size:10px; font-weight:900; color:#1e293b;">${escapeHtml(o.payment_status || 'Unverified')}</div>
                         <span style="width:10px; height:10px; border-radius:50%; background:${(o.payment_status || '').toLowerCase().includes('paid') ? '#10b981' : '#f59e0b'};"></span>
                    </div>
                </div>

                <div class="pf-mini-card" style="background:#0f172a; color:#fff; border:none; padding:.75rem; margin-bottom:0;">
                     <div class="pf-spec-key" style="color:#06A1A1; margin-bottom:2px; font-size:9px;">Total</div>
                     <div style="font-size:1.1rem; font-weight:900; line-height:1; margin-bottom:.75rem;">${o.total_amount || 'To be finalized'}</div>
                     <a href="${actionUrl}" style="display:block; text-align:center; background:#06A1A1; color:#fff; padding:8px; border-radius:10px; font-size:10px; font-weight:900; text-decoration:none;">${actionLabel}</a>
                </div>
            </div>

            <div class="details-main" style="${compact ? 'padding:1rem;' : 'padding-left:1rem;'}">
                <div class="details-main-heading" style="${compact ? 'padding:0 0 .85rem; border-bottom:1px solid #f1f5f9;' : ''}">Order Details</div>
                <div class="details-items">
                    ${it.length ? it.map(i => {
                        const specs = i.customization || {};
                        const entries = Object.entries(specs).filter(([k,v]) => v && v !== 'null' && typeof v !== 'object' && k !== 'service_type' && k !== 'branch_id');
                        let rawImg = i.service_image || i.design_url || '';
                        let displayImg = resolveAppUrl(rawImg, `${window.baseUrl}/public/assets/images/services/default.png`);
                        if (!displayImg) {
                            const placement = specs['print_placement'] || specs['placement'] || '';
                            if (placement.includes('Front Center')) displayImg = `${window.baseUrl}/public/assets/images/tshirt_replacement/Front Center Print.webp`;
                            if (placement.includes('Sleeve')) displayImg = `${window.baseUrl}/public/assets/images/tshirt_replacement/Sleeve Print.webp`;
                            if (placement.includes('Upper')) displayImg = `${window.baseUrl}/public/assets/images/tshirt_replacement/Back Upper Print.webp`;
                            if (specs.design_file) displayImg = `${window.baseUrl}/uploads/orders/${specs.design_file}`;
                        }

                        return `
                            <div class="detail-order-card">
                                <div class="detail-order-top">
                                    <div class="detail-order-thumb">
                                        <img src="${displayImg}" alt="${escapeHtml(i.product_name || 'Order Item')}" onerror="this.onerror=null; this.src='${window.baseUrl}/public/assets/images/services/default.png';">
                                    </div>
                                    <div class="detail-order-body">
                                        <div class="detail-order-summary">
                                            <div style="min-width:0; flex:1;">
                                                <div class="detail-order-title" title="${escapeHtml(i.product_name || 'Order Item')}">${escapeHtml(i.product_name || 'Order Item')}</div>
                                                <div class="detail-order-meta" style="margin-top:.65rem;">
                                                    <span class="detail-order-chip category">${escapeHtml(i.category || 'Service')}</span>
                                                    <span class="detail-order-chip">Units: ${i.quantity}</span>
                                                </div>
                                            </div>
                                            <div class="detail-order-price">
                                                <div class="pf-spec-key">Total</div>
                                                <strong>${i.subtotal || 'To be finalized'}</strong>
                                            </div>
                                        </div>
                                        <div class="pf-spec-grid" style="margin-top:0; gap:8px;">
                                            ${entries.map(([k,v]) => `
                                                <div class="pf-spec-box">
                                                    <div class="pf-spec-key" style="font-size:8px;">${escapeHtml(k.replace(/_/g,' ').replace('shirt ',''))}</div>
                                                    <div class="pf-spec-val" style="font-size:11px;">${escapeHtml(String(v))}</div>
                                                </div>
                                            `).join('')}
                                        </div>
                                    </div>
                                </div>
                            </div>`;
                    }).join('') : '<div style="text-align:center; padding:4rem; color:#cbd5e1; font-style:italic;">Order details are currently empty.</div>'}
                </div>
            </div>`;

        body.innerHTML = h;
    }).catch(err => {
        body.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:5rem; color:#ef4444; font-weight:800;">System Error: ${escapeHtml(err.message)}</div>`;
    });
}

function closeDetailsModal() { 
    const modal = document.getElementById('detailsModal');
    modal.classList.remove('active'); 
}

// --- Media Gallery ---
let activeGalleryTab = 'image';
let sharedMedia = [];

function toggleMediaGallery(show) {
    const el = document.getElementById('mediaGallery');
    if (!el) return;
    if (show) {
        el.classList.add('active');
        loadMedia();
    } else {
        el.classList.remove('active');
    }
}

function switchGalleryTab(tab) {
    activeGalleryTab = tab;
    document.getElementById('gTabImages').classList.toggle('active', tab === 'image');
    document.getElementById('gTabVideos').classList.toggle('active', tab === 'video');
    renderMediaGrid();
}

async function loadMedia() {
    if (!activeId) return;
    const grid = document.getElementById('mediaGrid');
    if (!grid) return;
    
    try {
        const data = await api(`/public/api/chat/fetch_media.php?order_id=${activeId}`);
        if (data.success) {
            sharedMedia = data.media || [];
            renderMediaGrid();
        } else {
            throw new Error(data.error || 'Failed to fetch media');
        }
    } catch (e) {
        grid.innerHTML = '<div class="col-span-3 text-center py-10 text-red-400 text-xs">Error loading media</div>';
    }
}

function renderMediaGrid() {
    const grid = document.getElementById('mediaGrid');
    if (!grid) return;
    const filtered = sharedMedia.filter(m => m.file_type === activeGalleryTab);
    
    if (filtered.length === 0) {
        grid.innerHTML = `
        <div style="grid-column: span 3; padding:5rem 1rem; text-align:center; color:#94a3b8;">
            <i class="bi bi-file-earmark-image" style="font-size:2.5rem; display:block; margin-bottom:1rem; opacity:0.3;"></i>
            <div style="font-size:0.85rem; font-weight:700;">No shared ${activeGalleryTab}s</div>
            <div style="font-size:0.7rem; margin-top:4px; font-weight:600; opacity:0.7;">Shared ${activeGalleryTab}s from this conversation will appear here.</div>
        </div>`;
        return;
    }
    
    grid.innerHTML = filtered.map(m => {
        if (m.file_type === 'image') {
            return `<div class="gallery-item" onclick="zoomImg('${m.message_file.replace(/'/g, "\\'")}')">
                <img src="${m.message_file}" loading="lazy">
            </div>`;
        } else {
            return `<div class="gallery-item" onclick="zoomVideo('${m.message_file.replace(/'/g, "\\'")}')">
                <video src="${m.message_file}#t=0.1" preload="metadata" muted
                    onerror="this.parentElement.innerHTML = '<div style=\'height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#f1f5f9; color:#94a3b8; font-size:0.7rem;\'><i class=\'bi bi-camera-video-off\' style=\'font-size:1.2rem;\'></i><span>Unavailable</span></div>';">
                </video>
                <div class="vid-icon"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></div>
            </div>`;
        }
    }).join('');
}

// --- Voice Recording Logic with Waveform ---
let mediaRecorder;
let audioChunks = [];
let timerInterval;
const MAX_DURATION = 60; // seconds

const startBtn = document.getElementById("startRecord");
const stopBtn = document.getElementById("stopRecord");
const status = document.getElementById("recordStatus");
const timerDisplay = document.getElementById("timer");
const inputBar = document.getElementById("inputContainer");
const cancelBtn = document.getElementById("cancelRecord");

let audioCtx;
let analyser;
let source;
let animationId;
let previewAudio;

function startVoiceVisualizer(stream) {
    const { canvas, ctx } = getCanvasContext("recordingCanvasMain");
    if (!canvas || !ctx) return;
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    analyser = audioCtx.createAnalyser();
    source = audioCtx.createMediaStreamSource(stream);
    source.connect(analyser);
    analyser.fftSize = 256;

    const bufferLength = analyser.frequencyBinCount;
    const dataArray = new Uint8Array(bufferLength);

    function draw() {
        if (!analyser) return;
        analyser.getByteFrequencyData(dataArray);

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const barWidth = (canvas.width / bufferLength) * 2.5;
        let x = 0;

        for (let i = 0; i < bufferLength; i++) {
            const barHeight = (dataArray[i] / 255) * canvas.height;
            ctx.fillStyle = '#ef4444';
            ctx.fillRect(x, canvas.height - barHeight, barWidth, barHeight);
            x += barWidth + 1;
        }
        animationId = requestAnimationFrame(draw);
    }
    draw();
}

function stopVoiceVisualizer() {
    if (animationId) cancelAnimationFrame(animationId);
    animationId = null;
    closeAudioContextSafely(audioCtx);
    audioCtx = null;
    analyser = null;
    source = null;
}

async function drawStaticWaveform(blob, canvasId, color = '#64748b') {
    if (!blob || !blob.size) return;
    const { canvas, ctx } = getCanvasContext(canvasId);
    if (!canvas || !ctx) return;

    let previewContext = null;
    try {
        const arrayBuffer = await blob.arrayBuffer();
        if (!arrayBuffer.byteLength) return;
        previewContext = new (window.AudioContext || window.webkitAudioContext)();
        const audioBuffer = await previewContext.decodeAudioData(arrayBuffer);
        const rawData = audioBuffer.getChannelData(0);
        const samples = 70;
        const blockSize = Math.max(1, Math.floor(rawData.length / samples));
        const filteredData = [];
        for (let i = 0; i < samples; i++) {
            let blockStart = blockSize * i;
            let sum = 0;
            for (let j = 0; j < blockSize; j++) {
                sum += Math.abs(rawData[blockStart + j] || 0);
            }
            filteredData.push(sum / blockSize);
        }
        if (!filteredData.length) return;

        const peak = Math.max(...filteredData) || 1;
        const multiplier = peak ? Math.pow(peak, -1) : 1;
        const normalizedData = filteredData.map(n => n * multiplier);

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const width = canvas.width / samples;
        for (let i = 0; i < samples; i++) {
            const height = normalizedData[i] * canvas.height;
            ctx.fillStyle = color;
            ctx.fillRect(i * width, (canvas.height - height) / 2, width - 1, height);
        }
    } catch (e) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    } finally {
        closeAudioContextSafely(previewContext);
    }
}

/**
 * HOLD TO RECORD LOGIC (MESSENGER STYLE)
 */
function initRecordingEvents() {
    const micBtn = document.getElementById("micBtnMain");
    if (!micBtn || micBtn.dataset.pfRecordingInit === '1') return;
    micBtn.dataset.pfRecordingInit = '1';

    const start = (e) => { e.preventDefault(); window.startRecording(); };
    if (window.PointerEvent) {
        micBtn.addEventListener("pointerdown", start);
    } else {
        micBtn.addEventListener("mousedown", start);
        micBtn.addEventListener("touchstart", start, { passive: false });
    }

    if (!window.__pfStaffChatRecordingReleaseBound) {
        window.__pfStaffChatRecordingReleaseBound = true;
        const stop = () => { if (mediaRecorder && mediaRecorder.state === "recording") window.stopRecording(); };
        if (window.PointerEvent) {
            window.addEventListener("pointerup", stop);
            window.addEventListener("pointercancel", stop);
        } else {
            window.addEventListener("mouseup", stop);
            window.addEventListener("touchend", stop);
            window.addEventListener("touchcancel", stop);
        }
        window.addEventListener("blur", stop);
        document.addEventListener("visibilitychange", () => {
            if (document.hidden) stop();
        });
    }
}

function bindMobileMessageHold(row) {
    if (!row || row.dataset.mobileHoldBound === '1' || !window.matchMedia('(max-width: 1023px)').matches) return;
    row.dataset.mobileHoldBound = '1';
    let holdTimer = null;
    let holdTriggered = false;
    const target = row.querySelector('.bubble, .voice-bubble-player, .call-log-bubble, .order-update-bubble');
    if (!target) return;

    const startHold = (event) => {
        if (event.target.closest('.msg-action-bar, .reaction-picker, .m-more-menu, .reaction-display, a, button, audio, video')) return;
        holdTriggered = false;
        clearTimeout(holdTimer);
        holdTimer = setTimeout(() => {
            holdTriggered = true;
            closeAllMenus();
            setActiveMessageRow(row);
        }, 450);
    };

    const clearHold = () => clearTimeout(holdTimer);

    target.addEventListener('touchstart', startHold, { passive: true });
    target.addEventListener('touchend', clearHold);
    target.addEventListener('touchcancel', clearHold);
    target.addEventListener('touchmove', clearHold);
    target.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        closeAllMenus();
        setActiveMessageRow(row);
    });

    target.addEventListener('click', (event) => {
        if (event.target.closest('a, button, audio, video')) return;
        if (!window.matchMedia('(max-width: 1023px)').matches) return;
        if (row.classList.contains('has-active-menu')) return;
        closeAllMenus();
        setActiveMessageRow(row);
        event.preventDefault();
        event.stopPropagation();
    }, true);

    row.addEventListener('click', (event) => {
        if (holdTriggered) {
            event.preventDefault();
            event.stopPropagation();
            holdTriggered = false;
        }
    }, true);
}

window.addEventListener('resize', () => {
    const activeRow = document.querySelector('.bubble-row.has-active-menu');
    if (!activeRow) return;
    positionMobileActionBar(activeRow);
});

document.getElementById('messagesArea')?.addEventListener('scroll', () => {
    const activeRow = document.querySelector('.bubble-row.has-active-menu');
    if (!activeRow) return;
    
    positionMobileActionBar(activeRow);
    
    // Also update any open floating menus
    const picker = document.querySelector('.reaction-picker.active');
    if (picker) {
        const msgId = picker.id.replace('picker-', '');
        const trigger = activeRow.querySelector('.bi-emoji-smile')?.parentElement;
        if (trigger) positionFloatingMenu(picker, trigger, { preferred: 'top', mobileWidth: 300, gap: 12 });
    }
    const moreMenu = document.querySelector('.m-more-menu.active');
    if (moreMenu) {
        const trigger = activeRow.querySelector('.bi-three-dots')?.parentElement;
        if (trigger) positionFloatingMenu(moreMenu, trigger, { preferred: 'bottom', mobileWidth: 190, gap: 10 });
    }
}, { passive: true });

window.startRecording = async function() {
    if (mediaRecorder && mediaRecorder.state === "recording") return;
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        showToast("Microphone access denied", "error");
        return;
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const recorderOptions = MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? { mimeType: 'audio/webm;codecs=opus' }
            : undefined;
        mediaRecorder = recorderOptions ? new MediaRecorder(stream, recorderOptions) : new MediaRecorder(stream);
        mediaRecorder.start(250);
        audioChunks = [];
        let seconds = 0;

        const recordStatus = document.getElementById("recordStatusMain");
        const inputBar = document.getElementById("inputBarMain");
        const micBtn = document.getElementById("micBtnMain");
        const micIcon = document.getElementById("micIconMain");
        if (recordStatus) recordStatus.classList.remove("hidden");
        if (inputBar) inputBar.classList.add("hidden");
        if (micBtn) micBtn.classList.add("recording");
        if (micIcon) micIcon.className = "bi bi-stop-fill";

        timerInterval = setInterval(() => {
            seconds++;
            const timer = document.getElementById("timerMain");
            if (timer) timer.textContent = formatAudioTime(seconds);
            if (seconds >= MAX_DURATION) window.stopRecording();
        }, 1000);

        mediaRecorder.ondataavailable = e => {
            if (e.data && e.data.size > 0) audioChunks.push(e.data);
        };
        mediaRecorder.onstop = showVoicePreview;
        startVoiceVisualizer(stream);
    } catch (e) {
        showToast("Microphone access denied", "error");
    }
};

window.stopRecording = function() {
    if (mediaRecorder && mediaRecorder.state === "recording") {
        mediaRecorder.stop();
        mediaRecorder.stream.getTracks().forEach(track => track.stop());
    }
    stopVoiceVisualizer();
    clearInterval(timerInterval);
    const recordStatus = document.getElementById("recordStatusMain");
    const micBtn = document.getElementById("micBtnMain");
    const micIcon = document.getElementById("micIconMain");
    if (recordStatus) recordStatus.classList.add("hidden");
    if (micBtn) micBtn.classList.remove("recording");
    if (micIcon) micIcon.className = "bi bi-mic";
};

function cancelRecording() {
    if (mediaRecorder && mediaRecorder.state === "recording") {
        mediaRecorder.onstop = null;
        mediaRecorder.stop();
        mediaRecorder.stream.getTracks().forEach(track => track.stop());
    }
    if (previewAudio) { previewAudio.pause(); previewAudio = null; }
    pendingVoiceBlob = null;
    const previewArea = document.getElementById("voicePreviewAreaMain");
    const inputBar = document.getElementById("inputBarMain");
    const micBtn = document.getElementById("micBtnMain");
    if (previewArea) previewArea.style.display = 'none';
    if (inputBar) inputBar.classList.remove("hidden");
    if (micBtn) micBtn.style.display = 'flex';
    stopRecording();
}

/* Custom Voice Player Logic */
function toggleVoicePlayer(id, src) {
    const audio = document.getElementById(`v-audio-${id}`);
    const icon = document.getElementById(`v-icon-${id}`);
    if (!audio || !icon) return;
    
    document.querySelectorAll('audio').forEach(a => {
        if (a.id !== `v-audio-${id}`) {
            a.pause();
            const sid = a.id.replace('v-audio-', '');
            const sicon = document.getElementById(`v-icon-${sid}`);
            if (sicon) {
                sicon.classList.remove('bi-pause-fill');
                sicon.classList.add('bi-play-fill');
            }
        }
    });

    if (audio.paused) {
        audio.play().catch(() => {});
        icon.classList.remove('bi-play-fill');
        icon.classList.add('bi-pause-fill');
    } else {
        audio.pause();
        icon.classList.remove('bi-pause-fill');
        icon.classList.add('bi-play-fill');
    }
}

function updateVoiceProgress(id) {
    const audio = document.getElementById(`v-audio-${id}`);
    const canvas = document.getElementById(`v-canvas-${id}`);
    const dur = document.getElementById(`v-dur-${id}`);
    if (!audio || !canvas) return;
    if (!Number.isFinite(audio.duration) || audio.duration <= 0 || !waveformCache[audio.src]) return;
    const percent = audio.currentTime / audio.duration;
    if (dur) dur.textContent = formatAudioTime(audio.currentTime);
    drawWaveformWithProgress(canvas, audio, percent);
}

const waveformCache = {};

async function drawWaveformFromUrl(url, canvasId, color, msgId = null) {
    if (!url) return;
    if (waveformCache[url]) {
        drawRawToCanvas(canvasId, waveformCache[url], color);
        return;
    }
    let waveformContext = null;
    try {
        const response = await fetch(url, { cache: 'no-store' });
        if (!response.ok) return;
        const arrayBuffer = await response.arrayBuffer();
        if (!arrayBuffer.byteLength) return;
        waveformContext = new (window.AudioContext || window.webkitAudioContext)();
        const audioBuffer = await waveformContext.decodeAudioData(arrayBuffer);
        const rawData = audioBuffer.getChannelData(0); 
        const samples = 60; 
        const blockSize = Math.max(1, Math.floor(rawData.length / samples));
        const filteredData = [];
        for (let i = 0; i < samples; i++) {
            let blockStart = blockSize * i;
            let sum = 0;
            for (let j = 0; j < blockSize; j++) {
                sum = sum + Math.abs(rawData[blockStart + j] || 0);
            }
            filteredData.push(sum / blockSize);
        }
        if (!filteredData.length) return;
        const peak = Math.max(...filteredData) || 1;
        const multiplier = peak ? Math.pow(peak, -1) : 1;
        const normalizedData = filteredData.map(n => n * multiplier);
        waveformCache[url] = normalizedData;
        drawRawToCanvas(canvasId, normalizedData, color);
        if (msgId) {
            const dur = document.getElementById(`v-dur-${msgId}`);
            if (dur && audioBuffer.duration > 0) dur.textContent = formatAudioTime(audioBuffer.duration);
        }
    } catch(e) {
        return;
    } finally {
        closeAudioContextSafely(waveformContext);
    }
}

function drawRawToCanvas(canvasId, data, color, progress = 0) {
    if (!data || !data.length) return;
    const { canvas, ctx } = getCanvasContext(canvasId);
    if (!canvas || !ctx) return;
    const samples = data.length;
    const width = canvas.width / samples;
    
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    for (let i = 0; i < samples; i++) {
        const height = data[i] * canvas.height;
        const isPlayed = (i / samples) < progress;
        ctx.fillStyle = isPlayed ? '#0ea5e9' : color;
        ctx.fillRect(i * width, (canvas.height - height) / 2, width - 1, height);
    }
}

function drawWaveformWithProgress(canvas, audio, progress) {
    const url = audio.src;
    const data = waveformCache[url];
    if (!data) return;
    const row = canvas.closest('.bubble-row');
    const isSelf = row ? row.classList.contains('self') : false;
    drawRawToCanvas(canvas.id, data, isSelf ? 'rgba(255,255,255,0.7)' : '#64748b', progress);
}

function resetVoicePlayer(id) {
    const icon = document.getElementById(`v-icon-${id}`);
    const canvas = document.getElementById(`v-canvas-${id}`);
    const dur = document.getElementById(`v-dur-${id}`);
    const audio = document.getElementById(`v-audio-${id}`);
    if (icon) { icon.classList.remove('bi-pause-fill'); icon.classList.add('bi-play-fill'); }
    if (canvas && audio) drawWaveformWithProgress(canvas, audio, 0);
    if (dur && audio) dur.textContent = formatAudioTime(audio.duration);
}

function initVoiceDuration(id) {
    const audio = document.getElementById(`v-audio-${id}`);
    const dur = document.getElementById(`v-dur-${id}`);
    if (!audio || !dur) return;
    if (Number.isFinite(audio.duration) && audio.duration > 0) {
        dur.textContent = formatAudioTime(audio.duration);
    } else {
        // WebM files from MediaRecorder often lack duration metadata
        // Fallback: measure by playing to end (silent)
        const originalVolume = audio.volume;
        audio.volume = 0;
        audio.play().then(() => {
            audio.addEventListener('durationchange', function handler() {
                if (Number.isFinite(audio.duration) && audio.duration > 0) {
                    dur.textContent = formatAudioTime(audio.duration);
                    audio.pause();
                    audio.currentTime = 0;
                    audio.volume = originalVolume;
                    audio.removeEventListener('durationchange', handler);
                }
            });
        }).catch(() => {
            dur.textContent = '—';
            audio.volume = originalVolume;
        });
    }
}

function seekVoice(id, event) {
    const audio = document.getElementById(`v-audio-${id}`);
    if (!audio || !Number.isFinite(audio.duration) || audio.duration <= 0) return;
    const container = event.currentTarget;
    const rect = container.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const percent = x / rect.width;
    audio.currentTime = percent * audio.duration;
}

function handleVoiceAudioError(id) {
    const dur = document.getElementById(`v-dur-${id}`);
    if (dur) dur.textContent = '0:00';
}

function formatAudioTime(seconds) {
    const n = Number(seconds);
    if (!Number.isFinite(n) || n < 0) return '0:00';
    const min = Math.floor(n / 60);
    const sec = Math.floor(n % 60);
    return `${min}:${sec.toString().padStart(2, '0')}`;
}

let pendingVoiceBlob = null;
let isSendingMessage = false;

function sendMsg() {
    const btn = document.getElementById('btnSend');
    const input = document.getElementById('msgInput');
    const txt = input.value.trim();
    
    if (txt.length > 500) {
        showToast("Message cannot exceed 500 characters.", "warning");
        return;
    }

    if (pendingVoiceBlob) {
        sendAudio();
        return;
    }

    if ((!txt && !uploadFiles.length) || isSendingMessage || !activeId || (btn && btn.disabled)) return;

    // Visual feedback
    isSendingMessage = true;
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<svg class="animate-spin h-5 w-5 text-white" xml:space="preserve" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
    }

    const fd = new FormData();
    fd.append('order_id', activeId);
    if (replyToMessageId) fd.append('reply_id', replyToMessageId);
    if (txt) fd.append('message', txt);
    uploadFiles.forEach(f => fd.append('image[]', f));
    
    api('/public/api/chat/send_message.php', 'POST', fd)
        .then(r => {
            if (r.success) {
                input.value = '';
                uploadFiles = [];
                if (document.getElementById('imgPreviewArea')) document.getElementById('imgPreviewArea').style.display = 'none';
                cancelReply();
                loadMsgs();
                
                // Reset textarea height
                input.style.height = 'auto';
            } else {
                showToast(r.error || 'Failed to send message', "error");
            }
        })
        .catch(err => {
            showToast(err?.message || 'Failed to send message', "error");
        })
        .finally(() => {
            isSendingMessage = false;
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send-fill"></i>';
            }
            input.focus();
        });
}

function showVoicePreview() {
    pendingVoiceBlob = new Blob(audioChunks, { type: 'audio/webm' });
    if (!pendingVoiceBlob || pendingVoiceBlob.size < 100) return;

    const previewArea = document.getElementById("voicePreviewAreaMain");
    const inputBar = document.getElementById("inputBarMain");
    if (previewArea) previewArea.style.display = 'flex';
    if (inputBar) inputBar.classList.add("hidden");

    drawStaticWaveform(pendingVoiceBlob, 'previewWaveformCanvasMain', '#0a2530');
    
    const tempAudio = new Audio(URL.createObjectURL(pendingVoiceBlob));
    tempAudio.onloadedmetadata = () => {
        const duration = document.getElementById("previewDurationMain");
        if (duration) duration.textContent = formatAudioTime(tempAudio.duration);
    };
    tempAudio.onerror = () => {
        const duration = document.getElementById("previewDurationMain");
        if (duration) duration.textContent = '0:00';
    };
}

function togglePreviewPlayback() {
    if (!pendingVoiceBlob) return;
    const icon = document.getElementById("previewPlayIconMain");
    if (!icon) return;
    
    if (!previewAudio) {
        previewAudio = new Audio(URL.createObjectURL(pendingVoiceBlob));
        previewAudio.onended = () => {
            icon.className = "bi bi-play-fill";
            previewAudio = null;
        };
    }

    if (previewAudio.paused) {
        previewAudio.play().catch(() => {});
        icon.className = "bi bi-pause-fill";
    } else {
        previewAudio.pause();
        icon.className = "bi bi-play-fill";
    }
}

function sendAudio() {
    if (!pendingVoiceBlob) return;
    const btn = document.getElementById('btnSend');
    const oldIcon = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<i class='bi bi-hourglass-split animate-spin'></i>`;

    const fd = new FormData();
    fd.append("voice", pendingVoiceBlob);
    fd.append("order_id", activeId);
    if (replyToMessageId) fd.append('reply_id', replyToMessageId);

    api('/public/api/chat/send_voice.php', 'POST', fd).then(res => {
        if (res.success) {
            cancelRecording();
            cancelReply();
            loadMsgs();
        } else showToast(res.error || "Failed to send voice", "error");
    }).finally(() => {
        btn.disabled = false;
        btn.innerHTML = oldIcon;
    });
}

function formatAudioTime(s) {
    const n = Number(s);
    if (!Number.isFinite(n) || n < 0) return '0:00';
    const m = Math.floor(n / 60);
    const rs = Math.floor(n % 60);
    return `${m}:${rs < 10 ? '0' : ''}${rs}`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function safeBase64Encode(str) {
    return btoa(unescape(encodeURIComponent(str || '')));
}

function safeBase64Decode(b64) {
    try {
        return decodeURIComponent(escape(atob(b64)));
    } catch(e) { return ''; }
}

function showToast(message, type = 'error', duration = 4000) {
    let container = document.getElementById('staff-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'staff-toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `staff-toast-item toast-${type}`;
    let icon = 'bi-exclamation-circle-fill';
    if (type === 'success') icon = 'bi-check-circle-fill';
    if (type === 'warning') icon = 'bi-exclamation-triangle-fill';
    toast.innerHTML = `
        <div class="toast-icon"><i class="bi ${icon}"></i></div>
        <div class="toast-content">
            <div class="toast-title" style="color: #0a2530 !important;">${type === 'error' ? 'Oops!' : (type === 'success' ? 'Success' : 'Notice')}</div>
            <div class="toast-message" style="color: #64748b !important;">${message}</div>
        </div>
        <div class="toast-progress"><div class="toast-progress-bar"></div></div>
    `;
    container.appendChild(toast);
    const progressBar = toast.querySelector('.toast-progress-bar');
    setTimeout(() => {
        progressBar.style.transitionDuration = `${duration}ms`;
        progressBar.style.width = '100%';
    }, 10);
    const removeToast = () => {
        toast.classList.add('exit');
        setTimeout(() => toast.remove(), 300);
    };
    const autoRemove = setTimeout(removeToast, duration);
    toast.onclick = () => { clearTimeout(autoRemove); removeToast(); };
}

function formatTime(d) {
    if (!d) return '...';
    try {
        // Safe string check before replace
        const dateStr = String(d);
        if (dateStr.includes('AM') || dateStr.includes('PM')) return dateStr;
        const diff = (Date.now() - new Date(dateStr.replace(/-/g,'/'))) / 1000;
        if (isNaN(diff)) return '...';
        if (diff < 60) return 'now';
        if (diff < 3600) return Math.floor(diff/60) + 'm';
        if (diff < 86400) return Math.floor(diff/3600) + 'h';
        return Math.floor(diff/86400) + 'd';
    } catch(e) { return '...'; }
}

function zoomImg(src) {
    const lb = document.getElementById('staffLightbox');
    const img = document.getElementById('staffLightboxImg');
    const video = document.getElementById('staffLightboxVideo');
    const down = document.getElementById('staffLightboxDownload');
    
    if (lb && img && video) {
        lb.style.display = 'flex';
        img.style.display = 'block';
        img.src = src;
        video.style.display = 'none';
        video.pause();
        if (down) down.href = src;
    }
}

function zoomVideo(src) {
    const lb = document.getElementById('staffLightbox');
    const img = document.getElementById('staffLightboxImg');
    const video = document.getElementById('staffLightboxVideo');
    const down = document.getElementById('staffLightboxDownload');
    
    if (lb && img && video) {
        lb.style.display = 'flex';
        img.style.display = 'none';
        video.style.display = 'block';
        video.src = src;
        video.play();
        if (down) down.href = src;
    }
}

function closeLightbox() {
    const lb = document.getElementById('staffLightbox');
    const video = document.getElementById('staffLightboxVideo');
    if (lb) lb.style.display = 'none';
    if (video) { video.pause(); video.src = ''; }
}

function scrollToBottom(smooth = true, force = false) {
    const box = document.getElementById('messagesArea');
    if (!box) return;
    
    if (!smooth || force) {
        // Instant jump
        box.scrollTop = box.scrollHeight;
        return;
    }

    const threshold = 100;
    const isNearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < threshold;
    if (isNearBottom) {
        box.scrollTo({ top: box.scrollHeight, behavior: 'smooth' });
    }
}

function updateStaffSeenIndicators(lastSeenId) {
    if (!lastSeenId) return;
    document.querySelectorAll('.seen-wrapper').forEach(el => el.innerHTML = '');
    
    // Reverse find the last self-sent message that was seen
    const allRows = [...document.querySelectorAll('.bubble-row.self')];
    let latestSeenRow = null;
    for (let i = allRows.length - 1; i >= 0; i--) {
        const id = parseInt(allRows[i].id.replace('ms-', ''));
        if (id <= lastSeenId) {
            latestSeenRow = allRows[i];
            break;
        }
    }

    if (latestSeenRow) {
        const wrapper = latestSeenRow.querySelector('.seen-wrapper');
        if (wrapper) {
            if (partnerAvatarUrl) {
                wrapper.innerHTML = `<img src="${partnerAvatarUrl}" class="seen-avatar" title="Seen by Customer" onerror="${PROFILE_IMAGE_ONERROR}">`;
            } else {
                wrapper.innerHTML = '<span style="font-size:10px; color:#94a3b8; font-weight:700;">✓ Seen</span>';
            }
        }
    }
}

function initStaffChatPage() {
    if (window.__pfStaffChatInitialized) return;
    window.__pfStaffChatInitialized = true;

    if (isMobileChatView()) {
        showConversationList();
    } else {
        updateMobileChatChrome();
    }

    initRecordingEvents();
    loadConvs();
    listId = setInterval(loadConvs, 10000);

    let searchTimeout;
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                loadConvs();
            }, 300);
        });
    }

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeLightbox();
            closeDetailsModal();
            closeForwardModal();
        }
    });

    window.addEventListener('resize', () => {
        if (isMobileChatView()) {
            if (activeId && window.staffUiOpened) showConversationThread();
            else showConversationList();
        } else {
            const app = document.getElementById('chatApp');
            if (app) {
                app.classList.remove('mobile-list-view', 'mobile-thread-view');
            }
            updateMobileChatChrome();
        }
    });

    // Initialize call system immediately so socket is ready before first call
    if (window.PFCall && typeof window.PFCall.startCall === 'function') {
        initCallSystem();
    } else {
        window.addEventListener('PFCallGlobalReady', initCallSystem, { once: true });
    }
    
    // Call Connection Status UI Sync
    const updatePFStatusUI = () => {
        const el = document.getElementById('pfCallStatus');
        if (!el) return;
        const dot = el.querySelector('.status-dot');
        const txt = el.querySelector('.status-text');
        
        const isConnected = !!(window.PFCall && window.PFCall.isSocketConnected);
        if (isConnected) {
            dot.style.background = '#22c55e';
            txt.textContent = 'Connected';
            txt.style.color = '#22c55e';
        } else {
            dot.style.background = '#ef4444';
            txt.textContent = 'Offline';
            txt.style.color = '#ef4444';
        }
    };
    window.addEventListener('PFCallConnected', updatePFStatusUI);
    window.addEventListener('PFCallDisconnected', updatePFStatusUI);
    window.addEventListener('PFCallGlobalReady', updatePFStatusUI);
    setTimeout(updatePFStatusUI, 2000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStaffChatPage, { once: true });
} else {
    initStaffChatPage();
}

// Global click handler for clickable order cards in chat
document.addEventListener('click', function(e) {
    const card = e.target.closest('.order-card');
    if (!card) return;

    const orderId = card.dataset.orderId;
    console.log("Clicked order:", orderId);

    openOrderModal(orderId);
});
</script>
</body>
</html>


