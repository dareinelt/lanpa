/* Baukasten für die Vorlage des Übergabeprotokolls: Blöcke anlegen, sortieren, bearbeiten, Live-Vorschau. */
(function () {
    "use strict";

    var form = document.getElementById("hb-form");
    if (!form) {
        return;
    }
    var hidden = document.getElementById("hb-blocks");
    var list = document.getElementById("hb-blocks-list");
    var tpl = document.getElementById("hb-block-template");
    var preview = document.getElementById("hb-preview");
    var previewState = document.getElementById("hb-preview-state");
    var options = JSON.parse(form.getAttribute("data-options") || "{}");
    var previewUrl = form.getAttribute("data-preview-url");
    var initial = safeParse(hidden.value, []);
    var blocks = JSON.parse(JSON.stringify(initial));
    var previewTimer = null;

    var DEFAULTS = {
        heading: { text: "Neue Überschrift", level: 2 },
        text: { text: "", style: "normal" },
        meta: { fields: Object.keys(options.metaFields || {}) },
        employee: { title: "Mitarbeiter", fields: ["display_name", "personnel_number", "department", "position", "email"] },
        assets: { title: "Arbeitsmittel", columns: ["inventory_number", "article", "serial_number", "assigned_at"], empty_text: "Keine protokollrelevanten Arbeitsmittel zugeordnet." },
        confirmation: { text: "Ich bestätige den Erhalt der aufgeführten Arbeitsmittel.", required: true },
        signature: { party: "employee", label: "Unterschrift Mitarbeiter" },
        divider: {},
        spacer: { height: 16 }
    };

    function safeParse(json, fallback) {
        try {
            var v = JSON.parse(json);
            return Array.isArray(v) ? v : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === "text") { node.textContent = attrs[k]; }
            else if (k === "html") { node.innerHTML = attrs[k]; }
            else if (k === "checked" || k === "selected") { node[k] = !!attrs[k]; }
            else { node.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { node.appendChild(c); } });
        return node;
    }

    function field(label, input, hint) {
        var wrap = el("div", { "class": "form-group" });
        var l = el("label", { text: label });
        wrap.appendChild(l);
        wrap.appendChild(input);
        if (hint) { wrap.appendChild(el("span", { "class": "form-hint", text: hint })); }
        return wrap;
    }

    function textInput(block, key, attrs) {
        var input = el("input", Object.assign({ type: "text", value: block[key] || "" }, attrs || {}));
        input.addEventListener("input", function () { block[key] = input.value; sync(); });
        return input;
    }

    function textArea(block, key, rows) {
        var input = el("textarea", { rows: rows || 3 });
        input.value = block[key] || "";
        input.addEventListener("input", function () { block[key] = input.value; sync(); });
        return input;
    }

    function select(block, key, choices, cast) {
        var input = el("select");
        Object.keys(choices).forEach(function (val) {
            input.appendChild(el("option", { value: val, text: choices[val], selected: String(block[key]) === val }));
        });
        input.addEventListener("change", function () { block[key] = cast ? cast(input.value) : input.value; sync(); });
        return input;
    }

    function checkList(block, key, choices) {
        var box = el("div", { "class": "hb-checks" });
        var current = Array.isArray(block[key]) ? block[key] : [];
        Object.keys(choices).forEach(function (val) {
            var cb = el("input", { type: "checkbox", value: val, checked: current.indexOf(val) !== -1 });
            cb.addEventListener("change", function () {
                var next = Object.keys(choices).filter(function (v) {
                    return box.querySelector('input[value="' + v + '"]').checked;
                });
                block[key] = next;
                sync();
            });
            box.appendChild(el("label", { "class": "checkbox-field" }, [cb, el("span", { text: choices[val] })]));
        });
        return box;
    }

    function checkbox(block, key, label) {
        var cb = el("input", { type: "checkbox", checked: !!block[key] });
        cb.addEventListener("change", function () { block[key] = cb.checked; sync(); });
        return el("label", { "class": "checkbox-field" }, [cb, el("span", { text: label })]);
    }

    function renderBody(block, body) {
        body.innerHTML = "";
        switch (block.type) {
            case "heading":
                body.appendChild(field("Text", textInput(block, "text", { maxlength: 200 }), "Platzhalter wie {firma} oder {mitarbeiter} sind möglich."));
                body.appendChild(field("Größe", select(block, "level", { "1": "Titel (groß)", "2": "Abschnitt", "3": "Unterabschnitt" }, Number)));
                break;
            case "text":
                body.appendChild(field("Text", textArea(block, "text", 4), "Zeilenumbrüche bleiben erhalten. Platzhalter siehe unten."));
                body.appendChild(field("Stil", select(block, "style", { normal: "Normal", small: "Klein", bold: "Fett", muted: "Gedämpft" })));
                break;
            case "meta":
                body.appendChild(field("Angezeigte Felder", checkList(block, "fields", options.metaFields || {})));
                break;
            case "employee":
                body.appendChild(field("Überschrift", textInput(block, "title", { maxlength: 120 })));
                body.appendChild(field("Felder", checkList(block, "fields", options.employeeFields || {})));
                break;
            case "assets":
                body.appendChild(field("Überschrift", textInput(block, "title", { maxlength: 120 })));
                body.appendChild(field("Spalten", checkList(block, "columns", options.assetColumns || {})));
                body.appendChild(field("Text, wenn keine Arbeitsmittel", textInput(block, "empty_text", { maxlength: 300 })));
                break;
            case "confirmation":
                body.appendChild(field("Bestätigungstext", textArea(block, "text", 3)));
                body.appendChild(checkbox(block, "required", "Pflicht – muss vor der Unterschrift angekreuzt werden"));
                break;
            case "signature":
                body.appendChild(field("Wer unterschreibt", select(block, "party", { employee: "Mitarbeiter (digital auf dem Mobilgerät)", issuer: "Aussteller (Name des angemeldeten Benutzers)" })));
                body.appendChild(field("Beschriftung", textInput(block, "label", { maxlength: 120 })));
                break;
            case "spacer":
                body.appendChild(field("Höhe", select(block, "height", { "8": "Klein (8 px)", "16": "Normal (16 px)", "24": "Mittel (24 px)", "32": "Groß (32 px)", "48": "Sehr groß (48 px)" }, Number)));
                break;
            default:
                body.appendChild(el("p", { "class": "text-muted text-sm mb-0", text: "Keine Einstellungen." }));
        }
    }

    function render() {
        list.innerHTML = "";
        blocks.forEach(function (block, index) {
            var node = tpl.content.firstElementChild.cloneNode(true);
            node.setAttribute("data-index", String(index));
            node.querySelector(".hb-block-title").textContent = (options.types && options.types[block.type]) || block.type;
            node.querySelector('[data-action="up"]').disabled = index === 0;
            node.querySelector('[data-action="down"]').disabled = index === blocks.length - 1;
            renderBody(block, node.querySelector(".hb-block-body"));
            list.appendChild(node);
        });
        if (blocks.length === 0) {
            list.appendChild(el("li", { "class": "card text-muted", text: "Noch keine Blöcke. Oben einen Block hinzufügen." }));
        }
        sync();
    }

    function sync() {
        hidden.value = JSON.stringify(blocks);
        schedulePreview();
    }

    function schedulePreview() {
        if (!previewUrl || !preview) { return; }
        if (previewState) { previewState.textContent = "wird aktualisiert …"; }
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(refreshPreview, 400);
    }

    function refreshPreview() {
        var body = new URLSearchParams();
        body.set("blocks", hidden.value);
        window.AppUI.api(previewUrl, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body.toString()
        }).then(function (data) {
            preview.innerHTML = data.html;
            if (previewState) { previewState.textContent = "aktuell"; }
        }).catch(function (error) {
            if (previewState) { previewState.textContent = error.message || "Vorschau fehlgeschlagen"; }
        });
    }

    list.addEventListener("click", function (event) {
        var button = event.target.closest("button[data-action]");
        if (!button) { return; }
        var item = button.closest(".hb-block");
        var index = parseInt(item.getAttribute("data-index"), 10);
        var action = button.getAttribute("data-action");
        if (action === "remove") {
            blocks.splice(index, 1);
        } else if (action === "up" && index > 0) {
            blocks.splice(index - 1, 0, blocks.splice(index, 1)[0]);
        } else if (action === "down" && index < blocks.length - 1) {
            blocks.splice(index + 1, 0, blocks.splice(index, 1)[0]);
        } else if (action === "duplicate") {
            blocks.splice(index + 1, 0, JSON.parse(JSON.stringify(blocks[index])));
        }
        render();
    });

    document.getElementById("hb-add").addEventListener("click", function () {
        var type = document.getElementById("hb-add-type").value;
        var block = Object.assign({ type: type }, JSON.parse(JSON.stringify(DEFAULTS[type] || {})));
        blocks.push(block);
        render();
        var last = list.lastElementChild;
        if (last) { last.scrollIntoView({ behavior: "smooth", block: "center" }); }
    });

    document.getElementById("hb-reset").addEventListener("click", function () {
        if (!window.confirm("Alle nicht gespeicherten Änderungen verwerfen?")) { return; }
        blocks = JSON.parse(JSON.stringify(initial));
        render();
    });

    form.addEventListener("submit", function (event) {
        var hasSignature = blocks.some(function (b) { return b.type === "signature" && (b.party || "employee") === "employee"; });
        if (!hasSignature) {
            event.preventDefault();
            window.AppUI.toast("Die Vorlage benötigt ein Unterschriftsfeld für den Mitarbeiter.", "error");
        }
    });

    render();
})();
