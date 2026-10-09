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
 * Welche Postfaecher ein Benutzer ausser seinem eigenen nutzen darf, ist auf
 * dem Exchange-Server in den Postfachberechtigungen hinterlegt und ueber EWS
 * nicht abfragbar. Der Adminbereich pflegt die Zuordnung daher je Benutzer;
 * Orvanta prueft sie ueber EWS als der Benutzer (Impersonation seines
 * Postfachs, Zugriff auf das zusaetzliche Postfach) und zeigt nur Postfaecher
 * an, fuer die Exchange dem Benutzer Vollzugriff gewaehrt. "Senden als"
 * erzwingt Exchange beim Versand: Orvanta sendet immer als der Benutzer.
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
        private readonly ?Logger $logger = null
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
     * Benutzer fuer die Auswahl im Adminbereich (AD-Bestand der Telefonliste).
     *
     * @return list<array{uid:string,username:string,display_name:string,email:string,source:string}>
     */
    public function searchUsers(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return $this->repository->searchUsers($term);
    }

    /**
     * Zuordnung anlegen oder aktualisieren und anschliessend pruefen.
     *
     * @param array{send_as?:bool,active?:bool,sort_order?:int} $options
     * @return array{id:int,ok:bool,error:string}
     */
    public function save(string $uid, string $email, string $displayName = '', array $options = []): array
    {
        $uid = trim($uid);
        $email = strtolower(trim($email));
        if ($uid === '' || mb_strlen($uid) > 190) {
            throw new \InvalidArgumentException('Bitte den Benutzer angeben (Office-Kennung, z. B. mueller oder mueller@ZWEIG).');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new \InvalidArgumentException('Bitte eine gültige E-Mail-Adresse des zusätzlichen Postfachs angeben.');
        }
        if (mb_strlen($displayName) > 190) {
            throw new \InvalidArgumentException('Der Anzeigename ist zu lang (höchstens 190 Zeichen).');
        }

        $id = $this->repository->save([
            'uid' => $uid,
            'email' => $email,
            'display_name' => trim($displayName),
            'send_as' => $options['send_as'] ?? true,
            'active' => $options['active'] ?? true,
            'sort_order' => max(0, (int) ($options['sort_order'] ?? 1)),
        ]);
        $error = $this->verify($id);

        return ['id' => $id, 'ok' => $error === '', 'error' => $error];
    }

    public function delete(int $id): void
    {
        $this->repository->delete($id);
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
     * Alle faelligen Zuordnungen eines Benutzers pruefen (hoechstens eine
     * EWS-Anfrage je Postfach und Gueltigkeitsdauer).
     *
     * @param array<string,mixed> $ssoUser
     * @param string $userAddress Postfachadresse des Benutzers (Impersonation)
     */
    public function refresh(array $ssoUser, string $userAddress = ''): void
    {
        foreach ($this->repository->forUser($this->uid($ssoUser)) as $row) {
            if ($this->isStale($row)) {
                $this->verify($row['id'], false, $userAddress);
            }
        }
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
