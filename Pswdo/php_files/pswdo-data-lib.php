<?php
declare(strict_types=1);

require_once __DIR__ . '/pswdo-common.php';
require_once __DIR__ . '/../../includes/ai-submission-gate.php';
require_once __DIR__ . '/../../includes/duplicate-detection.php';

/** The beneficiary's contact is separate from the person receiving AICS notices. */
function pswdo_applicant_sms_contact(array $row): string
{
    $savedContact = trim((string) ($row['applicant_contact_number'] ?? ''));
    return $savedContact !== ''
        ? $savedContact
        : trim((string) ($row['profile_contact_number'] ?? ''));
}

/** SQL CASE expression matching aics_smart_priority_tier(). */
function pswdo_smart_priority_order_case_sql(string $alias = 'pr'): string
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
        throw new InvalidArgumentException('Smart Priority SQL alias is invalid.');
    }

    if (aics_smart_priority_analysis_enabled()) {
        $urgency = "UPPER(TRIM(COALESCE({$alias}.urgency_indicator, '')))";
        $priority = "UPPER(TRIM(COALESCE({$alias}.priority_level, '')))";
        $pendingHigh = "({$priority} = 'HIGH' OR {$priority} REGEXP '^HIGH[[:space:]]+PRIORITY([[:space:]]|$)')";
        return "CASE WHEN {$urgency} = 'URGENT' THEN 0 WHEN {$urgency} = 'HIGH' OR ({$urgency} = 'REQUIRES_STAFF_REVIEW' AND {$pendingHigh}) THEN 1 ELSE 2 END";
    }

    $priorityLevel = "UPPER(TRIM(COALESCE({$alias}.priority_level, '')))";
    $base = "({$priorityLevel} = 'HIGH' OR {$priorityLevel} REGEXP '^HIGH[[:space:]]+PRIORITY([[:space:]]|$)')";
    $urgency = "UPPER(TRIM(COALESCE({$alias}.urgency_indicator, '')))";
    $highUrgent = aics_smart_priority_tier('HIGH', 'URGENT');
    $normalUrgent = aics_smart_priority_tier('NORMAL', 'URGENT');
    $highStandard = aics_smart_priority_tier('HIGH', 'STANDARD');
    $normalStandard = aics_smart_priority_tier('NORMAL', 'STANDARD');
    $highReview = aics_smart_priority_tier('HIGH', 'REQUIRES_STAFF_REVIEW');
    $normalReview = aics_smart_priority_tier('NORMAL', 'REQUIRES_STAFF_REVIEW');

    return "CASE
        WHEN {$base} AND {$urgency} = 'URGENT' THEN {$highUrgent}
        WHEN {$urgency} = 'URGENT' THEN {$normalUrgent}
        WHEN {$base} AND {$urgency} = 'STANDARD' THEN {$highStandard}
        WHEN {$urgency} = 'STANDARD' THEN {$normalStandard}
        WHEN {$base} THEN {$highReview}
        ELSE {$normalReview}
    END";
}

/** Compare hydrated PSWDO request rows using tier, oldest application, then ID. */
function pswdo_compare_smart_priority_rows(array $left, array $right): int
{
    $leftPriority = is_array($left['priority'] ?? null) ? $left['priority'] : $left;
    $rightPriority = is_array($right['priority'] ?? null) ? $right['priority'] : $right;
    $leftTier = aics_smart_priority_tier(
        (string) ($leftPriority['priority_level'] ?? 'NORMAL'),
        (string) ($leftPriority['urgency_indicator'] ?? 'REQUIRES_STAFF_REVIEW')
    );
    $rightTier = aics_smart_priority_tier(
        (string) ($rightPriority['priority_level'] ?? 'NORMAL'),
        (string) ($rightPriority['urgency_indicator'] ?? 'REQUIRES_STAFF_REVIEW')
    );
    if ($leftTier !== $rightTier) return $leftTier <=> $rightTier;

    $dateFor = static function (array $row): string {
        $applied = trim((string) ($row['date_applied'] ?? ''));
        if ($applied !== '' && !str_starts_with($applied, '0000-00-00')) return $applied;
        $created = trim((string) ($row['created_at'] ?? ''));
        return $created !== '' ? substr($created, 0, 10) : '9999-12-31';
    };
    $dateOrder = strcmp($dateFor($left), $dateFor($right));
    if ($dateOrder !== 0) return $dateOrder;

    $leftId = (int) ($left['id'] ?? $left['request_id'] ?? PHP_INT_MAX);
    $rightId = (int) ($right['id'] ?? $right['request_id'] ?? PHP_INT_MAX);
    return $leftId <=> $rightId;
}

/** @return array<string,mixed> */
function pswdo_effective_tamper_detection(string $findingsJson, string $supplementJson = ''): ?array
{
    $decoded = json_decode($findingsJson, true);
    $native = is_array($decoded) && is_array($decoded['tamper_detection'] ?? null) ? $decoded['tamper_detection'] : null;
    $supplement = json_decode($supplementJson, true);
    if (is_array($native) && ($native['status'] ?? '') === 'completed') return $native;
    if (is_array($supplement) && ($supplement['status'] ?? '') === 'completed') return $supplement;
    if (is_array($supplement)) return $supplement;
    return is_array($native) ? $native : null;
}

function pswdo_tamper_analysis_json(string $findingsJson, string $supplementJson = ''): array
{
    $decoded = json_decode($findingsJson, true);
    $raw = pswdo_effective_tamper_detection($findingsJson, $supplementJson);
    $historical = is_array($decoded) && count($decoded) > 0;
    $base = ['model' => 'Multi-signal alteration review', 'status' => 'not_analyzed', 'decision' => 'clear',
        'label' => 'Not yet analyzed', 'confidence_band' => 'low', 'evidence_families' => [],
        'indicators' => [], 'providers' => [], 'aggregate' => null, 'pages' => [],
        'message' => $historical ? 'Current document alteration analysis is being prepared.' : 'Waiting for AI image analysis.'];
    if (!is_array($raw)) return $historical
        ? array_merge($base, ['status' => 'legacy', 'label' => 'Earlier review', 'message' => 'This document was reviewed before the current image-review feature was available.'])
        : $base;
    $status = strtolower(trim((string) ($raw['status'] ?? '')));
    if ($status === 'unavailable') {
        $errorCode = strtolower(trim((string) ($raw['error_code'] ?? '')));
        $base['status'] = 'unavailable';
        $base['message'] = in_array($errorCode, ['ai_document_file_not_found', 'document_file_not_found'], true)
            ? 'The original uploaded file is no longer available, so the image review could not be completed.'
            : 'The image review could not be completed. Document identification results remain available for staff review.';
        $base['staff_guidance'] = 'Continue with the available Document Check and manually review any remaining document copy if available.';
        return $base;
    }
    if ($status !== 'completed') { $base['status'] = 'unknown'; $base['message'] = 'Alteration analysis result is unavailable. Manual document review is required.'; return $base; }
    $decision = strtolower(trim((string) ($raw['decision'] ?? 'clear'))) === 'review' ? 'review' : 'clear';
    $reviewBasis = in_array(($raw['review_basis'] ?? ''), ['none', 'deterministic_chronology_conflict', 'corroborated_visual_evidence', 'local_copied_region', 'cryptographic_signature_problem', 'generative_ai_provenance', 'ai_generated_image'], true)
        ? (string) $raw['review_basis'] : 'none';
    $base['status'] = 'completed';
    $base['decision'] = $decision;
    $base['review_basis'] = $reviewBasis;
    $base['label'] = $decision === 'review'
        ? ($reviewBasis === 'deterministic_chronology_conflict'
            ? 'Document date inconsistency — staff review required'
            : 'Suspected alteration — staff review required')
        : 'No corroborated alteration signal';
    if ($decision === 'review' && $reviewBasis === 'cryptographic_signature_problem') $base['label'] = 'PDF signature requires staff review';
    if ($decision === 'review' && $reviewBasis === 'generative_ai_provenance') $base['label'] = 'Made or edited with generative AI — staff review required';
    if ($decision === 'review' && $reviewBasis === 'ai_generated_image') $base['label'] = 'Possibly AI-generated image — staff review required';
    $base['confidence_band'] = in_array(($raw['confidence_band'] ?? ''), ['low', 'moderate', 'high'], true) ? (string) $raw['confidence_band'] : 'low';
    $rawDateConflict = is_array($raw['date_conflict'] ?? null) ? $raw['date_conflict'] : null;
    if ($reviewBasis === 'deterministic_chronology_conflict' && $rawDateConflict !== null) {
        $documentDate = trim((string) ($rawDateConflict['document_date'] ?? ''));
        $years = array_values(array_unique(array_filter(
            array_map('intval', (array) ($rawDateConflict['conflicting_years'] ?? [])),
            static fn (int $year): bool => $year >= 1900 && $year <= 2100
        )));
        sort($years);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate) === 1 && $years !== []) {
            $base['date_conflict'] = ['document_date' => $documentDate, 'conflicting_years' => array_slice($years, 0, 5)];
        }
    }
    $allowedFamilies = ['gemini_visual', 'semantic_chronology', 'local_regional', 'local_visual', 'adcd_net', 'file_provenance', 'gemini_ai_check'];
    $base['evidence_families'] = array_values(array_intersect($allowedFamilies, array_map('strval', (array) ($raw['evidence_families'] ?? []))));
    $allowedTypes = ['typography_mismatch', 'baseline_or_spacing_mismatch', 'ink_or_color_mismatch', 'pasted_region_boundary', 'background_texture_discontinuity', 'compression_or_resampling_difference', 'signature_or_stamp_anomaly', 'other_visual_anomaly', 'date_context_conflict', 'value_context_conflict', 'copy_move_region', 'repeated_visual_region', 'erasure_or_cover_region', 'handwritten_correction', 'sample_or_watermark', 'digital_text_overlay', 'file_format_mismatch', 'metadata_date_conflict', 'invalid_pdf_signature', 'pdf_changed_after_signing', 'ai_generated_content'];
    $safeBox = static function ($value): ?array {
        if (!is_array($value) || count($value) !== 4 || array_keys($value) !== [0, 1, 2, 3]) return null;
        foreach ($value as $coordinate) if (!is_int($coordinate) || $coordinate < 0 || $coordinate > 1000) return null;
        return $value[0] < $value[2] && $value[1] < $value[3] ? $value : null;
    };
    foreach (array_slice((array) ($raw['indicators'] ?? []), 0, 20) as $indicator) {
        if (!is_array($indicator) || !in_array(($indicator['type'] ?? ''), $allowedTypes, true)) continue;
        $item = ['type' => (string) $indicator['type'], 'page' => max(1, (int) ($indicator['page'] ?? 1)),
            'confidence_band' => in_array(($indicator['confidence_band'] ?? ''), ['low', 'moderate', 'high'], true) ? (string) $indicator['confidence_band'] : 'moderate',
            'reason' => substr(trim((string) ($indicator['reason'] ?? 'Inspect this area in the original document.')), 0, 300),
            'source' => in_array(($indicator['source'] ?? ''), ['gemini', 'chronology', 'local_regional', 'local_visual', 'metadata'], true) ? (string) $indicator['source'] : 'automated_check'];
        if (isset($indicator['source_page']) && is_int($indicator['source_page']) && $indicator['source_page'] >= 1 && $indicator['source_page'] <= 10) $item['source_page'] = $indicator['source_page'];
        foreach (['box_2d', 'source_box_2d'] as $boxName) { $box = $safeBox($indicator[$boxName] ?? null); if ($box !== null) $item[$boxName] = $box; }
        $base['indicators'][] = $item;
    }
    foreach (['gemini', 'semantic_chronology', 'local_regional', 'local_visual', 'metadata', 'qr', 'adcd_net'] as $providerName) {
        $provider = is_array($raw['providers'][$providerName] ?? null) ? $raw['providers'][$providerName] : null;
        if ($provider === null) continue;
        $base['providers'][$providerName] = ['status' => (string) ($provider['status'] ?? 'unavailable')];
        if ($providerName === 'adcd_net' && in_array(($provider['decision'] ?? ''), ['clear', 'review'], true)) $base['providers'][$providerName]['decision'] = (string) $provider['decision'];
    }
    $base['original_pages'] = [];
    foreach (array_slice((array) ($raw['original_pages'] ?? []), 0, 10) as $page) {
        if (is_array($page) && (int) ($page['page'] ?? 0) > 0) $base['original_pages'][] = ['page' => (int) $page['page'], 'available' => ($page['available'] ?? false) === true];
    }
    $auxiliary = is_array($raw['auxiliary'] ?? null) ? $raw['auxiliary'] : [];
    $fileFacts = is_array($auxiliary['file'] ?? null) ? $auxiliary['file'] : [];
    $base['auxiliary'] = ['file' => [], 'qr' => []];
    foreach (['creation_date', 'modification_date', 'creator', 'editing_software', 'image_format', 'date_taken', 'camera', 'pixel_size', 'camera_metadata'] as $factName) {
        if (isset($fileFacts[$factName]) && is_scalar($fileFacts[$factName])) $base['auxiliary']['file'][$factName] = substr((string) $fileFacts[$factName], 0, 80);
    }
    $signature = is_array($fileFacts['pdf_signature'] ?? null) ? $fileFacts['pdf_signature'] : [];
    if (in_array(($signature['state'] ?? ''), ['unsigned', 'unavailable', 'invalid_structure', 'invalid_signature', 'intact', 'changed_after_signing'], true)) $base['auxiliary']['file']['pdf_signature'] = ['state' => (string) $signature['state']];
    $provenance = is_array($fileFacts['ai_provenance'] ?? null) ? $fileFacts['ai_provenance'] : [];
    if (in_array(($provenance['state'] ?? ''), ['generated', 'edited'], true)) {
        $base['auxiliary']['file']['ai_provenance'] = ['state' => (string) $provenance['state'], 'tool' => substr((string) ($provenance['tool'] ?? 'a generative AI tool'), 0, 60)];
    }
    if (($fileFacts['design_app'] ?? null) === true) $base['auxiliary']['file']['design_app'] = true;
    foreach (array_slice((array) ($auxiliary['qr'] ?? []), 0, 12) as $qr) {
        if (!is_array($qr)) continue;
        $preview = substr((string) ($qr['content_preview'] ?? ''), 0, 160);
        $qrHost = parse_url($preview, PHP_URL_HOST);
        $qrScheme = parse_url($preview, PHP_URL_SCHEME);
        $preview = is_string($qrHost) && $qrHost !== '' && in_array($qrScheme, ['http', 'https'], true)
            ? $qrScheme . '://' . substr($qrHost, 0, 100) . ' (path and parameters redacted)'
            : 'Non-URL QR content (redacted)';
        $entry = ['page' => max(1, (int) ($qr['page'] ?? 1)), 'content_preview' => $preview, 'verification' => 'not_authenticated'];
        $qrBox = $safeBox($qr['box_2d'] ?? null); if ($qrBox !== null) $entry['box_2d'] = $qrBox;
        $base['auxiliary']['qr'][] = $entry;
    }
    if (!is_array($raw['aggregate'] ?? null)) {
        $base['message'] = $decision === 'review'
            ? ($reviewBasis === 'deterministic_chronology_conflict'
                ? 'The signature or issue date conflicts with repeated document event dates and requires staff inspection.'
                : 'Independent checks found possible document changes that require staff inspection.')
            : 'No corroborated alteration signal was found.';
        if ($decision === 'review' && $reviewBasis === 'cryptographic_signature_problem') $base['message'] = 'The PDF signature did not verify or a later revision needs staff inspection.';
    if ($decision === 'review' && $reviewBasis === 'generative_ai_provenance') $base['message'] = 'The file says it was made or edited with a generative AI tool, so it is not a direct photo or scan.';
        return $base;
    }
    $number = static function ($value): ?float { return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null; };
    $aggregate = [];
    foreach (['tampered_pixel_ratio', 'max_tamper_probability', 'mean_tamper_probability', 'processing_time_seconds'] as $key) { $aggregate[$key] = $number($raw['aggregate'][$key] ?? null); }
    if (in_array(null, $aggregate, true)) { $base['status'] = 'unknown'; $base['message'] = 'Tampering analysis result is unavailable. Manual document review is required.'; return $base; }
    $pages = [];
    foreach ((array) ($raw['pages'] ?? []) as $page) {
        if (!is_array($page) || !is_numeric($page['page'] ?? null)) continue;
        $row = ['page' => (int) $page['page']]; foreach (['tampered_pixel_ratio', 'max_tamper_probability', 'mean_tamper_probability', 'processing_time_seconds'] as $key) $row[$key] = $number($page[$key] ?? null);
        if (!in_array(null, $row, true) && $row['page'] > 0) $pages[] = $row;
    }
    $base['aggregate'] = $aggregate;
    $base['pages'] = $pages;
    $base['message'] = $decision === 'review'
        ? ($reviewBasis === 'deterministic_chronology_conflict'
            ? 'The signature or issue date conflicts with repeated document event dates and requires staff inspection.'
            : 'Independent checks found possible document changes that require staff inspection.')
        : 'Image analysis completed. Measurements support staff review only.';
    if ($decision === 'review' && $reviewBasis === 'cryptographic_signature_problem') $base['message'] = 'The PDF signature did not verify or a later revision needs staff inspection.';
    if ($decision === 'review' && $reviewBasis === 'generative_ai_provenance') $base['message'] = 'The file says it was made or edited with a generative AI tool, so it is not a direct photo or scan.';
    return $base;
}

/** @return array{title:string,document_date:string,event_dates:string,reason:string,action:string}|null */
function pswdo_flag_explanation(array $tamperAnalysis): ?array
{
    if (($tamperAnalysis['status'] ?? '') !== 'completed'
        || ($tamperAnalysis['decision'] ?? '') !== 'review'
        || ($tamperAnalysis['review_basis'] ?? '') !== 'deterministic_chronology_conflict') {
        return null;
    }
    $conflict = is_array($tamperAnalysis['date_conflict'] ?? null) ? $tamperAnalysis['date_conflict'] : [];
    $documentDate = trim((string) ($conflict['document_date'] ?? ''));
    $years = array_values(array_unique(array_filter(
        array_map('intval', (array) ($conflict['conflicting_years'] ?? [])),
        static fn (int $year): bool => $year >= 1900 && $year <= 2100
    )));
    sort($years);
    return [
        'title' => 'Why this document was flagged',
        'document_date' => $documentDate !== '' ? pswdo_date_label($documentDate) : 'Not clearly detected',
        'event_dates' => $years !== [] ? implode(', ', $years) : 'Older repeated dates detected',
        'reason' => 'The document or signature date conflicts with repeated event dates in the same form.',
        'action' => 'Compare the original dates before approving this document.',
    ];
}

/** @return array{tone:string,kicker:string,title:string,reason:string,action:string,date_facts:?array} */
function pswdo_ai_compact_explanation(string $status, array $findings, array $tamperAnalysis): array
{
    $status = strtolower(trim($status));
    $reasons = array_values(array_filter(array_map(
        static fn ($reason): string => trim((string) $reason),
        is_array($findings['reasons'] ?? null) ? $findings['reasons'] : []
    ), static fn (string $reason): bool => $reason !== ''));
    $mainReason = $reasons[0] ?? '';
    $dateExplanation = pswdo_flag_explanation($tamperAnalysis);
    $typeCheck = is_array($findings['checks']['document_type'] ?? null) ? $findings['checks']['document_type'] : [];
    $classification = is_array($typeCheck['classification'] ?? null) ? $typeCheck['classification'] : [];
    if ($status === 'flagged_inconsistency' && ($classification['decision'] ?? '') === 'wrong_slot') {
        return [
            'tone' => 'danger', 'kicker' => 'Inconsistency detected',
            'title' => 'Wrong document type',
            'reason' => $mainReason !== '' ? $mainReason : 'The uploaded file does not match the required document.',
            'action' => 'Compare the original, then ask the applicant to submit the correct document.',
            'date_facts' => null,
        ];
    }
    if ($status === 'flagged_inconsistency' && is_array($dateExplanation)) {
        return [
            'tone' => 'danger', 'kicker' => 'Needs staff confirmation',
            'title' => 'Document date inconsistency',
            'reason' => $dateExplanation['reason'], 'action' => $dateExplanation['action'],
            'date_facts' => [
                'document_date' => $dateExplanation['document_date'],
                'event_dates' => $dateExplanation['event_dates'],
            ],
        ];
    }
    if ($status === 'needs_reupload') {
        return [
            'tone' => 'danger', 'kicker' => 'Applicant update required',
            'title' => 'Document needs replacement',
            'reason' => $mainReason !== '' ? $mainReason : 'The uploaded file cannot be reviewed reliably.',
            'action' => 'Request a clear replacement document before approving.', 'date_facts' => null,
        ];
    }
    if ($status === 'flagged_inconsistency') {
        return [
            'tone' => 'danger', 'kicker' => 'Inconsistency detected',
            'title' => 'Submitted information conflict',
            'reason' => $mainReason !== '' ? $mainReason : 'One or more submitted values conflict and require staff confirmation.',
            'action' => 'Compare the original document with the submitted information before deciding.', 'date_facts' => null,
        ];
    }
    if ($status === 'needs_staff_review') {
        return [
            'tone' => 'warning', 'kicker' => 'Needs staff confirmation',
            'title' => 'Staff review needed',
            'reason' => $mainReason !== '' ? $mainReason : (string) ($tamperAnalysis['message'] ?? 'One or more automated checks need staff confirmation.'),
            'action' => 'Compare the original document with the submitted information before deciding.', 'date_facts' => null,
        ];
    }
    if ($status === 'failed' || ($tamperAnalysis['status'] ?? '') === 'unavailable') {
        return [
            'tone' => 'neutral', 'kicker' => 'Automated check unavailable',
            'title' => 'Continue with manual review',
            'reason' => $mainReason !== '' ? $mainReason : 'The automated review could not complete all checks.',
            'action' => 'Review the original document manually before deciding.', 'date_facts' => null,
        ];
    }
    if ($status === 'processing' || $status === '') {
        return [
            'tone' => 'info', 'kicker' => 'Review in progress',
            'title' => 'Document checks are running',
            'reason' => 'The automated review has not finished yet.',
            'action' => 'Wait for the result before completing document review.', 'date_facts' => null,
        ];
    }
    return [
        'tone' => 'success', 'kicker' => 'No immediate issue found',
        'title' => 'Checks completed',
        'reason' => $mainReason !== '' ? $mainReason : 'The automated checks completed without a blocking result.',
        'action' => 'Continue with normal staff verification of the original document.', 'date_facts' => null,
    ];
}

function pswdo_tamper_percent(?float $value): string { return $value === null ? 'N/A' : number_format($value * 100, 4) . '%'; }
function pswdo_tamper_seconds(?float $value): string { return $value === null ? 'N/A' : number_format($value, 2) . ' s'; }

/**
 * @return array<string, mixed>
 */
function pswdo_fetch_staff_profile(mysqli $connection, int $accountId, string $sessionDisplayName): array
{
    $sql = <<<SQL
        SELECT
            a.email,
            sp.first_name,
            sp.middle_name,
            sp.last_name,
            sp.position_title,
            sp.contact_number
        FROM aics_accounts a
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = a.id
        WHERE a.id = ?
        LIMIT 1
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    $firstName = trim((string) ($row['first_name'] ?? ''));
    $middleName = trim((string) ($row['middle_name'] ?? ''));
    $lastName = trim((string) ($row['last_name'] ?? ''));
    $displayName = trim(implode(' ', array_filter([$firstName, $lastName])));

    if ($displayName === '') {
        $displayName = trim($sessionDisplayName);
    }
    if ($displayName === '') {
        $displayName = 'PSWDO Personnel';
    }

    return [
        'display_name' => $displayName,
        'email' => trim((string) ($row['email'] ?? '')),
        'position_title' => trim((string) ($row['position_title'] ?? 'Case Management Officer')),
        'contact_number' => trim((string) ($row['contact_number'] ?? '')),
        'initials' => pswdo_initials($displayName),
    ];
}

/**
 * @return array<string, int>
 */
function pswdo_fetch_dashboard_metrics(mysqli $connection): array
{
    $metrics = [
        'total_requests' => 0,
        'pending_review' => 0,
        'under_review' => 0,
        'approved' => 0,
        'not_approved' => 0,
        'released' => 0,
        'incomplete_requirements' => 0,
        'for_interview' => 0,
        'documents_checked' => 0,
        'documents_uploaded' => 0,
    ];

    $countResult = $connection->query('SELECT COUNT(*) AS total_requests FROM aics_requests WHERE ' . aics_ai_gate_visible_sql('aics_requests'));
    if ($countRow = $countResult?->fetch_assoc()) {
        $metrics['total_requests'] = (int) ($countRow['total_requests'] ?? 0);
    }

    $statusResult = $connection->query('SELECT request_status, COUNT(*) AS total_count FROM aics_requests WHERE ' . aics_ai_gate_visible_sql('aics_requests') . ' GROUP BY request_status');
    while ($row = $statusResult?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $code = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'));
        $count = (int) ($row['total_count'] ?? 0);

        if (array_key_exists($code, $metrics)) {
            $metrics[$code] += $count;
        }
    }

    $docResult = $connection->query('SELECT COUNT(*) AS uploaded_docs FROM aics_request_documents');
    if ($docRow = $docResult?->fetch_assoc()) {
        $metrics['documents_uploaded'] = (int) ($docRow['uploaded_docs'] ?? 0);
    }

    $checkedResult = $connection->query('SELECT COUNT(*) AS checked_docs FROM aics_request_document_reviews WHERE is_checked = 1');
    if ($checkedRow = $checkedResult?->fetch_assoc()) {
        $metrics['documents_checked'] = (int) ($checkedRow['checked_docs'] ?? 0);
    }

    return $metrics;
}

function pswdo_normalize_overview_period(string $period): string
{
    $normalized = strtolower(trim($period));
    return match ($normalized) {
        'day', 'today' => 'day',
        'week', 'this_week', 'this week' => 'week',
        'month', 'this_month', 'this month' => 'month',
        default => 'day',
    };
}

function pswdo_overview_period_label(string $period): string
{
    return match (pswdo_normalize_overview_period($period)) {
        'week' => 'Last 7 Days',
        'month' => 'Last 30 Days',
        default => 'Today',
    };
}

/**
 * Rolling dashboard range ending today, so early-month and early-week views
 * still show recent activity.
 *
 * @return array{0:string,1:string} [date_from, date_to] as Y-m-d
 */
function pswdo_overview_period_range(string $period, ?string $today = null): array
{
    $end = new DateTimeImmutable($today ?? 'today', new DateTimeZone('Asia/Manila'));
    $days = match (pswdo_normalize_overview_period($period)) {
        'week' => 7,
        'month' => 30,
        default => 1,
    };
    return [$end->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), $end->format('Y-m-d')];
}

/** Date of the most recent request, or '' when there are none. */
function pswdo_fetch_latest_request_date(mysqli $connection): string
{
    $result = $connection->query("SELECT MAX(COALESCE(NULLIF(DATE(date_applied), '0000-00-00'), DATE(created_at))) AS latest FROM aics_requests WHERE " . aics_ai_gate_visible_sql('aics_requests'));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    return is_array($row) && is_string($row['latest'] ?? null) ? $row['latest'] : '';
}

function pswdo_overview_period_to_date_filter(string $period): string
{
    return match (pswdo_normalize_overview_period($period)) {
        'week' => 'this_week',
        'month' => 'this_month',
        default => 'today',
    };
}

/**
 * @return array<string, mixed>
 */
function pswdo_fetch_period_metrics(mysqli $connection, string $period): array
{
    $periodCode = pswdo_normalize_overview_period($period);
    $range = pswdo_overview_period_range($periodCode);

    $dateFrom = (string) $range[0];
    $dateTo = (string) $range[1];
    $dateExpression = "COALESCE(NULLIF(DATE(date_applied), '0000-00-00'), DATE(created_at))";

    $metrics = [
        'total_submitted' => 0,
        'pending_review' => 0,
        'for_interview' => 0,
        'under_review' => 0,
        'interview_initial_total' => 0,
        'approved' => 0,
        'not_approved' => 0,
        'released' => 0,
        'incomplete_requirements' => 0,
        'cancelled' => 0,
    ];

    $totalSql = "SELECT COUNT(*) AS total_count FROM aics_requests WHERE $dateExpression BETWEEN ? AND ? AND " . aics_ai_gate_visible_sql('aics_requests');
    $totalStmt = $connection->prepare($totalSql);
    $totalStmt->bind_param('ss', $dateFrom, $dateTo);
    $totalStmt->execute();
    $totalRow = $totalStmt->get_result()?->fetch_assoc();
    $totalStmt->close();
    $metrics['total_submitted'] = (int) ($totalRow['total_count'] ?? 0);

    $statusSql = "SELECT request_status, COUNT(*) AS total_count FROM aics_requests WHERE $dateExpression BETWEEN ? AND ? AND " . aics_ai_gate_visible_sql('aics_requests') . " GROUP BY request_status";
    $statusStmt = $connection->prepare($statusSql);
    $statusStmt->bind_param('ss', $dateFrom, $dateTo);
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();
    while ($row = $statusResult?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $rawRequestStatus = strtolower(trim((string) ($row['request_status'] ?? 'pending')));
        $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'));
        $isExplicitScreeningApproved = in_array($rawRequestStatus, ['approved'], true);
        if ($statusCode === 'approved' && !$isExplicitScreeningApproved) {
            // Keep "Approved" badge exclusive to explicit staff screening approval.
            $statusCode = 'under_review';
        }
        $count = (int) ($row['total_count'] ?? 0);
        if (array_key_exists($statusCode, $metrics)) {
            $metrics[$statusCode] += $count;
        }
    }
    $statusStmt->close();

    $metrics['interview_initial_total'] = (int) $metrics['for_interview'] + (int) $metrics['under_review'];

    return [
        'period_code' => $periodCode,
        'period_label' => pswdo_overview_period_label($periodCode),
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'metrics' => $metrics,
    ];
}

/**
 * @return array{
 *   period_code:string,
 *   period_label:string,
 *   date_from:string,
 *   date_to:string,
 *   points:array<int, array<string, int|string>>,
 *   max_submitted:int
 * }
 */
function pswdo_fetch_period_trend(mysqli $connection, string $period): array
{
    $visibleSql = aics_ai_gate_visible_sql('aics_requests');
    $periodCode = pswdo_normalize_overview_period($period);
    $range = pswdo_overview_period_range($periodCode);

    $dateFrom = (string) $range[0];
    $dateTo = (string) $range[1];
    $dateExpression = "COALESCE(NULLIF(DATE(date_applied), '0000-00-00'), DATE(created_at))";

    $points = [];
    $timezone = new DateTimeZone('Asia/Manila');

    if ($periodCode === 'day') {
        for ($hour = 0; $hour < 24; $hour++) {
            $key = sprintf('%02d', $hour);
            $hourForLabel = $hour % 12;
            if ($hourForLabel === 0) {
                $hourForLabel = 12;
            }
            $suffix = $hour >= 12 ? 'P' : 'A';
            $points[$key] = [
                'bucket' => $key,
                'label' => $hourForLabel . $suffix,
                'submitted' => 0,
                'pending_review' => 0,
                'interview_initial' => 0,
                'approved' => 0,
                'not_approved' => 0,
                'released' => 0,
                'other' => 0,
            ];
        }

        $sql = <<<SQL
            SELECT
                HOUR(created_at) AS bucket_key,
                request_status,
                COUNT(*) AS total_count
            FROM aics_requests
            WHERE $dateExpression = ? AND $visibleSql
            GROUP BY bucket_key, request_status
        SQL;
        $stmt = $connection->prepare($sql);
        $stmt->bind_param('s', $dateFrom);
    } else {
        $cursor = new DateTimeImmutable($dateFrom, $timezone);
        $end = new DateTimeImmutable($dateTo, $timezone);
        while ($cursor <= $end) {
            $bucketDate = $cursor->format('Y-m-d');
            $label = $periodCode === 'week'
                ? $cursor->format('D')
                : $cursor->format('M j');
            $points[$bucketDate] = [
                'bucket' => $bucketDate,
                'label' => $label,
                'submitted' => 0,
                'pending_review' => 0,
                'interview_initial' => 0,
                'approved' => 0,
                'not_approved' => 0,
                'released' => 0,
                'other' => 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $sql = <<<SQL
            SELECT
                $dateExpression AS bucket_key,
                request_status,
                COUNT(*) AS total_count
            FROM aics_requests
            WHERE $dateExpression BETWEEN ? AND ? AND $visibleSql
            GROUP BY bucket_key, request_status
        SQL;
        $stmt = $connection->prepare($sql);
        $stmt->bind_param('ss', $dateFrom, $dateTo);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $count = (int) ($row['total_count'] ?? 0);
        if ($count <= 0) {
            continue;
        }

        if ($periodCode === 'day') {
            $hour = (int) ($row['bucket_key'] ?? -1);
            if ($hour < 0 || $hour > 23) {
                continue;
            }
            $bucketKey = sprintf('%02d', $hour);
        } else {
            $bucketKey = trim((string) ($row['bucket_key'] ?? ''));
            if ($bucketKey === '') {
                continue;
            }
        }

        if (!isset($points[$bucketKey])) {
            continue;
        }

        $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'));
        $segmentKey = match ($statusCode) {
            'pending_review' => 'pending_review',
            'for_interview', 'under_review' => 'interview_initial',
            'approved' => 'approved',
            'not_approved' => 'not_approved',
            'released' => 'released',
            default => 'other',
        };

        $points[$bucketKey]['submitted'] = (int) $points[$bucketKey]['submitted'] + $count;
        $points[$bucketKey][$segmentKey] = (int) $points[$bucketKey][$segmentKey] + $count;
    }
    $stmt->close();

    $maxSubmitted = 0;
    foreach ($points as $point) {
        $value = (int) ($point['submitted'] ?? 0);
        if ($value > $maxSubmitted) {
            $maxSubmitted = $value;
        }
    }
    if ($maxSubmitted < 1) {
        $maxSubmitted = 1;
    }

    return [
        'period_code' => $periodCode,
        'period_label' => pswdo_overview_period_label($periodCode),
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'points' => array_values($points),
        'max_submitted' => $maxSubmitted,
    ];
}

/** @param array<int, array<string, mixed>> $documentRows
 *  @return array<int, array<string, mixed>>
 */
function pswdo_group_id_photos(array $documentRows): array
{
    $backPhoto = null;
    $grouped = [];
    foreach ($documentRows as $row) {
        if (($row['code'] ?? '') === 'valid_id_back') {
            $backPhoto = $row;
            continue;
        }
        $grouped[] = $row;
    }
    if ($backPhoto !== null) {
        foreach ($grouped as &$row) {
            if (($row['code'] ?? '') === 'valid_id') {
                $row['back_photo'] = $backPhoto;
                break;
            }
        }
        unset($row);
    }
    return $grouped;
}

/**
 * @param array<string, string> $filters
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_request_queue(mysqli $connection, array $filters = []): array
{
    $statusFilter = pswdo_normalize_filter_status((string) ($filters['status'] ?? 'all'));
    $dateFilter = pswdo_normalize_date_filter((string) ($filters['date_filter'] ?? 'all'));
    $searchFilter = trim((string) ($filters['search'] ?? ''));
    $detailRequestId = max(0, (int) ($filters['detail_request_id'] ?? 0));

    $query = <<<SQL
        SELECT
            r.id,
            r.reference_no,
            r.date_applied,
            r.request_status,
            r.beneficiary_first_name,
            r.beneficiary_middle_name,
            r.beneficiary_last_name,
            r.beneficiary_complete_name,
            r.beneficiary_birth_date,
            r.beneficiary_barangay,
            r.beneficiary_sitio_purok,
            r.beneficiary_municipality,
            r.beneficiary_province,
            r.contact_number,
            r.barangay,
            r.sitio_purok,
            r.municipality,
            r.province,
            r.gender,
            r.employment AS beneficiary_occupation,
            r.estimated_income AS beneficiary_estimated_income,
            r.family_composition_json,
            r.applicant_first_name,
            r.applicant_middle_name,
            r.applicant_last_name,
            r.applicant_age,
            r.beneficiary_age,
            r.client_categories_json,
            r.amount,
            r.claim_status,
            r.amount_claimed,
            r.cheque_date,
            r.created_at,
            r.updated_at,
            r.assistance_types_json,
            r.urgency_response,
            r.urgency_indicator,
            r.ai_gate_updated_at,
            at.assistance_code,
            at.assistance_name,
            COALESCE(NULLIF(TRIM(r.applicant_email), ''), a.email) AS applicant_email,
            COALESCE(NULLIF(TRIM(r.applicant_contact_number), ''), ap.contact_number) AS applicant_contact_number,
            COALESCE(NULLIF(TRIM(r.applicant_occupation), ''), ap.occupation) AS applicant_occupation,
            COALESCE(NULLIF(TRIM(r.applicant_estimated_monthly_income), ''), ap.estimated_monthly_income) AS applicant_estimated_monthly_income,
            r.applicant_relationship,
            r.family_total_income,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name)), ''), NULLIF(TRIM(CONCAT_WS(' ', ap.first_name, ap.middle_name, ap.last_name)), '')) AS applicant_profile_name
        FROM aics_requests r
        LEFT JOIN (
            SELECT request_id, MIN(assistance_type_id) AS assistance_type_id
            FROM aics_request_assistance
            GROUP BY request_id
        ) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        LEFT JOIN aics_account_requests ar ON ar.request_id = r.id
        LEFT JOIN aics_accounts a ON a.id = ar.account_id
        LEFT JOIN aics_applicant_profiles ap ON ap.account_id = a.id
        LEFT JOIN aics_request_prioritization pr ON pr.request_id = r.id
    SQL;

    $where = [aics_ai_gate_visible_sql('r')];
    $params = [];
    $types = '';

    // Request Management is the incoming-decision queue, not a workflow
    // history. Reuse the canonical pending-review aliases so any completed or
    // later-stage request is excluded at the database source.
    $incomingStatuses = pswdo_filter_status_values('pending_review');
    $incomingPlaceholders = implode(',', array_fill(0, count($incomingStatuses), '?'));
    $incomingStatusWhere = "LOWER(TRIM(r.request_status)) IN ($incomingPlaceholders)";
    if ($detailRequestId > 0) {
        $where[] = "($incomingStatusWhere OR r.id = ?)";
    } else {
        $where[] = $incomingStatusWhere;
    }
    foreach ($incomingStatuses as $incomingStatus) {
        $params[] = strtolower($incomingStatus);
        $types .= 's';
    }
    if ($detailRequestId > 0) {
        $params[] = $detailRequestId;
        $types .= 'i';
    }

    if ($statusFilter !== 'all') {
        $statusValues = pswdo_filter_status_values($statusFilter);
        if (count($statusValues) > 0) {
            $statusPlaceholders = implode(',', array_fill(0, count($statusValues), '?'));
            $where[] = "r.request_status IN ($statusPlaceholders)";
            foreach ($statusValues as $statusValue) {
                $params[] = $statusValue;
                $types .= 's';
            }
        }
    }

    $dateRange = pswdo_filter_date_range($dateFilter);
    if (is_array($dateRange) && count($dateRange) === 2) {
        $where[] = "COALESCE(NULLIF(DATE(r.date_applied), '0000-00-00'), DATE(r.created_at)) BETWEEN ? AND ?";
        $params[] = (string) $dateRange[0];
        $params[] = (string) $dateRange[1];
        $types .= 'ss';
    }

    if ($searchFilter !== '') {
        $where[] = '(r.reference_no LIKE ? OR r.beneficiary_complete_name LIKE ? OR CONCAT_WS(" ", ap.first_name, ap.last_name) LIKE ?)';
        $needle = '%' . $searchFilter . '%';
        $params[] = $needle;
        $params[] = $needle;
        $params[] = $needle;
        $types .= 'sss';
    }

    if (count($where) > 0) {
        $query .= ' WHERE ' . implode(' AND ', $where);
    }

    $smartPriorityOrderSql = pswdo_smart_priority_order_case_sql('pr');
    $query .= " ORDER BY {$smartPriorityOrderSql} ASC, COALESCE(NULLIF(r.date_applied, '0000-00-00'), DATE(r.created_at)) ASC, r.id ASC";

    $stmt = $connection->prepare($query);
    if ($types !== '') {
        $bindParams = [$types];
        foreach ($params as $index => $value) {
            $bindParams[$index + 1] = $value;
        }

        $references = [];
        foreach ($bindParams as $index => $value) {
            $references[$index] = &$bindParams[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $references);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $requestIds = [];

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $requestId = (int) ($row['id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }

        $assistanceCode = strtolower(trim((string) ($row['assistance_code'] ?? '')));
        if ($assistanceCode === '') {
            $assistanceCode = pswdo_extract_first_assistance_from_json((string) ($row['assistance_types_json'] ?? '[]'));
        }

        $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'));
        $applicantSubmittedName = trim(implode(' ', array_filter([
            trim((string) ($row['applicant_first_name'] ?? '')),
            trim((string) ($row['applicant_middle_name'] ?? '')),
            trim((string) ($row['applicant_last_name'] ?? '')),
        ])));
        $beneficiarySubmittedName = trim(implode(' ', array_filter([
            trim((string) ($row['beneficiary_first_name'] ?? '')),
            trim((string) ($row['beneficiary_middle_name'] ?? '')),
            trim((string) ($row['beneficiary_last_name'] ?? '')),
        ])));
        $familyComposition = json_decode((string) ($row['family_composition_json'] ?? '[]'), true);
        if (!is_array($familyComposition)) $familyComposition = [];

        $rows[$requestId] = [
            'id' => $requestId,
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'date_applied' => (string) ($row['date_applied'] ?? ''),
            'date_applied_label' => pswdo_date_label((string) ($row['date_applied'] ?? '')),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_at_label' => pswdo_datetime_label((string) ($row['created_at'] ?? '')),
            'updated_at_label' => pswdo_datetime_label((string) ($row['updated_at'] ?? '')),
            'status_code' => $statusCode,
            'status_label' => pswdo_status_labels()[$statusCode] ?? 'Pending Review',
            'status_tone' => pswdo_status_tone($statusCode),
            'beneficiary_name' => trim((string) ($row['beneficiary_complete_name'] ?? '')) !== ''
                ? trim((string) ($row['beneficiary_complete_name'] ?? ''))
                : $beneficiarySubmittedName,
            'beneficiary_birth_date' => trim((string) ($row['beneficiary_birth_date'] ?? '')),
            'contact_number' => trim((string) ($row['contact_number'] ?? '')),
            'barangay' => trim((string) ($row['barangay'] ?? '')),
            'sitio_purok' => trim((string) ($row['sitio_purok'] ?? '')),
            'municipality' => trim((string) ($row['municipality'] ?? '')),
            'province' => trim((string) ($row['province'] ?? '')),
            'gender' => trim((string) ($row['gender'] ?? '')),
            'gender_label' => pswdo_titleize((string) ($row['gender'] ?? '')),
            'beneficiary_occupation' => trim((string) ($row['beneficiary_occupation'] ?? '')),
            'beneficiary_estimated_income' => aics_income_value($row['beneficiary_estimated_income'] ?? null) ?? '',
            'applicant_name' => trim((string) ($row['applicant_profile_name'] ?? '')),
            'applicant_submitted_name' => $applicantSubmittedName,
            'applicant_email' => trim((string) ($row['applicant_email'] ?? '')),
            'applicant_contact_number' => trim((string) ($row['applicant_contact_number'] ?? '')),
            'applicant_occupation' => trim((string) ($row['applicant_occupation'] ?? '')),
            'applicant_estimated_monthly_income' => aics_income_value($row['applicant_estimated_monthly_income'] ?? null) ?? '',
            'applicant_relationship' => trim((string) ($row['applicant_relationship'] ?? '')),
            'family_total_income' => aics_income_value($row['family_total_income'] ?? null) ?? '',
            'family_composition' => array_values($familyComposition),
            'applicant_age' => (int) ($row['applicant_age'] ?? 0),
            'beneficiary_age' => (int) ($row['beneficiary_age'] ?? 0),
            'amount' => (float) ($row['amount'] ?? 0),
            'amount_label' => 'PHP ' . number_format((float) ($row['amount'] ?? 0), 2),
            'claim_status' => trim((string) ($row['claim_status'] ?? '')),
            'claim_status_label' => pswdo_titleize((string) ($row['claim_status'] ?? '')),
            'amount_claimed' => ($row['amount_claimed'] !== null ? (float) $row['amount_claimed'] : null),
            'cheque_date' => trim((string) ($row['cheque_date'] ?? '')),
            'cheque_date_label' => pswdo_date_label((string) ($row['cheque_date'] ?? '')),
            'client_categories_json' => (string) ($row['client_categories_json'] ?? '[]'),
            'client_categories_label' => pswdo_client_categories_label((string) ($row['client_categories_json'] ?? '[]')),
            'urgency_response' => trim((string) ($row['urgency_response'] ?? '')),
            'urgency_indicator' => trim((string) ($row['urgency_indicator'] ?? '')) !== '' ? (string) $row['urgency_indicator'] : 'Not assessed',
            'gate_received_at' => (string) ($row['ai_gate_updated_at'] ?? ''),
            'assistance_code' => $assistanceCode,
            'assistance_label' => trim((string) ($row['assistance_name'] ?? '')) !== ''
                ? (string) $row['assistance_name']
                : pswdo_assistance_label($assistanceCode),
            'required_documents' => [],
            'timeline' => [],
            'validation' => [
                'duplicate_flag' => false,
                'repeated_claim_flag' => false,
                'inconsistency_flag' => false,
                'risk_score' => 0,
                'validation_note' => '',
            ],
            'priority' => [
                'urgency_score' => 0,
                'vulnerability_score' => 0,
                'readiness_score' => 0,
                'risk_penalty' => 0,
                'priority_score' => 0,
                'priority_rank' => null,
                'priority_level' => 'NORMAL',
            ],
        ];

        $requestIds[] = $requestId;
    }

    $stmt->close();

    if (count($requestIds) === 0) {
        return [];
    }

    $requiredMap = pswdo_required_documents_by_assistance($connection);
    $documentsByRequest = pswdo_documents_by_request($connection, $requestIds);
    $aiHistoryByDocument = pswdo_ai_history_by_document($connection, $requestIds);
    $timelineByRequest = pswdo_status_timeline_by_request($connection, $requestIds);
    $validationByRequest = pswdo_validation_by_request($connection, $requestIds);
    $priorityByRequest = pswdo_priority_by_request($connection, $requestIds);

    foreach ($rows as $requestId => &$request) {
        $requiredCodes = $requiredMap[$request['assistance_code']] ?? [];
        $uploadedDocs = $documentsByRequest[$requestId] ?? [];
        $docByType = [];

        foreach ($uploadedDocs as $doc) {
            $docType = aics_normalize_document_type((string) ($doc['document_type'] ?? ''));
            if ($docType === '') {
                continue;
            }

            if (!isset($docByType[$docType])) {
                $docByType[$docType] = $doc;
            }
        }

        $requiredDocumentRows = [];
        $uploadedCount = 0;
        $checkedCount = 0;
        $aiProcessedCount = 0;
        $aiProcessingCount = 0;
        $aiReprocessingNeededCount = 0;
        $aiCompletedCount = 0;
        $aiUnavailableCount = 0;
        $aiQueuedCount = 0;
        $aiActiveCount = 0;
        $aiTerminalCount = 0;
        $aiFailedCount = 0;
        $aiReuploadCount = 0;
        $aiLatestUpdate = null;
        $aiTerminalStatuses = ['completed', 'likely_valid', 'needs_staff_review', 'needs_reupload', 'flagged_inconsistency', 'failed'];

        foreach ($requiredCodes as $code) {
            $doc = $docByType[$code] ?? null;
            $uploaded = is_array($doc);
            $checked = $uploaded && ((int) ($doc['is_checked'] ?? 0) === 1);

            if ($uploaded) {
                $uploadedCount++;
            }
            if ($checked) {
                $checkedCount++;
            }
            if ($uploaded) {
                $aiStatus = strtolower(trim((string) ($doc['ai_status'] ?? '')));
                $aiStage = strtolower(trim((string) ($doc['ai_processing_stage'] ?? 'queued')));
                if ($aiStatus === '' || $aiStatus === 'processing') {
                    if ($aiStage === '' || $aiStage === 'queued') $aiQueuedCount++;
                    else $aiActiveCount++;
                } elseif (in_array($aiStatus, $aiTerminalStatuses, true)) {
                    $aiTerminalCount++;
                    if ($aiStatus === 'failed') $aiFailedCount++;
                    if ($aiStatus === 'needs_reupload') $aiReuploadCount++;
                }
                $aiUpdatedAt = trim((string) ($doc['ai_stage_updated_at'] ?? ''));
                if ($aiUpdatedAt === '') $aiUpdatedAt = trim((string) ($doc['ai_processed_at'] ?? ''));
                if ($aiUpdatedAt !== '' && ($aiLatestUpdate === null || $aiUpdatedAt > $aiLatestUpdate)) $aiLatestUpdate = $aiUpdatedAt;
                $tamperState = pswdo_tamper_analysis_json((string) ($doc['ai_findings'] ?? ''), (string) ($doc['ai_image_supplement_tamper_detection'] ?? ''));
                $tamperStatus = (string) ($tamperState['status'] ?? '');
                if (in_array($aiStatus, $aiTerminalStatuses, true) && in_array($tamperStatus, ['completed', 'unavailable'], true)) {
                    $aiProcessedCount++;
                    if ($tamperStatus === 'completed') {
                        $aiCompletedCount++;
                    } else {
                        $aiUnavailableCount++;
                    }
                } elseif (in_array($aiStatus, $aiTerminalStatuses, true)) {
                    $aiReprocessingNeededCount++;
                } elseif ($aiStatus === 'processing' || $aiStatus === '') {
                    $aiProcessingCount++;
                }
            }

            $requiredDocumentRows[] = [
                'code' => $code,
                'label' => pswdo_document_label($code),
                'uploaded' => $uploaded,
                'checked' => $checked,
                'review_status' => $uploaded ? strtolower(trim((string) ($doc['review_status'] ?? 'pending'))) : 'pending',
                'review_remarks' => $uploaded ? trim((string) ($doc['review_remarks'] ?? '')) : '',
                'document_id' => $uploaded ? (int) ($doc['id'] ?? 0) : 0,
                'file_name' => $uploaded ? (string) ($doc['original_name'] ?? '') : '',
                'mime_type' => $uploaded ? (string) ($doc['mime_type'] ?? '') : '',
                'file_path' => $uploaded ? (string) ($doc['file_path'] ?? '') : '',
                'uploaded_at' => $uploaded ? pswdo_datetime_label((string) ($doc['uploaded_at'] ?? '')) : '',
                'is_required' => true,
                'reviewed_at' => $uploaded && (string) ($doc['reviewed_at'] ?? '') !== ''
                    ? pswdo_datetime_label((string) ($doc['reviewed_at'] ?? ''))
                    : '',
                'ai_status' => $uploaded ? (string) ($doc['ai_status'] ?? '') : '',
                'ai_processing_stage' => $uploaded ? (string) ($doc['ai_processing_stage'] ?? 'queued') : '',
                'ai_progress_percent' => $uploaded ? (int) ($doc['ai_progress_percent'] ?? 0) : 0,
                'ai_stage_updated_at' => $uploaded ? (string) ($doc['ai_stage_updated_at'] ?? '') : '',
                'ai_review_id' => $uploaded ? (int) ($doc['ai_review_id'] ?? 0) : 0,
                'ai_expected_document_type' => $uploaded ? (string) ($doc['ai_expected_document_type'] ?? '') : '',
                'ai_detected_document_type' => $uploaded ? (string) ($doc['ai_detected_document_type'] ?? '') : '',
                'ai_findings' => $uploaded ? (string) ($doc['ai_findings'] ?? '') : '',
                'tamper_analysis' => $uploaded ? pswdo_tamper_analysis_json((string) ($doc['ai_findings'] ?? ''), (string) ($doc['ai_image_supplement_tamper_detection'] ?? '')) : pswdo_tamper_analysis_json(''),
                'ai_image_tamper_detection' => $uploaded ? pswdo_effective_tamper_detection((string) ($doc['ai_findings'] ?? ''), (string) ($doc['ai_image_supplement_tamper_detection'] ?? '')) : null,
                'ai_confidence_score' => $uploaded ? (string) ($doc['ai_confidence_score'] ?? '') : '',
                'ai_processed_at' => $uploaded ? (string) ($doc['ai_processed_at'] ?? '') : '',
                'ai_model_service' => $uploaded ? (string) ($doc['ai_model_service'] ?? '') : '',
                'ai_model_version' => $uploaded ? (string) ($doc['ai_model_version'] ?? '') : '',
                'ai_history' => $uploaded ? ($aiHistoryByDocument[(int) ($doc['id'] ?? 0)] ?? []) : [],
            ];
        }

        // Medical/Burial always show the optional CPCS slot. Its absence is
        // informational only and never contributes to completeness counts.
        $optionalRequirements = array_filter(
            aics_document_requirements_for((string) $request['assistance_code']),
            static fn (array $requirement): bool => !$requirement['required']
        );
        if (isset($docByType['valid_id_back'])) {
            $optionalRequirements[] = ['code' => 'valid_id_back', 'label' => aics_document_requirement_label('valid_id_back')];
        }
        foreach ($optionalRequirements as $optionalRequirement) {
            $optionalCode = $optionalRequirement['code'];
            $optionalDoc = $docByType[$optionalCode] ?? null;
            $optionalUploaded = is_array($optionalDoc);
            $requiredDocumentRows[] = [
                'code' => $optionalCode,
                'label' => $optionalRequirement['label'] . ' (Optional)',
                'uploaded' => $optionalUploaded,
                'checked' => $optionalUploaded && ((int) ($optionalDoc['is_checked'] ?? 0) === 1),
                'review_status' => $optionalUploaded ? strtolower(trim((string) ($optionalDoc['review_status'] ?? 'pending'))) : 'optional_not_uploaded',
                'review_remarks' => $optionalUploaded ? trim((string) ($optionalDoc['review_remarks'] ?? '')) : '',
                'document_id' => $optionalUploaded ? (int) ($optionalDoc['id'] ?? 0) : 0,
                'file_name' => $optionalUploaded ? (string) ($optionalDoc['original_name'] ?? '') : '',
                'mime_type' => $optionalUploaded ? (string) ($optionalDoc['mime_type'] ?? '') : '',
                'file_path' => $optionalUploaded ? (string) ($optionalDoc['file_path'] ?? '') : '',
                'uploaded_at' => $optionalUploaded ? pswdo_datetime_label((string) ($optionalDoc['uploaded_at'] ?? '')) : '',
                'reviewed_at' => $optionalUploaded && (string) ($optionalDoc['reviewed_at'] ?? '') !== '' ? pswdo_datetime_label((string) $optionalDoc['reviewed_at']) : '',
                'ai_status' => $optionalUploaded ? (string) ($optionalDoc['ai_status'] ?? '') : '',
                'ai_review_id' => $optionalUploaded ? (int) ($optionalDoc['ai_review_id'] ?? 0) : 0,
                'ai_expected_document_type' => $optionalUploaded ? (string) ($optionalDoc['ai_expected_document_type'] ?? '') : '',
                'ai_detected_document_type' => $optionalUploaded ? (string) ($optionalDoc['ai_detected_document_type'] ?? '') : '',
                'ai_findings' => $optionalUploaded ? (string) ($optionalDoc['ai_findings'] ?? '') : '',
                'tamper_analysis' => $optionalUploaded ? pswdo_tamper_analysis_json((string) ($optionalDoc['ai_findings'] ?? ''), (string) ($optionalDoc['ai_image_supplement_tamper_detection'] ?? '')) : pswdo_tamper_analysis_json(''),
                'ai_image_tamper_detection' => $optionalUploaded ? pswdo_effective_tamper_detection((string) ($optionalDoc['ai_findings'] ?? ''), (string) ($optionalDoc['ai_image_supplement_tamper_detection'] ?? '')) : null,
                'ai_confidence_score' => $optionalUploaded ? (string) ($optionalDoc['ai_confidence_score'] ?? '') : '',
                'ai_processed_at' => $optionalUploaded ? (string) ($optionalDoc['ai_processed_at'] ?? '') : '',
                'ai_model_service' => $optionalUploaded ? (string) ($optionalDoc['ai_model_service'] ?? '') : '',
                'ai_model_version' => $optionalUploaded ? (string) ($optionalDoc['ai_model_version'] ?? '') : '',
                'ai_history' => $optionalUploaded ? ($aiHistoryByDocument[(int) ($optionalDoc['id'] ?? 0)] ?? []) : [],
                'is_required' => false,
            ];
        }

        $requiredCount = count($requiredCodes);
        $uploadProgress = $requiredCount > 0 ? (int) round(($uploadedCount / $requiredCount) * 100) : 0;
        $checkedProgress = $requiredCount > 0 ? (int) round(($checkedCount / $requiredCount) * 100) : 0;

        $request['required_documents'] = pswdo_group_id_photos($requiredDocumentRows);
        $request['required_total'] = $requiredCount;
        $request['uploaded_total'] = $uploadedCount;
        $request['checked_total'] = $checkedCount;
        $request['ai_processed_total'] = $aiProcessedCount;
        $request['ai_completed_total'] = $aiCompletedCount;
        $request['ai_unavailable_total'] = $aiUnavailableCount;
        $request['ai_processing_total'] = $aiProcessingCount;
        $request['ai_reprocessing_needed_total'] = $aiReprocessingNeededCount;
        $request['upload_progress'] = $uploadProgress;
        $request['checked_progress'] = $checkedProgress;
        $request['ai_attention_count'] = count(array_filter($requiredDocumentRows, static fn (array $document): bool =>
            !empty($document['uploaded']) && pswdo_ai_review_requires_attention((string) ($document['ai_status'] ?? ''))
        ));
        $request['ai_total'] = $uploadedCount;
        $request['ai_queued_total'] = $aiQueuedCount;
        $request['ai_active_total'] = $aiActiveCount;
        $request['ai_terminal_total'] = $aiTerminalCount;
        $request['ai_failed_total'] = $aiFailedCount;
        $request['ai_progress_percent'] = $uploadedCount > 0 ? (int) round(($aiTerminalCount / $uploadedCount) * 100) : 0;
        $request['ai_latest_update'] = $aiLatestUpdate;
        if ($uploadedCount === 0) {
            $request['ai_request_state'] = 'empty';
            $request['ai_request_label'] = 'No verifiable files';
        } elseif ($aiQueuedCount > 0 || $aiActiveCount > 0) {
            $request['ai_request_state'] = 'scanning';
            $request['ai_request_label'] = 'Scanning ' . $aiTerminalCount . ' of ' . $uploadedCount;
        } elseif ($aiFailedCount > 0) {
            $request['ai_request_state'] = 'failed';
            $request['ai_request_label'] = 'Scan unavailable';
        } elseif ($aiReuploadCount > 0) {
            $request['ai_request_state'] = 'reupload';
            $request['ai_request_label'] = 'Re-upload needed';
        } elseif ($request['ai_attention_count'] > 0) {
            $request['ai_request_state'] = 'attention';
            $request['ai_request_label'] = $request['ai_attention_count'] . ' need review';
        } else {
            $request['ai_request_state'] = 'ready';
            $request['ai_request_label'] = 'AI results ready';
        }
        $request['timeline'] = $timelineByRequest[$requestId] ?? [];

        if (isset($validationByRequest[$requestId])) {
            $request['validation'] = $validationByRequest[$requestId];
        }
        if (isset($priorityByRequest[$requestId])) {
            $request['priority'] = $priorityByRequest[$requestId];
        }
    }
    unset($request);

    foreach ($rows as $request) {
        pswdo_refresh_request_scoring($connection, $request, null);
    }
    pswdo_recalculate_priority_ranks($connection);

    $validationByRequest = pswdo_validation_by_request($connection, $requestIds);
    $priorityByRequest = pswdo_priority_by_request($connection, $requestIds);
    foreach ($rows as $requestId => &$request) {
        if (isset($validationByRequest[$requestId])) {
            $request['validation'] = $validationByRequest[$requestId];
        }
        if (isset($priorityByRequest[$requestId])) {
            $request['priority'] = $priorityByRequest[$requestId];
        }
        $duplicates = pswdo_find_application_duplicates($connection, $requestId);
        $request['duplicate_matches'] = (array) ($duplicates['matches'] ?? []);
        $request['duplicate_summary'] = (string) ($duplicates['summary'] ?? '');
    }
    unset($request);

    uasort($rows, 'pswdo_compare_smart_priority_rows');

    return array_values($rows);
}

/**
 * @param array<int, int> $requestIds
 * @return array<int, array<int, array<string, mixed>>>
 */
function pswdo_documents_by_request(mysqli $connection, array $requestIds): array
{
    $documents = [];
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));

    $sql = <<<SQL
        SELECT
            d.id,
            d.request_id,
            d.document_type,
            d.original_name,
            d.mime_type,
            d.file_path,
            d.uploaded_at,
            COALESCE(dr.is_checked, 0) AS is_checked,
            COALESCE(dr.review_status, 'pending') AS review_status,
            dr.remarks AS review_remarks,
            dr.reviewed_at,
            air.status AS ai_status,
            air.processing_stage AS ai_processing_stage,
            air.progress_percent AS ai_progress_percent,
            air.stage_updated_at AS ai_stage_updated_at,
            air.id AS ai_review_id,
            air.attempt_count AS ai_attempt_count,
            air.expected_document_type AS ai_expected_document_type,
            air.detected_document_type AS ai_detected_document_type,
            air.confidence_score AS ai_confidence_score,
            air.findings AS ai_findings,
            air.processed_at AS ai_processed_at,
            air.model_service AS ai_model_service,
            air.model_version AS ai_model_version,
            ais.status AS ai_image_supplement_status,
            ais.tamper_detection AS ai_image_supplement_tamper_detection
        FROM aics_request_documents d
        LEFT JOIN aics_request_document_reviews dr ON dr.request_document_id = d.id
        LEFT JOIN document_ai_reviews air ON air.request_document_id = d.id
        LEFT JOIN document_ai_review_image_supplements ais ON ais.ai_review_id = air.id
        WHERE d.request_id IN ($placeholders)
        ORDER BY d.uploaded_at DESC, d.id DESC
    SQL;

    $stmt = $connection->prepare($sql);
    $bindTypes = str_repeat('i', count($requestIds));
    $bindParams = [$bindTypes];
    foreach ($requestIds as $value) {
        $bindParams[] = $value;
    }
    $refs = [];
    foreach ($bindParams as $index => $value) {
        $refs[$index] = &$bindParams[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $requestId = (int) ($row['request_id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }
        if (!isset($documents[$requestId])) {
            $documents[$requestId] = [];
        }
        $documents[$requestId][] = $row;
    }

    $stmt->close();
    return $documents;
}

/**
 * @param array<int, int> $requestIds
 * @return array<int, array<int, array<string, mixed>>>
 */
function pswdo_ai_history_by_document(mysqli $connection, array $requestIds): array
{
    if (count($requestIds) === 0) return [];
    $history = [];
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $sql = 'SELECT h.request_document_id, h.previous_status, h.archive_reason, h.archived_at FROM document_ai_review_history h INNER JOIN aics_request_documents d ON d.id = h.request_document_id WHERE d.request_id IN (' . $placeholders . ') ORDER BY h.archived_at DESC, h.id DESC';
    $stmt = $connection->prepare($sql);
    if (!$stmt) return [];
    $types = str_repeat('i', count($requestIds));
    $params = [$types];
    foreach ($requestIds as $requestId) $params[] = $requestId;
    $refs = [];
    foreach ($params as $index => $value) $refs[$index] = &$params[$index];
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) break;
        $documentId = (int) ($row['request_document_id'] ?? 0);
        if ($documentId > 0) $history[$documentId][] = $row;
    }
    $stmt->close();
    return $history;
}

/**
 * @param array<int, int> $requestIds
 * @return array<int, array<int, array<string, mixed>>>
 */
function pswdo_status_timeline_by_request(mysqli $connection, array $requestIds): array
{
    $timeline = [];
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $sql = <<<SQL
        SELECT
            h.request_id,
            s.status_code,
            s.status_name,
            h.remarks,
            h.changed_at,
            CONCAT_WS(' ', sp.first_name, sp.last_name) AS changed_by_name
        FROM aics_request_status_history h
        INNER JOIN aics_request_statuses s ON s.id = h.status_id
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = h.changed_by_account_id
        WHERE h.request_id IN ($placeholders)
        ORDER BY h.changed_at DESC, h.id DESC
    SQL;

    $stmt = $connection->prepare($sql);
    $bindTypes = str_repeat('i', count($requestIds));
    $bindParams = [$bindTypes];
    foreach ($requestIds as $value) {
        $bindParams[] = $value;
    }
    $refs = [];
    foreach ($bindParams as $index => $value) {
        $refs[$index] = &$bindParams[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $requestId = (int) ($row['request_id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }
        if (!isset($timeline[$requestId])) {
            $timeline[$requestId] = [];
        }
        if (count($timeline[$requestId]) >= 5) {
            continue;
        }
        $timeline[$requestId][] = [
            'status_code' => strtolower((string) ($row['status_code'] ?? 'pending_review')),
            'status_name' => (string) ($row['status_name'] ?? 'Pending Review'),
            'remarks' => trim((string) ($row['remarks'] ?? '')),
            'changed_at_label' => pswdo_datetime_label((string) ($row['changed_at'] ?? '')),
            'changed_by_name' => trim((string) ($row['changed_by_name'] ?? '')),
        ];
    }

    $stmt->close();
    return $timeline;
}

/**
 * @param array<int, int> $requestIds
 * @return array<int, array<string, mixed>>
 */
function pswdo_validation_by_request(mysqli $connection, array $requestIds): array
{
    if (count($requestIds) === 0) {
        return [];
    }

    $rows = [];
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $sql = "SELECT request_id, duplicate_flag, repeated_claim_flag, inconsistency_flag, risk_score, validation_note FROM aics_request_validation_results WHERE request_id IN ($placeholders)";
    $stmt = $connection->prepare($sql);
    $bindTypes = str_repeat('i', count($requestIds));
    $bindParams = [$bindTypes];
    foreach ($requestIds as $value) {
        $bindParams[] = $value;
    }
    $refs = [];
    foreach ($bindParams as $index => $value) {
        $refs[$index] = &$bindParams[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $requestId = (int) ($row['request_id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }
        $rows[$requestId] = [
            'duplicate_flag' => ((int) ($row['duplicate_flag'] ?? 0) === 1),
            'repeated_claim_flag' => ((int) ($row['repeated_claim_flag'] ?? 0) === 1),
            'inconsistency_flag' => ((int) ($row['inconsistency_flag'] ?? 0) === 1),
            'risk_score' => (int) ($row['risk_score'] ?? 0),
            'validation_note' => trim((string) ($row['validation_note'] ?? '')),
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * @param array<int, int> $requestIds
 * @return array<int, array<string, mixed>>
 */
function pswdo_priority_by_request(mysqli $connection, array $requestIds): array
{
    if (count($requestIds) === 0) {
        return [];
    }

    $rows = [];
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $sql = "SELECT request_id, urgency_score, urgency_indicator, vulnerability_score, readiness_score, risk_penalty, priority_score, priority_rank, priority_level, priority_basis FROM aics_request_prioritization WHERE request_id IN ($placeholders)";
    $stmt = $connection->prepare($sql);
    $bindTypes = str_repeat('i', count($requestIds));
    $bindParams = [$bindTypes];
    foreach ($requestIds as $value) {
        $bindParams[] = $value;
    }
    $refs = [];
    foreach ($bindParams as $index => $value) {
        $refs[$index] = &$bindParams[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $requestId = (int) ($row['request_id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }
        $rows[$requestId] = [
            'urgency_score' => (int) ($row['urgency_score'] ?? 0),
            'urgency_indicator' => trim((string) ($row['urgency_indicator'] ?? '')),
            'vulnerability_score' => (int) ($row['vulnerability_score'] ?? 0),
            'readiness_score' => (int) ($row['readiness_score'] ?? 0),
            'risk_penalty' => (int) ($row['risk_penalty'] ?? 0),
            'priority_score' => (int) ($row['priority_score'] ?? 0),
            'priority_rank' => ($row['priority_rank'] !== null ? (int) $row['priority_rank'] : null),
            'priority_level' => (string) ($row['priority_level'] ?? 'NORMAL'),
            'priority_basis' => trim((string) ($row['priority_basis'] ?? '')),
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * @return array<string, array<int, string>>
 */
function pswdo_required_documents_by_assistance(mysqli $connection): array
{
    $map = [];
    $sql = <<<SQL
        SELECT at.assistance_code, dt.document_code
        FROM aics_assistance_required_documents ard
        INNER JOIN aics_assistance_types at ON at.id = ard.assistance_type_id
        INNER JOIN aics_document_types dt ON dt.id = ard.document_type_id
        ORDER BY at.sort_order ASC, ard.sort_order ASC
    SQL;

    $result = $connection->query($sql);
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $assistanceCode = strtolower(trim((string) ($row['assistance_code'] ?? '')));
        $documentCode = strtolower(trim((string) ($row['document_code'] ?? '')));
        if (
            $assistanceCode === '' ||
            $documentCode === '' ||
            $documentCode === 'coe_opswdo' ||
            $documentCode === 'valid_id_back' ||
            (in_array($assistanceCode, ['medical', 'burial'], true) && $documentCode === 'coe_mswdo')
        ) {
            continue;
        }
        if (!isset($map[$assistanceCode])) {
            $map[$assistanceCode] = [];
        }
        $map[$assistanceCode][] = $documentCode;
    }

    return $map;
}

/**
 * The AICS AI urgency findings saved for a request. 'analyzed' is true once a
 * finished analysis was saved; page loads then use it and never re-check.
 *
 * @return array{indicator:string,basis:string,analyzed:bool}
 */
function pswdo_saved_urgency(mysqli $connection, int $requestId): array
{
    $stmt = $connection->prepare('SELECT urgency_indicator, urgency_basis, urgency_analyzed_at FROM aics_requests WHERE id = ? LIMIT 1');
    $row = [];
    if ($stmt) {
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc() ?: [];
        $stmt->close();
    }
    $indicator = strtoupper(trim((string) ($row['urgency_indicator'] ?? '')));
    if (!in_array($indicator, ['URGENT', 'HIGH', 'STANDARD'], true)) $indicator = '';
    $basis = trim((string) ($row['urgency_basis'] ?? ''));
    return ['indicator' => $indicator, 'basis' => $basis, 'analyzed' => $indicator !== '' && $basis !== '' && ($row['urgency_analyzed_at'] ?? null) !== null];
}

/** Why a request is URGENT, HIGH or STANDARD, in words PSWDO staff can act on. */
function pswdo_priority_reason(string $indicator, array $categories, string $assistanceCode, string $basis, bool $waiting): string
{
    if (strtolower(trim($assistanceCode)) !== 'medical') return 'Standard: Smart Priority applies to Medical Assistance only.';
    $who = in_array('senior_citizen', $categories, true) ? 'a Senior Citizen' : (in_array('pwd', $categories, true) ? 'a PWD' : 'a Senior Citizen or PWD');
    $detail = trim((string) preg_replace([
        '/^Gemini priority analysis found sufficient current medical urgency\.\s*/i',
        '/^(Senior\/PWD )?Medical Assistance; no sufficient current urgency evidence\.\s*/i',
    ], '', $basis));
    $reason = match ($indicator) {
        'URGENT' => $waiting ? 'Urgent: an earlier check found a current medical need.' : 'Urgent: AICS AI found a current medical need in the medical documents.',
        'HIGH' => 'High: the beneficiary is ' . $who . ' requesting Medical Assistance.',
        default => 'Standard: Medical Assistance for a beneficiary who is not a Senior Citizen or PWD.',
    };
    if ($waiting) {
        $reason .= ' AICS AI is still checking the medical documents for urgency; its findings will be saved here.';
    } elseif ($indicator === 'URGENT') {
        $reason .= $detail !== '' ? ' ' . $detail : '';
    } elseif (stripos($detail, 'no current urgent trigger detected') !== false) {
        $reason .= ' No medical certificate or abstract was uploaded to check for urgency.';
    } elseif (stripos($detail, 'document is unavailable') !== false) {
        $reason .= ' A medical document could not be opened for the urgency check.';
    } else {
        $reason .= ' AICS AI found no current medical urgency.' . ($detail !== '' ? ' ' . $detail : '');
    }
    return $reason;
}

/**
 * Page loads pass $allowAnalysis = false: saved AICS AI findings are used as they
 * are; a request with none uses a cached analysis (and saves it) or waits for
 * tools/smart-priority-refresh.php, which runs the analysis. A failed analysis
 * never replaces saved findings.
 *
 * @param array<string, mixed> $request
 */
function pswdo_refresh_request_scoring(mysqli $connection, array $request, ?int $updatedByAccountId, bool $allowAnalysis = false): void
{
    $requestId = (int) ($request['id'] ?? 0);
    if ($requestId <= 0) return;

    $duplicates = pswdo_find_application_duplicates($connection, $requestId);
    $duplicateFlag = (bool) $duplicates['has_duplicate'];
    $validationNote = $duplicateFlag ? (string) $duplicates['summary'] : 'No major validation risks detected.';
    $riskScore = $duplicateFlag ? 40 : 0;
    $valStmt = $connection->prepare('INSERT INTO aics_request_validation_results (request_id, duplicate_flag, repeated_claim_flag, inconsistency_flag, risk_score, validation_note, updated_by_account_id) VALUES (?, ?, 0, 0, ?, ?, ?) ON DUPLICATE KEY UPDATE duplicate_flag = VALUES(duplicate_flag), repeated_claim_flag = 0, inconsistency_flag = 0, risk_score = VALUES(risk_score), validation_note = VALUES(validation_note), updated_by_account_id = VALUES(updated_by_account_id), updated_at = CURRENT_TIMESTAMP');
    $duplicateFlagInt = $duplicateFlag ? 1 : 0;
    $valStmt->bind_param('iiisi', $requestId, $duplicateFlagInt, $riskScore, $validationNote, $updatedByAccountId);
    $valStmt->execute();
    $valStmt->close();

    $priority = pswdo_calculate_application_priority($request);
    $categories = json_decode((string) ($request['client_categories_json'] ?? '[]'), true);
    $categories = is_array($categories) ? array_map(static fn($value): string => strtolower(trim((string) $value)), $categories) : [];
    $assistanceCode = (string) ($request['assistance_code'] ?? '');
    $analysisEnabled = aics_smart_priority_analysis_enabled();
    $saved = $analysisEnabled ? pswdo_saved_urgency($connection, $requestId) : ['indicator' => '', 'basis' => '', 'analyzed' => false];
    $waiting = false;
    $save = false;
    if ($saved['analyzed'] && !$allowAnalysis) {
        $urgency = ['indicator' => $saved['indicator'], 'basis' => $saved['basis']];
    } else {
        $urgency = $analysisEnabled
            ? aics_smart_priority_for_request($connection, $requestId, $categories, $assistanceCode, $allowAnalysis)
            : aics_medical_urgency_from_document_reviews($connection, $requestId, $assistanceCode);
        if (!$analysisEnabled) {
            // Document-review urgency is recomputed from the database each time.
        } elseif (empty($urgency['pending']) && ($urgency['final'] ?? false) === true) {
            $save = true;
        } elseif ($saved['analyzed']) {
            $urgency = ['indicator' => $saved['indicator'], 'basis' => $saved['basis']];
        } else {
            // Not analysed yet (or the analysis failed): keep the stored indicator so it never flips.
            if ($saved['indicator'] !== '') $urgency['indicator'] = $saved['indicator'];
            $waiting = true;
        }
    }
    if ($analysisEnabled) $priority = aics_smart_priority_storage_values($urgency['indicator']);
    $categoryBasis = in_array('senior_citizen', $categories, true) ? 'Senior Citizen' : (in_array('pwd', $categories, true) ? 'PWD' : 'Other beneficiary category');
    $reason = $analysisEnabled ? pswdo_priority_reason($urgency['indicator'], $categories, $assistanceCode, $urgency['basis'], $waiting) : $urgency['basis'];
    $basis = mb_strimwidth($categoryBasis . ' | ' . pswdo_assistance_label($assistanceCode) . ' | ' . $reason, 0, 700, '…');
    if ($save) {
        $urgencyBasis = mb_strimwidth((string) $urgency['basis'], 0, 700, '…');
        $requestUpdate = $connection->prepare('UPDATE aics_requests SET urgency_indicator = ?, urgency_basis = ?, urgency_analyzed_at = NOW() WHERE id = ? LIMIT 1');
        if ($requestUpdate) { $requestUpdate->bind_param('ssi', $urgency['indicator'], $urgencyBasis, $requestId); $requestUpdate->execute(); $requestUpdate->close(); }
    } else {
        $requestUpdate = $connection->prepare('UPDATE aics_requests SET urgency_indicator = ? WHERE id = ? LIMIT 1');
        if ($requestUpdate) { $requestUpdate->bind_param('si', $urgency['indicator'], $requestId); $requestUpdate->execute(); $requestUpdate->close(); }
    }
    $priStmt = $connection->prepare('INSERT INTO aics_request_prioritization (request_id, urgency_score, urgency_indicator, vulnerability_score, readiness_score, risk_penalty, priority_score, priority_level, priority_basis, updated_by_account_id) VALUES (?, 0, ?, 0, 0, 0, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE urgency_score = 0, urgency_indicator = VALUES(urgency_indicator), vulnerability_score = 0, readiness_score = 0, risk_penalty = 0, priority_score = VALUES(priority_score), priority_level = VALUES(priority_level), priority_basis = VALUES(priority_basis), updated_by_account_id = VALUES(updated_by_account_id), updated_at = CURRENT_TIMESTAMP');
    $priStmt->bind_param('isissi', $requestId, $urgency['indicator'], $priority['score'], $priority['label'], $basis, $updatedByAccountId);
    $priStmt->execute();
    $priStmt->close();
    // A new finished analysis: the applicant sees the same priority and expected time as PSWDO.
    if ($save || !$analysisEnabled) aics_ai_gate_sync_priority($connection, $requestId, (string) $urgency['indicator']);
}

/** @return array{score:int,label:string} */
function pswdo_calculate_application_priority(array $request): array
{
    $categories = json_decode((string) ($request['client_categories_json'] ?? '[]'), true);
    $categories = is_array($categories) ? array_map(static fn($v): string => strtolower(trim((string) $v)), $categories) : [];
    return aics_priority_from_beneficiary_categories($categories);
}

/** Refresh advisory priority from existing document-review results before queue ranking. */
function pswdo_refresh_smart_priorities(mysqli $connection): void
{
    $sql = "SELECT r.id, r.client_categories_json, r.assistance_types_json, at.assistance_code FROM aics_requests r LEFT JOIN (SELECT request_id, MIN(assistance_type_id) AS assistance_type_id FROM aics_request_assistance GROUP BY request_id) ra ON ra.request_id = r.id LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id WHERE LOWER(TRIM(r.request_status)) NOT IN ('released', 'not approved', 'not_approved', 'cancelled') AND " . aics_ai_gate_visible_sql('r');
    $result = $connection->query($sql);
    while ($row = $result?->fetch_assoc()) {
        $assistance = trim((string) ($row['assistance_code'] ?? ''));
        if ($assistance === '') $assistance = pswdo_extract_first_assistance_from_json((string) ($row['assistance_types_json'] ?? '[]'));
        $row['assistance_code'] = $assistance;
        pswdo_refresh_request_scoring($connection, $row, null);
    }
}

/**
 * Medical requests PSWDO can see whose AICS AI urgency findings were never saved,
 * or whose documents or details changed after the saved analysis. Newest first.
 *
 * @return list<array<string, mixed>>
 */
function pswdo_fetch_requests_needing_urgency_analysis(mysqli $connection, int $limit): array
{
    $result = $connection->query("SELECT r.id, r.reference_no, r.client_categories_json, r.assistance_types_json, at.assistance_code
        FROM aics_requests r
        LEFT JOIN (SELECT request_id, MIN(assistance_type_id) AS assistance_type_id FROM aics_request_assistance GROUP BY request_id) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        WHERE " . aics_ai_gate_visible_sql('r') . " AND " . aics_ai_gate_active_sql('r') . "
          AND LOWER(TRIM(r.request_status)) NOT IN ('released', 'not approved', 'not_approved', 'rejected', 'denied', 'completed', 'closed', 'cancelled')
          AND " . aics_ai_gate_urgency_stale_sql('r') . "
        ORDER BY r.id DESC");
    $rows = [];
    while (count($rows) < $limit && ($row = $result?->fetch_assoc())) {
        $assistance = trim((string) ($row['assistance_code'] ?? ''));
        if ($assistance === '') $assistance = pswdo_extract_first_assistance_from_json((string) ($row['assistance_types_json'] ?? '[]'));
        if ($assistance !== 'medical') continue;
        $row['assistance_code'] = $assistance;
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Run and save the AICS AI Smart Priority analysis of one request (background
 * job only: it can take several seconds). Used by tools/ai-gate-sync.php so a
 * Medical request reaches PSWDO with its final priority.
 */
function pswdo_analyse_request_priority(mysqli $connection, int $requestId): void
{
    $stmt = $connection->prepare("SELECT r.id, r.reference_no, r.client_categories_json, r.assistance_types_json, at.assistance_code
        FROM aics_requests r
        LEFT JOIN (SELECT request_id, MIN(assistance_type_id) AS assistance_type_id FROM aics_request_assistance GROUP BY request_id) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        WHERE r.id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    if (!is_array($row)) return;
    $assistance = trim((string) ($row['assistance_code'] ?? ''));
    $row['assistance_code'] = $assistance !== '' ? $assistance : pswdo_extract_first_assistance_from_json((string) ($row['assistance_types_json'] ?? '[]'));
    pswdo_refresh_request_scoring($connection, $row, null, true);
}

function pswdo_normalize_name_for_match(string $name): string
{
    $clean = strtolower(trim($name));
    $replaced = preg_replace('/[^a-z0-9\s]/', ' ', $clean);
    $clean = is_string($replaced) ? $replaced : $clean;
    $collapsed = preg_replace('/\s+/', ' ', $clean);
    $clean = is_string($collapsed) ? $collapsed : $clean;
    return trim($clean);
}

function pswdo_names_match_loose(string $a, string $b): bool
{
    $na = pswdo_normalize_name_for_match($a);
    $nb = pswdo_normalize_name_for_match($b);
    if ($na === '' || $nb === '') {
        return false;
    }
    if ($na === $nb) {
        return true;
    }
    $tokensA = array_values(array_filter(explode(' ', $na), static fn ($t): bool => strlen($t) >= 2));
    $tokensB = array_values(array_filter(explode(' ', $nb), static fn ($t): bool => strlen($t) >= 2));
    if (count($tokensA) === 0 || count($tokensB) === 0) {
        return false;
    }
    return count(array_intersect($tokensA, $tokensB)) >= 2;
}

/**
 * @return array<int, string>
 */
function pswdo_assistance_codes_from_json(string $json): array
{
    $decoded = json_decode($json !== '' ? $json : '[]', true);
    if (!is_array($decoded)) {
        return [];
    }
    $codes = [];
    foreach ($decoded as $item) {
        if (is_string($item) && trim($item) !== '') {
            $codes[] = strtolower(trim($item));
        }
    }
    return array_values(array_unique($codes));
}

function pswdo_assistance_codes_overlap(string $jsonA, string $jsonB): bool
{
    $a = pswdo_assistance_codes_from_json($jsonA);
    $b = pswdo_assistance_codes_from_json($jsonB);
    if (count($a) === 0 || count($b) === 0) {
        return false;
    }
    return count(array_intersect($a, $b)) > 0;
}

/**
 * @return array<int, string>
 */
function pswdo_family_member_names_from_json(string $json): array
{
    $decoded = json_decode($json !== '' ? $json : '[]', true);
    if (!is_array($decoded)) {
        return [];
    }
    $names = [];
    foreach ($decoded as $member) {
        if (!is_array($member)) {
            continue;
        }
        $name = trim((string) ($member['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

/**
 * Detect family-composition duplicates for a request.
 *
 * Direction A: this request's beneficiary appears in another active request's family composition.
 * Direction B: a family member listed in this request is the beneficiary of another active request.
 *
 * Only counts when the other request shares at least one assistance type with this one.
 *
 * @return array{
 *   has_match: bool,
 *   matches: array<int, array{
 *     other_request_id: int,
 *     other_reference_no: string,
 *     matched_name: string,
 *     direction: string
 *   }>
 * }
 */
function pswdo_family_member_duplicate_matches(mysqli $connection, int $requestId): array
{
    // Compatibility stub: household data is no longer a duplicate signal.
    return ['has_match' => false, 'matches' => []];

    $empty = ['has_match' => false, 'matches' => []];
    if ($requestId <= 0) {
        return $empty;
    }

    $selfStmt = $connection->prepare(
        'SELECT beneficiary_complete_name, family_composition_json, assistance_types_json
         FROM aics_requests WHERE id = ? LIMIT 1'
    );
    $selfStmt->bind_param('i', $requestId);
    $selfStmt->execute();
    $selfRow = $selfStmt->get_result()?->fetch_assoc();
    $selfStmt->close();

    if (!is_array($selfRow)) {
        return $empty;
    }

    $selfBeneficiary = trim((string) ($selfRow['beneficiary_complete_name'] ?? ''));
    $selfAssistanceJson = (string) ($selfRow['assistance_types_json'] ?? '[]');
    $selfFamilyJson = (string) ($selfRow['family_composition_json'] ?? '[]');
    $selfFamilyNames = pswdo_family_member_names_from_json($selfFamilyJson);

    if (pswdo_assistance_codes_from_json($selfAssistanceJson) === []) {
        return $empty;
    }
    if ($selfBeneficiary === '' && count($selfFamilyNames) === 0) {
        return $empty;
    }

    $stmt = $connection->prepare(
        "SELECT id, reference_no, beneficiary_complete_name, family_composition_json, assistance_types_json
         FROM aics_requests
         WHERE id <> ?
           AND LOWER(TRIM(COALESCE(request_status,''))) NOT IN (
             'rejected', 'denied', 'not_approved', 'not approved',
             'cancelled', 'canceled', 'released', 'distributed', 'completed', 'closed'
           )"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $result = $stmt->get_result();

    $matches = [];
    $seen = [];

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $otherAssistanceJson = (string) ($row['assistance_types_json'] ?? '[]');
        if (!pswdo_assistance_codes_overlap($selfAssistanceJson, $otherAssistanceJson)) {
            continue;
        }

        $otherId = (int) ($row['id'] ?? 0);
        $otherRef = trim((string) ($row['reference_no'] ?? ''));
        $otherBeneficiary = trim((string) ($row['beneficiary_complete_name'] ?? ''));
        $otherFamilyNames = pswdo_family_member_names_from_json((string) ($row['family_composition_json'] ?? '[]'));

        // Direction A: this beneficiary appears in other request's family composition
        if ($selfBeneficiary !== '') {
            foreach ($otherFamilyNames as $memberName) {
                if (pswdo_names_match_loose($selfBeneficiary, $memberName)) {
                    $key = $otherId . '|A';
                    if (!isset($seen[$key])) {
                        $matches[] = [
                            'other_request_id' => $otherId,
                            'other_reference_no' => $otherRef,
                            'matched_name' => $selfBeneficiary,
                            'direction' => 'beneficiary_in_other_family',
                        ];
                        $seen[$key] = true;
                    }
                    break;
                }
            }
        }

        // Direction B: a family member of this request is beneficiary of the other
        if ($otherBeneficiary !== '') {
            foreach ($selfFamilyNames as $familyName) {
                if (pswdo_names_match_loose($familyName, $otherBeneficiary)) {
                    $key = $otherId . '|B';
                    if (!isset($seen[$key])) {
                        $matches[] = [
                            'other_request_id' => $otherId,
                            'other_reference_no' => $otherRef,
                            'matched_name' => $familyName,
                            'direction' => 'family_member_has_own_request',
                        ];
                        $seen[$key] = true;
                    }
                    break;
                }
            }
        }
    }
    $stmt->close();

    return [
        'has_match' => count($matches) > 0,
        'matches' => $matches,
    ];
}

/**
 * @param array<int, array{matched_name:string, other_reference_no:string, direction:string}> $matches
 */
function pswdo_build_family_duplicate_block_message(array $matches): string
{
    if (count($matches) === 0) {
        return 'Family-member duplicate detected. Approval is blocked.';
    }

    $primary = $matches[0];
    $direction = (string) ($primary['direction'] ?? '');
    $matchedName = trim((string) ($primary['matched_name'] ?? ''));
    $refs = array_values(array_unique(array_filter(array_map(
        static fn ($m): string => trim((string) ($m['other_reference_no'] ?? '')),
        $matches
    ))));

    $base = $direction === 'family_member_has_own_request'
        ? 'This family member (' . ($matchedName !== '' ? $matchedName : 'listed in family composition') . ') already applied for the same assistance.'
        : 'This beneficiary (' . ($matchedName !== '' ? $matchedName : '') . ') is listed as a family member in another active request for the same assistance.';

    if (count($refs) > 0) {
        $base .= ' Matching request(s): ' . implode(', ', $refs) . '.';
    }

    return $base . ' Approval is blocked. Please review and mark the incorrect duplicate request as Not Approved.';
}

/**
 * @return array<int, string>
 */
function pswdo_duplicate_contact_candidates(string $contactNumber): array
{
    $digits = preg_replace('/\D+/', '', $contactNumber);
    if (!is_string($digits) || $digits === '') {
        return [];
    }

    $candidates = [$digits];

    if (str_starts_with($digits, '09') && strlen($digits) === 11) {
        $candidates[] = '63' . substr($digits, 1);
    }
    if (str_starts_with($digits, '639') && strlen($digits) === 12) {
        $candidates[] = '0' . substr($digits, 2);
    }
    if (str_starts_with($digits, '9') && strlen($digits) === 10) {
        $candidates[] = '0' . $digits;
        $candidates[] = '63' . $digits;
    }

    return array_values(array_unique(array_filter($candidates, static fn ($value): bool => is_string($value) && $value !== '')));
}

/**
 * Find review-only duplicates using applicant and beneficiary identities.
 * Family composition is deliberately not read or considered here.
 *
 * @return array{has_duplicate:bool,matches:array<int,array<string,mixed>>,summary:string}
 */
function pswdo_find_application_duplicates(mysqli $connection, int $requestId): array
{
    // Shared with the applicant submit step: names compared part by part,
    // birth-date typos, and the same file or photo in another request.
    return aics_find_application_duplicates($connection, $requestId);
}

/**
 * @param array<string, mixed> $validation
 */
function pswdo_should_block_duplicate_approval(array $validation): bool
{
    return false;
}

/**
 * @param array<int, string> $referenceNumbers
 */
function pswdo_build_duplicate_block_message(array $referenceNumbers): string
{
    $cleanRefs = array_values(array_unique(array_filter(array_map(
        static fn ($ref): string => trim((string) $ref),
        $referenceNumbers
    ))));

    if (count($cleanRefs) === 0) {
        return 'Possible duplicate entry detected for this beneficiary/contact. Please review before making a final decision.';
    }

    return 'Possible duplicate entry detected. Please review the matching request(s) before making a final decision: ' . implode(', ', $cleanRefs) . '.';
}

/**
 * @return array{
 *   has_duplicate:bool,
 *   matching_request_ids:array<int, int>,
 *   matching_reference_nos:array<int, string>
 * }
 */
function pswdo_duplicate_matches_for_request(mysqli $connection, int $requestId): array
{
    $reviewMatches = pswdo_find_application_duplicates($connection, $requestId);
    $matches = (array) ($reviewMatches['matches'] ?? []);
    return [
        'has_duplicate' => (bool) ($reviewMatches['has_duplicate'] ?? false),
        'matching_request_ids' => array_values(array_unique(array_map(static fn ($match): int => (int) ($match['request_id'] ?? 0), $matches))),
        'matching_reference_nos' => array_values(array_unique(array_filter(array_map(static fn ($match): string => trim((string) ($match['reference_no'] ?? '')), $matches)))),
    ];

    $emptyResult = [
        'has_duplicate' => false,
        'matching_request_ids' => [],
        'matching_reference_nos' => [],
    ];

    if ($requestId <= 0) {
        return $emptyResult;
    }

    $lookup = $connection->prepare('SELECT beneficiary_complete_name, contact_number FROM aics_requests WHERE id = ? LIMIT 1');
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc();
    $lookup->close();

    if (!is_array($row)) {
        return $emptyResult;
    }

    $beneficiaryName = trim((string) ($row['beneficiary_complete_name'] ?? ''));
    $contactNumber = trim((string) ($row['contact_number'] ?? ''));
    if ($beneficiaryName === '' || $contactNumber === '') {
        return $emptyResult;
    }

    $contactCandidates = pswdo_duplicate_contact_candidates($contactNumber);
    if (count($contactCandidates) === 0) {
        return $emptyResult;
    }

    $contactExpression = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(contact_number,' ',''),'-',''),'(',''),')',''),'+','')";
    $placeholders = implode(',', array_fill(0, count($contactCandidates), '?'));
    $sql = <<<SQL
        SELECT id, reference_no
        FROM aics_requests
        WHERE id <> ?
          AND beneficiary_complete_name = ?
          AND $contactExpression IN ($placeholders)
          AND LOWER(TRIM(request_status)) NOT IN (
            'rejected', 'denied', 'not_approved', 'not approved',
            'cancelled', 'canceled', 'released', 'distributed', 'completed', 'closed'
          )
        ORDER BY id DESC
        LIMIT 5
    SQL;

    $stmt = $connection->prepare($sql);
    $bindTypes = 'is' . str_repeat('s', count($contactCandidates));
    $bindParams = [$bindTypes, $requestId, $beneficiaryName];
    foreach ($contactCandidates as $candidate) {
        $bindParams[] = $candidate;
    }

    $refs = [];
    foreach ($bindParams as $index => $value) {
        $refs[$index] = &$bindParams[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();

    $matchingIds = [];
    $matchingRefs = [];
    while ($match = $result?->fetch_assoc()) {
        if (!is_array($match)) {
            break;
        }

        $matchId = (int) ($match['id'] ?? 0);
        if ($matchId <= 0) {
            continue;
        }

        $matchingIds[] = $matchId;
        $matchingRef = trim((string) ($match['reference_no'] ?? ''));
        if ($matchingRef !== '') {
            $matchingRefs[] = $matchingRef;
        }
    }
    $stmt->close();

    $matchingIds = array_values(array_unique($matchingIds));
    $matchingRefs = array_values(array_unique($matchingRefs));

    return [
        'has_duplicate' => count($matchingIds) > 0,
        'matching_request_ids' => $matchingIds,
        'matching_reference_nos' => $matchingRefs,
    ];
}

function pswdo_recalculate_priority_ranks(mysqli $connection): void
{
    $smartPriorityOrderSql = pswdo_smart_priority_order_case_sql('rp');
    $sql = <<<SQL
        SELECT rp.request_id, rp.priority_score, rp.priority_level, rp.urgency_indicator, r.request_status
        FROM aics_request_prioritization rp
        INNER JOIN aics_requests r ON r.id = rp.request_id
        ORDER BY {$smartPriorityOrderSql} ASC, COALESCE(NULLIF(r.date_applied, '0000-00-00'), DATE(r.created_at)) ASC, r.id ASC
    SQL;
    $result = $connection->query($sql);
    $rank = 1;

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $requestId = (int) ($row['request_id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }
        $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'));
        $isTerminal = in_array($statusCode, ['released', 'not_approved', 'cancelled'], true);
        $priorityRank = $isTerminal ? null : $rank;

        $update = $connection->prepare('UPDATE aics_request_prioritization SET priority_rank = ? WHERE request_id = ?');
        $update->bind_param('ii', $priorityRank, $requestId);
        $update->execute();
        $update->close();

        if (!$isTerminal) {
            $rank++;
        }
    }
}

/**
 * @return array<string, mixed>
 */
function pswdo_update_request_status_action(mysqli $connection, int $requestId, string $newStatusCode, int $accountId, string $remarks): array
{
    $normalizedStatus = pswdo_normalize_status_code($newStatusCode);
    $statusMap = pswdo_status_to_request_string_map();
    $requestStatusValue = $statusMap[$normalizedStatus] ?? 'pending';

    $requestLookupStmt = $connection->prepare('SELECT reference_no FROM aics_requests WHERE id = ? LIMIT 1');
    $requestLookupStmt->bind_param('i', $requestId);
    $requestLookupStmt->execute();
    $requestRow = $requestLookupStmt->get_result()?->fetch_assoc();
    $requestLookupStmt->close();
    if (!is_array($requestRow)) {
        throw new RuntimeException('Request not found.');
    }
    $referenceNo = trim((string) ($requestRow['reference_no'] ?? ''));

    $statusLookupStmt = $connection->prepare('SELECT id FROM aics_request_statuses WHERE status_code = ? LIMIT 1');
    $statusLookupStmt->bind_param('s', $normalizedStatus);
    $statusLookupStmt->execute();
    $statusRow = $statusLookupStmt->get_result()?->fetch_assoc();
    $statusLookupStmt->close();

    $statusId = (int) ($statusRow['id'] ?? 0);
    if ($statusId <= 0) {
        throw new RuntimeException('Invalid status selected.');
    }

    $connection->begin_transaction();
    try {
        $update = $connection->prepare('UPDATE aics_requests SET request_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->bind_param('si', $requestStatusValue, $requestId);
        $update->execute();
        $update->close();

        $historyRemarks = trim($remarks);
        if ($historyRemarks === '') {
            $historyRemarks = 'Status updated by PSWDO personnel.';
        }

        $insertHistory = $connection->prepare(
            'INSERT INTO aics_request_status_history (request_id, status_id, remarks, changed_by_account_id, changed_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $insertHistory->bind_param('iisi', $requestId, $statusId, $historyRemarks, $accountId);
        $insertHistory->execute();
        $insertHistory->close();

        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }

    return [
        'reference_no' => $referenceNo,
        'status_code' => $normalizedStatus,
        'status_label' => pswdo_status_labels()[$normalizedStatus] ?? 'Updated',
    ];
}

/**
 * @return array<string, mixed>
 */
function pswdo_mark_request_in_progress_action(mysqli $connection, int $requestId, int $accountId): array
{
    $lookup = $connection->prepare('SELECT reference_no, beneficiary_complete_name, request_status FROM aics_requests WHERE id = ? LIMIT 1');
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc();
    $lookup->close();

    if (!is_array($row)) {
        throw new RuntimeException('Request not found.');
    }

    $referenceNo = trim((string) ($row['reference_no'] ?? ''));
    $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'));
    if (in_array($statusCode, ['released', 'not_approved', 'cancelled'], true)) {
        throw new RuntimeException('This request is already final and cannot be moved to in-progress.');
    }
    if ($statusCode === 'approved') {
        // A repeated approval must not generate a second applicant notice or
        // overwrite the original screening decision.
        throw new RuntimeException('This request is already approved for screening.');
    }

    // Duplicate matches are review flags only; PSWDO personnel retain approval authority.

    $prescreen = pswdo_evaluate_prescreening_readiness($connection, $requestId);
    $isReady = (bool) ($prescreen['is_ready'] ?? false);
    $requiredTotal = (int) ($prescreen['required_total'] ?? 0);
    $uploadedTotal = (int) ($prescreen['uploaded_total'] ?? 0);

    $targetStatus = $isReady ? 'approved' : 'incomplete_requirements';
    $historyRemarks = $isReady
        ? 'PSWDO confirmed requirements are complete and approved this application for screening.'
        : 'PSWDO flagged this request for incomplete requirements during screening confirmation.';

    if ($statusCode !== $targetStatus) {
        pswdo_update_request_status_action(
            $connection,
            $requestId,
            $targetStatus,
            $accountId,
            $historyRemarks
        );
    }

    $followupWarnings = [];
    try {
        pswdo_save_prescreening_decision_action(
            $connection,
            $requestId,
            $isReady ? 'for_screening' : 'incomplete_requirements',
            $requiredTotal,
            $uploadedTotal,
            max(0, $requiredTotal - $uploadedTotal),
            $accountId,
            $historyRemarks
        );
    } catch (Throwable $exception) {
        // The request status was already committed. Keep the visible result
        // accurate and report the secondary queue-write failure for diagnosis.
        $followupWarnings[] = 'screening decision log';
        error_log('AICS approval follow-up failed request_id=' . $requestId . ' step=screening_decision error=' . $exception->getMessage());
    }

    $screeningSmsSent = false;
    if ($isReady) {
        $notificationMessage = $referenceNo !== ''
            ? ('Your application ' . $referenceNo . ' is approved and now queued for screening.')
            : 'Your application is approved and now queued for screening.';
        $notificationStage = 'Approved for Screening';

        try {
            $formalSmsMessage = pswdo_default_sms_message('for_screening', $referenceNo, pswdo_request_identity($connection, $requestId)['name'], pswdo_uploaded_requirements($connection, $requestId));
            pswdo_send_sms_to_applicant_action($connection, $requestId, 'for_screening', $formalSmsMessage, $accountId, false);
            $screeningSmsSent = true;
        } catch (Throwable) {
            $screeningSmsSent = false;
        }

        // Safety cleanup: keep newly approved screening cases in Screening Queue.
        // Older behavior could auto-create an unscheduled release row too early.
        try {
            $cleanupRelease = $connection->prepare(
                "DELETE FROM aics_request_release_schedule WHERE request_id = ? AND release_status = 'unscheduled'"
            );
            if ($cleanupRelease) {
                $cleanupRelease->bind_param('i', $requestId);
                $cleanupRelease->execute();
                $cleanupRelease->close();
            }
        } catch (Throwable $exception) {
            $followupWarnings[] = 'release queue cleanup';
            error_log('AICS approval follow-up failed request_id=' . $requestId . ' step=release_cleanup error=' . $exception->getMessage());
        }
    } else {
        $notificationMessage = $referenceNo !== ''
            ? ('Your application ' . $referenceNo . ' is flagged for incomplete requirements (' . $uploadedTotal . ' of ' . $requiredTotal . ' required documents uploaded). Please complete the missing requirements.')
            : ('Your application is flagged for incomplete requirements (' . $uploadedTotal . ' of ' . $requiredTotal . ' required documents uploaded). Please complete the missing requirements.');
        $notificationStage = 'Incomplete Requirements';
    }

    try {
        pswdo_save_notification_action(
            $connection,
            $requestId,
            $notificationStage,
            $notificationMessage,
            $accountId
        );
    } catch (Throwable $exception) {
        $followupWarnings[] = 'applicant notification log';
        error_log('AICS approval follow-up failed request_id=' . $requestId . ' step=notification_log error=' . $exception->getMessage());
    }

    return [
        'reference_no' => $referenceNo,
        'applicant_name' => pswdo_request_identity($connection, $requestId)['name'],
        'status_code' => $targetStatus,
        'status_label' => pswdo_status_labels()[$targetStatus] ?? ($isReady ? 'Approved' : 'Incomplete Requirements'),
        'is_ready_for_prescreening' => $isReady,
        'screening_sms_sent' => $screeningSmsSent,
        'followup_warning' => $followupWarnings !== [],
        'required_total' => $requiredTotal,
        'uploaded_total' => $uploadedTotal,
    ];
}

/**
 * The applicant's name (the beneficiary's when missing) and reference, for staff-facing results.
 *
 * @return array{name: string, reference: string}
 */
function pswdo_request_identity(mysqli $connection, int $requestId): array
{
    $lookup = $connection->prepare('SELECT reference_no, beneficiary_complete_name, applicant_first_name, applicant_last_name FROM aics_requests WHERE id = ? LIMIT 1');
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc() ?? [];
    $lookup->close();

    $name = trim(trim((string) ($row['applicant_first_name'] ?? '')) . ' ' . trim((string) ($row['applicant_last_name'] ?? '')));
    if ($name === '') $name = trim((string) ($row['beneficiary_complete_name'] ?? ''));
    return [
        'name' => $name !== '' ? $name : 'Applicant',
        'reference' => trim((string) ($row['reference_no'] ?? '')),
    ];
}

/**
 * Approves each selected request like a single approval (status, screening
 * queue, applicant SMS) and sorts the results for the feedback card.
 *
 * @param array<int, mixed> $rawIds
 * @return array{approved: list<array{name: string, reference: string, sms_sent: bool}>, incomplete: list<array{name: string, reference: string, sms_sent: bool}>, failed: list<array{name: string, reference: string, reason: string}>}
 */
function pswdo_bulk_approve_requests_action(mysqli $connection, array $rawIds, int $accountId): array
{
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn (int $id): bool => $id > 0)));
    if ($requestIds === []) {
        throw new RuntimeException('Select at least one request to approve.');
    }

    $outcome = ['approved' => [], 'incomplete' => [], 'failed' => []];
    foreach (array_slice($requestIds, 0, 100) as $requestId) {
        try {
            if (!aics_ai_gate_is_released($connection, $requestId)) {
                throw new RuntimeException('This request is still being checked by AICS AI.');
            }
            $result = pswdo_mark_request_in_progress_action($connection, $requestId, $accountId);
            $outcome[$result['is_ready_for_prescreening'] ? 'approved' : 'incomplete'][] = [
                'name' => (string) $result['applicant_name'],
                'reference' => (string) $result['reference_no'],
                'sms_sent' => (bool) $result['screening_sms_sent'],
            ];
        } catch (Throwable $exception) {
            $outcome['failed'][] = pswdo_request_identity($connection, $requestId) + ['reason' => $exception->getMessage()];
        }
    }

    return $outcome;
}

function pswdo_save_prescreening_decision_action(
    mysqli $connection,
    int $requestId,
    string $decisionStatus,
    int $requiredTotal,
    int $uploadedTotal,
    int $missingTotal,
    int $accountId,
    string $remarks
): void {
    $decision = pswdo_normalize_prescreening_tab($decisionStatus);
    $remarksValue = trim($remarks);
    if ($remarksValue === '') {
        $remarksValue = match ($decision) {
            'for_screening' => 'Marked as ready for screening.',
            'incomplete_requirements' => 'Flagged for incomplete requirements.',
            'invalid_requirements' => 'Marked as invalid requirements and not approved.',
            default => 'Screening decision saved.',
        };
    }

    $required = max(0, $requiredTotal);
    $uploaded = max(0, $uploadedTotal);
    $missing = max(0, $missingTotal);

    $sql = <<<SQL
        INSERT INTO aics_request_prescreening (
            request_id,
            decision_status,
            required_total,
            uploaded_total,
            missing_total,
            remarks,
            confirmed_by_account_id,
            confirmed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            decision_status = VALUES(decision_status),
            required_total = VALUES(required_total),
            uploaded_total = VALUES(uploaded_total),
            missing_total = VALUES(missing_total),
            remarks = VALUES(remarks),
            confirmed_by_account_id = VALUES(confirmed_by_account_id),
            confirmed_at = CURRENT_TIMESTAMP
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('isiiisi', $requestId, $decision, $required, $uploaded, $missing, $remarksValue, $accountId);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array{
 *   assistance_code:string,
 *   required_total:int,
 *   uploaded_total:int,
 *   checked_total:int,
 *   staff_reviewed_total:int,
 *   approved_total:int,
 *   missing_total:int,
 *   is_ready:bool
 * }
 */
function pswdo_evaluate_prescreening_readiness(mysqli $connection, int $requestId): array
{
    $lookupSql = <<<SQL
        SELECT
            r.assistance_types_json,
            at.assistance_code
        FROM aics_requests r
        LEFT JOIN (
            SELECT request_id, MIN(assistance_type_id) AS assistance_type_id
            FROM aics_request_assistance
            GROUP BY request_id
        ) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        WHERE r.id = ?
        LIMIT 1
    SQL;

    $lookup = $connection->prepare($lookupSql);
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc();
    $lookup->close();

    if (!is_array($row)) {
        throw new RuntimeException('Request not found for screening evaluation.');
    }

    $assistanceCode = strtolower(trim((string) ($row['assistance_code'] ?? '')));
    if ($assistanceCode === '') {
        $assistanceCode = pswdo_extract_first_assistance_from_json((string) ($row['assistance_types_json'] ?? '[]'));
    }

    $requiredMap = pswdo_required_documents_by_assistance($connection);
    $requiredCodes = $requiredMap[$assistanceCode] ?? [];
    $requiredLookup = [];
    foreach ($requiredCodes as $code) {
        $normalizedCode = strtolower(trim((string) $code));
        if ($normalizedCode === '') {
            continue;
        }
        $requiredLookup[$normalizedCode] = true;
    }

    $uploadedLookup = [];
    $checkedLookup = [];
    $staffReviewedLookup = [];
    $approvedLookup = [];
    $uploadedStmt = $connection->prepare(
        'SELECT d.document_type, COALESCE(dr.is_checked, 0) AS is_checked,
                COALESCE(dr.review_status, \'pending\') AS review_status
         FROM aics_request_documents d
         LEFT JOIN aics_request_document_reviews dr ON dr.request_document_id = d.id
         WHERE d.request_id = ?'
    );
    $uploadedStmt->bind_param('i', $requestId);
    $uploadedStmt->execute();
    $uploadedResult = $uploadedStmt->get_result();
    while ($docRow = $uploadedResult?->fetch_assoc()) {
        if (!is_array($docRow)) {
            break;
        }
        $documentType = aics_normalize_document_type((string) ($docRow['document_type'] ?? ''));
        if ($documentType === '' || !isset($requiredLookup[$documentType])) {
            continue;
        }
        $uploadedLookup[$documentType] = true;
        $reviewStatus = strtolower(trim((string) ($docRow['review_status'] ?? 'pending')));
        $isApproved = (int) ($docRow['is_checked'] ?? 0) === 1 && $reviewStatus === 'checked';
        $isStaffReviewed = in_array($reviewStatus, ['checked', 'needs_reupload', 'invalid'], true);
        if ($isApproved) {
            $checkedLookup[$documentType] = true;
            $approvedLookup[$documentType] = true;
        }
        if ($isStaffReviewed) {
            $staffReviewedLookup[$documentType] = true;
        }
    }
    $uploadedStmt->close();

    $requiredTotal = count($requiredLookup);
    $uploadedTotal = count($uploadedLookup);
    $checkedTotal = count($checkedLookup);
    $staffReviewedTotal = count($staffReviewedLookup);
    $approvedTotal = count($approvedLookup);
    $missingTotal = max(0, $requiredTotal - $uploadedTotal);
    $uncheckedTotal = max(0, $uploadedTotal - $checkedTotal);
    $isReady = $requiredTotal === 0 || $missingTotal === 0;
    $allChecked = $requiredTotal === 0 || $checkedTotal >= $requiredTotal;
    $allStaffReviewed = $requiredTotal === 0 || $staffReviewedTotal >= $requiredTotal;
    $allApproved = $requiredTotal === 0 || $approvedTotal >= $requiredTotal;

    return [
        'assistance_code' => $assistanceCode,
        'required_total' => $requiredTotal,
        'uploaded_total' => $uploadedTotal,
        'checked_total' => $checkedTotal,
        'staff_reviewed_total' => $staffReviewedTotal,
        'approved_total' => $approvedTotal,
        'missing_total' => $missingTotal,
        'unchecked_total' => $uncheckedTotal,
        'is_ready' => $isReady,
        'all_checked' => $allChecked,
        'all_staff_reviewed' => $allStaffReviewed,
        'all_approved' => $allApproved,
    ];
}

function pswdo_toggle_document_check_action(mysqli $connection, int $documentId, bool $checked, int $accountId, string $remarks): void
{
    $checkedInt = $checked ? 1 : 0;
    $reviewStatus = $checked ? 'checked' : 'pending';
    $sql = <<<SQL
        INSERT INTO aics_request_document_reviews (request_document_id, reviewed_by_account_id, is_checked, review_status, remarks, reviewed_at)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            reviewed_by_account_id = VALUES(reviewed_by_account_id),
            is_checked = VALUES(is_checked),
            review_status = VALUES(review_status),
            remarks = VALUES(remarks),
            reviewed_at = CURRENT_TIMESTAMP
    SQL;
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('iiiss', $documentId, $accountId, $checkedInt, $reviewStatus, $remarks);
    $stmt->execute();
    $stmt->close();
}

function pswdo_set_document_review_status_action(
    mysqli $connection,
    int $requestId,
    int $documentId,
    string $reviewStatus,
    int $accountId,
    string $remarks = ''
): array {
    $normalizedStatus = strtolower(trim($reviewStatus));
    if (!in_array($normalizedStatus, ['pending', 'checked', 'needs_reupload', 'invalid'], true)) {
        throw new RuntimeException('Invalid document review status.');
    }

    $lookup = $connection->prepare(
        'SELECT d.id, d.request_id, d.document_type, d.original_name, r.reference_no, r.beneficiary_complete_name,
                r.applicant_contact_number, ap.contact_number AS profile_contact_number
         FROM aics_request_documents d
         INNER JOIN aics_requests r ON r.id = d.request_id
         LEFT JOIN aics_account_requests ar ON ar.request_id = r.id
         LEFT JOIN aics_applicant_profiles ap ON ap.account_id = ar.account_id
         WHERE d.id = ? AND d.request_id = ?
         LIMIT 1'
    );
    $lookup->bind_param('ii', $documentId, $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc();
    $lookup->close();
    if (!is_array($row)) {
        throw new RuntimeException('Document not found for this request.');
    }

    $isChecked = $normalizedStatus === 'checked' ? 1 : 0;
    $finalRemarks = trim($remarks);
    if ($finalRemarks === '') {
        $finalRemarks = match ($normalizedStatus) {
            'checked' => 'Document approved by PSWDO.',
            'needs_reupload' => 'PSWDO requested re-upload for this document.',
            'invalid' => 'PSWDO marked this document as invalid.',
            default => 'Document marked for follow-up review.',
        };
    }

    $upsert = $connection->prepare(
        'INSERT INTO aics_request_document_reviews (request_document_id, reviewed_by_account_id, is_checked, review_status, remarks, reviewed_at, notified_at)
         VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            reviewed_by_account_id = VALUES(reviewed_by_account_id),
            is_checked = VALUES(is_checked),
            review_status = VALUES(review_status),
            remarks = VALUES(remarks),
            reviewed_at = CURRENT_TIMESTAMP,
            notified_at = CURRENT_TIMESTAMP'
    );
    $upsert->bind_param('iiiss', $documentId, $accountId, $isChecked, $normalizedStatus, $finalRemarks);
    $upsert->execute();
    $upsert->close();

    return [
        'reference_no' => trim((string) ($row['reference_no'] ?? '')),
        'beneficiary_name' => trim((string) ($row['beneficiary_complete_name'] ?? 'Applicant')),
        'recipient_number' => pswdo_applicant_sms_contact($row),
        'document_type' => strtolower(trim((string) ($row['document_type'] ?? ''))),
        'document_name' => trim((string) ($row['original_name'] ?? 'Document')),
        'review_status' => $normalizedStatus,
        'remarks' => $finalRemarks,
    ];
}

function pswdo_save_notification_action(mysqli $connection, int $requestId, string $stage, string $message, int $accountId): void
{
    $stageValue = trim($stage) !== '' ? trim($stage) : 'General Update';
    $messageValue = trim($message) !== '' ? trim($message) : 'Your AICS request has a new update from PSWDO.';

    $stmt = $connection->prepare(
        'INSERT INTO aics_request_notifications (request_id, notification_stage, message_text, sent_by_account_id, sent_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->bind_param('issi', $requestId, $stageValue, $messageValue, $accountId);
    $stmt->execute();
    $stmt->close();
}

function pswdo_save_sms_delivery_audit(mysqli $connection, int $requestId, string $stage, string $message, int $accountId, string $status): void
{
    $deliveryStatus = in_array($status, ['sent', 'failed'], true) ? $status : 'failed';
    $channel = 'applicant_sms';
    $stmt = $connection->prepare('INSERT INTO aics_request_notifications (request_id, notification_stage, channel, delivery_status, message_text, sent_by_account_id, sent_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
    $stmt->bind_param('issssi', $requestId, $stage, $channel, $deliveryStatus, $message, $accountId);
    $stmt->execute();
    $stmt->close();
}

function pswdo_mswdo_notification_mode(): string
{
    return AICS_MSWDO_NOTIFICATION_MODE === 'live' ? 'live' : 'simulate';
}

/** Uses the same municipality list that powers the structured applicant address selector. */
function pswdo_resolve_mswdo_municipality(string $municipality): string
{
    static $canonical = null;
    $value = trim($municipality);
    if ($value === '') {
        throw new RuntimeException('MSWDO Notification: Unable to generate because municipality is missing.');
    }
    if ($canonical === null) {
        $canonical = [];
        $source = __DIR__ . '/../../Applicants/js files/antique-barangays.js';
        $contents = is_file($source) ? file_get_contents($source) : false;
        if (is_string($contents) && preg_match_all('/^\s*"([^"]+)"\s*:\s*\[/m', $contents, $matches)) {
            foreach ($matches[1] as $name) {
                $canonical[strtolower(trim((string) $name))] = trim((string) $name);
            }
        }
    }
    $key = strtolower($value);
    if ($canonical === [] || !isset($canonical[$key])) {
        throw new RuntimeException('MSWDO Notification: Unable to generate because municipality is invalid.');
    }
    return $canonical[$key];
}

/** @param array<int, array<string, mixed>> $items */
function pswdo_group_mswdo_notification_items(array $items): array
{
    $groups = [];
    foreach ($items as $item) {
        $municipality = pswdo_resolve_mswdo_municipality((string) ($item['municipality'] ?? ''));
        $groups[$municipality][] = $item;
    }
    return $groups;
}

/** @param array<int, array<string, mixed>> $items */
function pswdo_build_mswdo_notification_template(string $municipality, array $items): array
{
    $references = [];
    $names = [];
    $assistance = [];
    $contexts = [];
    foreach ($items as $item) {
        $references[] = trim((string) ($item['reference_no'] ?? '')) ?: 'Application reference unavailable';
        $name = trim((string) ($item['beneficiary_name'] ?? $item['applicant_name'] ?? ''));
        if ($name !== '') $names[] = $name;
        $type = trim((string) ($item['assistance_label'] ?? 'Assistance Request'));
        if ($type !== '') $assistance[] = $type;
        $date = trim((string) ($item['scheduled_date_label'] ?? ''));
        $time = trim((string) ($item['scheduled_time_label'] ?? ''));
        $venue = trim((string) ($item['venue'] ?? 'PSWDO Office')) ?: 'PSWDO Office';
        $contexts[] = trim(implode(' at ', array_filter([$date, $time]))) . ' — ' . $venue;
    }
    $references = array_values(array_unique($references));
    $names = array_values(array_unique($names));
    $assistance = array_values(array_unique($assistance));
    $contexts = array_values(array_unique($contexts));
    $subject = 'AICS Distribution Notice - ' . $municipality . ' MSWDO';
    $body = "SIMULATION ONLY\nNo external email was sent.\n\n"
        . "The Office of the Provincial Social Welfare and Development Officer is informing the Municipal Social Welfare and Development Office of {$municipality} regarding AICS applicant(s) scheduled/approved for assistance distribution.\n\n"
        . "Municipality: {$municipality}\nApplicants: " . count($references) . "\nApplication Reference(s):\n- " . implode("\n- ", $references) . "\n"
        . ($names !== [] ? "Applicant / Beneficiary for coordination:\n- " . implode("\n- ", $names) . "\n" : '')
        . "Assistance Type: " . implode(', ', $assistance ?: ['Assistance Request']) . "\n"
        . "Distribution / Release Context: " . implode('; ', $contexts ?: ['Release scheduling']) . "\n"
        . 'Generated Date/Time: ' . date('F j, Y g:i A') . "\nDelivery Mode: Simulation";
    return ['subject' => $subject, 'body' => $body];
}

function pswdo_deliver_mswdo_notification(array $template): array
{
    if (pswdo_mswdo_notification_mode() !== 'simulate') {
        return ['status' => 'failed', 'error' => 'MSWDO live delivery is not configured for this prototype.'];
    }
    // Simulation intentionally performs no external transport.
    return ['status' => 'simulated', 'error' => ''];
}

/** @param array<int, array<string, mixed>> $items */
function pswdo_record_mswdo_notification(mysqli $connection, array $items, int $accountId): array
{
    if ($items === []) throw new RuntimeException('MSWDO Notification: no applications were available.');
    $municipality = pswdo_resolve_mswdo_municipality((string) ($items[0]['municipality'] ?? ''));
    $template = pswdo_build_mswdo_notification_template($municipality, $items);
    $requestIds = array_values(array_filter(array_map(static fn(array $item): int => (int) ($item['request_id'] ?? 0), $items)));
    sort($requestIds);
    $batchKey = 'release:' . substr(hash('sha256', implode(',', $requestIds) . '|' . $municipality . '|' . $template['body']), 0, 32);
    $dedupeKey = hash('sha256', 'mswdo_notification|simulate|' . $batchKey);
    $existing = $connection->prepare('SELECT id, delivery_status FROM aics_request_notifications WHERE dedupe_key = ? LIMIT 1');
    $existing->bind_param('s', $dedupeKey);
    $existing->execute();
    $existingRow = $existing->get_result()?->fetch_assoc();
    $existing->close();
    if (is_array($existingRow)) {
        return ['municipality' => $municipality, 'status' => (string) ($existingRow['delivery_status'] ?? 'simulated'), 'template' => $template, 'duplicate' => true];
    }
    $delivery = pswdo_deliver_mswdo_notification($template);
    $status = (string) ($delivery['status'] ?? 'failed');
    $requestId = $requestIds[0] ?? 0;
    if ($requestId <= 0) throw new RuntimeException('MSWDO Notification: invalid application target.');
    $stage = 'MSWDO Notification: ' . ucfirst($status);
    $mode = pswdo_mswdo_notification_mode();
    $message = $template['subject'] . ' — ' . ($status === 'simulated' ? 'SIMULATED' : 'FAILED');
    $stmt = $connection->prepare('INSERT INTO aics_request_notifications (request_id, notification_stage, channel, delivery_mode, delivery_status, municipality, batch_key, message_text, notification_template, dedupe_key, sent_by_account_id, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
    $channel = 'mswdo_notification';
    $fullTemplate = 'Subject: ' . $template['subject'] . "\n\n" . $template['body'];
    $stmt->bind_param('isssssssssi', $requestId, $stage, $channel, $mode, $status, $municipality, $batchKey, $message, $fullTemplate, $dedupeKey, $accountId);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $exception) {
        $stmt->close();
        // The unique fingerprint makes a concurrent double-click idempotent.
        if ((int) $exception->getCode() === 1062) {
            return ['municipality' => $municipality, 'status' => 'simulated', 'template' => $template, 'duplicate' => true];
        }
        throw $exception;
    }
    $stmt->close();
    return ['municipality' => $municipality, 'status' => $status, 'template' => $template, 'duplicate' => false, 'error' => (string) ($delivery['error'] ?? '')];
}

/** @param array<int, array<string, mixed>> $items */
function pswdo_process_mswdo_release_notifications(mysqli $connection, array $items, int $accountId): array
{
    $results = [];
    $groups = [];
    foreach ($items as $item) {
        try {
            $municipality = pswdo_resolve_mswdo_municipality((string) ($item['municipality'] ?? ''));
            $groups[$municipality][] = $item;
        } catch (Throwable $exception) {
            $results[] = ['municipality' => trim((string) ($item['municipality'] ?? 'Unknown')) ?: 'Unknown', 'status' => 'failed', 'error' => $exception->getMessage()];
        }
    }
    foreach ($groups as $municipality => $group) {
        try { $results[] = pswdo_record_mswdo_notification($connection, $group, $accountId); }
        catch (Throwable $exception) { $results[] = ['municipality' => $municipality, 'status' => 'failed', 'error' => $exception->getMessage()]; }
    }
    return $results;
}

/**
 * Mark request invalid (Not Approved) when requirements are confirmed invalid.
 *
 * @return array<string, string>
 */
function pswdo_mark_request_invalid_action(
    mysqli $connection,
    int $requestId,
    int $accountId,
    bool $logNotification = true,
    string $notificationMessageOverride = '',
    string $rejectionReason = '',
    bool $isExplicitRejection = false
): array
{
    $lookup = $connection->prepare('SELECT reference_no, request_status FROM aics_requests WHERE id = ? LIMIT 1');
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc();
    $lookup->close();

    if (!is_array($row)) {
        throw new RuntimeException('Request not found.');
    }

    $referenceNo = trim((string) ($row['reference_no'] ?? ''));
    $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'));
    if (in_array($statusCode, ['approved', 'released', 'not_approved', 'cancelled'], true)) {
        throw new RuntimeException('This request is already finalized and cannot be marked invalid.');
    }

    $cleanRejectionReason = trim($rejectionReason);
    if ($isExplicitRejection) {
        if ($cleanRejectionReason === '') {
            throw new RuntimeException('Please provide a reason for rejecting this application.');
        }
        if (mb_strlen($cleanRejectionReason) > 400) {
            throw new RuntimeException('The rejection reason must be 400 characters or fewer.');
        }
    }
    $statusRemark = $cleanRejectionReason !== ''
        ? ('PSWDO rejected this application. Reason: ' . $cleanRejectionReason)
        : ($isExplicitRejection
            ? 'PSWDO rejected this application.'
            : 'PSWDO marked this application as invalid due to invalid requirements.');
    pswdo_update_request_status_action(
        $connection,
        $requestId,
        'not_approved',
        $accountId,
        $statusRemark
    );

    $prescreen = pswdo_evaluate_prescreening_readiness($connection, $requestId);
    $requiredTotal = (int) ($prescreen['required_total'] ?? 0);
    $uploadedTotal = (int) ($prescreen['uploaded_total'] ?? 0);
    $missingTotal = max(0, $requiredTotal - $uploadedTotal);
    pswdo_save_prescreening_decision_action(
        $connection,
        $requestId,
        'invalid_requirements',
        $requiredTotal,
        $uploadedTotal,
        $missingTotal,
        $accountId,
        $statusRemark
    );

    if ($logNotification) {
        $notificationMessage = trim($notificationMessageOverride);
        if ($notificationMessage === '') {
            if ($cleanRejectionReason !== '') {
                $notificationMessage = $referenceNo !== ''
                    ? ('Your application ' . $referenceNo . ' was not approved. Reason: ' . $cleanRejectionReason . ' Please coordinate with PSWDO for guidance.')
                    : ('Your application was not approved. Reason: ' . $cleanRejectionReason . ' Please coordinate with PSWDO for guidance.');
            } elseif ($isExplicitRejection) {
                $notificationMessage = $referenceNo !== ''
                    ? ('Your application ' . $referenceNo . ' was not approved. Please coordinate with PSWDO for guidance.')
                    : 'Your application was not approved. Please coordinate with PSWDO for guidance.';
            } else {
                $notificationMessage = $referenceNo !== ''
                    ? ('Your application ' . $referenceNo . ' was marked as not approved because the submitted requirements are invalid. Please coordinate with PSWDO for guidance.')
                    : 'Your application was marked as not approved because the submitted requirements are invalid. Please coordinate with PSWDO for guidance.';
            }
        }

        pswdo_save_notification_action(
            $connection,
            $requestId,
            ($isExplicitRejection || $cleanRejectionReason !== '') ? 'Application Rejected' : 'Invalid Requirements',
            $notificationMessage,
            $accountId
        );
    }

    return [
        'reference_no' => $referenceNo,
        'status_label' => pswdo_status_labels()['not_approved'] ?? 'Not Approved',
    ];
}

/**
 * @return array<string, mixed>
 */
function pswdo_send_sms_to_applicant_action(
    mysqli $connection,
    int $requestId,
    string $decisionStatus,
    string $messageText,
    int $accountId,
    bool $allowQueueTransition = true,
    ?string $recipientOverride = null
): array {
    $lookup = $connection->prepare(
        'SELECT r.reference_no, r.beneficiary_complete_name, r.applicant_first_name, r.applicant_last_name,
                r.applicant_contact_number, ap.contact_number AS profile_contact_number, r.request_status
         FROM aics_requests r
         LEFT JOIN aics_account_requests ar ON ar.request_id = r.id
         LEFT JOIN aics_applicant_profiles ap ON ap.account_id = ar.account_id
         WHERE r.id = ? LIMIT 1'
    );
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc();
    $lookup->close();

    if (!is_array($row)) {
        throw new RuntimeException('Request not found for SMS sending.');
    }

    $referenceNo = trim((string) ($row['reference_no'] ?? ''));
    $recipientName = pswdo_sms_recipient_name($row);
    $rawContact = pswdo_applicant_sms_contact($row);
    if ($rawContact === '') {
        throw new RuntimeException('Applicant contact number is missing.');
    }

    $recipient = pswdo_normalize_sms_recipient($rawContact);
    $requestedRecipient = trim((string) $recipientOverride);
    if ($requestedRecipient !== '') {
        $requestedRecipient = pswdo_normalize_sms_recipient($requestedRecipient);
        if ($requestedRecipient !== $recipient) {
            throw new RuntimeException('The displayed recipient number no longer matches this application. Refresh the page and try again.');
        }
        $recipient = $requestedRecipient;
    }
    $decision = pswdo_detect_sms_decision_status($connection, $requestId, $decisionStatus);

    $finalMessage = trim($messageText);
    if ($finalMessage === '') {
        $finalMessage = pswdo_default_sms_message($decision, $referenceNo, $recipientName);
    }
    $finalMessage = pswdo_sms_with_greeting($finalMessage);

    $providerResult = pswdo_send_sms_via_philsms($recipient, $finalMessage);
    $stage = match ($decision) {
        'screening_passed' => 'Screening Complete SMS',
        'incomplete_requirements' => 'Incomplete Requirements SMS',
        'invalid_requirements' => 'Invalid Requirements SMS',
        'rejection_notice' => 'Rejection Notice SMS',
        'release_schedule' => 'Release Schedule SMS',
        'ready_for_release' => 'Ready for Release SMS',
        'missed_release' => 'Missed Release SMS',
        default => 'For Screening SMS',
    };
    pswdo_save_sms_delivery_audit($connection, $requestId, $stage, $finalMessage, $accountId, 'sent');


    return [
        'reference_no' => $referenceNo,
        'recipient' => $recipient,
        'decision_status' => $decision,
        'provider_result' => $providerResult,
    ];
}

/**
 * @param array<string, mixed> $item
 */
function pswdo_build_release_schedule_sms_message(array $item): string
{
    $applicant = pswdo_sms_recipient_name($item);

    $referenceNo = trim((string) ($item['reference_no'] ?? ''));
    $referenceLabel = $referenceNo !== '' ? $referenceNo : 'hindi available';
    $scheduleDate = trim((string) ($item['scheduled_date_label'] ?? ''));
    if ($scheduleDate === '') {
        $scheduleDate = 'itinakdang petsa';
    }
    $scheduleTime = trim((string) ($item['scheduled_time_label'] ?? ''));
    if ($scheduleTime === '') {
        $scheduleTime = 'itinakdang oras';
    }
    $venue = trim((string) ($item['venue'] ?? ''));
    if ($venue === '') {
        $venue = 'PSWDO Office';
    }

    return '{greeting}, ' . $applicant . ".\n"
        . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
        . "Naka-schedule na po ang pagkuha ng tulong para sa inyong AICS application.\n"
        . 'Reference No.: ' . $referenceLabel . "\n"
        . 'Petsa: ' . $scheduleDate . "\n"
        . 'Oras: ' . $scheduleTime . "\n"
        . 'Lugar: ' . $venue . "\n"
        . "Pakidating po sa takdang oras at dalhin ang inyong valid ID at mga requirements.\n"
        . 'Maraming salamat po.';
}

/**
 * @param array<string, mixed> $item
 */
function pswdo_apply_release_schedule_sms_tokens(string $template, array $item): string
{
    $beneficiary = trim((string) ($item['beneficiary_name'] ?? ''));
    if ($beneficiary === '') {
        $beneficiary = 'Applicant';
    }
    $applicant = pswdo_sms_recipient_name($item);

    $referenceNo = trim((string) ($item['reference_no'] ?? ''));
    if ($referenceNo === '') {
        $referenceNo = 'hindi available';
    }

    $scheduleDate = trim((string) ($item['scheduled_date_label'] ?? ''));
    if ($scheduleDate === '') {
        $scheduleDate = 'itinakdang petsa';
    }

    $scheduleTime = trim((string) ($item['scheduled_time_label'] ?? ''));
    if ($scheduleTime === '') {
        $scheduleTime = 'itinakdang oras';
    }

    $venue = trim((string) ($item['venue'] ?? ''));
    if ($venue === '') {
        $venue = 'PSWDO Office';
    }

    return str_replace(
        // {amount_line} is blanked for messages typed before the amount left the SMS.
        ['{applicant}', '{beneficiary}', '{reference}', '{schedule_date}', '{schedule_time}', '{venue}', '{amount_line}'],
        [$applicant, $beneficiary, $referenceNo, $scheduleDate, $scheduleTime, $venue, ''],
        $template
    );
}

/**
 * @return array<string, int|string>
 */
function pswdo_send_mass_release_schedule_sms_action(
    mysqli $connection,
    int $accountId,
    string $sortBy,
    string $sortGroup,
    string $messageTemplate = '',
    int $limit = 80,
    string $batchDateFrom = '',
    string $batchDateTo = '',
    string $batchKey = '',
    int $batchLimit = 10
): array {
    $releaseSort = pswdo_normalize_release_sort($sortBy);
    $releaseSortGroup = pswdo_normalize_release_sort_group($releaseSort, $sortGroup);
    $batchLimit = max(1, min(200, $batchLimit));
    $queueItems = pswdo_fetch_release_schedule_queue(
        $connection,
        'all',
        $limit,
        $releaseSort,
        $releaseSortGroup,
        $batchDateFrom,
        $batchDateTo,
        $batchKey
    );
    // Mass Notice covers up to TWO batches per send (Batch 1 morning + Batch 2 afternoon).
    $queueItems = array_slice($queueItems, 0, 2 * $batchLimit);

    $sentCount = 0;
    $failedCount = 0;
    $skippedNoContact = 0;
    $skippedNoSchedule = 0;
    $skippedInvalidContact = 0;
    // Counts only un-batched recipients so re-sends don't reshuffle existing batches.
    $freshPosition = 0;
    $afternoonTimeSql = '13:00:00';

    $mswdoItems = [];
    foreach ($queueItems as $item) {
        $requestId = (int) ($item['request_id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }

        $scheduledDate = trim((string) ($item['scheduled_date'] ?? ''));
        $scheduledTime = trim((string) ($item['scheduled_time'] ?? ''));
        if ($scheduledDate === '' || $scheduledDate === '0000-00-00' || $scheduledTime === '' || $scheduledTime === '00:00:00') {
            $skippedNoSchedule++;
            continue;
        }

        $rawContact = pswdo_applicant_sms_contact($item);
        if ($rawContact === '') {
            $skippedNoContact++;
            // Municipality coordination remains independent from SMS eligibility.
            $mswdoItems[] = $item;
            continue;
        }

        try {
            $recipient = pswdo_normalize_sms_recipient($rawContact);
        } catch (Throwable) {
            $skippedInvalidContact++;
            $mswdoItems[] = $item;
            continue;
        }

        // Assign Batch 1 (morning, chosen time) or Batch 2 (afternoon 1:00 PM) to
        // un-batched applicants based on their position within this send. Already-
        // batched applicants keep their previous time + batch so re-sends don't shuffle.
        $existingBatch = trim((string) ($item['release_batch'] ?? ''));
        if ($existingBatch === '') {
            $freshPosition++;
            if ($freshPosition <= $batchLimit) {
                $newBatch = 'Batch 1';
                $newTime = $scheduledTime;
            } else {
                $newBatch = 'Batch 2';
                $newTime = $afternoonTimeSql;
            }
            $updateStmt = $connection->prepare(
                'UPDATE aics_request_release_schedule SET scheduled_time = ?, release_batch = ?, updated_by_account_id = ?, updated_at = CURRENT_TIMESTAMP WHERE request_id = ?'
            );
            if ($updateStmt) {
                $updateStmt->bind_param('ssii', $newTime, $newBatch, $accountId, $requestId);
                $updateStmt->execute();
                $updateStmt->close();
            }
            $item['scheduled_time'] = $newTime;
            $item['scheduled_time_label'] = pswdo_release_time_label($newTime);
            $item['release_batch'] = $newBatch;
        }

        $mswdoItems[] = $item;

        $message = pswdo_sms_with_greeting(trim($messageTemplate) !== ''
            ? pswdo_apply_release_schedule_sms_tokens($messageTemplate, $item)
            : pswdo_build_release_schedule_sms_message($item));
        try {
            pswdo_send_sms_via_philsms($recipient, $message);
            pswdo_save_sms_delivery_audit($connection, $requestId, 'Release Schedule SMS', $message, $accountId, 'sent');
            $sentCount++;
        } catch (Throwable) {
            $failedCount++;
            try {
                pswdo_save_sms_delivery_audit($connection, $requestId, 'Release Schedule SMS', 'Applicant SMS delivery failed.', $accountId, 'failed');
            } catch (Throwable) {
                // The channel failure remains reflected in the operation result.
            }
        }
    }

    $mswdoResults = pswdo_process_mswdo_release_notifications($connection, $mswdoItems, $accountId);

    return [
        'sent_count' => $sentCount,
        'failed_count' => $failedCount,
        'skipped_no_contact' => $skippedNoContact,
        'skipped_no_schedule' => $skippedNoSchedule,
        'skipped_invalid_contact' => $skippedInvalidContact,
        'sort_by' => $releaseSort,
        'sort_group' => $releaseSortGroup,
        'batch_limit' => $batchLimit,
        'mswdo_results' => $mswdoResults,
    ];
}

/**
 * @param array<string, mixed> $item
 */
function pswdo_apply_prescreening_sms_tokens(string $template, array $item): string
{
    $beneficiary = trim((string) ($item['beneficiary_name'] ?? ''));
    $referenceNo = trim((string) ($item['reference_no'] ?? ''));

    return str_replace(
        ['{applicant}', '{beneficiary}', '{reference}'],
        [pswdo_sms_recipient_name($item), $beneficiary !== '' ? $beneficiary : 'Applicant', $referenceNo !== '' ? $referenceNo : 'hindi available'],
        $template
    );
}

/**
 * The name an applicant SMS is addressed to. It goes to the applicant's
 * phone, so it is the applicant's name; the beneficiary's only when no
 * applicant name was recorded.
 *
 * @param array<string, mixed> $row a request row or queue item
 */
function pswdo_sms_recipient_name(array $row): string
{
    $name = trim((string) ($row['applicant_name'] ?? ''));
    if ($name === '' || $name === 'Applicant') {
        $name = trim(trim((string) ($row['applicant_first_name'] ?? '')) . ' ' . trim((string) ($row['applicant_last_name'] ?? '')));
    }
    if ($name === '') {
        $name = trim((string) ($row['beneficiary_name'] ?? $row['beneficiary_complete_name'] ?? ''));
    }
    return $name !== '' ? $name : 'Applicant';
}

/**
 * @return array<string, int|string>
 */
function pswdo_send_mass_prescreening_sms_action(
    mysqli $connection,
    int $accountId,
    string $queueTab,
    string $dateFrom,
    string $dateTo,
    string $sortBy,
    string $sortGroup,
    string $messageTemplate = '',
    int $limit = 120
): array {
    $tab = pswdo_normalize_prescreening_tab($queueTab);
    if ($tab !== 'for_screening') {
        throw new RuntimeException('Mass notice is available only for the For Screening lane.');
    }

    $prescreenSort = pswdo_normalize_prescreening_sort($sortBy);
    $prescreenSortGroup = pswdo_normalize_prescreening_sort_group($prescreenSort, $sortGroup);
    $queueItems = pswdo_fetch_prescreening_queue($connection, $tab, $dateFrom, $dateTo, $prescreenSort, $prescreenSortGroup);
    $slice = array_slice($queueItems, 0, max(1, min(200, $limit)));

    $sentCount = 0;
    $skippedNoContact = 0;
    $skippedInvalidContact = 0;
    foreach ($slice as $item) {
        $requestId = (int) ($item['request_id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }

        $contactNumber = pswdo_applicant_sms_contact($item);
        if ($contactNumber === '') {
            $skippedNoContact++;
            continue;
        }

        try {
            pswdo_normalize_sms_recipient($contactNumber);
        } catch (Throwable) {
            $skippedInvalidContact++;
            continue;
        }

        $referenceNo = trim((string) ($item['reference_no'] ?? ''));
        $message = trim($messageTemplate);
        if ($message === '') {
            $message = pswdo_default_sms_message('for_screening', $referenceNo, pswdo_sms_recipient_name($item));
        }

        $message = pswdo_apply_prescreening_sms_tokens($message, $item);

        pswdo_send_sms_to_applicant_action($connection, $requestId, 'for_screening', $message, $accountId);
        $sentCount++;
    }

    return [
        'sent_count' => $sentCount,
        'skipped_no_contact' => $skippedNoContact,
        'skipped_invalid_contact' => $skippedInvalidContact,
        'queue_tab' => $tab,
        'queue_date' => $scope,
        'sort_by' => $prescreenSort,
        'sort_group' => $prescreenSortGroup,
    ];
}

function pswdo_detect_sms_decision_status(mysqli $connection, int $requestId, string $fallbackDecision): string
{
    $fallback = pswdo_normalize_sms_decision_status($fallbackDecision);
    // Sent the moment PSWDO finishes pre-screening or rejects, so the stored
    // queue state does not tell which notice this is.
    if ($requestId <= 0 || in_array($fallback, ['screening_passed', 'rejection_notice'], true)) {
        return $fallback;
    }

    $sql = <<<SQL
        SELECT
            p.decision_status,
            r.request_status,
            rs.release_status,
            rs.scheduled_date
        FROM aics_requests r
        LEFT JOIN aics_request_prescreening p ON p.request_id = r.id
        LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
        WHERE r.id = ?
        LIMIT 1
    SQL;
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return $fallback;
    }

    $queueDecision = trim((string) ($row['decision_status'] ?? ''));
    $releaseStatus = strtolower(trim((string) ($row['release_status'] ?? '')));
    $scheduledDate = trim((string) ($row['scheduled_date'] ?? ''));

    if (in_array($releaseStatus, ['ready_for_release', 'missed'], true)) {
        return $releaseStatus === 'missed' ? 'missed_release' : 'ready_for_release';
    }
    if ($releaseStatus !== '' && in_array($releaseStatus, ['scheduled', 'rescheduled'], true)) {
        return 'release_schedule';
    }
    if ($scheduledDate !== '' && $scheduledDate !== '0000-00-00') {
        return 'release_schedule';
    }
    if ($queueDecision !== '' && in_array($queueDecision, ['for_screening', 'incomplete_requirements', 'invalid_requirements'], true)) {
        return pswdo_normalize_prescreening_tab($queueDecision);
    }

    $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending_review'));
    return match ($statusCode) {
        'incomplete_requirements' => 'incomplete_requirements',
        'not_approved' => 'invalid_requirements',
        default => 'for_screening',
    };
}

/**
 * @param array<string, mixed> $requirements documents to bring to the office, from pswdo_uploaded_requirements() (approval notice only)
 */
function pswdo_default_sms_message(string $decisionStatus, string $referenceNo, string $applicantName, array $requirements = []): string
{
    $applicantLabel = trim($applicantName) !== '' ? trim($applicantName) : 'Applicant';
    // {greeting} becomes "Magandang umaga po" etc. when the SMS is sent.
    $opening = '{greeting}, ' . $applicantLabel . ".\n"
        . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n";
    $reference = 'Reference No.: ' . ($referenceNo !== '' ? $referenceNo : 'hindi available') . "\n";
    $closing = 'Maraming salamat po.';

    $body = match ($decisionStatus) {
        // Same text as the Screening Queue Mass Notice default.
        'screening_passed' => "Tapos na po at pumasa na sa further screening ang inyong AICS application.\n" . $reference
            . "Hintayin na lamang po ang aming text para sa petsa, oras at lugar ng pagkuha ng inyong tulong.\n",
        'incomplete_requirements' => "Kulang pa po ang requirements ng inyong AICS application.\n" . $reference
            . "Pakisumite po ang mga kulang na dokumento upang maipagpatuloy namin ang inyong screening.\n",
        'invalid_requirements' => "Ikinalulungkot po naming ipaalam na hindi naaprubahan ang inyong AICS application dahil sa hindi wastong requirements.\n" . $reference
            . "Makipag-ugnayan po sa PSWDO Office para sa gabay.\n",
        'release_schedule' => "Naka-schedule na po ang pagkuha ng tulong para sa inyong AICS application.\n" . $reference
            . "Pumunta po sa PSWDO Office sa itinakdang petsa at oras, at dalhin ang inyong valid ID at mga requirements.\n",
        'ready_for_release' => "Handa na po ang tulong para sa inyong AICS application.\n" . $reference
            . "Sundin po ang schedule at lugar na ibinigay ng PSWDO.\n",
        'missed_release' => "Ayon po sa aming tala, hindi ninyo nadaluhan ang schedule ng pagkuha ng tulong para sa inyong AICS application.\n" . $reference
            . "Hintayin po ang abiso ng PSWDO para sa bagong schedule.\n",
        default => "Aprubado na po ang inyong AICS application.\n" . $reference
            . "Maaari na po kayong pumunta sa PSWDO Office sa anumang araw ng opisina:\n"
            . "Lunes hanggang Huwebes, 8:00 AM - 11:00 AM at 1:00 PM - 4:00 PM.\n"
            . pswdo_requirements_sms_text($requirements),
    };

    return $opening . $body . $closing;
}

/**
 * The SMS an applicant gets when PSWDO rejects their application, with the
 * reason PSWDO typed. Same text as the "Rejection Notice (Default)" template.
 */
function pswdo_rejection_sms_message(string $referenceNo, string $applicantName, string $reason): string
{
    $reason = trim($reason);
    if ($reason !== '' && !preg_match('/[.!?]$/u', $reason)) $reason .= '.';
    return '{greeting}, ' . (trim($applicantName) !== '' ? trim($applicantName) : 'Applicant') . ".\n"
        . "Ito po ay mula sa PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\n"
        . "Ikinalulungkot po naming ipaalam na hindi naaprubahan ang inyong AICS application.\n"
        . 'Reference No.: ' . ($referenceNo !== '' ? $referenceNo : 'hindi available') . "\n"
        . 'Dahilan: ' . ($reason !== '' ? $reason : 'Makipag-ugnayan po sa PSWDO para sa detalye.') . "\n"
        . "Para sa paglilinaw o karagdagang tulong, maaari po kayong pumunta sa PSWDO Office, Lunes hanggang Huwebes, 8:00 AM - 11:00 AM at 1:00 PM - 4:00 PM.\n"
        . 'Maraming salamat po.';
}

/**
 * "Magandang umaga po", "tanghali", "hapon" or "gabi" for the hour an SMS is
 * sent, in Philippine time.
 */
function pswdo_sms_greeting(?DateTimeImmutable $now = null): string
{
    $hour = (int) ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('G');
    return match (true) {
        $hour >= 5 && $hour < 12 => 'Magandang umaga po',
        $hour === 12 => 'Magandang tanghali po',
        $hour >= 13 && $hour < 18 => 'Magandang hapon po',
        default => 'Magandang gabi po',
    };
}

/** Puts the greeting for the send time into an SMS's {greeting} placeholder. */
function pswdo_sms_with_greeting(string $message, ?DateTimeImmutable $now = null): string
{
    return str_replace('{greeting}', pswdo_sms_greeting($now), $message);
}

/**
 * Names of the uploaded documents, each once, split by whose document it is:
 * the valid ID is the applicant's own (its back belongs to it), every other
 * requirement is the beneficiary's (as on the Apply form).
 *
 * @param array<int, string> $documentCodes
 * @return array{applicant: list<string>, beneficiary: list<string>}
 */
function pswdo_requirement_owners_from_codes(array $documentCodes): array
{
    $owners = ['applicant' => [], 'beneficiary' => []];
    foreach ($documentCodes as $code) {
        $code = aics_normalize_document_type((string) $code);
        if ($code === '' || $code === 'valid_id_back') continue;
        $owners[$code === 'valid_id' ? 'applicant' : 'beneficiary'][$code] = pswdo_document_label($code);
    }
    return ['applicant' => array_values($owners['applicant']), 'beneficiary' => array_values($owners['beneficiary'])];
}

/**
 * The requirements to bring for the approval SMS.
 *
 * @return array{applicant: list<string>, beneficiary: list<string>, beneficiary_name: string, same_person: bool}
 */
function pswdo_uploaded_requirements(mysqli $connection, int $requestId): array
{
    $statement = $connection->prepare('SELECT document_type FROM aics_request_documents WHERE request_id = ? ORDER BY uploaded_at ASC, id ASC');
    $statement->bind_param('i', $requestId);
    $statement->execute();
    $codes = array_column($statement->get_result()?->fetch_all(MYSQLI_ASSOC) ?? [], 'document_type');
    $statement->close();

    $lookup = $connection->prepare('SELECT beneficiary_complete_name, applicant_relationship FROM aics_requests WHERE id = ? LIMIT 1');
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc() ?? [];
    $lookup->close();

    $beneficiaryName = trim((string) ($row['beneficiary_complete_name'] ?? ''));
    $applicantName = pswdo_request_identity($connection, $requestId)['name'];
    return pswdo_requirement_owners_from_codes(array_map('strval', $codes)) + [
        'beneficiary_name' => $beneficiaryName,
        'same_person' => strcasecmp(trim((string) ($row['applicant_relationship'] ?? '')), 'Self') === 0
            || ($beneficiaryName !== '' && strcasecmp($beneficiaryName, $applicantName) === 0),
    ];
}

/**
 * "Pakidala po..." lines of the approval SMS. Each document is listed under
 * the person it belongs to, so the applicant knows whose papers to bring.
 *
 * @param array{applicant?: list<string>, beneficiary?: list<string>, beneficiary_name?: string, same_person?: bool} $requirements
 */
function pswdo_requirements_sms_text(array $requirements): string
{
    $applicantDocs = (array) ($requirements['applicant'] ?? []);
    $beneficiaryDocs = (array) ($requirements['beneficiary'] ?? []);
    if ($applicantDocs === [] && $beneficiaryDocs === []) {
        return "Pakidala po ang inyong valid ID at ang orihinal na kopya ng inyong mga requirements.\n";
    }
    $list = static fn (array $labels): string => '- ' . implode("\n- ", $labels) . "\n";
    if (!empty($requirements['same_person'])) {
        return "Pakidala po ang orihinal na kopya ng inyong mga requirements:\n" . $list([...$applicantDocs, ...$beneficiaryDocs]);
    }
    $beneficiaryName = trim((string) ($requirements['beneficiary_name'] ?? ''));
    return "Pakidala po ang orihinal na kopya ng mga requirements:\n"
        . ($applicantDocs !== [] ? "Para sa inyo (applicant):\n" . $list($applicantDocs) : '')
        . ($beneficiaryDocs !== [] ? 'Para sa beneficiary' . ($beneficiaryName !== '' ? ' (' . $beneficiaryName . ')' : '') . ":\n" . $list($beneficiaryDocs) : '');
}

function pswdo_normalize_sms_decision_status(string $status): string
{
    $normalized = strtolower(trim($status));
    return match ($normalized) {
        'incomplete_requirements', 'incomplete requirements' => 'incomplete_requirements',
        'invalid_requirements', 'invalid requirements', 'not_approved', 'not approved', 'rejected', 'denied' => 'invalid_requirements',
        'release_schedule', 'release schedule', 'scheduled', 'rescheduled' => 'release_schedule',
        'ready_for_release', 'ready for release' => 'ready_for_release',
        'missed_release', 'missed release', 'missed' => 'missed_release',
        'screening_passed' => 'screening_passed',
        'rejection_notice' => 'rejection_notice',
        default => 'for_screening',
    };
}

function pswdo_normalize_sms_recipient(string $rawPhone): string
{
    $digits = preg_replace('/\D+/', '', $rawPhone);
    if (!is_string($digits) || $digits === '') {
        throw new RuntimeException('Invalid contact number format.');
    }

    if (str_starts_with($digits, '09') && strlen($digits) === 11) {
        return '63' . substr($digits, 1);
    }
    if (str_starts_with($digits, '639') && strlen($digits) === 12) {
        return $digits;
    }
    if (str_starts_with($digits, '9') && strlen($digits) === 10) {
        return '63' . $digits;
    }
    if (str_starts_with($digits, '63') && strlen($digits) === 12) {
        return $digits;
    }

    throw new RuntimeException('Invalid applicant mobile number. Use PH mobile format like 09XXXXXXXXX.');
}

/**
 * @return array<string, mixed>
 */
function pswdo_send_sms_via_philsms(string $recipient, string $message): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is required to send SMS.');
    }

    $apiToken = aics_philsms_api_token();

    $senderCandidates = [];
    $configuredSender = trim((string) AICS_PHILSMS_SENDER_ID);
    if ($configuredSender !== '') {
        $senderCandidates[] = $configuredSender;
    }
    // Common default sender id for accounts without approved custom sender ID.
    $senderCandidates[] = 'PhilSMS';
    // Keep custom app sender as fallback when approved in provider dashboard.
    $senderCandidates[] = 'AICSPSWDO';
    $senderCandidates = array_values(array_unique(array_filter($senderCandidates, static fn ($value): bool => is_string($value) && trim($value) !== '')));

    $baseUrl = getenv('PHILSMS_BASE_URL');
    $baseUrl = is_string($baseUrl) ? trim($baseUrl) : '';
    $endpoints = [];

    if ($baseUrl !== '') {
        $endpoints[] = rtrim($baseUrl, '/') . '/api/v3/sms/send';
    }
    $endpoints[] = 'https://dashboard.philsms.com/api/v3/sms/send';
    $endpoints[] = 'https://app.philsms.com/api/v3/sms/send';

    $lastError = 'Unable to send SMS.';
    foreach (array_values(array_unique($endpoints)) as $endpoint) {
        foreach ($senderCandidates as $senderId) {
            $payload = [
                'recipient' => $recipient,
                'sender_id' => $senderId,
                'type' => 'plain',
                'message' => $message,
            ];

            $result = pswdo_send_sms_via_philsms_endpoint($endpoint, $apiToken, $payload);
            if ((bool) ($result['ok'] ?? false) === true) {
                $data = (array) ($result['data'] ?? ['status' => 'success']);
                $data['sender_id_used'] = $senderId;
                $data['provider_endpoint_used'] = $endpoint;
                return $data;
            }

            $errorText = trim((string) ($result['error'] ?? ''));
            if ($errorText !== '') {
                $lastError = $errorText;
            }

            $lowerError = strtolower($lastError);
            $isAuthError = str_contains($lowerError, 'unauthenticated');
            $isUnauthorizedSenderError = str_contains($lowerError, 'sender id') && str_contains($lowerError, 'not authorized');

            // Try next sender when sender id is unauthorized.
            if ($isUnauthorizedSenderError) {
                continue;
            }
            // Try next endpoint only when auth failed for current endpoint.
            if ($isAuthError) {
                break;
            }
            // For non-auth and non-sender errors, stop retrying to avoid duplicates.
            throw new RuntimeException($lastError);
        }
    }

    throw new RuntimeException($lastError);
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok:bool,data?:array<string,mixed>,error?:string}
 */
function pswdo_send_sms_via_philsms_endpoint(string $endpoint, string $apiToken, array $payload): array
{
    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Unable to initialize SMS transport.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $curlError = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'error' => 'Unable to send SMS (network error): ' . $curlError];
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);
    $apiMessage = '';
    if (is_array($decoded)) {
        $apiMessage = trim((string) ($decoded['message'] ?? ''));
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'ok' => false,
            'error' => $apiMessage !== '' ? ('SMS sending failed: ' . $apiMessage) : ('SMS sending failed with HTTP ' . $httpCode . '.'),
        ];
    }

    if (is_array($decoded) && strtolower((string) ($decoded['status'] ?? 'success')) === 'error') {
        return [
            'ok' => false,
            'error' => $apiMessage !== '' ? ('SMS sending failed: ' . $apiMessage) : 'SMS provider returned an error.',
        ];
    }

    return [
        'ok' => true,
        'data' => is_array($decoded) ? $decoded : ['status' => 'success', 'raw' => (string) $response],
    ];
}

function pswdo_normalize_prescreening_tab(string $tab): string
{
    $normalized = strtolower(trim($tab));
    return in_array($normalized, ['for_screening', 'incomplete_requirements', 'invalid_requirements'], true)
        ? $normalized
        : 'for_screening';
}

function pswdo_normalize_prescreening_date_scope(string $scope): string
{
    $normalized = strtolower(trim($scope));
    return in_array($normalized, ['all', 'this_week', 'this_month', 'this_year'], true)
        ? $normalized
        : 'all';
}

function pswdo_sync_invalid_requirements_queue(mysqli $connection): void
{
    $sql = <<<SQL
        INSERT INTO aics_request_prescreening (
            request_id,
            decision_status,
            required_total,
            uploaded_total,
            missing_total,
            remarks,
            confirmed_by_account_id,
            confirmed_at
        )
        SELECT
            r.id,
            'invalid_requirements',
            0,
            0,
            0,
            'Auto-synced from Not Approved status.',
            NULL,
            COALESCE(r.updated_at, CURRENT_TIMESTAMP)
        FROM aics_requests r
        WHERE LOWER(TRIM(r.request_status)) IN ('not_approved', 'not approved', 'rejected', 'denied')
        ON DUPLICATE KEY UPDATE
            decision_status = VALUES(decision_status),
            remarks = CASE
                WHEN aics_request_prescreening.decision_status <> 'invalid_requirements' THEN VALUES(remarks)
                WHEN COALESCE(aics_request_prescreening.remarks, '') = '' THEN VALUES(remarks)
                ELSE aics_request_prescreening.remarks
            END,
            confirmed_by_account_id = aics_request_prescreening.confirmed_by_account_id,
            confirmed_at = CASE
                WHEN aics_request_prescreening.decision_status <> 'invalid_requirements' THEN CURRENT_TIMESTAMP
                ELSE aics_request_prescreening.confirmed_at
            END
    SQL;

    $connection->query($sql);
}

/**
 * @return array<string, int>
 */
function pswdo_fetch_prescreening_counts(mysqli $connection): array
{
    $visibleSql = aics_ai_gate_visible_sql('r');
    pswdo_sync_invalid_requirements_queue($connection);

    $counts = [
        'for_screening' => 0,
        'incomplete_requirements' => 0,
        'invalid_requirements' => 0,
    ];

    $sql = <<<SQL
        SELECT
            SUM(CASE
                WHEN p.decision_status = 'for_screening'
                 AND LOWER(TRIM(r.request_status)) = 'approved'
                 AND rs.request_id IS NULL
                THEN 1 ELSE 0 END) AS for_screening_total,
            SUM(CASE WHEN p.decision_status = 'incomplete_requirements' THEN 1 ELSE 0 END) AS incomplete_total,
            SUM(CASE WHEN p.decision_status = 'invalid_requirements' THEN 1 ELSE 0 END) AS invalid_total
        FROM aics_request_prescreening p
        INNER JOIN aics_requests r ON r.id = p.request_id
        LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
        WHERE $visibleSql
    SQL;
    $row = $connection->query($sql)?->fetch_assoc();
    if (is_array($row)) {
        $counts['for_screening'] = (int) ($row['for_screening_total'] ?? 0);
        $counts['incomplete_requirements'] = (int) ($row['incomplete_total'] ?? 0);
        $counts['invalid_requirements'] = (int) ($row['invalid_total'] ?? 0);
    }

    return $counts;
}

/**
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_prescreening_queue(
    mysqli $connection,
    string $tab = 'for_screening',
    string $dateFrom = '',
    string $dateTo = '',
    string $sortBy = 'default',
    string $sortGroup = 'all'
): array
{
    pswdo_sync_invalid_requirements_queue($connection);
    pswdo_refresh_smart_priorities($connection);
    pswdo_recalculate_priority_ranks($connection);

    $decisionStatus = pswdo_normalize_prescreening_tab($tab);
    $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateFrom)) === 1 ? trim($dateFrom) : '';
    $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateTo)) === 1 ? trim($dateTo) : '';
    if ($dateFrom !== '' && $dateTo !== '' && strcmp($dateFrom, $dateTo) > 0) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $prescreenSort = pswdo_normalize_prescreening_sort($sortBy);
    $prescreenSortGroup = pswdo_normalize_prescreening_sort_group($prescreenSort, $sortGroup);
    $smartPriorityOrderSql = pswdo_smart_priority_order_case_sql('pr');
    $rows = [];
    $whereParts = ['p.decision_status = ?'];
    if ($decisionStatus === 'for_screening') {
        // This queue is the approval-to-release decision stage only. Once a
        // request advances to the existing "for release" workflow it belongs
        // exclusively to Release Scheduling.
        $whereParts[] = "LOWER(TRIM(r.request_status)) = 'approved'";
        $whereParts[] = 'rs.request_id IS NULL';
    }
    // $dateFrom/$dateTo are strictly validated as YYYY-MM-DD above (digits + hyphens
    // only), so inlining them is injection-safe and matches the existing CURDATE() style.
    if ($dateFrom !== '') {
        $whereParts[] = "DATE(r.date_applied) >= '" . $dateFrom . "'";
    }
    if ($dateTo !== '') {
        $whereParts[] = "DATE(r.date_applied) <= '" . $dateTo . "'";
    }
    if ($prescreenSort === 'client_classification' && $prescreenSortGroup !== 'all') {
        $whereParts[] = "LOWER(COALESCE(r.client_categories_json, '')) LIKE ?";
    } elseif ($prescreenSort === 'type_of_assistance' && $prescreenSortGroup !== 'all') {
        $whereParts[] = "COALESCE(at.assistance_code, '') = ?";
    } elseif ($prescreenSort === 'high_priority' && $prescreenSortGroup === 'high_priority') {
        $whereParts[] = "{$smartPriorityOrderSql} IN (0, 1, 2, 4)";
    }
    $whereParts[] = aics_ai_gate_visible_sql('r');
    $whereSql = 'WHERE ' . implode("\n          AND ", $whereParts);

    $defaultOrderSql = <<<SQL
        {$smartPriorityOrderSql} ASC,
        COALESCE(NULLIF(r.date_applied, '0000-00-00'), DATE(r.created_at)) ASC,
        r.id ASC
    SQL;
    // Urgent, then high priority, always first; the chosen sort applies within each level.
    $clientClassificationOrderSql = <<<SQL
        {$smartPriorityOrderSql} ASC,
        CASE
            WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%pwd%' THEN 0
            WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%senior_citizen%' THEN 1
            ELSE 2
        END ASC,
        $defaultOrderSql
    SQL;
    $assistanceOrderSql = <<<SQL
        {$smartPriorityOrderSql} ASC,
        CASE
            WHEN COALESCE(at.assistance_code, '') = 'medical' THEN 0
            WHEN COALESCE(at.assistance_code, '') = 'burial' THEN 1
            WHEN COALESCE(at.assistance_code, '') = 'victim_of_disaster' THEN 2
            WHEN COALESCE(at.assistance_code, '') = 'special_cases' THEN 3
            ELSE 5
        END ASC,
        $defaultOrderSql
    SQL;
    $highPriorityOrderSql = $defaultOrderSql;
    $orderBySql = match ($prescreenSort) {
        'client_classification' => $clientClassificationOrderSql,
        'type_of_assistance' => $assistanceOrderSql,
        'high_priority' => $highPriorityOrderSql,
        default => $defaultOrderSql,
    };

    $sql = <<<SQL
        SELECT
            r.id,
            r.reference_no,
            r.date_applied,
            r.request_status,
            r.beneficiary_complete_name,
            r.applicant_first_name,
            r.applicant_middle_name,
            r.applicant_last_name,
            r.contact_number,
            r.applicant_contact_number,
            ap.contact_number AS profile_contact_number,
            r.barangay,
            r.municipality,
            r.client_categories_json,
            at.assistance_code,
            at.assistance_name,
            p.decision_status,
            p.required_total,
            p.uploaded_total,
            p.missing_total,
            p.remarks,
            p.confirmed_at,
            pr.priority_score,
            pr.priority_rank,
            pr.priority_level,
            pr.urgency_indicator,
            pr.priority_basis,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ap.first_name, ap.middle_name, ap.last_name)), ''), NULLIF(TRIM(CONCAT_WS(' ', r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name)), '')) AS applicant_profile_name,
            CONCAT_WS(' ', sp.first_name, sp.last_name) AS confirmed_by_name,
            (
                SELECT MAX(n.sent_at)
                FROM aics_request_notifications n
                WHERE n.request_id = r.id
                  AND n.notification_stage = 'For Screening SMS'
            ) AS screening_sms_notified_at
        FROM aics_request_prescreening p
        INNER JOIN aics_requests r ON r.id = p.request_id
        LEFT JOIN (
            SELECT request_id, MIN(assistance_type_id) AS assistance_type_id
            FROM aics_request_assistance
            GROUP BY request_id
        ) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        LEFT JOIN aics_account_requests ar ON ar.request_id = r.id
        LEFT JOIN aics_accounts a ON a.id = ar.account_id
        LEFT JOIN aics_applicant_profiles ap ON ap.account_id = a.id
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = p.confirmed_by_account_id
        LEFT JOIN aics_request_prioritization pr ON pr.request_id = r.id
        LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
        $whereSql
        ORDER BY $orderBySql
    SQL;

    $stmt = $connection->prepare($sql);
    if ($prescreenSort === 'client_classification' && $prescreenSortGroup !== 'all') {
        $stmt->bind_param('ss', $decisionStatus, $prescreenSortGroup);
    } elseif ($prescreenSort === 'type_of_assistance' && $prescreenSortGroup !== 'all') {
        $stmt->bind_param('ss', $decisionStatus, $prescreenSortGroup);
    } else {
        $stmt->bind_param('s', $decisionStatus);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $applicantSubmittedName = trim(implode(' ', array_filter([
            trim((string) ($row['applicant_first_name'] ?? '')),
            trim((string) ($row['applicant_middle_name'] ?? '')),
            trim((string) ($row['applicant_last_name'] ?? '')),
        ])));
        $applicantName = trim((string) ($row['applicant_profile_name'] ?? ''));
        if ($applicantName === '') {
            $applicantName = $applicantSubmittedName;
        }

        $decision = strtolower(trim((string) ($row['decision_status'] ?? 'for_screening')));
        $addressParts = array_filter([
            trim((string) ($row['barangay'] ?? '')),
            trim((string) ($row['municipality'] ?? '')),
        ]);
        $rows[] = [
            'request_id' => (int) ($row['id'] ?? 0),
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'municipality' => trim((string) ($row['municipality'] ?? '')),
            'date_applied_label' => pswdo_date_label((string) ($row['date_applied'] ?? '')),
            'status_code' => pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending')),
            'status_label' => pswdo_status_labels()[pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'))] ?? 'Pending Review',
            'status_tone' => pswdo_status_tone(pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'))),
            'beneficiary_name' => trim((string) ($row['beneficiary_complete_name'] ?? '')),
            'applicant_name' => $applicantName,
            'client_categories_json' => (string) ($row['client_categories_json'] ?? '[]'),
            'assistance_code' => (string) ($row['assistance_code'] ?? ''),
            'contact_number' => trim((string) ($row['contact_number'] ?? '')),
            'applicant_contact_number' => pswdo_applicant_sms_contact($row),
            'address_label' => count($addressParts) > 0 ? implode(', ', $addressParts) : 'Not available',
            'assistance_label' => trim((string) ($row['assistance_name'] ?? '')) !== '' ? (string) $row['assistance_name'] : 'Assistance Request',
            'decision_status' => $decision,
            'decision_label' => match ($decision) {
                'incomplete_requirements' => 'Incomplete Requirements',
                'invalid_requirements' => 'Invalid Requirements',
                default => 'For Screening',
            },
            'required_total' => (int) ($row['required_total'] ?? 0),
            'uploaded_total' => (int) ($row['uploaded_total'] ?? 0),
            'missing_total' => (int) ($row['missing_total'] ?? 0),
            'priority_score' => (int) ($row['priority_score'] ?? 0),
            'priority_rank' => $row['priority_rank'] !== null ? (int) $row['priority_rank'] : null,
            'priority_level' => trim((string) ($row['priority_level'] ?? '')) !== '' ? (string) $row['priority_level'] : 'Normal Priority',
            'urgency_indicator' => trim((string) ($row['urgency_indicator'] ?? '')) !== '' ? (string) $row['urgency_indicator'] : 'REQUIRES_STAFF_REVIEW',
            'priority_basis' => trim((string) ($row['priority_basis'] ?? '')),
            'remarks' => trim((string) ($row['remarks'] ?? '')),
            'confirmed_at_label' => pswdo_datetime_label((string) ($row['confirmed_at'] ?? '')),
            'confirmed_by' => trim((string) ($row['confirmed_by_name'] ?? '')) !== '' ? trim((string) ($row['confirmed_by_name'] ?? '')) : 'PSWDO Personnel',
            'screening_sms_notified_at' => trim((string) ($row['screening_sms_notified_at'] ?? '')),
            'screening_sms_notified_label' => trim((string) ($row['screening_sms_notified_at'] ?? '')) !== ''
                ? pswdo_date_label((string) $row['screening_sms_notified_at'])
                : '',
        ];
    }
    $stmt->close();

    return $rows;
}

/**
 * @return array<string, mixed>
 */
function pswdo_fetch_workflow_dashboard_data(mysqli $connection, int $limitPerLane = 5): array
{
    $limit = max(1, min(12, $limitPerLane));
    $prescreenCounts = pswdo_fetch_prescreening_counts($connection);

    return [
        'counts' => [
            'new_submissions' => pswdo_count_requests_by_status_values($connection, [
                'pending',
                'for_review',
                'for validation',
                'for_validation',
                'pending_review',
            ]),
            'document_review' => pswdo_count_document_review_queue($connection),
            'for_screening' => (int) ($prescreenCounts['for_screening'] ?? 0),
            'incomplete_requirements' => (int) ($prescreenCounts['incomplete_requirements'] ?? 0),
            'invalid_requirements' => (int) ($prescreenCounts['invalid_requirements'] ?? 0),
            'release_ready' => pswdo_count_release_ready_queue($connection),
        ],
        'new_submissions' => pswdo_fetch_workflow_lane_requests($connection, 'new_submissions', $limit),
        'document_review' => pswdo_fetch_workflow_lane_requests($connection, 'document_review', $limit),
        'for_screening' => array_slice(pswdo_fetch_prescreening_queue($connection, 'for_screening'), 0, $limit),
        'release_ready' => pswdo_fetch_workflow_lane_requests($connection, 'release_ready', $limit),
    ];
}

function pswdo_count_requests_by_status_values(mysqli $connection, array $statusValues): int
{
    $cleanValues = [];
    foreach ($statusValues as $value) {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            continue;
        }
        $cleanValues[$normalized] = $normalized;
    }

    if (count($cleanValues) === 0) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($cleanValues), '?'));
    $sql = "SELECT COUNT(*) AS total_count FROM aics_requests WHERE LOWER(TRIM(request_status)) IN ($placeholders) AND " . aics_ai_gate_visible_sql('aics_requests');
    $stmt = $connection->prepare($sql);
    $types = str_repeat('s', count($cleanValues));
    $bindParams = [$types];
    foreach (array_values($cleanValues) as $value) {
        $bindParams[] = $value;
    }
    $refs = [];
    foreach ($bindParams as $index => $value) {
        $refs[$index] = &$bindParams[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    return (int) ($row['total_count'] ?? 0);
}

function pswdo_count_document_review_queue(mysqli $connection): int
{
    $visibleSql = aics_ai_gate_visible_sql('r');
    $sql = <<<SQL
        SELECT COUNT(*) AS total_count
        FROM (
            SELECT
                r.id,
                COUNT(DISTINCT d.id) AS uploaded_total,
                SUM(CASE WHEN COALESCE(dr.is_checked, 0) = 1 THEN 1 ELSE 0 END) AS checked_total
            FROM aics_requests r
            LEFT JOIN aics_request_documents d ON d.request_id = r.id
            LEFT JOIN aics_request_document_reviews dr ON dr.request_document_id = d.id
            WHERE LOWER(TRIM(r.request_status)) NOT IN (
                'released', 'distributed', 'completed', 'closed',
                'not_approved', 'not approved', 'rejected', 'denied',
                'cancelled', 'canceled'
            )
              AND $visibleSql
            GROUP BY r.id
            HAVING uploaded_total > 0 AND checked_total < uploaded_total
        ) review_queue
    SQL;

    $row = $connection->query($sql)?->fetch_assoc();
    return (int) ($row['total_count'] ?? 0);
}

function pswdo_count_release_ready_queue(mysqli $connection): int
{
    $sql = <<<SQL
        SELECT COUNT(*) AS total_count
        FROM aics_requests r
        WHERE LOWER(TRIM(r.request_status)) IN ('validated', 'for_release', 'for release')
          AND LOWER(TRIM(COALESCE(r.claim_status, 'unclaimed'))) <> 'claimed'
    SQL;

    $row = $connection->query($sql)?->fetch_assoc();
    return (int) ($row['total_count'] ?? 0);
}

/**
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_workflow_lane_requests(mysqli $connection, string $lane, int $limit = 5): array
{
    $limit = max(1, min(12, $limit));
    $laneCode = strtolower(trim($lane));

    $conditions = [];
    $having = '';
    $orderBy = 'ORDER BY r.created_at DESC, r.id DESC';

    if ($laneCode === 'new_submissions') {
        $conditions[] = "LOWER(TRIM(r.request_status)) IN ('pending', 'for_review', 'for validation', 'for_validation', 'pending_review')";
    } elseif ($laneCode === 'document_review') {
        $conditions[] = "LOWER(TRIM(r.request_status)) NOT IN (
            'released', 'distributed', 'completed', 'closed',
            'not_approved', 'not approved', 'rejected', 'denied',
            'cancelled', 'canceled'
        )";
        $having = 'HAVING COUNT(DISTINCT d.id) > 0 AND SUM(CASE WHEN COALESCE(dr.is_checked, 0) = 1 THEN 1 ELSE 0 END) < COUNT(DISTINCT d.id)';
        $orderBy = 'ORDER BY (COUNT(DISTINCT d.id) - SUM(CASE WHEN COALESCE(dr.is_checked, 0) = 1 THEN 1 ELSE 0 END)) DESC, r.created_at ASC, r.id DESC';
    } elseif ($laneCode === 'release_ready') {
        $conditions[] = "LOWER(TRIM(r.request_status)) IN ('approved', 'validated', 'for_release', 'for release')";
        $conditions[] = "LOWER(TRIM(COALESCE(r.claim_status, 'unclaimed'))) <> 'claimed'";
        $orderBy = "ORDER BY
            CASE WHEN r.cheque_date IS NULL OR r.cheque_date = '0000-00-00' THEN 1 ELSE 0 END ASC,
            r.cheque_date ASC,
            r.updated_at DESC,
            r.id DESC";
    } else {
        return [];
    }

    $conditions[] = aics_ai_gate_visible_sql('r');
    $whereSql = count($conditions) > 0 ? ('WHERE ' . implode(' AND ', $conditions)) : '';
    // Urgent, then high priority, always first in every list.
    $orderBy = preg_replace('/^ORDER BY\s+/', 'ORDER BY ' . pswdo_smart_priority_order_case_sql('pr') . ' ASC, ', $orderBy) ?? $orderBy;

    $sql = <<<SQL
        SELECT
            r.id,
            r.reference_no,
            r.date_applied,
            r.request_status,
            r.beneficiary_complete_name,
            r.applicant_first_name,
            r.applicant_middle_name,
            r.applicant_last_name,
            r.contact_number,
            r.barangay,
            r.municipality,
            r.claim_status,
            r.amount,
            r.cheque_date,
            at.assistance_name,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ap.first_name, ap.middle_name, ap.last_name)), ''), NULLIF(TRIM(CONCAT_WS(' ', r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name)), '')) AS applicant_profile_name,
            COALESCE(req.required_total, 0) AS required_total,
            COUNT(DISTINCT d.id) AS uploaded_total,
            SUM(CASE WHEN COALESCE(dr.is_checked, 0) = 1 THEN 1 ELSE 0 END) AS checked_total
        FROM aics_requests r
        LEFT JOIN (
            SELECT request_id, MIN(assistance_type_id) AS assistance_type_id
            FROM aics_request_assistance
            GROUP BY request_id
        ) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        LEFT JOIN aics_account_requests ar ON ar.request_id = r.id
        LEFT JOIN aics_accounts a ON a.id = ar.account_id
        LEFT JOIN aics_applicant_profiles ap ON ap.account_id = a.id
        LEFT JOIN (
            SELECT ra.request_id, COUNT(*) AS required_total
            FROM aics_request_assistance ra
            INNER JOIN aics_assistance_required_documents ard ON ard.assistance_type_id = ra.assistance_type_id
            INNER JOIN aics_document_types dt ON dt.id = ard.document_type_id AND dt.document_code <> 'coe_opswdo'
            GROUP BY ra.request_id
        ) req ON req.request_id = r.id
        LEFT JOIN aics_request_documents d ON d.request_id = r.id
        LEFT JOIN aics_request_document_reviews dr ON dr.request_document_id = d.id
        LEFT JOIN aics_request_prioritization pr ON pr.request_id = r.id
        $whereSql
        GROUP BY
            pr.urgency_indicator,
            pr.priority_level,
            r.id,
            r.reference_no,
            r.date_applied,
            r.request_status,
            r.beneficiary_complete_name,
            r.applicant_first_name,
            r.applicant_middle_name,
            r.applicant_last_name,
            r.contact_number,
            r.barangay,
            r.municipality,
            r.claim_status,
            r.amount,
            r.cheque_date,
            at.assistance_name,
            applicant_profile_name,
            req.required_total,
            r.created_at,
            r.updated_at
        $having
        $orderBy
        LIMIT ?
    SQL;

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $applicantSubmittedName = trim(implode(' ', array_filter([
            trim((string) ($row['applicant_first_name'] ?? '')),
            trim((string) ($row['applicant_middle_name'] ?? '')),
            trim((string) ($row['applicant_last_name'] ?? '')),
        ])));
        $applicantName = trim((string) ($row['applicant_profile_name'] ?? ''));
        if ($applicantName === '') {
            $applicantName = $applicantSubmittedName;
        }

        $addressParts = array_filter([
            trim((string) ($row['barangay'] ?? '')),
            trim((string) ($row['municipality'] ?? '')),
        ]);

        $rows[] = [
            'request_id' => (int) ($row['id'] ?? 0),
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'date_applied_label' => pswdo_date_label((string) ($row['date_applied'] ?? '')),
            'status_code' => pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending')),
            'status_label' => pswdo_status_labels()[pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'))] ?? 'Pending Review',
            'status_tone' => pswdo_status_tone(pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'))),
            'beneficiary_name' => trim((string) ($row['beneficiary_complete_name'] ?? '')),
            'applicant_name' => $applicantName !== '' ? $applicantName : 'Applicant',
            'contact_number' => trim((string) ($row['contact_number'] ?? '')),
            'address_label' => count($addressParts) > 0 ? implode(', ', $addressParts) : 'Not available',
            'assistance_label' => trim((string) ($row['assistance_name'] ?? '')) !== '' ? (string) $row['assistance_name'] : 'Assistance Request',
            'required_total' => (int) ($row['required_total'] ?? 0),
            'uploaded_total' => (int) ($row['uploaded_total'] ?? 0),
            'checked_total' => (int) ($row['checked_total'] ?? 0),
            'claim_status' => trim((string) ($row['claim_status'] ?? '')),
            'claim_status_label' => pswdo_titleize((string) ($row['claim_status'] ?? '')),
            'amount_label' => 'PHP ' . number_format((float) ($row['amount'] ?? 0), 2),
            'cheque_date_label' => pswdo_date_label((string) ($row['cheque_date'] ?? '')),
        ];
    }
    $stmt->close();

    return $rows;
}

/**
 * @return array<string, string>
 */
function pswdo_release_status_labels(): array
{
    return [
        'unscheduled' => 'Needs Schedule',
        'scheduled' => 'Scheduled',
        'rescheduled' => 'Rescheduled',
        'ready_for_release' => 'Ready for Release',
        'missed' => 'Missed Schedule',
        'released' => 'Released',
    ];
}

/**
 * @return array<string, int>
 */
function pswdo_fetch_release_schedule_overview(mysqli $connection): array
{
    $summary = [
        'unscheduled' => 0,
        'scheduled_today' => 0,
        'upcoming' => 0,
        'ready_for_release' => 0,
        'missed' => 0,
        'released' => 0,
    ];

    $sql = <<<SQL
        SELECT
            SUM(CASE
                WHEN LOWER(TRIM(r.request_status)) IN ('validated', 'for_release', 'for release')
                  AND LOWER(TRIM(COALESCE(r.claim_status, 'unclaimed'))) <> 'claimed'
                  AND (rs.request_id IS NULL OR rs.release_status = 'unscheduled')
                THEN 1 ELSE 0 END) AS unscheduled_total,
            SUM(CASE
                WHEN rs.release_status IN ('scheduled', 'rescheduled')
                  AND rs.scheduled_date = CURDATE()
                THEN 1 ELSE 0 END) AS scheduled_today_total,
            SUM(CASE
                WHEN rs.release_status IN ('scheduled', 'rescheduled')
                  AND rs.scheduled_date > CURDATE()
                THEN 1 ELSE 0 END) AS upcoming_total,
            SUM(CASE
                WHEN rs.release_status = 'ready_for_release'
                THEN 1 ELSE 0 END) AS ready_total,
            SUM(CASE
                WHEN rs.release_status = 'missed'
                THEN 1 ELSE 0 END) AS missed_total,
            SUM(CASE
                WHEN LOWER(TRIM(r.request_status)) IN ('released', 'distributed', 'completed', 'closed')
                   OR rs.release_status = 'released'
                   OR LOWER(TRIM(COALESCE(r.claim_status, 'unclaimed'))) = 'claimed'
                THEN 1 ELSE 0 END) AS released_total
        FROM aics_requests r
        LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
    SQL;

    $row = $connection->query($sql)?->fetch_assoc();
    if (is_array($row)) {
        $summary['unscheduled'] = (int) ($row['unscheduled_total'] ?? 0);
        $summary['scheduled_today'] = (int) ($row['scheduled_today_total'] ?? 0);
        $summary['upcoming'] = (int) ($row['upcoming_total'] ?? 0);
        $summary['ready_for_release'] = (int) ($row['ready_total'] ?? 0);
        $summary['missed'] = (int) ($row['missed_total'] ?? 0);
        $summary['released'] = (int) ($row['released_total'] ?? 0);
    }

    return $summary;
}

function pswdo_approve_for_release_action(mysqli $connection, int $requestId, int $accountId): array
{
    $stmt = $connection->prepare('SELECT id, reference_no, request_status FROM aics_requests WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($row)) {
        throw new RuntimeException('Request not found.');
    }
    if ((string) ($row['request_status'] ?? '') !== 'approved') {
        throw new RuntimeException('Only requests with "Approved" status can be moved to Release Scheduling.');
    }
    $referenceNo = (string) ($row['reference_no'] ?? '');
    $status = 'for release';
    $connection->begin_transaction();
    try {
        $update = $connection->prepare('UPDATE aics_requests SET request_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND request_status = ?');
        $currentStatus = 'approved';
        $update->bind_param('sis', $status, $requestId, $currentStatus);
        $update->execute();
        $changedRows = $update->affected_rows;
        $update->close();
        if ($changedRows !== 1) {
            throw new RuntimeException('This request is no longer available for release approval. Please refresh the queue.');
        }
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    return ['reference_no' => $referenceNo];
}

/**
 * Pre-screening passed: the request moves to Release Scheduling and the
 * applicant gets an SMS to wait for the schedule text.
 *
 * @return array{name: string, reference: string, sms_sent: bool}
 */
function pswdo_finish_prescreening_action(mysqli $connection, int $requestId, int $accountId): array
{
    pswdo_approve_for_release_action($connection, $requestId, $accountId);
    $smsSent = false;
    try {
        pswdo_send_sms_to_applicant_action($connection, $requestId, 'screening_passed', '', $accountId);
        $smsSent = true;
    } catch (Throwable $exception) {
        error_log('AICS pre-screening SMS failed request_id=' . $requestId . ' error=' . $exception->getMessage());
    }
    $identity = pswdo_request_identity($connection, $requestId);
    try {
        pswdo_save_notification_action(
            $connection,
            $requestId,
            'Further Screening Passed',
            'Your application ' . $identity['reference'] . ' passed further screening. Please wait for an SMS with your release schedule.',
            $accountId
        );
    } catch (Throwable $exception) {
        error_log('AICS pre-screening notification failed request_id=' . $requestId . ' error=' . $exception->getMessage());
    }
    return $identity + ['sms_sent' => $smsSent];
}

/**
 * Finishes pre-screening for each selected request, like the mass notice:
 * one SMS per applicant, and a count of what happened.
 *
 * @param array<int, mixed> $rawIds
 * @return array{moved: int, sms_sent: int, failed: list<array{name: string, reference: string, reason: string}>}
 */
function pswdo_bulk_finish_prescreening_action(mysqli $connection, array $rawIds, int $accountId): array
{
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn (int $id): bool => $id > 0)));
    if ($requestIds === []) {
        throw new RuntimeException('Select at least one applicant to approve for scheduling.');
    }

    $outcome = ['moved' => 0, 'sms_sent' => 0, 'failed' => []];
    foreach (array_slice($requestIds, 0, 100) as $requestId) {
        try {
            $result = pswdo_finish_prescreening_action($connection, $requestId, $accountId);
            $outcome['moved']++;
            if ($result['sms_sent']) $outcome['sms_sent']++;
        } catch (Throwable $exception) {
            $outcome['failed'][] = pswdo_request_identity($connection, $requestId) + ['reason' => $exception->getMessage()];
        }
    }

    return $outcome;
}

/**
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_release_schedule_queue(
    mysqli $connection,
    string $view = 'all',
    int $limit = 50,
    string $sortBy = 'default',
    string $sortGroup = 'all',
    string $batchDateFrom = '',
    string $batchDateTo = '',
    string $batchKey = ''
): array
{
    $releaseView = pswdo_normalize_release_view($view);
    $releaseSort = pswdo_normalize_release_sort($sortBy);
    $releaseSortGroup = pswdo_normalize_release_sort_group($releaseSort, $sortGroup);
    $limit = max(1, min(200, $limit));

    $conditions = [];
    $params = [];
    $types = '';
    if ($releaseView === 'waiting_release') {
        $conditions[] = "LOWER(TRIM(r.request_status)) IN ('validated', 'for_release', 'for release')";
        $conditions[] = "LOWER(TRIM(COALESCE(r.claim_status, 'unclaimed'))) <> 'claimed'";
    } elseif ($releaseView === 'unscheduled') {
        $conditions[] = "LOWER(TRIM(r.request_status)) IN ('validated', 'for_release', 'for release')";
        $conditions[] = "LOWER(TRIM(COALESCE(r.claim_status, 'unclaimed'))) <> 'claimed'";
        $conditions[] = "(rs.request_id IS NULL OR rs.release_status = 'unscheduled')";
    } elseif ($releaseView === 'scheduled') {
        $conditions[] = "rs.release_status IN ('scheduled', 'rescheduled')";
    } elseif ($releaseView === 'ready_for_release') {
        $conditions[] = "rs.release_status = 'ready_for_release'";
    } elseif ($releaseView === 'missed') {
        $conditions[] = "rs.release_status = 'missed'";
    } elseif ($releaseView === 'released') {
        $conditions[] = "(LOWER(TRIM(r.request_status)) IN ('released', 'distributed', 'completed', 'closed') OR rs.release_status = 'released' OR LOWER(TRIM(COALESCE(r.claim_status, 'unclaimed'))) = 'claimed')";
    } else {
        $conditions[] = "(
            (
                LOWER(TRIM(r.request_status)) IN ('validated', 'for_release', 'for release')
                AND LOWER(TRIM(COALESCE(r.claim_status, 'unclaimed'))) <> 'claimed'
            )
            OR rs.release_status IN ('scheduled', 'rescheduled', 'ready_for_release', 'missed')
        )";
    }

    if ($releaseSort === 'client_classification' && $releaseSortGroup !== 'all') {
        $conditions[] = "LOWER(COALESCE(r.client_categories_json, '')) LIKE ?";
        $params[] = '%' . $releaseSortGroup . '%';
        $types .= 's';
    }

    if ($releaseSort === 'type_of_assistance' && $releaseSortGroup !== 'all') {
        $conditions[] = "COALESCE(at.assistance_code, '') = ?";
        $params[] = $releaseSortGroup;
        $types .= 's';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDateFrom) === 1) {
        $conditions[] = "COALESCE(NULLIF(rs.scheduled_date, '0000-00-00'), DATE(r.date_applied)) >= ?";
        $params[] = $batchDateFrom;
        $types .= 's';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDateTo) === 1) {
        $conditions[] = "COALESCE(NULLIF(rs.scheduled_date, '0000-00-00'), DATE(r.date_applied)) <= ?";
        $params[] = $batchDateTo;
        $types .= 's';
    }

    $normalizedBatchKey = trim($batchKey);
    if ($normalizedBatchKey !== '' && $normalizedBatchKey !== 'all') {
        $batchDatePart = '';
        $batchNamePart = '';

        if (str_contains($normalizedBatchKey, '|')) {
            [$datePart, $namePart] = explode('|', $normalizedBatchKey, 2);
            $batchDatePart = trim($datePart);
            $batchNamePart = trim($namePart);
        } else {
            $batchNamePart = $normalizedBatchKey;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchDatePart) === 1) {
            $conditions[] = "rs.scheduled_date = ?";
            $params[] = $batchDatePart;
            $types .= 's';
        }

        if ($batchNamePart !== '') {
            $conditions[] = "LOWER(TRIM(COALESCE(rs.release_batch, ''))) = LOWER(TRIM(?))";
            $params[] = $batchNamePart;
            $types .= 's';
        }
    }

    $whereSql = 'WHERE ' . implode(' AND ', $conditions);
    // Urgent, then high priority, always first; release status and the chosen sort apply within each level.
    $smartPriorityOrderSql = pswdo_smart_priority_order_case_sql('pr');
    $defaultOrderSql = <<<SQL
        {$smartPriorityOrderSql} ASC,
        CASE
            WHEN rs.release_status = 'ready_for_release' THEN 0
            WHEN rs.release_status IN ('scheduled', 'rescheduled') THEN 1
            WHEN rs.release_status = 'missed' THEN 2
            WHEN rs.request_id IS NULL OR rs.release_status = 'unscheduled' THEN 3
            ELSE 4
        END ASC,
        CASE WHEN pr.priority_rank IS NULL THEN 1 ELSE 0 END ASC,
        pr.priority_rank ASC,
        CASE WHEN rs.scheduled_date IS NULL OR rs.scheduled_date = '0000-00-00' THEN 1 ELSE 0 END ASC,
        rs.scheduled_date ASC,
        rs.scheduled_time ASC,
        r.updated_at DESC,
        r.id DESC
    SQL;
    $clientClassificationOrderSql = <<<SQL
        {$smartPriorityOrderSql} ASC,
        CASE
            WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%senior_citizen%' THEN 0
            WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%solo_parent%' THEN 1
            WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%pwd%' THEN 2
            WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%ips%' THEN 3
            ELSE 4
        END ASC,
        $defaultOrderSql
    SQL;
    $assistanceOrderSql = <<<SQL
        {$smartPriorityOrderSql} ASC,
        CASE
            WHEN COALESCE(at.assistance_code, '') = 'medical' THEN 0
            WHEN COALESCE(at.assistance_code, '') = 'burial' THEN 1
            WHEN COALESCE(at.assistance_code, '') = 'victim_of_disaster' THEN 2
            WHEN COALESCE(at.assistance_code, '') = 'special_cases' THEN 3
            ELSE 5
        END ASC,
        $defaultOrderSql
    SQL;
    $orderBySql = match ($releaseSort) {
        'client_classification' => $clientClassificationOrderSql,
        'type_of_assistance' => $assistanceOrderSql,
        default => $defaultOrderSql,
    };

    $sql = <<<SQL
        SELECT
            r.id,
            r.reference_no,
            r.date_applied,
            r.request_status,
            r.beneficiary_complete_name,
            r.applicant_first_name,
            r.applicant_middle_name,
            r.applicant_last_name,
            r.contact_number,
            r.applicant_contact_number,
            ap.contact_number AS profile_contact_number,
            r.barangay,
            r.municipality,
            r.claim_status,
            r.client_categories_json,
            at.assistance_code,
            at.assistance_name,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ap.first_name, ap.middle_name, ap.last_name)), ''), NULLIF(TRIM(CONCAT_WS(' ', r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name)), '')) AS applicant_profile_name,
            pr.priority_rank,
            pr.priority_level,
            COALESCE(req.required_total, 0) AS required_total,
            COUNT(DISTINCT d.id) AS uploaded_total,
            rs.scheduled_date,
            rs.scheduled_time,
            rs.venue,
            rs.release_batch,
            rs.amount_given,
            rs.release_status,
            rs.notes,
            rs.updated_at AS release_updated_at,
            CONCAT_WS(' ', sp.first_name, sp.last_name) AS release_updated_by,
            p.confirmed_at AS prescreen_confirmed_at,
            CONCAT_WS(' ', sp2.first_name, sp2.last_name) AS prescreen_confirmed_by
        FROM aics_requests r
        LEFT JOIN (
            SELECT request_id, MIN(assistance_type_id) AS assistance_type_id
            FROM aics_request_assistance
            GROUP BY request_id
        ) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        LEFT JOIN aics_account_requests ar ON ar.request_id = r.id
        LEFT JOIN aics_accounts a ON a.id = ar.account_id
        LEFT JOIN aics_applicant_profiles ap ON ap.account_id = a.id
        LEFT JOIN aics_request_prioritization pr ON pr.request_id = r.id
        LEFT JOIN (
            SELECT ra.request_id, COUNT(*) AS required_total
            FROM aics_request_assistance ra
            INNER JOIN aics_assistance_required_documents ard ON ard.assistance_type_id = ra.assistance_type_id
            INNER JOIN aics_document_types dt ON dt.id = ard.document_type_id AND dt.document_code <> 'coe_opswdo'
            GROUP BY ra.request_id
        ) req ON req.request_id = r.id
        LEFT JOIN aics_request_documents d ON d.request_id = r.id
        LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = rs.updated_by_account_id
        LEFT JOIN aics_request_prescreening p ON p.request_id = r.id
        LEFT JOIN aics_staff_profiles sp2 ON sp2.account_id = p.confirmed_by_account_id
        $whereSql
        GROUP BY
            r.id,
            r.reference_no,
            r.date_applied,
            r.request_status,
            r.beneficiary_complete_name,
            r.applicant_first_name,
            r.applicant_middle_name,
            r.applicant_last_name,
            r.contact_number,
            r.applicant_contact_number,
            ap.contact_number,
            r.barangay,
            r.municipality,
            r.claim_status,
            r.client_categories_json,
            at.assistance_code,
            at.assistance_name,
            ap.first_name,
            ap.middle_name,
            ap.last_name,
            pr.priority_rank,
            pr.priority_level,
            pr.urgency_indicator,
            req.required_total,
            rs.scheduled_date,
            rs.scheduled_time,
            rs.venue,
            rs.release_batch,
            rs.amount_given,
            rs.release_status,
            rs.notes,
            rs.updated_at,
            sp.first_name,
            sp.last_name,
            p.confirmed_at,
            sp2.first_name,
            sp2.last_name
        ORDER BY $orderBySql
        LIMIT ?
    SQL;

    $stmt = $connection->prepare($sql);
    $params[] = $limit;
    $types .= 'i';

    $bindParams = [$types];
    foreach ($params as $index => $value) {
        $bindParams[$index + 1] = $value;
    }

    $references = [];
    foreach ($bindParams as $index => $value) {
        $references[$index] = &$bindParams[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $references);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $labels = pswdo_release_status_labels();
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $applicantSubmittedName = trim(implode(' ', array_filter([
            trim((string) ($row['applicant_first_name'] ?? '')),
            trim((string) ($row['applicant_middle_name'] ?? '')),
            trim((string) ($row['applicant_last_name'] ?? '')),
        ])));
        $applicantName = trim((string) ($row['applicant_profile_name'] ?? ''));
        if ($applicantName === '') {
            $applicantName = $applicantSubmittedName;
        }

        $addressParts = array_filter([
            trim((string) ($row['barangay'] ?? '')),
            trim((string) ($row['municipality'] ?? '')),
        ]);

        $releaseStatus = strtolower(trim((string) ($row['release_status'] ?? '')));
        if ($releaseStatus === '') {
            $normalizedRequest = strtolower(trim((string) ($row['request_status'] ?? '')));
            $isReleased = in_array($normalizedRequest, ['released', 'distributed', 'completed', 'closed'], true)
                || strtolower(trim((string) ($row['claim_status'] ?? ''))) === 'claimed';
            $releaseStatus = $isReleased ? 'released' : 'unscheduled';
        }

        $confirmedAtRaw = trim((string) ($row['release_updated_at'] ?? ''));
        if ($confirmedAtRaw === '' || $confirmedAtRaw === '0000-00-00 00:00:00') {
            $confirmedAtRaw = trim((string) ($row['prescreen_confirmed_at'] ?? ''));
        }
        $confirmedBy = trim((string) ($row['release_updated_by'] ?? ''));
        if ($confirmedBy === '') {
            $confirmedBy = trim((string) ($row['prescreen_confirmed_by'] ?? ''));
        }

        $rows[] = [
            'request_id' => (int) ($row['id'] ?? 0),
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'date_applied_label' => pswdo_date_label((string) ($row['date_applied'] ?? '')),
            'status_code' => pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending')),
            'status_label' => pswdo_status_labels()[pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'))] ?? 'Pending Review',
            'status_tone' => pswdo_status_tone(pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'))),
            'beneficiary_name' => trim((string) ($row['beneficiary_complete_name'] ?? '')),
            'applicant_name' => $applicantName !== '' ? $applicantName : 'Applicant',
            'contact_number' => trim((string) ($row['contact_number'] ?? '')),
            'applicant_contact_number' => pswdo_applicant_sms_contact($row),
            'municipality' => trim((string) ($row['municipality'] ?? '')),
            'address_label' => count($addressParts) > 0 ? implode(', ', $addressParts) : 'Not available',
            'client_categories_json' => (string) ($row['client_categories_json'] ?? '[]'),
            'client_categories_label' => pswdo_client_categories_label((string) ($row['client_categories_json'] ?? '[]')),
            'assistance_code' => trim((string) ($row['assistance_code'] ?? '')),
            'assistance_label' => trim((string) ($row['assistance_name'] ?? '')) !== '' ? (string) $row['assistance_name'] : 'Assistance Request',
            'priority_rank' => ($row['priority_rank'] !== null ? (int) $row['priority_rank'] : null),
            'priority_level' => trim((string) ($row['priority_level'] ?? '')) !== '' ? (string) ($row['priority_level']) : 'Normal Priority',
            'priority_label' => ($row['priority_rank'] !== null ? ('#' . (int) $row['priority_rank'] . ' · ') : '') . (trim((string) ($row['priority_level'] ?? '')) !== '' ? (string) ($row['priority_level']) : 'Normal Priority'),
            'claim_status' => trim((string) ($row['claim_status'] ?? '')),
            'claim_status_label' => pswdo_titleize((string) ($row['claim_status'] ?? '')),
            'required_total' => (int) ($row['required_total'] ?? 0),
            'uploaded_total' => (int) ($row['uploaded_total'] ?? 0),
            'requirements_label' => ((int) ($row['uploaded_total'] ?? 0)) . ' / ' . ((int) ($row['required_total'] ?? 0)) . ' files',
            'scheduled_date' => trim((string) ($row['scheduled_date'] ?? '')),
            'scheduled_date_label' => pswdo_date_label((string) ($row['scheduled_date'] ?? '')),
            'scheduled_time' => trim((string) ($row['scheduled_time'] ?? '')),
            'scheduled_time_label' => pswdo_release_time_label((string) ($row['scheduled_time'] ?? '')),
            'venue' => trim((string) ($row['venue'] ?? '')),
            'release_batch' => trim((string) ($row['release_batch'] ?? '')),
            'amount_given' => ($row['amount_given'] ?? null) !== null ? (float) $row['amount_given'] : null,
            'release_status' => $releaseStatus,
            'release_status_label' => $labels[$releaseStatus] ?? 'Needs Schedule',
            'release_notes' => trim((string) ($row['notes'] ?? '')),
            'release_updated_at_label' => pswdo_datetime_label($confirmedAtRaw),
            'release_updated_by' => $confirmedBy !== '' ? $confirmedBy : 'PSWDO Personnel',
        ];
    }
    $stmt->close();

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function pswdo_fetch_release_schedule_record(mysqli $connection, int $requestId): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    $rows = pswdo_fetch_release_schedule_queue($connection, 'all', 200);
    foreach ($rows as $row) {
        if ((int) ($row['request_id'] ?? 0) === $requestId) {
            return $row;
        }
    }

    $rows = pswdo_fetch_release_schedule_queue($connection, 'released', 200);
    foreach ($rows as $row) {
        if ((int) ($row['request_id'] ?? 0) === $requestId) {
            return $row;
        }
    }

    return null;
}

/** The amount to release must fit DECIMAL(10,2) and be more than zero. */
function pswdo_validate_release_amount(float $amountGiven): void
{
    if (!($amountGiven > 0)) {
        throw new RuntimeException('Enter the amount to release.');
    }
    if ($amountGiven > 99999999.99) {
        throw new RuntimeException('The amount to release is too large.');
    }
}

function pswdo_should_reopen_release_schedule_modal(string $actionType): bool
{
    return strtolower(trim($actionType)) !== 'save_release_schedule';
}

function pswdo_release_time_label(string $value): string
{
    $clean = trim($value);
    if ($clean === '' || $clean === '00:00:00') {
        return 'Not set';
    }
    try {
        return (new DateTimeImmutable($clean))->format('h:i A');
    } catch (Throwable) {
        return $clean;
    }
}

/**
 * @return array<string, string>
 */
function pswdo_save_release_schedule_action(
    mysqli $connection,
    int $requestId,
    int $accountId,
    string $scheduledDate,
    string $scheduledTime,
    string $venue,
    int $batchLimit,
    string $notes,
    float $amountGiven
): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
        throw new RuntimeException('A valid release date is required.');
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $scheduledTime)) {
        throw new RuntimeException('A valid release time is required.');
    }
    if (trim($venue) === '') {
        throw new RuntimeException('Release venue is required.');
    }
    // The amount is entered at Complete Release; one saved earlier is kept.
    if ($amountGiven > 0) pswdo_validate_release_amount($amountGiven);
    $amountValue = $amountGiven > 0 ? $amountGiven : null;

    $lookup = $connection->prepare(
        'SELECT r.reference_no, r.request_status, rs.scheduled_date, rs.scheduled_time, rs.venue FROM aics_requests r LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id WHERE r.id = ? LIMIT 1'
    );
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc();
    $lookup->close();

    if (!is_array($row)) {
        throw new RuntimeException('Request not found for release scheduling.');
    }

    $referenceNo = trim((string) ($row['reference_no'] ?? ''));
    $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending_review'));
    if (in_array($statusCode, ['not_approved', 'cancelled'], true)) {
        throw new RuntimeException('Only approved or releasable requests can be scheduled.');
    }

    $existingDate = trim((string) ($row['scheduled_date'] ?? ''));
    $existingTime = substr(trim((string) ($row['scheduled_time'] ?? '')), 0, 5);
    $existingVenue = trim((string) ($row['venue'] ?? ''));
    $isReschedule = $existingDate !== '' || $existingTime !== '' || $existingVenue !== '';
    $releaseStatus = $isReschedule ? 'rescheduled' : 'scheduled';
    $notesValue = trim($notes);
    if ($notesValue === '') {
        $notesValue = $isReschedule
            ? 'Release schedule was updated by PSWDO.'
            : 'Release schedule was prepared by PSWDO.';
    }

    $scheduledTimeSql = $scheduledTime . ':00';
    $venueValue = trim($venue);

    $limit = max(1, $batchLimit);
    $countStmt = $connection->prepare(
        'SELECT COUNT(*) FROM aics_request_release_schedule WHERE scheduled_date = ? AND request_id != ?'
    );
    $countStmt->bind_param('si', $scheduledDate, $requestId);
    $countStmt->execute();
    $countStmt->bind_result($existingCount);
    $countStmt->fetch();
    $countStmt->close();
    $position = (int) $existingCount + 1;
    $batchNumber = (int) ceil($position / $limit);
    $batchValue = 'Batch ' . $batchNumber;
    $sql = <<<SQL
        INSERT INTO aics_request_release_schedule (
            request_id,
            scheduled_date,
            scheduled_time,
            venue,
            release_batch,
            amount_given,
            release_status,
            notes,
            scheduled_by_account_id,
            updated_by_account_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            scheduled_date = VALUES(scheduled_date),
            scheduled_time = VALUES(scheduled_time),
            venue = VALUES(venue),
            release_batch = VALUES(release_batch),
            amount_given = COALESCE(VALUES(amount_given), amount_given),
            release_status = VALUES(release_status),
            notes = VALUES(notes),
            updated_by_account_id = VALUES(updated_by_account_id)
    SQL;
    $stmt = $connection->prepare($sql);
    $stmt->bind_param(
        'issssdssii',
        $requestId,
        $scheduledDate,
        $scheduledTimeSql,
        $venueValue,
        $batchValue,
        $amountValue,
        $releaseStatus,
        $notesValue,
        $accountId,
        $accountId
    );
    $stmt->execute();
    $stmt->close();

    $statusRemark = 'PSWDO prepared a release schedule for this application.';
    pswdo_update_request_status_action($connection, $requestId, 'approved', $accountId, $statusRemark);

    $requestUpdate = $connection->prepare(
        'UPDATE aics_requests SET request_status = ?, cheque_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $requestStatus = 'for release';
    $requestUpdate->bind_param('ssi', $requestStatus, $scheduledDate, $requestId);
    $requestUpdate->execute();
    $requestUpdate->close();

    $scheduleLabel = pswdo_date_label($scheduledDate) . ' at ' . pswdo_release_time_label($scheduledTimeSql);
    $notificationMessage = $referenceNo !== ''
        ? ('PSWDO prepared a release schedule for application ' . $referenceNo . ' on ' . $scheduleLabel . ' at ' . $venueValue . '.')
        : ('PSWDO prepared a release schedule on ' . $scheduleLabel . ' at ' . $venueValue . '.');
    pswdo_save_notification_action($connection, $requestId, 'Release Schedule Prepared', $notificationMessage, $accountId);

    return [
        'reference_no' => $referenceNo,
        'release_status_label' => pswdo_release_status_labels()[$releaseStatus] ?? 'Scheduled',
        'schedule_label' => $scheduleLabel,
    ];
}

/**
 * Bulk-schedule a list of requests for the same date/time/venue.
 * Batches are auto-assigned sequentially based on $batchLimit.
 *
 * @param list<mixed> $requestIds
 */
function pswdo_bulk_save_release_schedule_action(
    mysqli $connection,
    array $requestIds,
    int $accountId,
    string $scheduledDate,
    string $scheduledTime,
    string $venue,
    int $batchLimit,
    string $notes
): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
        throw new RuntimeException('A valid release date is required.');
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $scheduledTime)) {
        throw new RuntimeException('A valid release time is required.');
    }
    if (trim($venue) === '') {
        throw new RuntimeException('Release venue is required.');
    }

    $limit = max(1, $batchLimit);
    // Bulk save only commits the chosen DATE + TIME for everyone. Batch labeling
    // (Batch 1 morning, Batch 2 afternoon) is deferred to Mass Notice, where the
    // first $batchLimit recipients get the chosen time and the rest are pushed to
    // an afternoon slot at send time.
    $scheduledTimeSql = $scheduledTime . ':00';
    $venueValue = trim($venue);
    $notesValue = trim($notes) !== '' ? trim($notes) : 'Release schedule was prepared by PSWDO.';
    $saved = 0;

    foreach ($requestIds as $rawId) {
        $requestId = (int) $rawId;
        if ($requestId <= 0) {
            continue;
        }

        $lookup = $connection->prepare(
            'SELECT request_status FROM aics_requests WHERE id = ? LIMIT 1'
        );
        $lookup->bind_param('i', $requestId);
        $lookup->execute();
        $row = $lookup->get_result()?->fetch_assoc();
        $lookup->close();

        if (!is_array($row)) {
            continue;
        }
        $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending_review'));
        if (in_array($statusCode, ['not_approved', 'cancelled'], true)) {
            continue;
        }

        // No per-row batch tagging at save time. release_batch stays empty until Mass
        // Notice runs (which will split the day into Batch 1 / Batch 2 by limit).
        $batchValue = '';

        $sql = <<<SQL
            INSERT INTO aics_request_release_schedule (
                request_id, scheduled_date, scheduled_time, venue,
                release_batch, release_status, notes,
                scheduled_by_account_id, updated_by_account_id
            ) VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                scheduled_date = VALUES(scheduled_date),
                scheduled_time = VALUES(scheduled_time),
                venue = VALUES(venue),
                release_batch = VALUES(release_batch),
                release_status = 'rescheduled',
                notes = VALUES(notes),
                updated_by_account_id = VALUES(updated_by_account_id)
        SQL;

        $stmt = $connection->prepare($sql);
        $stmt->bind_param(
            'isssssii',
            $requestId,
            $scheduledDate,
            $scheduledTimeSql,
            $venueValue,
            $batchValue,
            $notesValue,
            $accountId,
            $accountId
        );
        $stmt->execute();
        $stmt->close();

        $requestUpdate = $connection->prepare(
            'UPDATE aics_requests SET request_status = ?, cheque_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $requestStatus = 'for release';
        $requestUpdate->bind_param('ssi', $requestStatus, $scheduledDate, $requestId);
        $requestUpdate->execute();
        $requestUpdate->close();

        $saved++;
    }

    return $saved;
}

/**
 * @return array<string, string>
 */
function pswdo_update_release_schedule_status_action(
    mysqli $connection,
    int $requestId,
    string $releaseStatus,
    int $accountId,
    string $notes,
    float $amountGiven = 0.0
): array {
    $status = strtolower(trim($releaseStatus));
    if (!in_array($status, ['ready_for_release', 'missed', 'released'], true)) {
        throw new RuntimeException('Unsupported release schedule status.');
    }

    $lookup = $connection->prepare('SELECT r.reference_no, rs.amount_given FROM aics_requests r LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id WHERE r.id = ? LIMIT 1');
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc();
    $lookup->close();
    if (!is_array($row)) {
        throw new RuntimeException('Request not found for release update.');
    }
    if ($status === 'released') {
        // The amount comes from the schedule; one entered here is for schedules saved without it.
        if ($amountGiven > 0) {
            pswdo_validate_release_amount($amountGiven);
        } elseif ((float) ($row['amount_given'] ?? 0) <= 0) {
            throw new RuntimeException('Enter the amount to release before completing the release.');
        }
    }

    $referenceNo = trim((string) ($row['reference_no'] ?? ''));
    $notesValue = trim($notes);
    if ($notesValue === '') {
        $notesValue = match ($status) {
            'ready_for_release' => 'PSWDO marked this case as ready for release.',
            'missed' => 'PSWDO marked this scheduled release as missed.',
            'released' => 'PSWDO completed the release of assistance.',
            default => 'Release status updated.',
        };
    }

    if ($status === 'released' && $amountGiven > 0) {
        $stmt = $connection->prepare(
            'INSERT INTO aics_request_release_schedule (request_id, release_status, amount_given, notes, scheduled_by_account_id, updated_by_account_id) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE release_status = VALUES(release_status), amount_given = VALUES(amount_given), notes = VALUES(notes), updated_by_account_id = VALUES(updated_by_account_id)'
        );
        $stmt->bind_param('isdsii', $requestId, $status, $amountGiven, $notesValue, $accountId, $accountId);
    } else {
        $stmt = $connection->prepare(
            'INSERT INTO aics_request_release_schedule (request_id, release_status, notes, scheduled_by_account_id, updated_by_account_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE release_status = VALUES(release_status), notes = VALUES(notes), updated_by_account_id = VALUES(updated_by_account_id)'
        );
        $stmt->bind_param('issii', $requestId, $status, $notesValue, $accountId, $accountId);
    }
    $stmt->execute();
    $stmt->close();

    if ($status === 'released') {
        pswdo_update_request_status_action($connection, $requestId, 'released', $accountId, 'PSWDO marked this assistance as released.');
        $requestUpdate = $connection->prepare(
            'UPDATE aics_requests SET claim_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $claimStatus = 'claimed';
        $requestUpdate->bind_param('si', $claimStatus, $requestId);
        $requestUpdate->execute();
        $requestUpdate->close();
    } elseif ($status === 'ready_for_release') {
        $requestUpdate = $connection->prepare(
            'UPDATE aics_requests SET request_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $requestStatus = 'for release';
        $requestUpdate->bind_param('si', $requestStatus, $requestId);
        $requestUpdate->execute();
        $requestUpdate->close();
    }

    $label = pswdo_release_status_labels()[$status] ?? 'Updated';
    $notificationMessage = $referenceNo !== ''
        ? ('Release status for application ' . $referenceNo . ' is now ' . $label . '.')
        : ('Release status is now ' . $label . '.');
    pswdo_save_notification_action($connection, $requestId, 'Release Status Update', $notificationMessage, $accountId);

    return [
        'reference_no' => $referenceNo,
        'release_status_label' => $label,
    ];
}

/**
 * Mark a scheduled applicant as "did not arrive" and reset them to Needs Schedule.
 * Clears the scheduled date/time/batch and resets release_status to 'unscheduled' so
 * PSWDO can assign a new release date. Keeps the request_status at 'for release' so it
 * stays in the release queue.
 *
 * @return array{reference_no: string}
 */
function pswdo_reschedule_unattended_action(mysqli $connection, int $requestId, int $accountId): array
{
    if ($requestId <= 0) {
        throw new RuntimeException('Invalid request reference.');
    }

    $lookup = $connection->prepare(
        'SELECT r.reference_no, rs.release_status, rs.scheduled_date
         FROM aics_requests r
         LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
         WHERE r.id = ? LIMIT 1'
    );
    $lookup->bind_param('i', $requestId);
    $lookup->execute();
    $row = $lookup->get_result()?->fetch_assoc();
    $lookup->close();
    if (!is_array($row)) {
        throw new RuntimeException('Request not found.');
    }

    $currentReleaseStatus = strtolower(trim((string) ($row['release_status'] ?? '')));
    if ($currentReleaseStatus === 'released') {
        throw new RuntimeException('This applicant is already marked as released and cannot be rescheduled.');
    }

    $referenceNo = trim((string) ($row['reference_no'] ?? ''));

    $stmt = $connection->prepare(
        "UPDATE aics_request_release_schedule
         SET scheduled_date = NULL,
             scheduled_time = NULL,
             release_batch = NULL,
             release_status = 'unscheduled',
             notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes,'') = '' THEN '' ELSE ' | ' END, 'Did not arrive — rescheduled by PSWDO.'),
             updated_by_account_id = ?
         WHERE request_id = ?"
    );
    $stmt->bind_param('ii', $accountId, $requestId);
    $stmt->execute();
    $stmt->close();

    // Reset cheque_date too since it was being used as scheduled_date proxy.
    $requestUpdate = $connection->prepare(
        'UPDATE aics_requests SET cheque_date = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $requestUpdate->bind_param('i', $requestId);
    $requestUpdate->execute();
    $requestUpdate->close();

    pswdo_save_notification_action(
        $connection,
        $requestId,
        'Release Rescheduled',
        $referenceNo !== ''
            ? ('Request ' . $referenceNo . ' did not arrive and was returned to Needs Schedule.')
            : 'Applicant did not arrive and was returned to Needs Schedule.',
        $accountId
    );

    return ['reference_no' => $referenceNo];
}

/**
 * Returns approved applicants ready for release scheduling (for the formal report list).
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_for_release_report(
    mysqli $connection,
    string $dateFrom = '',
    string $dateTo = '',
    int $limit = 500
): array {
    $conditions = ["LOWER(TRIM(r.request_status)) IN ('for release', 'for_release', 'validated')"];
    $params = [];
    $types = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
        $conditions[] = "COALESCE(NULLIF(rs.scheduled_date, '0000-00-00'), DATE(r.updated_at)) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
        $conditions[] = "COALESCE(NULLIF(rs.scheduled_date, '0000-00-00'), DATE(r.updated_at)) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }
    $whereSql = 'WHERE ' . implode(' AND ', $conditions);
    $sql = <<<SQL
        SELECT
            r.id,
            r.reference_no,
            COALESCE(NULLIF(TRIM(r.beneficiary_complete_name), ''),
                CONCAT_WS(' ', r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name)) AS beneficiary_name,
            CONCAT_WS(' ', r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name) AS applicant_name,
            r.barangay,
            r.municipality,
            r.client_categories_json,
            r.date_applied,
            at.assistance_name,
            rs.scheduled_date,
            rs.scheduled_time,
            rs.venue,
            rs.release_batch,
            rs.release_status
        FROM aics_requests r
        LEFT JOIN (SELECT request_id, MIN(assistance_type_id) AS assistance_type_id FROM aics_request_assistance GROUP BY request_id) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
        $whereSql
        GROUP BY r.id, at.assistance_name, rs.scheduled_date, rs.scheduled_time, rs.venue, rs.release_batch, rs.release_status
        ORDER BY
            CASE WHEN rs.scheduled_date IS NULL OR rs.scheduled_date = '0000-00-00' THEN 1 ELSE 0 END ASC,
            rs.scheduled_date ASC,
            COALESCE(rs.release_batch, '') ASC,
            r.date_applied ASC,
            r.id ASC
        LIMIT ?
    SQL;
    $params[] = $limit;
    $types .= 'i';
    $stmt = $connection->prepare($sql);
    $bindParams = [$types];
    foreach ($params as $i => $v) { $bindParams[$i + 1] = $v; }
    $refs = [];
    foreach ($bindParams as $i => $v) { $refs[$i] = &$bindParams[$i]; }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    $statusLabels = pswdo_release_status_labels();
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) break;
        $schDate = (string) ($row['scheduled_date'] ?? '');
        $schTime = (string) ($row['scheduled_time'] ?? '');
        $rows[] = [
            'reference_no'   => (string) ($row['reference_no'] ?? '-'),
            'beneficiary_name' => (string) ($row['beneficiary_name'] ?? 'Unknown'),
            'applicant_name' => (string) ($row['applicant_name'] ?? ''),
            'address'        => trim(implode(', ', array_filter([(string)($row['barangay']??''), (string)($row['municipality']??'')]))),
            'categories'     => pswdo_client_categories_label((string)($row['client_categories_json'] ?? '')),
            'assistance'     => (string) ($row['assistance_name'] ?? 'Assistance'),
            'date_applied'   => pswdo_date_label((string)($row['date_applied'] ?? '')),
            'scheduled_date' => ($schDate !== '' && $schDate !== '0000-00-00') ? pswdo_date_label($schDate) : 'Not yet scheduled',
            'scheduled_time' => ($schTime !== '' && $schTime !== '00:00:00') ? pswdo_release_time_label($schTime) : '-',
            'venue'          => (string) ($row['venue'] ?? 'PSWDO Office'),
            'batch'          => (string) ($row['release_batch'] ?? '-'),
            'release_status' => $statusLabels[(string)($row['release_status'] ?? 'unscheduled')] ?? 'Needs Schedule',
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * Returns applicants who have already received their assistance (released status).
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_released_report(
    mysqli $connection,
    string $dateFrom = '',
    string $dateTo = '',
    int $limit = 500
): array {
    $conditions = [
        "(LOWER(TRIM(r.request_status)) IN ('released', 'distributed', 'completed', 'closed') OR rs.release_status = 'released' OR LOWER(TRIM(COALESCE(r.claim_status,''))) = 'claimed')"
    ];
    $params = [];
    $types = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
        $conditions[] = "COALESCE(NULLIF(rs.scheduled_date, '0000-00-00'), DATE(r.updated_at)) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
        $conditions[] = "COALESCE(NULLIF(rs.scheduled_date, '0000-00-00'), DATE(r.updated_at)) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }
    $whereSql = 'WHERE ' . implode(' AND ', $conditions);
    $sql = <<<SQL
        SELECT
            r.id,
            r.reference_no,
            COALESCE(NULLIF(TRIM(r.beneficiary_complete_name), ''),
                CONCAT_WS(' ', r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name)) AS beneficiary_name,
            CONCAT_WS(' ', r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name) AS applicant_name,
            r.barangay,
            r.municipality,
            r.client_categories_json,
            r.date_applied,
            at.assistance_name,
            rs.scheduled_date,
            rs.scheduled_time,
            rs.venue,
            rs.release_batch,
            rs.amount_given,
            rs.updated_at AS released_at
        FROM aics_requests r
        LEFT JOIN (SELECT request_id, MIN(assistance_type_id) AS assistance_type_id FROM aics_request_assistance GROUP BY request_id) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
        $whereSql
        GROUP BY r.id, at.assistance_name, rs.scheduled_date, rs.scheduled_time, rs.venue, rs.release_batch, rs.amount_given, rs.updated_at
        ORDER BY
            COALESCE(NULLIF(rs.scheduled_date, '0000-00-00'), DATE(r.updated_at)) DESC,
            COALESCE(rs.release_batch, '') ASC,
            r.id ASC
        LIMIT ?
    SQL;
    $params[] = $limit;
    $types .= 'i';
    $stmt = $connection->prepare($sql);
    $bindParams = [$types];
    foreach ($params as $i => $v) { $bindParams[$i + 1] = $v; }
    $refs = [];
    foreach ($bindParams as $i => $v) { $refs[$i] = &$bindParams[$i]; }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) break;
        $schDate = (string) ($row['scheduled_date'] ?? '');
        $schTime = (string) ($row['scheduled_time'] ?? '');
        $releasedAt = (string) ($row['released_at'] ?? '');
        $rows[] = [
            'reference_no'   => (string) ($row['reference_no'] ?? '-'),
            'beneficiary_name' => (string) ($row['beneficiary_name'] ?? 'Unknown'),
            'applicant_name' => (string) ($row['applicant_name'] ?? ''),
            'address'        => trim(implode(', ', array_filter([(string)($row['barangay']??''), (string)($row['municipality']??'')]))),
            'categories'     => pswdo_client_categories_label((string)($row['client_categories_json'] ?? '')),
            'assistance'     => (string) ($row['assistance_name'] ?? 'Assistance'),
            'date_applied'   => pswdo_date_label((string)($row['date_applied'] ?? '')),
            'release_date'   => ($schDate !== '' && $schDate !== '0000-00-00') ? pswdo_date_label($schDate) : 'Not recorded',
            'release_time'   => ($schTime !== '' && $schTime !== '00:00:00') ? pswdo_release_time_label($schTime) : '-',
            'venue'          => (string) ($row['venue'] ?? 'PSWDO Office'),
            'batch'          => (string) ($row['release_batch'] ?? '-'),
            'amount_given'   => $row['amount_given'] !== null ? (float) $row['amount_given'] : null,
            'released_at'    => $releasedAt !== '' ? pswdo_date_label(substr($releasedAt, 0, 10)) : 'Not recorded',
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * Returns distinct upcoming scheduled release dates with counts, for the date overview panel.
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_upcoming_release_dates(mysqli $connection): array
{
    $today = date('Y-m-d');
    $stmt = $connection->prepare(
        "SELECT rs.scheduled_date,
                COUNT(rs.request_id) AS applicant_count,
                COUNT(DISTINCT rs.release_batch) AS batch_count
         FROM aics_request_release_schedule rs
         INNER JOIN aics_requests r ON r.id = rs.request_id
         WHERE rs.scheduled_date IS NOT NULL
           AND rs.scheduled_date >= ?
           AND rs.scheduled_date != '0000-00-00'
           AND rs.release_status NOT IN ('released')
           AND r.request_status IN ('for release', 'for_release', 'validated')
         GROUP BY rs.scheduled_date
         ORDER BY rs.scheduled_date ASC
         LIMIT 20"
    );
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $dates = [];
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $dates[] = $row;
    }
    $stmt->close();
    return $dates;
}

/**
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_daily_approved_list(mysqli $connection, string $forDate): array
{
    $rows = [];
    $sql = <<<SQL
        SELECT
            r.reference_no,
            r.beneficiary_complete_name,
            r.contact_number,
            r.municipality,
            r.barangay,
            at.assistance_name,
            h.changed_at,
            CONCAT_WS(' ', sp.first_name, sp.last_name) AS approved_by
        FROM aics_request_status_history h
        INNER JOIN aics_request_statuses s ON s.id = h.status_id
        INNER JOIN aics_requests r ON r.id = h.request_id
        LEFT JOIN (
            SELECT request_id, MIN(assistance_type_id) AS assistance_type_id
            FROM aics_request_assistance
            GROUP BY request_id
        ) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = h.changed_by_account_id
        WHERE s.status_code = 'approved'
          AND DATE(h.changed_at) = ?
        ORDER BY h.changed_at DESC
    SQL;
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('s', $forDate);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $rows[] = [
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'beneficiary_name' => (string) ($row['beneficiary_complete_name'] ?? ''),
            'contact_number' => (string) ($row['contact_number'] ?? ''),
            'municipality' => (string) ($row['municipality'] ?? ''),
            'barangay' => (string) ($row['barangay'] ?? ''),
            'assistance_label' => trim((string) ($row['assistance_name'] ?? '')) !== '' ? (string) $row['assistance_name'] : 'Assistance Request',
            'approved_at' => pswdo_datetime_label((string) ($row['changed_at'] ?? '')),
            'approved_by' => trim((string) ($row['approved_by'] ?? '')),
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * @return array<string, int>
 */
function pswdo_fetch_report_summary(mysqli $connection, string $dateFrom, string $dateTo): array
{
    $summary = [
        'total_submitted' => 0,
        'approved' => 0,
        'not_approved' => 0,
        'released' => 0,
        'under_review' => 0,
    ];

    $submittedStmt = $connection->prepare('SELECT COUNT(*) AS total_count FROM aics_requests WHERE DATE(created_at) BETWEEN ? AND ? AND ' . aics_ai_gate_visible_sql('aics_requests'));
    $submittedStmt->bind_param('ss', $dateFrom, $dateTo);
    $submittedStmt->execute();
    $submittedRow = $submittedStmt->get_result()?->fetch_assoc();
    $submittedStmt->close();
    $summary['total_submitted'] = (int) ($submittedRow['total_count'] ?? 0);

    $statusResult = $connection->query('SELECT request_status, COUNT(*) AS total_count FROM aics_requests WHERE ' . aics_ai_gate_visible_sql('aics_requests') . ' GROUP BY request_status');
    while ($row = $statusResult?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'));
        $count = (int) ($row['total_count'] ?? 0);
        if ($statusCode === 'approved') {
            $summary['approved'] += $count;
        } elseif ($statusCode === 'not_approved') {
            $summary['not_approved'] += $count;
        } elseif ($statusCode === 'released') {
            $summary['released'] += $count;
        } elseif (in_array($statusCode, ['pending_review', 'for_interview', 'incomplete_requirements', 'under_review'], true)) {
            $summary['under_review'] += $count;
        }
    }

    return $summary;
}

/** @return array<string,int|float|null> */
function pswdo_fetch_ai_operational_metrics(mysqli $connection, string $dateFrom, string $dateTo): array
{
    $metrics = [
        'reviews' => 0, 'invocation_rate' => null, 'cache_hit_rate' => null,
        'unavailable_rate' => null, 'median_ms' => null, 'p95_ms' => null,
        'feedback' => 0, 'agreement_rate' => null, 'override_rate' => null,
    ];
    $stmt = $connection->prepare('SELECT ai.findings FROM document_ai_reviews ai INNER JOIN aics_request_documents d ON d.id = ai.request_document_id INNER JOIN aics_requests r ON r.id = d.request_id WHERE ai.processed_at IS NOT NULL AND DATE(ai.processed_at) BETWEEN ? AND ? AND ' . aics_ai_gate_visible_sql('r'));
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $rows = $stmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $metrics['reviews'] = count($rows);
    $invoked = 0; $cacheHits = 0; $unavailable = 0; $latencies = [];
    foreach ($rows as $row) {
        $findings = json_decode((string) ($row['findings'] ?? ''), true);
        $external = is_array($findings) && is_array($findings['external_ai'] ?? null) ? $findings['external_ai'] : [];
        $status = (string) ($external['status'] ?? 'not_needed');
        if (in_array($status, ['completed', 'unavailable'], true)) {
            $invoked++;
            $latencies[] = max(0, (int) ($external['elapsed_ms'] ?? 0));
        }
        if (!empty($external['cache_hit'])) $cacheHits++;
        if ($status === 'unavailable') $unavailable++;
    }
    $total = count($rows);
    if ($total > 0) {
        $metrics['invocation_rate'] = round(($invoked / $total) * 100, 1);
        $metrics['cache_hit_rate'] = round(($cacheHits / $total) * 100, 1);
        $metrics['unavailable_rate'] = round(($unavailable / $total) * 100, 1);
    }
    sort($latencies, SORT_NUMERIC);
    if ($latencies !== []) {
        $middle = (count($latencies) - 1) / 2;
        $lower = (int) floor($middle); $upper = (int) ceil($middle);
        $metrics['median_ms'] = (int) round(($latencies[$lower] + $latencies[$upper]) / 2);
        $p95Index = (int) ceil((count($latencies) * 0.95)) - 1;
        $metrics['p95_ms'] = $latencies[max(0, min(count($latencies) - 1, $p95Index))];
    }
    $feedbackStmt = $connection->prepare("SELECT agreement_state, COUNT(*) AS total FROM document_ai_review_feedback WHERE DATE(created_at) BETWEEN ? AND ? AND agreement_state IN ('agreed','overridden') GROUP BY agreement_state");
    $feedbackStmt->bind_param('ss', $dateFrom, $dateTo);
    $feedbackStmt->execute();
    $feedbackRows = $feedbackStmt->get_result()?->fetch_all(MYSQLI_ASSOC) ?: [];
    $feedbackStmt->close();
    $agreed = 0; $overridden = 0;
    foreach ($feedbackRows as $row) {
        if (($row['agreement_state'] ?? '') === 'agreed') $agreed += (int) $row['total'];
        if (($row['agreement_state'] ?? '') === 'overridden') $overridden += (int) $row['total'];
    }
    $metrics['feedback'] = $agreed + $overridden;
    if ($metrics['feedback'] > 0) {
        $metrics['agreement_rate'] = round(($agreed / $metrics['feedback']) * 100, 1);
        $metrics['override_rate'] = round(($overridden / $metrics['feedback']) * 100, 1);
    }
    return $metrics;
}

/**
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_notification_logs(mysqli $connection, int $limit = 12): array
{
    $rows = [];
    $sql = <<<SQL
        SELECT
            n.notification_stage,
            n.message_text,
            n.sent_at,
            r.reference_no,
            r.beneficiary_complete_name,
            CONCAT_WS(' ', sp.first_name, sp.last_name) AS sent_by
        FROM aics_request_notifications n
        INNER JOIN aics_requests r ON r.id = n.request_id
        LEFT JOIN aics_staff_profiles sp ON sp.account_id = n.sent_by_account_id
        ORDER BY n.sent_at DESC, n.id DESC
        LIMIT ?
    SQL;
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $rows[] = [
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'beneficiary_name' => (string) ($row['beneficiary_complete_name'] ?? ''),
            'stage' => (string) ($row['notification_stage'] ?? 'General Update'),
            'message' => (string) ($row['message_text'] ?? ''),
            'sent_at_label' => pswdo_datetime_label((string) ($row['sent_at'] ?? '')),
            'sent_by' => trim((string) ($row['sent_by'] ?? '')),
        ];
    }
    $stmt->close();
    return $rows;
}

/** @return array<int, array<string, string>> */
function pswdo_fetch_mswdo_notification_history(mysqli $connection, int $limit = 12): array
{
    $stmt = $connection->prepare("SELECT municipality, delivery_mode, delivery_status, notification_template, sent_at FROM aics_request_notifications WHERE channel = 'mswdo_notification' ORDER BY sent_at DESC, id DESC LIMIT ?");
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) break;
        $rows[] = [
            'municipality' => (string) ($row['municipality'] ?? 'Unknown'),
            'delivery_mode' => (string) ($row['delivery_mode'] ?? 'simulate'),
            'delivery_status' => (string) ($row['delivery_status'] ?? 'failed'),
            'template' => (string) ($row['notification_template'] ?? ''),
            'created_at_label' => pswdo_datetime_label((string) ($row['sent_at'] ?? '')),
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * Finalized release activity uses the same canonical released definition as
 * the Release KPI. Notification rows are supplementary, so older releases
 * remain visible even when they predate MSWDO simulation.
 *
 * @return array<int, array<string, string>>
 */
function pswdo_fetch_release_notification_history(mysqli $connection, int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    $sql = <<<SQL
        SELECT
            r.id AS request_id, r.reference_no, r.beneficiary_complete_name, r.municipality,
            at.assistance_name,
            COALESCE(NULLIF(rs.release_status, ''), 'released') AS release_status,
            COALESCE(
                (SELECT MAX(h.changed_at)
                 FROM aics_request_status_history h
                 INNER JOIN aics_request_statuses hs ON hs.id = h.status_id
                 WHERE h.request_id = r.id AND hs.status_code = 'released'),
                CASE WHEN rs.release_status = 'released' THEN NULLIF(rs.updated_at, '0000-00-00 00:00:00') END,
                r.updated_at
            ) AS released_at,
            (SELECT COALESCE(NULLIF(n.delivery_status, ''), 'sent') FROM aics_request_notifications n
             WHERE n.request_id = r.id AND (n.channel = 'applicant_sms' OR n.notification_stage = 'Release Schedule SMS')
             ORDER BY n.sent_at DESC, n.id DESC LIMIT 1) AS sms_status,
            (SELECT n.delivery_status FROM aics_request_notifications n
             WHERE n.request_id = r.id AND n.channel = 'mswdo_notification'
             ORDER BY n.sent_at DESC, n.id DESC LIMIT 1) AS mswdo_status,
            (SELECT n.notification_template FROM aics_request_notifications n
             WHERE n.request_id = r.id AND n.channel = 'mswdo_notification'
             ORDER BY n.sent_at DESC, n.id DESC LIMIT 1) AS mswdo_template
        FROM aics_requests r
        LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
        LEFT JOIN (
            SELECT request_id, MIN(assistance_type_id) AS assistance_type_id
            FROM aics_request_assistance GROUP BY request_id
        ) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        WHERE LOWER(TRIM(r.request_status)) IN ('released', 'distributed', 'completed', 'closed')
           OR rs.release_status = 'released'
           OR LOWER(TRIM(COALESCE(r.claim_status, 'unclaimed'))) = 'claimed'
        ORDER BY released_at DESC, r.id DESC
        LIMIT ?
    SQL;
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) break;
        $sms = strtolower(trim((string) ($row['sms_status'] ?? '')));
        // Legacy SMS rows were written only after a successful provider call.
        if ($sms === '') $sms = 'not_recorded';
        $mswdo = strtolower(trim((string) ($row['mswdo_status'] ?? '')));
        if ($mswdo === '') $mswdo = 'not_recorded';
        $rows[] = [
            'request_id' => (string) ((int) ($row['request_id'] ?? 0)),
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'beneficiary_name' => trim((string) ($row['beneficiary_complete_name'] ?? '')) ?: 'Applicant',
            'municipality' => trim((string) ($row['municipality'] ?? '')) ?: 'Not recorded',
            'assistance' => trim((string) ($row['assistance_name'] ?? '')) ?: 'Assistance Request',
            'release_status' => 'released',
            'sms_status' => $sms,
            'mswdo_status' => $mswdo,
            'mswdo_template' => (string) ($row['mswdo_template'] ?? ''),
            'released_at_label' => pswdo_datetime_label((string) ($row['released_at'] ?? '')),
        ];
    }
    $stmt->close();
    return $rows;
}

/**
 * @return array<int, array<string, mixed>>
 */
function pswdo_fetch_top_priority_cases(mysqli $connection, int $limit = 5): array
{
    $rows = [];
    $visibleSql = aics_ai_gate_visible_sql('r');
    $sql = <<<SQL
        SELECT
            r.reference_no,
            r.beneficiary_complete_name,
            p.priority_level,
            p.urgency_indicator,
            p.priority_score,
            p.priority_rank,
            at.assistance_name,
            r.request_status
        FROM aics_request_prioritization p
        INNER JOIN aics_requests r ON r.id = p.request_id
        LEFT JOIN (
            SELECT request_id, MIN(assistance_type_id) AS assistance_type_id
            FROM aics_request_assistance
            GROUP BY request_id
        ) ra ON ra.request_id = r.id
        LEFT JOIN aics_assistance_types at ON at.id = ra.assistance_type_id
        WHERE p.priority_rank IS NOT NULL AND $visibleSql
        ORDER BY p.priority_rank ASC
        LIMIT ?
    SQL;
    $stmt = $connection->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $rows[] = [
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'beneficiary_name' => (string) ($row['beneficiary_complete_name'] ?? ''),
            'priority_level' => (string) ($row['priority_level'] ?? 'Normal Priority'),
            'urgency_indicator' => (string) ($row['urgency_indicator'] ?? ''),
            'priority_indicator' => aics_smart_priority_analysis_enabled()
                ? pswdo_priority_display_label((string) ($row['priority_level'] ?? ''), (string) ($row['urgency_indicator'] ?? ''))
                : (str_starts_with(strtolower(trim((string) ($row['priority_level'] ?? ''))), 'high priority') ? 'High' : 'Normal'),
            'priority_chip_class' => aics_smart_priority_analysis_enabled()
                ? pswdo_priority_badge_meta((string) ($row['priority_level'] ?? ''), (string) ($row['urgency_indicator'] ?? ''))['class']
                : (str_starts_with(strtolower(trim((string) ($row['priority_level'] ?? ''))), 'high priority') ? 'priority-chip-high' : 'priority-chip-normal'),
            'priority_score' => (int) ($row['priority_score'] ?? 0),
            'priority_rank' => (int) ($row['priority_rank'] ?? 0),
            'assistance_label' => trim((string) ($row['assistance_name'] ?? '')) !== '' ? (string) $row['assistance_name'] : 'Assistance Request',
            'status_code' => pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending')),
        ];
    }
    $stmt->close();

    return $rows;
}

function pswdo_assistance_label(string $assistanceCode): string
{
    $lookup = [
        'medical' => 'Medical',
        'burial' => 'Burial',
        'disaster_victims' => 'Disaster Victims',
        'special_cases' => 'Special Cases',
        'educational_basic' => 'Educational Assistance — Basic Education',
        'high_inflation_affected' => 'Persons Affected by High Inflation Rate',
    ];
    $code = strtolower(trim($assistanceCode));
    if ($code === '') {
        return 'Assistance Request';
    }
    return $lookup[$code] ?? ucwords(str_replace('_', ' ', $code));
}

function pswdo_document_label(string $documentCode): string
{
    $documentCode = aics_normalize_document_type($documentCode);
    $lookup = ['certificate_of_indigency' => 'Certificate of Indigency'];
    foreach (aics_document_requirements() as $requirements) foreach ($requirements as $requirement) $lookup[$requirement['code']] = $requirement['label'];
    $code = strtolower(trim($documentCode));
    if ($code === '') {
        return 'Supporting Document';
    }
    return $lookup[$code] ?? ucwords(str_replace('_', ' ', $code));
}

function pswdo_status_tone(string $statusCode): string
{
    return match ($statusCode) {
        'pending_review' => 'pending',
        'incomplete_requirements' => 'warning',
        'for_interview' => 'info',
        'under_review' => 'info',
        'approved' => 'success',
        'released' => 'success',
        'not_approved', 'cancelled' => 'danger',
        default => 'pending',
    };
}

/**
 * @return array<int, string>
 */
function pswdo_filter_status_values(string $statusCode): array
{
    $normalized = pswdo_normalize_filter_status($statusCode);

    return match ($normalized) {
        'pending_review' => ['pending', 'for_review', 'for validation', 'for_validation'],
        'incomplete_requirements' => ['incomplete_requirements', 'incomplete requirements'],
        'for_interview' => ['for_interview', 'for interview', 'for interview/further screening', 'for interview / further screening'],
        'under_review' => ['under_review', 'under review', 'under assessment', 'under_assessment', 'assessment', 'in_review'],
        'approved' => ['approved', 'validated', 'for_release', 'for release'],
        'not_approved' => ['not_approved', 'not approved', 'rejected', 'denied'],
        'released' => ['released', 'distributed', 'completed', 'closed'],
        'cancelled' => ['cancelled', 'canceled'],
        default => [],
    };
}

function pswdo_normalize_filter_status(string $status): string
{
    $normalized = strtolower(trim($status));
    $allowed = array_merge(['all'], array_keys(pswdo_status_labels()));
    return in_array($normalized, $allowed, true) ? $normalized : 'all';
}

function pswdo_titleize(string $value): string
{
    $clean = trim($value);
    if ($clean === '') {
        return '';
    }
    return ucwords(str_replace('_', ' ', strtolower($clean)));
}


function pswdo_client_categories_label(string $json): string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || count($decoded) === 0) {
        return 'None';
    }

    $labels = [];
    foreach ($decoded as $item) {
        $value = strtolower(trim((string) $item));
        if ($value === '') {
            continue;
        }
        $labels[] = match ($value) {
            'senior_citizen' => 'Senior Citizen',
            'solo_parent' => 'Solo Parent',
            'pwd' => 'PWD',
            'ips' => 'IPS',
            default => pswdo_titleize($value),
        };
    }

    if (count($labels) === 0) {
        return 'None';
    }

    $labels = array_values(array_unique($labels));
    return implode(', ', $labels);
}

function pswdo_normalize_date_filter(string $dateFilter): string
{
    $normalized = strtolower(trim($dateFilter));
    $allowed = ['all', 'today', 'this_week', 'this_month'];
    return in_array($normalized, $allowed, true) ? $normalized : 'all';
}

/**
 * @return array{0:string,1:string}|null
 */
function pswdo_filter_date_range(string $dateFilter): ?array
{
    $normalized = pswdo_normalize_date_filter($dateFilter);
    if ($normalized === 'all') {
        return null;
    }

    $timezone = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $timezone);
    $start = $now;
    $end = $now;

    if ($normalized === 'today') {
        return [$now->format('Y-m-d'), $now->format('Y-m-d')];
    }

    if ($normalized === 'this_week') {
        $isoDayOfWeek = (int) $now->format('N'); // 1 (Mon) ... 7 (Sun)
        $start = $now->modify('-' . ($isoDayOfWeek - 1) . ' days');
        $end = $start->modify('+6 days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    if ($normalized === 'this_month') {
        $start = $now->modify('first day of this month');
        $end = $now->modify('last day of this month');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    return null;
}

function pswdo_extract_first_assistance_from_json(string $json): string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || count($decoded) === 0) {
        return '';
    }
    return strtolower(trim((string) $decoded[0]));
}

function pswdo_date_label(string $value): string
{
    if ($value === '' || $value === '0000-00-00') {
        return 'Not available';
    }
    try {
        return (new DateTimeImmutable($value))->format('F d, Y');
    } catch (Throwable) {
        return $value;
    }
}

function pswdo_datetime_label(string $value): string
{
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return 'Not available';
    }
    try {
        return (new DateTimeImmutable($value))->format('F d, Y h:i A');
    } catch (Throwable) {
        return $value;
    }
}
