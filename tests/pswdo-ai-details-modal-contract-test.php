<?php
declare(strict_types=1);

function ai_details_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
ai_details_assert(is_string($page) && str_contains($page, 'class="ai-details-toggle"'), 'Server detail source is missing the AI Details toggle.');
ai_details_assert(is_string($page) && str_contains($page, 'class="ai-details-panel"'), 'Server detail source is missing the AI Details panel.');
ai_details_assert(is_string($page) && str_contains($page, 'id="request-details-modal"'), 'Request details modal is missing.');
ai_details_assert(is_string($script) && str_contains($script, 'function prepareModalAiDetails()'), 'Cloned AI Details panels are not prepared for the modal.');
ai_details_assert(is_string($script) && str_contains($script, 'panel.id = `modal-ai-details-${documentId}`'), 'Cloned panels are not assigned modal-scoped IDs.');
ai_details_assert(is_string($script) && str_contains($script, 'const aiToggle = target.closest(".ai-details-toggle")'), 'Modal lacks delegated AI Details click handling.');
ai_details_assert(is_string($script) && str_contains($script, 'detailRow.hidden = !expanded;'), 'Delegated handler does not show/hide the visible full-width row.');
ai_details_assert(is_string($page) && str_contains($page, 'pswdo_ai_verdict_card_html(') && !str_contains($page, 'ai-verification-primary-grid'), 'The verdict card must replace the old Document Verification grids.');
ai_details_assert(is_string($page) && str_contains($page, 'View marked areas') && str_contains($page, 'Technical details'), 'The verdict card is missing its evidence button or collapsed technical details.');
ai_details_assert(is_string($page) && str_contains($page, 'ai-document-verification-heading') && str_contains($page, 'ai-document-verification-header') && !str_contains($page, 'ai-alteration-section'), 'Unified AI presentation is missing, or the old alteration section is back.');
$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
ai_details_assert(is_string($script) && str_contains($script, 'findings?.tamper_detection') && str_contains($script, 'Document Alteration Review'), 'Dynamic details modal does not use the canonical alteration result.');
ai_details_assert(is_string($page) && str_contains($page, 'class="btn-tag btn-ai-rescan"') && str_contains($page, '> Rescan</button>') && str_contains($page, '> Scanning...</button>'), 'Document action column is missing the Rescan/Scanning control.');
ai_details_assert(is_string($page) && str_contains($page, '$actionAiStatus = strtolower') && str_contains($page, '$actionAiProcessing = $actionAiStatus === \'processing\''), 'Rescan state must be calculated from the current document instead of a previous table row.');
ai_details_assert(is_string($page) && str_contains($page, 'action_type" value="reprocess_ai_review"') && str_contains($page, 'csrf_token'), 'AI action does not reuse the protected reprocess workflow.');
ai_details_assert(is_string($page) && str_contains($page, 'action="ai-reprocess.php"') && str_contains($page, 'class="inline-form js-ai-reprocess"'), 'Rescan does not use the in-place AI endpoint.');
ai_details_assert(is_string($script) && str_contains($script, 'new FormData(form)') && str_contains($script, 'fetch(form.action') && str_contains($script, 'start(documentId, requestId)'), 'Rescan does not update and poll in place.');
ai_details_assert(is_string($script) && str_contains($script, 'button.disabled = true') && str_contains($script, 'Scanning...'), 'AI action does not disable and show scanning immediately.');
ai_details_assert(is_string($page) && str_contains($page, 'data-reopen-ai-document-id='), 'Canonical reload cannot identify which rescanned document details to reopen.');
ai_details_assert(is_string($script) && str_contains($script, 'terminalDocumentReviewUrl') && str_contains($script, 'window.location.replace') && str_contains($script, 'if (terminal) { stop(documentId);'), 'Completed AI reviews do not switch to the canonical server-rendered details.');
ai_details_assert(is_string($script) && str_contains($script, 'reopenAiDocumentDetails'), 'Canonical reload does not expand the rescanned document details.');
ai_details_assert(!str_contains((string) $page, 'ai-phase-one') && !str_contains((string) $page, 'ai-phase-two') && !str_contains((string) $page, 'Document Type Mismatch'), 'Internal phase or technical mismatch terminology remains visible.');
ai_details_assert(is_string($script) && str_contains($script, 'Image Review for Possible Changes') && !str_contains($script, ': []));'), 'Image Review rendering has an unbalanced array/function closure.');
$primaryHandlerOffset = is_string($script) ? strpos($script, 'target.closest(".js-open-request-modal")') : false;
$primaryAvailableOffset = is_string($script) ? strpos($script, 'window.__aicsRequestModalHandlerAvailable = true;') : false;
ai_details_assert(is_int($primaryHandlerOffset) && is_int($primaryAvailableOffset) && $primaryAvailableOffset > $primaryHandlerOffset, 'The fallback suppression marker must be set only after the primary View handler is registered.');
ai_details_assert(is_file(__DIR__ . '/pswdo-pages-js-syntax.html'), 'The browser bundle syntax harness is missing.');
echo "PSWDO AI details modal contract test passed.\n";
