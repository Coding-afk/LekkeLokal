<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
require 'includes/db.php';
require 'includes/helpers.php';
require 'includes/admin_helpers.php';

ensure_admin($conn);

$tableCards = '';
foreach (admin_tables() as $tableName => $label) {
    $tableCards .= '
      <a class="admin-table-button" href="admin_table.php?table=' . h($tableName) . '">
        <span>' . h($label) . '</span>
        <small>' . h($tableName) . '</small>
      </a>
    ';
}

echo render_template(__DIR__ . '/templates/admin_tables.html', [
    'TABLE_CARDS' => $tableCards,
    'ADMIN_NAME' => h($_SESSION['first_name'] ?? 'Admin'),
]);
?>
