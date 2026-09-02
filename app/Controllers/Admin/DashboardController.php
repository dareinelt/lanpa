<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Services\LdapClient;

final class DashboardController extends AdminController
{
    public function index(Request $request): Response
    {
        $statistics = Container::statistics();
        $phonebook = Container::phonebook();
        $settings = Container::settings();

        return $this->adminView('admin.dashboard', [
            'pageTitle' => 'Dashboard',
            'activeNav' => 'dashboard',
            'navigationCount' => count(Container::navigation()->allItems()),
            'phonebookCount' => $phonebook->countActive(),
            'lastSync' => Container::syncLogRepository()->last(),
            'lastSuccessfulSync' => Container::syncLogRepository()->lastSuccessful(),
            'clicksTotal' => $statistics->totalClicks(),
            'clicks7' => $statistics->clicksLastDays(7),
            'clicks30' => $statistics->clicksLastDays(30),
            'ldapConfigured' => $settings->isLdapConfigured(),
            'ldapExtensionAvailable' => LdapClient::isSupported(),
        ]);
    }
}
