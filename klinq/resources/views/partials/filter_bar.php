<?php
/**
 * Standard-Filterleiste: Suche + Aktiv-Filter + optionale Zusatzfelder ($extraFilters HTML).
 * Erwartet: $filters, $basePath; optional $placeholder, $extraFilters
 */
?>
<form method="get" action="<?= e($basePath) ?>" class="filter-bar" role="search">
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="<?= e($placeholder ?? 'Suchen …') ?>">
    </div>
    <?= $extraFilters ?? '' ?>
    <div class="form-group">
        <label for="filter-active">Status</label>
        <select id="filter-active" name="active" data-autosubmit>
            <option value="1"<?= selected($filters['active'] ?? '1', '1') ?>>Aktiv</option>
            <option value="0"<?= selected($filters['active'] ?? '', '0') ?>>Inaktiv</option>
            <option value=""<?= ($filters['active'] ?? '1') === '' ? ' selected' : '' ?>>Alle</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
</form>
