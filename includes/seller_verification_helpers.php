<?php
require_once __DIR__ . '/security.php';
function seller_verification_allowed_file_types(): array {
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
}

function seller_verification_upload_dir(int $sellerId): string {
    $dir = dirname(__DIR__) . '/uploads/kyc/' . $sellerId;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function seller_verification_relative_path(int $sellerId, string $fileName): string {
    return 'uploads/kyc/' . $sellerId . '/' . $fileName;
}

function save_uploaded_verification_file(array $file, int $sellerId, string $prefix, bool $allowPdf = true): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    $policy = $allowPdf ? 'kyc_document' : 'kyc_selfie';
    $validated = security_validate_uploaded_file($file, $policy);

    if (empty($validated['ok'])) {
        error_log('Rejected KYC upload for seller ' . $sellerId . ': ' . ($validated['error'] ?? 'unknown reason'));
        return '';
    }

    $fileName = security_safe_upload_filename($prefix, $validated['extension']);
    $target = seller_verification_upload_dir($sellerId) . '/' . $fileName;

    if (!move_uploaded_file($validated['tmp_name'], $target)) {
        return '';
    }

    @chmod($target, 0640);
    return seller_verification_relative_path($sellerId, $fileName);
}

function save_captured_selfie_data(?string $data, int $sellerId): string {
    $data = trim((string)$data);
    if ($data === '') {
        return '';
    }

    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/i', $data, $matches)) {
        return '';
    }

    $binary = base64_decode($matches[2], true);
    if ($binary === false) {
        return '';
    }

    $validated = security_validate_image_binary($binary, 'kyc_selfie');
    if (empty($validated['ok'])) {
        error_log('Rejected captured selfie for seller ' . $sellerId . ': ' . ($validated['error'] ?? 'unknown reason'));
        return '';
    }

    $fileName = security_safe_upload_filename('selfie', $validated['extension']);
    $target = seller_verification_upload_dir($sellerId) . '/' . $fileName;

    if (file_put_contents($target, $binary, LOCK_EX) === false) {
        return '';
    }

    @chmod($target, 0640);
    return seller_verification_relative_path($sellerId, $fileName);
}

function seller_completed_sales_count(mysqli $conn, int $sellerId): int {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(oi.quantity), 0) AS sales_count
        FROM order_items oi
        INNER JOIN listings l ON l.listing_id = oi.listing_id
        INNER JOIN orders o ON o.order_id = oi.order_id
        WHERE l.seller_id = ?
          AND o.status IN ('completed', 'handled')
    ");
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int)($row['sales_count'] ?? 0);
}

function seller_complaint_stats(mysqli $conn, int $sellerId): array {
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_complaints,
            SUM(CASE WHEN status IN ('open', 'pending') THEN 1 ELSE 0 END) AS open_complaints
        FROM complaints
        WHERE seller_id = ?
    ");
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return [
        'total' => (int)($row['total_complaints'] ?? 0),
        'open' => (int)($row['open_complaints'] ?? 0),
    ];
}

function seller_average_rating(mysqli $conn, int $sellerId): ?float {
    $stmt = $conn->prepare("SELECT AVG(rating) AS avg_rating FROM reviews WHERE seller_id = ?");
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row && $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null;
}

function calculate_seller_trust_badge(mysqli $conn, int $sellerId): string {
    $sales = seller_completed_sales_count($conn, $sellerId);
    $rating = seller_average_rating($conn, $sellerId);
    $complaints = seller_complaint_stats($conn, $sellerId);
    $complaintRate = $sales > 0 ? ($complaints['total'] / $sales) : 0.0;

    if ($sales <= 0) {
        return 'New seller';
    }

    if ($complaints['open'] > 0 || $complaintRate >= 0.30) {
        return 'Under review';
    }

    if ($sales >= 20 && $rating !== null && $rating >= 4.5 && $complaintRate <= 0.05) {
        return 'Top trusted seller';
    }

    if ($sales >= 5 && $rating !== null && $rating >= 4.0 && $complaintRate <= 0.15) {
        return 'Trusted seller';
    }

    if ($sales >= 2 && ($rating === null || $rating >= 3.5)) {
        return 'Growing seller';
    }

    return 'New seller';
}

function update_seller_trust_badge(mysqli $conn, int $sellerId): string {
    $badge = calculate_seller_trust_badge($conn, $sellerId);
    $stmt = $conn->prepare("UPDATE seller SET trust_badge = ? WHERE user_id = ?");
    $stmt->bind_param('si', $badge, $sellerId);
    $stmt->execute();

    return $badge;
}

function latest_seller_verification(mysqli $conn, int $sellerId, ?string $status = null): ?array {
    if ($status === null) {
        $stmt = $conn->prepare("
            SELECT *
            FROM seller_verifications
            WHERE seller_id = ?
            ORDER BY submitted_at DESC, verification_id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $sellerId);
    } else {
        $stmt = $conn->prepare("
            SELECT *
            FROM seller_verifications
            WHERE seller_id = ? AND status = ?
            ORDER BY submitted_at DESC, verification_id DESC
            LIMIT 1
        ");
        $stmt->bind_param('is', $sellerId, $status);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}

function seller_has_initial_verification(mysqli $conn, int $sellerId): bool {
    $stmt = $conn->prepare("
        SELECT verification_id
        FROM seller_verifications
        WHERE seller_id = ?
          AND verification_type = 'initial'
          AND status = 'approved'
        LIMIT 1
    ");
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

function seller_has_current_verification(mysqli $conn, int $sellerId): bool {
    $stmt = $conn->prepare("
        SELECT verification_id
        FROM seller_verifications
        WHERE seller_id = ?
          AND status = 'approved'
          AND (expires_at IS NULL OR expires_at >= CURDATE())
        ORDER BY reviewed_at DESC, verification_id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

function seller_verification_pending(mysqli $conn, int $sellerId): ?array {
    $stmt = $conn->prepare("
        SELECT *
        FROM seller_verifications
        WHERE seller_id = ? AND status = 'pending'
        ORDER BY submitted_at DESC, verification_id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}

function get_seller_verification_state(mysqli $conn, int $sellerId): array {
    $pending = seller_verification_pending($conn, $sellerId);
    $initialApproved = seller_has_initial_verification($conn, $sellerId);
    $currentApproved = seller_has_current_verification($conn, $sellerId);
    $latest = latest_seller_verification($conn, $sellerId);

    if ($pending) {
        return [
            'code' => $pending['verification_type'] === 'initial' ? 'pending_initial' : 'pending_monthly',
            'can_create_listing' => false,
            'requires_document' => $pending['verification_type'] === 'initial',
            'title' => t('Seller verification pending'),
            'message' => t('Your seller verification has been submitted and is waiting for admin approval.'),
            'latest' => $pending,
        ];
    }

    if (!$initialApproved) {
        return [
            'code' => $latest && $latest['status'] === 'rejected' ? 'rejected_initial' : 'required_initial',
            'can_create_listing' => false,
            'requires_document' => true,
            'title' => t('Seller verification required'),
            'message' => $latest && $latest['status'] === 'rejected'
                ? t('Your previous seller verification was rejected. Please submit your document and selfie again.')
                : t('Before creating your first listing, please upload a verified document and a real-time selfie.'),
            'latest' => $latest,
        ];
    }

    if (!$currentApproved) {
        return [
            'code' => $latest && $latest['status'] === 'rejected' ? 'rejected_monthly' : 'monthly_due',
            'can_create_listing' => false,
            'requires_document' => false,
            'title' => t('Monthly seller selfie required'),
            'message' => $latest && $latest['status'] === 'rejected'
                ? t('Your latest monthly selfie was rejected. Please submit a new real-time selfie.')
                : t('Your seller verification has expired. Please submit a new real-time selfie for this month.'),
            'latest' => $latest,
        ];
    }

    return [
        'code' => 'verified',
        'can_create_listing' => true,
        'requires_document' => false,
        'title' => t('Seller verified'),
        'message' => t('Your seller verification is up to date.'),
        'latest' => $latest,
    ];
}


function seller_active_listing_count(mysqli $conn, int $sellerId): int {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS active_total
        FROM listings
        WHERE seller_id = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int)($row['active_total'] ?? 0);
}

function seller_listing_management_gate_state(mysqli $conn, int $sellerId): ?array {
    $activeListings = seller_active_listing_count($conn, $sellerId);

    if ($activeListings <= 0) {
        return null;
    }

    $state = get_seller_verification_state($conn, $sellerId);

    if (!empty($state['can_create_listing'])) {
        return null;
    }

    $state['active_listing_count'] = $activeListings;
    $state['title'] = t('Seller verification required to manage listings');
    $state['message'] = t('You have active listings, so your seller verification must be up to date before you can create, edit, or manage listings.');

    return $state;
}

function render_listing_management_verification_gate(mysqli $conn, int $sellerId, string $currentPage = 'manage_account', string $messageHtml = ''): string {
    $state = seller_listing_management_gate_state($conn, $sellerId);

    if ($state === null) {
        return '';
    }

    $countText = h((string)$state['active_listing_count']) . ' ' . h(t('active listing(s) found'));
    $notice = '
      <div class="notice notice--error">
        <strong>' . h(t('Listing tools locked')) . '</strong>
        <p>' . h(t('Your active listings are locked until your seller verification is approved or renewed.')) . '</p>
        <p>' . $countText . '</p>
      </div>
    ';

    return render_template(dirname(__DIR__) . '/templates/seller_verification.html', [
        'HEADER' => get_header($conn, $currentPage),
        'VERIFICATION_FORM' => render_seller_verification_form($state, $notice . $messageHtml),
    ]);
}

function enforce_listing_management_verification(mysqli $conn, int $sellerId, string $currentPage = 'manage_account'): void {
    $gateHtml = render_listing_management_verification_gate($conn, $sellerId, $currentPage);

    if ($gateHtml !== '') {
        echo $gateHtml;
        exit;
    }
}


function seller_verification_public_label(array $state): string {
    return match ($state['code'] ?? '') {
        'verified' => t('Verified'),
        'pending_initial', 'pending_monthly' => t('Pending verification'),
        'required_initial', 'monthly_due', 'rejected_initial', 'rejected_monthly' => t('Verification required'),
        default => t('Pending verification'),
    };
}

function submit_seller_verification(mysqli $conn, int $sellerId, array $post, array $files, array $state): array {
    $requiresDocument = !empty($state['requires_document']);
    $verificationType = $requiresDocument ? 'initial' : 'monthly';
    $documentType = $requiresDocument ? trim($post['document_type'] ?? '') : null;

    if ($requiresDocument && !in_array($documentType, ['ID', 'Passport', 'Driver licence'], true)) {
        return ['success' => false, 'message' => t('Please select a valid document type.')];
    }

    $documentPath = null;
    if ($requiresDocument) {
        $documentPath = save_uploaded_verification_file($files['document_file'] ?? [], $sellerId, 'document', true);
        if ($documentPath === '') {
            return ['success' => false, 'message' => t('Please upload a valid document file. Accepted formats: JPG, PNG, WEBP, or PDF.')];
        }
    }

    $selfiePath = save_captured_selfie_data($post['captured_selfie_data'] ?? '', $sellerId);
    if ($selfiePath === '') {
        $selfiePath = save_uploaded_verification_file($files['selfie_file'] ?? [], $sellerId, 'selfie', false);
    }

    if ($selfiePath === '') {
        return ['success' => false, 'message' => t('Please capture or upload a valid selfie image.')];
    }

    $stmt = $conn->prepare("
        INSERT INTO seller_verifications
        (seller_id, verification_type, document_type, document_path, selfie_path, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param('issss', $sellerId, $verificationType, $documentType, $documentPath, $selfiePath);
    $stmt->execute();

    $pending = 'pending';
    $sellerStmt = $conn->prepare("UPDATE seller SET verification_status = ? WHERE user_id = ?");
    $sellerStmt->bind_param('si', $pending, $sellerId);
    $sellerStmt->execute();

    return ['success' => true, 'message' => t('Seller verification submitted. An admin must approve it before you can create listings.')];
}

function render_seller_verification_form(array $state, string $messageHtml = ''): string {
    $requiresDocument = !empty($state['requires_document']);

    $documentFields = '';
    if ($requiresDocument) {
        $documentFields = '
          <div class="kyc-upload-grid">
            <div class="field kyc-upload-card">
              <label for="document_type">' . h(t('Document type')) . '</label>
              <select id="document_type" name="document_type" required>
                <option value="">' . h(t('Select document type')) . '</option>
                <option value="ID">' . h(t('South African ID')) . '</option>
                <option value="Passport">' . h(t('Passport')) . '</option>
                <option value="Driver licence">' . h(t('Driver licence')) . '</option>
              </select>
            </div>

            <div class="field kyc-upload-card kyc-upload-card--file">
              <label for="document_file">' . h(t('Verified document')) . '</label>
              <input id="document_file" type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required>
              <small>' . h(t('Upload a clear PDF or image file (.pdf, .jpg, .jpeg, .png, .webp).')) . '</small>
            </div>
          </div>
        ';
    }

    return '
      <section class="content-panel content-panel--single seller-verification-panel">
        <p class="eyebrow">' . h(t('Seller verification')) . '</p>
        <h1>' . h($state['title']) . '</h1>
        <p class="page-intro">' . h($state['message']) . '</p>
        ' . $messageHtml . '

        <div class="notice">
          <strong>' . h(t('Important privacy note')) . '</strong>
          <p>' . h(t('For this coursework prototype, only upload test documents. A real marketplace should store KYC files securely and follow privacy laws.')) . '</p>
        </div>

        <form method="post" enctype="multipart/form-data" class="listing-form kyc-form" data-seller-kyc-form>
          <input type="hidden" name="seller_verification_action" value="submit">
          ' . $documentFields . '

          <div class="kyc-camera-card" data-kyc-camera>
            <div class="kyc-section-head">
              <label>' . h(t('Real-time selfie')) . '</label>
              <p class="section-note">' . h(t('Use the camera button to take a selfie now, or upload a clear selfie image for local testing.')) . '</p>
            </div>

            <div class="kyc-media-frame">
              <video class="kyc-video" data-kyc-video autoplay playsinline muted></video>
              <canvas data-kyc-canvas hidden></canvas>
              <img class="kyc-preview" data-kyc-preview alt="' . h(t('Captured selfie preview')) . '" hidden>
            </div>
            <input type="hidden" name="captured_selfie_data" data-kyc-captured>

            <div class="form-actions kyc-actions">
              <button type="button" class="button button--solid button--small" data-kyc-start>' . h(t('Start camera')) . '</button>
              <button type="button" class="button button--ghost button--small" data-kyc-capture>' . h(t('Capture selfie')) . '</button>
            </div>

            <div class="field kyc-upload-card kyc-upload-card--selfie">
              <label for="selfie_file">' . h(t('Upload selfie instead')) . '</label>
              <input id="selfie_file" type="file" name="selfie_file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" capture="user">
              <small>' . h(t('Use this if the camera is not available on your device.')) . '</small>
            </div>
          </div>

          <div class="form-actions kyc-submit-actions">
            <button class="button button--solid" type="submit">' . h(t('Submit verification')) . '</button>
            <a class="button button--ghost" href="manage_account.php">' . h(t('Back to account')) . '</a>
          </div>
        </form>
      </section>
    ';
}
?>
