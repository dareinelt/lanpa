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

    function readMode() {
        try {
            var stored = window.localStorage.getItem(STORAGE_KEY);
            return MODES.indexOf(stored) === -1 ? 'auto' : stored;
        } catch (error) {
            return 'auto';
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

    // Theme sofort setzen, damit es keinen Farbsprung gibt.
    applyMode(readMode());

    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        initConfirmations();
        initColorFields();
    });
})();
