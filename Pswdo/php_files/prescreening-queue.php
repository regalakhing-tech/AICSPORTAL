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

$activeTab = pswdo_normalize_prescreening_tab((string) ($_GET['queue_tab'] ?? 'for_screening'));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = ''; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) { $dateTo = ''; }
if ($dateFrom !== '' && $dateTo !== '' && strcmp($dateFrom, $dateTo) > 0) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$sortBy = pswdo_normalize_prescreening_sort((string) ($_GET['sort_by'] ?? 'default'));
$sortGroup = pswdo_normalize_prescreening_sort_group($sortBy, (string) ($_GET['sort_group'] ?? 'all'));
$actionStatus = trim((string) ($_GET['status'] ?? ''));
$actionMessage = trim((string) ($_GET['message'] ?? ''));
$counts = [
    'for_screening' => 0,
    'incomplete_requirements' => 0,
    'invalid_requirements' => 0,
];
$queueItems = [];
$loadError = '';
$totalQueueCount = 0;

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);
    // Decide AICS AI checks first so this page lists every request that has passed.
    aics_ai_gate_page_sync($connection);
    $profile = pswdo_fetch_staff_profile($connection, $accountId, $displayName);
    $displayName = (string) ($profile['display_name'] ?? $displayName);
    $initials = (string) ($profile['initials'] ?? $initials);
    $counts = pswdo_fetch_prescreening_counts($connection);
    $queueItems = pswdo_fetch_prescreening_queue($connection, $activeTab, $dateFrom, $dateTo, $sortBy, $sortGroup);
    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load screening queue right now.';
}

$totalQueueCount = (int) ($counts['for_screening'] ?? 0) + (int) ($counts['incomplete_requirements'] ?? 0) + (int) ($counts['invalid_requirements'] ?? 0);
$sortLabels = [
    'default' => 'Smart Priority',
    'client_classification' => 'Beneficiary Classification',
    'type_of_assistance' => 'Type of Assistance',
    'high_priority' => 'Priority Cases',
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
    'high_priority' => [
        'all' => 'All',
        'high_priority' => 'Priority Cases Only',
    ],
];
$activeSortGroupLabel = $sortGroupLabels[$sortBy][$sortGroup] ?? 'All';
$massNoticePreviewCount = count($queueItems);
$massNoticeEligibleCount = 0;
foreach ($queueItems as $queueItem) {
    if (pswdo_applicant_sms_contact($queueItem) !== '') {
        $massNoticeEligibleCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PSWDO Portal | Screening Queue</title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= pswdo_h($paths['css_url']) ?>?v=<?= filemtime(__DIR__ . '/../css files/pswdo-pages.css') ?>">
</head>
<body class="prescreening-page">
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
        <a class="nav-link active" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=prescreening"><i class="fas fa-clipboard-check"></i> Screening Queue</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release"><i class="fas fa-calendar-check"></i> Release Scheduling</a>
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
          <div class="top-chip"><i class="fas fa-list-check"></i> Screening Decisions</div>
          <div class="profile-chip">
            <div class="avatar"><?= pswdo_h($initials) ?></div>
            <span><?= pswdo_h($displayName) ?></span>
          </div>
        </div>
      </header>

      <section class="content-wrap">
        <section class="queue-hero">
          <div class="queue-hero-copy page-header-main">
            <h1><i class="fas fa-clipboard-check"></i> Screening Queue</h1>
          </div>
          <div class="queue-summary-grid">
            <article class="queue-summary-card">
              <span class="queue-summary-label">For Screening</span>
              <strong><?= (int) ($counts['for_screening'] ?? 0) ?></strong>
            </article>
            <article class="queue-summary-card">
              <span class="queue-summary-label">Incomplete</span>
              <strong><?= (int) ($counts['incomplete_requirements'] ?? 0) ?></strong>
            </article>
            <article class="queue-summary-card">
              <span class="queue-summary-label">Invalid</span>
              <strong><?= (int) ($counts['invalid_requirements'] ?? 0) ?></strong>
            </article>
          </div>
        </section>

        <?php if ($loadError !== ''): ?>
          <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?= pswdo_h($loadError) ?></div>
        <?php endif; ?>

        <?php if ($actionStatus !== '' && $actionMessage !== ''): ?>
          <div class="alert toast-alert js-toast-alert <?= $actionStatus === 'success' ? 'alert-success' : 'alert-error' ?>" role="status" aria-live="polite">
            <i class="fas fa-<?= $actionStatus === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
            <span><?= pswdo_h($actionMessage) ?></span>
          </div>
        <?php endif; ?>


        <section class="panel-card queue-table-panel">
          <header class="panel-head release-queue-head">
            <div class="release-queue-head-main">
              <h2>
                <i class="fas fa-table-list"></i>
                <?= match ($activeTab) {
                  'incomplete_requirements' => 'Applications Flagged as Incomplete',
                  'invalid_requirements' => 'Applications Marked as Invalid / Not Approved',
                  default => 'Applications Ready for Screening',
                } ?>
              </h2>
            </div>
            <div class="screening-head-controls">
              <div class="field-wrap screening-search-control">
                <label for="queue-search">Search Applicant / Reference</label>
                <div class="screening-search-input">
                  <i class="fas fa-magnifying-glass"></i>
                  <input
                    type="text"
                    id="queue-search"
                    placeholder="Name, beneficiary, or reference no…"
                    autocomplete="off"
                  >
                </div>
              </div>
              <form method="get" action="<?= pswdo_h($paths['dashboard_url']) ?>" class="screening-inline-controls" id="screening-controls-form">
                <input type="hidden" name="page" value="prescreening">
                <input type="hidden" name="queue_tab" value="<?= pswdo_h($activeTab) ?>">
                <input type="hidden" name="sort_group" id="queue-sort-group" value="<?= pswdo_h($sortGroup) ?>">
                <div class="field-wrap">
                  <label for="queue-date-from">Date Applied From</label>
                  <input type="date" id="queue-date-from" name="date_from" value="<?= pswdo_h($dateFrom) ?>">
                </div>
                <div class="field-wrap">
                  <label for="queue-date-to">Date Applied To</label>
                  <input type="date" id="queue-date-to" name="date_to" value="<?= pswdo_h($dateTo) ?>">
                </div>
                <div class="field-wrap">
                  <label for="sort_by">Sort by</label>
                  <select id="sort_by" name="sort_by">
                    <option value="default" <?= $sortBy === 'default' ? 'selected' : '' ?>>Smart Priority</option>
                    <option value="client_classification" <?= $sortBy === 'client_classification' ? 'selected' : '' ?>>Beneficiary Classification</option>
                    <option value="type_of_assistance" <?= $sortBy === 'type_of_assistance' ? 'selected' : '' ?>>Type of Assistance</option>
                    <option value="high_priority" <?= $sortBy === 'high_priority' ? 'selected' : '' ?>>Priority Cases</option>
                  </select>
                </div>
                <?php if ($activeTab === 'for_screening'): ?>
                  <button
                    type="button"
                    class="btn-solid screening-mass-notice js-open-mass-sms-modal"
                    data-mass-action-type="mass_send_prescreening_sms"
                    data-mass-decision-status="for_screening"
                    data-recipient-count="<?= $massNoticePreviewCount ?>"
                    data-ready-count="<?= $massNoticeEligibleCount ?>"
                  >
                    <i class="fas fa-bullhorn"></i> Mass Notice
                  </button>
                <?php endif; ?>
              </form>
            </div>
          </header>
          <?php foreach ($sortGroupLabels as $groupSortKey => $groupOptions): ?>
            <div class="release-quick-filter-block<?= $sortBy === $groupSortKey ? '' : ' is-hidden' ?>" data-sort-buttons-for="<?= pswdo_h($groupSortKey) ?>">
              <span class="release-quick-filter-label"><?= pswdo_h($sortLabels[$groupSortKey] ?? 'Sort') ?> filters</span>
              <div class="release-quick-filter-row">
                <?php foreach ($groupOptions as $groupCode => $groupLabel): ?>
                  <a
                    href="<?= pswdo_h($paths['dashboard_url']) . '?page=prescreening&queue_tab=' . rawurlencode($activeTab) . '&date_from=' . rawurlencode($dateFrom) . '&date_to=' . rawurlencode($dateTo) . '&sort_by=' . rawurlencode($groupSortKey) . '&sort_group=' . rawurlencode($groupCode) ?>"
                    class="queue-tab<?= $sortBy === $groupSortKey && $sortGroup === $groupCode ? ' active' : '' ?>"
                  >
                    <?= pswdo_h($groupLabel) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (count($queueItems) === 0): ?>
            <p class="empty-note">No applications in this tab yet.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table class="request-table screening-queue-table">
                <colgroup>
                  <col class="screening-col-reference"><col class="screening-col-person"><col class="screening-col-priority">
                  <col class="screening-col-assistance"><col class="screening-col-applied"><col class="screening-col-requirements">
                  <col class="screening-col-status"><col class="screening-col-confirmed-by"><col class="screening-col-confirmed-at"><col class="screening-col-actions">
                </colgroup>
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Applicant / Beneficiary</th>
                    <th>Priority</th>
                    <th>Applying For</th>
                    <th>Date Applied</th>
                    <th>Requirements</th>
                    <th>Current Status</th>
                    <th>Confirmed By</th>
                    <th>Confirmed At</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr class="queue-no-results-row" style="display:none;">
                    <td colspan="10" style="text-align:center;padding:24px;color:#64748b;font-style:italic;">No applicants match your search.</td>
                  </tr>
                  <?php foreach ($queueItems as $item): ?>
                    <?php
                      $priorityIndicator = pswdo_screening_priority_indicator($item);
                      $searchText = strtolower(implode(' ', array_filter([
                          (string) ($item['reference_no'] ?? ''),
                          (string) ($item['applicant_name'] ?? ''),
                          (string) ($item['beneficiary_name'] ?? ''),
                          (string) ($item['assistance_label'] ?? ''),
                      ])));
                    ?>
                    <tr data-search-text="<?= pswdo_h($searchText) ?>">
                      <td>
                        <div class="reference-stack">
                          <strong><?= pswdo_h((string) ($item['reference_no'] ?? '-')) ?></strong>
                        </div>
                      </td>
                      <td>
                        <div class="cell-user">
                          <strong><?= pswdo_h((string) ($item['applicant_name'] ?? 'Applicant')) ?></strong>
                          <span>Beneficiary: <?= pswdo_h((string) ($item['beneficiary_name'] ?? '-')) ?></span>
                        </div>
                      </td>
                      <td>
                        <span class="priority-chip screening-priority-chip <?= pswdo_h($priorityIndicator['class']) ?>">
                          <?= pswdo_h($priorityIndicator['label']) ?>
                        </span>
                      </td>
                      <td><?= pswdo_h((string) ($item['assistance_label'] ?? 'Assistance Request')) ?></td>
                      <td><?= pswdo_h((string) ($item['date_applied_label'] ?? 'Not available')) ?></td>
                      <td>
                        <span class="doc-chip">
                          <?= (int) ($item['uploaded_total'] ?? 0) ?> / <?= (int) ($item['required_total'] ?? 0) ?> uploaded
                        </span>
                      </td>
                      <td>
                        <?php if (trim((string) ($item['screening_sms_notified_at'] ?? '')) !== ''): ?>
                          <span class="screening-notified-chip">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                            Notified
                          </span>
                        <?php else: ?>
                          <span class="pill pill-<?= pswdo_h((string) ($item['status_tone'] ?? 'pending')) ?>">
                            <?= pswdo_h((string) ($item['status_label'] ?? 'Pending Review')) ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <td><?= pswdo_h((string) ($item['confirmed_by'] ?? 'PSWDO Personnel')) ?></td>
                      <td><?= pswdo_h((string) ($item['confirmed_at_label'] ?? 'Not available')) ?></td>
                      <td>
                        <div class="queue-table-actions">
                          <button
                            type="button"
                            class="btn-outline screening-action-button screening-action-view js-open-screening-detail"
                            data-request-id="<?= (int) ($item['request_id'] ?? 0) ?>"
                            data-reference-no="<?= pswdo_h((string) ($item['reference_no'] ?? '')) ?>"
                          >
                            <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> View details
                          </button>
                          <button
                            type="button"
                            class="btn-outline screening-action-button screening-action-sms js-open-sms-modal"
                            data-request-id="<?= (int) ($item['request_id'] ?? 0) ?>"
                            data-reference-no="<?= pswdo_h((string) ($item['reference_no'] ?? '')) ?>"
                            data-applicant-name="<?= pswdo_h((string) ($item['applicant_name'] ?? 'Applicant')) ?>"
                            data-beneficiary-name="<?= pswdo_h((string) ($item['beneficiary_name'] ?? 'Applicant')) ?>"
                            data-recipient-number="<?= pswdo_h(pswdo_applicant_sms_contact($item)) ?>"
                            data-decision-status="<?= pswdo_h((string) ($item['decision_status'] ?? 'for_screening')) ?>"
                            <?= pswdo_applicant_sms_contact($item) === '' ? 'disabled' : '' ?>
                          >
                            <i class="fas fa-message" aria-hidden="true"></i> Send SMS
                          </button>
                          <?php if ($activeTab === 'for_screening'): ?>
                          <button
                            type="button"
                            class="btn-solid screening-action-button screening-action-approve js-approve-for-release"
                            data-request-id="<?= (int) ($item['request_id'] ?? 0) ?>"
                            data-reference-no="<?= pswdo_h((string) ($item['reference_no'] ?? '')) ?>"
                            data-applicant-name="<?= pswdo_h((string) ($item['applicant_name'] ?? 'Applicant')) ?>"
                          >
                            <i class="fas fa-circle-check" aria-hidden="true"></i> Approve for Scheduling
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
      </section>
    </main>
  </div>

  <!-- Approve for Scheduling confirmation modal -->
  <div class="sms-compose-modal" id="approve-release-modal" style="display:none;">
    <div class="sms-compose-card" role="dialog" aria-modal="true" aria-labelledby="approve-release-title">
      <div class="sms-compose-header">
        <h3 id="approve-release-title"><i class="fas fa-check-circle" style="color:#059669;"></i> Approve for Scheduling</h3>
        <button type="button" class="close-details-btn" id="close-approve-release-modal" aria-label="Close">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="sms-compose-body">
        <p style="margin:0 0 6px;">You are about to move this application to the <strong>Release Scheduling</strong> queue.</p>
        <p id="approve-release-applicant" style="margin:0 0 18px;color:#475569;font-size:14px;"></p>
        <p style="margin:0 0 18px;color:#475569;font-size:13px;">This action will change the applicant's status from <strong>Approved</strong> to <strong>For Release</strong>. The applicant will appear in Release Scheduling where you can set a release date and send a notice.</p>
        <form method="post" action="workflow-action.php" id="approve-release-form">
          <input type="hidden" name="csrf_token" value="<?= pswdo_h($csrfToken) ?>">
          <input type="hidden" name="action_type" value="approve_for_release">
          <input type="hidden" name="request_id" id="approve-release-request-id" value="">
          <input type="hidden" name="return_page" value="prescreening">
          <input type="hidden" name="return_queue_tab" value="<?= pswdo_h($activeTab) ?>">
          <input type="hidden" name="return_date_from" value="<?= pswdo_h($dateFrom) ?>">
          <input type="hidden" name="return_date_to" value="<?= pswdo_h($dateTo) ?>">
          <input type="hidden" name="return_queue_sort" value="<?= pswdo_h($sortBy) ?>">
          <input type="hidden" name="return_queue_group" value="<?= pswdo_h($sortGroup) ?>">
          <div class="confirm-actions">
            <button type="button" class="btn-outline" id="cancel-approve-release">Cancel</button>
            <button type="submit" class="btn-solid" style="background:#059669;"><i class="fas fa-check-circle"></i> Confirm for Scheduling</button>
          </div>
        </form>
      </div>
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
        <input type="hidden" name="return_page" value="prescreening">
        <input type="hidden" name="return_queue_tab" value="<?= pswdo_h($activeTab) ?>">
        <input type="hidden" name="return_date_from" value="<?= pswdo_h($dateFrom) ?>">
        <input type="hidden" name="return_date_to" value="<?= pswdo_h($dateTo) ?>">
        <input type="hidden" name="return_queue_sort" value="<?= pswdo_h($sortBy) ?>">
        <input type="hidden" name="return_queue_group" value="<?= pswdo_h($sortGroup) ?>">

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

  <!-- Screening Detail Modal (in-place iframe of Request Management) -->
  <div id="screening-detail-iframe-modal" class="screening-detail-modal" aria-hidden="true">
    <div class="screening-detail-card" role="dialog" aria-modal="true" aria-labelledby="screening-detail-title">
      <div class="screening-detail-header">
        <h3 id="screening-detail-title">
          <i class="fas fa-folder-open"></i> Application review
        </h3>
        <button type="button" id="close-screening-detail-iframe" class="close-details-btn" aria-label="Close application review">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <iframe id="screening-detail-iframe" class="screening-detail-frame" src="about:blank" title="Application review"></iframe>
    </div>
  </div>

  <script src="<?= pswdo_h($paths['js_url']) ?>"></script>
  <script>
    (() => {
      const scrollKey = 'aics_prescreening_scroll_y';
      const savedScroll = window.sessionStorage.getItem(scrollKey);
      if (savedScroll !== null) {
        const parsed = Number.parseInt(savedScroll, 10);
        if (Number.isFinite(parsed) && parsed >= 0) {
          window.scrollTo({ top: parsed, behavior: 'auto' });
        }
        window.sessionStorage.removeItem(scrollKey);
      }

      const controlsForm = document.getElementById('screening-controls-form');
      const dateFromInput = document.getElementById('queue-date-from');
      const dateToInput = document.getElementById('queue-date-to');
      const sortSelect = document.getElementById('sort_by');
      const sortGroupInput = document.getElementById('queue-sort-group');
      const quickBlocks = document.querySelectorAll('[data-sort-buttons-for]');
      if (
        !(controlsForm instanceof HTMLFormElement) ||
        !(sortSelect instanceof HTMLSelectElement) ||
        !(sortGroupInput instanceof HTMLInputElement) ||
        quickBlocks.length === 0
      ) {
        return;
      }

      function syncQuickButtons() {
        const selected = sortSelect.value;
        quickBlocks.forEach((block) => {
          const expected = block.getAttribute('data-sort-buttons-for');
          block.classList.toggle('is-hidden', expected !== selected);
        });
      }

      function rememberScrollPosition() {
        window.sessionStorage.setItem(scrollKey, String(Math.max(0, Math.floor(window.scrollY || 0))));
      }

      if (dateFromInput instanceof HTMLInputElement) {
        dateFromInput.addEventListener('change', () => {
          rememberScrollPosition();
          controlsForm.submit();
        });
      }
      if (dateToInput instanceof HTMLInputElement) {
        dateToInput.addEventListener('change', () => {
          rememberScrollPosition();
          controlsForm.submit();
        });
      }

      sortSelect.addEventListener('change', () => {
        sortGroupInput.value = 'all';
        syncQuickButtons();
        rememberScrollPosition();
        controlsForm.submit();
      });

      const quickFilterLinks = document.querySelectorAll('[data-sort-buttons-for] a.queue-tab');
      quickFilterLinks.forEach((link) => {
        link.addEventListener('click', () => {
          rememberScrollPosition();
        });
      });

      syncQuickButtons();
    })();

    // Queue search filter
    (() => {
      const searchInput = document.getElementById('queue-search');
      if (!(searchInput instanceof HTMLInputElement)) return;

      const tbody = document.querySelector('.request-table tbody');
      if (!(tbody instanceof HTMLElement)) return;

      const noResultsRow = tbody.querySelector('.queue-no-results-row');

      searchInput.addEventListener('input', () => {
        const term = searchInput.value.trim().toLowerCase();
        const dataRows = Array.from(tbody.querySelectorAll('tr[data-search-text]'));
        let visibleCount = 0;

        dataRows.forEach((row) => {
          const haystack = (row.getAttribute('data-search-text') || '').toLowerCase();
          const match = term === '' || haystack.includes(term);
          row.style.display = match ? '' : 'none';
          if (match) visibleCount++;
        });

        if (noResultsRow instanceof HTMLElement) {
          noResultsRow.style.display = visibleCount === 0 && term !== '' ? '' : 'none';
        }
      });

      searchInput.focus();
    })();

    // Approve for Scheduling modal
    (() => {
      const modal = document.getElementById('approve-release-modal');
      const requestIdInput = document.getElementById('approve-release-request-id');
      const applicantLabel = document.getElementById('approve-release-applicant');
      if (!(modal instanceof HTMLElement) || !(requestIdInput instanceof HTMLInputElement) || !(applicantLabel instanceof HTMLElement)) {
        return;
      }

      function openModal(requestId, referenceNo, applicantName) {
        requestIdInput.value = String(requestId);
        applicantLabel.textContent = referenceNo + ' — ' + applicantName;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
      }

      function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        requestIdInput.value = '';
        applicantLabel.textContent = '';
      }

      document.querySelectorAll('.js-approve-for-release').forEach((btn) => {
        btn.addEventListener('click', () => {
          const requestId = btn.getAttribute('data-request-id') ?? '';
          const referenceNo = btn.getAttribute('data-reference-no') ?? '';
          const applicantName = btn.getAttribute('data-applicant-name') ?? 'Applicant';
          openModal(requestId, referenceNo, applicantName);
        });
      });

      const cancelBtn = document.getElementById('cancel-approve-release');
      const closeBtn = document.getElementById('close-approve-release-modal');
      if (cancelBtn instanceof HTMLElement) cancelBtn.addEventListener('click', closeModal);
      if (closeBtn instanceof HTMLElement) closeBtn.addEventListener('click', closeModal);

      const approveForm = document.getElementById('approve-release-form');
      if (approveForm instanceof HTMLFormElement) {
        approveForm.addEventListener('submit', (event) => {
          const requestId = requestIdInput.value.trim();
          if (!/^\d+$/.test(requestId)) {
            event.preventDefault();
            return;
          }
          const submitButton = approveForm.querySelector('button[type="submit"]');
          if (submitButton instanceof HTMLButtonElement) {
            if (submitButton.disabled) {
              event.preventDefault();
              return;
            }
            submitButton.disabled = true;
            submitButton.setAttribute('aria-busy', 'true');
          }
        });
      }

      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
      });
    })();

    // Screening Detail iframe modal — loads Request Management in place
    (() => {
      const wrapper = document.getElementById('screening-detail-iframe-modal');
      const frame = document.getElementById('screening-detail-iframe');
      const closeBtn = document.getElementById('close-screening-detail-iframe');
      const dashboardUrl = <?= json_encode($paths['dashboard_url']) ?>;
      if (!wrapper || !frame) return;

      function openModal(requestId) {
        const id = (requestId || '').toString().trim();
        if (!/^\d+$/.test(id)) return;
        const url = dashboardUrl + '?page=requests&reopen_request=' + encodeURIComponent(id) + '&embed=1';
        frame.src = url;
        wrapper.style.display = 'flex';
        wrapper.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('modal-open');
        document.body.classList.add('modal-open');
      }

      function closeModal() {
        wrapper.style.display = 'none';
        wrapper.setAttribute('aria-hidden', 'true');
        frame.src = 'about:blank';
        document.documentElement.classList.remove('modal-open');
        document.body.classList.remove('modal-open');
      }

      document.querySelectorAll('.js-open-screening-detail').forEach((btn) => {
        btn.addEventListener('click', () => openModal(btn.dataset.requestId));
      });
      closeBtn?.addEventListener('click', closeModal);
      wrapper.addEventListener('click', (e) => {
        if (e.target === wrapper) closeModal();
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && wrapper.style.display === 'flex') closeModal();
      });

      // Listen for messages from inside the iframe
      window.addEventListener('message', (event) => {
        if (!event || !event.data || typeof event.data !== 'object') return;

        // Manual close request from iframe
        if (event.data.type === 'screening-detail-close') {
          closeModal();
          return;
        }

        // Workflow action completed inside the iframe — close modal and refresh the queue
        if (event.data.type === 'aics-workflow-complete') {
          closeModal();
          // Brief delay so the user sees the modal close, then refresh queue with toast.
          setTimeout(function () {
            const url = new URL(window.location.href);
            if (event.data.status) url.searchParams.set('status', event.data.status);
            if (event.data.message) url.searchParams.set('message', event.data.message);
            window.location.href = url.toString();
          }, 200);
        }
      });
    })();
  </script>
</body>
</html>
