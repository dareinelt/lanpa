<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EmergencyPlanRepository;
use PDO;
use RuntimeException;

final class MailQueueService
{
    public function __construct(private readonly PDO $pdo, private readonly SmtpService $smtp, private readonly EmergencyPlanRepository $events)
    {
    }

    public function enqueueTest(string $recipient): void
    {
        if (!SmtpService::validEmail($recipient)) {
            throw new \App\Exceptions\ValidationException(['recipient' => 'Bitte eine gültige Testadresse angeben.']);
        }
        $this->pdo->prepare('INSERT INTO mail_outbox (recipient, subject, body, available_at, created_at) VALUES (?, ?, ?, ?, ?)')->execute([
            $recipient, 'Intranet SMTP-Test', 'Testnachricht. Dies ist keine Notfallalarmierung.', time(), gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function recent(): array
    {
        return $this->pdo->query('SELECT id, event_id, recipient, status, attempts, message, created_at FROM mail_outbox ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function run(int $limit = 20): int
    {
        // Auch nach einem Prozessabbruch bleiben Nachrichten sichtbar und werden begrenzt erneut versucht.
        $now = time();
        $this->pdo->prepare("UPDATE mail_outbox SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'queued' END, message = 'Versand unterbrochen; Zustellung unklar, mögliche Doppelzustellung.', available_at = ? WHERE status = 'sending' AND available_at < ?")
            ->execute([$now, $now - 900]);
        $statement = $this->pdo->prepare("SELECT * FROM mail_outbox WHERE status = 'queued' AND available_at <= ? ORDER BY id LIMIT " . max(1, min(100, $limit)));
        $statement->execute([$now]);
        $processed = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $mail) {
            $claim = $this->pdo->prepare("UPDATE mail_outbox SET status = 'sending', attempts = attempts + 1, available_at = ? WHERE id = ? AND status = 'queued' AND attempts = ?");
            $claim->execute([$now, $mail['id'], $mail['attempts']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $attempt = (int) $mail['attempts'] + 1;
            try {
                $this->smtp->send($mail['recipient'], $mail['subject'], $mail['body'], 'lanpa-mail-' . $mail['id']);
                $status = 'sent';
                $message = 'Vom SMTP-Server angenommen; keine Lesebestätigung.';
            } catch (RuntimeException $exception) {
                $status = $attempt >= 3 ? 'failed' : 'queued';
                $message = mb_substr($exception->getMessage(), 0, 255);
                app_logger()->warning('E-Mail-Versand fehlgeschlagen.', ['mail_id' => $mail['id'], 'attempt' => $attempt, 'error' => $message]);
            }
            $this->pdo->prepare('UPDATE mail_outbox SET status = ?, message = ?, available_at = ? WHERE id = ?')
                ->execute([$status, $message, time() + $attempt * 60, $mail['id']]);
            if ($mail['event_id'] !== null) {
                $this->events->notificationLog((int) $mail['event_id'], 'email_' . $status, $mail['recipient'] . ': ' . $message . ' (Versuch ' . $attempt . '/3)');
            }
            $processed++;
        }

        return $processed;
    }
}
