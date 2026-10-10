<?php

declare(strict_types=1);

namespace App\Services\Topology;

/**
 * Statusmodell der Gesamt-Topologie.
 *
 * Genau sechs Zustaende sind zulaessig: ok, warn, error, off, unknown, stale.
 * Alle Zustandsangaben aus den beobachteten Modulen werden ueber
 * {@see self::fromSource()} auf dieses Modell abgebildet, damit die Oberflaeche
 * nur eine einzige, dokumentierte Skala kennt.
 *
 * Zwei Regeln sind hier fest verdrahtet, weil sie sonst leicht verloren gehen:
 * - "unbekannt" ist niemals gesund ({@see self::isHealthy()}).
 * - "abgeschaltet" ist kein Fehler ({@see self::isProblem()}).
 */
final class TopologyStatus
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const ERROR = 'error';
    public const OFF = 'off';
    public const UNKNOWN = 'unknown';
    public const STALE = 'stale';

    /** @var list<string> */
    public const STATES = [self::OK, self::WARN, self::ERROR, self::OFF, self::UNKNOWN, self::STALE];

    /**
     * Schweregrad fuer die Aggregation. Hoeher gewinnt.
     *
     * "unbekannt" und "veraltet" liegen ueber "abgeschaltet", aber unter "warn":
     * eine fehlende Messung darf eine Warnung nicht ueberdecken, ein bewusst
     * abgeschalteter Baustein aber auch keinen Fehler erzeugen.
     */
    private const RANK = [
        self::OK => 0,
        self::OFF => 1,
        self::STALE => 2,
        self::UNKNOWN => 3,
        self::WARN => 4,
        self::ERROR => 5,
    ];

    /**
     * Deutsche Beschriftungen fuer die Oberflaeche.
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return [
            self::OK => 'In Ordnung',
            self::WARN => 'Warnung',
            self::ERROR => 'Fehler',
            self::OFF => 'Abgeschaltet',
            self::UNKNOWN => 'Unbekannt',
            self::STALE => 'Veraltet',
        ];
    }

    public static function label(string $state): string
    {
        $labels = self::labels();

        return $labels[$state] ?? $state;
    }

    /**
     * Liefert den Zustand zurueck, wenn er zum Modell gehoert, sonst "unknown".
     * Unbekannte Werte werden nie stillschweigend als gesund behandelt.
     */
    public static function normalize(string $state): string
    {
        return in_array($state, self::STATES, true) ? $state : self::UNKNOWN;
    }

    public static function severity(string $state): int
    {
        return self::RANK[self::normalize($state)] ?? 0;
    }

    /**
     * Schwerster Zustand einer Menge. Leere Menge ergibt "unknown".
     *
     * @param iterable<string> $states
     */
    public static function worst(iterable $states): string
    {
        $worst = null;
        foreach ($states as $state) {
            $state = self::normalize((string) $state);
            if ($worst === null || self::severity($state) > self::severity($worst)) {
                $worst = $state;
            }
        }

        return $worst ?? self::UNKNOWN;
    }

    /**
     * Abbildung der Zustandsvokabeln aller angebundenen Module auf das
     * Topologie-Modell. Unbekannte Vokabeln ergeben "unknown" – nie "ok".
     */
    public static function fromSource(string $state): string
    {
        $key = strtolower(trim($state));
        $map = [
            // gesund
            'ok' => self::OK,
            'online' => self::OK,
            'up' => self::OK,
            'active' => self::OK,
            'ready' => self::OK,
            'healthy' => self::OK,
            'success' => self::OK,
            'valid' => self::OK,
            'in_sync' => self::OK,
            'syncing' => self::OK,
            'sent' => self::OK,
            'passed' => self::OK,
            'reachable' => self::OK,
            // Warnung
            'warn' => self::WARN,
            'warning' => self::WARN,
            'degraded' => self::WARN,
            'partial' => self::WARN,
            'lagging' => self::WARN,
            'blocked' => self::WARN,
            'paused' => self::WARN,
            'maintenance' => self::WARN,
            'queued' => self::WARN,
            'sending' => self::WARN,
            'expiring' => self::WARN,
            'starting' => self::WARN,
            // Fehler
            'error' => self::ERROR,
            'critical' => self::ERROR,
            'down' => self::ERROR,
            'offline' => self::ERROR,
            'failed' => self::ERROR,
            'failure' => self::ERROR,
            'unavailable' => self::ERROR,
            'unhealthy' => self::ERROR,
            'unreachable' => self::ERROR,
            'expired' => self::ERROR,
            'not-yet-valid' => self::ERROR,
            'invalid' => self::ERROR,
            // abgeschaltet
            'off' => self::OFF,
            'disabled' => self::OFF,
            'inactive' => self::OFF,
            'not_configured' => self::OFF,
            'none' => self::OFF,
            'skipped' => self::OFF,
            // veraltet
            'stale' => self::STALE,
            'outdated' => self::STALE,
            // unbekannt
            'unknown' => self::UNKNOWN,
            '' => self::UNKNOWN,
        ];

        return $map[$key] ?? self::UNKNOWN;
    }

    /**
     * Nur "ok" gilt als gesund. "abgeschaltet" ist bewusst nicht gesund, sonst
     * wuerde ein abgeschaltetes Modul als erfuellter Betrieb durchgehen.
     */
    public static function isHealthy(string $state): bool
    {
        return self::normalize($state) === self::OK;
    }

    /** Handlungsbedarf: nur echte Warnungen und Fehler. */
    public static function isProblem(string $state): bool
    {
        $state = self::normalize($state);

        return $state === self::ERROR || $state === self::WARN;
    }

    /** Nicht bewertet: der Zustand ist unbekannt oder zu alt. */
    public static function isUnrated(string $state): bool
    {
        $state = self::normalize($state);

        return $state === self::UNKNOWN || $state === self::STALE;
    }

    /**
     * Bewertet das Alter einer Messung. Ohne Messzeitpunkt bleibt der Zustand
     * unveraendert; ein zu alter Messwert wird auf "stale" gesetzt.
     *
     * @param string $state      abgeleiteter Zustand
     * @param ?int   $measuredAt Unix-Zeit der Messung, null wenn unbekannt
     */
    public static function fresh(string $state, ?int $measuredAt, int $now, int $staleAfter): string
    {
        $state = self::normalize($state);
        if ($measuredAt === null || $staleAfter <= 0) {
            return $state;
        }

        return $now - $measuredAt > $staleAfter ? self::STALE : $state;
    }
}
