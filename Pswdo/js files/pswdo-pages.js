// TEMPORARY: Request Management runtime diagnostics. Remove after an
// authenticated-browser trace identifies and verifies the targeted fix.
(() => {
  const isRequestManagement = document.getElementById("request-details-modal") instanceof HTMLElement;
  if (!isRequestManagement) return;

  const debug = (...args) => console.info("[RM DEBUG]", ...args);
  window.__aicsRequestManagementDebug = debug;
  debug("script loaded");
  debug("initializer started");
  window.addEventListener("error", (event) => {
    console.error("[RM DEBUG] window error", {
      message: event.message || "Unknown error",
      source: event.filename || "",
      line: event.lineno || 0,
      column: event.colno || 0,
      stack: event.error instanceof Error ? event.error.stack : "",
    });
  });
  window.addEventListener("unhandledrejection", (event) => {
    const reason = event.reason;
    console.error("[RM DEBUG] unhandled rejection", {
      message: reason instanceof Error ? reason.message : String(reason || "Unknown rejection"),
      stack: reason instanceof Error ? reason.stack : "",
    });
  });
})();

(() => {
  const modal = document.getElementById("ai-signal-modal");
  const stage = document.getElementById("ai-signal-stage");
  const closeButton = document.getElementById("close-ai-signal-modal");
  const previousButton = document.getElementById("ai-signal-previous");
  const nextButton = document.getElementById("ai-signal-next");
  const pageLabel = document.getElementById("ai-signal-page-label");
  const tabs = Array.from(document.querySelectorAll(".ai-signal-tab"));
  const tabsWrap = document.getElementById("ai-signal-tabs");
  const pageControls = document.getElementById("ai-signal-page-controls");
  const evidenceList = document.getElementById("ai-evidence-list");

  if (!(modal instanceof HTMLElement) || !(stage instanceof HTMLElement)) {
    return;
  }

  const aiSignalViewer = {
    pages: [],
    index: 0,
    view: "original",
    reviewId: 0,
    requestId: 0,
    documentId: 0,
    documentUrl: "",
    mimeType: "",
    indicators: [],
    decision: "clear"
  };

  // Human-readable context retained for accessibility and UI contract checks.
  modal.dataset.contextLabel = "Image Review for Possible Changes";

  function syncAiSignalBodyLock() {
    const sidebar = document.getElementById("sidebar");
    const hasOpenSidebar = Boolean(sidebar && sidebar.classList.contains("open"));
    const hasOpenModal = Boolean(document.querySelector(
      ".confirm-modal.open, .request-details-modal.open, .file-preview-modal.open, .ai-signal-modal.open, .sms-compose-modal.open, .request-action-popup-modal.open, .release-schedule-modal.open"
    ));
    document.body.classList.toggle("modal-open", hasOpenSidebar || hasOpenModal);
    document.documentElement.classList.toggle("modal-open", hasOpenSidebar || hasOpenModal);
  }

  function artifactUrl(reviewId, pageNumber, type) {
    const url = new URL("ai-artifact.php", window.location.href);
    url.searchParams.set("review_id", String(reviewId));
    url.searchParams.set("page", String(pageNumber));
    url.searchParams.set("type", type);
    return url.toString();
  }

  function showMessage(message, className = "ai-signal-empty") {
    if (evidenceList instanceof HTMLElement) evidenceList.replaceChildren();
    stage.innerHTML = "";
    const text = document.createElement("p");
    text.className = className;
    text.textContent = message;
    stage.appendChild(text);
  }

  function setLoading() {
    showMessage("Loading image view...", "ai-signal-loading");
  }

  function appendImage(source, altText, pageNumber = 0) {
    setLoading();
    const image = new Image();
    image.className = "ai-signal-image";
    image.alt = altText;
    image.addEventListener("load", () => {
      stage.innerHTML = "";
      if (!pageNumber) { stage.appendChild(image); return; }
      const canvas = document.createElement("div"); canvas.className = "ai-evidence-canvas"; canvas.appendChild(image);
      const displayedWidth = Math.max(1, Math.min(image.naturalWidth || 1, stage.clientWidth - 28, 760 * (image.naturalWidth || 1) / Math.max(1, image.naturalHeight || 1)));
      canvas.style.width = `${displayedWidth}px`; image.style.width = "100%"; image.style.maxHeight = "none";
      const caption = document.createElement("p"); caption.className = "ai-evidence-caption"; caption.textContent = "Select a marked area or a list item to read why it needs a closer look.";
      const markers = [];
      function addEvidenceBox(box, item, label) {
        if (!Array.isArray(box) || box.length !== 4 || box.some((value) => !Number.isInteger(value) || value < 0 || value > 1000) || box[0] >= box[2] || box[1] >= box[3]) return;
        // Each box has its own tone: red for strong findings, amber for weaker hints.
        const strong = item.tone ? item.tone === "strong" : aiSignalViewer.decision === "review";
        const marker = document.createElement("button"); marker.type = "button"; marker.className = `ai-evidence-box ${strong ? "is-review" : ""} ${box[0] < 40 ? "tag-inside" : ""}`;
        marker.style.top = `${box[0] / 10}%`; marker.style.left = `${box[1] / 10}%`; marker.style.height = `${(box[2] - box[0]) / 10}%`; marker.style.width = `${(box[3] - box[1]) / 10}%`;
        const number = markers.length + 1;
        const tag = document.createElement("span"); tag.className = "ai-evidence-tag"; tag.textContent = `${number}. ${String(item.label || label)}`; marker.appendChild(tag);
        marker.title = `${String(item.label || label)}: ${String(item.reason || "Inspect this area in the original document.")}`;
        marker.setAttribute("aria-label", marker.title);
        marker.addEventListener("click", () => focusEvidence(markers.length && markers.find((entry) => entry.marker === marker), markers, caption)); canvas.appendChild(marker);
        markers.push({marker, item, label: String(item.label || label), number, strong});
      }
      aiSignalViewer.indicators.forEach((item, index) => {
        if (item.page === pageNumber) addEvidenceBox(item.box_2d, item, `Evidence ${index + 1}`);
        if ((item.source_page || item.page) === pageNumber) addEvidenceBox(item.source_box_2d, {...item, label: `Copied from (${String(item.label || "evidence")})`}, `Source for evidence ${index + 1}`);
      });
      stage.append(canvas, caption);
      renderEvidenceList(markers, caption);
    });
    image.addEventListener("error", () => {
      showMessage("AI visualization could not be loaded.", "ai-signal-error");
    });
    image.src = source;
  }

  function focusEvidence(entry, markers, caption) {
    if (!entry) return;
    markers.forEach((other) => other.marker.classList.toggle("is-focused", other === entry));
    if (evidenceList instanceof HTMLElement) {
      evidenceList.querySelectorAll(".ai-evidence-item").forEach((button) => button.classList.toggle("is-active", button.dataset.number === String(entry.number)));
    }
    caption.textContent = `${entry.number}. ${entry.label}: ${String(entry.item.reason || "Inspect this area in the original document.")}`;
    entry.marker.scrollIntoView({block: "center", behavior: "smooth"});
  }

  function renderEvidenceList(markers, caption) {
    if (!(evidenceList instanceof HTMLElement)) return;
    evidenceList.replaceChildren();
    const heading = document.createElement("h4"); heading.textContent = markers.length ? `${markers.length} marked area${markers.length === 1 ? "" : "s"}` : "No marked areas";
    evidenceList.appendChild(heading);
    if (!markers.length) {
      const empty = document.createElement("p"); empty.className = "ai-evidence-empty";
      empty.textContent = "The automated checks did not point to any specific area on this page.";
      evidenceList.appendChild(empty);
      return;
    }
    markers.forEach((entry) => {
      const button = document.createElement("button"); button.type = "button";
      button.className = `ai-evidence-item ${entry.strong ? "is-strong" : "is-hint"}`; button.dataset.number = String(entry.number);
      const title = document.createElement("strong"); title.textContent = `${entry.number}. ${entry.label}`;
      const tone = document.createElement("span"); tone.className = "ai-evidence-tone"; tone.textContent = entry.strong ? "Strong finding" : "Hint";
      const reason = document.createElement("small"); reason.textContent = String(entry.item.reason || "Inspect this area in the original document.");
      button.append(title, tone, reason);
      button.addEventListener("click", () => focusEvidence(entry, markers, caption));
      evidenceList.appendChild(button);
    });
  }

  function renderOriginal(page) {
    if (page.original) {
      appendImage(artifactUrl(aiSignalViewer.reviewId, page.page, "original"), `Original document, page ${page.page}`, page.page);
      return;
    }
    if (!aiSignalViewer.documentUrl) {
      showMessage("The original document is unavailable.", "ai-signal-error");
      return;
    }

    if (aiSignalViewer.mimeType === "application/pdf") {
      stage.innerHTML = "";
      const frame = document.createElement("iframe");
      frame.className = "ai-signal-frame";
      frame.src = aiSignalViewer.documentUrl;
      frame.title = `Original document, page ${page.page}`;
      stage.appendChild(frame);
      return;
    }

    appendImage(aiSignalViewer.documentUrl, `Original document, page ${page.page}`, page.page);
  }

  function updateTabs(page) {
    tabs.forEach((tab) => {
      const view = tab.getAttribute("data-ai-view") || "original";
      const unavailable = (view === "signal" && !page.signal) || (view === "threshold" && !page.threshold);
      tab.classList.toggle("is-active", view === aiSignalViewer.view);
      tab.setAttribute("aria-selected", view === aiSignalViewer.view ? "true" : "false");
      tab.toggleAttribute("disabled", unavailable);
      tab.setAttribute("aria-disabled", unavailable ? "true" : "false");
    });
  }

  function renderAiSignalViewer() {
    const page = aiSignalViewer.pages[aiSignalViewer.index];
    if (!page) {
      if (pageLabel instanceof HTMLElement) pageLabel.textContent = "No analyzed pages";
      if (previousButton instanceof HTMLButtonElement) previousButton.disabled = true;
      if (nextButton instanceof HTMLButtonElement) nextButton.disabled = true;
      showMessage("No AI image views are available for this document.");
      return;
    }

    if (pageLabel instanceof HTMLElement) {
      pageLabel.textContent = `Page ${page.page} of ${aiSignalViewer.pages.length}`;
    }
    // Only show controls that can do something: model views when the optional
    // tamper model produced images, page buttons for multi-page documents.
    if (tabsWrap instanceof HTMLElement) tabsWrap.hidden = !aiSignalViewer.pages.some((item) => item.signal || item.threshold);
    if (pageControls instanceof HTMLElement) pageControls.hidden = aiSignalViewer.pages.length <= 1;
    if (previousButton instanceof HTMLButtonElement) previousButton.disabled = aiSignalViewer.index <= 0;
    if (nextButton instanceof HTMLButtonElement) nextButton.disabled = aiSignalViewer.index >= aiSignalViewer.pages.length - 1;
    updateTabs(page);

    if (aiSignalViewer.view === "original") {
      renderOriginal(page);
      return;
    }

    if (aiSignalViewer.view === "threshold" && page.regionsFound === false) {
      showMessage("No image region exceeded the current localization threshold.");
      return;
    }

    if (!page[aiSignalViewer.view]) {
      showMessage("This image view is unavailable for the selected page.");
      return;
    }

    if (evidenceList instanceof HTMLElement) evidenceList.replaceChildren();
    appendImage(
      artifactUrl(aiSignalViewer.reviewId, page.page, aiSignalViewer.view),
      `${aiSignalViewer.view === "signal" ? "AI signal" : "Threshold highlights"}, page ${page.page}`
    );
  }

  function openAiSignalViewer(config) {
    const rawPages = Array.isArray(config.pages) ? config.pages : [];
    aiSignalViewer.pages = rawPages
      .map((page, index) => ({
        page: Number.isFinite(Number(page.page)) && Number(page.page) > 0 ? Number(page.page) : index + 1,
        original: page.original === true,
        signal: Boolean(page.signal),
        threshold: Boolean(page.threshold),
        regionsFound: page.regionsFound === true
      }))
      .filter((page) => page.original || page.signal || page.threshold);
    aiSignalViewer.index = 0;
    aiSignalViewer.view = "original";
    aiSignalViewer.reviewId = Number(config.reviewId) || 0;
    aiSignalViewer.requestId = Number(config.requestId) || 0;
    aiSignalViewer.documentId = Number(config.documentId) || 0;
    aiSignalViewer.documentUrl = typeof config.documentUrl === "string" ? config.documentUrl : "";
    aiSignalViewer.mimeType = typeof config.mimeType === "string" ? config.mimeType.toLowerCase() : "";
    aiSignalViewer.indicators = Array.isArray(config.indicators) ? config.indicators.filter((item) => item && typeof item === "object" && Number.isInteger(Number(item.page))).map((item) => ({...item, page: Number(item.page)})) : [];
    aiSignalViewer.decision = config.decision === "review" ? "review" : "clear";
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    syncAiSignalBodyLock();
    renderAiSignalViewer();
    closeButton?.focus();
  }

  function closeAiSignalViewer() {
    modal.classList.remove("open");
    modal.setAttribute("aria-hidden", "true");
    stage.innerHTML = '<p class="ai-signal-loading">Select an AI visualization.</p>';
    syncAiSignalBodyLock();
  }

  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target.closest(".js-open-ai-signal-viewer") : null;
    if (!(target instanceof HTMLElement)) return;
    event.preventDefault();
    try {
      const config = JSON.parse(target.getAttribute("data-ai-viewer") || "{}");
      if (!config || typeof config !== "object" || !(Number(config.reviewId) > 0)) {
        throw new Error("Invalid viewer configuration");
      }
      openAiSignalViewer(config);
    } catch (_error) {
      modal.classList.add("open");
      modal.setAttribute("aria-hidden", "false");
      syncAiSignalBodyLock();
      showMessage("AI visualization could not be loaded.", "ai-signal-error");
    }
  });

  tabs.forEach((tab) => {
    tab.addEventListener("click", () => {
      if (!(tab instanceof HTMLButtonElement) || tab.disabled) return;
      aiSignalViewer.view = tab.getAttribute("data-ai-view") || "original";
      renderAiSignalViewer();
    });
  });

  previousButton?.addEventListener("click", () => {
    if (aiSignalViewer.index <= 0) return;
    aiSignalViewer.index -= 1;
    renderAiSignalViewer();
  });

  nextButton?.addEventListener("click", () => {
    if (aiSignalViewer.index >= aiSignalViewer.pages.length - 1) return;
    aiSignalViewer.index += 1;
    renderAiSignalViewer();
  });

  closeButton?.addEventListener("click", closeAiSignalViewer);
  modal.addEventListener("click", (event) => {
    if (event.target === modal) closeAiSignalViewer();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("open")) {
      event.stopImmediatePropagation();
      closeAiSignalViewer();
    }
  });
})();

(() => {
  function syncBodyLock() {
    const sidebar = document.getElementById("sidebar");
    const hasOpenSidebar = Boolean(sidebar && sidebar.classList.contains("open"));
    const hasOpenModal = Boolean(document.querySelector(".confirm-modal.open, .request-details-modal.open, .file-preview-modal.open, .ai-signal-modal.open, .sms-compose-modal.open, .request-action-popup-modal.open, .release-schedule-modal.open"));
    document.body.classList.toggle("modal-open", hasOpenSidebar || hasOpenModal);
  }

  const toggleBtn = document.getElementById("toggle-btn");
  const closeBtn = document.getElementById("close-btn");
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("overlay");
  const mainContent = document.getElementById("main-content");

  function isMobile() {
    return window.innerWidth <= 900;
  }

  function toggleSidebar() {
    if (!sidebar || !overlay || !mainContent) {
      return;
    }

    if (isMobile()) {
      sidebar.classList.toggle("open");
      overlay.classList.toggle("active");
      syncBodyLock();
      toggleBtn?.classList.toggle("is-open", sidebar.classList.contains("open"));
    } else {
      sidebar.classList.toggle("collapsed");
      mainContent.classList.toggle("expanded");
      toggleBtn?.classList.toggle("is-collapsed", sidebar.classList.contains("collapsed"));
    }
  }

  if (toggleBtn) {
    toggleBtn.addEventListener("click", toggleSidebar);
  }
  if (closeBtn) {
    closeBtn.addEventListener("click", () => {
      if (isMobile()) {
        sidebar?.classList.remove("open");
        overlay?.classList.remove("active");
        toggleBtn?.classList.remove("is-open");
        syncBodyLock();
      } else {
        toggleSidebar();
      }
    });
  }
  if (overlay) {
    overlay.addEventListener("click", () => {
      if (!sidebar) return;
      sidebar.classList.remove("open");
      overlay.classList.remove("active");
      toggleBtn?.classList.remove("is-open");
      syncBodyLock();
    });
  }

  window.addEventListener("resize", () => {
    if (!sidebar || !overlay || !mainContent) {
      return;
    }

    if (!isMobile()) {
      sidebar.classList.remove("open");
      overlay.classList.remove("active");
      syncBodyLock();
    } else {
      sidebar.classList.remove("collapsed");
      mainContent.classList.remove("expanded");
    }
  });
})();

(() => {
  const root = document.body;
  const endpoint = root instanceof HTMLBodyElement ? (root.getAttribute("data-ai-status-url") || "").trim() : "";
  if (endpoint === "") return;
  if (root.getAttribute("data-clear-legacy-ai-notice") === "1") {
    const url = new URL(window.location.href);
    url.searchParams.delete("status"); url.searchParams.delete("message");
    window.history.replaceState({}, "", url.toString());
  }
  const timers = new Map();
  const statusRequestsInFlight = new Set();
  const terminalStatuses = new Set(["completed", "likely_valid", "flagged_inconsistency", "needs_staff_review", "needs_reupload", "failed"]);
  const meta = {
    processing: ["Processing", "pill-info", "Awaiting local verification"], completed: ["Completed", "pill-success", "Automated checks completed"],
    likely_valid: ["Likely Valid", "pill-success", "Automated checks completed"], needs_staff_review: ["Needs Staff Review", "pill-warning", "Manual verification recommended"],
    needs_reupload: ["Needs Re-upload", "pill-warning", "Document needs attention"], flagged_inconsistency: ["Flagged Inconsistency", "pill-danger", "Consistency issue detected"],
    failed: ["Technical Processing Error", "pill-danger", "Local verification could not be completed. Retry or use manual review."],
  };
  const rowFor = (documentId, requestId) => document.querySelector(`tr[data-document-id="${CSS.escape(String(documentId))}"][data-request-id="${CSS.escape(String(requestId))}"]`);
  const stop = (documentId) => { const timer = timers.get(String(documentId)); if (timer) window.clearInterval(timer); timers.delete(String(documentId)); };

  const fieldLabels = { name: "Full Name", full_name: "Full Name", patient_name: "Patient Name", deceased_name: "Deceased Name", subject_name: "Subject / Client Name", client_name: "Client / Beneficiary Name", birth_date: "Date of Birth", date_of_birth: "Date of Birth", sex: "Sex", age: "Age", address: "Address", document_date: "Document Date", date_of_death: "Date of Death", place_of_death: "Place of Death", id_number: "ID Number", valid_until: "Valid Until", registry_number: "Registry Number", reference_number: "Reference Number", report_number: "Report Number", issuing_organization: "Issuing Organization / Office", hospital_or_clinic: "Hospital / Clinic", facility: "Facility / Clinic", physician_name: "Physician / Provider", funeral_home: "Funeral Home", amount: "Amount", document_subtype: "Document Subtype", report_subtype: "Report Subtype" };
  const documentLabels = { valid_id: "Valid ID", med_cert: "Medical Certificate", billing_statement: "Billing Statement / Prescription", death_cert: "Death Certificate", funeral_contract: "Funeral Contract", report_police_med: "Police / Medical Report", coe_mswdo: "Certificate of Eligibility" };
  const human = (key, value) => { const text = String(value ?? "").trim(); if (/^\d{4}-\d{2}-\d{2}$/.test(text) && ["birth_date", "date_of_birth", "document_date", "date_of_death", "valid_until"].includes(key)) return new Intl.DateTimeFormat("en-US", { month: "long", day: "numeric", year: "numeric", timeZone: "UTC" }).format(new Date(`${text}T00:00:00Z`)); return text.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()); };
  const displayConfidence = (value) => Number.isFinite(Number(value)) ? String(Number(value)) : String(value);
  function clarifyTypeVerification(content) {
    if (!(content instanceof HTMLElement)) return;
    const rows = Array.from(content.querySelectorAll(".ai-detail-list div"));
    const valueFor = (label) => rows.find((row) => row.querySelector("dt")?.textContent?.trim() === label)?.querySelector("dd");
    const expected = valueFor("Expected") || valueFor("Expected Document"); const detected = valueFor("Detected") || valueFor("Likely Detected") || valueFor("Detected Document"); const result = valueFor("Type Result") || valueFor("Type Verification");
    if (!(result instanceof HTMLElement)) return;
    const resultLabel = result.previousElementSibling; if (resultLabel instanceof HTMLElement) resultLabel.textContent = "Type Verification";
    const text = result.textContent?.trim() || "";
    if (/matched expected upload/i.test(text)) result.textContent = "Confirmed Match";
    else if (/wrong document type/i.test(text)) result.textContent = "Document Type Mismatch";
    else if (/uncertain|staff review/i.test(text)) { result.textContent = expected?.textContent?.trim() && expected.textContent.trim() === detected?.textContent?.trim() ? "Likely Match \u2014 Staff Confirmation Required" : "Needs Staff Confirmation"; const detectedLabel = detected?.previousElementSibling; if (detectedLabel instanceof HTMLElement && expected?.textContent?.trim() === detected?.textContent?.trim()) detectedLabel.textContent = "Likely Document"; }
  }
  function relocateDetails(row, create = false) {
    const cell = row.querySelector(".ai-verification-cell"); if (!(cell instanceof HTMLElement)) return null;
    const inRequestModal = row.closest("#request-details-modal") !== null;
    const detailsId = `${inRequestModal ? "modal-ai-details" : "ai-details"}-${row.getAttribute("data-document-id") || "new"}`;
    let detailRow = row.nextElementSibling; if (!(detailRow instanceof HTMLTableRowElement) || !detailRow.classList.contains("ai-details-row")) detailRow = null;
    let details = detailRow?.querySelector(".ai-details-panel") || cell.querySelector(".ai-details-panel") || document.getElementById(detailsId);
    if (!(details instanceof HTMLDetailsElement) && !create) return null;
    if (!(details instanceof HTMLDetailsElement)) { details = document.createElement("details"); details.className = "ai-details-panel"; details.innerHTML = "<summary aria-hidden=\"true\">AI details</summary><div class=\"ai-details-content\"></div>"; }
    details.id = detailsId;
    if (!detailRow) { detailRow = document.createElement("tr"); detailRow.className = "ai-details-row"; const detailCell = document.createElement("td"); detailCell.colSpan = row.cells.length; detailRow.appendChild(detailCell); row.insertAdjacentElement("afterend", detailRow); }
    detailRow.cells[0].colSpan = row.cells.length; if (details.parentElement !== detailRow.cells[0]) detailRow.cells[0].appendChild(details);
    if (inRequestModal) detailRow.hidden = !details.open;
    return details;
  }
  function ensureDetailsToggle(row, details) {
    const cell = row.querySelector(".ai-verification-cell"); if (!(cell instanceof HTMLElement)) return;
    let toggle = cell.querySelector(".ai-details-toggle");
    if (!(toggle instanceof HTMLButtonElement)) { toggle = document.createElement("button"); toggle.type = "button"; toggle.className = "ai-details-toggle"; toggle.textContent = "Details"; cell.appendChild(toggle); }
    toggle.setAttribute("aria-controls", details.id);
    if (!toggle.closest("#request-details-modal") && toggle.dataset.bound !== "1") { toggle.dataset.bound = "1"; toggle.addEventListener("click", () => { details.open = !details.open; toggle.setAttribute("aria-expanded", String(details.open)); toggle.textContent = details.open ? "Hide details" : "Details"; }); }
    if (details.dataset.toggleBound !== "1") { details.dataset.toggleBound = "1"; details.addEventListener("toggle", () => { toggle.setAttribute("aria-expanded", String(details.open)); toggle.textContent = details.open ? "Hide details" : "Details"; }); }
  }
  function effectiveTamperReview(data) {
    if (data?.image_review && typeof data.image_review === "object") return data.image_review;
    return data?.findings?.tamper_detection && typeof data.findings.tamper_detection === "object" ? data.findings.tamper_detection : null;
  }
  function dateConflictFrom(data) {
    const review = effectiveTamperReview(data);
    return review?.review_basis === "deterministic_chronology_conflict" && review.date_conflict && typeof review.date_conflict === "object" ? review.date_conflict : null;
  }
  function compactReviewFrom(data) {
    if (data?.compact_review && typeof data.compact_review === "object") return data.compact_review;
    const status = String(data?.status || "failed").toLowerCase();
    const reason = Array.isArray(data?.findings?.reasons) ? String(data.findings.reasons[0] || "") : "";
    if (status === "needs_reupload") return { tone: "danger", kicker: "Applicant update required", title: "Document needs replacement", reason: reason || "The uploaded file cannot be reviewed reliably.", action: "Request a clear replacement document before approving." };
    if (status === "flagged_inconsistency") return { tone: "danger", kicker: "Inconsistency detected", title: "Submitted information conflict", reason: reason || "One or more submitted values conflict and require staff confirmation.", action: "Compare the original document with the submitted information before deciding." };
    if (status === "needs_staff_review") return { tone: "warning", kicker: "Needs staff confirmation", title: "Staff review needed", reason: reason || "One or more automated checks need staff confirmation.", action: "Compare the original document with the submitted information before deciding." };
    if (status === "failed") return { tone: "neutral", kicker: "Automated check unavailable", title: "Continue with manual review", reason: reason || "The automated review could not complete all checks.", action: "Review the original document manually before deciding." };
    return { tone: "success", kicker: "No immediate issue found", title: "Checks completed", reason: reason || "The automated checks completed without a blocking result.", action: "Continue with normal staff verification of the original document." };
  }
  function renderDetails(row, data, label) {
    const details = relocateDetails(row, true); if (!(details instanceof HTMLDetailsElement)) return;
    ensureDetailsToggle(row, details);
    const content = details.querySelector(".ai-details-content");
    if (!(content instanceof HTMLElement)) return;
    const reasons = data.findings && Array.isArray(data.findings.reasons) ? data.findings.reasons : [];
    const checks = data.findings && typeof data.findings.checks === "object" ? data.findings.checks : {};
    const structured = data.findings?.structured_extraction?.fields || data.extracted_fields || {};
    const consistency = data.findings?.consistency_check?.comparisons;
    const tamperDetection = effectiveTamperReview(data);
    let sectionTarget = content;
    const addSection = (title, rows) => { if (!rows.length) return; const heading = document.createElement("h4"); heading.textContent = title; const list = document.createElement("dl"); list.className = "ai-detail-list"; rows.forEach(([term, value]) => { const item = document.createElement("div"); const dt = document.createElement("dt"); const dd = document.createElement("dd"); dt.textContent = term; dd.textContent = value; item.append(dt, dd); list.appendChild(item); }); sectionTarget.append(heading, list); };
    content.replaceChildren();
    const scanLabel = data.processed_at ? new Intl.DateTimeFormat("en-US", { month: "long", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }).format(new Date(String(data.processed_at).replace(" ", "T"))).replace(",", " \u2022") : "Not available";
    const compact = compactReviewFrom(data); const card = document.createElement("section"); card.className = `ai-review-explanation is-${["success", "warning", "danger", "info", "neutral"].includes(String(compact.tone)) ? compact.tone : "neutral"}`; card.setAttribute("aria-label", "AI review result");
    const head = document.createElement("div"); head.className = "ai-review-explanation-head"; const eyebrow = document.createElement("p"); eyebrow.textContent = String(compact.kicker || "Review result"); const heading = document.createElement("h4"); heading.textContent = String(compact.title || label); head.append(eyebrow, heading); card.appendChild(head);
    if (compact.date_facts && typeof compact.date_facts === "object") { const facts = document.createElement("dl"); facts.className = "ai-flag-facts"; [["Document / signature date", compact.date_facts.document_date], ["Repeated event date", compact.date_facts.event_dates]].forEach(([term, value]) => { const item = document.createElement("div"); const dt = document.createElement("dt"); const dd = document.createElement("dd"); dt.textContent = term; dd.textContent = String(value || "Not clearly detected"); item.append(dt, dd); facts.appendChild(item); }); card.appendChild(facts); }
    const compactReason = document.createElement("p"); compactReason.className = "ai-review-reason"; compactReason.textContent = String(compact.reason || "Review the original document."); const compactAction = document.createElement("p"); compactAction.className = "ai-review-action"; const actionLabel = document.createElement("strong"); actionLabel.textContent = "Staff action: "; compactAction.append(actionLabel, String(compact.action || "Complete manual verification before deciding.")); card.append(compactReason, compactAction);
    // The server renders the AICS AI verdict card (all values escaped) so a rescan matches a page load.
    if (typeof data.verdict_html === "string" && data.verdict_html.trim() !== "") { const verdictTemplate = document.createElement("template"); verdictTemplate.innerHTML = data.verdict_html; content.appendChild(verdictTemplate.content); } else content.appendChild(card);
    const full = document.createElement("details"); full.className = "ai-full-verification-details"; const fullSummary = document.createElement("summary"); fullSummary.textContent = "Technical details"; const body = document.createElement("div"); body.className = "ai-full-verification-body"; full.append(fullSummary, body); content.appendChild(full); sectionTarget = body;
    addSection("AI Verification Summary", [["Current Result", label], ["Last AI Scan", scanLabel]]);
    const assessmentTitles = { document_match: "Document match", readability: "Readability", applicant_match: "Beneficiary match", alteration_signal: "Alteration signal" };
    const assessments = data.findings?.assessments && typeof data.findings.assessments === "object" ? data.findings.assessments : {};
    const componentGrid = document.createElement("section"); componentGrid.className = "ai-component-grid"; componentGrid.setAttribute("aria-label", "AI verification assessments");
    Object.entries(assessmentTitles).forEach(([key, title]) => { const assessment = assessments[key] && typeof assessments[key] === "object" ? assessments[key] : {}; const state = ["pass", "review", "fail", "unavailable", "not_applicable"].includes(String(assessment.state)) ? String(assessment.state) : "unavailable"; const card = document.createElement("article"); card.className = `ai-component-card is-${state}`; const head = document.createElement("div"); head.className = "ai-component-card-head"; const heading = document.createElement("h4"); heading.textContent = title; const badge = document.createElement("span"); badge.textContent = String(assessment.label || "Check unavailable"); const explanation = document.createElement("p"); explanation.textContent = String(assessment.explanation || "Review the original document and confirm this item manually."); head.append(heading, badge); card.append(head, explanation); componentGrid.appendChild(card); });
    sectionTarget.appendChild(componentGrid);
    const type = checks.document_type || {}; const classification = type.classification || {}; const quality = checks.quality || {};
    addSection("Document Verification", [["Expected Document", documentLabels[data.expected_document_type] || human("", data.expected_document_type || "Not available")], ["Detected Document", documentLabels[data.detected_document_type] || human("", data.detected_document_type || "Not detected")], ["Type Result", classification.decision === "match" ? "Matched expected upload" : classification.decision === "wrong_slot" ? "Wrong document type" : "Uncertain \u2014 staff review required"], ...(quality.status ? [["Quality Result", quality.status === "acceptable" ? "Acceptable" : quality.status === "reupload" ? "Re-upload Recommended" : "Staff Review Recommended"]] : [])]);
    const fieldRows = Object.entries(structured).filter(([key, value]) => key !== "diagnosis_present" && value && typeof value === "object" && value.status === "extracted" && value.reliable !== false && value.value !== false && value.raw_value !== null).map(([key, value]) => [fieldLabels[key] || human("", key), human(key, value.normalized || value.raw_value || value.value)]).filter(([, value]) => value && !["Null", "None", "Undefined"].includes(value)); addSection("Extracted Information", fieldRows);
    const externalAi = data.findings?.external_ai && typeof data.findings.external_ai === "object" ? data.findings.external_ai : {};
    const gemini = externalAi.status === "completed" && externalAi.provider === "google_gemini" && externalAi.assessment && typeof externalAi.assessment === "object" ? externalAi.assessment : null;
    if (gemini) {
      const beneficiary = data.beneficiary && typeof data.beneficiary === "object" ? data.beneficiary : {};
      const beneficiaryKeys = { name: "full_name", patient_name: "full_name", deceased_name: "full_name", subject_name: "full_name", client_name: "full_name", full_name: "full_name", birth_date: "date_of_birth", date_of_birth: "date_of_birth", age: "age", sex: "sex", address: "address" };
      const geminiRows = Object.entries(gemini.fields || {}).filter(([, item]) => item && typeof item === "object" && (item.difference_detected === true || String(item.state || "") === "conflict" || (item.value !== null && item.value !== undefined && String(item.value).trim() !== ""))).map(([key, item]) => {
        const state = String(item.state || "review");
        const beneficiaryKey = beneficiaryKeys[key];
        const stateLabel = state === "support" ? (beneficiaryKey ? "Match" : "Extracted") : state === "conflict" ? "Conflict" : "Review Needed";
        const beneficiaryValue = beneficiaryKey ? beneficiary[beneficiaryKey] : null;
        const lines = [stateLabel];
        if (beneficiaryValue !== null && beneficiaryValue !== undefined && String(beneficiaryValue).trim() !== "" && Number(beneficiaryValue) !== 0) lines.push(`Beneficiary record: ${human(beneficiaryKey, beneficiaryValue)}`);
        if (item.difference_detected === true || state === "conflict") lines.push("Different value detected - inspect original document");
        else lines.push(`Extracted: ${human(key, item.value)}`);
        return [fieldLabels[key] || human("", key), lines.join("\n")];
      });
      addSection("AICS AI Beneficiary Cross-reference", geminiRows);
      const visualRows = [["Document quality", gemini.document_quality], ["Signature review", gemini.signature_review], ["Visual-integrity review", gemini.visual_integrity]].filter(([, item]) => item && typeof item === "object").map(([title, item]) => [title, `${human("", item.state || "unavailable")}\n${String(item.reason || "No explanation available.")}`]);
      visualRows.push(["Review limitation", "AICS AI visual and signature observations are advisory only and do not prove forgery or tampering."]);
      addSection("AICS AI Image Review", visualRows);
    }
    if (consistency && typeof consistency === "object") addSection("Consistency Check", Object.entries(consistency).filter(([, item]) => item && typeof item === "object").map(([key, item]) => { const sources = [["Submitted Beneficiary", item.application], ["Valid ID", item.valid_id], ["Document", item.document]].filter(([, value]) => value !== null && value !== undefined && value !== "").map(([source, value]) => `${source}: ${human(key, value)}`); return [fieldLabels[key] || human("", key), `${human("", item.status || "uncertain")}\n${sources.join("\n")}`]; }));
    if (tamperDetection && typeof tamperDetection === "object") {
      const indicators = Array.isArray(tamperDetection.indicators) ? tamperDetection.indicators : [];
      const state = tamperDetection.status === "unavailable" ? "Alteration review unavailable" : (tamperDetection.decision === "review" ? "Suspected alteration \u2014 staff review required" : "No corroborated alteration signal");
      const rows = [["Result", state], ["Evidence Signals", String(indicators.length)]];
      indicators.forEach((indicator, index) => {
        if (!indicator || typeof indicator !== "object") return;
        const type = human("", String(indicator.type || "visual indicator"));
        const source = human("", String(indicator.source || "automated check"));
        const page = Math.max(1, Number(indicator.page) || 1);
        const confidence = human("", String(indicator.confidence_band || "moderate"));
        rows.push([`Indicator ${index + 1}`, `Page ${page}: ${type}\nConfidence: ${confidence}\nEvidence Source: ${source}\nReason for Review: ${String(indicator.reason || "Inspect this area in the original document.")}`]);
      });
      rows.push(["Staff guidance", "Inspect the original document before deciding. This automated result does not reject the document."]);
      addSection("Document Alteration Review", rows);
    }
    if (reasons.length) { const heading = document.createElement("h4"); heading.textContent = "Findings"; const reasonList = document.createElement("ul"); reasonList.className = "ai-reason-list"; reasons.forEach((reason) => { const item = document.createElement("li"); item.textContent = String(reason); reasonList.appendChild(item); }); sectionTarget.append(heading, reasonList); }
    clarifyTypeVerification(sectionTarget);
  }

  function renderAction(row, data) {
    const actions = row.querySelector(".modal-doc-actions");
    if (!(actions instanceof HTMLElement)) return;
    actions.querySelectorAll(".ai-processing-state").forEach((element) => element.remove());
    const form = actions.querySelector(".js-ai-reprocess");
    const button = form instanceof HTMLFormElement ? form.querySelector('button[type="submit"]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    const processing = data.is_processing === true || String(data.status || "").toLowerCase() === "processing";
    button.disabled = processing;
    button.setAttribute("aria-disabled", String(processing));
    if (processing) {
      button.setAttribute("aria-busy", "true");
      button.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Scanning...';
    } else {
      button.removeAttribute("aria-busy");
      button.innerHTML = '<i class="fas fa-rotate"></i> Rescan';
      form.dataset.submitting = "0";
    }
  }

  function apply(row, data, syncSource = true) {
    const status = String(data.status || "failed").toLowerCase(); const [label, className, fallback] = meta[status] || meta.failed;
    const cell = row.querySelector(".ai-verification-cell"); if (!(cell instanceof HTMLElement)) return;
    cell.setAttribute("data-ai-status", status); cell.setAttribute("data-ai-review-id", String(data.review_id || ""));
    const badge = cell.querySelector(".ai-status-badge"); if (badge instanceof HTMLElement) { badge.className = `pill ${className} ai-status-badge`; badge.textContent = data.status_label || label; }
    const dateConflict = dateConflictFrom(data);
    const summary = cell.querySelector(".ai-status-summary"); if (summary instanceof HTMLElement) summary.textContent = data.verdict_html && data.summary && !data.is_processing ? String(data.summary) : status === "needs_staff_review" ? "Staff confirmation is required." : status === "flagged_inconsistency" ? (dateConflict ? "Document date inconsistency detected." : "Submitted information inconsistency detected.") : status === "needs_reupload" ? "Document quality is insufficient for reliable verification." : status === "failed" ? "AI processing could not be completed." : (data.summary || fallback);
    const confidence = cell.querySelector(".ai-row-confidence"); if (confidence instanceof HTMLElement) confidence.remove();
    if (!data.is_processing) renderDetails(row, data, data.status_label || label);
    renderAction(row, data);
    if (syncSource) {
      const documentId = (row.getAttribute("data-document-id") || "").trim();
      const requestId = (row.getAttribute("data-request-id") || "").trim();
      const template = /^\d+$/.test(requestId) ? document.getElementById(`request-detail-source-${requestId}`) : null;
      const sourceRow = template instanceof HTMLTemplateElement && /^\d+$/.test(documentId)
        ? template.content.querySelector(`tr[data-document-id="${CSS.escape(documentId)}"][data-request-id="${CSS.escape(requestId)}"]`)
        : null;
      if (sourceRow instanceof HTMLTableRowElement && sourceRow !== row) apply(sourceRow, data, false);
    }
  }

  async function refresh(documentId, requestId) {
    const row = rowFor(documentId, requestId); if (!(row instanceof HTMLTableRowElement)) return stop(documentId);
    const requestKey = `${documentId}:${requestId}`;
    if (statusRequestsInFlight.has(requestKey)) return;
    statusRequestsInFlight.add(requestKey);
    try { const url = new URL(endpoint, window.location.href); url.searchParams.set("document_id", documentId); url.searchParams.set("request_id", requestId); const response = await fetch(url, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } }); const data = await response.json(); if (!response.ok || !data || data.ok !== true) return; const terminal = terminalStatuses.has(String(data.status)); if (terminal) document.dispatchEvent(new CustomEvent("aics:ai-request-refresh", { detail: { requestId: String(requestId) } })); if (terminal) { stop(documentId); const terminalDocumentReviewUrl = window.AicsAiRequestPoller?.terminalDocumentReviewUrl; const target = typeof terminalDocumentReviewUrl === "function" ? terminalDocumentReviewUrl(window.location.href, requestId, documentId) : ""; if (target !== "") { window.location.replace(target); return; } } apply(row, data); if (terminal) stop(documentId); } catch (_error) { /* Temporary endpoint failures keep polling. */ } finally { statusRequestsInFlight.delete(requestKey); }
  }
  function start(documentId, requestId) { if (timers.has(String(documentId))) return; refresh(documentId, requestId); timers.set(String(documentId), window.setInterval(() => refresh(documentId, requestId), 2500)); }
  function discover() { document.querySelectorAll('.ai-verification-cell[data-ai-status="processing"]').forEach((cell) => { if (!(cell instanceof HTMLElement)) return; const documentId = cell.getAttribute("data-ai-document-id") || ""; const requestId = cell.getAttribute("data-ai-request-id") || ""; if (/^\d+$/.test(documentId) && /^\d+$/.test(requestId)) start(documentId, requestId); }); }
  document.addEventListener("aics:ensure-current-review", (event) => {
    const requestId = String(event.detail?.requestId || "");
    const queued = Array.isArray(event.detail?.queued) ? event.detail.queued : [];
    const skippedUnavailable = Array.isArray(event.detail?.skippedUnavailable) ? event.detail.skippedUnavailable : [];
    queued.forEach((item) => {
      const documentId = String(item?.document_id || "");
      if (!/^\d+$/.test(documentId) || !/^\d+$/.test(requestId)) return;
      const row = rowFor(documentId, requestId);
      if (row instanceof HTMLTableRowElement) apply(row, { review_id: item.review_id, status: "processing", status_label: "Processing", is_processing: true, summary: "AI verification is in progress...", confidence_score: null, findings: {} });
      start(documentId, requestId);
    });
    skippedUnavailable.forEach((value) => {
      const documentId = String(value || "");
      if (!/^\d+$/.test(documentId) || !/^\d+$/.test(requestId)) return;
      const row = rowFor(documentId, requestId);
      const cell = row instanceof HTMLTableRowElement ? row.querySelector(".ai-verification-cell") : null;
      if (!(cell instanceof HTMLElement) || (cell.getAttribute("data-ai-status") || "").trim() !== "") return;
      cell.setAttribute("data-ai-status", "unavailable");
      const badge = cell.querySelector(".ai-status-badge");
      const summary = cell.querySelector(".ai-status-summary");
      if (badge instanceof HTMLElement) { badge.className = "pill pill-danger ai-status-badge"; badge.textContent = "Temporarily Unavailable"; }
      if (summary instanceof HTMLElement) summary.textContent = "AI verification is temporarily unavailable. Existing review results remain available.";
    });
  });
  document.addEventListener("aics:ai-document-rows-mounted", discover);
  document.addEventListener("visibilitychange", () => { if (document.visibilityState === "visible") discover(); });
  document.addEventListener("submit", async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.classList.contains("js-ai-reprocess")) return;
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    if (!(button instanceof HTMLButtonElement)) return;
    if (button.disabled || form.dataset.submitting === "1") return;
    const row = form.closest("tr[data-document-id][data-request-id]");
    if (!(row instanceof HTMLTableRowElement)) return;
    const documentId = (row.getAttribute("data-document-id") || "").trim();
    const requestId = (row.getAttribute("data-request-id") || "").trim();
    if (!/^\d+$/.test(documentId) || !/^\d+$/.test(requestId)) return;
    form.dataset.submitting = "1";
    button.disabled = true;
    button.setAttribute("aria-disabled", "true");
    button.setAttribute("aria-busy", "true");
    button.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Scanning...';
    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });
      const data = await response.json();
      if (data && data.document_id) apply(row, data);
      if (!response.ok || !data || data.ok !== true) throw new Error(String(data?.message || data?.summary || "Unable to start document rescan."));
      start(documentId, requestId);
      showRuntimeToast("Document rescan started.", "success");
    } catch (error) {
      form.dataset.submitting = "0";
      button.disabled = false;
      button.setAttribute("aria-disabled", "false");
      button.removeAttribute("aria-busy");
      button.innerHTML = '<i class="fas fa-rotate"></i> Rescan';
      showRuntimeToast(error instanceof Error ? error.message : "Unable to start document rescan.");
    }
  });
  document.querySelectorAll("tr[data-document-id]").forEach((row) => { if (!(row instanceof HTMLTableRowElement)) return; const details = relocateDetails(row); if (details instanceof HTMLDetailsElement) { ensureDetailsToggle(row, details); clarifyTypeVerification(details.querySelector(".ai-details-content")); } });
  discover();
})();

(() => {
  const root = document.body;
  if (!(root instanceof HTMLBodyElement) || !root.classList.contains("overview-dashboard-page")) {
    return;
  }

  const endpoint = (root.getAttribute("data-dashboard-endpoint") || "").trim();
  const dashboardUrl = (root.getAttribute("data-dashboard-url") || "").trim();
  if (endpoint === "") {
    return;
  }

  const refreshBtn = document.getElementById("js-dashboard-refresh");
  const lastSyncedEl = document.getElementById("dashboard-last-synced");
  const errorBox = document.getElementById("dashboard-load-error");
  const topCasesContainer = document.getElementById("dashboard-top-cases");
  const logsContainer = document.getElementById("dashboard-notification-logs");
  const incomingQueueContainer = document.getElementById("dashboard-incoming-submissions");
  const documentBacklogContainer = document.getElementById("dashboard-document-review-backlog");
  const graphContainer = document.getElementById("dashboard-overview-graph");
  const periodLabelEl = document.getElementById("dashboard-period-label");
  const periodRangeEl = document.getElementById("dashboard-period-range");
  const trendTotalEl = document.getElementById("dashboard-trend-total");
  const periodControls = document.getElementById("dashboard-period-controls");
  const periodButtons = periodControls
    ? Array.from(periodControls.querySelectorAll(".overview-period-btn"))
    : [];

  if (
    !(refreshBtn instanceof HTMLButtonElement) ||
    !(lastSyncedEl instanceof HTMLElement) ||
    !(errorBox instanceof HTMLElement) ||
    !(logsContainer instanceof HTMLElement) ||
    !(graphContainer instanceof HTMLElement) ||
    !(periodLabelEl instanceof HTMLElement) ||
    !(periodRangeEl instanceof HTMLElement)
  ) {
    return;
  }

  const KPI_KEYS = [
    "total_requests",
    "pending_review",
    "interview_initial_total",
    "today_approved_count",
    "not_approved",
    "released",
    "documents_checked",
    "documents_uploaded",
    "approved",
  ];

  let isFetching = false;
  // A period chosen while a refresh is running; loaded as soon as it finishes.
  let queuedPeriod = "";
  let lastRenderedPeriod = "";
  let activePeriod = "month";
  const activeBtn = periodButtons.find((button) => button.classList.contains("active"));
  if (activeBtn instanceof HTMLButtonElement) {
    const initialPeriod = (activeBtn.getAttribute("data-period") || "").trim().toLowerCase();
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

  function renderError(message) {
    const msg = (message || "").toString().trim();
    if (msg === "") {
      errorBox.classList.add("is-hidden");
      return;
    }

    const textNode = errorBox.querySelector("span");
    if (textNode) {
      textNode.textContent = msg;
    }
    errorBox.classList.remove("is-hidden");
  }

  function renderTopCases(cases) {
    if (!(topCasesContainer instanceof HTMLElement)) {
      return;
    }

    if (!Array.isArray(cases) || cases.length === 0) {
      topCasesContainer.innerHTML = '<p class="empty-note">No ranked active cases yet.</p>';
      return;
    }

    topCasesContainer.innerHTML = cases
      .slice(0, 4)
      .map((item) => {
        const priorityIndicator = ["STANDARD", "HIGH", "URGENT", "High", "Normal"].includes(item?.priority_indicator)
          ? item.priority_indicator : "STANDARD";
        const priorityClass = ["priority-chip-normal", "priority-chip-request-high", "priority-chip-urgent", "priority-chip-high"]
          .includes(item?.priority_chip_class) ? item.priority_chip_class : "priority-chip-normal";
        return `
          <div class="list-item overview-feed-item priority-feed-item">
            <span class="feed-rank">${escapeHtml(formatCount(item.priority_rank || 0))}</span>
            <div class="feed-item-copy">
              <span class="feed-reference">${escapeHtml(item.reference_no || "-")}</span>
              <strong>${escapeHtml(item.beneficiary_name || "Beneficiary")}</strong>
              <p>${escapeHtml(item.assistance_label || "Assistance Request")}</p>
            </div>
            <span class="priority-chip ${priorityClass}">${escapeHtml(priorityIndicator)}</span>
          </div>
        `;
      })
      .join("");
  }

  function renderNotificationLogs(logs) {
    if (!Array.isArray(logs) || logs.length === 0) {
      logsContainer.innerHTML = '<p class="empty-note">No SMS or notification logs recorded yet.</p>';
      return;
    }

    logsContainer.innerHTML = logs
      .slice(0, 4)
      .map((item) => `
        <div class="list-item overview-feed-item notification-feed-item">
          <span class="notification-feed-icon"><i class="fas fa-comment-sms"></i></span>
          <div class="feed-item-copy">
            <span class="feed-reference">${escapeHtml(item.reference_no || "-")}</span>
            <strong>${escapeHtml(item.stage || "General Update")}</strong>
            <p>${escapeHtml(item.beneficiary_name || "Applicant")} · ${escapeHtml(item.sent_at_label || "Not available")}</p>
          </div>
        </div>
      `)
      .join("");
  }

  function requestLink(requestId) {
    if (dashboardUrl === "" || !/^\d+$/.test(String(requestId || ""))) return "#";
    const url = new URL(dashboardUrl, window.location.href);
    url.searchParams.set("page", "requests");
    url.searchParams.set("reopen_request", String(requestId));
    return url.toString();
  }

  function renderWorkflowQueue(container, items, kind) {
    if (!(container instanceof HTMLElement)) return;
    if (!Array.isArray(items) || items.length === 0) {
      container.innerHTML = `<p class="empty-note">${kind === "document" ? "All uploaded documents are currently checked." : "No new submissions waiting right now."}</p>`;
      return;
    }
    container.innerHTML = items.slice(0, 4).map((item) => {
      const isDocument = kind === "document";
      const detail = isDocument
        ? `${formatCount(item.checked_total || 0)}/${formatCount(item.uploaded_total || 0)} checked`
        : `${formatCount(item.uploaded_total || 0)}/${formatCount(item.required_total || 0)} files`;
      const subline = isDocument
        ? `${item.assistance_label || "Assistance Request"} · ${item.address_label || "Not available"}`
        : `${item.assistance_label || "Assistance Request"} · ${item.date_applied_label || "Not available"}`;
      const detailIcon = isDocument ? "fa-file-circle-check" : "fa-paperclip";
      return `<div class="workflow-list-item dashboard-queue-item"><div class="workflow-list-main"><span class="feed-reference">${escapeHtml(item.reference_no || "-")}</span><strong>${escapeHtml(item.beneficiary_name || "Applicant")}</strong><p>${escapeHtml(subline)}</p><span class="feed-progress"><i class="fas ${detailIcon}"></i> ${escapeHtml(detail)}</span></div><a href="${escapeHtml(requestLink(item.request_id))}" class="feed-open-btn" aria-label="Open request"><i class="fas fa-arrow-right"></i></a></div>`;
    }).join("");
  }

  function updateWorkflow(payload) {
    const workflow = payload && typeof payload.workflow === "object" ? payload.workflow : {};
    const counts = workflow && typeof workflow.counts === "object" ? workflow.counts : {};
    const countTargets = {
      new_submissions: "dashboard-workflow-new-submissions",
      document_review: "dashboard-workflow-document-review",
      release_ready: "dashboard-workflow-release-ready",
      for_screening: "dashboard-workflow-for-screening",
    };
    Object.entries(countTargets).forEach(([key, id]) => {
      const target = document.getElementById(id);
      if (target instanceof HTMLElement) target.textContent = formatCount(counts[key] || 0);
    });
    renderWorkflowQueue(incomingQueueContainer, workflow.new_submissions, "incoming");
    renderWorkflowQueue(documentBacklogContainer, workflow.document_review, "document");
  }

  function formatPeriodDateLabel(value) {
    const raw = (value || "").toString().trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
      return raw;
    }
    const parsed = new Date(`${raw}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) {
      return raw;
    }
    return parsed.toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "2-digit",
    });
  }

  function setActivePeriod(period) {
    const clean = (period || "").toString().trim().toLowerCase();
    activePeriod = ["day", "week", "month"].includes(clean) ? clean : "month";
    periodButtons.forEach((button) => {
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }
      button.classList.toggle("active", button.getAttribute("data-period") === activePeriod);
    });
  }

  function renderOverviewTrend(payload) {
    const trend = payload && typeof payload.trend === "object" ? payload.trend : {};
    const points = Array.isArray(trend.points) ? trend.points : [];
    const submittedTotal = points.reduce((total, point) => {
      const count = Number(point && point.submitted ? point.submitted : 0);
      return total + (Number.isFinite(count) ? Math.max(0, count) : 0);
    }, 0);
    if (trendTotalEl instanceof HTMLElement) {
      trendTotalEl.textContent = formatCount(submittedTotal);
    }

    if (points.length === 0 || submittedTotal === 0) {
      const latest = (trend.latest_request_date || "").toString().trim();
      const hint = latest !== ""
        ? `Latest request: ${formatPeriodDateLabel(latest)}. Try a longer range.`
        : "No requests have been submitted yet.";
      graphContainer.innerHTML = `
        <div class="overview-trend-empty">
          <span><i class="fas fa-chart-line"></i></span>
          <strong>No requests in this period</strong>
          <p>${escapeHtml(hint)}</p>
        </div>
      `;
      return;
    }

    const visiblePoints = points.filter((point) => {
      const count = Number(point && point.submitted ? point.submitted : 0);
      return Number.isFinite(count) && count > 0;
    });

    const maxSubmittedFromPayload = Number(trend.max_submitted || 0);
    const maxSubmittedFromPoints = points.reduce((max, point) => {
      const count = Number(point && point.submitted ? point.submitted : 0);
      return Number.isFinite(count) ? Math.max(max, count) : max;
    }, 0);
    const maxSubmitted = Math.max(1, maxSubmittedFromPayload, maxSubmittedFromPoints);
    const axisMax = Math.max(4, Math.ceil(maxSubmitted / 4) * 4);
    const axisLabels = [axisMax, axisMax * 0.75, axisMax * 0.5, axisMax * 0.25, 0]
      .map((value) => `<span>${escapeHtml(formatCount(value))}</span>`)
      .join("");

    const segmentMeta = [
      { key: "pending_review", label: "Pending Review", tone: "warning" },
      { key: "interview_initial", label: "Interview / Initial", tone: "info" },
      { key: "approved", label: "Approved", tone: "success" },
      { key: "not_approved", label: "Not Approved", tone: "danger" },
      { key: "released", label: "Released", tone: "neutral" },
      { key: "other", label: "Other", tone: "other" },
    ];

    const cols = visiblePoints
      .map((point) => {
        const label = (point && point.label ? point.label : "").toString().trim() || "-";
        const submitted = Math.max(0, Number(point && point.submitted ? point.submitted : 0));
        const stackHeight = submitted > 0 ? Math.max(8, Math.round((submitted / axisMax) * 100)) : 0;

        const summary = segmentMeta
          .map((meta) => `${meta.label}: ${formatCount(point && point[meta.key] ? point[meta.key] : 0)}`)
          .join(" | ");
        const title = `Submitted: ${formatCount(submitted)} | ${summary}`;

        return `
          <div class="overview-trend-col">
            <strong class="overview-trend-total">${submitted > 0 ? escapeHtml(formatCount(submitted)) : ""}</strong>
            <div class="overview-trend-stack-wrap">
              <div class="overview-trend-stack overview-trend-bar" style="height:${escapeHtml(String(stackHeight))}%;" title="${escapeHtml(title)}" aria-label="${escapeHtml(title)}"></div>
            </div>
            <span class="overview-trend-label">${escapeHtml(label)}</span>
          </div>
        `;
      })
      .join("");

    const colCount = visiblePoints.length;
    graphContainer.innerHTML = `
      <div class="overview-trend-visual">
        <div class="overview-trend-axis" aria-hidden="true">${axisLabels}</div>
        <div class="overview-trend-scroll" id="dashboard-trend-scroll">
          <div class="overview-trend-plot" style="grid-template-columns: repeat(${colCount}, minmax(58px, 1fr));">
            ${cols}
          </div>
        </div>
      </div>
    `;
    const scrollEl = graphContainer.querySelector(".overview-trend-scroll");
    if (scrollEl) scrollEl.scrollLeft = 0;
  }

  function updatePeriodMeta(payload) {
    const period = payload && typeof payload.period === "object" ? payload.period : {};
    const label = (period.label || "").toString().trim();
    const from = (period.date_from || "").toString().trim();
    const to = (period.date_to || "").toString().trim();
    periodLabelEl.textContent = label !== "" ? label : "Last 30 Days";

    if (from !== "" && to !== "") {
      const fromLabel = formatPeriodDateLabel(from);
      const toLabel = formatPeriodDateLabel(to);
      periodRangeEl.textContent = from === to ? fromLabel : `${fromLabel} - ${toLabel}`;
    } else {
      periodRangeEl.textContent = "";
    }
  }

  function updateKpis(payload) {
    const metrics = payload && typeof payload.metrics === "object" ? payload.metrics : {};
    KPI_KEYS.forEach((key) => {
      const el = document.querySelector(`[data-kpi="${key}"]`);
      if (!(el instanceof HTMLElement)) {
        return;
      }

      let value = 0;
      if (key === "today_approved_count") {
        value = Number(payload.today_approved_count || 0);
      } else {
        value = Number(metrics[key] || 0);
      }
      el.textContent = formatCount(value);
    });
  }

  function updateSyncedAt(payload) {
    const label = (payload.generated_at_label || "").toString().trim();
    const iso = (payload.generated_at || "").toString().trim();
    lastSyncedEl.textContent = label !== "" ? label : "Just now";
    if (iso !== "") {
      lastSyncedEl.setAttribute("data-iso", iso);
    }
  }

  async function fetchDashboardData(requestedPeriod, showError = false) {
    if (isFetching) {
      const requested = (requestedPeriod || "").toString().trim().toLowerCase();
      if (showError && requested !== "") {
        queuedPeriod = requested;
        setActivePeriod(requested);
      }
      return;
    }
    const period = (requestedPeriod || activePeriod || "month").toString().trim().toLowerCase();
    setActivePeriod(period);

    isFetching = true;
    refreshBtn.disabled = true;
    const defaultRefreshHtml = refreshBtn.innerHTML;
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';

    try {
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set("period", activePeriod);

      const response = await fetch(url.toString(), {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });

      const contentType = response.headers.get("content-type") || "";
      if (!contentType.includes("application/json")) {
        throw new Error("Dashboard refresh returned an invalid response.");
      }
      const payload = await response.json();
      if (!response.ok) {
        throw new Error(payload && payload.message ? String(payload.message) : "Unable to sync live dashboard data.");
      }
      if (!payload || payload.ok !== true) {
        throw new Error(payload && payload.message ? String(payload.message) : "Live sync returned an invalid response.");
      }

      if (queuedPeriod !== "" && queuedPeriod !== String(payload.period?.code || "")) {
        return; // A newer period was chosen while this response was loading.
      }
      if (payload.period && payload.period.code) {
        setActivePeriod(String(payload.period.code));
      }
      updateKpis(payload);
      renderOverviewTrend(payload);
      updatePeriodMeta(payload);
      lastRenderedPeriod = String(payload.period?.code || activePeriod);
      renderTopCases(payload.top_cases);
      renderNotificationLogs(payload.notification_logs);
      updateWorkflow(payload);
      updateSyncedAt(payload);
      renderError("");
    } catch (error) {
      // Background sync failures are deliberately quiet; current dashboard
      // data stays visible and the next interval retries automatically.
      if (showError) {
        renderError("Unable to refresh dashboard data. Please try again.");
      }
    } finally {
      isFetching = false;
      refreshBtn.disabled = false;
      refreshBtn.innerHTML = defaultRefreshHtml;
      if (queuedPeriod !== "") {
        const next = queuedPeriod;
        queuedPeriod = "";
        if (next !== lastRenderedPeriod) fetchDashboardData(next, true);
      }
    }
  }

  refreshBtn.addEventListener("click", () => {
    fetchDashboardData(activePeriod, true);
  });

  periodButtons.forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    button.addEventListener("click", () => {
      const period = (button.getAttribute("data-period") || "").trim().toLowerCase();
      if (!["day", "week", "month"].includes(period)) {
        return;
      }
      fetchDashboardData(period, true);
    });
  });

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") {
      fetchDashboardData(activePeriod, false);
    }
  });

  window.setInterval(() => {
    if (document.visibilityState === "visible") {
      fetchDashboardData(activePeriod, false);
    }
  }, 30000);
})();

(() => {
  const modal = document.getElementById("sms-compose-modal");
  const form = document.getElementById("sms-compose-form");
  const smsRemarksInput = document.getElementById("sms-remarks");
  const smsSubmitBtn = document.getElementById("sms-compose-submit");
  const openButtons = Array.from(document.querySelectorAll(".js-open-sms-modal"));
  const massOpenButtons = Array.from(document.querySelectorAll(".js-open-mass-sms-modal"));
  const closeBtn = document.getElementById("close-sms-compose-modal");
  const cancelBtn = document.getElementById("cancel-sms-compose");
  const titleEl = document.getElementById("sms-compose-title");
  const actionTypeInput = document.getElementById("sms-action-type");
  const requestIdInput = document.getElementById("sms-request-id");
  const decisionInput = document.getElementById("sms-decision-status");
  const documentIdInput = document.getElementById("sms-document-id");
  const documentReviewStatusInput = document.getElementById("sms-document-review-status");
  const documentLabelInput = document.getElementById("sms-document-label");
  const recipientLabelInput = document.getElementById("sms-recipient-label");
  const templateSelect = document.getElementById("sms-template-select");
  const massFieldsWrap = document.getElementById("sms-mass-schedule-fields");
  const massDateInput = document.getElementById("sms-mass-schedule-date");
  const massTimeInput = document.getElementById("sms-mass-schedule-time");
  const massVenueInput = document.getElementById("sms-mass-schedule-venue");
  const messageInput = document.getElementById("sms-message-text");

  if (
    !(modal instanceof HTMLElement) ||
    !(form instanceof HTMLFormElement) ||
    !(titleEl instanceof HTMLElement) ||
    !(actionTypeInput instanceof HTMLInputElement) ||
    !(requestIdInput instanceof HTMLInputElement) ||
    !(decisionInput instanceof HTMLInputElement) ||
    !(documentIdInput instanceof HTMLInputElement) ||
    !(documentReviewStatusInput instanceof HTMLInputElement) ||
    !(documentLabelInput instanceof HTMLInputElement) ||
    !(recipientLabelInput instanceof HTMLInputElement) ||
    !(templateSelect instanceof HTMLSelectElement) ||
    !(massFieldsWrap instanceof HTMLElement) ||
    !(massDateInput instanceof HTMLInputElement) ||
    !(massTimeInput instanceof HTMLInputElement) ||
    !(massVenueInput instanceof HTMLInputElement) ||
    !(messageInput instanceof HTMLTextAreaElement)
  ) {
    return;
  }

  let activeContext = null;

  const SMS_MONTH_NAMES = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December"
  ];

  // "2026-05-16" -> "May 16, 2026"
  function formatScheduleDateLabel(rawValue) {
    const trimmed = (rawValue || "").trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
      return "";
    }
    const [year, month, day] = trimmed.split("-");
    const monthName = SMS_MONTH_NAMES[Number.parseInt(month, 10) - 1] || month;
    return `${monthName} ${Number.parseInt(day, 10)}, ${year}`;
  }

  // "09:00" -> "9:00 AM"
  function formatScheduleTimeLabel(rawValue) {
    const trimmed = (rawValue || "").trim();
    if (!/^\d{2}:\d{2}$/.test(trimmed)) {
      return "";
    }
    const [hourText, minute] = trimmed.split(":");
    const hour = Number.parseInt(hourText, 10);
    if (!Number.isFinite(hour)) {
      return "";
    }
    const suffix = hour >= 12 ? "PM" : "AM";
    const normalizedHour = hour % 12 === 0 ? 12 : hour % 12;
    return `${normalizedHour}:${minute} ${suffix}`;
  }

  function formatMassDateLabel(rawValue) {
    return formatScheduleDateLabel(rawValue) || "{schedule_date}";
  }

  function formatMassTimeLabel(rawValue) {
    return formatScheduleTimeLabel(rawValue) || "{schedule_time}";
  }

  function refreshSmsMessageFromSelection() {
    const selectedOption = templateSelect.selectedOptions[0];
    if (!selectedOption) {
      messageInput.value = "";
      return;
    }

    const templateText = selectedOption.getAttribute("data-template-text") || "";
    const isMass = activeContext?.scope === "mass";
    if (!isMass) {
      messageInput.value = templateText;
      return;
    }

    messageInput.value = templateText
      .replaceAll("{schedule_date}", formatMassDateLabel(massDateInput.value))
      .replaceAll("{schedule_time}", formatMassTimeLabel(massTimeInput.value))
      .replaceAll("{venue}", massVenueInput.value.trim() || "PSWDO Office");
  }

  function syncBodyLock() {
    const sidebar = document.getElementById("sidebar");
    const hasOpenSidebar = Boolean(sidebar && sidebar.classList.contains("open"));
    const hasOpenModal = Boolean(document.querySelector(".confirm-modal.open, .request-details-modal.open, .file-preview-modal.open, .ai-signal-modal.open, .sms-compose-modal.open, .request-action-popup-modal.open, .release-schedule-modal.open"));
    document.body.classList.toggle("modal-open", hasOpenSidebar || hasOpenModal);
  }

  const templates = {
    for_screening: [
      {
        id: "screening_default",
        label: "Ready for Screening (Default)",
        text: "Good day, {beneficiary}.\nThis is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\nYour AICS application, {reference}, has been approved for further screening.\nPlease proceed to:\nOffice: PSWDO Office\nPurpose: Further Screening\nPlease bring your valid ID and required documents.\nThank you.",
      },
      {
        id: "screening_under_review",
        label: "Under Review Update",
        text: "PSWDO update: Application {reference} is now under screening review. Please keep your line open for possible follow-up.",
      },
      {
        id: "screening_followup",
        label: "Follow-up Reminder",
        text: "Your request {reference} has passed initial requirements checking and is now for screening. We will send another message once next steps are available.",
      },
    ],
    incomplete_requirements: [
      {
        id: "incomplete_default",
        label: "Incomplete Requirements (Default)",
        text: "Good day {beneficiary}. Your AICS application {reference} has incomplete requirements. Please submit the missing documents so PSWDO can continue your screening.",
      },
      {
        id: "incomplete_missing_docs",
        label: "Missing Documents Notice",
        text: "PSWDO update: Request {reference} cannot proceed to screening yet due to incomplete requirements. Please complete all required documents.",
      },
      {
        id: "incomplete_followup",
        label: "Action Needed Reminder",
        text: "Please complete pending requirements for application {reference}. Once complete, PSWDO will continue your screening process.",
      },
    ],
    invalid_requirements: [
      {
        id: "invalid_default",
        label: "Invalid Requirements (Default)",
        text: "Good day {beneficiary}. Your AICS application {reference} was marked as not approved due to invalid requirements. Please coordinate with PSWDO for guidance.",
      },
      {
        id: "invalid_correction",
        label: "Correction Required",
        text: "PSWDO update: Application {reference} was tagged invalid due to requirement issues. Please visit the office for correction guidance.",
      },
      {
        id: "invalid_followup",
        label: "Not Approved Notice",
        text: "Your request {reference} is currently not approved because of invalid requirements. Please contact PSWDO to clarify your next steps.",
      },
    ],
    rejection_notice: [
      {
        id: "reject_default",
        label: "Rejection Notice (Default)",
        text: "Good day {beneficiary}. We regret to inform you that your AICS application {reference} has been reviewed and was not approved by PSWDO. Reason: {rejection_reason} For further assistance, please coordinate with the PSWDO office.",
      },
      {
        id: "reject_ineligible",
        label: "Ineligible Notice",
        text: "Good day {beneficiary}. After careful review, your AICS application {reference} did not meet the eligibility requirements. Please contact PSWDO for clarification and guidance.",
      },
      {
        id: "reject_short",
        label: "Short Notice",
        text: "PSWDO update: Your AICS application {reference} has been marked as Not Approved. Reason: {rejection_reason} Please coordinate with the PSWDO office for guidance.",
      },
    ],
    document_review: [
      {
        id: "doc_reupload_default",
        label: "Needs Changes (Default)",
        text: "Good day, {beneficiary}.\nThis is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\nYour document for request {reference} requires changes.\nDocument: {document_label}\nPlease re-upload the updated document through your Application Status page.\nReason: {reason}\nThank you.",
      },
      {
        id: "doc_invalid_default",
        label: "Invalid Document",
        text: "Good day.\nThis is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\nYour document for request {reference} has been marked as invalid.\nDocument: {document_label}\nPlease upload a clearer or correct version through your Application Status page.\nReason: {reason}\nThank you.",
      },
    ],
    release_schedule: [
      {
        id: "release_schedule_default",
        label: "Release Schedule (Default)",
        text: "Good day, {beneficiary}.\nThis is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\nYour application, {reference}, is scheduled for assistance release on:\nDate: {schedule_date}\nTime: {schedule_time}\nVenue: {venue}\n{amount_line}Please arrive on time and bring your valid ID and required documents.\nThank you.",
      },
      {
        id: "release_schedule_reminder",
        label: "Release Reminder",
        text: "PSWDO reminder: Your assistance release for request {reference} is set on {schedule_date} at {schedule_time}, venue: {venue}. Please be present during your assigned schedule.",
      },
      {
        id: "release_schedule_notice",
        label: "Distribution Notice",
        text: "Good day, {beneficiary}.\nThis is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\nYour application, {reference}, is scheduled for assistance release on:\nDate: {schedule_date}\nTime: {schedule_time}\nVenue: {venue}\n{amount_line}Please arrive on time and bring your valid ID and required documents.\nThank you.",
      },
    ],
    ready_for_release: [
      {
        id: "ready_release_default",
        label: "Ready for Release (Default)",
        text: "Good day {beneficiary}. Your AICS application {reference} is ready for release. Please proceed to {venue} on {schedule_date} at {schedule_time}. {batch_line}",
      },
      {
        id: "ready_release_confirmation",
        label: "Final Confirmation",
        text: "PSWDO confirmation: Your assistance release for request {reference} is ready. Please attend your schedule on {schedule_date} at {schedule_time} in {venue}.",
      },
      {
        id: "ready_release_short",
        label: "Short Reminder",
        text: "Your AICS release schedule for request {reference} is confirmed: {schedule_date}, {schedule_time}, {venue}. Please arrive on time.",
      },
    ],
    missed_release: [
      {
        id: "missed_release_default",
        label: "Missed Schedule (Default)",
        text: "Good day {beneficiary}. Our records show that the release schedule for your AICS application {reference} was missed. Please wait for PSWDO instructions for rescheduling.",
      },
      {
        id: "missed_release_reschedule",
        label: "Reschedule Notice",
        text: "PSWDO update: You missed the scheduled release for request {reference}. Please coordinate with our office so we can arrange the next available schedule.",
      },
      {
        id: "missed_release_followup",
        label: "Follow-up Reminder",
        text: "Your assistance release for request {reference} needs follow-up because the assigned schedule was missed. Please contact PSWDO for guidance.",
      },
    ],
  };

  function applyTemplateTokens(templateText, context) {
    const batchLine = context.releaseBatch ? `Batch: ${context.releaseBatch}. ` : "";
    const amount = Number.parseFloat(context.amountGiven || "");
    const amountLine = amount > 0 ? `Amount: PHP ${amount.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}\n` : "";
    return templateText
      .replaceAll("{reference}", context.referenceNo || "your application")
      .replaceAll("{beneficiary}", context.beneficiaryName || "Applicant")
      .replaceAll("{applicant}", context.applicantName || "Applicant")
      .replaceAll("{schedule_date}", formatScheduleDateLabel(context.scheduleDate) || context.scheduleDate || "the scheduled date")
      .replaceAll("{schedule_time}", formatScheduleTimeLabel(context.scheduleTime) || context.scheduleTime || "the scheduled time")
      .replaceAll("{venue}", context.venue || "the assigned venue")
      .replaceAll("{release_batch}", context.releaseBatch || "")
      .replaceAll("{batch_line}", batchLine)
      .replaceAll("{amount_line}", amountLine)
      .replaceAll("{document_label}", context.documentLabel || "the document")
      .replaceAll("{reason}", context.reason || "Please review and update.")
      .replaceAll("{rejection_reason}", context.rejectionReason || "Please coordinate with PSWDO for details.");
  }

  function normalizeDecisionStatus(rawStatus) {
    const value = (rawStatus || "").toString().trim().toLowerCase();
    if (value === "incomplete_requirements" || value === "incomplete requirements") {
      return "incomplete_requirements";
    }
    if (value === "rejection_notice" || value === "reject") {
      return "rejection_notice";
    }
    if (
      value === "invalid_requirements" ||
      value === "invalid requirements" ||
      value === "not_approved" ||
      value === "not approved" ||
      value === "rejected" ||
      value === "denied"
    ) {
      return "invalid_requirements";
    }
    if (value === "release_schedule" || value === "release schedule" || value === "scheduled" || value === "rescheduled") {
      return "release_schedule";
    }
    if (value === "ready_for_release" || value === "ready for release") {
      return "ready_for_release";
    }
    if (value === "document_review" || value === "document review") {
      return "document_review";
    }
    if (value === "missed_release" || value === "missed release" || value === "missed") {
      return "missed_release";
    }
    return "for_screening";
  }

  function closeModal() {
    modal.classList.remove("open");
    activeContext = null;
    syncBodyLock();
  }

  function openModal(context) {
    const isMass = context.scope === "mass";
    const isReleaseMass = isMass && normalizeDecisionStatus(context.decisionStatus) === "release_schedule";
    activeContext = context;
    titleEl.innerHTML = isReleaseMass
      ? '<i class="fas fa-bullhorn"></i> Send Release Notifications'
      : (isMass
      ? '<i class="fas fa-comment-sms"></i> Send SMS to Applicants'
      : '<i class="fas fa-comment-sms"></i> Send SMS to Applicant');
    actionTypeInput.value = ((context.actionType || "").trim() || (isMass ? "mass_send_release_schedule_sms" : "send_sms"));
    requestIdInput.value = isMass ? "" : context.requestId;
    context.decisionStatus = normalizeDecisionStatus(context.decisionStatus);
    decisionInput.value = context.decisionStatus;
    documentIdInput.value = context.documentId || "";
    documentReviewStatusInput.value = context.documentReviewStatus || "";
    documentLabelInput.value = context.documentLabel || "";
    recipientLabelInput.value = isMass ? `${context.recipientCount || 0} recipients` : context.recipientNumber;
    massFieldsWrap.classList.toggle("is-hidden", !isReleaseMass);
    if (isReleaseMass) {
      massDateInput.value = /^\d{4}-\d{2}-\d{2}$/.test(context.scheduleDate || "") ? context.scheduleDate : "";
      massTimeInput.value = /^\d{2}:\d{2}$/.test(context.scheduleTime || "") ? context.scheduleTime : "09:00";
      massVenueInput.value = (context.venue || "").trim() || "PSWDO Office";
    }

    const list = context.decisionStatus === "incomplete_requirements"
      ? templates.incomplete_requirements
      : (context.decisionStatus === "invalid_requirements"
        ? templates.invalid_requirements
        : (context.decisionStatus === "rejection_notice"
          ? templates.rejection_notice
          : (context.decisionStatus === "release_schedule"
            ? templates.release_schedule
            : (context.decisionStatus === "ready_for_release"
              ? templates.ready_for_release
              : (context.decisionStatus === "document_review"
                ? templates.document_review
              : (context.decisionStatus === "missed_release"
                ? templates.missed_release
                : templates.for_screening))))));

    templateSelect.innerHTML = "";
    const preferredTemplateId = context.decisionStatus === "document_review"
      ? (context.documentReviewStatus === "invalid" ? "doc_invalid_default" : "doc_reupload_default")
      : "";

    list.forEach((item, index) => {
      const option = document.createElement("option");
      option.value = item.id;
      option.textContent = item.label;
      option.setAttribute("data-template-text", isMass ? item.text : applyTemplateTokens(item.text, context));
      if ((preferredTemplateId !== "" && item.id === preferredTemplateId) || (preferredTemplateId === "" && index === 0)) {
        option.selected = true;
      }
      templateSelect.appendChild(option);
    });

    refreshSmsMessageFromSelection();

    if (smsRemarksInput instanceof HTMLInputElement) {
      smsRemarksInput.value = context.rejectionReason || "";
    }

    const isReject = context.actionType === "reject_request";
    if (smsSubmitBtn instanceof HTMLButtonElement) {
      smsSubmitBtn.innerHTML = isReleaseMass
        ? '<i class="fas fa-paper-plane"></i> Send SMS + Simulate MSWDO'
        : (isReject
        ? '<i class="fas fa-circle-xmark"></i> Send SMS & Reject'
        : '<i class="fas fa-paper-plane"></i> Send SMS');
      smsSubmitBtn.classList.toggle("danger", isReject);
    }

    modal.classList.add("open");
    syncBodyLock();
    messageInput.focus();
  }

  window.aicsOpenSmsModal = openModal;

  function openSingleSmsFromButton(button) {
    if (button.getAttribute("data-terminal-locked") === "1") {
      showRuntimeToast("This applicant is already cancelled or not approved. SMS updates are no longer allowed.");
      return;
    }

    const context = {
      scope: "single",
      actionType: button.getAttribute("data-sms-action-type") || "send_sms",
      requestId: button.getAttribute("data-request-id") || "",
      referenceNo: button.getAttribute("data-reference-no") || "",
      beneficiaryName: button.getAttribute("data-beneficiary-name") || "Applicant",
      applicantName: button.getAttribute("data-applicant-name") || "Applicant",
      recipientNumber: button.getAttribute("data-recipient-number") || "",
      scheduleDate: button.getAttribute("data-schedule-date") || "",
      scheduleTime: button.getAttribute("data-schedule-time") || "",
      venue: button.getAttribute("data-venue") || "",
      releaseBatch: button.getAttribute("data-release-batch") || "",
      amountGiven: button.getAttribute("data-amount-given") || "",
      decisionStatus: normalizeDecisionStatus(button.getAttribute("data-decision-status") || "for_screening"),
      documentId: button.getAttribute("data-document-id") || "",
      documentReviewStatus: button.getAttribute("data-document-review-status") || "needs_reupload",
      documentLabel: button.getAttribute("data-document-label") || "Document",
      reason: button.getAttribute("data-document-reason") || "",
    };

    if (!/^\d+$/.test(context.requestId)) {
      return;
    }
    if (context.actionType === "send_document_sms" && !/^\d+$/.test(context.documentId || "")) {
      return;
    }
    if (context.recipientNumber.trim() === "") {
      return;
    }
    openModal(context);
  }

  openButtons.forEach((button) => {
    button.addEventListener("click", () => {
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }
      openSingleSmsFromButton(button);
    });
  });

  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    const button = target.closest(".js-open-sms-modal");
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    if (openButtons.includes(button)) {
      return;
    }
    openSingleSmsFromButton(button);
  });

  massOpenButtons.forEach((button) => {
    button.addEventListener("click", () => {
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }

      if (button.getAttribute("data-notice-ready") === "0") {
        showRuntimeToast("Schedule applicants with valid contact numbers before sending notices.", "error");
        return;
      }

      const recipientCount = Number.parseInt(button.getAttribute("data-recipient-count") || "0", 10);
      if (!Number.isFinite(recipientCount) || recipientCount < 0) {
        return;
      }
      const readyCount = Number.parseInt(button.getAttribute("data-ready-count") || "0", 10);
      const actionType = (button.getAttribute("data-mass-action-type") || "").trim() || "mass_send_release_schedule_sms";
      const decisionStatus = normalizeDecisionStatus(button.getAttribute("data-mass-decision-status") || "release_schedule");

      openModal({
        scope: "mass",
        actionType,
        requestId: "",
        referenceNo: "{reference}",
        beneficiaryName: "{beneficiary}",
        applicantName: "Applicant",
        recipientNumber: "",
        recipientCount: readyCount > 0 ? readyCount : recipientCount,
        scheduleDate: new Date().toISOString().slice(0, 10),
        scheduleTime: "09:00",
        venue: "PSWDO Office",
        releaseBatch: "",
        decisionStatus,
      });
    });
  });

  templateSelect.addEventListener("change", () => {
    refreshSmsMessageFromSelection();
  });

  [massDateInput, massTimeInput, massVenueInput].forEach((input) => {
    input.addEventListener("input", () => {
      if (activeContext?.scope === "mass") {
        refreshSmsMessageFromSelection();
      }
    });
  });

  form.addEventListener("submit", (event) => {
    const isMass = actionTypeInput.value.startsWith("mass_");
    if (!isMass && !/^\d+$/.test(requestIdInput.value.trim())) {
      event.preventDefault();
      return;
    }
    if (actionTypeInput.value === "send_document_sms" && !/^\d+$/.test(documentIdInput.value.trim())) {
      event.preventDefault();
      return;
    }
    if (messageInput.value.trim() === "") {
      event.preventDefault();
      messageInput.focus();
    }
  });

  if (closeBtn) {
    closeBtn.addEventListener("click", closeModal);
  }
  if (cancelBtn) {
    cancelBtn.addEventListener("click", closeModal);
  }

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("open")) {
      closeModal();
    }
  });
})();

(() => {
  document.querySelectorAll(".js-toast-alert").forEach((toast) => {
    if (!(toast instanceof HTMLElement)) return;
    window.setTimeout(() => {
      toast.classList.add("is-hiding");
      window.setTimeout(() => toast.remove(), 200);
    }, 1800);
  });
})();

function showRuntimeToast(message, tone = "error") {
  const text = (message || "").toString().trim();
  if (text === "") {
    return;
  }

  document.querySelectorAll(".toast-alert").forEach((existing) => existing.remove());

  const toast = document.createElement("div");
  toast.className = `alert toast-alert js-runtime-toast ${tone === "success" ? "alert-success" : "alert-error"}`;
  toast.setAttribute("role", "status");
  toast.setAttribute("aria-live", "polite");
  toast.innerHTML = `
    <i class="fas fa-${tone === "success" ? "circle-check" : "triangle-exclamation"}"></i>
    <span></span>
  `;
  const textEl = toast.querySelector("span");
  if (textEl) {
    textEl.textContent = text;
  }
  document.body.appendChild(toast);

  window.setTimeout(() => {
    toast.classList.add("is-hiding");
    window.setTimeout(() => toast.remove(), 200);
  }, 1800);
}

(() => {
  const root = document.body;
  function syncBodyLock() {
    const sidebar = document.getElementById("sidebar");
    const hasOpenSidebar = Boolean(sidebar && sidebar.classList.contains("open"));
    const hasOpenModal = Boolean(document.querySelector(".confirm-modal.open, .request-details-modal.open, .file-preview-modal.open, .ai-signal-modal.open, .sms-compose-modal.open, .request-action-popup-modal.open, .release-schedule-modal.open"));
    document.body.classList.toggle("modal-open", hasOpenSidebar || hasOpenModal);
  }

  const modal = document.getElementById("release-schedule-modal");
  const form = document.getElementById("release-schedule-modal-form");
  const openButtons = Array.from(document.querySelectorAll(".js-open-release-schedule"));
  const closeBtn = document.getElementById("close-release-schedule-modal");
  const cancelBtn = document.getElementById("cancel-release-schedule");
  const requestIdInput = document.getElementById("release-request-id");
  const dateInput = document.getElementById("release-modal-date");
  const timeInput = document.getElementById("release-modal-time");
  const venueInput = document.getElementById("release-modal-venue");
  const notesInput = document.getElementById("release-modal-notes");
  const titleRef = document.getElementById("release-modal-reference");
  const metaBeneficiary = document.getElementById("release-modal-beneficiary");
  const metaDetails = document.getElementById("release-modal-details");
  const smsCheckbox = document.getElementById("release-modal-send-sms");
  const smsFields = document.getElementById("release-modal-sms-fields");
  const smsRecipientInput = document.getElementById("release-modal-sms-recipient");
  const smsMessageInput = document.getElementById("release-modal-sms-message");
  const amountInput = document.getElementById("release-modal-amount");
  let smsActiveContext = { beneficiary: "Applicant", referenceNo: "-", contactNumber: "", batch: "", manuallyEdited: false };
  const autoOpenRequestId = (document.body?.getAttribute("data-release-schedule-request-id") || "").trim();

  if (
    !(modal instanceof HTMLElement) ||
    !(form instanceof HTMLFormElement) ||
    !(requestIdInput instanceof HTMLInputElement) ||
    !(dateInput instanceof HTMLInputElement) ||
    !(timeInput instanceof HTMLInputElement) ||
    !(venueInput instanceof HTMLInputElement) ||
    !(notesInput instanceof HTMLElement) ||
    !(titleRef instanceof HTMLElement) ||
    !(metaBeneficiary instanceof HTMLElement) ||
    !(metaDetails instanceof HTMLElement) ||
    openButtons.length === 0
  ) {
    return;
  }

  function closeModal() {
    modal.classList.remove("open");
    syncBodyLock();
  }

  function formatDateLabel(isoDate) {
    if (!isoDate) return "";
    const ts = new Date(isoDate + "T00:00:00");
    if (Number.isNaN(ts.getTime())) return isoDate;
    return ts.toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" });
  }

  function formatTimeLabel(time24) {
    if (!time24 || !/^\d{2}:\d{2}/.test(time24)) return "";
    const [hh, mm] = time24.split(":").map(Number);
    const period = hh >= 12 ? "PM" : "AM";
    const hour12 = hh % 12 === 0 ? 12 : hh % 12;
    return `${hour12}:${String(mm).padStart(2, "0")} ${period}`;
  }

  function formatAmountLabel(value) {
    const amount = Number.parseFloat(value || "");
    if (!(amount > 0)) return "";
    return amount.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function buildSmsTemplate() {
    const beneficiary = smsActiveContext.beneficiary || "Applicant";
    const reference = smsActiveContext.referenceNo || "";
    const dateLabel = formatDateLabel(dateInput.value || "") || "the scheduled date";
    const timeLabel = formatTimeLabel(timeInput.value || "") || "the scheduled time";
    const venue = (venueInput.value || "PSWDO Office").trim() || "PSWDO Office";
    const amountLabel = amountInput instanceof HTMLInputElement ? formatAmountLabel(amountInput.value) : "";
    const amountLine = amountLabel ? `Amount: PHP ${amountLabel}\n` : "";
    return `Good day, ${beneficiary}.\nThis is from the PSWDO – Assistance to Individuals in Crisis Situation (AICS) Program.\nYour application, ${reference}, is scheduled for assistance release on:\nDate: ${dateLabel}\nTime: ${timeLabel}\nVenue: ${venue}\n${amountLine}Please arrive on time and bring your valid ID and required documents.\nThank you.`;
  }

  function refreshSmsTemplate(force) {
    if (!(smsMessageInput instanceof HTMLTextAreaElement)) return;
    if (!force && smsActiveContext.manuallyEdited) return;
    smsMessageInput.value = buildSmsTemplate();
  }

  function syncSmsFieldsVisibility() {
    if (!(smsFields instanceof HTMLElement) || !(smsCheckbox instanceof HTMLInputElement)) return;
    smsFields.style.display = smsCheckbox.checked ? "" : "none";
  }

  function openModal(button) {
    const requestId = button.getAttribute("data-request-id") || "";
    const referenceNo = button.getAttribute("data-reference-no") || "-";
    const beneficiary = button.getAttribute("data-beneficiary-name") || "Applicant";
    const assistance = button.getAttribute("data-assistance-label") || "Assistance Request";
    const classification = button.getAttribute("data-client-categories") || "None";
    const scheduleDate = button.getAttribute("data-scheduled-date") || "";
    const scheduleTime = button.getAttribute("data-scheduled-time") || "";
    const venue = button.getAttribute("data-venue") || "PSWDO Office";
    const batch = button.getAttribute("data-release-batch") || "";
    const notes = button.getAttribute("data-release-notes") || "";
    const contactNumber = button.getAttribute("data-contact-number") || "";
    const amountGiven = button.getAttribute("data-amount-given") || "";

    if (!/^\d+$/.test(requestId)) {
      return;
    }

    requestIdInput.value = requestId;
    if (amountInput instanceof HTMLInputElement) amountInput.value = amountGiven;
    titleRef.textContent = referenceNo;
    metaBeneficiary.textContent = beneficiary;
    metaDetails.textContent = `${assistance} · ${classification}`;
    dateInput.value = scheduleDate || dateInput.getAttribute("data-default-value") || "";
    timeInput.value = scheduleTime || timeInput.getAttribute("data-default-value") || "";
    venueInput.value = venue || "PSWDO Office";
    notesInput.value = notes;

    smsActiveContext = {
      beneficiary,
      referenceNo: referenceNo === "-" ? "" : referenceNo,
      contactNumber,
      batch,
      manuallyEdited: false,
    };

    if (smsRecipientInput instanceof HTMLInputElement) {
      smsRecipientInput.value = contactNumber || "(no contact number on file)";
    }

    if (smsCheckbox instanceof HTMLInputElement) {
      const hasContact = contactNumber.trim() !== "";
      smsCheckbox.checked = hasContact;
      smsCheckbox.disabled = !hasContact;
    }
    refreshSmsTemplate(true);
    syncSmsFieldsVisibility();

    modal.classList.add("open");
    syncBodyLock();
    dateInput.focus();
  }

  openButtons.forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    button.addEventListener("click", () => openModal(button));
    if (autoOpenRequestId !== "" && button.getAttribute("data-request-id") === autoOpenRequestId) {
      openModal(button);
    }
  });

  [dateInput, timeInput, venueInput, amountInput].forEach((field) => {
    if (field instanceof HTMLInputElement) {
      field.addEventListener("input", () => refreshSmsTemplate(false));
      field.addEventListener("change", () => refreshSmsTemplate(false));
    }
  });

  if (smsMessageInput instanceof HTMLTextAreaElement) {
    smsMessageInput.addEventListener("input", () => {
      smsActiveContext.manuallyEdited = true;
    });
  }

  if (smsCheckbox instanceof HTMLInputElement) {
    smsCheckbox.addEventListener("change", syncSmsFieldsVisibility);
  }

  form.addEventListener("submit", (event) => {
    if (!/^\d+$/.test(requestIdInput.value.trim())) {
      event.preventDefault();
      return;
    }
    if (dateInput.value.trim() === "" || timeInput.value.trim() === "" || venueInput.value.trim() === "") {
      event.preventDefault();
    }
  });

  if (closeBtn) {
    closeBtn.addEventListener("click", closeModal);
  }
  if (cancelBtn) {
    cancelBtn.addEventListener("click", closeModal);
  }

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("open")) {
      closeModal();
    }
  });
})();

(() => {
  function syncBodyLock() {
    const sidebar = document.getElementById("sidebar");
    const hasOpenSidebar = Boolean(sidebar && sidebar.classList.contains("open"));
    const hasOpenModal = Boolean(document.querySelector(".confirm-modal.open, .request-details-modal.open, .file-preview-modal.open, .ai-signal-modal.open, .sms-compose-modal.open, .request-action-popup-modal.open, .release-schedule-modal.open"));
    document.body.classList.toggle("modal-open", hasOpenSidebar || hasOpenModal);
  }

  const modal = document.getElementById("bulk-schedule-modal");
  const form = document.getElementById("bulk-schedule-form");
  const openBtn = document.getElementById("open-bulk-schedule-btn");
  const closeBtn = document.getElementById("close-bulk-schedule-modal");
  const cancelBtn = document.getElementById("cancel-bulk-schedule");
  const dateInput = document.getElementById("bulk-modal-date");
  const timeInput = document.getElementById("bulk-modal-time");
  const venueInput = document.getElementById("bulk-modal-venue");

  if (
    !(modal instanceof HTMLElement) ||
    !(form instanceof HTMLFormElement) ||
    !(dateInput instanceof HTMLInputElement) ||
    !(timeInput instanceof HTMLInputElement) ||
    !(venueInput instanceof HTMLInputElement)
  ) {
    return;
  }

  function closeModal() {
    modal.classList.remove("open");
    syncBodyLock();
  }

  function openModal() {
    modal.classList.add("open");
    syncBodyLock();
    dateInput.focus();
  }

  if (openBtn) {
    openBtn.addEventListener("click", openModal);
  }

  form.addEventListener("submit", (event) => {
    if (dateInput.value.trim() === "" || timeInput.value.trim() === "" || venueInput.value.trim() === "") {
      event.preventDefault();
    }
  });

  if (closeBtn) {
    closeBtn.addEventListener("click", closeModal);
  }
  if (cancelBtn) {
    cancelBtn.addEventListener("click", closeModal);
  }

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("open")) {
      closeModal();
    }
  });
})();

(() => {
  function syncBodyLock() {
    const sidebar = document.getElementById("sidebar");
    const hasOpenSidebar = Boolean(sidebar && sidebar.classList.contains("open"));
    const hasOpenModal = Boolean(document.querySelector(".confirm-modal.open, .request-details-modal.open, .file-preview-modal.open, .ai-signal-modal.open, .sms-compose-modal.open, .request-action-popup-modal.open, .release-schedule-modal.open"));
    document.body.classList.toggle("modal-open", hasOpenSidebar || hasOpenModal);
  }

  const modal = document.getElementById("confirm-modal");
  if (!modal) {
    return;
  }

  const titleEl = document.getElementById("confirm-title");
  const messageEl = document.getElementById("confirm-message");
  const cancelBtn = document.getElementById("confirm-cancel");
  const acceptBtn = document.getElementById("confirm-accept");
  const actionPopupModal = document.getElementById("request-action-popup-modal");
  const actionPopupContent = document.getElementById("request-action-popup-content");
  if (!titleEl || !messageEl || !cancelBtn || !acceptBtn) {
    return;
  }

  let pendingForm = null;
  let pendingSubmission = null;
  let infoOnlyModal = false;
  const detailsModal = document.getElementById("request-details-modal");

  function closeModal() {
    modal.classList.remove("open");
    syncBodyLock();
    pendingForm = null;
    pendingSubmission = null;
    infoOnlyModal = false;
    acceptBtn.removeAttribute("disabled");
    acceptBtn.textContent = "Continue";
    cancelBtn.textContent = "Cancel";
    cancelBtn.style.display = '';
  }

  function buildSubmissionPayload(form) {
    const method = (form.getAttribute("method") || "post").toLowerCase();
    const action = form.getAttribute("action") || window.location.pathname;
    const fields = [];
    const data = new FormData(form);
    data.forEach((value, key) => {
      if (typeof value === "string") {
        fields.push({ key, value });
      }
    });
    return { method, action, fields };
  }

  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    if (!form.classList.contains("js-confirm-action")) {
      return;
    }

    event.preventDefault();
    if (actionPopupModal instanceof HTMLElement && actionPopupModal.classList.contains("open")) {
      actionPopupModal.classList.remove("open");
    }

    const isApproveScreeningForm = form.id === "approve-screening-form";
    const alreadyApproved = isApproveScreeningForm && form.getAttribute("data-already-approved") === "1";
    if (alreadyApproved) {
      pendingForm = null;
      pendingSubmission = null;
      infoOnlyModal = true;
      titleEl.textContent = "Already Approved";
      messageEl.textContent = "This applicant is already approved for screening.";
      acceptBtn.removeAttribute("disabled");
      acceptBtn.textContent = "OK";
      cancelBtn.style.display = 'none';
      modal.classList.add("open");
      syncBodyLock();
      return;
    }
    if (isApproveScreeningForm && form.getAttribute("data-staff-review-incomplete") === "1") {
      showRuntimeToast("Review all required documents before approving this application.", "error");
      return;
    }
    if (isApproveScreeningForm && form.getAttribute("data-docs-not-approved") === "1") {
      showRuntimeToast("Resolve the required document issues before approving this application.", "error");
      return;
    }

    pendingForm = form;
    pendingSubmission = buildSubmissionPayload(form);
    const title = form.getAttribute("data-title") || "Confirm Action";
    const message = form.getAttribute("data-message") || "Are you sure you want to continue?";
    titleEl.textContent = title;
    messageEl.textContent = message;
    acceptBtn.removeAttribute("disabled");
    acceptBtn.textContent = "Continue";
    cancelBtn.textContent = "Cancel";
    cancelBtn.style.display = '';
    modal.classList.add("open");
    syncBodyLock();
  });

  cancelBtn.addEventListener("click", closeModal);

  acceptBtn.addEventListener("click", () => {
    if (infoOnlyModal) {
      closeModal();
      return;
    }
    if (!pendingSubmission) {
      closeModal();
      return;
    }
    acceptBtn.setAttribute("disabled", "disabled");

    const submitForm = document.createElement("form");
    submitForm.method = pendingSubmission.method === "get" ? "get" : "post";
    submitForm.action = pendingSubmission.action;
    submitForm.style.display = "none";

    pendingSubmission.fields.forEach((field) => {
      const input = document.createElement("input");
      input.type = "hidden";
      input.name = field.key;
      input.value = field.value;
      submitForm.appendChild(input);
    });

    document.body.appendChild(submitForm);
    submitForm.submit();
  });

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("open")) {
      closeModal();
    }
  });
})();

(() => {
  const root = document.body;
  function syncBodyLock() {
    const sidebar = document.getElementById("sidebar");
    const hasOpenSidebar = Boolean(sidebar && sidebar.classList.contains("open"));
    const hasOpenModal = Boolean(document.querySelector(".confirm-modal.open, .request-details-modal.open, .file-preview-modal.open, .ai-signal-modal.open, .sms-compose-modal.open, .request-action-popup-modal.open, .release-schedule-modal.open"));
    document.body.classList.toggle("modal-open", hasOpenSidebar || hasOpenModal);
  }

  function getFileExtension(url) {
    try {
      const cleanUrl = url.split("?")[0].split("#")[0];
      const segments = cleanUrl.split(".");
      if (segments.length <= 1) {
        return "";
      }
      return segments.pop().toLowerCase();
    } catch (_error) {
      return "";
    }
  }

  function isInlinePreviewType(extension) {
    return ["pdf", "txt", "csv", "json", "xml"].includes(extension);
  }

  function isImagePreviewType(extension) {
    return ["jpg", "jpeg", "png", "gif", "bmp", "webp", "avif"].includes(extension);
  }

  const detailsModal = document.getElementById("request-details-modal");
  const detailsContent = document.getElementById("request-details-content");
  const closeDetailsBtn = document.getElementById("close-request-details-modal");
  const exportPdfBtn = document.getElementById("export-request-pdf-btn");
  const printGisBtn = document.getElementById("print-gis-btn");
  const openActionsBtn = document.getElementById("open-request-actions-popup");
  const actionPopupModal = document.getElementById("request-action-popup-modal");
  const actionPopupContent = document.getElementById("request-action-popup-content");
  const closeActionsBtn = document.getElementById("close-request-actions-popup");
  const reopenRequestId = (document.body?.getAttribute("data-reopen-request-id") || "").trim();
  const reopenAiDocumentId = (document.body?.getAttribute("data-reopen-ai-document-id") || "").trim();
  const exportBaseUrl = (detailsModal?.getAttribute("data-export-base-url") || "").trim();
  const gisBaseUrl = (detailsModal?.getAttribute("data-gis-base-url") || "").trim();
  const filePreviewModal = document.getElementById("file-preview-modal");
  const closeFilePreviewBtn = document.getElementById("close-file-preview-modal");
  const filePreviewStage = document.getElementById("file-preview-stage");
  const filePreviewName = document.getElementById("file-preview-name");
  const filePreviewLabel = document.getElementById("file-preview-label");
  const filePreviewApproveBtn = document.getElementById("file-preview-approve-btn");
  const filePreviewBackApproveBtn = document.getElementById("file-preview-back-approve-btn");
  const approveScreeningForm = document.getElementById("approve-screening-form");
  const approveScreeningBtn = document.getElementById("approve-screening-btn");
  const approveScreeningRequestIdInput = document.getElementById("approve-screening-request-id");
  const rejectRequestBtn = document.getElementById("reject-request-btn");
  const rejectReasonModal = document.getElementById("reject-reason-modal");
  const rejectReasonForm = document.getElementById("reject-reason-form");
  const rejectRequestIdInput = document.getElementById("reject-request-id");
  const cancelRejectModalBtn = document.getElementById("cancel-reject-modal");
  let activeRequestTerminalLocked = false;
  let activeDocumentActionsLocked = false;
  let activeRequestContactNumber = "";
  let activeRequestReferenceNo = "";
  let activeRequestBeneficiaryName = "";
  let activeRequestApplicantName = "";
  let activeRequiredDocumentTotal = 0;
  let activeStaffReviewedTotal = 0;
  const ensureAiEndpoint = root instanceof HTMLBodyElement ? (root.getAttribute("data-ai-ensure-url") || "").trim() : "";
  const debug = typeof window.__aicsRequestManagementDebug === "function"
    ? window.__aicsRequestManagementDebug
    : null;

  if (!(detailsModal instanceof HTMLElement) || !(detailsContent instanceof HTMLElement)) {
    debug?.("modal found", false);
    return;
  }

  function setExportButton(requestId) {
    if (!(exportPdfBtn instanceof HTMLAnchorElement)) {
      return;
    }

    const cleanId = (requestId || "").toString().trim();
    if (!/^\d+$/.test(cleanId) || exportBaseUrl === "") {
      exportPdfBtn.setAttribute("href", "#");
      exportPdfBtn.setAttribute("aria-disabled", "true");
      exportPdfBtn.classList.add("is-disabled");
      exportPdfBtn.hidden = false;
      exportPdfBtn.style.display = "";
      return;
    }

    const url = new URL(exportBaseUrl, window.location.href);
    url.searchParams.set("request_id", cleanId);
    url.searchParams.set("format", "pdf");
    exportPdfBtn.setAttribute("href", url.toString());
    exportPdfBtn.removeAttribute("aria-disabled");
    exportPdfBtn.classList.remove("is-disabled");
    exportPdfBtn.hidden = false;
    exportPdfBtn.style.display = "";
  }

  function setGisButton(requestId, isApproved = false) {
    if (!(printGisBtn instanceof HTMLAnchorElement)) {
      return;
    }

    const cleanId = (requestId || "").toString().trim();
    if (!isApproved || !/^\d+$/.test(cleanId) || gisBaseUrl === "") {
      printGisBtn.setAttribute("href", "#");
      printGisBtn.setAttribute("aria-disabled", "true");
      printGisBtn.classList.add("is-disabled");
      printGisBtn.hidden = true;
      return;
    }

    const url = new URL(gisBaseUrl, window.location.href);
    url.searchParams.set("request_id", cleanId);
    url.searchParams.set("format", "pdf");
    printGisBtn.setAttribute("href", url.toString());
    printGisBtn.removeAttribute("aria-disabled");
    printGisBtn.classList.remove("is-disabled");
    printGisBtn.hidden = false;
  }

  function closeActionPopup() {
    if (!(actionPopupModal instanceof HTMLElement)) {
      return;
    }
    actionPopupModal.classList.remove("open");
    if (actionPopupContent instanceof HTMLElement) {
      actionPopupContent.innerHTML = '<p class="empty-note">No actions available for this request.</p>';
    }
    syncBodyLock();
  }

  function openActionPopup() {
    if (
      !(actionPopupModal instanceof HTMLElement) ||
      !(actionPopupContent instanceof HTMLElement) ||
      !(detailsContent instanceof HTMLElement)
    ) {
      return;
    }

    const actionSource = detailsContent.querySelector(".js-request-action-source");
    actionPopupContent.innerHTML = "";
    if (actionSource instanceof HTMLElement) {
      Array.from(actionSource.children).forEach((child) => {
        actionPopupContent.appendChild(child.cloneNode(true));
      });
    }

    if (actionPopupContent.children.length === 0) {
      actionPopupContent.innerHTML = '<p class="empty-note">No actions available for this request.</p>';
    }

    actionPopupModal.classList.add("open");
    syncBodyLock();
  }

  function closeFilePreviewModal() {
    if (!filePreviewModal) {
      return;
    }
    filePreviewModal.classList.remove("open");
    syncBodyLock();
  }

  function renderFilePreviewContent(stage, fileUrl, fileName, fileMime = "") {
    if (!fileUrl) {
      const emptyState = document.createElement("p");
      emptyState.className = "file-preview-empty";
      emptyState.textContent = "No file URL found for this document.";
      stage.appendChild(emptyState);
      return;
    }

    const extension = getFileExtension(fileName || fileUrl);
    const mime = fileMime.toLowerCase().trim();
    const isImage = ["image/jpeg", "image/png", "image/webp", "image/gif"].includes(mime) || isImagePreviewType(extension);
    const isPdf = mime === "application/pdf" || extension === "pdf";

    if (isImage) {
      const image = document.createElement("img");
      image.src = fileUrl;
      image.alt = fileName || "Uploaded document image";
      image.className = "file-preview-image";
      stage.appendChild(image);
      return;
    }

    if (isPdf) {
      const frame = document.createElement("iframe");
      frame.src = fileUrl;
      frame.className = "file-preview-frame";
      frame.setAttribute("title", fileName || "Uploaded document preview");
      stage.appendChild(frame);
      return;
    }

    const fallback = document.createElement("div");
    fallback.className = "file-preview-fallback";
    fallback.innerHTML = `
      <i class="fas fa-file-circle-question"></i>
      <h4>Preview unavailable for this file type</h4>
      <p>Use <strong>Open in New Tab</strong> or <strong>Download File</strong> to inspect this document.</p>
    `;
    stage.appendChild(fallback);
  }

  function renderFilePreview(fileUrl, fileName, fileLabel, fileMime = "", backFileUrl = "", backFileName = "", backFileMime = "") {
    if (!filePreviewModal || !filePreviewStage || !filePreviewName || !filePreviewLabel) {
      return;
    }

    filePreviewName.textContent = backFileUrl ? "Front and back" : (fileName || "Uploaded file");
    filePreviewLabel.textContent = fileLabel || "Document";
    filePreviewStage.replaceChildren();
    filePreviewStage.classList.toggle("is-paired", Boolean(backFileUrl));

    if (backFileUrl) {
      [["Front", fileUrl, fileName, fileMime], ["Back", backFileUrl, backFileName, backFileMime]].forEach(([side, url, name, mime]) => {
        const panel = document.createElement("section");
        panel.className = "file-preview-panel";
        const heading = document.createElement("h4");
        heading.textContent = `${side} · ${name || "Uploaded file"}`;
        const surface = document.createElement("div");
        surface.className = "file-preview-surface";
        renderFilePreviewContent(surface, url, name, mime);
        panel.append(heading, surface);
        filePreviewStage.appendChild(panel);
      });
      return;
    }

    renderFilePreviewContent(filePreviewStage, fileUrl, fileName, fileMime);
  }

  function openFilePreviewModal(fileUrl, fileName, fileLabel, fileMime = "", backFileUrl = "", backFileName = "", backFileMime = "") {
    if (!filePreviewModal) {
      return;
    }

    renderFilePreview(fileUrl, fileName, fileLabel, fileMime, backFileUrl, backFileName, backFileMime);
    filePreviewModal.classList.add("open");
    syncBodyLock();
  }

  function closeRejectModal() {
    if (rejectReasonModal instanceof HTMLElement) {
      rejectReasonModal.classList.remove("open");
    }
    if (rejectRequestIdInput instanceof HTMLInputElement) {
      rejectRequestIdInput.value = "";
    }
    const reasonTextarea = document.getElementById("reject-reason-text");
    if (reasonTextarea instanceof HTMLTextAreaElement) {
      reasonTextarea.value = "";
    }
    syncBodyLock();
  }

  function closeDetailsModal() {
    closeActionPopup();
    closeRejectModal();
    detailsModal.classList.remove("open");
    closeFilePreviewModal();
    setExportButton("");
    setGisButton("", false);
    activeRequestTerminalLocked = false;
    activeDocumentActionsLocked = false;
    activeRequiredDocumentTotal = 0;
    activeStaffReviewedTotal = 0;
    if (approveScreeningBtn instanceof HTMLButtonElement) {
      approveScreeningBtn.disabled = true;
    }
    if (approveScreeningRequestIdInput instanceof HTMLInputElement) {
      approveScreeningRequestIdInput.value = "";
    }
    if (approveScreeningForm instanceof HTMLFormElement) {
      approveScreeningForm.setAttribute("data-already-approved", "0");
      approveScreeningForm.hidden = false;
    }
    if (rejectRequestBtn instanceof HTMLButtonElement) {
      rejectRequestBtn.disabled = true;
      rejectRequestBtn.hidden = false;
    }
    if (exportPdfBtn instanceof HTMLAnchorElement) {
      exportPdfBtn.hidden = false;
      exportPdfBtn.style.display = "";
    }
    syncBodyLock();
  }

  async function ensureCurrentAiReviews(requestId) {
    if (ensureAiEndpoint === "" || !/^\d+$/.test(String(requestId))) return;
    const csrf = root.querySelector('input[name="csrf_token"]');
    if (!(csrf instanceof HTMLInputElement)) return;
    const body = new FormData();
    body.set("csrf_token", csrf.value);
    body.set("request_id", String(requestId));
    try {
      const response = await fetch(ensureAiEndpoint, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" }, body });
      const payload = await response.json();
      if (!response.ok || !payload || payload.ok !== true) return;
      document.dispatchEvent(new CustomEvent("aics:ensure-current-review", { detail: { requestId: String(requestId), queued: payload.queued || [], skippedUnavailable: payload.skipped_unavailable || [] } }));
    } catch (_error) {
      // The visible application stays usable; the health indicator reports service availability separately.
    }
  }

  function prepareModalAiDetails() {
    detailsContent.querySelectorAll("tr[data-document-id]").forEach((row) => {
      if (!(row instanceof HTMLTableRowElement)) return;
      const cell = row.querySelector(".ai-verification-cell");
      const toggle = cell?.querySelector(".ai-details-toggle");
      const panel = cell?.querySelector(".ai-details-panel");
      const documentId = (row.getAttribute("data-document-id") || "").trim();
      if (!(cell instanceof HTMLElement) || !(toggle instanceof HTMLButtonElement) || !(panel instanceof HTMLDetailsElement) || !/^\d+$/.test(documentId)) return;
      let detailRow = row.nextElementSibling;
      if (!(detailRow instanceof HTMLTableRowElement) || !detailRow.classList.contains("ai-details-row")) {
        detailRow = document.createElement("tr");
        detailRow.className = "ai-details-row";
        const detailCell = document.createElement("td");
        detailRow.appendChild(detailCell);
        row.insertAdjacentElement("afterend", detailRow);
      }
      detailRow.cells[0].colSpan = row.cells.length;
      detailRow.hidden = true;
      panel.id = `modal-ai-details-${documentId}`;
      panel.open = false;
      if (panel.parentElement !== detailRow.cells[0]) detailRow.cells[0].appendChild(panel);
      toggle.setAttribute("aria-controls", panel.id);
      toggle.setAttribute("aria-expanded", "false");
      toggle.textContent = "Details";
    });
  }

  function reopenAiDocumentDetails(documentId) {
    if (!/^\d+$/.test(String(documentId))) return;
    const row = detailsContent.querySelector(`tr[data-document-id="${CSS.escape(String(documentId))}"]`);
    if (!(row instanceof HTMLTableRowElement)) return;
    const toggle = row.querySelector(".ai-details-toggle");
    const detailRow = row.nextElementSibling;
    const panel = detailRow instanceof HTMLTableRowElement ? detailRow.querySelector(".ai-details-panel") : null;
    if (!(toggle instanceof HTMLButtonElement) || !(detailRow instanceof HTMLTableRowElement) || !(panel instanceof HTMLDetailsElement)) return;
    panel.open = true;
    detailRow.hidden = false;
    toggle.setAttribute("aria-expanded", "true");
    toggle.textContent = "Hide details";
    window.requestAnimationFrame(() => panel.scrollIntoView({ block: "start" }));
  }

  function openDetailsModal(requestId) {
    const template = document.getElementById(`request-detail-source-${requestId}`);
    debug?.("template found", template instanceof HTMLTemplateElement);
    debug?.("modal found", detailsModal instanceof HTMLElement);
    if (!(template instanceof HTMLTemplateElement)) {
      showRuntimeToast("Unable to load application details. Please try again.");
      return false;
    }

    detailsContent.innerHTML = "";
    detailsContent.appendChild(template.content.cloneNode(true));
    prepareModalAiDetails();
    document.dispatchEvent(new CustomEvent("aics:ai-document-rows-mounted"));

    if (openActionsBtn instanceof HTMLButtonElement) {
      const actionSource = detailsContent.querySelector(".js-request-action-source");
      const hasActions = actionSource instanceof HTMLElement && actionSource.children.length > 0;
      openActionsBtn.disabled = !hasActions;
    }

    setExportButton(requestId);

    if (
      approveScreeningForm instanceof HTMLFormElement &&
      approveScreeningBtn instanceof HTMLButtonElement &&
      approveScreeningRequestIdInput instanceof HTMLInputElement
    ) {
      const approveSourceForm = detailsContent.querySelector(
        ".js-request-action-source form.js-confirm-action input[name='action_type'][value='view_request']"
      );
      const sourceInput = approveSourceForm instanceof HTMLInputElement
        ? approveSourceForm.parentElement?.querySelector("input[name='request_id']")
        : null;
      const sourceRequestId = sourceInput instanceof HTMLInputElement ? (sourceInput.value || "").trim() : "";
      const detailShell = detailsContent.querySelector(".request-detail-shell");
      const requiredTotal = detailShell instanceof HTMLElement
        ? Number.parseInt(detailShell.getAttribute("data-required-total") || "0", 10)
        : 0;
      const checkedTotal = detailShell instanceof HTMLElement
        ? Number.parseInt(detailShell.getAttribute("data-checked-total") || "0", 10)
        : 0;
      const staffReviewedTotal = detailShell instanceof HTMLElement
        ? Number.parseInt(detailShell.getAttribute("data-staff-reviewed-total") || "0", 10)
        : 0;
      const statusCode = detailShell instanceof HTMLElement
        ? (detailShell.getAttribute("data-status-code") || "").trim().toLowerCase()
        : "";
      setGisButton(requestId, statusCode === "approved");
      activeRequestTerminalLocked = detailShell instanceof HTMLElement
        && detailShell.getAttribute("data-terminal-locked") === "1";
      activeDocumentActionsLocked = detailShell instanceof HTMLElement
        && detailShell.getAttribute("data-document-actions-locked") === "1";
      activeRequestContactNumber = detailShell instanceof HTMLElement
        ? (detailShell.getAttribute("data-contact-number") || "").trim() : "";
      activeRequestReferenceNo = detailShell instanceof HTMLElement
        ? (detailShell.getAttribute("data-reference-no") || "").trim() : "";
      activeRequestBeneficiaryName = detailShell instanceof HTMLElement
        ? (detailShell.getAttribute("data-beneficiary-name") || "Applicant").trim() : "Applicant";
      activeRequestApplicantName = detailShell instanceof HTMLElement
        ? (detailShell.getAttribute("data-applicant-name") || "Applicant").trim() : "Applicant";
      const allDocsChecked = requiredTotal === 0 || (
        Number.isFinite(requiredTotal)
        && Number.isFinite(checkedTotal)
        && checkedTotal >= requiredTotal
      );
      const allStaffReviewed = requiredTotal === 0 || (
        Number.isFinite(requiredTotal)
        && Number.isFinite(staffReviewedTotal)
        && staffReviewedTotal >= requiredTotal
      );
      activeRequiredDocumentTotal = Number.isFinite(requiredTotal) ? requiredTotal : 0;
      activeStaffReviewedTotal = Number.isFinite(staffReviewedTotal) ? staffReviewedTotal : 0;
      const alreadyApproved = statusCode === "approved";

      if (exportPdfBtn instanceof HTMLAnchorElement) {
        exportPdfBtn.hidden = activeRequestTerminalLocked;
        exportPdfBtn.style.display = activeRequestTerminalLocked ? "none" : "";
      }
      approveScreeningForm.hidden = activeRequestTerminalLocked;

      const blockedButtons = detailsContent.querySelectorAll(".btn-doc-update");
      blockedButtons.forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
          return;
        }
        button.setAttribute("data-terminal-locked", activeRequestTerminalLocked ? "1" : "0");
        button.setAttribute("data-doc-actions-locked", activeDocumentActionsLocked ? "1" : "0");
        button.setAttribute("aria-disabled", activeDocumentActionsLocked ? "true" : "false");
        button.classList.toggle("is-disabled", activeDocumentActionsLocked);
      });

      const previewButtons = detailsContent.querySelectorAll(".js-open-file-preview");
      previewButtons.forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
          return;
        }
        button.setAttribute("data-terminal-locked", activeRequestTerminalLocked ? "1" : "0");
        button.setAttribute("aria-disabled", activeRequestTerminalLocked ? "true" : "false");
        button.disabled = activeRequestTerminalLocked;
        button.classList.toggle("is-disabled", activeRequestTerminalLocked);
      });

      if (/^\d+$/.test(sourceRequestId) && !activeRequestTerminalLocked) {
        approveScreeningRequestIdInput.value = sourceRequestId;
        const docsIncomplete = !allDocsChecked;
        // Keep this enabled for an incomplete review so the delegated submit
        // handler can explain the server-enforced rule instead of leaving a
        // seemingly unresponsive disabled action.
        approveScreeningBtn.disabled = alreadyApproved;
        if (alreadyApproved) {
          approveScreeningBtn.title = "This request has already been approved for screening.";
        } else if (docsIncomplete) {
          approveScreeningBtn.title = requiredTotal > 0
            ? `Review and approve all required documents first (${checkedTotal}/${requiredTotal} checked).`
            : "No required documents are configured for this assistance type.";
        } else {
          approveScreeningBtn.title = "";
        }
        approveScreeningForm.setAttribute("data-already-approved", alreadyApproved ? "1" : "0");
        approveScreeningForm.setAttribute("data-docs-incomplete", docsIncomplete ? "1" : "0");
        approveScreeningForm.setAttribute("data-staff-review-incomplete", allStaffReviewed ? "0" : "1");
        approveScreeningForm.setAttribute("data-docs-not-approved", allDocsChecked ? "0" : "1");
        approveScreeningForm.setAttribute("data-checked-total", checkedTotal.toString());
        approveScreeningForm.setAttribute("data-required-total", requiredTotal.toString());
      } else {
        approveScreeningRequestIdInput.value = "";
        approveScreeningBtn.disabled = true;
        approveScreeningBtn.title = "";
        approveScreeningForm.setAttribute("data-already-approved", "0");
        approveScreeningForm.setAttribute("data-docs-incomplete", "0");
        approveScreeningForm.setAttribute("data-staff-review-incomplete", "0");
        approveScreeningForm.setAttribute("data-docs-not-approved", "0");
      }

      if (rejectRequestBtn instanceof HTMLButtonElement && rejectRequestIdInput instanceof HTMLInputElement) {
        if (activeRequestTerminalLocked) {
          rejectRequestBtn.disabled = true;
          rejectRequestBtn.hidden = true;
        } else {
          rejectRequestIdInput.value = /^\d+$/.test(sourceRequestId) ? sourceRequestId : requestId.toString();
          rejectReasonForm?.setAttribute("data-staff-review-incomplete", allStaffReviewed ? "0" : "1");
          rejectRequestBtn.disabled = false;
          rejectRequestBtn.hidden = false;
        }
      }
    }

    debug?.("modal open called");
    detailsModal.classList.add("open");
    syncBodyLock();
    const modalStyle = window.getComputedStyle(detailsModal);
    const modalRect = detailsModal.getBoundingClientRect();
    const modalVisible = modalStyle.display !== "none"
      && modalStyle.visibility !== "hidden"
      && Number.parseFloat(modalStyle.opacity || "1") !== 0
      && modalRect.width > 0
      && modalRect.height > 0;
    debug?.("modal visible", modalVisible, {
      display: modalStyle.display,
      visibility: modalStyle.visibility,
      opacity: modalStyle.opacity,
      className: detailsModal.className,
      width: modalRect.width,
      height: modalRect.height,
    });
    void ensureCurrentAiReviews(requestId);
    return true;
  }

  function clearReopenRequestParam() {
    try {
      const url = new URL(window.location.href);
      if (!url.searchParams.has("reopen_request") && !url.searchParams.has("reopen_ai_document")) {
        return;
      }
      url.searchParams.delete("reopen_request");
      url.searchParams.delete("reopen_ai_document");
      window.history.replaceState({}, "", url.toString());
    } catch (_error) {
      // no-op: if URL API fails, keep current URL.
    }
  }

  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const button = target.closest(".js-open-request-modal");
    if (!(button instanceof HTMLButtonElement)) return;

    const requestId = (button.getAttribute("data-request-id") || "").trim();
    debug?.("view click", requestId || "missing", {
      selector: ".js-open-request-modal",
      className: button.className,
      disabled: button.disabled,
      pointerEvents: window.getComputedStyle(button).pointerEvents,
    });
    if (!/^\d+$/.test(requestId)) {
      showRuntimeToast("Unable to load application details because the request ID is missing.");
      return;
    }
    if (button.dataset.opening === "1") return;

    button.dataset.opening = "1";
    button.setAttribute("aria-busy", "true");
    try {
      openDetailsModal(requestId);
    } catch (_error) {
      showRuntimeToast("Unable to load application details. Please try again.");
    } finally {
      button.dataset.opening = "0";
      button.removeAttribute("aria-busy");
    }
  });
  window.__aicsRequestModalHandlerAvailable = true;

  if (/^\d+$/.test(reopenRequestId)) {
    openDetailsModal(reopenRequestId);
    reopenAiDocumentDetails(reopenAiDocumentId);
    clearReopenRequestParam();
  }

  if (closeDetailsBtn) {
    closeDetailsBtn.addEventListener("click", closeDetailsModal);
  }

  if (openActionsBtn instanceof HTMLButtonElement) {
    openActionsBtn.addEventListener("click", openActionPopup);
  }

  if (closeActionsBtn) {
    closeActionsBtn.addEventListener("click", closeActionPopup);
  }

  detailsModal.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    const aiToggle = target.closest(".ai-details-toggle");
    if (aiToggle instanceof HTMLButtonElement && detailsModal.contains(aiToggle)) {
      const panelId = aiToggle.getAttribute("aria-controls") || "";
      const panel = panelId !== "" ? detailsContent.querySelector(`#${CSS.escape(panelId)}`) : null;
      const detailRow = panel instanceof HTMLDetailsElement ? panel.closest("tr.ai-details-row") : null;
      if (!(panel instanceof HTMLDetailsElement) || !(detailRow instanceof HTMLTableRowElement)) return;
      const expanded = aiToggle.getAttribute("aria-expanded") !== "true";
      panel.open = expanded;
      detailRow.hidden = !expanded;
      aiToggle.setAttribute("aria-expanded", String(expanded));
      aiToggle.textContent = expanded ? "Hide details" : "Details";
      return;
    }

    const previewButton = target.closest(".js-open-file-preview");
    if (previewButton instanceof HTMLButtonElement) {
      if (previewButton.getAttribute("data-terminal-locked") === "1") {
        showRuntimeToast("This applicant is already cancelled or not approved. File preview is no longer available.");
        return;
      }
      const fileUrl = previewButton.getAttribute("data-file-url") || "";
      const fileName = previewButton.getAttribute("data-file-name") || "Uploaded file";
      const fileLabel = previewButton.getAttribute("data-file-label") || "Document";
      const fileMime = previewButton.getAttribute("data-file-mime") || "";
      const backFileUrl = previewButton.getAttribute("data-back-file-url") || "";
      const backFileName = previewButton.getAttribute("data-back-file-name") || "Back of ID";
      const backFileMime = previewButton.getAttribute("data-back-file-mime") || "";
      const requestId = previewButton.getAttribute("data-request-id") || "";
      const documentId = previewButton.getAttribute("data-document-id") || "";
      const isChecked = (previewButton.getAttribute("data-is-checked") || "0") === "1";
      if (filePreviewApproveBtn instanceof HTMLButtonElement) {
        filePreviewApproveBtn.setAttribute("data-request-id", requestId);
        filePreviewApproveBtn.setAttribute("data-document-id", documentId);
        filePreviewApproveBtn.disabled = isChecked || activeRequestTerminalLocked;
        filePreviewApproveBtn.innerHTML = isChecked
          ? `<i class="fas fa-circle-check"></i> ${backFileUrl ? "Front Checked" : "Checked"}`
          : (activeRequestTerminalLocked
            ? '<i class="fas fa-lock"></i> Action Unavailable'
            : `<i class="fas fa-circle-check"></i> ${backFileUrl ? "Mark Front as Checked" : "Mark as Checked"}`);
      }
      if (filePreviewBackApproveBtn instanceof HTMLButtonElement) {
        const backChecked = (previewButton.getAttribute("data-back-is-checked") || "0") === "1";
        filePreviewBackApproveBtn.hidden = !backFileUrl;
        filePreviewBackApproveBtn.setAttribute("data-request-id", requestId);
        filePreviewBackApproveBtn.setAttribute("data-document-id", previewButton.getAttribute("data-back-document-id") || "");
        filePreviewBackApproveBtn.disabled = backChecked || activeRequestTerminalLocked;
        filePreviewBackApproveBtn.innerHTML = backChecked
          ? '<i class="fas fa-circle-check"></i> Back Checked'
          : '<i class="fas fa-circle-check"></i> Mark Back as Checked';
      }
      openFilePreviewModal(fileUrl, fileName, fileLabel, fileMime, backFileUrl, backFileName, backFileMime);
      return;
    }

    const blockedUpdateButton = target.closest(".btn-doc-update");
    if (blockedUpdateButton instanceof HTMLButtonElement && blockedUpdateButton.getAttribute("data-doc-actions-locked") === "1") {
      const terminalLocked = blockedUpdateButton.getAttribute("data-terminal-locked") === "1";
      showRuntimeToast(
        terminalLocked
          ? "This applicant is already cancelled or not approved. Document status updates are no longer allowed."
          : "Document status updates are no longer allowed at this stage of the request."
      );
      return;
    }

    if (event.target === detailsModal) {
      closeDetailsModal();
    }
  });

  if (actionPopupModal) {
    actionPopupModal.addEventListener("click", (event) => {
      if (event.target === actionPopupModal) {
        closeActionPopup();
      }
    });
  }

  if (closeFilePreviewBtn) {
    closeFilePreviewBtn.addEventListener("click", closeFilePreviewModal);
  }

  function submitPreviewCheck(button) {
    const requestId = (button.getAttribute("data-request-id") || "").trim();
    const documentId = (button.getAttribute("data-document-id") || "").trim();
    if (!/^\d+$/.test(requestId) || !/^\d+$/.test(documentId)) {
      return;
    }

    const csrf = document.querySelector('input[name="csrf_token"]');
    const csrfToken = csrf instanceof HTMLInputElement ? csrf.value : "";
    if (csrfToken.trim() === "") {
      return;
    }

    const form = document.createElement("form");
    form.method = "post";
    form.action = "workflow-action.php";
    form.style.display = "none";
    [
      ["csrf_token", csrfToken],
      ["action_type", "document_check"],
      ["request_id", requestId],
      ["document_id", documentId],
      ["is_checked", "1"],
      ["remarks", "Document checked by PSWDO from preview."],
      ["return_page", "requests"],
    ].forEach(([name, value]) => {
      const input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      input.value = value;
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }

  if (filePreviewApproveBtn instanceof HTMLButtonElement) {
    filePreviewApproveBtn.addEventListener("click", () => submitPreviewCheck(filePreviewApproveBtn));
  }
  if (filePreviewBackApproveBtn instanceof HTMLButtonElement) {
    filePreviewBackApproveBtn.addEventListener("click", () => submitPreviewCheck(filePreviewBackApproveBtn));
  }

  if (filePreviewModal) {
    filePreviewModal.addEventListener("click", (event) => {
      if (event.target === filePreviewModal) {
        closeFilePreviewModal();
      }
    });
  }

  if (exportPdfBtn instanceof HTMLAnchorElement) {
    exportPdfBtn.addEventListener("click", (event) => {
      if (exportPdfBtn.classList.contains("is-disabled")) {
        event.preventDefault();
      }
    });
  }

  if (rejectRequestBtn instanceof HTMLButtonElement && rejectReasonModal instanceof HTMLElement) {
    rejectRequestBtn.addEventListener("click", () => {
      if (rejectRequestBtn.disabled) {
        return;
      }
      if (activeRequiredDocumentTotal > 0 && activeStaffReviewedTotal < activeRequiredDocumentTotal) {
        showRuntimeToast("Review all required documents before rejecting this application.");
        return;
      }
      rejectReasonModal.classList.add("open");
      syncBodyLock();
      const reasonTextarea = document.getElementById("reject-reason-text");
      if (reasonTextarea instanceof HTMLTextAreaElement) {
        reasonTextarea.focus();
      }
    });
  }

  if (cancelRejectModalBtn instanceof HTMLButtonElement) {
    cancelRejectModalBtn.addEventListener("click", closeRejectModal);
  }

  if (rejectReasonModal instanceof HTMLElement) {
    rejectReasonModal.addEventListener("click", (event) => {
      if (event.target === rejectReasonModal) {
        closeRejectModal();
      }
    });
  }

  if (rejectReasonForm instanceof HTMLFormElement) {
    rejectReasonForm.addEventListener("submit", (event) => {
      const requestIdVal = (rejectRequestIdInput instanceof HTMLInputElement ? rejectRequestIdInput.value : "").trim();
      if (!/^\d+$/.test(requestIdVal)) {
        event.preventDefault();
        showRuntimeToast("Unable to reject this application because its request ID is missing. Please reopen the application and try again.");
        return;
      }
      if (rejectReasonForm.getAttribute("data-staff-review-incomplete") === "1") {
        event.preventDefault();
        showRuntimeToast("Review all required documents before rejecting this application.");
        return;
      }
      const reasonTextarea = document.getElementById("reject-reason-text");
      if (!(reasonTextarea instanceof HTMLTextAreaElement) || reasonTextarea.value.trim() === "") {
        event.preventDefault();
        showRuntimeToast("Please provide a reason for rejecting this application.");
        reasonTextarea?.focus();
        return;
      }
      reasonTextarea.value = reasonTextarea.value.trim();
      const submitButton = rejectReasonForm.querySelector('button[type="submit"]');
      if (submitButton instanceof HTMLButtonElement) {
        if (submitButton.disabled) {
          event.preventDefault();
          return;
        }
        submitButton.disabled = true;
        submitButton.setAttribute("aria-busy", "true");
      }
    });
  }

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
      return;
    }

    if (rejectReasonModal instanceof HTMLElement && rejectReasonModal.classList.contains("open")) {
      closeRejectModal();
      return;
    }

    if (actionPopupModal && actionPopupModal.classList.contains("open")) {
      closeActionPopup();
      return;
    }

    if (filePreviewModal && filePreviewModal.classList.contains("open")) {
      closeFilePreviewModal();
      return;
    }

    if (detailsModal.classList.contains("open")) {
      closeDetailsModal();
    }
  });
})();

// Reports & Lists: reveal older notification logs after the latest five.
(() => {
  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-notification-show-more]");
    if (!(button instanceof HTMLButtonElement)) return;

    const feed = button.closest("[data-notification-feed]");
    if (!(feed instanceof HTMLElement)) return;

    const hiddenItems = Array.from(feed.querySelectorAll("[data-notification-overflow][hidden]"));
    hiddenItems.forEach((item) => item.removeAttribute("hidden"));
    button.setAttribute("aria-expanded", "true");
    button.hidden = true;
    const buttonWrap = button.closest(".notification-see-more-wrap");
    if (buttonWrap instanceof HTMLElement) buttonWrap.hidden = true;

    const firstSummary = hiddenItems[0]?.querySelector("summary");
    if (firstSummary instanceof HTMLElement) firstSummary.focus();
  });
})();

// PSWDO Logout Confirmation Modal
(() => {
  let logoutTargetHref = "";
  let logoutModal = null;

  function ensureLogoutModal() {
    if (logoutModal && document.body.contains(logoutModal)) {
      return logoutModal;
    }

    const existing = document.getElementById("pswdo-logout-modal");
    if (existing instanceof HTMLElement) {
      logoutModal = existing;
      return logoutModal;
    }

    const modal = document.createElement("div");
    modal.className = "pswdo-logout-modal";
    modal.id = "pswdo-logout-modal";
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "pswdo-logout-title");
    modal.innerHTML = `
      <div class="pswdo-logout-backdrop" data-logout-action="cancel"></div>
      <div class="pswdo-logout-card">
        <div class="pswdo-logout-icon">
          <i class="fas fa-right-from-bracket"></i>
        </div>
        <h3 id="pswdo-logout-title">Log out of PSWDO Portal?</h3>
        <p>Are you sure you want to end your current session? You can sign in again anytime with your staff credentials.</p>
        <div class="pswdo-logout-actions">
          <button type="button" class="logout-btn-cancel" data-logout-action="cancel">Cancel</button>
          <button type="button" class="logout-btn-confirm" data-logout-action="confirm">
            <i class="fas fa-right-from-bracket"></i> Yes, Log Out
          </button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    modal.addEventListener("click", (event) => {
      const actionEl = event.target.closest("[data-logout-action]");
      if (!actionEl) return;
      const action = actionEl.getAttribute("data-logout-action");
      if (action === "cancel") {
        closeLogoutModal();
      } else if (action === "confirm") {
        const dest = logoutTargetHref || "php_files/auth-logout.php";
        closeLogoutModal();
        window.location.assign(dest);
      }
    });

    logoutModal = modal;
    return logoutModal;
  }

  function openLogoutModal(href) {
    const modal = ensureLogoutModal();
    logoutTargetHref = String(href || "").trim();
    modal.classList.add("open");
    document.body.classList.add("modal-open");
    const cancelBtn = modal.querySelector(".logout-btn-cancel");
    if (cancelBtn instanceof HTMLButtonElement) {
      cancelBtn.focus();
    }
  }

  function closeLogoutModal() {
    if (!logoutModal) return;
    logoutModal.classList.remove("open");
    document.body.classList.remove("modal-open");
    logoutTargetHref = "";
  }

  document.addEventListener("click", (event) => {
    const link = event.target.closest("a.logout-link, a[href*='auth-logout.php']");
    if (!link || !(link instanceof HTMLAnchorElement)) return;
    event.preventDefault();
    openLogoutModal(link.href);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && logoutModal && logoutModal.classList.contains("open")) {
      closeLogoutModal();
    }
  });
})();
