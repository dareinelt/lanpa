<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

/**
 * Adminbereich „System → Information“: Versionen von PHP und Datenbank,
 * Migrationsstand und weitere Angaben zum laufenden System.
 */
final class SystemInfoController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin.system-info', [
            'pageTitle' => 'Information',
            'activeNav' => 'system_info',
            'report' => Container::systemInfo()->report(),
            'secure' => $request->isSecure(),
        ]);
    }
}
