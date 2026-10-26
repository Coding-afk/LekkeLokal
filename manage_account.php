<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
$userId = ensure_logged_in();
$header = get_header($conn, 'manage_account');
echo render_template(__DIR__ . '/templates/manage_account.html', ['HEADER' => $header]);
?>
