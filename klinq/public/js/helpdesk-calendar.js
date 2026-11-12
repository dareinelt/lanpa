/*
 * Help-Desk-Kalender: Lage der Termine im Tagesraster, Jetzt-Linie, Formularhilfen (Ganztägig, Ende
 * nachführen), Checkliste („Checkliste anlegen“), Overlay „Neuer Termin“ im Kalender, automatisches Termin-Overlay
 * nach Übernahme der Zuständigkeit und der Dialog
 * „Termin anlegen“ im Ticket mit Datumsvorschlägen aus dem Tickettext (KI).
 * Inline-Styles sind per CSP gesperrt; Positionen werden deshalb als CSS-Variablen über das CSSOM gesetzt.
 */
(function () {
    "use strict";

    const UI = window.AppUI;

    // ------------------------------------------------------------------ Tagesraster
    function layoutEvents(root) {
        root.querySelectorAll(".hd-cal-event[data-top]").forEach(function (el) {
            el.style.setProperty("--hd-cal-top", el.getAttribute("data-top"));
            el.style.setProperty("--hd-cal-height", el.getAttribute("data-height"));
            el.style.setProperty("--hd-cal-col", el.getAttribute("data-col"));
            el.style.setProperty("--hd-cal-cols", el.getAttribute("data-cols"));
        });
    }

    function minutesNow() {
        const now = new Date();
        return now.getHours() * 60 + now.getMinutes();
    }

    function localDate(date) {
        const pad = function (n) { return String(n).padStart(2, "0"); };
        return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
    }

    function nowLine(root) {
        const column = root.querySelector('.hd-cal-day[data-day="' + localDate(new Date()) + '"]');
        if (!column) { return; }
        let line = column.querySelector(".hd-cal-now");
        if (!line) {
            line = document.createElement("div");
            line.className = "hd-cal-now";
            line.setAttribute("aria-hidden", "true");
            column.appendChild(line);
        }
        line.style.setProperty("--hd-cal-top", String(minutesNow()));
    }

    function initGrid(root) {
        layoutEvents(root);
        const scroller = root.querySelector("[data-hd-cal-scroll]");
        if (scroller) {
            // Wie in Outlook: Raster beginnt beim Arbeitsbeginn (07:00) bzw. eine Stunde vor „jetzt“.
            const hour = scroller.querySelector(".hd-cal-slot, .hd-cal-hour-label");
            const hourHeight = hour ? hour.getBoundingClientRect().height : 48;
            const today = root.querySelector('.hd-cal-day[data-day="' + localDate(new Date()) + '"]');
            const startHour = today ? Math.max(0, Math.min(7, Math.floor(minutesNow() / 60) - 1)) : 7;
            // Etwas Luft lassen, damit die Beschriftung der ersten Stunde sichtbar bleibt.
            scroller.scrollTop = Math.max(0, Math.round(hourHeight * startHour) - 12);
            nowLine(root);
            window.setInterval(function () { nowLine(root); }, 60000);
        }
        const jump = root.querySelector("[data-hd-cal-jump]");
        if (jump) {
            jump.addEventListener("change", function () {
                if (jump.value) { jump.form.submit(); }
            });
        }
    }

    // ------------------------------------------------------------------ Formular
    function addMinutes(time, minutes) {
        const parts = String(time || "").split(":");
        if (parts.length !== 2) { return ""; }
        let total = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10) + minutes;
        total = Math.max(0, Math.min(23 * 60 + 59, total));
        return String(Math.floor(total / 60)).padStart(2, "0") + ":" + String(total % 60).padStart(2, "0");
    }

    function initFields(container) {
        const allDay = container.querySelector("[data-hd-cal-allday]");
        const date = container.querySelector("[data-hd-cal-date]");
        const endDate = container.querySelector("[data-hd-cal-end-date]");
        const times = container.querySelectorAll("[data-hd-cal-time]");
        const start = container.querySelector('[name="start_time"]');
        const end = container.querySelector("[data-hd-cal-end-time]");
        if (!allDay || !date) { return; }

        function applyAllDay() {
            times.forEach(function (input) {
                input.hidden = allDay.checked;
                input.disabled = allDay.checked;
            });
            if (!allDay.checked && start && !start.value) { start.value = "09:00"; }
            if (!allDay.checked && end && !end.value && start) { end.value = addMinutes(start.value, 60); }
        }
        allDay.addEventListener("change", applyAllDay);
        applyAllDay();

        let previousDate = date.value;
        date.addEventListener("change", function () {
            if (endDate && (!endDate.value || endDate.value === previousDate || endDate.value < date.value)) {
                endDate.value = date.value;
            }
            previousDate = date.value;
        });
        if (start && end) {
            let previousStart = start.value;
            start.addEventListener("change", function () {
                // Dauer beibehalten, solange Beginn und Ende am selben Tag liegen
                const sameDay = !endDate || !endDate.value || endDate.value === date.value;
                if (sameDay && start.value) {
                    const duration = previousStart && end.value > previousStart ? minutesBetween(previousStart, end.value) : 60;
                    end.value = addMinutes(start.value, duration);
                }
                previousStart = start.value;
            });
        }
    }

    function minutesBetween(a, b) {
        const pa = a.split(":"), pb = b.split(":");
        return (parseInt(pb[0], 10) * 60 + parseInt(pb[1], 10)) - (parseInt(pa[0], 10) * 60 + parseInt(pa[1], 10));
    }

    // ------------------------------------------------------------------ Serientermine
    var SERIES_UNITS = { daily: "Tage", weekly: "Wochen", monthly: "Monate", yearly: "Jahre" };

    function initRecurrence(form) {
        const rule = form.querySelector("[data-hd-cal-recurrence]");
        if (!rule) { return; }
        const extras = form.querySelectorAll("[data-hd-cal-series-extra]");
        const unit = form.querySelector("[data-hd-cal-interval-unit]");

        function apply() {
            const enabled = rule.value !== "";
            extras.forEach(function (field) { field.hidden = !enabled; });
            if (unit) { unit.textContent = SERIES_UNITS[rule.value] || SERIES_UNITS.weekly; }
        }

        rule.addEventListener("change", apply);
        apply();
    }

    // ------------------------------------------------------------------ Dialog „Termin anlegen“ im Ticket
    function formatDate(iso) {
        const p = iso.split("-");
        const d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
        const names = ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"];
        return names[d.getDay()] + ", " + p[2] + "." + p[1] + "." + p[0];
    }

    function initTicketDialog(dialog) {
        const form = dialog.querySelector("[data-hd-cal-ticket-form]");
        const status = dialog.querySelector("[data-hd-cal-suggest-status]");
        const list = dialog.querySelector("[data-hd-cal-suggest-list]");
        const options = dialog.querySelector("[data-hd-cal-suggest-options]");
        const choiceError = dialog.querySelector("[data-hd-cal-choice-error]");
        const date = form.querySelector("[data-hd-cal-date]");
        const endDate = form.querySelector("[data-hd-cal-end-date]");
        const start = form.querySelector('[name="start_time"]');
        const end = form.querySelector('[name="end_time"]');
        const allDay = form.querySelector("[data-hd-cal-allday]");
        const description = form.querySelector('[name="description"]');
        let requireChoice = false;
        let loaded = false;
        // Hat der Anwender während der Analyse schon selbst ein Datum eingetragen, überschreibt
        // ein später eintreffender Vorschlag seine Eingabe nicht.
        let touched = false;
        [date, endDate, start, end, allDay].forEach(function (field) {
            field.addEventListener("input", function () { touched = true; });
        });

        function apply(suggestion) {
            date.value = suggestion.date;
            endDate.value = suggestion.end_date || suggestion.date;
            if (suggestion.end_date && !suggestion.start) {
                // Mehrtägiger Zeitraum ohne Uhrzeit (z. B. Urlaub): ganztägig eintragen
                allDay.checked = true;
            }
            if (suggestion.start) {
                allDay.checked = false;
                start.value = suggestion.start;
                end.value = suggestion.end || addMinutes(suggestion.start, 60);
            }
            allDay.dispatchEvent(new Event("change"));
            if (description && !description.value && suggestion.excerpt) {
                description.value = "Aus dem Tickettext: „" + suggestion.excerpt + "“";
            }
            choiceError.hidden = true;
        }

        function render(data) {
            const suggestions = (data && data.suggestions) || [];
            status.textContent = (data && data.message) || "";
            status.classList.toggle("is-warning", !data || data.available === false);
            if (suggestions.length === 0) {
                list.hidden = true;
                date.focus();
                return;
            }
            options.textContent = "";
            suggestions.forEach(function (s, index) {
                const label = document.createElement("label");
                label.className = "checkbox-field hd-cal-suggest-option";
                const input = document.createElement("input");
                input.type = "radio";
                input.name = "suggestion";
                input.value = String(index);
                const text = document.createElement("span");
                const head = document.createElement("strong");
                head.textContent = formatDate(s.date) + (s.end_date ? " – " + formatDate(s.end_date) : "")
                    + (s.start ? ", " + s.start + (s.end ? "–" + s.end : "") + " Uhr" : "");
                text.appendChild(head);
                if (s.label) {
                    text.appendChild(document.createTextNode(" · " + s.label));
                }
                if (s.excerpt) {
                    const quote = document.createElement("span");
                    quote.className = "hd-cal-suggest-excerpt";
                    quote.textContent = "„" + s.excerpt + "“";
                    text.appendChild(quote);
                }
                label.appendChild(input);
                label.appendChild(text);
                input.addEventListener("change", function () { apply(s); });
                options.appendChild(label);
            });
            const manual = document.createElement("label");
            manual.className = "checkbox-field hd-cal-suggest-option";
            const manualInput = document.createElement("input");
            manualInput.type = "radio";
            manualInput.name = "suggestion";
            manualInput.value = "manual";
            manualInput.addEventListener("change", function () {
                choiceError.hidden = true;
                date.focus();
            });
            const manualText = document.createElement("span");
            manualText.textContent = "Anderes Datum manuell eintragen";
            manual.appendChild(manualInput);
            manual.appendChild(manualText);
            options.appendChild(manual);
            list.hidden = false;

            if (touched) {
                // Eigene Eingabe behalten; die Vorschläge stehen zur Wahl bereit.
                manualInput.checked = true;
            } else if (suggestions.length === 1) {
                // Ein Datum: als Vorschlag übernehmen, bleibt änderbar.
                options.querySelector('input[value="0"]').checked = true;
                apply(suggestions[0]);
            } else {
                // Mehrere Daten: der Anwender muss das zutreffende wählen.
                requireChoice = true;
                date.value = "";
                endDate.value = "";
            }
        }

        function load() {
            if (loaded) { return; }
            loaded = true;
            if (dialog.getAttribute("data-hd-cal-ai") !== "1") {
                date.focus();
                return;
            }
            dialog.classList.add("is-loading");
            // Lokale Modelle auf CPU brauchen beim ersten Aufruf (Modell laden) gern eine halbe Minute.
            const slowHint = window.setTimeout(function () {
                if (dialog.classList.contains("is-loading")) {
                    status.textContent = "Die KI-Analyse dauert noch einen Moment … Das Datum kann währenddessen schon manuell eingetragen werden.";
                }
            }, 6000);
            UI.api("/helpdesk/tickets/" + dialog.getAttribute("data-hd-cal-ticket") + "/termin-vorschlaege")
                .then(render)
                .catch(function () {
                    render({ available: false, message: "Die KI ist derzeit nicht erreichbar. Bitte das Datum manuell eintragen.", suggestions: [] });
                })
                .finally(function () {
                    window.clearTimeout(slowHint);
                    dialog.classList.remove("is-loading");
                });
        }

        document.querySelectorAll('[data-dialog-open="' + dialog.id + '"]').forEach(function (button) {
            button.addEventListener("click", load);
        });

        form.addEventListener("submit", function (event) {
            const chosen = form.querySelector('input[name="suggestion"]:checked');
            if ((requireChoice && !chosen) || !date.value) {
                event.preventDefault();
                // Nicht an die globale Absendesperre (ui.js) weitergeben, sonst bliebe der Button gesperrt.
                event.stopPropagation();
                choiceError.hidden = false;
                choiceError.scrollIntoView({ block: "nearest" });
                (requireChoice && !chosen ? options.querySelector("input") : date).focus();
            }
        });
    }

    // ------------------------------------------------------------------ Checkliste
    function initChecklist(root) {
        const open = root.querySelector("[data-hd-cal-checklist-open]");
        const panel = root.querySelector("[data-hd-cal-checklist-panel]");
        const rows = root.querySelector("[data-hd-cal-checklist-rows]");
        const template = root.querySelector("[data-hd-cal-checklist-template]");
        const add = root.querySelector("[data-hd-cal-checklist-add]");
        const max = parseInt(root.getAttribute("data-max"), 10) || 30;
        if (!open || !panel || !rows || !template || !add) { return; }
        // Zeilenindizes bleiben eindeutig, auch nach Entfernen von Zeilen.
        let next = rows.children.length;

        function count() { return rows.querySelectorAll("[data-hd-cal-checklist-row]").length; }

        function update() {
            const empty = count() === 0;
            panel.hidden = empty;
            open.hidden = !empty;
            add.hidden = count() >= max;
        }

        function bind(row) {
            const remove = row.querySelector("[data-hd-cal-checklist-remove]");
            remove.hidden = false;
            remove.addEventListener("click", function () {
                const sibling = row.nextElementSibling || row.previousElementSibling;
                row.remove();
                update();
                const field = sibling ? sibling.querySelector('input[type="text"]') : null;
                (field || open).focus();
            });
        }

        function addRow() {
            if (count() >= max) { return; }
            const html = template.innerHTML.split("__INDEX__").join(String(next++));
            rows.insertAdjacentHTML("beforeend", html);
            const row = rows.lastElementChild;
            bind(row);
            update();
            row.querySelector('input[type="text"]').focus();
        }

        // Leere Platzhalterzeilen (für Browser ohne JavaScript) entfallen; bei Fehlern bleibt die Liste offen.
        rows.querySelectorAll("[data-hd-cal-checklist-row]").forEach(function (row) {
            const label = row.querySelector('input[type="text"]');
            const id = row.querySelector('input[type="hidden"]');
            if (!label.value.trim() && !id) {
                row.remove();
            } else {
                bind(row);
            }
        });
        open.addEventListener("click", addRow);
        add.addEventListener("click", addRow);
        rows.addEventListener("keydown", function (event) {
            // Enter im letzten Punkt legt einen neuen Punkt an, statt das Formular abzusenden.
            if (event.key === "Enter" && event.target.matches('input[type="text"]')) {
                event.preventDefault();
                if (event.target.closest("[data-hd-cal-checklist-row]") === rows.lastElementChild && event.target.value.trim()) {
                    addRow();
                }
            }
        });
        update();
    }

    // ------------------------------------------------------------------ Overlay „Neuer Termin“ im Kalender
    function initNewDialog(dialog) {
        const form = dialog.querySelector("form");
        const field = function (name) { return form.querySelector('[name="' + name + '"]'); };
        const allDay = form.querySelector("[data-hd-cal-allday]");
        const recurrence = form.querySelector("[data-hd-cal-recurrence]");

        function open(link) {
            const params = new URL(link.href, window.location.href).searchParams;
            const date = params.get("datum") || "";
            const time = params.get("zeit") || "";
            const isAllDay = params.get("ganztags") === "1";
            ["title", "location", "description", "recurrence_until"].forEach(function (name) {
                if (field(name)) { field(name).value = ""; }
            });
            if (field("recurrence_interval")) { field("recurrence_interval").value = "1"; }
            if (recurrence) {
                recurrence.value = "";
                recurrence.dispatchEvent(new Event("change"));
            }
            form.querySelectorAll("[data-hd-cal-checklist-remove]").forEach(function (button) { button.click(); });
            form.querySelectorAll(".has-error").forEach(function (el) { el.classList.remove("has-error"); });
            form.querySelectorAll(".form-error").forEach(function (el) { el.remove(); });
            field("date").value = date;
            field("end_date").value = date;
            field("start_time").value = isAllDay ? "" : (time || "09:00");
            field("end_time").value = isAllDay ? "" : addMinutes(time || "09:00", 60);
            allDay.checked = isAllDay;
            allDay.dispatchEvent(new Event("change"));
            dialog.showModal();
            field("title").focus();
        }

        document.addEventListener("click", function (event) {
            const link = event.target.closest("a[data-hd-cal-new]");
            // Mittelklick und Strg-/Umschalt-Klick öffnen weiterhin die Formularseite.
            if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; }
            if (typeof dialog.showModal !== "function") { return; }
            event.preventDefault();
            open(link);
        });

        if (dialog.hasAttribute("data-dialog-autoopen")) {
            // Nach einem Validierungsfehler das erste fehlerhafte Feld fokussieren (nach dem automatischen Öffnen).
            window.setTimeout(function () {
                const invalid = form.querySelector(".has-error input:not([type=hidden]), .has-error select, .has-error textarea");
                if (invalid) { invalid.focus(); }
            }, 0);
        }
    }

    // ------------------------------------------------------------------ Start
    document.querySelectorAll("[data-hd-cal]").forEach(initGrid);
    document.querySelectorAll("[data-hd-cal-when]").forEach(function (el) { initFields(el.closest("form") || el); });
    document.querySelectorAll("[data-hd-cal-series]").forEach(function (el) { initRecurrence(el.closest("form") || el); });
    document.querySelectorAll("[data-hd-cal-checklist]").forEach(initChecklist);
    document.querySelectorAll("[data-hd-cal-ticket]").forEach(initTicketDialog);
    document.querySelectorAll("[data-hd-cal-new-dialog]").forEach(initNewDialog);
    document.querySelectorAll("dialog[data-dialog-autoopen]").forEach(function (dialog) {
        if (typeof dialog.showModal === "function") { dialog.showModal(); }
    });
})();
