<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/auth_helpers.php';

function render_register_form(string $message = '', string $type = 'error'): void {
    $notice = auth_notice($message, $type);

    if (($_GET['captcha_failed'] ?? '') === '1' && $notice === '') {
        $notice = auth_notice(t('CAPTCHA verification failed too many times. Please complete registration again.'));
    }

    if (($_GET['captcha_expired'] ?? '') === '1' && $notice === '') {
        $notice = auth_notice(t('Registration security check expired. Please complete registration again.'));
    }

    echo render_template(__DIR__ . '/templates/register.html', [
        'CSRF_INPUT' => csrf_input(),
        'NOTICE' => $notice,
    ]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    unset(
        $_SESSION['pending_registration'],
        $_SESSION['register_captcha_hash'],
        $_SESSION['register_captcha_svg'],
        $_SESSION['register_captcha_created_at']
    );

    render_register_form();
    exit;
}

require_valid_csrf();

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phoneNumber = trim($_POST['phone_number'] ?? '');
$passwordRaw = $_POST['password'] ?? '';
$languageCode = normalize_language_code($_POST['language_code'] ?? 'en');

if ($firstName === '' || $lastName === '' || $email === '' || $phoneNumber === '' || $passwordRaw === '') {
    render_register_form(t('Please complete all required fields.'));
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    render_register_form(t('Please enter a valid email address.'));
    exit;
}

$passwordError = password_policy_error($passwordRaw);
if ($passwordError !== '') {
    render_register_form($passwordError);
    exit;
}

$checkStmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
if (!$checkStmt) {
    render_register_form(t('Database error.'));
    exit;
}

$checkStmt->bind_param("s", $email);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();

if ($existing) {
    render_register_form(t('An account with this email already exists.'));
    exit;
}

/*
 * The account is NOT saved yet.
 * Details are stored temporarily in the session until the separate CAPTCHA page succeeds.
 */
session_regenerate_id(true);
$_SESSION['pending_registration'] = [
    'first_name' => $firstName,
    'last_name' => $lastName,
    'email' => $email,
    'phone_number' => $phoneNumber,
    'password_hash' => password_hash($passwordRaw, PASSWORD_DEFAULT),
    'language_code' => $languageCode,
    'created_at' => time(),
    'captcha_attempts' => 0,
    'fingerprint' => current_auth_fingerprint(),
];

generate_register_captcha();

header('Location: register_captcha.php');
exit;
?>
