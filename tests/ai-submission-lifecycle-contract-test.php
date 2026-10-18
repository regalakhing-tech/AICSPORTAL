<?php
declare(strict_types=1);
function lifecycle_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$reviews = file_get_contents(__DIR__ . '/../includes/document-ai-reviews.php');
$drafts = file_get_contents(__DIR__ . '/../Applicants/php_files/draft-lib.php');
$saveRequest = file_get_contents(__DIR__ . '/../Applicants/php_files/save-request.php');
$documentsUpload = file_get_contents(__DIR__ . '/../Applicants/php_files/documents-upload.php');
$ensureEndpoint = file_get_contents(__DIR__ . '/../Pswdo/php_files/ai-ensure-request-current.php');
$health = file_get_contents(__DIR__ . '/../Pswdo/php_files/ai-health.php');
lifecycle_assert(is_string($health) && str_contains($health, "'aics_ai_health_v3'"), 'Health endpoint did not invalidate the pre-provider status cache.');
$ensureCurrent = file_get_contents(__DIR__ . '/../Pswdo/php_files/ai-ensure-current.php');
lifecycle_assert(is_string($ensureCurrent) && str_contains($ensureCurrent, "['aics_ai_health_v3']"), 'AI ensure endpoint does not read the current health cache version.');
lifecycle_assert(is_string($reviews) && str_contains($reviews, 'start "" /B cmd.exe /D /S /C') && str_contains($reviews, '< NUL >>') && !str_contains($reviews, 'start "" /B call '), 'Windows worker launch still retains the parent batch command.');
lifecycle_assert(is_string($reviews) && str_contains($reviews, 'function aics_dispatch_unclaimed_ai_reviews_for_request') && str_contains($reviews, 'aics_ensure_current_ai_reviews_for_request($connection, $requestId)') && str_contains($reviews, 'aics_launch_request_ai_processor($requestId, $leaseToken)') && !str_contains($reviews, 'foreach ($jobs as $job)'), 'PSWDO request-wide dispatcher contract is incomplete.');
lifecycle_assert(is_string($reviews) && str_contains($reviews, 'function aics_queue_ai_reviews_after_commit'), 'The applicant-safe database-only queue helper is missing.');
lifecycle_assert(is_string($saveRequest) && substr_count($saveRequest, 'aics_queue_ai_reviews_after_commit($connection, $requestId)') === 2 && !str_contains($saveRequest, 'aics_dispatch_ai_reviews_after_commit($connection, $requestId)'), 'Applicant request submission still starts the AI worker before responding.');
lifecycle_assert(is_string($documentsUpload) && substr_count($documentsUpload, 'aics_queue_ai_reviews_after_commit($connection, $requestId)') === 2 && !str_contains($documentsUpload, 'aics_dispatch_ai_reviews_after_commit($connection, $requestId)'), 'Applicant document upload still starts the AI worker before redirecting.');
lifecycle_assert(is_string($drafts) && !str_contains($drafts, 'aics_create_ai_review('), 'Applicant draft finalization still creates AI work.');
lifecycle_assert(is_string($ensureEndpoint) && str_contains($ensureEndpoint, 'aics_ensure_current_ai_reviews_for_request') && str_contains($ensureEndpoint, 'aics_launch_request_ai_processor') && str_contains($ensureEndpoint, "'worker_needed'"), 'PSWDO ensure endpoint is not the normal AI start point.');
lifecycle_assert(is_string($ensureEndpoint) && str_contains($ensureEndpoint, 'session_write_close()') && strpos($ensureEndpoint, 'session_write_close()') < strpos($ensureEndpoint, 'aics_launch_request_ai_processor'), 'PSWDO background start holds the session lock while launching AI, which blocks progress polling.');
lifecycle_assert(is_string($health) && str_contains($health, 'session_write_close()') && !str_contains($health, '$_SESSION[$cacheKey] ='), 'Health endpoint still holds or writes the PSWDO session during probing.');
echo "AI submission lifecycle contract test passed.\n";
