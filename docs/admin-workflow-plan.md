# Admin workflow plan

The Admin role maintains account access, system reliability, and audit records.
PSWDO handles applicant assessment, approval, scheduling, and assistance release.

## Main sections

| Section | What the administrator does |
| --- | --- |
| Overview | Monitor account and request totals, failed sign-ins, overdue backups, and maintenance problems. Treat request statistics as monitoring information. |
| User Management | Create authorized staff accounts, assign the correct role, update account details, and manage active or restricted accounts. |
| Security Logs | Review failed sign-ins, lockouts, unauthorized access attempts, and recorded administrative actions. Investigate unusual activity. |
| Backup & Maintenance | Create and download protected backups, review failed backup runs, and run the existing database and log checks. |

## Working routine

1. At the start of the day, review Overview and Security Logs.
2. Handle account support requests in User Management and confirm each account's role.
3. Check backup results and run maintenance checks when a problem is reported.
4. Review repeated security or backup failures and record the resolution.

## Changes to make next

1. Make Overview emphasize account access, security, backups, and system health.
2. Show recent administrative activity and clear links to the affected Admin section.
3. Define who handles requests that the AI cannot verify before moving or retiring the existing recovery controls.

## Scope of the current change

The AICS AI Screening sidebar tab and its menu badge are removed. The other four
sections remain available. Background document checks and the existing guarded
recovery page remain operational; Overview can still link to that page when a
request needs attention. The next changes above are a plan, not newly implemented
features.
