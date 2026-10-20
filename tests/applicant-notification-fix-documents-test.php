<?php
declare(strict_types=1);

// When AICS AI could not accept a document, the "Re-upload needed" notification
// shows that document and lets the applicant upload a replacement from the
// notification, using the same upload as the Application Status page.

function notification_fix_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$page = (string) file_get_contents("{$root}/Applicants/php_files/notifications.php");
$status = (string) file_get_contents("{$root}/Applicants/php_files/application-status.php");

notification_fix_assert(str_contains($page, "require_once __DIR__ . '/../../includes/ai-submission-gate.php';"), 'Notifications load the AICS AI gate helpers.');
notification_fix_assert(str_contains($page, 'n.request_id,') && str_contains($page, 'r.ai_gate_status'), 'Each notification knows its request and whether it is on hold.');
notification_fix_assert(str_contains($page, "aics_ai_gate_stage_title('ai_check_hold')") && str_contains($page, 'aics_ai_gate_applicant_items($connection'), 'Re-upload notifications of a held request list the documents to replace.');
notification_fix_assert(str_contains($page, 'data-gate="'), 'The documents travel with the notification card.');
notification_fix_assert(str_contains($page, 'id="notification-modal-fix"') && str_contains($page, 'id="notification-modal-fix-list"'), 'The notification popup has a place for the documents.');

// The popup's upload form sends exactly what the Status page's replace form sends.
foreach (['reupload_single', 'request_id', 'document_type', 'reupload_file'] as $field) {
    notification_fix_assert(str_contains($status, $field), "The Status page sends {$field}.");
    notification_fix_assert(substr_count($page, $field) >= 1, "The notification upload sends {$field}.");
}
notification_fix_assert(str_contains($page, "form.action = 'documents-upload.php'"), 'The notification upload goes to the existing document upload.');
notification_fix_assert(str_contains($page, 'fixWrap.hidden = items.length === 0'), 'The section only shows while documents still need replacing.');
notification_fix_assert(!preg_match('/fixList\.innerHTML\s*=/', $page), 'Document names are added as text, not HTML.');

echo "applicant notification fix documents test passed\n";
