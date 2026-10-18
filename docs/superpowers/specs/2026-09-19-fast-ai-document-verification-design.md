# Fast Hybrid AI Document Verification Upgrade — Design Specification

**Date:** 2026-09-19

**Status:** Proposed for implementation
**Scope:** Improve the existing document-verification pipeline with fast local analysis and a conditional external-AI second opinion, without collecting or training on a new dataset.

## 1. Objective

Improve the reliability, clarity, and operational usefulness of AICS document verification while keeping applicant submission fast and preserving PSWDO staff as the final decision-maker.

The upgrade must:

- detect objectively poor uploads before weak OCR creates misleading inconsistencies;
- improve OCR through a bounded, document-aware multi-pass process;
- make document type, readability, identity consistency, and alteration analysis independent results;
- provide actionable, human-readable reasons and evidence;
- use an external model only when local evidence is uncertain, with image input enabled only after a successful capability test;
- collect staff feedback for evaluation and auditing, not automatic model training;
- remain functional when either the external AI or local alteration-analysis service is unavailable;
- never automatically approve, reject, or accuse an applicant of submitting a fake document.

## 2. Non-goals

- Training or fine-tuning Tesseract, DocTamper/ADCD, or another model.
- Creating a labeled training dataset.
- Replacing PSWDO review with an automated eligibility decision.
- Sending a re-upload SMS without staff confirmation.
- Making an external API a prerequisite for document verification.
- Using a language model as the authoritative duplicate detector or fraud detector.
- Sending every clear document to the external API.
- Claiming a scientifically validated accuracy percentage without a representative labeled evaluation set.

## 3. Recommended Architecture

The existing queue-first lifecycle remains unchanged at the applicant boundary:

```text
Applicant upload
  -> save files and queue database rows
  -> return response immediately

PSWDO background worker
  -> render and cache document pages once
  -> quality gate
  -> fast OCR pass
  -> conditional enhanced OCR passes
  -> document-type assessment
  -> field extraction and validation
  -> cross-document consistency
  -> conditional external-AI second opinion
  -> bounded alteration analysis
  -> conservative policy aggregation
  -> persist versioned findings
  -> update PSWDO live UI
```

Each stage must be independently testable and must return structured evidence rather than mutating the final status directly. A policy aggregator is the only component allowed to derive the suggested action.

## 4. Performance Design

### 4.1 Applicant response

Applicant submission and re-upload routes must perform database-only queueing. They must not launch Python, render PDFs, run OCR, or wait for the alteration service.

### 4.2 Fast path and enhanced path

Every page starts with one fast OCR pass on a normalized image. Enhanced variants run only when at least one of these is true:

- OCR confidence is below the configured reliable threshold;
- required fields are absent or uncertain;
- document-type evidence is ambiguous;
- image-quality checks indicate a correctable issue such as skew or uneven lighting.

The enhanced path may run at most two additional variants. It must stop early when two candidates agree on reliable normalized fields.

### 4.3 Bounded work

- Render each PDF page only once per pipeline version.
- Reuse rendered pages for quality, OCR, and alteration analysis.
- Cache preprocessing artifacts by file hash, page number, pipeline version, and strategy.
- Process documents sequentially within the existing request worker to protect a school-project computer from CPU and memory spikes.
- Permit at most two bounded tasks inside one document when alteration analysis can safely overlap OCR.
- Apply configurable per-stage and per-document timeouts.
- Limit external-AI calls to one request and at most one retry for a transient transport failure.
- Convert a timeout into an `unavailable` component, not a failed applicant submission.
- Do not retry a deterministic unreadable image repeatedly.

Initial configurable budgets will be conservative and measured on the deployment computer. The implementation must expose elapsed milliseconds per stage so the budgets can be tuned using evidence rather than guesses.

### 4.4 Rescan behavior

A normal rescan reuses cached rendering but recomputes verification with the current pipeline version. A forced diagnostic rescan may bypass the cache and must be an explicit staff action.

### 4.5 Performance objectives

The implementation will enforce these initial operational objectives and expose them in the evaluation report:

- Applicant submission performs only the request transaction and queue insert; it has no AI-process startup cost.
- Queue polling and UI progress requests return quickly and never wait for an active scan to finish.
- A normal one-page image should complete the fast path within 10 seconds on the deployment computer.
- A one-page enhanced scan should stop within a 25-second document budget.
- External AI has a 12-second total budget and cannot extend the applicant request or create an unbounded worker delay.
- External alteration analysis has its own timeout and cannot extend the OCR budget.
- Multi-page documents receive a bounded total budget derived from the page count, with a configurable hard ceiling.

The worker records actual median and p95 timings. If the deployment computer cannot meet an objective, staff still receive a usable partial result at the timeout, and the measured timings are used to tune thresholds without increasing applicant wait time.

## 5. Image Quality Gate

Extend the existing quality analysis to produce normalized facts and reason codes for every page:

- resolution and effective text scale;
- blur severity;
- brightness, darkness, and contrast;
- glare or clipped highlights;
- blank/near-blank content;
- skew angle;
- perspective distortion;
- likely cropped document boundaries;
- foreground/text coverage.

The quality component returns one of:

- `readable` — normal verification continues;
- `correctable` — enhanced preprocessing may continue;
- `reupload_recommended` — OCR results may be retained as diagnostic evidence but cannot create a reliable mismatch;
- `unavailable` — technical analysis failed.

Reason codes include `IMAGE_BLURRED`, `IMAGE_TOO_DARK`, `GLARE_DETECTED`, `DOCUMENT_CROPPED`, `RESOLUTION_TOO_LOW`, `PAGE_BLANK`, and `PERSPECTIVE_SEVERE`.

## 6. Multi-pass OCR and Consensus

The OCR engine will support named preprocessing strategies:

1. normalized original;
2. grayscale plus contrast normalization;
3. adaptive threshold for uneven lighting;
4. deskewed/perspective-corrected image when quality evidence requires it;
5. document-profile regions of interest for key fields.

The fast path uses strategy 1. Strategies 2–5 are selected conditionally, never all by default.

Every OCR candidate records:

- strategy identifier;
- page segmentation mode;
- mean confidence;
- coherent word ratio;
- extracted-field reliability;
- elapsed milliseconds.

Consensus is field-based. A value becomes reliable when independent candidates normalize to the same value and meet field-specific confidence rules. Conflicting candidates produce `uncertain`; the system must not choose the longest or highest-confidence string blindly.

Raw OCR text must not be written to normal application logs. Persist only the minimum evidence needed for staff review and debugging.

## 7. External-AI Second Opinion

### 7.1 Role and invocation policy

The external model is an optional advisory component. The worker invokes it only when local analysis produces at least one of these conditions:

- ambiguous document type;
- missing or conflicting required fields after bounded OCR enhancement;
- uncertain applicant/document identity consistency;
- a staff-requested diagnostic rescan.

Clear documents complete locally. The external result cannot replace objective image-quality measurements, local database duplicate matching, or DocTamper/ADCD alteration analysis.

### 7.2 API contract

The client uses the configured OpenAI-compatible chat-completions endpoint and model. Initial deployment values are:

- endpoint: `https://api.groq.com/openai/v1/chat/completions`;
- model: `openai/gpt-oss-20b`;
- total request budget: 12 seconds across the initial attempt and any retry;
- retry count: one, only for a timeout, connection reset, HTTP 429, or HTTP 5xx response.

The endpoint and model remain configuration values because they are third-party capabilities, not application invariants. The client requires strict JSON output containing a schema version, document-type assessment, extracted field candidates, consistency observations, confidence bands, and short reason codes. Free-form prose is never treated as a decision.

### 7.3 Image capability and payload minimization

The supplied example proves only text chat compatibility. Before document images are enabled, a controlled capability probe must demonstrate that the endpoint/model accepts image content and returns schema-valid results. Until then, the external model receives bounded OCR text and structured local findings only.

When image capability is explicitly enabled, the worker sends only the minimum normalized page or cropped key-field regions needed for the uncertain check. It does not send unrelated sibling documents or a complete database record. Prototype testing must use synthetic or authorized documents; deployment with real applicant documents requires PSWDO approval of the third-party processor.

### 7.4 Duplicate handling

Duplicate detection remains a deterministic local database operation using normalized applicant and beneficiary identity data. The external model may explain an already-selected candidate comparison or mark it uncertain, but it cannot create a `confirmed duplicate` state. A possible duplicate remains a staff-review flag.

### 7.5 Secrets and configuration

The exposed credential must be revoked and replaced. The replacement API credential is stored only in `C:\xampp\aics_private\aics-ai.env`, outside the web root. The worker loads this server-only file before constructing settings. The file is never committed, returned to the browser, displayed in health responses, or written to logs.

Supported settings are:

- `AICS_EXTERNAL_AI_ENABLED`;
- `AICS_EXTERNAL_AI_ENDPOINT`;
- `AICS_EXTERNAL_AI_MODEL`;
- `AICS_EXTERNAL_AI_API_KEY`;
- `AICS_EXTERNAL_AI_TIMEOUT_SECONDS`;
- `AICS_EXTERNAL_AI_IMAGE_ENABLED`.

Startup validation reports only `configured`, `disabled`, or a privacy-safe error code. A missing or invalid key disables the external component without disabling local verification.

### 7.6 Reliability controls

Results are cached by document hash, model, prompt/schema version, and payload mode. Three consecutive transient failures open a five-minute circuit breaker so subsequent documents immediately use the local fallback. Authentication, permission, invalid-model, and invalid-schema errors do not retry. The worker records timing, status, and privacy-safe error codes without recording the credential, authorization header, raw document, or full prompt.

## 8. Document-type Assessment

Document classification remains conservative and evidence-based. Each document profile defines:

- required and supporting phrases;
- structural labels and expected sections;
- issuer terminology;
- date/reference-number formats;
- competing-document negative evidence;
- optional regions of interest.

The component returns:

- `match`;
- `uncertain`;
- `mismatch`;
- `unavailable`.

Low-quality OCR cannot produce a definitive mismatch. A mismatch requires reliable competing evidence or reliable absence of required structural evidence after the enhanced OCR path.

## 9. Field Extraction and Validation

Field extraction will remain profile-specific and use shared normalization for:

- Philippine personal names, middle names, and suffixes;
- dates and age consistency;
- Antique municipalities and address tokens;
- document/reference numbers;
- assistance-specific dates and facts.

Each field result stores `value`, `normalized`, `status`, `confidence`, `source_page`, `source_strategy`, and a privacy-safe reason. Impossible dates, future issue dates where disallowed, label fragments, and OCR debris are rejected before consistency checking.

Missing or low-confidence values are `uncertain`, never `mismatch`.

## 10. Cross-document Consistency

Consistency checks compare reliable normalized fields against the immutable application snapshot and reliable sibling documents.

Rules must:

- ignore capitalization, punctuation, and harmless spacing;
- normalize suffixes and middle-name variations;
- use bounded fuzzy matching only for OCR-tolerant name/address fields;
- require exact normalized agreement for high-risk fields such as a complete birth date when both values are reliable;
- distinguish `not_present`, `uncertain`, `match`, and `mismatch`;
- show which documents or form fields were compared without exposing sensitive values in logs.

One unreliable source cannot overrule a reliable source.

## 11. Alteration Analysis

DocTamper/ADCD remains an independent advisory component. It returns:

- `no_strong_signal`;
- `review_signal`;
- `unavailable`.

It must never return or display `fake`, `fraudulent`, or an equivalent factual accusation. Heatmaps and threshold artifacts remain available for staff inspection with an explicit disclaimer.

The alteration service has a strict timeout and circuit breaker. If it is unavailable, OCR and consistency verification still complete. Its failure must not degrade unrelated component results.

## 12. Conservative Policy Aggregation

The policy aggregator consumes component results and returns one suggested action:

- `ready_for_staff_review`;
- `staff_attention_needed`;
- `reupload_recommended`;
- `verification_unavailable`.

Priority rules:

1. Objective severe quality failure -> `reupload_recommended`.
2. Reliable document mismatch or reliable identity mismatch -> `staff_attention_needed`.
3. Alteration review signal -> `staff_attention_needed`.
4. All available components acceptable -> `ready_for_staff_review`.
5. Critical components unavailable with insufficient remaining evidence -> `verification_unavailable`.

The policy never changes the request's approval status. A PSWDO user must confirm a re-upload decision before an applicant notification is sent.

## 13. Persistence and Audit Model

Keep the current `document_ai_reviews` lifecycle and version the findings payload. Add only the schema needed for staff feedback and performance measurement.

### `document_ai_review_feedback`

- `id`
- `ai_review_id`
- `reviewed_by_account_id`
- `ai_suggested_action`
- `staff_outcome`
- `agreement_state` (`agreed`, `overridden`, `not_applicable`)
- `corrected_document_type` nullable
- `reason_code`
- `remarks` nullable
- `created_at`

The versioned findings JSON contains component states, privacy-safe evidence, selected OCR strategies, local and external provider/model versions, external payload mode (`text` or `image`), cache state, and stage timings. It must not contain credentials, authorization headers, full prompts, or raw image payloads. Historical results remain immutable and rescans continue to archive the previous result.

## 14. PSWDO User Experience

The existing blue scanning lifecycle remains. The completed document row/modal shows four compact component cards:

- Document match
- Readability
- Applicant information match
- Alteration signal

An additional compact provider line states `Local checks`, `External AI checked`, `External AI unavailable`, or `External AI not needed`. It must never imply that a document was externally image-scanned when only OCR text was sent.

Each card contains one state, one short explanation, and optional details. The main suggested action uses advisory wording. Staff can:

- open the original file;
- inspect extracted-versus-submitted fields;
- inspect alteration visualizations;
- rescan;
- confirm document acceptance;
- confirm re-upload and compose the applicant SMS;
- record an override reason.

No background scroll is allowed while a review modal is open, and live polling must remain non-blocking.

## 15. Evaluation and Operational Reporting

Extend the existing evaluation module and add a PSWDO report based on staff feedback:

- AI/staff agreement rate;
- override rate by component and document type;
- re-upload recommendation confirmation rate;
- document-type correction rate;
- alteration-signal confirmation rate;
- unavailable/failure rate;
- external-AI invocation, cache-hit, timeout, invalid-response, and circuit-breaker rates;
- external text-only versus image-assisted usage;
- median and p95 processing time by stage and document type;
- fast-path versus enhanced-path usage.

These are operational metrics, not validated model-accuracy claims. The UI must state that representative labeled evaluation data is still required for a formal accuracy figure.

## 16. Error Handling

- Quality/OCR failure: preserve the document, return an explicit component state, and keep manual review available.
- Alteration-service failure: mark only alteration analysis unavailable.
- External-AI failure: preserve the local result, mark only the external component unavailable, and never fail the document review.
- External-AI authentication/model/schema failure: do not retry; emit a privacy-safe operator code and use local verification.
- Timeout: stop the affected stage and persist a privacy-safe code.
- Worker interruption: existing lease/heartbeat recovery remains authoritative.
- Malformed cached artifact: discard and regenerate it once.
- Database feedback failure: do not lose the staff workflow decision; return a visible error before completing the action.

## 17. Testing Strategy

Implementation follows test-driven development.

- Unit tests for every quality reason, preprocessing selector, consensus rule, field validator, consistency comparison, policy branch, timeout, and circuit-breaker transition.
- Synthetic/generated image fixtures for blur, rotation, glare, darkness, crop, and perspective behavior. These fixtures are regression inputs, not training data.
- Existing privacy-safe OCR text fixtures for profile and field rules.
- Integration tests for queueing, processing stages, cache reuse, rescan history, component persistence, and feedback records.
- Contract tests for the external request schema, redacted logging, response validation, capability probe, timeouts, retry classification, cache keys, and circuit-breaker transitions. Network calls are mocked in automated tests.
- Contract and browser tests for the four-component UI, scanning progress, modal behavior, staff confirmation, and error states.
- Performance tests that report stage timing and fail on accidental unbounded candidate expansion, duplicate rendering, duplicate workers, or applicant-side worker execution.
- Full existing PHP, Python, database, and browser regression suites before completion.

## 18. Delivery Sequence

1. Performance instrumentation and bounded pipeline contracts.
2. Quality-gate reason codes and synthetic quality regressions.
3. Render/preprocessing cache and conditional OCR strategy selection.
4. Multi-pass OCR consensus and document-profile ROI support.
5. Field normalization and conservative consistency upgrades.
6. External-AI client, private environment loading, strict schema, and mocked contract tests.
7. Conditional invocation, result cache, timeout/retry policy, and circuit breaker.
8. Independent alteration timeout/circuit breaker and advisory wording.
9. Policy aggregator and versioned findings schema.
10. Staff feedback schema, actions, and audit history.
11. PSWDO component/provider UI and staff-confirmed re-upload workflow.
12. Operational evaluation/performance report.
13. End-to-end verification, documentation, and deployment instructions.

## 19. Acceptance Criteria

- Applicant submission never invokes or waits for the AI worker.
- Normal high-quality documents take the one-pass fast path.
- Enhanced OCR is capped at two additional variants.
- The same page is not rendered repeatedly within one pipeline version.
- Every stage publishes elapsed time and respects a configurable timeout.
- Clear documents do not invoke the external API.
- External-AI failure, timeout, invalid JSON, invalid model, or missing key cannot fail local verification.
- The external credential never appears in source control, browser responses, findings, logs, or test output.
- Image mode remains disabled until a controlled capability test succeeds.
- Duplicate status continues to come from deterministic local database matching and staff review, not the external model.
- Poor quality cannot become a reliable identity or type mismatch.
- Alteration analysis cannot approve, reject, or label a document fake.
- Re-upload messaging requires staff confirmation.
- Staff can review and override every AI suggestion with an audit reason.
- Existing rescan, history, queue, and live-progress behavior remains functional.
- Reports distinguish operational agreement metrics from formal model accuracy.
