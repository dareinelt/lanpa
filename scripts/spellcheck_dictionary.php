<?php

declare(strict_types=1);

/**
 * Holt das deutsche Woerterbuch einmalig aus dem Netz und bereitet es fuer die
 * Rechtschreibpruefung in Orvanta auf (siehe OrvantaSpellcheckCompiler).
 *
 * Aufruf: php scripts/spellcheck_dictionary.php [--force] [--quiet]
 *
 * Der Lauf ist idempotent: liegt bereits ein vollstaendiger Datensatz vor
 * (meta.json), passiert nichts. Mit --force werden die Quelldateien erneut
 * geladen und uebersetzt. Das Skript wird beim Containerstart aufgerufen
 * (docker/php/entrypoint.sh) und darf dort nicht zum Abbruch fuehren - Fehler
 * werden als Meldung ausgegeben und ueber den Rueckgabewert angezeigt.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Services\Orvanta\OrvantaSpellcheckCompiler;
use App\Services\Orvanta\OrvantaSpellcheckDictionary;

$force = in_array('--force', $argv, true);
$quiet = in_array('--quiet', $argv, true);

$log = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, '[rechtschreibung] ' . $message . PHP_EOL);
    }
};

$targetDir = rtrim((string) Config::get('office.spellcheck_dir', BASE_PATH . '/storage/dictionaries/de_DE'), '/');
$baseUrl = rtrim((string) Config::get('office.spellcheck_url', ''), '/');
$timeout = max(5, (int) Config::get('office.spellcheck_timeout', 120));

if (Config::get('office.spellcheck_enabled', true) === false) {
    $log('Rechtschreibpruefung ist abgeschaltet (ORVANTA_SPELLCHECK), nichts zu tun.');
    exit(0);
}

if (!$force && (new OrvantaSpellcheckDictionary($targetDir))->isUsable()) {
    $log('Woerterbuch ist bereits aufbereitet, nichts zu tun.');
    exit(0);
}

if ($baseUrl === '') {
    $log('Keine Quelladresse hinterlegt (office.spellcheck_url).');
    exit(1);
}

$sourceDir = $targetDir . '/quelle';
$compileDir = $targetDir . '/neu-' . getmypid();

try {
    foreach (['de_DE_frami.aff', 'de_DE_frami.dic'] as $name) {
        $log('Lade ' . $name . ' …');
        download($baseUrl . '/' . $name, $sourceDir . '/' . $name, $timeout);
    }

    $started = microtime(true);
    $stats = (new OrvantaSpellcheckCompiler())->compile(
        $sourceDir . '/de_DE_frami.aff',
        $sourceDir . '/de_DE_frami.dic',
        $compileDir
    );
    $log(sprintf(
        'Uebersetzt in %d ms: %d Woerter, %d Suffix- und %d Prefixregeln.',
        (int) round((microtime(true) - $started) * 1000),
        $stats['words'],
        $stats['suffixes'],
        $stats['prefixes']
    ));
    // Erst den alten Datensatz entwerten, dann die neuen Dateien einsetzen:
    // die Nutzdaten kommen zuerst, meta.json und QUELLE.txt zuletzt.
    // meta.json gilt als Vollstaendigkeitsmerkmal - stuende es vor den
    // Wortdaten, koennte ein Abbruch hier einen halb getauschten Datensatz
    // hinterlassen, der als vollstaendig durchgeht.
    @unlink($targetDir . '/' . OrvantaSpellcheckCompiler::META_FILE);
    $markers = [OrvantaSpellcheckCompiler::META_FILE, OrvantaSpellcheckCompiler::NOTICE_FILE];
    $files = array_values(array_diff(scandir($compileDir) ?: [], ['.', '..']));
    usort($files, static function (string $a, string $b) use ($markers): int {
        return (int) in_array($a, $markers, true) <=> (int) in_array($b, $markers, true);
    });
    foreach ($files as $file) {
        if (!rename($compileDir . '/' . $file, $targetDir . '/' . $file)) {
            throw new RuntimeException('Datei konnte nicht eingesetzt werden: ' . $file);
        }
    }
} catch (Throwable $error) {
    $log('Fehlgeschlagen: ' . $error->getMessage());
    removeDirectory($compileDir);
    exit(1);
}
removeDirectory($compileDir);

$dictionary = new OrvantaSpellcheckDictionary($targetDir);
if (!$dictionary->isUsable()) {
    $log('Der erzeugte Datensatz konnte nicht gelesen werden.');
    exit(1);
}
$log('Fertig: ' . $dictionary->wordCount() . ' Woerter stehen bereit.');

exit(0);

/**
 * Laedt eine Datei ueber HTTP und legt sie im Zielverzeichnis ab.
 */
function download(string $url, string $target, int $timeout): void
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'follow_location' => 1,
            'max_redirects' => 5,
            'user_agent' => 'lanpa-rechtschreibung/1.0',
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $content = @file_get_contents($url, false, $context);
    if ($content === false || $content === '') {
        throw new RuntimeException('Die Datei konnte nicht geladen werden: ' . $url);
    }
    // Nach Weiterleitungen enthaelt die Liste die Kopfzeilen aller Antworten;
    // massgeblich ist die letzte Statuszeile.
    $status = '';
    foreach (http_get_last_response_headers() ?? [] as $header) {
        if (str_starts_with($header, 'HTTP/')) {
            $status = $header;
        }
    }
    if (preg_match('#^HTTP/\S+\s+200\b#', $status) !== 1) {
        throw new RuntimeException('Unerwartete Antwort beim Laden von ' . $url . ': ' . ($status !== '' ? $status : 'ohne Status'));
    }
    ensureDirectory(dirname($target));
    if (@file_put_contents($target, $content) === false) {
        throw new RuntimeException('Die Datei konnte nicht geschrieben werden: ' . $target);
    }
}

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
        throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $directory);
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $directory . '/' . $file;
        is_dir($path) ? removeDirectory($path) : @unlink($path);
    }
    @rmdir($directory);
}
