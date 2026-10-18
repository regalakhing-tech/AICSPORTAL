<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth-common.php';

aics_start_session();
if (!aics_is_logged_in_applicat()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$limit = min(5, max(0, (int) ($_GET['limit'] ?? 0)));
$connection = null;

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    aics_ensure_notification_read_schema($connection);
    $visibleNotifications = aics_applicant_notification_visibility_sql();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        $notificationId = (int) (is_array($input) ? ($input['notification_id'] ?? 0) : 0);
        if ($notificationId > 0) {
            $owned = $connection->prepare("SELECT n.id FROM aics_request_notifications n INNER JOIN aics_account_requests ar ON ar.request_id = n.request_id WHERE n.id = ? AND ar.account_id = ? AND {$visibleNotifications} LIMIT 1");
            $owned->bind_param('ii', $notificationId, $accountId);
            $owned->execute();
            $exists = $owned->get_result()?->num_rows === 1;
            $owned->close();
            if (!$exists) {
                throw new RuntimeException('Notification not found.');
            }
            $read = $connection->prepare('INSERT IGNORE INTO aics_applicant_notification_reads (account_id, notification_id) VALUES (?, ?)');
            $read->bind_param('ii', $accountId, $notificationId);
            $read->execute();
            $read->close();
        }
    }

    $count = $connection->prepare("SELECT COUNT(*) AS unread FROM aics_request_notifications n INNER JOIN aics_account_requests ar ON ar.request_id = n.request_id LEFT JOIN aics_applicant_notification_reads rd ON rd.notification_id = n.id AND rd.account_id = ar.account_id WHERE ar.account_id = ? AND rd.notification_id IS NULL AND {$visibleNotifications}");
    $count->bind_param('i', $accountId);
    $count->execute();
    $unread = (int) (($count->get_result()?->fetch_assoc()['unread'] ?? 0));
    $count->close();

    $notifications = [];
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $limit > 0) {
        $recent = $connection->prepare("SELECT n.id, n.notification_stage, n.message_text, n.sent_at, r.reference_no, CASE WHEN rd.notification_id IS NULL THEN 1 ELSE 0 END AS is_unread FROM aics_request_notifications n INNER JOIN aics_account_requests ar ON ar.request_id = n.request_id INNER JOIN aics_requests r ON r.id = n.request_id LEFT JOIN aics_applicant_notification_reads rd ON rd.notification_id = n.id AND rd.account_id = ar.account_id WHERE ar.account_id = ? AND {$visibleNotifications} ORDER BY n.sent_at DESC, n.id DESC LIMIT {$limit}");
        $recent->bind_param('i', $accountId);
        $recent->execute();
        $result = $recent->get_result();
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!is_array($row)) {
                break;
            }
            $message = trim((string) ($row['message_text'] ?? ''));
            $message = $message !== '' ? $message : 'You have a new notification update from PSWDO.';
            $notifications[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => trim((string) ($row['notification_stage'] ?? '')) ?: 'General Update',
                'preview' => mb_strimwidth($message, 0, 120, '...'),
                'sent_at' => trim((string) ($row['sent_at'] ?? '')),
                'reference_no' => trim((string) ($row['reference_no'] ?? '')),
                'is_unread' => (int) ($row['is_unread'] ?? 0) === 1,
            ];
        }
        $recent->close();
    }

    $connection->close();
    echo json_encode(['ok' => true, 'unread_count' => $unread, 'notifications' => $notifications], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        $connection->close();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to refresh notifications.']);
}
