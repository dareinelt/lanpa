/*
 * Adminbereich Speicher (HA): Live-Aktualisierung von HA-/Sync-Status,
 * Fuellstand, Datenrate und IOPS des Hot-Tiers (lokales Storage) und der
 * Speicherziele des Cold-Tiers (SMB-Tier).
 */
(function () {
    'use strict';

    var root = document.getElementById('storage-live');
    if (!root || !window.fetch) {
        return;
    }

    var url = root.getAttribute('data-url') || '/admin/speicher-ha/status';
    var interval = 5000;
    var failures = 0;
    var timer = null;
    var inFlight = false;

    var LABELS = {
        ok: 'OK', degraded: 'eingeschränkt', critical: 'kritisch', disabled: 'inaktiv',
        in_sync: 'synchron', syncing: 'überträgt', lagging: 'Rückstand', error: 'Fehler',
        online: 'erreichbar', offline: 'nicht erreichbar', mounting: 'wird eingebunden',
        invalid: 'ungültig', unknown: 'unbekannt', normal: 'normal', remote_only: 'nur Cold-Tier',
        blocked: 'angehalten', paused: 'pausiert'
    };
    var BADGES = {
        ok: 'badge--ok', in_sync: 'badge--ok', online: 'badge--ok', normal: 'badge--ok',
        degraded: 'badge--warn', lagging: 'badge--warn', syncing: 'badge--warn', remote_only: 'badge--warn', mounting: 'badge--warn',
        paused: 'badge--warn',
        critical: 'badge--error', error: 'badge--error', offline: 'badge--error', invalid: 'badge--error', blocked: 'badge--error'
    };

    function percentText(fill) {
        if (!fill || fill.percent === null || fill.percent === undefined) {
            return '–';
        }
        return Number(fill.percent).toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' %';
    }

    function text(scope, key, value) {
        var node = scope.querySelector('[data-live="' + key + '"]');
        if (node && value !== undefined && value !== null) {
            if (node.textContent !== String(value)) {
                node.textContent = String(value);
            }
        }
    }

    function badge(scope, key, state, label) {
        var node = scope.querySelector('[data-live="' + key + '"]');
        if (!node) {
            return;
        }
        node.className = 'badge ' + (BADGES[state] || 'badge--muted');
        node.textContent = label !== undefined ? label : (LABELS[state] || state);
    }

    function fillbar(id, fill) {
        var node = document.querySelector('[data-fill="' + id + '"]');
        if (!node || !fill) {
            return;
        }
        var percent = fill.percent === null || fill.percent === undefined ? 0 : Math.max(0, Math.min(100, Number(fill.percent)));
        node.value = percent;
        node.textContent = percentText(fill);
        node.setAttribute('aria-valuetext', fill.percent === null || fill.percent === undefined ? 'Keine Messwerte' : percentText(fill));
        node.className = 'fillbar fillbar--' + (fill.state || 'disabled');
    }

    function render(data) {
        badge(root, 'ha-state', data.ha.state);
        text(root, 'ha-message', data.ha.message);
        badge(root, 'sync-state', data.sync.state);
        text(root, 'sync-message', data.sync.message);
        root.querySelector('[data-status-card="ha"]').dataset.state = data.ha.state;
        root.querySelector('[data-status-card="sync"]').dataset.state = data.sync.state;
        root.querySelector('[data-confirm-deletes]').hidden = data.sync.state !== 'blocked';
        text(root, 'online', data.online);
        text(root, 'active', data.active);
        text(root, 'last-sync', data.last_sync);
        text(root, 'forecast', data.forecast);
        badge(root, 'mode', data.mode);

        var alert = root.querySelector('[data-storage-alert]');
        alert.hidden = !data.alert;
        if (data.alert) {
            alert.className = 'flash flash--' + (data.alert.level === 'error' ? 'error' : 'info');
            text(alert, 'alert-title', data.alert.title);
            text(alert, 'alert-message', data.alert.message);
            text(alert, 'alert-forecast', data.alert.forecast);
            alert.querySelector('[data-live="alert-forecast"]').hidden = !data.alert.forecast;
        }
        Object.keys(data.inventory).forEach(function (key) {
            text(root, 'inventory-' + key, data.inventory[key]);
        });

        text(root, 'local-percent', percentText(data.local.fill));
        text(root, 'local-used', data.local.used);
        text(root, 'local-total', data.local.total);
        text(root, 'local-read', data.local.read);
        text(root, 'local-write', data.local.write);
        text(root, 'local-iops', data.local.iops);
        text(root, 'pending', data.pending_files);
        text(root, 'recalls', data.recalls_active);
        fillbar('local', data.local.fill);

        (data.targets || []).forEach(function (target) {
            var row = document.querySelector('[data-target="' + target.id + '"]');
            if (!row) {
                return;
            }
            badge(row, 'state', target.state);
            row.dataset.state = target.state;
            text(row, 'message', target.message);
            row.querySelector('[data-live="message"]').hidden = !target.message;
            text(row, 'free', target.free);
            text(row, 'total', target.total);
            text(row, 'fill-text', percentText(target.fill));
            text(row, 'read', target.read);
            text(row, 'write', target.write);
            text(row, 'iops', target.iops);
            text(row, 'synced-files', target.synced_files);
            text(row, 'synced-bytes', target.synced_bytes);
            text(row, 'pending', target.pending);
            text(row, 'pending-bytes', target.pending_bytes);
            text(row, 'lag', target.lag);
            row.querySelector('[data-target-pending]').hidden = target.pending === 0 || target.state === 'disabled';
            badge(row, 'sync', target.state === 'disabled' ? 'disabled' : (target.in_sync ? 'ok' : 'degraded'),
                target.state === 'disabled' ? 'inaktiv' : (target.in_sync ? 'synchron' : 'ausstehend'));
            fillbar('target-' + target.id, target.fill);
        });

        var targetIds = Array.from(root.querySelectorAll('[data-target]')).map(function (node) {
            return Number(node.dataset.target);
        });
        var targetsChanged = targetIds.length !== data.targets.length || data.targets.some(function (target) {
            return targetIds.indexOf(target.id) === -1;
        });
        text(root, 'updated', targetsChanged
            ? 'Speicherziele wurden geändert. Bitte Seite neu laden, um die aktuelle Konfiguration zu sehen.'
            : 'Live aktualisiert: ' + new Date().toLocaleTimeString('de-DE') + ' · alle 5 Sekunden');
        root.querySelector('[data-live="updated"]').classList.remove('storage-live-state--error');
    }

    function schedule() {
        window.clearTimeout(timer);
        // Bei Fehlern seltener fragen (max. 60 s).
        timer = window.setTimeout(poll, Math.min(60000, interval * Math.pow(2, failures)));
    }

    function poll() {
        window.clearTimeout(timer);
        if (inFlight) {
            return;
        }
        if (document.hidden) {
            schedule();
            return;
        }
        inFlight = true;
        var controller = new AbortController();
        var timeout = window.setTimeout(function () { controller.abort(); }, 15000);
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', redirect: 'error', signal: controller.signal })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                render(data);
                failures = 0;
            })
            .catch(function () {
                failures = Math.min(failures + 1, 4);
                text(document, 'updated', 'Live-Aktualisierung unterbrochen – Seite neu laden, falls die Anmeldung abgelaufen ist.');
                root.querySelector('[data-live="updated"]').classList.add('storage-live-state--error');
            })
            .then(function () {
                window.clearTimeout(timeout);
                inFlight = false;
                schedule();
            });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
        }
    });
    schedule();
}());
