<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) aics_start_session();
if (empty($_SESSION['aics_account_id']) || ($_SESSION['aics_role_code'] ?? '') !== 'applicat') return;
if (empty($_SESSION['aics_applicant_chat_csrf'])) $_SESSION['aics_applicant_chat_csrf'] = bin2hex(random_bytes(32));
$chatBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$chatAssets = $chatBase . '/../../chatbot';
$chatLogo = $chatBase . '/../../../asset/logocp.png';
$chatEscape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $chatEscape($chatAssets) ?>/chatbot.css?v=<?= (int) @filemtime(__DIR__ . '/chatbot.css') ?>">
<div id="aics-chatbot-backdrop" class="aics-chatbot-backdrop" hidden></div>
<section id="aics-chatbot-panel" class="aics-chatbot-panel" role="dialog" aria-labelledby="aics-chatbot-title" hidden
 data-mode="applicant" data-endpoint="<?= $chatEscape($chatBase) ?>/chat-assistant.php"
 data-csrf="<?= $chatEscape($_SESSION['aics_applicant_chat_csrf']) ?>">
 <header class="aics-chatbot-header">
  <img class="aics-chatbot-brand-logo" src="<?= $chatEscape($chatLogo) ?>" alt="" aria-hidden="true">
  <div class="aics-chatbot-brand"><strong id="aics-chatbot-title">AICS Guide</strong><span>PSWDO Antique &middot; AICS questions only</span></div>
  <div class="aics-chatbot-header-actions">
   <button id="aics-chatbot-clear" class="aics-chatbot-icon-button" type="button" aria-label="New conversation" title="New conversation">↻</button>
   <button id="aics-chatbot-close" class="aics-chatbot-icon-button" type="button" aria-label="Close chat">×</button>
  </div>
 </header>
 <div id="aics-chatbot-messages" class="aics-chatbot-messages" role="log" aria-live="polite" aria-relevant="additions"></div>
 <div class="aics-chatbot-tools">
  <p class="aics-chatbot-privacy">Only your account's records · English / Filipino</p>
  <div class="aics-chatbot-prompts" aria-label="Quick questions">
   <button class="aics-chatbot-prompt" type="button" data-prompt="List all my applications and explain their current status">My applications</button>
   <button class="aics-chatbot-prompt" type="button" data-prompt="What documents need my attention?">My documents</button>
   <button class="aics-chatbot-prompt" type="button" data-prompt="When and where is my release?">Release schedule</button>
   <button class="aics-chatbot-prompt" type="button" data-prompt="When can I apply again after the cooldown?">Apply again</button>
  </div>
 </div>
 <div class="aics-chatbot-composer">
  <div class="aics-chatbot-input-wrap">
   <textarea id="aics-chatbot-input" class="aics-chatbot-input" rows="1" maxlength="800" placeholder="Ask about your application…" aria-label="Your question"></textarea>
   <button id="aics-chatbot-send" class="aics-chatbot-send" type="button" aria-label="Send message">↑</button>
  </div>
  <div class="aics-chatbot-composer-meta"><span id="aics-chatbot-status" role="status">Ready to help</span><span id="aics-chatbot-count">0/800</span></div>
 </div>
</section>
<button id="aics-chatbot-launcher" class="aics-chatbot-launcher" type="button" aria-expanded="false" aria-controls="aics-chatbot-panel">
 <img class="aics-chatbot-launcher-logo" src="<?= $chatEscape($chatLogo) ?>" alt="" aria-hidden="true"><span class="aics-chatbot-launcher-copy"><span>Ask AICS</span><span class="aics-chatbot-online">Guide online</span></span>
</button>
<script src="<?= $chatEscape($chatAssets) ?>/chatbot.js?v=<?= (int) @filemtime(__DIR__ . '/chatbot.js') ?>" defer></script>
