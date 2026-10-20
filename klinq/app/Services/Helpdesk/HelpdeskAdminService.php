<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRuleRepository;
use App\Repositories\TicketSlaRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\TicketTemplateRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Support\Validator;

/**
 * Pflege der Help-Desk-Stammdaten: Typen, Status, Prioritäten, Gruppen, Kategorien, SLAs, Tags, Vorlagen, Regeln.
 * Stammdaten mit Verwendung werden nie gelöscht, sondern deaktiviert.
 */
final class HelpdeskAdminService
{
    public const COLORS = ['neutral' => 'Neutral', 'info' => 'Blau', 'success' => 'Grün', 'warning' => 'Gelb', 'danger' => 'Rot'];
    public const STATUS_CATEGORIES = ['new' => 'Neu', 'open' => 'Offen', 'pending' => 'Wartend (SLA pausiert)', 'resolved' => 'Gelöst', 'closed' => 'Geschlossen', 'cancelled' => 'Storniert'];
    public const SIMPLE_TABLES = ['types' => 'ticket_types', 'statuses' => 'ticket_statuses', 'priorities' => 'ticket_priorities', 'groups' => 'ticket_groups'];

    public function __construct(
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketCategoryRepository $categories,
        private readonly TicketSlaRepository $slas,
        private readonly TicketTagRepository $tags,
        private readonly TicketTemplateRepository $templates,
        private readonly TicketRuleRepository $rules,
        private readonly TicketRuleEvaluator $evaluator,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    // ------------------------------------------------------------------ Typen / Status / Prioritäten / Gruppen

    /** @param array<string,mixed> $input */
    public function saveSimple(string $kind, ?int $id, array $input): int
    {
        $this->currentUser->require('helpdesk.admin');
        $table = self::SIMPLE_TABLES[$kind] ?? throw new NotFoundException('Unbekannte Stammdaten.');
        $existing = $id !== null ? $this->masterData->findIn($table, $id) : null;
        if ($id !== null && $existing === null) {
            throw new NotFoundException('Datensatz nicht gefunden.');
        }

        $v = (new Validator($input))
            ->string('name', 'Bezeichnung', true, 100)
            ->string('description', 'Beschreibung', false, 255)
            ->int('sort_order', 'Sortierung', false, 0, 9999)
            ->bool('is_active');
        if ($kind !== 'groups') {
            $v->in('color', 'Farbe', array_keys(self::COLORS), true);
        }
        match ($kind) {
            'types' => $v->pattern('code', 'Code', '/^[a-z][a-z0-9_]{1,29}$/', 'Code: Kleinbuchstaben, Ziffern, Unterstrich (2–30 Zeichen).', true)->string('icon', 'Symbol', false, 40),
            'statuses' => $v->pattern('code', 'Code', '/^[a-z][a-z0-9_]{1,29}$/', 'Code: Kleinbuchstaben, Ziffern, Unterstrich.', true)->in('category', 'Kategorie', array_keys(self::STATUS_CATEGORIES), true)->bool('pauses_sla'),
            'priorities' => $v->pattern('code', 'Code', '/^[a-z][a-z0-9_]{1,29}$/', 'Code: Kleinbuchstaben, Ziffern, Unterstrich.', true)->int('level', 'Stufe', true, 1, 9)->bool('is_default'),
            default => $v->email('email', 'Gruppen-E-Mail'),
        };
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data = $v->validated();
        $data['sort_order'] ??= 0;

        // Eindeutigkeit von Code/Name/Stufe
        foreach ($this->masterData->allIn($table) as $row) {
            if ($id !== null && (int) $row['id'] === $id) {
                continue;
            }
            if (isset($data['code']) && $row['code'] === $data['code']) {
                throw ValidationException::single('code', 'Dieser Code ist bereits vergeben.');
            }
            if ($kind === 'groups' && mb_strtolower((string) $row['name']) === mb_strtolower((string) $data['name'])) {
                throw ValidationException::single('name', 'Diese Bezeichnung ist bereits vergeben.');
            }
            if ($kind === 'priorities' && (int) $row['level'] === (int) $data['level']) {
                throw ValidationException::single('level', 'Diese Stufe ist bereits vergeben.');
            }
        }
        // Systemstatus: Code nicht veränderbar (Workflow referenziert Codes)
        if ($kind === 'statuses' && $existing !== null && $existing['code'] !== $data['code']) {
            throw ValidationException::single('code', 'Der Code eines bestehenden Status kann nicht geändert werden.');
        }
        if ($kind === 'groups') {
            unset($data['color']);
        }

        if ($id === null) {
            $id = $this->masterData->createIn($table, $data);
            $this->audit->log('create', $table, $id, (string) $data['name'], null, $data);
        } else {
            $this->masterData->updateIn($table, $id, $data);
            $this->audit->log('update', $table, $id, (string) $data['name'], $existing, $data);
        }
        if ($kind === 'priorities' && !empty($data['is_default'])) {
            foreach ($this->masterData->allIn($table) as $row) {
                if ((int) $row['id'] !== $id && !empty($row['is_default'])) {
                    $this->masterData->updateIn($table, (int) $row['id'], ['is_default' => 0]);
                }
            }
        }
        if ($kind === 'groups' && array_key_exists('members', $input)) {
            $members = array_map('intval', (array) $input['members']);
            $leads = array_map('intval', (array) ($input['leads'] ?? []));
            $this->masterData->setGroupMembers($id, $members, $leads);
        }

        return $id;
    }

    public function deactivateSimple(string $kind, int $id): void
    {
        $this->currentUser->require('helpdesk.admin');
        $table = self::SIMPLE_TABLES[$kind] ?? throw new NotFoundException('Unbekannte Stammdaten.');
        $row = $this->masterData->findIn($table, $id) ?? throw new NotFoundException('Datensatz nicht gefunden.');
        $this->masterData->updateIn($table, $id, ['is_active' => empty($row['is_active']) ? 1 : 0]);
        $this->audit->log('update', $table, $id, (string) $row['name'], ['is_active' => $row['is_active']], ['is_active' => empty($row['is_active']) ? 1 : 0]);
    }

    // ------------------------------------------------------------------ Kategorien

    /** @param array<string,mixed> $input */
    public function saveCategory(?int $id, array $input): int
    {
        $this->currentUser->require('helpdesk.categories');
        $existing = $id !== null ? ($this->categories->find($id) ?? throw new NotFoundException('Kategorie nicht gefunden.')) : null;
        $v = (new Validator($input))
            ->string('name', 'Bezeichnung', true, 120)
            ->string('description', 'Beschreibung', false, 255)
            ->id('parent_id', 'Übergeordnete Kategorie')
            ->id('default_group_id', 'Standardgruppe')
            ->id('default_priority_id', 'Standardpriorität')
            ->int('sort_order', 'Sortierung', false, 0, 9999)
            ->bool('is_active');
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data = $v->validated();
        $data['sort_order'] ??= 0;
        if (!empty($data['parent_id'])) {
            $parent = $this->categories->find((int) $data['parent_id']);
            if ($parent === null || !empty($parent['parent_id'])) {
                throw ValidationException::single('parent_id', 'Nur Hauptkategorien können übergeordnet sein (zwei Ebenen).');
            }
            if ($id !== null && (int) $data['parent_id'] === $id) {
                throw ValidationException::single('parent_id', 'Eine Kategorie kann nicht sich selbst untergeordnet sein.');
            }
        }
        if ($existing !== null && empty($existing['parent_id']) && !empty($data['parent_id']) && $this->categories->children($id, false) !== []) {
            throw ValidationException::single('parent_id', 'Eine Hauptkategorie mit Unterkategorien kann nicht verschoben werden.');
        }
        $duplicate = $this->categories->findByName((string) $data['name'], $data['parent_id'] ?? null);
        if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
            throw ValidationException::single('name', 'Diese Bezeichnung existiert auf dieser Ebene bereits.');
        }
        if (!empty($data['default_group_id']) && $this->masterData->findGroup((int) $data['default_group_id']) === null) {
            throw ValidationException::single('default_group_id', 'Gruppe nicht gefunden.');
        }
        if (!empty($data['default_priority_id']) && $this->masterData->findPriority((int) $data['default_priority_id']) === null) {
            throw ValidationException::single('default_priority_id', 'Priorität nicht gefunden.');
        }
        if ($id === null) {
            $id = $this->categories->create($data);
            $this->audit->log('create', 'ticket_category', $id, (string) $data['name'], null, $data);
        } else {
            $this->categories->update($id, $data);
            $this->audit->log('update', 'ticket_category', $id, (string) $data['name'], $existing, $data);
        }

        return $id;
    }

    // ------------------------------------------------------------------ SLAs

    /** @param array<string,mixed> $input */
    public function saveSla(?int $id, array $input): int
    {
        $this->currentUser->require('helpdesk.sla');
        $existing = $id !== null ? ($this->slas->find($id) ?? throw new NotFoundException('SLA nicht gefunden.')) : null;
        if (isset($input['business_days']) && is_array($input['business_days'])) {
            // Checkbox-Gruppe im Formular → kommagetrennte Liste
            $input['business_days'] = implode(',', array_map('strval', $input['business_days']));
        }
        $v = (new Validator($input))
            ->string('name', 'Bezeichnung', true, 120)
            ->string('description', 'Beschreibung', false, 255)
            ->id('priority_id', 'Priorität')
            ->id('category_id', 'Kategorie')
            ->int('response_minutes', 'Reaktionszeit (Minuten)', true, 1, 525600)
            ->int('resolution_minutes', 'Lösungszeit (Minuten)', true, 1, 525600)
            ->bool('business_hours_only')
            ->pattern('business_days', 'Geschäftstage', '/^[1-7](,[1-7]){0,6}$/', 'Geschäftstage als Liste 1–7 (Mo=1), z. B. 1,2,3,4,5.')
            ->pattern('business_start', 'Beginn', '/^([01]\d|2[0-3]):[0-5]\d$/', 'Uhrzeit im Format HH:MM.')
            ->pattern('business_end', 'Ende', '/^([01]\d|2[0-3]):[0-5]\d$/', 'Uhrzeit im Format HH:MM.')
            ->int('warning_percent', 'Warnschwelle (%)', false, 1, 99)
            ->int('escalation_percent', 'Eskalationsschwelle (%)', false, 1, 100)
            ->id('escalation_group_id', 'Eskalationsgruppe')
            ->bool('is_default')
            ->bool('is_active')
            ->int('sort_order', 'Sortierung', false, 0, 9999);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data = $v->validated();
        if ((int) $data['response_minutes'] > (int) $data['resolution_minutes']) {
            throw ValidationException::single('response_minutes', 'Die Reaktionszeit darf nicht länger als die Lösungszeit sein.');
        }
        $data['business_days'] ??= '1,2,3,4,5';
        $data['business_start'] = ($data['business_start'] ?? '08:00') . ':00';
        $data['business_end'] = ($data['business_end'] ?? '17:00') . ':00';
        if ($data['business_start'] >= $data['business_end']) {
            throw ValidationException::single('business_end', 'Das Ende der Geschäftszeit muss nach dem Beginn liegen.');
        }
        $data['sort_order'] ??= 0;
        $duplicate = $this->slas->findByName((string) $data['name']);
        if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
            throw ValidationException::single('name', 'Diese Bezeichnung ist bereits vergeben.');
        }
        if (!empty($data['priority_id']) && $this->masterData->findPriority((int) $data['priority_id']) === null) {
            throw ValidationException::single('priority_id', 'Priorität nicht gefunden.');
        }
        if (!empty($data['category_id']) && $this->categories->find((int) $data['category_id']) === null) {
            throw ValidationException::single('category_id', 'Kategorie nicht gefunden.');
        }
        if (!empty($data['escalation_group_id']) && $this->masterData->findGroup((int) $data['escalation_group_id']) === null) {
            throw ValidationException::single('escalation_group_id', 'Gruppe nicht gefunden.');
        }

        $id = $this->slas->transaction(function () use ($id, $data, $existing): int {
            if (!empty($data['is_default'])) {
                $this->slas->clearDefault();
            }
            if ($id === null) {
                $id = $this->slas->create($data);
                $this->audit->log('create', 'ticket_sla', $id, (string) $data['name'], null, $data);
            } else {
                $this->slas->update($id, $data);
                $this->audit->log('update', 'ticket_sla', $id, (string) $data['name'], $existing, $data);
            }

            return $id;
        });

        return $id;
    }

    // ------------------------------------------------------------------ Tags

    /** @param array<string,mixed> $input */
    public function saveTag(?int $id, array $input): int
    {
        $this->currentUser->require('helpdesk.admin');
        $v = (new Validator($input))->string('name', 'Bezeichnung', true, 60)->in('color', 'Farbe', array_keys(self::COLORS), true);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data = $v->validated();
        $duplicate = $this->tags->findByName((string) $data['name']);
        if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
            throw ValidationException::single('name', 'Dieses Tag existiert bereits.');
        }
        if ($id === null) {
            $id = $this->tags->ensure((string) $data['name'], (string) $data['color']);
            $this->tags->update($id, $data);
            $this->audit->log('create', 'ticket_tag', $id, (string) $data['name'], null, $data);
        } else {
            $existing = $this->tags->find($id) ?? throw new NotFoundException('Tag nicht gefunden.');
            $this->tags->update($id, $data);
            $this->audit->log('update', 'ticket_tag', $id, (string) $data['name'], $existing, $data);
        }

        return $id;
    }

    public function deleteTag(int $id): void
    {
        $this->currentUser->require('helpdesk.admin');
        $tag = $this->tags->find($id) ?? throw new NotFoundException('Tag nicht gefunden.');
        $this->tags->delete($id);
        $this->audit->log('delete', 'ticket_tag', $id, (string) $tag['name'], $tag, null);
    }

    // ------------------------------------------------------------------ Vorlagen

    /** @param array<string,mixed> $input */
    public function saveTemplate(?int $id, array $input): int
    {
        $this->currentUser->require('helpdesk.templates');
        $existing = $id !== null ? ($this->templates->find($id) ?? throw new NotFoundException('Vorlage nicht gefunden.')) : null;
        $v = (new Validator($input))
            ->string('name', 'Bezeichnung', true, 120)
            ->string('description', 'Beschreibung', false, 255)
            ->string('subject', 'Betreff', false, 255)
            ->text('body', 'Beschreibungstext', false, 20000)
            ->id('ticket_type_id', 'Tickettyp')
            ->id('category_id', 'Kategorie')
            ->id('subcategory_id', 'Unterkategorie')
            ->id('priority_id', 'Priorität')
            ->id('group_id', 'Gruppe')
            ->id('assignee_user_id', 'Bearbeiter')
            ->id('sla_id', 'SLA')
            ->string('tags', 'Tags', false, 500)
            ->bool('is_portal_visible')
            ->bool('is_active')
            ->int('sort_order', 'Sortierung', false, 0, 9999);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data = $v->validated();
        $data['sort_order'] ??= 0;
        $duplicate = $this->templates->findByName((string) $data['name']);
        if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
            throw ValidationException::single('name', 'Diese Bezeichnung ist bereits vergeben.');
        }
        if (!empty($data['category_id'])) {
            $category = $this->categories->find((int) $data['category_id']) ?? throw ValidationException::single('category_id', 'Kategorie nicht gefunden.');
            if (!empty($category['parent_id'])) {
                $data['subcategory_id'] = (int) $category['id'];
                $data['category_id'] = (int) $category['parent_id'];
            }
        }
        if ($id === null) {
            $data['created_by'] = $this->currentUser->id();
            $id = $this->templates->create($data);
            $this->audit->log('create', 'ticket_template', $id, (string) $data['name'], null, $data);
        } else {
            $this->templates->update($id, $data);
            $this->audit->log('update', 'ticket_template', $id, (string) $data['name'], $existing, $data);
        }

        return $id;
    }

    // ------------------------------------------------------------------ Regeln

    /** @param array<string,mixed> $input */
    public function saveRule(?int $id, array $input): int
    {
        $this->currentUser->require('helpdesk.admin');
        $existing = $id !== null ? ($this->rules->find($id) ?? throw new NotFoundException('Regel nicht gefunden.')) : null;
        $v = (new Validator($input))
            ->string('name', 'Bezeichnung', true, 120)
            ->string('description', 'Beschreibung', false, 255)
            ->in('trigger_event', 'Auslöser', TicketRuleRepository::TRIGGERS, true)
            ->text('conditions', 'Bedingungen (JSON)', true, 10000)
            ->text('actions', 'Aktionen (JSON)', true, 10000)
            ->bool('stop_processing')
            ->bool('is_active')
            ->int('sort_order', 'Sortierung', false, 0, 9999);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $data = $v->validated();
        $conditions = json_decode((string) $data['conditions'], true);
        $actions = json_decode((string) $data['actions'], true);
        if (!is_array($conditions)) {
            throw ValidationException::single('conditions', 'Bedingungen sind kein gültiges JSON-Objekt.');
        }
        if (!is_array($actions)) {
            throw ValidationException::single('actions', 'Aktionen sind kein gültiges JSON-Objekt.');
        }
        $errors = $this->evaluator->validateDefinition($conditions, $actions);
        if ($errors !== []) {
            throw ValidationException::single('actions', implode(' ', $errors));
        }
        $data['conditions'] = json_encode($conditions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $data['actions'] = json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $data['sort_order'] ??= 0;
        $duplicate = $this->rules->findByName((string) $data['name']);
        if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
            throw ValidationException::single('name', 'Diese Bezeichnung ist bereits vergeben.');
        }
        if ($id === null) {
            $id = $this->rules->create($data);
            $this->audit->log('create', 'ticket_rule', $id, (string) $data['name'], null, ['trigger' => $data['trigger_event']]);
        } else {
            $this->rules->update($id, $data);
            $this->audit->log('update', 'ticket_rule', $id, (string) $data['name'], ['trigger' => $existing['trigger_event'], 'is_active' => $existing['is_active']], ['trigger' => $data['trigger_event'], 'is_active' => $data['is_active']]);
        }

        return $id;
    }

    public function deleteRule(int $id): void
    {
        $this->currentUser->require('helpdesk.admin');
        $rule = $this->rules->find($id) ?? throw new NotFoundException('Regel nicht gefunden.');
        $this->rules->delete($id);
        $this->audit->log('delete', 'ticket_rule', $id, (string) $rule['name'], ['trigger' => $rule['trigger_event']], null);
    }
}
