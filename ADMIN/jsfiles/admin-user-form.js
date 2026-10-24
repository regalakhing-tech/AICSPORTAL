(function () {
  "use strict";

  const text = (value) => typeof value === "string" ? value.trim() : "";
  const count = (value) => Array.from(value).length;
  const passwordRules = (password) => ({
    length: count(password) >= 8 && new TextEncoder().encode(password).length <= 72 && !/[\x00-\x1f\x7f]/.test(password),
    case: /[A-Z]/.test(password) && /[a-z]/.test(password),
    number: /[0-9]/.test(password),
    symbol: /[^A-Za-z0-9]/.test(password),
  });

  function validate(data) {
    const errors = {};
    for (const [field, label] of Object.entries({ first_name: "First name", middle_name: "Middle name", last_name: "Last name" })) {
      const value = text(data[field]);
      if (!value && field !== "middle_name") errors[field] = `${label} is required.`;
      else if (value && (count(value) > 120 || !/^[\p{L}\p{M} .’'\-]+$/u.test(value) || !/\p{L}/u.test(value))) {
        errors[field] = "Use letters, spaces, apostrophes, periods or hyphens, up to 120 characters.";
      }
    }
    const email = text(data.email);
    if (!email || email.length > 180 || !/^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+$/.test(email) || email.includes("..")) {
      errors.email = "Enter a valid email address, up to 180 characters.";
    }
    if (!["admin", "pswdo", "applicat"].includes(text(data.role_code))) errors.role_code = "Select an account role.";
    if (!["active", "restricted", "disabled", "banned"].includes(text(data.status_code))) errors.status_code = "Select a valid account status.";
    const mobile = text(data.contact_number).replace(/[ ()-]/g, "");
    if (text(data.contact_number) && !/^(?:09[0-9]{9}|\+?639[0-9]{9})$/.test(mobile)) errors.contact_number = "Enter a Philippine mobile number: 09171234567 or +63 917 123 4567.";
    for (const [field, limit] of Object.entries({ position_title: 120, address: 255 })) {
      if (count(text(data[field])) > limit || /[<>\x00-\x1f\x7f]/.test(text(data[field]))) errors[field] = `Use plain text, up to ${limit} characters.`;
    }
    const password = typeof data.password === "string" ? data.password : "";
    const rules = passwordRules(password);
    if (new TextEncoder().encode(password).length > 72 || /[\x00-\x1f\x7f]/.test(password)) errors.password = "Use no more than 72 bytes and no control characters.";
    else if (!Object.values(rules).every(Boolean)) errors.password = "Use at least 8 characters with uppercase, lowercase, a number and a symbol.";
    if (!data.confirm_password || data.confirm_password !== password) errors.confirm_password = "Enter the same password again.";
    return errors;
  }

  function init(doc) {
    const form = doc.getElementById("create-user-form");
    if (!form) return;
    const shell = doc.getElementById("modal-create-user");
    const summary = doc.getElementById("create-user-errors");
    const submit = doc.getElementById("create-user-submit");
    const touched = new Set();
    let submitted = false;
    let returnFocus = null;
    const fields = Array.from(form.querySelectorAll("input:not([type=hidden]), select"));
    const data = () => Object.fromEntries(fields.filter((field) => !field.disabled).map((field) => [field.name, field.value]));

    function showFieldError(field, error) {
      const output = doc.getElementById(`create-${field.name}-error`);
      output.textContent = error || "";
      output.hidden = !error;
      if (error) field.setAttribute("aria-invalid", "true");
      else field.removeAttribute("aria-invalid");
    }

    function showSummary(errors) {
      const list = summary.querySelector("ul");
      list.replaceChildren();
      for (const [name, message] of Object.entries(errors)) {
        const item = doc.createElement("li");
        const link = doc.createElement("a");
        link.href = `#create-${name}`;
        const field = fields.find((input) => input.name === name);
        link.textContent = `${field.labels[0].textContent.replace(/\s*\*|\s*\(optional\)/g, "").trim()}: ${message}`;
        item.append(link);
        list.append(item);
      }
      summary.querySelector("p").textContent = "Correct these fields, then create the account.";
      summary.hidden = Object.keys(errors).length === 0;
    }

    function updatePasswordRules() {
      const rules = passwordRules(form.elements.namedItem("password").value);
      form.querySelectorAll("[data-password-rule]").forEach((item) => {
        const met = Boolean(rules[item.dataset.passwordRule]);
        item.classList.toggle("met", met);
        const icon = item.querySelector("i");
        icon.classList.toggle("fa-circle-check", met);
        icon.classList.toggle("fa-circle", !met);
        item.querySelector(".create-rule-state").textContent = met ? ": met" : ": not met";
      });
    }

    function updateRoleFields() {
      const role = form.elements.namedItem("role_code").value;
      form.querySelectorAll("[data-profile-field]").forEach((wrapper) => {
        const relevant = wrapper.dataset.profileField === "staff" ? ["admin", "pswdo"].includes(role) : role === "applicat";
        wrapper.hidden = !relevant;
        const input = wrapper.querySelector("input");
        input.disabled = !relevant;
        if (!relevant) showFieldError(input, "");
      });
      doc.getElementById("create-role-hint").textContent = role === "applicat" ? "An address helps complete the applicant profile." : ["admin", "pswdo"].includes(role) ? "A position title helps identify staff in the directory." : "Select a role to see the relevant profile fields.";
    }

    for (const field of fields) {
      if (field.getAttribute("aria-invalid") === "true") touched.add(field.name);
      field.addEventListener("blur", () => {
        touched.add(field.name);
        const errors = validate(data());
        showFieldError(field, errors[field.name]);
        if (submitted) showSummary(errors);
      });
      field.addEventListener("input", () => {
        const errors = validate(data());
        if (touched.has(field.name) || submitted) showFieldError(field, errors[field.name]);
        if (field.name === "password") {
          updatePasswordRules();
          if (touched.has("confirm_password") || submitted) showFieldError(form.elements.namedItem("confirm_password"), errors.confirm_password);
        }
      });
      field.addEventListener("change", () => {
        if (field.name === "role_code") updateRoleFields();
        const errors = validate(data());
        if (touched.has(field.name) || submitted) showFieldError(field, errors[field.name]);
        if (submitted) showSummary(errors);
      });
    }
    summary.addEventListener("click", (event) => {
      const link = event.target.closest("a");
      if (!link) return;
      const target = doc.getElementById(link.getAttribute("href").slice(1));
      if (target) { event.preventDefault(); target.focus(); }
    });
    form.addEventListener("submit", (event) => {
      submitted = true;
      const errors = validate(data());
      fields.forEach((field) => showFieldError(field, errors[field.name]));
      showSummary(errors);
      if (Object.keys(errors).length) {
        event.preventDefault();
        summary.focus();
        return;
      }
      submit.disabled = true;
      submit.querySelector("span").textContent = "Creating account…";
      form.setAttribute("aria-busy", "true");
    });
    shell.addEventListener("admin:modal-open", () => {
      const active = doc.activeElement;
      returnFocus = active && active !== doc.body && active !== doc.documentElement && !shell.contains(active)
        ? active : doc.querySelector('[data-modal-open="modal-create-user"]');
      if (!summary.hidden) summary.focus();
      else form.elements.namedItem("first_name").focus();
    });
    shell.addEventListener("admin:modal-close", () => {
      for (const name of ["password", "confirm_password"]) {
        const field = form.elements.namedItem(name);
        field.value = "";
        field.type = "password";
      }
      form.querySelectorAll("[data-password-toggle]").forEach((button) => {
        button.setAttribute("aria-pressed", "false");
        button.setAttribute("aria-label", "Show password");
        button.querySelector("i").className = "fas fa-eye";
      });
      updatePasswordRules();
      if (returnFocus && doc.contains(returnFocus)) returnFocus.focus();
    });
    shell.addEventListener("keydown", (event) => {
      if (event.key !== "Tab") return;
      const focusable = Array.from(shell.querySelectorAll('button:not(:disabled), input:not(:disabled):not([type=hidden]), select:not(:disabled), a[href]')).filter((element) => element.getClientRects().length);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && (doc.activeElement === first || doc.activeElement === summary)) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    updateRoleFields();
    updatePasswordRules();
    form.noValidate = true;
  }

  if (typeof module === "object" && module.exports) module.exports = { validate, init };
  else init(document);
})();
