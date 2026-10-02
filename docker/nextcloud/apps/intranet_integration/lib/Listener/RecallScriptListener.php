<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Listener;

use OCA\IntranetIntegration\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Laedt die Fortschrittsanzeige fuer Rueckholungen aus dem Cold-Tier
 * (js/recall.js), sofern das Speicher-Tiering eingerichtet ist.
 *
 * @template-implements IEventListener<Event>
 */
class RecallScriptListener implements IEventListener {
    private static bool $added = false;

    public function __construct(
        private IUserSession $userSession,
    ) {
    }

    public function handle(Event $event): void {
        if (self::$added || !$this->userSession->isLoggedIn()) {
            return;
        }
        $app = \OCP\Server::get(Application::class);
        $tiering = $app->tieringClient();
        if ($tiering === null || !$tiering->enabled()) {
            return;
        }
        self::$added = true;
        Util::addStyle(Application::APP_ID, 'recall');
        Util::addScript(Application::APP_ID, 'recall');
    }
}
