<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\EmergencyNumberRepository;
use App\Support\Validator;

final class EmergencyNumberService
{
    public function __construct(private readonly EmergencyNumberRepository $repository)
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

        $label = Validator::cleanText((string) ($input['label'] ?? ''), 120);
        if (!Validator::isNotEmpty($label, 120)) {
            $errors['label'] = 'Bitte eine Bezeichnung angeben (max. 120 Zeichen).';
        }

        $phone = Validator::cleanText((string) ($input['phone'] ?? ''), 64);
        if (!Validator::isPhoneNumber($phone)) {
            $errors['phone'] = 'Bitte eine gültige Rufnummer angeben.';
        }

        $description = Validator::cleanText((string) ($input['description'] ?? ''), 255);

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
            'label' => $label,
            'phone' => $phone,
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
