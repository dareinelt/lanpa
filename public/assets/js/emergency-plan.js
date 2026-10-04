(function () {
    'use strict';

    const types = { action: 'Maßnahme', contact: 'Kontakt', decision: 'Entscheidung', checklist: 'Checkliste', note: 'Hinweis', sms: 'SMS-Alarmierung' };
    const statuses = { open: 'Offen', in_progress: 'In Arbeit', blocked: 'Blockiert', done: 'Erledigt' };
    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    const svgElement = (tag, attributes, text) => {
        const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
        if (text !== undefined) node.textContent = text;
        return node;
    };
    let diagramCount = 0;
    // decorate(node, group, index) ergänzt im Editor Markierungen (Prüfhinweise, Suchfilter) ohne das Layout zu ändern.
    function diagram(container, definition, selected, onSelect, progress, decorate) {
        container.replaceChildren();
        const nodes = definition.nodes;
        if (!nodes.length) {
            container.append(element('p', 'Fügen Sie einen Baustein hinzu oder wählen Sie eine Beispielvorlage.'));
            return;
        }
        const positions = {};
        const levels = [];
        nodes.forEach(node => {
            const level = node.dependencies.reduce((max, edge) => Math.max(max, (positions[edge.id]?.level ?? -1) + 1), 0);
            levels[level] ||= [];
            positions[node.id] = { level, index: levels[level].length };
            levels[level].push(node);
        });
        const columns = Math.max(...levels.map(level => level.length));
        const width = Math.max(340, columns * 280);
        const height = levels.length * 148 + 30;
        const svg = svgElement('svg', { viewBox: `0 0 ${width} ${height}`, width, height, role: 'group', 'aria-label': 'Ablaufdiagramm: Elemente auswählen' });
        const markerId = 'ep-arrow-' + (++diagramCount);
        const defs = svgElement('defs', {});
        const marker = svgElement('marker', { id: markerId, markerWidth: 8, markerHeight: 8, refX: 7, refY: 4, orient: 'auto' });
        marker.append(svgElement('path', { d: 'M0,0 L8,4 L0,8 Z', class: 'ep-arrow' }));
        defs.append(marker);
        svg.append(defs);
        nodes.forEach(node => {
            const p = positions[node.id];
            p.x = (width - levels[p.level].length * 280) / 2 + p.index * 280 + 20;
            p.y = p.level * 148 + 20;
        });
        nodes.forEach(node => node.dependencies.forEach(edge => {
            const from = positions[edge.id], to = positions[node.id];
            if (!from) return;
            const sx = from.x + 120, sy = from.y + 92, tx = to.x + 120, ty = to.y;
            svg.append(svgElement('path', { d: `M${sx},${sy} C${sx},${sy + 28} ${tx},${ty - 28} ${tx},${ty}`, class: 'ep-edge', 'marker-end': `url(#${markerId})` }));
            if (edge.when !== 'always') svg.append(svgElement('text', { x: (sx + tx) / 2 + 8, y: (sy + ty) / 2, class: 'ep-edge-label' }, edge.when === 'yes' ? 'Ja' : 'Nein'));
        }));
        nodes.forEach((node, i) => {
            const p = positions[node.id];
            const state = progress?.state[node.id]?.status || 'open';
            const availability = progress?.ready[node.id];
            const group = svgElement('g', { role: 'button', tabindex: '0', 'aria-label': `${i + 1}. ${node.title || types[node.type]}`, class: `ep-graph-node ep-graph-node--${node.type}${selected === node.id ? ' is-selected' : ''} ep-graph-node--${availability === 'skipped' ? 'skipped' : state}` });
            group.append(svgElement('rect', { x: p.x, y: p.y, width: 240, height: 92, rx: node.type === 'decision' ? 25 : 8 }));
            group.append(svgElement('text', { x: p.x + 12, y: p.y + 22, class: 'ep-node-type' }, `${i + 1} · ${types[node.type]}`));
            const title = node.title || 'Unbenannt';
            const words = Array.from(title);
            [words.slice(0, 28).join(''), words.slice(28, 56).join('') + (words.length > 56 ? '…' : '')].forEach((line, j) => {
                if (line) group.append(svgElement('text', { x: p.x + 12, y: p.y + 45 + j * 17 }, line));
            });
            const hint = progress ? (availability === 'skipped' ? 'Entfällt' : availability === 'waiting' ? 'Wartet auf Vorgänger' : statuses[state]) : node.owner;
            group.append(svgElement('text', { x: p.x + 12, y: p.y + 81, class: 'ep-node-type' }, (hint || '').slice(0, 33)));
            group.addEventListener('click', () => onSelect(node.id));
            group.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); onSelect(node.id); }
            });
            if (decorate) decorate(node, group, i);
            svg.append(group);
        });
        container.append(svg);
    }

    async function post(url, data, signal) {
        const response = await fetch(url, { method: 'POST', body: data, signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!(response.headers.get('Content-Type') || '').includes('application/json')) {
            throw new Error('Sitzung abgelaufen oder technischer Fehler. Eingaben sichern und neu anmelden; Stand prüfen.');
        }
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Speichern fehlgeschlagen.');
        return result;
    }

    const editor = document.querySelector('[data-ep-editor]');
    if (editor) {
        const initial = JSON.parse(editor.querySelector('[data-ep-initial]').value);
        const alarms = JSON.parse(editor.querySelector('[data-ep-alarms]').value);
        let definition = initial.definition;
        let selected = definition.nodes[0]?.id;
        let dirty = false;
        let zoom = 1;
        let filter = '';
        let issues = null; // null = noch nicht geprüft
        let planPanelPinned = !definition.nodes.length;
        const $ = selector => editor.querySelector(selector);
        const $$ = selector => [...editor.querySelectorAll(selector)];
        const fields = $('[data-ep-fields]');
        const message = $('[data-ep-message]');
        const title = $('[data-ep-title]');
        const description = $('[data-ep-description]');
        const canvas = $('[data-ep-canvas]');
        const diagramHost = $('[data-ep-diagram]');
        const previewStatus = $('[data-ep-preview-status]');
        const setText = (selector, text) => $$(selector).forEach(node => { node.textContent = text; });

        // ---- Meldungen als Overlay (ersetzt window.confirm/alert; Hintergrund wird abgedunkelt) ----
        // cancel: null = reine Hinweismeldung mit nur einer Schaltfläche. Ergebnis: true = bestätigt.
        function overlay({ heading, text, confirm = 'OK', cancel = 'Abbrechen', danger = false }) {
            return new Promise(resolve => {
                const dialog = element('dialog', undefined, 'ep-dialog ep-alert' + (danger ? ' ep-alert--danger' : ''));
                dialog.setAttribute('role', cancel === null ? 'alertdialog' : 'dialog');
                const id = 'ep-alert-' + crypto.randomUUID();
                dialog.setAttribute('aria-labelledby', id + '-h'); dialog.setAttribute('aria-describedby', id + '-t');
                const h = element('h2', heading, 'ep-alert__heading'); h.id = id + '-h';
                const p = element('p', text, 'ep-alert__text'); p.id = id + '-t';
                const actions = element('div', undefined, 'ep-alert__actions');
                const ok = element('button', confirm, 'button ' + (danger ? 'button--danger' : 'button--primary')); ok.type = 'button';
                actions.append(ok);
                let result = false;
                if (cancel !== null) {
                    const no = element('button', cancel, 'button button--ghost'); no.type = 'button';
                    no.addEventListener('click', () => dialog.close());
                    actions.append(no);
                }
                ok.addEventListener('click', () => { result = true; dialog.close(); });
                dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
                dialog.addEventListener('close', () => { dialog.remove(); resolve(result); });
                dialog.append(h, p, actions);
                editor.append(dialog);
                dialog.showModal();
                ok.focus();
            });
        }
        // Hinweis in der Statusleiste und zusätzlich als Overlay, damit er nicht übersehen wird.
        const notify = (text, heading = 'Hinweis') => { message.textContent = text; return overlay({ heading, text, cancel: null }); };

        // ---- Live-Vorschau (BroadcastChannel ep-preview-<uuid>, siehe Referenz Abschnitt 9) ----
        let previewChannel = null;
        let previewVersion = 0;
        let previewTimer;
        let previewOpenTimer;
        const publishPreview = () => previewChannel?.postMessage({ type: 'definition', definition, version: previewVersion });
        const previewLinks = $$('[data-ep-open-preview]');
        previewLinks.forEach(link => link.addEventListener('click', event => {
            if (!('BroadcastChannel' in window)) {
                event.preventDefault();
                previewStatus.textContent = 'Dieser Browser unterstützt die Live-Vorschau nicht. Bitte einen aktuellen Browser verwenden.';
                return;
            }
            if (!previewChannel) {
                const key = crypto.randomUUID();
                previewChannel = new BroadcastChannel('ep-preview-' + key);
                previewLinks.forEach(l => { l.href = '/admin/notfallplan/vorschau#' + key; });
                previewChannel.onmessage = ({ data }) => {
                    if (data?.type !== 'sync' && data?.type !== 'ping') return;
                    clearTimeout(previewOpenTimer);
                    previewStatus.textContent = 'Vorschau verbunden – Änderungen werden live übertragen.';
                    previewStatus.classList.add('is-live');
                    if (data.type === 'sync') publishPreview();
                    else previewChannel.postMessage({ type: 'alive', version: previewVersion });
                };
            }
            previewStatus.textContent = 'Vorschau wird in einem neuen Tab geöffnet …';
            previewOpenTimer = setTimeout(() => {
                previewStatus.textContent = 'Noch keine Vorschau verbunden. Neuen Tab prüfen und gegebenenfalls Pop-ups erlauben.';
            }, 8000);
        }));
        window.addEventListener('pagehide', () => previewChannel?.postMessage({ type: 'disconnected' }));
        window.addEventListener('pageshow', publishPreview);

        // ---- Verlauf (Rückgängig / Wiederholen): JSON-Schnappschüsse des Entwurfs ----
        const journal = { undo: [], redo: [], key: null, at: 0 };
        const updateHistoryButtons = () => {
            $$('[data-ep-undo]').forEach(b => { b.disabled = !journal.undo.length; });
            $$('[data-ep-redo]').forEach(b => { b.disabled = !journal.redo.length; });
        };
        // key: zusammenhängende Tipp-Eingaben im selben Feld werden zu einem Schritt zusammengefasst.
        function snapshot(key) {
            const now = Date.now();
            if (key && key === journal.key && now - journal.at < 1500) { journal.at = now; return; }
            journal.undo.push(JSON.stringify(definition));
            if (journal.undo.length > 100) journal.undo.shift();
            journal.redo = []; journal.key = key || null; journal.at = now;
            updateHistoryButtons();
        }
        function restore(json) {
            definition = JSON.parse(json);
            title.value = definition.title; description.value = definition.description;
            if (!definition.nodes.some(n => n.id === selected)) selected = definition.nodes[0]?.id;
            journal.key = null; mark(); render(); updateHistoryButtons();
        }
        const undo = () => { if (!journal.undo.length) return; journal.redo.push(JSON.stringify(definition)); restore(journal.undo.pop()); message.textContent = 'Rückgängig gemacht.'; };
        const redo = () => { if (!journal.redo.length) return; journal.undo.push(JSON.stringify(definition)); restore(journal.redo.pop()); message.textContent = 'Wiederholt.'; };
        $$('[data-ep-undo]').forEach(b => b.addEventListener('click', undo));
        $$('[data-ep-redo]').forEach(b => b.addEventListener('click', redo));

        const mark = () => {
            dirty = true; message.textContent = 'Ungespeicherte Änderungen.';
            $('[data-ep-dirty-flag]').hidden = false;
            setText('[data-ep-doc-title]', definition.title || 'Neuer Notfallplan');
            if (issues !== null) validate(false);
            previewVersion++;
            clearTimeout(previewTimer);
            previewTimer = setTimeout(publishPreview, 150);
        };

        // ---- Client-Prüfung (nur Komfort – maßgeblich bleibt der Server) ----
        const nodeName = (n, i) => `Schritt ${i + 1}${n.title ? ' „' + n.title + '“' : ''}`;
        function validate(announce) {
            const found = [];
            const byId = Object.fromEntries(definition.nodes.map(n => [n.id, n]));
            if (!definition.title.trim()) found.push({ level: 'error', text: 'Der Plan hat noch keinen Titel.' });
            if (!definition.nodes.length) found.push({ level: 'error', text: 'Der Plan enthält noch keinen Schritt.' });
            const seen = new Set();
            definition.nodes.forEach((n, i) => {
                const name = nodeName(n, i);
                if (!n.title.trim()) found.push({ level: 'error', node: n.id, text: `${name}: Titel fehlt.` });
                if (n.type === 'checklist' && !n.checks.length) found.push({ level: 'error', node: n.id, text: `${name}: Checkliste braucht mindestens einen Prüfpunkt.` });
                if (n.checks.length > 20) found.push({ level: 'error', node: n.id, text: `${name}: höchstens 20 Prüfpunkte.` });
                if (n.type === 'sms' && !(Number(n.alarm_id) > 0)) found.push({ level: 'error', node: n.id, text: `${name}: SMS-Alarmvorlage wählen.` });
                if (n.type === 'sms' && Number(n.alarm_id) > 0 && !alarms.some(a => a.id === Number(n.alarm_id))) found.push({ level: 'error', node: n.id, text: `${name}: gewählte SMS-Vorlage ist nicht mehr aktiv.` });
                if (n.link && !/^https?:\/\/\S+$/i.test(n.link)) found.push({ level: 'error', node: n.id, text: `${name}: Link muss mit http:// oder https:// beginnen.` });
                if (!Number.isInteger(n.minutes) || n.minutes < 0 || n.minutes > 10080) found.push({ level: 'error', node: n.id, text: `${name}: Zielzeit muss zwischen 0 und 10080 Minuten liegen.` });
                if (n.dependencies.length > 80) found.push({ level: 'error', node: n.id, text: `${name}: zu viele Voraussetzungen.` });
                n.dependencies.forEach(edge => {
                    if (!seen.has(edge.id)) found.push({ level: 'error', node: n.id, text: `${name}: Voraussetzung zeigt auf einen späteren oder gelöschten Schritt.` });
                    else if (edge.when !== 'always' && byId[edge.id]?.type !== 'decision') found.push({ level: 'error', node: n.id, text: `${name}: Antwort Ja/Nein ist nur bei einer Entscheidung als Voraussetzung möglich.` });
                });
                if (i > 0 && !n.dependencies.length) found.push({ level: 'hint', node: n.id, text: `${name}: hat keine Voraussetzung und startet sofort parallel zum ersten Schritt.` });
                if (n.type === 'decision' && !definition.nodes.some(m => m.dependencies.some(e => e.id === n.id))) found.push({ level: 'hint', node: n.id, text: `${name}: Auf die Entscheidung folgt kein Schritt.` });
                seen.add(n.id);
            });
            issues = found;
            const errors = found.filter(f => f.level === 'error').length;
            const hints = found.length - errors;
            const summary = errors ? `${errors} Problem${errors === 1 ? '' : 'e'}` : 'Keine Probleme';
            setText('[data-ep-issue-count]', summary + (hints ? ` · ${hints} Hinweis${hints === 1 ? '' : 'e'}` : ''));
            $$('.ep-statusbar__issues').forEach(b => b.classList.toggle('has-errors', errors > 0));
            const panel = $('[data-ep-issues]');
            const list = $('[data-ep-issue-list]');
            list.replaceChildren();
            found.forEach(issue => {
                const li = element('li', undefined, 'ep-issue ep-issue--' + issue.level);
                if (issue.node) {
                    const b = element('button', issue.text, 'ep-issue__link'); b.type = 'button';
                    b.addEventListener('click', () => { planPanelPinned = false; select(issue.node); centerSelected(); });
                    li.append(b);
                } else li.append(element('span', issue.text));
                list.append(li);
            });
            if (!found.length) list.append(element('li', 'Alles vollständig. Der Entwurf kann gespeichert werden.', 'ep-issue ep-issue--ok'));
            panel.hidden = false;
            renderList(); refreshGraph();
            if (announce) message.textContent = errors ? `Prüfung: ${summary}. Betroffene Schritte sind markiert.` : 'Prüfung abgeschlossen: keine Probleme gefunden.';
            return errors === 0;
        }
        $$('[data-ep-validate]').forEach(b => b.addEventListener('click', () => { validate(true); switchTab('review'); }));
        const issueMap = () => {
            const map = new Map();
            (issues || []).forEach(issue => { if (issue.node && (!map.has(issue.node) || issue.level === 'error')) map.set(issue.node, issue.level); });
            return map;
        };

        // ---- Diagramm, Zoom, Verschieben ----
        const matchesFilter = n => !filter || [n.title, n.owner, n.text, types[n.type]].some(v => (v || '').toLowerCase().includes(filter));
        function applyZoom() {
            const svg = diagramHost.querySelector('svg');
            if (svg) {
                const [, , w, h] = svg.getAttribute('viewBox').split(' ').map(Number);
                svg.setAttribute('width', String(Math.round(w * zoom)));
                svg.setAttribute('height', String(Math.round(h * zoom)));
            }
            setText('[data-ep-zoom-level]', Math.round(zoom * 100) + ' %');
        }
        function refreshGraph() {
            const marks = issueMap();
            diagram(diagramHost, definition, selected, id => { planPanelPinned = false; select(id); }, null, (node, group) => {
                group.dataset.epNode = node.id;
                if (marks.has(node.id)) group.classList.add('ep-graph-node--' + marks.get(node.id));
                if (!matchesFilter(node)) group.classList.add('is-dimmed');
            });
            applyZoom();
        }
        function setZoom(next, origin) {
            const before = zoom;
            zoom = Math.min(2.5, Math.max(0.3, Math.round(next * 100) / 100));
            if (zoom === before) return;
            const rect = canvas.getBoundingClientRect();
            const ox = origin ? origin.x - rect.left : rect.width / 2;
            const oy = origin ? origin.y - rect.top : rect.height / 2;
            const px = (canvas.scrollLeft + ox) / before, py = (canvas.scrollTop + oy) / before;
            applyZoom();
            canvas.scrollLeft = px * zoom - ox; canvas.scrollTop = py * zoom - oy;
        }
        function fitZoom() {
            const svg = diagramHost.querySelector('svg');
            if (!svg) return;
            const [, , w, h] = svg.getAttribute('viewBox').split(' ').map(Number);
            setZoom(Math.min((canvas.clientWidth - 32) / w, (canvas.clientHeight - 32) / h, 1.5));
            canvas.scrollTo(0, 0);
        }
        function centerSelected() {
            const rect = diagramHost.querySelector('.ep-graph-node.is-selected rect');
            if (!rect) return;
            const x = (Number(rect.getAttribute('x')) + 120) * zoom, y = (Number(rect.getAttribute('y')) + 46) * zoom;
            canvas.scrollTo({ left: x - canvas.clientWidth / 2, top: y - canvas.clientHeight / 2, behavior: 'smooth' });
        }
        $$('[data-ep-zoom]').forEach(b => b.addEventListener('click', () => {
            const mode = b.dataset.epZoom;
            if (mode === 'in') setZoom(zoom * 1.2); else if (mode === 'out') setZoom(zoom / 1.2);
            else if (mode === 'reset') setZoom(1); else if (mode === 'fit') fitZoom(); else centerSelected();
        }));
        canvas.addEventListener('wheel', event => {
            if (!event.ctrlKey && !event.metaKey) return;
            event.preventDefault();
            setZoom(zoom * (event.deltaY < 0 ? 1.1 : 1 / 1.1), { x: event.clientX, y: event.clientY });
        }, { passive: false });
        let pan = null;
        canvas.addEventListener('pointerdown', event => {
            if (event.button !== 0 || event.target.closest('.ep-graph-node')) return;
            pan = { x: event.clientX, y: event.clientY, left: canvas.scrollLeft, top: canvas.scrollTop, id: event.pointerId };
            canvas.setPointerCapture(event.pointerId); canvas.classList.add('is-panning');
        });
        canvas.addEventListener('pointermove', event => {
            if (!pan || pan.id !== event.pointerId) return;
            canvas.scrollLeft = pan.left - (event.clientX - pan.x); canvas.scrollTop = pan.top - (event.clientY - pan.y);
        });
        const endPan = event => { if (pan && pan.id === event.pointerId) { pan = null; canvas.classList.remove('is-panning'); } };
        canvas.addEventListener('pointerup', endPan); canvas.addEventListener('pointercancel', endPan);
        canvas.addEventListener('click', event => { if (!event.target.closest('.ep-graph-node') && !definition.nodes.length) showPlanPanel(); });
        // Bausteine per Drag-and-Drop aus der Palette in das Diagramm ziehen (= wie 1-Klick-Hinzufügen).
        $$('[data-ep-drag-type]').forEach(b => b.addEventListener('dragstart', event => {
            event.dataTransfer.setData('text/plain', b.dataset.epDragType); event.dataTransfer.effectAllowed = 'copy';
        }));
        canvas.addEventListener('dragover', event => { if ([...event.dataTransfer.types].includes('text/plain')) { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; canvas.classList.add('is-dropping'); } });
        canvas.addEventListener('dragleave', () => canvas.classList.remove('is-dropping'));
        canvas.addEventListener('drop', event => {
            event.preventDefault(); canvas.classList.remove('is-dropping');
            const type = event.dataTransfer.getData('text/plain');
            if (types[type]) addNode(type);
        });

        // ---- Schritte ----
        const makeNode = type => ({ id: 'n' + crypto.randomUUID().replaceAll('-', ''), type, title: '', text: '', owner: '', phone: '', link: '', minutes: 0, checks: type === 'checklist' ? ['Prüfpunkt'] : [], dependencies: [], join: 'all', alarm_id: 0 });
        const current = () => definition.nodes.find(n => n.id === selected);
        function select(id) { selected = id; render(); }
        function showPlanPanel() { planPanelPinned = true; render(); title.focus(); }
        $('[data-ep-show-plan]').addEventListener('click', showPlanPanel);
        function addNode(type) {
            if (definition.nodes.length >= 80) { notify('Maximal 80 Schritte je Plan.'); return; }
            snapshot();
            const node = makeNode(type);
            if (definition.nodes.length && $('[data-ep-autolink]').checked) node.dependencies = [{ id: definition.nodes.at(-1).id, when: 'always' }];
            definition.nodes.push(node); selected = node.id; planPanelPinned = false; mark(); render();
            fields.querySelector('input')?.focus();
            centerSelected();
        }
        $$('[data-ep-add-type]').forEach(b => b.addEventListener('click', () => addNode(b.dataset.epAddType)));
        function move(offset) {
            const index = definition.nodes.findIndex(n => n.id === selected), target = index + offset;
            if (index < 0 || target < 0 || target >= definition.nodes.length) return;
            const copy = definition.nodes.slice();
            [copy[index], copy[target]] = [copy[target], copy[index]];
            const seen = new Set();
            const valid = copy.every(n => { const ok = n.dependencies.every(e => seen.has(e.id)); seen.add(n.id); return ok; });
            if (!valid) { notify('Verschieben würde eine Verbindung umkehren. Zuerst die betreffenden Voraussetzungen anpassen.', 'Verschieben nicht möglich'); return; }
            snapshot(); definition.nodes = copy; mark(); render();
        }
        $$('[data-ep-move]').forEach(b => b.addEventListener('click', () => move(Number(b.dataset.epMove))));
        function duplicate() {
            const node = current(); if (!node) return;
            if (definition.nodes.length >= 80) { notify('Maximal 80 Schritte je Plan.'); return; }
            snapshot();
            const copy = structuredClone(node); copy.id = makeNode(node.type).id; copy.title = (copy.title + ' (Kopie)').slice(0, 190);
            definition.nodes.splice(definition.nodes.indexOf(node) + 1, 0, copy); selected = copy.id; mark(); render();
        }
        // Zwischenablage: im Speicher und – sofern erlaubt – im localStorage, damit auch zwischen Plänen in anderen Tabs eingefügt werden kann.
        let clipboard = null;
        const readClipboard = () => {
            try { const stored = JSON.parse(localStorage.getItem('ep-clipboard') || 'null'); if (stored && types[stored.type]) return stored; } catch { /* nicht verfügbar */ }
            return clipboard;
        };
        function copyNode() {
            const node = current(); if (!node) return;
            clipboard = structuredClone(node);
            try { localStorage.setItem('ep-clipboard', JSON.stringify(clipboard)); } catch { /* nur im Speicher */ }
            message.textContent = `Schritt „${node.title || types[node.type]}“ kopiert.`;
        }
        function pasteNode() {
            const source = readClipboard();
            if (!source) { notify('Die Zwischenablage ist leer. Zuerst einen Schritt kopieren.'); return; }
            if (definition.nodes.length >= 80) { notify('Maximal 80 Schritte je Plan.'); return; }
            snapshot();
            const node = Object.assign(makeNode(source.type), structuredClone(source));
            node.id = makeNode(source.type).id;
            const anchor = current();
            const index = anchor ? definition.nodes.indexOf(anchor) + 1 : definition.nodes.length;
            // Nur Voraussetzungen behalten, die in diesem Plan vor der Einfügestelle liegen.
            const before = new Map(definition.nodes.slice(0, index).map(n => [n.id, n]));
            node.dependencies = (node.dependencies || []).filter(e => before.has(e.id) && (e.when === 'always' || before.get(e.id).type === 'decision'));
            definition.nodes.splice(index, 0, node); selected = node.id; planPanelPinned = false; mark(); render(); centerSelected();
            message.textContent = 'Schritt eingefügt.';
        }
        async function remove() {
            const node = current(); if (!node) return;
            const ok = await overlay({ heading: 'Schritt löschen?', text: 'Schritt und alle zugehörigen Verbindungen löschen? Nachfolger können dadurch zu Startpunkten werden.', confirm: 'Löschen', danger: true });
            if (!ok || !definition.nodes.includes(node)) return;
            snapshot();
            const index = definition.nodes.indexOf(node);
            definition.nodes = definition.nodes.filter(n => n.id !== node.id);
            definition.nodes.forEach(n => { n.dependencies = n.dependencies.filter(e => e.id !== node.id); });
            selected = (definition.nodes[index] || definition.nodes[index - 1])?.id; mark(); render();
        }
        $('[data-ep-duplicate]').addEventListener('click', duplicate);
        $('[data-ep-delete]').addEventListener('click', remove);

        // ---- Kontextmenü (Rechtsklick, Kontextmenü-Taste oder Umschalt+F10 auf einem Schritt im Diagramm) ----
        let contextMenu = null;
        const focusSelected = () => diagramHost.querySelector('.ep-graph-node.is-selected')?.focus({ preventScroll: true });
        function closeContextMenu(restoreFocus) {
            if (!contextMenu) return;
            contextMenu.remove(); contextMenu = null;
            if (restoreFocus) focusSelected();
        }
        function openContextMenu(x, y) {
            closeContextMenu(false);
            const full = definition.nodes.length >= 80;
            const entries = [
                ['Duplizieren', 'Strg+D', duplicate, full],
                ['Kopieren', 'Strg+C', copyNode, false],
                ['Einfügen', 'Strg+V', pasteNode, full || !readClipboard()],
                ['Rückgängig', 'Strg+Z', undo, !journal.undo.length],
                ['Löschen', 'Entf', remove, false, true],
            ];
            const menu = element('div', undefined, 'ep-context-menu');
            menu.setAttribute('role', 'menu'); menu.setAttribute('aria-label', 'Schritt bearbeiten');
            entries.forEach(([label, shortcut, action, disabled, danger], i) => {
                if (danger) menu.append(element('div', undefined, 'ep-context-menu__sep'));
                const item = element('button', undefined, 'ep-context-menu__item' + (danger ? ' ep-context-menu__item--danger' : ''));
                item.type = 'button'; item.setAttribute('role', 'menuitem'); item.tabIndex = -1; item.disabled = disabled;
                item.append(element('span', label), element('kbd', shortcut));
                item.addEventListener('click', () => { closeContextMenu(true); action(); });
                menu.append(item);
            });
            menu.addEventListener('keydown', event => {
                const items = [...menu.querySelectorAll('button:not(:disabled)')];
                const index = items.indexOf(document.activeElement);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
                } else if (event.key === 'Home' || event.key === 'End') { event.preventDefault(); items.at(event.key === 'Home' ? 0 : -1)?.focus(); }
                else if (event.key === 'Escape' || event.key === 'Tab') { event.preventDefault(); closeContextMenu(true); }
                event.stopPropagation();
            });
            editor.append(menu); contextMenu = menu;
            const { width, height } = menu.getBoundingClientRect();
            menu.style.left = Math.max(4, Math.min(x, window.innerWidth - width - 4)) + 'px';
            menu.style.top = Math.max(4, Math.min(y, window.innerHeight - height - 4)) + 'px';
            menu.querySelector('button:not(:disabled)')?.focus();
        }
        canvas.addEventListener('contextmenu', event => {
            const group = event.target.closest('.ep-graph-node');
            if (!group) return;
            event.preventDefault();
            const id = group.dataset.epNode;
            if (selected !== id || planPanelPinned) { planPanelPinned = false; select(id); }
            let { clientX: x, clientY: y } = event;
            if (!x && !y) { // per Tastatur ausgelöst: am Schritt öffnen
                const rect = diagramHost.querySelector('.ep-graph-node.is-selected rect')?.getBoundingClientRect();
                if (rect) { x = rect.left + 12; y = rect.bottom - 6; }
            }
            openContextMenu(x, y);
        });
        document.addEventListener('pointerdown', event => { if (contextMenu && !contextMenu.contains(event.target)) closeContextMenu(false); }, true);
        canvas.addEventListener('scroll', () => closeContextMenu(false));
        window.addEventListener('resize', () => closeContextMenu(false));
        window.addEventListener('blur', () => closeContextMenu(false));
        $('[data-ep-link-previous]').addEventListener('click', () => {
            const node = current(); const index = definition.nodes.indexOf(node);
            if (!node || index < 1) { notify('Der erste Schritt hat keinen Vorgänger.'); return; }
            const previous = definition.nodes[index - 1];
            if (node.dependencies.some(e => e.id === previous.id)) { notify('Der vorherige Schritt ist bereits Voraussetzung.'); return; }
            snapshot(); node.dependencies.push({ id: previous.id, when: 'always' }); mark(); render();
        });
        $('[data-ep-unlink-all]').addEventListener('click', () => {
            const node = current(); if (!node || !node.dependencies.length) return;
            snapshot(); node.dependencies = []; mark(); render();
        });
        $$('[data-ep-join]').forEach(b => b.addEventListener('click', () => {
            const node = current(); if (!node || node.join === b.dataset.epJoin) return;
            snapshot(); node.join = b.dataset.epJoin; mark(); render();
        }));

        // ---- Suche ----
        const search = $('[data-ep-search]');
        search.addEventListener('input', () => {
            filter = search.value.trim().toLowerCase();
            const hits = definition.nodes.filter(matchesFilter).length;
            $('[data-ep-search-result]').textContent = filter ? `${hits} von ${definition.nodes.length} Schritten passen` : '';
            renderList(); refreshGraph();
        });

        // ---- Eigenschaften (Inspector) ----
        function inputField(label, key, multiline, max, hint) {
            const node = current();
            const wrapper = element('label', label);
            const input = element(multiline ? 'textarea' : 'input');
            input.value = node[key];
            if (multiline) input.rows = 3;
            if (max) input.maxLength = max;
            if (key === 'minutes') { input.type = 'number'; input.min = '0'; input.max = '10080'; input.step = '1'; }
            if (key === 'link') { input.type = 'url'; input.placeholder = 'https://'; }
            if (key === 'phone') input.type = 'tel';
            input.addEventListener('input', () => {
                snapshot(node.id + ':' + key);
                node[key] = key === 'minutes' ? Number(input.value) : input.value;
                mark(); refreshGraph();
                if (key === 'title') renderList();
            });
            wrapper.append(input);
            if (hint) wrapper.append(element('small', hint, 'ep-hint'));
            fields.append(wrapper);
        }
        function button(text, fn, className) {
            const b = element('button', text, className || 'button button--ghost'); b.type = 'button'; b.addEventListener('click', fn); return b;
        }
        function renderList() {
            const list = $('[data-ep-list]'); list.replaceChildren();
            const marks = issueMap();
            definition.nodes.forEach((n, i) => {
                const li = element('li');
                if (!matchesFilter(n)) li.classList.add('is-hidden');
                const pick = element('button', undefined, 'ep-element-list__item ep-element-list__item--' + n.type); pick.type = 'button';
                pick.append(element('span', String(i + 1), 'ep-element-list__no'));
                const text = element('span', undefined, 'ep-element-list__text');
                text.append(element('strong', n.title || `(${types[n.type]} ohne Titel)`), element('small', types[n.type] + (n.owner ? ' · ' + n.owner : '')));
                pick.append(text);
                if (marks.has(n.id)) pick.append(element('span', marks.get(n.id) === 'error' ? '!' : 'i', 'ep-element-list__flag ep-element-list__flag--' + marks.get(n.id)));
                if (n.id === selected && !planPanelPinned) pick.setAttribute('aria-current', 'true');
                pick.addEventListener('click', () => { planPanelPinned = false; select(n.id); centerSelected(); });
                li.append(pick); list.append(li);
            });
            setText('[data-ep-count]', `${definition.nodes.length} / 80`);
        }
        function renderInspector() {
            const node = planPanelPinned ? null : current();
            $('[data-ep-plan-panel]').hidden = !!node;
            $('[data-ep-node-panel]').hidden = !node;
            const hasNode = !!current();
            ['[data-ep-duplicate]', '[data-ep-delete]', '[data-ep-move]', '[data-ep-link-previous]', '[data-ep-unlink-all]', '[data-ep-join]'].forEach(s => $$(s).forEach(b => { b.disabled = !hasNode; }));
            $$('[data-ep-join]').forEach(b => b.setAttribute('aria-pressed', String(!!current() && current().join === b.dataset.epJoin)));
            fields.replaceChildren();
            if (!node) return;
            const index = definition.nodes.indexOf(node);
            const head = element('p', undefined, 'ep-inspector-type');
            head.append(element('span', `Schritt ${index + 1} · ${types[node.type]}`));
            fields.append(head);
            inputField(node.type === 'decision' ? 'Frage (Ja / Nein)' : 'Titel des Schritts', 'title', false, 190);
            inputField('Anweisung / Erläuterung', 'text', true, 4000);
            inputField('Zuständigkeit / Funktion', 'owner', false, 190, 'z. B. Einsatzleitung, Pforte');
            inputField('Telefon / Durchwahl', 'phone', false, 100);
            inputField('Informationslink', 'link', false, 1000, 'Nur vollständige Adressen mit http:// oder https://');
            inputField('Zielzeit in Minuten ab Ereignisstart', 'minutes', false, 0, '0 = keine Zielzeit, höchstens 10080 (7 Tage)');
            if (node.type === 'checklist') {
                const label = element('label', 'Prüfpunkte (eine Zeile pro Punkt, höchstens 20)');
                const input = element('textarea'); input.rows = 5; input.value = node.checks.join('\n');
                input.addEventListener('input', () => { snapshot(node.id + ':checks'); node.checks = input.value.split('\n').map(s => s.trim()).filter(Boolean); mark(); });
                label.append(input); fields.append(label);
            }
            if (node.type === 'sms') {
                const label = element('label', 'Bestehende SMS-Alarmvorlage');
                const selectAlarm = element('select');
                const placeholder = element('option', 'Bitte wählen'); placeholder.value = '0'; selectAlarm.append(placeholder);
                alarms.forEach(alarm => { const o = element('option', alarm.title); o.value = alarm.id; selectAlarm.append(o); });
                selectAlarm.value = String(node.alarm_id);
                const preview = element('p', '', 'ep-sms');
                const show = () => {
                    const alarm = alarms.find(a => a.id === Number(selectAlarm.value));
                    preview.textContent = alarm ? `An ${alarm.target || 'Ziel fehlt'}: ${alarm.text || 'Text fehlt'}` : 'Keine aktive Vorlage gewählt. Ein Administrator pflegt Vorlagen unter Navigation / Alarmierung.';
                };
                selectAlarm.addEventListener('change', () => { snapshot(); node.alarm_id = Number(selectAlarm.value); mark(); show(); });
                show(); label.append(selectAlarm); fields.append(label, preview);
                fields.append(element('p', 'Ziel und Nachricht werden beim Speichern in den Plan kopiert. Im Einsatz ist für diese SMS eine eigene Bestätigung nötig.', 'ep-hint'));
            }

            // Voraussetzungen: Checkbox je vorherigem Schritt, Bedingung bei Entscheidungen, UND/ODER als Schalter.
            const edges = element('fieldset', undefined, 'ep-deps');
            edges.append(element('legend', 'Voraussetzungen (vorherige Schritte)'));
            const prior = definition.nodes.slice(0, index);
            const summary = element('p', undefined, 'ep-deps__summary');
            const updateSummary = () => {
                if (!node.dependencies.length) { summary.textContent = index === 0 ? 'Startpunkt: Dieser Schritt beginnt sofort.' : 'Keine Voraussetzung: Dieser Schritt beginnt sofort – parallel zum Start.'; return; }
                const names = node.dependencies.map(e => { const p = definition.nodes.find(n => n.id === e.id); const i = definition.nodes.indexOf(p); return `${i + 1}. ${p?.title || types[p?.type] || '?'}${e.when === 'yes' ? ' = Ja' : e.when === 'no' ? ' = Nein' : ''}`; });
                summary.textContent = node.dependencies.length === 1 ? `Startet, sobald erledigt: ${names[0]}` : `Startet, sobald ${node.join === 'any' ? 'MINDESTENS EINE' : 'ALLE'} der Voraussetzungen erledigt ${node.join === 'any' ? 'ist' : 'sind'}: ${names.join(' · ')}`;
            };
            if (!prior.length) edges.append(element('p', 'Erster Schritt: Es gibt noch keine vorherigen Schritte, die Voraussetzung sein könnten.', 'ep-hint'));
            prior.forEach((previous, i) => {
                const row = element('div', undefined, 'ep-dependency');
                const check = element('input'); check.type = 'checkbox'; check.id = 'dep-' + previous.id;
                const edge = node.dependencies.find(e => e.id === previous.id); check.checked = !!edge;
                const label = element('label', `${i + 1}. ${previous.title || types[previous.type]}`); label.htmlFor = check.id;
                label.prepend(check);
                row.append(label);
                const condition = element('select');
                const options = previous.type === 'decision' ? { always: 'Erledigt (beliebige Antwort)', yes: 'Antwort Ja', no: 'Antwort Nein' } : { always: 'Erledigt' };
                Object.entries(options).forEach(([value, text]) => { const option = element('option', text); option.value = value; condition.append(option); });
                condition.value = edge?.when || 'always'; condition.disabled = !check.checked; condition.setAttribute('aria-label', 'Bedingung');
                if (previous.type !== 'decision') condition.hidden = true;
                const change = () => {
                    snapshot();
                    node.dependencies = node.dependencies.filter(e => e.id !== previous.id);
                    if (check.checked) node.dependencies.push({ id: previous.id, when: condition.value });
                    condition.disabled = !check.checked; row.classList.toggle('is-active', check.checked);
                    mark(); refreshGraph(); updateSummary(); joinWrap.hidden = node.dependencies.length < 2;
                };
                row.classList.toggle('is-active', check.checked);
                check.addEventListener('change', change); condition.addEventListener('change', change);
                row.append(condition); edges.append(row);
            });
            const joinWrap = element('div', undefined, 'ep-join');
            joinWrap.append(element('span', 'Mehrere Voraussetzungen:'));
            const seg = element('div', undefined, 'ep-segment'); seg.setAttribute('role', 'radiogroup'); seg.setAttribute('aria-label', 'Verknüpfung');
            [['all', 'Alle (UND)'], ['any', 'Eine genügt (ODER)']].forEach(([value, text]) => {
                const b = element('button', text); b.type = 'button'; b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', String(node.join === value));
                b.addEventListener('click', () => { if (node.join === value) return; snapshot(); node.join = value; mark(); render(); });
                seg.append(b);
            });
            joinWrap.append(seg, element('small', 'ODER führt Ja/Nein-Zweige wieder zusammen, sonst entfällt der Schritt, sobald ein Zweig entfällt.', 'ep-hint'));
            joinWrap.hidden = node.dependencies.length < 2;
            edges.append(summary, joinWrap); fields.append(edges);
            updateSummary();

            const followers = definition.nodes.filter(n => n.dependencies.some(e => e.id === node.id));
            const next = element('p', undefined, 'ep-hint');
            next.textContent = followers.length ? 'Danach folgt: ' + followers.map(n => `${definition.nodes.indexOf(n) + 1}. ${n.title || types[n.type]}`).join(' · ') : 'Danach folgt bisher kein Schritt.';
            fields.append(next);
            const actions = element('div', undefined, 'ep-inspector__actions');
            actions.append(button('↑ Nach oben', () => move(-1)), button('↓ Nach unten', () => move(1)), button('Duplizieren', duplicate), button('Löschen', remove, 'button button--danger'));
            fields.append(actions);
        }
        function render() { renderList(); refreshGraph(); renderInspector(); }

        // ---- Plan-Angaben und Vorlagen ----
        title.addEventListener('input', () => { snapshot('plan:title'); definition.title = title.value; mark(); });
        description.addEventListener('input', () => { snapshot('plan:description'); definition.description = description.value; mark(); });
        $$('[data-ep-template]').forEach(b => b.addEventListener('click', async () => {
            if (definition.nodes.length && !await overlay({ heading: 'Beispielvorlage laden?', text: 'Aktuellen Entwurf durch die Beispielvorlage ersetzen? (Mit Rückgängig wiederherstellbar)', confirm: 'Ersetzen' })) return;
            snapshot();
            const fire = b.dataset.epTemplate === 'fire';
            const content = fire ? [
                ['note', 'Eigenschutz und Notruf', 'Eigenschutz beachten. Örtlichen Notruf absetzen und Lage beschreiben.', 'Alle'],
                ['checklist', 'Leitung alarmieren', 'Erreichbarkeit und Rückmeldungen dokumentieren.', 'Einsatzkoordination'],
                ['action', 'Schranken öffnen', 'Zufahrt für Einsatzkräfte freihalten.', 'Technik / Pforte'],
                ['contact', 'Brandschutzhelfer alarmieren', 'Sammelpunkt und Lage mitteilen.', 'Einsatzkoordination'],
                ['decision', 'Räumung erforderlich?', 'Entscheidung der Einsatzleitung dokumentieren.', 'Einsatzleitung'],
                ['action', 'Räumung koordinieren', 'Gemäß örtlichem Räumungskonzept handeln.', 'Bereichsleitung'],
            ] : [
                ['note', 'MANV-Lage bestätigen', 'Meldung, Umfang und Erstmaßnahmen dokumentieren.', 'Einsatzleitung'],
                ['action', 'Verkehrsregelung anpassen lassen', 'Zufahrten und Einbahnstraßenprinzip abstimmen.', 'Pforte / Sicherheitsdienst'],
                ['contact', 'Zusätzliches Personal alarmieren', 'Funktionen und benötigte Anzahl abstimmen.', 'Personalkoordination'],
                ['checklist', 'Versorgung vorbereiten', 'Bereitschaft der Bereiche rückmelden.', 'Medizinische Leitung'],
            ];
            const nodes = content.map(([type, name, text, owner]) => Object.assign(makeNode(type), { title: name, text, owner }));
            nodes.forEach((n, i) => { if (i) n.dependencies = [{ id: nodes[i - 1].id, when: nodes[i - 1].type === 'decision' ? 'yes' : 'always' }]; });
            const checklist = nodes.find(n => n.type === 'checklist');
            checklist.checks = fire ? ['Geschäftsführer alarmiert', 'Verwaltungsdirektor alarmiert', 'Pflegedirektion alarmiert'] : ['Aufnahme vorbereitet', 'Material bereitgestellt', 'Bereiche informiert'];
            definition = { title: fire ? 'Brandfall' : 'MANV', description: 'BEISPIEL – vor Veröffentlichung an örtliche Vorgaben anpassen und fachlich freigeben.', nodes };
            title.value = definition.title; description.value = definition.description; selected = nodes[0].id; planPanelPinned = false; mark(); render(); fitZoom();
        }));

        // ---- Ribbon-Reiter, Bereiche, Dialoge ----
        const tabs = $$('[data-ep-tab]');
        function switchTab(name) {
            tabs.forEach(tab => {
                const active = tab.dataset.epTab === name;
                tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1;
                $(`[data-ep-tabpanel="${tab.dataset.epTab}"]`).hidden = !active;
            });
        }
        tabs.forEach((tab, i) => {
            tab.addEventListener('click', () => switchTab(tab.dataset.epTab));
            tab.addEventListener('keydown', event => {
                const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
                if (!delta) return;
                event.preventDefault();
                const next = tabs[(i + delta + tabs.length) % tabs.length];
                switchTab(next.dataset.epTab); next.focus();
            });
        });
        $$('[data-ep-toggle-panel]').forEach(b => b.addEventListener('click', () => {
            const hidden = editor.classList.toggle('ep-hide-' + b.dataset.epTogglePanel);
            b.setAttribute('aria-pressed', String(!hidden));
        }));
        $$('[data-ep-open-dialog]').forEach(b => b.addEventListener('click', () => $(`[data-ep-dialog="${b.dataset.epOpenDialog}"]`).showModal()));
        $$('[data-ep-close-dialog]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
        $$('dialog').forEach(d => d.addEventListener('click', event => { if (event.target === d) d.close(); }));
        $('[data-ep-close]').addEventListener('click', async event => {
            const fallback = event.currentTarget.href;
            const standalone = window.opener || window.history.length <= 1;
            if (dirty || standalone) event.preventDefault();
            if (dirty && !await overlay({ heading: 'Editor schließen?', text: 'Es gibt ungespeicherte Änderungen. Editor trotzdem schließen?', confirm: 'Schließen', danger: true })) return;
            dirty = false;
            // Als eigener Tab geöffnet: Tab schließen; sonst (oder falls der Browser das verweigert) zur Übersicht.
            if (standalone) { window.close(); setTimeout(() => { location.href = fallback; }, 250); }
            else if (event.defaultPrevented) location.href = fallback;
        });

        // ---- Freigabe-Formulare: nur für den gespeicherten Entwurf ----
        document.querySelectorAll('[data-ep-review-form]').forEach(form => form.addEventListener('submit', async event => {
            if (dirty) {
                event.preventDefault();
                form.closest('dialog')?.close();
                notify('Bitte Änderungen zuerst speichern. Der Freigabeantrag gilt nur für den gespeicherten Entwurf.', 'Erst speichern');
                return;
            }
            if (!form.dataset.epConfirm) return;
            event.preventDefault();
            if (await overlay({ heading: 'Bitte bestätigen', text: form.dataset.epConfirm, danger: true })) form.submit();
        }));

        // ---- Speichern (POST /admin/notfallplan/speichern, optimistische Sperre über revision) ----
        let saving = false;
        async function save() {
            if (saving) return;
            if (issues === null) validate(false);
            const data = new FormData();
            data.set('_token', $('[name="_token"]').value);
            data.set('id', initial.id); data.set('revision', initial.revision);
            data.set('definition', JSON.stringify(definition));
            saving = true; message.textContent = 'Wird gespeichert …';
            // Keep editing disabled while the submitted revision is being committed.
            const controls = $$('input, textarea, select, button').filter(control => !control.disabled);
            controls.forEach(control => { control.disabled = true; });
            try {
                const result = await post(editor.dataset.saveUrl, data);
                initial.id = result.id; initial.revision = result.revision; dirty = false;
                $('[data-ep-dirty-flag]').hidden = true;
                history.replaceState(null, '', '/admin/notfallplan/bearbeiten?id=' + result.id);
                message.textContent = result.message + ' Version ' + result.revision + '.';
                setText('[data-ep-review-status]', 'Entwurf – zweite Freigabe erforderlich');
                setText('[data-ep-revision]', String(result.revision));
                const panel = document.querySelector('[data-ep-review-panel]');
                panel.replaceChildren(element('p', 'Der Entwurf wurde gespeichert. Für die Freigabe muss der gespeicherte Stand neu geladen werden.'));
                const link = element('a', 'Gespeicherten Entwurf neu laden und Freigabe anfordern', 'button button--primary');
                link.href = '/admin/notfallplan/bearbeiten?id=' + result.id;
                panel.append(link);
            } catch (error) { notify(error.message + ' Der Entwurf bleibt hier erhalten.', 'Speichern fehlgeschlagen'); }
            finally { saving = false; controls.forEach(control => { control.disabled = false; }); updateHistoryButtons(); renderInspector(); }
        }
        $$('[data-ep-save]').forEach(b => b.addEventListener('click', save));

        // ---- Tastenkürzel ----
        document.addEventListener('keydown', event => {
            const mod = event.ctrlKey || event.metaKey;
            const key = event.key.toLowerCase();
            const typing = /^(input|textarea|select)$/i.test(document.activeElement?.tagName || '');
            if (editor.querySelector('dialog.ep-alert[open]') || contextMenu) return;
            const onCanvas = !typing && canvas.contains(document.activeElement) && !!current();
            if (mod && key === 's') { event.preventDefault(); save(); }
            else if (mod && !event.shiftKey && key === 'd' && onCanvas) { event.preventDefault(); duplicate(); }
            else if (mod && !event.shiftKey && key === 'c' && onCanvas && !String(window.getSelection())) { event.preventDefault(); copyNode(); }
            else if (mod && !event.shiftKey && key === 'v' && !typing && canvas.contains(document.activeElement)) { event.preventDefault(); pasteNode(); }
            else if (mod && !event.shiftKey && key === 'z') { if (typing) return; event.preventDefault(); undo(); }
            else if (mod && (key === 'y' || (event.shiftKey && key === 'z'))) { if (typing) return; event.preventDefault(); redo(); }
            else if (mod && (key === '+' || key === '=')) { event.preventDefault(); setZoom(zoom * 1.2); }
            else if (mod && key === '-') { event.preventDefault(); setZoom(zoom / 1.2); }
            else if (mod && key === '0') { event.preventDefault(); setZoom(1); }
            else if (event.altKey && event.shiftKey && key === 'v') { event.preventDefault(); previewLinks[0].click(); }
            else if ((key === 'delete' || key === 'backspace') && !typing && canvas.contains(document.activeElement)) { event.preventDefault(); remove(); }
        });
        window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
        render();
        updateHistoryButtons();
        if (definition.nodes.length) requestAnimationFrame(fitZoom);
    }

    function renderStaticDiagram(root) {
        const staticDiagram = root.querySelector('[data-ep-static-diagram]');
        if (!staticDiagram) return;
        const definition = JSON.parse(root.querySelector('[data-ep-definition]').value);
        const progressInput = root.querySelector('[data-ep-state]');
        const progress = progressInput ? JSON.parse(progressInput.value) : null;
        diagram(staticDiagram, definition, null, id => {
            const card = document.getElementById('node-' + id);
            if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); card.setAttribute('tabindex', '-1'); card.focus({ preventScroll: true }); }
        }, progress);
    }
    renderStaticDiagram(document);

    const preview = document.querySelector('[data-ep-preview]');
    if (preview) {
        const content = preview.querySelector('[data-ep-preview-content]');
        const result = preview.querySelector('[data-ep-preview-result]');
        const connection = preview.querySelector('[data-ep-preview-connection]');
        const key = location.hash.slice(1);
        if (!('BroadcastChannel' in window) || !/^[a-f0-9-]{36}$/.test(key)) {
            connection.textContent = 'Keine Editorverbindung. Bitte die Live-Vorschau aus dem Editor mit einem aktuellen Browser öffnen.';
            return;
        }
        const channel = new BroadcastChannel('ep-preview-' + key);
        let definition = null;
        let version = -1;
        let renderedVersion = -1;
        let structure = '';
        let epoch = 0;
        let operations = [];
        let started = false;
        let startedAt = Math.floor(Date.now() / 1000);
        let lastSeen = 0;
        let busy = false;
        let queued = false;
        let timer;
        let pendingAction = null;
        let completedForm = null;
        let notice = '';
        const formKey = form => (form.elements.namedItem('node')?.value || '') + ':' + (form.querySelector('input[name="action"]')?.value || 'status');
        const controls = form => [...form.querySelectorAll('textarea:not([hidden]), select, input[type="checkbox"]')];

        // Only draft inputs in the preview are restored; hidden revision/CSRF fields always come from the server.
        function replaceContent(html, submittedForm, preserve) {
            const drafts = new Map();
            const focused = document.activeElement;
            const details = [...content.querySelectorAll('details')].map(item => item.open);
            const scroll = { x: window.scrollX, y: window.scrollY };
            if (preserve) content.querySelectorAll('form').forEach(form => {
                const id = formKey(form);
                if (id === submittedForm) return;
                drafts.set(id, controls(form).map(control => ({
                    name: control.name, value: control.value, checked: control.checked,
                    focused: control === focused, start: control.selectionStart, end: control.selectionEnd,
                })));
            });
            content.innerHTML = html;
            renderStaticDiagram(content);
            if (preserve) {
                content.querySelectorAll('details').forEach((item, i) => { if (details[i] !== undefined) item.open = details[i]; });
                content.querySelectorAll('form').forEach(form => {
                    const saved = drafts.get(formKey(form));
                    if (!saved) return;
                    controls(form).forEach((control, i) => {
                        const draft = saved[i];
                        if (!draft || draft.name !== control.name || control.disabled) return;
                        if (control.type === 'checkbox') control.checked = draft.checked;
                        else control.value = draft.value;
                        if (draft.focused) {
                            control.focus({ preventScroll: true });
                            if (control.setSelectionRange && draft.start !== null) control.setSelectionRange(draft.start, draft.end);
                        }
                    });
                });
                window.scrollTo(scroll.x, scroll.y);
            }
        }
        function schedule() {
            queued = true;
            content.inert = true;
            content.setAttribute('aria-busy', 'true');
            clearTimeout(timer);
            timer = setTimeout(renderPreview, 150);
        }
        async function renderPreview() {
            if (busy || !queued || !definition) return;
            busy = true; queued = false;
            const requestVersion = version;
            const requestEpoch = epoch;
            const action = pendingAction;
            pendingAction = null;
            const candidate = action ? [...operations, action.input] : operations;
            const data = new FormData();
            data.set('_token', preview.querySelector('[name="_token"]').value);
            data.set('preview', JSON.stringify({ definition, operations: candidate, started, startedAt }));
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const response = await post(preview.dataset.renderUrl, data, controller.signal);
                if (requestEpoch === epoch) {
                    operations = candidate;
                    if (action) completedForm = action.form;
                }
                if (requestVersion === version && requestEpoch === epoch) {
                    content.inert = false;
                    replaceContent(response.html, completedForm, renderedVersion !== -1 && !notice);
                    completedForm = null;
                    renderedVersion = version;
                    result.textContent = notice || (action ? 'Aktion simuliert. Keine produktiven Daten geändert.' : 'Vorschau aktuell – einschließlich ungespeicherter Änderungen.');
                    notice = '';
                } else queued = true;
            } catch (error) {
                if (requestVersion === version && requestEpoch === epoch) {
                    const message = controller.signal.aborted ? 'Vorschau-Server antwortet nicht. Bitte Verbindung erneut prüfen.' : error.message;
                    result.textContent = message + ' Der Editorentwurf bleibt erhalten. Angezeigt wird gegebenenfalls der letzte gültige Vorschau-Stand.';
                    if (action) {
                        const form = [...content.querySelectorAll('form')].find(form => formKey(form) === action.form);
                        const feedback = form?.querySelector('[data-ep-result]');
                        if (feedback) feedback.textContent = message;
                    }
                    content.inert = renderedVersion !== version || !!notice;
                }
            } finally {
                clearTimeout(timeout);
                busy = false;
                content.setAttribute('aria-busy', 'false');
                if (queued) schedule();
            }
        }
        function reset(toPlan) {
            epoch++;
            operations = [];
            pendingAction = null;
            completedForm = null;
            startedAt = Math.floor(Date.now() / 1000);
            if (toPlan) started = false;
            notice = 'Simulation zurückgesetzt. Der Editorentwurf ist unverändert.';
            schedule();
        }
        function sync() {
            channel.postMessage({ type: 'sync' });
            if (definition) schedule();
        }
        channel.onmessage = ({ data }) => {
            if (data?.type === 'disconnected') {
                lastSeen = 0;
                connection.textContent = 'Editor geschlossen oder verlassen. Letzter Stand bleibt sichtbar; zum Verbinden die Vorschau erneut aus dem Editor öffnen.';
                return;
            }
            if (data?.type !== 'definition' && data?.type !== 'alive') return;
            lastSeen = Date.now();
            connection.textContent = 'Live mit dem Editor verbunden. Änderungen werden ohne Neuladen übernommen.';
            if (data.type === 'alive') {
                if (data.version !== version) channel.postMessage({ type: 'sync' });
                return;
            }
            if (data.version === version) return;
            const nextStructure = JSON.stringify(data.definition.nodes.map(node => [node.id, node.type, node.dependencies, node.join, node.checks, node.alarm_id]));
            definition = data.definition;
            version = data.version;
            if (structure && nextStructure !== structure) {
                reset(false);
                notice = 'Ablaufstruktur geändert: Simulation zurückgesetzt. Texte und andere Editoränderungen bleiben erhalten.';
            }
            structure = nextStructure;
            schedule();
        };
        content.addEventListener('click', event => {
            if (event.target.closest('[data-ep-preview-start]')) {
                started = true;
                reset(false);
            }
            if (event.target.closest('[data-ep-print]')) window.print();
        });
        content.addEventListener('submit', event => {
            event.preventDefault();
            if (busy || queued || renderedVersion !== version) return;
            const form = event.target;
            if (!form.matches('[data-ep-update]')) return;
            if (form.dataset.epConfirm && !window.confirm(form.dataset.epConfirm)) return;
            const data = new FormData(form, event.submitter);
            pendingAction = { form: formKey(form), input: {
                action: data.get('action'), node: data.get('node') || '', status: data.get('status') || '',
                answer: data.get('answer') || '', checks: data.getAll('checks[]'), comment: data.get('comment') || '',
                at: Math.floor(Date.now() / 1000),
            } };
            schedule();
        });
        preview.querySelector('[data-ep-preview-reset]').addEventListener('click', () => reset(true));
        preview.querySelector('[data-ep-preview-retry]').addEventListener('click', sync);
        window.addEventListener('pageshow', sync);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); });
        window.setInterval(() => {
            if (!lastSeen || Date.now() - lastSeen > 15000) {
                connection.textContent = 'Keine aktuelle Editorverbindung. Letzter Stand kann veraltet sein; Editor geöffnet lassen oder Vorschau dort erneut öffnen.';
            }
            channel.postMessage({ type: definition ? 'ping' : 'sync' });
        }, 5000);
        sync();
    }

    const eventView = document.querySelector('[data-ep-event]');
    if (eventView) {
        const dirtyForms = new Set();
        const live = eventView.querySelector('[data-ep-live]');
        const refresh = eventView.querySelector('[data-ep-refresh]');
        let submitting = false;
        let changed = false;
        function reload() {
            if (dirtyForms.size && !window.confirm('Ungespeicherte Eingaben verwerfen und aktuellen Stand laden? Bitte Kommentare vorher sichern.')) return;
            dirtyForms.clear(); location.reload();
        }
        refresh.addEventListener('click', reload);
        eventView.querySelector('[data-ep-print]').addEventListener('click', () => window.print());
        eventView.querySelectorAll('[data-ep-update]').forEach(form => {
            form.addEventListener('input', () => dirtyForms.add(form));
            form.addEventListener('change', () => dirtyForms.add(form));
            form.addEventListener('submit', async event => {
                event.preventDefault();
                if (submitting) return;
                if (form.dataset.epConfirm && !window.confirm(form.dataset.epConfirm)) return;
                const result = form.querySelector('[data-ep-result]');
                const data = new FormData(form, event.submitter);
                submitting = true;
                const buttons = [...eventView.querySelectorAll('button[type="submit"], form button')];
                buttons.forEach(b => { b.disabled = true; });
                result.textContent = 'Wird gespeichert …';
                try {
                    await post(form.getAttribute('action'), data);
                    dirtyForms.delete(form);
                    if (!dirtyForms.size) { location.reload(); return; }
                    result.textContent = 'Gespeichert. Weitere ungespeicherte Eingaben bitte sichern und aktuellen Stand laden.';
                    refresh.hidden = false; changed = true;
                } catch (error) {
                    result.textContent = error.message + ' Eingabe bleibt erhalten. Bei unklarem Ergebnis zuerst den aktuellen Stand prüfen.';
                    refresh.hidden = false;
                } finally {
                    submitting = false; buttons.forEach(b => { b.disabled = false; });
                }
            });
        });
        async function poll() {
            if (document.hidden || submitting) return;
            try {
                const response = await fetch(eventView.dataset.statusUrl, { cache: 'no-store', credentials: 'same-origin' });
                if (!response.ok || !(response.headers.get('Content-Type') || '').includes('application/json')) throw new Error('Nicht erreichbar oder Zugriff entzogen.');
                const data = await response.json();
                if (data.revision !== Number(eventView.dataset.revision)) {
                    changed = true; refresh.hidden = false;
                    live.textContent = 'Neuer Stand verfügbar. Bitte aktualisieren; ungespeicherte Eingaben bleiben bis dahin erhalten.';
                    if (!dirtyForms.size && !submitting) location.reload();
                } else if (!changed) {
                    live.textContent = 'Stand bestätigt: ' + new Date(data.at).toLocaleTimeString('de-DE') + ' Uhr · Prüfung alle 10 Sekunden.';
                    live.classList.remove('ep-overdue');
                }
            } catch (error) {
                live.textContent = 'ACHTUNG: Keine aktuelle Verbindung. Angezeigter Stand kann veraltet sein. ' + error.message;
                live.classList.add('ep-overdue'); refresh.hidden = false;
            }
        }
        window.setInterval(poll, 10000);
        window.addEventListener('beforeunload', event => { if (dirtyForms.size) { event.preventDefault(); event.returnValue = ''; } });
    }
    document.querySelector('.ep-start')?.addEventListener('submit', event => {
        const button = event.currentTarget.querySelector('button[type="submit"], button');
        if (button) { button.disabled = true; button.textContent = 'Kennwort wird geprüft …'; }
    });
    document.querySelectorAll('[data-ep-print-guide]').forEach(button => button.addEventListener('click', () => window.print()));
})();
