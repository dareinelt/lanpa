/* Mobile Erfassung: Komfortfunktionen für Entnahme-/Rückgabeformulare.
   Absenden per klassischem POST (funktioniert ohne JS); ohne Netz wandert der Vorgang in die Offline-Warteschlange. */
(function () {
    "use strict";
    const form = document.getElementById("movement-form");
    if (!form) { return; }

    // Rückgabe: Zielstatus-Hinweis je Zustand
    const targetSelect = form.querySelector("[data-target-status]");
    const targetHint = form.querySelector("[data-target-hint]");
    const damageBox = document.getElementById("f-has-damage");
    const defaults = { ok: "Lagerbestand", worn: "Lagerbestand", damaged: "Reparatur", defective: "Defekt" };
    function updateTargetHint() {
        if (!targetSelect || !targetHint) { return; }
        const cond = form.querySelector("[data-condition]:checked");
        const code = cond ? cond.value : "ok";
        const auto = damageBox && damageBox.checked && code !== "defective" ? "Reparatur" : (defaults[code] || "Lagerbestand");
        targetHint.textContent = targetSelect.value === "" ? "Automatisch: " + auto : "";
        if (cond && (code === "damaged" || code === "defective") && damageBox && !damageBox.checked && !damageBox.dataset.touched) {
            damageBox.checked = true;
            damageBox.dispatchEvent(new Event("change", { bubbles: true }));
        }
    }
    form.querySelectorAll("[data-condition]").forEach(function (r) { r.addEventListener("change", updateTargetHint); });
    if (targetSelect) { targetSelect.addEventListener("change", updateTargetHint); }
    if (damageBox) { damageBox.addEventListener("click", function () { damageBox.dataset.touched = "1"; updateTargetHint(); }); }
    updateTargetHint();

    // Fotovorschau
    const photoInput = form.querySelector("[data-photo-input]");
    const previews = form.querySelector("[data-photo-previews]");
    if (photoInput && previews) {
        photoInput.addEventListener("change", function () {
            previews.innerHTML = "";
            Array.from(photoInput.files || []).slice(0, 8).forEach(function (file) {
                if (!file.type.startsWith("image/")) { return; }
                const img = document.createElement("img");
                img.alt = file.name;
                img.src = URL.createObjectURL(file);
                img.addEventListener("load", function () { URL.revokeObjectURL(img.src); });
                previews.appendChild(img);
            });
        });
    }

    // Offline: Vorgang lokal in die Warteschlange legen statt abzuschicken
    function readPhotos(input) {
        const files = Array.from((input && input.files) || []).filter(function (f) { return f.type.indexOf("image/") === 0; }).slice(0, 4);
        return Promise.all(files.map(function (file) {
            return new Promise(function (resolve) {
                const reader = new FileReader();
                reader.onload = function () { resolve({ name: file.name, type: file.type, data: String(reader.result).split(",")[1] || "" }); };
                reader.onerror = function () { resolve(null); };
                reader.readAsDataURL(file);
            });
        })).then(function (list) { return list.filter(Boolean); });
    }

    form.addEventListener("submit", function (e) {
        if (navigator.onLine) { return; }
        e.preventDefault();
        if (!window.Offline) {
            (window.AppUI && window.AppUI.toast ? window.AppUI.toast : window.alert)("Offline – bitte erneut senden, sobald eine Verbindung besteht.", "warning");
            return;
        }
        const type = form.getAttribute("data-movement") === "return" ? "return" : "checkout";
        const payload = {};
        new FormData(form).forEach(function (v, k) { if (typeof v === "string" && k !== "_csrf") { payload[k] = v; } });
        const pickerLabel = form.querySelector('[data-picker] [data-picker-label]');
        const label = type === "checkout"
            ? "an " + (pickerLabel && pickerLabel.textContent.trim() ? pickerLabel.textContent.trim() : "Mitarbeiter")
            : "Zustand: " + ((form.querySelector("[data-condition]:checked") || {}).value || "");
        const btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; }
        readPhotos(photoInput).then(function (photos) {
            return window.Offline.enqueue({ type: type, inventory_number: payload.inventory_number || "", label: label, payload: payload, photos: photos, client_transaction_id: payload.client_transaction_id });
        }).then(function () {
            window.location.href = "/m?queued=1";
        }).catch(function () {
            if (btn) { btn.disabled = false; }
            (window.AppUI && window.AppUI.toast ? window.AppUI.toast : window.alert)("Lokales Speichern fehlgeschlagen.", "error");
        });
    });
})();
