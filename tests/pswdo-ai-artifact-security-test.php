<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/document-ai-artifacts.php';

function artifact_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

// Only exact scalar logical identifiers are accepted.  This covers missing,
// arbitrary filename/path fields, traversal strings, drive paths and UNC input.
$valid = aics_ai_artifact_request(['review_id' => '80', 'page' => '1', 'type' => 'signal']);
artifact_assert($valid === ['review_id' => 80, 'page' => 1, 'type' => 'signal'], 'Valid logical request was rejected.');
artifact_assert(aics_ai_artifact_request(['review_id' => '80', 'page' => '1', 'type' => 'original']) !== null, 'Original page logical request was rejected.');
foreach ([
    ['review_id' => '80', 'page' => '1', 'type' => 'other'],
    ['review_id' => '80', 'page' => '../1', 'type' => 'signal'],
    ['review_id' => '80', 'page' => '..\\1', 'type' => 'signal'],
    ['review_id' => 'C:\\private\\file.png', 'page' => '1', 'type' => 'signal'],
    ['review_id' => '\\\\host\\share', 'page' => '1', 'type' => 'signal'],
    ['review_id' => '80', 'page' => '1', 'type' => 'signal', 'filename' => 'anything.png'],
    ['review_id' => '80', 'page' => '1', 'type' => 'signal', 'path' => '..\\secret'],
] as $invalid) artifact_assert(aics_ai_artifact_request($invalid) === null, 'Unsafe query input was accepted.');

$findings = json_encode(['tamper_detection' => ['status' => 'completed', 'pages' => [
    ['page' => 1, 'artifacts' => ['signal' => ['available' => true], 'threshold' => ['available' => true, 'regions_found' => false]]],
    ['page' => 2, 'artifacts' => ['signal' => ['available' => false], 'threshold' => ['available' => true]]],
]]]);
artifact_assert(aics_ai_artifact_available((string) $findings, 1, 'signal'), 'Available signal metadata was rejected.');
artifact_assert(aics_ai_artifact_available((string) $findings, 1, 'threshold'), 'Available threshold metadata was rejected.');
$originalFindings = json_encode(['tamper_detection' => ['status' => 'completed', 'original_pages' => [['page' => 1, 'available' => true]]]]);
artifact_assert(aics_ai_artifact_available((string) $originalFindings, 1, 'original'), 'Original-page metadata was rejected.');
artifact_assert(!aics_ai_artifact_available((string) $findings, 2, 'signal'), 'Unavailable artifact metadata was accepted.');
artifact_assert(!aics_ai_artifact_available((string) $findings, 3, 'signal'), 'Missing page was accepted.');
artifact_assert(!aics_ai_artifact_available('{}', 1, 'signal'), 'Legacy result without metadata was accepted.');
$supplement = json_encode(['status' => 'completed', 'pages' => [['page' => 1, 'artifacts' => ['signal' => ['available' => true], 'threshold' => ['available' => true]]]]]);
artifact_assert(aics_ai_artifact_available('{}', 1, 'signal', (string) $supplement), 'Supplemental first-page signal metadata was rejected.');
artifact_assert(aics_ai_artifact_available('{"tamper_detection":{"status":"unavailable","error_code":"service_disabled"}}', 1, 'signal', (string) $supplement), 'A stale native failure overrode a successful supplemental artifact.');

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-artifact-test-' . bin2hex(random_bytes(4));
$signalDirectory = $root . DIRECTORY_SEPARATOR . 'review_80' . DIRECTORY_SEPARATOR . 'page_1';
mkdir($signalDirectory, 0700, true);
$png = "\x89PNG\r\n\x1a\n" . 'test';
file_put_contents($signalDirectory . DIRECTORY_SEPARATOR . 'signal-overlay.png', $png);
file_put_contents($signalDirectory . DIRECTORY_SEPARATOR . 'threshold-overlay.png', $png);
file_put_contents($signalDirectory . DIRECTORY_SEPARATOR . 'original-page.png', $png);
try {
    artifact_assert(aics_ai_artifact_file($root, 80, 1, 'signal') !== null, 'Valid signal PNG was not resolved.');
    artifact_assert(aics_ai_artifact_file($root, 80, 1, 'threshold') !== null, 'Valid threshold PNG was not resolved.');
    artifact_assert(aics_ai_artifact_file($root, 80, 1, 'original') !== null, 'Valid original-page PNG was not resolved.');
    artifact_assert(aics_ai_artifact_file($root, 80, 2, 'signal') === null, 'Page one could retrieve a page two file.');
    artifact_assert(aics_ai_artifact_file($root, 81, 1, 'signal') === null, 'One review could retrieve another review file.');
    unlink($signalDirectory . DIRECTORY_SEPARATOR . 'threshold-overlay.png');
    artifact_assert(aics_ai_artifact_file($root, 80, 1, 'threshold') === null, 'Missing physical file was accepted.');
} finally {
    @unlink($signalDirectory . DIRECTORY_SEPARATOR . 'signal-overlay.png');
    @unlink($signalDirectory . DIRECTORY_SEPARATOR . 'original-page.png');
    @rmdir($signalDirectory); @rmdir(dirname($signalDirectory)); @rmdir($root);
}

$endpoint = file_get_contents(__DIR__ . '/../Pswdo/php_files/ai-artifact.php');
artifact_assert(is_string($endpoint) && str_contains($endpoint, "require_once __DIR__ . '/auth-guard.php'"), 'Endpoint does not apply PSWDO authentication.');
artifact_assert(str_contains($endpoint, 'INNER JOIN aics_request_documents'), 'Endpoint does not validate the active document/review relationship.');
artifact_assert(str_contains($endpoint, 'document_ai_review_image_supplements') && str_contains($endpoint, 'supplement_tamper_detection'), 'Endpoint does not resolve supplemental artifacts through the canonical review.');
artifact_assert(str_contains($endpoint, 'aics_ai_artifact_available') && str_contains($endpoint, 'aics_ai_artifact_file'), 'Endpoint does not require metadata before derived file access.');
artifact_assert(str_contains($endpoint, "header('Content-Type: image/png')") && str_contains($endpoint, 'X-Content-Type-Options: nosniff'), 'Safe PNG response headers are incomplete.');
artifact_assert(str_contains($endpoint, 'Cache-Control: private, no-store, max-age=0'), 'Private no-cache response policy is missing.');
foreach (['ai-ensure-current.php', 'processor.py', 'aics_launch_document_ai_processor', 'aics_reprocess_ai_review'] as $forbidden) artifact_assert(!str_contains($endpoint, $forbidden), 'Read-only endpoint contains processing action: ' . $forbidden);
artifact_assert(!str_contains($endpoint, 'test_outputs') && !str_contains($endpoint, 'AICS-Development') && !str_contains($endpoint, 'aics_private'), 'Endpoint source exposes artifact filesystem locations.');

echo "PSWDO private AI artifact security test passed.\n";
