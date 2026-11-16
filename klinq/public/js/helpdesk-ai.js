/* Help Desk: App-eigenes Kontextmenü für Agenten-Textfelder (Rückgängig, Ausschneiden,
 * Kopieren, Einfügen, Alles auswählen), mehrstufige Rechtschreib- und Kontextprüfung
 * mit direkter roter Wellenlinie im Text (wie in MS Word) und Sofortkorrektur per
 * Rechtsklick, 1-Klick-Textkorrektur (KI mit automatischem Rückfall auf lokale
 * Basis-Korrektur ohne KI) sowie „Mit KI verbessern“. Rein datenattribut-gesteuert (CSP). */
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
    const AI_WORDS_ENDPOINT = "/api/helpdesk/ai/wortvorschlaege";
    const AI_PROOFREAD_ENDPOINT = "/api/helpdesk/ai/korrigieren";
    const SPELL_ENDPOINT = "/api/helpdesk/rechtschreibung/vorschlaege";
    const SPELL_CHECK_ENDPOINT = "/api/helpdesk/rechtschreibung/pruefen";
    const SPELL_PROOFREAD_ENDPOINT = "/api/helpdesk/rechtschreibung/korrigieren";
    const SPELL_WORDS_ENDPOINT = "/api/helpdesk/rechtschreibung/woerterbuch";
    const SPELL_REMOVE_ENDPOINT = "/api/helpdesk/rechtschreibung/woerterbuch/entfernen";
    const CHIPS = [
        ["Höflicher", "Formuliere den Text höflicher und wertschätzender."],
        ["Kürzer", "Fasse den Text kürzer und prägnanter."],
        ["Korrigieren", "Korrigiere Rechtschreibung, Grammatik und Zeichensetzung."],
        ["Aufzählung", "Gliedere den Text in eine übersichtliche Aufzählung."],
        ["Englisch", "Übersetze den Text ins Englische."]
    ];

    /** Überarbeitete Textbereiche je Feld (KI oder Basis-Korrektur): {start, end, original, current}. */
    const blocks = new WeakMap();
    const fieldOverlays = new WeakMap();
    const fieldIgnoredIssues = new WeakMap();
    const fieldTimers = new WeakMap();
    const state = { menu: null, field: null, items: [], index: -1, snapshot: null, x: 0, y: 0, showSynonymsFor: "" };
    let dialog = null;
    let promptEl = null;
    let submitEl = null;
    let excerptEl = null;
    let titleEl = null;
    let errorEl = null;
    let errorWrapEl = null;
    let fallbackBtnEl = null;
    const ai = { field: null, block: null, range: null, text: "", busy: false, proofBusy: false };

    // ---------------------------------------------------------------- Felder und Bereiche

    function fieldOf(el) {
        return el && el.closest ? el.closest("[data-hd-field]") : null;
    }

    /** Kommentarfeld: „Interne Notiz“ und öffentliche Antwort haben unterschiedliche Tonalität. */
    function modeOf(el) {
        const mode = (el && el.getAttribute("data-hd-field")) || "generic";
        if (mode === "ticket_comment") {
            const checked = document.querySelector("input[name='type'][type='radio']:checked");
            if (checked && checked.value === "internal") {
                return "internal_note";
            }
        }
        return mode;
    }

    /** Betreff- und Ticketkontext des aktuellen Formulars oder der Detailseite ermitteln. */
    function contextOf(field) {
        const ctx = {};
        const form = field && field.closest ? field.closest("form, dialog") : null;
        const subjectEl = (form && form.querySelector("#f-subject, #mail-subject, #merge-subject, #f-title, input[name='subject'], input[name='title']"))
            || document.querySelector("#f-subject, #f-title, input[name='subject'], input[name='title']");
        if (subjectEl && typeof subjectEl.value === "string" && subjectEl.value.trim() !== "") {
            ctx.subject = subjectEl.value.trim();
        } else {
            const heading = document.querySelector(".page-header h1");
            if (heading && heading.textContent && heading.textContent.trim() !== "") {
                ctx.subject = heading.textContent.trim();
            }
        }
        const ticketBadge = document.querySelector(".ticket-number");
        const mailRoot = document.querySelector("[data-hd-mail][data-ticket]");
        if (ticketBadge && ticketBadge.textContent && ticketBadge.textContent.trim() !== "") {
            ctx.ticket = ticketBadge.textContent.trim();
        } else if (mailRoot && mailRoot.getAttribute("data-ticket")) {
            ctx.ticket = mailRoot.getAttribute("data-ticket");
        }
        return ctx;
    }

    function canEdit(el) {
        return !!el && !el.disabled && !el.readOnly;
    }

    function hasSelection(el) {
        return typeof el.selectionStart === "number" && el.selectionStart !== el.selectionEnd;
    }

    /** Trimmt führende/abschließende Leerzeichen einer Auswahl inkl. exakter Bereichsgrenzen. */
    function trimmedSelection(el) {
        if (!hasSelection(el)) {
            return null;
        }
        let start = el.selectionStart;
        let end = el.selectionEnd;
        const value = el.value;
        while (start < end && /\s/.test(value.charAt(start))) {
            start += 1;
        }
        while (end > start && /\s/.test(value.charAt(end - 1))) {
            end -= 1;
        }
        if (start >= end) {
            return null;
        }
        return { start: start, end: end, text: value.slice(start, end) };
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

    // ---------------------------------------------------------------- Rechtschreibprüfung + KI-Wortvorschläge

    const SPELL_MIN_WORD_LENGTH = 2;
    const SPELL_MAX_WORD_LENGTH = 64;
    const SPELL_RETRY_MS = 30000;
    const SPELL_BATCH_LIMIT = 250;
    const SPELL_DEBOUNCE_MS = 350;

    // Wortgrenzen wie bei Hunspell (WORDCHARS ß-.): Buchstaben, Ziffern und einzelne
    // Binde-/Schlusszeichen im Wort ("E-Mail"). Ein Punkt nach dem Wort bleibt stehen,
    // wird aber mitgeprüft ("Kundennr.").
    const SPELL_TOKEN_RE = (function () {
        try {
            return new RegExp("[\\p{L}\\p{M}0-9]+(?:[.\\-'\u2019]?[\\p{L}\\p{M}0-9]+)*", "gu");
        } catch (e) {
            return new RegExp("[A-Za-z\u00c0-\u024f0-9]+(?:[.\\-'\u2019]?[A-Za-z\u00c0-\u024f0-9]+)*", "g");
        }
    }());
    // Adressen, @Erwähnungen, Bildmarken und Vorlagenplatzhalter prüft das Wörterbuch nicht.
    const SPELL_SKIP_RE = /(?:https?:\/\/|ftp:\/\/|mailto:|www\.)[^\s<>"']+|[^\s<>"'@()\[\]]+@[^\s<>"'@()\[\]]+\.[^\s<>"'@()\[\]]+|\[Bild\s+\d+\]|\{[a-zA-Z0-9_.]+\}|@[A-Za-z0-9_.\-]+/gi;

    const spell = {
        enabled: true,                      // "available: false" vom Server schaltet Wörterbuchabfrage ab
        known: Object.create(null),         // key -> true (korrekt) | false (fehlerhaft)
        personal: Object.create(null),      // key -> gespeicherter Eintrag im persönlichen Wörterbuch
        suggestions: Object.create(null),   // key -> Liste aus Hunspell
        pending: Object.create(null),       // key -> Anfrage läuft
        aiSuggestions: Object.create(null), // cacheKey -> { valid, suggestions }
        aiPending: Object.create(null),     // cacheKey -> Anfrage läuft
        blockedUntil: 0,
        aiBlockedUntil: 0
    };

    function aiAvailable() {
        return AI_ENABLED && Date.now() >= spell.aiBlockedUntil;
    }

    function markAiUnavailable() {
        spell.aiBlockedUntil = Date.now() + SPELL_RETRY_MS;
    }

    function isAiEndpointFailure(error) {
        if (!error) {
            return true;
        }
        return !error.status || error.status === 502 || error.status === 503 || error.status === 504;
    }

    /** Bereiche mit Adressen, @Erwähnungen und Platzhaltern als [start, end]. */
    function spellSkipSpans(text) {
        const spans = [];
        let match;
        SPELL_SKIP_RE.lastIndex = 0;
        while ((match = SPELL_SKIP_RE.exec(text)) !== null) {
            spans.push([match.index, match.index + match[0].length]);
            if (SPELL_SKIP_RE.lastIndex === match.index) {
                SPELL_SKIP_RE.lastIndex += 1;
            }
        }
        return spans;
    }

    /** Treffer des Wortmusters als prüfbares Wort (mit absoluten Positionen), sonst null. */
    function spellTargetAt(text, token, skip, offset) {
        const word = token.word;
        if (word.length < SPELL_MIN_WORD_LENGTH || word.length > SPELL_MAX_WORD_LENGTH) {
            return null;
        }
        if (/[0-9]/.test(word) || (word === word.toUpperCase() && word !== word.toLowerCase())) {
            return null;
        }
        for (let i = 0; i < skip.length; i += 1) {
            if (token.start < skip[i][1] && token.end > skip[i][0]) {
                return null;
            }
        }
        return {
            start: offset + token.start,
            end: offset + token.end,
            word: word,
            key: text.charAt(token.end) === "." ? word + "." : word
        };
    }

    /** Alle prüfbaren Wörter eines Textes in Reihenfolge ermitteln. */
    function spellScanTokens(text) {
        if (!text) {
            return [];
        }
        const skip = spellSkipSpans(text);
        const list = [];
        let match;
        SPELL_TOKEN_RE.lastIndex = 0;
        while ((match = SPELL_TOKEN_RE.exec(text)) !== null) {
            const target = spellTargetAt(text, {
                start: match.index,
                end: match.index + match[0].length,
                word: match[0]
            }, skip, 0);
            if (target) {
                list.push(target);
            }
            if (SPELL_TOKEN_RE.lastIndex === match.index) {
                SPELL_TOKEN_RE.lastIndex += 1;
            }
        }
        return list;
    }

    /** Wort unter dem Cursor oder einzeln markiertes Wort; bei Mehrwort-Auswahl null. */
    function spellWordAt(el) {
        if (!el || typeof el.selectionStart !== "number") {
            return null;
        }
        const value = el.value;
        if (hasSelection(el)) {
            const sel = trimmedSelection(el);
            if (!sel || /\s/.test(sel.text)) {
                return null;
            }
            const skipSel = spellSkipSpans(value);
            return spellTargetAt(value, { start: sel.start, end: sel.end, word: sel.text }, skipSel, 0);
        }
        const caret = el.selectionStart;
        const from = Math.max(0, caret - SPELL_MAX_WORD_LENGTH);
        const text = value.slice(from, Math.min(value.length, caret + SPELL_MAX_WORD_LENGTH));
        const skip = spellSkipSpans(text);
        const position = caret - from;
        let match;
        let found = null;
        SPELL_TOKEN_RE.lastIndex = 0;
        while ((match = SPELL_TOKEN_RE.exec(text)) !== null) {
            const start = match.index;
            if (start > position) {
                break;
            }
            if (position <= start + match[0].length) {
                found = { start: start, end: start + match[0].length, word: match[0] };
            }
            if (SPELL_TOKEN_RE.lastIndex === match.index) {
                SPELL_TOKEN_RE.lastIndex += 1;
            }
        }
        return found ? spellTargetAt(text, found, skip, from) : null;
    }

    /** Umgebenden Satz um eine Wortposition extrahieren (für KI-Kontextvorschläge). */
    function sentenceAround(text, start, end) {
        let left = text.lastIndexOf("\n", Math.max(0, start - 1));
        left = left === -1 ? 0 : left + 1;
        let right = text.indexOf("\n", end);
        right = right === -1 ? text.length : right;
        const line = text.slice(left, right);
        const relStart = start - left;
        const relEnd = end - left;
        let sLeft = 0;
        let sRight = line.length;
        for (let i = relStart - 1; i > 0; i -= 1) {
            if (/[.!?]/.test(line.charAt(i)) && /\s/.test(line.charAt(i + 1))) {
                sLeft = i + 2;
                break;
            }
        }
        for (let j = relEnd; j < line.length; j += 1) {
            if (/[.!?]/.test(line.charAt(j)) && (j + 1 === line.length || /\s/.test(line.charAt(j + 1)))) {
                sRight = j + 1;
                break;
            }
        }
        return line.slice(sLeft, sRight).trim().slice(0, 320);
    }

    function aiWordCacheKey(el, target, synonyms) {
        const sentence = sentenceAround(el.value, target.start, target.end);
        return modeOf(el) + "|" + (synonyms ? "syn" : "fix") + "|" + sentence + "|" + target.key;
    }

    /** Einzelwort-Vorschläge aus dem Hunspell-Wörterbuch laden. */
    function spellRequest(key, field) {
        if (spell.pending[key] || Date.now() < spell.blockedUntil || !api) {
            return;
        }
        spell.pending[key] = true;
        api(SPELL_ENDPOINT + "?word=" + encodeURIComponent(key)).then(function (data) {
            if (data && data.available === false) {
                spell.enabled = false;
                return;
            }
            if (field && field.spellcheck !== false) {
                field.spellcheck = false;
            }
            spell.known[key] = !!(data && data.known);
            if (data && data.personal && data.personal_word) {
                spell.personal[key] = String(data.personal_word);
            } else {
                delete spell.personal[key];
            }
            spell.suggestions[key] = (data && data.suggestions) || [];
        }).catch(function () {
            spell.blockedUntil = Date.now() + SPELL_RETRY_MS;
        }).then(function () {
            delete spell.pending[key];
            if (field) {
                renderFieldOverlay(field);
            }
            spellRefresh();
        });
    }

    /** Kontextbezogene KI-Wortvorschläge oder Synonyme anfordern. */
    function requestAiWords(el, target, synonyms) {
        if (!aiAvailable() || !api) {
            return;
        }
        const cacheKey = aiWordCacheKey(el, target, synonyms);
        if (spell.aiPending[cacheKey] || spell.aiSuggestions[cacheKey] !== undefined) {
            return;
        }
        spell.aiPending[cacheKey] = true;
        const ctx = contextOf(el);
        api(AI_WORDS_ENDPOINT, {
            method: "POST",
            body: {
                word: target.key,
                sentence: sentenceAround(el.value, target.start, target.end),
                mode: modeOf(el),
                synonyms: !!synonyms,
                subject: ctx.subject || "",
                ticket: ctx.ticket || ""
            }
        }).then(function (data) {
            spell.aiSuggestions[cacheKey] = {
                valid: !!(data && data.valid),
                suggestions: (data && Array.isArray(data.suggestions)) ? data.suggestions : []
            };
        }).catch(function (error) {
            if (isAiEndpointFailure(error)) {
                markAiUnavailable();
            }
            spell.aiSuggestions[cacheKey] = { valid: false, suggestions: [], failed: true };
        }).then(function () {
            delete spell.aiPending[cacheKey];
            spellRefresh();
        });
    }

    /** Offenes Kontextmenü neu aufbauen (nach Eintreffen von Hunspell- oder KI-Vorschlägen). */
    function spellRefresh() {
        if (!state.menu || !state.field || !state.field.isConnected) {
            return;
        }
        showMenu(state.x, state.y, cleanItems(menuItems(state.field)), state.field);
    }

    /** Wort für diese Sitzung als korrekt behandeln („Alle ignorieren“). */
    function spellAccept(target, field) {
        spell.known[target.word] = true;
        spell.known[target.key] = true;
        delete spell.suggestions[target.word];
        delete spell.suggestions[target.key];
        refreshAllFieldOverlays();
        if (field && !field.isConnected) {
            return;
        }
    }

    /** Wort durch einen Vorschlag ersetzen (ohne doppelten Satzpunkt am Ende). */
    function spellReplace(el, target, suggestion) {
        if (!el.isConnected || el.value.slice(target.start, target.end) !== target.word) {
            toast("Das Wort steht nicht mehr im Text.", "info");
            return;
        }
        const swallowDot = suggestion.slice(-1) === "." && el.value.charAt(target.end) === ".";
        replaceRange(el, target.start, target.end + (swallowDot ? 1 : 0), suggestion);
        spell.known[suggestion] = true;
        if (target.key !== target.word && !swallowDot) {
            spell.known[suggestion + "."] = true;
        }
        scheduleFieldCheck(el);
    }

    /** Wort ins persönliche Wörterbuch aufnehmen (ohne Satzendepunkt, damit alle Satzpositionen gelten). */
    function spellAddWord(target, field) {
        if (!api) {
            return;
        }
        const entry = target.word;
        api(SPELL_WORDS_ENDPOINT, { method: "POST", body: { word: entry } }).then(function () {
            spellAccept(target, field);
            spell.personal[target.word] = entry;
            spell.personal[target.key] = entry;
            if (field) {
                scheduleFieldCheck(field);
            }
            toast("„" + entry + "“ wurde in Ihr Wörterbuch aufgenommen.", "success");
        }).catch(function (error) {
            toast(messageOf(error) || "Das Wort konnte nicht aufgenommen werden.", "error");
        });
    }

    /** Wort wieder aus dem persönlichen Wörterbuch entfernen. */
    function spellRemoveWord(storedWord, target, field) {
        if (!api || !storedWord) {
            return;
        }
        api(SPELL_REMOVE_ENDPOINT, { method: "POST", body: { word: storedWord } }).then(function () {
            Object.keys(spell.personal).forEach(function (k) {
                if (spell.personal[k] === storedWord) {
                    delete spell.personal[k];
                    delete spell.known[k];
                    delete spell.suggestions[k];
                }
            });
            delete spell.known[storedWord];
            delete spell.known[target.word];
            delete spell.known[target.key];
            if (field) {
                scheduleFieldCheck(field);
            }
            toast("„" + storedWord + "“ wurde aus Ihrem Wörterbuch entfernt.", "info");
        }).catch(function (error) {
            toast(messageOf(error) || "Das Wort konnte nicht entfernt werden.", "error");
        });
    }

    /** Einträge des Abschnitts „Rechtschreibung“ im Kontextmenü. */
    function spellItems(el) {
        if (!api) {
            return [];
        }
        const target = spellWordAt(el);
        if (!target) {
            return [];
        }

        const contextIssue = inlineIssueAt(el, target);
        if (contextIssue && contextIssue.kind === "repeat") {
            return [
                { header: true, label: "Wortwiederholung" },
                {
                    label: "Wiederholung „" + contextIssue.word + "“ löschen",
                    spell: true,
                    run: function () {
                        if (!el.isConnected || el.value.slice(contextIssue.start, contextIssue.end) !== contextIssue.word) {
                            toast("Die Textstelle wurde zwischenzeitlich geändert.", "info");
                            return;
                        }
                        replaceRange(el, contextIssue.replaceStart, contextIssue.replaceEnd, "");
                        renderFieldOverlay(el);
                        scheduleFieldCheck(el);
                    }
                },
                { separator: true },
                { label: "Einmal ignorieren", run: function () { ignoreFieldIssue(el, contextIssue.ignoreKey); } },
                { separator: true }
            ];
        }
        if (contextIssue && contextIssue.kind === "context") {
            return [
                { header: true, label: "Kontextprüfung" },
                {
                    label: contextIssue.suggestion,
                    spell: true,
                    run: function () { spellReplace(el, target, contextIssue.suggestion); }
                },
                { separator: true },
                { label: "Einmal ignorieren", run: function () { ignoreFieldIssue(el, contextIssue.ignoreKey); } },
                { separator: true }
            ];
        }

        // Noch unbekannter Status im Cache: Einzelabfrage anstoßen
        if (spell.enabled && spell.known[target.key] === undefined && Date.now() >= spell.blockedUntil) {
            spellRequest(target.key, el);
            return [
                { header: true, label: "Rechtschreibung" },
                { label: "Wird geprüft …", disabled: true },
                { separator: true }
            ];
        }

        // Wort ist bekannt (Wörterbuch oder persönlich)
        if (!spell.enabled || spell.known[target.key] === true) {
            const out = [];
            const personalEntry = spell.personal[target.key] || spell.personal[target.word] || "";
            const synCacheKey = aiWordCacheKey(el, target, true);
            const wantSyn = state.showSynonymsFor === synCacheKey;

            if (personalEntry || aiAvailable()) {
                out.push({ header: true, label: "Rechtschreibung & Wortwahl" });
            }
            if (personalEntry) {
                out.push({
                    label: "„" + personalEntry + "“ aus Wörterbuch entfernen",
                    run: function () { spellRemoveWord(personalEntry, target, el); }
                });
            }
            if (aiAvailable()) {
                if (wantSyn) {
                    const synData = spell.aiSuggestions[synCacheKey];
                    if (spell.aiPending[synCacheKey] || !synData) {
                        out.push({ note: true, label: "KI sucht passende Alternativen …" });
                        requestAiWords(el, target, true);
                    } else if (synData.suggestions && synData.suggestions.length) {
                        synData.suggestions.forEach(function (alt) {
                            out.push({
                                label: alt,
                                aiSpell: true,
                                badge: "KI",
                                run: function () { spellReplace(el, target, alt); }
                            });
                        });
                    } else {
                        out.push({ note: true, label: "Keine alternativen Wortvorschläge" });
                    }
                } else {
                    out.push({
                        label: "KI-Wortalternativen suchen",
                        ai: true,
                        run: function () {
                            state.showSynonymsFor = synCacheKey;
                            requestAiWords(el, target, true);
                            showMenu(state.x, state.y, cleanItems(menuItems(el)), el);
                        }
                    });
                }
            }
            if (out.length) {
                out.push({ separator: true });
            }
            return out;
        }

        // Wort ist als unbekannt/fehlerhaft markiert
        const items = [{ header: true, label: "Rechtschreibung" }];
        if (Date.now() < spell.blockedUntil) {
            items.push({ label: "Wörterbuch derzeit nicht verfügbar", disabled: true });
        } else if (spell.suggestions[target.key] === undefined) {
            items.push({ label: "Wird gesucht …", disabled: true });
            spellRequest(target.key, el);
        } else {
            const hunspellList = spell.suggestions[target.key] || [];
            const seen = Object.create(null);
            hunspellList.forEach(function (suggestion) {
                seen[suggestion] = true;
                items.push({
                    label: suggestion,
                    spell: true,
                    run: function () { spellReplace(el, target, suggestion); }
                });
            });

            if (aiAvailable()) {
                const fixKey = aiWordCacheKey(el, target, false);
                const aiData = spell.aiSuggestions[fixKey];
                if (!aiData) {
                    items.push({ note: true, label: "KI-Kontextprüfung läuft …" });
                    requestAiWords(el, target, false);
                } else {
                    if (aiData.valid) {
                        items.push({ note: true, valid: true, label: "✓ Fachbegriff im Kontext erkannt" });
                    }
                    (aiData.suggestions || []).forEach(function (suggestion) {
                        if (seen[suggestion]) {
                            return;
                        }
                        seen[suggestion] = true;
                        items.push({
                            label: suggestion,
                            aiSpell: true,
                            badge: "KI",
                            run: function () { spellReplace(el, target, suggestion); }
                        });
                    });
                }
            }

            if (Object.keys(seen).length === 0) {
                items.push({ label: "Keine Vorschläge", disabled: true });
            }

            items.push(
                { separator: true },
                { label: "Alle ignorieren", run: function () { spellAccept(target, el); } },
                { label: "„" + target.word + "“ zum Wörterbuch hinzufügen", run: function () { spellAddWord(target, el); } }
            );
        }
        items.push({ separator: true });
        return items;
    }

    // ---------------------------------------------------------------- Direkte Wellenlinie im Text (wie in MS Word)

    const REPEAT_ALLOWED_WORDS = {
        so: true, na: true, ja: true, nein: true, bla: true, bitte: true,
        teils: true, halb: true, war: true, hatte: true, sei: true
    };
    const RELATIVE_CLAUSE_WORDS = {
        die: true, das: true, der: true, den: true, dem: true, dessen: true,
        deren: true, sie: true, es: true, er: true, wir: true, ihr: true
    };
    const SEID_CONTEXT_RE = /^[ \t]+(?:\d+\s*(?:Uhr|Min|Minuten|Std|Stunden|Tagen?|Wochen?|Monaten?|Jahren?|%|\.)|(?:dem|gestern|heute|vorgestern|kurzem|langem|längerem|einigen|einem|einer|zwei|drei|vier|fünf|sechs|sieben|acht|neun|zehn|zwölf|mehreren|vielen|etlichen|wann|Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag|Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember|Wochen|Tagen|Stunden|Minuten|Monaten|Jahren|letzter|letztem|letzten|früh|heutigem|diesem|dieser|jeher|Beginn|Anfang|Ende|Update|Upgrade|Neustart|Austausch|Wechsel|Umstellung|Installation|Migration)\b)/iu;
    const WIDER_CONTEXT_RE = /^[ \t]+(?:einwandfrei|erwartungsgemäß|normal|erreichbar|verfügbar|funktioniert|funktionieren|ok|gut|da|online|aktiv|hergestellt|starten|gestartet|anmelden|angemeldet|drucken|möglich|offen|geschlossen|zurück|abholen|frei|stabil|schnell|problemlos|korrekt|ordnungsgemäß|wie)\b/iu;

    const OVERLAY_STYLE_PROPS = [
        "fontFamily", "fontSize", "fontWeight", "fontStyle", "fontVariant",
        "fontStretch", "fontKerning", "fontFeatureSettings", "letterSpacing",
        "wordSpacing", "textTransform", "textIndent", "tabSize", "direction"
    ];

    const resizeObserver = typeof window.ResizeObserver === "function"
        ? new window.ResizeObserver(function (entries) {
            entries.forEach(function (item) {
                const entry = fieldOverlays.get(item.target);
                if (entry) {
                    syncOverlayGeometry(entry);
                    syncOverlayScroll(entry);
                }
            });
        })
        : null;

    function isSpellField(field) {
        if (!field || !canEdit(field)) {
            return false;
        }
        if (field.tagName === "TEXTAREA") {
            return true;
        }
        if (field.tagName === "INPUT") {
            const type = String(field.type || "text").toLowerCase();
            return type === "text" || type === "search";
        }
        return false;
    }

    function ignoreFieldIssue(field, ignoreKey) {
        if (!field || !ignoreKey) {
            return;
        }
        let set = fieldIgnoredIssues.get(field);
        if (!set) {
            set = Object.create(null);
            fieldIgnoredIssues.set(field, set);
        }
        set[ignoreKey] = true;
        renderFieldOverlay(field);
    }

    /**
     * Ermittelt unterkringelungswürdige Stellen direkt im Text:
     * 1. Unbekannte Wörter (Hunspell)
     * 2. Unbeabsichtigte Wortwiederholungen („das das“, „wir wir“)
     * 3. Typische Kontextverwechslungen („seid gestern“ -> „seit“, „wider einwandfrei“ -> „wieder“)
     */
    function collectInlineIssues(field) {
        const text = field && typeof field.value === "string" ? field.value : "";
        if (!text) {
            return [];
        }
        const ignored = fieldIgnoredIssues.get(field) || Object.create(null);
        const tokens = spellScanTokens(text);
        const issues = [];

        for (let i = 0; i < tokens.length; i += 1) {
            const tok = tokens[i];
            if (spell.enabled && spell.known[tok.key] === false) {
                issues.push({
                    start: tok.start,
                    end: tok.end,
                    word: tok.word,
                    key: tok.key,
                    kind: "spell"
                });
                continue;
            }

            if (i > 0) {
                const prev = tokens[i - 1];
                const between = text.slice(prev.end, tok.start);
                if (/^[ \t]+$/.test(between) && !/[.\-'\u2019]/.test(tok.word)) {
                    const prevLower = prev.word.toLocaleLowerCase("de-DE");
                    const currLower = tok.word.toLocaleLowerCase("de-DE");
                    if (prevLower === currLower && !REPEAT_ALLOWED_WORDS[currLower]) {
                        const beforePrev = text.slice(Math.max(0, prev.start - 4), prev.start);
                        const isRelativeClause = RELATIVE_CLAUSE_WORDS[currLower] && /,\s*$/.test(beforePrev);
                        const ignoreKey = "rep:" + tok.start + ":" + currLower;
                        if (!isRelativeClause && !ignored[ignoreKey]) {
                            issues.push({
                                start: tok.start,
                                end: tok.end,
                                replaceStart: prev.end,
                                replaceEnd: tok.end,
                                word: tok.word,
                                key: tok.key,
                                kind: "repeat",
                                ignoreKey: ignoreKey
                            });
                            continue;
                        }
                    }
                }
            }

            const tail = text.slice(tok.end, Math.min(text.length, tok.end + 40));
            if ((tok.word === "seid" || tok.word === "Seid") && SEID_CONTEXT_RE.test(tail)) {
                const seidKey = "ctx:" + tok.start + ":" + tok.word;
                if (!ignored[seidKey]) {
                    issues.push({
                        start: tok.start,
                        end: tok.end,
                        word: tok.word,
                        key: tok.key,
                        kind: "context",
                        suggestion: tok.word.charAt(0) === "S" ? "Seit" : "seit",
                        ignoreKey: seidKey
                    });
                }
            } else if ((tok.word === "wider" || tok.word === "Wider") && WIDER_CONTEXT_RE.test(tail)) {
                const widerKey = "ctx:" + tok.start + ":" + tok.word;
                if (!ignored[widerKey]) {
                    issues.push({
                        start: tok.start,
                        end: tok.end,
                        word: tok.word,
                        key: tok.key,
                        kind: "context",
                        suggestion: tok.word.charAt(0) === "W" ? "Wieder" : "wieder",
                        ignoreKey: widerKey
                    });
                }
            }
        }
        return issues;
    }

    function inlineIssueAt(field, target) {
        if (!field || !target || !isSpellField(field)) {
            return null;
        }
        const issues = collectInlineIssues(field);
        for (let i = 0; i < issues.length; i += 1) {
            if (issues[i].start === target.start && issues[i].end === target.end && issues[i].kind !== "spell") {
                return issues[i];
            }
        }
        return null;
    }

    function ensureFieldOverlay(field) {
        if (!isSpellField(field) || !field.parentElement) {
            return null;
        }
        let entry = fieldOverlays.get(field);
        if (entry && entry.overlay.isConnected && entry.overlay.parentElement === field.parentElement) {
            return entry;
        }
        const host = field.parentElement;
        host.classList.add("hd-spell-host");

        const overlay = document.createElement("div");
        overlay.className = "hd-spell-overlay";
        overlay.setAttribute("aria-hidden", "true");
        overlay.setAttribute("data-hd-spell-overlay", "");
        overlay.hidden = true;

        const layer = document.createElement("div");
        const singleLine = field.tagName !== "TEXTAREA";
        layer.className = singleLine ? "hd-spell-layer is-single-line" : "hd-spell-layer";

        let textHost = layer;
        if (singleLine) {
            textHost = document.createElement("span");
            textHost.className = "hd-spell-layer-inner";
            layer.appendChild(textHost);
        }
        overlay.appendChild(layer);
        field.insertAdjacentElement("afterend", overlay);

        entry = { field: field, host: host, overlay: overlay, layer: layer, textHost: textHost, singleLine: singleLine };
        fieldOverlays.set(field, entry);
        if (resizeObserver) {
            resizeObserver.observe(field);
        }
        return entry;
    }

    function syncOverlayGeometry(entry) {
        if (!entry || !entry.field.isConnected || !entry.host.isConnected) {
            return false;
        }
        const field = entry.field;
        const host = entry.host;
        const width = field.clientWidth;
        const height = field.clientHeight;
        if (width <= 0 || height <= 0) {
            entry.overlay.hidden = true;
            return false;
        }
        const hostRect = host.getBoundingClientRect();
        const fieldRect = field.getBoundingClientRect();
        const cs = window.getComputedStyle(field);
        const hostCs = window.getComputedStyle(host);
        const borderLeft = parseFloat(cs.borderLeftWidth) || 0;
        const borderTop = parseFloat(cs.borderTopWidth) || 0;
        const hostBorderLeft = parseFloat(hostCs.borderLeftWidth) || 0;
        const hostBorderTop = parseFloat(hostCs.borderTopWidth) || 0;
        const left = (fieldRect.left - hostRect.left) - hostBorderLeft + host.scrollLeft + borderLeft;
        const top = (fieldRect.top - hostRect.top) - hostBorderTop + host.scrollTop + borderTop;

        const ovStyle = entry.overlay.style;
        ovStyle.left = left + "px";
        ovStyle.top = top + "px";
        ovStyle.width = width + "px";
        ovStyle.height = height + "px";

        const layerStyle = entry.layer.style;
        OVERLAY_STYLE_PROPS.forEach(function (prop) {
            layerStyle[prop] = cs[prop];
        });
        layerStyle.textAlign = cs.textAlign;
        layerStyle.paddingLeft = cs.paddingLeft;
        layerStyle.paddingRight = cs.paddingRight;

        if (entry.singleLine) {
            layerStyle.paddingTop = "0px";
            layerStyle.paddingBottom = "0px";
            layerStyle.lineHeight = "normal";
        } else {
            layerStyle.paddingTop = cs.paddingTop;
            layerStyle.paddingBottom = cs.paddingBottom;
            layerStyle.lineHeight = cs.lineHeight;
            layerStyle.whiteSpace = "pre-wrap";
            layerStyle.overflowWrap = "break-word";
            layerStyle.wordBreak = cs.wordBreak;
        }
        return true;
    }

    function syncOverlayScroll(entry) {
        if (!entry || !entry.field.isConnected) {
            return;
        }
        const x = -(entry.field.scrollLeft || 0);
        const y = entry.singleLine ? 0 : -(entry.field.scrollTop || 0);
        const target = entry.singleLine ? entry.textHost : entry.layer;
        target.style.transform = "translate3d(" + x + "px, " + y + "px, 0)";
    }

    function renderOverlayNodes(entry, rawValue, issues) {
        const text = entry.singleLine ? rawValue.replace(/[\r\n]/g, " ") : rawValue;
        const frag = document.createDocumentFragment();
        let cursor = 0;
        issues.forEach(function (issue) {
            if (issue.start < cursor || issue.end > text.length) {
                return;
            }
            if (issue.start > cursor) {
                frag.appendChild(document.createTextNode(text.slice(cursor, issue.start)));
            }
            const mark = document.createElement("mark");
            mark.className = "hd-spell-mark";
            mark.setAttribute("data-start", String(issue.start));
            mark.setAttribute("data-end", String(issue.end));
            mark.setAttribute("data-kind", issue.kind);
            mark.textContent = text.slice(issue.start, issue.end);
            frag.appendChild(mark);
            cursor = issue.end;
        });
        if (cursor < text.length) {
            frag.appendChild(document.createTextNode(text.slice(cursor)));
        }
        if (!entry.singleLine && text.slice(-1) === "\n") {
            frag.appendChild(document.createTextNode("\u200b"));
        }
        entry.textHost.textContent = "";
        entry.textHost.appendChild(frag);
    }

    function renderFieldOverlay(field) {
        if (!isSpellField(field)) {
            const stale = field && fieldOverlays.get(field);
            if (stale) {
                stale.overlay.hidden = true;
            }
            return;
        }
        const issues = collectInlineIssues(field);
        const existing = fieldOverlays.get(field);
        if (issues.length === 0) {
            if (existing) {
                existing.textHost.textContent = "";
                existing.overlay.hidden = true;
            }
            return;
        }
        const entry = ensureFieldOverlay(field);
        if (!entry) {
            return;
        }
        entry.overlay.hidden = false;
        if (!syncOverlayGeometry(entry)) {
            return;
        }
        renderOverlayNodes(entry, field.value, issues);
        syncOverlayScroll(entry);
    }

    function refreshAllFieldOverlays() {
        document.querySelectorAll("[data-hd-field]").forEach(function (field) {
            if (isSpellField(field)) {
                renderFieldOverlay(field);
            }
        });
    }

    /**
     * Positioniert bei Rechtsklick ohne Mehrwort-Markierung den Cursor direkt im
     * angeklickten (insbesondere rot unterkringelten) Wort – wie in MS Word.
     */
    function focusWordAtPoint(field, clientX, clientY) {
        if (!isSpellField(field) || hasSelection(field)) {
            return;
        }
        if (!Number.isFinite(clientX) || !Number.isFinite(clientY) || (clientX === 0 && clientY === 0)) {
            return;
        }
        const fieldRect = field.getBoundingClientRect();
        if (clientX < fieldRect.left || clientX > fieldRect.right || clientY < fieldRect.top || clientY > fieldRect.bottom) {
            return;
        }

        const placeCaret = function (pos) {
            const clamped = Math.max(0, Math.min(field.value.length, pos));
            const st = field.scrollTop;
            const sl = field.scrollLeft;
            try {
                field.focus({ preventScroll: true });
            } catch (e) {
                field.focus();
            }
            try {
                field.setSelectionRange(clamped, clamped);
            } catch (e) {
                /* ignorieren */
            }
            field.scrollTop = st;
            field.scrollLeft = sl;
        };

        const entry = ensureFieldOverlay(field);
        if (!entry || !field.value) {
            return;
        }

        const wasHidden = entry.overlay.hidden;
        if (wasHidden) {
            entry.overlay.hidden = false;
            if (!syncOverlayGeometry(entry)) {
                entry.overlay.hidden = true;
                return;
            }
            renderOverlayNodes(entry, field.value, collectInlineIssues(field));
            syncOverlayScroll(entry);
        }

        // 1. Direkter Treffer auf eine rote Wellenlinie (<mark class="hd-spell-mark">)
        const marks = entry.textHost.querySelectorAll(".hd-spell-mark");
        for (let i = 0; i < marks.length; i += 1) {
            const rects = marks[i].getClientRects();
            for (let r = 0; r < rects.length; r += 1) {
                const box = rects[r];
                if (clientX >= box.left - 3 && clientX <= box.right + 3 && clientY >= box.top - 3 && clientY <= box.bottom + 3) {
                    const start = parseInt(marks[i].getAttribute("data-start") || "0", 10);
                    const end = parseInt(marks[i].getAttribute("data-end") || "0", 10);
                    if (wasHidden) {
                        entry.textHost.textContent = "";
                        entry.overlay.hidden = true;
                    }
                    placeCaret(Math.floor((start + end) / 2));
                    return;
                }
            }
        }

        // 2. Beliebiges Wort an der Klickposition über das deckungsgleiche Text-Overlay ermitteln
        let hitOffset = null;
        entry.overlay.style.pointerEvents = "auto";
        try {
            let hitNode = null;
            let localOffset = 0;
            if (typeof document.caretPositionFromPoint === "function") {
                const pos = document.caretPositionFromPoint(clientX, clientY);
                if (pos) {
                    hitNode = pos.offsetNode;
                    localOffset = pos.offset;
                }
            } else if (typeof document.caretRangeFromPoint === "function") {
                const range = document.caretRangeFromPoint(clientX, clientY);
                if (range) {
                    hitNode = range.startContainer;
                    localOffset = range.startOffset;
                }
            }
            if (hitNode && entry.textHost.contains(hitNode)) {
                let total = 0;
                const walker = document.createTreeWalker(entry.textHost, NodeFilter.SHOW_TEXT, null);
                let current;
                while ((current = walker.nextNode()) !== null) {
                    if (current === hitNode) {
                        hitOffset = total + localOffset;
                        break;
                    }
                    total += current.nodeValue.length;
                }
            }
        } finally {
            entry.overlay.style.pointerEvents = "";
            if (wasHidden) {
                entry.textHost.textContent = "";
                entry.overlay.hidden = true;
            }
        }

        if (hitOffset !== null) {
            placeCaret(hitOffset);
        }
    }

    /** Wörter eines Feldes gebündelt vorprüfen (Debounced). */
    function checkFieldWordsNow(field) {
        if (!field || !field.isConnected || !isSpellField(field)) {
            return;
        }
        if (!spell.enabled || !api || Date.now() < spell.blockedUntil) {
            renderFieldOverlay(field);
            return;
        }
        const tokens = spellScanTokens(field.value);
        if (!tokens.length) {
            renderFieldOverlay(field);
            return;
        }
        const missing = [];
        const queued = Object.create(null);
        for (let i = 0; i < tokens.length && missing.length < SPELL_BATCH_LIMIT; i += 1) {
            const key = tokens[i].key;
            if (spell.known[key] === undefined && !queued[key]) {
                queued[key] = true;
                missing.push(key);
            }
        }
        if (!missing.length) {
            if (field.spellcheck !== false) {
                field.spellcheck = false;
            }
            renderFieldOverlay(field);
            return;
        }
        api(SPELL_CHECK_ENDPOINT, { method: "POST", body: { words: missing } }).then(function (data) {
            if (data && data.available === false) {
                spell.enabled = false;
                renderFieldOverlay(field);
                return;
            }
            if (field.spellcheck !== false) {
                field.spellcheck = false;
            }
            const results = (data && data.results) || {};
            const personal = (data && data.personal) || {};
            Object.keys(results).forEach(function (word) {
                spell.known[word] = !!results[word];
                if (personal[word]) {
                    spell.personal[word] = String(personal[word]);
                } else {
                    delete spell.personal[word];
                }
            });
            renderFieldOverlay(field);
        }).catch(function () {
            spell.blockedUntil = Date.now() + SPELL_RETRY_MS;
            renderFieldOverlay(field);
        });
    }

    function scheduleFieldCheck(field) {
        if (!field || !isSpellField(field)) {
            return;
        }
        const prev = fieldTimers.get(field);
        if (prev) {
            window.clearTimeout(prev);
        }
        fieldTimers.set(field, window.setTimeout(function () {
            fieldTimers.delete(field);
            checkFieldWordsNow(field);
        }, SPELL_DEBOUNCE_MS));
    }

    // ---------------------------------------------------------------- 1-Klick-Korrektur (KI + Basis-Korrektur)

    /**
     * Korrigiert Auswahl oder gesamtes Feld: mit KI (inkl. Server-Fallback auf
     * Basis-Korrektur bei Ausfall) oder direkt über die lokale Basis-Korrektur ohne KI.
     */
    function proofreadField(field, forceBasic) {
        if (!field || !canEdit(field) || ai.proofBusy || !api) {
            return;
        }
        const sel = trimmedSelection(field);
        const range = sel ? { start: sel.start, end: sel.end } : { start: 0, end: field.value.length };
        const sourceText = sel ? sel.text : field.value;
        if (!sourceText || sourceText.trim() === "") {
            toast("Kein Text zum Korrigieren vorhanden.", "info");
            return;
        }
        if (sourceText.length > MAX_TEXT) {
            toast("Der Text ist zu lang (maximal " + MAX_TEXT + " Zeichen). Bitte abschnittsweise markieren.", "warning");
            return;
        }

        const useBasic = !!forceBasic || !aiAvailable();
        const endpoint = useBasic ? SPELL_PROOFREAD_ENDPOINT : AI_PROOFREAD_ENDPOINT;
        const ctx = contextOf(field);
        const payload = useBasic
            ? { text: sourceText }
            : { text: sourceText, mode: modeOf(field), subject: ctx.subject || "", ticket: ctx.ticket || "" };

        ai.proofBusy = true;

        const runBasicFallback = function () {
            return api(SPELL_PROOFREAD_ENDPOINT, { method: "POST", body: { text: sourceText } }).then(function (basicData) {
                if (basicData) {
                    basicData.fallback = true;
                }
                return basicData;
            });
        };

        api(endpoint, { method: "POST", body: payload }).catch(function (error) {
            if (!useBasic && isAiEndpointFailure(error)) {
                markAiUnavailable();
                return runBasicFallback();
            }
            throw error;
        }).then(function (data) {
            const text = data && typeof data.text === "string" ? data.text : "";
            const isFallback = !!(data && data.fallback);
            if (isFallback) {
                markAiUnavailable();
            }
            if (!text || text === sourceText || (data && data.changed === false)) {
                if (isFallback) {
                    toast("KI nicht erreichbar – die lokale Basis-Prüfung fand keine eindeutigen Tippfehler.", "info");
                } else {
                    toast("Keine Rechtschreib- oder Tippfehler gefunden.", "success");
                }
                return;
            }
            if (field.value.slice(range.start, range.end) !== sourceText) {
                toast("Der Text wurde zwischenzeitlich geändert – Korrektur verworfen.", "warning");
                return;
            }
            applyResult(text, { field: field, range: range, block: null, source: sourceText });
            renderFieldOverlay(field);
            scheduleFieldCheck(field);
            if (isFallback) {
                toast("KI-Endpunkt nicht erreichbar – Basis-Korrektur ohne KI angewendet.", "info");
            } else if (useBasic) {
                toast("Basis-Korrektur angewendet.", "success");
            } else {
                toast("Rechtschreibung und Grammatik korrigiert.", "success");
            }
        }).catch(function (error) {
            toast(messageOf(error), "error");
        }).then(function () {
            ai.proofBusy = false;
        });
    }

    // ---------------------------------------------------------------- Kontextmenü

    function menuItems(el) {
        const editable = canEdit(el);
        const items = [];
        if (editable) {
            spellItems(el).forEach(function (item) { items.push(item); });
        }
        items.push(
            { label: "Rückgängig", key: MOD + "Z", disabled: !editable, run: function () { restore(state.snapshot); execCommand("undo"); } },
            { label: "Wiederholen", key: MOD + "Y", disabled: !editable, run: function () { restore(state.snapshot); execCommand("redo"); } },
            { separator: true },
            { label: "Ausschneiden", key: MOD + "X", disabled: !editable || !hasSelection(el), run: cutSelection },
            { label: "Kopieren", key: MOD + "C", disabled: !hasSelection(el), run: copySelection },
            { label: "Einfügen", key: MOD + "V", disabled: !editable, run: pasteClipboard },
            { separator: true },
            { label: "Alles auswählen", key: MOD + "A", run: function () { selectAll(el); } }
        );

        const block = blockAtCaret(el);
        if (block && editable) {
            items.push({ separator: true });
            if (AI_ENABLED) {
                items.push({ label: "Weiter verfeinern …", ai: true, run: function () { openDialog(el, block); } });
            }
            items.push({ label: "Auf Original zurücksetzen", run: function () { resetBlock(el, block); } });
            items.push({ label: "Markierung entfernen", run: function () { dropBlock(el, block); toast("Markierung entfernt."); } });
            return items;
        }

        if (editable) {
            const hasText = el.value && el.value.trim() !== "";
            const sel = trimmedSelection(el);
            if (hasText || sel) {
                items.push({ separator: true });
            }
            if (hasText) {
                if (aiAvailable()) {
                    items.push({
                        label: sel ? "Auswahl korrigieren" : "Rechtschreibung & Grammatik korrigieren",
                        key: "Alt+Umschalt+K",
                        ai: true,
                        run: function () { proofreadField(el, false); }
                    });
                } else {
                    items.push({
                        label: sel ? "Basis-Korrektur (Auswahl)" : "Basis-Korrektur anwenden",
                        key: "Alt+Umschalt+K",
                        run: function () { proofreadField(el, true); }
                    });
                }
            }
            if (AI_ENABLED && sel) {
                items.push({ label: "Mit KI verbessern …", ai: true, run: function () { openDialog(el, null); } });
            }
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
        state.x = x;
        state.y = y;
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
            if (item.header) {
                const head = document.createElement("div");
                head.className = "hd-ctx-head";
                head.setAttribute("role", "presentation");
                head.textContent = item.label;
                menu.appendChild(head);
                return;
            }
            if (item.note) {
                const note = document.createElement("div");
                note.className = "hd-ctx-note" + (item.valid ? " is-valid" : "");
                note.setAttribute("role", "presentation");
                note.textContent = item.label;
                menu.appendChild(note);
                return;
            }
            const button = document.createElement("button");
            button.type = "button";
            button.className = "hd-ctx-item"
                + (item.ai ? " is-ai" : "")
                + (item.spell ? " is-spell" : "")
                + (item.aiSpell ? " is-ai-spell" : "");
            button.setAttribute("role", "menuitem");
            button.setAttribute("aria-disabled", item.disabled ? "true" : "false");
            button.disabled = !!item.disabled;
            const label = document.createElement("span");
            label.className = "hd-ctx-label";
            label.textContent = item.label;
            button.appendChild(label);
            if (item.badge) {
                const badge = document.createElement("span");
                badge.className = "hd-ctx-badge";
                badge.textContent = item.badge;
                button.appendChild(badge);
            } else if (item.key) {
                const key = document.createElement("span");
                key.className = "hd-ctx-key";
                key.textContent = item.key;
                button.appendChild(key);
            }
            button.addEventListener("click", function () {
                if (!item.keepOpen) {
                    hideMenu();
                }
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
        const fallbackRect = field ? field.getBoundingClientRect() : { left: 16, bottom: 16 };
        const safeX = Number.isFinite(x) ? x : fallbackRect.left + 16;
        const safeY = Number.isFinite(y) ? y : fallbackRect.bottom + 4;
        const left = Math.max(8, Math.min(safeX, window.innerWidth - rect.width - 8));
        const top = Math.max(8, Math.min(safeY, window.innerHeight - rect.height - 8));
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
        focusWordAtPoint(field, event.clientX, event.clientY);
        state.snapshot = snapshotOf(field);
        state.showSynonymsFor = "";
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
            return;
        }
        if (event.altKey && event.shiftKey && (event.key === "K" || event.key === "k")) {
            const field = fieldOf(event.target);
            if (field && canEdit(field)) {
                event.preventDefault();
                proofreadField(field, !aiAvailable());
            }
        }
    }, true);

    window.addEventListener("resize", function () {
        hideMenu();
        refreshAllFieldOverlays();
    });
    window.addEventListener("blur", hideMenu);
    document.addEventListener("scroll", function (event) {
        const entry = event.target && fieldOverlays.get ? fieldOverlays.get(event.target) : null;
        if (entry) {
            syncOverlayScroll(entry);
        }
        hideMenu();
    }, true);

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
            '<p class="hd-ai-note text-xs text-muted">Der markierte Text wird an die hinterlegte KI gesendet. Steht der Endpunkt nicht zur Verfügung, bleibt die lokale Basis-Korrektur ohne KI nutzbar.</p>',
            '<div class="alert alert-danger" data-hd-ai-error-wrap hidden><div data-hd-ai-error></div>',
            '<div class="hd-ai-error-actions"><button type="button" class="btn btn-secondary btn-sm" data-hd-ai-basic-fallback hidden>Basis-Korrektur ohne KI anwenden</button></div></div>',
            '</div>',
            '<div class="dialog-footer"><button type="button" class="btn btn-secondary" data-hd-ai-close>Abbrechen</button>',
            '<button type="button" class="btn btn-primary" data-hd-ai-submit>Vorschlag erzeugen</button></div>'
        ].join("");
        document.body.appendChild(dialog);

        promptEl = dialog.querySelector("#hd-ai-prompt");
        submitEl = dialog.querySelector("[data-hd-ai-submit]");
        excerptEl = dialog.querySelector("[data-hd-ai-excerpt]");
        titleEl = dialog.querySelector("[data-hd-ai-title]");
        errorWrapEl = dialog.querySelector("[data-hd-ai-error-wrap]");
        errorEl = dialog.querySelector("[data-hd-ai-error]");
        fallbackBtnEl = dialog.querySelector("[data-hd-ai-basic-fallback]");

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

        fallbackBtnEl.addEventListener("click", function () {
            if (!ai.field || !ai.range || !ai.text || !api) {
                return;
            }
            fallbackBtnEl.disabled = true;
            api(SPELL_PROOFREAD_ENDPOINT, { method: "POST", body: { text: ai.text } }).then(function (data) {
                const target = { field: ai.field, range: ai.range, block: ai.block, source: ai.text };
                closeDialog();
                const corrected = data && typeof data.text === "string" ? data.text : "";
                if (corrected && corrected !== target.source && data.changed !== false) {
                    applyResult(corrected, target);
                    scheduleFieldCheck(target.field);
                    toast("Basis-Korrektur ohne KI angewendet.", "success");
                } else {
                    toast("Die Basis-Prüfung fand keine eindeutigen Tippfehler.", "info");
                }
            }).catch(function (err) {
                showError(messageOf(err), false);
            }).then(function () {
                if (fallbackBtnEl) {
                    fallbackBtnEl.disabled = false;
                }
            });
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
        if (errorWrapEl) {
            errorWrapEl.hidden = true;
        }
        if (errorEl) {
            errorEl.textContent = "";
        }
        if (fallbackBtnEl) {
            fallbackBtnEl.hidden = true;
            fallbackBtnEl.disabled = false;
        }
    }

    function closeDialog() {
        if (dialog && dialog.open) {
            dialog.close();
        } else {
            resetDialog();
        }
    }

    function showError(message, offerBasicFallback) {
        if (!errorWrapEl || !errorEl) {
            toast(message, "error");
            return;
        }
        errorEl.textContent = message;
        if (fallbackBtnEl) {
            fallbackBtnEl.hidden = !offerBasicFallback;
        }
        errorWrapEl.hidden = false;
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
            const sel = trimmedSelection(field);
            if (!sel) {
                toast("Bitte zuerst einen Text markieren.", "warning");
                return;
            }
            ai.text = sel.text;
            ai.range = { start: sel.start, end: sel.end };
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
            showError("Bitte eine Anweisung eingeben.", false);
            promptEl.focus();
            return;
        }
        if (ai.text.length > MAX_TEXT) {
            showError("Der markierte Text ist zu lang (maximal " + MAX_TEXT + " Zeichen).", false);
            return;
        }
        if (prompt.length > MAX_PROMPT) {
            showError("Die Anweisung ist zu lang (maximal " + MAX_PROMPT + " Zeichen).", false);
            return;
        }
        const ctx = contextOf(ai.field);
        const payload = {
            text: ai.text,
            prompt: prompt,
            mode: modeOf(ai.field),
            subject: ctx.subject || "",
            ticket: ctx.ticket || ""
        };
        if (ai.block) {
            payload.previous = ai.block.current;
        }
        ai.busy = true;
        submitEl.disabled = true;
        submitEl.textContent = "Wird erzeugt …";
        if (errorWrapEl) {
            errorWrapEl.hidden = true;
        }
        if (!api) {
            showError("Die KI ist derzeit nicht verfügbar.", true);
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
            scheduleFieldCheck(target.field);
            toast("Vorschlag übernommen.", "success");
        }).catch(function (error) {
            const endpointDown = isAiEndpointFailure(error);
            if (endpointDown) {
                markAiUnavailable();
            }
            showError(messageOf(error), endpointDown);
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
        scheduleFieldCheck(field);
        toast("Originaltext wiederhergestellt.", "success");
    }

    // Bereiche und Inline-Wellenlinien sofort bei Eingaben/Scrollen/Fokus synchron halten
    document.addEventListener("input", function (event) {
        const field = fieldOf(event.target);
        if (!field) {
            return;
        }
        if (blocks.has(field)) {
            validBlocks(field);
        }
        fieldIgnoredIssues.delete(field);
        renderFieldOverlay(field);
        scheduleFieldCheck(field);
    });

    function syncEventScroll(event) {
        const entry = event.target && fieldOverlays.get ? fieldOverlays.get(event.target) : null;
        if (entry) {
            syncOverlayScroll(entry);
        }
    }
    document.addEventListener("keyup", syncEventScroll);
    document.addEventListener("mouseup", syncEventScroll);
    document.addEventListener("select", syncEventScroll);

    document.addEventListener("focusin", function (event) {
        const field = fieldOf(event.target);
        if (field) {
            renderFieldOverlay(field);
            scheduleFieldCheck(field);
        }
    });

    // Vorausgefüllte Felder (z. B. beim Bearbeiten) einmal sanft vorprüfen
    window.setTimeout(function () {
        document.querySelectorAll("[data-hd-field]").forEach(function (field) {
            if (isSpellField(field) && field.value && field.value.trim() !== "") {
                scheduleFieldCheck(field);
            }
        });
    }, 150);
})();
