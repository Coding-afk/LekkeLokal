<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/auth_helpers.php';

function render_forgot_request(string $message = '', string $type = 'error'): void {
    echo render_template(__DIR__ . '/templates/forgot_password_request.html', [
        'CSRF_INPUT' => csrf_input(),
        'NOTICE' => auth_notice($message, $type),
    ]);
}

function render_forgot_verify(string $message = '', string $type = 'error'): void {
    $pending = pending_password_reset_user(false);
    if (!$pending) {
        render_forgot_request(t('We could not find a reset request. Please start again.'));
        return;
    }

    echo render_template(__DIR__ . '/templates/forgot_password_verify.html', [
        'CSRF_INPUT' => csrf_input(),
        'NOTICE' => auth_notice($message, $type),
        'MASKED_EMAIL' => h(mask_email((string)$pending['email'])),
    ]);
}

function render_forgot_reset(string $message = '', string $type = 'error'): void {
    $pending = pending_password_reset_user(true);
    if (!$pending) {
        render_forgot_request(t('Reset session expired. Please start again.'));
        return;
    }

    echo render_template(__DIR__ . '/templates/forgot_password_reset.html', [
        'CSRF_INPUT' => csrf_input(),
        'NOTICE' => auth_notice($message, $type),
    ]);
}

$step = $_GET['step'] ?? 'request';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($step === 'verify') {
        render_forgot_verify();
        exit;
    }

    if ($step === 'reset') {
        render_forgot_reset();
        exit;
    }

    render_forgot_request();
    exit;
}

require_valid_csrf();

$action = $_POST['reset_action'] ?? 'request';

if ($action === 'request') {
    unset($_SESSION['pending_password_reset']);
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        render_forgot_request(t('Please enter a valid email address.'));
        exit;
    }

    if (password_reset_is_rate_limited($conn, $email)) {
        render_forgot_request(t('Too many password reset requests. Please wait 15 minutes and try again.'));
        exit;
    }

    $stmt = $conn->prepare("SELECT user_id, first_name, email, status FROM users WHERE email = ? LIMIT 1");
    if (!$stmt) {
        render_forgot_request(t('Database error.'));
        exit;
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && ($user['status'] ?? '') === 'active') {
        try {
            $userId = (int)$user['user_id'];
            $code = create_password_reset_challenge($conn, $userId);
            send_password_reset_otp_email((string)$user['email'], (string)$user['first_name'], $code);
            start_pending_password_reset_session($userId, (string)$user['email']);
            render_forgot_verify(t('If an account exists for that email, we sent a reset OTP.'), 'success');
            exit;
        } catch (Throwable $e) {
            render_forgot_request(t('Could not send password reset OTP right now. Please try again.'));
            exit;
        }
    }

    // Generic message to avoid confirming whether an account exists.
    render_forgot_request(t('If an account exists for that email, we sent a reset OTP.'), 'success');
    exit;
}

if ($action === 'verify') {
    $pending = pending_password_reset_user(false);
    if (!$pending) {
        render_forgot_request(t('We could not find a reset request. Please start again.'));
        exit;
    }

    $error = '';
    if (!verify_password_reset_otp($conn, (int)$pending['user_id'], trim($_POST['reset_code'] ?? ''), $error)) {
        render_forgot_verify($error);
        exit;
    }

    header('Location: forgot_password.php?step=reset');
    exit;
}

if ($action === 'reset') {
    $pending = pending_password_reset_user(true);
    if (!$pending) {
        render_forgot_request(t('Reset session expired. Please start again.'));
        exit;
    }

    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        render_forgot_reset(t('Passwords do not match.'));
        exit;
    }

    $passwordError = password_policy_error($newPassword);
    if ($passwordError !== '') {
        render_forgot_reset($passwordError);
        exit;
    }

    if (!complete_password_reset($conn, (int)$pending['user_id'], $newPassword)) {
        render_forgot_reset(t('Could not update password right now. Please try again.'));
        exit;
    }

    header('Location: index.php?password_reset=1');
    exit;
}

render_forgot_request();
?>
