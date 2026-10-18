# Gemini-Assisted Document Alteration Detection Design

Date: 2026-09-29

## 1. Purpose

Extend the existing document-verification pipeline so Gemini can contribute structured visual evidence about possible document alteration. The feature must detect cases such as an edited date rendered with inconsistent typography while remaining conservative enough not to accuse an applicant of forgery or automatically reject an application.

Gemini remains one advisory component. Its observations are combined with deterministic chronology checks, local image measurements, OCR evidence, and the existing ADCD alteration analyzer. A sufficiently strong combined signal prevents automatic clearance and routes the document to staff review.

## 2. Goals

- Detect visually inconsistent inserted or replaced text, especially dates, names, amounts, reference numbers, signatures, and stamp areas.
- Detect internal chronology conflicts, such as a prominent certification date that is implausibly inconsistent with repeated event dates.
- Identify the affected page and region so staff can inspect the original document.
- Combine independent evidence sources rather than treating a language-model opinion as proof.
- Use the existing `needs_staff_review` workflow status to prevent automatic clearance.
- Display the neutral label `Suspected alteration — staff review required` when the aggregation threshold is met.
- Continue verification when Gemini or ADCD is unavailable and report the unavailable component accurately.
- Preserve privacy, bounded payloads, strict schemas, caching, timeouts, and circuit-breaker behavior.

## 3. Non-goals

- Automatically declaring a document fake, forged, fraudulent, or authentic.
- Automatically rejecting an application.
- Automatically requesting a new upload solely because a tamper signal exists.
- Treating a different font by itself as sufficient evidence of alteration.
- Replacing ADCD, OCR, deterministic cross-referencing, staff judgment, or issuer verification.
- Training or fine-tuning a new forensic model in this change.

## 4. Threat Model and Important Limitations

The feature targets common image-level and content-level edits:

- text pasted over an existing date, name, amount, or reference number;
- inconsistent font family, weight, size, character spacing, baseline, or stroke width within a comparable field;
- edge halos, rectangular boundaries, local blur or sharpness changes, and background texture discontinuity;
- different ink or foreground color in a field that should be visually consistent;
- local compression or resampling differences around edited text;
- a date or value that contradicts other reliable content in the same document;
- signature, stamp, or issuer-field anomalies that require human inspection.

Legitimate documents often contain several fonts, pre-printed text, typed entries, handwriting, stamps, photocopy artifacts, shadows, folds, and camera perspective. Consequently, typography differences are only meaningful when compared within the same semantic field or against nearby peer entries. No single weak visual difference may trigger an alteration finding.

## 5. Approaches Considered

### 5.1 Prompt-only Gemini change

This is the smallest change but is rejected as the primary design. A free-form or broad `visual_integrity` response cannot provide reliable regions, typed evidence, deterministic validation, or defensible aggregation.

### 5.2 Hybrid structured analysis — selected

Gemini returns bounded visual indicators and regions. Local code validates the response, performs deterministic chronology and regional image checks, and combines it with ADCD. This reuses the current architecture while adding independent corroboration and explicit false-positive controls.

### 5.3 New specialized forensic model

A specialized trained model could eventually improve pixel-level performance, but it requires a representative, authorized, calibrated dataset of clean and altered Philippine assistance documents. That work is outside this change. The current design keeps a clear provider interface so a calibrated model can be added later.

## 6. Architecture

The existing processing order remains:

1. Load and normalize document pages.
2. Run OCR, classification, field extraction, and local verification.
3. Send bounded page images and structured evidence to Gemini when configured.
4. Validate Gemini's structured response.
5. Run deterministic chronology and regional visual checks.
6. Run the existing ADCD alteration analyzer.
7. Aggregate independent alteration evidence.
8. Persist the advisory evidence and update the review recommendation.
9. Show staff the summary and affected regions.

The new aggregation occurs after both Gemini and ADCD results exist. This corrects the current ordering in which Gemini is attached before ADCD and neither component can produce a combined alteration recommendation.

## 7. Gemini Evidence Contract

The external assessment schema advances to `external-ai-assessment-v2`. Existing document quality, document type, field extraction, beneficiary cross-reference, signature review, and visual integrity fields remain. A required `tamper_assessment` object is added for image-capable Gemini responses.

```json
{
  "tamper_assessment": {
    "state": "no_signal | review_signal | unavailable",
    "confidence_band": "low | moderate | high",
    "summary": "short neutral explanation",
    "indicators": [
      {
        "type": "typography_mismatch",
        "page": 1,
        "box_2d": [780, 520, 835, 790],
        "confidence_band": "moderate",
        "reason": "Date text differs from nearby filled fields in weight and edge sharpness."
      }
    ]
  }
}
```

Allowed indicator types are:

- `typography_mismatch`;
- `baseline_or_spacing_mismatch`;
- `ink_or_color_mismatch`;
- `pasted_region_boundary`;
- `background_texture_discontinuity`;
- `compression_or_resampling_difference`;
- `date_context_conflict`;
- `value_context_conflict`;
- `signature_or_stamp_anomaly`;
- `other_visual_anomaly`.

`box_2d` uses Gemini's normalized `[ymin, xmin, ymax, xmax]` coordinates from 0 through 1000. The contract accepts at most twelve indicators, reasons are limited to 240 characters, page numbers must reference supplied pages, and coordinates must form a non-empty in-bounds rectangle.

For text-only provider calls, tamper assessment is forcibly normalized to `unavailable`; OCR text alone cannot establish visual alteration. A schema-invalid assessment makes only the external component unavailable and does not fail local verification.

The prompt must explicitly require comparison against appropriate nearby peer text, not the document's pre-printed template as a whole. It must prohibit conclusions of forgery, fraud, authenticity, or applicant intent.

## 8. Deterministic Local Evidence

### 8.1 Chronology analysis

The system extracts labeled and high-confidence dates with their source field and page. Document-profile rules distinguish event dates, admission or confinement ranges, issue dates, certification dates, and signature dates.

The analyzer emits `date_context_conflict` only for a rule-defined inconsistency. A later issue date is not automatically suspicious. Examples that warrant review include:

- an issue or signature date that predates the event it certifies where the document type disallows that order;
- mutually incompatible date ranges;
- a current-looking edited date that is separated from all related event dates by an unusually long interval and is also supported by a localized visual anomaly;
- multiple occurrences of the same labeled date disagreeing within one document.

Chronology evidence never produces a factual fraud conclusion.

### 8.2 Regional typography and pixel analysis

Using OCR word boxes and the original normalized page, local analysis computes bounded measurements for security-sensitive fields:

- median character or word height;
- baseline and angle;
- inter-character and inter-word spacing where measurable;
- foreground color and grayscale distribution;
- edge sharpness and stroke-width proxies;
- local noise and background residual;
- JPEG block or resampling discontinuity around the field boundary.

Measurements are compared with nearby fields of the same role and rendering mode. Printed template text is not used as the sole reference for handwritten or subsequently typed entries. The analyzer returns only normalized differences and confidence bands; raw crops remain private artifacts under the existing access controls.

## 9. Evidence Aggregation

All component outputs normalize to `no_strong_signal`, `review_signal`, or `unavailable`. Evidence is grouped into independent families:

- semantic chronology or value consistency;
- Gemini visual review;
- local regional typography or pixel analysis;
- ADCD alteration analysis.

The document receives `Suspected alteration — staff review required` when any of these rules holds:

1. ADCD returns a calibrated high-confidence review signal and at least one other family supplies moderate corroboration.
2. Gemini identifies a localized high-confidence pasted-region or texture-boundary anomaly and local regional analysis corroborates that location.
3. A deterministic chronology or value conflict is accompanied by a localized moderate-or-higher visual anomaly.
4. At least two independent visual families return moderate-or-higher review signals for overlapping or directly related regions.

A single typography difference, a single Gemini opinion, a signature merely being absent, or an unavailable component cannot meet the threshold.

When the threshold is met:

- the component assessment `alteration_signal` becomes `review`;
- the overall AI review status becomes `needs_staff_review` unless a higher-priority `needs_reupload` or reliable identity/document mismatch status already applies;
- the neutral reason is appended to findings;
- the document cannot be treated as automatically cleared;
- no application is rejected and no re-upload request is sent automatically.

If evidence is suspicious but below the threshold, it remains visible as a low-confidence advisory observation without changing the overall status.

## 10. Persistence and Backward Compatibility

The canonical combined result remains under `findings.tamper_detection`. It gains versioned provider evidence and an aggregation summary while preserving the existing top-level status values used by PHP and JavaScript.

Gemini's validated assessment remains under `findings.external_ai.assessment.tamper_assessment`. ADCD artifacts continue using private artifact storage. Bounding boxes and short neutral reasons may be persisted; full prompts, provider raw responses, image data URLs, and unrestricted crops may not be persisted in public findings.

Existing records without the new fields render as `Not analyzed` or `Unavailable`. No destructive database migration is required. The external prompt version and cache key version must change so prior cached v1 assessments are not reused as v2 tamper results.

## 11. Staff Interface

The PSWDO review interface adds a `Document alteration review` section containing:

- the combined state and confidence;
- the neutral staff-review label when escalated;
- each indicator's plain-language type, page, confidence, and reason;
- provider availability for Gemini, local regional analysis, and ADCD;
- links to existing authorized artifact or original-document viewers when available;
- a permanent note that the findings are advisory and require human confirmation.

The interface must not display `forged`, `fraudulent`, `fake`, or `authentic` as a machine conclusion. It must not expose hidden conflicting identity values, raw provider responses, filesystem paths, credentials, or unrestricted image data.

## 12. Failure Handling and Security

- Gemini timeout, authentication failure, invalid schema, or rate limiting leaves local and ADCD verification operational.
- ADCD failure leaves Gemini and local analysis operational.
- A component marked unavailable contributes no positive or negative evidence.
- Only image-capable calls can produce Gemini visual indicators.
- Every external value is schema-validated, length-bounded, enumerated, and safely rendered.
- Bounding boxes are range-checked before persistence or display.
- Cache keys include document hash, provider model, prompt version, schema version, and image payload mode.
- Logs contain only privacy-safe status, timing, and error codes.
- Staff-visible reasons are HTML-escaped and never interpreted as markup.

## 13. Testing and Evaluation

Implementation follows test-driven development.

Unit and contract tests cover:

- valid and invalid v2 Gemini tamper schemas;
- coordinate, page, enum, length, and indicator-count validation;
- text-only responses forced to `unavailable`;
- prompt prohibitions and required evidence instructions;
- cache invalidation after the prompt/schema version change;
- chronology rules, including legitimate later issue dates and genuine contradictions;
- aggregation thresholds and independence requirements;
- preservation of stronger existing workflow statuses;
- provider failure isolation and privacy-safe persistence;
- safe PSWDO rendering of the combined result.

Image evaluation uses authorized or synthetic fixtures representing:

- a clean multi-font form;
- a changed date with different typography;
- a date pasted using visually matching typography;
- edited names, amounts, and reference numbers;
- changed foreground color or sharpness;
- rectangular pasted regions and texture discontinuities;
- camera shadows, folds, skew, blur, photocopies, and compression without alteration;
- legitimate stamps, handwriting, and mixed pre-printed/typed fields.

The sample class described by the user must produce staff review only when the date-context inconsistency is accompanied by a corroborating localized visual signal. Clean multi-font documents must not be escalated solely because fonts differ.

## 14. Expected Implementation Areas

- `ai_processor/external_ai/client.py`: v2 prompt, reason codes, and JSON schema.
- `ai_processor/external_ai/contracts.py`: strict normalization and validation.
- `ai_processor/external_ai/service.py`: prompt/cache version update and image-mode rules.
- `ai_processor/external_ai/postprocessing.py`: safe attachment without premature workflow escalation.
- `ai_processor/tamper_detection/`: chronology, regional evidence, and aggregation modules.
- `ai_processor/processor.py`: reorder final alteration aggregation after Gemini and ADCD.
- `Pswdo/php_files/request-management.php` and shared PSWDO JavaScript/CSS: staff-facing evidence summary.
- `ai_processor/tests/` and `tests/`: unit, integration, privacy, and UI regressions.

## 15. Acceptance Criteria

- The changed-date case can be escalated using at least two independent, inspectable evidence families.
- Every escalated finding identifies a page and reason; visual findings include a valid region when the provider supplies one.
- Font variation alone does not escalate a document.
- No AI result automatically rejects an application, requests a re-upload, or declares forgery.
- A strong combined result prevents automatic clearance and requires staff review.
- Provider failures do not break local verification.
- Existing extraction, beneficiary cross-reference, release, and review workflows remain compatible.
- All new and existing relevant automated tests pass.
