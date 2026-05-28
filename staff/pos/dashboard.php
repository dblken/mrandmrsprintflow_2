<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/staff_access.php';

require_role('Staff');

if (printflow_get_staff_access_role() !== 'pos') {
    header('Location: ' . printflow_staff_home_url());
    exit;
}

require_once __DIR__ . '/../dashboard.php';
