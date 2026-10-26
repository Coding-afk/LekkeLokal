<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/review_helpers.php';

$userId = ensure_logged_in();
$userLanguage = get_user_language($conn, $userId);
$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);

if ($orderId <= 0) {
    die('Invalid sale order.');
}

$message = '';
$messageClass = 'notice';

$checkStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM orders o
    INNER JOIN order_items oi ON oi.order_id = o.order_id
    INNER JOIN listings l ON l.listing_id = oi.listing_id
    WHERE o.order_id = ? AND l.seller_id = ?
");
$checkStmt->bind_param('ii', $orderId, $userId);
$checkStmt->execute();
$allowed = (int)($checkStmt->get_result()->fetch_assoc()['total'] ?? 0);

if ($allowed <= 0) {
    die('Sale order not found or you do not have permission to view it.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = $_POST['status'] ?? '';

    if ($newStatus !== 'completed') {
        $message = 'Invalid status update.';
        $messageClass = 'notice notice--error';
    } else {
        $update = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ? AND status <> 'completed'");
        $update->bind_param('si', $newStatus, $orderId);

        if ($update->execute()) {
            if ($update->affected_rows > 0) {
                $message = t('Sale confirmed as completed/handled.');
                $messageClass = 'notice notice--success';
            } else {
                $message = t('This sale is already completed. No changes were made.');
                $messageClass = 'notice';
            }
        } else {
            $message = t('Could not update sale status.');
            $messageClass = 'notice notice--error';
        }
    }
}

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
    WHERE o.order_id = ?
    LIMIT 1
");
$orderStmt->bind_param('i', $orderId);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();

$itemStmt = $conn->prepare("
    SELECT
        oi.order_item_id,
        oi.quantity,
        oi.unit_price,
        l.title,
        l.listing_id,
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
    LEFT JOIN reviews r ON r.order_item_id = oi.order_item_id
    WHERE oi.order_id = ? AND l.seller_id = ?
    ORDER BY l.title ASC
");
$itemStmt->bind_param('ii', $orderId, $userId);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();

$itemsHtml = '';
$buyerReviewsHtml = '';
$productPhotosHtml = '';
$sellerTotal = 0.0;

while ($item = $itemResult->fetch_assoc()) {
    $lineTotal = (int)$item['quantity'] * (float)$item['unit_price'];
    $sellerTotal += $lineTotal;

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
          <h3>' . h($item['title']) . '</h3>
          <p class="section-note">' . h(translate_dynamic_text($item['category_name'], $userLanguage)) . ' • ' . h(translate_dynamic_text($item['condition'], $userLanguage)) . ' • ' . h(translate_dynamic_text($item['location'], $userLanguage)) . '</p>
        </div>
        <div class="sale-item-row__numbers">
          <span>' . h(t('Qty:')) . ' ' . h($item['quantity']) . '</span>
          <strong>R' . h(number_format($lineTotal, 2)) . '</strong>
        </div>
      </article>
    ';

    $buyerRating = (int)($item['rating'] ?? 0);
    $buyerReviewText = trim((string)($item['review_text'] ?? ''));

    if ($buyerRating > 0) {
        $buyerReviewTextHtml = $buyerReviewText !== ''
            ? '<p data-no-translate>' . nl2br(h($buyerReviewText)) . '</p>'
            : '<p class="section-note">' . h(t('No written review.')) . '</p>';

        $buyerReviewsHtml .= '
          <article class="review-card sale-review-card">
            <div class="review-card__head">
              <div>
                <strong>' . h($item['title']) . '</strong>
                <span>' . h(t('Posted on')) . ' ' . h($item['review_created_at']) . '</span>
              </div>
              ' . review_star_display((float)$buyerRating) . '
            </div>
            ' . $buyerReviewTextHtml . '
          </article>
        ';
    } else {
        $buyerReviewsHtml .= '
          <article class="review-card sale-review-card review-card--empty">
            <div class="review-card__head">
              <div>
                <strong>' . h($item['title']) . '</strong>
                <span>' . h(t('Buyer review')) . '</span>
              </div>
              <span class="review-stars">☆☆☆☆☆</span>
            </div>
            <p class="section-note">' . h(t('No review posted yet.')) . '</p>
          </article>
        ';
    }
}

if ($productPhotosHtml !== '') {
    $productPhotosHtml = '<div class="sale-photo-strip">' . $productPhotosHtml . '</div>';
}

$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';
$statusButton = ($order['status'] ?? '') === 'completed'
    ? '<button class="button button--ghost" type="button" disabled>' . h(t('Already completed')) . '</button>'
    : '<button class="button button--solid" type="submit">' . h(t('Confirm completed / handled')) . '</button>';

$header = get_header($conn, 'manage_account');

echo render_template(__DIR__ . '/templates/sale_detail.html', [
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
    'SELLER_TOTAL' => 'R' . h(number_format($sellerTotal, 2)),
    'PRODUCT_PHOTOS' => $productPhotosHtml,
    'ITEMS' => $itemsHtml,
    'BUYER_REVIEWS' => $buyerReviewsHtml,
    'STATUS_BUTTON' => $statusButton,
]);
?>
