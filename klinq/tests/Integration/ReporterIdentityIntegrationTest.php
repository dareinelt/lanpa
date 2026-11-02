<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Request;
use App\Repositories\EmployeeRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\ReporterIdentityService;
use App\Services\Helpdesk\TicketService;
use App\Services\Lanpa\LanpaIdentityService;
use Tests\Support\DatabaseTestCase;

/**
 * Meldererkennung über den Intranet-Einstiegsschein (LANPA).
 *
 * Personen aus dem Intranet-Telefonbuch haben nicht zwangsläufig einen Mitarbeiter-
 * datensatz in KLINQ und führen dort auch nicht immer denselben Anmeldenamen. Der
 * Name muss trotzdem am Ticket stehen – sonst zeigt die Ticketliste nur „–“ mit der
 * E-Mail-Adresse darunter.
 */
final class ReporterIdentityIntegrationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, is_active)
            SELECT 'hd-agent', 'Agent', 'agent@example.test', id, 'local', 1 FROM roles WHERE name = ?");
        $stmt->execute(['helpdesk_agent']);
        $agentId = (int) $this->pdo->lastInsertId();
        $this->c->get(CurrentUser::class)->login(['id' => $agentId, 'username' => 'hd-agent', 'display_name' => 'Agent', 'role' => 'helpdesk_agent', 'groups' => []]);
    }

    protected function tearDown(): void
    {
        $this->c->get(LanpaIdentityService::class)->forget();
        parent::tearDown();
    }

    /** Ohne Treffer über den Anmeldenamen wird der Mitarbeiter über die E-Mail-Adresse des Scheins gefunden. */
    public function testDetectFindsEmployeeByIntranetEmail(): void
    {
        $employeeId = $this->c->get(EmployeeRepository::class)->create([
            'first_name' => 'Sandra',
            'last_name' => 'Mertins',
            'display_name' => 'Sandra Mertins',
            'email' => 'sandra.mertins@example.test',
            'username' => 'smertins.ad',
            'source' => 'manual',
            'is_active' => 1,
        ]);
        $this->c->get(LanpaIdentityService::class)->remember([
            'sub' => 'sandra.mertins',
            'name' => 'Sandra Mertins',
            'email' => 'Sandra.Mertins@Example.Test',
        ]);

        $reporter = $this->c->get(ReporterIdentityService::class)->detect($this->request());

        $this->assertSame('sandra.mertins', $reporter['username']);
        $this->assertSame('Sandra Mertins', $reporter['display_name']);
        $this->assertSame($employeeId, $reporter['employee_id'], 'Verknüpfung über die E-Mail-Adresse');
    }

    /** Person nur im Telefonbuch: Der Name aus dem Schein wird am Ticket gespeichert und angezeigt. */
    public function testQuickReportKeepsReporterNameWithoutEmployeeRecord(): void
    {
        $this->c->get(LanpaIdentityService::class)->remember([
            'sub' => 'smertins',
            'name' => 'Sandra Mertins',
            'email' => 'sandra.mertins@example.test',
            'department' => 'IT',
        ]);
        $reporter = $this->c->get(ReporterIdentityService::class)->detect($this->request());
        $this->assertNull($reporter['employee_id'], 'kein Mitarbeiterdatensatz in KLINQ');

        $type = $this->c->get(TicketMasterDataRepository::class)->typeByCode('incident');
        $ticket = $this->c->get(TicketService::class)->create([
            'subject' => 'Drucker im 2. OG defekt',
            'description' => 'Der Drucker meldet Papierstau, obwohl kein Papier eingeklemmt ist.',
            'ticket_type_id' => $type['id'],
            'reporter' => $reporter,
        ], 'form');

        $this->assertSame('Sandra Mertins', $ticket['reporter_name']);
        $this->assertSame('Sandra Mertins', $ticket['requester_name'], 'Spalte „Melder“ zeigt den Namen statt „–“');
        $this->assertSame('sandra.mertins@example.test', $ticket['requester_email']);

        $rows = $this->c->get(TicketRepository::class)->search(['q' => 'Mertins'], 'created_at', 'desc', 50, 0);
        $this->assertCount(1, $rows, 'Volltextsuche findet den Meldernamen');
        $this->assertSame('Sandra Mertins', $rows[0]['requester_name']);
    }

    private function request(): Request
    {
        return new Request(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/stoerung', 'REMOTE_ADDR' => '127.0.0.1'], [], [], []);
    }
}
