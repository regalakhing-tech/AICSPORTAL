# Database and Authentication Recovery Design

## Goal

Restore the existing XAMPP deployment without deleting accounts or applications, make the checked-in SQL capable of creating every core workflow table, and verify applicant registration, login, and the main Applicant, PSWDO, and Admin database paths.

## Current State and Root Cause

- Apache and MySQL are running, and PHP connects successfully to the existing `aicsdb` database with the configured XAMPP credentials.
- The live database contains existing user and application data and must be preserved.
- `database/AICSdb.sql` defines 18 tables, while the current application depends on additional runtime-created tables.
- `aics_request_release_schedule` is referenced by authentication initialization and multiple PSWDO workflows but is absent from both the live database and the SQL file.
- `aics_ensure_auth_schema()` alters that missing release table unconditionally. The resulting exception is caught by login and signup handlers and appears to the user as a generic authentication failure.
- The registration UI permits an omitted email, but the live `aics_accounts.email` column is still `NOT NULL`.

## Chosen Approach

Use a non-destructive, in-place migration. Create a full SQL backup first, test the corrected schema against an isolated temporary database, then apply only additive or compatible schema changes to `aicsdb`. Existing records and uploaded documents remain untouched.

## Architecture and Responsibilities

### Canonical schema

`database/AICSdb.sql` is the canonical fresh-install schema. It will include all core applicant and PSWDO workflow tables, including pre-screening and release scheduling, plus compatible seed/reference data. Statements must be idempotent so importing the file again does not remove existing data.

### Authentication bootstrap

`Applicants/php_files/auth-common.php` will manage authentication-owned tables and columns only. It must not fail login or registration because an unrelated PSWDO workflow table is absent. Applicant email remains unique when present but is nullable.

### Installation and migration

The live repair sequence is:

1. Export all of `aicsdb` with routines, triggers, events, schema, and data to a timestamped backup outside the web-accessible application folders.
2. Import the corrected idempotent SQL into a temporary database and verify its required schema.
3. Import the corrected SQL into `aicsdb`.
4. Run the compatible column migration needed by existing installations.
5. Verify row counts for existing accounts and requests have not decreased.

## Data Flow

Registration validates the submitted fields, ensures only the auth schema, creates an account and applicant profile in one transaction, starts the session, and redirects to the applicant dashboard. Login ensures only the auth schema, looks up the applicant by email or phone, verifies the password hash and active status, records the attempt, starts the session, and redirects to the dashboard.

PSWDO release and screening features read and write their dedicated pre-screening and release-schedule tables. Those tables are created by the canonical schema rather than indirectly during authentication.

## Error Handling and Safety

- No database drop, truncate, or destructive reset is permitted.
- The backup must exist and be non-empty before migration begins.
- Temporary verification data uses unique generated identifiers and is removed after the test.
- Authentication handlers retain user-friendly redirects, while automated tests expose underlying failures.
- Any failed schema import or decreased preservation count stops the procedure before further verification.

## Verification

- A schema regression test imports `AICSdb.sql` into a disposable database and asserts all required tables and critical columns exist.
- An auth regression test demonstrates that authentication schema initialization succeeds even when only auth tables exist.
- HTTP verification creates a temporary applicant through the real signup endpoint, confirms the authenticated dashboard is reachable, logs out, logs in through the real login endpoint, and deletes the temporary account afterward.
- Existing PHP lint and database/dashboard/duplicate-guard smoke tests must pass.
- Applicant, PSWDO, and Admin entry pages must return successful HTTP responses, and database-backed dashboard smoke queries must complete without exceptions.

## Success Criteria

- Existing account and request counts are preserved.
- The live database contains the release-schedule and pre-screening tables.
- Applicant signup and subsequent login work through Apache.
- Database setup from the checked-in SQL is repeatable on a fresh XAMPP database.
- All verification commands complete successfully without PHP or MySQL errors.
