// Phone app shell for the applicant pages: bottom tabs, account sheet and the
// Requests switch. The elements are added on every screen size but are only
// shown by applicant-app.css below 769px, so desktop and tablet stay unchanged.
(function () {
  'use strict';

  const assetBase = ((document.currentScript && document.currentScript.dataset.assetBase) || '/AICS/AICS/asset').replace(/\/$/, '');
  const logoMark = `${assetBase}/logoinphone.png`;
  const sidebar = document.getElementById('sidebar');
  const topNav = document.querySelector('.top-nav');
  if (!sidebar || !topNav) return;

  const phone = window.matchMedia('(max-width: 768px)');
  const page = (window.location.pathname.split('/').pop() || '').toLowerCase();
  const TAB_BY_PAGE = {
    'overview-dashboard.php': 'home',
    'notifications.php': 'home',
    'application-status.php': 'requests',
    'cancel-request.php': 'requests',
    'submit-request.php': 'apply',
    'documents.php': 'documents',
    'my-profile.php': 'profile',
  };
  const activeTab = TAB_BY_PAGE[page] || '';
  const sidebarLink = (key) => sidebar.querySelector(`a[href*="page=${key}"]`);
  const hrefOf = (key) => (sidebarLink(key) || {}).href || '#';
  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  document.body.classList.add('has-app-shell');
  if (activeTab) document.body.classList.add(`app-tab-${activeTab}`);

  // Bottom tab bar. Apply passes the tap to the sidebar's Submit link so the
  // existing profile and cooldown rules in applicant-pages.js still decide.
  const tabs = [
    { key: 'home', page: 'overview', icon: 'fa-house', label: 'Home' },
    { key: 'requests', page: 'status', icon: 'fa-route', label: 'Requests' },
    { key: 'apply', page: 'submit', icon: 'fa-plus', label: 'Apply' },
    { key: 'documents', page: 'documents', icon: 'fa-folder-open', label: 'Documents' },
    { key: 'profile', page: 'profile', icon: 'fa-user', label: 'Profile' },
  ];
  const tabbar = document.createElement('nav');
  tabbar.className = 'app-tabbar';
  tabbar.setAttribute('aria-label', 'Main');
  tabbar.innerHTML = tabs.map((tab) => {
    const current = tab.key === activeTab ? ' is-active" aria-current="page' : '';
    const icon = tab.key === 'apply'
      ? `<span class="app-tab-fab" aria-hidden="true"><i class="fas ${tab.icon}"></i></span>`
      : `<i class="fas ${tab.icon}" aria-hidden="true"></i>`;
    return `<a class="app-tab app-tab-${tab.key}${current}" href="${escapeHtml(hrefOf(tab.page))}" data-app-tab="${tab.key}">${icon}<span>${tab.label}</span></a>`;
  }).join('');
  document.body.appendChild(tabbar);

  const applyTab = tabbar.querySelector('[data-app-tab="apply"]');
  applyTab.addEventListener('click', (event) => {
    const submitLink = sidebarLink('submit') || sidebar.querySelector('[data-submit-request-nav]');
    if (!submitLink) return;
    event.preventDefault();
    submitLink.click();
  });

  // Top bar: the AICS mark (a close button on Apply) and a short title that fits a phone.
  const SHORT_TITLES = {
    'overview-dashboard.php': 'Home',
    'notifications.php': 'Notifications',
    'application-status.php': 'Requests',
    'cancel-request.php': 'Requests',
    'documents.php': 'Documents',
    'my-profile.php': 'My Profile',
  };
  const navLeft = topNav.querySelector('.nav-left');
  const fullTitle = ((topNav.querySelector('.top-nav-heading') || {}).textContent || '').trim();
  const shortTitle = SHORT_TITLES[page] || (/edit/i.test(fullTitle) ? 'Edit request' : (activeTab === 'apply' ? 'New request' : fullTitle));
  if (navLeft && shortTitle) {
    navLeft.insertAdjacentHTML('afterbegin', `<span class="app-topbar-title" aria-hidden="true">${escapeHtml(shortTitle)}</span>`);
  }
  if (navLeft) {
    if (activeTab === 'apply') {
      navLeft.insertAdjacentHTML('afterbegin', `<a class="app-topbar-close" href="${escapeHtml(hrefOf('overview'))}" aria-label="Close and go to Home"><i class="fas fa-xmark" aria-hidden="true"></i></a>`);
    } else {
      navLeft.insertAdjacentHTML('afterbegin', `<img class="app-topbar-logo" src="${escapeHtml(logoMark)}" alt="AICS Portal">`);
    }
  }

  // Ask AICS on phones shows the AICS mark (phone-only images; desktop keeps its own).
  document.querySelectorAll('.aics-chatbot-launcher-logo, .aics-chatbot-brand-logo').forEach((img) => {
    img.insertAdjacentHTML('afterend', `<img class="app-chat-logo" src="${escapeHtml(logoMark)}" alt="" aria-hidden="true">`);
  });

  // Requests tab: one switch between Application Status and Manage Applications.
  const content = document.querySelector('.content-area');
  if (activeTab === 'requests' && content) {
    const onStatus = page === 'application-status.php';
    content.insertAdjacentHTML('afterbegin', `<nav class="app-segment" aria-label="Requests">
      <a href="${escapeHtml(hrefOf('status'))}"${onStatus ? ' class="is-active" aria-current="page"' : ''}><i class="fas fa-route" aria-hidden="true"></i>Status</a>
      <a href="${escapeHtml(hrefOf('cancel-request'))}"${onStatus ? '' : ' class="is-active" aria-current="page"'}><i class="fas fa-pen-to-square" aria-hidden="true"></i>Manage</a>
    </nav>`);
  }

  // Account sheet from the avatar: every sidebar destination plus Log Out.
  const chip = topNav.querySelector('.profile-chip');
  const logout = sidebar.querySelector('.logout-btn');
  if (!chip) return;
  const name = (chip.querySelector('span') || {}).textContent || 'My account';
  const initials = (chip.querySelector('.profile-av') || {}).textContent || '';
  const items = [
    { page: 'profile', icon: 'fa-user-circle', label: 'My Profile' },
    { page: 'notifications', icon: 'fa-bell', label: 'Notifications' },
    { page: 'status', icon: 'fa-route', label: 'Application Status' },
    { page: 'cancel-request', icon: 'fa-pen-to-square', label: 'Manage Applications' },
    { page: 'documents', icon: 'fa-file-invoice', label: 'Documents' },
  ];
  const scrim = document.createElement('div');
  scrim.className = 'app-sheet-scrim';
  const sheet = document.createElement('section');
  sheet.className = 'app-sheet';
  sheet.id = 'app-account-sheet';
  sheet.setAttribute('role', 'dialog');
  sheet.setAttribute('aria-modal', 'true');
  sheet.setAttribute('aria-labelledby', 'app-sheet-title');
  sheet.hidden = true;
  sheet.innerHTML = `<div class="app-sheet-grabber" aria-hidden="true"></div>
    <div class="app-sheet-head">
      <span class="app-sheet-avatar" aria-hidden="true">${escapeHtml(initials.trim())}</span>
      <div><strong id="app-sheet-title">${escapeHtml(name.trim())}</strong><span>Applicant account</span></div>
      <button type="button" class="app-sheet-close" aria-label="Close menu"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>
    <nav class="app-sheet-list" aria-label="Account">
      ${items.map((item) => `<a href="${escapeHtml(hrefOf(item.page))}"><span class="app-sheet-icon"><i class="fas ${item.icon}" aria-hidden="true"></i></span><span>${item.label}</span><i class="fas fa-chevron-right" aria-hidden="true"></i></a>`).join('')}
      ${logout ? `<a class="is-danger" href="${escapeHtml(logout.href)}"><span class="app-sheet-icon"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></span><span>Log Out</span></a>` : ''}
    </nav>`;
  document.body.append(scrim, sheet);

  let closeTimer = 0;
  const openSheet = () => {
    window.clearTimeout(closeTimer);
    sheet.hidden = false;
    window.requestAnimationFrame(() => document.body.classList.add('app-sheet-open'));
    sheet.querySelector('.app-sheet-close').focus();
  };
  const closeSheet = () => {
    if (sheet.hidden) return;
    document.body.classList.remove('app-sheet-open');
    closeTimer = window.setTimeout(() => { sheet.hidden = true; }, 260);
    if (phone.matches) chip.focus();
  };
  // The avatar already opens a small account menu (applicant-pages.js). On a
  // phone these capture listeners run first and open the sheet instead; on
  // desktop they do nothing, so the existing menu keeps working as before.
  chip.addEventListener('click', (event) => {
    if (!phone.matches) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openSheet();
  }, true);
  chip.addEventListener('keydown', (event) => {
    if (!phone.matches || (event.key !== 'Enter' && event.key !== ' ')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openSheet();
  }, true);
  scrim.addEventListener('click', closeSheet);
  sheet.querySelector('.app-sheet-close').addEventListener('click', closeSheet);
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeSheet(); });
  phone.addEventListener('change', () => {
    if (phone.matches) return;
    document.body.classList.remove('app-sheet-open');
    sheet.hidden = true;
  });
}());
