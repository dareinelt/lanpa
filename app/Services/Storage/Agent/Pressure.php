<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Fuellstand des Hot-Tiers (lokales Storage) mit Hysterese:
 *
 * - "normal": Vorhaltung nach Alter, Zugriffen und Dateigroesse.
 * - "remote_only": Der Hot-Tier ist voll – neue und selten genutzte Daten
 *   liegen nur noch im Cold-Tier (SMB-/S3-Tier), bis wieder genug Platz ist.
 *
 * Mit Limit (MB) zaehlt die Belegung der lokal vorgehaltenen Daten, ohne
 * Limit allein der freie Platz des Datentraegers.
 */
final class Pressure
{
    public const NORMAL = 'normal';
    public const REMOTE_ONLY = 'remote_only';

    /** Freier Platz (Anteil) – automatische Begrenzung. */
    private const AUTO_HIGH_FREE = 0.10;
    private const AUTO_LOW_FREE = 0.15;

    /** Mit Limit: immer mindestens so viel frei lassen. */
    private const LIMIT_HIGH_FREE = 0.05;
    private const LIMIT_LOW_FREE = 0.10;
    private const LIMIT_LOW_RATIO = 0.90;

    public function __construct(
        private readonly int $limitBytes,
        private readonly int $localBytes,
        private readonly int $diskTotal,
        private readonly int $diskFree
    ) {
    }

    /**
     * @return array{mode:string,reason:string}
     */
    public function next(string $current): array
    {
        if ($current === self::REMOTE_ONLY) {
            return $this->belowLow()
                ? ['mode' => self::NORMAL, 'reason' => 'Wieder genug Platz im Hot-Tier.']
                : ['mode' => self::REMOTE_ONLY, 'reason' => $this->reason()];
        }

        return $this->aboveHigh()
            ? ['mode' => self::REMOTE_ONLY, 'reason' => $this->reason()]
            : ['mode' => self::NORMAL, 'reason' => ''];
    }

    public function aboveHigh(): bool
    {
        if ($this->limitBytes > 0) {
            return $this->localBytes > $this->limitBytes || $this->freeRatio() < self::LIMIT_HIGH_FREE;
        }

        return $this->freeRatio() < self::AUTO_HIGH_FREE;
    }

    public function belowLow(): bool
    {
        if ($this->limitBytes > 0) {
            return $this->localBytes <= (int) ($this->limitBytes * self::LIMIT_LOW_RATIO) && $this->freeRatio() >= self::LIMIT_LOW_FREE;
        }

        return $this->freeRatio() >= self::AUTO_LOW_FREE;
    }

    /**
     * Wie viele Byte muessen ausgelagert werden, um unter die untere Schwelle zu kommen?
     */
    public function bytesToFree(): int
    {
        $needed = 0;
        if ($this->limitBytes > 0) {
            $needed = max(0, $this->localBytes - (int) ($this->limitBytes * self::LIMIT_LOW_RATIO));
            $minFree = self::LIMIT_LOW_FREE;
        } else {
            $minFree = self::AUTO_LOW_FREE;
        }
        if ($this->diskTotal > 0) {
            $needed = max($needed, (int) ($this->diskTotal * $minFree) - $this->diskFree);
        }

        return max(0, $needed);
    }

    /**
     * Darf eine Datei dieser Groesse (wieder) in den Hot-Tier, ohne die
     * Schwellen zu erreichen? Mit Sicherheitsabstand gegen Pendeln.
     */
    public function roomFor(int $size): bool
    {
        $free = $this->diskFree - $size;
        if ($this->diskTotal > 0 && $free < $this->diskTotal * ($this->limitBytes > 0 ? self::LIMIT_LOW_FREE : self::AUTO_LOW_FREE) + $this->diskTotal * 0.02) {
            return false;
        }

        return $this->limitBytes <= 0 || $this->localBytes + $size <= (int) ($this->limitBytes * (self::LIMIT_LOW_RATIO - 0.05));
    }

    public function withChange(int $deltaLocal): self
    {
        return new self($this->limitBytes, $this->localBytes + $deltaLocal, $this->diskTotal, $this->diskFree - $deltaLocal);
    }

    private function freeRatio(): float
    {
        return $this->diskTotal > 0 ? $this->diskFree / $this->diskTotal : 1.0;
    }

    private function reason(): string
    {
        $free = $this->diskTotal > 0 ? number_format($this->freeRatio() * 100, 1, ',', '.') . ' % frei' : 'frei unbekannt';
        if ($this->limitBytes > 0) {
            return sprintf(
                'Hot-Tier belegt %s von %s (Limit), Datenträger %s',
                \App\Services\Storage\StorageHealth::formatBytes($this->localBytes),
                \App\Services\Storage\StorageHealth::formatBytes($this->limitBytes),
                $free
            );
        }

        return 'Datenträger des Hot-Tiers nur noch ' . $free;
    }
}
