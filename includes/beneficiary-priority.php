<?php
declare(strict_types=1);
require_once __DIR__ . '/smart-priority-medical-analysis.php';

/** The approved beneficiary-category portion of Smart Prioritization. */
function aics_current_high_priority_beneficiary_categories(): array
{
    return ['senior_citizen', 'pwd'];
}

function aics_priority_from_beneficiary_categories(array $beneficiaryCategories): array
{
    $categories = array_map(static fn ($value): string => strtolower(trim((string) $value)), $beneficiaryCategories);
    if (array_intersect($categories, aics_current_high_priority_beneficiary_categories()) !== []) return ['score' => 1, 'label' => 'HIGH'];
    return ['score' => 0, 'label' => 'NORMAL'];
}

function aics_normalize_base_priority(string $basePriority): string
{
    $normalized = strtoupper(trim($basePriority));
    return $normalized === 'HIGH' || preg_match('/^HIGH\s+PRIORITY(?:\s|$)/', $normalized) === 1 ? 'HIGH' : 'NORMAL';
}

function aics_normalize_medical_urgency(string $medicalUrgency): string
{
    return match (strtoupper(trim($medicalUrgency))) {
        'URGENT' => 'URGENT',
        'HIGH' => 'HIGH',
        'STANDARD' => 'STANDARD',
        default => 'REQUIRES_STAFF_REVIEW',
    };
}

/** Lower values are handled first; uncertain evidence is never promoted. */
function aics_smart_priority_tier(string $basePriority, string $medicalUrgency): int
{
    if (aics_smart_priority_analysis_enabled()) {
        return match (aics_normalize_medical_urgency($medicalUrgency)) {
            'URGENT' => 0,
            'HIGH' => 1,
            'REQUIRES_STAFF_REVIEW' => aics_normalize_base_priority($basePriority) === 'HIGH' ? 1 : 2,
            default => 2,
        };
    }
    $base = aics_normalize_base_priority($basePriority);
    $urgency = aics_normalize_medical_urgency($medicalUrgency);

    return match ([$base, $urgency]) {
        ['HIGH', 'URGENT'] => 0,
        ['NORMAL', 'URGENT'] => 1,
        ['HIGH', 'STANDARD'] => 2,
        ['NORMAL', 'STANDARD'] => 3,
        ['HIGH', 'REQUIRES_STAFF_REVIEW'] => 4,
        default => 5,
    };
}

function aics_smart_priority_label(string $basePriority, string $medicalUrgency): string
{
    if (aics_smart_priority_analysis_enabled()) {
        return match (aics_smart_priority_tier($basePriority, $medicalUrgency)) {
            0 => 'URGENT', 1 => 'HIGH', default => 'STANDARD',
        };
    }
    return match (aics_smart_priority_tier($basePriority, $medicalUrgency)) {
        0 => 'URGENT MEDICAL + SENIOR/PWD',
        1 => 'URGENT MEDICAL',
        2 => 'SENIOR/PWD PRIORITY',
        3 => 'STANDARD',
        4 => 'SENIOR/PWD - REVIEW NEEDED',
        default => 'REVIEW NEEDED',
    };
}

/**
 * Returns an established beneficiary category/age validation message, if any.
 * PWD intentionally has no age restriction and may overlap Senior Citizen.
 */
function aics_beneficiary_category_age_error(int $age, string $category): ?string
{
    $category = strtolower(trim($category));
    if ($category === 'senior_citizen' && $age < 60) {
        return 'Senior Citizen can only be selected for beneficiaries aged 60 years or older.';
    }
    if ($category === 'youth' && $age >= 60) {
        return 'The selected beneficiary category is inconsistent with the beneficiary\'s age.';
    }
    return null;
}

/**
 * Classifies pre-filtered Medical Certificate and billing/prescription review rows.
 * Only reliable, completed evidence with explicit timing can produce URGENT.
 *
 * @param array<int,array<string,mixed>> $reviewRows
 * @return array{indicator:string,basis:string}
 */
function aics_medical_urgency_from_review_rows(array $reviewRows, string $assistanceType): array
{
    if (strtolower(trim($assistanceType)) !== 'medical') {
        return ['indicator' => 'STANDARD', 'basis' => 'Not applicable outside Medical Assistance.'];
    }

    $reliableReviews = 0;
    $uncertain = false;
    $urgentReason = '';
    foreach ($reviewRows as $row) {
        if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'completed' || (float) ($row['confidence_score'] ?? 0) < 60) {
            $uncertain = true;
            continue;
        }

        $reliableReviews++;
        $rawFields = $row['extracted_fields'] ?? '';
        $fields = is_array($rawFields) ? $rawFields : json_decode((string) $rawFields, true);
        $evidence = is_array($fields) ? ($fields['urgency_evidence'] ?? null) : null;
        if (!is_array($evidence)) {
            $uncertain = true;
            continue;
        }

        $evidenceStatus = strtolower(trim((string) ($evidence['status'] ?? '')));
        if ($evidenceStatus === 'not_present') {
            continue;
        }
        if ($evidenceStatus !== 'extracted' || (float) ($evidence['confidence'] ?? 0) < .70) {
            $uncertain = true;
            continue;
        }

        $reason = trim((string) ($evidence['value'] ?? ''));
        if ($reason === '') {
            $uncertain = true;
            continue;
        }
        if ($urgentReason === '') $urgentReason = $reason;
    }

    if ($uncertain || $reliableReviews === 0) {
        return ['indicator' => 'REQUIRES_STAFF_REVIEW', 'basis' => 'Medical document information is missing, unreadable, inconsistent, or not reliable enough to determine urgency.'];
    }

    if ($urgentReason !== '') {
        return ['indicator' => 'URGENT', 'basis' => 'Medical document indicates ' . $urgentReason . '.'];
    }

    return ['indicator' => 'STANDARD', 'basis' => 'Verified medical documents contain no explicit time-sensitive medical need.'];
}

/**
 * Uses only completed, reliable findings from the existing document-verification flow.
 * No urgency is inferred from a diagnosis, disease name, or applicant response.
 * @return array{indicator:string,basis:string}
 */
function aics_medical_urgency_from_document_reviews(mysqli $connection, int $requestId, string $assistanceType): array
{
    if (strtolower(trim($assistanceType)) !== 'medical') {
        return ['indicator' => 'STANDARD', 'basis' => 'Not applicable outside Medical Assistance.'];
    }

    $stmt = $connection->prepare("SELECT ai.status, ai.confidence_score, ai.extracted_fields FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id WHERE d.request_id = ? AND ai.expected_document_type IN ('med_cert', 'billing_statement')");
    if (!$stmt) {
        return ['indicator' => 'REQUIRES_STAFF_REVIEW', 'basis' => 'Medical document findings are unavailable for review.'];
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $reviewRows = [];
    while ($row = $result?->fetch_assoc()) {
        $reviewRows[] = $row;
    }
    $stmt->close();

    return aics_medical_urgency_from_review_rows($reviewRows, $assistanceType);
}

/** @return array{response_required:bool,question:string} Legacy intake compatibility; not used for prioritization. */
function aics_urgency_question_for_assistance(string $assistanceType): array
{
    return ['response_required' => false, 'question' => ''];
}

/** @return array{response:?string,indicator:string} */
function aics_urgency_from_structured_response(string $assistanceType, ?string $response): array
{
    $question = aics_urgency_question_for_assistance($assistanceType);
    $normalized = strtolower(trim((string) $response));
    if (!$question['response_required']) return ['response' => null, 'indicator' => 'REQUIRES_STAFF_REVIEW'];
    if (!in_array($normalized, ['yes', 'no'], true)) {
        throw new InvalidArgumentException('Select Yes or No for the urgency indicator.');
    }
    return ['response' => $normalized, 'indicator' => $normalized === 'yes' ? 'Urgent' : 'Standard'];
}
