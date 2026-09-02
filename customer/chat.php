<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Customer');

// Load config first — production needs empty BASE_URL, localhost needs /printflow
if (!defined('BASE_URL') && file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
if (!defined('BASE_URL')) define('BASE_URL', '');

$user_id    = get_user_id();
$user_name  = $_SESSION['user_name'] ?? 'Customer';
$user_avatar = $_SESSION['user_avatar'] ?? '';
$initial_order_id = $_GET['order_id'] ?? null;

$page_title = 'My Messages - PrintFlow';
$use_customer_css = true;
$is_chat_page = true;
$disable_turbo = true;
$hide_chatbot = true;
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/assets/css/chat_http.css?v=<?php echo filemtime(__DIR__ . '/../public/assets/css/chat_http.css'); ?>">

<style>
    :root {
        --pf-navy: #f8fafc;
        --pf-navy-card: #ffffff;
        --pf-cyan: #0a2530;
        --pf-cyan-glow: rgba(10,37,48,0.08);
        --pf-border: #e2e8f0;
        --pf-dim: #64748b;
        --pf-self-bubble: linear-gradient(135deg,#0a2530,#0f172a);
    }

    /* Layout — fill viewport below the site header */
    .hidden { display: none !important; }
    body.chat-page { overflow: hidden !important; background: var(--pf-navy); }
    body.chat-page #main-content { padding: 0 !important; min-height: 0 !important; overflow: hidden !important; display: flex; flex-direction: column; }
    body.chat-page #main-header { position: sticky; top: 0; z-index: 100; }
    body.chat-page .text-white { color: #0f172a !important; }

    /* Prevent layout shift from scrollbar appearance/disappearance */
    html { overflow-y: scroll; }
    body.chat-page { overflow-y: hidden !important; }

    #chat-root {
        display: grid;
        grid-template-columns: 350px 1fr;
        height: 100%;
        overflow: hidden;
        background: var(--pf-navy);
        font-family: 'Inter', sans-serif;
        border: 1px solid var(--pf-border);
        border-radius: 24px;
        box-shadow: 0 20px 45px rgba(15,23,42,0.08);
    }

    .chat-shell {
        width: 100%;
        max-width: 1100px;
        margin: 0 auto;
        padding: 1.25rem 1rem;
        height: calc(100vh - 65px);
        box-sizing: border-box;
    }

    /* ── Sidebar ── */
    .cs-sidebar { display:flex; flex-direction:column; background:#f8fafc; border-right:1px solid var(--pf-border); overflow:hidden; }
    .cs-sidebar-top { padding:1.25rem 1rem; border-bottom:1px solid var(--pf-border); flex-shrink:0; }
    .cs-sidebar-top h2 { font-size:1.1rem; font-weight:800; color:#0f172a; margin:0 0 .9rem; }
    .cs-search { position:relative; }
    .cs-search i { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:#94a3b8; opacity:.8; }
    .cs-search input { width:100%; box-sizing:border-box; background:#fff; border:1px solid var(--pf-border); border-radius:12px; padding:.55rem .75rem .55rem 2.25rem; font-size:.85rem; color:#0f172a; outline:none; transition:.2s; }
    .cs-search input:focus { border-color:var(--pf-cyan); box-shadow:0 0 0 3px rgba(10,37,48,0.08); }

    .cs-tabs { display:flex; gap:6px; padding:.75rem 1rem; border-bottom:1px solid var(--pf-border); flex-shrink:0; }
    .cs-tab { flex:1; text-align:center; padding:.4rem 0; border-radius:8px; font-size:.75rem; font-weight:700; color:var(--pf-dim); cursor:pointer; background:transparent; border:none; transition:.2s; }
    .cs-tab.active { background:#fff; color:var(--pf-cyan); border:1px solid var(--pf-border); box-shadow:0 2px 8px rgba(15,23,42,0.06); }

    .cs-list { flex:1; overflow-y:auto; padding:.5rem; }
    .cs-list::-webkit-scrollbar { width:3px; }
    .cs-list::-webkit-scrollbar-thumb { background:var(--pf-border); border-radius:10px; }

    .conv-card { display:flex; gap:11px; padding:12px 14px; border-radius:14px; margin-bottom:3px; cursor:pointer; border:1px solid transparent; transition:.18s; }
    .conv-card:hover { background:#f1f5f9; }
    .conv-card.active { background:#fff; border-color:var(--pf-border); box-shadow:0 4px 12px rgba(15,23,42,0.06); }
    .conv-av { width:44px; height:44px; border-radius:11px; background:#f1f5f9; border:1px solid var(--pf-border); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.95rem; color:var(--pf-cyan); flex-shrink:0; overflow:hidden; }
    .conv-av img { width:100%; height:100%; object-fit:cover; }
    .conv-info { flex:1; min-width:0; }
    .conv-top { display:flex; justify-content:space-between; align-items:baseline; gap:4px; }
    .conv-name { font-size:.88rem; font-weight:700; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .conv-time { font-size:.65rem; color:#94a3b8; font-weight:700; flex-shrink:0; }
    .conv-sub { font-size:.68rem; color:var(--pf-cyan); font-weight:800; text-transform:uppercase; letter-spacing:.04em; margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; opacity:.9; }
    .conv-prev { font-size:.75rem; color:var(--pf-dim); margin-top:4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; opacity:.9; }

    /* ── Main Chat Window ── */
    .cs-window { display:flex; flex-direction:column; overflow:hidden; background:#fff; position:relative; }
    .cs-header { display:flex; align-items:center; gap:12px; padding:1rem 1.5rem; border-bottom:1px solid var(--pf-border); background:#fff; z-index:20; flex-shrink:0; }
    .cs-header-info { flex:1; min-width:0; }
    .cs-header-name { font-size:1rem; font-weight:800; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px; min-width:0; }
    .cs-header-name #hName { display:block; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .cs-header-name #hOnline { flex-shrink:0; }
    .cs-header-meta { font-size:.75rem; color:var(--pf-dim); font-weight:700; opacity:.9; margin:0; }

    .cs-h-actions { display: flex; gap: 8px; }
    .cs-mobile-back {
        display: none;
        width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--pf-border);
        background: #fff; color: #64748b; align-items:center; justify-content:center; cursor:pointer; font-size: 1rem;
        transition: .2s; flex-shrink: 0;
    }
    .cs-mobile-back:hover { background:#f8fafc; color:#0f172a; }
    .cs-h-btn {
        width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--pf-border);
        background: #fff; color: #64748b;
        display: flex; align-items:center; justify-content:center; cursor:pointer; font-size: 1rem; transition:.2s;
    }
    .cs-h-btn:hover { background: #f8fafc; color: #0f172a; }

    .h-menu-wrap { position:relative; }
    .h-dropdown { display:none; position:absolute; top:calc(100% + 8px); right:0; background:#fff; border:1px solid var(--pf-border); border-radius:13px; width:170px; z-index:200; overflow:hidden; box-shadow:0 12px 30px rgba(15,23,42,0.12); }
    .h-dropdown.show { display:block; }
    .h-drop-item { padding:10px 16px; font-size:.84rem; font-weight:700; color:#0f172a; cursor:pointer; display:flex; align-items:center; gap:10px; transition:.15s; }
    .h-drop-item:hover { background:#f1f5f9; color:var(--pf-cyan); }

    /* Messages Area */
    #messagesArea { flex:1; overflow-y:auto; padding:1.5rem; display:flex; flex-direction:column; gap:4px; background:#f8fafc; scroll-behavior:smooth; }
    #messagesArea::-webkit-scrollbar { width:4px; }
    #messagesArea::-webkit-scrollbar-thumb { background:var(--pf-border); border-radius:10px; }

    /* Bubbles & Grouping */
    .brow { display:flex; width:100%; align-items:flex-end; gap:8px; margin-bottom:12px; position:relative; transition: margin 0.2s; }
    .brow.self { flex-direction:row-reverse; }
    .brow.system { justify-content:flex-start; margin-bottom: 16px; }

    .brow.grouped-msg { margin-bottom: 2px !important; }
    .brow.grouped-msg-next .b-meta { display: none !important; }
    .brow.grouped-msg-next .conv-av { visibility: hidden; }

    .b-col { max-width:75%; position:relative; }
    .brow.self .b-col { display:grid; justify-items:end; }
    .brow.other .b-col { display:flex; flex-direction:column; align-items:flex-start; }

    .bubble { display:inline-block; padding:10px 16px; border-radius:20px; font-size:.9rem; font-weight:500; line-height:1.45; max-width:100%; word-break:break-word; position: relative; }
    .brow.self .bubble { background: var(--pf-self-bubble); border:1px solid rgba(10,37,48,0.15); border-radius:20px 20px 4px 20px; color: #fff; }
    .brow.other .bubble { background:#fff; border:1px solid var(--pf-border); border-radius:20px 20px 20px 4px; color: #1e293b; }

    .brow.grouped-msg.other .bubble, .brow.grouped-msg.other .voice-bubble-player { border-radius: 20px 20px 4px 4px; }
    .brow.grouped-msg-next.other .bubble, .brow.grouped-msg-next.other .voice-bubble-player { border-radius: 4px 20px 20px 4px; }
    .brow.grouped-msg.self .bubble, .brow.grouped-msg.self .voice-bubble-player { border-radius: 20px 20px 4px 4px; }
    .brow.grouped-msg-next.self .bubble, .brow.grouped-msg-next.self .voice-bubble-player { border-radius: 20px 4px 4px 20px; }

    .brow.system .bubble { background:#fff; color:#1e293b; font-size:.9rem; border:1px solid var(--pf-border); border-radius:20px 20px 20px 4px; padding:10px 16px; font-weight:500; letter-spacing:normal; }

    .b-meta { font-size:.65rem; color:var(--pf-dim); font-weight:700; opacity:.8; margin-top:6px; display:flex; gap:4px; }
    .brow.self .b-meta { justify-content:flex-end; }

    .brow.order-update-card { margin:10px 0; }
    .brow.order-update-card.other { justify-content:flex-start; }
    .brow.order-update-card.self { justify-content:flex-end; }
    .order-update-bubble {
        display:flex;
        gap:12px;
        align-items:flex-start;
        width:min(100%, 420px);
        background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        border:1px solid #d9e6ee;
        border-radius:18px 18px 18px 6px;
        padding:12px;
        position:relative;
        box-shadow:0 10px 24px rgba(15,23,42,0.06);
        cursor:pointer;
        transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .brow.self.order-update-card .order-update-bubble {
        border-radius:18px 18px 6px 18px;
        background:linear-gradient(180deg, #f3fbff 0%, #e8f7ff 100%);
    }
    .order-update-bubble:hover { transform:translateY(-1px); box-shadow:0 14px 28px rgba(15,23,42,0.1); border-color:#7dd3d8; }
    .order-update-bubble:active { transform:translateY(0); }
    .order-update-bubble.read-only { cursor:default; }
    .order-thumb-wrap { width:58px; height:58px; border-radius:14px; overflow:hidden; background:#eaf2f7; border:1px solid #d9e6ee; flex-shrink:0; }
    .order-thumb { width:100%; height:100%; object-fit:cover; display:block; }
    .order-text { flex:1; min-width:0; }
    .order-update-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 9px; border-radius:999px; background:#e6f8f7; color:#0f766e; font-size:.62rem; font-weight:900; letter-spacing:.08em; text-transform:uppercase; margin-bottom:8px; }
    .order-update-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
    .order-update-head .order-update-badge { margin-bottom:0; }
    .order-status-pill { display:inline-flex; align-items:center; justify-content:center; padding:4px 10px; border-radius:999px; font-size:.66rem; font-weight:900; letter-spacing:.04em; white-space:nowrap; }
    .order-status-pill.tone-pending { background:#fff7ed; color:#c2410c; }
    .order-status-pill.tone-approved { background:#eff6ff; color:#1d4ed8; }
    .order-status-pill.tone-payment { background:#eef2ff; color:#4338ca; }
    .order-status-pill.tone-production { background:#ecfeff; color:#0f766e; }
    .order-status-pill.tone-ready { background:#ecfccb; color:#3f6212; }
    .order-status-pill.tone-complete { background:#dcfce7; color:#166534; }
    .order-status-pill.tone-alert { background:#fef2f2; color:#b91c1c; }
    .order-status-pill.tone-neutral { background:#f1f5f9; color:#475569; }
    .order-title { font-size:.9rem; font-weight:900; color:#0f172a; margin-bottom:4px; line-height:1.2; }
    .order-message { font-size:.8rem; color:#475569; line-height:1.45; word-break:break-word; }
    .order-update-meta { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:10px; flex-wrap:wrap; }
    .order-update-time { font-size:.68rem; color:#94a3b8; font-weight:800; }
    .order-update-cta { font-size:.68rem; font-weight:900; color:#0891b2; text-transform:uppercase; letter-spacing:.06em; }

    /* Rich Order Card V2 (Messenger Style) */
    .order-card-v2 {
        background: #ffffff;
        border: 1px solid var(--pf-border);
        border-radius: 18px;
        width: min(100%, 300px);
        overflow: hidden;
        box-shadow: 0 10px 25px rgba(15,23,42,0.06);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        display: flex;
        flex-direction: column;
        margin: 4px 0;
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
    .oc-btn:active {
        transform: scale(0.98);
    }
    
    .oc-status.tone-pending { background: #fff7ed; color: #c2410c; }
    .oc-status.tone-approved { background: #eff6ff; color: #1d4ed8; }
    .oc-status.tone-payment { background: #eef2ff; color: #4338ca; }
    .oc-status.tone-production { background: #ecfeff; color: #0f766e; }
    .oc-status.tone-ready { background: #ecfccb; color: #3f6212; }
    .oc-status.tone-complete { background: #dcfce7; color: #166534; }
    .oc-status.tone-alert { background: #fef2f2; color: #b91c1c; }
    .oc-status.tone-neutral { background: #f1f5f9; color: #475569; }

    /* Action Bar (Messenger Style) */
    .brow:hover .b-actions, .brow.has-active-menu .b-actions { opacity:1; pointer-events:auto; }
    .b-actions {
        opacity:0; pointer-events:none; display:flex; align-items: center; gap:4px;
        position:absolute; top:50%; transform:translateY(-50%); z-index:100; transition:opacity 0.15s ease;
        background: #fff; border: 1px solid var(--pf-border);
        border-radius:999px; padding:4px 8px; box-shadow:0 4px 20px rgba(15,23,42,0.12);
    }
    .brow.other .b-actions { left:calc(100% + 8px); }
    .brow.self  .b-actions { right:calc(100% + 8px); flex-direction:row-reverse; }

    .ab { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#64748b; cursor:pointer; font-size:1.1rem; transition:.15s; }
    .ab:hover { background: #f1f5f9; color: #0f172a; }

    /* More Menu Sub-Menu */
    .more-menu {
        display:none; position:absolute; top:100%; right:0; background:#fff;
        border:1px solid var(--pf-border); border-radius:12px; width:160px; z-index:151;
        overflow:hidden; box-shadow:0 12px 30px rgba(15,23,42,0.12); margin-top: 8px;
    }
    .more-menu.show { display:block; animation: menuFade 0.2s ease; }
    .mi { padding:10px 16px; font-size:.85rem; font-weight:700; color:#475569; cursor:pointer; display:flex; align-items:center; gap:10px; transition:.15s; text-align: left; }
    .mi:hover { background:#f1f5f9; color:var(--pf-cyan); }

    /* Reactions Attached to Bubble */
    .react-display {
        display:flex; gap:2px; position: relative; z-index: 12;
        background: #fff; border: 1px solid var(--pf-border); border-radius: 999px; padding: 1px 5px;
        box-shadow: 0 6px 14px rgba(15,23,42,0.18); cursor: default; white-space: nowrap;
        min-height: 20px; align-items: center; width: max-content; max-width: 100%;
        margin-top: -10px;
    }
    .brow.self .react-display { align-self: flex-end; margin-right: 10px; }
    .brow.other .react-display { align-self: flex-start; margin-left: 10px; }
    .react-chip { font-size:0.88rem; display:flex; align-items:center; gap:2px; color: #0f172a; line-height: 1; }
    .react-chip b { font-weight: 800; font-size: 0.62rem; color: #475569; margin-left: 1px; opacity: 0.92; }

    /* Reaction Picker */
    .react-picker {
        display:none; position:absolute; bottom:calc(100% + 12px); left:50%; transform:translateX(-50%);
        background:#fff; border:1px solid var(--pf-border); border-radius:999px; padding:6px 12px;
        gap:8px; z-index:150; box-shadow:0 12px 40px rgba(15,23,42,0.15); height: 50px; align-items: center; justify-content: center;
        animation: pickerPop 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
        width: max-content; min-width: auto; white-space: nowrap;
    }
    .react-picker.show { display:flex; }
    .react-picker span {
        font-size:1.6rem; cursor:pointer; transition:.15s; margin: 0 4px; 
        display: inline-flex; align-items: center; justify-content: center;
        width: 38px; height: 38px; border-radius: 50%;
    }
    .react-picker span:hover { background: #f1f5f9; transform:scale(1.25) translateY(-4px); }

    /* Seen Indicator */
    .seen-wrapper { display:flex; width:100%; margin-top:2px; min-height:16px; align-items:center; }
    .brow.self .seen-wrapper { justify-content: flex-end; }
    .seen-avatar { width: 14px; height: 14px; border-radius: 50%; object-fit: cover; border: 1px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.12); }

    /* Reply Sub-Area */
    #replyBox {
        display:none; background:#f8fafc; border-top:1px solid var(--pf-border);
        padding:10px 1.5rem; justify-content:space-between; align-items:center; gap:10px;
    }
    .reply-wrap { border-left:3px solid var(--pf-cyan); padding-left:12px; overflow:hidden; }
    .reply-head { font-size:.7rem; font-weight:800; color:var(--pf-cyan); margin-bottom:2px; }
    .reply-preview { font-size:.85rem; color:var(--pf-dim); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:400px; }
    .reply-close { background:transparent; border:none; color:var(--pf-dim); cursor:pointer; font-size:1.2rem; }

    /* ── Window Footer (Compact Staff Layout) ── */
    .cs-footer { padding: 0.75rem 1.25rem; border-top: 1px solid var(--pf-border); background:#fff; flex-shrink:0; z-index:20; }
    .chat-input-area { display: flex; align-items: center; gap: 10px; width: 100%; max-width: 900px; margin: 0 auto; }

    .input-bar {
        flex: 1; display: flex; align-items: center; gap: 10px; background: #f1f5f9; border: 2px solid transparent;
        border-radius: 16px; padding: 4px 4px 4px 12px; transition: all 0.2s; position: relative;
    }
    .input-bar:focus-within { background: #fff; border-color: var(--pf-cyan); box-shadow:0 10px 15px -3px rgba(15,23,42,0.08); }

    .play-pause-btn {
        width: 32px; height: 32px; border-radius: 50%; background: var(--pf-cyan); color: #fff;
        border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s;
    }
    .v-waveform-container { flex: 1; height: 30px; position: relative; cursor: pointer; display: flex; align-items: center; }
    .v-waveform-canvas { width: 100%; height: 100%; display: block; }
    .v-duration { font-size: 11px; font-weight: 700; color: var(--pf-dim); min-width: 35px; }

    .footer-action-btn {
        width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
        color: var(--pf-dim); cursor: pointer; transition: all 0.15s; background: transparent; flex-shrink: 0;
    }
    .footer-action-btn:hover { color: var(--pf-cyan); background: #f1f5f9; }

    #customerMsgInput {
        flex: 1; background: transparent; border: none !important; outline: none !important; color: #0f172a;
        font-size: 0.95rem; font-weight: 500; padding: 10px 0; font-family: inherit; line-height: 1.4;
        resize: none; max-height: 120px;
    }
    #customerMsgInput::placeholder { color: #94a3b8; }

    .char-counter { font-size: 10px; font-weight: 800; color: var(--pf-dim); opacity: 0.7; white-space: nowrap; align-self: center; }

    .btn-send {
        background: #0a2530; color: #fff; border: none; width: 44px; height: 44px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; flex-shrink: 0;
        box-shadow: 0 2px 10px rgba(10,37,48,0.2);
    }
    .btn-send:hover { background: #0f172a; transform: scale(1.05); }
    .btn-send.hidden { display: none; }

    /* Voice Bubble Style */
    .voice-bubble-player { display: flex; align-items: center; gap: 12px; padding: 8px 14px; border-radius: 20px; min-width: 220px; }
    .brow.self .voice-bubble-player { background: var(--pf-self-bubble); color: #fff; border: 1px solid rgba(10,37,48,0.15); border-radius: 20px 20px 4px 20px; }
    .brow.other .voice-bubble-player { background: #fff; color: #1e293b; border: 1px solid var(--pf-border); border-radius: 20px 20px 20px 4px; }
    .play-pause-bubble { width: 32px; height: 32px; border-radius: 50%; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
    .brow.self .play-pause-bubble { background: #fff; color: #0a2530; }
    .brow.other .play-pause-bubble { background: var(--pf-cyan); color: #fff; }

    @keyframes pulse-rec { 0%{box-shadow:0 0 0 0 rgba(239,68,68,.4)} 70%{box-shadow:0 0 0 10px rgba(239,68,68,0)} 100%{box-shadow:0 0 0 0 rgba(239,68,68,0)} }
    @keyframes pulse-dot { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(1.2); } }
    @keyframes pickerPop { from { opacity: 0; transform: translateX(-50%) scale(0.8) translateY(10px); } to { opacity: 1; transform: translateX(-50%) scale(1) translateY(0); } }
    @keyframes menuFade { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

    /* Forward Modal CSS */
    #pfFwdModal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:2000; align-items:center; justify-content:center; }
    #pfFwdModal.show { display:flex; }
    .fwd-panel { background:#fff; border:1px solid var(--pf-border); border-radius:32px; width:100%; max-width:480px; box-shadow:0 40px 100px rgba(15,23,42,0.2); display:flex; flex-direction:column; overflow:hidden; }
    .fwd-header { padding:1.25rem 1.5rem; border-bottom:1px solid var(--pf-border); display:flex; justify-content:space-between; align-items:center; }
    .fwd-search-wrap { padding:1rem 1.5rem; border-bottom:1px solid var(--pf-border); }
    .fwd-search-input { width:100%; height:44px; background:#f8fafc; border:1px solid var(--pf-border); border-radius:14px; padding:0 1rem 0 2.5rem; color:#0f172a; font-size:0.9rem; outline:none; transition:.2s; }
    .fwd-search-input:focus { border-color:var(--pf-cyan); background:#fff; box-shadow:0 0 0 3px rgba(10,37,48,0.08); }
    .fwd-preview-section { padding:0.75rem 1.5rem; background:#f8fafc; border-bottom:1px solid var(--pf-border); }
    .fwd-preview-label { font-size:0.65rem; color:var(--pf-cyan); font-weight:800; text-transform:uppercase; margin-bottom:4px; letter-spacing:0.05em; }
    .fwd-body { flex:1; max-height:380px; overflow-y:auto; padding:1rem 1.25rem; display:flex; flex-direction:column; gap:8px; }
    .fwd-body::-webkit-scrollbar { width:4px; }
    .fwd-body::-webkit-scrollbar-thumb { background:var(--pf-border); border-radius:10px; }

    .details-modal-overlay { display:none !important; position:fixed; inset:0; background:rgba(15,23,42,0.75); z-index:3000; align-items:center; justify-content:center; padding:1.5rem; backdrop-filter:blur(8px); }
    .details-modal-overlay.active { display:flex !important; }
    .details-modal-panel { background:#fff; border-radius:32px; width:min(100%, 1100px); max-height:min(88vh, 920px); overflow:hidden; box-shadow:0 40px 80px -15px rgba(0,0,0,0.4); border:1px solid rgba(255,255,255,0.1); display:flex; flex-direction:column; }
    /* Modal font: match app font for cleaner, consistent UI */
    .details-modal-panel, .details-modal-header, .details-main, .details-sidebar {
        font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        color: #0f172a;
    }
    .details-modal-header { padding:1.25rem 2rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; background:#fff; flex-shrink:0; }
    .details-modal-content { display:grid; grid-template-columns:minmax(250px, 290px) minmax(0, 1fr); flex:1; overflow:hidden; min-height:0; }
    .details-sidebar { background:linear-gradient(180deg,#f8fbff 0%, #f1f5f9 100%); border-right:1px solid #eef2f7; padding:1.5rem; overflow-y:auto; }
    .details-main { padding:1.5rem; overflow-y:auto; background:#fff; }
    .pf-mini-card { background:#fff; border-radius:20px; padding:1.25rem; border:1px solid #eef2f6; box-shadow:0 4px 6px -1px rgba(0,0,0,0.02); }
    .pf-spec-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(120px, 1fr)); gap:.5rem; margin-top:.75rem; }
    .pf-spec-box { background:#f8fafc; border:1px solid #f1f5f9; padding:8px 10px; border-radius:12px; overflow:hidden; min-width:0; }
    .pf-spec-key { font-size:8px; font-weight:900; color:#94a3b8; text-transform:uppercase; margin-bottom:3px; letter-spacing:.05em; }
    .pf-spec-val { font-size:10.5px; font-weight:800; color:#1e293b; line-height:1.3; overflow-wrap:break-word; }
    .details-main-heading { position:sticky; top:0; z-index:2; background:#fff; padding:0 0 1rem; font-size:9px; font-weight:900; color:#94a3b8; text-transform:uppercase; letter-spacing:.1em; margin-bottom:1rem; }
    .details-items { display:flex; flex-direction:column; gap:1rem; }
    .detail-order-card { background:#fff; border:1px solid #f1f5f9; border-radius:20px; padding:1rem; box-shadow:0 12px 32px rgba(15,23,42,0.04); }
    .detail-order-top { display:grid; grid-template-columns:112px minmax(0, 1fr); gap:1rem; align-items:start; }
    .detail-order-thumb { width:112px; height:112px; border-radius:16px; background:#f8fafc; border:1px solid #f1f5f9; overflow:hidden; display:flex; align-items:center; justify-content:center; }
    .detail-order-thumb img { width:100%; height:100%; object-fit:cover; }
    .detail-order-body { min-width:0; display:flex; flex-direction:column; gap:.9rem; }
    .detail-order-summary { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
    .detail-order-title { font-size:1.05rem; font-weight:900; color:#1e293b; line-height:1.2; word-break:break-word; }
    .detail-order-meta { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }
    .detail-order-chip { background:#f1f5f9; color:#475569; border-radius:999px; padding:.35rem .7rem; font-size:.72rem; font-weight:800; letter-spacing:.02em; }
    .detail-order-chip.category { background:#ecfeff; color:#0f766e; text-transform:uppercase; }
    .detail-order-price { min-width:120px; text-align:right; }
    .detail-order-price .pf-spec-key { margin-bottom:2px; font-size:9px; }
    .detail-order-price strong { display:block; font-size:1.05rem; font-weight:900; color:#0ea5a5; line-height:1.2; word-break:break-word; overflow-wrap:break-word; white-space:normal; }
    .fwd-footer { padding:1.25rem 1.5rem; border-top:1px solid var(--pf-border); display:flex; justify-content:flex-end; gap:12px; }

    .fwd-list-item { display:flex; align-items:center; gap:12px; padding:10px 14px; border-radius:16px; transition:.15s; cursor:pointer; background:#fff; border:1px solid var(--pf-border); }
    .fwd-list-item:hover { background:#f8fafc; border-color:#cbd5e1; }
    .fwd-list-item.selected { background:rgba(10,37,48,0.06); border-color:rgba(10,37,48,0.35); }
    .fwd-check-circle { width:20px; height:20px; border-radius:50%; border:2px solid rgba(10,37,48,0.25); display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:.2s; }
    .selected .fwd-check-circle { background:var(--pf-cyan); border-color:var(--pf-cyan); }

    #galleryPanel {
        position: absolute; right: 0; top: 0; bottom: 0; width: 340px;
        background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(30px);
        border-left: 1px solid rgba(0,0,0,0.06); z-index: 1000;
        display: none; flex-direction: column; 
        box-shadow: -15px 0 40px rgba(0,0,0,0.12);
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        transform: translateX(100%);
    }
    #galleryPanel.show { display: flex; transform: translateX(0); }
    .gal-tabs { display: flex; padding: 0.5rem 1rem; gap: 8px; border-bottom: 1px solid rgba(0,0,0,0.05); }
    .gal-tab { 
        flex: 1; padding: 8px; font-size: 0.75rem; font-weight: 700; text-align: center; 
        border-radius: 12px; cursor: pointer; transition: all 0.2s; color: #64748b; border: 1px solid transparent;
    }
    .gal-tab.active { background: #fff; color: #0a2530; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
    .gal-grid { flex: 1; overflow-y: auto; display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; padding: 1.5rem; align-content: flex-start; }
    .gal-item { aspect-ratio: 1; border-radius: 18px; overflow: hidden; cursor: pointer; position: relative; transition: all 0.3s; border: 1px solid rgba(0,0,0,0.05); background: #f1f5f9; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .gal-item:hover { transform: translateY(-4px) scale(1.02); box-shadow: 0 12px 24px rgba(0,0,0,0.1); border-color: var(--pf-cyan); }
    .gal-item img, .gal-item video { width: 100%; height: 100%; object-fit: cover; }
    .gal-vid-badge { position: absolute; top: 10px; right: 10px; background: rgba(0,0,0,0.6); color: #fff; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.1); }

    @media (max-width: 768px) {
        body.chat-page #main-content {
            overflow: visible !important;
        }

        .chat-shell {
            max-width: 100%;
            padding: 0;
            height: calc(100dvh - 65px);
        }

        #chat-root {
            grid-template-columns: 1fr;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }
        .details-modal-overlay { padding:.75rem; align-items:flex-end; }
        .details-modal-panel { max-height:min(92vh, 920px); border-radius:24px 24px 0 0; overflow:hidden; }
        .details-modal-header,
        .details-main,
        .details-sidebar { padding:1rem; }
        .details-modal-content { grid-template-columns:1fr; overflow-y:auto; overflow-x:hidden; }
        .details-sidebar { border-right:none; border-bottom:1px solid #eef2f7; overflow:visible; }
        .details-main { overflow:visible; }
        .details-main-heading { padding-bottom:.75rem; margin-bottom:.85rem; border-bottom:1px solid #f1f5f9; }
        .detail-order-top { grid-template-columns:1fr; }
        .detail-order-thumb { width:100%; max-width:240px; height:auto; aspect-ratio:1 / 1; }
        .detail-order-price { min-width:0; width:100%; text-align:left; }

        .cs-sidebar,
        .cs-window {
            min-width: 0;
            height: 100%;
        }

        .cs-window {
            display: none;
        }

        #chat-root.chat-open .cs-sidebar {
            display: none;
        }

        #chat-root.chat-open .cs-window {
            display: flex;
        }

        .cs-sidebar-top,
        .cs-tabs {
            padding-left: 0.875rem;
            padding-right: 0.875rem;
        }

        .cs-list {
            padding: 0.5rem 0.625rem 0.875rem;
        }

        .conv-card {
            padding: 12px;
            border-radius: 12px;
        }

        .cs-mobile-back {
            display: inline-flex;
        }

        .cs-header {
            padding: 0.875rem;
            gap: 10px;
        }

        .cs-header-name {
            font-size: 0.95rem;
        }

        .cs-header-meta {
            font-size: 0.7rem;
        }

        .cs-h-actions {
            gap: 6px;
        }

        .cs-h-btn {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            font-size: 0.92rem;
        }

        #messagesArea {
            padding: 1rem 0.875rem;
        }

        .b-col {
            max-width: 88%;
            overflow: visible;
        }

        .bubble {
            font-size: 0.88rem;
            padding: 10px 14px;
        }

        .cs-footer {
            padding: 0.75rem 0.875rem calc(0.75rem + env(safe-area-inset-bottom));
        }

        .chat-input-area {
            gap: 8px;
        }

        .input-bar {
            padding: 4px 4px 4px 10px;
            border-radius: 14px;
            min-width: 0;
        }

        #customerMsgInput {
            font-size: 0.92rem;
            min-width: 0;
        }

        .footer-action-btn,
        .btn-send {
            width: 40px;
            height: 40px;
            border-radius: 12px;
        }

        #galleryPanel {
            width: 100%;
            max-width: 100%;
        }

        .b-actions {
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

        .brow.has-active-menu .b-actions {
            display: flex;
            opacity: 1;
            pointer-events: auto;
        }

        .react-picker {
            position: fixed;
            left: 50%;
            right: auto;
            top: auto !important;
            bottom: auto !important;
            transform: translateX(-50%) !important;
            margin: 0 !important;
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
        }

        .more-menu {
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
        }

        #welcome {
            display: none !important;
        }
    }

    /* Pinned Messages Styles */
    .pinned-badge { position: absolute; top: -10px; right: -10px; width: 22px; height: 22px; background: #ef4444; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; border: 2px solid #fff; box-shadow: 0 4px 12px rgba(239,68,68,0.4); z-index: 10; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
    .pinned-badge i { transform: rotate(45deg); }
    .pin-bar-active { background: rgba(239,68,68,0.06) !important; color: #b91c1c !important; cursor: pointer; }
    .details-modal-overlay { display: none !important; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); z-index: 10000; align-items: center; justify-content: center; padding: 1.5rem; backdrop-filter: blur(8px); transition: all 0.3s; }
    .details-modal-overlay.active { display: flex !important; }
    .details-modal-panel { background: #fff; border-radius: 32px; width: min(100%, 1100px); max-height: min(88vh, 920px); overflow: hidden; box-shadow: 0 40px 80px -15px rgba(0, 0, 0, 0.4); position: relative; border: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column; }
    .details-modal-header { padding: 1.25rem 2rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; background: #fff; z-index: 10; flex-shrink: 0; }
    @keyframes highlightStaffMsg { 0% { background: rgba(14,165,233,0.2); } 100% { background: transparent; } }
</style>

<div class="chat-shell">
<!-- Lightbox / Modal Viewer (Part 4) -->
<div id="chatLightbox" onclick="closeLightbox()" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,0.97);z-index:9000;align-items:center;justify-content:center;padding:2rem;cursor:pointer;">
    <div style="position:relative; max-width:95vw; max-height:95vh;display:flex;flex-direction:column;align-items:center;" onclick="event.stopPropagation()">
        <img id="lightboxImg" src="" style="max-width:100%;max-height:80vh;border-radius:1rem;box-shadow:0 0 60px rgba(0,0,0,0.6);display:none;object-fit:contain;">
        <video id="lightboxVideo" controls style="max-width:100%;max-height:80vh;border-radius:1rem;box-shadow:0 0 60px rgba(0,0,0,0.6);display:none;background:#000;outline:none;" preload="metadata"></video>
        <div style="display:flex; justify-content:center; gap:1.5rem; margin-top:1.5rem;">
            <a id="lightboxDownload" href="" download class="cs-h-btn" style="width:auto; padding:0 20px; background:#fff; color:#0a2530; font-weight:700; text-decoration:none;">Download</a>
            <button onclick="closeLightbox()" class="cs-h-btn" style="width:auto; padding:0 20px; background:#fff; color:#0a2530; font-weight:700;">Close</button>
        </div>
    </div>
</div>

<div id="chat-root">
    <!-- ══ Sidebar ══ -->
    <aside class="cs-sidebar">
        <div class="cs-sidebar-top">
            <h2 style="display:flex;align-items:center;gap:8px;">My Messages <span class="pf-chat-unread-badge" data-chat-unread-badge style="display:none;"></span></h2>
            <div class="cs-search"><i class="bi bi-search"></i><input type="text" id="convSearch" placeholder="Search orders…"></div>
        </div>
        <div class="cs-list" id="convList"></div>
    </aside>

    <!-- ══ Chat Window ── -->
    <section class="cs-window">
        <div id="welcome" class="flex-1 flex items-center justify-center text-left p-12">
        <div>
            <div class="text-5xl opacity-20 text-white mb-6"><i class="bi bi-chat-heart-fill"></i></div>
            <h3 class="text-3xl font-black text-white letter-spacing-tight">Get in Touch</h3>
            <p class="text-white opacity-50 max-w-xs mt-3 font-bold text-lg leading-snug">Please select an order to start chatting. You can contact our admin or staff directly if you encounter any issues.</p>
        </div>
    </div>
        
        <div id="chatInterface" style="display:none;flex:1;flex-direction:column;overflow:hidden;">
            <header class="cs-header">
                <button type="button" class="cs-mobile-back" onclick="closeChatMobile()"><i class="bi bi-arrow-left"></i></button>
                <div id="hAvatar" class="conv-av"></div>
                <div class="cs-header-info"><h3 class="cs-header-name"><span id="hName">...</span><span id="hOnline" style="width:10px;height:10px;background:#22c55e;border-radius:50%;display:none;margin-left:8px;"></span></h3><p class="cs-header-meta" id="hMeta">...</p></div>
                <div class="cs-h-actions">
                    <div class="h-menu-wrap">
                        <button class="cs-h-btn" onclick="toggleHMenu(event)"><i class="bi bi-three-dots-vertical"></i></button>
                        <div class="h-dropdown" id="hDropdown">
                            <div class="h-drop-item" onclick="openGallery()"><i class="bi bi-images"></i> Shared Media</div>
                            <div class="h-drop-item" onclick="openOrderDetails()"><i class="bi bi-info-circle"></i> Order Details</div>
                        </div>
                    </div>
                </div>
            </header>

            <div id="pinnedBar" style="display:none; background:var(--pf-navy-card); border-bottom:1px solid var(--pf-border); padding:10px 1.5rem; align-items:center; justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:8px;"><i class="bi bi-pin-angle-fill" style="color:var(--pf-cyan);"></i><span id="pinnedTxt" style="font-size:0.75rem; font-weight:800; color:#0f172a;">0 pinned messages</span></div>
            </div>

            <div id="messagesArea"></div>

            <div id="galleryPanel">
                <div class="gal-head"><span style="font-weight:800;font-size:1.1rem;color:#0f172a;">Shared Media</span><button onclick="closeGallery()" style="background:transparent;border:none;color:#64748b;font-size:1.5rem;cursor:pointer;"><i class="bi bi-x"></i></button></div>
                <div class="gal-tabs">
                    <div class="gal-tab active" id="galTabImg" onclick="switchGalleryTab('image')">Images</div>
                </div>
                <div class="gal-grid" id="galleryGrid"></div>
            </div>

            <div id="replyBox">
                <div class="reply-wrap">
                    <div class="reply-head" id="replyHead">Replying to message</div>
                    <div class="reply-preview" id="replyPreviewTxt">...</div>
                </div>
                <button class="reply-close" onclick="cancelReply()"><i class="bi bi-x-circle-fill"></i></button>
            </div>

            <footer class="cs-footer">
                <div class="chat-input-area">
                    <div class="input-bar flex-1" id="inputBarMain" style="position:relative; display:flex; align-items:flex-end; gap:10px;">
                        <label class="footer-action-btn" title="Send images" style="margin-bottom:6px !important;">
                            <input type="file" id="customerMediaInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
                            <i class="bi bi-image"></i>
                        </label>
                        <textarea id="customerMsgInput" class="chat-input" placeholder="Type a message..." autocomplete="off" maxlength="2000" rows="1" style="background:transparent; border:none; outline:none; color:#1e293b; flex:1; resize:none; font-family:inherit; padding:10px 0; font-weight: 500;"></textarea>
                        <span id="customerCharCount" class="char-counter">0/2000</span>
                    </div>

                    <button id="customerSendBtn" class="btn-send" onclick="sendMsg()">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
                <div id="customerImgPreview" style="display:none;margin-top:0.6rem;gap:10px;flex-wrap:wrap;justify-content:center;padding:5px;"></div>
            </footer>
        </div>
    </section>
</div>
</div>

<script>
window.PrintFlowChatConfig = <?= json_encode([
    'role' => 'customer',
    'baseUrl' => BASE_URL,
    'csrf' => generate_csrf_token(),
    'initialOrderId' => $initial_order_id,
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?php echo BASE_URL; ?>/public/assets/js/chat_http.js?v=<?php echo filemtime(__DIR__ . '/../public/assets/js/chat_http.js'); ?>" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
