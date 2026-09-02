/* Landingpage: Detailbeschreibungen aufklappen und Klicks erfassen. */
(function () {
    'use strict';

    function initToggles() {
        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('.tile__toggle');
            if (!toggle) {
                return;
            }

            var tile = toggle.closest('.tile');
            if (!tile) {
                return;
            }

            var isOpen = tile.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            var label = toggle.querySelector('.tile__toggle-label');
            if (label) {
                label.textContent = isOpen ? 'Details ausblenden' : 'Details';
            }
        });
    }

    function trackClick(navigationId) {
        var url = '/api/klick';

        if (navigator.sendBeacon) {
            var data = new FormData();
            data.append('navigation_id', navigationId);
            if (navigator.sendBeacon(url, data)) {
                return;
            }
        }

        // Fallback: Fire-and-forget, blockiert die Navigation nicht.
        try {
            fetch(url, {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ navigation_id: navigationId })
            }).catch(function () { /* Statistik darf nie stören. */ });
        } catch (error) {
            /* ignorieren */
        }
    }

    function initTracking() {
        document.addEventListener('click', function (event) {
            var link = event.target.closest('[data-nav-id]');
            if (!link) {
                return;
            }

            var id = parseInt(link.getAttribute('data-nav-id'), 10);
            if (!isNaN(id) && id > 0) {
                trackClick(id);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initToggles();
        initTracking();
    });
})();
