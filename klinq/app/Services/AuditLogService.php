<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Security\CurrentUser;

final class AuditLogService
{
    private string $ipAddress = '';

    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly CurrentUser $currentUser
    ) {}

    public function setIpAddress(string $ip): void
    {
        $this->ipAddress = $ip;
    }

    /**
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public function log(string $action, string $objectType, ?int $objectId, ?string $label = null, ?array $old = null, ?array $new = null): void
    {
        // Nur tatsächlich geänderte Felder speichern (keine unnötige Vervielfachung personenbezogener Daten)
        if ($old !== null && $new !== null) {
            $diffOld = [];
            $diffNew = [];
            foreach ($new as $key => $value) {
                if (($old[$key] ?? null) != $value) {
                    $diffOld[$key] = $old[$key] ?? null;
                    $diffNew[$key] = $value;
                }
            }
            $old = $diffOld;
            $new = $diffNew;
            if ($old === [] && $new === []) {
                return;
            }
        }

        $this->repository->insert([
            'user_id' => $this->currentUser->id(),
            'username' => $this->currentUser->username(),
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'object_label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'old_data' => $old === null ? null : json_encode($this->sanitize($old), JSON_UNESCAPED_UNICODE),
            'new_data' => $new === null ? null : json_encode($this->sanitize($new), JSON_UNESCAPED_UNICODE),
            'ip_address' => $this->ipAddress !== '' ? $this->ipAddress : null,
        ]);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|secret|license_key|bind/i', (string) $key)) {
                $data[$key] = '***';
            }
        }

        return $data;
    }
}
