/* Einkauf: Positionsformular (Artikel → Typ/Bezeichnung) und Wareneingang (Seriennummernfelder je Stück) */
(function () {
    "use strict";

    // Positionsformular: Artikelwahl übernimmt Assettyp, steuert „Erzeugt Assets“ und schlägt Bezeichnung vor
    const articleSelect = document.querySelector("[data-article-select]");
    if (articleSelect) {
        const typeSelect = document.querySelector("[data-item-type]");
        const description = document.querySelector("[data-item-description]");
        const createsCheckbox = document.querySelector("[data-creates-assets]");
        let lastSuggested = description ? description.value : "";

        function syncArticleSelection(isInitial) {
            const opt = articleSelect.selectedOptions[0];
            if (!opt || !opt.value) {
                // Freie Position ohne Stammartikel: Assettyp optional, keine Asset-Erzeugung möglich
                if (typeSelect) { typeSelect.disabled = false; }
                if (createsCheckbox) {
                    createsCheckbox.checked = false;
                    createsCheckbox.disabled = true;
                }
                return;
            }
            const isConsumable = opt.dataset.consumable === "1";
            if (isConsumable) {
                if (typeSelect) {
                    typeSelect.value = "";
                    typeSelect.disabled = true;
                }
                if (createsCheckbox) {
                    createsCheckbox.checked = false;
                    createsCheckbox.disabled = true;
                }
            } else {
                if (typeSelect) {
                    if (opt.dataset.type) { typeSelect.value = opt.dataset.type; }
                    typeSelect.disabled = true;
                }
                if (createsCheckbox) {
                    const wasDisabled = createsCheckbox.disabled;
                    createsCheckbox.disabled = false;
                    if (!isInitial && wasDisabled) {
                        createsCheckbox.checked = true;
                    }
                }
            }
            if (!isInitial && description && (description.value.trim() === "" || description.value === lastSuggested)) {
                description.value = opt.dataset.label || "";
                lastSuggested = description.value;
            }
        }

        articleSelect.addEventListener("change", function () { syncArticleSelection(false); });
        syncArticleSelection(true);
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
        let consumables = 0;
        items.forEach(function (item) {
            const qty = parseInt((item.querySelector("[data-receive-qty]") || {}).value || "0", 10) || 0;
            pieces += qty;
            if (item.dataset.createsAssets === "1") { assets += qty; }
            if (item.dataset.consumable === "1") { consumables += qty; }
        });
        if (!summary) { return; }
        if (pieces <= 0) {
            summary.textContent = "";
            return;
        }
        const parts = [pieces + " Stück"];
        if (assets > 0) { parts.push(assets + (assets === 1 ? " neues Asset" : " neue Assets")); }
        if (consumables > 0) { parts.push(consumables + " Stück Bestandszugang"); }
        summary.textContent = parts.join(" · ");
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
