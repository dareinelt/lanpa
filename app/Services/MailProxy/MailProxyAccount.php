<?php

declare(strict_types=1);

namespace App\Services\MailProxy;

/**
 * Entschluesselte Verbindungsdaten eines Proxy-Postfachs. Lebt nur fuer die
 * Dauer eines Requests im Speicher; das Passwort ist weder per var_dump/
 * print_r noch per JSON oder serialize() auslesbar und wird nur beim Aufbau
 * der Proxy-Anfrage (payload()) eingesetzt.
 */
final class MailProxyAccount implements \JsonSerializable
{
    public function __construct(
        public readonly int $mailboxId,
        public readonly int $serverId,
        public readonly int $sourceId,
        public readonly string $username,
        public readonly string $email,
        public readonly string $displayName,
        #[\SensitiveParameter] private readonly string $password,
        /** @var array{smtp_host:string,smtp_port:int,smtp_security:string,smtp_auth:bool,imap_host:string,imap_port:int,imap_security:string,verify_tls:bool,timeout:int} */
        public readonly array $server,
        public readonly int $generation
    ) {
    }

    /**
     * Verbindungsdaten fuer den Proxy-Dienst (inkl. Passwort, nur fuer den Transport).
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return [
            'mailbox_id' => $this->mailboxId,
            'generation' => $this->generation,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'verify_tls' => $this->server['verify_tls'],
            'timeout' => $this->server['timeout'],
            'imap' => [
                'host' => $this->server['imap_host'],
                'port' => $this->server['imap_port'],
                'security' => $this->server['imap_security'],
                'username' => $this->username,
                'password' => $this->password,
            ],
            'smtp' => [
                'host' => $this->server['smtp_host'],
                'port' => $this->server['smtp_port'],
                'security' => $this->server['smtp_security'],
                'auth' => $this->server['smtp_auth'],
                'username' => $this->username,
                'password' => $this->server['smtp_auth'] ? $this->password : '',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return ['mailboxId' => $this->mailboxId, 'serverId' => $this->serverId, 'email' => $this->email, 'password' => '***'];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string,mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Postfach-Zugangsdaten duerfen nicht serialisiert werden.');
    }
}
