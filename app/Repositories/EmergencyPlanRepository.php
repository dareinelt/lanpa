<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\HttpException;
use PDO;
use PDOException;
use Throwable;

final class EmergencyPlanRepository extends Repository
{
    public function plans(bool $publishedOnly = false): array
    {
        $rows = $this->pdo->query('SELECT id, title, published, ' . ($publishedOnly ? 'published_revision AS revision, published_definition' : 'revision') . ', review_state, published_revision, updated_by, updated_at FROM emergency_plans'
            . ($publishedOnly ? ' WHERE published = 1' : '') . ' ORDER BY title, id')->fetchAll(PDO::FETCH_ASSOC);
        if ($publishedOnly) {
            foreach ($rows as &$row) {
                $row['title'] = json_decode($row['published_definition'], true, 64, JSON_THROW_ON_ERROR)['title'];
                unset($row['published_definition']);
            }
            unset($row);
        }

        return $rows;
    }

    public function plan(int $id): array
    {
        $row = $this->one('SELECT * FROM emergency_plans WHERE id = ?', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'Notfallplan nicht gefunden.');
        }
        $row['definition'] = json_decode($row['definition'], true, 64, JSON_THROW_ON_ERROR);
        $row['contributors'] = json_decode($row['contributors'] ?? '[]', true, 64, JSON_THROW_ON_ERROR);
        $row['published_definition'] = $row['published_definition'] === null ? null : json_decode($row['published_definition'], true, 64, JSON_THROW_ON_ERROR);

        return $row;
    }

    public function publishedPlan(int $id): array
    {
        $row = $this->plan($id);
        if (!(bool) $row['published'] || $row['published_definition'] === null) {
            throw new HttpException(404, 'Dieser Notfallplan ist nicht veröffentlicht.');
        }
        $row['definition'] = $row['published_definition'];
        $row['title'] = $row['definition']['title'];
        $row['revision'] = $row['published_revision'];

        return $row;
    }

    public function savePlan(int $id, int $revision, array $definition, string $actor): int
    {
        return $this->transaction(function () use ($id, $revision, $definition, $actor): int {
            $contributors = [$actor];
            if ($id !== 0) {
                $old = $this->plan($id);
                if ($old['review_state'] !== 'approved') {
                    $contributors = array_values(array_unique([...$old['contributors'], $actor]));
                }
            }
            $values = [$definition['title'], self::json($definition), $actor, gmdate('Y-m-d H:i:s'), self::json($contributors)];
            if ($id === 0) {
                $this->execute('INSERT INTO emergency_plans (title, definition, updated_by, updated_at, contributors) VALUES (?, ?, ?, ?, ?)', $values);
                $id = (int) $this->pdo->lastInsertId();
                $revision = 0;
            } else {
                $count = $this->execute("UPDATE emergency_plans SET title = ?, definition = ?, updated_by = ?, updated_at = ?, contributors = ?, revision = revision + 1, review_state = 'draft', submitted_by = NULL, submitted_at = NULL WHERE id = ? AND revision = ?", [...$values, $id, $revision]);
                $this->assertChanged($count);
            }
            $this->reviewLog($id, $revision + 1, $actor, 'saved', 'Entwurf gespeichert; eine offene Freigabeanforderung ist damit ungültig.');

            return $id;
        });
    }

    /**
     * Legt importierte Pläne als neue, unveröffentlichte Entwürfe an (eine Transaktion).
     *
     * @param list<array<string,mixed>> $definitions geprüfte Definitionen
     * @return list<int>
     */
    public function importPlans(array $definitions, string $actor): array
    {
        return $this->transaction(function () use ($definitions, $actor): array {
            $ids = [];
            foreach ($definitions as $definition) {
                $this->execute('INSERT INTO emergency_plans (title, definition, updated_by, updated_at, contributors) VALUES (?, ?, ?, ?, ?)',
                    [$definition['title'], self::json($definition), $actor, gmdate('Y-m-d H:i:s'), self::json([$actor])]);
                $id = (int) $this->pdo->lastInsertId();
                $this->reviewLog($id, 1, $actor, 'imported', 'Aus Exportdatei importiert. Veröffentlichung benötigt eine Vier-Augen-Freigabe.');
                $ids[] = $id;
            }

            return $ids;
        });
    }

    public function submit(int $id, int $revision, string $actor): void
    {
        $this->transaction(function () use ($id, $revision, $actor): void {
            $count = $this->execute("UPDATE emergency_plans SET review_state = 'pending', submitted_by = ?, submitted_at = ? WHERE id = ? AND revision = ? AND review_state IN ('draft', 'rejected')",
                [$actor, gmdate('Y-m-d H:i:s'), $id, $revision]);
            $this->assertChanged($count);
            $this->reviewLog($id, $revision, $actor, 'submitted', 'Freigabe durch eine zweite, unbeteiligte Person angefordert.');
        });
    }

    public function review(int $id, int $revision, string $actor, bool $approve, string $comment): void
    {
        $comment = \App\Services\EmergencyPlanDefinition::text($comment, 2000, 'Freigabekommentar', !$approve);
        $this->transaction(function () use ($id, $revision, $actor, $approve, $comment): void {
            $plan = $this->plan($id);
            if (in_array($actor, $plan['contributors'], true) || $actor === $plan['submitted_by']) {
                throw new HttpException(403, 'Eigene oder selbst mitbearbeitete Entwürfe dürfen nicht freigegeben oder abgelehnt werden. Eine zweite, unbeteiligte Person ist erforderlich.');
            }
            $definition = $plan['definition'];
            $definition['publication'] = [
                'authors' => $plan['contributors'],
                'approved_by' => $actor,
                'approved_at' => gmdate('Y-m-d H:i:s'),
                'revision' => $revision,
            ];
            $count = $this->execute("UPDATE emergency_plans SET review_state = ?"
                . ($approve ? ', published = 1, published_definition = ?, published_revision = revision' : '')
                . " WHERE id = ? AND revision = ? AND review_state = 'pending'",
                $approve ? ['approved', self::json($definition), $id, $revision] : ['rejected', $id, $revision]);
            $this->assertChanged($count);
            $this->reviewLog($id, $revision, $actor, $approve ? 'approved' : 'rejected', $comment);
        });
    }

    public function withdraw(int $id, int $revision, string $actor): void
    {
        $this->transaction(function () use ($id, $revision, $actor): void {
            $count = $this->execute("UPDATE emergency_plans SET published = 0, revision = revision + 1, review_state = 'draft', submitted_by = NULL, submitted_at = NULL WHERE id = ? AND revision = ?",
                [$id, $revision]);
            $this->assertChanged($count);
            $this->reviewLog($id, $revision + 1, $actor, 'withdrawn', 'Veröffentlichung zurückgezogen. Erneute Veröffentlichung benötigt eine neue Freigabe.');
        });
    }

    public function reviews(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT revision, actor, action, comment, created_at FROM emergency_plan_reviews WHERE plan_id = ? ORDER BY id');
        $statement->execute([$id]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function reviewLog(int $id, int $revision, string $actor, string $action, string $comment): void
    {
        $this->execute('INSERT INTO emergency_plan_reviews (plan_id, revision, actor, action, comment, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $revision, $actor, $action, $comment, gmdate('Y-m-d H:i:s')]);
    }

    public function events(?string $actor, string $status, string $from, string $to, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        foreach (['actor = ?' => $actor, 'status = ?' => $status === '' ? null : $status,
            'started_at >= ?' => $from === '' ? null : $from . ' 00:00:00',
            'started_at <= ?' => $to === '' ? null : $to . ' 23:59:59'] as $clause => $value) {
            if ($value !== null) {
                $where[] = $clause;
                $params[] = $value;
            }
        }
        $condition = implode(' AND ', $where);
        $count = $this->one('SELECT COUNT(*) AS total FROM emergency_events WHERE ' . $condition, $params);
        $statement = $this->pdo->prepare('SELECT id, title, actor, status, started_at, closed_at FROM emergency_events WHERE ' . $condition . ' ORDER BY id DESC LIMIT 30 OFFSET ' . (max(0, $page - 1) * 30));
        $statement->execute($params);

        return ['items' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count['total']];
    }

    public function event(int $id): array
    {
        $row = $this->one('SELECT * FROM emergency_events WHERE id = ?', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'Ereignis nicht gefunden.');
        }
        foreach (['snapshot', 'state'] as $field) {
            $row[$field] = json_decode($row[$field], true, 64, JSON_THROW_ON_ERROR);
        }
        $row['coordination'] = json_decode($row['coordination'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $row['sms'] = [];
        $statement = $this->pdo->prepare('SELECT node_id, status, message FROM emergency_sms WHERE event_id = ?');
        $statement->execute([$id]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $sms) {
            $row['sms'][$sms['node_id']] = $sms;
        }

        return $row;
    }

    public function existingRequest(string $key, string $actor): ?int
    {
        $row = $this->one('SELECT id FROM emergency_events WHERE request_key = ? AND actor = ?', [$key, $actor]);

        return $row === null ? null : (int) $row['id'];
    }

    public function dashboardToken(): string
    {
        $row = $this->pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(revision), 0) AS revision FROM emergency_events')->fetch(PDO::FETCH_ASSOC);

        return $row['total'] . ':' . $row['revision'];
    }

    public function dashboard(int $id, string $status, int $page): array
    {
        return $this->transaction(function () use ($id, $status, $page): array {
            $token = $this->dashboardToken();
            $events = $this->events(null, $status, '', '', $page);
            // Unbekannte oder gelöschte IDs (z. B. alte Lesezeichen) fallen auf das neueste Ereignis zurück.
            if ($id > 0 && $this->one('SELECT id FROM emergency_events WHERE id = ?', [$id]) === null) {
                $id = 0;
            }
            $id = $id ?: (int) ($events['items'][0]['id'] ?? 0);
            $event = $id > 0 ? $this->event($id) : null;

            return [
                'token' => $token, 'events' => $events, 'event' => $event,
                'ready' => $event === null ? [] : \App\Services\EmergencyPlanDefinition::readiness($event['snapshot'], $event['state']),
                'logs' => $id > 0 ? $this->journal($id) : [],
                'notifications' => $id > 0 ? $this->notifications($id) : [],
                'serverTime' => gmdate('c'),
            ];
        });
    }

    public function journal(int $id, int $before = 0, string $node = ''): array
    {
        $sql = 'SELECT * FROM emergency_log WHERE event_id = ?';
        $params = [$id];
        if ($before > 0) {
            $sql .= ' AND id < ?';
            $params[] = $before;
        }
        if ($node !== '') {
            $sql .= ' AND node_id = ?';
            $params[] = $node;
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY id DESC LIMIT 100');
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function coordinate(array $event, array $change, string $actor): void
    {
        $this->transaction(function () use ($event, $change, $actor): void {
            $count = $this->execute("UPDATE emergency_events SET coordination = ?, revision = revision + 1 WHERE id = ? AND revision = ? AND status = 'active'",
                [self::json($change['coordination']), $event['id'], $event['revision']]);
            $this->assertChanged($count);
            $this->append((int) $event['id'], $change['node'], $actor, $change['action'], $change['message']);
        });
    }

    public function start(array $plan, string $actor, string $key, array $recipients, int $missing, string $baseUrl): int
    {
        return $this->transaction(function () use ($plan, $actor, $key, $recipients, $missing, $baseUrl): int {
            // Lock/compare the plan before storing its immutable execution snapshot.
            $this->execute('UPDATE emergency_plans SET revision = revision WHERE id = ? AND published_revision = ? AND published = 1', [$plan['id'], $plan['revision']]);
            // MySQL reports unchanged rows as zero, so read under the acquired row lock.
            $current = $this->publishedPlan((int) $plan['id']);
            if ((int) $current['revision'] !== (int) $plan['revision'] || !(bool) $current['published']) {
                throw new HttpException(409, 'Der Plan wurde geändert. Bitte neu öffnen und prüfen.');
            }
            $this->execute('INSERT INTO emergency_events (plan_id, title, actor, request_key, snapshot, state, started_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$plan['id'], $plan['title'], $actor, $key, self::json($plan['definition']), '{}', gmdate('Y-m-d H:i:s')]);
            $id = (int) $this->pdo->lastInsertId();
            $this->append($id, '', $actor, 'started', 'Ereignis gestartet; Planversion ' . $plan['revision'] . '.');
            foreach ($recipients as $recipient) {
                $this->execute('INSERT INTO mail_outbox (event_id, recipient, subject, body, available_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $recipient, 'Notfallplan ausgelöst: ' . $plan['title'],
                        "Ein Notfallereignis wurde ausgelöst.\n\nPlan: " . $plan['title'] . "\nEreignis: #" . $id
                        . "\nAusgelöst durch: " . $actor . "\nZeit (UTC): " . gmdate('Y-m-d H:i:s')
                        . "\n\nAktueller Stand (KAEP-Anmeldung erforderlich):\n" . rtrim($baseUrl, '/') . '/admin/notfallplan/ereignis?id=' . $id
                        . "\n\nDies ist eine Benachrichtigung, keine Bestätigung einer Alarmzustellung.",
                        time(), gmdate('Y-m-d H:i:s')]);
            }
            $this->append($id, '', 'system', 'email_queued', count($recipients) . ' E-Mail-Benachrichtigungen eingeplant; '
                . $missing . ' KAEP-Mitglieder ohne gültige E-Mail-Adresse.'
                . ($recipients === [] ? ' WARNUNG: Keine Empfänger. KAEP-Team anderweitig informieren!' : ''));

            return $id;
        });
    }

    public function change(array $event, array $state, string $actor, string $node, string $action, string $message, bool $close = false, bool $sms = false, array $checkChanges = []): void
    {
        $this->transaction(function () use ($event, $state, $actor, $node, $action, $message, $close, $sms, $checkChanges): void {
            $count = $this->execute('UPDATE emergency_events SET state = ?, revision = revision + 1, status = ?, closed_at = ? WHERE id = ? AND revision = ? AND status = ?',
                [self::json($state), $close ? 'closed' : 'active', $close ? gmdate('Y-m-d H:i:s') : null, $event['id'], $event['revision'], 'active']);
            $this->assertChanged($count);
            if ($sms) {
                $this->execute('INSERT INTO emergency_sms (event_id, node_id, status, message) VALUES (?, ?, ?, ?)',
                    [$event['id'], $node, 'pending', 'Versand begonnen; Ergebnis noch nicht bestätigt. Nicht erneut auslösen.']);
            }
            $this->append((int) $event['id'], $node, $actor, $action, $message);
            foreach ($checkChanges as $change) {
                $this->append((int) $event['id'], $node, $actor, $change['action'], $change['message']);
            }
        });
    }

    public function finishSms(int $eventId, string $node, string $actor, string $status, string $message): void
    {
        $this->transaction(function () use ($eventId, $node, $actor, $status, $message): void {
            $this->execute('UPDATE emergency_events SET revision = revision + 1 WHERE id = ?', [$eventId]);
            $this->execute('UPDATE emergency_sms SET status = ?, message = ? WHERE event_id = ? AND node_id = ? AND status = ?',
                [$status, $message, $eventId, $node, 'pending']);
            $this->append($eventId, $node, $actor, 'sms_' . $status, $message);
        });
    }

    public function logs(int $eventId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM emergency_log WHERE event_id = ? ORDER BY id');
        $statement->execute([$eventId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function notificationLog(int $eventId, string $action, string $message): void
    {
        $this->transaction(function () use ($eventId, $action, $message): void {
            $this->execute('UPDATE emergency_events SET revision = revision + 1 WHERE id = ?', [$eventId]);
            $this->append($eventId, '', 'system', $action, $message);
        });
    }

    public function notifications(int $eventId): array
    {
        $statement = $this->pdo->prepare('SELECT recipient, status, attempts, message FROM mail_outbox WHERE event_id = ? ORDER BY id');
        $statement->execute([$eventId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reservePasswordAttempt(string $actor, int $now): bool
    {
        try {
            $this->execute('INSERT INTO emergency_password_attempts (actor, window_start, attempts) VALUES (?, ?, 0)', [$actor, $now]);
        } catch (PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
        }
        $this->execute('UPDATE emergency_password_attempts SET window_start = ?, attempts = 0 WHERE actor = ? AND window_start <= ?', [$now, $actor, $now - 300]);

        return $this->execute('UPDATE emergency_password_attempts SET attempts = attempts + 1 WHERE actor = ? AND attempts < 5', [$actor]) === 1;
    }

    private function append(int $event, string $node, string $actor, string $action, string $message): void
    {
        $this->execute('INSERT INTO emergency_log (event_id, node_id, actor, action, message, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$event, $node, $actor, $action, $message, gmdate('Y-m-d H:i:s')]);
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function execute(string $sql, array $params): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function assertChanged(int $count): void
    {
        if ($count !== 1) {
            throw new HttpException(409, 'Der Stand wurde zwischenzeitlich geändert oder abgeschlossen. Bitte aktualisieren; Ihre Eingabe wurde nicht gespeichert.');
        }
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
