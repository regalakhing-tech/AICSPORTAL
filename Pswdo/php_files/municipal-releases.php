<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-common.php';
require_once __DIR__ . '/pswdo-data-lib.php';

pswdo_start_session();
$paths = pswdo_paths();

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = pswdo_current_display_name();
$initials = pswdo_initials($displayName);

$selectedMunicipality = trim((string) ($_GET['municipality'] ?? 'Hamtic'));
if ($selectedMunicipality === '') {
    $selectedMunicipality = 'Hamtic';
}

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'scheduled', 'released'], true)) {
    $statusFilter = 'all';
}

$searchQuery = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$records = [];
$muniCounts = [];
$loadError = '';

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);

    $profile = pswdo_fetch_staff_profile($connection, $accountId, $displayName);
    $displayName = (string) ($profile['display_name'] ?? $displayName);
    $initials = (string) ($profile['initials'] ?? $initials);

    $muniCounts = pswdo_fetch_release_municipality_counts($connection);
    $records = pswdo_fetch_all_release_records($connection, [
        'municipality' => $selectedMunicipality,
        'status' => $statusFilter,
        'search' => $searchQuery,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'limit' => 500,
    ]);

    $connection->close();
} catch (Throwable $e) {
    $loadError = 'Unable to load municipal release records right now: ' . $e->getMessage();
}

$allMunicipalities = pswdo_antique_municipalities();

// Calculate summary stats for the current view
$totalCount = count($records);
$scheduledCount = 0;
$releasedCount = 0;
$totalDisbursed = 0.0;

foreach ($records as $r) {
    if (($r['status_code'] ?? '') === 'released') {
        $releasedCount++;
    } elseif (($r['status_code'] ?? '') === 'scheduled') {
        $scheduledCount++;
    }
    if ($r['amount_given'] !== null) {
        $totalDisbursed += (float) $r['amount_given'];
    }
}

$defaultMswdoEmail = pswdo_municipality_mswdo_email($selectedMunicipality);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PSWDO Portal | List of All Releases by Municipality</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= pswdo_h($paths['css_url']) ?>">
  <style>
    /* ── Municipal Releases Workspace Styling ─────────────────── */
    .muni-filter-bar {
      display: flex;
      gap: 8px;
      overflow-x: auto;
      padding: 6px 2px 14px;
      margin-bottom: 12px;
      scrollbar-width: thin;
    }
    .muni-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 14px;
      border-radius: 9999px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      color: #334155;
      font-size: 13px;
      font-weight: 500;
      text-decoration: none;
      white-space: nowrap;
      transition: all 0.2s ease;
      cursor: pointer;
    }
    .muni-pill:hover {
      background: #f1f5f9;
      border-color: #cbd5e1;
      color: #0f172a;
    }
    .muni-pill.active {
      background: #0284c7;
      border-color: #0284c7;
      color: #ffffff;
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
    }
    .muni-pill-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 2px 7px;
      border-radius: 9999px;
      font-size: 11px;
      font-weight: 700;
      background: #f1f5f9;
      color: #475569;
    }
    .muni-pill.active .muni-pill-badge {
      background: rgba(255, 255, 255, 0.25);
      color: #ffffff;
    }

    /* Stat Cards Row */
    .muni-stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      margin-bottom: 20px;
    }
    .muni-stat-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      gap: 16px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .muni-stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .muni-stat-icon {
      width: 46px;
      height: 46px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      flex-shrink: 0;
    }
    .muni-stat-icon.total { background: #e0f2fe; color: #0284c7; }
    .muni-stat-icon.scheduled { background: #cffafe; color: #0891b2; }
    .muni-stat-icon.released { background: #dcfce7; color: #16a34a; }
    .muni-stat-icon.amount { background: #fef3c7; color: #d97706; }
    .muni-stat-val { font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1.2; }
    .muni-stat-lbl { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }

    /* Action bar */
    .muni-actions-panel {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      background: #ffffff;
      padding: 14px 20px;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      margin-bottom: 16px;
    }
    .filter-group-wrap {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
    }

    /* Badges */
    .badge-released {
      background: #dcfce7;
      color: #15803d;
      border: 1px solid #bbf7d0;
      padding: 4px 10px;
      border-radius: 9999px;
      font-size: 11px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .badge-scheduled {
      background: #cffafe;
      color: #0e7490;
      border: 1px solid #a5f3fc;
      padding: 4px 10px;
      border-radius: 9999px;
      font-size: 11px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .badge-approved {
      background: #fef3c7;
      color: #b45309;
      border: 1px solid #fde68a;
      padding: 4px 10px;
      border-radius: 9999px;
      font-size: 11px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    /* Emerald Green Action Buttons */
    .btn-emerald {
      background: #059669 !important;
      border: 1px solid #059669 !important;
      color: #ffffff !important;
      box-shadow: 0 2px 6px rgba(5, 150, 105, 0.25);
      transition: all 0.2s ease;
    }
    .btn-emerald:hover,
    .btn-emerald:focus-visible {
      background: #047857 !important;
      border-color: #047857 !important;
      color: #ffffff !important;
      box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35);
      transform: translateY(-1px);
    }
    .btn-emerald:active {
      background: #065f46 !important;
      border-color: #065f46 !important;
      transform: translateY(0);
    }

    /* Floating sticky selection action bar */
    .selection-action-bar {
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%) translateY(120px);
      background: #0f172a;
      color: #ffffff;
      padding: 12px 24px;
      border-radius: 9999px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.3);
      display: flex;
      align-items: center;
      gap: 18px;
      z-index: 1000;
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .selection-action-bar.visible {
      transform: translateX(-50%) translateY(0);
    }
    .selection-count-badge {
      background: #0284c7;
      color: #fff;
      padding: 4px 10px;
      border-radius: 9999px;
      font-weight: 700;
      font-size: 12px;
    }
    .selection-action-bar .bar-btn-pill {
      background: #ffffff !important;
      color: #0f172a !important;
      border: 1px solid #cbd5e1 !important;
      padding: 8px 16px !important;
      font-size: 13px !important;
      font-weight: 700 !important;
      border-radius: 9999px !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: 7px !important;
      cursor: pointer !important;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.12) !important;
      transition: all 0.2s ease !important;
    }
    .selection-action-bar .bar-btn-pill:hover {
      background: #f8fafc !important;
      border-color: #94a3b8 !important;
      color: #0284c7 !important;
      transform: translateY(-1px) !important;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2) !important;
    }
    .selection-action-bar .bar-btn-pill:active {
      transform: translateY(0) !important;
    }

    /* Modal Styling */
    .email-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(4px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 2000;
      padding: 16px;
    }
    .email-modal-overlay.active {
      display: flex;
    }
    .email-modal-card {
      background: #ffffff;
      border-radius: 20px;
      width: 100%;
      max-width: 640px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
      border: 1px solid #e2e8f0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      animation: modalSlideUp 0.25s ease-out;
    }
    @keyframes modalSlideUp {
      from { opacity: 0; transform: translateY(20px) scale(0.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .email-modal-header {
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
      color: #ffffff;
      padding: 20px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .email-modal-body {
      padding: 24px;
      max-height: 80vh;
      overflow-y: auto;
    }
    .pdf-attachment-card {
      background: #f8fafc;
      border: 1px dashed #cbd5e1;
      border-radius: 12px;
      padding: 14px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }
  </style>
</head>
<body class="reports-page">
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=overview" class="brand">
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
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release"><i class="fas fa-calendar-check"></i> Release Scheduling</a>
        <?= pswdo_mswdo_nav_link($paths['dashboard_url'], true) ?>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=reports"><i class="fas fa-chart-pie"></i> Reports &amp; Lists</a>
      </nav>

      <div class="sidebar-footer">
        <a href="<?= pswdo_h($paths['logout_url']) ?>" class="logout-link"><i class="fas fa-right-from-bracket"></i> Log Out</a>
      </div>
    </aside>

    <div class="sidebar-overlay" id="overlay"></div>

    <main class="main-content" id="main-content">
      <header class="top-nav">
        <div class="top-nav-right">
          <div class="top-chip"><i class="fas fa-truck-ramp-box"></i> Municipal Releases</div>
          <div class="profile-chip">
            <div class="avatar"><?= pswdo_h($initials) ?></div>
            <span><?= pswdo_h($displayName) ?></span>
          </div>
        </div>
      </header>

      <section class="content-wrap">
        <!-- Page Header Card -->
        <section class="panel-card page-header-card reports-header-card" style="margin-bottom: 16px;">
          <div class="reports-header-content">
            <div class="reports-header-icon-box" style="background:#e0f2fe; color:#0284c7;">
              <i class="fas fa-truck-ramp-box"></i>
            </div>
            <div class="reports-header-titles">
              <div class="reports-title-row">
                <h1>List of All Releases &amp; Municipal Masterlists</h1>
                <span class="reports-status-tag"><i class="fas fa-shield-check"></i> PSWDO Antique</span>
              </div>
              <p class="reports-header-desc">
                Organize, review, filter by municipality, and transmit official release registries directly to municipal MSWDOs (e.g., Hamtic) in standardized PDF format.
              </p>
            </div>
          </div>
          <div class="page-header-actions" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <a
              id="header-preview-pdf-btn"
              href="release-pdf-export.php?download=0&municipality=<?= rawurlencode($selectedMunicipality) ?>&status=<?= rawurlencode($statusFilter) ?>&search=<?= rawurlencode($searchQuery) ?>"
              target="_blank"
              class="btn-outline"
              title="Preview official PDF Masterlist in browser"
            >
              <i class="fas fa-eye" style="color:#0284c7;"></i> Preview PDF
            </a>
            <a
              id="header-download-pdf-btn"
              href="release-pdf-export.php?download=1&municipality=<?= rawurlencode($selectedMunicipality) ?>&status=<?= rawurlencode($statusFilter) ?>&search=<?= rawurlencode($searchQuery) ?>"
              class="btn-outline"
              title="Download PDF Masterlist file"
            >
              <i class="fas fa-download" style="color:#10b981;"></i> Download PDF
            </a>
            <button
              type="button"
              class="btn-solid btn-emerald"
              id="header-email-btn"
              title="Open email transmission dialog"
            >
              <i class="fas fa-paper-plane"></i> Email to MSWDO
            </button>
          </div>
        </section>

        <?php if ($loadError !== ''): ?>
          <div class="alert alert-error" style="margin-bottom:16px;">
            <i class="fas fa-triangle-exclamation"></i> <?= pswdo_h($loadError) ?>
          </div>
        <?php endif; ?>

        <!-- Municipality Quick Filter Bar -->
        <div class="muni-filter-bar" role="navigation" aria-label="Municipalities">
          <a
            href="<?= pswdo_h($paths['dashboard_url']) ?>?page=all_releases&municipality=all&status=<?= rawurlencode($statusFilter) ?>"
            class="muni-pill <?= strtolower($selectedMunicipality) === 'all' ? 'active' : '' ?>"
          >
            <i class="fas fa-map"></i> All Municipalities
            <span class="muni-pill-badge">
              <?php
                $provinceTotal = 0;
                foreach ($muniCounts as $mc) { $provinceTotal += (int)$mc['total']; }
                echo $provinceTotal;
              ?>
            </span>
          </a>

          <?php foreach ($allMunicipalities as $m): ?>
            <?php
              $mCount = (int) ($muniCounts[$m]['total'] ?? 0);
              $isActive = strcasecmp($selectedMunicipality, $m) === 0;
            ?>
            <a
              href="<?= pswdo_h($paths['dashboard_url']) ?>?page=all_releases&municipality=<?= rawurlencode($m) ?>&status=<?= rawurlencode($statusFilter) ?>"
              class="muni-pill <?= $isActive ? 'active' : '' ?>"
            >
              <?= pswdo_h($m) ?>
              <span class="muni-pill-badge"><?= $mCount ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- Stat Counter Cards -->
        <div class="muni-stat-grid">
          <div class="muni-stat-card">
            <div class="muni-stat-icon total"><i class="fas fa-clipboard-list"></i></div>
            <div>
              <div class="muni-stat-val"><?= $totalCount ?></div>
              <div class="muni-stat-lbl">Total in <?= pswdo_h($selectedMunicipality !== 'all' ? $selectedMunicipality : 'Antique') ?></div>
            </div>
          </div>
          <div class="muni-stat-card">
            <div class="muni-stat-icon scheduled"><i class="fas fa-calendar-check"></i></div>
            <div>
              <div class="muni-stat-val"><?= $scheduledCount ?></div>
              <div class="muni-stat-lbl">Scheduled for Release</div>
            </div>
          </div>
          <div class="muni-stat-card">
            <div class="muni-stat-icon released"><i class="fas fa-hand-holding-heart"></i></div>
            <div>
              <div class="muni-stat-val"><?= $releasedCount ?></div>
              <div class="muni-stat-lbl">Completed Releases</div>
            </div>
          </div>
          <div class="muni-stat-card">
            <div class="muni-stat-icon amount"><i class="fas fa-peso-sign"></i></div>
            <div>
              <div class="muni-stat-val">PHP <?= number_format($totalDisbursed, 2) ?></div>
              <div class="muni-stat-lbl">Total Disbursed</div>
            </div>
          </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <section class="muni-actions-panel">
          <form method="get" action="<?= pswdo_h($paths['dashboard_url']) ?>" class="filter-group-wrap" style="flex:1;">
            <input type="hidden" name="page" value="all_releases" />
            <input type="hidden" name="municipality" value="<?= pswdo_h($selectedMunicipality) ?>" />

            <div class="field-wrap" style="min-width: 170px;">
              <select name="status" onchange="this.form.submit()" style="padding: 7px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>>Scheduled for Release</option>
                <option value="released" <?= $statusFilter === 'released' ? 'selected' : '' ?>>Completed / Released</option>
              </select>
            </div>

            <div class="field-wrap" style="flex:1; max-width: 320px;">
              <input
                type="text"
                name="search"
                value="<?= pswdo_h($searchQuery) ?>"
                placeholder="Search reference, name, barangay..."
                style="width: 100%; padding: 7px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;"
              />
            </div>

            <button type="submit" class="btn-solid" style="padding: 7px 16px; font-size: 13px;">
              <i class="fas fa-magnifying-glass"></i> Filter
            </button>

            <?php if ($searchQuery !== '' || $statusFilter !== 'all'): ?>
              <a
                href="<?= pswdo_h($paths['dashboard_url']) ?>?page=all_releases&municipality=<?= rawurlencode($selectedMunicipality) ?>"
                class="btn-outline"
                style="padding: 7px 12px; font-size: 13px;"
              >
                Clear Filters
              </a>
            <?php endif; ?>
          </form>

          <div style="display:flex; gap:10px; align-items:center;">
            <span style="font-size:13px; color:#64748b; font-weight:600;">
              Showing <?= count($records) ?> record<?= count($records) === 1 ? '' : 's' ?>
            </span>
          </div>
        </section>

        <!-- Main Release Records Table -->
        <section class="panel-card report-log-panel">
          <header class="panel-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
              <p class="report-list-title">
                <i class="fas fa-list-check" style="color:#0284c7;"></i>
                Release Masterlist: <?= pswdo_h($selectedMunicipality !== 'all' ? 'Municipality of ' . $selectedMunicipality : 'All Municipalities') ?>
              </p>
              <p class="report-list-subtitle">
                Applications are listed by date, oldest first. Select individual or all beneficiaries below to dispatch via email in official PDF format.
              </p>
            </div>
            <div>
              <button type="button" class="btn-outline" id="btn-select-all-top" style="font-size:13px;">
                <i class="fas fa-square-check"></i> Select All <?= count($records) ?>
              </button>
            </div>
          </header>

          <?php if (count($records) === 0): ?>
            <div style="text-align:center; padding: 48px 24px; color:#64748b;">
              <i class="fas fa-folder-open" style="font-size:42px; color:#cbd5e1; margin-bottom:12px;"></i>
              <p style="font-size:16px; font-weight:600; color:#334155; margin:0 0 6px;">No release records found for <?= pswdo_h($selectedMunicipality) ?></p>
              <p style="font-size:13px; margin:0;">Try selecting a different municipality or adjusting your status filters.</p>
            </div>
          <?php else: ?>
            <div class="table-wrap municipal-release-table-wrap">
              <table class="report-table municipal-release-table">
                <colgroup>
                  <col class="release-col-select" />
                  <col class="release-col-number" />
                  <col class="release-col-reference" />
                  <col class="release-col-beneficiary" />
                  <col class="release-col-applicant" />
                  <col class="release-col-barangay" />
                  <col class="release-col-assistance" />
                  <col class="release-col-amount" />
                  <col class="release-col-schedule" />
                  <col class="release-col-batch" />
                  <col class="release-col-status" />
                </colgroup>
                <thead>
                  <tr>
                    <th style="width: 38px; text-align: center;">
                      <input type="checkbox" id="master-checkbox" title="Select All on page" style="cursor:pointer;" />
                    </th>
                    <th style="width: 44px; text-align: center;">#</th>
                    <th>Reference No.</th>
                    <th>Beneficiary Name</th>
                    <th>Applicant / Proxy</th>
                    <th>Barangay</th>
                    <th>Assistance</th>
                    <th>Amount</th>
                    <th>Sched / Release</th>
                    <th>Batch &amp; Venue</th>
                    <th style="text-align:center;">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($records as $i => $row): ?>
                    <tr class="release-row" data-id="<?= (int)$row['id'] ?>" data-ref="<?= pswdo_h($row['reference_no']) ?>">
                      <td style="text-align: center;">
                        <input
                          type="checkbox"
                          class="record-checkbox"
                          value="<?= (int)$row['id'] ?>"
                          style="cursor:pointer;"
                        />
                      </td>
                      <td style="text-align: center; color: #64748b; font-weight: 600;"><?= $i + 1 ?></td>
                      <td>
                        <strong class="release-cell-line" style="color: #0369a1;" title="<?= pswdo_h($row['reference_no']) ?>"><?= pswdo_h($row['reference_no']) ?></strong>
                      </td>
                      <td>
                        <strong class="release-cell-line" title="<?= pswdo_h($row['beneficiary_name'] . (!empty($row['categories']) ? ', ' . $row['categories'] : '')) ?>">
                          <?= pswdo_h($row['beneficiary_name']) ?><?php if (!empty($row['categories'])): ?><span class="release-cell-meta">, <?= pswdo_h($row['categories']) ?></span><?php endif; ?>
                        </strong>
                      </td>
                      <td>
                        <span class="release-cell-line" title="<?= pswdo_h($row['applicant_name']) ?>"><?= pswdo_h($row['applicant_name']) ?></span>
                      </td>
                      <td>
                        <span class="release-cell-line" title="<?= pswdo_h($row['barangay'] . ', ' . $row['municipality']) ?>">
                          <?= pswdo_h($row['barangay']) ?><span class="release-cell-meta">, <?= pswdo_h($row['municipality']) ?></span>
                        </span>
                      </td>
                      <td><span class="release-cell-line" title="<?= pswdo_h($row['assistance']) ?>"><?= pswdo_h($row['assistance']) ?></span></td>
                      <td><strong class="release-cell-line" title="<?= pswdo_h($row['amount_formatted']) ?>"><?= pswdo_h($row['amount_formatted']) ?></strong></td>
                      <td>
                        <span class="release-cell-line" title="<?= pswdo_h($row['scheduled_date'] . ($row['scheduled_time'] !== '-' ? ', ' . $row['scheduled_time'] : '')) ?>">
                          <?= pswdo_h($row['scheduled_date']) ?><?php if ($row['scheduled_time'] !== '-'): ?><span class="release-cell-meta">, <?= pswdo_h($row['scheduled_time']) ?></span><?php endif; ?>
                        </span>
                      </td>
                      <td>
                        <span class="release-cell-line" title="<?= pswdo_h($row['batch'] . ', ' . $row['venue']) ?>">
                          <?= pswdo_h($row['batch']) ?><span class="release-cell-meta">, <?= pswdo_h($row['venue']) ?></span>
                        </span>
                      </td>
                      <td style="text-align:center;">
                        <span class="<?= pswdo_h($row['status_badge_class']) ?> municipal-status-badge" title="<?= pswdo_h($row['status_label']) ?>">
                          <?= pswdo_h($row['status_label']) ?>
                        </span>
                      </td>
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

  <!-- Sticky Bottom Selection Action Bar -->
  <div class="selection-action-bar" id="selection-bar">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="selection-count-badge" id="selected-count-badge">0</span>
      <span style="font-size:13px; font-weight:600;">Selected for <?= pswdo_h($selectedMunicipality) ?></span>
    </div>

    <button
      type="button"
      class="btn-solid btn-emerald"
      id="bar-email-btn"
      style="padding: 8px 18px; font-size: 13px; border: none; cursor:pointer;"
    >
      <i class="fas fa-paper-plane"></i> Send to Email (PDF)
    </button>

    <button
      type="button"
      class="bar-btn-pill"
      id="bar-preview-btn"
      title="Preview selected records in PDF"
    >
      <i class="fas fa-eye" style="color: #0284c7;"></i> Preview PDF
    </button>

    <button
      type="button"
      class="bar-btn-pill"
      id="bar-download-btn"
      title="Download selected records PDF"
    >
      <i class="fas fa-download" style="color: #059669;"></i> Download PDF
    </button>

    <button
      type="button"
      id="bar-clear-btn"
      style="background:none; border:none; color:#cbd5e1; font-size:13px; font-weight:600; cursor:pointer; text-decoration:underline;"
    >
      Clear Selection
    </button>
  </div>

  <!-- Interactive Email Modal Dialog -->
  <div class="email-modal-overlay" id="email-modal">
    <div class="email-modal-card" role="dialog" aria-modal="true" aria-labelledby="email-modal-title">
      <div class="email-modal-header">
        <div style="display:flex; align-items:center; gap:10px;">
          <i class="fas fa-envelope-open-text" style="font-size:22px;"></i>
          <div>
            <h3 id="email-modal-title" style="margin:0; font-size:17px; font-weight:700;">Email Release Masterlist to MSWDO</h3>
            <span style="font-size:12px; opacity:0.85;">Official provincial PDF transmittal</span>
          </div>
        </div>
        <button type="button" id="close-email-modal-btn" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;" aria-label="Close modal">
          <i class="fas fa-xmark"></i>
        </button>
      </div>

      <form id="email-transmittal-form" class="email-modal-body">
        <div style="margin-bottom:16px;">
          <label style="display:block; font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:6px;">
            Target Municipality
          </label>
          <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:15px; font-weight:700; color:#0f172a;"><?= pswdo_h($selectedMunicipality) ?></span>
            <span style="font-size:12px; color:#64748b;">(Province of Antique)</span>
          </div>
        </div>

        <div style="margin-bottom:16px;">
          <label for="modal-recipient-email" style="display:block; font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:6px;">
            Recipient MSWDO Email Address <span style="color:#ef4444;">*</span>
          </label>
          <input
            type="email"
            id="modal-recipient-email"
            required
            value="<?= pswdo_h($defaultMswdoEmail) ?>"
            style="width:100%; padding:10px 14px; border-radius:8px; border:1px solid #cbd5e1; font-size:14px;"
            placeholder="e.g. mswdo.hamtic@antique.gov.ph"
          />
          <span style="font-size:11px; color:#64748b; margin-top:4px; display:block;">
            Prefilled with official municipal social welfare address. You may update or add email.
          </span>
        </div>

        <div style="margin-bottom:16px;">
          <label for="modal-subject" style="display:block; font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:6px;">
            Subject Line
          </label>
          <input
            type="text"
            id="modal-subject"
            value="AICS Beneficiary Release Registry - Municipality of <?= pswdo_h($selectedMunicipality) ?>"
            style="width:100%; padding:10px 14px; border-radius:8px; border:1px solid #cbd5e1; font-size:14px;"
          />
        </div>

        <!-- Attached PDF Card -->
        <div class="pdf-attachment-card">
          <div style="display:flex; align-items:center; gap:12px;">
            <i class="fas fa-file-pdf" style="font-size:32px; color:#ef4444;"></i>
            <div>
              <div style="font-size:13px; font-weight:700; color:#0f172a;" id="modal-pdf-filename">
                AICS-Release-Masterlist-<?= pswdo_h($selectedMunicipality) ?>.pdf
              </div>
              <div style="font-size:11px; color:#64748b;">
                Official Document &bull; <span id="modal-attached-count">All <?= count($records) ?> records</span> included
              </div>
            </div>
          </div>
          <button
            type="button"
            id="modal-download-preview-btn"
            class="btn-outline"
            style="font-size:12px; padding:6px 12px;"
          >
            <i class="fas fa-download"></i> Download PDF
          </button>
        </div>

        <div style="margin-bottom:18px;">
          <label for="modal-notes" style="display:block; font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:6px;">
            Special Remarks / Municipal Coordination Instructions
          </label>
          <textarea
            id="modal-notes"
            rows="2"
            style="width:100%; padding:10px 14px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; font-family:inherit;"
            placeholder="Add any specific instructions regarding distribution schedule, payout venue, or barangay coordination..."
          ></textarea>
        </div>

        <!-- Required Municipal Actions Transmitted with Email -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px;">
          <strong style="color: #1e293b; font-size: 13px; display: block; margin-bottom: 8px; font-weight: 700;">
            <i class="fas fa-tasks" style="color: #0284c7; margin-right: 6px;"></i> Required Municipal Actions:
          </strong>
          <ul style="margin: 0; padding-left: 18px; font-size: 12.5px; line-height: 1.6; color: #475569;">
            <li style="margin-bottom: 4px;">Disseminate the beneficiary release registry and assigned release schedule to concerned beneficiaries.</li>
            <li style="margin-bottom: 4px;">Inform beneficiaries that assistance will be released at the <strong>PSWDO</strong> on the scheduled date.</li>
            <li style="margin-bottom: 4px;">Remind beneficiaries to bring a valid government-issued ID when claiming assistance.</li>
            <li style="margin-bottom: 4px;">Direct questions or concerns regarding assistance release to the PSWDO.</li>
            <li style="margin-bottom: 0;">Maintain a copy of the transmitted beneficiary registry for municipal reference and records.</li>
          </ul>
        </div>

        <!-- Feedback Alert inside Modal -->
        <div id="modal-alert-box" style="display:none; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:13px;"></div>

        <div style="display:flex; justify-content:flex-end; gap:10px; align-items:center;">
          <button type="button" class="btn-outline" id="cancel-email-modal-btn">Cancel</button>
          <button type="submit" class="btn-solid btn-emerald" id="submit-email-btn">
            <i class="fas fa-paper-plane"></i> Send Email Now
          </button>
        </div>
      </form>
    </div>
  </div>

  <script src="<?= pswdo_h($paths['js_url']) ?>"></script>
  <script>
    (function () {
      const municipality = <?= json_encode($selectedMunicipality) ?>;
      const statusFilter = <?= json_encode($statusFilter) ?>;
      const searchQuery = <?= json_encode($searchQuery) ?>;
      const masterCheckbox = document.getElementById('master-checkbox');
      const rowCheckboxes = Array.from(document.querySelectorAll('.record-checkbox'));
      const selectionBar = document.getElementById('selection-bar');
      const selectedCountBadge = document.getElementById('selected-count-badge');
      const btnSelectAllTop = document.getElementById('btn-select-all-top');
      const barClearBtn = document.getElementById('bar-clear-btn');
      const barEmailBtn = document.getElementById('bar-email-btn');
      const barPreviewBtn = document.getElementById('bar-preview-btn');
      const barDownloadBtn = document.getElementById('bar-download-btn');
      const headerEmailBtn = document.getElementById('header-email-btn');

      const emailModal = document.getElementById('email-modal');
      const closeEmailModalBtn = document.getElementById('close-email-modal-btn');
      const cancelEmailModalBtn = document.getElementById('cancel-email-modal-btn');
      const emailForm = document.getElementById('email-transmittal-form');
      const recipientInput = document.getElementById('modal-recipient-email');
      const subjectInput = document.getElementById('modal-subject');
      const notesInput = document.getElementById('modal-notes');
      const submitEmailBtn = document.getElementById('submit-email-btn');
      const modalAlertBox = document.getElementById('modal-alert-box');
      const modalAttachedCount = document.getElementById('modal-attached-count');
      const modalDownloadPreviewBtn = document.getElementById('modal-download-preview-btn');

      function getSelectedIds() {
        return rowCheckboxes
          .filter(cb => cb.checked)
          .map(cb => parseInt(cb.value, 10))
          .filter(id => id > 0);
      }

      function updateSelectionState() {
        const selected = getSelectedIds();
        const count = selected.length;
        if (selectedCountBadge) {
          selectedCountBadge.textContent = count;
        }
        if (count > 0) {
          selectionBar.classList.add('visible');
        } else {
          selectionBar.classList.remove('visible');
        }

        if (masterCheckbox && rowCheckboxes.length > 0) {
          masterCheckbox.checked = (count === rowCheckboxes.length);
          masterCheckbox.indeterminate = (count > 0 && count < rowCheckboxes.length);
        }
      }

      if (masterCheckbox) {
        masterCheckbox.addEventListener('change', function () {
          const checked = this.checked;
          rowCheckboxes.forEach(cb => { cb.checked = checked; });
          updateSelectionState();
        });
      }

      rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectionState);
      });

      if (btnSelectAllTop) {
        btnSelectAllTop.addEventListener('click', function () {
          const allChecked = rowCheckboxes.every(cb => cb.checked);
          rowCheckboxes.forEach(cb => { cb.checked = !allChecked; });
          updateSelectionState();
        });
      }

      if (barClearBtn) {
        barClearBtn.addEventListener('click', function () {
          rowCheckboxes.forEach(cb => { cb.checked = false; });
          updateSelectionState();
        });
      }

      function buildPdfUrl(ids, download = 1) {
        const params = new URLSearchParams();
        params.set('municipality', municipality);
        params.set('status', statusFilter);
        params.set('download', download ? '1' : '0');
        if (searchQuery) params.set('search', searchQuery);
        if (ids && ids.length > 0) {
          params.set('ids', ids.join(','));
        }
        return 'release-pdf-export.php?' + params.toString();
      }

      if (barPreviewBtn) {
        barPreviewBtn.addEventListener('click', function () {
          const ids = getSelectedIds();
          window.open(buildPdfUrl(ids, 0), '_blank');
        });
      }

      if (barDownloadBtn) {
        barDownloadBtn.addEventListener('click', function () {
          const ids = getSelectedIds();
          window.location.href = buildPdfUrl(ids, 1);
        });
      }

      function openEmailModal() {
        const ids = getSelectedIds();
        const count = ids.length > 0 ? ids.length : rowCheckboxes.length;
        modalAttachedCount.textContent = (ids.length > 0 ? (count + ' selected') : ('All ' + count)) + ' records';
        modalAlertBox.style.display = 'none';
        submitEmailBtn.disabled = false;
        submitEmailBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Email Now';
        emailModal.classList.add('active');
      }

      function closeEmailModal() {
        emailModal.classList.remove('active');
      }

      if (headerEmailBtn) headerEmailBtn.addEventListener('click', openEmailModal);
      if (barEmailBtn) barEmailBtn.addEventListener('click', openEmailModal);
      if (closeEmailModalBtn) closeEmailModalBtn.addEventListener('click', closeEmailModal);
      if (cancelEmailModalBtn) cancelEmailModalBtn.addEventListener('click', closeEmailModal);

      if (modalDownloadPreviewBtn) {
        modalDownloadPreviewBtn.addEventListener('click', function () {
          const ids = getSelectedIds();
          window.open(buildPdfUrl(ids, 0), '_blank');
        });
      }

      emailModal.addEventListener('click', function (e) {
        if (e.target === emailModal) {
          closeEmailModal();
        }
      });

      // Submit Email via AJAX
      emailForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const recipient = recipientInput.value.trim();
        const subject = subjectInput.value.trim();
        const notes = notesInput.value.trim();
        const selectedIds = getSelectedIds();

        submitEmailBtn.disabled = true;
        submitEmailBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending PDF...';
        modalAlertBox.style.display = 'none';

        try {
          const res = await fetch('municipal-release-email-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              municipality: municipality,
              recipient_email: recipient,
              subject: subject,
              notes: notes,
              status: statusFilter,
              request_ids: selectedIds
            })
          });

          const data = await res.json();
          if (data.success) {
            modalAlertBox.style.display = 'block';
            modalAlertBox.style.background = '#dcfce7';
            modalAlertBox.style.color = '#15803d';
            modalAlertBox.style.border = '1px solid #bbf7d0';
            modalAlertBox.innerHTML = '<i class="fas fa-circle-check"></i> ' + (data.message || 'Email sent successfully with PDF attached.');
            submitEmailBtn.innerHTML = '<i class="fas fa-check"></i> Sent';

            setTimeout(() => {
              closeEmailModal();
            }, 2200);
          } else {
            modalAlertBox.style.display = 'block';
            modalAlertBox.style.background = '#fee2e2';
            modalAlertBox.style.color = '#b91c1c';
            modalAlertBox.style.border = '1px solid #fecaca';
            modalAlertBox.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + (data.error || 'Failed to dispatch email.');
            submitEmailBtn.disabled = false;
            submitEmailBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Try Again';
          }
        } catch (err) {
          modalAlertBox.style.display = 'block';
          modalAlertBox.style.background = '#fee2e2';
          modalAlertBox.style.color = '#b91c1c';
          modalAlertBox.style.border = '1px solid #fecaca';
          modalAlertBox.innerHTML = '<i class="fas fa-circle-exclamation"></i> Network error: ' + err.message;
          submitEmailBtn.disabled = false;
          submitEmailBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Try Again';
        }
      });

    })();
  </script>
</body>
</html>
