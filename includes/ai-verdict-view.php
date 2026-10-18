<?php
declare(strict_types=1);

/**
 * Staff-facing view model for the AI document verdict card.
 *
 * The five checks follow the capstone definition of AI-Enhanced Document
 * Verification: incomplete, inconsistent, unreadable, altered, or potentially
 * tampered. Values are plain strings; the template escapes them on output.
 */

const AICS_AI_CHECK_ORDER = [
    'complete' => 'Complete',
    'consistent' => 'Consistent',
    'readable' => 'Readable',
    'unaltered' => 'No visible alterations',
    'untampered' => 'No signs of tampering',
];

function aics_ai_state_icon(string $state): string
{
    return match ($state) {
        'pass' => 'fa-circle-check',
        'review' => 'fa-triangle-exclamation',
        'fail' => 'fa-circle-xmark',
        'info' => 'fa-circle-info',
        default => 'fa-circle-minus',
    };
}

function aics_ai_status_tone(string $status): string
{
    return match (strtolower(trim($status))) {
        'likely_valid', 'completed' => 'success',
        'needs_staff_review' => 'warning',
        'needs_reupload', 'flagged_inconsistency' => 'danger',
        default => 'neutral',
    };
}

function aics_ai_status_label(string $status): string
{
    return match (strtolower(trim($status))) {
        'likely_valid', 'completed' => 'Looks good',
        'needs_staff_review' => 'Needs staff review',
        'needs_reupload' => 'Replace document',
        'flagged_inconsistency' => 'Flagged for review',
        'failed' => 'AI check unavailable',
        default => 'Review pending',
    };
}

function aics_ai_friendly_value(string $value): string
{
    $value = trim($value);
    if ($value === '') return '—';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $date->format('F j, Y') : $value;
}

/** @param array<string,mixed> $item */
function aics_ai_check_view(string $key, array $item): array
{
    $state = strtolower(trim((string) ($item['state'] ?? 'unavailable')));
    if (!in_array($state, ['pass', 'review', 'fail', 'unavailable'], true)) $state = 'unavailable';
    $missing = array_values(array_filter(array_map(
        static fn ($value): string => is_scalar($value) ? trim((string) $value) : '',
        is_array($item['missing'] ?? null) ? $item['missing'] : []
    ), static fn (string $value): bool => $value !== ''));
    return [
        'key' => $key,
        'title' => AICS_AI_CHECK_ORDER[$key],
        'state' => $state,
        'label' => trim((string) ($item['label'] ?? '')) ?: ($state === 'unavailable' ? 'Not checked' : ucfirst($state)),
        'reason' => trim((string) ($item['reason'] ?? $item['explanation'] ?? '')),
        'missing' => array_slice($missing, 0, 8),
        'icon' => aics_ai_state_icon($state),
    ];
}

/** Format EXIF ("2026:09:20 10:14:59") or PDF ("D:20260920101500+08'00'") timestamps. */
function aics_ai_metadata_date(string $value): string
{
    $digits = preg_replace('/\D/', '', $value) ?? '';
    if (strlen($digits) >= 12) {
        $date = DateTimeImmutable::createFromFormat('!YmdHi', substr($digits, 0, 12));
        if ($date instanceof DateTimeImmutable && $date->format('YmdHi') === substr($digits, 0, 12)) return $date->format('F j, Y g:i A');
    }
    return trim($value);
}

/**
 * File metadata facts stored by the worker, as label/value rows. Metadata is
 * weak evidence: it is shown for context and never changes the verdict.
 *
 * @param array<string,mixed> $file findings.tamper_detection.auxiliary.file
 * @return array{rows:array<int,array{label:string,value:string}>,note:string}
 */
function aics_ai_metadata_view(array $file): array
{
    $text = static fn (string $key): string => is_scalar($file[$key] ?? null) ? trim((string) $file[$key]) : '';
    $none = 'None recorded';
    $rows = [];
    $isPdf = is_array($file['pdf_signature'] ?? null) || $text('creator') !== '';
    if ($isPdf) {
        if ($text('creation_date') !== '') $rows[] = ['label' => 'Created', 'value' => aics_ai_metadata_date($text('creation_date'))];
        if ($text('modification_date') !== '') $rows[] = ['label' => 'Last modified', 'value' => aics_ai_metadata_date($text('modification_date'))];
        $rows[] = ['label' => 'Created with', 'value' => $text('creator') !== '' ? $text('creator') : $none];
        if ($text('editing_software') !== '') $rows[] = ['label' => 'PDF producer', 'value' => $text('editing_software')];
        $signature = (string) ($file['pdf_signature']['state'] ?? '');
        if ($signature !== '') $rows[] = ['label' => 'Digital signature', 'value' => match ($signature) {
            'unsigned' => 'Not digitally signed',
            'intact' => 'Signed; content unchanged since signing (signer not verified)',
            'changed_after_signing' => 'Changed after it was signed',
            'invalid_signature' => 'Signature did not verify',
            'invalid_structure' => 'Signature data is damaged',
            default => 'Could not be checked',
        }];
        $note = 'PDF details come from the file itself and can be edited, so treat them as supporting information only.';
        return ['rows' => $rows, 'note' => $note];
    }
    if ($text('image_format') === '' && $text('pixel_size') === '') return ['rows' => [], 'note' => ''];
    $rows[] = ['label' => 'File', 'value' => implode(' · ', array_filter([$text('image_format'), $text('pixel_size') !== '' ? $text('pixel_size') . ' pixels' : '']))];
    $rows[] = ['label' => 'Camera', 'value' => $text('camera') !== '' ? $text('camera') : $none];
    $rows[] = ['label' => 'Date taken', 'value' => $text('date_taken') !== '' ? aics_ai_metadata_date($text('date_taken')) : $none];
    $savedWith = $text('editing_software');
    if (($file['saved_with_adobe'] ?? false) === true) $savedWith = $savedWith !== '' ? $savedWith . ' (Adobe save marker)' : 'Adobe software (save marker in file)';
    $rows[] = ['label' => 'Saved with', 'value' => $savedWith !== '' ? $savedWith : $none];
    $note = ($file['camera_metadata'] ?? '') === 'missing'
        ? 'No camera information. The file may be a screenshot, a download, a scan, or a photo re-saved by an app. Missing metadata is common and does not show alteration by itself.'
        : 'Camera information is present. Metadata can be edited, so treat it as supporting information only.';
    return ['rows' => $rows, 'note' => $note];
}

/** Build five checks from the four assessments stored by earlier reviews. */
function aics_ai_legacy_checks(array $assessments): array
{
    $rank = ['pass' => 0, 'not_applicable' => 0, 'unavailable' => 1, 'review' => 2, 'fail' => 3];
    $item = static fn (string $key): array => is_array($assessments[$key] ?? null) ? $assessments[$key] : [];
    $consistent = $item('document_match');
    $applicant = $item('applicant_match');
    if (($rank[$applicant['state'] ?? 'unavailable'] ?? 1) > ($rank[$consistent['state'] ?? 'unavailable'] ?? 1)) $consistent = $applicant;
    $notChecked = ['state' => 'unavailable', 'label' => 'Not checked', 'reason' => 'This earlier review did not include this check.'];
    return [
        'complete' => $notChecked,
        'consistent' => $consistent,
        'readable' => $item('readability'),
        'unaltered' => $notChecked,
        'untampered' => $item('alteration_signal'),
    ];
}

/**
 * @param array<string,mixed> $findings Decoded document_ai_reviews.findings
 * @return array<string,mixed>
 */
function aics_ai_verdict_view(array $findings, string $status): array
{
    $status = strtolower(trim($status));
    $verdict = is_array($findings['verdict'] ?? null) ? $findings['verdict'] : [];
    $hasLegacy = is_array($findings['assessments'] ?? null) && $findings['assessments'] !== [];
    if (in_array($status, ['processing', ''], true) || ($verdict === [] && !$hasLegacy)) {
        return ['available' => false];
    }

    $source = in_array(($verdict['source'] ?? ''), ['gemini', 'local'], true) ? (string) $verdict['source'] : 'legacy';
    $rawChecks = $source === 'legacy'
        ? aics_ai_legacy_checks($findings['assessments'])
        : (is_array($findings['thesis_checks'] ?? null) ? $findings['thesis_checks'] : []);
    $checks = [];
    foreach (array_keys(AICS_AI_CHECK_ORDER) as $key) {
        $checks[] = aics_ai_check_view($key, is_array($rawChecks[$key] ?? null) ? $rawChecks[$key] : []);
    }

    $tone = (string) ($verdict['tone'] ?? '');
    if (!in_array($tone, ['success', 'warning', 'danger', 'neutral'], true)) $tone = aics_ai_status_tone($status);
    $reasons = array_values(array_filter(array_map('strval', is_array($findings['reasons'] ?? null) ? $findings['reasons'] : [])));
    $headline = trim((string) ($verdict['headline'] ?? '')) ?: aics_ai_status_label($status);
    $passedCount = count(array_filter($checks, static fn (array $item): bool => $item['state'] === 'pass'));
    $sourceLabel = match ($source) {
        'gemini' => 'Checked by AICS AI · ' . intdiv($passedCount * 100, count(AICS_AI_CHECK_ORDER)) . '% of checks passed',
        'local' => 'Basic check only — AICS AI did not run',
        default => 'Earlier review (local OCR)',
    };

    $consistentState = $checks[1]['state'];
    $required = trim((string) ($verdict['required_label'] ?? ''));
    $detected = trim((string) ($verdict['detected_label'] ?? ''));
    $typeCheck = is_array($findings['checks']['document_type'] ?? null) ? $findings['checks']['document_type'] : [];
    $typeDecision = (string) ($typeCheck['classification']['decision'] ?? '');
    $typeState = match ($typeDecision) { 'match' => 'pass', 'wrong_slot' => 'fail', '' => ($detected === '' ? 'unavailable' : 'review'), default => 'review' };
    if ($source === 'gemini' && $typeDecision === '') $typeState = $consistentState;

    $rows = [];
    foreach (array_slice(is_array($findings['field_comparison'] ?? null) ? $findings['field_comparison'] : [], 0, 8) as $row) {
        if (!is_array($row)) continue;
        $rowState = (string) ($row['state'] ?? 'info');
        if (!in_array($rowState, ['pass', 'review', 'fail', 'info', 'not_compared'], true)) $rowState = 'info';
        $rows[] = [
            'label' => trim((string) ($row['label'] ?? 'Detail')),
            'document' => aics_ai_friendly_value((string) ($row['document'] ?? '')),
            'application' => aics_ai_friendly_value((string) ($row['application'] ?? '')),
            'state' => $rowState,
            'note' => trim((string) ($row['note'] ?? '')),
            'icon' => aics_ai_state_icon($rowState === 'not_compared' ? 'unavailable' : $rowState),
        ];
    }

    return [
        'available' => true,
        'source' => $source,
        'tone' => $tone,
        'status_label' => aics_ai_status_label($status),
        'headline' => $headline,
        'reason' => trim((string) ($verdict['reason'] ?? '')) ?: ($reasons[0] ?? 'The automated checks completed.'),
        'action' => trim((string) ($verdict['action'] ?? '')) ?: 'Compare the original document with the application before deciding.',
        'notice' => trim((string) ($verdict['notice'] ?? '')),
        'source_label' => $sourceLabel,
        'checks' => $checks,
        'passed_count' => $passedCount,
        'type' => ['required' => $required, 'detected' => $detected !== '' ? $detected : 'Not identified',
            'title' => trim((string) ($verdict['document_title'] ?? '')), 'state' => $typeState, 'icon' => aics_ai_state_icon($typeState)],
        'rows' => $rows,
        'metadata' => aics_ai_metadata_view(is_array($findings['tamper_detection']['auxiliary']['file'] ?? null) ? $findings['tamper_detection']['auxiliary']['file'] : []),
    ];
}
