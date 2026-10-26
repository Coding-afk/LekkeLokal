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
    $chatId = (int)($_GET['chat_id'] ?? 0);
    $afterId = (int)($_GET['after_id'] ?? 0);

    if ($chatId <= 0 || !chat_user_can_access($conn, $chatId, $userId)) {
        throw new Exception('You do not have access to this chat.');
    }

    $stmt = $conn->prepare("
        SELECT chat_message_id, sender_id, message_text, sent_at
        FROM chat_messages
        WHERE chat_id = ? AND chat_message_id > ?
        ORDER BY sent_at ASC, chat_message_id ASC
    ");
    $stmt->bind_param('ii', $chatId, $afterId);
    $stmt->execute();
    $result = $stmt->get_result();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'id' => (int)$row['chat_message_id'],
            'chat_id' => $chatId,
            'sender_id' => (int)$row['sender_id'],
            'message_text' => $row['message_text'],
            'original_text' => $row['message_text'],
            'sent_at' => $row['sent_at'],
            'day_label' => in_array(chat_day_label($row['sent_at']), ['Today', 'Yesterday'], true) ? t(chat_day_label($row['sent_at'])) : chat_day_label($row['sent_at']),
            'time_label' => chat_time_label($row['sent_at']),
        ];
    }

    if (!empty($messages)) {
        $markRead = $conn->prepare("
            UPDATE chat_messages
            SET is_read = TRUE
            WHERE chat_id = ? AND sender_id <> ? AND chat_message_id > ?
        ");
        $markRead->bind_param('iii', $chatId, $userId, $afterId);
        $markRead->execute();
    }

    echo json_encode([
        'success' => true,
        'messages' => $messages,
    ]);
} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
?>
