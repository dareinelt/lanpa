<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $entries */
/** @var string $type */
/** @var int $page */
/** @var int $pages */
/** @var int $total */

$filters = ['' => 'Alle', 'user' => 'Benutzer', 'group' => 'AD-Gruppen', 'default' => 'Standard'];
$link = static function (string $art, int $seite): string {
    $query = array_filter(['art' => $art, 'seite' => $seite > 1 ? (string) $seite : ''], static fn (string $v): bool => $v !== '');

    return '/admin/speicherplatz/verlauf' . ($query === [] ? '' : '?' . http_build_query($query));
};

require __DIR__ . '/partials.php';
?>
<p><a href="/admin/speicherplatz">← Zurück zur Übersicht</a></p>

<section class="card" aria-labelledby="history-title">
    <h2 class="card__title" id="history-title">Verlauf der Kontingentänderungen (<?= (int) $total ?>)</h2>
    <p class="card__hint">Wer wem wann wie viel Speicherplatz gegeben oder entzogen hat – mit Begründung.</p>
    <nav class="toolbar" aria-label="Verlauf filtern">
        <?php foreach ($filters as $key => $label) { ?>
            <a class="button <?= $type === $key ? 'button--primary' : 'button--ghost' ?>" href="<?= $link($key, 1) ?>"<?= $type === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a>
        <?php } ?>
    </nav>
    <?php if ($entries === []) { ?>
        <p class="empty-state">Keine Einträge.</p>
    <?php } else {
        $historyTable($entries);
    } ?>
    <?php if ($pages > 1) { ?>
        <nav class="period-nav" aria-label="Seiten">
            <?php if ($page > 1) { ?>
                <a class="button button--ghost" href="<?= $link($type, $page - 1) ?>">← Neuere</a>
            <?php } ?>
            <span>Seite <?= (int) $page ?> von <?= (int) $pages ?></span>
            <?php if ($page < $pages) { ?>
                <a class="button button--ghost" href="<?= $link($type, $page + 1) ?>">Ältere →</a>
            <?php } ?>
        </nav>
    <?php } ?>
</section>
