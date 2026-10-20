<?php
declare(strict_types=1);

// Educational assistance asks the beneficiary for two documents: a School ID and
// an Enrollment Form. The School Assessment Form and the optional Certificate of
// Person in Crisis Situation are no longer requested.
require_once __DIR__ . '/../includes/document-requirements.php';

function education_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$labels = array_map(static fn (array $r): string => $r['label'], aics_document_requirements_for('educational_basic'));
education_assert($labels === ['Valid Identification Card', 'School ID', 'Enrollment Form'], 'Education asks for the applicant ID, a School ID and an Enrollment Form.');

$root = __DIR__ . '/..';
$js = (string) file_get_contents($root . '/Applicants/js files/applicant-pages.js');
$requirementsJs = substr($js, (int) strpos($js, 'const AICS_REQUIREMENTS = {'), 800);
education_assert(!str_contains($requirementsJs, 'educational_basic'), 'The form uses the server list for education (no extra documents).');
$pswdo = (string) file_get_contents($root . '/Pswdo/php_files/pswdo-common.php');
education_assert(str_contains($pswdo, "['school_id', 'School ID'") && str_contains($pswdo, "['registration_form', 'Enrollment Form'"), 'PSWDO shows both document names.');
education_assert(str_contains((string) file_get_contents($root . '/includes/document-ai-reviews.php'), "'school_id'"), 'AICS AI checks School IDs.');
$catalog = (string) file_get_contents($root . '/ai_processor/gemini_scanner/catalog.py');
education_assert(str_contains($catalog, "'school_id': {") && str_contains($catalog, "'label': 'School ID'") && str_contains($catalog, "'label': 'Enrollment Form'"), 'AICS AI knows both documents.');
$landing = (string) file_get_contents($root . '/deploy/site/landingpage.php');
education_assert(str_contains($landing, '<strong>School ID:</strong>') && str_contains($landing, '<strong>Enrollment Form:</strong>'), 'The landing page lists both documents.');

// PSWDO reads the required documents from the database: retired ones must go.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
require_once __DIR__ . '/http-regression-db.php';
education_assert(AICS_DB_NAME === AICS_HTTP_REGRESSION_DB && getenv('AICS_HTTP_TEST_MODE') === '1', 'Use only the isolated regression database.');
aics_http_regression_create_database();
$c = aics_db_connect();
pswdo_ensure_schema($c);
$c->query("INSERT IGNORE INTO aics_document_types (document_code, document_name, sort_order) VALUES ('school_assessment', 'School Assessment Form', 115)");
$c->query("INSERT IGNORE INTO aics_assistance_required_documents (assistance_type_id, document_type_id, sort_order) SELECT at.id, dt.id, 9 FROM aics_assistance_types at, aics_document_types dt WHERE at.assistance_code = 'educational_basic' AND dt.document_code = 'school_assessment'");
pswdo_ensure_schema($c);
education_assert((pswdo_required_documents_by_assistance($c)['educational_basic'] ?? []) === ['valid_id', 'school_id', 'registration_form'], 'PSWDO lists the School ID and Enrollment Form, and no retired documents.');
$c->close();
echo "Education document checks passed.\n";
