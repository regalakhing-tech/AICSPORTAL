(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.AicsAiRequestPoller = api;
}(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';

  const TERMINAL_STATES = new Set(['ready', 'attention', 'reupload', 'failed', 'empty']);

  function nextAiPollDelay(failures) {
    const count = Math.max(0, Number(failures) || 0);
    return Math.min(30000, 2500 * (2 ** Math.min(count, 4)));
  }

  function terminalDocumentReviewUrl(currentUrl, requestId, documentId) {
    const requestKey = String(requestId || '');
    const documentKey = String(documentId || '');
    if (!/^\d+$/.test(requestKey) || !/^\d+$/.test(documentKey)) return '';
    try {
      const url = new URL(String(currentUrl || ''), 'http://localhost/');
      url.searchParams.delete('status');
      url.searchParams.delete('message');
      url.searchParams.set('reopen_request', requestKey);
      url.searchParams.set('reopen_ai_document', documentKey);
      return url.toString();
    } catch (_error) {
      return '';
    }
  }

  function deriveRequestView(payload) {
    const state = String(payload && payload.state || 'failed').toLowerCase();
    const numericProgress = Number(payload && payload.progress_percent);
    const progress = Number.isFinite(numericProgress) ? Math.max(0, Math.min(100, Math.round(numericProgress))) : 0;
    const documents = Array.isArray(payload && payload.documents) ? payload.documents : [];
    return {
      state,
      label: String(payload && payload.label || 'Scan unavailable'),
      progress,
      scanning: state === 'scanning',
      terminal: TERMINAL_STATES.has(state),
      hasFlaggedInconsistency: documents.some(function (document) {
        return String(document && document.status || '').toLowerCase() === 'flagged_inconsistency';
      }),
    };
  }

  function setScanningVisibility(element, scanning) {
    if (!element) return;
    element.hidden = !scanning;
    if (element.style) element.style.display = scanning ? '' : 'none';
    if (typeof element.setAttribute === 'function') {
      element.setAttribute('aria-hidden', scanning ? 'false' : 'true');
    }
  }

  function createAiRequestStarter(options) {
    const endpoint = String(options && options.endpoint || '');
    const csrfToken = String(options && options.csrfToken || '');
    const fetchImpl = options && options.fetchImpl;
    const jobs = new Map();

    function start(requestId) {
      const key = String(requestId);
      if (!/^\d+$/.test(key) || endpoint === '' || csrfToken === '' || typeof fetchImpl !== 'function') return Promise.resolve(false);
      if (jobs.has(key)) return jobs.get(key);

      const body = new URLSearchParams();
      body.set('csrf_token', csrfToken);
      body.set('request_id', key);
      const job = (async function () {
        try {
          const response = await fetchImpl(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
            body,
          });
          const payload = await response.json();
          if (!response.ok || !payload || payload.ok !== true) throw new Error('Verification could not be started.');
          return true;
        } catch (_error) {
          jobs.delete(key);
          return false;
        }
      }());
      jobs.set(key, job);
      return job;
    }

    function whenIdle(requestId) {
      return jobs.get(String(requestId)) || Promise.resolve();
    }

    return { start, whenIdle };
  }

  function createAiRequestPoller(options) {
    const endpoint = String(options && options.endpoint || '');
    const fetchImpl = options.fetchImpl;
    const onUpdate = options.onUpdate;
    const schedule = options.schedule;
    const cancel = options.cancel;
    const isHidden = options.isHidden;
    const jobs = new Map();

    function ensureJob(requestId) {
      const key = String(requestId);
      if (!jobs.has(key)) jobs.set(key, { failures: 0, timer: null, inFlight: null });
      return jobs.get(key);
    }

    function stop(requestId) {
      const key = String(requestId);
      const job = jobs.get(key);
      if (job && job.timer !== null) cancel(job.timer);
      jobs.delete(key);
    }

    function queue(requestId, delay) {
      const key = String(requestId);
      const job = ensureJob(key);
      if (job.timer !== null) cancel(job.timer);
      job.timer = schedule(function () {
        job.timer = null;
        refresh(key);
      }, delay);
    }

    function refresh(requestId) {
      const key = String(requestId);
      const job = ensureJob(key);
      if (job.inFlight) return job.inFlight;
      if (isHidden()) {
        queue(key, nextAiPollDelay(0));
        return Promise.resolve();
      }
      job.inFlight = (async function () {
        try {
          const url = new URL(endpoint, typeof location !== 'undefined' ? location.href : 'http://localhost/');
          url.searchParams.set('request_id', key);
          const response = await fetchImpl(url.toString(), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
          const payload = await response.json();
          if (!response.ok || !payload || payload.ok !== true) throw new Error('Verification progress unavailable.');
          const view = deriveRequestView(payload);
          job.failures = 0;
          onUpdate(key, view, payload);
          if (view.terminal) stop(key);
          else queue(key, nextAiPollDelay(0));
        } catch (_error) {
          job.failures += 1;
          queue(key, nextAiPollDelay(job.failures));
        } finally {
          job.inFlight = null;
        }
      }());
      return job.inFlight;
    }

    function start(requestId) {
      const key = String(requestId);
      if (!/^\d+$/.test(key) || endpoint === '' || typeof fetchImpl !== 'function') return;
      ensureJob(key);
      refresh(key);
    }

    function whenIdle(requestId) {
      const job = jobs.get(String(requestId));
      return job && job.inFlight ? job.inFlight : Promise.resolve();
    }

    return { start, stop, refresh, whenIdle };
  }

  function bindRequestRefreshEvents(poller, eventTarget) {
    const target = eventTarget && typeof eventTarget.addEventListener === 'function' ? eventTarget : null;
    if (!target || !poller || typeof poller.start !== 'function') return function () {};
    const refreshRequest = function (event) {
      const requestId = String(event && event.detail && event.detail.requestId || '');
      if (/^\d+$/.test(requestId)) poller.start(requestId);
    };
    target.addEventListener('aics:ai-request-refresh', refreshRequest);
    return function () {
      if (typeof target.removeEventListener === 'function') target.removeEventListener('aics:ai-request-refresh', refreshRequest);
    };
  }

  function renderRequestRow(requestId, view, payload) {
    const row = document.querySelector('[data-ai-request-row][data-request-id="' + String(requestId) + '"]');
    if (!(row instanceof HTMLElement)) return;
    row.dataset.aiState = view.state;
    row.classList.toggle('is-ai-scanning', view.scanning);
    row.classList.toggle('has-ai-flagged-inconsistency', view.hasFlaggedInconsistency === true);
    const label = row.querySelector('.request-ai-label span');
    if (label) label.textContent = view.label;
    const icon = row.querySelector('.request-ai-label i');
    setScanningVisibility(icon, view.scanning);
    const progress = row.querySelector('.request-ai-progress');
    if (progress instanceof HTMLElement) {
      setScanningVisibility(progress, view.scanning);
      progress.setAttribute('aria-valuenow', String(view.progress));
      const fill = progress.querySelector('span');
      if (fill instanceof HTMLElement) fill.style.setProperty('--ai-progress', view.progress + '%');
    }
    row.dataset.aiActiveDocuments = String(Math.max(0, Number(payload.queued || 0) + Number(payload.active || 0)));
  }

  function bootstrap() {
    if (typeof document === 'undefined' || typeof window === 'undefined') return;
    const endpoint = String(document.body && document.body.dataset.aiRequestStatusUrl || '');
    const ensureEndpoint = String(document.body && document.body.dataset.aiEnsureRequestUrl || '');
    const csrfToken = String(document.body && document.body.dataset.aiCsrfToken || '');
    if (endpoint === '' || typeof window.fetch !== 'function') return;
    const poller = createAiRequestPoller({
      endpoint,
      fetchImpl: window.fetch.bind(window),
      onUpdate: renderRequestRow,
      schedule: window.setTimeout.bind(window),
      cancel: window.clearTimeout.bind(window),
      isHidden: function () { return document.visibilityState === 'hidden'; },
    });
    const starter = createAiRequestStarter({
      endpoint: ensureEndpoint,
      csrfToken,
      fetchImpl: window.fetch.bind(window),
    });
    bindRequestRefreshEvents(poller, document);
    function discover() {
      document.querySelectorAll('[data-ai-request-row][data-ai-state="scanning"]').forEach(function (row) {
        const requestId = row.getAttribute('data-request-id') || '';
        poller.start(requestId);
        starter.start(requestId);
      });
    }
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') discover();
    });
    discover();
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
    else bootstrap();
  }

  return { createAiRequestPoller, createAiRequestStarter, bindRequestRefreshEvents, deriveRequestView, nextAiPollDelay, setScanningVisibility, terminalDocumentReviewUrl, renderRequestRow, bootstrap };
}));
