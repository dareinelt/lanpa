/* Gemeinsame Funktionen: Theme-Umschaltung, Bestätigungen, Farbfelder. */
(function () {
    'use strict';

    var STORAGE_KEY = 'intranet.theme';
    var MODES = ['auto', 'light', 'dark'];
    var LABELS = {
        auto: 'Design: automatisch',
        light: 'Design: hell',
        dark: 'Design: dunkel'
    };
    var ANNOUNCEMENT_STORAGE_KEY = 'intranet.announcement.dismissed';

    function readMode() {
        try {
            var stored = window.localStorage.getItem(STORAGE_KEY);
            return MODES.indexOf(stored) === -1 ? 'light' : stored;
        } catch (error) {
            return 'light';
        }
    }

    function applyMode(mode) {
        document.documentElement.setAttribute('data-theme', mode);

        var toggles = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < toggles.length; i++) {
            var label = toggles[i].querySelector('.theme-toggle__label');
            if (label) {
                label.textContent = LABELS[mode];
            }
            toggles[i].setAttribute('title', LABELS[mode]);
        }
    }

    function storeMode(mode) {
        try {
            window.localStorage.setItem(STORAGE_KEY, mode);
        } catch (error) {
            /* Speichern ist optional. */
        }
    }

    function initTheme() {
        var mode = readMode();
        applyMode(mode);

        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-theme-toggle]');
            if (!toggle) {
                return;
            }

            var next = MODES[(MODES.indexOf(readMode()) + 1) % MODES.length];
            storeMode(next);
            applyMode(next);
        });
    }

    function initConfirmations() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            var message = form.getAttribute('data-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    }

    function initColorFields() {
        var pickers = document.querySelectorAll('input[type="color"][data-color-sync]');

        Array.prototype.forEach.call(pickers, function (picker) {
            var mirror = document.getElementById(picker.getAttribute('data-color-sync'));
            if (!mirror) {
                return;
            }

            picker.addEventListener('input', function () {
                mirror.value = picker.value;
            });

            mirror.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{6}$/.test(mirror.value)) {
                    picker.value = mirror.value;
                }
            });
        });
    }

    function announcementStore() {
        try {
            return window.localStorage;
        } catch (error) {
            return null;
        }
    }

    function announcementDismissedTokens() {
        var store = announcementStore();
        if (!store) {
            return {};
        }

        var raw = store.getItem(ANNOUNCEMENT_STORAGE_KEY);
        if (!raw) {
            return {};
        }

        // Neues Format: JSON-Array von Token. Altes Format: einzelnes Token.
        if (raw.charAt(0) === '[') {
            try {
                var tokens = JSON.parse(raw);
                var map = {};
                if (Array.isArray(tokens)) {
                    tokens.forEach(function (token) {
                        map[token] = true;
                    });
                }
                return map;
            } catch (error) {
                return {};
            }
        }

        var legacy = {};
        legacy[raw] = true;
        return legacy;
    }

    function announcementStoreDismissed(token) {
        var store = announcementStore();
        if (!store) {
            return;
        }

        var tokens = Object.keys(announcementDismissedTokens());
        if (tokens.indexOf(token) === -1) {
            tokens.push(token);
        }

        try {
            store.setItem(ANNOUNCEMENT_STORAGE_KEY, JSON.stringify(tokens));
        } catch (error) {
            /* Speichern ist optional. */
        }
    }

    function initAnnouncements() {
        var overlay = document.querySelector('[data-announcement-overlay]');
        if (!overlay) {
            return;
        }

        var contentBox = overlay.querySelector('[data-announcement-content]');
        var dismissButton = overlay.querySelector('[data-announcement-dismiss]');
        var templates = document.querySelectorAll('.announcement-template');
        var menuItems = document.querySelectorAll('[data-announcement-open]');
        var lastTrigger = null;

        function templateFor(id) {
            for (var i = 0; i < templates.length; i++) {
                if (templates[i].getAttribute('data-announcement-id') === String(id)) {
                    return templates[i];
                }
            }
            return null;
        }

        function open(id, version) {
            var template = templateFor(id);
            if (!template || !contentBox) {
                return;
            }

            contentBox.textContent = '';
            var clone = template.content.cloneNode(true);
            var title = clone.querySelector('.announcement-overlay__title');
            if (title) {
                title.id = 'announcement-overlay-title';
            }
            contentBox.appendChild(clone);

            overlay.setAttribute('data-announcement-id', String(id));
            overlay.setAttribute('data-announcement-version', version || '');
            overlay.hidden = false;
            document.body.classList.add('has-announcement-overlay');

            if (dismissButton) {
                dismissButton.focus();
            }
        }

        function close() {
            overlay.hidden = true;
            document.body.classList.remove('has-announcement-overlay');
            if (lastTrigger) {
                lastTrigger.focus();
                lastTrigger = null;
            }
        }

        if (dismissButton) {
            dismissButton.addEventListener('click', function () {
                var id = overlay.getAttribute('data-announcement-id') || '';
                var version = overlay.getAttribute('data-announcement-version') || '';
                if (id !== '') {
                    announcementStoreDismissed(id + ':' + version);
                }
                close();
            });
        }

        Array.prototype.forEach.call(menuItems, function (item) {
            item.addEventListener('click', function () {
                var id = item.getAttribute('data-announcement-id');
                var template = templateFor(id);
                var version = template ? (template.getAttribute('data-announcement-version') || '') : '';
                lastTrigger = item;
                open(id, version);
            });
        });

        // Aktive Mitteilung beim Oeffnen der Landingpage als Overlay anzeigen.
        if (document.body.getAttribute('data-page') === 'home') {
            for (var i = 0; i < menuItems.length; i++) {
                var id = menuItems[i].getAttribute('data-announcement-id');
                var template = templateFor(id);
                var version = template ? (template.getAttribute('data-announcement-version') || '') : '';
                var token = id + ':' + version;

                if (!announcementDismissedTokens()[token]) {
                    open(id, version);
                    break;
                }
            }
        }
    }

    function initAnnouncementMenu() {
        var menus = document.querySelectorAll('[data-announcement-menu]');
        if (menus.length === 0) {
            return;
        }

        function closeAll() {
            Array.prototype.forEach.call(menus, function (menu) {
                menu.removeAttribute('open');
            });
        }

        // ESC klappt das aufgeklappte Mitteilungen-Menü ein.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' || event.key === 'Esc') {
                closeAll();
            }
        });

        // Klick in einen freien Bereich klappt das Menü ein.
        document.addEventListener('click', function (event) {
            for (var i = 0; i < menus.length; i++) {
                if (menus[i].contains(event.target)) {
                    return;
                }
            }
            closeAll();
        });
    }

    // Theme sofort setzen, damit es keinen Farbsprung gibt.
    applyMode(readMode());

    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        initConfirmations();
        initColorFields();
        initAnnouncements();
        initAnnouncementMenu();
    });
})();
