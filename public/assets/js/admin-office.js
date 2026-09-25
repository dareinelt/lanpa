/*
 * Adminbereich Office: Live-Vorschau der Fusszeile und lokale Zeitangaben.
 */
(function () {
    'use strict';

    document.querySelectorAll('time[data-local-time]').forEach(function (node) {
        var date = new Date(node.getAttribute('datetime') || '');
        if (!isNaN(date.getTime())) {
            node.textContent = date.toLocaleString('de-DE');
        }
    });

    var preview = document.querySelector('[data-office-preview]');
    if (!preview || !window.IntranetOfficeFooter) {
        return;
    }

    var base;
    try {
        base = JSON.parse(preview.getAttribute('data-office-config') || '{}');
    } catch (e) {
        return;
    }

    var form = document.querySelector('[data-office-form]');

    function current() {
        var config = JSON.parse(JSON.stringify(base));
        if (!form) {
            return config;
        }
        var text = form.querySelector('#office_footer_text');
        var transparency = form.querySelector('#office_footer_transparency');
        var home = form.querySelector('#office_footer_home_url');
        var back = form.querySelector('#office_footer_show_back');
        var logo = form.querySelector('#office_footer_show_logo');
        var enabled = form.querySelector('#office_footer_enabled');

        if (text && text.value.trim() !== '') {
            config.text = text.value.trim();
        }
        if (transparency && transparency.value !== '') {
            config.transparency = Math.max(0, Math.min(90, parseInt(transparency.value, 10) || 0));
        }
        if (home && home.value.trim() !== '') {
            config.home_url = home.value.trim();
        }
        if (back) {
            config.show_back = back.checked;
        }
        if (logo && !logo.checked) {
            config.logo_url = '';
        }
        preview.classList.toggle('office-preview--disabled', enabled ? !enabled.checked : false);
        return config;
    }

    function refresh() {
        window.IntranetOfficeFooter.update(current(), preview);
    }

    refresh();
    if (form) {
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
    }
}());

/*
 * Office-Kachel: Live-Vorschau im iframe (serverseitig mit dem Stylesheet der
 * Landingpage gerendert, ungespeicherte Werte per Query-Parameter).
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-office-tile-form]');
    var frame = document.querySelector('[data-office-tile-preview]');
    var state = document.querySelector('[data-office-tile-state]');
    if (!form || !frame || !window.URLSearchParams) {
        return;
    }

    var fields = ['title', 'short_description', 'description', 'icon', 'office_tile_status', 'background_color', 'background_opacity'];
    var timer = null;

    function refresh() {
        var params = new URLSearchParams();
        fields.forEach(function (name) {
            var field = form.elements.namedItem(name);
            if (field && typeof field.value === 'string') {
                params.set(name, field.value);
            }
        });
        var override = form.elements.namedItem('override_background');
        if (override && override.checked) {
            params.set('override_background', '1');
        }
        if (state) {
            params.set('state', state.value);
        }
        frame.src = '/admin/office/kachel/vorschau?' + params.toString();
    }

    function schedule() {
        window.clearTimeout(timer);
        timer = window.setTimeout(refresh, 250);
    }

    // Gleiche Herkunft: Hoehe an den Inhalt anpassen (keine Scrollleiste).
    frame.addEventListener('load', function () {
        try {
            var doc = frame.contentDocument;
            if (doc && doc.documentElement) {
                frame.style.height = Math.max(120, doc.documentElement.scrollHeight + 4) + 'px';
            }
        } catch (e) { /* Vorschau bleibt in Standardhoehe */ }
    });

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    if (state) {
        state.addEventListener('change', refresh);
    }
    refresh();
})();
