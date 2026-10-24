<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

function print_report_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function render_print_partial(string $partial, array $variables): string
{
    print_report_assert(is_file($partial), 'Required print partial is missing: ' . basename($partial));
    extract($variables, EXTR_SKIP);
    ob_start();
    require $partial;
    return (string) ob_get_clean();
}

$base = __DIR__ . '/../Pswdo/php_files';

$header = render_print_partial($base . '/_report-print-header.php', [
    'printReportTitle' => 'Released Assistance Report',
    'printPeriodLabel' => 'September 1, 2026 to September 24, 2026',
    'printGeneratedAt' => 'September 24, 2026 at 5:08 PM',
    'printPreparedBy' => 'PSWDO Personnel',
]);

print_report_assert(str_contains($header, 'Provincial Social Welfare and Development Office'), 'The formal office heading must be printed.');
print_report_assert(str_contains($header, 'Released Assistance Report'), 'The report title must be printed.');
print_report_assert(str_contains($header, 'Report Period'), 'The reporting period needs a plain-language label.');
print_report_assert(str_contains($header, 'Prepared by'), 'The preparer must be identified.');
print_report_assert(!preg_match('/<(?:img|svg|i)\b/i', $header), 'The print header must not contain logos, icons, or symbol elements.');
print_report_assert(!str_contains($header, '&nbsp;|&nbsp;'), 'Print metadata must not use decorative separator symbols.');

$summary = render_print_partial($base . '/_report-print-summary.php', [
    'summary' => [
        'total_submitted' => 11,
        'approved' => 2,
        'not_approved' => 1,
        'released' => 2,
        'under_review' => 6,
    ],
]);

foreach ([
    'Total applications submitted' => '11',
    'Approved applications' => '2',
    'Applications not approved' => '1',
    'Released assistance' => '2',
    'Applications under review' => '6',
] as $label => $value) {
    print_report_assert(str_contains($summary, $label), 'Missing plain-language summary label: ' . $label);
    print_report_assert(str_contains($summary, '>' . $value . '<'), 'Missing summary value for: ' . $label);
}

print_report_assert(str_contains($summary, '<table'), 'The printable summary must use a formal table.');
print_report_assert(!preg_match('/<(?:img|svg|i)\b/i', $summary), 'The printable summary must not contain logos or icons.');
print_report_assert(!str_contains($summary, 'Notifications'), 'Notification activity must not appear in the printable summary.');
print_report_assert(!str_contains($summary, 'AI workflow'), 'Operational AI metrics must not appear in the printable summary.');

$failedSummary = render_print_partial($base . '/_report-print-summary.php', [
    'summary' => [],
    'loadError' => 'Unable to load reports right now.',
]);
print_report_assert(str_contains($failedSummary, 'Report unavailable'), 'A data-load failure must be stated in the printed report.');
print_report_assert(!str_contains($failedSummary, '<table'), 'A failed data load must not print authoritative-looking zero totals.');

$failedList = render_print_partial($base . '/_report-for-release-print-table.php', [
    'loadError' => 'Unable to load report data right now.',
    'reportItems' => [],
    'displayName' => 'PSWDO Personnel',
]);
print_report_assert(str_contains($failedList, 'Report unavailable'), 'A list data-load failure must be stated in print.');
print_report_assert(!str_contains($failedList, '<table'), 'A failed list must not print an empty official table.');
print_report_assert(!str_contains($failedList, 'class="report-signature"'), 'A failed list must not contain certification signatures.');

$emptyList = render_print_partial($base . '/_report-for-release-print-table.php', [
    'loadError' => '',
    'reportItems' => [],
    'displayName' => 'PSWDO Personnel',
]);
print_report_assert(str_contains($emptyList, 'No records found'), 'A valid empty list must be identified plainly.');
print_report_assert(str_contains($emptyList, 'class="report-signature"'), 'A valid empty list must retain its certification signatures.');

$releasedList = render_print_partial($base . '/_report-released-print-table.php', [
    'loadError' => '',
    'displayName' => 'PSWDO Personnel',
    'reportItems' => [[
        'reference_no' => 'AICS-2026-TEST01',
        'beneficiary_name' => 'Sample Beneficiary',
        'applicant_name' => 'Sample Applicant',
        'address' => 'Antique',
        'categories' => 'Individual',
        'assistance' => 'Medical Assistance',
        'date_applied' => 'September 1, 2026',
        'release_date' => 'September 24, 2026',
        'release_time' => '9:00 AM',
        'venue' => 'Provincial Office',
        'batch' => 'Batch 1',
        'amount_given' => 2500,
        'released_at' => 'September 24, 2026',
    ]],
]);
print_report_assert(!str_contains($releasedList, 'Amount Given') && !str_contains($releasedList, '2,500.00'), 'Released reports must not display cash amounts.');
print_report_assert(substr_count($releasedList, 'scope="col"') === 13, 'Every released-list column heading must have column scope.');

echo "PSWDO print report regression passed.\n";
