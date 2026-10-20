<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\HandoverRepository;
use App\Security\CurrentUser;
use App\Services\DocumentService;
use App\Services\HandoverRenderer;
use App\Services\HandoverService;
use App\Services\MailClient;
use App\Services\PdfClient;

/** Übergabeprotokolle: Desktop-Übersicht, Mitarbeiterstand, Versionen, mobile Unterschrift, Vorlagen-Baukasten. */
final class HandoverController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly HandoverService $service,
        private readonly HandoverRepository $protocols,
        private readonly DocumentService $documents,
        private readonly PdfClient $pdf,
        private readonly MailClient $mail,
    ) {
        parent::__construct($view, $currentUser);
    }

    // ------------------------------------------------------------------ Desktop

    /** Übersicht: Mitarbeiter mit relevanten Assets und Stand des gültigen Protokolls. */
    public function index(Request $request): Response
    {
        $q = trim($request->queryString('q'));
        $stateFilter = $request->queryString('state');
        $rows = [];
        $counts = ['ok' => 0, 'outdated' => 0, 'draft' => 0, 'missing' => 0, 'none' => 0];
        foreach ($this->protocols->employeeOverview($q) as $row) {
            $items = $this->protocols->relevantAssetsForEmployee((int) $row['id']);
            $row['state'] = HandoverService::state(
                $items !== [],
                $row['current_id'] !== null ? ['asset_fingerprint' => $row['current_fingerprint']] : null,
                $row['draft_id'] !== null ? ['id' => $row['draft_id']] : null,
                HandoverService::fingerprint($items)
            );
            $row['state_label'] = HandoverService::stateLabel($row['state']);
            $counts[$row['state']]++;
            if ($stateFilter === '' || $stateFilter === $row['state']) {
                $rows[] = $row;
            }
        }
        $recent = $this->protocols->search(['status' => 'all'], 15);

        return $this->render('handover.index', [
            'title' => 'Übergabeprotokolle',
            'activeNav' => 'handover',
            'rows' => $rows,
            'counts' => $counts,
            'q' => $q,
            'state' => $stateFilter,
            'recent' => $recent,
            'statusLabels' => HandoverService::STATUS_LABELS,
            'pdfEnabled' => $this->pdf->enabled(),
        ]);
    }

    /** Stand eines Mitarbeiters: gültiges Protokoll, Versionen, Abweichungen. */
    public function employee(Request $request): Response
    {
        $employeeId = $request->paramInt('employee');
        $status = $this->service->statusFor($employeeId);
        $this->findOrFail($status['employee'], 'Mitarbeiter nicht gefunden');

        return $this->render('handover.employee', [
            'title' => 'Übergabeprotokoll – ' . $status['employee']['display_name'],
            'activeNav' => 'handover',
            'status' => $status,
            'statusLabels' => HandoverService::STATUS_LABELS,
            'diff' => $this->diff($status),
        ]);
    }

    public function createDraft(Request $request): Response
    {
        $employeeId = $request->paramInt('employee');
        try {
            $id = $this->service->createDraft($employeeId, $request->stringOrNull('note'));
        } catch (ValidationException | ConflictException $e) {
            $this->flash('error', $e instanceof ValidationException ? implode(' ', array_map(static fn ($m) => is_array($m) ? implode(' ', $m) : (string) $m, $e->errors())) : $e->getMessage());

            return $this->redirect('/handover/employee/' . $employeeId);
        }
        $this->flash('success', 'Entwurf angelegt. Das Protokoll kann jetzt auf dem Mobilgerät unterschrieben werden.');

        return $this->redirect('/handover/' . $id);
    }

    public function show(Request $request): Response
    {
        $protocol = $this->protocolOrFail($request);
        $status = $this->service->statusFor((int) $protocol['employee_id']);

        return $this->render('handover.show', [
            'title' => 'Übergabeprotokoll ' . $protocol['protocol_number'],
            'activeNav' => 'handover',
            'protocol' => $protocol,
            'html' => $this->service->html($protocol),
            'status' => $status,
            'statusLabels' => HandoverService::STATUS_LABELS,
            'isCurrent' => $status['current'] !== null && (int) $status['current']['id'] === (int) $protocol['id'],
            'pdfEnabled' => $this->pdf->enabled(),
            'signUrl' => $this->signUrl($protocol),
        ]);
    }

    public function refresh(Request $request): Response
    {
        $protocol = $this->protocolOrFail($request);
        try {
            $this->service->refreshDraft((int) $protocol['id']);
            $this->flash('success', 'Entwurf auf den aktuellen Bestand aktualisiert.');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/handover/' . (int) $protocol['id']);
    }

    public function cancel(Request $request): Response
    {
        $protocol = $this->protocolOrFail($request);
        try {
            $this->service->cancel((int) $protocol['id'], $request->string('cancel_reason'));
            $this->flash('success', 'Entwurf storniert.');
        } catch (ValidationException | ConflictException $e) {
            $this->flash('error', $e instanceof ValidationException ? 'Bitte einen Grund angeben.' : $e->getMessage());
        }

        return $this->redirect('/handover/' . (int) $protocol['id']);
    }

    public function regeneratePdf(Request $request): Response
    {
        $protocol = $this->protocolOrFail($request);
        try {
            $docId = $this->service->generatePdf((int) $protocol['id']);
            $this->flash($docId !== null ? 'success' : 'warning', $docId !== null ? 'PDF erzeugt.' : 'Der PDF-Dienst ist nicht erreichbar. Bitte später erneut versuchen.');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/handover/' . (int) $protocol['id']);
    }

    /** Druck-Stylesheet der Protokolle (statt Inline-CSS, wegen CSP). */
    public function stylesheet(Request $request): Response
    {
        return new Response(HandoverRenderer::printCss(), 200, ['Content-Type' => 'text/css; charset=UTF-8', 'Cache-Control' => 'private, max-age=3600']);
    }

    /** Archiviertes PDF ausliefern (Fallback: druckbares HTML, wenn kein PDF vorliegt). */
    public function pdf(Request $request): Response
    {
        $protocol = $this->protocolOrFail($request);
        if (!empty($protocol['pdf_stored_name'])) {
            $path = $this->documents->path(['stored_name' => $protocol['pdf_stored_name']]);
            if (is_file($path)) {
                return Response::file($path, 'application/pdf', $protocol['protocol_number'] . '.pdf', $request->queryString('download') !== '1');
            }
        }
        // Fallback ohne PDF: druckbares HTML (bei unterschriebenen Protokollen das eingefrorene HTML)
        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>' . htmlspecialchars($protocol['protocol_number']) . '</title><link rel="stylesheet" href="/handover/print.css"></head><body class="hp-print">' . $this->service->html($protocol) . '</body></html>';

        return Response::html($html);
    }

    // ------------------------------------------------------------------ Mobile Unterschrift

    /** Liste der offenen Entwürfe auf dem Mobilgerät. */
    public function mobileIndex(Request $request): Response
    {
        $drafts = $this->protocols->search(['status' => 'draft'], 100);

        return $this->render('mobile.handover_list', [
            'title' => 'Übergabeprotokolle',
            'activeNav' => 'open',
            'drafts' => $drafts,
        ]);
    }

    public function mobileSign(Request $request): Response
    {
        $protocol = $this->protocolOrFail($request);
        if ($protocol['status'] !== 'draft') {
            $this->flash('info', 'Dieses Protokoll ist bereits ' . mb_strtolower(HandoverService::STATUS_LABELS[$protocol['status']] ?? $protocol['status']) . '.');

            return $this->redirect('/m/handover');
        }
        // Entwurf spiegelt immer den aktuellen Bestand wider
        $this->service->refreshDraft((int) $protocol['id']);
        $protocol = $this->protocols->find((int) $protocol['id']) ?? $protocol;

        return $this->render('mobile.handover_sign', [
            'title' => 'Unterschrift ' . $protocol['protocol_number'],
            'activeNav' => 'open',
            'protocol' => $protocol,
            'html' => $this->service->html($protocol, true),
            'errors' => $_SESSION['_errors'] ?? [],
            'backHref' => '/m/handover',
            'mailEnabled' => $this->mail->enabled(),
        ]);
    }

    public function mobileSubmit(Request $request): Response
    {
        $protocol = $this->protocolOrFail($request);
        $confirmations = $request->all()['confirmations'] ?? [];
        $notifyEmail = !empty($request->all()['notify_email']);
        try {
            $result = $this->service->sign(
                (int) $protocol['id'],
                $request->string('signature_data'),
                is_array($confirmations) ? array_map('intval', $confirmations) : [],
                $request->userAgent(),
                $request->ip(),
                $notifyEmail
            );
        } catch (ValidationException $e) {
            $_SESSION['_errors'] = $e->errors();
            $this->flash('error', 'Die Unterschrift konnte nicht gespeichert werden.');

            return $this->redirect('/m/handover/' . (int) $protocol['id'] . '/sign');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/m/handover');
        }
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $result['id'], 'pdf' => $result['pdf'], 'redirect' => '/m/handover/' . $result['id'] . '/done']);
        }

        return $this->redirect('/m/handover/' . $result['id'] . '/done');
    }

    public function mobileDone(Request $request): Response
    {
        $protocol = $this->protocolOrFail($request);

        return $this->render('mobile.handover_done', [
            'title' => 'Unterschrieben',
            'activeNav' => 'open',
            'protocol' => $protocol,
            'backHref' => '/m/handover',
        ]);
    }

    // ------------------------------------------------------------------ Vorlage (Baukasten)

    public function template(Request $request): Response
    {
        $template = $this->protocols->defaultTemplate();
        $blocks = $template['blocks'] ?? [];
        $old = $_SESSION['_old_input'] ?? [];
        if (!empty($old['blocks'])) {
            $decoded = json_decode((string) $old['blocks'], true);
            if (is_array($decoded)) {
                $blocks = $decoded;
            }
        }

        return $this->render('admin.handover_template', [
            'title' => 'Vorlage Übergabeprotokoll',
            'activeNav' => 'admin',
            'template' => $template,
            'blocks' => $blocks,
            'blockTypes' => HandoverRenderer::BLOCK_TYPES,
            'employeeFields' => HandoverRenderer::EMPLOYEE_FIELDS,
            'assetColumns' => HandoverRenderer::ASSET_COLUMNS,
            'metaFields' => HandoverRenderer::META_FIELDS,
            'placeholders' => HandoverRenderer::PLACEHOLDERS,
            'preview' => $this->service->preview($blocks),
            'pdfHealthy' => $this->pdf->enabled() ? $this->pdf->healthy() : null,
        ]);
    }

    public function saveTemplate(Request $request): Response
    {
        try {
            $this->service->saveTemplate($request->string('blocks'), $request->string('name'));
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());
            $this->flash('error', implode(' ', array_map(static fn ($m) => is_array($m) ? implode(' ', $m) : (string) $m, $e->errors())));

            return $this->redirect('/admin/handover-template');
        }
        $this->flash('success', 'Vorlage gespeichert. Sie gilt für alle künftig angelegten Protokolle.');

        return $this->redirect('/admin/handover-template');
    }

    /** JSON-Vorschau der (ungespeicherten) Blöcke mit Beispieldaten. */
    public function previewTemplate(Request $request): Response
    {
        try {
            $blocks = HandoverRenderer::normalizeBlocks($request->string('blocks'));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['ok' => true, 'html' => $this->service->preview($blocks)]);
    }

    // ------------------------------------------------------------------ intern

    private function protocolOrFail(Request $request): array
    {
        $protocol = $this->protocols->find($request->paramInt('id'));
        if ($protocol === null) {
            throw new NotFoundException('Übergabeprotokoll nicht gefunden.');
        }

        return $protocol;
    }

    private function signUrl(array $protocol): ?string
    {
        return $protocol['status'] === 'draft' ? '/m/handover/' . (int) $protocol['id'] . '/sign' : null;
    }

    /** Abweichung zwischen gültigem Protokoll und aktuellem Bestand. @return array{added:array,removed:array} */
    private function diff(array $status): array
    {
        if ($status['current'] === null) {
            return ['added' => [], 'removed' => []];
        }
        $currentIds = array_map(static fn (array $i): int => (int) $i['id'], (array) $status['current']['items']);
        $nowIds = array_map(static fn (array $i): int => (int) $i['id'], $status['items']);

        return [
            'added' => array_values(array_filter($status['items'], static fn (array $i): bool => !in_array((int) $i['id'], $currentIds, true))),
            'removed' => array_values(array_filter((array) $status['current']['items'], static fn (array $i): bool => !in_array((int) $i['id'], $nowIds, true))),
        ];
    }
}
