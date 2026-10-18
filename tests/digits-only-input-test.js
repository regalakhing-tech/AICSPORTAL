try {
var fs = new ActiveXObject('Scripting.FileSystemObject');
var scriptPath = fs.GetParentFolderName(fs.GetParentFolderName(WScript.ScriptFullName)) + '\\asset\\digits-only.js';
var events = {};
var existing = field('+639123456789', 12);
var document = {
  addEventListener: function (name, handler) { events[name] = handler; },
  querySelectorAll: function () { return [existing]; }
};
function Event(name) { this.type = name; }

function assert(value, message) {
  if (!value) throw new Error(message);
}

if (!fs.FileExists(scriptPath)) throw new Error('Digit filter script is missing');
eval(fs.OpenTextFile(scriptPath, 1).ReadAll());
assert(existing.value === '639123456789', 'existing phone value must display digits only');
existing.value = '09a123456789';
events.focusin({target: existing});
assert(existing.value === '09123456789', 'programmatically filled phone must be cleaned on focus');

function field(value, maxLength) {
  return {
    value: value,
    maxLength: maxLength,
    selectionStart: value.length,
    selectionEnd: value.length,
    matches: function (selector) { return selector === '[data-digits-only]'; },
    setSelectionRange: function (start, end) { this.selectionStart = start; this.selectionEnd = end; },
    dispatchEvent: function (event) { if (events[event.type]) events[event.type]({target: this}); }
  };
}

var phone = field('09', 11);
var blocked = false;
events.beforeinput({target: phone, inputType: 'insertText', data: 'a', preventDefault: function () { blocked = true; }});
assert(blocked, 'letters must be blocked before entry');
blocked = false;
events.beforeinput({target: phone, inputType: 'insertText', data: '+', preventDefault: function () { blocked = true; }});
assert(blocked, 'symbols must be blocked before entry');
blocked = false;
events.beforeinput({target: phone, inputType: 'insertText', data: '1', preventDefault: function () { blocked = true; }});
assert(!blocked, 'digits must remain available');
events.beforeinput({target: phone, inputType: 'insertFromPaste', data: '12-34', preventDefault: function () { blocked = true; }});
assert(!blocked, 'pasted digits must be accepted and cleaned');

var pastedOtp = field('', 6);
var pastePrevented = false;
events.paste({
  target: pastedOtp,
  clipboardData: {getData: function () { return '123-456'; }},
  preventDefault: function () { pastePrevented = true; }
});
assert(pastePrevented && pastedOtp.value === '123456', 'formatted OTP paste must keep all six digits');

var fullPhone = field('09123456789', 11);
fullPhone.selectionStart = 1;
fullPhone.selectionEnd = 1;
events.paste({
  target: fullPhone,
  clipboardData: {getData: function () { return '55'; }},
  preventDefault: function () {}
});
assert(fullPhone.value === '09123456789', 'paste into a full field must preserve existing digits');

phone = field('09a12+3456789', 11);
events.input({target: phone});
assert(phone.value === '09123456789', 'pasted phone must contain only 11 digits');

var otp = field('12ab+34567', 6);
events.input({target: otp});
assert(otp.value === '123456', 'OTP must contain only six digits');

var other = {value: 'a+1', matches: function () { return false; }};
events.input({target: other});
assert(other.value === 'a+1', 'unrelated fields must not change');

WScript.Echo('Digit input behavior passed');
} catch (error) {
  WScript.Echo('Digit input behavior failed: ' + error.message);
  WScript.Quit(1);
}
