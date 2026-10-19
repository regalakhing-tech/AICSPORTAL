<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/auth-common.php';
require_once __DIR__ . '/application-rules.php';
require_once __DIR__ . '/application-access.php';
require_once __DIR__ . '/../../includes/document-requirements.php';
require_once __DIR__ . '/../../includes/applicant-id-verification.php';

aics_start_session();
$assetCssVersion = (string) (filemtime(__DIR__ . '/../css files/applicant-pages.css') ?: time());
$appJsPath = __DIR__ . '/../js files/applicant-pages.js';
// A content hash prevents a browser from reusing an older modal implementation
// when rapid deployments preserve the file timestamp.
$assetJsVersion = (string) (is_file($appJsPath) ? md5_file($appJsPath) : time());
$birthdateJsPath = __DIR__ . '/../js files/beneficiary-birthdate.js';
$birthdateJsVersion = (string) (is_file($birthdateJsPath) ? md5_file($birthdateJsPath) : time());
$currentAccountStatusCode = aics_current_session_status_code();
$forceBannedModal = aics_should_force_banned_modal();
$displayName = aics_current_session_display_name();
$initials = aics_current_session_initials();
$medicalBurialDocumentRequirements = aics_document_requirements();
$clientDocumentRequirements = [];
foreach ($medicalBurialDocumentRequirements as $assistanceCode => $requirements) {
  $clientDocumentRequirements[$assistanceCode] = array_map(
    static fn (array $requirement): array => [
      'id' => $requirement['code'],
      'label' => $requirement['label'] . ($requirement['required'] ? '' : ' (Optional)'),
      'required' => $requirement['required'],
      'contentClassificationApplicable' => $requirement['content_classification_applicable'],
    ],
    $requirements
  );
}

$editMode = false;
$editNotice = '';
$editError = '';
$editRequestId = (int) ($_GET['edit_request_id'] ?? 0);
$editBootstrap = null;
$readOnlyBootstrap = null;
$profileComplete = true;
$accessState = ['mode' => 'NEW_APPLICATION'];
$activeRequestContext = null;
$profileDefaults = [];
$profileIdDocuments = [];
$newApplicationDate = (new DateTimeImmutable('today', aics_dashboard_timezone()))->format('Y-m-d');
$educationOptions = aics_education_options();
$occupationOptions = aics_occupation_options();
$nonEditableStatuses = [
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
$statusLabels = [
  'pending' => 'Pending Review',
  'pending_review' => 'Pending Review',
  'for_review' => 'Pending Review',
  'for_validation' => 'Pending Review',
  'for validation' => 'Pending Review',
  'under_review' => 'Under Review',
  'for_interview' => 'Interview / Initial Review',
  'approved' => 'Approved',
  'validated' => 'Validated',
  'for_screening' => 'For Screening',
  'for_release' => 'For Release',
  'for release' => 'For Release',
  'incomplete_requirements' => 'Incomplete Requirements',
  'invalid_requirements' => 'Invalid Requirements',
  'not_approved' => 'Not Approved',
  'not approved' => 'Not Approved',
  'released' => 'Released / Completed',
  'distributed' => 'Released / Completed',
  'completed' => 'Released / Completed',
  'cancelled' => 'Cancelled',
  'canceled' => 'Cancelled',
];

$cooldownInfo = [
  'last_date'      => '',
  'eligible_on'    => '',
  'days_remaining' => 0,
  'is_in_cooldown' => false,
  'cooldown_years' => (int) AICS_APPLICATION_COOLDOWN_YEARS,
];

if ($editRequestId <= 0) {
  try {
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    if ($accountId > 0) {
      $connection = aics_db_connect();
      aics_ensure_auth_schema($connection);
      aics_ensure_draft_schema($connection);
      $accessState = aics_applicant_application_access_state($connection, $accountId);
      $profileComplete = (bool) ($accessState['profile_complete'] ?? false);
      $cooldownInfo = is_array($accessState['cooldown'] ?? null)
        ? $accessState['cooldown']
        : $cooldownInfo;
      if (!$profileComplete) {
        $connection->close();
        header('Location: ../dashboard.php?page=profile&profile_notice=incomplete', true, 302);
        exit;
      }
      $profileStmt = $connection->prepare('SELECT p.first_name, p.middle_name, p.last_name, p.extension_name, p.date_of_birth, p.civil_status, p.civil_status_other, p.highest_educational_attainment, p.contact_number, p.occupation, p.occupation_other, p.estimated_monthly_income, p.province, p.municipality, p.barangay, p.address_line, a.email FROM aics_applicant_profiles p INNER JOIN aics_accounts a ON a.id = p.account_id WHERE p.account_id = ? LIMIT 1');
      $profileStmt->bind_param('i', $accountId);
      $profileStmt->execute();
      $profileDefaults = $profileStmt->get_result()?->fetch_assoc() ?: [];
      $profileStmt->close();
      $profileDefaults['estimated_monthly_income'] = aics_income_value($profileDefaults['estimated_monthly_income'] ?? null) ?? '';
      // ID photos saved at sign-up are offered for the applicant ID slots.
      foreach (aics_profile_id_documents($connection, $accountId) as $type => $document) {
        $profileIdDocuments[$type] = [
          'preview_url' => 'profile-id-photo.php?side=' . ($type === 'valid_id' ? 'front' : 'back'),
          'mime_type' => $document['mime_type'],
          'id_type_label' => $document['id_type_label'],
        ];
      }

      // Most recent active (non-terminal) request — for Edit button
      $existingSql = <<<SQL
        SELECT r.*
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        WHERE ar.account_id = ?
          AND LOWER(TRIM(r.request_status)) NOT IN (
            'rejected','denied','not_approved','not approved',
            'cancelled','canceled','released','distributed','completed','closed'
          )
        ORDER BY r.id DESC LIMIT 1
      SQL;
      $existingStmt = $connection->prepare($existingSql);
      $existingStmt->bind_param('i', $accountId);
      $existingStmt->execute();
      $existingRow = $existingStmt->get_result()?->fetch_assoc();
      $existingStmt->close();

      if (is_array($existingRow)) {
        $statusCode = strtolower(trim((string) ($existingRow['request_status'] ?? 'pending')));
        $activeRequestContext = [
          'request_id'  => (int) ($existingRow['id'] ?? 0),
          'reference_no'=> (string) ($existingRow['reference_no'] ?? ''),
          'status_code' => $statusCode,
          'status_label'=> $statusLabels[$statusCode] ?? ucwords(str_replace('_', ' ', $statusCode)),
          'can_edit'    => !in_array($statusCode, $nonEditableStatuses, true),
        ];
        $activeAssistance = json_decode((string) ($existingRow['assistance_types_json'] ?? '[]'), true);
        $activeCategories = json_decode((string) ($existingRow['client_categories_json'] ?? '[]'), true);
        $readOnlyBootstrap = [
          'form_values' => [
            'date_applied'=>(string)($existingRow['date_applied']??''), 'beneficiary_first_name'=>(string)($existingRow['beneficiary_first_name']??''), 'beneficiary_middle_name'=>(string)($existingRow['beneficiary_middle_name']??''), 'beneficiary_last_name'=>(string)($existingRow['beneficiary_last_name']??''), 'beneficiary_extension'=>(string)($existingRow['beneficiary_extension']??''), 'beneficiary_birth_date'=>(string)($existingRow['beneficiary_birth_date']??''), 'beneficiary_age'=>(string)($existingRow['beneficiary_age']??''), 'beneficiary_complete_name'=>(string)($existingRow['beneficiary_complete_name']??''), 'beneficiary_barangay'=>(string)($existingRow['beneficiary_barangay']??''), 'beneficiary_sitio_purok'=>(string)($existingRow['beneficiary_sitio_purok']??''), 'beneficiary_municipality'=>(string)($existingRow['beneficiary_municipality']??''), 'beneficiary_province'=>(string)($existingRow['beneficiary_province']??''), 'contact_number'=>(string)($existingRow['contact_number']??''),
            'gender'=>(string)($existingRow['gender']??''), 'estimated_income'=>aics_income_value($existingRow['estimated_income']??null)??'', 'employment'=>(string)($existingRow['employment']??''), 'problem_presented'=>(string)($existingRow['problem_presented']??''), 'client_category_other'=>(string)($existingRow['client_category_other']??''), 'assistance_type_other'=>(string)($existingRow['assistance_type_other']??''),
            'family_composition'=>json_decode((string)($existingRow['family_composition_json']??'[]'), true), 'assistance_type'=>is_array($activeAssistance) ? (string)($activeAssistance[0]??'') : '', 'client_category'=>is_array($activeCategories) ? array_values($activeCategories) : [],
          ],
        ];
      }

      $connection->close();
    }
  } catch (Throwable $existingRequestError) {
    // Keep form usable when lookup is temporarily unavailable.
  }
}

$assistanceTypeLabels = [
  'medical'            => 'Medical',
  'burial'             => 'Burial',
  'disaster_victims'    => 'Disaster Victims',
  'special_cases'      => 'Special Cases',
  'educational_basic'  => 'Educational Assistance for Basic Education',
  'high_inflation_affected' => 'Persons Affected by High Inflation Rate',
];

if ($editRequestId > 0) {
  try {
    $accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
    if ($accountId <= 0) {
      throw new RuntimeException('Invalid session.');
    }
    $editGrantExpiry = (int) ($_SESSION['aics_edit_request_grants'][(string) $editRequestId] ?? 0);
    if ($editGrantExpiry < time()) {
      throw new RuntimeException('Open the application from Manage Applications before editing it.');
    }

    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    aics_ensure_request_intake_columns($connection);

    // Only the authenticated request owner may provide legacy fallbacks.
    // Current request snapshots remain authoritative in Edit Mode.
    $profileStmt = $connection->prepare('SELECT p.first_name, p.middle_name, p.last_name, p.extension_name, p.date_of_birth, p.civil_status, p.civil_status_other, p.highest_educational_attainment, p.contact_number, p.occupation, p.occupation_other, p.estimated_monthly_income, p.province, p.municipality, p.barangay, p.address_line, a.email FROM aics_applicant_profiles p INNER JOIN aics_accounts a ON a.id = p.account_id WHERE p.account_id = ? LIMIT 1');
    $profileStmt->bind_param('i', $accountId);
    $profileStmt->execute();
    $profileDefaults = $profileStmt->get_result()?->fetch_assoc() ?: [];
    $profileStmt->close();
    $profileDefaults['estimated_monthly_income'] = aics_income_value($profileDefaults['estimated_monthly_income'] ?? null) ?? '';

    $requestSql = <<<SQL
      SELECT
        r.id,
        r.reference_no,
        r.request_status,
        r.date_applied,
        r.beneficiary_first_name,
        r.beneficiary_middle_name,
        r.beneficiary_last_name,
        r.beneficiary_extension,
        r.beneficiary_birth_date,
        r.beneficiary_age,
        r.beneficiary_complete_name,
        r.beneficiary_barangay,
        r.beneficiary_sitio_purok,
        r.beneficiary_municipality,
        r.beneficiary_province,
        r.contact_number,
        r.applicant_first_name,
        r.applicant_middle_name,
        r.applicant_last_name,
        r.applicant_extension,
        r.applicant_birth_date,
        r.applicant_age,
        r.applicant_contact_number,
        r.applicant_email,
        r.applicant_occupation,
        r.applicant_estimated_monthly_income,
        r.applicant_relationship,
        r.family_total_income,
        r.barangay,
        r.sitio_purok,
        r.municipality,
        r.province,
        r.gender,
        r.estimated_income,
        r.employment,
        r.civil_status,
        r.civil_status_other,
        r.highest_educational_attainment,
        r.problem_presented,
        r.client_category_other,
        r.assistance_type_other,
        r.family_composition_json,
        r.client_categories_json,
        r.assistance_types_json,
        r.urgency_response,
        r.urgency_indicator
      FROM aics_requests r
      INNER JOIN aics_account_requests ar ON ar.request_id = r.id
      WHERE ar.account_id = ? AND r.id = ?
      LIMIT 1
    SQL;

    $requestStmt = $connection->prepare($requestSql);
    $requestStmt->bind_param('ii', $accountId, $editRequestId);
    $requestStmt->execute();
    $requestRow = $requestStmt->get_result()?->fetch_assoc();
    $requestStmt->close();

    if (!is_array($requestRow)) {
      throw new RuntimeException('Selected application was not found.');
    }

    $snapshotPresent = false;
    foreach (['applicant_contact_number', 'applicant_email', 'applicant_occupation', 'applicant_estimated_monthly_income'] as $snapshotField) {
      if (array_key_exists($snapshotField, $requestRow) && $requestRow[$snapshotField] !== null && trim((string) $requestRow[$snapshotField]) !== '') {
        $snapshotPresent = true;
        break;
      }
    }
    $requestOrLegacyProfile = static function (string $requestField, string $profileField) use ($requestRow, $profileDefaults, $snapshotPresent): string {
      $requestValue = trim((string) ($requestRow[$requestField] ?? ''));
      if ($requestValue !== '') {
        return $requestValue;
      }
      // A modern request can legitimately have an empty optional field; only
      // historical requests without an Applicant snapshot use profile data.
      return !$snapshotPresent ? trim((string) ($profileDefaults[$profileField] ?? '')) : '';
    };
    $profileOccupation = trim((string) ($profileDefaults['occupation'] ?? ''));
    if ($profileOccupation === 'Other') {
      $profileOccupationOther = trim((string) ($profileDefaults['occupation_other'] ?? ''));
      $profileOccupation = $profileOccupationOther !== '' ? 'Other: ' . $profileOccupationOther : 'Other';
    }
    $profileAge = '';
    $profileBirthDate = trim((string) ($profileDefaults['date_of_birth'] ?? ''));
    $profileBirth = DateTimeImmutable::createFromFormat('!Y-m-d', $profileBirthDate);
    if ($profileBirth && $profileBirth->format('Y-m-d') === $profileBirthDate) {
      $profileAge = (string) $profileBirth->diff(new DateTimeImmutable('today'))->y;
    }

    $status = strtolower(trim((string) ($requestRow['request_status'] ?? 'pending')));
    if (in_array($status, $nonEditableStatuses, true)) {
      throw new RuntimeException('This application can no longer be edited at its current status.');
    }

    $hasDocReviewTable = ($connection->query("SHOW TABLES LIKE 'aics_request_document_reviews'")?->num_rows ?? 0) > 0;
    $docsSql = $hasDocReviewTable
      ? <<<SQL
          SELECT
            d.id,
            d.document_type,
            d.original_name,
            d.mime_type,
            d.file_size,
            d.uploaded_at,
            COALESCE(dr.is_checked, 0) AS is_checked
          FROM aics_request_documents d
          LEFT JOIN aics_request_document_reviews dr ON dr.request_document_id = d.id
          WHERE d.request_id = ?
          ORDER BY d.uploaded_at DESC, d.id DESC
        SQL
      : <<<SQL
          SELECT
            d.id,
            d.document_type,
            d.original_name,
            d.mime_type,
            d.file_size,
            d.uploaded_at,
            0 AS is_checked
          FROM aics_request_documents d
          WHERE d.request_id = ?
          ORDER BY d.uploaded_at DESC, d.id DESC
        SQL;

    $docsStmt = $connection->prepare($docsSql);
    $docsStmt->bind_param('i', $editRequestId);
    $docsStmt->execute();
    $docsResult = $docsStmt->get_result();

    $existingDocsByType = [];
    $lockedDocTypes = [];
    while ($docRow = $docsResult ? $docsResult->fetch_assoc() : null) {
      if (!is_array($docRow)) {
        break;
      }

      $docType = aics_normalize_document_type((string) ($docRow['document_type'] ?? ''));
      if ($docType === '') {
        continue;
      }

      if (!isset($existingDocsByType[$docType])) {
        $existingDocsByType[$docType] = [
          'document_id' => (int) ($docRow['id'] ?? 0),
          'file_name' => (string) ($docRow['original_name'] ?? ''),
          'mime_type' => (string) ($docRow['mime_type'] ?? ''),
          'file_size' => (int) ($docRow['file_size'] ?? 0),
          'uploaded_at' => (string) ($docRow['uploaded_at'] ?? ''),
          'is_checked' => ((int) ($docRow['is_checked'] ?? 0)) === 1,
        ];
      }

      if (((int) ($docRow['is_checked'] ?? 0)) === 1) {
        $lockedDocTypes[] = $docType;
      }
    }
    $docsStmt->close();
    $connection->close();

    $assistanceTypes = json_decode((string) ($requestRow['assistance_types_json'] ?? '[]'), true);
    $clientCategories = json_decode((string) ($requestRow['client_categories_json'] ?? '[]'), true);
    $assistanceType = '';
    if (is_array($assistanceTypes) && count($assistanceTypes) > 0) {
      $assistanceType = strtolower(trim((string) $assistanceTypes[0]));
    }

    $editBootstrap = [
      'edit_mode' => true,
      'request_id' => (int) ($requestRow['id'] ?? 0),
      'reference_no' => (string) ($requestRow['reference_no'] ?? ''),
      'original_assistance_type' => $assistanceType,
      'form_values' => [
        'date_applied' => (string) ($requestRow['date_applied'] ?? ''),
        'beneficiary_first_name' => (string) ($requestRow['beneficiary_first_name'] ?? ''),
        'beneficiary_middle_name' => (string) ($requestRow['beneficiary_middle_name'] ?? ''),
        'beneficiary_last_name' => (string) ($requestRow['beneficiary_last_name'] ?? ''),
        'beneficiary_extension' => (string) ($requestRow['beneficiary_extension'] ?? ''),
        'beneficiary_birth_date' => (string) ($requestRow['beneficiary_birth_date'] ?? ''),
        'beneficiary_age' => (string) ($requestRow['beneficiary_age'] ?? ''),
        'beneficiary_complete_name' => (string) ($requestRow['beneficiary_complete_name'] ?? ''),
        'beneficiary_barangay' => (string) ($requestRow['beneficiary_barangay'] ?? ''),
        'beneficiary_sitio_purok' => (string) ($requestRow['beneficiary_sitio_purok'] ?? ''),
        'beneficiary_municipality' => (string) ($requestRow['beneficiary_municipality'] ?? ''),
        'beneficiary_province' => (string) ($requestRow['beneficiary_province'] ?? ''),
        'contact_number' => (string) ($requestRow['contact_number'] ?? ''),
        'applicant_first_name' => $requestOrLegacyProfile('applicant_first_name', 'first_name'),
        'applicant_middle_name' => $requestOrLegacyProfile('applicant_middle_name', 'middle_name'),
        'applicant_last_name' => $requestOrLegacyProfile('applicant_last_name', 'last_name'),
        'applicant_extension' => $requestOrLegacyProfile('applicant_extension', 'extension_name'),
        'applicant_birth_date' => $requestOrLegacyProfile('applicant_birth_date', 'date_of_birth'),
        'applicant_age' => trim((string) ($requestRow['applicant_age'] ?? '')) !== '' ? (string) $requestRow['applicant_age'] : (!$snapshotPresent ? $profileAge : ''),
        'applicant_contact_number' => $requestOrLegacyProfile('applicant_contact_number', 'contact_number'),
        'applicant_email' => $requestOrLegacyProfile('applicant_email', 'email'),
        'applicant_occupation' => trim((string) ($requestRow['applicant_occupation'] ?? '')) !== '' ? (string) $requestRow['applicant_occupation'] : (!$snapshotPresent ? $profileOccupation : ''),
        'applicant_estimated_monthly_income' => trim((string) ($requestRow['applicant_estimated_monthly_income'] ?? '')) !== '' ? (string) $requestRow['applicant_estimated_monthly_income'] : (!$snapshotPresent ? (aics_income_value($profileDefaults['estimated_monthly_income'] ?? null) ?? '') : ''),
        'applicant_relationship' => (string) ($requestRow['applicant_relationship'] ?? ''),
        'family_total_income' => (string) ($requestRow['family_total_income'] ?? ''),
        'barangay' => $requestOrLegacyProfile('barangay', 'barangay'),
        'sitio_purok' => $requestOrLegacyProfile('sitio_purok', 'address_line'),
        'municipality' => $requestOrLegacyProfile('municipality', 'municipality'),
        'province' => $requestOrLegacyProfile('province', 'province'),
        'gender' => (string) ($requestRow['gender'] ?? ''),
        'estimated_income' => aics_income_value($requestRow['estimated_income'] ?? null) ?? '',
        'employment' => (string) ($requestRow['employment'] ?? ''),
        'civil_status' => $requestOrLegacyProfile('civil_status', 'civil_status'),
        'civil_status_other' => $requestOrLegacyProfile('civil_status_other', 'civil_status_other'),
        'highest_educational_attainment' => $requestOrLegacyProfile('highest_educational_attainment', 'highest_educational_attainment'),
        'problem_presented' => (string) ($requestRow['problem_presented'] ?? ''),
        'client_category_other' => (string) ($requestRow['client_category_other'] ?? ''),
        'assistance_type_other' => (string) ($requestRow['assistance_type_other'] ?? ''),
        'family_composition' => json_decode((string) ($requestRow['family_composition_json'] ?? '[]'), true),
        'assistance_type' => $assistanceType,
        'urgency_response' => (string) ($requestRow['urgency_response'] ?? ''),
        'urgency_indicator' => (string) ($requestRow['urgency_indicator'] ?? ''),
        'client_category' => is_array($clientCategories) ? array_values($clientCategories) : [],
      ],
      'existing_documents' => $existingDocsByType,
      'locked_document_types' => array_values(array_unique($lockedDocTypes)),
    ];

    $editMode = true;
    $editNotice = 'You are editing request ' . (string) ($requestRow['reference_no'] ?? '') . '. Documents already checked by PSWDO are locked.';
  } catch (Throwable $exception) {
    $editMode = false;
    $editBootstrap = null;
    $editError = $exception->getMessage();
  }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>AICS Applicant | <?= $editMode ? 'Edit Request' : 'Submit Application' ?></title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../css files/applicant-pages.css?v=<?= htmlspecialchars($assetCssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>

<body data-account-status="<?= htmlspecialchars($currentAccountStatusCode, ENT_QUOTES, 'UTF-8') ?>" data-banned-modal-required="<?= $forceBannedModal ? '1' : '0' ?>">
  <div class="app-container">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <a href="../dashboard.php?page=overview" class="logo" style="padding-top: 4px;">
          <img src="../../../asset/logo.png" alt="AICS | PSWDO Logo"
            style="height: 42px; width: auto; object-fit: contain;">
        </a>
        <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
      </div>

      <div class="nav-menu">
        <div class="nav-header">Main Menu</div>
        <a href="../dashboard.php?page=overview" class="nav-item "><i class="fas fa-border-all"></i> Overview
          Dashboard</a>
        <a href="../dashboard.php?page=profile" class="nav-item "><i class="fas fa-user-circle"></i> My Profile</a>

        <div class="nav-header nav-header-gap">Application</div>
        <a href="../dashboard.php?page=submit" class="nav-item active"><i class="fas fa-file-signature"></i> <?= $editMode ? 'Edit Request' : 'Submit Application' ?></a>
        <a href="../dashboard.php?page=documents" class="nav-item "><i class="fas fa-file-invoice"></i> Documents</a>
        <a href="../dashboard.php?page=status" class="nav-item "><i class="fas fa-route"></i> Application Status</a>
        <a href="../dashboard.php?page=cancel-request" class="nav-item "><i class="fas fa-pen-to-square"></i> Manage Applications</a>

        <div class="nav-header nav-header-gap">Support</div>
        <a href="../dashboard.php?page=notifications" class="nav-item "><i class="fas fa-bell"></i> Notifications</a>
      </div>

      <div class="sidebar-footer">
        <a href="auth-logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
      </div>
    </aside>

    <div class="sidebar-overlay" id="overlay"></div>

    <main class="main-content" id="main-content">
      <header class="top-nav">
        <div class="nav-left">
          <div class="top-nav-page-info">
            <span class="top-nav-badge"><i class="fas fa-file-signature"></i></span>
            <div class="top-nav-text">
              <span class="top-nav-sub">Application</span>
              <h1 class="top-nav-heading">Submit Application</h1>
            </div>
          </div>
        </div>

        <div class="nav-right">
          <a class="btn-icon" data-notification-trigger href="../dashboard.php?page=notifications" aria-label="Notifications" aria-expanded="false">
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
        <section class="section-card submit-hero-executive">
          <div class="submit-hero-executive-main">
            <div class="submit-hero-badge-row">
              <span class="submit-hero-pill">
                <span class="status-pulse-dot"></span>
                <?= $editMode ? 'Edit Request Mode' : 'Online Intake Portal' ?>
              </span>
              <span class="submit-hero-ref-pill">
                <i class="fas fa-file-shield"></i> <?= $editMode ? 'Updating Record' : 'Step-by-Step Intake' ?>
              </span>
            </div>
            <div class="submit-hero-copy">
              <h1 class="page-title"><?= $editMode ? 'Update General Intake Sheet' : 'General Intake Sheet' ?></h1>
              <p class="page-sub"><?= $editMode
                  ? 'Update the details from your previously submitted intake sheet. Any document already checked by PSWDO stays locked and cannot be replaced.'
                  : 'Please complete the applicant intake details based on the AICS general intake sheet. Fill in each section carefully so PSWDO can review your request faster.' ?></p>
            </div>
          </div>

          <div class="submit-hero-executive-actions">
            <?php if (!$editMode && is_array($activeRequestContext)): ?>
              <a href="../dashboard.php?page=status" class="btn-submit-hero-primary">
                <i class="fas fa-route"></i>
                <span>Track Active Request</span>
              </a>
            <?php endif; ?>
            <a href="../dashboard.php?page=overview" class="btn-submit-hero-secondary">
              <i class="fas fa-border-all"></i>
              <span>Overview Dashboard</span>
            </a>
          </div>
        </section>

        <?php if ($editError !== ''): ?>
          <div class="senior-alert senior-alert-error">
            <i class="fas fa-triangle-exclamation"></i>
            <span><?= htmlspecialchars($editError, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        <?php elseif ($editNotice !== ''): ?>
          <div class="senior-alert senior-alert-success">
            <i class="fas fa-pen-to-square"></i>
            <span><?= htmlspecialchars($editNotice, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        <?php endif; ?>

        <?php if (!$editMode && is_array($activeRequestContext)): ?>
          <div class="active-request-banner-executive" role="status">
            <div class="active-request-icon">
              <i class="fas fa-clock-rotate-left"></i>
            </div>
            <div class="active-request-body">
              <div class="active-request-title-row">
                <strong>Active Application on File</strong>
                <span class="active-request-status-pill"><i class="fas fa-circle-dot"></i> <?= htmlspecialchars((string) ($activeRequestContext['status_label'] ?? 'Pending Review'), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <p class="active-request-sub">
                You have an existing request reference <span class="active-request-ref"><?= htmlspecialchars((string) ($activeRequestContext['reference_no'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span> currently in progress.
              </p>
            </div>
            <div class="active-request-actions">
              <a href="../dashboard.php?page=status" class="btn-active-track">
                <i class="fas fa-route"></i>
                <span>Track Status</span>
              </a>
            </div>
          </div>
        <?php endif; ?>


        <section class="section-card form-card intake-shell intake-shell-senior">
          <div class="intake-progress-head intake-progress-senior">
            <div class="progress-meta progress-meta-step">
              <span class="progress-step-pill">
                <i class="fas fa-flag-checkered"></i>
                <span>Step <strong id="step-count">1</strong> of 5</span>
              </span>
            </div>
            <div class="progress-track progress-track-senior"><span id="progress-fill"></span></div>
          </div>

          <div class="intake-back-row hidden" id="back-btn-row">
            <button type="button" class="btn-senior btn-senior-muted" id="prev-step">
              <i class="fas fa-arrow-left"></i>
              <span>Previous</span>
            </button>
          </div>

          <div class="mobile-step-summary mobile-only" aria-live="polite" aria-atomic="true">
            <span class="mobile-step-count">Step 1 of 5</span>
            <strong class="mobile-step-label">Case Intake</strong>
            <span class="mobile-step-percent">20% complete</span>
          </div>

          <nav class="stepper stepper-senior app-scroll-region" aria-label="Applicant intake steps" tabindex="0">
            <button type="button" class="step-pill step-pill-senior active" data-step-index="0">
              <span class="step-num">1</span>
              <span class="step-label">Case Intake</span>
            </button>
            <button type="button" class="step-pill step-pill-senior" data-step-index="1">
              <span class="step-num">2</span>
              <span class="step-label">Applicant</span>
            </button>
            <button type="button" class="step-pill step-pill-senior" data-step-index="2">
              <span class="step-num">3</span>
              <span class="step-label">Classification</span>
            </button>
            <button type="button" class="step-pill step-pill-senior" data-step-index="3">
              <span class="step-num">4</span>
              <span class="step-label">Assistance</span>
            </button>
            <button type="button" class="step-pill step-pill-senior" data-step-index="4">
              <span class="step-num">5</span>
              <span class="step-label">Review</span>
            </button>
          </nav>

          <?php $applicationMode = $editMode ? 'EDIT_ALLOWED' : (string) ($accessState['mode'] ?? 'NEW_APPLICATION'); ?>
          <form id="aics-intake-form" action="save-request.php" method="post" enctype="multipart/form-data" novalidate data-account-id="<?= (int) ($accountId ?? 0) ?>" data-application-date="<?= htmlspecialchars($newApplicationDate, ENT_QUOTES, 'UTF-8') ?>" data-application-mode="<?= htmlspecialchars($applicationMode, ENT_QUOTES, 'UTF-8') ?>" data-can-recover-draft="<?= (($accessState['can_recover_draft'] ?? false) === true) ? 'true' : 'false' ?>" data-draft-get="draft-get.php" data-draft-save="draft-save.php" data-draft-discard="draft-discard.php" data-draft-document-remove="draft-document-remove.php">
            <?php if ($editMode && is_array($editBootstrap)): ?>
              <input type="hidden" name="edit_mode" value="1">
              <input type="hidden" name="edit_request_id" value="<?= (int) ($editBootstrap['request_id'] ?? 0) ?>">
            <?php endif; ?>
            <input type="hidden" name="draft_id" id="active-draft-id" value="">
            <?php if (!$editMode && !empty($cooldownInfo['is_in_cooldown'])): ?>
              <div class="cooldown-gate-notice cooldown-gate-notice-executive" role="alert">
                <div class="cooldown-gate-icon">
                  <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="cooldown-gate-content">
                  <div class="cooldown-gate-title-row">
                    <h3 class="cooldown-gate-title">Submission Temporarily Unavailable</h3>
                    <span class="cooldown-badge-pill">Annual Cooldown Active</span>
                  </div>
                  <p class="cooldown-gate-desc">
                    You currently have an active or recent AICS request. A one-year cooldown applies between applications. You may submit another request on
                    <strong><?= htmlspecialchars(aics_format_cooldown_date((string) $cooldownInfo['eligible_on']), ENT_QUOTES, 'UTF-8') ?></strong><?php if ((int) $cooldownInfo['days_remaining'] > 0): ?> (<span class="cooldown-days-tag"><?= (int) $cooldownInfo['days_remaining'] ?> day<?= ((int) $cooldownInfo['days_remaining'] === 1 ? '' : 's') ?> remaining</span>)<?php endif; ?>.
                    For urgent concerns, please coordinate directly with the PSWDO office.
                  </p>
                </div>
              </div>
            <?php endif; ?>
            <section class="intake-step active" data-step="0">
              <div class="section-title">Case Intake and Beneficiary Details</div>
              <div class="intake-note-panel">
                <div class="intake-note-icon"><i class="fas fa-circle-info"></i></div>
                <div class="intake-note-copy">
                  <strong>Fill in the beneficiary details exactly as they appear on the supporting documents.</strong>
                  <span>This section follows the intake sheet order so PSWDO can verify your request with fewer corrections.</span>
                </div>
              </div>
              <div class="form-grid beneficiary-details-grid">
                <div class="field field-full same-as-applicant-field">
                  <div class="same-as-applicant-card same-as-applicant-control">
                    <div class="same-as-applicant-left">
                      <div class="same-as-applicant-icon">
                        <i class="fas fa-user-check"></i>
                      </div>
                      <div class="same-as-applicant-text">
                        <label for="applicant-is-beneficiary" class="same-as-applicant-title">Same as Applicant</label>
                        <span class="same-as-applicant-sub">Autofill beneficiary details and address using your applicant profile</span>
                      </div>
                    </div>
                    <div class="same-as-applicant-checkbox-wrap">
                      <input type="checkbox" name="applicant_is_beneficiary" id="applicant-is-beneficiary" value="1">
                    </div>
                  </div>
                </div>
                <div class="field">
                  <label>Beneficiary First Name <span class="required-star">*</span></label>
                  <input type="text" name="beneficiary_first_name" id="beneficiary-first-name" placeholder="e.g. Juan" required autocomplete="off">
                </div>
                <div class="field">
                  <label for="date-applied-display">Date of Application</label>
                  <input type="text" id="date-applied-display" class="application-date-display" placeholder="Application Date" value="<?= $editMode ? '' : htmlspecialchars((new DateTimeImmutable($newApplicationDate, aics_dashboard_timezone()))->format('F j, Y'), ENT_QUOTES, 'UTF-8') ?>" readonly aria-readonly="true">
                  <input type="hidden" name="date_applied" value="<?= $editMode ? '' : htmlspecialchars($newApplicationDate, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="field">
                  <label>Beneficiary Last Name <span class="required-star">*</span></label>
                  <input type="text" name="beneficiary_last_name" id="beneficiary-last-name" placeholder="e.g. Dela Cruz" required autocomplete="off">
                </div>
                <div class="field">
                  <label>Beneficiary Middle Name <span class="field-optional-badge">Optional</span></label>
                  <input type="text" name="beneficiary_middle_name" id="beneficiary-middle-name" placeholder="e.g. Mercado (or leave blank if none)" autocomplete="off">
                </div>
                <div class="field">
                  <label>Extension Name <span class="field-optional-badge">Optional</span></label>
                  <input type="text" name="beneficiary_extension" id="beneficiary-extension" maxlength="20" placeholder="e.g. Jr., Sr., III" autocomplete="off">
                </div>
                <div class="field">
                  <label id="beneficiary-birthdate-label" for="beneficiary-birth-month">Date of Birth <span class="required-star">*</span></label>
                  <div class="beneficiary-birthdate-control" role="group" aria-labelledby="beneficiary-birthdate-label">
                    <input type="text" id="beneficiary-birth-month" inputmode="numeric" maxlength="2" placeholder="MM" aria-label="Birth month" autocomplete="off" required>
                    <span aria-hidden="true">/</span>
                    <input type="text" id="beneficiary-birth-day" inputmode="numeric" maxlength="2" placeholder="DD" aria-label="Birth day" autocomplete="off" required>
                    <span aria-hidden="true">/</span>
                    <input type="text" id="beneficiary-birth-year" inputmode="numeric" maxlength="4" placeholder="YYYY" aria-label="Birth year" autocomplete="off" required>
                    <input type="date" name="beneficiary_birth_date" id="beneficiary-birth-date" class="beneficiary-birthdate-picker" aria-label="Choose birth date from calendar" title="Choose birth date from calendar" required>
                  </div>
                </div>
                <div class="field">
                  <label>Age <span class="required-star">*</span></label>
                  <input type="number" name="beneficiary_age" id="beneficiary-age" min="0" max="120" placeholder="e.g. 45" inputmode="numeric" class="numeric-only" required>
                </div>
                <div class="field">
                  <label>Contact Number <span class="required-star">*</span></label>
                  <input type="tel" name="contact_number" id="beneficiary-contact-number" placeholder="09XXXXXXXXX (11 digits)" pattern="^09\d{9}$" maxlength="11" inputmode="numeric" class="numeric-only" required autocomplete="off">
                </div>
                <div class="field">
                  <label>Present Occupation / Source of Income <span class="required-star">*</span></label>
                  <select name="employment" id="employment-select" required><option value="" selected disabled hidden>Select occupation / source of income</option><?php foreach ($occupationOptions as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                </div>
                <div class="field" id="employment-other-field" hidden>
                  <label>Specify Occupation / Source of Income <span class="required-star">*</span></label>
                  <input type="text" name="employment_other" id="employment-other" maxlength="150" placeholder="e.g. Freelance carpenter, market vendor">
                </div>
                <div class="field">
                  <label>Estimated Monthly Income <span class="required-star">*</span></label>
                  <select name="estimated_income" required><option value="" selected disabled hidden>Select estimated monthly income</option><?php foreach (aics_income_options() as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                </div>
                <div class="field field-full">
                  <label>Complete Name of Beneficiary <span class="required-star">*</span></label>
                  <input type="text" name="beneficiary_complete_name" id="beneficiary-complete-name" placeholder="e.g. Juan Mercado Dela Cruz Jr." required>
                </div>
              </div>
              <div class="form-grid beneficiary-address-grid">
                <div class="field field-full beneficiary-address-heading">
                  <div class="sub-section-header">
                    <div class="sub-section-icon"><i class="fas fa-location-dot"></i></div>
                    <div class="sub-section-copy">
                      <strong>Beneficiary Address</strong>
                      <small class="field-help">This is the beneficiary's actual residence in Antique, not the applicant's profile address.</small>
                    </div>
                  </div>
                </div>
                <div class="field">
                  <label>Province <span class="required-star">*</span></label>
                  <input type="text" name="beneficiary_province" id="beneficiary-province" value="Antique" placeholder="e.g. Antique" required>
                </div>
                <div class="field">
                  <label>Municipality <span class="required-star">*</span></label>
                  <select name="beneficiary_municipality" id="municipality-select" required><option value="" selected disabled hidden>Select municipality</option></select>
                </div>
                <div class="field">
                  <label>Barangay <span class="required-star">*</span></label>
                  <select name="beneficiary_barangay" id="barangay-select" required disabled><option value="" selected disabled hidden>Select municipality first</option></select>
                </div>
                <div class="field">
                  <label>House No. / Street / Sitio / Purok <span class="field-optional-badge">Optional</span></label>
                  <input type="text" name="beneficiary_sitio_purok" id="beneficiary-sitio-purok" maxlength="120" placeholder="e.g. House #12, Sitio Riverside, Purok 3">
                </div>
              </div>
            </section>

            <section class="intake-step" data-step="1">
              <div class="section-title">Applicant Information</div>
              <div class="step-layout-grid">
                <div class="step-panel-card">
                  <div class="mini-section-title">Applicant Information</div>
                  <div class="form-grid">
                    <div class="field field-full" id="applicant-relationship-field">
                      <label for="applicant-relationship">Relationship to Beneficiary <span class="required-star">*</span></label>
                      <select name="applicant_relationship" id="applicant-relationship" required>
                        <option value="" selected disabled hidden>Select how you are related to the beneficiary</option>
                        <?php foreach (aics_applicant_relationship_options() as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                      </select>
                      <small class="field-help">Needed because you are applying for another person ("Same as Applicant" is not checked).</small>
                    </div>
                    <div class="field">
                      <label>Applicant First Name <span class="required-star">*</span></label>
                      <input type="text" name="applicant_first_name" id="applicant-first-name" placeholder="e.g. Maria" required readonly class="readonly-field">
                    </div>
                    <div class="field">
                      <label>Applicant Middle Name <span class="field-optional-badge">Optional</span></label>
                      <input type="text" name="applicant_middle_name" id="applicant-middle-name" placeholder="e.g. Santos (optional)" readonly class="readonly-field">
                    </div>
                    <div class="field">
                      <label>Applicant Last Name <span class="required-star">*</span></label>
                      <input type="text" name="applicant_last_name" id="applicant-last-name" placeholder="e.g. Dela Cruz" required readonly class="readonly-field">
                    </div>
                    <div class="field">
                      <label>Extension Name <span class="field-optional-badge">Optional</span></label>
                      <input type="text" name="applicant_extension" id="applicant-extension" maxlength="20" placeholder="e.g. Jr., Sr., III" readonly class="readonly-field">
                    </div>
                    <div class="field">
                      <label>Date of Birth <span class="required-star">*</span></label>
                      <input type="date" name="applicant_birth_date" id="applicant-birth-date" required readonly class="readonly-field">
                    </div>
                    <div class="field">
                      <label>Age <span class="required-star">*</span></label>
                      <input type="number" name="applicant_age" id="applicant-age" min="0" max="120" placeholder="e.g. 42" inputmode="numeric" class="numeric-only" required readonly>
                      <p id="applicant-age-warning" role="alert" style="display:none;margin:6px 0 0;font-size:12.5px;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:8px 10px;line-height:1.45;">
                        <i class="fas fa-triangle-exclamation"></i>
                        Applicant should be of legal age (18 and above).
                      </p>
                    </div>
                    <div class="field">
                      <label>Civil Status <span class="required-star">*</span></label>
                      <select name="civil_status" id="civil-status-select" required disabled>
                        <option value="" selected disabled hidden>Select civil status</option>
                        <option value="single">Single</option>
                        <option value="married">Married</option>
                        <option value="widowed">Widowed</option>
                        <option value="separated">Separated</option>
                        <option value="annulled">Annulled</option>
                        <option value="divorced">Divorced</option>
                      </select>
                    </div>
                    <div class="field">
                      <label>Highest Educational Attainment <span class="required-star">*</span></label>
                      <select name="highest_educational_attainment" required disabled><option value="" selected disabled hidden>Select educational attainment</option><?php foreach ($educationOptions as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="field">
                      <label>Present Occupation / Source of Income <span class="required-star">*</span></label>
                      <input type="text" name="applicant_occupation" id="applicant-occupation" placeholder="e.g. Self-employed" readonly class="readonly-field">
                    </div>
                    <div class="field">
                      <label>Estimated Monthly Income <span class="required-star">*</span></label>
                      <input type="text" name="applicant_estimated_monthly_income" id="applicant-estimated-income" placeholder="e.g. ₱10,000 – ₱19,999" readonly class="readonly-field">
                    </div>
                    <div class="field">
                      <label>Applicant Contact Number <span class="required-star">*</span></label>
                      <input type="tel" name="applicant_contact_number" id="applicant-contact-number" placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" class="numeric-only" readonly class="readonly-field">
                    </div>
                    <div class="field">
                      <label>Applicant Email <span class="field-optional-badge">Optional</span></label>
                      <input type="email" name="applicant_email" id="applicant-email" placeholder="e.g. applicant@email.com" readonly class="readonly-field">
                    </div>
                  </div>
                </div>

                <div class="step-panel-card">
                  <div class="mini-section-title">Complete Address</div>
                  <div class="form-grid">
                    <div class="field">
                      <label for="applicant-address-province">Province</label>
                      <input type="text" id="applicant-address-province" placeholder="e.g. Antique" readonly class="readonly-field" aria-readonly="true">
                    </div>
                    <div class="field">
                      <label for="applicant-address-municipality">Municipality</label>
                      <input type="text" id="applicant-address-municipality" placeholder="e.g. San Jose de Buenavista" readonly class="readonly-field" aria-readonly="true">
                    </div>
                    <div class="field">
                      <label for="applicant-address-barangay">Barangay</label>
                      <input type="text" id="applicant-address-barangay" placeholder="e.g. Funda-Dalipe" readonly class="readonly-field" aria-readonly="true">
                    </div>
                    <div class="field">
                      <label for="applicant-address-line">House No. / Street / Sitio / Purok</label>
                      <input type="text" id="applicant-address-line" placeholder="e.g. House #, Street, Sitio / Purok" readonly class="readonly-field" aria-readonly="true">
                    </div>
                    <div class="field field-full">
                      <label for="applicant-complete-address">Complete Address</label>
                      <input type="text" id="applicant-complete-address" placeholder="Complete residential address" readonly class="readonly-field" aria-readonly="true">
                      <small class="field-help">To update your address, go to My Profile.</small>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <section class="intake-step" data-step="2">
              <div class="section-title">Beneficiary Classification and Household Profile</div>
              <div class="step-layout-grid">
                <div class="step-panel-card">
                  <div class="mini-section-title">Beneficiary Classification</div>
                  <div class="field-group">
                    <label class="group-label">Sex / Gender</label>
                    <div class="choice-grid two-col">
                      <label class="choice-card">
                        <input type="radio" name="gender" value="male" required>
                        <span><i class="fas fa-mars"></i> Male</span>
                      </label>
                      <label class="choice-card">
                        <input type="radio" name="gender" value="female" required>
                        <span><i class="fas fa-venus"></i> Female</span>
                      </label>
                    </div>
                  </div>

                  <div class="field-group">
                    <label class="group-label">Beneficiary Category</label>
                    <div class="choice-grid" role="radiogroup" aria-label="Beneficiary Category">
                      <label class="choice-card">
                        <input type="radio" name="client_category" value="youth" required>
                        <span><i class="fas fa-child-reaching"></i> Youth</span>
                      </label>
                      <label class="choice-card">
                        <input type="radio" name="client_category" value="senior_citizen">
                        <span><i class="fas fa-user-clock"></i> Senior Citizen</span>
                      </label>
                      <label class="choice-card">
                        <input type="radio" name="client_category" value="pwd">
                        <span><i class="fas fa-wheelchair"></i> PWD</span>
                      </label>
                      <label class="choice-card">
                        <input type="radio" name="client_category" value="pantawid">
                        <span><i class="fas fa-people-roof"></i> Pantawid</span>
                      </label>
                      <label class="choice-card">
                        <input type="radio" name="client_category" value="ips">
                        <span><i class="fas fa-house-user"></i> IPs</span>
                      </label>
                      <label class="choice-card">
                        <input type="radio" name="client_category" value="solo_parent">
                        <span><i class="fas fa-hands-holding-child"></i> Solo Parent</span>
                      </label>
                      <label class="choice-card">
                        <input type="radio" name="client_category" value="others">
                        <span><i class="fas fa-ellipsis"></i> Others</span>
                      </label>
                    </div>
                    <p id="beneficiary-category-age-error" class="field-help" role="alert" hidden style="color:#b91c1c;font-weight:600;margin-top:8px;"></p>
                    <div class="form-grid">
                      <div class="field field-full" id="client-category-other-field">
                        <label>Please specify <span class="required-star">*</span></label>
                        <input type="text" name="client_category_other" id="client-category-other" placeholder="e.g. Displaced worker, OFW family">
                      </div>
                    </div>
                  </div>
                </div>

                <div class="step-panel-card">
                  <div class="mini-section-title">Problem Presented</div>
                  <div class="form-grid">
                    <div class="field field-full">
                      <label>Describe the beneficiary's problem / present need <span class="required-star">*</span></label>
                      <textarea name="problem_presented" rows="7" required placeholder="Explain the beneficiary's current situation, why assistance is needed, medical condition or emergency details, and any urgent concerns."></textarea>
                    </div>
                  </div>
                </div>
              </div>

              <div class="section-title section-title-inline">
                <span>Beneficiary Household / Family Composition <span class="required-mark">*</span></span>
                <span class="family-req-note">At least 1 member required</span>
              </div>
              <div class="family-table-shell">
                <label class="family-not-applicable-control" for="family-members-not-applicable">
                  <input type="checkbox" name="family_members_not_applicable" id="family-members-not-applicable" value="1">
                  <span><strong>Not Applicable</strong><small>I have no family/household member or other applicable person to declare.</small></span>
                </label>
                <div id="family-entry-controls">
                <div class="family-composition-grid" id="family-composition-body">
                  <div class="family-head">Name</div>
                  <div class="family-head">Age</div>
                  <div class="family-head">Relationship to Beneficiary</div>
                  <div class="family-head">Occupation</div>
                  <div class="family-head">Educational Attainment</div>
                  <div class="family-head family-action-head">Action</div>
                  <input type="text" name="family_name[]" placeholder="Full name (e.g. Maria Dela Cruz)" class="family-row-input">
                  <input type="number" name="family_age[]" min="0" max="120" placeholder="Age" inputmode="numeric" class="family-row-input numeric-only">
                  <select name="family_relationship[]" class="family-row-input"><option value="" selected disabled hidden>Relationship</option><option value="Spouse">Spouse</option><option value="Child">Child</option><option value="Parent">Parent</option><option value="Sibling">Sibling</option><option value="Grandparent">Grandparent</option><option value="Grandchild">Grandchild</option><option value="Other Relative">Other Relative</option><option value="Non-relative">Non-relative</option></select>
                  <div class="family-occupation-wrap">
                    <select name="family_occupation[]" class="family-row-input family-occupation-select"><option value="" selected disabled hidden>Select occupation</option><?php foreach ($occupationOptions as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                    <input type="text" name="family_occupation_other[]" placeholder="Specify occupation" class="family-occupation-other" maxlength="150" hidden>
                  </div>
                  <select name="family_education[]" class="family-row-input"><option value="" selected disabled hidden>Select educational attainment</option><?php foreach ($educationOptions as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                  <input type="text" name="family_name[]" placeholder="Full name (e.g. Juan Dela Cruz)" class="family-row-input">
                  <input type="number" name="family_age[]" min="0" max="120" placeholder="Age" inputmode="numeric" class="family-row-input numeric-only">
                  <select name="family_relationship[]" class="family-row-input"><option value="" selected disabled hidden>Relationship</option><option value="Spouse">Spouse</option><option value="Child">Child</option><option value="Parent">Parent</option><option value="Sibling">Sibling</option><option value="Grandparent">Grandparent</option><option value="Grandchild">Grandchild</option><option value="Other Relative">Other Relative</option><option value="Non-relative">Non-relative</option></select>
                  <div class="family-occupation-wrap">
                    <select name="family_occupation[]" class="family-row-input family-occupation-select"><option value="" selected disabled hidden>Select occupation</option><?php foreach ($occupationOptions as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                    <input type="text" name="family_occupation_other[]" placeholder="Specify occupation" class="family-occupation-other" maxlength="150" hidden>
                  </div>
                  <select name="family_education[]" class="family-row-input"><option value="" selected disabled hidden>Select educational attainment</option><?php foreach ($educationOptions as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                </div>
                <div class="family-add-row-wrap">
                  <button type="button" class="family-add-btn" id="family-add-row-btn">
                    <i class="fas fa-plus"></i> Add Member
                  </button>
                </div>
                <div class="field family-total-income-field">
                  <label for="family-total-income">Total Estimated Monthly Family Income <span class="required-star">*</span></label>
                  <select name="family_total_income" id="family-total-income" required>
                    <option value="" selected disabled hidden>Select the total for the whole household</option>
                    <?php foreach (aics_income_options() as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                  </select>
                  <small class="field-help">The combined monthly income of all the household members listed above. Enter one total only, not each member's income.</small>
                </div>
                </div>
              </div>
            </section>

            <section class="intake-step" data-step="3">
              <div class="section-title">Assistance Category and Required Attachments</div>
              <div class="step-panel-card step-panel-assist-full">
                <div class="mini-section-title">Type of Assistance Requested</div>
                <p class="assist-note">Select the category that best matches the client's present need.</p>
                <?php
                  $cooldownActive = !$editMode && !empty($cooldownInfo['is_in_cooldown']);
                  $cooldownDisabledAttr = $cooldownActive ? ' disabled' : '';
                ?>
                <?php if ($cooldownActive): ?>
                  <div class="cooldown-banner" role="alert" style="margin: 0 0 14px 0; padding: 12px 14px; background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #dc2626; border-radius: 10px; color: #991b1b; display: flex; align-items: center; gap: 10px; font-size: 13px; line-height: 1.45;">
                    <i class="fas fa-hourglass-half" style="font-size: 16px; color: #b91c1c;"></i>
                    <div>
                      <strong>Cooldown active until <?= htmlspecialchars(aics_format_cooldown_date((string) $cooldownInfo['eligible_on']), ENT_QUOTES, 'UTF-8') ?>.</strong>
                      You can submit a new application on or after that date.
                      <?php if ((int) $cooldownInfo['days_remaining'] > 0): ?>
                        About <?= (int) $cooldownInfo['days_remaining'] ?> day<?= ((int) $cooldownInfo['days_remaining'] === 1 ? '' : 's') ?> remaining.
                      <?php endif; ?>
                      A one-year cooldown applies between applications regardless of type.
                    </div>
                  </div>
                <?php endif; ?>
                <div class="choice-grid assist-choice-full">
                  <label class="choice-card">
                    <input type="radio" name="assistance_type" value="medical" required<?= $cooldownDisabledAttr ?>>
                    <span><i class="fas fa-notes-medical"></i> Medical Assistance</span>
                  </label>
                  <label class="choice-card">
                    <input type="radio" name="assistance_type" value="burial"<?= $cooldownDisabledAttr ?>>
                    <span><i class="fas fa-cross"></i> Burial Assistance</span>
                  </label>
                  <label class="choice-card">
                    <input type="radio" name="assistance_type" value="disaster_victims"<?= $cooldownDisabledAttr ?>>
                    <span><i class="fas fa-house-crack"></i> Disaster Victims</span>
                  </label>
                  <label class="choice-card">
                    <input type="radio" name="assistance_type" value="educational_basic"<?= $cooldownDisabledAttr ?>><span><i class="fas fa-graduation-cap"></i> Educational Assistance for Basic Education</span>
                  </label>
                  <label class="choice-card">
                    <input type="radio" name="assistance_type" value="high_inflation_affected"<?= $cooldownDisabledAttr ?>><span><i class="fas fa-money-bill-trend-up"></i> Persons Affected by High Inflation Rate</span>
                  </label>
                  <label class="choice-card">
                    <input type="radio" name="assistance_type" value="special_cases"<?= $cooldownDisabledAttr ?>>
                    <span><i class="fas fa-ellipsis"></i> Special Cases</span>
                  </label>
                </div>
                <div class="field field-full" id="assistance-type-other-field" hidden>
                  <label for="assistance-type-other">Specify Special Case <span class="required-star">*</span></label>
                  <input type="text" name="assistance_type_other" id="assistance-type-other" maxlength="150" placeholder="e.g. Dialysis support, chemotherapy, disaster relief">
                </div>
              </div>
            </section>

            <section class="intake-step" data-step="4">
              <div class="section-title">Supporting Documents and Final Review</div>
              <div class="step-panel-card step-panel-docs-full">
                <div class="mini-section-title">Required Documents</div>
                <div id="dynamic-upload-container" class="document-upload-groups">
                  <section class="document-upload-group" aria-labelledby="applicant-documents-title">
                    <h3 class="document-upload-group-title" id="applicant-documents-title">Applicant</h3>
                    <div id="applicant-upload-fields" class="form-grid docs-upload-grid"></div>
                  </section>
                  <section class="document-upload-group" aria-labelledby="beneficiary-documents-title">
                    <h3 class="document-upload-group-title" id="beneficiary-documents-title">Beneficiary</h3>
                    <p class="document-upload-help">Upload one file for each required document. A back photo is not needed.</p>
                    <div id="beneficiary-upload-fields" class="form-grid docs-upload-grid"></div>
                  </section>
                </div>
              </div>
            </section>

            <?php if (!$editMode): ?>
              <p class="submit-feedback submit-feedback-senior<?= !$profileComplete ? ' error' : '' ?>"<?= !$profileComplete ? ' role="alert"' : ' role="note"' ?>><?= !$profileComplete ? 'Please complete your profile before submitting a request.' : 'Your profile information will be used for this application.' ?> <a href="../dashboard.php?page=profile">Go to Profile</a>.</p>
            <?php endif; ?>
            <div class="action-row intake-actions intake-actions-senior">
              <?php if (!$editMode): ?><button type="button" class="discard-draft-action" id="discard-draft-btn" hidden>Discard draft</button><?php endif; ?>
              <button
                type="button"
                class="btn-senior btn-senior-primary intake-next-action"
                id="main-action-btn"
                <?= (!$editMode && !empty($cooldownInfo['is_in_cooldown'])) ? 'disabled title="Cooldown active. You can submit again on ' . htmlspecialchars(aics_format_cooldown_date((string) $cooldownInfo['eligible_on']), ENT_QUOTES, 'UTF-8') . '."' : '' ?><?= (!$editMode && !$profileComplete) ? ' disabled title="Complete your profile before submitting a request."' : '' ?>
              >
                <span id="main-action-label">Next</span>
                <i class="fas fa-arrow-right" id="main-action-icon"></i>
              </button>
            </div>
            <p class="submit-feedback submit-feedback-senior" id="submit-feedback" role="status" aria-live="polite"></p>
          </form>
        </section>

        <div class="help-footer-senior" role="note">
          <div class="help-icon"><i class="fas fa-circle-question"></i></div>
          <div class="help-copy">
            <strong>Need help filling this form?</strong>
            <span>You can ask a family member or your AICS officer. Take your time — your answers are saved as you go.</span>
          </div>
        </div>
      </div>
    </main>
  </div>

  <?php if (!$editMode): ?>
  <div id="draft-resume-panel" class="draft-modal-overlay" hidden style="display: none;" role="dialog" aria-modal="true" aria-labelledby="draft-resume-title" aria-describedby="draft-resume-description">
    <div class="draft-modal-card">
      <h3 id="draft-resume-title">Unfinished application</h3>
      <p id="draft-resume-description">You have an unfinished application. Would you like to continue where you left off?</p>
      <p id="draft-last-saved" class="draft-last-saved" hidden></p>
      <div class="draft-modal-actions">
        <button type="button" class="btn-senior btn-senior-muted" id="draft-start-new">Start New Application</button>
        <button type="button" class="btn-senior btn-senior-primary" id="draft-continue"><i class="fas fa-pen-to-square"></i> Continue Application</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="preview-modal-overlay" id="submit-confirm-modal" style="display: none;">
    <div class="confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="submit-confirm-title">

      <div class="confirm-modal-header">
        <h3 id="submit-confirm-title"><i class="fas fa-clipboard-check"></i> <?= $editMode ? 'Review Changes' : 'Final Review' ?></h3>
        <button type="button" class="close-confirm-btn" id="close-confirm-modal" aria-label="Close final review">
          <i class="fas fa-times" aria-hidden="true"></i>
        </button>
      </div>

      <div class="confirm-modal-body">
        <p class="confirm-modal-desc"><?= $editMode
            ? 'Please verify your updated details before saving. Checked documents are locked by PSWDO and cannot be edited.'
            : 'Please verify your application details before submitting. Once submitted, this cannot be edited.' ?></p>

        <div class="confirm-review-sections">
          <section class="confirm-review-section confirm-review-section-primary">
            <h4><i class="fas fa-clipboard-list"></i> Basic Application Summary</h4>
            <div class="confirm-review-grid confirm-review-grid-basic">
              <div class="summary-row"><span class="summary-label">Beneficiary Name</span><strong class="summary-value" id="modal-confirm-beneficiary">-</strong></div>
              <div class="summary-row"><span class="summary-label">Applicant Name</span><strong class="summary-value" id="modal-confirm-applicant">-</strong></div>
              <div class="summary-row"><span class="summary-label">Assistance Requested</span><strong class="summary-value highlight" id="modal-confirm-assistance">-</strong></div>
              <div class="summary-row"><span class="summary-label">Beneficiary Classification</span><strong class="summary-value" id="modal-confirm-classification">-</strong></div>
            </div>
          </section>

          <section class="confirm-review-section">
            <h4><i class="fas fa-user"></i> Beneficiary Information</h4>
            <div class="confirm-review-grid">
              <div class="summary-row"><span class="summary-label">Contact Number</span><strong class="summary-value" id="modal-confirm-contact">-</strong></div>
              <div class="summary-row"><span class="summary-label">Occupation / Source of Income</span><strong class="summary-value" id="modal-confirm-beneficiary-occupation">-</strong></div>
              <div class="summary-row"><span class="summary-label">Estimated Monthly Income</span><strong class="summary-value" id="modal-confirm-beneficiary-income">-</strong></div>
              <div class="summary-row summary-row-wide"><span class="summary-label">Beneficiary Address</span><strong class="summary-value" id="modal-confirm-beneficiary-address">-</strong></div>
            </div>
          </section>

          <section class="confirm-review-section">
            <h4><i class="fas fa-user-pen"></i> Applicant Information</h4>
            <div class="confirm-review-grid">
              <div class="summary-row"><span class="summary-label">Relationship to Beneficiary</span><strong class="summary-value" id="modal-confirm-applicant-relationship">-</strong></div>
              <div class="summary-row"><span class="summary-label">Occupation / Source of Income</span><strong class="summary-value" id="modal-confirm-applicant-occupation">-</strong></div>
              <div class="summary-row"><span class="summary-label">Estimated Monthly Income</span><strong class="summary-value" id="modal-confirm-applicant-income">-</strong></div>
              <div class="summary-row summary-row-wide"><span class="summary-label">Applicant Address</span><strong class="summary-value" id="modal-confirm-applicant-address">-</strong></div>
            </div>
          </section>

          <section class="confirm-review-section">
            <h4><i class="fas fa-paperclip"></i> Supporting Information</h4>
            <div class="confirm-review-grid">
              <div class="summary-row summary-row-wide"><span class="summary-label">Family Composition</span><strong class="summary-value" id="modal-confirm-family">-</strong></div>
              <div class="summary-row"><span class="summary-label">Total Estimated Monthly Family Income</span><strong class="summary-value" id="modal-confirm-family-income">-</strong></div>
              <div class="summary-row summary-row-documents"><span class="summary-label">Documents Attached</span><strong class="summary-documents-value" id="modal-confirm-docs">0 documents attached</strong></div>
            </div>
          </section>
        </div>
      </div>

      <div class="confirm-modal-actions">
        <button type="button" class="btn-cancel" id="btn-cancel-submit">Review Again</button>
        <button type="button" class="btn-submit" id="btn-confirm-submit">
          <i class="fas fa-paper-plane"></i> <?= $editMode ? 'Yes, Save Changes' : 'Yes, Submit Now' ?>
        </button>
      </div>

    </div>
  </div>

  <script>
    window.AICS_EDIT_BOOTSTRAP = <?= json_encode($editBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null' ?>;
    window.AICS_READ_ONLY_BOOTSTRAP = <?= json_encode($readOnlyBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null' ?>;
    window.AICS_DOCUMENT_REQUIREMENTS = <?= json_encode($clientDocumentRequirements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
    window.AICS_PROFILE_DEFAULTS = <?= json_encode($profileDefaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
    window.AICS_PROFILE_ID_DOCUMENTS = <?= json_encode((object) $profileIdDocuments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
    window.AICS_FAMILY_OPTIONS = <?= json_encode(['occupation' => $occupationOptions, 'education' => $educationOptions, 'income' => aics_income_options()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
  </script>
  <script src="../js files/antique-barangays.js"></script>
  <script src="../js files/beneficiary-birthdate.js?v=<?= htmlspecialchars($birthdateJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="../js files/applicant-pages.js?v=<?= htmlspecialchars($assetJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require __DIR__ . '/../../chatbot/applicant-widget.php'; ?>
</body>

</html>
