<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\SupplierRepository;
use App\Support\Validator;

final class SupplierService
{
    public function __construct(
        private readonly SupplierRepository $suppliers,
        private readonly AuditLogService $audit
    ) {}

    /** @param array<string,mixed> $input */
    public function create(array $input): int
    {
        $data = $this->validate($input, null);
        $id = $this->suppliers->create($data);
        $this->audit->log('create', 'supplier', $id, $data['name'], null, $data);

        return $id;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $existing, array $input): void
    {
        $data = $this->validate($input, $id);
        $this->suppliers->update($id, $data);
        $this->audit->log('update', 'supplier', $id, $data['name'], $existing, $data);
    }

    /** @param array<string,mixed> $existing */
    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->suppliers->update($id, ['is_active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'supplier', $id, (string) $existing['name'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, ?int $excludeId): array
    {
        $data = (new Validator($input))
            ->string('name', 'Firmenname', true, 200, 2)
            ->string('street', 'Straße', false, 200)
            ->string('postal_code', 'PLZ', false, 20)
            ->string('city', 'Ort', false, 120)
            ->string('country', 'Land', false, 80)
            ->string('contact_person', 'Ansprechpartner', false, 200)
            ->email('email', 'E-Mail')
            ->string('phone', 'Telefon', false, 60)
            ->string('customer_number', 'Kundennummer', false, 80)
            ->url('website', 'Webseite')
            ->text('note', 'Bemerkung')
            ->bool('is_active')
            ->validated();

        if ($this->suppliers->findByName($data['name'], $excludeId) !== null) {
            throw ValidationException::single('name', 'Ein Lieferant mit diesem Namen existiert bereits.');
        }

        return $data;
    }
}
