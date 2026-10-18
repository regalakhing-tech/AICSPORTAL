<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI execution only.\n"); }
require_once __DIR__ . '/../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../includes/document-ai-reviews.php';
try {
    $db = aics_db_connect(); aics_ensure_ai_review_schema($db); $db->close();
    $runtime = aics_ai_runtime_configuration();
    // The maintenance CLI does not inherit Apache SetEnv reliably.  Mirror
    // only the existing local ADCD runtime configuration for this child.
    putenv('AICS_ADCD_ENABLED=' . $runtime['adcd_enabled']);
    putenv('AICS_ADCD_SERVICE_URL=' . $runtime['adcd_service_url']);
    putenv('ADCD_CONNECT_TIMEOUT=' . $runtime['adcd_connect_timeout']);
    putenv('ADCD_READ_TIMEOUT=' . $runtime['adcd_read_timeout']);
    // Existing local service staging root for this deployment. It is never
    // stored in the database or returned to a browser.
    if (trim((string) getenv('AICS_ADCD_OUTPUT_ROOT')) === '') {
        putenv('AICS_ADCD_OUTPUT_ROOT=C:\\Users\\brian\\Documents\\AICS-Development\\ADCD-Net\\test_outputs\\service');
    }
    $python = $runtime['python']; $script = dirname(__DIR__) . '/ai_processor/legacy_image_enrichment.py';
    if (!is_file($python) || !is_file($script)) throw new RuntimeException('Legacy image enrichment runtime is unavailable.');
    $allowed = ['--dry-run' => true, '--execute' => true, '--process' => true, '--repair-artifacts' => true];
    $args = array_slice($argv, 1); foreach ($args as $arg) if (!isset($allowed[$arg]) && preg_match('/^--limit=[1-9][0-9]*$/', $arg) !== 1) throw new InvalidArgumentException('Unsupported argument.');
    $command = escapeshellarg($python) . ' -m ai_processor.legacy_image_enrichment ' . implode(' ', array_map('escapeshellarg', $args));
    passthru($command, $code); exit($code);
} catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage() . PHP_EOL); exit(1); }
