<?php
declare(strict_types=1);

// When "Same as Applicant" is not checked, the applicant says how they are
// related to the beneficiary. It is saved with the request and shown to PSWDO.
require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

function relationship_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$refused = static function (array $post): string {
    try {
        aics_applicant_relationship_from_post($post);
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
    return '';
};

// Server rules.
relationship_assert(aics_applicant_relationship_from_post(['applicant_is_beneficiary' => '1']) === 'Self', 'Same as Applicant saves "Self".');
relationship_assert(aics_applicant_relationship_from_post(['applicant_is_beneficiary' => '1', 'applicant_relationship' => 'Parent']) === 'Self', 'Same as Applicant ignores a stale relationship.');
relationship_assert(aics_applicant_relationship_from_post(['applicant_relationship' => 'Parent']) === 'Parent', 'A chosen relationship is saved.');
relationship_assert(str_contains($refused([]), 'relationship to the beneficiary'), 'A missing relationship is refused when the applicant is not the beneficiary.');
relationship_assert(str_contains($refused(['applicant_relationship' => 'Boss']), 'relationship to the beneficiary'), 'Only listed relationships are accepted.');
relationship_assert(!in_array('Self', aics_applicant_relationship_options(), true), '"Self" comes only from Same as Applicant.');

// Form.
$root = __DIR__ . '/..';
$submit = (string) file_get_contents($root . '/Applicants/php_files/submit-request.php');
$js = (string) file_get_contents($root . '/Applicants/js files/applicant-pages.js');
$save = (string) file_get_contents($root . '/Applicants/php_files/save-request.php');
$draft = (string) file_get_contents($root . '/Applicants/php_files/draft-lib.php');
$applicantStep = substr($submit, (int) strpos($submit, '<section class="intake-step" data-step="1">'), 6000);
relationship_assert(str_contains($applicantStep, 'id="applicant-relationship-field"') && str_contains($applicantStep, 'name="applicant_relationship"'), 'The Applicant Information step asks for the relationship.');
relationship_assert(str_contains($submit, 'aics_applicant_relationship_options()'), 'The form lists the same relationships the server accepts.');
relationship_assert(str_contains($submit, 'id="modal-confirm-applicant-relationship"'), 'The review shows the relationship.');
relationship_assert(str_contains($js, 'function syncApplicantRelationship()') && str_contains($js, 'applicantRelationshipField.hidden = isSelf;') && str_contains($js, 'applicantRelationship.disabled = isSelf;'), 'The field hides (and is not required) when Same as Applicant is checked.');
relationship_assert(str_contains($js, 'assignValue("applicant_relationship", values.applicant_relationship);'), 'Editing a request restores the relationship.');
relationship_assert(str_contains($draft, "'applicant_relationship',"), 'Drafts keep the relationship.');

// Saving and PSWDO.
relationship_assert(str_contains($save, "'applicant_relationship' => aics_applicant_relationship_from_post(\$post)"), 'Submissions validate the relationship.');
relationship_assert(str_contains($save, 'applicant_relationship = ?'), 'Submissions save the relationship.');
relationship_assert(str_contains((string) file_get_contents($root . '/Applicants/php_files/auth-common.php'), 'ADD COLUMN IF NOT EXISTS applicant_relationship VARCHAR(40)'), 'Older databases get the column.');
$ensure = 'aics_ensure_request_intake_columns($connection);';
$ensureBody = substr($save, (int) strpos($save, 'function ensure_tables_exist('), 6000);
relationship_assert(str_contains($ensureBody, $ensure), 'The column is created before the submission transaction.');
relationship_assert(str_contains($submit, $ensure) && strpos($submit, $ensure) < strpos($submit, 'r.applicant_relationship'), 'The edit form creates the column before reading it.');
relationship_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/pswdo-common.php'), $ensure), 'PSWDO pages create the column too.');
relationship_assert(str_contains((string) file_get_contents($root . '/tools/ai-gate-sync.php'), $ensure), 'Container start creates the column.');
relationship_assert(str_contains((string) file_get_contents($root . '/database/AICSdb.sql'), '`applicant_relationship` VARCHAR(40)'), 'New databases have the column.');
relationship_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/request-management.php'), 'Relationship to Beneficiary</span>'), 'PSWDO sees the relationship in the Applicant section.');
relationship_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/print-gis.php'), 'Relationship to Beneficiary</label>'), 'The printed GIS shows the relationship.');
echo "Applicant relationship to beneficiary checks passed.\n";
