<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';

pswdo_start_session();

/**
 * Redirect back to a page with status message.
 */
function pswdo_redirect_with_message(
    string $status,
    string $message,
    int $requestId = 0,
    string $page = 'requests',
    array $extraQuery = []
): void
{
    $query = [
        'page' => $page,
        'status' => $status,
        'message' => $message,
    ];

    foreach ($extraQuery as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        $query[$key] = (string) $value;
    }

    if ($requestId > 0 && $page === 'requests') {
        $query['reopen_request'] = (string) $requestId;
    }

    // Preserve embed=1 across the redirect so the iframe stays in embedded mode
    // and the parent (Screening Queue) can be notified via postMessage.
    if (((string) ($_POST['embed'] ?? $_GET['embed'] ?? '')) === '1') {
        $query['embed'] = '1';
    }

    header('Location: ../dashboard.php?' . http_build_query($query));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pswdo_redirect_with_message('error', 'Invalid action method.');
}

$csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
$sessionToken = trim((string) ($_SESSION['aics_pswdo_csrf'] ?? ''));

if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
    pswdo_redirect_with_message('error', 'Security token mismatch. Please refresh and retry.');
}

$actionType = strtolower(trim((string) ($_POST['action_type'] ?? '')));
$requestId = (int) ($_POST['request_id'] ?? 0);
$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$remarks = trim((string) ($_POST['remarks'] ?? ''));
$redirectPageInput = strtolower(trim((string) ($_POST['return_page'] ?? 'requests')));
$redirectPage = in_array($redirectPageInput, ['overview', 'workflow', 'release', 'requests', 'prescreening', 'reports'], true)
    ? $redirectPageInput
    : 'requests';
$redirectExtra = [];
if ($redirectPage === 'prescreening') {
    $redirectExtra['queue_tab'] = pswdo_normalize_prescreening_tab((string) ($_POST['return_queue_tab'] ?? 'for_screening'));
    $preDateFrom = trim((string) ($_POST['return_date_from'] ?? ''));
    $preDateTo = trim((string) ($_POST['return_date_to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $preDateFrom) === 1) {
        $redirectExtra['date_from'] = $preDateFrom;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $preDateTo) === 1) {
        $redirectExtra['date_to'] = $preDateTo;
    }
    $redirectExtra['sort_by'] = pswdo_normalize_prescreening_sort((string) ($_POST['return_queue_sort'] ?? 'default'));
    $redirectExtra['sort_group'] = pswdo_normalize_prescreening_sort_group(
        (string) ($redirectExtra['sort_by'] ?? 'default'),
        (string) ($_POST['return_queue_group'] ?? 'all')
    );
}
if ($redirectPage === 'release') {
    $redirectExtra['release_view'] = pswdo_normalize_release_view((string) ($_POST['return_release_view'] ?? 'all'));
    $redirectExtra['sort_by'] = pswdo_normalize_release_sort((string) ($_POST['return_release_sort'] ?? 'default'));
    $redirectExtra['sort_group'] = pswdo_normalize_release_sort_group(
        (string) ($redirectExtra['sort_by'] ?? 'default'),
        (string) ($_POST['return_release_group'] ?? 'all')
    );
    $batchDateFrom = trim((string) ($_POST['return_batch_date_from'] ?? ''));
    $batchDateTo = trim((string) ($_POST['return_batch_date_to'] ?? ''));
    $batchKey = trim((string) ($_POST['return_batch_key'] ?? 'all'));
    $batchLimit = (int) ($_POST['return_batch_limit'] ?? 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDateFrom) === 1) {
        $redirectExtra['batch_date_from'] = $batchDateFrom;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDateTo) === 1) {
        $redirectExtra['batch_date_to'] = $batchDateTo;
    }
    $redirectExtra['batch_key'] = $batchKey !== '' ? $batchKey : 'all';
    $redirectExtra['batch_limit'] = (string) max(1, min(200, $batchLimit));
}

if ($accountId <= 0) {
    pswdo_redirect_with_message('error', 'Invalid request target.', 0, $redirectPage, $redirectExtra);
}

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);
    $responseStatus = 'success';
    if ($requestId > 0 && !aics_ai_gate_is_released($connection, $requestId)) {
        $connection->close();
        pswdo_redirect_with_message('error', 'This request is still being checked by AICS AI.', 0, $redirectPage, $redirectExtra);
    }

    if ($actionType === 'mass_send_release_schedule_sms') {
        $smsMessage = trim((string) ($_POST['message_text'] ?? ''));
        $result = pswdo_send_mass_release_schedule_sms_action(
            $connection,
            $accountId,
            (string) ($redirectExtra['sort_by'] ?? 'default'),
            (string) ($redirectExtra['sort_group'] ?? 'all'),
            $smsMessage,
            200,
            (string) ($redirectExtra['batch_date_from'] ?? ''),
            (string) ($redirectExtra['batch_date_to'] ?? ''),
            (string) ($redirectExtra['batch_key'] ?? 'all'),
            (int) ($redirectExtra['batch_limit'] ?? 10)
        );
        $sentCount = (int) ($result['sent_count'] ?? 0);
        $failedCount = (int) ($result['failed_count'] ?? 0);
        $skippedNoSchedule = (int) ($result['skipped_no_schedule'] ?? 0);
        $skippedNoContact = (int) ($result['skipped_no_contact'] ?? 0);
        $skippedInvalidContact = (int) ($result['skipped_invalid_contact'] ?? 0);

        $message = 'Batch Notification Completed. Applicant SMS — Sent: ' . $sentCount . '; Failed: ' . $failedCount . '.';
        if ($skippedNoSchedule > 0 || $skippedNoContact > 0 || $skippedInvalidContact > 0) {
            $message .= ' Not attempted: '
                . $skippedNoSchedule . ' without schedule, '
                . ($skippedNoContact + $skippedInvalidContact) . ' without valid contact.';
        }
        $mswdoLabels = [];
        foreach ((array) ($result['mswdo_results'] ?? []) as $mswdoResult) {
            if (!is_array($mswdoResult)) continue;
            $mswdoLabels[] = trim((string) ($mswdoResult['municipality'] ?? 'Unknown')) . ': ' . ucfirst((string) ($mswdoResult['status'] ?? 'failed'));
            if (($mswdoResult['status'] ?? '') !== 'simulated') $responseStatus = 'error';
        }
        $message .= ' MSWDO Notifications — ' . ($mswdoLabels !== [] ? implode('; ', $mswdoLabels) . '.' : 'none generated.');
        if ($failedCount > 0 || $sentCount <= 0) $responseStatus = 'error';
    } elseif ($actionType === 'mass_send_prescreening_sms') {
        $smsMessage = trim((string) ($_POST['message_text'] ?? ''));
        $result = pswdo_send_mass_prescreening_sms_action(
            $connection,
            $accountId,
            (string) ($redirectExtra['queue_tab'] ?? 'for_screening'),
            (string) ($redirectExtra['date_from'] ?? ''),
            (string) ($redirectExtra['date_to'] ?? ''),
            (string) ($redirectExtra['sort_by'] ?? 'default'),
            (string) ($redirectExtra['sort_group'] ?? 'all'),
            $smsMessage,
            120
        );
        $sentCount = (int) ($result['sent_count'] ?? 0);
        $skippedNoContact = (int) ($result['skipped_no_contact'] ?? 0);
        $skippedInvalidContact = (int) ($result['skipped_invalid_contact'] ?? 0);

        if ($sentCount <= 0) {
            throw new RuntimeException('No applicant was notified. Make sure the visible screening queue has valid contact numbers.');
        }

        $message = 'Mass notice sent to ' . $sentCount . ' screening case' . ($sentCount === 1 ? '' : 's') . '. Cases were moved to Release Scheduling queue.';
        if ($skippedNoContact > 0 || $skippedInvalidContact > 0) {
            $message .= ' Skipped: ' . ($skippedNoContact + $skippedInvalidContact) . ' without valid contact.';
        }
    } elseif ($actionType === 'bulk_save_release_schedule') {
        $scheduledDate = trim((string) ($_POST['scheduled_date'] ?? ''));
        $scheduledTime = trim((string) ($_POST['scheduled_time'] ?? ''));
        $venue = trim((string) ($_POST['venue'] ?? ''));
        $batchLimit = max(1, (int) ($_POST['batch_limit'] ?? 10));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $rawIds = $_POST['request_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $savedCount = pswdo_bulk_save_release_schedule_action(
            $connection,
            $rawIds,
            $accountId,
            $scheduledDate,
            $scheduledTime,
            $venue,
            $batchLimit,
            $notes
        );
        $message = 'Release schedule saved for ' . $savedCount . ' applicant' . ($savedCount === 1 ? '' : 's') . '.';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate) === 1) {
            $redirectExtra['batch_date_from'] = $scheduledDate;
            $redirectExtra['batch_date_to'] = $scheduledDate;
            $redirectExtra['batch_key'] = 'all';
        }
    } elseif ($requestId <= 0) {
        throw new RuntimeException('Invalid request target.');
    } elseif ($actionType === 'record_ai_review_feedback') {
        $reviewId = (int) ($_POST['ai_review_id'] ?? 0);
        aics_record_ai_review_feedback($connection, $reviewId, $accountId, [
            'ai_suggested_action' => (string) ($_POST['ai_suggested_action'] ?? ''),
            'staff_outcome' => (string) ($_POST['staff_outcome'] ?? ''),
            'agreement_state' => (string) ($_POST['agreement_state'] ?? ''),
            'corrected_document_type' => (string) ($_POST['corrected_document_type'] ?? ''),
            'reason_code' => (string) ($_POST['reason_code'] ?? ''),
            'remarks' => (string) ($_POST['feedback_remarks'] ?? ''),
        ]);
        $message = 'AI review feedback saved. Your decision remains the final decision.';
    } elseif ($actionType === 'reprocess_ai_review') {
        $reviewId = (int) ($_POST['ai_review_id'] ?? 0);
        error_log('AICS AI reprocess requested account_id=' . $accountId . ' review_id=' . $reviewId . ' request_id=' . $requestId);
        $result = aics_reprocess_ai_review($connection, $reviewId, $accountId, $requestId);
        $documentId = (int) ($result['request_document_id'] ?? 0);
        if (!aics_launch_document_ai_processor($documentId)) {
            aics_mark_ai_review_failed($connection, $reviewId, 'AI_PROCESSOR_LAUNCH_FAILED', 'Local verification could not be started. Please retry or use manual review.');
            error_log('AICS AI launch failed account_id=' . $accountId . ' review_id=' . $reviewId . ' document_id=' . $documentId);
            throw new RuntimeException('Local AI verification could not be started. Please retry or use manual review.');
        }
        $message = 'AI verification is processing. The previous result was preserved.';
    } elseif ($actionType === 'update_status') {
        $newStatus = trim((string) ($_POST['new_status'] ?? 'pending_review'));
        $result = pswdo_update_request_status_action($connection, $requestId, $newStatus, $accountId, $remarks);
        $statusLabel = trim((string) ($result['status_label'] ?? 'Updated'));
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $message = $referenceNo !== ''
            ? ('Request ' . $referenceNo . ' status updated to ' . $statusLabel . '.')
            : ('Request status updated to ' . $statusLabel . '.');

        $notificationMessage = $referenceNo !== ''
            ? ('Your application ' . $referenceNo . ' status is now ' . $statusLabel . '. Please check your Application Status page for details.')
            : ('Your application status is now ' . $statusLabel . '. Please check your Application Status page for details.');
        if (trim($remarks) !== '') {
            $notificationMessage .= ' PSWDO note: ' . trim($remarks);
        }
        pswdo_save_notification_action(
            $connection,
            $requestId,
            'Status Update',
            $notificationMessage,
            $accountId
        );
    } elseif ($actionType === 'view_request') {
        $result = pswdo_mark_request_in_progress_action($connection, $requestId, $accountId);
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $isReadyForPrescreen = (bool) ($result['is_ready_for_prescreening'] ?? false);
        $screeningSmsSent = (bool) ($result['screening_sms_sent'] ?? false);
        $followupWarning = (bool) ($result['followup_warning'] ?? false);
        $uploadedTotal = (int) ($result['uploaded_total'] ?? 0);
        $requiredTotal = (int) ($result['required_total'] ?? 0);

        if ($isReadyForPrescreen) {
            $message = $referenceNo !== ''
                ? ('Request ' . $referenceNo . ' is approved and moved to For Screening queue, and applicant was notified' . ($screeningSmsSent ? ' via formal SMS.' : '.'))
                : ('Request is approved and moved to For Screening queue, and applicant was notified' . ($screeningSmsSent ? ' via formal SMS.' : '.'));
        } else {
            $message = $referenceNo !== ''
                ? ('Request ' . $referenceNo . ' was flagged as Incomplete Requirements (' . $uploadedTotal . ' of ' . $requiredTotal . ' documents uploaded), and applicant was notified.')
                : ('Request was flagged as Incomplete Requirements (' . $uploadedTotal . ' of ' . $requiredTotal . ' documents uploaded), and applicant was notified.');
        }
        if ($followupWarning) {
            $message .= ' The decision was saved, but one follow-up record could not be completed.';
        }
    } elseif ($actionType === 'document_check') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $isChecked = ((string) ($_POST['is_checked'] ?? '0')) === '1';
        if ($documentId <= 0) {
            throw new RuntimeException('Invalid document selection.');
        }
        pswdo_toggle_document_check_action($connection, $documentId, $isChecked, $accountId, $remarks);
        $message = $isChecked ? 'Document marked as checked.' : 'Document check was removed.';
    } elseif ($actionType === 'send_notification') {
        $stage = trim((string) ($_POST['notification_stage'] ?? 'General Update'));
        $smsMessage = trim((string) ($_POST['message_text'] ?? ''));
        pswdo_save_notification_action($connection, $requestId, $stage, $smsMessage, $accountId);
        $message = 'Applicant notification log saved.';
    } elseif ($actionType === 'mark_invalid') {
        $result = pswdo_mark_request_invalid_action($connection, $requestId, $accountId);
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $message = $referenceNo !== ''
            ? ('Request ' . $referenceNo . ' was marked as Not Approved due to invalid requirements and applicant was notified.')
            : 'Request was marked as Not Approved due to invalid requirements and applicant was notified.';
    } elseif ($actionType === 'mark_invalid_with_sms') {
        $smsMessage = trim((string) ($_POST['message_text'] ?? ''));
        if ($smsMessage === '') {
            throw new RuntimeException('Please enter an SMS message before sending.');
        }
        $result = pswdo_mark_request_invalid_action($connection, $requestId, $accountId, false);
        $smsResult = pswdo_send_sms_to_applicant_action($connection, $requestId, 'invalid_requirements', $smsMessage, $accountId);
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $recipient = trim((string) ($smsResult['recipient'] ?? ''));
        $message = $referenceNo !== ''
            ? ('Request ' . $referenceNo . ' was marked as Not Approved and SMS notice was sent' . ($recipient !== '' ? (' to ' . $recipient . '.') : '.'))
            : ('Request was marked as Not Approved and SMS notice was sent' . ($recipient !== '' ? (' to ' . $recipient . '.') : '.'));
    } elseif ($actionType === 'send_sms') {
        $decisionStatus = trim((string) ($_POST['decision_status'] ?? 'for_screening'));
        $smsMessage = trim((string) ($_POST['message_text'] ?? ''));
        $recipientNumber = trim((string) ($_POST['recipient_number'] ?? ''));
        if ($smsMessage === '') {
            throw new RuntimeException('Please enter an SMS message before sending.');
        }
        if ($recipientNumber === '') {
            throw new RuntimeException('Recipient number is missing. Refresh the page and try again.');
        }
        $result = pswdo_send_sms_to_applicant_action($connection, $requestId, $decisionStatus, $smsMessage, $accountId, true, $recipientNumber);
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $recipient = trim((string) ($result['recipient'] ?? ''));
        $message = $referenceNo !== ''
            ? ('SMS sent to applicant for request ' . $referenceNo . ($recipient !== '' ? (' (' . $recipient . ').') : '.'))
            : ('SMS sent to applicant' . ($recipient !== '' ? (' (' . $recipient . ').') : '.'));
    } elseif ($actionType === 'send_document_sms') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $documentReviewStatus = strtolower(trim((string) ($_POST['document_review_status'] ?? 'needs_reupload')));
        $documentLabel = trim((string) ($_POST['document_label'] ?? 'Document'));
        $smsMessage = trim((string) ($_POST['message_text'] ?? ''));
        if ($documentId <= 0) {
            throw new RuntimeException('Invalid document selection.');
        }
        if ($smsMessage === '') {
            throw new RuntimeException('Please enter an SMS message before sending.');
        }

        $update = pswdo_set_document_review_status_action(
            $connection,
            $requestId,
            $documentId,
            $documentReviewStatus,
            $accountId,
            $smsMessage
        );
        $smsResult = [];
        $smsSent = false;
        try {
            $smsResult = pswdo_send_sms_to_applicant_action($connection, $requestId, 'incomplete_requirements', $smsMessage, $accountId);
            $smsSent = true;
        } catch (Throwable $exception) {
            error_log('AICS document review SMS failed request_id=' . $requestId . ' document_id=' . $documentId . ' error=' . $exception->getMessage());
        }

        $stage = match ($documentReviewStatus) {
            'checked' => 'Document Approved',
            'invalid' => 'Document Invalid',
            default => 'Document Re-upload Requested',
        };
        try {
            pswdo_save_notification_action(
                $connection,
                $requestId,
                $stage . ': ' . $documentLabel,
                $smsMessage,
                $accountId
            );
        } catch (Throwable $exception) {
            error_log('AICS document review notification log failed request_id=' . $requestId . ' document_id=' . $documentId . ' error=' . $exception->getMessage());
        }

        $referenceNo = trim((string) ($update['reference_no'] ?? ''));
        $recipient = trim((string) ($smsResult['recipient'] ?? ''));
        $savedLabel = $documentReviewStatus === 'checked' ? 'approved' : ($documentReviewStatus === 'invalid' ? 'marked invalid' : 'marked for re-upload');
        $message = $referenceNo !== ''
            ? ('Document ' . $documentLabel . ' was ' . $savedLabel . ' for request ' . $referenceNo . '.')
            : ('Document ' . $documentLabel . ' was ' . $savedLabel . '.');
        $message .= $smsSent
            ? (' SMS sent' . ($recipient !== '' ? (' to ' . $recipient . '.') : '.'))
            : ' The update was saved, but SMS delivery was not completed.';
    } elseif ($actionType === 'save_release_schedule') {
        $scheduledDate = trim((string) ($_POST['scheduled_date'] ?? ''));
        $scheduledTime = trim((string) ($_POST['scheduled_time'] ?? ''));
        $venue = trim((string) ($_POST['venue'] ?? ''));
        $batchLimit = max(1, (int) ($_POST['batch_limit'] ?? 10));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $amountGiven = (float) preg_replace('/[^\d.]/', '', (string) ($_POST['amount_given'] ?? '0'));
        $result = pswdo_save_release_schedule_action(
            $connection,
            $requestId,
            $accountId,
            $scheduledDate,
            $scheduledTime,
            $venue,
            $batchLimit,
            $notes,
            $amountGiven
        );
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $scheduleLabel = trim((string) ($result['schedule_label'] ?? 'the selected schedule'));

        $sendSms = ((string) ($_POST['send_sms'] ?? '')) === '1';
        $smsMessage = trim((string) ($_POST['sms_message'] ?? ''));
        $smsRecipient = '';
        $smsSent = false;
        if ($sendSms && $smsMessage !== '') {
            try {
                $smsResult = pswdo_send_sms_to_applicant_action(
                    $connection,
                    $requestId,
                    'release_schedule',
                    $smsMessage,
                    $accountId
                );
                $smsRecipient = trim((string) ($smsResult['recipient'] ?? ''));
                $smsSent = true;
            } catch (Throwable $smsException) {
                $smsRecipient = '';
                $smsSent = false;
                try {
                    pswdo_save_sms_delivery_audit($connection, $requestId, 'Release Schedule SMS', 'Applicant SMS delivery failed.', $accountId, 'failed');
                } catch (Throwable) {
                    // Preserve the real channel result even when its audit write fails.
                }
            }
        }

        $releaseRecord = pswdo_fetch_release_schedule_record($connection, $requestId);
        $mswdoResults = $releaseRecord !== null
            ? pswdo_process_mswdo_release_notifications($connection, [$releaseRecord], $accountId)
            : [['municipality' => 'Unknown', 'status' => 'failed', 'error' => 'Release record could not be loaded.']];
        $mswdoResult = $mswdoResults[0] ?? ['municipality' => 'Unknown', 'status' => 'failed'];
        $mswdoLabel = trim((string) ($mswdoResult['municipality'] ?? 'MSWDO')) . ' MSWDO Notification: ' . ucfirst((string) ($mswdoResult['status'] ?? 'failed'));

        $baseMessage = $referenceNo !== ''
            ? ('Release schedule saved for request ' . $referenceNo . ' on ' . $scheduleLabel . '.')
            : ('Release schedule saved for ' . $scheduleLabel . '.');
        if ($sendSms && $smsSent) {
            $message = 'Release Notification Completed. Applicant SMS: Sent. ' . $mswdoLabel . '.';
        } elseif ($sendSms && !$smsSent) {
            $message = $baseMessage . ' Applicant SMS: Failed. ' . $mswdoLabel . '.';
            $responseStatus = 'error';
        } else {
            $message = $baseMessage . ' Applicant SMS: Not requested. ' . $mswdoLabel . '.';
        }
        if (($mswdoResult['status'] ?? '') !== 'simulated') $responseStatus = 'error';
        if (pswdo_should_reopen_release_schedule_modal($actionType)) {
            $redirectExtra['schedule_request'] = (string) $requestId;
        }
    } elseif ($actionType === 'update_release_status') {
        $releaseStatus = trim((string) ($_POST['release_status'] ?? 'scheduled'));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $amountGiven = max(0.0, (float) preg_replace('/[^\d.]/', '', (string) ($_POST['amount_given'] ?? '0')));
        $result = pswdo_update_release_schedule_status_action($connection, $requestId, $releaseStatus, $accountId, $notes, $amountGiven);
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $statusLabel = trim((string) ($result['release_status_label'] ?? 'Updated'));
        $message = $referenceNo !== ''
            ? ('Release status for request ' . $referenceNo . ' is now ' . $statusLabel . '.')
            : ('Release status is now ' . $statusLabel . '.');
        $redirectExtra['schedule_request'] = (string) $requestId;
    } elseif ($actionType === 'approve_for_release') {
        $result = pswdo_approve_for_release_action($connection, $requestId, $accountId);
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $message = $referenceNo !== ''
            ? ('Request ' . $referenceNo . ' approved for release and moved to Release Scheduling queue.')
            : 'Request approved for release and moved to Release Scheduling queue.';
    } elseif ($actionType === 'reschedule_unattended') {
        $result = pswdo_reschedule_unattended_action($connection, $requestId, $accountId);
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $message = $referenceNo !== ''
            ? ('Request ' . $referenceNo . ' has been moved back to Needs Schedule. Assign a new release date.')
            : 'Applicant has been moved back to Needs Schedule.';
        $redirectExtra['schedule_request'] = (string) $requestId;
    } elseif ($actionType === 'reject_request') {
        $rejectionReason = trim((string) ($_POST['remarks'] ?? ''));
        $result = pswdo_mark_request_invalid_action($connection, $requestId, $accountId, true, '', $rejectionReason, true);
        $referenceNo = trim((string) ($result['reference_no'] ?? ''));
        $message = $referenceNo !== ''
            ? ('Request ' . $referenceNo . ' has been rejected and the applicant has been notified.')
            : 'Request has been rejected and the applicant has been notified.';
    } else {
        throw new RuntimeException('Unsupported workflow action.');
    }

    $connection->close();
    pswdo_redirect_with_message(
        $responseStatus,
        $message,
        pswdo_request_reopen_id_after_action($requestId, $actionType, $responseStatus),
        $redirectPage,
        $redirectExtra
    );
} catch (Throwable $exception) {
    pswdo_redirect_with_message('error', $exception->getMessage(), $requestId, $redirectPage, $redirectExtra);
}
