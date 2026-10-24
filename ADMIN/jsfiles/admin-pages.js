(() => {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("overlay");
  const toggleBtn = document.getElementById("toggle-btn");
  const closeBtn = document.getElementById("close-btn");
  const mainContent = document.getElementById("main-content");

  if (!sidebar || !overlay || !mainContent) {
    return;
  }

  const isMobile = () => window.innerWidth <= 900;

  const toggleSidebar = () => {
    if (isMobile()) {
      sidebar.classList.toggle("open");
      overlay.classList.toggle("active");
      return;
    }

    sidebar.classList.toggle("collapsed");
    mainContent.classList.toggle("expanded");
  };

  toggleBtn?.addEventListener("click", toggleSidebar);
  closeBtn?.addEventListener("click", toggleSidebar);

  overlay.addEventListener("click", () => {
    sidebar.classList.remove("open");
    overlay.classList.remove("active");
  });

  window.addEventListener("resize", () => {
    if (isMobile()) {
      sidebar.classList.remove("collapsed");
      mainContent.classList.remove("expanded");
      return;
    }

    sidebar.classList.remove("open");
    overlay.classList.remove("active");
  });
})();

(() => {
  const alerts = Array.from(document.querySelectorAll(".flash-alert"));
  if (alerts.length === 0) {
    return;
  }

  alerts.forEach((alert) => {
    window.setTimeout(() => {
      alert.classList.add("fade-out");
      window.setTimeout(() => {
        alert.remove();
      }, 240);
    }, 3600);
  });
})();

(() => {
  const toggles = Array.from(document.querySelectorAll("[data-password-toggle]"));
  if (toggles.length === 0) {
    return;
  }

  toggles.forEach((toggle) => {
    if (!(toggle instanceof HTMLButtonElement)) {
      return;
    }

    const targetId = String(toggle.getAttribute("data-target") || "").trim();
    const target = document.getElementById(targetId);
    const icon = toggle.querySelector("i");
    if (!(target instanceof HTMLInputElement) || !(icon instanceof HTMLElement)) {
      return;
    }

    toggle.addEventListener("click", () => {
      const shouldShow = target.type === "password";
      target.type = shouldShow ? "text" : "password";
      toggle.setAttribute("aria-pressed", shouldShow ? "true" : "false");
      toggle.setAttribute("aria-label", shouldShow ? "Hide password" : "Show password");
      icon.classList.toggle("fa-eye", !shouldShow);
      icon.classList.toggle("fa-eye-slash", shouldShow);
    });
  });
})();

(() => {
  let logoutTargetHref = "";
  let modal = document.getElementById("admin-logout-modal");

  function ensureAdminLogoutModal() {
    if (modal && document.body.contains(modal)) {
      return modal;
    }

    const existing = document.getElementById("admin-logout-modal");
    if (existing instanceof HTMLElement) {
      modal = existing;
      return modal;
    }

    const newModal = document.createElement("div");
    newModal.className = "admin-logout-modal";
    newModal.id = "admin-logout-modal";
    newModal.setAttribute("role", "dialog");
    newModal.setAttribute("aria-modal", "true");
    newModal.setAttribute("aria-labelledby", "admin-logout-title");
    newModal.innerHTML = `
      <div class="admin-logout-modal-backdrop" data-logout-action="cancel"></div>
      <div class="admin-logout-modal-card">
        <div class="admin-logout-icon"><i class="fas fa-right-from-bracket"></i></div>
        <h2 id="admin-logout-title">Log out of Admin Console?</h2>
        <p>Are you sure you want to end your current session? You can sign in again anytime with your admin credentials.</p>
        <div class="admin-logout-actions">
          <button type="button" class="admin-logout-btn-cancel" data-logout-action="cancel">Cancel</button>
          <button type="button" class="admin-logout-btn-confirm" data-logout-action="confirm">
            <i class="fas fa-right-from-bracket"></i>
            Yes, Log Out
          </button>
        </div>
      </div>
    `;
    document.body.appendChild(newModal);

    newModal.addEventListener("click", (event) => {
      const actionEl = event.target.closest("[data-logout-action]");
      if (!actionEl) {
        if (event.target === newModal) {
          closeLogoutModal();
        }
        return;
      }
      const action = actionEl.getAttribute("data-logout-action");
      if (action === "cancel") {
        closeLogoutModal();
      } else if (action === "confirm") {
        const target = logoutTargetHref || "phpfiles/auth-logout.php";
        closeLogoutModal();
        window.location.assign(target);
      }
    });

    modal = newModal;
    return modal;
  }

  function closeLogoutModal() {
    if (!modal) return;
    modal.classList.remove("open");
    document.body.classList.remove("modal-open");
    logoutTargetHref = "";
  }

  function openLogoutModal(href) {
    const m = ensureAdminLogoutModal();
    logoutTargetHref = String(href || "").trim();
    if (logoutTargetHref === "") {
      logoutTargetHref = "phpfiles/auth-logout.php";
    }
    m.classList.add("open");
    document.body.classList.add("modal-open");
    const cancelBtn = m.querySelector('[data-logout-action="cancel"]');
    if (cancelBtn instanceof HTMLButtonElement) {
      cancelBtn.focus();
    }
  }

  document.addEventListener("click", (event) => {
    const link = event.target.closest("a.logout-link, a[href*='auth-logout.php']");
    if (!link || !(link instanceof HTMLAnchorElement)) {
      return;
    }
    event.preventDefault();
    openLogoutModal(link.href);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape" || !modal || !modal.classList.contains("open")) {
      return;
    }
    closeLogoutModal();
  });
})();

(() => {
  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const submitter = "submitter" in event ? event.submitter : null;
    const submitElement = submitter instanceof HTMLElement ? submitter : null;

    const message =
      submitElement?.getAttribute("data-confirm") ||
      form.getAttribute("data-confirm") ||
      "";

    if (message !== "" && !window.confirm(message)) {
      event.preventDefault();
    }
  });
})();

(() => {
  if (!document.body.classList.contains("admin-login-page")) {
    return;
  }

  if (!window.history.replaceState) {
    return;
  }

  const url = new URL(window.location.href);
  if (!url.searchParams.has("auth") && !url.searchParams.has("retry")) {
    return;
  }

  url.searchParams.delete("auth");
  url.searchParams.delete("retry");
  window.history.replaceState({}, "", url.toString());
})();

(() => {
  const root = document.getElementById("admin-overview-root");
  if (!(root instanceof HTMLElement)) {
    return;
  }

  const tabButtons = Array.from(root.querySelectorAll(".dashboard-tab-btn"));
  const tabPanels = Array.from(root.querySelectorAll(".dashboard-tab-panel"));

  function activateTab(target) {
    tabButtons.forEach((button) => {
      if (!(button instanceof HTMLButtonElement)) return;
      button.classList.toggle("active", button.getAttribute("data-tab-target") === target);
    });

    tabPanels.forEach((panel) => {
      if (!(panel instanceof HTMLElement)) return;
      panel.classList.toggle("active", panel.getAttribute("data-tab-panel") === target);
    });
  }

  tabButtons.forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.addEventListener("click", () => {
      const target = (button.getAttribute("data-tab-target") || "").trim();
      if (target !== "") {
        activateTab(target);
      }
    });
  });

  const endpoint = (root.getAttribute("data-endpoint") || "").trim();
  if (endpoint === "") {
    return;
  }

  const periodControls = root.querySelector("#dashboard-period-controls") || root.querySelector("#activity-period-controls");
  const periodButtons = periodControls
    ? Array.from(periodControls.querySelectorAll(".overview-period-btn, .activity-period-btn"))
    : [];
  const refreshBtn = root.querySelector("#activity-refresh-btn");
  const periodLabel = root.querySelector("#dashboard-period-label") || root.querySelector("#activity-period-label");
  const periodRange = root.querySelector("#dashboard-period-range") || root.querySelector("#activity-period-range");
  const lastSync = root.querySelector("#activity-last-sync");
  const chartContainer = root.querySelector("#dashboard-overview-graph") || root.querySelector("#activity-chart");

  const metricKeys = [
    "registered_applicants",
    "submitted_requests",
    "pending_review",
    "interview_pending",
    "under_review",
    "pending_total",
    "approved_total",
    "not_approved_total",
    "released_total",
  ];

  if (
    !(refreshBtn instanceof HTMLButtonElement) ||
    !(periodLabel instanceof HTMLElement) ||
    !(periodRange instanceof HTMLElement) ||
    !(lastSync instanceof HTMLElement) ||
    !(chartContainer instanceof HTMLElement)
  ) {
    return;
  }

  let activePeriod = "month";
  const activePeriodButton = periodButtons.find(
    (btn) => btn instanceof HTMLButtonElement && btn.classList.contains("active")
  );
  if (activePeriodButton instanceof HTMLButtonElement) {
    const initialPeriod = (activePeriodButton.getAttribute("data-period") || "").trim().toLowerCase();
    if (["day", "week", "month"].includes(initialPeriod)) {
      activePeriod = initialPeriod;
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function formatCount(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
      return "0";
    }
    return Math.max(0, Math.round(number)).toLocaleString();
  }

  function formatPeriodDateRange(from, to) {
    if (!from || !to) return "";
    const f = new Date(`${from}T00:00:00`);
    const t = new Date(`${to}T00:00:00`);
    if (Number.isNaN(f.getTime()) || Number.isNaN(t.getTime())) {
      return from === to ? from : `${from} to ${to}`;
    }
    const opt = { month: "long", day: "2-digit", year: "numeric" };
    const fStr = f.toLocaleDateString("en-US", opt);
    const tStr = t.toLocaleDateString("en-US", opt);
    return from === to ? fStr : `${fStr} to ${tStr}`;
  }

  function setActivePeriod(period) {
    const next = String(period || "").trim().toLowerCase();
    activePeriod = ["day", "week", "month"].includes(next) ? next : "month";

    periodButtons.forEach((button) => {
      if (!(button instanceof HTMLButtonElement)) return;
      button.classList.toggle("active", button.getAttribute("data-period") === activePeriod);
    });
  }

  function updateMetrics(metrics) {
    metricKeys.forEach((key) => {
      const target = root.querySelector(`[data-activity-metric="${key}"]`);
      if (!(target instanceof HTMLElement)) return;
      target.textContent = formatCount(metrics && metrics[key] ? metrics[key] : 0);
    });
  }

  function renderChart(points, maxCount) {
    const list = Array.isArray(points) ? points : [];
    const submittedTotal = list.reduce((total, point) => {
      const count = Number(point && (point.submitted !== undefined ? point.submitted : point.submitted_requests) || 0);
      return total + (Number.isFinite(count) ? Math.max(0, count) : 0);
    }, 0);

    const trendTotalEl = root.querySelector("#dashboard-trend-total");
    if (trendTotalEl instanceof HTMLElement) {
      trendTotalEl.textContent = formatCount(submittedTotal);
    }

    if (list.length === 0 || submittedTotal === 0) {
      chartContainer.innerHTML = `
        <div class="overview-trend-empty">
          <span><i class="fas fa-chart-line"></i></span>
          <strong>No requests in this period</strong>
          <p>Choose another range to see more activity.</p>
        </div>
      `;
      return;
    }

    const visiblePoints = list.filter((point) => {
      const count = Number(point && (point.submitted !== undefined ? point.submitted : point.submitted_requests) || 0);
      return Number.isFinite(count) && count > 0;
    });

    const maxSubmittedFromPoints = list.reduce((max, point) => {
      const count = Number(point && (point.submitted !== undefined ? point.submitted : point.submitted_requests) || 0);
      return Number.isFinite(count) ? Math.max(max, count) : max;
    }, 0);
    const maxSubmitted = Math.max(1, Number(maxCount || 0), maxSubmittedFromPoints);
    const axisMax = Math.max(4, Math.ceil(maxSubmitted / 4) * 4);
    const axisLabels = [axisMax, axisMax * 0.75, axisMax * 0.5, axisMax * 0.25, 0]
      .map((value) => `<span>${escapeHtml(formatCount(value))}</span>`)
      .join("");

    const cols = visiblePoints
      .map((point) => {
        const label = (point && point.label ? point.label : "").toString().trim() || "-";
        const submitted = Math.max(0, Number(point && (point.submitted !== undefined ? point.submitted : point.submitted_requests) || 0));
        const stackHeight = submitted > 0 ? Math.max(8, Math.round((submitted / axisMax) * 100)) : 0;
        const title = `Submitted: ${formatCount(submitted)}`;

        return `
          <div class="overview-trend-col">
            <strong class="overview-trend-total">${submitted > 0 ? escapeHtml(formatCount(submitted)) : ""}</strong>
            <div class="overview-trend-stack-wrap">
              <div
                class="overview-trend-stack overview-trend-bar"
                style="height: ${escapeHtml(String(stackHeight))}%;"
                title="${escapeHtml(title)}"
                aria-label="${escapeHtml(title)}"
              ></div>
            </div>
            <span class="overview-trend-label">${escapeHtml(label)}</span>
          </div>
        `;
      })
      .join("");

    const colCount = visiblePoints.length;
    chartContainer.innerHTML = `
      <div class="overview-trend-visual">
        <div class="overview-trend-axis" aria-hidden="true">${axisLabels}</div>
        <div class="overview-trend-scroll">
          <div class="overview-trend-plot" style="grid-template-columns: repeat(${colCount}, minmax(58px, 1fr));">
            ${cols}
          </div>
        </div>
      </div>
    `;

    const scrollEl = chartContainer.querySelector(".overview-trend-scroll");
    if (scrollEl) scrollEl.scrollLeft = 0;
  }

  let isFetching = false;
  async function fetchOverviewAnalytics(period) {
    if (isFetching) return;
    isFetching = true;
    refreshBtn.disabled = true;

    const targetPeriod = String(period || activePeriod).trim().toLowerCase();
    setActivePeriod(targetPeriod);

    try {
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set("period", activePeriod);

      const response = await fetch(url.toString(), {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });

      if (!response.ok) {
        throw new Error("Unable to fetch overview analytics.");
      }

      const payload = await response.json();
      if (!payload || payload.ok !== true) {
        throw new Error(payload && payload.message ? String(payload.message) : "Overview analytics request failed.");
      }

      const periodMeta = payload.period && typeof payload.period === "object" ? payload.period : {};
      setActivePeriod(periodMeta.code || activePeriod);

      const periodLabelEl = root.querySelector("#dashboard-period-label");
      if (periodLabelEl instanceof HTMLElement) {
        periodLabelEl.textContent = String(periodMeta.label || "This Month");
      }

      const from = String(periodMeta.date_from || "").trim();
      const to = String(periodMeta.date_to || "").trim();
      const periodRangeEl = root.querySelector("#dashboard-period-range");
      if (periodRangeEl instanceof HTMLElement) {
        periodRangeEl.textContent = formatPeriodDateRange(from, to);
      }

      updateMetrics(payload.metrics || {});
      const trendMeta = payload.trend && typeof payload.trend === "object" ? payload.trend : {};
      renderChart(trendMeta.points || [], trendMeta.max_count || 1);
      lastSync.textContent = String(payload.generated_at_label || "Just now");
    } catch (error) {
      const message = error instanceof Error ? error.message : "Unable to fetch dashboard analytics.";
      window.console.error(message);
    } finally {
      isFetching = false;
      refreshBtn.disabled = false;
    }
  }

  refreshBtn.addEventListener("click", () => {
    fetchOverviewAnalytics(activePeriod);
  });

  periodButtons.forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.addEventListener("click", () => {
      const period = (button.getAttribute("data-period") || "").trim().toLowerCase();
      if (!["day", "week", "month"].includes(period)) return;
      fetchOverviewAnalytics(period);
    });
  });

  window.setInterval(() => {
    if (document.visibilityState === "visible") {
      fetchOverviewAnalytics(activePeriod);
    }
  }, 45000);
})();

(() => {
  const root = document.getElementById("user-management-root");
  if (!(root instanceof HTMLElement)) {
    return;
  }

  const pageScope = root.parentElement instanceof HTMLElement ? root.parentElement : document;
  const modalShells = Array.from(pageScope.querySelectorAll(".admin-modal-shell"));
  if (modalShells.length === 0) {
    return;
  }
  let pendingRestrictForm = null;

  function getModalById(id) {
    return modalShells.find((modal) => modal instanceof HTMLElement && modal.id === id);
  }

  function openModal(modalId) {
    const modal = getModalById(String(modalId || "").trim());
    if (!(modal instanceof HTMLElement)) {
      return;
    }

    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open");
    modal.dispatchEvent(new Event("admin:modal-open"));
  }

  function closeModal(modal) {
    if (!(modal instanceof HTMLElement)) {
      return;
    }
    modal.classList.remove("open");
    modal.setAttribute("aria-hidden", "true");
    modal.dispatchEvent(new Event("admin:modal-close"));
    if (modal.id === "modal-restrict-confirm") {
      pendingRestrictForm = null;
    }

    const anyOpen = modalShells.some((item) => item instanceof HTMLElement && item.classList.contains("open"));
    if (!anyOpen) {
      document.body.classList.remove("modal-open");
    }
  }

  function closeAllModals() {
    modalShells.forEach((modal) => {
      closeModal(modal);
    });
  }

  pageScope.querySelectorAll("[data-modal-open]").forEach((trigger) => {
    if (!(trigger instanceof HTMLElement)) return;
    trigger.addEventListener("click", () => {
      const modalId = (trigger.getAttribute("data-modal-open") || "").trim();
      if (modalId !== "") {
        openModal(modalId);
      }
    });
  });

  pageScope.querySelectorAll("[data-modal-close]").forEach((trigger) => {
    if (!(trigger instanceof HTMLElement)) return;
    trigger.addEventListener("click", () => {
      const modal = trigger.closest(".admin-modal-shell");
      if (modal instanceof HTMLElement) {
        closeModal(modal);
      } else {
        closeAllModals();
      }
    });
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
      return;
    }

    const openModalElement = modalShells.find(
      (modal) => modal instanceof HTMLElement && modal.classList.contains("open")
    );
    if (openModalElement instanceof HTMLElement) {
      closeModal(openModalElement);
    }
  });

  const editForm = pageScope.querySelector("#edit-user-form");
  const editSubcopy = pageScope.querySelector("#modal-edit-subcopy");

  function setFormValue(name, value) {
    if (!(editForm instanceof HTMLFormElement)) return;
    const field = editForm.querySelector(`[name="${name}"]`);
    if (
      field instanceof HTMLInputElement ||
      field instanceof HTMLSelectElement ||
      field instanceof HTMLTextAreaElement
    ) {
      field.value = String(value ?? "");
    }
  }

  function updateRoleHintForForm(form, roleCode) {
    if (!(form instanceof HTMLFormElement)) return;
    const hint = form.querySelector("[data-role-hint]");
    if (!(hint instanceof HTMLElement)) return;

    const normalized = String(roleCode || "").trim().toLowerCase();
    if (normalized === "applicat") {
      hint.textContent = "Applicant selected: complete Address and Contact Number for profile completeness.";
      return;
    }

    if (normalized === "pswdo" || normalized === "admin") {
      hint.textContent = "Staff/Admin selected: complete Position Title and Contact Number for internal directory details.";
      return;
    }

    hint.textContent = "Select role to guide which profile details are most important.";
  }

  pageScope.querySelectorAll(".js-role-select").forEach((element) => {
    if (!(element instanceof HTMLSelectElement)) return;
    const form = element.closest("form");
    if (!(form instanceof HTMLFormElement)) return;

    updateRoleHintForForm(form, element.value);
    element.addEventListener("change", () => {
      updateRoleHintForForm(form, element.value);
    });
  });

  pageScope.querySelectorAll(".js-edit-user-btn").forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.addEventListener("click", () => {
      if (!(editForm instanceof HTMLFormElement)) {
        return;
      }

      const raw = (button.getAttribute("data-account") || "").trim();
      if (raw === "") {
        openModal("modal-edit-user");
        return;
      }

      let payload = null;
      try {
        payload = JSON.parse(raw);
      } catch (error) {
        window.console.error("Unable to parse edit payload.", error);
      }

      if (!payload || typeof payload !== "object") {
        openModal("modal-edit-user");
        return;
      }

      setFormValue("target_account_id", payload.id || "");
      setFormValue("first_name", payload.first_name || "");
      setFormValue("middle_name", payload.middle_name || "");
      setFormValue("last_name", payload.last_name || "");
      setFormValue("email", payload.email || "");
      setFormValue("role_code", payload.role_code || "");
      setFormValue("status_code", payload.status_code || "");
      setFormValue("position_title", payload.position_title || "");
      setFormValue("contact_number", payload.contact_number || "");
      setFormValue("address", payload.address || "");
      setFormValue("new_password", "");

      if (editSubcopy instanceof HTMLElement) {
        const label = payload.email ? `Editing: ${payload.email}` : "Update profile, role, status, or credentials.";
        editSubcopy.textContent = label;
      }

      const roleValue = String(payload.role_code || "");
      updateRoleHintForForm(editForm, roleValue);
      openModal("modal-edit-user");
    });
  });

  const restrictModal = pageScope.querySelector("#modal-restrict-confirm");
  const restrictCard = pageScope.querySelector("#modal-restrict-confirm .admin-modal-card");
  const restrictTitle = pageScope.querySelector("#modal-restrict-title");
  const restrictCopy = pageScope.querySelector("#modal-restrict-copy");
  const restrictBanner = pageScope.querySelector("#modal-restrict-banner");
  const restrictBannerIcon = pageScope.querySelector("#modal-restrict-banner-icon");
  const restrictBannerText = pageScope.querySelector("#modal-restrict-banner-text");
  const restrictTarget = pageScope.querySelector("#modal-restrict-target");
  const restrictStatus = pageScope.querySelector("#modal-restrict-status");
  const banReasonField = pageScope.querySelector("#ban-reason-field");
  const banReasonInput = pageScope.querySelector("#ban-reason-input");
  const banReasonCounter = pageScope.querySelector("#ban-reason-counter");
  const banReasonError = pageScope.querySelector("#ban-reason-error");
  const restrictConfirmBtn = pageScope.querySelector("#restrict-confirm-btn");

  function pendingRestrictionIsBan() {
    if (!(pendingRestrictForm instanceof HTMLFormElement)) {
      return false;
    }
    const statusSelect = pendingRestrictForm.querySelector('select[name="restrict_to"]');
    const selectedCode =
      statusSelect instanceof HTMLSelectElement
        ? String(statusSelect.value || "").trim().toLowerCase()
        : "restricted";

    return selectedCode === "banned";
  }

  function updateBanReasonCounter() {
    if (!(banReasonInput instanceof HTMLTextAreaElement) || !(banReasonCounter instanceof HTMLElement)) {
      return;
    }

    const max = Number.isFinite(banReasonInput.maxLength) && banReasonInput.maxLength > 0 ? banReasonInput.maxLength : 500;
    const length = String(banReasonInput.value || "").length;
    banReasonCounter.textContent = `${length} / ${max}`;
  }

  function updateConfirmButtonState() {
    if (!(restrictConfirmBtn instanceof HTMLButtonElement) || !(banReasonInput instanceof HTMLTextAreaElement)) {
      return;
    }

    if (!pendingRestrictionIsBan()) {
      restrictConfirmBtn.disabled = false;
      return;
    }

    const reason = String(banReasonInput.value || "").trim();
    restrictConfirmBtn.disabled = reason === "";
  }

  function setRestrictionModeUi(mode) {
    if (restrictCard instanceof HTMLElement) {
      restrictCard.classList.remove("is-ban", "is-unban", "is-unlock", "is-update");
      restrictCard.classList.add(`is-${mode}`);
    }

    if (restrictBanner instanceof HTMLElement) {
      restrictBanner.classList.toggle("hidden", false);
    }

    if (restrictBannerIcon instanceof HTMLElement) {
      const iconClass = mode === "ban"
        ? "fas fa-ban"
        : mode === "unban"
          ? "fas fa-unlock"
          : mode === "unlock"
            ? "fas fa-key"
            : "fas fa-shield-halved";
      restrictBannerIcon.className = iconClass;
    }

    if (restrictBannerText instanceof HTMLElement) {
      restrictBannerText.textContent = mode === "ban"
        ? "This action will block login immediately. Please record a complete reason."
        : mode === "unban"
          ? "This will remove ban restrictions and allow the user to log in."
          : mode === "unlock"
            ? "This will remove lock restrictions and allow the user to log in."
            : "Review this status change before confirming.";
    }

    if (restrictConfirmBtn instanceof HTMLButtonElement) {
      if (mode === "ban") {
        restrictConfirmBtn.innerHTML = '<i class="fas fa-gavel"></i> Ban & Save Reason';
      } else if (mode === "unban") {
        restrictConfirmBtn.innerHTML = '<i class="fas fa-unlock"></i> Confirm Unban';
      } else if (mode === "unlock") {
        restrictConfirmBtn.innerHTML = '<i class="fas fa-key"></i> Confirm Unlock';
      } else {
        restrictConfirmBtn.innerHTML = '<i class="fas fa-circle-check"></i> Confirm Update';
      }
    }
  }

  function ensureRestrictionReasonInput(form) {
    if (!(form instanceof HTMLFormElement)) return null;
    let field = form.querySelector('input[name="restriction_reason"]');
    if (field instanceof HTMLInputElement) {
      return field;
    }
    field = document.createElement("input");
    field.type = "hidden";
    field.name = "restriction_reason";
    form.appendChild(field);
    return field;
  }

  function openRestrictionModal(form) {
    if (
      !(form instanceof HTMLFormElement) ||
      !(restrictModal instanceof HTMLElement) ||
      !(restrictTitle instanceof HTMLElement) ||
      !(restrictCopy instanceof HTMLElement) ||
      !(restrictTarget instanceof HTMLElement) ||
      !(restrictStatus instanceof HTMLElement) ||
      !(banReasonField instanceof HTMLElement) ||
      !(banReasonInput instanceof HTMLTextAreaElement) ||
      !(banReasonError instanceof HTMLElement)
    ) {
      form.submit();
      return;
    }

    const statusSelect = form.querySelector('select[name="restrict_to"]');
    const selectedCode =
      statusSelect instanceof HTMLSelectElement
        ? String(statusSelect.value || "").trim().toLowerCase()
        : "restricted";
    const selectedLabel =
      statusSelect instanceof HTMLSelectElement
        ? String(statusSelect.options[statusSelect.selectedIndex]?.textContent || selectedCode || "Restricted").trim()
        : "Restricted";
    const isBan = selectedCode === "banned";
    const isActive = selectedCode === "active";
    const currentStatusCode = String(form.getAttribute("data-current-status") || "").trim().toLowerCase();
    const isUnban = isActive && currentStatusCode === "banned";
    const isUnlock = isActive && currentStatusCode === "locked";
    const accountLabel = String(form.getAttribute("data-account-label") || "Selected user").trim();
    const accountEmail = String(form.getAttribute("data-account-email") || "").trim();
    const mode = isBan ? "ban" : isUnban ? "unban" : isUnlock ? "unlock" : "update";

    pendingRestrictForm = form;
    setRestrictionModeUi(mode);
    restrictTitle.textContent = isBan
      ? "Confirm Account Ban"
      : isUnban
        ? "Confirm Account Unban"
        : isUnlock
          ? "Confirm Account Unlock"
      : isActive
        ? "Confirm Account Reactivation"
        : "Confirm Status Update";
    restrictCopy.textContent = isBan
      ? "Banning will block sign in immediately. Please record a clear reason below."
      : isUnban
        ? "Unbanning will restore login access immediately."
        : isUnlock
          ? "Unlocking will restore login access immediately."
      : isActive
        ? "Reactivating will restore account login access immediately."
        : "This status update will apply immediately for this account.";
    restrictTarget.textContent = accountEmail !== "" ? `User: ${accountLabel} (${accountEmail})` : `User: ${accountLabel}`;
    restrictStatus.textContent = `Status: ${selectedLabel}`;

    banReasonField.classList.toggle("hidden", !isBan);
    banReasonInput.required = isBan;
    banReasonInput.value = "";
    banReasonError.classList.add("hidden");
    updateBanReasonCounter();
    updateConfirmButtonState();
    openModal("modal-restrict-confirm");
    if (isBan) {
      window.setTimeout(() => {
        banReasonInput.focus();
      }, 60);
    }
  }

  pageScope.querySelectorAll(".js-restrict-form").forEach((formElement) => {
    if (!(formElement instanceof HTMLFormElement)) return;
    formElement.addEventListener("submit", (event) => {
      event.preventDefault();
      openRestrictionModal(formElement);
    });
  });

  if (
    restrictConfirmBtn instanceof HTMLButtonElement &&
    restrictModal instanceof HTMLElement &&
    banReasonInput instanceof HTMLTextAreaElement &&
    banReasonError instanceof HTMLElement
  ) {
    restrictConfirmBtn.addEventListener("click", () => {
      if (!(pendingRestrictForm instanceof HTMLFormElement)) {
        closeModal(restrictModal);
        return;
      }

      const statusSelect = pendingRestrictForm.querySelector('select[name="restrict_to"]');
      const selectedCode =
        statusSelect instanceof HTMLSelectElement
          ? String(statusSelect.value || "").trim().toLowerCase()
          : "restricted";
      const isBan = selectedCode === "banned";
      const reason = String(banReasonInput.value || "").trim();

      if (isBan && reason === "") {
        banReasonError.classList.remove("hidden");
        banReasonInput.focus();
        return;
      }

      const reasonField = ensureRestrictionReasonInput(pendingRestrictForm);
      if (reasonField instanceof HTMLInputElement) {
        reasonField.value = reason;
      }

      const formToSubmit = pendingRestrictForm;
      pendingRestrictForm = null;
      closeModal(restrictModal);
      formToSubmit.submit();
    });
  }

  if (banReasonInput instanceof HTMLTextAreaElement) {
    updateBanReasonCounter();
    banReasonInput.addEventListener("input", () => {
      if (banReasonError instanceof HTMLElement) {
        banReasonError.classList.add("hidden");
      }
      updateBanReasonCounter();
      updateConfirmButtonState();
    });
  }

  const autoOpen = (root.getAttribute("data-auto-open-modal") || "").trim().toLowerCase();
  if (autoOpen === "create") {
    openModal("modal-create-user");
  } else if (autoOpen === "edit") {
    openModal("modal-edit-user");
  }
})();
