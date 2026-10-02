<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Exceptions\ValidationException;
use App\Repositories\IncidentRepository;
use App\Repositories\StorageRepository;
use App\Services\SettingsService;
use PDOException;

/**
 * Adminbereich "Vorfaelle": Sicherheitsvorfaelle des Speicher-Tierings
 * (auffaelliges Ueberschreiben, Ransomware-Endungen), Erledigung und
 * Einstellungen der Erkennung.
 *
 * Die Erkennung selbst laeuft im Container storage-sync (ThreatDetector).
 */
final class IncidentService
{
    public const RULE_LABELS = [
        'extension' => 'Ransomware-Endung',
        'content' => 'Verschlüsselt wirkender Inhalt',
        'overwrite' => 'Massenhaftes Überschreiben',
    ];

    public function __construct(
        private readonly IncidentRepository $repository,
        private readonly StorageRepository $storage,
        private readonly SettingsService $settings
    ) {
    }

    public function settings(): IncidentSettings
    {
        return new IncidentSettings($this->settings->all());
    }

    /**
     * @param array<string,mixed> $input
     *
     * @throws ValidationException
     */
    public function saveSettings(array $input): void
    {
        $result = IncidentSettings::validate($input);
        if ($result['errors'] !== []) {
            throw new ValidationException($result['errors']);
        }
        $this->settings->update($result['values']);
    }

    public function resetPatterns(): void
    {
        $this->settings->update(['incident_extensions' => implode("\n", IncidentSettings::DEFAULT_PATTERNS)]);
    }

    public function openCount(): int
    {
        try {
            return $this->repository->openCount();
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * Vorfaelle fuer die Tabelle (offene zuerst).
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $limit = 200): array
    {
        return array_map([self::class, 'present'], $this->repository->all($limit));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->repository->find($id);

        return $row === null ? null : self::present($row);
    }

    /**
     * Vorfall erledigen: hebt die Einschraenkung des Benutzers auf und gibt
     * (sobald kein weiterer Vorfall offen ist) das Schutzziel wieder zur
     * Synchronisation frei.
     *
     * @return array{resolved:bool,remaining:int,target:string,uid:string}
     */
    public function resolve(int $id, string $admin): array
    {
        $incident = $this->repository->find($id);
        if ($incident === null || !$this->repository->resolve($id, $admin)) {
            return ['resolved' => false, 'remaining' => $this->openCount(), 'target' => '', 'uid' => ''];
        }
        $remaining = $this->repository->openCount();
        $target = (string) $incident['frozen_target_label'];
        $message = sprintf('Sicherheitsvorfall Nr. %d (Benutzer „%s“) von %s als erledigt markiert – Einschränkung aufgehoben.', $id, $incident['uid'], $admin);
        if ($remaining === 0 && $target !== '') {
            $message .= ' Die Synchronisation des Speicherziels „' . $target . '“ wird fortgesetzt.';
        }
        try {
            $this->storage->addEvent('warning', 'incident', $message, $incident['frozen_target_id'] !== null ? (int) $incident['frozen_target_id'] : null);
        } catch (PDOException) {
            // Ereignisse sind nicht kritisch.
        }

        return ['resolved' => true, 'remaining' => $remaining, 'target' => $target, 'uid' => (string) $incident['uid']];
    }

    /**
     * Speicherziele fuer die Auswahl des Schutzziels.
     *
     * @return array<int,string>
     */
    public function targetOptions(): array
    {
        $options = [];
        try {
            foreach ($this->storage->targets() as $row) {
                $options[(int) $row['id']] = (string) $row['label'] . ((int) $row['active'] === 1 ? '' : ' (deaktiviert)');
            }
        } catch (PDOException) {
            return [];
        }

        return $options;
    }

    /**
     * Meldung fuer das Admin-Dashboard (null = kein offener Vorfall).
     *
     * @return array{count:int,users:list<string>,target:string,title:string,message:string}|null
     */
    public function dashboardAlert(): ?array
    {
        try {
            $open = $this->repository->open();
        } catch (PDOException) {
            return null;
        }
        if ($open === []) {
            return null;
        }
        $users = array_values(array_unique(array_map(static fn (array $i): string => (string) $i['uid'], $open)));
        $target = '';
        foreach ($open as $incident) {
            if ((string) $incident['frozen_target_label'] !== '') {
                $target = (string) $incident['frozen_target_label'];
                break;
            }
        }
        $count = count($open);

        return [
            'count' => $count,
            'users' => $users,
            'target' => $target,
            'title' => $count === 1 ? 'Sicherheitsvorfall: auffälliges Überschreiben von Dateien' : $count . ' offene Sicherheitsvorfälle: auffälliges Überschreiben von Dateien',
            'message' => sprintf(
                'Möglicher Ransomware-Befall in Nextcloud. Betroffene Benutzer (%s) dürfen bis zur Erledigung nur noch lesen. %s',
                implode(', ', $users),
                $target !== ''
                    ? 'Das Speicherziel „' . $target . '“ des Cold-Tiers ist schreibgeschützt und wird nicht synchronisiert – dort bleibt der Datenbestand von vor dem Vorfall erhalten.'
                    : 'Es konnte kein Speicherziel des Cold-Tiers geschützt werden (kein aktives Ziel).'
            ),
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function present(array $row): array
    {
        $details = json_decode((string) ($row['details'] ?? ''), true);
        $details = is_array($details) ? $details : [];
        $rules = array_values(array_filter(explode(',', (string) $row['rules'])));

        return array_merge($row, [
            'id' => (int) $row['id'],
            'open' => $row['status'] === IncidentRepository::STATUS_OPEN,
            'rule_list' => $rules,
            'rule_labels' => array_map(static fn (string $r): string => self::RULE_LABELS[$r] ?? $r, $rules),
            'samples' => is_array($details['samples'] ?? null) ? $details['samples'] : [],
            'patterns' => is_array($details['patterns'] ?? null) ? $details['patterns'] : [],
            'reasons' => is_array($details['reasons'] ?? null) ? $details['reasons'] : [],
            'owners' => is_array($details['owners'] ?? null) ? $details['owners'] : [],
            'clients' => is_array($details['clients'] ?? null) ? $details['clients'] : [],
        ]);
    }
}
