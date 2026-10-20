/* Offline-Oberfläche der Scan-Seite (/m): Warteschlange anzeigen/steuern, Asset aus dem lokalen Cache anzeigen
   und Entnahme/Rückgabe ohne Netz erfassen. Benötigt offline.js (window.Offline). */
(function () {
    "use strict";
    const O = window.Offline;
    if (!O) { return; }
    const queueRoot = document.getElementById("offline-queue");
    const assetRoot = document.getElementById("offline-asset");
    const scanner = document.getElementById("scanner");

    function esc(s) {
        return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }
    function toast(msg, type) {
        if (window.AppUI && window.AppUI.toast) { window.AppUI.toast(msg, type || "info"); }
    }
    function icon(name) {
        return '<svg class="icon" aria-hidden="true"><use href="#icon-' + name + '"/></svg>';
    }
    function fmtTime(iso) {
        const d = new Date(iso);
        return isNaN(d) ? "" : d.toLocaleString("de-DE", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
    }

    // ------------------------------------------------------------------ Warteschlange

    const STATUS = {
        pending: { label: "Wartet", cls: "neutral" },
        conflict: { label: "Konflikt", cls: "danger" },
        error: { label: "Fehler", cls: "danger" },
        forbidden: { label: "Keine Berechtigung", cls: "danger" }
    };

    function renderQueue() {
        if (!queueRoot) { return; }
        O.listQueue().then(function (rows) {
            queueRoot.hidden = rows.length === 0;
            if (!rows.length) { queueRoot.innerHTML = ""; return; }
            const pending = rows.filter(function (r) { return r.status === "pending"; }).length;
            queueRoot.innerHTML =
                '<div class="m-queue-head"><h2 class="text-sm text-muted mb-0">Ausstehende Vorgänge (' + rows.length + ')</h2>'
                + (pending && navigator.onLine ? '<button type="button" class="btn btn-secondary btn-sm" data-queue-sync>' + icon("refresh") + ' Jetzt senden</button>' : "")
                + '</div>'
                + '<ul class="m-list m-queue">' + rows.map(function (r) {
                    const st = STATUS[r.status] || STATUS.pending;
                    const actions = [];
                    const versionConflict = r.status === "conflict" && r.conflict && r.conflict.version !== undefined;
                    if (versionConflict) {
                        actions.push('<button type="button" class="btn btn-danger btn-sm" data-queue-force="' + esc(r.client_transaction_id) + '">Trotzdem übernehmen</button>');
                    }
                    if (r.status !== "pending") {
                        actions.push('<button type="button" class="btn btn-secondary btn-sm" data-queue-retry="' + esc(r.client_transaction_id) + '">Erneut senden</button>');
                    }
                    actions.push('<button type="button" class="btn btn-ghost btn-sm" data-queue-remove="' + esc(r.client_transaction_id) + '">Verwerfen</button>');
                    return '<li><div class="m-list-item m-queue-item">'
                        + icon(r.type === "checkout" ? "checkout" : "return")
                        + '<div class="m-list-main"><div class="m-list-title"><span class="mono">' + esc(r.inventory_number) + '</span> · ' + (r.type === "checkout" ? "Entnahme" : "Rückgabe")
                        + ' <span class="badge badge-' + st.cls + '">' + st.label + '</span></div>'
                        + '<div class="m-list-sub">' + esc(r.label) + ' · ' + fmtTime(r.created_at) + (r.photos && r.photos.length ? ' · ' + r.photos.length + ' Foto(s)' : "") + '</div>'
                        + (r.message ? '<div class="m-queue-msg text-sm ' + (r.status === "pending" ? "text-muted" : "text-danger") + '">' + esc(r.message) + '</div>' : "")
                        + (versionConflict ? '<div class="text-xs text-muted">Aktuell auf dem Server: ' + esc(r.conflict.status || "") + (r.conflict.employee_name ? " · " + esc(r.conflict.employee_name) : "") + '</div>' : "")
                        + '<div class="m-queue-actions">' + actions.join("") + '</div>'
                        + '</div></div></li>';
                }).join("") + '</ul>';
        });
    }

    if (queueRoot) {
        queueRoot.addEventListener("click", function (e) {
            const btn = e.target.closest("button");
            if (!btn) { return; }
            if (btn.hasAttribute("data-queue-sync")) { btn.disabled = true; O.sync(); return; }
            const id = btn.getAttribute("data-queue-remove") || btn.getAttribute("data-queue-retry") || btn.getAttribute("data-queue-force");
            if (btn.hasAttribute("data-queue-remove")) {
                if (window.confirm("Diesen Vorgang endgültig verwerfen? Er wird nicht übertragen.")) { O.removeFromQueue(id); }
            } else if (btn.hasAttribute("data-queue-force")) {
                if (window.confirm("Das Asset wurde zwischenzeitlich auf dem Server geändert. Vorgang trotzdem mit dem aktuellen Serverstand übernehmen?")) { O.retry(id, true); }
            } else if (btn.hasAttribute("data-queue-retry")) {
                O.retry(id, false);
            }
        });
    }

    document.addEventListener("offline:queue-changed", renderQueue);
    document.addEventListener("offline:sync-done", function (e) {
        const d = e.detail || {};
        if (d.sent > 0) {
            toast(d.failed ? d.ok + " übertragen, " + d.failed + " mit Problemen – siehe ausstehende Vorgänge." : d.ok + " Vorgang" + (d.ok === 1 ? "" : "e") + " übertragen.", d.failed ? "warning" : "success");
        }
        renderQueue();
    });
    document.addEventListener("offline:sync-error", function (e) { toast((e.detail || {}).message || "Synchronisation fehlgeschlagen.", "warning"); renderQueue(); });
    window.addEventListener("online", renderQueue);
    window.addEventListener("offline", renderQueue);
    renderQueue();

    // ------------------------------------------------------------------ Offline-Assetkarte

    let permissions = {};
    let conditions = { ok: "In Ordnung", worn: "Gebrauchsspuren", damaged: "Beschädigt", defective: "Defekt" };

    function loadMeta() {
        return Promise.all([O.getMeta("permissions"), O.getMeta("conditions")]).then(function (r) {
            permissions = r[0] || {};
            if (r[1] && Object.keys(r[1]).length) { conditions = r[1]; }
        }).catch(function () { /* kein Cache */ });
    }

    function showAsset(asset) {
        if (!assetRoot) { return; }
        loadMeta().then(function () { renderAsset(asset); });
    }

    function renderAsset(asset) {
        const issued = asset.employee_id !== null || asset.status_code === "issued" || asset.status_code === "return_expected";
        const buttons = [];
        if (issued && permissions.return) {
            buttons.push('<button type="button" class="btn btn-primary btn-xl btn-block" data-offline-action="return">' + icon("return") + ' Rückgabe erfassen (offline)</button>');
        } else if (!issued && permissions.checkout) {
            buttons.push('<button type="button" class="btn btn-primary btn-xl btn-block" data-offline-action="checkout">' + icon("checkout") + ' Ausgeben (offline)</button>');
        }
        assetRoot.hidden = false;
        assetRoot.innerHTML =
            '<div class="alert alert-warning">' + icon("offline") + ' Offline – Daten aus dem lokalen Zwischenspeicher. Vorgänge werden später übertragen.</div>'
            + '<div class="card card-compact"><div class="m-asset-card"><div class="m-asset-icon">' + icon("box") + '</div><div class="m-list-main">'
            + '<p class="m-asset-title">' + esc(asset.inventory_number) + '</p>'
            + '<p class="m-asset-sub">' + esc(asset.type) + (asset.name ? " · " + esc(asset.name) : "") + (asset.manufacturer ? " (" + esc(asset.manufacturer) + ")" : "") + '</p>'
            + '<p class="mb-0 mt-2"><span class="badge badge-' + esc(asset.status_color || "neutral") + '">' + esc(asset.status_name) + '</span></p></div></div>'
            + '<dl class="m-asset-meta">'
            + (asset.serial_number ? '<dt>Seriennr.</dt><dd class="mono">' + esc(asset.serial_number) + '</dd>' : "")
            + '<dt>Mitarbeiter</dt><dd>' + (asset.employee_name ? esc(asset.employee_name) : '<span class="text-muted">–</span>') + '</dd>'
            + '<dt>Standort</dt><dd>' + (asset.location_path ? esc(asset.location_path) : '<span class="text-muted">–</span>') + '</dd>'
            + '</dl></div>'
            + '<div class="m-actions">' + buttons.join("") + '<div class="m-actions-row"><button type="button" class="btn btn-ghost" data-offline-action="close">' + icon("qr") + ' Weiter scannen</button></div></div>'
            + '<div id="offline-form"></div>';
        assetRoot.dataset.asset = JSON.stringify(asset);
        assetRoot.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function hideAsset() {
        if (!assetRoot) { return; }
        assetRoot.hidden = true;
        assetRoot.innerHTML = "";
        delete assetRoot.dataset.asset;
    }

    function notFound(code) {
        if (!assetRoot) { return; }
        O.getMeta("bootstrap_at").then(function (at) {
            assetRoot.hidden = false;
            assetRoot.innerHTML = '<div class="alert alert-error">' + icon("offline") + ' Offline: Kein Asset zu „' + esc(code) + '“ im lokalen Zwischenspeicher'
                + (at ? " (Stand " + fmtTime(new Date(at).toISOString()) + ")" : " – bitte einmal online die Scan-Seite öffnen") + '.</div>';
        });
    }

    // ------------------------------------------------------------------ Lokale Auswahl (Mitarbeiter/Standort)

    function localPicker(name, label, store, placeholder, required, preset) {
        return '<div class="form-group picker" data-local-picker="' + store + '">'
            + '<label>' + esc(label) + (required ? ' <span class="required">*</span>' : "") + '</label>'
            + '<input type="hidden" name="' + name + '" value="' + (preset ? esc(preset.id) : "") + '" data-picker-value>'
            + '<div class="picker-selected" data-picker-selected' + (preset ? "" : " hidden") + '><span class="picker-selected-text"><span class="picker-selected-label" data-picker-label>' + (preset ? esc(preset.name) : "") + '</span> <span class="text-muted text-sm" data-picker-meta>' + (preset ? esc(preset.meta || "") : "") + '</span></span>'
            + '<button type="button" class="btn btn-ghost btn-sm picker-clear" data-picker-clear aria-label="Auswahl entfernen">×</button></div>'
            + '<input type="search" class="picker-input" placeholder="' + esc(placeholder) + '" autocomplete="off" data-picker-input' + (preset ? " hidden" : "") + '>'
            + '<ul class="picker-results" role="listbox" hidden data-picker-results></ul></div>';
    }

    function initLocalPickers(root) {
        root.querySelectorAll("[data-local-picker]").forEach(function (p) {
            const store = p.getAttribute("data-local-picker");
            const value = p.querySelector("[data-picker-value]");
            const input = p.querySelector("[data-picker-input]");
            const results = p.querySelector("[data-picker-results]");
            const selected = p.querySelector("[data-picker-selected]");
            const label = p.querySelector("[data-picker-label]");
            const meta = p.querySelector("[data-picker-meta]");
            let items = [];
            function render() {
                results.innerHTML = items.length ? items.map(function (it, i) {
                    return '<li role="option"><button type="button" data-index="' + i + '"><span>' + esc(it.name) + '</span>' + (it.meta ? '<span class="result-meta">' + esc(it.meta) + '</span>' : "") + '</button></li>';
                }).join("") : '<li class="picker-empty">Keine Treffer</li>';
                results.hidden = false;
            }
            function choose(it) {
                value.value = it.id; label.textContent = it.name; meta.textContent = it.meta || "";
                selected.hidden = false; input.hidden = true; results.hidden = true; input.value = "";
            }
            input.addEventListener("input", function () {
                const term = input.value.trim();
                if (!term) { results.hidden = true; return; }
                O.searchList(store, term, 8).then(function (rows) { items = rows; render(); });
            });
            input.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); if (items[0] && !results.hidden) { choose(items[0]); } } });
            results.addEventListener("click", function (e) { const b = e.target.closest("button[data-index]"); if (b) { choose(items[Number(b.getAttribute("data-index"))]); } });
            p.querySelector("[data-picker-clear]").addEventListener("click", function () { value.value = ""; selected.hidden = true; input.hidden = false; input.focus(); });
        });
    }

    // ------------------------------------------------------------------ Offline-Formulare

    function renderForm(action, asset) {
        const box = document.getElementById("offline-form");
        if (!box) { return; }
        const today = new Date().toISOString().slice(0, 10);
        O.getAll("cost_centers").then(function (ccs) {
            let html = '<form class="m-form card card-compact" id="offline-movement-form" data-offline-type="' + action + '">';
            if (action === "checkout") {
                html += '<h2 class="text-sm text-muted mb-0">Entnahme (offline)</h2>'
                    + localPicker("employee_id", "Mitarbeiter", "employees", "Name, Abteilung …", true, null)
                    + localPicker("location_id", "Neuer Standort", "locations", "Gebäude, Raum …", false, null)
                    + '<div class="form-group"><label for="of-cc">Kostenstelle</label><select id="of-cc" name="cost_center_id"><option value="">Vom Mitarbeiter übernehmen</option>'
                    + ccs.map(function (c) { return '<option value="' + c.id + '"' + (asset.cost_center_id === c.id ? " selected" : "") + '>' + esc(c.number) + " – " + esc(c.description) + '</option>'; }).join("")
                    + '</select></div>'
                    + '<div class="form-group"><label for="of-date">Datum</label><input id="of-date" type="date" name="movement_date" value="' + today + '" max="' + today + '"></div>'
                    + '<div class="form-group"><label for="of-return">Rückgabe erwartet am</label><input id="of-return" type="date" name="expected_return_at"></div>';
            } else {
                const lastLoc = asset.location_id ? { id: asset.location_id, name: asset.location_path, meta: "" } : null;
                html += '<h2 class="text-sm text-muted mb-0">Rückgabe (offline)</h2>'
                    + '<fieldset class="form-group"><legend class="label">Zustand <span class="required">*</span></legend><div class="m-choice-grid" role="radiogroup">'
                    + Object.keys(conditions).map(function (code, i) { return '<label class="m-choice"><input type="radio" name="condition_code" value="' + code + '"' + (i === 0 ? " checked" : "") + ' data-condition><span>' + esc(conditions[code]) + '</span></label>'; }).join("")
                    + '</div></fieldset>'
                    + '<label class="checkbox-field"><input type="checkbox" name="has_damage" value="1" id="of-damage-toggle"> Schaden festgestellt</label>'
                    + '<div id="of-damage" class="m-form" hidden><div class="form-group"><label for="of-damage-text">Schadensbeschreibung <span class="required">*</span></label><textarea id="of-damage-text" name="damage_description" rows="3" maxlength="2000"></textarea></div>'
                    + '<div class="form-group m-photo-input"><label for="of-photos">Fotos vom Schaden</label><input id="of-photos" type="file" accept="image/*" capture="environment" multiple data-offline-photos><div class="m-photo-previews" data-photo-previews></div><span class="form-hint">Bis zu 4 Fotos werden lokal gespeichert und mit übertragen.</span></div></div>'
                    + '<label class="checkbox-field"><input type="checkbox" name="accessories_checked" value="1" checked> Zubehör vollständig</label>'
                    + localPicker("to_location_id", "Einlagern an", "locations", "Gebäude, Raum …", false, lastLoc)
                    + '<div class="form-group"><label for="of-date">Datum</label><input id="of-date" type="date" name="movement_date" value="' + today + '" max="' + today + '"></div>';
            }
            html += '<div class="form-group"><label for="of-note">Notiz</label><textarea id="of-note" name="note" rows="2" maxlength="2000"></textarea></div>'
                + '<div class="form-error" data-offline-error hidden></div>'
                + '<div class="m-actions"><button type="submit" class="btn btn-primary btn-xl btn-block">' + icon("check") + ' In Warteschlange speichern</button>'
                + '<button type="button" class="btn btn-ghost btn-block" data-offline-action="cancel">Abbrechen</button></div></form>';
            box.innerHTML = html;
            const form = box.querySelector("form");
            initLocalPickers(form);
            const toggle = form.querySelector("#of-damage-toggle");
            if (toggle) {
                toggle.addEventListener("change", function () { form.querySelector("#of-damage").hidden = !toggle.checked; });
                form.querySelectorAll("[data-condition]").forEach(function (r) { r.addEventListener("change", function () { if ((r.value === "damaged" || r.value === "defective") && !toggle.checked) { toggle.checked = true; toggle.dispatchEvent(new Event("change")); } }); });
            }
            const photoInput = form.querySelector("[data-offline-photos]");
            if (photoInput) {
                photoInput.addEventListener("change", function () {
                    const prev = form.querySelector("[data-photo-previews]");
                    prev.innerHTML = "";
                    Array.from(photoInput.files || []).slice(0, 4).forEach(function (f) { const img = document.createElement("img"); img.src = URL.createObjectURL(f); img.alt = f.name; prev.appendChild(img); });
                });
            }
            form.addEventListener("submit", function (e) { e.preventDefault(); submitOffline(form, action, asset); });
            form.scrollIntoView({ behavior: "smooth", block: "start" });
        });
    }

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

    function submitOffline(form, action, asset) {
        const err = form.querySelector("[data-offline-error]");
        const fd = new FormData(form);
        const payload = { asset_id: String(asset.id), inventory_number: asset.inventory_number, asset_version: String(asset.version) };
        fd.forEach(function (v, k) { if (typeof v === "string") { payload[k] = v; } });
        let label = "";
        if (action === "checkout") {
            if (!payload.employee_id) { err.textContent = "Bitte einen Mitarbeiter wählen."; err.hidden = false; return; }
            label = "an " + (form.querySelector('[data-local-picker="employees"] [data-picker-label]').textContent || "Mitarbeiter");
        } else {
            if (payload.has_damage && !String(payload.damage_description || "").trim()) { err.textContent = "Bitte den Schaden kurz beschreiben."; err.hidden = false; return; }
            label = "Zustand: " + (conditions[payload.condition_code] || payload.condition_code) + (asset.employee_name ? " · von " + asset.employee_name : "");
        }
        err.hidden = true;
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        readPhotos(form.querySelector("[data-offline-photos]")).then(function (photos) {
            return O.enqueue({ type: action, inventory_number: asset.inventory_number, label: label, payload: payload, photos: photos });
        }).then(function () {
            toast("Vorgang lokal gespeichert – wird bei Verbindung übertragen.", "success");
            hideAsset();
            if (navigator.onLine) { O.sync(); }
            window.scrollTo({ top: 0, behavior: "smooth" });
        }).catch(function () {
            btn.disabled = false;
            err.textContent = "Lokales Speichern fehlgeschlagen."; err.hidden = false;
        });
    }

    if (assetRoot) {
        assetRoot.addEventListener("click", function (e) {
            const btn = e.target.closest("[data-offline-action]");
            if (!btn) { return; }
            const action = btn.getAttribute("data-offline-action");
            const asset = assetRoot.dataset.asset ? JSON.parse(assetRoot.dataset.asset) : null;
            if (action === "close") { hideAsset(); return; }
            if (action === "cancel") { const box = document.getElementById("offline-form"); if (box) { box.innerHTML = ""; } return; }
            if (asset) { renderForm(action, asset); }
        });
    }

    /** Code offline auflösen (vom Scanner oder der Eingabe). */
    function lookupOffline(code) {
        O.findAsset(code).then(function (asset) { asset ? showAsset(asset) : notFound(code); });
    }

    document.addEventListener("scan:offline-code", function (e) { lookupOffline((e.detail || {}).code); });

    // Wurde /m/lookup?code=… ohne Serververbindung aufgerufen, liefert der Service Worker die Scan-Seite
    // als Ersatz. Erkennbar am Pfad: Die Scan-Seite wird sonst nie unter /m/lookup ausgeliefert.
    if (scanner && window.location.pathname === "/m/lookup") {
        const params = new URLSearchParams(window.location.search);
        const code = params.get("code");
        if (code) { lookupOffline(code); }
    }

    // Stammdaten aktuell halten, sobald online
    O.refreshBootstrap(false);
    window.addEventListener("online", function () { O.refreshBootstrap(false); });
    document.addEventListener("offline:bootstrap", function (e) {
        const st = document.getElementById("offline-cache-state");
        if (st) { st.textContent = (e.detail || {}).count + " Assets lokal verfügbar"; }
    });
    O.getMeta("asset_count").then(function (n) {
        const st = document.getElementById("offline-cache-state");
        if (st && n) { st.textContent = n + " Assets lokal verfügbar"; }
    });
})();
