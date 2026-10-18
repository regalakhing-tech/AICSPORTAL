# AI Verification Accuracy and DocTamper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve local document verification accuracy with document-specific OCR evidence, conservative field matching, calibrated outcomes, and an independent DocTamper/ADCD-Net alteration signal.

**Architecture:** Keep the existing local Python pipeline, but replace the single mixed confidence number with structured component assessments. Centralize document profiles, make OCR candidate selection evidence-based, keep DocTamper neutral to approval/outcome decisions, and gate `Likely matched` behind measured per-profile thresholds from a de-identified fixture manifest.

**Tech Stack:** Python 3.10+, OpenCV, pytesseract/Tesseract, pdf2image/Poppler, local DocTamper ADCD-Net service, PHP 8+, MySQL/MariaDB, unittest

**Spec:** `docs/superpowers/specs/2026-09-17-ai-document-verification-design.md`

## Global Constraints

- AI output remains advisory; automated processing must never approve, reject, or change PSWDO document-review state.
- DocTamper output is an alteration signal, not proof of fraud and not an automatic rejection reason.
- Missing or uncertain evidence must resolve to staff review instead of a confident guess.
- Processing stays local and must not send applicant documents or OCR content to an external service.
- Do not log OCR text, names, dates of birth, addresses, identifiers, or image bytes.
- Numeric diagnostic scores may be stored internally, but staff-facing UI must show separate plain-language component results.
- A document profile cannot display `Likely matched` until its validation manifest meets the configured thresholds.

## File Structure

- `ai_processor/document_profiles.py`: canonical supported-document profiles, anchors, fields, and calibration gates.
- `ai_processor/ocr_engine.py`: bounded preprocessing, orientation/deskew, OCR candidate evidence.
- `ai_processor/document_classifier.py`: profile-driven expected-slot classification and negative evidence.
- `ai_processor/field_extractor.py`: document-specific structured extraction with provenance.
- `ai_processor/field_normalization.py`: Philippine name/date/address/sex normalization.
- `ai_processor/consistency.py`: conservative application and trusted-document comparisons.
- `ai_processor/image_quality.py`: readable/unreadable image evidence independent of classification.
- `ai_processor/verification.py`: component assessments and advisory outcome selection.
- `ai_processor/processor.py`: orchestration and DocTamper attachment.
- `ai_processor/config.py`: local DocTamper defaults and validation thresholds.
- `ai_processor/tamper_detection/adapter.py`: DocTamper service contract and neutral failures.
- `ai_processor/tamper_detection/postprocessing.py`: safe aggregate/page assessment labels.
- `ai_processor/evaluation.py`: de-identified manifest runner and metrics.
- `ai_processor/tests/fixtures/validation/manifest.json`: labeled evaluation cases without applicant PII.
- `includes/document-ai-reviews.php`: health semantics for optional/degraded DocTamper.
- `Pswdo/php_files/ai-health.php`: degraded component reporting.
- `Pswdo/php_files/pswdo-common.php`: component label helpers.
- `Pswdo/php_files/request-management.php`: four concise component assessments.
- `Pswdo/js files/pswdo-pages.js`: live component rendering after terminal status.
- `tests/pswdo-ai-component-results-test.php`: staff-facing wording/structure contract.

---

### Task 1: Define Canonical Document Profiles and Calibration Gates

**Files:**
- Create: `ai_processor/document_profiles.py`
- Create: `ai_processor/tests/test_document_profiles.py`
- Modify: `ai_processor/document_classifier.py:1-260`
- Modify: `ai_processor/field_extractor.py:1-320`

**Interfaces:**
- Produces: `DocumentProfile` dataclass.
- Produces: `get_profile(document_type: str) -> DocumentProfile | None`.
- Produces: `supported_document_types() -> tuple[str, ...]`.
- Produces profile properties: `positive_anchor_groups`, `negative_anchors`, `extractable_fields`, `required_fields`, `identity_field`, `validation_gate`.
- Consumes: canonical document codes from `includes/document-requirements.php` mirrored exactly in Python.

- [ ] **Step 1: Write failing profile completeness tests**

```python
EXPECTED = {
    'valid_id', 'med_cert', 'billing_statement', 'death_cert',
    'funeral_contract', 'report_police_med', 'school_assessment',
    'registration_form', 'mdrrmo_cert', 'eclip_cert',
    'crisis_situation_cert', 'damaged_house_photo',
}

def test_every_supported_type_has_one_profile(self):
    self.assertEqual(set(supported_document_types()), EXPECTED)
    for code in EXPECTED:
        profile = get_profile(code)
        self.assertEqual(profile.code, code)
        self.assertGreaterEqual(profile.validation_gate.min_precision, .90)

def test_photo_evidence_never_claims_ocr_identity_fields(self):
    profile = get_profile('damaged_house_photo')
    self.assertEqual(profile.extractable_fields, ())
    self.assertIsNone(profile.identity_field)
```

- [ ] **Step 2: Run tests and verify the module-not-found failure**

Run: `python -m unittest ai_processor.tests.test_document_profiles -v`

Expected: FAIL because `ai_processor.document_profiles` does not exist.

- [ ] **Step 3: Implement immutable document profiles**

Use frozen dataclasses:

```python
@dataclass(frozen=True)
class ValidationGate:
    min_cases: int
    min_precision: float
    max_false_reupload_rate: float

@dataclass(frozen=True)
class DocumentProfile:
    code: str
    label: str
    positive_anchor_groups: tuple[tuple[str, ...], ...]
    negative_anchors: tuple[str, ...]
    extractable_fields: tuple[str, ...]
    required_fields: tuple[str, ...]
    identity_field: str | None
    validation_gate: ValidationGate
```

Medical profiles use `min_cases=30`, `min_precision=.95`, and `max_false_reupload_rate=.02`. Other profiles use `min_cases=20`, `min_precision=.90`, and `max_false_reupload_rate=.03`. Photo evidence always requires manual review. Move duplicated profile lists from classifier/extractor code into this registry without changing current outcomes yet.

- [ ] **Step 4: Run profile and existing classification/extraction tests**

Run: `python -m unittest ai_processor.tests.test_document_profiles ai_processor.tests.test_philippine_valid_id_validation ai_processor.tests.test_philippine_medical_slot_validation -v`

Expected: PASS.

- [ ] **Step 5: Commit the profile registry**

```powershell
git add -- ai_processor/document_profiles.py ai_processor/document_classifier.py ai_processor/field_extractor.py ai_processor/tests/test_document_profiles.py
git commit -m "refactor: centralize document verification profiles"
```

---

### Task 2: Make Image Preparation Orientation-Aware and Bounded

**Files:**
- Modify: `ai_processor/ocr_engine.py:1-320`
- Modify: `ai_processor/image_quality.py:1-180`
- Create: `ai_processor/tests/test_document_preprocessing.py`
- Add: `ai_processor/tests/fixtures/preprocessing/rotated-card.png`
- Add: `ai_processor/tests/fixtures/preprocessing/low-contrast-certificate.png`
- Add: `ai_processor/tests/fixtures/preprocessing/cropped-page.png`

**Interfaces:**
- Produces: `prepare_document_candidates(image) -> list[OcrCandidate]`.
- Produces: `OcrCandidate(name: str, image, transform: TransformMetadata)`.
- Produces transform metadata with rotation, crop box, and inverse coordinate mapping for DocTamper/OCR overlays.
- Consumes: existing `_document_region()`, deskew logic, and bounded candidate policy.

- [ ] **Step 1: Add failing deterministic preprocessing tests**

```python
def test_rotated_card_produces_upright_candidate(self):
    candidates = prepare_document_candidates(load_fixture('rotated-card.png'))
    self.assertLessEqual(len(candidates), 5)
    self.assertTrue(any(candidate.transform.rotation_degrees in (90, 270) for candidate in candidates))

def test_every_candidate_maps_boxes_to_original_coordinates(self):
    for candidate in prepare_document_candidates(load_fixture('cropped-page.png')):
        box = candidate.transform.to_original((10, 20, 100, 40))
        self.assert_box_inside_original(box)
```

Also assert low-contrast input includes a CLAHE candidate and that no candidate mutates the original array.

- [ ] **Step 2: Run preprocessing tests and verify the missing API fails**

Run: `python -m unittest ai_processor.tests.test_document_preprocessing -v`

Expected: FAIL because `prepare_document_candidates` is not defined.

- [ ] **Step 3: Implement bounded candidate preparation**

Create candidates for original, CLAHE, sharpened, and at most one orientation/deskew correction plus one corrected CLAHE version. Use Tesseract orientation detection only when it returns a supported rotation with sufficient script/orientation confidence. Carry inverse transforms so OCR boxes and DocTamper regions remain aligned to original pixels.

Do not mark low contrast, blur, darkness, or overexposure as a re-upload condition solely from pixels; combine visual evidence with OCR and extracted-field evidence in `image_quality.py`.

- [ ] **Step 4: Run preprocessing and quality suites**

Run: `python -m unittest ai_processor.tests.test_document_preprocessing ai_processor.tests.test_image_ocr_preprocessing ai_processor.tests.test_quality_regression -v`

Expected: PASS.

- [ ] **Step 5: Commit bounded preprocessing**

```powershell
git add -- ai_processor/ocr_engine.py ai_processor/image_quality.py ai_processor/tests/test_document_preprocessing.py ai_processor/tests/fixtures/preprocessing
git commit -m "feat: improve document image preprocessing"
```

---

### Task 3: Select OCR Evidence by Anchor and Field Consensus

**Files:**
- Modify: `ai_processor/ocr_engine.py:320-620`
- Modify: `ai_processor/field_extractor.py:1-360`
- Modify: `ai_processor/field_normalization.py:1-320`
- Create: `ai_processor/tests/test_ocr_candidate_consensus.py`

**Interfaces:**
- Produces: `score_ocr_candidate(text: str, confidence: float, profile: DocumentProfile, fields: dict) -> CandidateScore`.
- Produces: `select_ocr_evidence(candidates: list[OcrResult], profile: DocumentProfile) -> OcrEvidence`.
- `OcrEvidence` contains selected text, OCR confidence, privacy-safe candidate name, boxes, extracted fields, and field-level provenance.
- Consumes: profiles from Task 1 and candidates from Task 2.

- [ ] **Step 1: Write failing consensus tests**

Use synthetic OCR results, not real applicant data:

```python
def test_high_raw_confidence_without_anchors_loses_to_supported_candidate(self):
    noise = result('noise', 93, 'random unrelated text', {})
    supported = result('clahe', 76, MED_CERT_TEXT, MED_CERT_FIELDS)
    selected = select_ocr_evidence([noise, supported], get_profile('med_cert'))
    self.assertEqual(selected.candidate_name, 'clahe')

def test_conflicting_identity_values_become_uncertain(self):
    selected = select_ocr_evidence([candidate_named_ana(), candidate_named_anna()], get_profile('valid_id'))
    self.assertEqual(selected.fields['name']['status'], 'uncertain')
```

- [ ] **Step 2: Run consensus tests and verify they fail**

Run: `python -m unittest ai_processor.tests.test_ocr_candidate_consensus -v`

Expected: FAIL because `select_ocr_evidence` is not defined.

- [ ] **Step 3: Implement evidence scoring and conservative merge**

Score candidates from normalized OCR confidence, positive anchor-group coverage, negative-anchor penalty, required-field evidence, and coherent-line ratio. Cap each component and retain the breakdown. Merge a field only when normalized values agree or one candidate has stronger anchor/context proof; otherwise set `status='uncertain'` and omit a trusted value.

Normalize:

- particles and suffixes in Philippine names without dropping them;
- `MM/DD/YYYY`, `DD/MM/YYYY`, month-name, and ISO dates while rejecting ambiguous numeric dates without contextual evidence;
- sex/gender labels to `male`, `female`, or `unspecified`;
- address whitespace/punctuation while preserving municipality/province terms.

- [ ] **Step 4: Run OCR, normalization, and field suites**

Run: `python -m unittest ai_processor.tests.test_ocr_candidate_consensus ai_processor.tests.test_structured_consistency ai_processor.tests.test_philippine_valid_id_validation -v`

Expected: PASS.

- [ ] **Step 5: Commit evidence-based OCR selection**

```powershell
git add -- ai_processor/ocr_engine.py ai_processor/field_extractor.py ai_processor/field_normalization.py ai_processor/tests/test_ocr_candidate_consensus.py
git commit -m "feat: select OCR using document evidence"
```

---

### Task 4: Return Separate Component Assessments and Conservative Outcomes

**Files:**
- Modify: `ai_processor/verification.py:1-170`
- Modify: `ai_processor/consistency.py:1-320`
- Modify: `ai_processor/processor.py:70-130`
- Create: `ai_processor/tests/test_component_assessments.py`

**Interfaces:**
- Produces findings keys: `assessments.document_match`, `assessments.readability`, `assessments.applicant_match`, `assessments.alteration_signal`.
- Each assessment is `{state: pass|review|fail|unavailable|not_applicable, label: str, explanation: str, diagnostics: dict}`.
- Produces: `evaluate(..., profile_calibrated: bool = False) -> dict` and terminal status from component rules while preserving current database-compatible statuses.
- Consumes: classification, quality, field consensus, application consistency, and later DocTamper assessment.

- [ ] **Step 1: Write failing outcome-matrix tests**

```python
def test_uncertain_name_never_returns_likely_valid(self):
    result = evaluate_case(name_status='uncertain', type_decision='match', quality='acceptable')
    self.assertEqual(result['status'], 'needs_staff_review')
    self.assertEqual(result['findings']['assessments']['applicant_match']['state'], 'review')

def test_reliable_wrong_slot_is_reupload(self):
    result = evaluate_case(type_decision='wrong_slot', type_strength='strong')
    self.assertEqual(result['status'], 'needs_reupload')

def test_address_difference_alone_is_not_identity_failure(self):
    result = evaluate_case(address='mismatch', name='match', birth_date='match')
    self.assertNotEqual(result['status'], 'flagged_inconsistency')
```

Assert the result contains no aggregate user-facing confidence field in findings.

- [ ] **Step 2: Run the matrix and verify assessment keys are missing**

Run: `python -m unittest ai_processor.tests.test_component_assessments -v`

Expected: FAIL with missing `assessments`.

- [ ] **Step 3: Implement the decision matrix**

Use this precedence:

1. unreadable/blank/corrupt or strong wrong-slot evidence -> `needs_reupload`;
2. reliable stable-identity mismatch -> `flagged_inconsistency`;
3. unavailable, missing, conflicting, or uncertain evidence -> `needs_staff_review`;
4. all applicable validated components pass and `profile_calibrated` is true -> `likely_valid`;
5. `profile_calibrated` is false -> `needs_staff_review` even if checks pass.

For this task, `processor.py` passes `profile_calibrated=False`, which conservatively prevents `likely_valid` until Task 6 supplies a validated report. Continue populating `confidence_score` only as a backward-compatible diagnostic for this migration, but stop rendering it as overall accuracy. Store the component assessment structure under findings and set `model_version='component-assessments-v2'`.

- [ ] **Step 4: Run verification and consistency suites**

Run: `python -m unittest ai_processor.tests.test_component_assessments ai_processor.tests.test_structured_consistency ai_processor.tests.test_quality_regression -v`

Expected: PASS.

- [ ] **Step 5: Commit component outcomes**

```powershell
git add -- ai_processor/verification.py ai_processor/consistency.py ai_processor/processor.py ai_processor/tests/test_component_assessments.py
git commit -m "feat: separate AI verification assessments"
```

---

### Task 5: Make DocTamper a Default Local, Independent Evidence Branch

**Files:**
- Modify: `ai_processor/config.py:20-48`
- Modify: `ai_processor/tamper_detection/adapter.py:1-260`
- Modify: `ai_processor/tamper_detection/postprocessing.py:1-260`
- Modify: `ai_processor/processor.py:95-125`
- Modify: `includes/document-ai-reviews.php:252-330`
- Modify: `Pswdo/php_files/ai-health.php:1-100`
- Modify: `ai_processor/tests/test_tamper_adapter.py`
- Create: `ai_processor/tests/test_tamper_independence.py`

**Interfaces:**
- Produces alteration assessment states: `clear_signal`, `review_signal`, `unavailable`.
- Produces provider metadata: service/model version, calibrated threshold ID, aggregate metrics, and page metrics.
- Consumes: local loopback ADCD service and OCR boxes; never consumes or sends applicant form fields.

- [ ] **Step 1: Write failing independence and health tests**

```python
def test_review_signal_does_not_change_verification_outcome(self):
    base = verified_result(status='likely_valid')
    attached = attach_persisted_finding(base['findings'], doctamper_review_signal())
    self.assertEqual(base['status'], 'likely_valid')
    self.assertEqual(attached['assessments']['alteration_signal']['state'], 'review')

def test_unavailable_signal_preserves_other_assessments(self):
    attached = attach_persisted_finding(component_findings(), doctamper_unavailable())
    self.assertEqual(attached['assessments']['document_match']['state'], 'pass')
    self.assertEqual(attached['assessments']['alteration_signal']['state'], 'unavailable')
```

In `tests/ai-health-test.php`, require an unavailable ADCD service to yield overall `degraded` when database, Python, worker, Tesseract, and Poppler are available.

- [ ] **Step 2: Run DocTamper tests and verify current semantics fail**

Run: `python -m unittest ai_processor.tests.test_tamper_adapter ai_processor.tests.test_tamper_independence -v`

Expected: FAIL because the component assessment is not attached.

- [ ] **Step 3: Align local defaults and neutral failure semantics**

Set Python `adcd_enabled` default to true so it matches the PHP runtime contract. Keep the URL restricted to `http://127.0.0.1:<port>`. When the service is unavailable, timeouts, or returns malformed content, attach `unavailable` and continue saving OCR/classification findings.

Map calibrated output to plain language without calling it fraud or tampering proof:

```python
def alteration_assessment(provider):
    if provider.get('status') != 'completed':
        return {'state': 'unavailable', 'label': 'Alteration check unavailable',
                'explanation': 'Staff can continue reviewing the original document.'}
    state = 'review' if provider.get('decision') == 'review' else 'pass'
    return {'state': state,
            'label': 'Review highlighted areas' if state == 'review' else 'No strong alteration signal',
            'explanation': 'This image signal supports staff inspection and is not proof of document fraud.'}
```

Change health aggregation so required OCR components determine `unavailable`; an ADCD-only outage produces `degraded`.

- [ ] **Step 4: Run DocTamper, health, and persistence suites**

Run: `python -m unittest ai_processor.tests.test_tamper_adapter ai_processor.tests.test_tamper_independence ai_processor.tests.test_tamper_persistence ai_processor.tests.test_tamper_postprocessing -v`

Expected: PASS.

Run: `php tests/ai-health-test.php`

Expected: PASS with ADCD-only outage mapped to degraded.

- [ ] **Step 5: Commit independent DocTamper analysis**

```powershell
git add -- ai_processor/config.py ai_processor/tamper_detection/adapter.py ai_processor/tamper_detection/postprocessing.py ai_processor/processor.py includes/document-ai-reviews.php Pswdo/php_files/ai-health.php ai_processor/tests/test_tamper_adapter.py ai_processor/tests/test_tamper_independence.py tests/ai-health-test.php
git commit -m "feat: integrate DocTamper as advisory evidence"
```

---

### Task 6: Add a De-identified Evaluation Harness and Calibration Manifest

**Files:**
- Create: `ai_processor/evaluation.py`
- Create: `ai_processor/calibration.py`
- Create: `ai_processor/tests/test_evaluation_metrics.py`
- Create: `ai_processor/tests/fixtures/validation/manifest.json`
- Create: `ai_processor/calibration-report.json`
- Add: `ai_processor/tests/fixtures/validation/synthetic-medical/`
- Add: `ai_processor/tests/fixtures/validation/synthetic-other/`
- Modify: `ai_processor/config.py:1-60`
- Modify: `ai_processor/processor.py:70-105`

**Interfaces:**
- Produces: `evaluate_manifest(path: Path) -> EvaluationReport`.
- Produces per-profile metrics: case count, type precision/recall, field accuracy, identity false-match/missed-match rates, false re-upload rate, DocTamper false-positive/false-negative rates.
- Produces: `profile_is_calibrated(profile, report) -> bool`.
- Produces: `load_calibration_report(path: Path) -> EvaluationReport` with a fail-closed empty report for missing, invalid, or version-mismatched files.
- Consumes: synthetic or explicitly de-identified fixture paths and expected labels.

- [ ] **Step 1: Write failing metric and privacy tests**

```python
def test_metrics_are_separate_by_profile_and_component(self):
    report = evaluate_manifest(FIXTURE_MANIFEST)
    medical = report.profiles['med_cert']
    self.assertIsNotNone(medical.type_precision)
    self.assertIsNotNone(medical.false_reupload_rate)
    self.assertNotEqual(medical.type_precision, medical.doctamper_precision)

def test_manifest_contains_no_absolute_paths_or_real_reference_numbers(self):
    raw = FIXTURE_MANIFEST.read_text(encoding='utf-8')
    self.assertNotRegex(raw, r'[A-Za-z]:\\')
    self.assertNotRegex(raw, r'AICS-20\d{2}-[A-F0-9]{6}')

def test_missing_calibration_report_fails_closed(self):
    report = load_calibration_report(Path('missing-calibration.json'))
    self.assertFalse(profile_is_calibrated(get_profile('valid_id'), report))
```

- [ ] **Step 2: Run evaluation tests and verify the missing module fails**

Run: `python -m unittest ai_processor.tests.test_evaluation_metrics -v`

Expected: FAIL because `ai_processor.evaluation` does not exist.

- [ ] **Step 3: Implement deterministic metrics and the initial fixture manifest**

Manifest entries contain relative path, document profile, expected type decision, expected field states, expected application-match state, image condition, and optional DocTamper ground truth. Reject manifests with absolute paths or fields named `real_applicant`, `contact_number`, or `reference_no`.

Return Wilson-bound or exact-count metrics rather than a single overall accuracy. Serialize the report with `schema_version`, `processor_version`, generation timestamp, fixture-manifest SHA-256, and per-profile metrics. `calibration.py` rejects a report whose schema/processor version does not match. A profile is calibrated only if it meets its Task 1 `ValidationGate`; otherwise `profile_is_calibrated()` returns false and verification falls back to staff review. `processor.py` loads the configured report once per worker process and passes the resulting boolean to `evaluate(..., profile_calibrated=...)`.

Initial committed fixtures are synthetic and prove the harness. The committed `calibration-report.json` therefore records every profile as `calibrated: false`; it must never be hand-edited to bypass the metrics. Real de-identified evaluation images can be added only through a separately reviewed data process.

- [ ] **Step 4: Run evaluation and privacy tests**

Run: `python -m unittest ai_processor.tests.test_evaluation_metrics -v`

Expected: PASS.

Run: `python -m ai_processor.evaluation --manifest ai_processor/tests/fixtures/validation/manifest.json --format json`

Expected: exit 0 and JSON containing separate profile and DocTamper metrics; profiles below minimum case count report `calibrated: false`.

- [ ] **Step 5: Commit the evaluation harness**

```powershell
git add -- ai_processor/evaluation.py ai_processor/calibration.py ai_processor/config.py ai_processor/processor.py ai_processor/calibration-report.json ai_processor/tests/test_evaluation_metrics.py ai_processor/tests/fixtures/validation
git commit -m "test: add document verification evaluation harness"
```

---

### Task 7: Replace the Overall Confidence UI with Four Concise Assessments

**Files:**
- Modify: `Pswdo/php_files/pswdo-common.php:1-260`
- Modify: `Pswdo/php_files/request-management.php:683-890`
- Modify: `Pswdo/js files/pswdo-pages.js:382-490`
- Modify: `Pswdo/css files/pswdo-pages.css:6192-6372`
- Create: `tests/pswdo-ai-component-results-test.php`

**Interfaces:**
- Produces: `pswdo_ai_component_assessments(array $findings): array<string,array{state:string,label:string,explanation:string}>`.
- Consumes: `findings.assessments` from Tasks 4-5.
- Displays: Document match, Readability, Applicant match, Alteration signal.

- [ ] **Step 1: Write failing staff-facing UI contracts**

```php
component_assert(str_contains($page, 'Document match'), 'Document assessment is missing.');
component_assert(str_contains($page, 'Readability'), 'Readability assessment is missing.');
component_assert(str_contains($page, 'Applicant match'), 'Applicant comparison is missing.');
component_assert(str_contains($page, 'Alteration signal'), 'DocTamper assessment is missing.');
component_assert(!str_contains($page, 'Document Identification Confidence:'), 'Misleading overall confidence remains visible.');
component_assert(!str_contains(strtolower($page), 'proof of fraud'), 'UI overstates alteration evidence.');
```

- [ ] **Step 2: Run UI contract and verify it fails**

Run: `php tests/pswdo-ai-component-results-test.php`

Expected: FAIL because component labels are not rendered.

- [ ] **Step 3: Implement safe component mapping and compact cards**

Normalize missing/legacy findings to `unavailable` without inventing pass results. Render a two-by-two compact grid with icon, label, state pill, and one-sentence explanation. Keep technical numeric diagnostics inside the existing collapsed Technical Details area. In live JS rendering, rebuild only this grid from endpoint findings after a terminal update.

Use green for pass, amber for review, red only for a reliable fail/re-upload condition, blue for active scanning, and gray for unavailable/not applicable. Every state includes visible text.

- [ ] **Step 4: Run UI, syntax, and legacy result tests**

Run: `php tests/pswdo-ai-component-results-test.php`

Expected: PASS.

Run: `php tests/ai-unavailable-view-regression.php`

Expected: PASS for legacy/missing assessment data.

Run: `node --check "Pswdo/js files/pswdo-pages.js"`

Expected: no output and exit code 0.

- [ ] **Step 5: Commit the component result UI**

```powershell
git add -- Pswdo/php_files/pswdo-common.php Pswdo/php_files/request-management.php "Pswdo/js files/pswdo-pages.js" "Pswdo/css files/pswdo-pages.css" tests/pswdo-ai-component-results-test.php
git commit -m "feat: explain AI verification by component"
```

---

### Task 8: Run Full Accuracy, Security, and Workflow Verification

**Files:**
- Modify: `ai_processor/README.md:1-40`
- Modify: `ai_processor/DEPLOYMENT.md:1-220`
- Modify: `ai_processor/tamper_detection/ADCD-NET-OPERATIONS.md:1-220`

**Interfaces:**
- Documents all runtime, calibration, and failure semantics delivered in Tasks 1-7.

- [ ] **Step 1: Update operational documentation**

Document the four assessment meanings, profile calibration gates, evaluation command, loopback-only DocTamper service, ADCD degraded-mode behavior, and the rule that only PSWDO staff make workflow decisions. Replace the stale README claim that DocTamper is not implemented.

- [ ] **Step 2: Run all Python verification tests**

Run: `python -m unittest discover -s ai_processor/tests -p "test_*.py"`

Expected: all tests PASS with no OCR text or applicant identity values written to logs.

- [ ] **Step 3: Run PHP AI and security contracts**

Run:

```powershell
php tests/ai-auto-ensure-test.php
php tests/ai-backfill-recovery-test.php
php tests/ai-health-test.php
php tests/ai-review-reprocess-test.php
php tests/ai-unavailable-view-regression.php
php tests/pswdo-ai-artifact-security-test.php
php tests/pswdo-ai-details-modal-contract-test.php
php tests/pswdo-ai-signal-viewer-contract-test.php
php tests/pswdo-ai-component-results-test.php
```

Expected: every command exits 0.

- [ ] **Step 4: Run the synthetic calibration report**

Run: `python -m ai_processor.evaluation --manifest ai_processor/tests/fixtures/validation/manifest.json --format json`

Expected: exit 0; the output reports metrics per document profile and keeps under-sampled profiles at `calibrated: false` rather than claiming accuracy.

- [ ] **Step 5: Commit documentation and verified behavior**

```powershell
git add -- ai_processor/README.md ai_processor/DEPLOYMENT.md ai_processor/tamper_detection/ADCD-NET-OPERATIONS.md
git commit -m "docs: document calibrated local AI verification"
```
