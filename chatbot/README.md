# AICS Guide

Sign in as an applicant and select **Ask AICS** at the bottom right of any applicant page. Quick questions cover your application summary, document reviews, release schedule, and one-year cooldown. Ask for recent notifications or enter an older application reference to view another application belonging to your account. Say “latest application” to return to the latest record.

The landing-page guide answers general AICS questions. Its endpoint never retrieves applicant records.

Applicant records are selected using the authenticated session, checked against database ownership, and protected with a CSRF token. Chat history is separated using the current session's chat token. File contents, OCR, contact information, identity details, and amounts are excluded from the retrieved provider context. Staff notes are displayed locally only.

Groq uses the existing server-only key in `C:\xampp\aics_private\aics-ai.env` (or `AICS_EXTERNAL_AI_ENV_FILE`). Every accepted applicant question retrieves the account's full application index and the selected application's documents, classification, release schedule, cooldown, and notification metadata. Canonical document requirements are also retrieved from the portal's requirements code. Groq generates a natural answer from these sources, including misspelled and bilingual questions. The server validates the returned source IDs and rejects invented application references. Source labels appear below the answer. These checks do not independently prove every generated sentence; absent facts must be described as unknown. Provider failures fall back to a local report. No AI worker command is needed for chat.

From the AICS project directory, check the connection without sending applicant data:

```powershell
C:\xampp\php\php.exe tools\probe-chatbot.php
```

The guide cannot approve requests or estimate an unrecorded release date. Document scanning remains a separate worker feature.
