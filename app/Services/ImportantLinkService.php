<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\ImportantLinkRepository;
use App\Support\Validator;

/**
 * Verwaltet die "Wichtigen Links" unterhalb der Navigations-Kacheln.
 *
 * Die Liste wird stets automatisch alphabetisch sortiert (siehe Repository);
 * eine manuelle Sortierung wird im Adminbereich bewusst nicht angeboten.
 */
final class ImportantLinkService
{
    public function __construct(
        private readonly ImportantLinkRepository $repository,
        private readonly FaviconService $favicons
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function activeItems(): array
    {
        return $this->repository->allActive();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function allItems(): array
    {
        return $this->repository->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed> validierte Daten
     */
    public function validate(array $input): array
    {
        $errors = [];

        $title = Validator::cleanText((string) ($input['title'] ?? ''), 120);
        if (!Validator::isNotEmpty($title, 120)) {
            $errors['title'] = 'Bitte einen Titel angeben (max. 120 Zeichen).';
        }

        $url = trim((string) ($input['url'] ?? ''));
        if (!Validator::isSafeUrl($url)) {
            $errors['url'] = 'Bitte eine gültige http(s)-URL oder einen internen Pfad (/telefonliste) angeben.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'title' => $title,
            'url' => $url,
            'active' => !empty($input['active']),
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    public function create(array $input): int
    {
        $data = $this->validate($input);
        $data += ['icon_file' => null, 'icon_mime' => null];

        $id = $this->repository->create($data);
        $this->refreshIcon($id, $data['url']);

        return $id;
    }

    /**
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $input): void
    {
        $existing = $this->repository->find($id);
        if ($existing === null) {
            throw new ValidationException(['id' => 'Element nicht gefunden.']);
        }

        $data = $this->validate($input);
        $urlChanged = $data['url'] !== (string) $existing['url'];

        $data += [
            'icon_file' => $existing['icon_file'],
            'icon_mime' => $existing['icon_mime'],
        ];

        if ($urlChanged) {
            $data['icon_file'] = null;
            $data['icon_mime'] = null;
            $this->favicons->deleteStoredIcon((string) ($existing['icon_file'] ?? ''));
        }

        $this->repository->update($id, $data);

        if ($urlChanged) {
            $this->refreshIcon($id, $data['url']);
        }
    }

    public function delete(int $id): void
    {
        $item = $this->repository->find($id);
        $this->repository->delete($id);

        if ($item !== null) {
            $this->favicons->deleteStoredIcon((string) ($item['icon_file'] ?? ''));
        }
    }

    public function toggle(int $id): void
    {
        $item = $this->repository->find($id);
        if ($item === null) {
            throw new ValidationException(['id' => 'Element nicht gefunden.']);
        }

        $this->repository->setActive($id, !(bool) $item['active']);
    }

    /**
     * Ermittelt bei Bedarf erneut das Favicon fuer ein bestehendes Element.
     */
    public function refreshIcon(int $id, string $url): void
    {
        $icon = $this->favicons->fetchAndStore($url);
        if ($icon === null) {
            return;
        }

        $this->repository->updateIcon($id, $icon['icon_file'], $icon['icon_mime']);
    }
}
