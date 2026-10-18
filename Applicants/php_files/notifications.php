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
$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);

$logoUrl = $projectRootUrl . '/asset/logo.png';
$cssUrl = $applicantsBaseUrl . '/css files/applicant-pages.css?v=' . urlencode($assetCssVersion);
$appJsUrl = $applicantsBaseUrl . '/js files/applicant-pages.js?v=' . urlencode($assetJsVersion);
$logoutUrl = $phpFilesBaseUrl . '/auth-logout.php';
$notificationsStateUrl = $phpFilesBaseUrl . '/notifications-state.php';

/**
 * @param scalar|null $value
 */
function notif_h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function notif_datetime_label(string $raw): string
{
    $clean = trim($raw);
    if ($clean === '') {
        return 'Not available';
    }

    try {
        return (new DateTimeImmutable($clean))->format('F d, Y h:i A');
    } catch (Throwable $exception) {
        return $clean;
    }
}

function notif_time_ago(string $raw): string
{
    $clean = trim($raw);
    if ($clean === '') {
        return 'Now';
    }

    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $sentAt = new DateTimeImmutable($clean, new DateTimeZone('Asia/Manila'));
        $seconds = max(0, $now->getTimestamp() - $sentAt->getTimestamp());
    } catch (Throwable $exception) {
        return 'Recent';
    }

    if ($seconds < 60) {
        return 'Just now';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . 'm ago';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . 'h ago';
    }
    if ($seconds < 604800) {
        return floor($seconds / 86400) . 'd ago';
    }
    return floor($seconds / 604800) . 'w ago';
}

function notif_preview(string $message, int $limit = 145): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $message) ?? '');
    if (strlen($clean) <= $limit) {
        return $clean;
    }

    return rtrim(substr($clean, 0, max(0, $limit - 3))) . '...';
}

/**
 * @return array{title:string,icon:string,tone:string,kind:string}
 */
function notif_notice_meta(string $stage, string $message): array
{
    $stageClean = trim($stage);
    $stageNorm = strtolower($stageClean);
    $msgNorm = strtolower(trim($message));

    $containsAny = static function (string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }
        return false;
    };

    if ($containsAny($stageNorm . ' ' . $msgNorm, ['sms', 'text message'])) {
        return [
            'title' => $stageClean !== '' ? $stageClean : 'SMS Notification Sent',
            'icon' => 'comment-sms',
            'tone' => 'info',
            'kind' => 'sms',
        ];
    }

    if ($containsAny($stageNorm . ' ' . $msgNorm, ['not approved', 'invalid', 'rejected', 'denied', 'cancelled', 'canceled'])) {
        return [
            'title' => $stageClean !== '' ? $stageClean : 'Status Needs Attention',
            'icon' => 'triangle-exclamation',
            'tone' => 'pending',
            'kind' => 'status',
        ];
    }

    if ($containsAny($stageNorm . ' ' . $msgNorm, ['approved', 'released', 'distributed', 'completed'])) {
        return [
            'title' => $stageClean !== '' ? $stageClean : 'Status Approved',
            'icon' => 'circle-check',
            'tone' => 'ok',
            'kind' => 'status',
        ];
    }

    if ($containsAny($stageNorm . ' ' . $msgNorm, ['pending', 'review', 'screening', 'interview', 'incomplete'])) {
        return [
            'title' => $stageClean !== '' ? $stageClean : 'Status Update',
            'icon' => 'clock',
            'tone' => 'pending',
            'kind' => 'status',
        ];
    }

    return [
        'title' => $stageClean !== '' ? $stageClean : 'General Update',
        'icon' => 'circle-info',
        'tone' => 'info',
        'kind' => 'general',
    ];
}

$notifications = [];
$loadError = '';

if ($accountId > 0) {
    $connection = null;
    try {
        $connection = aics_db_connect();
        aics_ensure_auth_schema($connection);
        aics_ensure_notification_read_schema($connection);
        $visibleNotifications = aics_applicant_notification_visibility_sql();

        $sql = <<<SQL
            SELECT
                n.id,
                n.notification_stage,
                n.message_text,
                n.sent_at,
                r.reference_no,
                CASE WHEN rd.notification_id IS NULL THEN 1 ELSE 0 END AS is_unread
            FROM aics_request_notifications n
            INNER JOIN aics_account_requests ar ON ar.request_id = n.request_id
            INNER JOIN aics_requests r ON r.id = n.request_id
            LEFT JOIN aics_applicant_notification_reads rd ON rd.notification_id = n.id AND rd.account_id = ar.account_id
            WHERE ar.account_id = ? AND {$visibleNotifications}
            ORDER BY n.sent_at DESC, n.id DESC
            LIMIT 120
        SQL;
        $stmt = $connection->prepare($sql);
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!is_array($row)) {
                break;
            }

            $stage = trim((string) ($row['notification_stage'] ?? 'General Update'));
            $message = trim((string) ($row['message_text'] ?? ''));
            $message = $message !== '' ? $message : 'You have a new notification update from PSWDO.';
            $referenceNo = trim((string) ($row['reference_no'] ?? ''));
            $sentAt = trim((string) ($row['sent_at'] ?? ''));

            $meta = notif_notice_meta($stage, $message);

            $notifications[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) $meta['title'],
                'icon' => (string) $meta['icon'],
                'tone' => (string) $meta['tone'],
                'kind' => (string) $meta['kind'],
                'stage' => $stage !== '' ? $stage : 'General Update',
                'message' => $message,
                'preview' => notif_preview($message),
                'reference_no' => $referenceNo,
                'sent_at' => $sentAt,
                'sent_at_label' => notif_datetime_label($sentAt),
                'time_ago' => notif_time_ago($sentAt),
                'is_unread' => (int) ($row['is_unread'] ?? 0) === 1,
            ];
        }

        $stmt->close();
    } catch (Throwable $exception) {
        $loadError = 'Unable to load notifications right now. Please refresh the page in a moment.';
    } finally {
        if ($connection instanceof mysqli && $connection->connect_errno === 0) {
            $connection->close();
        }
    }
}

$hasNotifications = count($notifications) > 0;
$totalNotifications = count($notifications);
$unreadNotificationsCount = 0;
foreach ($notifications as $item) {
    if (!empty($item['is_unread'])) {
        $unreadNotificationsCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AICS Applicant | Notifications</title>
<link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
<link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= notif_h($cssUrl) ?>">
<style>
/* ==========================================================================
   Notifications Page - Modern Executive Design
   ========================================================================== */

.notifications-page .notif-hero-card {
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

.notifications-page .notif-hero-card::after {
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

.notifications-page .notif-hero-main {
  display: flex;
  align-items: center;
  gap: 20px;
  position: relative;
  z-index: 1;
  flex: 1;
}

.notifications-page .notif-hero-icon-pill {
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

.notifications-page .notif-hero-copy {
  flex: 1;
  min-width: 0;
}

.notifications-page .notif-hero-meta-badge {
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

.notifications-page .status-live-pulse-dot {
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

.notifications-page .notif-hero-card .page-title {
  font-size: clamp(21px, 2.2vw, 27px);
  font-weight: 800;
  color: #0b1220;
  margin: 0 0 6px;
  line-height: 1.25;
  letter-spacing: -0.02em;
}

.notifications-page .notif-hero-card .page-sub {
  font-size: 14.5px;
  color: #475569;
  line-height: 1.55;
  margin: 0;
  max-width: 82ch;
}

/* ---------- Container List Card ---------- */
.notifications-page .notif-list-card {
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 22px;
  padding: 24px 28px;
  box-shadow: 0 8px 26px rgba(15, 23, 42, 0.05);
}

.notifications-page .notif-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 18px;
  margin-bottom: 22px;
  border-bottom: 1.5px dashed #e2e8f0;
  flex-wrap: wrap;
}

.notifications-page .notif-head-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.notifications-page .notif-section-icon {
  width: 42px;
  height: 42px;
  border-radius: 13px;
  background: #f0f9ff;
  border: 1px solid #bae6fd;
  display: grid;
  place-items: center;
  font-size: 18px;
  color: #0284c7;
  flex-shrink: 0;
}

.notifications-page .notif-section-title {
  font-size: 18px;
  font-weight: 800;
  color: #0f172a;
  margin: 0 0 2px;
  letter-spacing: -0.01em;
}

.notifications-page .notif-section-sub {
  font-size: 13px;
  color: #64748b;
  margin: 0;
}

.notifications-page .notif-head-pills {
  display: flex;
  align-items: center;
  gap: 8px;
}

.notifications-page .notif-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: 999px;
  font-size: 12.5px;
  font-weight: 700;
}

.notifications-page .notif-pill-total {
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  color: #475569;
}

.notifications-page .notif-pill-unread {
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  color: #1d4ed8;
}

.notifications-page .notif-pulse-mini {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #2563eb;
  box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7);
  animation: liveBluePulse 1.8s infinite cubic-bezier(0.4, 0, 0.6, 1);
}

/* ---------- Empty State Card ---------- */
.notifications-page .notif-empty-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 52px 24px 44px;
  border-radius: 18px;
  background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
  border: 1.5px dashed #cbd5e1;
}

.notifications-page .notif-empty-icon-wrap {
  width: 82px;
  height: 82px;
  border-radius: 24px;
  background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
  border: 1.5px solid #7dd3fc;
  display: grid;
  place-items: center;
  margin-bottom: 20px;
  box-shadow: 0 10px 24px rgba(2, 132, 199, 0.15);
}

.notifications-page .notif-empty-icon {
  font-size: 34px;
  color: #0284c7;
}

.notifications-page .notif-empty-title {
  font-size: 20px;
  font-weight: 800;
  color: #0f172a;
  margin: 0 0 8px;
  letter-spacing: -0.01em;
}

.notifications-page .notif-empty-desc {
  font-size: 14px;
  color: #64748b;
  line-height: 1.6;
  max-width: 580px;
  margin: 0 0 22px;
}

.notifications-page .notif-empty-hint {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  padding: 9px 18px;
  border-radius: 999px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  font-size: 13px;
  color: #475569;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
}

.notifications-page .notif-empty-hint i {
  color: #0284c7;
}

/* ---------- Notification Items Stream ---------- */
.notifications-page .notif-items-stream {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.notifications-page .notice-item-card {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 18px 22px;
  border-radius: 16px;
  border: 1.5px solid #e2e8f0;
  background: #ffffff;
  text-align: left;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
  transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
  position: relative;
  overflow: hidden;
}

.notifications-page .notice-item-card:hover {
  background: #f8fafc;
  border-color: #38bdf8;
  transform: translateY(-2px);
  box-shadow: 0 10px 24px rgba(2, 132, 199, 0.1);
}

.notifications-page .notice-item-card.is-unread {
  background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%);
  border-color: #93c5fd;
  border-left: 4.5px solid #0284c7;
}

.notifications-page .notice-item-card.is-unread:hover {
  border-color: #60a5fa;
  border-left-color: #0369a1;
}

.notifications-page .notif-item-left {
  flex-shrink: 0;
}

.notifications-page .notif-item-avatar {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  display: grid;
  place-items: center;
  font-size: 19px;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
}

.notifications-page .notif-item-avatar.tone-ok {
  background: #dcfce7;
  color: #16a34a;
  border: 1px solid #bbf7d0;
}

.notifications-page .notif-item-avatar.tone-pending {
  background: #fef3c7;
  color: #d97706;
  border: 1px solid #fde68a;
}

.notifications-page .notif-item-avatar.tone-info {
  background: #e0f2fe;
  color: #0284c7;
  border: 1px solid #bae6fd;
}

.notifications-page .notif-item-avatar.tone-danger {
  background: #fee2e2;
  color: #dc2626;
  border: 1px solid #fecaca;
}

.notifications-page .notif-item-body {
  flex: 1;
  min-width: 0;
}

.notifications-page .notif-item-topline {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}

.notifications-page .notif-item-title {
  font-size: 15.5px;
  font-weight: 700;
  color: #0f172a;
}

.notifications-page .notif-item-new-chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 7px;
  border-radius: 6px;
  background: #0284c7;
  color: #ffffff;
  font-size: 10.5px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.notifications-page .notif-item-ref-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 9px;
  border-radius: 6px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  color: #0369a1;
  font-size: 12px;
  font-weight: 700;
}

.notifications-page .notif-item-ref-chip i {
  font-size: 10px;
  color: #0284c7;
}

.notifications-page .notif-item-preview {
  font-size: 13.5px;
  color: #64748b;
  line-height: 1.5;
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.notifications-page .notif-item-meta {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

.notifications-page .notif-time-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 600;
  padding: 5px 12px;
  border-radius: 999px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  color: #64748b;
  white-space: nowrap;
}

.notifications-page .notif-time-badge.tone-ok {
  background: #f0fdf4;
  border-color: #dcfce7;
  color: #15803d;
}

.notifications-page .notif-time-badge.tone-pending {
  background: #fffdf5;
  border-color: #fef3c7;
  color: #b45309;
}

.notifications-page .notif-time-badge.tone-info {
  background: #f0f9ff;
  border-color: #e0f2fe;
  color: #0369a1;
}

.notifications-page .notif-view-action {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  display: grid;
  place-items: center;
  font-size: 12px;
  color: #94a3b8;
  transition: all 0.2s ease;
}

.notifications-page .notice-item-card:hover .notif-view-action {
  background: #0284c7;
  border-color: #0284c7;
  color: #ffffff;
  transform: translateX(2px);
}

/* ---------- Modern Notification Detail Modal ---------- */
.notif-modal-card {
  max-width: 580px;
  width: 100%;
  border-radius: 24px;
  overflow: hidden;
  background: #ffffff;
  box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
  border: 1px solid #e2e8f0;
  animation: notifModalScale 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes notifModalScale {
  from { opacity: 0; transform: scale(0.95); }
  to { opacity: 1; transform: scale(1); }
}

.notif-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 22px 26px;
  background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
  color: #ffffff;
}

.notif-modal-header-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.notif-modal-icon {
  width: 44px;
  height: 44px;
  border-radius: 13px;
  background: rgba(255, 255, 255, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.28);
  display: grid;
  place-items: center;
  font-size: 19px;
  color: #ffffff;
  flex-shrink: 0;
}

.notif-modal-eyebrow {
  display: block;
  font-size: 11.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #bae6fd;
  margin-bottom: 2px;
}

.notif-modal-header h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  color: #ffffff;
  letter-spacing: -0.01em;
}

.notif-modal-close {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.25);
  color: #ffffff;
  display: grid;
  place-items: center;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s ease;
  flex-shrink: 0;
}

.notif-modal-close:hover {
  background: rgba(255, 255, 255, 0.3);
  transform: scale(1.05);
}

.notif-modal-body {
  padding: 24px 26px;
}

.notif-modal-meta-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 20px;
}

.notif-meta-cell {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 12px 16px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.notif-meta-cell.full-width {
  grid-column: 1 / -1;
}

.notif-meta-cell .meta-label {
  font-size: 11.5px;
  font-weight: 600;
  color: #64748b;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.notif-meta-cell .meta-label i {
  color: #0284c7;
}

.notif-meta-cell .meta-val {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}

.notif-modal-content-wrap {
  margin-bottom: 24px;
}

.notif-message-heading {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 12.5px;
  font-weight: 700;
  color: #475569;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 8px;
}

.notif-message-heading i {
  color: #0284c7;
}

.notif-message-box {
  background: #f0f9ff;
  border: 1.5px solid #bae6fd;
  border-radius: 14px;
  padding: 16px 18px;
}

.notif-message-box p {
  font-size: 14.5px;
  line-height: 1.65;
  color: #0f172a;
  margin: 0;
  white-space: pre-wrap;
}

.notif-modal-actions {
  display: flex;
  justify-content: flex-end;
}

.btn-notif-close {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 11px 24px;
  border-radius: 12px;
  background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
  border: none;
  color: #ffffff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(2, 132, 199, 0.25);
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

.btn-notif-close:hover {
  background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(2, 132, 199, 0.35);
}

/* ---------- Responsive Rules ---------- */
@media (max-width: 768px) {
  .notifications-page .notif-hero-card {
    padding: 20px 20px;
  }
  .notifications-page .notif-hero-main {
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
  }
  .notifications-page .notif-list-card {
    padding: 20px 18px;
  }
  .notifications-page .notif-section-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  .notifications-page .notice-item-card {
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
    padding: 16px 18px;
  }
  .notifications-page .notif-item-meta {
    width: 100%;
    justify-content: space-between;
    border-top: 1px dashed #e2e8f0;
    padding-top: 10px;
    margin-top: 4px;
  }
  .notif-modal-meta-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 480px) {
  .notif-modal-card {
    width: calc(100% - 16px);
  }
  .notif-modal-header {
    padding: 18px 20px;
  }
  .notif-modal-body {
    padding: 20px 18px;
  }
}
</style>
</head>
<body class="notifications-shell" data-account-status="<?= notif_h($currentAccountStatusCode) ?>" data-banned-modal-required="<?= $forceBannedModal ? '1' : '0' ?>">
<div class="app-container">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a href="<?= notif_h($dashboardUrl) ?>?page=overview" class="logo" style="padding-top: 4px;">
        <img src="<?= notif_h($logoUrl) ?>" alt="AICS | PSWDO Logo" style="height: 42px; width: auto; object-fit: contain;">
      </a>
      <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
    </div>

    <div class="nav-menu">
      <div class="nav-header">Main Menu</div>
      <a href="<?= notif_h($dashboardUrl) ?>?page=overview" class="nav-item "><i class="fas fa-border-all"></i> Overview Dashboard</a>
      <a href="<?= notif_h($dashboardUrl) ?>?page=profile" class="nav-item "><i class="fas fa-user-circle"></i> My Profile</a>

      <div class="nav-header nav-header-gap">Application</div>
      <a href="<?= notif_h($dashboardUrl) ?>?page=submit" class="nav-item "><i class="fas fa-file-signature"></i> Submit Application</a>
      <a href="<?= notif_h($dashboardUrl) ?>?page=documents" class="nav-item "><i class="fas fa-file-invoice"></i> Documents</a>
      <a href="<?= notif_h($dashboardUrl) ?>?page=status" class="nav-item "><i class="fas fa-route"></i> Application Status</a>
      <a href="<?= notif_h($dashboardUrl) ?>?page=cancel-request" class="nav-item "><i class="fas fa-pen-to-square"></i> Manage Applications</a>

      <div class="nav-header nav-header-gap">Support</div>
      <a href="<?= notif_h($dashboardUrl) ?>?page=notifications" class="nav-item active"><i class="fas fa-bell"></i> Notifications</a>
    </div>

    <div class="sidebar-footer">
      <a href="<?= notif_h($logoutUrl) ?>" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </aside>

  <div class="sidebar-overlay" id="overlay"></div>

  <main class="main-content" id="main-content">
    <header class="top-nav">
      <div class="nav-left">
        <div class="top-nav-page-info">
          <span class="top-nav-badge"><i class="fas fa-bell"></i></span>
          <div class="top-nav-text">
            <span class="top-nav-sub">Support</span>
            <h1 class="top-nav-heading">Notifications</h1>
          </div>
        </div>
      </div>

      <div class="nav-right">
        <div class="profile-chip">
          <div class="profile-av"><?= notif_h($initials) ?></div>
          <span><?= notif_h($displayName) ?></span>
        </div>
      </div>
    </header>

    <div class="content-area content-area-senior notifications-page">
      <section class="notif-hero-card">
        <div class="notif-hero-main">
          <div class="notif-hero-icon-pill">
            <i class="fas fa-bell"></i>
          </div>
          <div class="notif-hero-copy">
            <div class="notif-hero-meta-badge">
              <span class="status-live-pulse-dot"></span>
              REAL-TIME NOTIFICATIONS
            </div>
            <h1 class="page-title">Notification Center</h1>
          </div>
        </div>
      </section>

      <?php if ($loadError !== ''): ?>
        <div class="section-card">
          <div class="senior-alert senior-alert-error">
            <i class="fas fa-triangle-exclamation"></i>
            <span><?= notif_h($loadError) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <div class="notif-list-card">
        <div class="notif-section-head">
          <div class="notif-head-left">
            <div class="notif-section-icon"><i class="fas fa-inbox"></i></div>
            <div>
              <h2 class="notif-section-title">Latest Notifications</h2>
              <p class="notif-section-sub">Tap any notification to view complete details, reference code, and official advice</p>
            </div>
          </div>
          <div class="notif-head-pills">
            <?php if ($unreadNotificationsCount > 0): ?>
              <span class="notif-pill notif-pill-unread">
                <span class="notif-pulse-mini"></span>
                <?= (int) $unreadNotificationsCount ?> Unread
              </span>
            <?php endif; ?>
            <span class="notif-pill notif-pill-total">
              <?= (int) $totalNotifications ?> <?= $totalNotifications === 1 ? 'Message' : 'Messages' ?>
            </span>
          </div>
        </div>

        <?php if (!$hasNotifications): ?>
          <div class="notif-empty-card">
            <div class="notif-empty-icon-wrap">
              <div class="notif-empty-icon">
                <i class="fas fa-bell-slash"></i>
              </div>
            </div>
            <h3 class="notif-empty-title">No Notifications Yet</h3>
            <p class="notif-empty-desc">
              You're all caught up! Once the Provincial Social Welfare and Development Office (PSWDO) updates your request status or sends an SMS alert, updates will appear here automatically.
            </p>
            <div class="notif-empty-hint">
              <i class="fas fa-circle-info"></i>
              <span>Keep your contact number active to ensure you receive urgent SMS updates regarding your claim.</span>
            </div>
          </div>
        <?php else: ?>
          <div class="notif-items-stream">
            <?php foreach ($notifications as $item): ?>
              <button
                type="button"
                class="notice-item-card js-notice-item <?= $item['is_unread'] ? 'is-unread' : '' ?>"
                data-notification-id="<?= (int) $item['id'] ?>"
                data-unread="<?= $item['is_unread'] ? '1' : '0' ?>"
                data-title="<?= notif_h((string) $item['title']) ?>"
                data-stage="<?= notif_h((string) $item['stage']) ?>"
                data-reference-no="<?= notif_h((string) $item['reference_no']) ?>"
                data-message="<?= notif_h((string) $item['message']) ?>"
                data-sent-at="<?= notif_h((string) $item['sent_at_label']) ?>"
              >
                <div class="notif-item-left">
                  <div class="notif-item-avatar tone-<?= notif_h((string) $item['tone']) ?>">
                    <i class="fas fa-<?= notif_h((string) $item['icon']) ?>"></i>
                  </div>
                </div>

                <div class="notif-item-body">
                  <div class="notif-item-topline">
                    <span class="notif-item-title"><?= notif_h((string) $item['title']) ?></span>
                    <?php if ($item['is_unread']): ?>
                      <span class="notif-item-new-chip">New</span>
                    <?php endif; ?>
                    <?php if ((string) $item['reference_no'] !== ''): ?>
                      <span class="notif-item-ref-chip">
                        <i class="fas fa-hashtag"></i> <?= notif_h((string) $item['reference_no']) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <p class="notif-item-preview"><?= notif_h((string) $item['preview']) ?></p>
                </div>

                <div class="notif-item-meta">
                  <span class="notif-time-badge tone-<?= notif_h((string) $item['tone']) ?>">
                    <i class="far fa-clock"></i> <?= notif_h((string) $item['time_ago']) ?>
                  </span>
                  <span class="notif-view-action">
                    <i class="fas fa-chevron-right"></i>
                  </span>
                </div>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<div class="preview-modal-overlay" id="notification-message-modal" style="display: none;">
  <div class="confirm-modal-card notif-modal-card" role="dialog" aria-modal="true" aria-labelledby="notification-modal-title">
    <div class="notif-modal-header">
      <div class="notif-modal-header-left">
        <div class="notif-modal-icon">
          <i class="fas fa-bell"></i>
        </div>
        <div>
          <span class="notif-modal-eyebrow">AICS PSWDO Notification</span>
          <h3 id="notification-modal-title"><span id="notification-modal-title-text">Notification Details</span></h3>
        </div>
      </div>
      <button type="button" class="notif-modal-close" id="close-notification-modal" aria-label="Close notification details">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <div class="notif-modal-body">
      <div class="notif-modal-meta-grid">
        <div class="notif-meta-cell">
          <span class="meta-label"><i class="fas fa-layer-group"></i> Stage / Type</span>
          <strong class="meta-val" id="notification-modal-stage">-</strong>
        </div>
        <div class="notif-meta-cell">
          <span class="meta-label"><i class="fas fa-hashtag"></i> Reference No.</span>
          <strong class="meta-val" id="notification-modal-reference">-</strong>
        </div>
        <div class="notif-meta-cell full-width">
          <span class="meta-label"><i class="far fa-clock"></i> Date & Time Received</span>
          <strong class="meta-val" id="notification-modal-time">-</strong>
        </div>
      </div>

      <div class="notif-modal-content-wrap">
        <span class="notif-message-heading"><i class="fas fa-message"></i> Official Message</span>
        <div class="notif-message-box">
          <p id="notification-modal-message">-</p>
        </div>
      </div>

      <div class="notif-modal-actions">
        <button type="button" class="btn-notif-close" id="close-notification-modal-footer">
          <i class="fas fa-check"></i> Mark as Read
        </button>
      </div>
    </div>
  </div>
</div>

<script src="<?= notif_h($appJsUrl) ?>"></script>
<script>
(() => {
  const modal = document.getElementById("notification-message-modal");
  if (!modal) return;

  const openButtons = Array.from(document.querySelectorAll(".js-notice-item"));
  const closeHeaderBtn = document.getElementById("close-notification-modal");
  const closeFooterBtn = document.getElementById("close-notification-modal-footer");
  const titleText = document.getElementById("notification-modal-title-text");
  const stage = document.getElementById("notification-modal-stage");
  const reference = document.getElementById("notification-modal-reference");
  const sentAt = document.getElementById("notification-modal-time");
  const message = document.getElementById("notification-modal-message");

  const closeModal = () => {
    modal.style.display = "none";
    document.body.classList.remove("modal-open");
  };

  const openModalFrom = (button) => {
    if (!button) return;
    const data = button.dataset || {};
    if (titleText) titleText.textContent = data.title || "Notification Details";
    if (stage) stage.textContent = data.stage || "General Update";
    if (reference) reference.textContent = data.referenceNo || "Not available";
    if (sentAt) sentAt.textContent = data.sentAt || "Not available";
    if (message) message.textContent = data.message || "No message details available.";
    modal.style.display = "flex";
    document.body.classList.add("modal-open");
    const notificationId = Number(data.notificationId || 0);
    if (notificationId > 0 && data.unread === "1") {
      fetch("<?= notif_h($notificationsStateUrl) ?>", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ notification_id: notificationId })
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload.ok) return;
          button.dataset.unread = "0";
          button.classList.remove("is-unread");
          button.querySelector(".notif-item-new-chip")?.remove();
          window.AICS_refreshNotificationBadge?.();
        }).catch(() => {});
    }
  };

  openButtons.forEach((button) => {
    button.addEventListener("click", () => openModalFrom(button));
  });

  if (closeHeaderBtn) {
    closeHeaderBtn.addEventListener("click", closeModal);
  }
  if (closeFooterBtn) {
    closeFooterBtn.addEventListener("click", closeModal);
  }
  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.style.display === "flex") {
      closeModal();
    }
  });
})();
</script>
<?php require __DIR__ . '/../../chatbot/applicant-widget.php'; ?>
</body>
</html>
