<?php
declare(strict_types=1);

require_once __DIR__ . '/document-requirements.php';

/** Read the same server-only Gemini settings used by the AI worker. */
function aics_smart_priority_setting(string $name, string $default = ''): string
{
    $environment = getenv($name);
    if ($environment !== false) return trim((string) $environment);
    static $files = [];
    $path = (string) (getenv('AICS_EXTERNAL_AI_ENV_FILE') ?: 'C:\\xampp\\aics_private\\aics-ai.env');
    if (!array_key_exists($path, $files)) {
        $files[$path] = [];
        if (is_file($path)) {
            foreach ((array) @file($path, FILE_IGNORE_NEW_LINES) as $line) {
                $line = trim((string) $line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                $key = ltrim($key, "\xEF\xBB\xBF");
                $files[$path][$key] = $value;
            }
        }
    }
    return (string) ($files[$path][$name] ?? $default);
}

/** Default on. Set SMART_PRIORITY_MEDICAL_ANALYSIS=false in the private AI env file for legacy scoring. */
function aics_smart_priority_analysis_enabled(): bool
{
    return !in_array(strtolower(aics_smart_priority_setting('SMART_PRIORITY_MEDICAL_ANALYSIS', 'true')),
        ['0', 'false', 'off', 'no'], true);
}

/** Every Medical Assistance request is analyzed for urgency. */
function aics_smart_priority_qualifies(array $beneficiaryCategories, string $assistanceCode): bool
{
    return strtolower(trim($assistanceCode)) === 'medical';
}

/** Priority without urgent evidence: HIGH for senior citizens and PWD, STANDARD for everyone else. */
function aics_smart_priority_baseline(array $beneficiaryCategories): string
{
    $categories = array_map(static fn ($value): string => strtolower(trim((string) $value)), $beneficiaryCategories);
    return in_array('senior_citizen', $categories, true) || in_array('pwd', $categories, true) ? 'HIGH' : 'STANDARD';
}

function aics_smart_priority_medical_document_type(string $type): ?string
{
    $normalized = aics_normalize_document_type($type);
    if ($normalized === 'prescription') return 'billing_statement';
    return in_array($normalized, ['med_cert', 'billing_statement'], true) ? $normalized : null;
}

/** Keep the existing priority_basis column useful without changing its schema. */
function aics_smart_priority_result_explanation(array $result): string
{
    $reason = substr(trim((string) ($result['reason'] ?? '')), 0, 220);
    $findings = [];
    foreach ((array) ($result['evidence'] ?? []) as $item) {
        if (!is_string($item) || trim($item) === '') continue;
        $findings[] = substr(trim($item), 0, 80);
        if (count($findings) === 3) break;
    }
    $explanation = $reason;
    if ($findings !== []) $explanation .= ($explanation !== '' ? ' ' : '') . 'Findings: ' . implode('; ', $findings) . '.';
    return trim($explanation);
}

/** A separate, read-only decision. The callback sees only medical documents and returns urgency evidence. */
function aics_smart_priority_assess(array $beneficiaryCategories, string $assistanceCode,
                                    array $documents, callable $analyze): array
{
    if (!aics_smart_priority_qualifies($beneficiaryCategories, $assistanceCode)) {
        return ['indicator' => 'STANDARD', 'basis' => 'Smart Priority applies to Medical Assistance only.'];
    }
    $baseline = aics_smart_priority_baseline($beneficiaryCategories);
    $group = $baseline === 'HIGH' ? 'Senior/PWD Medical Assistance' : 'Medical Assistance';
    foreach ($documents as $document) {
        if (is_array($document) && ($document['unavailable'] ?? false) === true &&
            aics_smart_priority_medical_document_type((string) ($document['document_type'] ?? '')) !== null) {
            return ['indicator' => $baseline, 'basis' => 'A medical supporting document is unavailable for complete priority analysis.'];
        }
    }
    $medicalDocuments = array_values(array_filter($documents, static fn ($document): bool =>
        is_array($document) && aics_smart_priority_medical_document_type((string) ($document['document_type'] ?? '')) !== null));
    if ($medicalDocuments === []) {
        return ['indicator' => $baseline, 'basis' => $group . '; no current urgent trigger detected by priority analysis.'];
    }
    try {
        $result = $analyze($medicalDocuments);
    } catch (Throwable) {
        $result = null;
    }
    if (is_array($result) && ($result['urgent'] ?? null) === true) {
        return ['indicator' => 'URGENT', 'basis' => 'Gemini priority analysis found sufficient current medical urgency. ' .
            aics_smart_priority_result_explanation($result)];
    }
    if (is_array($result) && ($result['urgent'] ?? null) === false) {
        return ['indicator' => $baseline, 'basis' => $group . '; no sufficient current urgency evidence. ' .
            aics_smart_priority_result_explanation($result)];
    }
    return ['indicator' => $baseline, 'basis' => 'Smart Priority medical analysis was unavailable; defaulted to ' . $baseline . '.'];
}

/** Values for the existing priority columns; no new schema or workflow state. */
function aics_smart_priority_storage_values(string $indicator): array
{
    return match ($indicator) {
        'URGENT' => ['score' => 2, 'label' => 'URGENT'],
        'HIGH' => ['score' => 1, 'label' => 'HIGH'],
        default => ['score' => 0, 'label' => 'STANDARD'],
    };
}

function aics_smart_priority_gemini_extraction_prompt(): string
{
    return <<<'PROMPT'
You extract medical information from uploaded Medical Assistance document images ONLY for
AICS Smart Priority. Return one structured record for each image, in the same numbered order.
Copy only medical facts visible or clearly supported by each image. Do not invent missing
diagnoses, conditions, procedures, timing, or treatment needs. Use empty arrays when absent.
Do not decide priority or whether the case is urgent during extraction.
Do not verify authenticity, validity, acceptance, document type, or identity.
Do not compare applicant or beneficiary names. Do not change document-verification results.
Do not extract names, ID numbers, addresses, or other identity details.
Capture all visible diagnoses and the nature of disease or disability, symptoms, complications,
functional impairment, physician findings and recommendations, treatment requirements,
procedures, hospitalization or admission, medication instructions, billing services, severity,
current condition, deterioration or stability, treatment timing, historical conditions,
completed procedures, future or elective plans, and any other medical evidence.
Preserve wording that distinguishes CURRENT conditions from PAST history and future plans.
Return structured JSON only.
PROMPT;
}

/** The second stage sees only structured medical facts, never the uploaded images. */
function aics_smart_priority_gemini_prompt(): string
{
    return <<<'PROMPT'
You analyze structured medical information extracted from uploaded documents ONLY for AICS Smart Priority.
Do not verify authenticity, validity, acceptance, document type, readability, or identity.
Do not compare applicant or beneficiary names. Do not change document-verification results.
Evaluate all extracted diagnoses, nature of disease, disability, medical conditions, symptoms,
complications, functional impairment, physician recommendations, treatment requirements,
procedures, hospitalization, prescriptions, billing information, deterioration, and timing.
Consider the extracted medical documents together: a certificate or abstract may describe a condition,
while a bill or prescription may show current treatment or a need for intervention.
Use only the supplied extracted facts. Do not infer a missing diagnosis, current status, or treatment.
Determine whether the CURRENT medical case, taken as a whole, has sufficient severity,
active complications, significant impairment, treatment need, or time sensitivity to justify
URGENT priority and meaningful timely assistance. A documented current neurological deficit,
weakness, inability to walk, breathing difficulty, or other serious functional limitation
can be evidence even without an emergency label. Consider admission, surgery, dialysis,
chemotherapy, transfusion, or other significant intervention in its full clinical context.
A scheduled or required operation or surgery for the current condition is sufficient for urgent=true; a purely elective or cosmetic procedure is not.
Do not require explicit words such as acute, urgent, emergency, or immediate. These words,
a disease name alone, a billing line alone, or a medication name alone do not decide urgency.
PAST history, completed treatment, stable chronic conditions, routine follow-up, and elective
future procedures are not automatically urgent unless current evidence shows a significant need.
If evidence is insufficient or uncertain, set urgent=false. Return JSON with urgent (boolean),
reason (brief explanation), and evidence (array of concise medical findings). Only urgent
determines the priority classification.
PROMPT;
}

function aics_smart_priority_parse_gemini_json(string $raw): ?array
{
    $envelope = json_decode($raw, true);
    if (!is_array($envelope)) return null;
    $parts = $envelope['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) return null;
    $text = '';
    foreach ($parts as $part) {
        if (is_array($part) && !($part['thought'] ?? false)) $text .= (string) ($part['text'] ?? '');
    }
    $data = json_decode($text, true);
    return is_array($data) ? $data : null;
}

/** @return array{urgent:bool,reason:string,evidence:array<int,string>}|null */
function aics_smart_priority_parse_gemini_response(string $raw): ?array
{
    $data = aics_smart_priority_parse_gemini_json($raw);
    if (!is_array($data) || !is_bool($data['urgent'] ?? null)) return null;
    if (!is_string($data['reason'] ?? null) || !is_array($data['evidence'] ?? null) ||
        !array_is_list($data['evidence'])) return null;
    foreach ($data['evidence'] as $item) {
        if (!is_string($item)) return null;
    }
    if ($data['urgent'] && (trim($data['reason']) === '' ||
        !array_filter($data['evidence'], static fn (string $item): bool => trim($item) !== ''))) return null;
    return [
        'urgent' => $data['urgent'],
        'reason' => substr(trim($data['reason']), 0, 240),
        'evidence' => array_map(static fn (string $item): string => substr(trim($item), 0, 160),
            array_slice($data['evidence'], 0, 12)),
    ];
}

/** Never accept a DB path as authority; reconstruct paths inside this request's upload folder. */
function aics_smart_priority_document_file(array $document): ?array
{
    $reference = basename((string) ($document['reference_no'] ?? ''));
    $stored = basename((string) ($document['stored_name'] ?? ''));
    if ($reference === '' || $stored === '' || $reference === '.' || $stored === '.') return null;
    $root = realpath(__DIR__ . '/../Applicants/uploads/' . $reference);
    if ($root === false) return null;
    $file = realpath($root . DIRECTORY_SEPARATOR . $stored);
    if ($file === false || !is_file($file)) return null;
    $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    if (!str_starts_with(str_replace('\\', '/', $file), $prefix)) return null;
    $size = @filesize($file);
    if (!is_int($size) || $size < 1 || $size > 25 * 1024 * 1024) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) return null;
    return ['document_type' => aics_smart_priority_medical_document_type((string) ($document['document_type'] ?? '')),
            'path' => $file, 'mime' => $mime, 'size' => $size];
}

function aics_smart_priority_gemini_settings(): array
{
    $key = '';
    foreach (['AICS_GEMINI_API_KEY', 'AICS_GEMINI_IMAGE_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $name) {
        $key = aics_smart_priority_setting($name);
        if ($key !== '') break;
    }
    $models = array_filter(array_map('trim', explode(',', aics_smart_priority_setting('AICS_GEMINI_MODEL', 'gemini-3.5-flash-lite') . ',' .
        aics_smart_priority_setting('AICS_GEMINI_FALLBACK_MODELS', 'gemini-3.1-flash-lite'))),
        static fn (string $model): bool => preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $model) === 1);
    return ['key' => $key, 'models' => array_values(array_unique($models)),
            'timeout' => max(5, min(60, (int) aics_smart_priority_setting('SMART_PRIORITY_GEMINI_TIMEOUT_SECONDS', '45')))];
}

function aics_smart_priority_gemini_response_schema(): array
{
    return ['type' => 'object', 'properties' => [
        'urgent' => ['type' => 'boolean'], 'reason' => ['type' => 'string'],
        'evidence' => ['type' => 'array', 'items' => ['type' => 'string']],
    ], 'required' => ['urgent', 'reason', 'evidence']];
}

/** Gemini's responseSchema uses uppercase Type enum values on the REST wire. */
function aics_smart_priority_gemini_wire_schema(array $schema): array
{
    $wire = [];
    foreach ($schema as $key => $value) {
        if ($key === 'type' && is_string($value)) {
            $wire[$key] = strtoupper($value);
        } elseif ($key === 'properties' && is_array($value)) {
            $wire[$key] = array_map('aics_smart_priority_gemini_wire_schema', $value);
        } elseif ($key === 'items' && is_array($value)) {
            $wire[$key] = aics_smart_priority_gemini_wire_schema($value);
        } else {
            $wire[$key] = $value;
        }
    }
    return $wire;
}

function aics_smart_priority_extraction_fields(): array
{
    return [
        'diagnoses', 'nature_of_disease_or_disability', 'symptoms_or_impairments', 'complications',
        'functional_impairment', 'physician_findings', 'physician_recommendations', 'treatment_requirements',
        'procedures', 'hospitalization_or_admission', 'medications_or_instructions', 'billing_services',
        'severity_statements', 'current_status', 'timing_statements', 'historical_statements', 'other_medical_evidence',
    ];
}

function aics_smart_priority_gemini_extraction_schema(): array
{
    $properties = ['document_index' => ['type' => 'integer'], 'document_type' => ['type' => 'string']];
    foreach (aics_smart_priority_extraction_fields() as $field) {
        $properties[$field] = ['type' => 'array', 'items' => ['type' => 'string']];
    }
    return ['type' => 'object', 'properties' => [
        'documents' => ['type' => 'array', 'items' => [
            'type' => 'object', 'properties' => $properties, 'required' => array_keys($properties),
        ]],
    ], 'required' => ['documents']];
}

/** @return array{documents:array<int,array<string,mixed>>}|null */
function aics_smart_priority_parse_extraction_response(string $raw, int $expectedCount): ?array
{
    $data = aics_smart_priority_parse_gemini_json($raw);
    $documents = $data['documents'] ?? null;
    if ($expectedCount < 1 || !is_array($documents) || !array_is_list($documents) ||
        count($documents) !== $expectedCount) return null;
    $normalized = [];
    foreach ($documents as $index => $document) {
        if (!is_array($document) || ($document['document_index'] ?? null) !== $index + 1 ||
            !is_string($document['document_type'] ?? null)) return null;
        $record = ['document_index' => $index + 1,
            'document_type' => substr(trim($document['document_type']), 0, 120)];
        $hasMedicalFacts = false;
        foreach (aics_smart_priority_extraction_fields() as $field) {
            $values = $document[$field] ?? null;
            if (!is_array($values) || !array_is_list($values)) return null;
            $record[$field] = [];
            foreach ($values as $value) {
                if (!is_string($value)) return null;
                $value = substr(trim($value), 0, 500);
                if ($value !== '') {
                    $record[$field][] = $value;
                    $hasMedicalFacts = true;
                }
            }
        }
        if (!$hasMedicalFacts) return null;
        $normalized[] = $record;
    }
    return ['documents' => $normalized];
}

function aics_smart_priority_gemini_extraction_request_text(): string
{
    return 'Extract visible medical information separately from each numbered medical document.';
}

function aics_smart_priority_gemini_extraction_body(array $documents): ?string
{
    $parts = [['text' => aics_smart_priority_gemini_extraction_request_text() . ' Today in the Philippines: ' .
        (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') . '.']];
    foreach ($documents as $index => $document) {
        if (!is_array($document) || aics_smart_priority_medical_document_type((string) ($document['document_type'] ?? '')) === null ||
            !is_string($document['bytes'] ?? null) || $document['bytes'] === '') return null;
        $parts[] = ['text' => 'Medical document ' . ($index + 1) . ' slot: ' .
            aics_document_requirement_label((string) $document['document_type']) . '.'];
        $parts[] = ['inline_data' => ['mime_type' => $document['mime'], 'data' => base64_encode($document['bytes'])]];
    }
    if (count($parts) === 1) return null;
    $body = json_encode([
        'systemInstruction' => ['parts' => [['text' => aics_smart_priority_gemini_extraction_prompt()]]],
        'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json',
            'responseSchema' => aics_smart_priority_gemini_wire_schema(aics_smart_priority_gemini_extraction_schema())],
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($body) ? $body : null;
}

function aics_smart_priority_gemini_analysis_request_text(): string
{
    return 'Analyze the following structured medical data together for CURRENT medical urgency. ' .
        'Only the urgent boolean affects Smart Priority. Medical data: ';
}

function aics_smart_priority_gemini_analysis_body(array $extractedDocuments): ?string
{
    if ($extractedDocuments === []) return null;
    $medicalData = json_encode(['documents' => $extractedDocuments], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($medicalData)) return null;
    $body = json_encode([
        'systemInstruction' => ['parts' => [['text' => aics_smart_priority_gemini_prompt()]]],
        'contents' => [['role' => 'user', 'parts' => [[
            'text' => aics_smart_priority_gemini_analysis_request_text() . $medicalData,
        ]]]],
        'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json',
            'responseSchema' => aics_smart_priority_gemini_wire_schema(aics_smart_priority_gemini_response_schema())],
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($body) ? $body : null;
}

function aics_smart_priority_cache_contract(): array
{
    return [
        'prompt' => implode("\n\n", [aics_smart_priority_gemini_extraction_prompt(),
            aics_smart_priority_gemini_extraction_request_text(), aics_smart_priority_gemini_prompt(),
            aics_smart_priority_gemini_analysis_request_text()]),
        'schema' => ['extraction' => aics_smart_priority_gemini_wire_schema(aics_smart_priority_gemini_extraction_schema()),
            'analysis' => aics_smart_priority_gemini_wire_schema(aics_smart_priority_gemini_response_schema())],
    ];
}

/** Dedicated Smart Priority Gemini transport. No verification result is read or written. */
function aics_smart_priority_remaining_timeout(int $perAttempt, float $deadline, ?float $now = null): int
{
    return max(0, min($perAttempt, (int) floor($deadline - ($now ?? microtime(true)))));
}

function aics_smart_priority_gemini_request(string $body, callable $parse, ?float $deadline = null): ?array
{
    $settings = aics_smart_priority_gemini_settings();
    if ($settings['key'] === '' || !function_exists('curl_init')) return null;
    $deadline ??= microtime(true) + 100.0;
    foreach ($settings['models'] as $model) {
        $attemptTimeout = aics_smart_priority_remaining_timeout($settings['timeout'], $deadline);
        if ($attemptTimeout === 0) break;
        $handle = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent');
        if ($handle === false) return null;
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'x-goog-api-key: ' . $settings['key']],
            CURLOPT_CONNECTTIMEOUT => min(5, $attemptTimeout), CURLOPT_TIMEOUT => $attemptTimeout, CURLOPT_MAXREDIRS => 0]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if (is_string($raw) && $status === 200) {
            $result = $parse($raw);
            if ($result !== null) return $result;
        }
        if (in_array($status, [400, 401, 403], true)) break;
    }
    return null;
}

/** Image extraction, then analysis of the structured medical facts only. */
function aics_smart_priority_gemini_analyze(array $documents, ?callable $request = null): ?array
{
    if ($documents === []) return null;
    // Both Gemini stages and their model fallbacks share one request budget.
    $deadline = microtime(true) + 100.0;
    $inputs = [];
    foreach ($documents as $document) {
        if (!is_array($document) || aics_smart_priority_medical_document_type((string) ($document['document_type'] ?? '')) === null) return null;
        $bytes = @file_get_contents((string) ($document['path'] ?? ''));
        if (!is_string($bytes) || $bytes === '') return null;
        $inputs[] = $document + ['bytes' => $bytes];
    }
    $extractionBody = aics_smart_priority_gemini_extraction_body($inputs);
    if ($extractionBody === null) return null;
    $send = $request ?? static fn (string $body, callable $parse): ?array =>
        aics_smart_priority_gemini_request($body, $parse, $deadline);
    try {
        $extraction = $send($extractionBody,
            static fn (string $raw): ?array => aics_smart_priority_parse_extraction_response($raw, count($inputs)));
        if (!is_array($extraction) || !is_array($extraction['documents'] ?? null)) return null;
        $analysisBody = aics_smart_priority_gemini_analysis_body($extraction['documents']);
        if ($analysisBody === null) return null;
        $result = $send($analysisBody, 'aics_smart_priority_parse_gemini_response');
        return is_array($result) ? $result : null;
    } catch (Throwable) {
        return null;
    }
}

/** Cache decisions for these medical bytes, prompt, schema, models, and Philippine date. */
function aics_smart_priority_cache_fingerprint(array $documents, string $prompt, array $schema): ?string
{
    $files = [];
    foreach ($documents as $document) {
        $fileDigest = @hash_file('sha256', (string) ($document['path'] ?? ''));
        if (!is_string($fileDigest)) return null;
        $files[] = [(string) $document['path'], $fileDigest, (string) $document['document_type']];
    }
    $input = json_encode([
        'files' => $files,
        'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
        'models' => aics_smart_priority_gemini_settings()['models'],
        'prompt' => $prompt,
        'schema' => $schema,
        'revision' => 'priority-v4-all-medical-operations',
    ]);
    return is_string($input) ? hash('sha256', $input) : null;
}

/** Failed calls retry after five minutes. */
function aics_smart_priority_cached_analyze(array $documents): ?array
{
    $root = rtrim(aics_smart_priority_setting('AICS_EXTERNAL_AI_STATE_ROOT', 'C:\\xampp\\aics_private\\external_ai'), '\\/') . DIRECTORY_SEPARATOR . 'smart_priority';
    $project = realpath(__DIR__ . '/../..');
    if (!is_dir($root)) @mkdir($root, 0770, true);
    $resolved = realpath($root);
    $normalizedRoot = strtolower(str_replace('\\', '/', (string) $resolved));
    $normalizedProject = strtolower(rtrim(str_replace('\\', '/', (string) $project), '/'));
    if ($resolved === false || ($project !== false && ($normalizedRoot === $normalizedProject ||
        str_starts_with($normalizedRoot, $normalizedProject . '/')))) return aics_smart_priority_gemini_analyze($documents);
    $contract = aics_smart_priority_cache_contract();
    $fingerprint = aics_smart_priority_cache_fingerprint($documents, $contract['prompt'], $contract['schema']);
    if ($fingerprint === null) return aics_smart_priority_gemini_analyze($documents);
    $cache = $resolved . DIRECTORY_SEPARATOR . $fingerprint . '.json';
    $stored = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    if (is_array($stored) && (int) ($stored['expires'] ?? 0) > time()) return $stored['result'] ?? null;
    $result = aics_smart_priority_gemini_analyze($documents);
    $payload = json_encode(['expires' => time() + ($result === null ? 300 : 86400), 'result' => $result]);
    $temporary = @tempnam($resolved, 'priority-');
    if ($temporary !== false && is_string($payload)) {
        @file_put_contents($temporary, $payload, LOCK_EX);
        if (!@rename($temporary, $cache)) @unlink($temporary);
    }
    return $result;
}

/** Query only already-uploaded medical slots; the Valid ID is never selected. */
function aics_smart_priority_for_request(mysqli $connection, int $requestId, array $beneficiaryCategories,
                                         string $assistanceCode): array
{
    if (!aics_smart_priority_qualifies($beneficiaryCategories, $assistanceCode)) {
        return ['indicator' => 'STANDARD', 'basis' => 'Smart Priority applies to Medical Assistance only.'];
    }
    $baseline = aics_smart_priority_baseline($beneficiaryCategories);
    $statement = null;
    try {
        $statement = $connection->prepare("SELECT d.document_type, d.stored_name, r.reference_no FROM aics_request_documents d INNER JOIN aics_requests r ON r.id = d.request_id WHERE d.request_id = ? AND d.document_type IN ('med_cert', 'medical_certificate', 'medical_abstract', 'medical_abstract_certificate', 'billing_statement', 'billing_statement_or_prescription', 'prescription')");
        if (!$statement) return ['indicator' => $baseline, 'basis' => 'Medical supporting documents are unavailable for priority analysis.'];
        $statement->bind_param('i', $requestId);
        $statement->execute();
        $rows = $statement->get_result();
        $documents = [];
        while ($row = $rows?->fetch_assoc()) {
            $file = aics_smart_priority_document_file($row);
            $documents[] = $file ?? ['document_type' => (string) ($row['document_type'] ?? ''), 'unavailable' => true];
        }
    } catch (Throwable) {
        return ['indicator' => $baseline, 'basis' => 'Medical supporting documents are unavailable for priority analysis.'];
    } finally {
        if ($statement instanceof mysqli_stmt) $statement->close();
    }
    return aics_smart_priority_assess($beneficiaryCategories, $assistanceCode, $documents, 'aics_smart_priority_cached_analyze');
}
