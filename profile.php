<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';

$userId = ensure_logged_in();
ensure_user_profiles($conn, $userId, ($_SESSION['first_name'] ?? 'Seller') . "'s Store");
$currentLanguage = get_user_language($conn, $userId);

function fetch_user_profile(mysqli $conn, int $userId): ?array {
    $stmt = $conn->prepare("
        SELECT u.first_name, u.last_name, u.email, u.phone_number, u.status, u.role, u.created_at,
               b.default_shipping_address,
               s.store_name, s.bio, s.trust_badge, s.verification_status,
               lp.language_code
        FROM users u
        LEFT JOIN buyer b ON b.user_id = u.user_id
        LEFT JOIN seller s ON s.user_id = u.user_id
        LEFT JOIN language_preferences lp ON lp.user_id = u.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();

    return $profile ?: null;
}

$profile = fetch_user_profile($conn, $userId);
if (!$profile) {
    die('Profile not found.');
}

$currentLanguage = normalize_language_code($profile['language_code'] ?? $currentLanguage);
set_current_language_code($currentLanguage);

$message = '';
$messageClass = 'notice';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '');
    $deliveryAddress = build_address_from_post('default_shipping');
    $storeName = trim($_POST['store_name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $languageCode = normalize_language_code($_POST['language_code'] ?? $currentLanguage);

    if ($firstName === '' || $lastName === '' || $email === '' || $phone === '' || $storeName === '') {
        $message = t('Please complete all required profile fields.');
        $messageClass = 'notice notice--error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = t('Please enter a valid email address.');
        $messageClass = 'notice notice--error';
    } elseif ($deliveryAddress !== '' && !is_valid_address_format($deliveryAddress)) {
        $message = address_format_message();
        $messageClass = 'notice notice--error';
    } else {
        $profileChanged = (
            $firstName !== (string)($profile['first_name'] ?? '') ||
            $lastName !== (string)($profile['last_name'] ?? '') ||
            $email !== (string)($profile['email'] ?? '') ||
            $phone !== (string)($profile['phone_number'] ?? '') ||
            $deliveryAddress !== normalize_address_input($profile['default_shipping_address'] ?? '') ||
            $storeName !== (string)($profile['store_name'] ?? '') ||
            $bio !== (string)($profile['bio'] ?? '') ||
            $languageCode !== normalize_language_code($profile['language_code'] ?? 'en')
        );

        if (!$profileChanged) {
            $message = t('No profile changes were detected. Nothing was updated.');
            $messageClass = 'notice';
        } else {
            try {
                $conn->begin_transaction();

                $stmt = $conn->prepare("
                    UPDATE users
                    SET first_name = ?, last_name = ?, email = ?, phone_number = ?
                    WHERE user_id = ?
                ");
                $stmt->bind_param('ssssi', $firstName, $lastName, $email, $phone, $userId);
                $stmt->execute();

                $buyerStmt = $conn->prepare("
                    UPDATE buyer
                    SET default_shipping_address = ?
                    WHERE user_id = ?
                ");
                $buyerStmt->bind_param('si', $deliveryAddress, $userId);
                $buyerStmt->execute();

                $sellerStmt = $conn->prepare("
                    UPDATE seller
                    SET store_name = ?, bio = ?
                    WHERE user_id = ?
                ");
                $sellerStmt->bind_param('ssi', $storeName, $bio, $userId);
                $sellerStmt->execute();

                set_user_language($conn, $userId, $languageCode);

                $conn->commit();

                $_SESSION['first_name'] = $firstName;
                $currentLanguage = $languageCode;
                set_current_language_code($currentLanguage);
                $profile = fetch_user_profile($conn, $userId);

                $message = t('Profile updated successfully.');
                $messageClass = 'notice notice--success';
            } catch (Throwable $e) {
                $conn->rollback();

                if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                    $message = t('This email address is already being used by another account.');
                } else {
                    $message = t('Profile could not be updated:') . ' ' . $e->getMessage();
                }

                $messageClass = 'notice notice--error';
            }
        }
    }
}

$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';
$header = get_header($conn, 'manage_account');

echo render_template(__DIR__ . '/templates/profile.html', [
    'HEADER' => $header,
    'MESSAGE' => $messageHtml,
    'FIRST_NAME' => h($profile['first_name'] ?? ''),
    'LAST_NAME' => h($profile['last_name'] ?? ''),
    'EMAIL' => h($profile['email'] ?? ''),
    'PHONE_NUMBER' => h($profile['phone_number'] ?? ''),
    'DELIVERY_ADDRESS_FIELDS' => render_address_fields('default_shipping', $profile['default_shipping_address'] ?? '', false, false),
    'STORE_NAME' => h($profile['store_name'] ?? ''),
    'BIO' => h($profile['bio'] ?? ''),
    'LANGUAGE_OPTIONS' => language_options($currentLanguage),
    'TRUST_BADGE' => h(translate_dynamic_text($profile['trust_badge'] ?? 'New seller')),
    'VERIFICATION_STATUS' => h(translate_dynamic_text($profile['verification_status'] ?? 'pending')),
    'ACCOUNT_STATUS' => h(translate_dynamic_text($profile['status'] ?? '')),
    'ACCOUNT_ROLE' => h(translate_dynamic_text($profile['role'] ?? '')),
    'CREATED_AT' => h($profile['created_at'] ?? ''),
]);
?>
