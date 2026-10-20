<?php

declare(strict_types=1);

namespace App\Services\Klinq;

/**
 * Loest die KLINQ-Berechtigungen einer angemeldeten Person auf.
 */
final class KlinqAccessService
{
    public function __construct(private readonly KlinqConfigService $config)
    {
    }

    /**
     * @param array<string,mixed>|null $ssoUser
     * @return array{
     *     role:string,
     *     role_label:string,
     *     permission_groups:list<string>,
     *     areas:list<string>,
     *     area_labels:list<string>,
     *     matched:list<string>,
     *     elevated:bool
     * }
     */
    public function resolve(?array $ssoUser): array
    {
        $groups = [];
        if (is_array($ssoUser['groups'] ?? null)) {
            $groups = array_values($ssoUser['groups']);
        }

        return $this->config->resolve($groups);
    }
}
