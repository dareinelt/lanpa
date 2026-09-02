/* Liniendiagramm als SVG – ohne externe Bibliothek, dark-/lightmode-tauglich. */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var WIDTH = 720;
    var HEIGHT = 320;
    var PADDING = { top: 20, right: 16, bottom: 42, left: 48 };

    var container = document.querySelector('[data-chart]');
    if (!container) {
        return;
    }

    var canvas = container.querySelector('[data-chart-canvas]');
    var legend = container.querySelector('[data-chart-legend]');
    var endpoint = container.getAttribute('data-chart-endpoint');
    var days = container.getAttribute('data-chart-days') || '7';

    function element(name, attributes) {
        var node = document.createElementNS(SVG_NS, name);
        Object.keys(attributes || {}).forEach(function (key) {
            node.setAttribute(key, attributes[key]);
        });
        return node;
    }

    function seriesColor(index) {
        // Gleichmäßig verteilte Farbtöne, in beiden Modi ausreichend kontrastreich.
        var hue = (index * 47) % 360;
        return 'hsl(' + hue + ', 62%, 48%)';
    }

    function formatDate(iso) {
        var parts = String(iso).split('-');
        return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' : iso;
    }

    function render(report) {
        canvas.textContent = '';
        if (legend) {
            legend.textContent = '';
        }

        var labels = report.labels || [];
        var series = (report.series || []).filter(function (item) {
            return item.total > 0;
        });

        if (labels.length === 0 || series.length === 0) {
            var info = document.createElement('p');
            info.className = 'card__hint';
            info.textContent = 'Für den gewählten Zeitraum liegen keine Klicks vor.';
            canvas.appendChild(info);
            return;
        }

        var max = 0;
        series.forEach(function (item) {
            item.values.forEach(function (value) {
                max = Math.max(max, value);
            });
        });
        max = Math.max(max, 1);

        var innerWidth = WIDTH - PADDING.left - PADDING.right;
        var innerHeight = HEIGHT - PADDING.top - PADDING.bottom;
        var stepX = labels.length > 1 ? innerWidth / (labels.length - 1) : 0;

        var svg = element('svg', {
            viewBox: '0 0 ' + WIDTH + ' ' + HEIGHT,
            preserveAspectRatio: 'xMidYMid meet',
            role: 'img'
        });

        var title = element('title', {});
        title.textContent = 'Klicks je Navigationselement über ' + labels.length + ' Tage';
        svg.appendChild(title);

        // Y-Achse mit Gitternetz
        var ticks = 4;
        for (var t = 0; t <= ticks; t++) {
            var value = Math.round((max / ticks) * t);
            var y = PADDING.top + innerHeight - (innerHeight / ticks) * t;

            svg.appendChild(element('line', {
                x1: PADDING.left,
                y1: y,
                x2: WIDTH - PADDING.right,
                y2: y,
                stroke: 'currentColor',
                'stroke-opacity': t === 0 ? '0.45' : '0.15',
                'stroke-width': '1'
            }));

            var label = element('text', {
                x: PADDING.left - 8,
                y: y + 4,
                'text-anchor': 'end',
                'font-size': '11',
                fill: 'currentColor',
                'fill-opacity': '0.75'
            });
            label.textContent = String(value);
            svg.appendChild(label);
        }

        // X-Achsenbeschriftung (ausgedünnt)
        var every = Math.ceil(labels.length / 8);
        labels.forEach(function (labelValue, index) {
            if (index % every !== 0 && index !== labels.length - 1) {
                return;
            }

            var x = PADDING.left + stepX * index;
            var text = element('text', {
                x: x,
                y: HEIGHT - PADDING.bottom + 20,
                'text-anchor': 'middle',
                'font-size': '11',
                fill: 'currentColor',
                'fill-opacity': '0.75'
            });
            text.textContent = formatDate(labelValue);
            svg.appendChild(text);
        });

        series.forEach(function (item, index) {
            var color = seriesColor(index);
            var points = item.values.map(function (value, i) {
                var x = PADDING.left + stepX * i;
                var y = PADDING.top + innerHeight - (value / max) * innerHeight;
                return x.toFixed(2) + ',' + y.toFixed(2);
            });

            svg.appendChild(element('polyline', {
                points: points.join(' '),
                fill: 'none',
                stroke: color,
                'stroke-width': '2.5',
                'stroke-linejoin': 'round',
                'stroke-linecap': 'round'
            }));

            item.values.forEach(function (value, i) {
                var x = PADDING.left + stepX * i;
                var y = PADDING.top + innerHeight - (value / max) * innerHeight;
                var circle = element('circle', { cx: x, cy: y, r: '3', fill: color });
                var tooltip = element('title', {});
                tooltip.textContent = item.title + ': ' + value + ' Klicks am ' + formatDate(labels[i]);
                circle.appendChild(tooltip);
                svg.appendChild(circle);
            });

            if (legend) {
                var li = document.createElement('li');
                var swatch = document.createElement('span');
                swatch.className = 'chart__swatch';
                swatch.style.backgroundColor = color;
                li.appendChild(swatch);
                li.appendChild(document.createTextNode(item.title + ' (' + item.total + ')'));
                legend.appendChild(li);
            }
        });

        canvas.appendChild(svg);
    }

    fetch(endpoint + '?days=' + encodeURIComponent(days), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Anfrage fehlgeschlagen');
            }
            return response.json();
        })
        .then(render)
        .catch(function () {
            var info = document.createElement('p');
            info.className = 'card__hint';
            info.textContent = 'Das Diagramm konnte nicht geladen werden. Die Tabelle unten enthält alle Werte.';
            canvas.appendChild(info);
        });
})();
