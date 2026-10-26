<?php
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$target = 'messages.php' . ($queryString !== '' ? '?' . $queryString : '');
header('Location: ' . $target);
exit;
?>