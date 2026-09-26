<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Tls\CertificateInspector;
use App\Services\Tls\TlsCertificateService;
use App\Support\Html;

/** @var array<string,mixed> $state */
/** @var list<array<string,mixed>> $rows */
/** @var array<string,string> $keyTypes */
/** @var array<string,string> $csrValues */
/** @var array<string,string> $csrErrors */
/** @var string $networkInput */
/** @var array<string,string> $networkErrors */
/** @var int $highlightId */
/** @var string|null $newCsr */
/** @var array<string,mixed>|null $preview */

$time = static fn (?int $timestamp): string => $timestamp === null ? '–' : date('d.m.Y H:i', $timestamp);
$day = static fn (?int $timestamp): string => $timestamp === null ? '–' : date('d.m.Y', $timestamp);

/** @return array{0:string,1:string} [Badge-Klasse, Text] */
$statusBadge = static function (string $status, ?int $daysLeft): array {
    return match ($status) {
        CertificateInspector::STATUS_VALID => ['badge--ok', 'gültig'],
        CertificateInspector::STATUS_EXPIRING => ['badge--warn', 'läuft in ' . max(0, (int) $daysLeft) . ' Tagen ab'],
        CertificateInspector::STATUS_EXPIRED => ['badge--error', 'abgelaufen'],
        CertificateInspector::STATUS_NOT_YET_VALID => ['badge--warn', 'noch nicht gültig'],
        default => ['badge--muted', 'kein Zertifikat'],
    };
};

$field = static function (string $name, array $errors): string {
    return isset($errors[$name]) ? 'aria-invalid="true" aria-describedby="' . Html::e($name) . '-error"' : '';
};
$error = static function (string $name, array $errors): string {
    return isset($errors[$name]) ? '<p class="field__error" id="' . Html::e($name) . '-error">' . Html::e($errors[$name]) . '</p>' : '';
};

$strict = $state['mode'] === TlsCertificateService::MODE_STRICT;
$active = $state['active'];
$networks = $state['networks'];
$hasRequests = array_filter($rows, static fn (array $row): bool => $row['kind'] === TlsCertificateService::KIND_CSR) !== [];
$hasOpenRequest = array_filter($rows, static fn (array $row): bool => $row['kind'] === TlsCertificateService::KIND_CSR && !$row['has_certificate']) !== [];
$newRow = null;
foreach ($rows as $row) {
    if ($row['id'] === $highlightId && $row['kind'] === TlsCertificateService::KIND_CSR && !$row['has_certificate']) {
        $newRow = $row;
    }
}
?>
<section class="card cert-state cert-state--<?= $strict ? 'ok' : 'warn' ?>" aria-labelledby="cert-state-title">
    <div class="cert-state__head">
        <h2 class="card__title" id="cert-state-title">Aktueller Zustand</h2>
        <span class="badge <?= $strict ? 'badge--ok' : 'badge--warn' ?>"><?= $strict ? 'HTTPS erzwungen' : 'Notfallmodus' ?></span>
    </div>
    <?php if ($strict) { ?>
        <p>
            Aktiv ist das Zertifikat für <strong><?= Html::e((string) $active['common_name']) ?></strong>
            <?php if ((string) $active['issuer_cn'] !== '') { ?>(ausgestellt von <?= Html::e((string) $active['issuer_cn']) ?>)<?php } ?>,
            gültig bis <strong><?= Html::e($day((int) $active['not_after'])) ?></strong>
            (noch <?= (int) $active['days_left'] ?> Tage). Jeder Aufruf über HTTP wird auf HTTPS umgeleitet.
        </p>
    <?php } else { ?>
        <p>
            <?php if ($active !== null) { ?>
                Das aktive Zertifikat für <strong><?= Html::e((string) $active['common_name']) ?></strong> ist
                <strong><?= $state['active_status'] === CertificateInspector::STATUS_EXPIRED ? 'abgelaufen' : 'nicht gültig' ?></strong>.
            <?php } else { ?>
                Es ist kein gültiges Zertifikat aktiv.
            <?php } ?>
            HTTPS verwendet ein selbstsigniertes Notfall-Zertifikat (Browser zeigen eine Warnung).
            <?php if ($networks === []) { ?>
                Reines HTTP ist für kein Netz erlaubt – alle Aufrufe werden auf HTTPS umgeleitet.
            <?php } else { ?>
                Reines HTTP ist nur aus <?= implode(', ', array_map(static fn (string $net): string => '<code>' . Html::e($net) . '</code>', $networks)) ?>
                erlaubt; alle anderen Aufrufe werden auf HTTPS umgeleitet.
            <?php } ?>
        </p>
    <?php } ?>
    <p class="card__hint">
        <?php if ($state['last_sync'] === null) { ?>
            Der auth-Container hat die Zertifikatskonfiguration noch nicht abgerufen.
        <?php } elseif ($state['sync_stale']) { ?>
            <strong>Achtung:</strong> Der auth-Container hat die Konfiguration zuletzt am <?= Html::e($time((int) $state['last_sync'])) ?> abgerufen.
            Läuft er? Änderungen werden erst nach dem nächsten Abruf wirksam.
        <?php } else { ?>
            Der auth-Container ruft die Konfiguration jede Minute ab (zuletzt <?= Html::e($time((int) $state['last_sync'])) ?>).
            Änderungen auf dieser Seite sind damit spätestens nach einer Minute wirksam.
        <?php } ?>
    </p>

    <ol class="cert-steps" aria-label="Ablauf">
        <li class="cert-steps__item<?= $hasRequests ? ' is-done' : '' ?>"><a href="#csr"><span class="cert-steps__no">1</span> Request (CSR) erstellen</a></li>
        <li class="cert-steps__item<?= $hasRequests && !$hasOpenRequest ? ' is-done' : '' ?>"><span class="cert-steps__no">2</span> CSR herunterladen und von der CA signieren lassen</li>
        <li class="cert-steps__item<?= $strict ? ' is-done' : '' ?>"><a href="#import"><span class="cert-steps__no">3</span> Zertifikat importieren und aktivieren</a></li>
    </ol>
</section>

<?php if ($newRow !== null && $newCsr !== null) { ?>
    <section class="card cert-new" id="neuer-request" aria-labelledby="cert-new-title">
        <h2 class="card__title" id="cert-new-title">Neuer Request für <?= Html::e((string) $newRow['common_name']) ?></h2>
        <p class="card__hint">Übergeben Sie diesen CSR Ihrer Zertifizierungsstelle (z. B. Active Directory Certificate Services,
            Vorlage „Webserver“). Das ausgestellte Zertifikat importieren Sie anschließend unten unter „Zertifikat importieren“.</p>
        <label class="visually-hidden" for="cert-new-pem">CSR (PEM)</label>
        <textarea id="cert-new-pem" class="cert-pem" readonly rows="8" data-cert-copy-source><?= Html::e($newCsr) ?></textarea>
        <div class="form__actions">
            <a class="button button--primary" href="/admin/zertifikate/csr?id=<?= (int) $newRow['id'] ?>">CSR herunterladen</a>
            <button type="button" class="button button--ghost" data-cert-copy="cert-new-pem" hidden>In die Zwischenablage kopieren</button>
        </div>
    </section>
<?php } ?>

<div class="cert-grid">
    <section class="card" id="csr" aria-labelledby="csr-title">
        <h2 class="card__title" id="csr-title"><span class="cert-steps__no" aria-hidden="true">1</span> Request (CSR) erstellen</h2>
        <p class="card__hint">Der private Schlüssel wird hier erzeugt und verschlüsselt gespeichert; er verlässt die Anwendung nur in Richtung auth-Container.</p>

        <form method="post" action="/admin/zertifikate/csr" class="cert-form">
            <?= Csrf::field() ?>

            <div class="field">
                <label for="common_name">Hostname (Common Name) <span aria-hidden="true">*</span></label>
                <input type="text" id="common_name" name="common_name" required maxlength="253"
                       value="<?= Html::e($csrValues['common_name']) ?>" placeholder="intranet.firma.local" <?= $field('common_name', $csrErrors) ?>>
                <?= $error('common_name', $csrErrors) ?>
            </div>

            <div class="field">
                <label for="san">Weitere Namen (SAN)</label>
                <textarea id="san" name="san" rows="3" placeholder="intranet&#10;10.0.0.5" <?= $field('san', $csrErrors) ?>><?= Html::e($csrValues['san']) ?></textarea>
                <p class="field__hint">Ein Hostname oder eine IP-Adresse je Zeile, unter denen das Intranet aufgerufen wird. Der Common Name wird automatisch aufgenommen.</p>
                <?= $error('san', $csrErrors) ?>
            </div>

            <div class="field">
                <label for="key_type">Schlüssel</label>
                <select id="key_type" name="key_type" <?= $field('key_type', $csrErrors) ?>>
                    <?php foreach ($keyTypes as $value => $label) { ?>
                        <option value="<?= Html::e($value) ?>" <?= $csrValues['key_type'] === $value ? 'selected' : '' ?>><?= Html::e($label) ?></option>
                    <?php } ?>
                </select>
                <?= $error('key_type', $csrErrors) ?>
            </div>

            <?php $subjectOpen = array_intersect_key($csrErrors, ['country' => 1, 'email' => 1]) !== []; ?>
            <details class="cert-details"<?= $subjectOpen ? ' open' : '' ?>>
                <summary>Angaben zur Organisation (optional)</summary>
                <div class="field-row">
                    <div class="field">
                        <label for="organization">Organisation (O)</label>
                        <input type="text" id="organization" name="organization" maxlength="64" value="<?= Html::e($csrValues['organization']) ?>">
                    </div>
                    <div class="field">
                        <label for="organizational_unit">Abteilung (OU)</label>
                        <input type="text" id="organizational_unit" name="organizational_unit" maxlength="64" value="<?= Html::e($csrValues['organizational_unit']) ?>">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="locality">Ort (L)</label>
                        <input type="text" id="locality" name="locality" maxlength="64" value="<?= Html::e($csrValues['locality']) ?>">
                    </div>
                    <div class="field">
                        <label for="state">Bundesland (ST)</label>
                        <input type="text" id="state" name="state" maxlength="64" value="<?= Html::e($csrValues['state']) ?>">
                    </div>
                    <div class="field">
                        <label for="country">Land (C)</label>
                        <input type="text" id="country" name="country" maxlength="2" value="<?= Html::e($csrValues['country']) ?>" placeholder="DE" <?= $field('country', $csrErrors) ?>>
                        <?= $error('country', $csrErrors) ?>
                    </div>
                </div>
                <div class="field">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="text" id="email" name="email" maxlength="64" value="<?= Html::e($csrValues['email']) ?>" <?= $field('email', $csrErrors) ?>>
                    <?= $error('email', $csrErrors) ?>
                </div>
            </details>

            <div class="form__actions">
                <button type="submit" class="button button--primary">CSR erstellen</button>
            </div>
        </form>
    </section>

    <section class="card" id="import" aria-labelledby="import-title">
        <h2 class="card__title" id="import-title"><span class="cert-steps__no" aria-hidden="true">3</span> Zertifikat importieren</h2>
        <p class="card__hint">Das von der CA ausgestellte Zertifikat wird automatisch dem passenden Request zugeordnet.
            Vor der Übernahme werden alle Details zur Prüfung angezeigt.</p>

        <?php if (!$hasOpenRequest && !$hasRequests) { ?>
            <p class="cert-empty">Erstellen Sie zuerst einen Request – nur dazu ausgestellte Zertifikate können importiert werden.</p>
        <?php } ?>

        <form method="post" action="/admin/zertifikate/import/pruefen" enctype="multipart/form-data" class="cert-form">
            <?= Csrf::field() ?>

            <div class="field">
                <label for="certificate_file">Zertifikatsdatei</label>
                <input type="file" id="certificate_file" name="certificate_file" accept=".pem,.crt,.cer,application/x-pem-file,application/x-x509-ca-cert,application/pkix-cert">
                <p class="field__hint">Formate: PEM oder CRT (Base64 oder DER). Enthält die Datei Zwischenzertifikate, werden sie als Kette übernommen.</p>
            </div>

            <div class="field">
                <label for="certificate_text">oder Inhalt einfügen</label>
                <textarea id="certificate_text" name="certificate_text" rows="5" class="cert-pem" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
            </div>

            <div class="form__actions">
                <button type="submit" class="button button--primary" <?= $hasRequests ? '' : 'disabled' ?>>Prüfen und Vorschau anzeigen</button>
            </div>
        </form>
    </section>
</div>

<section class="card" aria-labelledby="cert-table-title">
    <h2 class="card__title" id="cert-table-title">Requests und Zertifikate</h2>
    <p class="card__hint">Alle bisher erstellten Requests mit dem jeweils importierten Zertifikat. Das aktive Zertifikat wählen Sie mit
        „Aktivieren“; es kann immer nur genau ein Zertifikat aktiv sein. Notfall-Zertifikate erscheinen, sobald sie verwendet wurden.</p>

    <?php if ($rows === []) { ?>
        <p class="cert-empty">Noch keine Requests vorhanden.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table cert-table">
                <caption class="visually-hidden">Requests und Zertifikate</caption>
                <thead>
                <tr>
                    <th scope="col">Erstellt</th>
                    <th scope="col">Request</th>
                    <th scope="col">Zertifikat</th>
                    <th scope="col">Status</th>
                    <th scope="col">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) {
                    $isFallback = $row['kind'] === TlsCertificateService::KIND_FALLBACK;
                    [$badgeClass, $badgeText] = $statusBadge((string) $row['status'], $row['days_left']);
                    if ($isFallback && $badgeClass === 'badge--ok') {
                        [$badgeClass, $badgeText] = ['badge--warn', 'selbstsigniert'];
                    }
                    $classes = trim(($row['active'] ? 'is-active ' : '') . ($row['id'] === $highlightId ? 'is-new ' : '') . ($isFallback ? 'is-fallback' : '')); ?>
                    <tr id="request-<?= (int) $row['id'] ?>"<?= $classes !== '' ? ' class="' . Html::e($classes) . '"' : '' ?>>
                        <td>
                            <time datetime="<?= Html::e(date('c', (int) $row['created_at'])) ?>"><?= Html::e($time((int) $row['created_at'])) ?></time>
                            <div class="table__hint"><?= Html::e((string) $row['created_by']) ?></div>
                        </td>
                        <td>
                            <?php if ($isFallback) { ?>
                                <strong>Notfall-Zertifikat</strong>
                                <div class="table__hint">selbstsigniert, automatisch erzeugt</div>
                            <?php } else { ?>
                                <strong><?= Html::e((string) $row['common_name']) ?></strong>
                            <?php } ?>
                            <?php $otherNames = array_values(array_diff($row['san'], [$row['common_name']])); ?>
                            <?php if ($otherNames !== []) { ?>
                                <div class="table__hint">auch: <?= Html::e(implode(', ', $otherNames)) ?></div>
                            <?php } ?>
                            <div class="table__hint"><?= Html::e((string) $row['key_type']) ?> · #<?= (int) $row['id'] ?></div>
                        </td>
                        <td>
                            <?php if (!$row['has_certificate']) { ?>
                                <span class="table__hint">noch nicht hochgeladen</span>
                            <?php } else { ?>
                                <?php if ($isFallback) { ?>
                                    verwendet <?= Html::e($time($row['first_used_at'])) ?> – <?= Html::e($time($row['last_used_at'])) ?>
                                <?php } else { ?>
                                    hochgeladen <?= Html::e($time($row['cert_uploaded_at'])) ?>
                                    <?php if ((string) $row['cert_issuer_cn'] !== '') { ?>
                                        <div class="table__hint">Aussteller: <?= Html::e((string) $row['cert_issuer_cn']) ?></div>
                                    <?php } ?>
                                <?php } ?>
                                <div class="table__hint">gültig <?= Html::e($day($row['cert_not_before'])) ?> – <?= Html::e($day($row['cert_not_after'])) ?></div>
                                <details class="cert-details cert-details--inline">
                                    <summary>Details</summary>
                                    <dl class="cert-dl">
                                        <dt>Inhaber</dt><dd><?= Html::e((string) $row['cert_subject']) ?></dd>
                                        <dt>Aussteller</dt><dd><?= Html::e((string) $row['cert_issuer']) ?></dd>
                                        <dt>Namen</dt><dd><?= Html::e(implode(', ', $row['cert_san'])) ?: '–' ?></dd>
                                        <dt>Seriennummer</dt><dd><code><?= Html::e((string) $row['cert_serial']) ?></code></dd>
                                        <dt>SHA-256</dt><dd><code class="cert-fingerprint"><?= Html::e((string) $row['cert_fingerprint']) ?></code></dd>
                                        <?php if (!$isFallback) { ?>
                                            <dt>Kette</dt><dd><?= $row['chain_count'] === 0 ? 'ohne Zwischenzertifikat' : ((int) $row['chain_count'] . ($row['chain_count'] === 1 ? ' CA-Zertifikat' : ' CA-Zertifikate')) ?></dd>
                                            <dt>Importiert von</dt><dd><?= Html::e((string) $row['cert_uploaded_by']) ?></dd>
                                        <?php } ?>
                                    </dl>
                                </details>
                            <?php } ?>
                        </td>
                        <td>
                            <div class="cert-badges">
                                <?php if ($row['active']) { ?>
                                    <span class="badge badge--active">● Aktiv</span>
                                <?php } ?>
                                <span class="badge <?= Html::e($badgeClass) ?>"><?= Html::e($badgeText) ?></span>
                                <?php if ($row['in_use']) { ?>
                                    <span class="badge badge--muted" title="Zuletzt vom auth-Container abgerufen: <?= Html::e($time($row['last_used_at'])) ?>">im Einsatz</span>
                                <?php } ?>
                            </div>
                            <?php if ($row['active'] && $row['activated_at'] !== null) { ?>
                                <div class="table__hint">aktiviert <?= Html::e($time($row['activated_at'])) ?></div>
                            <?php } ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <?php if ($row['active']) { ?>
                                    <form method="post" action="/admin/zertifikate/deaktivieren" class="inline-form"
                                          data-confirm="Aktives Zertifikat deaktivieren? Danach wird das selbstsignierte Notfall-Zertifikat verwendet und HTTP ist nur noch aus den hinterlegten Quellnetzen erlaubt.">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="button button--ghost">Deaktivieren</button>
                                    </form>
                                <?php } elseif ($row['can_activate']) { ?>
                                    <form method="post" action="/admin/zertifikate/aktivieren" class="inline-form"
                                          data-confirm="Zertifikat für <?= Html::e((string) $row['common_name']) ?> (gültig bis <?= Html::e($day($row['cert_not_after'])) ?>) als aktives Zertifikat verwenden?<?= $active !== null ? ' Das bisher aktive Zertifikat wird dabei abgelöst.' : '' ?> Anschließend wird jeder HTTP-Aufruf auf HTTPS umgeleitet.">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="button button--primary"
                                                aria-label="Zertifikat für <?= Html::e((string) $row['common_name']) ?> aktivieren">Aktivieren</button>
                                    </form>
                                <?php } ?>
                                <?php if ($row['has_csr']) { ?>
                                    <a class="button button--ghost" href="/admin/zertifikate/csr?id=<?= (int) $row['id'] ?>">CSR herunterladen</a>
                                <?php } ?>
                                <?php if ($row['can_delete']) { ?>
                                    <form method="post" action="/admin/zertifikate/loeschen" class="inline-form"
                                          data-confirm="Request für <?= Html::e((string) $row['common_name']) ?> löschen? Ein später dazu ausgestelltes Zertifikat kann dann nicht mehr importiert werden.">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="button button--danger">Löschen</button>
                                    </form>
                                <?php } ?>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>

<section class="card" id="http-zugriff" aria-labelledby="http-title">
    <h2 class="card__title" id="http-title">HTTP-Zugriff ohne gültiges Zertifikat</h2>
    <p class="card__hint">
        Solange kein gültiges Zertifikat aktiv ist, dürfen Clients aus diesen Quellnetzen das Intranet über reines HTTP aufrufen.
        Alle anderen werden auf HTTPS (Notfall-Zertifikat) umgeleitet. Mit gültigem aktivem Zertifikat wird immer auf HTTPS umgeleitet.
        <?php if ($strict) { ?><strong>Derzeit ohne Wirkung, da ein gültiges Zertifikat aktiv ist.</strong><?php } ?>
    </p>

    <form method="post" action="/admin/zertifikate/http-netze" class="cert-form cert-form--narrow">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="tls_http_networks">Quellnetze (CIDR)</label>
            <textarea id="tls_http_networks" name="tls_http_networks" rows="3" placeholder="<?= Html::e(TlsCertificateService::DEFAULT_HTTP_NETWORKS) ?>" <?= $field('tls_http_networks', $networkErrors) ?>><?= Html::e($networkInput) ?></textarea>
            <p class="field__hint">Ein Netz je Zeile, z. B. <code><?= Html::e(TlsCertificateService::DEFAULT_HTTP_NETWORKS) ?></code> (Standard). Leer = kein Netz.</p>
            <?= $error('tls_http_networks', $networkErrors) ?>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>
</section>

<?php if ($preview !== null) {
    $details = $preview['details'];
    [$previewClass, $previewText] = $statusBadge((string) $preview['status'], (int) floor(($details['not_after'] - time()) / 86400)); ?>
    <div class="nav-tree-overlay cert-overlay" data-cert-overlay role="dialog" aria-modal="true" aria-labelledby="cert-preview-title">
        <div class="nav-tree-overlay__box">
            <h2 class="nav-tree-overlay__title" id="cert-preview-title">Zertifikat prüfen und bestätigen</h2>
            <p class="nav-tree-overlay__hint">Bitte prüfen Sie die Angaben. Erst mit „Importieren“ wird das Zertifikat übernommen.</p>

            <div class="cert-preview__head">
                <strong><?= Html::e($details['common_name'] !== '' ? $details['common_name'] : $details['subject']) ?></strong>
                <span class="badge <?= Html::e($previewClass) ?>"><?= Html::e($previewText) ?></span>
            </div>

            <dl class="cert-dl">
                <dt>Gehört zu Request</dt>
                <dd>#<?= (int) $preview['request']['id'] ?> <?= Html::e((string) $preview['request']['common_name']) ?> vom <?= Html::e($time((int) $preview['request']['created_at'])) ?><?= $preview['request']['active'] ? ' (aktiv)' : '' ?></dd>
                <dt>Inhaber</dt><dd><?= Html::e($details['subject']) ?></dd>
                <dt>Namen (SAN)</dt><dd><?= Html::e(implode(', ', $details['san'])) ?: '–' ?></dd>
                <dt>Aussteller</dt><dd><?= Html::e($details['issuer']) ?></dd>
                <dt>Gültig ab</dt><dd><?= Html::e($time($details['not_before'])) ?></dd>
                <dt>Gültig bis</dt><dd><strong><?= Html::e($time($details['not_after'])) ?></strong></dd>
                <dt>Schlüssel</dt><dd><?= Html::e($details['key_type']) ?>, Signatur <?= Html::e($details['signature']) ?></dd>
                <dt>Seriennummer</dt><dd><code><?= Html::e($details['serial']) ?></code></dd>
                <dt>SHA-256</dt><dd><code class="cert-fingerprint"><?= Html::e($details['fingerprint']) ?></code></dd>
                <dt>Zertifikatskette</dt>
                <dd>
                    <?php if ($preview['chain'] === []) { ?>
                        keine Zwischenzertifikate
                    <?php } else { ?>
                        <ol class="cert-chain">
                            <?php foreach ($preview['chain'] as $link) { ?>
                                <li><?= Html::e((string) $link['subject']) ?> <span class="table__hint">(bis <?= Html::e($day((int) $link['not_after'])) ?>)</span></li>
                            <?php } ?>
                        </ol>
                    <?php } ?>
                </dd>
            </dl>

            <?php if ($preview['warnings'] !== []) { ?>
                <ul class="cert-warnings">
                    <?php foreach ($preview['warnings'] as $warning) { ?>
                        <li><?= Html::e((string) $warning) ?></li>
                    <?php } ?>
                </ul>
            <?php } ?>

            <form method="post" action="/admin/zertifikate/import/bestaetigen" class="cert-form">
                <?= Csrf::field() ?>
                <?php if ($preview['request']['active']) { ?>
                    <p class="field__hint">Der Request ist aktiv – das neue Zertifikat wird sofort verwendet.</p>
                <?php } elseif ($preview['usable']) { ?>
                    <div class="field field--check">
                        <input type="checkbox" id="activate" name="activate" value="1" checked>
                        <label for="activate">Direkt als aktives Zertifikat verwenden (HTTP wird dann auf HTTPS umgeleitet)</label>
                    </div>
                <?php } ?>
                <div class="form__actions">
                    <button type="submit" class="button button--primary" data-cert-overlay-focus>Importieren</button>
                    <button type="submit" class="button button--ghost" form="cert-discard" data-cert-overlay-cancel>Abbrechen</button>
                </div>
            </form>
            <form method="post" action="/admin/zertifikate/import/verwerfen" id="cert-discard" hidden>
                <?= Csrf::field() ?>
            </form>
        </div>
    </div>
<?php } ?>
