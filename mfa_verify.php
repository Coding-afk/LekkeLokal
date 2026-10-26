<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/auth_helpers.php';

function render_mfa_form(string $message = '', string $type = 'error'): void {
    $pending = pending_mfa_user();
    if (!$pending) {
        header('Location: index.php');
        exit;
    }

    $notice = auth_notice($message, $type);
    if (($_GET['sent'] ?? '') === '1' && $notice === '') {
        $notice = auth_notice(t('We sent a 6-digit OTP to your email address.'), 'success');
    }
    if (($_GET['wait'] ?? '') === '1' && $notice === '') {
        $notice = auth_notice(t('Please wait a minute before requesting another OTP.'), 'error');
    }
    if (($_GET['error'] ?? '') === 'send' && $notice === '') {
        $notice = auth_notice(t('Could not send OTP right now. Please try again.'), 'error');
    }

    echo render_template(__DIR__ . '/templates/mfa_verify.html', [
        'CSRF_INPUT' => csrf_input(),
        'NOTICE' => $notice,
        'MASKED_EMAIL' => h(mask_email((string)$pending['email'])),
    ]);
}

$pending = pending_mfa_user();
if (!$pending) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    render_mfa_form();
    exit;
}

require_valid_csrf();

$otp = trim($_POST['otp_code'] ?? '');
$error = '';
if (!verify_mfa_otp($conn, (int)$pending['user_id'], $otp, $error)) {
    render_mfa_form($error);
    exit;
}

complete_authenticated_session(
    $conn,
    (int)$pending['user_id'],
    (string)$pending['first_name'],
    (string)($pending['role'] ?? 'user')
);

redirect_after_login((string)($pending['role'] ?? 'user'));
?>
