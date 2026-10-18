(function () {
  function isDigitField(target) {
    return target && typeof target.matches === 'function' && target.matches('[data-digits-only]');
  }

  function cleanField(target) {
    if (!isDigitField(target)) return;
    var original = target.value;
    var digits = original.replace(/[^0-9]/g, '');
    if (target.maxLength >= 0) digits = digits.slice(0, target.maxLength);
    if (digits === original) return;

    var caret = target.selectionStart;
    target.value = digits;
    if (typeof caret === 'number' && typeof target.setSelectionRange === 'function') {
      var cleanCaret = original.slice(0, caret).replace(/[^0-9]/g, '').length;
      target.setSelectionRange(Math.min(cleanCaret, digits.length), Math.min(cleanCaret, digits.length));
    }
  }

  document.addEventListener('beforeinput', function (event) {
    if (isDigitField(event.target) && event.inputType === 'insertText' &&
        typeof event.data === 'string' && /[^0-9]/.test(event.data)) {
      event.preventDefault();
    }
  }, true);

  document.addEventListener('paste', function (event) {
    var target = event.target;
    if (!isDigitField(target) || !event.clipboardData) return;
    event.preventDefault();

    var digits = event.clipboardData.getData('text').replace(/[^0-9]/g, '');
    var start = target.selectionStart;
    var end = target.selectionEnd;
    var prefix = target.value.slice(0, start);
    var suffix = target.value.slice(end);
    if (target.maxLength >= 0) {
      digits = digits.slice(0, Math.max(0, target.maxLength - prefix.length - suffix.length));
    }
    target.value = prefix + digits + suffix;

    var caret = prefix.length + digits.length;
    target.setSelectionRange(caret, caret);
    target.dispatchEvent(new Event('input', { bubbles: true }));
  }, true);

  document.addEventListener('input', function (event) {
    cleanField(event.target);
  }, true);

  document.addEventListener('focusin', function (event) {
    cleanField(event.target);
  }, true);

  var existingFields = document.querySelectorAll('[data-digits-only]');
  for (var i = 0; i < existingFields.length; i++) cleanField(existingFields[i]);
}());
