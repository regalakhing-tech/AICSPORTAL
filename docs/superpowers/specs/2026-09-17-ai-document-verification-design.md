# AI-Assisted Document Verification Design

**Date:** 2026-09-17  
**Status:** Approved for implementation planning  
**Scope:** Applicant uploads, local verification worker, DocTamper/ADCD-Net analysis, PSWDO request table, and staff review details

## Objective

Make document verification begin immediately after a successful upload, communicate live progress clearly in the PSWDO request table, and improve the reliability and explainability of the local verification pipeline. AI output remains advisory: PSWDO personnel always make the final approval, rejection, and document-review decisions.

## Design Principles

- Never allow an automated result to approve or reject an application.
- Prefer an explicit uncertain result over an inaccurate confident result.
- Keep uploaded documents, OCR output, and image-analysis artifacts local and access-controlled.
- Separate different kinds of evidence instead of presenting one misleading confidence score.
- Make queued, active, completed, and failed states visible without requiring a page refresh.
- Preserve historical results when a document is replaced or manually reprocessed.

## Selected Approach

Use a local hybrid pipeline:

1. Image/PDF normalization and quality analysis.
2. OCR with document-specific preprocessing and bounded retries.
3. Expected-slot document classification.
4. Structured field extraction and comparison with application data.
5. DocTamper/ADCD-Net alteration-signal analysis.
6. Conservative rules that produce an advisory outcome and explainable findings.

This approach extends the current PHP queue and Python worker instead of introducing an external cloud service or attempting to train a new end-to-end model without a sufficiently large labeled dataset.

## Verification Lifecycle

Each current uploaded document has one active verification record and may have archived historical records. The active record uses these stages:

- `queued`: persisted and waiting for a worker.
- `preprocessing`: validating and normalizing the file, rendering PDF pages, and correcting orientation/contrast.
- `ocr`: reading text and document anchors.
- `verifying`: classifying the document, extracting fields, and comparing application data.
- `tamper_scan`: running DocTamper/ADCD-Net and persisting private artifacts.
- `completed`: terminal result saved successfully.
- `failed`: terminal technical failure with a safe retry path.

The advisory outcome is stored separately from the processing stage:

- `likely_matched`: checks found no material issue, but staff confirmation remains required.
- `needs_staff_review`: information is uncertain, incomplete, or requires human judgment.
- `needs_reupload`: the image is unreadable, blank, corrupt, or clearly belongs in a different upload slot.
- `flagged_inconsistency`: a reliable extracted identity or document field conflicts with trusted application data.
- `analysis_unavailable`: one or more required analysis components could not produce a reliable result.

Separating stage from outcome prevents a result such as `needs_staff_review` from being confused with a job that is still running.

## Upload and Queue Behavior

After the upload transaction commits, the application creates or ensures verification jobs only for the newly uploaded document IDs and dispatches a detached request worker. Upload success is independent of worker startup: a failed launch must not make a successfully stored upload appear unsuccessful.

Full submissions may queue several documents, but a request-scoped lease allows only one sequential worker for that request. Re-uploading a single document queues only the replacement document. Existing terminal results for other documents remain unchanged.

Jobs are idempotent and protected by:

- request-scoped worker leases;
- atomic claim updates;
- attempt limits;
- heartbeat or stage timestamps;
- stale-job recovery;
- explicit launch and processing failure codes.

## PSWDO Request Table Experience

The request table shows a compact request-level verification summary in the Documents column.

### Active scan state

- Apply a subtle blue background and left accent to only the rows with active verification work.
- Show an animated spinner and a short label such as `Scanning 1 of 3`.
- Show a thin progress bar based on terminal documents divided by verifiable documents.
- Keep the View button enabled so staff can inspect the request while scanning continues.
- Use accessible text and `aria-live="polite"`; color is never the only indicator.

### Terminal states

- `AI results ready` when every verifiable document is terminal.
- `2 need review` when advisory findings require staff attention.
- `Re-upload needed` when one or more documents cannot be reliably reviewed.
- `Scan unavailable` with a Retry action when processing failed.
- `No verifiable files` for unsupported document slots.

The page-level indicator reports useful aggregate status, for example `Scanning 4 documents`, `AI ready`, or `AI service unavailable`. It does not display the vague global label `Checking AI...` indefinitely.

## Live Status Updates

Add a request-level status response containing:

- request ID;
- total, queued, active, terminal, attention, and failed counts;
- current stages per document;
- safe labels and summaries;
- latest update timestamp;
- whether retry is allowed.

The browser polls only requests with queued or active work, updates the affected row in place, and stops polling once the request becomes terminal. Polling uses a moderate interval, prevents duplicate in-flight requests, applies backoff after network failures, and pauses when the tab is hidden. Reloading the page reconstructs the same state from the database.

## Accuracy and Reliability

### Image and PDF preparation

- Validate actual MIME type and reject corrupt content safely.
- Correct orientation using metadata and OCR orientation detection.
- Generate controlled grayscale, contrast-enhanced, and thresholded variants.
- Detect blank, cropped, very dark, overexposed, and heavily blurred pages.
- Preserve the original file and use derived images only for analysis.

### OCR and field extraction

- Use document-specific page and region strategies rather than one generic OCR pass.
- Compare alternate OCR variants and retain evidence for the selected value.
- Normalize Philippine names, common date formats, phone formats, and address text.
- Require document anchors before accepting fields from a valid ID or certificate profile.
- Mark missing and uncertain fields explicitly; never fabricate values.

### Document classification

- Compare the upload against its expected requirement slot.
- Use document-specific anchors and negative evidence.
- Treat close or conflicting classifications as uncertain.
- Require stronger evidence before reporting a wrong-slot re-upload recommendation.

### Application consistency

- Compare stable identity fields such as beneficiary name, date of birth, and sex.
- Treat address differences as corroborating context, not an automatic contradiction.
- Retain field-level match evidence and normalized values for staff inspection.
- Keep comparisons explainable and document-specific.

### DocTamper/ADCD-Net

- Run the configured local DocTamper/ADCD-Net model after OCR preparation.
- Store aggregate and page-level alteration signals separately from OCR confidence.
- Treat alteration output as a review signal, never proof of fraud.
- If DocTamper is unavailable, retain the OCR and consistency result but mark alteration analysis unavailable.
- Keep heatmaps, thresholds, and derived artifacts private and serve them only through authorized endpoints.

### Confidence presentation

Do not combine unrelated signals into one percentage. Present four independent assessments:

- Document match
- Readability
- Applicant-data match
- Alteration signal

Internal numeric values may be retained for evaluation and diagnostics, but staff-facing labels use plain language and an explanation of what was checked.

## Accuracy Validation

Create a de-identified labeled validation set grouped by document type and image condition. Medical documents—Valid ID, Medical Certificate, and Billing Statement/Prescription—receive the first and strictest validation pass, followed by every other supported requirement type.

For each document type, measure:

- document-type precision and recall;
- field extraction accuracy by field;
- identity-match false-positive and false-negative rates;
- re-upload recommendation precision;
- DocTamper false-positive and false-negative rates separately;
- processing completion and timeout rates.

An outcome may display `Likely matched` only after its document profile meets the configured validation threshold. Profiles without enough validated evidence always fall back to `Needs staff review`.

## Error Handling

- A successful upload remains successful even if worker dispatch fails.
- Queue-launch failures become `failed` with a retryable error code.
- A heartbeat/stage timeout converts abandoned work into a terminal unavailable state.
- Component failures identify the failed branch without exposing paths, commands, OCR text, or stack traces.
- Retrying archives the previous terminal result before resetting the active record.
- Staff review controls stay usable while AI is unavailable.

## Data Model Changes

Extend the active review record with processing-stage and progress metadata, including:

- `processing_stage`;
- `progress_percent`;
- `stage_updated_at`;
- optional worker heartbeat timestamp;
- processor/model version;
- structured component assessments.

Keep the advisory outcome in the existing status field during migration, or introduce a dedicated `outcome` field if compatibility analysis shows this is safer. Existing status values must be migrated without discarding completed findings. Schema creation and migration remain idempotent for existing XAMPP installations.

## Security and Privacy

- Continue resolving files only beneath the approved upload root.
- Never return absolute paths or raw worker errors to the browser.
- Require authenticated PSWDO access for statuses and derived artifacts.
- Avoid logging OCR text, applicant identity fields, or document images.
- Use CSRF protection for retry and reprocess actions.
- Keep all processing local; no applicant document is sent to an external AI service.

## Testing Strategy

### PHP contract and integration tests

- Upload commit creates jobs and dispatches only the intended request/documents.
- Re-upload queues only the replacement document.
- Launch failure does not roll back or misreport upload success.
- Request-level status counts and labels are correct.
- Retry authorization, CSRF protection, and history preservation work.
- Existing manual document-review state is never modified by AI.

### Python unit and regression tests

- Stage transitions occur in order and terminate correctly.
- Orientation, contrast, blur, blank-page, and multi-page fixtures behave consistently.
- Each document profile has positive, wrong-slot, uncertain, and unreadable fixtures.
- Field normalization and consistency comparisons cover representative Philippine formats.
- DocTamper results remain independent of OCR/outcome decisions.
- Exceptions persist safe failure codes without leaking sensitive data.

### Browser/UI tests

- Newly queued rows become blue and show an accessible spinner.
- Progress updates without a full refresh and polling stops at terminal state.
- Multiple scanning requests update independently.
- Network and worker failures exit the loading state and show Retry.
- View remains clickable during scanning.
- Table remains usable at supported desktop widths without hiding the Action column.

## Delivery Sequence

1. Add lifecycle schema and request-level status contract with regression tests.
2. Trigger queueing immediately after full upload and single-document re-upload.
3. Add worker stage reporting, timeouts, and safe failure handling.
4. Redesign the PSWDO table’s live scanning states and polling.
5. Improve preprocessing, OCR profiles, classification, and field comparison.
6. Integrate and validate DocTamper/ADCD-Net as a separate evidence branch.
7. Build the labeled evaluation harness and calibrate per-document thresholds.
8. Run end-to-end, security, UI, and accuracy regression suites.

## Acceptance Criteria

- Uploading a supported document automatically queues verification after commit.
- PSWDO sees queued/scanning progress in blue without opening the request or refreshing.
- No row remains in a loading state after a terminal success, failure, or timeout.
- Staff can inspect component results and concise explanations.
- DocTamper is used only as an alteration signal and never automatically rejects a document.
- Automated results never change application or staff-review decisions.
- Every supported document type has fixtures and recorded evaluation metrics before it can display `Likely matched`.
