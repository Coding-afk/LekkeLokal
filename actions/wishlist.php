<?php
require_once __DIR__ . '/../includes/security.php';
security_start_session();
require '../includes/db.php';
require '../includes/helpers.php';

$userId = ensure_logged_in();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$listingId = (int)($_POST['listing_id'] ?? 0);
$action = trim($_POST['action'] ?? 'add');
$returnTo = security_safe_redirect_target(
    trim($_POST['return_to'] ?? ''),
    $listingId > 0 ? 'listing_view.php?id=' . $listingId : 'wishlist.php'
);

if ($listingId <= 0) {
    header('Location: ../' . $returnTo);
    exit;
}

try {
    if ($action === 'remove') {
        $stmt = $conn->prepare("DELETE FROM wishlists WHERE user_id = ? AND listing_id = ?");
        $stmt->bind_param('ii', $userId, $listingId);
        $stmt->execute();
    } else {
        $checkStmt = $conn->prepare("
            SELECT listing_id
            FROM listings
            WHERE listing_id = ?
              AND seller_id <> ?
              AND status = 'active'
            LIMIT 1
        ");
        $checkStmt->bind_param('ii', $listingId, $userId);
        $checkStmt->execute();
        $listing = $checkStmt->get_result()->fetch_assoc();

        if ($listing) {
            $stmt = $conn->prepare("
                INSERT IGNORE INTO wishlists (user_id, listing_id)
                VALUES (?, ?)
            ");
            $stmt->bind_param('ii', $userId, $listingId);
            $stmt->execute();
        }
    }
} catch (Throwable $e) {
    // Keep the user on the current page. The wishlist page/listing page will still load normally.
}

header('Location: ../' . $returnTo);
exit;
?>
