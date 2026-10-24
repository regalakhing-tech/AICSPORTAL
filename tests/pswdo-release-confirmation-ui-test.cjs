// Exercise the real dialog script at its DOM boundary without a browser dependency.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(process.argv[2] || path.join(__dirname, '../Pswdo/php_files/release-scheduling.php'), 'utf8');
const script = source.match(/\/\/ Mark as Released modal\s*([\s\S]*?)\/\/ Reschedule \(Did Not Arrive\) modal/);
assert.ok(script, 'The release dialog script must be available.');
const elements = new Map();
const keyListeners = [];
const document = {
  activeElement: null,
  getElementById: (id) => elements.get(id) || null,
  querySelectorAll: () => [trigger],
  addEventListener: (event, handler) => { if (event === 'keydown') keyListeners.push(handler); },
};
class Element {
  constructor(id) {
    this.value = '';
    this.dataset = {};
    this.textContent = '';
    this.listeners = new Map();
    const classes = new Set();
    this.classList = { add: (name) => classes.add(name), remove: (name) => classes.delete(name), contains: (name) => classes.has(name) };
    elements.set(id, this);
  }
  addEventListener(event, handler) { this.listeners.set(event, handler); }
  fire(event, target = this) { this.listeners.get(event)?.({ target }); }
  focus() { document.activeElement = this; }
}
for (const id of ['mark-released-modal', 'mark-released-form', 'mark-released-desc', 'mark-released-request-id', 'mark-released-csrf', 'mark-released-sort', 'mark-released-group', 'mark-released-date-from', 'mark-released-date-to', 'mark-released-batch-key', 'mark-released-batch-limit', 'cancel-mark-released']) new Element(id);
const modal = elements.get('mark-released-modal');
const form = elements.get('mark-released-form');
const cancel = elements.get('cancel-mark-released');
const confirm = new Element('confirm-release');
form.querySelector = () => confirm;
const trigger = new Element('release-trigger');
trigger.dataset = { requestId: '123', csrf: 'test-csrf', beneficiary: 'Test Applicant', reference: 'AICS-2026-TEST01', returnSort: 'oldest', returnGroup: 'batch', returnDateFrom: '2026-10-24', returnDateTo: '2026-10-25', returnBatchKey: 'Batch 1', returnBatchLimit: '10' };
vm.runInNewContext(script[1], { document, HTMLInputElement: Element });
const key = (key, shiftKey = false) => {
  const event = { key, shiftKey, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
  keyListeners.forEach((handler) => handler(event));
  return event;
};

trigger.fire('click');
assert.equal(modal.classList.contains('open'), true, 'Release must open even when no cash input exists.');
assert.equal(elements.get('mark-released-request-id').value, '123');
assert.equal(elements.get('mark-released-csrf').value, 'test-csrf');
assert.equal(elements.get('mark-released-batch-limit').value, '10');
assert.match(elements.get('mark-released-desc').textContent, /Test Applicant.*AICS-2026-TEST01/);
assert.equal(document.activeElement, cancel, 'Cancel must receive focus before a consequential confirmation.');
assert.equal(key('Tab', true).defaultPrevented, true);
assert.equal(document.activeElement, confirm, 'Reverse tab must stay in the confirmation dialog.');
assert.equal(key('Tab').defaultPrevented, true);
assert.equal(document.activeElement, cancel, 'Tab from Confirm must stay in the dialog.');
modal.fire('click', form);
assert.equal(modal.classList.contains('open'), true, 'Clicking inside the dialog must not dismiss it.');
cancel.fire('click');
assert.equal(modal.classList.contains('open'), false);
assert.equal(document.activeElement, trigger, 'Closing must return focus to Release.');
trigger.fire('click');
assert.equal(key('Escape').defaultPrevented, true);
assert.equal(modal.classList.contains('open'), false);
trigger.fire('click');
modal.fire('click');
assert.equal(modal.classList.contains('open'), false);
console.log('PSWDO release confirmation interaction checks passed.');
