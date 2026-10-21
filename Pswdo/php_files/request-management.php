<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-data-lib.php';
require_once __DIR__ . '/pswdo-ai-verdict-card.php';

pswdo_start_session();
$paths = pswdo_paths();

if (!isset($_SESSION['aics_pswdo_csrf']) || !is_string($_SESSION['aics_pswdo_csrf']) || $_SESSION['aics_pswdo_csrf'] === '') {
    $_SESSION['aics_pswdo_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['aics_pswdo_csrf'];

// Set by an approval in workflow-action.php; shown once as the feedback card.
$approvalFeedback = is_array($_SESSION['aics_pswdo_approval_feedback'] ?? null) ? $_SESSION['aics_pswdo_approval_feedback'] : null;
unset($_SESSION['aics_pswdo_approval_feedback']);

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = pswdo_current_display_name();
$initials = pswdo_initials($displayName);

$filterStatus = pswdo_normalize_filter_status((string) ($_GET['status_filter'] ?? 'all'));
$dateFilter = pswdo_normalize_date_filter((string) ($_GET['date_filter'] ?? 'all'));
$search = trim((string) ($_GET['search'] ?? ''));

$actionStatus = trim((string) ($_GET['status'] ?? ''));
$actionMessage = trim((string) ($_GET['message'] ?? ''));
$isEmbedded = ((string) ($_GET['embed'] ?? '')) === '1';
$legacyAiReprocessNotice = $actionStatus === 'success' && in_array($actionMessage, [
    'AI verification was queued for reprocessing. The previous result was preserved.',
    'AI verification is processing. The previous result was preserved.',
], true);
if ($legacyAiReprocessNotice) {
    // AI reprocessing is now in-place. Consume legacy query-string notices
    // without affecting normal PSWDO status/review notifications.
    $actionStatus = '';
    $actionMessage = '';
}
$reopenRequestId = (int) ($_GET['reopen_request'] ?? 0);
$reopenAiDocumentId = (int) ($_GET['reopen_ai_document'] ?? 0);
$appModuleRootUrl = dirname((string) $paths['pswdo_base_url']);
$requestExportUrl = $appModuleRootUrl . '/Applicants/php_files/request-export.php';

$requests = [];
$loadError = '';

try {
    $connection = aics_db_connect();
    pswdo_ensure_schema($connection);
    // Decide AICS AI checks first so this page lists every request that has passed.
    aics_ai_gate_page_sync($connection);
    $profile = pswdo_fetch_staff_profile($connection, $accountId, $displayName);
    $displayName = (string) ($profile['display_name'] ?? $displayName);
    $initials = (string) ($profile['initials'] ?? $initials);
    $requestQueueFilters = [
        'status' => $filterStatus,
        'date_filter' => $dateFilter,
        'search' => $search,
    ];
    // The embedded detail modal can open an approved Screening Queue request.
    // Include only that explicitly selected record for its shared renderer;
    // the regular incoming list remains pending-review only.
    if ($isEmbedded && $reopenRequestId > 0) {
        $requestQueueFilters['detail_request_id'] = $reopenRequestId;
    }
    $requests = pswdo_fetch_request_queue($connection, $requestQueueFilters);
    $connection->close();
} catch (Throwable) {
    $loadError = 'Unable to load request queue right now.';
}

$statusLabels = pswdo_status_labels();
$hiddenFilterStatuses = ['for_interview', 'under_review'];
$statusLabels = array_filter(
    $statusLabels,
    static fn ($_label, $code): bool => !in_array((string) $code, $hiddenFilterStatuses, true),
    ARRAY_FILTER_USE_BOTH
);
$terminalStatusCodes = ['released', 'not_approved', 'cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PSWDO Portal | Request Management</title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= pswdo_h($paths['css_url']) ?>">
  <?php if ($isEmbedded): ?>
    <style>
      body.is-embedded .sidebar,
      body.is-embedded .sidebar-overlay,
      body.is-embedded .top-nav { display: none !important; }
      body.is-embedded .main-content { margin-left: 0 !important; padding: 0 !important; }
      body.is-embedded .content-wrap > .panel-card { display: none; }
      body.is-embedded .request-details-modal { background: transparent !important; }
      body.is-embedded .request-details-modal .request-details-card { box-shadow: none !important; border-radius: 0 !important; max-height: 100vh !important; }
      body.is-embedded { background: #fff; }
    </style>
  <?php endif; ?>
</head>
<body data-reopen-request-id="<?= $reopenRequestId > 0 ? $reopenRequestId : '' ?>" data-reopen-ai-document-id="<?= $reopenAiDocumentId > 0 ? $reopenAiDocumentId : '' ?>" data-ai-status-url="<?= pswdo_h($paths['php_files_base_url'] . '/ai-review-status.php') ?>" data-ai-request-status-url="<?= pswdo_h($paths['php_files_base_url'] . '/ai-request-status.php') ?>" data-ai-ensure-url="<?= pswdo_h($paths['php_files_base_url'] . '/ai-ensure-current.php') ?>" data-ai-ensure-request-url="<?= pswdo_h($paths['php_files_base_url'] . '/ai-ensure-request-current.php') ?>" data-ai-csrf-token="<?= pswdo_h($csrfToken) ?>" data-ai-live-status-v2="1" data-clear-legacy-ai-notice="<?= $legacyAiReprocessNotice ? '1' : '0' ?>" class="<?= $isEmbedded ? 'is-embedded' : '' ?>">
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
        <a class="nav-link active" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests"><i class="fas fa-folder-open"></i> Request Management</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=prescreening"><i class="fas fa-clipboard-check"></i> Screening Queue</a>
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
          <div class="top-chip"><i class="fas fa-table-list"></i> Applicant Request Table</div>
          <div class="profile-chip">
            <div class="avatar"><?= pswdo_h($initials) ?></div>
            <span><?= pswdo_h($displayName) ?></span>
          </div>
        </div>
      </header>

      <section class="content-wrap">
        <section class="panel-card page-header-card">
          <div class="page-header-main">
            <h1><i class="fas fa-folder-open"></i> Applicant Requests Table</h1>
          </div>
        </section>

        <section class="panel-card">
          <form method="get" action="<?= pswdo_h($paths['dashboard_url']) ?>" class="filter-form">
            <input type="hidden" name="page" value="requests" />
            <div class="field-wrap">
              <label for="status_filter">Filter by Status</label>
              <select id="status_filter" name="status_filter" onchange="this.form.submit()">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <?php foreach ($statusLabels as $code => $label): ?>
                  <option value="<?= pswdo_h($code) ?>" <?= $filterStatus === $code ? 'selected' : '' ?>><?= pswdo_h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-wrap">
              <label for="date_filter">Date Range</label>
              <select id="date_filter" name="date_filter">
                <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="this_week" <?= $dateFilter === 'this_week' ? 'selected' : '' ?>>This Week</option>
                <option value="this_month" <?= $dateFilter === 'this_month' ? 'selected' : '' ?>>This Month</option>
              </select>
            </div>
            <div class="field-wrap grow">
              <label for="search">Search Request / Beneficiary</label>
              <input id="search" type="text" name="search" value="<?= pswdo_h($search) ?>" placeholder="Reference number or beneficiary name">
            </div>
            <button type="submit" class="btn-solid"><i class="fas fa-magnifying-glass"></i> Search</button>
          </form>
        </section>

        <?php if ($actionStatus !== '' && $actionMessage !== '' && $approvalFeedback === null): ?>
          <div class="alert toast-alert js-toast-alert <?= $actionStatus === 'success' ? 'alert-success' : 'alert-error' ?>" role="status" aria-live="polite">
            <i class="fas fa-<?= $actionStatus === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
            <span><?= pswdo_h($actionMessage) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
          <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?= pswdo_h($loadError) ?></div>
        <?php endif; ?>

        <section class="panel-card">
          <header class="panel-head">
            <h2><i class="fas fa-list-check"></i> Incoming Applicant Requests</h2>
            <form method="post" action="workflow-action.php" id="bulk-approve-form" class="bulk-approve-bar js-confirm-action" hidden
              data-title="Approve Selected Applications?"
              data-message="">
              <input type="hidden" name="csrf_token" value="<?= pswdo_h($csrfToken) ?>">
              <input type="hidden" name="action_type" value="bulk_approve_requests">
              <input type="hidden" name="return_page" value="requests">
              <span class="bulk-approve-count" id="bulk-approve-count" aria-live="polite">0 selected</span>
              <button type="submit" class="btn-solid"><i class="fas fa-clipboard-check"></i> Approve Selected</button>
            </form>
          </header>
          <?php if (count($requests) === 0): ?>
            <p class="empty-note">No requests found for the current filter.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table class="request-table">
                <thead>
                  <tr>
                    <th class="request-select-cell"><input type="checkbox" id="select-all-requests" aria-label="Select all requests that can be approved"></th>
                    <th>Reference</th>
                    <th>Applicant / Beneficiary</th>
                    <th>Applying For</th>
                    <th>Date Applied</th>
                    <th>Contact</th>
                    <th>Documents</th>
                    <th>Priority</th>
                    <th>Duplicate</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($requests as $request): ?>
                    <?php
                      $statusCode = (string) ($request['status_code'] ?? 'pending_review');
                      $canSelectForApproval = !in_array($statusCode, ['approved', ...$terminalStatusCodes], true);
                      $aiProcessed = (int) ($request['ai_processed_total'] ?? 0);
                      $aiCompleted = (int) ($request['ai_completed_total'] ?? 0);
                      $aiUnavailable = (int) ($request['ai_unavailable_total'] ?? 0);
                      $aiProcessing = (int) ($request['ai_processing_total'] ?? 0);
                      $aiReprocessingNeeded = (int) ($request['ai_reprocessing_needed_total'] ?? 0);
                      $applicantName = trim((string) ($request['applicant_name'] ?? ''));
                      $beneficiaryName = trim((string) ($request['beneficiary_name'] ?? ''));
                      $possibleDuplicate = (bool) (($request['validation']['duplicate_flag'] ?? false));
                      $duplicateBadge = pswdo_duplicate_badge_meta($possibleDuplicate ? 'possible duplicate' : 'no duplicate');
                      $priorityUrgency = (string) ($request['priority']['urgency_indicator'] ?? 'REQUIRES_STAFF_REVIEW');
                      $priorityIndicator = pswdo_request_management_indicator((string) ($request['client_categories_json'] ?? '[]'), $priorityUrgency, (string) ($request['assistance_code'] ?? ''));
                      $priorityEta = aics_ai_gate_eta_summary($priorityIndicator['label'], (string) ($request['gate_received_at'] ?? ''));
                      $aiAttentionCount = (int) ($request['ai_attention_count'] ?? 0);
                      $aiRequestState = (string) ($request['ai_request_state'] ?? 'empty');
                      $aiRequestLabel = (string) ($request['ai_request_label'] ?? 'No verifiable files');
                      $aiProgress = max(0, min(100, (int) ($request['ai_progress_percent'] ?? 0)));
                      $hasAiFlaggedInconsistency = pswdo_request_has_flagged_inconsistency((array) ($request['required_documents'] ?? []));
                    ?>
                    <tr data-ai-request-row data-request-id="<?= (int) ($request['id'] ?? 0) ?>" data-ai-state="<?= pswdo_h($aiRequestState) ?>" class="<?= pswdo_h(trim(($aiRequestState === 'scanning' ? 'is-ai-scanning ' : '') . ($hasAiFlaggedInconsistency ? 'has-ai-flagged-inconsistency' : ''))) ?>">
                      <td class="request-select-cell">
                        <?php if ($canSelectForApproval): ?>
                          <input type="checkbox" class="js-request-select" value="<?= (int) ($request['id'] ?? 0) ?>" aria-label="Select request <?= pswdo_h((string) ($request['reference_no'] ?? '')) ?>">
                        <?php endif; ?>
                      </td>
                      <td><strong><?= pswdo_h((string) ($request['reference_no'] ?? '-')) ?></strong></td>
                      <td>
                        <div class="cell-user">
                          <strong><?= pswdo_h($applicantName !== '' ? $applicantName : 'Applicant Name Unavailable') ?></strong>
                          <span>Beneficiary: <?= pswdo_h($beneficiaryName !== '' ? $beneficiaryName : '-') ?></span>
                        </div>
                      </td>
                      <td><?= pswdo_h((string) ($request['assistance_label'] ?? 'Assistance Request')) ?></td>
                      <td><?= pswdo_h((string) ($request['date_applied_label'] ?? 'Not available')) ?></td>
                      <td><?= pswdo_h((string) ($request['contact_number'] ?? '-')) ?></td>
                      <td>
                        <div class="request-ai-state" aria-live="polite" aria-atomic="true">
                          <span class="request-ai-label">
                            <?php if ($aiRequestState === 'scanning'): ?><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i><?php endif; ?>
                            <span><?= pswdo_h($aiRequestLabel) ?></span>
                          </span>
                          <?php if ($aiRequestState === 'scanning'): ?>
                            <span class="request-ai-progress" role="progressbar" aria-label="Document verification progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $aiProgress ?>">
                              <span style="--ai-progress: <?= $aiProgress ?>%"></span>
                            </span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td>
                        <span class="priority-chip <?= pswdo_h($priorityIndicator['class']) ?>"><?= pswdo_h($priorityIndicator['label']) ?></span>
                        <?php if ($priorityEta !== null): ?>
                          <small class="priority-eta<?= $priorityEta['overdue'] ? ' is-overdue' : '' ?>" title="Expected processing: <?= pswdo_h($priorityEta['span']) ?>"><?= $priorityEta['overdue'] ? 'Overdue · ' : 'By ' ?><?= pswdo_h($priorityEta['due_short']) ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="pill <?= pswdo_h($duplicateBadge['class']) ?>"><i class="fas <?= pswdo_h($duplicateBadge['icon']) ?>"></i> <?= pswdo_h($duplicateBadge['label']) ?></span>
                      </td>
                      <td>
                        <span class="pill pill-<?= pswdo_h((string) ($request['status_tone'] ?? 'pending')) ?>">
                          <?= pswdo_h((string) ($request['status_label'] ?? 'Pending Review')) ?>
                        </span>
                      </td>
                      <td>
                        <span class="view-request-action">
                          <button type="button" class="btn-solid btn-view-request js-open-request-modal" data-request-id="<?= (int) ($request['id'] ?? 0) ?>">
                            <i class="fas fa-eye"></i> View
                          </button>
                          <?php if ($aiAttentionCount > 0): ?><span class="ai-attention-badge" aria-label="<?= $aiAttentionCount ?> document<?= $aiAttentionCount === 1 ? '' : 's' ?> need PSWDO attention"><?= $aiAttentionCount ?></span><?php endif; ?>
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

  <?php foreach ($requests as $request): ?>
    <?php
      $requestId = (int) ($request['id'] ?? 0);
      if ($requestId <= 0) {
          continue;
      }
      $statusCode = (string) ($request['status_code'] ?? 'pending_review');
      $canMarkInProgress = !in_array($statusCode, $terminalStatusCodes, true);
      $isAlreadyInProgress = in_array($statusCode, ['approved', 'under_review'], true);
      $isIncompleteRequirements = $statusCode === 'incomplete_requirements';
      $isInvalidRequirements = false;
      $canMarkInvalid = false;
      $applicantLocationParts = array_filter([
          trim((string) ($request['sitio_purok'] ?? '')),
          trim((string) ($request['barangay'] ?? '')),
          trim((string) ($request['municipality'] ?? '')),
          trim((string) ($request['province'] ?? '')),
      ]);
      $applicantLocationLabel = count($applicantLocationParts) > 0 ? implode(', ', $applicantLocationParts) : 'Not available';
      $beneficiaryLocationParts = array_filter([
          trim((string) ($request['beneficiary_sitio_purok'] ?? '')),
          trim((string) ($request['beneficiary_barangay'] ?? '')),
          trim((string) ($request['beneficiary_municipality'] ?? '')),
          trim((string) ($request['beneficiary_province'] ?? '')),
      ]);
      // Older requests can lack beneficiary components when the applicant
      // chose the same address. Reuse the actual stored applicant components
      // only in that empty-beneficiary case; never invent address values.
      $beneficiaryUsesApplicantAddress = count($beneficiaryLocationParts) === 0 && count($applicantLocationParts) > 0;
      $beneficiaryLocationLabel = count($beneficiaryLocationParts) > 0
          ? implode(', ', $beneficiaryLocationParts)
          : ($beneficiaryUsesApplicantAddress ? $applicantLocationLabel : 'Address not available');
      $applicantDisplayName = trim((string) ($request['applicant_submitted_name'] ?? ''));
      if ($applicantDisplayName === '') {
          $applicantDisplayName = trim((string) ($request['applicant_name'] ?? ''));
      }
      $genderLabel = trim((string) ($request['gender_label'] ?? ''));
      $clientCategoryLabel = trim((string) ($request['client_categories_label'] ?? ''));
      $beneficiaryAge = (int) ($request['beneficiary_age'] ?? 0);
      $beneficiaryBirthDate = trim((string) ($request['beneficiary_birth_date'] ?? ''));
      $beneficiaryBirthDateLabel = $beneficiaryBirthDate !== '' ? pswdo_date_label($beneficiaryBirthDate) : 'Not available';
      $classificationAge = $beneficiaryAge;
      if ($beneficiaryBirthDate !== '') {
          try {
              $dob = new DateTimeImmutable($beneficiaryBirthDate);
              $ageFromDob = $dob->diff(new DateTimeImmutable('today'))->y;
              if ($ageFromDob >= 0) $classificationAge = $ageFromDob;
          } catch (Throwable) {
              // Retain the stored age when a legacy DOB cannot be parsed.
          }
      }
      $beneficiaryCategories = json_decode((string) ($request['client_categories_json'] ?? '[]'), true);
      if (!is_array($beneficiaryCategories)) $beneficiaryCategories = [];
      $normalizedCategories = array_map(static fn ($value): string => strtolower(trim((string) $value)), $beneficiaryCategories);
      $hasSeniorCategory = in_array('senior_citizen', $normalizedCategories, true);
      $storedCategory = (string) ($normalizedCategories[0] ?? '');
      $categoryAgeError = $classificationAge > 0 ? aics_beneficiary_category_age_error($classificationAge, $storedCategory) : null;
      $classificationReason = $categoryAgeError !== null
          ? 'Beneficiary is ' . $classificationAge . ' years old but the recorded category is ' . ($clientCategoryLabel !== '' ? $clientCategoryLabel : 'not specified') . '. ' . $categoryAgeError
          : null;
      $classificationWarning = $classificationReason !== null;
      $priorityUrgency = (string) ($request['priority']['urgency_indicator'] ?? 'REQUIRES_STAFF_REVIEW');
      $priorityIndicator = pswdo_request_management_indicator((string) ($request['client_categories_json'] ?? '[]'), $priorityUrgency, (string) ($request['assistance_code'] ?? ''));
      $priorityEta = aics_ai_gate_eta_summary($priorityIndicator['label'], (string) ($request['gate_received_at'] ?? ''));
      $priorityUrgencyLabel = aics_normalize_medical_urgency($priorityUrgency) === 'REQUIRES_STAFF_REVIEW'
          ? 'Pending verification'
          : pswdo_medical_urgency_label($priorityUrgency);
      $priorityCategoryLabel = $hasSeniorCategory ? 'Senior Citizen' : (in_array('pwd', $normalizedCategories, true) ? 'PWD' : 'Other');
      $priorityAssistanceLabel = strtolower(trim((string) ($request['assistance_code'] ?? ''))) === 'medical' ? 'Medical' : (string) ($request['assistance_label'] ?? 'Assistance Request');
      $priorityNeedsEvidenceNote = strtolower(trim((string) ($request['assistance_code'] ?? ''))) === 'medical'
          && strtoupper(trim($priorityUrgency)) === 'REQUIRES_STAFF_REVIEW';
      $priorityBasis = 'Beneficiary Category: ' . $priorityCategoryLabel . ' | Assistance: ' . $priorityAssistanceLabel . ' | Medical Urgency: ' . $priorityUrgencyLabel;
      $storedPriorityBasis = trim((string) ($request['priority']['priority_basis'] ?? ''));
      $priorityExplanation = $storedPriorityBasis !== ''
          ? trim((string) (explode(' | ', $storedPriorityBasis, 3)[2] ?? $storedPriorityBasis))
          : $priorityBasis;
      $priorityExplanation = pswdo_priority_explanation_for_display($priorityExplanation);
      $approvalConfirmMessage = 'Approve this application? The applicant gets an SMS listing their requirements and the PSWDO office hours.';
      $incompleteQueueUrl = $paths['dashboard_url'] . '?page=prescreening&queue_tab=incomplete_requirements';
      $invalidQueueUrl = $paths['dashboard_url'] . '?page=prescreening&queue_tab=invalid_requirements';
    ?>
    <template id="request-detail-source-<?= $requestId ?>">
      <div
        class="request-detail-shell"
        data-status-code="<?= pswdo_h((string) ($request['status_code'] ?? 'pending_review')) ?>"
        data-terminal-locked="<?= in_array($statusCode, $terminalStatusCodes, true) ? '1' : '0' ?>"
        data-document-actions-locked="<?= in_array($statusCode, ['for_release', 'released', 'cancelled', 'not_approved'], true) ? '1' : '0' ?>"
        data-contact-number="<?= pswdo_h(pswdo_applicant_sms_contact($request)) ?>"
        data-reference-no="<?= pswdo_h((string) ($request['reference_no'] ?? '')) ?>"
        data-beneficiary-name="<?= pswdo_h((string) ($request['beneficiary_name'] ?? 'Applicant')) ?>"
        data-applicant-name="<?= pswdo_h($applicantDisplayName !== '' ? $applicantDisplayName : 'Applicant') ?>"
      >
        <div class="request-detail-head">
          <h2><?= pswdo_h((string) ($request['assistance_label'] ?? 'Assistance')) ?> assistance</h2>
          <div class="request-detail-head-pills">
            <span class="pill pill-<?= pswdo_h((string) ($request['status_tone'] ?? 'pending')) ?>">
              <?= pswdo_h((string) ($request['status_label'] ?? 'Pending Review')) ?>
            </span>
          </div>
        </div>

        <?php
          $validationData = is_array($request['validation'] ?? null) ? $request['validation'] : [];
          $validationNoteText = trim((string) ($validationData['validation_note'] ?? ''));
          $duplicateFlagged = (bool) ($validationData['duplicate_flag'] ?? false);
        ?>
        <?php if ($duplicateFlagged): ?>
          <div class="validation-warning" style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;padding:12px 14px;margin:8px 0 14px;display:flex;gap:10px;align-items:flex-start;">
            <i class="fas fa-triangle-exclamation" style="color:#dc2626;font-size:18px;margin-top:2px;flex-shrink:0;"></i>
            <div style="flex:1;">
              <strong style="color:#991b1b;display:block;margin-bottom:2px;">
                Possible Duplicate Detected
              </strong>
              <span style="color:#7f1d1d;font-size:13.5px;"><?= pswdo_h($validationNoteText !== '' ? $validationNoteText : 'A possible duplicate applicant or beneficiary record was detected.') ?></span>
              <div style="color:#7f1d1d;font-size:12px;margin-top:4px;opacity:0.85;">This is a review flag only; PSWDO personnel make the final decision.</div>
            </div>
          </div>
        <?php endif; ?>

        <?php $duplicateMatches = is_array($request['duplicate_matches'] ?? null) ? $request['duplicate_matches'] : []; ?>
        <div class="request-review-summary-grid">
        <div class="request-doc-section priority-indicator-section">
          <h3><i class="fas fa-layer-group"></i> Priority indicator</h3>
          <div class="request-detail-item priority-indicator-card">
            <span>Indicator</span>
            <span class="priority-chip <?= pswdo_h($priorityIndicator['class']) ?>"><?= pswdo_h($priorityIndicator['label']) ?></span>
            <?php if ($priorityEta !== null): ?>
              <span>Expected processing</span>
              <p class="priority-eta-detail<?= $priorityEta['overdue'] ? ' is-overdue' : '' ?>">
                <strong><?= pswdo_h($priorityEta['span']) ?></strong>
                <?= $priorityEta['overdue'] ? '· overdue since' : '· by' ?> <?= pswdo_h($priorityEta['due_label']) ?>
                <small>Office days are Monday to Thursday.</small>
              </p>
            <?php endif; ?>
            <span>Findings / explanation</span>
            <p class="priority-indicator-explanation"><?= pswdo_h($priorityExplanation) ?></p>
            <?php if ($priorityNeedsEvidenceNote): ?><small>Medical document evidence could not be reliably evaluated.</small><?php endif; ?>
          </div>
        </div>
        <div class="request-doc-section duplicate-check-section">
          <h3><i class="fas fa-clone"></i> Duplicate check</h3>
          <div class="request-detail-grid">
            <div class="request-detail-item">
              <span>Status</span>
              <strong><?= $duplicateFlagged ? 'Possible duplicate' : 'No duplicate' ?></strong>
            </div>
            <?php if ($duplicateFlagged || count($duplicateMatches) > 0): ?>
            <div class="request-detail-item">
              <span>Matched by</span>
              <strong><?php
                $basis = [];
                foreach ($duplicateMatches as $match) {
                  $basis[] = !empty($match['applicant_match']) && !empty($match['beneficiary_match']) ? 'Applicant + Beneficiary' : (!empty($match['applicant_match']) ? 'Applicant' : 'Beneficiary');
                }
                $basisLabel = count($basis) > 0 ? implode(', ', array_values(array_unique($basis))) : 'No basis found';
                echo pswdo_h($basisLabel);
              ?></strong>
            </div>
            <?php endif; ?>
          </div>
          <?php if (count($duplicateMatches) > 0): ?>
            <div class="table-wrap" style="margin-top:12px;">
              <table class="modal-doc-table">
                <thead><tr><th>Matching Reference</th><th>Previous Date</th><th>Assistance Type</th><th>Previous Status</th><th>Action</th></tr></thead>
                <tbody>
                  <?php foreach ($duplicateMatches as $match): ?>
                    <?php
                      $matchId = (int) ($match['request_id'] ?? 0);
                      $matchCode = pswdo_extract_first_assistance_from_json((string) ($match['assistance_types_json'] ?? '[]'));
                      $matchStatus = pswdo_normalize_status_code((string) ($match['request_status'] ?? 'pending'));
                    ?>
                    <tr>
                      <td><?= pswdo_h((string) ($match['reference_no'] ?? '-')) ?></td>
                      <td><?= pswdo_h(pswdo_date_label((string) ($match['date_applied'] ?? ''))) ?></td>
                      <td><?= pswdo_h(pswdo_assistance_label($matchCode)) ?></td>
                      <td><?= pswdo_h(pswdo_status_labels()[$matchStatus] ?? 'Pending Review') ?></td>
                      <td><?php if ($matchId > 0): ?><a class="btn-outline btn-doc-view" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests&amp;reopen_request=<?= $matchId ?>"><i class="fas fa-eye"></i> View</a><?php else: ?>Not available<?php endif; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        </div>

        <div class="request-action-source js-request-action-source" hidden>
          <?php if ($canMarkInProgress): ?>
            <form method="post" action="workflow-action.php" class="inline-form js-confirm-action"
              data-title="Approve Application?"
              data-message="<?= pswdo_h($approvalConfirmMessage) ?>">
              <input type="hidden" name="csrf_token" value="<?= pswdo_h($csrfToken) ?>">
              <input type="hidden" name="action_type" value="view_request">
              <input type="hidden" name="request_id" value="<?= $requestId ?>">
              <button type="submit" class="btn-solid">
                <i class="fas fa-clipboard-check"></i>
                Approve
              </button>
            </form>
          <?php endif; ?>


          <?php if ($isIncompleteRequirements): ?>
            <a href="<?= pswdo_h($incompleteQueueUrl) ?>" class="btn-outline">
              <i class="fas fa-arrow-up-right-from-square"></i> Go to Incomplete Requirements Tab
            </a>
          <?php endif; ?>
          <?php if ($isInvalidRequirements): ?>
            <a href="<?= pswdo_h($invalidQueueUrl) ?>" class="btn-outline">
              <i class="fas fa-arrow-up-right-from-square"></i> Go to Invalid Requirements Tab
            </a>
          <?php endif; ?>
          <?php if ($statusCode === 'approved'): ?>
            <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release&schedule_request=<?= $requestId ?>" class="btn-outline">
              <i class="fas fa-calendar-check"></i> Open Release Scheduling
            </a>
          <?php endif; ?>
          <?php if (!$canMarkInProgress && !$canMarkInvalid && !$isIncompleteRequirements && !$isInvalidRequirements): ?>
            <button type="button" class="btn-outline" disabled><i class="fas fa-lock"></i> Request Is Finalized</button>
          <?php endif; ?>
        </div>

        <div class="request-doc-section application-info-section">
          <h3><i class="fas fa-file-lines"></i> Request</h3>
          <div class="request-detail-grid">
          <div class="request-detail-item">
            <span>Reference</span>
            <strong><?= pswdo_h((string) ($request['reference_no'] ?? '-')) ?></strong>
          </div>
          <div class="request-detail-item">
            <span>Date Applied</span>
            <strong><?= pswdo_h((string) ($request['date_applied_label'] ?? 'Not available')) ?></strong>
          </div>
          <div class="request-detail-item">
            <span>Assistance</span>
            <strong><?= pswdo_h((string) ($request['assistance_label'] ?? 'Not available')) ?></strong>
          </div>
          <div class="request-detail-item">
            <span>Status</span>
            <strong><?= pswdo_h((string) ($request['status_label'] ?? 'Pending Review')) ?></strong>
          </div>
          </div>
          <?php if ($classificationWarning): ?>
            <div class="classification-warning"><i class="fas fa-triangle-exclamation"></i><div><strong>Beneficiary Category Review Needed</strong><span><?= pswdo_h($classificationReason) ?></span></div></div>
          <?php endif; ?>
        </div>

        <div class="request-doc-section beneficiary-info-section">
          <h3><i class="fas fa-user"></i> Beneficiary</h3>
          <div class="request-detail-grid">
          <div class="request-detail-item">
            <span>Name</span>
            <strong><?= pswdo_h((string) ($request['beneficiary_name'] ?? 'Not available')) ?></strong>
          </div>
          <div class="request-detail-item">
            <span>Sex / Gender</span>
            <strong><?= pswdo_h($genderLabel !== '' ? $genderLabel : 'Not available') ?></strong>
          </div>
          <div class="request-detail-item">
            <span>Date of birth</span>
            <strong><?= pswdo_h($beneficiaryBirthDateLabel) ?></strong>
          </div>
          <div class="request-detail-item">
            <span>Age</span>
            <strong><?= $beneficiaryAge > 0 ? $beneficiaryAge . ' years old' : 'Not available' ?></strong>
          </div>
          <div class="request-detail-item">
            <span>Category</span>
            <strong><?= pswdo_h($clientCategoryLabel !== '' ? $clientCategoryLabel : 'None') ?></strong>
          </div>
          <div class="request-detail-item">
            <span>Contact</span>
            <strong><?= pswdo_h(trim((string) ($request['contact_number'] ?? '')) !== '' ? (string) $request['contact_number'] : 'Not available') ?></strong>
          </div>
          <div class="request-detail-item request-detail-item-address">
            <span>Address</span>
            <strong><?= pswdo_h($beneficiaryLocationLabel) ?></strong>
          </div>
          <div class="request-detail-item request-detail-item-income-wide">
            <span>Occupation / Source of Income</span>
            <strong><?= pswdo_h(trim((string) ($request['beneficiary_occupation'] ?? '')) !== '' ? (string) $request['beneficiary_occupation'] : 'Not provided') ?></strong>
          </div>
          <div class="request-detail-item request-detail-item-income-wide">
            <span>Estimated Monthly Income</span>
            <strong><?= pswdo_h(trim((string) ($request['beneficiary_estimated_income'] ?? '')) !== '' ? (string) $request['beneficiary_estimated_income'] : 'Not provided') ?></strong>
          </div>
          </div>
        </div>

        <div class="request-doc-section applicant-info-section">
          <h3><i class="fas fa-user-pen"></i> Applicant</h3>
          <div class="request-detail-grid">
            <div class="request-detail-item"><span>Name</span><strong><?= pswdo_h($applicantDisplayName !== '' ? $applicantDisplayName : 'Not available') ?></strong></div>
            <?php $applicantRelationship = trim((string) ($request['applicant_relationship'] ?? '')); ?>
            <div class="request-detail-item"><span>Relationship to Beneficiary</span><strong><?= pswdo_h($applicantRelationship === 'Self' ? 'Self (same as beneficiary)' : ($applicantRelationship !== '' ? $applicantRelationship : 'Not provided')) ?></strong></div>
            <div class="request-detail-item"><span>Contact</span><strong><?= pswdo_h(trim((string) ($request['applicant_contact_number'] ?? '')) !== '' ? (string) $request['applicant_contact_number'] : 'Not available') ?></strong></div>
            <div class="request-detail-item"><span>Email</span><strong><?= pswdo_h(trim((string) ($request['applicant_email'] ?? '')) !== '' ? (string) $request['applicant_email'] : 'Not available') ?></strong></div>
            <div class="request-detail-item request-detail-item-address"><span>Address</span><strong><?= pswdo_h($applicantLocationLabel) ?></strong></div>
            <div class="request-detail-item request-detail-item-income-wide"><span>Occupation / Source of Income</span><strong><?= pswdo_h(trim((string) ($request['applicant_occupation'] ?? '')) !== '' ? (string) $request['applicant_occupation'] : 'Not provided') ?></strong></div>
            <div class="request-detail-item request-detail-item-income-wide"><span>Estimated Monthly Income</span><strong><?= pswdo_h(trim((string) ($request['applicant_estimated_monthly_income'] ?? '')) !== '' ? (string) $request['applicant_estimated_monthly_income'] : 'Not provided') ?></strong></div>
          </div>
        </div>

        <div class="request-doc-section family-info-section">
          <h3><i class="fas fa-people-roof"></i> Household</h3>
          <?php
            $familyComposition = is_array($request['family_composition'] ?? null) ? $request['family_composition'] : [];
            $familyComposition = array_values(array_filter($familyComposition, static function ($member): bool {
                if (!is_array($member)) return false;
                return trim((string) ($member['name'] ?? '')) !== '';
            }));
            // Older requests have an income per member; new ones have one household total.
            $familyHasMemberIncome = array_filter($familyComposition, static fn ($member): bool => is_array($member) && trim((string) ($member['estimated_monthly_income'] ?? '')) !== '') !== [];
            $familyTotalIncome = trim((string) ($request['family_total_income'] ?? ''));
          ?>
          <?php if (count($familyComposition) > 0): ?>
            <div class="table-wrap family-composition-table-wrap">
              <table class="modal-doc-table family-composition-table">
                <thead><tr><th>Name</th><th>Relationship to Beneficiary</th><th>Age</th><th>Occupation / Source of Income</th><th>Educational Attainment</th><?php if ($familyHasMemberIncome): ?><th>Estimated Monthly Income</th><?php endif; ?></tr></thead>
                <tbody>
                  <?php foreach ($familyComposition as $member): ?>
                    <?php
                      if (!is_array($member)) continue;
                      $memberOccupation = trim((string) ($member['occupation'] ?? ''));
                      $memberOccupationOther = trim((string) ($member['occupation_other'] ?? ''));
                      if ($memberOccupation === 'Other' && $memberOccupationOther !== '') $memberOccupation .= ': ' . $memberOccupationOther;
                    ?>
                    <tr>
                      <td><?= pswdo_h(trim((string) ($member['name'] ?? '')) !== '' ? (string) $member['name'] : 'Not provided') ?></td>
                      <td><?= pswdo_h(trim((string) ($member['relationship'] ?? '')) !== '' ? (string) $member['relationship'] : 'Not provided') ?></td>
                      <td><?= isset($member['age']) && $member['age'] !== null && $member['age'] !== '' ? (int) $member['age'] : 'Not provided' ?></td>
                      <td><?= pswdo_h($memberOccupation !== '' ? $memberOccupation : 'Not provided') ?></td>
                      <td><?= pswdo_h(trim((string) ($member['educational_attainment'] ?? '')) !== '' ? (string) $member['educational_attainment'] : 'Not provided') ?></td>
                      <?php if ($familyHasMemberIncome): ?><td><?= pswdo_h(trim((string) ($member['estimated_monthly_income'] ?? '')) !== '' ? (string) $member['estimated_monthly_income'] : 'Not provided') ?></td><?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
          <div class="request-detail-grid">
            <div class="request-detail-item request-detail-item-income-wide"><span>Total Estimated Monthly Family Income</span><strong><?= pswdo_h($familyTotalIncome !== '' ? $familyTotalIncome : 'Not provided') ?></strong></div>
          </div>
        </div>

        <div class="request-doc-section documents-review-section">
          <h3><i class="fas fa-file-circle-check"></i> Documents</h3>
          <div class="table-wrap">
            <table class="modal-doc-table">
              <thead>
                <tr>
                  <th>Document</th>
                  <th>File</th>
                  <th>Upload</th>
                  <th>AI check</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (($request['required_documents'] ?? []) as $doc): ?>
                  <?php
                    $uploaded = (bool) ($doc['uploaded'] ?? false);
                    $reviewStatus = strtolower(trim((string) ($doc['review_status'] ?? 'pending')));
                    $documentId = (int) ($doc['document_id'] ?? 0);
                    $fileName = trim((string) ($doc['file_name'] ?? 'Uploaded file'));
                    $mimeType = strtolower(trim((string) ($doc['mime_type'] ?? '')));
                    $fileType = match ($mimeType) {
                      'application/pdf' => 'PDF',
                      'image/jpeg', 'image/jpg' => 'JPG',
                      'image/png' => 'PNG',
                      'image/webp' => 'WEBP',
                      default => 'FILE',
                    };
                    $docLabel = (string) ($doc['label'] ?? 'Document');
                    $uploadedAt = (string) ($doc['uploaded_at'] ?? 'Not available');
                    $uploadedDate = $uploadedAt;
                    $uploadedTime = '';
                    if (preg_match('/^(.+?,\s*\d{4})\s+(\d{1,2}:\d{2}\s*[AP]M)$/', $uploadedAt, $uploadedParts) === 1) {
                      $uploadedDate = $uploadedParts[1];
                      $uploadedTime = $uploadedParts[2];
                    }
                    $fileUrl = $uploaded && $documentId > 0
                      ? 'document-view.php?request_id=' . $requestId . '&document_id=' . $documentId
                      : '';
                    $backPhoto = is_array($doc['back_photo'] ?? null) ? $doc['back_photo'] : null;
                    $backDocumentId = (int) ($backPhoto['document_id'] ?? 0);
                    $backFileUrl = $backDocumentId > 0
                      ? 'document-view.php?request_id=' . $requestId . '&document_id=' . $backDocumentId
                      : '';
                  ?>
                  <tr data-document-id="<?= $documentId ?>" data-request-id="<?= $requestId ?>"<?= $reviewStatus === 'optional_not_uploaded' ? ' class="optional-not-uploaded-row"' : '' ?>>
                    <td><?= pswdo_h($docLabel) ?></td>
                    <td>
                      <?php if ($uploaded): ?>
                        <span class="pill pill-neutral" aria-label="<?= pswdo_h($fileType) ?> file"><?= pswdo_h($fileType) ?></span>
                      <?php else: ?>
                        Not uploaded
                      <?php endif; ?>
                    </td>
                    <td><?= $uploaded ? '<span class="pill pill-info">Uploaded</span>' : ($reviewStatus === 'optional_not_uploaded' ? '<span class="pill pill-info">Optional not uploaded</span>' : '<span class="pill pill-warning">Not uploaded</span>') ?></td>
                    <td>
                      <?php if ($uploaded): ?>
                        <?php
                          $aiStatus = strtolower(trim((string) ($doc['ai_status'] ?? '')));
                          $aiFindings = json_decode((string) ($doc['ai_findings'] ?? ''), true);
                          $aiFindings = is_array($aiFindings) ? $aiFindings : [];
                          $aiStatusMeta = pswdo_ai_review_status_meta($aiStatus);
                          $aiQueueWaiting = $aiStatus === 'processing' && (int) ($doc['ai_attempt_count'] ?? 0) === 0;
                          $isAiProcessing = $aiStatus === 'processing';
                          if ($aiStatus === 'processing') $aiStatusMeta = $aiQueueWaiting
                            ? ['label' => 'Waiting for Review', 'class' => 'pill-info', 'summary' => 'Queued for AI document verification.']
                            : ['label' => 'Reviewing Document', 'class' => 'pill-info', 'summary' => 'AI document verification is in progress.'];
                        ?>
                        <?php $verdictView = aics_ai_verdict_view($aiFindings, $aiStatus); $aiSummary = ($verdictView['available'] ?? false) && ($verdictView['source'] ?? '') === 'gemini' ? $verdictView['headline'] . ' — ' . $verdictView['reason'] : pswdo_ai_review_summary($aiStatus, $aiFindings); $headerTamper = is_array($doc['tamper_analysis'] ?? null) ? $doc['tamper_analysis'] : pswdo_tamper_analysis_json((string) ($doc['ai_findings'] ?? '')); $headerTamperStatus = (string) ($headerTamper['status'] ?? 'not_analyzed'); $compactReviewExplanation = pswdo_ai_compact_explanation($aiStatus, $aiFindings, $headerTamper); ?>
                        <div class="ai-verification-cell" data-ai-document-id="<?= $documentId ?>" data-ai-request-id="<?= $requestId ?>" data-ai-status="<?= pswdo_h($aiStatus) ?>">
                          <span class="pill <?= pswdo_h($aiStatusMeta['class']) ?> ai-status-badge"><?= pswdo_h($aiStatusMeta['label']) ?></span>
                          <small class="ai-row-summary ai-status-summary"><?= pswdo_h($aiSummary !== '' ? $aiSummary : $aiStatusMeta['summary']) ?></small>
                          <?php if ($aiStatus !== 'processing' && (is_array($aiFindings) || (int) ($doc['ai_review_id'] ?? 0) > 0)): ?>
                            <button type="button" class="ai-details-toggle" aria-expanded="false" aria-controls="ai-details-<?= $documentId ?>">Details</button>
                            <details class="ai-details-panel" id="ai-details-<?= $documentId ?>">
                              <summary aria-hidden="true">Review details</summary>
                              <div class="ai-details-content">
                                <div class="ai-document-verification-header"><div><h3 class="ai-document-verification-heading">AI Document Verification</h3><p>One staff review workflow for document content and image alteration checks.</p></div>
                                </div>
                                <?php
                                  $tamperAnalysis = is_array($doc['tamper_analysis'] ?? null) ? $doc['tamper_analysis'] : pswdo_tamper_analysis_json('');
                                  $tamperStatus = (string) ($tamperAnalysis['status'] ?? 'not_analyzed');
                                  $tamperDecision = (string) ($tamperAnalysis['decision'] ?? 'clear');
                                  $tamperIndicators = is_array($tamperAnalysis['indicators'] ?? null) ? $tamperAnalysis['indicators'] : [];
                                  $tamperProviders = is_array($tamperAnalysis['providers'] ?? null) ? $tamperAnalysis['providers'] : [];
                                  $tamperAggregate = is_array($tamperAnalysis['aggregate'] ?? null) ? $tamperAnalysis['aggregate'] : [];
                                  $tamperPages = is_array($tamperAnalysis['pages'] ?? null) ? $tamperAnalysis['pages'] : [];
                                  $tamperReviewBasis = (string) ($tamperAnalysis['review_basis'] ?? 'none');
                                  if ($tamperStatus === 'completed' && $tamperDecision === 'review') $alterationDisplay = $tamperReviewBasis === 'deterministic_chronology_conflict' ? 'Document date inconsistency — staff review required' : 'Suspected alteration — staff review required';
                                  elseif ($tamperStatus === 'completed') $alterationDisplay = 'Review Complete';
                                  elseif ($tamperStatus === 'legacy') $alterationDisplay = 'Earlier Review';
                                  elseif ($tamperStatus === 'unavailable' || $aiStatus === 'failed') $alterationDisplay = 'AI Review Unavailable';
                                  elseif ($isAiProcessing) $alterationDisplay = $aiQueueWaiting ? 'Waiting for Review' : 'Reviewing Document';
                                  else $alterationDisplay = 'Not Yet Analyzed';
                                  $alterationMessage = $isAiProcessing
                                    ? ($aiQueueWaiting ? 'This document is waiting for AI image analysis.' : 'AI image analysis is currently in progress.')
                                    : (string) ($tamperAnalysis['message'] ?? 'Tampering analysis not available for this review.');
                                ?>
                                <?php
                                  $effectiveTamper = is_array($doc['ai_image_tamper_detection'] ?? null) ? $doc['ai_image_tamper_detection'] : [];
                                  $rawTamperPages = is_array($effectiveTamper['pages'] ?? null) ? $effectiveTamper['pages'] : [];
                                  $viewerPages = []; $hasArtifactMetadata = false;
                                  foreach ($tamperPages as $tamperPage) {
                                    $pageNumber = (int) ($tamperPage['page'] ?? 0); if ($pageNumber <= 0) continue;
                                    $rawPage = null; foreach ($rawTamperPages as $candidate) if (is_array($candidate) && (int) ($candidate['page'] ?? 0) === $pageNumber) { $rawPage = $candidate; break; }
                                    $artifacts = is_array($rawPage['artifacts'] ?? null) ? $rawPage['artifacts'] : []; if ($artifacts !== []) $hasArtifactMetadata = true;
                                    $viewerPages[] = ['page' => $pageNumber, 'signal' => (($artifacts['signal']['available'] ?? false) === true), 'threshold' => (($artifacts['threshold']['available'] ?? false) === true), 'regionsFound' => (($artifacts['threshold']['regions_found'] ?? false) === true)];
                                  }
                                  foreach ((array) ($tamperAnalysis['original_pages'] ?? []) as $originalPage) {
                                    if (!is_array($originalPage) || (int) ($originalPage['page'] ?? 0) <= 0) continue;
                                    $pageNumber = (int) $originalPage['page']; $found = false;
                                    foreach ($viewerPages as &$viewerPage) {
                                      if ($viewerPage['page'] !== $pageNumber) continue;
                                      $viewerPage['original'] = ($originalPage['available'] ?? false) === true; $found = true; break;
                                    }
                                    unset($viewerPage);
                                    if (!$found) $viewerPages[] = ['page' => $pageNumber, 'original' => ($originalPage['available'] ?? false) === true, 'signal' => false, 'threshold' => false, 'regionsFound' => false];
                                  }
                                  usort($viewerPages, static fn (array $a, array $b): int => $a['page'] <=> $b['page']);
                                  $signalPages = array_filter($viewerPages, static fn (array $page): bool => $page['signal']);
                                  $viewablePages = array_filter($viewerPages, static fn (array $page): bool => ($page['original'] ?? false) || $page['signal']);
                                  $viewerConfig = ['reviewId' => (int) ($doc['ai_review_id'] ?? 0), 'requestId' => $requestId, 'documentId' => $documentId, 'documentUrl' => $fileUrl, 'mimeType' => $mimeType, 'pages' => $viewerPages, 'indicators' => $tamperIndicators, 'decision' => $tamperDecision];
                                ?>
                                <?php $evidenceItems = pswdo_ai_evidence_items($tamperIndicators); $viewerConfig['indicators'] = $evidenceItems; ?>
                                <?php if ($verdictView['available'] ?? false): ?>
                                <?= pswdo_ai_verdict_card_html($verdictView, is_array($compactReviewExplanation['date_facts']) ? $compactReviewExplanation['date_facts'] : null, pswdo_document_label((string) ($doc['code'] ?? ''))) ?>
                                <?php if ($tamperStatus === 'completed' && count($viewablePages) > 0 && (int) ($doc['ai_review_id'] ?? 0) > 0): ?>
                                  <div class="ai-signal-action"><button type="button" class="btn-outline btn-ai-signal js-open-ai-signal-viewer" data-ai-viewer="<?= pswdo_h((string) json_encode($viewerConfig, JSON_UNESCAPED_SLASHES)) ?>"><i class="fas fa-images"></i> View marked areas<?= $evidenceItems !== [] ? ' (' . count($evidenceItems) . ')' : '' ?></button><p class="ai-visualization-unavailable">Red boxes are strong findings; amber boxes are weaker hints to check.</p></div>
                                <?php elseif ($tamperStatus === 'completed'): ?>
                                  <p class="ai-visualization-unavailable">Image review was completed, but the detailed image view is not available.</p>
                                <?php elseif ($tamperStatus === 'legacy'): ?>
                                  <p class="ai-visualization-unavailable">This earlier review does not include the current AI image-detail view.</p>
                                <?php endif; ?>
                                <?php else: ?>
                                <section class="ai-review-explanation is-<?= pswdo_h($compactReviewExplanation['tone']) ?>" aria-label="AI review result">
                                  <div class="ai-review-explanation-head"><p><?= pswdo_h($compactReviewExplanation['kicker']) ?></p><h4><?= pswdo_h($compactReviewExplanation['title']) ?></h4></div>
                                  <p class="ai-review-reason"><?= pswdo_h($compactReviewExplanation['reason']) ?></p>
                                  <p class="ai-review-action"><strong>Staff action:</strong> <?= pswdo_h($compactReviewExplanation['action']) ?></p>
                                </section>
                                <?php endif; ?>
                                <details class="ai-full-verification-details"><summary>Technical details</summary><div class="ai-full-verification-body">
                                <?php if ($evidenceItems !== []): ?>
                                  <div class="ai-alteration-indicators"><h5>Marked areas</h5><ul><?php foreach ($evidenceItems as $indicator): ?><li class="is-<?= pswdo_h($indicator['tone']) ?>"><strong>Page <?= pswdo_h((string) $indicator['page']) ?>: <?= pswdo_h($indicator['label']) ?></strong><span><?= pswdo_h($indicator['reason']) ?></span></li><?php endforeach; ?></ul></div>
                                <?php endif; ?>
                                <?php $forensicFacts = is_array($tamperAnalysis['auxiliary'] ?? null) ? $tamperAnalysis['auxiliary'] : []; $fileFacts = is_array($forensicFacts['file'] ?? null) ? $forensicFacts['file'] : []; $qrFacts = is_array($forensicFacts['qr'] ?? null) ? $forensicFacts['qr'] : []; ?>
                                <?php if ($qrFacts !== []): ?><details class="ai-technical-details"><summary>QR codes</summary><dl class="ai-detail-list"><?php foreach ($qrFacts as $qr): ?><?php if (!is_array($qr)) continue; ?><div><dt>QR code, page <?= pswdo_h((string) ($qr['page'] ?? 1)) ?></dt><dd><?= pswdo_h((string) ($qr['content_preview'] ?? '')) ?> — Decoded only; not authenticated.</dd></div><?php endforeach; ?></dl></details><?php endif; ?>
                                <?php if ($tamperProviders !== []): ?><details class="ai-technical-details"><summary>Checks that ran</summary><dl class="ai-detail-list"><?php foreach ($tamperProviders as $providerName => $provider): ?><?php if (!is_array($provider)) continue; ?><div><dt><?= pswdo_h(pswdo_ai_provider_label((string) $providerName)) ?></dt><dd><?= pswdo_h(pswdo_titleize(str_replace('_', ' ', (string) ($provider['status'] ?? 'unavailable')))) ?></dd></div><?php endforeach; ?></dl></details><?php endif; ?>
                                <?php
                                  $feedbackReviewId = (int) ($doc['ai_review_id'] ?? 0);
                                  $feedbackSuggestion = in_array($aiStatus, ['needs_reupload', 'flagged_inconsistency'], true)
                                    ? 'reupload_recommended'
                                    : ($aiStatus === 'failed' ? 'verification_unavailable' : ($aiStatus === 'likely_valid' ? 'ready_for_staff_review' : 'staff_attention_needed'));
                                ?>
                                <?php if ($feedbackReviewId > 0): ?>
                                  <form method="post" action="workflow-action.php" class="ai-feedback-form">
                                    <input type="hidden" name="csrf_token" value="<?= pswdo_h($csrfToken) ?>">
                                    <input type="hidden" name="action_type" value="record_ai_review_feedback">
                                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                    <input type="hidden" name="ai_review_id" value="<?= $feedbackReviewId ?>">
                                    <input type="hidden" name="ai_suggested_action" value="<?= pswdo_h($feedbackSuggestion) ?>">
                                    <input type="hidden" name="staff_outcome" value="accepted_for_manual_review">
                                    <input type="hidden" name="return_page" value="requests">
                                    <label>Staff feedback
                                      <select name="reason_code" aria-label="Feedback reason">
                                        <option value="STAFF_CONFIRMED">Staff confirmed</option>
                                        <option value="DOCUMENT_TYPE_CORRECTED">Document type corrected</option>
                                        <option value="CONTENT_REQUIRES_REVIEW">Content requires review</option>
                                      </select>
                                    </label>
                                    <div class="ai-feedback-actions">
                                      <button type="submit" name="agreement_state" value="agreed" class="btn-tag btn-feedback-agree"><i class="fas fa-check"></i> Agree</button>
                                      <button type="submit" name="agreement_state" value="overridden" class="btn-tag btn-feedback-override"><i class="fas fa-pen"></i> Override</button>
                                    </div>
                                    <small>Audit feedback only. Staff decisions stay final.</small>
                                  </form>
                                <?php endif; ?>
                                </div></details>
                              </div>
                            </details>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span>-</span>
                      <?php endif; ?>
                    </td>
                    <td class="modal-doc-actions">
                      <div class="modal-doc-action-buttons">
                      <?php if ($uploaded && $fileUrl !== ''): ?>
                        <button
                          type="button"
                          class="btn-outline btn-doc-view js-open-file-preview"
                          data-file-url="<?= pswdo_h($fileUrl) ?>"
                          data-file-name="<?= pswdo_h($fileName !== '' ? $fileName : 'Uploaded file') ?>"
                          data-file-label="<?= pswdo_h($docLabel) ?>"
                          data-file-mime="<?= pswdo_h($mimeType) ?>"
                          data-back-file-url="<?= pswdo_h($backFileUrl) ?>"
                          data-back-file-name="<?= pswdo_h((string) ($backPhoto['file_name'] ?? 'Back of ID')) ?>"
                          data-back-file-mime="<?= pswdo_h((string) ($backPhoto['mime_type'] ?? '')) ?>"
                          data-back-document-id="<?= $backDocumentId ?>"
                          data-request-id="<?= $requestId ?>"
                          data-document-id="<?= $documentId ?>"
                        >
                          <i class="fas fa-eye"></i> File
                        </button>
                      <?php endif; ?>

                      <?php if ($uploaded && $documentId > 0): ?>
                        <button
                          type="button"
                          class="btn-tag btn-tag-muted btn-doc-update js-open-sms-modal"
                          data-sms-action-type="send_document_sms"
                          data-request-id="<?= $requestId ?>"
                          data-reference-no="<?= pswdo_h((string) ($request['reference_no'] ?? '')) ?>"
                          data-beneficiary-name="<?= pswdo_h((string) ($request['beneficiary_name'] ?? 'Applicant')) ?>"
                          data-applicant-name="<?= pswdo_h($applicantDisplayName !== '' ? $applicantDisplayName : 'Applicant') ?>"
                          data-recipient-number="<?= pswdo_h(pswdo_applicant_sms_contact($request)) ?>"
                          data-decision-status="document_review"
                          data-document-id="<?= $documentId ?>"
                          data-document-code="<?= pswdo_h((string) ($doc['code'] ?? '')) ?>"
                          data-document-label="<?= pswdo_h($docLabel) ?>"
                          data-document-review-status="needs_reupload"
                          data-document-reason="Pakipalitan po ang dokumentong ito ayon sa pagsusuri ng PSWDO."
                        >
                          <i class="fas fa-pen-to-square"></i> Review
                        </button>
                      <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </template>
  <?php endforeach; ?>

  <div class="request-details-modal" id="request-details-modal" data-export-base-url="<?= pswdo_h($requestExportUrl) ?>" data-gis-base-url="<?= pswdo_h($paths['php_files_base_url'] . '/print-gis.php') ?>">
    <div class="request-details-card">
      <div class="request-details-header">
        <h3><i class="fas fa-folder-open"></i> Application review</h3>
        <div class="request-details-header-actions">
          <?php if ($isEmbedded && $reopenRequestId > 0): ?>
          <a href="<?= pswdo_h($paths['php_files_base_url'] . '/print-gis.php?request_id=' . $reopenRequestId . '&format=pdf') ?>"
             target="_blank" rel="noopener" class="btn-outline" id="print-gis-btn">
            <i class="fas fa-file-download"></i> Export GIS
          </a>
          <?php endif; ?>
          <button type="button" class="btn-solid danger" id="reject-request-btn"<?= ($isEmbedded && $reopenRequestId > 0) ? '' : ' disabled' ?>>
            <i class="fas fa-circle-xmark"></i> Reject
          </button>
          <?php if (!$isEmbedded): ?>
          <form method="post" action="workflow-action.php" id="approve-screening-form" class="inline-form js-confirm-action"
            data-title="Approve Application?"
            data-message="Approve this application? The applicant gets an SMS listing their requirements and the PSWDO office hours.">
            <input type="hidden" name="csrf_token" value="<?= pswdo_h($csrfToken) ?>">
            <input type="hidden" name="action_type" value="view_request">
            <input type="hidden" name="request_id" id="approve-screening-request-id" value="">
            <input type="hidden" name="return_page" value="requests">
            <button type="submit" class="btn-solid" id="approve-screening-btn" disabled>
              <i class="fas fa-clipboard-check"></i> Approve
            </button>
          </form>
          <?php endif; ?>
          <?php if (!$isEmbedded): ?>
          <button type="button" class="close-details-btn" id="close-request-details-modal"><i class="fas fa-times"></i></button>
          <?php endif; ?>
        </div>
      </div>
      <div class="request-details-body" id="request-details-content">
        <p class="empty-note">Select a request to view details.</p>
      </div>
    </div>
  </div>

  <div class="file-preview-modal" id="file-preview-modal">
    <div class="file-preview-card" role="dialog" aria-modal="true" aria-labelledby="file-preview-title">
      <div class="file-preview-header">
        <h3 id="file-preview-title"><i class="fas fa-file-lines"></i> Document Preview</h3>
        <button type="button" class="close-details-btn" id="close-file-preview-modal" aria-label="Close file preview">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="file-preview-meta">
        <span class="file-preview-label" id="file-preview-label">Document</span>
        <strong class="file-preview-name" id="file-preview-name">-</strong>
      </div>
      <div class="file-preview-body">
        <div class="file-preview-stage" id="file-preview-stage">
          <p class="file-preview-empty">Select a file to preview.</p>
        </div>
      </div>
    </div>
  </div>

  <div class="ai-signal-modal" id="ai-signal-modal" aria-hidden="true">
    <div class="ai-signal-card" role="dialog" aria-modal="true" aria-labelledby="ai-signal-title">
      <div class="file-preview-header"><h3 id="ai-signal-title"><i class="fas fa-images"></i> Marked areas on the document</h3><button type="button" class="close-details-btn" id="close-ai-signal-modal" aria-label="Close AI document image review"><i class="fas fa-times"></i></button></div>
      <div class="ai-signal-toolbar">
        <!-- Model views appear only when the optional deep-learning tamper model produced images. -->
        <div class="ai-signal-tabs" id="ai-signal-tabs" role="tablist" aria-label="AI Document Image Review views" hidden><button type="button" class="ai-signal-tab is-active" role="tab" aria-selected="true" data-ai-view="original">Original</button><button type="button" class="ai-signal-tab" role="tab" aria-selected="false" data-ai-view="signal">AI Signal</button><button type="button" class="ai-signal-tab" role="tab" aria-selected="false" data-ai-view="threshold">Threshold Highlights</button></div>
        <div class="ai-signal-page-controls" id="ai-signal-page-controls" hidden><button type="button" class="btn-outline" id="ai-signal-previous" aria-label="Previous analyzed page"><i class="fas fa-chevron-left" aria-hidden="true"></i> Previous</button><strong id="ai-signal-page-label">Page 1 of 1</strong><button type="button" class="btn-outline" id="ai-signal-next" aria-label="Next analyzed page">Next <i class="fas fa-chevron-right" aria-hidden="true"></i></button></div>
        <p class="ai-signal-legend"><span class="ai-legend-dot is-strong"></span> Strong finding <span class="ai-legend-dot is-hint"></span> Weaker hint &middot; Boxes guide staff to the area; compare with the original document.</p>
      </div>
      <div class="ai-signal-body">
        <div class="ai-signal-stage" id="ai-signal-stage"><p class="ai-signal-loading">Select an AI visualization.</p></div>
        <aside class="ai-evidence-list" id="ai-evidence-list" aria-label="List of marked areas"></aside>
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
        <input type="hidden" name="remarks" id="sms-remarks" value="">
        <input type="hidden" name="return_page" value="requests">

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
          <button type="submit" class="btn-solid" id="sms-compose-submit"><i class="fas fa-paper-plane"></i> Send SMS</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($approvalFeedback !== null): ?>
    <?php
      $feedbackApproved = array_values((array) ($approvalFeedback['approved'] ?? []));
      $feedbackIncomplete = array_values((array) ($approvalFeedback['incomplete'] ?? []));
      $feedbackFailed = array_values((array) ($approvalFeedback['failed'] ?? []));
      $feedbackApprovedCount = count($feedbackApproved);
      $feedbackTitle = match (true) {
          $feedbackApprovedCount === 1 => (string) ($feedbackApproved[0]['name'] ?? 'Applicant') . ' has been accepted',
          $feedbackApprovedCount > 1 => $feedbackApprovedCount . ' applicants have been accepted',
          default => 'No application was accepted',
      };
      $feedbackLead = match (true) {
          $feedbackApprovedCount === 1 => 'The application is now in the Screening Queue.',
          $feedbackApprovedCount > 1 => 'The applications are now in the Screening Queue.',
          default => 'See what needs attention below.',
      };
      $feedbackSmsMissed = count(array_filter($feedbackApproved, static fn ($entry): bool => empty($entry['sms_sent'])));
    ?>
    <div class="approval-feedback-modal open" id="approval-feedback-modal">
      <section class="approval-feedback-card<?= $feedbackApprovedCount === 0 ? ' is-warning' : '' ?>" role="dialog" aria-modal="true" aria-labelledby="approval-feedback-title" aria-describedby="approval-feedback-lead">
        <header class="approval-feedback-head">
          <span class="approval-feedback-seal" aria-hidden="true"><i class="fas <?= $feedbackApprovedCount > 0 ? 'fa-check' : 'fa-exclamation' ?>"></i></span>
          <div class="approval-feedback-heading">
            <h3 id="approval-feedback-title"><?= pswdo_h($feedbackTitle) ?></h3>
            <p id="approval-feedback-lead"><?= pswdo_h($feedbackLead) ?></p>
          </div>
          <button type="button" class="approval-feedback-close" data-close-approval-feedback aria-label="Close"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </header>

        <ul class="approval-feedback-list">
          <?php foreach ($feedbackApproved as $entry): ?>
            <li class="approval-feedback-row">
              <span class="approval-feedback-person">
                <strong><?= pswdo_h((string) ($entry['name'] ?? 'Applicant')) ?></strong>
                <span class="approval-feedback-ref"><?= pswdo_h((string) ($entry['reference'] ?? '')) ?></span>
              </span>
              <?php if (!empty($entry['sms_sent'])): ?>
                <span class="approval-sms-state is-sent"><i class="fas fa-check" aria-hidden="true"></i> SMS sent</span>
              <?php else: ?>
                <span class="approval-sms-state is-unsent"><i class="fas fa-comment-slash" aria-hidden="true"></i> SMS not sent</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
          <?php foreach ($feedbackIncomplete as $entry): ?>
            <li class="approval-feedback-row">
              <span class="approval-feedback-person">
                <strong><?= pswdo_h((string) ($entry['name'] ?? 'Applicant')) ?></strong>
                <span class="approval-feedback-ref"><?= pswdo_h((string) ($entry['reference'] ?? '')) ?></span>
                <span class="approval-feedback-reason">Missing documents, so it moved to Incomplete Requirements.</span>
              </span>
              <span class="approval-sms-state is-incomplete"><i class="fas fa-file-circle-exclamation" aria-hidden="true"></i> Incomplete</span>
            </li>
          <?php endforeach; ?>
          <?php foreach ($feedbackFailed as $entry): ?>
            <li class="approval-feedback-row">
              <span class="approval-feedback-person">
                <strong><?= pswdo_h((string) ($entry['name'] ?? 'Applicant')) ?></strong>
                <span class="approval-feedback-ref"><?= pswdo_h((string) ($entry['reference'] ?? '')) ?></span>
                <span class="approval-feedback-reason is-failed"><?= pswdo_h((string) ($entry['reason'] ?? 'Could not be approved.')) ?></span>
              </span>
              <span class="approval-sms-state is-failed"><i class="fas fa-xmark" aria-hidden="true"></i> Not approved</span>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if ($feedbackApprovedCount > 0): ?>
          <p class="approval-feedback-note">
            <i class="fas fa-calendar-day" aria-hidden="true"></i>
            <span>The SMS asks <?= $feedbackApprovedCount === 1 ? 'the applicant' : 'each applicant' ?> to visit the PSWDO Office with their original requirements, Monday to Thursday, 8:00–11:00 AM or 1:00–4:00 PM.</span>
          </p>
        <?php endif; ?>
        <?php if ($feedbackSmsMissed > 0): ?>
          <p class="approval-feedback-note is-alert">
            <i class="fas fa-phone" aria-hidden="true"></i>
            <span><?= $feedbackSmsMissed === 1 ? 'One SMS could not be sent.' : $feedbackSmsMissed . ' SMS could not be sent.' ?> Call or text <?= $feedbackSmsMissed === 1 ? 'that applicant' : 'those applicants' ?> to tell them they were accepted.</span>
          </p>
        <?php endif; ?>

        <footer class="approval-feedback-actions">
          <?php if ($feedbackApprovedCount > 0): ?>
            <a class="btn-outline" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=prescreening">View Screening Queue</a>
          <?php endif; ?>
          <button type="button" class="btn-solid" id="close-approval-feedback">Done</button>
        </footer>
      </section>
    </div>
  <?php endif; ?>

  <div class="confirm-modal" id="reject-reason-modal">
    <div class="confirm-card">
      <h3><i class="fas fa-circle-xmark"></i> Reject Application?</h3>
      <p>This marks the request as <strong>Not Approved</strong> and texts the applicant the reason you write below. This action cannot be undone.</p>
      <form method="post" action="workflow-action.php" id="reject-reason-form">
        <input type="hidden" name="csrf_token" value="<?= pswdo_h($csrfToken) ?>">
        <input type="hidden" name="action_type" value="reject_request">
        <input type="hidden" name="request_id" id="reject-request-id" value="<?= ($isEmbedded && $reopenRequestId > 0) ? (int) $reopenRequestId : '' ?>">
        <input type="hidden" name="return_page" value="requests">
        <div class="field-wrap" style="margin-top:12px;">
          <label for="reject-reason-text">Reason for Rejection <span aria-hidden="true">*</span></label>
          <textarea id="reject-reason-text" name="remarks" rows="3" maxlength="400" required aria-required="true" placeholder="hal. Hindi tugma ang pangalan sa valid ID at sa medical certificate." style="width:100%;border-radius:8px;border:1px solid #d1d5db;padding:8px 10px;font-size:14px;resize:vertical;"></textarea>
        </div>
        <p class="sms-preview-label"><i class="fas fa-comment-sms" aria-hidden="true"></i> This SMS is sent to the applicant:</p>
        <pre class="sms-preview" id="reject-sms-preview" data-template="<?= pswdo_h(pswdo_rejection_sms_message('{reference}', '{applicant}', '{rejection_reason}')) ?>"></pre>
        <div class="confirm-actions" style="margin-top:12px;">
          <button type="button" class="btn-outline" id="cancel-reject-modal">Cancel</button>
          <button type="submit" class="btn-solid danger"><i class="fas fa-circle-xmark"></i> Confirm Reject</button>
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

  <script src="<?= pswdo_h($paths['ai_request_poller_js_url']) ?>"></script>
  <script src="<?= pswdo_h($paths['js_url']) ?>"></script>
  <script>
    // Keep the fundamental View action available if a future page-bundle
    // error occurs. It deliberately has no dependency on AI health.
    (function () {
      function openFallback(button) {
        if (window.__aicsRequestModalHandlerAvailable) return;
        var requestId = String(button.getAttribute('data-request-id') || '').replace(/^\s+|\s+$/g, '');
        if (!/^\d+$/.test(requestId)) return;
        var template = document.getElementById('request-detail-source-' + requestId);
        var modal = document.getElementById('request-details-modal');
        var content = document.getElementById('request-details-content');
        if (!template || !template.content || !modal || !content) return;
        while (content.firstChild) content.removeChild(content.firstChild);
        content.appendChild(template.content.cloneNode(true));
        modal.classList.add('open');
        document.body.classList.add('modal-open');
        var endpoint = String(document.body.getAttribute('data-ai-ensure-request-url') || '');
        var csrf = document.querySelector('input[name="csrf_token"]');
        if (!endpoint || !csrf || !window.fetch || !window.FormData) return;
        var body = new FormData();
        body.set('csrf_token', csrf.value); body.set('request_id', requestId);
        window.fetch(endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, body: body }).catch(function () {});
      }
      document.addEventListener('click', function (event) {
        var node = event.target;
        while (node && node !== document && !(node.classList && node.classList.contains('js-open-request-modal'))) node = node.parentNode;
        if (node && node !== document) openFallback(node);
      });
      var close = document.getElementById('close-request-details-modal');
      if (close) close.addEventListener('click', function () {
        var modal = document.getElementById('request-details-modal');
        if (modal) modal.classList.remove('open');
        document.body.classList.remove('modal-open');
      });
    }());
  </script>

  <?php if ($isEmbedded): ?>
    <script>
      // When loaded inside an iframe (embed=1), keep the embed flag on every POST
      // and notify the parent window when an action completes successfully.
      (function () {
        // Inject hidden embed=1 input into every POST form so workflow-action preserves it.
        document.querySelectorAll('form[method="post" i]').forEach(function (form) {
          if (form.querySelector('input[name="embed"]')) return;
          var inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = 'embed';
          inp.value = '1';
          form.appendChild(inp);
        });

        // If the page loaded with a status flash, notify the parent so it can refresh.
        var params = new URLSearchParams(window.location.search);
        var status = params.get('status');
        var message = params.get('message') || '';
        if (status && window.parent && window.parent !== window) {
          try {
            window.parent.postMessage({
              type: 'aics-workflow-complete',
              status: status,
              message: message,
            }, '*');
          } catch (e) { /* parent message failed; ignore */ }
        }
      })();
    </script>
  <?php endif; ?>
</body>
</html>
