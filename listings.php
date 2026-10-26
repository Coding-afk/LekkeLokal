<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/seller_verification_helpers.php';

$userId = ensure_logged_in();
enforce_listing_management_verification($conn, $userId, 'manage_account');
$header = get_header($conn, 'manage_account');

$messageHtml = '';

if (($_GET['deleted'] ?? '') === '1') {
    $messageHtml = '<div class="notice notice--success">Listing removed successfully.</div>';
}

$sql = "
    SELECT l.listing_id, l.title, l.description, l.price, l.quantity, l.`condition`, l.location, l.status, l.created_at,
           c.category_name,
           (
             SELECT li.image_path
             FROM listing_images li
             WHERE li.listing_id = l.listing_id
             ORDER BY li.is_primary DESC, li.image_id ASC
             LIMIT 1
           ) AS primary_image
    FROM listings l
    INNER JOIN categories c ON c.category_id = l.category_id
    WHERE l.seller_id = ?
    ORDER BY l.created_at DESC
";

$listingsHtml = '';
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $listingsHtml .= render_listing_card($row, true);
}

if ($listingsHtml === '') {
    $listingsHtml = '<div class="empty-state">You have not created any listings yet. Use Create new listing to add your first product.</div>';
}

echo render_template(__DIR__ . '/templates/listings.html', [
    'HEADER' => $header,
    'MESSAGE' => $messageHtml,
    'LISTINGS' => $listingsHtml,
]);
?>
