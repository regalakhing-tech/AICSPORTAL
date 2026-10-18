# AICS Chatbot Bilingual Intent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add persistent Tagalog/English switching and broad English, Filipino, and Taglish AICS intent recognition without weakening local off-topic refusal.

**Architecture:** Pure PHP helpers recognize explicit language commands and common AICS intent families after the existing denylist. The controller answers language commands locally so they cost no Groq request, while the confirmation remains in bounded history and the system prompt tells Groq to retain the latest preference. Tests drive language variants, natural client wording, and safety boundaries.

**Tech Stack:** PHP 8.0, vanilla JavaScript client history, XAMPP Apache, Groq Chat Completions API

## Global Constraints

- Support English, Filipino, and Taglish AICS wording plus spacing, hyphen, case, punctuation, and respectful-particle variations.
- Explicit Tagalog/Filipino and English commands must work as the first message and use no Groq request.
- The latest explicit language preference in bounded history persists until another preference replaces it.
- The prompt-injection and explicit off-topic denylist runs before generic intent matching.
- One-word contextual follow-ups remain limited to prior AICS history.
- Preserve exact local refusal text, API-key handling, rate limiting, safe UI renderer, blue response styling, eight-message history, and 800-character user limit.

---

### Task 1: Explicit Language Switching

**Files:**
- Modify: `tests/chatbot-scope-test.php`
- Modify: `tests/chatbot-endpoint-test.php`
- Modify: `chatbot/aics-chatbot-lib.php`

**Interfaces:**
- Produces: `aics_chatbot_language_preference(string $message): ?string`
- Produces: `aics_chatbot_language_confirmation(string $language): string`
- Consumes: `aics_chatbot_handle(array $request, array &$rateState, ?callable $transport = null): array`

- [ ] **Step 1: Add failing language tests**

Test `Speak in Tagalog`, `Tagalog please`, `Tagalugin mo`, `Sa Filipino ka sumagot`, `English please`, and `Mag-English ka`. Require `filipino` or `english` from the preference helper. In controller tests, send one Filipino and one English command using a transport that increments a counter; require HTTP 200, localized confirmation, `local=true`, and zero transport calls.

```php
chatbot_scope_assert(aics_chatbot_language_preference('Tagalugin mo') === 'filipino', 'Tagalog command');
chatbot_scope_assert(aics_chatbot_language_preference('English please') === 'english', 'English command');
```

- [ ] **Step 2: Run RED**

```powershell
& 'C:\xampp\php\php.exe' 'tests\chatbot-scope-test.php'
```

Expected: FAIL because `aics_chatbot_language_preference()` is undefined.

- [ ] **Step 3: Implement language helpers and controller branch**

Normalize whitespace, lowercase text, remove harmless end punctuation, and match explicit phrases. Return `null` for non-language messages. Add the controller branch after validation and before topic refusal:

```php
$language = aics_chatbot_language_preference($latest);
if ($language !== null) {
    return [
        'status' => 200,
        'payload' => [
            'message' => aics_chatbot_language_confirmation($language),
            'local' => true,
        ],
    ];
}
```

Filipino confirmation: `Sige! Sasagot ako sa Tagalog/Filipino. Magtanong tungkol sa AICS at tutulungan kita.` English confirmation: `Sure! I will answer in English. Ask me anything related to AICS.`

- [ ] **Step 4: Run GREEN**

Expected: scope and endpoint tests pass with zero transport calls for language commands.

### Task 2: English, Filipino, and Taglish Intent Families

**Files:**
- Modify: `tests/chatbot-scope-test.php`
- Modify: `chatbot/aics-chatbot-lib.php`

**Interfaces:**
- Produces: `aics_chatbot_has_common_intent(string $normalizedText): bool`
- Consumes: normalized text after denylist rejection inside `aics_chatbot_is_aics_topic()`

- [ ] **Step 1: Add failing natural-language coverage**

Add at least 15 English and 20 Filipino/Taglish messages from the specification, including:

```php
'How do I apply?', 'What documents do I need?', 'Am I qualified?',
'Where can I apply?', 'How much assistance can I receive?',
'Paano mag-apply?', 'Paano mag apply po?', 'Ano requirements?',
'Pwede ba ako?', 'Saan pupunta?', 'Magkano ang tulong?',
'Bakit na-reject?', 'Paano i-track?', 'May SMS ba?',
'Pwede representative?', 'Kailan release?', 'Ilang beses pwede?'
```

Keep explicit off-topic regression cases such as `How do I apply CSS?`, `What are the requirements for a PHP shopping cart?`, and `Tell me the election status` refused.

- [ ] **Step 2: Run RED**

Expected: FAIL on short questions such as `How do I apply?` and `Ano requirements?`.

- [ ] **Step 3: Implement intent patterns after the denylist**

Use anchored or phrase-specific regex families for application, qualification, requirements, assistance types, portal operations, status, timing, location, frequency, amount, representative, and guarantee-letter questions. Strip standalone respectful particles (`po`, `opo`, `please`) only for matching. Keep broad single words such as `how`, `why`, `status`, `apply`, and `requirements` insufficient by themselves.

Update `aics_chatbot_is_aics_topic()` in this order:

```text
empty -> denylist -> language command -> explicit AICS domain term
-> common bilingual intent -> contextual follow-up -> refusal
```

- [ ] **Step 4: Strengthen the system prompt**

Add exact guidance:

```text
Obey the latest explicit language preference visible in conversation history.
Keep using that language until the user explicitly switches.
If no preference exists, match the latest user's English, Filipino, or Taglish.
```

- [ ] **Step 5: Run GREEN**

Expected: all expanded scope cases pass and all off-topic cases remain refused.

### Task 3: Documentation and Full Verification

**Files:**
- Modify: `Readme.md`
- Verify: all chatbot and existing regression scripts

**Interfaces:**
- Produces: documented bilingual command examples and fresh live evidence

- [ ] **Step 1: Document language commands**

Add a short section listing `Tagalog please`, `Tagalugin mo`, `English please`, and natural English/Filipino AICS examples. State that explicit off-topic questions remain refused.

- [ ] **Step 2: Run complete verification**

Run PHP lint, Node syntax check, four chatbot tests, six existing database/auth/dashboard regressions, Apache asset checks, and project secret scan.

- [ ] **Step 3: Run live language sequence**

Using one cookie-backed session, send:

1. `Tagalog please`
2. `Paano gumagana ang AICS?`
3. `English please`
4. `How does AICS work?`

Require both language commands to be local, the Filipino answer to contain common Filipino function words, the English answer to contain common English function words, both AICS questions to be remote Groq responses, and the unrelated coding request to retain the exact local refusal.
