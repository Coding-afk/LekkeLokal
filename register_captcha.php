<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/auth_helpers.php';

const REGISTER_CAPTCHA_MAX_ATTEMPTS = 3;
const PENDING_REGISTRATION_MINUTES = 30;

function pending_registration(): ?array {
    auth_session_start();

    $pending = $_SESSION['pending_registration'] ?? null;
    if (!is_array($pending) || empty($pending['email']) || empty($pending['password_hash'])) {
        return null;
    }

    if ((time() - (int)($pending['created_at'] ?? 0)) > PENDING_REGISTRATION_MINUTES * 60) {
        unset($_SESSION['pending_registration']);
        return null;
    }

    if (!hash_equals((string)($pending['fingerprint'] ?? ''), current_auth_fingerprint())) {
        unset($_SESSION['pending_registration']);
        return null;
    }

    return $pending;
}

function render_register_captcha(string $message = '', string $type = 'error'): void {
    $pending = pending_registration();
    if (!$pending) {
        header('Location: register.php?captcha_expired=1');
        exit;
    }

    if (isset($_GET['refresh']) || empty($_SESSION['register_captcha_svg'])) {
        generate_register_captcha();
    }

    $attempts = (int)($pending['captcha_attempts'] ?? 0);
    $remaining = max(0, REGISTER_CAPTCHA_MAX_ATTEMPTS - $attempts);

    echo render_template(__DIR__ . '/templates/register_captcha.html', [
        'CSRF_INPUT' => csrf_input(),
        'NOTICE' => auth_notice($message, $type),
        'CAPTCHA_IMAGE' => get_register_captcha_image(),
        'CAPTCHA_LABEL' => h(t('Enter the exact CAPTCHA text shown.')),
        'MASKED_EMAIL' => h(mask_email((string)$pending['email'])),
        'ATTEMPTS_REMAINING' => h((string)$remaining),
    ]);
}

function fail_registration_captcha(): void {
    unset(
        $_SESSION['pending_registration'],
        $_SESSION['register_captcha_hash'],
        $_SESSION['register_captcha_svg'],
        $_SESSION['register_captcha_created_at']
    );

    header('Location: register.php?captcha_failed=1');
    exit;
}

$pending = pending_registration();
if (!$pending) {
    header('Location: register.php?captcha_expired=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    render_register_captcha();
    exit;
}

require_valid_csrf();

if (!verify_register_captcha($_POST['captcha_answer'] ?? '')) {
    $attempts = (int)($pending['captcha_attempts'] ?? 0) + 1;
    $_SESSION['pending_registration']['captcha_attempts'] = $attempts;

    if ($attempts >= REGISTER_CAPTCHA_MAX_ATTEMPTS) {
        fail_registration_captcha();
    }

    generate_register_captcha();
    $remaining = REGISTER_CAPTCHA_MAX_ATTEMPTS - $attempts;
    render_register_captcha(t('Security check failed. Please try again.') . ' ' . h((string)$remaining) . ' ' . t('attempts remaining') . '.');
    exit;
}

$firstName = (string)$pending['first_name'];
$lastName = (string)$pending['last_name'];
$email = (string)$pending['email'];
$phoneNumber = (string)$pending['phone_number'];
$passwordHash = (string)$pending['password_hash'];
$languageCode = normalize_language_code($pending['language_code'] ?? 'en');
$status = 'active';
$role = 'user';

$checkStmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
if (!$checkStmt) {
    render_register_captcha(t('Database error.'));
    exit;
}

$checkStmt->bind_param("s", $email);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();

if ($existing) {
    unset($_SESSION['pending_registration']);
    header('Location: register.php');
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, phone_number, password_hash, status, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare user insert.');
    }

    $stmt->bind_param("sssssss", $firstName, $lastName, $email, $phoneNumber, $passwordHash, $status, $role);
    $stmt->execute();

    $userId = (int)$conn->insert_id;
    $storeName = $firstName . "'s Store";
    ensure_user_profiles($conn, $userId, $storeName);

    $prefStmt = $conn->prepare("INSERT INTO language_preferences (user_id, language_code) VALUES (?, ?)");
    if (!$prefStmt) {
        throw new RuntimeException('Could not prepare language insert.');
    }

    $prefStmt->bind_param("is", $userId, $languageCode);
    $prefStmt->execute();

    ensure_user_security_row($conn, $userId);

    $conn->commit();

    unset(
        $_SESSION['pending_registration'],
        $_SESSION['register_captcha_hash'],
        $_SESSION['register_captcha_svg'],
        $_SESSION['register_captcha_created_at']
    );

    header("Location: index.php?registered=1");
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    unset($_SESSION['pending_registration']);
    header('Location: register.php?captcha_expired=1');
    exit;
}
?>
