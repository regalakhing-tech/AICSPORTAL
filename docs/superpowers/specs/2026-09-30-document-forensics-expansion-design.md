# Document Forensics Expansion Design

Date: 2026-09-30

## 1. Purpose

Extend the existing AICS AI document-verification pipeline with the twelve approved image and file-forensics capabilities. The system will identify evidence that requires staff inspection; it will not declare that a document is authentic, forged, fraudulent, or officially issued.

This design extends `2026-09-29-gemini-tamper-detection-design.md`. It excludes issuing-office database checks, person-specific signature authentication, and official template, seal, or watermark authentication because no authoritative database or reviewed reference corpus is available.

## 2. Approved Scope

1. Enable image-capable Gemini review using the already configured external provider.
2. Activate ADCD-Net only when its service, trained checkpoints, and health checks are available.
3. Inspect image and PDF metadata for suspicious or conflicting editing information.
4. Detect possible copy-move or cloned regions within the same page.
5. Detect possible pasted regions, inconsistent compression, resampling, and splicing.
6. Improve typography analysis for font rendering, baseline, spacing, color, and background differences.
7. Detect possible erasure, overwriting, and covered regions.
8. Decode QR codes and report their contents without authenticating the contents.
9. Inspect embedded PDF signatures and detect cryptographic or byte-range changes after signing.
10. Compare repeated logo, stamp, and signature-like regions within the same uploaded document.
11. Display localized evidence boxes on an authorized document preview.
12. Generate deterministic synthetic alterations and test clean and altered cases.

## 3. Safety Boundaries

- Every result is advisory and requires authorized PSWDO review.
- No detector can approve, reject, or request re-upload by itself.
- No detector may label a person dishonest or a document fake, forged, fraudulent, or authentic.
- QR decoding exposes data encoded in the QR symbol; it does not verify the destination or issuer.
- PDF signature inspection verifies structural and cryptographic integrity when possible; it does not establish that the signer is an authorized official without a trusted issuer relationship.
- Repeated logos, stamps, and signatures may be legitimate. Repetition alone is not an alteration signal.
- Metadata is weak evidence because it can be missing, rewritten, or produced by ordinary scanning software.
- A component failure is reported as unavailable and never treated as clean evidence.

## 4. Architecture

All detectors implement a shared result contract and run after document loading and OCR:

```json
{
  "status": "completed | unavailable",
  "family": "metadata | qr | pdf_signature | copy_move | pixel_forensics | regional | repeated_visual | gemini_visual | adcd_net",
  "decision": "clear | review",
  "indicators": [
    {
      "type": "copy_move_region",
      "page": 1,
      "box_2d": [120, 180, 260, 420],
      "confidence_band": "low | moderate | high",
      "reason": "A similar non-adjacent image region requires staff inspection."
    }
  ],
  "facts": {}
}
```

`box_2d` is normalized `[ymin, xmin, ymax, xmax]` in the range 0-1000. File-level evidence may omit a box. Reasons are length-bounded, neutral, and safe for direct escaped rendering.

The processor runs:

1. existing OCR, classification, extraction, and beneficiary consistency checks;
2. file metadata and PDF signature inspection on the original upload;
3. QR decoding on normalized pages;
4. local copy-move, pixel-forensics, regional typography, erasure, and repeated-visual analysis;
5. Gemini image review when enabled and privacy-safe configuration passes;
6. ADCD-Net when artifacts and service health pass;
7. chronology analysis;
8. conservative evidence aggregation;
9. persistence of bounded findings and private forensic artifacts;
10. staff UI rendering with localized overlays.

The pipeline continues when an optional detector is unavailable.

## 5. Provider Activation

### 5.1 Gemini image mode

Image mode is enabled through the private environment configuration. The client sends only the bounded document pages already allowed by the current page and payload limits. It uses the strict `external-ai-assessment-v2` response schema and rejects unbounded or malformed evidence.

The cache key includes the document hash, image payload mode, model, prompt version, and schema version. Logs contain no image bytes, OCR text, QR content, or personal values.

### 5.2 ADCD-Net

ADCD activation is fail-closed. Startup validation checks:

- configured localhost-only service URL;
- required model and restoration checkpoint files;
- service health endpoint;
- compatible response schema;
- successful analysis of a harmless synthetic probe.

If any requirement is absent, the component stays unavailable. ADCD results do not influence workflow decisions until an available calibration report meets the existing validation gate. Merely finding checkpoint files does not justify treating the model as calibrated.

## 6. Local Forensics

### 6.1 Metadata inspection

The metadata module reads, but does not execute, image EXIF and PDF document information. It records only allow-listed facts needed for review:

- file format and page count;
- creation and modification timestamps;
- creator, producer, and editing-application labels;
- inconsistent timestamp ordering;
- metadata indicating editing after an embedded signature time;
- suspicious disagreement between container type and extension.

Ordinary software labels such as scanner, browser, office suite, or image editor are not sufficient for escalation. Metadata becomes corroborating evidence only when it conflicts with another reliable fact.

### 6.2 Copy-move detection

Local OpenCV analysis detects duplicated regions using scale-aware keypoints and descriptor matching. Matches are filtered by spatial separation, transformation consistency, minimum cluster size, and texture content. Repeated blank areas, borders, ruled lines, headers, and regular printed patterns are suppressed.

The result returns both source and destination boxes when a non-adjacent cluster is strong enough. Copy-move evidence requires visual or semantic corroboration before workflow escalation.

### 6.3 Pixel, compression, resampling, and splicing analysis

The pixel-forensics module uses bounded, explainable measurements rather than a binary authenticity label:

- local error-level and recompression residuals for JPEG inputs;
- block-grid and frequency discontinuities;
- resampling periodicity differences;
- local noise and sharpness discontinuities;
- edge halos and rectangular transition boundaries;
- color-channel and background-texture disagreement.

Camera shadows, folds, skew, lighting gradients, and document edges are masked or down-weighted. A single weak measurement remains advisory.

### 6.4 Typography analysis

The existing regional analyzer is extended to compare OCR lines and words of the same semantic role and rendering mode. Features include:

- normalized text height and aspect ratio;
- baseline position, angle, and variance;
- word and character spacing proxies;
- stroke width and foreground density;
- grayscale and color-channel distribution;
- edge sharpness and local background residual;
- boundary contrast and compression residual.

At least two materially abnormal feature families are required for a regional indicator. Pre-printed text, handwriting, stamps, and typed entries are not treated as peer groups unless their rendering modes match.

### 6.5 Erasure, overwrite, and cover detection

The local analyzer looks for bounded regions with abnormal smoothness, missing paper texture, edge remnants, white or uniform-color coverings, overwritten stroke intersections, or abrupt residual changes. It suppresses margins, naturally empty form fields, highlights, and large uniform backgrounds.

An erasure signal alone is moderate at most and must be corroborated before escalation.

### 6.6 QR decoding

OpenCV QR decoding runs locally on original and contrast-normalized page variants. Results are stored as privacy-bounded facts:

- page and QR bounding box;
- content type such as URL, plain text, or structured data;
- a redacted preview and cryptographic digest;
- whether multiple decoding variants agreed.

Full sensitive QR payloads are not written to logs or public findings. The staff UI clearly states `Decoded only — not authenticated`.

### 6.7 PDF signature inspection

For PDFs, the system inspects signature dictionaries, byte ranges, incremental updates, and cryptographic verification using an available maintained PDF-signature library. It reports:

- unsigned;
- cryptographically intact;
- document changed after signing;
- invalid signature structure;
- unsupported or unavailable verification.

Certificate subject details are minimized. Trust-chain status is reported separately from byte integrity, and no signer is described as official without an authorized trust configuration.

### 6.8 Repeated visual regions

Feature matching compares non-adjacent candidate regions within and across pages. It reports repeated logo-, stamp-, or signature-like regions only when the geometric match is unusually exact and context suggests the repetition deserves inspection. Normal repeated letterheads, page furniture, form labels, and photocopy patterns are suppressed.

The detector does not identify whose signature or stamp is shown.

## 7. Aggregation

Evidence families remain independent. The combined decision becomes `review` only when one of these rules applies:

1. a deterministic repeated chronology conflict is high confidence;
2. two independent moderate-or-higher visual detectors overlap or identify the same semantic field;
3. one high-confidence local or ADCD pixel signal is corroborated by Gemini, chronology, or regional analysis;
4. a PDF reports a cryptographically verified change after signing;
5. multiple strong copy-move clusters overlap a sensitive value and another detector corroborates the destination region.

Metadata, QR presence, an unsigned PDF, font difference alone, repeated letterhead, or one weak image residual cannot escalate a document. Existing higher-priority quality and identity results remain intact.

## 8. Persistence and Privacy

No database schema change is required. Versioned results are stored under `findings.tamper_detection` and detector-specific bounded facts. Full images, crops, masks, QR payloads, certificate blobs, raw provider responses, and low-level diagnostic arrays remain outside public findings.

Private artifacts use the existing protected artifact root and random review-scoped names. Paths, credentials, model locations, and external request bodies never appear in staff responses or logs.

## 9. Evidence Overlay UI

The authorized PSWDO document review displays the original page with an overlay layer. Each normalized box is transformed to the rendered page dimensions. Indicators are selectable and use consistent tones:

- red: corroborated inconsistency requiring review;
- amber: advisory evidence requiring comparison;
- gray: unavailable or unsupported check.

Selecting an indicator focuses its box and shows the plain-language reason, source, page, and confidence. The compact result card remains visible without scrolling. Technical detector details stay under `View full verification details`.

If the browser cannot render the document page, the textual evidence list remains available. Overlay coordinates are range-checked and cannot inject markup.

## 10. Synthetic Evaluation

A deterministic generator creates fictional documents only. It produces clean originals, masks, and modified variants for:

- dates, names, amounts, and reference-number replacement;
- text insertion and removal;
- copy-move cloning;
- pasted text with matched and mismatched fonts;
- resampling and recompression;
- spliced backgrounds;
- erasure, white covering, and overwriting;
- signature-region and stamp-region edits;
- repeated region insertion;
- benign rotation, perspective, blur, shadows, folds, lighting changes, and compression.

Tests assert detector contracts, localized box validity, privacy behavior, clean-document suppression, aggregation thresholds, and provider failure isolation. Evaluation reports precision, recall, false-review rate, localization IoU, and per-alteration results. Synthetic measurements validate implementation behavior but are not presented as real-world forgery-detection accuracy.

## 11. Implementation Boundaries

Expected implementation areas:

- `ai_processor/config.py` and private environment validation;
- `ai_processor/external_ai/` for image-mode activation and schema enforcement;
- `ai_processor/tamper_detection/metadata.py`;
- `ai_processor/tamper_detection/copy_move.py`;
- `ai_processor/tamper_detection/pixel_forensics.py`;
- `ai_processor/tamper_detection/qr.py`;
- `ai_processor/tamper_detection/pdf_signature.py`;
- `ai_processor/tamper_detection/repeated_visual.py`;
- extensions to `regional.py`, `aggregation.py`, and `processor.py`;
- private artifact generation and authorized retrieval;
- PSWDO PHP, JavaScript, and CSS for evidence overlays;
- unit, integration, contract, privacy, and synthetic-regression tests.

Optional dependencies must be feature-detected. Missing libraries produce a neutral unavailable result and do not prevent the rest of the review from completing.

## 12. Acceptance Criteria

- Gemini image mode is enabled through private configuration and returns validated localized evidence.
- ADCD runs only when its required artifacts and localhost service are healthy; otherwise its exact unavailable state is shown.
- Each new detector returns the shared bounded contract and cannot crash the overall review.
- QR output is labeled decoded, not authenticated.
- PDF signature output distinguishes byte integrity from signer trust.
- Strong findings identify a page and usable evidence box where applicable.
- Staff can see highlighted regions and a concise explanation without scrolling through technical output.
- Weak or single-source differences do not produce a forgery conclusion or automatic rejection.
- Synthetic altered samples exercise all supported alteration categories, while benign variants test false-review resistance.
- Existing document extraction, consistency, prioritization, notification, release, and staff-decision workflows remain compatible.
- All relevant Python and PHP regression tests pass.
