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
        u.first_name,
        u.last_name,
        u.email,
        COUNT(oi.order_item_id) AS item_count,
        SUM(oi.quantity * oi.unit_price) AS seller_total,
        GROUP_CONCAT(CONCAT(l.title, ' x', oi.quantity) SEPARATOR ' | ') AS item_summary
    FROM orders o
    INNER JOIN order_items oi ON oi.order_id = o.order_id
    INNER JOIN listings l ON l.listing_id = oi.listing_id
    INNER JOIN users u ON u.user_id = o.buyer_id
    WHERE l.seller_id = ?
    GROUP BY o.order_id, o.order_date, o.status, o.total_amount, o.delivery_method, o.delivery_address, u.first_name, u.last_name, u.email
    ORDER BY o.order_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();

$salesHtml = '';

while ($row = $result->fetch_assoc()) {
    $salesHtml .= '
      <a class="order-card order-card--link" href="sale_detail.php?id=' . h($row['order_id']) . '">
        <div class="order-card__head">
          <div>
            <p class="eyebrow">Sale order #' . h($row['order_id']) . '</p>
            <h2>' . h($row['first_name'] . ' ' . $row['last_name']) . '</h2>
          </div>
          <span class="status-pill status-pill--' . h(strtolower($row['status'])) . '">' . h(translate_dynamic_text(ucfirst($row['status']), $userLanguage)) . '</span>
        </div>

        <p class="section-note">' . h($row['item_summary']) . '</p>

        <div class="order-meta-grid">
          <div><span>Date</span><strong>' . h($row['order_date']) . '</strong></div>
          <div><span>Buyer email</span><strong>' . h($row['email']) . '</strong></div>
          <div><span>Your total</span><strong>R' . h(number_format((float)$row['seller_total'], 2)) . '</strong></div>
          <div><span>Method</span><strong>' . h(translate_dynamic_text(ucfirst($row['delivery_method']), $userLanguage)) . '</strong></div>
        </div>
      </a>
    ';
}

if ($salesHtml === '') {
    $salesHtml = '<div class="empty-state">No one has ordered from your listings yet.</div>';
}

echo render_template(__DIR__ . '/templates/sales.html', [
    'HEADER' => $header,
    'SALES' => $salesHtml,
]);
?>
