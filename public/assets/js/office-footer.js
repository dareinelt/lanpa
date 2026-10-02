/*
 * Intranet-Fusszeile fuer Nextcloud / Euro-Office.
 *
 * Wird vom Loader der Nextcloud-App "intranet_integration" (gleicher Host)
 * nachgeladen und im Adminbereich fuer die Vorschau verwendet. Farben und
 * Transparenz werden per CSSOM gesetzt (CSP-konform ohne Inline-Styles).
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'intranetOfficeFooterCollapsed';

    function safePath(value) {
        return typeof value === 'string' && value.charAt(0) === '/' && value.charAt(1) !== '/';
    }

    function safeUrl(value) {
        if (safePath(value)) {
            return true;
        }
        return typeof value === 'string' && /^https?:\/\/[^\s\\]+$/i.test(value);
    }

    function hexToRgb(hex) {
        var match = /^#?([0-9a-f]{6})$/i.exec(String(hex || ''));
        if (!match) {
            return null;
        }
        var n = parseInt(match[1], 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    function readableText(rgb) {
        if (!rgb) {
            return '#ffffff';
        }
        var l = rgb.map(function (c) {
            c /= 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });
        var luminance = 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
        return luminance > 0.4 ? '#111418' : '#ffffff';
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    function isCollapsed() {
        try {
            return localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function storeCollapsed(value) {
        try {
            localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
        } catch (e) { /* ignorieren */ }
    }

    function applyStyle(root, config) {
        var colors = config.colors || {};
        var rgb = hexToRgb(colors.primary) || [31, 78, 121];
        var transparency = Math.max(0, Math.min(90, parseInt(config.transparency, 10) || 0));
        root.style.setProperty('--iof-bg', 'rgb(' + rgb.join(',') + ')');
        root.style.setProperty('--iof-fg', readableText(rgb));
        root.style.setProperty('--iof-accent', /^#[0-9a-f]{6}$/i.test(colors.accent || '') ? colors.accent : '#c8102e');
        root.style.setProperty('--iof-rest-opacity', String((100 - transparency) / 100));
    }

    // Ein Euro-Office-Dokument (Editor oder Viewer) ist geoeffnet.
    function documentOpen() {
        return !!document.querySelector('.eurooffice-iframe-container, #content.app-eurooffice, .eurooffice-inviewer');
    }

    function focusDocument() {
        var frame = document.querySelector('.eurooffice-iframe-container iframe, .eurooffice-inviewer iframe, #content.app-eurooffice #app iframe');
        if (frame) {
            try {
                frame.focus();
                if (frame.contentWindow) {
                    frame.contentWindow.focus();
                }
            } catch (e) { /* ignorieren */ }
        }
    }

    var openDialog = null;

    /*
     * Ja/Nein-Abfrage vor dem Verlassen eines Dokuments.
     * Ja: onYes wird ausgefuehrt. Nein/Escape: zurueck zum Dokument.
     */
    function confirmSaved(config, onYes) {
        if (openDialog) {
            return;
        }
        var overlay = el('div', 'iof-dialog');
        var box = el('div', 'iof-dialog__box');
        box.setAttribute('role', 'alertdialog');
        box.setAttribute('aria-modal', 'true');
        var titleId = 'iof-dialog-title-' + Math.random().toString(36).slice(2, 8);
        var textId = titleId + '-text';
        box.setAttribute('aria-labelledby', titleId);
        box.setAttribute('aria-describedby', textId);

        var title = el('h2', 'iof-dialog__title', 'Datei gespeichert?');
        title.id = titleId;
        var text = el('p', 'iof-dialog__text', 'Wurde die Datei gespeichert? Nicht gespeicherte Änderungen gehen beim Verlassen verloren.');
        text.id = textId;

        var buttons = el('div', 'iof-dialog__actions');
        var yes = el('button', 'iof-dialog__btn iof-dialog__btn--yes', 'Ja');
        yes.type = 'button';
        var no = el('button', 'iof-dialog__btn iof-dialog__btn--no', 'Nein');
        no.type = 'button';
        buttons.appendChild(yes);
        buttons.appendChild(no);

        box.appendChild(title);
        box.appendChild(text);
        box.appendChild(buttons);
        overlay.appendChild(box);
        applyStyle(overlay, config);

        function close() {
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            openDialog = null;
        }

        function onKey(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close();
                focusDocument();
            } else if (event.key === 'Tab') {
                event.preventDefault();
                (document.activeElement === yes ? no : yes).focus();
            }
        }

        yes.addEventListener('click', function () {
            close();
            onYes();
        });
        no.addEventListener('click', function () {
            close();
            focusDocument();
        });
        document.addEventListener('keydown', onKey, true);

        document.body.appendChild(overlay);
        openDialog = overlay;
        no.focus();
    }

    function build(config, host) {
        var embedded = host !== document.body;
        var root = el('div', 'iof' + (embedded ? ' iof--embedded' : ''));
        root.setAttribute('data-intranet-office-footer', '');

        var bar = el('nav', 'iof__bar');
        bar.setAttribute('aria-label', 'Intranet');
        bar.id = 'iof-bar-' + Math.random().toString(36).slice(2, 8);

        var brand = el('span', 'iof__brand');
        if (config.logo_url && safePath(config.logo_url)) {
            var logo = el('img', 'iof__logo');
            logo.src = config.logo_url;
            logo.alt = '';
            logo.setAttribute('aria-hidden', 'true');
            brand.appendChild(logo);
        }
        brand.appendChild(el('span', 'iof__text', config.text || 'Intranet'));
        bar.appendChild(brand);

        var actions = el('span', 'iof__actions');
        if (config.show_back) {
            var back = el('button', 'iof__link iof__link--back', 'Zurück');
            back.type = 'button';
            back.addEventListener('click', function () {
                if (config.preview) {
                    return;
                }
                var goBack = function () {
                    if (window.history.length > 1) {
                        window.history.back();
                    } else {
                        window.location.href = safeUrl(config.home_url) ? config.home_url : '/';
                    }
                };
                if (documentOpen()) {
                    confirmSaved(config, goBack);
                } else {
                    goBack();
                }
            });
            actions.appendChild(back);
        }

        var home = el('a', 'iof__link iof__link--home', config.home_label || 'Zum Intranet');
        home.href = safeUrl(config.home_url) ? config.home_url : '/';
        home.addEventListener('click', function (event) {
            if (config.preview) {
                event.preventDefault();
                return;
            }
            // Oeffnen in neuem Tab/Fenster verlaesst das Dokument nicht.
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || !documentOpen()) {
                return;
            }
            event.preventDefault();
            confirmSaved(config, function () {
                window.location.href = home.href;
            });
        });
        actions.appendChild(home);

        var collapse = el('button', 'iof__toggle');
        collapse.type = 'button';
        collapse.setAttribute('aria-controls', bar.id);
        actions.appendChild(collapse);
        bar.appendChild(actions);

        var expand = el('button', 'iof__expand', 'Intranet');
        expand.type = 'button';
        expand.setAttribute('aria-controls', bar.id);
        expand.setAttribute('aria-expanded', 'false');
        expand.setAttribute('aria-label', 'Intranet-Fußzeile einblenden');

        root.appendChild(bar);
        root.appendChild(expand);
        applyStyle(root, config);

        function setCollapsed(value, focus) {
            root.classList.toggle('iof--collapsed', value);
            collapse.setAttribute('aria-expanded', value ? 'false' : 'true');
            collapse.setAttribute('aria-label', 'Intranet-Fußzeile ausblenden');
            collapse.title = 'Ausblenden';
            bar.hidden = value;
            expand.hidden = !value;
            document.documentElement.classList.toggle('iof-reserve', !embedded && !value);
            if (focus) {
                (value ? expand : home).focus();
            }
        }

        collapse.addEventListener('click', function () {
            setCollapsed(true, true);
            if (!config.preview) {
                storeCollapsed(true);
            }
        });
        expand.addEventListener('click', function () {
            setCollapsed(false, true);
            if (!config.preview) {
                storeCollapsed(false);
            }
        });

        // Aktivierung: Maus (hover, per CSS), Tastatur (focus-within, per CSS)
        // und Touch: erstes Antippen macht die Fusszeile nur deckend, loest
        // aber noch keine Aktion aus.
        var lastPointer = '';
        function activate() {
            root.classList.add('iof--active');
        }
        function deactivate() {
            root.classList.remove('iof--active');
        }
        root.addEventListener('pointerdown', function (event) {
            lastPointer = event.pointerType || '';
        }, true);
        root.addEventListener('click', function (event) {
            if (lastPointer === 'touch' && !root.classList.contains('iof--active')) {
                event.preventDefault();
                event.stopPropagation();
                activate();
            }
            lastPointer = '';
        }, true);
        document.addEventListener('pointerdown', function (event) {
            if (!root.contains(event.target)) {
                deactivate();
            }
        }, true);
        root.addEventListener('focusout', function (event) {
            if (!event.relatedTarget || !root.contains(event.relatedTarget)) {
                deactivate();
            }
        });
        root.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                deactivate();
                if (document.activeElement && root.contains(document.activeElement)) {
                    document.activeElement.blur();
                }
            }
        });

        setCollapsed(!config.preview && isCollapsed(), false);
        host.appendChild(root);

        return root;
    }

    var instances = [];

    function render(config, host) {
        if (!config || !host) {
            return null;
        }
        var root = build(config, host);
        instances.push({ host: host, root: root });
        return root;
    }

    function update(config, host) {
        for (var i = instances.length - 1; i >= 0; i--) {
            if (instances[i].host === host) {
                instances[i].root.remove();
                instances.splice(i, 1);
            }
        }
        return render(config, host);
    }

    window.IntranetOfficeFooter = { render: render, update: update };

    var config = window.IntranetOfficeFooterConfig;
    if (config && config.enabled && !config.preview && !document.querySelector('[data-intranet-office-footer]')) {
        render(config, document.body);
    }
}());
