<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/email_config.php';

const MFA_VALID_HOURS = 4;
const MFA_CODE_MINUTES = 10;
const LOGIN_LOCKOUT_MINUTES = 15;
const LOGIN_LOCKOUT_MAX_FAILURES = 5;
const PASSWORD_RESET_CODE_MINUTES = 10;

function auth_session_start(): void {
    security_start_session();
}

function csrf_token(): string {
    return security_csrf_token();
}

function csrf_input(): string {
    return security_csrf_input();
}

function csrf_is_valid(?string $token): bool {
    return security_csrf_is_valid($token);
}

function require_valid_csrf(): void {
    security_require_csrf();
}

function register_captcha_word(): string {
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $special = '@#$%&?!';

    $characters = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $special[random_int(0, strlen($special) - 1)],
    ];

    $pool = $upper . $lower . $digits . $special;
    while (count($characters) < 7) {
        $characters[] = $pool[random_int(0, strlen($pool) - 1)];
    }

    shuffle($characters);

    return implode('', $characters);
}

function register_captcha_svg(string $word): string {
    $width = 260;
    $height = 82;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="CAPTCHA security image">';
    $svg .= '<rect width="100%" height="100%" rx="18" fill="#faf7f6"/>';
    $svg .= '<rect x="1" y="1" width="' . ($width - 2) . '" height="' . ($height - 2) . '" rx="18" fill="none" stroke="#cbcac7" stroke-width="2"/>';

    for ($i = 0; $i < 12; $i++) {
        $x1 = random_int(0, $width);
        $y1 = random_int(0, $height);
        $x2 = random_int(0, $width);
        $y2 = random_int(0, $height);
        $opacity = random_int(16, 32) / 100;
        $svg .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#285570" stroke-width="' . random_int(1, 3) . '" opacity="' . $opacity . '"/>';
    }

    for ($i = 0; $i < 42; $i++) {
        $cx = random_int(4, $width - 4);
        $cy = random_int(4, $height - 4);
        $r = random_int(1, 3);
        $opacity = random_int(10, 28) / 100;
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="#333333" opacity="' . $opacity . '"/>';
    }

    $letters = str_split($word);
    $x = 22;
    foreach ($letters as $letter) {
        $y = random_int(48, 60);
        $angle = random_int(-24, 24);
        $size = random_int(27, 34);
        $safeLetter = htmlspecialchars($letter, ENT_QUOTES, 'UTF-8');
        $svg .= '<text x="' . $x . '" y="' . $y . '" transform="rotate(' . $angle . ' ' . $x . ' ' . $y . ')" font-family="Georgia, Times New Roman, serif" font-size="' . $size . '" font-weight="800" fill="#285570" opacity="0.96">' . $safeLetter . '</text>';
        $x += random_int(28, 33);
    }

    $svg .= '<path d="M12 41 C52 16, 92 70, 134 41 S210 16, 248 43" fill="none" stroke="#333333" stroke-width="2" opacity=".22"/>';
    $svg .= '</svg>';

    return '<img class="captcha-image" src="data:image/svg+xml;base64,' . base64_encode($svg) . '" alt="' . h(t('CAPTCHA security image')) . '">';
}

function generate_register_captcha(): string {
    auth_session_start();

    $word = register_captcha_word();

    $_SESSION['register_captcha_hash'] = password_hash($word, PASSWORD_DEFAULT);
    $_SESSION['register_captcha_svg'] = register_captcha_svg($word);
    $_SESSION['register_captcha_created_at'] = time();

    return $_SESSION['register_captcha_svg'];
}

function get_register_captcha_image(): string {
    auth_session_start();

    if (empty($_SESSION['register_captcha_svg'])) {
        return generate_register_captcha();
    }

    return (string)$_SESSION['register_captcha_svg'];
}

function verify_register_captcha(?string $answer): bool {
    auth_session_start();

    $answer = preg_replace('/\s+/', '', (string)$answer);
    $hash = $_SESSION['register_captcha_hash'] ?? '';
    $createdAt = (int)($_SESSION['register_captcha_created_at'] ?? 0);

    unset($_SESSION['register_captcha_hash'], $_SESSION['register_captcha_svg'], $_SESSION['register_captcha_created_at']);

    if ($answer === '' || $hash === '' || $createdAt <= 0) {
        return false;
    }

    if ((time() - $createdAt) > 20 * 60) {
        return false;
    }

    return password_verify($answer, $hash);
}

function password_policy_error(string $password): string {
    if (strlen($password) < 10) {
        return t('Password must be at least 10 characters and include uppercase, lowercase, number, and special character.');
    }

    if (!preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)) {
        return t('Password must be at least 10 characters and include uppercase, lowercase, number, and special character.');
    }

    return '';
}

function client_ip_address(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
}

function login_is_rate_limited(mysqli $conn, string $email): bool {
    $ip = client_ip_address();
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS failures
        FROM login_attempts
        WHERE email = ?
          AND ip_address = ?
          AND succeeded = FALSE
          AND attempted_at >= (NOW() - INTERVAL " . LOGIN_LOCKOUT_MINUTES . " MINUTE)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int)($row['failures'] ?? 0) >= LOGIN_LOCKOUT_MAX_FAILURES;
}

function record_login_attempt(mysqli $conn, string $email, bool $succeeded): void {
    $ip = client_ip_address();
    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address, succeeded) VALUES (?, ?, ?)");
    if (!$stmt) {
        return;
    }

    $successInt = $succeeded ? 1 : 0;
    $stmt->bind_param('ssi', $email, $ip, $successInt);
    $stmt->execute();
}

function ensure_user_security_row(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare("INSERT IGNORE INTO user_security (user_id) VALUES (?)");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
}

function mfa_is_current(mysqli $conn, int $userId): bool {
    ensure_user_security_row($conn, $userId);

    $stmt = $conn->prepare("
        SELECT last_mfa_verified_at
        FROM user_security
        WHERE user_id = ?
          AND last_mfa_verified_at IS NOT NULL
          AND last_mfa_verified_at >= (NOW() - INTERVAL " . MFA_VALID_HOURS . " HOUR)
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

function create_mfa_challenge(mysqli $conn, int $userId): string {
    ensure_user_security_row($conn, $userId);

    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);

    $cleanup = $conn->prepare("
        UPDATE mfa_challenges
        SET is_used = TRUE
        WHERE user_id = ? AND is_used = FALSE
    ");
    if ($cleanup) {
        $cleanup->bind_param('i', $userId);
        $cleanup->execute();
    }

    $stmt = $conn->prepare("
        INSERT INTO mfa_challenges (user_id, otp_hash, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . MFA_CODE_MINUTES . " MINUTE))
    ");
    if (!$stmt) {
        throw new RuntimeException('Could not create MFA challenge.');
    }

    $stmt->bind_param('is', $userId, $hash);
    $stmt->execute();

    return $code;
}

function mask_email(string $email): string {
    if (!str_contains($email, '@')) {
        return $email;
    }

    [$name, $domain] = explode('@', $email, 2);
    $visible = substr($name, 0, 2);
    return $visible . str_repeat('*', max(2, strlen($name) - 2)) . '@' . $domain;
}



function lekke_smtp_configured(): bool {
    return defined('LEKKE_SMTP_ENABLED')
        && LEKKE_SMTP_ENABLED === true
        && defined('LEKKE_SMTP_HOST')
        && defined('LEKKE_SMTP_PORT')
        && defined('LEKKE_SMTP_USERNAME')
        && defined('LEKKE_SMTP_PASSWORD')
        && trim((string)LEKKE_SMTP_HOST) !== ''
        && trim((string)LEKKE_SMTP_USERNAME) !== ''
        && trim((string)LEKKE_SMTP_PASSWORD) !== '';
}

function smtp_read_response($socket): string {
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }

        $response .= $line;

        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

function smtp_expect($socket, array $expectedCodes): string {
    $response = smtp_read_response($socket);
    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }

    return $response;
}

function smtp_command($socket, string $command, array $expectedCodes): string {
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expectedCodes);
}

function smtp_encode_header(string $value): string {
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_escape_body(string $body): string {
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);

    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }

    return implode("\r\n", $lines);
}

function send_security_otp_email_smtp(string $toEmail, string $subject, string $body): bool {
    if (!lekke_smtp_configured()) {
        return false;
    }

    $host = (string)LEKKE_SMTP_HOST;
    $port = (int)LEKKE_SMTP_PORT;
    $encryption = strtolower((string)LEKKE_SMTP_ENCRYPTION);
    $username = (string)LEKKE_SMTP_USERNAME;
    $password = (string)LEKKE_SMTP_PASSWORD;
    $fromEmail = defined('LEKKE_SMTP_FROM_EMAIL') ? (string)LEKKE_SMTP_FROM_EMAIL : $username;
    $fromName = defined('LEKKE_SMTP_FROM_NAME') ? (string)LEKKE_SMTP_FROM_NAME : 'Lekke Lokal';

    $transportHost = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host;
    $socket = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        throw new RuntimeException('Could not connect to SMTP server: ' . $errstr);
    }

    stream_set_timeout($socket, 20);

    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);

        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not enable SMTP TLS encryption.');
            }
            smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);

        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $headers = [
            'From: ' . smtp_encode_header($fromName) . ' <' . $fromEmail . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . smtp_encode_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . smtp_escape_body($body) . "\r\n.";
        smtp_command($socket, $message, [250]);
        smtp_command($socket, 'QUIT', [221]);

        fclose($socket);
        return true;
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
        throw $e;
    }
}


function send_security_otp_email(string $email, string $firstName, string $code, string $purpose, int $validMinutes, string $logFile, string $logField): bool {
    $isReset = $purpose === 'password_reset';
    $subject = $isReset ? 'Lekke Lokal password reset OTP' : 'Lekke Lokal login OTP';
    $actionText = $isReset ? 'password reset' : 'login';

    $body = "Hi " . $firstName . ",

"
          . "Your Lekke Lokal " . $actionText . " OTP is: " . $code . "

"
          . "This code expires in " . $validMinutes . " minutes. "
          . ($isReset
              ? "If you did not request a password reset, you can ignore this email.

"
              : "If you did not try to log in, you can ignore this email.

")
          . "Lekke Lokal";

    $sent = false;
    $method = 'none';
    $error = '';

    try {
        if (lekke_smtp_configured()) {
            $sent = send_security_otp_email_smtp($email, $subject, $body);
            $method = 'smtp';
        }
    } catch (Throwable $e) {
        $error = ' smtp_error=' . str_replace(["
", "
"], ' ', $e->getMessage());
        $sent = false;
        $method = 'smtp_failed';
    }

    if (!$sent && function_exists('mail')) {
        $from = defined('LEKKE_SMTP_FROM_EMAIL') ? (string)LEKKE_SMTP_FROM_EMAIL : 'no-reply@lekke-lokal.local';
        $fromName = defined('LEKKE_SMTP_FROM_NAME') ? (string)LEKKE_SMTP_FROM_NAME : 'Lekke Lokal';

        $headers = "From: " . $fromName . " <" . $from . ">
"
                 . "MIME-Version: 1.0
"
                 . "Content-Type: text/plain; charset=UTF-8
";

        $sent = @mail($email, $subject, $body, $headers);
        $method = $sent ? 'mail' : ($method === 'smtp_failed' ? 'smtp_failed_mail_failed' : 'mail_failed');
    }

    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $logLine = '[' . date('Y-m-d H:i:s') . ']'
    . ' mail_sent=' . ($sent ? 'yes' : 'no')
    . ' method=' . $method
    . PHP_EOL;
    file_put_contents($logDir . '/' . $logFile, $logLine, FILE_APPEND);

    return $sent;
}

function send_mfa_otp_email(string $email, string $firstName, string $code): bool {
    return send_security_otp_email($email, $firstName, $code, 'login', MFA_CODE_MINUTES, 'mfa_otp.log', 'otp');
}

function send_password_reset_otp_email(string $email, string $firstName, string $code): bool {
    return send_security_otp_email($email, $firstName, $code, 'password_reset', PASSWORD_RESET_CODE_MINUTES, 'password_reset_otp.log', 'reset_otp');
}


function start_pending_mfa_session(array $user, string $email): void {
    auth_session_start();
    session_regenerate_id(true);

    $_SESSION['pending_mfa'] = [
        'user_id' => (int)$user['user_id'],
        'first_name' => (string)$user['first_name'],
        'role' => (string)($user['role'] ?? 'user'),
        'email' => $email,
        'created_at' => time(),
        'last_sent_at' => time(),
        'fingerprint' => current_auth_fingerprint(),
    ];
}

function pending_mfa_user(): ?array {
    auth_session_start();

    $pending = $_SESSION['pending_mfa'] ?? null;
    if (!is_array($pending) || empty($pending['user_id']) || empty($pending['email'])) {
        return null;
    }

    if ((time() - (int)($pending['created_at'] ?? 0)) > 30 * 60) {
        unset($_SESSION['pending_mfa']);
        return null;
    }

    if (!hash_equals((string)($pending['fingerprint'] ?? ''), current_auth_fingerprint())) {
        unset($_SESSION['pending_mfa']);
        return null;
    }

    return $pending;
}

function resend_pending_mfa_allowed(): bool {
    $pending = pending_mfa_user();
    if (!$pending) {
        return false;
    }

    return (time() - (int)($pending['last_sent_at'] ?? 0)) >= 60;
}

function mark_pending_mfa_sent_now(): void {
    auth_session_start();
    if (isset($_SESSION['pending_mfa']) && is_array($_SESSION['pending_mfa'])) {
        $_SESSION['pending_mfa']['last_sent_at'] = time();
    }
}

function verify_mfa_otp(mysqli $conn, int $userId, string $code, string &$error = ''): bool {
    $code = trim($code);
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        $error = t('Please enter the 6-digit OTP code.');
        return false;
    }

    $stmt = $conn->prepare("
        SELECT mfa_id, otp_hash, attempts
        FROM mfa_challenges
        WHERE user_id = ?
          AND is_used = FALSE
          AND expires_at >= NOW()
        ORDER BY created_at DESC
        LIMIT 1
    ");

    if (!$stmt) {
        $error = t('Unable to verify OTP right now.');
        return false;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $challenge = $stmt->get_result()->fetch_assoc();

    if (!$challenge) {
        $error = t('Your OTP expired. Please request a new code.');
        return false;
    }

    if ((int)$challenge['attempts'] >= 5) {
        $error = t('Too many OTP attempts. Please request a new code.');
        return false;
    }

    $mfaId = (int)$challenge['mfa_id'];

    if (!password_verify($code, $challenge['otp_hash'])) {
        $attemptStmt = $conn->prepare("UPDATE mfa_challenges SET attempts = attempts + 1 WHERE mfa_id = ?");
        if ($attemptStmt) {
            $attemptStmt->bind_param('i', $mfaId);
            $attemptStmt->execute();
        }

        $error = t('Invalid OTP code.');
        return false;
    }

    $verifyStmt = $conn->prepare("UPDATE mfa_challenges SET is_used = TRUE, verified_at = NOW() WHERE mfa_id = ?");
    if ($verifyStmt) {
        $verifyStmt->bind_param('i', $mfaId);
        $verifyStmt->execute();
    }

    $securityStmt = $conn->prepare("
        INSERT INTO user_security (user_id, last_mfa_verified_at)
        VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE last_mfa_verified_at = NOW()
    ");
    if ($securityStmt) {
        $securityStmt->bind_param('i', $userId);
        $securityStmt->execute();
    }

    return true;
}

function complete_authenticated_session(mysqli $conn, int $userId, string $firstName, string $role): void {
    auth_session_start();
    session_regenerate_id(true);

    unset($_SESSION['pending_mfa']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['user_id'] = $userId;
    $_SESSION['first_name'] = $firstName;
    $_SESSION['role'] = $role;
    $_SESSION['auth_last_activity'] = time();
    $_SESSION['auth_started_at'] = time();
    $_SESSION['auth_fingerprint'] = current_auth_fingerprint();

    get_user_language($conn, $userId);
}

function redirect_after_login(string $role): void {
    if ($role === 'admin') {
        header('Location: admin_dashboard.php');
        exit;
    }

    header('Location: dashboard.php');
    exit;
}



function password_reset_is_rate_limited(mysqli $conn, string $email): bool {
    $ip = client_ip_address();
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS reset_requests
        FROM password_reset_challenges prc
        INNER JOIN users u ON u.user_id = prc.user_id
        WHERE u.email = ?
          AND prc.request_ip = ?
          AND prc.created_at >= (NOW() - INTERVAL 15 MINUTE)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int)($row['reset_requests'] ?? 0) >= 3;
}

function create_password_reset_challenge(mysqli $conn, int $userId): string {
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);

    $cleanup = $conn->prepare("
        UPDATE password_reset_challenges
        SET is_used = TRUE
        WHERE user_id = ? AND is_used = FALSE
    ");
    if ($cleanup) {
        $cleanup->bind_param('i', $userId);
        $cleanup->execute();
    }

    $stmt = $conn->prepare("
        INSERT INTO password_reset_challenges (user_id, otp_hash, expires_at, request_ip)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . PASSWORD_RESET_CODE_MINUTES . " MINUTE), ?)
    ");

    if (!$stmt) {
        throw new RuntimeException('Could not create password reset challenge.');
    }

    $ip = client_ip_address();
    $stmt->bind_param('iss', $userId, $hash, $ip);
    $stmt->execute();

    return $code;
}

function start_pending_password_reset_session(int $userId, string $email): void {
    auth_session_start();
    session_regenerate_id(true);

    $_SESSION['pending_password_reset'] = [
        'user_id' => $userId,
        'email' => $email,
        'created_at' => time(),
        'fingerprint' => current_auth_fingerprint(),
        'verified' => false,
    ];
}

function pending_password_reset_user(bool $requireVerified = false): ?array {
    auth_session_start();

    $pending = $_SESSION['pending_password_reset'] ?? null;
    if (!is_array($pending) || empty($pending['user_id']) || empty($pending['email'])) {
        return null;
    }

    if ((time() - (int)($pending['created_at'] ?? 0)) > 30 * 60) {
        unset($_SESSION['pending_password_reset']);
        return null;
    }

    if (!hash_equals((string)($pending['fingerprint'] ?? ''), current_auth_fingerprint())) {
        unset($_SESSION['pending_password_reset']);
        return null;
    }

    if ($requireVerified && empty($pending['verified'])) {
        return null;
    }

    return $pending;
}

function verify_password_reset_otp(mysqli $conn, int $userId, string $code, string &$error = ''): bool {
    $code = trim($code);
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        $error = t('Please enter the 6-digit reset code.');
        return false;
    }

    $stmt = $conn->prepare("
        SELECT reset_id, otp_hash, attempts
        FROM password_reset_challenges
        WHERE user_id = ?
          AND is_used = FALSE
          AND expires_at >= NOW()
        ORDER BY created_at DESC
        LIMIT 1
    ");

    if (!$stmt) {
        $error = t('Unable to verify reset code right now.');
        return false;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $challenge = $stmt->get_result()->fetch_assoc();

    if (!$challenge) {
        $error = t('Your reset code expired. Please request a new code.');
        return false;
    }

    if ((int)$challenge['attempts'] >= 5) {
        $error = t('Too many reset code attempts. Please request a new code.');
        return false;
    }

    $resetId = (int)$challenge['reset_id'];

    if (!password_verify($code, $challenge['otp_hash'])) {
        $attemptStmt = $conn->prepare("UPDATE password_reset_challenges SET attempts = attempts + 1 WHERE reset_id = ?");
        if ($attemptStmt) {
            $attemptStmt->bind_param('i', $resetId);
            $attemptStmt->execute();
        }

        $error = t('Invalid reset code.');
        return false;
    }

    $verifyStmt = $conn->prepare("UPDATE password_reset_challenges SET verified_at = NOW() WHERE reset_id = ?");
    if ($verifyStmt) {
        $verifyStmt->bind_param('i', $resetId);
        $verifyStmt->execute();
    }

    auth_session_start();
    if (isset($_SESSION['pending_password_reset']) && is_array($_SESSION['pending_password_reset'])) {
        $_SESSION['pending_password_reset']['verified'] = true;
        $_SESSION['pending_password_reset']['verified_at'] = time();
    }

    return true;
}

function complete_password_reset(mysqli $conn, int $userId, string $newPassword): bool {
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();

    $usedStmt = $conn->prepare("
        UPDATE password_reset_challenges
        SET is_used = TRUE
        WHERE user_id = ? AND is_used = FALSE
    ");
    if ($usedStmt) {
        $usedStmt->bind_param('i', $userId);
        $usedStmt->execute();
    }

    $securityStmt = $conn->prepare("
        INSERT INTO user_security (user_id, last_mfa_verified_at)
        VALUES (?, NULL)
        ON DUPLICATE KEY UPDATE last_mfa_verified_at = NULL
    ");
    if ($securityStmt) {
        $securityStmt->bind_param('i', $userId);
        $securityStmt->execute();
    }

    auth_session_start();
    unset($_SESSION['pending_password_reset']);

    return true;
}


function auth_notice(?string $message, string $type = 'error'): string {
    $message = trim((string)$message);
    if ($message === '') {
        return '';
    }

    $class = $type === 'success' ? 'notice notice--success' : 'notice notice--error';
    return '<div class="' . h($class) . '">' . h($message) . '</div>';
}
?>
