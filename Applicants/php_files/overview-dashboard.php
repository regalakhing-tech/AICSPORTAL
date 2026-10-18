<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/dashboard-data-lib.php';
require_once __DIR__ . '/application-rules.php';

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

$logoUrl = $projectRootUrl . '/asset/logo.png';
$cssUrl = $applicantsBaseUrl . '/css files/applicant-pages.css?v=' . urlencode($assetCssVersion);
$appJsUrl = $applicantsBaseUrl . '/js files/applicant-pages.js?v=' . urlencode($assetJsVersion);
$logoutUrl = $phpFilesBaseUrl . '/auth-logout.php';
$dashboardDataUrl = $phpFilesBaseUrl . '/dashboard-data.php';

$dashboardData = [
    'generated_at' => (new DateTimeImmutable('now', aics_dashboard_timezone()))->format(DATE_ATOM),
    'profile' => [
        'display_name' => (string) ($_SESSION['aics_display_name'] ?? 'Applicant'),
        'initials' => 'AP',
        'email' => '',
        'contact_number' => '',
        'complete_address' => '',
        'is_profile_complete' => false,
    ],
    'summary' => [
        'active_requests' => 0,
        'total_requests' => 0,
        'resolved_requests' => 0,
        'documents_uploaded' => 0,
        'required_documents' => 0,
        'documents_progress_label' => '0 / 0',
        'recent_updates' => 0,
        'action_items' => 0,
    ],
    'latest_request' => null,
    'recent_activity' => [],
    'next_steps' => [],
];
$dashboardLoadError = '';

$historyRequests = [];
$cooldownInfo = [
    'last_date'      => '',
    'eligible_on'    => '',
    'days_remaining' => 0,
    'is_in_cooldown' => false,
    'cooldown_years' => (int) AICS_APPLICATION_COOLDOWN_YEARS,
];
try {
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    if ($accountId > 0) {
        $dashboardData = aics_fetch_applicant_dashboard_data(
            $accountId,
            (string) ($_SESSION['aics_display_name'] ?? 'Applicant')
        );
        // Load history once for display. Eligibility itself remains calculated
        // by the shared cooldown helper below, never by history-card data.
        $conn = aics_db_connect();
        $historySql = <<<SQL
            SELECT r.assistance_types_json, r.request_status,
                   DATE(COALESCE(r.date_applied, r.created_at)) AS applied_on,
                   r.reference_no
            FROM aics_requests r
            INNER JOIN aics_account_requests ar ON ar.request_id = r.id
            WHERE ar.account_id = ?
              AND DATE(COALESCE(r.date_applied, r.created_at)) >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
            ORDER BY COALESCE(r.date_applied, r.created_at) DESC, r.id DESC
        SQL;
        $historyStmt = $conn->prepare($historySql);
        $historyStmt->bind_param('i', $accountId);
        $historyStmt->execute();
        $historyResult = $historyStmt->get_result();
        while ($historyRow = $historyResult?->fetch_assoc()) {
            if (!is_array($historyRow)) break;
            $decoded = json_decode((string) ($historyRow['assistance_types_json'] ?? '[]'), true);
            if (!is_array($decoded) || count($decoded) === 0) continue;
            $code = strtolower(trim((string) $decoded[0]));
            if ($code === '') continue;
            $historyRequests[] = [
                'type' => $code,
                'status' => strtolower(trim((string) ($historyRow['request_status'] ?? 'submitted'))),
                'applied_on' => (string) ($historyRow['applied_on'] ?? ''),
                'reference_no' => (string) ($historyRow['reference_no'] ?? ''),
            ];
        }
        $historyStmt->close();

        $cooldownInfo = aics_application_cooldown_state($conn, $accountId);

        $conn->close();
    }
} catch (Throwable $exception) {
    $dashboardLoadError = 'Live dashboard data could not be loaded right now.';
}

$profile = is_array($dashboardData['profile'] ?? null) ? $dashboardData['profile'] : [];
$summary = is_array($dashboardData['summary'] ?? null) ? $dashboardData['summary'] : [];
$latestRequest = is_array($dashboardData['latest_request'] ?? null) ? $dashboardData['latest_request'] : null;
$recentActivity = is_array($dashboardData['recent_activity'] ?? null) ? $dashboardData['recent_activity'] : [];
$nextSteps = is_array($dashboardData['next_steps'] ?? null) ? $dashboardData['next_steps'] : [];

$displayName = trim((string) ($profile['display_name'] ?? 'Applicant'));
if ($displayName === '') {
    $displayName = 'Applicant';
}
$initials = trim((string) ($profile['initials'] ?? 'AP'));
if ($initials === '') {
    $initials = 'AP';
}
$isProfileComplete = (bool) ($profile['is_profile_complete'] ?? false);
$requiredDocuments = (int) ($summary['required_documents'] ?? 0);
$uploadedDocuments = (int) ($summary['documents_uploaded'] ?? 0);
$docPercent = ($requiredDocuments > 0)
    ? min(100, (int) round(($uploadedDocuments / $requiredDocuments) * 100))
    : 0;
$documentKpiTone = $requiredDocuments <= 0
    ? 'neutral'
    : ($uploadedDocuments >= $requiredDocuments ? 'success' : 'warning');
$actionKpiTone = (int) ($summary['action_items'] ?? 0) > 0 ? 'warning' : 'neutral';

$latestStatusCode = strtolower((string) ($latestRequest['status_code'] ?? ''));
$latestStepIndex = 1;
if (in_array($latestStatusCode, ['under_review', 'under_assessment', 'for_interview', 'for interview'], true)) {
    $latestStepIndex = 2;
} elseif (in_array($latestStatusCode, ['approved', 'validated', 'for_release', 'for release', 'scheduled'], true)) {
    $latestStepIndex = 3;
} elseif (in_array($latestStatusCode, ['released', 'distributed', 'completed', 'closed'], true)) {
    $latestStepIndex = 4;
}
$latestStepperPercent = match ($latestStepIndex) {
    1 => '0%',
    2 => '33%',
    3 => '66%',
    4 => '100%',
};

function overview_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AICS Applicant | Overview Dashboard</title>
<link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
<link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= overview_h($cssUrl) ?>">
</head>
<body data-account-status="<?= overview_h($currentAccountStatusCode) ?>" data-banned-modal-required="<?= $forceBannedModal ? '1' : '0' ?>">
<div class="app-container">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a href="<?= overview_h($dashboardUrl) ?>?page=overview" class="logo" style="padding-top: 4px;">
        <img src="<?= overview_h($logoUrl) ?>" alt="AICS | PSWDO Logo" style="height: 42px; width: auto; object-fit: contain;">
      </a>
      <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
    </div>

    <div class="nav-menu">
      <div class="nav-header">Main Menu</div>
      <a href="<?= overview_h($dashboardUrl) ?>?page=overview" class="nav-item active"><i class="fas fa-border-all"></i> Overview Dashboard</a>
      <a href="<?= overview_h($dashboardUrl) ?>?page=profile" class="nav-item"><i class="fas fa-user-circle"></i> My Profile</a>

      <div class="nav-header nav-header-gap">Application</div>
      <a href="<?= overview_h($dashboardUrl) ?>?page=submit" class="nav-item" data-submit-request-nav data-submit-request-href="<?= overview_h($dashboardUrl) ?>?page=submit"><i class="fas fa-file-signature"></i> Submit Application</a>
      <a href="<?= overview_h($dashboardUrl) ?>?page=documents" class="nav-item"><i class="fas fa-file-invoice"></i> Documents</a>
      <a href="<?= overview_h($dashboardUrl) ?>?page=status" class="nav-item"><i class="fas fa-route"></i> Application Status</a>
      <a href="<?= overview_h($dashboardUrl) ?>?page=cancel-request" class="nav-item"><i class="fas fa-pen-to-square"></i> Manage Applications</a>

      <div class="nav-header nav-header-gap">Support</div>
      <a href="<?= overview_h($dashboardUrl) ?>?page=notifications" class="nav-item"><i class="fas fa-bell"></i> Notifications</a>
    </div>

    <div class="sidebar-footer">
      <a href="<?= overview_h($logoutUrl) ?>" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </aside>

  <div class="sidebar-overlay" id="overlay"></div>

  <main class="main-content" id="main-content">
    <header class="top-nav">
      <div class="nav-left">
        <div class="top-nav-page-info">
          <span class="top-nav-badge"><i class="fas fa-border-all"></i></span>
          <div class="top-nav-text">
            <span class="top-nav-sub">Main Menu</span>
            <h1 class="top-nav-heading">Overview Dashboard</h1>
          </div>
        </div>
      </div>

      <div class="nav-right">
        <a class="btn-icon" data-notification-trigger href="<?= overview_h($dashboardUrl) ?>?page=notifications" aria-label="Notifications" aria-expanded="false">
          <i class="fas fa-bell"></i>
          <span class="dot" hidden aria-hidden="true"></span>
        </a>
        <div class="profile-chip">
          <div class="profile-av"><?= overview_h($initials) ?></div>
          <span><?= overview_h($displayName) ?></span>
        </div>
      </div>
    </header>

    <div
      class="content-area content-area-senior"
      id="overview-dashboard-root"
      data-dashboard-root="true"
      data-endpoint="<?= overview_h($dashboardDataUrl) ?>"
      data-dashboard-url="<?= overview_h($dashboardUrl) ?>"
    >
      <section class="overview-hero-executive">
        <div class="overview-hero-executive-main">
          <div class="overview-hero-badge-row">
            <span class="overview-hero-pill">
              <span class="status-pulse-dot"></span>
              Live Portal Active
            </span>
            <?php if ($latestRequest !== null && !empty($latestRequest['reference_no'])): ?>
              <span class="overview-hero-ref-pill">
                <i class="fas fa-hashtag"></i> <?= overview_h((string) $latestRequest['reference_no']) ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="overview-hero-copy">
            <h1 class="page-title" data-dashboard-field="hero-title">
              <?= $latestRequest !== null ? overview_h('Welcome back, ' . $displayName . '!') : overview_h('Welcome, ' . $displayName . '!') ?>
            </h1>
            <p class="page-sub" data-dashboard-field="hero-subtitle">
              <?php if ($latestRequest !== null): ?>
                <?= overview_h('Track your current AICS request, document readiness, and the latest activity on your account in real-time.') ?>
              <?php else: ?>
                <?= overview_h('Let us help you get started. Complete your profile, then submit a request to begin receiving live updates here.') ?>
              <?php endif; ?>
            </p>
          </div>
        </div>

        <div class="overview-hero-executive-actions">
          <?php if ($latestRequest !== null || $isProfileComplete): ?>
            <a class="btn-hero-primary" href="<?= overview_h($dashboardUrl) ?>?page=<?= $latestRequest !== null ? 'status' : 'submit' ?>" data-dashboard-action="primary">
              <i class="fas fa-<?= $latestRequest !== null ? 'route' : 'file-signature' ?>"></i>
              <span><?= $latestRequest !== null ? 'Track Request' : 'Submit Application' ?></span>
            </a>
          <?php endif; ?>
          <a class="btn-hero-secondary" href="<?= overview_h($dashboardUrl) ?>?page=<?= $latestRequest !== null ? 'documents' : 'profile' ?>" data-dashboard-action="secondary">
            <i class="fas fa-<?= $latestRequest !== null ? 'folder-open' : 'user-check' ?>"></i>
            <span><?= $latestRequest !== null ? 'Manage Documents' : 'Complete Profile' ?></span>
          </a>
        </div>
      </section>

      <?php if ($dashboardLoadError !== ''): ?>
        <div class="section-card profile-feedback error senior-alert" style="margin-bottom: 18px;">
          <i class="fas fa-triangle-exclamation"></i>
          <span><?= overview_h($dashboardLoadError) ?></span>
        </div>
      <?php endif; ?>

      <h2 class="section-heading-executive">
        <span class="heading-icon-badge"><i class="fas fa-chart-simple"></i></span>
        <span>Quick Look at Your Account</span>
      </h2>

      <section class="overview-stats-executive">
        <article class="applicant-kpi-card tone-theme-sky tone-card-info" data-dashboard-kpi="active">
          <div class="applicant-kpi-card-head">
            <span class="applicant-kpi-label">Active Requests</span>
            <div class="applicant-kpi-icon-wrap"><i class="fas fa-clipboard-list"></i></div>
          </div>
          <div class="applicant-kpi-value" data-dashboard-field="metric-active"><?= overview_h((string) ($summary['active_requests'] ?? 0)) ?></div>
          <div class="applicant-kpi-note">Still being reviewed or released.</div>
        </article>

        <article class="applicant-kpi-card tone-theme-indigo tone-card-info" data-dashboard-kpi="total">
          <div class="applicant-kpi-card-head">
            <span class="applicant-kpi-label">Total Requests</span>
            <div class="applicant-kpi-icon-wrap"><i class="fas fa-layer-group"></i></div>
          </div>
          <div class="applicant-kpi-value" data-dashboard-field="metric-total"><?= overview_h((string) ($summary['total_requests'] ?? 0)) ?></div>
          <div class="applicant-kpi-note">All requests in your account history.</div>
        </article>

        <article class="applicant-kpi-card tone-theme-emerald tone-card-<?= overview_h($documentKpiTone) ?>" data-dashboard-kpi="documents">
          <div class="applicant-kpi-card-head">
            <span class="applicant-kpi-label">Documents Ready</span>
            <div class="applicant-kpi-icon-wrap"><i class="fas fa-file-circle-check"></i></div>
          </div>
          <div class="applicant-kpi-value" data-dashboard-field="metric-documents"><?= overview_h((string) ($summary['documents_progress_label'] ?? '0 / 0')) ?></div>
          <div class="applicant-kpi-note">Uploaded out of those required.</div>
          <div class="kpi-mini-progress-shell" aria-hidden="true">
            <span class="kpi-mini-progress-bar" style="width: <?= $docPercent ?>%;"></span>
          </div>
        </article>

        <article class="applicant-kpi-card tone-theme-amber tone-card-<?= overview_h($actionKpiTone) ?>" data-dashboard-kpi="actions">
          <div class="applicant-kpi-card-head">
            <span class="applicant-kpi-label">Things To Do</span>
            <div class="applicant-kpi-icon-wrap"><i class="fas fa-bell"></i></div>
          </div>
          <div class="applicant-kpi-value" data-dashboard-field="metric-actions"><?= overview_h((string) ($summary['action_items'] ?? 0)) ?></div>
          <div class="applicant-kpi-note">Tasks waiting for your attention.</div>
        </article>
      </section>

      <section style="margin-bottom: 28px;">
        <article class="surface-panel-executive">
          <div class="panel-head-executive">
            <div class="panel-head-executive-info">
              <div class="panel-icon-executive"><i class="fas fa-file-lines"></i></div>
              <div>
                <h2 class="panel-title">Your Latest Application</h2>
                <p class="panel-sub" data-dashboard-field="snapshot-subtitle">
                  <?= $latestRequest !== null ? overview_h((string) $latestRequest['status_description']) : overview_h('You have not submitted a request yet. You can start one anytime.') ?>
                </p>
              </div>
            </div>
            <?php if ($latestRequest !== null): ?>
              <?php
                $statusTone = (string) ($latestRequest['status_tone'] ?? 'info');
                $statusIcon = (string) ($latestRequest['status_icon'] ?? 'circle-info');
                $statusLabel = (string) ($latestRequest['status_label'] ?? 'Pending');
              ?>
              <div class="status-pill-executive status-<?= overview_h($latestStatusCode) ?> status-<?= overview_h($statusTone) ?>" data-dashboard-field="status-pill">
                <i class="fas fa-<?= overview_h($statusIcon) ?>"></i>
                <span data-dashboard-field="status-label"><?= overview_h($statusLabel) ?></span>
              </div>
            <?php endif; ?>
          </div>

          <div data-dashboard-field="snapshot-body">
            <?php if ($latestRequest !== null): ?>
              <div class="request-stepper-wrap">
                <div class="stepper-track-bar">
                  <span class="stepper-progress-fill" style="width: <?= $latestStepperPercent ?>;"></span>
                </div>
                <div class="stepper-steps-container">
                  <div class="stepper-step <?= $latestStepIndex > 1 ? 'is-completed' : ($latestStepIndex === 1 ? 'is-active' : '') ?>">
                    <div class="stepper-node-circle">
                      <i class="fas <?= $latestStepIndex > 1 ? 'fa-check' : 'fa-paper-plane' ?>"></i>
                    </div>
                    <div>
                      <div class="stepper-step-title">Submitted</div>
                      <div class="stepper-step-caption">Application Received</div>
                    </div>
                  </div>
                  <div class="stepper-step <?= $latestStepIndex > 2 ? 'is-completed' : ($latestStepIndex === 2 ? 'is-active' : '') ?>">
                    <div class="stepper-node-circle">
                      <i class="fas <?= $latestStepIndex > 2 ? 'fa-check' : 'fa-magnifying-glass' ?>"></i>
                    </div>
                    <div>
                      <div class="stepper-step-title">Under Review</div>
                      <div class="stepper-step-caption">Officer Assessment</div>
                    </div>
                  </div>
                  <div class="stepper-step <?= $latestStepIndex > 3 ? 'is-completed' : ($latestStepIndex === 3 ? 'is-active' : '') ?>">
                    <div class="stepper-node-circle">
                      <i class="fas <?= $latestStepIndex > 3 ? 'fa-check' : 'fa-certificate' ?>"></i>
                    </div>
                    <div>
                      <div class="stepper-step-title">Approved</div>
                      <div class="stepper-step-caption">Assistance Granted</div>
                    </div>
                  </div>
                  <div class="stepper-step <?= $latestStepIndex === 4 ? 'is-completed is-active' : '' ?>">
                    <div class="stepper-node-circle">
                      <i class="fas <?= $latestStepIndex === 4 ? 'fa-check-double' : 'fa-hand-holding-heart' ?>"></i>
                    </div>
                    <div>
                      <div class="stepper-step-title">Released</div>
                      <div class="stepper-step-caption">Distribution / Paid</div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="snapshot-grid-executive">
                <div class="snapshot-box-executive">
                  <div class="snapshot-box-header"><i class="fas fa-hashtag"></i> Reference Number</div>
                  <div class="snapshot-box-value">
                    <span class="snapshot-ref-pill"><?= overview_h((string) $latestRequest['reference_no']) ?></span>
                  </div>
                </div>
                <div class="snapshot-box-executive">
                  <div class="snapshot-box-header"><i class="fas fa-hand-holding-heart"></i> Type of Assistance</div>
                  <div class="snapshot-box-value"><?= overview_h((string) $latestRequest['assistance_label']) ?></div>
                </div>
                <div class="snapshot-box-executive">
                  <div class="snapshot-box-header"><i class="fas fa-calendar-day"></i> Date Applied</div>
                  <div class="snapshot-box-value"><?= overview_h((string) $latestRequest['date_applied_label']) ?></div>
                </div>
                <div class="snapshot-box-executive">
                  <div class="snapshot-box-header"><i class="fas fa-circle-check"></i> Claim Status</div>
                  <div class="snapshot-box-value"><?= overview_h((string) $latestRequest['claim_status_label']) ?></div>
                </div>
                <div class="snapshot-box-executive">
                  <div class="snapshot-box-header"><i class="fas fa-user"></i> Beneficiary</div>
                  <div class="snapshot-box-value"><?= overview_h((string) $latestRequest['beneficiary_name']) ?></div>
                </div>
                <div class="snapshot-box-executive">
                  <div class="snapshot-box-header"><i class="fas fa-location-dot"></i> Location</div>
                  <div class="snapshot-box-value"><?= overview_h(trim((string) $latestRequest['barangay'] . ', ' . (string) $latestRequest['municipality'])) ?></div>
                </div>
                <div class="snapshot-box-executive">
                  <div class="snapshot-box-header"><i class="fas fa-clock-rotate-left"></i> Last Updated</div>
                  <div class="snapshot-box-value"><?= overview_h((string) $latestRequest['updated_at_label']) ?></div>
                </div>
              </div>

              <div class="snapshot-grid-footer">
                <a class="btn-track-full" href="<?= overview_h($dashboardUrl) ?>?page=status">
                  <i class="fas fa-route"></i>
                  <span>Track Detailed Status</span>
                </a>
              </div>
            <?php else: ?>
              <div class="panel-empty panel-empty-senior">
                <i class="fas fa-folder-open"></i>
                <strong>No request yet</strong>
                <p>When you submit your first AICS request, all the details will appear here so you can easily follow its progress.</p>
              </div>
            <?php endif; ?>
          </div>
        </article>
      </section>

      <section class="overview-grid overview-grid-secondary grid-senior">
        <article class="surface-panel surface-panel-senior recent-activity-card<?= count($historyRequests) === 0 ? ' recent-activity-card-full' : '' ?>">
          <div class="panel-head panel-head-senior">
            <div class="panel-title-wrap">
              <div class="panel-title-icon tone-info"><i class="fas fa-clock-rotate-left"></i></div>
              <div>
                <h2 class="panel-title">What Happened Recently</h2>
                <p class="panel-sub">The latest updates on your applications and documents.</p>
              </div>
            </div>
            <span class="section-chip section-chip-count" data-dashboard-field="recent-updates-chip">
              <i class="fas fa-bolt"></i>
              <?= overview_h((string) ($summary['recent_updates'] ?? 0)) ?> new
            </span>
          </div>

          <div class="activity-feed-executive" data-dashboard-field="activity-list">
            <?php if (count($recentActivity) > 0): ?>
              <?php foreach ($recentActivity as $item): ?>
                <?php if (!is_array($item)) { continue; } ?>
                <div class="activity-item-executive">
                  <div class="activity-icon-executive tone-<?= overview_h((string) ($item['tone'] ?? 'neutral')) ?>">
                    <i class="fas fa-<?= overview_h((string) ($item['icon'] ?? 'circle-info')) ?>"></i>
                  </div>
                  <div class="activity-copy-executive">
                    <strong><?= overview_h((string) ($item['title'] ?? 'Update')) ?></strong>
                    <p><?= overview_h((string) ($item['description'] ?? '')) ?></p>
                  </div>
                  <div class="activity-time-executive">
                    <i class="fas fa-clock"></i>
                    <?= overview_h((string) ($item['time_label'] ?? '')) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="panel-empty compact panel-empty-senior">
                <i class="fas fa-stream"></i>
                <strong>Nothing new yet</strong>
                <p>When something happens with your applications, it will appear here.</p>
              </div>
            <?php endif; ?>
          </div>
        </article>

        <?php if (count($historyRequests) > 0): ?>
        <?php
          $ovTypeIcons = [
            'medical'            => 'fa-heart-pulse',
            'burial'             => 'fa-cross',
            'victim_of_disaster' => 'fa-house-flood-water',
            'special_cases'      => 'fa-star-of-life',
          ];
          $ovTypeLabels = [
            'medical'            => 'Medical',
            'burial'             => 'Burial',
            'victim_of_disaster' => 'Victim of Disaster',
            'special_cases'      => 'Special Cases',
          ];
        ?>
        <article class="surface-panel surface-panel-senior usage-panel">
          <div class="panel-head panel-head-senior usage-panel-head">
            <div class="panel-title-wrap usage-panel-title-wrap">
              <div class="panel-title-icon tone-info"><i class="fas fa-calendar-check"></i></div>
              <div>
                <h2 class="panel-title">Assistance History This Year</h2>
                <p class="panel-sub">A one-year cooldown applies between AICS applications regardless of assistance type.</p>
              </div>
            </div>
          </div>
          <?php if (!empty($cooldownInfo['is_in_cooldown'])): ?>
            <div class="cooldown-callout-executive" role="status">
              <i class="fas fa-hourglass-half" aria-hidden="true"></i>
              <div class="cooldown-callout-copy">
                <strong>Cooldown Active:</strong>
                You may file your next application on
                <strong><?= overview_h(aics_format_cooldown_date((string) $cooldownInfo['eligible_on'])) ?></strong>
                <?php if ((int) $cooldownInfo['days_remaining'] > 0): ?>
                  (<?= (int) $cooldownInfo['days_remaining'] ?> day<?= ((int) $cooldownInfo['days_remaining'] === 1 ? '' : 's') ?> remaining).
                <?php endif; ?>
                A one-year cooldown applies between applications regardless of assistance type.
              </div>
            </div>
          <?php endif; ?>
          <div class="usage-item-list">
            <?php foreach ($historyRequests as $historyRequest): ?>
              <?php
                $code = (string) $historyRequest['type'];
                $ovLabel      = $ovTypeLabels[$code] ?? ucwords(str_replace('_', ' ', $code));
                $ovStatus = (string) $historyRequest['status'];
                $ovStatusLabel = match ($ovStatus) {
                    'pending', 'pending_review', 'for_review', 'for_validation', 'for validation' => 'Submitted',
                    'under_review', 'under review', 'under_assessment', 'under assessment', 'for_interview', 'for interview' => 'Under Review',
                    'approved', 'validated', 'for_release', 'for release' => 'Approved',
                    'scheduled' => 'Scheduled',
                    'released', 'distributed', 'completed', 'closed' => 'Completed',
                    'rejected', 'denied', 'not_approved', 'not approved' => 'Rejected',
                    'cancelled', 'canceled' => 'Cancelled',
                    default => ucwords(str_replace('_', ' ', $ovStatus === '' ? 'Submitted' : $ovStatus)),
                };
                $ovRefNo      = overview_h((string) $historyRequest['reference_no']);
                $ovApplied    = '';
                $ovAppliedOn = (string) $historyRequest['applied_on'];
                if ($ovAppliedOn !== '' && $ovAppliedOn !== '0000-00-00') {
                  try {
                    $ovDt      = new DateTimeImmutable($ovAppliedOn);
                    $ovApplied = $ovDt->format('F j, Y');
                  } catch (Throwable) {}
                }
                $ovIcon = $ovTypeIcons[$code] ?? 'fa-hand-holding-heart';
              ?>
              <div class="usage-item">
                <div class="usage-item-dot"><i class="fas <?= $ovIcon ?>"></i></div>
                <div class="usage-item-body">
                  <div class="usage-item-name">
                    <?= $ovLabel ?>
                    <span class="usage-item-badge">
                      <?= overview_h($ovStatusLabel) ?>
                    </span>
                  </div>
                  <div class="usage-item-meta">
                    <?php if ($ovRefNo !== ''): ?>
                      <span><?= $ovRefNo ?></span><span class="usage-dot-sep"></span>
                    <?php endif; ?>
                    <?php if ($ovApplied !== ''): ?><span>Applied <?= overview_h($ovApplied) ?></span><?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </article>
        <?php endif; ?>

        <article class="surface-panel surface-panel-senior" id="what-to-do-next" tabindex="-1" style="grid-column: 1 / -1;">
          <div class="panel-head panel-head-senior">
            <div class="panel-title-wrap">
              <div class="panel-title-icon tone-warning"><i class="fas fa-lightbulb"></i></div>
              <div>
                <h2 class="panel-title">What To Do Next</h2>
                <p class="panel-sub">Helpful steps we recommend for you right now.</p>
              </div>
            </div>
          </div>

          <div class="next-steps next-steps-senior" data-dashboard-field="next-steps-list">
            <?php foreach ($nextSteps as $step): ?>
              <?php if (!is_array($step)) { continue; } ?>
              <a class="next-step-item next-step-senior" href="<?= overview_h($dashboardUrl) ?>?page=<?= overview_h((string) ($step['page'] ?? 'overview')) ?>">
                <div class="next-step-icon">
                  <i class="fas fa-arrow-right-long"></i>
                </div>
                <div class="next-step-copy">
                  <strong><?= overview_h((string) ($step['title'] ?? 'Next step')) ?></strong>
                  <p><?= overview_h((string) ($step['description'] ?? '')) ?></p>
                </div>
                <span class="next-step-cta">
                  <?= overview_h((string) ($step['action_label'] ?? 'Open')) ?>
                  <i class="fas fa-chevron-right"></i>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        </article>
      </section>

      <div class="help-footer-senior" role="note">
        <div class="help-icon"><i class="fas fa-circle-question"></i></div>
        <div class="help-copy">
          <strong>Need help?</strong>
          <span>If anything is unclear, please visit the <a href="<?= overview_h($dashboardUrl) ?>?page=notifications">Notifications</a> page or contact your AICS officer. We are here to help you.</span>
        </div>
      </div>
    </div>
  </main>
</div>

<script id="dashboard-bootstrap" type="application/json"><?= htmlspecialchars((string) json_encode($dashboardData, JSON_UNESCAPED_UNICODE), ENT_NOQUOTES, 'UTF-8') ?></script>
<script src="<?= overview_h($appJsUrl) ?>"></script>
<?php require __DIR__ . '/../../chatbot/applicant-widget.php'; ?>
</body>
</html>
