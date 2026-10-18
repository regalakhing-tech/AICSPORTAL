<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/http-regression-db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || getenv('AICS_HTTP_TEST_MODE') !== '1'
    || AICS_DB_NAME !== AICS_HTTP_REGRESSION_DB) {
    http_response_code(404);
    exit;
}

try {
    aics_http_regression_remove_upload_directory((string) ($_POST['reference_no'] ?? ''));
    http_response_code(204);
} catch (Throwable $exception) {
    http_response_code(500);
}
