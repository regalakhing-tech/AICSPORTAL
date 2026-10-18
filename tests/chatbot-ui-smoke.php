<?php
declare(strict_types=1);

function chatbot_ui_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label . ' failed.');
    }
}

$landingPath = dirname(__DIR__, 2) . '/landingpage.html';
$cssPath = __DIR__ . '/../chatbot/chatbot.css';
$jsPath = __DIR__ . '/../chatbot/chatbot.js';

chatbot_ui_assert(is_file($landingPath), 'Landing page exists');
$html = file_get_contents($landingPath);
chatbot_ui_assert(is_string($html), 'Landing page is readable');

chatbot_ui_assert(str_contains($html, 'AICS/chatbot/chatbot.css'), 'Chatbot stylesheet reference');
chatbot_ui_assert(str_contains($html, 'AICS/chatbot/chatbot.js'), 'Chatbot script reference');
chatbot_ui_assert(str_contains($html, 'id="aics-chatbot-launcher"'), 'Accessible launcher');
chatbot_ui_assert(str_contains($html, 'id="aics-chatbot-panel"'), 'Chatbot dialog');
chatbot_ui_assert(str_contains($html, 'role="dialog"'), 'Dialog role');
chatbot_ui_assert(str_contains($html, 'id="aics-chatbot-messages"'), 'Message log');
chatbot_ui_assert(substr_count($html, 'class="aics-chatbot-prompt"') === 4, 'Four quick prompts');
chatbot_ui_assert(str_contains($html, 'Do not share passwords'), 'Privacy notice');
chatbot_ui_assert(str_contains($html, 'id="aics-chatbot-input"'), 'Message textarea');
chatbot_ui_assert(str_contains($html, 'id="aics-chatbot-send"'), 'Send button');
chatbot_ui_assert(str_contains($html, 'id="aics-chatbot-clear"'), 'Clear button');

chatbot_ui_assert(is_file($cssPath), 'Chatbot CSS exists');
chatbot_ui_assert(is_file($jsPath), 'Chatbot JavaScript exists');
$css = file_get_contents($cssPath);
chatbot_ui_assert(is_string($css), 'Chatbot CSS is readable');
$js = file_get_contents($jsPath);
chatbot_ui_assert(is_string($js), 'Chatbot JavaScript is readable');
chatbot_ui_assert(str_contains($js, 'textContent'), 'Safe text rendering');
chatbot_ui_assert(str_contains($js, 'MAX_HISTORY = 8'), 'Eight-turn browser history cap');
chatbot_ui_assert(str_contains($js, 'function appendAicsInlineFormatting'), 'Safe inline emphasis renderer');
chatbot_ui_assert(str_contains($js, 'function renderAicsStructuredContent'), 'Structured answer renderer');
chatbot_ui_assert(str_contains($js, "createElement('h3')"), 'Structured heading element');
chatbot_ui_assert(str_contains($js, "createElement('p')"), 'Structured paragraph element');
chatbot_ui_assert(str_contains($js, "createElement('ul')"), 'Structured bullet list');
chatbot_ui_assert(str_contains($js, "createElement('ol')"), 'Structured ordered process list');
chatbot_ui_assert(str_contains($js, "createElement('strong')"), 'Safe strong emphasis');
chatbot_ui_assert(str_contains($js, 'boldHeadingMatch'), 'Standalone bold heading normalization');
chatbot_ui_assert(str_contains($js, 'calloutLabelMatch'), 'Bold callout label normalization');
chatbot_ui_assert(str_contains($js, 'Susunod na hakbang'), 'Filipino next-step callout rendering');
chatbot_ui_assert(str_contains($js, 'nestedListMatch'), 'Nested process detail normalization');
chatbot_ui_assert(str_contains($js, 'activeOrderedItem'), 'Continuous process-step tracking');
chatbot_ui_assert(str_contains($js, 'scrollMessageToStart'), 'Long answer starts at its heading');
chatbot_ui_assert(!str_contains($js, 'innerHTML'), 'No raw HTML rendering');
chatbot_ui_assert(!str_contains($js, 'marked.min.js'), 'No external Markdown renderer');
chatbot_ui_assert(!preg_match('/gsk_[A-Za-z0-9]+/', $html . $js), 'No Groq key in browser assets');
chatbot_ui_assert(!str_contains($js, 'Authorization: Bearer'), 'No bearer authorization in browser');
chatbot_ui_assert(str_contains($css, '.aics-chatbot-richtext'), 'Structured answer styles');
chatbot_ui_assert(str_contains($css, '.aics-chatbot-process'), 'Process flow styles');
chatbot_ui_assert(str_contains($css, "content: '↓'"), 'Process arrow');
chatbot_ui_assert(str_contains($css, '.aics-chatbot-callout'), 'Answer callout styles');
chatbot_ui_assert(str_contains($css, '@media (max-width: 640px)'), 'Mobile response breakpoint');

echo "AICS chatbot UI smoke test passed.\n";
