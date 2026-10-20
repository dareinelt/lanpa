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

    /**
     * Kurzfassung fuer die Anzeige im Adminbereich.
     *
     * @param array<string,mixed> $access
     */
    public static function describe(array $access): string
    {
        $parts = [(string) ($access['role_label'] ?? '')];
        $labels = is_array($access['area_labels'] ?? null) ? $access['area_labels'] : [];
        if ($labels !== []) {
            $parts[] = implode(', ', array_map(static fn (mixed $label): string => (string) $label, $labels));
        }

        return implode(' – ', array_filter($parts));
    }
}
