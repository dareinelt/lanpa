<?php

declare(strict_types=1);

namespace App\Services\MailProxy;

/**
 * Ergebnis der Postfach-Aufloesung fuer einen Benutzer:
 *
 *  - exchange: keine (verwendbare) Zuordnung -> bestehendes Exchange-Verhalten
 *  - proxy:    Postfach ueber den SMTP-/IMAP-Proxy
 *  - blocked:  Zuordnung vorhanden, Postfach deaktiviert -> keine Verbindung,
 *              aber auch kein stiller Rueckfall auf ein anderes Postfach
 *
 * Enthaelt nur Kennungen, keine Zugangsdaten (wird gecacht).
 */
final class MailProxyRoute
{
    public const EXCHANGE = 'exchange';
    public const PROXY = 'proxy';
    public const BLOCKED = 'blocked';

    private function __construct(
        public readonly string $state,
        public readonly int $mailboxId = 0,
        public readonly int $serverId = 0,
        public readonly int $sourceId = 0,
        public readonly string $email = '',
        public readonly string $reason = ''
    ) {
    }

    public static function exchange(string $reason = ''): self
    {
        return new self(self::EXCHANGE, reason: $reason);
    }

    public static function proxy(int $mailboxId, int $serverId, int $sourceId, string $email): self
    {
        return new self(self::PROXY, $mailboxId, $serverId, $sourceId, $email);
    }

    public static function blocked(int $mailboxId, int $sourceId, string $reason): self
    {
        return new self(self::BLOCKED, $mailboxId, 0, $sourceId, '', $reason);
    }

    public function isProxy(): bool
    {
        return $this->state === self::PROXY;
    }

    public function isBlocked(): bool
    {
        return $this->state === self::BLOCKED;
    }

    /**
     * @return array{state:string,mailbox_id:int,server_id:int,source_id:int,email:string,reason:string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'mailbox_id' => $this->mailboxId,
            'server_id' => $this->serverId,
            'source_id' => $this->sourceId,
            'email' => $this->email,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $state = (string) ($data['state'] ?? '');
        if (!in_array($state, [self::EXCHANGE, self::PROXY, self::BLOCKED], true)) {
            return null;
        }

        return new self(
            $state,
            (int) ($data['mailbox_id'] ?? 0),
            (int) ($data['server_id'] ?? 0),
            (int) ($data['source_id'] ?? 0),
            (string) ($data['email'] ?? ''),
            (string) ($data['reason'] ?? '')
        );
    }
}
