<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/admin_helpers.php';
require 'includes/seller_verification_helpers.php';

$adminId = ensure_admin($conn);

$message = '';
$messageClass = 'notice';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $verificationId = (int)($_POST['verification_id'] ?? 0);
    $action = $_POST['verification_action'] ?? '';
    $adminNotes = trim($_POST['admin_notes'] ?? '');

    if ($verificationId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $message = t('Invalid seller verification action.');
        $messageClass = 'notice notice--error';
    } else {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $expiresAt = $action === 'approve' ? date('Y-m-d', strtotime('+1 month')) : null;

        $stmt = $conn->prepare("
            UPDATE seller_verifications
            SET status = ?,
                admin_notes = ?,
                reviewed_at = NOW(),
                reviewed_by = ?,
                expires_at = ?
            WHERE verification_id = ?
              AND status = 'pending'
        ");
        $stmt->bind_param('ssisi', $status, $adminNotes, $adminId, $expiresAt, $verificationId);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $sellerStmt = $conn->prepare("
                    SELECT seller_id
                    FROM seller_verifications
                    WHERE verification_id = ?
                    LIMIT 1
                ");
                $sellerStmt->bind_param('i', $verificationId);
                $sellerStmt->execute();
                $sellerRow = $sellerStmt->get_result()->fetch_assoc();

                if ($sellerRow) {
                    $sellerId = (int)$sellerRow['seller_id'];
                    $sellerStatus = $status === 'approved' ? 'verified' : 'rejected';
                    $updateSeller = $conn->prepare("UPDATE seller SET verification_status = ? WHERE user_id = ?");
                    $updateSeller->bind_param('si', $sellerStatus, $sellerId);
                    $updateSeller->execute();
                }

                $message = $action === 'approve'
                    ? t('Seller verification approved.')
                    : t('Seller verification rejected.');
                $messageClass = 'notice notice--success';
            } else {
                $message = t('This seller verification has already been reviewed. No changes were made.');
                $messageClass = 'notice';
            }
        } else {
            $message = t('Seller verification could not be updated.');
            $messageClass = 'notice notice--error';
        }
    }
}

$result = $conn->query("
    SELECT
        sv.*,
        u.first_name,
        u.last_name,
        u.email,
        u.phone_number,
        s.store_name,
        reviewer.first_name AS reviewer_first_name,
        reviewer.last_name AS reviewer_last_name
    FROM seller_verifications sv
    INNER JOIN users u ON u.user_id = sv.seller_id
    INNER JOIN seller s ON s.user_id = sv.seller_id
    LEFT JOIN users reviewer ON reviewer.user_id = sv.reviewed_by
    ORDER BY
        CASE WHEN sv.status = 'pending' THEN 0 WHEN sv.status = 'rejected' THEN 1 ELSE 2 END,
        sv.submitted_at DESC,
        sv.verification_id DESC
");

$verificationsHtml = '';

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $status = strtolower((string)$row['status']);
        $statusClass = $status === 'approved' ? ' status-pill--completed' : ($status === 'rejected' ? ' status-pill--danger' : '');
        $reviewerName = trim((string)(($row['reviewer_first_name'] ?? '') . ' ' . ($row['reviewer_last_name'] ?? '')));
        $documentHtml = $row['document_path']
            ? '<a class="button button--ghost button--small" href="' . h($row['document_path']) . '" target="_blank" rel="noopener">' . h(t('Open document')) . '</a>'
            : '<span class="section-note">' . h(t('No document required')) . '</span>';

        $selfieHtml = $row['selfie_path']
            ? '<a class="kyc-selfie-thumb" href="' . h($row['selfie_path']) . '" target="_blank" rel="noopener"><img src="' . h($row['selfie_path']) . '" alt="' . h(t('Seller selfie')) . '"></a>'
            : '<span class="section-note">' . h(t('No selfie uploaded')) . '</span>';

        $reviewedInfo = $row['reviewed_at']
            ? '<p class="section-note">' . h(t('Reviewed on')) . ' ' . h($row['reviewed_at']) . ($reviewerName !== '' ? ' ' . h(t('by')) . ' ' . h($reviewerName) : '') . '</p>'
            : '';

        $notesText = trim((string)($row['admin_notes'] ?? ''));
        $notesHtml = $notesText !== ''
            ? '<div class="notice"><strong>' . h(t('Admin notes')) . '</strong><p data-no-translate>' . nl2br(h($notesText)) . '</p></div>'
            : '';

        $actionsHtml = '';
        if ($status === 'pending') {
            $actionsHtml = '
              <form method="post" class="seller-verification-admin-form" data-confirm="' . h(t('Are you sure you want to approve this seller verification?')) . '">
                <input type="hidden" name="verification_id" value="' . h($row['verification_id']) . '">
                <input type="hidden" name="verification_action" value="approve">
                <label class="field">
                  <span>' . h(t('Admin notes')) . '</span>
                  <textarea name="admin_notes" placeholder="' . h(t('Optional note for this verification...')) . '"></textarea>
                </label>
                <button class="button button--solid button--small" type="submit">' . h(t('Approve seller')) . '</button>
              </form>

              <form method="post" class="seller-verification-admin-form" data-confirm="' . h(t('Are you sure you want to reject this seller verification?')) . '">
                <input type="hidden" name="verification_id" value="' . h($row['verification_id']) . '">
                <input type="hidden" name="verification_action" value="reject">
                <label class="field">
                  <span>' . h(t('Reason for rejection')) . '</span>
                  <textarea name="admin_notes" required placeholder="' . h(t('Explain why the verification was rejected...')) . '"></textarea>
                </label>
                <button class="button button--ghost button--small" type="submit">' . h(t('Reject verification')) . '</button>
              </form>
            ';
        }

        $verificationsHtml .= '
          <article class="seller-verification-admin-card">
            <div class="seller-verification-admin-card__head">
              <div>
                <p class="eyebrow">' . h(t('Seller verification')) . ' #' . h($row['verification_id']) . '</p>
                <h2>' . h($row['store_name']) . '</h2>
                <p class="section-note">' . h(t(ucfirst((string)$row['verification_type']))) . ' • ' . h(t('Submitted')) . ' ' . h($row['submitted_at']) . '</p>
              </div>
              <span class="status-pill' . $statusClass . '">' . h(translate_dynamic_text(ucfirst($status))) . '</span>
            </div>

            <div class="seller-verification-admin-grid">
              <section>
                <h3>' . h(t('Seller details')) . '</h3>
                <div class="info-list">
                  <div class="info-row"><span>' . h(t('Name')) . '</span><strong>' . h($row['first_name'] . ' ' . $row['last_name']) . '</strong></div>
                  <div class="info-row"><span>' . h(t('Email')) . '</span><strong>' . h($row['email']) . '</strong></div>
                  <div class="info-row"><span>' . h(t('Phone')) . '</span><strong>' . h($row['phone_number']) . '</strong></div>
                  <div class="info-row"><span>' . h(t('Document type')) . '</span><strong>' . h($row['document_type'] ?: t('Monthly selfie only')) . '</strong></div>
                  <div class="info-row"><span>' . h(t('Expires')) . '</span><strong>' . h($row['expires_at'] ?: t('Not approved yet')) . '</strong></div>
                </div>
                ' . $reviewedInfo . '
                ' . $notesHtml . '
              </section>

              <section>
                <h3>' . h(t('Verification files')) . '</h3>
                <div class="kyc-admin-files">
                  <div>
                    <strong>' . h(t('Document')) . '</strong>
                    ' . $documentHtml . '
                  </div>
                  <div>
                    <strong>' . h(t('Selfie')) . '</strong>
                    ' . $selfieHtml . '
                  </div>
                </div>
              </section>

              <section>
                <h3>' . h(t('Admin decision')) . '</h3>
                ' . ($actionsHtml !== '' ? $actionsHtml : '<p class="section-note">' . h(t('This verification has already been reviewed.')) . '</p>') . '
              </section>
            </div>
          </article>
        ';
    }
}

if ($verificationsHtml === '') {
    $verificationsHtml = '<div class="empty-state">' . h(t('No seller verifications found.')) . '</div>';
}

$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';

echo render_template(__DIR__ . '/templates/admin_seller_verifications.html', [
    'ADMIN_NAME' => h($_SESSION['first_name'] ?? 'Admin'),
    'MESSAGE' => $messageHtml,
    'VERIFICATIONS' => $verificationsHtml,
]);
?>
