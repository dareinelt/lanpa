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
        let timer = null;
        let items = [];
        let active = -1;
        let controller = null;

        function escapeHtml(s) {
            return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
        }

        function render() {
            if (!items.length) {
                results.innerHTML = input.value.trim().length >= 1 ? '<li class="picker-empty">Keine Treffer</li>' : "";
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

        function choose(item) {
            value.value = item.id;
            label.textContent = item.label || item.name;
            if (meta) { meta.textContent = item.meta || item.path || ""; }
            selected.hidden = false;
            input.hidden = true;
            results.hidden = true;
            input.value = "";
            items = [];
            root.dispatchEvent(new CustomEvent("picker:change", { bubbles: true, detail: item }));
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
