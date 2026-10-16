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

/*
 * Active Directory: Bearbeiten einer Identitaetsquelle im Overlay. Der Server
 * rendert die Uebersicht samt Overlay (Bearbeiten-Links, Validierungsfehler);
 * hier wird es modal geoeffnet. Escape fuehrt wie "Abbrechen" zurueck zur
 * Uebersicht. Ohne dialog-Unterstuetzung bleibt das Overlay offen im Fluss.
 */
(function () {
    'use strict';

    var dialog = document.querySelector('[data-ad-source-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var target = '/admin/ad';
    if (dialog.open) {
        dialog.close();
    }
    dialog.showModal();

    var first = dialog.querySelector('[aria-invalid="true"]')
        || dialog.querySelector('form input:not([type="hidden"]), form textarea, form select');
    if (first) {
        first.focus();
    }

    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
        window.location.assign(target);
    });
}());

/*
 * Active Directory: Protokoll einer Anmelde-Instanz im Overlay. Die Anwendung
 * hat keinen Docker-Socket; der Dienst sso-agent legt die letzten 100
 * Eintraege und die letzten 48 Stunden im Austauschverzeichnis ab. Hier wird
 * zuerst der Auftrag abgegeben (json=1) und danach im Sekundentakt nachgefragt.
 * Inhalte werden nur per textContent gesetzt. Ohne fetch bleibt das normale
 * Formular: der Server wartet dann selbst und liefert das Overlay fertig.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('ad-logs-dialog');
    if (!dialog || typeof dialog.showModal !== 'function' || !window.fetch || !window.URLSearchParams) {
        return;
    }

    var forms = document.querySelectorAll('[data-ad-logs]');
    if (forms.length === 0) {
        return;
    }

    var title = document.getElementById('ad-logs-service');
    var status = document.getElementById('ad-logs-status');
    var output = document.getElementById('ad-logs-output');
    var download = document.getElementById('ad-logs-download');
    var close = document.getElementById('ad-logs-close');
    var contentPath = '/admin/ad/anmeldeinstanzen/protokoll/inhalt';
    var maxAttempts = 45;
    var timer = null;
    var run = 0;

    if (dialog.open) {
        dialog.close();
        dialog.showModal();
    }

    function setStatus(text, modifier) {
        status.textContent = text;
        status.className = 'ad-logs__status' + (modifier ? ' ad-logs__status--' + modifier : '');
    }

    function setDownload(url) {
        download.href = url || '#';
        download.hidden = !url;
    }

    function setContent(data) {
        if (data.state === 'ready') {
            var lines = Number(data.lines || 0).toLocaleString('de-DE');
            setStatus((data.lines === 1 ? '1 Eintrag' : lines + ' Einträge')
                + (data.generated_at ? ', erstellt am ' + data.generated_at : ''), 'ok');
            output.textContent = data.tail || '(keine Einträge)';
            setDownload(data.download_url || '');
            return true;
        }
        if (data.state === 'error') {
            setStatus(data.message || 'Das Protokoll konnte nicht geladen werden.', 'error');
            output.textContent = '';
            setDownload('');
            return true;
        }
        setStatus('Das Protokoll wird erstellt …', '');
        return false;
    }

    function failure(response) {
        return response.json().catch(function () {
            return {};
        }).then(function (data) {
            throw new Error(data && data.error ? data.error : (response.status === 419
                ? 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'
                : 'Das Protokoll konnte nicht geladen werden (HTTP ' + response.status + ').'));
        });
    }

    function poll(current, name, attempt) {
        if (current !== run) {
            return;
        }
        window.fetch(contentPath + '?service=' + encodeURIComponent(name), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            if (!response.ok) {
                return failure(response);
            }
            return response.json();
        }).then(function (data) {
            if (current !== run || setContent(data || {})) {
                return;
            }
            if (attempt >= maxAttempts) {
                setStatus('Das Protokoll ist nicht innerhalb von ' + maxAttempts
                    + ' Sekunden bereitgestellt worden. Läuft der Dienst sso-agent?', 'error');
                return;
            }
            timer = window.setTimeout(function () {
                poll(current, name, attempt + 1);
            }, 1000);
        }).catch(function (error) {
            if (current === run) {
                setStatus(error && error.message ? error.message : 'Das Protokoll konnte nicht geladen werden.', 'error');
            }
        });
    }

    function start(form) {
        var name = form.querySelector('input[name="service"]');
        name = name ? name.value : '';
        if (!name) {
            return;
        }
        run += 1;
        var current = run;
        window.clearTimeout(timer);
        timer = null;
        title.textContent = name;
        setStatus('Protokoll wird angefordert …', '');
        output.textContent = '';
        setDownload('');
        dialog.showModal();

        var body = new window.URLSearchParams(new window.FormData(form));
        body.set('json', '1');
        window.fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
            body: body
        }).then(function (response) {
            if (!response.ok) {
                return failure(response);
            }
            return response.json();
        }).then(function () {
            poll(current, name, 1);
        }).catch(function (error) {
            if (current === run) {
                setStatus(error && error.message ? error.message : 'Das Protokoll konnte nicht angefordert werden.', 'error');
            }
        });
    }

    for (var index = 0; index < forms.length; index++) {
        forms[index].addEventListener('submit', function (event) {
            event.preventDefault();
            start(event.currentTarget || event.target);
        });
    }

    if (close) {
        close.addEventListener('click', function (event) {
            event.preventDefault();
            run += 1;
            window.clearTimeout(timer);
            timer = null;
            dialog.close();
        });
    }
}());
