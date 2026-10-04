<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Repositories\PhonebookRepository;
use App\Services\Office\NextcloudFilesService;

/**
 * Empfaenger-Vorschlaege fuer An/Cc/Bcc: Telefonliste (lokaler AD-Bestand)
 * plus die Adressen, an die der Benutzer bereits gesendet hat. Letztere
 * liegen je Benutzer als versteckte Datei im Orvanta-Ordner seiner Nextcloud
 * (.empfaenger.json); die Datei wird fuer kurze Zeit in der PHP-Sitzung
 * gehalten, damit nicht jeder Tastendruck Nextcloud befragt. Fehler der
 * Ablage werden toleriert – der Verlauf ist eine Komfortfunktion.
 */
final class OrvantaRecipientService
{
    public const FILE_NAME = '.empfaenger.json';
    public const MAX_ENTRIES = 500;
    public const CACHE_TTL = 120;
    public const DEFAULT_LIMIT = 8;
    private const FORMAT = 'lanpa-orvanta-empfaenger';
    private const MAX_NAME = 120;

    /** @var array<string,list<array{name:string,email:string,count:int,last_used:int}>> */
    private array $memo = [];

    /**
     * @param \Closure(string):mixed $cacheGet   Sitzungs-Cache lesen (Schluessel => Wert|null)
     * @param \Closure(string,mixed):void $cachePut Sitzungs-Cache schreiben
     */
    public function __construct(
        private readonly OrvantaConfigService $config,
        private readonly NextcloudFilesService $nextcloud,
        private readonly PhonebookRepository $phonebook,
        private readonly \Closure $cacheGet,
        private readonly \Closure $cachePut
    ) {
    }

    /**
     * Vorschlaege zu einem Suchbegriff: zuerst der eigene Verlauf, dann die
     * Telefonliste (ohne Dubletten nach E-Mail-Adresse).
     *
     * @return list<array{display_name:string,email:string,department:string,source:string,recent:bool}>
     */
    public function suggest(string $uid, string $term, int $limit = self::DEFAULT_LIMIT, bool $includeWithoutEmail = false): array
    {
        $term = trim($term);
        $limit = max(1, min(25, $limit));
        if ($term === '') {
            return [];
        }

        $items = [];
        $seen = [];
        $known = [];
        $rows = $this->phonebook->search($term, $limit + 25, 0, $includeWithoutEmail)['items'];
        foreach ($rows as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '') {
                $known[strtolower($email)] = $row;
            }
        }

        foreach ($this->recent($uid) as $entry) {
            if (!self::matches($entry['name'], $entry['email'], $term)) {
                continue;
            }
            $key = strtolower($entry['email']);
            $seen[$key] = true;
            // Ist die Adresse in der Telefonliste bekannt, gelten deren Name und Bereich.
            $row = $known[$key] ?? null;
            $items[] = [
                'display_name' => $row !== null && trim((string) ($row['display_name'] ?? '')) !== '' ? (string) $row['display_name'] : $entry['name'],
                'email' => $row !== null ? trim((string) $row['email']) : $entry['email'],
                'department' => $row !== null ? (string) ($row['department'] ?? '') : '',
                'source' => 'Zuletzt verwendet',
                'recent' => true,
            ];
            if (count($items) >= $limit) {
                return $items;
            }
        }

        foreach ($rows as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || isset($seen[strtolower($email)])) {
                continue;
            }
            $seen[strtolower($email)] = true;
            $items[] = [
                'display_name' => (string) ($row['display_name'] ?? ''),
                'email' => $email,
                'department' => (string) ($row['department'] ?? ''),
                'source' => 'Telefonliste',
                'recent' => false,
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Merkt sich die Empfaenger einer gesendeten Nachricht.
     *
     * @param list<array{name?:mixed,email?:mixed}|string> $recipients
     */
    public function remember(string $uid, array $recipients, ?int $now = null): bool
    {
        $now ??= time();
        $clean = self::normalize($recipients);
        if ($clean === [] || $this->nextcloud->unavailableReason() !== null) {
            return false;
        }

        $entries = [];
        foreach ($this->recent($uid) as $entry) {
            $entries[strtolower($entry['email'])] = $entry;
        }
        foreach ($clean as $recipient) {
            $key = strtolower($recipient['email']);
            $previous = $entries[$key] ?? ['name' => '', 'email' => $recipient['email'], 'count' => 0, 'last_used' => 0];
            $entries[$key] = [
                'name' => $recipient['name'] !== '' ? $recipient['name'] : $previous['name'],
                'email' => $previous['email'],
                'count' => $previous['count'] + 1,
                'last_used' => $now,
            ];
        }

        $list = array_values($entries);
        usort($list, static fn (array $a, array $b): int => [$b['last_used'], $b['count']] <=> [$a['last_used'], $a['count']]);
        $list = array_slice($list, 0, self::MAX_ENTRIES);

        $json = json_encode(['format' => self::FORMAT, 'version' => 1, 'updated_at' => $now, 'items' => $list], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        $result = $this->nextcloud->upload($uid, $this->config->cacheFolder(), self::FILE_NAME, $json);
        if (!$result['ok']) {
            app_logger()->warning('Orvanta: Empfänger-Verlauf konnte nicht gespeichert werden.', ['uid' => $uid, 'message' => $result['message']]);

            return false;
        }
        $this->store($uid, $list, $now);

        return true;
    }

    /**
     * Verlauf des Benutzers, zuletzt verwendete zuerst.
     *
     * @return list<array{name:string,email:string,count:int,last_used:int}>
     */
    public function recent(string $uid): array
    {
        if (isset($this->memo[$uid])) {
            return $this->memo[$uid];
        }
        $cached = ($this->cacheGet)($this->cacheKey($uid));
        if (is_array($cached) && is_array($cached['items'] ?? null) && (int) ($cached['at'] ?? 0) > time() - self::CACHE_TTL) {
            return $this->memo[$uid] = self::entries($cached['items']);
        }

        $list = [];
        if ($this->nextcloud->unavailableReason() === null) {
            $fetched = $this->nextcloud->fetch($uid, $this->config->cacheFolder(), self::FILE_NAME);
            if ($fetched['ok']) {
                $data = json_decode($fetched['content'], true);
                $list = is_array($data) && ($data['format'] ?? '') === self::FORMAT ? self::entries($data['items'] ?? []) : [];
            }
        }
        $this->store($uid, $list, time());

        return $list;
    }

    /**
     * @param list<array{name:string,email:string,count:int,last_used:int}> $list
     */
    private function store(string $uid, array $list, int $now): void
    {
        $this->memo[$uid] = $list;
        ($this->cachePut)($this->cacheKey($uid), ['at' => $now, 'items' => $list]);
    }

    private function cacheKey(string $uid): string
    {
        return 'orvanta_recipients_' . sha1($uid);
    }

    /**
     * @param mixed $raw
     * @return list<array{name:string,email:string,count:int,last_used:int}>
     */
    private static function entries(mixed $raw): array
    {
        $list = [];
        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $email = self::email($item['email'] ?? '');
            if ($email === '' || isset($seen[strtolower($email)])) {
                continue;
            }
            $seen[strtolower($email)] = true;
            $list[] = [
                'name' => self::name($item['name'] ?? ''),
                'email' => $email,
                'count' => max(1, (int) ($item['count'] ?? 1)),
                'last_used' => max(0, (int) ($item['last_used'] ?? 0)),
            ];
            if (count($list) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return $list;
    }

    /**
     * @param list<array{name?:mixed,email?:mixed}|string> $recipients
     * @return list<array{name:string,email:string}>
     */
    private static function normalize(array $recipients): array
    {
        $out = [];
        $seen = [];
        foreach ($recipients as $recipient) {
            $name = '';
            $email = '';
            if (is_array($recipient)) {
                $name = self::name($recipient['name'] ?? '');
                $email = self::email($recipient['email'] ?? '');
            } elseif (is_string($recipient)) {
                $email = self::email($recipient);
            }
            if ($email === '' || isset($seen[strtolower($email)])) {
                continue;
            }
            $seen[strtolower($email)] = true;
            $out[] = ['name' => $name === $email ? '' : $name, 'email' => $email];
        }

        return $out;
    }

    private static function email(mixed $value): string
    {
        $email = trim(is_scalar($value) ? (string) $value : '', " \t\n\r<>");

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    private static function name(mixed $value): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''), " \t\"'");

        return mb_substr($name, 0, self::MAX_NAME);
    }

    /**
     * Praefix-Treffer auf Name, Namensbestandteile oder E-Mail-Adresse
     * (alle Suchwoerter muessen passen).
     */
    private static function matches(string $name, string $email, string $term): bool
    {
        $haystack = mb_strtolower($name . ' ' . $email . ' ' . str_replace(['@', '.', '_', '-'], ' ', $email));
        $words = preg_split('/\s+/u', $haystack) ?: [];
        foreach (preg_split('/\s+/u', mb_strtolower(trim($term))) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            $hit = str_starts_with(mb_strtolower($email), $token);
            foreach ($words as $word) {
                if ($hit || str_starts_with($word, $token)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }

        return true;
    }
}
