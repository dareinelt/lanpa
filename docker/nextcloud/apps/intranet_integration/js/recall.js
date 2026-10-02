/*
 * Intranet-Integration: Fortschritt der Rueckholung ausgelagerter Dateien
 * aus dem Cold-Tier (SMB-Tier) in den Hot-Tier (lokales Storage).
 *
 * Oeffnet ein Benutzer eine ausgelagerte Datei, wartet Nextcloud auf die
 * Rueckholung durch storage-sync. Diese Anzeige fragt den Fortschritt ab
 * (/apps/intranet_integration/api/recall) und zeigt je Datei einen
 * Fortschrittsbalken.
 *
 * Ist der Zugriff wegen eines Sicherheitsvorfalls eingeschraenkt (nur
 * lesen), erscheint oben ein dauerhafter, gut sichtbarer Hinweis.
 */
(function () {
    'use strict';

    try {
        if (window.top !== window.self) {
            return;
        }
    } catch (e) {
        return;
    }
    if (window.__intranetRecall) {
        return;
    }
    window.__intranetRecall = true;

    var FAST = 1000;
    var SLOW = 4000;
    var url = (window.OC && typeof window.OC.generateUrl === 'function')
        ? window.OC.generateUrl('/apps/intranet_integration/api/recall')
        : '/apps/intranet_integration/api/recall';
    var timer = null;
    var box = null;
    var list = null;
    var failures = 0;
    var banner = null;

    function showRestriction(message) {
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'intranet-restricted';
            banner.setAttribute('role', 'alert');
            var title = document.createElement('strong');
            title.className = 'intranet-restricted__title';
            title.textContent = 'Zugriff vorübergehend eingeschränkt';
            var text = document.createElement('span');
            text.className = 'intranet-restricted__text';
            banner.appendChild(title);
            banner.appendChild(text);
            document.body.appendChild(banner);
            document.body.classList.add('intranet-restricted-active');
        }
        banner.querySelector('.intranet-restricted__text').textContent = message;
    }

    function hideRestriction() {
        if (banner && banner.parentNode) {
            banner.parentNode.removeChild(banner);
        }
        banner = null;
        document.body.classList.remove('intranet-restricted-active');
    }

    function token() {
        if (window.OC && window.OC.requestToken) {
            return window.OC.requestToken;
        }
        var head = document.querySelector('head[data-requesttoken]');
        return head ? head.getAttribute('data-requesttoken') : '';
    }

    function formatBytes(bytes) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var value = Math.max(0, bytes || 0);
        var i = 0;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i++;
        }
        return value.toLocaleString('de-DE', { maximumFractionDigits: i === 0 ? 0 : 1 }) + ' ' + units[i];
    }

    function ensureBox() {
        if (box) {
            return;
        }
        box = document.createElement('section');
        box.className = 'intranet-recall';
        box.setAttribute('aria-live', 'polite');
        box.setAttribute('aria-label', 'Rückholung aus dem Cold-Tier');
        var title = document.createElement('h2');
        title.className = 'intranet-recall__title';
        title.textContent = 'Datei wird aus dem Cold-Tier (SMB-Tier) geladen';
        var hint = document.createElement('p');
        hint.className = 'intranet-recall__hint';
        hint.textContent = 'Selten genutzte Dateien liegen nur auf dem Netzwerkspeicher. Die Datei öffnet sich automatisch, sobald sie bereitsteht.';
        list = document.createElement('ul');
        list.className = 'intranet-recall__list';
        box.appendChild(title);
        box.appendChild(hint);
        box.appendChild(list);
        document.body.appendChild(box);
    }

    function removeBox() {
        if (box && box.parentNode) {
            box.parentNode.removeChild(box);
        }
        box = null;
        list = null;
    }

    function render(recalls) {
        if (!recalls.length) {
            removeBox();
            return;
        }
        ensureBox();
        list.textContent = '';
        recalls.forEach(function (item) {
            var li = document.createElement('li');
            li.className = 'intranet-recall__item intranet-recall__item--' + item.state;
            var name = document.createElement('span');
            name.className = 'intranet-recall__name';
            name.textContent = item.name;
            name.title = item.name;
            var bar = document.createElement('progress');
            bar.className = 'intranet-recall__bar';
            bar.max = 100;
            if (item.state === 'queued') {
                bar.removeAttribute('value');
            } else {
                bar.value = item.percent;
            }
            bar.setAttribute('aria-label', 'Fortschritt ' + item.name);
            var text = document.createElement('span');
            text.className = 'intranet-recall__text';
            if (item.state === 'done') {
                text.textContent = 'Bereit';
            } else if (item.state === 'failed') {
                text.textContent = 'Fehlgeschlagen' + (item.message ? ': ' + item.message : '');
            } else if (item.state === 'queued') {
                text.textContent = 'Wartet …' + (item.total ? ' (' + formatBytes(item.total) + ')' : '');
            } else {
                text.textContent = item.percent + ' % – ' + formatBytes(item.bytes) + ' von ' + formatBytes(item.total);
            }
            li.appendChild(name);
            li.appendChild(bar);
            li.appendChild(text);
            list.appendChild(li);
        });
    }

    function schedule(delay) {
        window.clearTimeout(timer);
        timer = window.setTimeout(poll, delay);
    }

    function poll() {
        if (document.hidden) {
            return;
        }
        fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', requesttoken: token() }
        })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data || !data.ok) {
                    failures++;
                    if (failures < 5) {
                        schedule(SLOW * failures);
                    }
                    return;
                }
                failures = 0;
                if (!data.enabled) {
                    removeBox();
                    hideRestriction();
                    return;
                }
                if (data.restricted) {
                    showRestriction(data.message || 'Der Zugriff auf Ihre Dateien wurde aus Sicherheitsgründen vorübergehend eingeschränkt. Bitte melden Sie sich beim Support.');
                } else {
                    hideRestriction();
                }
                var recalls = Array.isArray(data.recalls) ? data.recalls : [];
                render(recalls);
                var active = recalls.some(function (r) { return r.state === 'running' || r.state === 'queued'; });
                schedule(active ? FAST : SLOW);
            })
            .catch(function () {
                failures++;
                if (failures < 5) {
                    schedule(SLOW * failures);
                }
            });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            schedule(0);
        }
    });
    // Klick auf eine Datei: sofort nachsehen, ob eine Rueckholung beginnt.
    document.addEventListener('click', function () {
        schedule(700);
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { schedule(500); });
    } else {
        schedule(500);
    }
}());
