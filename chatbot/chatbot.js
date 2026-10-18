(() => {
  'use strict';

  const MAX_HISTORY = 8;
  const MAX_LENGTH = 800;

  const launcher = document.getElementById('aics-chatbot-launcher');
  const panel = document.getElementById('aics-chatbot-panel');
  const backdrop = document.getElementById('aics-chatbot-backdrop');
  const closeButton = document.getElementById('aics-chatbot-close');
  const clearButton = document.getElementById('aics-chatbot-clear');
  const messagesElement = document.getElementById('aics-chatbot-messages');
  const input = document.getElementById('aics-chatbot-input');
  const sendButton = document.getElementById('aics-chatbot-send');
  const characterCount = document.getElementById('aics-chatbot-count');
  const statusElement = document.getElementById('aics-chatbot-status');
  const promptButtons = [...document.querySelectorAll('.aics-chatbot-prompt')];

  if (!launcher || !panel || !messagesElement || !input || !sendButton) return;
  const isApplicant = panel.dataset.mode === 'applicant';
  const endpoint = panel.dataset.endpoint || 'AICS/chatbot/chat.php';
  const STORAGE_KEY = isApplicant ? `aics-chat-${panel.dataset.csrf}` : 'aics-chatbot-conversation-v1';

  let history = [];
  let activeRequest = null;
  let lastFocusedElement = null;

  function syncChatbotViewport() {
    const viewport = window.visualViewport;
    if (viewport && window.matchMedia('(max-width: 640px)').matches && !panel.hidden) {
      panel.style.setProperty('--aics-chatbot-viewport-height', `${Math.round(viewport.height)}px`);
      return;
    }
    panel.style.removeProperty('--aics-chatbot-viewport-height');
  }

  function trapChatbotFocus(event) {
    if (event.key !== 'Tab' || panel.hidden || !window.matchMedia('(max-width: 640px)').matches) return;
    const focusable = Array.from(panel.querySelectorAll(
      'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter((item) => item instanceof HTMLElement && !item.hidden && item.getClientRects().length > 0);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function safeStoredHistory() {
    try {
      const parsed = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]');
      if (!Array.isArray(parsed)) return [];
      return parsed.filter((message) =>
        message &&
        (message.role === 'user' || message.role === 'assistant') &&
        typeof message.content === 'string' &&
        message.content.trim()
      ).slice(-MAX_HISTORY);
    } catch (_) {
      return [];
    }
  }

  function saveHistory() {
    history = history.slice(-MAX_HISTORY);
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history));
    } catch (_) {
      // The assistant remains usable when storage is unavailable.
    }
  }

  function scrollToLatest() {
    requestAnimationFrame(() => {
      messagesElement.scrollTop = messagesElement.scrollHeight;
    });
  }

  function scrollMessageToStart(row) {
    requestAnimationFrame(() => {
      const target = row.offsetTop - messagesElement.offsetTop - 8;
      messagesElement.scrollTop = Math.max(0, target);
    });
  }

  function appendAicsInlineFormatting(parent, text) {
    // Render presentation markup as UI, never as literal reply characters.
    text = text.replace(/\\([*_`#])/g, '$1')
      .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
      .replace(/`+/g, '')
      .replace(/(^|\s)_([^_]+)_(?=\s|[.,!?]|$)/g, '$1$2');
    const clean = value => value.replace(/\*/g, '');
    const boldPattern = /\*\*([^*\n]+)\*\*/g;
    let cursor = 0;
    let match;

    while ((match = boldPattern.exec(text)) !== null) {
      if (match.index > cursor) {
        parent.appendChild(document.createTextNode(clean(text.slice(cursor, match.index))));
      }
      const strong = document.createElement('strong');
      strong.textContent = match[1];
      parent.appendChild(strong);
      cursor = match.index + match[0].length;
    }

    if (cursor < text.length) {
      parent.appendChild(document.createTextNode(clean(text.slice(cursor))));
    }
  }

  function renderAicsStructuredContent(container, content) {
    container.classList.add('aics-chatbot-richtext');
    const lines = content.replace(/\r\n?/g, '\n').split('\n');
    let paragraphLines = [];
    let activeList = null;
    let activeListType = '';
    let activeOrderedItem = null;
    let activeNestedList = null;

    const flushParagraph = () => {
      if (paragraphLines.length === 0) return;
      const paragraph = document.createElement('p');
      appendAicsInlineFormatting(paragraph, paragraphLines.join(' ').trim());
      container.appendChild(paragraph);
      paragraphLines = [];
    };

    const flushList = () => {
      activeList = null;
      activeListType = '';
      activeOrderedItem = null;
      activeNestedList = null;
    };

    const ensureList = (type) => {
      if (activeList && activeListType === type) return activeList;
      flushParagraph();
      flushList();
      activeList = type === 'ol' ? document.createElement('ol') : document.createElement('ul');
      activeListType = type;
      if (type === 'ol') activeList.className = 'aics-chatbot-process';
      container.appendChild(activeList);
      return activeList;
    };

    lines.forEach((rawLine) => {
      const line = rawLine.trim();
      if (line === '') {
        flushParagraph();
        if (activeListType !== 'ol') flushList();
        return;
      }

      if (/^(?:```.*|[-*_]{3,})$/.test(line)) return;
      const sourceMatch = line.match(/^Based on:\s*(.+)$/i);
      if (sourceMatch) {
        flushParagraph();
        flushList();
        const source = document.createElement('div');
        source.className = 'aics-chatbot-sources';
        const label = document.createElement('span');
        label.textContent = 'Answer sources';
        source.appendChild(label);
        sourceMatch[1].split(' · ').forEach(title => {
          const chip = document.createElement('span');
          chip.className = 'aics-chatbot-source-chip';
          chip.textContent = title.replace(/[*`]/g, '').trim();
          source.appendChild(chip);
        });
        container.appendChild(source);
        return;
      }

      const headingMatch = line.match(/^(#{1,6})\s+(.+)$/);
      if (headingMatch) {
        flushParagraph();
        flushList();
        const heading = headingMatch[1] === '##'
          ? document.createElement('h3')
          : document.createElement('h4');
        appendAicsInlineFormatting(heading, headingMatch[2]);
        container.appendChild(heading);
        return;
      }

      const boldHeadingMatch = line.match(/^\*\*([^*]+)\*\*$/);
      if (boldHeadingMatch) {
        flushParagraph();
        flushList();
        const heading = document.createElement('h3');
        heading.textContent = boldHeadingMatch[1];
        container.appendChild(heading);
        return;
      }

      const calloutLabelMatch = line.match(/^(?:\*\*)?(Important|Note|Next step|Mahalaga|Paalala|Susunod na hakbang):(?:\*\*)?\s*(.+)$/i);
      if (calloutLabelMatch) {
        flushParagraph();
        flushList();
        const callout = document.createElement('div');
        callout.className = `aics-chatbot-callout aics-chatbot-callout--${calloutLabelMatch[1].toLowerCase().replace(' ', '-')}`;
        const label = document.createElement('strong');
        label.textContent = `${calloutLabelMatch[1]}:`;
        const detail = document.createElement('p');
        appendAicsInlineFormatting(detail, calloutLabelMatch[2]);
        callout.append(label, detail);
        container.appendChild(callout);
        return;
      }

      const orderedMatch = line.match(/^\d+[.)]\s+(.+)$/);
      if (orderedMatch) {
        const list = ensureList('ol');
        const item = document.createElement('li');
        appendAicsInlineFormatting(item, orderedMatch[1]);
        list.appendChild(item);
        activeOrderedItem = item;
        activeNestedList = null;
        return;
      }

      const nestedListMatch = rawLine.match(/^\s{2,}[-*]\s+(.+)$/);
      if (nestedListMatch && activeListType === 'ol' && activeOrderedItem) {
        if (!activeNestedList) {
          activeNestedList = document.createElement('ul');
          activeNestedList.className = 'aics-chatbot-process-details';
          activeOrderedItem.appendChild(activeNestedList);
        }
        const item = document.createElement('li');
        appendAicsInlineFormatting(item, nestedListMatch[1]);
        activeNestedList.appendChild(item);
        return;
      }

      const unorderedMatch = line.match(/^[-*]\s+(.+)$/);
      if (unorderedMatch) {
        const list = ensureList('ul');
        const item = document.createElement('li');
        appendAicsInlineFormatting(item, unorderedMatch[1]);
        list.appendChild(item);
        return;
      }

      flushList();
      paragraphLines.push(line);
    });

    flushParagraph();
  }

  function renderMessage(role, content, options = {}) {
    const row = document.createElement('div');
    row.className = `aics-chatbot-message aics-chatbot-message--${role}`;
    if (options.error) row.classList.add('aics-chatbot-message--error');

    const bubble = document.createElement('div');
    bubble.className = 'aics-chatbot-bubble';
    if (role === 'assistant' && !options.error) {
      renderAicsStructuredContent(bubble, content);
    } else {
      bubble.textContent = content;
    }
    row.appendChild(bubble);
    messagesElement.appendChild(row);
    if (options.alignStart) scrollMessageToStart(row);
    else scrollToLatest();
    return row;
  }

  function renderThinking() {
    const row = document.createElement('div');
    row.className = 'aics-chatbot-message aics-chatbot-message--assistant';
    row.setAttribute('aria-label', 'AICS Guide is typing');
    const bubble = document.createElement('div');
    bubble.className = 'aics-chatbot-bubble aics-chatbot-thinking';
    for (let index = 0; index < 3; index += 1) bubble.appendChild(document.createElement('i'));
    const label = document.createElement('span');
    label.textContent = isApplicant ? 'Looking up your applications...' : 'Preparing your answer';
    bubble.appendChild(label);
    row.appendChild(bubble);
    messagesElement.appendChild(row);
    scrollToLatest();
    return row;
  }

  function renderWelcome() {
    if (isApplicant) {
      renderMessage('assistant', 'Hi! Let\'s check your application.\n\nI can explain your document reviews, release schedule, and next steps. Choose a question below or ask in English or Filipino.');
      return;
    }
    renderMessage(
      'assistant',
      'Hello! I’m your AICS Guide for PSWDO Antique. I can help with services, requirements, documents, applications, and status guidance.\n\nKumusta! Maaari kang magtanong sa English o Filipino.'
    );
  }

  function renderConversation() {
    messagesElement.textContent = '';
    renderWelcome();
    history.forEach((message) => renderMessage(message.role, message.content));
  }

  function setStatus(message) {
    if (statusElement) statusElement.textContent = message;
  }

  function updateComposer() {
    const length = [...input.value].length;
    if (characterCount) characterCount.textContent = `${length}/${MAX_LENGTH}`;
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 92)}px`;
    sendButton.disabled = activeRequest !== null || input.value.trim() === '' || length > MAX_LENGTH;
  }

  function openChat() {
    lastFocusedElement = document.activeElement;
    panel.hidden = false;
    if (backdrop && window.matchMedia('(max-width: 640px)').matches) backdrop.hidden = false;
    launcher.setAttribute('aria-expanded', 'true');
    document.body.classList.add('aics-chatbot-is-open');
    syncChatbotViewport();
    setTimeout(() => input.focus(), 50);
    scrollToLatest();
  }

  function closeChat() {
    panel.hidden = true;
    if (backdrop) backdrop.hidden = true;
    launcher.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('aics-chatbot-is-open');
    panel.style.removeProperty('--aics-chatbot-viewport-height');
    if (lastFocusedElement instanceof HTMLElement) lastFocusedElement.focus();
    else launcher.focus();
  }

  function clearConversation() {
    if (activeRequest) activeRequest.abort();
    activeRequest = null;
    history = [];
    try { sessionStorage.removeItem(STORAGE_KEY); } catch (_) {}
    input.value = '';
    setStatus('Conversation cleared');
    renderConversation();
    updateComposer();
    input.focus();
  }

  async function sendMessage() {
    const content = input.value.trim();
    if (!content || [...content].length > MAX_LENGTH || activeRequest) return;

    history.push({ role: 'user', content });
    history = history.slice(-MAX_HISTORY);
    saveHistory();
    renderMessage('user', content);
    input.value = '';

    activeRequest = new AbortController();
    const controller = activeRequest;
    const timeout = setTimeout(() => controller.abort(), 32000);
    updateComposer();
    setStatus('AICS Guide is typing…');
    const thinking = renderThinking();
    messagesElement.setAttribute('aria-busy', 'true');
    const progressTimer = setTimeout(() => {
      const label = thinking.querySelector('.aics-chatbot-thinking span');
      if (label) label.textContent = 'Still preparing your answer...';
    }, 5000);

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', ...(isApplicant ? { 'X-AICS-CSRF': panel.dataset.csrf } : {}) },
        body: JSON.stringify({ messages: history.slice(-MAX_HISTORY) }),
        signal: controller.signal,
      });
      const payload = await response.json().catch(() => ({}));
      if (activeRequest !== controller) return;
      if (!response.ok || typeof payload.message !== 'string') {
        throw new Error(typeof payload.error === 'string' ? payload.error : 'The assistant could not respond. Please try again.');
      }

      thinking.remove();
      const sources = Array.isArray(payload.sources) ? payload.sources.filter(source => typeof source === 'string').slice(0, 8) : [];
      const answer = payload.message + (sources.length ? '\n\nBased on: ' + sources.join(' · ') : '');
      history.push({ role: 'assistant', content: answer });
      saveHistory();
      const reply = renderMessage('assistant', answer, { alignStart: true });
      if (isApplicant && Array.isArray(payload.actions)) {
        const allowedPages = ['my-profile.php', 'application-status.php', 'documents.php', 'cancel-request.php', 'submit-request.php', 'notifications.php'];
        const actions = document.createElement('div');
        actions.className = 'aics-chatbot-page-actions';
        payload.actions.slice(0, 3).forEach(action => {
          if (!action || !allowedPages.includes(action.page) || typeof action.label !== 'string') return;
          const link = document.createElement('a');
          link.href = new URL(action.page, new URL(endpoint, window.location.href)).href;
          link.textContent = action.label;
          actions.appendChild(link);
        });
        if (actions.childElementCount) reply.querySelector('.aics-chatbot-bubble').appendChild(actions);
      }
      setStatus(isApplicant && payload.local ? 'Record-based answer · AI unavailable' : 'Ready');
    } catch (error) {
      thinking.remove();
      if (activeRequest !== controller) return;
      if (error.name === 'AbortError') {
        input.value = content;
        setStatus('Taking too long. Please try again.');
      } else {
        input.value = content;
        renderMessage('assistant', error.message || 'The assistant is temporarily unavailable.', { error: true });
        setStatus('Message not sent');
      }
    } finally {
      clearTimeout(timeout);
      clearTimeout(progressTimer);
      thinking.remove();
      if (activeRequest === controller) {
        activeRequest = null;
        messagesElement.setAttribute('aria-busy', 'false');
      }
      updateComposer();
    }
  }

  launcher.addEventListener('click', () => panel.hidden ? openChat() : closeChat());
  closeButton?.addEventListener('click', closeChat);
  backdrop?.addEventListener('click', closeChat);
  clearButton?.addEventListener('click', clearConversation);
  sendButton.addEventListener('click', sendMessage);

  input.addEventListener('input', updateComposer);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      sendMessage();
    }
  });

  promptButtons.forEach((button) => {
    button.addEventListener('click', () => {
      input.value = button.dataset.prompt || button.textContent.trim();
      updateComposer();
      sendMessage();
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) {
      closeChat();
      return;
    }
    trapChatbotFocus(event);
  });

  window.addEventListener('resize', () => {
    if (backdrop && !panel.hidden) backdrop.hidden = !window.matchMedia('(max-width: 640px)').matches;
    syncChatbotViewport();
  });
  window.visualViewport?.addEventListener('resize', syncChatbotViewport);
  window.visualViewport?.addEventListener('scroll', syncChatbotViewport);

  history = safeStoredHistory();
  renderConversation();
  updateComposer();
  if (window.location.hash === '#ask-aics') openChat();
})();
