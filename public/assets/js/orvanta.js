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
        online: true,
        noteDraft: false,
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

    function parseRecipients(value) {
        return String(value || '').split(/[;,\n]+/).map(function (part) {
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
                body.appendChild(node);
                render(folder.id, depth + 1);
            });
        }
        render('', 0);
        if (!state.folders.length) {
            body.appendChild(el('div', { 'class': 'ov-folders__empty', text: 'Keine Ordner gefunden.' }));
        }
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
        var offset = append ? state.messages.length : 0;
        if (!append) {
            listMessage('Wird geladen …', 'ov-list__empty--loading');
        }
        return api('/mail', { query: { ordner: state.folder, offset: offset, limit: 50, q: state.search } }).then(function (data) {
            state.messages = append ? state.messages.concat(data.items || []) : (data.items || []);
            state.total = data.total || state.messages.length;
            state.hasMore = !!data.has_more;
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
            'class': 'ov-item' + (message.is_read ? '' : ' ov-item--unread') + (state.selected && state.selected.id === message.id ? ' ov-item--active' : '') + (state.selectedIds.indexOf(message.id) !== -1 ? ' ov-item--checked' : ''),
            tabindex: '0',
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
            node.classList.toggle('ov-item--active', node.getAttribute('data-id') === summary.id);
        });
        updateActionState();
        var loading = el('div', { 'class': 'ov-mail ov-mail--loading', text: 'Nachricht wird geladen …' });
        showDetail(loading);
        api('/mail/nachricht', { query: { id: summary.id } }).then(function (message) {
            if (!state.selected || state.selected.id !== message.id) {
                return;
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
        }).catch(function (error) {
            showDetail(el('div', { 'class': 'ov-mail ov-mail--error', text: error.message }));
        });
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
                    el('div', { 'class': 'ov-mail__to', text: 'An: ' + ((message.to || []).map(mailboxFull).join(', ') || '–') }),
                    message.cc && message.cc.length ? el('div', { 'class': 'ov-mail__to', text: 'Cc: ' + message.cc.map(mailboxFull).join(', ') }) : null
                ]),
                el('div', { 'class': 'ov-mail__date', text: fmtDateTime(message.received) })
            ])
        ]);
        wrap.appendChild(head);

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

    function openMoveDialog() {
        if (!currentMailIds().length) {
            toast('Bitte zuerst eine Nachricht auswählen.', 'info');
            return;
        }
        var list = hook('move-folders');
        if (list) {
            list.innerHTML = '';
            state.folders.forEach(function (folder) {
                var key = folderKey(folder);
                if (key === state.folder) {
                    return;
                }
                var button = el('button', { type: 'button', 'class': 'ov-folder', text: (FOLDER_ICONS[folder.kind] || FOLDER_ICONS.folder) + ' ' + (FOLDER_LABELS[folder.kind] || folder.name) });
                button.addEventListener('click', function () {
                    closeDialog('move');
                    mailAction('move', null, key);
                });
                list.appendChild(button);
            });
        }
        openDialog('move');
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
        state.compose = { mode: mode || 'new', attachments: [], replyTo: message ? message.id : null };
        var body = hook('compose-body');
        var attachList = hook('attach-list');
        if (attachList) {
            attachList.innerHTML = '';
        }
        var title = hook('compose-title', form.closest('dialog'));
        var titles = { 'new': 'Neue Nachricht', reply: 'Antworten', replyall: 'Allen antworten', forward: 'Weiterleiten' };
        if (title) {
            title.textContent = titles[state.compose.mode] || 'Neue Nachricht';
        }
        if (body) {
            body.innerHTML = '<p><br></p>';
        }
        if (message) {
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
        openDialog('compose');
        if (mode === 'new' || mode === 'forward') {
            form.elements.to.focus();
        } else if (body) {
            body.focus();
        }
    }

    function composePayload(form) {
        var body = hook('compose-body');
        return {
            to: parseRecipients(form.elements.to.value),
            cc: parseRecipients(form.elements.cc.value),
            bcc: parseRecipients(form.elements.bcc ? form.elements.bcc.value : ''),
            subject: form.elements.subject.value.trim(),
            body: body ? body.innerHTML : '',
            html: true,
            importance: form.elements.importance ? form.elements.importance.value : 'Normal',
            attachments: state.compose ? state.compose.attachments : []
        };
    }

    function sendCompose(form, asDraft) {
        var payload = composePayload(form);
        if (!asDraft && !payload.to.length) {
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
        var buttons = $$('button', form);
        buttons.forEach(function (b) { b.disabled = true; });
        setStatus(asDraft ? 'Entwurf wird gespeichert …' : 'Nachricht wird gesendet …');
        var request;
        if (asDraft) {
            request = api('/mail/entwurf', { body: payload });
        } else if (state.compose && state.compose.mode !== 'new' && state.compose.replyTo && !payload.attachments.length) {
            request = api('/mail/antworten', { body: { id: state.compose.replyTo, mode: state.compose.mode, body: payload.body, to: payload.to, cc: payload.cc, html: true } });
        } else {
            request = api('/mail/senden', { body: payload });
        }
        request.then(function () {
            closeDialog('compose');
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
                state.compose.attachments.push(entry);
                if (list) {
                    var chip = el('span', { 'class': 'ov-attachment ov-attachment--compose' }, [
                        el('span', { text: attachmentIcon(file.name) + ' ' + file.name + ' (' + fmtBytes(file.size) + ')' })
                    ]);
                    var remove = el('button', { type: 'button', 'class': 'ov-attachment__action', 'aria-label': 'Entfernen', text: '×' });
                    remove.addEventListener('click', function () {
                        var index = state.compose.attachments.indexOf(entry);
                        if (index !== -1) {
                            state.compose.attachments.splice(index, 1);
                        }
                        chip.remove();
                    });
                    chip.appendChild(remove);
                    list.appendChild(chip);
                }
            };
            reader.readAsDataURL(file);
        });
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
            form.elements.body.value = String(event.body_html || '').replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n').replace(/<[^>]+>/g, '').trim();
        } else {
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
            body: form.elements.body.value,
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
            $$('[data-ov-quota-text]').forEach(function (node) {
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
            case 'send': sendCompose(hook('form-compose'), false); break;
            case 'save-draft': sendCompose(hook('form-compose'), true); break;
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

    function init() {
        applyPrefs();
        bindEvents();
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
