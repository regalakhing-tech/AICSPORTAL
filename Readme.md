# AICS Beneficiary and Distribution Notification System

A web system for the **Provincial Social Welfare and Development Office (PSWDO) of Antique** that handles the **Assistance to Individuals in Crisis Situation (AICS)** program from end to end: applicants apply and upload documents online, PSWDO staff screen and prioritize requests with AI-assisted document checks, and everyone is kept informed by SMS and email.

It runs locally on **XAMPP** (Apache + PHP + MySQL), with an optional **Python worker** for OCR and AI document review.

---

## Contents

- [The three portals](#the-three-portals)
- [Tech stack](#tech-stack)
- [Folder layout](#folder-layout)
- [Quick start (XAMPP)](#quick-start-xampp)
- [Configuration](#configuration)
- [AI document review (optional)](#ai-document-review-optional)
- [Chat assistants](#chat-assistants)
- [Running tests](#running-tests)
- [Deploying to Railway](#deploying-to-railway)
- [Troubleshooting](#troubleshooting)
- [Scope and limitations](#scope-and-limitations)
- [Further reading](#further-reading)

---

## The three portals

| Portal | Login page | Who uses it | What they can do |
|---|---|---|---|
| **Applicant** | `Applicants/login.php` | Members of the public | Sign up with phone OTP (email optional), complete a profile, file an AICS request, upload requirements, track status (including AICS AI re-upload requests and the expected processing time), read notifications, cancel or edit pending requests, chat with the AICS Guide |
| **PSWDO** | `Pswdo/login.php` | PSWDO personnel | Only requests that passed the AICS AI check. Pre-screening queue, request management, AI document verdicts, duplicate/repeat-claim checks, smart prioritization (urgent medical cases, PWD / senior citizen), status decisions, release scheduling, printable reports and GIS forms, map analytics, SMS notices |
| **Admin** | `ADMIN/login.php` | System administrators | Create and manage staff accounts, account status (active / disabled / locked / banned), AICS AI Screening (requests the AI could not confirm or check, AI service alerts, 7-day log), security and audit logs, overview dashboard, database backups and maintenance |

Assistance types supported: **Medical**, **Burial**, **Victim of Disaster**, **Special Cases**, and **Persons Affected by High Inflation Rate**. The required documents for each type are seeded by the database schema.

Request lifecycle:

```
Pending Review → Incomplete Requirements / For Interview → Under Review → Approved → Released
                                                                       ↘ Not Approved
                                     (Cancelled is possible until a terminal state)
```

---

## Tech stack

| Layer | Technology |
|---|---|
| Web server and backend | Apache + **PHP 8.2** (XAMPP), `mysqli` |
| Database | **MySQL / MariaDB** (XAMPP), database `AICSdb`, `utf8mb4` |
| Frontend | HTML5, CSS, vanilla JavaScript, Font Awesome, MapLibre GL (maps) |
| PDFs and email | FPDF (reports), PHPMailer (SMTP) |
| SMS | [PhilSMS](https://philsms.com) (OTP and applicant notices) |
| AI worker | **Python 3.10+** (tested on 3.13), Tesseract OCR, Poppler, OpenCV, PyMuPDF |
| AI providers | Google Gemini (document vision, shown to users as **"AICS AI"**), Groq `openai/gpt-oss-20b` (advisory text review and chat) |
| Optional tamper model | Local ADCD-Net / DocTamper service on `127.0.0.1:5001` |

Passwords are hashed with PHP's `password_hash()` (bcrypt by default).

---

## Folder layout

This repository is expected to live at **`C:\xampp\htdocs\AICS\AICS`**. The public landing page sits one level up and is **not** part of this repository:

```
C:\xampp\htdocs\AICS\
├── landingpage.php            Public landing page (links into AICS/…)
├── assets\vendor\maplibre-gl\  Map library used by the landing page
└── AICS\                       ← this repository
    ├── Applicants\             Applicant portal (login.php, dashboard.php, php_files\, uploads\)
    ├── Pswdo\                  PSWDO staff portal
    ├── ADMIN\                  Admin portal (+ backups\)
    ├── chatbot\                Landing-page guide and in-portal applicant assistant
    ├── includes\               Shared PHP: AI reviews, priority rules, requirements, mailer, PHPMailer
    ├── config\                 database.php (DB + PhilSMS), mail.php (SMTP)
    ├── database\               AICSdb.sql — canonical schema and seed data
    ├── ai_processor\           Python OCR / AI document-review worker
    ├── tools\                  Setup, backfill, probe, and validation scripts
    ├── tests\                  PHP, JS, and browser regression tests
    ├── storage\emails\         Simulated outgoing emails (when MAIL_DRIVER=simulate)
    ├── deploy\                 Railway/Docker config, plus a copy of the landing page in deploy\site\
    └── docs\superpowers\       Design specs and implementation plans
```

Private secrets and AI artifacts go **outside the web root**, under `C:\xampp\aics_private\` (and `C:\xampp\aics-private\` for the landing-page chat key).

---

## Quick start (XAMPP)

### 1. Prerequisites

- [XAMPP](https://www.apachefriends.org/) with PHP 8.2+ (Apache and MySQL)
- Git (optional, for cloning)

### 2. Get the code

```powershell
cd C:\xampp\htdocs
mkdir AICS
cd AICS
git clone https://github.com/regalakhing-tech/AICSPORTAL.git AICS
```

Copy `landingpage.php` and `assets\` into `C:\xampp\htdocs\AICS\` if you want the public landing page. The portals work without it.

### 3. Start Apache and MySQL

Open the XAMPP Control Panel and start both services.

### 4. Create the database

Import `database\AICSdb.sql` in phpMyAdmin, or run:

```powershell
& "C:\xampp\mysql\bin\mysql.exe" --no-defaults -uroot -e "SOURCE C:/xampp/htdocs/AICS/AICS/database/AICSdb.sql"
```

The script creates `AICSdb` and is **safe to re-run**. It uses `CREATE TABLE IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS`, so it adds missing schema without deleting records. Still, dump a populated database before repairing it.

### 5. Open the system

| Page | URL |
|---|---|
| Landing page | http://localhost/AICS/landingpage.php |
| Applicant portal | http://localhost/AICS/AICS/Applicants/login.php |
| PSWDO portal | http://localhost/AICS/AICS/Pswdo/login.php |
| Admin portal | http://localhost/AICS/AICS/ADMIN/login.php |

### 6. First login

The first admin sign-in attempt seeds a default administrator if none exists:

| Email | Password |
|---|---|
| `admin@aics.local` | `Admin123!` |

> **Change this password immediately** (Admin → Change Password). Then create PSWDO staff accounts from **Admin → User Management**.

Applicants register themselves from the applicant login page. Sign-up sends an OTP by SMS, so PhilSMS must be configured (see below). Applicants without email sign in with their `09XXXXXXXXX` mobile number.

---

## Configuration

All settings are read from **environment variables** with local fallbacks. On Windows, set them as system variables, then **fully close and reopen the XAMPP Control Panel** and restart Apache so the variables are picked up:

```powershell
# Administrator PowerShell
[Environment]::SetEnvironmentVariable('PHILSMS_API_TOKEN', '<token>', 'Machine')
```

For local XAMPP you can skip environment variables for the PhilSMS and email credentials: copy `config/secrets.local.example.php` to `config/secrets.local.php` and fill it in. That file is git-ignored. Environment variables still take precedence when both are set.

### Database: `config/database.php`

| Variable | Default |
|---|---|
| `AICS_DB_HOST` | `localhost` (also tries `127.0.0.1`) |
| `AICS_DB_PORT` | `3306` |
| `AICS_DB_USER` | `root` |
| `AICS_DB_PASS` | *(empty, the XAMPP default)* |
| `AICS_DB_NAME` | `AICSdb` |

### SMS: PhilSMS

| Variable | Purpose |
|---|---|
| `PHILSMS_API_TOKEN` | API token for OTP and applicant notices |
| `PHILSMS_SENDER_ID` | Sender name (default `PhilSMS`) |
| `PHILSMS_BASE_URL` | Optional API base URL override |
| `AICS_MSWDO_NOTIFICATION_MODE` | `simulate` (default). MSWDO notices are simulation-only in this prototype |

### Email: `config/mail.php`

| Variable | Default |
|---|---|
| `MAIL_DRIVER` | `smtp` (or `simulate`, which writes emails to `storage/emails/`) |
| `SMTP_HOST` / `SMTP_PORT` / `SMTP_SECURE` | `smtp.gmail.com` / `587` / `tls` |
| `SMTP_USER` / `SMTP_PASS` | Gmail address and a 16-character [App Password](https://myaccount.google.com/apppasswords) |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` / `MAIL_REPLY_TO` | Sender identity |

> **Never commit real credentials.** Keep tokens and passwords in environment variables, `config/secrets.local.php`, or `C:\xampp\aics_private\`. If a key has ever been pasted into source code, chat, or a screenshot, revoke it and create a new one. Before committing, run `powershell -ExecutionPolicy Bypass -File tests\external-ai-secret-regression.ps1`.

---

## AI document review (optional)

When an applicant uploads a document it is saved and queued immediately. Apache then launches a background Python worker, so the applicant never waits for OCR. For each document, PSWDO staff see a verdict card with five checks (**Complete, Consistent, Readable, No visible alterations, No signs of tampering**), plus the detected document type and a document-vs-application comparison.

**AI results are advisory only.** The worker never approves, rejects, or declares fraud. Staff always make the final decision, and if any AI component is unavailable the review falls back to local OCR.

### Setup

1. **Install system tools**
   - [Tesseract OCR](https://github.com/UB-Mannheim/tesseract/wiki): add it to `PATH` or set `AICS_TESSERACT_PATH`
   - [Poppler for Windows](https://github.com/oschwartz10612/poppler-windows/releases): set `AICS_POPPLER_PATH` to its `bin` folder (needed for PDFs)

2. **Install Python packages** from the repository folder:

   ```powershell
   python -m pip install -r ai_processor\requirements.txt
   ```

3. **Set the worker environment variables** for Apache (see `ai_processor/.env.example` for the full list):

   | Variable | Example |
   |---|---|
   | `AICS_PYTHON_EXECUTABLE` | Full path to `python.exe`, e.g. `C:\Python313\python.exe`. The launcher requires a real file path, so `py` will not work here |
   | `AICS_PROJECT_ROOT` | `C:\xampp\htdocs\AICS\AICS` |
   | `AICS_UPLOAD_ROOT` | `C:\xampp\htdocs\AICS\AICS\Applicants\uploads` |
   | `AICS_AI_REVIEW_ARTIFACT_ROOT` | `C:\xampp\aics_private\ai_review_artifacts` |

4. **Add AI provider keys** to `C:\xampp\aics_private\aics-ai.env`. Start from `ai_processor/external-ai.env.example` and never put this file in the repository:

   ```ini
   AICS_GEMINI_API_KEY=<key from https://aistudio.google.com/apikey>
   AICS_EXTERNAL_AI_API_KEY=<Groq key>
   ```

   The interactive helper `powershell -ExecutionPolicy Bypass -File tools\setup-external-ai.ps1` writes the Groq settings without echoing the key. Note that it overwrites `aics-ai.env`, so run it **before** adding the Gemini key.

5. **Restart Apache**, then verify:

   ```powershell
   python -m ai_processor.external_ai.probe --config-json
   python -m ai_processor.processor --once --dry-run
   ```

### Running the worker manually

From the repository folder:

```powershell
python -m ai_processor.processor --once                # process one queued request
python -m ai_processor.processor --batch 10            # process up to 10
python -m ai_processor.processor --document-id 123     # one specific document
```

Add `--dry-run` to print IDs and statuses without writing results. Per-launch logs are written to `ai_processor/logs/`.

### AICS AI submission gate

PSWDO only receives requests whose documents passed the AICS AI check:

| AI result | Where the request goes |
|---|---|
| Every document valid | **PSWDO**. The applicant is told the expected processing time: about 1 day (urgent), 3 days (senior citizen / PWD medical), 7 days (others) |
| Blurry, cut off, wrong or edited document | **Back to the applicant** with the document and reason. Re-uploading starts a new check automatically. A photo the AI found only hard to read also goes back to the applicant |
| AI not sure whether the details match or were changed | **Admin** (AICS AI Screening page): release to PSWDO or ask for a re-upload |
| AI failed, stalled, or only local OCR ran | Retried automatically with waits of 1, 5, 15 and 30 minutes, then **Admin** with a warning. Never PSWDO |

`tools/ai-gate-sync.php` starts queued checks (at most `AICS_AI_MAX_WORKERS` at once), retries failures and applies decisions. On Railway the container runs it every minute and the worker runs it after each check. On XAMPP, applicant, PSWDO and admin page loads run it; for a busy local install you can also run it on a schedule:

```powershell
C:\xampp\php\php.exe tools\ai-gate-sync.php            # one pass
C:\xampp\php\php.exe tools\ai-gate-sync.php --health   # AI health summary
```

| Variable | Default | Meaning |
|---|---|---|
| `AICS_AI_MAX_WORKERS` | `2` | AI checks running at the same time |
| `AICS_AI_WORKER_HEARTBEAT_MINUTES` | `10` | A worker silent this long no longer holds a slot |
| `AICS_AI_GATE_SCHEDULER` | `1` in the Docker image | Set when a scheduler runs `ai-gate-sync.php`; PSWDO page loads then skip the gate pass |
| `AICS_AI_GATE_MAX_RETRIES` | `4` | Automatic retries per document before the admin decides |
| `AICS_AI_GATE_ALERT_MINUTES` | `30` | Waiting time before the admin page warns |
| `AICS_ETA_URGENT_DAYS` / `AICS_ETA_HIGH_DAYS` / `AICS_ETA_STANDARD_DAYS` | `1` / `3` / `7` | Expected processing time shown to applicants |
| `AICS_PHP_EXECUTABLE` | (unset) | PHP CLI for the worker to run the gate after each check, e.g. `C:\xampp\php\php.exe` |

The optional ADCD-Net tamper-detection service, calibration, and the full list of settings are covered in [ai_processor/README.md](ai_processor/README.md), [ai_processor/DEPLOYMENT.md](ai_processor/DEPLOYMENT.md), and [ai_processor/tamper_detection/ADCD-NET-OPERATIONS.md](ai_processor/tamper_detection/ADCD-NET-OPERATIONS.md).

---

## Chat assistants

There are two separate assistants, both powered by Groq on the server. The API key is never sent to the browser.

| Assistant | Where | Data access | Key location |
|---|---|---|---|
| **Ask AICS** (landing page) | `chatbot/chat.php` | General AICS questions only, no applicant records. Off-topic and prompt-injection attempts are refused locally | `GROQ_API_KEY` env var, or `C:\xampp\aics-private\groq.key` (can be saved with `chatbot/setup-local.php` from localhost) |
| **AICS Guide** (applicant portal) | `chatbot/applicant-*.php` | The signed-in applicant's own applications, documents, schedule, and notifications (CSRF- and session-checked) | `C:\xampp\aics_private\aics-ai.env` |

Both understand English, Filipino, and Taglish (for example "Tagalog please" or "Mag-English ka"). Check connectivity without sending applicant data:

```powershell
C:\xampp\php\php.exe tools\probe-chatbot.php
```

See [chatbot/README.md](chatbot/README.md) for details.

---

## Running tests

Run these from `C:\xampp\htdocs\AICS` with Apache and MySQL running.

**Core smoke tests**

```powershell
& "C:\xampp\php\php.exe" "AICS\tests\db-connection-bootstrap-smoke.php"    # DB connectivity and core tables
& "C:\xampp\php\php.exe" "AICS\tests\database-schema-regression.php"      # imports schema into throwaway DBs and verifies it
& "C:\xampp\php\php.exe" "AICS\tests\auth-http-smoke.php"                 # phone-only signup and login through Apache
& "C:\xampp\php\php.exe" "AICS\tests\pswdo-dashboard-db-smoke.php"        # dashboard totals match the database
& "C:\xampp\php\php.exe" "AICS\tests\pswdo-duplicate-guard-test.php"      # duplicate-claim guard
```

**Chatbot tests**

```powershell
& "C:\xampp\php\php.exe" "AICS\tests\chatbot-scope-test.php"
& "C:\xampp\php\php.exe" "AICS\tests\chatbot-endpoint-test.php"
& "C:\xampp\php\php.exe" "AICS\tests\chatbot-ui-smoke.php"
```

**Python AI worker tests.** Run from `C:\xampp\htdocs\AICS\AICS`:

```powershell
python -m unittest discover -s ai_processor\tests -p "test_*.py" -q
```

The `tests\` folder contains many more regression tests named by area (`applicant-*`, `pswdo-*`, `ai-*`, `chatbot-*`). Files ending in `.html` are browser tests and should be opened through Apache.

> HTTP fixture tests (`http-regression-*`) need `AICS_HTTP_TEST_MODE=1` and a **separate** database named `aics_http_regression`, configured through the `AICS_TEST_DB_*` variables. They refuse to run against `AICSdb`. See [ai_processor/DEPLOYMENT.md](ai_processor/DEPLOYMENT.md).

---

## Deploying to Railway

Railway builds the `Dockerfile` automatically. The container uses the same folder layout as XAMPP: the landing page at `/AICS/` (copied from `deploy/site/`) and the app at `/AICS/AICS/`. If you change `landingpage.php`, `asset\` or `assets\` in the outer folder, copy them into `deploy\site\` too.

1. **New Project → Deploy from GitHub repo →** this repository.
2. **Add MariaDB** (not MySQL: the schema uses MariaDB-only syntax). Turn on its public networking, then import the schema from your PC:

   ```powershell
   & "C:\xampp\mysql\bin\mysql.exe" --no-defaults -h <public host> -P <public port> -u root -p<password> -e "SOURCE C:/xampp/htdocs/AICS/AICS/database/AICSdb.sql"
   ```

3. **Add a volume** to the app service, mounted at `/var/www/html/AICS/AICS/Applicants/uploads`. Uploads, applicant ID photos, AI results, backups and simulated emails are stored there.
4. **Set variables** on the app service:

   | Variable | Value |
   |---|---|
   | `AICS_DB_HOST` | MariaDB's private host (ends in `.railway.internal`) |
   | `AICS_DB_PORT` / `AICS_DB_USER` / `AICS_DB_PASS` | `3306` / `root` / MariaDB's password |
   | `AICS_DB_NAME` | `AICSdb` |
   | `PHILSMS_API_TOKEN` | PhilSMS token |
   | `MAIL_DRIVER` | `simulate` on the Hobby plan (Railway blocks SMTP there); `smtp` plus `SMTP_USER`, `SMTP_PASS`, `MAIL_FROM_ADDRESS` on Pro |
   | `AICS_GEMINI_API_KEY` | Gemini key (AICS AI document scanner) |
   | `AICS_EXTERNAL_AI_ENABLED` / `AICS_EXTERNAL_AI_API_KEY` | `1` / Groq key (advisory review, applicant guide) |
   | `GROQ_API_KEY` | Groq key (landing-page assistant) |

5. **Settings → Networking → Generate Domain.** The domain opens the landing page. Sign in to `/AICS/AICS/ADMIN/login.php` with the default admin and change its password immediately.
6. **Check AICS AI.** Every start prints a line such as `AICS AI health: operational | key configured | unavailable: none` in the deploy logs (`railway logs`). If it says `key MISSING` or lists unavailable parts, new requests wait for the admin instead of reaching PSWDO.

The optional ADCD-Net tamper model is not deployed (its weights are too large for GitHub); leave `AICS_ADCD_ENABLED` unset.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Database connection failed" | Check MySQL is running in XAMPP and `AICSdb` exists. Re-import `database/AICSdb.sql` |
| New environment variable isn't picked up | Close the XAMPP Control Panel completely (not just Apache), reopen it, and start Apache again |
| OTP / SMS not sent | Check `PHILSMS_API_TOKEN` and your PhilSMS balance |
| Emails not delivered | Use a Gmail **App Password**, not your normal password, or set `MAIL_DRIVER=simulate` to write emails to `storage/emails/` |
| AI card says "Local OCR only" | Gemini key missing, rate-limited, or timed out. The review still completes. Check `aics-ai.env` and run the probe |
| AI review stuck "processing" | Check `AICS_PYTHON_EXECUTABLE` is a full path to `python.exe` and look in `ai_processor/logs/`. Stale jobs time out after `AICS_AI_STALE_MINUTES` (default 30) and are retried automatically |
| New requests don't appear in PSWDO | They are still being checked, were sent back to the applicant, or wait for the admin. Open **Admin → AICS AI Screening**; run `php tools/ai-gate-sync.php --health` |
| PDF uploads fail OCR | Install Poppler and set `AICS_POPPLER_PATH` |

---

## Scope and limitations

This system covers **AICS transactions only**: beneficiary profiling, request processing, screening, validation, prioritization, notification, reporting, and maintenance. It does **not** include:

- Other social-welfare programs
- Actual disbursement of funds
- Field investigation outside the encoded workflow
- Integration with external government databases

It was built as a capstone project and is intended for local or office deployment on XAMPP.

---

## Further reading

- [ai_processor/README.md](ai_processor/README.md): AI worker, Gemini scanner, Groq advisory review
- [ai_processor/DEPLOYMENT.md](ai_processor/DEPLOYMENT.md): worker deployment, ADCD-Net, calibration, isolated HTTP tests
- [chatbot/README.md](chatbot/README.md): applicant AICS Guide
- [docs/superpowers/](docs/superpowers/): design specs and implementation plans for major features
- [Agent.md](Agent.md): notes for AI coding assistants working on this repository
