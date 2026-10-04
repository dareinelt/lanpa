<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Csrf;
use App\Security\Session;
use App\Services\Orvanta\OrvantaException;
use Throwable;

/**
 * JSON-Schnittstelle der Orvanta-App (AJAX-Aufrufe aus orvanta.js).
 *
 * Alle Endpunkte liegen unter /api/orvanta/… und setzen einen angemeldeten,
 * fuer Orvanta freigegebenen SSO-Benutzer voraus. Schreibende Aufrufe (POST)
 * senden ein JSON-Objekt mit dem CSRF-Token (Feld "_token" oder Header
 * X-CSRF-Token). Fehler werden als {"error": "…"} mit passendem HTTP-Status
 * geliefert.
 */
final class OrvantaApiController extends Controller
{
    private const MAX_BODY = 20 * 1024 * 1024;
    private const SYNC_INTERVAL = 300;

    /** @var array<string,mixed> */
    private array $body = [];

    // ------------------------------------------------------------------
    // Status / Mail
    // ------------------------------------------------------------------

    public function status(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $config = Container::orvantaConfig();

            return [
                'user' => ['name' => $access['user']['display_name'] ?? $access['user']['username'], 'email' => $access['impersonate']],
                'demo' => $config->isDemo(),
                'host' => $config->get('exchange_host'),
                'cache' => Container::orvantaAttachments()->usage($access['uid']),
                'server_time' => time(),
            ];
        });
    }

    public function folders(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['folders' => Container::orvantaExchange()->folders($access['impersonate'])]);
    }

    public function messages(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => Container::orvantaExchange()->messages(
            $access['impersonate'],
            (string) $request->query('ordner', 'inbox'),
            max(0, $request->queryInt('offset', 0)),
            max(1, min(100, $request->queryInt('limit', 50))),
            trim((string) $request->query('q', ''))
        ));
    }

    public function message(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => Container::orvantaExchange()->message($access['impersonate'], $this->requireId($request->query('id'))));
    }

    public function send(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $mail = $this->mailPayload();
            $result = Container::orvantaExchange()->send($access['impersonate'], $mail);

            return $result + ['message' => 'Die Nachricht wurde gesendet.'];
        }, true);
    }

    public function draft(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $result = Container::orvantaExchange()->saveDraft($access['impersonate'], $this->mailPayload());

            return $result + ['message' => 'Der Entwurf wurde gespeichert.'];
        }, true);
    }

    public function respond(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            Container::orvantaExchange()->respond(
                $access['impersonate'],
                $this->requireId($this->str('id')),
                $this->str('mode', 'reply'),
                $this->str('body'),
                $this->addresses('to'),
                $this->bool('html', true)
            );

            return ['message' => $this->str('mode') === 'forward' ? 'Die Nachricht wurde weitergeleitet.' : 'Die Antwort wurde gesendet.'];
        }, true);
    }

    /**
     * Sammelaktion fuer Nachrichten: read|unread|flag|unflag|move|delete|delete_permanent.
     */
    public function mailAction(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $ids = $this->ids();
            $exchange = Container::orvantaExchange();
            $action = $this->str('action');
            match ($action) {
                'read' => $exchange->markRead($access['impersonate'], $ids, true),
                'unread' => $exchange->markRead($access['impersonate'], $ids, false),
                'flag' => $exchange->flag($access['impersonate'], $ids, true),
                'unflag' => $exchange->flag($access['impersonate'], $ids, false),
                'move' => $exchange->move($access['impersonate'], $ids, $this->requireId($this->str('folder'))),
                'delete' => $exchange->delete($access['impersonate'], $ids, false),
                'delete_permanent' => $exchange->delete($access['impersonate'], $ids, true),
                default => throw new OrvantaException('Unbekannte Aktion.', 422),
            };

            return ['ok' => true, 'count' => count($ids)];
        }, true);
    }

    // ------------------------------------------------------------------
    // Anhaenge / Zwischenspeicher
    // ------------------------------------------------------------------

    public function attachmentLink(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $attachments = Container::orvantaAttachments();
            $name = $this->str('name', 'anhang');
            $token = $attachments->token($access['uid'], $access['impersonate'], $this->requireId($this->str('attachment_id')), $name);

            return [
                'url' => OrvantaController::PATH . '/anhang/oeffnen?' . http_build_query(['token' => $token]),
                'mode' => $attachments::openMode($name),
                'expires_in' => $attachments::TOKEN_LIFETIME,
            ];
        }, true);
    }

    public function attachmentToNextcloud(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $result = Container::orvantaAttachments()->saveToNextcloud($access['uid'], $access['impersonate'], $this->requireId($this->str('attachment_id')), $this->str('folder'));
            if (!$result['ok']) {
                throw new OrvantaException($result['message'], 502);
            }

            return $result;
        }, true);
    }

    public function cacheUsage(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => Container::orvantaAttachments()->usage($access['uid']));
    }

    public function cacheClear(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $removed = Container::orvantaAttachments()->clear($access['uid']);

            return Container::orvantaAttachments()->usage($access['uid']) + ['removed' => $removed, 'message' => 'Der Zwischenspeicher wurde geleert.'];
        }, true);
    }

    // ------------------------------------------------------------------
    // Kalender
    // ------------------------------------------------------------------

    public function calendar(Request $request): Response
    {
        return $this->handle($request, function (array $access) use ($request): array {
            $start = $request->queryInt('start', strtotime('monday this week') ?: time());
            $end = $request->queryInt('end', $start + 7 * 86400);
            if ($end <= $start || $end - $start > 100 * 86400) {
                throw new OrvantaException('Ungültiger Zeitraum.', 422);
            }

            return ['items' => Container::orvantaExchange()->calendar($access['impersonate'], $start, $end), 'start' => $start, 'end' => $end];
        });
    }

    public function event(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => Container::orvantaExchange()->event($access['impersonate'], $this->requireId($request->query('id'))));
    }

    public function saveEvent(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $event = [
                'subject' => $this->str('subject'),
                'start' => $this->int('start'),
                'end' => $this->int('end'),
                'all_day' => $this->bool('all_day'),
                'location' => $this->str('location'),
                'body' => $this->str('body'),
                'reminder' => $this->int('reminder', 15),
                'free_busy' => $this->str('free_busy', 'Busy'),
                'required' => $this->addresses('required'),
                'optional' => $this->addresses('optional'),
            ];
            $id = $this->str('id');
            $exchange = Container::orvantaExchange();
            if ($id === '') {
                $result = $exchange->createEvent($access['impersonate'], $event);
            } else {
                $exchange->updateEvent($access['impersonate'], $id, $event, $this->str('change_key'));
                $result = ['id' => $id];
            }
            $this->resync($access);

            return $result + ['message' => $id === '' ? 'Der Termin wurde angelegt.' : 'Der Termin wurde gespeichert.'];
        }, true);
    }

    public function deleteEvent(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            Container::orvantaExchange()->deleteEvent($access['impersonate'], $this->requireId($this->str('id')));
            $this->resync($access);

            return ['ok' => true, 'message' => 'Der Termin wurde gelöscht.'];
        }, true);
    }

    public function meetingResponse(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            Container::orvantaExchange()->respondToMeeting($access['impersonate'], $this->requireId($this->str('id')), $this->str('response', 'accept'));
            $this->resync($access);

            return ['ok' => true, 'message' => 'Die Antwort wurde gesendet.'];
        }, true);
    }

    // ------------------------------------------------------------------
    // Kontakte
    // ------------------------------------------------------------------

    public function contacts(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['items' => Container::orvantaExchange()->contacts($access['impersonate'], trim((string) $request->query('q', '')))]);
    }

    public function contact(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => Container::orvantaExchange()->contact($access['impersonate'], $this->requireId($request->query('id'))));
    }

    public function saveContact(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $contact = [];
            foreach (['given_name', 'surname', 'company', 'job_title', 'department', 'email', 'phone', 'mobile', 'notes'] as $key) {
                $contact[$key] = $this->str($key);
            }
            if (trim($contact['given_name'] . $contact['surname'] . $contact['company']) === '') {
                throw new OrvantaException('Bitte mindestens Vorname, Nachname oder Firma angeben.', 422);
            }
            $id = $this->str('id');
            $exchange = Container::orvantaExchange();
            if ($id === '') {
                $result = $exchange->createContact($access['impersonate'], $contact);
            } else {
                $exchange->updateContact($access['impersonate'], $id, $contact);
                $result = ['id' => $id];
            }

            return $result + ['message' => 'Der Kontakt wurde gespeichert.'];
        }, true);
    }

    public function deleteContact(Request $request): Response
    {
        return $this->deleteItems($request, 'Der Kontakt wurde gelöscht.');
    }

    // ------------------------------------------------------------------
    // Aufgaben
    // ------------------------------------------------------------------

    public function tasks(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['items' => Container::orvantaExchange()->tasks($access['impersonate'], $request->query('erledigt', '1') !== '0')]);
    }

    public function task(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => Container::orvantaExchange()->task($access['impersonate'], $this->requireId($request->query('id'))));
    }

    public function saveTask(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $task = ['subject' => $this->str('subject'), 'body' => $this->str('body'), 'importance' => $this->str('importance', 'Normal'), 'status' => $this->str('status', 'NotStarted')];
            foreach (['due', 'start', 'reminder', 'percent'] as $key) {
                if (array_key_exists($key, $this->body)) {
                    $task[$key] = $this->int($key);
                }
            }
            $id = $this->str('id');
            $exchange = Container::orvantaExchange();
            if ($id === '') {
                $result = $exchange->createTask($access['impersonate'], $task);
            } else {
                $exchange->updateTask($access['impersonate'], $id, $task);
                $result = ['id' => $id];
            }

            return $result + ['message' => 'Die Aufgabe wurde gespeichert.'];
        }, true);
    }

    public function deleteTask(Request $request): Response
    {
        return $this->deleteItems($request, 'Die Aufgabe wurde gelöscht.');
    }

    // ------------------------------------------------------------------
    // Notizen
    // ------------------------------------------------------------------

    public function notes(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['items' => Container::orvantaExchange()->notes($access['impersonate'])]);
    }

    public function note(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => Container::orvantaExchange()->note($access['impersonate'], $this->requireId($request->query('id'))));
    }

    public function saveNote(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $body = $this->str('body');
            if (trim($body) === '') {
                throw new OrvantaException('Die Notiz ist leer.', 422);
            }
            $id = $this->str('id');
            $exchange = Container::orvantaExchange();
            if ($id === '') {
                $result = $exchange->createNote($access['impersonate'], $body);
            } else {
                $exchange->updateNote($access['impersonate'], $id, $body);
                $result = ['id' => $id];
            }

            return $result + ['message' => 'Die Notiz wurde gespeichert.'];
        }, true);
    }

    public function deleteNote(Request $request): Response
    {
        return $this->deleteItems($request, 'Die Notiz wurde gelöscht.');
    }

    // ------------------------------------------------------------------
    // Terminerinnerungen
    // ------------------------------------------------------------------

    /**
     * Abfrage faelliger Erinnerungen; synchronisiert hoechstens alle fuenf
     * Minuten (oder mit ?sync=1) die anstehenden Termine aus Exchange.
     */
    public function reminders(Request $request): Response
    {
        return $this->handle($request, function (array $access) use ($request): array {
            $notifications = Container::orvantaNotifications();
            $warning = null;
            $key = 'orvanta_sync_' . $access['uid'];
            $last = (int) Session::get($key, 0);
            if ($request->query('sync') === '1' || time() - $last >= self::SYNC_INTERVAL) {
                $warning = $notifications->sync($access['uid'], $access['impersonate']);
                Session::put($key, time());
            }

            return $notifications->poll($access['uid']) + ['warning' => $warning];
        });
    }

    public function dismissReminder(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            Container::orvantaNotifications()->dismiss($access['uid'], $this->int('id'));

            return ['ok' => true];
        }, true);
    }

    public function snoozeReminder(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            Container::orvantaNotifications()->snooze($access['uid'], $this->int('id'), max(1, min(1440, $this->int('minutes', 5))));

            return ['ok' => true];
        }, true);
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    /**
     * Gemeinsamer Rahmen: Zugriffspruefung, JSON-Body, CSRF (POST) und
     * Fehlerbehandlung.
     *
     * @param \Closure(array{user:array<string,mixed>,uid:string,impersonate:string}):array<string,mixed> $action
     */
    private function handle(Request $request, \Closure $action, bool $write = false): Response
    {
        try {
            $access = OrvantaController::authorize($request);
            if ($write) {
                $this->body = $this->readBody($request);
                $token = $this->body['_token'] ?? $request->server['HTTP_X_CSRF_TOKEN'] ?? null;
                if (!Csrf::isValid(is_string($token) ? $token : null)) {
                    throw new HttpException(419, 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
                }
            }

            return Response::json($action($access))->withHeader('Vary', 'Cookie');
        } catch (OrvantaException $exception) {
            return Response::json(['error' => $exception->getMessage()], $exception->status());
        } catch (ValidationException $exception) {
            return Response::json(['error' => implode(' ', $exception->errors())], 422);
        } catch (HttpException $exception) {
            return Response::json(['error' => $exception->getMessage()], $exception->statusCode());
        } catch (Throwable $exception) {
            app_logger()->error('Orvanta: Unerwarteter Fehler.', ['path' => $request->path, 'error' => $exception->getMessage()]);

            return Response::json(['error' => 'Orvanta konnte die Anfrage nicht verarbeiten.'], 500);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readBody(Request $request): array
    {
        $contentType = (string) ($request->server['CONTENT_TYPE'] ?? '');
        if (!str_contains($contentType, 'application/json')) {
            return $request->post;
        }
        $raw = (string) file_get_contents('php://input');
        if (strlen($raw) > self::MAX_BODY) {
            throw new OrvantaException('Die Anfrage ist zu groß (maximal 20 MB).', 413);
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function deleteItems(Request $request, string $message): Response
    {
        return $this->handle($request, function (array $access) use ($message): array {
            Container::orvantaExchange()->delete($access['impersonate'], $this->ids(), false);

            return ['ok' => true, 'message' => $message];
        }, true);
    }

    /**
     * @param array{uid:string,impersonate:string} $access
     */
    private function resync(array $access): void
    {
        Container::orvantaNotifications()->sync($access['uid'], $access['impersonate']);
        Session::put('orvanta_sync_' . $access['uid'], time());
    }

    /**
     * @return array<string,mixed>
     */
    private function mailPayload(): array
    {
        $attachments = [];
        $total = 0;
        foreach ((array) ($this->body['attachments'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $content = base64_decode((string) ($file['content'] ?? ''), true);
            if ($content === false || $content === '') {
                continue;
            }
            $total += strlen($content);
            if ($total > 15 * 1024 * 1024) {
                throw new OrvantaException('Die Anhänge sind zusammen zu groß (maximal 15 MB).', 413);
            }
            $attachments[] = ['name' => (string) ($file['name'] ?? 'anhang.bin'), 'content_type' => (string) ($file['content_type'] ?? 'application/octet-stream'), 'content' => $content];
        }

        return [
            'to' => $this->addresses('to'),
            'cc' => $this->addresses('cc'),
            'bcc' => $this->addresses('bcc'),
            'subject' => $this->str('subject'),
            'body' => $this->str('body'),
            'html' => $this->bool('html', true),
            'importance' => $this->str('importance', 'Normal'),
            'attachments' => $attachments,
        ];
    }

    /**
     * @return list<string>
     */
    private function addresses(string $key): array
    {
        $value = $this->body[$key] ?? [];
        if (is_string($value)) {
            $value = preg_split('/[;,\s]+/', $value) ?: [];
        }
        $out = [];
        foreach ((array) $value as $address) {
            // Das Frontend liefert Empfaenger als {name, email}-Objekte.
            if (is_array($address)) {
                $address = $address['email'] ?? '';
            }
            $address = trim((string) $address, " \t\n\r<>");
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = $address;
            } elseif ($address !== '') {
                throw new OrvantaException('Ungültige E-Mail-Adresse: ' . $address, 422);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        $ids = $this->body['ids'] ?? ($this->body['id'] ?? []);
        $ids = array_values(array_filter(array_map('strval', (array) $ids), static fn (string $id): bool => $id !== ''));
        if ($ids === [] || count($ids) > 200) {
            throw new OrvantaException('Bitte Elemente auswählen (höchstens 200).', 422);
        }

        return $ids;
    }

    private function requireId(?string $id): string
    {
        $id = trim((string) $id);
        if ($id === '' || strlen($id) > 2048) {
            throw new OrvantaException('Es wurde kein Element angegeben.', 422);
        }

        return $id;
    }

    private function str(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    private function int(string $key, int $default = 0): int
    {
        $value = $this->body[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    private function bool(string $key, bool $default = false): bool
    {
        $value = $this->body[$key] ?? $default;

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
