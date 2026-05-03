<?php
/**
 * Protected Payment Proof Viewer (document root entry point).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_proof_serve.php';

printflow_serve_payment_proof();
