<?php
declare(strict_types=1);

require_once __DIR__ . '/pswdo-common.php';
require_once __DIR__ . '/pswdo-data-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

pswdo_start_session();

// This endpoint is fetched by the already authenticated dashboard. Use the
// same shared session/role helpers as the page, but return JSON instead of a
// redirect so an AJAX failure can never replace the dashboard with login HTML.
if (!aics_has_role_session('pswdo')) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'authenticated' => false,
        'message' => 'Session expired.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionStatus = aics_refresh_session_status_for_role('pswdo');
if ($sessionStatus === null) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'authenticated' => true,
        'message' => 'Dashboard data is temporarily unavailable.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($sessionStatus !== 'active') {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'authenticated' => false,
        'message' => 'Session expired.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = pswdo_current_display_name();
$periodInput = (string) ($_GET['period'] ?? 'month');
$periodCode = pswdo_normalize_overview_period($periodInput);

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);

    $profile = pswdo_fetch_staff_profile($connection, $accountId, $displayName);
    $displayName = (string) ($profile['display_name'] ?? $displayName);
    $initials = (string) ($profile['initials'] ?? pswdo_initials($displayName));

    $metrics = pswdo_fetch_dashboard_metrics($connection);
    $periodMetrics = pswdo_fetch_period_metrics($connection, $periodCode);
    $periodTrend = pswdo_fetch_period_trend($connection, $periodCode);
    $latestRequestDate = pswdo_fetch_latest_request_date($connection);
    $topCases = pswdo_fetch_top_priority_cases($connection, 6);
    $notificationLogs = pswdo_fetch_notification_logs($connection, 6);
    $todayApproved = pswdo_fetch_daily_approved_list($connection, date('Y-m-d'));
    $todayApprovedCount = count($todayApproved);
    $workflowData = pswdo_fetch_workflow_dashboard_data($connection, 4);

    $connection->close();

    $generatedAt = date('c');
    $generatedAtLabel = pswdo_datetime_label(date('Y-m-d H:i:s'));

    foreach ($topCases as &$case) {
        $statusCode = (string) ($case['status_code'] ?? 'pending_review');
        $case['status_tone'] = pswdo_status_tone($statusCode);
    }
    unset($case);

    echo json_encode([
        'ok' => true,
        'generated_at' => $generatedAt,
        'generated_at_label' => $generatedAtLabel,
        'profile' => [
            'display_name' => $displayName,
            'initials' => $initials,
        ],
        'metrics' => [
            'total_requests' => (int) ($metrics['total_requests'] ?? 0),
            'pending_review' => (int) ($metrics['pending_review'] ?? 0),
            'for_interview' => (int) ($metrics['for_interview'] ?? 0),
            'under_review' => (int) ($metrics['under_review'] ?? 0),
            'approved' => (int) ($metrics['approved'] ?? 0),
            'not_approved' => (int) ($metrics['not_approved'] ?? 0),
            'released' => (int) ($metrics['released'] ?? 0),
            'incomplete_requirements' => (int) ($metrics['incomplete_requirements'] ?? 0),
            'documents_checked' => (int) ($metrics['documents_checked'] ?? 0),
            'documents_uploaded' => (int) ($metrics['documents_uploaded'] ?? 0),
            'interview_initial_total' => (int) (($metrics['for_interview'] ?? 0) + ($metrics['under_review'] ?? 0)),
        ],
        'today_approved_count' => $todayApprovedCount,
        'workflow' => $workflowData,
        'period' => [
            'code' => (string) ($periodMetrics['period_code'] ?? $periodCode),
            'label' => (string) ($periodMetrics['period_label'] ?? pswdo_overview_period_label($periodCode)),
            'date_from' => (string) ($periodMetrics['date_from'] ?? ''),
            'date_to' => (string) ($periodMetrics['date_to'] ?? ''),
        ],
        'graph' => [
            'submitted' => (int) (($periodMetrics['metrics']['total_submitted'] ?? 0)),
            'pending_review' => (int) (($periodMetrics['metrics']['pending_review'] ?? 0)),
            'interview_initial' => (int) (($periodMetrics['metrics']['interview_initial_total'] ?? 0)),
            'approved' => (int) (($periodMetrics['metrics']['approved'] ?? 0)),
            'not_approved' => (int) (($periodMetrics['metrics']['not_approved'] ?? 0)),
            'released' => (int) (($periodMetrics['metrics']['released'] ?? 0)),
            'cancelled' => (int) (($periodMetrics['metrics']['cancelled'] ?? 0)),
        ],
        'trend' => [
            'points' => (array) ($periodTrend['points'] ?? []),
            'max_submitted' => (int) ($periodTrend['max_submitted'] ?? 1),
            'latest_request_date' => $latestRequestDate,
        ],
        'top_cases' => $topCases,
        'notification_logs' => $notificationLogs,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to refresh dashboard data right now.',
    ], JSON_UNESCAPED_UNICODE);
}
