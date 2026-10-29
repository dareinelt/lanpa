/* UI-Basisfunktionen: Toasts, Dialoge, Sidebar, Bestätigungen, CSRF-Fetch */
(function () {
    "use strict";

    function ensureToastRegion() {
        let region = document.querySelector(".toast-region");
        if (!region) {
            region = document.createElement("div");
            region.className = "toast-region";
            region.setAttribute("role", "status");
            region.setAttribute("aria-live", "polite");
            document.body.appendChild(region);
        }
        return region;
    }

    function showToast(message, type, duration) {
        const toast = document.createElement("div");
        toast.className = "toast" + (type ? " is-" + type : "");
        toast.textContent = message;
        ensureToastRegion().appendChild(toast);
        window.setTimeout(function () { toast.remove(); }, duration || 4000);
    }

    function openDialog(id) {
        const dialog = document.getElementById(id);
        if (dialog && typeof dialog.showModal === "function") {
            dialog.showModal();
        }
    }

    function closeDialog(id) {
        const dialog = document.getElementById(id);
        if (dialog) {
            dialog.close();
        }
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute("content") : "";
    }

    /**
     * Basis-Pfad der Installation (leer bei Auslieferung unter "/").
     * Läuft KLINQ hinter dem Intranet-Proxy unter einem Unterpfad, kennt PHP
     * diesen nicht; er wird deshalb aus der eigenen Skript-Adresse abgeleitet.
     */
    const BASE_PATH = (function () {
        const script = document.currentScript;
        if (!script || !script.src) { return ""; }
        try {
            const path = new URL(script.src, window.location.href).pathname;
            const marker = "/js/ui.js";
            const index = path.lastIndexOf(marker);
            return index >= 0 ? path.slice(0, index) : "";
        } catch (e) {
            return "";
        }
    })();

    /** Wurzelbezogene Adresse um den Basis-Pfad ergänzen; absolute und bereits ergänzte bleiben unverändert. */
    function withBase(path) {
        const value = path == null ? "" : String(path);
        if (value.charAt(0) !== "/" || value.charAt(1) === "/") { return value; }
        if (BASE_PATH !== "" && (value === BASE_PATH || value.indexOf(BASE_PATH + "/") === 0)) { return value; }
        return BASE_PATH + value;
    }

    /** fetch()-Wrapper für JSON-APIs inkl. CSRF-Header. */
    async function api(url, options) {
        options = options || {};
        const headers = Object.assign({
            "Accept": "application/json",
            "X-CSRF-Token": csrfToken()
        }, options.headers || {});
        if (options.body && typeof options.body !== "string" && !(options.body instanceof FormData)) {
            options.body = JSON.stringify(options.body);
            headers["Content-Type"] = "application/json";
        }
        const response = await fetch(withBase(url), Object.assign({}, options, { headers: headers, credentials: "same-origin" }));
        let data = null;
        try { data = await response.json(); } catch (e) { data = null; }
        if (!response.ok) {
            const error = new Error((data && data.error) || ("HTTP " + response.status));
            error.status = response.status;
            error.data = data;
            throw error;
        }
        return data;
    }

    function debounce(fn, wait) {
        let timer = null;
        return function () {
            const args = arguments;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { fn.apply(null, args); }, wait || 250);
        };
    }

    function escapeHtml(value) {
        return String(value == null ? "" : value)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    document.addEventListener("click", function (event) {
        const opener = event.target.closest("[data-dialog-open]");
        if (opener) {
            const validateFormId = opener.getAttribute("data-dialog-validate");
            const formToValidate = validateFormId ? document.getElementById(validateFormId) : null;
            if (formToValidate && !formToValidate.reportValidity()) {
                return;
            }
            openDialog(opener.getAttribute("data-dialog-open"));
        }
        const closer = event.target.closest("[data-dialog-close]");
        if (closer) {
            const dialog = closer.closest("dialog");
            if (dialog) { dialog.close(); }
        }
        const toggle = event.target.closest("#sidebar-toggle");
        if (toggle) {
            const open = document.body.classList.toggle("sidebar-open");
            toggle.setAttribute("aria-expanded", open ? "true" : "false");
        }
        if (event.target.classList.contains("sidebar-backdrop")) {
            document.body.classList.remove("sidebar-open");
        }
        const rowLink = event.target.closest("tr[data-href]");
        if (rowLink && !event.target.closest("a, button, input, form")) {
            window.location.href = rowLink.getAttribute("data-href");
        }
        // Dropdowns (z. B. Modulwechsel) schließen, sobald außerhalb geklickt wird
        document.querySelectorAll("details[data-module-switcher][open]").forEach(function (details) {
            if (!details.contains(event.target)) { details.open = false; }
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") { return; }
        document.querySelectorAll("details[data-module-switcher][open]").forEach(function (details) {
            details.open = false;
            const summary = details.querySelector("summary");
            if (summary) { summary.focus(); }
        });
    });

    /**
     * Bestätigungsfragen erscheinen als Overlay statt als Browser-Meldung.
     * `data-confirm` an einem Formular fragt vor dem Absenden nach,
     * `AppUI.confirm()` liefert die Antwort als Promise.
     */
    let confirmState = null;
    let confirmResolve = null;
    let confirmHandled = false;
    let approvedSubmission = null;

    /** Laufende Bestätigung abschließen; mehrfache Aufrufe (Klick + close-Event) sind unschädlich. */
    function finishConfirm(accepted) {
        if (confirmHandled) { return; }
        confirmHandled = true;
        const pending = confirmState;
        const resolve = confirmResolve;
        confirmState = null;
        confirmResolve = null;
        if (resolve) { resolve(accepted); }
        if (accepted && pending && pending.form) { submitApproved(pending); }
    }

    function confirmDialog() {
        let dialog = document.getElementById("ui-confirm-dialog");
        if (dialog) { return dialog; }
        dialog = document.createElement("dialog");
        dialog.id = "ui-confirm-dialog";
        dialog.className = "dialog";
        dialog.setAttribute("aria-labelledby", "ui-confirm-dialog-title");
        dialog.setAttribute("aria-describedby", "ui-confirm-dialog-message");

        const header = document.createElement("div");
        header.className = "dialog-header";
        const title = document.createElement("h2");
        title.id = "ui-confirm-dialog-title";
        title.textContent = "Bestätigung";
        header.appendChild(title);

        const body = document.createElement("div");
        body.className = "dialog-body";
        const message = document.createElement("p");
        message.id = "ui-confirm-dialog-message";
        message.className = "dialog-message";
        body.appendChild(message);

        const footer = document.createElement("div");
        footer.className = "dialog-footer";
        const cancel = document.createElement("button");
        cancel.type = "button";
        cancel.className = "btn btn-secondary";
        cancel.textContent = "Abbrechen";
        cancel.setAttribute("data-dialog-close", "");
        const accept = document.createElement("button");
        accept.type = "button";
        accept.className = "btn btn-primary";
        accept.textContent = "Bestätigen";
        accept.setAttribute("data-confirm-accept", "");
        accept.addEventListener("click", function () {
            finishConfirm(true);
            dialog.close();
        });
        footer.appendChild(cancel);
        footer.appendChild(accept);

        dialog.appendChild(header);
        dialog.appendChild(body);
        dialog.appendChild(footer);
        dialog.addEventListener("close", function () { finishConfirm(false); });
        document.body.appendChild(dialog);
        return dialog;
    }

    /** Bestätigung als Overlay anzeigen und das Ergebnis als Promise liefern. */
    function confirmOverlay(question) {
        const dialog = confirmDialog();
        if (typeof dialog.showModal !== "function") {
            return Promise.resolve(window.confirm(question));
        }
        return new Promise(function (resolve) {
            confirmHandled = false;
            confirmState = null;
            confirmResolve = resolve;
            dialog.querySelector("#ui-confirm-dialog-message").textContent = question;
            dialog.showModal();
        });
    }

    /** Formular nach der Bestätigung abschicken, ohne die Frage erneut zu stellen. */
    function submitApproved(pending) {
        const form = pending.form;
        if (!form || !form.isConnected) { return; }
        approvedSubmission = pending;
        try {
            if (typeof form.requestSubmit === "function") {
                if (pending.submitter && pending.submitter.form === form) {
                    form.requestSubmit(pending.submitter);
                } else {
                    form.requestSubmit();
                }
            } else {
                form.submit();
            }
        } finally {
            approvedSubmission = null;
        }
    }

    function askConfirm(form, submitter) {
        const question = form.getAttribute("data-confirm") || "";
        const pending = { form: form, submitter: submitter || null };
        const dialog = confirmDialog();
        if (question === "" || typeof dialog.showModal !== "function") {
            submitApproved(pending);
            return;
        }
        confirmHandled = false;
        confirmResolve = null;
        confirmState = pending;
        dialog.querySelector("#ui-confirm-dialog-message").textContent = question;
        dialog.showModal();
    }

    document.addEventListener("submit", function (event) {
        const form = event.target;
        if (form.matches("[data-confirm]")) {
            if (approvedSubmission && approvedSubmission.form === form) {
                approvedSubmission = null;
            } else {
                event.preventDefault();
                askConfirm(form, event.submitter);
                return;
            }
        }
        // Doppelte Absendung verhindern
        const submit = form.querySelector('button[type="submit"]:not([data-allow-multiple])');
        if (submit && !form.hasAttribute("data-no-lock")) {
            window.setTimeout(function () { submit.disabled = true; }, 0);
            window.setTimeout(function () { submit.disabled = false; }, 8000);
        }
    });

    // Dialoge, die anhand eines Query-Parameters automatisch geöffnet werden (z. B. Scan-Overlay)
    document.querySelectorAll("dialog[data-auto-open-if-query]").forEach(function (dialog) {
        const param = dialog.getAttribute("data-auto-open-if-query");
        const url = new URL(window.location.href);
        if (url.searchParams.get(param) === "1") {
            openDialog(dialog.id);
            url.searchParams.delete(param);
            window.history.replaceState({}, "", url.pathname + (url.searchParams.toString() ? "?" + url.searchParams.toString() : "") + url.hash);
        }
    });

    // Sidebar-Backdrop einfügen
    if (document.querySelector(".sidebar")) {
        const backdrop = document.createElement("div");
        backdrop.className = "sidebar-backdrop";
        document.body.appendChild(backdrop);
    }

    // Auto-Submit für Filterformulare
    document.addEventListener("change", function (event) {
        const el = event.target;
        if (el.matches("[data-autosubmit]")) {
            const form = el.closest("form");
            if (form) { form.requestSubmit ? form.requestSubmit() : form.submit(); }
        }
        if (el.matches("[data-toggle-target]")) {
            applyToggle(el);
        }
    });

    // Checkbox/Radio blendet abhängige Felder ein oder aus
    function applyToggle(el) {
        const target = document.querySelector(el.getAttribute("data-toggle-target"));
        if (!target) { return; }
        const on = el.type === "radio" ? el.checked && el.value === (el.getAttribute("data-toggle-value") || el.value) : el.checked;
        target.hidden = !on;
        target.querySelectorAll("[data-required-when-visible]").forEach(function (field) { field.required = on; });
    }
    document.querySelectorAll("[data-toggle-target]").forEach(applyToggle);

    window.AppUI = {
        toast: showToast,
        confirm: confirmOverlay,
        openDialog: openDialog,
        closeDialog: closeDialog,
        api: api,
        basePath: BASE_PATH,
        url: withBase,
        csrfToken: csrfToken,
        debounce: debounce,
        escapeHtml: escapeHtml
    };
})();
