<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use App\Services\Storage\IncidentSettings;

/**
 * Erkennung auffaelligen Ueberschreibens (Ransomware-aehnliches Verhalten) in
 * den Nextcloud-Benutzerdateien (<benutzer>/files/...).
 *
 * Der Abgleich (SyncEngine) meldet jede neue und jede geaenderte Datei. Je
 * Benutzer werden im Katalog festgehalten:
 *
 * - "extension": Datei mit bekannter Ransomware-Endung bzw. passendem Namen
 *   (z. B. *.makop, *.crypt, Erpresserschreiben) wurde geschrieben,
 * - "content":   eine vorhandene Datei wurde mit Inhalt ueberschrieben, der nicht
 *   mehr zum Dateityp passt und verschluesselt wirkt (hohe Entropie),
 * - "changed":   eine vorhandene Datei wurde ueberschrieben.
 *
 * Nextcloud protokolliert, wer (Benutzer der Sitzung, IP-Adresse, Client) eine
 * Datei geschrieben hat (TieringClient::logWrite). Damit wird ein Vorfall dem
 * tatsaechlich schreibenden Benutzer zugeordnet – auch in freigegebenen Ordnern.
 */
final class ThreatDetector
{
    /** Aktivitaeten werden hoechstens so lange vorgehalten. */
    public const RETENTION_SECONDS = 86400;

    /** Ab dieser Entropie (Bit je Byte) gilt eine Stichprobe als verschluesselt. */
    public const ENTROPY_THRESHOLD = 7.2;

    private const SAMPLE_BYTES = 8192;
    private const MIN_SAMPLE_BYTES = 256;
    private const MAX_SAMPLES = 15;

    private const PK = ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"];
    private const OLE = ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"];

    private const TEXT = ['txt', 'csv', 'md', 'xml', 'html', 'htm', 'json', 'log', 'ini', 'cfg', 'conf', 'svg', 'eml',
        'ps1', 'bat', 'cmd', 'sql', 'php', 'js', 'css', 'yml', 'yaml', 'tex', 'vcf', 'ics', 'tsv'];

    private \Closure $clock;

    /**
     * @param (callable():int)|null $clock
     */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly IncidentSettings $settings,
        ?callable $clock = null
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    public function settings(): IncidentSettings
    {
        return $this->settings;
    }

    /**
     * Neue oder geaenderte Datei aus dem Abgleich.
     */
    public function observe(string $source, string $rel, string $absolute, int $size, bool $isNew): void
    {
        if (!$this->settings->enabled() || $source !== PathRules::SOURCE_NEXTCLOUD_DATA
            || preg_match('#^([^/]+)/files/(.+)$#', $rel, $match) !== 1) {
            return;
        }
        $owner = $match[1];
        $name = basename($rel);
        $now = ($this->clock)();

        $pattern = $this->settings->matchName($name);
        if ($pattern !== null) {
            $this->record($owner, $now, 'extension', $rel, $size, $pattern, !$isNew);

            return;
        }
        if ($isNew) {
            return;
        }
        $reason = self::inspectContent($absolute, $name, $size);
        $this->record($owner, $now, $reason !== null ? 'content' : 'changed', $rel, $size, $reason ?? '', true);
    }

    /**
     * Schreibprotokoll aus Nextcloud uebernehmen (wer hat welche Datei geschrieben).
     *
     * @param list<array{t:int,u:string,ip:string,ua:string,p:string}> $entries
     */
    public function ingestWrites(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $pdo = $this->catalog->pdo();
        $this->catalog->transaction(static function () use ($pdo, $entries): void {
            $write = $pdo->prepare('INSERT INTO writes (path, uid, ip, ua, at) VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (path) DO UPDATE SET uid = excluded.uid, ip = excluded.ip, ua = excluded.ua, at = excluded.at
                WHERE excluded.at >= writes.at');
            $client = $pdo->prepare('INSERT INTO clients (uid, ip, ua, first_at, last_at, writes) VALUES (?, ?, ?, ?, ?, 1)
                ON CONFLICT (uid, ip, ua) DO UPDATE SET last_at = MAX(last_at, excluded.last_at), writes = writes + 1');
            foreach ($entries as $entry) {
                if ($entry['u'] === '' || $entry['p'] === '') {
                    continue;
                }
                $write->execute([$entry['p'], $entry['u'], $entry['ip'], $entry['ua'], $entry['t']]);
                $client->execute([$entry['u'], $entry['ip'], $entry['ua'], $entry['t'], $entry['t']]);
            }
        });
    }

    /**
     * Benutzer, deren Aktivitaet im Zeitfenster eine Schwelle ueberschreitet.
     *
     * @param array<string,int> $floors Benutzer => Zeitpunkt, vor dem Aktivitaet nicht mehr zaehlt
     *                                   (z. B. Erledigung eines Vorfalls)
     *
     * @return array<string,array<string,mixed>> Benutzer => Befund (siehe stats(), zusaetzlich rules)
     */
    public function evaluate(array $floors = []): array
    {
        $now = ($this->clock)();
        $this->prune($now);
        if (!$this->settings->enabled()) {
            return [];
        }
        $since = $now - $this->settings->windowSeconds();
        $statement = $this->catalog->pdo()->prepare(
            "SELECT COALESCE(w.uid, a.owner) AS who,
                    SUM(a.changed) AS changed,
                    SUM(CASE WHEN a.kind = 'content' THEN 1 ELSE 0 END) AS content,
                    SUM(CASE WHEN a.kind = 'extension' THEN 1 ELSE 0 END) AS extension
             FROM activity a LEFT JOIN writes w ON w.path = a.path
             WHERE a.at >= ? GROUP BY who"
        );
        $statement->execute([$since]);
        $findings = [];
        foreach ($statement->fetchAll() as $row) {
            $user = (string) $row['who'];
            $from = $since;
            if (($floors[$user] ?? 0) > $since) {
                $from = $floors[$user];
                $stats = $this->stats($user, $from);
                $row = ['changed' => $stats['changed'], 'content' => $stats['suspicious'], 'extension' => $stats['extension']];
            }
            $rules = [];
            if ((int) $row['extension'] >= $this->settings->extensionThreshold()) {
                $rules[] = 'extension';
            }
            if ((int) $row['content'] >= $this->settings->contentThreshold()) {
                $rules[] = 'content';
            }
            if ((int) $row['changed'] >= $this->settings->overwriteThreshold()) {
                $rules[] = 'overwrite';
            }
            if ($rules === []) {
                continue;
            }
            $findings[$user] = ['rules' => $rules] + $this->stats($user, $from);
        }

        return $findings;
    }

    /**
     * Umfang der Aktivitaet eines Benutzers seit $since (fuer die Vorfall-Tabelle).
     *
     * @return array{user:string,attribution:string,changed:int,suspicious:int,extension:int,bytes:int,first:?int,last:?int,
     *   samples:list<array{path:string,kind:string,detail:string,size:int,at:int}>,patterns:array<string,int>,reasons:array<string,int>,
     *   owners:array<string,int>,clients:list<array{ip:string,ua:string,writes:int,last:int}>}
     */
    public function stats(string $user, int $since): array
    {
        $pdo = $this->catalog->pdo();
        $scope = 'FROM activity a LEFT JOIN writes w ON w.path = a.path WHERE a.at >= ? AND (w.uid = ? OR (w.uid IS NULL AND a.owner = ?))';
        $params = [$since, $user, $user];

        $totals = $pdo->prepare(
            "SELECT COUNT(*) AS rows_total, COALESCE(SUM(a.changed), 0) AS changed,
                    SUM(CASE WHEN a.kind = 'content' THEN 1 ELSE 0 END) AS content,
                    SUM(CASE WHEN a.kind = 'extension' THEN 1 ELSE 0 END) AS extension,
                    COALESCE(SUM(a.size), 0) AS bytes, MIN(a.at) AS first_at, MAX(a.at) AS last_at,
                    SUM(CASE WHEN w.uid IS NOT NULL THEN 1 ELSE 0 END) AS attributed " . $scope
        );
        $totals->execute($params);
        $sum = $totals->fetch() ?: [];

        $samples = $pdo->prepare(
            "SELECT a.path, a.kind, a.detail, a.size, a.at " . $scope . "
             ORDER BY CASE a.kind WHEN 'extension' THEN 0 WHEN 'content' THEN 1 ELSE 2 END, a.at DESC LIMIT " . self::MAX_SAMPLES
        );
        $samples->execute($params);

        $grouped = static function (string $column, string $kind) use ($pdo, $scope, $params): array {
            $statement = $pdo->prepare(
                'SELECT ' . $column . ' AS k, COUNT(*) AS n ' . $scope . ($kind !== '' ? ' AND a.kind = ' . $pdo->quote($kind) : '')
                . ' GROUP BY k ORDER BY n DESC LIMIT 20'
            );
            $statement->execute($params);
            $result = [];
            foreach ($statement->fetchAll() as $row) {
                $result[(string) $row['k']] = (int) $row['n'];
            }

            return $result;
        };

        $clients = $pdo->prepare('SELECT ip, ua, writes, last_at FROM clients WHERE uid = ? AND last_at >= ? ORDER BY writes DESC, last_at DESC LIMIT 5');
        $clients->execute([$user, $since]);

        return [
            'user' => $user,
            'attribution' => (int) ($sum['attributed'] ?? 0) > 0 ? 'session' : 'owner',
            'changed' => (int) ($sum['changed'] ?? 0),
            'suspicious' => (int) ($sum['content'] ?? 0),
            'extension' => (int) ($sum['extension'] ?? 0),
            'bytes' => (int) ($sum['bytes'] ?? 0),
            'first' => isset($sum['first_at']) ? (int) $sum['first_at'] : null,
            'last' => isset($sum['last_at']) ? (int) $sum['last_at'] : null,
            'samples' => array_map(static fn (array $r): array => [
                'path' => (string) $r['path'], 'kind' => (string) $r['kind'], 'detail' => (string) $r['detail'],
                'size' => (int) $r['size'], 'at' => (int) $r['at'],
            ], $samples->fetchAll()),
            'patterns' => $grouped('a.detail', 'extension'),
            'reasons' => $grouped('a.detail', 'content'),
            'owners' => $grouped('a.owner', ''),
            'clients' => array_map(static fn (array $r): array => [
                'ip' => (string) $r['ip'], 'ua' => (string) $r['ua'], 'writes' => (int) $r['writes'], 'last' => (int) $r['last_at'],
            ], $clients->fetchAll()),
        ];
    }

    /**
     * Prueft eine ueberschriebene Datei: passt der Inhalt nicht mehr zum
     * Dateityp und wirkt er verschluesselt, liefert die Funktion den Grund.
     */
    public static function inspectContent(string $file, string $name, int $size): ?string
    {
        if ($size < self::MIN_SAMPLE_BYTES) {
            return null;
        }
        $dot = strrpos($name, '.');
        $extension = $dot === false ? '' : strtolower(substr($name, $dot + 1));
        $magic = self::magic($extension);
        $text = in_array($extension, self::TEXT, true);
        if ($magic === null && !$text) {
            return null;
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        $sample = (string) fread($handle, self::SAMPLE_BYTES);
        fclose($handle);
        if (strlen($sample) < self::MIN_SAMPLE_BYTES) {
            return null;
        }
        $entropy = self::entropy($sample);
        if ($entropy < self::ENTROPY_THRESHOLD) {
            return null;
        }
        if ($text) {
            return 'Textdatei .' . $extension . ' mit verschlüsseltem Inhalt';
        }
        foreach ($magic as $signature) {
            if ($extension === 'pdf' ? str_contains(substr($sample, 0, 1024), $signature) : str_starts_with($sample, $signature)) {
                return null;
            }
        }

        return 'Inhalt passt nicht zu .' . $extension . ' (verschlüsselt?)';
    }

    /**
     * Shannon-Entropie in Bit je Byte (0 … 8).
     */
    public static function entropy(string $data): float
    {
        $length = strlen($data);
        if ($length === 0) {
            return 0.0;
        }
        $entropy = 0.0;
        foreach (count_chars($data, 1) as $count) {
            $p = $count / $length;
            $entropy -= $p * log($p, 2);
        }

        return $entropy;
    }

    /**
     * Erwartete Dateisignaturen je Endung (null = unbekannt).
     *
     * @return list<string>|null
     */
    private static function magic(string $extension): ?array
    {
        return match ($extension) {
            // Kennwortgeschuetzte OOXML-Dateien sind OLE-Container.
            'docx', 'xlsx', 'pptx', 'docm', 'xlsm', 'pptm', 'dotx', 'xltx', 'potx', 'vsdx' => array_merge(self::PK, self::OLE),
            'odt', 'ods', 'odp', 'odg', 'zip', 'jar', 'epub' => self::PK,
            'doc', 'xls', 'ppt', 'msg', 'vsd', 'pub', 'dot', 'xlt', 'pot' => self::OLE,
            'pdf' => ['%PDF'],
            'png' => ["\x89PNG\r\n\x1A\n"],
            'jpg', 'jpeg' => ["\xFF\xD8\xFF"],
            'gif' => ['GIF87a', 'GIF89a'],
            'bmp' => ['BM'],
            'tif', 'tiff' => ["II*\x00", "MM\x00*"],
            '7z' => ["7z\xBC\xAF\x27\x1C"],
            'rar' => ["Rar!\x1A\x07"],
            'gz', 'tgz' => ["\x1F\x8B"],
            'rtf' => ['{\\rtf'],
            'webp', 'wav', 'avi' => ['RIFF'],
            default => null,
        };
    }

    private function record(string $owner, int $at, string $kind, string $path, int $size, string $detail, bool $changed): void
    {
        $this->catalog->pdo()->prepare('INSERT INTO activity (owner, at, kind, path, size, detail, changed) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$owner, $at, $kind, $path, $size, mb_substr($detail, 0, 200), $changed ? 1 : 0]);
    }

    private function prune(int $now): void
    {
        $limit = $now - max(self::RETENTION_SECONDS, $this->settings->windowSeconds());
        $pdo = $this->catalog->pdo();
        $pdo->prepare('DELETE FROM activity WHERE at < ?')->execute([$limit]);
        $pdo->prepare('DELETE FROM writes WHERE at < ?')->execute([$limit]);
        $pdo->prepare('DELETE FROM clients WHERE last_at < ?')->execute([$limit]);
    }
}
