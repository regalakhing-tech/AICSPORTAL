# AICS AI development deployment

All machine-specific AI settings are server environment variables. Keep the
populated values outside source control; `.env.example` is a names-only local
template and contains no credentials.

Set `AICS_PROJECT_ROOT`, `AICS_PYTHON_EXECUTABLE`, `AICS_UPLOAD_ROOT`,
`AICS_TESSERACT_PATH`, and `AICS_POPPLER_PATH` for the Python worker. The
worker rejects an invalid project/upload root with a safe internal error code.

For the local ADCD-Net service set `AICS_ADCD_ENABLED=1`,
`AICS_ADCD_SERVICE_URL=http://127.0.0.1:5001`,
`AICS_ADCD_PYTHON_EXECUTABLE`, `AICS_ADCD_NET_ROOT`, and
`AICS_ADCD_OUTPUT_ROOT`. The ADCD repository must contain its approved local
checkpoints and preprocessing assets; their paths are optional documentation
fields (`AICS_ADCD_CHECKPOINT`, `AICS_ADCD_DOCRES_CHECKPOINT`, and
`AICS_ADCD_QT_TABLE`) when the standalone service requires them. Start it with
`ai_processor\tamper_detection\start-adcd-service.bat`, then verify
`http://127.0.0.1:5001/health` reports `status: ready` and `model_loaded: true`.
ADCD is enabled by default and its URL is restricted to loopback. Set
`AICS_ADCD_ENABLED=0` only for an intentional local opt-out. An ADCD-only outage
reports overall AI health as `degraded`; OCR and staff review remain available.

`AICS_ADCD_OUTPUT_ROOT` is service staging only. Set
`AICS_AI_REVIEW_ARTIFACT_ROOT` to a private directory outside the web root for
the copied review artifacts. The ADCD service account needs write access to its
output root; the AI worker needs read access there and read/write access to the
private artifact root. The browser must have no direct filesystem alias to
either root; artifact access is only through the authorized application route.

For local regressions, run the Python suite from the `AICS` directory with
`python -m unittest discover -s ai_processor\tests -p "test_*.py" -q`.
Run PHP syntax checks with `C:\xampp\php\php.exe -l <file>`. Run fixture HTTP
tests only with `AICS_HTTP_TEST_MODE=1` plus an isolated database configured
through `AICS_TEST_DB_HOST`, `AICS_TEST_DB_PORT`, `AICS_TEST_DB_NAME`,
`AICS_TEST_DB_USER`, and `AICS_TEST_DB_PASSWORD`; copy those values into the
test server's `AICS_DB_*` environment. The database must be named
`aics_http_regression`; the fixtures verify both that explicit name and
`SELECT DATABASE()` before they create or reset fixtures. Never use `AICSdb`.

Stale-worker policy for this capstone is view-triggered: on the next authorized
request-wide ensure, an expired claimed row becomes `AI_PROCESSING_TIMEOUT` and
PSWDO can explicitly Retry it. A future continuously operated deployment may
add a scheduled stale-job monitor; none is part of the present system.

## Assessment and calibration semantics

The worker stores four independent assessments: document match, readability,
applicant match, and alteration signal. Missing or conflicting evidence returns
staff review. Strong wrong-slot or unreadable evidence requests a new upload;
only reliable stable-identity contradictions are flagged. Address differences
alone are never treated as identity failure.

Set `AICS_AI_CALIBRATION_REPORT` only to a reviewed local report generated from
an explicitly de-identified manifest. Run:

`python -m ai_processor.evaluation --manifest ai_processor/tests/fixtures/validation/manifest.json --format json`

A missing, invalid, version-mismatched, under-sampled, or manually marked
uncalibrated report fails closed to staff review. Numeric diagnostics remain
technical data; they are not displayed as an overall accuracy percentage.

## Optional Groq external AI provider

Revoke any credential that has appeared in chat or source code. Configure only a replacement credential using:

```powershell
powershell -ExecutionPolicy Bypass -File tools\setup-external-ai.ps1
python -m ai_processor.external_ai.probe --config-json
python -m ai_processor.external_ai.probe --text
python -m ai_processor.external_ai.probe --image
```

The private configuration is `C:\xampp\aics_private\aics-ai.env`; do not serve, commit, print, or copy this file. `--image` uses a generated synthetic image only. Keep real-document image mode disabled until PSWDO has authorized it and the matching endpoint/model image probe succeeds.

The setup script uses `https://api.groq.com/openai/v1/chat/completions` and `openai/gpt-oss-20b`. Do not substitute `meta-llama/llama-prompt-guard-2-86m`: that model classifies prompt-injection attempts and is not a general document-analysis model.

The provider is advisory and receives bounded evidence only for uncertain cases. Clear and unreadable documents skip it. A missing key, invalid response, timeout, open circuit, or provider outage records a safe unavailable state and local verification continues. Run one queued job with `python -m ai_processor.processor --once`. Check tracked files before handoff with `powershell -ExecutionPolicy Bypass -File tests\external-ai-secret-regression.ps1`.
