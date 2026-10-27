/**
 * Sammelauswahl der Ticketliste: Checkboxen, „Zusammenführen“-Schaltfläche
 * und Overlay zur Wahl des Zieltickets.
 */
(function () {
    "use strict";

    const boxes = Array.prototype.slice.call(document.querySelectorAll("[data-hd-ticket-select]"));
    const button = document.querySelector("[data-hd-bulk-merge]");
    if (boxes.length === 0 || !button) { return; }

    const countEl = document.querySelector("[data-hd-bulk-merge-count]");
    const selectAll = document.querySelector("[data-hd-ticket-select-all]");
    const dialog = document.querySelector("[data-hd-bulk-merge-dialog]");
    const form = dialog ? dialog.querySelector("[data-hd-bulk-merge-form]") : null;
    const targetList = dialog ? dialog.querySelector("[data-hd-bulk-merge-targets]") : null;
    const errorEl = dialog ? dialog.querySelector("[data-hd-bulk-merge-error]") : null;

    function activeBoxes() {
        return boxes.filter(function (box) { return !box.disabled; });
    }

    function selectedBoxes() {
        return boxes.filter(function (box) { return box.checked && !box.disabled; });
    }

    function refresh() {
        const count = selectedBoxes().length;
        button.hidden = count <= 2;
        if (countEl) { countEl.textContent = count > 2 ? " (" + count + ")" : ""; }
        if (selectAll) {
            const active = activeBoxes();
            const checked = active.filter(function (box) { return box.checked; }).length;
            selectAll.checked = checked > 0 && checked === active.length;
            selectAll.indeterminate = checked > 0 && checked < active.length;
        }
    }

    boxes.forEach(function (box) { box.addEventListener("change", refresh); });

    if (selectAll) {
        selectAll.addEventListener("change", function () {
            activeBoxes().forEach(function (box) { box.checked = selectAll.checked; });
            refresh();
        });
    }

    function buildTargets(items) {
        targetList.innerHTML = "";
        items.forEach(function (box, index) {
            const label = document.createElement("label");
            label.className = "hd-merge-target";

            const radio = document.createElement("input");
            radio.type = "radio";
            radio.name = "target";
            radio.value = box.value;
            radio.checked = index === 0;

            const main = document.createElement("span");
            main.className = "hd-merge-target-main";

            const number = document.createElement("span");
            number.className = "hd-merge-suggestion-number";
            number.textContent = box.getAttribute("data-number") || "";

            const subject = document.createElement("span");
            subject.className = "hd-merge-suggestion-subject";
            subject.textContent = box.getAttribute("data-subject") || "";

            main.appendChild(number);
            main.appendChild(subject);
            label.appendChild(radio);
            label.appendChild(main);
            targetList.appendChild(label);
        });
    }

    button.addEventListener("click", function () {
        const chosen = selectedBoxes();
        if (!dialog || !form || !targetList || chosen.length <= 2) { return; }
        buildTargets(chosen);
        if (errorEl) { errorEl.hidden = true; }
        if (typeof dialog.showModal === "function") { dialog.showModal(); }
    });

    if (!form) { return; }

    form.addEventListener("submit", function (event) {
        const target = form.querySelector('input[name="target"]:checked');
        if (!target) {
            event.preventDefault();
            if (errorEl) {
                errorEl.textContent = "Bitte ein Zielticket auswählen.";
                errorEl.hidden = false;
            }
            return;
        }

        form.querySelectorAll('input[name="sources[]"]').forEach(function (input) { input.remove(); });
        selectedBoxes().forEach(function (box) {
            if (box.value === target.value) { return; }
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = "sources[]";
            input.value = box.value;
            form.appendChild(input);
        });
    });

    refresh();
})();
