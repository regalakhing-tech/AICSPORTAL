<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/applicant-id-verification.php';
require_once __DIR__ . '/../../includes/secure-document-response.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');

if (!aics_is_logged_in_applicat()) {
    http_response_code(401);
    exit('Unauthorized.');
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$side = (string) ($_GET['side'] ?? '');
if ($accountId <= 0 || !in_array($side, ['front', 'back'], true)) {
    http_response_code(400);
    exit('Invalid ID photo selection.');
}

try {
    $connection = aics_db_connect();
    try {
        $verification = aics_id_verification_for_account($connection, $accountId);
    } finally {
        $connection->close();
    }
    $file = is_array($verification) ? aics_id_photo_path_for_account($accountId, $verification, $side) : null;
    if ($file === null) {
        http_response_code(404);
        exit('ID photo not found.');
    }
    aics_serve_document_file($file, aics_id_account_dir($accountId), 'Sign-up ID ' . ucfirst($side));
} catch (Throwable $exception) {
    error_log('Applicant ID photo preview failed.');
    http_response_code(500);
    exit('Unable to preview the ID photo.');
}
