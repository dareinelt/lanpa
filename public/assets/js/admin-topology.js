/*
 * Admin "System – Topologie": Gesamtansicht der Anwendung lanpa.
 *
 * Zeichnet Module, Dienste, Container, Netze, Speicherziele und externe
 * Systeme als verbundenes Netz auf ein Canvas: im Raum (3D, perspektivisch
 * projiziert) oder als flaches Netzdiagramm (2D); zwischen beiden Anordnungen
 * wird weich ueberblendet. Jede Modulgruppe bildet einen eigenen Cluster mit
 * Ring und Titel, Kindknoten liegen um ihren Elternknoten. Teilgraphen lassen
 * sich auf die Modulgruppen einklappen, ohne den Zusammenhang zur
 * Gesamtanwendung zu verlieren.
 *
 * Partikel wandern nur entlang echter Datenfluesse (Weiterleitung, Ablage,
 * Mailtransport, Anmeldung, Replikation). Abhaengigkeiten, Sicherungen,
 * Wiederherstellungen, Ueberwachungsabfragen und Enthaltensein sind bewusst
 * statisch: eine Ueberwachungsabfrage ist kein fachlicher Datenfluss.
 *
 * Zustaende: ok, warn, error, off, unknown, stale. Ein unbekannter oder
 * veralteter Wert wird nie als gesund gezeichnet; die Aussagekraft
 * (nachgewiesen, abgeleitet, vermutet, nicht ueberwacht) steht im Detail und
 * im Protokoll, damit die Aussage nicht allein von der Farbe abhaengt.
 *
 * Bedienung: Ziehen dreht (3D) bzw. verschiebt (2D), Umschalt+Ziehen oder
 * rechte/mittlere Maustaste verschiebt, Rad zoomt, Klick waehlt (Detailpanel),
 * Doppelklick zentriert. Tastatur: Pfeile, +/-, 0, F, R, P, L, G, Leertaste,
 * 2, 3, !, N, Shift+N, Enter, Escape.
 *
 * Daten: Startzustand aus <script type="application/json" data-topo-initial>,
 * danach dasselbe JSON von data-refresh-url. Knoten behalten bei der
 * Aktualisierung ihre Position; nur neue oder entfallene Knoten ordnen neu.
 * Im Hintergrund (document.hidden) pausieren Zeichnen und Abfrage.
 * prefers-reduced-motion schaltet Drehung und Partikel ab. Aufbau ausschliesslich
 * ueber createElement/textContent, keine Inline-Stile, kein innerHTML.
 *
 * Konzept: docs/admin-topologie-referenz.md
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-lanpa-topology]');
    if (!root) {
        return;
    }
    var canvas = root.querySelector('[data-topo-canvas]');
    var stage = root.querySelector('[data-topo-stage]');
    if (!canvas || !stage || !canvas.getContext) {
        return;
    }
    var ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }

    // ------------------------------------------------------------ Konstanten

    var STATES = ['ok', 'warn', 'error', 'off', 'unknown', 'stale'];
    var STATE_COLORS = {
        ok: '#2ad4a0', warn: '#ffc14d', error: '#ff4d6d',
        off: '#566072', unknown: '#9fb0c7', stale: '#c084fc'
    };
    var STATE_RGB = {
        ok: '42,212,160', warn: '255,193,77', error: '255,77,109',
        off: '86,96,114', unknown: '159,176,199', stale: '192,132,252'
    };
    var STATE_LABELS = {
        ok: 'In Ordnung', warn: 'Warnung', error: 'Fehler',
        off: 'Abgeschaltet', unknown: 'Unbekannt', stale: 'Veraltet'
    };
    var STATE_BADGE = {
        ok: 'badge--ok', warn: 'badge--warn', error: 'badge--error',
        off: 'badge--muted', unknown: 'badge--muted', stale: 'badge--warn'
    };
    /** Zustaende, die als Problem hervorgehoben werden (alles ausser ok/off). */
    var PROBLEM_STATES = { error: true, warn: true, unknown: true, stale: true };

    var KIND_COLORS = {
        core: '#58a6ff', users: '#f9fafb', external: '#f472b6', web: '#7dd3fc',
        identity: '#a78bfa', module: '#60a5fa', service: '#34d399', database: '#fbbf24',
        cache: '#fb923c', storage: '#22d3ee', volume: '#94a3b8', backup: '#4ade80',
        snapshot: '#2dd4bf', monitor: '#c084fc', mail: '#facc15', certificate: '#fda4af',
        network: '#64748b'
    };
    var KIND_RADIUS = {
        core: 34, users: 26, external: 18, web: 22, identity: 20, module: 24,
        service: 17, database: 18, cache: 15, storage: 16, volume: 10, backup: 17,
        snapshot: 15, monitor: 16, mail: 15, certificate: 13, network: 13
    };

    /** Kantenarten mit laufendem Datenfluss (Partikel). */
    var FLOW_TYPES = { routes: true, stores: true, mail: true, authenticates: true, replicates: true };
    /** Kantenarten ohne Datenfluss: statisch zeichnen. */
    var STATIC_TYPES = { depends: true, backs_up: true, restores: true, monitors: true, contains: true };

    var FOCAL = 1150;
    var MAX_PARTICLES_PER_EDGE = 12;
    var AMBIENT_PER_NODE = 8;
    var STARS = 200;
    var IDLE_BEFORE_ROTATE = 4000;
    var GROUP_RING_PAD = 96;
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ------------------------------------------------------------ Elemente

    var refreshUrl = root.getAttribute('data-refresh-url') || '';
    var refreshInterval = parseInt(root.getAttribute('data-refresh-interval'), 10);
    if (!isFinite(refreshInterval) || refreshInterval < 5) {
        refreshInterval = 120;
    }
    refreshInterval = Math.min(600, refreshInterval) * 1000;

    var tooltip = root.querySelector('[data-topo-tooltip]');
    var panel = root.querySelector('[data-topo-panel]');
    var logList = root.querySelector('[data-topo-log-list]');
    var logBox = root.querySelector('[data-topo-log]');
    var incidentsBox = root.querySelector('[data-topo-incidents]');
    var incidentList = root.querySelector('[data-topo-incident-list]');
    var incidentsEmpty = root.querySelector('[data-topo-incidents-empty]');
    var gapsBox = root.querySelector('[data-topo-gaps]');
    var gapList = root.querySelector('[data-topo-gap-list]');
    var gapsEmpty = root.querySelector('[data-topo-gaps-empty]');
    var staleBanner = root.querySelector('[data-topo-banner="stale"]');
    var hint = root.querySelector('[data-topo-hint]');
    var overallBox = root.querySelector('[data-topo-overall]');
    var overallPulse = root.querySelector('[data-topo-overall-pulse]');
    var kindFilter = root.querySelector('[data-topo-kind-filter]');
    var stateFilter = root.querySelector('[data-topo-state-filter]');
    var groupList = root.querySelector('.topo-groups__list');
    var draftBox = root.querySelector('[data-topo-draft]');
    var draftSourceLabel = root.querySelector('[data-topo-draft-source]');
    var draftStatus = root.querySelector('[data-topo-draft-status]');
    var menu = root.querySelector('[data-topo-menu]');
    var menuTitle = root.querySelector('[data-topo-menu-title]');
    var menuList = root.querySelector('[data-topo-menu-list]');
    var menuHint = root.querySelector('[data-topo-menu-hint]');
    var saveUrl = root.getAttribute('data-save-url') || '';
    var csrfToken = root.getAttribute('data-csrf') || '';
    var personalAvailable = root.getAttribute('data-personal-available') === '1';

    function field(name) {
        return root.querySelector('[data-topo-field="' + name + '"]');
    }

    function setField(name, value, bump) {
        var node = field(name);
        if (!node) {
            return;
        }
        var text = value === undefined || value === null ? '' : String(value);
        if (node.textContent === text) {
            return;
        }
        node.textContent = text;
        if (bump) {
            node.classList.remove('is-changed');
            void node.offsetWidth;
            node.classList.add('is-changed');
        }
    }

    function clearChildren(node) {
        while (node && node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function swapClass(node, prefix, value) {
        if (!node) {
            return;
        }
        STATES.forEach(function (name) {
            node.classList.remove(prefix + name);
        });
        node.classList.add(prefix + value);
    }

    function stateOf(value) {
        return STATES.indexOf(value) === -1 ? 'unknown' : value;
    }

    function timeNow() {
        var d = new Date();
        function two(n) { return (n < 10 ? '0' : '') + n; }
        return two(d.getHours()) + ':' + two(d.getMinutes()) + ':' + two(d.getSeconds());
    }

    // ------------------------------------------------------------ Zufall (deterministisch)

    function hashString(value) {
        var h = 2166136261;
        var text = String(value === undefined || value === null ? '' : value);
        for (var i = 0; i < text.length; i++) {
            h ^= text.charCodeAt(i);
            h = Math.imul(h, 16777619);
        }
        return h >>> 0;
    }

    function rng(seed) {
        var s = seed >>> 0 || 1;
        return function () {
            s ^= s << 13; s >>>= 0;
            s ^= s >>> 17;
            s ^= s << 5; s >>>= 0;
            return (s >>> 0) / 4294967296;
        };
    }

    // ------------------------------------------------------------ Modell

    var graph = {
        nodes: {}, order: [], edges: [], ambient: [],
        groups: {}, groupOrder: [], groupParent: {}
    };
    var stars = [];
    var effects = [];
    var view = {
        mode3d: true,
        mix: 1,               // 1 = 3D-Anordnung, 0 = 2D-Anordnung (weich ueberblendet)
        yaw: -0.35, pitch: 0.42, zoom: 1, panX: 0, panY: 0,
        tYaw: -0.35, tPitch: 0.42, tZoom: 1, tPanX: 0, tPanY: 0,
        rotate: !reducedMotion,
        particles: !reducedMotion,
        labels: true,
        focusProblems: false,
        collapse: false,
        focusGroup: null,
        hiddenKinds: {},
        hiddenStates: {},
        draft: false,
        hiddenNodes: {},
        hiddenGroups: {},
        selected: null,
        hovered: null,
        lastInteraction: 0,
        width: 0, height: 0, dpr: 1
    };
    var previous = null;
    var running = false;
    var timer = null;
    var rafId = null;
    var lastFrame = 0;
    var drawErrorLogged = false;
    /** Gespeicherte Auswahl des Entwurfsmodus (Herkunft und Kennungen). */
    var visibility = { source: 'default', nodes: [], groups: [] };
    /** Baustein, auf den das offene Kontextmenue sich bezieht. */
    var menuTarget = null;

    function parseInitial() {
        var holder = root.querySelector('[data-topo-initial]');
        if (!holder) {
            return null;
        }
        try {
            return JSON.parse(holder.textContent || 'null');
        } catch (error) {
            return null;
        }
    }

    function topGroupOf(groupId) {
        var guard = 0;
        var current = groupId;
        while (current && graph.groupParent[current] && guard < 16) {
            current = graph.groupParent[current];
            guard++;
        }
        return current || '';
    }

    function groupOfNode(node) {
        if (!node) {
            return '';
        }
        return topGroupOf(node.group || '');
    }

    function nodeHasChildren(key) {
        return graph.order.some(function (other) {
            return graph.nodes[other].parent === key;
        });
    }

    /**
     * Knoten, Kanten und Modulgruppen uebernehmen. Bestehende Knoten behalten
     * ihre Position, damit die Anzeige bei jeder Aktualisierung ruhig bleibt;
     * nur neue oder entfallene Knoten loesen eine Neuordnung aus.
     */
    function ingest(data) {
        if (!data || typeof data !== 'object') {
            return;
        }

        graph.groups = {};
        graph.groupOrder = [];
        graph.groupParent = {};
        (Array.isArray(data.groups) ? data.groups : []).forEach(function (raw) {
            var id = String(raw && raw.id ? raw.id : '');
            if (id === '') {
                return;
            }
            graph.groups[id] = {
                id: id,
                title: String(raw.title || id),
                subtitle: String(raw.subtitle || ''),
                parent: raw.parent ? String(raw.parent) : null,
                optional: raw.optional === true,
                nodeCount: parseInt(raw.node_count, 10) || 0,
                state: stateOf(raw.state),
                stateLabel: String(raw.state_label || ''),
                members: Array.isArray(raw.nodes) ? raw.nodes : []
            };
            graph.groupOrder.push(id);
            if (raw.parent) {
                graph.groupParent[id] = String(raw.parent);
            }
        });

        var incoming = data.nodes && typeof data.nodes === 'object' ? data.nodes : {};
        var seen = {};
        var layoutDirty = false;

        Object.keys(incoming).forEach(function (key) {
            var raw = incoming[key] || {};
            var kind = typeof raw.kind === 'string' ? raw.kind : 'module';
            var node = graph.nodes[key];
            if (!node) {
                node = {
                    key: key, pos3: { x: 0, y: 0, z: 0 }, pos2: { x: 0, y: 0 },
                    sx: 0, sy: 0, sr: 0, depth: 0, pulse: Math.random() * Math.PI * 2
                };
                graph.nodes[key] = node;
                graph.order.push(key);
                layoutDirty = true;
            }
            var group = String(raw.group || '');
            if (node.group !== undefined && node.group !== group) {
                layoutDirty = true;
            }
            if (node.parent !== undefined && String(node.parent || '') !== String(raw.parent || '')) {
                layoutDirty = true;
            }
            node.kind = KIND_RADIUS[kind] === undefined ? 'module' : kind;
            node.kindLabel = String(raw.kind_label || node.kind);
            node.group = group;
            node.topGroup = topGroupOf(group);
            node.parent = raw.parent ? String(raw.parent) : null;
            node.title = String(raw.title || key);
            node.subtitle = String(raw.subtitle || '');
            node.state = stateOf(raw.state);
            node.stateLabel = String(raw.state_label || STATE_LABELS[node.state]);
            node.message = String(raw.message || '');
            node.evidence = String(raw.evidence || 'suspected');
            node.evidenceLabel = String(raw.evidence_label || '');
            node.unwatched = raw.unwatched === true;
            node.optional = raw.optional === true;
            node.facts = Array.isArray(raw.facts) ? raw.facts : [];
            node.containers = Array.isArray(raw.containers) ? raw.containers : [];
            node.children = Array.isArray(raw.children) ? raw.children : [];
            node.link = typeof raw.link === 'string' ? raw.link : '';
            node.layer = parseInt(raw.layer, 10) || 4;
            node.severity = parseInt(raw.severity, 10) || 0;
            node.measuredAt = raw.measured_at === null || raw.measured_at === undefined ? null : parseInt(raw.measured_at, 10);
            seen[key] = true;
        });

        graph.order = graph.order.filter(function (key) {
            if (seen[key]) {
                return true;
            }
            delete graph.nodes[key];
            if (view.selected === key) {
                select(null);
            }
            layoutDirty = true;
            return false;
        });

        var edges = [];
        (Array.isArray(data.edges) ? data.edges : []).forEach(function (raw) {
            var from = String(raw && raw.from ? raw.from : '');
            var to = String(raw && raw.to ? raw.to : '');
            if (!graph.nodes[from] || !graph.nodes[to]) {
                return;
            }
            edges.push(makeEdge(raw, from, to));
        });

        var old = {};
        graph.edges.forEach(function (edge) {
            old[edge.id] = edge;
        });
        graph.edges = edges.map(function (edge) {
            var prior = old[edge.id];
            if (prior) {
                prior.state = edge.state;
                prior.type = edge.type;
                prior.typeLabel = edge.typeLabel;
                prior.evidence = edge.evidence;
                prior.evidenceLabel = edge.evidenceLabel;
                prior.protocol = edge.protocol;
                prior.label = edge.label;
                prior.message = edge.message;
                prior.flow = edge.flow;
                return prior;
            }
            return edge;
        });

        graph.edges.forEach(function (edge) {
            // Der Zustand einer Kante ist nie besser als der ihrer Endpunkte.
            // Ein abgeschalteter Endpunkt macht die Beziehung abgeschaltet; ein
            // gestoerter Endpunkt macht sie hoechstens so gut wie die Kante
            // selbst. Ein gesunder Endpunkt macht keine kranke Kante gesund.
            var from = graph.nodes[edge.from];
            var to = graph.nodes[edge.to];
            var drawState = edge.state;
            if (from.state === 'off' || to.state === 'off') {
                drawState = 'off';
            } else if (from.state === 'error' || to.state === 'error') {
                drawState = drawState === 'ok' ? 'warn' : drawState;
            } else if (from.state === 'unknown' || to.state === 'unknown' || from.state === 'stale' || to.state === 'stale') {
                drawState = drawState === 'ok' ? 'unknown' : drawState;
            } else if ((from.state === 'warn' || to.state === 'warn') && drawState === 'ok') {
                drawState = 'warn';
            }
            edge.drawState = stateOf(drawState);
            edge.activity = Math.min(from.activity, Math.max(1, to.activity));
            syncParticles(edge);
        });

        if (layoutDirty) {
            layout();
        }
        setField('node-count', graph.order.length, true);
    }

    function makeEdge(raw, from, to) {
        var id = String(raw.id || (from + '>' + to));
        var type = String(raw.type || 'depends');
        var seed = rng(hashString(id));
        var node = {
            id: id, from: from, to: to, type: type,
            typeLabel: String(raw.type_label || type),
            label: String(raw.label || ''),
            message: String(raw.message || ''),
            state: stateOf(raw.state),
            drawState: stateOf(raw.state),
            evidence: String(raw.evidence || 'suspected'),
            evidenceLabel: String(raw.evidence_label || ''),
            protocol: String(raw.protocol || ''),
            flow: FLOW_TYPES[type] === true,
            static: STATIC_TYPES[type] === true,
            bend: (seed() - 0.5) * 0.9,
            lift: (seed() - 0.5) * 110,
            particles: [], activity: 1
        };
        return node;
    }

    function syncParticles(edge) {
        var wanted = 0;
        if (edge.flow && !reducedMotion && view.particles && edge.drawState === 'ok') {
            wanted = Math.min(MAX_PARTICLES_PER_EDGE, 1 + Math.round(Math.log(edge.activity + 1) * 2.2));
        } else if (edge.flow && !reducedMotion && view.particles && edge.drawState === 'warn') {
            wanted = Math.min(6, 1 + Math.round(Math.log(edge.activity + 1)));
        } else if (edge.flow && !reducedMotion && view.particles && edge.drawState === 'error') {
            wanted = 4;
        }
        while (edge.particles.length < wanted) {
            edge.particles.push({ t: Math.random(), speed: 0.08 + Math.random() * 0.12, size: 1.4 + Math.random() * 1.8, phase: Math.random() });
        }
        if (edge.particles.length > wanted) {
            edge.particles.length = wanted;
        }
    }

    function activityOf(node) {
        if (node.state === 'off') {
            return 0;
        }
        var sum = 0;
        node.facts.forEach(function (fact) {
            var parsed = parseInt(String(fact && fact.value !== undefined ? fact.value : '').replace(/[^0-9-]/g, ''), 10);
            if (isFinite(parsed) && parsed > 0) {
                sum += Math.min(200, parsed);
            }
        });
        if (node.kind === 'core' || node.kind === 'users') {
            sum = Math.max(sum, 6);
        }
        return Math.max(node.state === 'error' ? 2 : 1, sum);
    }

    // ------------------------------------------------------------ Anordnung

    function byKind(kind) {
        return graph.order.filter(function (key) {
            return graph.nodes[key].kind === kind;
        });
    }

    function topGroupIds() {
        return graph.groupOrder.filter(function (id) {
            return !graph.groupParent[id];
        });
    }

    function membersOfGroup(groupId) {
        return graph.order.filter(function (key) {
            return graph.nodes[key].topGroup === groupId;
        });
    }

    function ringAround(keys, center, radius, seedKey, plane, yBias) {
        var n = keys.length;
        keys.forEach(function (key, i) {
            var node = graph.nodes[key];
            var r = rng(hashString(seedKey + key));
            var a = (i / Math.max(1, n)) * Math.PI * 2 + 0.3;
            var jitter = { x: (r() - 0.5) * 46, y: (r() - 0.5) * 34, z: (r() - 0.5) * 46 };
            var rad = n === 1 ? 0 : radius;
            if (plane === 'yz') {
                node.pos3 = {
                    x: center.x + jitter.x,
                    y: center.y + Math.cos(a) * rad + jitter.y + yBias,
                    z: center.z + Math.sin(a) * rad + jitter.z
                };
            } else {
                node.pos3 = {
                    x: center.x + Math.cos(a) * rad + jitter.x,
                    y: center.y + jitter.y + yBias,
                    z: center.z + Math.sin(a) * rad + jitter.z
                };
            }
        });
    }

    /**
     * Die Anwendung (Knoten der Art "core") ist das optische Zentrum.
     * 3D: lanpa liegt im Ursprung, die uebrigen Bausteine seiner Gruppe auf
     * einem engen Ring darum. Jede weitere oberste Modulgruppe bildet einen
     * Cluster auf einem grossen Ring um die Mitte; die Ebene (layer) verschiebt
     * einen Knoten nach oben oder unten, damit Zugriffe, Dienste und Speicher
     * unterscheidbar bleiben. Die Auto-Drehung kreist damit um lanpa.
     * 2D: lanpa in der mittleren Spalte, darueber der Zugriffsweg; die
     * Modulgruppen verteilen sich ausgewogen auf Spalten links und rechts.
     */
    function layout() {
        var coreKey = graph.order.filter(function (key) {
            return graph.nodes[key].kind === 'core';
        })[0] || null;
        var coreGroup = coreKey ? graph.nodes[coreKey].topGroup : null;
        var groups = topGroupIds().filter(function (groupId) {
            return groupId !== coreGroup;
        });
        var count = Math.max(1, groups.length);
        var groupRadius = Math.min(700, 320 + count * 34);

        if (coreKey) {
            var core = graph.nodes[coreKey];
            core.pos3 = { x: 0, y: 0, z: 0 };
            var around = membersOfGroup(coreGroup).filter(function (key) {
                return key !== coreKey;
            });
            ringAround(around, { x: 0, y: 0, z: 0 }, Math.min(230, 150 + around.length * 14), 'core:' + coreGroup, 'xz', 0);
        }

        groups.forEach(function (groupId, index) {
            var angle = (index / count) * Math.PI * 2;
            var center = {
                x: Math.cos(angle) * groupRadius,
                y: Math.sin(index * 1.7) * 90,
                z: Math.sin(angle) * groupRadius
            };
            var members = membersOfGroup(groupId);
            var roots = members.filter(function (key) {
                return !graph.nodes[key].parent;
            });
            var children = members.filter(function (key) {
                return !!graph.nodes[key].parent;
            });
            var radius = roots.length <= 1 ? 0 : Math.min(190, 62 + roots.length * 24);

            ringAround(roots, center, radius, 'g:' + groupId, 'xz', 0);
            roots.forEach(function (key) {
                var parent = graph.nodes[key];
                var kids = children.filter(function (childKey) {
                    return graph.nodes[childKey].parent === key;
                });
                var base = {
                    x: parent.pos3.x,
                    y: parent.pos3.y - 150,
                    z: parent.pos3.z
                };
                ringAround(kids, base, Math.min(150, 46 + kids.length * 24), 'c:' + key, 'xz', 0);
            });
            // Kinder ohne sichtbaren Elternknoten trotzdem im Cluster unterbringen.
            var orphans = children.filter(function (childKey) {
                return !graph.nodes[graph.nodes[childKey].parent];
            });
            if (orphans.length) {
                ringAround(orphans, { x: center.x, y: center.y - 150, z: center.z }, Math.min(160, 50 + orphans.length * 22), 'o:' + groupId, 'xz', 0);
            }
        });

        // Ebene als vertikale Feinkorrektur: Zugriff oben, Speicher unten.
        // lanpa selbst bleibt exakt im Ursprung.
        graph.order.forEach(function (key) {
            if (key === coreKey) {
                return;
            }
            var node = graph.nodes[key];
            node.pos3.y += (3 - node.layer) * 26;
        });

        layout2d(coreKey, coreGroup, groups);
        buildAmbient();
    }

    /**
     * 2D-Masse: Zeilenabstand in Weltkoordinaten; Gruppenkopf (Rahmen unten,
     * Ueberschrift, Rahmen oben) und Beschriftungsbreite in Bildschirmpunkten,
     * weil Schrift und Rahmenabstand nicht mitzoomen.
     */
    var ROW_GAP_2D = 56;
    var GROUP_HEAD_PX = 84;
    var LABEL_MAX_2D = 190;
    /** Tatsaechliche 2D-Beschriftungsbreite; schmaler, wenn die Buehne eng ist. */
    var labelMax2d = LABEL_MAX_2D;
    /** Platz ueber der obersten Zeile fuer Gruppentitel (Bildschirmpunkte, je Seite). */
    var TITLE_RESERVE_2D = 46;
    /** Hoechster Einpass-Zoom in 2D, damit Knoten die Nachbarspalten nicht ueberdecken. */
    var MAX_ZOOM_2D = 0.75;
    var MIN_ZOOM_2D = 0.12;

    /** Letzte 2D-Spaltenaufteilung; fit() passt Abstaende an den echten Zoom an. */
    var layout2dState = null;

    /**
     * 2D-Anordnung: Zugriffsweg und lanpa in der Mitte, Modulgruppen in
     * Spalten links und rechts. Die Gruppen werden der jeweils kuerzesten
     * Spalte zugeteilt (innen vor aussen), damit das Bild ausgewogen bleibt
     * und lanpa auch vertikal in der Mitte steht.
     */
    function layout2d(coreKey, coreGroup, groups) {
        var placeColumn = function (list, x, head) {
            var total = 0;
            list.forEach(function (groupId, i) {
                total += (i > 0 ? head : 0) + Math.max(0, membersOfGroup(groupId).length - 1) * ROW_GAP_2D;
            });
            var y = -total / 2;
            list.forEach(function (groupId, i) {
                if (i > 0) {
                    y += head;
                }
                membersOfGroup(groupId).forEach(function (key, j) {
                    if (j > 0) {
                        y += ROW_GAP_2D;
                    }
                    graph.nodes[key].pos2 = { x: x, y: y };
                });
            });
        };

        var coreRows = 0;
        if (coreKey) {
            var members = membersOfGroup(coreGroup);
            var at = members.indexOf(coreKey);
            members.forEach(function (key, i) {
                graph.nodes[key].pos2 = { x: 0, y: (i - at) * ROW_GAP_2D * 1.6 };
            });
            coreRows = 2 * Math.max(at, members.length - 1 - at) * 1.6;
        }

        var distribute = function (perSide, gap) {
            var columns = [];
            for (var c = 0; c < perSide; c++) {
                columns.push({ x: -(c + 1) * gap, list: [], rows: 0, heads: 0 });
                columns.push({ x: (c + 1) * gap, list: [], rows: 0, heads: 0 });
            }
            groups.forEach(function (groupId) {
                var size = membersOfGroup(groupId).length;
                var target = columns[0];
                columns.forEach(function (column) {
                    if (column.rows + column.heads < target.rows + target.heads - 0.01) {
                        target = column;
                    }
                });
                target.heads += target.list.length ? 1 : 0;
                target.rows += Math.max(0, size - 1);
                target.list.push(groupId);
            });
            return columns;
        };

        // Spaltenzahl und -abstand so waehlen, dass das Bild die Buehne am
        // besten ausfuellt. Gerechnet wird in Bildschirmpunkten mit denselben
        // Raendern wie fit(): Der Spaltenabstand ist eine feste Bildschirm-
        // breite, damit passt die Breite immer; die hoechste Spalte samt
        // Gruppenkoepfen bestimmt den Zoom.
        var area = freeArea();
        var halfW = Math.max(200, (area.right - area.left - 80) / 2);
        var availH = Math.max(240, area.bottom - area.top - 90 - 2 * TITLE_RESERVE_2D);
        var zoomFor = function (columns) {
            var zoom = coreRows > 0 ? availH / (coreRows * ROW_GAP_2D) : 1.4;
            columns.forEach(function (column) {
                var room = Math.max(availH * 0.25, availH - column.heads * GROUP_HEAD_PX);
                if (column.rows > 0) {
                    zoom = Math.min(zoom, room / (column.rows * ROW_GAP_2D));
                }
            });
            return Math.max(MIN_ZOOM_2D, Math.min(MAX_ZOOM_2D, zoom));
        };
        // Je Spaltenzahl die Beschriftungsbreite L so waehlen, dass die Breite
        // passt: halbe Breite = Ueberstand (L + 30) + Spalten x Abstand (L + 60).
        // Gekuerzte Beschriftungen werden gegenueber mehr Zoom deutlich abgewertet.
        var best = null;
        for (var perSide = 1; perSide <= 4; perSide++) {
            var label = Math.min(LABEL_MAX_2D, (halfW - 30 - 60 * perSide) / (perSide + 1));
            if (perSide > 1 && label < 80) {
                break;
            }
            label = Math.max(80, label);
            var zoom = zoomFor(distribute(perSide, 1));
            var score = zoom * (0.3 + 0.7 * label / LABEL_MAX_2D);
            if (best === null || score > best.score) {
                best = { zoom: zoom, score: score, perSide: perSide, gapPx: label + 60, label: label };
            }
        }
        labelMax2d = best.label;
        layout2dState = { zoom: 0, apply: function (zoom) {
            layout2dState.zoom = zoom;
            distribute(best.perSide, best.gapPx / zoom).forEach(function (column) {
                placeColumn(column.list, column.x, GROUP_HEAD_PX / zoom);
            });
        } };
        layout2dState.apply(best.zoom);
    }

    /** Schwebeteilchen um jeden Baustein (rein dekorativ). */
    function buildAmbient() {
        graph.ambient = [];
        graph.order.forEach(function (key) {
            var node = graph.nodes[key];
            var r = rng(hashString('amb' + key));
            var particles = node.kind === 'core' ? AMBIENT_PER_NODE * 2 : AMBIENT_PER_NODE;
            for (var i = 0; i < particles; i++) {
                var theta = r() * Math.PI * 2;
                var phi = Math.acos(2 * r() - 1);
                var dist = 30 + r() * 62;
                graph.ambient.push({
                    node: key,
                    dx: Math.sin(phi) * Math.cos(theta) * dist,
                    dy: Math.sin(phi) * Math.sin(theta) * dist,
                    dz: Math.cos(phi) * dist,
                    size: 0.6 + r() * 1.4,
                    phase: r() * Math.PI * 2
                });
            }
        });
    }

    function buildStars() {
        var r = rng(20260213);
        stars = [];
        for (var i = 0; i < STARS; i++) {
            stars.push({ x: (r() - 0.5) * 2800, y: (r() - 0.5) * 2000, z: (r() - 0.5) * 2400, size: 0.4 + r() * 1.3, tw: r() * Math.PI * 2 });
        }
    }

    // ------------------------------------------------------------ Sichtbarkeit

    function groupCollapsed(groupId) {
        return view.collapse && groupId !== '' && view.focusGroup !== groupId;
    }

    function isCollapsedGroup(groupId) {
        if (!view.collapse) {
            return false;
        }
        return view.focusGroup ? view.focusGroup !== groupId : true;
    }

    function isHidden(node) {
        if (!node) {
            return true;
        }
        if (isHiddenByUser(node)) {
            return true;
        }
        if (view.hiddenKinds[node.kind]) {
            return true;
        }
        if (view.hiddenStates[node.state]) {
            return true;
        }
        if (isCollapsedGroup(node.topGroup) && !isGroupAnchor(node)) {
            return true;
        }
        return false;
    }

    /**
     * Im Entwurfsmodus ausgeblendet. Im normalen Betrieb verschwindet der
     * Baustein; im Entwurfsmodus bleibt er blass sichtbar, damit er per
     * Rechtsklick wieder eingeblendet werden kann.
     */
    function isHiddenByUser(node) {
        if (!node) {
            return true;
        }
        return view.hiddenNodes[node.key] === true || view.hiddenGroups[node.topGroup] === true;
    }

    function isGhost(node) {
        return view.draft && isHiddenByUser(node);
    }

    /** Wird der Baustein ueberhaupt gezeichnet? */
    function isDrawn(node) {
        return !isHidden(node) || isGhost(node);
    }

    /** Bei eingeklappter Gruppe bleibt der wichtigste Knoten als Anker sichtbar. */
    function isGroupAnchor(node) {
        var members = membersOfGroup(node.topGroup);
        var worst = null;
        members.forEach(function (key) {
            var candidate = graph.nodes[key];
            if (worst === null || candidate.severity > worst.severity) {
                worst = candidate;
            }
        });
        return worst !== null && worst.key === node.key;
    }

    function visibleNodes() {
        return graph.order.filter(function (key) {
            return !isHidden(graph.nodes[key]);
        });
    }

    function isNeighbour(a, b) {
        for (var i = 0; i < graph.edges.length; i++) {
            var e = graph.edges[i];
            if ((e.from === a && e.to === b) || (e.from === b && e.to === a)) {
                return true;
            }
        }
        return false;
    }

    function nodeAlpha(node) {
        if (!isDrawn(node)) {
            return 0;
        }
        var alpha = isGhost(node) ? 0.16 : 1;
        if (view.focusProblems && !PROBLEM_STATES[node.state]) {
            alpha *= 0.16;
        }
        if (view.focusGroup && node.topGroup !== view.focusGroup) {
            alpha *= 0.35;
        }
        if (view.selected && view.selected !== node.key && !isNeighbour(view.selected, node.key)) {
            alpha *= 0.5;
        }
        return alpha;
    }

    function edgeAlpha(edge) {
        var from = graph.nodes[edge.from];
        var to = graph.nodes[edge.to];
        var alpha = Math.min(nodeAlpha(from), nodeAlpha(to));
        if (view.selected && edge.from !== view.selected && edge.to !== view.selected) {
            alpha *= 0.4;
        }
        if (view.focusProblems && !PROBLEM_STATES[edge.drawState]) {
            alpha *= 0.25;
        }
        return alpha;
    }

    // ------------------------------------------------------------ Projektion

    function project(p3, p2) {
        var mix = view.mix;
        var x = p3.x, y = p3.y, z = p3.z;
        var cy = Math.cos(view.yaw), sy = Math.sin(view.yaw);
        var cp = Math.cos(view.pitch), sp = Math.sin(view.pitch);
        var x1 = x * cy + z * sy;
        var z1 = -x * sy + z * cy;
        var y1 = y * cp - z1 * sp;
        var z2 = y * sp + z1 * cp;
        // Punkte hinter der Kamera (z2 >= FOCAL) wuerden einen negativen
        // Massstab liefern; Canvas wirft dann bei negativen Radien und die
        // Zeichenschleife bricht ab. Daher Abstand nach unten begrenzen.
        var behind = z2 >= FOCAL - 40;
        var scale3 = FOCAL / Math.max(40, FOCAL - z2);
        var px3 = x1 * scale3;
        var py3 = -y1 * scale3;
        var px = px3 * mix + (p2 ? p2.x : px3) * (1 - mix);
        var py = py3 * mix + (p2 ? p2.y : py3) * (1 - mix);
        var scale = scale3 * mix + (1 - mix);
        return {
            x: view.width / 2 + view.panX + px * view.zoom,
            y: view.height / 2 + view.panY + py * view.zoom,
            s: Math.max(0.0001, scale * view.zoom),
            depth: z2 * mix,
            behind: behind && mix > 0.5
        };
    }

    function resize() {
        var rect = stage.getBoundingClientRect();
        var dpr = Math.min(2, window.devicePixelRatio || 1);
        view.width = Math.max(1, Math.round(rect.width));
        view.height = Math.max(1, Math.round(rect.height));
        view.dpr = dpr;
        canvas.width = Math.round(view.width * dpr);
        canvas.height = Math.round(view.height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    // ------------------------------------------------------------ Zeichnen

    function lerp(a, b, t) {
        return a + (b - a) * t;
    }

    function controlPoint(edge, a, b) {
        var mx = (a.x + b.x) / 2;
        var my = (a.y + b.y) / 2;
        var mz = (a.z + b.z) / 2;
        var dx = b.x - a.x, dy = b.y - a.y, dz = b.z - a.z;
        var len = Math.sqrt(dx * dx + dy * dy + dz * dz) || 1;
        var nx = -dy / len, ny = dx / len;
        var bend = edge.static ? edge.bend * 0.4 : edge.bend;
        return { x: mx + nx * bend * len * 0.5, y: my + ny * bend * len * 0.5, z: mz + edge.lift * view.mix };
    }

    function controlPoint2(edge, a, b) {
        var mx = (a.x + b.x) / 2;
        var my = (a.y + b.y) / 2;
        var dx = b.x - a.x, dy = b.y - a.y;
        var len = Math.sqrt(dx * dx + dy * dy) || 1;
        var bend = edge.static ? edge.bend * 0.3 : edge.bend * 0.35;
        return { x: mx + (-dy / len) * bend * len, y: my + (dx / len) * bend * len };
    }

    function drawBackground(time) {
        ctx.clearRect(0, 0, view.width, view.height);
        var alphaBase = 0.2 + 0.4 * view.mix;
        for (var i = 0; i < stars.length; i++) {
            var s = stars[i];
            var p = project(s, { x: s.x * 0.4, y: s.y * 0.4 });
            if (p.x < -20 || p.y < -20 || p.x > view.width + 20 || p.y > view.height + 20) {
                continue;
            }
            var tw = 0.6 + 0.4 * Math.sin(time / 1400 + s.tw);
            ctx.fillStyle = 'rgba(200,214,232,' + (alphaBase * tw).toFixed(3) + ')';
            ctx.fillRect(p.x, p.y, s.size, s.size);
        }
    }

    function drawGroups() {
        var groups = topGroupIds();
        var titles = [];
        groups.forEach(function (groupId) {
            var group = graph.groups[groupId];
            if (!group) {
                return;
            }
            var members = membersOfGroup(groupId).filter(function (key) {
                // Ring nur zeichnen, wenn mindestens ein Baustein sichtbar ist.
                return isDrawn(graph.nodes[key]);
            });
            if (!members.length) {
                return;
            }
            var center3 = { x: 0, y: 0, z: 0 };
            var center2 = { x: 0, y: 0 };
            var radius3 = 0;
            var radius2 = 0;
            var count = 0;
            members.forEach(function (key) {
                var node = graph.nodes[key];
                center3.x += node.pos3.x; center3.y += node.pos3.y; center3.z += node.pos3.z;
                center2.x += node.pos2.x; center2.y += node.pos2.y;
                count++;
            });
            if (count === 0) {
                return;
            }
            center3.x /= count; center3.y /= count; center3.z /= count;
            center2.x /= count; center2.y /= count;
            members.forEach(function (key) {
                var node = graph.nodes[key];
                radius3 = Math.max(radius3, Math.sqrt(
                    Math.pow(node.pos3.x - center3.x, 2) + Math.pow(node.pos3.y - center3.y, 2) + Math.pow(node.pos3.z - center3.z, 2)
                ));
                radius2 = Math.max(radius2, Math.sqrt(
                    Math.pow(node.pos2.x - center2.x, 2) + Math.pow(node.pos2.y - center2.y, 2)
                ));
            });

            var collapsed = isCollapsedGroup(groupId);
            var focused = view.focusGroup === groupId;
            var color = STATE_RGB[group.state] || STATE_RGB.unknown;

            // In 2D umschliesst ein Rahmen die Spalte samt Beschriftungen, die
            // Ueberschrift steht darueber und ueberdeckt so keinen Baustein.
            if (view.mix < 0.5) {
                drawGroupFrame(group, members, collapsed, focused, color);
                return;
            }
            var p = project(center3, center2);
            if (p.behind) {
                return;
            }
            var ringScale = Math.max(0.0001, p.s);
            var radiusX = (radius3 + GROUP_RING_PAD) * ringScale * (1 - view.mix) * 0.0
                + (radius2 + GROUP_RING_PAD) * (1 - view.mix)
                + (radius3 + GROUP_RING_PAD) * ringScale * view.mix;
            var radiusY = radiusX * (0.42 + 0.58 * (1 - view.mix));

            ctx.save();
            ctx.beginPath();
            ctx.ellipse(p.x, p.y, Math.max(8, radiusX), Math.max(8, radiusY), 0, 0, Math.PI * 2);
            ctx.setLineDash(collapsed ? [6, 6] : [2, 6]);
            ctx.lineWidth = focused ? 2 : 1;
            ctx.strokeStyle = 'rgba(' + color + ',' + (focused ? 0.5 : collapsed ? 0.22 : 0.16).toFixed(3) + ')';
            ctx.stroke();
            ctx.setLineDash([]);
            if (collapsed) {
                ctx.fillStyle = 'rgba(' + color + ',0.05)';
                ctx.fill();
            }
            ctx.restore();

            if (!view.labels) {
                return;
            }
            var label = group.title + ' · ' + group.nodeCount;
            ctx.font = '600 12px system-ui, -apple-system, "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            var ty = p.y - Math.max(10, radiusY) - 10;
            var tw = ctx.measureText(label).width;
            // Gruppentitel, die sich ueberdecken wuerden, entfallen; der Titel
            // bleibt in der Gruppenliste unter der Buehne lesbar.
            var clash = titles.some(function (other) {
                return Math.abs(other.x - p.x) < (other.w + tw) / 2 + 8 && Math.abs(other.y - ty) < 16;
            });
            if (clash && !focused) {
                return;
            }
            titles.push({ x: p.x, y: ty, w: tw });
            ctx.fillStyle = 'rgba(203,213,225,' + (focused ? 0.95 : collapsed ? 0.8 : 0.55).toFixed(3) + ')';
            ctx.fillText(label, p.x, ty);
        });
    }

    /** 2D-Gruppenrahmen in Bildschirmpunkten (Bausteine samt Beschriftung). */
    function groupFrameRect(members) {
        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        ctx.save();
        ctx.font = '600 12px system-ui, -apple-system, "Segoe UI", sans-serif';
        members.forEach(function (key) {
            var node = graph.nodes[key];
            var p = project(node.pos3, node.pos2);
            var r = (KIND_RADIUS[node.kind] || 17) * p.s * 1.4;
            var labelWidth = view.labels ? Math.min(labelMax2d, ctx.measureText(node.title).width) + 24 : 0;
            minX = Math.min(minX, p.x - r);
            minY = Math.min(minY, p.y - r);
            maxX = Math.max(maxX, p.x + r + labelWidth);
            maxY = Math.max(maxY, p.y + r);
        });
        ctx.restore();
        var pad = 10;
        return { x: minX - pad, y: minY - pad, w: maxX - minX + pad * 2, h: maxY - minY + pad * 2 };
    }

    function drawGroupFrame(group, members, collapsed, focused, color) {
        var rect = groupFrameRect(members);
        var x = rect.x, y = rect.y, w = rect.w, h = rect.h;
        ctx.save();
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(x, y, w, h, 10);
        } else {
            ctx.rect(x, y, w, h);
        }
        ctx.setLineDash(collapsed ? [6, 6] : [2, 6]);
        ctx.lineWidth = focused ? 2 : 1;
        ctx.strokeStyle = 'rgba(' + color + ',' + (focused ? 0.5 : collapsed ? 0.26 : 0.22).toFixed(3) + ')';
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = 'rgba(' + color + ',' + (collapsed ? 0.05 : 0.025) + ')';
        ctx.fill();
        if (view.labels) {
            ctx.font = '600 12px system-ui, -apple-system, "Segoe UI", sans-serif';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'bottom';
            ctx.fillStyle = 'rgba(203,213,225,' + (focused ? 0.95 : 0.7).toFixed(3) + ')';
            // Titel nicht breiter als der Rahmen, sonst ueberdeckt er die Nachbarspalte.
            var count = ' · ' + group.nodeCount;
            var room = Math.max(40, w - 4 - ctx.measureText(count).width);
            ctx.fillText(truncate(group.title, room) + count, x + 2, y - 4);
        }
        ctx.restore();
    }

    function edgePath(edge) {
        var from = graph.nodes[edge.from];
        var to = graph.nodes[edge.to];
        var a = project(from.pos3, from.pos2);
        var b = project(to.pos3, to.pos2);
        if (a.behind || b.behind) {
            return null;
        }
        var c3 = controlPoint(edge, from.pos3, to.pos3);
        var c2 = controlPoint2(edge, from.pos2, to.pos2);
        var c = project(c3, c2);
        return { a: a, b: b, c: c, mid: bezier(a, c, b, 0.5) };
    }

    function bezier(p0, p1, p2, t) {
        var u = 1 - t;
        return { x: u * u * p0.x + 2 * u * t * p1.x + t * t * p2.x, y: u * u * p0.y + 2 * u * t * p1.y + t * t * p2.y };
    }

    function drawEdges() {
        graph.edges.forEach(function (edge) {
            var alpha = edgeAlpha(edge);
            if (alpha <= 0.02) {
                return;
            }
            var path = edgePath(edge);
            if (!path) {
                return;
            }
            var color = STATE_RGB[edge.drawState] || STATE_RGB.unknown;
            ctx.save();
            ctx.beginPath();
            ctx.moveTo(path.a.x, path.a.y);
            ctx.quadraticCurveTo(path.c.x, path.c.y, path.b.x, path.b.y);
            if (edge.static) {
                ctx.setLineDash(edge.type === 'contains' ? [1, 6] : edge.type === 'monitors' ? [2, 7] : [5, 5]);
            } else if (edge.evidence === 'suspected') {
                ctx.setLineDash([7, 5]);
            }
            ctx.lineWidth = edge.flow ? 1.6 : 1;
            ctx.strokeStyle = 'rgba(' + color + ',' + (alpha * (edge.flow ? 0.5 : 0.34)).toFixed(3) + ')';
            ctx.stroke();
            ctx.restore();
        });
    }

    function drawParticles() {
        if (!view.particles || reducedMotion) {
            return;
        }
        graph.edges.forEach(function (edge) {
            if (!edge.particles.length) {
                return;
            }
            var alpha = edgeAlpha(edge);
            if (alpha <= 0.05) {
                return;
            }
            var path = edgePath(edge);
            if (!path) {
                return;
            }
            var color = STATE_RGB[edge.drawState] || STATE_RGB.unknown;
            var stopsAt = edge.drawState === 'ok' ? 1 : edge.drawState === 'warn' ? 0.7 : 0.45;
            edge.particles.forEach(function (particle) {
                var t = particle.t % 1;
                if (t > stopsAt) {
                    return;
                }
                var pos = bezier(path.a, path.c, path.b, t);
                var fade = t > stopsAt - 0.1 ? 0.3 : 1;
                ctx.beginPath();
                ctx.arc(pos.x, pos.y, particle.size * Math.max(0.4, path.a.s), 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(' + color + ',' + (alpha * 0.85 * fade).toFixed(3) + ')';
                ctx.fill();
            });
        });
    }

    function drawAmbient(time) {
        graph.ambient.forEach(function (ambient) {
            var node = graph.nodes[ambient.node];
            if (!node) {
                return;
            }
            var alpha = nodeAlpha(node);
            if (alpha <= 0.05) {
                return;
            }
            var wobble = Math.sin(time / 900 + ambient.phase) * 4;
            var p = project(
                { x: node.pos3.x + ambient.dx + wobble, y: node.pos3.y + ambient.dy, z: node.pos3.z + ambient.dz },
                { x: node.pos2.x + ambient.dx * 0.4 + wobble, y: node.pos2.y + ambient.dy * 0.4 }
            );
            if (p.behind) {
                return;
            }
            var color = STATE_RGB[node.state] || STATE_RGB.unknown;
            ctx.beginPath();
            ctx.arc(p.x, p.y, Math.max(0.3, ambient.size * p.s), 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(' + color + ',' + (alpha * 0.28).toFixed(3) + ')';
            ctx.fill();
        });
    }

    function drawGlyph(kind, x, y, r, color, alpha) {
        var fill = 'rgba(' + color + ',' + alpha.toFixed(3) + ')';
        ctx.save();
        ctx.beginPath();
        if (kind === 'volume' || kind === 'database' || kind === 'storage') {
            // Zylinder fuer Speicher
            ctx.ellipse(x, y - r * 0.5, r * 0.82, r * 0.34, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.rect(x - r * 0.82, y - r * 0.5, r * 1.64, r);
            ctx.fill();
            ctx.beginPath();
            ctx.ellipse(x, y + r * 0.5, r * 0.82, r * 0.34, 0, 0, Math.PI * 2);
            ctx.fill();
        } else if (kind === 'network') {
            ctx.arc(x, y, r, 0, Math.PI * 2);
            ctx.strokeStyle = fill;
            ctx.lineWidth = Math.max(1, r * 0.32);
            ctx.stroke();
        } else if (kind === 'certificate' || kind === 'external') {
            // Raute
            ctx.moveTo(x, y - r);
            ctx.lineTo(x + r, y);
            ctx.lineTo(x, y + r);
            ctx.lineTo(x - r, y);
            ctx.closePath();
            ctx.fill();
        } else if (kind === 'module' || kind === 'monitor' || kind === 'backup' || kind === 'snapshot') {
            // Sechseck
            for (var i = 0; i < 6; i++) {
                var a = (i / 6) * Math.PI * 2 + Math.PI / 6;
                var px = x + Math.cos(a) * r;
                var py = y + Math.sin(a) * r;
                if (i === 0) { ctx.moveTo(px, py); } else { ctx.lineTo(px, py); }
            }
            ctx.closePath();
            ctx.fill();
        } else if (kind === 'users' || kind === 'web') {
            // Quadrat mit runden Ecken
            var s = r * 0.88;
            ctx.moveTo(x - s, y - s + s * 0.4);
            ctx.arcTo(x - s, y - s, x + s, y - s, s * 0.4);
            ctx.arcTo(x + s, y - s, x + s, y + s, s * 0.4);
            ctx.arcTo(x + s, y + s, x - s, y + s, s * 0.4);
            ctx.arcTo(x - s, y + s, x - s, y - s, s * 0.4);
            ctx.closePath();
            ctx.fill();
        } else {
            ctx.arc(x, y, r, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.restore();
    }

    function drawNodes(time) {
        var labels = [];
        var order = graph.order.slice().sort(function (a, b) {
            return graph.nodes[a].depth - graph.nodes[b].depth;
        });
        order.forEach(function (key) {
            var node = graph.nodes[key];
            var alpha = nodeAlpha(node);
            if (alpha <= 0.02) {
                return;
            }
            var p = project(node.pos3, node.pos2);
            if (p.behind) {
                return;
            }
            node.sx = p.x;
            node.sy = p.y;
            var radius = (KIND_RADIUS[node.kind] || 17) * p.s;
            if (radius < 0.6) {
                return;
            }
            node.sr = radius;
            node.depth = p.depth;
            var color = STATE_RGB[node.state] || STATE_RGB.unknown;
            var kindColor = KIND_COLORS[node.kind] || '#94a3b8';
            var isSelected = view.selected === key;
            var isHovered = view.hovered === key;

            // Störungen pulsieren, Warnungen atmen.
            if (node.state === 'error') {
                var beat = 1 + 0.16 * Math.sin(time / 260 + node.pulse);
                radius *= beat;
            } else if (node.state === 'warn' || node.state === 'stale') {
                radius *= 1 + 0.06 * Math.sin(time / 620 + node.pulse);
            }

            ctx.save();
            // Halo
            var halo = ctx.createRadialGradient(p.x, p.y, radius * 0.4, p.x, p.y, radius * 2.6);
            halo.addColorStop(0, 'rgba(' + color + ',' + (alpha * 0.28).toFixed(3) + ')');
            halo.addColorStop(1, 'rgba(' + color + ',0)');
            ctx.fillStyle = halo;
            ctx.beginPath();
            ctx.arc(p.x, p.y, radius * 2.6, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();

            // Kern: Form nach Art, Farbe nach Zustand.
            ctx.save();
            ctx.globalAlpha = alpha;
            drawGlyph(node.kind, p.x, p.y, radius, STATE_RGB[node.state] || STATE_RGB.unknown, 0.92);
            ctx.restore();

            // Rand in der Arteigenfarbe; abgeschaltete Knoten gestrichelt.
            ctx.save();
            ctx.globalAlpha = alpha;
            ctx.beginPath();
            ctx.arc(p.x, p.y, radius * 1.35, 0, Math.PI * 2);
            ctx.lineWidth = isSelected || isHovered ? 2.4 : 1.2;
            ctx.strokeStyle = isSelected ? '#ffffff' : kindColor;
            if (isGhost(node)) {
                // Im Entwurfsmodus ausgeblendet: blass und gestrichelt, damit der
                // Baustein per Rechtsklick wieder eingeblendet werden kann.
                ctx.setLineDash([2, 5]);
            } else if (node.unwatched) {
                ctx.setLineDash([3, 4]);
            } else if (node.optional) {
                ctx.setLineDash([6, 4]);
            }
            ctx.stroke();
            ctx.restore();

            if (view.labels && (radius > 3.5 || isSelected || isHovered)) {
                var text = node.title;
                if (node.state === 'unknown' || node.state === 'stale') {
                    text += ' (' + STATE_LABELS[node.state] + ')';
                }
                labels.push({
                    text: text,
                    x: p.x,
                    y: p.y,
                    radius: radius,
                    alpha: Math.min(1, alpha + 0.1),
                    core: node.kind === 'core',
                    force: isSelected || isHovered,
                    priority: (isSelected ? 1000 : 0) + (isHovered ? 900 : 0) + (node.kind === 'core' ? 800 : 0)
                        + (PROBLEM_STATES[node.state] ? 400 : 0) + radius * 4 + alpha * 50
                });
            }
        });
        drawLabels(labels);
    }

    /**
     * Beschriftungen nach Wichtigkeit setzen und Ueberlagerungen vermeiden:
     * Auswahl, lanpa und Stoerungen zuerst, danach grosse (nahe) Bausteine.
     * Eine Beschriftung, die eine bereits gesetzte ueberdecken wuerde, entfaellt
     * – der Name erscheint dann beim Ueberfahren oder nach dem Heranzoomen.
     * In 2D steht die Beschriftung rechts neben dem Baustein, in 3D darunter.
     */
    function truncate(text, max) {
        if (ctx.measureText(text).width <= max) {
            return text;
        }
        var cut = text;
        while (cut.length > 1 && ctx.measureText(cut + '…').width > max) {
            cut = cut.slice(0, -1);
        }
        return cut.replace(/\s+$/, '') + '…';
    }

    function drawLabels(labels) {
        var flat = view.mix < 0.5;
        var placed = [];
        labels.sort(function (a, b) {
            return b.priority - a.priority;
        });
        labels.forEach(function (label) {
            ctx.font = (label.core ? '700 13px ' : '600 12px ') + 'system-ui, -apple-system, "Segoe UI", sans-serif';
            // In 2D stehen Spalten nebeneinander: lange Namen werden gekuerzt,
            // der volle Name steht im Tooltip und in der Detailtafel.
            if (flat && !label.force) {
                label.text = truncate(label.text, labelMax2d);
            }
            var width = ctx.measureText(label.text).width + 8;
            var box = flat
                ? { x: label.x + label.radius * 1.5 + 4, y: label.y - 8, w: width, h: 16 }
                : { x: label.x - width / 2, y: label.y + label.radius * 1.5 + 3, w: width, h: 16 };
            var clash = placed.some(function (other) {
                return box.x < other.x + other.w && other.x < box.x + box.w && box.y < other.y + other.h && other.y < box.y + box.h;
            });
            if (clash && !label.force) {
                return;
            }
            placed.push(box);
            ctx.save();
            ctx.globalAlpha = label.alpha;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = 'rgba(9,12,20,0.62)';
            ctx.fillRect(box.x, box.y, box.w, box.h);
            ctx.fillStyle = 'rgba(226,232,240,0.94)';
            ctx.fillText(label.text, box.x + 4, box.y + box.h / 2 + 0.5);
            ctx.restore();
        });
    }

    function drawVignette() {
        var gradient = ctx.createRadialGradient(
            view.width / 2, view.height / 2, Math.min(view.width, view.height) * 0.35,
            view.width / 2, view.height / 2, Math.max(view.width, view.height) * 0.72
        );
        gradient.addColorStop(0, 'rgba(0,0,0,0)');
        gradient.addColorStop(1, 'rgba(0,0,0,0.35)');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, view.width, view.height);
    }

    function drawEffects(time) {
        if (!effects.length) {
            return;
        }
        var remaining = [];
        effects.forEach(function (effect) {
            var age = time - effect.start;
            if (age < 0) {
                remaining.push(effect);
                return;
            }
            if (age > effect.duration) {
                return;
            }
            var node = graph.nodes[effect.key];
            if (!node || isHidden(node)) {
                return;
            }
            var t = age / effect.duration;
            var p = project(node.pos3, node.pos2);
            var radius = (KIND_RADIUS[node.kind] || 17) * p.s * (1 + t * 4);
            ctx.beginPath();
            ctx.arc(p.x, p.y, Math.max(1, radius), 0, Math.PI * 2);
            ctx.lineWidth = 2 * (1 - t);
            ctx.strokeStyle = 'rgba(' + (STATE_RGB[effect.state] || STATE_RGB.unknown) + ',' + (0.7 * (1 - t)).toFixed(3) + ')';
            ctx.stroke();
            remaining.push(effect);
        });
        effects = remaining;
    }

    function frame(time) {
        rafId = null;
        if (!running) {
            return;
        }
        if (document.hidden) {
            lastFrame = time;
            schedule();
            return;
        }

        var dt = lastFrame ? Math.min(64, time - lastFrame) : 16;
        lastFrame = time;

        // Weiche Ueberblendung, Traegheit der Kamera, Auto-Drehung.
        view.mix = lerp(view.mix, view.mode3d ? 1 : 0, Math.min(1, dt / 220));
        view.yaw = lerp(view.yaw, view.tYaw, Math.min(1, dt / 160));
        view.pitch = lerp(view.pitch, view.tPitch, Math.min(1, dt / 160));
        view.zoom = lerp(view.zoom, view.tZoom, Math.min(1, dt / 160));
        view.panX = lerp(view.panX, view.tPanX, Math.min(1, dt / 160));
        view.panY = lerp(view.panY, view.tPanY, Math.min(1, dt / 160));

        if (view.rotate && !reducedMotion && time - view.lastInteraction > IDLE_BEFORE_ROTATE) {
            view.tYaw += dt * 0.00006;
            if (view.tYaw > Math.PI * 2) { view.tYaw -= Math.PI * 2; }
        }

        graph.edges.forEach(function (edge) {
            edge.particles.forEach(function (particle) {
                particle.t += particle.speed * (dt / 1000) * 1.6;
                if (particle.t > 1.4) {
                    particle.t -= 1.4;
                }
            });
        });

        try {
            drawBackground(time);
            drawGroups();
            drawEdges();
            drawParticles();
            drawAmbient(time);
            drawNodes(time);
            drawEffects(time);
            drawVignette();
        } catch (error) {
            ctx.setLineDash([]);
            ctx.globalAlpha = 1;
            if (!drawErrorLogged) {
                drawErrorLogged = true;
                log('warn', 'Die Darstellung wurde zurückgesetzt (Zeichenfehler).');
            }
        }

        schedule();
    }

    function schedule() {
        if (rafId === null && running) {
            rafId = window.requestAnimationFrame(frame);
        }
    }

    function start() {
        if (running) {
            return;
        }
        running = true;
        lastFrame = 0;
        schedule();
    }

    function stop() {
        running = false;
        if (rafId !== null) {
            window.cancelAnimationFrame(rafId);
            rafId = null;
        }
    }

    // ------------------------------------------------------------ Interaktion

    function localPoint(event) {
        var rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    }

    function hitTest(point) {
        var best = null;
        var bestDistance = Infinity;
        graph.order.forEach(function (key) {
            var node = graph.nodes[key];
            if (!isDrawn(node) || node.sr <= 0) {
                return;
            }
            var dx = point.x - node.sx;
            var dy = point.y - node.sy;
            var distance = Math.sqrt(dx * dx + dy * dy);
            var limit = Math.max(10, node.sr * 1.5);
            if (distance <= limit && distance < bestDistance) {
                bestDistance = distance;
                best = key;
            }
        });
        return best;
    }

    /**
     * Gruppe unter dem Zeiger ermitteln (Ring bzw. Beschriftung). Wird fuer das
     * Kontextmenue im Entwurfsmodus gebraucht.
     */
    function groupAt(point) {
        var best = null;
        var bestRadius = Infinity;
        // In 2D zaehlt der gezeichnete Rahmen samt Ueberschrift darueber.
        if (view.mix < 0.5) {
            topGroupIds().forEach(function (groupId) {
                var members = membersOfGroup(groupId).filter(function (key) {
                    return isDrawn(graph.nodes[key]);
                });
                if (!members.length) {
                    return;
                }
                var rect = groupFrameRect(members);
                if (point.x >= rect.x && point.x <= rect.x + rect.w
                    && point.y >= rect.y - 22 && point.y <= rect.y + rect.h
                    && rect.w * rect.h < bestRadius) {
                    bestRadius = rect.w * rect.h;
                    best = groupId;
                }
            });
            return best;
        }
        topGroupIds().forEach(function (groupId) {
            var circle = groupCircle(groupId);
            if (!circle) {
                return;
            }
            var dx = (point.x - circle.cx) / Math.max(8, circle.radiusX);
            var dy = (point.y - circle.cy) / Math.max(8, circle.radiusY);
            var distance = Math.sqrt(dx * dx + dy * dy);
            if (distance > 1.12) {
                return;
            }
            var labelDistance = Math.abs(point.x - circle.cx) <= 90
                && point.y >= circle.cy - circle.radiusY - 24
                && point.y <= circle.cy - circle.radiusY - 2;
            if (distance > 1 && !labelDistance) {
                return;
            }
            if (circle.radiusX < bestRadius) {
                bestRadius = circle.radiusX;
                best = groupId;
            }
        });
        return best;
    }

    /** Projizierter Ringmittelpunkt und -radien einer Gruppe. */
    function groupCircle(groupId) {
        var group = graph.groups[groupId];
        if (!group) {
            return null;
        }
        var members = membersOfGroup(groupId);
        if (!members.length) {
            return null;
        }
        var center3 = { x: 0, y: 0, z: 0 };
        var center2 = { x: 0, y: 0 };
        var radius3 = 0;
        var radius2 = 0;
        var count = 0;
        members.forEach(function (key) {
            var node = graph.nodes[key];
            if (!node) {
                return;
            }
            center3.x += node.pos3.x; center3.y += node.pos3.y; center3.z += node.pos3.z;
            center2.x += node.pos2.x; center2.y += node.pos2.y;
            count++;
        });
        if (count === 0) {
            return null;
        }
        center3.x /= count; center3.y /= count; center3.z /= count;
        center2.x /= count; center2.y /= count;
        members.forEach(function (key) {
            var node = graph.nodes[key];
            if (!node) {
                return;
            }
            radius3 = Math.max(radius3, Math.sqrt(
                Math.pow(node.pos3.x - center3.x, 2) + Math.pow(node.pos3.y - center3.y, 2) + Math.pow(node.pos3.z - center3.z, 2)
            ));
            radius2 = Math.max(radius2, Math.sqrt(
                Math.pow(node.pos2.x - center2.x, 2) + Math.pow(node.pos2.y - center2.y, 2)
            ));
        });
        var p = project(center3, center2);
        if (p.behind) {
            return null;
        }
        var ringScale = Math.max(0.0001, p.s);
        var radiusX = (radius2 + GROUP_RING_PAD) * (1 - view.mix)
            + (radius3 + GROUP_RING_PAD) * ringScale * view.mix;
        return {
            cx: p.x,
            cy: p.y,
            radiusX: Math.max(8, radiusX),
            radiusY: Math.max(8, radiusX * (0.42 + 0.58 * (1 - view.mix)))
        };
    }

    function showTooltip(key, point) {
        if (!tooltip || !key) {
            return;
        }
        // Das offene Kontextmenue nicht ueberdecken.
        if (menuOpen()) {
            hideTooltip();
            return;
        }
        var node = graph.nodes[key];
        if (!node) {
            return;
        }
        var title = tooltip.querySelector('[data-topo-tooltip-title]');
        var state = tooltip.querySelector('[data-topo-tooltip-state]');
        var message = tooltip.querySelector('[data-topo-tooltip-message]');
        if (title) { title.textContent = node.title; }
        if (state) {
            state.textContent = node.stateLabel + (node.evidenceLabel ? ' · ' + node.evidenceLabel : '');
            swapClass(state, 'topo-tooltip__state--', node.state);
        }
        if (message) { message.textContent = node.message || ''; }
        tooltip.hidden = false;
        tooltip.setAttribute('aria-hidden', 'false');
        var x = Math.min(view.width - 280, Math.max(8, point.x + 16));
        var y = Math.min(view.height - 90, Math.max(8, point.y + 16));
        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    }

    function hideTooltip() {
        if (!tooltip) {
            return;
        }
        tooltip.hidden = true;
        tooltip.setAttribute('aria-hidden', 'true');
    }

    function select(key) {
        view.selected = key;
        if (!panel) {
            return;
        }
        if (!key || !graph.nodes[key]) {
            panel.hidden = true;
            return;
        }
        var node = graph.nodes[key];
        var kind = panel.querySelector('[data-topo-panel-kind]');
        var title = panel.querySelector('[data-topo-panel-title]');
        var badge = panel.querySelector('[data-topo-panel-badge]');
        var subtitle = panel.querySelector('[data-topo-panel-subtitle]');
        var message = panel.querySelector('[data-topo-panel-message]');
        var muted = panel.querySelector('[data-topo-panel-muted]');
        var facts = panel.querySelector('[data-topo-panel-facts]');
        var cloud = panel.querySelector('[data-topo-panel-cloud]');
        var cloudList = panel.querySelector('[data-topo-panel-cloud-list]');
        var cloudTitle = panel.querySelector('[data-topo-panel-cloud-title]');
        var members = panel.querySelector('[data-topo-panel-members]');
        var membersList = panel.querySelector('[data-topo-panel-members-list]');
        var neighbours = panel.querySelector('[data-topo-panel-neighbours]');
        var link = panel.querySelector('[data-topo-panel-link]');

        if (kind) { kind.textContent = node.kindLabel || node.kind; }
        if (title) { title.textContent = node.title; }
        if (badge) {
            badge.textContent = node.stateLabel;
            swapClass(badge, 'badge--', node.state);
        }
        if (subtitle) { subtitle.textContent = node.subtitle || ''; }
        if (message) {
            message.textContent = node.message || '';
            message.hidden = !node.message;
        }
        if (muted) {
            var parts = [];
            if (node.evidenceLabel) { parts.push('Aussagekraft: ' + node.evidenceLabel); }
            if (node.measuredAt) {
                parts.push('Messung: ' + new Date(node.measuredAt * 1000).toLocaleString('de-DE'));
            } else {
                parts.push('Keine eigene Messung vorhanden');
            }
            if (node.optional) { parts.push('optionaler Baustein'); }
            muted.textContent = parts.join(' · ');
            muted.hidden = false;
        }

        if (facts) {
            clearChildren(facts);
            node.facts.forEach(function (fact) {
                if (!fact || fact.label === undefined) {
                    return;
                }
                facts.appendChild(el('dt', null, fact.label));
                facts.appendChild(el('dd', null, fact.value));
            });
            facts.hidden = !node.facts.length;
        }

        if (cloud && cloudList) {
            clearChildren(cloudList);
            node.containers.forEach(function (name) {
                cloudList.appendChild(el('li', null, name));
            });
            if (cloudTitle) {
                cloudTitle.textContent = 'Container';
            }
            cloud.hidden = !node.containers.length;
        }

        if (members && membersList) {
            clearChildren(membersList);
            var childKeys = node.children.slice();
            graph.order.forEach(function (other) {
                if (graph.nodes[other].parent === key && childKeys.indexOf(other) === -1) {
                    childKeys.push(other);
                }
            });
            childKeys.forEach(function (childKey) {
                var child = graph.nodes[childKey];
                if (!child) {
                    return;
                }
                var item = el('li');
                var button = el('button', 'topo-link', child.title);
                button.type = 'button';
                button.setAttribute('data-topo-focus', childKey);
                item.appendChild(button);
                item.appendChild(el('span', 'topo-link__state', child.stateLabel));
                membersList.appendChild(item);
            });
            members.hidden = !childKeys.length;
        }

        if (neighbours) {
            clearChildren(neighbours);
            graph.edges.forEach(function (edge) {
                var outgoing = edge.from === key;
                if (!outgoing && edge.to !== key) {
                    return;
                }
                var otherKey = outgoing ? edge.to : edge.from;
                var other = graph.nodes[otherKey];
                if (!other) {
                    return;
                }
                var item = el('li');
                var button = el('button', 'topo-link', (outgoing ? '→ ' : '← ') + other.title);
                button.type = 'button';
                button.setAttribute('data-topo-focus', otherKey);
                item.appendChild(button);
                item.appendChild(el('span', 'topo-link__meta', edge.typeLabel + ' · ' + STATE_LABELS[edge.drawState]));
                if (edge.protocol) {
                    item.appendChild(el('span', 'topo-link__meta', edge.protocol));
                }
                if (edge.evidenceLabel) {
                    item.appendChild(el('span', 'topo-link__meta', edge.evidenceLabel));
                }
                if (edge.message) {
                    item.appendChild(el('span', 'topo-link__meta', edge.message));
                }
                neighbours.appendChild(item);
            });
        }

        if (link) {
            if (node.link && /^\/[^\/\\]/.test(node.link)) {
                link.setAttribute('href', node.link);
                link.hidden = false;
            } else {
                link.removeAttribute('href');
                link.hidden = true;
            }
        }

        panel.hidden = false;
    }

    function centerOn(key) {
        var node = graph.nodes[key];
        if (!node) {
            return;
        }
        view.tPanX = -((node.pos3.x * view.mix + node.pos2.x * (1 - view.mix)) * view.zoom);
        view.tPanY = -((node.pos3.y * view.mix + node.pos2.y * (1 - view.mix)) * view.zoom);
    }

    function focusNode(key) {
        select(key);
        centerOn(key);
        view.tZoom = Math.max(1.15, view.tZoom);
    }

    /**
     * Ungezoomte Bildschirmlage eines Bausteins fuer die Zielansicht (2D/3D,
     * Ziel-Drehung) – unabhaengig vom gerade laufenden Uebergang.
     */
    function projectRaw(node, mix, yaw, pitch) {
        var cy = Math.cos(yaw), sy = Math.sin(yaw);
        var cp = Math.cos(pitch), sp = Math.sin(pitch);
        var x1 = node.pos3.x * cy + node.pos3.z * sy;
        var z1 = -node.pos3.x * sy + node.pos3.z * cy;
        var y1 = node.pos3.y * cp - z1 * sp;
        var z2 = node.pos3.y * sp + z1 * cp;
        var scale3 = FOCAL / Math.max(40, FOCAL - z2);
        return {
            x: x1 * scale3 * mix + node.pos2.x * (1 - mix),
            y: -y1 * scale3 * mix + node.pos2.y * (1 - mix)
        };
    }

    /** Breite der Beschriftung in Bildschirmpunkten (unabhaengig vom Zoom). */
    function labelWidth(node) {
        ctx.font = (node.kind === 'core' ? '700 13px ' : '600 12px ') + 'system-ui, -apple-system, "Segoe UI", sans-serif';
        var text = node.title + (node.state === 'unknown' || node.state === 'stale' ? ' (' + STATE_LABELS[node.state] + ')' : '');
        return Math.min(labelMax2d, ctx.measureText(text).width) + 30;
    }

    function boundsOfVisible() {
        var min = { x: Infinity, y: Infinity };
        var max = { x: -Infinity, y: -Infinity };
        var mix = view.mode3d ? 1 : 0;
        var labelRight = -Infinity;
        var yaws = [view.tYaw];
        graph.order.filter(function (key) {
            // Im Entwurfsmodus zaehlen auch die blassen Bausteine, damit die
            // Auswahl beim Einpassen nicht aus dem Bild faellt.
            return isDrawn(graph.nodes[key]);
        }).forEach(function (key) {
            var node = graph.nodes[key];
            yaws.forEach(function (yaw) {
                var p = projectRaw(node, mix, yaw, view.tPitch);
                min.x = Math.min(min.x, p.x);
                min.y = Math.min(min.y, p.y);
                max.x = Math.max(max.x, p.x);
                max.y = Math.max(max.y, p.y);
            });
            // In 2D steht die Beschriftung rechts daneben; sie gehoert mit ins Bild.
            if (!view.mode3d && view.labels) {
                var p2 = projectRaw(node, 0, 0, 0);
                labelRight = Math.max(labelRight, p2.x + labelWidth(node));
            }
        });
        if (!isFinite(min.x) || !isFinite(min.y)) {
            return null;
        }
        return { min: min, max: max, labelRight: labelRight };
    }

    /**
     * Freie Zeichenflaeche: Werkzeugleiste, Legende, Ereignisprotokoll und
     * Bedienhinweis liegen ueber der Buehne und werden ausgespart.
     */
    function freeArea() {
        var area = { left: 0, top: 0, right: view.width, bottom: view.height };
        var stageRect = stage.getBoundingClientRect();
        ['.topo-toolbar', '.topo-legend', '[data-topo-log]', '[data-topo-hint]'].forEach(function (selector) {
            var element = root.querySelector(selector);
            if (!element || element.hidden || !element.offsetParent) {
                return;
            }
            var rect = element.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) {
                return;
            }
            var left = rect.left - stageRect.left;
            var right = rect.right - stageRect.left;
            var top = rect.top - stageRect.top;
            var bottom = rect.bottom - stageRect.top;
            // Hochkant (Werkzeugleiste, Legende) belegt eine Seite, quer (Protokoll, Hinweis) oben/unten.
            if (rect.height > rect.width) {
                if (left + right < view.width) {
                    area.left = Math.max(area.left, right + 12);
                } else {
                    area.right = Math.min(area.right, left - 12);
                }
            } else if (top + bottom > view.height) {
                area.bottom = Math.min(area.bottom, top - 8);
            } else {
                area.top = Math.max(area.top, bottom + 8);
            }
        });
        // Bei sehr kleiner Buehne lieber Ueberlagerung als gar kein Bild.
        if (area.right - area.left < view.width * 0.4) {
            area.left = 0;
            area.right = view.width;
        }
        if (area.bottom - area.top < view.height * 0.4) {
            area.top = 0;
            area.bottom = view.height;
        }
        return area;
    }

    function coreNode() {
        for (var i = 0; i < graph.order.length; i++) {
            if (graph.nodes[graph.order[i]].kind === 'core') {
                return graph.nodes[graph.order[i]];
            }
        }
        return null;
    }

    function fit(immediate) {
        // In 2D haengen Spaltenabstand und Gruppenkopf (Weltkoordinaten) vom
        // Zoom ab, weil Schrift nicht mitzoomt: so lange neu anordnen, bis
        // Anordnung und Einpassung zum selben Zoom passen.
        if (!view.mode3d && layout2dState) {
            for (var pass = 0; pass < 8; pass++) {
                var zoom2d = fitZoom();
                if (!zoom2d || Math.abs(zoom2d - layout2dState.zoom) / zoom2d < 0.01) {
                    break;
                }
                layout2dState.apply(zoom2d);
            }
        }
        var target = fitZoom(true);
        if (!target) {
            return;
        }
        view.tZoom = target.zoom;
        view.tPanX = target.panX;
        view.tPanY = target.panY;
        if (immediate) {
            view.zoom = view.tZoom;
            view.panX = view.tPanX;
            view.panY = view.tPanY;
        }
    }

    /** Zoom (bzw. mit full=true Zoom und Verschiebung), der alles Sichtbare einpasst. */
    function fitZoom(full) {
        var bounds = boundsOfVisible();
        if (!bounds) {
            return null;
        }
        var area = freeArea();
        // Rand fuer Knotenradien und Beschriftungen (in 2D rechts daneben).
        var marginX = view.mode3d ? 120 : 80;
        var marginY = 90;
        var centerX = (bounds.min.x + bounds.max.x) / 2;
        var centerY = (bounds.min.y + bounds.max.y) / 2;
        // lanpa ist das optische Zentrum: ist der Kern sichtbar, wird um ihn
        // herum symmetrisch eingepasst, sonst um die Mitte des Sichtbaren.
        var core = coreNode();
        if (core && isDrawn(core)) {
            var c = projectRaw(core, view.mode3d ? 1 : 0, view.tYaw, view.tPitch);
            centerX = c.x;
            centerY = c.y;
        }
        var height = Math.max(1, 2 * Math.max(centerY - bounds.min.y, bounds.max.y - centerY));
        // Beschriftungen haben feste Schriftgroesse: ihr Ueberstand rechts der
        // aeussersten Spalte wird in Bildschirmpunkten freigehalten.
        var labelOverhang = isFinite(bounds.labelRight) ? Math.max(0, bounds.labelRight - bounds.max.x) : 0;
        var halfW = Math.max(40, (area.right - area.left - marginX) / 2);
        if (!view.mode3d) {
            marginY += 2 * TITLE_RESERVE_2D;
        }
        var availH = Math.max(80, area.bottom - area.top - marginY);
        var zoom = Math.min(
            halfW / Math.max(1, centerX - bounds.min.x),
            Math.max(20, halfW - labelOverhang) / Math.max(1, bounds.max.x - centerX),
            availH / height
        );
        // Die Auto-Drehung bringt nahe Bausteine naeher: etwas Reserve lassen.
        if (view.mode3d && view.rotate && !reducedMotion) {
            zoom *= 0.88;
        }
        zoom = view.mode3d ? Math.max(0.2, Math.min(1.6, zoom)) : Math.max(MIN_ZOOM_2D, Math.min(MAX_ZOOM_2D, zoom));
        if (!full) {
            return zoom;
        }
        return {
            zoom: zoom,
            panX: (area.left + area.right) / 2 - view.width / 2 - centerX * zoom,
            panY: (area.top + area.bottom) / 2 - view.height / 2 - centerY * zoom
        };
    }

    function resetView() {
        view.tYaw = -0.35;
        view.tPitch = 0.42;
        view.tZoom = 1;
        view.tPanX = 0;
        view.tPanY = 0;
        view.focusGroup = null;
        view.collapse = false;
        setPressed('collapse', false);
        fit();
    }

    function setPressed(action, pressed) {
        var buttons = root.querySelectorAll('[data-topo-action="' + action + '"]');
        Array.prototype.forEach.call(buttons, function (button) {
            if (pressed) {
                button.classList.add('is-active');
            } else {
                button.classList.remove('is-active');
            }
            button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        });
    }

    function setMode(mode3d, immediate) {
        var changed = view.mode3d !== mode3d;
        view.mode3d = mode3d;
        setPressed('mode-3d', mode3d);
        setPressed('mode-2d', !mode3d);
        // 2D und 3D haben verschiedene Anordnungen: neu einpassen.
        if (changed || immediate) {
            fit(immediate);
        }
    }

    function cycleSelection(step) {
        var visible = visibleNodes();
        if (!visible.length) {
            return;
        }
        var index = view.selected ? visible.indexOf(view.selected) : -1;
        var next = index + step;
        if (next < 0) { next = visible.length - 1; }
        if (next >= visible.length) { next = 0; }
        focusNode(visible[next]);
    }

    // ---------------------------------------------------------- Entwurfsmodus

    /** Gespeicherte Auswahl aus dem eingebetteten JSON lesen. */
    function parseVisibility() {
        var holder = root.querySelector('[data-topo-visibility]');
        if (!holder) {
            return null;
        }
        try {
            return JSON.parse(holder.textContent || 'null');
        } catch (error) {
            return null;
        }
    }

    /** Liste von Kennungen in eine Nachschlagetabelle umwandeln. */
    function keysOf(list) {
        var result = {};
        (Array.isArray(list) ? list : []).forEach(function (value) {
            var key = String(value === null || value === undefined ? '' : value);
            if (key !== '') {
                result[key] = true;
            }
        });
        return result;
    }

    /** Wirksame Auswahl uebernehmen und die Anzeige darauf abstimmen. */
    function applySelection(selection) {
        var payload = selection && typeof selection === 'object' ? selection : {};
        view.hiddenNodes = keysOf(payload.nodes);
        view.hiddenGroups = keysOf(payload.groups);
        visibility = {
            source: typeof payload.source === 'string' ? payload.source : 'default',
            source_label: typeof payload.source_label === 'string' ? payload.source_label : '',
            nodes: Object.keys(view.hiddenNodes).sort(),
            groups: Object.keys(view.hiddenGroups).sort()
        };
        if (view.selected && graph.nodes[view.selected] && isHiddenByUser(graph.nodes[view.selected])) {
            select(null);
        }
        setDraftUi();
    }

    /** Aktuelle Auswahl als Formulardaten fuer den Speicher-Endpunkt. */
    function selectionBody(scope) {
        var body = new window.URLSearchParams();
        body.append('_token', csrfToken);
        body.append('scope', scope);
        visibility.nodes.forEach(function (key) {
            body.append('nodes[]', key);
        });
        visibility.groups.forEach(function (id) {
            body.append('groups[]', id);
        });
        return body;
    }

    function hiddenCount() {
        return {
            nodes: Object.keys(view.hiddenNodes).length,
            groups: Object.keys(view.hiddenGroups).length
        };
    }

    /** Entwurfsleiste, Schaltflaechen und Statuszeile aktualisieren. */
    function setDraftUi() {
        var count = hiddenCount();
        if (draftBox) {
            draftBox.hidden = !view.draft;
        }
        root.classList.toggle('is-draft', view.draft);
        setPressed('draft', view.draft);
        if (draftSourceLabel) {
            draftSourceLabel.textContent = view.draft
                ? (visibility.source_label || 'Keine Auswahl gespeichert')
                    + ' · ausgeblendet: ' + count.nodes + ' Baustein(e), ' + count.groups + ' Gruppe(n)'
                : '';
        }
        if (personalAvailable) {
            setDisabled('draft-save-personal', false);
            setDisabled('draft-clear-personal', visibility.source !== 'personal');
        } else {
            setDisabled('draft-save-personal', true);
            setDisabled('draft-clear-personal', true);
        }
        syncGroupButtons();
    }

    /** Markierung der Gruppenliste an die Entwurfsauswahl anpassen. */
    function syncGroupButtons() {
        var buttons = root.querySelectorAll('[data-topo-group]');
        Array.prototype.forEach.call(buttons, function (button) {
            var id = button.getAttribute('data-topo-group');
            var hidden = view.hiddenGroups[id] === true;
            button.classList.toggle('is-hidden-by-user', hidden);
            if (hidden) {
                button.setAttribute('data-topo-hidden', '1');
            } else {
                button.removeAttribute('data-topo-hidden');
            }
        });
    }

    function setDisabled(action, disabled) {
        var buttons = root.querySelectorAll('[data-topo-action="' + action + '"]');
        Array.prototype.forEach.call(buttons, function (button) {
            button.disabled = disabled;
            if (disabled) {
                button.setAttribute('aria-disabled', 'true');
            } else {
                button.removeAttribute('aria-disabled');
            }
        });
    }

    function setDraftStatus(message, isError) {
        if (!draftStatus) {
            return;
        }
        draftStatus.textContent = message || '';
        draftStatus.classList.toggle('is-error', isError === true);
    }

    function toggleDraft() {
        view.draft = !view.draft;
        closeMenu();
        if (!view.draft) {
            setDraftStatus('');
            if (view.selected && graph.nodes[view.selected] && isHiddenByUser(graph.nodes[view.selected])) {
                select(null);
            }
        }
        setDraftUi();
        log('info', view.draft
            ? 'Entwurfsmodus aktiviert: Rechtsklick auf Baustein oder Gruppe blendet aus bzw. ein.'
            : 'Entwurfsmodus beendet.');
    }

    /** Einzelnen Baustein ein- oder ausblenden. */
    function toggleNode(key) {
        var node = graph.nodes[key];
        if (!node) {
            return;
        }
        if (view.hiddenGroups[node.topGroup] === true) {
            // Die ganze Gruppe ist ausgeblendet: erst den Einzelbaustein aus
            // der Gruppenausblendung loesen, sonst bliebe er unsichtbar.
            delete view.hiddenGroups[node.topGroup];
            visibility.groups = Object.keys(view.hiddenGroups).sort();
        }
        if (view.hiddenNodes[key] === true) {
            delete view.hiddenNodes[key];
            log('info', 'Eingeblendet: ' + node.title);
        } else {
            view.hiddenNodes[key] = true;
            log('info', 'Ausgeblendet: ' + node.title);
        }
        visibility.nodes = Object.keys(view.hiddenNodes).sort();
        afterVisibilityChange(key);
    }

    /** Gesamte Gruppe ein- oder ausblenden. */
    function toggleGroup(id) {
        var group = graph.groups[id];
        if (!group) {
            return;
        }
        if (view.hiddenGroups[id] === true) {
            delete view.hiddenGroups[id];
            log('info', 'Gruppe eingeblendet: ' + group.title);
        } else {
            view.hiddenGroups[id] = true;
            log('info', 'Gruppe ausgeblendet: ' + group.title);
        }
        visibility.groups = Object.keys(view.hiddenGroups).sort();
        afterVisibilityChange(null);
    }

    function afterVisibilityChange(key) {
        if (key && view.selected === key && isHiddenByUser(graph.nodes[key])) {
            select(null);
        }
        setDraftUi();
    }

    /** Auswahl an den Server schicken und als neuen Bezugspunkt uebernehmen. */
    function saveSelection(scope) {
        if (!saveUrl) {
            setDraftStatus('Speichern nicht moeglich: Endpunkt fehlt.', true);
            return;
        }
        if (scope === 'personal' && !personalAvailable) {
            setDraftStatus('Persoenliche Einstellung ist ohne Anmeldung nicht verfuegbar.', true);
            return;
        }
        setDraftStatus('Auswahl wird gespeichert …', false);
        window.fetch(saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: selectionBody(scope).toString()
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (!result.ok || !result.data || result.data.ok !== true) {
                setDraftStatus((result.data && result.data.error) || 'Speichern fehlgeschlagen.', true);
                return;
            }
            applySelection(result.data.selection);
            setDraftStatus(scope === 'personal'
                ? 'Persönliche Einstellung gespeichert.'
                : 'Globale Einstellung gespeichert.', false);
            log('info', 'Auswahl gespeichert (' + (scope === 'personal' ? 'persönlich' : 'global') + ').');
        }).catch(function (error) {
            setDraftStatus('Speichern fehlgeschlagen: ' + (error && error.message ? error.message : 'unbekannter Fehler'), true);
        });
    }

    /** Persoenliche Einstellung loeschen, danach gilt wieder die globale. */
    function clearPersonalSelection() {
        if (!saveUrl || !personalAvailable) {
            return;
        }
        setDraftStatus('Persönliche Einstellung wird gelöscht …', false);
        window.fetch(saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: selectionBody('clear').toString()
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (!result.ok || !result.data || result.data.ok !== true) {
                setDraftStatus((result.data && result.data.error) || 'Loeschen fehlgeschlagen.', true);
                return;
            }
            applySelection(result.data.selection);
            setDraftStatus('Persönliche Einstellung gelöscht.', false);
            log('info', 'Persönliche Einstellung gelöscht.');
        }).catch(function (error) {
            setDraftStatus('Loeschen fehlgeschlagen: ' + (error && error.message ? error.message : 'unbekannter Fehler'), true);
        });
    }

    /** Auf die zuletzt gespeicherte Auswahl zuruecksetzen. */
    function resetSelection() {
        var nodes = {};
        var groups = {};
        visibility.nodes.forEach(function (key) { nodes[key] = true; });
        visibility.groups.forEach(function (id) { groups[id] = true; });
        view.hiddenNodes = nodes;
        view.hiddenGroups = groups;
        setDraftUi();
        setDraftStatus('Auf gespeicherte Auswahl zurückgesetzt.', false);
        log('info', 'Entwurfsauswahl zurückgesetzt.');
    }

    // ------------------------------------------------------------ Kontextmenue

    function menuEntry(label, action, key, pressed) {
        var item = document.createElement('li');
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'topo-menu__button';
        button.setAttribute('role', 'menuitem');
        button.setAttribute('data-topo-menu-action', action);
        button.setAttribute('data-topo-menu-key', key === null || key === undefined ? '' : String(key));
        button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        button.textContent = label;
        item.appendChild(button);
        return item;
    }

    function closeMenu() {
        menuTarget = null;
        if (!menu) {
            return;
        }
        menu.hidden = true;
        menu.classList.remove('is-open');
    }

    function menuOpen() {
        return menu !== null && !menu.hidden;
    }

    /** Kontextmenue fuer einen Baustein oder eine Gruppe aufbauen und zeigen. */
    function openMenu(target, clientX, clientY) {
        if (!menu || !menuList || !view.draft) {
            return;
        }
        menuList.textContent = '';
        menuTarget = target;
        var hidden = target.type === 'node'
            ? isHiddenByUser(graph.nodes[target.key])
            : view.hiddenGroups[target.key] === true;
        if (menuTitle) {
            menuTitle.textContent = target.type === 'node'
                ? 'Baustein: ' + target.label
                : 'Gruppe: ' + target.label;
        }
        menuList.appendChild(menuEntry(hidden ? 'Einblenden' : 'Ausblenden',
            (target.type === 'node' ? 'node-' : 'group-') + (hidden ? 'show' : 'hide'), target.key, hidden));
        if (target.type === 'node') {
            menuList.appendChild(menuEntry('Details anzeigen', 'node-focus', target.key, view.selected === target.key));
        }
        if (menuHint) {
            menuHint.textContent = target.type === 'node'
                ? 'Einzelner Baustein; die Gesamtkennzahlen bleiben unverändert.'
                : 'Alle Bausteine dieser Gruppe.';
        }
        hideTooltip();
        menu.hidden = false;
        menu.classList.add('is-open');
        var rect = menu.getBoundingClientRect();
        var left = Math.min(Math.max(4, clientX), Math.max(4, window.innerWidth - rect.width - 4));
        var top = Math.min(Math.max(4, clientY), Math.max(4, window.innerHeight - rect.height - 4));
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        var first = menuList.querySelector('button');
        if (first) {
            first.focus();
        }
    }

    function runMenuAction(action, key) {
        if (action === 'node-hide' || action === 'node-show') {
            toggleNode(key);
        } else if (action === 'group-hide' || action === 'group-show') {
            toggleGroup(key);
        } else if (action === 'node-focus') {
            focusNode(key);
        }
        closeMenu();
    }

    function bindMenu() {
        if (menuList) {
            menuList.addEventListener('click', function (event) {
                var button = event.target.closest ? event.target.closest('[data-topo-menu-action]') : null;
                if (!button) {
                    return;
                }
                runMenuAction(button.getAttribute('data-topo-menu-action'), button.getAttribute('data-topo-menu-key'));
            });
            menuList.addEventListener('keydown', function (event) {
                var buttons = Array.prototype.slice.call(menuList.querySelectorAll('button'));
                var index = buttons.indexOf(document.activeElement);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    var step = event.key === 'ArrowDown' ? 1 : -1;
                    var next = index + step;
                    if (next < 0) { next = buttons.length - 1; }
                    if (next >= buttons.length) { next = 0; }
                    if (buttons[next]) { buttons[next].focus(); }
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closeMenu();
                    if (canvas) { canvas.focus(); }
                }
            });
        }
        document.addEventListener('pointerdown', function (event) {
            if (!menuOpen() || !menu) {
                return;
            }
            if (!menu.contains(event.target)) {
                closeMenu();
            }
        }, true);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menuOpen()) {
                closeMenu();
            }
        });
    }

    var actions = {
        'mode-3d': function () { setMode(true); },
        'mode-2d': function () { setMode(false); },
        rotate: function () {
            view.rotate = !view.rotate;
            setPressed('rotate', view.rotate);
        },
        particles: function () {
            view.particles = !view.particles;
            setPressed('particles', view.particles);
            graph.edges.forEach(syncParticles);
        },
        labels: function () {
            view.labels = !view.labels;
            setPressed('labels', view.labels);
        },
        'focus-problems': function () {
            view.focusProblems = !view.focusProblems;
            setPressed('focus-problems', view.focusProblems);
        },
        collapse: function () {
            view.collapse = !view.collapse;
            setPressed('collapse', view.collapse);
            if (!view.collapse) {
                view.focusGroup = null;
            }
            fit();
        },
        'zoom-in': function () {
            view.tZoom = Math.min(4, view.tZoom * 1.2);
        },
        'zoom-out': function () {
            view.tZoom = Math.max(0.2, view.tZoom / 1.2);
        },
        fit: function () { fit(); },
        reset: function () { resetView(); },
        refresh: function () { load(true); },
        fullscreen: function () {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (root.requestFullscreen) {
                root.requestFullscreen();
            }
        },
        'close-panel': function () { select(null); },
        draft: function () { toggleDraft(); },
        'draft-save-global': function () { saveSelection('global'); },
        'draft-save-personal': function () { saveSelection('personal'); },
        'draft-clear-personal': function () { clearPersonalSelection(); },
        'draft-reset': function () { resetSelection(); },
        'toggle-log': function (button) {
            if (!logList) {
                return;
            }
            var collapsed = logList.hasAttribute('hidden');
            if (collapsed) {
                logList.removeAttribute('hidden');
            } else {
                logList.setAttribute('hidden', '');
            }
            button.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
            button.textContent = collapsed ? 'Einklappen' : 'Ausklappen';
        }
    };

    function focusGroup(groupId) {
        view.collapse = true;
        setPressed('collapse', true);
        view.focusGroup = groupId === view.focusGroup ? null : groupId;
        var members = membersOfGroup(groupId);
        if (members.length) {
            select(members[0]);
        }
        fit();
        log('info', view.focusGroup
            ? 'Modulgruppe hervorgehoben: ' + ((graph.groups[groupId] && graph.groups[groupId].title) || groupId)
            : 'Alle Modulgruppen sind wieder aufgeklappt.');
    }

    // ------------------------------------------------------------ Ereignisse (Protokoll)

    function log(level, text) {
        if (!logList) {
            return;
        }
        var item = el('li', 'topo-log__item topo-log__item--' + level, timeNow() + ' · ' + text);
        logList.insertBefore(item, logList.firstChild);
        while (logList.childNodes.length > 60) {
            logList.removeChild(logList.lastChild);
        }
    }

    // ------------------------------------------------------------ Daten uebernehmen

    function nodeListEntry(node) {
        var item = el('li', 'topo-incident topo-incident--' + node.state);
        item.setAttribute('data-topo-incident', node.key);
        var button = el('button', 'topo-incident__focus');
        button.type = 'button';
        button.setAttribute('data-topo-focus', node.key);
        button.appendChild(el('strong', null, node.title));
        button.appendChild(el('span', 'topo-incident__state', node.stateLabel));
        if (node.message) {
            button.appendChild(el('span', null, node.message));
        }
        item.appendChild(button);
        if (node.link && /^\/[^\/\\]/.test(node.link)) {
            var link = el('a', null, 'Öffnen');
            link.setAttribute('href', node.link);
            item.appendChild(link);
        }
        return item;
    }

    function setIncidents(list) {
        if (incidentList) {
            clearChildren(incidentList);
            list.forEach(function (entry) {
                var node = graph.nodes[String(entry && entry.node ? entry.node : '')];
                if (node) {
                    incidentList.appendChild(nodeListEntry(node));
                }
            });
        }
        if (incidentsEmpty) {
            incidentsEmpty.hidden = list.length > 0;
        }
        if (incidentsBox) {
            incidentsBox.classList.remove('topo-incidents--open', 'topo-incidents--none');
            incidentsBox.classList.add(list.length ? 'topo-incidents--open' : 'topo-incidents--none');
        }
        setField('incident-count', list.length, true);
    }

    function setGaps(list) {
        if (gapList) {
            clearChildren(gapList);
            list.forEach(function (entry) {
                var node = graph.nodes[String(entry && entry.node ? entry.node : '')];
                if (node) {
                    gapList.appendChild(nodeListEntry(node));
                }
            });
        }
        if (gapsEmpty) {
            gapsEmpty.hidden = list.length > 0;
        }
        if (gapsBox) {
            gapsBox.classList.remove('topo-incidents--open', 'topo-incidents--none');
            gapsBox.classList.add(list.length ? 'topo-incidents--open' : 'topo-incidents--none');
        }
        setField('gap-count', list.length, true);
    }

    function setOverall(overall) {
        var state = stateOf(overall && overall.state);
        setField('overall-label', (overall && overall.label) || STATE_LABELS[state]);
        setField('overall-message', (overall && overall.message) || '', true);
        setField('overall-errors', overall && overall.errors !== undefined ? overall.errors : 0, true);
        setField('overall-warnings', overall && overall.warnings !== undefined ? overall.warnings : 0, true);
        setField('overall-unrated', overall && overall.unrated !== undefined ? overall.unrated : 0, true);
        setField('overall-unwatched', overall && overall.unwatched !== undefined ? overall.unwatched : 0, true);
        if (overallBox) {
            swapClass(overallBox, 'topo-head__status--', state);
        }
        if (overallPulse) {
            swapClass(overallPulse, 'topo-pulse--', state);
        }
        var counts = (overall && overall.counts) || {};
        STATES.forEach(function (name) {
            var node = root.querySelector('[data-topo-state-count="' + name + '"]');
            if (node) {
                node.textContent = String(counts[name] === undefined ? 0 : counts[name]);
            }
        });
    }

    function setKpis(kpis) {
        if (!Array.isArray(kpis)) {
            return;
        }
        kpis.forEach(function (kpi) {
            var box = root.querySelector('[data-topo-kpi="' + String(kpi && kpi.key ? kpi.key : '') + '"]');
            if (!box) {
                return;
            }
            var value = box.querySelector('dd');
            if (value) {
                value.textContent = String(kpi.value === undefined || kpi.value === null ? '' : kpi.value);
            }
            // Kennzahlen ohne eigenen Zustand bleiben neutral (kein "gesund").
            var state = STATES.indexOf(kpi.state) === -1 ? 'plain' : kpi.state;
            swapClass(box, 'topo-kpi--', state);
        });
    }

    function setGroupStates(groups) {
        if (!Array.isArray(groups)) {
            return;
        }
        groups.forEach(function (group) {
            var button = root.querySelector('[data-topo-group="' + String(group && group.id ? group.id : '') + '"]');
            if (!button) {
                return;
            }
            var state = stateOf(group.state);
            swapClass(button, 'topo-groups__item--', state);
            var dot = button.querySelector('.topo-dot');
            if (dot) {
                swapClass(dot, 'topo-dot--', state);
            }
            var meta = button.querySelector('span:last-child');
            if (meta) {
                meta.textContent = (parseInt(group.node_count, 10) || 0) + ' Bausteine · ' + (group.state_label || STATE_LABELS[state]);
            }
        });
    }

    var staleText = staleBanner ? staleBanner.textContent.trim() : '';
    var loadFailed = false;

    /**
     * Veraltet-Hinweis: zeigt veraltete Messwerte an und – solange die letzte
     * Aktualisierung fehlgeschlagen ist – dass die Anzeige nicht mehr dem
     * aktuellen Stand entspricht.
     */
    function setFreshness(data) {
        var freshness = (data && data.freshness) || {};
        if (staleBanner) {
            staleBanner.hidden = freshness.fresh !== false;
            staleBanner.textContent = staleText;
        }
        if (data && data.generated_at) {
            setField('generated', data.generated_at, true);
        }
    }

    function markLoadFailed(message) {
        if (!loadFailed) {
            log('error', 'Aktualisierung fehlgeschlagen: ' + message);
        }
        loadFailed = true;
        if (staleBanner) {
            staleBanner.textContent = 'Aktualisierung fehlgeschlagen (' + message + '). Die Anzeige zeigt den letzten bekannten Stand und ist möglicherweise veraltet.';
            staleBanner.hidden = false;
        }
    }

    /** Aenderungen gegenueber der letzten Antwort hervorheben und protokollieren. */
    function diff(data) {
        var now = {};
        graph.order.forEach(function (key) {
            now[key] = graph.nodes[key].state;
        });
        if (previous) {
            Object.keys(now).forEach(function (key) {
                if (!previous[key] || previous[key] === now[key]) {
                    return;
                }
                var node = graph.nodes[key];
                var level = now[key] === 'error' ? 'error' : now[key] === 'warn' ? 'warn' : now[key] === 'ok' ? 'info' : 'info';
                log(level, node.title + ': ' + STATE_LABELS[previous[key]] + ' → ' + STATE_LABELS[now[key]]);
                if (now[key] === 'error' && previous[key] !== 'error') {
                    effects.push({ key: key, start: performance.now(), duration: 1600, state: 'error' });
                }
            });
            Object.keys(previous).forEach(function (key) {
                if (!now[key]) {
                    log('info', 'Baustein entfallen: ' + key);
                }
            });
        }
        previous = now;
    }

    function apply(data) {
        if (!data || typeof data !== 'object') {
            return;
        }
        ingest(data);
        diff(data);
        setOverall(data.overall);
        setKpis(data.kpis);
        setGroupStates(data.groups);
        setIncidents(Array.isArray(data.incidents) ? data.incidents : []);
        setGaps(Array.isArray(data.gaps) ? data.gaps : []);
        setFreshness(data);
        if (data.validation_ok === false && Array.isArray(data.validation) && data.validation.length) {
            log('warn', 'Der Graph enthält ' + data.validation.length + ' Hinweis(e) zur Prüfung.');
        }
    }

    var loading = false;
    var loadSeq = 0;

    /** Gueltige Antwort: Objekt mit Bausteinen (Liste oder Schluesselobjekt, Schema 1). */
    function isPayload(data) {
        return !!data && typeof data === 'object' && !!data.nodes && typeof data.nodes === 'object'
            && (data.schema_version === undefined || parseInt(data.schema_version, 10) === 1);
    }

    function load(manual) {
        if (!refreshUrl || typeof window.fetch !== 'function') {
            return;
        }
        // Keine ueberlappenden Abrufe; nur die juengste Antwort zaehlt.
        if (loading && !manual) {
            return;
        }
        var seq = ++loadSeq;
        loading = true;
        var button = root.querySelector('[data-topo-action="refresh"]');
        if (manual) {
            log('info', 'Aktualisierung angefordert.');
            if (button) {
                button.classList.add('is-busy');
            }
        }
        window.fetch(refreshUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (data) {
            if (seq !== loadSeq) {
                return;
            }
            if (!isPayload(data)) {
                throw new Error('unerwartete Antwort');
            }
            if (loadFailed) {
                log('info', 'Aktualisierung wieder erfolgreich.');
            }
            loadFailed = false;
            apply(data);
        })['catch'](function (error) {
            if (seq === loadSeq) {
                markLoadFailed(error && error.message ? error.message : 'unbekannter Fehler');
            }
        }).then(function () {
            if (seq === loadSeq) {
                loading = false;
                if (button) {
                    button.classList.remove('is-busy');
                }
            }
        });
    }

    function scheduleRefresh() {
        if (timer !== null) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(function () {
            if (!document.hidden) {
                load(false);
            }
            scheduleRefresh();
        }, refreshInterval);
    }

    // ------------------------------------------------------------ Start

    function bindPointer() {
        var dragging = false;
        var panning = false;
        var moved = false;
        var last = { x: 0, y: 0 };
        var pinchDistance = 0;

        canvas.addEventListener('pointerdown', function (event) {
            canvas.setPointerCapture(event.pointerId);
            dragging = true;
            moved = false;
            // Im Entwurfsmodus oeffnet die rechte Maustaste das Kontextmenue,
            // deshalb wird dort nicht mit ihr verschoben.
            panning = event.shiftKey || event.button === 1 || (event.button === 2 && !view.draft);
            last = { x: event.clientX, y: event.clientY };
            view.lastInteraction = performance.now();
        });

        canvas.addEventListener('pointermove', function (event) {
            var point = localPoint(event);
            if (dragging) {
                var dx = event.clientX - last.x;
                var dy = event.clientY - last.y;
                if (Math.abs(dx) + Math.abs(dy) > 2) {
                    moved = true;
                }
                if (panning || !view.mode3d) {
                    view.tPanX += dx;
                    view.tPanY += dy;
                    view.panX += dx;
                    view.panY += dy;
                } else {
                    view.tYaw += dx * 0.006;
                    view.tPitch = Math.max(-1.2, Math.min(1.2, view.tPitch + dy * 0.005));
                }
                last = { x: event.clientX, y: event.clientY };
                view.lastInteraction = performance.now();
                hideTooltip();
                return;
            }
            var key = hitTest(point);
            if (key !== view.hovered) {
                view.hovered = key;
                canvas.style.cursor = key ? 'pointer' : 'grab';
            }
            if (key) {
                showTooltip(key, point);
            } else {
                hideTooltip();
            }
        });

        function endPointer(event) {
            if (!dragging) {
                return;
            }
            dragging = false;
            if (event.button === 2 && view.draft) {
                // Rechtsklick im Entwurfsmodus: nur Kontextmenue, keine Auswahl.
                return;
            }
            if (!moved) {
                var point = localPoint(event);
                var key = hitTest(point);
                if (key) {
                    select(key);
                } else {
                    select(null);
                }
            }
        }

        canvas.addEventListener('pointerup', endPointer);
        canvas.addEventListener('pointercancel', function () { dragging = false; });
        canvas.addEventListener('pointerleave', function () {
            view.hovered = null;
            hideTooltip();
        });
        canvas.addEventListener('contextmenu', function (event) {
            event.preventDefault();
            if (!view.draft) {
                closeMenu();
                return;
            }
            var point = localPoint(event);
            var key = hitTest(point);
            if (key) {
                openMenu({ type: 'node', key: key, label: graph.nodes[key].title }, event.clientX, event.clientY);
                return;
            }
            var groupId = groupAt(point);
            if (groupId) {
                openMenu({ type: 'group', key: groupId, label: graph.groups[groupId].title }, event.clientX, event.clientY);
                return;
            }
            closeMenu();
        });

        canvas.addEventListener('dblclick', function (event) {
            var key = hitTest(localPoint(event));
            if (key) {
                focusNode(key);
            } else {
                fit();
            }
        });

        canvas.addEventListener('wheel', function (event) {
            event.preventDefault();
            var factor = event.deltaY < 0 ? 1.1 : 1 / 1.1;
            view.tZoom = Math.max(0.2, Math.min(4, view.tZoom * factor));
            view.lastInteraction = performance.now();
        }, { passive: false });

        canvas.addEventListener('touchstart', function (event) {
            if (event.touches.length === 2) {
                var dx = event.touches[0].clientX - event.touches[1].clientX;
                var dy = event.touches[0].clientY - event.touches[1].clientY;
                pinchDistance = Math.sqrt(dx * dx + dy * dy);
            }
        }, { passive: true });

        canvas.addEventListener('touchmove', function (event) {
            if (event.touches.length !== 2 || !pinchDistance) {
                return;
            }
            event.preventDefault();
            var dx = event.touches[0].clientX - event.touches[1].clientX;
            var dy = event.touches[0].clientY - event.touches[1].clientY;
            var distance = Math.sqrt(dx * dx + dy * dy);
            if (distance > 0) {
                view.tZoom = Math.max(0.2, Math.min(4, view.tZoom * (distance / pinchDistance)));
                pinchDistance = distance;
            }
        }, { passive: false });

        canvas.addEventListener('touchend', function () { pinchDistance = 0; }, { passive: true });
    }

    function bindKeyboard() {
        canvas.addEventListener('keydown', function (event) {
            var handled = true;
            switch (event.key) {
                case 'ArrowLeft': view.tYaw -= 0.08; break;
                case 'ArrowRight': view.tYaw += 0.08; break;
                case 'ArrowUp': view.tPitch = Math.max(-1.2, view.tPitch - 0.06); break;
                case 'ArrowDown': view.tPitch = Math.min(1.2, view.tPitch + 0.06); break;
                case '+': case '=': view.tZoom = Math.min(4, view.tZoom * 1.15); break;
                case '-': case '_': view.tZoom = Math.max(0.2, view.tZoom / 1.15); break;
                case '0': resetView(); break;
                case 'f': case 'F': fit(); break;
                case 'r': case 'R': actions.refresh(); break;
                case 'p': case 'P': actions.particles(); break;
                case 'l': case 'L': actions.labels(); break;
                case 'g': case 'G': actions.collapse(); break;
                case '2': setMode(false); break;
                case '3': setMode(true); break;
                case '!': actions['focus-problems'](); break;
                case 'n': cycleSelection(1); break;
                case 'N': cycleSelection(-1); break;
                case 'e': case 'E': toggleDraft(); break;
                case 'Enter': if (view.hovered) { focusNode(view.hovered); } break;
                case 'Escape':
                    if (menuOpen()) {
                        closeMenu();
                    } else {
                        select(null);
                        hideTooltip();
                    }
                    break;
                case ' ': actions.rotate(); break;
                default: handled = false;
            }
            if (handled) {
                event.preventDefault();
                view.lastInteraction = performance.now();
            }
        });
    }

    function bindActions() {
        root.addEventListener('click', function (event) {
            var target = event.target;
            while (target && target !== root) {
                if (target.getAttribute) {
                    var action = target.getAttribute('data-topo-action');
                    if (action && actions[action]) {
                        actions[action](target);
                        return;
                    }
                    var focus = target.getAttribute('data-topo-focus');
                    if (focus) {
                        focusNode(focus);
                        return;
                    }
                    var group = target.getAttribute('data-topo-group');
                    if (group) {
                        focusGroup(group);
                        return;
                    }
                }
                target = target.parentNode;
            }
        });

        if (kindFilter) {
            kindFilter.addEventListener('change', function (event) {
                var input = event.target;
                if (!input || input.type !== 'checkbox') {
                    return;
                }
                var kind = String(input.value);
                if (input.checked) {
                    delete view.hiddenKinds[kind];
                } else {
                    view.hiddenKinds[kind] = true;
                }
            });
        }

        if (stateFilter) {
            stateFilter.addEventListener('change', function (event) {
                var input = event.target;
                if (!input || input.type !== 'checkbox') {
                    return;
                }
                var state = String(input.value);
                if (input.checked) {
                    delete view.hiddenStates[state];
                } else {
                    view.hiddenStates[state] = true;
                }
            });
        }

        if (groupList) {
            groupList.addEventListener('click', function (event) {
                var target = event.target;
                while (target && target !== groupList) {
                    if (target.getAttribute && target.getAttribute('data-topo-group')) {
                        focusGroup(target.getAttribute('data-topo-group'));
                        return;
                    }
                    target = target.parentNode;
                }
            });
            groupList.addEventListener('contextmenu', function (event) {
                var target = event.target;
                while (target && target !== groupList) {
                    if (target.getAttribute && target.getAttribute('data-topo-group')) {
                        event.preventDefault();
                        if (!view.draft) {
                            closeMenu();
                            return;
                        }
                        var id = target.getAttribute('data-topo-group');
                        var group = graph.groups[id];
                        openMenu({ type: 'group', key: id, label: group ? group.title : id }, event.clientX, event.clientY);
                        return;
                    }
                    target = target.parentNode;
                }
            });
        }
    }

    function bindLifecycle() {
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else {
                load(false);
                scheduleRefresh();
                start();
            }
        });

        window.addEventListener('resize', resize);
        if (typeof window.ResizeObserver === 'function') {
            new window.ResizeObserver(resize).observe(stage);
        }
        document.addEventListener('fullscreenchange', function () {
            window.setTimeout(resize, 60);
        });
    }

    function boot() {
        resize();
        buildStars();
        var initial = parseInitial();
        if (initial) {
            apply(initial);
        } else {
            log('warn', 'Kein Startzustand im Dokument gefunden.');
        }
        setMode(true);
        setPressed('rotate', view.rotate);
        setPressed('particles', view.particles);
        setPressed('labels', view.labels);
        setPressed('collapse', view.collapse);
        applySelection(parseVisibility());
        // Startansicht: lanpa in der Mitte, alles Sichtbare im freien Bereich.
        fit(true);
        bindPointer();
        bindKeyboard();
        bindActions();
        bindMenu();
        bindLifecycle();
        // Nur eine bereits gesetzte Auswahl wiederherstellen; beim ersten Aufruf
        // bleibt die Detailtafel zu, bis der Nutzer etwas anklickt.
        if (view.selected) {
            select(view.selected);
        }
        start();
        scheduleRefresh();
    }

    boot();
}());
