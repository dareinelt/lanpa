/* Einkauf: Positionsformular (Artikel → Typ/Bezeichnung) und Wareneingang (Seriennummernfelder je Stück) */
(function () {
    "use strict";

    // Positionsformular: Artikelwahl übernimmt Assettyp und schlägt Bezeichnung vor
    const articleSelect = document.querySelector("[data-article-select]");
    if (articleSelect) {
        const typeSelect = document.querySelector("[data-item-type]");
        const description = document.querySelector("[data-item-description]");
        let lastSuggested = description ? description.value : "";
        articleSelect.addEventListener("change", function () {
            const opt = articleSelect.selectedOptions[0];
            if (!opt || !opt.value) {
                if (typeSelect) { typeSelect.disabled = false; }
                return;
            }
            if (typeSelect && opt.dataset.type) {
                typeSelect.value = opt.dataset.type;
            }
            if (description && (description.value.trim() === "" || description.value === lastSuggested)) {
                description.value = opt.dataset.label || "";
                lastSuggested = description.value;
            }
        });
    }

    // Wareneingang
    const form = document.querySelector(".receive-form");
    if (!form) { return; }

    const items = Array.from(form.querySelectorAll("[data-receive-item]"));
    const summary = form.querySelector("[data-receive-summary]");

    function syncSerials(item) {
        const list = item.querySelector("[data-serial-list]");
        const qtyInput = item.querySelector("[data-receive-qty]");
        if (!list || !qtyInput) { return; }
        const max = parseInt(qtyInput.max || "0", 10);
        let qty = parseInt(qtyInput.value || "0", 10);
        if (isNaN(qty) || qty < 0) { qty = 0; }
        if (max && qty > max) { qty = max; qtyInput.value = String(max); }
        item.classList.toggle("is-filled", qty > 0);
        if (item.dataset.serial !== "1") {
            list.innerHTML = "";
            return;
        }
        const id = item.dataset.itemId;
        const fields = Array.from(list.querySelectorAll(".serial-field"));
        while (fields.length > qty) {
            fields.pop().remove();
        }
        for (let i = fields.length; i < qty; i++) {
            const wrap = document.createElement("div");
            wrap.className = "form-group serial-field";
            const label = document.createElement("label");
            label.htmlFor = "s-" + id + "-" + i;
            label.textContent = "Seriennummer Stück " + (i + 1);
            const input = document.createElement("input");
            input.id = label.htmlFor;
            input.name = "items[" + id + "][serials][]";
            input.className = "mono";
            input.maxLength = 120;
            input.autocomplete = "off";
            wrap.appendChild(label);
            wrap.appendChild(input);
            list.appendChild(wrap);
        }
    }

    function updateSummary() {
        let pieces = 0;
        let assets = 0;
        items.forEach(function (item) {
            const qty = parseInt((item.querySelector("[data-receive-qty]") || {}).value || "0", 10) || 0;
            pieces += qty;
            if (item.dataset.createsAssets === "1") { assets += qty; }
        });
        if (summary) {
            summary.textContent = pieces > 0 ? pieces + " Stück, " + assets + " neue Assets" : "";
        }
    }

    items.forEach(function (item) {
        const qtyInput = item.querySelector("[data-receive-qty]");
        const allBtn = item.querySelector("[data-receive-all]");
        if (qtyInput) {
            qtyInput.addEventListener("input", function () { syncSerials(item); updateSummary(); });
            syncSerials(item);
        }
        if (allBtn && qtyInput) {
            allBtn.addEventListener("click", function () {
                qtyInput.value = allBtn.dataset.receiveAll;
                syncSerials(item);
                updateSummary();
                const first = item.querySelector(".serial-field input");
                if (first) { first.focus(); }
            });
        }
    });
    updateSummary();

    // Scanner: Enter in einem Seriennummernfeld springt zum nächsten statt abzusenden
    form.addEventListener("keydown", function (event) {
        if (event.key !== "Enter" || !(event.target instanceof HTMLInputElement)) { return; }
        if (!event.target.name || event.target.name.indexOf("[serials]") === -1) { return; }
        event.preventDefault();
        const inputs = Array.from(form.querySelectorAll('input[name*="[serials]"]'));
        const next = inputs[inputs.indexOf(event.target) + 1];
        if (next) {
            next.focus();
            next.select();
        }
    });

    // Doppelte Seriennummern sofort markieren
    form.addEventListener("input", function (event) {
        if (!(event.target instanceof HTMLInputElement) || event.target.name.indexOf("[serials]") === -1) { return; }
        const inputs = Array.from(form.querySelectorAll('input[name*="[serials]"]'));
        const seen = {};
        inputs.forEach(function (input) {
            const key = input.value.replace(/[\s-]/g, "").toUpperCase();
            input.setAttribute("aria-invalid", key !== "" && seen[key] ? "true" : "false");
            if (key !== "") { seen[key] = true; }
        });
    });
})();
