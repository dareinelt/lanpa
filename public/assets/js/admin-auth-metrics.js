/**
 * Overlays der Kennzahl-Kacheln auf dem Dashboard ("Reverse-Proxy" und die
 * Container-Kacheln): die Kachel selbst oeffnet per Klick (oder mit
 * Enter/Leertaste) die Details mit den Verlaufsgrafiken. Das Ziel steht im
 * Attribut data-auth-metrics-dialog der Kachel (ohne Angabe das Overlay des
 * Reverse-Proxys). Klicks auf die einklappbaren Abschnitte der Kachel klappen
 * nur diese auf. Das native dialog-Element uebernimmt Fokus, Escape und die
 * Hintergrundebene.
 */
(function () {
    'use strict';

    var cards = document.querySelectorAll('[data-auth-metrics-card]');

    /** Klicks und Tasten auf bedienbaren Teilen der Kachel bleiben bei diesen. */
    function ownControl(target) {
        return typeof target.closest === 'function'
            && target.closest('summary, a, input, select, textarea, label, button') !== null;
    }

    function setup(card) {
        var dialog = document.getElementById(card.getAttribute('data-auth-metrics-dialog') || 'reverse-proxy-dialog');
        if (dialog === null || typeof dialog.showModal !== 'function') {
            return;
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
    }

    Array.prototype.forEach.call(cards, setup);
})();
