<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\NavigationRepository;
use App\Support\Validator;

final class NavigationService
{
    public function __construct(private readonly NavigationRepository $repository)
    {
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
    public function validate(array $input, bool $isNew): array
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

        $type = (string) ($input['type'] ?? 'external');
        if (!Validator::isNavigationType($type)) {
            $errors['type'] = 'Ungültiger Typ.';
        }

        if ($type === 'internal' && !str_starts_with($url, '/')) {
            $errors['url'] = 'Interne Elemente benötigen einen anwendungsinternen Pfad, z. B. /telefonliste.';
        }

        $icon = Validator::cleanText((string) ($input['icon'] ?? ''), 32);
        if ($icon !== '' && preg_match('/^[a-z0-9-]{1,32}$/', $icon) !== 1) {
            $errors['icon'] = 'Icon-Schlüssel dürfen nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.';
        }

        $shortDescription = Validator::cleanText((string) ($input['short_description'] ?? ''), 255);
        $description = Validator::cleanText((string) ($input['description'] ?? ''), 5000);

        $sortOrder = (int) ($input['sort_order'] ?? 0);
        if ($sortOrder < 1) {
            $sortOrder = $isNew ? $this->repository->nextSortOrder() : 1;
        }
        if ($sortOrder > 9999) {
            $errors['sort_order'] = 'Sortierung muss zwischen 1 und 9999 liegen.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'title' => $title,
            'url' => $url,
            'type' => $type,
            'icon' => $icon === '' ? null : $icon,
            'short_description' => $shortDescription,
            'description' => $description,
            'sort_order' => $sortOrder,
            'active' => !empty($input['active']),
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    public function create(array $input): int
    {
        return $this->repository->create($this->validate($input, true));
    }

    /**
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $input): void
    {
        if ($this->repository->find($id) === null) {
            throw new ValidationException(['id' => 'Element nicht gefunden.']);
        }

        $this->repository->update($id, $this->validate($input, false));
    }

    public function delete(int $id): void
    {
        $this->repository->delete($id);
    }

    public function toggle(int $id): void
    {
        $item = $this->repository->find($id);
        if ($item === null) {
            throw new ValidationException(['id' => 'Element nicht gefunden.']);
        }

        $this->repository->setActive($id, !(bool) $item['active']);
    }

    public function move(int $id, string $direction): bool
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        return $this->repository->move($id, $direction);
    }
}
