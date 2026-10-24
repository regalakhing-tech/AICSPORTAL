<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';

pswdo_start_session();
$paths = pswdo_paths();

if (!isset($_SESSION['aics_pswdo_csrf']) || !is_string($_SESSION['aics_pswdo_csrf']) || $_SESSION['aics_pswdo_csrf'] === '') {
    $_SESSION['aics_pswdo_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['aics_pswdo_csrf'];

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = pswdo_current_display_name();
$initials = pswdo_initials($displayName);

$sortBy = pswdo_normalize_release_sort((string) ($_GET['sort_by'] ?? 'default'));
$releaseView = pswdo_normalize_release_view((string) ($_GET['release_view'] ?? 'all'));
$sortGroup = pswdo_normalize_release_sort_group($sortBy, (string) ($_GET['sort_group'] ?? 'all'));
$batchDateFrom = trim((string) ($_GET['batch_date_from'] ?? ''));
$batchDateTo = trim((string) ($_GET['batch_date_to'] ?? ''));
$batchKey = trim((string) ($_GET['batch_key'] ?? 'all'));
$batchLimit = (int) ($_GET['batch_limit'] ?? 10);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDateFrom)) {
    $batchDateFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDateTo)) {
    $batchDateTo = '';
}
if ($batchDateFrom !== '' && $batchDateTo !== '' && strcmp($batchDateFrom, $batchDateTo) > 0) {
    [$batchDateFrom, $batchDateTo] = [$batchDateTo, $batchDateFrom];
}
if ($batchLimit < 1) {
    $batchLimit = 10;
}
if ($batchLimit > 200) {
    $batchLimit = 200;
}
$selectedRequestId = (int) ($_GET['schedule_request'] ?? 0);
$actionStatus = trim((string) ($_GET['status'] ?? ''));
$actionMessage = trim((string) ($_GET['message'] ?? ''));

$summary = [
    'unscheduled' => 0,
    'scheduled_today' => 0,
    'upcoming' => 0,
    'ready_for_release' => 0,
    'missed' => 0,
    'released' => 0,
];
$queueItems = [];
$upcomingDates = [];
$selectedRecord = null;
$releaseHistory = [];
$loadError = '';

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);

    $profile = pswdo_fetch_staff_profile($connection, $accountId, $displayName);
    $displayName = (string) ($profile['display_name'] ?? $displayName);
    $initials = (string) ($profile['initials'] ?? $initials);

    $summary = pswdo_fetch_release_schedule_overview($connection);
    $queueItems = pswdo_fetch_release_schedule_queue(
        $connection,
        $releaseView,
        200,
        $sortBy,
        $sortGroup,
        $batchDateFrom,
        $batchDateTo,
        $batchKey
    );
    // Build the Release Batch dropdown from an UNFILTERED-by-batch fetch so every
    // batch in the date range stays visible even after the user picks a specific one.
    if ($batchKey !== '' && $batchKey !== 'all') {
        $allBatchItems = pswdo_fetch_release_schedule_queue(
            $connection,
            $releaseView,
            200,
            $sortBy,
            $sortGroup,
            $batchDateFrom,
            $batchDateTo,
            'all'
        );
    } else {
        $allBatchItems = $queueItems;
    }
    $upcomingDates = pswdo_fetch_upcoming_release_dates($connection);
    $releaseHistory = pswdo_fetch_release_notification_history($connection, 100);
    if ($selectedRequestId > 0) {
        $selectedRecord = pswdo_fetch_release_schedule_record($connection, $selectedRequestId);
    }

    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load release scheduling right now.';
}

$sortLabels = [
    'default' => 'Default Queue Order',
    'client_classification' => 'Beneficiary Classification',
    'type_of_assistance' => 'Type of Assistance',
];
$sortGroupLabels = [
    'client_classification' => [
        'all' => 'All',
        'senior_citizen' => 'Senior Citizen',
        'solo_parent' => 'Solo Parent',
        'pwd' => 'PWD',
        'ips' => 'IPS',
    ],
    'type_of_assistance' => [
        'all' => 'All',
        'medical' => 'Medical',
        'burial' => 'Burial',
        'victim_of_disaster' => 'Victim of Disaster',
        'special_cases' => 'Special Cases',
    ],
];
$activeSortGroupLabel = $sortGroupLabels[$sortBy][$sortGroup] ?? 'All';
$massNoticePreviewCount = count($queueItems);
$massNoticeEligibleCount = 0;
foreach ($queueItems as $queueItem) {
    $scheduledDate = trim((string) ($queueItem['scheduled_date'] ?? ''));
    $scheduledTime = trim((string) ($queueItem['scheduled_time'] ?? ''));
    $contactNumber = pswdo_applicant_sms_contact($queueItem);
    if ($scheduledDate !== '' && $scheduledDate !== '0000-00-00' && $scheduledTime !== '' && $scheduledTime !== '00:00:00' && $contactNumber !== '') {
        $massNoticeEligibleCount++;
    }
}
$massNoticeTargetCount = min($batchLimit, $massNoticeEligibleCount);
$batchOptions = ['all' => 'All Scheduled Batches'];
$batchOptionsSource = isset($allBatchItems) && is_array($allBatchItems) ? $allBatchItems : $queueItems;
foreach ($batchOptionsSource as $queueItem) {
    $scheduledDate = trim((string) ($queueItem['scheduled_date'] ?? ''));
    $batchName = trim((string) ($queueItem['release_batch'] ?? ''));
    if ($scheduledDate === '' || $scheduledDate === '0000-00-00' || $batchName === '') {
        continue;
    }
    $optionKey = $scheduledDate . '|' . $batchName;
    if (!isset($batchOptions[$optionKey])) {
        $batchOptions[$optionKey] = pswdo_date_label($scheduledDate) . ' - ' . $batchName;
    }
}
if ($batchKey !== 'all' && !isset($batchOptions[$batchKey])) {
    $batchKey = 'all';
}
$unscheduledQueueItems = array_values(array_filter(
    $queueItems,
    static fn(array $item): bool =>
        trim((string) ($item['scheduled_date'] ?? '')) === '' ||
        trim((string) ($item['scheduled_date'] ?? '')) === '0000-00-00'
));
$unscheduledCount = count($unscheduledQueueItems);

// ---- Batch Quota Partition (Morning vs Afternoon) ----
// Only count items that are scheduled (have a date+time) and not yet released.
// Morning = scheduled time strictly before 12:00 NN. Afternoon = 12:00 NN or later.
$morningBatch = [];
$afternoonBatch = [];
foreach ($queueItems as $queueItem) {
    $scheduledDate = trim((string) ($queueItem['scheduled_date'] ?? ''));
    $scheduledTime = trim((string) ($queueItem['scheduled_time'] ?? ''));
    $releaseStatus = strtolower(trim((string) ($queueItem['release_status'] ?? '')));
    if ($scheduledDate === '' || $scheduledDate === '0000-00-00' || $scheduledTime === '' || $scheduledTime === '00:00:00') {
        continue;
    }
    if (in_array($releaseStatus, ['released', 'distributed', 'completed', 'closed'], true)) {
        continue;
    }
    $hour = (int) substr($scheduledTime, 0, 2);
    if ($hour < 12) {
        $morningBatch[] = $queueItem;
    } else {
        $afternoonBatch[] = $queueItem;
    }
}
$morningCount = count($morningBatch);
$afternoonCount = count($afternoonBatch);
$morningQuotaPct = $batchLimit > 0 ? min(100, (int) round($morningCount / $batchLimit * 100)) : 0;
$afternoonQuotaPct = $batchLimit > 0 ? min(100, (int) round($afternoonCount / $batchLimit * 100)) : 0;
$morningOverflow = max(0, $morningCount - $batchLimit);
$afternoonOverflow = max(0, $afternoonCount - $batchLimit);
$batchFilterActive = ($batchDateFrom !== '' || $batchDateTo !== '');
$batchFilterLabel = '';
if ($batchDateFrom !== '' && $batchDateTo !== '' && $batchDateFrom === $batchDateTo) {
    $batchFilterLabel = pswdo_date_label($batchDateFrom);
} elseif ($batchDateFrom !== '' && $batchDateTo !== '') {
    $batchFilterLabel = pswdo_date_label($batchDateFrom) . ' – ' . pswdo_date_label($batchDateTo);
} elseif ($batchDateFrom !== '') {
    $batchFilterLabel = 'From ' . pswdo_date_label($batchDateFrom);
} elseif ($batchDateTo !== '') {
    $batchFilterLabel = 'Until ' . pswdo_date_label($batchDateTo);
}

$defaultScheduleDate = $selectedRecord !== null && trim((string) ($selectedRecord['scheduled_date'] ?? '')) !== ''
    ? (string) $selectedRecord['scheduled_date']
    : date('Y-m-d');
$defaultScheduleTime = $selectedRecord !== null && trim((string) ($selectedRecord['scheduled_time'] ?? '')) !== ''
    ? substr((string) $selectedRecord['scheduled_time'], 0, 5)
    : '09:00';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PSWDO Portal | Release Scheduling</title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= pswdo_h($paths['css_url']) ?>">
</head>
<body class="release-page" data-release-schedule-request-id="<?= $selectedRequestId > 0 ? $selectedRequestId : '' ?>">
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release" class="brand">
          <img src="<?= pswdo_h($paths['logo_url']) ?>" alt="AICS Logo" />
        </a>
        <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
      </div>

      <nav class="sidebar-nav">
        <p class="nav-title">PSWDO Menu</p>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=overview"><i class="fas fa-layer-group"></i> Overview Dashboard</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=map"><i class="fas fa-map-location-dot"></i> Map Analytics</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests"><i class="fas fa-folder-open"></i> Request Management</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=prescreening"><i class="fas fa-clipboard-check"></i> Screening Queue</a>
        <a class="nav-link active" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release"><i class="fas fa-calendar-check"></i> Release Scheduling</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=reports"><i class="fas fa-chart-pie"></i> Reports & Lists</a>
      </nav>

      <div class="sidebar-footer">
        <a href="<?= pswdo_h($paths['logout_url']) ?>" class="logout-link"><i class="fas fa-right-from-bracket"></i> Log Out</a>
      </div>
    </aside>

    <div class="sidebar-overlay" id="overlay"></div>

    <main class="main-content" id="main-content">
      <header class="top-nav">
        <div class="top-nav-right">
          <div class="top-chip"><i class="fas fa-calendar-days"></i> Distribution Preparation</div>
          <div class="profile-chip">
            <div class="avatar"><?= pswdo_h($initials) ?></div>
            <span><?= pswdo_h($displayName) ?></span>
          </div>
        </div>
      </header>

      <section class="content-wrap">
        <section class="panel-card page-header-card">
          <div class="page-header-main">
            <div class="release-page-title-icon"><i class="fas fa-calendar-check"></i></div>
            <div>
              <h1>Release Scheduling</h1>
              <p>Plan and track assistance distribution.</p>
            </div>
          </div>
        </section>

        <?php if ($actionStatus !== '' && $actionMessage !== ''): ?>
          <div class="alert toast-alert js-toast-alert <?= $actionStatus === 'success' ? 'alert-success' : 'alert-error' ?>" role="status" aria-live="polite">
            <i class="fas fa-<?= $actionStatus === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
            <span><?= pswdo_h($actionMessage) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
          <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?= pswdo_h($loadError) ?></div>
        <?php endif; ?>

        <section class="release-summary-grid">
          <article class="release-stat-card">
            <div class="release-stat-icon is-blue"><i class="fas fa-calendar-plus"></i></div><div><div class="rs-stat-value"><?= (int) ($summary['unscheduled'] ?? 0) ?></div><div class="rs-stat-label">Needs schedule</div></div>
          </article>
          <article class="release-stat-card">
            <div class="release-stat-icon is-cyan"><i class="fas fa-calendar-day"></i></div><div><div class="rs-stat-value"><?= (int) ($summary['scheduled_today'] ?? 0) ?></div><div class="rs-stat-label">Today</div></div>
          </article>
          <article class="release-stat-card">
            <div class="release-stat-icon is-violet"><i class="fas fa-clock"></i></div><div><div class="rs-stat-value"><?= (int) ($summary['upcoming'] ?? 0) ?></div><div class="rs-stat-label">Upcoming</div></div>
          </article>
          <article class="release-stat-card">
            <div class="release-stat-icon is-green"><i class="fas fa-box-open"></i></div><div><div class="rs-stat-value"><?= (int) ($summary['ready_for_release'] ?? 0) ?></div><div class="rs-stat-label">Ready</div></div>
          </article>
          <article class="release-stat-card">
            <div class="release-stat-icon is-amber"><i class="fas fa-calendar-xmark"></i></div><div><div class="rs-stat-value"><?= (int) ($summary['missed'] ?? 0) ?></div><div class="rs-stat-label">Missed</div></div>
          </article>
          <article class="release-stat-card">
            <div class="release-stat-icon is-slate"><i class="fas fa-circle-check"></i></div><div><div class="rs-stat-value"><?= (int) ($summary['released'] ?? 0) ?></div><div class="rs-stat-label">Released</div></div>
          </article>
        </section>

        <?php
          // Active batch = decided by current server time of day
          $currentHour = (int) date('G');
          $isMorningPhase = $currentHour < 12;
          $activeBatchCode = $isMorningPhase ? 'A' : 'B';
          $activeBatchLabel = $isMorningPhase ? 'Morning Batch' : 'Afternoon Batch';
          $activeBatchIcon = $isMorningPhase ? 'fa-sun' : 'fa-cloud-sun';
          $activeBatchCount = $isMorningPhase ? $morningCount : $afternoonCount;
          $activeBatchOverflow = $isMorningPhase ? $morningOverflow : $afternoonOverflow;
          $activeBatchPct = $isMorningPhase ? $morningQuotaPct : $afternoonQuotaPct;
          $otherBatchLabel = $isMorningPhase ? 'Afternoon Batch (B)' : 'Morning Batch (A)';
          // Show the real pending count per batch (decrements as applicants are received).
          $otherBatchCount = $isMorningPhase ? $afternoonCount : $morningCount;
          $accentColor = $isMorningPhase ? '#92400e' : '#1e3a8a';
          $accentMuted = $isMorningPhase ? '#b45309' : '#1d4ed8';
          $accentBg = $isMorningPhase ? '#fffbeb' : '#eff6ff';
          $accentBorder = $isMorningPhase ? '#fde68a' : '#bfdbfe';
          $accentTrack = $isMorningPhase ? '#fde68a' : '#bfdbfe';
          $accentFill = $isMorningPhase ? '#f59e0b' : '#3b82f6';
          // The batch card only makes sense for a single release day. Show it when
          // the worker has narrowed the view to one specific date (From == To) or
          // picked a saved batch from the dropdown.
          $singleDayFocus = $batchDateFrom !== '' && $batchDateFrom === $batchDateTo;
          $showBatchCard = $singleDayFocus || $batchFilterActive;
        ?>
        <?php if ($showBatchCard): ?>
        <section class="panel-card">
          <header class="panel-head">
            <h2><i class="fas fa-people-arrows"></i> Current Release Batch</h2>
            <span class="section-kicker">
              Max per batch: <strong><?= $batchLimit ?></strong>
              <?php if ($batchFilterActive): ?>
                &middot; <?= pswdo_h($batchFilterLabel) ?>
              <?php else: ?>
                &middot; All scheduled dates
              <?php endif; ?>
            </span>
          </header>
          <article style="border:1px solid <?= $accentBorder ?>;border-radius:12px;padding:18px 20px;background:<?= $accentBg ?>;margin-top:8px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:14px;">
              <div>
                <div style="font-size:11px;color:<?= $accentMuted ?>;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Batch <?= $activeBatchCode ?></div>
                <h3 style="margin:4px 0 2px;font-size:20px;color:<?= $accentColor ?>;"><i class="fas <?= $activeBatchIcon ?>"></i> <?= $activeBatchLabel ?></h3>
              </div>
              <div style="text-align:right;flex-shrink:0;">
                <div style="font-size:44px;font-weight:800;color:<?= $accentColor ?>;line-height:1;"><?= $activeBatchCount ?></div>
                <div style="font-size:12px;color:<?= $accentMuted ?>;margin-top:2px;"><?= $batchLimit ?> per-batch quota</div>
              </div>
            </div>
            <div style="background:<?= $accentTrack ?>;border-radius:6px;height:10px;overflow:hidden;">
              <div style="background:<?= $accentFill ?>;height:100%;width:<?= $activeBatchPct ?>%;transition:width 0.3s;"></div>
            </div>
            <?php if ($activeBatchOverflow > 0): ?>
              <div style="margin-top:10px;font-size:13px;color:#1e3a8a;background:#eff6ff;border:1px solid #bfdbfe;padding:8px 12px;border-radius:6px;">
                <i class="fas fa-circle-info"></i>
                <?php if ($isMorningPhase): ?>
                  <strong><?= $activeBatchOverflow ?></strong> applicant<?= $activeBatchOverflow === 1 ? '' : 's' ?> beyond the <?= $batchLimit ?>-slot morning quota — schedule the extra in the <strong>Afternoon Batch (B)</strong>.
                <?php else: ?>
                  <strong><?= $activeBatchOverflow ?></strong> applicant<?= $activeBatchOverflow === 1 ? '' : 's' ?> beyond the <?= $batchLimit ?>-slot afternoon quota — carry the extra to the <strong>next release date</strong>.
                <?php endif; ?>
              </div>
            <?php elseif ($activeBatchCount === 0): ?>
              <div style="margin-top:10px;font-size:13px;color:<?= $accentColor ?>;opacity:0.75;font-style:italic;">No applicants in this batch yet.</div>
            <?php endif; ?>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid <?= $accentBorder ?>;font-size:12.5px;color:<?= $accentMuted ?>;">
              Next: <?= $otherBatchLabel ?> &mdash; <strong><?= $otherBatchCount ?></strong> applicant<?= $otherBatchCount === 1 ? '' : 's' ?> waiting.
            </div>
          </article>

        </section>
        <?php endif; ?>

        <?php if (is_array($upcomingDates ?? null) && count($upcomingDates) > 0): ?>
        <section class="panel-card" style="padding:14px 18px;">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
            <div style="font-size:13.5px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
              <i class="fas fa-calendar-day" style="color:#0ea5e9;"></i> Saved Release Dates
            </div>
            <span style="font-size:11.5px;color:#64748b;">Click a date to view its batch progress.</span>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach ($upcomingDates as $dateRow): ?>
              <?php
                $chipDate = (string) ($dateRow['scheduled_date'] ?? '');
                if ($chipDate === '' || $chipDate === '0000-00-00') { continue; }
                $chipLabel = pswdo_date_label($chipDate);
                $chipCount = (int) ($dateRow['applicant_count'] ?? 0);
                $chipActive = $batchDateFrom === $chipDate && $batchDateTo === $chipDate;
                $chipHref = $paths['dashboard_url']
                  . '?page=release'
                  . '&batch_date_from=' . rawurlencode($chipDate)
                  . '&batch_date_to=' . rawurlencode($chipDate)
                  . '&batch_key=all&batch_limit=' . $batchLimit;
                $chipBg = $chipActive ? '#0ea5e9' : '#f1f5f9';
                $chipColor = $chipActive ? '#ffffff' : '#0f172a';
                $chipBorder = $chipActive ? '#0284c7' : '#cbd5e1';
              ?>
              <a href="<?= pswdo_h($chipHref) ?>"
                 style="display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;background:<?= $chipBg ?>;color:<?= $chipColor ?>;border:1px solid <?= $chipBorder ?>;font-size:12.5px;font-weight:600;text-decoration:none;transition:all 0.15s;">
                <?= pswdo_h($chipLabel) ?>
                <span style="opacity:0.85;font-weight:500;">&middot; <?= $chipCount ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>

        <section class="panel-card release-filter-panel">
          <form method="get" action="<?= pswdo_h($paths['dashboard_url']) ?>" class="filter-form release-filter-toolbar">
            <input type="hidden" name="page" value="release" />
            <input type="hidden" name="release_view" value="<?= pswdo_h($releaseView) ?>" />
            <div class="field-wrap release-search-field">
              <label for="release-search">Search</label>
              <input type="text" id="release-search" placeholder="Name or reference no…" autocomplete="off">
            </div>
            <div class="field-wrap">
              <label for="batch_date_from">From</label>
              <input type="date" id="batch_date_from" name="batch_date_from" value="<?= pswdo_h($batchDateFrom) ?>">
            </div>
            <div class="field-wrap">
              <label for="batch_date_to">To</label>
              <input type="date" id="batch_date_to" name="batch_date_to" value="<?= pswdo_h($batchDateTo) ?>">
            </div>
            <div class="field-wrap">
              <label for="batch_key">Batch</label>
              <select id="batch_key" name="batch_key">
                <?php foreach ($batchOptions as $optionKey => $optionLabel): ?>
                  <option value="<?= pswdo_h($optionKey) ?>" <?= $batchKey === $optionKey ? 'selected' : '' ?>><?= pswdo_h($optionLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-wrap">
              <label for="batch_limit">Limit</label>
              <input id="batch_limit" type="number" name="batch_limit" min="1" max="200" value="<?= $batchLimit ?>">
            </div>
            <button type="submit" class="btn-solid">
              <i class="fas fa-filter"></i> Apply
            </button>
            <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release"
               class="btn-outline" title="Reset all filters">
              <i class="fas fa-rotate-left"></i> Reset
            </a>
          </form>
          <?php foreach ($sortGroupLabels as $groupSortKey => $groupOptions): ?>
            <div class="release-quick-filter-block<?= $sortBy === $groupSortKey ? '' : ' is-hidden' ?>" data-sort-buttons-for="<?= pswdo_h($groupSortKey) ?>">
              <span class="release-quick-filter-label"><?= pswdo_h($sortLabels[$groupSortKey] ?? 'Sort') ?> filters</span>
              <div class="release-quick-filter-row">
                <?php foreach ($groupOptions as $groupCode => $groupLabel): ?>
                  <?php
                    $groupHref = $paths['dashboard_url']
                      . '?page=release'
                      . '&sort_by=' . rawurlencode($groupSortKey)
                      . '&sort_group=' . rawurlencode($groupCode)
                      . ($batchDateFrom !== '' ? ('&batch_date_from=' . rawurlencode($batchDateFrom)) : '')
                      . ($batchDateTo !== '' ? ('&batch_date_to=' . rawurlencode($batchDateTo)) : '')
                      . '&batch_key=' . rawurlencode($batchKey)
                      . '&batch_limit=' . rawurlencode((string) $batchLimit);
                  ?>
                  <a
                    href="<?= pswdo_h($groupHref) ?>"
                    class="queue-tab<?= $sortBy === $groupSortKey && $sortGroup === $groupCode ? ' active' : '' ?>"
                  >
                    <?= pswdo_h($groupLabel) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </section>

        <section class="panel-card release-queue-panel">
          <header class="panel-head release-queue-head">
            <div class="release-queue-head-main">
              <h2><i class="fas fa-table-list"></i> Release Queue</h2>
              <span class="release-count-badge"><?= count($queueItems) ?> case<?= count($queueItems) === 1 ? '' : 's' ?></span>
            </div>
            <div class="release-head-actions">
              <?php if ($unscheduledCount > 0): ?>
              <button type="button" class="btn-solid" id="open-bulk-schedule-btn">
                <i class="fas fa-calendar-check"></i> Set Schedule for All (<?= $unscheduledCount ?>)
              </button>
              <?php endif; ?>
              <button
                type="button"
                class="btn-solid secondary js-open-mass-sms-modal"
                data-recipient-count="<?= $massNoticePreviewCount ?>"
                data-ready-count="<?= $massNoticeTargetCount ?>"
                data-notice-ready="<?= $massNoticeTargetCount > 0 ? '1' : '0' ?>"
                data-mass-action-type="mass_send_release_schedule_sms"
                data-mass-decision-status="release_schedule"
                data-sort-by="<?= pswdo_h($sortBy) ?>"
                data-sort-group="<?= pswdo_h($sortGroup) ?>"
                data-batch-date-from="<?= pswdo_h($batchDateFrom) ?>"
                data-batch-date-to="<?= pswdo_h($batchDateTo) ?>"
                data-batch-key="<?= pswdo_h($batchKey) ?>"
                data-batch-limit="<?= $batchLimit ?>"
                aria-disabled="<?= $massNoticeTargetCount > 0 ? 'false' : 'true' ?>"
                title="<?= $massNoticeTargetCount > 0 ? 'Notify all eligible scheduled applicants.' : 'Schedule applicants with valid contact numbers first.' ?>"
              >
                <i class="fas fa-paper-plane"></i> Notify Scheduled Applicants<?= $massNoticeTargetCount > 0 ? ' (' . $massNoticeTargetCount . ')' : '' ?>
              </button>
            </div>
          </header>

          <?php if (count($queueItems) === 0): ?>
            <div class="release-empty-state"><i class="fas fa-calendar-plus"></i><div><strong>No applications are currently waiting for release scheduling.</strong><span>Applications approved for release will appear here.</span></div></div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="report-table release-queue-table">
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Applicant / Beneficiary</th>
                    <th>Applying For</th>
                    <th>Date Applied</th>
                    <th>Requirements</th>
                    <th>Current Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($queueItems as $item): ?>
                    <tr data-search="<?= pswdo_h(strtolower(
                        ($item['reference_no'] ?? '') . ' ' .
                        ($item['applicant_name'] ?? '') . ' ' .
                        ($item['beneficiary_name'] ?? '')
                    )) ?>">
                      <td><strong><?= pswdo_h((string) ($item['reference_no'] ?? '-')) ?></strong></td>
                      <td>
                        <div class="cell-user">
                          <strong><?= pswdo_h((string) ($item['applicant_name'] ?? 'Applicant')) ?></strong>
                          <span>Beneficiary: <?= pswdo_h((string) ($item['beneficiary_name'] ?? '-')) ?></span>
                          <span class="release-category"><?= pswdo_h((string) ($item['client_categories_label'] ?? 'None')) ?></span>
                        </div>
                      </td>
                      <td>
                        <?= pswdo_h((string) ($item['assistance_label'] ?? 'Assistance Request')) ?>
                      </td>
                      <td>
                        <?= pswdo_h((string) ($item['date_applied_label'] ?? 'Not available')) ?>
                      </td>
                      <td>
                        <?= pswdo_h((string) ($item['requirements_label'] ?? '0 / 0 files')) ?>
                      </td>
                      <td>
                        <span class="release-status-pill is-<?= pswdo_h(strtolower(trim((string) ($item['release_status'] ?? 'unscheduled')))) ?>">
                          <?= pswdo_h((string) ($item['release_status_label'] ?? 'Needs Schedule')) ?>
                        </span>
                      </td>
                      <td>
                        <div class="release-table-actions">
                          <?php
                            $itemReleaseStatus = strtolower(trim((string) ($item['release_status'] ?? '')));
                            $itemScheduleDate = trim((string) ($item['scheduled_date'] ?? ''));
                            $itemHasSchedule = $itemScheduleDate !== '' && $itemScheduleDate !== '0000-00-00';
                            $itemScheduleDue = $itemHasSchedule && $itemScheduleDate <= date('Y-m-d');
                          ?>
                          <button
                            type="button"
                            class="btn-solid btn-view-request js-open-release-schedule"
                            data-request-id="<?= (int) ($item['request_id'] ?? 0) ?>"
                            data-reference-no="<?= pswdo_h((string) ($item['reference_no'] ?? '')) ?>"
                            data-beneficiary-name="<?= pswdo_h((string) ($item['beneficiary_name'] ?? 'Applicant')) ?>"
                            data-assistance-label="<?= pswdo_h((string) ($item['assistance_label'] ?? 'Assistance Request')) ?>"
                            data-client-categories="<?= pswdo_h((string) ($item['client_categories_label'] ?? 'None')) ?>"
                            data-scheduled-date="<?= pswdo_h((string) ($item['scheduled_date'] ?? '')) ?>"
                            data-scheduled-time="<?= pswdo_h(
                              trim((string) ($item['scheduled_time'] ?? '')) !== '' ? substr((string) $item['scheduled_time'], 0, 5) : ''
                            ) ?>"
                            data-venue="<?= pswdo_h(trim((string) ($item['venue'] ?? '')) !== '' ? (string) $item['venue'] : 'PSWDO Office') ?>"
                            data-release-batch="<?= pswdo_h((string) ($item['release_batch'] ?? '')) ?>"
                            data-release-notes="<?= pswdo_h((string) ($item['release_notes'] ?? '')) ?>"
                            data-applicant-name="<?= pswdo_h((string) ($item['applicant_name'] ?? 'Applicant')) ?>"
                            data-contact-number="<?= pswdo_h(pswdo_applicant_sms_contact($item)) ?>"
                          >
                            <i class="fas <?= $itemHasSchedule ? 'fa-pen' : 'fa-calendar-plus' ?>"></i> <?= $itemHasSchedule ? 'Edit' : 'Schedule' ?>
                          </button>
                          <?php if ($itemHasSchedule && $itemReleaseStatus !== 'released'): ?>
                          <button
                            type="button"
                            class="btn-solid release-complete-btn js-open-mark-released"
                            data-request-id="<?= (int) ($item['request_id'] ?? 0) ?>"
                            data-beneficiary="<?= pswdo_h((string) ($item['beneficiary_name'] ?? 'this applicant')) ?>"
                            data-reference="<?= pswdo_h((string) ($item['reference_no'] ?? '')) ?>"
                            data-csrf="<?= pswdo_h($csrfToken) ?>"
                            data-return-sort="<?= pswdo_h($sortBy) ?>"
                            data-return-group="<?= pswdo_h($sortGroup) ?>"
                            data-return-date-from="<?= pswdo_h($batchDateFrom) ?>"
                            data-return-date-to="<?= pswdo_h($batchDateTo) ?>"
                            data-return-batch-key="<?= pswdo_h($batchKey) ?>"
                            data-return-batch-limit="<?= $batchLimit ?>"
                          >
                            <i class="fas fa-hand-holding-heart"></i> Release
                          </button>
                          <?php endif; ?>
                          <?php if (in_array($itemReleaseStatus, ['scheduled', 'rescheduled', 'missed'], true) && $itemScheduleDue): ?>
                          <button
                            type="button"
                            class="btn-outline release-reschedule-btn js-open-reschedule"
                            title="Did not arrive — reschedule this applicant"
                            data-request-id="<?= (int) ($item['request_id'] ?? 0) ?>"
                            data-beneficiary="<?= pswdo_h((string) ($item['beneficiary_name'] ?? 'this applicant')) ?>"
                            data-reference="<?= pswdo_h((string) ($item['reference_no'] ?? '')) ?>"
                            data-csrf="<?= pswdo_h($csrfToken) ?>"
                            data-return-sort="<?= pswdo_h($sortBy) ?>"
                            data-return-group="<?= pswdo_h($sortGroup) ?>"
                            data-return-date-from="<?= pswdo_h($batchDateFrom) ?>"
                            data-return-date-to="<?= pswdo_h($batchDateTo) ?>"
                            data-return-batch-key="<?= pswdo_h($batchKey) ?>"
                            data-return-batch-limit="<?= $batchLimit ?>"
                          >
                            <i class="fas fa-rotate-left"></i> Reschedule
                          </button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="panel-card release-history-panel">
          <header class="panel-head release-history-head">
            <div><h2><i class="fas fa-clock-rotate-left"></i> Release History</h2></div>
          </header>
          <?php if ($releaseHistory === []): ?>
            <div class="release-empty-state"><i class="fas fa-box-open"></i><div><strong>No finalized release activity yet.</strong><span>Completed release actions will remain available here.</span></div></div>
          <?php else: ?>
            <div class="table-wrap release-history-table-wrap">
              <table class="report-table release-history-table">
                <thead><tr><th>Reference</th><th>Applicant / Beneficiary</th><th>Municipality</th><th>Assistance</th><th>Release Status</th><th>Applicant SMS</th><th>Released At</th></tr></thead>
                <tbody>
                  <?php foreach ($releaseHistory as $history): ?>
                    <?php
                      $smsStatus = (string) $history['sms_status'];
                      $smsLabel = $smsStatus === 'sent' ? 'SMS Sent' : ($smsStatus === 'failed' ? 'SMS Failed' : 'Not Recorded');
                    ?>
                    <tr>
                      <td><strong><?= pswdo_h($history['reference_no']) ?></strong></td>
                      <td><?= pswdo_h($history['beneficiary_name']) ?></td>
                      <td><?= pswdo_h($history['municipality']) ?></td>
                      <td><?= pswdo_h($history['assistance']) ?></td>
                      <td><span class="release-history-badge released">Released</span></td>
                      <td><span class="release-history-badge <?= $smsStatus === 'failed' ? 'failed' : ($smsStatus === 'sent' ? 'sent' : 'muted') ?>"><?= pswdo_h($smsLabel) ?></span></td>
                      <td><?= pswdo_h($history['released_at_label']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      </section>
    </main>
  </div>

  <div class="release-schedule-modal" id="release-schedule-modal">
    <div class="sms-compose-card release-schedule-card" role="dialog" aria-modal="true" aria-labelledby="release-schedule-title">
      <div class="sms-compose-header">
        <h3 id="release-schedule-title"><i class="fas fa-calendar-check"></i> Schedule Release</h3>
        <button type="button" class="close-details-btn" id="close-release-schedule-modal" aria-label="Close release schedule modal">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <form method="post" action="workflow-action.php" id="release-schedule-modal-form" class="sms-compose-body release-schedule-form">
        <input type="hidden" name="csrf_token" value="<?= pswdo_h($csrfToken) ?>">
        <input type="hidden" name="action_type" value="save_release_schedule">
        <input type="hidden" name="request_id" id="release-request-id" value="">
        <input type="hidden" name="return_page" value="release">
        <input type="hidden" name="return_release_view" value="all">
        <input type="hidden" name="return_release_sort" value="<?= pswdo_h($sortBy) ?>">
        <input type="hidden" name="return_release_group" value="<?= pswdo_h($sortGroup) ?>">
        <input type="hidden" name="return_batch_date_from" value="<?= pswdo_h($batchDateFrom) ?>">
        <input type="hidden" name="return_batch_date_to" value="<?= pswdo_h($batchDateTo) ?>">
        <input type="hidden" name="return_batch_key" value="<?= pswdo_h($batchKey) ?>">
        <input type="hidden" name="return_batch_limit" value="<?= $batchLimit ?>">

        <div class="release-applicant-card">
          <div class="release-applicant-card-icon"><i class="fas fa-user"></i></div>
          <div class="release-applicant-card-body">
            <div class="release-applicant-name" id="release-modal-beneficiary">Applicant</div>
            <div class="release-applicant-meta" id="release-modal-details">Assistance Request | Beneficiary Classification: None</div>
            <div class="release-applicant-ref" id="release-modal-reference">-</div>
          </div>
        </div>

        <div class="release-schedule-fields-grid">
          <div class="field-wrap">
            <label for="release-modal-date">Release Date</label>
            <input id="release-modal-date" data-default-value="<?= pswdo_h($defaultScheduleDate) ?>" type="date" name="scheduled_date" value="<?= pswdo_h($defaultScheduleDate) ?>" required>
          </div>
          <div class="field-wrap">
            <label for="release-modal-time">Release Time</label>
            <input id="release-modal-time" data-default-value="<?= pswdo_h($defaultScheduleTime) ?>" type="time" name="scheduled_time" value="<?= pswdo_h($defaultScheduleTime) ?>" required>
          </div>
        </div>
        <input id="release-modal-venue" type="hidden" name="venue" value="PSWDO Office">
        <input type="hidden" name="batch_limit" value="<?= $batchLimit ?>">
        <input type="hidden" id="release-modal-notes" name="notes" value="">

        <div class="release-sms-section">
          <label class="release-sms-toggle">
            <input type="checkbox" id="release-modal-send-sms" name="send_sms" value="1" checked>
            <i class="fas fa-comment-sms"></i>
            Send SMS notice
          </label>
          <div id="release-modal-sms-fields">
            <div class="field-wrap release-sms-recipient">
              <label for="release-modal-sms-recipient">Applicant Phone Number</label>
              <input type="text" id="release-modal-sms-recipient" readonly>
            </div>
            <div class="field-wrap">
              <label for="release-modal-sms-message">Message <span class="field-optional">Optional</span></label>
              <textarea id="release-modal-sms-message" name="sms_message" rows="10" maxlength="600"></textarea>
            </div>
          </div>
        </div>

        <div class="confirm-actions">
          <button type="button" class="btn-outline" id="cancel-release-schedule">Cancel</button>
          <button type="submit" class="btn-solid"><i class="fas fa-calendar-check"></i> Save Schedule &amp; Notify</button>
        </div>
      </form>
    </div>
  </div>

  <div class="release-schedule-modal" id="bulk-schedule-modal">
    <div class="sms-compose-card release-schedule-card" role="dialog" aria-modal="true" aria-labelledby="bulk-schedule-title">
      <div class="sms-compose-header">
        <h3 id="bulk-schedule-title"><i class="fas fa-calendar-check"></i> Set Schedule for All</h3>
        <button type="button" class="close-details-btn" id="close-bulk-schedule-modal" aria-label="Close bulk schedule modal">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <form method="post" action="workflow-action.php" id="bulk-schedule-form" class="sms-compose-body release-schedule-form">
        <input type="hidden" name="csrf_token" value="<?= pswdo_h($csrfToken) ?>">
        <input type="hidden" name="action_type" value="bulk_save_release_schedule">
        <input type="hidden" name="return_page" value="release">
        <input type="hidden" name="return_release_view" value="all">
        <input type="hidden" name="return_release_sort" value="<?= pswdo_h($sortBy) ?>">
        <input type="hidden" name="return_release_group" value="<?= pswdo_h($sortGroup) ?>">
        <input type="hidden" name="return_batch_limit" value="<?= $batchLimit ?>">
        <input type="hidden" name="batch_limit" value="<?= $batchLimit ?>">
        <?php foreach ($unscheduledQueueItems as $bulkItem): ?>
          <input type="hidden" name="request_ids[]" value="<?= (int) ($bulkItem['request_id'] ?? 0) ?>">
        <?php endforeach; ?>

        <p class="mini-copy">
          <strong><?= $unscheduledCount ?></strong> unscheduled applicant<?= $unscheduledCount === 1 ? '' : 's' ?> in this queue will be assigned a release date. Batches are auto-assigned based on the Batch Send Limit (<?= $batchLimit ?> per batch).
        </p>

        <div class="release-schedule-fields-grid">
          <div class="field-wrap">
            <label for="bulk-modal-date">Release Date</label>
            <input id="bulk-modal-date" type="date" name="scheduled_date" value="<?= pswdo_h($defaultScheduleDate) ?>" required>
          </div>
          <div class="field-wrap">
            <label for="bulk-modal-time">Release Time</label>
            <input id="bulk-modal-time" type="time" name="scheduled_time" value="09:00" required>
          </div>
        </div>
        <input id="bulk-modal-venue" type="hidden" name="venue" value="PSWDO Office">
        <input id="bulk-modal-notes" type="hidden" name="notes" value="">

        <div class="confirm-actions">
          <button type="button" class="btn-outline" id="cancel-bulk-schedule">Cancel</button>
          <button type="submit" class="btn-solid"><i class="fas fa-calendar-check"></i> Save Schedule for All</button>
        </div>
      </form>
    </div>
  </div>

  <div class="sms-compose-modal" id="sms-compose-modal">
    <div class="sms-compose-card" role="dialog" aria-modal="true" aria-labelledby="sms-compose-title">
      <div class="sms-compose-header">
        <h3 id="sms-compose-title"><i class="fas fa-comment-sms"></i> Send SMS to Applicant</h3>
        <button type="button" class="close-details-btn" id="close-sms-compose-modal" aria-label="Close SMS modal">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <form method="post" action="workflow-action.php" id="sms-compose-form" class="sms-compose-body">
        <input type="hidden" name="csrf_token" value="<?= pswdo_h($csrfToken) ?>">
        <input type="hidden" name="action_type" id="sms-action-type" value="send_sms">
        <input type="hidden" name="request_id" id="sms-request-id" value="">
        <input type="hidden" name="decision_status" id="sms-decision-status" value="">
        <input type="hidden" name="document_id" id="sms-document-id" value="">
        <input type="hidden" name="document_review_status" id="sms-document-review-status" value="">
        <input type="hidden" name="document_label" id="sms-document-label" value="">
        <input type="hidden" name="return_page" value="release">
        <input type="hidden" name="return_release_view" value="all">
        <input type="hidden" name="return_release_sort" value="<?= pswdo_h($sortBy) ?>">
        <input type="hidden" name="return_release_group" value="<?= pswdo_h($sortGroup) ?>">
        <input type="hidden" name="return_batch_date_from" value="<?= pswdo_h($batchDateFrom) ?>">
        <input type="hidden" name="return_batch_date_to" value="<?= pswdo_h($batchDateTo) ?>">
        <input type="hidden" name="return_batch_key" value="<?= pswdo_h($batchKey) ?>">
        <input type="hidden" name="return_batch_limit" value="<?= $batchLimit ?>">

        <div class="field-wrap">
          <label for="sms-recipient-label">Applicant Phone Number</label>
          <input type="text" id="sms-recipient-label" name="recipient_number" value="" readonly>
        </div>
        <div class="field-wrap">
          <label for="sms-template-select">Message Template</label>
          <select id="sms-template-select"></select>
        </div>
        <div class="sms-mass-schedule-fields is-hidden" id="sms-mass-schedule-fields">
          <div class="field-wrap">
            <label for="sms-mass-schedule-date">Release Date</label>
            <input type="date" id="sms-mass-schedule-date" value="<?= pswdo_h(date('Y-m-d')) ?>">
          </div>
          <div class="field-wrap">
            <label for="sms-mass-schedule-time">Release Time</label>
            <input type="time" id="sms-mass-schedule-time" value="09:00">
          </div>
          <div class="field-wrap">
            <label for="sms-mass-schedule-venue">Venue</label>
            <input type="text" id="sms-mass-schedule-venue" value="PSWDO Office" placeholder="PSWDO Office / Distribution Venue">
          </div>
        </div>
        <div class="field-wrap">
          <label for="sms-message-text">Message Text</label>
          <textarea id="sms-message-text" name="message_text" rows="5" maxlength="600" required></textarea>
        </div>

        <div class="confirm-actions">
          <button type="button" class="btn-outline" id="cancel-sms-compose">Cancel</button>
          <button type="submit" class="btn-solid"><i class="fas fa-paper-plane"></i> Send SMS</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Confirm that the scheduled assistance has been released. -->
  <div class="confirm-modal release-confirm-modal" id="mark-released-modal" role="dialog" aria-modal="true" aria-labelledby="mark-released-title" aria-describedby="mark-released-question mark-released-desc">
    <div class="confirm-card release-confirm-card">
      <div class="release-confirm-icon"><i class="fas fa-hand-holding-heart"></i></div>
      <h3 id="mark-released-title">Confirm Release</h3>
      <p id="mark-released-question">Are you sure you want to release this assistance?</p>
      <p id="mark-released-desc" class="release-confirm-desc"></p>
      <form method="post" action="workflow-action.php" id="mark-released-form">
        <input type="hidden" name="csrf_token" id="mark-released-csrf" value="">
        <input type="hidden" name="action_type" value="update_release_status">
        <input type="hidden" name="request_id" id="mark-released-request-id" value="">
        <input type="hidden" name="release_status" value="released">
        <input type="hidden" name="return_page" value="release">
        <input type="hidden" name="return_release_view" value="all">
        <input type="hidden" name="return_release_sort" id="mark-released-sort" value="">
        <input type="hidden" name="return_release_group" id="mark-released-group" value="">
        <input type="hidden" name="return_batch_date_from" id="mark-released-date-from" value="">
        <input type="hidden" name="return_batch_date_to" id="mark-released-date-to" value="">
        <input type="hidden" name="return_batch_key" id="mark-released-batch-key" value="">
        <input type="hidden" name="return_batch_limit" id="mark-released-batch-limit" value="">
        <div class="confirm-actions">
          <button type="button" class="btn-outline" id="cancel-mark-released">Cancel</button>
          <button type="submit" class="btn-solid release-complete-btn"><i class="fas fa-check"></i> Confirm Release</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Reschedule (Did Not Arrive) modal -->
  <div class="confirm-modal" id="reschedule-modal">
    <div class="confirm-card" style="max-width:440px;">
      <h3><i class="fas fa-rotate-left" style="color:#b45309;"></i> Did Not Arrive — Reschedule</h3>
      <p id="reschedule-desc" style="margin-bottom:14px;"></p>
      <form method="post" action="workflow-action.php" id="reschedule-form">
        <input type="hidden" name="csrf_token" id="reschedule-csrf" value="">
        <input type="hidden" name="action_type" value="reschedule_unattended">
        <input type="hidden" name="request_id" id="reschedule-request-id" value="">
        <input type="hidden" name="return_page" value="release">
        <input type="hidden" name="return_release_view" value="unscheduled">
        <input type="hidden" name="return_release_sort" id="reschedule-sort" value="">
        <input type="hidden" name="return_release_group" id="reschedule-group" value="">
        <input type="hidden" name="return_batch_date_from" id="reschedule-date-from" value="">
        <input type="hidden" name="return_batch_date_to" id="reschedule-date-to" value="">
        <input type="hidden" name="return_batch_key" id="reschedule-batch-key" value="">
        <input type="hidden" name="return_batch_limit" id="reschedule-batch-limit" value="">
        <div style="padding:10px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;margin-bottom:14px;font-size:13px;color:#92400e;">
          <i class="fas fa-info-circle"></i>
          This will clear the scheduled date, time, and batch. The request will return to <strong>Needs Schedule</strong> so a new release date can be assigned.
        </div>
        <div class="confirm-actions">
          <button type="button" class="btn-outline" id="cancel-reschedule">Cancel</button>
          <button type="submit" class="btn-solid" style="background:#b45309;"><i class="fas fa-rotate-left"></i> Confirm Reschedule</button>
        </div>
      </form>
    </div>
  </div>

  <div class="confirm-modal" id="confirm-modal">
    <div class="confirm-card">
      <h3 id="confirm-title">Confirm Action</h3>
      <p id="confirm-message">Are you sure you want to continue?</p>
      <div class="confirm-actions">
        <button type="button" class="btn-outline" id="confirm-cancel">Cancel</button>
        <button type="button" class="btn-solid" id="confirm-accept">Continue</button>
      </div>
    </div>
  </div>

  <script src="<?= pswdo_h($paths['js_url']) ?>"></script>
  <script>
    (() => {
      const sortSelect = document.getElementById('sort_by');
      const quickBlocks = document.querySelectorAll('[data-sort-buttons-for]');
      if (!(sortSelect instanceof HTMLSelectElement) || quickBlocks.length === 0) {
        return;
      }

      function syncQuickButtons() {
        const selected = sortSelect.value;
        quickBlocks.forEach((block) => {
          const expected = block.getAttribute('data-sort-buttons-for');
          block.classList.toggle('is-hidden', expected !== selected);
        });
      }

      sortSelect.addEventListener('change', syncQuickButtons);
      syncQuickButtons();
    })();

    // Mark as Released modal
    (() => {
      const modal      = document.getElementById('mark-released-modal');
      const form       = document.getElementById('mark-released-form');
      const desc       = document.getElementById('mark-released-desc');
      const reqId      = document.getElementById('mark-released-request-id');
      const csrf       = document.getElementById('mark-released-csrf');
      const sort       = document.getElementById('mark-released-sort');
      const group      = document.getElementById('mark-released-group');
      const dateFrom   = document.getElementById('mark-released-date-from');
      const dateTo     = document.getElementById('mark-released-date-to');
      const batchKey   = document.getElementById('mark-released-batch-key');
      const batchLimit = document.getElementById('mark-released-batch-limit');
      const confirmBtn = form ? form.querySelector('button[type="submit"]') : null;
      const cancelBtn  = document.getElementById('cancel-mark-released');
      let triggerButton = null;

      if (!modal || !form || !desc || !reqId || !csrf) return;

      function openModal(btn) {
        triggerButton = btn;
        reqId.value      = btn.dataset.requestId    || '';
        csrf.value       = btn.dataset.csrf         || '';
        sort.value       = btn.dataset.returnSort   || '';
        group.value      = btn.dataset.returnGroup  || '';
        dateFrom.value   = btn.dataset.returnDateFrom || '';
        dateTo.value     = btn.dataset.returnDateTo   || '';
        batchKey.value   = btn.dataset.returnBatchKey || '';
        batchLimit.value = btn.dataset.returnBatchLimit || '';
        desc.textContent = (btn.dataset.beneficiary || 'This applicant')
          + ' · ' + (btn.dataset.reference || '');
        modal.classList.add('open');
        cancelBtn?.focus();
      }

      function closeModal() {
        modal.classList.remove('open');
        triggerButton?.focus();
      }

      document.querySelectorAll('.js-open-mark-released').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn));
      });

      cancelBtn?.addEventListener('click', closeModal);

      modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
      });

      document.addEventListener('keydown', e => {
        if (!modal.classList.contains('open')) return;
        if (e.key === 'Escape') {
          e.preventDefault();
          closeModal();
        } else if (e.key === 'Tab' && cancelBtn && confirmBtn) {
          if (e.shiftKey && document.activeElement === cancelBtn) {
            e.preventDefault();
            confirmBtn.focus();
          } else if (!e.shiftKey && document.activeElement === confirmBtn) {
            e.preventDefault();
            cancelBtn.focus();
          }
        }
      });
    })();

    // Reschedule (Did Not Arrive) modal
    (() => {
      const modal      = document.getElementById('reschedule-modal');
      const form       = document.getElementById('reschedule-form');
      const desc       = document.getElementById('reschedule-desc');
      const reqId      = document.getElementById('reschedule-request-id');
      const csrf       = document.getElementById('reschedule-csrf');
      const sort       = document.getElementById('reschedule-sort');
      const group      = document.getElementById('reschedule-group');
      const dateFrom   = document.getElementById('reschedule-date-from');
      const dateTo     = document.getElementById('reschedule-date-to');
      const batchKey   = document.getElementById('reschedule-batch-key');
      const batchLimit = document.getElementById('reschedule-batch-limit');
      const cancelBtn  = document.getElementById('cancel-reschedule');

      if (!modal || !form) return;

      function openModal(btn) {
        reqId.value      = btn.dataset.requestId    || '';
        csrf.value       = btn.dataset.csrf         || '';
        sort.value       = btn.dataset.returnSort   || '';
        group.value      = btn.dataset.returnGroup  || '';
        // Reschedule clears the applicant's date and returns them to "Needs Schedule".
        // Drop the active date/batch filter so they stay visible for re-assignment
        // instead of being filtered out by the old release-date range.
        dateFrom.value   = '';
        dateTo.value     = '';
        batchKey.value   = 'all';
        batchLimit.value = btn.dataset.returnBatchLimit || '';
        desc.textContent = 'Mark ' + (btn.dataset.beneficiary || 'this applicant')
          + ' (' + (btn.dataset.reference || '') + ') as did not arrive? Their current schedule will be cleared.';
        modal.classList.add('open');
      }

      function closeModal() { modal.classList.remove('open'); }

      document.querySelectorAll('.js-open-reschedule').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn));
      });
      cancelBtn?.addEventListener('click', closeModal);
      modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
      document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
      });
    })();
  </script>
  <script>
    (() => {
      const searchInput = document.getElementById("release-search");
      const tbody = document.querySelector(".release-queue-table tbody");
      if (!searchInput || !tbody) return;
      searchInput.addEventListener("input", () => {
        const term = searchInput.value.trim().toLowerCase();
        tbody.querySelectorAll("tr[data-search]").forEach((row) => {
          row.style.display = term === "" || row.getAttribute("data-search").includes(term) ? "" : "none";
        });
      });
    })();
  </script>
</body>
</html>
