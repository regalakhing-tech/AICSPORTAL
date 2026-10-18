<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

if (!function_exists('aics_applicant_notification_visibility_sql')) {
    throw new RuntimeException('Applicant notification visibility rule is missing.');
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE notification_samples (id INTEGER PRIMARY KEY, channel TEXT)');
$database->exec("INSERT INTO notification_samples (id, channel) VALUES
    (1, 'applicant_sms'),
    (2, 'mswdo_notification'),
    (3, 'general'),
    (4, NULL)");

$visibility = aics_applicant_notification_visibility_sql();
$query = $database->query("SELECT n.id FROM notification_samples n WHERE {$visibility} ORDER BY n.id");
$visibleIds = $query->fetchAll(PDO::FETCH_COLUMN);
if (array_map('intval', $visibleIds) !== [1, 3, 4]) {
    throw new RuntimeException('Applicant notices did not exclude MSWDO simulation while retaining regular and legacy notices.');
}

echo "Applicant notification visibility test passed.\n";
