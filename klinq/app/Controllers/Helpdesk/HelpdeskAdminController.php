<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\NotFoundException;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRuleRepository;
use App\Repositories\TicketSlaRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\TicketTemplateRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\HelpdeskAdminService;
use App\Services\Helpdesk\MailboxSettingsService;
use App\Services\Helpdesk\TicketMailIngestionService;
use App\Services\Helpdesk\TicketNotificationService;
use App\Services\Helpdesk\TicketRuleEvaluator;
use App\Services\Helpdesk\TicketService;

/**
 * Help-Desk-Administration: Typen, Status, Prioritäten, Gruppen, Kategorien, SLAs, Tags, Vorlagen, Regeln.
 * Ein Bereich („kind“) pro Tab; Formulare werden generisch über helpdesk.admin.form gerendert.
 */
final class HelpdeskAdminController extends HelpdeskBaseController
{
    /** Bereich => [Label, benötigte Berechtigung] */
    public const KINDS = [
        'types' => ['Tickettypen', 'helpdesk.admin'],
        'statuses' => ['Status', 'helpdesk.admin'],
        'priorities' => ['Prioritäten', 'helpdesk.admin'],
        'groups' => ['Gruppen', 'helpdesk.admin'],
        'categories' => ['Kategorien', 'helpdesk.categories'],
        'slas' => ['SLAs', 'helpdesk.sla'],
        'tags' => ['Tags', 'helpdesk.admin'],
        'templates' => ['Vorlagen', 'helpdesk.templates'],
        'rules' => ['Regeln', 'helpdesk.admin'],
    ];

    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly HelpdeskAdminService $service,
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketCategoryRepository $categories,
        private readonly TicketSlaRepository $slas,
        private readonly TicketTagRepository $tags,
        private readonly TicketTemplateRepository $templates,
        private readonly TicketRuleRepository $rules,
        private readonly TicketRuleEvaluator $evaluator,
        private readonly TicketNotificationService $notifications,
        private readonly TicketMailIngestionService $mailIngestion,
        private readonly MailboxSettingsService $mailSettings,
        private readonly Config $config
    ) {
        parent::__construct($view, $currentUser);
    }

    /** Erster Bereich, den der Benutzer sehen darf. */
    private function defaultKind(): string
    {
        foreach (self::KINDS as $kind => [, $permission]) {
            if ($this->currentUser->can($permission)) {
                return $kind;
            }
        }
        $this->currentUser->require('helpdesk.admin');

        return 'types';
    }

    /** Bereich aus Pfad (/helpdesk/admin/{kind}/…) oder Query (?tab=) lesen und Berechtigung prüfen. */
    private function kindFrom(Request $request): string
    {
        $segments = explode('/', trim($request->path(), '/'));
        $kind = $segments[2] ?? $request->queryString('tab');
        if ($kind === '' || !isset(self::KINDS[$kind])) {
            $kind = $this->defaultKind();
        }
        $this->currentUser->require(self::KINDS[$kind][1]);

        return $kind;
    }

    public function index(Request $request): Response
    {
        $kind = $this->kindFrom($request);

        return $this->render('helpdesk.admin.index', [
            'title' => 'Help-Desk-Administration',
            'activeNav' => 'helpdesk-admin',
            'areaLabel' => 'Help Desk',
            'kind' => $kind,
            'kinds' => $this->visibleKinds(),
            'rows' => $this->rowsFor($kind),
            'usage' => $this->usageFor($kind),
            'config' => [
                'enabled' => (bool) $this->config->get('helpdesk.enabled', true),
                'ticket_prefix' => (string) $this->config->get('helpdesk.ticket_prefix', 'HD'),
                'escalation_enabled' => (bool) $this->config->get('helpdesk.escalation_enabled', true),
                'escalation_interval_minutes' => (int) $this->config->get('helpdesk.escalation_interval_minutes', 15),
                'auto_close_days' => (int) $this->config->get('helpdesk.auto_close_days', 5),
                'reopen_days' => (int) $this->config->get('helpdesk.reopen_days', 14),
                'notifications_enabled' => $this->notifications->enabled(),
                'mail_intake_enabled' => $this->mailIngestion->enabled(),
                'sla_warning_percent' => (int) $this->config->get('helpdesk.sla_warning_percent', 75),
                'sla_escalation_percent' => (int) $this->config->get('helpdesk.sla_escalation_percent', 90),
            ],
            'colors' => HelpdeskAdminService::COLORS,
            'quickReport' => [
                'enabled' => (bool) $this->config->get('helpdesk.quick_report.enabled', true),
                'url' => rtrim((string) $this->config->get('app.url', ''), '/') . '/stoerung',
                'system_user' => (string) $this->config->get('helpdesk.quick_report.system_user', 'admin'),
                'networks' => (array) $this->config->get('helpdesk.quick_report.allowed_networks', []),
            ],
            'statusCategories' => HelpdeskAdminService::STATUS_CATEGORIES,
            'triggers' => TicketRuleRepository::TRIGGERS,
        ]);
    }

    /** Protokoll des E-Mail-Eingangs (IMAP) mit Konfigurationsübersicht. */
    public function mail(Request $request): Response
    {
        $this->currentUser->require('helpdesk.admin');
        $rows = $this->rules->recentInboundMails(200);
        $counts = ['created' => 0, 'comment' => 0, 'ignored' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['action']] = ($counts[(string) $row['action']] ?? 0) + 1;
        }
        $settings = $this->mailSettings->all();
        $driver = (string) $settings['driver'];

        return $this->render('helpdesk.admin.mail', [
            'title' => 'E-Mail-Eingang',
            'activeNav' => 'helpdesk-admin',
            'areaLabel' => 'Help Desk',
            'rows' => $rows,
            'counts' => $counts,
            'canConfigure' => $this->currentUser->can('settings.manage'),
            'lastRun' => $this->mailSettings->lastRun(),
            'config' => [
                'enabled' => $this->mailIngestion->enabled(),
                'driver' => $driver,
                'host' => $driver === 'file'
                    ? (string) $settings['file_path']
                    : trim((string) $settings['host'] . ':' . (string) $settings['port'], ':'),
                'encryption' => (string) $settings['encryption'],
                'mailbox' => (string) $settings['mailbox'],
                'processed_mailbox' => $settings['move_processed'] && $settings['processed_mailbox'] !== ''
                    ? (string) $settings['processed_mailbox']
                    : '– (nur gelesen markieren)',
                'interval_minutes' => (int) $settings['interval_minutes'],
                'batch_size' => (int) $settings['batch_size'],
                'system_user' => (string) $settings['system_user'],
                'allow_unknown_senders' => (bool) $settings['allow_unknown_senders'],
                'default_type' => (string) $settings['default_type'],
                'mail_domain' => $this->notifications->mailDomain(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $kind = $this->kindFrom($request);

        return $this->renderForm($kind, null);
    }

    public function edit(Request $request): Response
    {
        $kind = $this->kindFrom($request);
        $row = $this->findOrFail($this->findRow($kind, $request->paramInt('id')), 'Datensatz nicht gefunden');

        return $this->renderForm($kind, $row);
    }

    public function store(Request $request): Response
    {
        $kind = $this->kindFrom($request);
        $response = $this->attempt($request, fn () => $this->save($kind, null, $request->all()), '/helpdesk/admin/' . $kind . '/new');

        return $response ?? $this->respond($request, self::KINDS[$kind][0] . ': Eintrag angelegt.', '/helpdesk/admin?tab=' . $kind);
    }

    public function update(Request $request): Response
    {
        $kind = $this->kindFrom($request);
        $id = $request->paramInt('id');
        $this->findOrFail($this->findRow($kind, $id), 'Datensatz nicht gefunden');
        $response = $this->attempt($request, fn () => $this->save($kind, $id, $request->all()), '/helpdesk/admin/' . $kind . '/' . $id . '/edit');

        return $response ?? $this->respond($request, self::KINDS[$kind][0] . ': Eintrag gespeichert.', '/helpdesk/admin?tab=' . $kind);
    }

    /** Aktiv/Inaktiv umschalten bzw. löschen (Tags, Regeln). */
    public function toggle(Request $request): Response
    {
        $kind = $this->kindFrom($request);
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->findRow($kind, $id), 'Datensatz nicht gefunden');
        $message = 'Eintrag aktualisiert.';
        switch ($kind) {
            case 'types':
            case 'statuses':
            case 'priorities':
            case 'groups':
                $this->service->deactivateSimple($kind, $id);
                $message = empty($row['is_active']) ? 'Eintrag aktiviert.' : 'Eintrag deaktiviert.';
                break;
            case 'categories':
                $this->service->saveCategory($id, ['is_active' => empty($row['is_active']) ? '1' : '0'] + $row);
                break;
            case 'slas':
                $this->service->saveSla($id, ['is_active' => empty($row['is_active']) ? '1' : '0'] + $this->slaInput($row));
                break;
            case 'templates':
                $this->service->saveTemplate($id, ['is_active' => empty($row['is_active']) ? '1' : '0'] + $row);
                break;
            case 'tags':
                $this->service->deleteTag($id);
                $message = 'Tag gelöscht.';
                break;
            case 'rules':
                $this->service->deleteRule($id);
                $message = 'Regel gelöscht.';
                break;
        }

        return $this->respond($request, $message, '/helpdesk/admin?tab=' . $kind);
    }

    // ------------------------------------------------------------------ intern

    /** @return array<string,string> */
    private function visibleKinds(): array
    {
        $out = [];
        foreach (self::KINDS as $kind => [$label, $permission]) {
            if ($this->currentUser->can($permission)) {
                $out[$kind] = $label;
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function rowsFor(string $kind): array
    {
        return match ($kind) {
            'types' => $this->masterData->types(false),
            'statuses' => $this->masterData->statuses(false),
            'priorities' => $this->masterData->priorities(false),
            'groups' => array_map(fn (array $g): array => $g + ['members' => $this->masterData->groupMembers((int) $g['id'])], $this->masterData->groups(false)),
            'categories' => $this->categories->all(false),
            'slas' => $this->slas->all(false),
            'tags' => $this->tags->all(),
            'templates' => $this->templates->all(false),
            'rules' => $this->rules->all(),
            default => [],
        };
    }

    /** Verwendungszähler für Stammdaten (Löschschutz-Hinweis). @return array<int,int> */
    private function usageFor(string $kind): array
    {
        $table = HelpdeskAdminService::SIMPLE_TABLES[$kind] ?? null;
        if ($table === null) {
            return [];
        }
        $usage = [];
        foreach ($this->masterData->allIn($table) as $row) {
            $usage[(int) $row['id']] = $this->masterData->usageCount($table, (int) $row['id']);
        }

        return $usage;
    }

    /** @return array<string,mixed>|null */
    private function findRow(string $kind, int $id): ?array
    {
        return match ($kind) {
            'types', 'statuses', 'priorities', 'groups' => $this->masterData->findIn(HelpdeskAdminService::SIMPLE_TABLES[$kind], $id),
            'categories' => $this->categories->find($id),
            'slas' => $this->slas->find($id),
            'tags' => $this->tags->find($id),
            'templates' => $this->templates->find($id),
            'rules' => $this->rules->find($id),
            default => throw new NotFoundException('Unbekannter Bereich.'),
        };
    }

    /** @param array<string,mixed> $input */
    private function save(string $kind, ?int $id, array $input): int
    {
        return match ($kind) {
            'types', 'statuses', 'priorities', 'groups' => $this->service->saveSimple($kind, $id, $input),
            'categories' => $this->service->saveCategory($id, $input),
            'slas' => $this->service->saveSla($id, $input),
            'tags' => $this->service->saveTag($id, $input),
            'templates' => $this->service->saveTemplate($id, $input),
            'rules' => $this->service->saveRule($id, $input),
            default => throw new NotFoundException('Unbekannter Bereich.'),
        };
    }

    /** SLA-Datensatz zurück in Formular-Eingaben (Zeit HH:MM:SS → HH:MM). @param array<string,mixed> $row @return array<string,mixed> */
    private function slaInput(array $row): array
    {
        $row['business_start'] = substr((string) ($row['business_start'] ?? '08:00:00'), 0, 5);
        $row['business_end'] = substr((string) ($row['business_end'] ?? '17:00:00'), 0, 5);

        return $row;
    }

    /** @param array<string,mixed>|null $row */
    private function renderForm(string $kind, ?array $row): Response
    {
        if ($kind === 'slas' && $row !== null) {
            $row = $this->slaInput($row);
        }
        $groupMembers = [];
        $groupLeads = [];
        if ($kind === 'groups' && $row !== null) {
            foreach ($this->masterData->groupMembers((int) $row['id']) as $member) {
                $groupMembers[] = (int) $member['user_id'];
                if (!empty($member['is_lead'])) {
                    $groupLeads[] = (int) $member['user_id'];
                }
            }
        }

        return $this->render('helpdesk.admin.form', [
            'title' => self::KINDS[$kind][0] . ($row === null ? ' – neu' : ' – bearbeiten'),
            'activeNav' => 'helpdesk-admin',
            'areaLabel' => 'Help Desk',
            'kind' => $kind,
            'kinds' => $this->visibleKinds(),
            'row' => $row,
            'colors' => HelpdeskAdminService::COLORS,
            'statusCategories' => HelpdeskAdminService::STATUS_CATEGORIES,
            'triggers' => TicketRuleRepository::TRIGGERS,
            'ruleFields' => TicketRuleEvaluator::FIELDS,
            'ruleOperators' => TicketRuleEvaluator::OPERATORS,
            'ruleActions' => TicketRuleEvaluator::ACTIONS,
            'types' => $this->masterData->types(),
            'priorities' => $this->masterData->priorities(),
            'groups' => $this->masterData->groups(),
            'agents' => $this->masterData->agents(TicketService::AGENT_ROLES),
            'categories' => $this->categories->all(true),
            'roots' => $this->categories->roots(),
            'slas' => $this->slas->all(true),
            'groupMembers' => $groupMembers,
            'groupLeads' => $groupLeads,
            'scripts' => ['/js/helpdesk.js'],
        ]);
    }
}
