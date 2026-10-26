<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';

$userId = ensure_logged_in();
ensure_user_profiles($conn, $userId, ($_SESSION['first_name'] ?? 'Buyer') . "'s Store");

$listingId = (int)($_GET['id'] ?? $_POST['listing_id'] ?? 0);
if ($listingId <= 0) {
    die('Invalid listing.');
}

$message = '';
$messageClass = 'notice notice--error';

function get_buy_listing(mysqli $conn, int $listingId, int $userId): ?array {
    $stmt = $conn->prepare("
        SELECT
            l.*,
            c.category_name,
            s.store_name,
            b.default_shipping_address,
            (
                SELECT li.image_path
                FROM listing_images li
                WHERE li.listing_id = l.listing_id
                ORDER BY li.is_primary DESC, li.image_id ASC
                LIMIT 1
            ) AS primary_image
        FROM listings l
        INNER JOIN categories c ON c.category_id = l.category_id
        INNER JOIN seller s ON s.user_id = l.seller_id
        LEFT JOIN buyer b ON b.user_id = ?
        WHERE l.listing_id = ? AND l.status = 'active' AND l.seller_id <> ?
        LIMIT 1
    ");
    $stmt->bind_param('iii', $userId, $listingId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}

$listing = get_buy_listing($conn, $listingId, $userId);
if (!$listing) {
    die('Listing not found, unavailable, or you cannot buy your own listing.');
}

$allowDelivery = (int)($listing['allow_delivery'] ?? 1);
$allowPickup = (int)($listing['allow_pickup'] ?? 1);
$pickupAddress = normalize_address_input($listing['pickup_address'] ?? '');
$pickupUsable = ($allowPickup === 1 && $pickupAddress !== '') ? 1 : 0;

if ($allowDelivery === 0 && $pickupUsable === 0) {
    die('This listing has no available fulfilment option.');
}

$selectedMethod = $_POST['delivery_method'] ?? ($allowDelivery ? 'delivery' : 'pickup');
if ($selectedMethod === 'pickup' && $pickupUsable !== 1) {
    $selectedMethod = $allowDelivery ? 'delivery' : 'pickup';
}
$selectedQty = max(1, (int)($_POST['quantity'] ?? 1));
$savedAddress = normalize_address_input($listing['default_shipping_address'] ?? '');
$deliveryAddress = ($_SERVER['REQUEST_METHOD'] === 'POST') ? build_address_from_post('delivery') : $savedAddress;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'place_order';

    if ($selectedQty < 1) {
        $message = 'Quantity must be at least 1.';
    } elseif ($selectedQty > (int)$listing['quantity']) {
        $message = 'You cannot order more than the available stock.';
    } elseif ($selectedMethod === 'delivery' && $allowDelivery !== 1) {
        $message = 'Delivery is not available for this listing.';
    } elseif ($selectedMethod === 'pickup' && $pickupUsable !== 1) {
        $message = 'Pickup is not available for this listing because no pickup address was provided.';
    } elseif ($selectedMethod === 'delivery' && $deliveryAddress === '') {
        $message = t('Please enter a delivery address.');
    } elseif ($selectedMethod === 'delivery' && !is_valid_address_format($deliveryAddress)) {
        $message = address_format_message();
    } else {
        if ($action === 'save_address') {
            if ($deliveryAddress === $savedAddress) {
                $message = t('This delivery address is already saved. No changes were made.');
                $messageClass = 'notice';
            } else {
                $updateAddress = $conn->prepare("UPDATE buyer SET default_shipping_address = ? WHERE user_id = ?");
                $updateAddress->bind_param('si', $deliveryAddress, $userId);
                $updateAddress->execute();

                if ($updateAddress->affected_rows > 0) {
                    $message = t('Delivery address saved successfully.');
                    $messageClass = 'notice notice--success';
                    $savedAddress = $deliveryAddress;
                } else {
                    $message = t('No address changes were detected. Nothing was updated.');
                    $messageClass = 'notice';
                }
            }
        } elseif ($action === 'place_order') {
            $conn->begin_transaction();

            try {
                $lockStmt = $conn->prepare("
                    SELECT listing_id, seller_id, price, quantity, status, allow_delivery, allow_pickup, pickup_address
                    FROM listings
                    WHERE listing_id = ?
                    FOR UPDATE
                ");
                $lockStmt->bind_param('i', $listingId);
                $lockStmt->execute();
                $locked = $lockStmt->get_result()->fetch_assoc();

                if (!$locked || $locked['status'] !== 'active') {
                    throw new Exception('This listing is no longer available.');
                }

                if ((int)$locked['seller_id'] === $userId) {
                    throw new Exception('You cannot buy your own listing.');
                }

                if ($selectedQty > (int)$locked['quantity']) {
                    throw new Exception('The requested quantity is no longer available.');
                }

                if ($selectedMethod === 'delivery' && (int)$locked['allow_delivery'] !== 1) {
                    throw new Exception('Delivery is no longer available for this listing.');
                }

                $lockedPickupAddress = normalize_address_input($locked['pickup_address'] ?? '');
                if ($selectedMethod === 'pickup' && ((int)$locked['allow_pickup'] !== 1 || $lockedPickupAddress === '')) {
                    throw new Exception('Pickup is no longer available for this listing because no pickup address was provided.');
                }

                $unitPrice = (float)$locked['price'];
                $totalAmount = $unitPrice * $selectedQty;
                $orderAddress = $selectedMethod === 'delivery'
                    ? $deliveryAddress
                    : trim((string)($locked['pickup_address'] ?? ''));

                if ($orderAddress === '') {
                    throw new Exception('No address is available for the selected fulfilment method.');
                }

                $orderStatus = 'pending';
                $insertOrder = $conn->prepare("
                    INSERT INTO orders (buyer_id, status, total_amount, delivery_method, delivery_address)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insertOrder->bind_param('isdss', $userId, $orderStatus, $totalAmount, $selectedMethod, $orderAddress);
                $insertOrder->execute();
                $orderId = (int)$conn->insert_id;

                $insertItem = $conn->prepare("
                    INSERT INTO order_items (order_id, listing_id, quantity, unit_price)
                    VALUES (?, ?, ?, ?)
                ");
                $insertItem->bind_param('iiid', $orderId, $listingId, $selectedQty, $unitPrice);
                $insertItem->execute();

                $remainingQty = (int)$locked['quantity'] - $selectedQty;
                $newStatus = $remainingQty <= 0 ? 'sold' : 'active';
                $updateListing = $conn->prepare("
                    UPDATE listings
                    SET quantity = ?, status = ?
                    WHERE listing_id = ?
                ");
                $updateListing->bind_param('isi', $remainingQty, $newStatus, $listingId);
                $updateListing->execute();

                $conn->commit();

                header('Location: dashboard.php?order=success');
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $messageClass = 'notice notice--error';
            }
        }
    }
}

$listing = get_buy_listing($conn, $listingId, $userId);
if (!$listing) {
    header('Location: dashboard.php');
    exit;
}

$allowDelivery = (int)($listing['allow_delivery'] ?? 1);
$allowPickup = (int)($listing['allow_pickup'] ?? 1);
$pickupAddress = normalize_address_input($listing['pickup_address'] ?? '');
$pickupUsable = ($allowPickup === 1 && $pickupAddress !== '') ? 1 : 0;

if ($selectedMethod === 'pickup' && $pickupUsable === 1) {
    // Keep pickup selected.
} else {
    $selectedMethod = ($selectedMethod === 'pickup' && $allowPickup) ? 'pickup' : ($allowDelivery ? 'delivery' : 'pickup');
}

$deliveryAddressDisplay = $selectedMethod === 'delivery' ? '' : 'style="display:none"';
$pickupAddressDisplay = $selectedMethod === 'pickup' ? '' : 'style="display:none"';

$imageHtml = ($listing['primary_image'] ?? '') !== ''
    ? '<img src="' . h($listing['primary_image']) . '" alt="' . h($listing['title']) . '">'
    : '<div class="buy-photo-placeholder">No photo uploaded</div>';

$deliveryRadio = $allowDelivery === 1
    ? '<label class="radio-card"><input type="radio" name="delivery_method" value="delivery" data-buy-method ' . ($selectedMethod === 'delivery' ? 'checked' : '') . '> <span>Delivery</span></label>'
    : '';

if ($allowPickup === 1 && $pickupUsable === 1) {
    $pickupRadio = '<label class="radio-card"><input type="radio" name="delivery_method" value="pickup" data-buy-method ' . ($selectedMethod === 'pickup' ? 'checked' : '') . '> <span>Pickup</span></label>';
} elseif ($allowPickup === 1 && $pickupUsable !== 1) {
    $pickupRadio = '<label class="radio-card radio-card--disabled" title="Pickup is unavailable because the seller has not added a pickup address."><input type="radio" name="delivery_method" value="pickup" disabled> <span>Pickup unavailable</span></label>';
} else {
    $pickupRadio = '';
}

$messageHtml = $message !== '' ? '<div class="' . h($messageClass) . '">' . h($message) . '</div>' : '';
$header = get_header($conn, 'dashboard');

echo render_template(__DIR__ . '/templates/buy_now.html', [
    'HEADER' => $header,
    'MESSAGE' => $messageHtml,
    'LISTING_ID' => h($listingId),
    'TITLE' => h($listing['title']),
    'IMAGE' => $imageHtml,
    'PRICE' => h(number_format((float)$listing['price'], 2, '.', '')),
    'PRICE_DISPLAY' => 'R' . h(number_format((float)$listing['price'], 2)),
    'MAX_QTY' => h($listing['quantity']),
    'SELECTED_QTY' => h($selectedQty),
    'DELIVERY_RADIO' => $deliveryRadio,
    'PICKUP_RADIO' => $pickupRadio,
    'DELIVERY_ADDRESS_FIELDS' => render_address_fields('delivery', $deliveryAddress, true, false),
    'PICKUP_ADDRESS_FIELDS' => render_address_fields('pickup_display', $pickupAddress, false, true),
    'DELIVERY_ADDRESS_DISPLAY' => $deliveryAddressDisplay,
    'PICKUP_ADDRESS_DISPLAY' => $pickupAddressDisplay,
]);
?>
