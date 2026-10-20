/* Etiketten: Druck auslösen + protokollieren, Live-Vorschau in den Einstellungen. */
(function () {
    "use strict";

    // Inventarnummer darf nie abgeschnitten und der Firmenname nicht mitten im Wort getrennt werden:
    // Schrift so weit verkleinern, dass der Inhalt (bzw. das längste Wort) in die Textspalte passt.
    // (CSSOM-Zuweisungen sind trotz CSP ohne 'unsafe-inline' erlaubt – nur style-Attribute im HTML nicht.)
    const fitLabelText = function (root) {
        root.querySelectorAll(".label-inventory, .label-company").forEach(function (el) {
            el.style.fontSize = "";
            el.style.overflowWrap = "";
            const container = el.parentElement;
            if (!container) { return; }
            const minSize = el.classList.contains("label-inventory") ? 5 : 6.5; // Untergrenze in px (≈ 4–5 pt)
            let size = parseFloat(getComputedStyle(el).fontSize);
            let guard = 0;
            while (el.scrollWidth > container.clientWidth + 0.5 && size - 0.5 >= minSize && guard++ < 30) {
                size -= 0.5;
                el.style.fontSize = size + "px";
            }
            if (el.scrollWidth > container.clientWidth + 0.5) {
                el.style.overflowWrap = "anywhere"; // Notfall: überlanges Einzelwort trennen statt abschneiden
            }
        });
    };
    fitLabelText(document);
    window.addEventListener("load", function () { fitLabelText(document); });
    window.addEventListener("beforeprint", function () { fitLabelText(document); });

    // ---- Druckansicht -------------------------------------------------------
    const printButton = document.getElementById("print-button");
    const sheet = document.getElementById("label-sheet");
    if (printButton && sheet) {
        document.body.classList.add("is-label-print");
        let recorded = false;
        const record = function () {
            if (recorded) { return; }
            recorded = true;
            const ids = (sheet.getAttribute("data-ids") || "").split(",").filter(Boolean).map(Number);
            if (ids.length === 0) { return; } // Testdruck mit Dummy-Etikett: nichts zu protokollieren
            AppUI.api("/api/labels/printed", { method: "POST", body: { ids: ids } })
                .then(function (res) { if (res && res.ok) { AppUI.toast("Druck protokolliert (" + res.count + " Asset" + (res.count === 1 ? "" : "s") + ").", "success"); } })
                .catch(function () { AppUI.toast("Druck konnte nicht protokolliert werden.", "error"); });
        };
        printButton.addEventListener("click", function () {
            window.print();
        });
        window.addEventListener("afterprint", record);
        // Fallback für Browser ohne afterprint (z. B. ältere iOS-Versionen)
        if (window.matchMedia) {
            const mq = window.matchMedia("print");
            let wasPrinting = false;
            mq.addEventListener("change", function (e) {
                if (e.matches) { wasPrinting = true; } else if (wasPrinting) { record(); }
            });
        }
        // Tastenkürzel Strg/Cmd+P löst den normalen Browserdruck aus → afterprint greift ebenfalls

        // Testdruck (Administration → Etikettenlayout): Druckdialog sofort nach dem Laden öffnen
        if (sheet.getAttribute("data-autoprint") === "1") {
            window.addEventListener("load", function () { window.print(); });
        }
    }

    // ---- Einstellungen: Live-Vorschau --------------------------------------
    const form = document.getElementById("label-settings-form");
    const preview = document.getElementById("label-preview");
    const styleLink = document.getElementById("style-labels-style-css");
    if (form && preview && styleLink) {
        const dims = document.getElementById("preview-dimensions");
        const collect = function () {
            const data = {};
            new FormData(form).forEach(function (value, key) {
                if (key === "_csrf" || key === "logo" || key === "remove_logo") { return; }
                data[key] = typeof value === "string" ? value : "";
            });
            if (!form.querySelector("[name=show_logo]").checked) { data.show_logo = "0"; }
            return data;
        };
        const refresh = AppUI.debounce(function () {
            const data = collect();
            const params = new URLSearchParams(data);
            // Stylesheet neu laden (Maße/Schriften), dann HTML der Vorschau aktualisieren
            const fresh = styleLink.cloneNode();
            fresh.href = "/labels/style.css?" + params.toString();
            fresh.addEventListener("load", function () { styleLink.remove(); fresh.id = "style-labels-style-css"; fitLabelText(preview); });
            styleLink.after(fresh);
            if (dims) { dims.textContent = (data.width_mm || "?") + " × " + (data.height_mm || "?") + " mm"; }
            AppUI.api("/api/labels/preview", { method: "POST", body: data }).then(function (res) {
                if (!res || !res.label) { return; }
                const l = res.label;
                const layout = res.layout;
                const logo = l.logo_url ? '<img class="label-logo" src="' + AppUI.escapeHtml(l.logo_url) + '" alt="">' : "";
                const extra = l.extra !== null ? '<div class="label-extra">' + AppUI.escapeHtml(l.extra) + "</div>" : "";
                preview.innerHTML = '<div class="label label-pos-' + AppUI.escapeHtml(layout.qr_position) + '">'
                    + '<div class="label-qr">' + l.qr_svg + "</div>"
                    + '<div class="label-text">' + logo
                    + '<div class="label-company">' + AppUI.escapeHtml(l.company) + "</div>"
                    + '<div class="label-inventory">' + AppUI.escapeHtml(l.inventory_number) + "</div>"
                    + extra + "</div></div>";
                fitLabelText(preview);
            }).catch(function () { /* Vorschau ist optional */ });
        }, 250);
        form.addEventListener("input", refresh);
        form.addEventListener("change", refresh);
    }
})();
