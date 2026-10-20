<?php

declare(strict_types=1);

namespace App\Services\Lanpa;

/**
 * Melderdaten aus dem Intranet (LANPA).
 *
 * Das Intranet ermittelt beim Einstieg ueber den auth-Container Anmeldename,
 * Kontaktdaten und Rechnername und uebergibt sie mit dem signierten Schein.
 * KLINQ legt sie in der Sitzung ab und verwendet sie fuer das Help-Desk-Ticket
 * ("Gemeldet von", "Rechner", Kontaktdaten) - ohne zusaetzliche Eingabefelder.
 */
final class LanpaIdentityService
{
    private const SESSION_KEY = 'lanpa_identity';

    /**
     * Melderdaten aus dem Schein in der Sitzung ablegen.
     *
     * @param array<string,mixed> $claims
     */
    public function remember(array $claims): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'username' => self::clean($claims['sub'] ?? null, 64),
            'display_name' => self::clean($claims['name'] ?? null, 200),
            'first_name' => self::clean($claims['first_name'] ?? null, 100),
            'last_name' => self::clean($claims['last_name'] ?? null, 100),
            'email' => self::clean($claims['email'] ?? null, 255),
            'phone' => self::clean($claims['phone'] ?? null, 60),
            'mobile' => self::clean($claims['mobile'] ?? null, 60),
            'department' => self::clean($claims['department'] ?? null, 190),
            'position' => self::clean($claims['title'] ?? null, 190),
            'ip' => self::clean($claims['ip'] ?? null, 45),
            'host' => self::clean($claims['host'] ?? null, 190),
            'report_only' => filter_var($claims['report_only'] ?? false, FILTER_VALIDATE_BOOL),
            'received_at' => time(),
        ];
    }

    public function forget(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Hinterlegte Melderdaten; null, wenn der Besucher nicht ueber das Intranet kam.
     *
     * @return array{username:?string,display_name:?string,first_name:?string,last_name:?string,
     *               email:?string,phone:?string,mobile:?string,department:?string,position:?string,
     *               ip:?string,host:?string,report_only:bool,received_at:int}|null
     */
    public function current(): ?array
    {
        $data = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($data) ? $data + ['report_only' => false, 'received_at' => 0] : null;
    }

    public function username(): ?string
    {
        return $this->current()['username'] ?? null;
    }

    private static function clean(mixed $value, int $maxLength): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
