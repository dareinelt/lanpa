<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\Mail\ImapMailboxClient;
use Tests\Support\FakeImapServer;
use Tests\Support\TestCase;

/** IMAP-Client gegen einen lokalen Testserver: Exchange-kompatible Suche und Fehlermarkierung. */
final class HelpdeskImapClientTest extends TestCase
{
    private const MESSAGE = "From: gast@extern.test\r\nSubject: Drucker\r\n\r\nHallo\r\n";

    private ?FakeImapServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function client(FakeImapServer $server): ImapMailboxClient
    {
        return new ImapMailboxClient([
            'host' => '127.0.0.1',
            'port' => $server->port,
            'encryption' => 'none',
            'username' => 'test',
            'password' => 'geheim',
            'mailbox' => 'INBOX',
            'timeout' => 5,
            'verify_peer' => false,
        ]);
    }

    /** @param list<string> $commands @return list<string> */
    private function startingWith(array $commands, string $prefix): array
    {
        return array_values(array_filter($commands, static fn (string $c): bool => str_starts_with(strtoupper($c), $prefix)));
    }

    public function testSearchUsesPlainUnseenWithoutKeyword(): void
    {
        $server = $this->server = new FakeImapServer(self::MESSAGE);
        $client = $this->client($server);

        $messages = $client->fetchUnprocessed(10);
        $client->close();

        $this->assertSame(['7' => self::MESSAGE], $messages);
        $searches = $this->startingWith($server->commands(), 'UID SEARCH');
        $this->assertCount(1, $searches);
        $this->assertSame('UID SEARCH UNSEEN', $searches[0], 'Exchange lehnt UNKEYWORD mit benutzerdefinierten Keywords ab');
    }

    public function testFailedMessageIsMarkedSeenEvenWhenKeywordIsRejected(): void
    {
        $server = $this->server = new FakeImapServer(self::MESSAGE, rejectKeywords: true);
        $client = $this->client($server);

        $client->markFailed('7');
        $client->close();

        $stores = $this->startingWith($server->commands(), 'UID STORE');
        $this->assertCount(2, $stores);
        $this->assertSame('UID STORE 7 +FLAGS.SILENT (\Seen)', $stores[0], '\Seen muss auch ohne Keyword-Unterstützung gesetzt werden');
        $this->assertStringContains('$HelpdeskFailed', $stores[1]);
    }
}
