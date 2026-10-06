/*
 * Active Directory: manuelle Synchronisation im Overlay. Der Server meldet den
 * Fortschritt je Identitaetsquelle als Server-Sent Events (POST, daher per
 * fetch statt EventSource). Das Overlay laesst sich nur mit OK schliessen;
 * danach wird die Seite neu geladen (Zaehler, Protokoll). Inhalte werden nur
 * per textContent gesetzt. Ohne fetch/dialog bleibt das normale Formular.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-ad-sync]');
    var dialog = document.getElementById('ad-sync-dialog');
    if (!form || !dialog || typeof dialog.showModal !== 'function' || !window.fetch || !window.TextDecoder) {
        return;
    }

    var button = form.querySelector('button[type="submit"]');
    var status = document.getElementById('ad-sync-status');
    var list = document.getElementById('ad-sync-sources');
    var summary = document.getElementById('ad-sync-summary');
    var ok = document.getElementById('ad-sync-ok');
    var items = {};
    var running = null;
    var timer = null;
    var started = 0;
    var finished = false;
    var acknowledged = false;

    function number(value) {
        return Number(value || 0).toLocaleString('de-DE');
    }

    function item(source) {
        var key = String(source.id);
        if (!items[key]) {
            var row = document.createElement('li');
            row.className = 'ad-sync__source ad-sync__source--waiting';
            var label = document.createElement('span');
            label.className = 'ad-sync__label';
            label.textContent = source.label || ('Quelle ' + key);
            var state = document.createElement('span');
            state.className = 'ad-sync__state';
            state.textContent = 'wartet';
            var detail = document.createElement('span');
            detail.className = 'ad-sync__detail';
            detail.hidden = true;
            row.appendChild(label);
            row.appendChild(state);
            row.appendChild(detail);
            list.appendChild(row);
            items[key] = {row: row, state: state, detail: detail};
        }
        return items[key];
    }

    function setState(entry, modifier, text, detail) {
        entry.row.className = 'ad-sync__source ad-sync__source--' + modifier;
        entry.state.textContent = text;
        entry.detail.textContent = detail || '';
        entry.detail.hidden = !detail;
    }

    function tick() {
        if (running) {
            running.state.textContent = 'wird synchronisiert … ' + Math.round((Date.now() - started) / 1000) + ' s';
        }
    }

    function finish(message, state) {
        if (finished) {
            return;
        }
        finished = true;
        window.clearInterval(timer);
        timer = null;
        running = null;
        Object.keys(items).forEach(function (key) {
            if (/--(waiting|running)$/.test(items[key].row.className)) {
                setState(items[key], 'error', 'nicht abgeschlossen');
            }
        });
        status.textContent = state === 'success' ? 'Synchronisation abgeschlossen.' : 'Synchronisation beendet – bitte Hinweise prüfen.';
        summary.textContent = message;
        summary.hidden = !message;
        summary.className = 'ad-sync__summary ad-sync__summary--' + (state === 'success' ? 'ok' : 'error');
        ok.disabled = false;
        ok.focus();
    }

    function handle(event, data) {
        if (event === 'sources') {
            (data.sources || []).forEach(item);
            status.textContent = (data.sources || []).length === 1
                ? 'Die Identitätsquelle wird synchronisiert …'
                : 'Die Identitätsquellen werden nacheinander synchronisiert …';
        } else if (event === 'source-start') {
            running = item(data);
            started = Date.now();
            setState(running, 'running', 'wird synchronisiert …');
        } else if (event === 'source-done') {
            var entry = item(data);
            if (entry === running) {
                running = null;
            }
            if (data.status === 'success') {
                var text = number(data.processed) + ' aktualisiert, ' + number(data.deactivated) + ' deaktiviert';
                if (data.groups) {
                    text += ', ' + number(data.groups) + ' AD-Gruppen';
                }
                setState(entry, data.warning ? 'warn' : 'ok', text, data.warning || '');
            } else {
                setState(entry, 'error', 'fehlgeschlagen – letzter Stand bleibt erhalten', data.message || '');
            }
        } else if (event === 'done') {
            finish(data.message || '', data.status);
        } else if (event === 'error') {
            finish(data.message || 'Die Synchronisation ist fehlgeschlagen.', 'error');
        }
    }

    function parse(block) {
        var event = 'message';
        var data = [];
        block.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) {
                event = line.slice(6).trim();
            } else if (line.indexOf('data:') === 0) {
                data.push(line.slice(5).replace(/^ /, ''));
            }
        });
        if (data.length === 0) {
            return;
        }
        try {
            handle(event, JSON.parse(data.join('\n')));
        } catch (error) {
            // Unvollstaendige oder fremde Zeilen ignorieren.
        }
    }

    function consume(buffer, final) {
        var blocks = buffer.replace(/\r\n?/g, '\n').split('\n\n');
        var rest = final ? '' : blocks.pop();
        blocks.forEach(parse);
        return rest;
    }

    function read(response) {
        var decoder = new TextDecoder();
        if (!response.body || !response.body.getReader) {
            return response.text().then(function (text) {
                consume(text, true);
            });
        }
        var reader = response.body.getReader();
        var buffer = '';
        function pump() {
            return reader.read().then(function (chunk) {
                if (chunk.done) {
                    consume(buffer + decoder.decode(), true);
                    return;
                }
                buffer = consume(buffer + decoder.decode(chunk.value, {stream: true}), false);
                return pump();
            });
        }
        return pump();
    }

    function failure(response) {
        return response.json().catch(function () {
            return {};
        }).then(function (data) {
            throw new Error(data && data.error ? data.error : (response.status === 419
                ? 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'
                : 'Die Synchronisation konnte nicht gestartet werden (HTTP ' + response.status + ').'));
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (button && button.disabled) {
            return;
        }
        if (button) {
            button.disabled = true;
        }
        items = {};
        list.textContent = '';
        summary.hidden = true;
        summary.textContent = '';
        status.textContent = 'Synchronisation wird gestartet …';
        ok.disabled = true;
        finished = false;
        acknowledged = false;
        dialog.showModal();
        timer = window.setInterval(tick, 1000);

        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'text/event-stream'},
            body: new URLSearchParams(new FormData(form))
        }).then(function (response) {
            var type = response.headers.get('Content-Type') || '';
            if (!response.ok || type.indexOf('text/event-stream') !== 0) {
                return failure(response);
            }
            return read(response).then(function () {
                finish('Die Verbindung wurde vor dem Ende der Synchronisation getrennt. Der Status ist im Protokoll nach dem Neuladen ersichtlich.', 'error');
            });
        }).catch(function (error) {
            finish(error && error.message ? error.message : 'Die Synchronisation ist fehlgeschlagen.', 'error');
        });
    });

    ok.addEventListener('click', function () {
        acknowledged = true;
        dialog.close();
        window.location.assign(window.location.pathname);
    });

    // Nur per OK schliessen: Escape wird unterdrueckt, ein dennoch
    // geschlossenes Overlay (z. B. wiederholtes Escape) erneut geoeffnet.
    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
    });
    dialog.addEventListener('close', function () {
        if (!acknowledged) {
            dialog.showModal();
        }
    });
}());
