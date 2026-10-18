# Gemini-Primary Document Scanner Design

Date: 2026-10-01

## 1. Purpose

Make Gemini vision the primary reader of every uploaded AICS document. It checks the five verification categories defined in Chapters 1 and 3 of the capstone paper (*AI-Enhanced Document Verification*). The result screen shows those five checks in plain language so PSWDO staff can act quickly.

The paper states that the feature "assists in checking uploaded documentary requirements for **incomplete, inconsistent, unreadable, altered, or potentially tampered** information. Documents that require further verification are flagged for review by authorized PSWDO personnel." AI results are decision support only.

## 2. Problems Found (evidence from `document_ai_reviews`)

1. Gemini never ran. `AICS_GEMINI_IMAGE_API_KEY` is read by `config.py`, but `private_env.py` does not allowlist it, so a key in `aics-ai.env` is silently dropped (`gemini_image_key_present: False`).
2. The "external AI" that did run is Groq `openai/gpt-oss-20b`. It reads only Tesseract OCR text (42% confidence on review #205) and never sees the image.
3. The external verdict cannot correct the document type. `apply_external_recommendation` ignores the AI's `document_type`, so the local keyword classifier's answer stays.
4. Review #205: a *Certificate of Death* (a sample from commercial-archive.com) for a different person was uploaded to the *Medical Certificate* slot. It was reported as "Certificate of Enrollment", and the name mismatch came back only as "uncertain".
5. Of the 15 most recent reviews, none passed. Valid IDs and billing statements were mostly "type unknown, 20%".

## 3. Decisions

| Decision | Choice |
|---|---|
| Gemini role | **Primary.** Gemini reads the page images and decides type, fields, and the five checks. Local OCR and forensics still run for corroboration and as the fallback. |
| Result view | **Thesis five-check card**: verdict banner, five-check scorecard, required vs detected type, document vs application table, then collapsed evidence and technical details. |
| Key handling | The key stays in `C:\xampp\aics_private\aics-ai.env`. The loader accepts `AICS_GEMINI_API_KEY`, `AICS_GEMINI_IMAGE_API_KEY`, `GEMINI_API_KEY`, and `GOOGLE_API_KEY`. The key is never logged, printed, or stored in findings. |
| Groq text provider | Kept only as a fallback when the Gemini scan is unavailable. It is skipped when Gemini completes. |

## 4. The Five Checks

| Key | Staff title | Fails / flags when |
|---|---|---|
| `complete` | Complete | Pages or key parts are missing or cut off, or required details (name, date, signature, issuer) are absent. |
| `consistent` | Consistent | Wrong document type for the slot, the person's name or birth date differs from the application, or dates and details contradict each other. |
| `readable` | Readable | Blur, glare, darkness, crop, or low resolution prevents reliable reading. |
| `unaltered` | No visible alterations | Erasures, white-out, overwriting, or handwritten corrections on typed values. |
| `untampered` | No signs of tampering | Pasted or cloned regions, font or ink mismatches on key values, sample/specimen/internet-watermark images, or corroborated local forensic signals. |

Each check has `state` (`pass` \| `review` \| `fail` \| `unavailable`), a short `label`, and a one-sentence `reason`.

## 5. Architecture

New package `ai_processor/gemini_scanner/`:

- `catalog.py`: the AICS document catalog (code, label, visual description, whose name must match the beneficiary). This is the single source for both the prompt and the verdict.
- `prompt.py`: system instruction and strict response schema (Gemini Schema subset, enums for document type and indicator types).
- `contract.py`: validates and bounds Gemini JSON, rejects unknown enums, clamps boxes to 0–1000, trims text, and strips accusatory words (forged, fraud, fake, authentic).
- `client.py`: minimal Gemini `generateContent` client with model fallback (`gemini-2.5-flash` → `gemini-2.5-flash-lite`), bounded timeout, and error codes. The API key goes in the `x-goog-api-key` header only.
- `service.py`: `scan_document(...)` builds the images, applies the cache and circuit breaker (reusing `ExternalAiStateStore`), and calls the client. Failures return `{'status': 'unavailable', 'error_code': ...}` and never raise.
- `verdict.py`: `build_verdict(...)` combines the Gemini scan, the local result, and the combined tamper result into the five checks, the overall status, a detected type, a confidence, and a field comparison. Name and birth-date comparisons are deterministic (`name_match_details`, `normalize_date`). The model's opinion never overrides them.
- `visual_adapter`: `as_visual_result(scan)` converts Gemini alteration and tampering indicators into the shape `aggregate_tamper_evidence` already expects, so the evidence overlay keeps working.

### Processor flow

1. Load pages, OCR, local classify and extract, and local `evaluate` (unchanged).
2. **Gemini scan** (new, stage `external_review`).
3. If the Gemini scan is unavailable, the existing Groq text advisory runs as before.
4. Chronology, regional, metadata, QR, local visual, and ADCD run as before. The Gemini visual indicators come from the scan.
5. `aggregate_tamper_evidence` runs (unchanged).
6. **`build_verdict`** writes `findings.verdict`, `findings.thesis_checks`, `findings.field_comparison`, and `findings.gemini_scan`. When Gemini completed, it also sets `status`, `detected_document_type`, `confidence_score`, and `model_service = 'gemini_vision_primary'`.

When Gemini is unavailable, `build_verdict` derives the five checks from the local assessments with `source: 'local'`, so the card always renders.

### Status mapping (advisory only, never an approval or rejection)

1. `readable` fail → `needs_reupload`
2. `consistent` fail, or `untampered`/`unaltered` fail corroborated by a local `review` decision → `flagged_inconsistency`
3. `complete` fail → `needs_reupload`
4. any `review` (including an uncorroborated alteration or tampering fail) → `needs_staff_review`
5. all pass → `likely_valid`

An existing stronger local status (`flagged_inconsistency` from deterministic chronology) is never weakened.

## 6. UI

`includes/ai-verdict-view.php` adds a pure function, `aics_ai_verdict_view(array $findings, string $status, string $expectedCode): array`. It returns escaped-ready strings for the banner, the five checks, the type row, and the comparison rows. Legacy findings without a `verdict` fall back to the four existing assessments.

`request-management.php` renders the card at the top of the details panel. Existing sections (document evidence, extracted information, overlay viewer, provider status, feedback form) move under **Technical details**, which is collapsed by default. The row summary shows the verdict headline.

The card ends with a **File metadata** section built from `findings.tamper_detection.auxiliary.file`.
- For images it shows the format and pixel size, the camera make/model, the date taken (read from the Exif sub-IFD), the saving software, and the Adobe APP14 save marker.
- For PDFs it shows the created and modified dates, the creator, the producer, and the digital-signature state.
- GPS tags are never read.
- Metadata is display-only and never changes a check or the status. Missing camera data is common: screenshots, messaging apps, and scanners all strip it.

Tones: green pass, amber review, red fail, gray unavailable. The icon and text carry the meaning, so color is never the only cue.

## 7. Privacy and Safety

- The key is read from the private env file only. It is never in argv, logs, findings, or the UI.
- Findings store only bounded fields: the primary person name and role, dates, the issuer, and a short summary. ID numbers are not stored.
- The document-side name is shown to authorized staff in the comparison table. Staff can already open the original document, so this does not widen access. This deliberately replaces the earlier "hide conflicting value" rule, because staff cannot act on a mismatch they cannot see.
- There is no fraud, forgery, or authenticity wording, and no automatic approval, rejection, or SMS.

## 8. Testing

- Unit: env alias loading, contract normalization (bad enums, out-of-range boxes, accusatory text), client request shape and error mapping with a fake opener, verdict mapping for each rule, the review-#205 scenario (death certificate in a med-cert slot with a different name → `flagged_inconsistency`, detected `death_cert`), and the Gemini-unavailable fallback.
- Integration: a processor run with a stubbed scanner stores the verdict and the five checks.
- PHP: a view-model test for Gemini and legacy findings, plus a page contract test for the card markup.
- Live: re-scan document #89 with the real key and confirm the detected type is `death_cert` and consistency is `fail`.
- The full existing Python suite (315 tests) and the PHP AI tests keep passing.

## 9. Additions (2026-10-01, after live testing)

Live testing with a real Canva-edited certificate (date "September 24, 2026" typed over a 2021 certificate) showed the AI reading alone misses typed-on text. Added:

- **Typed-on text detector** (`tamper_detection/overlay.py`): a run of at least 5 letters is flagged when all of these hold:
  - the letters are flat digital ink (lightness ≤ 15), much darker than the page's camera-grey ink;
  - their colour tone differs from the page ink, or they are neutral black on a photo;
  - they sit on a confidently read OCR line, away from the image border;
  - the page is a real photo (fine camera grain ≥ 0.62).

  Tuned against all 211 uploads: 0 false alarms.
- **Copy detection fingerprint:** a cloned region duplicates the paper's camera noise, while repeated printed text does not. The check uses 6,000 ORB points, template-aligned noise correlation of at least 0.15, compact regions only, and photos only. Repeated printed phrases (3+ shared words, or the same line) are skipped.
- **Editor metadata:** XMP `CreatorTool` (Canva, Photoshop, Picsart, …) is read as "Saved with". Account and document IDs are dropped. Metadata alone never flags anything.
- **Verdict:** typed-on text is `review`. It becomes `fail` when it is also corroborated by one of: a conflicting date at the same place, the AI reading, or an editing app in the metadata. When the corroboration is a conflicting date, the headline is **"Possible edited date"**.
- **AI request** now includes today's (Philippine) date and the signs of text typed onto a photo. Signatures in a different ink are normal. Whole-image findings get a document-sized box.
- **Staff UI:**
  - all labels say **AICS AI**;
  - Technical details keeps only marked areas, checks that ran, and staff feedback;
  - the viewer shows numbered, labelled boxes (red = high confidence, amber = hint) with a clickable list;
  - model-only tabs and page buttons are hidden when they cannot do anything.
- **Test set:** `tests/edited-documents/` holds 19 generated documents (11 certificates, 8 IDs) with a full-pipeline runner and report. Result: 19/19.
