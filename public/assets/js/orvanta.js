/* Orvanta – Mail- und Kalender-Client (Vanilla JS, kein Build-Schritt). */
(function () {
    'use strict';

    var root = document.querySelector('[data-orvanta]');
    if (!root) {
        return;
    }

    var config = {};
    try {
        config = JSON.parse(root.getAttribute('data-config') || '{}');
    } catch (e) {
        config = {};
    }
    var csrf = root.getAttribute('data-csrf') || '';
    var API = '/api/orvanta';
    var PREF_KEY = 'orvanta.prefs';

    var state = {
        module: root.getAttribute('data-module') || config.defaultModule || 'mail',
        folders: [],
        folder: 'inbox',
        folderName: 'Posteingang',
        messages: [],
        total: 0,
        hasMore: false,
        selected: null,
        selectedIds: [],
        dragIds: null,
        filter: 'all',
        search: '',
        calView: 'week',
        calDate: startOfDay(new Date()),
        events: [],
        contacts: [],
        tasks: [],
        tasksCompleted: false,
        notes: [],
        reminders: { due: [], active: [] },
        snoozed: {},
        compose: null,
        standalone: false,
        online: true,
        noteDraft: false,
        archive: null,
        archiveFolders: [],
        prefs: loadPrefs()
    };

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    function $(selector, scope) {
        return (scope || root).querySelector(selector);
    }

    function $$(selector, scope) {
        return Array.prototype.slice.call((scope || root).querySelectorAll(selector));
    }

    function hook(name, scope) {
        if (name.indexOf('form-') === 0) {
            return $('[data-ov-form="' + name.slice(5) + '"]', scope);
        }
        return $('[data-ov-' + name + ']', scope);
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * Inline-Styles aus (serverseitig bereinigtem) Mail-HTML in ein Datenattribut
     * verschieben: Die Content-Security-Policy erlaubt keine style-Attribute,
     * wohl aber Zuweisungen ueber das CSSOM (element.style).
     */
    function inlineStylesToCssom(html) {
        // Textersetzung statt DOMParser: auch inerte Dokumente melden CSP-Verstoesse.
        // Der Server liefert serialisiertes HTML mit doppelt gequoteten Attributen.
        return String(html).replace(/\sstyle=("[^"]*"|'[^']*')/gi, ' data-ov-style=$1');
    }

    /**
     * Bereinigtes HTML in einen contenteditable-Editor laden (Inline-Styles
     * ueber das CSSOM setzen) bzw. den Inhalt zurueckgeben.
     */
    function setEditorHtml(editor, html) {
        if (!editor) {
            return;
        }
        editor.innerHTML = String(html || '').trim() === '' ? '<p><br></p>' : inlineStylesToCssom(html);
        editor.querySelectorAll('[data-ov-style]').forEach(function (node) {
            node.style.cssText = node.getAttribute('data-ov-style');
            node.removeAttribute('data-ov-style');
        });
    }

    /**
     * Fest zugeordnete Signatur in den Editor einfuegen: schreibgeschuetzter
     * Block (contenteditable=false) vor einem Zitat bzw. am Ende. Vorhandene
     * Bloecke (z. B. aus einem Entwurf) werden ersetzt; massgeblich ist ohnehin
     * die serverseitig beim Senden/Speichern angefuegte Fassung.
     */
    function renderComposeSignature(editor) {
        editor = editor || hook('compose-body');
        if (!editor) {
            return;
        }
        stripSignatureBlocks(editor);
        var signature = config.signature && config.signature.html ? config.signature.html : '';
        if (signature === '') {
            return;
        }
        var holder = document.createElement('div');
        holder.innerHTML = inlineStylesToCssom(signature);
        holder.querySelectorAll('[data-ov-style]').forEach(function (node) {
            node.style.cssText = node.getAttribute('data-ov-style');
            node.removeAttribute('data-ov-style');
        });
        var block = holder.querySelector('.ov-signature-block') || holder.firstElementChild;
        if (!block) {
            return;
        }
        block.setAttribute('contenteditable', 'false');
        var quote = editor.querySelector(':scope > .ov-quote');
        if (quote) {
            editor.insertBefore(block, quote);
        } else {
            editor.appendChild(block);
        }
        if (!block.previousElementSibling) {
            editor.insertBefore(el('p', { html: '<br>' }), block);
        }
        editor.setAttribute('data-ov-signature-html', block.outerHTML);
    }

    /**
     * Signaturbloecke aus dem Editor entfernen (vor dem erneuten Einfuegen).
     */
    function stripSignatureBlocks(editor) {
        if (!editor) {
            return;
        }
        editor.querySelectorAll('.ov-signature-block').forEach(function (node) { node.remove(); });
        if (editor.innerHTML.trim() === '') {
            editor.innerHTML = '<p><br></p>';
        }
    }

    /**
     * Schreibschutz der Signatur im Editor: Eingaben, die den Block beruehren,
     * werden verworfen; wurde er dennoch entfernt (z. B. "Alles auswaehlen"
     * + Entf), wird er wieder eingefuegt.
     */
    function guardComposeSignature(editor) {
        if (!editor) {
            return;
        }
        editor.addEventListener('beforeinput', function (event) {
            var block = editor.querySelector('.ov-signature-block');
            var selection = window.getSelection();
            if (!block || !selection || !selection.rangeCount) {
                return;
            }
            var range = selection.getRangeAt(0);
            var inside = block.contains(range.startContainer) || block.contains(range.endContainer);
            if (!inside && (range.collapsed || !range.intersectsNode(block))) {
                return;
            }
            event.preventDefault();
            if (range.collapsed && inside) {
                return;
            }
            // Nur die Teile der Auswahl vor und hinter der Signatur bearbeiten
            // (z. B. "Alles auswaehlen" + Entf).
            var blockRange = document.createRange();
            blockRange.selectNode(block);
            var before = null;
            var after = null;
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
            editor.querySelectorAll(':scope > p:empty').forEach(function (p) { p.innerHTML = '<br>'; });
            if (!block.previousElementSibling) {
                editor.insertBefore(el('p', { html: '<br>' }), block);
            }
            var caret = document.createRange();
            if (before) {
                caret.setStart(before.startContainer, before.startOffset);
            } else {
                caret.setStart(block.previousElementSibling, 0);
            }
            caret.collapse(true);
            selection.removeAllRanges();
            selection.addRange(caret);
            if (event.inputType === 'insertText' && typeof event.data === 'string') {
                document.execCommand('insertText', false, event.data);
            } else if (event.inputType === 'insertParagraph') {
                document.execCommand('insertParagraph');
            } else {
                editor.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
        editor.addEventListener('input', function () {
            if (!config.signature || !config.signature.html) {
                return;
            }
            var block = editor.querySelector('.ov-signature-block');
            // Entfernt oder (z. B. per Formatierungsbefehl) veraendert: Original wiederherstellen.
            if (!block || block.outerHTML !== editor.getAttribute('data-ov-signature-html')) {
                renderComposeSignature(editor);
            }
        });
    }

    function editorHtml(editor) {
        if (!editor) {
            return '';
        }
        var text = (editor.textContent || '').replace(/\u00a0/g, ' ').trim();
        return text === '' && !editor.querySelector('img, li') ? '' : editor.innerHTML;
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'class') {
                node.className = attrs[key];
            } else if (key === 'text') {
                node.textContent = attrs[key];
            } else if (key === 'html') {
                node.innerHTML = attrs[key];
            } else if (key === 'style') {
                // CSSOM statt style-Attribut (Content-Security-Policy ohne unsafe-inline).
                node.style.cssText = attrs[key];
            } else if (attrs[key] !== null && attrs[key] !== undefined && attrs[key] !== false) {
                node.setAttribute(key, attrs[key] === true ? '' : attrs[key]);
            }
        });
        (children || []).forEach(function (child) {
            if (child === null || child === undefined) {
                return;
            }
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return node;
    }

    function loadPrefs() {
        var defaults = { dense: false, preview: true, images: false, notify: true, sound: false, folders: true, reading: true };
        try {
            var stored = JSON.parse(window.localStorage.getItem(PREF_KEY) || '{}');
            Object.keys(stored).forEach(function (key) {
                defaults[key] = stored[key];
            });
        } catch (e) {
            /* ignorieren */
        }
        return defaults;
    }

    function savePrefs() {
        try {
            window.localStorage.setItem(PREF_KEY, JSON.stringify(state.prefs));
        } catch (e) {
            /* ignorieren */
        }
    }

    function startOfDay(date) {
        var d = new Date(date.getTime());
        d.setHours(0, 0, 0, 0);
        return d;
    }

    function addDays(date, days) {
        var d = new Date(date.getTime());
        d.setDate(d.getDate() + days);
        return d;
    }

    function startOfWeek(date) {
        var d = startOfDay(date);
        var day = (d.getDay() + 6) % 7;
        return addDays(d, -day);
    }

    function sameDay(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function toTs(date) {
        return Math.floor(date.getTime() / 1000);
    }

    function fromTs(ts) {
        return new Date((ts || 0) * 1000);
    }

    var MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    var DAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    var DAYS_LONG = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    function fmtTime(ts) {
        var d = fromTs(ts);
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function fmtDate(ts) {
        var d = fromTs(ts);
        return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear();
    }

    function fmtDateTime(ts) {
        return fmtDate(ts) + ' ' + fmtTime(ts);
    }

    function fmtListDate(ts) {
        if (!ts) {
            return '';
        }
        var d = fromTs(ts);
        var now = new Date();
        if (sameDay(d, now)) {
            return fmtTime(ts);
        }
        if (sameDay(d, addDays(now, -1))) {
            return 'Gestern';
        }
        if (now.getTime() - d.getTime() < 6 * 86400000) {
            return DAYS_LONG[(d.getDay() + 6) % 7];
        }
        return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + String(d.getFullYear()).slice(2);
    }

    function fmtBytes(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes >= 1073741824) {
            return (bytes / 1073741824).toFixed(2).replace('.', ',') + ' GB';
        }
        if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
        }
        if (bytes >= 1024) {
            return Math.round(bytes / 1024) + ' KB';
        }
        return bytes + ' B';
    }

    function toLocalInput(ts) {
        var d = fromTs(ts);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function toDateInput(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function fromLocalInput(value) {
        if (!value) {
            return 0;
        }
        var d = new Date(value);
        return isNaN(d.getTime()) ? 0 : toTs(d);
    }

    function mailboxName(box) {
        if (!box) {
            return '';
        }
        return box.name || box.email || '';
    }

    function mailboxFull(box) {
        if (!box) {
            return '';
        }
        if (box.name && box.email && box.name !== box.email) {
            return box.name + ' <' + box.email + '>';
        }
        return box.email || box.name || '';
    }

    function initials(name) {
        var parts = String(name || '?').trim().split(/[\s@._-]+/).filter(Boolean);
        var out = parts.slice(0, 2).map(function (p) {
            return p.charAt(0).toUpperCase();
        }).join('');
        return out || '?';
    }

    // Trennt an ; , oder Zeilenumbruch – aber nicht innerhalb von "…" oder <…>.
    // Ein Komma trennt nur, wenn das bisherige Segment schon eine Adresse
    // enthaelt, damit Anzeigenamen wie "Müller, Steffen <…>" erhalten bleiben.
    function splitRecipients(value) {
        var parts = [];
        var current = '';
        var quoted = false;
        var angle = false;
        String(value || '').split('').forEach(function (ch) {
            if (ch === '"' && !angle) {
                quoted = !quoted;
            } else if (ch === '<' && !quoted) {
                angle = true;
            } else if (ch === '>' && !quoted) {
                angle = false;
            } else if (!quoted && !angle && (ch === ';' || ch === '\n' || (ch === ',' && current.indexOf('@') !== -1))) {
                parts.push(current);
                current = '';
                return;
            }
            current += ch;
        });
        parts.push(current);
        return parts;
    }

    function parseRecipients(value) {
        return splitRecipients(value).map(function (part) {
            part = part.trim();
            var match = part.match(/^(.*?)\s*<([^>]+)>$/);
            if (match) {
                return { name: match[1].replace(/^"|"$/g, ''), email: match[2].trim() };
            }
            return part ? { name: '', email: part } : null;
        }).filter(Boolean);
    }

    // ------------------------------------------------------------------
    // API
    // ------------------------------------------------------------------

    function api(path, options) {
        options = options || {};
        var init = {
            method: options.method || 'GET',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin'
        };
        var url = API + path;
        if (options.query) {
            var params = [];
            Object.keys(options.query).forEach(function (key) {
                if (options.query[key] !== undefined && options.query[key] !== null && options.query[key] !== '') {
                    params.push(encodeURIComponent(key) + '=' + encodeURIComponent(options.query[key]));
                }
            });
            if (params.length) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + params.join('&');
            }
        }
        if (options.body !== undefined) {
            init.method = options.method || 'POST';
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        return window.fetch(url, init).then(function (response) {
            return response.text().then(function (text) {
                var data = {};
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (e) {
                    data = { error: 'Ungültige Antwort des Servers.' };
                }
                if (!response.ok) {
                    var error = new Error(data.error || data.message || ('Fehler ' + response.status));
                    error.status = response.status;
                    error.data = data;
                    throw error;
                }
                setOnline(true);
                return data;
            });
        }).catch(function (error) {
            if (error instanceof TypeError) {
                setOnline(false);
                throw new Error('Keine Verbindung zum Server.');
            }
            if (error.status === 502 || error.status === 503 || error.status === 504) {
                setOnline(false);
            }
            throw error;
        });
    }

    function setOnline(online) {
        state.online = online;
        var conn = hook('status-conn');
        if (conn) {
            conn.textContent = online ? (config.demo ? 'Demo-Postfach' : 'Verbunden mit Exchange') : 'Keine Verbindung';
            conn.classList.toggle('ov-status__item--error', !online);
        }
    }

    function setStatus(text) {
        var node = hook('status-text');
        if (node) {
            node.textContent = text || '';
        }
    }

    function setCount(text) {
        var node = hook('status-count');
        if (node) {
            node.textContent = text || '';
        }
    }

    function markSync() {
        var node = hook('status-sync');
        if (node) {
            node.textContent = 'Zuletzt aktualisiert ' + pad(new Date().getHours()) + ':' + pad(new Date().getMinutes());
        }
    }

    // ------------------------------------------------------------------
    // Toasts & Dialoge
    // ------------------------------------------------------------------

    function toast(message, type, actions) {
        var host = hook('toasts');
        if (!host) {
            return;
        }
        var node = el('div', { 'class': 'ov-toast ov-toast--' + (type || 'info'), role: 'status' }, [
            el('div', { 'class': 'ov-toast__text', text: message })
        ]);
        if (actions && actions.length) {
            var bar = el('div', { 'class': 'ov-toast__actions' });
            actions.forEach(function (action) {
                var button = el('button', { type: 'button', 'class': 'ov-toast__button', text: action.label });
                button.addEventListener('click', function () {
                    action.run();
                    node.remove();
                });
                bar.appendChild(button);
            });
            node.appendChild(bar);
        }
        var close = el('button', { type: 'button', 'class': 'ov-toast__close', 'aria-label': 'Schließen', text: '×' });
        close.addEventListener('click', function () {
            node.remove();
        });
        node.appendChild(close);
        host.appendChild(node);
        window.setTimeout(function () {
            node.classList.add('ov-toast--leaving');
            window.setTimeout(function () {
                node.remove();
            }, 400);
        }, type === 'error' ? 9000 : 5000);
    }

    function openDialog(name) {
        var dialog = $('[data-ov-dialog="' + name + '"]');
        if (!dialog) {
            return null;
        }
        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) {
                dialog.showModal();
            }
        } else {
            dialog.setAttribute('open', '');
        }
        var error = hook('form-error', dialog);
        if (error) {
            error.textContent = '';
            error.hidden = true;
        }
        var first = dialog.querySelector('input:not([type=hidden]):not([disabled]), textarea, [contenteditable]');
        if (first) {
            window.setTimeout(function () {
                first.focus();
            }, 30);
        }
        return dialog;
    }

    function closeDialog(name) {
        var dialog = typeof name === 'string' ? $('[data-ov-dialog="' + name + '"]') : name;
        if (!dialog) {
            return;
        }
        if (typeof dialog.close === 'function' && dialog.open) {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    }

    function formError(form, message) {
        var node = hook('form-error', form.closest('dialog') || form);
        if (node) {
            node.textContent = message;
            node.hidden = !message;
        } else if (message) {
            toast(message, 'error');
        }
    }

    function confirmAction(message) {
        return window.confirm(message);
    }

    // ------------------------------------------------------------------
    // Modulsteuerung
    // ------------------------------------------------------------------

    var MODULE_TITLES = { mail: 'E-Mail', calendar: 'Kalender', contacts: 'Kontakte', tasks: 'Aufgaben', notes: 'Notizen' };

    function switchModule(name) {
        if (!MODULE_TITLES[name]) {
            return;
        }
        state.module = name;
        state.selected = null;
        state.selectedIds = [];
        root.setAttribute('data-module', name);
        $$('[data-ov-module]').forEach(function (button) {
            var active = button.getAttribute('data-ov-module') === name;
            button.classList.toggle('ov-module--active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        $$('[data-ov-for]').forEach(function (panel) {
            var modules = panel.getAttribute('data-ov-for').split(/\s+/);
            panel.hidden = modules.indexOf(name) === -1 && modules.indexOf('all') === -1;
        });
        var search = hook('search');
        if (search) {
            search.placeholder = 'In ' + MODULE_TITLES[name] + ' suchen …';
            search.value = '';
        }
        state.search = '';
        showDetailEmpty();
        try {
            window.history.replaceState(null, '', '/office/orvanta?modul=' + name);
        } catch (e) {
            /* ignorieren */
        }
        loadModule();
    }

    function loadModule() {
        if (state.module === 'mail') {
            return loadFolders().then(loadMessages);
        }
        if (state.module === 'calendar') {
            renderCalendarSidebar();
            return loadCalendar();
        }
        if (state.module === 'contacts') {
            renderSimpleSidebar('Kontakte', [{ label: 'Alle Kontakte', count: state.contacts.length }]);
            return loadContacts();
        }
        if (state.module === 'tasks') {
            renderTasksSidebar();
            return loadTasks();
        }
        if (state.module === 'notes') {
            renderSimpleSidebar('Notizen', [{ label: 'Alle Notizen', count: state.notes.length }]);
            return loadNotes();
        }
        return Promise.resolve();
    }

    function showDetailEmpty() {
        var empty = hook('detail-empty');
        var body = hook('detail-body');
        if (empty) {
            empty.hidden = false;
        }
        if (body) {
            body.hidden = true;
            body.innerHTML = '';
        }
        root.classList.remove('ov--detail-open');
        updateActionState();
    }

    function showDetail(node) {
        var empty = hook('detail-empty');
        var body = hook('detail-body');
        if (empty) {
            empty.hidden = true;
        }
        if (body) {
            body.hidden = false;
            body.innerHTML = '';
            body.appendChild(node);
            body.scrollTop = 0;
        }
        root.classList.add('ov--detail-open');
        updateActionState();
    }

    function updateActionState() {
        var hasSelection = state.selected !== null || state.selectedIds.length > 0;
        var kinds = { message: 'mail', event: 'calendar', contact: 'contacts', task: 'tasks', note: 'notes' };
        $$('[data-ov-needs]').forEach(function (button) {
            var need = button.getAttribute('data-ov-needs');
            var enabled = hasSelection && kinds[need] === state.module;
            if (need === 'note') {
                enabled = state.module === 'notes' && (state.selected !== null || state.noteDraft === true);
            }
            button.disabled = !enabled;
        });
    }

    function setListTitle(title, hint) {
        var node = hook('list-title');
        if (node) {
            node.textContent = title;
        }
        var foot = hook('list-foot');
        if (foot) {
            foot.innerHTML = '';
            if (hint) {
                foot.appendChild(typeof hint === 'string' ? el('span', { text: hint }) : hint);
            }
        }
    }

    function listBody() {
        var body = hook('list-body');
        if (body) {
            body.innerHTML = '';
        }
        return body;
    }

    function listMessage(text, cls) {
        var body = listBody();
        if (body) {
            body.appendChild(el('div', { 'class': 'ov-list__empty ' + (cls || ''), text: text }));
        }
    }

    function renderSimpleSidebar(title, entries) {
        var head = hook('folders-title');
        if (head) {
            head.textContent = title;
        }
        var body = hook('folders-body');
        if (!body) {
            return;
        }
        body.innerHTML = '';
        entries.forEach(function (entry) {
            body.appendChild(el('div', { 'class': 'ov-folder ov-folder--active' }, [
                el('span', { 'class': 'ov-folder__name', text: entry.label }),
                entry.count ? el('span', { 'class': 'ov-folder__count', text: String(entry.count) }) : null
            ]));
        });
    }

    // ------------------------------------------------------------------
    // Mail: Ordner
    // ------------------------------------------------------------------

    var FOLDER_LABELS = { inbox: 'Posteingang', drafts: 'Entwürfe', sentitems: 'Gesendete Elemente', deleteditems: 'Gelöschte Elemente', junkemail: 'Junk-E-Mail', outbox: 'Postausgang' };
    var FOLDER_ICONS = { inbox: '📥', drafts: '📝', sentitems: '📤', deleteditems: '🗑', junkemail: '⚠', outbox: '📮', folder: '📁' };

    function loadFolders() {
        return api('/mail/ordner').then(function (data) {
            state.folders = data.folders || data || [];
            renderFolders();
            var inboxUnread = 0;
            state.folders.forEach(function (folder) {
                if (folder.kind === 'inbox') {
                    inboxUnread = folder.unread;
                }
            });
            var count = $('[data-ov-module-count="mail"]');
            if (count) {
                count.textContent = inboxUnread > 0 ? String(inboxUnread) : '';
                count.hidden = inboxUnread <= 0;
            }
        }).catch(function (error) {
            renderSimpleSidebar('Ordner', []);
            toast(error.message, 'error');
        }).then(function () {
            return loadArchive();
        });
    }

    // ------------------------------------------------------------------
    // Mail: Langzeitarchiv (📦)
    // ------------------------------------------------------------------

    function isArchiveFolder(key) {
        return typeof key === 'string' && key.indexOf('archive:') === 0;
    }

    /**
     * Archivstatus und -ordner laden; meldet den Abschluss eines neuen
     * Archivierungslaufs einmalig per Toast (Vergleich der Job-ID im
     * localStorage).
     */
    function loadArchive() {
        return api('/archiv/status').then(function (status) {
            state.archive = status;
            if (status.last_job && status.last_job.id) {
                var key = 'orvanta.archive.job';
                var last = '';
                try { last = window.localStorage.getItem(key) || ''; } catch (e) { /* privat */ }
                if (String(status.last_job.id) !== last) {
                    try { window.localStorage.setItem(key, String(status.last_job.id)); } catch (e) { /* privat */ }
                    if (last !== '') {
                        if (status.last_job.status === 'completed' && status.last_job.deleted_count > 0) {
                            toast('📦 Archivierung abgeschlossen: ' + status.last_job.deleted_count + ' E-Mail(s) in das Langzeitarchiv verschoben.', 'success');
                        } else if (status.last_job.status === 'failed') {
                            toast('📦 Archivierung fehlgeschlagen: ' + (status.last_job.error || 'Details im Anwendungsprotokoll.'), 'error');
                        }
                    }
                }
            }
            if (!status.exists) {
                state.archiveFolders = [];
                return null;
            }
            return api('/archiv/ordner').then(function (data) {
                state.archiveFolders = data.folders || [];
                renderArchiveFolders();
                return null;
            });
        }).catch(function () {
            state.archiveFolders = [];
        });
    }

    function renderArchiveFolders() {
        var body = hook('folders-body');
        if (!body || !state.archiveFolders.length) {
            return;
        }
        var old = body.querySelector('.ov-folders__archive');
        if (old) {
            old.remove();
        }
        var group = el('div', { 'class': 'ov-folders__archive' }, [
            el('div', { 'class': 'ov-list__group', text: '📦 Langzeitarchiv' })
        ]);
        state.archiveFolders.forEach(function (folder) {
            var key = 'archive:' + folder.id;
            var node = el('button', {
                type: 'button',
                'class': 'ov-folder ov-folder--archive' + (key === state.folder ? ' ov-folder--active' : ''),
                'data-folder': key
            }, [
                el('span', { 'class': 'ov-folder__icon', 'aria-hidden': 'true', text: '📦' }),
                el('span', { 'class': 'ov-folder__name', text: folder.name + ' (' + folder.total + ')' })
            ]);
            node.addEventListener('click', function () {
                selectFolder(key, '📦 ' + folder.name);
            });
            group.appendChild(node);
        });
        body.appendChild(group);
    }

    /** Index-Eintrag des Archivs auf das Listenformat der Mail-Ansicht abbilden. */
    function mapArchiveItem(item) {
        item.received = item.date ? Math.floor(Date.parse(item.date.replace(' ', 'T') + 'Z') / 1000) : 0;
        item.is_read = true;
        item.archived = true;
        item.size = item.size || item.size_bytes || 0;
        return item;
    }

    function loadArchiveMessages(append) {
        var offset = append ? state.messages.length : 0;
        if (!append) {
            listMessage('Archiv wird geladen …', 'ov-list__empty--loading');
        }
        return api('/archiv/mail', { query: { ordner: state.folder.slice(8), offset: offset, limit: 50 } }).then(function (data) {
            var items = (data.items || []).map(mapArchiveItem);
            state.messages = append ? state.messages.concat(items) : items;
            state.total = data.total || state.messages.length;
            state.hasMore = state.messages.length < state.total;
            renderMessages();
            markSync();
        }).catch(function (error) {
            listMessage(error.message, 'ov-list__empty--error');
        });
    }

    function folderKey(folder) {
        return folder.kind !== 'folder' ? folder.kind : folder.id;
    }

    function renderFolders() {
        var head = hook('folders-title');
        if (head) {
            head.textContent = 'Ordner';
        }
        var body = hook('folders-body');
        if (!body) {
            return;
        }
        body.innerHTML = '';
        var byParent = {};
        var known = {};
        state.folders.forEach(function (folder) {
            known[folder.id] = true;
        });
        state.folders.forEach(function (folder) {
            var parent = known[folder.parent] ? folder.parent : '';
            (byParent[parent] = byParent[parent] || []).push(folder);
        });
        function render(parent, depth) {
            (byParent[parent] || []).forEach(function (folder) {
                var key = folderKey(folder);
                var node = el('button', {
                    type: 'button',
                    'class': 'ov-folder' + (key === state.folder ? ' ov-folder--active' : ''),
                    'data-folder': key,
                    style: 'padding-left:' + (0.75 + depth * 0.9) + 'rem'
                }, [
                    el('span', { 'class': 'ov-folder__icon', 'aria-hidden': 'true', text: FOLDER_ICONS[folder.kind] || FOLDER_ICONS.folder }),
                    el('span', { 'class': 'ov-folder__name', text: FOLDER_LABELS[folder.kind] || folder.name }),
                    folder.unread > 0 ? el('span', { 'class': 'ov-folder__count', text: String(folder.unread) }) : null
                ]);
                node.addEventListener('click', function () {
                    selectFolder(key, FOLDER_LABELS[folder.kind] || folder.name);
                });
                bindFolderDrop(node, key);
                body.appendChild(node);
                render(folder.id, depth + 1);
            });
        }
        render('', 0);
        if (!state.folders.length) {
            body.appendChild(el('div', { 'class': 'ov-folders__empty', text: 'Keine Ordner gefunden.' }));
        }
        renderArchiveFolders();
    }

    /**
     * Ordner als Ablageziel fuer per Drag&Drop gezogene Nachrichten; der
     * aktuell geoeffnete Ordner nimmt keine Ablage an.
     */
    function bindFolderDrop(node, key) {
        function accepts() {
            return !!(state.dragIds && state.dragIds.length) && key !== state.folder;
        }
        node.addEventListener('dragenter', function (event) {
            if (accepts()) {
                event.preventDefault();
                node.classList.add('ov-folder--drop');
            }
        });
        node.addEventListener('dragover', function (event) {
            if (accepts()) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                node.classList.add('ov-folder--drop');
            }
        });
        node.addEventListener('dragleave', function (event) {
            if (!node.contains(event.relatedTarget)) {
                node.classList.remove('ov-folder--drop');
            }
        });
        node.addEventListener('drop', function (event) {
            node.classList.remove('ov-folder--drop');
            if (!accepts()) {
                return;
            }
            event.preventDefault();
            var ids = state.dragIds.slice();
            state.dragIds = null;
            mailAction('move', ids, key);
        });
    }

    function selectFolder(key, name) {
        state.folder = key;
        state.folderName = name;
        state.selected = null;
        state.selectedIds = [];
        $$('[data-folder]', hook('folders-body')).forEach(function (node) {
            node.classList.toggle('ov-folder--active', node.getAttribute('data-folder') === key);
        });
        showDetailEmpty();
        loadMessages();
    }

    // ------------------------------------------------------------------
    // Mail: Liste
    // ------------------------------------------------------------------

    function loadMessages(append) {
        if (isArchiveFolder(state.folder)) {
            return loadArchiveMessages(append);
        }
        var offset = append ? state.messages.length : 0;
        if (!append) {
            listMessage('Wird geladen …', 'ov-list__empty--loading');
        }
        return api('/mail', { query: { ordner: state.folder, offset: offset, limit: 50, q: state.search } }).then(function (data) {
            state.messages = append ? state.messages.concat(data.items || []) : (data.items || []);
            state.total = data.total || state.messages.length;
            state.hasMore = !!data.has_more;
            if (!append && state.search) {
                // Suche zusaetzlich im Langzeitarchiv: Treffer markiert anhaengen.
                return api('/archiv/suche', { query: { q: state.search } }).then(function (archiveData) {
                    state.messages = state.messages.concat((archiveData.items || []).map(mapArchiveItem));
                    renderMessages();
                    markSync();
                }).catch(function () {
                    renderMessages();
                    markSync();
                });
            }
            renderMessages();
            markSync();
        }).catch(function (error) {
            listMessage(error.message, 'ov-list__empty--error');
        });
    }

    function filteredMessages() {
        return state.messages.filter(function (message) {
            if (state.filter === 'unread') {
                return !message.is_read;
            }
            if (state.filter === 'flagged') {
                return message.flagged;
            }
            if (state.filter === 'attachments') {
                return message.has_attachments;
            }
            return true;
        });
    }

    function renderMessages() {
        var body = listBody();
        if (!body) {
            return;
        }
        var items = filteredMessages();
        setListTitle(state.search ? 'Suche: ' + state.search : state.folderName);
        setCount(state.total + ' Element' + (state.total === 1 ? '' : 'e') + (state.folder === 'inbox' ? ', ' + state.messages.filter(function (m) { return !m.is_read; }).length + ' ungelesen' : ''));
        if (!items.length) {
            body.appendChild(el('div', { 'class': 'ov-list__empty', text: state.search ? 'Keine Treffer.' : 'Dieser Ordner ist leer.' }));
        }
        var lastGroup = '';
        items.forEach(function (message) {
            var group = fmtListDate(message.received);
            var groupLabel = /^\d{2}:\d{2}$/.test(group) ? 'Heute' : (/^\d{2}\.\d{2}\.\d{2}$/.test(group) ? 'Älter' : group);
            if (groupLabel !== lastGroup && !state.prefs.dense) {
                body.appendChild(el('div', { 'class': 'ov-list__group', text: groupLabel }));
                lastGroup = groupLabel;
            }
            body.appendChild(renderMessageRow(message));
        });
        if (state.hasMore) {
            var more = el('button', { type: 'button', 'class': 'ov-list__more', text: 'Weitere Nachrichten laden' });
            more.addEventListener('click', function () {
                more.disabled = true;
                loadMessages(true);
            });
            body.appendChild(more);
        }
        updateActionState();
    }

    function renderMessageRow(message) {
        var who = state.folder === 'sentitems' || state.folder === 'drafts' || state.folder === 'outbox'
            ? (message.to || []).map(mailboxName).join(', ') || '(kein Empfänger)'
            : mailboxName(message.from) || '(unbekannt)';
        var row = el('article', {
            'class': 'ov-item' + (message.archived ? ' ov-item--archive' : '') + (message.is_read ? '' : ' ov-item--unread') + (state.selected && state.selected.id === message.id ? ' ov-item--active' : '') + (state.selectedIds.indexOf(message.id) !== -1 ? ' ov-item--checked' : ''),
            tabindex: '0',
            draggable: 'true',
            'data-id': message.id
        }, [
            el('label', { 'class': 'ov-item__check' }, [
                (function () {
                    var box = el('input', { type: 'checkbox', 'aria-label': 'Auswählen' });
                    box.checked = state.selectedIds.indexOf(message.id) !== -1;
                    box.addEventListener('click', function (event) {
                        event.stopPropagation();
                        toggleChecked(message.id, box.checked);
                    });
                    return box;
                })()
            ]),
            el('div', { 'class': 'ov-item__avatar', text: initials(who) }),
            el('div', { 'class': 'ov-item__main' }, [
                el('div', { 'class': 'ov-item__row' }, [
                    el('span', { 'class': 'ov-item__from', text: who }),
                    el('span', { 'class': 'ov-item__date', text: fmtListDate(message.received) })
                ]),
                el('div', { 'class': 'ov-item__row' }, [
                    el('span', { 'class': 'ov-item__subject', text: message.subject || '(kein Betreff)' }),
                    el('span', { 'class': 'ov-item__icons' }, [
                        message.archived ? el('span', { title: 'Archivierte Nachricht (Langzeitarchiv)', 'class': 'ov-item__archive', text: '📦' }) : null,
                        message.importance === 'High' ? el('span', { title: 'Hohe Wichtigkeit', 'class': 'ov-item__important', text: '!' }) : null,
                        message.has_attachments ? el('span', { title: 'Anhang', text: '📎' }) : null,
                        message.is_meeting_request ? el('span', { title: 'Besprechungsanfrage', text: '📅' }) : null,
                        message.flagged ? el('span', { title: 'Gekennzeichnet', 'class': 'ov-item__flag', text: '⚑' }) : null
                    ])
                ]),
                state.prefs.preview && message.preview ? el('div', { 'class': 'ov-item__preview', text: message.preview }) : null
            ])
        ]);
        row.addEventListener('click', function () {
            openMessage(message);
        });
        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openMessage(message);
            }
        });
        row.addEventListener('dragstart', function (event) {
            // Gehoert die Zeile zur Mehrfachauswahl, wird die ganze Auswahl gezogen.
            state.dragIds = state.selectedIds.indexOf(message.id) !== -1 ? state.selectedIds.slice() : [message.id];
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', state.dragIds.length > 1 ? state.dragIds.length + ' Nachrichten' : (message.subject || '(kein Betreff)'));
            state.dragIds.forEach(function (id) {
                var node = $('[data-id="' + CSS.escape(id) + '"]', hook('list-body'));
                if (node) {
                    node.classList.add('ov-item--dragging');
                }
            });
        });
        row.addEventListener('dragend', function () {
            state.dragIds = null;
            $$('.ov-item--dragging', hook('list-body')).forEach(function (node) {
                node.classList.remove('ov-item--dragging');
            });
            $$('.ov-folder--drop', hook('folders-body')).forEach(function (node) {
                node.classList.remove('ov-folder--drop');
            });
        });
        return row;
    }

    function toggleChecked(id, checked) {
        var index = state.selectedIds.indexOf(id);
        if (checked && index === -1) {
            state.selectedIds.push(id);
        } else if (!checked && index !== -1) {
            state.selectedIds.splice(index, 1);
        }
        var row = $('[data-id="' + CSS.escape(id) + '"]', hook('list-body'));
        if (row) {
            row.classList.toggle('ov-item--checked', checked);
        }
        updateActionState();
    }

    function currentMailIds() {
        if (state.selectedIds.length) {
            return state.selectedIds.slice();
        }
        return state.selected ? [state.selected.id] : [];
    }

    // ------------------------------------------------------------------
    // Mail: Detailansicht
    // ------------------------------------------------------------------

    function openMessage(summary) {
        state.selected = summary;
        $$('.ov-item', hook('list-body')).forEach(function (node) {
            node.classList.toggle('ov-item--active', node.getAttribute('data-id') === String(summary.id));
        });
        updateActionState();
        if (summary.archived) {
            return openArchiveMessage(summary);
        }
        var loading = el('div', { 'class': 'ov-mail ov-mail--loading', text: 'Nachricht wird geladen …' });
        showDetail(loading);
        return api('/mail/nachricht', { query: { id: summary.id } }).then(function (message) {
            if (!state.selected || state.selected.id !== message.id) {
                return null;
            }
            state.selected = message;
            showDetail(renderMessage(message));
            if (!summary.is_read && state.folder !== 'drafts') {
                api('/mail/aktion', { body: { action: 'read', ids: [message.id] } }).then(function () {
                    summary.is_read = true;
                    message.is_read = true;
                    patchFolderUnread(-1);
                    renderMessages();
                }).catch(function () { /* still */ });
            }
            return message;
        }).catch(function (error) {
            showDetail(el('div', { 'class': 'ov-mail ov-mail--error', text: error.message }));
            return null;
        });
    }

    /**
     * Archivierte Nachricht aus dem Langzeitarchiv laden: ehrlicher
     * Ladezustand (der Archivcontainer wird serverseitig gelesen, geprueft
     * und entpackt), keine vorgetaeuschte Fortschrittsanzeige.
     */
    function openArchiveMessage(summary) {
        var loading = el('div', { 'class': 'ov-mail ov-mail--loading' }, [
            el('p', { text: '📦 Archivierte Nachricht wird geladen …' }),
            el('p', { 'class': 'ov-mail__hint', text: 'Der Archivcontainer wird aus Nextcloud gelesen, auf Unversehrtheit geprüft und entpackt. Das kann einen Moment dauern.' })
        ]);
        showDetail(loading);
        return api('/archiv/mail/detail', { query: { id: summary.id } }).then(function (message) {
            if (!state.selected || state.selected.id !== message.id) {
                return null;
            }
            mapArchiveItem(message);
            state.selected = message;
            showDetail(renderMessage(message));
            return message;
        }).catch(function (error) {
            showDetail(el('div', { 'class': 'ov-mail ov-mail--error', text: error.message }));
            return null;
        });
    }

    /**
     * Vollstaendige Nachricht (mit Body) fuer Antworten/Weiterleiten liefern;
     * laedt sie bei Bedarf nach und zeigt sie dabei im Lesebereich an.
     */
    function withFullMessage(summary) {
        if (state.selected && state.selected.id === summary.id && state.selected.body_html !== undefined) {
            return Promise.resolve(state.selected);
        }
        return openMessage(summary);
    }

    function patchFolderUnread(delta) {
        state.folders.forEach(function (folder) {
            if (folderKey(folder) === state.folder) {
                folder.unread = Math.max(0, folder.unread + delta);
            }
        });
        renderFolders();
        var inbox = state.folders.filter(function (f) { return f.kind === 'inbox'; })[0];
        var count = $('[data-ov-module-count="mail"]');
        if (count && inbox) {
            count.textContent = inbox.unread > 0 ? String(inbox.unread) : '';
            count.hidden = inbox.unread <= 0;
        }
    }

    function renderMessage(message) {
        var wrap = el('div', { 'class': 'ov-mail' });
        var head = el('header', { 'class': 'ov-mail__head' }, [
            el('h2', { 'class': 'ov-mail__subject', text: message.subject || '(kein Betreff)' }),
            el('div', { 'class': 'ov-mail__meta' }, [
                el('div', { 'class': 'ov-mail__avatar', text: initials(mailboxName(message.from)) }),
                el('div', { 'class': 'ov-mail__who' }, [
                    el('div', { 'class': 'ov-mail__from' }, [
                        el('strong', { text: mailboxName(message.from) || '(unbekannt)' }),
                        message.from && message.from.email ? el('span', { 'class': 'ov-mail__email', text: ' <' + message.from.email + '>' }) : null
                    ]),
                    el('div', { 'class': 'ov-mail__to', text: 'An: ' + (message.archived ? (message.recipients || '–') : ((message.to || []).map(mailboxFull).join(', ') || '–')) }),
                    message.cc && message.cc.length ? el('div', { 'class': 'ov-mail__to', text: 'Cc: ' + message.cc.map(mailboxFull).join(', ') }) : null
                ]),
                el('div', { 'class': 'ov-mail__date', text: fmtDateTime(message.received) })
            ])
        ]);
        wrap.appendChild(head);

        if (message.archived) {
            wrap.appendChild(el('div', { 'class': 'ov-mail__meeting ov-mail__archive-note' }, [
                el('span', { text: '📦 Archivierte Nachricht – aus dem Langzeitarchiv in Nextcloud gelesen (Integrität geprüft).' })
            ]));
        }

        if (state.folder === 'drafts') {
            var draftBar = el('div', { 'class': 'ov-mail__meeting' }, [el('span', { text: 'Entwurf – ' })]);
            var edit = el('button', { type: 'button', 'class': 'button button--ghost', text: 'Entwurf bearbeiten' });
            edit.addEventListener('click', function () {
                openCompose('draft', message);
            });
            draftBar.appendChild(edit);
            wrap.appendChild(draftBar);
        }

        if (message.is_meeting_request) {
            var bar = el('div', { 'class': 'ov-mail__meeting' }, [el('span', { text: 'Besprechungsanfrage – ' })]);
            [['Accept', 'Zusagen'], ['Tentative', 'Mit Vorbehalt'], ['Decline', 'Ablehnen']].forEach(function (pair) {
                var button = el('button', { type: 'button', 'class': 'button button--ghost', text: pair[1] });
                button.addEventListener('click', function () {
                    api('/kalender/antwort', { body: { id: message.id, response: pair[0] } }).then(function () {
                        toast('Antwort gesendet: ' + pair[1], 'success');
                        loadMessages();
                    }).catch(function (error) {
                        toast(error.message, 'error');
                    });
                });
                bar.appendChild(button);
            });
            wrap.appendChild(bar);
        }

        if (message.blocked_images > 0 && !state.prefs.images) {
            var notice = el('div', { 'class': 'ov-mail__notice' }, [
                el('span', { text: message.blocked_images + ' externe Bild(er) wurden zum Schutz Ihrer Privatsphäre blockiert.' })
            ]);
            var show = el('button', { type: 'button', 'class': 'ov-link', text: 'Bilder anzeigen' });
            show.addEventListener('click', function () {
                body.querySelectorAll('[data-blocked-src]').forEach(function (img) {
                    img.setAttribute('src', img.getAttribute('data-blocked-src'));
                });
                notice.remove();
            });
            notice.appendChild(show);
            wrap.appendChild(notice);
        }

        var attachments = (message.attachments || []).filter(function (a) { return !a.inline; });
        if (attachments.length) {
            wrap.appendChild(renderAttachments(attachments));
        }

        var body = el('div', { 'class': 'ov-mail__body' });
        body.innerHTML = inlineStylesToCssom(message.body_html || '');
        body.querySelectorAll('[data-ov-style]').forEach(function (node) {
            node.style.cssText = node.getAttribute('data-ov-style');
            node.removeAttribute('data-ov-style');
        });
        body.querySelectorAll('a[href]').forEach(function (link) {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer nofollow');
        });
        if (state.prefs.images) {
            body.querySelectorAll('[data-blocked-src]').forEach(function (img) {
                img.setAttribute('src', img.getAttribute('data-blocked-src'));
            });
        }
        wrap.appendChild(body);
        return wrap;
    }

    function attachmentIcon(name) {
        var ext = String(name || '').split('.').pop().toLowerCase();
        if (/^(docx?|odt|rtf)$/.test(ext)) { return '📄'; }
        if (/^(xlsx?|ods|csv)$/.test(ext)) { return '📊'; }
        if (/^(pptx?|odp)$/.test(ext)) { return '📽'; }
        if (ext === 'pdf') { return '📕'; }
        if (/^(png|jpe?g|gif|webp|bmp|svg)$/.test(ext)) { return '🖼'; }
        if (/^(zip|7z|rar|gz|tar)$/.test(ext)) { return '🗜'; }
        return '📎';
    }

    function renderAttachments(attachments) {
        var list = el('div', { 'class': 'ov-attachments' }, [
            el('div', { 'class': 'ov-attachments__title', text: attachments.length + ' Anhang' + (attachments.length === 1 ? '' : 'änge') })
        ]);
        attachments.forEach(function (attachment) {
            var chip = el('div', { 'class': 'ov-attachment' }, [
                el('span', { 'class': 'ov-attachment__icon', 'aria-hidden': 'true', text: attachmentIcon(attachment.name) }),
                el('span', { 'class': 'ov-attachment__name', text: attachment.name, title: attachment.name }),
                el('span', { 'class': 'ov-attachment__size', text: fmtBytes(attachment.size) })
            ]);
            var open = el('button', { type: 'button', 'class': 'ov-attachment__action', title: 'Öffnen', text: 'Öffnen' });
            open.addEventListener('click', function () {
                openAttachment(attachment);
            });
            chip.appendChild(open);
            if (config.nextcloudAvailable) {
                var save = el('button', { type: 'button', 'class': 'ov-attachment__action', title: 'In Nextcloud speichern', text: '☁ Nextcloud' });
                save.addEventListener('click', function () {
                    save.disabled = true;
                    api('/anhang/nextcloud', { body: { attachment_id: attachment.id, name: attachment.name } }).then(function (result) {
                        toast(result.message || 'Anhang in Nextcloud gespeichert.', result.ok === false ? 'error' : 'success');
                    }).catch(function (error) {
                        toast(error.message, 'error');
                    }).then(function () {
                        save.disabled = false;
                    });
                });
                chip.appendChild(save);
            }
            list.appendChild(chip);
        });
        return list;
    }

    var OFFICE_EXT = /^(docx?|docm|dotx?|odt|ott|rtf|txt|pdf|djvu|xps|epub|fb2|html?|mht|xlsx?|xlsm|xltx?|ods|ots|csv|pptx?|pptm|potx?|odp|otp|ppsx|pps)$/;
    var BROWSER_EXT = /^(png|jpe?g|gif|webp|bmp|svg|txt|pdf)$/;

    function attachmentMode(name) {
        var ext = String(name || '').split('.').pop().toLowerCase();
        if (OFFICE_EXT.test(ext)) { return 'office'; }
        return BROWSER_EXT.test(ext) ? 'browser' : 'download';
    }

    function openAttachment(attachment) {
        var ext = String(attachment.name || '').split('.').pop().toLowerCase();
        var mode = attachmentMode(attachment.name);
        // Nur Vorschau-faehige Anhaenge in einen neuen Tab; alles andere als Download
        // (spiegelt OrvantaController::openAttachment).
        var preview = mode === 'browser' || (mode === 'office' && (config.officeAvailable || BROWSER_EXT.test(ext)));
        var popup = preview ? window.open('', '_blank') : null;
        if (popup) {
            // about:blank erbt die CSP des Oeffners: keine Inline-Styles; document.close()
            // beendet das Laden, sonst ignoriert der Browser die spaetere Navigation.
            popup.document.write('<!doctype html><title>Orvanta – Anhang wird geöffnet …</title><p>Anhang wird geöffnet …</p>');
            popup.document.close();
        }
        api('/anhang/link', { body: { attachment_id: attachment.id, name: attachment.name } }).then(function (result) {
            if (popup) {
                popup.location.href = result.url;
            } else {
                window.location.href = result.url;
            }
        }).catch(function (error) {
            if (popup) {
                popup.close();
            }
            toast(error.message, 'error');
        });
    }

    // ------------------------------------------------------------------
    // Mail: Aktionen
    // ------------------------------------------------------------------

    function mailAction(action, ids, folder) {
        ids = ids || currentMailIds();
        if (!ids.length) {
            toast('Bitte zuerst eine Nachricht auswählen.', 'info');
            return Promise.resolve();
        }
        return api('/mail/aktion', { body: { action: action, ids: ids, folder: folder || '' } }).then(function () {
            var labels = { read: 'Als gelesen markiert.', unread: 'Als ungelesen markiert.', flag: 'Gekennzeichnet.', unflag: 'Kennzeichnung entfernt.', move: 'Verschoben.', 'delete': 'In „Gelöschte Elemente“ verschoben.', delete_permanent: 'Endgültig gelöscht.' };
            toast(labels[action] || 'Erledigt.', 'success');
            if (action === 'delete' || action === 'delete_permanent' || action === 'move') {
                state.selected = null;
                state.selectedIds = [];
                showDetailEmpty();
            }
            return loadFolders().then(loadMessages);
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    function openMoveDialog(ids) {
        ids = ids || currentMailIds();
        if (!ids.length) {
            toast('Bitte zuerst eine Nachricht auswählen.', 'info');
            return;
        }
        var list = hook('move-folders');
        if (!list) {
            return;
        }
        // Ein <select> nimmt nur <option>/<optgroup> auf; Ordner hierarchisch einruecken.
        list.innerHTML = '';
        var byParent = {};
        var known = {};
        state.folders.forEach(function (folder) {
            known[folder.id] = true;
        });
        state.folders.forEach(function (folder) {
            var parent = known[folder.parent] ? folder.parent : '';
            (byParent[parent] = byParent[parent] || []).push(folder);
        });
        function add(parent, depth) {
            (byParent[parent] || []).forEach(function (folder) {
                var key = folderKey(folder);
                if (key !== state.folder) {
                    var indent = new Array(depth + 1).join('\u00a0\u00a0\u00a0');
                    list.appendChild(el('option', { value: key, text: indent + (FOLDER_ICONS[folder.kind] || FOLDER_ICONS.folder) + ' ' + (FOLDER_LABELS[folder.kind] || folder.name) }));
                }
                add(folder.id, depth + 1);
            });
        }
        add('', 0);
        if (!list.options.length) {
            toast('Kein anderer Ordner verfügbar.', 'info');
            return;
        }
        state.moveIds = ids.slice();
        openDialog('move');
    }

    function submitMove(form) {
        var select = hook('move-folders', form);
        var target = select ? select.value : '';
        var ids = state.moveIds || [];
        if (!target || !ids.length) {
            return;
        }
        state.moveIds = null;
        closeDialog('move');
        mailAction('move', ids, target);
    }

    // ------------------------------------------------------------------
    // Mail: Info (rohe Kopfzeilen)
    // ------------------------------------------------------------------

    function openHeadersDialog(message) {
        var dialog = $('[data-ov-dialog="headers"]');
        if (!dialog) {
            return;
        }
        var subject = hook('headers-subject', dialog);
        var raw = hook('headers-raw', dialog);
        if (subject) {
            subject.textContent = message.subject || '(kein Betreff)';
        }
        if (raw) {
            raw.textContent = 'Kopfzeilen werden geladen …';
            raw.classList.add('is-loading');
        }
        openDialog('headers');
        api('/mail/kopfzeilen', { query: { id: message.id } }).then(function (info) {
            if (raw) {
                raw.textContent = info.headers || '';
                raw.classList.remove('is-loading');
                raw.focus();
            }
            if (subject && info.subject) {
                subject.textContent = info.subject;
            }
        }).catch(function (error) {
            if (raw) {
                raw.textContent = error.message || 'Die Kopfzeilen konnten nicht geladen werden.';
                raw.classList.remove('is-loading');
            }
        });
    }

    function copyHeaders() {
        var raw = hook('headers-raw');
        var text = raw ? raw.textContent : '';
        if (!text || (raw && raw.classList.contains('is-loading'))) {
            return;
        }
        var done = function () { toast('Kopfzeilen in die Zwischenablage kopiert.', 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () {
                toast('Kopieren nicht möglich – bitte Text markieren und manuell kopieren.', 'error');
            });
            return;
        }
        var range = document.createRange();
        range.selectNodeContents(raw);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        if (document.execCommand('copy')) {
            done();
        }
    }

    // ------------------------------------------------------------------
    // Mail: Ordner-Kontextmenue (Neuer Ordner, Alle gelesen, Eigenschaften)
    // ------------------------------------------------------------------

    function folderLabel(folder) {
        return FOLDER_LABELS[folder.kind] || folder.name;
    }

    function openNewFolderDialog(parent) {
        var form = hook('form-folder-new');
        if (!form) {
            return;
        }
        form.reset();
        form.elements.parent.value = parent ? folderKey(parent) : '';
        var label = hook('folder-new-parent', form);
        if (label) {
            label.textContent = parent ? folderLabel(parent) : 'Postfach (oberste Ebene)';
        }
        openDialog('folder-new');
    }

    function createFolder(form) {
        var name = form.elements.name.value.trim();
        if (!name) {
            formError(form, 'Bitte einen Ordnernamen angeben.');
            return;
        }
        var button = form.querySelector('[type=submit]');
        button.disabled = true;
        api('/mail/ordner/neu', { body: { parent: form.elements.parent.value, name: name } }).then(function (result) {
            closeDialog('folder-new');
            toast(result.message || 'Der Ordner wurde angelegt.', 'success');
            return loadFolders();
        }).catch(function (error) {
            formError(form, error.message);
        }).then(function () {
            button.disabled = false;
        });
    }

    function markFolderRead(folder) {
        var key = folderKey(folder);
        return api('/mail/ordner/gelesen', { body: { folder: key } }).then(function (result) {
            toast(result.message || 'Alle Nachrichten wurden als gelesen markiert.', 'success');
            return loadFolders().then(function () {
                if (state.folder === key) {
                    return loadMessages();
                }
            });
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    function openFolderProperties(folder) {
        var dialog = $('[data-ov-dialog="folder-props"]');
        if (!dialog) {
            return;
        }
        var title = hook('folder-props-title', dialog);
        var list = hook('folder-props', dialog);
        if (title) {
            title.textContent = folderLabel(folder);
        }
        function fill(rows) {
            if (!list) {
                return;
            }
            list.innerHTML = '';
            rows.forEach(function (row) {
                list.appendChild(el('dt', { text: row[0] }));
                list.appendChild(el('dd', { text: row[1] }));
            });
        }
        fill([['Status', 'Eigenschaften werden geladen …']]);
        openDialog('folder-props');
        var items = function (n) { return n + ' Element' + (n === 1 ? '' : 'e'); };
        api('/mail/ordner/eigenschaften', { query: { ordner: folderKey(folder) } }).then(function (info) {
            var rows = [
                ['Elemente', items(info.total)],
                ['Ungelesen', String(info.unread)],
                ['Größe', fmtBytes(info.size)]
            ];
            if (info.subfolders > 0) {
                rows.push(['Unterordner', String(info.subfolders)]);
                rows.push(['Inkl. Unterordner', items(info.total_with_subfolders) + ', ' + fmtBytes(info.size_with_subfolders)]);
            }
            fill(rows);
        }).catch(function (error) {
            fill([['Fehler', error.message || 'Die Eigenschaften konnten nicht geladen werden.']]);
        });
    }

    // ------------------------------------------------------------------
    // Mail: Verfassen
    // ------------------------------------------------------------------

    function quoteHeader(message) {
        return '<br><br><div class="ov-quote"><hr><p><b>Von:</b> ' + esc(mailboxFull(message.from)) + '<br><b>Gesendet:</b> ' + esc(fmtDateTime(message.sent || message.received))
            + '<br><b>An:</b> ' + esc((message.to || []).map(mailboxFull).join('; ')) + '<br><b>Betreff:</b> ' + esc(message.subject) + '</p>' + (message.body_html || '') + '</div>';
    }

    function openCompose(mode, message) {
        var form = hook('form-compose');
        if (!form) {
            return;
        }
        form.reset();
        var isDraft = mode === 'draft';
        state.compose = {
            mode: isDraft ? 'new' : (mode || 'new'),
            attachments: [],
            replyTo: message && !isDraft ? message.id : null,
            draftId: isDraft && message ? message.id : null,
            changeKey: isDraft && message ? (message.change_key || '') : ''
        };
        var body = hook('compose-body');
        var attachList = hook('attach-list');
        if (attachList) {
            attachList.innerHTML = '';
        }
        var title = hook('compose-title', form.closest('dialog'));
        var titles = { 'new': 'Neue Nachricht', reply: 'Antworten', replyall: 'Allen antworten', forward: 'Weiterleiten', draft: 'Entwurf bearbeiten' };
        if (title) {
            title.textContent = titles[mode] || 'Neue Nachricht';
        }
        if (body) {
            body.innerHTML = '<p><br></p>';
        }
        if (isDraft && message) {
            fillComposeFromDraft(form, body, message);
        } else if (message) {
            var subject = message.subject || '';
            var prefix = mode === 'forward' ? 'WG: ' : 'AW: ';
            if (!/^(AW|RE|WG|FW|FWD):/i.test(subject)) {
                subject = prefix + subject;
            }
            form.elements.subject.value = subject;
            if (mode === 'reply' || mode === 'replyall') {
                var replyTo = (message.reply_to && message.reply_to.length ? message.reply_to : [message.from]).map(mailboxFull);
                form.elements.to.value = replyTo.join('; ');
                if (mode === 'replyall') {
                    var me = (config.user && config.user.email || '').toLowerCase();
                    var cc = (message.to || []).concat(message.cc || []).filter(function (box) {
                        return box.email && box.email.toLowerCase() !== me && replyTo.indexOf(mailboxFull(box)) === -1;
                    }).map(mailboxFull);
                    form.elements.cc.value = cc.join('; ');
                }
            }
            if (body) {
                body.innerHTML = inlineStylesToCssom('<p><br></p>' + quoteHeader(message));
                body.querySelectorAll('[data-ov-style]').forEach(function (node) {
                    node.style.cssText = node.getAttribute('data-ov-style');
                    node.removeAttribute('data-ov-style');
                });
            }
        }
        renderComposeSignature();
        openDialog('compose');
        if (mode === 'new' || mode === 'forward') {
            form.elements.to.focus();
        } else if (body) {
            body.focus();
        }
    }

    /**
     * Gespeicherten Entwurf in das Formular laden; vorhandene Anhaenge bleiben
     * am Entwurf und werden nur angezeigt.
     */
    function fillComposeFromDraft(form, body, message) {
        form.elements.to.value = (message.to || []).map(mailboxFull).join('; ');
        form.elements.cc.value = (message.cc || []).map(mailboxFull).join('; ');
        if (form.elements.bcc) {
            form.elements.bcc.value = (message.bcc || []).map(mailboxFull).join('; ');
        }
        form.elements.subject.value = message.subject || '';
        if (form.elements.importance && message.importance) {
            form.elements.importance.value = message.importance;
        }
        if (body) {
            body.innerHTML = inlineStylesToCssom(message.body_html || '<p><br></p>');
            body.querySelectorAll('[data-ov-style]').forEach(function (node) {
                node.style.cssText = node.getAttribute('data-ov-style');
                node.removeAttribute('data-ov-style');
            });
            body.querySelectorAll('[data-blocked-src]').forEach(function (img) {
                img.setAttribute('src', img.getAttribute('data-blocked-src'));
            });
            renderComposeSignature(body);
        }
        var list = hook('attach-list');
        (message.attachments || []).filter(function (a) { return !a.inline; }).forEach(function (attachment) {
            var entry = { name: attachment.name, content_type: attachment.content_type, content: '', size: attachment.size, uploaded: true };
            state.compose.attachments.push(entry);
            if (list) {
                list.appendChild(renderAttachmentChip(entry));
            }
        });
    }

    /**
     * Anhang-Chip im Verfassen-Dialog; bereits am Entwurf gespeicherte Anhaenge
     * werden nur angezeigt, neue lassen sich entfernen.
     */
    function renderAttachmentChip(entry) {
        var chip = el('span', { 'class': 'ov-attachment ov-attachment--compose', title: entry.uploaded ? 'Bereits am Entwurf gespeichert' : null }, [
            el('span', { text: attachmentIcon(entry.name) + ' ' + entry.name + ' (' + fmtBytes(entry.size) + ')' })
        ]);
        if (!entry.uploaded) {
            var remove = el('button', { type: 'button', 'class': 'ov-attachment__action', 'aria-label': 'Entfernen', text: '×' });
            remove.addEventListener('click', function () {
                var index = state.compose ? state.compose.attachments.indexOf(entry) : -1;
                if (index !== -1) {
                    state.compose.attachments.splice(index, 1);
                }
                chip.remove();
            });
            chip.appendChild(remove);
        }
        return chip;
    }

    function composePayload(form) {
        var body = hook('compose-body');
        var compose = state.compose || {};
        return {
            to: parseRecipients(form.elements.to.value),
            cc: parseRecipients(form.elements.cc.value),
            bcc: parseRecipients(form.elements.bcc ? form.elements.bcc.value : ''),
            subject: form.elements.subject.value.trim(),
            body: body ? body.innerHTML : '',
            html: true,
            importance: form.elements.importance ? form.elements.importance.value : 'Normal',
            attachments: (compose.attachments || []).filter(function (a) { return !a.uploaded; }),
            draft_id: compose.draftId || '',
            change_key: compose.changeKey || ''
        };
    }

    function sendCompose(form, asDraft) {
        var payload = composePayload(form);
        var compose = state.compose || {};
        var isReply = compose.mode !== 'new' && !!compose.replyTo;
        if (!asDraft && !payload.to.length && !(isReply && compose.mode !== 'forward' && (payload.cc.length || payload.bcc.length))) {
            formError(form, 'Bitte mindestens einen Empfänger angeben.');
            return;
        }
        var invalid = payload.to.concat(payload.cc, payload.bcc).filter(function (box) {
            return !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(box.email);
        });
        if (invalid.length) {
            formError(form, 'Ungültige Adresse: ' + invalid[0].email);
            return;
        }
        formError(form, '');
        var buttons = $$('button', form);
        buttons.forEach(function (b) { b.disabled = true; });
        setStatus(asDraft ? 'Entwurf wird gespeichert …' : 'Nachricht wird gesendet …');
        var request;
        if (asDraft) {
            payload.reply_id = isReply ? compose.replyTo : '';
            payload.mode = isReply ? compose.mode : 'new';
            request = api('/mail/entwurf', { body: payload });
        } else if (isReply && !compose.draftId) {
            payload.id = compose.replyTo;
            payload.mode = compose.mode;
            request = api('/mail/antworten', { body: payload });
        } else {
            request = api('/mail/senden', { body: payload });
        }
        request.then(function (result) {
            if (asDraft) {
                // Entwurf bleibt geoeffnet; weitere Speicherungen aktualisieren ihn.
                state.compose.draftId = result && result.id ? result.id : state.compose.draftId;
                state.compose.changeKey = result && result.change_key ? result.change_key : '';
                payload.attachments.forEach(function (a) { a.uploaded = true; });
                var title = hook('compose-title', form.closest('dialog'));
                if (title && state.compose.mode === 'new') {
                    title.textContent = 'Entwurf bearbeiten';
                }
            } else {
                closeDialog('compose');
            }
            toast(asDraft ? 'Entwurf gespeichert.' : 'Nachricht gesendet.', 'success');
            setStatus('');
            loadFolders().then(loadMessages);
        }).catch(function (error) {
            setStatus('');
            formError(form, error.message);
        }).then(function () {
            buttons.forEach(function (b) { b.disabled = false; });
        });
    }

    function addComposeFiles(files) {
        var list = hook('attach-list');
        Array.prototype.forEach.call(files, function (file) {
            if (file.size > 15 * 1024 * 1024) {
                toast('Die Datei „' + file.name + '“ ist größer als 15 MB.', 'error');
                return;
            }
            var reader = new FileReader();
            reader.onload = function () {
                var base64 = String(reader.result).split(',')[1] || '';
                var entry = { name: file.name, content_type: file.type || 'application/octet-stream', content: base64, size: file.size };
                if (!state.compose) {
                    return;
                }
                state.compose.attachments.push(entry);
                if (list) {
                    list.appendChild(renderAttachmentChip(entry));
                }
            };
            reader.readAsDataURL(file);
        });
    }

    // ------------------------------------------------------------------
    // Mail: Verfassen in eigenem Tab
    // ------------------------------------------------------------------

    var COMPOSE_CHANNEL = 'orvanta-compose';
    var COMPOSE_HANDOFF_KEY = 'orvanta.compose.handoff.';
    var COMPOSE_HANDOFF_STORAGE_MAX = 1.5 * 1024 * 1024;
    var composeHandoffs = {};
    var composeChannel = null;

    function composeChannelOpen() {
        if (composeChannel === null && typeof window.BroadcastChannel === 'function') {
            composeChannel = new window.BroadcastChannel(COMPOSE_CHANNEL);
            composeChannel.addEventListener('message', onComposeChannelMessage);
        }
        return composeChannel;
    }

    /**
     * Vollstaendiger Zustand des Verfassen-Dialogs (Felder, Text, Anhaenge,
     * Antwort-/Entwurfsbezug) fuer die Uebergabe an einen anderen Tab.
     */
    function composeSnapshot(form) {
        var compose = state.compose || {};
        var body = hook('compose-body');
        var title = hook('compose-title', form.closest('dialog'));
        return {
            mode: compose.mode || 'new',
            replyTo: compose.replyTo || null,
            draftId: compose.draftId || null,
            changeKey: compose.changeKey || '',
            attachments: (compose.attachments || []).map(function (a) {
                return { name: a.name, content_type: a.content_type, content: a.content || '', size: a.size, uploaded: !!a.uploaded };
            }),
            title: title ? title.textContent : '',
            fields: {
                to: form.elements.to.value,
                cc: form.elements.cc.value,
                bcc: form.elements.bcc ? form.elements.bcc.value : '',
                subject: form.elements.subject.value,
                importance: form.elements.importance ? form.elements.importance.value : 'Normal'
            },
            body: body ? body.innerHTML : ''
        };
    }

    function restoreCompose(snapshot) {
        var form = hook('form-compose');
        if (!form || !snapshot) {
            return;
        }
        form.reset();
        state.compose = {
            mode: snapshot.mode || 'new',
            attachments: [],
            replyTo: snapshot.replyTo || null,
            draftId: snapshot.draftId || null,
            changeKey: snapshot.changeKey || ''
        };
        var fields = snapshot.fields || {};
        form.elements.to.value = fields.to || '';
        form.elements.cc.value = fields.cc || '';
        if (form.elements.bcc) {
            form.elements.bcc.value = fields.bcc || '';
        }
        form.elements.subject.value = fields.subject || '';
        if (form.elements.importance && fields.importance) {
            form.elements.importance.value = fields.importance;
        }
        var title = hook('compose-title', form.closest('dialog'));
        if (title && snapshot.title) {
            title.textContent = snapshot.title;
        }
        setEditorHtml(hook('compose-body'), snapshot.body || '');
        renderComposeSignature();
        var list = hook('attach-list');
        if (list) {
            list.innerHTML = '';
        }
        (snapshot.attachments || []).forEach(function (entry) {
            state.compose.attachments.push(entry);
            if (list) {
                list.appendChild(renderAttachmentChip(entry));
            }
        });
        openDialog('compose');
    }

    /**
     * Verschiebt den geoeffneten Verfassen-Dialog in einen neuen Tab. Der neue
     * Tab fordert den Zustand ueber einen BroadcastChannel an (grosse Anhaenge
     * moeglich); kleine Zustaende liegen zusaetzlich im localStorage, damit die
     * Uebergabe auch ohne Channel funktioniert. Erst nach bestaetigtem Empfang
     * wird der Dialog hier geschlossen.
     */
    function detachCompose() {
        var form = hook('form-compose');
        if (!form || !state.compose) {
            return;
        }
        var key = String(Date.now().toString(36)) + Math.random().toString(36).slice(2, 10);
        var snapshot = composeSnapshot(form);
        var channel = composeChannelOpen();
        var stored = false;
        try {
            var json = JSON.stringify(snapshot);
            if (json.length <= COMPOSE_HANDOFF_STORAGE_MAX) {
                window.localStorage.setItem(COMPOSE_HANDOFF_KEY + key, json);
                stored = true;
            }
        } catch (e) {
            stored = false;
        }
        if (!channel && !stored) {
            toast('Der Entwurf ist zu groß für die Übergabe an einen neuen Tab. Bitte zuerst als Entwurf speichern.', 'error');
            return;
        }
        var opened = window.open('/office/orvanta?modul=mail&verfassen=' + encodeURIComponent(key), '_blank');
        if (!opened) {
            try { window.localStorage.removeItem(COMPOSE_HANDOFF_KEY + key); } catch (e) { /* ignorieren */ }
            toast('Der neue Tab konnte nicht geöffnet werden (Popup-Blocker?).', 'error');
            return;
        }
        var buttons = $$('button', form);
        buttons.forEach(function (b) { b.disabled = true; });
        setStatus('Nachricht wird in neuen Tab verschoben …');
        composeHandoffs[key] = {
            snapshot: snapshot,
            timer: window.setTimeout(function () {
                if (!composeHandoffs[key]) {
                    return;
                }
                delete composeHandoffs[key];
                buttons.forEach(function (b) { b.disabled = false; });
                setStatus('');
                toast('Der neue Tab hat den Entwurf nicht übernommen. Die Nachricht bleibt hier geöffnet.', 'error');
            }, 20000),
            done: function () {
                window.clearTimeout(composeHandoffs[key].timer);
                delete composeHandoffs[key];
                try { window.localStorage.removeItem(COMPOSE_HANDOFF_KEY + key); } catch (e) { /* ignorieren */ }
                buttons.forEach(function (b) { b.disabled = false; });
                setStatus('');
                state.compose = null;
                closeDialog('compose');
                toast('Die Nachricht wird im neuen Tab bearbeitet.', 'info');
            }
        };
        // Ohne Channel kann der neue Tab keinen Empfang melden: Uebergabe per
        // localStorage gilt mit dem Loeschen des Eintrags als abgeschlossen.
        if (!channel && stored) {
            window.addEventListener('storage', function onStorage(event) {
                if (event.key === COMPOSE_HANDOFF_KEY + key && event.newValue === null && composeHandoffs[key]) {
                    window.removeEventListener('storage', onStorage);
                    composeHandoffs[key].done();
                }
            });
        }
    }

    function onComposeChannelMessage(event) {
        var data = event.data || {};
        var key = data.key || '';
        if (data.type === 'request' && composeHandoffs[key]) {
            composeChannel.postMessage({ type: 'payload', key: key, snapshot: composeHandoffs[key].snapshot });
        } else if (data.type === 'received' && composeHandoffs[key]) {
            composeHandoffs[key].done();
        } else if (data.type === 'payload' && state.standalone && state.standalone.key === key && !state.standalone.received) {
            state.standalone.received = true;
            window.clearTimeout(state.standalone.timer);
            composeChannel.postMessage({ type: 'received', key: key });
            restoreCompose(data.snapshot);
        }
    }

    /**
     * Neuer Tab: Zustand aus localStorage oder ueber den Channel uebernehmen.
     */
    function startStandaloneCompose(key) {
        state.standalone = { key: key, received: false, timer: null };
        root.classList.add('ov--compose-standalone');
        var dialog = $('[data-ov-dialog="compose"]');
        if (dialog) {
            dialog.addEventListener('close', function () {
                finishStandaloneCompose();
            });
        }
        window.addEventListener('beforeunload', function (event) {
            if (state.standalone && state.compose && dialog && dialog.open) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        var json = null;
        try {
            json = window.localStorage.getItem(COMPOSE_HANDOFF_KEY + key);
            if (json !== null) {
                window.localStorage.removeItem(COMPOSE_HANDOFF_KEY + key);
            }
        } catch (e) {
            json = null;
        }
        if (json !== null) {
            state.standalone.received = true;
            try {
                restoreCompose(JSON.parse(json));
            } catch (e) {
                json = null;
                state.standalone.received = false;
            }
        }
        var channel = composeChannelOpen();
        if (json !== null) {
            if (channel) {
                channel.postMessage({ type: 'received', key: key });
            }
            return;
        }
        if (!channel) {
            showStandaloneDone('Der Entwurf konnte nicht übernommen werden. Bitte im ursprünglichen Tab weiterarbeiten.');
            return;
        }
        channel.postMessage({ type: 'request', key: key });
        state.standalone.timer = window.setTimeout(function () {
            if (!state.standalone.received) {
                showStandaloneDone('Der Entwurf konnte nicht übernommen werden. Bitte im ursprünglichen Tab weiterarbeiten.');
            }
        }, 8000);
    }

    function showStandaloneDone(message) {
        var done = hook('standalone-done');
        var text = hook('standalone-done-text');
        if (text && message) {
            text.textContent = message;
        }
        if (done) {
            done.hidden = false;
        }
    }

    /**
     * Nach Senden/Verwerfen im eigenen Tab: Tab schliessen, sonst Hinweis.
     */
    function finishStandaloneCompose() {
        state.compose = null;
        window.setTimeout(function () {
            window.close();
            showStandaloneDone('Dieses Fenster kann geschlossen werden.');
        }, 150);
    }

    function applyFormat(command, value) {
        var body = hook('compose-body');
        if (!body) {
            return;
        }
        body.focus();
        try {
            if (command === 'createLink') {
                value = window.prompt('Link-Adresse (URL):', 'https://');
                if (!value) {
                    return;
                }
            }
            document.execCommand(command, false, value || null);
        } catch (e) {
            /* ignorieren */
        }
    }

    // ------------------------------------------------------------------
    // Kalender
    // ------------------------------------------------------------------

    function calendarRange() {
        var start;
        var end;
        if (state.calView === 'month') {
            var first = new Date(state.calDate.getFullYear(), state.calDate.getMonth(), 1);
            start = startOfWeek(first);
            end = addDays(start, 42);
        } else if (state.calView === 'day') {
            start = startOfDay(state.calDate);
            end = addDays(start, 1);
        } else if (state.calView === 'agenda') {
            start = startOfDay(state.calDate);
            end = addDays(start, 30);
        } else {
            start = startOfWeek(state.calDate);
            end = addDays(start, state.calView === 'workweek' ? 5 : 7);
        }
        return { start: start, end: end };
    }

    function loadCalendar() {
        var range = calendarRange();
        setListTitle('Termine werden geladen …');
        return api('/kalender', { query: { start: toTs(range.start), end: toTs(range.end) } }).then(function (data) {
            state.events = (data.items || data || []).slice().sort(function (a, b) {
                return a.start - b.start;
            });
            renderCalendar();
            renderCalendarSidebar();
            markSync();
        }).catch(function (error) {
            listMessage(error.message, 'ov-list__empty--error');
        });
    }

    function calendarTitle() {
        var range = calendarRange();
        var d = state.calDate;
        if (state.calView === 'month') {
            return MONTHS[d.getMonth()] + ' ' + d.getFullYear();
        }
        if (state.calView === 'day') {
            return DAYS_LONG[(d.getDay() + 6) % 7] + ', ' + pad(d.getDate()) + '. ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
        }
        if (state.calView === 'agenda') {
            return 'Agenda ab ' + pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear();
        }
        var last = addDays(range.end, -1);
        return pad(range.start.getDate()) + '.' + pad(range.start.getMonth() + 1) + '. – ' + pad(last.getDate()) + '.' + pad(last.getMonth() + 1) + '.' + last.getFullYear();
    }

    function eventsOnDay(day) {
        var dayStart = toTs(day);
        var dayEnd = toTs(addDays(day, 1));
        return state.events.filter(function (event) {
            return event.start < dayEnd && event.end > dayStart;
        });
    }

    function eventClass(event) {
        var cls = 'ov-event ov-event--' + String(event.free_busy || 'Busy').toLowerCase();
        if (event.all_day) {
            cls += ' ov-event--allday';
        }
        if (event.my_response === 'Tentative') {
            cls += ' ov-event--tentative';
        }
        if (state.selected && state.selected.id === event.id) {
            cls += ' ov-event--active';
        }
        return cls;
    }

    function eventNode(event, showTime) {
        var node = el('button', { type: 'button', 'class': eventClass(event), 'data-id': event.id, title: event.subject + (event.location ? ' – ' + event.location : '') }, [
            showTime && !event.all_day ? el('span', { 'class': 'ov-event__time', text: fmtTime(event.start) }) : null,
            el('span', { 'class': 'ov-event__subject', text: event.subject || '(ohne Betreff)' })
        ]);
        node.addEventListener('click', function (e) {
            e.stopPropagation();
            openEvent(event);
        });
        return node;
    }

    function renderCalendar() {
        var body = listBody();
        if (!body) {
            return;
        }
        setListTitle(calendarTitle());
        setCount(state.events.length + ' Termin' + (state.events.length === 1 ? '' : 'e'));
        $$('[data-ov-action="cal-view"]').forEach(function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-ov-value') === state.calView ? 'true' : 'false');
        });
        if (state.calView === 'month') {
            body.appendChild(renderMonth());
        } else if (state.calView === 'agenda') {
            body.appendChild(renderAgenda());
        } else {
            body.appendChild(renderWeek(state.calView === 'day' ? 1 : (state.calView === 'workweek' ? 5 : 7)));
        }
        updateActionState();
    }

    function renderMonth() {
        var range = calendarRange();
        var today = startOfDay(new Date());
        var grid = el('div', { 'class': 'ov-month' });
        DAYS.forEach(function (name) {
            grid.appendChild(el('div', { 'class': 'ov-month__head', text: name }));
        });
        for (var i = 0; i < 42; i++) {
            (function () {
                var day = addDays(range.start, i);
                var cell = el('div', {
                    'class': 'ov-month__cell' + (day.getMonth() !== state.calDate.getMonth() ? ' ov-month__cell--other' : '') + (sameDay(day, today) ? ' ov-month__cell--today' : '') + (day.getDay() === 0 || day.getDay() === 6 ? ' ov-month__cell--weekend' : '')
                }, [el('div', { 'class': 'ov-month__num', text: day.getDate() === 1 ? day.getDate() + '. ' + MONTHS[day.getMonth()].slice(0, 3) : String(day.getDate()) })]);
                var events = eventsOnDay(day);
                events.slice(0, 4).forEach(function (event) {
                    cell.appendChild(eventNode(event, true));
                });
                if (events.length > 4) {
                    var more = el('button', { type: 'button', 'class': 'ov-month__more', text: '+' + (events.length - 4) + ' weitere' });
                    more.addEventListener('click', function (e) {
                        e.stopPropagation();
                        state.calDate = day;
                        state.calView = 'day';
                        loadCalendar();
                    });
                    cell.appendChild(more);
                }
                cell.addEventListener('dblclick', function () {
                    openEventDialog(null, day);
                });
                cell.addEventListener('click', function () {
                    state.calDate = day;
                    renderCalendarSidebar();
                });
                grid.appendChild(cell);
            })();
        }
        return grid;
    }

    function renderWeek(days) {
        var range = calendarRange();
        var today = startOfDay(new Date());
        var wrap = el('div', { 'class': 'ov-week ov-week--' + days });
        var head = el('div', { 'class': 'ov-week__head' }, [el('div', { 'class': 'ov-week__gutter' })]);
        var allDayRow = el('div', { 'class': 'ov-week__allday' }, [el('div', { 'class': 'ov-week__gutter', text: 'Ganztägig' })]);
        var grid = el('div', { 'class': 'ov-week__grid' });
        var gutter = el('div', { 'class': 'ov-week__hours' });
        for (var h = 0; h < 24; h++) {
            gutter.appendChild(el('div', { 'class': 'ov-week__hour', text: pad(h) + ':00' }));
        }
        grid.appendChild(gutter);
        for (var i = 0; i < days; i++) {
            (function () {
                var day = addDays(range.start, i);
                var isToday = sameDay(day, today);
                head.appendChild(el('div', { 'class': 'ov-week__day' + (isToday ? ' ov-week__day--today' : '') }, [
                    el('span', { 'class': 'ov-week__dayname', text: DAYS[(day.getDay() + 6) % 7] }),
                    el('span', { 'class': 'ov-week__daynum', text: String(day.getDate()) })
                ]));
                var events = eventsOnDay(day);
                var allDayCell = el('div', { 'class': 'ov-week__alldaycell' });
                var column = el('div', { 'class': 'ov-week__col' + (isToday ? ' ov-week__col--today' : '') });
                for (var h2 = 0; h2 < 24; h2++) {
                    var slot = el('div', { 'class': 'ov-week__slot' + (h2 >= 8 && h2 < 18 ? ' ov-week__slot--work' : ''), 'data-hour': h2 });
                    column.appendChild(slot);
                }
                column.addEventListener('dblclick', function (e) {
                    var rect = column.getBoundingClientRect();
                    var hour = Math.floor((e.clientY - rect.top + column.scrollTop) / (rect.height / 24));
                    var start = new Date(day.getTime());
                    start.setHours(Math.max(0, Math.min(23, hour)), 0, 0, 0);
                    openEventDialog(null, start);
                });
                var dayStart = toTs(day);
                var dayEnd = toTs(addDays(day, 1));
                var timed = events.filter(function (event) {
                    if (event.all_day || (event.end - event.start) >= 86400) {
                        allDayCell.appendChild(eventNode(event, false));
                        return false;
                    }
                    return true;
                });
                // Spaltenlayout bei Überschneidungen
                var lanes = [];
                timed.forEach(function (event) {
                    var lane = 0;
                    while (lanes[lane] && lanes[lane] > event.start) {
                        lane++;
                    }
                    lanes[lane] = event.end;
                    event._lane = lane;
                });
                var laneCount = Math.max(1, lanes.length);
                timed.forEach(function (event) {
                    var s = Math.max(event.start, dayStart) - dayStart;
                    var e2 = Math.min(event.end, dayEnd) - dayStart;
                    var node = eventNode(event, true);
                    node.style.top = (s / 86400 * 100) + '%';
                    node.style.height = Math.max(1.6, (e2 - s) / 86400 * 100) + '%';
                    node.style.left = (event._lane / laneCount * 100) + '%';
                    node.style.width = (100 / laneCount - 1) + '%';
                    node.classList.add('ov-event--timed');
                    if (!event.all_day) {
                        node.appendChild(el('span', { 'class': 'ov-event__range', text: fmtTime(event.start) + ' – ' + fmtTime(event.end) + (event.location ? ' · ' + event.location : '') }));
                    }
                    column.appendChild(node);
                });
                if (isToday) {
                    var now = new Date();
                    var line = el('div', { 'class': 'ov-week__now' });
                    line.style.top = ((now.getHours() * 60 + now.getMinutes()) / 1440 * 100) + '%';
                    column.appendChild(line);
                }
                allDayRow.appendChild(allDayCell);
                grid.appendChild(column);
            })();
        }
        wrap.appendChild(head);
        wrap.appendChild(allDayRow);
        wrap.appendChild(grid);
        window.setTimeout(function () {
            grid.scrollTop = grid.scrollHeight * (7 / 24);
        }, 0);
        return wrap;
    }

    function renderAgenda() {
        var wrap = el('div', { 'class': 'ov-agenda' });
        if (!state.events.length) {
            wrap.appendChild(el('div', { 'class': 'ov-list__empty', text: 'Keine Termine in den nächsten 30 Tagen.' }));
            return wrap;
        }
        var lastDay = '';
        state.events.forEach(function (event) {
            var day = fmtDate(event.start);
            if (day !== lastDay) {
                var d = fromTs(event.start);
                wrap.appendChild(el('div', { 'class': 'ov-agenda__day' + (sameDay(d, new Date()) ? ' ov-agenda__day--today' : ''), text: DAYS_LONG[(d.getDay() + 6) % 7] + ', ' + day }));
                lastDay = day;
            }
            var row = el('button', { type: 'button', 'class': 'ov-agenda__item ' + eventClass(event), 'data-id': event.id }, [
                el('span', { 'class': 'ov-agenda__time', text: event.all_day ? 'Ganztägig' : fmtTime(event.start) + ' – ' + fmtTime(event.end) }),
                el('span', { 'class': 'ov-agenda__main' }, [
                    el('span', { 'class': 'ov-agenda__subject', text: event.subject || '(ohne Betreff)' }),
                    event.location ? el('span', { 'class': 'ov-agenda__location', text: event.location }) : null
                ]),
                event.is_meeting ? el('span', { 'class': 'ov-agenda__badge', text: 'Besprechung' }) : null
            ]);
            row.addEventListener('click', function () {
                openEvent(event);
            });
            wrap.appendChild(row);
        });
        return wrap;
    }

    function renderCalendarSidebar() {
        var head = hook('folders-title');
        if (head) {
            head.textContent = 'Kalender';
        }
        var body = hook('folders-body');
        if (!body) {
            return;
        }
        body.innerHTML = '';
        var view = new Date(state.calDate.getFullYear(), state.calDate.getMonth(), 1);
        var mini = el('div', { 'class': 'ov-minical' });
        var nav = el('div', { 'class': 'ov-minical__nav' });
        var prev = el('button', { type: 'button', 'class': 'ov-minical__btn', 'aria-label': 'Vorheriger Monat', text: '‹' });
        var next = el('button', { type: 'button', 'class': 'ov-minical__btn', 'aria-label': 'Nächster Monat', text: '›' });
        prev.addEventListener('click', function () {
            state.calDate = new Date(view.getFullYear(), view.getMonth() - 1, 1);
            loadCalendar();
        });
        next.addEventListener('click', function () {
            state.calDate = new Date(view.getFullYear(), view.getMonth() + 1, 1);
            loadCalendar();
        });
        nav.appendChild(prev);
        nav.appendChild(el('span', { 'class': 'ov-minical__title', text: MONTHS[view.getMonth()] + ' ' + view.getFullYear() }));
        nav.appendChild(next);
        mini.appendChild(nav);
        var grid = el('div', { 'class': 'ov-minical__grid' });
        DAYS.forEach(function (d) {
            grid.appendChild(el('span', { 'class': 'ov-minical__head', text: d.charAt(0) }));
        });
        var start = startOfWeek(view);
        var today = startOfDay(new Date());
        var range = calendarRange();
        for (var i = 0; i < 42; i++) {
            (function () {
                var day = addDays(start, i);
                var inRange = day >= range.start && day < range.end;
                var hasEvents = state.events.some(function (event) {
                    return event.start < toTs(addDays(day, 1)) && event.end > toTs(day);
                });
                var cell = el('button', {
                    type: 'button',
                    'class': 'ov-minical__day' + (day.getMonth() !== view.getMonth() ? ' ov-minical__day--other' : '') + (sameDay(day, today) ? ' ov-minical__day--today' : '') + (inRange ? ' ov-minical__day--range' : '') + (hasEvents ? ' ov-minical__day--events' : ''),
                    text: String(day.getDate())
                });
                cell.addEventListener('click', function () {
                    state.calDate = day;
                    if (state.calView === 'month') {
                        state.calView = 'day';
                    }
                    loadCalendar();
                });
                grid.appendChild(cell);
            })();
        }
        mini.appendChild(grid);
        body.appendChild(mini);

        var upcoming = state.events.filter(function (event) {
            return event.end >= toTs(new Date());
        }).slice(0, 5);
        if (upcoming.length) {
            body.appendChild(el('div', { 'class': 'ov-folders__section', text: 'Demnächst' }));
            upcoming.forEach(function (event) {
                var row = el('button', { type: 'button', 'class': 'ov-folder ov-folder--event' }, [
                    el('span', { 'class': 'ov-folder__name', text: event.subject || '(ohne Betreff)' }),
                    el('span', { 'class': 'ov-folder__count ov-folder__count--muted', text: fmtListDate(event.start) })
                ]);
                row.addEventListener('click', function () {
                    openEvent(event);
                });
                body.appendChild(row);
            });
        }
    }

    function openEvent(summary) {
        state.selected = summary;
        $$('[data-id]', hook('list-body')).forEach(function (node) {
            node.classList.toggle('ov-event--active', node.getAttribute('data-id') === summary.id);
        });
        updateActionState();
        showDetail(el('div', { 'class': 'ov-mail ov-mail--loading', text: 'Termin wird geladen …' }));
        api('/kalender/termin', { query: { id: summary.id } }).then(function (event) {
            if (!state.selected || state.selected.id !== event.id) {
                return;
            }
            state.selected = event;
            showDetail(renderEvent(event));
        }).catch(function (error) {
            showDetail(el('div', { 'class': 'ov-mail ov-mail--error', text: error.message }));
        });
    }

    var FREE_BUSY = { Free: 'Frei', Tentative: 'Mit Vorbehalt', Busy: 'Beschäftigt', OOF: 'Abwesend', WorkingElsewhere: 'An anderem Ort tätig' };
    var RESPONSES = { Accept: 'Zugesagt', Tentative: 'Mit Vorbehalt', Decline: 'Abgelehnt', Unknown: 'Keine Antwort', NoResponseReceived: 'Keine Antwort', Organizer: 'Organisator' };

    function renderEvent(event) {
        var wrap = el('div', { 'class': 'ov-mail ov-eventdetail' });
        var when = event.all_day
            ? fmtDate(event.start) + (event.end - event.start > 86400 ? ' – ' + fmtDate(event.end - 1) : '') + ' (ganztägig)'
            : fmtDate(event.start) + ', ' + fmtTime(event.start) + ' – ' + (sameDay(fromTs(event.start), fromTs(event.end)) ? '' : fmtDate(event.end) + ', ') + fmtTime(event.end);
        wrap.appendChild(el('header', { 'class': 'ov-mail__head' }, [
            el('div', { 'class': 'ov-eventdetail__stripe ov-event--' + String(event.free_busy || 'Busy').toLowerCase() }),
            el('h2', { 'class': 'ov-mail__subject', text: event.subject || '(ohne Betreff)' }),
            el('dl', { 'class': 'ov-eventdetail__facts' }, [
                el('dt', { text: 'Wann' }), el('dd', { text: when }),
                event.location ? el('dt', { text: 'Ort' }) : null, event.location ? el('dd', { text: event.location }) : null,
                el('dt', { text: 'Anzeigen als' }), el('dd', { text: FREE_BUSY[event.free_busy] || event.free_busy }),
                event.organizer && event.organizer.email ? el('dt', { text: 'Organisator' }) : null, event.organizer && event.organizer.email ? el('dd', { text: mailboxFull(event.organizer) }) : null,
                el('dt', { text: 'Erinnerung' }), el('dd', { text: event.reminder_set ? event.reminder_minutes + ' Minuten vorher' : 'Keine' }),
                event.recurring ? el('dt', { text: 'Serie' }) : null, event.recurring ? el('dd', { text: 'Dieser Termin ist Teil einer Serie.' }) : null
            ])
        ]));
        if (event.is_meeting && event.my_response !== 'Organizer') {
            var bar = el('div', { 'class': 'ov-mail__meeting' }, [el('span', { text: 'Ihre Antwort: ' + (RESPONSES[event.my_response] || 'Keine') + ' ' })]);
            [['Accept', 'Zusagen'], ['Tentative', 'Mit Vorbehalt'], ['Decline', 'Ablehnen']].forEach(function (pair) {
                var button = el('button', { type: 'button', 'class': 'button button--ghost', text: pair[1] });
                button.addEventListener('click', function () {
                    api('/kalender/antwort', { body: { id: event.id, response: pair[0] } }).then(function () {
                        toast('Antwort gesendet: ' + pair[1], 'success');
                        loadCalendar();
                    }).catch(function (error) {
                        toast(error.message, 'error');
                    });
                });
                bar.appendChild(button);
            });
            wrap.appendChild(bar);
        }
        var attendees = (event.required || []).concat(event.optional || []);
        if (attendees.length) {
            var list = el('ul', { 'class': 'ov-eventdetail__attendees' });
            attendees.forEach(function (person) {
                list.appendChild(el('li', {}, [
                    el('span', { 'class': 'ov-item__avatar ov-item__avatar--small', text: initials(person.name || person.email) }),
                    el('span', { text: person.name || person.email }),
                    el('span', { 'class': 'ov-eventdetail__response ov-eventdetail__response--' + String(person.response || 'Unknown').toLowerCase(), text: RESPONSES[person.response] || '' })
                ]));
            });
            wrap.appendChild(el('h3', { 'class': 'ov-eventdetail__h', text: 'Teilnehmer (' + attendees.length + ')' }));
            wrap.appendChild(list);
        }
        var attachments = (event.attachments || []).filter(function (a) { return !a.inline; });
        if (attachments.length) {
            wrap.appendChild(renderAttachments(attachments));
        }
        var body = el('div', { 'class': 'ov-mail__body' });
        body.innerHTML = event.body_html || '<p class="ov-muted">Keine Beschreibung.</p>';
        wrap.appendChild(body);
        return wrap;
    }

    function openEventDialog(event, startDate, meeting) {
        var form = hook('form-event');
        if (!form) {
            return;
        }
        form.reset();
        var title = hook('event-title', form.closest('dialog'));
        if (title) {
            title.textContent = event ? 'Termin bearbeiten' : (meeting ? 'Neue Besprechung' : 'Neuer Termin');
        }
        if (event) {
            form.elements.id.value = event.id;
            form.elements.change_key.value = event.change_key || '';
            form.elements.subject.value = event.subject || '';
            form.elements.location.value = event.location || '';
            form.elements.all_day.checked = !!event.all_day;
            form.elements.start.value = toLocalInput(event.start);
            form.elements.end.value = toLocalInput(event.all_day ? event.end - 60 : event.end);
            form.elements.reminder.value = event.reminder_set ? String(event.reminder_minutes) : '-1';
            form.elements.free_busy.value = event.free_busy || 'Busy';
            form.elements.required.value = (event.required || []).map(function (p) { return p.email; }).join('; ');
            form.elements.optional.value = (event.optional || []).map(function (p) { return p.email; }).join('; ');
            setEditorHtml(hook('event-body'), event.body_html || '');
        } else {
            setEditorHtml(hook('event-body'), '');
            var start = startDate ? new Date(startDate.getTime()) : new Date();
            if (!startDate) {
                start.setMinutes(start.getMinutes() < 30 ? 30 : 60, 0, 0);
            } else if (start.getHours() === 0 && start.getMinutes() === 0) {
                start.setHours(9);
            }
            form.elements.id.value = '';
            form.elements.change_key.value = '';
            form.elements.start.value = toLocalInput(toTs(start));
            form.elements.end.value = toLocalInput(toTs(start) + 1800);
            form.elements.reminder.value = String(config.reminderLead || 15);
            form.elements.free_busy.value = 'Busy';
        }
        var attendeeBlock = $('[data-ov-attendees]', form);
        if (attendeeBlock) {
            attendeeBlock.hidden = false;
            attendeeBlock.open = !!(meeting || (event && ((event.required || []).length || (event.optional || []).length)));
        }
        openDialog('event');
        form.elements.subject.focus();
    }

    function saveEvent(form) {
        var start = fromLocalInput(form.elements.start.value);
        var end = fromLocalInput(form.elements.end.value);
        var allDay = form.elements.all_day.checked;
        if (!form.elements.subject.value.trim()) {
            formError(form, 'Bitte einen Betreff angeben.');
            return;
        }
        if (!start || !end) {
            formError(form, 'Bitte Beginn und Ende angeben.');
            return;
        }
        if (allDay) {
            start = toTs(startOfDay(fromTs(start)));
            end = toTs(addDays(startOfDay(fromTs(end)), 1));
        }
        if (end <= start) {
            formError(form, 'Das Ende muss nach dem Beginn liegen.');
            return;
        }
        var payload = {
            id: form.elements.id.value,
            change_key: form.elements.change_key.value,
            subject: form.elements.subject.value.trim(),
            start: start,
            end: end,
            all_day: allDay,
            location: form.elements.location.value.trim(),
            body: editorHtml(hook('event-body')),
            reminder: parseInt(form.elements.reminder.value, 10),
            free_busy: form.elements.free_busy.value,
            required: parseRecipients(form.elements.required.value).map(function (box) { return box.email; }),
            optional: parseRecipients(form.elements.optional.value).map(function (box) { return box.email; })
        };
        $$('button', form).forEach(function (b) { b.disabled = true; });
        api('/kalender/termin', { body: payload }).then(function () {
            closeDialog('event');
            toast(payload.id ? 'Termin aktualisiert.' : 'Termin erstellt.', 'success');
            state.selected = null;
            showDetailEmpty();
            loadCalendar();
            syncReminders(true);
        }).catch(function (error) {
            formError(form, error.message);
        }).then(function () {
            $$('button', form).forEach(function (b) { b.disabled = false; });
        });
    }

    function deleteEvent() {
        if (!state.selected || !state.selected.id) {
            toast('Bitte zuerst einen Termin auswählen.', 'info');
            return;
        }
        if (!confirmAction('Termin „' + (state.selected.subject || '') + '“ wirklich löschen?')) {
            return;
        }
        api('/kalender/termin/loeschen', { body: { id: state.selected.id } }).then(function () {
            toast('Termin gelöscht.', 'success');
            state.selected = null;
            showDetailEmpty();
            loadCalendar();
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    // ------------------------------------------------------------------
    // Kontakte
    // ------------------------------------------------------------------

    function loadContacts() {
        listMessage('Kontakte werden geladen …', 'ov-list__empty--loading');
        return api('/kontakte', { query: { q: state.search } }).then(function (data) {
            state.contacts = data.items || [];
            renderContacts();
            renderSimpleSidebar('Kontakte', [{ label: 'Alle Kontakte', count: state.contacts.length }]);
            markSync();
        }).catch(function (error) {
            listMessage(error.message, 'ov-list__empty--error');
        });
    }

    function renderContacts() {
        var body = listBody();
        if (!body) {
            return;
        }
        setListTitle(state.search ? 'Suche: ' + state.search : 'Kontakte');
        setCount(state.contacts.length + ' Kontakt' + (state.contacts.length === 1 ? '' : 'e'));
        if (!state.contacts.length) {
            body.appendChild(el('div', { 'class': 'ov-list__empty', text: 'Keine Kontakte gefunden.' }));
        }
        var lastLetter = '';
        var sorted = state.contacts.slice().sort(function (a, b) {
            return (a.file_as || a.display_name || a.email || '').localeCompare(b.file_as || b.display_name || b.email || '', 'de', { sensitivity: 'base' });
        });
        sorted.forEach(function (contact) {
            var name = contact.file_as || contact.display_name || contact.email || '?';
            var letter = name.charAt(0).toUpperCase();
            if (letter !== lastLetter) {
                body.appendChild(el('div', { 'class': 'ov-list__group', text: letter }));
                lastLetter = letter;
            }
            var row = el('article', { 'class': 'ov-item ov-item--contact' + (state.selected && state.selected.id === contact.id ? ' ov-item--active' : ''), tabindex: '0', 'data-id': contact.id }, [
                el('div', { 'class': 'ov-item__avatar', text: initials(contact.display_name || contact.email) }),
                el('div', { 'class': 'ov-item__main' }, [
                    el('div', { 'class': 'ov-item__row' }, [el('span', { 'class': 'ov-item__from', text: contact.display_name || contact.email })]),
                    el('div', { 'class': 'ov-item__preview', text: [contact.job_title, contact.company].filter(Boolean).join(' · ') || contact.email })
                ])
            ]);
            row.addEventListener('click', function () {
                openContact(contact);
            });
            row.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    openContact(contact);
                }
            });
            body.appendChild(row);
        });
        updateActionState();
    }

    function openContact(summary) {
        state.selected = summary;
        $$('.ov-item', hook('list-body')).forEach(function (node) {
            node.classList.toggle('ov-item--active', node.getAttribute('data-id') === summary.id);
        });
        showDetail(renderContact(summary));
        api('/kontakte/kontakt', { query: { id: summary.id } }).then(function (contact) {
            if (state.selected && state.selected.id === contact.id) {
                state.selected = contact;
                showDetail(renderContact(contact));
            }
        }).catch(function () { /* Zusammenfassung bleibt sichtbar */ });
    }

    function renderContact(contact) {
        var card = el('div', { 'class': 'ov-card' });
        card.appendChild(el('div', { 'class': 'ov-card__head' }, [
            el('div', { 'class': 'ov-card__avatar', text: initials(contact.display_name || contact.email) }),
            el('div', {}, [
                el('h2', { 'class': 'ov-card__name', text: contact.display_name || contact.email || '(ohne Namen)' }),
                el('div', { 'class': 'ov-card__sub', text: [contact.job_title, contact.department, contact.company].filter(Boolean).join(' · ') })
            ])
        ]));
        var facts = el('dl', { 'class': 'ov-card__facts' });
        function fact(label, value, href) {
            if (!value) {
                return;
            }
            facts.appendChild(el('dt', { text: label }));
            facts.appendChild(el('dd', {}, [href ? el('a', { href: href, text: value }) : el('span', { text: value })]));
        }
        (contact.emails || (contact.email ? [contact.email] : [])).forEach(function (email, index) {
            fact(index === 0 ? 'E-Mail' : 'E-Mail ' + (index + 1), email, 'mailto:' + email);
        });
        fact('Telefon', contact.phone, 'tel:' + String(contact.phone || '').replace(/\s+/g, ''));
        fact('Mobil', contact.mobile, 'tel:' + String(contact.mobile || '').replace(/\s+/g, ''));
        if (contact.phones) {
            Object.keys(contact.phones).forEach(function (key) {
                if (['BusinessPhone', 'HomePhone', 'MobilePhone'].indexOf(key) === -1) {
                    fact(key.replace(/([A-Z])/g, ' $1').trim(), contact.phones[key]);
                }
            });
        }
        fact('Adresse', contact.address);
        fact('Firma', contact.company);
        fact('Abteilung', contact.department);
        card.appendChild(facts);
        if (contact.notes || contact.body) {
            card.appendChild(el('div', { 'class': 'ov-card__notes', text: contact.notes || contact.body }));
        }
        var actions = el('div', { 'class': 'ov-card__actions' });
        if (contact.email) {
            var mail = el('button', { type: 'button', 'class': 'button button--primary', text: '✉ E-Mail senden' });
            mail.addEventListener('click', function () {
                composeTo(contact);
            });
            actions.appendChild(mail);
        }
        var edit = el('button', { type: 'button', 'class': 'button', text: 'Bearbeiten' });
        edit.addEventListener('click', function () {
            openContactDialog(contact);
        });
        actions.appendChild(edit);
        card.appendChild(actions);
        return card;
    }

    function composeTo(contact) {
        switchModule('mail');
        window.setTimeout(function () {
            openCompose('new');
            var form = hook('form-compose');
            if (form) {
                form.elements.to.value = mailboxFull({ name: contact.display_name, email: contact.email });
                form.elements.subject.focus();
            }
        }, 50);
    }

    function openContactDialog(contact) {
        var form = hook('form-contact');
        if (!form) {
            return;
        }
        form.reset();
        var title = hook('contact-title', form.closest('dialog'));
        if (title) {
            title.textContent = contact ? 'Kontakt bearbeiten' : 'Neuer Kontakt';
        }
        form.elements.id.value = contact ? contact.id : '';
        if (contact) {
            ['given_name', 'surname', 'company', 'job_title', 'department', 'email', 'phone', 'mobile', 'notes'].forEach(function (key) {
                if (form.elements[key]) {
                    form.elements[key].value = contact[key] || (key === 'notes' ? contact.body || '' : '');
                }
            });
        }
        openDialog('contact');
    }

    function saveContact(form) {
        var payload = { id: form.elements.id.value };
        ['given_name', 'surname', 'company', 'job_title', 'department', 'email', 'phone', 'mobile', 'notes'].forEach(function (key) {
            payload[key] = form.elements[key] ? form.elements[key].value.trim() : '';
        });
        if (!(payload.given_name || payload.surname || payload.company)) {
            formError(form, 'Bitte mindestens Vorname, Nachname oder Firma angeben.');
            return;
        }
        api('/kontakte/kontakt', { body: payload }).then(function (result) {
            closeDialog('contact');
            toast(result.message || 'Kontakt gespeichert.', 'success');
            state.selected = null;
            showDetailEmpty();
            loadContacts();
        }).catch(function (error) {
            formError(form, error.message);
        });
    }

    function deleteContact() {
        if (!state.selected) {
            toast('Bitte zuerst einen Kontakt auswählen.', 'info');
            return;
        }
        if (!confirmAction('Kontakt „' + (state.selected.display_name || '') + '“ löschen?')) {
            return;
        }
        api('/kontakte/loeschen', { body: { ids: [state.selected.id] } }).then(function () {
            toast('Kontakt gelöscht.', 'success');
            state.selected = null;
            showDetailEmpty();
            loadContacts();
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    // ------------------------------------------------------------------
    // Aufgaben
    // ------------------------------------------------------------------

    var TASK_STATUS = { NotStarted: 'Nicht begonnen', InProgress: 'In Bearbeitung', Completed: 'Erledigt', WaitingOnOthers: 'Wartet auf andere', Deferred: 'Zurückgestellt' };

    function renderTasksSidebar() {
        var open = state.tasks.filter(function (t) { return !t.is_complete; }).length;
        var overdue = state.tasks.filter(function (t) { return !t.is_complete && t.due && t.due < toTs(startOfDay(new Date())); }).length;
        renderSimpleSidebar('Aufgaben', [{ label: 'Offene Aufgaben', count: open }].concat(overdue ? [{ label: 'Überfällig', count: overdue }] : []));
    }

    function loadTasks() {
        listMessage('Aufgaben werden geladen …', 'ov-list__empty--loading');
        var completedToggle = hook('tasks-completed');
        state.tasksCompleted = completedToggle ? completedToggle.checked : true;
        return api('/aufgaben', { query: { erledigt: state.tasksCompleted ? '1' : '0' } }).then(function (data) {
            state.tasks = (data.items || []).slice().sort(function (a, b) {
                if (a.is_complete !== b.is_complete) {
                    return a.is_complete ? 1 : -1;
                }
                return (a.due || 9e12) - (b.due || 9e12);
            });
            renderTasks();
            renderTasksSidebar();
            var count = $('[data-ov-module-count="tasks"]');
            var open = state.tasks.filter(function (t) { return !t.is_complete; }).length;
            if (count) {
                count.textContent = open ? String(open) : '';
                count.hidden = !open;
            }
            markSync();
        }).catch(function (error) {
            listMessage(error.message, 'ov-list__empty--error');
        });
    }

    function renderTasks() {
        var body = listBody();
        if (!body) {
            return;
        }
        var tasks = state.tasks.filter(function (task) {
            return !state.search || (task.subject || '').toLowerCase().indexOf(state.search.toLowerCase()) !== -1;
        });
        setListTitle('Aufgaben');
        setCount(tasks.length + ' Aufgabe' + (tasks.length === 1 ? '' : 'n'));
        if (!tasks.length) {
            body.appendChild(el('div', { 'class': 'ov-list__empty', text: 'Keine Aufgaben vorhanden.' }));
        }
        var today = toTs(startOfDay(new Date()));
        tasks.forEach(function (task) {
            var overdue = !task.is_complete && task.due && task.due < today;
            var row = el('article', { 'class': 'ov-item ov-item--task' + (task.is_complete ? ' ov-item--done' : '') + (overdue ? ' ov-item--overdue' : '') + (state.selected && state.selected.id === task.id ? ' ov-item--active' : ''), tabindex: '0', 'data-id': task.id });
            var box = el('input', { type: 'checkbox', 'class': 'ov-item__taskcheck', 'aria-label': 'Erledigt' });
            box.checked = !!task.is_complete;
            box.addEventListener('click', function (event) {
                event.stopPropagation();
                toggleTaskComplete(task, box.checked);
            });
            row.appendChild(el('label', { 'class': 'ov-item__check ov-item__check--visible' }, [box]));
            row.appendChild(el('div', { 'class': 'ov-item__main' }, [
                el('div', { 'class': 'ov-item__row' }, [
                    el('span', { 'class': 'ov-item__subject', text: task.subject || '(ohne Betreff)' }),
                    el('span', { 'class': 'ov-item__icons' }, [task.importance === 'High' ? el('span', { 'class': 'ov-item__important', text: '!' }) : null])
                ]),
                el('div', { 'class': 'ov-item__preview' }, [
                    el('span', { text: task.due ? 'Fällig ' + fmtDate(task.due) : 'Kein Fälligkeitsdatum' }),
                    el('span', { 'class': 'ov-item__badge', text: TASK_STATUS[task.status] || task.status }),
                    task.percent > 0 && task.percent < 100 ? el('span', { text: task.percent + ' %' }) : null
                ])
            ]));
            row.addEventListener('click', function () {
                openTask(task);
            });
            row.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    openTask(task);
                }
            });
            body.appendChild(row);
        });
        updateActionState();
    }

    function toggleTaskComplete(task, complete) {
        api('/aufgaben/aufgabe', { body: { id: task.id, subject: task.subject, body: task.body || '', importance: task.importance, status: complete ? 'Completed' : 'NotStarted', percent: complete ? 100 : 0, due: task.due || 0, start: task.start || 0 } }).then(function () {
            toast(complete ? 'Aufgabe erledigt.' : 'Aufgabe wieder geöffnet.', 'success');
            loadTasks();
        }).catch(function (error) {
            toast(error.message, 'error');
            loadTasks();
        });
    }

    function openTask(summary) {
        state.selected = summary;
        $$('.ov-item', hook('list-body')).forEach(function (node) {
            node.classList.toggle('ov-item--active', node.getAttribute('data-id') === summary.id);
        });
        showDetail(renderTask(summary));
        api('/aufgaben/aufgabe', { query: { id: summary.id } }).then(function (task) {
            if (state.selected && state.selected.id === task.id) {
                state.selected = task;
                showDetail(renderTask(task));
            }
        }).catch(function () { /* Zusammenfassung bleibt */ });
    }

    function renderTask(task) {
        var card = el('div', { 'class': 'ov-card ov-card--task' });
        card.appendChild(el('h2', { 'class': 'ov-card__name', text: task.subject || '(ohne Betreff)' }));
        var facts = el('dl', { 'class': 'ov-card__facts' });
        [['Status', TASK_STATUS[task.status] || task.status], ['Priorität', { High: 'Hoch', Low: 'Niedrig', Normal: 'Normal' }[task.importance] || task.importance], ['Beginn', task.start ? fmtDate(task.start) : ''], ['Fällig', task.due ? fmtDate(task.due) : ''], ['Erinnerung', task.reminder ? fmtDateTime(task.reminder) : ''], ['Erledigt am', task.completed_at ? fmtDate(task.completed_at) : '']].forEach(function (pair) {
            if (pair[1]) {
                facts.appendChild(el('dt', { text: pair[0] }));
                facts.appendChild(el('dd', { text: pair[1] }));
            }
        });
        card.appendChild(facts);
        card.appendChild(el('div', { 'class': 'ov-progress', title: task.percent + ' %' }, [el('span', { 'class': 'ov-progress__fill', style: 'width:' + (task.percent || 0) + '%' })]));
        if (task.body) {
            card.appendChild(el('div', { 'class': 'ov-card__notes', text: task.body }));
        }
        var actions = el('div', { 'class': 'ov-card__actions' });
        var complete = el('button', { type: 'button', 'class': 'button button--primary', text: task.is_complete ? 'Wieder öffnen' : '✓ Als erledigt markieren' });
        complete.addEventListener('click', function () {
            toggleTaskComplete(task, !task.is_complete);
        });
        var edit = el('button', { type: 'button', 'class': 'button', text: 'Bearbeiten' });
        edit.addEventListener('click', function () {
            openTaskDialog(task);
        });
        actions.appendChild(complete);
        actions.appendChild(edit);
        card.appendChild(actions);
        return card;
    }

    function openTaskDialog(task) {
        var form = hook('form-task');
        if (!form) {
            return;
        }
        form.reset();
        var title = hook('task-title', form.closest('dialog'));
        if (title) {
            title.textContent = task ? 'Aufgabe bearbeiten' : 'Neue Aufgabe';
        }
        form.elements.id.value = task ? task.id : '';
        if (task) {
            form.elements.subject.value = task.subject || '';
            form.elements.start.value = task.start ? toDateInput(fromTs(task.start)) : '';
            form.elements.due.value = task.due ? toDateInput(fromTs(task.due)) : '';
            form.elements.reminder.value = task.reminder ? toLocalInput(task.reminder) : '';
            form.elements.status.value = task.status || 'NotStarted';
            form.elements.importance.value = task.importance || 'Normal';
            form.elements.percent.value = String(task.percent || 0);
            form.elements.body.value = task.body || '';
        }
        openDialog('task');
    }

    function saveTask(form) {
        if (!form.elements.subject.value.trim()) {
            formError(form, 'Bitte einen Betreff angeben.');
            return;
        }
        var payload = {
            id: form.elements.id.value,
            subject: form.elements.subject.value.trim(),
            body: form.elements.body.value,
            status: form.elements.status.value,
            importance: form.elements.importance.value,
            percent: Math.max(0, Math.min(100, parseInt(form.elements.percent.value, 10) || 0)),
            start: form.elements.start.value ? toTs(new Date(form.elements.start.value + 'T00:00')) : 0,
            due: form.elements.due.value ? toTs(new Date(form.elements.due.value + 'T00:00')) : 0,
            reminder: fromLocalInput(form.elements.reminder.value)
        };
        if (payload.status === 'Completed') {
            payload.percent = 100;
        }
        api('/aufgaben/aufgabe', { body: payload }).then(function (result) {
            closeDialog('task');
            toast(result.message || 'Aufgabe gespeichert.', 'success');
            state.selected = null;
            showDetailEmpty();
            loadTasks();
        }).catch(function (error) {
            formError(form, error.message);
        });
    }

    function deleteTask() {
        if (!state.selected) {
            toast('Bitte zuerst eine Aufgabe auswählen.', 'info');
            return;
        }
        if (!confirmAction('Aufgabe „' + (state.selected.subject || '') + '“ löschen?')) {
            return;
        }
        api('/aufgaben/loeschen', { body: { ids: [state.selected.id] } }).then(function () {
            toast('Aufgabe gelöscht.', 'success');
            state.selected = null;
            showDetailEmpty();
            loadTasks();
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    // ------------------------------------------------------------------
    // Notizen
    // ------------------------------------------------------------------

    function loadNotes() {
        listMessage('Notizen werden geladen …', 'ov-list__empty--loading');
        return api('/notizen').then(function (data) {
            state.notes = data.items || [];
            renderNotes();
            renderSimpleSidebar('Notizen', [{ label: 'Alle Notizen', count: state.notes.length }]);
            markSync();
        }).catch(function (error) {
            listMessage(error.message, 'ov-list__empty--error');
        });
    }

    function renderNotes() {
        var body = listBody();
        if (!body) {
            return;
        }
        var notes = state.notes.filter(function (note) {
            return !state.search || ((note.subject || '') + ' ' + (note.preview || '')).toLowerCase().indexOf(state.search.toLowerCase()) !== -1;
        });
        setListTitle('Notizen');
        setCount(notes.length + ' Notiz' + (notes.length === 1 ? '' : 'en'));
        var grid = el('div', { 'class': 'ov-notes' });
        if (!notes.length) {
            grid.appendChild(el('div', { 'class': 'ov-list__empty', text: 'Keine Notizen vorhanden. Legen Sie mit „Neue Notiz“ los.' }));
        }
        notes.forEach(function (note) {
            var tile = el('button', { type: 'button', 'class': 'ov-note ov-note--' + String(note.color || 'yellow').toLowerCase() + (state.selected && state.selected.id === note.id ? ' ov-note--active' : ''), 'data-id': note.id }, [
                el('span', { 'class': 'ov-note__title', text: note.subject || '(ohne Titel)' }),
                el('span', { 'class': 'ov-note__preview', text: note.preview || '' }),
                el('span', { 'class': 'ov-note__date', text: note.modified ? fmtListDate(note.modified) : '' })
            ]);
            tile.addEventListener('click', function () {
                openNote(note);
            });
            grid.appendChild(tile);
        });
        body.appendChild(grid);
        updateActionState();
    }

    function openNote(summary) {
        state.selected = summary;
        state.noteDraft = false;
        $$('.ov-note', hook('list-body')).forEach(function (node) {
            node.classList.toggle('ov-note--active', node.getAttribute('data-id') === summary.id);
        });
        showDetail(el('div', { 'class': 'ov-mail ov-mail--loading', text: 'Notiz wird geladen …' }));
        api('/notizen/notiz', { query: { id: summary.id } }).then(function (note) {
            if (state.selected && state.selected.id === note.id) {
                state.selected = note;
                showDetail(renderNoteEditor(note));
            }
        }).catch(function (error) {
            showDetail(el('div', { 'class': 'ov-mail ov-mail--error', text: error.message }));
        });
    }

    function renderNoteEditor(note) {
        var wrap = el('div', { 'class': 'ov-noteeditor ov-note--' + String(note.color || 'yellow').toLowerCase() });
        var area = el('textarea', { 'class': 'ov-noteeditor__text', 'data-ov-note-text': '', 'aria-label': 'Notiztext', placeholder: 'Notiz eingeben … Die erste Zeile wird zum Titel.' });
        area.value = note.body || note.subject || '';
        var meta = el('div', { 'class': 'ov-noteeditor__meta', text: note.modified ? 'Zuletzt geändert ' + fmtDateTime(note.modified) : 'Neue Notiz – noch nicht gespeichert' });
        var save = el('button', { type: 'button', 'class': 'button button--primary', text: 'Speichern' });
        save.addEventListener('click', saveNote);
        area.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                event.preventDefault();
                saveNote();
            }
        });
        wrap.appendChild(area);
        wrap.appendChild(el('div', { 'class': 'ov-noteeditor__foot' }, [meta, save]));
        window.setTimeout(function () {
            area.focus();
        }, 30);
        return wrap;
    }

    function newNote() {
        state.selected = null;
        state.noteDraft = true;
        $$('.ov-note', hook('list-body')).forEach(function (node) {
            node.classList.remove('ov-note--active');
        });
        showDetail(renderNoteEditor({ id: '', body: '', color: 'yellow', modified: 0 }));
    }

    function saveNote() {
        var area = hook('note-text');
        if (!area) {
            return;
        }
        var text = area.value;
        if (!text.trim()) {
            toast('Die Notiz ist leer.', 'info');
            return;
        }
        api('/notizen/notiz', { body: { id: state.selected ? state.selected.id : '', body: text } }).then(function (result) {
            toast(result.message || 'Notiz gespeichert.', 'success');
            state.noteDraft = false;
            loadNotes().then(function () {
                var id = result.id || (state.selected && state.selected.id);
                var match = state.notes.filter(function (n) { return n.id === id; })[0];
                if (match) {
                    openNote(match);
                }
            });
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    function deleteNote() {
        if (!state.selected) {
            if (state.noteDraft) {
                state.noteDraft = false;
                showDetailEmpty();
            }
            return;
        }
        if (!confirmAction('Notiz löschen?')) {
            return;
        }
        api('/notizen/loeschen', { body: { ids: [state.selected.id] } }).then(function () {
            toast('Notiz gelöscht.', 'success');
            state.selected = null;
            showDetailEmpty();
            loadNotes();
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    // ------------------------------------------------------------------
    // Erinnerungen & Benachrichtigungen
    // ------------------------------------------------------------------

    var reminderTimer = null;
    var shownReminderIds = {};

    function notifyState() {
        if (!('Notification' in window)) {
            return 'Nicht unterstützt';
        }
        return { granted: 'Erlaubt', denied: 'Blockiert', 'default': 'Noch nicht erlaubt' }[window.Notification.permission] || window.Notification.permission;
    }

    function updateNotifyState() {
        var node = hook('notify-state');
        if (node) {
            node.textContent = notifyState();
        }
    }

    function requestNotifyPermission() {
        if (!('Notification' in window)) {
            toast('Dieser Browser unterstützt keine Desktop-Benachrichtigungen.', 'error');
            return;
        }
        window.Notification.requestPermission().then(function (result) {
            updateNotifyState();
            toast(result === 'granted' ? 'Desktop-Benachrichtigungen sind aktiv.' : 'Desktop-Benachrichtigungen wurden nicht erlaubt.', result === 'granted' ? 'success' : 'info');
        });
    }

    function playSound() {
        if (!state.prefs.sound) {
            return;
        }
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.08;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.25);
        } catch (e) {
            /* ignorieren */
        }
    }

    function desktopNotify(reminder) {
        if (!state.prefs.notify || !('Notification' in window) || window.Notification.permission !== 'granted') {
            return;
        }
        try {
            var note = new window.Notification(reminder.subject || 'Terminerinnerung', {
                body: (reminder.relative ? reminder.relative + ' – ' : '') + fmtTime(reminder.start) + (reminder.location ? ' · ' + reminder.location : ''),
                icon: '/assets/images/orvanta-logo.png',
                tag: 'orvanta-reminder-' + reminder.id,
                requireInteraction: true
            });
            note.onclick = function () {
                window.focus();
                switchModule('calendar');
                note.close();
            };
        } catch (e) {
            /* ignorieren */
        }
    }

    function syncReminders(force) {
        return api('/erinnerungen', { query: force ? { sync: '1' } : {} }).then(function (data) {
            state.reminders = { due: data.due || [], active: data.active || [] };
            renderReminders();
            var fresh = state.reminders.due.filter(function (reminder) {
                return !shownReminderIds[reminder.id];
            });
            if (fresh.length) {
                fresh.forEach(function (reminder) {
                    shownReminderIds[reminder.id] = true;
                    desktopNotify(reminder);
                });
                playSound();
                openReminderDialog(fresh);
            }
        }).catch(function () {
            /* leise scheitern – nächster Poll */
        });
    }

    function renderReminders() {
        var count = hook('reminders-count');
        var pending = state.reminders.active.filter(function (r) { return r.state !== 'dismissed'; });
        if (count) {
            count.textContent = String(pending.length);
            count.hidden = pending.length === 0;
        }
        var body = hook('reminders-body');
        if (!body) {
            return;
        }
        body.innerHTML = '';
        if (!pending.length) {
            body.appendChild(el('p', { 'class': 'ov-muted', text: 'Keine anstehenden Erinnerungen.' }));
            return;
        }
        pending.forEach(function (reminder) {
            body.appendChild(reminderRow(reminder, false));
        });
    }

    function reminderRow(reminder, inDialog) {
        var row = el('div', { 'class': 'ov-reminder' + (reminder.state === 'delivered' ? ' ov-reminder--due' : '') + (reminder.state === 'snoozed' ? ' ov-reminder--snoozed' : ''), 'data-reminder-id': reminder.id }, [
            el('div', { 'class': 'ov-reminder__main' }, [
                el('strong', { 'class': 'ov-reminder__subject', text: reminder.subject || 'Termin' }),
                el('span', { 'class': 'ov-reminder__when', text: fmtDateTime(reminder.start) + (reminder.location ? ' · ' + reminder.location : '') }),
                el('span', { 'class': 'ov-reminder__relative', text: reminder.relative || '' })
            ])
        ]);
        var actions = el('div', { 'class': 'ov-reminder__actions' });
        var open = el('button', { type: 'button', 'class': 'ov-mini ov-mini--text', title: 'Termin öffnen', text: 'Öffnen' });
        open.addEventListener('click', function () {
            closeDialog('reminder');
            toggleRemindersPopover(false);
            switchModule('calendar');
            window.setTimeout(function () {
                openEvent({ id: reminder.item_id, subject: reminder.subject });
            }, 50);
        });
        var snooze = el('button', { type: 'button', 'class': 'ov-mini ov-mini--text', title: 'Später erinnern', text: 'Später' });
        snooze.addEventListener('click', function () {
            snoozeReminder(reminder.id, inDialog ? snoozeMinutes() : 5);
        });
        var dismiss = el('button', { type: 'button', 'class': 'ov-mini', title: 'Schließen', text: '×' });
        dismiss.addEventListener('click', function () {
            dismissReminder(reminder.id);
        });
        actions.appendChild(open);
        actions.appendChild(snooze);
        actions.appendChild(dismiss);
        row.appendChild(actions);
        return row;
    }

    function snoozeMinutes() {
        var select = hook('snooze-minutes');
        return select ? parseInt(select.value, 10) || 5 : 5;
    }

    function openReminderDialog(reminders) {
        var body = hook('reminder-dialog-body');
        if (!body) {
            return;
        }
        var existing = $$('[data-reminder-id]', body).map(function (node) { return node.getAttribute('data-reminder-id'); });
        reminders.forEach(function (reminder) {
            if (existing.indexOf(String(reminder.id)) === -1) {
                body.appendChild(reminderRow(reminder, true));
            }
        });
        openDialog('reminder');
    }

    function dialogReminderIds() {
        return $$('[data-reminder-id]', hook('reminder-dialog-body')).map(function (node) { return parseInt(node.getAttribute('data-reminder-id'), 10); });
    }

    function removeReminderRows(id) {
        $$('[data-reminder-id="' + id + '"]').forEach(function (node) {
            node.remove();
        });
        var body = hook('reminder-dialog-body');
        if (body && !body.children.length) {
            closeDialog('reminder');
        }
    }

    function dismissReminder(id) {
        return api('/erinnerungen/erledigt', { body: { id: id } }).then(function () {
            removeReminderRows(id);
            state.reminders.active = state.reminders.active.filter(function (r) { return r.id !== id; });
            renderReminders();
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    function snoozeReminder(id, minutes) {
        return api('/erinnerungen/spaeter', { body: { id: id, minutes: minutes } }).then(function () {
            removeReminderRows(id);
            delete shownReminderIds[id];
            toast('Erinnerung in ' + minutes + ' Minuten erneut.', 'info');
            syncReminders(false);
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    function toggleRemindersPopover(show) {
        var popover = hook('reminders');
        if (!popover) {
            return;
        }
        popover.hidden = show === undefined ? !popover.hidden : !show;
    }

    function startReminderPolling() {
        var interval = Math.max(15, parseInt(config.pollInterval, 10) || 60) * 1000;
        syncReminders(true);
        reminderTimer = window.setInterval(function () {
            syncReminders(false);
        }, interval);
        // Serverseitiger Abgleich mit Exchange etwa alle fünf Minuten
        window.setInterval(function () {
            syncReminders(true);
        }, 5 * 60 * 1000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                syncReminders(false);
            }
        });
    }

    // ------------------------------------------------------------------
    // Zwischenspeicher / Quota & Einstellungen
    // ------------------------------------------------------------------

    function loadQuota() {
        return api('/zwischenspeicher').then(renderQuota).catch(function () {
            $$('[data-ov-quota-text], [data-ov-mailbox-text]').forEach(function (node) {
                node.textContent = 'Nicht verfügbar';
            });
        });
    }

    function renderQuota(usage) {
        var percent = Math.max(0, Math.min(100, Number(usage.percent) || 0));
        $$('[data-ov-quota-fill]').forEach(function (node) {
            node.style.width = percent + '%';
            node.classList.toggle('ov-quota__fill--warn', percent >= 80);
        });
        $$('[data-ov-quota-text]').forEach(function (node) {
            node.textContent = fmtBytes(usage.used) + ' von ' + fmtBytes(usage.quota) + ' (' + usage.items + ' Datei' + (usage.items === 1 ? '' : 'en') + ')';
        });
        renderMailboxUsage(usage.mailbox);
    }

    // Postfachbelegung auf dem Exchange (Groesse aller Ordner, Sendegrenze
    // bzw. im Adminbereich eingetragene Postfachgroesse)
    function renderMailboxUsage(mailbox) {
        var detail = $('[data-ov-mailbox-detail]');
        if (!mailbox) {
            $$('[data-ov-mailbox-fill]').forEach(function (node) {
                node.style.width = '0';
            });
            $$('[data-ov-mailbox-text]').forEach(function (node) {
                node.textContent = 'Nicht verfügbar';
            });
            if (detail) {
                detail.hidden = true;
            }
            return;
        }
        var limit = Number(mailbox.limit) || Number(mailbox.quota) || Number(mailbox.receive_limit) || Number(mailbox.warning) || 0;
        var percent = Math.max(0, Math.min(100, Number(mailbox.percent) || 0));
        var text = limit > 0 ? fmtBytes(mailbox.used) + ' von ' + fmtBytes(limit) : fmtBytes(mailbox.used) + ' (ohne Grenze)';
        $$('[data-ov-mailbox-fill]').forEach(function (node) {
            node.style.width = (limit > 0 ? percent : 0) + '%';
            node.classList.toggle('ov-quota__fill--warn', limit > 0 && percent >= 80);
        });
        $$('[data-ov-mailbox-text]').forEach(function (node) {
            node.textContent = text;
        });
        if (detail) {
            var parts = [];
            if (Number(mailbox.warning) > 0) {
                parts.push('Warnung ab ' + fmtBytes(mailbox.warning));
            }
            if (Number(mailbox.quota) > 0) {
                parts.push('Senden gesperrt ab ' + fmtBytes(mailbox.quota));
            }
            if (Number(mailbox.receive_limit) > 0) {
                parts.push('Empfang gesperrt ab ' + fmtBytes(mailbox.receive_limit));
            }
            detail.textContent = parts.join(' · ');
            detail.hidden = parts.length === 0;
        }
    }

    function clearCache() {
        if (!confirmAction('Alle zwischengespeicherten Anhänge aus Ihrem Nextcloud-Bereich entfernen?')) {
            return;
        }
        api('/zwischenspeicher/leeren', { body: {} }).then(function (usage) {
            renderQuota(usage);
            toast(usage.message || 'Zwischenspeicher geleert.', 'success');
        }).catch(function (error) {
            toast(error.message, 'error');
        });
    }

    function renderSettingsInfo() {
        var dl = hook('settings-info');
        if (!dl) {
            return;
        }
        dl.innerHTML = '';
        [['Postfach', config.user && config.user.email], ['Modus', config.demo ? 'Demo (ohne Exchange-Verbindung)' : 'Exchange Web Services (SSO)'], ['Erinnerungsvorlauf', (config.reminderLead || 15) + ' Minuten'], ['Abfrageintervall', (config.pollInterval || 60) + ' Sekunden'], ['Euro-Office', config.officeAvailable ? 'Verfügbar' : 'Nicht konfiguriert'], ['Nextcloud', config.nextcloudAvailable ? 'Verfügbar' : 'Nicht konfiguriert']].forEach(function (pair) {
            dl.appendChild(el('dt', { text: pair[0] }));
            dl.appendChild(el('dd', { text: String(pair[1] || '–') }));
        });
    }

    function applyPrefs() {
        root.classList.toggle('ov--dense', !!state.prefs.dense);
        root.classList.toggle('ov--no-folders', state.prefs.folders === false);
        root.classList.toggle('ov--no-reading', state.prefs.reading === false);
        $$('[data-ov-pref]').forEach(function (box) {
            box.checked = !!state.prefs[box.getAttribute('data-ov-pref')];
        });
        var tf = $('[data-ov-action="toggle-folders"]');
        if (tf) {
            tf.setAttribute('aria-pressed', state.prefs.folders === false ? 'false' : 'true');
        }
        var tr = $('[data-ov-action="toggle-reading"]');
        if (tr) {
            tr.setAttribute('aria-pressed', state.prefs.reading === false ? 'false' : 'true');
        }
    }

    // ------------------------------------------------------------------
    // Aktionen & Ereignisse
    // ------------------------------------------------------------------

    function runAction(name, button) {
        var value = button ? button.getAttribute('data-ov-value') : null;
        switch (name) {
            case 'compose': openCompose('new'); break;
            case 'new-menu':
                if (state.module === 'mail') { openCompose('new'); }
                else if (state.module === 'calendar') { openEventDialog(null, null); }
                else if (state.module === 'contacts') { openContactDialog(null); }
                else if (state.module === 'tasks') { openTaskDialog(null); }
                else { newNote(); }
                break;
            case 'reply': if (state.selected) { openCompose('reply', state.selected); } break;
            case 'replyall': if (state.selected) { openCompose('replyall', state.selected); } break;
            case 'forward': if (state.selected) { openCompose('forward', state.selected); } break;
            case 'mail-delete': mailAction(state.folder === 'deleteditems' ? 'delete_permanent' : 'delete'); break;
            case 'mail-archive': {
                var archive = state.folders.filter(function (f) { return /^archiv/i.test(f.name); })[0];
                if (archive) { mailAction('move', null, folderKey(archive)); } else { openMoveDialog(); }
                break;
            }
            case 'mail-move': openMoveDialog(); break;
            case 'toggle-read': {
                var target = state.selected || state.messages.filter(function (m) { return m.id === state.selectedIds[0]; })[0];
                mailAction(target && target.is_read ? 'unread' : 'read');
                break;
            }
            case 'toggle-flag': {
                var flagged = state.selected || state.messages.filter(function (m) { return m.id === state.selectedIds[0]; })[0];
                mailAction(flagged && flagged.flagged ? 'unflag' : 'flag');
                break;
            }
            case 'print': window.print(); break;
            case 'refresh': loadModule(); loadQuota(); syncReminders(true); break;
            case 'more': loadMessages(true); break;
            case 'event-new': openEventDialog(null, state.module === 'calendar' ? state.calDate : null); break;
            case 'meeting-new': openEventDialog(null, state.calDate, true); break;
            case 'cal-today': state.calDate = startOfDay(new Date()); loadCalendar(); break;
            case 'cal-prev':
            case 'cal-next': {
                var dir = name === 'cal-next' ? 1 : -1;
                if (state.calView === 'month') {
                    state.calDate = new Date(state.calDate.getFullYear(), state.calDate.getMonth() + dir, 1);
                } else if (state.calView === 'day') {
                    state.calDate = addDays(state.calDate, dir);
                } else if (state.calView === 'agenda') {
                    state.calDate = addDays(state.calDate, dir * 30);
                } else {
                    state.calDate = addDays(state.calDate, dir * 7);
                }
                loadCalendar();
                break;
            }
            case 'cal-view': if (value) { state.calView = value; loadCalendar(); } break;
            case 'event-edit': if (state.selected) { openEventDialog(state.selected); } break;
            case 'event-delete': deleteEvent(); break;
            case 'contact-new': openContactDialog(null); break;
            case 'contact-edit': if (state.selected) { openContactDialog(state.selected); } break;
            case 'contact-delete': deleteContact(); break;
            case 'contact-mail': if (state.selected) { composeTo(state.selected); } break;
            case 'contact-meeting':
                if (state.selected) {
                    var email = state.selected.email;
                    switchModule('calendar');
                    window.setTimeout(function () {
                        openEventDialog(null, null, true);
                        var form = hook('form-event');
                        if (form && email) {
                            form.elements.required.value = email;
                        }
                    }, 50);
                }
                break;
            case 'task-new': openTaskDialog(null); break;
            case 'task-complete': if (state.selected) { toggleTaskComplete(state.selected, !state.selected.is_complete); } break;
            case 'task-edit': if (state.selected) { openTaskDialog(state.selected); } break;
            case 'task-delete': deleteTask(); break;
            case 'note-new': newNote(); break;
            case 'note-save': saveNote(); break;
            case 'note-delete': deleteNote(); break;
            case 'toggle-folders': state.prefs.folders = state.prefs.folders === false; savePrefs(); applyPrefs(); break;
            case 'toggle-reading': state.prefs.reading = state.prefs.reading === false; savePrefs(); applyPrefs(); break;
            case 'notify-permission': requestNotifyPermission(); break;
            case 'notify-test':
                desktopNotify({ id: 'test', subject: 'Testbenachrichtigung', start: toTs(new Date()) + 600, relative: 'in 10 Minuten', location: 'Orvanta' });
                toast('Testbenachrichtigung ausgelöst.', 'info');
                playSound();
                break;
            case 'cache-clear': clearCache(); break;
            case 'headers-copy': copyHeaders(); break;
            case 'send': sendCompose(hook('form-compose'), false); break;
            case 'save-draft': sendCompose(hook('form-compose'), true); break;
            case 'compose-detach': detachCompose(); break;
            case 'snooze-all': {
                var minutes = snoozeMinutes();
                Promise.all(dialogReminderIds().map(function (id) { return snoozeReminder(id, minutes); })).then(function () { closeDialog('reminder'); });
                break;
            }
            case 'dismiss-all':
                Promise.all(dialogReminderIds().map(dismissReminder)).then(function () { closeDialog('reminder'); });
                break;
            default: break;
        }
    }

    function bindEvents() {
        root.addEventListener('click', function (event) {
            var target = event.target.closest('[data-ov-action], [data-ov-module], [data-ov-tab], [data-ov-dialog-open], [data-ov-dialog-close], [data-ov-fmt], [data-ov-reminders-toggle], [data-ov-reminders-close]');
            if (!target) {
                return;
            }
            if (target.hasAttribute('data-ov-action')) {
                event.preventDefault();
                runAction(target.getAttribute('data-ov-action'), target);
            } else if (target.hasAttribute('data-ov-module')) {
                switchModule(target.getAttribute('data-ov-module'));
            } else if (target.hasAttribute('data-ov-tab')) {
                selectTab(target.getAttribute('data-ov-tab'));
            } else if (target.hasAttribute('data-ov-dialog-open')) {
                var name = target.getAttribute('data-ov-dialog-open');
                if (name === 'settings') {
                    renderSettingsInfo();
                    updateNotifyState();
                    loadQuota();
                }
                openDialog(name);
            } else if (target.hasAttribute('data-ov-dialog-close')) {
                closeDialog(target.closest('dialog'));
            } else if (target.hasAttribute('data-ov-fmt')) {
                event.preventDefault();
                applyFormat(target.getAttribute('data-ov-fmt'));
            } else if (target.hasAttribute('data-ov-reminders-toggle')) {
                toggleRemindersPopover();
            } else if (target.hasAttribute('data-ov-reminders-close')) {
                toggleRemindersPopover(false);
            }
        });

        document.addEventListener('click', function (event) {
            var popover = hook('reminders');
            if (popover && !popover.hidden && !event.target.closest('[data-ov-reminders], [data-ov-reminders-toggle]')) {
                popover.hidden = true;
            }
        });

        $$('[data-ov-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var kind = form.getAttribute('data-ov-form');
                if (kind === 'event') { saveEvent(form); }
                else if (kind === 'contact') { saveContact(form); }
                else if (kind === 'task') { saveTask(form); }
                else if (kind === 'compose') { sendCompose(form, false); }
                else if (kind === 'folder-new') { createFolder(form); }
                else if (kind === 'move') { submitMove(form); }
            });
        });

        var attachInput = hook('attach-input');
        if (attachInput) {
            attachInput.addEventListener('change', function () {
                addComposeFiles(attachInput.files);
                attachInput.value = '';
            });
        }
        var composeBody = hook('compose-body');
        if (composeBody) {
            guardComposeSignature(composeBody);
            composeBody.addEventListener('paste', function (event) {
                var text = event.clipboardData && event.clipboardData.getData('text/plain');
                if (text) {
                    event.preventDefault();
                    document.execCommand('insertText', false, text);
                }
            });
            composeBody.addEventListener('drop', function (event) {
                if (event.dataTransfer && event.dataTransfer.files.length) {
                    event.preventDefault();
                    addComposeFiles(event.dataTransfer.files);
                }
            });
        }

        var filter = hook('list-filter');
        if (filter) {
            filter.addEventListener('change', function () {
                state.filter = filter.value;
                if (state.module === 'mail') {
                    renderMessages();
                }
            });
        }

        var search = hook('search');
        if (search) {
            var timer = null;
            search.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    state.search = search.value.trim();
                    if (state.module === 'mail') { loadMessages(); }
                    else if (state.module === 'contacts') { loadContacts(); }
                    else if (state.module === 'tasks') { renderTasks(); }
                    else if (state.module === 'notes') { renderNotes(); }
                }, 350);
            });
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    search.value = '';
                    search.dispatchEvent(new Event('input'));
                    search.blur();
                }
            });
        }

        var completed = hook('tasks-completed');
        if (completed) {
            completed.addEventListener('change', loadTasks);
        }

        $$('[data-ov-pref]').forEach(function (box) {
            box.addEventListener('change', function () {
                state.prefs[box.getAttribute('data-ov-pref')] = box.checked;
                savePrefs();
                applyPrefs();
                if (state.module === 'mail') {
                    renderMessages();
                    if (state.selected && state.selected.body_html !== undefined) {
                        showDetail(renderMessage(state.selected));
                    }
                }
            });
        });

        var eventForm = hook('form-event');
        if (eventForm) {
            eventForm.elements.start.addEventListener('change', function () {
                var start = fromLocalInput(eventForm.elements.start.value);
                var end = fromLocalInput(eventForm.elements.end.value);
                if (start && (!end || end <= start)) {
                    eventForm.elements.end.value = toLocalInput(start + 1800);
                }
            });
        }

        document.addEventListener('keydown', function (event) {
            var tag = (event.target.tagName || '').toLowerCase();
            var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable || event.target.closest('dialog[open]');
            if (event.key === 'Escape') {
                toggleRemindersPopover(false);
                return;
            }
            if (typing || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }
            var modules = ['mail', 'calendar', 'contacts', 'tasks', 'notes'];
            if (/^[1-5]$/.test(event.key)) {
                switchModule(modules[parseInt(event.key, 10) - 1]);
            } else if (event.key === '/') {
                event.preventDefault();
                if (search) { search.focus(); }
            } else if (event.key === 'n' || event.key === 'N') {
                runAction('new-menu');
            } else if ((event.key === 'r' || event.key === 'R') && state.module === 'mail' && state.selected) {
                runAction('reply');
            } else if (event.key === 'Delete') {
                if (state.module === 'mail') { runAction('mail-delete'); }
                else if (state.module === 'calendar') { runAction('event-delete'); }
                else if (state.module === 'contacts') { runAction('contact-delete'); }
                else if (state.module === 'tasks') { runAction('task-delete'); }
                else if (state.module === 'notes') { runAction('note-delete'); }
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                var rows = $$('.ov-item', hook('list-body'));
                if (!rows.length) {
                    return;
                }
                var index = rows.findIndex(function (row) { return row.classList.contains('ov-item--active'); });
                var next = rows[Math.max(0, Math.min(rows.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1)))];
                if (next) {
                    event.preventDefault();
                    next.click();
                    next.focus();
                }
            }
        });

        window.addEventListener('beforeprint', function () {
            root.classList.add('ov--printing');
        });
        window.addEventListener('afterprint', function () {
            root.classList.remove('ov--printing');
        });
    }

    function selectTab(name) {
        $$('[data-ov-tab]').forEach(function (tab) {
            tab.setAttribute('aria-selected', tab.getAttribute('data-ov-tab') === name ? 'true' : 'false');
        });
        $$('[data-ov-panel]').forEach(function (panel) {
            var belongs = panel.getAttribute('data-ov-panel') === name;
            var modules = (panel.getAttribute('data-ov-for') || 'all').split(/\s+/);
            panel.hidden = !belongs || (modules.indexOf('all') === -1 && modules.indexOf(state.module) === -1);
        });
    }

    // Beim Modulwechsel nur die Panels des aktiven Tabs einblenden
    var originalSwitch = switchModule;
    switchModule = function (name) {
        originalSwitch(name);
        var active = $$('[data-ov-tab]').filter(function (tab) { return tab.getAttribute('aria-selected') === 'true'; })[0];
        selectTab(active ? active.getAttribute('data-ov-tab') : 'start');
    };

    // ------------------------------------------------------------------
    // Start
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // App-Kontextmenue (Ordnerbaum, Mail-Liste, Textfelder, KI-Unterstuetzung)
    // ------------------------------------------------------------------

    var MOD_KEY = /Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌘' : 'Strg+';

    var ctx = {
        menu: null,
        origin: null        // Element, von dem aus das Menue geoeffnet wurde (Fokusrueckgabe)
    };

    function ctxHide() {
        if (ctx.menu && !ctx.menu.hidden) {
            ctx.menu.hidden = true;
            ctx.menu.innerHTML = '';
        }
    }

    /**
     * Menue mit Eintraegen { label, run, icon?, key?, title?, disabled?, ai? }
     * bzw. { separator: true } an der Mausposition anzeigen. Liegt der Ursprung
     * in einem modalen Dialog (Top-Layer), wandert das Menue dort hinein.
     */
    function ctxShow(event, items, origin) {
        var menu = ctx.menu;
        if (!menu) {
            return false;
        }
        var cleaned = [];
        items.forEach(function (item) {
            if (!item) {
                return;
            }
            if (item.separator) {
                if (cleaned.length && !cleaned[cleaned.length - 1].separator) {
                    cleaned.push(item);
                }
                return;
            }
            cleaned.push(item);
        });
        while (cleaned.length && cleaned[cleaned.length - 1].separator) {
            cleaned.pop();
        }
        if (!cleaned.length) {
            return false;
        }
        menu.innerHTML = '';
        cleaned.forEach(function (item) {
            if (item.separator) {
                menu.appendChild(el('div', { 'class': 'ov-ctx-menu__sep', role: 'separator' }));
                return;
            }
            var icon = typeof item.icon === 'string' || !item.icon
                ? el('span', { 'class': 'ov-ctx-menu__icon', 'aria-hidden': 'true', text: item.icon || '' })
                : item.icon;
            var button = el('button', { type: 'button', role: 'menuitem', 'class': 'ov-ctx-menu__item' + (item.ai ? ' ov-ctx-menu__item--ai' : ''), title: item.title || null }, [
                icon,
                el('span', { 'class': 'ov-ctx-menu__label', text: item.label }),
                item.key ? el('span', { 'class': 'ov-ctx-menu__key', 'aria-hidden': 'true', text: item.key }) : null
            ]);
            button.disabled = !!item.disabled;
            button.addEventListener('click', function () {
                ctxHide();
                item.run();
            });
            menu.appendChild(button);
        });
        ctx.origin = origin || null;
        var host = (origin && origin.closest && origin.closest('dialog')) || document.body;
        if (menu.parentNode !== host) {
            host.appendChild(menu);
        }
        menu.hidden = false;
        var x = event.clientX;
        var y = event.clientY;
        if (!x && !y && origin && origin.getBoundingClientRect) {
            // Tastatur (Shift+F10 / Menuetaste): am Ursprungselement ausrichten.
            var rect = origin.getBoundingClientRect();
            x = rect.left + Math.min(rect.width / 2, 24);
            y = Math.min(rect.bottom, window.innerHeight - 8);
        }
        // Innerhalb des Fensters halten; Position ueber das CSSOM (CSP).
        var width = menu.offsetWidth || 220;
        var height = menu.offsetHeight || 120;
        var left = Math.min(x, window.innerWidth - width - 8);
        var top = Math.min(y, window.innerHeight - height - 8);
        menu.style.cssText = 'left:' + Math.max(8, left) + 'px;top:' + Math.max(8, top) + 'px;';
        return true;
    }

    function ctxEditableField(target) {
        if (!target || target.nodeType !== 1) {
            return null;
        }
        var field = target.closest('input, textarea, [contenteditable]');
        if (!field) {
            return null;
        }
        if (field.tagName === 'INPUT') {
            var type = (field.getAttribute('type') || 'text').toLowerCase();
            return ['text', 'search', 'email', 'url', 'tel', 'password', 'number'].indexOf(type) === -1 ? null : field;
        }
        return field.tagName === 'TEXTAREA' || field.isContentEditable ? field : null;
    }

    /**
     * Auswahl sichern: Beim Klick ins Menue darf die Markierung im Feld nicht
     * verloren gehen, sonst greifen Ausschneiden/Kopieren ins Leere.
     */
    function ctxSelectionSnapshot(field) {
        if (field.isContentEditable) {
            var selection = window.getSelection();
            if (selection && selection.rangeCount && field.contains(selection.getRangeAt(0).commonAncestorContainer)) {
                var range = selection.getRangeAt(0).cloneRange();
                return { range: range, text: range.toString() };
            }
            return { range: null, text: '' };
        }
        var start = null;
        var end = null;
        try {
            start = field.selectionStart;
            end = field.selectionEnd;
        } catch (e) {
            // z. B. type=number: keine Auswahl-API
        }
        return { start: start, end: end, text: start !== null && end !== null ? String(field.value).slice(start, end) : '' };
    }

    function ctxRestoreSelection(field, snapshot) {
        field.focus();
        if (field.isContentEditable) {
            if (snapshot.range) {
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(snapshot.range);
            }
        } else if (snapshot.start !== null && snapshot.start !== undefined) {
            try {
                field.setSelectionRange(snapshot.start, snapshot.end);
            } catch (e) { /* nicht unterstuetzt */ }
        }
    }

    function ctxExec(command, field, snapshot) {
        ctxRestoreSelection(field, snapshot);
        try {
            return document.execCommand(command);
        } catch (e) {
            return false;
        }
    }

    function ctxInsertText(field, text) {
        var ok = false;
        try {
            // insertText erhaelt die Rueckgaengig-Historie des Browsers.
            ok = document.execCommand('insertText', false, text);
        } catch (e) {
            ok = false;
        }
        if (ok) {
            return;
        }
        if (field.isContentEditable) {
            var selection = window.getSelection();
            if (selection && selection.rangeCount) {
                var range = selection.getRangeAt(0);
                range.deleteContents();
                var node = document.createTextNode(text);
                range.insertNode(node);
                range.setStartAfter(node);
                range.collapse(true);
                selection.removeAllRanges();
                selection.addRange(range);
            } else {
                field.appendChild(document.createTextNode(text));
            }
        } else {
            var start = field.selectionStart;
            var end = field.selectionEnd;
            if (start === null || start === undefined) {
                field.value += text;
            } else {
                field.setRangeText(text, start, end, 'end');
            }
        }
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function ctxPaste(field, snapshot) {
        if (!(navigator.clipboard && navigator.clipboard.readText)) {
            ctxRestoreSelection(field, snapshot);
            toast('Einfügen über das Menü wird von diesem Browser nicht unterstützt – bitte ' + MOD_KEY + 'V verwenden.', 'info');
            return;
        }
        navigator.clipboard.readText().then(function (text) {
            ctxRestoreSelection(field, snapshot);
            if (text !== '') {
                ctxInsertText(field, text);
            }
        }).catch(function () {
            ctxRestoreSelection(field, snapshot);
            toast('Der Browser hat den Zugriff auf die Zwischenablage nicht erlaubt – bitte ' + MOD_KEY + 'V verwenden.', 'info');
        });
    }

    function ctxSelectAll(field) {
        field.focus();
        if (field.isContentEditable) {
            var range = document.createRange();
            range.selectNodeContents(field);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        } else {
            field.select();
        }
    }

    function editMenuItems(field) {
        var snapshot = ctxSelectionSnapshot(field);
        var hasText = snapshot.text !== '';
        var editable = field.isContentEditable || !(field.readOnly || field.disabled);
        var secret = field.tagName === 'INPUT' && (field.getAttribute('type') || '').toLowerCase() === 'password';
        var hasContent = field.isContentEditable ? (field.textContent || '').trim() !== '' : String(field.value) !== '';
        return [
            { label: 'Rückgängig', key: MOD_KEY + 'Z', disabled: !editable, run: function () { ctxExec('undo', field, snapshot); } },
            { label: 'Wiederholen', key: MOD_KEY + 'Y', disabled: !editable, run: function () { ctxExec('redo', field, snapshot); } },
            { separator: true },
            { label: 'Ausschneiden', key: MOD_KEY + 'X', disabled: !editable || !hasText || secret, run: function () { ctxExec('cut', field, snapshot); } },
            { label: 'Kopieren', key: MOD_KEY + 'C', disabled: !hasText || secret, run: function () { ctxExec('copy', field, snapshot); } },
            { label: 'Einfügen', key: MOD_KEY + 'V', disabled: !editable, run: function () { ctxPaste(field, snapshot); } },
            { separator: true },
            { label: 'Alles auswählen', key: MOD_KEY + 'A', disabled: !hasContent, run: function () { ctxSelectAll(field); } }
        ];
    }

    function mailMenuItems(row) {
        var id = row.getAttribute('data-id');
        var message = state.messages.filter(function (m) { return m.id === id; })[0];
        if (!message) {
            return [];
        }
        var checked = state.selectedIds.indexOf(id) !== -1;
        if (!checked && !(state.selected && state.selected.id === id)) {
            openMessage(message); // Rechtsklick waehlt die Nachricht wie ein Linksklick aus.
        }
        var ids = checked ? state.selectedIds.slice() : [id];
        var targets = state.messages.filter(function (m) { return ids.indexOf(m.id) !== -1; });
        var many = ids.length > 1;
        var suffix = many ? ' (' + ids.length + ')' : '';
        var drafts = state.folder === 'drafts';
        var trash = state.folder === 'deleteditems';
        var anyUnread = targets.some(function (m) { return !m.is_read; });
        var anyUnflagged = targets.some(function (m) { return !m.flagged; });
        var compose = function (mode) {
            return function () {
                withFullMessage(message).then(function (full) {
                    if (full) {
                        openCompose(mode, full);
                    }
                });
            };
        };
        var items = [];
        if (drafts) {
            items.push({ label: 'Entwurf bearbeiten', icon: '📝', disabled: many, run: compose('draft') });
        } else {
            items.push({ label: 'Öffnen', icon: '📨', disabled: many, run: function () { openMessage(message); } });
            items.push({ separator: true });
            items.push({ label: 'Antworten', icon: '↩', key: 'R', disabled: many, run: compose('reply') });
            items.push({ label: 'Allen antworten', icon: '↩', disabled: many, run: compose('replyall') });
            items.push({ label: 'Weiterleiten', icon: '↪', disabled: many, run: compose('forward') });
        }
        items.push({ separator: true });
        items.push({ label: (anyUnread ? 'Als gelesen markieren' : 'Als ungelesen markieren') + suffix, icon: anyUnread ? '✉' : '📩', run: function () { mailAction(anyUnread ? 'read' : 'unread', ids); } });
        items.push({ label: (anyUnflagged ? 'Kennzeichnen' : 'Kennzeichnung entfernen') + suffix, icon: '⚑', run: function () { mailAction(anyUnflagged ? 'flag' : 'unflag', ids); } });
        items.push({ separator: true });
        items.push({ label: 'Verschieben …' + suffix, icon: '📁', run: function () { openMoveDialog(ids); } });
        if (!trash) {
            items.push({ label: 'Archivieren' + suffix, icon: '🗄', run: function () {
                var archive = state.folders.filter(function (f) { return /^archiv/i.test(f.name); })[0];
                if (archive) {
                    mailAction('move', ids, folderKey(archive));
                } else {
                    openMoveDialog(ids);
                }
            } });
        }
        items.push({ label: (trash ? 'Endgültig löschen' : 'Löschen') + suffix, icon: '🗑', key: 'Entf', run: function () { mailAction(trash ? 'delete_permanent' : 'delete', ids); } });
        items.push({ separator: true });
        items.push({ label: 'Info', icon: 'ℹ', disabled: many, run: function () { openHeadersDialog(message); } });
        return items;
    }

    function folderMenuItems(node) {
        var key = node.getAttribute('data-folder');
        var folder = state.folders.filter(function (f) { return folderKey(f) === key; })[0];
        if (!folder) {
            return [];
        }
        return [
            { label: 'Öffnen', icon: '📂', disabled: key === state.folder, run: function () { selectFolder(key, folderLabel(folder)); } },
            { separator: true },
            { label: 'Neuer Ordner …', icon: '📁', run: function () { openNewFolderDialog(folder); } },
            { label: 'Alle als gelesen markieren', icon: '✉', disabled: !(folder.unread > 0), run: function () { markFolderRead(folder); } },
            { separator: true },
            { label: 'Eigenschaften', icon: 'ℹ', run: function () { openFolderProperties(folder); } }
        ];
    }

    function onContextMenu(event) {
        if (!ctx.menu || event.defaultPrevented) {
            return;
        }
        var target = event.target && event.target.nodeType === 1 ? event.target : (event.target && event.target.parentNode);
        if (!target) {
            return;
        }
        if (ctx.menu.contains(target)) {
            event.preventDefault();
            return;
        }
        var items = [];
        var origin = null;
        var field = ctxEditableField(target);
        if (field) {
            // KI-Eintraege (nur bei Markierung bzw. KI-Block) vor den Standardbefehlen.
            items = aiMenuItems(event);
            if (items.length) {
                items.push({ separator: true });
            }
            items = items.concat(editMenuItems(field));
            origin = field;
        } else if (state.module === 'mail') {
            var row = target.closest('.ov-item');
            var list = hook('list-body');
            var folderNode = target.closest('[data-folder]');
            var folders = hook('folders-body');
            if (row && list && list.contains(row)) {
                items = mailMenuItems(row);
                origin = row;
            } else if (folderNode && folders && folders.contains(folderNode)) {
                items = folderMenuItems(folderNode);
                origin = folderNode;
            }
        }
        if (!items.length) {
            ctxHide();
            return; // Browser-Menue unveraendert lassen (z. B. Links im Lesebereich).
        }
        event.preventDefault();
        if (!ctxShow(event, items, origin)) {
            ctxHide();
        }
    }

    function ctxOnKeydown(event) {
        var menu = ctx.menu;
        if (!menu || menu.hidden) {
            return;
        }
        var buttons = $$('button:not(:disabled)', menu);
        var index = buttons.indexOf(document.activeElement);
        var inside = menu.contains(document.activeElement);
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            ctxHide();
            if (ctx.origin && ctx.origin.focus) {
                ctx.origin.focus();
            }
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            event.stopPropagation();
            if (!buttons.length) {
                return;
            }
            var next;
            if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = buttons.length - 1;
            } else if (index === -1) {
                next = event.key === 'ArrowDown' ? 0 : buttons.length - 1;
            } else {
                next = (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
            }
            buttons[next].focus();
            return;
        }
        if (event.key === 'Tab' || (!inside && ['Shift', 'Control', 'Alt', 'Meta', 'CapsLock'].indexOf(event.key) === -1)) {
            ctxHide(); // Weitertippen im Feld bzw. Tab schliesst das Menue.
        }
    }

    function initContextMenu() {
        ctx.menu = hook('ctx-menu');
        if (!ctx.menu) {
            return;
        }
        root.addEventListener('contextmenu', onContextMenu);
        // Kein Fokuswechsel beim Klick ins Menue: Markierung im Textfeld bleibt erhalten.
        ctx.menu.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        document.addEventListener('mousedown', function (event) {
            if (!ctx.menu.hidden && !ctx.menu.contains(event.target)) {
                ctxHide();
            }
        });
        document.addEventListener('keydown', ctxOnKeydown, true);
        window.addEventListener('scroll', ctxHide, true);
        window.addEventListener('resize', ctxHide);
        window.addEventListener('blur', ctxHide);
    }

    // ------------------------------------------------------------------
    // KI-Unterstuetzung (Kontextmenue im Editor, hellblau markierte Bloecke)
    // ------------------------------------------------------------------

    var AI_MAX_TEXT = 8000;
    var AI_MAX_PROMPT = 1000;
    var AI_BLOCK_CLASS = 'ov-ai-block';

    var ai = {
        available: !!config.aiAvailable,
        dialog: null,
        editor: null,       // Editor, in dem das Menue geoeffnet wurde
        range: null,        // gesicherte Auswahl (Range) fuer "verbessern"
        block: null,        // angeklickter KI-Block fuer verfeinern/zuruecksetzen
        originals: {},      // id -> urspruenglicher Text (nur im Speicher)
        pending: false,
        seq: 0
    };

    function aiEditors() {
        return [hook('compose-body'), hook('event-body')].filter(Boolean);
    }

    /**
     * Einsatzort fuer den Server: steuert Systemprompt und Statistik.
     */
    function aiMode(editor) {
        if (editor && editor.hasAttribute('data-ov-event-body')) {
            var form = hook('form-event');
            var reminder = form && form.elements.reminder ? parseInt(form.elements.reminder.value, 10) : -1;
            return reminder >= 0 ? 'reminder' : 'event';
        }
        var mode = (state.compose && state.compose.mode) || 'new';
        if (mode === 'reply' || mode === 'replyall') {
            return 'mail_reply';
        }
        return mode === 'forward' ? 'mail_forward' : 'mail_compose';
    }

    function aiContext(editor) {
        var form = editor && editor.hasAttribute('data-ov-event-body') ? hook('form-event') : hook('form-compose');
        if (!form) {
            return {};
        }
        var recipients = 0;
        ['to', 'cc', 'bcc', 'required', 'optional'].forEach(function (name) {
            if (form.elements[name]) {
                recipients += parseRecipients(form.elements[name].value).length;
            }
        });
        return { subject: form.elements.subject ? form.elements.subject.value.trim().slice(0, 300) : '', recipients: recipients };
    }

    function aiSelectionIn(editor) {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || selection.isCollapsed) {
            return null;
        }
        var range = selection.getRangeAt(0);
        if (!editor.contains(range.commonAncestorContainer)) {
            return null;
        }
        return range.toString().trim() === '' ? null : range.cloneRange();
    }

    // Blocktext mit Zeilenumbruechen (<br> -> \n), textContent wuerde sie verschlucken.
    function aiBlockText(block) {
        var text = '';
        block.childNodes.forEach(function (node) {
            if (node.nodeType === 3) {
                text += node.textContent;
            } else if (node.nodeName === 'BR') {
                text += '\n';
            } else if (node.nodeType === 1) {
                text += aiBlockText(node);
            }
        });
        return text;
    }

    /**
     * KI-Eintraege fuer das App-Kontextmenue (nur in den KI-faehigen Editoren
     * und nur bei Markierung bzw. Klick auf einen KI-Block).
     */
    function aiMenuItems(event) {
        if (!ai.available || !ai.dialog) {
            return [];
        }
        var editor = event.target.closest('[data-ov-compose-body], [data-ov-event-body]');
        if (!editor || aiEditors().indexOf(editor) === -1) {
            return [];
        }
        var block = event.target.closest('.' + AI_BLOCK_CLASS);
        var range = aiSelectionIn(editor);
        if (!block && !range) {
            return [];
        }
        ai.editor = editor;
        ai.block = block && editor.contains(block) ? block : null;
        ai.range = range;
        var robot = el('img', { src: '/assets/images/orvanta-ai-robot-small.png', srcset: '/assets/images/orvanta-ai-robot-small@2x.png 2x', width: '12', height: '16', alt: '' });
        var items = [];
        if (range && !ai.block) {
            items.push({ label: 'Mit KI verbessern …', icon: robot, ai: true, run: function () { aiMenuAction('improve'); } });
        }
        if (ai.block) {
            items.push({ label: 'Weiter verfeinern …', icon: robot, ai: true, run: function () { aiMenuAction('refine'); } });
            if (ai.originals[ai.block.getAttribute('data-ov-ai-id')] !== undefined) {
                items.push({ label: 'Auf Original zurücksetzen', ai: true, run: function () { aiMenuAction('reset'); } });
            }
            items.push({ label: 'Markierung entfernen', ai: true, run: function () { aiMenuAction('unmark'); } });
        }
        return items;
    }

    function aiOpenDialog(kind) {
        var dialog = ai.dialog;
        if (!dialog) {
            return;
        }
        var form = hook('form-ai');
        form.reset();
        form.setAttribute('data-ov-ai-kind', kind);
        var text = kind === 'refine' && ai.block ? aiBlockText(ai.block) : (ai.range ? ai.range.toString() : '');
        text = text.replace(/\s+/g, ' ').trim();
        var title = hook('ai-title', dialog);
        if (title) {
            title.textContent = kind === 'refine' ? 'Vorschlag weiter verfeinern' : 'Mit KI verbessern';
        }
        var excerpt = hook('ai-excerpt', dialog);
        if (excerpt) {
            excerpt.textContent = text.length > 160 ? text.slice(0, 160) + ' …' : text;
        }
        var note = hook('ai-note', dialog);
        if (note) {
            note.textContent = kind === 'refine'
                ? 'Die KI erhält den bisherigen Vorschlag und Ihre neue Anweisung.'
                : 'Übertragen werden nur dieser Abschnitt, Betreff und Empfängeranzahl.';
        }
        openDialog('ai');
        if (text.length > AI_MAX_TEXT) {
            formError(form, 'Der markierte Text ist zu lang (höchstens ' + AI_MAX_TEXT + ' Zeichen). Bitte einen kleineren Abschnitt wählen.');
        }
    }

    /**
     * Markierten Bereich in einen hellblau umrandeten KI-Block umwandeln.
     */
    function aiWrapRange(range, text) {
        ai.seq += 1;
        var id = 'ai' + Date.now().toString(36) + ai.seq;
        var span = el('span', { 'class': AI_BLOCK_CLASS, 'data-ov-ai-id': id, title: 'Von der KI erzeugter Text – Rechtsklick für Optionen' });
        try {
            range.surroundContents(span);
        } catch (e) {
            // Auswahl ueber Elementgrenzen: Inhalt herausloesen und als Block einfuegen.
            var fragment = range.extractContents();
            span.appendChild(fragment);
            range.insertNode(span);
        }
        ai.originals[id] = text;
        return span;
    }

    function aiFillBlock(block, text) {
        block.innerHTML = '';
        var lines = String(text).split(/\r?\n/);
        lines.forEach(function (line, index) {
            if (index > 0) {
                block.appendChild(document.createElement('br'));
            }
            block.appendChild(document.createTextNode(line));
        });
    }

    function aiSubmit(form) {
        if (ai.pending) {
            return;
        }
        var kind = form.getAttribute('data-ov-ai-kind') || 'improve';
        var prompt = form.elements.prompt.value.trim();
        if (prompt === '') {
            formError(form, 'Bitte beschreiben Sie, was geändert werden soll.');
            return;
        }
        if (prompt.length > AI_MAX_PROMPT) {
            formError(form, 'Die Anweisung ist zu lang (höchstens ' + AI_MAX_PROMPT + ' Zeichen).');
            return;
        }
        var editor = ai.editor;
        var payload = { mode: aiMode(editor), prompt: prompt, context: aiContext(editor) };
        var block = kind === 'refine' ? ai.block : null;
        if (block) {
            var id = block.getAttribute('data-ov-ai-id');
            payload.text = ai.originals[id] !== undefined ? ai.originals[id] : aiBlockText(block);
            payload.previous_text = aiBlockText(block);
        } else {
            if (!ai.range) {
                formError(form, 'Bitte zuerst Text markieren.');
                return;
            }
            payload.text = ai.range.toString();
        }
        if (payload.text.trim() === '') {
            formError(form, 'Der markierte Text ist leer.');
            return;
        }
        if (payload.text.length > AI_MAX_TEXT) {
            formError(form, 'Der markierte Text ist zu lang (höchstens ' + AI_MAX_TEXT + ' Zeichen).');
            return;
        }
        formError(form, '');
        ai.pending = true;
        var submit = hook('ai-submit', form);
        var label = submit ? submit.textContent : '';
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Wird erzeugt …';
        }
        form.classList.add('is-busy');
        api('/ki/verbessern', { body: payload }).then(function (data) {
            var text = String(data.text || '').trim();
            if (text === '') {
                throw new Error('Die KI hat keinen Vorschlag geliefert.');
            }
            if (!block) {
                block = aiWrapRange(ai.range, payload.text);
            }
            aiFillBlock(block, text);
            block.classList.add('ov-ai-block--fresh');
            window.setTimeout(function () { block.classList.remove('ov-ai-block--fresh'); }, 1200);
            closeDialog('ai');
            toast('Vorschlag eingefügt – Rechtsklick auf den blauen Block zum Verfeinern oder Zurücksetzen.', 'success');
            ai.range = null;
            ai.block = null;
            editor.focus();
        }).catch(function (error) {
            formError(form, error.message || 'Die KI-Anfrage ist fehlgeschlagen.');
        }).then(function () {
            ai.pending = false;
            form.classList.remove('is-busy');
            if (submit) {
                submit.disabled = false;
                submit.textContent = label;
            }
        });
    }

    function aiUnwrap(block, text) {
        var parent = block.parentNode;
        if (!parent) {
            return;
        }
        if (text !== undefined) {
            parent.replaceChild(document.createTextNode(text), block);
        } else {
            while (block.firstChild) {
                parent.insertBefore(block.firstChild, block);
            }
            parent.removeChild(block);
        }
        parent.normalize();
    }

    function aiMenuAction(action) {
        var block = ai.block;
        if (action === 'improve') {
            aiOpenDialog('improve');
        } else if (action === 'refine' && block) {
            aiOpenDialog('refine');
        } else if (action === 'reset' && block) {
            var id = block.getAttribute('data-ov-ai-id');
            aiUnwrap(block, ai.originals[id]);
            delete ai.originals[id];
            toast('Ursprünglicher Text wiederhergestellt.', 'info');
        } else if (action === 'unmark' && block) {
            delete ai.originals[block.getAttribute('data-ov-ai-id')];
            aiUnwrap(block);
        }
        if (action !== 'improve' && action !== 'refine' && ai.editor) {
            ai.editor.focus();
        }
    }

    function initAi() {
        ai.dialog = $('[data-ov-dialog="ai"]');
        if (!ai.available || !ai.dialog) {
            ai.dialog = null;
            return;
        }
        var form = hook('form-ai');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            aiSubmit(form);
        });
        $$('[data-ov-ai-chip]', form).forEach(function (chip) {
            chip.addEventListener('click', function () {
                var field = form.elements.prompt;
                var text = chip.getAttribute('data-ov-ai-chip');
                field.value = field.value.trim() === '' ? text : field.value.trim() + ' ' + text;
                field.focus();
            });
        });
        var indicator = hook('ai-indicator');
        if (indicator) {
            indicator.addEventListener('click', function () {
                openDialog('help');
                var section = document.getElementById('ov-help-ai');
                if (section) {
                    section.scrollIntoView({ block: 'nearest' });
                }
            });
        }
        // Beim Oeffnen eines Editors Zuordnungen verwerfen (Bloecke aus Entwuerfen
        // tragen keine Originale mehr, sind aber weiterhin umrandet).
        aiEditors().forEach(function (editor) {
            var dialog = editor.closest('dialog');
            if (dialog) {
                dialog.addEventListener('close', function () {
                    ai.originals = {};
                    ai.range = null;
                    ai.block = null;
                });
            }
        });
    }

    // ------------------------------------------------------------------
    // Empfaenger-Vorschlaege aus der Telefonliste
    // ------------------------------------------------------------------
    // Gleicher Mechanismus wie die AD-Gruppen-Vorschlaege im Adminbereich
    // (ARIA-Combobox mit Inline-Ergaenzung und Liste). Quellen: der lokal
    // synchronisierte Telefonlisten-Bestand und die bereits vom Benutzer
    // angeschriebenen Adressen (Verlauf in seiner Nextcloud), serverseitig
    // zusammengefuehrt unter /api/orvanta/empfaenger.

    var RECIPIENT_SUGGEST_URL = API + '/empfaenger';
    var RECIPIENT_SUGGEST_DEBOUNCE_MS = 150;
    var RECIPIENT_SUGGEST_LIMIT = 8;
    var recipientSuggestCounter = 0;

    function recipientLabel(item) {
        var name = String(item.display_name || '').trim();
        var email = String(item.email || '').trim();
        // Namen mit Trennzeichen wuerden parseRecipients() zerlegen.
        if (name === '' || name === email || /[;,<>]/.test(name)) {
            return email;
        }
        return name + ' <' + email + '>';
    }

    function initRecipientSuggest(input) {
        if (!input || input.getAttribute('data-ov-recipients-ready') === '1') {
            return;
        }
        input.setAttribute('data-ov-recipients-ready', '1');

        var listId = 'ov-recipients-' + (++recipientSuggestCounter);
        var list = document.createElement('ul');
        list.id = listId;
        list.className = 'ov-suggest__list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', 'Vorgeschlagene Empfänger aus Telefonliste und Verlauf');
        list.hidden = true;

        var status = document.createElement('span');
        status.className = 'visually-hidden';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');

        var control = document.createElement('span');
        control.className = 'ov-suggest';
        input.parentNode.insertBefore(control, input);
        control.appendChild(input);
        control.appendChild(list);
        control.appendChild(status);

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'both');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);

        var items = [];
        var active = -1;
        var timer = null;
        var requestId = 0;
        var cache = {};
        var lastInputType = '';

        // Aktuelles Token (Text zwischen den Trennzeichen um die Schreibmarke).
        function currentToken() {
            var value = input.value;
            var caret = input.selectionStart === null ? value.length : input.selectionStart;
            var start = Math.max(value.lastIndexOf(';', caret - 1), value.lastIndexOf(',', caret - 1)) + 1;
            var ends = [value.indexOf(';', caret), value.indexOf(',', caret)].filter(function (i) { return i !== -1; });
            var end = ends.length ? Math.min.apply(null, ends) : value.length;
            var raw = value.slice(start, end);
            var lead = raw.length - raw.replace(/^\s+/, '').length;
            return {
                start: start + lead,
                end: end,
                text: value.slice(start + lead, caret),
                full: raw.trim()
            };
        }

        function chosenEmails(exceptStart) {
            var emails = {};
            var offset = 0;
            input.value.split(/[;,]/).forEach(function (part) {
                var partStart = offset + (part.length - part.replace(/^\s+/, '').length);
                offset += part.length + 1;
                var parsed = parseRecipients(part)[0];
                if (parsed && partStart !== exceptStart) {
                    emails[parsed.email.toLowerCase()] = true;
                }
            });
            return emails;
        }

        function close() {
            list.hidden = true;
            list.innerHTML = '';
            items = [];
            active = -1;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function setActive(index) {
            var options = list.querySelectorAll('[role="option"]');
            if (options.length === 0) {
                active = -1;
                return;
            }
            active = (index + options.length) % options.length;
            Array.prototype.forEach.call(options, function (option, i) {
                var selected = i === active;
                option.setAttribute('aria-selected', selected ? 'true' : 'false');
                option.classList.toggle('is-active', selected);
                if (selected) {
                    input.setAttribute('aria-activedescendant', option.id);
                    option.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        // Die Liste liegt fest im Viewport, damit der scrollbare Dialog sie nicht abschneidet.
        function position() {
            var rect = input.getBoundingClientRect();
            list.style.top = (rect.bottom + 4) + 'px';
            list.style.left = rect.left + 'px';
            list.style.width = rect.width + 'px';
            list.style.maxHeight = Math.max(120, window.innerHeight - rect.bottom - 16) + 'px';
        }

        function render(found, token) {
            var chosen = chosenEmails(token.start);
            items = found.filter(function (item) {
                var email = String(item.email || '').trim();
                return email !== '' && !chosen[email.toLowerCase()];
            });

            list.innerHTML = '';
            active = -1;
            input.removeAttribute('aria-activedescendant');

            if (items.length === 0) {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                status.textContent = token.text === '' ? '' : 'Kein passender Empfänger in Telefonliste oder Verlauf.';
                return;
            }

            items.forEach(function (item, index) {
                var option = document.createElement('li');
                option.id = listId + '-' + index;
                option.className = 'ov-suggest__option';
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');

                var name = document.createElement('span');
                name.className = 'ov-suggest__name';
                name.textContent = item.display_name || item.email;
                option.appendChild(name);

                if (item.recent) {
                    option.classList.add('is-recent');
                }
                var meta = [item.email];
                if (item.department) {
                    meta.push(item.department);
                }
                if (item.source) {
                    meta.push(item.source);
                }
                var hint = document.createElement('span');
                hint.className = 'ov-suggest__meta';
                hint.textContent = meta.join(' · ');
                option.appendChild(hint);

                option.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                });
                option.addEventListener('click', function () {
                    choose(index);
                });
                list.appendChild(option);
            });

            position();
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            status.textContent = items.length === 1 ? '1 Vorschlag verfügbar.' : items.length + ' Vorschläge verfügbar.';

            inlineComplete(token);
        }

        // Ergaenzt den ersten passenden Eintrag markiert hinter der Schreibmarke.
        function inlineComplete(token) {
            if (lastInputType !== 'insertText' || token.text === '' || items.length === 0) {
                return;
            }
            var caret = input.selectionStart;
            if (caret !== input.selectionEnd || caret !== token.start + token.text.length || token.end !== caret) {
                return;
            }
            var label = recipientLabel(items[0]);
            if (label.toLowerCase().indexOf(token.text.toLowerCase()) !== 0 || label.length === token.text.length) {
                return;
            }
            var value = input.value;
            input.value = value.slice(0, token.start) + token.text + label.slice(token.text.length) + value.slice(token.end);
            input.setSelectionRange(caret, token.start + label.length);
            setActive(0);
        }

        function choose(index) {
            var item = items[index];
            if (!item) {
                return;
            }
            var token = currentToken();
            var value = input.value;
            var before = value.slice(0, token.start);
            var after = value.slice(token.end).replace(/^\s*[;,]?\s*/, '');
            var insert = recipientLabel(item) + '; ';
            input.value = before + insert + after;
            var caret = before.length + insert.length;
            input.setSelectionRange(caret, caret);
            status.textContent = (item.display_name || item.email) + ' übernommen.';
            close();
            input.focus();
        }

        function load() {
            var token = currentToken();
            var key = token.text.trim().toLowerCase();
            if (key === '') {
                close();
                return;
            }
            if (cache[key]) {
                render(cache[key], token);
                return;
            }

            var id = ++requestId;
            window.fetch(RECIPIENT_SUGGEST_URL + '?q=' + encodeURIComponent(token.text.trim()) + '&limit=' + RECIPIENT_SUGGEST_LIMIT, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }
            })
                .then(function (response) {
                    return response.ok ? response.json() : { items: [] };
                })
                .then(function (data) {
                    var found = Array.isArray(data.items) ? data.items : [];
                    cache[key] = found;
                    if (id === requestId && document.activeElement === input) {
                        render(found, currentToken());
                    }
                })
                .catch(function () {
                    close();
                });
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(load, RECIPIENT_SUGGEST_DEBOUNCE_MS);
        }

        input.addEventListener('input', function (event) {
            lastInputType = event.inputType || 'insertText';
            schedule();
        });

        input.addEventListener('click', function () {
            lastInputType = '';
            schedule();
        });

        input.addEventListener('keydown', function (event) {
            var open = !list.hidden && items.length > 0;
            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    if (!open) {
                        lastInputType = '';
                        load();
                        return;
                    }
                    setActive(active + 1);
                    break;
                case 'ArrowUp':
                    if (open) {
                        event.preventDefault();
                        setActive(active - 1);
                    }
                    break;
                case 'Enter':
                    if (open && active >= 0) {
                        event.preventDefault();
                        choose(active);
                    } else if (open) {
                        // Enter ohne Auswahl nur die Liste schliessen, kein Formular-Submit.
                        event.preventDefault();
                        close();
                    }
                    break;
                case 'Tab':
                    // Tab uebernimmt nur eine sichtbare Inline-Ergaenzung.
                    if (open && active >= 0 && input.selectionStart !== input.selectionEnd && !event.shiftKey) {
                        event.preventDefault();
                        choose(active);
                    }
                    break;
                case 'Escape':
                    if (open) {
                        // Dialog offen lassen; nur die Vorschlaege schliessen.
                        event.preventDefault();
                        event.stopPropagation();
                        if (input.selectionStart !== input.selectionEnd) {
                            var start = input.selectionStart;
                            input.value = input.value.slice(0, start) + input.value.slice(input.selectionEnd);
                            input.setSelectionRange(start, start);
                        }
                        close();
                    }
                    break;
                default:
                    break;
            }
        });

        input.addEventListener('blur', function () {
            window.setTimeout(close, 100);
        });

        var dialog = input.closest('dialog');
        if (dialog) {
            dialog.addEventListener('close', close);
            var body = dialog.querySelector('.ov-dialog__body');
            if (body) {
                body.addEventListener('scroll', function () {
                    if (!list.hidden) {
                        position();
                    }
                });
            }
        }
        window.addEventListener('resize', function () {
            if (!list.hidden) {
                position();
            }
        });
    }

    function initRecipientSuggestAll() {
        $$('input[data-ov-recipients]').forEach(initRecipientSuggest);
    }

    function init() {
        applyPrefs();
        bindEvents();
        initContextMenu();
        initAi();
        initRecipientSuggestAll();
        updateNotifyState();
        setOnline(true);
        var params = new window.URLSearchParams(window.location.search);
        var module = state.module;
        var wanted = params.get('modul') || '';
        if (['mail', 'calendar', 'contacts', 'tasks', 'notes'].indexOf(wanted) !== -1) {
            module = wanted;
        }
        state.module = '';
        switchModule(module);
        // Verfassen in eigenem Tab: Zustand vom urspruenglichen Tab uebernehmen.
        var handoff = params.get('verfassen') || '';
        if (handoff !== '') {
            startStandaloneCompose(handoff);
            return;
        }
        // Deep-Link aus den Kopfzeilen-Mitteilungen: Termin direkt oeffnen.
        var eventId = params.get('termin') || '';
        if (module === 'calendar' && eventId !== '') {
            openEvent({ id: eventId });
        }
        loadQuota();
        startReminderPolling();
        $$('[data-ov-tasks-completed]').forEach(function (box) {
            state.tasksCompleted = box.checked;
        });
        if ('Notification' in window && window.Notification.permission === 'default' && state.prefs.notify) {
            toast('Desktop-Benachrichtigungen für Terminerinnerungen aktivieren?', 'info', [{ label: 'Erlauben', run: requestNotifyPermission }]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
