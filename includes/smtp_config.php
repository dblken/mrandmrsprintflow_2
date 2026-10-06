<?php
/**
 * SMTP Configuration for PHPMailer
 * PrintFlow – configure your credentials here.
 * DO NOT commit real passwords to version control.
 */

return [
    // ── SMTP Credentials ─────────────────────────────────────────────────────
    'smtp_host'     => 'smtp.hostinger.com',       // e.g. smtp.gmail.com, smtp.zoho.com
    'smtp_port'     => 465,
    'smtp_user'     => 'printflow@mrandmrsprintflow.com', // <-- REPLACE with your Gmail
    'smtp_pass'     => 'Printflow@123',    // <-- REPLACE with Gmail App Password (not your real password)
    'smtp_secure'   => 'ssl',                  // 'tls' (port 587) or 'ssl' (port 465)

    // ── Sender identity ───────────────────────────────────────────────────────
    'from_email'    => 'printflow@mrandmrsprintflow.com', // Must match smtp_user for Gmail
    'from_name'     => 'PrintFlow',

    // ── OTP settings ─────────────────────────────────────────────────────────
    'otp_expiry_minutes' => 5,
    'otp_resend_cooldown' => 60, // seconds before user can resend
];
