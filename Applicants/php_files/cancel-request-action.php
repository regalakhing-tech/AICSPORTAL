<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/application-rules.php';

aics_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cancel_redirect('Invalid request method.', false);
}

if (!aics_is_logged_in_applicat()) {
    cancel_redirect('Please log in first.', false);
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
if ($accountId <= 0) {
    cancel_redirect('Invalid session.', false);
}

$sessionToken = (string) ($_SESSION['aics_cancel_csrf'] ?? '');
$postedToken = trim((string) ($_POST['csrf_token'] ?? ''));
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    cancel_redirect('Security check failed. Please try again.', false);
}

$requestId = (int) ($_POST['request_id'] ?? 0);
if ($requestId <= 0) {
    cancel_redirect('Invalid application selected.', false);
}
$cancellationReason = trim((string) ($_POST['cancellation_reason'] ?? ''));
if ($cancellationReason === '') {
    cancel_redirect('Enter a cancellation reason before cancelling the application.', false);
}
if (mb_strlen($cancellationReason) > 500) {
    cancel_redirect('Cancellation reason must be 500 characters or fewer.', false);
}

$connection = null;

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    $cancellationCooldown = aics_cancellation_cooldown_state($connection, $accountId);
    if (!empty($cancellationCooldown['is_in_cooldown'])) {
        throw new RuntimeException('You can cancel another application after ' . aics_format_cancellation_cooldown_datetime((string) ($cancellationCooldown['eligible_on'] ?? '')) . '.');
    }

    $selectSql = <<<SQL
        SELECT r.id, r.reference_no, r.request_status
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        WHERE ar.account_id = ? AND r.id = ?
        LIMIT 1
    SQL;

    $selectStmt = $connection->prepare($selectSql);
    $selectStmt->bind_param('ii', $accountId, $requestId);
    $selectStmt->execute();
    $row = $selectStmt->get_result()?->fetch_assoc();
    $selectStmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Application not found or not allowed.');
    }

    $currentStatus = strtolower(trim((string) ($row['request_status'] ?? '')));
    if (!cancel_status_is_cancellable($currentStatus)) {
        throw new RuntimeException('This application cannot be cancelled at its current status.');
    }

    $newStatus = 'cancelled';
    $updateStmt = $connection->prepare('UPDATE aics_requests SET request_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND request_status = ? LIMIT 1');
    $currentStatusForUpdate = (string) $row['request_status'];
    $updateStmt->bind_param('sis', $newStatus, $requestId, $currentStatusForUpdate);
    $updateStmt->execute();
    if ($updateStmt->affected_rows !== 1) {
        $updateStmt->close();
        throw new RuntimeException('This application was already updated. Refresh the page and try again.');
    }
    $updateStmt->close();

    // When PSWDO workflow tables exist, log cancellation in status history.
    try {
        $historyTable = $connection->query("SHOW TABLES LIKE 'aics_request_status_history'");
        $statusTable = $connection->query("SHOW TABLES LIKE 'aics_request_statuses'");

        if (($historyTable?->num_rows ?? 0) > 0 && ($statusTable?->num_rows ?? 0) > 0) {
            $statusIdRow = $connection->query("SELECT id FROM aics_request_statuses WHERE status_code = 'cancelled' LIMIT 1")?->fetch_assoc();
            $statusId = (int) ($statusIdRow['id'] ?? 0);
            if ($statusId > 0) {
                $historyStmt = $connection->prepare(
                    'INSERT INTO aics_request_status_history (request_id, status_id, remarks, changed_by_account_id, changed_at) VALUES (?, ?, ?, NULL, CURRENT_TIMESTAMP)'
                );
                $historyNote = 'Cancelled by applicant: ' . $cancellationReason;
                $historyStmt->bind_param('iis', $requestId, $statusId, $historyNote);
                $historyStmt->execute();
                $historyStmt->close();
            }
        }
    } catch (Throwable $ignored) {
        // Keep cancellation success even if history log is unavailable.
    }

    $referenceNo = trim((string) ($row['reference_no'] ?? ''));
    $successMessage = $referenceNo !== ''
        ? ('Application ' . $referenceNo . ' has been cancelled successfully.')
        : 'Application has been cancelled successfully.';

    $connection->close();
    cancel_redirect($successMessage, true);
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        try {
            $connection->close();
        } catch (Throwable $ignored) {
            // no-op
        }
    }
    cancel_redirect($exception->getMessage(), false);
}

function cancel_status_is_cancellable(string $status): bool
{
    $lockedStatuses = [
        'approved',
        'validated',
        'for_release',
        'for release',
        'released',
        'distributed',
        'completed',
        'closed',
        'rejected',
        'denied',
        'not_approved',
        'not approved',
        'cancelled',
        'canceled',
    ];

    return !in_array($status, $lockedStatuses, true);
}

function aics_format_cancellation_cooldown_datetime(string $value): string
{
    if ($value === '') return '24 hours have passed';
    try {
        return (new DateTimeImmutable($value, aics_dashboard_timezone()))->format('F j, Y, g:i A');
    } catch (Throwable) {
        return $value;
    }
}

function cancel_redirect(string $message, bool $success): void
{
    $status = $success ? 'success' : 'error';
    $location = '../dashboard.php?page=cancel-request&cancel=' . urlencode($status) . '&message=' . urlencode($message);
    header('Location: ' . $location);
    exit;
}
