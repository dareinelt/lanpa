<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ConflictException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use Throwable;

final class Application
{
    public function __construct(
        private readonly Router $router,
        private readonly View $view,
        private readonly Logger $logger,
        private readonly bool $debug = false
    ) {}

    public function run(): void
    {
        $request = Request::capture();
        $this->handle($request)->send();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            return $this->errorResponse($request, $exception->statusCode(), $exception->getMessage(), ['errors' => $exception->errors()]);
        } catch (ConflictException $exception) {
            return $this->errorResponse($request, 409, $exception->getMessage(), ['conflict' => $exception->details()]);
        } catch (HttpException $exception) {
            return $this->errorResponse($request, $exception->statusCode(), $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Unbehandelte Ausnahme', [
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => basename($exception->getFile()) . ':' . $exception->getLine(),
                'path' => $request->path(),
            ]);
            $message = $this->debug ? $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine() : 'Interner Serverfehler';

            return $this->errorResponse($request, 500, $message);
        }
    }

    /** @param array<string,mixed> $extra */
    private function errorResponse(Request $request, int $status, string $message, array $extra = []): Response
    {
        if ($request->wantsJson()) {
            return Response::json(array_merge(['error' => $message, 'status' => $status], $extra), $status);
        }

        try {
            return Response::html($this->view->render('errors.error', ['status' => $status, 'message' => $message]), $status);
        } catch (Throwable) {
            return Response::text("{$status} – {$message}", $status);
        }
    }
}
