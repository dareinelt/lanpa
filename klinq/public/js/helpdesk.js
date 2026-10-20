/* Help Desk: Kaskaden (Kategorie → Unterkategorie, Gruppe → Bearbeiter, Melder → Assets),
 * Vorschläge (Tags, Tickets, Wissensartikel), Vorlagenwahl und Kommentar-Sichtbarkeit.
 * Rein datenattribut-gesteuert, ohne Inline-Skripte (CSP). */
(function () {
    "use strict";

    const UI = window.AppUI || {};
    const api = UI.api || async function (url) {
        const response = await fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" });
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

    /** Optionen eines Selects ersetzen; erster Eintrag bleibt Platzhalter. */
    function fillSelect(select, items, selectedValue, placeholder) {
        const keep = select.querySelector("option[value='']");
        const multiple = select.multiple;
        // Bei Mehrfachauswahl bisherige Auswahl beibehalten
        const selectedSet = new Set(multiple ? Array.from(select.selectedOptions).map(function (o) { return o.value; }) : []);
        if (selectedValue !== undefined && selectedValue !== null) {
            String(selectedValue).split(",").forEach(function (v) { if (v !== "") { selectedSet.add(v); } });
        }
        select.innerHTML = "";
        if (!multiple) {
            const empty = document.createElement("option");
            empty.value = "";
            empty.textContent = placeholder || (keep ? keep.textContent : "– bitte wählen –");
            select.appendChild(empty);
        }
        items.forEach(function (item) {
            const opt = document.createElement("option");
            opt.value = String(item.id);
            opt.textContent = item.label || item.name || String(item.id);
            if (item.meta) { opt.dataset.meta = item.meta; }
            if (selectedSet.has(String(item.id))) { opt.selected = true; }
            select.appendChild(opt);
        });
        select.disabled = !multiple && items.length === 0;
    }

    // ---------------------------------------------------------------- Kategorie → Unterkategorie
    document.querySelectorAll("select[data-hd-category]").forEach(function (categorySelect) {
        const target = document.querySelector(categorySelect.getAttribute("data-hd-category"));
        if (!target) { return; }
        const initial = target.getAttribute("data-selected") || target.value;
        async function load(keepValue) {
            const id = categorySelect.value;
            if (!id) { fillSelect(target, [], undefined, "– keine Unterkategorie –"); return; }
            try {
                const data = await api("/api/helpdesk/categories/" + encodeURIComponent(id) + "/children");
                fillSelect(target, data.items || [], keepValue ? initial : undefined, "– keine Unterkategorie –");
            } catch (e) { fillSelect(target, [], undefined, "– nicht verfügbar –"); }
        }
        categorySelect.addEventListener("change", function () { load(false); });
        if (categorySelect.value && target.options.length <= 1) { load(true); }
    });

    // ---------------------------------------------------------------- Gruppe → Bearbeiter
    document.querySelectorAll("select[data-hd-group]").forEach(function (groupSelect) {
        const target = document.querySelector(groupSelect.getAttribute("data-hd-group"));
        if (!target) { return; }
        groupSelect.addEventListener("change", async function () {
            const current = target.value;
            const url = "/api/helpdesk/agents" + (groupSelect.value ? "?group_id=" + encodeURIComponent(groupSelect.value) : "");
            try {
                const data = await api(url);
                fillSelect(target, data.items || [], current, "– nicht zugewiesen –");
                target.disabled = false;
            } catch (e) { /* Liste unverändert lassen */ }
        });
    });

    // ---------------------------------------------------------------- Melder → Assets des Mitarbeiters
    document.querySelectorAll("select[data-hd-employee-assets]").forEach(function (employeeSelect) {
        const target = document.querySelector(employeeSelect.getAttribute("data-hd-employee-assets"));
        if (!target) { return; }
        employeeSelect.addEventListener("change", async function () {
            if (!employeeSelect.value) { fillSelect(target, [], undefined, "– kein Asset –"); target.disabled = false; return; }
            try {
                const data = await api("/api/helpdesk/employees/" + encodeURIComponent(employeeSelect.value) + "/assets");
                fillSelect(target, data.items || [], undefined, "– kein Asset –");
                target.disabled = false;
            } catch (e) { /* ignorieren */ }
        });
    });

    // ---------------------------------------------------------------- Melder-Picker (phonetische Autovervollständigung) → Assets
    document.querySelectorAll("[data-picker][data-hd-employee-assets]").forEach(function (picker) {
        const target = document.querySelector(picker.getAttribute("data-hd-employee-assets"));
        if (!target) { return; }
        picker.addEventListener("picker:change", async function (e) {
            const id = e.detail && e.detail.id;
            if (!id) { fillSelect(target, [], undefined, "– kein Asset –"); target.disabled = false; return; }
            try {
                const data = await api("/api/helpdesk/employees/" + encodeURIComponent(id) + "/assets");
                fillSelect(target, data.items || [], undefined, "– kein Asset –");
                target.disabled = false;
            } catch (err) { /* ignorieren */ }
        });
    });

    // ---------------------------------------------------------------- Vorlage wählen → Formular mit ?template= neu laden
    document.querySelectorAll("select[data-hd-template]").forEach(function (select) {
        select.addEventListener("change", function () {
            const base = select.getAttribute("data-hd-template") || window.location.pathname;
            window.location.href = select.value ? base + "?template=" + encodeURIComponent(select.value) : base;
        });
    });

    // ---------------------------------------------------------------- Datalist-Vorschläge (Tags, Tickets)
    function bindSuggest(input, buildUrl, toValue) {
        const listId = input.getAttribute("list");
        const list = listId ? document.getElementById(listId) : null;
        if (!list) { return; }
        const run = debounce(async function () {
            const term = input.value.split(",").pop().trim();
            if (term.length < 2) { return; }
            try {
                const data = await api(buildUrl(term));
                list.innerHTML = "";
                (data.items || []).forEach(function (item) {
                    const opt = document.createElement("option");
                    opt.value = toValue(item);
                    opt.textContent = item.meta ? item.meta : "";
                    list.appendChild(opt);
                });
            } catch (e) { /* ignorieren */ }
        }, 200);
        input.addEventListener("input", run);
    }
    document.querySelectorAll("input[data-hd-tags]").forEach(function (input) {
        bindSuggest(input, function (term) { return "/api/helpdesk/tags/suggest?q=" + encodeURIComponent(term); }, function (item) {
            const parts = input.value.split(",").map(function (p) { return p.trim(); }).filter(Boolean);
            parts.pop();
            parts.push(item.name || item.label);
            return parts.join(", ");
        });
    });
    document.querySelectorAll("input[data-hd-ticket-search]").forEach(function (input) {
        const exclude = input.getAttribute("data-hd-ticket-search") || "";
        bindSuggest(input, function (term) {
            return "/api/helpdesk/tickets/search?q=" + encodeURIComponent(term) + (exclude ? "&exclude=" + encodeURIComponent(exclude) : "");
        }, function (item) { return item.name || item.label; });
    });

    // ---------------------------------------------------------------- Wissensartikel zum Betreff vorschlagen
    document.querySelectorAll("[data-hd-kb-suggest]").forEach(function (input) {
        const target = document.querySelector(input.getAttribute("data-hd-kb-suggest"));
        if (!target) { return; }
        const extra = function () {
            const form = input.closest("form");
            const cat = form ? form.querySelector("[name='category_id']") : null;
            const sub = form ? form.querySelector("[name='subcategory_id']") : null;
            let q = "";
            if (cat && cat.value) { q += "&category_id=" + encodeURIComponent(cat.value); }
            if (sub && sub.value) { q += "&subcategory_id=" + encodeURIComponent(sub.value); }
            return q;
        };
        const run = debounce(async function () {
            const term = input.value.trim();
            if (term.length < 4) { target.hidden = true; return; }
            try {
                const data = await api("/api/helpdesk/knowledge/suggest?q=" + encodeURIComponent(term) + extra());
                const items = data.items || [];
                if (items.length === 0) { target.hidden = true; return; }
                target.innerHTML = "<p class=\"kb-suggest-title\">Passende Wissensartikel</p><ul>" + items.map(function (a) {
                    return "<li><a href=\"" + escapeHtml(a.url || ("/helpdesk/knowledge/" + a.id)) + "\" target=\"_blank\" rel=\"noopener\">" + escapeHtml(a.label || a.name) + "</a>" + (a.meta ? " <span class=\"text-muted text-xs\">" + escapeHtml(a.meta) + "</span>" : "") + "</li>";
                }).join("") + "</ul>";
                target.hidden = false;
            } catch (e) { target.hidden = true; }
        }, 400);
        input.addEventListener("input", run);
    });

    // ---------------------------------------------------------------- Kommentar: Sichtbarkeit (öffentlich/intern) umschalten
    document.querySelectorAll("[data-hd-comment-form]").forEach(function (form) {
        const radios = form.querySelectorAll("input[name='type']");
        function apply() {
            const checked = form.querySelector("input[name='type']:checked");
            form.classList.toggle("is-internal", !!checked && checked.value === "internal");
        }
        radios.forEach(function (r) { r.addEventListener("change", apply); });
        apply();
    });

    // ---------------------------------------------------------------- Aufklappbare Karten: per Link-Anker (#attachments, #worklog) öffnen
    function openTargetDetails() {
        const hash = window.location.hash;
        if (hash.length < 2) { return; }
        let target = null;
        try { target = document.querySelector(hash); } catch (e) { return; }
        if (!target) { return; }
        const details = target.closest("details");
        if (details && !details.open) {
            details.open = true;
            target.scrollIntoView();
        }
    }
    openTargetDetails();
    window.addEventListener("hashchange", openTargetDetails);

    // ---------------------------------------------------------------- Kommentarfilter (alle / öffentlich / intern)
    document.querySelectorAll("[data-hd-comment-filter]").forEach(function (bar) {
        const list = document.querySelector(bar.getAttribute("data-hd-comment-filter"));
        if (!list) { return; }
        bar.addEventListener("click", function (event) {
            const btn = event.target.closest("button[data-filter]");
            if (!btn) { return; }
            bar.querySelectorAll("button[data-filter]").forEach(function (b) { b.classList.toggle("is-active", b === btn); b.setAttribute("aria-pressed", b === btn ? "true" : "false"); });
            const filter = btn.getAttribute("data-filter");
            list.querySelectorAll("[data-comment-type]").forEach(function (item) {
                item.hidden = filter !== "all" && item.getAttribute("data-comment-type") !== filter;
            });
        });
    });

    // ---------------------------------------------------------------- Statusdialog: Zielstatus in verstecktes Feld übernehmen
    document.addEventListener("click", function (event) {
        const trigger = event.target.closest("[data-hd-status]");
        if (!trigger) { return; }
        const dialog = document.getElementById("status-dialog");
        if (!dialog) { return; }
        const status = trigger.getAttribute("data-hd-status");
        dialog.querySelector("input[name='status']").value = status;
        dialog.querySelector("[data-status-label]").textContent = trigger.getAttribute("data-hd-label") || status;
        dialog.querySelectorAll("[data-show-for]").forEach(function (block) {
            const show = block.getAttribute("data-show-for").split(",").indexOf(status) !== -1;
            block.hidden = !show;
            block.querySelectorAll("[data-required-when-visible]").forEach(function (f) { f.required = show; });
        });
        if (typeof dialog.showModal === "function") { dialog.showModal(); }
    });

    // ---------------------------------------------------------------- Ticketliste: Zeitanzeige relativ aktualisieren (SLA-Restzeit)
    document.querySelectorAll("[data-hd-due]").forEach(function (el) {
        const due = new Date(el.getAttribute("data-hd-due").replace(" ", "T") + "Z");
        if (isNaN(due.getTime())) { return; }
        function render() {
            const minutes = Math.floor((due.getTime() - Date.now()) / 60000);
            const abs = Math.abs(minutes);
            const parts = [];
            if (abs >= 1440) { parts.push(Math.floor(abs / 1440) + " T"); }
            if (abs % 1440 >= 60) { parts.push(Math.floor((abs % 1440) / 60) + " h"); }
            if (abs % 60 > 0 || parts.length === 0) { parts.push((abs % 60) + " min"); }
            el.textContent = (minutes < 0 ? "überfällig seit " : "noch ") + parts.join(" ");
            el.classList.toggle("is-overdue", minutes < 0);
        }
        render();
        window.setInterval(render, 60000);
    });
})();
