<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/auth_helpers.php';

function render_login_form(string $message = '', string $type = 'error'): void {
    $notice = auth_notice($message, $type);
    if (($_GET['registered'] ?? '') === '1' && $notice === '') {
        $notice = auth_notice(t('Account created successfully. Please log in.'), 'success');
    }
    if (($_GET['password_reset'] ?? '') === '1' && $notice === '') {
        $notice = auth_notice(t('Your password was updated successfully. Please log in.'), 'success');
    }
    if (($_GET['expired'] ?? '') === '1' && $notice === '') {
        $notice = auth_notice(t('Your session expired. Please log in again.'), 'error');
    }
    if (($_GET['security'] ?? '') === '1' && $notice === '') {
        $notice = auth_notice(t('Your session could not be verified. Please log in again.'), 'error');
    }

    echo render_template(__DIR__ . '/templates/login.html', [
        'CSRF_INPUT' => csrf_input(),
        'NOTICE' => $notice,
    ]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    render_login_form();
    exit;
}

require_valid_csrf();

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    render_login_form(t('Please enter both email and password.'));
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    render_login_form(t('Please enter a valid email address.'));
    exit;
}

if (login_is_rate_limited($conn, $email)) {
    render_login_form(t('Too many failed login attempts. Please wait 15 minutes and try again.'));
    exit;
}

$stmt = $conn->prepare("SELECT user_id, password_hash, first_name, status, role FROM users WHERE email = ? LIMIT 1");
if (!$stmt) {
    render_login_form(t('Database error.'));
    exit;
}

$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user || !password_verify($password, $user['password_hash'])) {
    record_login_attempt($conn, $email, false);
    render_login_form(t('Invalid email or password.'));
    exit;
}

if (($user['status'] ?? '') !== 'active') {
    record_login_attempt($conn, $email, false);
    render_login_form(t('This account is not active.'));
    exit;
}

record_login_attempt($conn, $email, true);
$userId = (int)$user['user_id'];
$firstName = (string)$user['first_name'];
$role = (string)($user['role'] ?? 'user');

if (mfa_is_current($conn, $userId)) {
    complete_authenticated_session($conn, $userId, $firstName, $role);
    redirect_after_login($role);
}

try {
    $code = create_mfa_challenge($conn, $userId);
    send_mfa_otp_email($email, $firstName, $code);
    start_pending_mfa_session($user, $email);
    header('Location: mfa_verify.php?sent=1');
    exit;
} catch (Throwable $e) {
    render_login_form(t('Could not send OTP right now. Please try again.'));
    exit;
}
?>
