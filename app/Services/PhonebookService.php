<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\PhonebookRepository;
use App\Support\Dates;

final class PhonebookService
{
    public const DEFAULT_LIMIT = 25;

    /** @var array<int,string>|null */
    private ?array $sourceLabels = null;

    public function __construct(
        private readonly PhonebookRepository $repository,
        private readonly ?IdentitySourceService $sources = null,
    ) {
    }

    /**
     * Beschriftung der Identitaetsquelle eines Eintrags; leer, solange nur
     * die Hauptquelle existiert (dann ist keine Unterscheidung noetig).
     *
     * @param array<string,mixed> $row
     */
    private function sourceLabel(array $row): string
    {
        if ($this->sourceLabels === null) {
            $this->sourceLabels = $this->sources !== null && $this->sources->hasAdditionalSources()
                ? $this->sources->labels()
                : [];
        }

        if ($this->sourceLabels === []) {
            return '';
        }

        return $this->sourceLabels[(int) ($row['identity_source_id'] ?? 0)] ?? '';
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,has_more:bool}
     */
    public function search(string $term, int $limit = self::DEFAULT_LIMIT, int $offset = 0, bool $includeWithoutEmail = false): array
    {
        $limit = max(1, min(PhonebookRepository::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $result = $this->repository->search($term, $limit, $offset, $includeWithoutEmail);

        $items = array_map(
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'source' => $this->sourceLabel($row),
                'modified' => Dates::formatDate(is_string($row['ad_modified'] ?? null) ? $row['ad_modified'] : null),
            ],
            $result['items']
        );

        return [
            'items' => $items,
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $result['total'],
        ];
    }

    public function countActive(): int
    {
        return $this->repository->countActive();
    }

    /**
     * Personenauswahl fuer KLINQ (Help-Desk-Tickets): Treffer aus dem
     * Telefonbuch ueber alle Identitaetsquellen, auch ohne E-Mail-Adresse,
     * damit auch Personen ohne Postfach als Melder erfasst werden koennen.
     *
     * Die Kennungen external_id (objectGUID als Hex) und samaccount_name
     * erlauben KLINQ, die Person einem lokalen Mitarbeiter zuzuordnen.
     *
     * @return list<array<string,mixed>>
     */
    public function directory(string $term, int $limit = self::DEFAULT_LIMIT): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $limit = max(1, min(PhonebookRepository::MAX_LIMIT, $limit));
        $result = $this->repository->search($term, $limit, 0, true);

        return array_values(array_map($this->directoryEntryFromRow(...), $result['items']));
    }

    /**
     * Einzelner Telefonbucheintrag fuer die Uebernahme in KLINQ.
     *
     * @return array<string,mixed>|null
     */
    public function directoryEntry(int $id): ?array
    {
        $row = $this->repository->findVisibleById($id);

        return $row === null ? null : $this->directoryEntryFromRow($row);
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function directoryEntryFromRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'external_id' => (string) ($row['external_id'] ?? ''),
            'username' => (string) ($row['samaccount_name'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'mobile' => (string) ($row['mobile'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'source' => $this->sourceLabel($row),
        ];
    }

    public function countVisible(bool $includeWithoutEmail = false): int
    {
        return $this->repository->countVisible($includeWithoutEmail);
    }

    public function lastSyncedAt(): ?string
    {
        return $this->repository->lastSyncedAt();
    }

    /**
     * Alle Eintraege fuer den Adminbereich (aktiv/inaktiv, ein-/ausgeblendet).
     *
     * @param array{has_email?:bool,is_active?:bool,has_phone?:bool,is_visible?:bool} $filters
     *
     * @return list<array<string,mixed>>
     */
    public function allEntries(string $term = '', array $filters = []): array
    {
        $rows = $this->repository->allForAdmin($term, $filters);

        return array_map(
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'source' => $this->sourceLabel($row),
                'active' => (int) ($row['active'] ?? 0) === 1,
                'visible' => (int) ($row['visible'] ?? 1) === 1,
                'synced_at' => is_string($row['synced_at'] ?? null) ? $row['synced_at'] : null,
            ],
            $rows
        );
    }

    public function setVisible(int $id, bool $visible): void
    {
        if (!$this->repository->setVisible($id, $visible)) {
            throw new ValidationException(['id' => 'Eintrag nicht gefunden.']);
        }
    }

    /**
     * CSV-Inhalt aller Eintraege, die auch fuer nicht angemeldete Besucher in
     * der Telefonliste sichtbar sind. Spalten: Name, Bereich, Telefonnummer,
     * Mail-Adresse. Getrennt mit Semikolon und mit UTF-8-BOM, damit die Datei
     * in deutschsprachigem Excel korrekt geoeffnet wird.
     */
    public function csvExport(): string
    {
        $rows = $this->repository->visibleForExport();

        $lines = ['Name;Bereich;Telefonnummer;Mail-Adresse'];

        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(
                $this->csvCell(...),
                [
                    (string) ($row['display_name'] ?? ''),
                    (string) ($row['department'] ?? ''),
                    (string) ($row['phone'] ?? ''),
                    (string) ($row['email'] ?? ''),
                ]
            ));
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Umschliesst einen CSV-Wert mit Anfuehrungszeichen und verdoppelt darin
     * enthaltene Anfuehrungszeichen (RFC-4180-Entsprechung).
     */
    private function csvCell(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
