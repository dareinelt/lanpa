<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $total = array_sum(array_map(static fn (array $g): int => count($g['items']), $groups)); ?>
<div class="page-header">
    <div><h1>Suche</h1><p class="page-subtitle text-muted mb-0"><?= $term !== '' ? $total . ' Treffer für „' . e($term) . '“' : 'Bitte einen Suchbegriff eingeben.' ?></p></div>
</div>
<form method="get" action="/search" class="filter-bar mb-4" role="search">
    <div class="form-group filter-wide">
        <label for="search-q">Suchbegriff</label>
        <input id="search-q" type="search" name="q" value="<?= e($term) ?>" placeholder="Inventarnr., Seriennr., MAC, IMEI, Mitarbeiter, Standort, Kostenstelle, Hersteller, Artikel, Lieferant …" autofocus>
    </div>
    <button type="submit" class="btn btn-primary"><?= icon('search') ?> Suchen</button>
</form>
<?php if ($term !== '' && $total === 0): ?>
    <div class="card"><p class="text-muted mb-0">Keine Treffer. Tipp: Bei Inventarnummern reicht die genaue Nummer (z. B. <span class="mono">PC24001</span>), Seriennummern werden ohne Leerzeichen und Bindestriche verglichen.</p></div>
<?php endif; ?>
<?php foreach ($groups as $g): if (!$g['items']) { continue; } ?>
<div class="card card-flush mb-4">
    <div class="card-header"><h2><?= e($g['label']) ?> <span class="text-muted text-sm">(<?= count($g['items']) ?>)</span></h2><a class="btn btn-link btn-sm" href="<?= e($g['url']) ?>">In Liste öffnen</a></div>
    <table class="table table-compact">
        <tbody>
        <?php foreach ($g['items'] as $item): ?>
            <tr class="is-clickable" data-href="<?= e($item['url']) ?>">
                <td><a href="<?= e($item['url']) ?>"><strong><?= e($item['title']) ?></strong></a></td>
                <td class="text-muted text-sm"><?= e($item['meta']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
