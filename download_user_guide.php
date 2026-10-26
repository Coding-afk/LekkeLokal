<?php

$filePath = __DIR__ . '/downloads/Lekke-Lokal-User-Guide.pdf';

if (!is_file($filePath)) {
    http_response_code(404);
    exit('The user guide could not be found.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Lekke-Lokal-User-Guide.pdf"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($filePath);
exit;