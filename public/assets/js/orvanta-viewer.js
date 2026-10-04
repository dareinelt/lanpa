/* Orvanta – Anhang im Euro-Office-DocumentServer anzeigen. */
(function () {
    'use strict';

    var root = document.querySelector('[data-orvanta-viewer]');
    if (!root) {
        return;
    }

    var fallback = root.querySelector('[data-ov-viewer-fallback]');
    var config;
    try {
        config = JSON.parse(root.getAttribute('data-config') || '{}');
    } catch (e) {
        config = null;
    }

    function showFallback() {
        if (fallback) {
            fallback.hidden = false;
        }
    }

    if (!config || !config.document) {
        showFallback();
        return;
    }

    config.height = '100%';
    config.width = '100%';
    config.events = {
        onError: showFallback,
        onRequestClose: function () {
            window.close();
        }
    };

    var script = document.createElement('script');
    script.src = root.getAttribute('data-api');
    script.async = true;
    script.onload = function () {
        if (!window.DocsAPI || typeof window.DocsAPI.DocEditor !== 'function') {
            showFallback();
            return;
        }
        try {
            new window.DocsAPI.DocEditor('ov-viewer', config);
        } catch (e) {
            showFallback();
        }
    };
    script.onerror = showFallback;
    document.head.appendChild(script);

    // Wenn der DocumentServer nach 20 Sekunden nichts geliefert hat, Download anbieten.
    window.setTimeout(function () {
        if (!root.querySelector('#ov-viewer iframe')) {
            showFallback();
        }
    }, 20000);
})();
