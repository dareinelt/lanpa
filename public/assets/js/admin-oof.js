/*
 * Live-Vorschau der Orvanta-Abwesenheitsvorlage im Adminbereich.
 *
 * Die Vorschau ist ein iframe mit eigener CSP (Inline-Styles der E-Mail-
 * Signatur). Beim Aendern der Felder wird die Vorschau-URL mit den aktuellen
 * Formularwerten neu geladen; serverseitig wird nur geprueft, nie gespeichert.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 350;

    function init(form) {
        var frame = form.querySelector('[data-oof-preview]');
        if (!frame) {
            return;
        }
        var base = frame.getAttribute('data-preview-url') || frame.getAttribute('src');
        var timer = null;

        function refresh() {
            var params = new URLSearchParams();
            ['fixed_text', 'example_text'].forEach(function (name) {
                var field = form.elements[name];
                params.set(name, field ? field.value : '');
            });
            params.set('name', 'Vorschau');
            frame.src = base + '?' + params.toString();
        }

        form.querySelectorAll('[data-oof-field]').forEach(function (field) {
            field.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(refresh, DEBOUNCE_MS);
            });
            field.addEventListener('change', function () {
                window.clearTimeout(timer);
                refresh();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-oof-form]').forEach(init);
    });
})();
