# Hybrid External-AI Document Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a fast, failure-tolerant external-AI second opinion to the existing background document verifier without exposing credentials, delaying applicant submission, or replacing local duplicate checks and staff decisions.

**Architecture:** The existing Python worker remains authoritative for image quality, OCR, structured extraction, database duplicate matching, and DocTamper/ADCD evidence. A new `ai_processor.external_ai` package conditionally sends bounded OCR evidence—and images only after a successful capability probe—to the configured OpenAI-compatible chat-completions endpoint. Strict response validation, a private file-backed cache/circuit breaker, and advisory-only persistence ensure that any third-party outage falls back to the completed local result.

**Tech Stack:** Python 3.10+ standard library, OpenCV/Pillow, PyMySQL, PHP 8/MySQL, XAMPP on Windows, `unittest`, existing PHP contract tests.

**Spec:** `docs/superpowers/specs/2026-09-19-fast-ai-document-verification-design.md`

**Scope relationship:** The repository already contains the queue lifecycle, bounded local OCR candidates, document profiles, quality gate, structured consistency, independent DocTamper branch, rescan history, evaluation harness, and four-card PSWDO presentation delivered from `docs/superpowers/plans/2026-09-17-ai-verification-accuracy-doctamper.md` and `docs/superpowers/plans/2026-09-17-ai-verification-lifecycle-ui.md`. This plan implements the remaining external-provider, staff-feedback, and provider-performance delta while preserving and regression-testing that baseline.

## Global Constraints

- Applicant submission and re-upload routes remain database-only queue operations and never wait for the worker or external API.
- Clear documents do not invoke the external API.
- The external request budget is 12 seconds total across the initial attempt and one eligible retry.
- Authentication, permission, invalid-model, and invalid-schema failures do not retry.
- Missing configuration, timeout, invalid JSON, provider outage, or an open circuit never fails the local document review.
- The external model is advisory and cannot approve, reject, confirm a duplicate, or declare fraud.
- Duplicate detection remains deterministic local database matching plus staff review.
- Image mode remains disabled until a controlled capability probe succeeds for the configured endpoint and model.
- The replacement credential lives only in `C:\xampp\aics_private\aics-ai.env`; it never appears in Git, browser output, logs, findings, test fixtures, or shell command arguments.
- Never use the credential already exposed in chat; it must be revoked and replaced.
- Do not log raw OCR text, full prompts, authorization headers, Base64 images, or unredacted provider responses.
- Automated tests mock all network traffic and use synthetic data only.

---

## File Structure

- Create `ai_processor/private_env.py`: parse the server-only environment file without overwriting process-level settings.
- Modify `ai_processor/config.py`: expose validated external-AI settings and privacy-safe configuration health.
- Create `ai_processor/external-ai.env.example`: names-only deployment template with no key value.
- Create `tools/setup-external-ai.ps1`: securely prompt for a replacement key and write the private file outside the web root.
- Create `ai_processor/external_ai/contracts.py`: invocation policy, bounded payload construction, and strict response normalization.
- Create `ai_processor/external_ai/client.py`: chat-completions transport, timeout/retry classification, and redacted errors.
- Create `ai_processor/external_ai/state.py`: atomic private cache, capability marker, and circuit-breaker state.
- Create `ai_processor/external_ai/images.py`: bounded JPEG preparation for capability-approved image requests.
- Create `ai_processor/external_ai/postprocessing.py`: attach advisory provider evidence without changing the local decision.
- Create `ai_processor/external_ai/probe.py`: configuration/text/image capability CLI.
- Create `ai_processor/timing.py`: privacy-safe per-stage elapsed-time collection.
- Modify `ai_processor/processor.py`: conditionally invoke and persist external evidence between local verification and tamper analysis.
- Modify `ai_processor/database.py`: add the external stage and persist dynamic provider metadata.
- Modify `includes/document-ai-reviews.php`: report optional provider health without exposing the key.
- Modify `Pswdo/php_files/pswdo-common.php`: normalize provider evidence for presentation.
- Modify `Pswdo/php_files/request-management.php`: show one compact, truthful provider status line.
- Modify `Pswdo/css files/pswdo-pages.css`: style the provider line consistently.
- Modify `database/AICSdb.sql` and `includes/document-ai-reviews.php`: add staff feedback/audit persistence.
- Modify `Pswdo/php_files/workflow-action.php`: accept an authorized, CSRF-protected AI feedback action.
- Modify `ai_processor/evaluation.py`: report provider availability, cache, latency, and staff agreement metrics without calling them model accuracy.
- Modify `ai_processor/README.md` and `ai_processor/DEPLOYMENT.md`: setup, probe, fallback, and privacy instructions.
- Add focused Python and PHP tests alongside the existing suites.

---

### Task 1: Private Configuration and Secure Setup

**Files:**
- Create: `ai_processor/private_env.py`
- Create: `ai_processor/external-ai.env.example`
- Create: `tools/setup-external-ai.ps1`
- Modify: `ai_processor/config.py`
- Test: `ai_processor/tests/test_external_ai_config.py`

**Interfaces:**
- Produces: `load_private_environment(path: Path, environ: MutableMapping[str, str]) -> dict[str, str]`
- Produces: `external_ai_configuration_health(settings: Settings) -> str`
- Produces: `redacted_external_ai_config(settings: Settings) -> dict[str, str | bool | int]`
- Produces settings: `external_ai_enabled`, `external_ai_endpoint`, `external_ai_model`, `external_ai_api_key`, `external_ai_timeout_seconds`, `external_ai_image_enabled`, `external_ai_state_root`

- [ ] **Step 1: Write failing private-environment tests**

```python
def test_private_file_loads_allowed_names_without_overwriting_process_env(self):
    env = {'AICS_EXTERNAL_AI_MODEL': 'process-model'}
    loaded = load_private_environment(self.path, env)
    self.assertEqual(env['AICS_EXTERNAL_AI_MODEL'], 'process-model')
    self.assertEqual(env['AICS_EXTERNAL_AI_API_KEY'], 'replacement-test-key')
    self.assertNotIn('UNRELATED_NAME', loaded)

def test_health_never_contains_the_key(self):
    settings = replace(SETTINGS, external_ai_enabled=True, external_ai_api_key='secret-value')
    self.assertEqual(external_ai_configuration_health(settings), 'configured')
    self.assertNotIn('secret-value', repr(redacted_external_ai_config(settings)))
```

- [ ] **Step 2: Run the tests and verify the missing module/settings failure**

Run: `python -m unittest ai_processor.tests.test_external_ai_config -v`

Expected: FAIL because `ai_processor.private_env` and external settings do not exist.

- [ ] **Step 3: Implement the allowlisted private environment loader**

```python
ALLOWED_EXTERNAL_AI_NAMES = frozenset({
    'AICS_EXTERNAL_AI_ENABLED', 'AICS_EXTERNAL_AI_ENDPOINT',
    'AICS_EXTERNAL_AI_MODEL', 'AICS_EXTERNAL_AI_API_KEY',
    'AICS_EXTERNAL_AI_TIMEOUT_SECONDS', 'AICS_EXTERNAL_AI_IMAGE_ENABLED',
    'AICS_EXTERNAL_AI_STATE_ROOT',
})

def load_private_environment(path: Path, environ: MutableMapping[str, str]) -> dict[str, str]:
    loaded = {}
    if not path.is_file():
        return loaded
    for line in path.read_text(encoding='utf-8-sig').splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith('#') or '=' not in stripped:
            continue
        name, value = (part.strip() for part in stripped.split('=', 1))
        if name not in ALLOWED_EXTERNAL_AI_NAMES or '\x00' in value:
            continue
        loaded[name] = value
        environ.setdefault(name, value)
    return loaded
```

Call the loader at the top of `config.py`, before `Settings` is instantiated, using `Path(os.getenv('AICS_EXTERNAL_AI_ENV_FILE', r'C:\xampp\aics_private\aics-ai.env'))`.

- [ ] **Step 4: Add validated settings and privacy-safe health**

Require an HTTPS endpoint, timeout range `2..12`, a non-empty model, and a non-empty key when enabled. Return only `disabled`, `configured`, `missing_key`, `invalid_endpoint`, or `invalid_timeout`; never return configuration values.

- [ ] **Step 5: Add the names-only example and secure setup script**

The example contains:

```dotenv
AICS_EXTERNAL_AI_ENABLED=1
AICS_EXTERNAL_AI_ENDPOINT=https://api.groq.com/openai/v1/chat/completions
AICS_EXTERNAL_AI_MODEL=openai/gpt-oss-20b
AICS_EXTERNAL_AI_API_KEY=
AICS_EXTERNAL_AI_TIMEOUT_SECONDS=12
AICS_EXTERNAL_AI_IMAGE_ENABLED=0
AICS_EXTERNAL_AI_STATE_ROOT=C:\xampp\aics_private\external_ai
```

The PowerShell script must use `Read-Host -AsSecureString`, convert only in memory, create `C:\xampp\aics_private`, write UTF-8 without echoing the key, and print only the destination path plus the next probe command. It must reject a blank key.

- [ ] **Step 6: Run focused and baseline configuration tests**

Run: `python -m unittest ai_processor.tests.test_external_ai_config ai_processor.tests.test_config_defaults -v`

Expected: PASS.

- [ ] **Step 7: Commit the configuration unit**

```powershell
git add ai_processor/private_env.py ai_processor/config.py ai_processor/external-ai.env.example tools/setup-external-ai.ps1 ai_processor/tests/test_external_ai_config.py
git commit -m "feat: add private external AI configuration"
```

---

### Task 2: Invocation Policy and Strict Contracts

**Files:**
- Create: `ai_processor/external_ai/__init__.py`
- Create: `ai_processor/external_ai/contracts.py`
- Test: `ai_processor/tests/test_external_ai_contracts.py`

**Interfaces:**
- Produces: `decide_invocation(local_result: dict, classification: dict, fields: dict, force: bool = False) -> InvocationDecision`
- Produces: `build_evidence(expected_type: str, ocr_text: str, fields: dict, local_result: dict, application_context: dict) -> dict`
- Produces: `normalize_assessment(payload: dict) -> dict`
- `InvocationDecision` fields: `invoke: bool`, `reason: str`

- [ ] **Step 1: Write failing policy and schema tests**

```python
def test_clear_local_result_skips_external_provider(self):
    decision = decide_invocation(CLEAR_RESULT, MATCH_CLASSIFICATION, RELIABLE_FIELDS)
    self.assertFalse(decision.invoke)
    self.assertEqual(decision.reason, 'local_evidence_sufficient')

def test_uncertain_identity_invokes_external_provider(self):
    result = {'findings': {'assessments': {'applicant_match': {'state': 'review'}}}}
    self.assertTrue(decide_invocation(result, MATCH_CLASSIFICATION, {}).invoke)

def test_invalid_provider_schema_is_rejected(self):
    with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
        normalize_assessment({'decision': 'approve'})
```

- [ ] **Step 2: Run tests and confirm contract symbols are missing**

Run: `python -m unittest ai_processor.tests.test_external_ai_contracts -v`

Expected: FAIL on missing `ai_processor.external_ai.contracts`.

- [ ] **Step 3: Implement conservative invocation rules**

Invoke for `force=True`, ambiguous/mismatched local classification, missing/conflicting required fields, or `review`/`fail` applicant-match evidence. Skip photo-only evidence, unreadable uploads requiring re-upload, and complete local `pass` evidence.

- [ ] **Step 4: Implement a bounded evidence builder**

Whitelist expected type, OCR text capped at 12,000 characters, normalized field values/statuses, assessment states, and the submitted identity snapshot. Exclude internal paths, database IDs, logs, prior raw findings, and unrelated sibling documents.

- [ ] **Step 5: Implement strict assessment normalization**

Accept only schema version `external-ai-assessment-v1`, states `support`, `review`, `conflict`, or `unavailable`, confidence bands `low`, `moderate`, or `high`, and at most eight reason codes matching `^[A-Z0-9_]{3,64}$`. Reject approval/rejection/fraud/confirmed-duplicate fields and cap extracted values at 500 characters.

- [ ] **Step 6: Run contract tests**

Run: `python -m unittest ai_processor.tests.test_external_ai_contracts -v`

Expected: PASS.

- [ ] **Step 7: Commit the contract unit**

```powershell
git add ai_processor/external_ai/__init__.py ai_processor/external_ai/contracts.py ai_processor/tests/test_external_ai_contracts.py
git commit -m "feat: define external AI advisory contract"
```

---

### Task 3: Resilient Chat-Completions Client

**Files:**
- Create: `ai_processor/external_ai/client.py`
- Test: `ai_processor/tests/test_external_ai_client.py`
- Test: `ai_processor/tests/test_external_ai_privacy.py`

**Interfaces:**
- Consumes: `normalize_assessment(payload: dict) -> dict`
- Produces: `ExternalAiClient(settings, opener=urlopen, sleeper=time.sleep)`
- Produces: `ExternalAiClient.assess(evidence: dict, image_data_url: str | None = None) -> dict`
- Produces privacy-safe codes: `EXTERNAL_AI_TIMEOUT`, `EXTERNAL_AI_RATE_LIMITED`, `EXTERNAL_AI_UNAVAILABLE`, `EXTERNAL_AI_AUTH_FAILED`, `EXTERNAL_AI_MODEL_INVALID`, `EXTERNAL_AI_INVALID_RESPONSE`

- [ ] **Step 1: Write failing transport tests with mocked HTTP**

```python
def test_successful_chat_completion_returns_normalized_json(self):
    client = ExternalAiClient(self.settings, opener=FakeOpener.completing(VALID_RESPONSE))
    result = client.assess({'expected_document_type': 'valid_id'})
    self.assertEqual(result['status'], 'completed')

def test_authentication_failure_does_not_retry(self):
    opener = FakeOpener.http_error(401)
    result = ExternalAiClient(self.settings, opener=opener).assess({})
    self.assertEqual(result['error_code'], 'EXTERNAL_AI_AUTH_FAILED')
    self.assertEqual(opener.calls, 1)

def test_transient_failure_retries_once_inside_total_budget(self):
    opener = FakeOpener.sequence(TimeoutError(), VALID_RESPONSE)
    result = ExternalAiClient(self.settings, opener=opener, sleeper=lambda _: None).assess({})
    self.assertEqual(result['status'], 'completed')
    self.assertEqual(opener.calls, 2)
```

- [ ] **Step 2: Run tests and confirm the client is missing**

Run: `python -m unittest ai_processor.tests.test_external_ai_client ai_processor.tests.test_external_ai_privacy -v`

Expected: FAIL on missing client.

- [ ] **Step 3: Implement the request body and strict system instruction**

Use `urllib.request.Request` and `urlopen` to avoid another dependency. Send `model`, `temperature: 0`, and messages that demand only `external-ai-assessment-v1` JSON. Use text content by default; use an OpenAI-compatible `image_url` data URL only when supplied by the capability-approved image path.

- [ ] **Step 4: Implement response extraction and retry classification**

Extract `choices[0].message.content`, strip at most one surrounding JSON code fence, parse JSON, then call `normalize_assessment`. Compute remaining timeout from `time.monotonic()` so two attempts together cannot exceed 12 seconds. Retry only timeout, reset, HTTP 429, and HTTP 5xx once.

- [ ] **Step 5: Implement privacy-safe logging**

Log provider status, attempt count, elapsed milliseconds, HTTP class, and safe error code only. Add a regression test that installs a capture handler and asserts the API key, OCR sample, Base64 prefix, and applicant name do not appear.

- [ ] **Step 6: Run client and privacy tests**

Run: `python -m unittest ai_processor.tests.test_external_ai_client ai_processor.tests.test_external_ai_privacy -v`

Expected: PASS.

- [ ] **Step 7: Commit the transport unit**

```powershell
git add ai_processor/external_ai/client.py ai_processor/tests/test_external_ai_client.py ai_processor/tests/test_external_ai_privacy.py
git commit -m "feat: add resilient external AI client"
```

---

### Task 4: Cache, Circuit Breaker, and Image Capability

**Files:**
- Create: `ai_processor/external_ai/state.py`
- Create: `ai_processor/external_ai/images.py`
- Create: `ai_processor/external_ai/probe.py`
- Test: `ai_processor/tests/test_external_ai_state.py`
- Test: `ai_processor/tests/test_external_ai_images.py`
- Test: `ai_processor/tests/fixtures/external_ai_probe.png`

**Interfaces:**
- Produces: `ExternalAiStateStore(root: Path)` with `get_cached`, `put_cached`, `circuit_is_open`, `record_success`, `record_transient_failure`, `image_capability_verified`
- Produces: `build_cache_key(document_path: Path, model: str, prompt_version: str, payload_mode: str) -> str`
- Produces: `prepare_image_data_url(image, max_edge: int = 1600, jpeg_quality: int = 80) -> str`
- Produces CLI: `python -m ai_processor.external_ai.probe --config-json|--text|--image`

- [ ] **Step 1: Write failing state and image tests**

```python
def test_three_transient_failures_open_circuit_for_five_minutes(self):
    store.record_transient_failure(now=100)
    store.record_transient_failure(now=101)
    store.record_transient_failure(now=102)
    self.assertTrue(store.circuit_is_open(now=103))
    self.assertFalse(store.circuit_is_open(now=403))

def test_prepared_image_is_bounded_jpeg(self):
    data_url = prepare_image_data_url(np.zeros((2400, 3200, 3), dtype=np.uint8))
    self.assertTrue(data_url.startswith('data:image/jpeg;base64,'))
    self.assertLess(len(base64.b64decode(data_url.split(',', 1)[1])), 1_500_000)
```

- [ ] **Step 2: Run tests and verify modules are missing**

Run: `python -m unittest ai_processor.tests.test_external_ai_state ai_processor.tests.test_external_ai_images -v`

Expected: FAIL.

- [ ] **Step 3: Implement atomic private state**

Store cache entries under `<state_root>\cache\<sha256>.json` with a seven-day expiry and circuit state under `<state_root>\provider-state.json`. Write to a same-directory random temporary file, flush, then `os.replace`. Treat malformed state as a cache miss and regenerate once.

- [ ] **Step 4: Implement stable cache keys and capability markers**

Hash file bytes plus endpoint hostname, model, prompt version, and payload mode. A successful image probe writes a marker containing only endpoint hash, model, probe version, and timestamp. `image_capability_verified` returns false when endpoint/model no longer match.

- [ ] **Step 5: Implement bounded JPEG conversion**

Resize without upscaling, preserve aspect ratio, convert BGR to RGB, encode JPEG quality 80, and reject empty or oversized results with `EXTERNAL_AI_IMAGE_PREPARATION_FAILED`.

- [ ] **Step 6: Implement the probe CLI**

`--config-json` performs no network call and prints only `{"status":"configured|disabled|error","image_enabled":true|false}`. `--text` sends a synthetic schema request. `--image` sends only the committed synthetic fixture and writes the capability marker after a schema-valid response. Never accept a real applicant path on the probe command line.

- [ ] **Step 7: Run state/image tests and CLI config probe**

Run: `python -m unittest ai_processor.tests.test_external_ai_state ai_processor.tests.test_external_ai_images -v`

Run: `python -m ai_processor.external_ai.probe --config-json`

Expected: tests PASS; probe returns disabled or a privacy-safe configuration state without network access.

- [ ] **Step 8: Commit the state/capability unit**

```powershell
git add ai_processor/external_ai/state.py ai_processor/external_ai/images.py ai_processor/external_ai/probe.py ai_processor/tests/test_external_ai_state.py ai_processor/tests/test_external_ai_images.py ai_processor/tests/fixtures/external_ai_probe.png
git commit -m "feat: add external AI cache and capability controls"
```

---

### Task 5: Advisory Postprocessing and Worker Integration

**Files:**
- Create: `ai_processor/external_ai/postprocessing.py`
- Create: `ai_processor/timing.py`
- Modify: `ai_processor/processor.py`
- Modify: `ai_processor/database.py`
- Modify: `ai_processor/tests/test_processing_stages.py`
- Test: `ai_processor/tests/test_external_ai_integration.py`
- Test: `ai_processor/tests/test_processing_timings.py`

**Interfaces:**
- Consumes all Task 1–4 interfaces.
- Produces: `attach_external_assessment(findings: dict, assessment: dict, invocation_reason: str) -> dict`
- Produces: `StageTimings(clock=time.perf_counter)` with `measure(name)` and `as_dict() -> dict[str, int]`
- Adds stage: `external_review` at 75%; moves `tamper_scan` to 88%.
- Persists `findings.external_ai` with status, provider/model, payload mode, invocation reason, cache state, elapsed milliseconds, normalized assessment, and safe error code.

- [ ] **Step 1: Write failing advisory and orchestration tests**

```python
def test_external_conflict_is_advisory_and_does_not_change_local_status(self):
    local = {'status': 'needs_staff_review', 'findings': {}}
    local['findings'] = attach_external_assessment(local['findings'], CONFLICT, 'identity_uncertain')
    self.assertEqual(local['status'], 'needs_staff_review')
    self.assertEqual(local['findings']['external_ai']['assessment']['state'], 'conflict')

def test_provider_timeout_still_finishes_local_review(self):
    self.external_client.assess.return_value = {'status': 'unavailable', 'error_code': 'EXTERNAL_AI_TIMEOUT'}
    self.assertTrue(process_one(document_id=17))
    self.finish.assert_called_once()
    self.fail.assert_not_called()
```

- [ ] **Step 2: Run tests and confirm integration is absent**

Run: `python -m unittest ai_processor.tests.test_external_ai_integration ai_processor.tests.test_processing_stages -v`

Expected: FAIL because the postprocessor/stage is missing.

- [ ] **Step 3: Implement advisory finding attachment**

Never mutate `status`, `confidence_score`, local assessments, or duplicate fields. Normalize unavailable/not-needed/completed provider states and remove any raw response before persistence.

- [ ] **Step 4: Add privacy-safe stage timing**

Implement `StageTimings` as a context-manager-backed collector that records nonnegative integer milliseconds only. Wrap page loading, OCR, local verification, external review, and alteration analysis; persist them under `findings.performance.stage_ms` with `total_ms`, `external_cache_hit`, and OCR fast/enhanced-path metadata. Never store document text or field values in timing data.

- [ ] **Step 5: Integrate conditional invocation after local evaluation**

After `evaluate(...)`, compute the invocation decision. When disabled/not needed/open-circuit, attach the corresponding provider state immediately. Otherwise compute the cache key, reuse a valid cached assessment, or call the client. Supply the first normalized page image only when both configuration and the matching capability marker allow it; otherwise use text mode.

- [ ] **Step 6: Preserve local completion through every provider failure**

Catch only external-package exceptions at the external stage, convert them to a privacy-safe unavailable assessment, and continue to `tamper_scan` and `finish`. Do not route provider failures through `_record_failure`.

- [ ] **Step 7: Persist truthful provider metadata**

Change `finish` to read `result.get('model_service', 'local_ocr_rules')` and `result.get('model_version', 'component-assessments-v2')`. Set service to `hybrid_local_external` only after a completed external assessment; cached external responses retain the hybrid label and record `cache_hit: true`.

- [ ] **Step 8: Update and run stage/integration/timing tests**

Expected stages are `preprocessing:10`, `ocr:35`, `verifying:65`, optional `external_review:75`, and `tamper_scan:88`. Run:

`python -m unittest ai_processor.tests.test_external_ai_integration ai_processor.tests.test_processing_stages ai_processor.tests.test_processing_timings ai_processor.tests.test_core -v`

Expected: PASS.

- [ ] **Step 9: Commit the worker integration**

```powershell
git add ai_processor/external_ai/postprocessing.py ai_processor/timing.py ai_processor/processor.py ai_processor/database.py ai_processor/tests/test_external_ai_integration.py ai_processor/tests/test_processing_stages.py ai_processor/tests/test_processing_timings.py
git commit -m "feat: integrate advisory external AI verification"
```

---

### Task 6: Optional Provider Health Reporting

**Files:**
- Modify: `includes/document-ai-reviews.php`
- Modify: `Pswdo/php_files/ai-health.php`
- Modify: `tests/ai-health-test.php`
- Test: `ai_processor/tests/test_external_ai_probe.py`

**Interfaces:**
- Consumes: `python -m ai_processor.external_ai.probe --config-json`
- Produces health component `external_ai`: `disabled`, `configured`, or `unavailable`
- Overall health: external unavailable degrades but never blocks processing.

- [ ] **Step 1: Write failing Python probe and PHP health tests**

```python
def test_config_probe_contains_no_endpoint_model_or_key(self):
    payload = configuration_payload(self.settings)
    self.assertEqual(set(payload), {'status', 'image_enabled'})
```

Add PHP assertions that `external_ai=disabled` keeps core health operational and `external_ai=unavailable` produces degraded—not unavailable—health.

- [ ] **Step 2: Run tests and confirm missing health behavior**

Run: `python -m unittest ai_processor.tests.test_external_ai_probe -v`

Run: `C:\xampp\php\php.exe tests\ai-health-test.php`

Expected: FAIL on the missing provider component rules.

- [ ] **Step 3: Extend the import-only Python health probe**

After the existing worker import succeeds, execute the config-only module and parse its one-line JSON. Do not make a network request from the 45-second cached PSWDO health endpoint.

- [ ] **Step 4: Add optional-component aggregation**

Treat `disabled` and `configured` as non-blocking. Treat `unavailable` as degraded. Keep database, Python, worker, Tesseract, and Poppler as the only components that can make overall processing unavailable.

- [ ] **Step 5: Run Python/PHP health tests and lint**

Run: `python -m unittest ai_processor.tests.test_external_ai_probe -v`

Run: `C:\xampp\php\php.exe -l includes\document-ai-reviews.php`

Run: `C:\xampp\php\php.exe -l Pswdo\php_files\ai-health.php`

Run: `C:\xampp\php\php.exe tests\ai-health-test.php`

Expected: all PASS/no syntax errors.

- [ ] **Step 6: Commit health reporting**

```powershell
git add includes/document-ai-reviews.php Pswdo/php_files/ai-health.php tests/ai-health-test.php ai_processor/tests/test_external_ai_probe.py
git commit -m "feat: report external AI provider health"
```

---

### Task 7: PSWDO Provider Evidence UI

**Files:**
- Modify: `Pswdo/php_files/pswdo-common.php`
- Modify: `Pswdo/php_files/request-management.php`
- Modify: `Pswdo/php_files/reports.php`
- Modify: `Pswdo/php_files/pswdo-data-lib.php`
- Modify: `Pswdo/css files/pswdo-pages.css`
- Modify: `tests/pswdo-ai-component-results-test.php`
- Test: `tests/pswdo-external-ai-provider-ui-test.php`

**Interfaces:**
- Produces: `pswdo_external_ai_provider_meta(array $findings): array{state:string,label:string,detail:string,icon:string}`
- Displays exactly one provider line beneath the four established component cards.

- [ ] **Step 1: Write failing presentation tests**

```php
$meta = pswdo_external_ai_provider_meta([
    'external_ai' => ['status' => 'completed', 'payload_mode' => 'text', 'cache_hit' => false],
]);
provider_assert($meta['label'] === 'External AI checked', 'Completed provider label is incorrect.');
provider_assert(str_contains($meta['detail'], 'OCR text'), 'Text-only evidence is not disclosed.');
```

Also assert unavailable says local checks still completed, not-needed says local evidence was sufficient, and no output includes endpoint, model, key, prompt, or confidence percentage.

- [ ] **Step 2: Run tests and verify the helper/UI is missing**

Run: `C:\xampp\php\php.exe tests\pswdo-external-ai-provider-ui-test.php`

Expected: FAIL.

- [ ] **Step 3: Implement the normalized provider helper**

Map completed text mode to `External AI checked / OCR text reviewed`, completed image mode to `External AI checked / Document image reviewed`, unavailable to `External AI unavailable / Local checks completed`, not-needed to `External AI not needed / Local evidence was sufficient`, and legacy/missing to `Provider details unavailable`.

- [ ] **Step 4: Render and style the compact provider line**

Place it below `.ai-component-grid`; use the existing neutral/success/warning palette, one icon, a short label, and one short detail. Do not add another large card or expose technical error strings to staff.

- [ ] **Step 5: Run UI contract tests and PHP lint**

Run: `C:\xampp\php\php.exe tests\pswdo-ai-component-results-test.php`

Run: `C:\xampp\php\php.exe tests\pswdo-external-ai-provider-ui-test.php`

Run: `C:\xampp\php\php.exe -l Pswdo\php_files\pswdo-common.php`

Run: `C:\xampp\php\php.exe -l Pswdo\php_files\request-management.php`

Expected: all PASS/no syntax errors.

- [ ] **Step 6: Commit the provider UI**

```powershell
git add Pswdo/php_files/pswdo-common.php Pswdo/php_files/request-management.php "Pswdo/css files/pswdo-pages.css" tests/pswdo-ai-component-results-test.php tests/pswdo-external-ai-provider-ui-test.php
git commit -m "feat: show external AI evidence status"
```

---

### Task 8: Staff Feedback and Provider Operational Metrics

**Files:**
- Modify: `database/AICSdb.sql`
- Modify: `includes/document-ai-reviews.php`
- Modify: `Pswdo/php_files/workflow-action.php`
- Modify: `Pswdo/php_files/request-management.php`
- Modify: `ai_processor/evaluation.py`
- Test: `tests/ai-review-feedback-test.php`
- Modify: `ai_processor/tests/test_evaluation_metrics.py`

**Interfaces:**
- Produces table: `document_ai_review_feedback(id, ai_review_id, reviewed_by_account_id, ai_suggested_action, staff_outcome, agreement_state, corrected_document_type, reason_code, remarks, created_at)`
- Produces: `aics_record_ai_review_feedback(mysqli $connection, int $reviewId, int $staffId, array $feedback): int`
- Produces action: `record_ai_review_feedback`
- Produces evaluation fields: `external_invocation_rate`, `external_cache_hit_rate`, `external_unavailable_rate`, `external_median_ms`, `external_p95_ms`, `staff_agreement_rate`, `staff_override_rate`
- Produces: `evaluate_operational_records(records: list[dict], feedback: list[dict]) -> dict`

- [ ] **Step 1: Write failing schema, authorization, and metric tests**

```php
$feedbackId = aics_record_ai_review_feedback($connection, $reviewId, $pswdoAccountId, [
    'ai_suggested_action' => 'staff_attention_needed',
    'staff_outcome' => 'accepted_for_manual_review',
    'agreement_state' => 'agreed',
    'reason_code' => 'STAFF_CONFIRMED_REVIEW',
    'remarks' => '',
]);
feedback_assert($feedbackId > 0, 'AI review feedback was not stored.');
```

Add Python metric fixtures with one completed API call, one cache hit, one timeout, and one staff override; assert the exact rates and that the report labels them operational metrics rather than accuracy.

- [ ] **Step 2: Run tests and confirm persistence/metrics are missing**

Run: `C:\xampp\php\php.exe tests\ai-review-feedback-test.php`

Run: `python -m unittest ai_processor.tests.test_evaluation_metrics -v`

Expected: FAIL on missing feedback table/helper and provider metrics.

- [ ] **Step 3: Add the idempotent feedback schema**

Add the table to `database/AICSdb.sql` and `aics_ensure_ai_review_schema()`. Use foreign keys to `document_ai_reviews` and `aics_accounts`, indexes on review/staff/created date, enum-like `VARCHAR` values validated in PHP, and immutable insert-only rows.

- [ ] **Step 4: Implement authorized feedback recording**

Allow only a real PSWDO account, review IDs belonging to an existing request document, agreement states `agreed|overridden|not_applicable`, suggested actions `ready_for_staff_review|staff_attention_needed|reupload_recommended|verification_unavailable`, staff outcomes `document_accepted|accepted_for_manual_review|reupload_requested|not_applicable`, reason codes matching `^[A-Z0-9_]{3,64}$`, and remarks up to 500 characters. Add `aics_ai_supported_document_types()` returning exactly `valid_id`, `med_cert`, `billing_statement`, `death_cert`, `funeral_contract`, `report_police_med`, `school_assessment`, `registration_form`, `mdrrmo_cert`, `eclip_cert`, `crisis_situation_cert`, and `damaged_house_photo`; reject any other corrected type. Use a transaction and parameterized statements.

- [ ] **Step 5: Add the compact feedback action to the review modal**

After staff reviews the external evidence, show `Agree with suggestion` and `Override` actions. Override requires a reason selection; neither action changes request approval automatically. Submit through the existing CSRF-protected `workflow-action.php` pattern and return through the existing two-second top toaster behavior.

- [ ] **Step 6: Extend operational evaluation**

Read persisted `findings.external_ai` and feedback rows. Compute invocation/cache/unavailable rates and median/p95 elapsed milliseconds. Compute agreement/override rates only for reviews with feedback. Label every value `operational`, never `accuracy`, `precision`, or `fraud detection rate`.

- [ ] **Step 7: Add compact provider metrics to PSWDO Reports**

Add a data-library query that reads only provider status, cache flag, elapsed milliseconds, payload mode, and feedback agreement state. Render a compact `Verification Operations` section with invocation, unavailable, cache-hit, median/p95 latency, agreement, and override values. Display `No reviewed records yet` instead of dividing by zero, and include the sentence `Operational workflow metrics; not validated model accuracy.`

- [ ] **Step 8: Run schema, PHP, report, and evaluation tests**

Run:

```powershell
C:\xampp\php\php.exe -l includes\document-ai-reviews.php
C:\xampp\php\php.exe -l Pswdo\php_files\workflow-action.php
C:\xampp\php\php.exe -l Pswdo\php_files\request-management.php
C:\xampp\php\php.exe -l Pswdo\php_files\reports.php
C:\xampp\php\php.exe -l Pswdo\php_files\pswdo-data-lib.php
C:\xampp\php\php.exe tests\ai-review-feedback-test.php
python -m unittest ai_processor.tests.test_evaluation_metrics -v
```

Expected: all PASS/no syntax errors.

- [ ] **Step 9: Commit feedback and metrics**

```powershell
git add database/AICSdb.sql includes/document-ai-reviews.php Pswdo/php_files/workflow-action.php Pswdo/php_files/request-management.php Pswdo/php_files/reports.php Pswdo/php_files/pswdo-data-lib.php ai_processor/evaluation.py tests/ai-review-feedback-test.php ai_processor/tests/test_evaluation_metrics.py
git commit -m "feat: record AI review feedback and provider metrics"
```

---

### Task 9: Deployment Documentation, Security Regression, and End-to-End Verification

**Files:**
- Modify: `ai_processor/README.md`
- Modify: `ai_processor/DEPLOYMENT.md`
- Create: `tests/external-ai-secret-regression.ps1`
- Modify: `Readme.md`

**Interfaces:**
- Documents secure setup command, text probe, synthetic image probe, worker run, fallback semantics, and key rotation.
- Produces a repository secret regression that fails when a live-looking bearer credential is committed outside explicitly excluded generated/log/upload paths.

- [ ] **Step 1: Write the failing secret regression**

The script searches tracked files only with `git grep -n -E 'sk-[A-Za-z0-9_-]{20,}|Authorization:[[:space:]]*Bearer[[:space:]]+[A-Za-z0-9_-]{12,}'` and exits nonzero when a match exists. Test fixtures must use values such as `replacement-test-key`, never live-looking keys.

- [ ] **Step 2: Run the secret regression before documentation updates**

Run: `powershell -ExecutionPolicy Bypass -File tests\external-ai-secret-regression.ps1`

Expected: PASS if the exposed credential was never copied into the repository; otherwise remove it before continuing.

- [ ] **Step 3: Document exact local setup**

Document these commands without embedding a key:

```powershell
powershell -ExecutionPolicy Bypass -File tools\setup-external-ai.ps1
python -m ai_processor.external_ai.probe --config-json
python -m ai_processor.external_ai.probe --text
python -m ai_processor.external_ai.probe --image
python -m ai_processor.processor --once
```

State that `--image` uses only the committed synthetic fixture, that real-document image mode requires PSWDO authorization, and that local review continues if any probe/provider call fails.

- [ ] **Step 4: Run the complete Python suite**

Run: `python -m unittest discover -s ai_processor\tests -p "test_*.py" -q`

Expected: PASS with no real network calls.

- [ ] **Step 5: Run relevant PHP regressions**

Run:

```powershell
C:\xampp\php\php.exe tests\ai-health-test.php
C:\xampp\php\php.exe tests\ai-submission-lifecycle-contract-test.php
C:\xampp\php\php.exe tests\ai-review-reprocess-test.php
C:\xampp\php\php.exe tests\pswdo-ai-component-results-test.php
C:\xampp\php\php.exe tests\pswdo-external-ai-provider-ui-test.php
C:\xampp\php\php.exe tests\pswdo-duplicate-guard-test.php
```

Expected: every script prints its pass message and exits `0`.

- [ ] **Step 6: Verify submission remains decoupled and duplicate detection remains local**

Run: `rg -n "external_ai|chat/completions" Applicants Pswdo/php_files/pswdo-data-lib.php`

Expected: no external client invocation in applicant submission files or `pswdo_find_application_duplicates`; integration exists only in the Python background worker and presentation/health code.

- [ ] **Step 7: Run syntax, privacy, and repository checks**

Run:

```powershell
python -m compileall -q ai_processor
powershell -ExecutionPolicy Bypass -File tests\external-ai-secret-regression.ps1
git diff --check
git status --short
```

Expected: compile succeeds, secret scan passes, no whitespace errors, and only intended files are staged for this feature.

- [ ] **Step 8: Perform the live provider smoke test only after key rotation**

Run the setup script with the replacement key, then run `--text`. Run `--image` only with the synthetic fixture. Confirm the returned status is schema-valid; never print or copy the private environment file. If the endpoint rejects the model or image format, keep image mode disabled and verify `python -m ai_processor.processor --once` still stores a completed local review with `external_ai.status=unavailable`.

- [ ] **Step 9: Commit documentation and verification tooling**

```powershell
git add ai_processor/README.md ai_processor/DEPLOYMENT.md Readme.md tests/external-ai-secret-regression.ps1
git commit -m "docs: add hybrid AI deployment and verification"
```

---

## Completion Evidence

Before reporting completion, capture and report:

- focused test output for every task;
- full Python suite result and test count;
- all listed PHP regression exit codes;
- secret-regression result;
- text capability probe result using the rotated credential;
- image capability result using only the synthetic fixture;
- one local-fallback worker result with the provider deliberately unavailable;
- confirmation that applicant submission performed no external request;
- confirmation that no credential or raw document content appears in Git, logs, browser payloads, or persisted findings.
