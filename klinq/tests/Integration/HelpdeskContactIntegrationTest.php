<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\Helpdesk\HelpdeskContactService;
use App\Support\FuzzySearch;
use Tests\Support\DatabaseTestCase;

/**
 * Kontaktverzeichnis gegen die Test-Datenbank: Anlegen mit Stichworten, alphabetische Sortierung nach Firma,
 * Suche mit Ähnlichkeit, Stichwortfilter, Bearbeiten/Löschen (Stichworte werden mitgelöscht) und Rechte.
 */
final class HelpdeskContactIntegrationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $stmt = $this->pdo->prepare("INSERT INTO users (username, display_name, email, role_id, auth_source, is_active) SELECT ?, ?, ?, id, 'local', 1 FROM roles WHERE name = ?");
        $stmt->execute(['ct-agent', 'Kontakt Agent', 'ct-agent@example.test', 'helpdesk_agent']);
        $this->loginAs((int) $this->pdo->lastInsertId(), 'helpdesk_agent');
    }

    private function loginAs(int $userId, string $role): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $userId, 'username' => 'u' . $userId, 'display_name' => 'Agent ' . $userId, 'role' => $role, 'groups' => []]);
    }

    private function contacts(): HelpdeskContactService
    {
        return $this->c->get(HelpdeskContactService::class);
    }

    public function testCreateSortsByCompanyAndSearchesWithSimilarity(): void
    {
        $max = $this->contacts()->create(['company' => 'RPDoc', 'first_name' => 'Max', 'last_name' => 'Mustermann', 'phone' => '0531 12345', 'tags' => 'Hotline, Röntgen, hotline']);
        $this->contacts()->create(['company' => 'Agfa', 'first_name' => 'Anna', 'last_name' => 'Alt', 'email' => 'anna@agfa.test', 'tags' => 'PACS']);
        $this->contacts()->create(['company' => 'RPDoc', 'first_name' => 'Erika', 'last_name' => 'Beispiel', 'email' => 'erika@rpdoc.test']);

        $this->assertSame(['Hotline', 'Röntgen'], $max['tags'], 'Doppelte Stichworte werden zusammengefasst');
        $this->assertSame('Agent ' . $max['created_by'], $max['created_by_name']);

        $all = $this->contacts()->search('');
        $names = array_map(static fn (array $r): string => $r['company'] . '/' . $r['last_name'], $all['rows']);
        $this->assertSame(['Agfa/Alt', 'RPDoc/Beispiel', 'RPDoc/Mustermann'], $names, 'Alphabetisch nach Firma, dann Name');

        foreach (['rpdoc', 'rp doc', 'rpdok'] as $q) {
            $found = $this->contacts()->search($q);
            $this->assertSame(2, $found['paginator']->total, $q);
        }
        $this->assertSame(FuzzySearch::SIMILAR, $this->contacts()->search('rpdok')['rows'][0]['match']);
        $this->assertSame(2, $this->contacts()->search('rpdok')['similar']);
        $this->assertSame(1, $this->contacts()->search('röntgen')['paginator']->total, 'Stichworte sind durchsuchbar');
        $this->assertSame(1, $this->contacts()->search('', 'pacs')['paginator']->total, 'Filter nach Stichwort');

        $tags = array_column($this->contacts()->tags(), 'count', 'tag');
        $this->assertSame(['Hotline' => 1, 'PACS' => 1, 'Röntgen' => 1], $tags);
    }

    public function testValidationRequiresPhoneOrEmail(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->contacts()->create(['company' => 'RPDoc', 'first_name' => 'Max', 'last_name' => 'Mustermann']));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM helpdesk_contacts WHERE company = 'RPDoc'")->fetchColumn());
    }

    public function testUpdateReplacesTagsAndDeleteCascades(): void
    {
        $contact = $this->contacts()->create(['company' => 'Siemens', 'first_name' => 'Sven', 'last_name' => 'Sommer', 'mobile' => '0171 1234567', 'tags' => 'MRT, Wartung']);
        $updated = $this->contacts()->update((int) $contact['id'], ['company' => 'Siemens Healthineers', 'first_name' => 'Sven', 'last_name' => 'Sommer', 'mobile' => '0171 1234567', 'tags' => 'mrt, CT']);

        $this->assertSame('Siemens Healthineers', $updated['company']);
        $this->assertSame(['CT', 'mrt'], $updated['tags']);

        $this->contacts()->delete((int) $contact['id']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM helpdesk_contact_tags WHERE contact_id = ' . (int) $contact['id'])->fetchColumn());
        $this->assertThrows(\App\Exceptions\NotFoundException::class, fn () => $this->contacts()->get((int) $contact['id']));
    }

    public function testPortalUsersCannotReadOrWrite(): void
    {
        $this->loginAs(999999, 'portal');
        $this->assertThrows(ForbiddenException::class, fn () => $this->contacts()->search(''));
        $this->assertThrows(ForbiddenException::class, fn () => $this->contacts()->create(['company' => 'X', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.test']));
    }
}
