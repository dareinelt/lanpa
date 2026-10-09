/*
 * Admin "Office → Orvanta – Nachrichtenfluss": Live-Aktualisierung.
 *
 * Die Seite zeigt Kennzahlen, Knoten, Kanten und Stoerungen des
 * Nachrichtenflusses. Der Server liefert unter
 * /admin/office/orvanta/nachrichtenfluss/daten dasselbe JSON wie beim ersten
 * Rendern (nur lesend). Ohne JavaScript bleibt die Seite vollstaendig
 * bedienbar, dann eben ohne Auto-Aktualisierung. Wird der Tab verborgen,
 * pausiert die Abfrage; laufende Abfragen ueberlappen nicht.
 *
 * Aktualisiert werden nur Werte, Zustandsklassen und Texte – keine neuen
 * Knoten. Kommt ein Baustein spaeter hinzu (z. B. ein neuer Exchange-Host),
 * zeigt ihn erst ein Neuladen der Seite; die Aktualisierung ist damit
 * ausdruecklich eine Ergaenzung zum Seitenaufbau, kein Ersatz.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-orvanta-flow]');
    if (!root) {
        return;
    }

    var url = root.getAttribute('data-refresh-url');
    var interval = parseInt(root.getAttribute('data-refresh-interval'), 10);
    if (!url) {
        return;
    }
    if (!isFinite(interval) || interval < 5) {
        interval = 120;
    }
    interval = Math.min(600, interval) * 1000;

    var BADGE_CLASSES = ['badge--ok', 'badge--warn', 'badge--muted', 'badge--error'];
    var STATE_BADGE = {
        ok: 'badge--ok',
        warn: 'badge--warn',
        error: 'badge--error',
        off: 'badge--muted'
    };

    var head = root.querySelector('[data-flow-head]');
    var incidentCard = root.querySelector('[data-flow-incidents]');
    var incidentList = root.querySelector('[data-flow-incident-list]');
    var manual = root.querySelector('[data-flow-refresh]');

    var nodes = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-flow-node]'), function (node) {
        nodes[node.getAttribute('data-flow-node')] = node;
    });

    var edges = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-flow-edge]'), function (edge) {
        edges[edge.getAttribute('data-flow-edge')] = edge;
    });

    var timer = null;
    var running = false;

    function field(name) {
        return root.querySelector('[data-flow-field="' + name + '"]');
    }

    function setField(name, value) {
        var node = field(name);
        if (node) {
            node.textContent = value;
        }
    }

    function badgeFor(state) {
        return STATE_BADGE[state] || 'badge--muted';
    }

    function setBadge(badge, state, label) {
        if (!badge) {
            return;
        }
        badge.textContent = label;
        BADGE_CLASSES.forEach(function (name) {
            badge.classList.remove(name);
        });
        badge.classList.add(badgeFor(state));
    }

    function setOverall(overall) {
        if (!overall) {
            return;
        }
        var state = typeof overall.state === 'string' ? overall.state : 'off';
        setBadge(field('overall-label'), state, overall.label || '');
        setField('overall-message', overall.message || '');
        setField('overall-errors', String(overall.errors || 0));
        setField('overall-warnings', String(overall.warnings || 0));
        if (head) {
            ['ok', 'warn', 'error', 'off'].forEach(function (name) {
                head.classList.remove('flow-head--' + name);
            });
            head.classList.add('flow-head--' + state);
        }
    }

    function setKpis(kpis) {
        if (!Array.isArray(kpis)) {
            return;
        }
        kpis.forEach(function (kpi) {
            var key = typeof kpi.key === 'string' ? kpi.key : '';
            if (key === '') {
                return;
            }
            var value = root.querySelector('[data-flow-kpi="' + key + '"]');
            if (value) {
                value.textContent = kpi.value === undefined || kpi.value === null ? '' : String(kpi.value);
            }
            var hint = root.querySelector('[data-flow-kpi-hint="' + key + '"]');
            if (hint) {
                hint.textContent = kpi.hint === undefined || kpi.hint === null ? '' : String(kpi.hint);
            }
            var item = root.querySelector('[data-flow-kpi-item="' + key + '"]');
            if (item) {
                ['ok', 'warn', 'error', 'off'].forEach(function (name) {
                    item.classList.remove('flow-kpi--' + name);
                });
                item.classList.add('flow-kpi--' + (typeof kpi.state === 'string' ? kpi.state : 'off'));
            }
        });
    }

    function setNode(key, node) {
        var element = nodes[key];
        if (!element) {
            return;
        }
        var state = typeof node.state === 'string' ? node.state : 'off';
        element.setAttribute('data-flow-node-state', state);
        ['ok', 'warn', 'error', 'off'].forEach(function (name) {
            element.classList.remove('flow-node--' + name);
        });
        element.classList.add('flow-node--' + state);
        element.classList.toggle('flow-node--muted', node.muted === true);
        setBadge(element.querySelector('[data-flow-node-label]'), state, node.state_label || '');

        var message = element.querySelector('[data-flow-node-message]');
        if (message) {
            message.textContent = node.message || '';
            message.hidden = !node.message;
        }

        var muted = element.querySelector('[data-flow-node-muted]');
        if (muted) {
            muted.textContent = node.muted_label || '';
            muted.hidden = !node.muted_label;
        }

        var cloud = element.querySelector('.flow-cloud');
        if (cloud) {
            cloud.classList.toggle('cloud--muted', node.cloud_muted === true);
        }
    }

    function setLane(lane) {
        var element = root.querySelector('[data-flow-lane="' + lane.key + '"]');
        if (!element) {
            return;
        }
        setBadge(
            element.querySelector('[data-flow-lane-label]'),
            typeof lane.state === 'string' ? lane.state : 'off',
            lane.state_label || ''
        );
    }

    function setEdge(edge) {
        var key = String(edge.from || '') + '>' + String(edge.to || '');
        var element = edges[key];
        if (!element) {
            return;
        }
        ['ok', 'error'].forEach(function (name) {
            element.classList.remove('flow-edge--' + name);
        });
        element.classList.add('flow-edge--' + (edge.state === 'error' ? 'error' : 'ok'));
    }

    function clearChildren(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function incidentItem(incident) {
        var item = document.createElement('li');
        item.className = 'flow-incident';

        var alert = document.createElement('span');
        alert.className = 'flow-alert';
        alert.setAttribute('aria-hidden', 'true');
        alert.textContent = '!';
        item.appendChild(alert);

        var sr = document.createElement('span');
        sr.className = 'visually-hidden';
        sr.textContent = 'Störung';
        item.appendChild(sr);

        var title = document.createElement('strong');
        title.textContent = incident.title || '';
        item.appendChild(title);

        if (incident.message) {
            var message = document.createElement('span');
            message.className = 'flow-incident__message';
            message.textContent = incident.message;
            item.appendChild(message);
        }

        if (incident.url) {
            var link = document.createElement('a');
            link.href = incident.url;
            link.textContent = 'Beheben';
            item.appendChild(link);
        }

        return item;
    }

    function setIncidents(incidents) {
        if (!incidentCard || !incidentList) {
            return;
        }
        var list = Array.isArray(incidents) ? incidents : [];
        clearChildren(incidentList);
        list.forEach(function (incident) {
            incidentList.appendChild(incidentItem(incident));
        });
        incidentCard.hidden = list.length === 0;
    }

    function apply(data) {
        if (!data || typeof data !== 'object') {
            return;
        }
        setOverall(data.overall);
        setKpis(data.kpis);
        setIncidents(data.incidents);
        if (data.generated_at) {
            setField('generated', data.generated_at);
        }
        var nodesData = data.nodes || {};
        Object.keys(nodesData).forEach(function (key) {
            setNode(key, nodesData[key] || {});
        });
        if (Array.isArray(data.lanes)) {
            data.lanes.forEach(setLane);
        }
        if (Array.isArray(data.edges)) {
            data.edges.forEach(setEdge);
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
