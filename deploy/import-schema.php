<?php
// One-time database setup on Railway: imports database/AICSdb.sql into MariaDB over the
// private network, so the database never needs a public address. Safe to re-run.
// Usage: railway ssh --service AICSPORTAL -- php /var/www/html/AICS/AICS/deploy/import-schema.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(
    getenv('AICS_DB_HOST') ?: 'localhost',
    getenv('AICS_DB_USER') ?: 'root',
    (string) getenv('AICS_DB_PASS'),
    '',
    (int) (getenv('AICS_DB_PORT') ?: 3306)
);
$db->set_charset('utf8mb4');
$db->multi_query((string) file_get_contents(__DIR__ . '/../database/AICSdb.sql'));
$statements = 0;
do {
    if ($result = $db->store_result()) {
        $result->free();
    }
    $statements++;
} while ($db->more_results() && $db->next_result());

$tables = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'AICSdb'")->fetch_row()[0];
echo "Imported database/AICSdb.sql: {$statements} statements, {$tables} tables in AICSdb.\n";
