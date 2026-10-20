<?php

declare(strict_types=1);

namespace App\Repositories;

final class AdSyncRunRepository extends BaseRepository
{
    public function start(string $triggeredBy): int
    {
        return $this->insertRow('ad_sync_runs', ['triggered_by' => mb_substr($triggeredBy, 0, 120), 'status' => 'running']);
    }

    /** @param array<string,mixed> $data */
    public function finish(int $id, array $data): void
    {
        $this->updateRow('ad_sync_runs', $id, array_merge($data, ['finished_at' => gmdate('Y-m-d H:i:s')]));
    }

    /** @return array<int,array<string,mixed>> */
    public function latest(int $limit = 20): array
    {
        return $this->fetchAll("SELECT * FROM ad_sync_runs ORDER BY started_at DESC, id DESC LIMIT {$limit}");
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ad_sync_runs WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function lastSuccessful(): ?array
    {
        return $this->fetchOne("SELECT * FROM ad_sync_runs WHERE status = 'success' ORDER BY finished_at DESC LIMIT 1");
    }

    /** @return array<string,mixed>|null */
    public function running(): ?array
    {
        return $this->fetchOne("SELECT * FROM ad_sync_runs WHERE status = 'running' ORDER BY started_at DESC LIMIT 1");
    }

    /** Markiert hängende Läufe (z. B. nach Absturz) als fehlgeschlagen. */
    public function failStale(int $maxMinutes = 60): int
    {
        return $this->execute(
            "UPDATE ad_sync_runs SET status = 'failed', finished_at = NOW(), message = 'Abgebrochen (Zeitüberschreitung)'
             WHERE status = 'running' AND started_at < NOW() - INTERVAL :m MINUTE",
            ['m' => $maxMinutes]
        );
    }
}
