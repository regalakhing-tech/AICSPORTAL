# Applicant Sign-up ID Verification Design

Date: 2026-10-01

## Purpose

Before an applicant account is created, the applicant uploads the **front and back of a valid ID**. AICS AI reads the ID, and the account's registered name and birth date must match it. This stops accounts registered under a different person's name, which is the start of most AICS fraud, and gives staff a verified identity for every applicant. As everywhere else in the system, AI results support people; staff keep the final say.

## Decisions

| Decision | Choice |
|---|---|
| Where | New first step in the existing sign-up wizard: **Verify ID → Personal Details → Security** |
| What is read | ID type, last name, given names, middle name, birth date; whether the back belongs to the same ID; edited-photo checks |
| Name rule | Server-enforced at account creation. Last name equal; first given name equal; extra given names allowed; middle name may be an initial. Case, accents (Ñ/N), punctuation, and one-letter misreads on names of 5+ letters are tolerated |
| Birth date rule | Must equal the ID's birth date when the ID shows one |
| Pre-fill | Name and birth date fields are pre-filled from the ID. The applicant may correct a misread letter, but the server still enforces the rule |
| AI unavailable | Sign-up continues; the ID is stored as **pending staff check** |
| Unreadable / not an ID / back from a different card | Applicant is asked to retake the photo; no account yet |
| Edited photo | Strong evidence (typed-on text or a clear AI finding) asks for an original photo. Weaker hints are stored for staff |
| Later name edits | Name and birth date are locked in My Profile once the ID is verified |
| Storage | `C:\xampp\aics_private\` (outside the web root). Abandoned sign-up scans are deleted after 24 hours |

## Components

1. `ai_processor/id_check.py`: CLI `python -m ai_processor.id_check --front F --back B`, which prints one JSON object. It does the following:
   - Uses the AICS AI client with an ID-specific instruction and schema; the client gains optional instruction/schema/normalizer parameters.
   - Adds the local typed-on-text detector and editor metadata for both images.
   - Returns a `decision`: `ok` | `retake` | `edited` | `unavailable`, plus the fields above.
2. `includes/applicant-id-verification.php`: name and birth-date matching, the private storage paths, the `aics_applicant_id_verifications` table, cleanup, and the Python runner (synchronous, 60-second timeout).
3. `Applicants/php_files/signup-id-scan.php`: the JSON endpoint. It validates the two images (JPEG/PNG/WebP, 10 MB or less each), applies rate limits (6 scans per sign-up attempt, 20 per session per hour), stores the files privately, runs the check, and keeps the result in the session by sign-up attempt.
4. `auth-signup.php`:
   - requires an ID scan for the attempt;
   - enforces the name and birth-date rules for `ok` results;
   - accepts `unavailable` as pending;
   - moves the files into the account's private folder and records the verification row.
5. `profile-save.php` and My Profile: name and birth date are read-only when verified.
6. `login.html`, `login.js`, `login.css`: the new step with front and back tiles (camera on phones), a "Scan my ID" button, the result panel, and pre-fill.

## Privacy and safety

- ID images are never stored under the web root.
- No ID numbers are stored.
- Logs contain no names.
- The session stores only the extracted name, birth date, ID type, decision, and private file paths.
- The result card states that staff make the final decision.

## Testing

- **Python:** contract normalization, the decision rules, and the CLI with a fake client.
- **PHP:** name and birth-date matching cases, the sign-up enforcement contract, the endpoint validation contract, and profile locking.
- **Live:** synthetic front and back IDs (`tests/edited-documents/ids.py`) through the CLI with AICS AI, plus a browser run of the whole sign-up in Edge. The test account is removed afterwards.
