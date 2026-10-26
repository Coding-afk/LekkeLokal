<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/admin_helpers.php';

ensure_admin($conn);

echo render_template(__DIR__ . '/templates/admin_dashboard.html', [
    'ADMIN_NAME' => h($_SESSION['first_name'] ?? 'Admin'),
]);
?>
