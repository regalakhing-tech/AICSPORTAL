(() => {
  const modal = document.getElementById('password-reset-modal');
  const trigger = document.getElementById('forgot-password-trigger');
  const form = document.getElementById('password-reset-form');
  if (!modal || !trigger || !form) return;

  const phone = document.getElementById('password-reset-phone');
  const code = document.getElementById('password-reset-code');
  const password = document.getElementById('password-reset-password');
  const confirmation = document.getElementById('password-reset-confirm');
  const fields = document.getElementById('password-reset-fields');
  const sendButton = document.getElementById('password-reset-send');
  const submitButton = document.getElementById('password-reset-submit');
  const feedback = document.getElementById('password-reset-feedback');
  const loginIdentifier = document.getElementById('login-identifier');
  const loginAction = document.getElementById('login-access-form')?.action || 'php_files/auth-login.php';
  const endpoint = loginAction.replace(/auth-login\.php(?:$|\?)/, 'auth-reset-password.php');
  let cooldownUntil = 0;
  let cooldownTimer = null;
  let previousFocus = null;
  let sentPhone = '';

  function message(text, error = false) {
    feedback.textContent = text;
    feedback.classList.toggle('is-error', error);
    feedback.classList.toggle('is-success', !error && text !== '');
  }

  function close() {
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('popup-open');
    previousFocus?.focus();
  }

  function updateCooldown() {
    const seconds = Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000));
    sendButton.disabled = seconds > 0;
    sendButton.textContent = seconds > 0 ? `Resend code in ${seconds}s` : (sentPhone ? 'Resend SMS code' : 'Send SMS code');
    if (seconds === 0 && cooldownTimer) {
      clearInterval(cooldownTimer);
      cooldownTimer = null;
    }
  }

  async function request(action, values) {
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ action, phone: phone.value.trim(), ...values }),
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result?.success) throw new Error(result?.message || 'Request failed. Please try again.');
    return result;
  }

  trigger.addEventListener('click', () => {
    previousFocus = document.activeElement;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('popup-open');
    const loginPhone = (loginIdentifier?.value || '').replace(/^\+/, '');
    phone.value = sentPhone || (/^(?:09\d{9}|639\d{9})$/.test(loginPhone) ? loginPhone : '');
    message('');
    phone.focus();
  });
  modal.querySelectorAll('[data-reset-close]').forEach((button) => button.addEventListener('click', close));
  modal.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') close();
    if (event.key === 'Tab') {
      const focusable = [...modal.querySelectorAll('button:not([disabled]), input:not([disabled])')].filter((element) => element.offsetParent !== null);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
  });
  phone.addEventListener('input', () => {
    if (phone.value.trim() !== sentPhone) {
      fields.classList.add('hidden');
      code.value = '';
      password.value = '';
      confirmation.value = '';
      message('');
    } else if (sentPhone) {
      fields.classList.remove('hidden');
    }
  });

  sendButton.addEventListener('click', async () => {
    if (!/^(?:09\d{9}|\+?639\d{9})$/.test(phone.value.trim())) {
      message('Enter a valid mobile number such as 09XXXXXXXXX.', true);
      phone.focus();
      return;
    }
    sendButton.disabled = true;
    phone.disabled = true;
    sendButton.textContent = 'Sending...';
    message('');
    try {
      const result = await request('send', {});
      sentPhone = phone.value.trim();
      fields.classList.remove('hidden');
      message(result.message);
      cooldownUntil = Date.now() + (result.cooldown_seconds || 60) * 1000;
      updateCooldown();
      if (cooldownTimer) clearInterval(cooldownTimer);
      cooldownTimer = setInterval(updateCooldown, 1000);
      code.focus();
    } catch (error) {
      message(error.message || 'SMS could not be sent. Please try again.', true);
      sendButton.disabled = false;
      sendButton.textContent = 'Send SMS code';
    } finally {
      phone.disabled = false;
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (fields.classList.contains('hidden')) return;
    if (!/^[0-9]{6}$/.test(code.value.trim())) { message('Enter the six digit SMS code.', true); code.focus(); return; }
    if (password.value.length < 8 || /\s/.test(password.value) || !/[A-Z]/.test(password.value) || !/[a-z]/.test(password.value) || !/[0-9]/.test(password.value)) {
      message('Use at least 8 characters with uppercase, lowercase, and a number. No spaces.', true);
      password.focus();
      return;
    }
    if (password.value !== confirmation.value) { message('Passwords do not match.', true); confirmation.focus(); return; }
    submitButton.disabled = true;
    phone.disabled = true;
    submitButton.textContent = 'Resetting...';
    try {
      const result = await request('reset', { code: code.value.trim(), password: password.value, confirm_password: confirmation.value });
      const resetPhone = phone.value.trim();
      form.reset();
      sentPhone = '';
      fields.classList.add('hidden');
      cooldownUntil = 0;
      updateCooldown();
      close();
      if (loginIdentifier) loginIdentifier.value = resetPhone;
      if (typeof showToast === 'function') showToast(result.message, 'success');
      document.getElementById('login-password')?.focus();
    } catch (error) {
      message(error.message || 'Password reset failed. Please try again.', true);
    } finally {
      phone.disabled = false;
      submitButton.disabled = false;
      submitButton.textContent = 'Reset password';
    }
  });
})();
