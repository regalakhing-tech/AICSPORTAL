# Gemini-Assisted Tamper Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add strict, multi-signal detection for edited document fields such as dates, names, and amounts, then prevent automatic clearance and require staff review when independent evidence corroborates possible alteration.

**Architecture:** Extend Gemini's schema with bounded, localized tamper indicators; add deterministic chronology and regional image analyzers; combine those outputs with ADCD after all components finish. Persist one backward-compatible canonical tamper branch and surface neutral, staff-review-only evidence in the PSWDO interface.

**Tech Stack:** Python 3, `unittest`, OpenCV, NumPy, existing Gemini REST client and JSON-schema validation, PHP 8, vanilla JavaScript/CSS.

**Spec:** `docs/superpowers/specs/2026-09-29-gemini-tamper-detection-design.md`

## Global Constraints

- Never automatically declare a document fake, forged, fraudulent, or authentic.
- Never automatically reject an application or send a re-upload request from alteration evidence alone.
- Use the existing `needs_staff_review` workflow status for a confirmed combined review signal.
- A font difference by itself must never escalate a document.
- Gemini visual evidence is valid only when the provider received images.
- External failures must not stop OCR, local verification, or ADCD.
- Persist only validated, bounded evidence; never persist raw prompts, raw provider responses, image data URLs, credentials, unrestricted crops, or filesystem paths.
- Preserve existing user work in the dirty workspace and stage only files named by the active task.

---

### Task 1: Versioned Gemini Tamper Evidence Contract

**Files:**
- Modify: `ai_processor/external_ai/client.py`
- Modify: `ai_processor/external_ai/contracts.py`
- Modify: `ai_processor/external_ai/service.py`
- Modify: `ai_processor/tests/test_external_ai_contracts.py`
- Modify: `ai_processor/tests/test_external_ai_client.py`
- Modify: `ai_processor/tests/test_external_ai_integration.py`

**Interfaces:**
- Consumes: existing `ExternalAiClient.assess(evidence: dict, image_data_urls: list[str] | None = None) -> dict`.
- Produces: validated `assessment['tamper_assessment']` with `state`, `confidence_band`, `summary`, and `indicators`.
- Produces: `PROMPT_VERSION = 'external-ai-assessment-v4'` and assessment schema version `external-ai-assessment-v2`.

- [ ] **Step 1: Add failing normalization tests for the v2 contract**

Add literal fixtures to `test_external_ai_contracts.py` proving valid localization and rejecting unsafe or malformed evidence:

```python
def test_v2_tamper_assessment_normalizes_bounded_visual_indicator(self):
    from ai_processor.external_ai.contracts import normalize_assessment
    payload = valid_v2_assessment(tamper_assessment={
        'state': 'review_signal', 'confidence_band': 'high',
        'summary': 'Date area needs staff inspection.',
        'indicators': [{
            'type': 'typography_mismatch', 'page': 1,
            'box_2d': [780, 520, 835, 790], 'confidence_band': 'moderate',
            'reason': 'Date weight and edge sharpness differ from nearby filled fields.',
        }],
    })
    normalized = normalize_assessment(payload, payload_mode='image', page_count=1)
    assert normalized['tamper_assessment']['indicators'][0]['box_2d'] == [780, 520, 835, 790]

def test_tamper_indicator_rejects_out_of_range_box_and_page(self):
    from ai_processor.external_ai.contracts import ExternalAiContractError, normalize_assessment
    with self.assertRaises(ExternalAiContractError):
        normalize_assessment(valid_v2_assessment(tamper_assessment={
            'state': 'review_signal', 'confidence_band': 'high', 'summary': 'Review.',
            'indicators': [{'type': 'pasted_region_boundary', 'page': 2,
                            'box_2d': [900, 100, 800, 1200],
                            'confidence_band': 'high', 'reason': 'Invalid box.'}],
        }), payload_mode='image', page_count=1)

def test_text_only_response_cannot_claim_visual_tampering(self):
    normalized = normalize_assessment(
        valid_v2_assessment(tamper_assessment=review_signal_fixture()),
        payload_mode='text', page_count=0,
    )
    assert normalized['tamper_assessment'] == {
        'state': 'unavailable', 'confidence_band': 'low',
        'summary': 'Visual alteration review requires document images.', 'indicators': [],
    }
```

Also test: no more than twelve indicators; 240-character reason/summary bounds; enumerated types only; integer page and coordinates; non-empty in-bounds rectangle; forbidden keys such as `fraud`, `forged`, `authentic`, `decision`, and `reject` at any tamper-section level.

Define `valid_v2_assessment(tamper_assessment: dict) -> dict` in the test file as a complete literal v2 assessment containing all required legacy sections, and `review_signal_fixture() -> dict` as the one-indicator section shown above. Production private helpers used in Step 3 have these exact contracts: `_contains_forbidden_key(value: object) -> bool` recursively scans dictionary keys; `_normalize_base_assessment(payload: dict) -> dict` performs the existing state/field/quality normalization; and `_validated_image_tamper_section(section: object, page_count: int) -> dict` performs every new bound and enum check.

- [ ] **Step 2: Run the focused contract tests and confirm the expected failure**

Run:

```powershell
python -m unittest ai_processor.tests.test_external_ai_contracts -v
```

Expected: FAIL because schema v2, `tamper_assessment`, and contextual normalization parameters do not exist.

- [ ] **Step 3: Implement strict v2 normalization**

In `contracts.py`, add constants and contextual validation:

```python
_TAMPER_STATES = frozenset({'no_signal', 'review_signal', 'unavailable'})
_TAMPER_INDICATOR_TYPES = frozenset({
    'typography_mismatch', 'baseline_or_spacing_mismatch', 'ink_or_color_mismatch',
    'pasted_region_boundary', 'background_texture_discontinuity',
    'compression_or_resampling_difference', 'date_context_conflict',
    'value_context_conflict', 'signature_or_stamp_anomaly', 'other_visual_anomaly',
})

def normalize_assessment(payload: dict, *, payload_mode: str = 'text', page_count: int = 0) -> dict:
    if not isinstance(payload, dict) or _contains_forbidden_key(payload):
        raise _invalid_schema()
    normalized = _normalize_base_assessment(payload)
    normalized['tamper_assessment'] = _normalize_tamper_assessment(
        payload.get('tamper_assessment'), payload_mode, page_count,
    )
    return normalized

def _normalize_tamper_assessment(section: object, payload_mode: str, page_count: int) -> dict:
    if payload_mode != 'image':
        return {'state': 'unavailable', 'confidence_band': 'low',
                'summary': 'Visual alteration review requires document images.', 'indicators': []}
    return _validated_image_tamper_section(section, page_count)
```

The implementation must recursively reject forbidden conclusion keys and normalize text-only results to `unavailable` even when the provider claims `review_signal`.

- [ ] **Step 4: Add failing client-schema and prompt tests**

Extend `test_external_ai_client.py` and `test_external_ai_integration.py`:

```python
def test_gemini_schema_requires_localized_tamper_assessment(self):
    body = json.loads(ExternalAiClient(gemini_settings())._request_body(
        {'fields': {'document_date': {'value': None}}},
        ['data:image/jpeg;base64,AA=='],
    ))
    schema = body['generationConfig']['responseSchema']
    tamper = schema['properties']['tamper_assessment']
    self.assertIn('tamper_assessment', schema['required'])
    self.assertEqual(tamper['properties']['indicators']['maxItems'], 12)
    self.assertIn('typography_mismatch', tamper['properties']['indicators']['items']['properties']['type']['enum'])

def test_prompt_requires_peer_comparison_and_prohibits_forgery_conclusions(self):
    instruction = gemini_instruction().lower()
    self.assertIn('nearby peer text', instruction)
    self.assertIn('font difference alone', instruction)
    self.assertIn('never conclude forgery', instruction)
    self.assertIn('page and normalized bounding box', instruction)
```

- [ ] **Step 5: Run the client tests and confirm the expected failure**

Run:

```powershell
python -m unittest ai_processor.tests.test_external_ai_client ai_processor.tests.test_external_ai_integration -v
```

Expected: FAIL because the request schema and prompt still expose only `visual_integrity`.

- [ ] **Step 6: Implement the Gemini v2 request contract and cache invalidation**

Update `_SYSTEM_INSTRUCTION`, `_REASON_CODES`, and `_assessment_json_schema()` in `client.py`. Pass `payload_mode` and `page_count` into `normalize_assessment()` at the response boundary. Update all test fixtures from `external-ai-assessment-v1` to v2 and include a valid tamper section.

In `service.py` set:

```python
PROMPT_VERSION = 'external-ai-assessment-v4'
```

Keep the cache key inputs unchanged except for the version value so all older assessments are bypassed.

- [ ] **Step 7: Run all external-AI tests**

Run:

```powershell
python -m unittest discover -s ai_processor/tests -p 'test_external_ai_*.py' -v
```

Expected: PASS with no raw prompt, credential, or conflicting identity leakage.

- [ ] **Step 8: Commit Task 1**

```powershell
git add -- ai_processor/external_ai/client.py ai_processor/external_ai/contracts.py ai_processor/external_ai/service.py ai_processor/tests/test_external_ai_contracts.py ai_processor/tests/test_external_ai_client.py ai_processor/tests/test_external_ai_integration.py
git -c user.name='Codex' -c user.email='codex@openai.com' commit -m "feat: add structured Gemini alteration evidence"
```

---

### Task 2: Deterministic Chronology and Regional Image Evidence

**Files:**
- Create: `ai_processor/tamper_detection/chronology.py`
- Create: `ai_processor/tamper_detection/regional.py`
- Create: `ai_processor/tests/test_tamper_chronology.py`
- Create: `ai_processor/tests/test_tamper_regional.py`

**Interfaces:**
- Consumes: OCR metadata shaped as `{'pages': [{'lines': [{'text': str, 'box': [x1,y1,x2,y2], 'confidence': float}]}]}`.
- Consumes: original OpenCV page arrays and extracted fields.
- Produces: `analyze_chronology(expected_type: str, ocr_metadata: dict, fields: dict) -> dict`.
- Produces: `analyze_regional_inconsistency(images: list, ocr_metadata: dict) -> dict`.
- Each result contains `status`, `family`, `decision`, and a bounded `indicators` list.

- [ ] **Step 1: Write failing chronology tests**

In `test_tamper_chronology.py`, use literal OCR lines for the reported class of edit:

```python
def test_medical_certificate_large_date_gap_is_candidate_not_standalone_verdict(self):
    metadata = pages_with_lines([
        ('confined from April 11 - 15, 2021 inclusive', [40, 600, 900, 650]),
        ('Date: September 24, 2026', [500, 1300, 900, 1360]),
    ])
    result = analyze_chronology('med_cert', metadata, {
        'document_date': {'status': 'extracted', 'normalized': '2026-09-24', 'page': 1},
    })
    self.assertEqual(result['decision'], 'review')
    self.assertEqual(result['indicators'][0]['type'], 'date_context_conflict')
    self.assertEqual(result['indicators'][0]['confidence_band'], 'moderate')

def test_legitimate_later_issue_date_inside_profile_tolerance_is_clear(self):
    metadata = pages_with_lines([
        ('Examined on February 1, 2026', [40, 600, 500, 650]),
        ('Date Issued: February 3, 2026', [500, 1300, 900, 1360]),
    ])
    result = analyze_chronology('med_cert', metadata, {
        'document_date': {'status': 'extracted', 'normalized': '2026-02-03', 'page': 1},
    })
    self.assertEqual(result['decision'], 'clear')

def test_font_or_date_gap_evidence_never_uses_fraud_wording(self):
    self.assertNotRegex(json.dumps(result).lower(), r'forg|fraud|fake|authentic')
```

Cover incompatible order, repeated labeled-date disagreement, no reliable dates, and unsupported document types.

Define `pages_with_lines(rows: list[tuple[str, list[int]]]) -> dict` in the test file to return one OCR metadata page with 95.0 confidence for each supplied row. In production, add `_validated_page_lines(metadata: dict) -> list[dict]`, `_extract_dated_lines(lines: list[dict]) -> list[dict]`, and `_profile_conflicts(expected_type: str, evidence: list[dict], fields: dict) -> list[dict]`; each output indicator must use the v2 indicator keys and normalized coordinates.

- [ ] **Step 2: Run chronology tests and verify RED**

```powershell
python -m unittest ai_processor.tests.test_tamper_chronology -v
```

Expected: ERROR because `chronology.py` does not exist.

- [ ] **Step 3: Implement bounded chronology analysis**

Create `chronology.py` with these public constants and function:

```python
_LONG_GAP_DAYS = 730
_MAX_INDICATORS = 6

def analyze_chronology(expected_type: str, ocr_metadata: dict, fields: dict) -> dict:
    """Return neutral chronology evidence; never a fraud or authenticity decision."""
    lines = _validated_page_lines(ocr_metadata)
    evidence = _extract_dated_lines(lines)
    indicators = _profile_conflicts(expected_type, evidence, fields)[:_MAX_INDICATORS]
    return {'status': 'completed', 'family': 'semantic_chronology',
            'decision': 'review' if indicators else 'clear', 'indicators': indicators}
```

Reuse `field_extractor.DATE_PATTERN` and `field_normalization.normalize_date`. Preserve page and normalized box for matched lines. A long gap is moderate candidate evidence only; the aggregator in Task 3 decides whether corroboration is sufficient.

- [ ] **Step 4: Run chronology tests and verify GREEN**

```powershell
python -m unittest ai_processor.tests.test_tamper_chronology -v
```

Expected: PASS.

- [ ] **Step 5: Write failing regional-analysis tests with synthetic images**

Create clean and edited NumPy fixtures directly in `test_tamper_regional.py`; do not store personal documents in the repository.

```python
def test_single_font_difference_is_not_enough_to_flag(self):
    image, metadata = clean_multifont_form_fixture()
    result = analyze_regional_inconsistency([image], metadata)
    self.assertEqual(result['decision'], 'clear')

def test_date_region_with_size_and_sharpness_outliers_is_review_signal(self):
    image, metadata = synthetic_edited_date_fixture()
    result = analyze_regional_inconsistency([image], metadata)
    self.assertEqual(result['decision'], 'review')
    indicator = result['indicators'][0]
    self.assertEqual(indicator['type'], 'typography_mismatch')
    self.assertGreaterEqual(len(indicator['metrics']), 2)
    self.assertEqual(len(indicator['box_2d']), 4)

def test_shadow_fold_and_global_jpeg_noise_do_not_create_local_review_signal(self):
    image, metadata = synthetic_clean_page_with_shadow_fold_and_jpeg_noise()
    result = analyze_regional_inconsistency([image], metadata)
    self.assertEqual(result['decision'], 'clear')
```

Also cover a pasted rectangle, changed foreground color, empty OCR boxes, invalid boxes, grayscale pages, and multi-page page numbering.

The test module defines four deterministic fixture helpers returning `(numpy.ndarray, ocr_metadata)`: `clean_multifont_form_fixture()`, `synthetic_edited_date_fixture()`, `synthetic_clean_page_with_shadow_fold_and_jpeg_noise()`, and `synthetic_pasted_region_fixture()`. Draw fixtures with OpenCV primitives and `cv2.putText`; do not read external images.

- [ ] **Step 6: Run regional tests and verify RED**

```powershell
python -m unittest ai_processor.tests.test_tamper_regional -v
```

Expected: ERROR because `regional.py` does not exist.

- [ ] **Step 7: Implement robust regional measurements**

Create `regional.py` with a small public surface:

```python
_SENSITIVE_TEXT = re.compile(
    r'(?i)\b(date|issued|valid|expires|name|patient|deceased|amount|total|reference|signature|stamp)\b'
)

def analyze_regional_inconsistency(images: list, ocr_metadata: dict) -> dict:
    indicators = []
    for page_number, image, line in _sensitive_lines(images, ocr_metadata):
        metrics = _regional_metrics(image, line['box'])
        outliers = _robust_outliers(metrics, _page_peer_metrics(image, ocr_metadata, page_number))
        if len(outliers) >= 2:
            indicators.append(_regional_indicator(page_number, line, outliers))
    return {'status': 'completed', 'family': 'local_regional',
            'decision': 'review' if indicators else 'clear', 'indicators': indicators[:8]}
```

For each sensitive OCR line, clip its box, normalize it to `[0,1000]`, and calculate line height, foreground intensity, Laplacian sharpness, stroke-width proxy, local background residual, and boundary contrast. Compare against robust page medians using median absolute deviation. Emit `review` only when at least two independent metrics exceed calibrated conservative thresholds; one metric returns `clear` with optional low-confidence diagnostics omitted from persisted findings. Cap indicators at eight.

Private helper contracts in this module are: `_sensitive_lines(images: list, metadata: dict) -> Iterable[tuple[int, ndarray, dict]]`, `_regional_metrics(image: ndarray, box: list[int]) -> dict[str, float]`, `_page_peer_metrics(image: ndarray, metadata: dict, page_number: int) -> list[dict[str, float]]`, `_robust_outliers(candidate: dict, peers: list[dict]) -> list[str]`, and `_regional_indicator(page_number: int, line: dict, outliers: list[str]) -> dict`.

- [ ] **Step 8: Run Task 2 tests and the existing image suite**

```powershell
python -m unittest ai_processor.tests.test_tamper_chronology ai_processor.tests.test_tamper_regional ai_processor.tests.test_image_ocr_preprocessing ai_processor.tests.test_tamper_adapter -v
```

Expected: PASS.

- [ ] **Step 9: Commit Task 2**

```powershell
git add -- ai_processor/tamper_detection/chronology.py ai_processor/tamper_detection/regional.py ai_processor/tests/test_tamper_chronology.py ai_processor/tests/test_tamper_regional.py
git -c user.name='Codex' -c user.email='codex@openai.com' commit -m "feat: add local alteration evidence analyzers"
```

---

### Task 3: Independent-Evidence Aggregation and Safe Persistence

**Files:**
- Create: `ai_processor/tamper_detection/aggregation.py`
- Modify: `ai_processor/tamper_detection/postprocessing.py`
- Create: `ai_processor/tests/test_tamper_aggregation.py`
- Modify: `ai_processor/tests/test_tamper_persistence.py`
- Modify: `ai_processor/tests/test_tamper_independence.py`

**Interfaces:**
- Consumes: normalized Gemini assessment, chronology result, regional result, and raw ADCD result.
- Produces: `aggregate_tamper_evidence(external_result: dict, chronology: dict, regional: dict, adcd_result: dict) -> dict`.
- Produces: `attach_combined_finding(findings: object, combined: dict) -> dict`.
- Produces: `apply_tamper_recommendation(result: dict, combined: dict) -> dict`.

- [ ] **Step 1: Write failing aggregation-policy tests**

Create table-driven tests in `test_tamper_aggregation.py`:

```python
CASES = [
    ('font_only', gemini('typography_mismatch', 'moderate'), clear(), clear(), clear_adcd(), False),
    ('date_plus_local_visual', gemini('typography_mismatch', 'moderate'),
     chronology_review(), regional_review('typography_mismatch'), clear_adcd(), True),
    ('adcd_plus_gemini', gemini('pasted_region_boundary', 'moderate'),
     clear(), clear(), review_adcd(.91, .02), True),
    ('gemini_only_high', gemini('pasted_region_boundary', 'high'), clear(), clear(), clear_adcd(), False),
    ('all_unavailable', unavailable(), unavailable(), unavailable(), unavailable_adcd(), False),
]

def test_aggregation_requires_independent_corroboration(self):
    for name, external, chronology, regional, adcd, expected_review in CASES:
        with self.subTest(name=name):
            combined = aggregate_tamper_evidence(external, chronology, regional, adcd)
            self.assertEqual(combined['decision'] == 'review', expected_review)
```

Add explicit overlap tests: Gemini and local visual boxes must overlap or relate to the same page/semantic date indicator before satisfying the two-visual-family rule.

The test module defines literal helpers `gemini()`, `chronology_review()`, `regional_review()`, `clear()`, `unavailable()`, `clear_adcd()`, `review_adcd()`, and `unavailable_adcd()`; each returns a complete dictionary matching the producing task's documented interface.

- [ ] **Step 2: Run aggregation tests and verify RED**

```powershell
python -m unittest ai_processor.tests.test_tamper_aggregation -v
```

Expected: ERROR because `aggregation.py` does not exist.

- [ ] **Step 3: Implement aggregation with explicit family independence**

Create `aggregation.py`:

```python
def boxes_overlap(left: list[int], right: list[int], minimum_iou: float = .10) -> bool:
    intersection = _intersection_area(left, right)
    union = _box_area(left) + _box_area(right) - intersection
    return union > 0 and intersection / union >= minimum_iou

def aggregate_tamper_evidence(external_result: dict, chronology: dict,
                              regional: dict, adcd_result: dict) -> dict:
    """Combine independent advisory signals without asserting forgery."""
    evidence = _normalized_family_evidence(external_result, chronology, regional, adcd_result)
    review = _meets_corroboration_policy(evidence)
    return _combined_result(evidence, review)

def apply_tamper_recommendation(result: dict, combined: dict) -> dict:
    output = deepcopy(result or {})
    if combined.get('decision') != 'review':
        return output
    if output.get('status') not in {'needs_reupload', 'flagged_inconsistency'}:
        output['status'] = 'needs_staff_review'
    return _append_neutral_review_reason(output)
```

The combined result must retain backward-compatible `status`, `decision`, `aggregate`, and `pages` values for existing ADCD readers while adding `integration_version: 2`, `label`, `confidence_band`, `indicators`, and sanitized `providers` summaries.

`apply_tamper_recommendation()` sets `needs_staff_review` only when `combined['decision'] == 'review'` and the current status is not `needs_reupload` or `flagged_inconsistency`. It appends one neutral reason and never changes a stronger existing result.

Private helper contracts are: `_box_area(box: list[int]) -> int`, `_intersection_area(left: list[int], right: list[int]) -> int`, `_normalized_family_evidence(external: dict, chronology: dict, regional: dict, adcd: dict) -> dict`, `_meets_corroboration_policy(evidence: dict) -> bool`, `_combined_result(evidence: dict, review: bool) -> dict`, and `_append_neutral_review_reason(result: dict) -> dict`.

- [ ] **Step 4: Add failing persistence and independence tests**

Extend existing tests to assert:

```python
attached = attach_combined_finding(BASE, combined_review_fixture())
self.assertEqual(attached['assessments']['alteration_signal'], {
    'state': 'review',
    'label': 'Suspected alteration — staff review required',
    'explanation': 'Independent checks found changes that require inspection of the original document.',
    'diagnostics': {'integration_version': 2},
})
self.assertNotIn('image_data', json.dumps(attached))
self.assertNotIn('raw_response', json.dumps(attached))
```

Prove unavailable components stay neutral and legacy ADCD-only findings still normalize.

- [ ] **Step 5: Run persistence tests and verify RED**

```powershell
python -m unittest ai_processor.tests.test_tamper_persistence ai_processor.tests.test_tamper_independence -v
```

Expected: FAIL because combined persistence and the version-2 assessment do not exist.

- [ ] **Step 6: Implement safe combined persistence**

In `postprocessing.py`, retain `persisted_finding()` for legacy callers and add:

```python
def attach_combined_finding(findings: object, combined: dict) -> dict:
    merged = dict(findings) if isinstance(findings, dict) else {}
    merged.pop('tampering', None)
    merged['tamper_detection'] = sanitize_combined_finding(combined)
    assessments = dict(merged.get('assessments') or {})
    assessments['alteration_signal'] = combined_alteration_assessment(merged['tamper_detection'])
    merged['assessments'] = assessments
    return merged
```

Whitelist every persisted key and revalidate page, box, enum, confidence, and length bounds instead of copying provider dictionaries.

- [ ] **Step 7: Run all tamper unit tests**

```powershell
python -m unittest discover -s ai_processor/tests -p 'test_tamper_*.py' -v
```

Expected: PASS.

- [ ] **Step 8: Commit Task 3**

```powershell
git add -- ai_processor/tamper_detection/aggregation.py ai_processor/tamper_detection/postprocessing.py ai_processor/tests/test_tamper_aggregation.py ai_processor/tests/test_tamper_persistence.py ai_processor/tests/test_tamper_independence.py
git -c user.name='Codex' -c user.email='codex@openai.com' commit -m "feat: aggregate independent alteration signals"
```

---

### Task 4: Pipeline Integration and Workflow Escalation

**Files:**
- Modify: `ai_processor/processor.py`
- Modify: `ai_processor/tests/test_processing_stages.py`
- Modify: `ai_processor/tests/test_external_ai_integration.py`
- Modify: `ai_processor/tests/test_processing_timings.py`

**Interfaces:**
- Consumes: Task 2 analyzers and Task 3 aggregator.
- Produces: one finalized `findings.tamper_detection` branch after Gemini, local evidence, and ADCD complete.

- [ ] **Step 1: Write a failing pipeline-order test**

Patch all four analyzers in `test_processing_stages.py` and capture call order:

```python
expected_order = [
    'review_document', 'analyze_chronology', 'analyze_regional_inconsistency',
    'analyze_tampering', 'aggregate_tamper_evidence', 'attach_combined_finding',
    'apply_tamper_recommendation', 'finish',
]
self.assertEqual(events, expected_order)
```

Add a result test proving a combined review signal changes `completed` to `needs_staff_review`, while `needs_reupload` and `flagged_inconsistency` remain unchanged.

- [ ] **Step 2: Run processing tests and verify RED**

```powershell
python -m unittest ai_processor.tests.test_processing_stages ai_processor.tests.test_processing_timings -v
```

Expected: FAIL because the pipeline does not run or aggregate the local analyzers.

- [ ] **Step 3: Integrate the complete alteration stage**

Update imports and replace the current shadow-only attachment in `processor.py` with:

```python
chronology_result = analyze_chronology(expected_type, ocr_metadata, fields)
regional_result = analyze_regional_inconsistency(images, ocr_metadata)
adcd_result = analyze_tampering(images, SETTINGS, ocr_metadata)
adcd_result = persist_private_artifacts(adcd_result, review_id, SETTINGS)
combined_tamper = aggregate_tamper_evidence(
    external_result, chronology_result, regional_result, adcd_result,
)
result['findings'] = attach_combined_finding(result.get('findings'), combined_tamper)
result = apply_tamper_recommendation(result, combined_tamper)
```

Record separate timing keys `chronology_analysis`, `regional_analysis`, and `alteration_analysis`. Keep every component inside failure isolation so an exception becomes that component's neutral unavailable result rather than failing the document review.

- [ ] **Step 4: Run the pipeline and integration tests**

```powershell
python -m unittest ai_processor.tests.test_processing_stages ai_processor.tests.test_processing_timings ai_processor.tests.test_external_ai_integration ai_processor.tests.test_tamper_persistence -v
```

Expected: PASS.

- [ ] **Step 5: Run the complete Python suite**

```powershell
python -m unittest discover -s ai_processor/tests -p 'test_*.py' -v
```

Expected: PASS with zero failures or errors.

- [ ] **Step 6: Commit Task 4**

```powershell
git add -- ai_processor/processor.py ai_processor/tests/test_processing_stages.py ai_processor/tests/test_external_ai_integration.py ai_processor/tests/test_processing_timings.py
git -c user.name='Codex' -c user.email='codex@openai.com' commit -m "feat: enforce staff review for corroborated alterations"
```

---

### Task 5: PSWDO Staff Evidence Display

**Files:**
- Modify: `Pswdo/php_files/pswdo-data-lib.php`
- Modify: `Pswdo/php_files/request-management.php`
- Modify: `Pswdo/js files/pswdo-pages.js`
- Modify: `Pswdo/css files/pswdo-pages.css`
- Modify: `tests/pswdo-tamper-analysis-test.php`
- Modify: `tests/pswdo-ai-component-results-test.php`
- Modify: `tests/pswdo-ai-details-modal-contract-test.php`

**Interfaces:**
- Consumes: persisted version-2 `findings.tamper_detection` and Gemini `tamper_assessment`.
- Produces: normalized PHP tamper view model and safely rendered staff evidence.

- [ ] **Step 1: Add failing PHP normalization and copy tests**

Extend `pswdo-tamper-analysis-test.php` with a combined fixture:

```php
$combined = pswdo_tamper_analysis_json(json_encode([
    'tamper_detection' => [
        'status' => 'completed', 'integration_version' => 2, 'decision' => 'review',
        'label' => 'Suspected alteration — staff review required',
        'confidence_band' => 'high',
        'indicators' => [[
            'type' => 'date_context_conflict', 'page' => 1,
            'box_2d' => [780, 520, 835, 790], 'confidence_band' => 'moderate',
            'reason' => 'Certification date differs from the document timeline.',
        ]],
        'providers' => [
            'gemini' => ['status' => 'completed'],
            'local_regional' => ['status' => 'completed'],
            'adcd_net' => ['status' => 'completed'],
        ],
    ],
]));
tamper_assert($combined['decision'] === 'review', 'Combined review decision was lost.');
tamper_assert(count($combined['indicators']) === 1, 'Localized evidence was lost.');
```

Assert the page contains `Document Alteration Review`, `Suspected alteration — staff review required`, `Page`, `Confidence`, `Evidence source`, and the human-review disclaimer. Assert forbidden conclusion labels remain absent.

- [ ] **Step 2: Run PHP tests and verify RED**

```powershell
& 'C:\xampp\php\php.exe' tests/pswdo-tamper-analysis-test.php
& 'C:\xampp\php\php.exe' tests/pswdo-ai-component-results-test.php
& 'C:\xampp\php\php.exe' tests/pswdo-ai-details-modal-contract-test.php
```

Expected: FAIL because combined indicators and the new section are not normalized or rendered.

- [ ] **Step 3: Implement the PHP view model and server-rendered section**

In `pswdo-data-lib.php`, whitelist combined states and indicators. Invalid coordinates, page numbers, types, or reasons must be dropped, not rendered.

In `request-management.php`, render:

```php
<section class="ai-alteration-review" aria-labelledby="ai-alteration-review-title">
  <h4 id="ai-alteration-review-title">Document Alteration Review</h4>
  <!-- escaped decision label, confidence, provider availability, and indicator rows -->
  <p class="ai-tamper-note">AI-assisted alteration findings are advisory and require confirmation by authorized PSWDO personnel.</p>
</section>
```

Use `pswdo_h()` for every provider-originated string. Do not render raw boxes as executable attributes; show page and plain-language evidence, with authorized existing document/artifact links only.

- [ ] **Step 4: Add failing dynamic-modal test assertions**

Extend `pswdo-ai-details-modal-contract-test.php` to require the canonical `tamper_detection` branch and forbid the legacy `findings?.tampering` lookup.

- [ ] **Step 5: Update JavaScript and CSS**

In `pswdo-pages.js`, render indicators with DOM `textContent` only:

```javascript
const tamper = data.findings?.tamper_detection;
if (tamper && typeof tamper === "object") {
  const indicatorRows = Array.isArray(tamper.indicators)
    ? tamper.indicators.map((item) => [
        human("", item.type || "review_signal"),
        `Page ${Number(item.page) || 1} · ${human("", item.confidence_band || "low")}\n${String(item.reason || "Staff inspection required.")}`,
      ])
    : [];
  addSection("Document Alteration Review", [["Result", String(tamper.label || "Review unavailable")], ...indicatorRows]);
}
```

Add narrowly scoped styles for readable evidence rows and uniform provider-status badges without changing unrelated reports or applicant UI.

- [ ] **Step 6: Run PSWDO tests and syntax checks**

```powershell
& 'C:\xampp\php\php.exe' -l 'Pswdo/php_files/pswdo-data-lib.php'
& 'C:\xampp\php\php.exe' -l 'Pswdo/php_files/request-management.php'
& 'C:\xampp\php\php.exe' tests/pswdo-tamper-analysis-test.php
& 'C:\xampp\php\php.exe' tests/pswdo-ai-component-results-test.php
& 'C:\xampp\php\php.exe' tests/pswdo-ai-details-modal-contract-test.php
```

Expected: PASS.

- [ ] **Step 7: Commit Task 5**

```powershell
git add -- Pswdo/php_files/pswdo-data-lib.php Pswdo/php_files/request-management.php 'Pswdo/js files/pswdo-pages.js' 'Pswdo/css files/pswdo-pages.css' tests/pswdo-tamper-analysis-test.php tests/pswdo-ai-component-results-test.php tests/pswdo-ai-details-modal-contract-test.php
git -c user.name='Codex' -c user.email='codex@openai.com' commit -m "feat: show corroborated alteration evidence to staff"
```

---

### Task 6: Security, Regression, and Evaluation Gate

**Files:**
- Modify: `ai_processor/tests/test_external_ai_privacy.py`
- Modify: `ai_processor/tests/test_privacy_logging.py`
- Modify: `tests/pswdo-ai-artifact-security-test.php`
- Create: `ai_processor/tests/test_tamper_security_regression.py`
- Modify: `ai_processor/README.md`

**Interfaces:**
- Consumes: completed feature from Tasks 1–5.
- Produces: final security and false-positive regression gate plus operator documentation.

- [ ] **Step 1: Write failing end-to-end security regressions**

Create `test_tamper_security_regression.py` with synthetic images and complete component dictionaries:

```python
def test_changed_date_requires_two_independent_evidence_families(self):
    combined = run_synthetic_case('edited_date')
    self.assertEqual(combined['decision'], 'review')
    self.assertGreaterEqual(len(set(combined['evidence_families'])), 2)

def test_clean_multifont_document_does_not_escalate(self):
    combined = run_synthetic_case('clean_multifont')
    self.assertEqual(combined['decision'], 'clear')

def test_persisted_and_logged_data_excludes_sensitive_payloads(self):
    serialized = json.dumps(run_synthetic_case('edited_name'))
    for forbidden in ('data:image', 'raw_response', 'api_key', 'C:\\xampp\\aics_private'):
        self.assertNotIn(forbidden, serialized)
```

Also verify unavailable Gemini and unavailable ADCD independently, malformed boxes, Unicode reasons, and HTML-like provider text.

Define `run_synthetic_case(case_name: str) -> dict` in the test module as a local dispatcher over literal component fixtures for `edited_date`, `clean_multifont`, and `edited_name`; it must call the real Task 3 aggregator and persistence sanitizer rather than reproduce their decision logic.

- [ ] **Step 2: Run security tests and verify RED**

```powershell
python -m unittest ai_processor.tests.test_tamper_security_regression ai_processor.tests.test_external_ai_privacy ai_processor.tests.test_privacy_logging -v
& 'C:\xampp\php\php.exe' tests/pswdo-ai-artifact-security-test.php
```

Expected: FAIL until the new full-flow fixtures and privacy assertions are satisfied.

- [ ] **Step 3: Fix only discovered security or false-positive gaps**

Keep changes within the files already introduced by Tasks 1–5. Do not loosen the two-family requirement to make a fixture pass. Update `ai_processor/README.md` with the exact v2 states, component availability behavior, staff-review-only policy, and commands for the tamper-focused tests.

- [ ] **Step 4: Run the complete verification suite**

```powershell
python -m unittest discover -s ai_processor/tests -p 'test_*.py' -v
& 'C:\xampp\php\php.exe' tests/pswdo-tamper-analysis-test.php
& 'C:\xampp\php\php.exe' tests/pswdo-ai-component-results-test.php
& 'C:\xampp\php\php.exe' tests/pswdo-ai-details-modal-contract-test.php
& 'C:\xampp\php\php.exe' tests/pswdo-ai-artifact-security-test.php
& 'C:\xampp\php\php.exe' tests/pswdo-screening-decision-contract-test.php
& 'C:\xampp\php\php.exe' tests/pswdo-request-management-refinement-test.php
```

Expected: all commands exit 0 with no failures or errors.

- [ ] **Step 5: Run static and whitespace checks**

```powershell
python -m compileall -q ai_processor
& 'C:\xampp\php\php.exe' -l 'Pswdo/php_files/pswdo-data-lib.php'
& 'C:\xampp\php\php.exe' -l 'Pswdo/php_files/request-management.php'
git diff --check
```

Expected: exit 0. Existing line-ending warnings are acceptable; whitespace errors are not.

- [ ] **Step 6: Commit Task 6**

```powershell
git add -- ai_processor/tests/test_external_ai_privacy.py ai_processor/tests/test_privacy_logging.py tests/pswdo-ai-artifact-security-test.php ai_processor/tests/test_tamper_security_regression.py ai_processor/README.md
git -c user.name='Codex' -c user.email='codex@openai.com' commit -m "test: verify alteration detection security boundaries"
```

- [ ] **Step 7: Request final code review**

Provide the reviewer the spec path, this plan path, the six task commit hashes, and explicit requirements: multi-signal threshold, no automatic rejection/re-upload, no forgery assertion, failure isolation, bounded persistence, and clean multi-font false-positive coverage.

- [ ] **Step 8: Re-run verification after review fixes**

Repeat Steps 4 and 5 after addressing all Critical and Important review findings. Record the exact passing commands and any environment-dependent test that could not run.
