<?php
declare(strict_types=1);
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=false');
require_once __DIR__ . '/../includes/beneficiary-priority.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';
function priority_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
priority_assert(aics_priority_from_beneficiary_categories(['senior_citizen'])['label'] === 'HIGH', 'Senior Citizen must receive HIGH base priority.');
priority_assert(aics_priority_from_beneficiary_categories(['pwd'])['label'] === 'HIGH', 'PWD must receive HIGH base priority.');
priority_assert(aics_priority_from_beneficiary_categories(['youth'])['label'] === 'NORMAL', 'Other categories must receive NORMAL base priority.');
priority_assert(aics_smart_priority_tier('HIGH', 'URGENT') === 0, 'Urgent Senior/PWD must be the first Smart Priority tier.');
priority_assert(aics_smart_priority_tier('NORMAL', 'URGENT') === 1, 'Verified urgent medical need must outrank standard category priority.');
priority_assert(aics_smart_priority_tier('NORMAL', 'URGENT') < aics_smart_priority_tier('HIGH', 'STANDARD'), 'Urgent medical must outrank Senior/PWD standard need.');
priority_assert(aics_smart_priority_tier('HIGH', 'STANDARD') < aics_smart_priority_tier('NORMAL', 'STANDARD'), 'Senior/PWD must break ties between standard cases.');
priority_assert(aics_smart_priority_tier('HIGH', 'REQUIRES_STAFF_REVIEW') > aics_smart_priority_tier('NORMAL', 'STANDARD'), 'Uncertain evidence must not be automatically promoted above verified standard cases.');
priority_assert(aics_smart_priority_tier('High Priority - PWD', 'urgent') === 0, 'Historical high-priority labels must normalize into the canonical urgent tier.');
priority_assert(aics_normalize_base_priority('HIGHLIGHT') === 'NORMAL', 'Unrelated HIGH-prefixed values must not become high priority.');
priority_assert(aics_smart_priority_label('HIGH', 'URGENT') === 'URGENT MEDICAL + SENIOR/PWD', 'Combined urgent/category label mismatch.');
priority_assert(aics_smart_priority_label('NORMAL', 'URGENT') === 'URGENT MEDICAL', 'Urgent medical label mismatch.');
priority_assert(aics_smart_priority_label('HIGH', 'STANDARD') === 'SENIOR/PWD PRIORITY', 'Senior/PWD standard label mismatch.');
priority_assert(aics_smart_priority_label('NORMAL', 'STANDARD') === 'STANDARD', 'Standard label mismatch.');
priority_assert(aics_smart_priority_label('NORMAL', 'REQUIRES_STAFF_REVIEW') === 'REVIEW NEEDED', 'Review-needed label mismatch.');
$urgentFromDocuments = aics_medical_urgency_from_review_rows([[
    'status' => 'completed',
    'confidence_score' => 92,
    'extracted_fields' => json_encode(['urgency_evidence' => [
        'status' => 'extracted',
        'confidence' => 0.88,
        'value' => 'scheduled surgery on 2026-09-25',
    ]]),
]], 'medical');
priority_assert($urgentFromDocuments['indicator'] === 'URGENT', 'Reliable scheduled-surgery evidence must produce urgent medical priority.');
priority_assert(str_contains($urgentFromDocuments['basis'], 'scheduled surgery'), 'Urgent priority basis must retain the document reason.');
$standardFromDocuments = aics_medical_urgency_from_review_rows([[
    'status' => 'completed',
    'confidence_score' => 92,
    'extracted_fields' => json_encode([
        'diagnosis_present' => ['status' => 'extracted', 'value' => true],
        'urgency_evidence' => ['status' => 'not_present', 'confidence' => 0, 'value' => null],
    ]),
]], 'medical');
priority_assert($standardFromDocuments['indicator'] === 'STANDARD', 'Diagnosis alone must not produce urgent medical priority.');
$uncertainFromDocuments = aics_medical_urgency_from_review_rows([[
    'status' => 'needs_staff_review',
    'confidence_score' => 75,
    'extracted_fields' => json_encode(['urgency_evidence' => [
        'status' => 'extracted',
        'confidence' => 0.88,
        'value' => 'scheduled surgery',
    ]]),
]], 'medical');
priority_assert($uncertainFromDocuments['indicator'] === 'REQUIRES_STAFF_REVIEW', 'Uncertain document reviews must not auto-promote an application to urgent.');
$malformedDocumentEvidence = aics_medical_urgency_from_review_rows([[
    'status' => 'completed',
    'confidence_score' => 92,
    'extracted_fields' => '{malformed json',
]], 'medical');
priority_assert($malformedDocumentEvidence['indicator'] === 'REQUIRES_STAFF_REVIEW', 'Malformed medical evidence must require staff review.');
$missingUrgencyEvidence = aics_medical_urgency_from_review_rows([[
    'status' => 'completed',
    'confidence_score' => 92,
    'extracted_fields' => json_encode(['diagnosis_present' => ['status' => 'extracted', 'value' => true]]),
]], 'medical');
priority_assert($missingUrgencyEvidence['indicator'] === 'REQUIRES_STAFF_REVIEW', 'Missing urgency evidence must require staff review.');
$uncertainEvidenceStatus = aics_medical_urgency_from_review_rows([[
    'status' => 'completed',
    'confidence_score' => 92,
    'extracted_fields' => json_encode(['urgency_evidence' => [
        'status' => 'needs_staff_review',
        'confidence' => 0.88,
        'value' => 'scheduled surgery',
    ]]),
]], 'medical');
priority_assert($uncertainEvidenceStatus['indicator'] === 'REQUIRES_STAFF_REVIEW', 'Uncertain urgency-evidence status must require staff review.');
$lowConfidenceUrgencyEvidence = aics_medical_urgency_from_review_rows([[
    'status' => 'completed',
    'confidence_score' => 92,
    'extracted_fields' => json_encode(['urgency_evidence' => [
        'status' => 'extracted',
        'confidence' => 0.69,
        'value' => 'scheduled surgery',
    ]]),
]], 'medical');
priority_assert($lowConfidenceUrgencyEvidence['indicator'] === 'REQUIRES_STAFF_REVIEW', 'Low-confidence urgency evidence must require staff review.');
$urgentAndUncertainEvidence = aics_medical_urgency_from_review_rows([
    [
        'status' => 'completed',
        'confidence_score' => 92,
        'extracted_fields' => json_encode(['urgency_evidence' => [
            'status' => 'extracted',
            'confidence' => 0.88,
            'value' => 'scheduled surgery',
        ]]),
    ],
    [
        'status' => 'needs_staff_review',
        'confidence_score' => 75,
        'extracted_fields' => json_encode(['urgency_evidence' => [
            'status' => 'extracted',
            'confidence' => 0.88,
            'value' => 'emergency treatment',
        ]]),
    ],
], 'medical');
priority_assert($urgentAndUncertainEvidence['indicator'] === 'REQUIRES_STAFF_REVIEW', 'Urgent evidence must not override uncertainty in another medical document.');
priority_assert(!aics_urgency_question_for_assistance('medical')['response_required'], 'Applicant self-report must not determine medical urgency.');
priority_assert(aics_urgency_from_structured_response('medical', 'yes')['indicator'] === 'REQUIRES_STAFF_REVIEW', 'Self-reported urgency must remain a review state.');
priority_assert(pswdo_medical_urgency_label('URGENT') === 'Urgent', 'Urgent UI label mismatch.');
priority_assert(pswdo_medical_urgency_label('STANDARD') === 'Standard', 'Standard UI label mismatch.');
priority_assert(pswdo_medical_urgency_label('REQUIRES_STAFF_REVIEW') === 'Review Needed', 'Review-needed UI label mismatch.');
priority_assert(pswdo_priority_display_label('HIGH', 'REQUIRES_STAFF_REVIEW') === 'SENIOR/PWD - REVIEW NEEDED', 'Combined priority UI label mismatch.');
priority_assert(pswdo_priority_badge_meta('NORMAL', 'URGENT')['class'] === 'priority-chip-high', 'Urgent medical cases must use the strong priority badge even without Senior/PWD category.');
priority_assert(pswdo_priority_badge_meta('NORMAL', 'REQUIRES_STAFF_REVIEW')['class'] === 'priority-chip-review', 'Uncertain evidence must use the review badge.');
$requestManagementPage = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
$screeningQueuePage = file_get_contents(__DIR__ . '/../Pswdo/php_files/prescreening-queue.php');
priority_assert(is_string($requestManagementPage) && is_string($screeningQueuePage), 'Smart Priority PSWDO pages must be readable.');
foreach ([
    ['HIGH', 'URGENT', 'URGENT MEDICAL + SENIOR/PWD'],
    ['NORMAL', 'URGENT', 'URGENT MEDICAL'],
    ['HIGH', 'STANDARD', 'SENIOR/PWD PRIORITY'],
    ['NORMAL', 'STANDARD', 'STANDARD'],
    ['NORMAL', 'REQUIRES_STAFF_REVIEW', 'REVIEW NEEDED'],
] as [$basePriority, $urgency, $staffFacingState]) {
    priority_assert(pswdo_priority_display_label($basePriority, $urgency) === $staffFacingState, "PSWDO staff-facing priority label mismatch for {$staffFacingState}.");
}
priority_assert(substr_count($requestManagementPage, 'pswdo_request_management_indicator(') === 2, 'Request Management must use its three-state indicator in the table and detail panel.');
priority_assert(str_contains($screeningQueuePage, '>Smart Priority</option>'), 'The visible default queue sort must be labelled Smart Priority.');
priority_assert(str_contains($screeningQueuePage, 'Priority Cases Only'), 'The priority group action must be labelled Priority Cases Only.');
priority_assert(str_contains($screeningQueuePage, 'name="sort_by"') && str_contains($screeningQueuePage, 'name="sort_group"'), 'Queue sort query parameter keys must remain unchanged.');
priority_assert(aics_beneficiary_category_age_error(76, 'youth') !== null, 'Age 76 plus Youth must be blocked.');
priority_assert(aics_beneficiary_category_age_error(76, 'senior_citizen') === null, 'Age 76 plus Senior Citizen must be allowed.');
priority_assert(aics_beneficiary_category_age_error(76, 'pwd') === null, 'Age 76 plus PWD must be allowed.');
priority_assert(aics_beneficiary_category_age_error(59, 'senior_citizen') !== null, 'Age 59 plus Senior Citizen must be blocked.');
priority_assert(aics_beneficiary_category_age_error(35, 'pantawid') === null, 'Ordinary adult category must be allowed.');
priority_assert(aics_beneficiary_category_age_error(35, 'pwd') === null, 'PWD below 60 must be allowed.');
echo "Smart priority category, precedence, and safeguard checks passed.\n";
