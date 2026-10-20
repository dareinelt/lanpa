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
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Services\Helpdesk\ReporterIdentityService;
use App\Services\Helpdesk\TicketService;
use App\Support\IpRange;

/**
 * Öffentliches Störungsformular (/stoerung) für Anwender – ohne Anmeldung und bewusst
 * auf zwei Felder beschränkt: Betreff und Beschreibung.
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

    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TicketService $service,
        private readonly ReporterIdentityService $identity,
        private readonly TicketMasterDataRepository $masterData,
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

        // Nur Betreff und Beschreibung stammen aus dem Formular – alle Melderangaben serverseitig.
        $input = [
            'subject' => $request->string('subject'),
            'description' => $request->string('description'),
            'reporter' => $reporter,
        ];
        $type = $this->masterData->typeByCode((string) $this->config->get('helpdesk.quick_report.default_type', 'incident'));
        if ($type !== null) {
            $input['ticket_type_id'] = (int) $type['id'];
        }

        try {
            $ticket = $this->createAsSystemUser($input);
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->renderForm($request, null, 422, $reporter);
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

    /** Ticket unter dem Systemkonto anlegen, ohne die Sitzung des Besuchers zu verändern. */
    private function createAsSystemUser(array $input): array
    {
        $username = (string) $this->config->get('helpdesk.quick_report.system_user', 'admin');
        $user = $this->users->findByUsername($username);
        if ($user === null || empty($user['is_active']) || !$this->permissions->roleHas((string) $user['role'], 'helpdesk.create')) {
            $this->logger->error('Störungsformular: Systemkonto fehlt oder darf keine Tickets anlegen', ['username' => $username]);

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
        ], $status);
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
}
