<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/app-shell.php';
require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/application-rules.php';
require_once __DIR__ . '/../../includes/document-requirements.php';
require_once __DIR__ . '/../../includes/ai-submission-gate.php';

aics_start_session();

$scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$scriptDirectory = rtrim($scriptDirectory, '/');

$isPhpFilesRoute = str_ends_with($scriptDirectory, '/php_files');
$applicantsBaseUrl = $isPhpFilesRoute ? dirname($scriptDirectory) : $scriptDirectory;
$phpFilesBaseUrl = $isPhpFilesRoute ? $scriptDirectory : $scriptDirectory . '/php_files';
$dashboardUrl = $applicantsBaseUrl . '/dashboard.php';
$projectRootUrl = dirname(dirname($applicantsBaseUrl));
$assetCssVersion = (string) (filemtime(__DIR__ . '/../css files/applicant-pages.css') ?: time());
$assetJsVersion = (string) (filemtime(__DIR__ . '/../js files/applicant-pages.js') ?: time());
$currentAccountStatusCode = aics_current_session_status_code();
$forceBannedModal = aics_should_force_banned_modal();
$displayName = aics_current_session_display_name();
$initials = aics_current_session_initials();
$uploadStatus = (string) ($_GET['upload'] ?? '');
$uploadMessage = trim((string) ($_GET['message'] ?? ''));

$logoUrl = $projectRootUrl . '/asset/logo.png';
$cssUrl = $applicantsBaseUrl . '/css files/applicant-pages.css?v=' . urlencode($assetCssVersion);
$appJsUrl = $applicantsBaseUrl . '/js files/applicant-pages.js?v=' . urlencode($assetJsVersion);
$logoutUrl = $phpFilesBaseUrl . '/auth-logout.php';
$docUploadUrl = $phpFilesBaseUrl . '/documents-upload.php';

$assistanceLabels = [
    'medical' => 'Medical',
    'burial' => 'Burial',
    'disaster_victims' => 'Disaster Victims',
    'special_cases' => 'Special Cases',
    'educational_basic' => 'Educational Assistance — Basic Education',
    'high_inflation_affected' => 'Persons Affected by High Inflation Rate',
];

$assistanceIcons = [
    'medical' => 'notes-medical',
    'burial' => 'cross',
    'disaster_victims' => 'house-crack',
    'special_cases' => 'shield-heart',
    'educational_basic' => 'graduation-cap',
    'high_inflation_affected' => 'money-bill-trend-up',
];

$documentLabels = [];
foreach (aics_document_requirements() as $requirements) foreach ($requirements as $requirement) $documentLabels[$requirement['code']] = $requirement['label'];
$documentLabels += ['certificate_of_indigency' => 'Certificate of Indigency'];

$requiredByAssistance = array_map(static fn (array $requirements): array => array_map(static fn (array $requirement): string => $requirement['code'], $requirements), aics_document_requirements());

$requests = [];
$loadError = '';
$cooldownInfo = ['is_in_cooldown' => false, 'eligible_on' => '', 'days_remaining' => 0];

try {
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    if ($accountId > 0) {
        $connection = aics_db_connect();
        aics_ensure_auth_schema($connection);
        try {
            aics_ai_gate_sync_for_account($connection, $accountId);
        } catch (Throwable $exception) {
            error_log('AICS AI gate sync for applicant failed: category=' . $exception::class);
        }
        $hasDocReviewTable = ($connection->query("SHOW TABLES LIKE 'aics_request_document_reviews'")?->num_rows ?? 0) > 0;

        $requestsSql = <<<SQL
            SELECT
                r.id,
                r.reference_no,
                r.assistance_types_json,
                r.request_status,
                r.date_applied,
                r.created_at,
                r.updated_at,
                r.beneficiary_complete_name,
                r.ai_gate_status,
                r.ai_gate_eta_days,
                r.ai_gate_priority,
                r.ai_gate_updated_at,
                rs.scheduled_date,
                rs.scheduled_time,
                rs.venue,
                rs.release_batch,
                rs.release_status
            FROM aics_requests r
            INNER JOIN aics_account_requests ar ON ar.request_id = r.id
            LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
            WHERE ar.account_id = ?
            ORDER BY r.id DESC
        SQL;

        $requestsStmt = $connection->prepare($requestsSql);
        $requestsStmt->bind_param('i', $accountId);
        $requestsStmt->execute();
        $requestsResult = $requestsStmt->get_result();

        $requestIds = [];
        while ($row = $requestsResult ? $requestsResult->fetch_assoc() : null) {
            if (!is_array($row)) {
                break;
            }

            $requestId = (int) ($row['id'] ?? 0);
            if ($requestId <= 0) {
                continue;
            }

            $requestIds[] = $requestId;
            $assistanceType = status_first_assistance_type((string) ($row['assistance_types_json'] ?? '[]'));
            $statusMeta = status_request_meta((string) ($row['request_status'] ?? 'pending'));

            $requests[$requestId] = [
                'id' => $requestId,
                'reference_no' => (string) ($row['reference_no'] ?? ''),
                'beneficiary_name' => trim((string) ($row['beneficiary_complete_name'] ?? '')),
                'assistance_type' => $assistanceType,
                'assistance_label' => $assistanceLabels[$assistanceType] ?? ($assistanceType !== '' ? ucwords(str_replace('_', ' ', $assistanceType)) : 'Not set'),
                'assistance_icon' => $assistanceIcons[$assistanceType] ?? 'hand-holding-heart',
                'status_code' => (string) ($row['request_status'] ?? ''),
                'status_label' => (string) $statusMeta['label'],
                'status_timeline_level' => (int) $statusMeta['timeline_level'],
                'status_badge' => (string) $statusMeta['badge'],
                'date_applied_label' => status_fmt_date((string) ($row['date_applied'] ?? '')),
                'created_label' => status_fmt_datetime((string) ($row['created_at'] ?? '')),
                'updated_label' => status_fmt_datetime((string) ($row['updated_at'] ?? '')),
                'documents' => [],
                'scheduled_date'  => (string) ($row['scheduled_date'] ?? ''),
                'scheduled_time'  => (string) ($row['scheduled_time'] ?? ''),
                'venue'           => (string) ($row['venue'] ?? ''),
                'release_batch'   => (string) ($row['release_batch'] ?? ''),
                'release_status'  => (string) ($row['release_status'] ?? ''),
                'gate_status'     => (string) ($row['ai_gate_status'] ?? 'released'),
                'gate_eta_days'   => (int) ($row['ai_gate_eta_days'] ?? 0),
                'gate_eta'        => aics_ai_gate_eta_summary((string) ($row['ai_gate_priority'] ?? ''), (string) ($row['ai_gate_updated_at'] ?? '')),
                'gate_items'      => [],
            ];
        }
        $requestsStmt->close();
        foreach ($requests as $gateRequestId => $gateRequest) {
            if ($gateRequest['gate_status'] === 'held') {
                $requests[$gateRequestId]['gate_items'] = aics_ai_gate_applicant_items($connection, (int) $gateRequestId);
            } elseif ($gateRequest['gate_status'] === 'pending') {
                $requests[$gateRequestId]['gate_progress'] = aics_ai_gate_applicant_progress($connection, (int) $gateRequestId);
            }
        }

        if (count($requestIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
            $docsSql = $hasDocReviewTable
                ? <<<SQL
                    SELECT
                        d.request_id,
                        d.document_type,
                        d.original_name,
                        d.uploaded_at,
                        COALESCE(dr.is_checked, 0) AS is_checked,
                        COALESCE(dr.review_status, 'pending') AS review_status,
                        dr.remarks AS review_remarks,
                        dr.reviewed_at
                    FROM aics_request_documents d
                    LEFT JOIN aics_request_document_reviews dr ON dr.request_document_id = d.id
                    WHERE d.request_id IN ($placeholders)
                    ORDER BY d.uploaded_at DESC
                SQL
                : <<<SQL
                    SELECT
                        d.request_id,
                        d.document_type,
                        d.original_name,
                        d.uploaded_at,
                        0 AS is_checked,
                        'pending' AS review_status,
                        '' AS review_remarks,
                        NULL AS reviewed_at
                    FROM aics_request_documents d
                    WHERE d.request_id IN ($placeholders)
                    ORDER BY d.uploaded_at DESC
                SQL;
            $docsStmt = $connection->prepare($docsSql);

            $bindTypes = str_repeat('i', count($requestIds));
            $bindParams = [$bindTypes];
            foreach ($requestIds as $value) {
                $bindParams[] = $value;
            }
            $references = [];
            foreach ($bindParams as $index => $value) {
                $references[$index] = &$bindParams[$index];
            }
            call_user_func_array([$docsStmt, 'bind_param'], $references);

            $docsStmt->execute();
            $docsResult = $docsStmt->get_result();
            while ($doc = $docsResult ? $docsResult->fetch_assoc() : null) {
                if (!is_array($doc)) {
                    break;
                }
                $docRequestId = (int) ($doc['request_id'] ?? 0);
                if (!isset($requests[$docRequestId])) {
                    continue;
                }

                $docType = aics_normalize_document_type((string) ($doc['document_type'] ?? ''));
                if ($docType === '') {
                    continue;
                }

                if (!isset($requests[$docRequestId]['documents'][$docType])) {
                    $requests[$docRequestId]['documents'][$docType] = $doc;
                }
            }
            $docsStmt->close();
        }

        $cooldownInfo = aics_application_cooldown_state($connection, $accountId);
        $connection->close();
    }
} catch (Throwable $exception) {
    $loadError = 'Unable to load your live application status right now.';
}

$totalRequests = count($requests);

foreach ($requests as $requestId => $requestData) {
    $requiredTypes = $requiredByAssistance[$requestData['assistance_type']] ?? [];
    $requiredStates = [];
    $uploadedRequiredCount = 0;

    foreach ($requiredTypes as $requiredType) {
        $doc = $requestData['documents'][$requiredType] ?? null;
        $uploaded = is_array($doc);
        if ($uploaded) {
            $uploadedRequiredCount++;
        }

        $requiredStates[] = [
            'type' => $requiredType,
            'label' => $documentLabels[$requiredType] ?? ucwords(str_replace('_', ' ', $requiredType)),
            'uploaded' => $uploaded,
            'checked' => $uploaded ? (((int) ($doc['is_checked'] ?? 0)) === 1) : false,
            'file_name' => $uploaded ? (string) ($doc['original_name'] ?? '') : '',
            'uploaded_at_label' => $uploaded ? status_fmt_datetime((string) ($doc['uploaded_at'] ?? '')) : '',
            'review_status' => $uploaded ? strtolower(trim((string) ($doc['review_status'] ?? 'pending'))) : 'pending',
            'review_remarks' => $uploaded ? trim((string) ($doc['review_remarks'] ?? '')) : '',
            'reviewed_at_label' => $uploaded ? status_fmt_datetime((string) ($doc['reviewed_at'] ?? '')) : '',
        ];
    }

    $requiredCount = count($requiredTypes);

    $requests[$requestId]['required_count'] = $requiredCount;
    $requests[$requestId]['uploaded_required_count'] = $uploadedRequiredCount;
    $requests[$requestId]['required_document_states'] = $requiredStates;
    $requests[$requestId]['stage_rows'] = status_stage_rows(
        (string) ($requestData['status_code'] ?? ''),
        (int) ($requestData['status_timeline_level'] ?? 1),
        (string) ($requestData['created_label'] ?? ''),
        (string) ($requestData['updated_label'] ?? '')
    );
    $requests[$requestId]['stage_rows'] = status_gate_stage_override($requests[$requestId]['stage_rows'], (string) ($requestData['gate_status'] ?? 'released'));
}

function status_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function status_fmt_date(string $raw): string
{
    if ($raw === '' || $raw === '0000-00-00') {
        return 'Not available';
    }
    try {
        return (new DateTimeImmutable($raw))->format('F d, Y');
    } catch (Throwable $e) {
        return $raw;
    }
}

function status_fmt_datetime(string $raw): string
{
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return 'Not available';
    }
    try {
        return (new DateTimeImmutable($raw))->format('F d, Y h:i A');
    } catch (Throwable $e) {
        return $raw;
    }
}

function status_fmt_time(string $raw): string
{
    if ($raw === '' || $raw === '00:00:00') {
        return '';
    }
    try {
        return (new DateTimeImmutable($raw))->format('h:i A');
    } catch (Throwable) {
        return $raw;
    }
}

function status_first_assistance_type(string $json): string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || count($decoded) === 0) {
        return '';
    }
    return strtolower(trim((string) $decoded[0]));
}

/**
 * @return array{label:string,badge:string,timeline_level:int}
 */
/**
 * What the applicant does next once PSWDO has decided, shown in place of the
 * expected processing time: visit for further screening, wait for the
 * schedule text, come on the release date, or wait for a new date. Null while
 * PSWDO is still reviewing (the expected processing card shows) and after a
 * release or rejection (those have their own sections).
 *
 * @param array<string, mixed> $req
 * @return array{tone: string, icon: string, label: string, title: string, lines: list<string>}|null
 */
function status_stage_notice(array $req): ?array
{
    $status = strtolower(trim((string) ($req['status_code'] ?? '')));
    $release = strtolower(trim((string) ($req['release_status'] ?? '')));
    $date = (string) ($req['scheduled_date'] ?? '');
    if (in_array($status, ['approved', 'validated'], true)) {
        return ['tone' => 'approved', 'icon' => 'fa-building-circle-check', 'label' => 'Approved for further screening', 'title' => 'Visit the PSWDO Office', 'lines' => [
            'Any office day: Monday to Thursday, 8:00 AM - 11:00 AM or 1:00 PM - 4:00 PM.',
            'Bring the original copies of your requirements.',
        ]];
    }
    if (!in_array($status, ['for_release', 'for release'], true)) return null;
    if ($release === 'missed') {
        return ['tone' => 'missed', 'icon' => 'fa-calendar-xmark', 'label' => 'Release schedule missed', 'title' => 'Wait for a new schedule', 'lines' => [
            'PSWDO will text you a new date, time and place.',
        ]];
    }
    if ($date !== '' && $date !== '0000-00-00') {
        $time = status_fmt_time((string) ($req['scheduled_time'] ?? ''));
        $venue = trim((string) ($req['venue'] ?? ''));
        return ['tone' => 'scheduled', 'icon' => 'fa-calendar-day', 'label' => $release === 'ready_for_release' ? 'Your assistance is ready' : 'Release scheduled', 'title' => status_fmt_date($date) . ($time !== '' ? ', ' . $time : ''), 'lines' => [
            'Place: ' . ($venue !== '' ? $venue : 'PSWDO Office'),
            'Bring your valid ID and your requirements. Please arrive on time.',
        ]];
    }
    return ['tone' => 'waiting', 'icon' => 'fa-hourglass-half', 'label' => 'Further screening passed', 'title' => 'Wait for your release schedule', 'lines' => [
        'PSWDO will text you the date, time and place to receive your assistance.',
    ]];
}

function status_request_meta(string $status): array
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'pending', 'for_review', 'for validation', 'for_validation' => [
            'label' => 'Pending Review',
            'badge' => 'pending',
            'timeline_level' => 1,
        ],
        'incomplete_requirements', 'incomplete requirements' => [
            'label' => 'Incomplete Requirements',
            'badge' => 'pending',
            'timeline_level' => 2,
        ],
        'for_interview', 'for interview', 'for interview/further screening', 'for interview / further screening' => [
            'label' => 'For Interview / Further Screening',
            'badge' => 'info',
            'timeline_level' => 2,
        ],
        'under assessment', 'under_assessment', 'assessment', 'in_review' => [
            'label' => 'Under Assessment',
            'badge' => 'info',
            'timeline_level' => 3,
        ],
        'under_review', 'under review' => [
            'label' => 'Under Review',
            'badge' => 'info',
            'timeline_level' => 3,
        ],
        'approved', 'validated' => [
            'label' => 'Approved',
            'badge' => 'ok',
            'timeline_level' => 4,
        ],
        'for_release', 'for release' => [
            'label' => 'Release Scheduled',
            'badge' => 'pending',
            'timeline_level' => 4,
        ],
        'released', 'distributed', 'completed', 'closed' => [
            'label' => 'Released',
            'badge' => 'ok',
            'timeline_level' => 5,
        ],
        'cancelled', 'canceled' => [
            'label' => 'Cancelled',
            'badge' => 'danger',
            'timeline_level' => 5,
        ],
        'rejected', 'denied', 'not_approved', 'not approved' => [
            'label' => 'Rejected',
            'badge' => 'danger',
            'timeline_level' => 5,
        ],
        default => [
            'label' => ucwords(str_replace('_', ' ', $normalized === '' ? 'pending' : $normalized)),
            'badge' => 'info',
            'timeline_level' => 1,
        ],
    };
}

/**
 * Until AICS AI passes the documents, step 2 describes the AI check rather
 * than PSWDO validation, which has not started yet.
 *
 * @param array<int, array{title:string,meta:string,badge:string,badge_label:string}> $rows
 * @return array<int, array{title:string,meta:string,badge:string,badge_label:string}>
 */
function status_gate_stage_override(array $rows, string $gateStatus): array
{
    $step = match ($gateStatus) {
        'pending' => ['meta' => 'AICS AI is checking your documents.', 'badge' => 'pending', 'badge_label' => 'Checking'],
        'held' => ['meta' => 'Replace the document AICS AI could not accept.', 'badge' => 'attention', 'badge_label' => 'Action Needed'],
        'admin_review' => ['meta' => 'Your documents are being reviewed before they go to PSWDO.', 'badge' => 'review', 'badge_label' => 'In Review'],
        default => null,
    };
    if ($step !== null && isset($rows[1])) $rows[1] = array_merge($rows[1], $step);
    return $rows;
}

/**
 * @return array<int, array{title:string,meta:string,badge:string,badge_label:string}>
 */
function status_stage_rows(string $statusCode, int $timelineLevel, string $createdLabel, string $updatedLabel): array
{
    $normalized = strtolower(trim($statusCode));
    $isTerminalCancelled = in_array($normalized, ['cancelled', 'canceled'], true);
    $isTerminalRejected = in_array($normalized, ['rejected', 'denied', 'not_approved', 'not approved'], true);
    $isIncomplete = in_array($normalized, ['incomplete_requirements', 'incomplete requirements'], true);
    $isForInterview = in_array($normalized, ['for_interview', 'for interview', 'for interview/further screening', 'for interview / further screening'], true);

    $rows = [
        [
            'title' => 'Request Submitted',
            'meta' => $createdLabel !== '' ? $createdLabel : 'Submitted',
            'badge' => 'ok',
            'badge_label' => 'Completed',
        ],
        [
            'title' => 'Initial Validation',
            'meta' => $isIncomplete
                ? 'Requirements were marked incomplete by PSWDO.'
                : ($isForInterview
                    ? 'Application is ready for office interview and further screening.'
                    : ($timelineLevel >= 2 ? 'Initial validation completed.' : 'Waiting for validation team review.')),
            'badge' => $timelineLevel >= 2 ? 'ok' : 'pending',
            'badge_label' => $isIncomplete ? 'Needs Completion' : ($timelineLevel >= 2 ? 'Completed' : 'In Progress'),
        ],
        [
            'title' => 'Case Assessment',
            'meta' => $timelineLevel >= 3
                ? 'Case assessment completed.'
                : ($isForInterview
                    ? 'Waiting for beneficiary office appearance/interview.'
                    : ($timelineLevel === 2 ? 'Assessment is currently in progress.' : 'Assessment will begin after validation.')),
            'badge' => $timelineLevel >= 3 ? 'ok' : ($isForInterview ? 'info' : ($timelineLevel === 2 ? 'pending' : 'info')),
            'badge_label' => $timelineLevel >= 3 ? 'Completed' : ($isForInterview ? 'For Interview' : ($timelineLevel === 2 ? 'In Progress' : 'Pending')),
        ],
        [
            'title' => 'Final Decision',
            'meta' => $updatedLabel !== '' ? ('Last update: ' . $updatedLabel) : 'Final decision is pending.',
            'badge' => $timelineLevel >= 4 ? 'ok' : 'info',
            'badge_label' => $timelineLevel >= 4 ? 'Completed' : 'Pending',
        ],
    ];

    if ($isTerminalCancelled) {
        $rows[3]['badge'] = 'danger';
        $rows[3]['badge_label'] = 'Cancelled';
        $rows[3]['meta'] = $updatedLabel !== '' ? ('Request cancelled on ' . $updatedLabel) : 'Request has been cancelled.';
    } elseif ($isTerminalRejected) {
        $rows[3]['badge'] = 'danger';
        $rows[3]['badge_label'] = 'Rejected';
        $rows[3]['meta'] = $updatedLabel !== '' ? ('Request marked as rejected on ' . $updatedLabel) : 'Request has been rejected.';
    }

    return $rows;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AICS Applicant | Application Status</title>
<link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
<link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= status_h($cssUrl) ?>">
<?= aics_app_shell_head() ?>
<style>
/* ==========================================================================
   Application Status Page - Modern Executive Design
   ========================================================================== */

/* ---------- Hero Banner ---------- */
.status-page .status-hero-card {
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 26px 30px;
  border-radius: 22px;
  border: 1px solid #bae6fd;
  background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 55%, #e0f2fe 100%);
  box-shadow: 0 12px 32px rgba(2, 132, 199, 0.08);
  margin-bottom: 22px;
}

.status-page .status-hero-card::after {
  content: '';
  position: absolute;
  top: -60px;
  right: -60px;
  width: 220px;
  height: 220px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(56, 189, 248, 0.18) 0%, rgba(2, 132, 199, 0) 70%);
  pointer-events: none;
}

.status-page .status-hero-main {
  display: flex;
  align-items: center;
  gap: 20px;
  position: relative;
  z-index: 1;
  flex: 1;
}

.status-page .status-hero-icon-pill {
  width: 58px;
  height: 58px;
  border-radius: 18px;
  background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
  display: grid;
  place-items: center;
  font-size: 24px;
  color: #ffffff;
  box-shadow: 0 8px 20px rgba(2, 132, 199, 0.28);
  flex-shrink: 0;
}

.status-page .status-hero-copy {
  flex: 1;
  min-width: 0;
}

.status-page .status-hero-meta-badge {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(2, 132, 199, 0.08);
  border: 1px solid rgba(2, 132, 199, 0.22);
  color: #0369a1;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.02em;
  margin-bottom: 8px;
}

.status-page .status-live-pulse-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
  animation: liveStatusPulse 2s infinite cubic-bezier(0.4, 0, 0.6, 1);
}

@keyframes liveStatusPulse {
  0% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
  }
  70% {
    transform: scale(1);
    box-shadow: 0 0 0 6px rgba(16, 185, 129, 0);
  }
  100% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
  }
}

.status-page .status-hero-card .page-title {
  font-size: clamp(21px, 2.2vw, 27px);
  font-weight: 800;
  color: #0b1220;
  margin: 0 0 6px;
  line-height: 1.25;
  letter-spacing: -0.02em;
}

.status-page .status-hero-card .page-sub {
  font-size: 14.5px;
  color: #475569;
  line-height: 1.55;
  margin: 0;
  max-width: 82ch;
}

/* ---------- Cooldown Banner ---------- */
.status-page .senior-cooldown-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 22px;
  border-radius: 18px;
  background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%);
  border: 1px solid #fecdd3;
  border-left: 5px solid #e11d48;
  box-shadow: 0 8px 24px rgba(225, 29, 72, 0.07);
  margin-bottom: 22px;
}

.status-page .cooldown-icon-badge {
  width: 44px;
  height: 44px;
  border-radius: 13px;
  background: #ffffff;
  display: grid;
  place-items: center;
  font-size: 20px;
  color: #e11d48;
  box-shadow: 0 4px 12px rgba(225, 29, 72, 0.12);
  flex-shrink: 0;
}

.status-page .cooldown-copy {
  flex: 1;
  min-width: 0;
}

.status-page .cooldown-title {
  display: block;
  font-size: 15px;
  font-weight: 800;
  color: #9f1239;
  margin-bottom: 3px;
}

.status-page .cooldown-detail {
  font-size: 13.5px;
  color: #881337;
  line-height: 1.5;
  font-weight: 500;
}

.status-page .cooldown-detail strong {
  color: #4c0519;
  font-weight: 700;
}

.status-page .cooldown-tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: 999px;
  background: rgba(225, 29, 72, 0.1);
  border: 1px solid rgba(225, 29, 72, 0.2);
  color: #be123c;
  font-size: 12.5px;
  font-weight: 700;
  white-space: nowrap;
  flex-shrink: 0;
}

/* ---------- Request Card Block ---------- */
.status-page .docs-request-block {
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 22px;
  padding: 24px 28px;
  box-shadow: 0 8px 26px rgba(15, 23, 42, 0.05);
  transition: box-shadow 0.25s ease, border-color 0.25s ease;
  margin-bottom: 24px;
}

.status-page .docs-request-block:hover {
  box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
  border-color: #cbd5e1;
}

.status-page .docs-request-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  padding-bottom: 20px;
  margin-bottom: 22px;
  border-bottom: 1.5px dashed #e2e8f0;
  flex-wrap: wrap;
}

.status-page .docs-request-title-wrap {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  min-width: 0;
  flex: 1 1 340px;
}

.status-page .docs-request-icon {
  width: 56px;
  height: 56px;
  border-radius: 17px;
  background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
  display: grid;
  place-items: center;
  font-size: 24px;
  color: #ffffff;
  box-shadow: 0 8px 22px rgba(2, 132, 199, 0.25);
  flex-shrink: 0;
}

.status-page .docs-request-title-copy {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.status-page .docs-request-type-eyebrow {
  font-size: 11.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #0284c7;
  margin-bottom: 2px;
}

.status-page .docs-request-title {
  font-size: 21px;
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.02em;
  line-height: 1.25;
  margin: 0 0 10px;
}

.status-page .docs-request-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 10px;
}

.status-page .status-meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 6px 13px;
  border-radius: 999px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  font-size: 13px;
  color: #475569;
  font-weight: 500;
  transition: all 0.2s ease;
}

.status-page .status-meta-chip:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
}

.status-page .status-meta-chip i {
  font-size: 12px;
  color: #64748b;
}

.status-page .status-meta-chip.chip-ref {
  background: #f0f9ff;
  border-color: #bae6fd;
  color: #0369a1;
  font-weight: 700;
}

.status-page .status-meta-chip.chip-ref i {
  color: #0284c7;
}

.status-page .status-meta-chip.chip-user i { color: #3b82f6; }
.status-page .status-meta-chip.chip-date i { color: #8b5cf6; }
.status-page .status-meta-chip.chip-docs i { color: #10b981; }

.status-page .docs-request-status {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  padding: 9px 18px;
  border-radius: 999px;
  font-size: 13.5px;
  font-weight: 700;
  letter-spacing: 0.01em;
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fde68a;
  box-shadow: 0 2px 10px rgba(245, 158, 11, 0.12);
  white-space: nowrap;
  flex-shrink: 0;
}

.status-page .docs-request-status.status-pill-ok,
.status-page .docs-request-status.is-complete {
  background: #dcfce7;
  color: #166534;
  border-color: #bbf7d0;
  box-shadow: 0 2px 10px rgba(22, 163, 74, 0.12);
}

.status-page .docs-request-status.status-pill-danger {
  background: #fee2e2;
  color: #991b1b;
  border-color: #fecaca;
  box-shadow: 0 2px 10px rgba(220, 38, 38, 0.12);
}

.status-page .status-dot-pulse {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
  position: relative;
  display: inline-block;
}

.status-page .status-dot-pulse::after {
  content: '';
  position: absolute;
  inset: -3px;
  border-radius: 50%;
  border: 1.5px solid currentColor;
  opacity: 0.6;
  animation: pulseDotRing 1.8s cubic-bezier(0.25, 0, 0.2, 1) infinite;
}

@keyframes pulseDotRing {
  0% { transform: scale(0.8); opacity: 0.8; }
  100% { transform: scale(2); opacity: 0; }
}

/* ---------- 4-Stage Application Progress Stepper ---------- */
.status-page .status-stages-block {
  margin-top: 24px;
}

.status-page .section-title-wrap {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px 16px;
  margin-bottom: 14px;
}

.status-page .section-title {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  font-size: 16px;
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.01em;
  margin: 0;
}

.status-page .section-title i {
  color: #0284c7;
  font-size: 15px;
}

.status-page .section-title-hint {
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
}

.status-page .status-progress-tracker {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  position: relative;
  margin-top: 14px;
}

/* Timeline card */
.status-page .status-stage {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 18px 20px;
  border-radius: 18px;
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.03);
  transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  min-height: 160px;
}

.status-page .status-stage:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.07);
}

.status-page .stage-top-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 12px;
}

.status-page .stage-indicator-pill {
  display: flex;
  align-items: center;
  gap: 8px;
}

.status-page .stage-icon-circle {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 13px;
  font-weight: 800;
  flex-shrink: 0;
}

.status-page .stage-step-tag {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #64748b;
}

/* Stage: OK (Completed) */
.status-page .status-stage-ok {
  border-color: #86efac;
  background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%);
}

.status-page .status-stage-ok .stage-icon-circle.icon-ok {
  background: #16a34a;
  color: #ffffff;
  box-shadow: 0 2px 8px rgba(22, 163, 74, 0.28);
}

.status-page .status-stage-ok .stage-step-tag {
  color: #15803d;
}

.status-page .status-stage-ok .badge.ok {
  background: #dcfce7;
  color: #15803d;
  border: 1px solid #bbf7d0;
  font-size: 11px;
  font-weight: 800;
  padding: 4px 10px;
  border-radius: 999px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Stage: Pending (Active / In-progress) */
.status-page .status-stage-pending {
  border-color: #0284c7;
  background: linear-gradient(180deg, #f0f9ff 0%, #ffffff 100%);
  box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12), 0 8px 24px rgba(2, 132, 199, 0.09);
}

.status-page .status-stage-pending .stage-icon-circle.icon-pending {
  position: relative;
  background: #0284c7;
  color: #ffffff;
  box-shadow: 0 2px 10px rgba(2, 132, 199, 0.35);
}

.status-page .stage-pulse-beacon {
  position: absolute;
  inset: -3px;
  border-radius: 50%;
  border: 2px solid #0284c7;
  opacity: 0.6;
  animation: pulseDotRing 1.8s infinite;
}

.status-page .status-stage-pending .stage-step-tag {
  color: #0284c7;
  font-weight: 800;
}

.status-page .status-stage-pending .badge.pending {
  background: #e0f2fe;
  color: #0284c7;
  border: 1px solid #bae6fd;
  font-size: 11px;
  font-weight: 800;
  padding: 4px 10px;
  border-radius: 999px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Stage: Info (Upcoming / Queued) */
.status-page .status-stage-info {
  border-color: #e2e8f0;
  background: #f8fafc;
}

.status-page .status-stage-info .stage-icon-circle.icon-muted {
  background: #e2e8f0;
  color: #64748b;
}

.status-page .status-stage-info .badge.info {
  background: #f1f5f9;
  color: #64748b;
  border: 1px solid #e2e8f0;
  font-size: 11px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 999px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Stage: Danger (Cancelled / Rejected) */
.status-page .status-stage-danger {
  border-color: #fca5a5;
  background: #fef2f2;
}

.status-page .status-stage-danger .stage-icon-circle.icon-danger {
  background: #dc2626;
  color: #ffffff;
  box-shadow: 0 2px 8px rgba(220, 38, 38, 0.28);
}

.status-page .status-stage-danger .badge.danger {
  background: #fee2e2;
  color: #991b1b;
  border: 1px solid #fecaca;
  font-size: 11px;
  font-weight: 800;
  padding: 4px 10px;
  border-radius: 999px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.status-page .status-stage strong.stage-title,
.status-page .status-stage strong {
  font-size: 15px;
  font-weight: 800;
  color: #0f172a;
  line-height: 1.3;
  margin: 4px 0 6px;
  display: block;
}

.status-page .status-stage-pending strong {
  color: #0369a1;
}

.status-page .status-stage .timeline-meta,
.status-page .status-stage .stage-meta {
  font-size: 13px;
  color: #475569;
  line-height: 1.5;
  font-weight: 500;
  flex: 1;
}

/* ---------- Release Schedule Block ---------- */
.status-page .release-schedule-block {
  margin-top: 22px;
}

.status-page .release-schedule-block .section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #1d4ed8;
}

.status-page .release-schedule-block.released .section-title {
  color: #15803d;
}

.status-page .release-schedule-card {
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 16px;
  padding: 18px 22px;
  margin-top: 10px;
  box-shadow: 0 4px 14px rgba(29, 78, 216, 0.04);
}

.status-page .release-schedule-card.released-card {
  background: #f0fdf4;
  border-color: #bbf7d0;
  box-shadow: 0 4px 14px rgba(21, 128, 61, 0.04);
}

.status-page .release-schedule-note {
  margin: 0 0 14px;
  font-size: 13.5px;
  color: #1e40af;
  font-weight: 500;
}

.status-page .release-schedule-row {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  margin-bottom: 12px;
}

.status-page .release-schedule-row:last-child {
  margin-bottom: 0;
}

.status-page .release-schedule-row > i {
  color: #3b82f6;
  font-size: 16px;
  margin-top: 3px;
  width: 20px;
  text-align: center;
  flex-shrink: 0;
}

.status-page .release-schedule-card.released-card .release-schedule-row > i {
  color: #16a34a;
}

.status-page .release-schedule-row > div {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.status-page .release-schedule-row > div strong {
  font-size: 11.5px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #64748b;
  font-weight: 700;
}

.status-page .release-schedule-row > div span {
  font-size: 14px;
  font-weight: 600;
  color: #1e293b;
}

/* ---------- Document Checklist Section ---------- */
.status-page .status-checklist-block {
  margin-top: 28px;
  padding-top: 24px;
  border-top: 1.5px dashed #e2e8f0;
}

.status-page .document-progress-senior {
  margin-bottom: 16px;
}

.status-page .document-progress-senior .progress-track-senior {
  height: 8px;
  background: #f1f5f9;
  border-radius: 999px;
  overflow: hidden;
}

.status-page .document-progress-senior .progress-track-senior span {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #0284c7 0%, #10b981 100%);
  transition: width 0.6s ease;
}

.status-page .document-progress-senior .progress-meta-senior {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13.5px;
  color: #475569;
  margin-top: 8px;
  font-weight: 600;
}

.status-page .document-progress-senior .progress-meta-senior i {
  color: #0284c7;
}

.status-page .document-checklist-senior {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.status-page .document-check-senior {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px 20px;
  border-radius: 16px;
  border: 1.5px solid #e2e8f0;
  background: #ffffff;
  transition: all 0.2s ease;
}

.status-page .document-check-senior:hover {
  border-color: #cbd5e1;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
}

.status-page .document-check-senior.is-uploaded {
  background: #f0fdf4;
  border-color: #bbf7d0;
}

.status-page .document-check-senior.is-pending {
  background: #fffdf5;
  border-color: #fde68a;
}

.status-page .document-check-icon {
  width: 44px;
  height: 44px;
  border-radius: 13px;
  display: grid;
  place-items: center;
  font-size: 19px;
  flex-shrink: 0;
}

.status-page .document-check-senior.is-uploaded .document-check-icon {
  background: #dcfce7;
  color: #15803d;
}

.status-page .document-check-senior.is-pending .document-check-icon {
  background: #fef3c7;
  color: #a16207;
}

.status-page .document-check-copy {
  flex: 1;
  min-width: 0;
}

.status-page .document-check-copy strong {
  display: block;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 3px;
}

.status-page .document-check-copy span {
  font-size: 13px;
  color: #475569;
  line-height: 1.45;
}

.status-page .document-remarks-banner {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin: 6px 0 0 60px;
  padding: 10px 14px;
  border-radius: 10px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #92400e;
  font-size: 12.5px;
}

.status-page .document-remarks-banner i {
  margin-top: 2px;
  color: #d97706;
}

/* ---------- Responsive Breakpoints ---------- */
@media (max-width: 1024px) {
  .status-page .status-progress-tracker {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .status-page .status-hero-card {
    padding: 20px 20px;
  }
  .status-page .status-hero-main {
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
  }
  .status-page .senior-cooldown-banner {
    flex-direction: column;
    align-items: flex-start;
  }
  .status-page .docs-request-block {
    padding: 20px 18px;
  }
  .status-page .docs-request-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
  }
  .status-page .docs-request-status {
    width: 100%;
    justify-content: center;
  }
  .status-page .document-check-senior {
    flex-wrap: wrap;
    gap: 12px;
  }
  .status-page .document-check-copy {
    width: calc(100% - 60px);
  }
}

@media (max-width: 580px) {
  .status-page .status-progress-tracker {
    grid-template-columns: 1fr;
  }
  .status-page .status-stage {
    min-height: auto;
  }
  .status-page .document-remarks-banner {
    margin-left: 0;
  }
}
</style>
</head>
<body data-account-status="<?= status_h($currentAccountStatusCode) ?>" data-banned-modal-required="<?= $forceBannedModal ? '1' : '0' ?>">
<div class="app-container">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a href="<?= status_h($dashboardUrl) ?>?page=overview" class="logo" style="padding-top: 4px;">
        <img src="<?= status_h($logoUrl) ?>" alt="AICS | PSWDO Logo" style="height: 42px; width: auto; object-fit: contain;">
      </a>
      <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
    </div>

    <div class="nav-menu">
      <div class="nav-header">Main Menu</div>
      <a href="<?= status_h($dashboardUrl) ?>?page=overview" class="nav-item "><i class="fas fa-border-all"></i> Overview Dashboard</a>
      <a href="<?= status_h($dashboardUrl) ?>?page=profile" class="nav-item "><i class="fas fa-user-circle"></i> My Profile</a>

      <div class="nav-header nav-header-gap">Application</div>
      <a href="<?= status_h($dashboardUrl) ?>?page=submit" class="nav-item" data-submit-request-nav data-submit-request-href="<?= status_h($dashboardUrl) ?>?page=submit"><i class="fas fa-file-signature"></i> Submit Application</a>
      <a href="<?= status_h($dashboardUrl) ?>?page=documents" class="nav-item "><i class="fas fa-file-invoice"></i> Documents</a>
      <a href="<?= status_h($dashboardUrl) ?>?page=status" class="nav-item active"><i class="fas fa-route"></i> Application Status</a>
      <a href="<?= status_h($dashboardUrl) ?>?page=cancel-request" class="nav-item "><i class="fas fa-pen-to-square"></i> Manage Applications</a>

      <div class="nav-header nav-header-gap">Support</div>
      <a href="<?= status_h($dashboardUrl) ?>?page=notifications" class="nav-item "><i class="fas fa-bell"></i> Notifications</a>
    </div>

    <div class="sidebar-footer">
      <a href="<?= status_h($logoutUrl) ?>" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </aside>

  <div class="sidebar-overlay" id="overlay"></div>

  <main class="main-content" id="main-content">
    <header class="top-nav">
      <div class="nav-left">
        <div class="top-nav-page-info">
          <span class="top-nav-badge"><i class="fas fa-route"></i></span>
          <div class="top-nav-text">
            <span class="top-nav-sub">Application</span>
            <h1 class="top-nav-heading">Application Status</h1>
          </div>
        </div>
      </div>

      <div class="nav-right">
        <a class="btn-icon" data-notification-trigger href="<?= status_h($dashboardUrl) ?>?page=notifications" aria-label="Notifications" aria-expanded="false">
          <i class="fas fa-bell"></i>
          <span class="dot" hidden aria-hidden="true"></span>
        </a>
        <div class="profile-chip">
          <div class="profile-av"><?= status_h($initials) ?></div>
          <span><?= status_h($displayName) ?></span>
        </div>
      </div>
    </header>

    <div class="content-area content-area-senior status-page">
      <section class="section-card docs-hero-senior status-hero-card">
        <div class="docs-hero-main status-hero-main">
          <div class="status-hero-icon-pill">
            <i class="fas fa-route"></i>
          </div>
          <div class="docs-hero-copy status-hero-copy">
            <div class="status-hero-meta-badge">
              <span class="status-live-pulse-dot"></span>
              <span>Live Application Tracker</span>
            </div>
            <h1 class="page-title">Track Request and Document Progress</h1>
            <p class="page-sub">Monitor your real-time application stages, scheduled assistance releases, and PSWDO document verification checklist in one secure portal.</p>
          </div>
        </div>
      </section>

      <?php if ($loadError !== ''): ?>
        <div class="senior-alert senior-alert-error">
          <i class="fas fa-triangle-exclamation"></i>
          <span><?= status_h($loadError) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($uploadStatus !== '' && $uploadMessage !== ''): ?>
        <div class="senior-alert <?= $uploadStatus === 'success' ? 'senior-alert-success' : 'senior-alert-error' ?>">
          <i class="fas fa-<?= $uploadStatus === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
          <span><?= status_h($uploadMessage) ?></span>
        </div>
      <?php endif; ?>
      <?php if (!empty($cooldownInfo['is_in_cooldown'])): ?>
        <div class="senior-alert senior-alert-error senior-cooldown-banner" role="status">
          <div class="cooldown-icon-badge">
            <i class="fas fa-clock-rotate-left"></i>
          </div>
          <div class="cooldown-copy">
            <strong class="cooldown-title">Application Cooldown Period Active</strong>
            <span class="cooldown-detail"><span class="cooldown-policy">Under PSWDO policy, an annual interval applies between assistance grants.</span> <span>Your next eligible submission date is</span> <strong><?= status_h(aics_format_cooldown_date((string) ($cooldownInfo['eligible_on'] ?? ''))) ?></strong>.</span>
          </div>
          <div class="cooldown-tag">
            <i class="fas fa-hourglass-half"></i> Active Cooldown
          </div>
        </div>
      <?php endif; ?>

      <?php if ($totalRequests === 0): ?>
        <section class="section-card docs-empty-card">
          <div class="docs-empty-illustration" aria-hidden="true">
            <i class="fas fa-file-signature"></i>
          </div>
          <h2>No request to track yet</h2>
          <p>Submit your first request and this page will show live progress and document checklist updates.</p>
          <a class="btn-senior btn-senior-primary" href="<?= status_h($dashboardUrl) ?>?page=submit" data-submit-request-nav data-submit-request-href="<?= status_h($dashboardUrl) ?>?page=submit">
            <i class="fas fa-file-signature"></i>
            <span>Submit a Request</span>
          </a>
        </section>
      <?php else: ?>
        <div class="docs-requests-list">
          <?php foreach ($requests as $req): ?>
            <?php
              $requiredCount = (int) ($req['required_count'] ?? 0);
              $uploadedRequiredCount = (int) ($req['uploaded_required_count'] ?? 0);
              $statusBadge = (string) ($req['status_badge'] ?? 'pending');
              $statusLabel = (string) ($req['status_label'] ?? 'Pending');
              $stageRows = is_array($req['stage_rows'] ?? null) ? $req['stage_rows'] : [];
              $requiredStates = is_array($req['required_document_states'] ?? null) ? $req['required_document_states'] : [];
            ?>
            <section class="docs-request-block">
              <header class="docs-request-head">
                <div class="docs-request-title-wrap">
                  <div class="docs-request-icon tone-brand">
                    <i class="fas fa-<?= status_h((string) ($req['assistance_icon'] ?? 'hand-holding-heart')) ?>"></i>
                  </div>
                  <div class="docs-request-title-copy">
                    <div class="docs-request-type-eyebrow">AICS Assistance Request</div>
                    <h2 class="docs-request-title"><?= status_h((string) ($req['assistance_label'] ?? 'Assistance')) ?> Request</h2>
                    <div class="docs-request-meta">
                      <span class="status-meta-chip chip-ref"><i class="fas fa-hashtag"></i> <strong><?= status_h((string) ($req['reference_no'] ?? '-')) ?></strong></span>
                      <span class="status-meta-chip chip-user"><i class="fas fa-user"></i> <?= status_h((string) ($req['beneficiary_name'] ?? '-')) ?></span>
                      <span class="status-meta-chip chip-date"><i class="fas fa-calendar-day"></i> Applied: <?= status_h((string) ($req['date_applied_label'] ?? 'Not available')) ?></span>
                    </div>
                  </div>
                </div>
                <div class="docs-request-status status-pill-<?= status_h($statusBadge) ?><?= $statusBadge === 'ok' ? ' is-complete' : '' ?>">
                  <span class="status-dot-pulse"></span>
                  <i class="fas fa-<?= $statusBadge === 'ok' ? 'circle-check' : ($statusBadge === 'danger' ? 'circle-exclamation' : 'circle-info') ?>"></i>
                  <span><?= status_h($statusLabel) ?></span>
                </div>
              </header>

              <?php $gateStatus = (string) ($req['gate_status'] ?? 'released'); ?>
              <?php if ($gateStatus === 'held'): ?>
                <?php $gateItems = (array) ($req['gate_items'] ?? []); ?>
                <div class="ai-gate-notice ai-gate-hold-banner" role="alert">
                  <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                  <div>
                    <strong><?= count($gateItems) === 1 ? 'Replace this document to continue' : 'Replace these documents to continue' ?></strong>
                    <p class="ai-gate-hold-lead">AICS AI checked your application and could not accept <?= count($gateItems) === 1 ? 'one document' : count($gateItems) . ' documents' ?>. Replace <?= count($gateItems) === 1 ? 'it' : 'them' ?> here and AICS AI will check again right away.</p>
                    <ul class="ai-gate-fix-list">
                      <?php foreach ($gateItems as $gateItem): ?>
                        <li class="ai-gate-fix">
                          <span class="ai-gate-fix-name"><?= status_h((string) ($gateItem['label'] ?? 'Document')) ?></span>
                          <span class="ai-gate-fix-text"><?= status_h((string) ($gateItem['fix'] ?? 'Take a new, sharp photo in good light that shows the whole page.')) ?></span>
                          <form method="post" action="<?= status_h($docUploadUrl) ?>" enctype="multipart/form-data" class="ai-gate-fix-form">
                            <input type="hidden" name="action_mode" value="reupload_single">
                            <input type="hidden" name="request_id" value="<?= (int) ($req['id'] ?? 0) ?>">
                            <input type="hidden" name="document_type" value="<?= status_h((string) ($gateItem['type'] ?? '')) ?>">
                            <label class="ai-gate-fix-button">
                              <i class="fas fa-upload" aria-hidden="true"></i>
                              <span class="ai-gate-fix-button-text" data-upload-text>Upload a new file</span>
                              <input type="file" name="reupload_file" accept=".pdf,.jpg,.jpeg,.png" required aria-label="Upload a new <?= status_h((string) ($gateItem['label'] ?? 'document')) ?>">
                            </label>
                          </form>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              <?php elseif ($gateStatus === 'pending'): ?>
                <?php
                  $gateProgress = is_array($req['gate_progress'] ?? null) ? $req['gate_progress'] : ['total' => 0, 'checked' => 0, 'documents' => []];
                  $gateIcons = ['done' => 'fas fa-circle-check', 'checking' => 'fas fa-circle-half-stroke', 'waiting' => 'far fa-circle'];
                ?>
                <section class="ai-check-live" data-progress-url="ai-check-progress.php?request_id=<?= (int) ($req['id'] ?? 0) ?>" aria-labelledby="ai-check-title-<?= (int) ($req['id'] ?? 0) ?>">
                  <h3 class="ai-check-title" id="ai-check-title-<?= (int) ($req['id'] ?? 0) ?>">Checking your documents</h3>
                  <p class="ai-check-lead">AICS AI is checking your documents. This usually takes 1 to 3 minutes.</p>
                  <div class="ai-check-track" aria-hidden="true">
                    <?php foreach ($gateProgress['documents'] as $gateDoc): ?>
                      <span class="ai-check-segment is-<?= status_h((string) $gateDoc['state']) ?>" style="--fill: <?= (int) $gateDoc['percent'] ?>%"></span>
                    <?php endforeach; ?>
                  </div>
                  <p class="ai-check-count" role="status" aria-live="polite"><strong><span data-ai-checked><?= (int) $gateProgress['checked'] ?></span> of <span data-ai-total><?= (int) $gateProgress['total'] ?></span></strong> documents checked</p>
                  <ul class="ai-check-list">
                    <?php foreach ($gateProgress['documents'] as $gateDoc): ?>
                      <li class="ai-check-item is-<?= status_h((string) $gateDoc['state']) ?>">
                        <i class="ai-check-icon <?= status_h($gateIcons[(string) $gateDoc['state']] ?? 'far fa-circle') ?>" aria-hidden="true"></i>
                        <span class="ai-check-name"><?= status_h((string) $gateDoc['label']) ?></span>
                        <span class="ai-check-step"><?= status_h((string) $gateDoc['status_text']) ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                  <p class="ai-check-note" data-ai-note>You can leave this page. We will send you a notification when the check is done.</p>
                </section>
              <?php elseif ($gateStatus === 'admin_review'): ?>
                <div class="ai-gate-notice is-review" role="status">
                  <i class="fas fa-user-shield" aria-hidden="true"></i>
                  <span>Your documents are being reviewed before they go to PSWDO.</span>
                </div>
              <?php elseif (($stageNotice = status_stage_notice($req)) !== null): ?>
                <div class="ai-gate-notice is-eta ai-gate-eta is-stage is-<?= status_h($stageNotice['tone']) ?>" role="status">
                  <i class="fas <?= status_h($stageNotice['icon']) ?>" aria-hidden="true"></i>
                  <div class="ai-gate-eta-body">
                    <span class="ai-gate-eta-label"><?= status_h($stageNotice['label']) ?></span>
                    <strong class="ai-gate-eta-span"><?= status_h($stageNotice['title']) ?></strong>
                    <?php foreach ($stageNotice['lines'] as $stageLine): ?>
                      <span class="ai-gate-stage-line"><?= status_h($stageLine) ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php elseif (is_array($req['gate_eta'] ?? null) && !in_array($statusBadge, ['ok', 'danger'], true)): ?>
                <?php $gateEta = $req['gate_eta']; ?>
                <div class="ai-gate-notice is-eta ai-gate-eta is-<?= status_h(strtolower((string) $gateEta['priority'])) ?><?= $gateEta['overdue'] ? ' is-overdue' : '' ?>" role="status">
                  <i class="fas fa-calendar-check" aria-hidden="true"></i>
                  <div class="ai-gate-eta-body">
                    <span class="ai-gate-eta-label">Expected processing</span>
                    <strong class="ai-gate-eta-span"><?= status_h((string) $gateEta['span']) ?></strong>
                    <?php if ($gateEta['overdue']): ?>
                      <span class="ai-gate-eta-date">Taking longer than expected. PSWDO is still reviewing your request.</span>
                    <?php else: ?>
                      <span class="ai-gate-eta-date">By <?= status_h((string) $gateEta['due_label']) ?></span>
                    <?php endif; ?>
                    <span class="ai-gate-eta-meta">
                      <span class="ai-gate-eta-priority">Priority: <?= status_h((string) $gateEta['priority_label']) ?></span>
                      <span class="ai-gate-eta-hours">The PSWDO office is open Monday to Thursday.</span>
                    </span>
                  </div>
                </div>
              <?php endif; ?>

              <div class="status-block status-stages-block">
                <div class="section-title-wrap">
                  <h3 class="section-title"><i class="fas fa-bars-progress"></i> Application Progress</h3>
                  <span class="section-title-hint">Step-by-step review stages from PSWDO validation to final decision</span>
                </div>
                <div class="timeline status-progress-tracker" role="list" aria-label="Application progress">
                  <?php $stageStepNum = 0; ?>
                  <?php foreach ($stageRows as $row): ?>
                    <?php if (!is_array($row)) { continue; } ?>
                    <?php 
                      $stageStepNum++;
                      $stageBadge = (string) ($row['badge'] ?? 'info');
                      $stageBadgeLabel = (string) ($row['badge_label'] ?? 'Pending');
                    ?>
                    <div class="timeline-item status-stage status-stage-<?= status_h($stageBadge) ?>" role="listitem">
                      <div class="stage-top-bar">
                        <div class="stage-indicator-pill">
                          <?php if ($stageBadge === 'ok'): ?>
                            <span class="stage-icon-circle icon-ok" title="Completed"><i class="fas fa-check"></i></span>
                          <?php elseif ($stageBadge === 'pending'): ?>
                            <span class="stage-icon-circle icon-pending" title="In Progress"><span class="stage-pulse-beacon"></span><i class="fas fa-spinner fa-spin-pulse"></i></span>
                          <?php elseif ($stageBadge === 'danger'): ?>
                            <span class="stage-icon-circle icon-danger" title="Alert"><i class="fas fa-xmark"></i></span>
                          <?php elseif ($stageBadge === 'attention'): ?>
                            <span class="stage-icon-circle icon-attention" title="Action needed"><i class="fas fa-exclamation"></i></span>
                          <?php elseif ($stageBadge === 'review'): ?>
                            <span class="stage-icon-circle icon-review" title="In review"><i class="fas fa-hourglass-half"></i></span>
                          <?php else: ?>
                            <span class="stage-icon-circle icon-muted" title="Step <?= $stageStepNum ?>"><?= $stageStepNum ?></span>
                          <?php endif; ?>
                          <span class="stage-step-tag">Step <?= $stageStepNum ?></span>
                        </div>
                        <span class="badge <?= status_h($stageBadge) ?>"><?= status_h($stageBadgeLabel) ?></span>
                      </div>
                      <strong class="stage-title"><?= status_h((string) ($row['title'] ?? 'Stage')) ?></strong>
                      <div class="timeline-meta stage-meta"><?= status_h((string) ($row['meta'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <?php
                $schedDate   = (string) ($req['scheduled_date'] ?? '');
                $schedTime   = (string) ($req['scheduled_time'] ?? '');
                $schedVenue   = (string) ($req['venue'] ?? '');
                $schedBatch   = (string) ($req['release_batch'] ?? '');
                $schedStatus  = strtolower(trim((string) ($req['release_status'] ?? '')));
                $reqStatusCode = strtolower(trim((string) ($req['status_code'] ?? '')));

                $showSchedule = $schedDate !== '' && $schedDate !== '0000-00-00'
                    && in_array($reqStatusCode, ['approved','for_release','for release','validated','pending_release','scheduled'], true);
                $showReleased = in_array($reqStatusCode, ['released','distributed','completed'], true)
                    && $schedDate !== '' && $schedDate !== '0000-00-00';
              ?>
              <?php if ($showReleased): ?>
              <div class="status-block release-schedule-block released">
                <div class="section-title"><i class="fas fa-circle-check"></i> Assistance Released</div>
                <div class="release-schedule-card released-card">
                  <div class="release-schedule-row">
                    <i class="fas fa-calendar-check"></i>
                    <div>
                      <strong>Released on</strong>
                      <span><?= status_h(status_fmt_date($schedDate)) ?></span>
                    </div>
                  </div>
                  <?php if ($schedVenue !== ''): ?>
                  <div class="release-schedule-row">
                    <i class="fas fa-location-dot"></i>
                    <div>
                      <strong>Location</strong>
                      <span><?= status_h($schedVenue) ?></span>
                    </div>
                  </div>
                  <?php endif; ?>
                  <?php if ($schedBatch !== ''): ?>
                  <div class="release-schedule-row">
                    <i class="fas fa-layer-group"></i>
                    <div>
                      <strong>Release Batch</strong>
                      <span><?= status_h($schedBatch) ?></span>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
              <?php elseif ($showSchedule): ?>
              <div class="status-block release-schedule-block">
                <div class="section-title"><i class="fas fa-calendar-clock"></i> Release Schedule</div>
                <div class="release-schedule-card">
                  <p class="release-schedule-note">Your assistance release has been scheduled. Please be at the venue on time.</p>
                  <div class="release-schedule-row">
                    <i class="fas fa-calendar-day"></i>
                    <div>
                      <strong>Date</strong>
                      <span><?= status_h(status_fmt_date($schedDate)) ?></span>
                    </div>
                  </div>
                  <?php if (($t = status_fmt_time($schedTime)) !== ''): ?>
                  <div class="release-schedule-row">
                    <i class="fas fa-clock"></i>
                    <div>
                      <strong>Time</strong>
                      <span><?= status_h($t) ?></span>
                    </div>
                  </div>
                  <?php endif; ?>
                  <?php if ($schedVenue !== ''): ?>
                  <div class="release-schedule-row">
                    <i class="fas fa-location-dot"></i>
                    <div>
                      <strong>Venue</strong>
                      <span><?= status_h($schedVenue) ?></span>
                    </div>
                  </div>
                  <?php endif; ?>
                  <?php if ($schedBatch !== ''): ?>
                  <div class="release-schedule-row">
                    <i class="fas fa-layer-group"></i>
                    <div>
                      <strong>Release Batch</strong>
                      <span><?= status_h($schedBatch) ?></span>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endif; ?>

              <div class="status-block status-checklist-block">
                <div class="section-title-wrap">
                  <h3 class="section-title"><i class="fas fa-list-check"></i> Document Checklist</h3>
                  <span class="section-title-hint"><?= $uploadedRequiredCount ?> of <?= $requiredCount ?> required documents uploaded</span>
                </div>

                <div class="document-checklist document-checklist-senior">
                  <?php if (count($requiredStates) === 0): ?>
                    <div class="panel-empty compact document-empty-state panel-empty-senior">
                      <i class="fas fa-folder-plus"></i>
                      <strong>No requirement map found</strong>
                      <p>We could not match requirements for this application type yet.</p>
                    </div>
                  <?php else: ?>
                    <?php $gateFixes = array_column((array) ($req['gate_items'] ?? []), 'fix', 'type'); ?>
                    <?php foreach ($requiredStates as $state): ?>
                      <?php if (!is_array($state)) { continue; } ?>
                      <?php $aiFix = (string) ($gateFixes[(string) ($state['type'] ?? '')] ?? ''); ?>
                      <?php $isUploaded = (bool) ($state['uploaded'] ?? false); ?>
                      <?php $isChecked = (bool) ($state['checked'] ?? false); ?>
                      <?php $reviewStatus = strtolower(trim((string) ($state['review_status'] ?? 'pending'))); ?>
                      <?php $reviewRemarks = trim((string) ($state['review_remarks'] ?? '')); ?>
                      <div class="document-check-item document-check-senior <?= $isChecked ? 'is-uploaded' : 'is-pending' ?>">
                        <div class="document-check-icon">
                          <i class="fas fa-<?= $isChecked ? 'circle-check' : ($isUploaded ? 'file-lines' : 'circle-exclamation') ?>"></i>
                        </div>
                        <div class="document-check-copy">
                          <strong><?= status_h((string) ($state['label'] ?? 'Document')) ?></strong>
                          <span>
                            <?php if ($isUploaded && $isChecked): ?>
                              Uploaded <?= status_h((string) ($state['label'] ?? 'document')) ?>
                              <?= (string) (($state['reviewed_at_label'] ?? '') !== '' ? (' · Reviewed ' . (string) $state['reviewed_at_label']) : '') ?>
                            <?php elseif ($isUploaded && in_array($reviewStatus, ['needs_reupload', 'invalid'], true)): ?>
                              Uploaded <?= status_h((string) ($state['label'] ?? 'document')) ?>
                              <?= (string) (($state['reviewed_at_label'] ?? '') !== '' ? (' · Reviewed ' . (string) $state['reviewed_at_label']) : '') ?>
                            <?php elseif ($isUploaded && $aiFix !== ''): ?>
                              AICS AI could not accept this document. <?= status_h($aiFix) ?>
                            <?php elseif ($isUploaded): ?>
                              Uploaded <?= status_h((string) ($state['label'] ?? 'document')) ?>
                              · Uploaded — waiting for PSWDO review
                            <?php else: ?>
                              Not yet uploaded
                            <?php endif; ?>
                          </span>
                        </div>
                        <span class="badge badge-senior <?= $isChecked ? 'ok' : ($isUploaded && $aiFix === '' ? 'pending' : 'danger') ?>">
                          <?=
                            $reviewStatus === 'needs_reupload' || $aiFix !== '' ? 'Needs Changes'
                            : ($reviewStatus === 'invalid' ? 'Invalid'
                            : ($isChecked ? 'Checked' : ($isUploaded ? 'Uploaded' : 'Not Uploaded')))
                          ?>
                        </span>
                        <?php if ($isUploaded && in_array($reviewStatus, ['needs_reupload', 'invalid'], true)): ?>
                          <form method="post" action="<?= status_h($docUploadUrl) ?>" enctype="multipart/form-data" style="margin-left: 10px;">
                            <input type="hidden" name="action_mode" value="reupload_single">
                            <input type="hidden" name="request_id" value="<?= (int) ($req['id'] ?? 0) ?>">
                            <input type="hidden" name="document_type" value="<?= status_h((string) ($state['type'] ?? '')) ?>">
                            <label class="btn-senior btn-senior-primary" style="padding: 8px 12px; font-size: 12px; cursor:pointer;">
                              <i class="fas fa-upload"></i> <span data-upload-text>Re-upload</span>
                              <input type="file" name="reupload_file" accept=".pdf,.jpg,.jpeg,.png" required style="display:none;">
                            </label>
                          </form>
                        <?php endif; ?>
                      </div>
                      <?php if ($isUploaded && $reviewRemarks !== '' && in_array($reviewStatus, ['needs_reupload', 'invalid'], true)): ?>
                        <div class="document-remarks-banner">
                          <i class="fas fa-triangle-exclamation"></i>
                          <div>
                            <strong>PSWDO Note:</strong>
                            <span><?= status_h($reviewRemarks) ?></span>
                          </div>
                        </div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
  // Re-upload: the chosen file is sent at once. While it uploads, every
  // re-upload button is locked so a second click cannot start another upload.
  (function () {
    var uploading = false;
    document.addEventListener('click', function (event) {
      var label = uploading && event.target instanceof Element ? event.target.closest('label') : null;
      if (label && label.querySelector('input[name="reupload_file"]')) event.preventDefault();
    }, true);
    document.addEventListener('change', function (event) {
      var input = event.target;
      if (uploading || !(input instanceof HTMLInputElement) || input.name !== 'reupload_file' || !input.files || input.files.length === 0) return;
      uploading = true;
      document.querySelectorAll('input[name="reupload_file"]').forEach(function (other) {
        var label = other.closest('label');
        if (!label) return;
        label.classList.add('is-uploading');
        label.setAttribute('aria-disabled', 'true');
        label.style.cursor = 'progress';
      });
      var current = input.closest('label');
      var icon = current ? current.querySelector('i') : null;
      var text = current ? current.querySelector('[data-upload-text]') : null;
      if (icon) icon.className = 'fas fa-spinner fa-spin';
      if (text) text.textContent = 'Uploading…';
      input.form.submit();
    });
    // Returning to this page from browser history shows the buttons unlocked.
    window.addEventListener('pageshow', function (event) { if (event.persisted) window.location.reload(); });
  }());
</script>
<script src="<?= status_h($appJsUrl) ?>"></script>
<?php require __DIR__ . '/../../chatbot/applicant-widget.php'; ?>
<?= aics_app_shell_script() ?>
</body>
</html>
