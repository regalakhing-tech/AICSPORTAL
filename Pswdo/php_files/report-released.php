<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';

pswdo_start_session();
$paths = pswdo_paths();

$accountId  = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = pswdo_current_display_name();
$initials    = pswdo_initials($displayName);

$dateFrom = trim((string) ($_GET['date_from'] ?? date('Y-m-01')));
$dateTo   = trim((string) ($_GET['date_to']   ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $dateTo   = date('Y-m-d'); }
if (strcmp($dateFrom, $dateTo) > 0) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$reportItems = [];
$loadError   = '';

try {
    $connection  = aics_db_connect();
    pswdo_ensure_schema($connection);
    $profile     = pswdo_fetch_staff_profile($connection, $accountId, $displayName);
    $displayName = (string) ($profile['display_name'] ?? $displayName);
    $initials    = (string) ($profile['initials']     ?? $initials);
    $reportItems = pswdo_fetch_released_report($connection, $dateFrom, $dateTo);
    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load report data right now.';
}

/* ── CSV export ─────────────────────────────────────────── */
if (trim((string) ($_GET['format'] ?? '')) === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="released-beneficiaries-' . $dateFrom . '-to-' . $dateTo . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No.', 'Reference No.', 'Beneficiary / Patient Name', 'Applicant Name', 'Address', 'Beneficiary Classification', 'Type of Assistance', 'Date Applied', 'Release Date', 'Release Time', 'Venue', 'Batch', 'Date Recorded Released']);
    foreach ($reportItems as $i => $row) {
        fputcsv($out, [
            $i + 1,
            $row['reference_no'],
            $row['beneficiary_name'],
            $row['applicant_name'],
            $row['address'],
            $row['categories'],
            $row['assistance'],
            $row['date_applied'],
            $row['release_date'],
            $row['release_time'],
            $row['venue'],
            $row['batch'],
            $row['released_at'],
        ]);
    }
    fclose($out);
    exit;
}

$dateFromLabel = pswdo_date_label($dateFrom);
$dateToLabel   = pswdo_date_label($dateTo);
$generatedAt   = date('F j, Y \a\t h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PSWDO | Released Beneficiaries List</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= pswdo_h($paths['css_url']) ?>">
  <style>
    /* ── Print layout ─────────────────────────────────────── */
    .print-header, .report-print-error { display: none; }

    @media print {
      @page { size: A4 landscape; margin: 12mm; }
      .sidebar, .sidebar-overlay, .top-nav, .report-filter-card,
      .report-nav-card, .report-actions-card, .toast-alert,
      .alert-error, .no-print { display: none !important; }
      html, body, .app-shell, .main-content {
        background: #fff !important;
        color: #000 !important;
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 8pt;
      }
      .main-content { margin-left: 0 !important; width: 100% !important; max-width: none !important; overflow: visible !important; }
      .content-wrap { padding: 0 !important; }
      .panel-card { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 0 16px !important; }
      .print-header { display: block !important; text-align: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1.5px solid #000; }
      .report-print-error { display: block !important; margin: 0; padding: 10px 12px; border: 1px solid #000; line-height: 1.45; }
      .print-header .print-agency { font-size: 13pt; font-weight: 700; margin: 0; text-transform: uppercase; }
      .print-header .print-province { font-size: 10pt; font-weight: 700; margin: 2px 0; }
      .print-header .print-program { font-size: 9pt; margin: 0 0 8px; }
      .print-header .print-title { font-size: 11pt; font-weight: 700; margin: 8px 0; text-transform: uppercase; }
      .print-header .print-meta-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 0; text-align: left; font-size: 7.5pt; }
      .print-header .print-meta-row dt { font-weight: 700; margin: 0 0 1px; }
      .print-header .print-meta-row dd { margin: 0; }
      .table-wrap { overflow: visible !important; }
      .report-table { width: 100% !important; min-width: 0 !important; border-collapse: collapse !important; }
      .report-table th, .report-table td { color: #000 !important; font-size: 7pt !important; line-height: 1.2; padding: 3px 4px !important; border: 1px solid #000 !important; vertical-align: top; }
      .report-table thead { display: table-header-group; background: #fff !important; }
      .report-table tr { break-inside: avoid; page-break-inside: avoid; }
      .report-signature { display: flex !important; }
      .report-signature, .sig-block { break-inside: avoid; page-break-inside: avoid; }
      .page-break { page-break-before: always; }
    }

    .report-list-title { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
    .report-list-subtitle { font-size: 13px; color: #64748b; margin: 0; }
    .report-signature {
      display: none;
      justify-content: space-between;
      margin-top: 32px;
      gap: 24px;
    }
    .sig-block { flex: 1; text-align: center; }
    .sig-block .sig-line { border-top: 1px solid #0f172a; margin: 48px 16px 4px; }
    .sig-block p { font-size: 9pt; margin: 0; }
    .sig-block strong { font-size: 10pt; display: block; }
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
        <?= pswdo_mswdo_nav_link($paths['dashboard_url']) ?>
        <a class="nav-link active" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=reports"><i class="fas fa-chart-pie"></i> Reports &amp; Lists</a>
      </nav>
      <div class="sidebar-footer">
        <a href="<?= pswdo_h($paths['logout_url']) ?>" class="logout-link"><i class="fas fa-right-from-bracket"></i> Log Out</a>
      </div>
    </aside>

    <div class="sidebar-overlay" id="overlay"></div>

    <main class="main-content" id="main-content">
      <header class="top-nav">
        <div class="top-nav-right">
          <div class="profile-chip">
            <div class="avatar"><?= pswdo_h($initials) ?></div>
            <span><?= pswdo_h($displayName) ?></span>
          </div>
        </div>
      </header>

      <section class="content-wrap">

        <!-- Navigation breadcrumb -->
        <section class="panel-card report-nav-card no-print" style="padding:14px 20px;">
          <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=reports" class="btn-outline" style="font-size:13px;">
            <i class="fas fa-arrow-left"></i> Back to Reports &amp; Lists
          </a>
        </section>

        <?php if ($loadError !== ''): ?>
          <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?= pswdo_h($loadError) ?></div>
        <?php endif; ?>

        <!-- Filter -->
        <section class="panel-card report-filter-card no-print">
          <form method="get" action="<?= pswdo_h($paths['dashboard_url']) ?>" class="filter-form">
            <input type="hidden" name="page" value="report_released" />
            <div class="field-wrap">
              <label for="date_from">Date From</label>
              <input id="date_from" type="date" name="date_from" value="<?= pswdo_h($dateFrom) ?>">
            </div>
            <div class="field-wrap">
              <label for="date_to">Date To</label>
              <input id="date_to" type="date" name="date_to" value="<?= pswdo_h($dateTo) ?>">
            </div>
            <button type="submit" class="btn-solid"><i class="fas fa-arrows-rotate"></i> Refresh</button>
          </form>
        </section>

        <!-- Actions -->
        <section class="panel-card report-actions-card no-print" style="padding:14px 20px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <button type="button" class="btn-solid" onclick="window.print()">
            <i class="fas fa-print"></i> Print List
          </button>
          <a
            href="<?= pswdo_h($paths['dashboard_url']) ?>?page=report_released&date_from=<?= rawurlencode($dateFrom) ?>&date_to=<?= rawurlencode($dateTo) ?>&format=csv"
            class="btn-outline"
          >
            <i class="fas fa-file-csv"></i> Export CSV
          </a>
          <span style="font-size:13px; color:#64748b; margin-left:4px;"><?= count($reportItems) ?> record<?= count($reportItems) === 1 ? '' : 's' ?> found</span>
        </section>

        <!-- Report Table -->
        <section class="panel-card report-log-panel">

          <!-- Formal print header (hidden on screen, visible on print) -->
          <?php
            $printReportTitle = 'Released Assistance Report';
            $printPeriodLabel = $dateFromLabel . ' to ' . $dateToLabel;
            $printGeneratedAt = $generatedAt;
            $printPreparedBy = $displayName;
            require __DIR__ . '/_report-print-header.php';
          ?>

          <header class="panel-head no-print">
            <div>
              <p class="report-list-title"><i class="fas fa-hand-holding-heart"></i> List of AICS Beneficiaries Who Received Assistance</p>
              <p class="report-list-subtitle">Release period: <?= pswdo_h($dateFromLabel) ?> &ndash; <?= pswdo_h($dateToLabel) ?></p>
            </div>
          </header>

          <?php require __DIR__ . '/_report-released-print-table.php'; ?>
        </section>

      </section>
    </main>
  </div>
  <script src="<?= pswdo_h($paths['js_url']) ?>"></script>
</body>
</html>
