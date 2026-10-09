<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Support\Html;

/**
 * Wolkendarstellung des Nachrichtenfluss-Dashboards (Postfaecher je
 * Identitaetsquelle, verbundene Exchange-Clients, fleissigste KI-Nutzer).
 *
 * Die Wolke ist HTML-Text und kein Bild: Text bleibt auf Retina-Displays und
 * beim Zoomen scharf, ist vorlesbar und durchsuchbar, und die Seite verbietet
 * Inline-Stile (CSP). Groesse und Staffelung laufen deshalb ausschliesslich
 * ueber CSS-Klassen. Die Stufe (`l1` klein ... `l5` gross) ergibt sich aus dem
 * Wert im Verhaeltnis zum groessten Wert der Liste; die Staffelung
 * (`--up`/`--down`) wechselt mit der Position, damit die Woerter nicht in einer
 * Zeile kleben.
 *
 * Jede Wolke liefert ihren Inhalt zusaetzlich als Wertetabelle, damit die
 * Groesse nie die einzige Quelle einer Zahl ist.
 */
final class OrvantaFlowCloud
{
    /** Anzahl der Groessenstufen (CSS-Klassen `cloud__word--l1` bis `--l5`). */
    public const LEVELS = 5;

    /**
     * Untergrenze des Wertanteils je Stufe. Die Liste ist absteigend sortiert,
     * die erste passende Grenze gewinnt; alles darunter bleibt Stufe 1. Der
     * groesste Wert hebt sich dadurch sichtbar ab, mittlere Werte bleiben
     * unterscheidbar.
     *
     * @var array<int,float>
     */
    private const THRESHOLDS = [
        5 => 0.9,
        4 => 0.7,
        3 => 0.5,
        2 => 0.3,
    ];

    /**
     * Bereitet die Eintraege fuer die Darstellung auf: Wert, Groessenstufe und
     * Staffelung. Eintraege ohne Beschriftung werden verworfen, negative Werte
     * auf 0 begrenzt.
     *
     * @param list<array{label?:mixed,value?:mixed,title?:mixed,url?:mixed}> $entries
     *
     * @return list<array{label:string,value:int,level:int,direction:string,title:string,url:string}>
     */
    public function items(array $entries): array
    {
        $normalised = [];
        foreach ($entries as $entry) {
            $label = trim((string) ($entry['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $normalised[] = [
                'label' => $label,
                'value' => max(0, (int) ($entry['value'] ?? 0)),
                'title' => trim((string) ($entry['title'] ?? '')),
                'url' => trim((string) ($entry['url'] ?? '')),
            ];
        }

        if ($normalised === []) {
            return [];
        }

        $max = 0;
        foreach ($normalised as $entry) {
            $max = max($max, $entry['value']);
        }

        $items = [];
        foreach ($normalised as $index => $entry) {
            $items[] = [
                'label' => $entry['label'],
                'value' => $entry['value'],
                'level' => self::level($entry['value'], $max),
                'direction' => $index % 2 === 0 ? 'up' : 'down',
                'title' => $entry['title'] !== '' ? $entry['title'] : $entry['label'] . ': ' . $entry['value'],
                'url' => $entry['url'],
            ];
        }

        return $items;
    }

    /**
     * Groessenstufe eines Werts im Verhaeltnis zum Hoechstwert. Ohne Werte
     * (Hoechstwert 0) bleibt es bei der kleinsten Stufe.
     */
    public static function level(int $value, int $max): int
    {
        if ($max <= 0) {
            return 1;
        }

        $ratio = $value / $max;
        foreach (self::THRESHOLDS as $level => $threshold) {
            if ($ratio >= $threshold) {
                return $level;
            }
        }

        return 1;
    }

    /**
     * Wolkendarstellung als HTML-Liste. Der Text ist maskiert, es werden keine
     * Inline-Stile gesetzt.
     *
     * @param list<array{label?:mixed,value?:mixed,title?:mixed,url?:mixed}> $entries
     * @param string $emptyText Hinweis, wenn die Liste leer ist
     */
    public function render(array $entries, string $emptyText = 'Keine Werte vorhanden.'): string
    {
        $items = $this->items($entries);
        if ($items === []) {
            return '<p class="cloud cloud--empty">' . Html::e($emptyText) . '</p>';
        }

        $html = '<ul class="cloud">';
        foreach ($items as $item) {
            $class = 'cloud__word cloud__word--l' . $item['level'] . ' cloud__word--' . $item['direction'];
            $inner = '<span class="cloud__value">' . Html::e((string) $item['value']) . '</span>'
                . '<span class="cloud__label">' . Html::e($item['label']) . '</span>';

            if ($item['url'] !== '') {
                $inner = '<a class="cloud__link" href="' . Html::url($item['url']) . '">' . $inner . '</a>';
            }

            $html .= '<li class="' . $class . '" title="' . Html::e($item['title']) . '">' . $inner . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * Dieselben Werte als aufklappbare Tabelle. Die Wolke ist damit nie die
     * einzige Quelle einer Zahl.
     *
     * @param list<array{label?:mixed,value?:mixed,title?:mixed,url?:mixed}> $entries
     * @param string $summary Beschriftung des Aufklapppunkts
     * @param string $headLeft Spaltenkopf der Beschriftungsspalte
     */
    public function table(
        array $entries,
        string $summary = 'Werte als Tabelle',
        string $headLeft = 'Element'
    ): string {
        $items = $this->items($entries);
        if ($items === []) {
            return '';
        }

        $html = '<details class="cloud-values"><summary>' . Html::e($summary) . '</summary>'
            . '<table class="table"><thead><tr>'
            . '<th scope="col">' . Html::e($headLeft) . '</th>'
            . '<th scope="col">Anzahl</th>'
            . '</tr></thead><tbody>';

        foreach ($items as $item) {
            $label = $item['url'] !== ''
                ? '<a href="' . Html::url($item['url']) . '">' . Html::e($item['label']) . '</a>'
                : Html::e($item['label']);

            $html .= '<tr><th scope="row">' . $label . '</th><td>' . Html::e((string) $item['value']) . '</td></tr>';
        }

        return $html . '</tbody></table></details>';
    }
}
