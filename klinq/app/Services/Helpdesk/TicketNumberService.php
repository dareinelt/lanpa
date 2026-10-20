<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Repositories\TicketRepository;

/**
 * Vergibt Ticketnummern im Format PREFIX-JJJJ-NNNNNN (z. B. HD-2026-000001).
 * Die laufende Nummer wird je Präfix und Jahr transaktionssicher (SELECT ... FOR UPDATE) hochgezählt;
 * der Aufruf muss innerhalb der Transaktion erfolgen, die auch das Ticket anlegt.
 */
final class TicketNumberService
{
    public const PATTERN = '/^[A-Z0-9]{1,10}-\d{4}-\d{6,}$/';

    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly Config $config
    ) {}

    public function prefix(): string
    {
        $prefix = strtoupper(trim((string) $this->config->get('helpdesk.ticket_prefix', 'HD')));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix) ?? '';

        return $prefix !== '' ? mb_substr($prefix, 0, 10) : 'HD';
    }

    /** Nächste freie Ticketnummer für das (UTC-)Jahr des Zeitpunkts. */
    public function next(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $year = $now->format('Y');
        $prefix = $this->prefix();
        // Schutz gegen manuell importierte Nummern: bei Kollision weiterzählen
        for ($i = 0; $i < 100; $i++) {
            $number = self::format($prefix, $year, $this->tickets->nextSequence($prefix, $year));
            if (!$this->tickets->numberExists($number)) {
                return $number;
            }
        }
        throw new \RuntimeException('Es konnte keine freie Ticketnummer vergeben werden.');
    }

    public static function format(string $prefix, string $year, int $sequence): string
    {
        return sprintf('%s-%s-%06d', $prefix, $year, $sequence);
    }

    public static function isValid(string $number): bool
    {
        return preg_match(self::PATTERN, $number) === 1;
    }

    /** Normalisiert Benutzereingaben wie „hd-2026-12“ zu „HD-2026-000012“; null, wenn nicht interpretierbar. */
    public static function normalize(string $input): ?string
    {
        $input = strtoupper(trim($input));
        if (preg_match('/^([A-Z0-9]{1,10})-(\d{4})-(\d{1,9})$/', $input, $m) === 1) {
            return self::format($m[1], $m[2], (int) $m[3]);
        }

        return null;
    }
}
