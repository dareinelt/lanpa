/*
 * Orvanta – Terminerinnerungen in den Kopfzeilen-Mitteilungen des Intranets.
 * Fragt im Hintergrund faellige Erinnerungen ab, blendet sie in das
 * "Mitteilungen"-Menue ein und loest Browser-Benachrichtigungen aus.
 */
(function () {
    'use strict';

    var menu = document.querySelector('[data-orvanta-reminders]');
    var list = document.querySelector('[data-orvanta-reminder-list]');
    if (!menu || !list) {
        return;
    }

    var script = document.currentScript || document.querySelector('script[src*="orvanta-reminders.js"]');
    var csrf = script ? (script.getAttribute('data-csrf') || '') : '';
    var NOTIFIED_KEY = 'orvanta.header.notified';
    var POLL_MS = 60000;
    var SYNC_EVERY = 5;
    var polls = 0;
    var current = [];

    function notified() {
        try {
            return JSON.parse(window.sessionStorage.getItem(NOTIFIED_KEY) || '{}');
        } catch (e) {
            return {};
        }
    }

    function markNotified(id) {
        var store = notified();
        store[id] = Date.now();
        try {
            window.sessionStorage.setItem(NOTIFIED_KEY, JSON.stringify(store));
        } catch (e) { /* Speicher nicht verfuegbar */ }
    }

    function api(path, options) {
        options = options || {};
        var init = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
        };
        if (options.body) {
            init.headers['Content-Type'] = 'application/json';
            init.headers['X-CSRF-Token'] = csrf;
            init.body = JSON.stringify(options.body);
        }
        return window.fetch('/api/orvanta' + path, init).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    function formatTime(value) {
        if (!value) {
            return '';
        }
        // Unix-Zeitstempel (Sekunden) der API; ISO-Zeichenketten bleiben moeglich.
        var date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
        if (isNaN(date.getTime())) {
            return '';
        }
        var today = new Date();
        var sameDay = date.toDateString() === today.toDateString();
        var time = date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
        return sameDay ? time + ' Uhr' : date.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' }) + ', ' + time;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function updateCount() {
        var count = menu.querySelector('.site-nav__summary-count');
        var announcements = menu.querySelectorAll('[data-announcement-open]').length;
        if (!count) {
            return;
        }
        count.textContent = String(announcements + current.length);
        count.classList.toggle('site-nav__summary-count--alert', current.length > 0);
        var hide = announcements + current.length === 0;
        menu.hidden = hide;
        if (hide) {
            menu.removeAttribute('open');
        }
    }

    function render() {
        list.innerHTML = '';
        if (current.length === 0) {
            list.hidden = true;
            updateCount();
            return;
        }
        list.hidden = false;
        list.appendChild(el('span', 'site-nav__dropdown-heading', 'Terminerinnerungen'));
        current.forEach(function (reminder) {
            var item = el('a', 'site-nav__dropdown-item site-nav__reminder');
            item.href = '/office/orvanta?modul=calendar&termin=' + encodeURIComponent(reminder.item_id || '');
            item.appendChild(el('strong', '', reminder.subject || '(Ohne Betreff)'));
            var meta = (reminder.relative ? reminder.relative + ' · ' : '') + formatTime(reminder.start) + (reminder.location ? ' · ' + reminder.location : '');
            item.appendChild(el('span', 'site-nav__reminder-meta', meta));
            list.appendChild(item);

            var actions = el('div', 'site-nav__reminder-actions');
            var snooze = el('button', 'site-nav__reminder-action', '5 Min. später');
            snooze.type = 'button';
            snooze.addEventListener('click', function (event) {
                event.preventDefault();
                act('/erinnerungen/spaeter', { id: reminder.id, minutes: 5 });
            });
            var dismiss = el('button', 'site-nav__reminder-action', 'Schließen');
            dismiss.type = 'button';
            dismiss.addEventListener('click', function (event) {
                event.preventDefault();
                act('/erinnerungen/erledigt', { id: reminder.id });
            });
            actions.appendChild(snooze);
            actions.appendChild(dismiss);
            list.appendChild(actions);
        });
        updateCount();
    }

    function act(path, body) {
        current = current.filter(function (item) {
            return item.id !== body.id;
        });
        render();
        api(path, { method: 'POST', body: body }).catch(function () { /* beim naechsten Poll erneut */ });
    }

    function desktopNotify(reminder) {
        if (!('Notification' in window) || window.Notification.permission !== 'granted') {
            return;
        }
        var seen = notified();
        if (seen[reminder.id]) {
            return;
        }
        markNotified(reminder.id);
        try {
            var notification = new window.Notification(reminder.subject || 'Terminerinnerung', {
                body: formatTime(reminder.start) + (reminder.location ? ' · ' + reminder.location : ''),
                tag: 'orvanta-' + reminder.id,
                icon: '/assets/images/orvanta-logo.png'
            });
            notification.onclick = function () {
                window.focus();
                window.location.href = '/office/orvanta?modul=calendar&termin=' + encodeURIComponent(reminder.item_id || '');
            };
        } catch (e) { /* Browser ohne Notification-Konstruktor */ }
    }

    function poll() {
        polls += 1;
        var sync = polls === 1 || polls % SYNC_EVERY === 0;
        api('/erinnerungen' + (sync ? '?sync=1' : '')).then(function (data) {
            current = Array.isArray(data.due) ? data.due : [];
            render();
            current.forEach(desktopNotify);
        }).catch(function () { /* Exchange gerade nicht erreichbar */ });
    }

    // Die Orvanta-App selbst kuemmert sich um ihre Erinnerungen; doppelte Abfragen vermeiden.
    if (document.querySelector('[data-orvanta]')) {
        return;
    }

    poll();
    window.setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
        }
    });
})();
