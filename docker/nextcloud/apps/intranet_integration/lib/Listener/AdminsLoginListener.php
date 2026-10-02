<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Listener;

use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Service\AdminsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * Vergibt bei der Anmeldung die Administratorrechte aus dem Intranet (fuer Konten,
 * die Nextcloud bei der letzten Uebergabe noch nicht kannte).
 *
 * @template-implements IEventListener<Event>
 */
class AdminsLoginListener implements IEventListener {
    public function __construct(
        private AdminsService $admins,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof PostLoginEvent) {
            return;
        }

        try {
            $this->admins->applyForUser($event->getUser());
        } catch (\Throwable $e) {
            // Die Anmeldung darf daran nie scheitern.
            $this->logger->warning('Administratorrechte konnten bei der Anmeldung nicht vergeben werden.', [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);
        }
    }
}
