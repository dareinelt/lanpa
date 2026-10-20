<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\CostCenterRepository;
use App\Support\Validator;

final class CostCenterService
{
    public function __construct(
        private readonly CostCenterRepository $costCenters,
        private readonly AuditLogService $audit
    ) {}

    /** @param array<string,mixed> $input */
    public function create(array $input): int
    {
        $data = $this->validate($input, null);
        $id = $this->costCenters->create($data);
        $this->audit->log('create', 'cost_center', $id, $data['number'], null, $data);

        return $id;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $existing, array $input): void
    {
        $data = $this->validate($input, $id);
        $this->costCenters->update($id, $data);
        $this->audit->log('update', 'cost_center', $id, $data['number'], $existing, $data);
    }

    /** @param array<string,mixed> $existing */
    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->costCenters->update($id, ['is_active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'cost_center', $id, (string) $existing['number'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, ?int $excludeId): array
    {
        $data = (new Validator($input))
            ->pattern('number', 'Nummer', '/^\d{5}$/', 'Die Kostenstellennummer muss genau fünf Ziffern haben.', true)
            ->string('description', 'Beschreibung', true, 200)
            ->id('location_id', 'Standort')
            ->bool('is_active')
            ->validated();

        $existing = $this->costCenters->findByNumber($data['number']);
        if ($existing !== null && (int) $existing['id'] !== $excludeId) {
            throw ValidationException::single('number', 'Diese Kostenstellennummer ist bereits vergeben.');
        }

        return $data;
    }
}
