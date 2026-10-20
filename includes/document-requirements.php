<?php
declare(strict_types=1);

/**
 * Canonical applicant document matrix. `code` is the persisted upload role;
 * each OR requirement has one role, so one accepted upload satisfies its group.
 *
 * @return array<string,array<int,array{code:string,label:string,required:bool,content_classification_applicable:bool,alteration_analysis_applicable:bool}>>
 */
function aics_document_requirements(): array
{
    $document = static fn (string $code, string $label, bool $classification = true): array => ['code' => $code, 'label' => $label, 'required' => true, 'content_classification_applicable' => $classification, 'alteration_analysis_applicable' => true];
    return [
        'medical' => [$document('valid_id', 'Valid Identification Card'), $document('med_cert', 'Medical Abstract or Medical Certificate'), $document('billing_statement', 'Billing Statement or Prescription')],
        'burial' => [$document('valid_id', 'Valid Identification Card'), $document('death_cert', 'Death Certificate'), $document('funeral_contract', 'Funeral Contract')],
        'special_cases' => [$document('valid_id', 'Valid Identification Card'), $document('report_police_med', 'Police Report or Medical Report')],
        'educational_basic' => [$document('valid_id', 'Valid Identification Card'), $document('registration_form', 'School ID or Enrollment Form')],
        'disaster_victims' => [$document('valid_id', 'Valid Identification Card'), $document('damaged_house_photo', 'Photo of Damaged Property', false)],
        'high_inflation_affected' => [$document('valid_id', 'Valid Identification Card')],
    ];
}

/** Resolves historical slot identifiers without changing stored records. */
function aics_normalize_document_type(string $type): string
{
    $type = strtolower(trim($type));
    $aliases = [
        'valid_identification_card' => 'valid_id', 'medical_certificate' => 'med_cert', 'medical_abstract' => 'med_cert', 'medical_abstract_certificate' => 'med_cert',
        'death_certificate' => 'death_cert', 'billing_statement_or_prescription' => 'billing_statement', 'damage_photos' => 'damaged_house_photo',
        'picture_of_damaged_property' => 'damaged_house_photo', 'inflation_crisis_cert' => 'crisis_situation_cert',
        'certificate_person_in_crisis' => 'crisis_situation_cert', 'certificate_of_person_in_crisis_situation' => 'crisis_situation_cert', 'coe_mswdo' => 'crisis_situation_cert',
    ];
    return $aliases[$type] ?? $type;
}

/** @return array<int,array{code:string,label:string,required:bool,content_classification_applicable:bool,alteration_analysis_applicable:bool}> */
function aics_document_requirements_for(string $assistanceCode): array { return aics_document_requirements()[strtolower(trim($assistanceCode))] ?? []; }
/** @return array<int,string> */
function aics_required_document_codes(string $assistanceCode): array { return array_values(array_map(static fn (array $r): string => $r['code'], array_filter(aics_document_requirements_for($assistanceCode), static fn (array $r): bool => $r['required']))); }
/** @return array<int,string> */
function aics_allowed_document_codes(string $assistanceCode): array { $required = aics_required_document_codes($assistanceCode); return $required === [] ? [] : [...$required, 'valid_id_back']; }
function aics_document_type_is_verifiable(string $type): bool { $type = aics_normalize_document_type($type); if (in_array($type, ['mdrrmo_cert', 'school_assessment'], true)) return true; foreach (aics_document_requirements() as $requirements) foreach ($requirements as $requirement) if ($requirement['code'] === $type) return true; return false; }
function aics_document_type_content_classification_applies(string $type): bool { foreach (aics_document_requirements() as $requirements) foreach ($requirements as $requirement) if ($requirement['code'] === aics_normalize_document_type($type)) return $requirement['content_classification_applicable']; return true; }
function aics_document_requirement_label(string $code): string { $code = aics_normalize_document_type($code); if ($code === 'valid_id_back') return 'Back of Applicant ID'; if ($code === 'mdrrmo_cert') return 'MDRRMO Certification of Damage or Displacement'; if ($code === 'school_assessment') return 'School Assessment Form'; foreach (aics_document_requirements() as $requirements) foreach ($requirements as $requirement) if ($requirement['code'] === $code) return $requirement['label']; return ucwords(str_replace('_', ' ', $code)); }
