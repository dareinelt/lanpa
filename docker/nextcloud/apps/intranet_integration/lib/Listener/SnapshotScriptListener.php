<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Listener;

use OCA\IntranetIntegration\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Util;

/**
 * Laedt in der Dateien-App die Aktion "Vorgängerversionen" (js/snapshots.js)
 * fuer Administratoren, sofern das Speicher-Tiering eingerichtet ist. Die
 * Berechtigung wird serverseitig (SnapshotsController) erneut geprueft.
 *
 * @template-implements IEventListener<Event>
 */
class SnapshotScriptListener implements IEventListener {
    public function __construct(
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
    }

    public function handle(Event $event): void {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return;
        }
        $tiering = \OCP\Server::get(Application::class)->tieringClient();
        if ($tiering === null || !$tiering->enabled()) {
            return;
        }
        Util::addStyle(Application::APP_ID, 'snapshots');
        Util::addScript(Application::APP_ID, 'snapshots');
    }
}
