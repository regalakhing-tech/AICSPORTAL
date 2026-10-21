<?php
declare(strict_types=1);

// PSWDO approves from the file view alone (no per-document "Mark as Checked"),
// can select many requests and approve them at once, and sees who was accepted.
function bulk_approval_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
$workflow = file_get_contents(__DIR__ . '/../Pswdo/php_files/workflow-action.php');
$library = file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php');
$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
bulk_approval_assert(is_string($page) && is_string($workflow) && is_string($library) && is_string($script), 'PSWDO sources could not be read.');

// Documents: view the file, or send a manual Review SMS; no per-document check.
bulk_approval_assert(!str_contains($page, 'Mark as Checked') && !str_contains($page, 'file-preview-approve-btn'), 'The file preview still asks staff to mark each document as checked.');
bulk_approval_assert(!str_contains($page, '<th>Review</th>'), 'The documents table still has a per-document review column.');
bulk_approval_assert(!str_contains($page, '> Rescan</button>') && !str_contains($page, 'js-ai-reprocess'), 'The documents action column still shows Rescan.');
bulk_approval_assert(str_contains($page, '></i> File'), 'The documents action column lost the file view.');
bulk_approval_assert(str_contains($page, 'data-sms-action-type="send_document_sms"') && str_contains($page, '></i> Review') && str_contains($page, 'id="sms-compose-modal"'), 'The Review button that sends a manual SMS is missing.');
bulk_approval_assert(!str_contains($page, 'Staff Review Pending') && !str_contains($script, 'submitPreviewCheck'), 'Leftover per-document check UI remains.');

// Approval no longer waits for staff review of each document.
bulk_approval_assert(!str_contains($library, 'Review all required documents before approving this application.'), 'Approval still requires each document to be checked.');
bulk_approval_assert(!str_contains($library, 'Review all required documents before rejecting this application.'), 'Rejection still requires each document to be checked.');
bulk_approval_assert(!str_contains($script, 'data-staff-review-incomplete'), 'The page still blocks approval until every document is checked.');

// Select all, approve selected.
bulk_approval_assert(str_contains($page, 'id="select-all-requests"') && str_contains($page, 'class="js-request-select"'), 'Requests cannot be selected for bulk approval.');
bulk_approval_assert(str_contains($page, 'id="bulk-approve-form"') && str_contains($page, 'value="bulk_approve_requests"'), 'The bulk Approve action is missing.');
bulk_approval_assert(str_contains($workflow, "\$actionType === 'bulk_approve_requests'") && str_contains($workflow, 'pswdo_bulk_approve_requests_action('), 'The workflow endpoint does not handle bulk approval.');
bulk_approval_assert(str_contains($library, 'function pswdo_bulk_approve_requests_action('), 'Bulk approval is not implemented in the data library.');
bulk_approval_assert(str_contains($library, 'aics_ai_gate_is_released($connection, $requestId)'), 'Bulk approval skips the AICS AI release check.');

// Feedback card after approval names the accepted applicants.
bulk_approval_assert(str_contains($workflow, "\$_SESSION['aics_pswdo_approval_feedback']"), 'Approval results are not handed to the page.');
bulk_approval_assert(str_contains($page, 'id="approval-feedback-modal"') && str_contains($page, 'has been accepted'), 'The approval feedback card is missing.');

echo "PSWDO bulk approval contract test passed.\n";
