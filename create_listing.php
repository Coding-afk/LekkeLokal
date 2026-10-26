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

if (!$verificationState['can_create_listing']) {
    $messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';
    echo render_template(__DIR__ . '/templates/seller_verification.html', [
        'HEADER' => get_header($conn, 'create_listing'),
        'VERIFICATION_FORM' => render_seller_verification_form($verificationState, $messageHtml),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 1);
    $condition = trim($_POST['condition'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $allowDelivery = isset($_POST['allow_delivery']) ? 1 : 0;
    $allowPickup = isset($_POST['allow_pickup']) ? 1 : 0;
    $pickupAddress = build_address_from_post('pickup');
    $status = 'active';

    if ($title === '' || $categoryId <= 0 || $description === '' || $price <= 0 || $quantity <= 0 || $condition === '' || $location === '') {
        $message = 'Please complete all listing fields before submitting.';
        $messageClass = 'notice notice--error';
    } elseif ($allowDelivery === 0 && $allowPickup === 0) {
        $message = 'Please select at least one fulfilment option: delivery, pickup, or both.';
        $messageClass = 'notice notice--error';
    } elseif ($allowPickup === 1 && $pickupAddress === '') {
        $message = 'Please enter the pickup address because pickup is enabled.';
        $messageClass = 'notice notice--error';
    } elseif ($allowPickup === 1 && !is_valid_address_format($pickupAddress)) {
        $message = address_format_message();
        $messageClass = 'notice notice--error';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO listings
            (seller_id, category_id, title, description, price, quantity, `condition`, location, allow_delivery, allow_pickup, pickup_address, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'iissdissiiss',
            $userId,
            $categoryId,
            $title,
            $description,
            $price,
            $quantity,
            $condition,
            $location,
            $allowDelivery,
            $allowPickup,
            $pickupAddress,
            $status
        );

        if ($stmt->execute()) {
            $listingId = (int)$conn->insert_id;
            $uploadResult = save_listing_images(
            $_FILES['photos'] ?? [],
            $listingId,
            $conn
        );

        if (!empty($uploadResult['errors'])) {
            $_SESSION['listing_photo_upload_notice'] = [
                'added' => (int)$uploadResult['added'],
                'errors' => $uploadResult['errors'],
            ];
        }

        header('Location: listing_detail.php?id=' . $listingId);
        exit;
        } else {
            $message = 'Listing could not be created. Please make sure the database has been updated with the Buy Now fields.';
            $messageClass = 'notice notice--error';
        }
    }
}

$header = get_header($conn, 'create_listing');
$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';

echo render_template(__DIR__ . '/templates/create_listing.html', [
    'HEADER' => $header,
    'MESSAGE' => $messageHtml,
    'CATEGORY_OPTIONS' => build_category_options($conn, null, false),
    'LOCATION_OPTIONS' => build_location_options(null, false),
    'CONDITION_OPTIONS' => build_condition_options(null),
    'PICKUP_ADDRESS_FIELDS' => render_address_fields('pickup', '', false, false),
]);
?>
