<?php

declare(strict_types=1);

use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Hintergrundabgleich der Mail-Liste in `orvanta.js`: Er muss sich nach einem
 * Fehler selbst wieder fangen. Ein Fehler (abgelaufene Sitzung, abgelehntes
 * Kennwort, kein Netz) oder eine haengende Abfrage darf den Takt nicht
 * dauerhaft sperren – sonst erscheinen neue Mails erst nach dem Neuladen der
 * Seite.
 */
function orvantaMailRefreshSection(): string
{
    $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/orvanta.js');
    $start = strpos($js, 'function mailRefreshAllowed()');
    $end = strpos($js, 'function applyRefreshedMessages(');

    Assert::true($start !== false && $end !== false && $end > $start, 'Der Mail-Abgleich ist nicht auffindbar');

    return substr($js, $start, $end - $start);
}

Runner::test('Orvanta: Hintergrundabgleich verschluckt Fehler nicht mehr', static function (): void {
    $section = orvantaMailRefreshSection();

    Assert::false(str_contains($section, '.catch(function () {})'), 'Fehler werden noch still verschluckt');
    Assert::contains('mailRefreshFailures = Math.min(mailRefreshFailures + 1, 4);', $section);
});

Runner::test('Orvanta: Sperre des Hintergrundabgleichs wird in jedem Fall geloest', static function (): void {
    $section = orvantaMailRefreshSection();

    // Beide Abfragen melden ihren Fehler, danach wird die Sperre aufgehoben.
    Assert::same(2, substr_count($section, '}).catch(note);'), 'Jede Abfrage muss ihren Fehler melden');
    Assert::contains("var messages = folders.then(function () {", $section);
    Assert::contains('return messages.then(function () {', $section);
    Assert::contains('mailRefreshBusy = false;', $section);
    Assert::false(str_contains($section, 'Promise.all(['), 'Die Sperre haengt sonst an einem abgelehnten Versprechen');
});

Runner::test('Orvanta: haengende Abfrage sperrt den Hintergrundabgleich nicht dauerhaft', static function (): void {
    $section = orvantaMailRefreshSection();

    Assert::contains('mailRefreshStartedAt = Date.now();', $section);
    Assert::contains('Date.now() - mailRefreshStartedAt < MAIL_REFRESH_TIMEOUT', $section);
});

Runner::test('Orvanta: Takt plant sich selbst neu und pausiert im Hintergrund', static function (): void {
    $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/orvanta.js');

    Assert::false(str_contains($js, 'setInterval(refreshMail'), 'Ein festes Intervall laeuft bei Fehlern auf');
    Assert::contains('function scheduleMailPoll(delay) {', $js);
    Assert::contains('mailPollTimer = window.setTimeout(mailPoll, delay);', $js);
    Assert::contains('function mailPoll() {', $js);
    Assert::contains('if (document.hidden) {', $js);
});

Runner::test('Orvanta: Rueckkehr in den Tab gleicht ab und laedt bei Fehlern sichtbar neu', static function (): void {
    $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/orvanta.js');

    Assert::contains('function reloadMailAfterFailedRefresh() {', $js);
    Assert::contains('reloadMailAfterFailedRefresh();', $js);
    // Ein beim Tabwechsel abgebrochenes Ziehen darf nicht sperren.
    Assert::contains('state.dragIds = null;', $js);
});
