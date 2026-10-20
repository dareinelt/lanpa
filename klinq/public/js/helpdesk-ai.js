/* Help Desk: App-eigenes Kontextmenü für Agenten-Textfelder (Rückgängig, Ausschneiden,
 * Kopieren, Einfügen, Alles auswählen) und „Mit KI verbessern“ für markierte Absätze.
 * KI-Ergebnisse werden als Textbereich gemerkt, damit sie sich verfeinern oder
 * zurücksetzen lassen. Rein datenattribut-gesteuert, ohne Inline-Skripte (CSP). */
(function () {
    "use strict";

    const UI = window.AppUI || {};
    const api = UI.api;
    const toast = UI.toast || function () {};
    const configEl = document.querySelector("[data-hd-ai-config]");
    const AI_ENABLED = !!(configEl && configEl.getAttribute("data-enabled") === "1");
    const MOD = /Mac|iPhone|iPad/.test(navigator.platform || "") ? "⌘" : "Strg+";
    const MAX_TEXT = 8000;
    const MAX_PROMPT = 1000;
    const AI_ENDPOINT = "/api/helpdesk/ai/verbessern";
    const CHIPS = [
        ["Höflicher", "Formuliere den Text höflicher und wertschätzender."],
        ["Kürzer", "Fasse den Text kürzer und prägnanter."],
        ["Korrigieren", "Korrigiere Rechtschreibung, Grammatik und Zeichensetzung."],
        ["Aufzählung", "Gliedere den Text in eine übersichtliche Aufzählung."],
        ["Englisch", "Übersetze den Text ins Englische."]
    ];

    /** Von der KI erzeugte Textbereiche je Feld: {start, end, original, current}. */
    const blocks = new WeakMap();
    const state = { menu: null, field: null, items: [], index: -1, snapshot: null };
    let dialog = null;
    let promptEl = null;
    let submitEl = null;
    let excerptEl = null;
    let titleEl = null;
    let errorEl = null;
    const ai = { field: null, block: null, range: null, text: "", busy: false };

    // ---------------------------------------------------------------- Felder und Bereiche

    function fieldOf(el) {
        return el && el.closest ? el.closest("[data-hd-field]") : null;
    }

    /** Kommentarfeld: „Interne Notiz“ und öffentliche Antwort haben unterschiedliche Tonalität. */
    function modeOf(el) {
        const mode = el.getAttribute("data-hd-field") || "generic";
        if (mode === "ticket_comment") {
            const checked = document.querySelector("input[name='type'][type='radio']:checked");
            if (checked && checked.value === "internal") {
                return "internal_note";
            }
        }
        return mode;
    }

    function canEdit(el) {
        return !!el && !el.disabled && !el.readOnly;
    }

    function hasSelection(el) {
        return typeof el.selectionStart === "number" && el.selectionStart !== el.selectionEnd;
    }

    function snapshotOf(el) {
        return { el: el, start: el.selectionStart, end: el.selectionEnd };
    }

    function restore(snap) {
        if (!snap || !snap.el.isConnected) {
            return;
        }
        snap.el.focus();
        try {
            snap.el.setSelectionRange(snap.start, snap.end);
        } catch (e) {
            /* Eingabefelder ohne Auswahlbereich ignorieren */
        }
    }

    /** Nur Bereiche behalten, deren Text noch unverändert an der gemerkten Stelle steht. */
    function validBlocks(el) {
        const list = (blocks.get(el) || []).filter(function (b) {
            return el.value.slice(b.start, b.end) === b.current;
        });
        if (list.length) {
            blocks.set(el, list);
        } else {
            blocks.delete(el);
        }
        return list;
    }

    function blockAtCaret(el) {
        const pos = typeof el.selectionStart === "number" ? el.selectionStart : 0;
        const list = validBlocks(el);
        for (let i = 0; i < list.length; i += 1) {
            if (pos >= list[i].start && pos <= list[i].end) {
                return list[i];
            }
        }
        return null;
    }

    function dropBlock(el, block) {
        blocks.set(el, validBlocks(el).filter(function (b) { return b !== block; }));
    }

    // ---------------------------------------------------------------- Textmanipulation

    function execCommand(name) {
        try {
            document.execCommand(name);
        } catch (e) {
            /* Browser ohne execCommand-Unterstützung: nichts weiter tun */
        }
    }

    function insertText(el, text) {
        el.focus();
        let done = false;
        try {
            done = document.execCommand("insertText", false, text);
        } catch (e) {
            done = false;
        }
        if (!done) {
            const start = typeof el.selectionStart === "number" ? el.selectionStart : el.value.length;
            const end = typeof el.selectionEnd === "number" ? el.selectionEnd : start;
            el.setRangeText(text, start, end, "end");
            el.dispatchEvent(new Event("input", { bubbles: true }));
        }
    }

    function replaceRange(el, start, end, text) {
        el.focus();
        try {
            el.setSelectionRange(start, end);
        } catch (e) {
            /* ignorieren */
        }
        insertText(el, text);
    }

    function copySelection() {
        restore(state.snapshot);
        execCommand("copy");
    }

    function cutSelection() {
        restore(state.snapshot);
        execCommand("cut");
    }

    function selectAll(el) {
        el.focus();
        try {
            el.setSelectionRange(0, el.value.length);
        } catch (e) {
            el.select();
        }
    }

    function pasteClipboard() {
        const snap = state.snapshot;
        restore(snap);
        if (!navigator.clipboard || typeof navigator.clipboard.readText !== "function") {
            toast("Einfügen wird vom Browser blockiert – bitte Strg+V verwenden.", "warning");
            return;
        }
        navigator.clipboard.readText().then(function (text) {
            if (!text) {
                return;
            }
            restore(snap);
            insertText(snap.el, text);
        }).catch(function () {
            toast("Zwischenablage nicht lesbar – bitte Strg+V verwenden.", "warning");
        });
    }

    // ---------------------------------------------------------------- Kontextmenü

    function menuItems(el) {
        const editable = canEdit(el);
        const items = [
            { label: "Rückgängig", key: MOD + "Z", disabled: !editable, run: function () { restore(state.snapshot); execCommand("undo"); } },
            { label: "Wiederholen", key: MOD + "Y", disabled: !editable, run: function () { restore(state.snapshot); execCommand("redo"); } },
            { separator: true },
            { label: "Ausschneiden", key: MOD + "X", disabled: !editable || !hasSelection(el), run: cutSelection },
            { label: "Kopieren", key: MOD + "C", disabled: !hasSelection(el), run: copySelection },
            { label: "Einfügen", key: MOD + "V", disabled: !editable, run: pasteClipboard },
            { separator: true },
            { label: "Alles auswählen", key: MOD + "A", run: function () { selectAll(el); } }
        ];
        if (!AI_ENABLED) {
            return items;
        }
        const block = blockAtCaret(el);
        if (block) {
            items.push({ separator: true });
            items.push({ label: "Weiter verfeinern …", ai: true, run: function () { openDialog(el, block); } });
            items.push({ label: "Auf Original zurücksetzen", run: function () { resetBlock(el, block); } });
            items.push({ label: "Markierung entfernen", run: function () { dropBlock(el, block); toast("Markierung entfernt."); } });
        } else if (editable && hasSelection(el)) {
            items.push({ separator: true });
            items.push({ label: "Mit KI verbessern …", ai: true, run: function () { openDialog(el, null); } });
        }
        return items;
    }

    /** Aufeinanderfolgende Trennzeichen und Ränder entfernen. */
    function cleanItems(items) {
        const out = [];
        items.forEach(function (item) {
            if (item.separator && (out.length === 0 || out[out.length - 1].separator)) {
                return;
            }
            out.push(item);
        });
        while (out.length && out[out.length - 1].separator) {
            out.pop();
        }
        return out;
    }

    function hideMenu() {
        if (state.menu && state.menu.parentNode) {
            state.menu.parentNode.removeChild(state.menu);
        }
        state.menu = null;
        state.items = [];
        state.index = -1;
    }

    function menuFocusables() {
        return state.menu ? Array.prototype.slice.call(state.menu.querySelectorAll("[role='menuitem']:not([aria-disabled='true'])")) : [];
    }

    function highlight(index) {
        const items = menuFocusables();
        if (!items.length) {
            return;
        }
        state.index = (index + items.length) % items.length;
        items.forEach(function (node, i) { node.classList.toggle("is-active", i === state.index); });
    }

    function showMenu(x, y, items, field) {
        hideMenu();
        state.field = field;
        state.items = items;
        const menu = document.createElement("div");
        menu.className = "hd-ctx-menu";
        menu.setAttribute("role", "menu");
        menu.setAttribute("aria-label", "Textbearbeitung");
        items.forEach(function (item) {
            if (item.separator) {
                const sep = document.createElement("div");
                sep.className = "hd-ctx-sep";
                sep.setAttribute("role", "separator");
                menu.appendChild(sep);
                return;
            }
            const button = document.createElement("button");
            button.type = "button";
            button.className = "hd-ctx-item" + (item.ai ? " is-ai" : "");
            button.setAttribute("role", "menuitem");
            button.setAttribute("aria-disabled", item.disabled ? "true" : "false");
            button.disabled = !!item.disabled;
            const label = document.createElement("span");
            label.className = "hd-ctx-label";
            label.textContent = item.label;
            button.appendChild(label);
            if (item.key) {
                const key = document.createElement("span");
                key.className = "hd-ctx-key";
                key.textContent = item.key;
                button.appendChild(key);
            }
            button.addEventListener("click", function () {
                hideMenu();
                if (!item.disabled) {
                    item.run();
                }
            });
            menu.appendChild(button);
        });
        menu.addEventListener("mousedown", function (event) { event.preventDefault(); });
        menu.addEventListener("keydown", onMenuKeydown);
        const host = field && field.closest("dialog[open]") ? field.closest("dialog[open]") : document.body;
        menu.style.visibility = "hidden";
        host.appendChild(menu);
        state.menu = menu;
        const rect = menu.getBoundingClientRect();
        const left = Math.max(8, Math.min(x, window.innerWidth - rect.width - 8));
        const top = Math.max(8, Math.min(y, window.innerHeight - rect.height - 8));
        menu.style.left = left + "px";
        menu.style.top = top + "px";
        menu.style.visibility = "visible";
        const first = menuFocusables()[0];
        if (first) {
            first.focus();
            highlight(0);
        }
    }

    function onMenuKeydown(event) {
        if (event.key === "Escape") {
            event.preventDefault();
            const field = state.field;
            hideMenu();
            if (field) {
                field.focus();
            }
            return;
        }
        if (event.key === "ArrowDown") {
            event.preventDefault();
            highlight(state.index + 1);
            return;
        }
        if (event.key === "ArrowUp") {
            event.preventDefault();
            highlight(state.index - 1);
            return;
        }
        if (event.key === "Home") {
            event.preventDefault();
            highlight(0);
            return;
        }
        if (event.key === "End") {
            event.preventDefault();
            highlight(menuFocusables().length - 1);
            return;
        }
        if (event.key === "Enter" || event.key === " ") {
            const items = menuFocusables();
            if (items.length && state.index >= 0) {
                event.preventDefault();
                items[state.index].click();
            }
        }
    }

    document.addEventListener("contextmenu", function (event) {
        const field = fieldOf(event.target);
        if (!field) {
            return;
        }
        state.snapshot = snapshotOf(field);
        const items = cleanItems(menuItems(field));
        if (!items.length) {
            return;
        }
        event.preventDefault();
        showMenu(event.clientX, event.clientY, items, field);
    });

    document.addEventListener("mousedown", function (event) {
        if (state.menu && !state.menu.contains(event.target)) {
            hideMenu();
        }
    }, true);

    document.addEventListener("keydown", function (event) {
        if (state.menu && event.key === "Escape") {
            event.preventDefault();
            hideMenu();
        }
    }, true);

    window.addEventListener("resize", hideMenu);
    window.addEventListener("blur", hideMenu);
    document.addEventListener("scroll", hideMenu, true);

    // ---------------------------------------------------------------- KI-Dialog

    function ensureDialog() {
        if (dialog) {
            return dialog;
        }
        dialog = document.createElement("dialog");
        dialog.id = "hd-ai-dialog";
        dialog.className = "dialog hd-ai-dialog";
        dialog.innerHTML = [
            '<div class="dialog-header"><h2><span data-hd-ai-title>Mit KI verbessern</span></h2>',
            '<button type="button" class="btn btn-ghost btn-sm" data-hd-ai-close aria-label="Schließen">&times;</button></div>',
            '<div class="dialog-body">',
            '<p class="hd-ai-excerpt" data-hd-ai-excerpt></p>',
            '<div class="form-group"><label for="hd-ai-prompt">Anweisung</label>',
            '<textarea id="hd-ai-prompt" rows="3" maxlength="1000" placeholder="z. B. höflicher formulieren, kürzer fassen, Rechtschreibung korrigieren"></textarea></div>',
            '<div class="hd-ai-chips" data-hd-ai-chips></div>',
            '<p class="hd-ai-note text-xs text-muted">Der markierte Text wird an die in „Auswertung &amp; System“ hinterlegte KI gesendet. Bitte keine vertraulichen Daten einfügen.</p>',
            '<p class="alert alert-danger" data-hd-ai-error hidden></p>',
            '</div>',
            '<div class="dialog-footer"><button type="button" class="btn btn-secondary" data-hd-ai-close>Abbrechen</button>',
            '<button type="button" class="btn btn-primary" data-hd-ai-submit>Vorschlag erzeugen</button></div>'
        ].join("");
        document.body.appendChild(dialog);

        promptEl = dialog.querySelector("#hd-ai-prompt");
        submitEl = dialog.querySelector("[data-hd-ai-submit]");
        excerptEl = dialog.querySelector("[data-hd-ai-excerpt]");
        titleEl = dialog.querySelector("[data-hd-ai-title]");
        errorEl = dialog.querySelector("[data-hd-ai-error]");

        const chips = dialog.querySelector("[data-hd-ai-chips]");
        CHIPS.forEach(function (chip) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "btn btn-ghost btn-sm";
            button.textContent = chip[0];
            button.addEventListener("click", function () {
                const current = promptEl.value.trim();
                promptEl.value = current ? current + " " + chip[1] : chip[1];
                promptEl.focus();
                promptEl.setSelectionRange(promptEl.value.length, promptEl.value.length);
            });
            chips.appendChild(button);
        });

        dialog.querySelectorAll("[data-hd-ai-close]").forEach(function (button) {
            button.addEventListener("click", closeDialog);
        });
        submitEl.addEventListener("click", submit);
        dialog.addEventListener("close", resetDialog);
        dialog.addEventListener("cancel", function () { resetDialog(); });
        return dialog;
    }

    function resetDialog() {
        ai.field = null;
        ai.block = null;
        ai.range = null;
        ai.text = "";
        ai.busy = false;
        if (promptEl) {
            promptEl.value = "";
        }
        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = "";
        }
    }

    function closeDialog() {
        if (dialog && dialog.open) {
            dialog.close();
        } else {
            resetDialog();
        }
    }

    function showError(message) {
        if (!errorEl) {
            toast(message, "error");
            return;
        }
        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    function excerpt(text) {
        const flat = String(text).replace(/\s+/g, " ").trim();
        return flat.length > 180 ? flat.slice(0, 177) + "…" : flat;
    }

    function openDialog(field, block) {
        ensureDialog();
        resetDialog();
        ai.field = field;
        ai.block = block;
        if (block) {
            ai.text = block.original;
            ai.range = { start: block.start, end: block.end };
            titleEl.textContent = "Weiter verfeinern";
        } else {
            ai.text = field.value.slice(field.selectionStart, field.selectionEnd).trim();
            ai.range = { start: field.selectionStart, end: field.selectionEnd };
            titleEl.textContent = "Mit KI verbessern";
        }
        excerptEl.textContent = "„" + excerpt(ai.text) + "“";
        if (typeof dialog.showModal === "function") {
            dialog.showModal();
        } else {
            dialog.setAttribute("open", "");
        }
        promptEl.focus();
    }

    function messageOf(error) {
        const data = error && error.data;
        if (data) {
            if (data.message) {
                return String(data.message);
            }
            if (data.error) {
                return String(data.error);
            }
            if (data.errors) {
                const keys = Object.keys(data.errors);
                if (keys.length && data.errors[keys[0]]) {
                    const first = data.errors[keys[0]];
                    return String(Array.isArray(first) ? first[0] : first);
                }
            }
        }
        return error && error.message ? String(error.message) : "Die KI ist derzeit nicht erreichbar.";
    }

    function submit() {
        if (ai.busy || !ai.field || !ai.text) {
            return;
        }
        const prompt = promptEl.value.trim();
        if (!prompt) {
            showError("Bitte eine Anweisung eingeben.");
            promptEl.focus();
            return;
        }
        if (ai.text.length > MAX_TEXT) {
            showError("Der markierte Text ist zu lang (maximal " + MAX_TEXT + " Zeichen).");
            return;
        }
        if (prompt.length > MAX_PROMPT) {
            showError("Die Anweisung ist zu lang (maximal " + MAX_PROMPT + " Zeichen).");
            return;
        }
        const payload = {
            text: ai.text,
            prompt: prompt,
            mode: modeOf(ai.field)
        };
        if (ai.block) {
            payload.previous = ai.block.current;
        }
        const subjectField = document.querySelector("#f-subject");
        if (subjectField && subjectField.value) {
            payload.subject = subjectField.value;
        }
        ai.busy = true;
        submitEl.disabled = true;
        submitEl.textContent = "Wird erzeugt …";
        errorEl.hidden = true;
        if (!api) {
            showError("Die KI ist derzeit nicht verfügbar.");
            finish();
            return;
        }
        api(AI_ENDPOINT, { method: "POST", body: payload }).then(function (data) {
            const text = data && typeof data.text === "string" ? data.text : "";
            if (!text) {
                throw new Error("Die KI hat eine ungültige Antwort geliefert.");
            }
            // Erst schließen: ein modaler Dialog macht das Zielfeld inert, dann greift kein Einfügen
            const target = { field: ai.field, range: ai.range, block: ai.block, source: ai.text };
            closeDialog();
            applyResult(text, target);
            toast("Vorschlag übernommen.", "success");
        }).catch(function (error) {
            showError(messageOf(error));
        }).then(finish, finish);
    }

    function finish() {
        ai.busy = false;
        if (submitEl) {
            submitEl.disabled = false;
            submitEl.textContent = "Vorschlag erzeugen";
        }
    }

    function applyResult(text, target) {
        const field = (target && target.field) || ai.field;
        const range = (target && target.range) || ai.range;
        const block = (target && target.block) || ai.block;
        if (!field || !range) {
            return;
        }
        if (block) {
            dropBlock(field, block);
        }
        replaceRange(field, range.start, range.end, text);
        const list = validBlocks(field);
        list.push({
            start: range.start,
            end: range.start + text.length,
            original: (target && target.source) || ai.text,
            current: text
        });
        list.sort(function (a, b) { return a.start - b.start; });
        blocks.set(field, list);
        try {
            field.setSelectionRange(range.start, range.start + text.length);
        } catch (e) {
            /* ignorieren */
        }
    }

    function resetBlock(field, block) {
        dropBlock(field, block);
        replaceRange(field, block.start, block.end, block.original);
        toast("Originaltext wiederhergestellt.", "success");
    }

    // Bereiche nach jeder Eingabe prüfen, damit veraltete Markierungen verschwinden
    document.addEventListener("input", function (event) {
        const field = fieldOf(event.target);
        if (field && blocks.has(field)) {
            validBlocks(field);
        }
    });
})();
