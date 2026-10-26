<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';

$userId = ensure_logged_in();
$userLanguage = get_user_language($conn, $userId);
$header = get_header($conn, 'wishlist');

$sql = "
    SELECT
        w.wishlist_id,
        w.created_at AS saved_at,
        l.listing_id,
        l.title,
        l.description,
        l.price,
        l.quantity,
        l.`condition`,
        l.location,
        l.status,
        c.category_name,
        s.store_name,
        (
            SELECT li.image_path
            FROM listing_images li
            WHERE li.listing_id = l.listing_id
            ORDER BY li.is_primary DESC, li.image_id ASC
            LIMIT 1
        ) AS primary_image
    FROM wishlists w
    INNER JOIN listings l ON l.listing_id = w.listing_id
    INNER JOIN categories c ON c.category_id = l.category_id
    INNER JOIN seller s ON s.user_id = l.seller_id
    WHERE w.user_id = ?
    ORDER BY w.created_at DESC, w.wishlist_id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();

$itemsHtml = '';
while ($row = $result->fetch_assoc()) {
    $title = (string)($row['title'] ?? '');
    $price = 'R' . number_format((float)($row['price'] ?? 0), 2);
    $image = $row['primary_image'] ?? '';
    $status = (string)($row['status'] ?? 'active');
    $isActive = $status === 'active';

    $imageHtml = $image !== ''
        ? '<img src="' . h($image) . '" alt="' . h($title) . '">'
        : '<div class="listing-card__placeholder">' . h(t('No image')) . '</div>';

    $viewAction = $isActive
        ? '<a class="button button--solid" href="listing_view.php?id=' . h($row['listing_id']) . '">' . h(t('View listing')) . '</a>'
        : '<button class="button button--ghost" type="button" disabled>' . h(t('Listing unavailable')) . '</button>';

    $itemsHtml .= '
      <article class="wishlist-card">
        <a class="wishlist-card__image" href="' . ($isActive ? 'listing_view.php?id=' . h($row['listing_id']) : '#') . '">
          ' . $imageHtml . '
        </a>

        <div class="wishlist-card__body">
          <p class="eyebrow">' . h(t('Saved item')) . '</p>
          <h2 data-no-translate>' . h($title) . '</h2>

          <div class="listing-card__meta">
            <span class="pill">' . h(translate_dynamic_text($row['category_name'] ?? 'General', $userLanguage)) . '</span>
            <span class="pill">' . h(translate_dynamic_text($row['condition'] ?? 'Unknown', $userLanguage)) . '</span>
            <span class="pill">' . h(translate_dynamic_text($row['location'] ?? 'All provinces', $userLanguage)) . '</span>
          </div>

          <div class="wishlist-card__details">
            <div><span>' . h(t('Price')) . '</span><strong>' . h($price) . '</strong></div>
            <div><span>' . h(t('Seller')) . '</span><strong data-no-translate>' . h($row['store_name']) . '</strong></div>
            <div><span>' . h(t('Status')) . '</span><strong>' . h(translate_dynamic_text(ucfirst($status), $userLanguage)) . '</strong></div>
            <div><span>' . h(t('Saved on')) . '</span><strong>' . h($row['saved_at']) . '</strong></div>
          </div>

          <div class="wishlist-card__actions">
            ' . $viewAction . '
            <form method="post" action="actions/wishlist.php">
              <input type="hidden" name="listing_id" value="' . h($row['listing_id']) . '">
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="return_to" value="wishlist.php">
              <button class="button button--ghost" type="submit">' . h(t('Remove from wishlist')) . '</button>
            </form>
          </div>
        </div>
      </article>
    ';
}

if ($itemsHtml === '') {
    $itemsHtml = '
      <div class="empty-state wishlist-empty">
        <strong>' . h(t('Your wishlist is empty.')) . '</strong>
        <span>' . h(t('Save listings you like and return to them from here.')) . '</span>
        <a class="button button--solid" href="dashboard.php">' . h(t('Browse marketplace')) . '</a>
      </div>
    ';
}

echo render_template(__DIR__ . '/templates/wishlist.html', [
    'HEADER' => $header,
    'WISHLIST_ITEMS' => $itemsHtml,
]);
?>
