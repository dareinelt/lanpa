<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\OrvantaMailBackendInterface;
use App\Core\Logger;
use App\Repositories\OrvantaMailOutboxRepository;
use Closure;
use Throwable;

/**
 * Warteschlange fuer Orvanta-Mails an Exchange-Postfaecher.
 *
 * Eine kurzfristige Stoerung einzelner Exchange-Server (oder der ganzen DAG)
 * darf das Senden nicht verhindern: Der Versand laeuft zuerst direkt, bei
 * einem Ausfall wird der vollstaendige Auftrag in orvanta_mail_outbox gelegt
 * und vom Dienst scripts/mail_worker.php nachgeholt. Das gilt nur fuer
 * Fehler, bei denen die Nachricht den Server nachweislich nicht erreicht hat
 * (Verbindungsfehler, Zeitueberschreitung vor dem Senden, HTTP 5xx) - sonst
 * koennte eine bereits zugestellte Mail doppelt ankommen.
 *
 * Fachliche Fehler (unbekannter Empfaenger, verweigerte Impersonation,
 * abgelehnte Anmeldung) werden nicht eingereiht: Sie wuerden bei jedem
 * Versuch erneut scheitern.
 */
final class OrvantaMailQueueService
{
    /** Hoechstzahl der Zustellversuche je Auftrag. */
    public const MAX_ATTEMPTS = 10;

    /** Meldung an die Oberflaeche, wenn ein Auftrag eingereiht wurde. */
    public const QUEUED_MESSAGE = 'Exchange ist derzeit nicht erreichbar. Die Nachricht wurde in die Warteschlange gestellt und wird automatisch gesendet, sobald Exchange wieder verfügbar ist.';

    /** Hoechstzahl der Auftraege, die ein Lauf sofort zustellt. */
    private const MAX_DISPATCH = 5;

    /** Nach dieser Zeit gilt ein laufender Auftrag als abgebrochen (Sekunden). */
    private const CLAIM_TIMEOUT = 900;

    /** Aufbewahrung zugestellter Auftraege (Sekunden). */
    private const SENT_RETENTION = 604800;

    /** Aufbewahrung endgueltig gescheiterter Auftraege (Sekunden). */
    private const FAILED_RETENTION = 2592000;

    /** @var Closure(string,string):OrvantaMailBackendInterface */
    private readonly Closure $backendFor;

    /**
     * @param Closure(string,string):OrvantaMailBackendInterface $backendFor
     *        liefert das Mail-Backend zu AD-Kennung und Postfachadresse
     */
    public function __construct(
        private readonly OrvantaMailOutboxRepository $repository,
        Closure $backendFor,
        private readonly ?Logger $logger = null
    ) {
        $this->backendFor = $backendFor;
    }

    /**
     * Versendet eine neue Nachricht oder reiht sie bei einem Exchange-Ausfall
     * ein. Die Antwort traegt `queued`, damit die Oberflaeche den Unterschied
     * zwischen „gesendet“ und „in der Warteschlange“ zeigen kann.
     *
     * @param array<string,mixed> $mail
     * @return array<string,mixed>
     */
    public function send(
        OrvantaMailBackendInterface $backend,
        string $uid,
        string $mailbox,
        array $mail,
        string $draftId = '',
        string $changeKey = ''
    ): array {
        try {
            return $backend->send($mailbox, $mail, $draftId, $changeKey) + ['queued' => false];
        } catch (OrvantaException $exception) {
            return $this->enqueue($backend, $uid, $mailbox, $mail, $draftId, $changeKey, $exception);
        }
    }

    /**
     * Antwort oder Weiterleitung mit demselben Verhalten wie send().
     *
     * @param array<string,mixed> $mail
     * @return array<string,mixed>
     */
    public function respond(
        OrvantaMailBackendInterface $backend,
        string $uid,
        string $mailbox,
        string $id,
        string $mode,
        array $mail
    ): array {
        try {
            return $backend->respond($mailbox, $id, $mode, $mail) + ['queued' => false];
        } catch (OrvantaException $exception) {
            // Beide Backends setzen respond() auf send() mit Bezug zur
            // Originalnachricht auf; der Auftrag bleibt damit vollstaendig.
            $payload = ['reference' => ['id' => $id, 'mode' => $mode]] + $mail;

            return $this->enqueue($backend, $uid, $mailbox, $payload, '', '', $exception);
        }
    }

    /**
     * Stellt faellige Auftraege zu. Bricht nie ab: Ein fehlgeschlagener
     * Auftrag bleibt mit Fehlertext und naechstem Versuch stehen.
     *
     * @return int Anzahl der zugestellten Auftraege
     */
    public function dispatch(): int
    {
        $now = self::sql(time());
        $this->repository->recover(self::sql(time() - self::CLAIM_TIMEOUT), $now);
        $this->repository->prune(
            self::sql(time() - self::SENT_RETENTION),
            self::sql(time() - self::FAILED_RETENTION)
        );
        $sent = 0;
        foreach ($this->repository->due($now, self::MAX_ATTEMPTS, self::MAX_DISPATCH) as $row) {
            $id = (int) $row['id'];
            if (!$this->repository->claim($id, (int) $row['attempts'], $now)) {
                continue;
            }
            try {
                $this->deliver($row);
                $this->repository->finish($id, 'sent', null, null, $now, $now);
                $sent++;
            } catch (Throwable $exception) {
                $attempts = (int) $row['attempts'] + 1;
                $transient = self::transient($exception);
                $final = !$transient || $attempts >= self::MAX_ATTEMPTS;
                $this->repository->finish(
                    $id,
                    $final ? 'failed' : 'pending',
                    $exception->getMessage(),
                    $final ? null : self::sql(time() + self::backoff($attempts)),
                    null,
                    self::sql(time())
                );
                $this->logger?->warning('Orvanta: Nachricht aus der Warteschlange konnte nicht gesendet werden.', [
                    'outbox' => $id,
                    'attempts' => $attempts,
                    'final' => $final,
                    'error' => $exception->getMessage(),
                ]);
                if ($transient) {
                    // Exchange antwortet weiterhin nicht; weitere Auftraege
                    // wuerden nur warten. Der naechste Lauf versucht es erneut.
                    break;
                }
            }
        }

        return $sent;
    }

    /**
     * @param array<string,mixed> $mail
     * @return array<string,mixed>
     */
    private function enqueue(
        OrvantaMailBackendInterface $backend,
        string $uid,
        string $mailbox,
        array $mail,
        string $draftId,
        string $changeKey,
        OrvantaException $exception
    ): array {
        if ($exception->reason() !== OrvantaException::EXCHANGE_UNAVAILABLE || $backend->backendName() !== 'exchange') {
            throw $exception;
        }
        $payload = self::encode($mail);
        if ($payload === null) {
            throw $exception;
        }
        $reference = is_array($mail['reference'] ?? null) ? $mail['reference'] : [];
        try {
            $id = $this->repository->enqueue(
                $uid,
                $backend->backendName(),
                $mailbox,
                (string) ($mail['from'] ?? ''),
                (string) ($reference['mode'] ?? 'new'),
                (string) ($mail['subject'] ?? ''),
                array_merge(
                    (array) ($mail['to'] ?? []),
                    (array) ($mail['cc'] ?? []),
                    (array) ($mail['bcc'] ?? [])
                ),
                $payload,
                $draftId,
                $changeKey,
                $exception->getMessage(),
                self::sql(time())
            );
        } catch (Throwable $failure) {
            // Ohne Auftrag in der Datenbank bleibt nur die urspruengliche
            // Fehlermeldung; die Nachricht ist dann nicht gesendet.
            $this->logger?->error('Orvanta: Nachricht konnte nicht in die Warteschlange gestellt werden.', [
                'uid' => $uid,
                'error' => $failure->getMessage(),
            ]);

            throw $exception;
        }
        $this->logger?->warning('Orvanta: Nachricht in die Warteschlange gestellt.', [
            'outbox' => $id,
            'uid' => $uid,
            'kind' => (string) ($reference['mode'] ?? 'new'),
            'error' => $exception->getMessage(),
        ]);

        return ['id' => '', 'queued' => true, 'outbox_id' => $id, 'message' => self::QUEUED_MESSAGE];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function deliver(array $row): void
    {
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            throw new OrvantaException('Der Versandauftrag ist unvollständig.', 500);
        }
        $backend = ($this->backendFor)((string) $row['user_uid'], (string) $row['mailbox']);
        if ($backend->backendName() !== (string) $row['backend']) {
            // Das Postfach wird inzwischen anders gefuehrt; ein stiller
            // Ausweichweg (etwa ueber Exchange) waere falsch.
            throw new OrvantaException('Das Postfach wird nicht mehr über Exchange geführt.', 409);
        }
        $backend->send(
            (string) $row['mailbox'],
            self::decode($payload),
            (string) $row['draft_id'],
            (string) $row['change_key']
        );
    }

    /**
     * Serialisiert den Versandauftrag. Anhaenge sind binaer und damit kein
     * gueltiges UTF-8; sie werden deshalb base64-kodiert abgelegt.
     *
     * @param array<string,mixed> $mail
     */
    private static function encode(array $mail): ?string
    {
        foreach ((array) ($mail['attachments'] ?? []) as $index => $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $mail['attachments'][$index]['content'] = base64_encode((string) ($attachment['content'] ?? ''));
            $mail['attachments'][$index]['encoding'] = 'base64';
        }
        $payload = json_encode($mail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($payload) ? $payload : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function decode(array $payload): array
    {
        foreach ((array) ($payload['attachments'] ?? []) as $index => $attachment) {
            if (!is_array($attachment) || ($attachment['encoding'] ?? '') !== 'base64') {
                continue;
            }
            $content = base64_decode((string) ($attachment['content'] ?? ''), true);
            if ($content === false) {
                throw new OrvantaException('Der Anhang im Versandauftrag ist unbrauchbar.', 500);
            }
            $payload['attachments'][$index]['content'] = $content;
            unset($payload['attachments'][$index]['encoding']);
        }

        return $payload;
    }

    /**
     * Ist der Fehler kurzfristig? Nur dann lohnt ein weiterer Versuch.
     */
    private static function transient(Throwable $exception): bool
    {
        return $exception instanceof OrvantaException
            && $exception->reason() === OrvantaException::EXCHANGE_UNAVAILABLE;
    }

    /** Wartezeit bis zum naechsten Versuch: 30 s, 60 s, 120 s … hoechstens 15 min. */
    private static function backoff(int $attempts): int
    {
        return (int) min(900, 30 * (2 ** max(0, $attempts - 1)));
    }

    private static function sql(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
