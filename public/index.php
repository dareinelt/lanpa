<?php

declare(strict_types=1);

/**
 * Front-Controller. Alle HTTP-Anfragen laufen hier hinein.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\AdminUserController;
use App\Controllers\Admin\ActivationNumberController;
use App\Controllers\Admin\AlarmController;
use App\Controllers\Admin\AlarmGroupController;
use App\Controllers\Admin\AnnouncementController;
use App\Controllers\Admin\CertificateController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\DescriptionController;
use App\Controllers\Admin\DesignController;
use App\Controllers\Admin\EmergencyNumberController;
use App\Controllers\Admin\ImportExportController;
use App\Controllers\Admin\ImportantLinkController;
use App\Controllers\Admin\IncidentController;
use App\Controllers\Admin\LdapController;
use App\Controllers\Admin\MailProxyController;
use App\Controllers\Admin\NavigationController;
use App\Controllers\Admin\NetworkDriveController as NetworkDriveAdminController;
use App\Controllers\Admin\OfficeController as OfficeAdminController;
use App\Controllers\Admin\OfficeAppsController as OfficeAppsAdminController;
use App\Controllers\Admin\OrvantaHostController;
use App\Controllers\Admin\OrvantaFlowController;
use App\Controllers\Admin\OrvantaOofController;
use App\Controllers\Admin\OrvantaSignatureController;
use App\Controllers\Admin\OrvantaSharedMailboxController;
use App\Controllers\Admin\PhonebookAdminController;
use App\Controllers\Admin\SnmpController;
use App\Controllers\Admin\StatisticsController;
use App\Controllers\Admin\StorageQuotaController;
use App\Controllers\Admin\StorageController as StorageAdminController;
use App\Controllers\BackgroundImageController;
use App\Controllers\AlarmTriggerController;
use App\Controllers\ClickController;
use App\Controllers\HealthController;
use App\Controllers\ImportantLinkIconController;
use App\Controllers\InternalController;
use App\Controllers\KiController;
use App\Controllers\LandingController;
use App\Controllers\LogoController;
use App\Controllers\NetworkDriveController;
use App\Controllers\OfficeController;
use App\Controllers\OrvantaApiController;
use App\Controllers\OrvantaController;
use App\Controllers\PageController;
use App\Controllers\PhonebookController;
use App\Controllers\ProtectedAccessController;
use App\Controllers\SmsCodeController;
use App\Controllers\SsoController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Exceptions\HttpException;
use App\Security\Session;
use App\Security\SsoAuth;

$request = Request::fromGlobals();
Session::start($request->isSecure());

$nonce = base64_encode(random_bytes(16));
$GLOBALS['csp_nonce'] = $nonce;

$router = new Router();

// Serverseitige Zugriffssperre fuer geschuetzte interne Navigationselemente.
// Interne Elemente (z. B. /telefonliste) werden nicht ueber den PageController
// ausgeliefert, deshalb greift hier eine zusaetzliche Schranke.
$requireUnlocked = static function (Request $request): ?Response {
    if ($request->method !== 'GET') {
        return null;
    }

    $item = Container::smsCode()->findProtectedInternal($request->path);
    if ($item === null || Container::smsCode()->isVerified((int) $item['id'])) {
        return null;
    }

    return Response::redirect('/zugriff?id=' . (int) $item['id']);
};

// Freiwillige Windows-Anmeldung: Seitenaufrufe ohne erkannte Anmeldung werden
// einmal je Sitzung ueber den Anmeldepunkt geleitet (SSO_AUTO_LOGIN).
// Domaenen-Clients kommen angemeldet zurueck, alle anderen ohne Anmeldung –
// es gibt keine Anmeldepflicht.
$ssoAttempt = static function (Request $request): ?Response {
    if (!in_array($request->path, ['/', '/unterseite', '/seite', '/office-starten', '/office-app', '/office/orvanta'], true)) {
        return null;
    }

    $sso = Container::sso();
    if (!$sso->shouldAttempt($request)) {
        return null;
    }

    return Response::redirect(SsoAuth::loginUrl((string) ($request->server['REQUEST_URI'] ?? '/')))
        ->withHeader('Cache-Control', 'no-store');
};

$router->get('/sso', [SsoController::class, 'start']);
$router->get('/sso/anmelden', [SsoController::class, 'login']);
$router->get('/sso/nicht-erkannt', [SsoController::class, 'notRecognized']);
// Meldung der Netzlaufwerke durch das Anmeldeskript der Clients (Windows-
// Anmeldung im auth-Container, keine Sitzung/CSRF).
$router->post('/sso/laufwerke', [NetworkDriveController::class, 'report']);

$router->group([$requireUnlocked, $ssoAttempt], static function (Router $router): void {
    $router->get('/', [LandingController::class, 'index']);
    $router->get('/unterseite', [PageController::class, 'subpage']);
    $router->get('/seite', [PageController::class, 'page']);
    $router->get('/zugriff', [ProtectedAccessController::class, 'show']);
    $router->get('/telefonliste', [PhonebookController::class, 'index']);
    $router->get('/api/telefonliste', [PhonebookController::class, 'search']);
    $router->post('/api/klick', [ClickController::class, 'store']);
    $router->post('/api/alarm', [AlarmTriggerController::class, 'store']);
    $router->post('/api/sms-code/send', [SmsCodeController::class, 'send']);
    $router->post('/api/sms-code/verify', [SmsCodeController::class, 'verify']);
    $router->get('/logo', [LogoController::class, 'show']);
    $router->get('/hintergrundbild', [BackgroundImageController::class, 'show']);
    $router->get('/wichtige-links/icon', [ImportantLinkIconController::class, 'show']);
    $router->get('/health', [HealthController::class, 'index']);
    $router->get('/office-starten', [OfficeController::class, 'start']);
});

// Interne Schnittstelle fuer die auth-Container (Token + Absenderpruefung).
$router->get('/internal/sso-config', [InternalController::class, 'ssoConfig']);
$router->get('/internal/tls-config', [InternalController::class, 'tlsConfig']);
$router->post('/internal/auth-metrics', [InternalController::class, 'authMetrics']);

// Office-Integration: Hinweisseite (auch Fehlerseite des auth-Proxys) und
// Endpunkte fuer die Fusszeile in Nextcloud bzw. den Kachelstatus.
$router->get('/office-nicht-verfuegbar', [OfficeController::class, 'unavailable']);
$router->get('/api/office/footer', [OfficeController::class, 'footer']);
$router->get('/api/office/status', [OfficeController::class, 'status']);
// LLMInt unter /ki/: Fehlerseite des auth-Proxys, wenn LLMInt nicht erreichbar ist.
$router->get('/ki-nicht-verfuegbar', [KiController::class, 'unavailable']);
$router->group([$ssoAttempt], static function (Router $router): void {
    $router->get('/office-app', [OfficeController::class, 'launch']);
    $router->get('/office/orvanta', [OrvantaController::class, 'index']);
});

// Orvanta (Mail & Kalender, Exchange On-Premise): Anhang-Links und JSON-API.
// Zugriff: SSO-Benutzer mit App-Freigabe (OrvantaController::authorize).
$router->get('/office/orvanta/anhang/oeffnen', [OrvantaController::class, 'openAttachment']);
$router->get('/office/orvanta/anhang/datei', [OrvantaController::class, 'attachmentFile']); // Token-Zugriff des DocumentServers
$router->get('/api/orvanta/status', [OrvantaApiController::class, 'status']);
$router->get('/api/orvanta/sitzung', [OrvantaApiController::class, 'keepAlive']); // Keep-alive + aktuelles CSRF-Token
$router->get('/api/orvanta/abwesenheit', [OrvantaApiController::class, 'oof']); // Abwesenheitsnotiz (Exchange OOF)
$router->post('/api/orvanta/abwesenheit', [OrvantaApiController::class, 'saveOof']); // Abwesenheitsnotiz setzen/abschalten
$router->get('/api/orvanta/mail/ordner', [OrvantaApiController::class, 'folders']);
$router->get('/api/orvanta/mail/ordner/eigenschaften', [OrvantaApiController::class, 'folderProperties']);
$router->post('/api/orvanta/mail/ordner/neu', [OrvantaApiController::class, 'createFolder']);
$router->post('/api/orvanta/mail/ordner/gelesen', [OrvantaApiController::class, 'markFolderRead']);
$router->post('/api/orvanta/mail/kennwort', [OrvantaApiController::class, 'mailPassword']); // nur Proxy-Postfaecher: geaendertes Kennwort uebernehmen
$router->get('/api/orvanta/mail', [OrvantaApiController::class, 'messages']);
$router->get('/api/orvanta/mail/nachricht', [OrvantaApiController::class, 'message']);
$router->get('/api/orvanta/mail/kopfzeilen', [OrvantaApiController::class, 'messageHeaders']);
$router->post('/api/orvanta/mail/senden', [OrvantaApiController::class, 'send']);
$router->get('/api/orvanta/empfaenger', [OrvantaApiController::class, 'recipients']); // Vorschlaege fuer An/Cc/Bcc
$router->post('/api/orvanta/mail/entwurf', [OrvantaApiController::class, 'draft']);
$router->post('/api/orvanta/mail/antworten', [OrvantaApiController::class, 'respond']);
$router->post('/api/orvanta/mail/aktion', [OrvantaApiController::class, 'mailAction']);
$router->post('/api/orvanta/anhang/link', [OrvantaApiController::class, 'attachmentLink']);
$router->post('/api/orvanta/anhang/nextcloud', [OrvantaApiController::class, 'attachmentToNextcloud']);
$router->get('/api/orvanta/zwischenspeicher', [OrvantaApiController::class, 'cacheUsage']);
$router->post('/api/orvanta/zwischenspeicher/leeren', [OrvantaApiController::class, 'cacheClear']);
$router->get('/api/orvanta/archiv/status', [OrvantaApiController::class, 'archiveStatus']);
$router->get('/api/orvanta/archiv/ordner', [OrvantaApiController::class, 'archiveFolders']);
$router->get('/api/orvanta/archiv/mail', [OrvantaApiController::class, 'archiveMessages']);
$router->get('/api/orvanta/archiv/mail/detail', [OrvantaApiController::class, 'archiveMessage']);
$router->get('/api/orvanta/archiv/suche', [OrvantaApiController::class, 'archiveSearch']);
$router->get('/api/orvanta/kalender', [OrvantaApiController::class, 'calendar']);
$router->get('/api/orvanta/kalender/termin', [OrvantaApiController::class, 'event']);
$router->post('/api/orvanta/kalender/termin', [OrvantaApiController::class, 'saveEvent']);
$router->post('/api/orvanta/kalender/termin/verschieben', [OrvantaApiController::class, 'moveEvent']);
$router->post('/api/orvanta/kalender/termin/loeschen', [OrvantaApiController::class, 'deleteEvent']);
$router->post('/api/orvanta/kalender/antwort', [OrvantaApiController::class, 'meetingResponse']);
$router->post('/api/orvanta/kalender/postfach', [OrvantaApiController::class, 'setCalendarVisible']); // Kalender zusaetzlicher Postfaecher ein-/ausblenden
$router->get('/api/orvanta/kontakte', [OrvantaApiController::class, 'contacts']);
$router->get('/api/orvanta/kontakte/kontakt', [OrvantaApiController::class, 'contact']);
$router->post('/api/orvanta/kontakte/kontakt', [OrvantaApiController::class, 'saveContact']);
$router->post('/api/orvanta/kontakte/loeschen', [OrvantaApiController::class, 'deleteContact']);
$router->get('/api/orvanta/aufgaben', [OrvantaApiController::class, 'tasks']);
$router->get('/api/orvanta/aufgaben/aufgabe', [OrvantaApiController::class, 'task']);
$router->post('/api/orvanta/aufgaben/aufgabe', [OrvantaApiController::class, 'saveTask']);
$router->post('/api/orvanta/aufgaben/loeschen', [OrvantaApiController::class, 'deleteTask']);
$router->get('/api/orvanta/notizen', [OrvantaApiController::class, 'notes']);
$router->get('/api/orvanta/notizen/notiz', [OrvantaApiController::class, 'note']);
$router->post('/api/orvanta/notizen/notiz', [OrvantaApiController::class, 'saveNote']);
$router->post('/api/orvanta/notizen/loeschen', [OrvantaApiController::class, 'deleteNote']);
$router->get('/api/orvanta/erinnerungen', [OrvantaApiController::class, 'reminders']);
$router->post('/api/orvanta/erinnerungen/erledigt', [OrvantaApiController::class, 'dismissReminder']);
$router->post('/api/orvanta/erinnerungen/spaeter', [OrvantaApiController::class, 'snoozeReminder']);
$router->post('/api/orvanta/ki/verbessern', [OrvantaApiController::class, 'aiImprove']); // KI-Textunterstuetzung (globales Modell)
$router->post('/api/orvanta/rechtschreibung/pruefen', [OrvantaApiController::class, 'spellcheck']); // Deutsche Rechtschreibpruefung
$router->post('/api/orvanta/rechtschreibung/vorschlaege', [OrvantaApiController::class, 'spellcheckSuggest']);
$router->get('/api/orvanta/rechtschreibung/woerterbuch', [OrvantaApiController::class, 'spellcheckWords']); // Persoenliches Woerterbuch
$router->post('/api/orvanta/rechtschreibung/woerterbuch', [OrvantaApiController::class, 'spellcheckAddWord']);
$router->post('/api/orvanta/rechtschreibung/woerterbuch/entfernen', [OrvantaApiController::class, 'spellcheckRemoveWord']);

$router->get('/admin/login', [AuthController::class, 'showLogin']);
foreach (['/notfallplan' => 'index', '/notfallplan/plan' => 'plan', '/notfallplan/ereignis' => 'event', '/notfallplan/stand' => 'status', '/notfallplan/anleitung' => 'guide', '/notfallplan/anhang' => 'attachment'] as $path => $method) {
    $router->get($path, [\App\Controllers\EmergencyPlanController::class, $method]);
}
$router->post('/notfallplan/start', [\App\Controllers\EmergencyPlanController::class, 'start']);
$router->post('/notfallplan/massnahme', [\App\Controllers\EmergencyPlanController::class, 'update']);
foreach (['' => 'index', '/daten' => 'data', '/live' => 'stream', '/journal' => 'journal'] as $path => $method) {
    $router->get('/kaep-dashboard' . $path, [\App\Controllers\KaepDashboardController::class, $method]);
}
$router->post('/kaep-dashboard/aktion', [\App\Controllers\KaepDashboardController::class, 'update']);
$router->post('/admin/login', [AuthController::class, 'login']);
$router->get(AuthController::WINDOWS_LOGIN_PATH, [AuthController::class, 'windowsLogin']);

$requireAuth = static function (Request $request): ?Response {
    if (Container::auth()->check()) {
        if (Container::auth()->role() === \App\Security\Auth::ROLE_KAEP) {
            if ($request->path === '/admin') {
                return Response::redirect('/admin/notfallplan');
            }
            if ($request->path !== '/admin/logout' && !str_starts_with($request->path, '/admin/notfallplan')) {
                throw new HttpException(403, 'Das KAEP-Team hat ausschließlich Zugriff auf Notfallpläne.');
            }
        }
        return null;
    }

    if (str_starts_with($request->path, '/admin/api')) {
        return Response::json(['error' => 'Nicht angemeldet.'], 401);
    }

    return Response::redirect('/admin/login');
};

$requireAdmin = static function (Request $request): ?Response {
    if (Container::auth()->isAdmin()) {
        return null;
    }

    if (str_starts_with($request->path, '/admin/api')) {
        return Response::json(['error' => 'Kein Zugriff.'], 403);
    }

    throw new HttpException(403, 'Für diesen Bereich fehlt die Berechtigung.');
};

$router->group([$requireAuth], static function (Router $router) use ($requireAdmin): void {
    $requireKaep = static function (Request $request): ?Response {
        if (!\App\Services\EmergencyPlanService::isManager(Container::auth()->role())) {
            throw new HttpException(403, 'Nur Administratoren und das KAEP-Team haben Zugriff.');
        }
        return null;
    };
    $router->group([$requireKaep], static function (Router $router): void {
        foreach (['' => 'index', '/bearbeiten' => 'edit', '/gruppen' => 'groups', '/ereignis' => 'event', '/stand' => 'status', '/export' => 'export', '/anleitung' => 'guide', '/anhang' => 'attachment'] as $path => $method) {
            $router->get('/admin/notfallplan' . $path, [\App\Controllers\EmergencyPlanController::class, $method]);
        }
        $router->post('/admin/notfallplan/einstellungen', [\App\Controllers\EmergencyPlanController::class, 'settings']);
        $router->post('/admin/notfallplan/anhang', [\App\Controllers\EmergencyPlanController::class, 'uploadAttachment']);
        $router->post('/admin/notfallplan/speichern', [\App\Controllers\EmergencyPlanController::class, 'save']);
        $router->get('/admin/notfallplan/vorschau', [\App\Controllers\EmergencyPlanController::class, 'preview']);
        $router->post('/admin/notfallplan/vorschau', [\App\Controllers\EmergencyPlanController::class, 'previewRender']);
        $router->post('/admin/notfallplan/freigabe', [\App\Controllers\EmergencyPlanController::class, 'review']);
        $router->post('/admin/notfallplan/massnahme', [\App\Controllers\EmergencyPlanController::class, 'update']);
        // Export-Sätze (Teildateien ≤ 15 MB, Download oder Nextcloud) und Import für Administratoren und KAEP-Team.
        $router->post('/admin/notfallplan/plaene/export', [\App\Controllers\EmergencyPlanController::class, 'exportPlans']);
        $router->get('/admin/notfallplan/plaene/export/datei', [\App\Controllers\EmergencyPlanController::class, 'exportPart']);
        $router->post('/admin/notfallplan/plaene/export/nextcloud', [\App\Controllers\EmergencyPlanController::class, 'exportNextcloud']);
        $router->post('/admin/notfallplan/plaene/import', [\App\Controllers\EmergencyPlanController::class, 'importPlans']);
        $router->post('/admin/notfallplan/plaene/import/abschluss', [\App\Controllers\EmergencyPlanController::class, 'importFinish']);
    });
    $router->post('/admin/logout', [AuthController::class, 'logout']);

    $router->get('/admin', [DashboardController::class, 'index']);

    $router->get('/admin/wichtige-links', [ImportantLinkController::class, 'index']);
    $router->get('/admin/wichtige-links/neu', [ImportantLinkController::class, 'create']);
    $router->post('/admin/wichtige-links/neu', [ImportantLinkController::class, 'store']);
    $router->get('/admin/wichtige-links/bearbeiten', [ImportantLinkController::class, 'edit']);
    $router->post('/admin/wichtige-links/bearbeiten', [ImportantLinkController::class, 'update']);
    $router->post('/admin/wichtige-links/loeschen', [ImportantLinkController::class, 'delete']);
    $router->post('/admin/wichtige-links/status', [ImportantLinkController::class, 'toggle']);

    $router->group([$requireAdmin], static function (Router $router): void {
        $router->get('/admin/smtp', [\App\Controllers\Admin\SmtpController::class, 'index']);
        $router->post('/admin/smtp', [\App\Controllers\Admin\SmtpController::class, 'save']);
        $router->post('/admin/smtp/test', [\App\Controllers\Admin\SmtpController::class, 'test']);
        $router->get('/admin/navigation', [NavigationController::class, 'index']);
        $router->get('/admin/navigation/neu', [NavigationController::class, 'create']);
        $router->post('/admin/navigation/neu', [NavigationController::class, 'store']);
        $router->get('/admin/navigation/bearbeiten', [NavigationController::class, 'edit']);
        $router->post('/admin/navigation/bearbeiten', [NavigationController::class, 'update']);
        $router->post('/admin/navigation/loeschen', [NavigationController::class, 'delete']);
        $router->post('/admin/navigation/status', [NavigationController::class, 'toggle']);
        $router->post('/admin/navigation/sortieren', [NavigationController::class, 'move']);
        $router->post('/admin/navigation/ansicht', [NavigationController::class, 'updateViewMode']);
        $router->post('/admin/navigation/einstellungen', [NavigationController::class, 'updateSettings']);
        $router->get('/admin/navigation/berechtigungen', [NavigationController::class, 'permissions']);
        $router->post('/admin/navigation/berechtigungen', [NavigationController::class, 'storePermissions']);

        $router->get('/admin/notfallnummern', [EmergencyNumberController::class, 'index']);
        $router->get('/admin/notfallnummern/neu', [EmergencyNumberController::class, 'create']);
        $router->post('/admin/notfallnummern/neu', [EmergencyNumberController::class, 'store']);
        $router->get('/admin/notfallnummern/bearbeiten', [EmergencyNumberController::class, 'edit']);
        $router->post('/admin/notfallnummern/bearbeiten', [EmergencyNumberController::class, 'update']);
        $router->post('/admin/notfallnummern/loeschen', [EmergencyNumberController::class, 'delete']);
        $router->post('/admin/notfallnummern/status', [EmergencyNumberController::class, 'toggle']);
        $router->post('/admin/notfallnummern/sortieren', [EmergencyNumberController::class, 'move']);

        $router->get('/admin/telefonliste', [PhonebookAdminController::class, 'index']);
        $router->post('/admin/telefonliste/status', [PhonebookAdminController::class, 'toggle']);
        $router->post('/admin/telefonliste/export', [PhonebookAdminController::class, 'export']);

        $router->get('/admin/mitteilungen', [AnnouncementController::class, 'index']);
        $router->get('/admin/mitteilungen/neu', [AnnouncementController::class, 'create']);
        $router->post('/admin/mitteilungen/neu', [AnnouncementController::class, 'store']);
        $router->get('/admin/mitteilungen/bearbeiten', [AnnouncementController::class, 'edit']);
        $router->post('/admin/mitteilungen/bearbeiten', [AnnouncementController::class, 'update']);
        $router->post('/admin/mitteilungen/loeschen', [AnnouncementController::class, 'delete']);
        $router->post('/admin/mitteilungen/status', [AnnouncementController::class, 'toggle']);

        $router->get('/admin/beschreibungen', [DescriptionController::class, 'index']);
        $router->post('/admin/beschreibungen', [DescriptionController::class, 'update']);

        $router->get('/admin/design', [DesignController::class, 'index']);
        $router->post('/admin/design', [DesignController::class, 'update']);
        $router->post('/admin/design/logo', [DesignController::class, 'uploadLogo']);
        $router->post('/admin/design/logo-entfernen', [DesignController::class, 'removeLogo']);
        $router->post('/admin/design/hintergrundbild', [DesignController::class, 'uploadBackground']);
        $router->post('/admin/design/hintergrundbild-entfernen', [DesignController::class, 'removeBackground']);

        $router->get('/admin/ad', [LdapController::class, 'index']);
        $router->post('/admin/ad', [LdapController::class, 'update']);
        $router->get('/admin/ad/hauptquelle', [LdapController::class, 'editPrimary']);
        $router->get('/admin/ad/synchronisation', [LdapController::class, 'syncPage']);
        $router->post('/admin/ad/synchronisation', [LdapController::class, 'updateSyncInterval']);
        $router->post('/admin/ad/sync', [LdapController::class, 'sync']);
        $router->get('/admin/ad/quellen/neu', [LdapController::class, 'createSource']);
        $router->post('/admin/ad/quellen/neu', [LdapController::class, 'storeSource']);
        $router->get('/admin/ad/quellen/bearbeiten', [LdapController::class, 'editSource']);
        $router->post('/admin/ad/quellen/bearbeiten', [LdapController::class, 'updateSource']);
        $router->post('/admin/ad/quellen/loeschen', [LdapController::class, 'deleteSource']);
        $router->post('/admin/ad/quellen/testen', [LdapController::class, 'testSource']);
        $router->get('/admin/ad/gruppen', [LdapController::class, 'groups']);

        $router->get('/admin/office', [OfficeAdminController::class, 'index']);
        $router->get('/admin/office/fusszeile', [OfficeAdminController::class, 'showFooter']);
        $router->post('/admin/office/fusszeile', [OfficeAdminController::class, 'update']);
        $router->get('/admin/office/ki', [OfficeAdminController::class, 'showAi']);
        $router->post('/admin/office/ki', [OfficeAdminController::class, 'updateAi']);
        $router->post('/admin/office/ki/testen', [OfficeAdminController::class, 'testAi']);
        $router->get('/admin/office/app-store', [OfficeAdminController::class, 'showAppStore']);
        $router->post('/admin/office/app-store', [OfficeAdminController::class, 'updateAppStore']);
        $router->post('/admin/office/pruefen', [OfficeAdminController::class, 'check']);
        $router->get('/admin/office/sicherung', [OfficeAdminController::class, 'showBackup']);
        $router->post('/admin/office/sicherung', [OfficeAdminController::class, 'backup']);
        $router->get('/admin/office/kachel', [OfficeAdminController::class, 'showTile']);
        $router->post('/admin/office/kachel', [OfficeAdminController::class, 'createTile']);
        $router->post('/admin/office/kachel/gestaltung', [OfficeAdminController::class, 'updateTile']);
        $router->get('/admin/office/orvanta', [OfficeAdminController::class, 'showOrvanta']);
        $router->post('/admin/office/orvanta', [OfficeAdminController::class, 'updateOrvanta']);
        $router->post('/admin/office/orvanta/pruefen', [OfficeAdminController::class, 'testOrvanta']);
        // Orvanta: Hosts einer Exchange-DAG (Lastverteilung, Ausfallsicherung, Dashboard).
        $router->get('/admin/office/orvanta/hosts', [OrvantaHostController::class, 'index']);
        $router->post('/admin/office/orvanta/hosts', [OrvantaHostController::class, 'add']);
        $router->post('/admin/office/orvanta/hosts/status', [OrvantaHostController::class, 'toggle']);
        $router->post('/admin/office/orvanta/hosts/loeschen', [OrvantaHostController::class, 'remove']);
        $router->post('/admin/office/orvanta/hosts/pruefen', [OrvantaHostController::class, 'check']);
        $router->post('/admin/office/orvanta/hosts/pruefpostfach', [OrvantaHostController::class, 'testMailbox']);
        $router->get('/admin/office/orvanta/hosts/daten', [OrvantaHostController::class, 'data']);
        // Orvanta: Nachrichtenfluss-Dashboard (Identitaetsquellen, Proxy, DAG-Hosts, Speicher, KI).
        $router->get('/admin/office/orvanta/nachrichtenfluss', [OrvantaFlowController::class, 'index']);
        $router->get('/admin/office/orvanta/nachrichtenfluss/daten', [OrvantaFlowController::class, 'data']);
        $router->get('/admin/office/orvanta/nachrichtenfluss/topologie', [OrvantaFlowController::class, 'topology']);
        $router->post('/admin/office/orvanta/nachrichtenfluss/quellen/pruefen', [OrvantaFlowController::class, 'checkSources']);
        // Orvanta: zusaetzlich berechtigte Postfaecher je Benutzer (Vollzugriff / "Senden als").
        $router->get('/admin/office/orvanta/postfaecher', [OrvantaSharedMailboxController::class, 'index']);
        $router->post('/admin/office/orvanta/postfaecher/pruefen', [OrvantaSharedMailboxController::class, 'verify']);
        $router->get('/admin/office/kachel/vorschau', [OfficeAdminController::class, 'tilePreview']);
        $router->get('/admin/office/apps', [OfficeAppsAdminController::class, 'index']);
        $router->post('/admin/office/apps/owa', [OfficeAppsAdminController::class, 'updateOwa']);
        $router->post('/admin/office/apps/freigaben', [OfficeAppsAdminController::class, 'updatePermissions']);
        $router->get('/admin/office/apps/paket', [OfficeAppsAdminController::class, 'editPackage']);
        $router->post('/admin/office/apps/paket', [OfficeAppsAdminController::class, 'savePackage']);
        $router->post('/admin/office/apps/paket/loeschen', [OfficeAppsAdminController::class, 'deletePackage']);
        // Orvanta: Signaturvorlagen (Zuordnung per AD-Gruppe, serverseitig angefuegt).
        $router->get('/admin/office/signaturen', [OrvantaSignatureController::class, 'index']);
        $router->get('/admin/office/signaturen/vorlage', [OrvantaSignatureController::class, 'edit']);
        $router->post('/admin/office/signaturen/vorlage', [OrvantaSignatureController::class, 'save']);
        $router->post('/admin/office/signaturen/loeschen', [OrvantaSignatureController::class, 'delete']);
        $router->get('/admin/office/signaturen/vorschau', [OrvantaSignatureController::class, 'preview']);
        // Orvanta: Abwesenheitsnotizen (Vorlagen per AD-Gruppe, Uebertragung auf Exchange).
        $router->get('/admin/office/abwesenheit', [OrvantaOofController::class, 'index']);
        $router->get('/admin/office/abwesenheit/vorlage', [OrvantaOofController::class, 'edit']);
        $router->post('/admin/office/abwesenheit/vorlage', [OrvantaOofController::class, 'save']);
        $router->post('/admin/office/abwesenheit/loeschen', [OrvantaOofController::class, 'delete']);
        $router->get('/admin/office/abwesenheit/vorschau', [OrvantaOofController::class, 'preview']);
        // Orvanta: SMTP-/IMAP-Proxy fuer Benutzer ohne Exchange-Postfach (Server, Postfaecher, Zuordnung).
        $router->get('/admin/office/mail-proxy', [MailProxyController::class, 'index']);
        $router->get('/admin/office/mail-proxy/server/neu', [MailProxyController::class, 'createServer']);
        $router->get('/admin/office/mail-proxy/server/bearbeiten', [MailProxyController::class, 'editServer']);
        $router->get('/admin/office/mail-proxy/postfach/neu', [MailProxyController::class, 'createMailbox']);
        $router->get('/admin/office/mail-proxy/postfach/bearbeiten', [MailProxyController::class, 'editMailbox']);
        $router->post('/admin/office/mail-proxy/server', [MailProxyController::class, 'saveServer']);
        $router->post('/admin/office/mail-proxy/server/status', [MailProxyController::class, 'toggleServer']);
        $router->post('/admin/office/mail-proxy/server/loeschen', [MailProxyController::class, 'deleteServer']);
        $router->post('/admin/office/mail-proxy/postfach', [MailProxyController::class, 'saveMailbox']);
        $router->post('/admin/office/mail-proxy/postfach/loeschen', [MailProxyController::class, 'deleteMailbox']);
        $router->post('/admin/office/mail-proxy/postfach/test', [MailProxyController::class, 'testMailbox']);
        $router->post('/admin/office/mail-proxy/zuordnung', [MailProxyController::class, 'saveMapping']);
        $router->post('/admin/office/mail-proxy/zuordnung/loeschen', [MailProxyController::class, 'deleteMapping']);
        $router->get('/admin/office/mail-proxy/users', [MailProxyController::class, 'users']);
        $router->get('/admin/office/mail-proxy/mailboxes', [MailProxyController::class, 'mailboxes']);
        $router->get('/admin/speicherplatz', [StorageQuotaController::class, 'index']);
        $router->post('/admin/speicherplatz/standard', [StorageQuotaController::class, 'updateDefault']);
        $router->post('/admin/speicherplatz/gruppen', [StorageQuotaController::class, 'saveGroup']);
        $router->post('/admin/speicherplatz/gruppen/loeschen', [StorageQuotaController::class, 'deleteGroup']);
        $router->get('/admin/speicherplatz/benutzer', [StorageQuotaController::class, 'editUser']);
        $router->post('/admin/speicherplatz/benutzer', [StorageQuotaController::class, 'saveUser']);
        $router->post('/admin/speicherplatz/benutzer/entfernen', [StorageQuotaController::class, 'removeUser']);
        $router->post('/admin/speicherplatz/uebertragen', [StorageQuotaController::class, 'push']);
        $router->get('/admin/speicherplatz/verlauf', [StorageQuotaController::class, 'history']);
        $router->get('/admin/netzlaufwerke', [NetworkDriveAdminController::class, 'index']);
        $router->post('/admin/netzlaufwerke/einstellungen', [NetworkDriveAdminController::class, 'updateSettings']);
        $router->post('/admin/netzlaufwerke/benutzer/entfernen', [NetworkDriveAdminController::class, 'deleteUser']);
        $router->post('/admin/netzlaufwerke/uebertragen', [NetworkDriveAdminController::class, 'push']);
        $router->get('/admin/netzlaufwerke/skript', [NetworkDriveAdminController::class, 'script']);
        $router->get('/admin/speicher-ha', [StorageAdminController::class, 'index']);
        $router->get('/admin/speicher-ha/status', [StorageAdminController::class, 'live']);
        $router->post('/admin/speicher-ha/einstellungen', [StorageAdminController::class, 'updateSettings']);
        $router->get('/admin/speicher-ha/ziel', [StorageAdminController::class, 'editTarget']);
        $router->post('/admin/speicher-ha/ziel', [StorageAdminController::class, 'saveTarget']);
        $router->post('/admin/speicher-ha/ziel/loeschen', [StorageAdminController::class, 'deleteTarget']);
        $router->get('/admin/speicher-ha/erweitern', [StorageAdminController::class, 'extendForm']);
        $router->post('/admin/speicher-ha/erweitern', [StorageAdminController::class, 'extend']);
        $router->post('/admin/speicher-ha/auftrag', [StorageAdminController::class, 'request']);
        $router->post('/admin/speicher-ha/snapshot-einstellungen', [StorageAdminController::class, 'updateSnapshotSettings']);
        $router->get('/admin/speicher-ha/dateiversionen', [StorageAdminController::class, 'versions']);
        $router->post('/admin/speicher-ha/dateiversionen/wiederherstellen', [StorageAdminController::class, 'restoreVersion']);
        $router->get('/admin/vorfaelle', [IncidentController::class, 'index']);
        $router->post('/admin/vorfaelle/erledigt', [IncidentController::class, 'resolve']);
        $router->post('/admin/vorfaelle/einstellungen', [IncidentController::class, 'updateSettings']);

        $router->get('/admin/zertifikate', [CertificateController::class, 'index']);
        $router->post('/admin/zertifikate/csr', [CertificateController::class, 'createRequest']);
        $router->get('/admin/zertifikate/csr', [CertificateController::class, 'downloadCsr']);
        $router->post('/admin/zertifikate/import/pruefen', [CertificateController::class, 'previewImport']);
        $router->post('/admin/zertifikate/import/bestaetigen', [CertificateController::class, 'confirmImport']);
        $router->post('/admin/zertifikate/import/verwerfen', [CertificateController::class, 'discardImport']);
        $router->post('/admin/zertifikate/aktivieren', [CertificateController::class, 'activate']);
        $router->post('/admin/zertifikate/deaktivieren', [CertificateController::class, 'deactivate']);
        $router->post('/admin/zertifikate/loeschen', [CertificateController::class, 'delete']);
        $router->post('/admin/zertifikate/http-netze', [CertificateController::class, 'updateNetworks']);

        $router->get('/admin/snmp', [SnmpController::class, 'index']);
        $router->post('/admin/snmp', [SnmpController::class, 'update']);

        $router->get('/admin/alarmierung', [AlarmController::class, 'index']);
        $router->post('/admin/alarmierung', [AlarmController::class, 'update']);
        $router->get('/admin/alarmierung/gruppen/neu', [AlarmGroupController::class, 'create']);
        $router->post('/admin/alarmierung/gruppen/neu', [AlarmGroupController::class, 'store']);
        $router->get('/admin/alarmierung/gruppen/bearbeiten', [AlarmGroupController::class, 'edit']);
        $router->post('/admin/alarmierung/gruppen/bearbeiten', [AlarmGroupController::class, 'update']);
        $router->post('/admin/alarmierung/gruppen/loeschen', [AlarmGroupController::class, 'delete']);
        $router->post('/admin/alarmierung/gruppen/status', [AlarmGroupController::class, 'toggle']);

        $router->get('/admin/aktivierungs-rufnummern', [ActivationNumberController::class, 'index']);
        $router->post('/admin/aktivierungs-rufnummern', [ActivationNumberController::class, 'update']);
        $router->get('/admin/aktivierungs-rufnummern/neu', [ActivationNumberController::class, 'create']);
        $router->post('/admin/aktivierungs-rufnummern/neu', [ActivationNumberController::class, 'store']);
        $router->get('/admin/aktivierungs-rufnummern/bearbeiten', [ActivationNumberController::class, 'edit']);
        $router->post('/admin/aktivierungs-rufnummern/bearbeiten', [ActivationNumberController::class, 'updateItem']);
        $router->post('/admin/aktivierungs-rufnummern/loeschen', [ActivationNumberController::class, 'delete']);
        $router->post('/admin/aktivierungs-rufnummern/status', [ActivationNumberController::class, 'toggle']);
        $router->post('/admin/aktivierungs-rufnummern/sortieren', [ActivationNumberController::class, 'move']);

        $router->get('/admin/statistik', [StatisticsController::class, 'index']);
        $router->get('/admin/api/statistik', [StatisticsController::class, 'data']);

        $router->get('/admin/benutzer', [AdminUserController::class, 'index']);
        $router->get('/admin/benutzer/neu', [AdminUserController::class, 'create']);
        $router->post('/admin/benutzer/neu', [AdminUserController::class, 'store']);
        $router->get('/admin/benutzer/bearbeiten', [AdminUserController::class, 'edit']);
        $router->post('/admin/benutzer/bearbeiten', [AdminUserController::class, 'update']);
        $router->post('/admin/benutzer/loeschen', [AdminUserController::class, 'delete']);
        $router->post('/admin/benutzer/status', [AdminUserController::class, 'toggle']);
        $router->post('/admin/benutzer/ad-gruppen', [AdminUserController::class, 'storeGroup']);
        $router->post('/admin/benutzer/ad-gruppen/loeschen', [AdminUserController::class, 'deleteGroup']);
        $router->post('/admin/benutzer/nextcloud-uebertragen', [AdminUserController::class, 'pushNextcloud']);

        $router->get('/admin/sicherung', [ImportExportController::class, 'index']);
        $router->post('/admin/sicherung/export', [ImportExportController::class, 'export']);
        $router->post('/admin/sicherung/import', [ImportExportController::class, 'import']);
    });
});

try {
    $response = $router->dispatch($request);
} catch (HttpException $exception) {
    $response = renderError($exception->statusCode(), $exception->getMessage());
} catch (Throwable $exception) {
    app_logger()->error('Unbehandelter Fehler.', [
        'message' => $exception->getMessage(),
        'path' => $request->path,
    ]);

    $response = renderError(500, 'Es ist ein technischer Fehler aufgetreten.');
}

// Von Controllern gesetzte Sicherheitsheader (z. B. eigene CSP für ausgelieferte Dateien) bleiben erhalten.
foreach (securityHeaders($nonce) as $name => $value) {
    if (!array_key_exists($name, $response->headers())) {
        $response = $response->withHeader($name, $value);
    }
}

$response->send();

/**
 * @return array<string,string>
 */
function securityHeaders(string $nonce): array
{
    return [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), interest-cohort=()',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'nonce-" . $nonce
            . "'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
    ];
}

function renderError(int $status, string $message): Response
{
    $template = $status === 404 ? 'errors.404' : 'errors.generic';

    try {
        $html = View::render($template, [
            'appName' => (string) Config::get('app.name', 'Intranet'),
            'status' => $status,
            'message' => $message,
            'themeCss' => Container::theme()->css(),
            'assetVersion' => '1',
        ], 'layouts.minimal');
    } catch (Throwable) {
        // Fallback ohne Datenbank (z. B. MySQL nicht erreichbar).
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Fehler ' . $status . '</title></head><body style="font-family:system-ui,sans-serif;padding:2rem">'
            . '<h1>Fehler ' . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="/">Zur Startseite</a></p></body></html>';
    }

    return Response::html($html, $status);
}
