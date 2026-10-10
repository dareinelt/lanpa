/**
 * Verlaufsgrafiken im Overlay "Reverse-Proxy": beim Ueberfahren zeigt eine
 * senkrechte Linie den angenavigierten Zeitpunkt, daneben stehen Zeitpunkt und
 * Werte der Linien. Die Werte liefert die Grafik als JSON in data-chart
 * (siehe App\Services\Auth\AuthMetricsCharts); es wird nichts nachgerechnet,
 * angezeigt werden die gezeichneten Punkte.
 *
 * Gezeichnet wird im Koordinatensystem der Grafik (viewBox), damit die Anzeige
 * mit der Grafik mitskaliert und keine style-Attribute noetig sind
 * (CSP: style-src ohne 'unsafe-inline').
 */
(function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var FONT = 11;
    var LINE_HEIGHT = 15;
    var PADDING = 8;
    /** Platz vor einem Wert fuer das Farbzeichen der Linie. */
    var SWATCH = 14;

    /** Zahl wie in der Oberflaeche: Komma als Dezimaltrennzeichen. */
    function format(value, unit) {
        var rounded = Math.round(value * 10) / 10;
        var decimals = unit === 'percent' || rounded !== Math.round(rounded) ? 1 : 0;

        return rounded.toFixed(decimals).replace('.', ',') + (unit === 'percent' ? ' %' : '');
    }

    /** Zeitpunkt eines Abschnitts als "TT.MM. HH:MM". */
    function stamp(milliseconds) {
        var date = new Date(milliseconds);
        if (isNaN(date.getTime())) {
            return '';
        }

        var pad = function (value) {
            return (value < 10 ? '0' : '') + value;
        };

        return pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '. '
            + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function element(name, attributes) {
        var node = document.createElementNS(NS, name);
        Object.keys(attributes).forEach(function (key) {
            node.setAttribute(key, String(attributes[key]));
        });

        return node;
    }

    function clear(node) {
        while (node.firstChild !== null) {
            node.removeChild(node.firstChild);
        }
    }

    function chartData(svg) {
        var raw = svg.getAttribute('data-chart');
        if (raw === null) {
            return null;
        }

        var parsed;
        try {
            parsed = JSON.parse(raw);
        } catch (error) {
            return null;
        }

        if (parsed === null || !Array.isArray(parsed.series) || parsed.series.length === 0) {
            return null;
        }

        return parsed;
    }

    /**
     * Hinweisfeld am angenavigierten Zeitpunkt; an den Raendern weicht es auf
     * die andere Seite aus, damit es in der Grafik bleibt.
     */
    function tooltip(tip, time, lines, at, chart) {
        clear(tip);

        var entries = [{ text: time, color: null }];
        if (lines.length === 0) {
            entries.push({ text: 'Keine Messwerte', color: null });
        }
        lines.forEach(function (line) {
            entries.push({ text: line.text, color: line.color });
        });

        var texts = [];
        var width = 0;
        entries.forEach(function (entry, index) {
            var indent = entry.color === null ? 0 : SWATCH;
            var text = element('text', {
                x: 0,
                y: 0,
                'font-size': FONT,
                class: index === 0 ? 'auth-chart__tip-time' : 'auth-chart__tip-value'
            });
            text.textContent = entry.text;
            tip.appendChild(text);

            width = Math.max(width, text.getBBox().width + indent);
            texts.push({ node: text, entry: entry, indent: indent });
        });

        var boxWidth = width + PADDING * 2;
        var boxHeight = PADDING * 2 + FONT + (entries.length - 1) * LINE_HEIGHT;
        var x = at + 12;
        if (x + boxWidth > chart.width - chart.padRight) {
            x = at - 12 - boxWidth;
        }
        x = Math.max(chart.padLeft, x);
        var y = chart.padTop;

        // Der Hintergrund liegt unter den Texten, wird aber erst nach dem
        // Messen der Breite eingefuegt.
        tip.insertBefore(
            element('rect', { x: x, y: y, width: boxWidth, height: boxHeight, rx: 4, class: 'auth-chart__tip-box' }),
            tip.firstChild
        );

        texts.forEach(function (item, index) {
            var lineY = y + PADDING + FONT + index * LINE_HEIGHT;
            item.node.setAttribute('x', x + PADDING + item.indent);
            item.node.setAttribute('y', lineY);

            if (item.entry.color === null) {
                return;
            }

            tip.appendChild(element('line', {
                x1: x + PADDING,
                x2: x + PADDING + 8,
                y1: lineY - 3.5,
                y2: lineY - 3.5,
                stroke: item.entry.color,
                'stroke-width': 2.5,
                'stroke-linecap': 'round'
            }));
        });

        tip.setAttribute('visibility', 'visible');
    }

    function setup(svg) {
        var chart = chartData(svg);
        if (chart === null || chart.buckets < 2 || !(chart.axis > 0)) {
            return;
        }

        var left = chart.padLeft;
        var step = (chart.width - chart.padRight - left) / (chart.buckets - 1);
        var plotH = chart.height - chart.padTop - chart.padBottom;

        var cursor = element('line', {
            class: 'auth-chart__cursor',
            x1: left,
            x2: left,
            y1: chart.padTop,
            y2: chart.padTop + plotH,
            visibility: 'hidden'
        });
        var points = element('g', { class: 'auth-chart__points' });
        var tip = element('g', { class: 'auth-chart__tip', visibility: 'hidden' });
        svg.appendChild(cursor);
        svg.appendChild(points);
        svg.appendChild(tip);

        function hide() {
            cursor.setAttribute('visibility', 'hidden');
            tip.setAttribute('visibility', 'hidden');
            clear(points);
        }

        function show(event) {
            var box = svg.getBoundingClientRect();
            if (box.width === 0) {
                return;
            }

            var index = Math.round(((event.clientX - box.left) / box.width * chart.width - left) / step);
            index = Math.max(0, Math.min(chart.buckets - 1, index));
            var at = left + step * index;

            cursor.setAttribute('x1', at);
            cursor.setAttribute('x2', at);
            cursor.setAttribute('visibility', 'visible');

            clear(points);
            var lines = [];
            chart.series.forEach(function (line) {
                var value = line.values[index];
                if (value === null || typeof value === 'undefined') {
                    return;
                }

                points.appendChild(element('circle', {
                    cx: at,
                    cy: chart.padTop + plotH - plotH * Math.min(chart.axis, value) / chart.axis,
                    r: 2.5,
                    fill: line.color,
                    class: 'auth-chart__point'
                }));
                lines.push({ color: line.color, text: line.label + ': ' + format(value, chart.unit) });
            });

            tooltip(tip, stamp(chart.start + index * chart.minutes * 60000), lines, at, chart);
        }

        svg.addEventListener('pointermove', show);
        svg.addEventListener('pointerdown', show);
        svg.addEventListener('pointerleave', hide);
    }

    Array.prototype.forEach.call(
        document.querySelectorAll('#reverse-proxy-dialog svg.auth-chart[data-chart]'),
        setup
    );
})();
