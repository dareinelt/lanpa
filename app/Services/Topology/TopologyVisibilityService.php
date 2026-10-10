<?php

declare(strict_types=1);

namespace App\Services\Topology;

use App\Repositories\AdminUserPreferenceRepository;
use App\Services\SettingsService;

/**
 * Im Entwurfsmodus ausgeblendete Bausteine der Topologie-Ansicht.
 *
 * Es gibt zwei Ablagen:
 *
 *   global    Eintrag in der Tabelle settings (Schluessel topology_hidden).
 *             Gilt fuer alle Betrachter und wird im Entwurfsmodus als
 *             "globale Einstellung" gespeichert.
 *   personal  Eintrag in der Tabelle admin_user_preferences, gilt nur fuer ein
 *             Konto.
 *
 * Ein persoenlicher Eintrag hat Vorrang vor dem globalen: er ist eine
 * vollstaendige Auswahl, kein Zusatz. Fehlt er, gilt der globale Eintrag;
 * fehlt auch der, ist nichts ausgeblendet (source = default).
 *
 * Die Auswahl wirkt ausschliesslich auf die Anzeige. Der Graph selbst bleibt
 * vollstaendig (siehe docs/admin-topologie-referenz.md): Kennzahlen,
 * Stoerungen und Luecken zaehlen weiterhin alle Bausteine.
 */
final class TopologyVisibilityService
{
    /** Schluessel in der Tabelle settings (globale Einstellung). */
    public const GLOBAL_KEY = 'topology_hidden';

    /** Schluessel in der Tabelle admin_user_preferences (persoenlich). */
    public const PERSONAL_KEY = 'topology_hidden';

    /** Auswahl stammt aus der globalen Einstellung. */
    public const SOURCE_GLOBAL = 'global';

    /** Auswahl stammt aus der persoenlichen Einstellung. */
    public const SOURCE_PERSONAL = 'personal';

    /** Keine Einstellung vorhanden: es ist nichts ausgeblendet. */
    public const SOURCE_DEFAULT = 'default';

    /** Obergrenze der gespeicherten Kennungen je Art (Schutz vor aufgeblaehten Werten). */
    public const MAX_ENTRIES = 500;

    /** Obergrenze der Laenge einer Kennung in Byte (Passung zur Spalte VARCHAR(190)). */
    public const MAX_ID_LENGTH = 190;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AdminUserPreferenceRepository $preferences
    ) {
    }

    /**
     * Wirksame Auswahl fuer einen Betrachter. Ohne Kontonamen (z. B. wenn die
     * Anmeldung nicht ermittelbar ist) gilt nur der globale Eintrag.
     *
     * @return array{source:string,source_label:string,nodes:list<string>,groups:list<string>}
     */
    public function selection(?string $username): array
    {
        $username = is_string($username) ? trim($username) : '';

        if ($username !== '') {
            $stored = $this->preferences->get($username, self::PERSONAL_KEY);
            if ($stored !== null && trim($stored) !== '') {
                return $this->describe(self::SOURCE_PERSONAL, $this->decode($stored));
            }
        }

        $global = $this->settings->get(self::GLOBAL_KEY);
        if (trim($global) !== '') {
            return $this->describe(self::SOURCE_GLOBAL, $this->decode($global));
        }

        return $this->describe(self::SOURCE_DEFAULT, ['nodes' => [], 'groups' => []]);
    }

    /**
     * Speichert die Auswahl fuer alle Betrachter.
     *
     * @param mixed $nodes
     * @param mixed $groups
     * @return array{source:string,source_label:string,nodes:list<string>,groups:list<string>}
     */
    public function saveGlobal(mixed $nodes, mixed $groups): array
    {
        $encoded = $this->encode($nodes, $groups);
        $this->settings->update([self::GLOBAL_KEY => $encoded]);

        return $this->describe(self::SOURCE_GLOBAL, $this->decode($encoded));
    }

    /**
     * Speichert die Auswahl fuer ein Konto. Ohne Kontonamen geschieht nichts.
     *
     * @param mixed $nodes
     * @param mixed $groups
     * @return array{source:string,source_label:string,nodes:list<string>,groups:list<string>}
     */
    public function savePersonal(string $username, mixed $nodes, mixed $groups): array
    {
        $username = trim($username);
        if ($username === '') {
            return $this->selection(null);
        }

        $encoded = $this->encode($nodes, $groups);
        $this->preferences->set($username, self::PERSONAL_KEY, $encoded);

        return $this->describe(self::SOURCE_PERSONAL, $this->decode($encoded));
    }

    /**
     * Entfernt die persoenliche Auswahl; danach gilt wieder der globale
     * Eintrag.
     *
     * @return array{source:string,source_label:string,nodes:list<string>,groups:list<string>}
     */
    public function clearPersonal(string $username): array
    {
        $username = trim($username);
        if ($username !== '') {
            $this->preferences->delete($username, self::PERSONAL_KEY);
        }

        return $this->selection($username === '' ? null : $username);
    }

    /**
     * Kennungen pruefen: nur Zeichenketten, ohne Leerraum, ohne Doppelte, in
     * stabiler Reihenfolge und begrenzter Anzahl. Erwartet wird eine Liste
     * (so kommen die Formularfelder und der gespeicherte Wert an); alles andere
     * gilt als leere Auswahl. Unbekannte Kennungen werden nicht verworfen - sie
     * koennen zu einem Baustein gehoeren, der gerade nicht messbar ist.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function normalizeIds(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $unique = [];
        foreach ($raw as $value) {
            if (!is_string($value) && !is_int($value)) {
                continue;
            }

            $id = trim((string) $value);
            if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
                continue;
            }

            $unique[$id] = true;
        }

        $ids = array_map('strval', array_keys($unique));
        sort($ids, SORT_STRING);

        return array_slice($ids, 0, self::MAX_ENTRIES);
    }

    /** Bezeichnung der Herkunft fuer die Anzeige. */
    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            self::SOURCE_PERSONAL => 'Persönliche Einstellung',
            self::SOURCE_GLOBAL => 'Globale Einstellung',
            default => 'Keine Auswahl gespeichert',
        };
    }

    /**
     * @param mixed $nodes
     * @param mixed $groups
     */
    private function encode(mixed $nodes, mixed $groups): string
    {
        $json = json_encode(
            ['nodes' => self::normalizeIds($nodes), 'groups' => self::normalizeIds($groups)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($json) ? $json : '{"nodes":[],"groups":[]}';
    }

    /**
     * @return array{nodes:list<string>,groups:list<string>}
     */
    private function decode(string $stored): array
    {
        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return ['nodes' => [], 'groups' => []];
        }

        return [
            'nodes' => self::normalizeIds($decoded['nodes'] ?? []),
            'groups' => self::normalizeIds($decoded['groups'] ?? []),
        ];
    }

    /**
     * @param array{nodes:list<string>,groups:list<string>} $selection
     * @return array{source:string,source_label:string,nodes:list<string>,groups:list<string>}
     */
    private function describe(string $source, array $selection): array
    {
        return [
            'source' => $source,
            'source_label' => self::sourceLabel($source),
            'nodes' => $selection['nodes'],
            'groups' => $selection['groups'],
        ];
    }
}
