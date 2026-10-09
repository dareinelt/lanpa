<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\OrvantaMailBackendInterface;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Csrf;
use App\Security\Session;
use App\Services\LdapClient;
use App\Services\Orvanta\OrvantaAiService;
use App\Services\Orvanta\OrvantaException;
use App\Services\Orvanta\OrvantaExchangeService;
use App\Services\Orvanta\OrvantaSignatureService;
use App\Services\Orvanta\OrvantaSpellcheckService;
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
    private const PASSWORD_ATTEMPTS = 5;
    private const PASSWORD_LOCK_SECONDS = 300;

    /** @var array<string,mixed> */
    private array $body = [];

    // ------------------------------------------------------------------
    // Status / Mail
    // ------------------------------------------------------------------

    public function status(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $config = Container::orvantaConfig();
            $this->registerArchive($access);

            return [
                'user' => ['name' => $access['user']['display_name'] ?? $access['user']['username'], 'email' => $access['impersonate']],
                'demo' => $access['route']->isProxy() ? false : $config->isDemo(),
                'host' => $access['route']->isProxy() ? '' : $config->get('exchange_host'),
                'exchange_host' => OrvantaController::exchangeHost($access),
                'backend' => $this->mail($access)->backendName(),
                'capabilities' => $this->mail($access)->capabilities(),
                'cache' => Container::orvantaAttachments()->usage($access['uid']),
                'archive' => Container::orvantaArchive()->status($access['uid'], $this->archiveEnabledFor($access)),
                'server_time' => time(),
            ];
        });
    }

    /**
     * Keep-alive: haelt die Sitzung waehrend der Bearbeitung (z. B. langer
     * Antworten) am Leben und liefert das aktuelle CSRF-Token sowie den
     * Exchange-Host der Sitzung (Tooltipp im Fussbereich).
     */
    public function keepAlive(Request $request): Response
    {
        return $this->handle($request, static function (array $access): array {
            Session::put('_orvanta_alive', time());

            return [
                'ok' => true,
                'csrf' => Csrf::token(),
                'exchange_host' => OrvantaController::exchangeHost($access),
                'server_time' => time(),
            ];
        })->withHeader('Cache-Control', 'no-store');
    }

    public function folders(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['folders' => $this->mail($access)->folders($access['impersonate'])]);
    }

    /**
     * Eigenschaften eines Ordners (Kontextmenue „Eigenschaften“): Anzahl der
     * Elemente und Groesse, auch zusammen mit den Unterordnern.
     */
    public function folderProperties(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => $this->mail($access)->folderProperties($access['impersonate'], $this->requireId($request->query('ordner'))));
    }

    /**
     * Neuen Ordner anlegen; `parent` leer = oberste Ebene des Postfachs.
     */
    public function createFolder(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $parent = trim($this->str('parent'));
            $result = $this->mail($access)->createFolder($access['impersonate'], $parent !== '' ? $this->requireId($parent) : '', $this->str('name'));

            return $result + ['message' => 'Der Ordner „' . $result['name'] . '“ wurde angelegt.'];
        }, true);
    }

    /**
     * Alle Nachrichten eines Ordners als gelesen markieren.
     */
    public function markFolderRead(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $this->mail($access)->markFolderRead($access['impersonate'], $this->requireId($this->str('folder')));

            return ['ok' => true, 'message' => 'Alle Nachrichten wurden als gelesen markiert.'];
        }, true);
    }

    public function messages(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => $this->mail($access)->messages(
            $access['impersonate'],
            (string) $request->query('ordner', 'inbox'),
            max(0, $request->queryInt('offset', 0)),
            max(1, min(100, $request->queryInt('limit', 50))),
            trim((string) $request->query('q', ''))
        ));
    }

    public function message(Request $request): Response
    {
        return $this->handle($request, function (array $access) use ($request): array {
            $message = $this->mail($access)->message($access['impersonate'], $this->requireId($request->query('id')));

            return Container::orvantaAttachments()->embedInlineImages($access['uid'], $access['impersonate'], $message);
        });
    }

    /**
     * Rohe Internet-Kopfzeilen einer Nachricht (Kontextmenue „Info“).
     */
    public function messageHeaders(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => $this->mail($access)->messageHeaders($access['impersonate'], $this->requireId($request->query('id'))));
    }

    public function send(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $result = $this->mail($access)->send($access['impersonate'], $this->withSignature($this->mailPayload(), $access), $this->str('draft_id'), $this->str('change_key'));
            $this->rememberRecipients($access);

            return $result + ['message' => 'Die Nachricht wurde gesendet.'];
        }, true);
    }

    /**
     * Empfaenger-Vorschlaege fuer An/Cc/Bcc (Telefonliste + eigener Verlauf).
     */
    public function recipients(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['items' => Container::orvantaRecipients()->suggest(
            $access['uid'],
            (string) $request->query('q', ''),
            $request->queryInt('limit', \App\Services\Orvanta\OrvantaRecipientService::DEFAULT_LIMIT),
            Container::auth()->check()
        )]);
    }

    /**
     * Entwurf anlegen oder (mit draft_id) aktualisieren; reply_id/mode legen
     * einen Antwort-Entwurf mit Bezug zur Originalnachricht an.
     */
    public function draft(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $mail = $this->withSignature($this->mailPayload(), $access);
            $replyId = $this->str('reply_id');
            $mode = $this->str('mode', 'new');
            if ($replyId !== '' && in_array($mode, ['reply', 'replyall', 'forward'], true)) {
                $mail['reference'] = ['id' => $replyId, 'mode' => $mode];
            }
            $result = $this->mail($access)->saveDraft($access['impersonate'], $mail, $this->str('draft_id'), $this->str('change_key'));

            return $result + ['message' => 'Der Entwurf wurde gespeichert.'];
        }, true);
    }

    public function respond(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $result = $this->mail($access)->respond(
                $access['impersonate'],
                $this->requireId($this->str('id')),
                $this->str('mode', 'reply'),
                $this->withSignature($this->mailPayload(), $access)
            );
            $this->rememberRecipients($access);

            return $result + ['message' => $this->str('mode') === 'forward' ? 'Die Nachricht wurde weitergeleitet.' : 'Die Antwort wurde gesendet.'];
        }, true);
    }

    /**
     * Sammelaktion fuer Nachrichten: read|unread|flag|unflag|move|delete|delete_permanent.
     */
    public function mailAction(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $ids = $this->ids();
            $exchange = $this->mail($access);
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
        return $this->handle($request, fn (array $access): array => Container::orvantaAttachments()->usage($access['uid']) + ['mailbox' => $this->mailboxUsage($access)]);
    }

    public function cacheClear(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $removed = Container::orvantaAttachments()->clear($access['uid']);

            return Container::orvantaAttachments()->usage($access['uid']) + ['mailbox' => $this->mailboxUsage($access), 'removed' => $removed, 'message' => 'Der Zwischenspeicher wurde geleert.'];
        }, true);
    }

    // ------------------------------------------------------------------
    // Langzeitarchiv
    // ------------------------------------------------------------------

    public function archiveStatus(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $this->registerArchive($access);

            return Container::orvantaArchive()->status($access['uid'], $this->archiveEnabledFor($access));
        });
    }

    /**
     * Postfach fuer die Hintergrund-Archivierung registrieren: ab der ersten
     * Nutzung arbeitet der Archiv-Worker unabhaengig von einer geoeffneten
     * Oberflaeche. Wird von den Statusabfragen der Oberflaeche aufgerufen.
     * Nur Mitglieder der Freigabegruppe werden registriert.
     *
     * @param array{user:array<string,mixed>,uid:string,impersonate:string} $access
     */
    private function registerArchive(array $access): void
    {
        if ($this->archiveEnabledFor($access)) {
            Container::orvantaArchive()->registerMailbox($access['uid'], $access['impersonate']);
        }
    }

    /**
     * @param array{user:array<string,mixed>,uid:string,impersonate:string} $access
     */
    private function archiveEnabledFor(array $access): bool
    {
        // Der Archiv-Worker arbeitet ueber EWS; Proxy-Postfaecher werden nicht
        // registriert (bereits archivierte Daten bleiben lesbar).
        if (!$this->can($access, OrvantaMailBackendInterface::CAPABILITY_ARCHIVE)) {
            return false;
        }
        $groups = $access['user']['groups'] ?? [];

        return Container::orvantaConfig()->archiveEnabledFor(is_array($groups) ? array_values($groups) : []);
    }

    public function archiveFolders(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['folders' => Container::orvantaArchive()->folders($access['uid'])]);
    }

    public function archiveMessages(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => Container::orvantaArchive()->messages(
            $access['uid'],
            max(1, $request->queryInt('ordner', 0)),
            max(0, $request->queryInt('offset', 0)),
            max(1, min(100, $request->queryInt('limit', 50)))
        ));
    }

    public function archiveMessage(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => Container::orvantaArchive()->message($access['uid'], max(1, $request->queryInt('id', 0))));
    }

    public function archiveSearch(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['items' => Container::orvantaArchive()->search($access['uid'], trim((string) $request->query('q', '')))]);
    }

    /**
     * Postfachbelegung des Mail-Backends; Fehler blockieren die Anzeige des
     * Zwischenspeichers nicht (null = nicht ermittelbar). Die AD-Grenzen
     * werden nur fuer Exchange-Postfaecher gelesen; Proxy-Postfaecher
     * verwenden die im Adminbereich eingetragene feste Postfachgroesse.
     *
     * @param array<string,mixed> $access
     * @return array{used:int,quota:int,warning:int,receive_limit:int,limit:int,percent:int,source:string}|null
     */
    private function mailboxUsage(array $access): ?array
    {
        try {
            $backend = $this->mail($access);
            $directory = $backend instanceof OrvantaExchangeService ? $this->directoryQuota($access['user']) : null;

            return $backend->mailboxUsage($access['impersonate'], $directory);
        } catch (Throwable) {
            return null;
        }
    }

    /** Gueltigkeit der aus dem AD gelesenen Postfachgrenzen in der Sitzung (Sekunden). */
    private const DIRECTORY_QUOTA_TTL = 900;

    /**
     * Postfachgrenzen des Benutzers aus seiner Identitaetsquelle (AD),
     * je Sitzung zwischengespeichert; Fehler fuehren zu null.
     *
     * @param array<string,mixed> $ssoUser
     * @return array{warning:int,send:int,receive:int,defaults:bool}|null
     */
    private function directoryQuota(array $ssoUser): ?array
    {
        $username = (string) ($ssoUser['username'] ?? '');
        if ($username === '' || (!empty($ssoUser['fake']) && (int) ($ssoUser['id'] ?? 0) === 0) || !LdapClient::isSupported() || Container::orvantaConfig()->isDemo()) {
            return null;
        }
        $sourceId = (int) ($ssoUser['source_id'] ?? 0);
        $key = $sourceId . ':' . strtolower($username);
        $cached = Session::get('orvanta_directory_quota');
        if (is_array($cached) && ($cached['key'] ?? '') === $key && (int) ($cached['at'] ?? 0) > time() - self::DIRECTORY_QUOTA_TTL) {
            /** @var array{warning:int,send:int,receive:int,defaults:bool}|null $quota */
            $quota = $cached['quota'] ?? null;

            return $quota;
        }

        $quota = null;
        try {
            foreach (Container::identitySources()->configs() as $config) {
                if ((int) ($config['id'] ?? -1) === $sourceId) {
                    $quota = (new LdapClient($config, app_logger()))->mailboxQuota($username);
                    break;
                }
            }
        } catch (Throwable $exception) {
            app_logger()->warning('Orvanta: Postfachgrenzen konnten nicht aus dem AD gelesen werden.', ['error' => $exception->getMessage()]);
        }
        Session::put('orvanta_directory_quota', ['key' => $key, 'at' => time(), 'quota' => $quota]);

        return $quota;
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

            return ['items' => $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_CALENDAR)->calendar($access['impersonate'], $start, $end), 'start' => $start, 'end' => $end];
        });
    }

    public function event(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_CALENDAR)->event($access['impersonate'], $this->requireId($request->query('id'))));
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
            $exchange = $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_CALENDAR);
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

    /**
     * Verschiebt einen Termin per Drag and Drop: nur Beginn und Ende aendern.
     */
    public function moveEvent(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $id = $this->requireId($this->str('id'));
            $start = $this->int('start');
            $end = $this->int('end');
            $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_CALENDAR)->moveEvent($access['impersonate'], $id, $start, $end, $this->str('change_key'));
            $this->resync($access);

            return ['id' => $id, 'start' => $start, 'end' => $end, 'message' => 'Der Termin wurde verschoben.'];
        }, true);
    }

    public function deleteEvent(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_CALENDAR)->deleteEvent($access['impersonate'], $this->requireId($this->str('id')));
            $this->resync($access);

            return ['ok' => true, 'message' => 'Der Termin wurde gelöscht.'];
        }, true);
    }

    public function meetingResponse(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_CALENDAR)->respondToMeeting($access['impersonate'], $this->requireId($this->str('id')), $this->str('response', 'accept'));
            $this->resync($access);

            return ['ok' => true, 'message' => 'Die Antwort wurde gesendet.'];
        }, true);
    }

    // ------------------------------------------------------------------
    // Kontakte
    // ------------------------------------------------------------------

    public function contacts(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['items' => $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_CONTACTS)->contacts($access['impersonate'], trim((string) $request->query('q', '')))]);
    }

    public function contact(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_CONTACTS)->contact($access['impersonate'], $this->requireId($request->query('id'))));
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
            $exchange = $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_CONTACTS);
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
        return $this->deleteItems($request, 'Der Kontakt wurde gelöscht.', OrvantaMailBackendInterface::CAPABILITY_CONTACTS);
    }

    // ------------------------------------------------------------------
    // Aufgaben
    // ------------------------------------------------------------------

    public function tasks(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['items' => $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_TASKS)->tasks($access['impersonate'], $request->query('erledigt', '1') !== '0')]);
    }

    public function task(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_TASKS)->task($access['impersonate'], $this->requireId($request->query('id'))));
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
            $exchange = $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_TASKS);
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
        return $this->deleteItems($request, 'Die Aufgabe wurde gelöscht.', OrvantaMailBackendInterface::CAPABILITY_TASKS);
    }

    // ------------------------------------------------------------------
    // Notizen
    // ------------------------------------------------------------------

    public function notes(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => ['items' => $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_NOTES)->notes($access['impersonate'])]);
    }

    public function note(Request $request): Response
    {
        return $this->handle($request, fn (array $access): array => $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_NOTES)->note($access['impersonate'], $this->requireId($request->query('id'))));
    }

    public function saveNote(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $body = $this->str('body');
            if (trim($body) === '') {
                throw new OrvantaException('Die Notiz ist leer.', 422);
            }
            $id = $this->str('id');
            $exchange = $this->exchange($access, OrvantaMailBackendInterface::CAPABILITY_NOTES);
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
        return $this->deleteItems($request, 'Die Notiz wurde gelöscht.', OrvantaMailBackendInterface::CAPABILITY_NOTES);
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
            // Proxy-Postfaecher haben keinen Kalender: keine EWS-Synchronisation.
            if ($this->can($access, OrvantaMailBackendInterface::CAPABILITY_REMINDERS)
                && ($request->query('sync') === '1' || time() - $last >= self::SYNC_INTERVAL)) {
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
    // KI-Textunterstuetzung
    // ------------------------------------------------------------------

    /**
     * Markierten Text nach Anweisung umformulieren bzw. (mit previous_text)
     * eine erzeugte Fassung verfeinern. Gespeichert werden nur Zaehler.
     */
    public function aiImprove(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $ai = Container::orvantaAi();
            $mode = $this->str('mode');
            if (!in_array($mode, OrvantaAiService::MODES, true)) {
                throw new OrvantaException('Unbekannter Einsatzort der KI-Unterstützung.', 422);
            }
            $text = $this->str('text');
            $prompt = $this->str('prompt');
            $previous = $this->str('previous_text');
            if (strlen($text) > OrvantaAiService::MAX_TEXT * 4 || strlen($previous) > OrvantaAiService::MAX_TEXT * 4 || strlen($prompt) > OrvantaAiService::MAX_PROMPT * 4) {
                throw new OrvantaException('Der Text oder die Anweisung ist zu lang.', 422);
            }
            $context = is_array($this->body['context'] ?? null) ? $this->body['context'] : [];
            $result = $ai->improve($text, $prompt, $mode, $previous !== '' ? $previous : null, [
                'subject' => is_scalar($context['subject'] ?? null) ? (string) $context['subject'] : '',
                'recipients' => is_numeric($context['recipients'] ?? null) ? (int) $context['recipients'] : 0,
            ]);
            $ai->recordUsage($access['uid'], $mode, $result['usage']['input_tokens'], $result['usage']['output_tokens'], $result['model']);

            return ['text' => $result['text'], 'usage' => $result['usage']];
        }, true);
    }

    // ------------------------------------------------------------------
    // Rechtschreibpruefung
    // ------------------------------------------------------------------

    /**
     * Prueft eine Liste von Woertern und liefert die fehlerhaften zurueck.
     * Die Oberflaeche sendet nur unbekannte Woerter, daher bleibt die Antwort
     * klein; doppelte Woerter werden vorher zusammengefasst.
     */
    public function spellcheck(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $spellcheck = $this->spellcheckFor($access);
            $words = $this->words('words', OrvantaSpellcheckService::MAX_WORDS_PER_REQUEST);
            $misspelled = [];
            foreach ($words as $word) {
                if (!$spellcheck->check($word)) {
                    $misspelled[] = $word;
                }
            }

            return ['available' => $spellcheck->isUsable(), 'misspelled' => $misspelled];
        }, true);
    }

    /**
     * Verbesserungsvorschlaege fuer ein einzelnes Wort (Kontextmenue
     * "Rechtschreibpruefung -> ---Vorschlaege---").
     */
    public function spellcheckSuggest(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $spellcheck = $this->spellcheckFor($access);
            $word = $this->str('word');
            if (mb_strlen($word, 'UTF-8') > OrvantaSpellcheckService::MAX_WORD_LENGTH) {
                throw new OrvantaException('Das Wort ist zu lang.', 422);
            }

            return ['available' => $spellcheck->isUsable(), 'suggestions' => $spellcheck->suggest($word)];
        }, true);
    }

    /**
     * Persoenliches Woerterbuch des Benutzers (Einstellungen).
     */
    public function spellcheckWords(Request $request): Response
    {
        return $this->handle($request, static fn (array $access): array => [
            'words' => Container::orvantaSpellcheckUserWords()->words((string) $access['uid']),
        ]);
    }

    /**
     * Wort ins persoenliche Woerterbuch aufnehmen (Kontextmenue "Zum
     * Woerterbuch hinzufuegen").
     */
    public function spellcheckAddWord(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            return ['words' => Container::orvantaSpellcheckUserWords()->add((string) $access['uid'], $this->str('word'))];
        }, true);
    }

    public function spellcheckRemoveWord(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            return ['words' => Container::orvantaSpellcheckUserWords()->remove((string) $access['uid'], $this->str('word'))];
        }, true);
    }

    /**
     * Pruefung mit den persoenlichen Woertern des Benutzers.
     *
     * @param array<string,mixed> $access
     */
    private function spellcheckFor(array $access): OrvantaSpellcheckService
    {
        $spellcheck = Container::orvantaSpellcheck();
        if (!$spellcheck->isAvailable()) {
            return $spellcheck;
        }

        return $spellcheck->withUserWords(Container::orvantaSpellcheckUserWords()->wordsForCheck((string) $access['uid']));
    }

    // ------------------------------------------------------------------
    // Proxy-Postfach: Kennwort durch den Benutzer
    // ------------------------------------------------------------------

    /**
     * Aktuelles Kennwort des zugeordneten Proxy-Postfachs uebernehmen, wenn
     * der Mailserver das hinterlegte ablehnt (Overlay in orvanta.js). Das
     * Kennwort wird vor dem Speichern gegen den Mailserver geprueft und
     * ersetzt bei Erfolg den vom Admin eingetragenen Wert. Fehlversuche
     * werden je Sitzung und Postfach begrenzt.
     */
    public function mailPassword(Request $request): Response
    {
        return $this->handle($request, function (array $access): array {
            $route = $access['route'];
            if (!$route->isProxy()) {
                throw new OrvantaException('Für Ihr Postfach kann hier kein Kennwort hinterlegt werden.', 409);
            }
            $key = 'orvanta_mail_password_' . $route->mailboxId;
            $state = Session::get($key);
            $state = is_array($state) ? $state : ['attempts' => 0, 'locked_until' => 0];
            $wait = (int) ($state['locked_until'] ?? 0) - time();
            if ($wait > 0) {
                throw new OrvantaException('Zu viele Fehlversuche. Bitte in ' . (int) ceil($wait / 60) . ' Minute(n) erneut versuchen.', 429);
            }
            try {
                Container::mailProxy()->updateUserPassword($route, $this->str('password'));
            } catch (OrvantaException $exception) {
                if ($exception->status() === 422) {
                    $attempts = (int) ($state['attempts'] ?? 0) + 1;
                    Session::put($key, $attempts >= self::PASSWORD_ATTEMPTS
                        ? ['attempts' => 0, 'locked_until' => time() + self::PASSWORD_LOCK_SECONDS]
                        : ['attempts' => $attempts, 'locked_until' => 0]);
                }
                throw $exception;
            }
            Session::forget($key);

            return ['ok' => true, 'message' => 'Das Kennwort wurde übernommen.'];
        }, true);
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    /**
     * Mail-Backend des Benutzers (Exchange oder SMTP-/IMAP-Proxy).
     *
     * @param array<string,mixed> $access
     */
    private function mail(array $access): OrvantaMailBackendInterface
    {
        return $access['backend'];
    }

    /**
     * @param array<string,mixed> $access
     */
    private function can(array $access, string $capability): bool
    {
        return ($this->mail($access)->capabilities()[$capability] ?? false) === true;
    }

    /**
     * Exchange-Funktionen (Kalender, Kontakte, Aufgaben, Notizen) nur fuer
     * Exchange-Postfaecher; fuer Proxy-Postfaecher erfolgt kein EWS-Aufruf.
     *
     * @param array<string,mixed> $access
     */
    private function exchange(array $access, string $capability): OrvantaExchangeService
    {
        $backend = $this->mail($access);
        if (!$backend instanceof OrvantaExchangeService || !$this->can($access, $capability)) {
            throw new OrvantaException('Diese Funktion steht für Ihr Postfach (IMAP/SMTP) nicht zur Verfügung.', 409);
        }

        return $backend;
    }

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
                    // Der Benutzer ist (per Windows-Anmeldung) weiterhin
                    // berechtigt, nur das Token der Seite ist veraltet: neues
                    // Token mitliefern, der Client wiederholt einmal.
                    return Response::json([
                        'error' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.',
                        'code' => 'csrf',
                        'csrf' => Csrf::token(),
                    ], 419)->withHeader('Cache-Control', 'no-store');
                }
            }

            return Response::json($action($access))->withHeader('Vary', 'Cookie');
        } catch (OrvantaException $exception) {
            $payload = ['error' => $exception->getMessage()];
            if ($exception->reason() !== '') {
                $payload['code'] = $exception->reason();
            }

            return Response::json($payload, $exception->status());
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

    private function deleteItems(Request $request, string $message, string $capability): Response
    {
        return $this->handle($request, function (array $access) use ($message, $capability): array {
            $this->exchange($access, $capability)->delete($access['impersonate'], $this->ids(), false);

            return ['ok' => true, 'message' => $message];
        }, true);
    }

    /**
     * @param array{uid:string,impersonate:string} $access
     */
    private function resync(array $access): void
    {
        if (!$this->can($access, OrvantaMailBackendInterface::CAPABILITY_REMINDERS)) {
            return;
        }
        Container::orvantaNotifications()->sync($access['uid'], $access['impersonate']);
        Session::put('orvanta_sync_' . $access['uid'], time());
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * Fuegt die per AD-Gruppe zugeordnete Signatur serverseitig an – der
     * Benutzer kann sie im Editor weder entfernen noch veraendern.
     *
     * @param array<string,mixed> $mail
     * @param array{user:array<string,mixed>,uid:string,impersonate:string} $access
     * @return array<string,mixed>
     */
    private function withSignature(array $mail, array $access): array
    {
        if (($mail['html'] ?? true) !== true) {
            return $mail;
        }
        $signature = Container::orvantaSignatures()->forUser($access['user']);
        $mail['body'] = OrvantaSignatureService::append((string) ($mail['body'] ?? ''), $signature['html'] ?? '');

        return $mail;
    }

    /**
     * Merkt sich die Empfaenger (mit Anzeigenamen aus dem Frontend) nach
     * erfolgreichem Versand; Fehler der Ablage bleiben ohne Auswirkung.
     *
     * @param array{uid:string} $access
     */
    private function rememberRecipients(array $access): void
    {
        $recipients = [];
        foreach (['to', 'cc', 'bcc'] as $key) {
            $value = $this->body[$key] ?? [];
            foreach (is_string($value) ? (preg_split('/[;,\s]+/', $value) ?: []) : (array) $value as $recipient) {
                $recipients[] = $recipient;
            }
        }
        try {
            Container::orvantaRecipients()->remember($access['uid'], $recipients);
        } catch (Throwable $exception) {
            app_logger()->warning('Orvanta: Empfänger-Verlauf nicht aktualisiert.', ['error' => $exception->getMessage()]);
        }
    }

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

    /**
     * Wortliste aus dem Anfragetext: bereinigt, entdoppelt und begrenzt.
     *
     * @return list<string>
     */
    private function words(string $key, int $limit): array
    {
        $value = $this->body[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $seen = [];
        foreach ($value as $word) {
            if (!is_scalar($word)) {
                continue;
            }
            $word = trim((string) $word);
            if ($word === '' || mb_strlen($word, 'UTF-8') > OrvantaSpellcheckService::MAX_WORD_LENGTH) {
                continue;
            }
            $seen[$word] = true;
            if (count($seen) >= $limit) {
                break;
            }
        }

        return array_map('strval', array_keys($seen));
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
