<?php
/*
 * Lekke Lokal security helpers.
 * Defensive controls added for session hardening, CSRF protection,
 * secure headers, basic request throttling, safe redirects, and upload validation.
 */

const SECURITY_RATE_LIMIT_GLOBAL_MAX = 300;
const SECURITY_RATE_LIMIT_GLOBAL_WINDOW = 300;
const SECURITY_RATE_LIMIT_API_MAX = 90;
const SECURITY_RATE_LIMIT_API_WINDOW = 60;
const SECURITY_RATE_LIMIT_AUTH_MAX = 40;
const SECURITY_RATE_LIMIT_AUTH_WINDOW = 900;

function security_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $forwardedProto === 'https';
}

function security_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $secure = security_is_https();
    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('LEKKELOKAL_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    $now = time();
    $lastRegenerated = (int)($_SESSION['last_session_regeneration'] ?? 0);
    if ($lastRegenerated <= 0) {
        $_SESSION['last_session_regeneration'] = $now;
    } elseif (($now - $lastRegenerated) > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_session_regeneration'] = $now;
    }
}

function security_send_headers(): void {
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), geolocation=(), microphone=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');

    // Safe baseline CSP that does not break the current inline JavaScript used by the prototype.
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'");

    if (security_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function security_client_ip(): string {
    return preg_replace('/[^0-9a-fA-F:\.]/', '', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function security_rate_limit_dir(): string {
    $dir = dirname(__DIR__) . '/logs/rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function security_rate_limit(string $bucket, int $maxRequests, int $windowSeconds): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $dir = security_rate_limit_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    $key = hash('sha256', security_client_ip() . '|' . $bucket);
    $file = $dir . '/' . $key . '.json';
    $now = time();
    $events = [];

    if (is_file($file)) {
        $raw = file_get_contents($file);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $events = array_values(array_filter($decoded, static fn($ts) => is_int($ts) && ($now - $ts) <= $windowSeconds));
        }
    }

    $events[] = $now;
    file_put_contents($file, json_encode($events), LOCK_EX);

    if (count($events) > $maxRequests) {
        http_response_code(429);
        header('Retry-After: ' . max(30, $windowSeconds));
        die('Too many requests. Please wait a moment and try again.');
    }
}

function security_apply_request_guards(): void {
    security_send_headers();

    $uri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));
    if (str_contains($uri, '/api/')) {
        security_rate_limit('api', SECURITY_RATE_LIMIT_API_MAX, SECURITY_RATE_LIMIT_API_WINDOW);
    } elseif (preg_match('~/(login|register|forgot_password|mfa_verify|resend_mfa)~', $uri)) {
        security_rate_limit('auth', SECURITY_RATE_LIMIT_AUTH_MAX, SECURITY_RATE_LIMIT_AUTH_WINDOW);
    } else {
        security_rate_limit('global', SECURITY_RATE_LIMIT_GLOBAL_MAX, SECURITY_RATE_LIMIT_GLOBAL_WINDOW);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        security_require_csrf();
    }
}

function security_csrf_token(): string {
    security_start_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function security_csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function security_csrf_is_valid(?string $token): bool {
    security_start_session();

    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function security_require_csrf(): void {
    if (!security_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Security token expired. Please refresh the page and try again.');
    }
}

function security_inject_csrf_into_post_forms(string $html): string {
    if (stripos($html, '<form') === false || stripos($html, 'method="post"') === false && stripos($html, "method='post'") === false) {
        return $html;
    }

    return preg_replace_callback('/<form\b[^>]*>/i', static function ($matches) {
        $formTag = $matches[0];

        if (!preg_match('/method\s*=\s*["\']?post["\']?/i', $formTag)) {
            return $formTag;
        }

        return $formTag . "\n          " . security_csrf_input();
    }, $html);
}

function security_safe_redirect_target(string $target, string $fallback = 'dashboard.php'): string {
    $target = trim($target);

    if ($target === '' || preg_match('/^https?:\/\//i', $target) || str_contains($target, '\\') || str_contains($target, "\0")) {
        return $fallback;
    }

    $target = ltrim($target, '/');
    $parts = parse_url($target);

    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || str_contains($path, '..')) {
        return $fallback;
    }

    return $target;
}

function security_upload_error_message(int $errorCode): string {
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        default => 'The uploaded file could not be processed.',
    };
}

function security_upload_policies(): array {
    return [
        'listing_image' => [
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'mimes' => [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ],
            'max_bytes' => 5 * 1024 * 1024,
            'image' => true,
            'pdf' => false,
        ],
        'kyc_document' => [
            'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
            'mimes' => [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ],
            'max_bytes' => 10 * 1024 * 1024,
            'image' => true,
            'pdf' => true,
        ],
        'kyc_selfie' => [
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'mimes' => [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ],
            'max_bytes' => 5 * 1024 * 1024,
            'image' => true,
            'pdf' => false,
        ],
    ];
}

function security_file_mime(string $path): string {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return (string)$finfo->file($path);
}

function security_validate_uploaded_file(array $file, string $policyName): array {
    $policies = security_upload_policies();
    if (!isset($policies[$policyName])) {
        return ['ok' => false, 'error' => 'Invalid upload policy.'];
    }

    $policy = $policies[$policyName];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => security_upload_error_message($error)];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid uploaded file.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > (int)$policy['max_bytes']) {
        return ['ok' => false, 'error' => 'The file is too large or empty.'];
    }

    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }

    if (!in_array($ext, $policy['extensions'], true)) {
        return ['ok' => false, 'error' => 'This file extension is not allowed.'];
    }

    $mime = security_file_mime($tmp);
    if (!isset($policy['mimes'][$mime])) {
        return ['ok' => false, 'error' => 'This file type is not allowed.'];
    }

    $safeExt = $policy['mimes'][$mime];

    if ($safeExt !== 'pdf' && !empty($policy['image'])) {
        $imageInfo = @getimagesize($tmp);
        if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
            return ['ok' => false, 'error' => 'The image file is not valid.'];
        }

        if ($imageInfo[0] > 8000 || $imageInfo[1] > 8000) {
            return ['ok' => false, 'error' => 'The image dimensions are too large.'];
        }
        if ($policyName === 'listing_image' &&
            ($imageInfo[0] < 600 || $imageInfo[1] < 600)) {
            return ['ok' => false,'error' => 'Listing images must be at least 600 × 600 pixels.'];
        }
    }

    if ($safeExt === 'pdf') {
        if (empty($policy['pdf'])) {
            return ['ok' => false, 'error' => 'PDF files are not allowed here.'];
        }

        $handle = fopen($tmp, 'rb');
        $signature = $handle ? fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($signature !== '%PDF-') {
            return ['ok' => false, 'error' => 'The PDF file is not valid.'];
        }
    }

    return [
        'ok' => true,
        'tmp_name' => $tmp,
        'extension' => $safeExt,
        'mime' => $mime,
        'size' => $size,
    ];
}


function security_path_is_inside(string $path, string $baseDir): bool {
    $baseReal = realpath($baseDir);
    $targetReal = realpath($path);

    if ($baseReal === false || $targetReal === false) {
        return false;
    }

    $baseReal = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($targetReal, $baseReal);
}


function security_safe_upload_filename(string $prefix, string $extension): string {
    $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix);
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(12)) . '.' . strtolower($extension);
}

function security_validate_image_binary(string $binary, string $policyName = 'kyc_selfie'): array {
    $policies = security_upload_policies();
    $policy = $policies[$policyName] ?? $policies['kyc_selfie'];

    if ($binary === '' || strlen($binary) > (int)$policy['max_bytes']) {
        return ['ok' => false, 'error' => 'The image is too large or empty.'];
    }

    $imageInfo = @getimagesizefromstring($binary);
    if ($imageInfo === false || empty($imageInfo['mime']) || empty($policy['mimes'][$imageInfo['mime']])) {
        return ['ok' => false, 'error' => 'The captured image is not valid.'];
    }

    if (($imageInfo[0] ?? 0) > 8000 || ($imageInfo[1] ?? 0) > 8000) {
        return ['ok' => false, 'error' => 'The captured image dimensions are too large.'];
    }

    return [
        'ok' => true,
        'extension' => $policy['mimes'][$imageInfo['mime']],
        'mime' => $imageInfo['mime'],
    ];
}
?>
