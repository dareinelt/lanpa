<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\NotificationSettingsService;
use App\Services\Helpdesk\TicketWorkflowService;
use Tests\Support\TestCase;

/**
 * Benachrichtigungseinstellungen: Jede Aktion, die eine Mail an den Melder auslöst, ist
 * einzeln schaltbar; ohne gespeicherten Wert bleibt der Versand aktiv.
 */
final class NotificationSettingsTest extends TestCase
{
    public function testEveryEventIsDescribedForTheAdministration(): void
    {
        foreach (NotificationSettingsService::EVENTS as $event => $meta) {
            $this->assertTrue($meta['label'] !== '', 'Ereignis ohne Beschriftung: ' . $event);
            $this->assertTrue($meta['description'] !== '', 'Ereignis ohne Erläuterung: ' . $event);
            $this->assertTrue($meta['group'] !== '', 'Ereignis ohne Gruppe: ' . $event);
        }
    }

    public function testStatusEventsMatchTheWorkflowCodes(): void
    {
        $codes = [
            TicketWorkflowService::NEW,
            TicketWorkflowService::OPEN,
            TicketWorkflowService::IN_PROGRESS,
            TicketWorkflowService::WAITING_FEEDBACK,
            TicketWorkflowService::WAITING_THIRD_PARTY,
            TicketWorkflowService::WAITING_DELIVERY,
            TicketWorkflowService::WAITING_INTERNAL,
            TicketWorkflowService::RESOLVED,
            TicketWorkflowService::CLOSED,
            TicketWorkflowService::CANCELLED,
        ];
        foreach ($codes as $code) {
            $this->assertTrue(isset(NotificationSettingsService::EVENTS[$code]), 'Statuscode nicht schaltbar: ' . $code);
        }
        $this->assertTrue(isset(NotificationSettingsService::EVENTS['created']), 'Anlage des Tickets fehlt.');
        $this->assertTrue(isset(NotificationSettingsService::EVENTS['comment']), 'Antwort an den Melder fehlt.');
    }

    public function testUnstoredEventsStayEnabled(): void
    {
        $this->assertTrue(NotificationSettingsService::isEnabledFrom([], 'created'));
        $this->assertTrue(NotificationSettingsService::isEnabledFrom(['closed' => '1'], 'created'));
    }

    public function testStoredZeroTurnsEventOff(): void
    {
        $this->assertFalse(NotificationSettingsService::isEnabledFrom(['created' => '0'], 'created'));
        $this->assertTrue(NotificationSettingsService::isEnabledFrom(['created' => '1'], 'created'));
    }

    public function testUnknownEventsAreAlwaysEnabled(): void
    {
        $this->assertTrue(NotificationSettingsService::isEnabledFrom(['closed' => '0'], 'unbekannt'));
    }

    public function testInputIsStoredForEveryEventWithPrefix(): void
    {
        $values = NotificationSettingsService::valuesFromInput(['created', 'closed']);

        foreach (array_keys(NotificationSettingsService::EVENTS) as $event) {
            $this->assertTrue(array_key_exists(NotificationSettingsService::PREFIX . $event, $values), 'Schalter fehlt: ' . $event);
        }
        $this->assertSame('1', $values[NotificationSettingsService::PREFIX . 'created']);
        $this->assertSame('1', $values[NotificationSettingsService::PREFIX . 'closed']);
        $this->assertSame('0', $values[NotificationSettingsService::PREFIX . 'comment']);
    }

    public function testMissingOrUnknownInputDisablesNothingUnexpected(): void
    {
        $values = NotificationSettingsService::valuesFromInput(null);

        $this->assertSame('0', $values[NotificationSettingsService::PREFIX . 'created'], 'Ohne Häkchen gilt der Schalter als aus.');
        $this->assertTrue(!array_key_exists(NotificationSettingsService::PREFIX . 'unbekannt', $values), 'Unbekannte Eingaben werden verworfen.');
    }
}
