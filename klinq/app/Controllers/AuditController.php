<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\AuditLogRepository;
use App\Security\CurrentUser;
use App\Services\ReportService;
use App\Support\Paginator;

/** Audit-Log: Wer hat wann was geändert (nur lesend). */
final class AuditController extends BaseController
{
    public const ACTION_LABELS = [
        'create' => 'Angelegt', 'update' => 'Geändert', 'delete' => 'Gelöscht', 'status' => 'Statuswechsel',
        'activate' => 'Aktiviert', 'deactivate' => 'Deaktiviert', 'checkout' => 'Entnahme', 'return' => 'Retoure',
        'cancel' => 'Storniert', 'complete' => 'Abgeschlossen', 'receive' => 'Wareneingang', 'assign' => 'Zugeordnet',
        'release' => 'Freigegeben', 'upload' => 'Dokument hochgeladen', 'print' => 'Gedruckt', 'import' => 'Import',
        'login' => 'Anmeldung', 'logout' => 'Abmeldung', 'login_failed' => 'Anmeldung fehlgeschlagen', 'sync' => 'AD-Sync',
        'retire' => 'Ausgemustert', 'merge' => 'Zusammengeführt', 'reset_password' => 'Passwort zurückgesetzt', 'change_password' => 'Passwort geändert',
    ];

    public const OBJECT_LABELS = [
        'asset' => 'Asset', 'movement' => 'Bewegung', 'employee' => 'Mitarbeiter', 'location' => 'Standort',
        'cost_center' => 'Kostenstelle', 'manufacturer' => 'Hersteller', 'article' => 'Artikel', 'supplier' => 'Lieferant',
        'purchase_order' => 'Bestellung', 'purchase_order_item' => 'Bestellposition', 'goods_receipt' => 'Wareneingang',
        'license' => 'Lizenz', 'document' => 'Dokument', 'label' => 'Etikett', 'user' => 'Benutzer', 'settings' => 'Einstellungen',
        'import_run' => 'Import', 'asset_type' => 'Assettyp', 'asset_category' => 'Kategorie', 'sync_run' => 'AD-Sync',
    ];

    /** Objekttyp → Detailseite */
    private const OBJECT_LINKS = [
        'asset' => '/assets/%d', 'movement' => '/movements/%d', 'employee' => '/employees/%d', 'location' => '/locations/%d',
        'purchase_order' => '/orders/%d', 'license' => '/licenses/%d', 'import_run' => '/imports/%d', 'supplier' => '/suppliers/%d',
    ];

    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly AuditLogRepository $audit
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->queryString('q'),
            'object_type' => $request->queryString('object_type'),
            'object_id' => $request->int('object_id') ?: '',
            'action' => $request->queryString('action'),
            'username' => $request->queryString('username'),
            'from' => ReportService::validDate($request->queryString('from')) ?? '',
            'to' => ReportService::validDate($request->queryString('to')) ?? '',
        ];
        $paginator = new Paginator($this->audit->countSearch($filters), $request->int('page', 1) ?? 1, 50);

        return $this->render('audit.index', [
            'title' => 'Audit-Log',
            'activeNav' => 'audit',
            'filters' => $filters,
            'entries' => $this->audit->search($filters, $paginator->perPage, $paginator->offset()),
            'paginator' => $paginator,
            'basePath' => '/audit',
            'query' => $request->query(),
            'actions' => $this->audit->distinctValues('action'),
            'objectTypes' => $this->audit->distinctValues('object_type'),
            'usernames' => $this->audit->distinctValues('username'),
            'actionLabels' => self::ACTION_LABELS,
            'objectLabels' => self::OBJECT_LABELS,
            'objectLinks' => self::OBJECT_LINKS,
        ]);
    }
}
