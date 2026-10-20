<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\Mail\ImapMailboxClient;
use App\Support\Secret;
use Tests\Support\TestCase;

/**
 * Bausteine der in der Anwendung gepflegten Postfachkonfiguration: verschlüsselte Ablage des
 * Postfachpassworts und die IMAP-Kodierung von Verzeichnisnamen (modifiziertes UTF-7, RFC 3501).
 */
final class HelpdeskMailboxConfigTest extends TestCase
{
    // ------------------------------------------------------------------ Passwortablage

    public function testEncryptedPasswordRoundtrip(): void
    {
        $secret = new Secret('app-key-1');
        $stored = $secret->encrypt('geheim!123');

        $this->assertTrue(Secret::isEncrypted($stored));
        $this->assertFalse(str_contains($stored, 'geheim'));
        $this->assertSame('geheim!123', $secret->decrypt($stored));
    }

    public function testEncryptionUsesRandomIv(): void
    {
        $secret = new Secret('app-key-1');
        $this->assertFalse($secret->encrypt('gleich') === $secret->encrypt('gleich'));
    }

    public function testDecryptWithDifferentKeyReturnsNull(): void
    {
        $stored = (new Secret('app-key-1'))->encrypt('geheim!123');

        // APP_KEY geändert: Der Wert ist nicht mehr lesbar und muss neu erfasst werden.
        $this->assertNull((new Secret('app-key-2'))->decrypt($stored));
    }

    public function testDecryptAcceptsPlainLegacyValuesAndEmptyString(): void
    {
        $secret = new Secret('app-key-1');
        $this->assertSame('klartext', $secret->decrypt('klartext'));
        $this->assertSame('', $secret->decrypt(''));
        $this->assertSame('', $secret->encrypt(''));
    }

    public function testFromEnvironmentPrefersAppKey(): void
    {
        $stored = Secret::fromEnvironment('app-key-1', 'egal')->encrypt('geheim');
        $this->assertSame('geheim', Secret::fromEnvironment('  app-key-1  ', 'anders')->decrypt($stored));
        $this->assertNull(Secret::fromEnvironment('', 'anders')->decrypt($stored));
    }

    public function testFromEnvironmentFallsBackWhenAppKeyIsEmpty(): void
    {
        $stored = Secret::fromEnvironment('', 'abgeleitet')->encrypt('geheim');
        $this->assertSame('geheim', Secret::fromEnvironment('', 'abgeleitet')->decrypt($stored));
    }

    // ------------------------------------------------------------------ Verzeichnisnamen (UTF-7)

    public function testAsciiMailboxNamesRemainUnchanged(): void
    {
        $this->assertSame('INBOX', ImapMailboxClient::encodeUtf7('INBOX'));
        $this->assertSame('INBOX/Verarbeitet', ImapMailboxClient::encodeUtf7('INBOX/Verarbeitet'));
        $this->assertSame('INBOX/Verarbeitet', ImapMailboxClient::decodeUtf7('INBOX/Verarbeitet'));
    }

    public function testAmpersandIsEscaped(): void
    {
        $this->assertSame('Support &- Service', ImapMailboxClient::encodeUtf7('Support & Service'));
        $this->assertSame('Support & Service', ImapMailboxClient::decodeUtf7('Support &- Service'));
    }

    public function testUmlautsAreEncodedAsModifiedUtf7(): void
    {
        $encoded = ImapMailboxClient::encodeUtf7('INBOX/Erledigt-Prüfung');
        $this->assertStringContains('&', $encoded);
        $this->assertFalse(str_contains($encoded, 'ü'));
        $this->assertSame('INBOX/Erledigt-Prüfung', ImapMailboxClient::decodeUtf7($encoded));
    }

    public function testDecodeHandlesKnownServerFolder(): void
    {
        // Typische Antwort eines IMAP-Servers auf LIST für „Gelöschte Elemente“
        $this->assertSame('Gelöschte Elemente', ImapMailboxClient::decodeUtf7('Gel&APY-schte Elemente'));
    }
}
