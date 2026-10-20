<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\TicketAttachmentRepository;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketCommentRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRelationRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketSlaRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\TicketTemplateRepository;
use App\Repositories\TicketWorklogRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Support\Validator;

/**
 * Fachlogik des Ticketsystems: Anlage, Änderung, Statuswechsel, Zuweisung, Kommentare,
 * Asset-Verknüpfungen, Tags, Watcher, Arbeitszeiten, Beziehungen und Sichtbarkeit.
 * Alle schreibenden Vorgänge sind transaktional, schreiben den Ticketverlauf und das Audit-Log.
 */
final class TicketService
{
    public const SOURCES = ['web' => 'Help Desk', 'portal' => 'Portal', 'api' => 'API', 'email' => 'E-Mail', 'phone' => 'Telefon', 'form' => 'Störungsmeldung', 'scheduler' => 'Automatisch'];
    public const WORKLOG_ACTIVITIES = ['analysis' => 'Analyse', 'support' => 'Support', 'onsite' => 'Vor-Ort-Einsatz', 'remote' => 'Fernwartung', 'coordination' => 'Abstimmung', 'documentation' => 'Dokumentation', 'other' => 'Sonstiges'];
    public const RELATION_LABELS = ['duplicate_of' => 'Duplikat von', 'parent_of' => 'Übergeordnet zu', 'related' => 'Verwandt mit', 'problem_of' => 'Problem zu', 'change_for' => 'Change für'];
    public const AGENT_ROLES = ['admin', 'helpdesk_admin', 'helpdesk_lead', 'helpdesk_agent'];

    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketCategoryRepository $categories,
        private readonly TicketSlaRepository $slas,
        private readonly TicketTagRepository $tags,
        private readonly TicketCommentRepository $comments,
        private readonly TicketAttachmentRepository $attachments,
        private readonly TicketWorklogRepository $worklogs,
        private readonly TicketRelationRepository $relations,
        private readonly TicketTemplateRepository $templates,
        private readonly EmployeeRepository $employees,
        private readonly UserRepository $users,
        private readonly AssetRepository $assets,
        private readonly TicketNumberService $numbers,
        private readonly TicketWorkflowService $workflow,
        private readonly TicketSlaService $sla,
        private readonly TicketRuleService $rules,
        private readonly TicketNotificationService $notifications,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser,
        private readonly Config $config
    ) {}

    // ------------------------------------------------------------------ Lesen / Sichtbarkeit

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $ticket = $this->tickets->find($id);
        if ($ticket === null) {
            throw new NotFoundException('Ticket nicht gefunden.');
        }

        return $ticket;
    }

    /** @return array<string,mixed> */
    public function getByNumber(string $number): array
    {
        $normalized = TicketNumberService::normalize($number) ?? $number;
        $ticket = $this->tickets->findByNumber($normalized);
        if ($ticket === null) {
            throw new NotFoundException('Ticket nicht gefunden.');
        }

        return $ticket;
    }

    /** Agenten mit helpdesk.view sehen alles; sonst nur eigene Tickets (Melder, Ersteller, Betroffener, Watcher). */
    public function canView(array $ticket): bool
    {
        if ($this->currentUser->can('helpdesk.view')) {
            return true;
        }
        if (!$this->currentUser->can('portal.view')) {
            return false;
        }

        return $this->isOwnTicket($ticket);
    }

    /** @param array<string,mixed> $ticket */
    public function isOwnTicket(array $ticket): bool
    {
        $userId = $this->currentUser->id();
        if ($userId === null) {
            return false;
        }
        if ((int) ($ticket['requester_user_id'] ?? 0) === $userId || (int) ($ticket['created_by'] ?? 0) === $userId) {
            return true;
        }
        $employeeId = $this->currentEmployeeId();
        if ($employeeId !== null && ((int) ($ticket['requester_employee_id'] ?? 0) === $employeeId || (int) ($ticket['affected_employee_id'] ?? 0) === $employeeId)) {
            return true;
        }

        return $this->tickets->isWatching((int) $ticket['id'], $userId);
    }

    /** @return array<string,mixed> */
    public function getVisible(int $id): array
    {
        $ticket = $this->get($id);
        if (!$this->canView($ticket)) {
            throw new ForbiddenException('Keine Berechtigung für dieses Ticket.');
        }

        return $ticket;
    }

    public function isAgent(): bool
    {
        return $this->currentUser->can('helpdesk.view');
    }

    /**
     * Verlaufseintrag „viewed“: Ticket wurde von einem Agenten angesehen. Nur Agenten lösen
     * den Eintrag aus; sichtbar ist er nur mit der Berechtigung „Darf Ticketaufruf auswerten“
     * (Berechtigungsgruppe „ticketaufruf“) bzw. für Admins (siehe timelineVisible()).
     */
    public function recordView(int $ticketId): void
    {
        if (!$this->isAgent()) {
            return;
        }
        $this->event($ticketId, 'viewed', null, null, null);
    }

    public function currentEmployeeId(): ?int
    {
        $userId = $this->currentUser->id();
        if ($userId === null) {
            return null;
        }
        $user = $this->users->find($userId);

        return $user !== null && !empty($user['employee_id']) ? (int) $user['employee_id'] : null;
    }

    /** Standardansichten der Ticketliste mit Bezeichnung. @return array<string,string> */
    public static function views(): array
    {
        return [
            'mine_open' => 'Meine offenen',
            'mine' => 'Mir zugewiesen',
            'unassigned' => 'Nicht zugewiesen',
            'open' => 'Alle offenen',
            'overdue' => 'Überfällig',
            'waiting_user' => 'Wartet auf Melder',
            'critical' => 'Kritisch',
            'recently_resolved' => 'Kürzlich gelöst',
            'recently_closed' => 'Kürzlich geschlossen',
            'all' => 'Alle',
        ];
    }

    // ------------------------------------------------------------------ Anlegen

    /**
     * Neues Ticket durch Agenten (source web/phone/api), Portal (source portal) oder das
     * öffentliche Störungsformular (source form, Melderdaten unter $input['reporter']).
     * @param array<string,mixed> $input
     * @return array<string,mixed> das angelegte Ticket
     */
    public function create(array $input, string $source = 'web'): array
    {
        $portal = $source === 'portal';
        // Störungsformular: gleiche Feldbeschränkung wie im Portal, der Melder wird aber
        // nicht aus der Sitzung abgeleitet (dort läuft ein Systemkonto), sondern übergeben.
        $quickReport = $source === 'form';
        $this->currentUser->require($portal ? 'portal.create' : 'helpdesk.create');
        $data = $this->validate($input, null, $portal || $quickReport);
        $userId = $this->currentUser->id();
        $createdByName = $this->currentUser->displayName();
        $extraFields = [];

        if ($portal) {
            $employeeId = $this->currentEmployeeId();
            $data['requester_user_id'] = $userId;
            $data['requester_employee_id'] = $employeeId;
            $data['affected_employee_id'] = $data['affected_employee_id'] ?? $employeeId;
            $data['is_portal'] = true;
        }
        if ($quickReport) {
            $reporter = is_array($input['reporter'] ?? null) ? $input['reporter'] : [];
            $data['requester_employee_id'] = $this->existingEmployeeId($reporter['employee_id'] ?? null);
            $data['requester_user_id'] = $this->activeUserId($reporter['user_id'] ?? null);
            $data['affected_employee_id'] = $data['requester_employee_id'];
            $extraFields = $this->reporterFields($reporter);
            $createdByName = mb_substr(trim((string) ($reporter['display_name'] ?? '')) ?: 'Störungsmeldung', 0, 200);
        }
        if (!$quickReport && empty($data['requester_employee_id']) && empty($data['requester_user_id'])) {
            $data['requester_user_id'] = $userId;
        }
        // E-Mail-Eingang: externe Absenderadresse und Message-ID für das Threading übernehmen
        if ($source === 'email') {
            $mailFields = [];
            $email = trim((string) ($input['requester_email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $mailFields['requester_email'] = mb_substr($email, 0, 255);
            }
            $mailFields['mail_message_id'] = isset($input['mail_message_id']) ? mb_substr(trim((string) $input['mail_message_id']), 0, 255) : null;
            $from = trim((string) ($input['mail_from_address'] ?? ''));
            $mailFields['mail_from_address'] = $from !== '' ? mb_substr($from, 0, 255) : null;
            $fromName = trim((string) ($input['mail_from_name'] ?? ''));
            $mailFields['mail_from_name'] = $fromName !== '' ? mb_substr($fromName, 0, 150) : null;
            if (!empty($input['external_requester']) && (int) $data['requester_user_id'] === $userId && empty($data['requester_employee_id'])) {
                // Unbekannter externer Absender: Systembenutzer soll nicht als Melder erscheinen
                $data['requester_user_id'] = null;
            }
            $extraFields = $mailFields;
        }

        $type = $this->masterData->findType((int) $data['ticket_type_id']);
        $priority = $this->resolvePriority($data, null);
        $status = $this->masterData->statusByCode(TicketWorkflowService::NEW);
        if ($type === null || $priority === null || $status === null) {
            throw ValidationException::single('ticket_type_id', 'Stammdaten des Ticketsystems sind unvollständig.');
        }
        $category = !empty($data['category_id']) ? $this->categories->find((int) $data['category_id']) : null;
        $subcategory = !empty($data['subcategory_id']) ? $this->categories->find((int) $data['subcategory_id']) : null;
        if (empty($data['group_id'])) {
            // Standardgruppe: Unterkategorie hat Vorrang vor der Hauptkategorie
            foreach ([$subcategory, $category] as $candidate) {
                if ($candidate !== null && !empty($candidate['default_group_id'])) {
                    $data['group_id'] = (int) $candidate['default_group_id'];
                    break;
                }
            }
        }
        $requester = !empty($data['requester_employee_id']) ? $this->employees->find((int) $data['requester_employee_id']) : null;
        if ($requester !== null) {
            $data['location_id'] = $data['location_id'] ?? ($requester['location_id'] ?? null);
            $data['cost_center_id'] = $data['cost_center_id'] ?? ($requester['cost_center_id'] ?? null);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $slaRule = !empty($data['sla_id']) ? $this->slas->find((int) $data['sla_id']) : $this->slas->match((int) $priority['id'], $data['category_id'] ?? null, $data['subcategory_id'] ?? null);
        $due = $slaRule !== null ? $this->sla->dueDates($slaRule, $now) : ['response_due_at' => null, 'resolution_due_at' => null];

        $tagNames = $this->parseTags((string) ($input['tags'] ?? ''));
        $assetIds = $this->parseIds($input['asset_ids'] ?? ($input['asset_id'] ?? []));

        $ticketId = $this->tickets->transaction(function () use ($data, $priority, $status, $slaRule, $due, $now, $userId, $source, $tagNames, $assetIds, $extraFields, $createdByName): int {
            $number = $this->numbers->next($now);
            $id = $this->tickets->create([
                'number' => $number,
                'subject' => $data['subject'],
                'description' => $data['description'],
                'ticket_type_id' => (int) $data['ticket_type_id'],
                'category_id' => $data['category_id'] ?? null,
                'subcategory_id' => $data['subcategory_id'] ?? null,
                'status_id' => (int) $status['id'],
                'priority_id' => (int) $priority['id'],
                'impact' => $data['impact'] ?? 2,
                'urgency' => $data['urgency'] ?? 2,
                'sla_id' => $slaRule['id'] ?? null,
                'requester_employee_id' => $data['requester_employee_id'] ?? null,
                'requester_user_id' => $data['requester_user_id'] ?? null,
                'affected_employee_id' => $data['affected_employee_id'] ?? null,
                'assignee_user_id' => $data['assignee_user_id'] ?? null,
                'deputy_user_id' => $data['deputy_user_id'] ?? null,
                'group_id' => $data['group_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'external_reference' => $data['external_reference'] ?? null,
                'source' => array_key_exists($source, self::SOURCES) ? $source : 'web',
                'response_due_at' => $due['response_due_at'],
                'resolution_due_at' => $due['resolution_due_at'],
                'sla_response_state' => $due['response_due_at'] !== null ? TicketSlaService::STATE_OK : TicketSlaService::STATE_NONE,
                'sla_resolution_state' => $due['resolution_due_at'] !== null ? TicketSlaService::STATE_OK : TicketSlaService::STATE_NONE,
                'created_by' => $userId,
                'created_by_name' => $createdByName,
                'updated_by' => $userId,
            ] + $extraFields);
            foreach ($tagNames as $name) {
                $this->tags->attach($id, $this->tags->ensure($name));
            }
            foreach ($assetIds as $assetId) {
                if ($this->assets->find($assetId) !== null) {
                    $this->tickets->addAsset($id, $assetId, null, $userId);
                }
            }
            if (!empty($data['assignee_user_id'])) {
                $this->event($id, 'assigned', null, null, $this->userName((int) $data['assignee_user_id']));
            }
            $this->event($id, 'created', null, null, $number, ['source' => $source] + (isset($extraFields['mail_from_address']) && $extraFields['mail_from_address'] !== null
                ? ['mail_from' => $extraFields['mail_from_address'], 'mail_from_name' => $extraFields['mail_from_name'] ?? null]
                : []));

            return $id;
        });

        $ticket = $this->get($ticketId);
        $this->audit->log('create', 'ticket', $ticketId, $ticket['number'] . ' ' . $ticket['subject'], null, $this->auditSnapshot($ticket));
        $this->rules->apply('created', $ticket, $userId, $this->currentUser->displayName());
        $ticket = $this->get($ticketId);
        $this->notifications->ticketCreated($ticket);
        if (!empty($ticket['assignee_user_id'])) {
            $this->notifications->assigned($ticket);
        }

        return $ticket;
    }

    /** Ticket aus Vorlage vorbelegen (für das Formular). @return array<string,mixed> */
    public function prefillFromTemplate(int $templateId, bool $portal): array
    {
        $template = $this->templates->find($templateId);
        if ($template === null || empty($template['is_active']) || ($portal && empty($template['is_portal_visible']))) {
            return [];
        }

        return [
            'subject' => $template['subject'],
            'description' => $template['body'],
            'ticket_type_id' => $template['ticket_type_id'],
            'category_id' => $template['category_id'],
            'subcategory_id' => $template['subcategory_id'],
            'priority_id' => $template['priority_id'],
            'group_id' => $template['group_id'],
            'assignee_user_id' => $template['assignee_user_id'],
            'sla_id' => $template['sla_id'],
            'tags' => $template['tags'],
            'template_id' => $template['id'],
        ];
    }

    // ------------------------------------------------------------------ Ändern

    /**
     * Der Beschreibungstext eines bestehenden Tickets darf nur von Help-Desk-Administratoren
     * geändert werden; alle anderen Bearbeiter behalten den ursprünglichen Text bei.
     */
    public function canEditDescription(): bool
    {
        return $this->currentUser->can('helpdesk.admin');
    }

    /**
     * Stammdaten eines Tickets ändern (nicht Status/Zuweisung – dafür eigene Methoden).
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $id, array $input, ?int $expectedVersion = null): array
    {
        $this->currentUser->require('helpdesk.update');
        $ticket = $this->get($id);
        $this->assertNotMerged($ticket);
        if (!$this->canEditDescription()) {
            // Der Beschreibungstext darf nur von Help-Desk-Administratoren geändert werden.
            $input['description'] = $ticket['description'];
        }
        $data = $this->validate($input, $ticket, false);
        $priority = $this->resolvePriority($data, $ticket);
        if ($priority === null) {
            throw ValidationException::single('priority_id', 'Priorität ungültig.');
        }

        $update = [
            'subject' => $data['subject'],
            'description' => $data['description'],
            'ticket_type_id' => (int) $data['ticket_type_id'],
            'category_id' => $data['category_id'] ?? null,
            'subcategory_id' => $data['subcategory_id'] ?? null,
            'priority_id' => (int) $priority['id'],
            'impact' => $data['impact'] ?? $ticket['impact'],
            'urgency' => $data['urgency'] ?? $ticket['urgency'],
            'requester_employee_id' => $data['requester_employee_id'] ?? null,
            'affected_employee_id' => $data['affected_employee_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
            'updated_by' => $this->currentUser->id(),
        ];
        // SLA nur neu berechnen, wenn sie sich (durch Priorität/Kategorie oder manuell) ändert
        $slaId = !empty($data['sla_id']) ? (int) $data['sla_id'] : ($this->slas->match((int) $priority['id'], $update['category_id'], $update['subcategory_id'])['id'] ?? null);
        if ($slaId !== null) {
            $slaId = (int) $slaId;
        }
        if ($slaId !== (int) ($ticket['sla_id'] ?? 0) && !$this->workflow->isResolvedOrFinal((string) $ticket['status_code'])) {
            $update['sla_id'] = $slaId;
            $slaRule = $slaId !== null ? $this->slas->find($slaId) : null;
            $due = $slaRule !== null ? $this->sla->dueDates($slaRule, $this->sla->parse((string) $ticket['created_at'])) : ['response_due_at' => null, 'resolution_due_at' => null];
            $update['response_due_at'] = $this->sla->shiftDue($due['response_due_at'], (int) $ticket['sla_paused_minutes']);
            $update['resolution_due_at'] = $this->sla->shiftDue($due['resolution_due_at'], (int) $ticket['sla_paused_minutes']);
        }

        $this->tickets->transaction(function () use ($ticket, $update, $expectedVersion, $input): void {
            $id = (int) $ticket['id'];
            if (!$this->tickets->update($id, $update, $expectedVersion)) {
                throw new ConflictException('Das Ticket wurde zwischenzeitlich geändert. Bitte neu laden.');
            }
            $this->diffEvents($ticket, $update);
            if (array_key_exists('tags', $input)) {
                $this->syncTags($id, $this->parseTags((string) $input['tags']));
            }
        });

        $fresh = $this->get($id);
        $this->audit->log('update', 'ticket', $id, $fresh['number'] . ' ' . $fresh['subject'], $this->auditSnapshot($ticket), $this->auditSnapshot($fresh));
        $this->rules->apply('updated', $fresh, $this->currentUser->id(), $this->currentUser->displayName());

        return $this->get($id);
    }

    /**
     * Statuswechsel gemäß Workflow inkl. SLA-Pause/-Fortsetzung, Lösung, Wiedereröffnung.
     * @return array<string,mixed>
     */
    public function changeStatus(int $id, string $toCode, ?string $note = null, ?string $resolution = null, ?string $reason = null, ?int $expectedVersion = null, bool $system = false): array
    {
        $ticket = $this->get($id);
        $this->assertNotMerged($ticket);
        $fromCode = (string) $ticket['status_code'];
        if (!$this->workflow->canTransition($fromCode, $toCode)) {
            throw ValidationException::single('status', "Statuswechsel von „{$ticket['status_name']}“ nach „{$toCode}“ ist nicht erlaubt.");
        }
        if (!$system) {
            $this->currentUser->require($this->workflow->requiredPermission($fromCode, $toCode));
        }
        $toStatus = $this->masterData->statusByCode($toCode);
        if ($toStatus === null) {
            throw ValidationException::single('status', 'Unbekannter Status.');
        }
        $resolution = $resolution !== null ? trim($resolution) : null;
        if ($this->workflow->requiresResolution($toCode) && ($resolution === null || $resolution === '') && empty($ticket['resolution'])) {
            throw ValidationException::single('resolution', 'Bitte eine Lösungsbeschreibung angeben.');
        }
        if ($this->workflow->requiresReason($toCode) && trim((string) $reason) === '') {
            throw ValidationException::single('close_reason', 'Bitte einen Grund für die Stornierung angeben.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $nowDt = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
        $update = ['status_id' => (int) $toStatus['id'], 'updated_by' => $this->currentUser->id()];

        // SLA pausieren/fortsetzen
        $wasPaused = !empty($ticket['pauses_sla']) && !empty($ticket['sla_paused_at']);
        if ($wasPaused) {
            $paused = (int) floor(($nowDt->getTimestamp() - $this->sla->parse((string) $ticket['sla_paused_at'])->getTimestamp()) / 60);
            $update['sla_paused_at'] = null;
            $update['sla_paused_minutes'] = (int) $ticket['sla_paused_minutes'] + max(0, $paused);
            $update['response_due_at'] = $ticket['first_response_at'] !== null ? $ticket['response_due_at'] : $this->sla->shiftDue($ticket['response_due_at'], $paused);
            $update['resolution_due_at'] = $this->sla->shiftDue($ticket['resolution_due_at'], $paused);
        }
        if (!empty($toStatus['pauses_sla'])) {
            $update['sla_paused_at'] = $now;
        }

        $isReopen = $this->workflow->isReopen($fromCode, $toCode);
        switch ($toCode) {
            case TicketWorkflowService::RESOLVED:
                $update['resolved_at'] = $now;
                if ($resolution !== null && $resolution !== '') {
                    $update['resolution'] = $resolution;
                }
                $update['sla_resolution_state'] = $this->sla->evaluate($ticket['resolution_due_at'], $now, $nowDt, $ticket['created_at'], 100);
                if ($ticket['first_response_at'] === null) {
                    $update['first_response_at'] = $now;
                    $update['sla_response_state'] = $this->sla->evaluate($ticket['response_due_at'], $now, $nowDt, $ticket['created_at'], 100);
                }
                break;
            case TicketWorkflowService::CLOSED:
                $update['closed_at'] = $now;
                if ($ticket['resolved_at'] === null) {
                    $update['resolved_at'] = $now;
                    $update['sla_resolution_state'] = $this->sla->evaluate($ticket['resolution_due_at'], $now, $nowDt, $ticket['created_at'], 100);
                }
                if ($reason !== null && trim($reason) !== '') {
                    $update['close_reason'] = trim($reason);
                }
                break;
            case TicketWorkflowService::CANCELLED:
                $update['cancelled_at'] = $now;
                $update['close_reason'] = trim((string) $reason);
                break;
            default:
                if ($isReopen) {
                    $update['reopen_count'] = (int) $ticket['reopen_count'] + 1;
                    $update['resolved_at'] = null;
                    $update['closed_at'] = null;
                    $update['sla_resolution_state'] = $ticket['resolution_due_at'] !== null ? TicketSlaService::STATE_OK : TicketSlaService::STATE_NONE;
                }
                if ($ticket['first_response_at'] === null && !$system && $this->isAgent() && $toCode !== TicketWorkflowService::OPEN) {
                    $update['first_response_at'] = $now;
                    $update['sla_response_state'] = $this->sla->evaluate($ticket['response_due_at'], $now, $nowDt, $ticket['created_at'], 100);
                }
        }

        $this->tickets->transaction(function () use ($ticket, $update, $expectedVersion, $fromCode, $toCode, $toStatus, $note, $isReopen): void {
            $id = (int) $ticket['id'];
            if (!$this->tickets->update($id, $update, $expectedVersion)) {
                throw new ConflictException('Das Ticket wurde zwischenzeitlich geändert. Bitte neu laden.');
            }
            $this->event($id, $this->workflow->eventFor($fromCode, $toCode), 'status', (string) $ticket['status_name'], (string) $toStatus['name'], $note !== null && trim($note) !== '' ? ['note' => trim($note)] : null);
            if ($note !== null && trim($note) !== '') {
                $this->insertComment($ticket, trim($note), $isReopen || !$this->isAgent() ? 'public' : 'internal');
            }
        });

        $fresh = $this->get($id);
        $this->audit->log('status', 'ticket', $id, $fresh['number'] . ' → ' . $toStatus['name'], ['status' => $ticket['status_name']], ['status' => $toStatus['name']]);
        $this->rules->apply('status_changed', $fresh, $this->currentUser->id(), $this->currentUser->displayName());
        $this->notifications->statusChanged($fresh, $fromCode, $toCode);

        return $this->get($id);
    }

    /** Wiedereröffnung durch den Melder über das Portal (innerhalb der Frist). @return array<string,mixed> */
    public function reopenFromPortal(int $id, string $note): array
    {
        $ticket = $this->getVisible($id);
        if (!$this->isOwnTicket($ticket)) {
            throw new ForbiddenException('Nur der Melder kann dieses Ticket wieder öffnen.');
        }
        if (!$this->canReopenFromPortal($ticket)) {
            throw ValidationException::single('note', 'Dieses Ticket kann nicht mehr wieder geöffnet werden.');
        }
        if (trim($note) === '') {
            throw ValidationException::single('note', 'Bitte beschreiben Sie, warum das Ticket wieder geöffnet werden soll.');
        }

        return $this->changeStatus($id, TicketWorkflowService::IN_PROGRESS, $note, null, null, null, true);
    }

    /** @param array<string,mixed> $ticket */
    public function canReopenFromPortal(array $ticket): bool
    {
        $code = (string) $ticket['status_code'];
        if ($code !== TicketWorkflowService::RESOLVED && $code !== TicketWorkflowService::CLOSED) {
            return false;
        }
        $reference = $ticket['closed_at'] ?? $ticket['resolved_at'] ?? null;
        if ($reference === null) {
            return true;
        }
        $days = (int) $this->config->get('helpdesk.reopen_days', 14);

        return $this->sla->parse((string) $reference)->modify("+{$days} days") >= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Zuweisung an Bearbeiter, Vertretung und/oder Gruppe.
     * @return array<string,mixed>
     */
    public function assign(int $id, ?int $assigneeId, ?int $groupId, ?int $deputyId = null, bool $keepGroup = false, ?int $expectedVersion = null): array
    {
        $this->currentUser->require('helpdesk.assign');
        $ticket = $this->get($id);
        $this->assertNotMerged($ticket);
        if ($this->workflow->isFinal((string) $ticket['status_code'])) {
            throw ValidationException::single('assignee_user_id', 'Abgeschlossene Tickets können nicht neu zugewiesen werden.');
        }
        $update = ['assignee_user_id' => $this->validUser($assigneeId, 'assignee_user_id'), 'deputy_user_id' => $this->validUser($deputyId, 'deputy_user_id'), 'updated_by' => $this->currentUser->id()];
        if (!$keepGroup) {
            if ($groupId !== null && $this->masterData->findGroup($groupId) === null) {
                throw ValidationException::single('group_id', 'Gruppe nicht gefunden.');
            }
            $update['group_id'] = $groupId;
        }
        if ($update['assignee_user_id'] !== null && $ticket['first_response_at'] === null && in_array((string) $ticket['status_code'], [TicketWorkflowService::NEW], true)) {
            // Annahme durch Bearbeiter setzt „offen“
            $open = $this->masterData->statusByCode(TicketWorkflowService::OPEN);
            if ($open !== null) {
                $update['status_id'] = (int) $open['id'];
            }
        }

        $this->tickets->transaction(function () use ($ticket, $update, $expectedVersion): void {
            $id = (int) $ticket['id'];
            if (!$this->tickets->update($id, $update, $expectedVersion)) {
                throw new ConflictException('Das Ticket wurde zwischenzeitlich geändert. Bitte neu laden.');
            }
            if ((int) ($ticket['assignee_user_id'] ?? 0) !== (int) ($update['assignee_user_id'] ?? 0)) {
                $this->event($id, $update['assignee_user_id'] === null ? 'unassigned' : 'assigned', 'assignee', $ticket['assignee_name'] ?? null, $update['assignee_user_id'] !== null ? $this->userName((int) $update['assignee_user_id']) : null);
            }
            if (array_key_exists('group_id', $update) && (int) ($ticket['group_id'] ?? 0) !== (int) ($update['group_id'] ?? 0)) {
                $this->event($id, 'group_changed', 'group', $ticket['group_name'] ?? null, $update['group_id'] !== null ? (string) ($this->masterData->findGroup((int) $update['group_id'])['name'] ?? '') : null);
            }
            if ((int) ($ticket['deputy_user_id'] ?? 0) !== (int) ($update['deputy_user_id'] ?? 0)) {
                $this->event($id, 'deputy_changed', 'deputy', $ticket['deputy_name'] ?? null, $update['deputy_user_id'] !== null ? $this->userName((int) $update['deputy_user_id']) : null);
            }
            if (isset($update['status_id'])) {
                $this->event($id, 'status_changed', 'status', (string) $ticket['status_name'], 'Offen');
            }
        });

        $fresh = $this->get($id);
        $this->audit->log('assign', 'ticket', $id, $fresh['number'], ['assignee' => $ticket['assignee_name'], 'group' => $ticket['group_name']], ['assignee' => $fresh['assignee_name'], 'group' => $fresh['group_name']]);
        $this->notifications->assigned($fresh, $ticket['assignee_email'] ?? null);

        return $fresh;
    }

    /** Ticket dem angemeldeten Benutzer zuweisen. @return array<string,mixed> */
    public function takeOver(int $id): array
    {
        $userId = $this->currentUser->id();
        if ($userId === null) {
            throw new ForbiddenException('Nicht angemeldet.');
        }
        $ticket = $this->get($id);

        return $this->assign($id, $userId, $ticket['group_id'] !== null ? (int) $ticket['group_id'] : null, $ticket['deputy_user_id'] !== null ? (int) $ticket['deputy_user_id'] : null, true);
    }

    // ------------------------------------------------------------------ Kommentare

    /**
     * Kommentar hinzufügen (öffentlich oder intern). Portalnutzer dürfen nur öffentlich kommentieren.
     * @return array<string,mixed> Kommentar
     */
    public function addComment(int $id, string $body, string $type = 'public', string $source = 'web'): array
    {
        $ticket = $this->getVisible($id);
        $this->assertNotMerged($ticket);
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::single('body', 'Bitte einen Kommentartext eingeben.');
        }
        if (mb_strlen($body) > 20000) {
            throw ValidationException::single('body', 'Der Kommentar ist zu lang (max. 20.000 Zeichen).');
        }
        $type = $type === 'internal' ? 'internal' : 'public';
        if ($this->isAgent()) {
            $this->currentUser->require($type === 'internal' ? 'helpdesk.internal_note' : 'helpdesk.comment');
        } else {
            $type = 'public';
            if ($this->workflow->isFinal((string) $ticket['status_code'])) {
                throw ValidationException::single('body', 'Abgeschlossene Tickets können nicht mehr kommentiert werden.');
            }
        }

        $commentId = $this->tickets->transaction(function () use ($ticket, $body, $type, $source): int {
            $commentId = $this->insertComment($ticket, $body, $type, $source);
            $now = gmdate('Y-m-d H:i:s');
            $update = ['updated_by' => $this->currentUser->id()];
            if ($type === 'public') {
                $update['last_public_comment_at'] = $now;
            }
            if ($this->isAgent() && !$this->isOwnTicket($ticket)) {
                $update['last_agent_comment_at'] = $now;
                if ($ticket['first_response_at'] === null && $type === 'public') {
                    $update['first_response_at'] = $now;
                    $update['sla_response_state'] = $this->sla->evaluate($ticket['response_due_at'], $now, new \DateTimeImmutable($now, new \DateTimeZone('UTC')), $ticket['created_at'], 100);
                }
            } elseif ((string) $ticket['status_code'] === TicketWorkflowService::WAITING_USER) {
                // Rückmeldung des Melders beendet die Wartezeit automatisch
                $inProgress = $this->masterData->statusByCode(TicketWorkflowService::IN_PROGRESS);
                if ($inProgress !== null) {
                    $paused = $ticket['sla_paused_at'] !== null ? (int) floor((time() - $this->sla->parse((string) $ticket['sla_paused_at'])->getTimestamp()) / 60) : 0;
                    $update['status_id'] = (int) $inProgress['id'];
                    $update['sla_paused_at'] = null;
                    $update['sla_paused_minutes'] = (int) $ticket['sla_paused_minutes'] + max(0, $paused);
                    $update['resolution_due_at'] = $this->sla->shiftDue($ticket['resolution_due_at'], $paused);
                    $this->event((int) $ticket['id'], 'status_changed', 'status', (string) $ticket['status_name'], (string) $inProgress['name'], ['auto' => true]);
                }
            }
            $this->tickets->update((int) $ticket['id'], $update);
            $this->event((int) $ticket['id'], $type === 'internal' ? 'internal_note' : 'comment', null, null, mb_substr($body, 0, 120), ['comment_id' => $commentId]);

            return $commentId;
        });

        $comment = $this->comments->find($commentId) ?? [];
        $fresh = $this->get($id);
        $this->audit->log('comment', 'ticket', $id, $fresh['number'], null, ['type' => $type, 'comment_id' => $commentId]);
        $this->rules->apply('comment_added', $fresh, $this->currentUser->id(), $this->currentUser->displayName());
        $this->notifications->commentAdded($fresh, $comment);

        return $comment;
    }

    /** @param array<string,mixed> $ticket */
    private function insertComment(array $ticket, string $body, string $type, string $source = 'web'): int
    {
        return $this->comments->create([
            'ticket_id' => (int) $ticket['id'],
            'type' => $type,
            'body' => $body,
            'author_user_id' => $this->currentUser->id(),
            'author_name' => $this->currentUser->displayName(),
            'is_requester' => $this->isOwnTicket($ticket) ? 1 : 0,
            'source' => array_key_exists($source, self::SOURCES) ? $source : 'web',
        ]);
    }

    /**
     * Kommentar aus einer eingehenden E-Mail. Läuft unter dem Systembenutzer des Mail-Eingangs,
     * wird aber dem tatsächlichen Absender zugeschrieben: Antworten des Melders beenden „Wartet auf Melder“
     * automatisch, Antworten von Agenten zählen als Agentenreaktion (Erstreaktion/SLA).
     *
     * @param array{user_id:?int,name:string,email:string,is_requester:bool,is_agent:bool} $author
     * @return array<string,mixed> Kommentar
     */
    public function addInboundMailComment(int $id, string $body, array $author, string $messageId, string $fromAddress = ''): array
    {
        $this->currentUser->require('helpdesk.comment');
        $ticket = $this->get($id);
        if (!empty($ticket['merged_into_ticket_id'])) {
            // Antworten auf zusammengeführte Tickets landen im Zielticket
            $ticket = $this->get((int) $ticket['merged_into_ticket_id']);
            $id = (int) $ticket['id'];
        }
        $body = trim($body);
        if ($body === '') {
            $body = '(Leere Nachricht)';
        }
        $body = mb_substr($body, 0, 20000);

        $commentId = $this->tickets->transaction(function () use ($ticket, $body, $author, $messageId, $fromAddress): int {
            $sender = trim($fromAddress) !== '' ? trim($fromAddress) : $author['email'];
            $commentId = $this->comments->create([
                'ticket_id' => (int) $ticket['id'],
                'type' => 'public',
                'body' => $body,
                'author_user_id' => $author['user_id'],
                'author_name' => mb_substr($author['name'] !== '' ? $author['name'] : $author['email'], 0, 150),
                'is_requester' => $author['is_requester'] ? 1 : 0,
                'source' => 'email',
                'mail_message_id' => mb_substr($messageId, 0, 255),
                'mail_from_address' => $sender !== '' ? mb_substr($sender, 0, 255) : null,
            ]);
            $now = gmdate('Y-m-d H:i:s');
            $update = ['updated_by' => $this->currentUser->id(), 'last_public_comment_at' => $now];
            if ($author['is_agent'] && !$author['is_requester']) {
                $update['last_agent_comment_at'] = $now;
                if ($ticket['first_response_at'] === null) {
                    $update['first_response_at'] = $now;
                    $update['sla_response_state'] = $this->sla->evaluate($ticket['response_due_at'], $now, new \DateTimeImmutable($now, new \DateTimeZone('UTC')), $ticket['created_at'], 100);
                }
            } elseif ((string) $ticket['status_code'] === TicketWorkflowService::WAITING_USER) {
                $inProgress = $this->masterData->statusByCode(TicketWorkflowService::IN_PROGRESS);
                if ($inProgress !== null) {
                    $paused = $ticket['sla_paused_at'] !== null ? (int) floor((time() - $this->sla->parse((string) $ticket['sla_paused_at'])->getTimestamp()) / 60) : 0;
                    $update['status_id'] = (int) $inProgress['id'];
                    $update['sla_paused_at'] = null;
                    $update['sla_paused_minutes'] = (int) $ticket['sla_paused_minutes'] + max(0, $paused);
                    $update['resolution_due_at'] = $this->sla->shiftDue($ticket['resolution_due_at'], $paused);
                    $this->event((int) $ticket['id'], 'status_changed', 'status', (string) $ticket['status_name'], (string) $inProgress['name'], ['auto' => true, 'via' => 'email']);
                }
            } elseif ((string) $ticket['status_code'] === TicketWorkflowService::RESOLVED && !$author['is_agent']) {
                // Melder antwortet auf ein gelöstes Ticket: wieder öffnen statt Antwort zu verlieren
                $reopened = $this->masterData->statusByCode(TicketWorkflowService::OPEN) ?? $this->masterData->statusByCode(TicketWorkflowService::IN_PROGRESS);
                if ($reopened !== null) {
                    $update['status_id'] = (int) $reopened['id'];
                    $update['resolved_at'] = null;
                    $this->event((int) $ticket['id'], 'status_changed', 'status', (string) $ticket['status_name'], (string) $reopened['name'], ['auto' => true, 'via' => 'email']);
                }
            }
            $this->tickets->update((int) $ticket['id'], $update);
            $this->tickets->addEvent((int) $ticket['id'], 'comment', $author['user_id'], $author['name'] !== '' ? $author['name'] : $author['email'], null, null, mb_substr($body, 0, 120), ['comment_id' => $commentId, 'via' => 'email', 'mail_from' => $sender]);

            return $commentId;
        });

        $comment = $this->comments->find($commentId) ?? [];
        $fresh = $this->get($id);
        $this->audit->log('comment', 'ticket', $id, $fresh['number'], null, ['type' => 'public', 'comment_id' => $commentId, 'via' => 'email', 'from' => $author['email']]);
        $this->rules->apply('comment_added', $fresh, $author['user_id'] ?? $this->currentUser->id(), $author['name']);
        $this->notifications->commentAdded($fresh, $comment);

        return $comment;
    }

    /** Kommentare des Tickets; interne nur für Agenten. @return array<int,array<string,mixed>> */
    public function comments(array $ticket): array
    {
        return $this->comments->forTicket((int) $ticket['id'], $this->isAgent());
    }

    /** @return array<int,array<string,mixed>> */
    public function attachments(array $ticket): array
    {
        return $this->attachments->forTicket((int) $ticket['id'], $this->isAgent());
    }

    // ------------------------------------------------------------------ Assets

    public function addAsset(int $ticketId, int $assetId, ?string $note = null): void
    {
        $this->currentUser->require('helpdesk.update');
        $ticket = $this->get($ticketId);
        $this->assertNotMerged($ticket);
        $asset = $this->assets->find($assetId);
        if ($asset === null) {
            throw ValidationException::single('asset_id', 'Asset nicht gefunden.');
        }
        if ($this->tickets->hasAsset($ticketId, $assetId)) {
            return;
        }
        $this->tickets->transaction(function () use ($ticketId, $assetId, $note, $asset): void {
            $this->tickets->addAsset($ticketId, $assetId, $note !== null ? mb_substr(trim($note), 0, 255) : null, $this->currentUser->id());
            $this->event($ticketId, 'asset_linked', 'asset', null, (string) $asset['inventory_number'], ['asset_id' => $assetId]);
            $this->tickets->update($ticketId, ['updated_by' => $this->currentUser->id()]);
        });
        $this->audit->log('link', 'ticket', $ticketId, $ticket['number'], null, ['asset' => $asset['inventory_number']]);
    }

    public function removeAsset(int $ticketId, int $assetId): void
    {
        $this->currentUser->require('helpdesk.update');
        $ticket = $this->get($ticketId);
        $asset = $this->assets->find($assetId);
        $this->tickets->transaction(function () use ($ticketId, $assetId, $asset): void {
            if ($this->tickets->removeAsset($ticketId, $assetId) > 0) {
                $this->event($ticketId, 'asset_unlinked', 'asset', (string) ($asset['inventory_number'] ?? $assetId), null, ['asset_id' => $assetId]);
                $this->tickets->update($ticketId, ['updated_by' => $this->currentUser->id()]);
            }
        });
        $this->audit->log('unlink', 'ticket', $ticketId, $ticket['number'], ['asset' => $asset['inventory_number'] ?? $assetId], null);
    }

    /** Assets des Melders (Vorschläge beim Anlegen). @return array<int,array<string,mixed>> */
    public function assetsForEmployee(?int $employeeId): array
    {
        if ($employeeId === null) {
            return [];
        }

        return $this->assets->search(['employee_id' => $employeeId], 50, 0);
    }

    // ------------------------------------------------------------------ Tags / Watcher / Worklogs / Beziehungen

    /** @param array<int,string> $names */
    public function syncTags(int $ticketId, array $names): void
    {
        $existing = array_map(static fn (array $t): string => (string) $t['name'], $this->tags->forTicket($ticketId));
        $names = array_values(array_unique($names));
        $added = array_diff($names, $existing);
        $removed = array_diff($existing, $names);
        if ($added === [] && $removed === []) {
            return;
        }
        $this->tags->detachAll($ticketId);
        foreach ($names as $name) {
            $this->tags->attach($ticketId, $this->tags->ensure($name));
        }
        $this->event($ticketId, 'tags_changed', 'tags', implode(', ', $existing), implode(', ', $names));
    }

    public function setTags(int $ticketId, string $tags): void
    {
        $this->currentUser->require('helpdesk.update');
        $this->get($ticketId);
        $this->tickets->transaction(fn () => $this->syncTags($ticketId, $this->parseTags($tags)));
    }

    public function toggleWatch(int $ticketId): bool
    {
        $ticket = $this->getVisible($ticketId);
        $userId = $this->currentUser->id();
        if ($userId === null) {
            throw new ForbiddenException('Nicht angemeldet.');
        }
        if ($this->tickets->isWatching($ticketId, $userId)) {
            $this->tickets->removeWatcher($ticketId, $userId);

            return false;
        }
        $this->tickets->addWatcher($ticketId, $userId);
        $this->event($ticketId, 'watcher_added', null, null, $this->currentUser->displayName());

        return true;
    }

    public function addWatcher(int $ticketId, int $userId): void
    {
        $this->currentUser->require('helpdesk.update');
        $this->get($ticketId);
        $this->validUser($userId, 'user_id');
        $this->tickets->addWatcher($ticketId, $userId);
        $this->event($ticketId, 'watcher_added', null, null, $this->userName($userId));
    }

    public function removeWatcher(int $ticketId, int $userId): void
    {
        $this->currentUser->require('helpdesk.update');
        $this->tickets->removeWatcher($ticketId, $userId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function addWorklog(int $ticketId, array $input): array
    {
        $this->currentUser->require('helpdesk.worklog');
        $ticket = $this->get($ticketId);
        $this->assertNotMerged($ticket);
        $v = (new Validator($input))
            ->int('minutes', 'Dauer (Minuten)', true, 1, 1440 * 30)
            ->in('activity', 'Tätigkeit', array_keys(self::WORKLOG_ACTIVITIES), true)
            ->text('note', 'Notiz', false, 2000)
            ->date('worked_on', 'Datum');
        if (isset($input['minutes']) && is_numeric($input['minutes']) && (int) $input['minutes'] % 15 !== 0) {
            $v->addError('minutes', 'Dauer (Minuten) muss ein Vielfaches von 15 Minuten sein.');
        }
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data = $v->validated();
        $workedOn = $data['worked_on'] ?? gmdate('Y-m-d');
        $started = (new \DateTimeImmutable($workedOn . ' 12:00:00', new \DateTimeZone((string) $this->config->get('app.timezone', 'UTC'))))->setTimezone(new \DateTimeZone('UTC'));
        $id = $this->worklogs->create([
            'ticket_id' => $ticketId,
            'user_id' => $this->currentUser->id(),
            'user_name' => $this->currentUser->displayName(),
            'started_at' => $started->format('Y-m-d H:i:s'),
            'ended_at' => $started->modify('+' . (int) $data['minutes'] . ' minutes')->format('Y-m-d H:i:s'),
            'minutes' => (int) $data['minutes'],
            'activity' => $data['activity'],
            'note' => $data['note'],
        ]);
        $this->event($ticketId, 'worklog_added', 'worklog', null, $data['minutes'] . ' min ' . self::WORKLOG_ACTIVITIES[$data['activity']]);
        $this->audit->log('worklog', 'ticket', $ticketId, $ticket['number'], null, ['minutes' => $data['minutes'], 'activity' => $data['activity']]);

        return $this->worklogs->find($id) ?? [];
    }

    public function deleteWorklog(int $ticketId, int $worklogId): void
    {
        $this->currentUser->require('helpdesk.worklog');
        $log = $this->worklogs->find($worklogId);
        if ($log === null || (int) $log['ticket_id'] !== $ticketId) {
            throw new NotFoundException('Arbeitszeit nicht gefunden.');
        }
        if ((int) $log['user_id'] !== $this->currentUser->id() && !$this->currentUser->can('helpdesk.admin')) {
            throw new ForbiddenException('Nur eigene Arbeitszeiten können gelöscht werden.');
        }
        $this->worklogs->delete($worklogId);
        $this->event($ticketId, 'worklog_removed', 'worklog', $log['minutes'] . ' min', null);
    }

    public function addRelation(int $ticketId, string $relatedNumberOrId, string $type): void
    {
        $this->currentUser->require('helpdesk.update');
        $ticket = $this->get($ticketId);
        if (!in_array($type, TicketRelationRepository::TYPES, true)) {
            throw ValidationException::single('type', 'Unbekannter Beziehungstyp.');
        }
        $related = ctype_digit($relatedNumberOrId) ? $this->tickets->find((int) $relatedNumberOrId) : $this->tickets->findByNumber(TicketNumberService::normalize($relatedNumberOrId) ?? $relatedNumberOrId);
        if ($related === null) {
            throw ValidationException::single('related', 'Verknüpftes Ticket nicht gefunden.');
        }
        if ((int) $related['id'] === $ticketId) {
            throw ValidationException::single('related', 'Ein Ticket kann nicht mit sich selbst verknüpft werden.');
        }
        if ($this->relations->exists($ticketId, (int) $related['id'], $type)) {
            return;
        }
        $this->relations->create($ticketId, (int) $related['id'], $type, $this->currentUser->id());
        $this->event($ticketId, 'relation_added', 'relation', null, self::RELATION_LABELS[$type] . ' ' . $related['number']);
        $this->event((int) $related['id'], 'relation_added', 'relation', null, 'Verknüpft mit ' . $ticket['number']);
        $this->audit->log('link', 'ticket', $ticketId, $ticket['number'], null, ['relation' => $type, 'ticket' => $related['number']]);
    }

    public function removeRelation(int $ticketId, int $relationId): void
    {
        $this->currentUser->require('helpdesk.update');
        $relation = $this->relations->find($relationId);
        if ($relation === null || ((int) $relation['ticket_id'] !== $ticketId && (int) $relation['related_ticket_id'] !== $ticketId)) {
            throw new NotFoundException('Beziehung nicht gefunden.');
        }
        $this->relations->delete($relationId);
        $this->event($ticketId, 'relation_removed', 'relation', $relation['type'], null);
    }

    // ------------------------------------------------------------------ Löschen

    public function delete(int $id): void
    {
        $this->currentUser->require('helpdesk.delete');
        $ticket = $this->get($id);
        $this->tickets->transaction(function () use ($id): void {
            $this->tickets->pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        });
        $this->audit->log('delete', 'ticket', $id, $ticket['number'] . ' ' . $ticket['subject'], $this->auditSnapshot($ticket), null);
    }

    // ------------------------------------------------------------------ Hilfen

    /** @return array<int,array<string,mixed>> */
    public function timeline(int $ticketId): array
    {
        return $this->tickets->events($ticketId);
    }

    /**
     * Verlauf für die Anzeige: „viewed“-Ereignisse (Ticketaufrufe) sind nur mit der Berechtigung
     * „Darf Ticketaufruf auswerten“ (Berechtigungsgruppe „ticketaufruf“) sichtbar; Admins haben
     * das Recht über ihre Rolle.
     * @return array<int,array<string,mixed>>
     */
    public function timelineVisible(int $ticketId): array
    {
        $events = $this->timeline($ticketId);
        if ($this->currentUser->can('helpdesk.view_history')) {
            return $events;
        }

        return array_values(array_filter($events, static fn (array $e): bool => ($e['type'] ?? null) !== 'viewed'));
    }

    /** Zulässige Folgestatus für die Aktionsleiste (inkl. Berechtigungsprüfung). @return array<int,array<string,mixed>> */
    public function availableTransitions(array $ticket): array
    {
        $out = [];
        $from = (string) $ticket['status_code'];
        foreach ($this->workflow->allowedTransitions($from) as $code) {
            if (!$this->currentUser->can($this->workflow->requiredPermission($from, $code))) {
                continue;
            }
            $status = $this->masterData->statusByCode($code);
            if ($status !== null) {
                $out[] = $status + ['is_reopen' => $this->workflow->isReopen($from, $code), 'requires_resolution' => $this->workflow->requiresResolution($code), 'requires_reason' => $this->workflow->requiresReason($code)];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $data @param array<string,mixed>|null $ticket @return array<string,mixed>|null */
    private function resolvePriority(array $data, ?array $ticket): ?array
    {
        if (!empty($data['priority_id'])) {
            return $this->masterData->findPriority((int) $data['priority_id']);
        }
        if (isset($data['impact'], $data['urgency'])) {
            $byLevel = $this->masterData->priorityByLevel(TicketPriorityMatrix::level((int) $data['impact'], (int) $data['urgency']));
            if ($byLevel !== null) {
                return $byLevel;
            }
        }
        if ($ticket !== null) {
            return $this->masterData->findPriority((int) $ticket['priority_id']);
        }
        if (!empty($data['category_id']) || !empty($data['subcategory_id'])) {
            foreach ([$data['subcategory_id'] ?? null, $data['category_id'] ?? null] as $categoryId) {
                $category = !empty($categoryId) ? $this->categories->find((int) $categoryId) : null;
                if ($category !== null && !empty($category['default_priority_id'])) {
                    return $this->masterData->findPriority((int) $category['default_priority_id']);
                }
            }
        }

        return $this->masterData->defaultPriority();
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validate(array $input, ?array $existing, bool $portal): array
    {
        $v = (new Validator($input))
            ->string('subject', 'Betreff', true, 255, 3)
            ->text('description', 'Beschreibung', true, 20000)
            ->id('ticket_type_id', 'Tickettyp', !$portal)
            ->id('category_id', 'Kategorie')
            ->id('subcategory_id', 'Unterkategorie')
            ->int('impact', 'Auswirkung', false, 1, 3)
            ->int('urgency', 'Dringlichkeit', false, 1, 3)
            ->id('affected_employee_id', 'Betroffener Mitarbeiter');
        if (!$portal) {
            $v->id('priority_id', 'Priorität')
                ->id('requester_employee_id', 'Melder')
                ->id('assignee_user_id', 'Bearbeiter')
                ->id('deputy_user_id', 'Vertretung')
                ->id('group_id', 'Gruppe')
                ->id('sla_id', 'SLA')
                ->id('location_id', 'Standort')
                ->id('cost_center_id', 'Kostenstelle')
                ->string('external_reference', 'Externe Referenz', false, 100);
        }
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data = $v->validated();

        if ($portal) {
            // Portal: gewählter Typ nur, wenn gültig – sonst Standard „incident“ bzw. „general“
            $chosen = !empty($input['ticket_type_id']) ? $this->masterData->findType((int) $input['ticket_type_id']) : null;
            $type = $chosen ?? $this->masterData->typeByCode('incident') ?? $this->masterData->typeByCode('general');
            $data['ticket_type_id'] = $type !== null ? (int) $type['id'] : null;
            $data['priority_id'] = null;
        }
        if (empty($data['ticket_type_id'])) {
            $types = $this->masterData->types();
            $data['ticket_type_id'] = $types !== [] ? (int) $types[0]['id'] : null;
        }
        if (empty($data['ticket_type_id']) || $this->masterData->findType((int) $data['ticket_type_id']) === null) {
            throw ValidationException::single('ticket_type_id', 'Bitte einen gültigen Tickettyp wählen.');
        }
        if (!empty($data['category_id'])) {
            $category = $this->categories->find((int) $data['category_id']);
            if ($category === null || empty($category['is_active'])) {
                throw ValidationException::single('category_id', 'Kategorie nicht gefunden.');
            }
            if (!empty($category['parent_id'])) {
                // Unterkategorie direkt gewählt → Haupt-/Unterkategorie ableiten
                $data['subcategory_id'] = (int) $category['id'];
                $data['category_id'] = (int) $category['parent_id'];
            }
        }
        if (!empty($data['subcategory_id'])) {
            $sub = $this->categories->find((int) $data['subcategory_id']);
            if ($sub === null || (int) ($sub['parent_id'] ?? 0) !== (int) ($data['category_id'] ?? 0)) {
                throw ValidationException::single('subcategory_id', 'Unterkategorie passt nicht zur Kategorie.');
            }
        }
        foreach (['requester_employee_id' => 'Melder', 'affected_employee_id' => 'Betroffener Mitarbeiter'] as $field => $label) {
            if (!empty($data[$field]) && $this->employees->find((int) $data[$field]) === null) {
                throw ValidationException::single($field, "{$label} nicht gefunden.");
            }
        }
        foreach (['assignee_user_id', 'deputy_user_id'] as $field) {
            if (!empty($data[$field])) {
                $this->validUser((int) $data[$field], $field);
            }
        }
        if (!empty($data['group_id']) && $this->masterData->findGroup((int) $data['group_id']) === null) {
            throw ValidationException::single('group_id', 'Gruppe nicht gefunden.');
        }
        if (!empty($data['sla_id']) && $this->slas->find((int) $data['sla_id']) === null) {
            throw ValidationException::single('sla_id', 'SLA nicht gefunden.');
        }
        if (!empty($data['priority_id']) && $this->masterData->findPriority((int) $data['priority_id']) === null) {
            throw ValidationException::single('priority_id', 'Priorität nicht gefunden.');
        }

        return $data;
    }

    private function validUser(?int $userId, string $field): ?int
    {
        if ($userId === null || $userId === 0) {
            return null;
        }
        $user = $this->users->find($userId);
        if ($user === null || empty($user['is_active'])) {
            throw ValidationException::single($field, 'Benutzer nicht gefunden oder inaktiv.');
        }

        return $userId;
    }

    /** Mitarbeiter-ID nur übernehmen, wenn der Datensatz existiert (Melder aus der automatischen Erkennung). */
    private function existingEmployeeId(mixed $value): ?int
    {
        $id = is_numeric($value) ? (int) $value : 0;

        return $id > 0 && $this->employees->find($id) !== null ? $id : null;
    }

    /** Benutzer-ID nur übernehmen, wenn das Konto existiert und aktiv ist. */
    private function activeUserId(mixed $value): ?int
    {
        $id = is_numeric($value) ? (int) $value : 0;
        $user = $id > 0 ? $this->users->find($id) : null;

        return $user !== null && !empty($user['is_active']) ? $id : null;
    }

    /**
     * Automatisch erkannte Melderdaten für die Ticketspalten aufbereiten.
     * @param array<string,mixed> $reporter
     * @return array<string,?string>
     */
    private function reporterFields(array $reporter): array
    {
        $trim = static function (mixed $value, int $length): ?string {
            $value = trim((string) $value);

            return $value === '' ? null : mb_substr($value, 0, $length);
        };
        $email = $trim($reporter['email'] ?? null, 255);

        return [
            'requester_email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null,
            'reporter_username' => $trim($reporter['username'] ?? null, 100),
            'reporter_host' => $trim($reporter['host'] ?? null, 255),
            'reporter_ip' => $trim($reporter['ip'] ?? null, 45),
            'reporter_phone' => $trim($reporter['phone'] ?? null, 50),
            'reporter_department' => $trim($reporter['department'] ?? null, 150),
        ];
    }

    private function userName(int $userId): string
    {
        $user = $this->users->find($userId);

        return (string) ($user['display_name'] ?? $user['username'] ?? $userId);
    }

    /** @param array<string,mixed> $ticket */
    private function assertNotMerged(array $ticket): void
    {
        if (!empty($ticket['merged_into_ticket_id'])) {
            throw ValidationException::single('ticket', 'Dieses Ticket wurde in ' . $ticket['merged_into_number'] . ' zusammengeführt und ist schreibgeschützt.');
        }
    }

    /** @param array<string,mixed>|null $payload */
    private function event(int $ticketId, string $type, ?string $field, ?string $old, ?string $new, ?array $payload = null): void
    {
        $this->tickets->addEvent($ticketId, $type, $this->currentUser->id(), $this->currentUser->displayName(), $field, $old, $new, $payload);
    }

    /** Verlaufseinträge für geänderte Felder. @param array<string,mixed> $before @param array<string,mixed> $update */
    private function diffEvents(array $before, array $update): void
    {
        $labels = ['subject' => 'Betreff', 'description' => 'Beschreibung', 'ticket_type_id' => 'Typ', 'category_id' => 'Kategorie', 'subcategory_id' => 'Unterkategorie', 'priority_id' => 'Priorität', 'impact' => 'Auswirkung', 'urgency' => 'Dringlichkeit', 'requester_employee_id' => 'Melder', 'affected_employee_id' => 'Betroffener', 'location_id' => 'Standort', 'cost_center_id' => 'Kostenstelle', 'external_reference' => 'Externe Referenz', 'sla_id' => 'SLA'];
        foreach ($labels as $field => $label) {
            if (!array_key_exists($field, $update) || (string) ($before[$field] ?? '') === (string) ($update[$field] ?? '')) {
                continue;
            }
            $old = (string) ($before[$field] ?? '');
            $new = (string) ($update[$field] ?? '');
            if ($field === 'description') {
                $old = mb_substr($old, 0, 80);
                $new = mb_substr($new, 0, 80);
            }
            $this->event((int) $before['id'], 'field_changed', $label, $old !== '' ? $old : null, $new !== '' ? $new : null);
        }
    }

    /** @param array<string,mixed> $ticket @return array<string,mixed> */
    private function auditSnapshot(array $ticket): array
    {
        return [
            'subject' => $ticket['subject'],
            'type' => $ticket['type_name'] ?? null,
            'status' => $ticket['status_name'] ?? null,
            'priority' => $ticket['priority_name'] ?? null,
            'category' => $ticket['category_name'] ?? null,
            'subcategory' => $ticket['subcategory_name'] ?? null,
            'requester' => $ticket['requester_name'] ?? $ticket['requester_user_name'] ?? null,
            'assignee' => $ticket['assignee_name'] ?? null,
            'group' => $ticket['group_name'] ?? null,
            'sla' => $ticket['sla_name'] ?? null,
            'external_reference' => $ticket['external_reference'] ?? null,
        ];
    }

    /** @return array<int,string> */
    public function parseTags(string $raw): array
    {
        $names = [];
        foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $name) {
            $name = trim($name);
            if ($name !== '' && mb_strlen($name) <= 60) {
                $names[mb_strtolower($name)] = $name;
            }
        }

        return array_values($names);
    }

    /** @return array<int,int> */
    private function parseIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,\s]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $raw), static fn (int $i): bool => $i > 0)));
    }
}
