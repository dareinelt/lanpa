/* Help Desk: Sammelkonten im Störungsformular als Eingabezeile mit
 * Autovervollständigung und Liste der bereits eingetragenen Konten.
 * Das Textfeld (name="shared_accounts") bleibt als Wertespeicher und
 * Rückfallebene ohne JavaScript erhalten. Keine Inline-Skripte (CSP). */
(function () {
    "use strict";

    const UI = window.AppUI || {};
    const withBase = UI.url || function (path) { return path; };
    const api = UI.api || async function (url) {
        const response = await fetch(withBase(url), { headers: { Accept: "application/json" }, credentials: "same-origin" });
        if (!response.ok) { throw new Error("HTTP " + response.status); }
        return response.json();
    };
    const debounce = UI.debounce || function (fn, wait) {
        let timer = null;
        return function () { const args = arguments; window.clearTimeout(timer); timer = window.setTimeout(function () { fn.apply(null, args); }, wait || 250); };
    };
    const escapeHtml = UI.escapeHtml || function (value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) { return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" })[c]; });
    };

    const SPLIT = /[\r\n,;]+/;
    const VALID = /^[a-z0-9._-]{1,100}$/;
    const MIN_TERM = 2;

    /** "DOMAIN\\konto", "konto@domain" und "konto" auf den Anmeldenamen reduzieren. */
    function normalize(raw) {
        let value = String(raw == null ? "" : raw).trim();
        const backslash = value.lastIndexOf("\\");
        if (backslash !== -1) { value = value.slice(backslash + 1); }
        const at = value.indexOf("@");
        if (at !== -1) { value = value.slice(0, at); }
        value = value.trim().toLowerCase();
        return VALID.test(value) ? value : null;
    }

    function initPicker(root) {
        const input = root.querySelector("[data-shared-account-input]");
        const store = root.querySelector("[data-shared-account-value]");
        const list = root.querySelector("[data-shared-account-list]");
        const box = root.querySelector("[data-shared-account-suggestions]");
        const emptyHint = root.querySelector("[data-shared-account-empty]");
        const feedback = root.querySelector("[data-shared-account-feedback]");
        const ui = root.querySelector("[data-shared-account-ui]");
        const addButton = root.querySelector("[data-shared-account-add]");
        const suggestUrl = root.getAttribute("data-suggest-url") || "";
        const form = root.closest("form");
        if (!input || !store || !list || !box) { return; }

        let accounts = store.value.split(SPLIT).map(normalize).filter(Boolean);
        let suggestions = [];
        let activeIndex = -1;

        // Ohne JavaScript bleibt das Textfeld sichtbar und bearbeitbar.
        store.hidden = true;
        if (ui) { ui.hidden = false; }
        list.hidden = false;

        function message(text) {
            if (!feedback) { return; }
            feedback.textContent = text || "";
            feedback.hidden = !text;
        }

        function syncStore() {
            store.value = accounts.join("\n");
        }

        function renderList() {
            list.innerHTML = accounts.map(function (name) {
                return '<li class="account-picker-chip">' + escapeHtml(name) +
                    '<button type="button" class="account-picker-remove" aria-label="Konto ' + escapeHtml(name) +
                    ' entfernen" data-shared-account-remove="' + escapeHtml(name) + '">&times;</button></li>';
            }).join("");
            if (emptyHint) { emptyHint.hidden = accounts.length > 0; }
            syncStore();
        }

        function closeSuggestions() {
            suggestions = [];
            activeIndex = -1;
            box.hidden = true;
            box.innerHTML = "";
            input.setAttribute("aria-expanded", "false");
            input.removeAttribute("aria-activedescendant");
        }

        function highlight(index) {
            const options = box.querySelectorAll("[role='option']");
            if (options.length === 0) { return; }
            activeIndex = (index + options.length) % options.length;
            options.forEach(function (option, i) {
                const isActive = i === activeIndex;
                option.classList.toggle("is-active", isActive);
                option.setAttribute("aria-selected", isActive ? "true" : "false");
                if (isActive) {
                    input.setAttribute("aria-activedescendant", option.id);
                    if (option.scrollIntoView) { option.scrollIntoView({ block: "nearest" }); }
                }
            });
        }

        function renderSuggestions() {
            if (suggestions.length === 0) { closeSuggestions(); return; }
            box.innerHTML = suggestions.map(function (item, i) {
                return '<li class="account-picker-option" role="option" id="shared-account-option-' + i +
                    '" aria-selected="false" data-index="' + i + '"><span class="account-picker-name">' +
                    escapeHtml(item.name) + '</span>' +
                    (item.meta ? '<span class="account-picker-meta">' + escapeHtml(item.meta) + '</span>' : "") +
                    '</li>';
            }).join("");
            box.hidden = false;
            input.setAttribute("aria-expanded", "true");
            highlight(0);
        }

        function add(raw) {
            const name = normalize(raw);
            if (name === null) {
                message("Ungültiger Anmeldename: " + String(raw == null ? "" : raw).trim());
                return false;
            }
            message("");
            if (accounts.indexOf(name) === -1) {
                accounts.push(name);
                renderList();
            }
            input.value = "";
            closeSuggestions();
            return true;
        }

        const load = debounce(async function () {
            const term = input.value.trim();
            if (suggestUrl === "" || term.length < MIN_TERM) { closeSuggestions(); return; }
            try {
                const data = await api(suggestUrl + "?q=" + encodeURIComponent(term));
                const items = Array.isArray(data.items) ? data.items : [];
                suggestions = items.filter(function (item) {
                    const name = normalize(item && item.name);
                    return name !== null && accounts.indexOf(name) === -1;
                }).map(function (item) {
                    return { name: normalize(item.name), meta: item.meta ? String(item.meta) : "" };
                });
                renderSuggestions();
            } catch (error) {
                closeSuggestions();
            }
        }, 200);

        input.addEventListener("input", function () { message(""); load(); });
        input.addEventListener("keydown", function (event) {
            if (event.key === "ArrowDown" && suggestions.length > 0) {
                event.preventDefault();
                highlight(activeIndex + 1);
            } else if (event.key === "ArrowUp" && suggestions.length > 0) {
                event.preventDefault();
                highlight(activeIndex - 1);
            } else if (event.key === "Enter") {
                event.preventDefault();
                if (!box.hidden && activeIndex >= 0 && suggestions[activeIndex]) {
                    add(suggestions[activeIndex].name);
                } else if (input.value.trim() !== "") {
                    add(input.value);
                }
            } else if (event.key === "Escape") {
                closeSuggestions();
            } else if (event.key === "Tab") {
                closeSuggestions();
            }
        });

        box.addEventListener("mousedown", function (event) {
            const option = event.target.closest("[data-index]");
            if (!option) { return; }
            event.preventDefault();
            const item = suggestions[Number(option.getAttribute("data-index"))];
            if (item) { add(item.name); input.focus(); }
        });

        list.addEventListener("click", function (event) {
            const button = event.target.closest("[data-shared-account-remove]");
            if (!button) { return; }
            const name = button.getAttribute("data-shared-account-remove");
            accounts = accounts.filter(function (entry) { return entry !== name; });
            renderList();
            input.focus();
        });

        if (addButton) {
            addButton.addEventListener("click", function () {
                if (input.value.trim() === "") { input.focus(); return; }
                add(input.value);
                input.focus();
            });
        }

        input.addEventListener("blur", function () { window.setTimeout(closeSuggestions, 150); });

        // Resteingabe beim Absenden übernehmen, damit nichts verloren geht.
        if (form) {
            form.addEventListener("submit", function () {
                if (input.value.trim() !== "") { add(input.value); }
            });
        }

        renderList();
    }

    function init() {
        document.querySelectorAll("[data-shared-accounts]").forEach(initPicker);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
