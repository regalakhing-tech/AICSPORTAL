<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/auth-common.php';

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

$logoUrl = $projectRootUrl . '/asset/logo.png';
$cssUrl = $applicantsBaseUrl . '/css files/applicant-pages.css?v=' . urlencode($assetCssVersion);
$appJsUrl = $applicantsBaseUrl . '/js files/applicant-pages.js?v=' . urlencode($assetJsVersion);
$logoutUrl = $phpFilesBaseUrl . '/auth-logout.php';
$cancelActionUrl = $phpFilesBaseUrl . '/cancel-request-action.php';
$submitRequestUrl = $phpFilesBaseUrl . '/submit-request.php';

$assistanceLabels = [
    'medical' => 'Medical',
    'burial' => 'Burial',
    'victim_of_disaster' => 'Victim of Disaster',
    'special_cases' => 'Special Cases',
];

$assistanceIcons = [
    'medical' => 'notes-medical',
    'burial' => 'cross',
    'victim_of_disaster' => 'house-crack',
    'special_cases' => 'shield-heart',
];

if (!isset($_SESSION['aics_cancel_csrf']) || !is_string($_SESSION['aics_cancel_csrf']) || $_SESSION['aics_cancel_csrf'] === '') {
    $_SESSION['aics_cancel_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['aics_cancel_csrf'];

$cancelStatus = (string) ($_GET['cancel'] ?? '');
$cancelMessage = trim((string) ($_GET['message'] ?? ''));

$requests = [];

try {
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    if ($accountId > 0) {
        $connection = aics_db_connect();
        aics_ensure_auth_schema($connection);

        $requestsSql = <<<SQL
            SELECT
                r.id,
                r.reference_no,
                r.assistance_types_json,
                r.request_status,
                r.date_applied,
                r.created_at,
                r.updated_at,
                r.beneficiary_complete_name
            FROM aics_requests r
            INNER JOIN aics_account_requests ar ON ar.request_id = r.id
            WHERE ar.account_id = ?
            ORDER BY r.id DESC
        SQL;

        $stmt = $connection->prepare($requestsSql);
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!is_array($row)) {
                break;
            }

            $assistanceType = '';
            $decoded = json_decode((string) ($row['assistance_types_json'] ?? '[]'), true);
            if (is_array($decoded) && count($decoded) > 0) {
                $assistanceType = strtolower(trim((string) $decoded[0]));
            }

            $statusMeta = cancel_request_status_meta((string) ($row['request_status'] ?? 'pending'));
            $statusBadge = (string) $statusMeta['badge'];
            $canCancel = (bool) $statusMeta['can_cancel'];
            $statusNoteClass = $statusBadge === 'ok'
                ? ' success'
                : ($canCancel ? '' : ' missing');
            $statusNoteIcon = $statusBadge === 'ok'
                ? 'circle-check'
                : ($canCancel ? 'circle-info' : 'circle-exclamation');
            $requests[] = [
                'id' => (int) ($row['id'] ?? 0),
                'reference_no' => (string) ($row['reference_no'] ?? ''),
                'assistance_type' => $assistanceType,
                'assistance_label' => $assistanceLabels[$assistanceType] ?? ($assistanceType !== '' ? ucwords(str_replace('_', ' ', $assistanceType)) : 'Not set'),
                'assistance_icon' => $assistanceIcons[$assistanceType] ?? 'hand-holding-heart',
                'beneficiary_name' => trim((string) ($row['beneficiary_complete_name'] ?? '')),
                'date_applied_label' => cancel_fmt_date((string) ($row['date_applied'] ?? '')),
                'updated_at_label' => cancel_fmt_datetime((string) ($row['updated_at'] ?? '')),
                'status_code' => (string) ($row['request_status'] ?? ''),
                'status_label' => (string) $statusMeta['label'],
                'status_badge' => $statusBadge,
                'status_icon' => (string) $statusMeta['icon'],
                'can_cancel' => $canCancel,
                'can_edit' => cancel_request_is_editable((string) ($row['request_status'] ?? 'pending')),
                'status_note' => (string) $statusMeta['note'],
                'status_note_class' => $statusNoteClass,
                'status_note_icon' => $statusNoteIcon,
                'edit_url' => $phpFilesBaseUrl . '/edit-request.php?request_id=' . (int) ($row['id'] ?? 0) . '&token=' . urlencode((string) ($_SESSION['aics_edit_entry_tokens'][(string) ((int) ($row['id'] ?? 0))] ?? '')),
            ];
            if (cancel_request_is_editable((string) ($row['request_status'] ?? 'pending'))) {
                $requestKey = (string) ((int) ($row['id'] ?? 0));
                $_SESSION['aics_edit_entry_tokens'][$requestKey] = bin2hex(random_bytes(24));
                $requests[array_key_last($requests)]['edit_url'] = $phpFilesBaseUrl . '/edit-request.php?request_id=' . (int) $requestKey . '&token=' . urlencode($_SESSION['aics_edit_entry_tokens'][$requestKey]);
            }
        }

        $stmt->close();
        $connection->close();
    }
} catch (Throwable $exception) {
    if ($cancelStatus === '') {
        $cancelStatus = 'error';
        $cancelMessage = 'Unable to load your applications right now.';
    }
}

function cancel_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cancel_fmt_date(string $raw): string
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

function cancel_fmt_datetime(string $raw): string
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

/**
 * @return array{label:string,badge:string,icon:string,can_cancel:bool,note:string}
 */
function cancel_request_status_meta(string $status): array
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'pending', 'for_review', 'for validation', 'for_validation' => [
            'label' => 'Pending Review',
            'badge' => 'pending',
            'icon' => 'circle-info',
            'can_cancel' => true,
            'note' => 'This application is still pending and can be cancelled.',
        ],
        'incomplete_requirements', 'incomplete requirements' => [
            'label' => 'Incomplete Requirements',
            'badge' => 'pending',
            'icon' => 'circle-info',
            'can_cancel' => true,
            'note' => 'This application was marked incomplete and can still be cancelled.',
        ],
        'for_interview', 'for interview', 'for interview/further screening', 'for interview / further screening' => [
            'label' => 'For Interview',
            'badge' => 'info',
            'icon' => 'circle-info',
            'can_cancel' => true,
            'note' => 'This application is for interview/further screening and can still be cancelled.',
        ],
        'under assessment', 'under_assessment', 'assessment', 'in_review' => [
            'label' => 'Under Assessment',
            'badge' => 'info',
            'icon' => 'circle-info',
            'can_cancel' => true,
            'note' => 'This application is currently under assessment and can still be cancelled.',
        ],
        'under_review', 'under review' => [
            'label' => 'Under Review',
            'badge' => 'info',
            'icon' => 'circle-info',
            'can_cancel' => true,
            'note' => 'This application is under review and can still be cancelled.',
        ],
        'approved', 'validated', 'for_release', 'for release' => [
            'label' => 'Approved / For Release',
            'badge' => 'ok',
            'icon' => 'circle-check',
            'can_cancel' => false,
            'note' => 'This application is already approved for processing and can no longer be cancelled.',
        ],
        'released', 'distributed', 'completed', 'closed' => [
            'label' => 'Completed',
            'badge' => 'ok',
            'icon' => 'circle-check',
            'can_cancel' => false,
            'note' => 'This application has been completed and can no longer be cancelled.',
        ],
        'rejected', 'denied', 'not_approved', 'not approved' => [
            'label' => 'Rejected',
            'badge' => 'pending',
            'icon' => 'circle-exclamation',
            'can_cancel' => false,
            'note' => 'This application was rejected and cannot be cancelled.',
        ],
        'cancelled', 'canceled' => [
            'label' => 'Cancelled',
            'badge' => 'pending',
            'icon' => 'circle-exclamation',
            'can_cancel' => false,
            'note' => 'This application is already cancelled.',
        ],
        default => [
            'label' => ucwords(str_replace('_', ' ', $normalized === '' ? 'pending' : $normalized)),
            'badge' => 'info',
            'icon' => 'circle-info',
            'can_cancel' => true,
            'note' => 'This application can be cancelled.',
        ],
    };
}

function cancel_request_is_editable(string $status): bool
{
    $normalized = strtolower(trim($status));
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

    return !in_array($normalized, $lockedStatuses, true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AICS Applicant | Manage Applications</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= cancel_h($cssUrl) ?>">
<style>
/* ==========================================================================
   Manage Request Page - Modern Executive Design
   ========================================================================== */

.cancel-request-page .cancel-hero-card {
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
  margin-bottom: 24px;
}

.cancel-request-page .cancel-hero-card::after {
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

.cancel-request-page .cancel-hero-main {
  display: flex;
  align-items: center;
  gap: 20px;
  position: relative;
  z-index: 1;
  flex: 1;
}

.cancel-request-page .cancel-hero-icon-pill {
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

.cancel-request-page .cancel-hero-copy {
  flex: 1;
  min-width: 0;
}

.cancel-request-page .cancel-hero-meta-badge {
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

.cancel-request-page .status-live-pulse-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #0284c7;
  box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.7);
  animation: liveBluePulse 2s infinite cubic-bezier(0.4, 0, 0.6, 1);
}

@keyframes liveBluePulse {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.7); }
  70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(2, 132, 199, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(2, 132, 199, 0); }
}

.cancel-request-page .cancel-hero-card .page-title {
  font-size: clamp(21px, 2.2vw, 27px);
  font-weight: 800;
  color: #0b1220;
  margin: 0 0 6px;
  line-height: 1.25;
  letter-spacing: -0.02em;
}

.cancel-request-page .cancel-hero-card .page-sub {
  font-size: 14.5px;
  color: #475569;
  line-height: 1.55;
  margin: 0;
  max-width: 82ch;
}

/* ---------- Request Card Block ---------- */
.cancel-request-page .docs-requests-list {
  display: flex;
  flex-direction: column;
  gap: 22px;
}

.cancel-request-page .docs-request-block {
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 22px;
  padding: 24px 28px;
  box-shadow: 0 8px 26px rgba(15, 23, 42, 0.05);
  transition: box-shadow 0.25s ease, border-color 0.25s ease;
}

.cancel-request-page .docs-request-block:hover {
  box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
  border-color: #cbd5e1;
}

.cancel-request-page .docs-request-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  padding-bottom: 20px;
  margin-bottom: 18px;
  border-bottom: 1.5px dashed #e2e8f0;
  flex-wrap: wrap;
}

.cancel-request-page .docs-request-title-wrap {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  min-width: 0;
  flex: 1 1 340px;
}

.cancel-request-page .docs-request-icon {
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

.cancel-request-page .docs-request-title-copy {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.cancel-request-page .docs-request-type-eyebrow {
  font-size: 11.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #0284c7;
  margin-bottom: 2px;
}

.cancel-request-page .docs-request-title {
  font-size: 21px;
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.02em;
  line-height: 1.25;
  margin: 0 0 10px;
}

.cancel-request-page .docs-request-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 10px;
}

.cancel-request-page .status-meta-chip {
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

.cancel-request-page .status-meta-chip:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
}

.cancel-request-page .status-meta-chip i {
  font-size: 12px;
  color: #64748b;
}

.cancel-request-page .status-meta-chip.chip-ref {
  background: #f0f9ff;
  border-color: #bae6fd;
  color: #0369a1;
  font-weight: 700;
}

.cancel-request-page .status-meta-chip.chip-ref i { color: #0284c7; }
.cancel-request-page .status-meta-chip.chip-user i { color: #3b82f6; }
.cancel-request-page .status-meta-chip.chip-date i { color: #8b5cf6; }
.cancel-request-page .status-meta-chip.chip-clock i { color: #059669; }

.cancel-request-page .docs-request-status {
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

.cancel-request-page .docs-request-status.status-pill-ok,
.cancel-request-page .docs-request-status.is-complete {
  background: #dcfce7;
  color: #166534;
  border-color: #bbf7d0;
  box-shadow: 0 2px 10px rgba(22, 163, 74, 0.12);
}

.cancel-request-page .docs-request-status.status-pill-danger {
  background: #fee2e2;
  color: #991b1b;
  border-color: #fecaca;
  box-shadow: 0 2px 10px rgba(220, 38, 38, 0.12);
}

.cancel-request-page .status-dot-pulse {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
  position: relative;
  display: inline-block;
}

.cancel-request-page .status-dot-pulse::after {
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

/* ---------- Status Callout ---------- */
.cancel-request-page .cancel-status-callout {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 18px;
  border-radius: 14px;
  background: #f0f9ff;
  border: 1.5px solid #bae6fd;
  margin-bottom: 20px;
  transition: all 0.2s ease;
}

.cancel-request-page .cancel-status-callout .callout-icon {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  background: #ffffff;
  display: grid;
  place-items: center;
  font-size: 14px;
  color: #0284c7;
  box-shadow: 0 2px 6px rgba(2, 132, 199, 0.15);
  flex-shrink: 0;
  margin-top: 1px;
}

.cancel-request-page .cancel-status-callout .callout-text {
  font-size: 13.5px;
  line-height: 1.55;
  color: #0369a1;
  flex: 1;
}

.cancel-request-page .cancel-status-callout .callout-text strong {
  font-weight: 700;
  margin-right: 4px;
  color: #075985;
}

.cancel-request-page .cancel-status-callout.success {
  background: #f0fdf4;
  border-color: #bbf7d0;
}
.cancel-request-page .cancel-status-callout.success .callout-icon {
  color: #16a34a;
  box-shadow: 0 2px 6px rgba(22, 163, 74, 0.15);
}
.cancel-request-page .cancel-status-callout.success .callout-text {
  color: #15803d;
}
.cancel-request-page .cancel-status-callout.success .callout-text strong {
  color: #166534;
}

.cancel-request-page .cancel-status-callout.missing {
  background: #fffdf5;
  border-color: #fde68a;
}
.cancel-request-page .cancel-status-callout.missing .callout-icon {
  color: #d97706;
  box-shadow: 0 2px 6px rgba(217, 119, 6, 0.15);
}
.cancel-request-page .cancel-status-callout.missing .callout-text {
  color: #92400e;
}
.cancel-request-page .cancel-status-callout.missing .callout-text strong {
  color: #78350f;
}

/* ---------- Action Buttons ---------- */
.cancel-request-page .cancel-action-wrap {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.cancel-request-page .btn-request-edit {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 20px;
  border-radius: 12px;
  background: #ffffff;
  border: 1.5px solid #0284c7;
  color: #0284c7;
  font-size: 14px;
  font-weight: 700;
  text-decoration: none;
  box-shadow: 0 2px 8px rgba(2, 132, 199, 0.08);
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

.cancel-request-page .btn-request-edit:hover {
  background: #f0f9ff;
  border-color: #0369a1;
  color: #0369a1;
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(2, 132, 199, 0.15);
}

.cancel-request-page .btn-cancel-request {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 20px;
  border-radius: 12px;
  background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
  border: 1.5px solid #b91c1c;
  color: #ffffff;
  font-size: 14px;
  font-weight: 700;
  box-shadow: 0 2px 10px rgba(220, 38, 38, 0.2);
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  cursor: pointer;
}

.cancel-request-page .btn-cancel-request:hover {
  background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(220, 38, 38, 0.3);
}

/* ---------- Confirm Modal ---------- */
.confirm-modal-card {
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 20px 48px rgba(15, 23, 42, 0.2);
  border: 1px solid #e2e8f0;
}

.confirm-modal-header.danger {
  background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
}

.confirm-summary-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 14px 18px;
  display: grid;
  gap: 10px;
}

.confirm-summary-box .summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13.5px;
}

.confirm-summary-box .summary-label {
  color: #64748b;
  font-weight: 500;
}

.confirm-summary-box .summary-value {
  color: #0f172a;
  font-weight: 700;
}

.confirm-modal-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 20px;
}

@media (max-width: 768px) {
  .cancel-request-page .cancel-hero-card {
    padding: 20px 20px;
  }
  .cancel-request-page .cancel-hero-main {
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
  }
  .cancel-request-page .docs-request-block {
    padding: 20px 18px;
  }
  .cancel-request-page .docs-request-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
  }
  .cancel-request-page .docs-request-status {
    width: 100%;
    justify-content: center;
  }
  .cancel-request-page .cancel-action-wrap {
    width: 100%;
  }
  .cancel-request-page .btn-request-edit,
  .cancel-request-page .btn-cancel-request {
    flex: 1 1 auto;
    justify-content: center;
  }
}

@media (max-width: 480px) {
  .cancel-request-page .docs-request-meta {
    font-size: 12.5px;
  }
  .cancel-request-page .confirm-modal-card {
    width: calc(100% - 16px);
  }
}
</style>
</head>
<body class="cancel-request-shell" data-account-status="<?= cancel_h($currentAccountStatusCode) ?>" data-banned-modal-required="<?= $forceBannedModal ? '1' : '0' ?>">
<div class="app-container">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a href="<?= cancel_h($dashboardUrl) ?>?page=overview" class="logo" style="padding-top: 4px;">
        <img src="<?= cancel_h($logoUrl) ?>" alt="AICS | PSWDO Logo" style="height: 42px; width: auto; object-fit: contain;">
      </a>
      <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
    </div>

    <div class="nav-menu">
      <div class="nav-header">Main Menu</div>
      <a href="<?= cancel_h($dashboardUrl) ?>?page=overview" class="nav-item "><i class="fas fa-border-all"></i> Overview Dashboard</a>
      <a href="<?= cancel_h($dashboardUrl) ?>?page=profile" class="nav-item "><i class="fas fa-user-circle"></i> My Profile</a>

      <div class="nav-header nav-header-gap">Application</div>
      <a href="<?= cancel_h($dashboardUrl) ?>?page=submit" class="nav-item "><i class="fas fa-file-signature"></i> Submit Application</a>
      <a href="<?= cancel_h($dashboardUrl) ?>?page=documents" class="nav-item "><i class="fas fa-file-invoice"></i> Documents</a>
      <a href="<?= cancel_h($dashboardUrl) ?>?page=status" class="nav-item "><i class="fas fa-route"></i> Application Status</a>
      <a href="<?= cancel_h($dashboardUrl) ?>?page=cancel-request" class="nav-item active"><i class="fas fa-pen-to-square"></i> Manage Applications</a>

      <div class="nav-header nav-header-gap">Support</div>
      <a href="<?= cancel_h($dashboardUrl) ?>?page=notifications" class="nav-item "><i class="fas fa-bell"></i> Notifications</a>
    </div>

    <div class="sidebar-footer">
      <a href="<?= cancel_h($logoutUrl) ?>" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </aside>

  <div class="sidebar-overlay" id="overlay"></div>

  <main class="main-content" id="main-content">
    <header class="top-nav">
      <div class="nav-left">
        <div class="top-nav-page-info">
          <span class="top-nav-badge"><i class="fas fa-pen-to-square"></i></span>
          <div class="top-nav-text">
            <span class="top-nav-sub">Application</span>
            <h1 class="top-nav-heading">Manage Applications</h1>
          </div>
        </div>
      </div>

      <div class="nav-right">
        <a class="btn-icon" data-notification-trigger href="<?= cancel_h($dashboardUrl) ?>?page=notifications" aria-label="Notifications" aria-expanded="false">
          <i class="fas fa-bell"></i>
          <span class="dot" hidden aria-hidden="true"></span>
        </a>
        <div class="profile-chip">
          <div class="profile-av"><?= cancel_h($initials) ?></div>
          <span><?= cancel_h($displayName) ?></span>
        </div>
      </div>
    </header>

    <div class="content-area content-area-senior cancel-request-page">
      <section class="section-card docs-hero-senior cancel-hero-card">
        <div class="docs-hero-main cancel-hero-main">
          <div class="cancel-hero-icon-pill">
            <i class="fas fa-pen-to-square"></i>
          </div>
          <div class="docs-hero-copy cancel-hero-copy">
            <div class="cancel-hero-meta-badge">
              <span class="status-live-pulse-dot"></span>
              <span>Application Management</span>
            </div>
            <h1 class="page-title">Manage Your Submitted Applications</h1>
            <p class="page-sub">Edit eligible applications or cancel applications that are still in a cancellable stage. Locked applications remain read-only.</p>
          </div>
        </div>
      </section>

      <?php if ($cancelStatus !== '' && $cancelMessage !== ''): ?>
        <div class="senior-alert <?= $cancelStatus === 'success' ? 'senior-alert-success' : 'senior-alert-error' ?>">
          <i class="fas fa-<?= $cancelStatus === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
          <span><?= cancel_h($cancelMessage) ?></span>
        </div>
      <?php endif; ?>

      <?php if (count($requests) === 0): ?>
        <section class="section-card docs-empty-card">
          <div class="docs-empty-illustration" aria-hidden="true">
            <i class="fas fa-file-signature"></i>
          </div>
          <h2>No submitted applications yet</h2>
          <p>You do not have any submitted applications to manage or cancel right now.</p>
          <a class="btn-senior btn-senior-primary" href="<?= cancel_h($dashboardUrl) ?>?page=submit">
            <i class="fas fa-file-signature"></i>
            <span>Submit an Application</span>
          </a>
        </section>
      <?php else: ?>
        <div class="docs-requests-list">
          <?php foreach ($requests as $req): ?>
            <section class="docs-request-block">
              <header class="docs-request-head">
                <div class="docs-request-title-wrap">
                  <div class="docs-request-icon tone-brand">
                    <i class="fas fa-<?= cancel_h((string) $req['assistance_icon']) ?>"></i>
                  </div>
                  <div class="docs-request-title-copy">
                    <div class="docs-request-type-eyebrow">AICS Assistance Application</div>
                    <h2 class="docs-request-title"><?= cancel_h((string) $req['assistance_label']) ?> Application</h2>
                    <div class="docs-request-meta">
                      <span class="status-meta-chip chip-ref"><i class="fas fa-hashtag"></i> <strong><?= cancel_h((string) $req['reference_no']) ?></strong></span>
                      <span class="status-meta-chip chip-user"><i class="fas fa-user"></i> <?= cancel_h((string) $req['beneficiary_name']) ?></span>
                      <span class="status-meta-chip chip-date"><i class="fas fa-calendar-day"></i> Applied: <?= cancel_h((string) $req['date_applied_label']) ?></span>
                      <span class="status-meta-chip chip-clock"><i class="fas fa-clock-rotate-left"></i> Updated: <?= cancel_h((string) $req['updated_at_label']) ?></span>
                    </div>
                  </div>
                </div>
                <div class="docs-request-status status-pill-<?= cancel_h((string) $req['status_badge']) ?><?= (string) $req['status_badge'] === 'ok' ? ' is-complete' : '' ?>">
                  <span class="status-dot-pulse"></span>
                  <i class="fas fa-<?= cancel_h((string) $req['status_icon']) ?>"></i>
                  <span><?= cancel_h((string) $req['status_label']) ?></span>
                </div>
              </header>

              <div class="cancel-status-callout<?= cancel_h((string) $req['status_note_class']) ?>">
                <div class="callout-icon">
                  <i class="fas fa-<?= cancel_h((string) $req['status_note_icon']) ?>"></i>
                </div>
                <div class="callout-text">
                  <strong>Notice:</strong>
                  <span><?= cancel_h((string) $req['status_note']) ?></span>
                </div>
              </div>

              <?php if ((bool) $req['can_edit'] || (bool) $req['can_cancel']): ?>
                <div class="docs-card-actions cancel-action-wrap">
                  <?php if ((bool) $req['can_edit']): ?>
                    <a
                      class="btn-senior btn-request-edit"
                      href="<?= cancel_h((string) $req['edit_url']) ?>"
                    >
                      <i class="fas fa-pen-to-square"></i>
                      <span>Edit Application</span>
                    </a>
                  <?php endif; ?>

                  <?php if ((bool) $req['can_cancel']): ?>
                    <form method="post" action="<?= cancel_h($cancelActionUrl) ?>" class="js-cancel-form">
                      <input type="hidden" name="csrf_token" value="<?= cancel_h($csrfToken) ?>">
                      <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                      <button
                        type="button"
                        class="btn-senior btn-cancel-request js-open-cancel-modal"
                        data-reference="<?= cancel_h((string) $req['reference_no']) ?>"
                        data-assistance="<?= cancel_h((string) $req['assistance_label']) ?>"
                        data-beneficiary="<?= cancel_h((string) $req['beneficiary_name']) ?>"
                      >
                        <i class="fas fa-ban"></i>
                        <span>Cancel Application</span>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<div class="preview-modal-overlay" id="cancel-confirm-modal" style="display: none;">
  <div class="confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="cancel-modal-title">
    <div class="confirm-modal-header danger">
      <h3 id="cancel-modal-title"><i class="fas fa-ban"></i> Cancel Application</h3>
      <button type="button" class="close-confirm-btn" id="close-cancel-modal">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <div class="confirm-modal-body">
      <p class="confirm-modal-desc">
        This will cancel the selected application. This action cannot be undone.
      </p>

      <div class="confirm-summary-box">
        <div class="summary-row">
          <span class="summary-label">Reference Number</span>
          <strong class="summary-value subtle" id="cancel-modal-reference">-</strong>
        </div>
        <div class="summary-row">
          <span class="summary-label">Assistance Type</span>
          <strong class="summary-value subtle" id="cancel-modal-assistance">-</strong>
        </div>
        <div class="summary-row">
          <span class="summary-label">Beneficiary</span>
          <strong class="summary-value subtle" id="cancel-modal-beneficiary">-</strong>
        </div>
      </div>

      <div class="field" style="margin-top:16px;">
        <label for="cancel-reason">Reason for cancellation <span class="required-mark">*</span></label>
        <textarea id="cancel-reason" rows="3" maxlength="500" required placeholder="Briefly explain why you are cancelling this application."></textarea>
        <p class="submit-feedback error" id="cancel-reason-error" hidden></p>
      </div>

      <div class="confirm-modal-actions">
        <button type="button" class="btn-cancel" id="cancel-modal-no">Keep Application</button>
        <button type="button" class="btn-submit" id="cancel-modal-yes" style="background:#dc2626;">
          <i class="fas fa-ban"></i> Yes, Cancel Application
        </button>
      </div>
    </div>
  </div>
</div>

<script src="<?= cancel_h($appJsUrl) ?>"></script>
<script>
(function () {
  var modal = document.getElementById('cancel-confirm-modal');
  var closeBtn = document.getElementById('close-cancel-modal');
  var noBtn = document.getElementById('cancel-modal-no');
  var yesBtn = document.getElementById('cancel-modal-yes');
  var referenceEl = document.getElementById('cancel-modal-reference');
  var assistanceEl = document.getElementById('cancel-modal-assistance');
  var beneficiaryEl = document.getElementById('cancel-modal-beneficiary');
  var reasonInput = document.getElementById('cancel-reason');
  var reasonError = document.getElementById('cancel-reason-error');
  var openButtons = document.querySelectorAll('.js-open-cancel-modal');

  if (!modal || !yesBtn || !openButtons.length) return;

  var activeForm = null;

  function closeModal() {
    modal.style.display = 'none';
    document.body.classList.remove('modal-open');
    activeForm = null;
  }

  function openModal(form, button) {
    activeForm = form;
    if (reasonInput) reasonInput.value = '';
    if (reasonError) reasonError.hidden = true;
    if (referenceEl) referenceEl.textContent = button.getAttribute('data-reference') || '-';
    if (assistanceEl) assistanceEl.textContent = button.getAttribute('data-assistance') || '-';
    if (beneficiaryEl) beneficiaryEl.textContent = button.getAttribute('data-beneficiary') || '-';
    modal.style.display = 'flex';
    document.body.classList.add('modal-open');
  }

  openButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      var form = button.closest('.js-cancel-form');
      if (!form) return;
      openModal(form, button);
    });
  });

  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (noBtn) noBtn.addEventListener('click', closeModal);

  yesBtn.addEventListener('click', function () {
    if (!activeForm) return;
    var reason = reasonInput ? reasonInput.value.trim() : '';
    if (!reason) {
      if (reasonError) { reasonError.textContent = 'Enter a cancellation reason before continuing.'; reasonError.hidden = false; }
      if (reasonInput) reasonInput.focus();
      return;
    }
    if (!window.confirm('Are you sure you want to cancel this application? This action cannot be undone.')) return;
    var hiddenReason = activeForm.querySelector('input[name="cancellation_reason"]');
    if (!hiddenReason) { hiddenReason = document.createElement('input'); hiddenReason.type = 'hidden'; hiddenReason.name = 'cancellation_reason'; activeForm.appendChild(hiddenReason); }
    hiddenReason.value = reason;
    yesBtn.setAttribute('disabled', 'disabled');
    activeForm.submit();
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modal.style.display !== 'none') {
      closeModal();
    }
  });
})();
</script>
<?php require __DIR__ . '/../../chatbot/applicant-widget.php'; ?>
</body>
</html>
