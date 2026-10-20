<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\AdSyncRunRepository;
use App\Security\CurrentUser;
use App\Services\Ad\EmployeeSyncService;

final class AdSyncController extends BaseController
{
    public function __construct(
        Container $c,
        private readonly EmployeeSyncService $sync,
        private readonly AdSyncRunRepository $runs,
        private readonly Config $config
    ) {
        parent::__construct($c->get(View::class), $c->get(CurrentUser::class));
    }

    public function index(Request $request): Response
    {
        $attributes = (array) $this->config->get('ldap.attributes', []);

        return $this->render('admin/ad_sync', [
            'title' => 'AD-Synchronisation',
            'activeNav' => 'employees',
            'enabled' => $this->sync->isEnabled(),
            'driver' => (string) $this->config->get('ldap.driver', 'ldap'),
            'host' => (string) $this->config->get('ldap.host'),
            'baseDn' => (string) $this->config->get('ldap.base_dn'),
            'intervalMinutes' => (int) $this->config->get('ldap.sync_interval_minutes', 0),
            'attributes' => $attributes,
            'runs' => $this->runs->latest(20),
            'running' => $this->runs->running(),
            'lastSuccess' => $this->runs->lastSuccessful(),
        ]);
    }

    public function show(Request $request): Response
    {
        $run = $this->findOrFail($this->runs->find((int) $request->param('id')), 'Synchronisationslauf nicht gefunden');
        $run['details'] = is_string($run['details']) ? (array) json_decode($run['details'], true) : [];

        return $this->render('admin/ad_sync_run', ['title' => 'Synchronisationslauf #' . $run['id'], 'activeNav' => 'employees', 'run' => $run]);
    }

    public function run(Request $request): Response
    {
        $dryRun = $request->input('dry_run') === '1';
        try {
            $result = $this->sync->run($this->currentUser->username(), $dryRun);
            if (($result['status'] ?? '') === 'success') {
                $this->flash('success', (string) $result['message']);
            } else {
                $this->flash('error', 'Synchronisation fehlgeschlagen: ' . ($result['message'] ?? 'unbekannter Fehler'));
            }
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/ad-sync');
    }
}
