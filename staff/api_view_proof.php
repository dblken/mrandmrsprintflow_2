<?php
/**
 * Protected Payment Proof Viewer (staff-relative URL — avoids 404 when only /staff/ is routed).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payment_proof_serve.php';

printflow_serve_payment_proof();
