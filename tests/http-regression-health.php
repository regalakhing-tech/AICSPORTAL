<?php
declare(strict_types=1);

// Test-only: inert unless the isolated test environment is explicitly selected.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/http-regression-db.php';

if (getenv('AICS_HTTP_TEST_MODE') !== '1' || AICS_DB_NAME !== AICS_HTTP_REGRESSION_DB) {
    http_response_code(404);
    exit;
}
try {
    $connection = aics_db_connect();
    aics_http_regression_require_seed_data($connection);
    $connection->close();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'http_test_mode_present' => true, 'test_db_host_configured' => AICS_DB_HOST !== '', 'test_db_name' => AICS_DB_NAME, 'test_db_user_configured' => AICS_DB_USER !== ''], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"isolated_http_test_database_unavailable"}';
}
