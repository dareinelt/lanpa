/*
 * Live-Vorschau der Orvanta-Signaturvorlage im Adminbereich.
 *
 * Die Vorschau ist ein iframe mit eigener CSP (Inline-Styles der E-Mail-
 * Signatur). Beim Aendern der Felder wird die Vorschau-URL mit den aktuellen
 * Formularwerten neu geladen; serverseitig wird nur geprueft, nie gespeichert.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 350;

    function init(form) {
        var frame = form.querySelector('[data-signature-preview]');
        if (!frame) {
            return;
        }
        var base = frame.getAttribute('data-preview-url') || frame.getAttribute('src');
        var timer = null;

        function refresh() {
            var params = new URLSearchParams();
            ['greeting', 'name_format', 'street', 'postal_city', 'phone_prefix', 'text_color', 'separator_color'].forEach(function (name) {
                var field = form.elements[name];
                params.set(name, field ? field.value : '');
            });
            var mode = form.querySelector('input[name="phone_mode"]:checked');
            params.set('phone_mode', mode ? mode.value : 'prefix');
            params.set('name', 'Vorschau');
            frame.src = base + '?' + params.toString();
        }

        form.querySelectorAll('[data-signature-field]').forEach(function (field) {
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
        document.querySelectorAll('[data-signature-form]').forEach(init);
    });
})();
