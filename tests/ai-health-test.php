<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/document-ai-reviews.php';
function health_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'available', 'worker' => 'available', 'tesseract' => 'available', 'poppler' => 'available', 'adcd' => 'available']) === 'operational', 'All available components must be operational.');
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'unavailable', 'worker' => 'available', 'tesseract' => 'available', 'poppler' => 'available']) === 'unavailable', 'Python outage must be unavailable.');
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'available', 'worker' => 'available', 'tesseract' => 'unavailable', 'poppler' => 'available']) === 'unavailable', 'Tesseract outage must be unavailable.');
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'available', 'worker' => 'available', 'tesseract' => 'available', 'poppler' => 'unavailable']) === 'unavailable', 'Poppler outage must be unavailable because PDF processing is required.');
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'available', 'worker' => 'available', 'tesseract' => 'available', 'poppler' => 'available', 'adcd' => 'available', 'recent_failures' => 'warning']) === 'degraded', 'Non-critical warning must be degraded.');
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'available', 'worker' => 'available', 'tesseract' => 'available', 'poppler' => 'available', 'adcd' => 'unavailable']) === 'degraded', 'ADCD-only outage must be degraded because staff can continue with OCR and the original document.');
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'available', 'worker' => 'available', 'tesseract' => 'available', 'poppler' => 'available', 'adcd' => 'disabled']) === 'operational', 'Explicitly disabled optional ADCD must not degrade core AI health.');
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'available', 'worker' => 'available', 'tesseract' => 'available', 'poppler' => 'available', 'adcd' => 'disabled', 'external_ai' => 'disabled']) === 'operational', 'Explicitly disabled external AI must not degrade core AI health.');
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'available', 'worker' => 'available', 'tesseract' => 'available', 'poppler' => 'available', 'adcd' => 'disabled', 'external_ai' => 'configured']) === 'operational', 'Configured external AI must preserve operational health.');
health_assert(aics_ai_health_status_from_components(['database' => 'available', 'python' => 'available', 'worker' => 'available', 'tesseract' => 'available', 'poppler' => 'available', 'adcd' => 'disabled', 'external_ai' => 'unavailable']) === 'degraded', 'External AI outage must degrade but not disable local verification.');
$previousAdcdEnabled = getenv('AICS_ADCD_ENABLED');
$previousExternalAiEnabled = getenv('AICS_EXTERNAL_AI_ENABLED');
putenv('AICS_ADCD_ENABLED');
putenv('AICS_EXTERNAL_AI_ENABLED=0');
health_assert(aics_ai_runtime_configuration()['adcd_enabled'] === '0', 'Missing ADCD configuration must default to disabled until the optional service is configured.');
putenv('AICS_ADCD_ENABLED=0');
$disabledAdcdHealth = aics_ai_environment_health(false);
health_assert(($disabledAdcdHealth['components']['adcd'] ?? '') === 'disabled', 'Environment health must report intentionally disabled ADCD separately from an outage.');
health_assert(($disabledAdcdHealth['components']['external_ai'] ?? '') === 'disabled', 'Environment health must read the privacy-safe Python provider configuration probe.');
if ($previousAdcdEnabled === false) putenv('AICS_ADCD_ENABLED'); else putenv('AICS_ADCD_ENABLED=' . $previousAdcdEnabled);
if ($previousExternalAiEnabled === false) putenv('AICS_EXTERNAL_AI_ENABLED'); else putenv('AICS_EXTERNAL_AI_ENABLED=' . $previousExternalAiEnabled);
$launcher = file_get_contents(__DIR__ . '/../includes/document-ai-reviews.php');
health_assert(is_string($launcher) && str_contains($launcher, "'launch-' . \$documentId") && !str_contains($launcher, "'processor.log';"), 'Concurrent worker dispatch must not redirect through a shared processor log.');
// AI health is checked in the background only (deploy log); PSWDO pages show no badge.
$pswdoFrontend = (string) file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js') . (string) file_get_contents(__DIR__ . '/../Pswdo/js files/ai-request-poller.js')
    . (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php') . (string) file_get_contents(__DIR__ . '/../Pswdo/css files/pswdo-pages.css');
health_assert(!str_contains($pswdoFrontend, 'ai-health-indicator') && !str_contains($pswdoFrontend, 'data-ai-health-url') && !str_contains($pswdoFrontend, 'AI Verification &mdash;'), 'PSWDO pages must not show an AI health badge.');
$workerWrapper = file_get_contents(__DIR__ . '/../ai_processor/run-ai-worker.bat');
health_assert(is_string($workerWrapper) && str_contains($workerWrapper, 'if "%AICS_ADCD_ENABLED%"=="" set "AICS_ADCD_ENABLED=0"'), 'Worker wrapper must default optional ADCD to disabled.');
echo "AI health test passed.\n";
