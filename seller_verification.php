<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/seller_verification_helpers.php';

$userId = ensure_logged_in();
ensure_user_profiles($conn, $userId, ($_SESSION['first_name'] ?? 'Seller') . "'s Store");

$message = '';
$messageClass = 'notice';

$verificationState = get_seller_verification_state($conn, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['seller_verification_action'] ?? '') === 'submit') {
    $verificationResult = submit_seller_verification($conn, $userId, $_POST, $_FILES, $verificationState);
    $message = $verificationResult['message'];
    $messageClass = $verificationResult['success'] ? 'notice notice--success' : 'notice notice--error';
    $verificationState = get_seller_verification_state($conn, $userId);
}

$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';
$verificationContent = $verificationState['can_create_listing']
    ? '
      <section class="content-panel content-panel--single seller-verification-panel">
        <p class="eyebrow">' . h(t('Seller verification')) . '</p>
        <h1>' . h(t('Seller verified')) . '</h1>
        <p class="page-intro">' . h(t('Your seller verification is up to date.')) . '</p>
        ' . $messageHtml . '
        <div class="form-actions">
          <a class="button button--solid" href="create_listing.php">' . h(t('Create new listing')) . '</a>
          <a class="button button--ghost" href="manage_account.php">' . h(t('Back to account')) . '</a>
        </div>
      </section>
    '
    : render_seller_verification_form($verificationState, $messageHtml);

echo render_template(__DIR__ . '/templates/seller_verification.html', [
    'HEADER' => get_header($conn, 'manage_account'),
    'VERIFICATION_FORM' => $verificationContent,
]);
?>
