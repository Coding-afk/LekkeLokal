<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/admin_helpers.php';

$adminId = ensure_admin($conn);
get_user_language($conn, $adminId);

function admin_stat_scalar(mysqli $conn, string $sql, $default = 0) {
    $result = $conn->query($sql);
    if (!$result) {
        return $default;
    }

    $row = $result->fetch_row();
    if (!$row) {
        return $default;
    }

    return $row[0] ?? $default;
}

function admin_stat_rows(mysqli $conn, string $sql): array {
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function admin_stat_int($value): string {
    return number_format((int)$value);
}

function admin_stat_money($value): string {
    return 'R' . number_format((float)$value, 2);
}

function admin_stat_rating($value): string {
    $value = (float)$value;
    return $value > 0 ? number_format($value, 1) . ' / 5' : '0 / 5';
}

function admin_metric_card(string $label, string $value, string $note = '', string $icon = '•'): string {
    return '
      <article class="stats-metric-card">
        <div class="stats-metric-card__top">
          <span class="stats-metric-card__icon">' . h($icon) . '</span>
          <span>' . h(t($label)) . '</span>
        </div>
        <strong>' . h($value) . '</strong>
        ' . ($note !== '' ? '<small>' . h(t($note)) . '</small>' : '') . '
      </article>
    ';
}

function admin_bar_width(float $value, float $max): string {
    if ($max <= 0) {
        return '0%';
    }

    $width = max(4, min(100, ($value / $max) * 100));
    return number_format($width, 2, '.', '') . '%';
}

function admin_table_empty(int $columns): string {
    return '<tr><td colspan="' . h($columns) . '">' . h(t('No data available yet.')) . '</td></tr>';
}

$activeToday = admin_stat_scalar($conn, "
    SELECT COUNT(DISTINCT user_id)
    FROM (
      SELECT user_id FROM users WHERE DATE(created_at) = CURDATE()
      UNION
      SELECT buyer_id AS user_id FROM orders WHERE DATE(order_date) = CURDATE()
      UNION
      SELECT seller_id AS user_id FROM listings WHERE DATE(created_at) = CURDATE()
      UNION
      SELECT buyer_id AS user_id FROM reviews WHERE DATE(created_at) = CURDATE()
      UNION
      SELECT buyer_id AS user_id FROM complaints WHERE DATE(created_at) = CURDATE()
      UNION
      SELECT user_id FROM wishlists WHERE DATE(created_at) = CURDATE()
      UNION
      SELECT sender_id AS user_id FROM chat_messages WHERE DATE(sent_at) = CURDATE()
    ) daily_activity
");

$totalUsers = admin_stat_scalar($conn, "SELECT COUNT(*) FROM users");
$activeUsers = admin_stat_scalar($conn, "SELECT COUNT(*) FROM users WHERE status = 'active'");
$newSignupsToday = admin_stat_scalar($conn, "SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()");
$newSignupsMonth = admin_stat_scalar($conn, "SELECT COUNT(*) FROM users WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
$totalBuyers = admin_stat_scalar($conn, "SELECT COUNT(*) FROM buyer");
$totalSellers = admin_stat_scalar($conn, "SELECT COUNT(*) FROM seller");
$totalAdmins = admin_stat_scalar($conn, "SELECT COUNT(*) FROM admin");

$activeListings = admin_stat_scalar($conn, "SELECT COUNT(*) FROM listings WHERE status = 'active'");
$totalListings = admin_stat_scalar($conn, "SELECT COUNT(*) FROM listings");
$listingsMonth = admin_stat_scalar($conn, "SELECT COUNT(*) FROM listings WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
$outOfStock = admin_stat_scalar($conn, "SELECT COUNT(*) FROM listings WHERE quantity <= 0");
$totalWishlist = admin_stat_scalar($conn, "SELECT COUNT(*) FROM wishlists");

$ordersToday = admin_stat_scalar($conn, "SELECT COUNT(*) FROM orders WHERE DATE(order_date) = CURDATE()");
$ordersMonth = admin_stat_scalar($conn, "SELECT COUNT(*) FROM orders WHERE YEAR(order_date) = YEAR(CURDATE()) AND MONTH(order_date) = MONTH(CURDATE())");
$totalOrders = admin_stat_scalar($conn, "SELECT COUNT(*) FROM orders");
$orderValueMonth = admin_stat_scalar($conn, "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE YEAR(order_date) = YEAR(CURDATE()) AND MONTH(order_date) = MONTH(CURDATE())");
$totalOrderValue = admin_stat_scalar($conn, "SELECT COALESCE(SUM(total_amount), 0) FROM orders");
$avgOrderValue = admin_stat_scalar($conn, "SELECT COALESCE(AVG(total_amount), 0) FROM orders");

$totalComplaints = admin_stat_scalar($conn, "SELECT COUNT(*) FROM complaints");
$openComplaints = admin_stat_scalar($conn, "SELECT COUNT(*) FROM complaints WHERE status IN ('open', 'pending')");
$completedComplaints = admin_stat_scalar($conn, "SELECT COUNT(*) FROM complaints WHERE status IN ('completed', 'handled')");
$complaintsMonth = admin_stat_scalar($conn, "SELECT COUNT(*) FROM complaints WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");

$totalReviews = admin_stat_scalar($conn, "SELECT COUNT(*) FROM reviews");
$avgRating = admin_stat_scalar($conn, "SELECT COALESCE(AVG(rating), 0) FROM reviews");
$reviewsMonth = admin_stat_scalar($conn, "SELECT COUNT(*) FROM reviews WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");

$totalChats = admin_stat_scalar($conn, "SELECT COUNT(*) FROM chats");
$messagesToday = admin_stat_scalar($conn, "SELECT COUNT(*) FROM chat_messages WHERE DATE(sent_at) = CURDATE()");
$totalMessages = admin_stat_scalar($conn, "SELECT COUNT(*) FROM chat_messages");

$metricCards = '';
$metricCards .= admin_metric_card('Estimated active users today', admin_stat_int($activeToday), 'Based on signups, listings, orders, reviews, complaints, wishlist saves, and chat messages created today.', '◉');
$metricCards .= admin_metric_card('New sign ups today', admin_stat_int($newSignupsToday), 'New accounts created today.', '＋');
$metricCards .= admin_metric_card('New sign ups this month', admin_stat_int($newSignupsMonth), 'New accounts created this month.', '↗');
$metricCards .= admin_metric_card('Total users', admin_stat_int($totalUsers), 'All registered user accounts.', '👥');
$metricCards .= admin_metric_card('Active users', admin_stat_int($activeUsers), 'Accounts currently marked as active.', '✓');
$metricCards .= admin_metric_card('Total buyers', admin_stat_int($totalBuyers), 'Buyer profiles on the platform.', '🛒');
$metricCards .= admin_metric_card('Total sellers', admin_stat_int($totalSellers), 'Seller profiles on the platform.', '🏪');
$metricCards .= admin_metric_card('Total admins', admin_stat_int($totalAdmins), 'Admin accounts with backend access.', '⚙');

$marketCards = '';
$marketCards .= admin_metric_card('Active listings', admin_stat_int($activeListings), 'Listings currently visible to buyers.', '▣');
$marketCards .= admin_metric_card('Total listings', admin_stat_int($totalListings), 'All listings created on the platform.', '▦');
$marketCards .= admin_metric_card('Listings created this month', admin_stat_int($listingsMonth), 'New listings posted this month.', '🗓');
$marketCards .= admin_metric_card('Out of stock listings', admin_stat_int($outOfStock), 'Listings with no available quantity.', '!');
$marketCards .= admin_metric_card('Wishlist saves', admin_stat_int($totalWishlist), 'Total wishlist saves by buyers.', '♥');

$orderCards = '';
$orderCards .= admin_metric_card('Orders today', admin_stat_int($ordersToday), 'Orders placed today.', '●');
$orderCards .= admin_metric_card('Orders this month', admin_stat_int($ordersMonth), 'Orders placed during the current month.', '◌');
$orderCards .= admin_metric_card('Total orders', admin_stat_int($totalOrders), 'All orders placed on the platform.', '#');
$orderCards .= admin_metric_card('Order value this month', admin_stat_money($orderValueMonth), 'Total order value for the current month.', 'R');
$orderCards .= admin_metric_card('Total order value', admin_stat_money($totalOrderValue), 'Total value of all placed orders.', 'Σ');
$orderCards .= admin_metric_card('Average order value', admin_stat_money($avgOrderValue), 'Average value per order.', 'Ø');

$trustCards = '';
$trustCards .= admin_metric_card('Open complaints', admin_stat_int($openComplaints), 'Complaints still needing admin review.', '⚠');
$trustCards .= admin_metric_card('Total complaints', admin_stat_int($totalComplaints), 'All complaints submitted by buyers.', '!');
$trustCards .= admin_metric_card('Completed complaints', admin_stat_int($completedComplaints), 'Complaints marked as handled/completed.', '✓');
$trustCards .= admin_metric_card('Complaints this month', admin_stat_int($complaintsMonth), 'Complaints submitted this month.', '🗓');
$trustCards .= admin_metric_card('Average rating', admin_stat_rating($avgRating), 'Average buyer review rating.', '★');
$trustCards .= admin_metric_card('Total reviews', admin_stat_int($totalReviews), 'All buyer reviews posted.', '✎');
$trustCards .= admin_metric_card('Reviews this month', admin_stat_int($reviewsMonth), 'Reviews posted this month.', '✦');

$engagementCards = '';
$engagementCards .= admin_metric_card('Chats', admin_stat_int($totalChats), 'Buyer-seller conversations created.', '✉');
$engagementCards .= admin_metric_card('Messages sent today', admin_stat_int($messagesToday), 'Chat messages sent today.', '↪');
$engagementCards .= admin_metric_card('Total messages', admin_stat_int($totalMessages), 'All chat messages sent.', '☰');

$orderStatusRows = admin_stat_rows($conn, "
    SELECT status, COUNT(*) AS total, COALESCE(SUM(total_amount), 0) AS value
    FROM orders
    GROUP BY status
    ORDER BY total DESC
");
$orderStatusHtml = '';
foreach ($orderStatusRows as $row) {
    $orderStatusHtml .= '
      <tr>
        <td><span class="status-pill">' . h(translate_dynamic_text(ucfirst((string)$row['status']))) . '</span></td>
        <td>' . h(admin_stat_int($row['total'])) . '</td>
        <td>' . h(admin_stat_money($row['value'])) . '</td>
      </tr>
    ';
}
if ($orderStatusHtml === '') {
    $orderStatusHtml = admin_table_empty(3);
}

$orderMethodRows = admin_stat_rows($conn, "
    SELECT delivery_method, COUNT(*) AS total, COALESCE(SUM(total_amount), 0) AS value
    FROM orders
    GROUP BY delivery_method
    ORDER BY total DESC
");
$orderMethodHtml = '';
foreach ($orderMethodRows as $row) {
    $orderMethodHtml .= '
      <tr>
        <td>' . h(translate_dynamic_text(ucfirst((string)$row['delivery_method']))) . '</td>
        <td>' . h(admin_stat_int($row['total'])) . '</td>
        <td>' . h(admin_stat_money($row['value'])) . '</td>
      </tr>
    ';
}
if ($orderMethodHtml === '') {
    $orderMethodHtml = admin_table_empty(3);
}

$complaintTypeRows = admin_stat_rows($conn, "
    SELECT complaint_type, COUNT(*) AS total
    FROM complaints
    GROUP BY complaint_type
    ORDER BY total DESC, complaint_type ASC
");
$complaintTypeHtml = '';
$maxComplaintType = 0;
foreach ($complaintTypeRows as $row) {
    $maxComplaintType = max($maxComplaintType, (int)$row['total']);
}
foreach ($complaintTypeRows as $row) {
    $width = admin_bar_width((int)$row['total'], $maxComplaintType);
    $complaintTypeHtml .= '
      <div class="stats-bar-row">
        <div>
          <strong>' . h(t((string)$row['complaint_type'])) . '</strong>
          <span>' . h(admin_stat_int($row['total'])) . ' ' . h(t('complaints')) . '</span>
        </div>
        <div class="stats-bar-track"><span style="width:' . h($width) . '"></span></div>
      </div>
    ';
}
if ($complaintTypeHtml === '') {
    $complaintTypeHtml = '<div class="empty-state">' . h(t('No data available yet.')) . '</div>';
}

$topCategoriesRows = admin_stat_rows($conn, "
    SELECT c.category_name, COUNT(l.listing_id) AS total
    FROM categories c
    LEFT JOIN listings l ON l.category_id = c.category_id AND l.status = 'active'
    GROUP BY c.category_id, c.category_name
    ORDER BY total DESC, c.category_name ASC
    LIMIT 8
");
$topCategoriesHtml = '';
$maxTopCategory = 0;
foreach ($topCategoriesRows as $row) {
    $maxTopCategory = max($maxTopCategory, (int)$row['total']);
}
foreach ($topCategoriesRows as $row) {
    $width = admin_bar_width((int)$row['total'], $maxTopCategory);
    $topCategoriesHtml .= '
      <div class="stats-bar-row">
        <div>
          <strong>' . h(translate_dynamic_text($row['category_name'])) . '</strong>
          <span>' . h(admin_stat_int($row['total'])) . ' ' . h(t('active listings')) . '</span>
        </div>
        <div class="stats-bar-track"><span style="width:' . h($width) . '"></span></div>
      </div>
    ';
}
if ($topCategoriesHtml === '') {
    $topCategoriesHtml = '<div class="empty-state">' . h(t('No data available yet.')) . '</div>';
}

$topSoldRows = admin_stat_rows($conn, "
    SELECT c.category_name, COALESCE(SUM(oi.quantity), 0) AS items_sold, COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS value
    FROM categories c
    INNER JOIN listings l ON l.category_id = c.category_id
    INNER JOIN order_items oi ON oi.listing_id = l.listing_id
    GROUP BY c.category_id, c.category_name
    ORDER BY items_sold DESC, value DESC
    LIMIT 8
");
$topSoldHtml = '';
foreach ($topSoldRows as $row) {
    $topSoldHtml .= '
      <tr>
        <td>' . h(translate_dynamic_text($row['category_name'])) . '</td>
        <td>' . h(admin_stat_int($row['items_sold'])) . '</td>
        <td>' . h(admin_stat_money($row['value'])) . '</td>
      </tr>
    ';
}
if ($topSoldHtml === '') {
    $topSoldHtml = admin_table_empty(3);
}

$topSellerRows = admin_stat_rows($conn, "
    SELECT s.store_name, u.first_name, u.last_name, COUNT(DISTINCT o.order_id) AS orders_count,
           COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS value
    FROM seller s
    INNER JOIN users u ON u.user_id = s.user_id
    INNER JOIN listings l ON l.seller_id = s.user_id
    INNER JOIN order_items oi ON oi.listing_id = l.listing_id
    INNER JOIN orders o ON o.order_id = oi.order_id
    GROUP BY s.user_id, s.store_name, u.first_name, u.last_name
    ORDER BY value DESC, orders_count DESC
    LIMIT 8
");
$topSellerHtml = '';
foreach ($topSellerRows as $row) {
    $sellerName = $row['store_name'] !== '' ? $row['store_name'] : trim($row['first_name'] . ' ' . $row['last_name']);
    $topSellerHtml .= '
      <tr>
        <td>' . h($sellerName) . '</td>
        <td>' . h(admin_stat_int($row['orders_count'])) . '</td>
        <td>' . h(admin_stat_money($row['value'])) . '</td>
      </tr>
    ';
}
if ($topSellerHtml === '') {
    $topSellerHtml = admin_table_empty(3);
}

$recentComplaintsRows = admin_stat_rows($conn, "
    SELECT c.complaint_id, c.complaint_type, c.created_at, c.status,
           buyer.first_name AS buyer_first_name, buyer.last_name AS buyer_last_name,
           s.store_name
    FROM complaints c
    INNER JOIN users buyer ON buyer.user_id = c.buyer_id
    INNER JOIN seller s ON s.user_id = c.seller_id
    WHERE c.status IN ('open', 'pending')
    ORDER BY c.created_at DESC
    LIMIT 5
");
$recentComplaintsHtml = '';
foreach ($recentComplaintsRows as $row) {
    $recentComplaintsHtml .= '
      <tr>
        <td>#' . h($row['complaint_id']) . '</td>
        <td>' . h(t($row['complaint_type'])) . '</td>
        <td>' . h(trim($row['buyer_first_name'] . ' ' . $row['buyer_last_name'])) . '</td>
        <td>' . h($row['store_name']) . '</td>
        <td>' . h($row['created_at']) . '</td>
      </tr>
    ';
}
if ($recentComplaintsHtml === '') {
    $recentComplaintsHtml = admin_table_empty(5);
}

echo render_template(__DIR__ . '/templates/admin_statistics.html', [
    'ADMIN_NAME' => h($_SESSION['first_name'] ?? 'Admin'),
    'UPDATED_AT' => h(date('Y-m-d H:i')),
    'METRIC_CARDS' => $metricCards,
    'MARKET_CARDS' => $marketCards,
    'ORDER_CARDS' => $orderCards,
    'TRUST_CARDS' => $trustCards,
    'ENGAGEMENT_CARDS' => $engagementCards,
    'ORDER_STATUS_ROWS' => $orderStatusHtml,
    'ORDER_METHOD_ROWS' => $orderMethodHtml,
    'COMPLAINT_TYPE_ROWS' => $complaintTypeHtml,
    'TOP_CATEGORIES_ROWS' => $topCategoriesHtml,
    'TOP_SOLD_ROWS' => $topSoldHtml,
    'TOP_SELLERS_ROWS' => $topSellerHtml,
    'RECENT_COMPLAINTS_ROWS' => $recentComplaintsHtml,
]);
?>
