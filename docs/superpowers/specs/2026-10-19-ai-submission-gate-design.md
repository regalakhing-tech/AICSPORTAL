# AICS AI Submission Gate — Design

Date: 2026-10-19 · Status: proposed (awaiting owner review)

## Problem

Every submitted request appears in the PSWDO Request Management table immediately, even when AICS AI
has already found that a document is blurred, edited, or the wrong document. Staff spend time on
requests the applicant must fix anyway, and applicants hear nothing about when PSWDO will act.

## What changes

1. **Requests are held until AICS AI has checked them.** A new submission starts in a *pending*
   state and is invisible to PSWDO until the AI review of its documents finishes.
2. **Failed checks never reach PSWDO.** If any document's current AI result is `needs_reupload`
   (blurred, cut off, incomplete) or `flagged_inconsistency` (does not match the application,
   edited, tampered), the request is *held*. PSWDO does not see it in any list, count, chart, map or
   priority card, and PSWDO actions on it are refused.
3. **The applicant is told what to fix.** Held requests produce an in-app notification and a banner
   on the Application Status page naming each document and the reason in plain words. Re-uploading
   a document re-runs the AI check automatically.
4. **Admin sees everything for 7 days.** A new admin page, *AICS AI Screening*, lists every hold,
   release, applicant edit/re-upload, timeout and admin action from the last 7 days with the AI
   findings. Records older than 7 days are deleted automatically. PSWDO never sees these findings.
5. **Admin can release a held request to PSWDO** after reviewing it (safety valve for AI mistakes).
6. **Passed requests get an expected processing time.** When a request is released, the applicant
   gets a notification and a status-page line with the expected processing time from its priority.
7. **Urgency from documents for all medical requests.** The existing Smart Priority medical analysis
   runs for every Medical request (today: senior citizen and PWD only), and a scheduled or required
   operation/surgery counts as urgent.

## Decisions

| # | Decision |
|---|---|
| D1 | New column `aics_requests.ai_gate_status` with values `pending`, `held`, `released`. Existing rows become `released` (PSWDO's view of current data is unchanged); new rows default to `pending`. |
| D2 | A request is **held** when at least one verifiable document's current review status is `needs_reupload` or `flagged_inconsistency`. `likely_valid`, `needs_staff_review`, `completed`, and `failed` do not hold a request. |
| D3 | A pending request is **released** when every verifiable document's review is terminal and none blocks; when it has no verifiable documents; or when it has been pending longer than `AICS_AI_GATE_TIMEOUT_MINUTES` (default 30) — so an AI outage never hides a request from PSWDO. |
| D4 | A held request returns to **pending** when the applicant changes its documents, and is re-decided after the new AI check. Released requests are never pulled back from PSWDO. |
| D5 | Gate decisions run lazily in `aics_ai_gate_sync()`, called from applicant page loads/polls, PSWDO list loads and the admin page; transitions use a conditional `UPDATE … WHERE ai_gate_status = <old>` so concurrent callers produce one event and one notification. |
| D6 | Expected processing time by priority indicator: URGENT **1 day**, HIGH **3 days**, STANDARD **7 days**; overridable with `AICS_ETA_URGENT_DAYS`, `AICS_ETA_HIGH_DAYS`, `AICS_ETA_STANDARD_DAYS`. |
| D7 | Priority baseline for Medical requests: senior citizen/PWD → HIGH, others → STANDARD; the medical urgency analysis can raise either to URGENT. Non-medical requests are unchanged. |
| D8 | Admin event records are deleted 7 days after creation. Requests, documents and AI reviews themselves are **not** deleted. |
| D9 | All new applicant- and admin-facing text says "AICS AI", never "Gemini". |

## Applicant copy (exact)

- Held (`ai_check_hold`): `AICS AI found a problem with {count} document(s) in request {ref}: {items}. Upload a clear replacement in Manage Applications. It will be checked again automatically.`
  - `{items}` joins `{document label} ({reason})` with `; `, where reason is
    `needs_reupload` → `blurry, cut off, or incomplete` and `flagged_inconsistency` → `does not match your application or looks edited`.
- Released after a passed check (`ai_check_passed`): `AICS AI checked the documents for request {ref}. PSWDO will now review it. Expected processing time: about {days} day(s).`
  - URGENT adds the sentence: `Your request was marked urgent.`
- Released by timeout (`ai_check_skipped`) or by admin (`ai_check_admin_release`): `Request {ref} is now with PSWDO for review. Expected processing time: about {days} day(s).`
- Status page line for released requests: `Expected processing time: about {days} day(s)`.

## Non-goals

SMS for these notices; automatically cancelling held requests; changes to the Python document
verifier; deleting requests or documents after 7 days.

## Risk

AI mistakes can hold a valid request. Mitigations: only the two blocking verdicts hold, an AI outage
releases after 30 minutes, and an admin can release any held request.
