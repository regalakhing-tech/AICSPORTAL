# Gemini-Primary Document Scanner Implementation Plan

> **For agentic workers:** Implement task-by-task with TDD (failing test → minimal code → pass). Run the full Python suite after each task.

**Goal:** Gemini vision reads every uploaded document and produces the thesis five-check verdict. Staff see it as one clear card.

**Spec:** `docs/superpowers/specs/2026-10-01-gemini-primary-document-scanner-design.md`

**Tech stack:** Python 3.13 (unittest, urllib, OpenCV), PHP 8.2, CSS.

## Global constraints

- Advisory only: no approval, rejection, fraud, or authenticity wording.
- The API key never appears in logs, argv, findings, tests output, or the UI.
- A Gemini failure never fails the review. The local result always completes.
- The baseline of 315 Python tests must stay green.

## Tasks

- [x] **1. Key loading.** `private_env.py` allowlists the Gemini names. `config.py` adds `gemini_api_key` (first non-empty alias), `gemini_model`, `gemini_fallback_models`, and `gemini_timeout_seconds` (default 45). `gemini_image_api_key` keeps working.
  Test: `tests/test_gemini_scanner_config.py`.
- [x] **2. Catalog + prompt + contract.** `gemini_scanner/catalog.py`, `prompt.py`, `contract.py`.
  Test: `tests/test_gemini_scanner_contract.py` (enum rejection, box clamping, text bounds, accusatory-text scrub, schema lists every catalog code).
- [x] **3. Client + service.** `gemini_scanner/client.py`, `service.py` with a fake opener: request shape (header key, inline images, responseSchema), model fallback on 404/429/5xx, error codes, cache hit, circuit open, and unconfigured → unavailable.
  Test: `tests/test_gemini_scanner_client.py`.
- [x] **4. Verdict.** `gemini_scanner/verdict.py`: five checks, status mapping, deterministic name/DOB comparison, field comparison rows, local fallback, and `as_visual_result`.
  Test: `tests/test_gemini_scanner_verdict.py`, including the review-#205 scenario.
- [x] **5. Processor wiring.** Call the scan before the Groq advisory, skip Groq when the scan completes, feed visual indicators to the aggregation, and apply `build_verdict` last.
  Test: `tests/test_gemini_scanner_processor.py` with the stubbed scanner.
- [x] **6. PHP view model + card UI.** `includes/ai-verdict-view.php`, the card markup in `request-management.php`, the row summary, and CSS.
  Tests: `tests/pswdo-ai-verdict-card-test.php` plus the existing PHP AI tests.
- [ ] **7. Verification.** Full Python suite, PHP AI tests, `php -l` on changed files, a live re-scan of document #89 with the real key, and a check of the rendered page in the browser.
  Done: 363 Python tests, 11 PHP AI tests, lint, a real-pipeline run on #89 (local fallback plus a simulated Gemini response), and card screenshots at 900px and 420px.
  **Open:** the live Gemini re-scan. No Gemini key is present in `aics-ai.env` under any accepted name (`gemini_key_present: False`).
