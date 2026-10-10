/**
 * Overlay der Karte "Reverse-Proxy" auf dem Dashboard: die Karte selbst oeffnet
 * per Klick (oder mit Enter/Leertaste) die Details mit den Verlaufsgrafiken.
 * Klicks auf die einklappbaren Abschnitte der Karte klappen nur diese auf.
 * Das native dialog-Element uebernimmt Fokus, Escape und die Hintergrundebene.
 */
(function () {
    'use strict';

    var card = document.querySelector('[data-auth-metrics-card]');
    var dialog = document.getElementById('reverse-proxy-dialog');
    if (card === null || dialog === null || typeof dialog.showModal !== 'function') {
        return;
    }

    /** Klicks und Tasten auf bedienbaren Teilen der Karte bleiben bei diesen. */
    function ownControl(target) {
        return typeof target.closest === 'function'
            && target.closest('summary, a, input, select, textarea, label, button') !== null;
    }

    function open() {
        if (!dialog.open) {
            dialog.showModal();
        }
    }

    function close() {
        if (dialog.open) {
            dialog.close();
        }
    }

    card.addEventListener('click', function (event) {
        if (ownControl(event.target)) {
            return;
        }

        open();
    });

    card.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        if (ownControl(event.target)) {
            return;
        }

        event.preventDefault();
        open();
    });

    var closer = dialog.querySelector('[data-auth-metrics-close]');
    if (closer !== null) {
        closer.addEventListener('click', close);
    }

    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            close();
        }
    });
})();
