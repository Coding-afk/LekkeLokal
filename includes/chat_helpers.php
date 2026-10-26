<?php
require_once __DIR__ . '/lang.php';
function chat_user_can_access(mysqli $conn, int $chatId, int $userId): bool {
    $stmt = $conn->prepare("
        SELECT chat_id
        FROM chats
        WHERE chat_id = ? AND (buyer_id = ? OR seller_id = ?)
        LIMIT 1
    ");
    $stmt->bind_param('iii', $chatId, $userId, $userId);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

function chat_find_or_create_for_listing(mysqli $conn, int $listingId, int $buyerId): int {
    $stmt = $conn->prepare("
        SELECT seller_id
        FROM listings
        WHERE listing_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();

    if (!$listing) {
        throw new Exception('Listing not found.');
    }

    $sellerId = (int)$listing['seller_id'];
    if ($sellerId === $buyerId) {
        throw new Exception('You cannot start a chat with yourself for your own listing.');
    }

    $find = $conn->prepare("
        SELECT chat_id
        FROM chats
        WHERE listing_id = ? AND buyer_id = ? AND seller_id = ?
        LIMIT 1
    ");
    $find->bind_param('iii', $listingId, $buyerId, $sellerId);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();

    if ($existing) {
        return (int)$existing['chat_id'];
    }

    $insert = $conn->prepare("
        INSERT INTO chats (listing_id, buyer_id, seller_id)
        VALUES (?, ?, ?)
    ");
    $insert->bind_param('iii', $listingId, $buyerId, $sellerId);
    $insert->execute();

    return (int)$conn->insert_id;
}

function chat_add_message(mysqli $conn, int $chatId, int $senderId, string $messageText): int {
    if (!chat_user_can_access($conn, $chatId, $senderId)) {
        throw new Exception('You do not have access to this chat.');
    }

    $messageText = trim($messageText);
    if ($messageText === '') {
        throw new Exception('Please type a message before sending.');
    }

    $stmt = $conn->prepare("
        INSERT INTO chat_messages (chat_id, sender_id, message_text)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param('iis', $chatId, $senderId, $messageText);
    $stmt->execute();

    $messageId = (int)$conn->insert_id;

    $update = $conn->prepare("
        UPDATE chats
        SET last_message_at = CURRENT_TIMESTAMP
        WHERE chat_id = ?
    ");
    $update->bind_param('i', $chatId);
    $update->execute();

    return $messageId;
}

function chat_day_label(string $dateTime): string {
    $date = new DateTime($dateTime);
    $today = new DateTime('today');
    $yesterday = new DateTime('yesterday');

    if ($date->format('Y-m-d') === $today->format('Y-m-d')) {
        return 'Today';
    }

    if ($date->format('Y-m-d') === $yesterday->format('Y-m-d')) {
        return 'Yesterday';
    }

    return $date->format('d F Y');
}

function chat_time_label(string $dateTime): string {
    return (new DateTime($dateTime))->format('H:i');
}

?>
