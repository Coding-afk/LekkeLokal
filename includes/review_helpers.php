<?php
function review_star_display(float $rating, string $className = ''): string {
    $rounded = (int)round($rating);
    if ($rounded < 0) $rounded = 0;
    if ($rounded > 5) $rounded = 5;

    $stars = str_repeat('★', $rounded) . str_repeat('☆', 5 - $rounded);
    $classAttr = trim('review-stars ' . $className);

    return '<span class="' . h($classAttr) . '" aria-label="' . h(number_format($rating, 1)) . ' out of 5 stars">' . h($stars) . '</span>';
}

function review_summary_html(?float $average, int $count): string {
    if ($count <= 0 || $average === null) {
        return '<div class="review-summary review-summary--empty"><span class="review-stars">☆☆☆☆☆</span><strong>' . h(t('No reviews yet')) . '</strong></div>';
    }

    return '
      <div class="review-summary">
        ' . review_star_display((float)$average) . '
        <strong>' . h(number_format((float)$average, 1)) . '/5</strong>
        <span>(' . h($count) . ' ' . h($count === 1 ? t('review') : t('reviews')) . ')</span>
      </div>
    ';
}

function get_listing_review_stats(mysqli $conn, int $listingId): array {
    $stmt = $conn->prepare("
        SELECT AVG(rating) AS average_rating, COUNT(*) AS review_count
        FROM reviews
        WHERE listing_id = ?
    ");
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return [
        'average' => $row && $row['average_rating'] !== null ? (float)$row['average_rating'] : null,
        'count' => (int)($row['review_count'] ?? 0),
    ];
}

function get_seller_review_stats(mysqli $conn, int $sellerId): array {
    $stmt = $conn->prepare("
        SELECT AVG(rating) AS average_rating, COUNT(*) AS review_count
        FROM reviews
        WHERE seller_id = ?
    ");
    $stmt->bind_param('i', $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return [
        'average' => $row && $row['average_rating'] !== null ? (float)$row['average_rating'] : null,
        'count' => (int)($row['review_count'] ?? 0),
    ];
}

function seller_rating_text(?float $average, int $count): string {
    if ($count <= 0 || $average === null) {
        return t('No ratings yet');
    }

    return number_format((float)$average, 1) . '/5 (' . $count . ' ' . ($count === 1 ? t('review') : t('reviews')) . ')';
}

function render_review_pagination(string $baseUrl, int $currentPage, int $totalPages): string {
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<nav class="review-pagination" aria-label="' . h(t('Review pages')) . '">';

    for ($page = 1; $page <= $totalPages; $page++) {
        $activeClass = $page === $currentPage ? ' is-active' : '';
        $html .= '<a class="review-page-link' . $activeClass . '" href="' . h($baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'reviews_page=' . $page . '#reviews') . '">' . h($page) . '</a>';
    }

    $html .= '</nav>';
    return $html;
}
?>
