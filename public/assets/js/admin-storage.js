/*
 * Adminbereich Speicher (HA): Live-Aktualisierung von HA-/Sync-Status,
 * Fuellstand, Datenrate und IOPS des Hot-Tiers (lokales Storage) und der
 * Speicherziele des Cold-Tiers (SMB-/S3-Tier).
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

    // Ziele eines erweiterten Cold-Tiers: Zustand, Fuellstand und gestapelte Leiste.
    function renderMembers(row, target) {
        var members = target.members || [];
        var stack = row.querySelector('[data-stack]');
        var equal = members.some(function (member) { return member.share === null || member.share === undefined; });
        var x = 0;
        members.forEach(function (member) {
            var item = row.querySelector('[data-member="' + member.id + '"]');
            if (item) {
                item.dataset.state = member.state;
                badge(item, 'member-state', member.state);
                var message = item.querySelector('[data-live="member-message"]');
                if (message) {
                    message.textContent = member.message || '';
                    message.hidden = !member.message || member.state === 'online';
                }
                var full = item.querySelector('[data-live="member-full"]');
                if (full) {
                    full.hidden = !member.fill || member.fill.state !== 'critical';
                }
                text(item, 'member-fill-text', percentText(member.fill));
                text(item, 'member-free', member.free);
                text(item, 'member-total', member.total);
                text(item, 'member-synced-files', member.synced_files);
                text(item, 'member-synced-bytes', member.synced_bytes);
                fillbar('member-' + member.id, member.fill);
            }
            var segment = stack ? stack.querySelector('[data-segment="' + member.id + '"]') : null;
            var width = equal ? 100 / Math.max(1, members.length) : Number(member.share);
            if (segment) {
                var percent = member.fill && member.fill.percent !== null && member.fill.percent !== undefined
                    ? Math.max(0, Math.min(100, Number(member.fill.percent))) : 0;
                var span = Math.max(0, width - 0.4);
                segment.setAttribute('class', 'tier-stack__segment tier-stack__segment--' + ((member.fill && member.fill.state) || 'disabled'));
                var capacity = segment.querySelector('.tier-stack__capacity');
                var used = segment.querySelector('.tier-stack__used');
                capacity.setAttribute('x', x.toFixed(3));
                capacity.setAttribute('width', span.toFixed(3));
                used.setAttribute('x', x.toFixed(3));
                used.setAttribute('width', Math.min(span, width * percent / 100).toFixed(3));
            }
            x += width;
        });
    }

    function memberKey(ids) {
        return ids.slice().sort(function (a, b) { return a - b; }).join(',');
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
            renderMembers(row, target);
        });

        var snapshot = data.snapshot;
        var snapshotCard = document.getElementById('snapshots');
        if (snapshot && snapshotCard) {
            snapshotCard.dataset.state = snapshot.state;
            badge(snapshotCard, 'snapshot-state', snapshot.state);
            text(snapshotCard, 'snapshot-message', snapshot.message);
            snapshotCard.querySelector('[data-live="snapshot-message"]').hidden = !snapshot.message;
            text(snapshotCard, 'snapshot-fill-text', percentText(snapshot.fill));
            text(snapshotCard, 'snapshot-free', snapshot.free);
            text(snapshotCard, 'snapshot-total', snapshot.total);
            text(snapshotCard, 'snapshot-read', snapshot.read);
            text(snapshotCard, 'snapshot-write', snapshot.write);
            text(snapshotCard, 'snapshot-iops', snapshot.iops);
            text(snapshotCard, 'snapshot-total-count', snapshot.snapshots_total);
            text(snapshotCard, 'snapshot-bytes', snapshot.snapshots_bytes);
            text(snapshotCard, 'snapshot-last', snapshot.last_snapshot);
            text(snapshotCard, 'snapshot-pending', snapshot.pending);
            text(snapshotCard, 'snapshot-failed', snapshot.failed);
            fillbar('snapshot', snapshot.fill);
        }

        var targetIds = Array.from(root.querySelectorAll('[data-target]')).map(function (node) {
            return Number(node.dataset.target);
        });
        var memberIds = Array.from(root.querySelectorAll('[data-member]')).map(function (node) {
            return Number(node.dataset.member);
        });
        var liveMemberIds = [];
        data.targets.forEach(function (target) {
            if ((target.members || []).length > 1) {
                target.members.forEach(function (member) { liveMemberIds.push(member.id); });
            }
        });
        var targetsChanged = targetIds.length !== data.targets.length || data.targets.some(function (target) {
            return targetIds.indexOf(target.id) === -1;
        }) || memberKey(memberIds) !== memberKey(liveMemberIds);
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
