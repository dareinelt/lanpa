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
    function diagram(container, definition, selected, onSelect, progress) {
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
            svg.append(group);
        });
        container.append(svg);
    }

    async function post(url, data) {
        const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
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
        const fields = editor.querySelector('[data-ep-fields]');
        const message = editor.querySelector('[data-ep-message]');
        const title = editor.querySelector('[data-ep-title]');
        const description = editor.querySelector('[data-ep-description]');
        const mark = () => { dirty = true; message.textContent = 'Ungespeicherte Änderungen.'; };
        const refreshGraph = () => diagram(editor.querySelector('[data-ep-diagram]'), definition, selected, select);
        const makeNode = type => ({ id: 'n' + crypto.randomUUID().replaceAll('-', ''), type, title: '', text: '', owner: '', phone: '', link: '', minutes: 0, checks: type === 'checklist' ? ['Prüfpunkt'] : [], dependencies: [], join: 'all', alarm_id: 0 });
        function select(id) { selected = id; render(); }
        function inputField(label, key, multiline, max) {
            const wrapper = element('label', label);
            const input = element(multiline ? 'textarea' : 'input');
            const node = definition.nodes.find(n => n.id === selected);
            input.value = node[key];
            if (multiline) input.rows = 3;
            if (max) input.maxLength = max;
            if (key === 'minutes') { input.type = 'number'; input.min = '0'; input.max = '10080'; }
            input.addEventListener('input', () => {
                node[key] = key === 'minutes' ? Number(input.value) : input.value;
                mark(); refreshGraph();
                if (key === 'title') renderList();
            });
            wrapper.append(input); fields.append(wrapper);
        }
        function button(text, fn) {
            const b = element('button', text, 'button button--ghost'); b.type = 'button'; b.addEventListener('click', fn); return b;
        }
        function renderList() {
            const list = editor.querySelector('[data-ep-list]'); list.replaceChildren();
            definition.nodes.forEach((n, i) => {
                const li = element('li');
                const pick = button(`${i + 1}. ${n.title || types[n.type]}`, () => select(n.id));
                if (n.id === selected) pick.setAttribute('aria-current', 'true');
                li.append(pick); list.append(li);
            });
        }
        function move(offset) {
            const index = definition.nodes.findIndex(n => n.id === selected), target = index + offset;
            if (target < 0 || target >= definition.nodes.length) return;
            const copy = definition.nodes.slice();
            [copy[index], copy[target]] = [copy[target], copy[index]];
            const seen = new Set();
            const valid = copy.every(n => { const ok = n.dependencies.every(e => seen.has(e.id)); seen.add(n.id); return ok; });
            if (!valid) { message.textContent = 'Verschieben würde eine Verbindung umkehren. Zuerst die betreffenden Vorgänger anpassen.'; return; }
            definition.nodes = copy; mark(); render();
        }
        function render() {
            renderList(); refreshGraph(); fields.replaceChildren();
            const node = definition.nodes.find(n => n.id === selected);
            if (!node) { fields.append(element('p', 'Wählen Sie ein Element.')); return; }
            fields.append(element('p', types[node.type], 'ep-inspector-type'));
            inputField('Titel / Frage', 'title', false, 190);
            inputField('Anweisung / Erläuterung', 'text', true, 4000);
            inputField('Zuständigkeit / Funktion', 'owner', false, 190);
            inputField('Telefon / Durchwahl', 'phone', false, 100);
            inputField('Informationslink (https://...)', 'link', false, 1000);
            inputField('Zielzeit in Minuten ab Ereignisstart (0 = keine)', 'minutes', false);
            if (node.type === 'checklist') {
                const label = element('label', 'Prüfpunkte (eine Zeile pro Punkt, maximal 20)');
                const input = element('textarea'); input.rows = 5; input.value = node.checks.join('\n');
                input.addEventListener('input', () => { node.checks = input.value.split('\n').map(s => s.trim()).filter(Boolean); mark(); });
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
                selectAlarm.addEventListener('change', () => { node.alarm_id = Number(selectAlarm.value); mark(); show(); });
                show(); label.append(selectAlarm); fields.append(label, preview);
                fields.append(element('p', 'Ziel und Nachricht werden beim Speichern in den Plan kopiert. Im Einsatz ist für diese SMS eine eigene Bestätigung nötig.'));
            }
            const edges = element('fieldset');
            edges.append(element('legend', 'Vorgänger / Verbindungen'));
            const prior = definition.nodes.slice(0, definition.nodes.indexOf(node));
            if (!prior.length) edges.append(element('p', 'Startpunkt: keine Vorgänger.'));
            prior.forEach(previous => {
                const row = element('label', undefined, 'ep-dependency');
                const check = element('input'); check.type = 'checkbox';
                const edge = node.dependencies.find(e => e.id === previous.id); check.checked = !!edge;
                row.append(check, document.createTextNode(previous.title || types[previous.type]));
                const condition = element('select');
                const options = previous.type === 'decision' ? { always: 'Erledigt (beliebige Antwort)', yes: 'Antwort Ja', no: 'Antwort Nein' } : { always: 'Erledigt' };
                Object.entries(options).forEach(([value, text]) => { const option = element('option', text); option.value = value; condition.append(option); });
                condition.value = edge?.when || 'always'; condition.disabled = !check.checked;
                const change = () => {
                    node.dependencies = node.dependencies.filter(e => e.id !== previous.id);
                    if (check.checked) node.dependencies.push({ id: previous.id, when: condition.value });
                    condition.disabled = !check.checked; mark(); refreshGraph();
                };
                check.addEventListener('change', change); condition.addEventListener('change', change);
                row.append(condition); edges.append(row);
            });
            const joinLabel = element('label', 'Freigabe der Maßnahme');
            const join = element('select');
            [['all', 'Alle Vorgänger (UND)'], ['any', 'Mindestens ein Vorgänger (ODER)']].forEach(([value, text]) => { const o = element('option', text); o.value = value; join.append(o); });
            join.value = node.join;
            join.addEventListener('change', () => { node.join = join.value; mark(); });
            joinLabel.append(join); edges.append(joinLabel); fields.append(edges);
            const actions = element('div', undefined, 'toolbar');
            actions.append(button('↑ Nach oben', () => move(-1)), button('↓ Nach unten', () => move(1)));
            actions.append(button('Duplizieren', () => {
                if (definition.nodes.length >= 80) { message.textContent = 'Maximal 80 Elemente.'; return; }
                const copy = structuredClone(node); copy.id = makeNode(node.type).id; copy.title = (copy.title + ' (Kopie)').slice(0, 190);
                definition.nodes.splice(definition.nodes.indexOf(node) + 1, 0, copy); selected = copy.id; mark(); render();
            }));
            actions.append(button('Element löschen', () => {
                if (!window.confirm('Element und alle zugehörigen Verbindungen löschen? Nachfolger können dadurch zu Startpunkten werden.')) return;
                definition.nodes = definition.nodes.filter(n => n.id !== node.id);
                definition.nodes.forEach(n => { n.dependencies = n.dependencies.filter(e => e.id !== node.id); });
                selected = definition.nodes[0]?.id; mark(); render();
            }));
            fields.append(actions);
        }
        editor.querySelector('[data-ep-add]').addEventListener('click', () => {
            if (definition.nodes.length >= 80) { message.textContent = 'Maximal 80 Elemente.'; return; }
            const node = makeNode(editor.querySelector('[data-ep-add-type]').value);
            if (definition.nodes.length) node.dependencies = [{ id: definition.nodes.at(-1).id, when: 'always' }];
            definition.nodes.push(node); selected = node.id; mark(); render();
        });
        editor.querySelectorAll('[data-ep-template]').forEach(b => b.addEventListener('click', () => {
            if (definition.nodes.length && !window.confirm('Aktuellen Entwurf durch Beispielvorlage ersetzen?')) return;
            const fire = b.dataset.epTemplate === 'fire';
            const content = fire ? [
                ['note', 'Eigenschutz und Notruf', 'Eigenschutz beachten. Örtlichen Notruf absetzen und Lage beschreiben.', 'Alle'],
                ['checklist', 'Leitung alarmieren', 'Erreichbarkeit und Rückmeldungen dokumentieren.', 'Einsatzkoordination'],
                ['action', 'Schranken öffnen', 'Zufahrt für Einsatzkräfte freihalten.', 'Technik / Pforte'],
                ['contact', 'Brandschutzhelfer alarmieren', 'Sammelpunkt und Lage mitteilen.', 'Einsatzkoordination'],
                ['decision', 'Räumung erforderlich?', 'Entscheidung der Einsatzleitung dokumentieren.', 'Einsatzleitung'],
                ['action', 'Räumung koordinieren', 'Gemäß örtlichem Räumungskonzept handeln.', 'Bereichsleitung'],
            ] : [
                ['note', 'MANF-Lage bestätigen', 'Meldung, Umfang und Erstmaßnahmen dokumentieren.', 'Einsatzleitung'],
                ['action', 'Verkehrsregelung anpassen lassen', 'Zufahrten und Einbahnstraßenprinzip abstimmen.', 'Pforte / Sicherheitsdienst'],
                ['contact', 'Zusätzliches Personal alarmieren', 'Funktionen und benötigte Anzahl abstimmen.', 'Personalkoordination'],
                ['checklist', 'Versorgung vorbereiten', 'Bereitschaft der Bereiche rückmelden.', 'Medizinische Leitung'],
            ];
            const nodes = content.map(([type, name, text, owner]) => Object.assign(makeNode(type), { title: name, text, owner }));
            nodes.forEach((n, i) => { if (i) n.dependencies = [{ id: nodes[i - 1].id, when: nodes[i - 1].type === 'decision' ? 'yes' : 'always' }]; });
            const checklist = nodes.find(n => n.type === 'checklist');
            checklist.checks = fire ? ['Geschäftsführer alarmiert', 'Verwaltungsdirektor alarmiert', 'Pflegedirektion alarmiert'] : ['Aufnahme vorbereitet', 'Material bereitgestellt', 'Bereiche informiert'];
            definition = { title: fire ? 'Brandfall' : 'MANF', description: 'BEISPIEL – vor Veröffentlichung an örtliche Vorgaben anpassen und fachlich freigeben.', nodes };
            title.value = definition.title; description.value = definition.description; selected = nodes[0].id; mark(); render();
        }));
        title.addEventListener('input', () => { definition.title = title.value; mark(); });
        description.addEventListener('input', () => { definition.description = description.value; mark(); });
        document.querySelectorAll('[data-ep-review-form]').forEach(form => form.addEventListener('submit', event => {
            if (dirty) {
                event.preventDefault();
                message.textContent = 'Bitte Änderungen zuerst speichern. Der Freigabeantrag gilt nur für den gespeicherten Entwurf.';
                message.scrollIntoView({ block: 'center' });
            }
        }));
        editor.querySelector('[data-ep-save]').addEventListener('click', async event => {
            const button = event.currentTarget;
            const data = new FormData();
            data.set('_token', editor.querySelector('[name="_token"]').value);
            data.set('id', initial.id); data.set('revision', initial.revision);
            data.set('definition', JSON.stringify(definition));
            button.disabled = true; message.textContent = 'Wird gespeichert …';
            // Keep editing disabled while the submitted revision is being committed.
            const controls = [...editor.querySelectorAll('input, textarea, select, button')].filter(control => !control.disabled);
            controls.forEach(control => { control.disabled = true; });
            try {
                const result = await post(editor.dataset.saveUrl, data);
                initial.id = result.id; initial.revision = result.revision; dirty = false;
                history.replaceState(null, '', '/admin/notfallplan/bearbeiten?id=' + result.id);
                message.textContent = result.message + ' Version ' + result.revision;
                editor.querySelector('[data-ep-review-status]').textContent = 'Entwurf – zweite Freigabe erforderlich';
                const panel = document.querySelector('[data-ep-review-panel]');
                panel.replaceChildren(element('h2', 'Vier-Augen-Freigabe'));
                const link = element('a', 'Gespeicherten Entwurf öffnen und Freigabe anfordern', 'button button--primary');
                link.href = '/admin/notfallplan/bearbeiten?id=' + result.id;
                panel.append(link);
            } catch (error) { message.textContent = error.message + ' Der Entwurf bleibt hier erhalten.'; }
            finally { button.disabled = false; controls.forEach(control => { control.disabled = false; }); }
        });
        window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
        render();
    }

    const staticDiagram = document.querySelector('[data-ep-static-diagram]');
    if (staticDiagram) {
        const definition = JSON.parse(document.querySelector('[data-ep-definition]').value);
        const progressInput = document.querySelector('[data-ep-state]');
        const progress = progressInput ? JSON.parse(progressInput.value) : null;
        diagram(staticDiagram, definition, null, id => {
            const card = document.getElementById('node-' + id);
            if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); card.setAttribute('tabindex', '-1'); card.focus({ preventScroll: true }); }
        }, progress);
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
