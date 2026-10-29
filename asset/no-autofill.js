// Login fields marked data-no-autofill start read-only, so the browser does not fill in
// saved emails and passwords when the page opens. The first tap, click or Tab into the
// form makes its fields editable again, before the browser checks required fields on submit.
(function () {
  document.querySelectorAll('form').forEach(function (form) {
    var fields = form.querySelectorAll('input[data-no-autofill]');
    if (!fields.length) return;

    function unlock() {
      fields.forEach(function (input) {
        input.removeAttribute('readonly');
      });
    }

    ['pointerdown', 'focusin', 'keydown'].forEach(function (type) {
      form.addEventListener(type, unlock);
    });
    if (form.contains(document.activeElement)) unlock();
  });
})();
