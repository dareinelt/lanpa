<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Repositories\OrvantaFlowRepository;
use App\Repositories\OrvantaRepository;
use App\Services\MailProxy\MailProxyRoute;

/**
 * Praesenz der Orvanta-Benutzer fuer das Nachrichtenfluss-Dashboard.
 *
 * "Aktiver Nutzer" ist, wer innerhalb von ACTIVE_WINDOW Sekunden Orvanta
 * benutzt hat. Die Aktivitaet wird an der einzigen Eintrittstelle der
 * Orvanta-Schnittstelle erfasst und ist damit unabhaengig davon, ob der
 * Zugriff ueber Exchange oder den SMTP-/IMAP-Proxy laeuft.
 *
 * Aus der Aktivitaet entsteht im SAMPLE_INTERVAL-Raster eine Probe der
 * aktiven Nutzer (nur Zaehler, keine Kennungen). Die Proben bilden den
 * Verlauf ueber 14/30/90/180/365 Tage.
 *
 * Die Aufbewahrung ist begrenzt: ACTIVITY_TTL fuer die Aktivitaetszeilen,
 * HISTORY_DAYS fuer die Proben. Geraeumt wird im Archivierungs-Worker.
 */
final class OrvantaPresenceService
{
    /** Zeitfenster, in dem eine Aktivitaet den Benutzer als aktiv zaehlt (Sekunden). */
    public const ACTIVE_WINDOW = 300;

    /** Mindestabstand zweier Proben (Sekunden); bestimmt das Zeitraster. */
    public const SAMPLE_INTERVAL = 300;

    /** Aufbewahrung der Proben (Tage). */
    public const HISTORY_DAYS = 400;

    /** Aufbewahrung der Aktivitaetszeilen (Sekunden). */
    public const ACTIVITY_TTL = 86400;

    /** Zeitraeume der Verlaufsgrafik (Tage). */
    public const PERIODS = [14, 30, 90, 180, 365];

    /**
     * @param \Closure():int|null $clock liefert den aktuellen Zeitstempel
     *                                    (Tests); ohne Angabe gilt time()
     */
    public function __construct(
        private readonly OrvantaFlowRepository $repository,
        private readonly ?OrvantaRepository $ai = null,
        private readonly ?\Closure $clock = null
    ) {
    }

    /**
     * Backend-Schluessel fuer die Aktivitaetserfassung aus der Postfachaufloesung.
     */
    public static function backendFor(MailProxyRoute $route): string
    {
        return $route->isProxy() ? 'proxy' : 'exchange';
    }

    /**
     * Aktivitaet eines Benutzers erfassen (bei jedem Orvanta-Aufruf).
     */
    public function touch(string $userUid, string $backend = 'exchange'): void
    {
        if (trim($userUid) === '') {
            return;
        }
        $this->repository->touchActivity($userUid, $backend, $this->stamp($this->now()));
    }

    /**
     * Probe der aktiven Nutzer ablegen, wenn fuer das laufende Zeitraster noch
     * keine existiert. Bestehende Proben werden nie ueberschrieben.
     *
     * @return bool true, wenn eine neue Probe geschrieben wurde
     */
    public function sample(): bool
    {
        $now = $this->now();
        $sampledAt = $this->stamp($now - ($now % self::SAMPLE_INTERVAL));
        if ($this->repository->hasSampleAt($sampledAt)) {
            return false;
        }

        $active = $this->repository->activeUsers($this->stamp($now - self::ACTIVE_WINDOW), $this->stamp($now));

        return $this->repository->recordSample(
            $sampledAt,
            $active['total'],
            $active['exchange'],
            $active['proxy'],
            $this->aiUsers($now)
        );
    }

    /**
     * Kennzahlen der Nutzung: aktueller Wert aus der Aktivitaet, Minimum,
     * Maximum und Mittelwert aus den Proben der letzten 24 Stunden.
     *
     * @return array{current:int,exchange:int,proxy:int,min:int,max:int,avg:float,samples:int,window:int,generated_at:string}
     */
    public function stats(): array
    {
        $now = $this->now();
        $active = $this->repository->activeUsers($this->stamp($now - self::ACTIVE_WINDOW), $this->stamp($now));
        $samples = $this->repository->sampleStats($this->stamp($now - 86400), $this->stamp($now));

        return [
            'current' => $active['total'],
            'exchange' => $active['exchange'],
            'proxy' => $active['proxy'],
            'min' => $samples['samples'] > 0 ? $samples['min'] : $active['total'],
            'max' => $samples['samples'] > 0 ? $samples['max'] : $active['total'],
            'avg' => $samples['samples'] > 0 ? $samples['avg'] : (float) $active['total'],
            'samples' => $samples['samples'],
            'window' => self::ACTIVE_WINDOW,
            'generated_at' => $this->stamp($now),
        ];
    }

    /**
     * Tagesmaximum der aktiven Nutzer je Zeitraum. Jeder Zeitraum endet heute
     * und enthaelt genau so viele Punkte wie Tage; Tage ohne Probe haben den
     * Wert null (keine Daten, nicht "null Nutzer").
     *
     * @return array{periods:list<int>,series:array<int,array{days:int,from:string,to:string,max:int,avg:float,samples:int,points:list<array{day:string,value:int|null}>}>,max:int}
     */
    public function history(): array
    {
        $now = $this->now();
        $series = [];
        $overall = 0;
        foreach (self::PERIODS as $days) {
            $from = $this->dayStart($now - ($days - 1) * 86400);
            $peaks = $this->repository->dailyPeaks($from, $this->stamp($now));
            $points = [];
            $values = [];
            for ($offset = $days - 1; $offset >= 0; $offset--) {
                $day = date('Y-m-d', $now - $offset * 86400);
                $value = $peaks[$day] ?? null;
                if ($value !== null) {
                    $values[] = $value;
                    $overall = max($overall, $value);
                }
                $points[] = ['day' => $day, 'value' => $value];
            }
            $series[$days] = [
                'days' => $days,
                'from' => $from,
                'to' => $this->stamp($now),
                'max' => $values === [] ? 0 : max($values),
                'avg' => $values === [] ? 0.0 : round(array_sum($values) / count($values), 1),
                'samples' => count($values),
                'points' => $points,
            ];
        }

        return ['periods' => self::PERIODS, 'series' => $series, 'max' => $overall];
    }

    /**
     * Alte Aktivitaetszeilen und Proben entfernen.
     *
     * @return array{activity:int,samples:int}
     */
    public function purge(): array
    {
        $now = $this->now();

        return $this->repository->purge(
            $this->stamp($now - self::ACTIVITY_TTL),
            $this->stamp($now - self::HISTORY_DAYS * 86400)
        );
    }

    /**
     * Nutzer mit KI-Nutzung im Aktivitaetsfenster (0 ohne KI-Datenquelle).
     */
    private function aiUsers(int $now): int
    {
        if ($this->ai === null) {
            return 0;
        }
        try {
            return $this->ai->aiTokenTotals($this->stamp($now - self::ACTIVE_WINDOW), $this->stamp($now))['users'];
        } catch (\Throwable) {
            // Die Praesenz ist nachrangig; ohne KI-Zaehler bleibt der Wert 0.
            return 0;
        }
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }

    private function stamp(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function dayStart(int $timestamp): string
    {
        return date('Y-m-d 00:00:00', $timestamp);
    }
}
