'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');

const {
  createAiRequestPoller,
  createAiRequestStarter,
  deriveRequestView,
  nextAiPollDelay,
  setScanningVisibility,
  terminalDocumentReviewUrl,
  bindRequestRefreshEvents,
} = require(path.join(__dirname, '..', 'Pswdo', 'js files', 'ai-request-poller.js'));

async function run() {
  const startCalls = [];
  const starter = createAiRequestStarter({
    endpoint: 'http://localhost/ai-ensure-request-current.php',
    csrfToken: 'test-csrf',
    fetchImpl: async (url, options) => {
      startCalls.push([url, options]);
      return { ok: true, json: async () => ({ ok: true }) };
    },
  });
  starter.start('42');
  starter.start('42');
  await starter.whenIdle('42');
  assert.equal(startCalls.length, 1, 'The same queued request was started more than once.');
  assert.equal(startCalls[0][1].method, 'POST', 'AI start did not use POST.');
  assert.equal(startCalls[0][1].body.get('request_id'), '42', 'AI start omitted the request identifier.');
  assert.equal(startCalls[0][1].body.get('csrf_token'), 'test-csrf', 'AI start omitted CSRF protection.');

  assert.deepEqual(
    deriveRequestView({ state: 'scanning', label: 'Scanning 1 of 3', progress_percent: 33 }),
    { state: 'scanning', label: 'Scanning 1 of 3', progress: 33, scanning: true, terminal: false, hasFlaggedInconsistency: false },
    'Scanning payload did not create the active row view.',
  );
  assert.equal(
    deriveRequestView({ state: 'attention', label: '1 needs review', progress_percent: 100, documents: [{ document_id: 7, status: 'flagged_inconsistency' }] }).hasFlaggedInconsistency,
    true,
    'Flagged document was not exposed in the request view.',
  );
  assert.equal(nextAiPollDelay(0), 2500, 'Normal polling delay changed.');
  assert.equal(nextAiPollDelay(1), 5000, 'First retry must back off.');
  assert.equal(nextAiPollDelay(9), 30000, 'Retry delay must be capped.');

  assert.equal(
    terminalDocumentReviewUrl(
      'http://localhost/dashboard.php?page=requests&date_filter=week&status=success&message=queued',
      '42',
      '77',
    ),
    'http://localhost/dashboard.php?page=requests&date_filter=week&reopen_request=42&reopen_ai_document=77',
    'Completed explicit rescan did not preserve filters and reopen the canonical document details.',
  );
  assert.equal(
    terminalDocumentReviewUrl('http://localhost/dashboard.php?page=requests', 'bad', '77'),
    '',
    'Invalid request identifiers produced a navigation target.',
  );

  const terminalElement = { hidden: false, style: { display: 'block' } };
  setScanningVisibility(terminalElement, false);
  assert.equal(terminalElement.hidden, true, 'Terminal state did not apply the hidden attribute.');
  assert.equal(terminalElement.style.display, 'none', 'Terminal state remained visible because component CSS overrode hidden.');
  setScanningVisibility(terminalElement, true);
  assert.equal(terminalElement.hidden, false, 'Scanning state did not restore the element.');
  assert.equal(terminalElement.style.display, '', 'Scanning state retained an inline display override.');

  const terminalUpdates = [];
  const terminalSchedules = [];
  const terminalPoller = createAiRequestPoller({
    endpoint: 'http://localhost/ai-request-status.php',
    fetchImpl: async () => ({ ok: true, json: async () => ({ ok: true, request_id: 9, state: 'ready', label: 'AI results ready', progress_percent: 100 }) }),
    onUpdate: (requestId, view) => terminalUpdates.push([requestId, view]),
    schedule: (callback, delay) => { terminalSchedules.push([callback, delay]); return 1; },
    cancel: () => {},
    isHidden: () => false,
  });
  terminalPoller.start('9');
  await terminalPoller.whenIdle('9');
  assert.equal(terminalUpdates.length, 1, 'Terminal payload was not rendered once.');
  assert.equal(terminalUpdates[0][1].terminal, true, 'Ready payload was not terminal.');
  assert.equal(terminalSchedules.length, 0, 'Terminal request continued polling.');

  const eventListeners = new Map();
  const eventTarget = {
    addEventListener: (name, listener) => eventListeners.set(name, listener),
    removeEventListener: (name, listener) => { if (eventListeners.get(name) === listener) eventListeners.delete(name); },
    dispatchEvent: (event) => { const listener = eventListeners.get(event.type); if (listener) listener(event); },
  };
  const synchronizedUpdates = [];
  let synchronizedFetchCount = 0;
  let resolveStaleRequest;
  const synchronizedPoller = createAiRequestPoller({
    endpoint: 'http://localhost/ai-request-status.php',
    fetchImpl: async () => {
      synchronizedFetchCount += 1;
      if (synchronizedFetchCount === 1) return new Promise((resolve) => { resolveStaleRequest = resolve; });
      return { ok: true, json: async () => ({ ok: true, request_id: 187, state: 'attention', label: '3 need review', progress_percent: 100, documents: [] }) };
    },
    onUpdate: (requestId, view) => synchronizedUpdates.push([requestId, view]),
    schedule: () => 3,
    cancel: () => {},
    isHidden: () => false,
  });
  const unbindRequestRefresh = bindRequestRefreshEvents(synchronizedPoller, eventTarget);
  const staleRequest = synchronizedPoller.refresh('187');
  eventTarget.dispatchEvent({ type: 'aics:ai-request-refresh', detail: { requestId: '187' } });
  resolveStaleRequest({ ok: true, json: async () => ({ ok: true, request_id: 187, state: 'scanning', label: 'Scanning 2 of 3', progress_percent: 67, documents: [] }) });
  await staleRequest;
  await synchronizedPoller.whenIdle('187');
  unbindRequestRefresh();
  assert.equal(synchronizedFetchCount, 2, 'Completion during an active request did not force one fresh request-row read.');
  assert.equal(synchronizedUpdates.length, 2, 'Completion did not render both the stale and refreshed request states.');
  assert.equal(synchronizedUpdates[1][1].label, '3 need review', 'Document completion rendered a stale request label.');

  const retrySchedules = [];
  const retryPoller = createAiRequestPoller({
    endpoint: 'http://localhost/ai-request-status.php',
    fetchImpl: async () => { throw new Error('temporary network failure'); },
    onUpdate: () => {},
    schedule: (callback, delay) => { retrySchedules.push([callback, delay]); return 2; },
    cancel: () => {},
    isHidden: () => false,
  });
  retryPoller.start('12');
  await retryPoller.whenIdle('12');
  assert.equal(retrySchedules.length, 1, 'Transient failure did not schedule a retry.');
  assert.equal(retrySchedules[0][1], 5000, 'Transient failure used the wrong backoff delay.');

  console.log('AI request poller behavior test passed.');
}

run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
