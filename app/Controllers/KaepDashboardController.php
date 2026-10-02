<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\KaepDashboard;

final class KaepDashboardController extends Controller
{
    private function access(Request $request): string
    {
        $actor = $this->kaepActor($request);
        if ($actor === null) {
            throw new HttpException(403, 'KAEP-Team oder Administration erforderlich. Bitte mit einem berechtigten Konto anmelden.');
        }

        return $actor;
    }

    public function index(Request $request): Response
    {
        $actor = $this->access($request);

        return $this->view('emergency.dashboard', [
            'pageTitle' => 'KAEP-Dashboard', 'activeNav' => 'kaep_dashboard',
            'pageScript' => 'kaep-dashboard.js', 'actor' => $actor,
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function data(Request $request): Response
    {
        $this->access($request);
        $status = $request->query('status', 'active');
        if (!in_array($status, ['active', 'closed'], true)) {
            throw new HttpException(422, 'Ungültiger Ereignisstatus.');
        }

        return Response::json(Container::emergencyPlans()->repository->dashboard(
            max(0, $request->queryInt('id')), $status, max(1, min(100000, $request->queryInt('page', 1)))
        ));
    }

    public function journal(Request $request): Response
    {
        $this->access($request);
        $repo = Container::emergencyPlans()->repository;
        $id = $request->queryInt('id');
        $repo->event($id);

        return Response::json(['logs' => $repo->journal($id, max(0, $request->queryInt('before')), (string) $request->query('node', ''))]);
    }

    public function update(Request $request): Response
    {
        $actor = $this->access($request);
        $this->requireValidCsrf($request);
        try {
            $service = Container::emergencyPlans();
            $event = $service->repository->event($request->inputInt('id'));
            if (in_array($request->input('action'), ['status', 'comment', 'sms', 'close'], true)) {
                $service->update($event, $actor, $request->post);
            } else {
                $service->repository->coordinate($event, KaepDashboard::change($event, $request->post), $actor);
            }

            return Response::json(['message' => 'Gespeichert und im Einsatzjournal dokumentiert.']);
        } catch (ValidationException $exception) {
            return Response::json(['error' => implode(' ', $exception->errors())], 422);
        } catch (HttpException $exception) {
            return Response::json(['error' => $exception->getMessage()], $exception->statusCode());
        }
    }

    public function stream(Request $request): Response
    {
        $this->access($request);
        $repo = Container::emergencyPlans()->repository;
        $repo->dashboardToken();

        return Response::eventStream(static function () use ($repo): void {
            // Begrenzte Verbindung: Anmeldung und Gruppenrechte beim Wiederverbinden erneut prüfen.
            $until = microtime(true) + 25;
            $last = '';
            $heartbeat = 0.0;
            echo "retry: 500\n\n";
            try {
                while (!connection_aborted() && microtime(true) < $until) {
                    $token = $repo->dashboardToken();
                    if ($token !== $last || microtime(true) - $heartbeat >= 5) {
                        echo 'data: ' . json_encode(['token' => $token], JSON_THROW_ON_ERROR) . "\n\n";
                        flush();
                        $heartbeat = microtime(true);
                        $last = $token;
                    }
                    usleep(200000);
                }
                if (!connection_aborted()) {
                    echo "event: renew\ndata: {}\n\n";
                    flush();
                }
            } catch (\Throwable $exception) {
                app_logger()->error('KAEP-Livestream unterbrochen.', ['message' => $exception->getMessage()]);
                echo "event: unavailable\ndata: {}\n\n";
                flush();
            }
        });
    }
}
