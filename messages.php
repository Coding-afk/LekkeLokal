<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/chat_helpers.php';

$userId = ensure_logged_in();
$userLanguage = get_user_language($conn, $userId);
$selectedChatId = (int)($_GET['chat_id'] ?? ($_POST['chat_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    security_require_csrf();

    $postChatId = (int)($_POST['chat_id'] ?? 0);
    $messageText = trim((string)($_POST['message_text'] ?? ''));

    if ($postChatId > 0 && $messageText !== '' && chat_user_can_access($conn, $postChatId, $userId)) {
        chat_add_message($conn, $postChatId, $userId, $messageText);
        header('Location: messages.php?chat_id=' . $postChatId . '&sent=1');
        exit;
    }

    header('Location: messages.php?chat_id=' . max(0, $postChatId) . '&send_error=1');
    exit;
}

$chatListStmt = $conn->prepare("
    SELECT
        c.chat_id,
        c.listing_id,
        c.buyer_id,
        c.seller_id,
        c.last_message_at,
        l.title AS listing_title,
        other_user.first_name,
        other_user.last_name,
        (
            SELECT cm.message_text
            FROM chat_messages cm
            WHERE cm.chat_id = c.chat_id
            ORDER BY cm.sent_at DESC, cm.chat_message_id DESC
            LIMIT 1
        ) AS last_message
    FROM chats c
    LEFT JOIN listings l ON l.listing_id = c.listing_id
    INNER JOIN users other_user
      ON other_user.user_id = CASE
        WHEN c.buyer_id = ? THEN c.seller_id
        ELSE c.buyer_id
      END
    WHERE c.buyer_id = ? OR c.seller_id = ?
    ORDER BY c.last_message_at DESC, c.created_at DESC
");
$chatListStmt->bind_param('iii', $userId, $userId, $userId);
$chatListStmt->execute();
$chatListResult = $chatListStmt->get_result();

$chatList = [];
while ($row = $chatListResult->fetch_assoc()) {
    $chatList[] = $row;
}

if ($selectedChatId <= 0 && count($chatList) > 0) {
    $selectedChatId = (int)$chatList[0]['chat_id'];
}

$chatCardsHtml = '';
foreach ($chatList as $chat) {
    $isActive = ((int)$chat['chat_id'] === $selectedChatId) ? ' is-active' : '';
    $name = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
    $lastMessage = trim((string)($chat['last_message'] ?? 'No messages yet.'));
    $listingTitle = $chat['listing_title'] ?? 'General chat';

    if (strlen($lastMessage) > 72) {
        $lastMessage = substr($lastMessage, 0, 72) . '...';
    }

    $chatCardsHtml .= '
      <a class="chat-thread-card' . $isActive . '" href="messages.php?chat_id=' . h($chat['chat_id']) . '">
        <strong>' . h($name) . '</strong>
        <span>' . h($listingTitle) . '</span>
        <small>' . h($lastMessage) . '</small>
      </a>
    ';
}

if ($chatCardsHtml === '') {
    $chatCardsHtml = '<div class="empty-state">No chats yet. Open a listing and message the seller to start a chat.</div>';
}

$chatMessagesHtml = '';
$chatTitle = 'No chat selected';
$chatSubtitle = 'Start a chat from a listing.';
$lastMessageId = 0;

if ($selectedChatId > 0 && chat_user_can_access($conn, $selectedChatId, $userId)) {
    $chatInfoStmt = $conn->prepare("
        SELECT
            c.chat_id,
            l.title AS listing_title,
            other_user.first_name,
            other_user.last_name
        FROM chats c
        LEFT JOIN listings l ON l.listing_id = c.listing_id
        INNER JOIN users other_user
          ON other_user.user_id = CASE
            WHEN c.buyer_id = ? THEN c.seller_id
            ELSE c.buyer_id
          END
        WHERE c.chat_id = ? AND (c.buyer_id = ? OR c.seller_id = ?)
        LIMIT 1
    ");
    $chatInfoStmt->bind_param('iiii', $userId, $selectedChatId, $userId, $userId);
    $chatInfoStmt->execute();
    $chatInfo = $chatInfoStmt->get_result()->fetch_assoc();

    if ($chatInfo) {
        $chatTitle = trim(($chatInfo['first_name'] ?? '') . ' ' . ($chatInfo['last_name'] ?? ''));
        $chatSubtitle = $chatInfo['listing_title'] ?? 'General chat';
    }

    $markReadStmt = $conn->prepare("
        UPDATE chat_messages
        SET is_read = TRUE
        WHERE chat_id = ? AND sender_id <> ?
    ");
    $markReadStmt->bind_param('ii', $selectedChatId, $userId);
    $markReadStmt->execute();

    $messagesStmt = $conn->prepare("
        SELECT chat_message_id, sender_id, message_text, sent_at
        FROM chat_messages
        WHERE chat_id = ?
        ORDER BY sent_at ASC, chat_message_id ASC
    ");
    $messagesStmt->bind_param('i', $selectedChatId);
    $messagesStmt->execute();
    $messagesResult = $messagesStmt->get_result();

    $currentDay = '';
    while ($msg = $messagesResult->fetch_assoc()) {
        $dayLabel = chat_day_label($msg['sent_at']);
        $dayLabel = in_array($dayLabel, ['Today', 'Yesterday'], true) ? t($dayLabel) : $dayLabel;

        if ($dayLabel !== $currentDay) {
            $currentDay = $dayLabel;
            $chatMessagesHtml .= '<div class="chat-day-separator" data-day-label="' . h($dayLabel) . '">' . h($dayLabel) . '</div>';
        }

        $ownClass = ((int)$msg['sender_id'] === $userId) ? ' chat-message--own' : '';
        $chatMessagesHtml .= '
          <div class="chat-message' . $ownClass . '" data-message-id="' . h($msg['chat_message_id']) . '">
            <div class="chat-bubble">
              <p>' . nl2br(h($msg['message_text'])) . '</p>
              <span>' . h(chat_time_label($msg['sent_at'])) . '</span>
            </div>
          </div>
        ';
        $lastMessageId = (int)$msg['chat_message_id'];
    }

    if ($chatMessagesHtml === '') {
        $chatMessagesHtml = '<div class="empty-state chat-empty-state">No messages yet. Send the first message below.</div>';
    }
} elseif ($selectedChatId > 0) {
    $selectedChatId = 0;
    $chatMessagesHtml = '<div class="empty-state chat-empty-state">You do not have access to this chat.</div>';
} else {
    $chatMessagesHtml = '<div class="empty-state chat-empty-state">Select a chat from the left, or start one from a listing.</div>';
}

$chatFormHtml = '';
if ($selectedChatId > 0) {
    $chatFormHtml = '
      <form class="chat-send-form" method="post" action="messages.php?chat_id=' . h($selectedChatId) . '">
        <input type="hidden" name="chat_id" value="' . h($selectedChatId) . '">
        <textarea name="message_text" placeholder="Please keep the conversation in English where possible unless you both agree to another language in chat." required></textarea>
        <button class="button button--solid" type="submit">Send</button>
      </form>
    ';
}

$header = get_header($conn, 'chat');

echo render_template(__DIR__ . '/templates/chat.html', [
    'HEADER' => $header,
    'CHAT_LIST' => $chatCardsHtml,
    'CHAT_TITLE' => h($chatTitle),
    'CHAT_SUBTITLE' => h($chatSubtitle),
    'CHAT_ID' => h($selectedChatId),
    'CURRENT_USER_ID' => h($userId),
    'LAST_MESSAGE_ID' => h($lastMessageId),
    'MESSAGES' => $chatMessagesHtml,
    'CHAT_FORM' => $chatFormHtml,
]);
?>
