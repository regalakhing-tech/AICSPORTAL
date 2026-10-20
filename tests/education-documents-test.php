<?php
declare(strict_types=1);

// Educational assistance asks the beneficiary for one document only: a School ID
// or Enrollment Form. The School Assessment Form and the optional Certificate of
// Person in Crisis Situation are no longer requested.
require_once __DIR__ . '/../includes/document-requirements.php';

function education_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$labels = array_map(static fn (array $r): string => $r['label'], aics_document_requirements_for('educational_basic'));
education_assert($labels === ['Valid Identification Card', 'School ID or Enrollment Form'], 'Education asks for the applicant ID and a School ID or Enrollment Form only.');

$root = __DIR__ . '/..';
$js = (string) file_get_contents($root . '/Applicants/js files/applicant-pages.js');
$requirementsJs = substr($js, (int) strpos($js, 'const AICS_REQUIREMENTS = {'), 800);
education_assert(!str_contains($requirementsJs, 'educational_basic'), 'The form uses the server list for education (no extra documents).');
education_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/pswdo-common.php'), "['registration_form', 'School ID or Enrollment Form'"), 'PSWDO shows the new document name.');
$catalog = (string) file_get_contents($root . '/ai_processor/gemini_scanner/catalog.py');
education_assert(str_contains($catalog, "'label': 'School ID or Enrollment Form'"), 'AICS AI knows the new document name.');
echo "Education document checks passed.\n";
