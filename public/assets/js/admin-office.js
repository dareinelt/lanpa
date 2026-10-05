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

/*
 * Lokale KI: Verbindungstest im Overlay. Sendet die aktuellen (auch
 * ungespeicherten) Formularwerte, prueft /models und zeigt die Antwort des
 * Modells auf die Testnachricht. Inhalte werden nur per textContent gesetzt.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-ai-form]');
    var button = form ? form.querySelector('[data-ai-test]') : null;
    var dialog = document.getElementById('ai-test-dialog');
    if (!form || !button || !dialog || typeof dialog.showModal !== 'function' || !window.fetch) {
        return;
    }

    var steps = document.getElementById('ai-test-steps');
    var chat = document.getElementById('ai-test-chat');
    var answer = document.getElementById('ai-test-answer');
    var model = document.getElementById('ai-test-model');
    var meta = document.getElementById('ai-test-meta');
    var again = document.getElementById('ai-test-again');
    var closer = document.getElementById('ai-test-close');
    var controller = null;
    var timer = null;

    button.hidden = false;

    function step(text, state) {
        var item = document.createElement('li');
        item.className = 'ai-test__step ai-test__step--' + state;
        item.textContent = text;
        steps.appendChild(item);
        return item;
    }

    function finish() {
        window.clearInterval(timer);
        timer = null;
        controller = null;
        again.disabled = false;
        button.disabled = false;
    }

    function run() {
        if (controller) {
            return;
        }
        steps.textContent = '';
        meta.textContent = '';
        model.textContent = '';
        answer.textContent = '';
        chat.hidden = true;
        again.disabled = true;
        button.disabled = true;

        var started = Date.now();
        var pending = step('Test läuft …', 'pending');
        timer = window.setInterval(function () {
            pending.textContent = 'Test läuft … ' + Math.round((Date.now() - started) / 1000) + ' s';
        }, 1000);

        controller = window.AbortController ? new AbortController() : null;
        fetch('/admin/office/ki/testen', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
            body: new URLSearchParams(new FormData(form)),
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error(response.status === 419
                    ? 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'
                    : 'Unerwartete Antwort des Servers (HTTP ' + response.status + ').');
            });
        }).then(function (data) {
            steps.textContent = '';
            var errors = data.errors || {};
            Object.keys(errors).forEach(function (key) {
                step(errors[key], 'error');
            });
            (data.steps || []).forEach(function (item) {
                step(item.label + ': ' + item.message, item.ok ? 'ok' : 'error');
            });
            if (data.answer) {
                chat.hidden = false;
                answer.textContent = data.answer;
                model.textContent = data.model ? '(' + data.model + ')' : '';
                var info = [];
                if (data.duration_ms) {
                    info.push((data.duration_ms / 1000).toLocaleString('de-DE', {maximumFractionDigits: 1}) + ' s');
                }
                if (data.tokens) {
                    info.push(data.tokens + ' Token');
                }
                meta.textContent = info.join(' · ');
            }
            if (!data.steps || data.steps.length === 0) {
                if (Object.keys(errors).length === 0) {
                    step('Der Test konnte nicht ausgeführt werden.', 'error');
                }
            }
        }).catch(function (error) {
            steps.textContent = '';
            if (!error || error.name !== 'AbortError') {
                step(error && error.message ? error.message : 'Der Test ist fehlgeschlagen.', 'error');
            }
        }).then(finish);
    }

    button.addEventListener('click', function () {
        dialog.showModal();
        run();
    });
    again.addEventListener('click', run);
    closer.addEventListener('click', function () {
        dialog.close();
    });
    dialog.addEventListener('close', function () {
        if (controller) {
            controller.abort();
        }
    });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
}());
