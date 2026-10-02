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
            node.textContent = String(value);
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
        var percent = fill.percent === null || fill.percent === undefined ? 0 : Math.min(100, Number(fill.percent));
        node.value = percent;
        node.textContent = percentText(fill);
        node.className = 'fillbar fillbar--' + (fill.state || 'disabled');
    }

    function render(data) {
        badge(root, 'ha-state', data.ha.state);
        text(root, 'ha-message', data.ha.message);
        badge(root, 'sync-state', data.sync.state);
        text(root, 'sync-message', data.sync.message);
        badge(root, 'mode', data.mode);

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
            text(row, 'fill-text', percentText(target.fill));
            text(row, 'read', target.read);
            text(row, 'write', target.write);
            text(row, 'iops', target.iops);
            if (row.querySelector('[data-live="sync"]')) {
                badge(row, 'sync', target.in_sync ? 'ok' : 'degraded', target.in_sync ? 'synchron' : 'ausstehend');
            }
            fillbar('target-' + target.id, target.fill);
        });

        text(document, 'updated', 'Live aktualisiert: ' + new Date().toLocaleTimeString('de-DE'));
    }

    function schedule() {
        window.clearTimeout(timer);
        // Bei Fehlern seltener fragen (max. 60 s).
        timer = window.setTimeout(poll, Math.min(60000, interval * Math.pow(2, failures)));
    }

    function poll() {
        if (document.hidden) {
            schedule();
            return;
        }
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', redirect: 'error' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                failures = 0;
                render(data);
            })
            .catch(function () {
                failures = Math.min(failures + 1, 4);
                text(document, 'updated', 'Live-Aktualisierung unterbrochen – Seite neu laden, falls die Anmeldung abgelaufen ist.');
            })
            .then(schedule);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
        }
    });
    schedule();
}());
