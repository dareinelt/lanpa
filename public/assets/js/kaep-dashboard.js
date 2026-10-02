(() => {
    'use strict';
    const root = document.getElementById('kaep');
    if (!root) return;
    const $ = id => document.getElementById(`kd-${id}`);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const utc = value => value ? Date.parse(value.includes('T') && /Z$|[+-]\d\d:\d\d$/.test(value) ? value : value.replace(' ', 'T') + 'Z') : NaN;
    const date = value => value ? new Date(utc(value)).toLocaleString('de-DE', {dateStyle: 'medium', timeStyle: 'short'}) : 'Nicht festgelegt';
    const pad = n => String(n).padStart(2, '0');
    // datetime-local arbeitet in der Gerätezeitzone; der Server speichert und erwartet UTC.
    const inputDate = value => {
        if (!value) return '';
        const d = new Date(utc(value));
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    };
    const toUtcInput = local => {
        if (!local) return '';
        const d = new Date(local);
        return Number.isNaN(d.getTime()) ? local : `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
    };
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Gerätezeit';
    const relative = ms => {
        const abs = Math.abs(ms);
        const minutes = Math.round(abs / 60000);
        const text = minutes < 1 ? 'jetzt' : minutes < 60 ? `${minutes} Min.` : minutes < 1440 ? `${Math.floor(minutes / 60)} Std. ${pad(minutes % 60)} Min.` : `${Math.floor(minutes / 1440)} T ${Math.floor(minutes / 60) % 24} Std.`;
        return ms < 0 ? `seit ${text}` : minutes < 1 ? text : `in ${text}`;
    };
    const labels = {open: 'Offen', in_progress: 'In Arbeit', blocked: 'Blockiert', done: 'Erledigt', skipped: 'Entfällt', waiting: 'Wartet auf Voraussetzung'};
    const types = {action: 'Maßnahme', contact: 'Kontakt', decision: 'Entscheidung', checklist: 'Checkliste', note: 'Hinweis', sms: 'SMS-Alarmierung'};
    const priorities = {normal: 'Normal', high: 'Hoch', critical: 'Kritisch'};
    const journalTypes = {
        handover: ['handover'], journal: ['journal'], situation: ['situation'], leadership: ['leadership'], assignment: ['assignment'], reminder: ['reminder'],
        status: ['status', 'comment', 'check_done', 'check_reopened', 'closed'], sms: ['sms_requested', 'sms_success', 'sms_error', 'sms_pending'], system: ['started', 'email_queued', 'email_sent', 'email_failed'],
    };
    const baseTitle = document.title;
    const params = new URLSearchParams(location.search);
    let selected = Math.max(0, Number(params.get('id')) || 0);
    let page = 1, model = null, loading = false, reloadPending = false, heartbeat = 0, serverOffset = 0;
    let dataHealthy = false, stream = null, journalRows = [], journalMore = false, journalGeneration = 0, journalHistorical = false;
    let dialogContext = null, submitting = false;
    const dialog = $('dialog');
    const now = () => Date.now() + serverOffset;
    const active = () => model?.event?.status === 'active';
    const connected = () => dataHealthy && Date.now() - heartbeat < 12000;
    const error = message => { $('error').textContent = message; $('error').hidden = !message; };

    async function json(url, options = {}) {
        const response = await fetch(url, {cache: 'no-store', ...options, signal: AbortSignal.timeout(20000)});
        if (!response.headers.get('content-type')?.includes('application/json')) {
            throw new Error(response.status === 403 || response.status === 401
                ? 'Zugriff nicht mehr erlaubt. Bitte erneut mit einem berechtigten Konto anmelden.'
                : response.status === 419 ? 'Sitzung abgelaufen. Eingaben sichern und die Seite nach erneuter Anmeldung öffnen.'
                : `Serverantwort nicht lesbar (HTTP ${response.status}). Stand nicht bestätigt.`);
        }
        const result = await response.json();
        if (!response.ok) {
            const exception = new Error(result.error || `Anfrage fehlgeschlagen (HTTP ${response.status}).`);
            exception.status = response.status;
            throw exception;
        }
        return result;
    }

    function assignment(node) {
        return model.event.coordination.assignments?.[node.id] || {};
    }
    function status(node) {
        return model.ready[node.id] === 'skipped' ? 'skipped' : (model.event.state[node.id]?.status || 'open');
    }
    function due(node) {
        return assignment(node).due ? utc(assignment(node).due) : node.minutes > 0 ? utc(model.event.started_at) + node.minutes * 60000 : NaN;
    }
    function overdue(node) {
        return active() && !['done', 'skipped'].includes(status(node)) && due(node) < now();
    }
    function owner(node) { return assignment(node).owner || node.owner || ''; }
    function critical(node) { return status(node) === 'blocked' || overdue(node) || (!['done', 'skipped'].includes(status(node)) && assignment(node).priority === 'critical'); }
    function preserveFocus(container, html) {
        const focused = container.contains(document.activeElement) ? document.activeElement.id : '';
        container.innerHTML = html;
        if (focused) document.getElementById(focused)?.focus({preventScroll: true});
    }
    function updateUrl() {
        const url = new URL(location.href);
        if (selected) url.searchParams.set('id', String(selected)); else url.searchParams.delete('id');
        history.replaceState(null, '', url);
    }

    async function load() {
        if (loading) { reloadPending = true; return; }
        loading = true;
        const requested = `${selected}:${page}:${$('event-status').value}`;
        try {
            const result = await json(`/kaep-dashboard/daten?${new URLSearchParams({id: selected, page, status: $('event-status').value})}`);
            if (requested !== `${selected}:${page}:${$('event-status').value}`) { reloadPending = true; return; }
            const changedEvent = Number(result.event?.id || 0) !== Number(model?.event?.id || 0);
            model = result;
            selected = Number(result.event?.id || 0);
            serverOffset = utc(result.serverTime) - Date.now();
            dataHealthy = true;
            error('');
            updateUrl();
            render();
            if (changedEvent) {
                resetJournal();
                $('journal-node').innerHTML = '<option value="">Gesamter Einsatz</option>' + (model.event?.snapshot.nodes || []).map(n => `<option value="${escape(n.id)}">${escape(n.title)}</option>`).join('');
                $('journal-search').value = '';
            }
            await loadJournal(false);
        } catch (exception) {
            dataHealthy = false;
            error(`${exception.message} Letzten sichtbaren Stand nicht als aktuell behandeln.`);
        } finally {
            loading = false;
            updateConnection();
            if (reloadPending) { reloadPending = false; void load(); }
        }
    }

    function render() {
        preserveFocus($('events'), model.events.items.map(event => `<button type="button" id="kd-event-${Number(event.id)}" class="kd-event" data-event="${Number(event.id)}" aria-current="${Number(event.id) === selected}"><strong>#${Number(event.id)} ${escape(event.title)}</strong><small>${escape(date(event.started_at))} · ${event.status === 'active' ? 'Laufend' : 'Abgeschlossen'}</small></button>`).join('') || '<p>Keine Ereignisse in dieser Auswahl.</p>');
        $('page').textContent = `${page} / ${Math.max(1, Math.ceil(model.events.total / 30))}`;
        $('prev').disabled = page <= 1;
        $('next').disabled = page * 30 >= model.events.total;
        $('content').hidden = !model.event;
        $('empty').hidden = !!model.event;
        $('empty').textContent = 'Kein Ereignis ausgewählt. Neu ausgelöste Notfallpläne erscheinen hier automatisch.';
        if (!model.event) return;
        const event = model.event;
        $('title').textContent = `#${event.id} ${event.title}`;
        $('event-state').textContent = active() ? 'Laufender Einsatz' : 'Abgeschlossen · nur lesbar';
        $('start').textContent = `Ausgelöst ${date(event.started_at)} · ${event.actor}${event.closed_at ? ` · Ende ${date(event.closed_at)}` : ''}`;
        $('duration-label').textContent = active() ? 'Seit Auslösung' : 'Einsatzdauer';
        $('situation').textContent = event.coordination.situation || 'Noch keine Lageübersicht dokumentiert.';
        $('briefing').textContent = event.coordination.briefing ? `Nächste Lagebesprechung: ${date(event.coordination.briefing)}` : 'Nächste Lagebesprechung noch nicht festgelegt.';
        $('updated').textContent = `Datenstand ${date(model.serverTime)} · Version ${event.revision}`;
        root.querySelectorAll('.kd-edit').forEach(item => { item.hidden = !active(); });
        preserveFocus($('leaders'), Object.entries(event.coordination.leadership || {}).map(([role, leader], i) =>
            `<div class="kd-leader"><strong>${escape(role)}</strong><span>${escape(leader.person)}</span><span>${escape(leader.phone || 'Keine Erreichbarkeit hinterlegt')}</span>${leader.until ? `<span class="${active() && utc(leader.until) < now() ? 'kd-urgent' : ''}">Ablösung geplant: ${escape(date(leader.until))} (${escape(relative(utc(leader.until) - now()))})</span>` : ''}${active() ? `<button type="button" class="button" id="kd-leader-${i}" data-leader="${escape(role)}">Bearbeiten</button>` : ''}</div>`
        ).join('') || '<p>Optional: Einsatzleitung und Bereichsleitungen mit Erreichbarkeit und geplanter Ablösung eintragen.</p>');
        const mailLabels = {queued: 'Wartet auf Versand', sending: 'Versand läuft', sent: 'SMTP-Annahme bestätigt', failed: 'Fehlgeschlagen'};
        const smsLabels = {pending: 'Ergebnis ungeklärt', success: 'Gateway-Annahme bestätigt', error: 'Fehler / Ergebnis ungeklärt'};
        const uncertain = model.notifications.filter(m => m.status !== 'sent').length + Object.values(event.sms).filter(s => s.status !== 'success').length;
        $('notification-summary').textContent = `Alarm- und Benachrichtigungsstatus · ${uncertain} offen / ungeklärt${model.notifications.length ? '' : ' · Keine E-Mail-Empfänger!'}`;
        $('mail').innerHTML = (model.notifications.map(mail => `<li>${escape(mail.recipient)}: <strong>${escape(mailLabels[mail.status] || mail.status)}</strong> · Versuch ${Number(mail.attempts)}/3 · ${escape(mail.message)}</li>`).join('') || '<li>Keine E-Mail-Empfänger hinterlegt. KAEP-Team anderweitig informieren.</li>')
            + Object.entries(event.sms).map(([id, sms]) => `<li>SMS · ${escape(event.snapshot.nodes.find(n => n.id === id)?.title || id)}: <strong>${escape(smsLabels[sms.status] || sms.status)}</strong> · ${escape(sms.message)}</li>`).join('');
        renderBoard();
        renderSchedule();
        tick();
        updateDialog();
        document.dispatchEvent(new Event('kaep:render'));
    }

    function scheduleItems() {
        const event = model.event;
        const items = [];
        if (event.coordination.briefing) items.push({at: utc(event.coordination.briefing), kind: 'Lagebesprechung', title: 'Nächste Lagebesprechung', dialog: 'situation'});
        Object.entries(event.coordination.leadership || {}).forEach(([role, leader]) => {
            if (leader.until) items.push({at: utc(leader.until), kind: 'Ablösung', title: `${role}: ${leader.person}`, leader: role});
        });
        event.snapshot.nodes.forEach(node => {
            if (!['done', 'skipped'].includes(status(node)) && Number.isFinite(due(node))) items.push({at: due(node), kind: 'Zielzeit', title: node.title, detail: owner(node) || 'Zuständigkeit offen', node: node.id});
        });
        (event.coordination.reminders || []).forEach(reminder => {
            if (!reminder.done) items.push({at: utc(reminder.due), kind: 'Wiedervorlage', title: reminder.title, detail: reminder.node ? event.snapshot.nodes.find(n => n.id === reminder.node)?.title : '', reminder: reminder.key});
        });
        return items.sort((a, b) => a.at - b.at);
    }
    function renderSchedule() {
        if (!model?.event) return;
        const items = scheduleItems();
        const html = items.map(item => {
            const late = active() && item.at < now();
            const open = item.node ? `data-node="${escape(item.node)}"` : item.dialog ? `data-dialog="${escape(item.dialog)}"` : item.leader ? `data-leader="${escape(item.leader)}"` : '';
            return `<li class="kd-timeline-item${late ? ' kd-timeline-item--late' : ''}" data-schedule-at="${item.at}">
                <time>${escape(date(new Date(item.at).toISOString()))}</time><span class="kd-timeline-relative${late ? ' kd-urgent' : ''}">${escape(relative(item.at - now()))}</span>
                <div><small>${escape(item.kind)}</small><strong>${escape(item.title)}</strong>${item.detail ? `<span>${escape(item.detail)}</span>` : ''}</div>
                <div class="kd-timeline-actions">${open && active() ? `<button type="button" class="button kd-edit" ${open}>Öffnen</button>` : ''}${item.reminder && active() ? `<button type="button" class="button button--primary kd-edit" data-reminder-done="${escape(item.reminder)}">Erledigt</button><button type="button" class="button kd-edit" data-reminder-remove="${escape(item.reminder)}" aria-label="Wiedervorlage „${escape(item.title)}“ entfernen">Entfernen</button>` : ''}</div>
            </li>`;
        }).join('');
        preserveFocus($('schedule'), html || '<li class="kd-hint">Keine Termine, Zielzeiten oder Wiedervorlagen. Bei mehrtägigen Einsätzen Lagebesprechungen, Ablösungen und Rückrufe hier planen.</li>');
        const done = (model.event.coordination.reminders || []).filter(r => r.done);
        $('reminders-done-wrap').hidden = done.length === 0;
        $('reminders-done-summary').textContent = `Erledigte Wiedervorlagen (${done.length})`;
        $('reminders-done').innerHTML = done.map(r => `<li>${escape(r.title)} · fällig ${escape(date(r.due))}</li>`).join('');
    }
    function updateSchedule() {
        if (!model?.event) return;
        root.querySelectorAll('[data-schedule-at]').forEach(item => {
            const at = Number(item.dataset.scheduleAt);
            const late = active() && at < now();
            const rel = item.querySelector('.kd-timeline-relative');
            const text = relative(at - now());
            if (rel.textContent !== text) rel.textContent = text;
            rel.classList.toggle('kd-urgent', late);
            item.classList.toggle('kd-timeline-item--late', late);
        });
    }

    function renderBoard() {
        if (!model?.event) return;
        const query = $('search').value.toLocaleLowerCase('de');
        const filter = $('filter').value;
        const nodes = model.event.snapshot.nodes.filter(node => {
            if (!`${node.title} ${node.text} ${owner(node)}`.toLocaleLowerCase('de').includes(query)) return false;
            if (filter === 'outstanding') return !['done', 'skipped'].includes(status(node));
            if (filter === 'critical') return critical(node);
            if (filter === 'unassigned') return !owner(node) && !['done', 'skipped'].includes(status(node));
            return true;
        });
        const lanes = {open: [], in_progress: [], blocked: [], done: []};
        nodes.forEach(node => lanes[status(node) === 'skipped' ? 'done' : status(node)].push(node));
        preserveFocus($('board'), Object.entries(lanes).map(([lane, items]) => `<section class="kd-lane"><h3>${lane === 'done' ? 'Erledigt / entfällt' : labels[lane]} · ${items.length}</h3>${items.map(node => {
            const s = status(node);
            const dep = node.dependencies.map(edge => `${model.event.snapshot.nodes.find(n => n.id === edge.id)?.title || edge.id}${edge.when === 'always' ? '' : edge.when === 'yes' ? ' (Ja)' : ' (Nein)'}`).join(', ');
            return `<button type="button" id="kd-node-${escape(node.id)}" class="kd-node kd-node--${s}" data-node="${escape(node.id)}">
                <small>${escape(types[node.type])} · ${escape(labels[s])}</small><strong>${escape(node.title)}</strong>
                <span>${escape(owner(node) || 'Zuständigkeit offen')}</span>
                ${model.ready[node.id] === 'waiting' ? '<span>Wartet auf Voraussetzung</span>' : ''}
                ${dep ? `<small>Nach (${node.join === 'any' ? 'ODER' : 'UND'}): ${escape(dep)}</small>` : ''}
                ${node.type === 'checklist' ? `<span>Prüfpunkte ${model.event.state[node.id]?.checks?.length || 0} / ${node.checks.length}</span>` : ''}
                ${assignment(node).priority && assignment(node).priority !== 'normal' ? `<span class="kd-urgent">Priorität ${priorities[assignment(node).priority]}</span>` : ''}
                ${Number.isFinite(due(node)) ? `<span data-due-node="${escape(node.id)}"></span>` : ''}
            </button>`;
        }).join('') || '<p class="kd-hint">Keine Maßnahmen</p>'}</section>`).join(''));
        updateDeadlines();
    }

    function updateDeadlines() {
        if (!model?.event) return;
        root.querySelectorAll('[data-due-node]').forEach(item => {
            const node = model.event.snapshot.nodes.find(n => n.id === item.dataset.dueNode);
            item.textContent = `${overdue(node) ? 'Überfällig · ' : 'Zielzeit · '}${new Date(due(node)).toLocaleString('de-DE', {dateStyle: 'medium', timeStyle: 'short'})}`;
            item.classList.toggle('kd-urgent', overdue(node));
        });
    }
    function tick() {
        updateConnection();
        if (!model?.event) return;
        const event = model.event;
        const seconds = Math.max(0, Math.floor(((event.closed_at ? utc(event.closed_at) : now()) - utc(event.started_at)) / 1000));
        const days = Math.floor(seconds / 86400);
        const h = String(Math.floor(seconds / 3600) % 24).padStart(2, '0');
        const m = String(Math.floor(seconds / 60) % 60).padStart(2, '0');
        const s = String(seconds % 60).padStart(2, '0');
        $('duration').textContent = `${days ? `${days} T ` : ''}${h}:${m}:${s}`;
        const nodes = event.snapshot.nodes;
        const done = nodes.filter(n => status(n) === 'done').length;
        const skipped = nodes.filter(n => status(n) === 'skipped').length;
        const late = nodes.filter(overdue).length;
        const openReminders = (event.coordination.reminders || []).filter(r => !r.done && active() && utc(r.due) < now()).length;
        const metrics = [
            ['Noch offen', nodes.length - done - skipped],
            ['In Arbeit', nodes.filter(n => status(n) === 'in_progress').length],
            ['Blockiert', nodes.filter(n => status(n) === 'blocked').length],
            ['Überfällig', late],
            ['Erledigt', `${done}/${nodes.length - skipped}`],
        ];
        const metricsHtml = metrics.map(([label, count], i) => `<div class="kd-metric${i === 2 || i === 3 ? ' kd-metric--alert' : ''}"><strong>${count}</strong><span>${label}</span></div>`).join('');
        if ($('metrics').innerHTML !== metricsHtml) $('metrics').innerHTML = metricsHtml;
        $('progress').max = Math.max(1, nodes.length - skipped);
        $('progress').value = done;
        const alerts = late + openReminders + nodes.filter(n => status(n) === 'blocked').length;
        const title = `${alerts && active() ? `(${alerts}) ` : ''}#${event.id} ${event.title} · ${baseTitle}`;
        if (document.title !== title) document.title = title;
        updateDeadlines();
        updateSchedule();
    }
    function updateConnection() {
        const live = connected();
        const label = live ? 'Live verbunden' : 'Nicht aktuell · Verbindung wird wiederhergestellt';
        if ($('connection').textContent !== label) $('connection').textContent = label;
        $('connection').className = `kd-badge kd-badge--${live ? 'live' : 'offline'}`;
        if (dialog.open) {
            dialog.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = submitting || !live || !active() || !!button.closest('[data-readonly]'); });
        }
    }
    function connect() {
        stream?.close();
        stream = new EventSource('/kaep-dashboard/live');
        stream.onmessage = message => {
            const data = JSON.parse(message.data);
            heartbeat = Date.now();
            if (!model || model.token !== data.token || !dataHealthy) void load();
            updateConnection();
        };
        stream.onerror = () => { heartbeat = 0; updateConnection(); if (dataHealthy) void load(); };
        stream.addEventListener('renew', connect);
        stream.addEventListener('unavailable', () => {
            heartbeat = 0;
            dataHealthy = false;
            error('Live-Dienst gestört. Letzten sichtbaren Stand nicht als aktuell behandeln.');
            updateConnection();
        });
    }

    function resetJournal() {
        journalRows = [];
        journalHistorical = false;
        journalGeneration++;
    }
    async function loadJournal(older) {
        if (!model?.event) { journalRows = []; renderJournal(); return; }
        if (!older && journalHistorical) { renderJournal(); return; }
        const generation = ++journalGeneration;
        const id = selected;
        const node = $('journal-node').value;
        const before = older && journalRows.length ? journalRows[journalRows.length - 1].id : 0;
        $('more').disabled = true;
        try {
            const result = !older && !node ? {logs: model.logs} : await json(`/kaep-dashboard/journal?${new URLSearchParams({id, node, before})}`);
            if (generation !== journalGeneration || id !== selected || node !== $('journal-node').value) return;
            const rows = result.logs;
            journalMore = rows.length === 100;
            if (older && rows.length === 0) { renderJournal(); return; }
            journalHistorical = older;
            journalRows = rows;
            renderJournal();
        } catch (exception) {
            $('journal-info').textContent = `Journal nicht aktualisiert: ${exception.message}`;
        } finally {
            if (generation === journalGeneration) $('more').disabled = false;
        }
    }
    function renderJournal() {
        const query = $('journal-search').value.toLocaleLowerCase('de');
        const type = $('journal-type').value;
        const titles = Object.fromEntries((model?.event?.snapshot.nodes || []).map(n => [n.id, n.title]));
        const rows = journalRows.filter(row => (!type || (journalTypes[type] || []).includes(row.action))
            && `${row.actor} ${row.message} ${titles[row.node_id] || ''}`.toLocaleLowerCase('de').includes(query));
        let day = '';
        $('journal').innerHTML = rows.map(row => {
            const rowDay = new Date(utc(row.created_at)).toLocaleDateString('de-DE', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'});
            const separator = rowDay !== day ? `<li class="kd-day" aria-label="Tag"><strong>${escape(rowDay)}</strong></li>` : '';
            day = rowDay;
            return `${separator}<li class="kd-entry kd-entry--${escape(row.action)}${row.action === 'handover' ? ' kd-handover' : ''}"><header><time>${escape(new Date(utc(row.created_at)).toLocaleTimeString('de-DE', {timeStyle: 'short'}))}</time> · <strong>${escape(row.actor)}</strong> · ${escape(titles[row.node_id] || 'Einsatz')} · #${Number(row.id)}</header><p class="kd-pre">${escape(row.message)}</p></li>`;
        }).join('') || '<li>Keine passenden Einträge im geladenen Zeitraum.</li>';
        $('more').hidden = !journalMore;
        $('latest').hidden = !journalHistorical;
        $('journal-info').textContent = `${journalRows.length} Einträge im Ausschnitt, ${rows.length} sichtbar. Art- und Textfilter beziehen sich auf diesen Ausschnitt.${journalHistorical ? ' Historischer Ausschnitt: Neue Einträge über „Zurück zum Live-Journal“ anzeigen. Maßnahmenlage bleibt live.' : ' Neueste Einträge werden live angezeigt.'}`;
    }

    function field(name, label, value = '', max = 190, type = 'text') {
        return `<label>${label}<input name="${name}" type="${type}" value="${escape(value)}" maxlength="${max}"></label>`;
    }
    function textarea(name, label, value = '', max = 2000) {
        return `<label>${label}<textarea name="${name}" rows="3" maxlength="${max}">${escape(value)}</textarea></label>`;
    }
    function options(values, current) {
        return Object.entries(values).map(([value, label]) => `<option value="${escape(value)}"${value === current ? ' selected' : ''}>${escape(label)}</option>`).join('');
    }
    function form(action, content, label, node = '', danger = false) {
        return `<form data-action="${action}" data-node="${escape(node)}">${content}<button type="submit" class="button button--${danger ? 'danger' : 'primary'}">${label}</button></form>`;
    }
    function openDialog(kind, key = '') {
        if (!model?.event) return;
        dialogContext = {kind, key, revision: Number(model.event.revision), id: selected};
        $('result').textContent = '';
        $('rebase').hidden = true;
        let html = '';
        const c = model.event.coordination;
        const comment = textarea('comment', 'Notiz / Begründung');
        if (kind === 'node') {
            const node = model.event.snapshot.nodes.find(n => n.id === key);
            if (!node) return;
            $('dialog-title').textContent = node.title;
            const state = model.event.state[key] || {status: 'open', checks: [], answer: ''};
            html = `<p class="kd-pre">${escape(node.text)}</p><p>${escape(types[node.type])} · Vorgabe: ${escape(node.owner || 'nicht zugewiesen')}</p>`
                + (node.phone ? `<p>Telefon: ${escape(node.phone)}</p>` : '')
                + (node.link ? `<p><a href="${escape(node.link)}" target="_blank" rel="noopener noreferrer">Weiterführende Information</a></p>` : '')
                + (node.dependencies.length ? `<p>Voraussetzungen (${node.join === 'any' ? 'mindestens eine' : 'alle'}): ${node.dependencies.map(edge => escape(model.event.snapshot.nodes.find(n => n.id === edge.id)?.title || edge.id) + (edge.when === 'always' ? '' : edge.when === 'yes' ? ' = Ja' : ' = Nein')).join('; ')}</p>` : '');
            if (node.type === 'checklist') {
                html += '<h3>Bestätigte Prüfpunkte</h3><ul>' + node.checks.map((check, i) => {
                    const detail = state.check_details?.[i];
                    return `<li>${state.checks.includes(i) ? 'Bestätigt' : 'Offen'}: ${escape(check)}${detail ? ` · ${escape(detail.actor)} · ${escape(date(detail.at))}` : ''}</li>`;
                }).join('') + '</ul>';
            }
            if (state.answer) html += `<p>Entscheidung: <strong>${state.answer === 'yes' ? 'Ja' : 'Nein'}</strong></p>`;
            if (node.type === 'sms') {
                const sms = model.event.sms[key];
                html += `<h3>SMS-Alarmierung</h3><p>An: ${escape(node.alarm.alarm_group_number)}</p><blockquote>${escape(node.alarm.alarm_text)}</blockquote><p>${escape(sms?.message || 'Noch nicht angefordert.')}</p><p>Gateway-Annahme bestätigt keine Zustellung. Bei ungeklärtem Ergebnis nicht erneut auslösen; Ersatzmeldeweg dokumentieren.</p>`;
                if (active() && model.ready[key] === 'ready' && state.status !== 'done' && !sms) {
                    html += form('sms', '', 'SMS separat bestätigen und senden', key, true);
                }
            }
            if (active()) {
                if (model.ready[key] === 'ready' && state.status !== 'done') {
                    let controls = `<label>Status<select name="status">${options({open: 'Offen', in_progress: 'In Arbeit', blocked: 'Blockiert', done: 'Erledigt'}, state.status)}</select></label>`;
                    if (node.type === 'decision') controls += `<label>Entscheidung<select name="answer">${options({'': 'Bitte wählen', yes: 'Ja', no: 'Nein'}, state.answer)}</select></label>`;
                    if (node.type === 'checklist') controls += `<fieldset><legend>Prüfpunkte</legend>${node.checks.map((check, i) => `<label class="kd-check"><input type="checkbox" name="checks[]" value="${i}"${state.checks.includes(i) ? ' checked' : ''}>${escape(check)}</label>`).join('')}</fieldset>`;
                    html += form('status', controls + comment, 'Status und Notiz speichern', key);
                }
                html += form('journal', comment, 'Nur Notiz hinzufügen', key);
                const a = assignment(node);
                html += form('assignment', '<h3>Zuständigkeit und Zielzeit</h3>'
                    + field('owner', 'Zuständige Person / Team (leer = Planvorgabe)', a.owner || '')
                    + `<label>Priorität<select name="priority">${options(priorities, a.priority || 'normal')}</select></label>`
                    + field('due', `Zielzeit in Ihrer Zeitzone (${timezone}; leer = Planvorgabe)`, inputDate(a.due), 20, 'datetime-local')
                    + comment, 'Zuständigkeit speichern', key);
                html += `<button type="button" class="button" data-dialog="reminder" data-reminder-node="${escape(key)}">Wiedervorlage zu dieser Maßnahme</button> `;
            }
            html += `<button type="button" class="button" data-show-journal="${escape(key)}">Journal dieser Maßnahme anzeigen</button>`;
        } else if (kind === 'situation') {
            $('dialog-title').textContent = 'Lageübersicht aktualisieren';
            html = form(kind, textarea('situation', 'Aktuelle Lage, Ziele und wichtige Hinweise', c.situation || '', 4000)
                + field('briefing', `Nächste Lagebesprechung in Ihrer Zeitzone (${timezone}; optional)`, inputDate(c.briefing), 20, 'datetime-local'), 'Lage speichern');
        } else if (kind === 'leadership') {
            $('dialog-title').textContent = 'Einsatz- / Bereichsleitung';
            const leader = c.leadership?.[key] || {};
            html = form(kind, field('role', 'Leitungsbereich (z. B. Einsatzleitung oder Pflege)', key, 100)
                + field('person', 'Person / Team (leer = Besetzung entfernen)', leader.person || '')
                + field('phone', 'Erreichbarkeit / Telefon', leader.phone || '', 100)
                + field('until', `Geplante Ablösung / Schichtende in Ihrer Zeitzone (${timezone}; optional)`, inputDate(leader.until), 20, 'datetime-local')
                + comment, 'Besetzung speichern');
        } else if (kind === 'reminder') {
            $('dialog-title').textContent = 'Wiedervorlage anlegen';
            const node = model.event.snapshot.nodes.find(n => n.id === key);
            html = (node ? `<p>Bezug: ${escape(node.title)}</p>` : '<p>Rückrufe, Fristen, Kontrollgänge oder Meldungen an externe Stellen terminieren. Fällige Wiedervorlagen erscheinen im Zeitplan; es erfolgt keine automatische Alarmierung.</p>')
                + form(kind, '<input type="hidden" name="mode" value="add">'
                    + field('title', 'Wiedervorlage (z. B. „Rückruf Feuerwehr Leitstelle“)', '', 190)
                    + field('due', `Fällig in Ihrer Zeitzone (${timezone})`, '', 20, 'datetime-local')
                    + comment, 'Wiedervorlage speichern', key);
        } else {
            $('dialog-title').textContent = {journal: 'Einsatznotiz', handover: 'Schichtübergabe', close: 'Einsatz abschließen'}[kind];
            html = form(kind, kind === 'handover'
                ? '<p>Abgebende / übernehmende Person, offene Aufgaben, Risiken, Erreichbarkeit und nächste Schritte dokumentieren.</p>' + comment
                : comment, kind === 'close' ? 'Unwiderruflich abschließen' : 'Im Einsatzjournal speichern', '', kind === 'close');
        }
        $('dialog-body').innerHTML = html;
        if (!dialog.open) dialog.showModal();
        updateDialog();
        updateConnection();
    }
    function updateDialog() {
        if (!dialog.open || !dialogContext || !model?.event) return;
        const changed = dialogContext.id !== selected || dialogContext.revision !== Number(model.event.revision);
        $('dialog-warning').hidden = !changed;
        $('dialog-warning').textContent = !active() ? 'Einsatz wurde abgeschlossen. Keine weiteren Änderungen möglich.'
            : 'Neuere Änderungen liegen vor. Ihre Eingaben bleiben unverändert. Aktuellen Stand vor erneutem Speichern prüfen.';
        if (dialogContext.kind === 'node') {
            const node = model.event.snapshot.nodes.find(n => n.id === dialogContext.key);
            if (node) $('dialog-current').textContent = `Aktuell: ${labels[status(node)]} · ${model.ready[node.id] === 'waiting' ? labels.waiting + ' · ' : ''}Zuständig: ${owner(node) || 'offen'} · Priorität: ${priorities[assignment(node).priority || 'normal']} · Zielzeit: ${Number.isFinite(due(node)) ? new Date(due(node)).toLocaleString('de-DE') : 'keine'}\nVersion ${model.event.revision}`;
        } else {
            $('dialog-current').textContent = `Aktueller Stand · Version ${model.event.revision}\n${dialogContext.kind === 'leadership' ? Object.entries(model.event.coordination.leadership || {}).map(([role, leader]) => `${role}: ${leader.person} · ${leader.phone}${leader.until ? ` · Ablösung ${date(leader.until)}` : ''}`).join('\n') : dialogContext.kind === 'reminder' ? `${(model.event.coordination.reminders || []).filter(r => !r.done).length} offene Wiedervorlagen` : model.event.coordination.situation || 'Keine Lageübersicht'}\nLagebesprechung: ${date(model.event.coordination.briefing)}`;
        }
        $('rebase').hidden = !changed || !active();
    }
    async function send(body, onError) {
        body.set('_token', document.querySelector('meta[name="csrf-token"]').content);
        submitting = true;
        updateConnection();
        try {
            await json('/kaep-dashboard/aktion', {method: 'POST', body});
            return true;
        } catch (exception) {
            onError(`${exception.message} Eingaben bleiben erhalten. Bei unklarem Ergebnis zuerst das Journal prüfen, nicht blind erneut senden.`);
            return false;
        } finally {
            submitting = false;
            updateConnection();
            await load();
        }
    }
    dialog.addEventListener('submit', async event => {
        event.preventDefault();
        if (submitting || !dialogContext) return;
        if (!connected()) { $('result').textContent = 'Keine bestätigte Live-Verbindung. Eingaben bleiben erhalten; nichts gesendet.'; return; }
        if (dialogContext.id !== selected || dialogContext.revision !== Number(model.event.revision)) { $('result').textContent = 'Zwischenzeitliche Änderungen zuerst prüfen. Nichts gespeichert.'; updateDialog(); return; }
        const target = event.target;
        const action = target.dataset.action;
        if (['sms', 'close'].includes(action) && !confirm(action === 'sms' ? 'Diese SMS jetzt wirklich senden? Kein automatischer Wiederholungsversuch.' : 'Einsatz unwiderruflich abschließen? Danach sind keine Ergänzungen mehr möglich.')) return;
        const body = new FormData(target);
        target.querySelectorAll('input[type="datetime-local"]').forEach(input => body.set(input.name, toUtcInput(input.value)));
        body.set('action', action);
        body.set('node', target.dataset.node);
        body.set('id', String(dialogContext.id));
        body.set('revision', String(dialogContext.revision));
        $('result').textContent = 'Wird gespeichert …';
        if (await send(body, message => { $('result').textContent = message; })) dialog.close();
    });
    async function reminderAction(key, mode) {
        if (submitting || !model?.event || !active()) return;
        if (!connected()) { error('Keine bestätigte Live-Verbindung. Nichts gesendet.'); return; }
        const reminder = (model.event.coordination.reminders || []).find(r => r.key === key);
        if (!reminder || (mode === 'remove' && !confirm(`Wiedervorlage „${reminder.title}“ entfernen? Der Vorgang bleibt im Journal dokumentiert.`))) return;
        const body = new FormData();
        body.set('action', 'reminder');
        body.set('mode', mode);
        body.set('key', key);
        body.set('node', '');
        body.set('id', String(selected));
        body.set('revision', String(model.event.revision));
        await send(body, error);
    }
    $('rebase').addEventListener('click', () => {
        if (!active() || !dialogContext || submitting) return;
        if (!confirm('Den angezeigten aktuellen Stand geprüft? Ihre Eingaben werden beibehalten. Status, Entscheidung und Prüfpunkte werden auf den aktuellen Stand zurückgesetzt und müssen erneut geprüft werden.')) return;
        const node = model.event.snapshot.nodes.find(n => n.id === dialogContext.key);
        if (node && dialogContext.kind === 'node') {
            const state = model.event.state[node.id] || {status: 'open', answer: '', checks: []};
            const statusForm = dialog.querySelector('[data-action="status"]');
            if (statusForm) {
                statusForm.elements.status.value = state.status;
                if (statusForm.elements.answer) statusForm.elements.answer.value = state.answer || '';
                statusForm.querySelectorAll('[name="checks[]"]').forEach(input => { input.checked = (state.checks || []).includes(Number(input.value)); });
                if (state.status === 'done' || model.ready[node.id] !== 'ready') {
                    statusForm.dataset.readonly = 'true';
                }
            }
            if (model.event.sms[node.id] || state.status === 'done' || model.ready[node.id] !== 'ready') dialog.querySelector('[data-action="sms"]')?.remove();
        }
        dialogContext.revision = Number(model.event.revision);
        $('result').textContent = 'Aktuellen Stand übernommen. Eingaben prüfen und erneut speichern.';
        updateDialog();
        updateConnection();
    });
    function dialogDirty() {
        return Array.from(dialog.querySelectorAll('input, textarea, select')).some(input => {
            if (input.type === 'checkbox') return input.checked !== input.defaultChecked;
            if (input.tagName === 'SELECT') return input.selectedIndex !== Math.max(0, Array.from(input.options).findIndex(option => option.defaultSelected));
            return input.value !== input.defaultValue;
        });
    }
    function closeDialog() {
        if (submitting) return;
        if (dialogDirty() && !confirm('Dialog schließen und ungespeicherte Eingaben verwerfen?')) return;
        dialog.close();
    }
    $('dialog-close').addEventListener('click', closeDialog);
    dialog.addEventListener('cancel', event => { event.preventDefault(); closeDialog(); });
    root.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.event) {
            selected = Number(button.dataset.event); resetJournal(); void load();
        } else if (button.dataset.node && !button.closest('form')) {
            if (dialog.open && dialog.contains(button)) return;
            openDialog('node', button.dataset.node);
        } else if (button.dataset.reminderDone) void reminderAction(button.dataset.reminderDone, 'done');
        else if (button.dataset.reminderRemove) void reminderAction(button.dataset.reminderRemove, 'remove');
        else if (button.dataset.dialog) {
            if (dialog.open && dialog.contains(button) && dialogDirty() && !confirm('Ungespeicherte Eingaben in diesem Dialog verwerfen und Wiedervorlage anlegen?')) return;
            openDialog(button.dataset.dialog, button.dataset.reminderNode || '');
        } else if (button.dataset.leader) openDialog('leadership', button.dataset.leader);
        else if (button.dataset.showJournal) {
            const key = button.dataset.showJournal;
            closeDialog();
            if (dialog.open) return;
            $('journal-node').value = key;
            resetJournal(); void loadJournal(false);
            $('journal-title').scrollIntoView({block: 'start'});
        }
    });
    $('event-status').addEventListener('change', () => { selected = 0; page = 1; resetJournal(); void load(); });
    $('prev').addEventListener('click', () => { page--; selected = 0; void load(); });
    $('next').addEventListener('click', () => { page++; selected = 0; void load(); });
    $('search').addEventListener('input', renderBoard);
    $('filter').addEventListener('change', renderBoard);
    $('journal-search').addEventListener('input', renderJournal);
    $('journal-type').addEventListener('change', renderJournal);
    $('timezone').textContent = timezone;
    $('journal-node').addEventListener('change', () => { resetJournal(); void loadJournal(false); });
    $('more').addEventListener('click', () => void loadJournal(true));
    $('latest').addEventListener('click', () => { resetJournal(); void loadJournal(false); });
    function tv(enabled) {
        document.body.classList.toggle('kd-tv', enabled);
        $('tv').setAttribute('aria-pressed', String(enabled));
        $('tv').textContent = enabled ? 'TV-Ansicht verlassen' : 'TV-Ansicht';
        const url = new URL(location.href);
        if (enabled) url.searchParams.set('tv', '1'); else url.searchParams.delete('tv');
        history.replaceState(null, '', url);
        document.dispatchEvent(new Event('kaep:render'));
    }
    $('tv').addEventListener('click', () => tv(!document.body.classList.contains('kd-tv')));
    $('print').addEventListener('click', () => window.print());
    window.addEventListener('online', () => { connect(); void load(); });
    window.addEventListener('offline', () => { heartbeat = 0; updateConnection(); });
    window.addEventListener('pagehide', () => stream?.close());
    window.addEventListener('beforeunload', event => {
        if (dialog.open && (submitting || dialogDirty())) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pageshow', event => { if (event.persisted) { connect(); void load(); } });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { connect(); void load(); } });
    tv(params.get('tv') === '1');
    setInterval(tick, 1000);
    setInterval(() => { if ($('filter').value === 'critical') renderBoard(); }, 10000);
    void load();
    connect();
})();
