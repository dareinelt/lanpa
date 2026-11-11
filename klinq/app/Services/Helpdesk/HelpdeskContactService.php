<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\HelpdeskContactRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Support\FuzzySearch;
use App\Support\Paginator;

/**
 * Gemeinsames Kontaktverzeichnis des Help Desk: ein über alle Agenten geteiltes Adressbuch.
 *
 * - Lesen mit `helpdesk.view`, Anlegen/Bearbeiten/Löschen mit `helpdesk.update` (beide Rechte hat jeder Agent).
 * - Pflicht: Firma, Vorname, Name und mindestens eine Rufnummer (Telefon/Mobil) oder E-Mail-Adresse.
 * - Sortierung alphabetisch nach Firma, dann Name und Vorname.
 * - Suche über alle Felder einschließlich Stichworten mit Ähnlichkeitsfunktion ({@see FuzzySearch}):
 *   exakte Treffer und ähnliche Treffer („rp doc“/„rpdok“ → „RPDoc“) erscheinen in derselben
 *   alphabetischen Liste, ähnliche sind markiert. Die Suche läuft in PHP über das gesamte Verzeichnis –
 *   ausgelegt auf ein Adressbuch mit einigen tausend Einträgen.
 */
final class HelpdeskContactService
{
    public const PER_PAGE = 50;
    public const MAX_TAGS = 20;
    public const MAX_TAG_LENGTH = 50;

    /** Feldlängen laut Migration 039. */
    private const LIMITS = ['company' => 200, 'first_name' => 100, 'last_name' => 100, 'phone' => 50, 'mobile' => 50, 'email' => 255, 'notes' => 2000];

    public function __construct(
        private readonly HelpdeskContactRepository $contacts,
        private readonly CurrentUser $currentUser,
        private readonly AuditLogService $audit
    ) {}

    /**
     * Gefilterte, alphabetisch sortierte Seite des Verzeichnisses.
     *
     * @return array{rows:list<array<string,mixed>>,paginator:Paginator,similar:int}
     *         rows mit zusätzlichem Schlüssel `match` (exact|similar); similar = Anzahl ähnlicher Treffer
     */
    public function search(string $query, string $tag = '', int $page = 1): array
    {
        $this->currentUser->require('helpdesk.view');
        $rows = self::filter($this->contacts->all(), $query, $tag);
        $paginator = new Paginator(count($rows), $page, self::PER_PAGE);
        $similar = count(array_filter($rows, static fn (array $r): bool => $r['match'] === FuzzySearch::SIMILAR));

        return [
            'rows' => array_slice($rows, $paginator->offset(), $paginator->perPage),
            'paginator' => $paginator,
            'similar' => $similar,
        ];
    }

    /**
     * Filtert Kontakte nach Suchbegriff (alle Felder und Stichworte, mit Ähnlichkeit) und Stichwort.
     * Die Reihenfolge der Eingabe (alphabetisch aus dem Repository) bleibt erhalten.
     *
     * @param list<array<string,mixed>> $contacts
     * @return list<array<string,mixed>>
     */
    public static function filter(array $contacts, string $query, string $tag = ''): array
    {
        $tagKey = mb_strtolower(trim($tag));
        $out = [];
        foreach ($contacts as $contact) {
            $tags = array_map('strval', (array) ($contact['tags'] ?? []));
            if ($tagKey !== '' && !in_array($tagKey, array_map('mb_strtolower', $tags), true)) {
                continue;
            }
            $match = FuzzySearch::match($query, self::searchFields($contact));
            if ($match === null) {
                continue;
            }
            $contact['match'] = $match;
            $out[] = $contact;
        }

        return $out;
    }

    /** Durchsuchbare Felder eines Kontakts. @param array<string,mixed> $contact @return list<string> */
    public static function searchFields(array $contact): array
    {
        $fields = [];
        foreach (['company', 'first_name', 'last_name', 'phone', 'mobile', 'email', 'notes'] as $key) {
            $fields[] = (string) ($contact[$key] ?? '');
        }

        return array_merge($fields, array_map('strval', (array) ($contact['tags'] ?? [])));
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $this->currentUser->require('helpdesk.view');
        $contact = $this->contacts->find($id);
        if ($contact === null) {
            throw new NotFoundException('Kontakt nicht gefunden.');
        }

        return $contact;
    }

    /** Alle vergebenen Stichworte (Filter und Vorschläge). @return list<array{tag:string,count:int}> */
    public function tags(): array
    {
        return $this->contacts->tags();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> Der angelegte Kontakt */
    public function create(array $input): array
    {
        $this->currentUser->require('helpdesk.update');
        [$data, $tags] = self::validate($input);
        $id = (int) $this->contacts->transaction(function () use ($data, $tags): int {
            $id = $this->contacts->create($data + [
                'created_by' => $this->currentUser->id(),
                'created_by_name' => $this->currentUser->displayName() !== '' ? $this->currentUser->displayName() : 'system',
                'updated_by' => $this->currentUser->id(),
            ]);
            $this->contacts->replaceTags($id, $tags);

            return $id;
        });
        $this->audit->log('create', 'helpdesk_contact', $id, self::label($data), null, $data + ['tags' => implode(', ', $tags)]);

        return $this->get($id);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(int $id, array $input): array
    {
        $this->currentUser->require('helpdesk.update');
        $old = $this->get($id);
        [$data, $tags] = self::validate($input);
        $this->contacts->transaction(function () use ($id, $data, $tags): void {
            $this->contacts->update($id, $data + ['updated_by' => $this->currentUser->id()]);
            $this->contacts->replaceTags($id, $tags);
        });
        $oldValues = array_intersect_key($old, $data) + ['tags' => implode(', ', (array) $old['tags'])];
        $this->audit->log('update', 'helpdesk_contact', $id, self::label($data), $oldValues, $data + ['tags' => implode(', ', $tags)]);

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->currentUser->require('helpdesk.update');
        $contact = $this->get($id);
        $this->contacts->delete($id);
        $this->audit->log('delete', 'helpdesk_contact', $id, self::label($contact));
    }

    /**
     * Prüft und bereinigt die Eingaben eines Kontakts.
     *
     * @param array<string,mixed> $input
     * @return array{0:array<string,string|null>,1:list<string>} [Spaltenwerte, Stichworte]
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $data = [];
        $labels = ['company' => 'Firma', 'first_name' => 'Vorname', 'last_name' => 'Name', 'phone' => 'Telefon', 'mobile' => 'Mobil', 'email' => 'E-Mail', 'notes' => 'Bemerkung'];
        foreach (self::LIMITS as $key => $max) {
            $value = $key === 'notes'
                ? trim(str_replace(["\r\n", "\r"], "\n", (string) ($input[$key] ?? '')))
                : trim((string) preg_replace('/\s+/u', ' ', (string) ($input[$key] ?? '')));
            if (mb_strlen($value) > $max) {
                $errors[$key] = $labels[$key] . ' darf höchstens ' . $max . ' Zeichen lang sein.';
            }
            $data[$key] = $value === '' ? null : $value;
        }
        foreach (['company', 'first_name', 'last_name'] as $key) {
            if ($data[$key] === null) {
                $errors[$key] = 'Bitte ' . ($key === 'company' ? 'die Firma' : ($key === 'first_name' ? 'den Vornamen' : 'den Namen')) . ' angeben.';
            }
        }
        foreach (['phone', 'mobile'] as $key) {
            if ($data[$key] !== null && !isset($errors[$key]) && !self::isPhoneNumber($data[$key])) {
                $errors[$key] = $labels[$key] . ': bitte eine gültige Rufnummer angeben (Ziffern, Leerzeichen, + / - ( )).';
            }
        }
        if ($data['email'] !== null && !isset($errors['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'E-Mail ist keine gültige E-Mail-Adresse.';
        }
        if ($data['phone'] === null && $data['mobile'] === null && $data['email'] === null) {
            // Eigener Schlüssel: die Meldung gehört zur Gruppe „Erreichbarkeit“, nicht zu einem Einzelfeld
            $errors['reach'] = 'Bitte mindestens eine Rufnummer oder eine E-Mail-Adresse angeben.';
        }

        $tags = [];
        try {
            $tags = self::parseTags($input['tags'] ?? '');
        } catch (\InvalidArgumentException $e) {
            $errors['tags'] = $e->getMessage();
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$data, $tags];
    }

    /**
     * Zerlegt die Stichwort-Eingabe (Komma/Semikolon getrennt oder Liste), entfernt „#“, Doppelungen
     * (ohne Groß-/Kleinschreibung) und Leereinträge; die erste Schreibweise bleibt erhalten.
     *
     * @param string|list<string>|mixed $raw
     * @return list<string>
     * @throws \InvalidArgumentException bei zu langen oder zu vielen Stichworten
     */
    public static function parseTags(mixed $raw): array
    {
        $parts = is_array($raw) ? array_map('strval', $raw) : preg_split('/[,;]/', (string) $raw);
        $tags = [];
        foreach ($parts ?: [] as $part) {
            $tag = trim((string) preg_replace('/\s+/u', ' ', ltrim(trim($part), '#')));
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag) > self::MAX_TAG_LENGTH) {
                throw new \InvalidArgumentException('Ein Stichwort darf höchstens ' . self::MAX_TAG_LENGTH . ' Zeichen lang sein.');
            }
            $tags[mb_strtolower($tag)] ??= $tag;
        }
        if (count($tags) > self::MAX_TAGS) {
            throw new \InvalidArgumentException('Höchstens ' . self::MAX_TAGS . ' Stichworte je Kontakt.');
        }

        return array_values($tags);
    }

    /** Rufnummer: erlaubte Zeichen und mindestens drei Ziffern. */
    public static function isPhoneNumber(string $value): bool
    {
        return preg_match('~^\+?[0-9 ()/.\-]+$~', $value) === 1 && preg_match_all('/\d/', $value) >= 3;
    }

    /** Anzeigename „Vorname Name (Firma)“. @param array<string,mixed> $contact */
    public static function label(array $contact): string
    {
        $name = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));

        return $name . (!empty($contact['company']) ? ' (' . $contact['company'] . ')' : '');
    }

    /** Formularwerte eines Kontakts (Stichworte kommagetrennt). @param array<string,mixed>|null $contact @return array<string,string> */
    public static function formValues(?array $contact): array
    {
        $values = [];
        foreach (array_keys(self::LIMITS) as $key) {
            $values[$key] = (string) ($contact[$key] ?? '');
        }
        $values['tags'] = implode(', ', (array) ($contact['tags'] ?? []));

        return $values;
    }
}
