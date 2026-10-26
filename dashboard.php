<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';

$userId = ensure_logged_in();
$firstName = $_SESSION['first_name'] ?? 'User';

$q = trim($_GET['q'] ?? '');
$categoryId = trim($_GET['category_id'] ?? 'all');
$location = trim($_GET['location'] ?? 'all');

$header = get_header($conn, 'dashboard');

$where = ["l.status = 'active'", "l.seller_id <> ?"];
$params = [$userId];
$types = 'i';

if ($categoryId !== '' && $categoryId !== 'all' && ctype_digit($categoryId)) {
    $where[] = "l.category_id = ?";
    $params[] = (int)$categoryId;
    $types .= 'i';
}

if ($location !== '' && $location !== 'all') {
    $where[] = "l.location = ?";
    $params[] = $location;
    $types .= 's';
}

$orderBy = "l.created_at DESC";

if ($q !== '') {
    $searchParts = ["l.title LIKE ?", "l.description LIKE ?", "c.category_name LIKE ?", "l.`condition` LIKE ?", "l.location LIKE ?", "s.store_name LIKE ?"];
    $phrase = '%' . $q . '%';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $phrase;
        $types .= 's';
    }

    $terms = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $like = '%' . $term . '%';
        $searchParts[] = "l.title LIKE ?";
        $params[] = $like;
        $types .= 's';
        $searchParts[] = "l.description LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
    $orderBy = "
        CASE
            WHEN l.title LIKE ? THEN 0
            WHEN c.category_name LIKE ? THEN 1
            WHEN l.description LIKE ? THEN 2
            ELSE 3
        END,
        l.created_at DESC
    ";
    $params[] = $phrase;
    $params[] = $phrase;
    $params[] = $phrase;
    $types .= 'sss';
}

$sql = "
    SELECT l.listing_id, l.title, l.description, l.price, l.quantity, l.`condition`, l.location, l.status, l.created_at,
           c.category_name, s.store_name,
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
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $orderBy
    LIMIT 30
";

$listingsHtml = '';
$stmt = $conn->prepare($sql);
if ($stmt) {
    bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $listingsHtml .= render_listing_card($row, false);
    }
}

if ($listingsHtml === '') {
    $listingsHtml = '<div class="empty-state">No matches found. Try a broader search term, remove a filter, or check another province.</div>';
}

$filterNote = 'Showing marketplace listings from other sellers';
if ($q !== '' || ($categoryId !== '' && $categoryId !== 'all') || ($location !== '' && $location !== 'all')) {
    $parts = [];
    if ($q !== '') $parts[] = 'search: "' . h($q) . '"';
    if ($categoryId !== '' && $categoryId !== 'all') $parts[] = 'category filter applied';
    if ($location !== '' && $location !== 'all') $parts[] = h($location);
    $filterNote = 'Filtered by ' . implode(', ', $parts);
}

$orderScript = (($_GET['order'] ?? '') === 'success') ? '<script>window.addEventListener("DOMContentLoaded", function(){ alert("Placed order successfully"); if (window.history && window.history.replaceState) { window.history.replaceState({}, document.title, "dashboard.php"); } });</script>' : '';

echo render_template(__DIR__ . '/templates/dashboard.html', [
    'HEADER' => $header,
    'FIRST_NAME' => h($firstName),
    'LISTINGS' => $listingsHtml,
    'FILTER_NOTE' => h($filterNote),
    'ORDER_SCRIPT' => $orderScript,
]);
?>
