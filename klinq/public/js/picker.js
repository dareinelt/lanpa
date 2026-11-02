/* Autocomplete-Auswahl (Mitarbeiter, Standorte) auf Basis der Such-APIs. Markup: siehe resources/views/mobile/_picker.php */
(function () {
    "use strict";

    function initPicker(root) {
        const value = root.querySelector("[data-picker-value]");
        const input = root.querySelector("[data-picker-input]");
        const results = root.querySelector("[data-picker-results]");
        const selected = root.querySelector("[data-picker-selected]");
        const label = root.querySelector("[data-picker-label]");
        const meta = root.querySelector("[data-picker-meta]");
        const clear = root.querySelector("[data-picker-clear]");
        // data-picker-create-url: unbekannte Personen direkt aus der Suche heraus anlegen
        const createUrl = root.getAttribute("data-picker-create-url");
        const createDialog = createUrl ? root.querySelector("[data-picker-create-dialog]") : null;
        const createTitle = createDialog ? createDialog.querySelector("[data-picker-create-title]") : null;
        const createEmail = createDialog ? createDialog.querySelector("[data-picker-create-email]") : null;
        const createPhone = createDialog ? createDialog.querySelector("[data-picker-create-phone]") : null;
        const createKeep = createDialog ? createDialog.querySelector("[data-picker-create-keep]") : null;
        const createSubmit = createDialog ? createDialog.querySelector("[data-picker-create-submit]") : null;
        const createError = createDialog ? createDialog.querySelector("[data-picker-create-error]") : null;
        let timer = null;
        let items = [];
        let active = -1;
        let controller = null;
        let createTerm = "";
        let creating = false;

        // Der Dialog steht im Formular und damit ggf. in einem ausgeblendeten
        // Assistenten-Schritt; als Modal funktioniert er nur direkt im Body.
        if (createDialog && createDialog.parentNode !== document.body) {
            document.body.appendChild(createDialog);
        }

        function escapeHtml(s) {
            return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
        }

        /** Beschriftung der Anlege-Aktion aus dem eingegebenen Namen ("Gustav Gans hinzufügen"). */
        function createLabel(term) {
            const max = 60;
            const short = term.length > max ? term.slice(0, max - 1).trim() + "…" : term;
            return short + " hinzufügen";
        }

        function render() {
            if (!items.length) {
                const term = input.value.trim();
                if (term.length < 1) {
                    results.innerHTML = "";
                } else {
                    results.innerHTML = '<li class="picker-empty">Keine Treffer</li>'
                        + (createDialog ? '<li class="picker-add"><button type="button" class="btn btn-sm" data-picker-create>' + escapeHtml(createLabel(term)) + '</button></li>' : "");
                }
                results.hidden = results.innerHTML === "";
                input.setAttribute("aria-expanded", results.hidden ? "false" : "true");
                return;
            }
            results.innerHTML = items.map(function (item, i) {
                const sub = item.meta || item.path || (item.type ? item.type : "");
                return '<li role="option" aria-selected="' + (i === active) + '"><button type="button" class="' + (i === active ? "is-active" : "") + '" data-index="' + i + '">'
                    + '<span>' + escapeHtml(item.label || item.name) + '</span>'
                    + (sub ? '<span class="result-meta">' + escapeHtml(sub) + '</span>' : "")
                    + '</button></li>';
            }).join("");
            results.hidden = false;
            input.setAttribute("aria-expanded", "true");
        }

        function search(term) {
            if (controller) { controller.abort(); }
            controller = new AbortController();
            // URL bei jeder Suche lesen – Formulare dürfen sie (z. B. mit Filterparametern) nachträglich ändern
            const url = root.getAttribute("data-search-url");
            fetch(url + (url.indexOf("?") >= 0 ? "&" : "?") + "q=" + encodeURIComponent(term), { headers: { Accept: "application/json" }, signal: controller.signal })
                .then(function (r) { return r.ok ? r.json() : { items: [] }; })
                .then(function (data) { items = data.items || []; active = items.length ? 0 : -1; render(); })
                .catch(function () { /* abgebrochen oder offline */ });
        }

        function apply(item, id) {
            value.value = id;
            label.textContent = item.label || item.name;
            if (meta) { meta.textContent = item.meta || item.path || ""; }
            selected.hidden = false;
            input.hidden = true;
            results.hidden = true;
            input.value = "";
            items = [];
            root.dispatchEvent(new CustomEvent("picker:change", { bubbles: true, detail: Object.assign({}, item, { id: id }) }));
        }

        function csrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute("content") || "" : "";
        }

        /** Treffer ohne lokale ID (z. B. Personen aus dem Telefonbuch) beim Auswaehlen uebernehmen. */
        function adopt(item) {
            const url = root.getAttribute("data-resolve-url");
            results.hidden = true;
            const payload = { ref: item.ref };
            const request = window.AppUI && window.AppUI.api
                ? window.AppUI.api(url, { method: "POST", body: payload })
                : fetch(url, {
                    method: "POST",
                    headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-Token": csrfToken() },
                    credentials: "same-origin",
                    body: JSON.stringify(payload)
                }).then(function (r) { return r.ok ? r.json() : null; });
            Promise.resolve(request)
                .then(function (data) {
                    if (data && data.id) { apply(item, data.id); return; }
                    window.alert("Person konnte nicht übernommen werden.");
                })
                .catch(function () { window.alert("Person konnte nicht übernommen werden."); });
        }

        function choose(item) {
            if (item.ref && root.hasAttribute("data-resolve-url")) { adopt(item); return; }
            apply(item, item.id);
        }

        /** Fehler im Anlege-Dialog anzeigen (leer = ausblenden). */
        function showCreateError(message) {
            if (!createError) { return; }
            createError.textContent = message || "";
            createError.hidden = !message;
        }

        function openCreate() {
            if (!createDialog) { return; }
            createTerm = input.value.trim();
            if (createTitle) { createTitle.textContent = createLabel(createTerm); }
            showCreateError("");
            results.hidden = true;
            if (createEmail) { createEmail.value = ""; }
            if (createPhone) { createPhone.value = ""; }
            if (typeof createDialog.showModal === "function") { createDialog.showModal(); } else { createDialog.setAttribute("open", "true"); }
            if (createEmail) { createEmail.focus(); }
        }

        function closeCreate() {
            if (!createDialog) { return; }
            if (typeof createDialog.close === "function") { createDialog.close(); } else { createDialog.removeAttribute("open"); }
        }

        /** Unbekannte Person anlegen und direkt als Auswahl uebernehmen. */
        function submitCreate() {
            if (!createDialog || !createUrl || creating) { return; }
            const email = createEmail ? createEmail.value.trim() : "";
            const phone = createPhone ? createPhone.value.trim() : "";
            if (email === "" && phone === "") {
                showCreateError("Bitte E-Mail-Adresse oder Rückrufnummer angeben.");
                return;
            }
            if (email !== "" && createEmail && !createEmail.checkValidity()) {
                showCreateError("Bitte eine gültige E-Mail-Adresse angeben.");
                return;
            }
            showCreateError("");
            creating = true;
            if (createSubmit) { createSubmit.disabled = true; }
            const payload = { name: createTerm, email: email, phone: phone, keep: createKeep ? createKeep.checked : true };
            const request = window.AppUI && window.AppUI.api
                ? window.AppUI.api(createUrl, { method: "POST", body: payload })
                : fetch(createUrl, {
                    method: "POST",
                    headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-Token": csrfToken() },
                    credentials: "same-origin",
                    body: JSON.stringify(payload)
                }).then(function (r) {
                    return r.json().then(function (data) {
                        if (!r.ok) { throw new Error((data && data.error) || "Person konnte nicht angelegt werden."); }
                        return data;
                    });
                });
            Promise.resolve(request)
                .then(function (data) {
                    if (!data || !data.id) { throw new Error("Person konnte nicht angelegt werden."); }
                    closeCreate();
                    apply({ name: data.name, meta: data.meta }, data.id);
                })
                .catch(function (error) {
                    showCreateError((error && error.message) || "Person konnte nicht angelegt werden.");
                })
                .then(function () {
                    creating = false;
                    if (createSubmit) { createSubmit.disabled = false; }
                });
        }

        if (createDialog) {
            createDialog.addEventListener("click", function (e) {
                if (e.target.closest("[data-picker-create-submit]")) { submitCreate(); }
            });
            createDialog.addEventListener("keydown", function (e) {
                const field = e.target;
                if (e.key === "Enter" && field && field.tagName === "INPUT" && field.type !== "checkbox") {
                    e.preventDefault();
                    submitCreate();
                }
            });
            createDialog.addEventListener("close", function () {
                if (!input.hidden) { input.focus(); }
            });
        }

        function reset() {
            value.value = "";
            selected.hidden = true;
            input.hidden = false;
            input.focus();
            root.dispatchEvent(new CustomEvent("picker:change", { bubbles: true, detail: null }));
        }

        input.addEventListener("input", function () {
            window.clearTimeout(timer);
            const term = input.value.trim();
            if (term.length < 1 && !root.hasAttribute("data-picker-browse")) { items = []; render(); return; }
            timer = window.setTimeout(function () { search(term); }, 180);
        });
        input.addEventListener("focus", function () {
            if (items.length) { render(); return; }
            // data-picker-browse: Liste schon beim Fokus anzeigen (kleine Stammdatenlisten)
            if (root.hasAttribute("data-picker-browse")) { search(input.value.trim()); }
        });
        input.addEventListener("keydown", function (e) {
            if (results.hidden) { return; }
            if (e.key === "ArrowDown") { e.preventDefault(); active = Math.min(active + 1, items.length - 1); render(); }
            else if (e.key === "ArrowUp") { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (e.key === "Enter") { if (active >= 0 && items[active]) { e.preventDefault(); choose(items[active]); } }
            else if (e.key === "Escape") { results.hidden = true; }
        });
        results.addEventListener("click", function (e) {
            if (e.target.closest("[data-picker-create]")) { openCreate(); return; }
            const btn = e.target.closest("button[data-index]");
            if (btn) { choose(items[Number(btn.getAttribute("data-index"))]); }
        });
        clear.addEventListener("click", reset);
        document.addEventListener("click", function (e) { if (!root.contains(e.target)) { results.hidden = true; } });
    }

    document.querySelectorAll("[data-picker]").forEach(initPicker);

    // Formulare mit Code-Fallback: ohne Auswahl wird der getippte/gescannte Text als Code mitgesendet
    document.querySelectorAll("form [data-asset-code]").forEach(function (codeField) {
        const form = codeField.closest("form");
        const picker = form.querySelector("[data-picker]");
        if (!picker) { return; }
        const input = picker.querySelector("[data-picker-input]");
        const value = picker.querySelector("[data-picker-value]");
        input.addEventListener("keydown", function (e) {
            const results = picker.querySelector("[data-picker-results]");
            if (e.key === "Enter" && (results.hidden || !results.querySelector("button")) && input.value.trim() !== "") {
                e.preventDefault();
                codeField.value = input.value.trim();
                form.requestSubmit();
            }
        });
        form.addEventListener("submit", function () {
            if (value.value === "" && input.value.trim() !== "") { codeField.value = input.value.trim(); }
        });
    });
})();
