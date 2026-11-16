/* Help Desk: E-Mail-Dialog eines Tickets. Der Editor arbeitet wie in Orvanta mit
 * einem formatierbaren Bereich; Rechtschreibprüfung (rote Wellenlinie per CSS
 * Custom Highlight API, Hunspell + kontextbezogene KI-Wortvorschläge), 1-Klick-
 * Korrektur (mit lokaler Basis-Korrektur ohne KI als Rückfall) und „Mit KI
 * verbessern“ sind integriert. Die Signatur stammt unveränderlich aus dem Intranet
 * und wird nur angezeigt – angehängt wird sie beim Versand auf dem Server. Rein
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
    const subjectField = root.querySelector("[data-hd-mail-subject]");
    const pauseCode = root.querySelector("[data-hd-mail-pause-code]");
    const pauseGroup = root.querySelector("[data-hd-mail-pause-until]");
    const pauseUntil = pauseGroup ? pauseGroup.querySelector("input[name='pause_until']") : null;
    const errorEl = root.querySelector("[data-hd-mail-error]");
    const submitEl = root.querySelector("[data-hd-mail-submit]");

    const AI_ENABLED = root.getAttribute("data-ai-enabled") === "1";
    const AI_ENDPOINT = "/api/helpdesk/ai/verbessern";
    const AI_WORDS_ENDPOINT = "/api/helpdesk/ai/wortvorschlaege";
    const AI_PROOFREAD_ENDPOINT = "/api/helpdesk/ai/korrigieren";
    const SPELL_ENDPOINT = "/api/helpdesk/rechtschreibung/vorschlaege";
    const SPELL_CHECK_ENDPOINT = "/api/helpdesk/rechtschreibung/pruefen";
    const SPELL_PROOFREAD_ENDPOINT = "/api/helpdesk/rechtschreibung/korrigieren";
    const SPELL_WORDS_ENDPOINT = "/api/helpdesk/rechtschreibung/woerterbuch";
    const SPELL_REMOVE_ENDPOINT = "/api/helpdesk/rechtschreibung/woerterbuch/entfernen";
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

    const state = { menu: null, items: [], index: -1, range: null, x: 0, y: 0, target: null, showSynonymsFor: "" };
    const ai = { range: null, original: "", current: "", dialog: null, busy: false, proofBusy: false };

    // ---------------------------------------------------------------- Inline-Styles

    function inlineStylesToCssom(html) {
        return String(html).replace(/\sstyle=("[^"]*"|'[^']*')/gi, " data-hd-style=$1");
    }

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

    let signatureHtml = "";
    let signaturePromise = null;

    function loadSignature() {
        if (signaturePromise) {
            return signaturePromise;
        }
        signaturePromise = api(root.getAttribute("data-signature-url")).then(function (data) {
            signatureHtml = data && data.html ? String(data.html) : "";
            renderSignature();
            return data;
        }).catch(function () {
            signaturePromise = null;
        });
        return signaturePromise;
    }

    function renderSignature() {
        if (!editor) {
            return;
        }
        editor.querySelectorAll(".ov-signature-block").forEach(function (node) { node.remove(); });
        if (signatureHtml === "") {
            return;
        }
        const holder = document.createElement("div");
        holder.innerHTML = inlineStylesToCssom(signatureHtml);
        applyInlineStyles(holder);
        const block = holder.querySelector(".ov-signature-block") || holder.firstElementChild;
        if (!block) {
            return;
        }
        block.setAttribute("contenteditable", "false");
        const quote = editor.querySelector(":scope > blockquote");
        if (quote) {
            editor.insertBefore(block, quote);
        } else {
            editor.appendChild(block);
        }
        if (!block.previousElementSibling) {
            const spacer = document.createElement("p");
            spacer.appendChild(document.createElement("br"));
            editor.insertBefore(spacer, block);
        }
        editor.setAttribute("data-hd-signature-html", block.outerHTML);
    }

    function guardSignature() {
        if (!editor) {
            return;
        }
        editor.addEventListener("beforeinput", function (event) {
            const block = editor.querySelector(".ov-signature-block");
            const selection = window.getSelection();
            if (!block || !selection || !selection.rangeCount) {
                return;
            }
            const range = selection.getRangeAt(0);
            const inside = block.contains(range.startContainer) || block.contains(range.endContainer);
            if (!inside && (range.collapsed || !range.intersectsNode(block))) {
                return;
            }
            event.preventDefault();
            if (range.collapsed && inside) {
                return;
            }
            const blockRange = document.createRange();
            blockRange.selectNode(block);
            let before = null;
            let after = null;
            if (range.compareBoundaryPoints(Range.START_TO_START, blockRange) < 0) {
                before = range.cloneRange();
                before.setEndBefore(block);
            }
            if (range.compareBoundaryPoints(Range.END_TO_END, blockRange) > 0) {
                after = range.cloneRange();
                after.setStartAfter(block);
            }
            if (after) {
                after.deleteContents();
            }
            if (before) {
                before.deleteContents();
            }
            editor.querySelectorAll(":scope > p:empty").forEach(function (p) {
                p.appendChild(document.createElement("br"));
            });
            if (!block.previousElementSibling) {
                const spacer = document.createElement("p");
                spacer.appendChild(document.createElement("br"));
                editor.insertBefore(spacer, block);
            }
            const caret = document.createRange();
            if (before) {
                caret.setStart(before.startContainer, before.startOffset);
            } else {
                caret.setStart(block.previousElementSibling, 0);
            }
            caret.collapse(true);
            selection.removeAllRanges();
            selection.addRange(caret);
            if (event.inputType === "insertText" && typeof event.data === "string") {
                document.execCommand("insertText", false, event.data);
            } else if (event.inputType === "insertParagraph") {
                document.execCommand("insertParagraph");
            } else {
                editor.dispatchEvent(new Event("input", { bubbles: true }));
            }
        });
        editor.addEventListener("input", function () {
            if (signatureHtml !== "") {
                const block = editor.querySelector(".ov-signature-block");
                if (!block || block.outerHTML !== editor.getAttribute("data-hd-signature-html")) {
                    renderSignature();
                }
            }
            if (ai.range && (!editor.contains(ai.range.commonAncestorContainer) || ai.range.toString() !== ai.current)) {
                ai.range = null;
                ai.original = "";
                ai.current = "";
            }
            scheduleMailSpellcheck();
        });
    }

    function editorContent() {
        if (!editor) {
            return { text: "", hasImage: false };
        }
        const clone = editor.cloneNode(true);
        clone.querySelectorAll(".ov-signature-block").forEach(function (node) { node.remove(); });
        return {
            text: (clone.textContent || "").trim(),
            hasImage: clone.querySelector("img") !== null
        };
    }

    guardSignature();

    root.addEventListener("toggle", function () {
        if (!root.open) {
            hideMenu();
            clearHighlights();
            return;
        }
        loadSignature();
        if (editor) {
            editor.focus();
            scheduleMailSpellcheck();
        }
    });

    // ---------------------------------------------------------------- Auswahl & Textmanipulation

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

    function restoreRange(range) {
        if (!range || !editor) {
            return;
        }
        editor.focus();
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

    /** Ersetzt einen DOM-Range und liefert den neuen Range über dem eingefügten Text zurück. */
    function replaceRangeAndSelect(range, text) {
        if (!range || !editor) {
            return null;
        }
        restoreRange(range);
        range.deleteContents();
        const node = document.createTextNode(text);
        range.insertNode(node);
        const inserted = document.createRange();
        inserted.selectNodeContents(node);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(inserted);
        editor.dispatchEvent(new Event("input", { bubbles: true }));
        return inserted.cloneRange();
    }

    function replaceRange(range, text) {
        replaceRangeAndSelect(range, text);
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
        const sig = editor.querySelector(".ov-signature-block");
        if (sig && sig.parentNode === editor) {
            range.setEndBefore(sig);
        }
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
            if (text && state.range) {
                replaceRange(state.range, text);
            }
        }).catch(function () {
            toast("Zwischenablage nicht lesbar – bitte Strg+V verwenden.", "warning");
        });
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
        return (error && error.message) ? String(error.message) : "Die KI ist derzeit nicht erreichbar.";
    }

    // ---------------------------------------------------------------- Rechtschreibprüfung im Mail-Editor

    const SPELL_MIN_LEN = 2;
    const SPELL_MAX_LEN = 64;
    const SPELL_RETRY_MS = 30000;
    const SUPPORTS_HIGHLIGHT = typeof window.CSS !== "undefined" && !!window.CSS.highlights && typeof window.Highlight === "function";
    const SPELL_TOKEN_RE = (function () {
        try {
            return new RegExp("[\\p{L}\\p{M}0-9]+(?:[.\\-'\u2019]?[\\p{L}\\p{M}0-9]+)*", "gu");
        } catch (e) {
            return new RegExp("[A-Za-z\u00c0-\u024f0-9]+(?:[.\\-'\u2019]?[A-Za-z\u00c0-\u024f0-9]+)*", "g");
        }
    }());
    const SPELL_SKIP_RE = /(?:https?:\/\/|ftp:\/\/|mailto:|www\.)[^\s<>"']+|[^\s<>"'@()\[\]]+@[^\s<>"'@()\[\]]+\.[^\s<>"'@()\[\]]+|@[A-Za-z0-9_.\-]+/gi;

    const spell = {
        enabled: true,
        known: Object.create(null),
        personal: Object.create(null),
        suggestions: Object.create(null),
        pending: Object.create(null),
        aiSuggestions: Object.create(null),
        aiPending: Object.create(null),
        blockedUntil: 0,
        aiBlockedUntil: 0,
        timer: 0
    };

    function aiAvailable() {
        return AI_ENABLED && Date.now() >= spell.aiBlockedUntil;
    }

    function isAiEndpointFailure(error) {
        return !error || !error.status || error.status === 502 || error.status === 503 || error.status === 504;
    }

    function clearHighlights() {
        if (SUPPORTS_HIGHLIGHT) {
            window.CSS.highlights.delete("hd-spell-error");
        }
    }

    function isInsideIgnoredBlock(node) {
        const el = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
        return !!(el && el.closest(".ov-signature-block, blockquote"));
    }

    function skipSpans(text) {
        const spans = [];
        let m;
        SPELL_SKIP_RE.lastIndex = 0;
        while ((m = SPELL_SKIP_RE.exec(text)) !== null) {
            spans.push([m.index, m.index + m[0].length]);
            if (SPELL_SKIP_RE.lastIndex === m.index) {
                SPELL_SKIP_RE.lastIndex += 1;
            }
        }
        return spans;
    }

    function tokenInfo(text, start, end, word, skip) {
        if (word.length < SPELL_MIN_LEN || word.length > SPELL_MAX_LEN) {
            return null;
        }
        if (/[0-9]/.test(word) || (word === word.toUpperCase() && word !== word.toLowerCase())) {
            return null;
        }
        for (let i = 0; i < skip.length; i += 1) {
            if (start < skip[i][1] && end > skip[i][0]) {
                return null;
            }
        }
        return {
            start: start,
            end: end,
            word: word,
            key: text.charAt(end) === "." ? word + "." : word
        };
    }

    function collectEditorTokens() {
        if (!editor) {
            return [];
        }
        const out = [];
        const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                return isInsideIgnoredBlock(node) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            }
        });
        let node;
        while ((node = walker.nextNode()) !== null) {
            const text = node.nodeValue || "";
            if (!text.trim()) {
                continue;
            }
            const skip = skipSpans(text);
            let m;
            SPELL_TOKEN_RE.lastIndex = 0;
            while ((m = SPELL_TOKEN_RE.exec(text)) !== null) {
                const tok = tokenInfo(text, m.index, m.index + m[0].length, m[0], skip);
                if (tok) {
                    tok.node = node;
                    tok.sentence = text.trim().slice(0, 320);
                    out.push(tok);
                }
                if (SPELL_TOKEN_RE.lastIndex === m.index) {
                    SPELL_TOKEN_RE.lastIndex += 1;
                }
            }
        }
        return out;
    }

    const MAIL_REPEAT_ALLOWED = {
        so: true, na: true, ja: true, nein: true, bla: true, bitte: true,
        teils: true, halb: true, war: true, hatte: true, sei: true
    };
    const MAIL_RELATIVE_WORDS = {
        die: true, das: true, der: true, den: true, dem: true, dessen: true,
        deren: true, sie: true, es: true, er: true, wir: true, ihr: true
    };
    const MAIL_SEID_RE = /^[ \t]+(?:\d+\s*(?:Uhr|Min|Minuten|Std|Stunden|Tagen?|Wochen?|Monaten?|Jahren?|%|\.)|(?:dem|gestern|heute|vorgestern|kurzem|langem|längerem|einigen|einem|einer|zwei|drei|vier|fünf|sechs|sieben|acht|neun|zehn|zwölf|mehreren|vielen|etlichen|wann|Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag|Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember|Wochen|Tagen|Stunden|Minuten|Monaten|Jahren|letzter|letztem|letzten|früh|heutigem|diesem|dieser|jeher|Beginn|Anfang|Ende|Update|Upgrade|Neustart|Austausch|Wechsel|Umstellung|Installation|Migration)\b)/iu;
    const MAIL_WIDER_RE = /^[ \t]+(?:einwandfrei|erwartungsgemäß|normal|erreichbar|verfügbar|funktioniert|funktionieren|ok|gut|da|online|aktiv|hergestellt|starten|gestartet|anmelden|angemeldet|drucken|möglich|offen|geschlossen|zurück|abholen|frei|stabil|schnell|problemlos|korrekt|ordnungsgemäß|wie)\b/iu;
    const mailIgnoredIssues = new WeakMap();

    function ignoreMailIssue(node, key) {
        if (!node || !key) {
            return;
        }
        let set = mailIgnoredIssues.get(node);
        if (!set) {
            set = Object.create(null);
            mailIgnoredIssues.set(node, set);
        }
        set[key] = true;
        paintHighlights(collectEditorTokens());
    }

    function detectMailTokenIssue(tokens, index) {
        const tok = tokens[index];
        if (!tok || !tok.node || !tok.node.isConnected) {
            return null;
        }
        if (spell.known[tok.key] === false) {
            return { kind: "spell" };
        }
        const text = tok.node.nodeValue || "";
        const ignored = mailIgnoredIssues.get(tok.node) || Object.create(null);
        if (index > 0) {
            const prev = tokens[index - 1];
            if (prev.node === tok.node) {
                const between = text.slice(prev.end, tok.start);
                if (/^[ \t]+$/.test(between) && !/[.\-'\u2019]/.test(tok.word)) {
                    const prevLower = prev.word.toLocaleLowerCase("de-DE");
                    const currLower = tok.word.toLocaleLowerCase("de-DE");
                    if (prevLower === currLower && !MAIL_REPEAT_ALLOWED[currLower]) {
                        const beforePrev = text.slice(Math.max(0, prev.start - 4), prev.start);
                        const isRel = MAIL_RELATIVE_WORDS[currLower] && /,\s*$/.test(beforePrev);
                        const repKey = "rep:" + tok.start + ":" + currLower;
                        if (!isRel && !ignored[repKey]) {
                            return { kind: "repeat", replaceStart: prev.end, replaceEnd: tok.end, ignoreKey: repKey };
                        }
                    }
                }
            }
        }
        const tail = text.slice(tok.end, Math.min(text.length, tok.end + 40));
        if ((tok.word === "seid" || tok.word === "Seid") && MAIL_SEID_RE.test(tail)) {
            const seidKey = "ctx:" + tok.start + ":" + tok.word;
            if (!ignored[seidKey]) {
                return { kind: "context", suggestion: tok.word.charAt(0) === "S" ? "Seit" : "seit", ignoreKey: seidKey };
            }
        } else if ((tok.word === "wider" || tok.word === "Wider") && MAIL_WIDER_RE.test(tail)) {
            const widerKey = "ctx:" + tok.start + ":" + tok.word;
            if (!ignored[widerKey]) {
                return { kind: "context", suggestion: tok.word.charAt(0) === "W" ? "Wieder" : "wieder", ignoreKey: widerKey };
            }
        }
        return null;
    }

    function paintHighlights(tokens) {
        if (!SUPPORTS_HIGHLIGHT) {
            return;
        }
        const ranges = [];
        tokens.forEach(function (tok, idx) {
            if (detectMailTokenIssue(tokens, idx) && tok.node && tok.node.isConnected) {
                try {
                    const r = document.createRange();
                    r.setStart(tok.node, tok.start);
                    r.setEnd(tok.node, tok.end);
                    ranges.push(r);
                } catch (e) {
                    /* ignorieren */
                }
            }
        });
        if (ranges.length) {
            window.CSS.highlights.set("hd-spell-error", new window.Highlight(...ranges));
        } else {
            window.CSS.highlights.delete("hd-spell-error");
        }
    }

    function checkMailWordsNow() {
        if (!spell.enabled || !editor || !root.open || Date.now() < spell.blockedUntil) {
            return;
        }
        const tokens = collectEditorTokens();
        if (!tokens.length) {
            clearHighlights();
            return;
        }
        const missing = [];
        const queued = Object.create(null);
        for (let i = 0; i < tokens.length && missing.length < 250; i += 1) {
            const k = tokens[i].key;
            if (spell.known[k] === undefined && !queued[k]) {
                queued[k] = true;
                missing.push(k);
            }
        }
        if (!missing.length) {
            paintHighlights(tokens);
            return;
        }
        api(SPELL_CHECK_ENDPOINT, { method: "POST", body: { words: missing } }).then(function (data) {
            if (data && data.available === false) {
                spell.enabled = false;
                editor.setAttribute("spellcheck", "true");
                clearHighlights();
                return;
            }
            const results = (data && data.results) || {};
            const personal = (data && data.personal) || {};
            Object.keys(results).forEach(function (w) {
                spell.known[w] = !!results[w];
                if (personal[w]) {
                    spell.personal[w] = String(personal[w]);
                } else {
                    delete spell.personal[w];
                }
            });
            paintHighlights(collectEditorTokens());
        }).catch(function () {
            spell.blockedUntil = Date.now() + SPELL_RETRY_MS;
        });
    }

    function scheduleMailSpellcheck() {
        if (spell.timer) {
            window.clearTimeout(spell.timer);
        }
        spell.timer = window.setTimeout(function () {
            spell.timer = 0;
            checkMailWordsNow();
        }, 350);
    }

    function wordTargetAtPoint(clientX, clientY) {
        let caretRange = null;
        if (document.caretRangeFromPoint) {
            caretRange = document.caretRangeFromPoint(clientX, clientY);
        } else if (document.caretPositionFromPoint) {
            const pos = document.caretPositionFromPoint(clientX, clientY);
            if (pos && pos.offsetNode) {
                caretRange = document.createRange();
                caretRange.setStart(pos.offsetNode, pos.offset);
                caretRange.collapse(true);
            }
        }
        if (!caretRange) {
            caretRange = selectionRange();
        }
        if (!caretRange || !editor.contains(caretRange.startContainer) || isInsideIgnoredBlock(caretRange.startContainer)) {
            return null;
        }
        const node = caretRange.startContainer;
        if (node.nodeType !== Node.TEXT_NODE) {
            return null;
        }
        const text = node.nodeValue || "";
        const offset = caretRange.startOffset;
        const skip = skipSpans(text);
        let m;
        let found = null;
        SPELL_TOKEN_RE.lastIndex = 0;
        while ((m = SPELL_TOKEN_RE.exec(text)) !== null) {
            if (m.index > offset) {
                break;
            }
            if (offset <= m.index + m[0].length) {
                found = tokenInfo(text, m.index, m.index + m[0].length, m[0], skip);
                if (found) {
                    found.node = node;
                    found.sentence = text.trim().slice(0, 320);
                }
            }
            if (SPELL_TOKEN_RE.lastIndex === m.index) {
                SPELL_TOKEN_RE.lastIndex += 1;
            }
        }
        return found;
    }

    function replaceMailWord(target, suggestion) {
        if (!target || !target.node || !target.node.isConnected) {
            return;
        }
        const text = target.node.nodeValue || "";
        if (text.slice(target.start, target.end) !== target.word) {
            return;
        }
        const swallowDot = suggestion.slice(-1) === "." && text.charAt(target.end) === ".";
        const r = document.createRange();
        r.setStart(target.node, target.start);
        r.setEnd(target.node, target.end + (swallowDot ? 1 : 0));
        replaceRange(r, suggestion);
        spell.known[suggestion] = true;
        scheduleMailSpellcheck();
    }

    function refreshOpenMenu() {
        if (!state.menu || !root.open) {
            return;
        }
        showMenu(state.x, state.y, cleanItems(menuItems()));
    }

    function requestSingleSpell(key) {
        if (spell.pending[key] || Date.now() < spell.blockedUntil) {
            return;
        }
        spell.pending[key] = true;
        api(SPELL_ENDPOINT + "?word=" + encodeURIComponent(key)).then(function (data) {
            if (data && data.available === false) {
                spell.enabled = false;
                return;
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
            scheduleMailSpellcheck();
            refreshOpenMenu();
        });
    }

    function requestMailAiWords(target, synonyms) {
        if (!aiAvailable() || !target) {
            return;
        }
        const cacheKey = (synonyms ? "syn|" : "fix|") + (target.sentence || "") + "|" + target.key;
        if (spell.aiPending[cacheKey] || spell.aiSuggestions[cacheKey] !== undefined) {
            return;
        }
        spell.aiPending[cacheKey] = true;
        api(AI_WORDS_ENDPOINT, {
            method: "POST",
            body: {
                word: target.key,
                sentence: target.sentence || "",
                mode: "ticket_mail",
                synonyms: !!synonyms,
                subject: subjectField ? subjectField.value : "",
                ticket: TICKET
            }
        }).then(function (data) {
            spell.aiSuggestions[cacheKey] = {
                valid: !!(data && data.valid),
                suggestions: (data && Array.isArray(data.suggestions)) ? data.suggestions : []
            };
        }).catch(function (error) {
            if (isAiEndpointFailure(error)) {
                spell.aiBlockedUntil = Date.now() + SPELL_RETRY_MS;
            }
            spell.aiSuggestions[cacheKey] = { valid: false, suggestions: [] };
        }).then(function () {
            delete spell.aiPending[cacheKey];
            refreshOpenMenu();
        });
    }

    function mailIssueForTarget(target) {
        if (!target || !target.node) {
            return null;
        }
        const tokens = collectEditorTokens();
        for (let i = 0; i < tokens.length; i += 1) {
            if (tokens[i].node === target.node && tokens[i].start === target.start && tokens[i].end === target.end) {
                const issue = detectMailTokenIssue(tokens, i);
                return issue && issue.kind !== "spell" ? issue : null;
            }
        }
        return null;
    }

    function mailSpellItems(hasMultiWordSelection) {
        const target = hasMultiWordSelection ? null : state.target;
        if (!target) {
            return [];
        }
        const ctxIssue = mailIssueForTarget(target);
        if (ctxIssue && ctxIssue.kind === "repeat") {
            return [
                { header: true, label: "Wortwiederholung" },
                {
                    label: "Wiederholung „" + target.word + "“ löschen",
                    spell: true,
                    run: function () {
                        if (!target.node || !target.node.isConnected) {
                            return;
                        }
                        const r = document.createRange();
                        r.setStart(target.node, ctxIssue.replaceStart);
                        r.setEnd(target.node, ctxIssue.replaceEnd);
                        replaceRange(r, "");
                        scheduleMailSpellcheck();
                    }
                },
                { separator: true },
                { label: "Einmal ignorieren", run: function () { ignoreMailIssue(target.node, ctxIssue.ignoreKey); } },
                { separator: true }
            ];
        }
        if (ctxIssue && ctxIssue.kind === "context") {
            return [
                { header: true, label: "Kontextprüfung" },
                {
                    label: ctxIssue.suggestion,
                    spell: true,
                    run: function () { replaceMailWord(target, ctxIssue.suggestion); }
                },
                { separator: true },
                { label: "Einmal ignorieren", run: function () { ignoreMailIssue(target.node, ctxIssue.ignoreKey); } },
                { separator: true }
            ];
        }
        if (spell.enabled && spell.known[target.key] === undefined && Date.now() >= spell.blockedUntil) {
            requestSingleSpell(target.key);
            return [
                { header: true, label: "Rechtschreibung" },
                { label: "Wird geprüft …", disabled: true },
                { separator: true }
            ];
        }
        if (!spell.enabled || spell.known[target.key] === true) {
            const out = [];
            const personalWord = spell.personal[target.key] || spell.personal[target.word] || "";
            const synKey = "syn|" + (target.sentence || "") + "|" + target.key;
            if (personalWord || aiAvailable()) {
                out.push({ header: true, label: "Rechtschreibung & Wortwahl" });
            }
            if (personalWord) {
                out.push({
                    label: "„" + personalWord + "“ aus Wörterbuch entfernen",
                    run: function () {
                        api(SPELL_REMOVE_ENDPOINT, { method: "POST", body: { word: personalWord } }).then(function () {
                            delete spell.personal[target.key];
                            delete spell.personal[target.word];
                            delete spell.known[target.key];
                            delete spell.known[target.word];
                            scheduleMailSpellcheck();
                            toast("„" + personalWord + "“ wurde aus Ihrem Wörterbuch entfernt.", "info");
                        }).catch(function (err) { toast(messageOf(err), "error"); });
                    }
                });
            }
            if (aiAvailable()) {
                if (state.showSynonymsFor === synKey) {
                    const synData = spell.aiSuggestions[synKey];
                    if (spell.aiPending[synKey] || !synData) {
                        out.push({ note: true, label: "KI sucht passende Alternativen …" });
                        requestMailAiWords(target, true);
                    } else if (synData.suggestions && synData.suggestions.length) {
                        synData.suggestions.forEach(function (alt) {
                            out.push({
                                label: alt,
                                aiSpell: true,
                                badge: "KI",
                                run: function () { replaceMailWord(target, alt); }
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
                            state.showSynonymsFor = synKey;
                            requestMailAiWords(target, true);
                            showMenu(state.x, state.y, cleanItems(menuItems()));
                        }
                    });
                }
            }
            if (out.length) {
                out.push({ separator: true });
            }
            return out;
        }

        const items = [{ header: true, label: "Rechtschreibung" }];
        if (spell.suggestions[target.key] === undefined) {
            items.push({ label: "Wird gesucht …", disabled: true });
            requestSingleSpell(target.key);
        } else {
            const seen = Object.create(null);
            (spell.suggestions[target.key] || []).forEach(function (s) {
                seen[s] = true;
                items.push({ label: s, spell: true, run: function () { replaceMailWord(target, s); } });
            });
            if (aiAvailable()) {
                const fixKey = "fix|" + (target.sentence || "") + "|" + target.key;
                const aiData = spell.aiSuggestions[fixKey];
                if (!aiData) {
                    items.push({ note: true, label: "KI-Kontextprüfung läuft …" });
                    requestMailAiWords(target, false);
                } else {
                    if (aiData.valid) {
                        items.push({ note: true, valid: true, label: "✓ Fachbegriff im Kontext erkannt" });
                    }
                    (aiData.suggestions || []).forEach(function (s) {
                        if (!seen[s]) {
                            seen[s] = true;
                            items.push({ label: s, aiSpell: true, badge: "KI", run: function () { replaceMailWord(target, s); } });
                        }
                    });
                }
            }
            if (!Object.keys(seen).length) {
                items.push({ label: "Keine Vorschläge", disabled: true });
            }
            items.push(
                { separator: true },
                {
                    label: "Alle ignorieren",
                    run: function () {
                        spell.known[target.word] = true;
                        spell.known[target.key] = true;
                        scheduleMailSpellcheck();
                    }
                },
                {
                    label: "„" + target.word + "“ zum Wörterbuch hinzufügen",
                    run: function () {
                        api(SPELL_WORDS_ENDPOINT, { method: "POST", body: { word: target.word } }).then(function () {
                            spell.known[target.word] = true;
                            spell.known[target.key] = true;
                            spell.personal[target.word] = target.word;
                            spell.personal[target.key] = target.word;
                            scheduleMailSpellcheck();
                            toast("„" + target.word + "“ wurde in Ihr Wörterbuch aufgenommen.", "success");
                        }).catch(function (err) { toast(messageOf(err), "error"); });
                    }
                }
            );
        }
        items.push({ separator: true });
        return items;
    }

    /** 1-Klick-Korrektur einer Auswahl im Mail-Editor (mit Basis-Korrektur als Rückfall). */
    function proofreadMailRange(range, forceBasic) {
        if (!range || ai.proofBusy) {
            return;
        }
        const sourceText = range.toString();
        if (!sourceText || !sourceText.trim()) {
            toast("Bitte den zu korrigierenden Abschnitt markieren.", "info");
            return;
        }
        if (sourceText.length > MAX_TEXT) {
            toast("Der markierte Text ist zu lang (höchstens " + MAX_TEXT + " Zeichen).", "warning");
            return;
        }
        const targetRange = range.cloneRange();
        const useBasic = !!forceBasic || !aiAvailable();
        ai.proofBusy = true;

        const callBasic = function () {
            return api(SPELL_PROOFREAD_ENDPOINT, { method: "POST", body: { text: sourceText } }).then(function (d) {
                if (d) {
                    d.fallback = true;
                }
                return d;
            });
        };

        const req = useBasic
            ? api(SPELL_PROOFREAD_ENDPOINT, { method: "POST", body: { text: sourceText } })
            : api(AI_PROOFREAD_ENDPOINT, {
                method: "POST",
                body: {
                    text: sourceText,
                    mode: "ticket_mail",
                    subject: subjectField ? subjectField.value : "",
                    ticket: TICKET
                }
            }).catch(function (err) {
                if (isAiEndpointFailure(err)) {
                    spell.aiBlockedUntil = Date.now() + SPELL_RETRY_MS;
                    return callBasic();
                }
                throw err;
            });

        req.then(function (data) {
            const text = data && typeof data.text === "string" ? data.text : "";
            const isFallback = !!(data && data.fallback);
            if (isFallback) {
                spell.aiBlockedUntil = Date.now() + SPELL_RETRY_MS;
            }
            if (!text || text === sourceText || (data && data.changed === false)) {
                toast(isFallback ? "KI nicht erreichbar – Basis-Prüfung fand keine eindeutigen Tippfehler." : "Keine Rechtschreib- oder Tippfehler gefunden.", "info");
                return;
            }
            const inserted = replaceRangeAndSelect(targetRange, text);
            if (inserted) {
                ai.range = inserted;
                ai.original = sourceText;
                ai.current = text;
            }
            scheduleMailSpellcheck();
            toast(isFallback ? "KI-Endpunkt nicht erreichbar – Basis-Korrektur ohne KI angewendet." : (useBasic ? "Basis-Korrektur angewendet." : "Auswahl korrigiert."), "success");
        }).catch(function (err) {
            toast(messageOf(err), "error");
        }).then(function () {
            ai.proofBusy = false;
        });
    }

    // ---------------------------------------------------------------- Kontextmenü

    function menuItems() {
        const range = state.range || selectionRange();
        const selText = range ? range.toString() : "";
        const hasSelection = selText.trim().length > 0;
        const hasMultiWord = hasSelection && /\s/.test(selText.trim());
        const items = [];

        mailSpellItems(hasMultiWord).forEach(function (it) { items.push(it); });

        items.push(
            { label: "Rückgängig", key: MOD + "Z", run: function () { restoreRange(state.range); execCommand("undo"); } },
            { label: "Wiederholen", key: MOD + "Y", run: function () { restoreRange(state.range); execCommand("redo"); } },
            { separator: true },
            { label: "Ausschneiden", key: MOD + "X", disabled: !hasSelection, run: function () { restoreRange(state.range); execCommand("cut"); } },
            { label: "Kopieren", key: MOD + "C", disabled: !hasSelection, run: function () { restoreRange(state.range); execCommand("copy"); } },
            { label: "Einfügen", key: MOD + "V", run: pasteClipboard },
            { separator: true },
            { label: "Alles auswählen", key: MOD + "A", run: selectAll }
        );

        if (hasSelection) {
            items.push({ separator: true });
            if (aiAvailable()) {
                items.push({
                    label: "Auswahl korrigieren",
                    ai: true,
                    run: function () { proofreadMailRange(range, false); }
                });
            } else {
                items.push({
                    label: "Basis-Korrektur (Auswahl)",
                    run: function () { proofreadMailRange(range, true); }
                });
            }
            if (AI_ENABLED) {
                items.push({ label: "Mit KI verbessern …", ai: true, run: function () { openAi(range, false); } });
            }
        } else if (ai.range && ai.current !== "") {
            items.push({ separator: true });
            if (AI_ENABLED) {
                items.push({ label: "KI-Vorschlag weiter verfeinern …", ai: true, run: function () { openAi(ai.range, true); } });
            }
            items.push({
                label: "Auf Original zurücksetzen",
                run: function () {
                    replaceRangeAndSelect(ai.range, ai.original);
                    ai.range = null;
                    ai.original = "";
                    ai.current = "";
                    scheduleMailSpellcheck();
                    toast("Originaltext wiederhergestellt.", "success");
                }
            });
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
        const fallbackRect = editor.getBoundingClientRect();
        const safeX = Number.isFinite(x) ? x : fallbackRect.left + 16;
        const safeY = Number.isFinite(y) ? y : fallbackRect.top + 32;
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

    if (editor) {
        editor.addEventListener("contextmenu", function (event) {
            const range = selectionRange();
            state.range = range ? range.cloneRange() : null;
            state.target = wordTargetAtPoint(event.clientX, event.clientY);
            state.showSynonymsFor = "";
            const items = cleanItems(menuItems());
            if (!items.length) {
                return;
            }
            event.preventDefault();
            showMenu(event.clientX, event.clientY, items);
        });

        editor.addEventListener("paste", function () {
            window.setTimeout(function () {
                applyInlineStyles(editor);
                scheduleMailSpellcheck();
            }, 0);
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
            '<div class="dialog-header"><h2><span data-hd-mail-ai-title>Mit KI verbessern</span></h2>',
            '<button type="button" class="btn btn-ghost btn-sm" data-hd-mail-ai-close aria-label="Schließen">&times;</button></div>',
            '<div class="dialog-body">',
            '<p class="hd-ai-excerpt" data-hd-mail-ai-excerpt></p>',
            '<div class="form-group"><label for="hd-mail-ai-prompt">Anweisung</label>',
            '<textarea id="hd-mail-ai-prompt" rows="3" maxlength="' + MAX_PROMPT + '" placeholder="z. B. höflicher formulieren, kürzer fassen, Rechtschreibung korrigieren"></textarea></div>',
            '<div class="hd-ai-chips" data-hd-mail-ai-chips></div>',
            '<p class="hd-ai-note text-xs text-muted">Der markierte Text wird an die hinterlegte KI gesendet. Fällt der Endpunkt aus, steht die lokale Basis-Korrektur ohne KI bereit.</p>',
            '<div class="alert alert-danger" data-hd-mail-ai-error-wrap hidden><div data-hd-mail-ai-error></div>',
            '<div class="hd-ai-error-actions"><button type="button" class="btn btn-secondary btn-sm" data-hd-mail-ai-fallback hidden>Basis-Korrektur ohne KI anwenden</button></div></div>',
            '</div>',
            '<div class="dialog-footer"><button type="button" class="btn btn-secondary" data-hd-mail-ai-close>Abbrechen</button>',
            '<button type="button" class="btn btn-primary" data-hd-mail-ai-submit>Vorschlag erzeugen</button></div>'
        ].join("");
        root.appendChild(dialog);

        const promptInput = dialog.querySelector("#hd-mail-ai-prompt");
        const chips = dialog.querySelector("[data-hd-mail-ai-chips]");
        CHIPS.forEach(function (chip) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "btn btn-ghost btn-sm";
            button.textContent = chip[0];
            button.addEventListener("click", function () {
                const current = promptInput.value.trim();
                promptInput.value = current ? current + " " + chip[1] : chip[1];
                promptInput.focus();
            });
            chips.appendChild(button);
        });

        dialog.querySelector("[data-hd-mail-ai-fallback]").addEventListener("click", function () {
            const targetRange = ai.range ? ai.range.cloneRange() : null;
            dialog.close();
            if (targetRange) {
                proofreadMailRange(targetRange, true);
            }
        });

        dialog.querySelectorAll("[data-hd-mail-ai-close]").forEach(function (button) {
            button.addEventListener("click", function () { dialog.close(); });
        });
        dialog.querySelector("[data-hd-mail-ai-submit]").addEventListener("click", submitAi);

        ai.dialog = dialog;
        return dialog;
    }

    function openAi(range, isRefine) {
        const dialog = ensureAiDialog();
        const text = isRefine ? ai.original : (range ? range.toString() : "");
        if (!text || !text.trim()) {
            toast("Bitte zuerst einen Text markieren.", "warning");
            return;
        }
        if (text.length > MAX_TEXT) {
            toast("Der markierte Text ist zu lang (höchstens " + MAX_TEXT + " Zeichen).", "warning");
            return;
        }
        ai.range = range.cloneRange();
        if (!isRefine) {
            ai.original = text;
            ai.current = "";
        }
        dialog.querySelector("[data-hd-mail-ai-title]").textContent = isRefine ? "Weiter verfeinern" : "Mit KI verbessern";
        dialog.querySelector("[data-hd-mail-ai-excerpt]").textContent = "„" + (text.length > 220 ? text.slice(0, 220) + "…" : text) + "“";
        dialog.querySelector("#hd-mail-ai-prompt").value = "";
        dialog.querySelector("[data-hd-mail-ai-error-wrap]").hidden = true;
        dialog.querySelector("[data-hd-mail-ai-fallback]").hidden = true;
        dialog.showModal();
        dialog.querySelector("#hd-mail-ai-prompt").focus();
    }

    function submitAi() {
        if (ai.busy || !ai.dialog) {
            return;
        }
        const dialog = ai.dialog;
        const promptEl = dialog.querySelector("#hd-mail-ai-prompt");
        const errWrap = dialog.querySelector("[data-hd-mail-ai-error-wrap]");
        const errEl = dialog.querySelector("[data-hd-mail-ai-error]");
        const fallbackBtn = dialog.querySelector("[data-hd-mail-ai-fallback]");
        const submit = dialog.querySelector("[data-hd-mail-ai-submit]");
        const prompt = promptEl.value.trim();
        if (prompt === "") {
            errEl.textContent = "Bitte eine Anweisung eingeben.";
            fallbackBtn.hidden = true;
            errWrap.hidden = false;
            promptEl.focus();
            return;
        }
        ai.busy = true;
        submit.disabled = true;
        submit.textContent = "Wird erzeugt …";
        errWrap.hidden = true;
        api(AI_ENDPOINT, {
            method: "POST",
            body: {
                text: ai.original,
                prompt: prompt,
                mode: "ticket_mail",
                previous: ai.current || null,
                subject: subjectField ? subjectField.value : "",
                ticket: TICKET
            }
        }).then(function (data) {
            const text = data && typeof data.text === "string" ? data.text : "";
            if (text === "") {
                throw new Error("Die KI hat keine Antwort geliefert.");
            }
            const targetRange = ai.range ? ai.range.cloneRange() : null;
            // Zuerst schließen, damit der dahinterliegende Editor nicht mehr inert ist
            dialog.close();
            const inserted = replaceRangeAndSelect(targetRange, text);
            if (inserted) {
                ai.range = inserted;
                ai.current = text;
            }
            scheduleMailSpellcheck();
            toast("Vorschlag eingefügt.", "success");
        }).catch(function (error) {
            const endpointDown = isAiEndpointFailure(error);
            if (endpointDown) {
                spell.aiBlockedUntil = Date.now() + SPELL_RETRY_MS;
            }
            errEl.textContent = messageOf(error);
            fallbackBtn.hidden = !endpointDown;
            errWrap.hidden = false;
        }).then(function () {
            ai.busy = false;
            submit.disabled = false;
            submit.textContent = "Vorschlag erzeugen";
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

    // ---------------------------------------------------------------- Weitere Empfänger

    const extraRoot = root.querySelector("[data-hd-mail-extra]");
    const extraPicker = extraRoot ? extraRoot.querySelector("[data-picker]") : null;
    const extraList = root.querySelector("[data-hd-mail-extra-list]");
    const extraInputs = root.querySelector("[data-hd-mail-extra-inputs]");
    const extraRecipients = [];

    /** Empfängerliste als Chips und als Formularfelder (additional_recipients[]) ausgeben. */
    function renderExtra() {
        if (!extraList || !extraInputs) {
            return;
        }
        extraList.textContent = "";
        extraRecipients.forEach(function (person, index) {
            const entry = document.createElement("li");
            entry.className = "hd-mail-extra-chip";
            const name = document.createElement("span");
            name.className = "hd-mail-extra-name";
            name.textContent = person.name;
            const mail = document.createElement("span");
            mail.className = "hd-mail-extra-mail";
            mail.textContent = person.email;
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "hd-mail-extra-remove";
            remove.setAttribute("data-hd-mail-extra-remove", String(index));
            remove.setAttribute("aria-label", person.name + " entfernen");
            remove.textContent = "×";
            entry.appendChild(name);
            entry.appendChild(mail);
            entry.appendChild(remove);
            extraList.appendChild(entry);
        });
        extraList.hidden = extraRecipients.length === 0;

        extraInputs.textContent = "";
        extraRecipients.forEach(function (person) {
            const field = document.createElement("input");
            field.type = "hidden";
            field.name = "additional_recipients[]";
            field.value = String(person.id);
            extraInputs.appendChild(field);
        });
    }

    function resetExtraPicker() {
        const clear = extraPicker ? extraPicker.querySelector("[data-picker-clear]") : null;
        if (clear) {
            clear.click();
        }
    }

    if (extraPicker) {
        // Die Personenauswahl liefert E-Mail und ID; die Auswahl selbst führt die
        // Liste, damit mehrere Empfänger möglich sind. Der Picker wird danach
        // zurückgesetzt und meldet das mit detail = null.
        extraPicker.addEventListener("picker:change", function (event) {
            const person = event.detail;
            if (!person || !person.id) {
                return;
            }
            const name = person.name || person.label || "";
            if (extraRecipients.some(function (entry) { return entry.id === person.id; })) {
                showError(name + " ist bereits als Empfänger eingetragen.");
                resetExtraPicker();
                return;
            }
            if (!person.email) {
                showError("Für " + name + " ist keine E-Mail-Adresse hinterlegt.");
                resetExtraPicker();
                return;
            }
            extraRecipients.push({ id: person.id, name: name, email: person.email });
            renderExtra();
            if (errorEl) {
                errorEl.hidden = true;
            }
            resetExtraPicker();
        });
    }

    if (extraList) {
        extraList.addEventListener("click", function (event) {
            const button = event.target.closest("[data-hd-mail-extra-remove]");
            if (!button) {
                return;
            }
            extraRecipients.splice(Number(button.getAttribute("data-hd-mail-extra-remove")), 1);
            renderExtra();
        });
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
            const content = editorContent();
            if (content.text === "" && !content.hasImage) {
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
