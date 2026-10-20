<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\CurrentUser;
use App\Services\Helpdesk\TicketReportService;
use App\Support\CsvWriter;

/** Help-Desk-Berichte (Zeitraum-Auswertungen, SLA, Arbeitszeit, wiederkehrende Probleme) + CSV-Export. */
final class HelpdeskReportController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TicketReportService $reports
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        [$from, $to] = TicketReportService::range($request->queryString('from') ?: null, $request->queryString('to') ?: null);

        return $this->render('helpdesk.reports.index', [
            'title' => 'Help-Desk-Berichte',
            'activeNav' => 'helpdesk-reports',
            'areaLabel' => 'Help Desk',
            'report' => $this->reports->overview($from, $to),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function export(Request $request): Response
    {
        [$from, $to] = TicketReportService::range($request->queryString('from') ?: null, $request->queryString('to') ?: null);

        return Response::download($this->reports->exportCsv($from, $to), CsvWriter::MIME, CsvWriter::filename('helpdesk-' . $from . '_' . $to));
    }
}
