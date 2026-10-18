<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function retired_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$database = 'aics_retired_assistance_' . strtolower(dechex((int) getmypid()) . '_' . bin2hex(random_bytes(3)));
$server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
try {
    $server->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $database, AICS_DB_PORT);
    $db->query('CREATE TABLE aics_requests (id INT UNSIGNED PRIMARY KEY, assistance_types_json JSON NOT NULL)');
    $db->query('CREATE TABLE aics_assistance_types (id INT UNSIGNED PRIMARY KEY, assistance_code VARCHAR(60) NOT NULL UNIQUE, assistance_name VARCHAR(120) NOT NULL, sort_order INT NOT NULL)');
    $db->query('CREATE TABLE aics_request_assistance (request_id INT UNSIGNED NOT NULL, assistance_type_id INT UNSIGNED NOT NULL, PRIMARY KEY(request_id, assistance_type_id))');
    $db->query('CREATE TABLE aics_document_types (id INT UNSIGNED PRIMARY KEY, document_code VARCHAR(60) NOT NULL UNIQUE)');
    $db->query('CREATE TABLE aics_assistance_required_documents (assistance_type_id INT UNSIGNED NOT NULL, document_type_id INT UNSIGNED NOT NULL, sort_order INT NOT NULL, PRIMARY KEY(assistance_type_id, document_type_id))');
    $db->query("INSERT INTO aics_assistance_types VALUES (1,'special_cases','Special Cases',40),(2,'former_rebels','Former Rebels',60),(3,'former_rebel','Former Rebel',61),(4,'high_inflation_affected','Persons Affected by High Inflation Rate',70)");
    $db->query("INSERT INTO aics_document_types VALUES (98,'valid_id'),(99,'crisis_situation_cert')");
    $db->query("INSERT INTO aics_requests VALUES (10,'[\"former_rebels\"]'),(11,'[\"former_rebel\"]'),(12,'[\"special_cases\"]')");
    $db->query('INSERT INTO aics_request_assistance VALUES (10,2),(11,3),(12,1)');
    $db->query('INSERT INTO aics_assistance_required_documents VALUES (2,99,1),(3,99,1),(4,98,1),(4,99,2)');

    pswdo_migrate_removed_assistance_types($db);

    retired_assert((int) ($db->query("SELECT COUNT(*) AS total FROM aics_assistance_types WHERE assistance_code IN ('former_rebels','former_rebel')")->fetch_assoc()['total'] ?? -1) === 0, 'Retired lookup categories were not removed.');
    retired_assert((int) ($db->query('SELECT COUNT(*) AS total FROM aics_request_assistance WHERE assistance_type_id = 1')->fetch_assoc()['total'] ?? -1) === 3, 'Historical requests were not reclassified as Special Cases.');
    retired_assert((int) ($db->query('SELECT COUNT(*) AS total FROM aics_assistance_required_documents WHERE assistance_type_id IN (2,3)')->fetch_assoc()['total'] ?? -1) === 0, 'Retired category requirements were left behind.');
    retired_assert((int) ($db->query('SELECT COUNT(*) AS total FROM aics_assistance_required_documents WHERE assistance_type_id = 4 AND document_type_id = 98')->fetch_assoc()['total'] ?? -1) === 1, 'High Inflation lost its Valid ID requirement.');
    retired_assert((int) ($db->query('SELECT COUNT(*) AS total FROM aics_assistance_required_documents WHERE assistance_type_id = 4 AND document_type_id <> 98')->fetch_assoc()['total'] ?? -1) === 0, 'High Inflation still requires a document other than Valid ID.');
    $rows = $db->query('SELECT assistance_types_json FROM aics_requests ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) retired_assert(json_decode((string) $row['assistance_types_json'], true) === ['special_cases'], 'Historical JSON category was not reclassified.');

    pswdo_migrate_removed_assistance_types($db);
    retired_assert((int) ($db->query('SELECT COUNT(*) AS total FROM aics_request_assistance')->fetch_assoc()['total'] ?? -1) === 3, 'Retired-category migration is not idempotent.');
    $db->close();
    echo "Retired assistance migration test passed.\n";
} finally {
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $server->close();
}
