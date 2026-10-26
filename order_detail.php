<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/review_helpers.php';
require 'includes/complaint_helpers.php';

$userId = ensure_logged_in();
$userLanguage = get_user_language($conn, $userId);
$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);

if ($orderId <= 0) {
    die('Invalid order.');
}

$message = '';
$messageClass = 'notice';

$orderStmt = $conn->prepare("
    SELECT
        o.order_id,
        o.order_date,
        o.status,
        o.total_amount,
        o.delivery_method,
        o.delivery_address,
        u.first_name,
        u.last_name,
        u.email,
        u.phone_number
    FROM orders o
    INNER JOIN users u ON u.user_id = o.buyer_id
    WHERE o.order_id = ? AND o.buyer_id = ?
    LIMIT 1
");
$orderStmt->bind_param('ii', $orderId, $userId);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();

if (!$order) {
    die('Order not found or you do not have permission to view it.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_review') {
    $orderItemId = (int)($_POST['order_item_id'] ?? 0);
    $rating = (int)($_POST['rating'] ?? 0);
    $reviewText = trim($_POST['review_text'] ?? '');

    if ($orderItemId <= 0) {
        $message = 'Invalid review item.';
        $messageClass = 'notice notice--error';
    } elseif ($rating < 1 || $rating > 5) {
        $message = 'Please select a rating between 1 and 5 stars.';
        $messageClass = 'notice notice--error';
    } else {
        $itemCheck = $conn->prepare("
            SELECT oi.order_item_id, oi.listing_id, l.seller_id
            FROM order_items oi
            INNER JOIN listings l ON l.listing_id = oi.listing_id
            WHERE oi.order_item_id = ? AND oi.order_id = ?
            LIMIT 1
        ");
        $itemCheck->bind_param('ii', $orderItemId, $orderId);
        $itemCheck->execute();
        $item = $itemCheck->get_result()->fetch_assoc();

        if (!$item) {
            $message = 'The selected item could not be reviewed.';
            $messageClass = 'notice notice--error';
        } else {
            $existingReviewStmt = $conn->prepare("
                SELECT rating, review_text
                FROM reviews
                WHERE order_item_id = ? AND buyer_id = ?
                LIMIT 1
            ");
            $existingReviewStmt->bind_param('ii', $orderItemId, $userId);
            $existingReviewStmt->execute();
            $existingReview = $existingReviewStmt->get_result()->fetch_assoc();

            if ($existingReview
                && (int)$existingReview['rating'] === $rating
                && trim((string)($existingReview['review_text'] ?? '')) === $reviewText) {
                $message = t('This review already contains the same rating and text. No changes were made.');
                $messageClass = 'notice';
            } else {
                $reviewStmt = $conn->prepare("
                    INSERT INTO reviews (order_id, order_item_id, listing_id, buyer_id, seller_id, rating, review_text)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        rating = VALUES(rating),
                        review_text = VALUES(review_text),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $listingId = (int)$item['listing_id'];
                $sellerId = (int)$item['seller_id'];
                $reviewStmt->bind_param('iiiiiis', $orderId, $orderItemId, $listingId, $userId, $sellerId, $rating, $reviewText);

                if ($reviewStmt->execute()) {
                    $message = t('Your review has been saved and posted.');
                    $messageClass = 'notice notice--success';
                } else {
                    $message = t('Could not save your review.');
                    $messageClass = 'notice notice--error';
                }
            }
        }
    }
}

$itemStmt = $conn->prepare("
    SELECT
        oi.order_item_id,
        oi.quantity,
        oi.unit_price,
        l.title,
        l.listing_id,
        l.seller_id,
        l.location,
        l.`condition`,
        c.category_name,
        r.rating,
        r.review_text,
        r.created_at AS review_created_at,
        (
            SELECT li.image_path
            FROM listing_images li
            WHERE li.listing_id = l.listing_id
            ORDER BY li.is_primary DESC, li.image_id ASC
            LIMIT 1
        ) AS primary_image
    FROM order_items oi
    INNER JOIN listings l ON l.listing_id = oi.listing_id
    INNER JOIN categories c ON c.category_id = l.category_id
    LEFT JOIN reviews r ON r.order_item_id = oi.order_item_id AND r.buyer_id = ?
    WHERE oi.order_id = ?
    ORDER BY l.title ASC
");
$itemStmt->bind_param('ii', $userId, $orderId);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();

$itemsHtml = '';
$productPhotosHtml = '';
$reviewsHtml = '';

while ($item = $itemResult->fetch_assoc()) {
    $lineTotal = (int)$item['quantity'] * (float)$item['unit_price'];

    if (!empty($item['primary_image'])) {
        $productPhotosHtml .= '
          <div class="sale-product-photo">
            <img src="' . h($item['primary_image']) . '" alt="' . h($item['title']) . '">
            <span>' . h($item['title']) . '</span>
          </div>
        ';
    }

    $itemsHtml .= '
      <article class="sale-item-row">
        <div>
          <h3><a href="listing_view.php?id=' . h($item['listing_id']) . '">' . h($item['title']) . '</a></h3>
          <p class="section-note">' . h(translate_dynamic_text($item['category_name'], $userLanguage)) . ' • ' . h(translate_dynamic_text($item['condition'], $userLanguage)) . ' • ' . h(translate_dynamic_text($item['location'], $userLanguage)) . '</p>
        </div>
        <div class="sale-item-row__numbers">
          <span>' . h(t('Qty:')) . ' ' . h($item['quantity']) . '</span>
          <strong>R' . h(number_format($lineTotal, 2)) . '</strong>
        </div>
      </article>
    ';

    $currentRating = (int)($item['rating'] ?? 0);
    $reviewText = (string)($item['review_text'] ?? '');

    $starInputs = '';
    for ($star = 5; $star >= 1; $star--) {
        $checked = $currentRating === $star ? ' checked' : '';
        $starInputs .= '
          <input type="radio" id="rating-' . h($item['order_item_id']) . '-' . $star . '" name="rating" value="' . $star . '"' . $checked . ' required>
          <label for="rating-' . h($item['order_item_id']) . '-' . $star . '" aria-label="' . $star . ' stars">★</label>
        ';
    }

    $reviewStatus = $currentRating > 0
        ? '<div class="existing-review-summary">' . review_star_display($currentRating) . '<span>' . h(t('Posted review')) . '</span></div>'
        : '<p class="section-note">' . h(t('You have not reviewed this item yet.')) . '</p>';

    $reviewsHtml .= '
      <article class="review-form-card">
        <div class="review-form-card__head">
          <div>
            <p class="eyebrow">' . h(t('Review item')) . '</p>
            <h3>' . h($item['title']) . '</h3>
          </div>
          ' . $reviewStatus . '
        </div>

        <form method="post" class="review-form">
          <input type="hidden" name="action" value="save_review">
          <input type="hidden" name="order_id" value="' . h($orderId) . '">
          <input type="hidden" name="order_item_id" value="' . h($item['order_item_id']) . '">

          <div class="star-input" aria-label="' . h(t('Rating')) . '">
            ' . $starInputs . '
          </div>

          <label class="field">
            <span>' . h(t('Review')) . '</span>
            <textarea name="review_text" placeholder="' . h(t('Write an optional review...')) . '">' . h($reviewText) . '</textarea>
          </label>

          <button class="button button--solid" type="submit">' . h(t('Save and post review')) . '</button>
        </form>
      </article>
    ';
}

if ($productPhotosHtml !== '') {
    $productPhotosHtml = '<div class="sale-photo-strip">' . $productPhotosHtml . '</div>';
}

$complaintActionHtml = '
  <div class="complaint-order-action">
    <p class="section-note">' . h(t('Need help with this order? You can raise a complaint for admin review.')) . '</p>
    <a class="button button--solid" href="complaint.php?order_id=' . h($orderId) . '">' . h(t('Raise a complaint')) . '</a>
  </div>
';

$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';
$header = get_header($conn, 'manage_account');

echo render_template(__DIR__ . '/templates/order_detail.html', [
    'HEADER' => $header,
    'MESSAGE' => $messageHtml,
    'ORDER_ID' => h($order['order_id']),
    'ORDER_DATE' => h($order['order_date']),
    'STATUS' => h(translate_dynamic_text(ucfirst($order['status']), $userLanguage)),
    'BUYER_NAME' => h($order['first_name'] . ' ' . $order['last_name']),
    'BUYER_EMAIL' => h($order['email']),
    'BUYER_PHONE' => h($order['phone_number']),
    'METHOD' => h(translate_dynamic_text(ucfirst($order['delivery_method']), $userLanguage)),
    'ADDRESS' => h($order['delivery_address']),
    'ORDER_TOTAL' => 'R' . h(number_format((float)$order['total_amount'], 2)),
    'PRODUCT_PHOTOS' => $productPhotosHtml,
    'ITEMS' => $itemsHtml,
    'REVIEWS' => $reviewsHtml,
    'COMPLAINT_ACTION' => $complaintActionHtml,
]);
?>
