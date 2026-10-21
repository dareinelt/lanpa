<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EmployeeRepository;
use App\Services\Ad\AdUserMapper;
use App\Services\Lanpa\LanpaBridge;
use PDOException;

/**
 * Personenauswahl fuer Melder- und Betroffenenfelder.
 *
 * Grundlage ist das Telefonbuch des Intranets (alle Identitaetsquellen), damit
 * die Auswahlfelder den gesamten Personenbestand abbilden und nicht nur die
 * nach KLINQ synchronisierten Konten.
 *
 * Tickets und Ausgaben verweisen auf lokale Mitarbeiter. Jeder Treffer wird
 * deshalb auf einen lokalen Datensatz abgebildet. Unbekannte Personen werden
 * erst beim Auswaehlen uebernommen – nicht schon beim Tippen –, damit die Suche
 * den Mitarbeiterbestand nicht fuellt.
 */
final class PersonDirectoryService
{
    public const LIMIT = 30;

    public function __construct(
        private readonly LanpaBridge $lanpa,
        private readonly EmployeeRepository $employees,
        private readonly EmployeeService $local,
        private readonly AuditLogService $audit
    ) {}

    /**
     * Treffer fuer die Auswahlfelder.
     *
     * `id` ist die lokale Mitarbeiter-ID; bei noch nicht uebernommenen Personen
     * ist sie 0 und `ref` nennt die Telefonbuch-ID, die der Client beim
     * Auswaehlen an die Uebernahme schickt.
     *
     * @return list<array{id:int,name:string,meta:string,ref:int}>
     */
    public function search(string $term, int $limit = self::LIMIT): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));

        $items = [];
        foreach ($this->lanpa->phonebook($term, $limit) ?? [] as $entry) {
            $id = $this->resolve($entry);
            $item = [
                'id' => $id ?? 0,
                'name' => $entry['display_name'],
                'meta' => self::meta($entry['department'], $entry['source'], $entry['username']),
                'ref' => $id === null ? $entry['id'] : 0,
            ];
            $items[$id === null ? 'ref:' . $entry['id'] : (string) $id] = $item;
        }

        // Lokale Treffer ergaenzen: Personen, die nicht im Telefonbuch stehen,
        // und phonetische Naeherungen fuer abweichende Schreibweisen.
        foreach ($this->local->searchAutocomplete($term, $limit) as $row) {
            $id = (int) $row['id'];
            if (isset($items[(string) $id])) {
                continue;
            }
            $items[(string) $id] = [
                'id' => $id,
                'name' => (string) $row['display_name'],
                'meta' => self::meta(
                    (string) ($row['department'] ?? ''),
                    (string) ($row['personnel_number'] ?? ''),
                    (string) ($row['username'] ?? '')
                ),
                'ref' => 0,
            ];
        }

        return array_slice(array_values($items), 0, $limit);
    }

    /**
     * Uebernimmt eine Person aus dem Telefonbuch als lokalen Mitarbeiter und
     * liefert dessen ID. Die Stammdaten kommen aus dem Intranet, nicht aus der
     * Anfrage; bekannte Personen werden nur aufgeloest.
     */
    public function adopt(int $phonebookId): ?int
    {
        $entry = $this->lanpa->phonebookEntry($phonebookId);
        if ($entry === null) {
            return null;
        }
        $existing = $this->resolve($entry);
        if ($existing !== null) {
            return $existing;
        }

        $data = [
            // Die objectGUID sorgt dafuer, dass der spaetere AD-Abgleich diesen
            // Datensatz wiedererkennt und auf source='ad' umstellt.
            'ad_object_guid' => AdUserMapper::formatGuid($entry['external_id']),
            'username' => self::cut($entry['username'], 120),
            'first_name' => $entry['first_name'],
            'last_name' => $entry['last_name'],
            'display_name' => $entry['display_name'],
            'email' => self::cut($entry['email'], 190),
            'department' => self::cut($entry['department'], 150),
            'position' => self::cut($entry['title'], 150),
            'phone' => self::cut($entry['phone'] !== '' ? $entry['phone'] : $entry['mobile'], 60),
            'source' => 'manual',
            'is_active' => 1,
        ];

        // Die Personalnummer kommt aus dem Intranet und wird nur uebernommen,
        // wenn sie dort gepflegt ist; sie ist in KLINQ auf 50 Zeichen begrenzt.
        $personnelNumber = self::cut($entry['personnel_number'] ?? '', 50);
        if ($personnelNumber !== '') {
            $data['personnel_number'] = $personnelNumber;
        }

        try {
            $id = $this->employees->create($data);
        } catch (PDOException) {
            // Gleichzeitige Auswahl derselben Person: der zweite Lauf findet sie nun.
            return $this->resolve($entry);
        }

        $this->audit->log('create', 'employee', $id, $entry['display_name'], null, [
            'display_name' => $entry['display_name'],
            'username' => $data['username'],
            'department' => $data['department'],
            'source' => 'telefonbuch',
        ]);

        return $id;
    }

    /**
     * Lokale Mitarbeiter-ID zu einem Telefonbucheintrag.
     *
     * Reihenfolge: objectGUID (stabil), E-Mail, Benutzername. Der Benutzername
     * trifft auch manuell gepflegte Datensaetze; die uebernimmt der AD-Abgleich
     * spaeter, sobald die Person im Verzeichnis auftaucht.
     *
     * @param array{external_id:string,username:string,email:string} $entry
     */
    private function resolve(array $entry): ?int
    {
        $guid = AdUserMapper::formatGuid($entry['external_id']);
        if ($guid !== null) {
            $employee = $this->employees->findByGuid($guid);
            if ($employee !== null) {
                return (int) $employee['id'];
            }
        }
        if ($entry['email'] !== '') {
            $employee = $this->employees->findByEmail($entry['email']);
            if ($employee !== null) {
                return (int) $employee['id'];
            }
        }
        if ($entry['username'] !== '') {
            $employee = $this->employees->findByUsername($entry['username']);
            if ($employee !== null) {
                return (int) $employee['id'];
            }
        }

        return null;
    }

    private static function meta(string $department, string $source, string $username): string
    {
        return implode(' · ', array_filter([$department, $source, $username], static fn (string $v): bool => $v !== ''));
    }

    private static function cut(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
