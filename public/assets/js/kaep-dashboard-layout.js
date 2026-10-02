(() => {
    'use strict';

    function ordered(layout) {
        return {
            order: [...layout.order.filter(id => layout.pinned.includes(id)), ...layout.order.filter(id => !layout.pinned.includes(id))],
            pinned: [...layout.pinned],
        };
    }
    function normalize(saved, ids) {
        if (!saved || saved.version !== 1 || !Array.isArray(saved.order) || !Array.isArray(saved.pinned)
            || ![...saved.order, ...saved.pinned].every(id => typeof id === 'string')
            || new Set(saved.order).size !== saved.order.length || new Set(saved.pinned).size !== saved.pinned.length
            || saved.pinned.some(id => !saved.order.includes(id))) {
            throw new Error('Ungültige gespeicherte Anordnung.');
        }
        return ordered({
            order: [...saved.order.filter(id => ids.includes(id)), ...ids.filter(id => !saved.order.includes(id))],
            pinned: saved.pinned.filter(id => ids.includes(id)),
        });
    }
    function place(layout, id, target, after) {
        if (id === target || !layout.order.includes(id) || !layout.order.includes(target)
            || layout.pinned.includes(id) !== layout.pinned.includes(target)) return ordered(layout);
        const order = layout.order.filter(item => item !== id);
        order.splice(order.indexOf(target) + (after ? 1 : 0), 0, id);
        return ordered({order, pinned: layout.pinned});
    }
    function move(layout, id, direction, visible = layout.order) {
        const group = layout.order.filter(item => visible.includes(item) && layout.pinned.includes(item) === layout.pinned.includes(id));
        const index = group.indexOf(id);
        const target = index < 0 ? null : group[index + direction];
        return target ? place(layout, id, target, direction > 0) : ordered(layout);
    }
    function pin(layout, id) {
        if (!layout.order.includes(id)) return ordered(layout);
        const wasPinned = layout.pinned.includes(id);
        return ordered({
            order: [id, ...layout.order.filter(item => item !== id)],
            pinned: wasPinned ? layout.pinned.filter(item => item !== id) : [...layout.pinned, id],
        });
    }

    if (typeof module !== 'undefined' && module.exports) module.exports = {normalize, place, move, pin};
    if (typeof document === 'undefined') return;
    const root = document.getElementById('kaep');
    const container = document.getElementById('kd-content');
    if (!root || !container) return;
    const panels = new Map(Array.from(container.querySelectorAll(':scope > [data-kd-panel]')).map(panel => [panel.dataset.kdPanel, panel]));
    const ids = [...panels.keys()];
    const key = 'lanpa.kaep.dashboard.layout.v1';
    const status = document.getElementById('kd-layout-status');
    const arrangeButton = document.getElementById('kd-arrange');
    const resetButton = document.getElementById('kd-layout-reset');
    let layout = {order: [...ids], pinned: []};
    let storageError = '';
    let drag = null, frame = 0;
    const escape = value => String(value).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const label = id => panels.get(id).dataset.kdLabel;
    const visible = () => layout.order.filter(id => panels.get(id).getClientRects().length > 0);
    function announce(message) {
        status.textContent = message + (storageError ? ` ${storageError}` : '');
        status.classList.toggle('kd-warning', !!storageError);
    }
    try {
        const saved = sessionStorage.getItem(key);
        if (saved) layout = normalize(JSON.parse(saved), ids);
    } catch (exception) {
        storageError = exception instanceof SyntaxError || exception.message === 'Ungültige gespeicherte Anordnung.'
            ? 'Gespeicherte Anordnung ist beschädigt. Standardanordnung geladen.'
            : 'Lokaler Speicher nicht verfügbar. Die Anordnung bleibt nur bis zum Neuladen erhalten.';
        announce('');
    }
    const pinIcon = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M8 3h8l-1 7 4 4v2H5v-2l4-4-1-7ZM12 16v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
    for (const [id, panel] of panels) {
        const tools = document.createElement(panel.tagName === 'DETAILS' ? 'span' : 'div');
        tools.className = 'kd-panel-tools';
        tools.innerHTML = `<span class="kd-panel-label">${escape(label(id))}</span><span class="kd-pin-badge">Oben angeheftet</span><span class="kd-panel-actions">
            <button type="button" class="button kd-drag-handle" data-kd-action="drag" aria-label="${escape(label(id))} ziehen oder mit Pfeiltasten verschieben" title="Ziehen (auch per Touch) oder Pfeiltasten verwenden">⠿</button>
            <button type="button" class="button" data-kd-action="up" aria-label="${escape(label(id))} nach oben verschieben" title="Nach oben">↑</button>
            <button type="button" class="button" data-kd-action="down" aria-label="${escape(label(id))} nach unten verschieben" title="Nach unten">↓</button>
            <button type="button" class="button" data-kd-action="pin" aria-label="${escape(label(id))} oben anheften" aria-pressed="false" title="Vor allen nicht angehefteten Abschnitten fixieren">${pinIcon}</button>
        </span>`;
        if (panel.tagName === 'DETAILS') panel.querySelector('summary').append(tools);
        else panel.prepend(tools);
    }
    function controls() {
        const shown = visible();
        for (const [id, panel] of panels) {
            const pinned = layout.pinned.includes(id);
            panel.classList.toggle('kd-pinned', pinned);
            const group = shown.filter(item => layout.pinned.includes(item) === pinned);
            const index = group.indexOf(id);
            panel.querySelector('[data-kd-action="up"]').disabled = index <= 0;
            panel.querySelector('[data-kd-action="down"]').disabled = index < 0 || index >= group.length - 1;
            const pinButton = panel.querySelector('[data-kd-action="pin"]');
            pinButton.setAttribute('aria-pressed', String(pinned));
            pinButton.setAttribute('aria-label', `${label(id)} ${pinned ? 'lösen' : 'oben anheften'}`);
            pinButton.title = pinned ? 'Fixierung lösen' : 'Vor allen nicht angehefteten Abschnitten fixieren';
        }
    }
    function apply(save = false, message = '') {
        const focus = document.activeElement;
        for (const id of layout.order) container.append(panels.get(id));
        controls();
        if (focus instanceof HTMLElement && container.contains(focus)) {
            const nextFocus = focus.matches(':disabled') ? focus.closest('[data-kd-panel]').querySelector('[data-kd-action="drag"]') : focus;
            nextFocus.focus({preventScroll: true});
        }
        if (save) {
            try {
                sessionStorage.setItem(key, JSON.stringify({version: 1, ...layout}));
                storageError = '';
            } catch {
                storageError = 'Lokaler Speicher nicht verfügbar. Die Anordnung bleibt nur bis zum Neuladen erhalten.';
            }
            announce(message);
        }
    }
    function shift(id, direction) {
        layout = move(layout, id, direction, visible());
        apply(true, `${label(id)} ${direction < 0 ? 'nach oben' : 'nach unten'} verschoben. Nur in diesem Tab.`);
    }
    arrangeButton.addEventListener('click', () => {
        const enabled = !root.classList.contains('kd-arranging');
        root.classList.toggle('kd-arranging', enabled);
        arrangeButton.setAttribute('aria-pressed', String(enabled));
        arrangeButton.textContent = enabled ? 'Anordnung fertig' : 'Anordnung bearbeiten';
        resetButton.hidden = !enabled;
        document.getElementById('kd-layout-help').hidden = !enabled;
        controls();
    });
    resetButton.addEventListener('click', () => {
        if (!confirm('Anordnung und angeheftete Abschnitte nur in diesem Tab zurücksetzen?')) return;
        layout = {order: [...ids], pinned: []};
        apply(true, 'Standardanordnung wiederhergestellt. Nur in diesem Tab.');
    });
    container.addEventListener('click', event => {
        const button = event.target.closest('[data-kd-action]');
        if (!button) return;
        event.preventDefault();
        const id = button.closest('[data-kd-panel]').dataset.kdPanel;
        if (button.dataset.kdAction === 'up') shift(id, -1);
        if (button.dataset.kdAction === 'down') shift(id, 1);
        if (button.dataset.kdAction === 'pin') {
            layout = pin(layout, id);
            apply(true, `${label(id)} ${layout.pinned.includes(id) ? 'oben angeheftet' : 'gelöst'}. Nur in diesem Tab.`);
            if (layout.pinned.includes(id)) panels.get(id).scrollIntoView({block: 'start'});
        }
    });
    container.addEventListener('keydown', event => {
        if (event.key === 'Escape' && drag) { event.preventDefault(); finishDrag(false); return; }
        if (!event.target.matches('[data-kd-action="drag"]') || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        shift(event.target.closest('[data-kd-panel]').dataset.kdPanel, event.key === 'ArrowUp' ? -1 : 1);
    });
    function clearMarkers() {
        for (const panel of panels.values()) panel.removeAttribute('data-kd-drop');
    }
    function dragFrame() {
        if (!drag?.active) return;
        const speed = drag.y < 80 ? -14 : drag.y > innerHeight - 80 ? 14 : 0;
        if (speed) window.scrollBy(0, speed);
        const candidates = visible().filter(id => id !== drag.id && layout.pinned.includes(id) === layout.pinned.includes(drag.id));
        clearMarkers();
        const target = candidates.find(id => panels.get(id).getBoundingClientRect().bottom >= drag.y) || candidates.at(-1);
        if (target) {
            const rect = panels.get(target).getBoundingClientRect();
            drag.target = target;
            drag.after = drag.y > rect.top + rect.height / 2;
            panels.get(target).dataset.kdDrop = drag.after ? 'after' : 'before';
        }
        frame = requestAnimationFrame(dragFrame);
    }
    container.addEventListener('pointerdown', event => {
        if (event.button !== 0 || !event.isPrimary || !event.target.closest('[data-kd-action="drag"]') || drag) return;
        const button = event.target.closest('[data-kd-action="drag"]');
        drag = {id: button.closest('[data-kd-panel]').dataset.kdPanel, pointer: event.pointerId, x: event.clientX, y: event.clientY, startX: event.clientX, startY: event.clientY, active: false};
        button.focus({preventScroll: true});
        container.setPointerCapture(event.pointerId);
    });
    container.addEventListener('pointermove', event => {
        if (!drag || drag.pointer !== event.pointerId) return;
        drag.x = event.clientX;
        drag.y = event.clientY;
        if (!drag.active && Math.hypot(drag.x - drag.startX, drag.y - drag.startY) > 8) {
            drag.active = true;
            panels.get(drag.id).classList.add('kd-being-dragged');
            root.classList.add('kd-dragging');
            announce(`${label(drag.id)} wird verschoben. Zum Abbrechen Escape drücken.`);
            dragFrame();
        }
    });
    function finishDrag(commit) {
        if (!drag) return;
        const current = drag;
        drag = null;
        cancelAnimationFrame(frame);
        clearMarkers();
        panels.get(current.id).classList.remove('kd-being-dragged');
        root.classList.remove('kd-dragging');
        if (container.hasPointerCapture(current.pointer)) container.releasePointerCapture(current.pointer);
        if (commit && current.active && current.target) {
            layout = place(layout, current.id, current.target, current.after);
            apply(true, `${label(current.id)} verschoben. Nur in diesem Tab.`);
        } else if (current.active) {
            announce('Verschieben abgebrochen. Anordnung unverändert.');
        }
    }
    container.addEventListener('pointerup', event => { if (drag?.pointer === event.pointerId) finishDrag(true); });
    container.addEventListener('pointercancel', () => finishDrag(false));
    container.addEventListener('lostpointercapture', () => finishDrag(false));
    window.addEventListener('blur', () => finishDrag(false));
    window.addEventListener('resize', controls);
    document.addEventListener('kaep:render', controls);
    apply();
})();
