<?php

declare(strict_types=1);

use App\Support\Dates;
use App\Support\Html;

/** @var int $entryCount */
/** @var string|null $lastSync */
/** @var list<array<string,mixed>> $emergencyNumbers */
?>
<section class="page-head">
    <h1>Telefonliste</h1>
    <p class="page-head__meta">
        <?= (int) $entryCount ?> Einträge
        <?php if ($lastSync !== null) { ?>
            · zuletzt aktualisiert: <?= Html::e(Dates::formatDateTime($lastSync)) ?>
        <?php } ?>
    </p>
</section>

<form class="search" role="search" data-phonebook-form>
    <label class="search__label" for="phonebook-search">Suche nach Name, Abteilung oder Telefonnummer</label>
    <div class="search__control">
        <input type="search"
               id="phonebook-search"
               name="q"
               class="search__input"
               autocomplete="off"
               spellcheck="false"
               placeholder="z. B. Mustermann, IT oder 123456"
               aria-describedby="phonebook-status">
        <button type="submit" class="button button--primary">Suchen</button>
    </div>
    <p class="search__hint" id="phonebook-status" role="status" aria-live="polite" data-phonebook-status>
        Bitte geben Sie einen Suchbegriff ein oder starten Sie die Suche, um alle Einträge zu sehen.
    </p>
</form>

<?php if ($emergencyNumbers !== []) { ?>
    <div class="phonebook" data-phonebook-emergency>
        <?php foreach ($emergencyNumbers as $number) { ?>
            <article class="person person--emergency">
                <h2 class="person__name"><?= Html::e((string) $number['label']) ?></h2>
                <dl class="person__details">
                    <div class="person__row">
                        <dt>Telefon</dt>
                        <dd>
                            <a class="person__phone" href="tel:<?= Html::e(preg_replace('/[^\d+]/', '', (string) $number['phone']) ?? '') ?>">
                                <?= Html::e((string) $number['phone']) ?>
                            </a>
                        </dd>
                    </div>
                    <?php if ((string) $number['description'] !== '') { ?>
                        <div class="person__row">
                            <dt>Hinweis</dt>
                            <dd><?= Html::e((string) $number['description']) ?></dd>
                        </div>
                    <?php } ?>
                </dl>
            </article>
        <?php } ?>
    </div>
<?php } ?>

<div class="phonebook" data-phonebook-results hidden>
    <noscript>
        <p class="empty-state">Für die Suche wird JavaScript benötigt.</p>
    </noscript>
</div>

<div class="phonebook__actions">
    <button type="button" class="button button--ghost" data-phonebook-more hidden>Weitere Einträge laden</button>
</div>

<template data-phonebook-template>
    <article class="person">
        <h2 class="person__name" data-field="display_name"></h2>
        <dl class="person__details">
            <div class="person__row">
                <dt>Telefon</dt>
                <dd><a class="person__phone" data-field="phone" href="#"></a></dd>
            </div>
            <div class="person__row" data-row="mobile" hidden>
                <dt>Mobil</dt>
                <dd><a class="person__phone" data-field="mobile" href="#"></a></dd>
            </div>
            <div class="person__row">
                <dt>Abteilung</dt>
                <dd data-field="department"></dd>
            </div>
            <div class="person__row" data-row="email" hidden>
                <dt>E-Mail</dt>
                <dd><a class="person__mail" data-field="email" href="#"></a></dd>
            </div>
        </dl>
    </article>
</template>

