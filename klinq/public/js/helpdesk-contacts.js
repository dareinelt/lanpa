/*
 * Kontaktverzeichnis des Help Desk: Vorschläge vorhandener Stichworte im kommagetrennten Eingabefeld.
 * Die Liste der Stichworte liefert der Server im Attribut data-hd-contact-tag-source (JSON); das Datalist
 * wird bei jeder Eingabe mit „bisherige Stichworte, Vorschlag“ befüllt, damit die Auswahl das zuletzt
 * getippte Stichwort ergänzt statt das ganze Feld zu ersetzen.
 */
(function () {
    "use strict";

    document.querySelectorAll("input[data-hd-contact-tags]").forEach(function (input) {
        const list = document.getElementById(input.getAttribute("list") || "");
        if (!list) { return; }
        let source = [];
        try { source = JSON.parse(list.getAttribute("data-hd-contact-tag-source") || "[]"); } catch (e) { source = []; }
        if (!Array.isArray(source) || source.length === 0) { return; }

        function refresh() {
            const parts = input.value.split(/[,;]/);
            const current = (parts.pop() || "").trim().toLowerCase();
            const chosen = parts.map(function (p) { return p.trim(); }).filter(Boolean);
            const chosenLower = chosen.map(function (p) { return p.toLowerCase(); });
            const prefix = chosen.length ? chosen.join(", ") + ", " : "";
            list.textContent = "";
            source.filter(function (tag) {
                const lower = String(tag).toLowerCase();
                return chosenLower.indexOf(lower) === -1 && (current === "" || lower.indexOf(current) !== -1);
            }).slice(0, 15).forEach(function (tag) {
                const opt = document.createElement("option");
                opt.value = prefix + tag;
                list.appendChild(opt);
            });
        }
        input.addEventListener("input", refresh);
        input.addEventListener("focus", refresh);
    });
})();
