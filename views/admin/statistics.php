<?php

declare(strict_types=1);

use App\Support\Html;

/** @var int $days */
/** @var list<int> $periods */
/** @var array{days:int,labels:list<string>,series:list<array{id:int,title:string,values:list<int>,total:int}>,total:int} $report */
?>
<nav class="period-nav" aria-label="Zeitraum wählen">
    <?php foreach ($periods as $period) { ?>
        <a class="period-nav__link<?= $period === $days ? ' is-active' : '' ?>"
           href="/admin/statistik?days=<?= (int) $period ?>"
           <?= $period === $days ? 'aria-current="page"' : '' ?>><?= (int) $period ?> Tage</a>
    <?php } ?>
</nav>

<section class="card">
    <h2 class="card__title">Klicks je Tag</h2>
    <p class="card__hint">Gesamt im Zeitraum: <?= (int) $report['total'] ?> Klicks</p>

    <div class="chart" data-chart data-chart-endpoint="/admin/api/statistik" data-chart-days="<?= (int) $days ?>">
        <div class="chart__canvas" data-chart-canvas role="img"
             aria-label="Liniendiagramm der Klicks je Navigationselement über <?= (int) $days ?> Tage"></div>
        <ul class="chart__legend" data-chart-legend></ul>
    </div>

    <noscript>
        <p class="card__hint">Ohne JavaScript wird nur die Tabelle angezeigt.</p>
    </noscript>
</section>

<section class="card">
    <h2 class="card__title">Tabellarische Auswertung</h2>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Klicks je Navigationselement und Tag</caption>
            <thead>
            <tr>
                <th scope="col">Datum</th>
                <?php foreach ($report['series'] as $series) { ?>
                    <th scope="col"><?= Html::e($series['title']) ?></th>
                <?php } ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($report['labels'] as $index => $label) { ?>
                <tr>
                    <th scope="row"><?= Html::e(date('d.m.Y', (int) strtotime($label))) ?></th>
                    <?php foreach ($report['series'] as $series) { ?>
                        <td><?= (int) ($series['values'][$index] ?? 0) ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
            </tbody>
            <tfoot>
            <tr>
                <th scope="row">Summe</th>
                <?php foreach ($report['series'] as $series) { ?>
                    <td><strong><?= (int) $series['total'] ?></strong></td>
                <?php } ?>
            </tr>
            </tfoot>
        </table>
    </div>
</section>
