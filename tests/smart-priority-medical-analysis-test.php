<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/smart-priority-medical-analysis.php';
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=true');

function smart_priority_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$calls = 0;
$lastDocuments = [];
$analyze = static function (array $documents) use (&$calls, &$lastDocuments): ?array {
    $calls++;
    $lastDocuments = $documents;
    $contents = array_column($documents, 'content');
    if (in_array('API failure', $contents, true)) return null;
    $urgent = in_array('Patient requires urgent surgery.', $contents, true)
        || in_array('ICU admission due to critical condition requiring immediate care.', $contents, true)
        || in_array('STAT - administer immediately due to emergency condition.', $contents, true)
        || in_array('Patient requires surgery for current condition.', $contents, true)
        || (in_array('Cerebrovascular Accident with significant right-sided weakness.', $contents, true)
            && in_array('Physician recommends continued medical intervention.', $contents, true));
    return ['urgent' => $urgent, 'reason' => $urgent ? 'Current medical need.' : 'No sufficient current medical need.', 'evidence' => $contents];
};
$document = static fn (string $content, string $type = 'med_cert'): array => ['document_type' => $type, 'content' => $content];
$assess = static fn (array $categories, string $assistance, array $documents): array =>
    aics_smart_priority_assess($categories, $assistance, $documents, $analyze);

smart_priority_assert($assess(['youth'], 'educational_basic', [])['indicator'] === 'STANDARD', 'Non-senior educational must be standard.');
smart_priority_assert($assess(['senior_citizen'], 'educational_basic', [])['indicator'] === 'STANDARD', 'Senior educational must be standard.');
smart_priority_assert($calls === 0, 'Gemini must not be called outside Medical Assistance.');
smart_priority_assert($assess(['youth'], 'medical', [$document('Patient requires urgent surgery.')])['indicator'] === 'URGENT', 'Non-senior medical with current urgency must be urgent.');
smart_priority_assert($calls === 1, 'Every Medical Assistance request must be analyzed.');
smart_priority_assert($assess(['youth'], 'medical', [$document('Patient is stable and advised routine follow-up.')])['indicator'] === 'STANDARD', 'Non-senior medical without urgency must be standard.');
smart_priority_assert($assess(['youth'], 'medical', [$document('API failure')])['indicator'] === 'STANDARD', 'Non-senior medical keeps its standard baseline when analysis is unavailable.');
smart_priority_assert($assess(['youth'], 'medical', [])['indicator'] === 'STANDARD', 'Non-senior medical without medical documents must be standard.');
smart_priority_assert($assess(['senior_citizen'], 'medical', [$document('Patient is stable and advised routine follow-up.')])['indicator'] === 'HIGH', 'Stable condition must be high.');
smart_priority_assert($assess(['pwd'], 'medical', [$document('Patient requires urgent surgery.')])['indicator'] === 'URGENT', 'Current urgent surgery must be urgent.');
$urgentBasis = $assess(['pwd'], 'medical', [$document('Patient requires urgent surgery.')])['basis'];
smart_priority_assert(str_contains($urgentBasis, 'Current medical need.') && str_contains($urgentBasis, 'Patient requires urgent surgery.'), 'Urgent priority basis must retain Gemini reason and supporting evidence.');
smart_priority_assert($assess(['senior_citizen'], 'medical', [$document('History of surgery in 2024.')])['indicator'] === 'HIGH', 'Past surgery must be high.');
smart_priority_assert($assess(['pwd'], 'medical', [$document('Elective surgery scheduled next month.')])['indicator'] === 'HIGH', 'Elective future surgery must be high.');
smart_priority_assert($assess(['pwd'], 'medical', [$document('No urgency.'), $document('ICU admission due to critical condition requiring immediate care.', 'billing_statement')])['indicator'] === 'URGENT', 'A credible urgent bill must outweigh a non-urgent certificate.');
smart_priority_assert($assess(['pwd'], 'medical', [$document('Take one tablet daily.', 'billing_statement')])['indicator'] === 'HIGH', 'Routine prescription must be high.');
smart_priority_assert($assess(['pwd'], 'medical', [$document('STAT - administer immediately due to emergency condition.', 'prescription')])['indicator'] === 'URGENT', 'Emergency prescription must be urgent.');
smart_priority_assert($assess(['senior_citizen'], 'medical', [$document('Hypertensive Heart Disease')])['indicator'] === 'HIGH', 'Diagnosis alone must not promote urgency.');
$highBasis = $assess(['senior_citizen'], 'medical', [$document('Hypertensive Heart Disease')])['basis'];
smart_priority_assert(str_contains($highBasis, 'No sufficient current medical need.') && str_contains($highBasis, 'Hypertensive Heart Disease'), 'High priority basis must explain why the extracted evidence was insufficient.');
smart_priority_assert($assess(['senior_citizen'], 'medical', [$document('Stable hypertensive heart disease. Continue maintenance medication.')])['indicator'] === 'HIGH', 'Stable chronic treatment must stay high.');
smart_priority_assert($assess(['pwd'], 'medical', [$document('History of cerebrovascular accident in 2022. Patient currently stable.')])['indicator'] === 'HIGH', 'Historical stroke must stay high.');
smart_priority_assert($assess(['pwd'], 'medical', [$document('Patient requires surgery for current condition.')])['indicator'] === 'URGENT', 'Current surgery need without an urgency keyword may be urgent.');
$beforeCombined = $calls;
smart_priority_assert($assess(['senior_citizen'], 'medical', [
    $document('Cerebrovascular Accident with significant right-sided weakness.'),
    $document('Physician recommends continued medical intervention.', 'billing_statement'),
    $document('Identity card says emergency.', 'valid_id'),
])['indicator'] === 'URGENT', 'Combined medical context must support urgency without reading Valid ID.');
smart_priority_assert($calls === $beforeCombined + 1, 'All eligible medical documents must be assessed together in one Gemini call.');
smart_priority_assert(count($lastDocuments) === 2 && !in_array('valid_id', array_column($lastDocuments, 'document_type'), true), 'Valid ID must be excluded from the combined analysis input.');
smart_priority_assert($assess(['senior_citizen'], 'medical', [
    $document('Patient requires urgent surgery.'),
    ['document_type' => 'medical_abstract', 'unavailable' => true],
])['indicator'] === 'HIGH', 'An unavailable medical document must prevent a partial urgent decision.');
smart_priority_assert($assess(['pwd'], 'medical', [$document('API failure')])['indicator'] === 'HIGH', 'API failure must be high.');
smart_priority_assert(aics_smart_priority_assess(['pwd'], 'medical', [$document('API failure')],
    static fn (): never => throw new RuntimeException('timeout'))['indicator'] === 'HIGH', 'Timeout exceptions must be high.');
smart_priority_assert($assess(['pwd'], 'medical', [])['indicator'] === 'HIGH', 'No medical documents must be high.');
smart_priority_assert($assess(['pwd'], 'medical', [$document('Patient requires urgent surgery.', 'valid_id')])['indicator'] === 'HIGH', 'Valid IDs must never be analyzed.');
smart_priority_assert(aics_smart_priority_medical_document_type('medical_abstract') === 'med_cert', 'Medical Abstract must use the medical certificate slot.');
smart_priority_assert(aics_smart_priority_medical_document_type('medical_abstract_certificate') === 'med_cert', 'Historical Medical Abstract/Certificate alias must be eligible.');
smart_priority_assert(aics_smart_priority_medical_document_type('billing_statement_or_prescription') === 'billing_statement', 'Billing or prescription slot must be eligible.');

$prompt = aics_smart_priority_gemini_prompt();
smart_priority_assert(str_contains($prompt, 'A scheduled or required operation or surgery for the current condition is sufficient for urgent=true; a purely elective or cosmetic procedure is not.'), 'Priority prompt must treat a needed operation as urgent.');
smart_priority_assert(str_contains($prompt, 'CURRENT') && str_contains($prompt, 'PAST'), 'Priority prompt must distinguish current from historical care.');
smart_priority_assert(str_contains($prompt, 'Do not verify') && str_contains($prompt, 'Do not compare'), 'Priority prompt must exclude verification and identity matching.');
foreach (['diagnoses', 'disability', 'functional impairment', 'complications', 'physician recommendations', 'treatment requirements', 'hospitalization', 'prescriptions', 'billing', 'timing'] as $factor) {
    smart_priority_assert(stripos($prompt, $factor) !== false, 'Priority prompt must assess ' . $factor . '.');
}
smart_priority_assert(str_contains($prompt, 'not require explicit words') && str_contains($prompt, 'disease name alone') && str_contains($prompt, 'documents together'), 'Priority prompt must use combined context rather than keyword or disease-name shortcuts.');
$extractionPrompt = aics_smart_priority_gemini_extraction_prompt();
smart_priority_assert(str_contains($extractionPrompt, 'visible') && str_contains($extractionPrompt, 'Do not decide priority'), 'Image extraction must copy visible medical facts without classifying priority.');
smart_priority_assert(str_contains($extractionPrompt, 'Do not verify') && str_contains($extractionPrompt, 'Do not compare'), 'Image extraction must stay outside verification and identity matching.');
$parsedUrgency = aics_smart_priority_parse_gemini_response(json_encode(['candidates' => [['content' => ['parts' => [['text' => '{"urgent":true,"reason":"Current weakness and intervention need.","evidence":["Right-sided weakness","Physician recommendation"]}']]]]]]));
smart_priority_assert($parsedUrgency['urgent'] === true && count($parsedUrgency['evidence']) === 2, 'Structured contextual urgency result must parse.');
smart_priority_assert(aics_smart_priority_parse_gemini_response(json_encode(['candidates' => [['content' => ['parts' => [['text' => '{"urgent":true,"reason":"","evidence":[]}']]]]]])) === null, 'Unsupported urgent result must fail safely.');
smart_priority_assert(aics_smart_priority_parse_gemini_response('{bad json') === null, 'Malformed Gemini result must fail safely.');
smart_priority_assert(aics_smart_priority_parse_gemini_response(json_encode(['candidates' => [['content' => ['parts' => [['text' => '{"urgent":"true"}']]]]]])) === null, 'String urgency must not become true.');
smart_priority_assert(aics_smart_priority_parse_gemini_response(json_encode(['candidates' => [['content' => ['parts' => [['text' => '{"urgent":true}']]]]]])) === null, 'Incomplete Gemini output must not create urgent priority.');
smart_priority_assert(aics_smart_priority_parse_gemini_response(json_encode(['candidates' => [['content' => ['parts' => [['text' => '{"urgent":true,"reason":"Need care","evidence":"stroke"}']]]]]])) === null, 'Malformed evidence must not create urgent priority.');
$body = json_decode(aics_smart_priority_gemini_extraction_body([
    ['document_type' => 'med_cert', 'mime' => 'application/pdf', 'bytes' => '%PDF test'],
    ['document_type' => 'billing_statement', 'mime' => 'image/png', 'bytes' => 'PNG test'],
]), true);
smart_priority_assert($body['contents'][0]['parts'][2]['inline_data']['mime_type'] === 'application/pdf', 'Gemini priority request must send the uploaded PDF.');
smart_priority_assert(base64_decode($body['contents'][0]['parts'][2]['inline_data']['data']) === '%PDF test', 'Gemini priority request must include document bytes.');
smart_priority_assert($body['contents'][0]['parts'][4]['inline_data']['mime_type'] === 'image/png', 'Gemini priority request must include the second medical document.');
smart_priority_assert(base64_decode($body['contents'][0]['parts'][4]['inline_data']['data']) === 'PNG test', 'Gemini priority request must include the second document bytes.');
smart_priority_assert($body['generationConfig']['responseSchema']['type'] === 'OBJECT' && $body['generationConfig']['responseSchema']['properties']['documents']['type'] === 'ARRAY', 'Image extraction must send Gemini Schema enum types.');
smart_priority_assert($body['generationConfig']['responseSchema']['properties']['documents']['items']['properties']['diagnoses']['type'] === 'ARRAY', 'Image extraction must capture diagnoses.');
smart_priority_assert($body['generationConfig']['responseSchema']['properties']['documents']['items']['properties']['functional_impairment']['type'] === 'ARRAY', 'Image extraction must capture functional impairment.');
smart_priority_assert(!str_contains(json_encode($body), 'beneficiary_full_name'), 'Priority Gemini context must not carry identity targets.');
$medicalFields = [
    'diagnoses', 'nature_of_disease_or_disability', 'symptoms_or_impairments', 'complications',
    'functional_impairment', 'physician_findings', 'physician_recommendations', 'treatment_requirements',
    'procedures', 'hospitalization_or_admission', 'medications_or_instructions', 'billing_services',
    'severity_statements', 'current_status', 'timing_statements', 'historical_statements', 'other_medical_evidence',
];
$extractedDocument = array_fill_keys($medicalFields, []);
$extractedDocument['document_index'] = 1;
$extractedDocument['document_type'] = 'Medical Certificate';
$extractedDocument['diagnoses'] = ['Cerebrovascular Accident'];
$extractedDocument['functional_impairment'] = ['Right-sided weakness'];
$extractionEnvelope = static fn (array $documents): string => json_encode(['candidates' => [['content' => ['parts' => [
    ['text' => json_encode(['documents' => $documents])],
]]]]]);
$parsedExtraction = aics_smart_priority_parse_extraction_response($extractionEnvelope([$extractedDocument]), 1);
smart_priority_assert($parsedExtraction['documents'][0]['diagnoses'] === ['Cerebrovascular Accident'] &&
    $parsedExtraction['documents'][0]['functional_impairment'] === ['Right-sided weakness'], 'Gemini image extraction must preserve diagnosis and impairment separately.');
smart_priority_assert(aics_smart_priority_parse_extraction_response($extractionEnvelope([$extractedDocument]), 2) === null, 'Missing extraction for one medical image must fail safely.');
$invalidExtraction = $extractedDocument;
$invalidExtraction['diagnoses'] = 'Cerebrovascular Accident';
smart_priority_assert(aics_smart_priority_parse_extraction_response($extractionEnvelope([$invalidExtraction]), 1) === null, 'Malformed extracted medical data must fail safely.');
$emptyExtraction = array_fill_keys($medicalFields, []);
$emptyExtraction['document_index'] = 1;
$emptyExtraction['document_type'] = 'Medical Certificate';
smart_priority_assert(aics_smart_priority_parse_extraction_response($extractionEnvelope([$emptyExtraction]), 1) === null, 'An image with no extracted medical facts must fail safely.');
$analysisBody = json_decode(aics_smart_priority_gemini_analysis_body($parsedExtraction['documents']), true);
$analysisText = $analysisBody['contents'][0]['parts'][0]['text'] ?? '';
smart_priority_assert(str_contains($analysisText, 'Cerebrovascular Accident') && str_contains($analysisText, 'Right-sided weakness'), 'Urgency analysis must receive the structured extracted medical facts.');
smart_priority_assert(!str_contains(json_encode($analysisBody), 'inline_data'), 'Urgency analysis must use extracted data rather than re-reading image bytes.');
smart_priority_assert($analysisBody['generationConfig']['responseSchema']['properties']['urgent']['type'] === 'BOOLEAN', 'Urgency analysis must request a boolean Gemini Schema field.');
$firstImage = tempnam(sys_get_temp_dir(), 'aics-priority-image-');
$secondImage = tempnam(sys_get_temp_dir(), 'aics-priority-image-');
smart_priority_assert(is_string($firstImage) && is_string($secondImage), 'Temporary priority image fixtures must be available.');
file_put_contents($firstImage, 'medical certificate image');
file_put_contents($secondImage, 'billing statement image');
$secondExtractedDocument = $extractedDocument;
$secondExtractedDocument['document_index'] = 2;
$secondExtractedDocument['document_type'] = 'Billing Statement';
$secondExtractedDocument['diagnoses'] = [];
$secondExtractedDocument['functional_impairment'] = [];
$secondExtractedDocument['billing_services'] = ['Current hospitalization'];
$geminiCalls = [];
$geminiTransport = static function (string $requestBody, callable $parse) use (&$geminiCalls, $extractionEnvelope, $extractedDocument, $secondExtractedDocument): ?array {
    $request = json_decode($requestBody, true);
    $geminiCalls[] = $request;
    if (count($geminiCalls) === 1) return $parse($extractionEnvelope([$extractedDocument, $secondExtractedDocument]));
    return $parse(json_encode(['candidates' => [['content' => ['parts' => [
        ['text' => '{"urgent":true,"reason":"Current neurological impairment and hospitalization.","evidence":["Right-sided weakness","Current hospitalization"]}'],
    ]]]]]));
};
$twoStageResult = aics_smart_priority_gemini_analyze([
    ['document_type' => 'med_cert', 'mime' => 'image/png', 'path' => $firstImage],
    ['document_type' => 'billing_statement', 'mime' => 'image/png', 'path' => $secondImage],
], $geminiTransport);
smart_priority_assert($twoStageResult['urgent'] === true && count($geminiCalls) === 2, 'Medical priority must extract all images, then run one combined urgency analysis.');
smart_priority_assert(count(array_filter($geminiCalls[0]['contents'][0]['parts'], static fn ($part): bool => isset($part['inline_data']))) === 2, 'Extraction stage must include both medical images.');
smart_priority_assert(!str_contains(json_encode($geminiCalls[1]), 'inline_data'), 'Analysis stage must contain extracted data only.');
smart_priority_assert(str_contains($geminiCalls[1]['contents'][0]['parts'][0]['text'], 'Current hospitalization'), 'Combined analysis must use evidence from the second document.');
$failedCalls = 0;
$failedExtraction = static function (string $requestBody, callable $parse) use (&$failedCalls): ?array { $failedCalls++; return null; };
smart_priority_assert(aics_smart_priority_gemini_analyze([['document_type' => 'med_cert', 'mime' => 'image/png', 'path' => $firstImage]], $failedExtraction) === null && $failedCalls === 1, 'Failed image extraction must stop before urgency analysis.');
$malformedAnalysisCalls = 0;
$malformedAnalysis = static function (string $requestBody, callable $parse) use (&$malformedAnalysisCalls, $extractionEnvelope, $extractedDocument): ?array {
    $malformedAnalysisCalls++;
    return $malformedAnalysisCalls === 1 ? $parse($extractionEnvelope([$extractedDocument])) : $parse('{bad json');
};
smart_priority_assert(aics_smart_priority_gemini_analyze([['document_type' => 'med_cert', 'mime' => 'image/png', 'path' => $firstImage]], $malformedAnalysis) === null && $malformedAnalysisCalls === 2, 'Malformed urgency analysis must use the safe HIGH fallback.');
unlink($firstImage);
unlink($secondImage);
$cacheDocuments = [['path' => __FILE__, 'document_type' => 'med_cert']];
$cacheSchema = ['extraction' => $body['generationConfig']['responseSchema'], 'analysis' => $analysisBody['generationConfig']['responseSchema']];
$cacheContract = aics_smart_priority_cache_contract();
smart_priority_assert(str_contains($cacheContract['prompt'], aics_smart_priority_gemini_extraction_request_text()) &&
    str_contains($cacheContract['prompt'], aics_smart_priority_gemini_analysis_request_text()), 'Both Gemini stage instructions must invalidate cached priority decisions when changed.');
smart_priority_assert($cacheContract['schema'] === $cacheSchema, 'Cache contract must include both schemas sent to Gemini.');
$firstCacheKey = aics_smart_priority_cache_fingerprint($cacheDocuments, 'prompt A', $cacheSchema);
smart_priority_assert(is_string($firstCacheKey) && $firstCacheKey !== '', 'Medical priority cache fingerprint must be available.');
smart_priority_assert($firstCacheKey !== aics_smart_priority_cache_fingerprint($cacheDocuments, 'prompt B', $cacheSchema), 'Prompt changes must invalidate the medical priority cache.');
$revisedSchema = $cacheSchema;
$revisedSchema['properties']['evidence']['description'] = 'Revised evidence rule';
smart_priority_assert($firstCacheKey !== aics_smart_priority_cache_fingerprint($cacheDocuments, 'prompt A', $revisedSchema), 'Schema changes must invalidate the medical priority cache.');
smart_priority_assert(aics_smart_priority_tier('HIGH', 'STANDARD') === aics_smart_priority_tier('NORMAL', 'STANDARD'), 'Senior non-medical must rank as standard.');
smart_priority_assert(aics_smart_priority_label('HIGH', 'REQUIRES_STAFF_REVIEW') === 'HIGH', 'Pending senior medical analysis must have the safe high fallback.');
smart_priority_assert(aics_smart_priority_tier('NORMAL', 'URGENT') < aics_smart_priority_tier('HIGH', 'HIGH'), 'Urgent must outrank high.');
smart_priority_assert(aics_smart_priority_storage_values('STANDARD') === ['score' => 0, 'label' => 'STANDARD'], 'Standard must be stored as the final classification.');
smart_priority_assert(aics_smart_priority_storage_values('HIGH') === ['score' => 1, 'label' => 'HIGH'], 'High must be stored as the final classification.');
smart_priority_assert(aics_smart_priority_storage_values('URGENT') === ['score' => 2, 'label' => 'URGENT'], 'Urgent must be stored as the final classification.');
smart_priority_assert(aics_smart_priority_label('HIGH', 'STANDARD') === 'STANDARD', 'New labels must use three indicators only.');
smart_priority_assert(aics_smart_priority_label('HIGH', 'HIGH') === 'HIGH', 'High label must be plain text.');
smart_priority_assert(pswdo_priority_display_label('HIGH', 'URGENT') === 'URGENT', 'Queue must display urgent.');
smart_priority_assert(pswdo_medical_urgency_label('HIGH') === 'High', 'High detail label must be available.');
smart_priority_assert(pswdo_priority_badge_meta('HIGH', 'STANDARD')['class'] === 'priority-chip-normal', 'Senior non-medical badge must be neutral.');
$newSortSql = pswdo_smart_priority_order_case_sql();
smart_priority_assert(str_contains($newSortSql, "= 'HIGH'"), 'Queue SQL must include the high indicator.');
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=false');
smart_priority_assert(aics_smart_priority_label('HIGH', 'URGENT') === 'URGENT MEDICAL + SENIOR/PWD', 'Feature flag must restore the legacy label.');
smart_priority_assert(pswdo_smart_priority_order_case_sql() !== $newSortSql, 'Feature flag must restore legacy ordering.');
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=true');

$previousEnvFile = getenv('AICS_EXTERNAL_AI_ENV_FILE');
$previousModel = getenv('AICS_GEMINI_MODEL');
$previousFallback = getenv('AICS_GEMINI_FALLBACK_MODELS');
$flagFile = tempnam(sys_get_temp_dir(), 'aics-priority-');
smart_priority_assert(is_string($flagFile), 'Temporary private configuration file is unavailable.');
file_put_contents($flagFile, "SMART_PRIORITY_MEDICAL_ANALYSIS=false\n");
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS');
putenv('AICS_EXTERNAL_AI_ENV_FILE=' . $flagFile);
smart_priority_assert(!aics_smart_priority_analysis_enabled(), 'Private configuration must disable Smart Priority analysis.');
putenv('AICS_GEMINI_MODEL');
putenv('AICS_GEMINI_FALLBACK_MODELS');
smart_priority_assert(aics_smart_priority_gemini_settings()['models'] ===
    ['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite'], 'Smart Priority must use supported model defaults.');
smart_priority_assert(aics_smart_priority_gemini_settings()['timeout'] >= 45,
    'Smart Priority must allow the document-analysis provider enough time for image extraction.');
smart_priority_assert(aics_smart_priority_remaining_timeout(45, 1100.0, 1000.0) === 45,
    'The initial Gemini attempt must retain the configured per-attempt timeout.');
smart_priority_assert(aics_smart_priority_remaining_timeout(45, 1100.0, 1080.0) === 20,
    'Later Gemini attempts must be limited by the remaining full-analysis budget.');
smart_priority_assert(aics_smart_priority_remaining_timeout(45, 1100.0, 1100.0) === 0,
    'Gemini requests must stop when the full-analysis budget expires.');
unlink($flagFile);
putenv($previousEnvFile === false ? 'AICS_EXTERNAL_AI_ENV_FILE' : 'AICS_EXTERNAL_AI_ENV_FILE=' . $previousEnvFile);
putenv($previousModel === false ? 'AICS_GEMINI_MODEL' : 'AICS_GEMINI_MODEL=' . $previousModel);
putenv($previousFallback === false ? 'AICS_GEMINI_FALLBACK_MODELS' : 'AICS_GEMINI_FALLBACK_MODELS=' . $previousFallback);
putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=true');

echo "Smart Priority medical analysis checks passed.\n";
