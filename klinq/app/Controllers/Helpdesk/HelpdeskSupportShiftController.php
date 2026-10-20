<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\CurrentUser;
use App\Services\Helpdesk\SupportShiftService;

/** Übernahme der tagesweisen 1st-/2nd-Level-Zuständigkeit über das Dashboard-Overlay „Zuständigkeit“. */
final class HelpdeskSupportShiftController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly SupportShiftService $shifts
    ) {
        parent::__construct($view, $currentUser);
    }

    public function claim(Request $request): Response
    {
        $level = $request->int('level');
        $response = $this->attempt($request, function () use ($level): void {
            $this->shifts->claim((int) $level);
        }, '/helpdesk', false);

        $label = $level === SupportShiftService::SECOND_LEVEL ? '2nd Level Support' : '1st Level Support';

        return $response ?? $this->respond($request, 'Zuständigkeit für ' . $label . ' übernommen.', '/helpdesk');
    }
}
