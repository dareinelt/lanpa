/*
 * Help-Desk-Kalender: Lage der Termine im Tagesraster, Jetzt-Linie, Formularhilfen (Ganztägig, Ende
 * nachführen), automatisches Termin-Overlay nach Übernahme der Zuständigkeit und der Dialog
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
            scroller.scrollTop = Math.round(hourHeight * startHour);
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

        function apply(suggestion) {
            date.value = suggestion.date;
            endDate.value = suggestion.date;
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
                head.textContent = formatDate(s.date) + (s.start ? ", " + s.start + (s.end ? "–" + s.end : "") + " Uhr" : "");
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

            if (suggestions.length === 1) {
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
            UI.api("/helpdesk/tickets/" + dialog.getAttribute("data-hd-cal-ticket") + "/termin-vorschlaege")
                .then(render)
                .catch(function () {
                    render({ available: false, message: "Die KI ist derzeit nicht erreichbar. Bitte das Datum manuell eintragen.", suggestions: [] });
                })
                .finally(function () { dialog.classList.remove("is-loading"); });
        }

        document.querySelectorAll('[data-dialog-open="' + dialog.id + '"]').forEach(function (button) {
            button.addEventListener("click", load);
        });

        form.addEventListener("submit", function (event) {
            const chosen = form.querySelector('input[name="suggestion"]:checked');
            if ((requireChoice && !chosen) || !date.value) {
                event.preventDefault();
                choiceError.hidden = false;
                (requireChoice && !chosen ? options.querySelector("input") : date).focus();
            }
        });
    }

    // ------------------------------------------------------------------ Start
    document.querySelectorAll("[data-hd-cal]").forEach(initGrid);
    document.querySelectorAll("[data-hd-cal-when]").forEach(function (el) { initFields(el.closest("form") || el); });
    document.querySelectorAll("[data-hd-cal-ticket]").forEach(initTicketDialog);
    document.querySelectorAll("dialog[data-dialog-autoopen]").forEach(function (dialog) {
        if (typeof dialog.showModal === "function") { dialog.showModal(); }
    });
})();
