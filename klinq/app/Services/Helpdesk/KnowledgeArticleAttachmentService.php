<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\DocumentRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\DocumentService;

/**
 * Anhänge eines Wissensartikels: Ablage über den DocumentService (Datei im Dateisystem,
 * Metadaten in `documents` mit `entity_type = knowledge_article`). Anders als bei Tickets
 * braucht es keine eigene Verknüpfungstabelle – die Sichtbarkeit regelt der Artikel selbst.
 */
final class KnowledgeArticleAttachmentService
{
    /** Höchstzahl Dateien je Formularvorgang. */
    public const MAX_FILES_PER_UPLOAD = 5;

    public function __construct(
        private readonly KnowledgeBaseService $knowledge,
        private readonly DocumentService $documents,
        private readonly DocumentRepository $documentRepository,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {
    }

    /**
     * Eine hochgeladene Datei ablegen und dem Artikel zuordnen.
     *
     * @param array<string,mixed> $file $_FILES-Eintrag
     * @return int ID des documents-Datensatzes
     */
    public function store(int $articleId, array $file, ?string $note = null): int
    {
        $this->currentUser->require('knowledgebase.manage');
        $article = $this->knowledge->getVisible($articleId);
        $documentId = $this->documents->store('knowledge_article', $articleId, 'attachment', $file, $note);
        $this->audit->log('upload', 'attachment', $documentId, $article['title'] . ' ' . $file['name'], null, ['file' => $file['name']]);

        return $documentId;
    }

    /**
     * Mehrfachauswahl eines Formularfelds (`name="attachments[]"`) ablegen.
     * Fehlerhafte Dateien brechen den Vorgang nicht ab, sondern werden gemeldet.
     *
     * @param array<string,mixed>|null $upload $_FILES-Eintrag des Feldes
     * @return array{saved:int,errors:list<string>}
     */
    public function storeAll(int $articleId, ?array $upload): array
    {
        $saved = 0;
        $errors = [];
        foreach ($this->normalize($upload ?? []) as $file) {
            if ($saved >= self::MAX_FILES_PER_UPLOAD) {
                $errors[] = 'Es wurden nur die ersten ' . self::MAX_FILES_PER_UPLOAD . ' Dateien übernommen.';

                break;
            }
            try {
                $this->store($articleId, $file);
                $saved++;
            } catch (ValidationException $exception) {
                $errors[] = (string) $file['name'] . ': ' . implode(' ', $exception->errors());
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /** @return array<int,array<string,mixed>> */
    public function forArticle(int $articleId): array
    {
        return $this->documentRepository->forEntity('knowledge_article', $articleId);
    }

    /**
     * Anhang eines Artikels laden; fremde Dokumente werden nicht ausgeliefert.
     *
     * @return array<string,mixed>
     */
    public function findForArticle(int $articleId, int $documentId): array
    {
        $document = $this->documentRepository->find($documentId);
        if ($document === null || $document['entity_type'] !== 'knowledge_article' || (int) $document['entity_id'] !== $articleId) {
            throw new NotFoundException('Anhang nicht gefunden.');
        }

        return $document;
    }

    /** @param array<string,mixed> $document */
    public function delete(array $document): void
    {
        $this->currentUser->require('knowledgebase.manage');
        $this->documents->delete($document);
    }

    /**
     * Normalisiert Einzel- und Mehrfachauswahl aus $_FILES zu einer Liste.
     *
     * @param array<string,mixed> $upload
     * @return list<array<string,mixed>>
     */
    private function normalize(array $upload): array
    {
        if (!isset($upload['name'])) {
            return [];
        }
        if (!is_array($upload['name'])) {
            return (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$upload];
        }
        $files = [];
        foreach (array_keys($upload['name']) as $index) {
            if ((int) ($upload['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => (string) $upload['name'][$index],
                'type' => (string) ($upload['type'][$index] ?? ''),
                'tmp_name' => (string) ($upload['tmp_name'][$index] ?? ''),
                'error' => (int) $upload['error'][$index],
                'size' => (int) ($upload['size'][$index] ?? 0),
            ];
        }

        return $files;
    }
}
