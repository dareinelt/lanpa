/**
 * Overlay zum Zusammenführen eines Tickets.
 *
 * Sucht offene Tickets (bevorzugt derselben meldenden Person) über die
 * Helpdesk-API, hält das gewählte Zielticket in einem versteckten Feld und
 * ersetzt die Platzhalter der Info-E-Mail, solange der Text nicht bearbeitet
 * wurde.
 */
(function () {
    "use strict";

    const UI = window.AppUI || {};
    const api = UI.api || (async function (url, options) {
        const response = await fetch(url, Object.assign({ credentials: "same-origin" }, options || {}));
        if (!response.ok) { throw new Error("HTTP " + response.status); }
        return response.json();
    });
    const debounce = UI.debounce || function (fn, wait) {
        let timer = null;
        return function () {
            const args = arguments;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { fn.apply(null, args); }, wait || 250);
        };
    };
    const escapeHtml = UI.escapeHtml || function (value) {
        return String(value == null ? "" : value)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    };

    const dialog = document.querySelector("dialog[data-hd-merge]");
    if (!dialog) { return; }

    const form = dialog.querySelector("[data-hd-merge-form]");
    const search = dialog.querySelector("[data-hd-merge-search]");
    const targetInput = dialog.querySelector("[data-hd-merge-target]");
    const suggestions = dialog.querySelector("[data-hd-merge-suggestions]");
    const selectedBox = dialog.querySelector("[data-hd-merge-selected]");
    const targetError = dialog.querySelector("[data-hd-merge-target-error]");
    const reason = dialog.querySelector("[data-hd-merge-reason]");
    const note = dialog.querySelector("[data-hd-merge-note]");
    const notify = dialog.querySelector("[data-hd-merge-notify]");
    const mailBox = dialog.querySelector("[data-hd-merge-mail]");
    const subject = dialog.querySelector("[data-hd-merge-subject]");
    const editor = dialog.querySelector("[data-hd-merge-editor]");
    const bodyInput = dialog.querySelector("[data-hd-merge-body]");
    const errorEl = dialog.querySelector("[data-hd-merge-error]");

    if (!form || !search || !targetInput || !suggestions) { return; }

    const sourceId = dialog.getAttribute("data-source") || "";
    const sourceNumber = dialog.getAttribute("data-number") || "";
    const templates = {
        subject: subject ? subject.value : "",
        body: editor ? editor.innerHTML : ""
    };
    const rendered = { subject: templates.subject, body: templates.body };
    let selected = null;
    let requestSeq = 0;

    // ------------------------------------------------------------ Platzhalter

    function reasonLabel() {
        const option = reason && reason.selectedIndex >= 0 ? reason.options[reason.selectedIndex] : null;
        const name = option ? option.textContent.trim() : "";
        const extra = note ? note.value.trim() : "";
        if (name === "") { return extra; }
        return extra === "" ? name : name + " – " + extra;
    }

    function substitute(value, escapeValues) {
        const map = {
            "{{nummer}}": sourceNumber,
            "{{ziel}}": selected ? selected.number : "",
            "{{grund}}": reasonLabel()
        };
        return String(value == null ? "" : value).replace(/\{\{(nummer|ziel|grund)\}\}/g, function (match) {
            const replacement = map[match];
            if (replacement === undefined || replacement === "") { return match; }
            return escapeValues ? escapeHtml(replacement) : replacement;
        });
    }

    /** Platzhalter nachziehen, solange der Text nicht von Hand geändert wurde. */
    function refresh() {
        if (subject) {
            const next = substitute(templates.subject, false);
            if (subject.value === rendered.subject) { subject.value = next; }
            rendered.subject = next;
        }
        if (editor) {
            const next = substitute(templates.body, true);
            if (editor.innerHTML === rendered.body) { editor.innerHTML = next; }
            rendered.body = next;
        }
    }

    // ------------------------------------------------------------ Vorschläge

    function hideSuggestions() {
        suggestions.hidden = true;
        search.setAttribute("aria-expanded", "false");
    }

    function renderSuggestions(items) {
        suggestions.innerHTML = "";
        if (!items.length) {
            hideSuggestions();
            return;
        }
        items.forEach(function (item) {
            const entry = document.createElement("li");
            const button = document.createElement("button");
            button.type = "button";
            button.setAttribute("role", "option");
            button.className = "hd-merge-suggestion" + (item.same_requester ? " is-same-requester" : "");
            button.innerHTML =
                '<span class="hd-merge-suggestion-main">' +
                    '<span class="hd-merge-suggestion-number">' + escapeHtml(item.number) + "</span>" +
                    '<span class="hd-merge-suggestion-subject">' + escapeHtml(item.subject || "") + "</span>" +
                "</span>" +
                '<span class="hd-merge-suggestion-meta">' + escapeHtml(item.meta || "") +
                    (item.same_requester ? '<span class="hd-merge-badge">gleiche meldende Person</span>' : "") +
                "</span>";
            button.addEventListener("click", function () { choose(item); });
            entry.appendChild(button);
            suggestions.appendChild(entry);
        });
        suggestions.hidden = false;
        search.setAttribute("aria-expanded", "true");
    }

    async function loadSuggestions(term) {
        const seq = ++requestSeq;
        const params = new URLSearchParams();
        params.set("merge_for", sourceId);
        params.set("limit", "8");
        if (term !== "") { params.set("q", term); }
        try {
            const data = await api("/api/helpdesk/tickets/search?" + params.toString());
            if (seq !== requestSeq) { return; }
            renderSuggestions((data && data.items) || []);
        } catch (error) {
            if (seq === requestSeq) { hideSuggestions(); }
        }
    }

    const runSearch = debounce(function () { loadSuggestions(search.value.trim()); }, 200);

    function choose(item) {
        selected = { id: String(item.id), number: item.number || "", subject: item.subject || "" };
        targetInput.value = selected.id;
        search.value = selected.number + (selected.subject !== "" ? " · " + selected.subject : "");
        if (selectedBox) {
            selectedBox.innerHTML = "Gewählt: <strong>" + escapeHtml(selected.number) + "</strong>" +
                (selected.subject !== "" ? " · " + escapeHtml(selected.subject) : "");
            selectedBox.hidden = false;
        }
        if (targetError) { targetError.hidden = true; }
        hideSuggestions();
        refresh();
    }

    function clearSelection() {
        if (!selected) { return; }
        selected = null;
        targetInput.value = "";
        if (selectedBox) {
            selectedBox.hidden = true;
            selectedBox.innerHTML = "";
        }
        refresh();
    }

    search.addEventListener("input", function () {
        clearSelection();
        runSearch();
    });
    search.addEventListener("focus", function () { loadSuggestions(search.value.trim()); });
    search.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !suggestions.hidden) {
            event.stopPropagation();
            hideSuggestions();
        }
    });

    document.addEventListener("click", function (event) {
        if (suggestions.hidden) { return; }
        if (suggestions.contains(event.target) || event.target === search) { return; }
        hideSuggestions();
    });

    if (reason) { reason.addEventListener("change", refresh); }
    if (note) { note.addEventListener("input", refresh); }

    // ------------------------------------------------------------ E-Mail-Bereich

    function syncNotify() {
        if (!notify || !mailBox) { return; }
        mailBox.hidden = !notify.checked;
    }

    if (notify) { notify.addEventListener("change", syncNotify); }
    syncNotify();

    function showError(message) {
        if (!errorEl) {
            if (UI.toast) { UI.toast(message, "danger"); }
            return;
        }
        errorEl.textContent = message;
        errorEl.hidden = false;
        errorEl.scrollIntoView({ block: "nearest" });
    }

    // ------------------------------------------------------------ Absenden

    form.addEventListener("submit", function (event) {
        if (errorEl) { errorEl.hidden = true; }
        if (targetInput.value === "") {
            event.preventDefault();
            if (targetError) {
                targetError.textContent = "Bitte ein Zielticket aus den Vorschlägen wählen.";
                targetError.hidden = false;
            }
            search.focus();
            return;
        }
        if (!notify || !notify.checked) { return; }

        const html = editor ? editor.innerHTML.trim() : "";
        const text = editor ? editor.textContent.replace(/\u00a0/g, " ").trim() : "";
        const hasImage = html.indexOf("<img") !== -1;
        if (text === "" && !hasImage) {
            event.preventDefault();
            showError("Bitte einen Nachrichtentext eingeben.");
            if (editor) { editor.focus(); }
            return;
        }
        if (subject && subject.value.trim() === "") {
            event.preventDefault();
            showError("Bitte einen Betreff angeben.");
            subject.focus();
            return;
        }
        if (bodyInput) { bodyInput.value = html; }
    });

    refresh();
})();
