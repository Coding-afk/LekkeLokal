<?php
/*
 * Example database connection file.
 * Copy this file and rename the copy to db.php when running locally.
 * The real hosted db.php is not included because it contains sensitive credentials.
 */

require_once __DIR__ . '/security.php';

security_apply_request_guards();

$host = 'localhost';
$user = 'root';
$password = '';
$database = 'lekke_lokal';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Database connection failed. Please try again later.');
}

$conn->set_charset('utf8mb4');
?>