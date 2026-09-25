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
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = safeUrl(config.home_url) ? config.home_url : '/';
                }
            });
            actions.appendChild(back);
        }

        var home = el('a', 'iof__link iof__link--home', config.home_label || 'Zum Intranet');
        home.href = safeUrl(config.home_url) ? config.home_url : '/';
        if (config.preview) {
            home.addEventListener('click', function (event) { event.preventDefault(); });
        }
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
