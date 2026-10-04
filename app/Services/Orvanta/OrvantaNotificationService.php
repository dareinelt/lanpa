<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Repositories\OrvantaRepository;

/**
 * Terminerinnerungen: gleicht anstehende Exchange-Termine mit der lokalen
 * Tabelle orvanta_reminders ab, ermittelt faellige Erinnerungen fuer die
 * Browser-Benachrichtigung (HTML5 Notifications API) und liefert die aktiven
 * Erinnerungen fuer das Mitteilungssystem im Kopfbereich des Intranets.
 */
final class OrvantaNotificationService
{
    public const SYNC_WINDOW_HOURS = 48;

    public function __construct(
        private readonly OrvantaRepository $repository,
        private readonly OrvantaExchangeService $exchange,
        private readonly OrvantaConfigService $config
    ) {
    }

    /**
     * Synchronisiert die Erinnerungen des Benutzers mit Exchange. Fehler der
     * Exchange-Abfrage werden als Meldung zurueckgegeben, nicht geworfen.
     */
    public function sync(string $uid, string $impersonate, ?int $now = null): ?string
    {
        $now ??= time();
        try {
            $events = $this->exchange->upcomingReminders($impersonate, $now, self::SYNC_WINDOW_HOURS);
        } catch (OrvantaException $exception) {
            return $exception->getMessage();
        }
        $lead = $this->config->reminderLeadMinutes();
        $items = [];
        foreach ($events as $event) {
            if ($event['end'] > 0 && $event['end'] < $now) {
                continue;
            }
            $remindAt = $event['remind_at'];
            if ($lead > 0 && $event['start'] - $lead * 60 < $remindAt) {
                $remindAt = $event['start'] - $lead * 60;
            }
            $items[] = [
                'item_id' => $event['id'],
                'subject' => mb_substr($event['subject'], 0, 255),
                'location' => mb_substr($event['location'], 0, 255),
                'starts_at' => self::sql($event['start']),
                'remind_at' => self::sql($remindAt),
            ];
        }
        $this->repository->syncReminders($uid, $items);
        $this->repository->purgeReminders(self::sql($now - 7 * 86400));

        return null;
    }

    /**
     * Faellige Erinnerungen werden als ausgeliefert markiert und zur Anzeige
     * zurueckgegeben (Browser-Notification), zusammen mit allen aktiven
     * Erinnerungen fuer die Kopfbereichs-Mitteilungen.
     *
     * @return array{due:list<array<string,mixed>>,active:list<array<string,mixed>>,server_time:int}
     */
    public function poll(string $uid, ?int $now = null): array
    {
        $now ??= time();
        $due = $this->repository->dueReminders($uid, self::sql($now));
        if ($due !== []) {
            $this->repository->markDelivered($uid, array_map(static fn (array $row): int => (int) $row['id'], $due), self::sql($now));
            $due = array_map(static fn (array $row): array => ['state' => 'delivered'] + $row, $due);
        }

        return [
            'due' => array_map(fn (array $row): array => $this->present($row, $now), $due),
            'active' => array_map(fn (array $row): array => $this->present($row, $now), $this->repository->activeReminders($uid)),
            'server_time' => $now,
        ];
    }

    public function dismiss(string $uid, int $id, ?int $now = null): bool
    {
        return $this->repository->dismissReminder($uid, $id, self::sql($now ?? time()));
    }

    public function snooze(string $uid, int $id, int $minutes, ?int $now = null): bool
    {
        $minutes = max(1, min(1440, $minutes));

        return $this->repository->snoozeReminder($uid, $id, self::sql(($now ?? time()) + $minutes * 60));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,item_id:string,subject:string,location:string,start:int,remind_at:int,state:string,relative:string}
     */
    private function present(array $row, int $now): array
    {
        $start = strtotime((string) $row['starts_at']) ?: 0;

        return [
            'id' => (int) $row['id'],
            'item_id' => (string) $row['item_id'],
            'subject' => (string) $row['subject'],
            'location' => (string) $row['location'],
            'start' => $start,
            'remind_at' => strtotime((string) $row['remind_at']) ?: 0,
            'state' => (string) $row['state'],
            'relative' => self::relative($start - $now),
        ];
    }

    public static function relative(int $seconds): string
    {
        if ($seconds <= -60) {
            $minutes = (int) floor(-$seconds / 60);

            return $minutes < 60 ? 'seit ' . $minutes . ' Min.' : 'seit ' . (int) floor($minutes / 60) . ' Std.';
        }
        if ($seconds < 60) {
            return 'jetzt';
        }
        $minutes = (int) ceil($seconds / 60);
        if ($minutes < 60) {
            return 'in ' . $minutes . ' Min.';
        }
        if ($minutes < 1440) {
            return 'in ' . (int) floor($minutes / 60) . ' Std.';
        }

        return 'in ' . (int) floor($minutes / 1440) . ' Tagen';
    }

    private static function sql(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
