/*
 * Intranet-Integration: laedt die Intranet-Fusszeile in Nextcloud/Euro-Office.
 *
 * Der Loader enthaelt bewusst keine Darstellung. Konfiguration, Stylesheet und
 * Skript der Fusszeile stammen aus dem Intranet (gleicher Host), sodass die
 * Vorschau im Intranet-Adminbereich und die Anzeige hier identisch sind.
 */
(function () {
    'use strict';

    // Nie in eingebetteten Frames (z. B. Editor-iframe, Viewer) einblenden.
    try {
        if (window.top !== window.self) {
            return;
        }
    } catch (e) {
        return;
    }
    if (window.__intranetOfficeFooterLoader) {
        return;
    }
    window.__intranetOfficeFooterLoader = true;

    var meta = document.querySelector('meta[name="intranet-office-footer-api"]');
    var api = meta ? meta.getAttribute('content') : '/api/office/footer';
    if (!api || api.charAt(0) !== '/' || api.charAt(1) === '/') {
        api = '/api/office/footer';
    }

    function sameOriginPath(value) {
        return typeof value === 'string' && value.charAt(0) === '/' && value.charAt(1) !== '/';
    }

    function start() {
        var url = api + (api.indexOf('?') === -1 ? '?' : '&') + 'seite=' + encodeURIComponent(location.pathname + location.search);
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (config) {
                if (!config) {
                    return;
                }
                if (config.redirect && sameOriginPath(config.redirect)) {
                    // Direktaufruf ohne Intranet-Einstieg: einmal ueber den
                    // Intranet-Einstieg leiten (Schleifenschutz per sessionStorage).
                    var key = 'intranetOfficeRedirect';
                    var last = 0;
                    try { last = parseInt(sessionStorage.getItem(key) || '0', 10); } catch (e) { last = 0; }
                    if (Date.now() - last > 30000) {
                        try { sessionStorage.setItem(key, String(Date.now())); } catch (e) { /* ignorieren */ }
                        location.replace(config.redirect);
                        return;
                    }
                }
                if (!config.enabled || !config.assets) {
                    return;
                }
                if (!sameOriginPath(config.assets.css) || !sameOriginPath(config.assets.js)) {
                    return;
                }
                window.IntranetOfficeFooterConfig = config;
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = config.assets.css;
                document.head.appendChild(link);
                var script = document.createElement('script');
                script.src = config.assets.js;
                script.async = true;
                document.head.appendChild(script);
            })
            .catch(function () { /* Intranet nicht erreichbar: Nextcloud bleibt nutzbar. */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
