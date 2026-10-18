# AICS Landing-Page Chatbot Design

## Goal

Add a polished, responsive chatbot to the existing AICS landing page. The assistant must answer only AICS-related questions, use Groq through a PHP backend, protect the API key, and refuse unrelated requests without sending them to Groq.

## Scope

The chatbot appears on the public `landingpage.html` page only. It supports general AICS program information, this system's applicant workflow, assistance categories, documentary guidance, application submission, document upload, status tracking, and directions to official PSWDO or DSWD channels. It does not make eligibility decisions, promise assistance, process applications, retrieve private application data, or accept sensitive documents or credentials.

## Visual Design

### Visual thesis

A calm civic-service assistant that feels native to the existing blue-and-white AICS interface: crisp typography, a restrained frosted panel, clear spacing, and one strong blue action color.

### Content plan

- Preserve the current landing-page hero, phone mockups, sections, and calls to action.
- Add a fixed bottom-right `Ask AICS` launcher with the AICS mark and online indicator.
- Open a desktop panel approximately 390 pixels wide and a mobile bottom sheet that respects the viewport and safe areas.
- Show a short bilingual welcome, privacy notice, and four quick prompts: what AICS is, assistance types, common requirements, and how to apply or track.
- Render compact user and assistant messages, typing feedback, timestamps, retry messaging, and a clear-conversation control.

### Interaction thesis

- The launcher receives one restrained entrance animation and a single attention pulse, then becomes still.
- The panel opens with a short scale/fade transition; messages reveal with a small vertical movement.
- Quick prompts transition into the conversation and the send button visibly changes while a request is pending.
- All motion is disabled when `prefers-reduced-motion` is enabled.

## Architecture

### Browser UI

`landingpage.html` loads dedicated chatbot CSS and JavaScript from `AICS/chatbot/`. JavaScript owns panel state, keyboard/focus behavior, quick prompts, a capped in-memory conversation, same-origin requests, safe text rendering, error states, and local session persistence. It never receives or stores the Groq key.

### PHP endpoint

`AICS/chatbot/chat.php` accepts same-origin JSON `POST` requests only. It validates request size, message shape, message length, allowed roles, turn count, and rate limits before doing any remote work. It sends a bounded conversation plus the curated system instruction to Groq's Chat Completions API at `https://api.groq.com/openai/v1/chat/completions`.

The endpoint reads `GROQ_API_KEY` from the server environment and uses `openai/gpt-oss-20b`. It applies connection and total timeouts, returns normalized JSON, and never exposes upstream response bodies, credentials, or stack traces to the browser.

### Knowledge and scope policy

`AICS/chatbot/aics-chatbot-lib.php` contains pure validation, topic classification, prompt construction, and response normalization functions. The system instruction grounds the model in:

- the local AICS system capabilities and workflows;
- general official DSWD AICS descriptions and assistance categories;
- the rule that requirements vary by assistance type and social-worker assessment;
- a requirement to direct uncertain or case-specific questions to PSWDO/DSWD;
- a prohibition on eligibility guarantees, fabricated application statuses, and requests for sensitive data.

The server performs deterministic topic filtering before the Groq call. Obvious off-topic questions receive this fixed response: `I can only help with AICS services, requirements, applications, documents, and status guidance.` Greetings and short follow-ups are allowed when they occur in an AICS conversation. Prompt-injection requests, requests to ignore instructions, and unrelated coding, entertainment, political, or general-knowledge questions are refused locally.

## Security and Privacy

- The exposed key from chat is not written to any file and must be revoked or rotated.
- A new key is supplied through the Apache/PHP `GROQ_API_KEY` environment variable.
- Browser rendering uses `textContent`; model output is never inserted as raw HTML.
- Each user message is limited to 800 characters; conversation history is limited to the most recent 8 messages.
- Session/IP rate limiting allows 15 accepted requests per 10-minute window.
- Only same-origin requests with JSON content are accepted.
- The assistant displays a reminder not to share IDs, medical records, passwords, or complete personal information.
- Server logs must not contain message bodies or API keys.

## Error Handling

- Missing server key: return HTTP 503 with a configuration-safe message.
- Invalid method, content type, JSON, role, length, or history: return HTTP 4xx with a concise error code.
- Off-topic input: return HTTP 200 with a local refusal and `source: local`.
- Rate limit: return HTTP 429 with a retry hint.
- Groq timeout, HTTP error, or malformed response: return HTTP 502 or 504 with a friendly retry message and no upstream details.
- The browser keeps the user's unsent text and exposes a retry action when a network request fails.

## Testing

- Pure PHP tests cover AICS topic acceptance, greetings/follow-ups, off-topic refusal, prompt-injection refusal, message limits, prompt construction, and upstream response normalization.
- Endpoint tests cover method/content validation, missing-key handling, local refusal without Groq, and a mocked successful Groq response.
- JavaScript behavior is checked for safe rendering, capped history, keyboard behavior, clear conversation, and loading/error states.
- PHP lint and the existing database/auth smoke suite remain green.
- The landing page is verified through Apache at desktop and mobile viewport sizes; the assistant must not cover primary navigation or calls to action.

## Success Criteria

- The current landing-page composition remains intact.
- The assistant is usable with mouse, keyboard, and mobile touch.
- Off-topic and prompt-injection requests are refused without a Groq request.
- On-topic requests are sent only through the PHP backend.
- No API key appears in HTML, JavaScript, repository diffs, HTTP responses, or browser storage.
- All chatbot and existing regression tests pass.
