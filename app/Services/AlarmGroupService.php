<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\AlarmGroupRepository;
use App\Support\Validator;

final class AlarmGroupService
{
    public function __construct(private readonly AlarmGroupRepository $repository)
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

        $groupNumber = Validator::cleanText((string) ($input['group_number'] ?? ''), 64);
        if (!Validator::isGroupNumber($groupNumber)) {
            $errors['group_number'] = 'Bitte eine gültige Gruppennummer angeben (max. 64 Zeichen).';
        }

        $description = Validator::cleanText((string) ($input['description'] ?? ''), 255);
        if (!Validator::isNotEmpty($description, 255)) {
            $errors['description'] = 'Bitte eine Beschreibung angeben (max. 255 Zeichen).';
        }

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
            'group_number' => $groupNumber,
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
            throw new ValidationException(['id' => 'Gruppe nicht gefunden.']);
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
            throw new ValidationException(['id' => 'Gruppe nicht gefunden.']);
        }

        $this->repository->setActive($id, !(bool) $item['active']);
    }
}
