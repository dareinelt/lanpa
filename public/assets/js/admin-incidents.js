/*
 * Adminbereich Vorfaelle: Bestaetigungs-Overlay "Event wirklich als erledigt
 * markieren?" (Fokus im Dialog halten, Escape = Nein).
 */
(function () {
    'use strict';

    var overlay = document.querySelector('[data-incident-overlay]');
    if (!overlay) {
        return;
    }

    var cancel = overlay.querySelector('[data-incident-overlay-cancel]');
    document.body.classList.add('has-nav-tree-overlay');

    var focusable = function () {
        return Array.prototype.filter.call(
            overlay.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"])'),
            function (node) { return node.offsetParent !== null; }
        );
    };

    var initial = overlay.querySelector('[data-incident-overlay-focus]');
    if (initial) {
        initial.focus();
    }

    overlay.addEventListener('click', function (event) {
        if (event.target === overlay && cancel) {
            cancel.click();
        }
    });

    overlay.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && cancel) {
            event.preventDefault();
            cancel.click();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        var nodes = focusable();
        if (nodes.length === 0) {
            return;
        }
        var first = nodes[0];
        var last = nodes[nodes.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
}());
