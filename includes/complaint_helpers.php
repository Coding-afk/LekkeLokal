<?php
function complaint_types(): array {
    return [
        'Product Misrepresentation & Quality',
        'Transaction & Fraud Issues',
        'Shipping & Logistics',
        'Platform & Customer Service',
    ];
}

function complaint_type_allowed(string $type): bool {
    return in_array($type, complaint_types(), true);
}

function complaint_type_options(?string $selected = null): string {
    $html = '<option value="">' . h(t('Select complaint type')) . '</option>';

    foreach (complaint_types() as $type) {
        $isSelected = $selected === $type ? ' selected' : '';
        $html .= '<option value="' . h($type) . '"' . $isSelected . '>' . h(t($type)) . '</option>';
    }

    return $html;
}

function complaint_status_label(string $status): string {
    $status = strtolower(trim($status));

    if ($status === 'completed' || $status === 'handled') {
        return t('Handled / completed');
    }

    return t('Open');
}

function get_order_seller_summary(mysqli $conn, int $orderId): array {
    $stmt = $conn->prepare("
        SELECT DISTINCT
            s.user_id AS seller_id,
            s.store_name,
            u.email,
            u.phone_number
        FROM order_items oi
        INNER JOIN listings l ON l.listing_id = oi.listing_id
        INNER JOIN seller s ON s.user_id = l.seller_id
        INNER JOIN users u ON u.user_id = s.user_id
        WHERE oi.order_id = ?
        ORDER BY s.store_name ASC
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();

    $sellers = [];
    while ($row = $result->fetch_assoc()) {
        $sellers[] = $row;
    }

    return $sellers;
}

function get_primary_order_seller_id(mysqli $conn, int $orderId): int {
    $sellers = get_order_seller_summary($conn, $orderId);
    if (!$sellers) {
        return 0;
    }

    return (int)$sellers[0]['seller_id'];
}
?>
