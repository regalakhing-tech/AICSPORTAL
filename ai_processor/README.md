# AICS local document verification worker

Requires Python 3.10+ and a local Tesseract installation available on `PATH`, or set `AICS_TESSERACT_PATH`. Install packages with `python -m pip install -r requirements.txt`.

Copy `.env.example` values into your local environment (do not commit passwords). From the `AICS` folder run `python -m ai_processor.processor --once`, or `python -m ai_processor.processor --batch 10`. Add `--dry-run` to print only IDs/statuses without updating reviews.

For normal PSWDO reprocessing, configure Apache/XAMPP with `AICS_PYTHON_EXECUTABLE` and `AICS_PYTHON_VERSION` (for example `py` and `-3.13`) and restart Apache. Clicking Reprocess then launches `--document-id <id>` in the background; it does not wait for OCR or drain other queued documents. `--once` remains available for manual diagnostics.

It processes one request's queued documents sequentially, reads only database-stored upload paths beneath `Applicants/uploads`, and updates the existing review rows with visible stages. Results are advisory; PSWDO workflow decisions are never changed automatically. PDFs are rendered through `pdf2image` (which requires Poppler installed locally).

Each result exposes four separate staff-facing assessments: Document match, Readability, Applicant match, and Alteration signal. OCR selection uses document-profile anchors and conservative field consensus. Alteration review combines validated Gemini image indicators, deterministic date-context checks, local regional typography/image measurements, and the optional local DocTamper/ADCD-Net signal. No single font difference is sufficient. Corroborated evidence can route a document to staff review, but it never automatically rejects the upload or establishes authenticity, intent, fraud, or forgery.

Profiles fail closed to staff review until a reviewed calibration report meets the profile's minimum case count, precision, and false-reupload limits. Run the synthetic privacy-safe harness with `python -m ai_processor.evaluation --manifest ai_processor/tests/fixtures/validation/manifest.json --format json`. The committed report intentionally leaves every profile uncalibrated.

Current limits: first three PDF pages, bounded OCR candidates, and conservative quality/type/identity rules. When explicitly enabled, each review may send bounded OCR evidence to the configured advisory provider so it can cross-reference readable beneficiary fields; document images are sent only when image review is separately enabled and the provider is Gemini or has passed the synthetic capability probe. Provider failure never blocks the completed local review.

## Gemini vision document scanner (primary)

Gemini reads every uploaded page image and scores the five checks from the capstone paper's definition of AI-Enhanced Document Verification: **Complete, Consistent, Readable, No visible alterations, No signs of tampering**. It also identifies the document type and reads the main person's name and dates. The system then compares those values with the application deterministically (`matching.name_match_details`), so the model never decides a match on its own. PSWDO staff see the result as one card: a verdict banner, the five checks, required vs detected document, and a document-vs-application table.

For every assistance type, the `valid_id` upload is compared with the applicant name and available birth date from that request. Other required documents continue to use the beneficiary snapshot. This changes the identity reference, not OCR extraction, accepted document types, or staff decisions. The same owner rule applies to every Valid ID upload; an applicant ID is not used as a beneficiary identity reference for other documents.

The default `VALID_ID_OWNER_MODE=applicant` enables this rule. To restore the previous beneficiary comparison temporarily, set `VALID_ID_OWNER_MODE=legacy` in the private `C:\xampp\aics_private\aics-ai.env` file and restart the AI worker. Set it back to `applicant` and restart the worker to re-enable applicant matching. The setting does not alter existing saved reviews; reprocess a document to obtain a result under the selected mode. The two modes use separate Gemini and external-AI cache entries for Valid IDs.

Add the key to `C:\xampp\aics_private\aics-ai.env` (never to the repository):

```
AICS_GEMINI_API_KEY=<your key from https://aistudio.google.com/apikey>
```

`AICS_GEMINI_IMAGE_API_KEY`, `GEMINI_API_KEY`, and `GOOGLE_API_KEY` are accepted too. Check the setup without printing the key:

```powershell
python -c "from ai_processor.config import SETTINGS as s; print({'gemini_key_present': bool(s.gemini_image_api_key), 'model': s.gemini_model})"
```

When Gemini is not configured, times out, or is rate limited, the review still completes with the local OCR result and the Groq text advisory. The card then says "Local OCR only — Gemini did not run". Results are cached per file, required document, and prompt version. Three consecutive transient failures pause Gemini calls for five minutes. Code: `ai_processor/gemini_scanner/`.

## Optional Groq advisory review

Use only a newly rotated credential. Never reuse a key exposed in chat, source code, screenshots, or logs. From the project directory run:

```powershell
powershell -ExecutionPolicy Bypass -File tools\setup-external-ai.ps1
python -m ai_processor.external_ai.probe --config-json
python -m ai_processor.external_ai.probe --text
python -m ai_processor.external_ai.probe --image
python -m ai_processor.processor --once
```

The setup command configures Groq's official OpenAI-compatible chat-completions endpoint with `openai/gpt-oss-20b` and writes the key to `C:\xampp\aics_private\aics-ai.env`, outside the web root, without printing it. Prompt Guard models are security classifiers and must not be used as the document verifier. The image probe generates synthetic pixels in memory and never accepts an applicant file path. Authorize real-document image review separately before setting `AICS_EXTERNAL_AI_IMAGE_ENABLED=1`. Local OCR, database duplicate detection, and DocTamper remain authoritative inputs to staff review; the provider cannot approve, reject, declare fraud, or confirm a duplicate.
