<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Repositories\OrvantaRepository;

/**
 * Terminerinnerungen: gleicht anstehende Termine mit der lokalen Tabelle
 * orvanta_reminders ab – aus Exchange (EWS) oder aus dem lokalen Kalender der
 * Proxy-Postfaecher –, ermittelt faellige Erinnerungen fuer die
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
        $this->apply($uid, $events, $now);

        return null;
    }

    /**
     * Synchronisiert die Erinnerungen aus dem lokalen Kalender
     * (Proxy-Postfaecher). Die Termine kommen aus der eigenen Datenbank, es
     * wird also nie Exchange befragt; die Ablage bleibt dieselbe wie bei
     * Exchange, damit Anzeige, Verschieben (Schlummern) und Ausblenden
     * unveraendert funktionieren.
     *
     * @param list<array<string,mixed>> $events Termine in der Form von
     *        OrvantaCalendarService::upcomingReminders()
     */
    public function syncLocal(string $uid, array $events, ?int $now = null): void
    {
        $this->apply($uid, $events, $now ?? time());
        // Der lokale Kalender ist die einzige Quelle fuer seine Termine: geloeschte
        // Termine duerfen nicht weiter erinnern. Die Liste ist dabei massgeblich.
        $this->repository->pruneLocalReminders($uid, array_map(
            static fn (array $event): string => (string) $event['id'],
            $events
        ));
    }

    /**
     * Uebernimmt anstehende Termine in die Erinnerungstabelle. Abgesagte und
     * abgelehnte Termine werden entfernt, eigene Erinnerungen haben Vorrang vor
     * der allgemeinen Vorlaufzeit (0 = keine Benachrichtigung).
     *
     * @param list<array<string,mixed>> $events
     */
    private function apply(string $uid, array $events, int $now): void
    {
        $lead = $this->config->reminderLeadMinutes();
        $items = [];
        $cancelled = [];
        foreach ($events as $event) {
            // Abgesagte Termine und abgelehnte Besprechungen erinnern nicht mehr.
            if (!empty($event['is_cancelled']) || $event['my_response'] === 'Decline') {
                $cancelled[] = $event['id'];
                continue;
            }
            if ($event['end'] > 0 && $event['end'] < $now) {
                continue;
            }
            // Eigene Exchange-Erinnerung hat Vorrang; die Vorlaufzeit gilt nur
            // fuer Termine ohne hinterlegte Erinnerung (0 = keine Benachrichtigung).
            if (!empty($event['reminder_set'])) {
                $remindAt = (int) $event['remind_at'];
            } elseif ($lead > 0) {
                $remindAt = $event['start'] - $lead * 60;
            } else {
                continue;
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
        if ($cancelled !== []) {
            $this->repository->removeReminders($uid, $cancelled);
        }
        $this->repository->purgeReminders(self::sql($now - 7 * 86400));
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
