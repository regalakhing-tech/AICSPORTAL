<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/document-requirements.php';
function matrix_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$expected = [
    'medical' => ['valid_id', 'med_cert', 'billing_statement'], 'burial' => ['valid_id', 'death_cert', 'funeral_contract'],
    'special_cases' => ['valid_id', 'report_police_med'], 'educational_basic' => ['valid_id', 'school_id', 'registration_form'],
    'disaster_victims' => ['valid_id', 'damaged_house_photo'],
    'high_inflation_affected' => ['valid_id'],
];
foreach ($expected as $assistance => $codes) matrix_assert(aics_required_document_codes($assistance) === $codes, "Requirement matrix mismatch: $assistance");
foreach (array_keys($expected) as $assistance) {
    matrix_assert(in_array('valid_id_back', aics_allowed_document_codes($assistance), true), "The back of the applicant ID is not accepted for $assistance.");
    matrix_assert(!in_array('valid_id_back', aics_required_document_codes($assistance), true), "The ID back must not count as a separate required document for $assistance.");
}
matrix_assert(aics_document_requirement_label('valid_id_back') === 'Back of Applicant ID', 'The applicant ID back has no clear review label.');
matrix_assert(aics_document_requirements_for('former_rebels') === [], 'Former Rebels remains an active assistance category.');
matrix_assert(!in_array('coe_mswdo', aics_required_document_codes('special_cases'), true), 'Certificate of Eligibility/CPCS must not be a Special Cases applicant upload.');
matrix_assert(aics_normalize_document_type('damage_photos') === 'damaged_house_photo', 'Historical damaged-photo alias is not retained.');
matrix_assert(aics_document_requirement_label('damaged_house_photo') === 'Photo of Damaged Property', 'Beneficiary damage photo label is incorrect.');
matrix_assert(aics_document_type_is_verifiable('mdrrmo_cert'), 'Previously uploaded MDRRMO certificates must remain reviewable.');
matrix_assert(aics_document_requirement_label('mdrrmo_cert') === 'MDRRMO Certification of Damage or Displacement', 'Historical MDRRMO certificate label changed.');
matrix_assert(!aics_document_type_content_classification_applies('damaged_house_photo'), 'Damaged-house photo must not require document classification.');
matrix_assert(aics_document_requirement_label('school_id') === 'School ID' && aics_document_requirement_label('registration_form') === 'Enrollment Form', 'Education asks for a School ID and an Enrollment Form.');
matrix_assert(aics_document_type_is_verifiable('school_assessment') && aics_document_requirement_label('school_assessment') === 'School Assessment Form', 'Previously uploaded School Assessment Forms must remain reviewable.');
echo "All-category document requirement matrix test passed.\n";
