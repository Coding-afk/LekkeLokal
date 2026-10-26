<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/admin_helpers.php';
require 'includes/complaint_helpers.php';

$adminId = ensure_admin($conn);

$message = '';
$messageClass = 'notice';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complaintId = (int)($_POST['complaint_id'] ?? 0);
    $action = $_POST['complaint_action'] ?? '';
    $resolutionText = trim($_POST['resolution_text'] ?? '');

    if ($complaintId <= 0 || $action !== 'complete') {
        $message = t('Invalid complaint action.');
        $messageClass = 'notice notice--error';
    } elseif ($resolutionText === '') {
        $message = t('Please enter a short resolution message before marking the complaint as handled.');
        $messageClass = 'notice notice--error';
    } else {
        $stmt = $conn->prepare("
            UPDATE complaints
            SET status = 'completed',
                resolution_text = ?,
                handled_at = NOW(),
                handled_by = ?
            WHERE complaint_id = ?
              AND status NOT IN ('completed', 'handled')
        ");
        $stmt->bind_param('sii', $resolutionText, $adminId, $complaintId);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $message = t('Complaint marked as handled/completed.');
                $messageClass = 'notice notice--success';
            } else {
                $message = t('This complaint is already handled. No changes were made.');
                $messageClass = 'notice';
            }
        } else {
            $message = t('Complaint could not be updated.');
            $messageClass = 'notice notice--error';
        }
    }
}

$result = $conn->query("
    SELECT
        c.complaint_id,
        c.order_id,
        c.complaint_type,
        c.complaint_text,
        c.resolution_text,
        c.status,
        c.created_at,
        c.handled_at,
        buyer.first_name AS buyer_first_name,
        buyer.last_name AS buyer_last_name,
        buyer.email AS buyer_email,
        buyer.phone_number AS buyer_phone,
        seller_user.first_name AS seller_first_name,
        seller_user.last_name AS seller_last_name,
        seller_user.email AS seller_email,
        seller_user.phone_number AS seller_phone,
        s.store_name,
        admin_user.first_name AS admin_first_name,
        admin_user.last_name AS admin_last_name
    FROM complaints c
    INNER JOIN users buyer ON buyer.user_id = c.buyer_id
    INNER JOIN users seller_user ON seller_user.user_id = c.seller_id
    INNER JOIN seller s ON s.user_id = c.seller_id
    LEFT JOIN users admin_user ON admin_user.user_id = c.handled_by
    ORDER BY
      CASE WHEN c.status IN ('open', 'pending') THEN 0 ELSE 1 END,
      c.created_at DESC
");

$complaintsHtml = '';

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $status = strtolower((string)$row['status']);
        $statusClass = in_array($status, ['completed', 'handled'], true) ? ' status-pill--completed' : '';
        $handledBy = trim((string)(($row['admin_first_name'] ?? '') . ' ' . ($row['admin_last_name'] ?? '')));
        $handledInfo = in_array($status, ['completed', 'handled'], true)
            ? '<p class="section-note">' . h(t('Handled on')) . ' ' . h($row['handled_at'] ?? '') . ($handledBy !== '' ? ' ' . h(t('by')) . ' ' . h($handledBy) : '') . '</p>'
            : '';

        $resolutionText = trim((string)($row['resolution_text'] ?? ''));
        $resolutionHtml = $resolutionText !== ''
            ? '<section class="complaint-resolution-box"><h3>' . h(t('Admin resolution')) . '</h3><p data-no-translate>' . nl2br(h($resolutionText)) . '</p></section>'
            : '';

        if (in_array($status, ['completed', 'handled'], true)) {
            $actionButton = '<button class="button button--ghost complaint-admin-compact-button" type="button" disabled>' . h(t('Already handled')) . '</button>';
        } else {
            $actionButton = '
              <form method="post" class="complaint-resolution-form" data-confirm="' . h(t('Are you sure you want to mark this complaint as handled/completed?')) . '">
                <input type="hidden" name="complaint_id" value="' . h($row['complaint_id']) . '">
                <input type="hidden" name="complaint_action" value="complete">

                <label class="field">
                  <span>' . h(t('Resolution message')) . '</span>
                  <textarea name="resolution_text" required placeholder="' . h(t('Briefly explain how this complaint was resolved...')) . '"></textarea>
                </label>

                <button class="button button--solid complaint-admin-compact-button" type="submit">' . h(t('Mark handled / completed')) . '</button>
              </form>
            ';
        }

        $complaintsHtml .= '
          <article class="complaint-admin-card">
            <div class="complaint-admin-card__head">
              <div>
                <p class="eyebrow">' . h(t('Complaint')) . ' #' . h($row['complaint_id']) . '</p>
                <h2>' . h(t($row['complaint_type'])) . '</h2>
                <p class="section-note">' . h(t('Order #')) . h($row['order_id']) . ' • ' . h($row['created_at']) . '</p>
              </div>
              <span class="status-pill' . $statusClass . '">' . h(complaint_status_label($status)) . '</span>
            </div>

            <div class="complaint-admin-grid">
              <section>
                <h3>' . h(t('Buyer details')) . '</h3>
                <div class="info-list">
                  <div class="info-row"><span>' . h(t('Name')) . '</span><strong>' . h($row['buyer_first_name'] . ' ' . $row['buyer_last_name']) . '</strong></div>
                  <div class="info-row"><span>' . h(t('Email')) . '</span><strong>' . h($row['buyer_email']) . '</strong></div>
                  <div class="info-row"><span>' . h(t('Phone')) . '</span><strong>' . h($row['buyer_phone']) . '</strong></div>
                </div>
              </section>

              <section>
                <h3>' . h(t('Seller details')) . '</h3>
                <div class="info-list">
                  <div class="info-row"><span>' . h(t('Seller store')) . '</span><strong>' . h($row['store_name']) . '</strong></div>
                  <div class="info-row"><span>' . h(t('Name')) . '</span><strong>' . h($row['seller_first_name'] . ' ' . $row['seller_last_name']) . '</strong></div>
                  <div class="info-row"><span>' . h(t('Email')) . '</span><strong>' . h($row['seller_email']) . '</strong></div>
                  <div class="info-row"><span>' . h(t('Phone')) . '</span><strong>' . h($row['seller_phone']) . '</strong></div>
                </div>
              </section>
            </div>

            <section class="complaint-admin-text">
              <h3>' . h(t('Complaint explanation')) . '</h3>
              <p data-no-translate>' . nl2br(h($row['complaint_text'])) . '</p>
            </section>

            ' . $resolutionHtml . '

            <div class="form-actions complaint-admin-actions">
              <a class="button button--ghost complaint-admin-order-link" href="admin_table.php?table=orders">' . h(t('View buyer order')) . '</a>
              ' . $actionButton . '
            </div>
            ' . $handledInfo . '
          </article>
        ';
    }
} else {
    $complaintsHtml = '<div class="empty-state">' . h(t('No complaints have been raised yet.')) . '</div>';
}

$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';

echo render_template(__DIR__ . '/templates/admin_complaints.html', [
    'MESSAGE' => $messageHtml,
    'COMPLAINTS' => $complaintsHtml,
]);
?>
