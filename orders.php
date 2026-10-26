<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';

$userId = ensure_logged_in();
$userLanguage = get_user_language($conn, $userId);
$header = get_header($conn, 'manage_account');

$sql = "
    SELECT
        o.order_id,
        o.order_date,
        o.status,
        o.total_amount,
        o.delivery_method,
        o.delivery_address,
        COUNT(oi.order_item_id) AS item_count,
        GROUP_CONCAT(CONCAT(l.title, ' x', oi.quantity) SEPARATOR ' | ') AS item_summary
    FROM orders o
    INNER JOIN order_items oi ON oi.order_id = o.order_id
    INNER JOIN listings l ON l.listing_id = oi.listing_id
    WHERE o.buyer_id = ?
    GROUP BY o.order_id, o.order_date, o.status, o.total_amount, o.delivery_method, o.delivery_address
    ORDER BY o.order_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();

$ordersHtml = '';

while ($row = $result->fetch_assoc()) {
    $ordersHtml .= '
      <a class="order-card order-card--link" href="order_detail.php?id=' . h($row['order_id']) . '">
        <div class="order-card__head">
          <div>
            <p class="eyebrow">Order #' . h($row['order_id']) . '</p>
            <h2>' . h($row['item_summary']) . '</h2>
          </div>
          <span class="status-pill status-pill--' . h(strtolower($row['status'])) . '">' . h(translate_dynamic_text(ucfirst($row['status']), $userLanguage)) . '</span>
        </div>

        <div class="order-meta-grid">
          <div><span>Date</span><strong>' . h($row['order_date']) . '</strong></div>
          <div><span>Total</span><strong>R' . h(number_format((float)$row['total_amount'], 2)) . '</strong></div>
          <div><span>Items</span><strong>' . h($row['item_count']) . '</strong></div>
          <div><span>Method</span><strong>' . h(translate_dynamic_text(ucfirst($row['delivery_method']), $userLanguage)) . '</strong></div>
        </div>

        <p class="order-address">' . h($row['delivery_address'] ?? '') . '</p>
      </a>
    ';
}

if ($ordersHtml === '') {
    $ordersHtml = '<div class="empty-state">You have not placed any orders yet.</div>';
}

echo render_template(__DIR__ . '/templates/orders.html', [
    'HEADER' => $header,
    'ORDERS' => $ordersHtml,
]);
?>
