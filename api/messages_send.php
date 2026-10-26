<?php
require_once __DIR__ . '/../includes/security.php';
security_start_session();
header('Content-Type: application/json');

require '../includes/db.php';
require '../includes/helpers.php';
require '../includes/chat_helpers.php';

try {
    $userId = ensure_logged_in();
    $userLanguage = get_user_language($conn, $userId);
    $chatId = (int)($_POST['chat_id'] ?? 0);
    $messageText = trim($_POST['message_text'] ?? '');

    if ($chatId <= 0) {
        throw new Exception('Invalid chat.');
    }

    $messageId = chat_add_message($conn, $chatId, $userId, $messageText);

    $stmt = $conn->prepare("
        SELECT chat_message_id, sender_id, message_text, sent_at
        FROM chat_messages
        WHERE chat_message_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    echo json_encode([
        'success' => true,
        'message' => [
            'id' => (int)$row['chat_message_id'],
            'chat_id' => $chatId,
            'sender_id' => (int)$row['sender_id'],
            'message_text' => $row['message_text'],
            'original_text' => $row['message_text'],
            'sent_at' => $row['sent_at'],
            'day_label' => in_array(chat_day_label($row['sent_at']), ['Today', 'Yesterday'], true) ? t(chat_day_label($row['sent_at'])) : chat_day_label($row['sent_at']),
            'time_label' => chat_time_label($row['sent_at']),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
?>
