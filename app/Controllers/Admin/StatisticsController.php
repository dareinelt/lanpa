<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Services\StatisticsService;

final class StatisticsController extends AdminController
{
    public function index(Request $request): Response
    {
        $days = StatisticsService::normalizePeriod($request->queryInt('days', 7));

        return $this->adminView('admin.statistics', [
            'pageTitle' => 'Statistik',
            'activeNav' => 'statistics',
            'days' => $days,
            'periods' => StatisticsService::PERIODS,
            'report' => Container::statistics()->report($days),
            'pageScript' => 'statistics.js',
        ]);
    }

    public function data(Request $request): Response
    {
        $days = StatisticsService::normalizePeriod($request->queryInt('days', 7));

        return Response::json(Container::statistics()->report($days));
    }
}
