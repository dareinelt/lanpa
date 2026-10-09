<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Core\Logger;
use App\Repositories\OrvantaSharedMailboxRepository;
use Throwable;

/**
 * Zusaetzlich berechtigte Postfaecher eines Benutzers (Vollzugriff /
 * "Senden als").
 *
 * Welche Postfaecher ein Benutzer ausser seinem eigenen nutzen darf, pflegt
 * der Exchange-Administrator im ECP (Postfachberechtigung Vollzugriff). Ueber
 * EWS ist das nicht abfragbar, wohl aber im AD: Exchange traegt den Benutzer
 * mit Auto-Mapping am Postfach ein, der Rueckverweis msExchDelegateListBL am
 * Benutzer nennt seine Postfaecher – derselbe Weg, ueber den Outlook sie
 * automatisch einbindet. Orvanta liest ihn beim Oeffnen der App
 * (OrvantaDelegateDirectory) und uebernimmt die Liste eins zu eins in
 * orvanta_shared_mailboxes; eine manuelle Zuordnung gibt es nicht, der
 * Adminbereich zeigt den Stand nur an und stoesst Pruefungen an. Jede
 * Zuordnung prueft Orvanta ueber EWS als der Benutzer (Impersonation seines
 * Postfachs, Zugriff auf das zusaetzliche Postfach) und zeigt nur Postfaecher
 * an, fuer die Exchange dem Benutzer Vollzugriff gewaehrt. "Senden als"
 * erzwingt Exchange beim Versand.
 *
 * Wichtig: Archiviert wird ausschliesslich das primaere Benutzerpostfach.
 * Zusaetzliche Postfaecher laufen nicht in die Archivierung ein.
 */
final class OrvantaSharedMailboxService
{
    /** Gueltigkeit der EWS-Pruefung, danach wird erneut geprueft (Sekunden). */
    private const VERIFY_TTL = 43200;

    /** Zustand ohne Postfachadresse des Benutzers: Pruefung bei der naechsten Anmeldung. */
    public const PENDING = 'Die Berechtigung wird bei der nächsten Anmeldung des Benutzers in Orvanta geprüft (keine Postfachadresse im Telefonbuch).';

    public function __construct(
        private readonly OrvantaSharedMailboxRepository $repository,
        private readonly OrvantaExchangeService $exchange,
        private readonly ?Logger $logger = null,
        private readonly ?OrvantaDelegateDirectory $directory = null
    ) {
    }

    /**
     * Postfaecher, die dem Benutzer im Ordnerbaum zur Verfuegung stehen – nur
     * aktive und ueber EWS bestaetigte Zuordnungen.
     *
     * @param array<string,mixed> $ssoUser
     * @return list<array{id:int,email:string,name:string,send_as:bool}>
     */
    public function available(array $ssoUser): array
    {
        $uid = $this->uid($ssoUser);
        if ($uid === '') {
            return [];
        }

        $mailboxes = [];
        foreach ($this->repository->forUser($uid) as $row) {
            if (!$this->isVerified($row)) {
                continue;
            }
            $mailboxes[] = [
                'id' => $row['id'],
                'email' => $row['email'],
                'name' => $row['display_name'] !== '' ? $row['display_name'] : $row['email'],
                'send_as' => $row['send_as'],
            ];
        }

        return $mailboxes;
    }

    /**
     * Kalender, die der Benutzer im Kalender ausgeblendet hat (Checkboxen).
     *
     * @param array<string,mixed> $ssoUser
     * @return array{hidden:list<string>,visible:list<string>}
     */
    public function calendarSelection(array $ssoUser): array
    {
        $hidden = [];
        $visible = [];
        foreach ($this->repository->forUser($this->uid($ssoUser)) as $row) {
            if (!$this->isVerified($row)) {
                continue;
            }
            if ($row['calendar_visible']) {
                $visible[] = (string) $row['id'];
            } else {
                $hidden[] = (string) $row['id'];
            }
        }

        return ['hidden' => $hidden, 'visible' => $visible];
    }

    /**
     * Checkbox im Kalender umschalten.
     *
     * @param array<string,mixed> $ssoUser
     */
    public function setCalendarVisible(array $ssoUser, int $id, bool $visible): bool
    {
        $row = $this->repository->find($id);
        if ($row === null || strcasecmp($row['uid'], $this->uid($ssoUser)) !== 0) {
            return false;
        }
        $this->repository->setCalendarVisible($id, $visible);

        return true;
    }

    /**
     * Zuordnung des Benutzers aufloesen; nur aktive, bestaetigte Postfaecher
     * sind waehlbar. $requested ist die Postfachkennung aus der Anfrage (Id
     * oder Adresse), ein leerer Wert steht fuer das primaere Postfach.
     *
     * @param array<string,mixed> $ssoUser
     * @return array{id:int,email:string,name:string,send_as:bool}|null null = nicht erlaubt
     */
    public function resolve(array $ssoUser, string $requested): ?array
    {
        $requested = trim($requested);
        if ($requested === '') {
            return null;
        }
        foreach ($this->available($ssoUser) as $mailbox) {
            if ((string) $mailbox['id'] === $requested || strcasecmp($mailbox['email'], $requested) === 0) {
                return $mailbox;
            }
        }

        return null;
    }

    /**
     * Absenderadresse pruefen: entweder das primaere Postfach (leere Adresse)
     * oder ein zusaetzliches Postfach mit "Senden als".
     *
     * @param array<string,mixed> $ssoUser
     * @return array{email:string,name:string}|null null = nicht erlaubt
     */
    public function sender(array $ssoUser, string $primary, string $address): ?array
    {
        $address = trim($address);
        if ($address === '' || strcasecmp($address, trim($primary)) === 0) {
            return ['email' => '', 'name' => ''];
        }
        foreach ($this->available($ssoUser) as $mailbox) {
            if (strcasecmp($mailbox['email'], $address) === 0) {
                return $mailbox['send_as']
                    ? ['email' => $mailbox['email'], 'name' => $mailbox['name']]
                    : null;
            }
        }

        return null;
    }

    /**
     * Zuordnungen fuer den Adminbereich; ohne Kennung alle Benutzer.
     *
     * @return list<array{id:int,uid:string,email:string,name:string,send_as:bool,active:bool,sort_order:int,calendar_visible:bool,verified:bool,error:string,checked_at:string}>
     */
    public function forAdmin(string $uid): array
    {
        $list = [];
        foreach ($this->repository->all(trim($uid)) as $row) {
            $list[] = [
                'id' => $row['id'],
                'uid' => $row['uid'],
                'email' => $row['email'],
                'name' => $row['display_name'],
                'send_as' => $row['send_as'],
                'active' => $row['active'],
                'sort_order' => $row['sort_order'],
                'calendar_visible' => $row['calendar_visible'],
                'verified' => $this->isVerified($row),
                'error' => $row['verify_error'],
                'checked_at' => $row['checked_at'],
            ];
        }

        return $list;
    }

    /**
     * Berechtigung des Benutzers auf ein Postfach pruefen und Ergebnis
     * festhalten. $userAddress ist die Postfachadresse des Benutzers (bei der
     * Anmeldung bekannt); ohne sie wird die Adresse aus dem Telefonbuch
     * genommen. Fehlt auch diese, bleibt die Zuordnung bis zur naechsten
     * Anmeldung des Benutzers ungeprueft (und damit nicht sichtbar).
     *
     * @return string Fehlertext, leer = erreichbar
     */
    public function verify(int $id, bool $force = false, string $userAddress = ''): string
    {
        $row = $this->repository->find($id);
        if ($row === null) {
            return 'Zuordnung nicht gefunden.';
        }
        if (!$force && !$this->isStale($row)) {
            return $row['verify_error'];
        }

        $userAddress = trim($userAddress);
        if ($userAddress === '') {
            $userAddress = $this->repository->userAddress($row['uid']);
        }
        if ($userAddress === '') {
            $this->repository->markPending($id, self::PENDING);

            return self::PENDING;
        }

        $error = '';
        try {
            $result = $this->exchange->probeMailbox($row['email'], $userAddress);
            $error = $result['ok'] ? '' : $result['error'];
        } catch (Throwable $exception) {
            $error = 'Pruefung fehlgeschlagen: ' . $exception->getMessage();
        }
        $this->repository->markVerified($id, $error);
        if ($error !== '') {
            $this->logger?->info('Orvanta: zusaetzliches Postfach nicht erreichbar.', ['mailbox' => $row['email'], 'user' => $userAddress, 'error' => $error]);
        }

        return $error;
    }

    /**
     * Zuordnungen eines Benutzers mit dem AD abgleichen (Auto-Mapping) und
     * alle faelligen Zuordnungen pruefen (hoechstens eine EWS-Anfrage je
     * Postfach und Gueltigkeitsdauer).
     *
     * @param array<string,mixed> $ssoUser
     * @param string $userAddress Postfachadresse des Benutzers (Impersonation)
     */
    public function refresh(array $ssoUser, string $userAddress = ''): void
    {
        $this->syncFromDirectory($ssoUser);
        foreach ($this->repository->forUser($this->uid($ssoUser)) as $row) {
            if ($this->isStale($row)) {
                $this->verify($row['id'], false, $userAddress);
            }
        }
    }

    /**
     * Im AD automatisch eingebundene Postfaecher (msExchDelegateListBL) in die
     * Zuordnungen uebernehmen; neue Postfaecher gelten als ungeprueft und
     * werden anschliessend in refresh() ueber EWS bestaetigt. Ohne Ergebnis
     * aus dem AD bleibt der Bestand unveraendert.
     *
     * @param array<string,mixed> $ssoUser
     * @return bool true, wenn sich der Bestand geaendert hat
     */
    public function syncFromDirectory(array $ssoUser): bool
    {
        $uid = $this->uid($ssoUser);
        if ($uid === '' || $this->directory === null) {
            return false;
        }
        $mailboxes = $this->directory->mailboxes($ssoUser);
        if ($mailboxes === null) {
            return false;
        }
        try {
            $changed = $this->repository->syncDiscovered($uid, $mailboxes);
        } catch (Throwable $exception) {
            $this->logger?->warning('Orvanta: Abgleich der automatisch eingebundenen Postfaecher fehlgeschlagen.', ['user' => $uid, 'error' => $exception->getMessage()]);

            return false;
        }
        if ($changed) {
            $this->logger?->info('Orvanta: automatisch eingebundene Postfaecher aus dem AD uebernommen.', [
                'user' => $uid,
                'mailboxes' => array_map(static fn (array $mailbox): string => $mailbox['email'], $mailboxes),
            ]);
        }

        return $changed;
    }

    /**
     * Office-Kennung des Benutzers, wie sie der Adminbereich pflegt.
     *
     * @param array<string,mixed> $ssoUser
     */
    public function uid(array $ssoUser): string
    {
        $uid = trim((string) ($ssoUser['office_uid'] ?? ''));
        if ($uid !== '') {
            return $uid;
        }
        $username = trim((string) ($ssoUser['username'] ?? ''));
        if ($username === '') {
            return '';
        }
        $source = strtolower(trim((string) ($ssoUser['source_key'] ?? '')));

        return $source !== '' ? $username . '@' . $source : $username;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isVerified(array $row): bool
    {
        return !empty($row['verified']);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isStale(array $row): bool
    {
        $checkedAt = trim((string) ($row['checked_at'] ?? ''));
        if ($checkedAt === '') {
            return true;
        }
        $time = strtotime($checkedAt);

        return $time === false || $time < time() - self::VERIFY_TTL;
    }
}
