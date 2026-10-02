<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Aktueller Stand der Speicherziele des Cold-Tiers (SMB-/S3-Tier), vom Monitor
 * nach jeder Pruefung geschrieben und von Synchronisation und Rueckholung
 * gelesen (state/targets.json). Nur Ziele mit "online" sind eingebunden und
 * gehoeren nachweislich zu dieser Installation.
 */
final class TargetMap
{
    public function __construct(private readonly string $file)
    {
    }

    /**
     * @param list<array{id:int,label:string,root:string,online:bool,primary:bool,active:bool}> $targets
     */
    public function write(array $targets): void
    {
        $temp = $this->file . '.' . getmypid() . '.tmp';
        FileCopier::ensureDir(dirname($this->file));
        file_put_contents($temp, json_encode(['updated' => time(), 'targets' => $targets], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($temp, $this->file);
    }

    /**
     * @return list<array{id:int,label:string,root:string,online:bool,primary:bool,active:bool}>
     */
    public function all(): array
    {
        $data = json_decode((string) @file_get_contents($this->file), true);
        if (!is_array($data) || !is_array($data['targets'] ?? null)) {
            return [];
        }
        // Veralteter Stand (Monitor ausgefallen): keine Ziele als erreichbar annehmen.
        $stale = time() - (int) ($data['updated'] ?? 0) > 120;
        $result = [];
        foreach ($data['targets'] as $target) {
            if (!is_array($target) || !isset($target['id'], $target['root'])) {
                continue;
            }
            $result[] = [
                'id' => (int) $target['id'],
                'label' => (string) ($target['label'] ?? ''),
                'root' => (string) $target['root'],
                'online' => !$stale && !empty($target['online']),
                'primary' => !empty($target['primary']),
                'active' => !empty($target['active']),
            ];
        }

        return $result;
    }

    /**
     * Erreichbare, aktive Ziele (primaeres zuerst).
     *
     * @return list<array{id:int,label:string,root:string,online:bool,primary:bool,active:bool}>
     */
    public function online(): array
    {
        $online = array_values(array_filter($this->all(), static fn (array $t): bool => $t['online'] && $t['active']));
        usort($online, static fn (array $a, array $b): int => [$b['primary'], $a['id']] <=> [$a['primary'], $b['id']]);

        return $online;
    }

    /**
     * @return list<int>
     */
    public function activeIds(): array
    {
        return array_values(array_map(
            static fn (array $t): int => $t['id'],
            array_filter($this->all(), static fn (array $t): bool => $t['active'])
        ));
    }
}
