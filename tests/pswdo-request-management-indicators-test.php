<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

function indicator_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$cases = [
    ['[]', 'STANDARD', 'educational_basic', 'STANDARD', 'priority-chip-normal'],
    ['[]', 'STANDARD', 'burial', 'STANDARD', 'priority-chip-normal'],
    ['[]', 'URGENT', 'medical', 'URGENT', 'priority-chip-urgent'],
    ['[]', 'STANDARD', 'medical', 'STANDARD', 'priority-chip-normal'],
    ['[]', 'REQUIRES_STAFF_REVIEW', 'medical', 'STANDARD', 'priority-chip-normal'],
    ['["senior_citizen"]', 'URGENT', 'burial', 'STANDARD', 'priority-chip-normal'],
    ['["senior_citizen"]', 'REQUIRES_STAFF_REVIEW', 'medical', 'HIGH', 'priority-chip-request-high'],
    ['["pwd"]', 'STANDARD', 'medical', 'HIGH', 'priority-chip-request-high'],
    ['["senior_citizen","pwd"]', 'URGENT', 'medical', 'URGENT', 'priority-chip-urgent'],
    ['invalid json', 'URGENT', 'medical', 'URGENT', 'priority-chip-urgent'],
];

foreach ($cases as [$categories, $urgency, $assistance, $expected, $expectedClass]) {
    $indicator = pswdo_request_management_indicator($categories, $urgency, $assistance);
    indicator_assert($indicator['label'] === $expected, "Wrong {$assistance} indicator for {$categories}/{$urgency}.");
    indicator_assert($indicator['class'] === $expectedClass, "Wrong {$assistance} badge color for {$categories}/{$urgency}.");
}

$datedFindings = 'Gemini priority analysis found sufficient current medical urgency. Surgery is scheduled for October 8, 2026. Findings: Physician recommendation.';
indicator_assert(
    pswdo_priority_explanation_for_display($datedFindings) === 'Surgery is scheduled for October 8, 2026. Findings: Physician recommendation.',
    'Priority display must omit the provider preface and retain a documented medical date.'
);
indicator_assert(
    pswdo_priority_explanation_for_display('Gemini priority analysis found sufficient current medical urgency. Current weakness requires assistance.') === 'Current weakness requires assistance.',
    'Priority display must not invent a date when findings contain none.'
);

$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
indicator_assert(is_string($page), 'Request Management page is unavailable.');
indicator_assert(substr_count($page, 'pswdo_request_management_indicator(') === 2, 'Both request table and detail panel must show the same indicator.');
indicator_assert(substr_count($page, "(string) (\$request['client_categories_json'] ?? '[]')") >= 2, 'Both indicators must use stored beneficiary categories.');
indicator_assert(str_contains($page, 'pswdo_priority_explanation_for_display($priorityExplanation)'), 'Request detail must format the stored findings for display.');

echo "Request Management priority indicators passed.\n";
