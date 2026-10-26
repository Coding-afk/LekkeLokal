<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/helpers.php';
function admin_tables(): array {
    return [
        'users' => 'Users',
        'buyer' => 'Buyer profiles',
        'seller' => 'Seller profiles',
        'seller_verifications' => 'Seller verifications',
        'admin' => 'Admin users',
        'language_preferences' => 'Language preferences',
        'categories' => 'Categories',
        'listings' => 'Listings',
        'listing_images' => 'Listing images',
        'wishlists' => 'Wishlists',
        'chats' => 'Chats',
        'chat_messages' => 'Chat messages',
        'orders' => 'Orders',
        'order_items' => 'Order items',
        'reviews' => 'Reviews',
        'complaints' => 'Complaints',
    ];
}

function ensure_admin(mysqli $conn): int {
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

    $userId = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT u.role, a.user_id AS admin_user_id
        FROM users u
        LEFT JOIN admin a ON a.user_id = u.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || (($row['role'] ?? '') !== 'admin' && empty($row['admin_user_id']))) {
        header('Location: dashboard.php');
        exit;
    }

    $_SESSION['role'] = 'admin';
    return $userId;
}

function admin_label(string $table): string {
    $tables = admin_tables();
    return $tables[$table] ?? $table;
}

function admin_table_allowed(string $table): bool {
    return array_key_exists($table, admin_tables());
}

function bt(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function admin_column_type(array $col): string {
    $type = strtolower((string)($col['DATA_TYPE'] ?? ''));
    if (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint'], true)) {
        return 'i';
    }
    if (in_array($type, ['decimal', 'float', 'double', 'real'], true)) {
        return 'd';
    }
    return 's';
}

function admin_input_value($value): string {
    if ($value === null) {
        return '';
    }
    return (string)$value;
}

function admin_post_value(array $col) {
    $name = $col['COLUMN_NAME'];
    $raw = $_POST[$name] ?? '';

    if ($raw === '' && strtoupper((string)$col['IS_NULLABLE']) === 'YES') {
        return null;
    }

    $type = strtolower((string)$col['DATA_TYPE']);
    if (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint'], true)) {
        return ($raw === '') ? 0 : (int)$raw;
    }
    if (in_array($type, ['decimal', 'float', 'double', 'real'], true)) {
        return ($raw === '') ? 0 : (float)$raw;
    }

    return (string)$raw;
}

function admin_bind(mysqli_stmt $stmt, string $types, array $values): void {
    if ($types === '') {
        return;
    }

    $refs = [$types];
    foreach ($values as $key => $value) {
        $refs[] = &$values[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}
?>
