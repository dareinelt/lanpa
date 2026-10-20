<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\KnowledgeBaseRepository;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\TicketTemplateRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\HelpdeskAiService;
use App\Services\Helpdesk\KnowledgeBaseService;
use App\Services\Helpdesk\TicketService;

/** JSON-Endpunkte für Autocomplete, Kaskaden und Ticket-Daten (nur für angemeldete Benutzer). */
final class HelpdeskApiController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TicketService $service,
        private readonly TicketRepository $tickets,
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketCategoryRepository $categories,
        private readonly TicketTagRepository $tags,
        private readonly TicketTemplateRepository $templates,
        private readonly KnowledgeBaseService $knowledge,
        private readonly KnowledgeBaseRepository $articles,
        private readonly HelpdeskAiService $ai,
        private readonly Config $config
    ) {
        parent::__construct($view, $currentUser);
    }

    /** Ticketsuche (Merge, Beziehungen, Verknüpfen). Portalnutzer sehen nur eigene Tickets. */
    public function searchTickets(Request $request): Response
    {
        $term = $request->queryString('q');
        $limit = max(1, min(25, $request->int('limit', 10) ?? 10));
        $exclude = $request->int('exclude');
        $rows = $this->service->isAgent()
            ? $this->tickets->suggest($term, $limit, $exclude)
            : $this->tickets->quickSearch($term, $limit, $this->currentUser->id());

        return $this->json(['items' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'number' => $r['number'],
            'name' => $r['number'] . ' · ' . $r['subject'],
            'label' => $r['number'] . ' · ' . $r['subject'],
            'meta' => trim(($r['status_name'] ?? '') . ' · ' . ($r['priority_name'] ?? ''), ' ·'),
            'url' => '/helpdesk/tickets/' . (int) $r['id'],
        ], $rows)]);
    }

    public function ticket(Request $request): Response
    {
        $ticket = $this->service->getVisible($request->paramInt('id'));
        $isAgent = $this->service->isAgent();
        $out = [
            'id' => (int) $ticket['id'],
            'number' => $ticket['number'],
            'subject' => $ticket['subject'],
            'status' => ['code' => $ticket['status_code'], 'name' => $ticket['status_name'], 'color' => $ticket['status_color']],
            'priority' => ['code' => $ticket['priority_code'], 'name' => $ticket['priority_name'], 'level' => (int) $ticket['priority_level']],
            'type' => ['code' => $ticket['type_code'], 'name' => $ticket['type_name']],
            'category' => $ticket['category_name'],
            'subcategory' => $ticket['subcategory_name'],
            'requester' => $ticket['requester_name'],
            'assignee' => $ticket['assignee_name'],
            'group' => $ticket['group_name'],
            'version' => (int) $ticket['version'],
            'created_at' => $ticket['created_at'],
            'updated_at' => $ticket['updated_at'],
            'resolution_due_at' => $ticket['resolution_due_at'],
            'response_due_at' => $ticket['response_due_at'],
            'sla_response_state' => $ticket['sla_response_state'],
            'sla_resolution_state' => $ticket['sla_resolution_state'],
            'tags' => $ticket['tag_names'] !== null && $ticket['tag_names'] !== '' ? explode(',', (string) $ticket['tag_names']) : [],
            'transitions' => array_map(static fn (array $s): array => ['code' => $s['code'], 'name' => $s['name']], $this->service->availableTransitions($ticket)),
        ];
        if ($isAgent) {
            $out['description'] = $ticket['description'];
            $out['assets'] = $this->tickets->assets((int) $ticket['id']);
        }
        if ($request->queryString('include') === 'comments') {
            $out['comments'] = $this->service->comments($ticket);
        }

        return $this->json($out);
    }

    /** Tag-Vorschläge: {items:[{id,name,color}]} */
    public function suggestTags(Request $request): Response
    {
        $rows = $this->tags->suggest($request->queryString('q'), 10);

        return $this->json(['items' => array_map(static fn (array $t): array => ['id' => (int) $t['id'], 'name' => $t['name'], 'label' => $t['name'], 'color' => $t['color'], 'meta' => isset($t['usage_count']) ? $t['usage_count'] . '×' : ''], $rows)]);
    }

    /** Unterkategorien einer Kategorie (Kaskade im Formular). */
    public function children(Request $request): Response
    {
        $rows = $this->categories->children($request->paramInt('id'));

        return $this->json(['items' => array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'name' => $c['name'], 'default_group_id' => $c['default_group_id'] !== null ? (int) $c['default_group_id'] : null, 'default_priority_id' => $c['default_priority_id'] !== null ? (int) $c['default_priority_id'] : null], $rows)]);
    }

    /** Agenten (für Zuweisung); optional nach Gruppe eingeschränkt. */
    public function agents(Request $request): Response
    {
        $groupId = $request->int('group_id');
        $rows = $groupId !== null
            ? array_map(static fn (array $m): array => ['id' => (int) $m['user_id'], 'display_name' => $m['display_name'], 'is_lead' => (bool) ($m['is_lead'] ?? false)], $this->masterData->groupMembers($groupId))
            : array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'display_name' => $u['display_name'], 'is_lead' => false], $this->masterData->agents(TicketService::AGENT_ROLES));

        return $this->json(['items' => array_map(static fn (array $a): array => ['id' => $a['id'], 'name' => $a['display_name'], 'label' => $a['display_name'], 'meta' => $a['is_lead'] ? 'Leitung' : ''], $rows)]);
    }

    /** Vorlage als JSON (Formular-Vorbelegung ohne Reload). */
    public function template(Request $request): Response
    {
        $portal = !$this->service->isAgent();
        $data = $this->service->prefillFromTemplate($request->paramInt('id'), $portal);

        return $this->json(['template' => $data]);
    }

    /** Assets eines Mitarbeiters (für die Asset-Auswahl beim Anlegen). */
    public function employeeAssets(Request $request): Response
    {
        $rows = $this->service->assetsForEmployee($request->paramInt('id'));

        return $this->json(['items' => array_map(static fn (array $a): array => [
            'id' => (int) $a['id'],
            'name' => $a['inventory_number'] . ' · ' . ($a['article_name'] ?? $a['model'] ?? $a['manufacturer_name'] ?? ''),
            'label' => $a['inventory_number'],
            'meta' => trim(($a['article_name'] ?? '') . ' ' . ($a['serial_number'] ?? '')),
        ], $rows)]);
    }

    /** Wissensartikel-Vorschläge zum eingegebenen Betreff (Portal + Agent). */
    public function suggestArticles(Request $request): Response
    {
        $subject = $request->queryString('q');
        if (mb_strlen($subject) < 3) {
            return $this->json(['items' => []]);
        }
        $categoryId = $request->int('category_id');
        $rows = $this->articles->suggestForTicket($subject, $categoryId, $request->int('subcategory_id'), $this->currentUser->can('helpdesk.view'), 5);

        return $this->json(['items' => array_map(static fn (array $a): array => ['id' => (int) $a['id'], 'name' => $a['title'], 'label' => $a['title'], 'meta' => $a['summary'] ?? '', 'url' => '/helpdesk/knowledge/' . (int) $a['id']], array_values(array_filter($rows, fn (array $a): bool => $this->knowledge->isVisible($a))))]);
    }

    /**
     * Markierten Text per KI umformulieren („Mit KI verbessern“).
     *
     * Gespeichert wird nichts – die Antwort geht direkt an den Editor zurück.
     */
    public function improve(Request $request): Response
    {
        $result = $this->ai->improve(
            $request->string('text'),
            $request->string('prompt'),
            $request->string('mode', 'generic'),
            $request->stringOrNull('previous'),
            [
                'subject' => $request->string('subject'),
                'ticket' => $request->string('ticket'),
            ]
        );

        return $this->json([
            'ok' => true,
            'text' => $result['text'],
            'model' => $result['model'],
            'usage' => $result['usage'],
        ]);
    }

    /** Sichtbare Vorlagen (Agent: alle aktiven, Portal: nur portalsichtbare). */
    public function templates(Request $request): Response
    {
        $rows = $this->templates->all(true, !$this->service->isAgent());

        return $this->json(['items' => array_map(static fn (array $t): array => ['id' => (int) $t['id'], 'name' => $t['name'], 'label' => $t['name'], 'meta' => $t['description'] ?? ''], $rows)]);
    }
}
