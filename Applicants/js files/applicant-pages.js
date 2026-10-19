const toggleBtn = document.getElementById("toggle-btn");
let closeBtn = document.getElementById("close-btn");
const sidebar = document.getElementById("sidebar");
const mainContent = document.getElementById("main-content");
const overlay = document.getElementById("overlay");
const toggleBtnHome = toggleBtn?.parentNode || null;
const toggleBtnMarker = toggleBtn ? document.createComment('applicant-navigation-toggle') : null;
if (toggleBtn && toggleBtnHome && toggleBtnMarker) toggleBtnHome.insertBefore(toggleBtnMarker, toggleBtn);

function createApplicantDrawerClose() {
  if (!sidebar || closeBtn) return closeBtn;
  closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.id = 'close-btn';
  closeBtn.className = 'close-sidebar-btn';
  closeBtn.setAttribute('aria-label', 'Close navigation menu');
  closeBtn.innerHTML = '<i class="fas fa-xmark" aria-hidden="true"></i>';
  sidebar.querySelector('.sidebar-header')?.appendChild(closeBtn);
  return closeBtn;
}
createApplicantDrawerClose();

// One shared, low-frequency controller owns the unread state and the compact
// header preview on every Applicant page.
const aicsNotificationTriggers = Array.from(document.querySelectorAll('[data-notification-trigger]'));
const aicsNotificationDots = Array.from(document.querySelectorAll('[data-notification-trigger] .dot'));
if (aicsNotificationTriggers.length) {
  const notificationPopup = document.createElement('section');
  notificationPopup.id = 'applicant-notification-popup';
  notificationPopup.className = 'notification-popup';
  notificationPopup.hidden = true;
  notificationPopup.setAttribute('role', 'dialog');
  notificationPopup.setAttribute('aria-label', 'Notifications');
  document.body.appendChild(notificationPopup);

  let popupRequest = null;
  let popupOpen = false;
  let activeNotificationTrigger = null;
  const notificationPageUrl = aicsNotificationTriggers[0].href;
  aicsNotificationTriggers.forEach((trigger) => trigger.setAttribute('aria-controls', notificationPopup.id));

  const unreadCountOf = (value) => {
    const count = Number(value);
    return Number.isFinite(count) && count > 0 ? Math.floor(count) : 0;
  };
  const renderBadge = (count) => {
    const hasUnread = unreadCountOf(count) > 0;
    aicsNotificationDots.forEach((dot) => { dot.hidden = !hasUnread; });
  };
  const setPopupOpen = (open, restoreFocus = false) => {
    popupOpen = open;
    notificationPopup.hidden = !open;
    aicsNotificationTriggers.forEach((trigger) => trigger.setAttribute('aria-expanded', String(open)));
    if (!open && restoreFocus && activeNotificationTrigger) activeNotificationTrigger.focus();
  };
  const popupItem = (item) => {
    const link = document.createElement('a');
    link.href = notificationPageUrl;
    link.className = `notification-popup-item${item.is_unread ? ' is-unread' : ''}`;
    const title = document.createElement('strong');
    title.textContent = item.title || 'General Update';
    const preview = document.createElement('span');
    preview.textContent = item.preview || 'You have a notification update from PSWDO.';
    const time = document.createElement('small');
    time.textContent = item.sent_at || '';
    link.append(title, preview, time);
    return link;
  };
  const renderPopup = (payload) => {
    notificationPopup.replaceChildren();
    const header = document.createElement('div');
    header.className = 'notification-popup-header';
    const heading = document.createElement('strong');
    heading.textContent = 'Notifications';
    header.appendChild(heading);
    notificationPopup.appendChild(header);
    const items = Array.isArray(payload.notifications) ? payload.notifications : [];
    const list = document.createElement('div');
    list.className = 'notification-popup-list';
    if (items.length) items.forEach((item) => list.appendChild(popupItem(item)));
    else list.textContent = 'No notifications yet.';
    notificationPopup.appendChild(list);
    const footer = document.createElement('a');
    footer.className = 'notification-popup-footer';
    footer.href = notificationPageUrl;
    footer.textContent = 'View All Notifications';
    notificationPopup.appendChild(footer);
  };
  const renderPopupMessage = (message) => {
    renderPopup({ notifications: [] });
    notificationPopup.querySelector('.notification-popup-list').textContent = message;
  };
  const refreshAicsNotificationBadge = async () => {
    try {
      const response = await fetch('notifications-state.php', { cache: 'no-store' });
      const payload = await response.json();
      if (!response.ok || !payload || payload.ok !== true) return;
      renderBadge(payload.unread_count);
    } catch (_) { /* Retain the last confirmed state when refresh is unavailable. */ }
  };
  const loadPopup = async () => {
    if (popupRequest) return popupRequest;
    notificationPopup.replaceChildren();
    notificationPopup.textContent = 'Loading notifications...';
    popupRequest = fetch('notifications-state.php?limit=5', { cache: 'no-store' })
      .then((response) => response.json().then((payload) => ({ response, payload })))
      .then(({ response, payload }) => {
        if (!response.ok || !payload || payload.ok !== true) throw new Error('Unable to load notifications.');
        renderBadge(payload.unread_count);
        renderPopup(payload);
      })
      .catch(() => renderPopupMessage('Unable to load notifications.'))
      .finally(() => { popupRequest = null; });
    return popupRequest;
  };
  aicsNotificationTriggers.forEach((trigger) => trigger.addEventListener('click', (event) => {
    event.preventDefault();
    if (popupOpen) {
      setPopupOpen(false);
      return;
    }
    activeNotificationTrigger = trigger;
    setPopupOpen(true);
    loadPopup().then(() => {
      if (!popupOpen) return;
      const focusTarget = notificationPopup.querySelector('.notification-popup-item')
        || notificationPopup.querySelector('.notification-popup-footer');
      focusTarget?.focus();
    });
  }));
  document.addEventListener('click', (event) => {
    if (popupOpen && !notificationPopup.contains(event.target) && !aicsNotificationTriggers.some((trigger) => trigger.contains(event.target))) setPopupOpen(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && popupOpen) setPopupOpen(false, true);
  });
  refreshAicsNotificationBadge();
  window.setInterval(refreshAicsNotificationBadge, 60000);
  window.AICS_refreshNotificationBadge = refreshAicsNotificationBadge;
}

// Keep all shared navigation entry points aligned with the same reversible
// state transition. The state itself always comes from a server response.
const aicsApplicationLinks = Array.from(
  document.querySelectorAll('[data-submit-request-nav], a[href*="page=submit"]'),
);

function setSubmitRequestAvailability(isProfileComplete) {
  aicsApplicationLinks.forEach((link) => {
    const currentHref = link.getAttribute('href') || '';
    if (currentHref.includes('page=submit')) {
      link.dataset.aicsOriginalHref = currentHref;
    }

    const submitHref = link.dataset.submitRequestHref || link.dataset.aicsOriginalHref || '';
    if (isProfileComplete) {
      if (submitHref) link.setAttribute('href', submitHref);
      delete link.dataset.submitRequestBlocked;
      link.removeAttribute('aria-disabled');
      link.removeAttribute('title');
      link.removeAttribute('tabindex');
      link.classList.remove('is-disabled');
      return;
    }

    link.dataset.submitRequestBlocked = 'true';
    if (submitHref) link.setAttribute('href', submitHref);
    link.removeAttribute('aria-disabled');
    link.removeAttribute('title');
    link.removeAttribute('tabindex');
    link.classList.remove('is-disabled');
  });
}

window.setSubmitRequestAvailability = setSubmitRequestAvailability;

if (aicsApplicationLinks.length) {
  fetch('profile-get.php', { cache: 'no-store' })
    .then((response) => response.json())
    .then((payload) => {
      if (!payload || typeof payload.profile_complete !== 'boolean') return;
      setSubmitRequestAvailability(payload.profile_complete);
    }).catch(() => { /* navigation remains protected by the server route */ });
}

function showIncompleteProfileDialog() {
  const dialogOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  const existing = document.getElementById('profile-completion-modal');
  if (existing) existing.remove();
  const profileNav = document.querySelector('a[href*="page=profile"]');
  const fallback = new URL('php_files/my-profile.php', window.location.href);
  const profileUrl = profileNav instanceof HTMLAnchorElement
    ? new URL(profileNav.href.replace(/dashboard\.php(?:\?.*)?$/, 'php_files/my-profile.php'), window.location.href)
    : fallback;
  const returnTo = window.location.pathname + window.location.search;
  profileUrl.searchParams.set('return_to', returnTo);

  const modal = document.createElement('div');
  modal.id = 'profile-completion-modal';
  modal.className = 'logout-modal open';
  modal.innerHTML = `
    <div class="logout-modal-card profile-completion-dialog" role="dialog" aria-modal="true" aria-labelledby="profile-dialog-title">
      <button type="button" class="profile-dialog-close" id="profile-dialog-close-btn" aria-label="Close dialog">
        <i class="fas fa-times"></i>
      </button>
      <div class="profile-dialog-icon">
        <i class="fas fa-id-card-clip"></i>
      </div>
      <h3 class="profile-dialog-title" id="profile-dialog-title">Profile Completion Required</h3>
      <p class="profile-dialog-desc">
        Please complete your applicant profile details before submitting an assistance request. Your verified information is required to process your application.
      </p>
      <div class="profile-dialog-checklist">
        <div class="profile-dialog-check-item">
          <i class="fas fa-circle-check"></i>
          <span>Personal information &amp; birthdate</span>
        </div>
        <div class="profile-dialog-check-item">
          <i class="fas fa-circle-check"></i>
          <span>Contact number &amp; complete address</span>
        </div>
      </div>
      <div class="profile-dialog-actions">
        <button type="button" class="btn-profile-secondary" id="profile-dialog-cancel">
          Maybe Later
        </button>
        <a href="${profileUrl.href}" class="btn-profile-primary" id="profile-dialog-proceed">
          <i class="fas fa-user-pen"></i>
          <span>Complete Profile Now</span>
        </a>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  document.body.classList.add('modal-open');

  let isClosing = false;
  const close = () => {
    if (isClosing) return;
    isClosing = true;
    modal.classList.remove('open');
    document.removeEventListener('keydown', onDialogKeydown);
    setTimeout(() => {
      modal.remove();
      document.body.classList.remove('modal-open');
      dialogOpener?.focus();
    }, 180);
  };

  const closeBtn = modal.querySelector('#profile-dialog-close-btn');
  const cancelBtn = modal.querySelector('#profile-dialog-cancel');
  const proceedBtn = modal.querySelector('#profile-dialog-proceed');

  closeBtn?.addEventListener('click', close);
  cancelBtn?.addEventListener('click', close);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) close();
  });

  const onDialogKeydown = (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }
    trapFocusInside(modal, event);
  };
  document.addEventListener('keydown', onDialogKeydown);
  proceedBtn?.focus();
}

// Profile incompleteness is a business-rule block, not a disabled control.
document.addEventListener('click', (event) => {
  const blocked = event.target.closest('a[data-submit-request-blocked="true"]');
  if (!blocked) return;
  event.preventDefault();
  showIncompleteProfileDialog();
});

// Shared header account menu. Routes are taken from the existing navigation,
// preserving the application’s logout handler and account-settings route.
document.querySelectorAll('.profile-chip').forEach((chip) => {
  if (!(chip instanceof HTMLElement) || chip.dataset.accountMenuReady === 'true') return;
  chip.dataset.accountMenuReady = 'true';
  chip.tabIndex = 0;
  chip.setAttribute('role', 'button');
  chip.setAttribute('aria-haspopup', 'menu');
  chip.setAttribute('aria-expanded', 'false');
  const settings = document.querySelector('a[href*="page=profile"]');
  const logout = document.querySelector('.logout-btn');
  const menu = document.createElement('div');
  menu.className = 'account-popover'; menu.hidden = true; menu.setAttribute('role', 'menu');
  menu.innerHTML = `<a role="menuitem" href="${settings instanceof HTMLAnchorElement ? settings.href : '#'}"><i class="fas fa-gear"></i> Account Settings</a><a role="menuitem" href="${logout instanceof HTMLAnchorElement ? logout.href : '#'}"><i class="fas fa-right-from-bracket"></i> Log Out</a>`;
  chip.appendChild(menu);
  const close = () => { menu.hidden = true; chip.setAttribute('aria-expanded', 'false'); };
  const toggle = () => { const open = menu.hidden; document.querySelectorAll('.account-popover').forEach((item) => { item.hidden = true; }); menu.hidden = !open; chip.setAttribute('aria-expanded', String(open)); };
  chip.addEventListener('click', (event) => { event.stopPropagation(); toggle(); });
  chip.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggle(); } if (event.key === 'Escape') close(); });
  document.addEventListener('click', (event) => { if (!chip.contains(event.target)) close(); });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });
});

const quickLookRoutes = { active: 'status', total: 'status', documents: 'documents', actions: '#what-to-do-next' };
document.querySelectorAll('[data-dashboard-kpi]').forEach((card) => {
  const page = quickLookRoutes[card.dataset.dashboardKpi || ''];
  const dashboard = document.getElementById('overview-dashboard-root')?.dataset.dashboardUrl;
  if (!page || !dashboard) return;
  card.classList.add('is-clickable'); card.tabIndex = 0; card.setAttribute('role', 'link');
  const open = () => {
    if (page.startsWith('#')) {
      const target = document.querySelector(page);
      if (target instanceof HTMLElement) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        target.focus({ preventScroll: true });
      }
      return;
    }
    window.location.assign(`${dashboard}?page=${page}`);
  };
  card.addEventListener('click', open);
  card.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); } });
});

const applicantDrawerMedia = window.matchMedia('(max-width: 992px)');
let applicantDrawerOpener = null;

function isMobile() {
  return applicantDrawerMedia.matches;
}

function applicantDrawerFocusable() {
  if (!sidebar) return [];
  return Array.from(sidebar.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'))
    .filter((item) => item instanceof HTMLElement && !item.hidden && item.getClientRects().length > 0);
}

function trapFocusInside(container, event) {
  if (event.key !== 'Tab' || !(container instanceof HTMLElement)) return;
  const focusable = Array.from(container.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  )).filter((item) => item instanceof HTMLElement && !item.hidden && item.getClientRects().length > 0);
  if (!focusable.length) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

function openApplicantDrawer() {
  if (!sidebar || !overlay || !isMobile()) return;
  applicantDrawerOpener = document.activeElement instanceof HTMLElement ? document.activeElement : toggleBtn;
  sidebar.classList.add('open');
  overlay.classList.add('active');
  document.body.classList.add('sidebar-drawer-open');
  toggleBtn?.setAttribute('aria-expanded', 'true');
  toggleBtn?.classList.add('is-open');
  sidebar.setAttribute('aria-hidden', 'false');
  sidebar.inert = false;
  overlay.setAttribute('aria-hidden', 'false');
  const focusTarget = closeBtn || applicantDrawerFocusable()[0];
  focusTarget?.focus();
}

function closeApplicantDrawer({ restoreFocus = true } = {}) {
  if (!sidebar || !overlay) return;
  sidebar.classList.remove('open');
  overlay.classList.remove('active');
  document.body.classList.remove('sidebar-drawer-open');
  toggleBtn?.setAttribute('aria-expanded', 'false');
  toggleBtn?.classList.remove('is-open');
  if (isMobile()) {
    sidebar.setAttribute('aria-hidden', 'true');
    sidebar.inert = true;
  } else {
    sidebar.removeAttribute('aria-hidden');
    sidebar.inert = false;
  }
  overlay.setAttribute('aria-hidden', 'true');
  if (restoreFocus) (applicantDrawerOpener || toggleBtn)?.focus();
  applicantDrawerOpener = null;
}

function toggleSidebar() {
  if (!sidebar || !overlay || !mainContent) return;
  if (isMobile()) {
    if (sidebar.classList.contains('open')) closeApplicantDrawer();
    else openApplicantDrawer();
    return;
  }
  sidebar.classList.toggle('collapsed');
  mainContent.classList.toggle('expanded');
  const isCollapsed = sidebar.classList.contains('collapsed');
  toggleBtn?.classList.toggle('is-collapsed', isCollapsed);
  toggleBtn?.setAttribute('aria-expanded', String(!isCollapsed));
}

if (toggleBtn) {
  toggleBtn.addEventListener("click", toggleSidebar);
}

if (closeBtn) {
  closeBtn.addEventListener("click", () => {
    if (isMobile()) closeApplicantDrawer();
    else toggleSidebar();
  });
}

if (overlay) {
  overlay.setAttribute('aria-hidden', 'true');
  overlay.addEventListener("click", () => closeApplicantDrawer());
}

if (toggleBtn) {
  toggleBtn.setAttribute("aria-controls", "sidebar");
  toggleBtn.setAttribute("aria-expanded", "false");
}
if (closeBtn) {
  closeBtn.setAttribute("aria-label", "Close navigation menu");
}

document.addEventListener("keydown", (event) => {
  if (!sidebar?.classList.contains('open') || !isMobile()) return;
  if (event.key === 'Escape') {
    event.preventDefault();
    closeApplicantDrawer();
    return;
  }
  if (event.key === 'Tab') {
    const focusable = applicantDrawerFocusable();
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }
});

sidebar?.querySelectorAll('.nav-item').forEach((item) => {
  item.addEventListener('click', () => { if (isMobile()) closeApplicantDrawer({ restoreFocus: false }); });
});

const syncApplicantDrawerMode = () => {
  const focusWasInside = sidebar?.contains(document.activeElement) === true;
  if (isMobile()) {
    const topNavLeft = document.querySelector('.top-nav .nav-left');
    if (toggleBtn && topNavLeft && toggleBtn.parentNode !== topNavLeft) topNavLeft.prepend(toggleBtn);
    toggleBtn?.setAttribute('aria-label', 'Open navigation menu');
  } else if (toggleBtn && toggleBtnHome && toggleBtnMarker) {
    toggleBtnHome.insertBefore(toggleBtn, toggleBtnMarker.nextSibling);
    toggleBtn.setAttribute('aria-label', 'Toggle sidebar');
  }
  closeApplicantDrawer({ restoreFocus: focusWasInside });
};
if (typeof applicantDrawerMedia.addEventListener === 'function') applicantDrawerMedia.addEventListener('change', syncApplicantDrawerMode);
else applicantDrawerMedia.addListener(syncApplicantDrawerMode);
syncApplicantDrawerMode();

function initLogoutConfirm() {
  const logoutLinks = Array.from(document.querySelectorAll("a.logout-btn"));
  if (logoutLinks.length === 0) {
    return;
  }

  if (document.getElementById("logout-confirm-modal")) {
    return;
  }

  const modal = document.createElement("div");
  modal.id = "logout-confirm-modal";
  modal.className = "logout-modal";
  modal.innerHTML = `
    <div class="logout-modal-card" role="dialog" aria-modal="true" aria-labelledby="logout-modal-title">
      <div class="logout-modal-icon"><i class="fas fa-right-from-bracket"></i></div>
      <h2 class="logout-modal-title" id="logout-modal-title">Log out of Applicant Portal?</h2>
      <p class="logout-modal-text">Are you sure you want to end your current session? You can sign in again anytime with your applicant credentials.</p>
      <div class="logout-modal-actions">
        <button type="button" class="btn-sm btn-muted" data-action="cancel">Cancel</button>
        <button type="button" class="btn-sm logout-confirm-btn" data-action="confirm"><i class="fas fa-right-from-bracket"></i> Yes, Log Out</button>
      </div>
    </div>
  `;

  document.body.appendChild(modal);

  const cancelBtn = modal.querySelector('[data-action="cancel"]');
  const confirmBtn = modal.querySelector('[data-action="confirm"]');
  let targetHref = "";
  let logoutDialogOpener = null;

  function closeModal() {
    modal.classList.remove("open");
    document.body.classList.remove("modal-open");
    targetHref = "";
    logoutDialogOpener?.focus();
    logoutDialogOpener = null;
  }

  function openModal(href) {
    logoutDialogOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    targetHref = href;
    modal.classList.add("open");
    document.body.classList.add("modal-open");
    if (cancelBtn instanceof HTMLElement) {
      cancelBtn.focus();
    }
  }

  logoutLinks.forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      const href = link.getAttribute("href") || "auth-logout.php";
      openModal(href);
    });
  });

  if (cancelBtn) {
    cancelBtn.addEventListener("click", closeModal);
  }

  if (confirmBtn) {
    confirmBtn.addEventListener("click", () => {
      if (targetHref) {
        window.location.assign(targetHref);
      }
    });
  }

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (!modal.classList.contains("open")) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeModal();
      return;
    }
    trapFocusInside(modal, event);
  });
}

function openBannedAccessModal(displayName) {
  const existing = document.getElementById("account-banned-modal");
  if (existing instanceof HTMLElement) {
    existing.classList.add("open");
    document.body.classList.add("modal-open", "account-banned-lockdown");
    return;
  }

  const safeName = String(displayName || "Applicant").trim() || "Applicant";
  const modal = document.createElement("div");
  modal.id = "account-banned-modal";
  modal.className = "logout-modal banned-access-modal open";
  modal.innerHTML = `
    <div class="logout-modal-card banned-access-card" role="dialog" aria-modal="true" aria-labelledby="banned-modal-title">
      <div class="logout-modal-icon"><i class="fas fa-user-lock"></i></div>
      <h2 class="logout-modal-title" id="banned-modal-title">Account Banned</h2>
      <p class="logout-modal-text">
        ${safeName}, your applicant account has been banned by the administrator.
        Access is now restricted for security and policy reasons.
      </p>
      <p class="logout-modal-text banned-modal-note">
        Please log out now and contact support if you believe this is a mistake.
      </p>
      <div class="logout-modal-actions">
        <button type="button" class="btn-sm logout-confirm-btn banned-logout-btn" data-action="logout">Log Out Now</button>
      </div>
    </div>
  `;

  document.body.appendChild(modal);
  document.body.classList.add("modal-open", "account-banned-lockdown");

  const logoutBtn = modal.querySelector('[data-action="logout"]');
  if (logoutBtn instanceof HTMLButtonElement) {
    logoutBtn.addEventListener("click", () => {
      window.location.assign("auth-logout.php");
    });
    window.setTimeout(() => logoutBtn.focus(), 20);
  }
}

function displayValue(value) {
  return value === null || value === undefined || String(value).trim().toUpperCase() === "NULL" ? "" : String(value);
}

const AICS_CPCS_DOCUMENT = {id:"coe_mswdo",label:"Certificate of Person in Crisis Situation (Optional)",required:false};
const AICS_REQUIREMENTS = {
  ...(window.AICS_DOCUMENT_REQUIREMENTS || {}),
  special_cases: [{id:"valid_id",label:"Valid Identification Card",required:true},{id:"report_police_med",label:"Police Report OR Medical Report",required:true},AICS_CPCS_DOCUMENT],
  educational_basic: [{id:"valid_id",label:"Valid Identification Card",required:true},{id:"school_assessment",label:"School Assessment Form",required:true},{id:"registration_form",label:"Registration Form",required:true},AICS_CPCS_DOCUMENT],
  disaster_victims: [{id:"valid_id",label:"Valid Identification Card",required:true},{id:"damaged_house_photo",label:"Photo of Damaged Property",required:true}],
};

const AICS_EDIT_CONTEXT = {
  isEditMode: false,
  requestId: 0,
  referenceNo: "",
  originalAssistanceType: "",
  existingDocumentsByType: {},
  lockedDocumentTypes: new Set(),
  formValues: {},
};

// --- NEW PREVIEW MODAL LOGIC ---
let documentPreviewOpener = null;
function closeDocumentPreview(modal, { restoreFocus = true } = {}) {
  if (!(modal instanceof HTMLElement)) return;
  modal.style.display = 'none';
  document.body.classList.remove('modal-open');
  const body = modal.querySelector('.preview-modal-body');
  if (body) body.replaceChildren();
  if (restoreFocus && documentPreviewOpener instanceof HTMLElement) documentPreviewOpener.focus();
  documentPreviewOpener = null;
}

function openPreviewModal(fileUrl, fileType, fileName) {
  let modal = document.getElementById("file-preview-modal");
  documentPreviewOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;

  // If it doesn't exist yet, build it
  if (!modal) {
    modal = document.createElement("div");
    modal.id = "file-preview-modal";
    modal.className = "preview-modal-overlay";
    modal.innerHTML = `
      <div class="preview-modal-card" role="dialog" aria-modal="true" aria-labelledby="preview-modal-title">
        <div class="preview-modal-header">
          <h3 id="preview-modal-title">Document Preview</h3>
          <button type="button" class="close-preview-btn" id="close-preview-modal" aria-label="Close document preview"><i class="fas fa-times" aria-hidden="true"></i></button>
        </div>
        <div class="preview-modal-body" id="preview-modal-body"></div>
      </div>
    `;
    document.body.appendChild(modal);

    // Close Button Logic
    document
      .getElementById("close-preview-modal")
      .addEventListener("click", () => closeDocumentPreview(modal));
    modal.addEventListener('click', (event) => {
      if (event.target === modal) closeDocumentPreview(modal);
    });
    modal.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeDocumentPreview(modal);
        return;
      }
      trapFocusInside(modal, event);
    });
  }

  const modalBody = document.getElementById("preview-modal-body");
  document.getElementById("preview-modal-title").textContent = fileName;

  // Render Image or PDF
  if (fileType.startsWith("image/")) {
    modalBody.innerHTML = `<img src="${fileUrl}" alt="Document preview" style="max-width: 100%; max-height: 65vh; object-fit: contain; border-radius: 4px;">`;
  } else if (fileType === "application/pdf") {
    modalBody.innerHTML = `<iframe src="${fileUrl}" title="PDF document preview" style="width: 100%; height: 65vh; border: none; border-radius: 4px;"></iframe><p class="preview-pdf-fallback"><a href="${fileUrl}" target="_blank" rel="noopener">Open PDF in a new tab</a></p>`;
  } else {
    modalBody.innerHTML = `<p>Preview is unavailable for this file type. <a href="${fileUrl}" target="_blank" rel="noopener">Open document in a new tab</a>.</p>`;
  }

  modal.style.display = "flex"; // Force it to show
  document.body.classList.add('modal-open');
  document.getElementById('close-preview-modal')?.focus();
}

function initializeDynamicUploadDropZones(container) {
  if (!(container instanceof HTMLElement) || container.dataset.uploadDropZonesReady === "true") {
    return;
  }

  container.dataset.uploadDropZonesReady = "true";

  const getDropZone = (target) => {
    if (!(target instanceof Element)) return null;
    const zone = target.closest("[data-upload-drop-zone]");
    return zone instanceof HTMLElement && container.contains(zone) ? zone : null;
  };

  const getInput = (dropZone) => {
    const inputId = dropZone.dataset.uploadInputId || dropZone.htmlFor;
    const input = inputId ? document.getElementById(inputId) : null;
    return input instanceof HTMLInputElement && input.type === "file" ? input : null;
  };

  const clearDragState = (dropZone) => dropZone.classList.remove("is-dragover");

  ["dragenter", "dragover"].forEach((eventName) => {
    container.addEventListener(eventName, (event) => {
      const dropZone = getDropZone(event.target);
      if (!dropZone) return;
      event.preventDefault();
      event.stopPropagation();
      dropZone.classList.add("is-dragover");
    });
  });

  container.addEventListener("dragleave", (event) => {
    const dropZone = getDropZone(event.target);
    if (!dropZone) return;
    event.preventDefault();
    if (!(event.relatedTarget instanceof Node) || !dropZone.contains(event.relatedTarget)) {
      clearDragState(dropZone);
    }
  });

  container.addEventListener("drop", (event) => {
    const dropZone = getDropZone(event.target);
    if (!dropZone) return;
    event.preventDefault();
    event.stopPropagation();
    clearDragState(dropZone);

    const input = getInput(dropZone);
    const file = event.dataTransfer?.files?.[0];
    if (!input || !file) return;

    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
    // Browse and drop must use the same validation, rendering, persistence,
    // and submission path.
    input.dispatchEvent(new Event("change", { bubbles: true }));
  });

  container.addEventListener("keydown", (event) => {
    const dropZone = getDropZone(event.target);
    if (!dropZone || (event.key !== "Enter" && event.key !== " ")) return;
    const input = getInput(dropZone);
    if (!input) return;
    event.preventDefault();
    input.click();
  });
}

function renderApplicantIdUploads(container) {
  // New applications reuse the ID photos saved at sign-up; a new photo replaces them.
  const profileIds = AICS_EDIT_CONTEXT.isEditMode ? {} : (window.AICS_PROFILE_ID_DOCUMENTS || {});
  const usesProfileIds = Boolean(profileIds.valid_id && profileIds.valid_id_back);
  const group = document.createElement("div");
  group.className = "applicant-id-upload-group";
  group.innerHTML = `<p class="applicant-id-upload-help"></p>
    <div class="applicant-id-upload-tiles"></div>`;
  const profileIdLabel = usesProfileIds ? String(profileIds.valid_id.id_type_label || "").trim() : "";
  group.querySelector(".applicant-id-upload-help").textContent = usesProfileIds
    ? `We will use the ID photos from your profile${profileIdLabel ? `: ${profileIdLabel}` : ""}. You don't need to upload them again. To use a different ID, tap a photo and choose a new one.`
    : "Add clear photos of the front and back of your valid ID. You can check each photo before submitting.";
  const tiles = group.querySelector(".applicant-id-upload-tiles");

  [["valid_id", "Front of ID", "Front"], ["valid_id_back", "Back or second view", "Back"]].forEach(([type, title, badge]) => {
    const draftInfo = window.AICS_DRAFT_DOCUMENTS?.[type] || null;
    const profileInfo = usesProfileIds ? { ...profileIds[type], file_name: "From your profile" } : null;
    const existingInfo = draftInfo || AICS_EDIT_CONTEXT.existingDocumentsByType[type] || profileInfo || null;
    const isLocked = AICS_EDIT_CONTEXT.isEditMode && AICS_EDIT_CONTEXT.lockedDocumentTypes.has(type);
    const previewUrl = draftInfo?.document_id
      ? `draft-document-view.php?document_id=${encodeURIComponent(String(draftInfo.document_id))}`
      : existingInfo?.document_id && AICS_EDIT_CONTEXT.requestId > 0
        ? `document-view.php?request_id=${encodeURIComponent(String(AICS_EDIT_CONTEXT.requestId))}&document_id=${encodeURIComponent(String(existingInfo.document_id))}`
        : existingInfo === profileInfo && profileInfo ? String(profileInfo.preview_url || "") : "";
    const isImage = String(existingInfo?.mime_type || "").startsWith("image/");
    const field = document.createElement("div");
    field.className = "field applicant-id-upload-field";
    if (isLocked) {
      field.innerHTML = `<label>${title}</label><div class="upload-locked-card"><div class="upload-locked-head"><i class="fas fa-lock"></i><strong>Locked by PSWDO Review</strong></div><small>This ID photo has already been checked by PSWDO and cannot be replaced.</small></div>`;
      tiles.appendChild(field);
      return;
    }
    const required = !existingInfo && (!AICS_EDIT_CONTEXT.isEditMode || type === "valid_id");
    field.innerHTML = `<label for="file-${type}">${title} ${required ? '<span class="required-mark">*</span>' : ""}</label>
      <label class="applicant-id-upload-tile${existingInfo && isImage ? " has-photo" : ""}" for="file-${type}">
        <input type="file" name="doc_${type}" id="file-${type}" data-document-type="${type}" accept="image/jpeg,image/png,image/webp" capture="environment" class="applicant-id-upload-input" ${required ? "required" : ""}>
        <span class="applicant-id-upload-placeholder"><span class="applicant-id-upload-icon"><i class="fas fa-camera" aria-hidden="true"></i></span><strong>${title}</strong><small>Tap to take or choose a photo</small></span>
        <img class="applicant-id-upload-thumb${existingInfo && isImage ? "" : " hidden"}" alt="${title}" ${existingInfo && isImage && previewUrl ? `src="${previewUrl}"` : ""}>
        <span class="applicant-id-upload-badge"><i class="fas fa-check" aria-hidden="true"></i> ${badge}</span>
      </label>
      <div class="applicant-id-upload-details"><span class="applicant-id-upload-name"></span>${previewUrl ? `<button type="button" class="btn-sm" data-secure-document-preview="${previewUrl}"><i class="fas fa-eye"></i> View saved photo</button>` : ""}</div>`;
    tiles.appendChild(field);
    const input = field.querySelector("input");
    const tile = field.querySelector(".applicant-id-upload-tile");
    const thumb = field.querySelector(".applicant-id-upload-thumb");
    const name = field.querySelector(".applicant-id-upload-name");
    name.textContent = existingInfo ? String(existingInfo.file_name || existingInfo.original_name || "Saved photo") : "";
    let objectUrl = null;
    input.addEventListener("change", () => {
      const file = input.files?.[0];
      if (!file) return;
      const extension = file.name.split(".").pop()?.toLowerCase() || "";
      const mimeByExtension = { jpg: "image/jpeg", jpeg: "image/jpeg", png: "image/png", webp: "image/webp" };
      const validType = mimeByExtension[extension] === file.type;
      const validSize = file.size > 0 && file.size <= 10 * 1024 * 1024;
      if (!validType || !validSize) {
        input.setCustomValidity(validType ? "ID photo must be 10 MB or smaller." : "Please use a JPG, PNG, or WebP photo of your ID.");
        input.reportValidity();
        input.value = "";
        return;
      }
      input.setCustomValidity("");
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = URL.createObjectURL(file);
      thumb.src = objectUrl;
      thumb.classList.remove("hidden");
      tile.classList.add("has-photo");
      name.textContent = file.name;
    });
  });
  container.appendChild(group);
}

// 2. THE GENERATOR FUNCTION
function updateDynamicUploadFields() {
  const container = document.getElementById("dynamic-upload-container");
  const selectedType = document.querySelector(
    'input[name="assistance_type"]:checked',
  )?.value;

  if (!container || !selectedType) return;

  initializeDynamicUploadDropZones(container);
  const requirements = AICS_REQUIREMENTS[selectedType] || [];
  const applicantFields = document.getElementById("applicant-upload-fields");
  const beneficiaryFields = document.getElementById("beneficiary-upload-fields");
  if (!applicantFields || !beneficiaryFields) return;
  applicantFields.innerHTML = "";
  beneficiaryFields.innerHTML = "";

  requirements.forEach((req) => {
    if (req.id === "valid_id") {
      renderApplicantIdUploads(applicantFields);
      return;
    }
    const uploadFields = req.id === "valid_id" ? applicantFields : beneficiaryFields;
    const draftInfo = window.AICS_DRAFT_DOCUMENTS && window.AICS_DRAFT_DOCUMENTS[req.id]
      ? window.AICS_DRAFT_DOCUMENTS[req.id] : null;
    const existingInfo = draftInfo || AICS_EDIT_CONTEXT.existingDocumentsByType[req.id] || null;
    const existingPreviewUrl = draftInfo && draftInfo.document_id
      ? `draft-document-view.php?document_id=${encodeURIComponent(String(draftInfo.document_id))}`
      : (!draftInfo && existingInfo && existingInfo.document_id && AICS_EDIT_CONTEXT.requestId > 0
        ? `document-view.php?request_id=${encodeURIComponent(String(AICS_EDIT_CONTEXT.requestId))}&document_id=${encodeURIComponent(String(existingInfo.document_id))}`
        : "");
    const isLockedDoc =
      AICS_EDIT_CONTEXT.isEditMode &&
      AICS_EDIT_CONTEXT.lockedDocumentTypes instanceof Set &&
      AICS_EDIT_CONTEXT.lockedDocumentTypes.has(req.id);
    const isRequiredInput =
      req.required &&
      (!AICS_EDIT_CONTEXT.isEditMode || !existingInfo) &&
      !isLockedDoc;

    const fieldDiv = document.createElement("div");
    fieldDiv.className = "field";

    if (isLockedDoc && existingInfo) {
      fieldDiv.innerHTML = `
        <label>${req.label} <span class="required-mark">*</span></label>
        <div class="upload-locked-card">
          <div class="upload-locked-head">
            <i class="fas fa-lock"></i>
            <strong>Locked by PSWDO Review</strong>
          </div>
          <p class="upload-locked-file">${req.label}${existingInfo.mime_type ? ` (${String(existingInfo.mime_type).split('/').pop().toUpperCase()})` : ''}</p>
          <small>This document has already been checked by PSWDO and cannot be replaced.</small>
        </div>
      `;
      uploadFields.appendChild(fieldDiv);
      return;
    }

    const existingName = String(existingInfo?.file_name || existingInfo?.original_name || req.label);
    const existingMime = String(existingInfo?.mime_type || '');
    const existingSize = Number(existingInfo?.file_size || 0);
    const existingMetaText = existingMime ? existingMime.toUpperCase() : 'Saved document';
    const existingSizeText = existingSize > 0 ? ` · ${(existingSize / (1024 * 1024)).toFixed(existingSize >= 1024 * 1024 ? 1 : 2)} MB` : '';

    fieldDiv.innerHTML = `
      <label>${req.label} ${isRequiredInput ? '<span class="required-mark">*</span>' : ""}</label>
      <input type="file" name="doc_${req.id}" id="file-${req.id}" data-document-type="${req.id}" accept=".pdf,.jpg,.jpeg,.png" ${isRequiredInput ? "required" : ""} style="display:none;">
      ${existingInfo ? '' : `<label class="upload-drop" id="drop-zone-${req.id}" for="file-${req.id}" role="button" tabindex="0" data-upload-drop-zone data-upload-input-id="file-${req.id}">
        <i class="fas fa-cloud-arrow-up"></i>
        <span class="upload-title">Drag and drop file here</span>
        <span class="upload-browse-text">or <strong>Browse File</strong></span>
        <span class="upload-meta">PDF, JPG, JPEG, PNG up to 25 MB</span>
      </label>`}

      <div class="upload-success-card document-upload-preview-card" id="success-card-${req.id}" style="display: ${existingInfo ? 'block' : 'none'};">
        <label class="document-preview-tile" id="preview-tile-${req.id}" for="file-${req.id}" role="button" tabindex="0" aria-label="Reselect ${req.label}" title="Reselect ${req.label}">
          <img class="document-preview-image" alt="Preview of ${req.label}" hidden>
          <iframe class="document-preview-pdf" title="Preview of ${req.label}" tabindex="-1" loading="lazy" hidden></iframe>
          <span class="document-preview-fallback" hidden><i class="fas fa-file-lines" aria-hidden="true"></i> Document ready to view</span>
          <span class="document-preview-badge"><i class="fas fa-check" aria-hidden="true"></i> Ready</span>
        </label>
        <div class="success-actions">
          <span class="uploaded-file-name" id="file-name-${req.id}">${existingInfo ? existingName : ''}</span>
          <small class="upload-meta" id="file-meta-${req.id}">${existingInfo ? `${existingMetaText}${existingSizeText}` : ''}</small>
          <button type="button" class="btn-sm document-view-button" ${existingPreviewUrl ? '' : 'hidden'}><i class="fas fa-eye" aria-hidden="true"></i> View document</button>
        </div>
      </div>
    `;
    uploadFields.appendChild(fieldDiv);

    const input = fieldDiv.querySelector(`#file-${req.id}`);
    const dropZone = fieldDiv.querySelector(`#drop-zone-${req.id}`);
    const successCard = fieldDiv.querySelector(`#success-card-${req.id}`);
    const fileNameDisplay = fieldDiv.querySelector(`#file-name-${req.id}`);
    const fileMetaDisplay = fieldDiv.querySelector(`#file-meta-${req.id}`);
    const viewButton = fieldDiv.querySelector('.document-view-button');
    const previewTile = fieldDiv.querySelector(`#preview-tile-${req.id}`);
    const previewImage = previewTile?.querySelector('.document-preview-image');
    const previewPdf = previewTile?.querySelector('.document-preview-pdf');
    const previewFallback = previewTile?.querySelector('.document-preview-fallback');

    let objectUrl = null;

    if (!input || !successCard || !fileNameDisplay || !fileMetaDisplay || !viewButton || !previewTile || !previewImage || !previewPdf || !previewFallback) {
      return;
    }

    const showInlinePreview = (url, mimeType, fileName) => {
      const isImage = mimeType.startsWith('image/') || /\.(?:jpe?g|png|webp|gif)$/i.test(fileName);
      const isPdf = mimeType === 'application/pdf' || /\.pdf$/i.test(fileName);
      previewImage.hidden = !isImage;
      previewPdf.hidden = !isPdf;
      previewFallback.hidden = isImage || isPdf;
      if (isImage) previewImage.src = url;
      else previewImage.removeAttribute('src');
      if (isPdf) previewPdf.src = `${url}#page=1&toolbar=0&navpanes=0`;
      else previewPdf.removeAttribute('src');
      viewButton.innerHTML = `<i class="fas fa-eye" aria-hidden="true"></i> ${isImage ? 'View photo' : 'View document'}`;
      viewButton.hidden = false;
    };

    if (existingInfo && existingPreviewUrl) showInlinePreview(existingPreviewUrl, existingMime, existingName);

    const openCurrentPreview = () => {
      const file = input.files?.[0];
      const url = file ? objectUrl : existingPreviewUrl;
      const name = file?.name || existingName;
      const mimeType = file?.type || existingMime || (/\.pdf$/i.test(name) ? 'application/pdf' : 'image/png');
      if (url) openPreviewModal(url, mimeType, name);
    };
    viewButton.addEventListener('click', openCurrentPreview);
    previewTile.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      input.click();
    });

    input.addEventListener("change", () => {
      if (input.files && input.files[0]) {
        const file = input.files[0];
        const extension = file.name.split('.').pop()?.toLowerCase() || '';
        const validExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!validExtensions.includes(extension) || file.size > 25 * 1024 * 1024) {
          input.setCustomValidity(!validExtensions.includes(extension) ? 'Please upload this document as PDF, JPG, or PNG.' : 'File exceeds the 25 MB limit.');
          input.reportValidity();
          input.value = '';
          return;
        }
        input.setCustomValidity('');

        if (dropZone) dropZone.style.display = "none";
        successCard.style.display = "block";
        fileNameDisplay.textContent = file.name;
        fileMetaDisplay.textContent = `${(file.type || file.name.split('.').pop() || 'file').toUpperCase()} · ${(file.size / (1024 * 1024)).toFixed(file.size >= 1024 * 1024 ? 1 : 2)} MB`;

        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = URL.createObjectURL(file);

        showInlinePreview(objectUrl, file.type, file.name);
      } else {
        if (!existingInfo) {
          if (dropZone) dropZone.style.display = "flex";
          successCard.style.display = "none";
        }
      }
    });
  });
}

// Strict Numeric & Contact Input Enforcement
// Completely prevents typing letters (a-z, A-Z, e, E, symbols) in numeric fields
function isStrictNumericInput(element) {
  if (!(element instanceof HTMLInputElement)) return false;
  if (element.classList.contains("numeric-only")) return true;
  if (element.type === "number") return true;
  if (element.type === "tel") return true;
  if (element.inputMode === "numeric") return true;
  const name = element.name || "";
  const id = element.id || "";
  return (
    name === "beneficiary_age" ||
    name === "applicant_age" ||
    name === "family_age[]" ||
    name === "contact_number" ||
    name === "applicant_contact_number" ||
    id === "beneficiary-age" ||
    id === "applicant-age" ||
    id === "beneficiary-contact-number" ||
    id === "applicant-contact-number"
  );
}

document.addEventListener("keydown", (e) => {
  const target = e.target;
  if (!isStrictNumericInput(target)) return;

  // Always allow navigation / editing control keys
  const allowedKeys = [
    "Backspace", "Delete", "Tab", "Escape", "Enter",
    "ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown", "Home", "End"
  ];
  if (allowedKeys.includes(e.key)) return;

  // Allow standard shortcut combinations (Ctrl/Cmd + A, C, V, X, Z)
  if ((e.ctrlKey || e.metaKey) && ["a", "c", "v", "x", "z"].includes(e.key.toLowerCase())) {
    return;
  }

  // Strictly block any character that is NOT a digit 0-9 (blocks letters a-z, e, E, +, -, ., etc.)
  if (!/^[0-9]$/.test(e.key)) {
    e.preventDefault();
    e.stopPropagation();
    return;
  }

  // Length limits: Contact number = 11 digits, Age = 3 digits
  const isContact = (target.name || "").includes("contact") || (target.id || "").includes("contact");
  const isAge = (target.name || "").includes("age") || (target.id || "").includes("age");
  const maxLen = isContact ? 11 : (isAge ? 3 : null);
  if (maxLen && target.value.length >= maxLen && target.selectionStart === target.selectionEnd) {
    e.preventDefault();
    e.stopPropagation();
  }
}, true);

document.addEventListener("input", (e) => {
  const target = e.target;
  if (!isStrictNumericInput(target)) return;

  const isContact = (target.name || "").includes("contact") || (target.id || "").includes("contact");
  const isAge = (target.name || "").includes("age") || (target.id || "").includes("age");
  const maxLen = isContact ? 11 : (isAge ? 3 : null);

  let digits = target.value.replace(/\D/g, "");
  if (maxLen && digits.length > maxLen) {
    digits = digits.slice(0, maxLen);
  }
  if (isAge && digits.length > 0) {
    const num = parseInt(digits, 10);
    if (num > 120) {
      digits = "120";
    }
  }
  if (target.value !== digits) {
    target.value = digits;
  }
}, true);

document.addEventListener("paste", (e) => {
  const target = e.target;
  if (!isStrictNumericInput(target)) return;

  e.preventDefault();
  const text = (e.clipboardData || window.clipboardData)?.getData("text") || "";
  let digits = text.replace(/\D/g, "");
  const isContact = (target.name || "").includes("contact") || (target.id || "").includes("contact");
  const isAge = (target.name || "").includes("age") || (target.id || "").includes("age");
  const maxLen = isContact ? 11 : (isAge ? 3 : null);

  const start = target.selectionStart || 0;
  const end = target.selectionEnd || 0;
  const currentVal = target.value;
  let newVal = currentVal.slice(0, start) + digits + currentVal.slice(end);
  newVal = newVal.replace(/\D/g, "");
  if (maxLen && newVal.length > maxLen) {
    newVal = newVal.slice(0, maxLen);
  }
  if (isAge && newVal.length > 0) {
    const num = parseInt(newVal, 10);
    if (num > 120) {
      newVal = "120";
    }
  }
  target.value = newVal;
  target.dispatchEvent(new Event("input", { bubbles: true }));
}, true);

function initApplicantIntakeForm() {
  const form = document.getElementById("aics-intake-form");
  if (!form) {
    return;
  }

  const beneficiaryBirthdateControl = initBeneficiaryBirthdateInput();

  const steps = Array.from(form.querySelectorAll(".intake-step"));
  const stepPills = Array.from(document.querySelectorAll(".step-pill"));
  const mobileStepCount = document.querySelector('.mobile-step-count');
  const mobileStepLabel = document.querySelector('.mobile-step-label');
  const mobileStepPercent = document.querySelector('.mobile-step-percent');
  const prevBtn = document.getElementById("prev-step");
  const mainActionBtn = document.getElementById("main-action-btn");
  const mainActionLabel = document.getElementById("main-action-label");
  const mainActionIcon = document.getElementById("main-action-icon");
  const stepCount = document.getElementById("step-count");
  const progressFill = document.getElementById("progress-fill");
  const feedback = document.getElementById("submit-feedback");

  const beneficiaryFirst = document.getElementById("beneficiary-first-name");
  const beneficiaryMiddle = document.getElementById("beneficiary-middle-name");
  const beneficiaryLast = document.getElementById("beneficiary-last-name");
  const beneficiaryExtension = document.getElementById("beneficiary-extension");
  const beneficiaryBirthDate = document.getElementById("beneficiary-birth-date");
  const beneficiaryAge = document.getElementById("beneficiary-age");
  const beneficiaryFull = document.getElementById("beneficiary-complete-name");

  const applicantFirst = document.getElementById("applicant-first-name");
  const applicantMiddle = document.getElementById("applicant-middle-name");
  const applicantLast = document.getElementById("applicant-last-name");
  const applicantExtension = document.getElementById("applicant-extension");
  const applicantBirthDate = document.getElementById("applicant-birth-date");
  const applicantAge = document.getElementById("applicant-age");
  const civilStatusSelect = document.getElementById("civil-status-select");
  const civilStatusOther = document.getElementById("civil-status-other");
  const civilStatusOtherField = document.getElementById(
    "civil-status-other-field",
  );
  const employmentSelect = document.getElementById("employment-select");
  const employmentOther = document.getElementById("employment-other");
  const employmentOtherField = document.getElementById("employment-other-field");
  const employmentOtherAnchor = employmentOtherField ? document.createComment("employment-other") : null;
  if (employmentOtherField && employmentOtherAnchor) {
    employmentOtherField.before(employmentOtherAnchor);
  }
  const clientCategoryOther = document.getElementById("client-category-other");
  const clientCategoryOtherField = document.getElementById(
    "client-category-other-field",
  );
  const assistanceTypeOther = document.getElementById("assistance-type-other");
  const assistanceTypeOtherField = document.getElementById(
    "assistance-type-other-field",
  );

  const municipalitySelect = document.getElementById("municipality-select");
  const barangaySelect = document.getElementById("barangay-select");
  const applicantIsBeneficiary = document.getElementById("applicant-is-beneficiary");

  const dateApplied = form.querySelector('input[name="date_applied"]');
  const dateAppliedDisplay = document.getElementById("date-applied-display");
  const assistanceOptions = Array.from(
    form.querySelectorAll('input[name="assistance_type"]'),
  );
  const claimStatusOptions = Array.from(
    form.querySelectorAll('input[name="claim_status"]'),
  );
  const amountField = form.querySelector('input[name="amount"]');
  const amountClaimedField = document.getElementById("amount-claimed");
  const chequeDateField = document.getElementById("cheque-date");
  const certificateOfIndigencyInput = document.getElementById(
    "certificate-of-indigency",
  );
  const certificateOfIndigencyTitle = document.getElementById(
    "certificate-of-indigency-title",
  );
  const validIdInput = document.getElementById("valid-id-upload");
  const validIdTitle = document.getElementById("valid-id-upload-title");
  const caseDocumentGroup = document.getElementById("case-document-group");
  const caseDocumentInput = document.getElementById("case-document-upload");
  const caseDocumentLabel = document.getElementById("case-document-label");
  const caseDocumentMeta = document.getElementById("case-document-upload-meta");
  const caseDocumentTitle = document.getElementById(
    "case-document-upload-title",
  );

  const reviewBeneficiary = document.getElementById("review-beneficiary");
  const reviewApplicant = document.getElementById("review-applicant");
  const reviewAssistance = document.getElementById("review-assistance");
  const reviewAmount = document.getElementById("review-amount");

  const allowedExtensions = ["pdf", "jpg", "jpeg", "png"];
  const maxFileSize = 25 * 1024 * 1024;
  const defaultUploadTitle = "Click to upload or drag and drop";
  let currentStep = 0;
  let activeCaseDocumentType = null;
  let beneficiaryManualSnapshot = null;
  const municipalityBarangays =
    window.ANTIQUE_MUNICIPALITY_BARANGAYS &&
    typeof window.ANTIQUE_MUNICIPALITY_BARANGAYS === "object"
      ? window.ANTIQUE_MUNICIPALITY_BARANGAYS
      : {};
  if (municipalitySelect && municipalitySelect.options.length <= 1) {
    Object.keys(municipalityBarangays).sort().forEach((municipality) => {
      const option = document.createElement("option"); option.value = municipality; option.textContent = municipality; municipalitySelect.appendChild(option);
    });
  }
  const editBootstrap =
    window.AICS_EDIT_BOOTSTRAP &&
    typeof window.AICS_EDIT_BOOTSTRAP === "object"
      ? window.AICS_EDIT_BOOTSTRAP
      : null;
  const readOnlyBootstrap =
    window.AICS_READ_ONLY_BOOTSTRAP && typeof window.AICS_READ_ONLY_BOOTSTRAP === "object"
      ? window.AICS_READ_ONLY_BOOTSTRAP
      : null;
  const formBootstrap = editBootstrap || readOnlyBootstrap;
  const isEditMode = Boolean(editBootstrap && editBootstrap.edit_mode === true);
  const applicationMode = String(form.dataset.applicationMode || "NEW_APPLICATION");
  const isReadOnlyApplication = applicationMode === "READ_ONLY_ACTIVE" || applicationMode === "COOLDOWN_READ_ONLY";
  const applicationAccess = {
    mode: applicationMode,
    canRecoverDraft: form.dataset.canRecoverDraft === "true",
  };
  const draftEnabled = !isEditMode;
  const draftGetUrl = form.dataset.draftGet || "draft-get.php";
  const draftSaveUrl = form.dataset.draftSave || "draft-save.php";
  const draftDiscardUrl = form.dataset.draftDiscard || "draft-discard.php";
  const draftDocumentRemoveUrl = form.dataset.draftDocumentRemove || "draft-document-remove.php";
  const draftUploadUrl = "draft-upload.php";
  const draftIdInput = document.getElementById("active-draft-id");
  const discardDraftBtn = document.getElementById("discard-draft-btn");
  const draftResumePanel = document.getElementById("draft-resume-panel");
  const draftContinueBtn = document.getElementById("draft-continue");
  const draftStartNewBtn = document.getElementById("draft-start-new");
  let draftState = { id: "", version: 0, ready: !draftEnabled, saving: false };
  let draftTimer = null;
  let draftResumeData = null;
  let isRestoringDraftState = false;

  function enforceReadOnlyApplicationMode() {
    if (!isReadOnlyApplication) return;
    form.classList.add("application-read-only");
    form.querySelectorAll("input, select, textarea, button").forEach((control) => {
      if (control instanceof HTMLInputElement && control.type === "hidden") return;
      control.disabled = true;
      if (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement) control.readOnly = true;
    });
  }

  if (formBootstrap) {
    AICS_EDIT_CONTEXT.isEditMode = isEditMode;
    AICS_EDIT_CONTEXT.requestId = Number(formBootstrap.request_id || 0);
    AICS_EDIT_CONTEXT.referenceNo = String(formBootstrap.reference_no || "");
    AICS_EDIT_CONTEXT.originalAssistanceType = String(
      formBootstrap.original_assistance_type || "",
    );
    AICS_EDIT_CONTEXT.existingDocumentsByType =
      formBootstrap.existing_documents &&
      typeof formBootstrap.existing_documents === "object"
        ? formBootstrap.existing_documents
        : {};
    AICS_EDIT_CONTEXT.lockedDocumentTypes = new Set(
      Array.isArray(formBootstrap.locked_document_types)
        ? formBootstrap.locked_document_types.map((v) => String(v || ""))
        : [],
    );
    AICS_EDIT_CONTEXT.formValues =
      formBootstrap.form_values && typeof formBootstrap.form_values === "object"
        ? formBootstrap.form_values
        : {};
  }

  function syncApplicationDateDisplay() {
    if (!(dateAppliedDisplay instanceof HTMLInputElement) || !dateApplied?.value) return;
    const date = new Date(`${dateApplied.value}T00:00:00Z`);
    if (!Number.isNaN(date.getTime())) {
      dateAppliedDisplay.value = new Intl.DateTimeFormat("en-PH", {
        month: "long", day: "numeric", year: "numeric", timeZone: "UTC",
      }).format(date);
    }
  }

  if (dateApplied && !dateApplied.value) {
    dateApplied.value = form.dataset.applicationDate || "";
  }
  syncApplicationDateDisplay();

  function draftFormData() {
    const data = {};
    const checkboxStates = {};
    form.querySelectorAll("input, select, textarea").forEach((field) => {
      if (!field.name || (field instanceof HTMLInputElement && field.type === "file")) return;
      if (field instanceof HTMLInputElement && field.type === "checkbox") {
        // Persist both values, including disabled controls, so an unchecked
        // box cannot inherit a stale checked value from an older draft.
        checkboxStates[field.name] = field.checked;
        return;
      }
      if (field.disabled) return;
      if (field instanceof HTMLInputElement && field.type === "radio" && !field.checked) return;
      const name = field.name.replace(/\[\]$/, "");
      const value = field.value || "";
      if (field.name.endsWith("[]")) {
        if (!Array.isArray(data[name])) data[name] = [];
        data[name].push(value);
      } else {
        data[name] = value;
      }
    });
    data.checkbox_states = checkboxStates;
    return data;
  }

  const draftCacheStorageKey = `aics_intake_draft_cache_${form.dataset.accountId || "active"}`;

  function getLocalDraftCache() {
    try {
      const raw = window.sessionStorage.getItem(draftCacheStorageKey) || window.localStorage.getItem(draftCacheStorageKey);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : null;
    } catch (_) {
      return null;
    }
  }

  function saveLocalDraftCache(step = currentStep) {
    if (!draftEnabled) return;
    try {
      const payload = {
        current_step: Math.min(4, Math.max(0, Number(step || 0))),
        data: draftFormData(),
        cached_at: new Date().toISOString(),
      };
      const json = JSON.stringify(payload);
      window.sessionStorage.setItem(draftCacheStorageKey, json);
      window.localStorage.setItem(draftCacheStorageKey, json);
    } catch (_) {}
  }

  function clearLocalDraftCache() {
    try {
      window.sessionStorage.removeItem(draftCacheStorageKey);
      window.localStorage.removeItem(draftCacheStorageKey);
    } catch (_) {}
  }

  async function saveDraft(step = currentStep, quiet = false) {
    if (!draftEnabled || !draftState.ready || draftState.saving) return false;
    draftState.saving = true;
    saveLocalDraftCache(step);
    if (!quiet) setFeedback("Saving...");
    try {
      const response = await fetch(draftSaveUrl, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draft_id: draftState.id || null, version: draftState.version, current_step: Math.min(3, step), data: draftFormData() }) });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || "Unable to save draft.");
      draftState.id = String(payload.draft.id || ""); draftState.version = Number(payload.draft.version || 0);
      draftResumeData = {
        ...(draftResumeData || {}),
        id: draftState.id,
        version: draftState.version,
        current_step: Math.min(3, step),
        data: draftFormData(),
        updated_at: new Date().toISOString(),
      };
      saveLocalDraftCache(step);
      if (draftIdInput) draftIdInput.value = draftState.id;
      if (discardDraftBtn) discardDraftBtn.hidden = !draftState.id;
      if (!quiet) setFeedback("Draft saved", "success");
      return true;
    } catch (error) {
      setFeedback(error.message || "Unable to save draft. Your current entries are still available on this page.", "error");
      return false;
    } finally { draftState.saving = false; }
  }

  function queueDraftSave() {
    if (!draftEnabled || isRestoringDraftState) return;
    saveLocalDraftCache(currentStep);
    if (!draftState.ready) return;
    if (draftTimer) window.clearTimeout(draftTimer);
    draftTimer = window.setTimeout(() => { saveDraft(currentStep, true); }, 750);
  }

  function checkboxStateIsChecked(value) {
    return value === true || value === 1 || String(value).trim().toLowerCase() === "true" || String(value).trim() === "1";
  }

  function restoreCheckboxStates(states, applyDefaults = false) {
    const savedStates = states && typeof states === "object" && !Array.isArray(states) ? states : {};
    const restored = [];
    form.querySelectorAll('input[type="checkbox"][name]').forEach((checkbox) => {
      if (!(checkbox instanceof HTMLInputElement)) return;
      if (!Object.prototype.hasOwnProperty.call(savedStates, checkbox.name)) {
        if (!applyDefaults) return;
      } else {
        checkbox.checked = checkboxStateIsChecked(savedStates[checkbox.name]);
      }
      restored.push(checkbox);
    });

    // Existing checkbox change handlers remain the single source for their
    // dependent UI. Suppress autosave only while replaying saved state.
    isRestoringDraftState = true;
    try {
      restored.forEach((checkbox) => checkbox.dispatchEvent(new Event("change", { bubbles: true })));
    } finally {
      isRestoringDraftState = false;
    }
  }

  function restoreDraftData(data) {
    if (!data || typeof data !== "object") return;
    const familyCount = Array.isArray(data?.family_name) ? data.family_name.length : 0;
    if (familyCount > 0) ensureFamilyRowsForEdit(familyCount);
    Object.entries(data || {}).forEach(([name, raw]) => {
      // A resumed draft must receive today's server-rendered application date,
      // not the calendar date on which the draft was first saved.
      if (name === "date_applied") return;
      const values = Array.isArray(raw) ? raw : [raw];
      const fields = Array.from(form.querySelectorAll(`[name="${name}"] , [name="${name}[]"]`));
      fields.forEach((field, index) => {
        const value = String(values[index] ?? "");
        if (field instanceof HTMLInputElement && (field.type === "radio" || field.type === "checkbox")) field.checked = values.map(String).includes(field.value);
        else field.value = value;
      });
    });

    // Helper for robust select option matching by value or text
    const setSelectOption = (select, targetValue) => {
      if (!(select instanceof HTMLSelectElement) || targetValue === undefined || targetValue === null) return false;
      const norm = String(targetValue).trim().toLowerCase();
      if (!norm) return false;
      const option = Array.from(select.options).find((opt) => opt.value.trim().toLowerCase() === norm || opt.textContent.trim().toLowerCase() === norm);
      if (option) {
        select.value = option.value;
        return true;
      }
      return false;
    };

    // 1. Beneficiary Province
    const beneficiaryProvince = form.querySelector('[name="beneficiary_province"]');
    if (beneficiaryProvince && data?.beneficiary_province) {
      beneficiaryProvince.value = String(data.beneficiary_province);
    }

    // 2. Beneficiary Municipality
    if (municipalitySelect && data?.beneficiary_municipality) {
      setSelectOption(municipalitySelect, data.beneficiary_municipality);
    }

    // 3. Populate Barangay options based on the restored municipality
    updateBarangayOptions();

    // 4. Beneficiary Barangay
    const savedBarangay = form.querySelector('[name="beneficiary_barangay"]');
    if (savedBarangay && data?.beneficiary_barangay) {
      setSelectOption(savedBarangay, data.beneficiary_barangay);
    }

    // 5. Beneficiary Sitio / Purok
    const beneficiarySitio = form.querySelector('[name="beneficiary_sitio_purok"]');
    if (beneficiarySitio && data?.beneficiary_sitio_purok) {
      beneficiarySitio.value = String(data.beneficiary_sitio_purok);
    }

    syncApplicationDateDisplay();
    updateDerivedBeneficiaryName(); updateConditionalFields(); updateDynamicUploadFields();

    // 6. Checkbox states (includes applicant_is_beneficiary)
    restoreCheckboxStates(data?.checkbox_states);
    if (familyNotApplicable instanceof HTMLInputElement && familyNotApplicable.checked) setFamilyNotApplicable(true);
    if (applicantIsBeneficiary instanceof HTMLInputElement && applicantIsBeneficiary.checked && !data?.checkbox_states) {
      applicantIsBeneficiary.dispatchEvent(new Event("change", { bubbles: true }));
    }

    // 7. If applicant is NOT beneficiary, ensure the address fields from draft were not wiped or overridden
    if (applicantIsBeneficiary instanceof HTMLInputElement && !applicantIsBeneficiary.checked) {
      if (beneficiaryProvince && data?.beneficiary_province) beneficiaryProvince.value = String(data.beneficiary_province);
      if (municipalitySelect && data?.beneficiary_municipality) {
        setSelectOption(municipalitySelect, data.beneficiary_municipality);
        updateBarangayOptions();
      }
      if (savedBarangay && data?.beneficiary_barangay) setSelectOption(savedBarangay, data.beneficiary_barangay);
      if (beneficiarySitio && data?.beneficiary_sitio_purok) beneficiarySitio.value = String(data.beneficiary_sitio_purok);
    }

    beneficiaryBirthdateControl.syncFromIso();
    updateReviewCards();
  }

  function formatDraftUpdatedAt(value) {
    if (!value) return "";
    const date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return "";
    const datePart = new Intl.DateTimeFormat(undefined, {
      month: "short", day: "numeric", year: "numeric",
    }).format(date);
    const timePart = new Intl.DateTimeFormat(undefined, {
      hour: "numeric", minute: "2-digit",
    }).format(date);
    return `${datePart} at ${timePart}`;
  }

  let draftResumeOpener = null;

  function hideDraftResumePanel() {
    if (!draftResumePanel) return;
    const wasVisible = !draftResumePanel.hidden;
    draftResumePanel.hidden = true;
    draftResumePanel.style.display = "none";
    draftResumePanel.classList.remove("show", "active");
    document.body.classList.remove("draft-resume-open", "modal-open");
    if (wasVisible) draftResumeOpener?.focus();
    draftResumeOpener = null;
  }

  function showDraftResumePanel(restoreOnContinue) {
    // This is the final gate. No caller, cached value, or stale draft object
    // can make the modal visible without an explicit server approval.
    if (
      !draftResumePanel ||
      !draftResumeData ||
      applicationAccess.canRecoverDraft !== true ||
      applicationAccess.mode !== "NEW_APPLICATION"
    ) {
      hideDraftResumePanel();
      return;
    }
    const lastSaved = document.getElementById("draft-last-saved");
    const formattedUpdatedAt = formatDraftUpdatedAt(draftResumeData.updated_at);
    if (lastSaved) {
      lastSaved.textContent = formattedUpdatedAt ? `Last saved: ${formattedUpdatedAt}` : "";
      lastSaved.hidden = !formattedUpdatedAt;
    }
    draftResumeOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    draftResumePanel.hidden = false;
    draftResumePanel.style.display = "flex";
    document.body.classList.add("draft-resume-open", "modal-open");
    draftContinueBtn?.focus();
    if (draftContinueBtn) draftContinueBtn.onclick = () => {
      if (restoreOnContinue) {
        restoreDraftData(draftResumeData.data);
        currentStep = Math.min(4, Math.max(0, Number(draftResumeData.current_step || 0)));
        highestReachedStep = Math.max(highestReachedStep, currentStep);
        updateStepUI();
      }
      hideDraftResumePanel();
    };
    if (draftStartNewBtn) draftStartNewBtn.onclick = async () => {
      draftStartNewBtn.disabled = true;
      try {
        await discardDraft();
        resetDiscardedDraft();
        hideDraftResumePanel();
      } catch (error) {
        setFeedback(error.message || "Unable to start a new application.", "error");
      } finally {
        draftStartNewBtn.disabled = false;
      }
    };
  }

  async function loadDraft() {
    if (!draftEnabled) return;
    try {
      const response = await fetch(draftGetUrl, { credentials: "same-origin" }); const payload = await response.json();
      // The server is the sole authority for recovery. A cached browser value
      // or an old draft row must never open this modal by itself.
      applicationAccess.mode = payload && typeof payload.application_mode === "string" ? payload.application_mode : applicationAccess.mode;
      applicationAccess.canRecoverDraft = payload && payload.can_recover_draft === true;
      let draft = payload.ok && applicationAccess.canRecoverDraft === true ? payload.draft : null;
      draftState.ready = true;

      // Merge local draft cache if present to guarantee no typed address or field is lost
      const localCache = getLocalDraftCache();
      if (localCache && localCache.data && typeof localCache.data === "object") {
        if (!draft && applicationAccess.mode === "NEW_APPLICATION") {
          const hasEntries = Object.values(localCache.data).some((val) => typeof val === "string" ? val.trim() !== "" : Array.isArray(val) ? val.length > 0 : Boolean(val));
          if (hasEntries) {
            draft = {
              id: "",
              version: 0,
              current_step: localCache.current_step || 0,
              data: localCache.data,
              updated_at: localCache.cached_at || new Date().toISOString(),
            };
            applicationAccess.canRecoverDraft = true;
          }
        } else if (draft) {
          const mergedData = { ...(draft.data || {}) };
          Object.entries(localCache.data).forEach(([k, v]) => {
            if (v !== "" && v !== null && v !== undefined && !(Array.isArray(v) && v.length === 0)) {
              mergedData[k] = v;
            }
          });
          if (localCache.data.checkbox_states && typeof localCache.data.checkbox_states === "object") {
            mergedData.checkbox_states = {
              ...(mergedData.checkbox_states || {}),
              ...localCache.data.checkbox_states,
            };
          }
          draft.data = mergedData;
          if (localCache.current_step !== undefined && Number(localCache.current_step) > Number(draft.current_step || 0)) {
            draft.current_step = localCache.current_step;
          }
        }
      }

      if (!draft) { hideDraftResumePanel(); return; }
      draftState.id = String(draft.id || ""); draftState.version = Number(draft.version || 0);
      window.AICS_DRAFT_DOCUMENTS = {};
      (Array.isArray(draft.documents) ? draft.documents : []).forEach((document) => {
        window.AICS_DRAFT_DOCUMENTS[String(document.document_type || "")] = { document_id: String(document.id || ""), file_name: String(document.original_name || "Uploaded document"), mime_type: String(document.mime_type || ""), temporary: true };
      });
      if (draftIdInput) draftIdInput.value = draftState.id;
      if (discardDraftBtn) discardDraftBtn.hidden = !draftState.id;
      draftResumeData = draft;
      showDraftResumePanel(true);
    } catch (_) { draftState.ready = true; setFeedback("Unable to load a saved draft. You can continue on this page.", "error"); }
  }

  async function discardDraft() {
    clearLocalDraftCache();
    if (!draftState.id) return true;
    const response = await fetch(draftDiscardUrl, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draft_id: draftState.id }) });
    const payload = await response.json(); if (!response.ok || !payload.ok) throw new Error(payload.message || "Unable to discard draft.");
    draftState = { id: "", version: 0, ready: true, saving: false };
    draftResumeData = null;
    window.AICS_DRAFT_DOCUMENTS = {};
    if (draftIdInput) draftIdInput.value = "";
    if (discardDraftBtn) discardDraftBtn.hidden = true;
    return true;
  }

  function resetDiscardedDraft() {
    clearLocalDraftCache();
    form.reset();
    beneficiaryBirthdateControl.syncFromIso();
    beneficiaryManualSnapshot = null;
    applyProfileDefaults();
    updateDynamicUploadFields();
    updateConditionalFields();
    restoreCheckboxStates({}, true);
    currentStep = 0;
    updateStepUI();
    setFeedback("Draft discarded.", "success");
  }

  document.addEventListener("keydown", (event) => {
    if (!draftResumePanel || draftResumePanel.hidden) return;
    if (event.key === "Escape") {
      event.preventDefault();
      hideDraftResumePanel();
      return;
    }
    trapFocusInside(draftResumePanel, event);
  });

  function setFeedback(message, type = "") {
    if (!feedback) {
      return;
    }

    feedback.textContent = message;
    feedback.classList.remove("success", "error");
    if (type) {
      feedback.classList.add(type);
    }
  }

  function composeFullName(first, middle, last) {
    return [first, middle, last]
      .filter(Boolean)
      .join(" ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function computeAgeFromBirthDate(value) {
    if (!value) {
      return "";
    }

    const birthDate = new Date(value);
    if (Number.isNaN(birthDate.getTime())) {
      return "";
    }

    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDiff = today.getMonth() - birthDate.getMonth();

    if (
      monthDiff < 0 ||
      (monthDiff === 0 && today.getDate() < birthDate.getDate())
    ) {
      age -= 1;
    }

    return age >= 0 ? String(age) : "";
  }

  function updateDerivedBeneficiaryName() {
    if (!beneficiaryFull) {
      return;
    }

    const isEditableByUser = beneficiaryFull.dataset.edited === "true";
    if (isEditableByUser && beneficiaryFull.value.trim() !== "") {
      return;
    }

    const value = composeFullName(
      beneficiaryFirst ? beneficiaryFirst.value.trim() : "",
      beneficiaryMiddle ? beneficiaryMiddle.value.trim() : "",
      beneficiaryLast ? beneficiaryLast.value.trim() : "",
    );
    beneficiaryFull.value = value;
  }

  function setApplicantFieldsDisabled(disabled) {
    [applicantFirst, applicantMiddle, applicantLast, applicantExtension, applicantBirthDate, applicantAge].forEach(
      (field) => {
        if (!field) {
          return;
        }
        field.readOnly = disabled;
        field.classList.toggle("readonly-field", disabled);
      },
    );
  }

  function setOptionalFieldVisibility(wrapper, input, visible) {
    if (!(wrapper instanceof HTMLElement) || !input) {
      return;
    }

    wrapper.classList.toggle("field-hidden", !visible);
    wrapper.hidden = !visible;
    input.disabled = !visible;
    input.required = visible;
    if (!visible) {
      input.value = "";
      input.setCustomValidity("");
    }
  }

  function updateEmploymentOtherField() {
    const visible = employmentSelect && employmentSelect.value === "Other";
    if (employmentOtherField && employmentOtherAnchor) {
      if (visible && !employmentOtherField.isConnected) {
        employmentOtherAnchor.after(employmentOtherField);
      }
      if (visible) employmentOtherField.hidden = false;
      if (!visible && employmentOtherField.isConnected) employmentOtherField.remove();
    }
    if (employmentOther) {
      employmentOther.disabled = !visible;
      employmentOther.required = Boolean(visible);
      if (!visible) {
        employmentOther.value = "";
        employmentOther.setCustomValidity("");
      }
    }
  }

  function updateConditionalFields() {
    const selectedCategories = Array.from(
      form.querySelectorAll('input[name="client_category"]:checked'),
    ).map((input) => String(input.value || ""));
    const selectedAssistanceType = getSelectedAssistanceType();

    updateEmploymentOtherField();
    setOptionalFieldVisibility(
      civilStatusOtherField,
      civilStatusOther,
      civilStatusSelect && civilStatusSelect.value === "other",
    );
    setOptionalFieldVisibility(
      clientCategoryOtherField,
      clientCategoryOther,
      selectedCategories.includes("others"),
    );
    setOptionalFieldVisibility(
      assistanceTypeOtherField,
      assistanceTypeOther,
      selectedAssistanceType === "special_cases",
    );
  }

  function updateBarangayOptions() {
    if (!municipalitySelect || !barangaySelect) {
      return;
    }

    const selectedMunicipality = municipalitySelect.value;
    const values = municipalityBarangays[selectedMunicipality] || [];
    const previousValue = barangaySelect.value;
    barangaySelect.innerHTML = "";

    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.disabled = true;
    placeholder.selected = true;
    placeholder.textContent = selectedMunicipality
      ? "Select barangay"
      : "Select municipality first";
    barangaySelect.appendChild(placeholder);

    values.forEach((name) => {
      const option = document.createElement("option");
      option.value = name;
      option.textContent = name;
      barangaySelect.appendChild(option);
    });
    barangaySelect.disabled = values.length === 0;

    if (values.includes(previousValue)) {
      barangaySelect.value = previousValue;
    }
  }

  function selectExistingOption(select, value) {
    if (!(select instanceof HTMLSelectElement)) return false;
    const expected = String(value ?? "").trim();
    if (expected === "") return false;
    const option = Array.from(select.options).find((item) => item.value === expected);
    if (!option) return false;
    select.value = option.value;
    select.setCustomValidity("");
    return true;
  }

  function applyApplicantAddressToBeneficiary(source) {
    const province = document.getElementById("beneficiary-province");
    const street = document.getElementById("beneficiary-sitio-purok");
    const sourceProvince = String(source?.province ?? "").trim();
    const sourceMunicipality = String(source?.municipality ?? "").trim();
    const sourceBarangay = String(source?.barangay ?? "").trim();
    const sourceStreet = String(source?.sitio_purok ?? source?.address_line ?? "").trim();

    if (!sourceProvince || !sourceMunicipality || !sourceBarangay) {
      setFeedback("Please complete your profile address first, then use Same as Applicant.", "error");
      return false;
    }
    if (province instanceof HTMLInputElement) province.value = sourceProvince;

    // Municipality options are loaded from the shared Antique dataset. Build
    // barangay options only after the exact municipality option is selected.
    if (!selectExistingOption(municipalitySelect, sourceMunicipality)) {
      setFeedback("Your saved profile municipality is unavailable. Please complete your profile address first.", "error");
      return false;
    }
    updateBarangayOptions();

    // The preceding call synchronously creates municipality-specific options;
    // this cannot leave the default barangay placeholder selected.
    if (!selectExistingOption(barangaySelect, sourceBarangay)) {
      setFeedback("Your saved profile barangay is unavailable. Please complete your profile address first.", "error");
      return false;
    }
    if (street instanceof HTMLInputElement) street.value = sourceStreet;
    return true;
  }

  function validateAssistanceType() {
    const options = Array.from(
      form.querySelectorAll('input[name="assistance_type"]'),
    );
    const hasSelection = options.some((opt) => opt.checked);
    const selectedOption = options.find((opt) => opt.checked) || null;
    const selectedValue = selectedOption ? String(selectedOption.value || "") : "";

    options.forEach((opt) => {
      opt.setCustomValidity(
        hasSelection ? "" : "Please select an assistance type.",
      );
    });

    if (
      hasSelection &&
      isEditMode &&
      AICS_EDIT_CONTEXT.lockedDocumentTypes.size > 0 &&
      AICS_EDIT_CONTEXT.originalAssistanceType &&
      selectedValue !== AICS_EDIT_CONTEXT.originalAssistanceType
    ) {
      options.forEach((opt) => {
        opt.setCustomValidity(
          "Assistance type cannot be changed while PSWDO-checked documents are locked.",
        );
      });
      if (selectedOption) {
        selectedOption.reportValidity();
      }
      return false;
    }

    if (!hasSelection) options[0].reportValidity();
    return hasSelection;
  }

  function updateClaimStatusFields() {
    const isClaimed =
      form.querySelector(
        'input[name="claim_status"][value="claimed"]:checked',
      ) !== null;
    if (!amountClaimedField || !chequeDateField) {
      return;
    }

    amountClaimedField.disabled = !isClaimed;
    chequeDateField.disabled = !isClaimed;

    if (!isClaimed) {
      amountClaimedField.value = "";
      chequeDateField.value = "";
      amountClaimedField.setCustomValidity("");
      chequeDateField.setCustomValidity("");
    }
  }

  function validateTemplateSpecificFields(stepIndex = currentStep) {
    const selectedCategories = Array.from(
      form.querySelectorAll('input[name="client_category"]:checked'),
    ).map((input) => String(input.value || ""));
    const selectedAssistanceType = getSelectedAssistanceType();

    if (
      stepIndex === 2 && clientCategoryOther &&
      selectedCategories.includes("others") &&
      clientCategoryOther.value.trim() === ""
    ) {
      clientCategoryOther.setCustomValidity(
        "Please specify the beneficiary category marked as Others.",
      );
      clientCategoryOther.reportValidity();
      return false;
    }
    if (clientCategoryOther) {
      clientCategoryOther.setCustomValidity("");
    }

    if (
      stepIndex === 1 && civilStatusSelect &&
      civilStatusOther &&
      civilStatusSelect.value === "other" &&
      civilStatusOther.value.trim() === ""
    ) {
      civilStatusOther.setCustomValidity(
        "Please specify the civil status marked as Other.",
      );
      civilStatusOther.reportValidity();
      return false;
    }
    if (civilStatusOther) {
      civilStatusOther.setCustomValidity("");
    }

    if (
      stepIndex === 3 && assistanceTypeOther &&
      selectedAssistanceType === "special_cases" &&
      assistanceTypeOther.value.trim() === ""
    ) {
      assistanceTypeOther.setCustomValidity(
        "Please specify the Special Case or present need.",
      );
      assistanceTypeOther.reportValidity();
      return false;
    }
    if (assistanceTypeOther) {
      assistanceTypeOther.setCustomValidity("");
    }

    return true;
  }

  function applyEditBootstrapValues() {
    if (!isEditMode || !AICS_EDIT_CONTEXT.formValues) {
      return;
    }

    const values = AICS_EDIT_CONTEXT.formValues;
    const assignValue = (name, value) => {
      const field = form.querySelector(`[name="${name}"]`);
      if (!field || value === undefined || value === null) {
        return;
      }
      field.value = displayValue(value);
    };

    assignValue("date_applied", values.date_applied);
    syncApplicationDateDisplay();
    assignValue("beneficiary_first_name", values.beneficiary_first_name);
    assignValue("beneficiary_middle_name", values.beneficiary_middle_name);
    assignValue("beneficiary_last_name", values.beneficiary_last_name);
    assignValue("beneficiary_extension", values.beneficiary_extension);
    assignValue("beneficiary_birth_date", values.beneficiary_birth_date);
    beneficiaryBirthdateControl.syncFromIso();
    assignValue("beneficiary_age", values.beneficiary_age);
    assignValue("beneficiary_complete_name", values.beneficiary_complete_name);
    assignValue("beneficiary_province", values.beneficiary_province);
    assignValue("beneficiary_municipality", values.beneficiary_municipality);
    updateBarangayOptions();
    assignValue("beneficiary_barangay", values.beneficiary_barangay);
    assignValue("beneficiary_sitio_purok", values.beneficiary_sitio_purok);
    assignValue("contact_number", values.contact_number);
    assignValue("applicant_first_name", values.applicant_first_name);
    assignValue("applicant_middle_name", values.applicant_middle_name);
    assignValue("applicant_last_name", values.applicant_last_name);
    assignValue("applicant_extension", values.applicant_extension);
    assignValue("applicant_birth_date", values.applicant_birth_date);
    assignValue("applicant_age", values.applicant_age);
    assignValue("applicant_contact_number", values.applicant_contact_number);
    assignValue("applicant_email", values.applicant_email);
    assignValue("applicant_occupation", values.applicant_occupation);
    assignValue("applicant_estimated_monthly_income", values.applicant_estimated_monthly_income);
    assignValue("civil_status", values.civil_status);
    assignValue("highest_educational_attainment", values.highest_educational_attainment);
    assignValue("estimated_income", values.estimated_income);
    assignValue("employment", values.employment);
    assignValue("employment_other", values.employment_other);
    if (employmentSelect && String(values.employment || "").startsWith("Other: ")) {
      employmentSelect.value = "Other";
      if (employmentOther) employmentOther.value = String(values.employment).slice(7);
    }
    assignValue("problem_presented", values.problem_presented);
    assignValue("client_category_other", values.client_category_other);
    assignValue("assistance_type_other", values.assistance_type_other);

    const genderValue = String(values.gender || "");
    if (genderValue !== "") {
      const genderField = form.querySelector(
        `input[name="gender"][value="${genderValue}"]`,
      );
      if (genderField) {
        genderField.checked = true;
      }
    }

    const category = Array.isArray(values.client_category)
      ? values.client_category[0]
      : values.client_category;
    const categoryField = form.querySelector(
      `input[name="client_category"][value="${String(category || "")}"]`,
    );
    if (categoryField) categoryField.checked = true;

    const assistanceTypeValue = String(values.assistance_type || "");
    if (assistanceTypeValue !== "") {
      const assistanceField = form.querySelector(
        `input[name="assistance_type"][value="${assistanceTypeValue}"]`,
      );
      if (assistanceField) {
        assistanceField.checked = true;
      }
    }

    if (beneficiaryFull) {
      beneficiaryFull.dataset.edited =
        String(values.beneficiary_complete_name || "").trim() !== ""
          ? "true"
          : "false";
    }
    syncApplicantAddressPresentation(values);

    const familyRows = Array.isArray(values.family_composition)
      ? values.family_composition
      : [];
    const familyIsNotApplicable = familyRows.length === 1 && familyRows[0]?.not_applicable === true;
    if (familyNotApplicable instanceof HTMLInputElement) familyNotApplicable.checked = familyIsNotApplicable;
    if (familyIsNotApplicable) setFamilyNotApplicable(true);
    const familyNameInputs = Array.from(
      form.querySelectorAll('input[name="family_name[]"]'),
    );
    const familyAgeInputs = Array.from(
      form.querySelectorAll('input[name="family_age[]"]'),
    );
    const familyRelationshipInputs = Array.from(
      form.querySelectorAll('[name="family_relationship[]"]'),
    );
    const familyOccupationInputs = Array.from(
      form.querySelectorAll('select[name="family_occupation[]"]'),
    );
    const familyEducationInputs = Array.from(
      form.querySelectorAll('select[name="family_education[]"]'),
    );
    const familyOccupationOtherInputs = Array.from(
      form.querySelectorAll('input[name="family_occupation_other[]"]'),
    );
    const familyIncomeInputs = Array.from(
      form.querySelectorAll('select[name="family_estimated_income[]"]'),
    );

    familyRows.forEach((row, index) => {
      if (familyNameInputs[index]) {
        familyNameInputs[index].value = displayValue(row.name);
      }
      if (familyAgeInputs[index]) {
        familyAgeInputs[index].value =
          row.age === null || row.age === undefined ? "" : String(row.age);
      }
      if (familyRelationshipInputs[index]) {
        familyRelationshipInputs[index].value = displayValue(row.relationship);
      }
      if (familyOccupationInputs[index]) {
        familyOccupationInputs[index].value = displayValue(row.occupation);
        updateFamilyOccupationOtherField(familyOccupationInputs[index]);
      }
      if (familyOccupationOtherInputs[index]) {
        familyOccupationOtherInputs[index].value = displayValue(row.occupation_other);
      }
      if (familyEducationInputs[index]) {
        familyEducationInputs[index].value = String(
          displayValue(row.educational_attainment),
        );
      }
      if (familyIncomeInputs[index]) {
        familyIncomeInputs[index].value = displayValue(row.estimated_monthly_income);
      }
    });

    updateConditionalFields();
  }

  function validateClaimedFields() {
    const isClaimed =
      form.querySelector(
        'input[name="claim_status"][value="claimed"]:checked',
      ) !== null;
    if (!isClaimed || !amountClaimedField || !chequeDateField) {
      return true;
    }

    if (!amountClaimedField.value) {
      amountClaimedField.setCustomValidity(
        "Enter amount claimed for claimed status.",
      );
      amountClaimedField.reportValidity();
      return false;
    }

    amountClaimedField.setCustomValidity("");

    if (!chequeDateField.value) {
      chequeDateField.setCustomValidity(
        "Enter cheque date for claimed status.",
      );
      chequeDateField.reportValidity();
      return false;
    }

    chequeDateField.setCustomValidity("");
    return true;
  }

  function getSelectedAssistanceType() {
    const selected = assistanceOptions.find((option) => option.checked);
    return selected ? selected.value : "";
  }

  function getCaseDocumentConfig() {
    const assistanceType = getSelectedAssistanceType();
    if (assistanceType === "medical") {
      return {
        type: "med_cert",
        label: "Medical Certificate",
      };
    }

    if (assistanceType === "burial") {
      return {
        type: "death_cert",
        label: "Death Certificate",
      };
    }

    return null;
  }

  function updateUploadDropTitle(input, titleElement) {
    if (!input || !titleElement) {
      return;
    }

    const fileName = input.files && input.files[0] ? input.files[0].name : "";
    titleElement.textContent = fileName || defaultUploadTitle;
  }

  function updateCaseDocumentField() {
    if (!caseDocumentGroup || !caseDocumentInput) {
      return;
    }

    const config = getCaseDocumentConfig();

    if (!config) {
      caseDocumentGroup.classList.add("hidden");
      caseDocumentInput.required = false;
      caseDocumentInput.disabled = true;
      caseDocumentInput.value = "";
      caseDocumentInput.setCustomValidity("");
      activeCaseDocumentType = null;
      if (caseDocumentLabel) {
        caseDocumentLabel.textContent = "Case Document";
      }
      if (caseDocumentMeta) {
        caseDocumentMeta.textContent = "PDF, JPG, PNG up to 25 MB";
      }
      updateUploadDropTitle(caseDocumentInput, caseDocumentTitle);
      return;
    }

    if (activeCaseDocumentType !== config.type) {
      caseDocumentInput.value = "";
      caseDocumentInput.setCustomValidity("");
    }

    activeCaseDocumentType = config.type;
    caseDocumentGroup.classList.remove("hidden");
    caseDocumentInput.disabled = false;
    caseDocumentInput.required = true;
    if (caseDocumentLabel) {
      caseDocumentLabel.textContent = config.label;
    }
    if (caseDocumentMeta) {
      caseDocumentMeta.textContent = `PDF, JPG, PNG up to 25 MB (${config.label})`;
    }

    updateUploadDropTitle(caseDocumentInput, caseDocumentTitle);
  }

  function validateSingleDocumentInput(input, label, required = true) {
    if (!input || input.disabled) {
      return true;
    }

    const selectedFile = input.files && input.files[0] ? input.files[0] : null;
    if (!selectedFile) {
      if (required) {
        input.setCustomValidity(`${label} is required.`);
        input.reportValidity();
        return false;
      }

      input.setCustomValidity("");
      return true;
    }

    const extension = selectedFile.name.split(".").pop()?.toLowerCase() || "";
    if (!allowedExtensions.includes(extension)) {
      input.setCustomValidity(`Please upload ${label} as PDF, JPG, or PNG.`);
      input.reportValidity();
      return false;
    }

    if (selectedFile.size > maxFileSize) {
      input.setCustomValidity(`${label} exceeds the 25 MB limit.`);
      input.reportValidity();
      return false;
    }

    input.setCustomValidity("");
    return true;
  }

  function validateRequiredDocuments() {
    const container = document.getElementById("dynamic-upload-container");
    if (!container) return true;

    const inputs = Array.from(container.querySelectorAll('input[type="file"]'));
    for (const input of inputs) {
      const labelElement = input
        .closest(".field")
        ?.querySelector("label:not(.upload-drop)");
      const label = labelElement
        ? labelElement.textContent.replace("*", "").trim()
        : "This document";
      const selectedFile = input.files && input.files[0] ? input.files[0] : null;
      const hasTemporaryUpload = Boolean(window.AICS_DRAFT_DOCUMENTS && window.AICS_DRAFT_DOCUMENTS[input.dataset.documentType || ""]);

      if (input.required && !selectedFile && !hasTemporaryUpload) {
        input.setCustomValidity(`${label} is required.`);
        setFeedback(`Please upload the required document: ${label}.`, "error");
        const field = input.closest(".field");
        if (field instanceof HTMLElement) {
          field.scrollIntoView({ behavior: "smooth", block: "center" });
        }
        return false;
      }

      if (selectedFile) {
        const extension =
          selectedFile.name.split(".").pop()?.toLowerCase() || "";
        const isIdPhoto = ["valid_id", "valid_id_back"].includes(input.dataset.documentType || "");
        const extensions = isIdPhoto ? ["jpg", "jpeg", "png", "webp"] : allowedExtensions;
        const sizeLimit = isIdPhoto ? 10 * 1024 * 1024 : maxFileSize;
        if (!extensions.includes(extension)) {
          const message = isIdPhoto ? `Please upload ${label} as JPG, PNG, or WebP.` : `Please upload ${label} as PDF, JPG, or PNG.`;
          input.setCustomValidity(message);
          setFeedback(message, "error");
          const field = input.closest(".field");
          if (field instanceof HTMLElement) {
            field.scrollIntoView({ behavior: "smooth", block: "center" });
          }
          return false;
        }

        if (selectedFile.size > sizeLimit) {
          const message = `${label} exceeds the ${isIdPhoto ? "10" : "25"} MB file size limit.`;
          input.setCustomValidity(message);
          setFeedback(message, "error");
          const field = input.closest(".field");
          if (field instanceof HTMLElement) {
            field.scrollIntoView({ behavior: "smooth", block: "center" });
          }
          return false;
        }
      }

      input.setCustomValidity("");
    }
    setFeedback("");
    return true;
  }

  function updateReviewCards() {
    if (!reviewBeneficiary || !reviewApplicant || !reviewAssistance) {
      return;
    }

    const beneficiaryName = beneficiaryFull ? beneficiaryFull.value.trim() : "";
    const applicantName = composeFullName(
      applicantFirst ? applicantFirst.value.trim() : "",
      applicantMiddle ? applicantMiddle.value.trim() : "",
      applicantLast ? applicantLast.value.trim() : "",
    );
    const selectedAssistance = assistanceOptions
      .filter((option) => option.checked)
      .map((option) => {
        const card = option.closest(".choice-card");
        return card ? card.innerText.trim() : option.value;
      });
    reviewBeneficiary.textContent = beneficiaryName || "-";
    reviewApplicant.textContent = applicantName || "-";
    reviewAssistance.textContent = selectedAssistance.length
      ? `${selectedAssistance.join(", ")}${getSelectedAssistanceType() === "special_cases" && assistanceTypeOther?.value.trim() ? ` — ${assistanceTypeOther.value.trim()}` : ""}`
      : "-";

    if (reviewAmount) {
      if (amountField && amountField.value) {
        const parsed = Number(amountField.value);
        reviewAmount.textContent = Number.isFinite(parsed)
          ? `PHP ${parsed.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
          : `PHP ${amountField.value}`;
      } else {
        reviewAmount.textContent = "-";
      }
    }
  }

  function validateFamilyComposition() {
    if (form.querySelector('#family-members-not-applicable')?.checked) return true;
    const nameInputs = Array.from(
      form.querySelectorAll('input[name="family_name[]"]'),
    );
    const hasAtLeastOne = nameInputs.some((inp) => inp.value.trim() !== "");
    if (!hasAtLeastOne) {
      setFeedback(
        "Family Composition is required — please fill in at least one member's name.",
        "error",
      );
      const shell = form.querySelector(".family-table-shell");
      if (shell instanceof HTMLElement) {
        shell.scrollIntoView({ behavior: "smooth", block: "center" });
      }
      return false;
    }
    const incomeInputs = Array.from(form.querySelectorAll('select[name="family_estimated_income[]"]'));
    const namedRows = nameInputs.filter((input) => input.value.trim() !== "");
    for (const nameInput of namedRows) {
      const rowIndex = nameInputs.indexOf(nameInput);
      const incomeInput = incomeInputs[rowIndex];
      if (incomeInput && !incomeInput.value) {
        incomeInput.setCustomValidity("Estimated Monthly Income is required for each household member.");
        incomeInput.reportValidity();
        return false;
      }
      if (incomeInput) incomeInput.setCustomValidity("");
    }
    return true;
  }

  function validateStep(stepIndex) {
    const panel = steps[stepIndex];
    if (!panel) return true;

    // Standard fields validation
    const fields = Array.from(
      panel.querySelectorAll("input, select, textarea"),
    ).filter((f) => !f.disabled);
    for (const field of fields) {
      if (field.name === "assistance_type") continue;
      if (field instanceof HTMLInputElement && field.type === "file") continue;
      if (field.classList.contains("family-row-input")) continue;
      // Skip custom check
      if (!field.checkValidity()) {
        setFeedback("Please complete all required fields before continuing.", "error");
        field.reportValidity();
        return false;
      }
    }

    // Step 2 Family Composition Validation
    if (stepIndex === 2 && !validateFamilyComposition()) {
      return false;
    }

    // Step 3 Custom Validation
    if (stepIndex === 3 && !validateAssistanceType()) {
      return false;
    }

    if (!validateTemplateSpecificFields(stepIndex)) {
      return false;
    }

    // Step 4 Custom Validation
    if (stepIndex === 4 && !validateRequiredDocuments()) {
      return false;
    }

    return true;
  }

  function validateCurrentStep() {
    return validateStep(currentStep);
  }

  function validatePrerequisites(targetStep) {
    for (let stepIndex = 0; stepIndex < targetStep; stepIndex += 1) {
      if (!validateStep(stepIndex)) return false;
    }
    return true;
  }

  function syncMobileStepSummary(step) {
    const index = Math.max(0, Math.min(Number(step) || 0, steps.length - 1));
    const label = stepPills[index]?.querySelector('.step-label')?.textContent?.trim() || `Step ${index + 1}`;
    const percent = steps.length ? Math.round(((index + 1) / steps.length) * 100) : 100;
    if (mobileStepCount) mobileStepCount.textContent = `Step ${index + 1} of ${steps.length}`;
    if (mobileStepLabel) mobileStepLabel.textContent = label;
    if (mobileStepPercent) mobileStepPercent.textContent = `${percent}% complete`;
    stepPills[index]?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }

  function updateStepUI() {
    steps.forEach((panel, index) => {
      panel.classList.toggle("active", index === currentStep);
    });
    stepPills.forEach((pill, index) => {
      pill.classList.toggle("active", index === currentStep);
      const isActive = index === currentStep;
      const isLocked = isReadOnlyApplication || (!isEditMode && index > currentStep);
      pill.disabled = isActive || isLocked;
      pill.setAttribute("aria-disabled", (isActive || isLocked) ? "true" : "false");
      if (isActive) pill.setAttribute("aria-current", "step");
      else pill.removeAttribute("aria-current");
    });
    if (stepCount) {
      stepCount.textContent = String(currentStep + 1);
    }

    if (progressFill) {
      const progress =
        steps.length > 1 ? (currentStep / (steps.length - 1)) * 100 : 100;
      progressFill.style.width = `${progress}%`;
    }
    syncMobileStepSummary(currentStep);

    // Previous button: visible only from step 2 onward (index >= 1)
    const backRow = document.getElementById("back-btn-row");
    if (backRow) {
      backRow.classList.toggle("hidden", currentStep === 0);
    }

    // Edit mode updates the already-authorized request; it never creates one.
    if (mainActionBtn && mainActionLabel && mainActionIcon) {
      const isLast = currentStep === steps.length - 1;
      mainActionBtn.dataset.isLast = isLast ? "1" : "0";
      if (isLast) {
        mainActionLabel.textContent = isEditMode ? "Save Changes" : "Submit Application";
        mainActionIcon.className = isEditMode ? "fas fa-floppy-disk" : "fas fa-paper-plane";
        mainActionBtn.insertBefore(mainActionIcon, mainActionBtn.firstChild);
      } else {
        mainActionLabel.textContent = "Next";
        mainActionIcon.className = "fas fa-arrow-right";
        mainActionBtn.appendChild(mainActionIcon);
      }
    }

    updateReviewCards();
  }

  function canNavigateToStep(targetStep, allowSequentialForward = false) {
    const target = Number(targetStep);
    if (!Number.isInteger(target) || target < 0 || target >= steps.length) return false;
    // This is intentionally the first navigation rule: a same-step request is
    // a strict no-op in every application mode.
    if (target === currentStep) return false;
    if (isReadOnlyApplication) return false;
    if (isEditMode) return true;
    if (target < currentStep) return true;
    return allowSequentialForward && target === currentStep + 1;
  }

  async function navigateToStep(targetStep, saveDraftAfterNavigation = true, allowSequentialForward = false) {
    const target = Number(targetStep);
    if (!canNavigateToStep(target, allowSequentialForward)) return false;
    currentStep = target;
    highestReachedStep = Math.max(highestReachedStep, currentStep);
    updateStepUI();
    if (saveDraftAfterNavigation && draftState.id) await saveDraft(currentStep, true);
    return true;
  }

  // Set by the submit button so the upload can show its progress.
  let submitUploadProgress = null;

  async function submitFormData() {
    const endpoint = form.getAttribute("action") || "save-request.php";
    const formData = new FormData(form);

    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open("POST", endpoint);
      xhr.upload.onprogress = (event) => {
        if (event.lengthComputable && typeof submitUploadProgress === "function") {
          submitUploadProgress(event.loaded / event.total);
        }
      };
      xhr.upload.onload = () => {
        if (typeof submitUploadProgress === "function") submitUploadProgress(1);
      };
      xhr.onload = () => {
        let payload = null;
        try {
          payload = JSON.parse(xhr.responseText);
        } catch (error) {
          payload = null;
        }
        if (xhr.status < 200 || xhr.status >= 300 || !payload || payload.ok !== true) {
          reject(new Error(payload && payload.message ? payload.message : "Unable to submit request. Please try again."));
          return;
        }
        resolve(payload);
      };
      xhr.onerror = () => reject(new Error("The connection was interrupted. Check your internet connection and try again."));
      xhr.send(formData);
    });
  }

  if (prevBtn) {
    prevBtn.addEventListener("click", async () => {
      if (currentStep > 0) {
        await navigateToStep(currentStep - 1);
      }
    });
  }

  if (mainActionBtn) {
    mainActionBtn.addEventListener("click", async () => {
      if (!validateCurrentStep()) {
        return;
      }

      const isLast = mainActionBtn.dataset.isLast === "1";
      if (isLast) {
        // Trigger the form's submit handler (shows confirmation modal)
        form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
      } else {
        const targetStep = currentStep + 1;
        if (!await navigateToStep(targetStep, false, true)) return;
        setFeedback("");
        await saveDraft(currentStep, true);
      }
    });
  }

  let highestReachedStep = 0;
  stepPills.forEach((pill, index) => {
    pill.addEventListener("click", async () => {
      // The active pill is an accessible status indicator, never navigation.
      if (index === currentStep) return;
      if (!canNavigateToStep(index)) return;
      // Backward navigation only changes the view. Forward navigation always
      // checks the live values of every prerequisite step.
      if (!isEditMode && index > currentStep && !validatePrerequisites(index)) {
        return;
      }
      if (!await navigateToStep(index, false)) return;
      // A stepper click only navigates. Autosave handles edits, and an
      // already-active draft records the new position without creating one.
      if (draftState.id) await saveDraft(currentStep, true);
    });
  });

  form.addEventListener("input", queueDraftSave);
  form.addEventListener("change", queueDraftSave);

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      saveLocalDraftCache(currentStep);
      if (draftTimer) {
        window.clearTimeout(draftTimer);
        draftTimer = null;
        saveDraft(currentStep, true);
      }
    }
  });

  window.addEventListener("pagehide", () => {
    saveLocalDraftCache(currentStep);
    if (draftTimer) {
      window.clearTimeout(draftTimer);
      draftTimer = null;
      saveDraft(currentStep, true);
    }
  });

  window.addEventListener("beforeunload", () => {
    saveLocalDraftCache(currentStep);
  });

  document.querySelectorAll(".nav-menu a, .sidebar a, .top-nav a, .submit-hero-executive-actions a").forEach((link) => {
    link.addEventListener("click", () => {
      saveLocalDraftCache(currentStep);
      if (draftTimer) {
        window.clearTimeout(draftTimer);
        draftTimer = null;
        saveDraft(currentStep, true);
      }
    });
  });
  form.addEventListener("change", async (event) => {
    const input = event.target;
    if (!draftEnabled || !(input instanceof HTMLInputElement) || input.type !== "file" || !input.files?.[0]) return;
    const documentType = input.dataset.documentType || "";
    if (!documentType) return;
    if (!draftState.id) await saveDraft(currentStep, true);
    if (!draftState.id) return;
    const upload = new FormData(); upload.append("draft_id", draftState.id); upload.append("document_type", documentType); upload.append("document", input.files[0]);
    try {
      const response = await fetch(draftUploadUrl, { method: "POST", body: upload }); const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || "Unable to save document.");
      window.AICS_DRAFT_DOCUMENTS = window.AICS_DRAFT_DOCUMENTS || {};
      window.AICS_DRAFT_DOCUMENTS[documentType] = { document_id: String(payload.document.id || ""), file_name: String(payload.document.original_name || "Uploaded document"), mime_type: String(payload.document.mime_type || ""), temporary: true };
      input.value = ""; updateDynamicUploadFields(); setFeedback("Document saved with your draft.", "success");
    } catch (error) { setFeedback(error.message || "Unable to save document. Your selected file remains on this page.", "error"); }
  });
  form.addEventListener("click", async (event) => {
    const previewButton = event.target.closest("[data-secure-document-preview]");
    if (previewButton) {
      const previewUrl = previewButton.dataset.secureDocumentPreview || "";
      if (previewUrl) window.open(previewUrl, "_blank", "noopener,noreferrer");
      return;
    }
    const button = event.target.closest("[data-draft-remove-document]");
    if (!button || !draftEnabled || !draftState.id) return;
    const documentType = button.dataset.draftRemoveDocument || "";
    if (!documentType || !window.confirm("Remove this temporary draft document?")) return;
    try {
      const response = await fetch(draftDocumentRemoveUrl, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draft_id: draftState.id, document_type: documentType }) });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || "Unable to remove document.");
      delete window.AICS_DRAFT_DOCUMENTS[documentType];
      updateDynamicUploadFields();
      setFeedback("Temporary document removed.", "success");
    } catch (error) { setFeedback(error.message || "Unable to remove document.", "error"); }
  });
  if (discardDraftBtn) {
    // Reuse the single unfinished-application modal as the safe decision
    // point; Start New is the only route that removes a saved draft.
    discardDraftBtn.addEventListener("click", () => showDraftResumePanel(false));
  }
  if (beneficiaryFull) {
    beneficiaryFull.addEventListener("input", () => {
      beneficiaryFull.dataset.edited =
        beneficiaryFull.value.trim() !== "" ? "true" : "false";
    });
  }

  [
    beneficiaryFirst,
    beneficiaryMiddle,
    beneficiaryLast,
    beneficiaryExtension,
    beneficiaryAge,
  ].forEach((field) => {
    if (!field) {
      return;
    }

    field.addEventListener("input", () => {
      updateDerivedBeneficiaryName();
      updateReviewCards();
    });
  });
  [applicantFirst, applicantMiddle, applicantLast, amountField].forEach(
    (field) => {
      if (!field) {
        return;
      }
      field.addEventListener("input", updateReviewCards);
    },
  );

  if (civilStatusSelect) {
    civilStatusSelect.addEventListener("change", updateConditionalFields);
  }
  if (employmentSelect) {
    employmentSelect.addEventListener("change", updateConditionalFields);
  }

  if (municipalitySelect) {
    municipalitySelect.addEventListener("change", updateBarangayOptions);
  }
  if (applicantIsBeneficiary instanceof HTMLInputElement) {
    applicantIsBeneficiary.addEventListener("change", () => {
      const contactInput = form.querySelector('[name="contact_number"]');
      const incomeInput = form.querySelector('[name="estimated_income"]');
      const province = document.getElementById("beneficiary-province");
      const street = document.getElementById("beneficiary-sitio-purok");
      const snapshotValue = (field) => field instanceof HTMLInputElement || field instanceof HTMLSelectElement ? field.value : "";

      if (!applicantIsBeneficiary.checked) {
        if (beneficiaryManualSnapshot) {
          const restore = (field, key) => {
            if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) field.value = beneficiaryManualSnapshot[key] || "";
          };
          restore(beneficiaryFirst, "first"); restore(beneficiaryMiddle, "middle"); restore(beneficiaryLast, "last");
          restore(beneficiaryExtension, "extension"); restore(beneficiaryBirthDate, "birthDate"); restore(beneficiaryAge, "age");
          beneficiaryBirthdateControl.syncFromIso();
          restore(contactInput, "contact"); restore(employmentSelect, "employment"); updateConditionalFields();
          restore(employmentOther, "employmentOther"); restore(incomeInput, "income"); restore(province, "province");
          if (municipalitySelect) { municipalitySelect.value = beneficiaryManualSnapshot.municipality || ""; updateBarangayOptions(); }
          if (barangaySelect) barangaySelect.value = beneficiaryManualSnapshot.barangay || "";
          restore(street, "street"); restore(beneficiaryFull, "fullName");
          if (beneficiaryFull) beneficiaryFull.dataset.edited = beneficiaryManualSnapshot.fullNameEdited || "false";
        }
        beneficiaryManualSnapshot = null;
        updateConditionalFields();
        updateReviewCards();
        queueDraftSave();
        return;
      }

      beneficiaryManualSnapshot = {
        first: snapshotValue(beneficiaryFirst), middle: snapshotValue(beneficiaryMiddle), last: snapshotValue(beneficiaryLast),
        extension: snapshotValue(beneficiaryExtension), birthDate: snapshotValue(beneficiaryBirthDate), age: snapshotValue(beneficiaryAge),
        contact: snapshotValue(contactInput), employment: snapshotValue(employmentSelect), employmentOther: snapshotValue(employmentOther),
        income: snapshotValue(incomeInput), province: snapshotValue(province), municipality: snapshotValue(municipalitySelect),
        barangay: snapshotValue(barangaySelect), street: snapshotValue(street), fullName: snapshotValue(beneficiaryFull),
        fullNameEdited: beneficiaryFull?.dataset.edited || "false",
      };
      const source = isEditMode ? (editBootstrap?.form_values || {}) : (window.AICS_PROFILE_DEFAULTS || {});
      const sourceValue = (applicantField, profileField) => String(source[applicantField] ?? source[profileField] ?? "");
      const assign = (field, value) => {
        if (field instanceof HTMLInputElement) field.value = value;
      };

      assign(beneficiaryFirst, sourceValue("applicant_first_name", "first_name"));
      assign(beneficiaryMiddle, sourceValue("applicant_middle_name", "middle_name"));
      assign(beneficiaryLast, sourceValue("applicant_last_name", "last_name"));
      assign(beneficiaryExtension, sourceValue("applicant_extension", "extension_name"));
      assign(beneficiaryBirthDate, sourceValue("applicant_birth_date", "date_of_birth"));
      beneficiaryBirthdateControl.syncFromIso();
      if (beneficiaryBirthDate instanceof HTMLInputElement && beneficiaryAge instanceof HTMLInputElement) {
        beneficiaryAge.value = computeAgeFromBirthDate(beneficiaryBirthDate.value);
      }
      assign(contactInput, sourceValue("applicant_contact_number", "contact_number"));

      const occupation = sourceValue("applicant_occupation", "occupation");
      if (employmentSelect) {
        const optionExists = Array.from(employmentSelect.options).some((option) => option.value === occupation);
        employmentSelect.value = optionExists ? occupation : (occupation ? "Other" : "");
      }
      if (employmentOther instanceof HTMLInputElement && occupation && employmentSelect?.value === "Other") {
        employmentOther.value = occupation.replace(/^Other:\s*/i, "");
      }
      if (incomeInput instanceof HTMLSelectElement) {
        const income = sourceValue("applicant_estimated_monthly_income", "estimated_monthly_income");
        incomeInput.value = Array.from(incomeInput.options).some((option) => option.value === income) ? income : "";
      }
      applyApplicantAddressToBeneficiary(source);
      if (beneficiaryFull) beneficiaryFull.dataset.edited = "false";
      updateDerivedBeneficiaryName();
      updateConditionalFields();
      updateReviewCards();
      queueDraftSave();
    });
  }

  if (beneficiaryBirthDate) {
    beneficiaryBirthDate.addEventListener("change", () => {
      if (beneficiaryAge) {
        beneficiaryAge.value = computeAgeFromBirthDate(
          beneficiaryBirthDate.value,
        );
      }
      validateBeneficiaryCategoryAge();
      updateReviewCards();
    });
  }

  function toggleApplicantAgeWarning() {
    const warning = document.getElementById("applicant-age-warning");
    if (!warning || !applicantAge) {
      return;
    }
    const ageVal = parseInt(applicantAge.value, 10);
    const isMinor = Number.isFinite(ageVal) && ageVal > 0 && ageVal < 18;
    warning.style.display = isMinor ? "block" : "none";
  }

  if (applicantBirthDate) {
    applicantBirthDate.addEventListener("change", () => {
      if (applicantAge) {
        applicantAge.value = computeAgeFromBirthDate(applicantBirthDate.value);
      }
      toggleApplicantAgeWarning();
      updateReviewCards();
    });
  }

  if (applicantAge) {
    applicantAge.addEventListener("input", toggleApplicantAgeWarning);
  }

  function validateBeneficiaryCategoryAge() {
    if (!beneficiaryAge) return true;
    const ageFromDob = beneficiaryBirthDate?.value
      ? parseInt(computeAgeFromBirthDate(beneficiaryBirthDate.value), 10)
      : NaN;
    const age = Number.isFinite(ageFromDob) ? ageFromDob : parseInt(beneficiaryAge.value, 10);
    const category = form.querySelector('input[name="client_category"]:checked')?.value || "";
    let message = "";
    if (Number.isFinite(age) && category === "senior_citizen" && age < 60) {
      message = "Senior Citizen can only be selected for beneficiaries aged 60 years or older.";
    } else if (Number.isFinite(age) && category === "youth" && age >= 60) {
      message = "The selected beneficiary category is inconsistent with the beneficiary's age.";
    }
    beneficiaryAge.setCustomValidity(message);
    const inlineError = document.getElementById("beneficiary-category-age-error");
    if (inlineError) {
      inlineError.textContent = message;
      inlineError.hidden = message === "";
    }
    return message === "";
  }

  if (beneficiaryAge) beneficiaryAge.addEventListener("input", validateBeneficiaryCategoryAge);

  [
    [
      certificateOfIndigencyInput,
      certificateOfIndigencyTitle,
      "Certificate of Indigency",
    ],
    [validIdInput, validIdTitle, "Valid ID"],
    [caseDocumentInput, caseDocumentTitle, "Case Document"],
  ].forEach(([input, titleElement, label]) => {
    if (!input) {
      return;
    }

    input.addEventListener("change", () => {
      updateUploadDropTitle(input, titleElement);

      const caseConfig = getCaseDocumentConfig();
      const resolvedLabel =
        input === caseDocumentInput && caseConfig ? caseConfig.label : label;
      validateSingleDocumentInput(input, resolvedLabel, input.required);
    });
  });
  assistanceOptions.forEach((option) => {
    option.addEventListener("change", () => {
      updateDynamicUploadFields();
      updateConditionalFields();
      updateReviewCards();
    });
  });
  Array.from(form.querySelectorAll('input[name="client_category"]')).forEach(
    (option) => {
      option.addEventListener("change", () => { updateConditionalFields(); validateBeneficiaryCategoryAge(); });
    },
  );
  claimStatusOptions.forEach((option) => {
    option.addEventListener("change", updateClaimStatusFields);
  });

  // --- SUBMIT CONFIRMATION LOGIC ---
  const confirmModal = document.getElementById("submit-confirm-modal");
  const closeConfirmBtn = document.getElementById("close-confirm-modal");
  const cancelSubmitBtn = document.getElementById("btn-cancel-submit");
  const finalSubmitBtn = document.getElementById("btn-confirm-submit");
  const finalSubmitDefaultHtml = finalSubmitBtn
    ? finalSubmitBtn.innerHTML
    : '<i class="fas fa-paper-plane"></i> Yes, Submit Now';

  let submitConfirmOpener = null;
  function openSubmitConfirmModal() {
    if (!confirmModal) return;
    submitConfirmOpener = document.activeElement instanceof HTMLElement ? document.activeElement : mainActionBtn;
    confirmModal.style.display = 'flex';
    document.body.classList.add('modal-open');
    (closeConfirmBtn || cancelSubmitBtn || finalSubmitBtn)?.focus();
  }
  function closeSubmitConfirmModal({ restoreFocus = true } = {}) {
    if (!confirmModal) return;
    confirmModal.style.display = 'none';
    document.body.classList.remove('modal-open');
    if (restoreFocus) (submitConfirmOpener || mainActionBtn)?.focus();
    submitConfirmOpener = null;
  }
  const closeConfirmModal = () => closeSubmitConfirmModal();
  if (closeConfirmBtn)
    closeConfirmBtn.addEventListener("click", closeConfirmModal);
  if (cancelSubmitBtn)
    cancelSubmitBtn.addEventListener("click", closeConfirmModal);
  confirmModal?.addEventListener('click', (event) => {
    if (event.target === confirmModal) closeSubmitConfirmModal();
  });
  confirmModal?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeSubmitConfirmModal();
      return;
    }
    trapFocusInside(confirmModal, event);
  });

  // 1. Intercept the Form Submission
  form.addEventListener("submit", (event) => {
    event.preventDefault(); // Stop instant submission

    if (!validateCurrentStep()) return;

    if (!validateBeneficiaryCategoryAge()) {
      beneficiaryAge?.reportValidity();
      return;
    }

    if (!validateAssistanceType()) {
      document.querySelector('input[name="assistance_type"]').reportValidity();
      return;
    }

    if (!validateRequiredDocuments()) return;

    if (confirmModal) {
      try {
      // 2. Populate Modal Data
      const beneficiaryName = document.getElementById("beneficiary-complete-name")?.value ?? "";
      const applicantName = composeFullName(
        applicantFirst ? applicantFirst.value.trim() : "",
        applicantMiddle ? applicantMiddle.value.trim() : "",
        applicantLast ? applicantLast.value.trim() : "",
      );
      const checkedAssistanceInput = document.querySelector('input[name="assistance_type"]:checked');
      const assistanceLabel = checkedAssistanceInput?.parentElement?.innerText.trim() ?? "-";
      const specialCase = assistanceTypeOther?.value.trim() ?? "";
      const assistanceType = `${assistanceLabel}${checkedAssistanceInput?.value === "special_cases" && specialCase ? ` — ${specialCase}` : ""}`;
      const beneficiaryCategories = Array.from(form.querySelectorAll('input[name="client_category"]:checked')).map((input) => input.closest(".choice-card")?.innerText.trim() || input.value);
      const contactNumber = form.querySelector('[name="contact_number"]')?.value.trim() || "-";
      const beneficiaryOccupation = form.querySelector('[name="employment"]')?.value.trim() || "-";
      const beneficiaryIncome = form.querySelector('[name="estimated_income"]')?.value.trim() || "-";
      const applicantOccupation = document.getElementById("applicant-occupation")?.value.trim() || "-";
      const applicantIncome = document.getElementById("applicant-estimated-income")?.value.trim() || "-";
      const familyNames = Array.from(form.querySelectorAll('input[name="family_name[]"]'));
      const familyRelationships = Array.from(form.querySelectorAll('[name="family_relationship[]"]'));
      const familySummary = form.querySelector('#family-members-not-applicable')?.checked ? "Not Applicable" : familyNames.map((input, index) => {
        const name = input.value.trim();
        if (!name) return "";
        const relationship = familyRelationships[index]?.value.trim() || "Relationship not provided";
        return `${name} (${relationship})`;
      }).filter(Boolean).join("; ") || "No family composition information provided";
      const beneficiaryAddress = [document.getElementById("beneficiary-sitio-purok")?.value, barangaySelect?.value, municipalitySelect?.value, document.getElementById("beneficiary-province")?.value].filter(Boolean).join(", ") || "-";
      const applicantAddress = document.getElementById("applicant-complete-address")?.value.trim() || "-";
      const uploadedDocumentTypes = new Set(Object.keys(window.AICS_DRAFT_DOCUMENTS || {}));
      if (!AICS_EDIT_CONTEXT.isEditMode) Object.keys(window.AICS_PROFILE_ID_DOCUMENTS || {}).forEach((type) => uploadedDocumentTypes.add(type));
      Array.from(
        document.querySelectorAll('#dynamic-upload-container input[type="file"]'),
      ).filter((input) => input.files && input.files.length > 0).forEach((input) => {
        if (input.dataset.documentType) uploadedDocumentTypes.add(input.dataset.documentType);
      });
      const uploadedDocsCount = uploadedDocumentTypes.size;
      const lockedDocsCount = AICS_EDIT_CONTEXT.isEditMode
        ? AICS_EDIT_CONTEXT.lockedDocumentTypes.size
        : 0;

      const setModalText = (id, text) => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
      };
      setModalText("modal-confirm-beneficiary", beneficiaryName || "-");
      setModalText("modal-confirm-applicant", applicantName || "-");
      setModalText("modal-confirm-assistance", assistanceType);
      setModalText("modal-confirm-classification", beneficiaryCategories.length ? beneficiaryCategories.join(", ") : "-");
      setModalText("modal-confirm-contact", contactNumber);
      setModalText("modal-confirm-beneficiary-occupation", beneficiaryOccupation);
      setModalText("modal-confirm-beneficiary-income", beneficiaryIncome);
      setModalText("modal-confirm-applicant-occupation", applicantOccupation);
      setModalText("modal-confirm-applicant-income", applicantIncome);
      setModalText("modal-confirm-family", familySummary);
      setModalText("modal-confirm-beneficiary-address", beneficiaryAddress);
      setModalText("modal-confirm-applicant-address", applicantAddress);
      setModalText("modal-confirm-docs",
        isEditMode
          ? `${uploadedDocsCount} new document${uploadedDocsCount === 1 ? "" : "s"} to upload, ${lockedDocsCount} locked by PSWDO`
          : `${uploadedDocsCount} document${uploadedDocsCount === 1 ? "" : "s"} attached`
      );

      // Remove any leftover error banner before showing the confirm modal
      confirmModal.querySelector(".submit-error-banner")?.remove();

      // 3. Show Modal
      openSubmitConfirmModal();
      } catch (populateError) {
        // If modal population fails for any reason, still show the modal
        openSubmitConfirmModal();
      }
    }
  });

  // 4. Execute the Actual Database Submission
  if (finalSubmitBtn) {
    finalSubmitBtn.addEventListener("click", async () => {
      finalSubmitBtn.disabled = true;
      finalSubmitBtn.innerHTML =
        '<i class="fas fa-cloud-arrow-up" aria-hidden="true"></i> <span data-submit-progress-text>Uploading your documents…</span>';
      if (cancelSubmitBtn) cancelSubmitBtn.style.display = "none"; // Hide cancel so they don't click it while loading
      const uploadBar = document.createElement("div");
      uploadBar.className = "submit-upload-progress";
      uploadBar.setAttribute("role", "progressbar");
      uploadBar.setAttribute("aria-label", "Upload progress");
      uploadBar.setAttribute("aria-valuemin", "0");
      uploadBar.setAttribute("aria-valuemax", "100");
      uploadBar.innerHTML = "<span></span>";
      finalSubmitBtn.closest(".confirm-modal-actions")?.before(uploadBar);
      submitUploadProgress = (fraction) => {
        const percent = Math.max(0, Math.min(100, Math.round(fraction * 100)));
        uploadBar.style.setProperty("--upload", `${percent}%`);
        uploadBar.setAttribute("aria-valuenow", String(percent));
        const text = finalSubmitBtn.querySelector("[data-submit-progress-text]");
        if (text) text.textContent = percent >= 100 ? "Saving your application…" : `Uploading your documents… ${percent}%`;
      };

      try {
        // Send data to save-request.php
        const payload = await submitFormData();
        if (!isEditMode) {
          // The server has consumed the draft. Clear only this wizard's
          // transient state so a redirect/refresh cannot rediscover it.
          if (draftTimer) window.clearTimeout(draftTimer);
          draftState = { id: "", version: 0, ready: true, saving: false };
          if (draftIdInput) draftIdInput.value = "";
          window.AICS_DRAFT_DOCUMENTS = {};
          ["aics_active_draft_id", "aics_application_draft_id", "aics_draft_id"].forEach((key) => {
            try { window.localStorage.removeItem(key); window.sessionStorage.removeItem(key); } catch (_) { /* storage may be unavailable */ }
          });
          clearLocalDraftCache();
          if (draftResumePanel) draftResumePanel.hidden = true;
        }
        setFeedback(
          isEditMode
            ? "Request updated successfully. Redirecting..."
            : "Request submitted successfully. Redirecting...",
          "success",
        );

        // --- NEW POLISHED SUCCESS UI ---
        document.querySelector(
          "#submit-confirm-modal .confirm-modal-body",
        ).innerHTML = `
          <div style="text-align: center; padding: 30px 10px;">
            <i class="fas fa-check-circle" style="font-size: 4rem; color: #10b981; margin-bottom: 16px;"></i>
            <h3 style="color: #0f172a; font-size: 1.5rem; margin-bottom: 8px; font-weight: 700;">${isEditMode ? "Request Updated!" : "Application Submitted!"}</h3>
            <p style="color: #475569; font-size: 1.05rem; margin-bottom: 24px;">Reference No: <strong style="color: #0f172a;">${payload.reference_no}</strong></p>
            <div style="background: #f1f5f9; padding: 12px; border-radius: 6px; color: #64748b; font-size: 0.9rem; font-weight: 500;">
              <i class="fas fa-spinner fa-spin" style="margin-right: 5px;"></i> Opening your application status, where you can watch AICS AI check your documents...
            </div>
          </div>
        `;

        // The server response above is authoritative; keep only a brief cosmetic success state.
        setTimeout(() => {
          window.location.href = "../dashboard.php?page=status";
        }, 900);
      } catch (error) {
        const message =
          error instanceof Error ? error.message : "Unknown error.";
        setFeedback(`Submission failed: ${message}`, "error");
        submitUploadProgress = null;
        document.querySelector("#submit-confirm-modal .submit-upload-progress")?.remove();

        // Reset the submit button so the user can retry
        finalSubmitBtn.disabled = false;
        finalSubmitBtn.innerHTML = finalSubmitDefaultHtml;
        if (cancelSubmitBtn) cancelSubmitBtn.style.display = "inline-block";

        // Show an error banner at the top of the modal WITHOUT destroying the modal content
        const modalBody = document.querySelector("#submit-confirm-modal .confirm-modal-body");
        if (modalBody) {
          const existing = modalBody.querySelector(".submit-error-banner");
          if (existing) existing.remove();
          const banner = document.createElement("div");
          banner.className = "submit-error-banner";
          banner.style.cssText = "background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px;";
          banner.innerHTML = `
            <i class="fas fa-circle-xmark" style="color:#ef4444;font-size:1.2rem;flex-shrink:0;margin-top:2px;"></i>
            <div style="flex:1;">
              <div style="font-weight:700;color:#b91c1c;margin-bottom:2px;">Submission Failed</div>
              <div style="color:#991b1b;font-size:0.9rem;">${message}</div>
            </div>
            <button type="button" class="submit-error-close" style="background:none;border:none;color:#b91c1c;font-size:1.1rem;cursor:pointer;flex-shrink:0;padding:0;"><i class="fas fa-times"></i></button>
          `;
          banner.querySelector(".submit-error-close").addEventListener("click", () => banner.remove());
          modalBody.insertBefore(banner, modalBody.firstChild);
        }
      }
    });
  }

  // ---- Dynamic Family Composition Rows ----
  const familyOptions = window.AICS_FAMILY_OPTIONS && typeof window.AICS_FAMILY_OPTIONS === "object"
    ? window.AICS_FAMILY_OPTIONS
    : { occupation: [], education: [], income: [] };

  function createFamilySelect(name, placeholder, options) {
    const select = document.createElement("select");
    select.name = name;
    select.className = "family-row-input";
    const initial = document.createElement("option");
    initial.value = "";
    initial.textContent = placeholder;
    initial.disabled = true;
    initial.hidden = true;
    initial.selected = true;
    select.appendChild(initial);
    (Array.isArray(options) ? options : []).forEach((option) => {
      const item = document.createElement("option");
      item.value = String(option);
      item.textContent = String(option);
      select.appendChild(item);
    });
    return select;
  }

  function labelFamilyCompositionRows() {
    const container = document.getElementById('family-composition-body');
    if (!container) return;
    container.querySelectorAll('.family-mobile-label').forEach((label) => label.remove());
    const labels = ['Name', 'Age', 'Relationship to Beneficiary', 'Occupation', 'Educational Attainment', 'Estimated Monthly Income'];
    const cards = Array.from(container.querySelectorAll(':scope > .family-member-card'));
    cards.forEach((card, cardIndex) => {
      const rowNumber = cardIndex + 1;
      const title = card.querySelector('.family-member-title');
      if (title) title.textContent = `Family member ${rowNumber}`;
      const fields = Array.from(card.children).filter((item) =>
        !item.classList.contains('family-member-card-header') && !item.classList.contains('family-mobile-label')
      );
      fields.forEach((field, fieldIndex) => {
        const fieldLabel = labels[fieldIndex] || `Field ${fieldIndex + 1}`;
        field.setAttribute('data-family-field-label', fieldLabel);
        const mobileLabel = document.createElement('span');
        mobileLabel.className = 'family-mobile-label';
        mobileLabel.textContent = fieldLabel;
        field.before(mobileLabel);
        const controls = field.matches('input, select') ? [field] : Array.from(field.querySelectorAll('input, select'));
        controls.forEach((control) => control.setAttribute('aria-label', `Family member ${rowNumber} ${fieldLabel}`));
      });
    });
    const onlyOne = cards.length <= 1;
    cards.forEach((card) => {
      const remove = card.querySelector('[data-action="remove-family-member"]');
      if (!(remove instanceof HTMLButtonElement)) return;
      remove.disabled = onlyOne;
      remove.setAttribute('aria-disabled', String(onlyOne));
      remove.title = onlyOne ? 'At least one family member is required' : 'Remove this family member';
    });
  }

  function createFamilyMemberCard(fields) {
    const card = document.createElement('div');
    card.className = 'family-member-card';
    const header = document.createElement('div');
    header.className = 'family-member-card-header';
    header.innerHTML = '<strong class="family-member-title">Family member</strong><button type="button" class="family-remove-btn" data-action="remove-family-member"><i class="fas fa-trash" aria-hidden="true"></i><span>Remove</span></button>';
    card.append(...fields, header);
    return card;
  }

  function normalizeFamilyCompositionRows() {
    const container = document.getElementById('family-composition-body');
    if (!container || container.querySelector(':scope > .family-member-card')) return;
    const fields = Array.from(container.children).filter((item) => !item.classList.contains('family-head'));
    for (let index = 0; index < fields.length; index += 6) {
      const rowFields = fields.slice(index, index + 6);
      if (rowFields.length) container.appendChild(createFamilyMemberCard(rowFields));
    }
  }

  function updateFamilyOccupationOtherField(select) {
    const wrapper = select.closest(".family-occupation-wrap");
    const other = wrapper ? wrapper.querySelector('input[name="family_occupation_other[]"]') : null;
    if (!(other instanceof HTMLInputElement)) return;
    const isOther = select.value === "Other";
    other.hidden = !isOther;
    other.required = isOther;
    if (!isOther) other.value = "";
  }

  function addFamilyRow() {
    const tbody = document.getElementById("family-composition-body");
    if (!tbody) return;
    const occupationWrap = document.createElement("div");
    occupationWrap.className = "family-occupation-wrap";
    const occupation = createFamilySelect("family_occupation[]", "Select occupation", familyOptions.occupation);
    occupation.classList.add("family-occupation-select");
    const occupationOther = Object.assign(document.createElement("input"), {
      type: "text", name: "family_occupation_other[]", placeholder: "Specify occupation", className: "family-occupation-other", maxLength: 150, hidden: true,
    });
    occupationWrap.append(occupation, occupationOther);
    const inputs = [
      Object.assign(document.createElement("input"), {
        type: "text", name: "family_name[]", placeholder: "Full name (e.g. Maria Dela Cruz)", className: "family-row-input",
      }),
      Object.assign(document.createElement("input"), {
        type: "number", name: "family_age[]", placeholder: "Age", min: "0", max: "120", className: "family-row-input numeric-only", inputMode: "numeric",
      }),
      createFamilySelect("family_relationship[]", "Relationship", ["Spouse", "Child", "Parent", "Sibling", "Grandparent", "Grandchild", "Other Relative", "Non-relative"]),
      occupationWrap,
      createFamilySelect("family_education[]", "Select educational attainment", familyOptions.education),
      createFamilySelect("family_estimated_income[]", "Select estimated monthly income", familyOptions.income),
    ];
    tbody.appendChild(createFamilyMemberCard(inputs));
    labelFamilyCompositionRows();
    queueDraftSave();
  }

  const familyAddBtn = document.getElementById("family-add-row-btn");
  const familyNotApplicable = document.getElementById("family-members-not-applicable");
  const familyEntryControls = document.getElementById("family-entry-controls");
  function setFamilyNotApplicable(active) {
    if (familyEntryControls instanceof HTMLElement) {
      familyEntryControls.hidden = active;
      familyEntryControls.querySelectorAll("input, select, button").forEach((field) => { field.disabled = active; });
    }
    queueDraftSave();
  }
  if (familyNotApplicable instanceof HTMLInputElement) {
    familyNotApplicable.addEventListener("change", () => setFamilyNotApplicable(familyNotApplicable.checked));
  }
  if (familyAddBtn) {
    familyAddBtn.addEventListener("click", addFamilyRow);
  }
  const familyBody = document.getElementById("family-composition-body");
  if (familyBody) {
    normalizeFamilyCompositionRows();
    familyBody.addEventListener("change", (event) => {
      if (event.target instanceof HTMLSelectElement && event.target.name === "family_occupation[]") {
        updateFamilyOccupationOtherField(event.target);
      }
    });
    familyBody.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-action="remove-family-member"]');
      if (!(remove instanceof HTMLButtonElement) || remove.disabled) return;
      remove.closest('.family-member-card')?.remove();
      labelFamilyCompositionRows();
      queueDraftSave();
    });
    familyBody.querySelectorAll('select[name="family_occupation[]"]').forEach(updateFamilyOccupationOtherField);
    labelFamilyCompositionRows();
  }

  // In edit mode, expand rows to fit saved data before populating
  function ensureFamilyRowsForEdit(count) {
    const nameInputs = form.querySelectorAll('input[name="family_name[]"]');
    let existing = nameInputs.length;
    while (existing < count) {
      addFamilyRow();
      existing += 1;
    }
  }

  function applyProfileDefaults() {
    const p = window.AICS_PROFILE_DEFAULTS && typeof window.AICS_PROFILE_DEFAULTS === "object" ? window.AICS_PROFILE_DEFAULTS : {};
    const setProfileValue = (name, value) => {
      const field = form.querySelector(`[name="${name}"]`);
      if (field && value !== undefined && value !== null) field.value = displayValue(value);
    };
    // Step 1 belongs exclusively to the beneficiary. Never preload its
    // contact, occupation, income, or other personal data from the applicant.
    // Only Step 2 fields below may be sourced from the authenticated profile.
    setProfileValue("applicant_first_name", p.first_name);
    setProfileValue("applicant_middle_name", p.middle_name);
    setProfileValue("applicant_last_name", p.last_name);
    setProfileValue("applicant_extension", p.extension_name);
    setProfileValue("applicant_birth_date", p.date_of_birth);
    setProfileValue("civil_status", p.civil_status);
    setProfileValue("highest_educational_attainment", p.highest_educational_attainment);
    setProfileValue("applicant_occupation", p.occupation);
    setProfileValue("applicant_estimated_monthly_income", p.estimated_monthly_income);
    setProfileValue("applicant_contact_number", p.contact_number);
    setProfileValue("applicant_email", p.email);
    syncApplicantAddressPresentation(p);
    if (applicantBirthDate && applicantAge) applicantAge.value = computeAgeFromBirthDate(applicantBirthDate.value);
  }

  function syncApplicantAddressPresentation(source) {
    const values = source && typeof source === "object" ? source : {};
    const addressLine = displayValue(values.sitio_purok ?? values.address_line).trim();
    const province = displayValue(values.province).trim();
    const municipality = displayValue(values.municipality).trim();
    const barangay = displayValue(values.barangay).trim();
    const setPresentationValue = (id, value) => {
      const field = document.getElementById(id);
      if (field instanceof HTMLInputElement) field.value = value || "-";
    };

    setPresentationValue("applicant-address-province", province);
    setPresentationValue("applicant-address-municipality", municipality);
    setPresentationValue("applicant-address-barangay", barangay);
    setPresentationValue("applicant-address-line", addressLine);
    setPresentationValue(
      "applicant-complete-address",
      [addressLine, barangay, municipality, province].filter(Boolean).join(", "),
    );
  }

  updateDerivedBeneficiaryName();
  updateClaimStatusFields();
  if (formBootstrap) {
    const familyRows = Array.isArray(AICS_EDIT_CONTEXT.formValues.family_composition)
      ? AICS_EDIT_CONTEXT.formValues.family_composition
      : [];
    if (familyRows.length > 2) {
      ensureFamilyRowsForEdit(familyRows.length);
    }
    applyEditBootstrapValues();
  }
  // Apply after a saved-request bootstrap too: request/draft values are never
  // an authority for the authenticated applicant's identity or address.
  if (!isEditMode) applyProfileDefaults();
  // Step 2 is always the authenticated applicant profile. Step 1 stays
  // beneficiary-only and is never prefilled from that profile.
  setApplicantFieldsDisabled(true);
  updateDynamicUploadFields();
  updateConditionalFields();
  enforceReadOnlyApplicationMode();
  updateCaseDocumentField();
  updateUploadDropTitle(
    certificateOfIndigencyInput,
    certificateOfIndigencyTitle,
  );
  updateUploadDropTitle(validIdInput, validIdTitle);
  updateUploadDropTitle(caseDocumentInput, caseDocumentTitle);
  if (isEditMode && AICS_EDIT_CONTEXT.referenceNo) {
    setFeedback(
      `Editing request ${AICS_EDIT_CONTEXT.referenceNo}. Checked documents cannot be replaced.`,
      "success",
    );
  }
  updateStepUI();
  loadDraft();
}

initLogoutConfirm();
initApplicantIntakeForm();
async function initApplicantIdentity() {
  const body = document.body;
  const bootStatusCode = String(body?.getAttribute("data-account-status") || "")
    .trim()
    .toLowerCase();
  const bootBannedRequired = String(
    body?.getAttribute("data-banned-modal-required") || "0",
  ).trim() === "1";
  if (bootStatusCode === "banned" || bootBannedRequired) {
    const displayNameFromChip =
      document.querySelector(".profile-chip span")?.textContent || "Applicant";
    openBannedAccessModal(displayNameFromChip);
  }

  try {
    const response = await fetch("auth-me.php", { method: "GET" });
    if (!response.ok) {
      return;
    }

    const payload = await response.json();
    if (!payload || payload.ok !== true) {
      return;
    }

    const displayName = payload.display_name || "Applicat";
    const statusCode = String(payload.status_code || "active").trim().toLowerCase();
    const bannedModalRequired = payload.banned_modal_required === true;
    const initials = displayName
      .split(" ")
      .filter(Boolean)
      .map((part) => part[0])
      .slice(0, 2)
      .join("")
      .toUpperCase();
    document.querySelectorAll(".profile-chip span").forEach((el) => {
      el.textContent = displayName;
    });
    document.querySelectorAll(".profile-av").forEach((el) => {
      el.textContent = initials || "AP";
    });
    const title = document.querySelector(".page-title");
    if (title && /welcome back/i.test(title.textContent)) {
      title.textContent = `Welcome back, ${displayName}!`;
    }

    if (statusCode === "banned" || bannedModalRequired) {
      openBannedAccessModal(displayName);
    }
  } catch (error) {
    // ignore optional profile sync errors
  }
}

initApplicantIdentity();
function initApplicantDashboard() {
  const root = document.querySelector('[data-dashboard-root="true"]');
  if (!root) {
    return;
  }

  const endpoint = root.getAttribute("data-endpoint") || "dashboard-data.php";
  const dashboardUrl =
    root.getAttribute("data-dashboard-url") || "../dashboard.php";
  const bootstrapElement = document.getElementById("dashboard-bootstrap");
  let refreshTimer = null;
  let isRefreshing = false;
  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function setFieldText(field, value) {
    const target = root.querySelector(`[data-dashboard-field="${field}"]`);
    if (target) {
      target.textContent = value;
    }
  }

  function setFieldHtml(field, html) {
    const target = root.querySelector(`[data-dashboard-field="${field}"]`);
    if (target) {
      target.innerHTML = html;
    }
  }

  function setKpiTone(kpi, tone) {
    const card = root.querySelector(`[data-dashboard-kpi="${kpi}"]`);
    if (!card) return;
    card.classList.remove("tone-card-info", "tone-card-success", "tone-card-warning", "tone-card-neutral");
    card.classList.add(`tone-card-${tone}`);
  }

  function buildSnapshotMarkup(latestRequest) {
    if (!latestRequest) {
      return `
        <div class="panel-empty panel-empty-senior">
          <i class="fas fa-folder-open"></i>
          <strong>No request yet</strong>
          <p>When you submit your first AICS request, all the details will appear here so you can easily follow its progress.</p>
        </div>
      `;
    }

    const locationParts = [
      latestRequest.barangay,
      latestRequest.municipality,
    ].filter(Boolean);

    const statusCode = String(latestRequest.status_code || "").toLowerCase();
    let stepIndex = 1;
    if (['under_review', 'under_assessment', 'for_interview', 'for interview'].includes(statusCode)) {
      stepIndex = 2;
    } else if (['approved', 'validated', 'for_release', 'for release', 'scheduled'].includes(statusCode)) {
      stepIndex = 3;
    } else if (['released', 'distributed', 'completed', 'closed'].includes(statusCode)) {
      stepIndex = 4;
    }

    const progressWidth = stepIndex === 1 ? '0%' : (stepIndex === 2 ? '33%' : (stepIndex === 3 ? '66%' : '100%'));

    const step1Class = stepIndex > 1 ? 'is-completed' : (stepIndex === 1 ? 'is-active' : '');
    const step2Class = stepIndex > 2 ? 'is-completed' : (stepIndex === 2 ? 'is-active' : '');
    const step3Class = stepIndex > 3 ? 'is-completed' : (stepIndex === 3 ? 'is-active' : '');
    const step4Class = stepIndex === 4 ? 'is-completed is-active' : '';

    return `
      <div class="request-stepper-wrap">
        <div class="stepper-track-bar">
          <span class="stepper-progress-fill" style="width: ${progressWidth};"></span>
        </div>
        <div class="stepper-steps-container">
          <div class="stepper-step ${step1Class}">
            <div class="stepper-node-circle">
              <i class="fas ${stepIndex > 1 ? 'fa-check' : 'fa-paper-plane'}"></i>
            </div>
            <div>
              <div class="stepper-step-title">Submitted</div>
              <div class="stepper-step-caption">Application Received</div>
            </div>
          </div>
          <div class="stepper-step ${step2Class}">
            <div class="stepper-node-circle">
              <i class="fas ${stepIndex > 2 ? 'fa-check' : 'fa-magnifying-glass'}"></i>
            </div>
            <div>
              <div class="stepper-step-title">Under Review</div>
              <div class="stepper-step-caption">Officer Assessment</div>
            </div>
          </div>
          <div class="stepper-step ${step3Class}">
            <div class="stepper-node-circle">
              <i class="fas ${stepIndex > 3 ? 'fa-check' : 'fa-certificate'}"></i>
            </div>
            <div>
              <div class="stepper-step-title">Approved</div>
              <div class="stepper-step-caption">Assistance Granted</div>
            </div>
          </div>
          <div class="stepper-step ${step4Class}">
            <div class="stepper-node-circle">
              <i class="fas ${stepIndex === 4 ? 'fa-check-double' : 'fa-hand-holding-heart'}"></i>
            </div>
            <div>
              <div class="stepper-step-title">Released</div>
              <div class="stepper-step-caption">Distribution / Paid</div>
            </div>
          </div>
        </div>
      </div>

      <div class="snapshot-grid-executive">
        <div class="snapshot-box-executive">
          <div class="snapshot-box-header"><i class="fas fa-hashtag"></i> Reference Number</div>
          <div class="snapshot-box-value">
            <span class="snapshot-ref-pill">${escapeHtml(latestRequest.reference_no || "-")}</span>
          </div>
        </div>
        <div class="snapshot-box-executive">
          <div class="snapshot-box-header"><i class="fas fa-hand-holding-heart"></i> Type of Assistance</div>
          <div class="snapshot-box-value">${escapeHtml(latestRequest.assistance_label || "Assistance request")}</div>
        </div>
        <div class="snapshot-box-executive">
          <div class="snapshot-box-header"><i class="fas fa-calendar-day"></i> Date Applied</div>
          <div class="snapshot-box-value">${escapeHtml(latestRequest.date_applied_label || "Not available")}</div>
        </div>
        <div class="snapshot-box-executive">
          <div class="snapshot-box-header"><i class="fas fa-circle-check"></i> Claim Status</div>
          <div class="snapshot-box-value">${escapeHtml(latestRequest.claim_status_label || "Unclaimed")}</div>
        </div>
        <div class="snapshot-box-executive">
          <div class="snapshot-box-header"><i class="fas fa-user"></i> Beneficiary</div>
          <div class="snapshot-box-value">${escapeHtml(latestRequest.beneficiary_name || "-")}</div>
        </div>
        <div class="snapshot-box-executive">
          <div class="snapshot-box-header"><i class="fas fa-location-dot"></i> Location</div>
          <div class="snapshot-box-value">${escapeHtml(locationParts.join(", ") || "-")}</div>
        </div>
        <div class="snapshot-box-executive">
          <div class="snapshot-box-header"><i class="fas fa-clock-rotate-left"></i> Last Updated</div>
          <div class="snapshot-box-value">${escapeHtml(latestRequest.updated_at_label || "Not available")}</div>
        </div>
      </div>

      <div class="snapshot-grid-footer">
        <a class="btn-track-full" href="${escapeHtml(`${dashboardUrl}?page=status`)}">
          <i class="fas fa-route"></i>
          <span>Track Detailed Status</span>
        </a>
      </div>
    `;
  }

  function buildDocumentsMarkup(latestRequest) {
    if (
      !latestRequest ||
      !Array.isArray(latestRequest.required_documents) ||
      latestRequest.required_documents.length === 0
    ) {
      return `
        <div class="panel-empty compact document-empty-state">
          <i class="fas fa-folder-plus"></i>
          <strong>No case requirements yet</strong>
          <p>Once your first request is submitted, this area will switch from 
empty state to live document tracking.</p>
        </div>
      `;
    }

    return latestRequest.required_documents
      .map((document) => {
        const uploaded = Boolean(document.uploaded);
        return `
        <div class="document-check-item">
          <div class="document-check-copy">
            <strong>${escapeHtml(document.label || "Document")}</strong>
            <span>${escapeHtml(uploaded ? `Uploaded ${document.label || "document"}` : "Missing from your latest request")}</span>
          </div>
 
         <span class="badge ${uploaded ? "ok" : "pending"}">${uploaded ? "Uploaded" : "Needed"}</span>
        </div>
      `;
      })
      .join("");
  }

  function buildActivityMarkup(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return `
        <div class="panel-empty compact panel-empty-senior">
          <i class="fas fa-stream"></i>
          <strong>Nothing new yet</strong>
          <p>When something happens with your requests, it will appear here.</p>
        </div>
      `;
    }

    return items
      .map(
        (item) => `
      <div class="activity-item-executive">
        <div class="activity-icon-executive tone-${escapeHtml(item.tone || "neutral")}">
          <i class="fas fa-${escapeHtml(item.icon || "circle-info")}"></i>
        </div>
        <div class="activity-copy-executive">
          <strong>${escapeHtml(item.title || "Update")}</strong>
          <p>${escapeHtml(item.description || "")}</p>
        </div>
        <div class="activity-time-executive">
          <i class="fas fa-clock"></i>
          ${escapeHtml(item.time_label || "")}
        </div>
      </div>
    `,
      )
      .join("");
  }

  function buildNextStepsMarkup(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return `
        <a class="next-step-item" href="${escapeHtml(`${dashboardUrl}?page=overview`)}">
          <div class="next-step-copy">
            <strong>Dashboard looks up to date</strong>
            <p>Your account has no urgent actions right now.
Keep checking for new updates.</p>
          </div>
          <span>Open</span>
        </a>
      `;
    }

    return items
      .map(
        (item) => `
      <a class="next-step-item" href="${escapeHtml(`${dashboardUrl}?page=${item.page || "overview"}`)}">
        <div class="next-step-copy">
          <strong>${escapeHtml(item.title || "Next step")}</strong>
          <p>${escapeHtml(item.description || "")}</p>
        </div>
        <span>${escapeHtml(item.action_label || "Open")}</span>
      </a>
    `,
      )

      .join("");
  }

  function applyDashboardState(payload) {
    if (!payload || typeof payload !== "object") {
      return;
    }

    const profile =
      payload.profile && typeof payload.profile === "object"
        ? payload.profile
        : {};
    const summary =
      payload.summary && typeof payload.summary === "object"
        ? payload.summary
        : {};
    const latestRequest =
      payload.latest_request && typeof payload.latest_request === "object"
        ? payload.latest_request
        : null;
    const activity = Array.isArray(payload.recent_activity)
      ? payload.recent_activity
      : [];
    const nextSteps = Array.isArray(payload.next_steps)
      ? payload.next_steps
      : [];

    const displayName = profile.display_name || "Applicant";
    const heroTitle = latestRequest
      ? `Welcome back, ${displayName}!`
      : `Welcome, ${displayName}!`;
    const heroSubtitle = latestRequest
      ? "Track your current AICS request, document readiness, and the latest activity on your account."
      : "Your dashboard is ready. Complete your profile and submit a request to start receiving live AICS updates.";

    setFieldText("hero-title", heroTitle);
    setFieldText("hero-subtitle", heroSubtitle);
    setFieldText(
      "status-label",
      latestRequest
        ? latestRequest.status_label || "Pending Review"
        : "No active request",
    );
    setFieldText(
      "reference-chip",
      latestRequest
        ? latestRequest.reference_no || "No reference yet"
        : "No reference yet",
    );
    const syncedAt = payload.generated_at
      ? new Date(payload.generated_at)
      : new Date();
    setFieldText(
      "synced-at",
      `Synced ${Number.isNaN(syncedAt.getTime()) ? "just now" : syncedAt.toLocaleString("en-PH", { month: "long", day: "2-digit", year: "numeric", hour: "numeric", minute: "2-digit" })}`,
    );
    const statusPill = root.querySelector(
      '[data-dashboard-field="status-pill"]',
    );
    if (statusPill) {
      const statusCode = String(latestRequest ? latestRequest.status_code || "" : "").toLowerCase();
      const statusTone = latestRequest ? latestRequest.status_tone || "neutral" : "neutral";
      statusPill.className = `status-pill-executive status-${escapeHtml(statusCode)} status-${escapeHtml(statusTone)}`;
      statusPill.innerHTML = `
        <i class="fas fa-${escapeHtml(latestRequest ? latestRequest.status_icon || "circle-info" : "circle-info")}"></i>
        <span data-dashboard-field="status-label">${escapeHtml(latestRequest ? latestRequest.status_label || "No active request" : "No active request")}</span>
      `;
    }

    const primaryAction = root.querySelector(
      '[data-dashboard-action="primary"]',
    );
    const canSubmitRequest = profile.is_profile_complete === true;
    if (primaryAction) {
      primaryAction.innerHTML = `
        <i class="fas fa-${latestRequest ? "route" : "file-signature"}"></i>
        <span>${latestRequest ? "Track Request" : "Submit Application"}</span>
      `;
      if (!latestRequest && !canSubmitRequest) {
        primaryAction.removeAttribute("href");
        primaryAction.setAttribute("aria-disabled", "true");
        primaryAction.setAttribute("title", "Complete your profile before submitting a request.");
        primaryAction.classList.add("is-disabled");
      } else {
        primaryAction.setAttribute(
          "href",
          `${dashboardUrl}?page=${latestRequest ? "status" : "submit"}`,
        );
        primaryAction.removeAttribute("aria-disabled");
        primaryAction.removeAttribute("title");
        primaryAction.classList.remove("is-disabled");
      }

    }
    setSubmitRequestAvailability(canSubmitRequest);

    const secondaryAction = root.querySelector(
      '[data-dashboard-action="secondary"]',
    );
    if (secondaryAction) {
      const canSubmitRequest = profile.is_profile_complete === true;
      secondaryAction.innerHTML = `
        <i class="fas fa-${latestRequest ? "folder-open" : "user-check"}"></i>
        <span>${latestRequest ? "Manage Documents" : "Complete Profile"}</span>
      `;
      secondaryAction.setAttribute(
        "href",
        `${dashboardUrl}?page=${latestRequest ? "documents" : "profile"}`,
      );
      secondaryAction.style.display = !latestRequest && canSubmitRequest ? "none" : "";
    }

    setFieldText("metric-active", String(summary.active_requests ?? 0));
    setFieldText("metric-total", String(summary.total_requests ?? 0));
    setFieldText(
      "metric-documents",
      summary.documents_progress_label || "0 / 0",
    );
    setFieldText("metric-actions", String(summary.action_items ?? 0));
    const requiredDocuments = Number(summary.required_documents ?? 0);
    const uploadedDocuments = Number(summary.documents_uploaded ?? 0);
    setKpiTone(
      "documents",
      requiredDocuments <= 0
        ? "neutral"
        : uploadedDocuments >= requiredDocuments
          ? "success"
          : "warning",
    );
    setKpiTone("actions", Number(summary.action_items ?? 0) > 0 ? "warning" : "neutral");

    const kpiMiniProgress = root.querySelector('[data-dashboard-kpi="documents"] .kpi-mini-progress-bar');
    if (kpiMiniProgress) {
      const docPct = requiredDocuments > 0 ? Math.min(100, Math.round((uploadedDocuments / requiredDocuments) * 100)) : 0;
      kpiMiniProgress.style.width = `${docPct}%`;
    }

    setFieldText(
      "snapshot-subtitle",
      latestRequest
        ? latestRequest.status_description || "Latest request loaded."
        : "No AICS request has been submitted from this account yet.",
    );
    setFieldHtml("snapshot-body", buildSnapshotMarkup(latestRequest));

    const panelHead = root.querySelector(
      '[data-dashboard-field="documents-progress"]',
    )?.parentElement;
    const existingProgressChip = root.querySelector(
      '[data-dashboard-field="documents-progress"]',
    );
    if (latestRequest) {
      if (!existingProgressChip && panelHead) {
        const chip = document.createElement("span");
        chip.className = "section-chip";
        chip.setAttribute("data-dashboard-field", "documents-progress");
        panelHead.appendChild(chip);
      }
      setFieldText(
        "documents-progress",
        latestRequest.documents_progress_label || "0 / 0",
      );
    } else if (existingProgressChip) {
      existingProgressChip.remove();
    }

    setFieldHtml(
      "documents-shell",
      latestRequest
        ? `
          <div class="document-progress-shell">
            <div class="progress-track dashboard-progress">
              <span data-dashboard-field="documents-progress-bar"></span>
            </div>
            <div class="progress-meta" data-dashboard-field="documents-meta">
         
     ${escapeHtml(`${latestRequest.documents_uploaded_count || 0} uploaded out of ${latestRequest.required_documents_count || 0} required documents.`)}
            </div>
          </div>
        `
        : `
          <div class="document-empty-intro">
            <strong>Requirements will appear after your first application.</strong>
            <p>Submit an AICS request first, 
then this panel will show the exact files needed for your case.</p>
            <a class="btn-sm btn-primary" href="${escapeHtml(`${dashboardUrl}?page=submit`)}">Submit application</a>
          </div>
        `,
    );
    const progressBar = root.querySelector(
      '[data-dashboard-field="documents-progress-bar"]',
    );
    if (progressBar && latestRequest) {
      const uploaded = Number(latestRequest.documents_uploaded_count || 0);
      const required = Number(latestRequest.required_documents_count || 0);
      const width =
        required > 0
          ? Math.min(100, Math.round((uploaded / required) * 100))
          : 0;
      progressBar.style.width = `${width}%`;
    }

    setFieldHtml("documents-list", buildDocumentsMarkup(latestRequest));

    setFieldText(
      "recent-updates-chip",
      `${summary.recent_updates ?? 0} updates`,
    );
    setFieldHtml("activity-list", buildActivityMarkup(activity));
    setFieldHtml("next-steps-list", buildNextStepsMarkup(nextSteps));

    document.querySelectorAll(".profile-chip span").forEach((element) => {
      element.textContent = displayName;
    });
    const initials = profile.initials || "AP";
    document.querySelectorAll(".profile-av").forEach((element) => {
      element.textContent = initials;
    });
  }

  function readBootstrap() {
    if (!bootstrapElement) {
      return null;
    }

    try {
      return JSON.parse(bootstrapElement.textContent || "null");
    } catch (error) {
      return null;
    }
  }

  async function refreshDashboard() {
    if (isRefreshing) {
      return;
    }

    isRefreshing = true;
    try {
      const response = await fetch(endpoint, {
        method: "GET",
        headers: {
          Accept: "application/json",
        },
      });
      if (!response.ok) {
        return;
      }

      const payload = await response.json();
      if (!payload || payload.ok !== true || !payload.dashboard) {
        return;
      }

      applyDashboardState(payload.dashboard);
    } catch (error) {
      // Keep the last successful dashboard state on screen.
    } finally {
      isRefreshing = false;
    }
  }

  const initialState = readBootstrap();
  if (initialState) {
    applyDashboardState(initialState);
  }

  refreshDashboard();
  refreshTimer = window.setInterval(refreshDashboard, 8000);
  window.addEventListener("beforeunload", () => {
    if (refreshTimer !== null) {
      window.clearInterval(refreshTimer);
    }
  });
}

initApplicantDashboard();

// Application Status: while AICS AI checks a request, refresh its progress
// every few seconds; when the check finishes, reload to show the result.
(function initAiCheckProgress() {
  const cards = Array.from(document.querySelectorAll(".ai-check-live[data-progress-url]"));
  if (cards.length === 0) return;
  const icons = { done: "fas fa-circle-check", checking: "fas fa-circle-half-stroke", waiting: "far fa-circle" };
  const startedAt = Date.now();

  cards.forEach((card) => {
    const url = card.dataset.progressUrl;
    let timer = null;
    let delay = 4000;

    const render = (data) => {
      const checked = card.querySelector("[data-ai-checked]");
      const total = card.querySelector("[data-ai-total]");
      if (checked) checked.textContent = String(data.checked);
      if (total) total.textContent = String(data.total);
      const documents = Array.isArray(data.documents) ? data.documents : [];
      card.querySelector(".ai-check-track")?.replaceChildren(...documents.map((doc) => {
        const segment = document.createElement("span");
        segment.className = `ai-check-segment is-${doc.state}`;
        segment.style.setProperty("--fill", `${Number(doc.percent) || 0}%`);
        return segment;
      }));
      card.querySelector(".ai-check-list")?.replaceChildren(...documents.map((doc) => {
        const item = document.createElement("li");
        item.className = `ai-check-item is-${doc.state}`;
        const icon = document.createElement("i");
        icon.className = `ai-check-icon ${icons[doc.state] || icons.waiting}`;
        icon.setAttribute("aria-hidden", "true");
        const name = document.createElement("span");
        name.className = "ai-check-name";
        name.textContent = String(doc.label || "Document");
        const step = document.createElement("span");
        step.className = "ai-check-step";
        step.textContent = String(doc.status_text || "");
        item.append(icon, name, step);
        return item;
      }));
      const note = card.querySelector("[data-ai-note]");
      if (note && Date.now() - startedAt > 10 * 60 * 1000) {
        note.textContent = "This is taking longer than usual. You can close this page. We will send you a notification when the check is done.";
      }
    };

    const poll = async () => {
      timer = null;
      if (document.hidden) return;
      try {
        const response = await fetch(url, { headers: { Accept: "application/json" }, cache: "no-store", credentials: "same-origin" });
        const data = await response.json();
        if (!response.ok || !data || data.ok !== true) throw new Error("progress unavailable");
        if (data.state !== "pending") {
          window.location.reload();
          return;
        }
        render(data);
        delay = 4000;
      } catch (error) {
        delay = Math.min(delay * 2, 30000);
      }
      timer = window.setTimeout(poll, delay);
    };

    document.addEventListener("visibilitychange", () => {
      if (!document.hidden && timer === null) poll();
    });
    timer = window.setTimeout(poll, 1500);
  });
})();
