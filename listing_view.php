<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/chat_helpers.php';
require 'includes/review_helpers.php';
require 'includes/seller_verification_helpers.php';

$userId = ensure_logged_in();
$userLanguage = get_user_language($conn, $userId);
$listingId = (int)($_GET['id'] ?? 0);

if ($listingId <= 0) {
    die('Invalid listing.');
}

$stmt = $conn->prepare("
    SELECT
        l.*,
        c.category_name,
        s.store_name,
        s.trust_badge,
        s.verification_status,
        seller_user.email AS seller_email,
        seller_user.first_name AS seller_first_name,
        seller_user.last_name AS seller_last_name,
        buyer_user.email AS buyer_email,
        buyer_user.first_name AS buyer_first_name,
        buyer_user.last_name AS buyer_last_name
    FROM listings l
    INNER JOIN categories c ON c.category_id = l.category_id
    INNER JOIN seller s ON s.user_id = l.seller_id
    INNER JOIN users seller_user ON seller_user.user_id = l.seller_id
    INNER JOIN users buyer_user ON buyer_user.user_id = ?
    WHERE l.listing_id = ? AND l.status = 'active'
    LIMIT 1
");
$stmt->bind_param('ii', $userId, $listingId);
$stmt->execute();
$listing = $stmt->get_result()->fetch_assoc();

if (!$listing) {
    die('Listing not found.');
}

if ((int)$listing['seller_id'] === $userId) {
    header('Location: listing_detail.php?id=' . $listingId);
    exit;
}

$displayTitle = (string)$listing['title'];
$displayDescription = (string)$listing['description'];

$listing['trust_badge'] = update_seller_trust_badge($conn, (int)$listing['seller_id']);
$sellerVerificationState = get_seller_verification_state($conn, (int)$listing['seller_id']);
$sellerVerificationLabel = seller_verification_public_label($sellerVerificationState);

$messageNotice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_text'])) {
    $messageText = trim($_POST['message_text'] ?? '');

    if ($messageText === '') {
        $messageNotice = '<div class="notice notice--error">Please type a message before sending.</div>';
    } else {
        try {
            $chatId = chat_find_or_create_for_listing($conn, $listingId, $userId);
            chat_add_message($conn, $chatId, $userId, $messageText);

header('Location: messages.php?chat_id=' . $chatId);
            exit;
        } catch (Throwable $e) {
            $messageNotice = '<div class="notice notice--error">' . h($e->getMessage()) . '</div>';
        }
    }
}

$images = [];
$imgStmt = $conn->prepare("
    SELECT image_path, is_primary
    FROM listing_images
    WHERE listing_id = ?
    ORDER BY is_primary DESC, image_id ASC
");
$imgStmt->bind_param('i', $listingId);
$imgStmt->execute();
$imgResult = $imgStmt->get_result();

while ($img = $imgResult->fetch_assoc()) {
    $images[] = $img;
}

$mainImageHtml = '<div class="public-main-photo-placeholder">No photo uploaded</div>';
$thumbnailsHtml = '<div class="public-thumb-placeholder">No photos</div>';

if (count($images) > 0) {
    $firstImage = $images[0]['image_path'];
    $mainImageHtml = '<img data-main-listing-image src="' . h($firstImage) . '" alt="' . h($displayTitle) . '">';

    $thumbnailsHtml = '';
    foreach ($images as $index => $img) {
        $activeClass = $index === 0 ? ' is-active' : '';
        $label = $index === 0 ? 'Main' : 'Photo ' . ($index + 1);

        $thumbnailsHtml .= '
          <button type="button" class="public-thumb' . $activeClass . '" data-gallery-thumb data-image-src="' . h($img['image_path']) . '" data-image-alt="' . h($displayTitle) . '">
            <img src="' . h($img['image_path']) . '" alt="' . h($label) . '">
            <span>' . h($label) . '</span>
          </button>
        ';
    }
}

$header = get_header($conn, 'dashboard');

$price = 'R' . number_format((float)$listing['price'], 2);
$description = nl2br(h($displayDescription));
$plainDescription = trim(preg_replace('/\s+/', ' ', $displayDescription));
$descriptionLength = function_exists('mb_strlen') ? mb_strlen($plainDescription) : strlen($plainDescription);
$descriptionLineCount = substr_count($displayDescription, "\n") + 1;
$descriptionToggle = ($descriptionLength > 260 || $descriptionLineCount > 4)
    ? '<button type="button" class="description-toggle" data-description-toggle data-show-more="' . h(t('Show more')) . '" data-show-less="' . h(t('Show less')) . '" aria-expanded="false">' . h(t('Show more')) . '</button>'
    : '';
$stockLabel = ((int)$listing['quantity'] > 0) ? h($listing['quantity']) . ' ' . t('available') : t('Out of stock');
$statusLabel = translate_dynamic_text(ucfirst((string)$listing['status']), $userLanguage);
$isWishlisted = user_has_wishlist_item($conn, $userId, $listingId);
$wishlistActionValue = $isWishlisted ? 'remove' : 'add';
$wishlistButtonText = $isWishlisted ? t('Remove from wishlist') : t('Add to wishlist');

$sellerStats = get_seller_review_stats($conn, (int)$listing['seller_id']);
$sellerRatingText = seller_rating_text($sellerStats['average'], $sellerStats['count']);

$infoHtml = '
  <div class="info-row"><span>Category</span><strong>' . h(translate_dynamic_text($listing['category_name'], $userLanguage)) . '</strong></div>
  <div class="info-row"><span>Condition</span><strong>' . h(translate_dynamic_text($listing['condition'], $userLanguage)) . '</strong></div>
  <div class="info-row"><span>Location</span><strong>' . h(translate_dynamic_text($listing['location'], $userLanguage)) . '</strong></div>
  <div class="info-row"><span>Seller</span><strong>' . h($listing['store_name']) . '</strong></div>
  <div class="info-row"><span>Seller rating</span><strong>' . h($sellerRatingText) . '</strong></div>
  <div class="info-row"><span>Trust badge</span><strong>' . h(translate_dynamic_text($listing['trust_badge'] ?? 'New seller', $userLanguage)) . '</strong></div>
  <div class="info-row"><span>Verification</span><strong>' . h($sellerVerificationLabel) . '</strong></div>
';

$reviewPage = max(1, (int)($_GET['reviews_page'] ?? 1));
$reviewsPerPage = 10;
$reviewOffset = ($reviewPage - 1) * $reviewsPerPage;

$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM reviews WHERE listing_id = ?");
$countStmt->bind_param('i', $listingId);
$countStmt->execute();
$totalReviews = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$totalReviewPages = max(1, (int)ceil($totalReviews / $reviewsPerPage));
if ($reviewPage > $totalReviewPages) {
    $reviewPage = $totalReviewPages;
    $reviewOffset = ($reviewPage - 1) * $reviewsPerPage;
}

$reviewStats = get_listing_review_stats($conn, $listingId);
$reviewStmt = $conn->prepare("
    SELECT r.rating, r.review_text, r.created_at, u.first_name, u.last_name
    FROM reviews r
    INNER JOIN users u ON u.user_id = r.buyer_id
    WHERE r.listing_id = ?
    ORDER BY r.created_at DESC, r.review_id DESC
    LIMIT ? OFFSET ?
");
$reviewStmt->bind_param('iii', $listingId, $reviewsPerPage, $reviewOffset);
$reviewStmt->execute();
$reviewResult = $reviewStmt->get_result();

$reviewCardsHtml = '';
$reviewIndex = 0;

while ($review = $reviewResult->fetch_assoc()) {
    $reviewIndex++;
    $extraClass = $reviewIndex > 3 ? ' review-card--extra' : '';
    $reviewText = trim((string)($review['review_text'] ?? ''));
    $reviewTextHtml = $reviewText !== ''
        ? '<p data-no-translate>' . nl2br(h($reviewText)) . '</p>'
        : '<p class="section-note">' . h(t('No written review.')) . '</p>';

    $reviewCardsHtml .= '
      <article class="review-card' . $extraClass . '" ' . ($reviewIndex > 3 ? 'data-review-extra' : '') . '>
        <div class="review-card__head">
          <div>
            <strong>' . h(trim($review['first_name'] . ' ' . $review['last_name'])) . '</strong>
            <span>' . h($review['created_at']) . '</span>
          </div>
          ' . review_star_display((float)$review['rating']) . '
        </div>
        ' . $reviewTextHtml . '
      </article>
    ';
}

if ($reviewCardsHtml === '') {
    $reviewCardsHtml = '<div class="empty-state">' . h(t('No reviews have been posted for this listing yet.')) . '</div>';
}

$readMoreButton = $reviewIndex > 3
    ? '<button type="button" class="button button--ghost review-toggle" data-review-toggle data-show-more="' . h(t('Read more reviews')) . '" data-show-less="' . h(t('Show fewer reviews')) . '">' . h(t('Read more reviews')) . '</button>'
    : '';

$reviewPagination = render_review_pagination('listing_view.php?id=' . urlencode((string)$listingId), $reviewPage, $totalReviewPages);

$reviewsSection = '
  <section class="reviews-card" id="reviews">
    <div class="reviews-card__head">
      <div>
        <p class="eyebrow">' . h(t('Ratings and reviews')) . '</p>
        <h2>' . h(t('Customer reviews')) . '</h2>
      </div>
      ' . review_summary_html($reviewStats['average'], $reviewStats['count']) . '
    </div>

    <div class="review-list">
      ' . $reviewCardsHtml . '
    </div>

    <div class="review-actions">
      ' . $readMoreButton . '
      ' . $reviewPagination . '
    </div>
  </section>
';

echo render_template(__DIR__ . '/templates/listing_view.html', [
    'HEADER' => $header,
    'TITLE' => h($displayTitle),
    'PRICE' => h($price),
    'WISHLIST_BUTTON' => '
      <form method="post" action="actions/wishlist.php" class="wishlist-action-form">
        <input type="hidden" name="listing_id" value="' . h($listingId) . '">
        <input type="hidden" name="action" value="' . h($wishlistActionValue) . '">
        <input type="hidden" name="return_to" value="listing_view.php?id=' . h($listingId) . '">
        <button class="button button--ghost wishlist-button" type="submit">♥ ' . h($wishlistButtonText) . '</button>
      </form>
    ',
    'DESCRIPTION' => $description,
    'DESCRIPTION_TOGGLE' => $descriptionToggle,
    'MAIN_IMAGE' => $mainImageHtml,
    'THUMBNAILS' => $thumbnailsHtml,
    'STOCK' => $stockLabel,
    'STATUS' => h($statusLabel),
    'SELLER_NAME' => h($listing['store_name']),
    'INFO' => $infoHtml,
    'REVIEWS_SECTION' => $reviewsSection,
    'MESSAGE_NOTICE' => $messageNotice,
    'LISTING_ID' => h($listingId),
]);
?>
