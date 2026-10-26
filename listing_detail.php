<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/seller_verification_helpers.php';

$userId = ensure_logged_in();
enforce_listing_management_verification($conn, $userId, 'manage_account');
$listingId = (int)($_GET['id'] ?? $_POST['listing_id'] ?? 0);
if ($listingId <= 0) {
    die('Invalid listing.');
}

function fetch_listing_detail(mysqli $conn, int $listingId, int $userId): ?array {
    $stmt = $conn->prepare("
        SELECT l.*, c.category_name
        FROM listings l
        INNER JOIN categories c ON c.category_id = l.category_id
        WHERE l.listing_id = ? AND l.seller_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $listingId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}

function uploaded_photos_selected(array $files): bool {
    if (!isset($files['name']) || !is_array($files['name'])) {
        return false;
    }

    foreach ($files['name'] as $index => $name) {
        if ($name !== '' && (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            return true;
        }
    }

    return false;
}

$listing = fetch_listing_detail($conn, $listingId, $userId);
if (!$listing) {
    die('Listing not found or you do not have permission to view it.');
}

$message = '';
$messageClass = 'notice';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['delete_listing', 'restore_listing'], true)) {
    $statusAction = $_POST['action'] ?? '';
    $newStatus = $statusAction === 'restore_listing' ? 'active' : 'removed';
    $redirectFlag = $statusAction === 'restore_listing' ? 'restored=1' : 'removed=1';

    $stmt = $conn->prepare("
        UPDATE listings
        SET status = ?
        WHERE listing_id = ? AND seller_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('sii', $newStatus, $listingId, $userId);

    if ($stmt->execute()) {
        header('Location: listing_detail.php?id=' . $listingId . '&' . $redirectFlag);
        exit;
    }

    $message = $statusAction === 'restore_listing'
        ? t('Could not restore listing. Please try again.')
        : t('Could not remove listing. Please try again.');
    $messageClass = 'notice notice--error';

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deleteImageIds = $_POST['delete_images'] ?? [];
    if (!is_array($deleteImageIds)) {
        $deleteImageIds = [];
    }

    $title = trim($_POST['title'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 1);
    $condition = trim($_POST['condition'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $allowDelivery = isset($_POST['allow_delivery']) ? 1 : 0;
    $allowPickup = isset($_POST['allow_pickup']) ? 1 : 0;
    $pickupAddress = build_address_from_post('pickup');
    $status = trim($_POST['status'] ?? 'active');

    if ($title === '' || $categoryId <= 0 || $description === '' || $price <= 0 || $quantity <= 0 || $condition === '' || $location === '') {
        $message = t('Please complete all required fields.');
        $messageClass = 'notice notice--error';
    } elseif ($allowDelivery === 0 && $allowPickup === 0) {
        $message = t('Please select at least one fulfilment option: delivery, pickup, or both.');
        $messageClass = 'notice notice--error';
    } elseif ($allowPickup === 1 && $pickupAddress === '') {
        $message = t('Please enter the pickup address because pickup is enabled.');
        $messageClass = 'notice notice--error';
    } elseif ($allowPickup === 1 && !is_valid_address_format($pickupAddress)) {
        $message = address_format_message();
        $messageClass = 'notice notice--error';
    } else {
        $listingChanged = (
            $title !== (string)$listing['title'] ||
            $categoryId !== (int)$listing['category_id'] ||
            $description !== (string)$listing['description'] ||
            abs($price - (float)$listing['price']) > 0.00001 ||
            $quantity !== (int)$listing['quantity'] ||
            $condition !== (string)$listing['condition'] ||
            $location !== (string)$listing['location'] ||
            $allowDelivery !== (int)$listing['allow_delivery'] ||
            $allowPickup !== (int)$listing['allow_pickup'] ||
            $pickupAddress !== normalize_address_input($listing['pickup_address'] ?? '') ||
            $status !== (string)$listing['status']
        );

        $hasPhotoDeletes = count(array_filter($deleteImageIds, static fn($id) => (int)$id > 0)) > 0;
        $hasPhotoUploads = uploaded_photos_selected($_FILES['photos'] ?? []);

        if (!$listingChanged && !$hasPhotoDeletes && !$hasPhotoUploads) {
            $message = t('No listing changes were detected. Nothing was updated.');
            $messageClass = 'notice';
        } else {
            $updated = true;

            if ($listingChanged) {
                $stmt = $conn->prepare("
                    UPDATE listings
                    SET title = ?, category_id = ?, description = ?, price = ?, quantity = ?, `condition` = ?, location = ?,
                        allow_delivery = ?, allow_pickup = ?, pickup_address = ?, status = ?
                    WHERE listing_id = ? AND seller_id = ?
                ");
                $stmt->bind_param(
                    'sisdissiissii',
                    $title,
                    $categoryId,
                    $description,
                    $price,
                    $quantity,
                    $condition,
                    $location,
                    $allowDelivery,
                    $allowPickup,
                    $pickupAddress,
                    $status,
                    $listingId,
                    $userId
                );

                $updated = $stmt->execute();
            }

            if ($updated) {
                $deletedCount = delete_listing_images($conn, $listingId, $userId, $deleteImageIds);
                $uploadResult = save_listing_images(
                    $_FILES['photos'] ?? [],
                    $listingId,
                    $conn
                );

                $addedCount = (int)$uploadResult['added'];
                $uploadErrors = $uploadResult['errors'];

                $parts = [];
                if ($listingChanged) {
                    $parts[] = t('listing details updated');
                }
                if ($deletedCount > 0) {
                    $parts[] = t('removed') . ' ' . $deletedCount . ' ' . t($deletedCount === 1 ? 'photo' : 'photos');
                }
                if ($addedCount > 0) {
                    $parts[] = t('added') . ' ' . $addedCount . ' ' . t($addedCount === 1 ? 'photo' : 'photos');
                }

                if ($parts) {
                    $message =
                        t('Listing updated successfully:') .
                        ' ' . implode(', ', $parts) . '.';

                    $messageClass = 'notice notice--success';
                } else {
                    $message =
                        t('No listing changes were detected. Nothing was updated.');

                    $messageClass = 'notice';
                }

                if (!empty($uploadErrors)) {
                    $uploadErrorMessage =
                        'Some selected photos were not uploaded: ' .
                        implode(' | ', $uploadErrors);

                    if ($parts) {
                        $message .= ' ' . $uploadErrorMessage;
                    } else {
                        $message = $uploadErrorMessage;
                    }

                    $messageClass = 'notice notice--error';
                }

                $listing = fetch_listing_detail($conn, $listingId, $userId);
            } else {
                $message = t('Could not update listing. Please make sure the database has been updated with the Buy Now fields.');
                $messageClass = 'notice notice--error';
            }
        }
    }
}

if ($message === '' && ($_GET['removed'] ?? '') === '1') {
    $message = t('Listing removed successfully. Buyers can no longer view or buy it.');
    $messageClass = 'notice notice--success';
} elseif ($message === '' && ($_GET['restored'] ?? '') === '1') {
    $message = t('Listing restored successfully. Buyers can view and buy it again.');
    $messageClass = 'notice notice--success';
}
if (
    $message === '' &&
    isset($_SESSION['listing_photo_upload_notice']) &&
    is_array($_SESSION['listing_photo_upload_notice'])
) {
    $uploadNotice = $_SESSION['listing_photo_upload_notice'];
    unset($_SESSION['listing_photo_upload_notice']);

    $addedCount = (int)($uploadNotice['added'] ?? 0);
    $uploadErrors = $uploadNotice['errors'] ?? [];

    if (!is_array($uploadErrors)) {
        $uploadErrors = [];
    }

    if ($addedCount > 0) {
        $message =
            'Listing created and ' .
            $addedCount . ' ' .
            ($addedCount === 1 ? 'photo was' : 'photos were') .
            ' uploaded. However, some photos were rejected: ' .
            implode(' | ', $uploadErrors);
    } else {
        $message =
            'The listing was created, but the selected photos could not be uploaded: ' .
            implode(' | ', $uploadErrors);
    }

    $messageClass = 'notice notice--error';
}

$imagesHtml = '';
$imgStmt = $conn->prepare("SELECT image_id, image_path, is_primary FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, image_id ASC");
$imgStmt->bind_param('i', $listingId);
$imgStmt->execute();
$imgResult = $imgStmt->get_result();
while ($img = $imgResult->fetch_assoc()) {
    $primaryLabel = ((int)$img['is_primary'] === 1) ? '<span class="image-badge">' . h(t('Primary')) . '</span>' : '';
    $imagesHtml .= '
      <label class="image-thumb image-thumb--selectable">
        <img src="' . h($img['image_path']) . '" alt="' . h(t('Listing image')) . '">
        ' . $primaryLabel . '
        <span class="image-remove-option">
          <input type="checkbox" name="delete_images[]" value="' . h($img['image_id']) . '">
          ' . h(t('Remove photo')) . '
        </span>
      </label>
    ';
}
if ($imagesHtml === '') {
    $imagesHtml = '<div class="empty-state">' . h(t('No photos uploaded for this listing yet.')) . '</div>';
}

$header = get_header($conn, 'manage_account');
$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';

$allowDelivery = (int)($listing['allow_delivery'] ?? 1);
$allowPickup = (int)($listing['allow_pickup'] ?? 1);

$isRemoved = (string)($listing['status'] ?? '') === 'removed';
$statusAction = $isRemoved ? 'restore_listing' : 'delete_listing';
$statusButtonLabel = $isRemoved ? t('Restore listing') : t('Remove listing');
$statusButtonClass = $isRemoved ? 'button button--ghost' : 'button button--ghost button--danger';
$statusConfirm = $isRemoved
    ? t('Are you sure you want to restore this listing? Buyers will be able to view and buy it again.')
    : t('Are you sure you want to remove this listing? Buyers will no longer be able to view or buy it.');

echo render_template(__DIR__ . '/templates/listing_detail.html', [
    'HEADER' => $header,
    'MESSAGE' => $messageHtml,
    'LISTING_ID' => h($listingId),
    'TITLE' => h($listing['title']),
    'DESCRIPTION' => h($listing['description']),
    'PRICE' => h($listing['price']),
    'QUANTITY' => h($listing['quantity']),
    'CATEGORY_OPTIONS' => build_category_options($conn, $listing['category_id'], false),
    'LOCATION_OPTIONS' => build_location_options($listing['location'], false),
    'CONDITION_OPTIONS' => build_condition_options($listing['condition']),
    'STATUS_OPTIONS' => build_status_options($listing['status']),
    'DELIVERY_CHECKED' => $allowDelivery === 1 ? 'checked' : '',
    'PICKUP_CHECKED' => $allowPickup === 1 ? 'checked' : '',
    'PICKUP_ADDRESS_FIELDS' => render_address_fields('pickup', $listing['pickup_address'] ?? '', false, false),
    'IMAGES' => $imagesHtml,
	'STATUS_ACTION' => h($statusAction),
	'STATUS_BUTTON_LABEL' => h($statusButtonLabel),
	'STATUS_BUTTON_CLASS' => h($statusButtonClass),
	'STATUS_CONFIRM' => h($statusConfirm),
]);
?>
