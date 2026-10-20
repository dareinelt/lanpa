<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\DocumentRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\HandoverRepository;
use App\Security\CurrentUser;

/**
 * Übergabeprotokolle: je Mitarbeiter versioniert (draft → signed → superseded / cancelled).
 * Das zuletzt unterschriebene Protokoll ist das gültige; ältere bleiben mit PDF archiviert.
 */
final class HandoverService
{
    public const STATUS_LABELS = [
        'draft' => 'Entwurf',
        'signed' => 'Gültig',
        'superseded' => 'Abgelöst',
        'cancelled' => 'Storniert',
    ];

    /** Felder des Mitarbeiters, die im Protokoll eingefroren werden. */
    private const EMPLOYEE_FIELDS = [
        'id', 'username', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'personnel_number',
        'department', 'position', 'location_id', 'location_path', 'cost_center_id', 'cost_center_number', 'cost_center_name',
    ];

    public function __construct(
        private readonly HandoverRepository $protocols,
        private readonly EmployeeRepository $employees,
        private readonly DocumentService $documents,
        private readonly DocumentRepository $documentRepository,
        private readonly PdfClient $pdf,
        private readonly SettingsService $settings,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser,
        private readonly Logger $logger,
        private readonly MailClient $mail,
    ) {
    }

    // ------------------------------------------------------------------ Status

    /**
     * Stand eines Mitarbeiters: relevante Assets, gültiges Protokoll, offener Entwurf und ob das gültige
     * Protokoll noch zum aktuellen Bestand passt.
     *
     * @return array{employee:array<string,mixed>|null, items:array<int,array<string,mixed>>, fingerprint:string, current:array<string,mixed>|null, draft:array<string,mixed>|null, versions:array<int,array<string,mixed>>, state:string, state_label:string}
     */
    public function statusFor(int $employeeId): array
    {
        $employee = $this->employees->find($employeeId);
        $items = $this->protocols->relevantAssetsForEmployee($employeeId);
        $fingerprint = self::fingerprint($items);
        $current = $this->protocols->currentSigned($employeeId);
        $draft = $this->protocols->openDraft($employeeId);
        $state = self::state($items !== [], $current, $draft, $fingerprint);

        return [
            'employee' => $employee,
            'items' => $items,
            'fingerprint' => $fingerprint,
            'current' => $current,
            'draft' => $draft,
            'versions' => $this->protocols->forEmployee($employeeId),
            'state' => $state,
            'state_label' => self::stateLabel($state),
        ];
    }

    /**
     * Zustand aus Übersichtsdaten (ohne Assets zu laden): none | ok | outdated | draft | missing.
     * outdated = gültiges Protokoll vorhanden, aber Bestand hat sich geändert.
     */
    public static function state(bool $hasItems, ?array $current, ?array $draft, string $fingerprint): string
    {
        if ($draft !== null) {
            return 'draft';
        }
        if ($current === null) {
            return $hasItems ? 'missing' : 'none';
        }

        return ($current['asset_fingerprint'] ?? '') === $fingerprint ? 'ok' : 'outdated';
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'ok' => 'Aktuell',
            'outdated' => 'Veraltet – Bestand geändert',
            'draft' => 'Entwurf offen',
            'missing' => 'Kein Protokoll',
            default => 'Keine relevanten Arbeitsmittel',
        };
    }

    /** SHA-256 über die sortierten Asset-IDs; leer → Hash der leeren Liste. @param array<int,array<string,mixed>> $items */
    public static function fingerprint(array $items): string
    {
        $ids = array_map(static fn (array $i): int => (int) $i['id'], $items);
        sort($ids);

        return hash('sha256', implode(',', $ids));
    }

    // ------------------------------------------------------------------ Entwurf

    /** Legt einen neuen Entwurf (nächste Version) an und friert Mitarbeiter, Assets und Vorlage ein. */
    public function createDraft(int $employeeId, ?string $note = null): int
    {
        $employee = $this->employees->find($employeeId);
        if ($employee === null) {
            throw ValidationException::single('employee_id', 'Der Mitarbeiter existiert nicht.');
        }
        if ($this->protocols->openDraft($employeeId) !== null) {
            throw new ConflictException('Für diesen Mitarbeiter existiert bereits ein offener Entwurf. Bitte zuerst unterschreiben oder stornieren.');
        }
        $template = $this->protocols->defaultTemplate();
        if ($template === null || $template['blocks'] === []) {
            throw new ConflictException('Es ist keine Protokollvorlage hinterlegt. Bitte zuerst unter Administration → Übergabeprotokoll-Vorlage anlegen.');
        }
        $items = $this->protocols->relevantAssetsForEmployee($employeeId);
        $version = $this->protocols->nextVersion($employeeId);
        $snapshot = array_intersect_key($employee, array_flip(self::EMPLOYEE_FIELDS));

        $id = $this->protocols->create([
            'protocol_number' => $this->protocolNumber($employee, $version),
            'employee_id' => $employeeId,
            'version' => $version,
            'status' => 'draft',
            'template_id' => (int) $template['id'],
            'template_snapshot' => $template['blocks'],
            'employee_snapshot' => $snapshot,
            'items' => $items,
            'item_count' => count($items),
            'asset_fingerprint' => self::fingerprint($items),
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null,
            'issuer_user_id' => $this->currentUser->id(),
            'issuer_name' => $this->currentUser->displayName(),
            'created_by' => $this->currentUser->id(),
            'created_by_name' => $this->currentUser->displayName(),
        ]);
        $this->audit->log('create', 'handover', $id, 'Übergabeprotokoll v' . $version . ' für ' . $employee['display_name'], null, ['version' => $version, 'item_count' => count($items)]);

        return $id;
    }

    /** Aktualisiert einen Entwurf auf den aktuellen Bestand und die aktuelle Vorlage. */
    public function refreshDraft(int $id): void
    {
        $protocol = $this->requireStatus($id, 'draft');
        $items = $this->protocols->relevantAssetsForEmployee((int) $protocol['employee_id']);
        $employee = $this->employees->find((int) $protocol['employee_id']) ?? [];
        $template = $this->protocols->defaultTemplate();
        $data = [
            'items' => $items,
            'item_count' => count($items),
            'asset_fingerprint' => self::fingerprint($items),
            'employee_snapshot' => array_intersect_key($employee, array_flip(self::EMPLOYEE_FIELDS)) ?: $protocol['employee_snapshot'],
        ];
        if ($template !== null && $template['blocks'] !== []) {
            $data['template_id'] = (int) $template['id'];
            $data['template_snapshot'] = $template['blocks'];
        }
        $this->protocols->update($id, $data);
    }

    // ------------------------------------------------------------------ Unterschrift

    /**
     * Unterschreibt einen Entwurf: Signatur speichern, HTML einfrieren, ältere Versionen ablösen, PDF erzeugen.
     *
     * @param list<int> $confirmations Indizes der bestätigten Checkboxen
     */
    public function sign(int $id, string $signatureDataUrl, array $confirmations, string $device, string $ip, bool $notifyEmail = false): array
    {
        $protocol = $this->requireStatus($id, 'draft');
        $png = self::decodeSignature($signatureDataUrl);

        $required = HandoverRenderer::requiredConfirmations($protocol['template_snapshot']);
        $missing = array_diff($required, array_map('intval', $confirmations));
        if ($missing !== []) {
            throw ValidationException::single('confirmations', 'Bitte alle Pflichtbestätigungen ankreuzen.');
        }

        $signedAt = date('Y-m-d H:i:s');
        $signatureDocId = $this->documents->storeGenerated('handover', $id, 'signature', $png, $protocol['protocol_number'] . '-unterschrift.png', 'Unterschrift Mitarbeiter');

        $protocol['status'] = 'signed';
        $protocol['signed_at'] = $signedAt;
        $html = HandoverRenderer::render($protocol['template_snapshot'], $this->context($protocol, 'data:image/png;base64,' . base64_encode($png)));

        $this->protocols->update($id, [
            'status' => 'signed',
            'signed_at' => $signedAt,
            'signed_device' => mb_substr($device, 0, 255),
            'signed_ip' => mb_substr($ip, 0, 45),
            'signature_document_id' => $signatureDocId,
            'rendered_html' => $html,
            'notify_email' => $notifyEmail ? 1 : 0,
        ]);
        $superseded = $this->protocols->supersedeOthers((int) $protocol['employee_id'], $id);
        $this->audit->log('sign', 'handover', $id, 'Übergabeprotokoll ' . $protocol['protocol_number'], null, [
            'version' => (int) $protocol['version'],
            'superseded' => $superseded,
            'device' => mb_substr($device, 0, 255),
        ]);

        $pdfCreated = $this->generatePdf($id) !== null;
        if ($notifyEmail) {
            $this->maybeSendEmail((int) $id);
        }

        return ['id' => $id, 'pdf' => $pdfCreated];
    }

    /**
     * Sendet – falls beim Unterschreiben per Opt-in-Checkbox angefordert – das signierte
     * Übergabeprotokoll als PDF per E-Mail an den Mitarbeiter. Fehler werden nur geloggt.
     */
    private function maybeSendEmail(int $id): void
    {
        $protocol = $this->protocols->find($id);
        if ($protocol === null || empty($protocol['pdf_document_id'])) {
            return;
        }
        $email = trim((string) (($protocol['employee_snapshot'] ?? [])['email'] ?? ''));
        if ($email === '' || !$this->mail->enabled()) {
            return;
        }
        try {
            $document = $this->documentRepository->find((int) $protocol['pdf_document_id']);
            $path = $document !== null ? $this->documents->path($document) : null;
            $pdf = $path !== null && is_file($path) ? (string) file_get_contents($path) : null;
            if ($pdf === null) {
                return;
            }
            $subject = 'Übergabeprotokoll ' . $protocol['protocol_number'];
            $employeeName = (string) (($protocol['employee_snapshot'] ?? [])['display_name'] ?? '');
            $body = '<p>Hallo ' . htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>im Anhang finden Sie Ihr unterschriebenes Übergabeprotokoll ' . htmlspecialchars((string) $protocol['protocol_number'], ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p>Diese E-Mail wurde automatisch von KLINQ erzeugt.</p>';
            $sent = $this->mail->send($email, $subject, $body, [
                ['filename' => $protocol['protocol_number'] . '.pdf', 'content' => $pdf, 'mime_type' => 'application/pdf'],
            ]);
            if ($sent) {
                $this->protocols->update($id, ['email_sent_at' => date('Y-m-d H:i:s'), 'email_sent_to' => $email]);
                $this->audit->log('email', 'handover', $id, $subject, null, ['to' => $email]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Übergabeprotokoll konnte nicht per E-Mail versendet werden', ['handover_id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /** Erzeugt (oder erneuert) das archivierte PDF; null wenn der Dienst nicht erreichbar ist. */
    public function generatePdf(int $id): ?int
    {
        $protocol = $this->protocols->find($id);
        if ($protocol === null || !in_array($protocol['status'], ['signed', 'superseded'], true)) {
            throw new ConflictException('Ein PDF wird nur für unterschriebene Protokolle erzeugt.');
        }
        $html = HandoverRenderer::renderDocument($protocol['template_snapshot'], $this->context($protocol, $this->signatureDataUrl($protocol)));
        $pdf = $this->pdf->render($html);
        if ($pdf === null) {
            $this->logger->warning('PDF für Übergabeprotokoll konnte nicht erzeugt werden', ['protocol_id' => $id]);

            return null;
        }
        $docId = $this->documents->storeGenerated('handover', $id, 'handover_protocol', $pdf, $protocol['protocol_number'] . '.pdf', 'Übergabeprotokoll Version ' . $protocol['version']);
        $this->protocols->update($id, ['pdf_document_id' => $docId]);
        $this->audit->log('pdf', 'handover', $id, 'PDF ' . $protocol['protocol_number'], null, ['document_id' => $docId]);

        return $docId;
    }

    /** Storniert einen Entwurf (nur Entwürfe – unterschriebene Versionen bleiben unveränderlich). */
    public function cancel(int $id, string $reason): void
    {
        $protocol = $this->requireStatus($id, 'draft');
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('cancel_reason', 'Bitte einen Grund angeben.');
        }
        $this->protocols->update($id, ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => mb_substr($reason, 0, 500)]);
        $this->audit->log('cancel', 'handover', $id, 'Übergabeprotokoll ' . $protocol['protocol_number'], null, ['reason' => $reason]);
    }

    // ------------------------------------------------------------------ Darstellung

    /** HTML eines Protokolls: eingefrorenes HTML bei unterschriebenen, sonst live gerendert. */
    public function html(array $protocol, bool $interactive = false): string
    {
        if (!$interactive && in_array($protocol['status'], ['signed', 'superseded'], true) && is_string($protocol['rendered_html'] ?? null) && $protocol['rendered_html'] !== '') {
            return $protocol['rendered_html'];
        }

        return HandoverRenderer::render($protocol['template_snapshot'], $this->context($protocol, $this->signatureDataUrl($protocol), $interactive));
    }

    /** Vorschau des Baukastens mit Beispieldaten. @param array<int,array<string,mixed>> $blocks */
    public function preview(array $blocks): string
    {
        $context = [
            'protocol' => ['protocol_number' => 'UP-' . date('Y') . '-00042', 'version' => 3, 'created_at' => date('Y-m-d H:i:s'), 'signed_at' => date('Y-m-d H:i:s'), 'issuer_name' => $this->currentUser->displayName(), 'status' => 'signed'],
            'employee' => [
                'display_name' => 'Erika Mustermann', 'first_name' => 'Erika', 'last_name' => 'Mustermann', 'personnel_number' => '10042',
                'username' => 'emustermann', 'email' => 'erika.mustermann@example.com', 'phone' => '+49 30 123456', 'department' => 'Vertrieb',
                'position' => 'Key-Account-Managerin', 'location_path' => 'Berlin / Haus A / 2.14', 'cost_center_number' => '4711', 'cost_center_name' => 'Vertrieb Ost',
            ],
            'items' => [
                ['id' => 1, 'inventory_number' => 'IT-000123', 'article_name' => 'ThinkPad T14 Gen 5', 'manufacturer_name' => 'Lenovo', 'asset_type_name' => 'Notebook', 'category_name' => 'IT', 'serial_number' => 'PF3ABC12', 'mac_address' => '3C:22:FB:10:20:30', 'imei' => '', 'assigned_at' => date('Y-m-d', strtotime('-40 days')), 'expected_return_at' => null, 'note' => ''],
                ['id' => 2, 'inventory_number' => 'IT-000456', 'article_name' => 'iPhone 15', 'manufacturer_name' => 'Apple', 'asset_type_name' => 'Smartphone', 'category_name' => 'IT', 'serial_number' => 'F2LXYZ98', 'mac_address' => '', 'imei' => '35 123456 789012 3', 'assigned_at' => date('Y-m-d', strtotime('-10 days')), 'expected_return_at' => null, 'note' => ''],
            ],
            'company' => $this->settings->companyName(),
            'signature' => null,
            'interactive' => false,
        ];

        return HandoverRenderer::render($blocks, $context);
    }

    // ------------------------------------------------------------------ Vorlage

    /** Speichert die Standardvorlage (Baukasten). @return array<int,array<string,mixed>> normalisierte Blöcke */
    public function saveTemplate(mixed $rawBlocks, string $name): array
    {
        try {
            $blocks = HandoverRenderer::normalizeBlocks($rawBlocks);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::single('blocks', $e->getMessage());
        }
        $hasSignature = false;
        foreach ($blocks as $b) {
            if ($b['type'] === 'signature' && $b['party'] === 'employee') {
                $hasSignature = true;
            }
        }
        if (!$hasSignature) {
            throw ValidationException::single('blocks', 'Die Vorlage benötigt ein Unterschriftsfeld für den Mitarbeiter.');
        }
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'Standardvorlage';
        $existing = $this->protocols->defaultTemplate();
        $data = ['name' => $name, 'blocks' => $blocks, 'is_default' => 1, 'updated_by' => $this->currentUser->id()];
        if ($existing === null) {
            $id = $this->protocols->createTemplate($data);
        } else {
            $id = (int) $existing['id'];
            $this->protocols->updateTemplate($id, $data);
        }
        $this->audit->log('update', 'handover_template', $id, $name, $existing !== null ? ['blocks' => $existing['blocks']] : null, ['blocks' => $blocks]);

        return $blocks;
    }

    // ------------------------------------------------------------------ intern

    private function context(array $protocol, ?string $signature, bool $interactive = false): array
    {
        return [
            'protocol' => $protocol,
            'employee' => (array) ($protocol['employee_snapshot'] ?? []),
            'items' => (array) ($protocol['items'] ?? []),
            'company' => $this->settings->companyName(),
            'signature' => $signature,
            'interactive' => $interactive,
        ];
    }

    private function signatureDataUrl(array $protocol): ?string
    {
        if (empty($protocol['signature_document_id']) || empty($protocol['signature_stored_name'])) {
            return null;
        }
        $path = $this->documents->path(['stored_name' => $protocol['signature_stored_name']]);
        if (!is_file($path)) {
            return null;
        }

        return 'data:' . ($protocol['signature_mime'] ?: 'image/png') . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    /** Prüft eine Signatur-Data-URL (PNG) und liefert die Binärdaten. */
    public static function decodeSignature(string $dataUrl): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', trim($dataUrl), $m)) {
            throw ValidationException::single('signature_data', 'Bitte unterschreiben Sie im Unterschriftsfeld.');
        }
        $png = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        if ($png === false || strlen($png) < 200 || !str_starts_with($png, "\x89PNG")) {
            throw ValidationException::single('signature_data', 'Die Unterschrift ist ungültig oder leer.');
        }
        if (strlen($png) > 2_000_000) {
            throw ValidationException::single('signature_data', 'Die Unterschrift ist zu groß.');
        }

        return $png;
    }

    private function requireStatus(int $id, string $status): array
    {
        $protocol = $this->protocols->find($id);
        if ($protocol === null) {
            throw new ConflictException('Das Protokoll existiert nicht.');
        }
        if ($protocol['status'] !== $status) {
            throw new ConflictException('Das Protokoll hat den Status „' . (self::STATUS_LABELS[$protocol['status']] ?? $protocol['status']) . '“ und kann nicht mehr geändert werden.');
        }

        return $protocol;
    }

    private function protocolNumber(array $employee, int $version): string
    {
        $base = trim((string) ($employee['personnel_number'] ?? ''));
        if ($base === '') {
            $base = 'E' . (int) $employee['id'];
        }

        return 'UP-' . preg_replace('/[^A-Za-z0-9]/', '', $base) . '-' . str_pad((string) $version, 2, '0', STR_PAD_LEFT);
    }
}
