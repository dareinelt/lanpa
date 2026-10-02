<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Support\Validator;

/**
 * Meldung der gemappten Netzlaufwerke eines Windows-Clients
 * (scripts/network-drives-report.ps1, Anmeldeskript per Gruppenrichtlinie).
 *
 *   POST /sso/laufwerke   laufwerke = "H=\\server\freigabe" je Zeile,
 *                         domaene, computer (optional)
 *
 * Der auth-Container verlangt fuer diesen Pfad die Windows-Anmeldung und
 * reicht den Benutzer als Header weiter; ohne erkannten Benutzer wird nichts
 * gespeichert. Antwort als Text (Protokoll des Anmeldeskripts).
 */
final class NetworkDriveController extends Controller
{
    private const MAX_BODY = 16384;
    /** Header des Anmeldeskripts (X-Intranet-Client: netzlaufwerke). */
    public const CLIENT_HEADER_VALUE = 'netzlaufwerke';

    public function report(Request $request): Response
    {
        $sso = Container::sso();
        if (!$sso->isEnabled() || $sso->isFake()) {
            return $this->text('Windows-Anmeldung (SSO) ist im Intranet nicht aktiviert.', 404);
        }

        $user = $sso->resolveHeader($request);
        if ($user === null) {
            return $this->text('Benutzer nicht erkannt (Windows-Anmeldung fehlt oder kein Telefonbucheintrag).', 403);
        }

        // Schutz vor Cross-Site-Formularen: Browser senden die
        // Windows-Anmeldung automatisch mit. Nur das Anmeldeskript setzt den
        // eigenen Header (Browser duerften ihn fremden Seiten nur nach einer
        // CORS-Freigabe senden, die es hier nicht gibt) und sendet kein Origin.
        if (!self::isScriptRequest($request->server)) {
            return $this->text('Nur für das Anmeldeskript (Header X-Intranet-Client fehlt).', 400);
        }

        $raw = (string) $request->input('laufwerke', '');
        if (strlen($raw) > self::MAX_BODY) {
            return $this->text('Meldung zu groß.', 413);
        }

        try {
            $result = Container::networkDrives()->report(
                $user,
                $raw,
                (string) $request->input('domaene', ''),
                (string) $request->input('computer', '')
            );
        } catch (ValidationException $exception) {
            return $this->text((string) (array_values($exception->errors())[0] ?? 'Ungültige Meldung.'), 422);
        }

        app_logger()->info('Netzlaufwerke gemeldet.', [
            'user' => $user['office_uid'],
            'drives' => count($result['accepted']),
            'ignored' => count($result['ignored']),
            'changed' => $result['changed'],
        ]);

        $lines = ['OK ' . $user['office_uid'] . ': ' . count($result['accepted']) . ' Laufwerk(e) übernommen.'];
        foreach ($result['accepted'] as $drive) {
            $lines[] = '  ' . $drive['letter'] . ': ' . $drive['unc'] . ($drive['excluded'] ? ' (wird nie weitergereicht)' : '');
        }
        foreach ($result['ignored'] as $ignored) {
            $lines[] = '  ignoriert: ' . Validator::cleanText($ignored['entry'], 120) . ' – ' . $ignored['reason'];
        }
        if ($result['push'] !== null) {
            $lines[] = ($result['push']['ok'] ? 'Nextcloud: ' : 'Nextcloud (wird später erneut versucht): ') . $result['push']['message'];
        }

        return $this->text(implode("\n", $lines) . "\n");
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function isScriptRequest(array $server): bool
    {
        if (strtolower(trim((string) ($server['HTTP_X_INTRANET_CLIENT'] ?? ''))) !== self::CLIENT_HEADER_VALUE) {
            return false;
        }
        if (trim((string) ($server['HTTP_ORIGIN'] ?? '')) !== '') {
            return false;
        }
        $site = strtolower(trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')));

        return $site === '' || $site === 'none';
    }

    private function text(string $body, int $status = 200): Response
    {
        return Response::text($body, $status)->withHeader('Cache-Control', 'no-store');
    }
}
