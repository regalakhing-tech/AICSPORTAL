# AICS Chatbot Bilingual Intent Design

**Date:** 2026-07-19

## Goal

Allow applicants to ask common AICS questions naturally in English, Filipino, or Taglish—even when a short question does not repeat the word `AICS`—and allow users to switch the assistant explicitly between Tagalog/Filipino and English.

## Approach

Use deterministic intent families after the existing prompt-injection and off-topic denylist. This is broader than exact keyword matching but remains narrower and safer than sending every message to Groq. Explicit language commands are recognized separately and answered locally, placing the chosen language and confirmation into conversation history so Groq continues that language on later AICS turns.

## Language Commands

The server will recognize case-insensitive, punctuation-tolerant commands including:

- `Speak in Tagalog`, `Tagalog please`, `Please use Tagalog`, `Answer in Filipino`
- `Mag-Tagalog ka`, `Tagalugin mo`, `Sa Tagalog ka sumagot`, `Filipino po`
- `Speak in English`, `English please`, `Please use English`, `Answer in English`
- `Mag-English ka`, `Ingles naman`, `Sa English ka sumagot`

A Tagalog/Filipino command returns a local Filipino confirmation. An English command returns a local English confirmation. These commands are allowed as the first message and do not call Groq.

The system prompt will instruct Groq to retain the latest explicit language preference visible in the bounded history until the user requests another language. If no preference exists, it should match the language of the latest AICS question. Simple Taglish is acceptable.

## English Intent Families

The AICS-only gate will recognize natural English questions covering:

- Meaning and process: `what is it`, `how does it work`, `how do I apply`, `application steps`, `what happens next`
- Qualification: `can I apply`, `am I qualified`, `who is eligible`, `can my representative apply`
- Requirements: `what do I need`, `required documents`, `missing requirements`, `valid ID`, `no ID`
- Assistance: `what help is available`, medical, hospital, medicine, laboratory, dialysis, chemotherapy, funeral, burial, transportation, education, food, material, cash relief, counseling, and referral
- Application system: register, sign up, sign in, login, forgot password, profile, upload, submit, dashboard, tracking, SMS, notification, schedule, release, duplicate, returned, rejected, denied, resubmit
- Timing and location: `how long`, `when will it be released`, `where do I go`, `where can I apply`, `how often`
- Amount and form: `how much assistance`, `cash or guarantee letter`, `how does a guarantee letter work`

## Filipino and Taglish Intent Families

The gate will recognize punctuation, spacing, and common hyphen variations including:

- `Ano ang AICS`, `Paano gumagana`, `Paano ang proseso`, `Ano ang susunod`
- `Paano mag-apply`, `Paano mag apply`, `Saan mag-aapply`, `Pwede ba ako`, `Qualified ba ako`, `Sino ang pwede`
- `Ano ang requirements`, `Requirements po`, `Anong dokumento`, `Kulang ang requirements`, `Wala akong valid ID`
- `Tulong medikal`, `Pambayad ospital`, `Gamot`, `Laboratoryo`, `Dialysis`, `Chemotherapy`, `Pampalibing`, `Pamasahe pauwi`, `Tulong pang-eskwela`, `Pagkain`, `Cash assistance`, `Counseling`
- `Paano mag-register`, `Hindi maka-login`, `Nakalimutan password`, `Paano mag-upload`, `Paano i-track`, `Ano ang status`, `May SMS ba`, `Kailan release`
- `Bakit na-reject`, `Hindi approved`, `Pwede mag-resubmit`, `Duplicate daw`, `Pwede representative`
- `Magkano ang tulong`, `Cash ba`, `Ano ang guarantee letter`, `Gaano katagal`, `Ilang beses pwede`, `Saan pupunta`

Common respectful particles such as `po`, `opo`, `please`, and `salamat` will not prevent a match.

## Context and Safety

The denylist continues to run before all intent matching. Coding, weather, politics, recipes, sports, entertainment, prompt injection, system-prompt requests, and other explicit non-AICS topics remain refused locally even if they contain a generic word such as `apply`, `status`, or `requirements`.

Generic one-word messages such as `how`, `why`, `where`, `paano`, or `bakit` are accepted only when prior bounded history contains an AICS user turn. Greetings and language commands remain the only context-free conversational messages.

## Testing and Acceptance

- Scope tests cover at least 15 English intent phrases, 20 Filipino/Taglish phrases, spacing and hyphen variants, respectful particles, and contextual follow-ups.
- Controller tests prove Tagalog and English language commands return local confirmations with zero transport calls.
- Prompt tests prove persistence of the latest language preference and latest-message language matching.
- Existing unrelated-topic and prompt-injection refusal cases remain green.
- Live Groq tests verify the same AICS process question returns Tagalog after a Filipino preference and English after switching back.
- All existing chatbot, database, authentication, and dashboard regressions remain green.
