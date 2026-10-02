/*
 * Intranet-Integration: Einstellung "Netzlaufwerke anzeigen" im Dateien-App
 * (Dateien > Einstellungen).
 *
 * Die Netzlaufwerke meldet das Anmeldeskript des Windows-Clients an das
 * Intranet, das sie (ohne ausgeschlossene Laufwerke) an Nextcloud uebergibt.
 * Eingebunden werden sie erst, wenn der Benutzer die Anzeige hier aktiviert.
 * Das Windows-Kennwort wird einmal hinterlegt und gilt fuer alle Laufwerke.
 */
(function () {
    'use strict';

    if (window.__intranetNetworkDrives) {
        return;
    }
    window.__intranetNetworkDrives = true;

    var state = null;
    var root = document.createElement('div');
    root.className = 'intranet-network-drives';
    root.style.display = 'grid';
    root.style.gap = '8px';
    root.style.marginBottom = '16px';

    function apiUrl() {
        var path = '/apps/intranet_integration/api/network-drives';
        if (window.OC && typeof window.OC.generateUrl === 'function') {
            return window.OC.generateUrl(path);
        }
        return ((window.OC && window.OC.webroot) || '') + '/index.php' + path;
    }

    function requestToken() {
        if (window.OC && window.OC.requestToken) {
            return window.OC.requestToken;
        }
        return document.head ? (document.head.getAttribute('data-requesttoken') || '') : '';
    }

    function request(method, body) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'requesttoken': requestToken(), 'OCS-APIREQUEST': 'true' }
        };
        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        return fetch(apiUrl(), options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok) {
                    throw new Error(data && data.message ? data.message : 'Anfrage fehlgeschlagen (HTTP ' + response.status + ').');
                }
                return data;
            });
        });
    }

    function el(tag, text, style) {
        var node = document.createElement(tag);
        if (text) {
            node.textContent = text;
        }
        if (style) {
            Object.keys(style).forEach(function (key) { node.style[key] = style[key]; });
        }
        return node;
    }

    function hint(text) {
        return el('p', text, { color: 'var(--color-text-maxcontrast)', margin: '0' });
    }

    function reloadSoon() {
        window.setTimeout(function () { window.location.reload(); }, 600);
    }

    function render(message, isError) {
        root.textContent = '';
        root.appendChild(el('h3', 'Netzlaufwerke', { margin: '0' }));

        if (!state) {
            root.appendChild(hint(message || 'Wird geladen …'));
            return;
        }

        var id = 'intranet-network-drives-toggle';
        var row = el('div', '', { display: 'flex', alignItems: 'center', gap: '8px' });
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.id = id;
        box.className = 'checkbox';
        box.checked = !!state.opted_in;
        box.disabled = !state.enabled || !state.available;
        var label = el('label', 'Netzlaufwerke anzeigen');
        label.setAttribute('for', id);
        row.appendChild(box);
        row.appendChild(label);
        root.appendChild(row);

        box.addEventListener('change', function () {
            box.disabled = true;
            request('POST', { enabled: box.checked }).then(function (data) {
                state = data;
                if (data.ok === false) {
                    render(data.message || 'Speichern fehlgeschlagen.', true);
                    return;
                }
                if (!box.checked || data.credentials || !data.drives.length) {
                    render('Gespeichert – Ansicht wird neu geladen …');
                    reloadSoon();
                } else {
                    render('Bitte jetzt einmalig Ihr Windows-Kennwort hinterlegen.');
                }
            }).catch(function (error) {
                box.checked = !box.checked;
                box.disabled = false;
                render(error.message, true);
            });
        });

        root.appendChild(hint('Zeigt die Netzlaufwerke Ihres Windows-Computers (z. B. H:) unter „Alle Dateien“ an. '
            + 'Netzlaufwerke zählen nicht zu Ihrem Speicherplatz-Kontingent.'));

        if (!state.enabled) {
            root.appendChild(hint('Die Weitergabe von Netzlaufwerken ist im Intranet ausgeschaltet.'));
        } else if (!state.available) {
            root.appendChild(hint('Netzlaufwerke sind auf diesem Server derzeit nicht verfügbar (Externer Speicher/SMB fehlt).'));
        }

        if (state.drives && state.drives.length) {
            var list = el('ul', '', { margin: '0', paddingLeft: '18px', listStyle: 'disc' });
            state.drives.forEach(function (drive) {
                list.appendChild(el('li', drive.letter + ':  ' + drive.path));
            });
            root.appendChild(list);
        } else if (state.enabled) {
            root.appendChild(hint('Noch keine Netzlaufwerke gemeldet – sie werden bei der nächsten Windows-Anmeldung übertragen.'));
        }

        if (state.excluded && state.excluded.length) {
            root.appendChild(hint('Nie angezeigt: ' + state.excluded.map(function (letter) { return letter + ':'; }).join(', ')));
        }

        if (state.opted_in && state.enabled && state.available) {
            root.appendChild(credentialsForm());
        }

        if (message) {
            root.appendChild(el('p', message, { margin: '0', color: isError ? 'var(--color-error-text, var(--color-error))' : 'var(--color-success-text, inherit)' }));
        }
    }

    function credentialsForm() {
        var form = el('form', '', { display: 'grid', gap: '6px', maxWidth: '420px' });
        form.appendChild(hint(state.credentials
            ? 'Windows-Kennwort ist hinterlegt. Nach einer Kennwortänderung in Windows hier erneut speichern.'
            : 'Für den Zugriff wird einmalig Ihr Windows-Kennwort benötigt. Es wird verschlüsselt gespeichert und gilt für alle Ihre Netzlaufwerke.'));

        var login = document.createElement('input');
        login.type = 'text';
        login.autocomplete = 'username';
        login.value = state.login || '';
        login.setAttribute('aria-label', 'Windows-Benutzername');
        login.placeholder = 'Windows-Benutzername';

        var password = document.createElement('input');
        password.type = 'password';
        password.autocomplete = 'current-password';
        password.required = true;
        password.setAttribute('aria-label', 'Windows-Kennwort');
        password.placeholder = 'Windows-Kennwort';

        var button = el('button', state.credentials ? 'Kennwort aktualisieren' : 'Kennwort speichern');
        button.type = 'submit';
        button.className = 'primary';

        form.appendChild(login);
        form.appendChild(password);
        form.appendChild(button);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!password.value) {
                return;
            }
            button.disabled = true;
            request('POST', { login: login.value, password: password.value }).then(function (data) {
                state = data;
                render('Kennwort gespeichert – Ansicht wird neu geladen …');
                reloadSoon();
            }).catch(function (error) {
                button.disabled = false;
                render(error.message, true);
            });
        });

        return form;
    }

    function load() {
        render();
        request('GET').then(function (data) {
            state = data;
            render();
        }).catch(function (error) {
            render(error.message, true);
        });
    }

    function register() {
        var settings = window.OCA && window.OCA.Files && window.OCA.Files.Settings;
        if (!settings || typeof settings.register !== 'function' || typeof settings.Setting !== 'function') {
            return false;
        }
        settings.register(new settings.Setting('intranet-network-drives', {
            el: function () {
                if (!state) {
                    load();
                }
                return root;
            },
            open: load,
            close: function () {},
            order: 5
        }));
        return true;
    }

    var attempts = 0;
    (function tryRegister() {
        if (register()) {
            return;
        }
        if (++attempts < 100) {
            window.setTimeout(tryRegister, 100);
        } else if (window.console) {
            window.console.warn('Intranet-Integration: Dateien-Einstellungen nicht gefunden, "Netzlaufwerke anzeigen" nicht verfügbar.');
        }
    })();
})();
