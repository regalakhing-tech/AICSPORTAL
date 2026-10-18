<?php
declare(strict_types=1);

require_once __DIR__ . '/php_files/pswdo-common.php';

pswdo_start_session();

if (isset($_SESSION['aics_account_id']) && (string) ($_SESSION['aics_role_code'] ?? '') === 'pswdo') {
    header('Location: dashboard.php?page=overview', true, 302);
    exit;
}

$authCode = trim((string) ($_GET['auth'] ?? ''));
$messages = [
    'missing_fields' => ['type' => 'error', 'text' => 'Please enter both email and password.'],
    'invalid_credentials' => ['type' => 'error', 'text' => 'Invalid PSWDO credentials.'],
    'account_banned' => ['type' => 'error', 'text' => 'Your account has been banned by the administrator. Please contact support.'],
    'account_disabled' => ['type' => 'error', 'text' => 'Your account is disabled. Please contact support.'],
    'account_restricted' => ['type' => 'error', 'text' => 'Your account is restricted. Please contact support.'],
    'account_locked' => ['type' => 'error', 'text' => 'Your account is locked. Please contact support.'],
    'account_inactive' => ['type' => 'error', 'text' => 'Your account is inactive. Please contact support.'],
    'database_unavailable' => ['type' => 'error', 'text' => 'Database is unavailable. Start MySQL in XAMPP and make sure the database name is AICSdb or aicsdb.'],
    'login_failed' => ['type' => 'error', 'text' => 'Login failed. Please try again.'],
    'logged_out' => ['type' => 'success', 'text' => 'You have been logged out from PSWDO portal.'],
];
$authMessage = $messages[$authCode] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PSWDO Portal | Secure Login</title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <style>
    :root {
      --primary: #0284c7;
      --primary-dark: #0369a1;
      --primary-50: #f0f9ff;
      --primary-100: #e0f2fe;
      --ink: #0f172a;
      --slate: #334155;
      --muted: #64748b;
      --line: #dbeafe;
      --card-bg: #ffffff;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; overflow-x: hidden; }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      color: var(--ink);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      background:
        linear-gradient(180deg, rgba(235, 245, 255, 0.44) 0%, rgba(214, 237, 253, 0.50) 100%),
        url('../asset/antique-bg.jpg') no-repeat center center fixed;
      background-size: cover;
      overflow-x: hidden;
    }

    /* Top Right Watermark: Antique - Where the Mountain Meets the Sea */
    .brand-watermark-top {
      position: absolute;
      top: 36px;
      right: 48px;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      z-index: 2;
      pointer-events: none;
      user-select: none;
    }

    .watermark-signature {
      font-family: 'Caveat', cursive;
      font-size: 56px;
      line-height: 0.95;
      color: #2b6cb0;
      letter-spacing: 0.02em;
      transform: rotate(-3deg);
      text-shadow: 0 2px 10px rgba(255, 255, 255, 0.85);
    }

    .watermark-tagline {
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 0.20em;
      text-transform: uppercase;
      color: #3182ce;
      margin-top: 4px;
      margin-right: 4px;
      text-shadow: 0 1px 6px rgba(255, 255, 255, 0.9);
    }

    /* Bottom Left Motto: SERVICE PEOPLE COMMUNITY */
    .brand-motto-bottom {
      position: absolute;
      bottom: 40px;
      left: 48px;
      display: flex;
      flex-direction: column;
      gap: 3px;
      z-index: 2;
      pointer-events: none;
      user-select: none;
    }

    .brand-motto-bottom span {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.28em;
      color: #597d9a;
      text-transform: uppercase;
    }

    .motto-divider {
      width: 48px;
      height: 1.5px;
      background: #7ba3c4;
      margin-top: 8px;
    }

    /* Flowing Curved Waves at Bottom */
    .scenic-waves-wrap {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      width: 100%;
      height: 220px;
      overflow: hidden;
      pointer-events: none;
      z-index: 2;
    }

    .wave-svg {
      position: absolute;
      bottom: 0;
      left: 0;
      width: 100%;
      height: 100%;
    }

    /* ── Main Centered Login Container ───────────────────── */
    .auth-container {
      position: relative;
      z-index: 10;
      width: 100%;
      max-width: 440px;
      padding: 20px;
    }

    .auth-card {
      background: var(--card-bg);
      border-radius: 22px;
      border: 1px solid rgba(186, 230, 253, 0.7);
      box-shadow:
        0 24px 60px -12px rgba(2, 132, 199, 0.20),
        0 6px 20px -4px rgba(15, 23, 42, 0.05);
      position: relative;
      overflow: hidden;
      padding: 34px 34px 32px;
    }

    /* Top Accent Line */
    .auth-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 5px;
      background: linear-gradient(90deg, #0284c7 0%, #38bdf8 100%);
    }

    /* Brand Logo Area */
    .card-brand {
      display: flex;
      justify-content: center;
      margin-bottom: 22px;
    }

    .brand-logo-img {
      height: 64px;
      width: auto;
      max-width: 100%;
      object-fit: contain;
      transition: transform 0.2s ease;
    }

    .brand-logo-img:hover {
      transform: scale(1.02);
    }

    /* Role Pill Badge with Horizontal Line Dividers */
    .role-divider-row {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 26px;
    }

    .divider-line {
      flex: 1;
      height: 1px;
      background: #e2e8f0;
    }

    .role-pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 5px 14px;
      border-radius: 9999px;
      background: var(--primary-50);
      border: 1px solid #bae6fd;
      color: var(--primary);
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .role-pill i {
      font-size: 12px;
    }

    /* Form Fields */
    .field-group {
      margin-bottom: 18px;
      text-align: left;
    }

    .field-group label {
      display: block;
      font-size: 13px;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 6px;
      margin-left: 2px;
    }

    .field-input-box {
      position: relative;
      display: flex;
      align-items: center;
    }

    .field-icon {
      position: absolute;
      left: 15px;
      color: #94a3b8;
      font-size: 15px;
      pointer-events: none;
      transition: color 0.18s ease;
    }

    .field-input-box input {
      width: 100%;
      height: 48px;
      padding: 0 44px 0 44px;
      border-radius: 12px;
      border: 1.5px solid #dbeafe;
      background: #f8fbff;
      font-size: 14px;
      font-family: inherit;
      color: var(--ink);
      transition: all 0.2s ease;
    }

    .field-input-box input::placeholder {
      color: #94a3b8;
      font-weight: 400;
    }

    .field-input-box input:focus {
      outline: none;
      border-color: var(--primary);
      background: #ffffff;
      box-shadow: 0 0 0 3.5px rgba(2, 132, 199, 0.14);
    }

    .field-input-box:focus-within .field-icon {
      color: var(--primary);
    }

    .password-toggle {
      position: absolute;
      right: 12px;
      width: 32px;
      height: 32px;
      border: none;
      border-radius: 8px;
      background: transparent;
      color: #94a3b8;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 14px;
      transition: color 0.15s ease, background 0.15s ease;
    }

    .password-toggle:hover {
      color: var(--primary);
      background: rgba(2, 132, 199, 0.08);
    }

    /* Submit Button */
    .login-btn {
      width: 100%;
      height: 48px;
      margin-top: 8px;
      border: none;
      border-radius: 12px;
      background: linear-gradient(180deg, #0284c7 0%, #0369a1 100%);
      color: #ffffff;
      font-size: 13.5px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      cursor: pointer;
      box-shadow: 0 8px 20px -4px rgba(2, 132, 199, 0.50);
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .login-btn:hover {
      background: linear-gradient(180deg, #0369a1 0%, #075985 100%);
      box-shadow: 0 12px 26px -4px rgba(2, 132, 199, 0.65);
      transform: translateY(-1px);
    }

    .login-btn:active {
      transform: translateY(0);
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.4);
    }

    .login-btn i {
      font-size: 13px;
      transition: transform 0.18s ease;
    }

    .login-btn:hover i {
      transform: translateX(3px);
    }

    /* Top Flash Alert */
    .top-flash {
      position: fixed;
      top: 18px;
      left: 50%;
      transform: translateX(-50%);
      width: min(92vw, 540px);
      z-index: 9999;
      padding: 12px 18px;
      border-radius: 12px;
      font-size: 13.5px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 10px;
      box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
      border: 1px solid transparent;
      transition: opacity 0.35s ease, transform 0.35s ease;
    }

    .top-flash.success {
      background: #ecfdf5;
      color: #065f46;
      border-color: #a7f3d0;
    }

    .top-flash.error {
      background: #fef2f2;
      color: #991b1b;
      border-color: #fecaca;
    }

    .top-flash.hide {
      opacity: 0;
      transform: translate(-50%, -8px);
      pointer-events: none;
    }

    /* Blocked Account Modal */
    body.popup-open { overflow: hidden; }

    .auth-popup-modal {
      position: fixed;
      inset: 0;
      display: grid;
      place-items: center;
      z-index: 10000;
      padding: 16px;
    }

    .auth-popup-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(2, 6, 23, 0.60);
      backdrop-filter: blur(3px);
    }

    .auth-popup-card {
      position: relative;
      width: min(460px, 100%);
      border-radius: 18px;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: #ffffff;
      box-shadow: 0 28px 64px -20px rgba(2, 6, 23, 0.6);
      padding: 24px;
    }

    .auth-popup-head {
      display: flex;
      gap: 14px;
      align-items: flex-start;
    }

    .auth-popup-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      background: #dc2626;
      color: #ffffff;
      flex-shrink: 0;
      font-size: 18px;
    }

    .auth-popup-head h3 {
      margin: 0;
      font-size: 19px;
      color: #0f172a;
      font-weight: 800;
    }

    .auth-popup-head p {
      margin: 6px 0 0;
      color: #475569;
      font-size: 13.5px;
      line-height: 1.5;
    }

    .auth-popup-actions {
      margin-top: 20px;
      display: flex;
      justify-content: flex-end;
    }

    .auth-popup-actions .btn-modal-ok {
      padding: 9px 24px;
      border-radius: 10px;
      border: none;
      background: var(--primary);
      color: #ffffff;
      font-weight: 700;
      font-size: 13.5px;
      cursor: pointer;
    }

    .hidden { display: none !important; }

    /* Responsive Design */
    @media (max-width: 900px) {
      .brand-watermark-top { top: 20px; right: 24px; }
      .brand-motto-bottom { bottom: 20px; left: 24px; }
    }

    @media (max-width: 640px) {
      .brand-watermark-top { display: none; }
      .brand-motto-bottom { display: none; }
      .auth-card { padding: 30px 22px 26px; border-radius: 18px; }
      .auth-container { padding: 16px; }
    }
  </style>
</head>
<body>

  <!-- Top Right Watermark -->
  <div class="brand-watermark-top" aria-hidden="true">
    <span class="watermark-signature">Antique</span>
    <span class="watermark-tagline">Where the Mountain Meets the Sea</span>
  </div>

  <!-- Bottom Left Motto -->
  <div class="brand-motto-bottom" aria-hidden="true">
    <span>S E R V I C E</span>
    <span>P E O P L E</span>
    <span>C O M M U N I T Y</span>
    <div class="motto-divider"></div>
  </div>

  <!-- Flowing Curved Waves at Bottom -->
  <div class="scenic-waves-wrap" aria-hidden="true">
    <svg class="wave-svg" viewBox="0 0 1440 220" fill="none" preserveAspectRatio="none">
      <path d="M0,130 C320,180 440,90 740,140 C1040,190 1220,110 1440,135 L1440,220 L0,220 Z" fill="rgba(56, 189, 248, 0.22)" />
      <path d="M0,165 C380,105 620,200 960,145 C1240,100 1360,160 1440,150 L1440,220 L0,220 Z" fill="rgba(2, 132, 199, 0.16)" />
      <path d="M0,190 C420,160 760,210 1100,180 C1280,165 1380,195 1440,185 L1440,220 L0,220 Z" fill="rgba(186, 230, 253, 0.40)" />
    </svg>
  </div>

  <?php if (is_array($authMessage)): ?>
    <div class="top-flash <?= pswdo_h((string) $authMessage['type']) ?>" id="top-flash-message" role="status" aria-live="polite">
      <i class="fas fa-<?= (string) $authMessage['type'] === 'success' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      <span><?= pswdo_h((string) $authMessage['text']) ?></span>
    </div>
  <?php endif; ?>

  <!-- Centered Login Card -->
  <main class="auth-container">
    <section class="auth-card">
      <div class="card-brand">
        <a href="../../landingpage.html" title="Return to Portal Home">
          <img src="../asset/Logo.png" alt="Aics Portal - PSWDO Antique Logo" class="brand-logo-img" />
        </a>
      </div>

      <div class="role-divider-row">
        <span class="divider-line"></span>
        <div class="role-pill">
          <i class="fas fa-shield-halved"></i>
          <span>PSWDO PERSONNEL</span>
        </div>
        <span class="divider-line"></span>
      </div>

      <form action="php_files/auth-login.php" method="post" class="login-form">
        <div class="field-group">
          <label for="pswdo-email">Office Email</label>
          <div class="field-input-box">
            <i class="fas fa-envelope field-icon"></i>
            <input
              id="pswdo-email"
              type="email"
              name="email"
              placeholder="pswdo@aics.local"
              required
              autocomplete="username"
            />
          </div>
        </div>

        <div class="field-group">
          <label for="pswdo-password">Password</label>
          <div class="field-input-box">
            <i class="fas fa-lock field-icon"></i>
            <input
              id="pswdo-password"
              type="password"
              name="password"
              placeholder="••••••••"
              required
              autocomplete="current-password"
            />
            <button
              type="button"
              class="password-toggle"
              data-password-toggle
              data-target="pswdo-password"
              aria-label="Show password"
              aria-pressed="false"
            >
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="login-btn">
          <span>SIGN IN TO PSWDO</span>
          <i class="fas fa-arrow-right"></i>
        </button>
      </form>
    </section>
  </main>

  <!-- Access Restricted Popup Modal -->
  <div id="auth-popup-modal" class="auth-popup-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="auth-popup-title">
    <div class="auth-popup-backdrop" data-popup-close></div>
    <article class="auth-popup-card">
      <header class="auth-popup-head">
        <div class="auth-popup-icon"><i class="fas fa-shield-halved"></i></div>
        <div>
          <h3 id="auth-popup-title">Account Access Notice</h3>
          <p id="auth-popup-message">Your account status prevents access at this time.</p>
        </div>
      </header>
      <div class="auth-popup-actions">
        <button type="button" class="btn-modal-ok" id="auth-popup-ok">OK</button>
      </div>
    </article>
  </div>

  <script>
    (function () {
      var authCode = <?= json_encode($authCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      var blockedCodes = ['account_banned', 'account_disabled', 'account_restricted', 'account_locked', 'account_inactive'];
      var blockedTexts = {
        account_banned: 'Your account has been banned by the administrator. Please contact support.',
        account_disabled: 'Your account is disabled. Please contact support.',
        account_restricted: 'Your account is restricted. Please contact support.',
        account_locked: 'Your account is locked. Please contact support.',
        account_inactive: 'Your account is inactive. Please contact support.'
      };
      var popupModal = document.getElementById('auth-popup-modal');
      var popupTitle = document.getElementById('auth-popup-title');
      var popupMessage = document.getElementById('auth-popup-message');
      var popupOkButton = document.getElementById('auth-popup-ok');
      var passwordToggles = Array.prototype.slice.call(document.querySelectorAll('[data-password-toggle]'));

      function closePopup() {
        if (!popupModal) return;
        popupModal.classList.add('hidden');
        popupModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('popup-open');
      }

      function showPopup(title, message) {
        if (!popupModal || !popupTitle || !popupMessage) return;
        popupTitle.textContent = title || 'Account Access Notice';
        popupMessage.textContent = message || 'Your account cannot access this portal.';
        popupModal.classList.remove('hidden');
        popupModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('popup-open');
        if (popupOkButton) {
          popupOkButton.focus();
        }
      }

      if (popupOkButton) {
        popupOkButton.addEventListener('click', closePopup);
      }

      if (popupModal) {
        popupModal.querySelectorAll('[data-popup-close]').forEach(function (element) {
          element.addEventListener('click', closePopup);
        });
      }

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && popupModal && !popupModal.classList.contains('hidden')) {
          closePopup();
        }
      });

      if (blockedCodes.indexOf(authCode) !== -1) {
        window.setTimeout(function () {
          var title = authCode === 'account_banned' ? 'Account Banned' : 'Account Access Restricted';
          showPopup(title, blockedTexts[authCode] || 'Your account cannot access this portal.');
        }, 120);
      }

      passwordToggles.forEach(function (toggleButton) {
        if (!(toggleButton instanceof HTMLButtonElement)) return;
        var targetId = String(toggleButton.getAttribute('data-target') || '').trim();
        if (targetId === '') return;
        var targetInput = document.getElementById(targetId);
        var icon = toggleButton.querySelector('i');
        if (!(targetInput instanceof HTMLInputElement) || !(icon instanceof HTMLElement)) return;

        toggleButton.addEventListener('click', function () {
          var shouldShow = targetInput.type === 'password';
          targetInput.type = shouldShow ? 'text' : 'password';
          toggleButton.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
          toggleButton.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
          icon.classList.toggle('fa-eye', !shouldShow);
          icon.classList.toggle('fa-eye-slash', shouldShow);
        });
      });

      // Clear URL parameters to prevent re-triggering messages on refresh
      if (window.history.replaceState) {
        var url = new URL(window.location.href);
        if (url.searchParams.has('auth')) {
          url.searchParams.delete('auth');
          window.history.replaceState({ path: url.href }, '', url.href);
        }
      }

      var flash = document.getElementById('top-flash-message');
      if (!flash) return;
      window.setTimeout(function () {
        flash.classList.add('hide');
        window.setTimeout(function () {
          if (flash && flash.parentNode) {
            flash.parentNode.removeChild(flash);
          }
        }, 380);
      }, 3200);
    })();
  </script>
</body>
</html>
