<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Repositories\TaskRepository;

/**
 * Vergibt Aufgabennummern im Format PREFIX-JJJJ-NNNNNN (z. B. AUF-2026-000001).
 * Die laufende Nummer liegt in derselben Sequenztabelle wie die Ticketnummern, wird aber über
 * ein eigenes Präfix getrennt gezählt. Der Aufruf muss innerhalb der Transaktion erfolgen,
 * die auch die Aufgabe anlegt.
 */
final class TaskNumberService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly Config $config
    ) {}

    public function prefix(): string
    {
        $prefix = strtoupper(trim((string) $this->config->get('helpdesk.task_prefix', 'AUF')));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix) ?? '';

        return $prefix !== '' ? mb_substr($prefix, 0, 10) : 'AUF';
    }

    /** Nächste freie Aufgabennummer für das (UTC-)Jahr des Zeitpunkts. */
    public function next(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $year = $now->format('Y');
        $prefix = $this->prefix();
        for ($i = 0; $i < 100; $i++) {
            $number = TicketNumberService::format($prefix, $year, $this->tasks->nextSequence($prefix, $year));
            if (!$this->tasks->numberExists($number)) {
                return $number;
            }
        }
        throw new \RuntimeException('Es konnte keine freie Aufgabennummer vergeben werden.');
    }
}
