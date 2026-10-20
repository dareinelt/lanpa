<?php

declare(strict_types=1);

namespace App\Services\Klinq;

use App\Repositories\PhonebookRepository;

/**
 * Kontaktdaten der angemeldeten Person fuer KLINQ.
 *
 * Der auth-Container ermittelt die Windows-Anmeldung; die Kontaktdaten stammen
 * aus dem Telefonbuch (AD-Synchronisation des Intranets). Sie werden beim
 * Einstieg in KLINQ mitgegeben, damit der Help-Desk-Schnellbericht ohne
 * zusaetzliche Eingaben vollstaendig gefuellt ist.
 */
final class KlinqIdentityService
{
    public function __construct(private readonly PhonebookRepository $phonebook)
    {
    }

    /**
     * @param array<string,mixed> $ssoUser
     * @return array{
     *     username:string,
     *     display_name:string,
     *     first_name:string,
     *     last_name:string,
     *     email:string,
     *     phone:string,
     *     mobile:string,
     *     department:string,
     *     title:string,
     *     source:string
     * }
     */
    public function contact(array $ssoUser): array
    {
        $username = trim((string) ($ssoUser['username'] ?? ''));
        $row = null;
        if ($username !== '') {
            try {
                $row = $this->phonebook->contactBySamAccountName($username, (int) ($ssoUser['source_id'] ?? 0));
            } catch (\Throwable) {
                // Ohne Datenbank bleibt die Anmeldung aus der SSO-Sitzung.
            }
        }

        return [
            'username' => $username,
            'display_name' => $this->pick($row['display_name'] ?? null, $ssoUser['display_name'] ?? null, $username),
            'first_name' => $this->pick($row['first_name'] ?? null),
            'last_name' => $this->pick($row['last_name'] ?? null),
            'email' => $this->pick($row['email'] ?? null, $ssoUser['email'] ?? null),
            'phone' => $this->pick($row['phone'] ?? null),
            'mobile' => $this->pick($row['mobile'] ?? null),
            'department' => $this->pick($row['department'] ?? null),
            'title' => $this->pick($row['title'] ?? null),
            'source' => (string) ($ssoUser['source_key'] ?? ''),
        ];
    }

    private function pick(mixed ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
