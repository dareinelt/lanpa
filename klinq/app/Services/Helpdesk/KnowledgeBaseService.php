<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\KnowledgeBaseRepository;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketTagRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Support\Validator;

/** Wissensdatenbank: Artikel anlegen/pflegen, aus Tickets erzeugen, Sichtbarkeit und Suche. */
final class KnowledgeBaseService
{
    public const STATUSES = ['draft' => 'Entwurf', 'published' => 'Veröffentlicht', 'archived' => 'Archiviert'];
    public const VISIBILITIES = ['internal' => 'Nur Help Desk', 'public' => 'Alle Benutzer (Portal)'];

    public function __construct(
        private readonly KnowledgeBaseRepository $articles,
        private readonly TicketCategoryRepository $categories,
        private readonly TicketTagRepository $tags,
        private readonly TicketRepository $tickets,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    public function canManage(): bool
    {
        return $this->currentUser->can('knowledgebase.manage');
    }

    /** Artikel lesen; Entwürfe/interne Artikel nur für Berechtigte. @return array<string,mixed> */
    public function getVisible(int $id, bool $countView = false): array
    {
        $article = $this->articles->find($id);
        if ($article === null) {
            throw new NotFoundException('Artikel nicht gefunden.');
        }
        if (!$this->isVisible($article)) {
            throw new ForbiddenException('Dieser Artikel ist nicht freigegeben.');
        }
        if ($countView) {
            $this->articles->incrementViews($id);
        }

        return $article;
    }

    /** @param array<string,mixed> $article */
    public function isVisible(array $article): bool
    {
        if ($this->canManage()) {
            return true;
        }
        if ($article['status'] !== 'published') {
            return false;
        }

        return $article['visibility'] === 'public' || $this->currentUser->can('helpdesk.view');
    }

    /** Filter für Suche/Liste je nach Berechtigung einschränken. @param array<string,mixed> $filters @return array<string,mixed> */
    public function restrictFilters(array $filters): array
    {
        if (!$this->canManage()) {
            $filters['published_only'] = true;
            if (!$this->currentUser->can('helpdesk.view')) {
                $filters['visibility'] = 'public';
            }
        }

        return $filters;
    }

    /** Mit einem Ticket verknüpfte, für den Benutzer sichtbare Artikel. @return array<int,array<string,mixed>> */
    public function articlesForTicket(int $ticketId): array
    {
        return array_values(array_filter($this->articles->articlesForTicket($ticketId), fn (array $a): bool => $this->isVisible($a)));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(array $input, ?int $sourceTicketId = null): array
    {
        $this->currentUser->require('knowledgebase.manage');
        $data = $this->validate($input, null);
        $data['source_ticket_id'] = $sourceTicketId;
        $data['created_by'] = $this->currentUser->id();
        $data['updated_by'] = $this->currentUser->id();
        if ($data['status'] === 'published') {
            $data['published_at'] = gmdate('Y-m-d H:i:s');
        }
        $tagNames = $this->parseTags((string) ($input['tags'] ?? ''));
        $id = $this->articles->transaction(function () use ($data, $tagNames, $sourceTicketId): int {
            $id = $this->articles->create($data);
            $this->tags->setArticleTags($id, array_map(fn (string $n): int => $this->tags->ensure($n), $tagNames));
            if ($sourceTicketId !== null) {
                $this->articles->linkTicket($id, $sourceTicketId, $this->currentUser->id());
                $this->tickets->update($sourceTicketId, ['knowledge_article_id' => $id]);
                $this->tickets->addEvent($sourceTicketId, 'knowledge_created', $this->currentUser->id(), $this->currentUser->displayName(), null, null, (string) $data['title']);
            }

            return $id;
        });
        $this->audit->log('create', 'knowledge_article', $id, (string) $data['title'], null, ['title' => $data['title'], 'status' => $data['status']]);

        return $this->articles->find($id) ?? [];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(int $id, array $input): array
    {
        $this->currentUser->require('knowledgebase.manage');
        $existing = $this->articles->find($id);
        if ($existing === null) {
            throw new NotFoundException('Artikel nicht gefunden.');
        }
        $data = $this->validate($input, $existing);
        $data['updated_by'] = $this->currentUser->id();
        if ($data['status'] === 'published' && $existing['published_at'] === null) {
            $data['published_at'] = gmdate('Y-m-d H:i:s');
        }
        $tagNames = $this->parseTags((string) ($input['tags'] ?? ''));
        $this->articles->transaction(function () use ($id, $data, $tagNames): void {
            $this->articles->update($id, $data);
            $this->tags->setArticleTags($id, array_map(fn (string $n): int => $this->tags->ensure($n), $tagNames));
        });
        $this->audit->log('update', 'knowledge_article', $id, (string) $data['title'], ['title' => $existing['title'], 'status' => $existing['status'], 'visibility' => $existing['visibility']], ['title' => $data['title'], 'status' => $data['status'], 'visibility' => $data['visibility']]);

        return $this->articles->find($id) ?? [];
    }

    public function linkTicket(int $articleId, string $ticketNumberOrId): void
    {
        $this->currentUser->require('knowledgebase.manage');
        $ticket = ctype_digit($ticketNumberOrId) ? $this->tickets->find((int) $ticketNumberOrId) : $this->tickets->findByNumber(TicketNumberService::normalize($ticketNumberOrId) ?? $ticketNumberOrId);
        if ($ticket === null) {
            throw ValidationException::single('ticket', 'Ticket nicht gefunden.');
        }
        $this->articles->linkTicket($articleId, (int) $ticket['id'], $this->currentUser->id());
        $this->tickets->addEvent((int) $ticket['id'], 'knowledge_linked', $this->currentUser->id(), $this->currentUser->displayName(), null, null, (string) ($this->articles->find($articleId)['title'] ?? ''));
    }

    public function unlinkTicket(int $articleId, int $ticketId): void
    {
        $this->currentUser->require('knowledgebase.manage');
        $this->articles->unlinkTicket($articleId, $ticketId);
    }

    /** Vorbelegung eines Artikels aus einem gelösten Ticket. @param array<string,mixed> $ticket @return array<string,mixed> */
    public function prefillFromTicket(array $ticket): array
    {
        $body = "## Problem\n\n" . trim((string) $ticket['description']) . "\n\n## Lösung\n\n" . trim((string) ($ticket['resolution'] ?? ''));

        return [
            'title' => $ticket['subject'],
            'summary' => mb_substr(trim((string) ($ticket['resolution'] ?? $ticket['description'])), 0, 300),
            'body' => $body,
            'category_id' => $ticket['subcategory_id'] ?? $ticket['category_id'],
            'tags' => $ticket['tag_names'] ?? '',
            'status' => 'draft',
            'visibility' => 'internal',
        ];
    }

    /** Vorschläge zu einem Ticket. @param array<string,mixed> $ticket @return array<int,array<string,mixed>> */
    public function suggestionsFor(array $ticket): array
    {
        return $this->articles->suggestForTicket((string) $ticket['subject'], $ticket['category_id'] !== null ? (int) $ticket['category_id'] : null, $ticket['subcategory_id'] !== null ? (int) $ticket['subcategory_id'] : null, $this->currentUser->can('helpdesk.view'));
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $existing @return array<string,mixed> */
    private function validate(array $input, ?array $existing): array
    {
        $v = (new Validator($input))
            ->string('title', 'Titel', true, 255, 3)
            ->string('summary', 'Kurzfassung', false, 500)
            ->text('body', 'Inhalt', true, 60000)
            ->id('category_id', 'Kategorie')
            ->in('status', 'Status', array_keys(self::STATUSES), true)
            ->in('visibility', 'Sichtbarkeit', array_keys(self::VISIBILITIES), true)
            ->string('slug', 'Kurzlink', false, 200);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data = $v->validated();
        if (!empty($data['category_id']) && $this->categories->find((int) $data['category_id']) === null) {
            throw ValidationException::single('category_id', 'Kategorie nicht gefunden.');
        }
        $slug = self::slugify((string) ($data['slug'] ?: $data['title']));
        if ($slug === '') {
            $slug = 'artikel';
        }
        $base = $slug;
        for ($i = 2; $this->articles->slugExists($slug, $existing['id'] ?? null); $i++) {
            $slug = $base . '-' . $i;
        }
        $data['slug'] = $slug;

        return $data;
    }

    public static function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim(mb_substr($value, 0, 190), '-');
    }

    /** @return array<int,string> */
    private function parseTags(string $raw): array
    {
        $names = [];
        foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $name) {
            $name = trim($name);
            if ($name !== '' && mb_strlen($name) <= 60) {
                $names[mb_strtolower($name)] = $name;
            }
        }

        return array_values($names);
    }
}
