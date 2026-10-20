<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Core\Config;
use App\Services\Helpdesk\ReporterIdentityService;
use App\Support\IpRange;
use Tests\Support\TestCase;

/**
 * Störungsformular: Normalisierung des erkannten Windows-Kontos, Netzfreigaben und
 * der vorübergehende Systemkontext, unter dem anonyme Meldungen angelegt werden.
 */
final class QuickReportTest extends TestCase
{
    public function testUsernameNormalizationAcceptsCommonWindowsFormats(): void
    {
        $this->assertSame('mmuster', ReporterIdentityService::normalize('FIRMA\\mmuster'));
        $this->assertSame('mmuster', ReporterIdentityService::normalize('mmuster@firma.local'));
        $this->assertSame('mmuster', ReporterIdentityService::normalize('  mmuster  '));
        $this->assertSame('m.muster-1', ReporterIdentityService::normalize('FIRMA\\m.muster-1'));
    }

    public function testUsernameNormalizationRejectsEmptyOrInjectedValues(): void
    {
        $this->assertNull(ReporterIdentityService::normalize(null));
        $this->assertNull(ReporterIdentityService::normalize(''));
        $this->assertNull(ReporterIdentityService::normalize('FIRMA\\'));
        $this->assertNull(ReporterIdentityService::normalize('mmuster)(objectClass=*'));
        $this->assertNull(ReporterIdentityService::normalize('mit leerzeichen'));
    }

    public function testAllowedNetworksMatchIpv4AndIpv6(): void
    {
        $this->assertTrue(IpRange::matches('10.12.3.44', '10.0.0.0/8'));
        $this->assertFalse(IpRange::matches('192.168.5.1', '10.0.0.0/8'));
        $this->assertTrue(IpRange::matches('192.168.5.130', '192.168.5.128/25'));
        $this->assertFalse(IpRange::matches('192.168.5.127', '192.168.5.128/25'));
        $this->assertTrue(IpRange::matches('172.16.0.9', '172.16.0.9'));
        $this->assertTrue(IpRange::matches('2001:db8::5', '2001:db8::/32'));
        $this->assertFalse(IpRange::matches('2001:dba::5', '2001:db8::/32'));
        $this->assertFalse(IpRange::matches('nonsense', '10.0.0.0/8'));
        $this->assertTrue(IpRange::matchesAny('10.1.2.3', ['192.168.0.0/16', '10.0.0.0/8']));
        $this->assertFalse(IpRange::matchesAny('10.1.2.3', []));
    }

    public function testRunAsGrantsRightsOnlyDuringTheActionAndNeverTouchesTheSession(): void
    {
        $_SESSION = [];
        $currentUser = new CurrentUser(new Permissions(new Config(dirname(__DIR__, 2) . '/config')));
        $system = ['id' => 7, 'username' => 'helpdesk-system', 'display_name' => 'Help Desk', 'role' => 'helpdesk_agent'];

        $this->assertFalse($currentUser->isAuthenticated());
        $inside = $currentUser->runAs($system, static fn (): array => [$currentUser->id(), $currentUser->can('helpdesk.create')]);

        $this->assertSame([7, true], $inside);
        $this->assertFalse($currentUser->isAuthenticated(), 'Systemkontext darf nach der Aktion nicht bestehen bleiben');
        $this->assertFalse($currentUser->can('helpdesk.create'));
        $this->assertSame([], $_SESSION, 'Die Sitzung des Besuchers darf nicht verändert werden');
    }

    public function testRunAsRestoresContextEvenOnFailure(): void
    {
        $_SESSION = [];
        $currentUser = new CurrentUser(new Permissions(new Config(dirname(__DIR__, 2) . '/config')));
        $system = ['id' => 7, 'username' => 'helpdesk-system', 'display_name' => 'Help Desk', 'role' => 'helpdesk_agent'];

        $this->assertThrows(\RuntimeException::class, static function () use ($currentUser, $system): void {
            $currentUser->runAs($system, static function (): void {
                throw new \RuntimeException('Fehler beim Anlegen');
            });
        });
        $this->assertFalse($currentUser->isAuthenticated());
    }
}
