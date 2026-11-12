/*
 * Help Desk: Erwähnungen per „@Name“ in internen Notizen und Aufgabenkommentaren.
 * Sobald ein „@“ am Wortanfang getippt wird, erscheint eine Vorschlagsliste aller Agenten; sie
 * filtert sich mit jedem weiteren Buchstaben (Anzeige- und Benutzername). Pfeiltasten wählen,
 * Enter/Tab übernimmt, Escape schließt. Die Agentenliste liegt als JSON im Attribut
 * data-hd-mentions am Textfeld – es ist keine Serveranfrage nötig. Bei Tickets ist die Liste nur
 * aktiv, solange „Interne Notiz“ ausgewählt ist.
 */
(function () {
    "use strict";

    var MAX_QUERY = 60;
    var BOUNDARY = /[\s([{"'„“‚‘>]/;
    var MIRROR_PROPS = [
        "boxSizing", "width", "borderTopWidth", "borderRightWidth", "borderBottomWidth", "borderLeftWidth",
        "paddingTop", "paddingRight", "paddingBottom", "paddingLeft", "fontStyle", "fontVariant", "fontWeight",
        "fontStretch", "fontSize", "lineHeight", "fontFamily", "textAlign", "textTransform", "textIndent",
        "letterSpacing", "wordSpacing", "tabSize"
    ];

    function normalize(value) {
        return String(value || "").toLocaleLowerCase("de-DE");
    }

    /** Pixelposition des Cursors innerhalb des Textfelds (Spiegel-Element-Verfahren). */
    function caretPosition(textarea, index) {
        var style = window.getComputedStyle(textarea);
        var mirror = document.createElement("div");
        MIRROR_PROPS.forEach(function (prop) { mirror.style[prop] = style[prop]; });
        mirror.style.position = "absolute";
        mirror.style.visibility = "hidden";
        mirror.style.top = "0";
        mirror.style.left = "-9999px";
        mirror.style.whiteSpace = "pre-wrap";
        mirror.style.overflowWrap = "break-word";
        mirror.style.overflow = "hidden";
        mirror.textContent = textarea.value.slice(0, index);
        var marker = document.createElement("span");
        marker.textContent = "\u200b";
        mirror.appendChild(marker);
        document.body.appendChild(mirror);
        var lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.4 || 20;
        var pos = { top: marker.offsetTop - textarea.scrollTop, left: marker.offsetLeft - textarea.scrollLeft, lineHeight: lineHeight };
        document.body.removeChild(mirror);
        return pos;
    }

    function init(textarea) {
        var agents;
        try {
            agents = JSON.parse(textarea.getAttribute("data-hd-mentions") || "[]");
        } catch (e) {
            agents = [];
        }
        if (!Array.isArray(agents) || agents.length === 0) {
            return;
        }
        agents.forEach(function (agent) {
            agent.nameKey = normalize(agent.name);
            agent.userKey = normalize(agent.username);
        });

        var form = textarea.form;
        var host = textarea.parentElement;
        host.classList.add("hd-mention-host");

        var list = document.createElement("ul");
        list.className = "picker-results hd-mention-results";
        list.setAttribute("role", "listbox");
        list.setAttribute("aria-label", "Agenten erwähnen");
        list.id = (textarea.id || "hd-mention") + "-mentions";
        list.hidden = true;
        host.appendChild(list);
        textarea.setAttribute("aria-autocomplete", "list");
        textarea.setAttribute("aria-controls", list.id);

        var state = null; // { at, query, items, active }
        var dismissedAt = -1;

        function mentionsAllowed() {
            if (!form) {
                return true;
            }
            var type = form.querySelector('input[name="type"]:checked');
            return type === null || type.value === "internal";
        }

        /** Sucht ein „@…“ unmittelbar vor dem Cursor; null, wenn keines aktiv ist. */
        function context() {
            if (textarea.selectionStart !== textarea.selectionEnd) {
                return null;
            }
            var pos = textarea.selectionStart;
            var before = textarea.value.slice(Math.max(0, pos - MAX_QUERY - 1), pos);
            var idx = before.lastIndexOf("@");
            if (idx === -1) {
                return null;
            }
            var at = pos - before.length + idx;
            var prev = at > 0 ? textarea.value.charAt(at - 1) : "";
            if (prev !== "" && !BOUNDARY.test(prev)) {
                return null; // z. B. E-Mail-Adresse
            }
            var query = textarea.value.slice(at + 1, pos);
            if (/[\n\r@]/.test(query) || /\s\s/.test(query) || /^\s/.test(query)) {
                return null;
            }
            return { at: at, query: query };
        }

        function filter(query) {
            var q = normalize(query).trim();
            if (q === "") {
                return agents.slice();
            }
            var starts = [];
            var contains = [];
            agents.forEach(function (agent) {
                if (agent.nameKey.indexOf(q) === 0 || agent.userKey.indexOf(q) === 0
                    || agent.nameKey.split(/\s+/).some(function (part) { return part.indexOf(q) === 0; })) {
                    starts.push(agent);
                } else if (agent.nameKey.indexOf(q) !== -1 || agent.userKey.indexOf(q) !== -1) {
                    contains.push(agent);
                }
            });
            return starts.concat(contains);
        }

        function close() {
            state = null;
            list.hidden = true;
            list.textContent = "";
            textarea.removeAttribute("aria-activedescendant");
            textarea.setAttribute("aria-expanded", "false");
        }

        function position() {
            var caret = caretPosition(textarea, state.at);
            var width = Math.min(288, textarea.clientWidth);
            var left = textarea.offsetLeft + Math.max(0, Math.min(caret.left, textarea.clientWidth - width));
            var top = textarea.offsetTop + Math.min(caret.top + caret.lineHeight, textarea.clientHeight) + 4;
            list.style.left = left + "px";
            list.style.top = top + "px";
            list.style.width = width + "px";
        }

        function highlight() {
            Array.prototype.forEach.call(list.querySelectorAll("button"), function (btn, i) {
                var active = i === state.active;
                btn.classList.toggle("is-active", active);
                btn.setAttribute("aria-selected", active ? "true" : "false");
                if (active) {
                    textarea.setAttribute("aria-activedescendant", btn.id);
                    btn.scrollIntoView({ block: "nearest" });
                }
            });
        }

        function render() {
            list.textContent = "";
            if (state.items.length === 0) {
                var empty = document.createElement("li");
                empty.className = "picker-empty";
                empty.textContent = "Kein Agent gefunden";
                list.appendChild(empty);
            }
            state.items.forEach(function (agent, i) {
                var li = document.createElement("li");
                var btn = document.createElement("button");
                btn.type = "button";
                btn.id = list.id + "-" + i;
                btn.setAttribute("role", "option");
                btn.tabIndex = -1;
                var name = document.createElement("span");
                name.textContent = agent.name;
                var meta = document.createElement("span");
                meta.className = "result-meta";
                meta.textContent = "@" + agent.username;
                btn.appendChild(name);
                btn.appendChild(meta);
                btn.addEventListener("mousedown", function (e) { e.preventDefault(); });
                btn.addEventListener("click", function () { select(agent); });
                li.appendChild(btn);
                list.appendChild(li);
            });
            list.hidden = false;
            textarea.setAttribute("aria-expanded", "true");
            position();
            highlight();
        }

        function update() {
            var ctx = mentionsAllowed() ? context() : null;
            if (ctx === null) {
                dismissedAt = -1;
                close();
                return;
            }
            if (ctx.at === dismissedAt) {
                close();
                return;
            }
            var items = filter(ctx.query);
            // Mit Leerzeichen im Suchbegriff ohne Treffer ist die Erwähnung offensichtlich beendet.
            if (items.length === 0 && /\s/.test(ctx.query)) {
                close();
                return;
            }
            // Auswahl nur behalten, solange sich der Suchbegriff nicht ändert; sonst ist der beste Treffer aktiv.
            var previous = state !== null && state.at === ctx.at && state.query === ctx.query ? state.items[state.active] : null;
            var active = previous ? items.indexOf(previous) : -1;
            state = { at: ctx.at, query: ctx.query, items: items, active: active >= 0 ? active : 0 };
            render();
        }

        function select(agent) {
            if (state === null) {
                return;
            }
            var end = textarea.selectionStart;
            var after = textarea.value.slice(end);
            var insert = "@" + agent.name + (after.charAt(0) === " " ? "" : " ");
            textarea.value = textarea.value.slice(0, state.at) + insert + after;
            var caret = state.at + insert.length + (after.charAt(0) === " " ? 1 : 0);
            textarea.setSelectionRange(caret, caret);
            // Übernommene Erwähnung nicht erneut vorschlagen (das folgende input-Event fände sie sonst wieder).
            dismissedAt = state.at;
            close();
            textarea.focus();
            textarea.dispatchEvent(new Event("input", { bubbles: true }));
        }

        textarea.addEventListener("keydown", function (e) {
            if (state === null) {
                return;
            }
            if (e.key === "ArrowDown" || e.key === "ArrowUp") {
                if (state.items.length > 0) {
                    e.preventDefault();
                    var step = e.key === "ArrowDown" ? 1 : -1;
                    state.active = (state.active + step + state.items.length) % state.items.length;
                    highlight();
                }
            } else if ((e.key === "Enter" || e.key === "Tab") && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
                if (state.items.length > 0) {
                    e.preventDefault();
                    select(state.items[state.active]);
                }
            } else if (e.key === "Escape") {
                e.preventDefault();
                e.stopPropagation();
                dismissedAt = state.at;
                close();
            }
        });
        textarea.addEventListener("input", update);
        textarea.addEventListener("keyup", function (e) {
            if (["ArrowLeft", "ArrowRight", "Home", "End", "PageUp", "PageDown"].indexOf(e.key) !== -1) {
                update();
            }
        });
        textarea.addEventListener("click", update);
        textarea.addEventListener("scroll", function () { if (state !== null) { position(); } });
        textarea.addEventListener("blur", function () { window.setTimeout(close, 150); });
        if (form) {
            form.addEventListener("change", function (e) {
                if (e.target && e.target.name === "type") {
                    update();
                }
            });
            form.addEventListener("submit", close);
        }
        textarea.setAttribute("aria-expanded", "false");
    }

    document.querySelectorAll("textarea[data-hd-mentions]").forEach(init);
})();
