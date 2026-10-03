<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use App\Services\Storage\StorageService;

/**
 * Verteilung der Dateien eines Cold-Tiers auf seine Ziele (Basisziel und
 * Erweiterungen). Jeder Tier haelt eine vollstaendige Kopie; jede Datei liegt
 * darin auf genau einem Ziel unter <ziel>/<quelle>/<pfad>. Neue Dateien landen
 * auf dem ersten Ziel mit ausreichend Platz (Ueberlauf in der Reihenfolge
 * Basisziel, Erweiterung 1, 2 ...). Ein volles Ziel bleibt voll; es wird nur
 * noch gelesen, geaendert oder geleert.
 */
final class TierLayout
{
    /** Freizuhaltender Platz je Ziel: 1 % der Groesse, mindestens 64 MB, hoechstens 1 GB. */
    public const RESERVE_MIN = 64 * 1048576;
    public const RESERVE_MAX = 1073741824;

    /** @var \Closure(string):?int */
    private \Closure $freeSpace;

    /** @var array<int,int> Seit der letzten Messung auf ein Ziel geschriebene Bytes */
    private array $placed = [];

    /**
     * @param (callable(string):?int)|null $freeSpace Freier Platz eines eingebundenen Ziels (Test)
     */
    public function __construct(?callable $freeSpace = null)
    {
        $this->freeSpace = $freeSpace !== null
            ? \Closure::fromCallable($freeSpace)
            : static function (string $root): ?int {
                $free = @disk_free_space($root);

                return $free === false ? null : (int) $free;
            };
    }

    /**
     * Ziele eines Tiers (Basisziel zuerst).
     *
     * @param array<string,mixed> $target Eintrag aus TargetMap
     *
     * @return list<array{id:int,label:string,root:string,online:bool,kind:string,total_bytes:int,free_bytes:int}>
     */
    public static function members(array $target): array
    {
        $members = $target['members'] ?? [];
        if (!is_array($members) || $members === []) {
            return [[
                'id' => (int) $target['id'],
                'label' => (string) ($target['label'] ?? ''),
                'root' => rtrim((string) $target['root'], '/'),
                'online' => (bool) ($target['online'] ?? false),
                'kind' => StorageService::KIND_SMB,
                'total_bytes' => 0,
                'free_bytes' => 0,
            ]];
        }

        return array_values($members);
    }

    /**
     * Wo liegt <quelle>/<pfad> im Tier? Erstes Ziel mit der Datei oder null.
     *
     * @param array<string,mixed> $target
     *
     * @return array{member:array<string,mixed>,path:string}|null
     */
    public static function locate(array $target, string $path): ?array
    {
        foreach (self::members($target) as $member) {
            $full = $member['root'] . '/' . $path;
            clearstatcache(true, $full);
            if (is_file($full)) {
                return ['member' => $member, 'path' => $full];
            }
        }

        return null;
    }

    /**
     * Alle vorhandenen Kopien von <quelle>/<pfad> im Tier (normalerweise eine).
     *
     * @param array<string,mixed> $target
     *
     * @return list<array{member:array<string,mixed>,path:string}>
     */
    public static function copies(array $target, string $path): array
    {
        $result = [];
        foreach (self::members($target) as $member) {
            $full = $member['root'] . '/' . $path;
            clearstatcache(true, $full);
            if (is_file($full)) {
                $result[] = ['member' => $member, 'path' => $full];
            }
        }

        return $result;
    }

    /**
     * Ziel fuer eine Datei der Groesse $size. Eine vorhandene Kopie bleibt auf
     * ihrem Ziel, solange dort Platz fuer die neue Version ist; sonst das erste
     * Ziel mit ausreichend Platz. Passt sie nirgends, das Ziel mit dem meisten
     * Platz (die Uebertragung meldet dann den Fehler).
     *
     * @param array<string,mixed> $target
     * @param array{member:array<string,mixed>,path:string}|null $current
     *
     * @return array<string,mixed>
     */
    public function place(array $target, int $size, ?array $current): array
    {
        $members = self::members($target);
        if (count($members) === 1) {
            return $members[0];
        }
        $currentId = $current === null ? null : (int) $current['member']['id'];
        $currentSize = 0;
        if ($current !== null) {
            $stat = @stat($current['path']);
            $currentSize = $stat === false ? 0 : (int) $stat['size'];
        }
        $best = null;
        $bestFree = PHP_INT_MIN;
        $ordered = $members;
        if ($currentId !== null) {
            // Bisheriges Ziel zuerst pruefen (kein unnoetiges Verschieben).
            usort($ordered, static fn (array $a, array $b): int => ((int) $b['id'] === $currentId) <=> ((int) $a['id'] === $currentId));
        }
        foreach ($ordered as $member) {
            $free = $this->free($member);
            if ($free === null) {
                return $member;
            }
            $need = $size - ((int) $member['id'] === $currentId ? $currentSize : 0);
            if ($free - self::reserve($member) >= $need) {
                return $member;
            }
            if ($free > $bestFree) {
                [$best, $bestFree] = [$member, $free];
            }
        }

        return $best ?? $members[0];
    }

    /**
     * Vermerkt geschriebene Bytes (fuer Ziele, deren Platz nicht live messbar ist).
     */
    public function placed(int $memberId, int $bytes): void
    {
        $this->placed[$memberId] = ($this->placed[$memberId] ?? 0) + $bytes;
    }

    /**
     * Freier Platz eines Ziels (null = ohne Grenze).
     *
     * @param array<string,mixed> $member
     */
    public function free(array $member): ?int
    {
        $id = (int) $member['id'];
        if (($member['kind'] ?? StorageService::KIND_SMB) === StorageService::KIND_S3) {
            if ((int) ($member['total_bytes'] ?? 0) <= 0) {
                return null;
            }

            return (int) $member['free_bytes'] - ($this->placed[$id] ?? 0);
        }
        $live = ($this->freeSpace)((string) $member['root']);

        return $live ?? (int) ($member['free_bytes'] ?? 0) - ($this->placed[$id] ?? 0);
    }

    /**
     * @param array<string,mixed> $member
     */
    public static function reserve(array $member): int
    {
        return min(self::RESERVE_MAX, max(self::RESERVE_MIN, intdiv(max(0, (int) ($member['total_bytes'] ?? 0)), 100)));
    }
}
