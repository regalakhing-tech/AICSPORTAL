# AICS Chatbot Coverage and Response UI Design

**Date:** 2026-07-19

## Goal

Make the public AICS Guide answer a much broader range of AICS questions while presenting AI responses as polished, readable service guidance instead of raw Markdown text. Preserve the server-side AICS-only boundary, privacy rules, local Groq configuration, and existing landing-page composition.

## Official Guidance Basis

The assistant knowledge prompt will align with the current official DSWD sources:

- [DSWD AICS frequently asked questions](https://aics.dswd.gov.ph/about-us/faqs/)
- [DSWD AICS program overview](https://aics.dswd.gov.ph/aics-program/)

The prompt may summarize these sources but must not promise eligibility, approval, exact benefit amounts, or release dates. Requirements, frequency, assistance form, and final decisions remain subject to social-worker assessment and current PSWDO/DSWD policy.

## Coverage

The deterministic server-side topic gate will recognize common English, Filipino, and Taglish questions in these areas:

- AICS meaning, purpose, beneficiaries, eligibility, and crisis situations
- Medical, hospital, medicine, laboratory, dialysis, chemotherapy, assistive-device, and implant assistance
- Burial, funeral, death-certificate, transfer-of-cadaver, and funeral-home needs
- Transportation, educational, food, material, cash-relief, psychosocial, and referral assistance
- General and category-specific documentary requirements, valid IDs, certificates, quotations, bills, prescriptions, and guarantee letters
- Where and how to apply, interview and assessment, processing stages, frequency of availment, representatives, minors, seniors, PWDs, Indigenous Peoples, and disaster-related cases
- Applicant-system signup, login, password, profile, upload, tracking, status, rejection, resubmission, duplicate checks, schedules, release, and SMS notifications
- Short contextual follow-ups after an AICS question

Unrelated requests, prompt injection, credential requests, politics, entertainment, coding, recipes, weather, and other non-AICS topics remain locally refused before Groq is called.

## Answer Behavior

The system prompt will contain a stable AICS reference covering services, typical supporting documents, application flow, frequency caveats, status guidance, and privacy limitations. It will instruct the model to:

1. Lead with a direct answer.
2. Use a short heading only when it improves scanning.
3. Use bullet or numbered lists for requirements and steps.
4. Add a short `Important` or `Next step` callout when appropriate.
5. Match English, Filipino, or Taglish.
6. Avoid decorative Markdown, nested lists, tables, raw HTML, and unnecessary repetition.
7. Refer applicants to the dashboard or PSWDO Antique for private case status and current case-specific requirements.
8. For process questions such as "How does AICS work?", "How do I apply?", or "What happens next?", return a clear ordered sequence with one action per step.

The Groq completion budget will increase modestly from 450 to 650 tokens so complete requirement lists are less likely to be cut off, while the prompt will keep responses concise.

## Safe Structured Renderer

The browser will use a small allowlisted renderer rather than `innerHTML` or an external Markdown package. It will parse only:

- `##` and `###` headings
- `**bold**` inline emphasis
- unordered `-` or `*` list items
- ordered list items such as `1.`
- paragraphs separated by blank lines
- `Important:`, `Note:`, and `Next step:` callouts

Every text fragment will be inserted with `textContent`. Unsupported syntax will remain harmless text. Links, arbitrary HTML, scripts, images, tables, code blocks, and inline event attributes will not be interpreted.

## Visual Direction

**Visual thesis:** A calm government-service answer surface with crisp navy typography, restrained AICS blue accents, and document-like structure rather than oversized chat bubbles.

**Content plan:** compact sender label, direct answer, structured detail, highlighted next action, and persistent privacy/composer controls.

**Interaction thesis:** retain the restrained message entrance, add a subtle blue heading reveal, and use lightweight hover/focus feedback on actionable controls. Reduced-motion preferences remain respected.

Assistant responses will use up to 94% of the message width, a slim blue accent edge, tighter heading rhythm, blue list markers, highlighted callout bands, and improved paragraph spacing. User messages remain compact blue bubbles aligned right. The desktop panel grows slightly where space allows; under 640px it remains a full-width bottom sheet with readable padding and horizontal quick prompts.

### Process Answer Treatment

Ordered lists in AI responses will appear as a vertical AICS process flow rather than plain browser numbering:

- Each step uses a blue numbered circle aligned with its text.
- A slim blue connector runs between steps and ends in a small downward arrow, making the sequence easy to follow.
- Step titles or bold phrases use dark navy; supporting text uses readable slate.
- The current actionable step may use a pale-blue highlight without adding another competing accent color.
- The final `Next step:` callout uses a blue-tinted band and arrow icon.
- On narrow phones, the marker column remains fixed while text wraps naturally without horizontal scrolling.

This treatment applies only to ordered process instructions. Ordinary requirement lists remain compact blue-bullet lists so long answers do not become visually noisy.

## Testing and Acceptance

- Scope tests cover the expanded assistance categories, document terms, application workflow, Filipino phrasing, system help, contextual follow-ups, and existing refusals.
- Prompt tests verify official-service categories, accuracy boundaries, formatted-answer instructions, and status/privacy guidance.
- UI tests verify allowlisted structured rendering, safe `textContent` usage, absence of `innerHTML`, structured CSS selectors, and the existing eight-turn history cap.
- Existing endpoint, local-key, database, authentication, and dashboard regressions remain green.
- Live Apache tests verify an AICS request reaches Groq and an unrelated request is still refused locally.
- Desktop and mobile screenshots verify readable hierarchy, blue numbered process arrows, complete controls, no raw Markdown markers in rendered example content, and no horizontal clipping.
