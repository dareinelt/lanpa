<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Listener;

use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Service\QuotaService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * Setzt bei jeder Anmeldung das Kontingent aus dem Intranet (fuer Konten,
 * die Nextcloud bei der letzten Uebergabe noch nicht kannte).
 *
 * @template-implements IEventListener<Event>
 */
class QuotaLoginListener implements IEventListener {
    public function __construct(
        private QuotaService $quota,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof PostLoginEvent) {
            return;
        }

        try {
            $this->quota->applyForUser($event->getUser());
        } catch (\Throwable $e) {
            // Die Anmeldung darf daran nie scheitern.
            $this->logger->warning('Kontingent konnte bei der Anmeldung nicht gesetzt werden.', [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);
        }
    }
}
