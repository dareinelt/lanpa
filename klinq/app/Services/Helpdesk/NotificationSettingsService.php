<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\SettingsService;

/**
 * Benachrichtigungseinstellungen des Help-Desk (Help-Desk-Einstellungen → „Benachrichtigungen“).
 *
 * Legt je Aktion im Ticket fest, ob der Melder eine E-Mail erhält. Gespeichert wird ein Schalter
 * ('1'/'0') pro Ereignis unter dem Präfix `helpdesk.notification.` in der `settings`-Tabelle.
 * Ohne gespeicherten Wert bleibt die Benachrichtigung aktiv, damit bestehende Installationen
 * unverändert weiterversenden. Unbekannte Ereignisse – etwa ein später angelegter Statuscode –
 * gelten ebenfalls als aktiv.
 */
final class NotificationSettingsService
{
    /** Präfix der gespeicherten Einstellungen in der `settings`-Tabelle. */
    public const PREFIX = 'helpdesk.notification.';

    /**
     * Ereignisse mit Beschriftung, Erläuterung und Gruppe für die Administration.
     * `created` und `comment` sind eigene Aktionen, alle übrigen Schlüssel sind Statuscodes
     * (`TicketWorkflowService`), bei deren Erreichen der Melder benachrichtigt wird.
     *
     * @var array<string,array{label:string,description:string,group:string}>
     */
    public const EVENTS = [
        'created' => [
            'label' => 'Ticket angelegt',
            'description' => 'Eingangsbestätigung bzw. Info-Mail an den Melder, wenn ein Ticket angelegt wird – auch über Störungsformular und Störungspostfach.',
            'group' => 'Allgemein',
        ],
        'comment' => [
            'label' => 'Neue Antwort',
            'description' => 'Öffentliche Antwort eines Agenten an den Melder.',
            'group' => 'Allgemein',
        ],
        'new' => [
            'label' => 'Neu',
            'description' => 'Statuswechsel zurück auf „Neu“ (etwa nach dem Wiedereröffnen).',
            'group' => 'Statuswechsel',
        ],
        'open' => [
            'label' => 'Offen',
            'description' => 'Statuswechsel zu „Offen“.',
            'group' => 'Statuswechsel',
        ],        'in_progress' => [
            'label' => 'In Bearbeitung',
            'description' => 'Statuswechsel zu „In Bearbeitung“, auch beim Wiedereröffnen eines Tickets.',
            'group' => 'Statuswechsel',
        ],
        'waiting_feedback' => [
            'label' => 'Warten auf Rückmeldung',
            'description' => 'Statuswechsel zu „Warten auf Rückmeldung“ (Pausierung mit Frist).',
            'group' => 'Statuswechsel',
        ],
        'waiting_third_party' => [
            'label' => 'Warten auf Dritte',
            'description' => 'Statuswechsel zu „Warten auf Dritte“.',
            'group' => 'Statuswechsel',
        ],
        'waiting_delivery' => [
            'label' => 'Warten auf Lieferung',
            'description' => 'Statuswechsel zu „Warten auf Lieferung“.',
            'group' => 'Statuswechsel',
        ],
        'waiting_internal' => [
            'label' => 'Warten auf interne Rücksprache',
            'description' => 'Statuswechsel zu „Warten auf interne Rücksprache“.',
            'group' => 'Statuswechsel',
        ],
        'resolved' => [
            'label' => 'Gelöst',
            'description' => 'Statuswechsel zu „Gelöst“; die Lösung steht in der Mail, sofern sie im Ticket erfasst ist.',
            'group' => 'Statuswechsel',
        ],
        'closed' => [
            'label' => 'Geschlossen',
            'description' => 'Schließung des Tickets – mit dem Schließungstext aus den Vorlageneinstellungen, sofern hinterlegt. Wird das Ticket beim Umwandeln in eine Aufgabe geschlossen, gilt der Umwandlungstext.',
            'group' => 'Statuswechsel',
        ],
        'cancelled' => [
            'label' => 'Storniert',
            'description' => 'Stornierung des Tickets.',
            'group' => 'Statuswechsel',
        ],
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    /**
     * Aktuelle Schalter je Ereignis (Standard: aktiv).
     *
     * @return array<string,bool>
     */
    public function all(): array
    {
        $stored = $this->settings->withPrefix(self::PREFIX);
        $out = [];
        foreach (array_keys(self::EVENTS) as $event) {
            $out[$event] = self::isEnabledFrom($stored, $event);
        }

        return $out;
    }

    /** Ist die E-Mail an den Melder für dieses Ereignis aktiv? */
    public function enabled(string $event): bool
    {
        if (!isset(self::EVENTS[$event])) {
            return true;
        }

        return $this->all()[$event];
    }

    /**
     * Schalterwert aus den gespeicherten Rohwerten (ohne Präfix) lesen.
     * Unbekannte Ereignisse und noch nie gespeicherte Schalter gelten als aktiv.
     *
     * @param array<string,mixed> $stored
     */
    public static function isEnabledFrom(array $stored, string $event): bool
    {
        if (!isset(self::EVENTS[$event])) {
            return true;
        }

        return !array_key_exists($event, $stored) || (string) $stored[$event] !== '0';
    }

    /**
     * Formulareingabe (`events[]`) in die zu speichernden Werte übersetzen.
     * Unbekannte Ereignisse werden ignoriert, fehlende Häkchen als '0' gespeichert.
     *
     * @param mixed $input Rohwert des Formularfelds `events`
     * @return array<string,string> Schlüssel mit Präfix → '1' oder '0'
     */
    public static function valuesFromInput(mixed $input): array
    {
        $selected = is_array($input) ? array_map(static fn (mixed $value): string => (string) $value, $input) : [];
        $values = [];
        foreach (array_keys(self::EVENTS) as $event) {
            $values[self::PREFIX . $event] = in_array($event, $selected, true) ? '1' : '0';
        }

        return $values;
    }

    /**
     * Schalter speichern; protokolliert die Änderung im Audit-Log.
     *
     * @param array<string,mixed> $input Rohdaten aus dem Formular (`events[]`)
     * @return array<string,bool>
     */
    public function save(array $input): array
    {
        $this->currentUser->require('helpdesk.admin');
        $before = $this->all();

        $this->settings->setMany(self::valuesFromInput($input['events'] ?? []), $this->currentUser->id());

        $after = $this->all();
        $this->audit->log('update', 'settings', null, 'Help-Desk-Benachrichtigungen', $before, $after);

        return $after;
    }
}
