<?php

declare(strict_types=1);

namespace App\Repositories;

final class ClickRepository extends Repository
{
    public function record(int $navigationId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO click_events (navigation_id, clicked_at) VALUES (:navigation_id, NOW())'
        );
        $statement->execute(['navigation_id' => $navigationId]);
    }

    /**
     * Aggregierte Klicks pro Tag und Navigationselement.
     *
     * @return list<array{day:string,navigation_id:int,clicks:int}>
     */
    public function aggregateByDay(string $fromDate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DATE(clicked_at) AS day, navigation_id, COUNT(*) AS clicks
               FROM click_events
              WHERE clicked_at >= :from_date
              GROUP BY DATE(clicked_at), navigation_id
              ORDER BY day ASC'
        );
        $statement->execute(['from_date' => $fromDate . ' 00:00:00']);

        $rows = [];
        /** @var array{day:string,navigation_id:?int,clicks:int|string} $row */
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'day' => (string) $row['day'],
                'navigation_id' => (int) ($row['navigation_id'] ?? 0),
                'clicks' => (int) $row['clicks'],
            ];
        }

        return $rows;
    }

    public function countSince(string $fromDate): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM click_events WHERE clicked_at >= :from_date');
        $statement->execute(['from_date' => $fromDate . ' 00:00:00']);
        $value = $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function countTotal(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM click_events')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Loescht Klickdaten aelter als X Tage (Datensparsamkeit).
     */
    public function purgeOlderThan(int $days): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM click_events WHERE clicked_at < (NOW() - INTERVAL :days DAY)'
        );
        $statement->bindValue('days', $days, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }
}
