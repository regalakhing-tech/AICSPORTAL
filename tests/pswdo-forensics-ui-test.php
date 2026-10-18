<?php
declare(strict_types=1);
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
function forensics_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$raw = json_encode(['tamper_detection' => [
    'status' => 'completed', 'decision' => 'review', 'review_basis' => 'corroborated_visual_evidence',
    'indicators' => [['type' => 'copy_move_region', 'page' => 1, 'box_2d' => [100, 120, 220, 320],
                      'source_page' => 2, 'source_box_2d' => [400, 420, 520, 620], 'confidence_band' => 'moderate',
                      'reason' => 'Compare the repeated area.', 'source' => 'local_visual']],
    'providers' => ['local_visual' => ['status' => 'completed'], 'qr' => ['status' => 'completed']],
    'original_pages' => [['page' => 1, 'available' => true]],
    'auxiliary' => ['file' => ['editing_software' => 'Image editor', 'pdf_signature' => ['state' => 'unsigned']],
                    'qr' => [['page' => 1, 'content_preview' => 'https://example.invalid/check',
                              'verification' => 'not_authenticated']]],
]]);
$analysis = pswdo_tamper_analysis_json((string) $raw);
forensics_assert(($analysis['indicators'][0]['box_2d'] ?? null) === [100, 120, 220, 320], 'Evidence box was lost.');
forensics_assert(($analysis['indicators'][0]['source_page'] ?? null) === 2, 'Cross-page source was lost.');
forensics_assert(($analysis['original_pages'][0]['available'] ?? false) === true, 'Original page metadata was lost.');
forensics_assert(($analysis['auxiliary']['qr'][0]['verification'] ?? '') === 'not_authenticated', 'QR authentication limit was lost.');
$signature = pswdo_tamper_analysis_json((string) json_encode(['tamper_detection' => [
    'status' => 'completed', 'decision' => 'review', 'review_basis' => 'cryptographic_signature_problem',
    'indicators' => [['type' => 'invalid_pdf_signature', 'confidence_band' => 'high',
                      'reason' => 'The signed bytes failed cryptographic verification.']],
    'auxiliary' => ['file' => ['pdf_signature' => ['state' => 'invalid_signature']]],
]]));
forensics_assert(($signature['label'] ?? '') === 'PDF signature requires staff review', 'Signature warning label was lost.');
forensics_assert(($signature['auxiliary']['file']['pdf_signature']['state'] ?? '') === 'invalid_signature', 'Signature state was lost.');
$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
forensics_assert(is_string($page) && str_contains($page, 'View marked areas'), 'Evidence viewer is not reachable.');
forensics_assert(is_string($script) && str_contains($script, 'ai-evidence-box'), 'Evidence boxes are not rendered.');
echo "PSWDO forensic evidence UI test passed.\n";
