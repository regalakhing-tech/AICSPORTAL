let currentSignupStep = 1;

// Shared computers: remove AICS Guide chats kept in this tab from earlier sign-ins.
(() => {
  try {
    Object.keys(window.sessionStorage)
      .filter((key) => key.startsWith('aics-chat-'))
      .forEach((key) => window.sessionStorage.removeItem(key));
  } catch (_) { /* storage may be unavailable */ }
})();

function aicsEscapeHtml(str) {
  if (typeof str !== 'string') return '';
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function showToast(message, type = 'error', title = null, duration = 5000) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container';
    container.setAttribute('aria-live', 'assertive');
    container.setAttribute('aria-atomic', 'true');
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

  let defaultTitle = title;
  let iconClass = 'fas fa-info-circle';
  if (type === 'error') {
    defaultTitle = title || 'Error';
    iconClass = 'fas fa-circle-exclamation';
  } else if (type === 'success') {
    defaultTitle = title || 'Success';
    iconClass = 'fas fa-circle-check';
  } else if (type === 'warning') {
    defaultTitle = title || 'Notice';
    iconClass = 'fas fa-triangle-exclamation';
  } else {
    defaultTitle = title || 'Information';
    iconClass = 'fas fa-circle-info';
  }

  toast.innerHTML = `
    <i class="${iconClass} toast-icon" aria-hidden="true"></i>
    <div class="toast-content">
      ${defaultTitle ? `<div class="toast-title">${aicsEscapeHtml(defaultTitle)}</div>` : ''}
      <div class="toast-message">${aicsEscapeHtml(message)}</div>
    </div>
    <button type="button" class="toast-close" aria-label="Close notification">&times;</button>
  `;

  const closeBtn = toast.querySelector('.toast-close');
  let dismissTimer = null;

  const removeToast = () => {
    if (dismissTimer) clearTimeout(dismissTimer);
    toast.classList.remove('is-visible');
    toast.classList.add('is-leaving');
    setTimeout(() => {
      if (toast.parentNode) toast.remove();
    }, 320);
  };

  if (closeBtn) {
    closeBtn.addEventListener('click', removeToast);
  }

  container.appendChild(toast);

  requestAnimationFrame(() => {
    toast.classList.add('is-visible');
  });

  if (duration > 0) {
    dismissTimer = setTimeout(removeToast, duration);
  }
}

function getAuthMessageMap() {
  return {
    missing_fields: { type: 'error', text: 'Please complete all required fields. Use a valid email if you enter one.' },
    invalid_contact: { type: 'error', text: 'Phone number must be in 09XXXXXXXXX format.' },
    invalid_name: { type: 'error', text: 'Names may contain letters, spaces, hyphens, and apostrophes only.' },
    invalid_email: { type: 'error', text: 'Enter a valid email address or leave it blank.' },
    weak_password: { type: 'error', text: 'Password does not meet the requirements. Use at least 8 characters with uppercase, lowercase, and a number. Spaces are not allowed.' },
    password_mismatch: { type: 'error', text: 'Passwords do not match.' },
    email_exists: { type: 'error', text: 'That email is already registered.' },
    phone_exists: { type: 'error', text: 'That mobile number is already registered.' },
    signup_success: { type: 'success', text: 'Account created successfully! Please log in.' },
    id_required: { type: 'error', text: 'Please scan the front and back of your valid ID first.' },
    id_not_verified: { type: 'error', text: 'Your ID could not be verified. Please scan a clear photo of your original ID.' },
    id_name_mismatch: { type: 'error', text: 'Your name must match the name on your ID.' },
    id_birthdate_mismatch: { type: 'error', text: 'Your birth date must match the birth date on your ID.' },
    signup_failed: { type: 'error', text: 'Sign up failed. Please check your information and try again.' },
    invalid_otp: { type: 'error', text: 'The entered OTP code is incorrect. Please check the code sent to your phone.' },
    otp_expired: { type: 'error', text: 'Your OTP code has expired. Please click "RESEND OTP" to get a new code.' },
    invalid_credentials: { type: 'error', text: 'Incorrect email, mobile number, or password.' },
    account_banned: { type: 'error', text: 'Your account has been banned by the administrator. Please contact support.' },
    account_disabled: { type: 'error', text: 'Your account is disabled. Please contact support.' },
    account_restricted: { type: 'error', text: 'Your account is restricted. Please contact support.' },
    account_locked: { type: 'error', text: 'Your account is locked. Please contact support.' },
    account_inactive: { type: 'error', text: 'Your account is inactive. Please contact support.' },
    database_unavailable: { type: 'error', text: 'Database is unavailable. Start MySQL in XAMPP and verify connection.' },
    login_failed: { type: 'error', text: 'Login failed. Please try again.' },
    login_required: { type: 'error', text: 'Please log in first to access your dashboard.' },
    logged_out: { type: 'success', text: 'You have been logged out.' },
    underage: { type: 'error', text: 'You must be at least 18 years old to register as an applicant.' },
    invalid_birthdate: { type: 'error', text: 'Please enter a valid date of birth.' },
  };
}

function validateAndCalculateAge(showErrors = false) {
  const birthdateInput = document.getElementById('register-birthdate');
  const isoInput = document.getElementById('register-birthdate-iso');
  const ageInput = document.getElementById('register-age');
  const birthdateWrapper = document.getElementById('register-birthdate-wrapper');
  const ageWrapper = document.getElementById('register-age-wrapper');
  const birthdateError = document.getElementById('register-birthdate-error');
  const ageError = document.getElementById('register-age-error');
  const selectedDate = document.getElementById('register-birthdate-selected');

  if (!(birthdateInput instanceof HTMLInputElement) || !(isoInput instanceof HTMLInputElement)
      || !(ageInput instanceof HTMLInputElement)) {
    return { valid: false, age: null, reason: 'missing_elements' };
  }

  const val = birthdateInput.value.trim();
  isoInput.value = '';
  if (selectedDate) {
    selectedDate.textContent = '';
    selectedDate.classList.add('hidden');
  }
  if (!val) {
    ageInput.value = '';
    birthdateWrapper?.classList.remove('has-error', 'has-success');
    ageWrapper?.classList.remove('has-error', 'has-success');
    if (birthdateError) {
      birthdateError.classList.add('hidden');
      birthdateError.textContent = '';
    }
    if (ageError) {
      ageError.classList.add('hidden');
      ageError.textContent = '';
    }

    if (showErrors) {
      if (birthdateError) {
        birthdateError.textContent = 'Please enter your date of birth.';
        birthdateError.classList.remove('hidden');
      }
      birthdateWrapper?.classList.add('has-error');
    }
    return { valid: false, age: null, reason: 'empty' };
  }

  const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(val);
  if (!parts) {
    if (showErrors && birthdateError) {
      birthdateError.textContent = 'Please enter a valid date of birth.';
      birthdateError.classList.remove('hidden');
    }
    birthdateWrapper?.classList.add('has-error');
    ageInput.value = 'Invalid Date';
    return { valid: false, age: null, reason: 'format' };
  }

  const year = parseInt(parts[1], 10);
  const month = parseInt(parts[2], 10) - 1;
  const day = parseInt(parts[3], 10);
  const birthDate = new Date(year, month, day);

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  if (isNaN(birthDate.getTime()) || birthDate.getFullYear() !== year || birthDate.getMonth() !== month || birthDate.getDate() !== day) {
    if (showErrors && birthdateError) {
      birthdateError.textContent = 'Please enter a valid calendar date.';
      birthdateError.classList.remove('hidden');
    }
    birthdateWrapper?.classList.add('has-error');
    ageInput.value = 'Invalid Date';
    return { valid: false, age: null, reason: 'invalid_date' };
  }

  isoInput.value = val;
  if (selectedDate) {
    selectedDate.textContent = `Selected date: ${formatIdDate(val)}`;
    selectedDate.classList.remove('hidden');
  }

  if (birthDate > today) {
    const msg = 'Date of birth cannot be in the future.';
    if (birthdateError) {
      birthdateError.textContent = msg;
      birthdateError.classList.remove('hidden');
    }
    birthdateWrapper?.classList.add('has-error');
    birthdateWrapper?.classList.remove('has-success');
    ageWrapper?.classList.add('has-error');
    ageWrapper?.classList.remove('has-success');
    if (ageError) {
      ageError.classList.add('hidden');
    }
    ageInput.value = 'Future Date';
    return { valid: false, age: null, reason: 'future' };
  }

  let age = today.getFullYear() - birthDate.getFullYear();
  const monthDiff = today.getMonth() - birthDate.getMonth();
  const dayDiff = today.getDate() - birthDate.getDate();
  if (monthDiff < 0 || (monthDiff === 0 && dayDiff < 0)) {
    age--;
  }

  if (age < 0) {
    const msg = 'Date of birth cannot be in the future.';
    if (birthdateError) {
      birthdateError.textContent = msg;
      birthdateError.classList.remove('hidden');
    }
    birthdateWrapper?.classList.add('has-error');
    ageInput.value = 'Invalid Date';
    return { valid: false, age: null, reason: 'future' };
  }

  if (age > 130) {
    const msg = 'Please enter a valid date of birth.';
    if (birthdateError) {
      birthdateError.textContent = msg;
      birthdateError.classList.remove('hidden');
    }
    birthdateWrapper?.classList.add('has-error');
    ageInput.value = `${age} yrs`;
    return { valid: false, age: age, reason: 'unrealistic' };
  }

  if (age < 18) {
    ageInput.value = `${age} ${age === 1 ? 'yr' : 'yrs'} old (Under 18)`;
    birthdateWrapper?.classList.add('has-error');
    birthdateWrapper?.classList.remove('has-success');
    ageWrapper?.classList.add('has-error');
    ageWrapper?.classList.remove('has-success');
    if (birthdateError) {
      birthdateError.classList.add('hidden');
    }
    if (ageError) {
      ageError.textContent = `You must be at least 18 years old to create an account. (Current age: ${age})`;
      ageError.classList.remove('hidden');
    }
    return { valid: false, age: age, isUnderage: true, reason: 'underage' };
  }

  // Valid adult age (>= 18)
  ageInput.value = `${age} years old`;
  birthdateWrapper?.classList.remove('has-error');
  birthdateWrapper?.classList.add('has-success');
  ageWrapper?.classList.remove('has-error');
  ageWrapper?.classList.add('has-success');
  if (birthdateError) {
    birthdateError.classList.add('hidden');
    birthdateError.textContent = '';
  }
  if (ageError) {
    ageError.classList.add('hidden');
    ageError.textContent = '';
  }

  return { valid: true, age: age, isUnderage: false };
}

function initSignupBirthdateAndAge() {
  const birthdateInput = document.getElementById('register-birthdate');
  if (!(birthdateInput instanceof HTMLInputElement)) return;

  birthdateInput.addEventListener('input', () => {
    validateAndCalculateAge(false);
  });

  birthdateInput.addEventListener('change', () => {
    validateAndCalculateAge(false);
  });

  birthdateInput.addEventListener('blur', () => {
    if (birthdateInput.value.trim() !== '') {
      validateAndCalculateAge(true);
    }
  });
}

// Sign-up steps: 1 = your details, 'id' = valid ID, 2 = security.
function validateSignupDetails() {
  // Validate required fields in Step 1
  const firstName = document.getElementById('register-first-name');
  const lastName = document.getElementById('register-last-name');
  const phone = document.getElementById('register-phone');
  const phoneError = document.getElementById('register-phone-error');
  const email = document.getElementById('register-email');

  if (firstName instanceof HTMLInputElement && !firstName.value.trim()) {
    showToast('Please enter your first name.', 'warning');
    firstName.focus();
    return false;
  }

  if (lastName instanceof HTMLInputElement && !lastName.value.trim()) {
    showToast('Please enter your last name.', 'warning');
    lastName.focus();
    return false;
  }

  // Validate Date of Birth and Age (must be >= 18)
  const birthCheck = validateAndCalculateAge(true);
  const birthdateInput = document.getElementById('register-birthdate');
  if (!birthCheck.valid) {
    if (birthCheck.reason === 'empty') {
      showToast('Please enter your date of birth.', 'warning');
    } else if (birthCheck.isUnderage) {
      showToast(`You must be at least 18 years old to register. (Calculated age: ${birthCheck.age})`, 'error');
    } else if (birthCheck.reason === 'future') {
      showToast('Date of birth cannot be in the future.', 'warning');
    } else {
      showToast('Please enter a valid date of birth.', 'warning');
    }
    birthdateInput?.focus();
    return false;
  }

  const phoneVal = phone instanceof HTMLInputElement ? phone.value.trim() : '';
  const phonePattern = /^(?:09\d{9}|(?:\+?63)9\d{9})$/;
  if (!phonePattern.test(phoneVal)) {
    phoneError?.classList.remove('hidden');
    showToast('Please enter a valid Philippine mobile number (e.g. 09XXXXXXXXX).', 'warning');
    phone?.focus();
    return false;
  } else {
    phoneError?.classList.add('hidden');
  }

  if (email instanceof HTMLInputElement && email.value.trim() !== '') {
    if (!email.checkValidity()) {
      showToast('Please enter a valid email address or leave it blank.', 'warning');
      email.focus();
      return false;
    }
  }

  return true;
}

function setSignupStep(step) {
  const panels = {
    1: document.getElementById('signup-step-1'),
    id: document.getElementById('signup-step-id'),
    2: document.getElementById('signup-step-2'),
  };
  if (!panels[1] || !panels.id || !panels[2]) return;
  const terms = document.getElementById('register-terms');
  if ((step === 'id' || step === 2) && (!(terms instanceof HTMLInputElement) || !terms.checked)) {
    showToast('Please agree to the Terms and Conditions to continue.', 'warning');
    terms?.focus();
    return;
  }
  if ((step === 'id' || step === 2) && !validateSignupDetails()) return;
  if (step === 2 && !signupIdReady()) {
    if (currentSignupStep !== 'id') showToast('Please verify your valid ID first.', 'warning');
    step = 'id';
  }
  currentSignupStep = step;
  Object.entries(panels).forEach(([key, panel]) => panel.classList.toggle('hidden', key !== String(step)));
  const order = [1, 'id', 2];
  const index = order.indexOf(step);
  [document.getElementById('step-node-1'), document.getElementById('step-node-id'), document.getElementById('step-node-2')]
    .forEach((node, nodeIndex) => {
      node?.classList.toggle('is-active', nodeIndex <= index);
      node?.classList.toggle('is-completed', nodeIndex < index);
      node?.setAttribute('aria-current', nodeIndex === index ? 'step' : 'false');
    });
  [document.getElementById('step-track-fill'), document.getElementById('step-track-fill-id')].forEach((track, trackIndex) => {
    if (track) track.style.width = trackIndex < index ? '100%' : '0%';
  });
  if (step === 'id') refreshSignupIdCheck();
}

function handleSignupBack() {
  if (currentSignupStep === 2) {
    setSignupStep('id');
  } else if (currentSignupStep === 'id') {
    setSignupStep(1);
  } else {
    toggleAuth('login');
  }
}

let otpCooldownTimer = null;
let otpCooldownPhone = '';
let signupAttemptId = '';
const OTP_COOLDOWN_STORAGE_PREFIX = 'aics_otp_attempt_cooldown:';

function createSignupAttemptId() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function initializeSignupAttempt(attemptId = '') {
  if (otpCooldownTimer) {
    clearInterval(otpCooldownTimer);
    otpCooldownTimer = null;
  }
  otpCooldownPhone = '';
  signupAttemptId = attemptId || createSignupAttemptId();
  const attemptInput = document.getElementById('signup-attempt-id');
  if (attemptInput instanceof HTMLInputElement) {
    attemptInput.value = signupAttemptId;
  }
  return signupAttemptId;
}

function normalizeOtpPhone(value) {
  const digits = String(value || '').replace(/\D+/g, '');
  if (/^09\d{9}$/.test(digits)) return digits;
  if (/^639\d{9}$/.test(digits)) return `0${digits.slice(2)}`;
  return '';
}

function otpCooldownStorageKey(attemptId, phone) {
  const canonicalPhone = normalizeOtpPhone(phone);
  return attemptId && canonicalPhone ? `${OTP_COOLDOWN_STORAGE_PREFIX}${attemptId}:${canonicalPhone}` : '';
}

function setOtpCooldownForAttempt(attemptId, phone, expiryTimestamp) {
  const storageKey = otpCooldownStorageKey(attemptId, phone);
  if (!storageKey) return;
  sessionStorage.setItem(storageKey, String(expiryTimestamp));
}

function getOtpCooldownForAttempt(attemptId, phone) {
  const storageKey = otpCooldownStorageKey(attemptId, phone);
  if (!storageKey) return 0;
  const expiry = Number.parseInt(sessionStorage.getItem(storageKey) || '', 10);
  return Number.isFinite(expiry) ? expiry : 0;
}

function clearOtpCooldownForAttempt(attemptId, phone) {
  const storageKey = otpCooldownStorageKey(attemptId, phone);
  if (storageKey) sessionStorage.removeItem(storageKey);
}

function formatCooldownTime(totalSeconds) {
  const mins = Math.floor(totalSeconds / 60);
  const secs = totalSeconds % 60;
  return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function updateOtpButtonCooldown(remainingSeconds, readyLabel = 'RESEND OTP') {
  const btn = document.getElementById('btn-send-otp');
  const btnText = document.getElementById('otp-btn-text');
  const btnIcon = document.getElementById('otp-btn-icon');
  if (!btn) return;

  if (remainingSeconds > 0) {
    btn.disabled = true;
    btn.classList.add('is-cooldown');
    if (btnIcon) btnIcon.className = 'fas fa-clock';
    if (btnText) btnText.textContent = `Resend in ${formatCooldownTime(remainingSeconds)}`;
  } else {
    btn.disabled = false;
    btn.classList.remove('is-cooldown');
    if (btnIcon) btnIcon.className = readyLabel === 'SEND OTP' ? 'fas fa-paper-plane' : 'fas fa-redo';
    if (btnText) btnText.textContent = readyLabel;
  }
}

function startOtpCooldown(attemptId, phone, expiryTimestamp) {
  if (otpCooldownTimer) {
    clearInterval(otpCooldownTimer);
    otpCooldownTimer = null;
  }
  otpCooldownPhone = normalizeOtpPhone(phone);

  const tick = () => {
    const phoneInput = document.getElementById('register-phone');
    const currentPhone = phoneInput instanceof HTMLInputElement ? normalizeOtpPhone(phoneInput.value) : '';
    if (signupAttemptId !== attemptId || currentPhone !== otpCooldownPhone) {
      clearInterval(otpCooldownTimer);
      otpCooldownTimer = null;
      otpCooldownPhone = '';
      syncOtpCooldownForCurrentPhone();
      return;
    }

    const now = Date.now();
    const remaining = Math.max(0, Math.ceil((expiryTimestamp - now) / 1000));
    updateOtpButtonCooldown(remaining);

    if (remaining <= 0) {
      clearInterval(otpCooldownTimer);
      otpCooldownTimer = null;
      clearOtpCooldownForAttempt(attemptId, otpCooldownPhone);
      otpCooldownPhone = '';
      const note = document.getElementById('register-otp-note');
      if (note && note.dataset.expiredMessage !== 'true') {
        note.innerHTML = '<span class="otp-expired-note"><i class="fas fa-info-circle"></i> OTP expired. You can request a new code.</span>';
        note.dataset.expiredMessage = 'true';
      }
    }
  };

  tick();
  otpCooldownTimer = setInterval(tick, 1000);
}

function syncOtpCooldownForCurrentPhone() {
  if (otpCooldownTimer) {
    clearInterval(otpCooldownTimer);
    otpCooldownTimer = null;
  }
  otpCooldownPhone = '';

  const phoneInput = document.getElementById('register-phone');
  const phoneVal = phoneInput instanceof HTMLInputElement ? phoneInput.value.trim() : '';
  const canonicalPhone = normalizeOtpPhone(phoneVal);
  const note = document.getElementById('register-otp-note');
  const expiry = canonicalPhone ? getOtpCooldownForAttempt(signupAttemptId, canonicalPhone) : 0;

  if (expiry > Date.now()) {
    if (note) {
      note.innerHTML = `<span class="otp-success-note"><i class="fas fa-check-circle"></i> Verification code sent to your phone (${phoneVal}). Please check your SMS.</span>`;
    }
    startOtpCooldown(signupAttemptId, canonicalPhone, expiry);
    return;
  }

  if (expiry > 0) {
    clearOtpCooldownForAttempt(signupAttemptId, canonicalPhone);
  }
  updateOtpButtonCooldown(0, 'SEND OTP');
  if (note) {
    note.innerHTML = 'Click "SEND OTP" to receive a verification code on your mobile number.';
    delete note.dataset.expiredMessage;
  }
}

function checkExistingOtpCooldown() {
  localStorage.removeItem('aics_last_otp_code');
  localStorage.removeItem('aics_otp_cooldown_expiry');
  const otpInput = document.getElementById('register-otp');
  if (otpInput instanceof HTMLInputElement) otpInput.value = '';
  syncOtpCooldownForCurrentPhone();
}

async function handleSendOtp() {
  const btn = document.getElementById('btn-send-otp');
  const btnText = document.getElementById('otp-btn-text');
  const btnIcon = document.getElementById('otp-btn-icon');
  const phoneInput = document.getElementById('register-phone');
  const note = document.getElementById('register-otp-note');
  const otpInput = document.getElementById('register-otp');

  if (btn && btn.disabled) return;

  const phoneVal = phoneInput instanceof HTMLInputElement ? phoneInput.value.trim() : '';
  const phonePattern = /^(?:09\d{9}|(?:\+?63)9\d{9})$/;
  if (!phonePattern.test(phoneVal)) {
    if (note) {
      note.innerHTML = '<span class="otp-error-note"><i class="fas fa-exclamation-triangle"></i> Please provide a valid mobile number in Step 1 first.</span>';
    }
    setSignupStep(1);
    phoneInput?.focus();
    return;
  }

  // Set loading state on button
  if (btn) btn.disabled = true;
  if (btnIcon) btnIcon.className = 'fas fa-spinner fa-spin';
  if (btnText) btnText.textContent = 'SENDING...';
  if (note) {
    note.innerHTML = '<span class="otp-sending-note"><i class="fas fa-paper-plane fa-fade"></i> Sending verification code to ' + phoneVal + '...</span>';
  }

  try {
    const formData = new FormData();
    formData.append('contact_number', phoneVal);
    formData.append('signup_attempt_id', signupAttemptId);

    const response = await fetch('php_files/auth-send-otp.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });

    const result = await response.json().catch(() => null);

    if (response.ok && result && result.success) {
      const cooldownSec = result.cooldown_seconds || 300; // 5 minutes = 300 seconds
      const expiry = Date.now() + cooldownSec * 1000;
      setOtpCooldownForAttempt(signupAttemptId, phoneVal, expiry);

      if (note) {
        note.innerHTML = `<span class="otp-success-note"><i class="fas fa-check-circle"></i> Verification code sent to your phone (<strong>${phoneVal}</strong>). Please check your SMS.</span>`;
        delete note.dataset.expiredMessage;
      }

      showToast(`Verification code sent to ${phoneVal}. Please check your SMS.`, 'success', 'OTP Sent');

      // Do NOT auto-populate the input field: user must read the code on their cellphone and type it in
      if (otpInput instanceof HTMLInputElement) {
        otpInput.value = '';
        otpInput.focus();
      }

      startOtpCooldown(signupAttemptId, phoneVal, expiry);
    } else if (result && result.error === 'cooldown_active') {
      const remainingSec = result.remaining_seconds || 300;
      const expiry = Date.now() + remainingSec * 1000;
      setOtpCooldownForAttempt(signupAttemptId, phoneVal, expiry);
      if (note) {
        note.innerHTML = `<span class="otp-warning-note"><i class="fas fa-hourglass-half"></i> Please wait for the 5-minute cooldown before requesting a new code.</span>`;
      }
      showToast('Please wait for the 5-minute cooldown before requesting a new code.', 'warning', 'Cooldown Active');
      startOtpCooldown(signupAttemptId, phoneVal, expiry);
    } else {
      throw new Error((result && result.message) || 'Failed to send SMS.');
    }
  } catch (err) {
    if (btn) btn.disabled = false;
    if (btnIcon) btnIcon.className = 'fas fa-paper-plane';
    if (btnText) btnText.textContent = 'SEND OTP';
    if (note) {
      note.innerHTML = `<span class="otp-error-note"><i class="fas fa-exclamation-triangle"></i> ${err.message || 'Failed to send SMS. Please try again.'}</span>`;
    }
    showToast(err.message || 'Failed to send SMS. Please try again.', 'error');
  }
}

// ---------------------------------------------------------------- Valid ID step
// Step 2 of sign-up. AICS AI reads the front and back of a valid ID and the
// server compares it with the details from step 1; the server enforces the
// same rules again when the account is created.
let signupIdScan = null;
let signupIdCheckVersion = 0;

function signupIdMatches(scan) {
  // Automatic: the ID must be read successfully and match the details from step 1.
  if (!scan || !scan.can_continue || scan.decision !== 'ok') return false;
  return Boolean(scan.name_match && scan.name_match.match && scan.birthdate_match);
}

function signupIdReady() {
  return signupIdMatches(signupIdScan);
}

function titleCaseName(value) {
  return String(value || '').toLowerCase().replace(/(^|[\s'-])([a-zñ])/g, (match, separator, letter) => separator + letter.toUpperCase());
}

function formatIdDate(iso) {
  const parsed = /^\d{4}-\d{2}-\d{2}$/.test(String(iso || '')) ? new Date(`${iso}T00:00:00`) : null;
  return parsed && !Number.isNaN(parsed.getTime())
    ? parsed.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
    : '';
}

function idFullName(scan) {
  return titleCaseName([scan?.given_names, scan?.middle_name, scan?.last_name].filter(Boolean).join(' '));
}

function signupDetailsPayload(body) {
  [['first_name', 'register-first-name'], ['middle_name', 'register-middle-name'], ['last_name', 'register-last-name'],
    ['date_of_birth', 'register-birthdate-iso']].forEach(([field, id]) => {
    const input = document.getElementById(id);
    body.append(field, input instanceof HTMLInputElement ? input.value.trim() : '');
  });
  return body;
}

function signupDetailsSnapshot() {
  return JSON.stringify([...signupDetailsPayload(new FormData()).entries()]);
}

function idScanEndpoint() {
  const form = document.getElementById('register-access-form');
  return new URL('signup-id-scan.php', form instanceof HTMLFormElement ? form.action : window.location.href).toString();
}

function makeElement(tag, className, text) {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (text !== undefined) element.textContent = text;
  return element;
}

function renderSignupIdResult(scan, isError) {
  const box = document.getElementById('signup-id-result');
  const continueButton = document.getElementById('btn-id-continue');
  const scanButton = document.getElementById('btn-scan-id');
  if (!(box instanceof HTMLElement)) return;
  box.replaceChildren();
  const ready = !isError && signupIdMatches(scan);
  const mismatch = !isError && scan.decision === 'ok' && !ready;
  box.className = `signup-id-result ${isError ? 'is-error' : (mismatch ? 'is-warning' : (scan.decision === 'ok' ? 'is-ok' : 'is-pending'))}`;

  const head = makeElement('div', 'signup-id-result-head');
  const icon = makeElement('i', `fas ${isError ? 'fa-circle-xmark' : (mismatch ? 'fa-triangle-exclamation' : (scan.decision === 'ok' ? 'fa-circle-check' : 'fa-clock'))}`);
  icon.setAttribute('aria-hidden', 'true');
  const title = isError ? 'Please try again'
    : (mismatch ? 'Your details do not match this ID'
      : 'ID verified');
  head.append(icon, makeElement('strong', '', title));
  box.appendChild(head);

  if (isError || scan.decision !== 'ok') {
    box.appendChild(makeElement('p', '', String(scan.message || '')));
  } else {
    const details = makeElement('dl', 'signup-id-facts');
    const enteredBirthDate = document.getElementById('register-birthdate-iso');
    const facts = [['ID', scan.id_type_label], ['Name on ID', idFullName(scan)], ['Birth date on ID', formatIdDate(scan.birth_date) || 'Not shown']];
    if (mismatch && !scan.birthdate_match && enteredBirthDate instanceof HTMLInputElement) {
      facts.push(['Birth date you entered', formatIdDate(enteredBirthDate.value) || 'Not entered']);
    }
    facts
      .forEach(([term, value]) => {
        const row = makeElement('div');
        row.append(makeElement('dt', '', term), makeElement('dd', '', String(value || '')));
        details.appendChild(row);
      });
    box.appendChild(details);
    if (mismatch) {
      const reasons = makeElement('ul', 'signup-id-reasons');
      if (scan.name_match && !scan.name_match.match) reasons.appendChild(makeElement('li', '', scan.name_match.reason));
      if (!scan.birthdate_match) reasons.appendChild(makeElement('li', '', `Your birth date must match your ID (${formatIdDate(scan.birth_date)}).`));
      box.appendChild(reasons);
      const actions = makeElement('div', 'signup-id-fix');
      const useId = makeElement('button', 'signup-id-fix-primary', 'Use the details on my ID');
      useId.type = 'button';
      useId.addEventListener('click', useIdDetails);
      const edit = makeElement('button', 'signup-id-fix-secondary', 'Edit my details');
      edit.type = 'button';
      edit.addEventListener('click', () => setSignupStep(1));
      actions.append(useId, edit);
      box.appendChild(actions);
    } else {
      box.appendChild(makeElement('p', 'signup-id-match', 'Your name and birth date match this ID.'));
    }
  }
  box.classList.remove('hidden');
  continueButton?.classList.toggle('hidden', !ready);
  if (scanButton instanceof HTMLButtonElement) {
    scanButton.classList.toggle('btn-step-secondary', ready);
    const label = scanButton.querySelector('span');
    if (label) label.textContent = signupIdScan ? 'SCAN AGAIN' : 'SCAN MY ID';
  }
}

async function refreshSignupIdCheck() {
  if (!signupIdScan || signupIdScan.decision !== 'ok' || !signupAttemptId) return false;
  const version = ++signupIdCheckVersion;
  const box = document.getElementById('signup-id-result');
  if (box instanceof HTMLElement) {
    box.className = 'signup-id-result is-loading';
    box.replaceChildren(makeElement('p', '', 'Comparing your current details with your ID…'));
  }
  document.getElementById('btn-id-continue')?.classList.add('hidden');
  try {
    const body = signupDetailsPayload(new FormData());
    const details = JSON.stringify([...body.entries()]);
    body.append('signup_attempt_id', signupAttemptId);
    body.append('action', 'check');
    const response = await fetch(idScanEndpoint(), { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const result = await response.json().catch(() => ({}));
    if (version !== signupIdCheckVersion) return false;
    if (response.ok && result.ok) {
      if (details !== signupDetailsSnapshot()) return refreshSignupIdCheck();
      signupIdScan = result;
      renderSignupIdResult(result, !result.can_continue);
      return true;
    }
  } catch (error) {
    // A comparison error must not leave a stale match visible.
  }
  if (version === signupIdCheckVersion) {
    renderSignupIdResult({ message: 'We could not compare your current details. Go back and return to retry.', decision: 'unavailable' }, true);
  }
  return false;
}

function setSignupBirthdateFromIso(iso) {
  const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
  if (!parts) return;
  const input = document.getElementById('register-birthdate');
  if (input instanceof HTMLInputElement) input.value = iso;
  validateAndCalculateAge(false);
}

function useIdDetails() {
  if (!signupIdScan || signupIdScan.decision !== 'ok') return;
  [['register-first-name', signupIdScan.given_names], ['register-middle-name', signupIdScan.middle_name],
    ['register-last-name', signupIdScan.last_name]].forEach(([id, value]) => {
    const input = document.getElementById(id);
    if (input instanceof HTMLInputElement) input.value = value ? titleCaseName(value) : '';
  });
  if (signupIdScan.birth_date) setSignupBirthdateFromIso(signupIdScan.birth_date);
  showToast('Your details were updated to match your ID.', 'success');
  refreshSignupIdCheck();
}

function resetSignupId() {
  signupIdCheckVersion++;
  signupIdScan = null;
  ['front', 'back'].forEach((side) => {
    const input = document.getElementById(`id-${side}-input`);
    if (input instanceof HTMLInputElement) input.value = '';
    const thumb = document.getElementById(`id-${side}-thumb`);
    thumb?.classList.add('hidden');
    thumb?.removeAttribute('src');
    document.getElementById(`id-${side}-tile`)?.classList.remove('has-photo');
  });
  document.getElementById('signup-id-result')?.classList.add('hidden');
  document.getElementById('btn-id-continue')?.classList.add('hidden');
  const scanButton = document.getElementById('btn-scan-id');
  if (scanButton instanceof HTMLButtonElement) {
    scanButton.disabled = true;
    scanButton.classList.remove('btn-step-secondary');
    const label = scanButton.querySelector('span');
    if (label) label.textContent = 'SCAN MY ID';
  }
  currentSignupStep = 1;
}

function initSignupIdStep() {
  const scanButton = document.getElementById('btn-scan-id');
  const continueButton = document.getElementById('btn-id-continue');
  const front = document.getElementById('id-front-input');
  const back = document.getElementById('id-back-input');
  if (!(scanButton instanceof HTMLButtonElement) || !(front instanceof HTMLInputElement) || !(back instanceof HTMLInputElement)) return;

  const idleScanButtonContent = scanButton.innerHTML;
  const syncButton = () => { scanButton.disabled = !(front.files?.length && back.files?.length); };
  [['front', front], ['back', back]].forEach(([side, input]) => {
    input.addEventListener('change', () => {
      signupIdCheckVersion++;
      const file = input.files && input.files[0];
      const thumb = document.getElementById(`id-${side}-thumb`);
      if (file && thumb instanceof HTMLImageElement) {
        thumb.src = URL.createObjectURL(file);
        thumb.classList.remove('hidden');
        document.getElementById(`id-${side}-tile`)?.classList.add('has-photo');
      }
      signupIdScan = null;
      document.getElementById('signup-id-result')?.classList.add('hidden');
      continueButton?.classList.add('hidden');
      scanButton.classList.remove('btn-step-secondary');
      scanButton.innerHTML = idleScanButtonContent;
      syncButton();
    });
  });

  continueButton?.addEventListener('click', () => setSignupStep(2));

  const nodeId = document.getElementById('step-node-id');
  nodeId?.addEventListener('click', () => setSignupStep('id'));
  nodeId?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      setSignupStep('id');
    }
  });

  scanButton.addEventListener('click', async () => {
    if (!(front.files?.length && back.files?.length)) return;
    const version = ++signupIdCheckVersion;
    const original = scanButton.innerHTML;
    scanButton.disabled = true;
    continueButton?.classList.add('hidden');
    scanButton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span>READING YOUR ID…</span>';
    const box = document.getElementById('signup-id-result');
    if (box instanceof HTMLElement) {
      box.className = 'signup-id-result is-loading';
      box.replaceChildren(makeElement('p', '', 'AICS AI is reading your ID. This takes about 10 seconds.'));
    }
    try {
      const body = signupDetailsPayload(new FormData());
      const details = JSON.stringify([...body.entries()]);
      body.append('signup_attempt_id', signupAttemptId);
      body.append('id_front', front.files[0]);
      body.append('id_back', back.files[0]);
      const response = await fetch(idScanEndpoint(), { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const result = await response.json().catch(() => ({}));
      if (version !== signupIdCheckVersion) return;
      scanButton.innerHTML = original;
      if (!response.ok || !result.ok) {
        signupIdScan = null;
        renderSignupIdResult({ message: result.message || 'We could not check your ID. Please try again.' }, true);
        return;
      }
      signupIdScan = result;
      if (details !== signupDetailsSnapshot() && result.decision === 'ok') {
        await refreshSignupIdCheck();
      } else {
        renderSignupIdResult(result, !result.can_continue);
      }
    } catch (error) {
      if (version !== signupIdCheckVersion) return;
      scanButton.innerHTML = original;
      signupIdScan = null;
      renderSignupIdResult({ message: 'Network error. Please check your connection and try again.' }, true);
    } finally {
      syncButton();
    }
  });
}

function initSignupTermsControls() {
  const btnNext = document.getElementById('btn-goto-step-2');
  const terms = document.getElementById('register-terms');
  const modalTerms = document.getElementById('register-terms-modal');
  const form = document.getElementById('register-access-form');
  if (btnNext instanceof HTMLButtonElement && terms instanceof HTMLInputElement && modalTerms instanceof HTMLInputElement) {
    const syncNext = () => {
      modalTerms.checked = terms.checked;
      btnNext.disabled = !terms.checked;
    };
    const syncFromModal = () => {
      terms.checked = modalTerms.checked;
      syncNext();
    };
    terms.addEventListener('input', syncNext);
    terms.addEventListener('change', syncNext);
    modalTerms.addEventListener('input', syncFromModal);
    modalTerms.addEventListener('change', syncFromModal);
    form?.addEventListener('reset', () => {
      modalTerms.checked = false;
      btnNext.disabled = true;
    });
    window.addEventListener('pageshow', syncNext);
    syncNext();
    btnNext.addEventListener('click', () => setSignupStep('id'));
  }
}

function initSignupTermsModal() {
  const link = document.getElementById('signup-terms-link');
  const modal = document.getElementById('signup-terms-modal');
  const card = modal?.querySelector('.signup-terms-card');
  if (!(link instanceof HTMLAnchorElement) || !(modal instanceof HTMLElement) || !(card instanceof HTMLElement)) return;

  const close = () => {
    if (modal.classList.contains('hidden')) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('popup-open');
    link.focus();
  };

  link.addEventListener('click', (event) => {
    event.preventDefault();
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('popup-open');
    card.focus();
  });
  modal.querySelectorAll('[data-terms-close]').forEach((element) => {
    element.addEventListener('click', close);
  });
  modal.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
    } else if (event.key === 'Tab') {
      const focusable = [...modal.querySelectorAll('button, a[href], [tabindex]:not([tabindex="-1"])')]
        .filter((element) => element instanceof HTMLElement && !element.hidden);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && (document.activeElement === first || document.activeElement === card)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
}

function initTwoStepSignup() {
  initializeSignupAttempt();
  initSignupIdStep();

  const backBtn = document.getElementById('signup-back-btn');
  if (backBtn) {
    backBtn.addEventListener('click', handleSignupBack);
  }

  const node1 = document.getElementById('step-node-1');
  if (node1) {
    node1.addEventListener('click', () => setSignupStep(1));
    node1.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        setSignupStep(1);
      }
    });
  }

  const node2 = document.getElementById('step-node-2');
  if (node2) {
    node2.addEventListener('click', () => setSignupStep(2));
    node2.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        setSignupStep(2);
      }
    });
  }

  const phoneInput = document.getElementById('register-phone');
  const phoneError = document.getElementById('register-phone-error');
  if (phoneInput instanceof HTMLInputElement && phoneError) {
    phoneInput.addEventListener('input', () => {
      const val = phoneInput.value.trim();
      const pattern = /^(?:09\d{9}|(?:\+?63)9\d{9})$/;
      if (pattern.test(val) || val === '') {
        phoneError.classList.add('hidden');
      }
      syncOtpCooldownForCurrentPhone();
    });
  }

  const btnSendOtp = document.getElementById('btn-send-otp');
  if (btnSendOtp) {
    btnSendOtp.addEventListener('click', handleSendOtp);
  }

  checkExistingOtpCooldown();
}

function toggleAuth(view) {
  const loginForm = document.getElementById('login-form');
  const registerForm = document.getElementById('register-form');
  const stage = document.querySelector('.auth-stage');

  if (!loginForm || !registerForm) {
    return;
  }

  if (view === 'register') {
    loginForm.classList.add('hidden');
    registerForm.classList.remove('hidden');
    setSignupStep(currentSignupStep || 1);
    if (stage instanceof HTMLElement) {
      stage.classList.remove('is-login-view');
      stage.classList.add('is-register-view');
    }
  } else {
    registerForm.classList.add('hidden');
    loginForm.classList.remove('hidden');
    if (stage instanceof HTMLElement) {
      stage.classList.add('is-login-view');
      stage.classList.remove('is-register-view');
    }
  }
}

function normalizePhpRoutes() {
  if (window.location.protocol !== 'file:') {
    return;
  }

  const loginSubmit = document.getElementById('login-access-form');
  const registerSubmit = document.getElementById('register-access-form');
  const normalizedPath = window.location.pathname.replace(/\\/g, '/');
  const projectMatch = normalizedPath.match(/\/([^/]+)\/AICS\/Applicants\/login\.php$/i);
  const projectFolder = projectMatch ? projectMatch[1] : 'SIA102';
  const baseUrl = `http://localhost/${projectFolder}/AICS/Applicants`;

  if (loginSubmit) {
    loginSubmit.action = `${baseUrl}/php_files/auth-login.php`;
  }

  if (registerSubmit) {
    registerSubmit.action = `${baseUrl}/php_files/auth-signup.php`;
  }
}

function closeAuthPopup() {
  const modal = document.getElementById('auth-popup-modal');
  if (!(modal instanceof HTMLElement)) {
    return;
  }

  modal.classList.add('hidden');
  modal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('popup-open');
}

function showAuthPopup(title, message) {
  const modal = document.getElementById('auth-popup-modal');
  const titleNode = document.getElementById('auth-popup-title');
  const messageNode = document.getElementById('auth-popup-message');
  const okButton = document.getElementById('auth-popup-ok');

  if (
    !(modal instanceof HTMLElement) ||
    !(titleNode instanceof HTMLElement) ||
    !(messageNode instanceof HTMLElement) ||
    !(okButton instanceof HTMLButtonElement)
  ) {
    return;
  }

  titleNode.textContent = String(title || 'Account Access Notice');
  messageNode.textContent = String(message || '');

  modal.classList.remove('hidden');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('popup-open');
  okButton.focus();
}

function initAuthPopupEvents() {
  const modal = document.getElementById('auth-popup-modal');
  if (!(modal instanceof HTMLElement)) {
    return;
  }

  const okButton = document.getElementById('auth-popup-ok');
  if (okButton instanceof HTMLButtonElement) {
    okButton.addEventListener('click', closeAuthPopup);
  }

  modal.querySelectorAll('[data-popup-close]').forEach((element) => {
    if (!(element instanceof HTMLElement)) return;
    element.addEventListener('click', closeAuthPopup);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
      closeAuthPopup();
    }
  });
}

function renderAuthMessage() {
  const statusBox = document.getElementById('auth-status');
  if (statusBox) {
    statusBox.classList.add('hidden');
  }

  const params = new URLSearchParams(window.location.search);
  const authCode = params.get('auth');
  const requestedView = params.get('view');

  if (requestedView === 'register') {
    toggleAuth('register');
    const requestedStep = params.get('step');
    if (requestedStep === '2') {
      setSignupStep(2);
    }
  }

  if (!authCode) {
    return;
  }

  const messageMap = getAuthMessageMap();
  const payload = messageMap[authCode];
  if (payload) {
    if (authCode === 'account_banned') {
      window.setTimeout(() => {
        showAuthPopup('Account Banned', payload.text);
      }, 120);
    } else {
      showToast(payload.text, payload.type);
    }
  }
}

function clearAuthParams() {
  if (!window.history.replaceState) {
    return;
  }

  const url = new URL(window.location.href);
  if (!url.searchParams.has('auth') && !url.searchParams.has('view') && !url.searchParams.has('step')) {
    return;
  }

  url.searchParams.delete('auth');
  url.searchParams.delete('view');
  url.searchParams.delete('step');
  const cleanPath = url.pathname + (url.search ? url.search : '');
  window.history.replaceState({}, '', cleanPath);
}

function initPasswordToggles() {
  document.querySelectorAll('[data-password-toggle]').forEach((toggleButton) => {
    if (!(toggleButton instanceof HTMLButtonElement)) {
      return;
    }

    const targetId = String(toggleButton.getAttribute('data-target') || '').trim();
    if (targetId === '') {
      return;
    }

    const targetInput = document.getElementById(targetId);
    const icon = toggleButton.querySelector('i');
    if (!(targetInput instanceof HTMLInputElement) || !(icon instanceof HTMLElement)) {
      return;
    }

    toggleButton.addEventListener('click', () => {
      const shouldShow = targetInput.type === 'password';
      targetInput.type = shouldShow ? 'text' : 'password';
      targetInput.setAttribute('data-password-visible', shouldShow ? 'true' : 'false');
      toggleButton.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
      toggleButton.setAttribute(
        'aria-label',
        shouldShow ? 'Hide password' : (targetId.includes('confirm') ? 'Show confirm password' : 'Show password')
      );
      icon.classList.toggle('fa-eye', !shouldShow);
      icon.classList.toggle('fa-eye-slash', shouldShow);
    });
  });
}

function initSignupValidation() {
  const form = document.getElementById('register-access-form');
  const password = document.getElementById('register-password');
  const confirmPassword = document.getElementById('register-confirm-password');
  const passwordError = document.getElementById('register-password-error');
  const confirmError = document.getElementById('register-confirm-password-error');
  if (!(form instanceof HTMLFormElement) || !(password instanceof HTMLInputElement) || !(confirmPassword instanceof HTMLInputElement)) return;

  const passwordPattern = /^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])\S{8,}$/;
  const showPasswordError = () => passwordError?.classList.toggle('hidden', password.value === '' || passwordPattern.test(password.value));
  const syncConfirmation = (showMessage = false) => {
    const mismatch = confirmPassword.value !== '' && password.value !== confirmPassword.value;
    confirmPassword.setCustomValidity(mismatch ? 'Passwords do not match.' : '');
    if (showMessage || !mismatch) confirmError?.classList.toggle('hidden', !mismatch);
  };

  password.addEventListener('invalid', showPasswordError);
  password.addEventListener('blur', showPasswordError);
  password.addEventListener('input', () => {
    if (!passwordError?.classList.contains('hidden')) showPasswordError();
    syncConfirmation(false);
  });
  confirmPassword.addEventListener('input', () => syncConfirmation(false));
  confirmPassword.addEventListener('blur', () => syncConfirmation(true));
  confirmPassword.addEventListener('invalid', () => syncConfirmation(true));
}

function initSignupFormSubmit() {
  const form = document.getElementById('register-access-form');
  const submitBtn = document.getElementById('btn-submit-signup');
  if (!form || !submitBtn) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    // 1. Validate Step 1 fields
    const firstName = document.getElementById('register-first-name');
    const lastName = document.getElementById('register-last-name');
    const phone = document.getElementById('register-phone');
    const email = document.getElementById('register-email');

    const firstVal = firstName instanceof HTMLInputElement ? firstName.value.trim() : '';
    const lastVal = lastName instanceof HTMLInputElement ? lastName.value.trim() : '';
    const phoneVal = phone instanceof HTMLInputElement ? phone.value.trim() : '';
    const emailVal = email instanceof HTMLInputElement ? email.value.trim() : '';

    if (!firstVal || !lastVal) {
      showToast('Please complete your first and last name in Step 1.', 'warning');
      setSignupStep(1);
      (firstVal ? lastName : firstName)?.focus();
      return;
    }

    const birthdateInput = document.getElementById('register-birthdate');
    const birthCheck = validateAndCalculateAge(true);
    if (!birthCheck.valid) {
      if (birthCheck.isUnderage) {
        showToast(`You must be at least 18 years old to register. (Calculated age: ${birthCheck.age})`, 'error');
      } else if (birthCheck.reason === 'future') {
        showToast('Date of birth cannot be in the future.', 'warning');
      } else {
        showToast('Please provide your date of birth in Step 1. Applicants must be at least 18 years old.', 'warning');
      }
      setSignupStep(1);
      birthdateInput?.focus();
      return;
    }

    const phonePattern = /^(?:09\d{9}|(?:\+?63)9\d{9})$/;
    if (!phonePattern.test(phoneVal)) {
      showToast('Please enter a valid Philippine mobile number in Step 1 (e.g. 09XXXXXXXXX).', 'warning');
      setSignupStep(1);
      phone?.focus();
      return;
    }

    if (emailVal !== '') {
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailPattern.test(emailVal)) {
        showToast('Please enter a valid email address in Step 1 or leave it blank.', 'warning');
        setSignupStep(1);
        email?.focus();
        return;
      }
    }

    // 2. Validate Step 2 fields
    const otpInput = document.getElementById('register-otp');
    const passwordInput = document.getElementById('register-password');
    const confirmPasswordInput = document.getElementById('register-confirm-password');

    const otpVal = otpInput instanceof HTMLInputElement ? otpInput.value.trim() : '';
    const passwordVal = passwordInput instanceof HTMLInputElement ? passwordInput.value : '';
    const confirmPasswordVal = confirmPasswordInput instanceof HTMLInputElement ? confirmPasswordInput.value : '';

    if (!otpVal) {
      showToast('Please enter the OTP verification code sent to your phone.', 'warning');
      otpInput?.focus();
      return;
    }

    // Password policy check: at least 8 chars, uppercase, lowercase, number, no spaces
    const passwordPattern = /^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])\S{8,}$/;
    if (!passwordPattern.test(passwordVal)) {
      showToast(
        'Password does not meet the requirements. Use at least 8 characters with uppercase, lowercase, and a number. Spaces are not allowed.',
        'error'
      );
      passwordInput?.focus();
      return;
    }

    if (passwordVal !== confirmPasswordVal) {
      showToast('Passwords do not match.', 'error');
      confirmPasswordInput?.focus();
      return;
    }

    // 3. Submit via AJAX
    const originalBtnContent = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i><span>CREATING ACCOUNT...</span>';

    try {
      const formData = new FormData(form);
      formData.append('ajax', '1');
      formData.delete('id_front');  // already uploaded and checked in the ID step
      formData.delete('id_back');

      const response = await fetch(form.action, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json'
        }
      });

      const result = await response.json().catch(() => null);

      if (response.ok && result && result.success) {
        showToast('Account created successfully! Please log in with your credentials.', 'success', 'Registration Complete');
        form.reset();
        const ageInput = document.getElementById('register-age');
        if (ageInput instanceof HTMLInputElement) ageInput.value = '';
        document.getElementById('register-birthdate-wrapper')?.classList.remove('has-error', 'has-success');
        document.getElementById('register-age-wrapper')?.classList.remove('has-error', 'has-success');
        clearOtpCooldownForAttempt(signupAttemptId, phoneVal);
        initializeSignupAttempt();
        resetSignupId();
        setSignupStep(1);
        updateOtpButtonCooldown(0, 'SEND OTP');
        const note = document.getElementById('register-otp-note');
        if (note) {
          note.innerHTML = 'Click "SEND OTP" to receive a verification code on your mobile number.';
        }
        setTimeout(() => {
          toggleAuth('login');
          const loginId = document.getElementById('login-identifier');
          if (loginId instanceof HTMLInputElement) {
            loginId.value = phoneVal || emailVal || '';
            const loginPwd = document.getElementById('login-password');
            loginPwd?.focus();
          }
        }, 1200);
      } else {
        const authCode = (result && (result.auth || result.error)) || 'signup_failed';
        const messageMap = getAuthMessageMap();
        const msg = (messageMap[authCode] && messageMap[authCode].text) || (result && result.message) || 'Registration failed. Please check your information.';

        showToast(msg, 'error');

        // DO NOT reload or reset inputs! User input is 100% preserved.
        if (authCode === 'id_name_mismatch' && signupIdScan) {
          showToast(`Your name must match your ID: ${titleCaseName(idFullName(signupIdScan))}. Please correct it.`, 'error');
          setSignupStep(1);
          firstName?.focus();
        } else if (authCode === 'id_birthdate_mismatch' && signupIdScan) {
          showToast(`Your birth date must match your ID (${formatIdDate(signupIdScan.birth_date)}).`, 'error');
          setSignupStep(1);
          birthdateInput?.focus();
        } else if (authCode === 'id_required' || authCode === 'id_not_verified') {
          signupIdScan = null;
          setSignupStep('id');
        } else if (authCode === 'phone_exists' || authCode === 'invalid_contact') {
          setSignupStep(1);
          phone?.focus();
        } else if (authCode === 'email_exists' || authCode === 'invalid_email') {
          setSignupStep(1);
          email?.focus();
        } else if (authCode === 'underage' || authCode === 'invalid_birthdate') {
          setSignupStep(1);
          birthdateInput?.focus();
        } else if (authCode === 'invalid_name') {
          setSignupStep(1);
          firstName?.focus();
        } else if (authCode === 'invalid_otp' || authCode === 'otp_expired') {
          otpInput?.focus();
        } else if (authCode === 'weak_password' || authCode === 'password_mismatch') {
          passwordInput?.focus();
        }
      }
    } catch (err) {
      showToast('Network error or server unavailable. Please try again.', 'error');
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnContent;
    }
  });
}

function initLoginFormSubmit() {
  const form = document.getElementById('login-access-form');
  const submitBtn = form?.querySelector('button[type="submit"]');
  if (!form || !submitBtn) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const identifierInput = document.getElementById('login-identifier');
    const passwordInput = document.getElementById('login-password');

    const identifierVal = identifierInput instanceof HTMLInputElement ? identifierInput.value.trim() : '';
    const passwordVal = passwordInput instanceof HTMLInputElement ? passwordInput.value : '';

    if (!identifierVal) {
      showToast('Please enter your email or mobile number.', 'warning');
      identifierInput?.focus();
      return;
    }

    if (!passwordVal) {
      showToast('Please enter your password.', 'warning');
      passwordInput?.focus();
      return;
    }

    const originalBtnContent = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i><span>SIGNING IN...</span>';

    try {
      const formData = new FormData(form);
      formData.append('ajax', '1');

      const response = await fetch(form.action, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json'
        }
      });

      const result = await response.json().catch(() => null);

      if (response.ok && result && result.success) {
        showToast('Login successful! Redirecting...', 'success');
        const redirectUrl = result.redirect || 'dashboard.php?page=overview';
        window.location.href = redirectUrl;
      } else {
        const authCode = (result && (result.auth || result.error)) || 'login_failed';
        const messageMap = getAuthMessageMap();
        const msg = (messageMap[authCode] && messageMap[authCode].text) || (result && result.message) || 'Login failed. Please check your credentials.';

        if (authCode === 'account_banned') {
          showAuthPopup('Account Banned', msg);
        } else {
          showToast(msg, 'error');
        }

        passwordInput?.focus();
      }
    } catch (err) {
      showToast('Network error or server unavailable. Please try again.', 'error');
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnContent;
    }
  });
}

function syncInitialAuthStageLayout() {
  const stage = document.querySelector('.auth-stage');
  const loginForm = document.getElementById('login-form');
  if (!(stage instanceof HTMLElement) || !(loginForm instanceof HTMLElement)) {
    return;
  }

  const loginVisible = !loginForm.classList.contains('hidden');
  stage.classList.toggle('is-login-view', loginVisible);
  stage.classList.toggle('is-register-view', !loginVisible);
}

normalizePhpRoutes();
initSignupTermsControls();
initSignupTermsModal();
initPasswordToggles();
initSignupValidation();
initSignupBirthdateAndAge();
initTwoStepSignup();
initAuthPopupEvents();
initLoginFormSubmit();
initSignupFormSubmit();
renderAuthMessage();
syncInitialAuthStageLayout();
clearAuthParams();
