# AICS AI Submission Gate — Design

Date: 2026-10-19 · Status: proposed (revised after owner review: only AI-passed requests reach PSWDO)

## Problem

Every submitted request appears in the PSWDO Request Management table immediately, even when AICS AI
has already found that a document is blurred, edited, or the wrong document. If 1,000 applicants send
blurred documents, PSWDO staff would have to open and remind each one manually. Applicants also hear
nothing about when PSWDO will act.

## Rule

**PSWDO only ever receives requests whose documents passed the AICS AI check.** Nothing unchecked
reaches PSWDO — not on an AI outage, not after a timeout.

## What changes

1. **Requests wait for AICS AI.** A new submission starts *pending* and is invisible to PSWDO until
   every document has a final AI result.
2. **Passed requests go to PSWDO.** When every verifiable document is `likely_valid` (or the legacy
   `completed`), the request is released to PSWDO and the applicant is told the expected processing time.
3. **Failed documents go back to the applicant, not PSWDO.** Any `needs_reupload` (blurry, cut off,
   incomplete) or `flagged_inconsistency` (does not match the application, looks edited) holds the
   request. The applicant gets a notification and a status-page banner naming each document and the
   reason; re-uploading re-runs the check automatically. No staff action is needed.
4. **Uncertain results go to the admin, not PSWDO.** `needs_staff_review` (the AI could not confirm a
   check) puts the request in *admin review*. The admin either releases it to PSWDO or asks the
   applicant to re-upload.
5. **AICS AI is made to finish.** Checks start automatically when the applicant submits (today they only
   start when PSWDO opens a request, which can no longer happen for unchecked requests). At most
   `AICS_AI_MAX_WORKERS` (default 2) checks run at once, so a rush of submissions queues instead of
   overloading the server. Stalled checks are recovered and the worker relaunched; failed checks
   are retried automatically up to the existing `AICS_AI_REVIEW_MAX_ATTEMPTS`. If a check still fails,
   the request moves to *admin review* (reason: AI could not check). Requests waiting longer than
   `AICS_AI_GATE_ALERT_MINUTES` (default 30) and any AI service problem are shown as an alert on the
   admin page. Waiting requests are **never** released to PSWDO automatically.
6. **Admin sees everything for 7 days.** A new admin page, *AICS AI Screening*, shows requests needing
   an admin decision, the AI service status, and a log of every hold, pass, retry, applicant edit or
   re-upload and admin action with the AI findings. Log records older than 7 days are deleted
   automatically. PSWDO never sees these findings.
7. **Urgency from documents for all medical requests.** The Smart Priority medical analysis runs for
   every Medical request (today: senior citizen and PWD only); a scheduled or required operation or
   surgery counts as urgent. Urgent requests are told "about 1 day".

## Decisions

| # | Decision |
|---|---|
| D1 | New column `aics_requests.ai_gate_status`: `pending`, `held` (applicant must fix), `admin_review`, `released`, with `ai_gate_reason` recording why. Existing rows become `released` (PSWDO's view of current data is unchanged); new rows default to `pending`. |
| D2 | Precedence when documents disagree: any blocking (`needs_reupload`, `flagged_inconsistency`) → `held`; else any `failed` → retry (stays `pending`); else any non-final → `pending`; else any `needs_staff_review` → `admin_review`; else → `released`. A request with no verifiable documents is `released` (nothing for the AI to check). |
| D3 | Retries: a `failed` review with attempts left is retried with `aics_retry_ai_review()` and its document worker launched with `aics_launch_document_ai_processor()` (a failed launch marks it failed again with `aics_mark_ai_review_failed()`). When the attempt limit (`AICS_AI_REVIEW_MAX_ATTEMPTS`, 3) is reached the request goes to `admin_review`. Stalled and unclaimed work is handled by the existing `aics_dispatch_unclaimed_ai_reviews_for_request()` (turns stalled claimed rows into `failed`, creates missing reviews, launches one request worker under the existing lease). |
| D4 | A `held` request returns to `pending` when the applicant changes its documents. Released requests are never pulled back from PSWDO. |
| D5 | Admin actions on non-released requests: **Release to PSWDO**, **Ask applicant to re-upload** (`admin_review` → `held`), **Retry AICS AI check** (resets `attempt_count` to 0 on the request's `failed` reviews and returns it to `pending`, so the next sync retries them). Each is written to the 7-day log and the admin audit log. |
| D6 | Gate decisions and AI driving run in `aics_ai_gate_sync()`: called after an applicant saves documents, every 60 seconds by a scheduler loop in the container, by the AI worker (through a PHP CLI tool) right after it finishes so the next queued check starts at once, and on applicant, PSWDO and admin page loads. Transitions use `UPDATE … WHERE ai_gate_status = <old>` so concurrent callers produce one log entry and one notification. |
| D7 | Expected processing time: URGENT **1 day**, HIGH **3 days**, STANDARD **7 days**; overridable with `AICS_ETA_URGENT_DAYS`, `AICS_ETA_HIGH_DAYS`, `AICS_ETA_STANDARD_DAYS`. |
| D8 | Medical priority baseline: senior citizen/PWD → HIGH, others → STANDARD; the medical urgency analysis can raise either to URGENT. Non-medical requests are unchanged. |
| D9 | Log records are deleted 7 days after creation. Requests, documents and AI reviews themselves are **not** deleted. |
| D10 | All applicant- and admin-facing text says "AICS AI", never "Gemini". Feedback is in-app (notification + status page), not SMS. |

## Applicant copy (exact)

- Held (`ai_check_hold`): `AICS AI found a problem with {count} document(s) in request {ref}: {items}. Upload a clear replacement in Manage Applications. It will be checked again automatically.`
  - `{items}` joins `{document label} ({reason})` with `; `. Reasons: `needs_reupload` → `blurry, cut off, or incomplete`; `flagged_inconsistency` → `does not match your application or looks edited`; `needs_staff_review` and `failed` (admin asked for a re-upload) → `needs a clearer copy`.
- Admin review (`ai_check_admin_review`): `Request {ref} is being reviewed before it goes to PSWDO. You will be notified when it moves forward.`
- Released after a passed check (`ai_check_passed`): `AICS AI checked the documents for request {ref}. PSWDO will now review it. Expected processing time: about {days} day(s).` — URGENT adds `Your request was marked urgent.`
- Released by an admin (`ai_check_admin_release`): `Request {ref} is now with PSWDO for review. Expected processing time: about {days} day(s).`
- Status page: pending `AICS AI is checking your documents.`; admin review `Your documents are being reviewed before they go to PSWDO.`; released `Expected processing time: about {days} day(s)`.

## Non-goals

SMS for these notices; automatically cancelling held requests; changes to the Python document
verifier; deleting requests or documents after 7 days.

## Risks

- **AI outage stalls new applications.** By design they wait instead of reaching PSWDO unchecked.
  Mitigated by automatic retries, the admin alert with AI service status, and admin release/retry.
- **AI mistakes can hold a valid request.** Only clear failures go back to the applicant; uncertain
  results go to the admin, who can release them.
