<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\AppInfo;

use OCA\IntranetIntegration\Listener\AdminsLoginListener;
use OCA\IntranetIntegration\Listener\FooterListener;
use OCA\IntranetIntegration\Listener\NetworkDrivesScriptListener;
use OCA\IntranetIntegration\Listener\QuotaLoginListener;
use OCA\IntranetIntegration\Listener\RecallScriptListener;
use OCA\IntranetIntegration\Listener\SnapshotScriptListener;
use OCA\IntranetIntegration\Storage\TieringClient;
use OCA\IntranetIntegration\Storage\TieringWrapper;
use OC\Files\Filesystem;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\User\Events\PostLoginEvent;
use OCP\Util;

class Application extends App implements IBootstrap {
    public const APP_ID = 'intranet_integration';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Alle ueber das AppFramework gerenderten Seiten (Dateien, Editor,
        // Freigaben, Einstellungen ...) sowie die Anmeldeseite.
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, FooterListener::class);
        $context->registerEventListener(BeforeLoginTemplateRenderedEvent::class, FooterListener::class);
        // Speicherplatz-Kontingente aus dem Intranet fuer neu bekannte Konten.
        $context->registerEventListener(PostLoginEvent::class, QuotaLoginListener::class);
        // Nextcloud-Administratoren aus AD-Gruppen fuer neu bekannte Konten.
        $context->registerEventListener(PostLoginEvent::class, AdminsLoginListener::class);
        // Einstellung "Netzlaufwerke anzeigen" im Dateien-App.
        $context->registerEventListener('OCA\\Files\\Event\\LoadAdditionalScriptsEvent', NetworkDrivesScriptListener::class);
        // Speicher-Tiering: Fortschritt der Rueckholung aus dem Cold-Tier.
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, RecallScriptListener::class);
        // Snapshot-Speicher: Rechtsklick "Vorgängerversionen" fuer Administratoren.
        $context->registerEventListener('OCA\\Files\\Event\\LoadAdditionalScriptsEvent', SnapshotScriptListener::class);
        // Speicher-Tiering (storage-sync): ausgelagerte Dateien bei Zugriff zurueckholen.
        Util::connectHook('OC_Filesystem', 'preSetup', $this, 'addTieringWrapper');
    }

    /**
     * Haengt den Tiering-Wrapper an die lokalen Speicher (Home-Verzeichnisse),
     * sobald storage-sync das gemeinsame Verzeichnis eingerichtet hat.
     *
     * @internal
     */
    public function addTieringWrapper(): void {
        static $added = false;
        if ($added) {
            return;
        }
        $added = true;
        $tiering = $this->tieringClient();
        if ($tiering === null || !$tiering->enabled()) {
            return;
        }
        // Hohe Prioritaet: direkt um den lokalen Speicher (innerste Schicht).
        Filesystem::addStorageWrapper(
            'intranet_tiering',
            static fn (string $mountPoint, IStorage $storage, IMountPoint $mount): IStorage => TieringWrapper::wrap($tiering, $storage),
            1000
        );
    }

    public function tieringClient(): ?TieringClient {
        static $client = false;
        if ($client !== false) {
            return $client;
        }
        $config = $this->getContainer()->get(IConfig::class);
        $settings = $config->getSystemValue(self::APP_ID, []);
        $base = is_array($settings) && is_string($settings['tiering_dir'] ?? null) ? $settings['tiering_dir'] : TieringClient::DEFAULT_BASE;
        $dataDir = rtrim((string) $config->getSystemValueString('datadirectory', \OC::$SERVERROOT . '/data'), '/');
        $client = is_dir($base) && $dataDir !== '' ? new TieringClient($base, $dataDir) : null;

        return $client;
    }

    public function boot(IBootContext $context): void {
        $context->injectFn($this->redirectLoginToIntranet(...));
    }

    /**
     * Anmeldeseite -> Intranet-Einstieg: Das Intranet kennt den Benutzer
     * (Windows-Anmeldung) und reicht ihn per Einmal-Token an den
     * SSO-Endpunkt weiter. So ist beim Wechsel nach Nextcloud keine erneute
     * Kennworteingabe noetig. "?direct=1" zeigt weiterhin das Formular.
     */
    public function redirectLoginToIntranet(IRequest $request, IUserSession $userSession, IConfig $config, IURLGenerator $urlGenerator): void {
        if (PHP_SAPI === 'cli' || $request->getMethod() !== 'GET') {
            return;
        }

        $settings = $config->getSystemValue(self::APP_ID, []);
        $settings = is_array($settings) ? $settings : [];
        if (!filter_var($settings['sso_login_redirect'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        try {
            if ($request->getPathInfo() !== '/login' || $userSession->isLoggedIn()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        if ((string) $request->getParam('direct', '') === '1') {
            return;
        }

        $entry = is_string($settings['intranet_entry'] ?? null) ? $settings['intranet_entry'] : '/office-starten';
        if (!str_starts_with($entry, '/') || str_starts_with($entry, '//')) {
            $entry = '/office-starten';
        }
        $target = (string) $request->getParam('redirect_url', '');
        $location = $entry . ($target !== '' ? '?' . http_build_query(['ziel' => $target]) : '?ziel=' . rawurlencode(rtrim($urlGenerator->getWebroot(), '/') . '/'));

        header('Location: ' . $location, true, 302);
        header('Cache-Control: no-store');
        exit();
    }
}
