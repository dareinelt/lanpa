/*
 * Admin "Office → Orvanta – DAG-Hosts": Live-Aktualisierung der Host-Kacheln.
 *
 * Die Kacheln zeigen Latenz, verbundene Sitzungen und Status jedes
 * Exchange-Hosts. Der Server liefert dieselben Werte wie beim ersten Rendern
 * unter /admin/office/orvanta/hosts/daten (JSON, nur lesend); die Seite bleibt
 * ohne JavaScript vollständig bedienbar, dann eben ohne Auto-Aktualisierung.
 * Wird der Tab verborgen, pausiert die Abfrage; laufende Abfragen
 * ueberlappen nicht.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-orvanta-hosts]');
    if (!root) {
        return;
    }

    var url = root.getAttribute('data-refresh-url');
    var interval = parseInt(root.getAttribute('data-refresh-interval'), 10);
    if (!url) {
        return;
    }
    if (!isFinite(interval) || interval < 5) {
        interval = 30;
    }
    interval = Math.min(120, interval) * 1000;

    var STATUS_CLASSES = ['badge--ok', 'badge--warn', 'badge--muted'];
    var STATUS_BADGE = {
        online: 'badge--ok',
        offline: 'badge--warn',
        maintenance: 'badge--muted',
        unknown: 'badge--muted'
    };

    var summary = root.querySelector('[data-hosts-summary]');
    var generated = root.querySelector('[data-hosts-generated]');
    var manual = root.querySelector('[data-hosts-refresh]');
    var tiles = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-host-id]'), function (tile) {
        tiles[tile.getAttribute('data-host-id')] = tile;
    });

    var timer = null;
    var running = false;

    function setText(tile, field, value) {
        var node = tile.querySelector('[data-field="' + field + '"]');
        if (node) {
            node.textContent = value;
        }
    }

    function applyHost(host) {
        var tile = tiles[String(host.id)];
        if (!tile) {
            return;
        }
        var status = typeof host.status === 'string' ? host.status : 'unknown';
        tile.setAttribute('data-status', status);
        var badge = tile.querySelector('[data-field="status"]');
        if (badge) {
            badge.textContent = typeof host.status_label === 'string' ? host.status_label : status;
            STATUS_CLASSES.forEach(function (name) {
                badge.classList.remove(name);
            });
            badge.classList.add(STATUS_BADGE[status] || 'badge--muted');
        }
        setText(tile, 'latency', host.latency_label || '–');
        setText(tile, 'last_latency', host.last_latency_label || '–');
        setText(tile, 'sessions', String(host.sessions || 0));
        setText(tile, 'check', host.last_check_label || '–');
        setText(tile, 'last_session', host.last_session_label || '–');
        var error = tile.querySelector('[data-field="error"]');
        if (error) {
            error.textContent = host.last_error || '';
            error.hidden = !host.last_error;
        }
    }

    function apply(data) {
        if (!data || !Array.isArray(data.hosts)) {
            return;
        }
        data.hosts.forEach(applyHost);
        var totals = data.totals || {};
        if (summary) {
            summary.textContent = (totals.hosts || 0) + ' Host(s), davon ' + (totals.active || 0)
                + ' aktiv und ' + (totals.online || 0) + ' online · ' + (totals.sessions || 0)
                + ' verbundene Sitzung(en)';
        }
        if (generated && data.generated_at) {
            generated.textContent = data.generated_at;
        }
    }

    function load() {
        if (running || document.hidden) {
            return;
        }
        running = true;
        window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                apply(data);
            })
            .catch(function () {
                // Ein fehlgeschlagener Abruf laesst die zuletzt bekannten Werte stehen.
            })
            .then(function () {
                running = false;
            });
    }

    function schedule() {
        window.clearInterval(timer);
        if (document.hidden) {
            return;
        }
        timer = window.setInterval(load, interval);
    }

    if (manual) {
        manual.addEventListener('click', load);
    }
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            window.clearInterval(timer);
        } else {
            load();
            schedule();
        }
    });
    schedule();
}());
