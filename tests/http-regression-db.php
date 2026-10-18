<?php
declare(strict_types=1);

/**
 * Test-only isolated database bootstrap for fixture-producing HTTP regressions.
 * It replays the checked-in schema and its static seed statements; it never
 * reads records from the development database.
 */
const AICS_HTTP_REGRESSION_DB = 'aics_http_regression';

function aics_http_regression_require_seed_data(mysqli $connection): void
{
    if (getenv('AICS_HTTP_TEST_MODE') !== '1' || AICS_DB_NAME !== AICS_HTTP_REGRESSION_DB) {
        throw new RuntimeException('Refusing to run database test fixtures outside the isolated regression database.');
    }
    $identity = $connection->query('SELECT DATABASE() AS database_name')?->fetch_assoc();
    if (($identity['database_name'] ?? '') !== AICS_HTTP_REGRESSION_DB) {
        throw new RuntimeException('Refusing to run database test fixtures outside the isolated regression database.');
    }
    $required = [
        'aics_roles' => "role_code IN ('applicat', 'pswdo')",
        'aics_account_statuses' => "status_code = 'active'",
        'aics_assistance_types' => "assistance_code IN ('medical', 'special_cases')",
        'aics_document_types' => "document_code IN ('valid_id', 'med_cert', 'billing_statement', 'report_police_med')",
        'aics_request_statuses' => "status_code IN ('pending_review', 'cancelled')",
    ];

    foreach ($required as $table => $where) {
        $result = $connection->query("SELECT COUNT(*) AS total FROM {$table} WHERE {$where}");
        $count = (int) ($result?->fetch_assoc()['total'] ?? 0);
        $minimum = str_contains($where, ' IN ') ? substr_count($where, "'") / 2 : 1;
        if ($count < $minimum) {
            throw new RuntimeException('HTTP regression database is missing required reference seed data.');
        }
    }
}

function aics_http_regression_create_database(): void
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $admin = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
    $admin->set_charset('utf8mb4');
    $admin->query('DROP DATABASE IF EXISTS `' . AICS_HTTP_REGRESSION_DB . '`');
    $admin->query('CREATE DATABASE `' . AICS_HTTP_REGRESSION_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->close();

    $schemaPath = __DIR__ . '/../database/AICSdb.sql';
    $schema = file_get_contents($schemaPath);
    if (!is_string($schema)) {
        throw new RuntimeException('Unable to read the canonical database schema.');
    }
    $schema = preg_replace('/^\s*CREATE DATABASE[^;]+;\s*USE\s+`?AICSdb`?;\s*/i', '', $schema, 1, $replacements);
    if (!is_string($schema) || $replacements !== 1) {
        throw new RuntimeException('Canonical database schema does not have the expected database header.');
    }

    $connection = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, AICS_HTTP_REGRESSION_DB, AICS_DB_PORT);
    $connection->set_charset('utf8mb4');
    if (!$connection->multi_query($schema)) {
        throw new RuntimeException('Unable to initialize the HTTP regression database.');
    }
    do {
        if ($result = $connection->store_result()) {
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());
    if ($connection->errno !== 0) {
        throw new RuntimeException('Unable to initialize the HTTP regression database: ' . $connection->error);
    }
    aics_http_regression_require_seed_data($connection);
    $connection->close();
}

function aics_http_regression_drop_database(): void
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $admin = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
    $admin->query('DROP DATABASE IF EXISTS `' . AICS_HTTP_REGRESSION_DB . '`');
    $admin->close();
}

/** Removes only a validated, test-generated request upload directory. */
function aics_http_regression_remove_upload_directory(string $referenceNo): void
{
    if (preg_match('/^AICS-\d{4}-[A-F0-9]{6}$/', $referenceNo) !== 1) {
        return;
    }
    $uploadsRoot = realpath(__DIR__ . '/../Applicants/uploads');
    $directory = __DIR__ . '/../Applicants/uploads/' . $referenceNo;
    if ($uploadsRoot === false || realpath(dirname($directory)) !== $uploadsRoot || !is_dir($directory)) {
        return;
    }

    // The normal CLI runner cannot delete files created by an elevated Apache
    // process on Windows. Ask the test-only alias to perform the exact same
    // guarded deletion under that account instead.
    $baseUrl = rtrim((string) getenv('AICS_HTTP_BASE_URL'), '/');
    if (PHP_SAPI === 'cli' && $baseUrl !== '') {
        $cleanupUrl = dirname($baseUrl) . '/tests/http-regression-upload-cleanup.php';
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close",
            'content' => http_build_query(['reference_no' => $referenceNo], '', '&', PHP_QUERY_RFC3986),
            'ignore_errors' => true,
            'timeout' => 20,
        ]]);
        file_get_contents($cleanupUrl, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s204\s/', $statusLine) === 1) {
            return;
        }
        throw new RuntimeException('HTTP regression upload cleanup endpoint did not confirm deletion: ' . $referenceNo);
    }

    // Detached document workers can briefly keep a just-submitted PDF open
    // on Windows. Retry only this already-validated fixture directory.
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        foreach ($entries as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if ((scandir($directory) ?: []) === ['.', '..'] && @rmdir($directory)) {
            return;
        }
        usleep(250000);
    }
    throw new RuntimeException('Unable to remove HTTP regression upload fixture: ' . $referenceNo);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require_once __DIR__ . '/../config/database.php';
    $command = $argv[1] ?? 'create';
    if ($command === 'create') {
        aics_http_regression_create_database();
        echo "Isolated HTTP regression database created and seeded.\n";
    } elseif ($command === 'check') {
        $connection = aics_db_connect();
        aics_http_regression_require_seed_data($connection);
        $connection->close();
        echo "Isolated HTTP regression database seed data verified.\n";
    } elseif ($command === 'drop') {
        aics_http_regression_drop_database();
        echo "Isolated HTTP regression database dropped.\n";
    } else {
        throw new RuntimeException('Usage: php tests/http-regression-db.php [create|check|drop]');
    }
}
