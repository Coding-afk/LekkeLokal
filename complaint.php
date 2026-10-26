<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/complaint_helpers.php';

$userId = ensure_logged_in();
$userLanguage = get_user_language($conn, $userId);
$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if ($orderId <= 0) {
    die(t('Invalid order.'));
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
    WHERE o.order_id = ? AND o.buyer_id = ?
    LIMIT 1
");
$orderStmt->bind_param('ii', $orderId, $userId);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();

if (!$order) {
    die(t('Order not found or you do not have permission to view it.'));
}

$sellers = get_order_seller_summary($conn, $orderId);
$primarySellerId = get_primary_order_seller_id($conn, $orderId);

if ($primarySellerId <= 0) {
    die(t('Seller details could not be loaded for this order.'));
}

$message = '';
$messageClass = 'notice';
$currentType = '';
$currentText = '';
$unchangedComplaint = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complaintType = trim($_POST['complaint_type'] ?? '');
    $complaintText = trim($_POST['complaint_text'] ?? '');

    $currentType = $complaintType;
    $currentText = $complaintText;

    if (!complaint_type_allowed($complaintType)) {
        $message = t('Please select a valid complaint type.');
        $messageClass = 'notice notice--error';
    } elseif ($complaintText === '') {
        $message = t('Please explain your complaint before posting it.');
        $messageClass = 'notice notice--error';
    } else {
        $openStmt = $conn->prepare("
            SELECT complaint_id, complaint_text
            FROM complaints
            WHERE order_id = ?
              AND buyer_id = ?
              AND complaint_type = ?
              AND status NOT IN ('completed', 'handled')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $openStmt->bind_param('iis', $orderId, $userId, $complaintType);
        $openStmt->execute();
        $openComplaint = $openStmt->get_result()->fetch_assoc();

        if ($openComplaint) {
            if ($complaintText === trim((string)($openComplaint['complaint_text'] ?? ''))) {
                $success = true;
                $unchangedComplaint = true;
            } else {
                $update = $conn->prepare("
                    UPDATE complaints
                    SET complaint_text = ?,
                        resolution_text = NULL,
                        status = 'open',
                        handled_at = NULL,
                        handled_by = NULL
                    WHERE complaint_id = ? AND buyer_id = ?
                ");
                $complaintId = (int)$openComplaint['complaint_id'];
                $update->bind_param('sii', $complaintText, $complaintId, $userId);
                $success = $update->execute();
                $unchangedComplaint = $update->affected_rows === 0;
            }
        } else {
            $insert = $conn->prepare("
                INSERT INTO complaints (order_id, buyer_id, seller_id, complaint_type, complaint_text)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert->bind_param('iiiss', $orderId, $userId, $primarySellerId, $complaintType, $complaintText);
            $success = $insert->execute();
        }

        if ($success) {
            if (!empty($unchangedComplaint)) {
                $message = t('This complaint already contains the same details. No changes were made.');
                $messageClass = 'notice';
            } else {
                $message = t('Your complaint has been saved successfully.');
                $messageClass = 'notice notice--success';
                $currentType = '';
                $currentText = '';
            }
        } else {
            $message = t('Complaint could not be saved. Please try again.');
            $messageClass = 'notice notice--error';
        }
    }
}

$itemStmt = $conn->prepare("
    SELECT
        oi.quantity,
        oi.unit_price,
        l.title,
        l.listing_id,
        c.category_name,
        l.`condition`,
        l.location
    FROM order_items oi
    INNER JOIN listings l ON l.listing_id = oi.listing_id
    INNER JOIN categories c ON c.category_id = l.category_id
    WHERE oi.order_id = ?
    ORDER BY l.title ASC
");
$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();

$itemsHtml = '';
while ($item = $itemResult->fetch_assoc()) {
    $lineTotal = (int)$item['quantity'] * (float)$item['unit_price'];

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
}

$sellersHtml = '';
foreach ($sellers as $seller) {
    $sellersHtml .= '
      <div class="info-list info-list--profile complaint-seller-card">
        <div class="info-row"><span>' . h(t('Seller store')) . '</span><strong>' . h($seller['store_name']) . '</strong></div>
        <div class="info-row"><span>' . h(t('Seller email')) . '</span><strong>' . h($seller['email']) . '</strong></div>
        <div class="info-row"><span>' . h(t('Seller phone')) . '</span><strong>' . h($seller['phone_number']) . '</strong></div>
      </div>
    ';
}

$historyStmt = $conn->prepare("
    SELECT
        c.complaint_id,
        c.complaint_type,
        c.complaint_text,
        c.resolution_text,
        c.status,
        c.created_at,
        c.handled_at,
        admin_user.first_name AS admin_first_name,
        admin_user.last_name AS admin_last_name
    FROM complaints c
    LEFT JOIN users admin_user ON admin_user.user_id = c.handled_by
    WHERE c.order_id = ? AND c.buyer_id = ?
    ORDER BY c.created_at DESC
");
$historyStmt->bind_param('ii', $orderId, $userId);
$historyStmt->execute();
$historyResult = $historyStmt->get_result();

$complaintHistoryHtml = '';
while ($complaint = $historyResult->fetch_assoc()) {
    $status = strtolower((string)$complaint['status']);
    $statusClass = in_array($status, ['completed', 'handled'], true) ? ' status-pill--completed' : '';
    $adminName = trim((string)(($complaint['admin_first_name'] ?? '') . ' ' . ($complaint['admin_last_name'] ?? '')));
    $handledInfo = in_array($status, ['completed', 'handled'], true)
        ? '<p class="section-note">' . h(t('Handled on')) . ' ' . h($complaint['handled_at'] ?? '') . ($adminName !== '' ? ' ' . h(t('by')) . ' ' . h($adminName) : '') . '</p>'
        : '';

    $resolution = trim((string)($complaint['resolution_text'] ?? ''));
    $resolutionHtml = $resolution !== ''
        ? '<div class="complaint-resolution-box"><strong>' . h(t('Admin resolution')) . '</strong><p data-no-translate>' . nl2br(h($resolution)) . '</p></div>'
        : '<p class="section-note">' . h(t('No admin resolution has been posted yet.')) . '</p>';

    $complaintHistoryHtml .= '
      <article class="complaint-history-card">
        <div class="complaint-history-card__head">
          <div>
            <p class="eyebrow">' . h(t('Complaint')) . ' #' . h($complaint['complaint_id']) . '</p>
            <h3>' . h(t($complaint['complaint_type'])) . '</h3>
            <p class="section-note">' . h($complaint['created_at']) . '</p>
          </div>
          <span class="status-pill' . $statusClass . '">' . h(complaint_status_label($status)) . '</span>
        </div>
        <div class="complaint-admin-text">
          <h4>' . h(t('Your complaint')) . '</h4>
          <p data-no-translate>' . nl2br(h($complaint['complaint_text'])) . '</p>
        </div>
        ' . $resolutionHtml . '
        ' . $handledInfo . '
      </article>
    ';
}

if ($complaintHistoryHtml === '') {
    $complaintHistoryHtml = '<div class="empty-state">' . h(t('No complaints have been raised for this order yet.')) . '</div>';
}

$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';
$header = get_header($conn, 'manage_account');

echo render_template(__DIR__ . '/templates/complaint.html', [
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
    'ITEMS' => $itemsHtml,
    'SELLERS' => $sellersHtml,
    'COMPLAINT_TYPE_OPTIONS' => complaint_type_options($currentType),
    'COMPLAINT_TEXT' => h($currentText),
    'COMPLAINT_PLACEHOLDER' => h(t('Explain the issue clearly...')),
    'COMPLAINT_HISTORY' => $complaintHistoryHtml,
    'SUBMIT_LABEL' => h(t('Post complaint')),
]);
?>
