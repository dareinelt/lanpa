/* Help Desk: E-Mail-Dialog eines Tickets. Der Editor arbeitet wie in Orvanta mit
 * einem formatierbaren Bereich; Kontextmenü und „Mit KI verbessern“ werden
 * übernommen. Die Signatur stammt unveränderlich aus dem Intranet und wird nur
 * angezeigt – angehängt wird sie beim Versand auf dem Server. Rein
 * datenattribut-gesteuert, ohne Inline-Skripte (CSP). */
(function () {
    "use strict";

    const UI = window.AppUI || {};
    const api = UI.api;
    const toast = UI.toast || function () {};
    const root = document.querySelector("[data-hd-mail]");
    if (!root || typeof api !== "function") {
        return;
    }

    const form = root.querySelector("[data-hd-mail-form]");
    const editor = root.querySelector("[data-hd-mail-editor]");
    const bodyField = root.querySelector("[data-hd-mail-body]");
    const signatureBody = root.querySelector("[data-hd-mail-signature-body]");
    const subjectField = root.querySelector("[data-hd-mail-subject]");
    const pauseCode = root.querySelector("[data-hd-mail-pause-code]");
    const pauseUntil = root.querySelector("[data-hd-mail-pause-until]");
    const pauseGroup = root.querySelector("[data-hd-mail-pause-until]")
        ? root.querySelector("[data-hd-mail-pause-until]").closest(".form-group")
        : null;
    const errorEl = root.querySelector("[data-hd-mail-error]");
    const submitEl = root.querySelector("[data-hd-mail-submit]");

    const AI_ENABLED = root.getAttribute("data-ai-enabled") === "1";
    const AI_ENDPOINT = "/api/helpdesk/ai/verbessern";
    const TICKET = root.getAttribute("data-ticket") || "";
    const MAX_TEXT = 8000;
    const MAX_PROMPT = 1000;
    const MOD = /Mac|iPhone|iPad/.test(navigator.platform || "") ? "⌘" : "Strg+";
    const CHIPS = [
        ["Höflicher", "Formuliere den Text höflicher und wertschätzender."],
        ["Kürzer", "Fasse den Text kürzer und prägnanter."],
        ["Korrigieren", "Korrigiere Rechtschreibung, Grammatik und Zeichensetzung."],
        ["Aufzählung", "Gliedere den Text in eine übersichtliche Aufzählung."],
        ["Englisch", "Übersetze den Text ins Englische."]
    ];

    const state = { menu: null, items: [], index: -1, range: null };
    const ai = { range: null, text: "", previous: null, dialog: null, busy: false };

    // ---------------------------------------------------------------- Inline-Styles

    /**
     * Inline-Styles aus (serverseitig bereinigtem) HTML vor dem Einsetzen in ein
     * Datenattribut verschieben: Die CSP erlaubt keine style-Attribute, Zuweisungen
     * ueber das CSSOM (element.style) aber schon. Wie im Orvanta-Mail-Editor.
     */
    function inlineStylesToCssom(html) {
        return String(html).replace(/\sstyle=("[^"]*"|'[^']*')/gi, " data-hd-style=$1");
    }

    /** Vorbereitete Inline-Styles im Container per CSSOM anwenden. */
    function applyInlineStyles(container) {
        if (!container) {
            return;
        }
        container.querySelectorAll("[data-hd-style]").forEach(function (node) {
            const css = node.getAttribute("data-hd-style");
            node.removeAttribute("data-hd-style");
            if (css) {
                node.style.cssText = css;
            }
        });
        container.querySelectorAll("[style]").forEach(function (node) {
            const css = node.getAttribute("style");
            node.removeAttribute("style");
            if (css) {
                node.style.cssText = css;
            }
        });
    }

    // ---------------------------------------------------------------- Signatur

    let signaturePromise = null;

    /** Feste Signatur des angemeldeten Agenten einmalig nachladen. */
    function loadSignature() {
        if (signaturePromise) {
            return signaturePromise;
        }
        signaturePromise = api(root.getAttribute("data-signature-url")).then(function (data) {
            if (!signatureBody) {
                return data;
            }
            if (data && data.html) {
                signatureBody.innerHTML = inlineStylesToCssom(data.html);
                applyInlineStyles(signatureBody);
            } else {
                signatureBody.textContent = "Für Ihr Konto ist keine Signatur hinterlegt.";
                signatureBody.className += " text-muted text-sm";
            }
            return data;
        }).catch(function () {
            signaturePromise = null;
            if (signatureBody) {
                signatureBody.textContent = "Die Signatur konnte nicht geladen werden. Sie wird beim Versand dennoch angehängt.";
            }
        });
        return signaturePromise;
    }

    root.addEventListener("toggle", function () {
        if (!root.open) {
            hideMenu();
            return;
        }
        loadSignature();
        if (editor) {
            editor.focus();
        }
    });

    // ---------------------------------------------------------------- Auswahl im Editor

    function selectionRange() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || !editor) {
            return null;
        }
        const range = selection.getRangeAt(0);
        if (!editor.contains(range.commonAncestorContainer)) {
            return null;
        }
        return range;
    }

    function selectedText() {
        const range = selectionRange();
        return range ? range.toString() : "";
    }

    function restoreRange(range) {
        if (!range || !editor) {
            return;
        }
        editor.focus();
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function replaceRange(range, text) {
        if (!range) {
            return;
        }
        restoreRange(range);
        let done = false;
        try {
            done = document.execCommand("insertText", false, text);
        } catch (e) {
            done = false;
        }
        if (!done) {
            range.deleteContents();
            range.insertNode(document.createTextNode(text));
        }
        editor.dispatchEvent(new Event("input", { bubbles: true }));
    }

    function execCommand(name) {
        try {
            document.execCommand(name);
        } catch (e) {
            /* Browser ohne execCommand-Unterstützung: nichts weiter tun */
        }
    }

    function selectAll() {
        if (!editor) {
            return;
        }
        editor.focus();
        const range = document.createRange();
        range.selectNodeContents(editor);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function pasteClipboard() {
        restoreRange(state.range);
        if (!navigator.clipboard || typeof navigator.clipboard.readText !== "function") {
            toast("Einfügen wird vom Browser blockiert – bitte Strg+V verwenden.", "warning");
            return;
        }
        navigator.clipboard.readText().then(function (text) {
            if (text) {
                replaceRange(state.range, text);
            }
        }).catch(function () {
            toast("Zwischenablage nicht lesbar – bitte Strg+V verwenden.", "warning");
        });
    }

    // ---------------------------------------------------------------- Kontextmenü

    function menuItems() {
        const range = selectionRange();
        const hasSelection = !!(range && range.toString().length > 0);
        const items = [
            { label: "Rückgängig", key: MOD + "Z", run: function () { restoreRange(state.range); execCommand("undo"); } },
            { label: "Wiederholen", key: MOD + "Y", run: function () { restoreRange(state.range); execCommand("redo"); } },
            { separator: true },
            { label: "Ausschneiden", key: MOD + "X", disabled: !hasSelection, run: function () { restoreRange(state.range); execCommand("cut"); } },
            { label: "Kopieren", key: MOD + "C", disabled: !hasSelection, run: function () { restoreRange(state.range); execCommand("copy"); } },
            { label: "Einfügen", key: MOD + "V", run: pasteClipboard },
            { separator: true },
            { label: "Alles auswählen", key: MOD + "A", run: selectAll }
        ];
        if (!AI_ENABLED) {
            return items;
        }
        if (hasSelection) {
            items.push({ separator: true });
            items.push({ label: "Mit KI verbessern …", ai: true, run: function () { openAi(range); } });
        } else if (ai.text !== "") {
            items.push({ separator: true });
            items.push({ label: "KI-Vorschlag weiter verfeinern …", ai: true, run: function () { openAi(ai.range); } });
        }
        return items;
    }

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

    function showMenu(x, y, items) {
        hideMenu();
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
        menu.style.visibility = "hidden";
        root.appendChild(menu);
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
            hideMenu();
            if (editor) {
                editor.focus();
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
        if (event.key === "Enter" || event.key === " ") {
            const items = menuFocusables();
            if (items.length && state.index >= 0) {
                event.preventDefault();
                items[state.index].click();
            }
        }
    }

    if (editor) {
        editor.addEventListener("contextmenu", function (event) {
            const items = cleanItems(menuItems());
            if (!items.length) {
                return;
            }
            const range = selectionRange();
            state.range = range ? range.cloneRange() : null;
            event.preventDefault();
            showMenu(event.clientX, event.clientY, items);
        });

        // Eingefuegtes HTML behaelt seine Formatierung: Die dabei gesetzten
        // style-Attribute werden nachtraeglich ueber das CSSOM uebernommen.
        editor.addEventListener("paste", function () {
            window.setTimeout(function () { applyInlineStyles(editor); }, 0);
        });
    }

    document.addEventListener("mousedown", function (event) {
        if (state.menu && !state.menu.contains(event.target)) {
            hideMenu();
        }
    }, true);

    // ---------------------------------------------------------------- KI-Dialog

    function ensureAiDialog() {
        if (ai.dialog) {
            return ai.dialog;
        }
        const dialog = document.createElement("dialog");
        dialog.className = "dialog hd-ai-dialog";
        dialog.innerHTML = [
            '<div class="dialog-header"><h2>Mit KI verbessern</h2>',
            '<button type="button" class="btn btn-ghost btn-sm" data-hd-mail-ai-close aria-label="Schließen">&times;</button></div>',
            '<div class="dialog-body">',
            '<p class="hd-ai-excerpt" data-hd-mail-ai-excerpt></p>',
            '<div class="form-group"><label for="hd-mail-ai-prompt">Anweisung</label>',
            '<textarea id="hd-mail-ai-prompt" rows="3" maxlength="' + MAX_PROMPT + '" placeholder="z. B. höflicher formulieren, kürzer fassen, Rechtschreibung korrigieren"></textarea></div>',
            '<div class="hd-ai-chips" data-hd-mail-ai-chips></div>',
            '<p class="hd-ai-note text-xs text-muted">Der markierte Text wird an die in den KLINQ-Einstellungen hinterlegte KI gesendet. Bitte keine vertraulichen Daten einfügen.</p>',
            '<p class="alert alert-danger" data-hd-mail-ai-error hidden></p>',
            '</div>',
            '<div class="dialog-footer"><button type="button" class="btn btn-secondary" data-hd-mail-ai-close>Abbrechen</button>',
            '<button type="button" class="btn btn-primary" data-hd-mail-ai-submit>Vorschlag erzeugen</button></div>'
        ].join("");
        root.appendChild(dialog);

        const chips = dialog.querySelector("[data-hd-mail-ai-chips]");
        CHIPS.forEach(function (chip) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "chip";
            button.textContent = chip[0];
            button.addEventListener("click", function () {
                dialog.querySelector("#hd-mail-ai-prompt").value = chip[1];
                dialog.querySelector("#hd-mail-ai-prompt").focus();
            });
            chips.appendChild(button);
        });

        dialog.querySelectorAll("[data-hd-mail-ai-close]").forEach(function (button) {
            button.addEventListener("click", function () { dialog.close(); });
        });
        dialog.querySelector("[data-hd-mail-ai-submit]").addEventListener("click", submitAi);

        ai.dialog = dialog;
        return dialog;
    }

    function openAi(range) {
        const dialog = ensureAiDialog();
        const text = range ? range.toString() : "";
        if (!text) {
            toast("Bitte zuerst einen Text markieren.", "warning");
            return;
        }
        if (text.length > MAX_TEXT) {
            toast("Der markierte Text ist zu lang (höchstens " + MAX_TEXT + " Zeichen).", "warning");
            return;
        }
        ai.range = range.cloneRange();
        ai.text = text;
        ai.previous = null;
        dialog.querySelector("[data-hd-mail-ai-excerpt]").textContent = text.length > 220 ? text.slice(0, 220) + " …" : text;
        dialog.querySelector("#hd-mail-ai-prompt").value = "";
        dialog.querySelector("[data-hd-mail-ai-error]").hidden = true;
        dialog.showModal();
        dialog.querySelector("#hd-mail-ai-prompt").focus();
    }

    function submitAi() {
        if (ai.busy || !ai.dialog) {
            return;
        }
        const dialog = ai.dialog;
        const promptEl = dialog.querySelector("#hd-mail-ai-prompt");
        const errorEl = dialog.querySelector("[data-hd-mail-ai-error]");
        const submit = dialog.querySelector("[data-hd-mail-ai-submit]");
        const prompt = promptEl.value.trim();
        if (prompt === "") {
            errorEl.textContent = "Bitte eine Anweisung eingeben.";
            errorEl.hidden = false;
            promptEl.focus();
            return;
        }
        ai.busy = true;
        submit.disabled = true;
        errorEl.hidden = true;
        api(AI_ENDPOINT, {
            method: "POST",
            body: {
                text: ai.text,
                prompt: prompt,
                mode: "ticket_mail",
                previous: ai.previous,
                subject: subjectField ? subjectField.value : "",
                ticket: TICKET
            }
        }).then(function (data) {
            const text = data && typeof data.text === "string" ? data.text : "";
            if (text === "") {
                throw new Error("Die KI hat keine Antwort geliefert.");
            }
            replaceRange(ai.range, text);
            ai.previous = text;
            dialog.close();
            toast("Vorschlag eingefügt.");
        }).catch(function (error) {
            errorEl.textContent = (error && error.message) || "Die KI ist derzeit nicht erreichbar.";
            errorEl.hidden = false;
        }).then(function () {
            ai.busy = false;
            submit.disabled = false;
        });
    }

    // ---------------------------------------------------------------- Pausierung

    function syncPause() {
        if (!pauseCode || !pauseUntil || !pauseGroup) {
            return;
        }
        const active = pauseCode.value !== "";
        pauseGroup.hidden = !active;
        pauseUntil.disabled = !active;
        pauseUntil.required = active;
    }

    if (pauseCode) {
        pauseCode.addEventListener("change", syncPause);
        syncPause();
    }

    // ---------------------------------------------------------------- Absenden

    function showError(message) {
        if (!errorEl) {
            toast(message, "danger");
            return;
        }
        errorEl.textContent = message;
        errorEl.hidden = false;
        errorEl.scrollIntoView({ block: "nearest" });
    }

    if (form) {
        form.addEventListener("submit", function (event) {
            if (errorEl) {
                errorEl.hidden = true;
            }
            const html = editor ? editor.innerHTML.trim() : "";
            const text = editor ? (editor.textContent || "").trim() : "";
            if (text === "" && html.indexOf("<img") < 0) {
                event.preventDefault();
                showError("Bitte einen Nachrichtentext eingeben.");
                if (editor) {
                    editor.focus();
                }
                return;
            }
            if (subjectField && subjectField.value.trim() === "") {
                event.preventDefault();
                showError("Bitte einen Betreff angeben.");
                return;
            }
            if (bodyField) {
                bodyField.value = html;
            }
            if (submitEl) {
                submitEl.disabled = true;
            }
        });
    }

    // Bereits geladene Signatur beim Öffnen über die Schaltfläche nachziehen
    document.querySelectorAll('[data-dialog-open="mail-dialog"]').forEach(function (button) {
        button.addEventListener("click", loadSignature);
    });
})();
