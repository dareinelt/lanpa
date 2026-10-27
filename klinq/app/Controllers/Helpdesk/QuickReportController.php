<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Services\Helpdesk\ReporterIdentityService;
use App\Services\Helpdesk\TicketService;
use App\Services\PersonDirectoryService;
use App\Support\IpRange;

/**
 * Öffentliches Störungsformular (/stoerung) für Anwender – ohne Anmeldung und bewusst
 * schlank: Betreff, Beschreibung und optional zusätzliche betroffene Benutzer.
 *
 * Melder (Windows-Konto inkl. AD-Abgleich), Rechnername und IP-Adresse werden serverseitig
 * erkannt (ReporterIdentityService) und dürfen nicht aus dem Formular übernommen werden.
 * Das Ticket selbst legt ein konfiguriertes Systemkonto an; dessen Rechte gelten nur für
 * die Dauer des Vorgangs (CurrentUser::runAs) und landen nie in der Sitzung des Besuchers.
 */
final class QuickReportController extends HelpdeskBaseController
{
    private const SESSION_RATE_KEY = '_quick_report_times';
    private const SESSION_LAST_KEY = '_quick_report_last';
    private const SESSION_PEOPLE_KEY = '_quick_report_people_times';
    /** Obergrenze für Telefonbuch-Übernahmen pro Sitzung und Stunde (Schutz vor Massenanlage). */
    private const PEOPLE_LIMIT_PER_HOUR = 20;

    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TicketService $service,
        private readonly ReporterIdentityService $identity,
        private readonly TicketMasterDataRepository $masterData,
        private readonly PersonDirectoryService $people,
        private readonly EmployeeRepository $employees,
        private readonly UserRepository $users,
        private readonly Permissions $permissions,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
        parent::__construct($view, $currentUser);
    }

    public function form(Request $request): Response
    {
        $this->assertAvailable($request);

        return $this->renderForm($request);
    }

    public function store(Request $request): Response
    {
        $this->assertAvailable($request);
        $reporter = $this->identity->detect($request);

        if (!$this->withinRateLimit()) {
            return $this->renderForm($request, 'Es wurden in kurzer Zeit sehr viele Meldungen gesendet. Bitte wenden Sie sich telefonisch an den Help Desk.', 429, $reporter);
        }

        // Betreff, Beschreibung und der optional gewählte betroffene Mitarbeiter stammen aus dem
        // Formular – alle Melderangaben werden serverseitig ermittelt.
        $input = [
            'subject' => $request->string('subject'),
            'description' => $request->string('description'),
            'reporter' => $reporter,
        ];
        $affectedId = $request->int('affected_employee_id');
        if ($affectedId !== null && $affectedId > 0) {
            $input['affected_employee_id'] = $affectedId;
        }
        $type = $this->masterData->typeByCode((string) $this->config->get('helpdesk.quick_report.default_type', 'incident'));
        if ($type !== null) {
            $input['ticket_type_id'] = (int) $type['id'];
        }

        try {
            $ticket = $this->createAsSystemUser($input);
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->renderForm($request, null, 422, $reporter);
        } catch (\Throwable $e) {
            // Fehlkonfiguration oder Datenbankfehler (z. B. fehlendes Systemkonto): Der Besucher
            // bekommt eine verstaendliche Meldung statt einer unbehandelten Ausnahme; die
            // Ursache landet mit Kontext im Log (storage/logs/app-*.log).
            $this->logger->error('Störungsformular: Meldung konnte nicht angelegt werden', [
                'type' => $e::class,
                'message' => $e->getMessage(),
                'ort' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $this->renderForm($request, 'Die Meldung konnte nicht gespeichert werden. Bitte wenden Sie sich telefonisch an den Help Desk.', 500, $reporter);
        }

        $this->rememberSubmission();
        $_SESSION[self::SESSION_LAST_KEY] = ['number' => (string) $ticket['number'], 'at' => time()];

        return $this->redirect('/stoerung/gesendet');
    }

    public function done(Request $request): Response
    {
        $this->assertAvailable($request);
        $last = $_SESSION[self::SESSION_LAST_KEY] ?? null;
        unset($_SESSION[self::SESSION_LAST_KEY]);
        if (!is_array($last) || empty($last['number'])) {
            return $this->redirect('/stoerung');
        }

        return $this->render('helpdesk.quick.done', [
            'title' => 'Meldung eingegangen',
            'number' => (string) $last['number'],
        ]);
    }

    /**
     * Personensuche für das Zusatzfeld „Zusätzlicher Benutzer“ im öffentlichen Formular.
     * Liefert nur Treffer; ein Mitarbeiterdatensatz entsteht erst über adoptPerson().
     */
    public function searchPeople(Request $request): Response
    {
        $this->assertAvailable($request);

        return $this->json(['items' => $this->people->search($request->queryString('q'), PersonDirectoryService::LIMIT)]);
    }

    /**
     * Übernimmt eine im Telefonbuch gefundene Person als lokalen Mitarbeiter, damit das
     * Ticket auf einen gültigen Datensatz verweist. Die Stammdaten kommen aus dem Intranet,
     * nicht aus der Anfrage. Da der Aufruf ohne Anmeldung möglich ist, gilt eine eigene
     * Obergrenze pro Sitzung.
     */
    public function adoptPerson(Request $request): Response
    {
        $this->assertAvailable($request);
        if (!$this->withinPeopleLimit()) {
            return $this->json(['error' => 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.'], 429);
        }
        $this->rememberPeopleLookup();

        $id = $this->people->adopt($request->int('ref') ?? 0);
        if ($id === null) {
            return $this->json(['error' => 'Person nicht gefunden.'], 404);
        }

        return $this->json(['id' => $id]);
    }

    /** Ticket unter dem Systemkonto anlegen, ohne die Sitzung des Besuchers zu verändern. */
    private function createAsSystemUser(array $input): array
    {
        $username = (string) $this->config->get('helpdesk.quick_report.system_user', 'admin');
        $user = $this->users->findByUsername($username);
        // hasEffective() statt roleHas(): Berechtigungsgruppen zaehlen mit, genau wie in
        // CurrentUser::can() – sonst wuerde ein Konto abgelehnt, das das Recht ueber eine
        // Gruppe besitzt, und das Ticket-Anlegen spaeter doch erlaubt waere.
        if ($user === null || empty($user['is_active']) || !$this->permissions->hasEffective((string) $user['role'], (array) ($user['groups'] ?? []), 'helpdesk.create')) {
            $this->logger->error('Störungsformular: Systemkonto fehlt, ist inaktiv oder darf keine Tickets anlegen', ['username' => $username, 'rolle' => $user['role'] ?? null]);

            throw new \RuntimeException('Das Störungsformular ist nicht vollständig eingerichtet.');
        }

        /** @var array<string,mixed> $ticket */
        $ticket = $this->currentUser->runAs($user, fn (): array => $this->service->create($input, 'form'));

        return $ticket;
    }

    /** @param array<string,mixed>|null $reporter bereits ermittelte Melderdaten (spart einen zweiten AD-Zugriff) */
    private function renderForm(Request $request, ?string $error = null, int $status = 200, ?array $reporter = null): Response
    {
        return $this->render('helpdesk.quick.form', [
            'title' => 'Störung melden',
            'reporter' => $reporter ?? $this->identity->detect($request),
            'error' => $error,
            'affected' => $this->selectedAffected(),
        ], $status);
    }

    /**
     * Bereits gewählter betroffener Mitarbeiter (nach Validierungsfehler erneut anzeigen).
     * @return array{id:int,name:string,meta:string}|null
     */
    private function selectedAffected(): ?array
    {
        $id = (int) $this->oldInput('affected_employee_id', 0);
        if ($id <= 0) {
            return null;
        }
        $row = $this->employees->find($id);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['display_name'],
            'meta' => trim((string) ($row['department'] ?? '')),
        ];
    }

    /** Formular abgeschaltet oder Zugriff aus einem nicht freigegebenen Netz. */
    private function assertAvailable(Request $request): void
    {
        if (!(bool) $this->config->get('helpdesk.enabled', true) || !(bool) $this->config->get('helpdesk.quick_report.enabled', true)) {
            throw new NotFoundException('Das Störungsformular ist nicht aktiviert.');
        }
        $networks = (array) $this->config->get('helpdesk.quick_report.allowed_networks', []);
        if ($networks !== [] && !IpRange::matchesAny($request->ip(), $networks)) {
            throw new ForbiddenException('Das Störungsformular ist aus diesem Netz nicht erreichbar.');
        }
    }

    private function withinRateLimit(): bool
    {
        $limit = (int) $this->config->get('helpdesk.quick_report.rate_limit_per_hour', 10);
        if ($limit <= 0) {
            return true;
        }

        return count($this->recentSubmissions()) < $limit;
    }

    private function rememberSubmission(): void
    {
        $times = $this->recentSubmissions();
        $times[] = time();
        $_SESSION[self::SESSION_RATE_KEY] = $times;
    }

    /** @return array<int,int> Zeitstempel der letzten Stunde */
    private function recentSubmissions(): array
    {
        $times = is_array($_SESSION[self::SESSION_RATE_KEY] ?? null) ? $_SESSION[self::SESSION_RATE_KEY] : [];
        $threshold = time() - 3600;

        return array_values(array_filter($times, static fn (mixed $t): bool => is_int($t) && $t > $threshold));
    }

    private function withinPeopleLimit(): bool
    {
        return count($this->recentPeopleLookups()) < self::PEOPLE_LIMIT_PER_HOUR;
    }

    private function rememberPeopleLookup(): void
    {
        $times = $this->recentPeopleLookups();
        $times[] = time();
        $_SESSION[self::SESSION_PEOPLE_KEY] = $times;
    }

    /** @return array<int,int> Zeitstempel der letzten Stunde */
    private function recentPeopleLookups(): array
    {
        $times = is_array($_SESSION[self::SESSION_PEOPLE_KEY] ?? null) ? $_SESSION[self::SESSION_PEOPLE_KEY] : [];
        $threshold = time() - 3600;

        return array_values(array_filter($times, static fn (mixed $t): bool => is_int($t) && $t > $threshold));
    }
}
