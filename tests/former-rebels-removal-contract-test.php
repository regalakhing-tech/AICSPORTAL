<?php
declare(strict_types=1);

function removal_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$runtimeFiles = [
    'Applicants/blank-gis.php',
    'Applicants/js files/applicant-pages.js',
    'Applicants/php_files/application-status.php',
    'Applicants/php_files/cancel-request.php',
    'Applicants/php_files/documents.php',
    'Applicants/php_files/overview-dashboard.php',
    'Applicants/php_files/request-export.php',
    'Applicants/php_files/save-request.php',
    'Applicants/php_files/submit-request.php',
    'Pswdo/js files/map-analytics.js',
    'Pswdo/php_files/prescreening-queue.php',
    'Pswdo/php_files/print-gis.php',
    'Pswdo/php_files/pswdo-data-lib.php',
    'Pswdo/php_files/release-scheduling.php',
    'chatbot/aics-chatbot-lib.php',
    'includes/geo-analytics.php',
];
foreach ($runtimeFiles as $relativePath) {
    $source = file_get_contents(__DIR__ . '/../' . $relativePath);
    removal_assert(is_string($source), 'Unable to read runtime source: ' . $relativePath);
    removal_assert(preg_match('/former[ _-]rebels?/i', $source) !== 1, 'Former Rebels remains in active runtime source: ' . $relativePath);
}
echo "Former Rebels active-runtime removal contract passed.\n";
