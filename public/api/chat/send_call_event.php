<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/chat_http.php';

ob_start();
printflow_chat_require_login();
printflow_chat_json(['success' => false, 'error' => 'Chat calling is no longer available'], 410);
