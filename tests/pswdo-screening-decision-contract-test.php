<?php
declare(strict_types=1);

function screening_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$library = file_get_contents(__DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php');
$workflow = file_get_contents(__DIR__ . '/../Pswdo/php_files/workflow-action.php');
$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');

screening_contract_assert(is_string($library), 'PSWDO data library could not be read.');
screening_contract_assert(is_string($workflow), 'PSWDO workflow endpoint could not be read.');
screening_contract_assert(is_string($page), 'Request Management page could not be read.');
screening_contract_assert(is_string($script), 'PSWDO page script could not be read.');

screening_contract_assert(str_contains($library, "'all_staff_reviewed' => \$allStaffReviewed"), 'Required-document staff-review completion is not exposed by the authoritative evaluator.');
screening_contract_assert(str_contains($library, "'all_approved' => \$allApproved"), 'Required-document approval completion is not exposed by the authoritative evaluator.');
screening_contract_assert(str_contains($library, "['checked', 'needs_reupload', 'invalid']"), 'Staff review does not recognize existing non-approved review outcomes.');
screening_contract_assert(str_contains($library, 'Review all required documents before approving this application.'), 'Approve is not blocked for incomplete staff review.');
screening_contract_assert(!str_contains($library, 'Resolve the required document issues before approving this application.'), 'Final approval still requires an artificial per-document approval state.');
screening_contract_assert(str_contains($library, 'Review all required documents before rejecting this application.'), 'Reject is not blocked for incomplete staff review.');
screening_contract_assert(str_contains($library, 'Please provide a reason for rejecting this application.'), 'Reject reason is not enforced by the server.');
screening_contract_assert(str_contains($library, "['approved', 'released', 'not_approved', 'cancelled']"), 'Terminal status guard does not include approved requests.');
screening_contract_assert(str_contains($workflow, "\$result = pswdo_mark_request_invalid_action"), 'Reject workflow does not use the server-side decision gate.');
screening_contract_assert(str_contains($page, 'Approve Application?') && str_contains($page, '></i> Approve'), 'Final approval action was not renamed to Approve.');
screening_contract_assert(str_contains($page, 'textarea id="reject-reason-text"') && str_contains($page, 'aria-required="true"'), 'Reject reason field is not required and accessible.');
screening_contract_assert(str_contains($script, 'data-staff-review-incomplete') && str_contains($script, 'Please provide a reason for rejecting this application.'), 'Client prechecks are missing the screening decision gates.');

echo "PSWDO screening decision contract test passed.\n";
