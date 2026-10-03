/*
 * Intranet-Integration: Rechtsklick-Aktion "Vorgängerversionen" in der
 * Dateien-App (nur Administratoren). Zeigt die vom Snapshot-Speicher
 * gesicherten Versionen einer Datei und stoesst nach Ja/Nein-Bestaetigung
 * eine Wiederherstellung an (/apps/intranet_integration/api/snapshots).
 *
 * Die Aktion wird in die Registry von @nextcloud/files eingetragen
 * (window._nc_fileactions), ohne Build-Schritt. Alle Inhalte werden ueber
 * textContent gesetzt, nie als HTML.
 */
(function () {
    'use strict';

    if (window.__intranetSnapshots) {
        return;
    }
    window.__intranetSnapshots = true;

    var ACTION_ID = 'intranet-snapshots';
    var ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M13 3a9 9 0 0 0-9 9H1l3.9 3.9.1.1L9 12H6a7 7 0 1 1 2 4.9l-1.4 1.4A9 9 0 1 0 13 3m-1 5v5l4.3 2.5.7-1.2-3.5-2.1V8z"/></svg>';
    var base = (window.OC && typeof window.OC.generateUrl === 'function')
        ? window.OC.generateUrl('/apps/intranet_integration/api/snapshots')
        : '/apps/intranet_integration/api/snapshots';

    function token() {
        if (window.OC && window.OC.requestToken) {
            return window.OC.requestToken;
        }
        var head = document.querySelector('head[data-requesttoken]');
        return head ? head.getAttribute('data-requesttoken') : '';
    }

    function isAdmin() {
        return !!(window.OC && typeof window.OC.isUserAdmin === 'function' && window.OC.isUserAdmin());
    }

    function formatBytes(bytes) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var value = Math.max(0, bytes || 0);
        var i = 0;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i++;
        }
        return value.toLocaleString('de-DE', { maximumFractionDigits: i === 0 ? 0 : 1 }) + ' ' + units[i];
    }

    function formatTime(unix) {
        if (!unix) {
            return '–';
        }
        return new Date(unix * 1000).toLocaleString('de-DE', {
            day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function request(url, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({ Accept: 'application/json', requesttoken: token() }, options.headers || {});
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || data.ok === false) {
                    var error = new Error(data.message || ('Fehler ' + response.status));
                    error.state = data.state;
                    throw error;
                }
                return data;
            });
        });
    }

    var overlay = null;

    function close() {
        if (overlay) {
            overlay.remove();
            overlay = null;
            document.removeEventListener('keydown', onKey);
        }
    }

    function onKey(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    function open(node) {
        close();
        overlay = el('div', 'intranet-snapshots');
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'intranet-snapshots-title');
        var panel = el('div', 'intranet-snapshots__panel');
        var head = el('header', 'intranet-snapshots__head');
        var title = el('h2', 'intranet-snapshots__title', 'Vorgängerversionen');
        title.id = 'intranet-snapshots-title';
        var closeButton = el('button', 'intranet-snapshots__close', 'Schließen');
        closeButton.type = 'button';
        closeButton.addEventListener('click', close);
        head.appendChild(title);
        head.appendChild(closeButton);
        var file = el('p', 'intranet-snapshots__file', node.basename || node.name || '');
        var status = el('p', 'intranet-snapshots__status', 'Versionen werden geladen …');
        status.setAttribute('role', 'status');
        var list = el('div', 'intranet-snapshots__list');
        panel.appendChild(head);
        panel.appendChild(file);
        panel.appendChild(status);
        panel.appendChild(list);
        overlay.appendChild(panel);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                close();
            }
        });
        document.body.appendChild(overlay);
        document.addEventListener('keydown', onKey);
        closeButton.focus();

        request(base + '?fileId=' + encodeURIComponent(node.fileid))
            .then(function (data) {
                render(node, data, list, status);
            })
            .catch(function (error) {
                status.textContent = error.message || 'Die Versionen konnten nicht geladen werden.';
                status.classList.add('intranet-snapshots__status--error');
            });
    }

    function render(node, data, list, status) {
        list.textContent = '';
        if (!data.snapshots || data.snapshots.length === 0) {
            status.textContent = 'Für diese Datei sind keine Vorgängerversionen gesichert.';
            return;
        }
        status.textContent = data.snapshots.length + ' Version(en) · aktuell: ' + formatBytes(data.current.size) + ', Stand ' + formatTime(data.current.mtime);
        var table = el('table', 'intranet-snapshots__table');
        var thead = el('thead');
        var hr = el('tr');
        ['Version', 'Stand', 'Gesichert', 'Größe', 'Geändert von', ''].forEach(function (label) {
            hr.appendChild(el('th', null, label));
        });
        thead.appendChild(hr);
        table.appendChild(thead);
        var tbody = el('tbody');
        data.snapshots.forEach(function (snapshot) {
            var tr = el('tr');
            tr.appendChild(el('td', null, String(snapshot.version)));
            tr.appendChild(el('td', null, formatTime(snapshot.mtime)));
            tr.appendChild(el('td', null, formatTime(snapshot.created_at)));
            tr.appendChild(el('td', null, formatBytes(snapshot.size)));
            tr.appendChild(el('td', null, snapshot.user || '–'));
            var cell = el('td');
            var button = el('button', 'intranet-snapshots__restore', 'Wiederherstellen');
            button.type = 'button';
            button.addEventListener('click', function () {
                confirmRestore(node, data, snapshot, status, button);
            });
            cell.appendChild(button);
            if (snapshot.restored_at) {
                cell.appendChild(el('div', 'intranet-snapshots__hint', 'zuletzt wiederhergestellt ' + formatTime(snapshot.restored_at)));
            }
            tr.appendChild(cell);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        list.appendChild(table);
    }

    function confirmRestore(node, data, snapshot, status, button) {
        var existing = overlay.querySelector('.intranet-snapshots__confirm');
        if (existing) {
            existing.remove();
        }
        var box = el('div', 'intranet-snapshots__confirm');
        box.setAttribute('role', 'alertdialog');
        box.appendChild(el('p', 'intranet-snapshots__confirm-title', 'Möchten Sie diese Dateiversion wirklich wiederherstellen?'));
        var dl = el('dl');
        [['Datei', data.path], ['Version', String(snapshot.version)], ['Stand', formatTime(snapshot.mtime)]].forEach(function (pair) {
            dl.appendChild(el('dt', null, pair[0]));
            dl.appendChild(el('dd', null, pair[1]));
        });
        box.appendChild(dl);
        box.appendChild(el('p', 'intranet-snapshots__hint', 'Die aktuelle Datei wird durch diese Vorgängerversion ersetzt. Dabei entsteht keine neue Dateiversion.'));
        var actions = el('div', 'intranet-snapshots__actions');
        var no = el('button', 'intranet-snapshots__button', 'Nein');
        no.type = 'button';
        var yes = el('button', 'intranet-snapshots__button intranet-snapshots__button--primary', 'Ja, wiederherstellen');
        yes.type = 'button';
        no.addEventListener('click', function () { box.remove(); button.focus(); });
        yes.addEventListener('click', function () {
            yes.disabled = true;
            no.disabled = true;
            status.textContent = 'Wiederherstellung läuft …';
            status.classList.remove('intranet-snapshots__status--error');
            var body = new URLSearchParams();
            body.set('fileId', String(node.fileid));
            body.set('uid', snapshot.uid);
            request(base + '/restore', { method: 'POST', body: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
                .then(function (result) {
                    box.remove();
                    status.textContent = result.message || 'Die Datei wurde wiederhergestellt.';
                    refreshView();
                    return request(base + '?fileId=' + encodeURIComponent(node.fileid)).then(function (fresh) {
                        render(node, fresh, overlay.querySelector('.intranet-snapshots__list'), status);
                        status.textContent = result.message || 'Die Datei wurde wiederhergestellt.';
                    });
                })
                .catch(function (error) {
                    box.remove();
                    status.textContent = error.message || 'Die Wiederherstellung ist fehlgeschlagen.';
                    status.classList.add('intranet-snapshots__status--error');
                });
        });
        actions.appendChild(no);
        actions.appendChild(yes);
        box.appendChild(actions);
        overlay.querySelector('.intranet-snapshots__panel').appendChild(box);
        no.focus();
    }

    function refreshView() {
        // Dateiliste neu laden, damit Groesse/Datum der wiederhergestellten Datei stimmen.
        try {
            if (window.OCP && window.OCP.Files && window.OCP.Files.Router && typeof window.OCP.Files.Router.goToRoute === 'function') {
                var router = window.OCP.Files.Router;
                router.goToRoute(null, router.params, Object.assign({}, router.query, { _r: String(Date.now()) }), true);
                return;
            }
        } catch (e) {
            // Fallback unten
        }
        window.dispatchEvent(new CustomEvent('files:node:updated'));
    }

    var action = {
        id: ACTION_ID,
        order: 95,
        displayName: function () { return 'Vorgängerversionen'; },
        iconSvgInline: function () { return ICON; },
        enabled: function (nodes) {
            if (!isAdmin() || !nodes || nodes.length !== 1) {
                return false;
            }
            var node = nodes[0];
            var path = String(node.path || node.dirname || '');
            // Nur eigene/geteilte Dateien im Home-Speicher, keine Ordner, kein Papierkorb.
            return node.type === 'file' && !!node.fileid && !/^\/?files_trashbin\//.test(path);
        },
        exec: function (node) {
            open(node);
            return Promise.resolve(null);
        }
    };

    function register() {
        if (typeof window._nc_fileactions === 'undefined') {
            window._nc_fileactions = [];
        }
        if (window._nc_fileactions.some(function (existing) { return existing.id === ACTION_ID; })) {
            return;
        }
        window._nc_fileactions.push(action);
    }

    register();
}());
