<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/app-shell.php';
require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/../../includes/document-requirements.php';

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
$docsJsUrl = $applicantsBaseUrl . '/js files/documents.js';
$uploadUrl = $phpFilesBaseUrl . '/documents-upload.php';
$requestExportUrl = $phpFilesBaseUrl . '/request-export.php';
$logoutUrl = $phpFilesBaseUrl . '/auth-logout.php';

$documentLabels = [];
foreach (aics_document_requirements() as $requirements) foreach ($requirements as $requirement) $documentLabels[$requirement['code']] = $requirement['label'];
$documentLabels['valid_id_back'] = aics_document_requirement_label('valid_id_back');
$documentLabels += ['certificate_of_indigency' => 'Certificate of Indigency'];

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

$requiredByAssistance = array_map(static fn (array $requirements): array => array_map(static fn (array $requirement): string => $requirement['code'], $requirements), aics_document_requirements());

$requests = [];
$assistanceFilters = [];

$uploadStatus = (string) ($_GET['upload'] ?? '');
$uploadMessage = trim((string) ($_GET['message'] ?? ''));

try {
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    if ($accountId > 0) {
        $connection = aics_db_connect();
        aics_ensure_auth_schema($connection);

        $connection->query("ALTER TABLE aics_request_documents ADD COLUMN IF NOT EXISTS document_type VARCHAR(60) DEFAULT NULL AFTER request_id");
        $connection->query("CREATE INDEX IF NOT EXISTS idx_aics_request_documents_type ON aics_request_documents (document_type)");

        $requestsSql = <<<SQL
            SELECT r.id, r.reference_no, r.assistance_types_json, r.created_at
            FROM aics_requests r
            INNER JOIN aics_account_requests ar ON ar.request_id = r.id
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

            $assistanceType = '';
            $decoded = json_decode((string) ($row['assistance_types_json'] ?? '[]'), true);
            if (is_array($decoded) && count($decoded) > 0) {
                $assistanceType = strtolower(trim((string) $decoded[0]));
            }

            $createdLabel = '';
            if (!empty($row['created_at'])) {
                try {
                    $createdLabel = (new DateTimeImmutable((string) $row['created_at']))->format('F d, Y');
                } catch (Throwable $e) {
                    $createdLabel = (string) $row['created_at'];
                }
            }

            $requests[$requestId] = [
                'id' => $requestId,
                'reference_no' => (string) ($row['reference_no'] ?? ''),
                'assistance_type' => $assistanceType,
                'assistance_label' => $assistanceLabels[$assistanceType] ?? ($assistanceType !== '' ? ucwords(str_replace('_', ' ', $assistanceType)) : 'Not set'),
                'assistance_icon' => $assistanceIcons[$assistanceType] ?? 'hand-holding-heart',
                'created_label' => $createdLabel,
                'documents' => [],
            ];

            if ($assistanceType !== '' && !isset($assistanceFilters[$assistanceType])) {
                $assistanceFilters[$assistanceType] = $requests[$requestId]['assistance_label'];
            }
        }
        $requestsStmt->close();

        if (count($requestIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
            $docsSql = "SELECT id, request_id, document_type, original_name, file_path, mime_type, file_size, uploaded_at FROM aics_request_documents WHERE request_id IN ($placeholders) ORDER BY uploaded_at DESC";
            $docsStmt = $connection->prepare($docsSql);

            $bindTypes = str_repeat('i', count($requestIds));
            $bindParams = [$bindTypes];
            foreach ($requestIds as $idx => $value) {
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

        $connection->close();
    }
} catch (Throwable $exception) {
    if ($uploadStatus === '') {
        $uploadStatus = 'error';
        $uploadMessage = 'Unable to load your documents right now.';
    }
}

$totalRequests = count($requests);
$totalUploadedFiles = 0;
$totalRequiredFiles = 0;
$totalRequiredUploadedFiles = 0;
foreach ($requests as $requestId => $req) {
    $requiredForType = $requiredByAssistance[$req['assistance_type']] ?? [];
    $uploadedTypes = array_keys($req['documents']);

    $requiredUploadedCount = 0;
    $missingTypes = [];
    foreach ($requiredForType as $requiredType) {
        if (isset($req['documents'][$requiredType])) {
            $requiredUploadedCount++;
            continue;
        }
        $missingTypes[] = $requiredType;
    }

    // Show uploaded documents with expected document types first.
    $displayTypes = [];
    foreach ($requiredForType as $requiredType) {
        if (isset($req['documents'][$requiredType])) {
            $displayTypes[] = $requiredType;
        }
    }
    foreach ($uploadedTypes as $uploadedType) {
        if (!in_array($uploadedType, $displayTypes, true)) {
            $displayTypes[] = $uploadedType;
        }
    }

    $uploadedCount = count($uploadedTypes);
    $requiredCount = count($requiredForType);
    $progressPct = $requiredCount > 0
        ? (int) round(($requiredUploadedCount / $requiredCount) * 100)
        : ($uploadedCount > 0 ? 100 : 0);

    $requests[$requestId]['required_types'] = $requiredForType;
    $requests[$requestId]['missing_types'] = $missingTypes;
    $requests[$requestId]['display_types'] = $displayTypes;
    $requests[$requestId]['uploaded_count'] = $uploadedCount;
    $requests[$requestId]['required_count'] = $requiredCount;
    $requests[$requestId]['required_uploaded_count'] = $requiredUploadedCount;
    $requests[$requestId]['progress_pct'] = $progressPct;

    $totalUploadedFiles += $uploadedCount;
    $totalRequiredFiles += $requiredCount;
    $totalRequiredUploadedFiles += $requiredUploadedCount;
}
$overallPct = $totalRequiredFiles > 0
    ? (int) round(($totalRequiredUploadedFiles / $totalRequiredFiles) * 100)
    : ($totalUploadedFiles > 0 ? 100 : 0);

function docs_fmt_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return number_format($bytes / 1024, 0) . ' KB';
}

function docs_fmt_date(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($raw))->format('F d, Y');
    } catch (Throwable $e) {
        return $raw;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AICS Applicant | Documents</title>
<link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
<link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') ?>">
<?= aics_app_shell_head() ?>
</head>
<body data-account-status="<?= htmlspecialchars($currentAccountStatusCode, ENT_QUOTES, 'UTF-8') ?>" data-banned-modal-required="<?= $forceBannedModal ? '1' : '0' ?>">
<div class="app-container">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=overview" class="logo" style="padding-top: 4px;">
        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="AICS | PSWDO Logo" style="height: 42px; width: auto; object-fit: contain;">
      </a>
      <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
    </div>

    <div class="nav-menu">
      <div class="nav-header">Main Menu</div>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=overview" class="nav-item "><i class="fas fa-border-all"></i> Overview Dashboard</a>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=profile" class="nav-item "><i class="fas fa-user-circle"></i> My Profile</a>

      <div class="nav-header nav-header-gap">Application</div>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=submit" class="nav-item "><i class="fas fa-file-signature"></i> Submit Application</a>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=documents" class="nav-item active"><i class="fas fa-file-invoice"></i> Documents</a>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=status" class="nav-item "><i class="fas fa-route"></i> Application Status</a>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=cancel-request" class="nav-item "><i class="fas fa-pen-to-square"></i> Manage Applications</a>

      <div class="nav-header nav-header-gap">Support</div>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=notifications" class="nav-item "><i class="fas fa-bell"></i> Notifications</a>
    </div>

    <div class="sidebar-footer">
      <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </aside>

  <div class="sidebar-overlay" id="overlay"></div>

  <main class="main-content" id="main-content">
    <header class="top-nav">
      <div class="nav-left">
        <div class="top-nav-page-info">
          <span class="top-nav-badge"><i class="fas fa-file-invoice"></i></span>
          <div class="top-nav-text">
            <span class="top-nav-sub">Application</span>
            <h1 class="top-nav-heading">Documents</h1>
          </div>
        </div>
      </div>

      <div class="nav-right">
        <a class="btn-icon" data-notification-trigger href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=notifications" aria-label="Notifications" aria-expanded="false">
          <i class="fas fa-bell"></i>
          <span class="dot" hidden aria-hidden="true"></span>
        </a>
        <div class="profile-chip">
          <div class="profile-av"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
          <span><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>
    </header>

    <div class="content-area content-area-senior">
      <section class="section-card docs-hero-senior">
        <div class="docs-hero-main">
          <div class="docs-hero-copy">
            <h1 class="page-title">Files You Have Already Submitted</h1>
            <p class="page-sub">This page shows the files submitted with your applications. To replace or update files for an editable application, open <strong>Manage Applications</strong> and choose <strong>Edit Application</strong>.</p>
          </div>
        </div>
      </section>

      <?php if ($uploadStatus !== '' && $uploadMessage !== ''): ?>
        <div class="senior-alert <?= $uploadStatus === 'success' ? 'senior-alert-success' : 'senior-alert-error' ?>">
          <i class="fas fa-<?= $uploadStatus === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
          <span><?= htmlspecialchars($uploadMessage, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      <?php endif; ?>

      <?php if ($totalRequests === 0): ?>
        <section class="section-card docs-empty-card">
          <div class="docs-empty-illustration" aria-hidden="true">
            <i class="fas fa-folder-plus"></i>
          </div>
          <h2>No documents yet</h2>
          <p>You have not submitted a request, so there is nothing to show here. Start a request to upload your documents.</p>
          <a class="btn-senior btn-senior-primary" href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=submit">
            <i class="fas fa-file-signature"></i>
            <span>Start a Request</span>
          </a>
        </section>
      <?php else: ?>

        <section class="section-card docs-progress-senior">
          <div class="docs-progress-head">
            <div class="docs-progress-icon"><i class="fas fa-file-circle-check"></i></div>
            <div class="docs-progress-copy">
              <strong>Overall Documents Ready</strong>
              <?php if ($totalRequiredFiles > 0): ?>
                <span><?= (int) $totalRequiredUploadedFiles ?> of <?= (int) $totalRequiredFiles ?> required files complete (<?= (int) $totalUploadedFiles ?> uploaded file<?= $totalUploadedFiles === 1 ? '' : 's' ?> total)</span>
              <?php else: ?>
                <span><?= (int) $totalUploadedFiles ?> uploaded file<?= $totalUploadedFiles === 1 ? '' : 's' ?> across all your applications</span>
              <?php endif; ?>
            </div>
            <div class="docs-progress-badge">
              <?php if ($overallPct === 100): ?>
                <span class="badge-senior ok"><i class="fas fa-check"></i> All Done</span>
              <?php else: ?>
                <span class="badge-senior pending"><i class="fas fa-clock"></i> <?= (int) $overallPct ?>% Ready</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="progress-track progress-track-senior">
            <span style="width: <?= (int) $overallPct ?>%;"></span>
          </div>
        </section>

        <?php if (count($assistanceFilters) > 1): ?>
          <div class="docs-filter-bar app-scroll-region" role="tablist" aria-label="Filter by assistance type" tabindex="0">
            <span class="docs-filter-label"><i class="fas fa-filter"></i> Show:</span>
            <button type="button" class="docs-filter-chip active" data-filter="all" role="tab" aria-selected="true">
              <i class="fas fa-layer-group"></i>
              <span>All</span>
              <em><?= (int) $totalRequests ?></em>
            </button>
            <?php foreach ($assistanceFilters as $typeKey => $typeLabel): ?>
              <?php
                $icon = $assistanceIcons[$typeKey] ?? 'hand-holding-heart';
                $count = 0;
                foreach ($requests as $r) {
                    if ($r['assistance_type'] === $typeKey) {
                        $count++;
                    }
                }
              ?>
              <button type="button" class="docs-filter-chip" data-filter="<?= htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8') ?>" role="tab" aria-selected="false">
                <i class="fas fa-<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>
                <span><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <em><?= (int) $count ?></em>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="docs-requests-list">
          <?php foreach ($requests as $req): ?>
            <section class="docs-request-block" data-assistance="<?= htmlspecialchars($req['assistance_type'], ENT_QUOTES, 'UTF-8') ?>">
              <header class="docs-request-head">
                <div class="docs-request-title-wrap">
                  <div class="docs-request-icon tone-brand">
                    <i class="fas fa-<?= htmlspecialchars($req['assistance_icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                  </div>
                  <div class="docs-request-title-copy">
                    <h2 class="docs-request-title"><?= htmlspecialchars($req['assistance_label'], ENT_QUOTES, 'UTF-8') ?> Request</h2>
                    <div class="docs-request-meta">
                      <?php if ($req['reference_no'] !== ''): ?>
                        <span><i class="fas fa-hashtag"></i> <?= htmlspecialchars($req['reference_no'], ENT_QUOTES, 'UTF-8') ?></span>
                      <?php endif; ?>
                      <?php if ($req['created_label'] !== ''): ?>
                        <span><i class="fas fa-calendar-day"></i> <?= htmlspecialchars($req['created_label'], ENT_QUOTES, 'UTF-8') ?></span>
                      <?php endif; ?>
                      <span><i class="fas fa-paperclip"></i> <?= (int) ($req['uploaded_count'] ?? 0) ?> uploaded file<?= ((int) ($req['uploaded_count'] ?? 0)) === 1 ? '' : 's' ?></span>
                      <?php if ((int) ($req['required_count'] ?? 0) > 0): ?>
                        <span><i class="fas fa-list-check"></i> <?= (int) ($req['required_uploaded_count'] ?? 0) ?> / <?= (int) ($req['required_count'] ?? 0) ?> required</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <?php if (!((int) ($req['required_count'] ?? 0) > 0 && (int) ($req['progress_pct'] ?? 0) === 100)): ?>
                  <div class="docs-request-status">
                    <?php if ((int) ($req['required_count'] ?? 0) > 0): ?>
                      <span class="badge-senior pending"><i class="fas fa-clock"></i> <?= (int) ($req['progress_pct'] ?? 0) ?>%</span>
                    <?php elseif ((int) ($req['uploaded_count'] ?? 0) > 0): ?>
                      <span class="badge-senior ok"><i class="fas fa-check"></i> Uploaded</span>
                    <?php else: ?>
                      <span class="badge-senior pending"><i class="fas fa-clock"></i> No files</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </header>
              <?php
                $requestId = (int) ($req['id'] ?? 0);
                $requestIdQuery = urlencode((string) $requestId);
                $pdfUrl = $requestExportUrl . '?request_id=' . $requestIdQuery . '&format=pdf';
              ?>
              <?php if ($requestId > 0): ?>
                <div class="docs-card-actions" style="margin: 0 0 12px; justify-content: flex-end;">
                  <button type="button" class="btn-senior btn-senior-primary btn-docs-sm" onclick="aicsDownloadPersonalForm('<?= htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8') ?>', this);">
                    <i class="fas fa-download"></i>
                    <span>Personal Form PDF</span>
                  </button>
                </div>
              <?php endif; ?>

              <div class="docs-gallery-grid">
                <?php if (count($req['display_types'] ?? []) === 0): ?>
                  <article class="docs-card is-missing">
                    <div class="docs-card-preview">
                      <div class="docs-preview-missing">
                        <i class="fas fa-folder-open"></i>
                        <span>No uploaded files yet</span>
                      </div>
                      <span class="docs-card-badge badge-senior pending">
                        <i class="fas fa-circle-exclamation"></i>
                        Needed
                      </span>
                    </div>
                    <div class="docs-card-body">
                      <h3 class="docs-card-title">Upload your requirements</h3>
                      <p class="docs-card-filename missing">
                        <i class="fas fa-circle-exclamation"></i>
                        Submit your files first so they can appear here.
                      </p>
                      <div class="docs-card-actions">
                        <a class="btn-senior btn-senior-primary btn-docs-sm" href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=cancel-request">
                          <i class="fas fa-arrow-right"></i>
                          <span>Manage Applications</span>
                        </a>
                      </div>
                    </div>
                  </article>
                <?php else: ?>
                  <?php foreach (($req['display_types'] ?? []) as $type): ?>
                    <?php
                      $doc = $req['documents'][$type] ?? null;
                      if (!is_array($doc)) {
                          continue;
                      }

                      $label = $documentLabels[$type] ?? ucwords(str_replace('_', ' ', $type));
                      $documentId = (int) ($doc['id'] ?? 0);
                      $documentUrl = $documentId > 0
                          ? $phpFilesBaseUrl . '/document-view.php?request_id=' . rawurlencode((string) ($req['id'] ?? 0)) . '&document_id=' . rawurlencode((string) $documentId)
                          : '';
                      $mimeType = (string) ($doc['mime_type'] ?? '');
                      $originalName = (string) ($doc['original_name'] ?? '');
                      $fileSize = (int) ($doc['file_size'] ?? 0);
                      $uploadedAtLabel = docs_fmt_date((string) ($doc['uploaded_at'] ?? ''));
                      $isImage = str_starts_with($mimeType, 'image/');
                      $isPdf = $mimeType === 'application/pdf';
                      $sizeLabel = docs_fmt_size($fileSize);
                    ?>
                    <article class="docs-card is-uploaded">
                      <div class="docs-card-preview">
                        <?php if ($isImage && $documentUrl !== ''): ?>
                          <a href="<?= htmlspecialchars($documentUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="docs-preview-link">
                            <img src="<?= htmlspecialchars($documentUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            <span class="docs-preview-overlay"><i class="fas fa-magnifying-glass-plus"></i> View</span>
                          </a>
                        <?php elseif ($isPdf): ?>
                          <div class="docs-preview-filetype pdf">
                            <i class="fas fa-file-pdf"></i>
                            <span>PDF Document</span>
                          </div>
                        <?php else: ?>
                          <div class="docs-preview-filetype generic">
                            <i class="fas fa-file-lines"></i>
                            <span>File</span>
                          </div>
                        <?php endif; ?>

                        <span class="docs-card-badge badge-senior ok">
                          <i class="fas fa-circle-check"></i>
                          Uploaded
                        </span>
                      </div>

                      <div class="docs-card-body">
                        <h3 class="docs-card-title"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="docs-card-filename" title="<?= htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') ?>">
                          <i class="fas fa-paperclip"></i>
                          <?= htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="docs-card-meta">
                          <?php if ($uploadedAtLabel !== ''): ?>
                            <span><i class="fas fa-calendar-day"></i> <?= htmlspecialchars($uploadedAtLabel, ENT_QUOTES, 'UTF-8') ?></span>
                          <?php endif; ?>
                          <?php if ($sizeLabel !== ''): ?>
                            <span><i class="fas fa-weight-hanging"></i> <?= htmlspecialchars($sizeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                          <?php endif; ?>
                        </div>
                        <?php if ($documentUrl !== ''): ?>
                          <div class="docs-card-actions">
                            <a class="btn-senior btn-senior-muted btn-docs-sm" href="<?= htmlspecialchars($documentUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                              <i class="fas fa-eye"></i>
                              <span>View</span>
                            </a>
                            <a class="btn-senior btn-senior-muted btn-docs-sm" href="<?= htmlspecialchars($documentUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                              <i class="fas fa-download"></i>
                              <span>Download</span>
                            </a>
                          </div>
                        <?php endif; ?>
                      </div>
                    </article>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <?php if (count($req['missing_types'] ?? []) > 0): ?>
                <?php
                  $missingLabels = [];
                  foreach (($req['missing_types'] ?? []) as $missingType) {
                      $missingLabels[] = $documentLabels[$missingType] ?? ucwords(str_replace('_', ' ', (string) $missingType));
                  }
                ?>
                <p class="docs-card-filename missing" style="margin: 14px 0 0;">
                  <i class="fas fa-circle-exclamation"></i>
                  Missing requirement<?= count($missingLabels) === 1 ? '' : 's' ?>:
                  <?= htmlspecialchars(implode(', ', $missingLabels), ENT_QUOTES, 'UTF-8') ?>.
                  <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=cancel-request">Manage this application</a>
                </p>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        </div>

        <div class="docs-filter-empty" hidden>
          <i class="fas fa-folder-open"></i>
          <strong>No requests of this type.</strong>
          <p>Try a different filter above.</p>
        </div>
      <?php endif; ?>

    </div>
  </main>
</div>

<script src="<?= htmlspecialchars($appJsUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($docsJsUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
  var chips = document.querySelectorAll('.docs-filter-chip');
  var blocks = document.querySelectorAll('.docs-request-block');
  var emptyEl = document.querySelector('.docs-filter-empty');
  if (!chips.length || !blocks.length) return;

  function applyFilter(filter) {
    var visible = 0;
    blocks.forEach(function (block) {
      var match = filter === 'all' || block.getAttribute('data-assistance') === filter;
      block.hidden = !match;
      if (match) visible++;
    });
    if (emptyEl) emptyEl.hidden = visible !== 0;
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      chips.forEach(function (c) {
        c.classList.remove('active');
        c.setAttribute('aria-selected', 'false');
      });
      chip.classList.add('active');
      chip.setAttribute('aria-selected', 'true');
      applyFilter(chip.getAttribute('data-filter') || 'all');
      chip.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
    });
  });
})();

function aicsDownloadPersonalForm(url, btn) {
  if (!url) return;
  if (btn) {
    btn.disabled = true;
  }
  var iframe = document.createElement('iframe');
  // html2canvas needs the iframe to have real layout dimensions to
  // capture content. Position it off-screen instead of hiding it.
  iframe.style.position = 'fixed';
  iframe.style.left = '-99999px';
  iframe.style.top = '0';
  iframe.style.width = '1200px';
  iframe.style.height = '1400px';
  iframe.style.border = '0';
  iframe.style.opacity = '0';
  iframe.style.pointerEvents = 'none';
  iframe.setAttribute('aria-hidden', 'true');
  iframe.src = url;
  document.body.appendChild(iframe);

  var cleanup = function () {
    try { document.body.removeChild(iframe); } catch (e) {}
    if (btn) {
      btn.disabled = false;
    }
  };
  // The PDF page self-closes its own window after save; we just need
  // to give the download time to start before removing the iframe.
  setTimeout(cleanup, 12000);
}
</script>
<?php require __DIR__ . '/../../chatbot/applicant-widget.php'; ?>
<?= aics_app_shell_script() ?>
</body>
</html>
