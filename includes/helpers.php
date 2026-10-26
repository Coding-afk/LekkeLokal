<?php
require_once __DIR__ . '/security.php';

function send_app_security_headers(): void {
    security_send_headers();
}

function current_auth_fingerprint(): string {
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent';
    return hash('sha256', $agent);
}

function authenticated_session_is_valid(): bool {
    if (empty($_SESSION['auth_fingerprint']) || !hash_equals((string)$_SESSION['auth_fingerprint'], current_auth_fingerprint())) {
        return false;
    }

    $startedAt = (int)($_SESSION['auth_started_at'] ?? 0);
    if ($startedAt <= 0 || (time() - $startedAt) > 12 * 60 * 60) {
        return false;
    }

    return true;
}

function destroy_authenticated_session_and_redirect(string $reason = 'expired'): void {
    session_unset();
    session_destroy();
    header('Location: index.php?' . $reason . '=1');
    exit;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/lang.php';

function render_template(string $templatePath, array $vars = []): string {
    send_app_security_headers();
    $html = file_get_contents($templatePath);
    if ($html === false) {
        return '';
    }

    foreach ($vars as $key => $value) {
        $html = str_replace('{{' . strtoupper($key) . '}}', (string)$value, $html);
    }

    $html = str_replace('{{CSRF_INPUT}}', security_csrf_input(), $html);
    $html = security_inject_csrf_into_post_forms($html);

    return tr_template($html);
}

function bind_dynamic_params(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '') {
        return;
    }

    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function build_category_options(mysqli $conn, $selected = null, bool $includeAll = true): string {
    $options = [];

    if ($includeAll) {
        $selectedAttr = ($selected === '' || $selected === null || $selected === 'all') ? ' selected' : '';
        $options[] = '<option value="all"' . $selectedAttr . '>' . h(t('All categories')) . '</option>';
    }

    $result = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $isSelected = ((string)($selected ?? '') === (string)$row['category_id']) ? ' selected' : '';
            $options[] = '<option value="' . h($row['category_id']) . '"' . $isSelected . '>' . h(translate_dynamic_text($row['category_name'])) . '</option>';
        }
    }

    return implode('', $options);
}

function build_location_options($selected = null, bool $includeAll = true): string {
    $locations = [
        'Eastern Cape',
        'Free State',
        'Gauteng',
        'KwaZulu-Natal',
        'Limpopo',
        'Mpumalanga',
        'North West',
        'Northern Cape',
        'Western Cape',
    ];

    $options = [];
    if ($includeAll) {
        $selectedAttr = ($selected === '' || $selected === null || $selected === 'all') ? ' selected' : '';
        $options[] = '<option value="all"' . $selectedAttr . '>' . h(t('All locations')) . '</option>';
    }

    foreach ($locations as $location) {
        $isSelected = ((string)$selected === $location) ? ' selected' : '';
        $options[] = '<option value="' . h($location) . '"' . $isSelected . '>' . h(translate_dynamic_text($location)) . '</option>';
    }

    return implode('', $options);
}

function build_condition_options($selected = null): string {
    $conditions = ['New', 'Like New', 'Good', 'Fair', 'Used'];
    $options = '<option value="">' . h(t('Select condition')) . '</option>';

    foreach ($conditions as $condition) {
        $isSelected = ((string)$selected === $condition) ? ' selected' : '';
        $options .= '<option value="' . h($condition) . '"' . $isSelected . '>' . h(translate_dynamic_text($condition)) . '</option>';
    }

    return $options;
}

function build_status_options($selected = 'active'): string {
    $statuses = ['active' => 'Active', 'paused' => 'Paused', 'sold' => 'Sold', 'removed' => 'Removed'];
    $options = '';

    foreach ($statuses as $value => $label) {
        $isSelected = ((string)$selected === $value) ? ' selected' : '';
        $options .= '<option value="' . h($value) . '"' . $isSelected . '>' . h(translate_dynamic_text($label)) . '</option>';
    }

    return $options;
}



function normalize_address_input(?string $address): string {
    $address = str_replace(["\r\n", "\r"], "\n", (string)$address);
    $lines = array_map('trim', explode("\n", $address));
    $lines = array_values(array_filter($lines, static fn($line) => $line !== ''));

    return implode("\n", $lines);
}

function parse_address_components(?string $address): array {
    $address = normalize_address_input($address);
    $components = [
        'street_number' => '',
        'street_name' => '',
        'suburb' => '',
        'city' => '',
        'postal_code' => '',
    ];

    if ($address === '') {
        return $components;
    }

    $lines = explode("\n", $address);
    $streetLine = trim($lines[0] ?? '');

    if (preg_match('/^([0-9]+[A-Za-z0-9\/-]*)\s+(.+)$/u', $streetLine, $matches)) {
        $components['street_number'] = trim($matches[1]);
        $components['street_name'] = trim($matches[2]);
    } else {
        $components['street_name'] = $streetLine;
    }

    $components['suburb'] = trim($lines[1] ?? '');
    $components['city'] = trim($lines[2] ?? '');
    $components['postal_code'] = trim($lines[count($lines) - 1] ?? '');

    return $components;
}

function format_address_components(array $components): string {
    $streetNumber = trim((string)($components['street_number'] ?? ''));
    $streetName = trim((string)($components['street_name'] ?? ''));
    $suburb = trim((string)($components['suburb'] ?? ''));
    $city = trim((string)($components['city'] ?? ''));
    $postalCode = trim((string)($components['postal_code'] ?? ''));

    $lines = [];
    $streetLine = trim($streetNumber . ' ' . $streetName);
    if ($streetLine !== '') {
        $lines[] = $streetLine;
    }
    if ($suburb !== '') {
        $lines[] = $suburb;
    }
    if ($city !== '') {
        $lines[] = $city;
    }
    if ($postalCode !== '') {
        $lines[] = $postalCode;
    }

    return implode("\n", $lines);
}

function get_address_components_from_post(string $prefix): array {
    return [
        'street_number' => trim($_POST[$prefix . '_street_number'] ?? ''),
        'street_name' => trim($_POST[$prefix . '_street_name'] ?? ''),
        'suburb' => trim($_POST[$prefix . '_suburb'] ?? ''),
        'city' => trim($_POST[$prefix . '_city'] ?? ''),
        'postal_code' => trim($_POST[$prefix . '_postal_code'] ?? ''),
    ];
}

function build_address_from_post(string $prefix): string {
    return format_address_components(get_address_components_from_post($prefix));
}

function address_components_are_empty(array $components): bool {
    foreach (['street_number', 'street_name', 'suburb', 'city', 'postal_code'] as $key) {
        if (trim((string)($components[$key] ?? '')) !== '') {
            return false;
        }
    }

    return true;
}

function is_valid_address_components(array $components, bool $allowEmpty = false): bool {
    if ($allowEmpty && address_components_are_empty($components)) {
        return true;
    }

    $streetNumber = trim((string)($components['street_number'] ?? ''));
    $streetName = trim((string)($components['street_name'] ?? ''));
    $suburb = trim((string)($components['suburb'] ?? ''));
    $city = trim((string)($components['city'] ?? ''));
    $postalCode = trim((string)($components['postal_code'] ?? ''));

    if (!preg_match('/^[0-9]+[A-Za-z0-9\/-]*$/u', $streetNumber)) {
        return false;
    }

    if (!preg_match('/[A-Za-z]/u', $streetName) || strlen($streetName) < 2) {
        return false;
    }

    if (!preg_match('/[A-Za-z]/u', $suburb) || strlen($suburb) < 2) {
        return false;
    }

    if (!preg_match('/[A-Za-z]/u', $city) || strlen($city) < 2) {
        return false;
    }

    return preg_match('/^[0-9]{4}$/u', $postalCode) === 1;
}

function is_valid_address_format(?string $address): bool {
    return is_valid_address_components(parse_address_components($address), false);
}

function address_format_message(): string {
    return t('Please complete a valid address using street number, street name, suburb, city/town, and a 4-digit postal code.');
}

function render_address_fields(string $prefix, ?string $address = '', bool $required = false, bool $readonly = false): string {
    $values = parse_address_components($address);
    $requiredAttr = $required ? ' required' : '';
    $readonlyAttr = $readonly ? ' readonly' : '';
    $requiredData = $required ? 'true' : 'false';
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix);

    $field = static function (string $key, string $label, string $placeholder, string $extra = '') use ($safePrefix, $values, $requiredAttr, $readonlyAttr): string {
        $id = $safePrefix . '_' . $key;
        $name = $safePrefix . '_' . $key;
        $value = h($values[$key] ?? '');
        return '
          <div class="field address-field address-field--' . h($key) . '">
            <label for="' . h($id) . '">' . h(t($label)) . '</label>
            <input id="' . h($id) . '" name="' . h($name) . '" value="' . $value . '" placeholder="' . h($placeholder) . '"' . $requiredAttr . $readonlyAttr . ' ' . $extra . '>
          </div>
        ';
    };

    return '
      <div class="address-fields" data-address-group data-address-required="' . h($requiredData) . '">
        <div class="address-fields__street">
          ' . $field('street_number', 'Street number', '15', 'data-address-street-number inputmode="numeric" autocomplete="address-line1"') . '
          ' . $field('street_name', 'Street name', 'Church Street', 'data-address-street-name autocomplete="address-line1"') . '
        </div>
        ' . $field('suburb', 'Suburb', 'Durbanville', 'data-address-suburb autocomplete="address-line2"') . '
        ' . $field('city', 'City/Town', 'Cape Town', 'data-address-city autocomplete="address-level2"') . '
        ' . $field('postal_code', 'Postal code', '7550', 'data-address-postal-code inputmode="numeric" maxlength="4" autocomplete="postal-code"') . '
      </div>
    ';
}


function get_unread_chat_count(mysqli $conn, int $userId): int {
    if ($userId <= 0) {
        return 0;
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS unread_total
            FROM chat_messages cm
            INNER JOIN chats c ON c.chat_id = cm.chat_id
            WHERE (c.buyer_id = ? OR c.seller_id = ?)
              AND cm.sender_id <> ?
              AND cm.is_read = 0
        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('iii', $userId, $userId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return (int)($row['unread_total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}


function get_wishlist_count(mysqli $conn, int $userId): int {
    if ($userId <= 0) {
        return 0;
    }

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM wishlists WHERE user_id = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function user_has_wishlist_item(mysqli $conn, int $userId, int $listingId): bool {
    if ($userId <= 0 || $listingId <= 0) {
        return false;
    }

    try {
        $stmt = $conn->prepare("SELECT wishlist_id FROM wishlists WHERE user_id = ? AND listing_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $userId, $listingId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    } catch (Throwable $e) {
        return false;
    }
}

function build_header(string $currentPage, string $categoryOptions, string $locationOptions, int $unreadCount = 0, int $wishlistCount = 0): string {
    $createActive = $currentPage === 'create_listing' ? ' is-active' : '';
    $accountActive = $currentPage === 'manage_account' ? ' is-active' : '';
    $chatActive = $currentPage === 'chat' ? ' is-active' : '';
    $wishlistActive = $currentPage === 'wishlist' ? ' is-active' : '';
    $createCurrent = $currentPage === 'create_listing' ? ' aria-current="page"' : '';
    $accountCurrent = $currentPage === 'manage_account' ? ' aria-current="page"' : '';
    $chatCurrent = $currentPage === 'chat' ? ' aria-current="page"' : '';
    $wishlistCurrent = $currentPage === 'wishlist' ? ' aria-current="page"' : '';
    $unreadBadge = $unreadCount > 0 ? '<span class="nav-unread-badge">' . h($unreadCount > 99 ? '99+' : (string)$unreadCount) . '</span>' : '';
    $wishlistBadge = $wishlistCount > 0 ? '<span class="nav-unread-badge nav-wishlist-badge">' . h($wishlistCount > 99 ? '99+' : (string)$wishlistCount) . '</span>' : '';
    $searchPlaceholder = h(t('Search listings, sellers, items...'));
    $searchLabel = h(t('Search listings'));
    $openMessagesLabel = h(t('Open messages'));
    $openWishlistLabel = h(t('Open wishlist'));
    $wishlistLabel = h(t('Wishlist'));
    $messagesLabel = h(t('Messages'));
    $createListingLabel = h(t('Create new listing'));
    $manageAccountLabel = h(t('Manage Account'));

    return <<<HTML
<header class="site-header">
  <div class="site-header__inner">
    <a class="site-header__brand" href="dashboard.php" aria-label="Go to dashboard">
      <img class="site-logo site-logo--long" src="assets/images/Long_Logo.png" alt="Lekke Lokal">
      <img class="site-logo site-logo--short" src="assets/images/Short_Logo.png" alt="Lekke Lokal">
    </a>

    <form class="site-header__search" action="dashboard.php" method="get" role="search">
      <label class="sr-only" for="nav-category">Categories</label>
      <select id="nav-category" name="category_id" class="nav-select">
        {$categoryOptions}
      </select>

      <label class="sr-only" for="nav-query">Search</label>
      <input id="nav-query" type="search" name="q" placeholder="{$searchPlaceholder}" />

      <button type="submit" class="icon-button" aria-label="{$searchLabel}">
        <span aria-hidden="true">🔍</span>
      </button>

      <label class="sr-only" for="nav-location">Location</label>
      <select id="nav-location" name="location" class="nav-select">
        {$locationOptions}
      </select>
    </form>

    <nav class="site-header__actions" aria-label="Primary">
      <a class="nav-action nav-action--wishlist{$wishlistActive}"{$wishlistCurrent} href="wishlist.php" aria-label="{$openWishlistLabel}">
        <span class="nav-action__icon" aria-hidden="true">♥</span>
        <span class="nav-action__text">{$wishlistLabel}</span>{$wishlistBadge}
      </a>
      <a class="nav-action nav-action--chat{$chatActive}"{$chatCurrent} href="messages.php" aria-label="{$openMessagesLabel}">
        <span class="nav-action__icon" aria-hidden="true">✉</span>
        <span class="nav-action__text">{$messagesLabel}</span>{$unreadBadge}
      </a>
      <a class="nav-action nav-action--create{$createActive}"{$createCurrent} href="create_listing.php">
        <span class="nav-action__icon" aria-hidden="true">＋</span>
        <span class="nav-action__text">{$createListingLabel}</span>
      </a>
      <a class="nav-action nav-action--account{$accountActive}"{$accountCurrent} href="manage_account.php">
        <span class="nav-action__icon" aria-hidden="true">☰</span>
        <span class="nav-action__text">{$manageAccountLabel}</span>
      </a>
    </nav>
  </div>
</header>
HTML;
}

function get_header(mysqli $conn, string $currentPage = 'dashboard'): string {
    $categoryId = trim($_GET['category_id'] ?? 'all');
    $location = trim($_GET['location'] ?? 'all');

    $unreadCount = 0;
    $wishlistCount = 0;
    if (session_status() === PHP_SESSION_NONE) {
        security_start_session();
    }

    if (isset($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
        get_user_language($conn, $userId);
        $unreadCount = get_unread_chat_count($conn, $userId);
        $wishlistCount = get_wishlist_count($conn, $userId);
    }

    return build_header(
        $currentPage,
        build_category_options($conn, $categoryId, true),
        build_location_options($location, true),
        $unreadCount,
        $wishlistCount
    );
}

function ensure_logged_in(): int {
    if (session_status() === PHP_SESSION_NONE) {
        security_start_session();
    }

    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }

    if (!authenticated_session_is_valid()) {
        destroy_authenticated_session_and_redirect('security');
    }

    $lastActivity = (int)($_SESSION['auth_last_activity'] ?? time());
    if ((time() - $lastActivity) > 2 * 60 * 60) {
        destroy_authenticated_session_and_redirect('expired');
    }

    $_SESSION['auth_last_activity'] = time();

    return (int)$_SESSION['user_id'];
}

function ensure_user_profiles(mysqli $conn, int $userId, ?string $storeName = null): void {
    $buyerStmt = $conn->prepare("INSERT IGNORE INTO buyer (user_id, default_shipping_address) VALUES (?, NULL)");
    $buyerStmt->bind_param('i', $userId);
    $buyerStmt->execute();

    $storeName = $storeName ?: 'Lekke Lokal Seller';
    $bio = '';
    $trustBadge = 'New seller';
    $verificationStatus = 'pending';
    $sellerStmt = $conn->prepare("INSERT IGNORE INTO seller (user_id, store_name, bio, trust_badge, verification_status) VALUES (?, ?, ?, ?, ?)");
    $sellerStmt->bind_param('issss', $userId, $storeName, $bio, $trustBadge, $verificationStatus);
    $sellerStmt->execute();
}


function save_listing_images(array $files, int $listingId, mysqli $conn): array {
    $result = [
        'added' => 0,
        'errors' => [],
    ];

    if (!isset($files['name']) || !is_array($files['name'])) {
        return $result;
    }

    $uploadDir = dirname(__DIR__) . '/uploads/listings/' . $listingId;

    if (
        !is_dir($uploadDir) &&
        !mkdir($uploadDir, 0755, true) &&
        !is_dir($uploadDir)
    ) {
        $result['errors'][] =
            'The photo upload folder could not be created on the server.';

        return $result;
    }

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM listing_images
         WHERE listing_id = ?"
    );
    $countStmt->bind_param('i', $listingId);
    $countStmt->execute();

    $countExisting = (int)(
        $countStmt->get_result()->fetch_assoc()['total'] ?? 0
    );

    $insertImage = $conn->prepare(
        "INSERT INTO listing_images
         (listing_id, image_path, is_primary)
         VALUES (?, ?, ?)"
    );

    foreach ($files['name'] as $index => $originalName) {
        $displayName = trim((string)$originalName);

        if ($displayName === '') {
            $displayName = 'Unnamed photo';
        }

        $singleFile = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];

        if (
            ($singleFile['error'] ?? UPLOAD_ERR_NO_FILE) ===
            UPLOAD_ERR_NO_FILE
        ) {
            continue;
        }

        $validated = security_validate_uploaded_file(
            $singleFile,
            'listing_image'
        );

        if (empty($validated['ok'])) {
            $reason = $validated['error'] ?? 'Unknown upload error';

            $result['errors'][] =
                $displayName . ': ' . $reason;

            error_log(
                'Rejected listing image upload: ' .
                $displayName . ' - ' . $reason
            );

            continue;
        }

        $safeName = security_safe_upload_filename(
            'listing_' . $listingId,
            $validated['extension']
        );

        $targetPath = $uploadDir . '/' . $safeName;
        $relativePath =
            'uploads/listings/' . $listingId . '/' . $safeName;

        if (!move_uploaded_file($validated['tmp_name'], $targetPath)) {
            $result['errors'][] =
                $displayName .
                ': The file passed validation but could not be saved on the server.';

            continue;
        }

        @chmod($targetPath, 0644);

        $isPrimary = $countExisting === 0 ? 1 : 0;

        $insertImage->bind_param(
            'isi',
            $listingId,
            $relativePath,
            $isPrimary
        );

        if (!$insertImage->execute()) {
            @unlink($targetPath);

            $result['errors'][] =
                $displayName .
                ': The photo could not be added to the listing database.';

            continue;
        }

        $countExisting++;
        $result['added']++;
    }

    return $result;
}

function reset_primary_image(mysqli $conn, int $listingId): void {
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM listing_images WHERE listing_id = ?");
    $countStmt->bind_param('i', $listingId);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

    if ($total === 0) {
        return;
    }

    $hasPrimaryStmt = $conn->prepare("SELECT COUNT(*) AS total FROM listing_images WHERE listing_id = ? AND is_primary = 1");
    $hasPrimaryStmt->bind_param('i', $listingId);
    $hasPrimaryStmt->execute();
    $hasPrimary = (int)($hasPrimaryStmt->get_result()->fetch_assoc()['total'] ?? 0);

    if ($hasPrimary > 0) {
        return;
    }

    $firstStmt = $conn->prepare("SELECT image_id FROM listing_images WHERE listing_id = ? ORDER BY image_id ASC LIMIT 1");
    $firstStmt->bind_param('i', $listingId);
    $firstStmt->execute();
    $first = $firstStmt->get_result()->fetch_assoc();

    if ($first) {
        $imageId = (int)$first['image_id'];
        $primaryStmt = $conn->prepare("UPDATE listing_images SET is_primary = 1 WHERE image_id = ? AND listing_id = ?");
        $primaryStmt->bind_param('ii', $imageId, $listingId);
        $primaryStmt->execute();
    }
}

function delete_listing_images(mysqli $conn, int $listingId, int $sellerId, array $imageIds): int {
    $deleted = 0;

    if (!$imageIds) {
        return 0;
    }

    $checkOwner = $conn->prepare("SELECT listing_id FROM listings WHERE listing_id = ? AND seller_id = ? LIMIT 1");
    $checkOwner->bind_param('ii', $listingId, $sellerId);
    $checkOwner->execute();
    if (!$checkOwner->get_result()->fetch_assoc()) {
        return 0;
    }

    $selectStmt = $conn->prepare("SELECT image_id, image_path FROM listing_images WHERE listing_id = ? AND image_id = ? LIMIT 1");
    $deleteStmt = $conn->prepare("DELETE FROM listing_images WHERE listing_id = ? AND image_id = ?");

    foreach ($imageIds as $rawId) {
        $imageId = (int)$rawId;
        if ($imageId <= 0) {
            continue;
        }

        $selectStmt->bind_param('ii', $listingId, $imageId);
        $selectStmt->execute();
        $image = $selectStmt->get_result()->fetch_assoc();

        if (!$image) {
            continue;
        }

        $deleteStmt->bind_param('ii', $listingId, $imageId);
        if ($deleteStmt->execute()) {
            $fullPath = dirname(__DIR__) . '/' . $image['image_path'];
            $uploadsBase = dirname(__DIR__) . '/uploads/listings';
            if (is_file($fullPath) && security_path_is_inside($fullPath, $uploadsBase)) {
                @unlink($fullPath);
            }
            $deleted++;
        }
    }

    reset_primary_image($conn, $listingId);
    return $deleted;
}

function render_listing_card(array $row, bool $ownListing = false): string {
    $language = current_language_code();
    $title = (string)($row['title'] ?? '');
    $description = trim((string)($row['description'] ?? ''));

    if ($language !== 'en') {
        $title = translate_dynamic_text($title, $language);
        $description = translate_dynamic_text($description, $language);
    }

    $price = 'R' . number_format((float)($row['price'] ?? 0), 2);
    $image = $row['primary_image'] ?? '';
    $imageHtml = $image !== ''
        ? '<img src="' . h($image) . '" alt="' . h($title !== '' ? $title : t('Listing image')) . '">'
        : '<div class="listing-card__placeholder">' . h(t('No image')) . '</div>';

    $url = $ownListing
        ? 'listing_detail.php?id=' . urlencode((string)$row['listing_id'])
        : 'listing_view.php?id=' . urlencode((string)$row['listing_id']);

    $action = $ownListing
        ? '<a class="button button--ghost" href="' . h($url) . '">' . h(t('View / edit')) . '</a>'
        : '<a class="button button--ghost" href="' . h($url) . '">' . h(t('View listing')) . '</a>';

    $shortDescription = strlen($description) > 90 ? substr($description, 0, 90) . '...' : $description;

    return '
      <article class="listing-card">
        <a class="listing-card__image" href="' . h($url) . '">' . $imageHtml . '</a>
        <div class="listing-card__meta">
          <span class="pill">' . h(translate_dynamic_text($row['category_name'] ?? 'General')) . '</span>
          <span class="pill">' . h(translate_dynamic_text($row['condition'] ?? 'Unknown')) . '</span>
          <span class="pill">' . h(translate_dynamic_text($row['location'] ?? 'All provinces')) . '</span>
        </div>
        <h3><a href="' . h($url) . '">' . h($title) . '</a></h3>
        <div class="listing-card__price">' . h($price) . '</div>
        <div class="listing-card__meta">
          <span>' . h(t('Qty:')) . ' ' . h($row['quantity'] ?? 0) . '</span>
          <span>' . h(t('Status:')) . ' ' . h(translate_dynamic_text($row['status'] ?? 'active')) . '</span>
        </div>
        <p class="listing-card__description">' . h($shortDescription) . '</p>
        ' . $action . '
      </article>
    ';
}
?>
