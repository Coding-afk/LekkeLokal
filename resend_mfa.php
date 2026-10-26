<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/auth_helpers.php';

$pending = pending_mfa_user();
if (!$pending) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: mfa_verify.php');
    exit;
}

require_valid_csrf();

if (!resend_pending_mfa_allowed()) {
    header('Location: mfa_verify.php?wait=1');
    exit;
}

try {
    $code = create_mfa_challenge($conn, (int)$pending['user_id']);
    send_mfa_otp_email((string)$pending['email'], (string)$pending['first_name'], $code);
    mark_pending_mfa_sent_now();
    header('Location: mfa_verify.php?sent=1');
    exit;
} catch (Throwable $e) {
    header('Location: mfa_verify.php?error=send');
    exit;
}
?>
