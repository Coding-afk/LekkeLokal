<?php
require_once __DIR__ . '/includes/security.php';
security_start_session();
session_unset();
session_destroy();
header("Location: index.php");
exit;
?>
