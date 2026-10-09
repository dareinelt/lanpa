/*
 * Admin "Office → Orvanta – Nachrichtenfluss": Topologie-Ansicht.
 *
 * Zeichnet alle am Nachrichtenfluss beteiligten Bausteine als verbundenes Netz
 * auf ein Canvas: Knoten liegen im Raum (3D, perspektivisch projiziert, im
 * Stil eines neuronalen Netzes) oder als flaches Netzdiagramm (2D); zwischen
 * beiden Anordnungen wird weich ueberblendet. Partikel wandern entlang der
 * Kanten und bilden Aktivitaet ab (Menge nach Sitzungen/Postfaechern/Anfragen);
 * auf gestoerten Kanten verloeschen sie auf halber Strecke. Gestoerte Knoten
 * pulsieren rot, eingeschraenkte atmen gelb. Aendert sich ein Zustand bei der
 * Aktualisierung, laeuft eine Welle vom Knoten aus und das Ereignis landet im
 * Protokoll.
 *
 * Bedienung: Ziehen dreht (3D) bzw. verschiebt (2D), Umschalt+Ziehen oder
 * rechte/mittlere Maustaste verschiebt, Rad zoomt, Klick waehlt (Detailpanel),
 * Doppelklick zentriert, Zwei-Finger-Geste zoomt. Tastatur: Pfeile, +/-, 0, F,
 * R, P, L, Leertaste, 2, 3, !, N, Escape.
 *
 * Daten: Startzustand aus <script type="application/json" data-flow-initial>,
 * danach dasselbe JSON wie das Kartendashboard von data-refresh-url. Neue oder
 * entfallene Knoten (z. B. ein weiterer Exchange-Host) werden uebernommen und
 * das Netz neu angeordnet. Im Hintergrund (document.hidden) pausieren
 * Zeichnen und Abfrage. prefers-reduced-motion schaltet Drehung und Partikel
 * ab; alles bleibt bedienbar. DOM-Aufbau nur über createElement/textContent, keine Inline-Stile.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-flow-topology]');
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

    var STATES = ['ok', 'warn', 'error', 'off'];
    var STATE_COLORS = { ok: '#2ad4a0', warn: '#ffc14d', error: '#ff4d6d', off: '#6b7a95' };
    var STATE_RGB = { ok: '42,212,160', warn: '255,193,77', error: '255,77,109', off: '107,122,149' };
    var STATE_LABELS = { ok: 'In Ordnung', warn: 'Eingeschränkt', error: 'Störung', off: 'Nicht aktiv' };
    var STATE_BADGE = { ok: 'badge--ok', warn: 'badge--warn', error: 'badge--error', off: 'badge--muted' };
    var KIND_COLORS = {
        source: '#7dd3fc', proxy: '#a78bfa', host: '#60a5fa', users: '#f9fafb',
        ai: '#f0abfc', cache: '#fbbf24', tier: '#34d399'
    };
    var KIND_LABELS = {
        source: 'Identitätsquelle', proxy: 'IMAP-/SMTP-Proxy', host: 'Exchange-Host', users: 'Orvanta-Nutzer',
        ai: 'KI-Endpunkt', cache: 'Zwischenspeicher', tier: 'Speicher-Tier'
    };
    var KIND_RADIUS = { source: 15, proxy: 22, host: 18, users: 27, ai: 16, cache: 16, tier: 13 };
    var FOCAL = 1150;
    var MAX_PARTICLES_PER_EDGE = 14;
    var AMBIENT_PER_NODE = 10;
    var STARS = 220;
    var IDLE_BEFORE_ROTATE = 4000;
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
    var hint = root.querySelector('[data-topo-hint]');
    var overallBox = root.querySelector('[data-topo-overall]');
    var overallPulse = root.querySelector('[data-topo-overall-pulse]');
    var kindFilter = root.querySelector('[data-topo-kind-filter]');

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
        return STATES.indexOf(value) === -1 ? 'off' : value;
    }

    function timeNow() {
        var d = new Date();
        function two(n) { return (n < 10 ? '0' : '') + n; }
        return two(d.getHours()) + ':' + two(d.getMinutes()) + ':' + two(d.getSeconds());
    }

    // ------------------------------------------------------------ Zufall (deterministisch)

    function hashString(value) {
        var h = 2166136261;
        for (var i = 0; i < value.length; i++) {
            h ^= value.charCodeAt(i);
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

    var graph = { nodes: {}, order: [], edges: [], ambient: [] };
    var stars = [];
    var effects = [];
    var view = {
        mode3d: true,
        mix: 1,              // 1 = 3D-Anordnung, 0 = 2D-Anordnung (weich ueberblendet)
        yaw: -0.35, pitch: 0.22, zoom: 1, panX: 0, panY: 0,
        tYaw: -0.35, tPitch: 0.22, tZoom: 1, tPanX: 0, tPanY: 0,
        rotate: !reducedMotion,
        particles: !reducedMotion,
        labels: true,
        focusProblems: false,
        hiddenKinds: {},
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

    function parseInitial() {
        var holder = root.querySelector('[data-flow-initial]');
        if (!holder) {
            return null;
        }
        try {
            return JSON.parse(holder.textContent || 'null');
        } catch (error) {
            return null;
        }
    }

    function activityOf(node, data) {
        var sum = 0;
        var cloud = Array.isArray(node.cloud) ? node.cloud : [];
        cloud.forEach(function (entry) {
            sum += Math.max(0, parseInt(entry && entry.value, 10) || 0);
        });
        if (node.kind === 'users') {
            sum = Math.max(sum, parseInt(data && data.presence && data.presence.current, 10) || 0);
        }
        if (node.kind === 'proxy') {
            sum = Math.max(sum, parseInt(data && data.presence && data.presence.proxy, 10) || 0, 1);
        }
        if (node.kind === 'ai') {
            sum = Math.round((parseInt(data && data.ai && data.ai.requests, 10) || 0) / 30);
        }
        if (node.kind === 'tier') {
            sum = 2;
        }
        if (node.state === 'off') {
            return 0;
        }
        return Math.max(node.state === 'error' ? 2 : 1, sum);
    }

    /**
     * Knoten/Kanten aus dem JSON uebernehmen. Bestehende Knoten behalten ihre
     * Position (ruhige Darstellung); neue bekommen eine im Layout.
     */
    function ingest(data) {
        if (!data || typeof data !== 'object') {
            return;
        }
        var incoming = data.nodes && typeof data.nodes === 'object' ? data.nodes : {};
        var seen = {};
        var layoutDirty = false;

        Object.keys(incoming).forEach(function (key) {
            var raw = incoming[key] || {};
            var kind = typeof raw.kind === 'string' ? raw.kind : 'users';
            var node = graph.nodes[key];
            if (!node) {
                node = {
                    key: key, kind: kind, pos3: { x: 0, y: 0, z: 0 }, pos2: { x: 0, y: 0 },
                    sx: 0, sy: 0, sr: 0, depth: 0, visible: true, pulse: Math.random() * Math.PI * 2
                };
                graph.nodes[key] = node;
                graph.order.push(key);
                layoutDirty = true;
            }
            node.kind = kind;
            node.title = String(raw.title || key);
            node.subtitle = String(raw.subtitle || '');
            node.state = stateOf(raw.state);
            node.stateLabel = String(raw.state_label || STATE_LABELS[node.state]);
            node.message = String(raw.message || '');
            node.muted = raw.muted === true;
            node.mutedLabel = String(raw.muted_label || '');
            node.primary = raw.primary === true;
            var transport = String(raw.transport || '');
            if (node.transport !== undefined && node.transport !== transport) {
                layoutDirty = true;
            }
            node.transport = transport;
            node.facts = Array.isArray(raw.facts) ? raw.facts : [];
            node.cloud = Array.isArray(raw.cloud) ? raw.cloud : [];
            node.cloudTitle = String(raw.cloud_title || '');
            node.members = Array.isArray(raw.members) ? raw.members : [];
            node.link = raw.link && typeof raw.link === 'object' ? raw.link : null;
            node.counters = countersOf(raw.counters);
            node.activity = activityOf(node, data);
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
            var from = String(raw.from || '');
            var to = String(raw.to || '');
            if (!graph.nodes[from] || !graph.nodes[to]) {
                return;
            }
            edges.push(makeEdge(from, to, raw.state === 'error' ? 'error' : 'ok', false));
        });
        // Speicher-Tiers ohne eigene Kante im JSON (aeltere Antworten) speisen den Zwischenspeicher.
        var hasOutgoing = {};
        edges.forEach(function (edge) {
            hasOutgoing[edge.from] = true;
        });
        graph.order.forEach(function (key) {
            var node = graph.nodes[key];
            if (node.kind === 'tier' && graph.nodes.cache && !hasOutgoing[key]) {
                edges.push(makeEdge(key, 'cache', node.state === 'error' ? 'error' : 'ok', true));
            }
        });
        var old = {};
        graph.edges.forEach(function (edge) {
            old[edge.id] = edge;
        });
        graph.edges = edges.map(function (edge) {
            var prior = old[edge.id];
            if (prior) {
                prior.state = edge.state;
                prior.synthetic = edge.synthetic;
                return prior;
            }
            return edge;
        });
        graph.edges.forEach(function (edge) {
            var from = graph.nodes[edge.from];
            var to = graph.nodes[edge.to];
            var fromState = from.state === 'off' || to.state === 'off' ? 'off' : edge.state;
            if (fromState !== 'off' && (from.state === 'error' || to.state === 'error')) {
                fromState = 'error';
            } else if (fromState !== 'off' && (from.state === 'warn' || to.state === 'warn')) {
                fromState = edge.state === 'error' ? 'error' : 'warn';
            }
            edge.drawState = fromState;
            edge.activity = Math.min(from.activity, Math.max(1, to.activity));
            syncParticles(edge);
        });

        if (layoutDirty) {
            layout();
        }
        setField('node-count', graph.order.length, true);
    }

    function makeEdge(from, to, state, synthetic) {
        var seed = rng(hashString(from + '>' + to));
        return {
            id: from + '>' + to, from: from, to: to, state: state, drawState: state, synthetic: synthetic,
            bend: (seed() - 0.5) * 0.9, lift: (seed() - 0.5) * 120, particles: [], activity: 1
        };
    }

    function syncParticles(edge) {
        var wanted = 0;
        if (edge.drawState === 'ok') {
            wanted = Math.min(MAX_PARTICLES_PER_EDGE, 1 + Math.round(Math.log(edge.activity + 1) * 2.4));
        } else if (edge.drawState === 'warn') {
            wanted = Math.min(6, 1 + Math.round(Math.log(edge.activity + 1)));
        } else if (edge.drawState === 'error') {
            wanted = 4;
        }
        while (edge.particles.length < wanted) {
            edge.particles.push({ t: Math.random(), speed: 0.08 + Math.random() * 0.12, size: 1.4 + Math.random() * 1.8, phase: Math.random() });
        }
        if (edge.particles.length > wanted) {
            edge.particles.length = wanted;
        }
    }

    // ------------------------------------------------------------ Anordnung

    /**
     * Zaehler am Knotenrand (oben rechts = aktuell, oben links = 24 h).
     */
    function countersOf(raw) {
        var result = { current: null, peak: null };
        if (!raw || typeof raw !== 'object') {
            return result;
        }
        ['current', 'peak'].forEach(function (slot) {
            var entry = raw[slot];
            if (entry && typeof entry === 'object' && entry.value !== undefined && entry.value !== null) {
                result[slot] = { value: Math.max(0, parseInt(entry.value, 10) || 0), label: String(entry.label || '') };
            }
        });
        return result;
    }

    function byKind(kind) {
        return graph.order.filter(function (key) {
            return graph.nodes[key].kind === kind;
        });
    }

    function ring(keys, center, radius, plane, seedKey) {
        var n = keys.length;
        keys.forEach(function (key, i) {
            var node = graph.nodes[key];
            var r = rng(hashString(seedKey + key));
            var a = (i / Math.max(1, n)) * Math.PI * 2 + (n > 1 ? 0.3 : 0);
            var jitter = { x: (r() - 0.5) * 40, y: (r() - 0.5) * 30, z: (r() - 0.5) * 40 };
            var rad = n === 1 ? 0 : radius;
            if (plane === 'yz') {
                node.pos3 = { x: center.x + jitter.x, y: center.y + Math.cos(a) * rad + jitter.y, z: center.z + Math.sin(a) * rad + jitter.z };
            } else {
                node.pos3 = { x: center.x + Math.cos(a) * rad + jitter.x, y: center.y + jitter.y, z: center.z + Math.sin(a) * rad + jitter.z };
            }
        });
    }

    function column(keys, x, yCenter, gap) {
        var n = keys.length;
        keys.forEach(function (key, i) {
            graph.nodes[key].pos2 = { x: x, y: yCenter + (i - (n - 1) / 2) * gap };
        });
    }

    function row(keys, y, xCenter, gap) {
        var n = keys.length;
        keys.forEach(function (key, i) {
            graph.nodes[key].pos2 = { x: xCenter + (i - (n - 1) / 2) * gap, y: y };
        });
    }

    /**
     * 3D: Nutzer im Zentrum, Proxy links davor mit den Proxy-Quellen als Wolke
     * dahinter, Hosts rechts als Ring mit den Exchange-Quellen (ohne Proxy)
     * dahinter, KI oben, Zwischenspeicher unten mit den Tiers darunter.
     * 2D: klassisches Netzdiagramm in Spalten.
     */
    function layout() {
        var allSources = byKind('source');
        var sources = allSources.filter(function (key) {
            return graph.nodes[key].transport !== 'exchange';
        });
        var exchangeSources = allSources.filter(function (key) {
            return graph.nodes[key].transport === 'exchange';
        });
        var hosts = byKind('host');
        var allTiers = byKind('tier');
        // Lokaler VM-Speicher sitzt direkt unter dem Zwischenspeicher, Cold-Tiers
        // und Snapshot-Speicher haengen als Kinder darunter.
        var hasLocal = !!graph.nodes['tier-local'];
        var tiers = allTiers.filter(function (key) {
            return key !== 'tier-local';
        });
        var tierDepth = hasLocal ? -480 : -360;
        var set = function (key, x, y, z) {
            if (graph.nodes[key]) {
                graph.nodes[key].pos3 = { x: x, y: y, z: z };
            }
        };

        set('users', 0, 0, 0);
        set('proxy', -230, 10, 40);
        set('ai', 90, 215, -140);
        set('cache', 40, -215, 90);
        set('tier-local', 40, -360, 90);
        ring(sources, { x: -450, y: 0, z: 0 }, Math.min(230, 70 + sources.length * 28), 'yz', 'src');
        ring(hosts, { x: 290, y: 0, z: 0 }, Math.min(220, 60 + hosts.length * 26), 'yz', 'host');
        ring(exchangeSources, { x: 510, y: 0, z: 0 }, Math.min(230, 70 + exchangeSources.length * 28), 'yz', 'xsrc');
        ring(tiers, { x: 60, y: tierDepth, z: 60 }, Math.min(240, 60 + tiers.length * 30), 'xz', 'tier');

        var set2 = function (key, x, y) {
            if (graph.nodes[key]) {
                graph.nodes[key].pos2 = { x: x, y: y };
            }
        };
        column(sources, -520, 0, 96);
        set2('proxy', -260, 0);
        set2('users', 0, 0);
        column(hosts, 300, 0, 100);
        column(exchangeSources, 560, 0, 96);
        set2('ai', 0, -220);
        set2('cache', 0, 220);
        set2('tier-local', 0, 380);
        row(tiers, hasLocal ? 520 : 380, 0, 150);

        graph.ambient = [];
        graph.order.forEach(function (key) {
            var node = graph.nodes[key];
            var r = rng(hashString('amb' + key));
            var count = node.kind === 'users' ? AMBIENT_PER_NODE * 2 : AMBIENT_PER_NODE;
            for (var i = 0; i < count; i++) {
                var theta = r() * Math.PI * 2;
                var phi = Math.acos(2 * r() - 1);
                var dist = 36 + r() * 70;
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
        var r = rng(20240917);
        stars = [];
        for (var i = 0; i < STARS; i++) {
            stars.push({ x: (r() - 0.5) * 2600, y: (r() - 0.5) * 1800, z: (r() - 0.5) * 2200, size: 0.4 + r() * 1.3, tw: r() * Math.PI * 2 });
        }
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

    function nodeAlpha(node) {
        if (view.hiddenKinds[node.kind]) {
            return 0;
        }
        var alpha = node.muted ? 0.5 : 1;
        if (view.focusProblems && node.state !== 'error') {
            alpha *= 0.18;
        }
        if (view.selected && view.selected !== node.key && !isNeighbour(view.selected, node.key)) {
            alpha *= 0.55;
        }
        return alpha;
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

    function edgeAlpha(edge) {
        var from = graph.nodes[edge.from];
        var to = graph.nodes[edge.to];
        var alpha = Math.min(nodeAlpha(from), nodeAlpha(to));
        if (view.selected && edge.from !== view.selected && edge.to !== view.selected) {
            alpha *= 0.45;
        }
        if (view.focusProblems && edge.drawState !== 'error') {
            alpha *= 0.3;
        }
        return alpha;
    }

    function controlPoint(edge, a, b) {
        var mx = (a.x + b.x) / 2;
        var my = (a.y + b.y) / 2;
        var mz = (a.z + b.z) / 2;
        var dx = b.x - a.x, dy = b.y - a.y, dz = b.z - a.z;
        var len = Math.sqrt(dx * dx + dy * dy + dz * dz) || 1;
        // Senkrechte in der x/y-Ebene plus Hub in z fuer organische Boegen.
        var nx = -dy / len, ny = dx / len;
        return { x: mx + nx * edge.bend * len * 0.5, y: my + ny * edge.bend * len * 0.5, z: mz + edge.lift * view.mix };
    }

    function controlPoint2(edge, a, b) {
        var mx = (a.x + b.x) / 2;
        var my = (a.y + b.y) / 2;
        var dx = b.x - a.x, dy = b.y - a.y;
        var len = Math.sqrt(dx * dx + dy * dy) || 1;
        return { x: mx + (-dy / len) * edge.bend * len * 0.35, y: my + (dx / len) * edge.bend * len * 0.35 };
    }

    function bezier(p0, p1, p2, t) {
        var u = 1 - t;
        return { x: u * u * p0.x + 2 * u * t * p1.x + t * t * p2.x, y: u * u * p0.y + 2 * u * t * p1.y + t * t * p2.y };
    }

    function drawBackground(time) {
        ctx.clearRect(0, 0, view.width, view.height);
        // Sterne mit Parallaxe (nur 3D-Anteil).
        var alphaBase = 0.25 + 0.45 * view.mix;
        for (var i = 0; i < stars.length; i++) {
            var s = stars[i];
            var p = project(s, { x: s.x * 0.4, y: s.y * 0.4 });
            if (p.behind || p.x < -10 || p.y < -10 || p.x > view.width + 10 || p.y > view.height + 10) {
                continue;
            }
            var tw = 0.5 + 0.5 * Math.sin(time * 0.0012 + s.tw);
            ctx.globalAlpha = alphaBase * (0.25 + 0.75 * tw) * Math.min(1, p.s);
            ctx.fillStyle = '#9fb7e8';
            ctx.beginPath();
            ctx.arc(p.x, p.y, s.size * Math.min(1.6, p.s), 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.globalAlpha = 1;
    }

    function drawAmbient(time) {
        if (view.mix < 0.05) {
            return;
        }
        for (var i = 0; i < graph.ambient.length; i++) {
            var a = graph.ambient[i];
            var node = graph.nodes[a.node];
            if (!node) {
                continue;
            }
            var alpha = nodeAlpha(node);
            if (alpha <= 0) {
                continue;
            }
            var wob = Math.sin(time * 0.0009 + a.phase) * 4;
            var p = project({ x: node.pos3.x + a.dx + wob, y: node.pos3.y + a.dy - wob, z: node.pos3.z + a.dz }, null);
            if (p.behind) {
                continue;
            }
            ctx.globalAlpha = alpha * view.mix * (0.18 + 0.2 * (0.5 + 0.5 * Math.sin(time * 0.002 + a.phase)));
            ctx.fillStyle = node.state === 'off' ? STATE_COLORS.off : KIND_COLORS[node.kind] || '#fff';
            ctx.beginPath();
            ctx.arc(p.x, p.y, a.size * p.s, 0, Math.PI * 2);
            ctx.fill();
            // feine Dendriten zum Knoten
            if (a.size > 1.5) {
                var n = project(node.pos3, node.pos2);
                ctx.globalAlpha *= 0.5;
                ctx.strokeStyle = ctx.fillStyle;
                ctx.lineWidth = 0.5;
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
                ctx.lineTo(n.x, n.y);
                ctx.stroke();
            }
        }
        ctx.globalAlpha = 1;
    }

    function drawEdges(time, dt) {
        for (var i = 0; i < graph.edges.length; i++) {
            var edge = graph.edges[i];
            var from = graph.nodes[edge.from];
            var to = graph.nodes[edge.to];
            if (!from || !to) {
                continue;
            }
            var alpha = edgeAlpha(edge);
            if (alpha <= 0) {
                continue;
            }
            var a = project(from.pos3, from.pos2);
            var b = project(to.pos3, to.pos2);
            var c3 = controlPoint(edge, from.pos3, to.pos3);
            var c2 = controlPoint2(edge, from.pos2, to.pos2);
            var c = project(c3, c2);
            var state = edge.drawState;
            var color = STATE_RGB[state] || STATE_RGB.off;
            var depthFade = Math.max(0.35, Math.min(1, (a.s + b.s) / 2));
            var width = (edge.synthetic ? 0.9 : 1.4) * Math.min(2.2, 0.8 + Math.log(edge.activity + 1) * 0.4) * Math.max(0.6, (a.s + b.s) / 2);

            ctx.globalAlpha = alpha * depthFade * (state === 'off' ? 0.35 : 0.75);
            ctx.lineWidth = width;
            ctx.strokeStyle = 'rgba(' + color + ',1)';
            if (edge.synthetic) {
                ctx.setLineDash([4, 6]);
            } else if (state === 'error') {
                ctx.setLineDash([8, 6]);
                ctx.lineDashOffset = -time * 0.02;
            } else {
                ctx.setLineDash([]);
            }
            ctx.beginPath();
            ctx.moveTo(a.x, a.y);
            ctx.quadraticCurveTo(c.x, c.y, b.x, b.y);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.lineDashOffset = 0;

            // Leuchtspur
            if (state !== 'off') {
                ctx.globalAlpha = alpha * depthFade * 0.18;
                ctx.lineWidth = width * 4;
                ctx.stroke();
            }

            if (state === 'error') {
                // Bruchstelle: rotes Kreuz auf halber Strecke
                var mid = bezier(a, c, b, 0.5);
                var r = 6 * Math.max(0.6, (a.s + b.s) / 2);
                var pulse = 0.6 + 0.4 * Math.sin(time * 0.008);
                ctx.globalAlpha = alpha * pulse;
                ctx.strokeStyle = STATE_COLORS.error;
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(mid.x - r, mid.y - r); ctx.lineTo(mid.x + r, mid.y + r);
                ctx.moveTo(mid.x + r, mid.y - r); ctx.lineTo(mid.x - r, mid.y + r);
                ctx.stroke();
            }

            if (view.particles && state !== 'off') {
                drawParticles(edge, a, c, b, alpha * depthFade, dt, time);
            }
        }
        ctx.globalAlpha = 1;
    }

    function drawParticles(edge, a, c, b, alpha, dt, time) {
        var state = edge.drawState;
        var color = STATE_RGB[state] || STATE_RGB.off;
        var speedFactor = state === 'warn' ? 0.45 : 1;
        for (var i = 0; i < edge.particles.length; i++) {
            var p = edge.particles[i];
            if (!reducedMotion) {
                p.t += p.speed * speedFactor * dt;
            }
            var fade = 1;
            if (state === 'error') {
                // Partikel verloeschen vor der Bruchstelle und starten neu.
                if (p.t > 0.46) {
                    p.t = 0;
                }
                fade = p.t > 0.3 ? 1 - (p.t - 0.3) / 0.16 : 1;
            } else if (p.t > 1) {
                p.t -= 1;
            }
            var pt = bezier(a, c, b, p.t);
            var size = p.size * Math.max(0.5, (a.s + b.s) / 2) * (state === 'warn' ? 0.8 : 1);
            ctx.globalAlpha = alpha * fade * (0.6 + 0.4 * Math.sin(time * 0.01 + p.phase * 6));
            ctx.fillStyle = 'rgba(' + color + ',1)';
            ctx.beginPath();
            ctx.arc(pt.x, pt.y, size, 0, Math.PI * 2);
            ctx.fill();
            ctx.globalAlpha *= 0.35;
            ctx.beginPath();
            ctx.arc(pt.x, pt.y, size * 2.6, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    function drawGlyph(kind, x, y, r, color) {
        ctx.strokeStyle = color;
        ctx.fillStyle = color;
        ctx.lineWidth = Math.max(1, r * 0.14);
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        var s = r * 0.46;
        ctx.beginPath();
        switch (kind) {
            case 'proxy':
                ctx.moveTo(x - s, y - s * 0.4); ctx.lineTo(x + s, y - s * 0.4); ctx.lineTo(x + s * 0.55, y - s * 0.85);
                ctx.moveTo(x + s, y + s * 0.4); ctx.lineTo(x - s, y + s * 0.4); ctx.lineTo(x - s * 0.55, y + s * 0.85);
                ctx.stroke();
                break;
            case 'host':
                ctx.rect(x - s, y - s * 0.75, s * 2, s * 0.6);
                ctx.rect(x - s, y + s * 0.15, s * 2, s * 0.6);
                ctx.stroke();
                ctx.beginPath();
                ctx.arc(x + s * 0.6, y - s * 0.45, s * 0.14, 0, Math.PI * 2);
                ctx.arc(x + s * 0.6, y + s * 0.45, s * 0.14, 0, Math.PI * 2);
                ctx.fill();
                break;
            case 'source':
                ctx.arc(x - s * 0.35, y + s * 0.15, s * 0.5, Math.PI * 0.5, Math.PI * 1.5);
                ctx.arc(x + s * 0.05, y - s * 0.25, s * 0.55, Math.PI, Math.PI * 1.95);
                ctx.arc(x + s * 0.5, y + s * 0.15, s * 0.5, Math.PI * 1.5, Math.PI * 0.5);
                ctx.closePath();
                ctx.stroke();
                break;
            case 'users':
                ctx.arc(x, y - s * 0.35, s * 0.42, 0, Math.PI * 2);
                ctx.stroke();
                ctx.beginPath();
                ctx.arc(x, y + s * 0.9, s * 0.95, Math.PI * 1.15, Math.PI * 1.85);
                ctx.stroke();
                break;
            case 'ai':
                ctx.moveTo(x, y - s); ctx.quadraticCurveTo(x, y, x + s, y); ctx.quadraticCurveTo(x, y, x, y + s);
                ctx.quadraticCurveTo(x, y, x - s, y); ctx.quadraticCurveTo(x, y, x, y - s);
                ctx.fill();
                break;
            case 'cache':
                ctx.ellipse(x, y - s * 0.55, s, s * 0.35, 0, 0, Math.PI * 2);
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(x - s, y - s * 0.55); ctx.lineTo(x - s, y + s * 0.55);
                ctx.ellipse(x, y + s * 0.55, s, s * 0.35, 0, Math.PI, 0, true);
                ctx.lineTo(x + s, y - s * 0.55);
                ctx.stroke();
                break;
            case 'tier':
                ctx.rect(x - s, y - s * 0.8, s * 2, s * 0.45);
                ctx.rect(x - s, y - s * 0.15, s * 2, s * 0.45);
                ctx.rect(x - s, y + s * 0.5, s * 2, s * 0.45);
                ctx.stroke();
                break;
            default:
                ctx.arc(x, y, s * 0.5, 0, Math.PI * 2);
                ctx.fill();
        }
    }

    function drawNodes(time) {
        var sorted = graph.order.slice().sort(function (ka, kb) {
            return graph.nodes[ka].depth - graph.nodes[kb].depth;
        });
        for (var i = 0; i < sorted.length; i++) {
            var node = graph.nodes[sorted[i]];
            var alpha = nodeAlpha(node);
            node.visible = alpha > 0;
            if (!node.visible) {
                node.sr = 0;
                continue;
            }
            var p = project(node.pos3, node.pos2);
            var base = KIND_RADIUS[node.kind] || 16;
            var r = base * p.s;
            node.sx = p.x;
            node.sy = p.y;
            node.sr = r;
            node.depth = p.depth;
            var state = node.state;
            var color = STATE_COLORS[state];
            var rgb = STATE_RGB[state];
            var kindColor = KIND_COLORS[node.kind] || '#fff';
            var hovered = view.hovered === node.key;
            var selected = view.selected === node.key;
            var depthFade = Math.max(0.4, Math.min(1, p.s));

            // Aura
            var auraPulse = 1;
            if (state === 'error') {
                auraPulse = 1 + 0.35 * Math.sin(time * 0.009 + node.pulse);
            } else if (state === 'warn') {
                auraPulse = 1 + 0.15 * Math.sin(time * 0.004 + node.pulse);
            } else if (state === 'ok') {
                auraPulse = 1 + 0.05 * Math.sin(time * 0.002 + node.pulse);
            }
            var auraR = r * 2.4 * auraPulse;
            var grad = ctx.createRadialGradient(p.x, p.y, r * 0.6, p.x, p.y, auraR);
            grad.addColorStop(0, 'rgba(' + rgb + ',' + (state === 'off' ? 0.12 : 0.45) + ')');
            grad.addColorStop(1, 'rgba(' + rgb + ',0)');
            ctx.globalAlpha = alpha * depthFade;
            ctx.fillStyle = grad;
            ctx.beginPath();
            ctx.arc(p.x, p.y, auraR, 0, Math.PI * 2);
            ctx.fill();

            // Stoerung: expandierende Ringe
            if (state === 'error' && !reducedMotion) {
                for (var k = 0; k < 2; k++) {
                    var phase = ((time * 0.0007 + node.pulse / 6 + k * 0.5) % 1);
                    ctx.globalAlpha = alpha * (1 - phase) * 0.7;
                    ctx.strokeStyle = color;
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, r * (1.1 + phase * 2.2), 0, Math.PI * 2);
                    ctx.stroke();
                }
            }

            // Kern
            ctx.globalAlpha = alpha;
            var core = ctx.createRadialGradient(p.x - r * 0.3, p.y - r * 0.35, r * 0.1, p.x, p.y, r);
            core.addColorStop(0, node.muted ? '#2b3650' : '#1a2440');
            core.addColorStop(1, node.muted ? '#0b1120' : '#070c18');
            ctx.fillStyle = core;
            ctx.beginPath();
            ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
            ctx.fill();

            // Zustandsring + Bausteinfarbe innen
            ctx.lineWidth = Math.max(1.5, r * 0.16);
            ctx.strokeStyle = color;
            ctx.stroke();
            ctx.lineWidth = Math.max(1, r * 0.06);
            ctx.strokeStyle = node.muted ? STATE_COLORS.off : kindColor;
            ctx.beginPath();
            ctx.arc(p.x, p.y, r * 0.78, 0, Math.PI * 2);
            ctx.stroke();

            drawGlyph(node.kind, p.x, p.y, r, node.muted ? STATE_COLORS.off : kindColor);

            if (node.primary) {
                ctx.fillStyle = kindColor;
                ctx.beginPath();
                ctx.arc(p.x + r * 0.72, p.y - r * 0.72, Math.max(2, r * 0.18), 0, Math.PI * 2);
                ctx.fill();
            }

            if (state === 'error') {
                var br = Math.max(5, r * 0.36);
                ctx.fillStyle = color;
                ctx.beginPath();
                ctx.arc(p.x + r * 0.75, p.y - r * 0.75, br, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = '#fff';
                ctx.font = '700 ' + Math.round(br * 1.5) + 'px system-ui, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('!', p.x + r * 0.75, p.y - r * 0.72);
            }

            drawCounters(node, p, r, alpha * depthFade, kindColor, hovered || selected);

            if (hovered || selected) {
                ctx.strokeStyle = '#fff';
                ctx.lineWidth = 1.5;
                ctx.setLineDash(selected ? [5, 5] : []);
                ctx.lineDashOffset = -time * 0.02;
                ctx.beginPath();
                ctx.arc(p.x, p.y, r * 1.35, 0, Math.PI * 2);
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.lineDashOffset = 0;
            }

            if (view.labels && (p.s > 0.45 || hovered || selected)) {
                var fontSize = Math.max(9, Math.min(15, 12 * p.s));
                ctx.font = (selected || hovered ? '700 ' : '600 ') + fontSize + 'px system-ui, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'top';
                var label = node.title;
                if (label.length > 28) {
                    label = label.slice(0, 26) + '…';
                }
                var tw = ctx.measureText(label).width;
                ctx.globalAlpha = alpha * Math.min(1, depthFade + 0.3);
                ctx.fillStyle = 'rgba(6,11,22,0.75)';
                roundRect(p.x - tw / 2 - 6, p.y + r + 6, tw + 12, fontSize + 8, 5);
                ctx.fill();
                ctx.fillStyle = state === 'off' ? '#93a4c4' : '#e6eefc';
                ctx.fillText(label, p.x, p.y + r + 10);
                if (p.s > 0.9 || hovered || selected) {
                    ctx.font = '500 ' + Math.max(8, fontSize - 3) + 'px system-ui, sans-serif';
                    ctx.fillStyle = color;
                    ctx.fillText(node.stateLabel, p.x, p.y + r + fontSize + 14);
                }
            }
        }
        ctx.globalAlpha = 1;
    }

    /**
     * Kennzahlen-Pillen am Badge: aktueller Wert oben rechts, 24-h-Wert oben links.
     * Nur bei ausreichender Groesse bzw. Hover/Auswahl, damit entfernte Knoten ruhig bleiben.
     */
    function drawCounters(node, p, r, alpha, kindColor, emphasised) {
        var counters = node.counters;
        if (!counters || (!counters.current && !counters.peak)) {
            return;
        }
        if (!view.labels || (p.s < 0.5 && !emphasised)) {
            return;
        }
        var fontSize = Math.max(8, Math.min(13, 11 * p.s));
        var padX = Math.max(4, fontSize * 0.5);
        var h = fontSize + padX;
        var cy = p.y - r * 1.25;
        ctx.font = '700 ' + fontSize + 'px system-ui, sans-serif';
        ctx.textBaseline = 'middle';
        ctx.globalAlpha = Math.min(1, alpha + 0.2);

        if (counters.current) {
            var textR = String(counters.current.value);
            var wR = ctx.measureText(textR).width + padX * 2;
            var xR = p.x + r * 0.95;
            ctx.fillStyle = node.muted ? STATE_COLORS.off : kindColor;
            roundRect(xR, cy - h / 2, wR, h, h / 2);
            ctx.fill();
            ctx.fillStyle = '#070c18';
            ctx.textAlign = 'left';
            ctx.fillText(textR, xR + padX, cy + 0.5);
        }
        if (counters.peak) {
            var textL = String(counters.peak.value);
            var wL = ctx.measureText(textL).width + padX * 2;
            var xL = p.x - r * 0.95 - wL;
            ctx.fillStyle = 'rgba(6,11,22,0.85)';
            roundRect(xL, cy - h / 2, wL, h, h / 2);
            ctx.fill();
            ctx.strokeStyle = node.muted ? STATE_COLORS.off : kindColor;
            ctx.lineWidth = 1;
            ctx.stroke();
            ctx.fillStyle = node.muted ? '#93a4c4' : '#e6eefc';
            ctx.textAlign = 'left';
            ctx.fillText(textL, xL + padX, cy + 0.5);
        }
    }

    function roundRect(x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    function drawEffects(time) {
        var keep = [];
        for (var i = 0; i < effects.length; i++) {
            var fx = effects[i];
            var node = graph.nodes[fx.node];
            var age = (time - fx.start) / fx.duration;
            if (!node || age >= 1) {
                continue;
            }
            keep.push(fx);
            if (!node.visible) {
                continue;
            }
            var color = STATE_RGB[fx.state] || STATE_RGB.off;
            for (var k = 0; k < 3; k++) {
                var a = Math.max(0, age - k * 0.12);
                if (a <= 0) {
                    continue;
                }
                ctx.globalAlpha = (1 - a) * 0.8;
                ctx.strokeStyle = 'rgba(' + color + ',1)';
                ctx.lineWidth = 3 * (1 - a) + 0.5;
                ctx.beginPath();
                ctx.arc(node.sx, node.sy, node.sr * (1 + a * 7), 0, Math.PI * 2);
                ctx.stroke();
            }
            ctx.globalAlpha = Math.max(0, 0.5 - age) * 1.2;
            ctx.fillStyle = 'rgba(' + color + ',1)';
            ctx.beginPath();
            ctx.arc(node.sx, node.sy, node.sr * 1.1, 0, Math.PI * 2);
            ctx.fill();
        }
        effects = keep;
        ctx.globalAlpha = 1;
    }

    function drawVignette() {
        var g = ctx.createRadialGradient(view.width / 2, view.height / 2, Math.min(view.width, view.height) * 0.35, view.width / 2, view.height / 2, Math.max(view.width, view.height) * 0.75);
        g.addColorStop(0, 'rgba(6,11,22,0)');
        g.addColorStop(1, 'rgba(6,11,22,0.75)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, view.width, view.height);
    }

    function frame(time) {
        rafId = null;
        if (document.hidden) {
            return;
        }
        var dt = lastFrame ? Math.min(0.1, (time - lastFrame) / 1000) : 0.016;
        lastFrame = time;

        var ease = reducedMotion ? 1 : 1 - Math.pow(0.001, dt);
        view.yaw = lerp(view.yaw, view.tYaw, ease);
        view.pitch = lerp(view.pitch, view.tPitch, ease);
        view.zoom = lerp(view.zoom, view.tZoom, ease);
        view.panX = lerp(view.panX, view.tPanX, ease);
        view.panY = lerp(view.panY, view.tPanY, ease);
        view.mix = lerp(view.mix, view.mode3d ? 1 : 0, reducedMotion ? 1 : 1 - Math.pow(0.02, dt));

        if (view.rotate && view.mode3d && !reducedMotion && time - view.lastInteraction > IDLE_BEFORE_ROTATE && !dragging) {
            view.tYaw += 0.09 * dt;
        }

        // Ein Zeichenfehler darf die Schleife nicht dauerhaft anhalten –
        // sonst bleibt die Leinwand bis zum Neuladen leer.
        try {
            drawBackground(time);
            drawAmbient(time);
            drawEdges(time, dt);
            drawNodes(time);
            drawEffects(time);
            drawVignette();
        } catch (error) {
            if (!drawErrorLogged && window.console && window.console.error) {
                drawErrorLogged = true;
                window.console.error('Topologie: Zeichenfehler', error);
            }
            ctx.setLineDash([]);
            ctx.lineDashOffset = 0;
            ctx.globalAlpha = 1;
        }

        rafId = window.requestAnimationFrame(frame);
    }

    function start() {
        if (rafId === null && !document.hidden) {
            lastFrame = 0;
            rafId = window.requestAnimationFrame(frame);
        }
    }

    // ------------------------------------------------------------ Treffer

    function hitTest(x, y) {
        var best = null;
        var bestDepth = -Infinity;
        for (var i = 0; i < graph.order.length; i++) {
            var node = graph.nodes[graph.order[i]];
            if (!node.visible || node.sr <= 0) {
                continue;
            }
            var dx = x - node.sx;
            var dy = y - node.sy;
            var radius = Math.max(node.sr * 1.3, 12);
            if (dx * dx + dy * dy <= radius * radius && node.depth >= bestDepth) {
                best = node;
                bestDepth = node.depth;
            }
        }
        return best;
    }

    function localPoint(event) {
        var rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    }

    // ------------------------------------------------------------ Tooltip & Panel

    function showTooltip(node, x, y) {
        if (!tooltip) {
            return;
        }
        tooltip.hidden = false;
        tooltip.setAttribute('aria-hidden', 'false');
        tooltip.querySelector('[data-topo-tooltip-title]').textContent = node.title;
        var st = tooltip.querySelector('[data-topo-tooltip-state]');
        st.textContent = (KIND_LABELS[node.kind] || node.kind) + ' · ' + node.stateLabel;
        swapClass(st, 'topo-tooltip__state--', node.state);
        tooltip.querySelector('[data-topo-tooltip-message]').textContent = node.message || (node.muted ? node.mutedLabel : '');
        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    }

    function hideTooltip() {
        if (tooltip) {
            tooltip.hidden = true;
            tooltip.setAttribute('aria-hidden', 'true');
        }
    }

    function select(key) {
        view.selected = key && graph.nodes[key] ? key : null;
        if (!panel) {
            return;
        }
        if (!view.selected) {
            panel.hidden = true;
            return;
        }
        var node = graph.nodes[view.selected];
        panel.hidden = false;
        panel.querySelector('[data-topo-panel-kind]').textContent = KIND_LABELS[node.kind] || node.kind;
        panel.querySelector('[data-topo-panel-title]').textContent = node.title + (node.primary ? ' · primär' : '');
        var badge = panel.querySelector('[data-topo-panel-badge]');
        badge.textContent = node.stateLabel;
        badge.className = 'badge ' + (STATE_BADGE[node.state] || 'badge--muted');
        panel.querySelector('[data-topo-panel-subtitle]').textContent = node.subtitle;

        var message = panel.querySelector('[data-topo-panel-message]');
        message.textContent = node.message;
        message.hidden = node.message === '';
        message.classList.toggle('topo-panel__message--error', node.state === 'error');

        var muted = panel.querySelector('[data-topo-panel-muted]');
        muted.textContent = node.mutedLabel;
        muted.hidden = node.mutedLabel === '';

        var facts = panel.querySelector('[data-topo-panel-facts]');
        clearChildren(facts);
        node.facts.forEach(function (fact) {
            facts.appendChild(el('dt', null, fact.label || ''));
            var dd = el('dd', null, fact.value === undefined || fact.value === null ? '' : String(fact.value));
            if (fact.state === 'warn' || fact.state === 'error') {
                dd.classList.add('is-' + fact.state);
            }
            facts.appendChild(dd);
        });

        var cloudBox = panel.querySelector('[data-topo-panel-cloud]');
        var cloudList = panel.querySelector('[data-topo-panel-cloud-list]');
        clearChildren(cloudList);
        if (node.cloud.length > 0) {
            cloudBox.hidden = false;
            panel.querySelector('[data-topo-panel-cloud-title]').textContent = node.cloudTitle || 'Einträge';
            var max = 0;
            node.cloud.forEach(function (entry) {
                max = Math.max(max, parseInt(entry.value, 10) || 0);
            });
            node.cloud.slice(0, 12).forEach(function (entry) {
                var li = el('li');
                li.title = String(entry.title || '');
                li.appendChild(el('span', null, entry.label || ''));
                var bar = el('span', 'topo-panel__cloud-bar');
                var fill = el('span');
                fill.style.width = (max > 0 ? Math.round(((parseInt(entry.value, 10) || 0) / max) * 100) : 0) + '%';
                bar.appendChild(fill);
                li.appendChild(bar);
                li.appendChild(el('span', null, entry.value));
                cloudList.appendChild(li);
            });
        } else {
            cloudBox.hidden = true;
        }

        var membersBox = panel.querySelector('[data-topo-panel-members]');
        var membersList = panel.querySelector('[data-topo-panel-members-list]');
        clearChildren(membersList);
        if (node.members.length > 0) {
            membersBox.hidden = false;
            node.members.forEach(function (member) {
                var li = el('li');
                li.title = String(member.title || '');
                li.appendChild(el('span', null, member.label || ''));
                var b = el('span', 'badge ' + (STATE_BADGE[member.state] || 'badge--muted'), member.value || '');
                li.appendChild(b);
                membersList.appendChild(li);
            });
        } else {
            membersBox.hidden = true;
        }

        var neighbours = panel.querySelector('[data-topo-panel-neighbours]');
        clearChildren(neighbours);
        graph.edges.forEach(function (edge) {
            if (edge.from !== node.key && edge.to !== node.key) {
                return;
            }
            var otherKey = edge.from === node.key ? edge.to : edge.from;
            var other = graph.nodes[otherKey];
            if (!other) {
                return;
            }
            var li = el('li');
            var button = el('button', 'topo-neighbour');
            button.type = 'button';
            button.setAttribute('data-topo-focus', otherKey);
            var dot = el('span', 'topo-dot topo-dot--' + edge.drawState);
            dot.setAttribute('aria-hidden', 'true');
            button.appendChild(dot);
            button.appendChild(el('span', 'topo-neighbour__dir', edge.from === node.key ? '→' : '←'));
            button.appendChild(el('span', null, other.title));
            button.appendChild(el('span', 'topo-neighbour__dir', ' · ' + (edge.drawState === 'error' ? 'unterbrochen' : STATE_LABELS[edge.drawState])));
            li.appendChild(button);
            neighbours.appendChild(li);
        });

        var link = panel.querySelector('[data-topo-panel-link]');
        if (node.link && typeof node.link.url === 'string' && /^\/[^/\\]/.test(node.link.url)) {
            link.hidden = false;
            link.href = node.link.url;
            link.textContent = node.link.label || 'Verwalten';
        } else {
            link.hidden = true;
            link.removeAttribute('href');
        }
    }

    function centerOn(key) {
        var node = graph.nodes[key];
        if (!node) {
            return;
        }
        var saved = { panX: view.panX, panY: view.panY };
        view.panX = 0;
        view.panY = 0;
        var p = project(node.pos3, node.pos2);
        view.panX = saved.panX;
        view.panY = saved.panY;
        view.tPanX = view.width / 2 - p.x;
        view.tPanY = view.height / 2 - p.y;
        view.lastInteraction = performance.now();
    }

    function focusNode(key) {
        select(key);
        centerOn(key);
        if (view.tZoom < 1.2) {
            view.tZoom = 1.3;
        }
    }

    // ------------------------------------------------------------ Ansicht steuern

    function fit() {
        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        var saved = { zoom: view.zoom, panX: view.panX, panY: view.panY };
        view.zoom = 1;
        view.panX = 0;
        view.panY = 0;
        graph.order.forEach(function (key) {
            var node = graph.nodes[key];
            if (view.hiddenKinds[node.kind]) {
                return;
            }
            var p = project(node.pos3, node.pos2);
            var r = (KIND_RADIUS[node.kind] || 16) * p.s * 3;
            minX = Math.min(minX, p.x - r); maxX = Math.max(maxX, p.x + r);
            minY = Math.min(minY, p.y - r); maxY = Math.max(maxY, p.y + r + 30);
        });
        view.zoom = saved.zoom;
        view.panX = saved.panX;
        view.panY = saved.panY;
        if (!isFinite(minX)) {
            return;
        }
        var w = maxX - minX, h = maxY - minY;
        var zoom = Math.min(2.4, Math.max(0.3, Math.min((view.width - 260) / w, (view.height - 160) / h)));
        view.tZoom = zoom;
        view.tPanX = (view.width / 2 - (minX + maxX) / 2) * zoom;
        view.tPanY = (view.height / 2 - (minY + maxY) / 2) * zoom;
        view.lastInteraction = performance.now();
    }

    function resetView() {
        view.tYaw = -0.35;
        view.tPitch = 0.22;
        view.tZoom = 1;
        view.tPanX = 0;
        view.tPanY = 0;
        view.lastInteraction = performance.now();
        window.setTimeout(fit, 50);
    }

    function setPressed(action, on) {
        var button = root.querySelector('[data-topo-action="' + action + '"]');
        if (button) {
            button.classList.toggle('is-active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        }
    }

    function setMode(mode3d) {
        view.mode3d = mode3d;
        setPressed('mode-3d', mode3d);
        setPressed('mode-2d', !mode3d);
        if (!mode3d) {
            view.tYaw = 0;
            view.tPitch = 0;
        } else if (view.tYaw === 0 && view.tPitch === 0) {
            view.tYaw = -0.35;
            view.tPitch = 0.22;
        }
        view.lastInteraction = performance.now();
        window.setTimeout(fit, reducedMotion ? 0 : 450);
    }

    var actions = {
        'refresh': function () { load(true); },
        'fullscreen': function () {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (root.requestFullscreen) {
                root.requestFullscreen();
            }
        },
        'mode-3d': function () { setMode(true); },
        'mode-2d': function () { setMode(false); },
        'rotate': function () { view.rotate = !view.rotate; setPressed('rotate', view.rotate); view.lastInteraction = 0; },
        'particles': function () { view.particles = !view.particles; setPressed('particles', view.particles); },
        'labels': function () { view.labels = !view.labels; setPressed('labels', view.labels); },
        'focus-problems': function () { view.focusProblems = !view.focusProblems; setPressed('focus-problems', view.focusProblems); },
        'zoom-in': function () { view.tZoom = Math.min(4, view.tZoom * 1.25); view.lastInteraction = performance.now(); },
        'zoom-out': function () { view.tZoom = Math.max(0.2, view.tZoom / 1.25); view.lastInteraction = performance.now(); },
        'fit': fit,
        'reset': resetView,
        'close-panel': function () { select(null); },
        'toggle-log': function () {
            if (!logBox) {
                return;
            }
            var collapsed = logBox.classList.toggle('is-collapsed');
            var toggle = logBox.querySelector('[data-topo-action="toggle-log"]');
            toggle.textContent = collapsed ? 'Ausklappen' : 'Einklappen';
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    };

    root.addEventListener('click', function (event) {
        var target = event.target.closest ? event.target.closest('[data-topo-action], [data-topo-focus]') : null;
        if (!target) {
            return;
        }
        if (target.hasAttribute('data-topo-focus')) {
            focusNode(target.getAttribute('data-topo-focus'));
            return;
        }
        var action = target.getAttribute('data-topo-action');
        if (actions[action]) {
            actions[action]();
        }
    });

    if (kindFilter) {
        kindFilter.addEventListener('change', function (event) {
            var input = event.target;
            if (!input || input.type !== 'checkbox') {
                return;
            }
            if (input.checked) {
                delete view.hiddenKinds[input.value];
            } else {
                view.hiddenKinds[input.value] = true;
            }
            if (view.selected && view.hiddenKinds[graph.nodes[view.selected].kind]) {
                select(null);
            }
        });
    }

    // ------------------------------------------------------------ Zeiger

    var dragging = false;
    var dragMode = 'rotate';
    var dragStart = null;
    var dragMoved = false;
    var pointers = {};
    var pinchStart = null;
    var suppressClick = false;

    function fadeHint() {
        if (hint && !hint.classList.contains('is-faded')) {
            window.setTimeout(function () {
                hint.classList.add('is-faded');
            }, 1500);
        }
    }

    canvas.addEventListener('contextmenu', function (event) {
        event.preventDefault();
    });

    canvas.addEventListener('pointerdown', function (event) {
        canvas.focus({ preventScroll: true });
        pointers[event.pointerId] = localPoint(event);
        var ids = Object.keys(pointers);
        if (ids.length === 2) {
            var a = pointers[ids[0]], b = pointers[ids[1]];
            pinchStart = { dist: Math.hypot(a.x - b.x, a.y - b.y), zoom: view.tZoom };
            dragging = false;
            return;
        }
        dragging = true;
        dragMoved = false;
        dragMode = (event.button === 2 || event.button === 1 || event.shiftKey || !view.mode3d) ? 'pan' : 'rotate';
        dragStart = { x: event.clientX, y: event.clientY, yaw: view.tYaw, pitch: view.tPitch, panX: view.tPanX, panY: view.tPanY };
        canvas.classList.add(dragMode === 'pan' ? 'is-panning' : 'is-dragging');
        canvas.setPointerCapture(event.pointerId);
        view.lastInteraction = performance.now();
        fadeHint();
    });

    canvas.addEventListener('pointermove', function (event) {
        var point = localPoint(event);
        if (pointers[event.pointerId]) {
            pointers[event.pointerId] = point;
        }
        var ids = Object.keys(pointers);
        if (ids.length === 2 && pinchStart) {
            var a = pointers[ids[0]], b = pointers[ids[1]];
            var dist = Math.hypot(a.x - b.x, a.y - b.y);
            view.tZoom = Math.min(4, Math.max(0.2, pinchStart.zoom * (dist / Math.max(1, pinchStart.dist))));
            view.lastInteraction = performance.now();
            return;
        }
        if (dragging && dragStart) {
            var dx = event.clientX - dragStart.x;
            var dy = event.clientY - dragStart.y;
            if (Math.abs(dx) + Math.abs(dy) > 3) {
                dragMoved = true;
            }
            if (dragMode === 'rotate') {
                view.tYaw = dragStart.yaw + dx * 0.006;
                view.tPitch = Math.max(-1.2, Math.min(1.2, dragStart.pitch + dy * 0.005));
                if (reducedMotion) {
                    view.yaw = view.tYaw;
                    view.pitch = view.tPitch;
                }
            } else {
                view.tPanX = dragStart.panX + dx;
                view.tPanY = dragStart.panY + dy;
            }
            view.lastInteraction = performance.now();
            hideTooltip();
            return;
        }
        var hit = hitTest(point.x, point.y);
        var key = hit ? hit.key : null;
        if (key !== view.hovered) {
            view.hovered = key;
            canvas.classList.toggle('is-hovering', !!key);
        }
        if (hit) {
            showTooltip(hit, point.x, point.y - hit.sr);
        } else {
            hideTooltip();
        }
    });

    function endPointer(event) {
        delete pointers[event.pointerId];
        if (Object.keys(pointers).length < 2) {
            pinchStart = null;
        }
        if (!dragging) {
            return;
        }
        dragging = false;
        canvas.classList.remove('is-dragging', 'is-panning');
        if (canvas.hasPointerCapture && canvas.hasPointerCapture(event.pointerId)) {
            canvas.releasePointerCapture(event.pointerId);
        }
        if (dragMoved) {
            suppressClick = true;
            window.setTimeout(function () { suppressClick = false; }, 0);
        }
    }

    canvas.addEventListener('pointerup', endPointer);
    canvas.addEventListener('pointercancel', endPointer);
    window.addEventListener('blur', function () {
        // Fensterwechsel waehrend des Ziehens: Zeigerzustand zuruecksetzen.
        pointers = {};
        pinchStart = null;
        dragging = false;
        canvas.classList.remove('is-dragging', 'is-panning');
    });
    canvas.addEventListener('pointerleave', function () {
        view.hovered = null;
        canvas.classList.remove('is-hovering');
        hideTooltip();
    });

    canvas.addEventListener('click', function (event) {
        if (suppressClick || event.button !== 0) {
            return;
        }
        var point = localPoint(event);
        var hit = hitTest(point.x, point.y);
        select(hit ? hit.key : null);
    });

    canvas.addEventListener('dblclick', function (event) {
        var point = localPoint(event);
        var hit = hitTest(point.x, point.y);
        if (hit) {
            focusNode(hit.key);
        } else {
            fit();
        }
    });

    canvas.addEventListener('wheel', function (event) {
        event.preventDefault();
        var point = localPoint(event);
        var factor = Math.exp(-event.deltaY * (event.deltaMode === 1 ? 0.05 : 0.0015));
        var newZoom = Math.min(4, Math.max(0.2, view.tZoom * factor));
        // Zoom um den Zeiger herum
        var cx = view.width / 2, cy = view.height / 2;
        var wx = (point.x - cx - view.tPanX) / view.tZoom;
        var wy = (point.y - cy - view.tPanY) / view.tZoom;
        view.tPanX = point.x - cx - wx * newZoom;
        view.tPanY = point.y - cy - wy * newZoom;
        view.tZoom = newZoom;
        view.lastInteraction = performance.now();
        fadeHint();
    }, { passive: false });

    canvas.addEventListener('keydown', function (event) {
        var step = event.shiftKey ? 40 : 15;
        var handled = true;
        switch (event.key) {
            case 'ArrowLeft': if (view.mode3d && !event.altKey) { view.tYaw -= 0.12; } else { view.tPanX += step; } break;
            case 'ArrowRight': if (view.mode3d && !event.altKey) { view.tYaw += 0.12; } else { view.tPanX -= step; } break;
            case 'ArrowUp': if (view.mode3d && !event.altKey) { view.tPitch = Math.min(1.2, view.tPitch + 0.1); } else { view.tPanY += step; } break;
            case 'ArrowDown': if (view.mode3d && !event.altKey) { view.tPitch = Math.max(-1.2, view.tPitch - 0.1); } else { view.tPanY -= step; } break;
            case '+': case '=': actions['zoom-in'](); break;
            case '-': case '_': actions['zoom-out'](); break;
            case '0': resetView(); break;
            case 'f': case 'F': fit(); break;
            case 'r': case 'R': load(true); break;
            case 'p': case 'P': actions.particles(); break;
            case 'l': case 'L': actions.labels(); break;
            case ' ': actions.rotate(); break;
            case '2': setMode(false); break;
            case '3': setMode(true); break;
            case '!': actions['focus-problems'](); break;
            case 'Escape': select(null); hideTooltip(); break;
            case 'n': case 'N': cycleSelection(event.shiftKey ? -1 : 1); break;
            case 'Enter':
                if (view.selected) {
                    centerOn(view.selected);
                } else {
                    handled = false;
                }
                break;
            default: handled = false;
        }
        if (handled) {
            event.preventDefault();
            view.lastInteraction = performance.now();
        }
    });

    function cycleSelection(direction) {
        var keys = graph.order.filter(function (key) {
            return !view.hiddenKinds[graph.nodes[key].kind];
        });
        if (keys.length === 0) {
            return;
        }
        var index = keys.indexOf(view.selected);
        index = (index + direction + keys.length) % keys.length;
        focusNode(keys[index]);
    }

    // ------------------------------------------------------------ Protokoll & Stoerungen

    function log(level, text) {
        if (!logList) {
            return;
        }
        var item = el('li', 'topo-log__item topo-log__item--' + level);
        item.appendChild(el('span', 'topo-log__time', timeNow()));
        item.appendChild(document.createTextNode(text));
        logList.insertBefore(item, logList.firstChild);
        while (logList.children.length > 60) {
            logList.removeChild(logList.lastChild);
        }
    }

    function setIncidents(incidents) {
        if (!incidentList || !incidentsBox) {
            return;
        }
        var list = Array.isArray(incidents) ? incidents : [];
        clearChildren(incidentList);
        list.forEach(function (incident) {
            var li = el('li', 'topo-incident');
            li.setAttribute('data-topo-incident', String(incident.key || ''));
            var button = el('button', 'topo-incident__focus');
            button.type = 'button';
            button.setAttribute('data-topo-focus', String(incident.key || ''));
            button.appendChild(el('strong', null, incident.title || ''));
            if (incident.message) {
                button.appendChild(el('span', null, incident.message));
            }
            li.appendChild(button);
            if (typeof incident.url === 'string' && /^\/[^/\\]/.test(incident.url)) {
                var link = el('a', null, 'Beheben');
                link.href = incident.url;
                li.appendChild(link);
            }
            incidentList.appendChild(li);
        });
        incidentsBox.classList.toggle('topo-incidents--open', list.length > 0);
        incidentsBox.classList.toggle('topo-incidents--none', list.length === 0);
        if (incidentsEmpty) {
            incidentsEmpty.hidden = list.length > 0;
        }
        setField('incident-count', list.length, true);
    }

    function setOverall(overall) {
        if (!overall) {
            return;
        }
        var state = stateOf(overall.state);
        setField('overall-label', overall.label || STATE_LABELS[state]);
        setField('overall-message', overall.message || '');
        setField('overall-errors', overall.errors || 0, true);
        setField('overall-warnings', overall.warnings || 0, true);
        swapClass(overallBox, 'topo-head__status--', state);
        swapClass(overallPulse, 'topo-pulse--', state);
        root.setAttribute('data-overall-state', state);
    }

    /**
     * Zustandswechsel gegenueber dem letzten Stand erkennen: Welle am Knoten,
     * Eintrag im Protokoll, bei neuer Stoerung Fokus auf den Knoten (sofern
     * der Admin gerade nichts ausgewaehlt hat).
     */
    function diff(data) {
        if (!previous) {
            return;
        }
        var now = performance.now();
        var oldNodes = previous.nodes || {};
        var newNodes = data.nodes || {};
        var firstError = null;
        Object.keys(newNodes).forEach(function (key) {
            var before = oldNodes[key];
            var after = newNodes[key] || {};
            var afterState = stateOf(after.state);
            if (!before) {
                effects.push({ node: key, start: now, duration: 1800, state: afterState });
                log(afterState, 'Neuer Baustein: ' + (after.title || key) + ' (' + STATE_LABELS[afterState] + ')');
                return;
            }
            var beforeState = stateOf(before.state);
            if (beforeState === afterState) {
                return;
            }
            effects.push({ node: key, start: now, duration: afterState === 'error' ? 2600 : 1800, state: afterState });
            log(afterState, (after.title || key) + ': ' + STATE_LABELS[beforeState] + ' → ' + STATE_LABELS[afterState]
                + (after.message ? ' – ' + after.message : ''));
            if (afterState === 'error' && !firstError) {
                firstError = key;
            }
        });
        Object.keys(oldNodes).forEach(function (key) {
            if (!newNodes[key]) {
                log('off', 'Baustein entfernt: ' + (oldNodes[key].title || key));
            }
        });
        var oldOverall = stateOf(previous.overall && previous.overall.state);
        var newOverall = stateOf(data.overall && data.overall.state);
        if (oldOverall !== newOverall) {
            log(newOverall, 'Gesamtstatus: ' + STATE_LABELS[oldOverall] + ' → ' + STATE_LABELS[newOverall]);
        }
        if (firstError && !view.selected && !dragging) {
            focusNode(firstError);
        }
    }

    function apply(data) {
        if (!data || typeof data !== 'object') {
            return;
        }
        diff(data);
        ingest(data);
        setOverall(data.overall);
        setIncidents(data.incidents);
        if (data.generated_at) {
            setField('generated', data.generated_at);
        }
        if (view.selected) {
            select(view.selected);
        }
        previous = data;
    }

    function load(manual) {
        if (running || !refreshUrl || (document.hidden && !manual)) {
            return;
        }
        running = true;
        var button = root.querySelector('[data-topo-action="refresh"]');
        if (button) {
            button.classList.add('is-busy');
        }
        window.fetch(refreshUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (data) {
                    apply(data);
                    if (manual) {
                        log('ok', 'Aktualisiert · Stand ' + (data.generated_at || timeNow()));
                    }
                } else {
                    log('warn', 'Aktualisierung fehlgeschlagen – letzter Stand bleibt sichtbar.');
                }
            })
            .catch(function () {
                log('warn', 'Aktualisierung nicht möglich (keine Verbindung zum Server).');
            })
            .then(function () {
                running = false;
                if (button) {
                    button.classList.remove('is-busy');
                }
            });
    }

    function schedule() {
        window.clearInterval(timer);
        if (document.hidden) {
            return;
        }
        timer = window.setInterval(function () { load(false); }, refreshInterval);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            window.clearInterval(timer);
            if (rafId !== null) {
                window.cancelAnimationFrame(rafId);
                rafId = null;
            }
        } else {
            load(false);
            schedule();
            start();
        }
    });

    window.addEventListener('resize', function () {
        resize();
    });
    if (window.ResizeObserver) {
        new ResizeObserver(function () {
            resize();
        }).observe(stage);
    }
    document.addEventListener('fullscreenchange', function () {
        window.setTimeout(resize, 50);
    });

    // ------------------------------------------------------------ Start

    buildStars();
    resize();
    var initial = parseInitial();
    if (initial) {
        ingest(initial);
        setOverall(initial.overall);
        previous = initial;
        var errors = parseInt(initial.overall && initial.overall.errors, 10) || 0;
        if (errors > 0) {
            log('error', errors + ' Störung(en) beim Laden: ' + (initial.overall.message || ''));
        }
    } else {
        load(true);
    }
    if (reducedMotion) {
        setPressed('rotate', false);
        setPressed('particles', false);
        log('off', 'Bewegungsreduktion aktiv: keine Drehung, keine Partikel.');
    }
    window.setTimeout(fit, 30);
    schedule();
    start();
}());
