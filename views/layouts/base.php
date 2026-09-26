<?php

declare(strict_types=1);

use App\Support\Html;

/** @var string $content */
/** @var string $themeCss */
/** @var string $assetVersion */
/** @var string $appName */
$pageTitle = $pageTitle ?? ($siteTitle ?? $appName);
$activeNav = $activeNav ?? '';
$hasLogo = $hasLogo ?? false;
$hasBackground = $hasBackground ?? false;
$siteSubtitleVisible = $siteSubtitleVisible ?? true;
$flashes = $flashes ?? [];
$announcements = $announcements ?? [];
$documentationEnabled = $documentationEnabled ?? false;
$csrfToken = $csrfToken ?? '';
$ssoUser = isset($ssoUser) && is_array($ssoUser) ? $ssoUser : null;
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
$metaRefresh = isset($metaRefresh) && is_string($metaRefresh) ? $metaRefresh : '';
$ssoLoginUrl = isset($ssoLoginUrl) && is_string($ssoLoginUrl) ? $ssoLoginUrl : '';
?>
<!doctype html>
<html lang="de" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?php if ($metaRefresh !== '') { ?>
        <meta http-equiv="refresh" content="0;url=<?= Html::e($metaRefresh) ?>">
    <?php } ?>
    <meta name="csrf-token" content="<?= Html::e($csrfToken) ?>">
    <title><?= Html::e($pageTitle) ?> – <?= Html::e($siteTitle ?? $appName) ?></title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= Html::e($assetVersion) ?>">
    <style nonce="<?= Html::e($nonce) ?>"><?= $themeCss ?><?php if ($hasBackground) { ?>:root{--watermark-image:url('/hintergrundbild');}<?php } ?></style>
</head>
<body data-page="<?= Html::e($activeNav) ?>">
<a class="skip-link" href="#inhalt">Zum Inhalt springen</a>

<?php if ($hasBackground) { ?>
    <div class="site-watermark" aria-hidden="true"></div>
<?php } ?>

<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="/">
            <?php if ($hasLogo) { ?>
                <img class="brand__logo" src="/logo" alt="<?= Html::e($siteTitle ?? $appName) ?>">
            <?php } else { ?>
                <span class="brand__mark" aria-hidden="true"><?= Html::e(mb_substr((string) ($siteTitle ?? $appName), 0, 2)) ?></span>
            <?php } ?>
            <span class="brand__text">
                <span class="brand__title"><?= Html::e($siteTitle ?? $appName) ?></span>
                <?php if (($siteSubtitleVisible ?? true) && ($siteSubtitle ?? '') !== '') { ?>
                    <span class="brand__subtitle"><?= Html::e($siteSubtitle) ?></span>
                <?php } ?>
            </span>
        </a>

        <nav class="site-nav" aria-label="Hauptnavigation">
            <?php if ($announcements !== []) { ?>
                <details class="site-nav__dropdown" data-announcement-menu>
                    <summary class="site-nav__link site-nav__summary">
                        <span class="site-nav__summary-label">Mitteilungen</span>
                        <span class="site-nav__summary-count" aria-hidden="true"><?= count($announcements) ?></span>
                        <span class="site-nav__chevron" aria-hidden="true"></span>
                    </summary>
                    <ul class="site-nav__dropdown-list">
                        <?php foreach ($announcements as $announcementItem) {
                            $announcementItemId = (int) $announcementItem['id']; ?>
                            <li>
                                <button type="button"
                                        class="site-nav__dropdown-item"
                                        data-announcement-open
                                        data-announcement-id="<?= $announcementItemId ?>">
                                    <?= Html::e((string) $announcementItem['title']) ?>
                                </button>
                            </li>
                        <?php } ?>
                    </ul>
                </details>
            <?php } ?>
            <a class="site-nav__link<?= $activeNav === 'home' ? ' is-active' : '' ?>" href="/"<?= $activeNav === 'home' ? ' aria-current="page"' : '' ?>>Start</a>
            <a class="site-nav__link<?= $activeNav === 'phonebook' ? ' is-active' : '' ?>" href="/telefonliste"<?= $activeNav === 'phonebook' ? ' aria-current="page"' : '' ?>>Telefonliste</a>
            <?php if ($ssoUser !== null) {
                $ssoName = trim((string) ($ssoUser['display_name'] ?? '')) ?: (string) ($ssoUser['username'] ?? '');
                $ssoInitials = '';
                foreach (preg_split('/[\s._-]+/u', $ssoName, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ssoPart) {
                    if (mb_strlen($ssoInitials) < 2) {
                        $ssoInitials .= mb_strtoupper(mb_substr($ssoPart, 0, 1));
                    }
                }
                $ssoTitle = 'Angemeldet als ' . $ssoName . ' (' . (string) ($ssoUser['username'] ?? '') . ')'
                    . (!empty($ssoUser['fake']) ? ' – simulierte Anmeldung (Testmodus)' : '');
                ?>
                <span class="site-user<?= !empty($ssoUser['fake']) ? ' site-user--fake' : '' ?>" title="<?= Html::e($ssoTitle) ?>" data-sso-user>
                    <span class="site-user__avatar" aria-hidden="true"><?= Html::e($ssoInitials) ?></span>
                    <span class="visually-hidden">Angemeldet als</span>
                    <span class="site-user__name"><?= Html::e($ssoName) ?></span>
                    <?php if (!empty($ssoUser['fake'])) { ?>
                        <span class="site-user__badge">Test</span>
                    <?php } ?>
                </span>
            <?php } elseif ($ssoLoginUrl !== '') { ?>
                <a class="site-nav__link site-nav__link--sso" href="<?= Html::e($ssoLoginUrl) ?>" title="Mit dem Windows-Konto anmelden, um persönliche Inhalte zu sehen">Mit Windows anmelden</a>
            <?php } ?>
            <button type="button" class="theme-toggle" data-theme-toggle aria-live="polite">
                <span class="theme-toggle__icon" aria-hidden="true"></span>
                <span class="theme-toggle__label">Design wechseln</span>
            </button>
        </nav>
    </div>
</header>

<main id="inhalt" class="container main">
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container site-footer__inner">
        <span><?= Html::e(($footerText ?? '') !== '' ? $footerText : ($siteTitle ?? $appName)) ?></span>
        <nav class="site-footer__links" aria-label="Fußnavigation">
            <?php if ($documentationEnabled) { ?>
                <a href="/manuals/anwenderhandbuch.pdf" target="_blank" rel="noopener">Anwenderhandbuch</a>
            <?php } ?>
            <a href="/admin">Administration</a>
        </nav>
    </div>
</footer>

<?php if ($announcements !== []) { ?>
    <?php foreach ($announcements as $announcementItem) {
        $announcementItemId = (int) $announcementItem['id'];
        $announcementItemVersion = (string) ($announcementItem['updated_at'] ?? $announcementItem['created_at'] ?? '');
        ?>
        <template class="announcement-template"
                  data-announcement-id="<?= $announcementItemId ?>"
                  data-announcement-version="<?= Html::e($announcementItemVersion) ?>">
            <h2 class="announcement-overlay__title"><?= Html::e((string) $announcementItem['title']) ?></h2>
            <p class="announcement-overlay__text"><?= nl2br(Html::e((string) $announcementItem['message'])) ?></p>
        </template>
    <?php } ?>
    <div class="announcement-overlay"
         data-announcement-overlay
         role="dialog"
         aria-modal="true"
         aria-labelledby="announcement-overlay-title"
         hidden>
        <div class="announcement-overlay__box">
            <div data-announcement-content></div>
            <div class="announcement-overlay__actions">
                <button type="button" class="button button--primary" data-announcement-dismiss>Verstanden</button>
            </div>
        </div>
    </div>
<?php } ?>

<div class="announcement-overlay"
     data-alarm-overlay
     role="dialog"
     aria-modal="true"
     aria-labelledby="alarm-overlay-title"
     hidden>
    <div class="announcement-overlay__box">
        <h2 class="announcement-overlay__title" id="alarm-overlay-title">Alarmierung auslösen</h2>
        <p class="announcement-overlay__text" data-alarm-details></p>

        <div class="alarm-freetext">
            <label class="alarm-freetext__toggle">
                <input type="checkbox" data-alarm-freetext-toggle>
                Freitext hinzufügen
            </label>
            <div class="alarm-freetext__body" data-alarm-freetext-body hidden>
                <textarea class="alarm-freetext__input" data-alarm-freetext rows="3" maxlength="255"
                          placeholder="Zusätzlicher Text für die Meldung …"></textarea>
                <p class="alarm-freetext__counter" data-alarm-freetext-counter aria-live="polite"></p>
            </div>
        </div>

        <div class="alarm-preview">
            <p class="alarm-preview__label">Vorschau</p>
            <div class="alarm-preview__phone" aria-hidden="true">
                <div class="alarm-preview__screen">
                    <div class="alarm-preview__message" data-alarm-preview-message></div>
                </div>
            </div>
        </div>

        <div class="announcement-overlay__actions">
            <button type="button" class="button button--ghost" data-alarm-cancel>Abbrechen</button>
            <button type="button" class="button button--danger" data-alarm-confirm>Alarmierung auslösen</button>
        </div>
    </div>
</div>

<div class="announcement-overlay"
     data-sms-overlay
     role="dialog"
     aria-modal="true"
     aria-labelledby="sms-overlay-title"
     hidden>
    <div class="announcement-overlay__box sms-overlay__box">
        <h2 class="announcement-overlay__title" id="sms-overlay-title">Geschützter Zugriff</h2>
        <p class="announcement-overlay__text" data-sms-title></p>

        <form class="sms-overlay__form" data-sms-stage="phone" novalidate>
            <div class="field">
                <label for="sms-phone">Rufnummer</label>
                <input type="tel" id="sms-phone" name="phone" inputmode="tel" autocomplete="tel"
                       placeholder="+49 170 1234567" maxlength="64">
                <p class="field__hint field__hint--error" data-sms-error hidden></p>
            </div>
            <div class="announcement-overlay__actions">
                <button type="button" class="button button--ghost" data-sms-cancel>Abbrechen</button>
                <button type="submit" class="button button--primary" data-sms-request>Code anfordern</button>
            </div>
        </form>

        <form class="sms-overlay__form" data-sms-stage="code" hidden novalidate>
            <p class="sms-overlay__countdown">Code gültig für <strong data-sms-countdown>120</strong> Sekunden.</p>
            <div class="field">
                <label for="sms-code">Sechsstelliger Code</label>
                <input type="text" id="sms-code" name="code" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" pattern="[0-9]{6}" placeholder="123456">
                <p class="field__hint field__hint--error" data-sms-error hidden></p>
            </div>
            <div class="announcement-overlay__actions">
                <button type="button" class="button button--ghost" data-sms-back>Zurück</button>
                <button type="submit" class="button button--primary" data-sms-verify>Bestätigen</button>
            </div>
        </form>
    </div>
</div>

<script src="/assets/js/app.js?v=<?= Html::e($assetVersion) ?>" defer></script>
<?php if (($pageScript ?? '') !== '') { ?>
    <script src="/assets/js/<?= Html::e($pageScript) ?>?v=<?= Html::e($assetVersion) ?>" defer></script>
<?php } ?>
</body>
</html>
