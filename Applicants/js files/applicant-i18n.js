// Applicant page language (English, Tagalog, Hiligaynon). Translates page content in
// place from applicant-i18n-dict.js; the navigation (side bar, top bar, bottom tabs,
// account menu), Ask AICS, and anything a person typed are never changed.
// Each text keeps its English original, so switching back or between languages is exact.
(function () {
  'use strict';

  const LANGUAGES = ['en', 'tl', 'hil'];
  const COLUMN = { tl: 0, hil: 1 };
  const HTML_LANG = { en: 'en', tl: 'fil', hil: 'hil' };
  const STORAGE_KEY = 'aics-applicant-language';
  const DICT = window.AICS_I18N_DICT || {};
  const PATTERNS = window.AICS_I18N_PATTERNS || [];
  const SKIP = '.sidebar, .top-nav, .app-tabbar, .app-sheet, .app-sheet-scrim, .app-segment, .aics-chatbot-panel, .aics-chatbot-launcher, '
    + 'script, style, noscript, textarea, code, pre, [data-no-translate], [contenteditable="true"]';
  const ATTRIBUTES = ['placeholder', 'title', 'aria-label'];
  const root = document.documentElement;
  const textOriginals = new WeakMap();
  const attributeOriginals = new WeakMap();
  let language = LANGUAGES.includes(root.getAttribute('data-aics-lang')) ? root.getAttribute('data-aics-lang') : 'en';
  let started = language !== 'en';

  function lookup(text) {
    if (language === 'en') return null;
    const column = COLUMN[language];
    if (Object.prototype.hasOwnProperty.call(DICT, text)) return DICT[text][column];
    for (const [pattern, outputs] of PATTERNS) {
      const match = text.match(pattern);
      if (!match) continue;
      return outputs[column].replace(/\{(t?)(\d)\}/g, (_, translate, index) => {
        const part = match[Number(index)] || '';
        return translate ? (lookup(part) || part) : part;
      });
    }
    return null;
  }

  function translateString(raw) {
    const text = raw.replace(/\s+/g, ' ').trim();
    if (!text || !/[A-Za-z]/.test(text)) return raw;
    const hit = lookup(text);
    if (hit === null || hit === undefined) return raw;
    return raw.match(/^\s*/)[0] + hit + raw.match(/\s*$/)[0];
  }

  const skipped = (element) => !element || element.closest(SKIP) !== null;

  function applyText(node) {
    if (skipped(node.parentElement)) return;
    let record = textOriginals.get(node);
    if (!record) {
      record = { en: node.data, shown: node.data };
      textOriginals.set(node, record);
      // An <option> without a value would submit its visible text: keep the English one.
      const option = node.parentElement;
      if (option.tagName === 'OPTION' && !option.hasAttribute('value')) option.setAttribute('value', option.textContent.trim());
    } else if (node.data !== record.shown) {
      record.en = node.data; // the page changed this text itself
    }
    const next = language === 'en' ? record.en : translateString(record.en);
    if (node.data !== next) node.data = next;
    record.shown = next;
  }

  function applyAttributes(element) {
    if (skipped(element)) return;
    let records = attributeOriginals.get(element);
    if (!records) {
      records = {};
      attributeOriginals.set(element, records);
    }
    for (const name of ATTRIBUTES) {
      if (!element.hasAttribute(name)) continue;
      const current = element.getAttribute(name);
      if (!records[name] || current !== records[name].shown) records[name] = { en: current, shown: current };
      const next = language === 'en' ? records[name].en : translateString(records[name].en);
      if (current !== next) element.setAttribute(name, next);
      records[name].shown = next;
    }
  }

  function applyTree(start) {
    if (!start) return;
    if (start.nodeType === Node.TEXT_NODE) {
      applyText(start);
      return;
    }
    if (start.nodeType !== Node.ELEMENT_NODE || skipped(start)) return;
    const walker = document.createTreeWalker(start, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) applyText(node);
    applyAttributes(start);
    start.querySelectorAll('[placeholder], [title], [aria-label]').forEach(applyAttributes);
  }

  const observer = new MutationObserver((mutations) => {
    if (!started) return;
    for (const mutation of mutations) {
      if (mutation.type === 'characterData') applyText(mutation.target);
      else if (mutation.type === 'attributes') applyAttributes(mutation.target);
      else mutation.addedNodes.forEach(applyTree);
    }
    observer.takeRecords(); // drop the records our own changes just made
  });

  function setLanguage(next) {
    if (!LANGUAGES.includes(next)) return;
    language = next;
    started = started || next !== 'en';
    root.setAttribute('data-aics-lang', next);
    root.setAttribute('lang', HTML_LANG[next]);
    try { window.localStorage.setItem(STORAGE_KEY, next); } catch (error) { /* private mode */ }
    if (started) {
      applyTree(document.body);
      observer.takeRecords();
    }
    syncPicker();
    document.dispatchEvent(new CustomEvent('aics:languagechange', { detail: { language: next } }));
  }

  // Language picker on My Profile: buttons with data-language, saved to the account.
  function syncPicker() {
    document.querySelectorAll('[data-language-option]').forEach((button) => {
      const active = button.getAttribute('data-language-option') === language;
      button.setAttribute('aria-checked', active ? 'true' : 'false');
      button.classList.toggle('is-active', active);
    });
  }

  function bindPicker() {
    const picker = document.querySelector('[data-language-picker]');
    if (!picker) return;
    const status = picker.querySelector('[data-language-status]');
    const saveUrl = picker.getAttribute('data-save-url') || 'language-save.php';
    picker.querySelectorAll('[data-language-option]').forEach((button) => {
      button.addEventListener('click', () => {
        const next = button.getAttribute('data-language-option');
        if (next === language) return;
        setLanguage(next);
        if (status) status.textContent = '';
        const body = new FormData();
        body.append('language', next);
        fetch(saveUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then((response) => response.json())
          .then((payload) => {
            if (status) status.textContent = payload && payload.ok ? 'Language saved.' : 'Language changed on this device. It will be saved to your account when you are online.';
          })
          .catch(() => {
            if (status) status.textContent = 'Language changed on this device. It will be saved to your account when you are online.';
          });
      });
    });
    syncPicker();
  }

  window.AICS_I18N = { setLanguage, getLanguage: () => language, translate: translateString };

  function start() {
    bindPicker();
    if (started) applyTree(document.body);
    observer.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRIBUTES });
    root.setAttribute('lang', HTML_LANG[language]);
    root.classList.remove('aics-i18n-pending');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
