<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/pswdo-common.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../Applicants/php_files/auth-common.php';

pswdo_start_session();

$requestId = (int) ($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    http_response_code(400);
    exit('Invalid request ID.');
}

$paths = pswdo_paths();
$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);

// Fetch full GIS data
$row = null;
$staffRow = null;

try {
    $connection = aics_db_connect();

    $sql = <<<SQL
        SELECT
            r.id,
            r.reference_no,
            r.date_applied,
            r.request_status,
            r.beneficiary_first_name,
            r.beneficiary_middle_name,
            r.beneficiary_last_name,
            r.beneficiary_extension,
            r.beneficiary_birth_date,
            r.beneficiary_age,
            r.beneficiary_complete_name,
            r.applicant_first_name,
            r.applicant_middle_name,
            r.applicant_last_name,
            r.contact_number,
            r.sitio_purok,
            r.barangay,
            r.municipality,
            r.province,
            r.gender,
            r.estimated_income,
            r.employment,
            r.civil_status,
            r.civil_status_other,
            r.highest_educational_attainment,
            r.problem_presented,
            r.family_composition_json,
            r.client_categories_json,
            r.assistance_types_json,
            r.assistance_type_other,
            r.created_at
        FROM aics_requests r
        WHERE r.id = ?
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    // Fetch interviewing staff info
    $staffSql = <<<SQL
        SELECT
            sp.first_name,
            sp.middle_name,
            sp.last_name,
            sp.position_title
        FROM aics_staff_profiles sp
        WHERE sp.account_id = ?
        LIMIT 1
    SQL;
    $staffStmt = $connection->prepare($staffSql);
    $staffStmt->bind_param('i', $accountId);
    $staffStmt->execute();
    $staffRow = $staffStmt->get_result()?->fetch_assoc();
    $staffStmt->close();

    $connection->close();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to load request data. Please try again.');
}

if (!is_array($row)) {
    http_response_code(404);
    exit('Request not found.');
}

// --- Parse all fields ---
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$refNo    = trim((string) ($row['reference_no'] ?? ''));
$dateApplied = trim((string) ($row['date_applied'] ?? (string) ($row['created_at'] ?? '')));
$dateLabel = '';
if ($dateApplied !== '' && $dateApplied !== '0000-00-00') {
    $ts = strtotime($dateApplied);
    $dateLabel = $ts !== false ? date('F d, Y', $ts) : $dateApplied;
}

// Beneficiary name parts
$bFName = trim((string) ($row['beneficiary_first_name'] ?? ''));
$bMName = trim((string) ($row['beneficiary_middle_name'] ?? ''));
$bLName = trim((string) ($row['beneficiary_last_name'] ?? ''));
$bExt   = trim((string) ($row['beneficiary_extension'] ?? ''));
$bDob   = trim((string) ($row['beneficiary_birth_date'] ?? ''));
$bAge   = (int) ($row['beneficiary_age'] ?? 0);
$bDobLabel = '';
if ($bDob !== '' && $bDob !== '0000-00-00') {
    $ts = strtotime($bDob);
    $bDobLabel = $ts !== false ? date('F d, Y', $ts) : $bDob;
}

// Full name — "Last Name, First Name MI. Extension"
$bMI = $bMName !== '' ? strtoupper(substr($bMName, 0, 1)) . '.' : '';
$bFirstPart = trim(implode(' ', array_filter([$bFName, $bMI, $bExt])));
$bFullName = trim(implode(', ', array_filter([$bLName, $bFirstPart])));
$bFullName = $bFullName !== '' ? $bFullName : trim((string) ($row['beneficiary_complete_name'] ?? ''));

$gender       = ucfirst(strtolower(trim((string) ($row['gender'] ?? ''))));
$civilStatus  = trim((string) ($row['civil_status'] ?? ''));
$civilOther   = trim((string) ($row['civil_status_other'] ?? ''));
$civilLabel   = $civilStatus === 'other' ? ($civilOther !== '' ? $civilOther : 'Other') : ucfirst(str_replace('_', ' ', $civilStatus));
$education    = trim((string) ($row['highest_educational_attainment'] ?? ''));
$employment   = trim((string) ($row['employment'] ?? ''));
$incomeLabel  = aics_income_value($row['estimated_income'] ?? null) ?? '';

// Address
$addressParts = array_filter([
    trim((string) ($row['sitio_purok'] ?? '')),
    trim((string) ($row['barangay'] ?? '')),
    trim((string) ($row['municipality'] ?? '')),
    trim((string) ($row['province'] ?? '')),
]);
$address = implode(', ', $addressParts);

$contactNo = trim((string) ($row['contact_number'] ?? ''));

// Family composition
$familyRaw = trim((string) ($row['family_composition_json'] ?? '[]'));
$familyData = json_decode($familyRaw, true);
$familyMembers = is_array($familyData) ? $familyData : [];

// Problem presented
$problem = trim((string) ($row['problem_presented'] ?? ''));

// Assistance types
$assistanceRaw = trim((string) ($row['assistance_types_json'] ?? '[]'));
$assistanceData = json_decode($assistanceRaw, true);
$assistanceTypes = is_array($assistanceData) ? $assistanceData : [];
$assistanceOther = trim((string) ($row['assistance_type_other'] ?? ''));

// Beneficiary categories (legacy storage column retained for compatibility).
$categoriesRaw = trim((string) ($row['client_categories_json'] ?? '[]'));
$categoriesData = json_decode($categoriesRaw, true);
$clientCategories = is_array($categoriesData) ? $categoriesData : [];

// Applicant name
$aFName = trim((string) ($row['applicant_first_name'] ?? ''));
$aMName = trim((string) ($row['applicant_middle_name'] ?? ''));
$aLName = trim((string) ($row['applicant_last_name'] ?? ''));
$applicantName = trim(implode(' ', array_filter([$aFName, $aMName, $aLName])));

// Staff info
$staffFName = trim((string) ($staffRow['first_name'] ?? ''));
$staffMName = trim((string) ($staffRow['middle_name'] ?? ''));
$staffLName = trim((string) ($staffRow['last_name'] ?? ''));
$staffName  = trim(implode(' ', array_filter([$staffFName, $staffMName, $staffLName])));
$staffPosition = trim((string) ($staffRow['position_title'] ?? 'Case Management Officer'));
if ($staffName === '') {
    $staffName = pswdo_current_display_name();
}

// Assistance labels map
$assistanceLabelMap = [
    'medical'            => 'Medical Assistance',
    'burial'             => 'Burial Assistance',
    'victim_of_disaster' => 'Victims of Calamity',
    'special_cases'      => 'Special Cases / Others',
];

// Client category labels map
$categoryLabelMap = [
    'senior_citizen'       => 'Senior Citizen',
    'person_with_disability' => 'Person with Disability (PWD)',
    'solo_parent'          => 'Solo Parent',
    'indigent'             => 'Indigent',
    'displaced_worker'     => 'Displaced Worker',
    'victim_calamity'      => 'Victim of Calamity / Disaster',
    'victim_violence'      => 'Victim of Violence',
    'ip'                   => 'Indigenous People (IP)',
    'ofw'                  => 'Overseas Filipino Worker (OFW)',
    'other'                => 'Other',
];

$assistanceLabels = array_map(static fn ($code) => $assistanceLabelMap[$code] ?? ucwords(str_replace('_', ' ', (string) $code)), $assistanceTypes);
if ($assistanceOther !== '' && in_array('other', $assistanceTypes, true)) {
    $assistanceLabels[] = $assistanceOther;
}
$assistanceFinal = implode('; ', array_filter($assistanceLabels));

$categoryLabels = array_map(static fn ($code) => $categoryLabelMap[$code] ?? ucwords(str_replace('_', ' ', (string) $code)), $clientCategories);
$categoriesFinal = implode('; ', array_filter($categoryLabels));

$safeRef = $refNo !== '' ? preg_replace('/[^A-Za-z0-9\-_]/', '-', $refNo) : (string) $requestId;
$isPdfExport = !empty($_GET['format']) && $_GET['format'] === 'pdf';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>GIS — <?= $h($refNo !== '' ? $refNo : 'Request #' . $requestId) ?></title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <style>
    @page {
      size: legal portrait;
      margin: 18mm 15mm 18mm 20mm;
    }

    *, *::before, *::after { box-sizing: border-box; }

    body {
      font-family: 'Times New Roman', Times, serif;
      font-size: 11pt;
      color: #000;
      background: #fff;
      margin: 0;
      padding: 16px 20px;
    }

    /* --- HEADER --- */
    .gis-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 8px;
    }
    .gis-logo {
      width: 60px;
      height: 60px;
      flex-shrink: 0;
      object-fit: contain;
    }
    .gis-header-text {
      flex: 1;
      text-align: center;
    }
    .gis-header-text p {
      margin: 0;
      line-height: 1.4;
      font-size: 10pt;
    }
    .gis-header-text .office-name {
      font-size: 11pt;
      font-weight: bold;
      text-transform: uppercase;
    }
    .gis-form-title {
      text-align: center;
      font-size: 14pt;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 2px;
      margin: 8px 0 4px;
      border-top: 2.5px solid #000;
      border-bottom: 2.5px solid #000;
      padding: 4px 0;
    }

    /* --- META ROW --- */
    .gis-meta-row {
      display: flex;
      justify-content: space-between;
      margin: 6px 0 10px;
      font-size: 10pt;
    }
    .gis-meta-item {
      display: flex;
      gap: 6px;
    }
    .gis-meta-item label {
      font-weight: bold;
    }

    /* --- SECTION HEADING --- */
    .section-heading {
      background: #1e3a8a;
      color: #fff;
      font-size: 10.5pt;
      font-weight: bold;
      padding: 3px 8px;
      margin: 10px 0 4px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* --- DATA GRID --- */
    .gis-data-grid {
      display: grid;
      gap: 0;
      border-left: 1px solid #000;
      border-top: 1px solid #000;
      margin-bottom: 4px;
    }
    .gis-data-grid.cols-2 { grid-template-columns: 1fr 1fr; }
    .gis-data-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
    .gis-data-grid.cols-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }

    .gis-field {
      border-right: 1px solid #000;
      border-bottom: 1px solid #000;
      padding: 3px 6px;
    }
    .gis-field label {
      display: block;
      font-size: 8.5pt;
      font-weight: bold;
      text-transform: uppercase;
      color: #333;
      margin-bottom: 1px;
    }
    .gis-field .gis-value {
      font-size: 11pt;
      min-height: 16px;
      display: block;
    }
    .gis-field.span-2 { grid-column: span 2; }
    .gis-field.span-3 { grid-column: span 3; }
    .gis-field.span-4 { grid-column: span 4; }

    /* --- FAMILY TABLE --- */
    .gis-family-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 10pt;
      margin-bottom: 4px;
    }
    .gis-family-table th {
      background: #dbeafe;
      color: #1e3a8a;
      font-weight: bold;
      border: 1px solid #93c5fd;
      padding: 3px 5px;
      text-align: center;
      font-size: 9.5pt;
      text-transform: uppercase;
    }
    .gis-family-table td {
      border: 1px solid #000;
      padding: 3px 5px;
      vertical-align: top;
      height: 20px;
    }

    /* --- NARRATIVE AREA --- */
    .gis-narrative {
      border: 1px solid #000;
      min-height: 80px;
      padding: 5px 8px;
      font-size: 11pt;
      white-space: pre-wrap;
      margin-bottom: 4px;
    }
    .gis-narrative.short { min-height: 50px; }

    /* --- CHECKBOXES --- */
    .gis-check-item {
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .gis-check-box {
      width: 12px;
      height: 12px;
      border: 1.5px solid #000;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 9pt;
      font-weight: bold;
      flex-shrink: 0;
      background: #fff;
    }
    .gis-check-box.checked {
      background: #1e3a8a;
      color: #fff;
      border-color: #1e3a8a;
    }

    /* --- SIGNATURES --- */
    .gis-sig-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 40px;
      margin-top: 28px;
    }
    .gis-sig-block {
      text-align: left;
    }
    .gis-sig-header {
      font-size: 10pt;
      margin-bottom: 36px;
    }
    .gis-sig-line {
      border-top: 1.5px solid #000;
      margin-bottom: 3px;
      width: 60%;
    }
    .gis-sig-role {
      font-size: 10pt;
    }

    /* --- PRINT --- */
    @media screen {
      body { max-width: 820px; margin: 20px auto; padding: 24px 32px; }
      .print-action-bar {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        align-items: center;
      }
      .print-btn {
        background: #1d4ed8;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 9px 20px;
        font-size: 14px;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        display: flex;
        align-items: center;
        gap: 6px;
      }
      .print-btn:hover { background: #1e40af; }
      .close-link {
        color: #475569;
        font-size: 14px;
        font-family: 'Inter', sans-serif;
        text-decoration: none;
      }
      .close-link:hover { text-decoration: underline; }
    }
    @media print {
      .print-action-bar { display: none !important; }
      body { padding: 0; margin: 0; }
    }

    .text-muted { color: #888; font-style: italic; }
    #gis-document { width: 100%; box-sizing: border-box; overflow: hidden; }

    /* --- BENEFICIARY CATEGORY GRID --- */
    .gis-cat-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      border: 1px solid #000;
      margin-bottom: 4px;
    }
    .gis-cat-col {
      border-right: 1px solid #000;
      padding: 4px 8px;
    }
    .gis-cat-col:last-child { border-right: none; }
    .gis-cat-item {
      display: flex;
      align-items: center;
      gap: 5px;
      margin-bottom: 3px;
      font-size: 10pt;
    }
    .gis-cat-other {
      display: flex;
      align-items: center;
      gap: 5px;
      font-size: 10pt;
    }
    .gis-cat-other-line {
      border-bottom: 1px solid #000;
      flex: 1;
      min-width: 40px;
      height: 14px;
    }
  </style>
  <?php if ($isPdfExport): ?>
  <style>
    .print-action-bar { display: none !important; }
    body { padding: 0 !important; margin: 0 !important; }
    #gis-document { padding-right: 50px !important; }
  </style>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <?php endif; ?>
</head>
<body>

  <div class="print-action-bar">
    <button class="print-btn" onclick="window.print()">&#128438; Print / Save as PDF</button>
    <a class="close-link" href="javascript:window.close()">&#8592; Close</a>
  </div>

  <div id="gis-document">
  <!-- HEADER -->
  <div class="gis-header">
    <div class="gis-header-text">
      <p>Republic of the Philippines</p>
      <p>Province of Antique</p>
      <p class="office-name">Office of the Provincial Social Welfare and Development Officer</p>
      <p><?= $h(ucwords(strtolower(trim((string) ($row['municipality'] ?? 'San Jose'))))) ?>, Antique</p>
    </div>
  </div>

  <div class="gis-form-title">General Intake Sheet</div>

  <!-- META ROW -->
  <div class="gis-meta-row">
    <div class="gis-meta-item">
      <label>Case No.:</label>
      <span><?= $h($refNo) ?></span>
    </div>
    <div class="gis-meta-item">
      <label>Date:</label>
      <span><?= $h($dateLabel) ?></span>
    </div>
    <div class="gis-meta-item">
      <label>Reference No.:</label>
      <span><?= $h($refNo) ?></span>
    </div>
  </div>

  <!-- SECTION I: CLIENT INFORMATION -->
  <div class="section-heading">I. Demographic Data of the Client</div>

  <div class="gis-data-grid cols-1">
    <div class="gis-field">
      <label>Full Name (Last Name, First Name, Middle Initial, Extension)</label>
      <span class="gis-value"><?= $h($bFullName !== '' ? $bFullName : '&nbsp;') ?></span>
    </div>
  </div>

  <div class="gis-data-grid cols-4">
    <div class="gis-field">
      <label>Date of Birth</label>
      <span class="gis-value"><?= $h($bDobLabel !== '' ? $bDobLabel : '&nbsp;') ?></span>
    </div>
    <div class="gis-field">
      <label>Age</label>
      <span class="gis-value"><?= $bAge > 0 ? $bAge : '&nbsp;' ?></span>
    </div>
    <div class="gis-field">
      <label>Sex</label>
      <span class="gis-value"><?= $h($gender !== '' ? $gender : '&nbsp;') ?></span>
    </div>
    <div class="gis-field">
      <label>Civil Status</label>
      <span class="gis-value"><?= $h($civilLabel !== '' ? $civilLabel : '&nbsp;') ?></span>
    </div>
  </div>

  <div class="gis-data-grid cols-2">
    <div class="gis-field">
      <label>Highest Educational Attainment</label>
      <span class="gis-value"><?= $h($education !== '' ? $education : '&nbsp;') ?></span>
    </div>
    <div class="gis-field">
      <label>Present Occupation / Source of Income</label>
      <span class="gis-value"><?= $h($employment !== '' ? $employment : '&nbsp;') ?></span>
    </div>
  </div>

  <div class="gis-data-grid cols-2">
    <div class="gis-field">
      <label>Estimated Monthly Income</label>
      <span class="gis-value"><?= $h($incomeLabel !== '' ? $incomeLabel : '&nbsp;') ?></span>
    </div>
    <div class="gis-field">
      <label>Contact Number</label>
      <span class="gis-value"><?= $h($contactNo !== '' ? $contactNo : '&nbsp;') ?></span>
    </div>
  </div>

  <div class="gis-data-grid cols-1">
    <div class="gis-field">
      <label>Present Address (Sitio/Purok, Barangay, Municipality, Province)</label>
      <span class="gis-value"><?= $h($address !== '' ? $address : '&nbsp;') ?></span>
    </div>
  </div>

  <?php if ($applicantName !== ''): ?>
  <div class="gis-data-grid cols-1">
    <div class="gis-field">
      <label>Applicant</label>
      <span class="gis-value"><?= $h($applicantName) ?></span>
    </div>
  </div>
  <?php endif; ?>

  <!-- SECTION II: BENEFICIARY HOUSEHOLD / FAMILY COMPOSITION -->
  <div class="section-heading">II. Beneficiary Household / Family Composition</div>

  <table class="gis-family-table">
    <thead>
      <tr>
        <th style="width:22%">Name</th>
        <th style="width:6%">Age</th>
        <th style="width:8%">Sex</th>
        <th style="width:15%">Relationship to Beneficiary</th>
        <th style="width:16%">Occupation</th>
        <th style="width:16%">Educational Attainment</th>
        <th style="width:17%">Estimated Monthly Income</th>
      </tr>
    </thead>
    <tbody>
      <?php
        $minRows = max(6, count($familyMembers));
        for ($i = 0; $i < $minRows; $i++):
          $member = is_array($familyMembers[$i] ?? null) ? $familyMembers[$i] : [];
          $mName  = trim((string) ($member['name'] ?? ''));
          $mAge   = trim((string) ($member['age'] ?? ''));
          $mRel   = trim((string) ($member['relationship'] ?? ''));
          $mOcc   = trim((string) ($member['occupation'] ?? ''));
          $mEdu   = trim((string) ($member['educational_attainment'] ?? ''));
          $mIncome = aics_income_value($member['estimated_monthly_income'] ?? null) ?? '';
      ?>
      <tr>
        <td><?= $h($mName) ?></td>
        <td style="text-align:center"><?= $h($mAge) ?></td>
        <td></td>
        <td><?= $h($mRel) ?></td>
        <td><?= $h($mOcc) ?></td>
        <td><?= $h($mEdu) ?></td>
        <td><?= $h($mIncome) ?></td>
      </tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <!-- SECTION III: PROBLEM PRESENTED -->
  <div class="section-heading">III. Problem Presented</div>
  <div class="gis-narrative"><?= $h($problem) ?></div>

  <!-- SECTION IV: CASE WORKER'S ASSESSMENT -->
  <div class="section-heading">IV. Case Worker's Assessment / Initial Recommendation</div>
  <div class="gis-narrative short">Client is eligible for financial assistance &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; &#8369; &nbsp;&nbsp;&nbsp;&nbsp; -</div>

  <!-- BENEFICIARY CATEGORY -->
  <?php
    $catOther = trim((string) ($row['client_category_other'] ?? ''));
    $catChecked = static fn(string $code): bool => in_array($code, $clientCategories, true);
    $chk = static fn(bool $c): string => $c ? '<div class="gis-check-box checked">&#10003;</div>' : '<div class="gis-check-box"></div>';
    $aChecked = static fn(string $code): bool => in_array($code, $assistanceTypes, true);
  ?>
  <div style="font-size:10pt; font-weight:bold; margin: 10px 0 3px;">Beneficiary Category:</div>
  <div class="gis-cat-grid">
    <div class="gis-cat-col">
      <div class="gis-cat-item"><?= $chk($catChecked('youth')) ?> Youth</div>
      <div class="gis-cat-item"><?= $chk($catChecked('senior_citizen')) ?> Senior Citizen</div>
      <div class="gis-cat-item"><?= $chk($catChecked('pwd')) ?> PWD</div>
      <div class="gis-cat-item"><?= $chk($catChecked('pantawid')) ?> Pantawid</div>
    </div>
    <div class="gis-cat-col">
      <div class="gis-cat-item"><?= $chk($catChecked('ips')) ?> IPS&rsquo;s</div>
      <div class="gis-cat-item"><?= $chk($catChecked('family_head')) ?> Family Head</div>
      <div class="gis-cat-item"><?= $chk($catChecked('men_women')) ?> Men/Women</div>
      <div class="gis-cat-other"><?= $chk($catChecked('others')) ?> Others: <span class="gis-cat-other-line"><?= $h($catChecked('others') ? $catOther : '') ?></span></div>
    </div>
    <div class="gis-cat-col">
      <div class="gis-cat-item"><?= $chk($aChecked('medical')) ?> Medical</div>
      <div class="gis-cat-item"><?= $chk($aChecked('burial')) ?> Burial</div>
      <div class="gis-cat-item"><?= $chk($aChecked('victim_of_disaster')) ?> Victims of Calamity</div>
      <div class="gis-cat-other"><?= $chk($aChecked('special_cases')) ?> Others: <span class="gis-cat-other-line"><?= $h($aChecked('special_cases') ? $assistanceOther : '') ?></span></div>
    </div>
  </div>

  <!-- SIGNATURES -->
  <div class="gis-sig-row">
    <div class="gis-sig-block">
      <div class="gis-sig-header">Interviewed by:</div>
      <div class="gis-sig-line"></div>
      <div class="gis-sig-role"><?= $h($staffPosition !== '' ? $staffPosition : 'Social Worker') ?></div>
    </div>
    <div class="gis-sig-block">
      <div class="gis-sig-header">Client:</div>
      <div class="gis-sig-line"></div>
      <div class="gis-sig-role">Name and Signature of Client</div>
    </div>
  </div>
  </div><!-- #gis-document -->

  <?php if ($isPdfExport): ?>
  <script>
  window.addEventListener('load', function () {
    var el = document.getElementById('gis-document');
    var W = 820;
    el.style.width = W + 'px';
    html2pdf().set({
      margin: [10, 6, 10, 6],
      filename: 'GIS-<?= $safeRef ?>.pdf',
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2, useCORS: true, logging: false, windowWidth: W, width: W, x: 0, y: 0 },
      jsPDF: { unit: 'mm', format: 'legal', orientation: 'portrait' }
    }).from(el).save().then(function () { window.close(); });
  });
  </script>
  <?php endif; ?>
</body>
</html>
