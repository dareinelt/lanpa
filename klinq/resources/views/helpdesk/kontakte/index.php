<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
use App\Support\FuzzySearch;

$basePath = '/helpdesk/kontakte';
$query = array_filter(['q' => $filters['q'], 'tag' => $filters['tag']], static fn (string $v): bool => $v !== '');
$isFiltered = $query !== [];
$tel = static fn (string $number): string => 'tel:' . preg_replace('/[^0-9+]/', '', $number);
$tagUrl = static fn (string $tag): string => query_url($basePath, $query, ['tag' => $tag, 'page' => null]);
?>
<div class="page-header">
    <div>
        <h1>Kontaktverzeichnis</h1>
        <p class="page-subtitle text-muted mb-0">Gemeinsames Adressbuch aller Agenten · <?= (int) $paginator->total ?> <?= $paginator->total === 1 ? 'Kontakt' : 'Kontakte' ?><?= $isFiltered ? ' gefunden' : '' ?></p>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
        <a class="btn btn-primary" href="/helpdesk/kontakte/neu"><?= icon('plus') ?> Neuer Kontakt</a>
    </div>
    <?php endif; ?>
</div>

<form method="get" action="<?= e($basePath) ?>" class="filter-bar hd-contacts-filter" role="search">
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Firma, Name, Rufnummer, E-Mail, Stichwort …" autofocus>
    </div>
    <div class="form-group">
        <label for="filter-tag">Stichwort</label>
        <select id="filter-tag" name="tag" data-autosubmit>
            <option value="">Alle Stichworte</option>
            <?php foreach ($allTags as $t): ?>
                <option value="<?= e($t['tag']) ?>"<?= mb_strtolower($filters['tag']) === mb_strtolower($t['tag']) ? ' selected' : '' ?>><?= e($t['tag']) ?> (<?= (int) $t['count'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('search') ?> Suchen</button>
    <?php if ($isFiltered): ?><a class="btn btn-ghost" href="<?= e($basePath) ?>"><?= icon('x') ?> Zurücksetzen</a><?php endif; ?>
</form>

<?php if ($similarCount > 0): ?>
    <p class="hd-contacts-hint text-muted text-sm"><?= icon('sparkle', 'icon icon-xs') ?> <?= (int) $similarCount ?> <?= $similarCount === 1 ? 'ähnlicher Treffer' : 'ähnliche Treffer' ?> zu „<?= e($filters['q']) ?>“ (z. B. abweichende Schreibweise oder Leerzeichen) – markiert mit <span class="badge badge-info">ähnlich</span>.</p>
<?php endif; ?>

<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">
            <?= $isFiltered ? 'Keine passenden Kontakte gefunden.' : 'Noch keine Kontakte hinterlegt.' ?>
            <?php if ($canEdit): ?><br><a href="/helpdesk/kontakte/neu">Ersten Kontakt anlegen</a><?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table hd-contacts-table">
        <thead><tr><th>Firma</th><th>Name</th><th>Rufnummern</th><th>E-Mail</th><th>Stichworte</th><th></th></tr></thead>
        <tbody>
        <?php $prevCompany = null; foreach ($rows as $r):
            $companyKey = mb_strtolower((string) $r['company']);
            $sameCompany = $companyKey === $prevCompany;
            $prevCompany = $companyKey;
        ?>
            <tr class="is-clickable<?= $sameCompany ? ' is-same-company' : '' ?>" data-href="/helpdesk/kontakte/<?= (int) $r['id'] ?>">
                <td class="hd-contacts-company"><strong><?= e($r['company']) ?></strong><?php if ($r['match'] === FuzzySearch::SIMILAR): ?> <span class="badge badge-info" title="Ähnlicher Treffer">ähnlich</span><?php endif; ?></td>
                <td class="hd-contacts-name"><?= e(trim($r['last_name'] . ', ' . $r['first_name'], ', ')) ?><?php if (!empty($r['notes'])): ?><div class="text-muted text-sm hd-contacts-notes"><?= e(mb_strimwidth((string) $r['notes'], 0, 90, '…')) ?></div><?php endif; ?></td>
                <td class="hd-contacts-phones">
                    <?php if (!empty($r['phone'])): ?><div><a href="<?= e($tel((string) $r['phone'])) ?>"><?= e($r['phone']) ?></a></div><?php endif; ?>
                    <?php if (!empty($r['mobile'])): ?><div><span class="text-muted text-sm">Mobil</span> <a href="<?= e($tel((string) $r['mobile'])) ?>"><?= e($r['mobile']) ?></a></div><?php endif; ?>
                    <?php if (empty($r['phone']) && empty($r['mobile'])): ?><span class="text-muted">–</span><?php endif; ?>
                </td>
                <td><?php if (!empty($r['email'])): ?><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php else: ?><span class="text-muted">–</span><?php endif; ?></td>
                <td><?php if ($r['tags'] !== []): ?><div class="tag-list"><?php foreach ($r['tags'] as $tag): ?><a class="tag-chip" href="<?= e($tagUrl((string) $tag)) ?>"><?= e($tag) ?></a><?php endforeach; ?></div><?php endif; ?></td>
                <td class="table-actions"><?php if ($canEdit): ?><a class="btn btn-ghost btn-sm" href="/helpdesk/kontakte/<?= (int) $r['id'] ?>" title="Bearbeiten" aria-label="Bearbeiten"><?= icon('pen') ?></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
