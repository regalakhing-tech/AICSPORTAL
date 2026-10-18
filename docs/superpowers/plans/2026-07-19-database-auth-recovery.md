# Database and Authentication Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repair the populated XAMPP database without data loss and make applicant signup, login, and the application database workflows operational.

**Architecture:** Keep `database/AICSdb.sql` as an idempotent canonical schema, keep authentication bootstrap limited to authentication-owned objects, and apply the schema to the live database only after an isolated import test and full backup. Verify the real HTTP signup/login flow with temporary data that is removed afterward.

**Tech Stack:** PHP 8.0, MariaDB/MySQL through XAMPP, mysqli, Apache, PowerShell, curl

## Global Constraints

- Preserve every existing account, request, document, and uploaded file.
- Do not drop, truncate, or rebuild the live `aicsdb` database.
- Store the pre-migration dump outside the web-accessible `htdocs` directory.
- Use test-first changes and verify the expected failure before editing production code.
- Preserve unrelated user changes in the dirty working tree.

---

## File Structure

- `tests/database-schema-regression.php`: disposable-database regression test for the canonical schema and auth-only bootstrap.
- `Applicants/php_files/auth-common.php`: authentication schema ownership and compatibility migrations.
- `database/AICSdb.sql`: idempotent fresh-install and in-place compatible schema.
- `Readme.md`: exact XAMPP import, backup, and verification commands.

### Task 1: Reproduce Schema and Authentication Bootstrap Failures

**Files:**
- Create: `tests/database-schema-regression.php`
- Read: `database/AICSdb.sql`
- Read: `Applicants/php_files/auth-common.php`

**Interfaces:**
- Consumes: `AICS_DB_HOST`, `AICS_DB_USER`, `AICS_DB_PASS`, `AICS_DB_PORT`, `aics_ensure_auth_schema(mysqli $connection): void`
- Produces: an executable regression test that creates only databases whose names match `^aics_recovery_test_[a-z0-9_]+$` and always removes them

- [ ] **Step 1: Write the failing regression test**

Create a test that:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

function recovery_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function recovery_drop_database(mysqli $server, string $database): void
{
    recovery_assert(
        preg_match('/^aics_recovery_test_[a-z0-9_]+$/', $database) === 1,
        'Refusing to drop an unexpected database name.'
    );
    $server->query('DROP DATABASE IF EXISTS `' . $database . '`');
}

$suffix = strtolower(dechex((int) getmypid()) . '_' . bin2hex(random_bytes(3)));
$schemaDatabase = 'aics_recovery_test_schema_' . $suffix;
$authDatabase = 'aics_recovery_test_auth_' . $suffix;
$server = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, '', AICS_DB_PORT);
$server->set_charset('utf8mb4');

try {
    foreach ([$schemaDatabase, $authDatabase] as $database) {
        $server->query(
            'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    $schema = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $schemaDatabase, AICS_DB_PORT);
    $schema->set_charset('utf8mb4');
    $sql = (string) file_get_contents(__DIR__ . '/../database/AICSdb.sql');
    $sql = preg_replace('/^CREATE DATABASE[^;]+;\s*/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^USE\s+`?AICSdb`?;\s*/mi', '', $sql) ?? $sql;
    recovery_assert($schema->multi_query($sql), 'Canonical schema import failed: ' . $schema->error);
    do {
        if ($result = $schema->store_result()) {
            $result->free();
        }
    } while ($schema->more_results() && $schema->next_result());
    recovery_assert($schema->errno === 0, 'Canonical schema import failed: ' . $schema->error);

    $requiredTables = [
        'aics_accounts',
        'aics_applicant_profiles',
        'aics_requests',
        'aics_request_prescreening',
        'aics_request_release_schedule',
        'aics_admin_login_attempts',
        'aics_admin_audit_logs',
        'aics_admin_backup_runs',
        'aics_admin_maintenance_checks',
        'aics_admin_flow_steps',
        'aics_admin_exception_flows',
    ];
    foreach ($requiredTables as $table) {
        $stmt = $schema->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        recovery_assert($total === 1, 'Missing canonical table: ' . $table);
    }
    $schema->close();

    $auth = new mysqli(AICS_DB_HOST, AICS_DB_USER, AICS_DB_PASS, $authDatabase, AICS_DB_PORT);
    $auth->set_charset('utf8mb4');
    aics_ensure_auth_schema($auth);
    $emailColumn = $auth->query(
        "SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'aics_accounts' AND column_name = 'email'"
    )?->fetch_assoc();
    recovery_assert(($emailColumn['IS_NULLABLE'] ?? '') === 'YES', 'Applicant email must be nullable.');
    $auth->close();

    echo "Database schema regression test passed.\n";
} finally {
    recovery_drop_database($server, $authDatabase);
    recovery_drop_database($server, $schemaDatabase);
    $server->close();
}
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'tests\database-schema-regression.php'
```

Expected: FAIL reporting missing `aics_request_prescreening` or `aics_request_release_schedule`; after canonical-table assertions are temporarily narrowed, the auth-only call must fail because it tries to alter `aics_request_release_schedule`.

### Task 2: Decouple Authentication from PSWDO Schema

**Files:**
- Modify: `Applicants/php_files/auth-common.php:95-99`
- Test: `tests/database-schema-regression.php`

**Interfaces:**
- Consumes: an auth-only mysqli connection
- Produces: `aics_ensure_auth_schema()` that creates/migrates only roles, statuses, accounts, applicant profiles, and login attempts

- [ ] **Step 1: Remove the unrelated release-schedule migration**

Change the compatibility block to exactly:

```php
// Keep existing authentication schema revisions compatible.
$connection->query("ALTER TABLE aics_applicant_profiles ADD COLUMN IF NOT EXISTS complete_address VARCHAR(255) DEFAULT NULL AFTER contact_number");
$connection->query("ALTER TABLE aics_accounts MODIFY COLUMN email VARCHAR(180) DEFAULT NULL");
$connection->query("ALTER TABLE aics_accounts ADD COLUMN IF NOT EXISTS session_invalidate_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
$connection->query("ALTER TABLE aics_accounts ADD COLUMN IF NOT EXISTS force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER session_invalidate_at");
```

- [ ] **Step 2: Run the focused auth-only test**

Run the regression test. Expected at this point: the auth-only portion no longer throws; the full schema portion still fails until Task 3.

### Task 3: Complete the Canonical Database Schema

**Files:**
- Modify: `database/AICSdb.sql`
- Test: `tests/database-schema-regression.php`

**Interfaces:**
- Consumes: an empty MariaDB database or an existing compatible `aicsdb`
- Produces: all Applicant, PSWDO, and Admin tables needed by the current application

- [ ] **Step 1: Make applicant email compatible with the UI**

Define `aics_accounts.email` as:

```sql
`email` VARCHAR(180) DEFAULT NULL,
`session_invalidate_at` TIMESTAMP NULL DEFAULT NULL,
`force_password_change` TINYINT(1) NOT NULL DEFAULT 0,
```

and add this idempotent existing-install migration after the account/profile tables:

```sql
ALTER TABLE `aics_accounts` MODIFY COLUMN `email` VARCHAR(180) DEFAULT NULL;
ALTER TABLE `aics_accounts` ADD COLUMN IF NOT EXISTS `session_invalidate_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
ALTER TABLE `aics_accounts` ADD COLUMN IF NOT EXISTS `force_password_change` TINYINT(1) NOT NULL DEFAULT 0 AFTER `session_invalidate_at`;
```

- [ ] **Step 2: Add PSWDO workflow tables**

Copy the current `CREATE TABLE IF NOT EXISTS` definitions for `aics_request_prescreening` and `aics_request_release_schedule` from `Pswdo/php_files/pswdo-common.php` into the canonical schema after their referenced parent tables. Include this release amount column directly after `release_batch`:

```sql
`amount_given` DECIMAL(10,2) DEFAULT NULL,
```

and retain an idempotent compatibility migration:

```sql
ALTER TABLE `aics_request_release_schedule`
  ADD COLUMN IF NOT EXISTS `amount_given` DECIMAL(10,2) DEFAULT NULL AFTER `release_batch`;
```

- [ ] **Step 3: Add runtime-created Admin tables**

Copy the exact current `CREATE TABLE IF NOT EXISTS` definitions from `Applicants/php_files/auth-common.php` and `ADMIN/phpfiles/admin-common.php` for:

```text
aics_admin_login_attempts
aics_admin_audit_logs
aics_admin_backup_runs
aics_admin_maintenance_checks
aics_admin_flow_steps
aics_admin_exception_flows
```

Place them after `aics_accounts`, because their foreign keys reference accounts.

- [ ] **Step 4: Run the complete schema regression test and verify GREEN**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'tests\database-schema-regression.php'
```

Expected: `Database schema regression test passed.` and no remaining test databases.

### Task 4: Back Up and Migrate the Live Database

**Files:**
- Create outside repository: `C:\xampp\mysql\backups\aicsdb-before-recovery-<timestamp>.sql`
- Read/import: `database/AICSdb.sql`

**Interfaces:**
- Consumes: live `aicsdb`
- Produces: verified dump and non-destructively migrated live schema

- [ ] **Step 1: Record preservation counts**

Run a read-only query for `aics_accounts`, `aics_requests`, `aics_request_documents`, and `aics_applicant_profiles` counts and retain the values for comparison.

- [ ] **Step 2: Create and validate the full dump**

Run `mysqldump.exe` with `--single-transaction --routines --triggers --events --databases aicsdb --result-file=<absolute path>`. Require exit code 0 and a non-empty file before continuing.

- [ ] **Step 3: Import the canonical schema**

Run:

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' --no-defaults -uroot -e "SOURCE C:/xampp/htdocs/SIA102/AICS/database/AICSdb.sql"
```

Expected: exit code 0 with no SQL errors.

- [ ] **Step 4: Verify preservation**

Re-query the four preservation counts. Each must equal its pre-migration value, and all required tables/columns from the regression test must exist.

### Task 5: Verify Real Signup and Login

**Files:**
- No production files
- Temporary cookie jar outside the repository

**Interfaces:**
- Consumes: Apache URL `http://localhost/SIA102/AICS/Applicants`
- Produces: evidence that a phone-only applicant can sign up, access the dashboard, log out, and log back in

- [ ] **Step 1: Submit a unique phone-only registration**

POST `first_name`, `last_name`, `contact_number`, `password`, and `confirm_password` to `php_files/auth-signup.php` with redirects disabled. Expect HTTP 302 to `../dashboard.php?page=overview`.

- [ ] **Step 2: Verify the authenticated dashboard**

Reuse the response cookie and GET the redirect target. Expect HTTP 200 and applicant dashboard markup.

- [ ] **Step 3: Log out and log back in by phone**

Call `auth-logout.php`, then POST the same phone and password to `auth-login.php`. Expect HTTP 302 to the applicant dashboard and HTTP 200 after following it.

- [ ] **Step 4: Remove temporary data**

Delete only the generated test account by its exact phone number; rely on the profile foreign key cascade. Verify it no longer exists.

### Task 6: Full Verification and Documentation

**Files:**
- Modify: `Readme.md`
- Test: all `tests/*.php` smoke/regression scripts relevant to database and PSWDO behavior

**Interfaces:**
- Produces: repeatable local setup instructions and final verification evidence

- [ ] **Step 1: Update XAMPP instructions**

Document the canonical import command, backup location, required `AICSdb` name, optional applicant email behavior, and the regression-test command.

- [ ] **Step 2: Run PHP lint**

Run `php -l` for every PHP file. Expected: no syntax errors.

- [ ] **Step 3: Run automated verification**

Run:

```powershell
& 'C:\xampp\php\php.exe' 'tests\database-schema-regression.php'
& 'C:\xampp\php\php.exe' 'tests\db-connection-bootstrap-smoke.php'
& 'C:\xampp\php\php.exe' 'tests\pswdo-dashboard-db-smoke.php'
& 'C:\xampp\php\php.exe' 'tests\pswdo-dashboard-period-smoke.php'
& 'C:\xampp\php\php.exe' 'tests\pswdo-duplicate-guard-test.php'
```

Expected: every command exits 0 and reports its pass message.

- [ ] **Step 4: Verify portal entry pages**

GET the Applicant, PSWDO, and Admin login pages through Apache. Expect HTTP 200. Check the Apache error log for new AICS PHP fatal errors after the verification timestamp; expect none.

- [ ] **Step 5: Review only the task-owned diff**

Run `git diff --check` and inspect changes only in the four task-owned files. Do not stage, revert, or overwrite unrelated working-tree changes.
