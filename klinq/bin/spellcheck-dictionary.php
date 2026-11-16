<?php

declare(strict_types=1);

/**
 * Holt das deutsche Wörterbuch einmalig aus dem Netz und bereitet es für die
 * Rechtschreibprüfung im Help Desk auf (siehe SpellcheckCompiler).
 *
 * Aufruf: php bin/spellcheck-dictionary.php [--force] [--quiet]
 *
 * Der Lauf ist idempotent: liegt bereits ein vollständiger Datensatz vor
 * (meta.json), passiert nichts. Mit --force werden die Quelldateien erneut
 * geladen und übersetzt. Das Skript wird beim Containerstart aufgerufen
 * (docker/php/entrypoint.sh) und darf dort nicht zum Abbruch führen – Fehler
 * werden als Meldung ausgegeben und über den Rückgabewert angezeigt.
 */

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Env;
use App\Services\Helpdesk\Spellcheck\SpellcheckCompiler;
use App\Services\Helpdesk\Spellcheck\SpellcheckDictionary;

$basePath = dirname(__DIR__);
Env::load($basePath . '/.env');
$config = new Config($basePath . '/config');

$force = in_array('--force', $argv, true);
$quiet = in_array('--quiet', $argv, true);

$log = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, '[rechtschreibung] ' . $message . PHP_EOL);
    }
};

$targetDir = rtrim((string) $config->get('helpdesk.spellcheck.dir', $basePath . '/storage/dictionaries/de_DE'), '/');
$baseUrl = rtrim((string) $config->get('helpdesk.spellcheck.url', ''), '/');
$timeout = max(5, (int) $config->get('helpdesk.spellcheck.timeout', 120));

if ($config->get('helpdesk.spellcheck.enabled', true) === false) {
    $log('Rechtschreibprüfung ist abgeschaltet (HELPDESK_SPELLCHECK_ENABLED), nichts zu tun.');
    exit(0);
}

if (!$force && (new SpellcheckDictionary($targetDir))->isUsable()) {
    $log('Wörterbuch ist bereits aufbereitet, nichts zu tun.');
    exit(0);
}

if ($baseUrl === '') {
    $log('Keine Quelladresse hinterlegt (HELPDESK_SPELLCHECK_URL).');
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
    $stats = (new SpellcheckCompiler())->compile(
        $sourceDir . '/de_DE_frami.aff',
        $sourceDir . '/de_DE_frami.dic',
        $compileDir
    );
    $log(sprintf(
        'Übersetzt in %d ms: %d Wörter, %d Suffix- und %d Prefixregeln.',
        (int) round((microtime(true) - $started) * 1000),
        $stats['words'],
        $stats['suffixes'],
        $stats['prefixes']
    ));
    // Erst den alten Datensatz entwerten, dann die neuen Dateien einsetzen:
    // die Nutzdaten kommen zuerst, meta.json und QUELLE.txt zuletzt. meta.json
    // gilt als Vollständigkeitsmerkmal – stünde es vor den Wortdaten, könnte ein
    // Abbruch hier einen halb getauschten Datensatz hinterlassen, der als
    // vollständig durchgeht.
    @unlink($targetDir . '/' . SpellcheckCompiler::META_FILE);
    $markers = [SpellcheckCompiler::META_FILE, SpellcheckCompiler::NOTICE_FILE];
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

$dictionary = new SpellcheckDictionary($targetDir);
if (!$dictionary->isUsable()) {
    $log('Der erzeugte Datensatz konnte nicht gelesen werden.');
    exit(1);
}
$log('Fertig: ' . $dictionary->wordCount() . ' Wörter stehen bereit.');

exit(0);

/**
 * Lädt eine Datei über HTTP und legt sie im Zielverzeichnis ab.
 */
function download(string $url, string $target, int $timeout): void
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'follow_location' => 1,
            'max_redirects' => 5,
            'user_agent' => 'klinq-rechtschreibung/1.0',
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
    // Nach Weiterleitungen enthält die Liste die Kopfzeilen aller Antworten;
    // maßgeblich ist die letzte Statuszeile.
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
