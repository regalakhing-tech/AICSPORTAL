# One-time applicant data reset

**Goal:** Clear applicant accounts, applications, and their documents from the live local AICS instance while retaining admin and PSWDO accounts and their configuration.

**Scope confirmed:** All applicant data, including two released applications, may be removed. Keep existing admin backups and staff records.

## Preflight

- Confirm the target database is `AICSdb` and the role codes are `applicat`, `pswdo`, and `admin`.
- Require that all request owners are applicant accounts. Stop if a request is unlinked or staff-owned.
- Record counts for applicant, admin, and PSWDO accounts and applicant-related tables. Confirm storage roots contain no reparse points.

## Database reset

- In one transaction, select applicant account IDs and their request IDs by role. Delete request worker leases and applicant ID verification rows that have no cascading foreign key.
- Delete applicant-owned requests. Let foreign keys remove application documents, review records, notifications, schedules, and history.
- Delete only accounts in the `applicat` role. Let foreign keys remove profiles, drafts, password resets, and account-request links.
- Confirm all applicant accounts, requests, documents, drafts, and ID verification rows are zero before commit. Confirm admin and PSWDO account and staff profile counts are unchanged.

## File reset

- Remove only children of `Applicants/uploads`, private `applicant_ids`, `signup_ids`, `ai_review_artifacts`, and the draft-upload temporary root.
- Clear document-analysis cache entries under private `external_ai/cache`, `external_ai/gemini_scanner/cache`, and `external_ai/smart_priority`. Keep provider configuration and cache directories.
- Resolve and validate each absolute path against its expected root before a recursive removal. Do not follow reparse points.

## Verification

- Requery database counts and staff account/profile counts.
- Confirm the scoped upload and cache roots are empty and that admin backups, staff accounts, and staff profiles remain.
- Report counts and any path that could not be removed. No new backup is created, to avoid retaining another copy of applicant data.

## Execution result (2026-10-08)

- Removed 3 applicant accounts, 2 released applications, and 8 linked document records in one transaction. Dependent applicant, review, draft, and notification rows are zero.
- Removed 274 direct entries across the eight scoped applicant file roots. Each root is empty after cleanup.
- Admin and PSWDO accounts remain at one each; both staff profiles remain. Admin audit logs remain at 20 rows and admin login attempts at 242 rows. Five admin backup files were left intact.
- Existing admin backups and logs were deliberately retained under the user's instruction not to touch admin and PSWDO data.
