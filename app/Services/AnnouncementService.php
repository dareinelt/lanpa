<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\AnnouncementRepository;
use App\Support\Validator;

/**
 * Verwaltet die optionale Overlay-Mitteilung der Landingpage.
 *
 * Es kann jeweils nur eine Mitteilung aktiv sein. Wird eine neue Mitteilung
 * aktiviert, werden alle anderen automatisch archiviert. Archivierte
 * Mitteilungen bleiben als Verlauf erhalten und koennen reaktiviert werden.
 */
final class AnnouncementService
{
    public function __construct(private readonly AnnouncementRepository $repository)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function active(): ?array
    {
        return $this->repository->findActive();
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

        $title = Validator::cleanText((string) ($input['title'] ?? ''), 160);
        if (!Validator::isNotEmpty($title, 160)) {
            $errors['title'] = 'Bitte einen Titel angeben (max. 160 Zeichen).';
        }

        $message = trim((string) ($input['message'] ?? ''));
        if (!Validator::isNotEmpty($message, 5000)) {
            $errors['message'] = 'Bitte einen Text angeben (max. 5000 Zeichen).';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'title' => $title,
            'message' => $message,
            'active' => !empty($input['active']),
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    public function create(array $input): int
    {
        return $this->repository->create($this->validate($input));
    }

    /**
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $input): void
    {
        if ($this->repository->find($id) === null) {
            throw new ValidationException(['id' => 'Mitteilung nicht gefunden.']);
        }

        $this->repository->update($id, $this->validate($input));
    }

    public function delete(int $id): void
    {
        $this->repository->delete($id);
    }

    /**
     * Aktiviert eine Mitteilung erneut (reaktivieren) bzw. archiviert sie.
     */
    public function setActive(int $id, bool $active): void
    {
        if ($this->repository->find($id) === null) {
            throw new ValidationException(['id' => 'Mitteilung nicht gefunden.']);
        }

        $this->repository->setActive($id, $active);
    }
}
