<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';

aics_start_session();

$format = strtolower(trim((string) ($_GET['format'] ?? 'print')));
if (!in_array($format, ['print', 'pdf', 'txt'], true)) {
    $format = 'print';
}

if (!isset($_SESSION['aics_account_id'])) {
    export_fail('Please log in first.', 401, $format);
}

$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$roleCode = strtolower(trim((string) ($_SESSION['aics_role_code'] ?? '')));
$isApplicantViewer = $roleCode === 'applicat';
$isPswdoViewer = $roleCode === 'pswdo';
$isAdminViewer = $roleCode === 'admin';

if (!$isApplicantViewer && !$isPswdoViewer && !$isAdminViewer) {
    export_fail('You are not allowed to access this export.', 403, $format);
}

$requestId = (int) ($_GET['request_id'] ?? 0);
if ($requestId <= 0 || ($isApplicantViewer && $accountId <= 0)) {
    export_fail('Invalid request reference.', 400, $format);
}

$scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$scriptDirectory = rtrim($scriptDirectory, '/');
$isPhpFilesRoute = str_ends_with($scriptDirectory, '/php_files');
$applicantsBaseUrl = $isPhpFilesRoute ? dirname($scriptDirectory) : $scriptDirectory;
$phpFilesBaseUrl = $isPhpFilesRoute ? $scriptDirectory : $scriptDirectory . '/php_files';
$dashboardUrl = $applicantsBaseUrl . '/dashboard.php';
$projectRootUrl = dirname(dirname($applicantsBaseUrl));
$backUrl = $isPswdoViewer
    ? $projectRootUrl . '/Pswdo/dashboard.php?page=requests'
    : ($dashboardUrl . '?page=documents');
$backLabel = $isPswdoViewer ? 'Back to Request Management' : 'Back to Documents';
$logoUrl = $projectRootUrl . '/asset/logocp.png';

try {
    $connection = aics_db_connect();
    aics_ensure_auth_schema($connection);
    aics_ensure_request_intake_columns($connection);

    $whereSql = $isApplicantViewer
        ? 'WHERE ar.account_id = ? AND r.id = ?'
        : 'WHERE r.id = ?';

    $sql = <<<SQL
        SELECT
            r.id,
            r.reference_no,
            r.date_applied,
            r.beneficiary_first_name,
            r.beneficiary_middle_name,
            r.beneficiary_last_name,
            r.beneficiary_extension,
            r.beneficiary_birth_date,
            r.beneficiary_age,
            r.beneficiary_complete_name,
            r.contact_number,
            r.applicant_first_name,
            r.applicant_middle_name,
            r.applicant_last_name,
            r.applicant_age,
            r.barangay,
            r.sitio_purok,
            r.municipality,
            r.province,
            r.gender,
            r.civil_status,
            r.civil_status_other,
            r.highest_educational_attainment,
            r.employment,
            r.estimated_income,
            r.problem_presented,
            r.family_composition_json,
            r.family_total_income,
            r.client_categories_json,
            r.assistance_types_json,
            r.assistance_type_other,
            r.created_at,
            a.email AS applicant_email,
            ap.first_name AS profile_first_name,
            ap.middle_name AS profile_middle_name,
            ap.last_name AS profile_last_name,
            ap.contact_number AS profile_contact_number,
            ap.complete_address AS profile_complete_address
        FROM aics_requests r
        LEFT JOIN aics_account_requests ar ON ar.request_id = r.id
        LEFT JOIN aics_accounts a ON a.id = ar.account_id
        LEFT JOIN aics_applicant_profiles ap ON ap.account_id = ar.account_id
        $whereSql
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    if ($isApplicantViewer) {
        $stmt->bind_param('ii', $accountId, $requestId);
    } else {
        $stmt->bind_param('i', $requestId);
    }
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    $connection->close();

    if (!is_array($row)) {
        export_fail('Request not found or not allowed.', 404, $format);
    }
} catch (Throwable $exception) {
    export_fail('Unable to generate request export right now.', 500, $format);
}

// ---------- Parse fields ----------
$referenceNo = trim((string) ($row['reference_no'] ?? ''));
$dateApplied = export_date((string) ($row['date_applied'] ?? ''));
$submittedAt = export_datetime((string) ($row['created_at'] ?? ''));

$beneficiaryFirstName = trim((string) ($row['beneficiary_first_name'] ?? ''));
$beneficiaryMiddleName = trim((string) ($row['beneficiary_middle_name'] ?? ''));
$beneficiaryLastName = trim((string) ($row['beneficiary_last_name'] ?? ''));
$beneficiaryExtension = trim((string) ($row['beneficiary_extension'] ?? ''));
$beneficiaryBirthDate = export_date((string) ($row['beneficiary_birth_date'] ?? ''));
$beneficiaryAge = trim((string) ($row['beneficiary_age'] ?? ''));

$beneficiaryFullName = trim((string) ($row['beneficiary_complete_name'] ?? ''));
if ($beneficiaryFullName === '') {
    $beneficiaryFullName = trim(implode(' ', array_filter([
        $beneficiaryFirstName,
        $beneficiaryMiddleName,
        $beneficiaryLastName,
        $beneficiaryExtension,
    ])));
}

$profileFirstName = trim((string) ($row['profile_first_name'] ?? ''));
$profileMiddleName = trim((string) ($row['profile_middle_name'] ?? ''));
$profileLastName = trim((string) ($row['profile_last_name'] ?? ''));

$applicantFirstName = trim((string) ($row['applicant_first_name'] ?? '')) ?: $profileFirstName;
$applicantMiddleName = trim((string) ($row['applicant_middle_name'] ?? '')) ?: $profileMiddleName;
$applicantLastName = trim((string) ($row['applicant_last_name'] ?? '')) ?: $profileLastName;
$applicantAge = trim((string) ($row['applicant_age'] ?? ''));
$applicantFullName = trim(implode(' ', array_filter([
    $applicantFirstName,
    $applicantMiddleName,
    $applicantLastName,
])));
$applicantEmail = trim((string) ($row['applicant_email'] ?? ''));

$contactNumber = trim((string) ($row['contact_number'] ?? ''));
$gender = export_titleize((string) ($row['gender'] ?? ''));

$civilStatusRaw = aics_normalize_civil_status($row['civil_status'] ?? null) ?? '';
$civilStatusOther = trim((string) ($row['civil_status_other'] ?? ''));
$civilStatusLabel = $civilStatusRaw === 'other'
    ? ($civilStatusOther !== '' ? $civilStatusOther : 'Other')
    : (aics_civil_status_options()[$civilStatusRaw] ?? export_titleize($civilStatusRaw));

$education = trim((string) ($row['highest_educational_attainment'] ?? ''));
$employment = trim((string) ($row['employment'] ?? ''));
$incomeLabel = aics_income_value($row['estimated_income'] ?? null) ?? '';

$problem = trim((string) ($row['problem_presented'] ?? ''));

$province = trim((string) ($row['province'] ?? ''));
$municipality = trim((string) ($row['municipality'] ?? ''));
$barangay = trim((string) ($row['barangay'] ?? ''));
$sitioPurok = trim((string) ($row['sitio_purok'] ?? ''));

$fullAddress = trim(implode(', ', array_filter([
    $sitioPurok,
    $barangay,
    $municipality,
    $province,
])));
if ($fullAddress === '') {
    $fullAddress = trim((string) ($row['profile_complete_address'] ?? ''));
}

$clientCategoryLabel = export_categories_label((string) ($row['client_categories_json'] ?? '[]'), 'client');
$assistanceTypeLabel = export_categories_label((string) ($row['assistance_types_json'] ?? '[]'), 'assistance');
$assistanceTypeOther = trim((string) ($row['assistance_type_other'] ?? ''));
if ($assistanceTypeOther !== '') {
    $assistanceTypeLabel = $assistanceTypeLabel !== 'Not provided'
        ? $assistanceTypeLabel . '; Others: ' . $assistanceTypeOther
        : 'Others: ' . $assistanceTypeOther;
}

$familyData = json_decode(trim((string) ($row['family_composition_json'] ?? '[]')), true);
$familyMembers = is_array($familyData) ? $familyData : [];

$safeRef = $referenceNo !== '' ? preg_replace('/[^A-Za-z0-9._-]/', '_', $referenceNo) : ('request_' . $requestId);
$isPdfExport = $format === 'pdf';

// ---------- TXT format ----------
if ($format === 'txt') {
    $lines = [];
    $lines[] = 'AICS PERSONAL INFORMATION SHEET';
    $lines[] = str_repeat('=', 50);
    $lines[] = 'Reference No.: ' . export_fill($referenceNo, 'Not available');
    $lines[] = 'Date Applied: ' . export_fill($dateApplied, 'Not available');
    $lines[] = 'Submitted At: ' . export_fill($submittedAt, 'Not available');
    $lines[] = '';
    $lines[] = '-- BENEFICIARY --';
    $lines[] = 'Full Name: ' . export_fill($beneficiaryFullName);
    $lines[] = 'Date of Birth: ' . export_fill($beneficiaryBirthDate);
    $lines[] = 'Age: ' . export_age_label($beneficiaryAge);
    $lines[] = 'Beneficiary Sex / Gender: ' . export_fill($gender);
    $lines[] = 'Civil Status: ' . export_fill($civilStatusLabel);
    $lines[] = '';
    $lines[] = '-- APPLICANT --';
    $lines[] = 'Full Name: ' . export_fill($applicantFullName);
    $lines[] = 'Email: ' . export_fill($applicantEmail);
    $lines[] = 'Age: ' . export_age_label($applicantAge);
    $lines[] = '';
    $lines[] = '-- PERSONAL BACKGROUND --';
    $lines[] = 'Educational Attainment: ' . export_fill($education);
    $lines[] = 'Occupation / Employment: ' . export_fill($employment);
    $lines[] = 'Estimated Monthly Income: ' . export_fill($incomeLabel);
    $lines[] = '';
    $lines[] = '-- CONTACT & ADDRESS --';
    $lines[] = 'Contact Number: ' . export_fill($contactNumber);
    $lines[] = 'Sitio / Purok: ' . export_fill($sitioPurok);
    $lines[] = 'Barangay: ' . export_fill($barangay);
    $lines[] = 'Municipality: ' . export_fill($municipality);
    $lines[] = 'Province: ' . export_fill($province);
    $lines[] = 'Full Address: ' . export_fill($fullAddress);
    $lines[] = '';
    $lines[] = '-- ASSISTANCE REQUEST --';
    $lines[] = 'Beneficiary Category: ' . export_fill($clientCategoryLabel);
    $lines[] = 'Assistance Type: ' . export_fill($assistanceTypeLabel);
    $lines[] = 'Beneficiary Present Need: ' . export_fill($problem);
    $lines[] = '';
    if (count($familyMembers) > 0) {
        $lines[] = '-- FAMILY COMPOSITION --';
        foreach ($familyMembers as $i => $member) {
            $idx = $i + 1;
            $lines[] = "Member {$idx}: " . trim((string) ($member['name'] ?? '')) . ' | Age ' . trim((string) ($member['age'] ?? '')) . ' | ' . trim((string) ($member['relationship'] ?? '')) . ' | ' . trim((string) ($member['occupation'] ?? '')) . ' | ' . trim((string) ($member['educational_attainment'] ?? '')) . ' | Estimated Monthly Income: ' . export_fill(aics_income_value($member['estimated_monthly_income'] ?? null) ?? '');
        }
        $lines[] = 'Total Estimated Monthly Family Income: ' . export_fill(aics_income_value($row['family_total_income'] ?? null) ?? '');
        $lines[] = '';
    }
    $lines[] = 'Generated: ' . date('Y-m-d H:i:s');

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeRef . '-personal-sheet.txt"');
    echo implode("\r\n", $lines);
    exit;
}

// ---------- helper functions (declared below) ----------
function export_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function export_fill(string $value, string $fallback = 'Not provided'): string
{
    $clean = trim($value);
    return $clean !== '' ? $clean : $fallback;
}

function export_age_label(string $value): string
{
    $clean = trim($value);
    if ($clean === '') {
        return 'Not provided';
    }
    if (preg_match('/^\d+$/', $clean) === 1) {
        return $clean . ' years old';
    }
    return $clean;
}

function export_categories_label(string $json, string $kind): string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || count($decoded) === 0) {
        return 'Not provided';
    }
    $labels = [];
    foreach ($decoded as $item) {
        $value = strtolower(trim((string) $item));
        if ($value === '') {
            continue;
        }
        if ($kind === 'client') {
            $labels[] = match ($value) {
                'senior_citizen' => 'Senior Citizen',
                'solo_parent' => 'Solo Parent',
                'pwd' => 'PWD',
                'ips' => 'IPs',
                'youth' => 'Youth',
                'pantawid' => 'Pantawid',
                'family_head' => 'Family Head',
                'men_women' => 'Men/Women',
                'others' => 'Others',
                default => export_titleize($value),
            };
        } else {
            $labels[] = match ($value) {
                'medical' => 'Medical',
                'burial' => 'Burial',
                'victim_of_disaster' => 'Victims of Calamity',
                'special_cases' => 'Special Cases',
                default => export_titleize($value),
            };
        }
    }
    $labels = array_values(array_unique(array_filter($labels)));
    if (count($labels) === 0) {
        return 'Not provided';
    }
    return implode(', ', $labels);
}

function export_titleize(string $value): string
{
    $clean = trim($value);
    if ($clean === '') {
        return '';
    }
    return ucwords(str_replace('_', ' ', $clean));
}

function export_date(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '' || $trimmed === '0000-00-00') {
        return '';
    }
    try {
        return (new DateTimeImmutable($trimmed))->format('F d, Y');
    } catch (Throwable $exception) {
        return $trimmed;
    }
}

function export_datetime(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '' || $trimmed === '0000-00-00 00:00:00') {
        return '';
    }
    try {
        return (new DateTimeImmutable($trimmed))->format('F d, Y h:i A');
    } catch (Throwable $exception) {
        return $trimmed;
    }
}

function export_fail(string $message, int $statusCode, string $format): void
{
    http_response_code($statusCode);
    if ($format === 'txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Request Export</title></head><body><p>'
        . export_h($message) . '</p></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= export_h(($referenceNo !== '' ? $referenceNo : 'AICS Request') . ' | Personal Information Sheet') ?></title>
  <style>
    :root {
      --ink: #111827;
      --muted: #4b5563;
      --line: #7c8798;
      --line-soft: #b9c2cf;
      --paper: #ffffff;
      --panel: #f7f9fc;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 18px;
      background: #eef2f7;
      font-family: Arial, Helvetica, sans-serif;
      color: var(--ink);
      line-height: 1.4;
    }
    .sheet {
      max-width: 900px;
      margin: 0 auto;
      background: var(--paper);
      border: 1px solid var(--line);
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
    }
    .tools {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      padding: 12px 14px;
      border-bottom: 1px solid var(--line-soft);
      background: var(--panel);
    }
    .btn {
      display: inline-block;
      text-decoration: none;
      border: 1px solid var(--line-soft);
      border-radius: 8px;
      padding: 8px 12px;
      font-size: 13px;
      font-weight: 700;
      color: var(--ink);
      background: #fff;
      cursor: pointer;
      font-family: inherit;
    }
    .btn-primary {
      background: #0f172a;
      border-color: #0f172a;
      color: #fff;
    }
    .head {
      padding: 14px 18px 12px;
      border-bottom: 1px solid var(--line);
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .head-logo {
      width: 64px;
      height: 64px;
      object-fit: contain;
      flex: 0 0 auto;
    }
    .head-copy {
      flex: 1 1 auto;
      text-align: center;
      padding-right: 64px;
    }
    .head h1 {
      margin: 0;
      font-size: 19px;
      font-weight: 800;
      letter-spacing: 0.6px;
      text-transform: uppercase;
    }
    .head p {
      margin: 6px 0 0;
      font-size: 12px;
      color: var(--muted);
    }
    .head .agency {
      margin: 0 0 4px;
      font-size: 11px;
      letter-spacing: 0.4px;
      text-transform: uppercase;
      color: #334155;
      font-weight: 700;
    }
    .section-title {
      margin: 0;
      padding: 8px 12px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-top: 1px solid var(--line);
      border-bottom: 1px solid var(--line);
      background: #ecf1f6;
      page-break-inside: avoid;
      page-break-after: avoid;
      break-inside: avoid;
      break-after: avoid;
    }
    table.form {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      page-break-inside: auto;
    }
    table.form tr {
      page-break-inside: avoid;
      break-inside: avoid;
    }
    table.form td {
      border-right: 1px solid var(--line-soft);
      border-bottom: 1px solid var(--line-soft);
      vertical-align: top;
      padding: 8px 10px;
      font-size: 12px;
      word-wrap: break-word;
      overflow-wrap: break-word;
    }
    table.form tr td:last-child {
      border-right: 0;
    }
    .label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      color: #334155;
      margin-bottom: 4px;
      letter-spacing: 0.35px;
    }
    .value {
      min-height: 20px;
      padding: 2px 2px 3px;
      font-size: 12px;
      color: #111827;
      word-break: break-word;
    }
    .narrative {
      min-height: 60px;
      padding: 4px;
      font-size: 12px;
      white-space: pre-wrap;
    }
    .span-2 { width: 66.66%; }
    .span-1 { width: 33.33%; }
    table.family {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    table.family tr {
      page-break-inside: avoid;
      break-inside: avoid;
    }
    table.family th, table.family td {
      border: 1px solid var(--line-soft);
      padding: 5px 7px;
      font-size: 10.5px;
      text-align: left;
      word-wrap: break-word;
      overflow-wrap: break-word;
      vertical-align: top;
    }
    table.family th {
      background: #ecf1f6;
      font-weight: 700;
      text-transform: uppercase;
      font-size: 9.5px;
      letter-spacing: 0.3px;
    }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { max-width: none; border: 0; border-radius: 0; box-shadow: none; }
      .no-print { display: none !important; }
    }
  </style>
  <?php if ($isPdfExport): ?>
  <style>
    .no-print { display: none !important; }
    body {
      padding: 0 !important;
      margin: 0 !important;
      background: #f1f5f9 !important;
      font-family: 'Inter', Arial, Helvetica, sans-serif;
    }
    /* Keep the sheet in normal flow so html2canvas can capture it
       reliably from inside a hidden iframe download. */
    .sheet {
      max-width: 1140px !important;
      width: 1140px !important;
      border: 0 !important;
      border-radius: 0 !important;
      box-shadow: none !important;
      margin: 0 !important;
    }
    .pdf-loading-overlay {
      position: fixed;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 16px;
      background: #f1f5f9;
      z-index: 9999;
    }
    .pdf-loading-spinner {
      width: 50px;
      height: 50px;
      border: 4px solid #cbd5e1;
      border-top-color: #1d4ed8;
      border-radius: 50%;
      animation: pdf-spin 0.9s linear infinite;
    }
    .pdf-loading-title { font-size: 16px; font-weight: 700; color: #0f172a; }
    .pdf-loading-sub   { font-size: 12.5px; color: #475569; }
    @keyframes pdf-spin { to { transform: rotate(360deg); } }
  </style>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <?php endif; ?>
</head>
<body>
  <?php if ($isPdfExport): ?>
    <div class="pdf-loading-overlay" id="pdf-loading-overlay">
      <div class="pdf-loading-spinner"></div>
      <div class="pdf-loading-title">Generating your PDF...</div>
      <div class="pdf-loading-sub">This window will close automatically when the download starts.</div>
    </div>
  <?php endif; ?>

  <div class="sheet" id="export-sheet">
    <div class="tools no-print">
      <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save PDF</button>
      <a class="btn" href="<?= export_h($backUrl) ?>"><?= export_h($backLabel) ?></a>
    </div>

    <header class="head">
      <img class="head-logo" src="<?= export_h($logoUrl) ?>" alt="Office Logo">
      <div class="head-copy">
        <p class="agency">Republic of the Philippines</p>
        <p class="agency">Provincial Social Welfare and Development Office &middot; Province of Antique</p>
        <h1>AICS Personal Information Sheet</h1>
        <p>Official form &mdash; submitted application details</p>
      </div>
    </header>

    <!-- Request Reference -->
    <h2 class="section-title">Request Reference</h2>
    <table class="form">
      <tr>
        <td class="span-1"><div class="label">Reference Number</div><div class="value"><?= export_h(export_fill($referenceNo, 'Not available')) ?></div></td>
        <td class="span-1"><div class="label">Date Applied</div><div class="value"><?= export_h(export_fill($dateApplied, 'Not available')) ?></div></td>
        <td class="span-1"><div class="label">Submitted At</div><div class="value"><?= export_h(export_fill($submittedAt, 'Not available')) ?></div></td>
      </tr>
    </table>

    <!-- Beneficiary Information -->
    <h2 class="section-title">Beneficiary Information</h2>
    <table class="form">
      <tr>
        <td class="span-1"><div class="label">Last Name</div><div class="value"><?= export_h(export_fill($beneficiaryLastName)) ?></div></td>
        <td class="span-1"><div class="label">First Name</div><div class="value"><?= export_h(export_fill($beneficiaryFirstName)) ?></div></td>
        <td class="span-1"><div class="label">Middle Name</div><div class="value"><?= export_h(export_fill($beneficiaryMiddleName)) ?></div></td>
      </tr>
      <tr>
        <td class="span-2" colspan="2"><div class="label">Complete Name of Beneficiary</div><div class="value"><?= export_h(export_fill($beneficiaryFullName)) ?></div></td>
        <td class="span-1"><div class="label">Name Extension</div><div class="value"><?= export_h(export_fill($beneficiaryExtension)) ?></div></td>
      </tr>
      <tr>
        <td class="span-1"><div class="label">Date of Birth</div><div class="value"><?= export_h(export_fill($beneficiaryBirthDate)) ?></div></td>
        <td class="span-1"><div class="label">Age</div><div class="value"><?= export_h(export_age_label($beneficiaryAge)) ?></div></td>
        <td class="span-1"><div class="label">Beneficiary Sex / Gender</div><div class="value"><?= export_h(export_fill($gender)) ?></div></td>
      </tr>
      <tr>
        <td class="span-1"><div class="label">Civil Status</div><div class="value"><?= export_h(export_fill($civilStatusLabel)) ?></div></td>
        <td class="span-2" colspan="2"><div class="label">Highest Educational Attainment</div><div class="value"><?= export_h(export_fill($education)) ?></div></td>
      </tr>
      <tr>
        <td class="span-2" colspan="2"><div class="label">Occupation / Source of Income</div><div class="value"><?= export_h(export_fill($employment)) ?></div></td>
        <td class="span-1"><div class="label">Estimated Monthly Income</div><div class="value"><?= export_h(export_fill($incomeLabel)) ?></div></td>
      </tr>
    </table>

    <!-- Applicant Information (Representative) -->
    <h2 class="section-title">Applicant Information</h2>
    <table class="form">
      <tr>
        <td class="span-1"><div class="label">Last Name</div><div class="value"><?= export_h(export_fill($applicantLastName)) ?></div></td>
        <td class="span-1"><div class="label">First Name</div><div class="value"><?= export_h(export_fill($applicantFirstName)) ?></div></td>
        <td class="span-1"><div class="label">Middle Name</div><div class="value"><?= export_h(export_fill($applicantMiddleName)) ?></div></td>
      </tr>
      <tr>
        <td class="span-2" colspan="2"><div class="label">Complete Name of Applicant</div><div class="value"><?= export_h(export_fill($applicantFullName)) ?></div></td>
        <td class="span-1"><div class="label">Age</div><div class="value"><?= export_h(export_age_label($applicantAge)) ?></div></td>
      </tr>
      <tr>
        <td colspan="3"><div class="label">Email Address</div><div class="value"><?= export_h(export_fill($applicantEmail)) ?></div></td>
      </tr>
    </table>

    <!-- Contact & Address -->
    <h2 class="section-title">Contact and Address</h2>
    <table class="form">
      <tr>
        <td class="span-1"><div class="label">Contact Number</div><div class="value"><?= export_h(export_fill($contactNumber)) ?></div></td>
        <td class="span-1"><div class="label">Sitio / Purok</div><div class="value"><?= export_h(export_fill($sitioPurok)) ?></div></td>
        <td class="span-1"><div class="label">Barangay</div><div class="value"><?= export_h(export_fill($barangay)) ?></div></td>
      </tr>
      <tr>
        <td class="span-1"><div class="label">Municipality</div><div class="value"><?= export_h(export_fill($municipality)) ?></div></td>
        <td class="span-1"><div class="label">Province</div><div class="value"><?= export_h(export_fill($province)) ?></div></td>
        <td class="span-1"></td>
      </tr>
      <tr>
        <td colspan="3"><div class="label">Complete Address</div><div class="value"><?= export_h(export_fill($fullAddress)) ?></div></td>
      </tr>
    </table>

    <!-- Assistance Request -->
    <h2 class="section-title">Assistance Request Details</h2>
    <table class="form">
      <tr>
        <td colspan="3"><div class="label">Beneficiary Category</div><div class="value"><?= export_h(export_fill($clientCategoryLabel)) ?></div></td>
      </tr>
      <tr>
        <td colspan="3"><div class="label">Type of Assistance Requested</div><div class="value"><?= export_h(export_fill($assistanceTypeLabel)) ?></div></td>
      </tr>
      <tr>
        <td colspan="3"><div class="label">Beneficiary Present Need</div><div class="narrative"><?= export_h(export_fill($problem)) ?></div></td>
      </tr>
    </table>

    <!-- Family Composition -->
    <?php if (count($familyMembers) > 0): ?>
      <h2 class="section-title">Beneficiary Household / Family Composition</h2>
      <div style="padding: 8px 10px;">
        <table class="family">
          <thead>
            <tr>
              <th style="width: 5%;">#</th>
              <th style="width: 30%;">Name</th>
              <th style="width: 8%;">Age</th>
              <th style="width: 17%;">Relationship</th>
              <th style="width: 20%;">Occupation</th>
              <th style="width: 20%;">Educational Attainment</th>
              <th style="width: 18%;">Estimated Monthly Income</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($familyMembers as $idx => $member): ?>
              <?php if (!is_array($member)) continue; ?>
              <tr>
                <td><?= (int) ($idx + 1) ?></td>
                <td><?= export_h(export_fill(trim((string) ($member['name'] ?? '')))) ?></td>
                <td><?= export_h(export_fill(trim((string) ($member['age'] ?? '')))) ?></td>
                <td><?= export_h(export_fill(trim((string) ($member['relationship'] ?? '')))) ?></td>
                <td><?= export_h(export_fill(trim((string) ($member['occupation'] ?? '')))) ?></td>
                <td><?= export_h(export_fill(trim((string) ($member['educational_attainment'] ?? '')))) ?></td>
                <td><?= export_h(export_fill(aics_income_value($member['estimated_monthly_income'] ?? null) ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td colspan="6" style="text-align: right; font-weight: 700;">Total Estimated Monthly Family Income</td>
              <td><?= export_h(export_fill(aics_income_value($row['family_total_income'] ?? null) ?? '')) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div style="padding: 10px 14px; font-size: 10.5px; color: #64748b; border-top: 1px solid var(--line-soft);">
      Generated on <?= export_h(date('F d, Y h:i A')) ?>
    </div>
  </div>

  <?php if ($isPdfExport): ?>
  <script>
    window.addEventListener('load', function () {
      var el = document.getElementById('export-sheet');

      // Safety fallback: if html2pdf fails or hangs, close the window after 15s.
      var safetyTimer = setTimeout(function () {
        try { window.close(); } catch (e) {}
      }, 15000);

      html2pdf().set({
        margin: [10, 12, 12, 12],
        filename: '<?= $safeRef ?>-personal-form.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, logging: false, windowWidth: 1180 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
        pagebreak: { mode: ['avoid-all', 'css', 'legacy'], avoid: ['tr', '.section-title', 'table.family'] }
      }).from(el).save().then(function () {
        clearTimeout(safetyTimer);
        // Short delay so the browser's download dialog has a chance to fire.
        setTimeout(function () {
          try { window.close(); } catch (e) {}
        }, 600);
      }).catch(function () {
        clearTimeout(safetyTimer);
        try { window.close(); } catch (e) {}
      });
    });
  </script>
  <?php endif; ?>
</body>
</html>
