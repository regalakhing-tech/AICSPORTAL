(() => {
  document.querySelectorAll('[data-password-toggle]').forEach((toggleButton) => {
    const targetId = toggleButton.getAttribute('data-target');
    const targetInput = targetId ? document.getElementById(targetId) : null;
    const icon = toggleButton.querySelector('i');
    if (!(targetInput instanceof HTMLInputElement) || !icon) return;

    toggleButton.addEventListener('click', () => {
      const shouldShow = targetInput.type === 'password';
      targetInput.type = shouldShow ? 'text' : 'password';
      toggleButton.setAttribute('aria-pressed', String(shouldShow));
      toggleButton.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
      icon.classList.toggle('fa-eye', !shouldShow);
      icon.classList.toggle('fa-eye-slash', shouldShow);
    });
  });

  if (window.history.replaceState) {
    const url = new URL(window.location.href);
    if (url.searchParams.has('auth')) {
      url.searchParams.delete('auth');
      window.history.replaceState({ path: url.href }, '', url.href);
    }
  }

  const flash = document.getElementById('top-flash-message');
  if (!flash) return;

  if (flash.dataset.bannedAlert === 'true') {
    window.alert(flash.querySelector('span')?.textContent || 'Your administrator account is banned.');
  }

  window.setTimeout(() => {
    flash.classList.add('hide');
    window.setTimeout(() => flash.remove(), 380);
  }, 3200);
})();
