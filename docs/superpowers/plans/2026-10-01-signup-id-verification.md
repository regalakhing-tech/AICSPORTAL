# Sign-up ID Verification Implementation Plan

**Spec:** `docs/superpowers/specs/2026-10-01-signup-id-verification-design.md`. Test-first for every task.

- [x] 1. AICS AI client accepts an optional instruction, schema, and normalizer (the default stays the document scanner).
- [x] 2. `ai_processor/id_check.py`: ID prompt and schema, normalization, decision rules, and CLI JSON output (fake-client tests).
- [x] 3. Synthetic ID backs in `tests/edited-documents/ids.py`; live CLI check with AICS AI.
- [x] 4. `includes/applicant-id-verification.php`: name and birth-date matching, storage paths, table, cleanup, and the Python runner (PHP tests).
- [x] 5. `signup-id-scan.php` endpoint and enforcement in `auth-signup.php` (contract tests).
- [x] 6. Lock name and birth date in My Profile when verified.
- [x] 7. Sign-up UI step (HTML, JS, CSS) with pre-fill and messages.
- [x] 8. Verification: full Python suite (403) and PHP tests, and a live run of the ID step in Edge through Apache, PHP, Python, and AICS AI. A synthetic ID was correctly refused, so no test account was created. The success display and pre-fill were checked with a sample result. The test photos were removed.
  Open: a run with a real ID photo to see the `ok` path end to end.
