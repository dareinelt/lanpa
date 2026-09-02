<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LdapClientInterface;
use App\Contracts\PhonebookStoreInterface;
use App\Contracts\SyncLogStoreInterface;
use App\Core\Logger;
use Throwable;

/**
 * Synchronisiert das Active Directory in den lokalen MySQL-Datenbestand.
 *
 * Grundsatz: Bei einem Fehler (AD nicht erreichbar, leeres Ergebnis) bleibt der
 * letzte gueltige Datenbestand unveraendert erhalten.
 */
final class AdSyncService
{
    public function __construct(
        private readonly LdapClientInterface $client,
        private readonly PhonebookStoreInterface $store,
        private readonly SyncLogStoreInterface $syncLog,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array{status:string,processed:int,deactivated:int,message:?string}
     */
    public function run(): array
    {
        $runId = $this->syncLog->start();
        $syncedAt = date('Y-m-d H:i:s');

        try {
            $users = $this->client->fetchUsers();
        } catch (Throwable $exception) {
            $message = 'AD-Synchronisation fehlgeschlagen: ' . $exception->getMessage();
            $this->logger->error($message);
            $this->syncLog->finish($runId, 'error', 0, 0, $exception->getMessage());

            return ['status' => 'error', 'processed' => 0, 'deactivated' => 0, 'message' => $message];
        }

        if ($users === []) {
            $message = 'AD-Synchronisation lieferte keine Datensätze – bestehender Datenbestand bleibt unverändert.';
            $this->logger->warning($message);
            $this->syncLog->finish($runId, 'error', 0, 0, 'Keine Datensätze geliefert.');

            return ['status' => 'error', 'processed' => 0, 'deactivated' => 0, 'message' => $message];
        }

        try {
            $this->store->beginTransaction();

            $processed = 0;
            foreach ($users as $user) {
                if (!isset($user['external_id']) || (string) $user['external_id'] === '') {
                    continue;
                }

                $this->store->upsert($user, $syncedAt);
                $processed++;
            }

            $deactivated = $processed > 0 ? $this->store->deactivateStale($syncedAt) : 0;
            $this->store->commit();
        } catch (Throwable $exception) {
            $this->store->rollBack();
            $message = 'AD-Synchronisation konnte nicht gespeichert werden: ' . $exception->getMessage();
            $this->logger->error($message);
            $this->syncLog->finish($runId, 'error', 0, 0, $exception->getMessage());

            return ['status' => 'error', 'processed' => 0, 'deactivated' => 0, 'message' => $message];
        }

        $this->logger->info('AD-Synchronisation erfolgreich.', [
            'processed' => $processed,
            'deactivated' => $deactivated,
        ]);
        $this->syncLog->finish($runId, 'success', $processed, $deactivated, null);

        return [
            'status' => 'success',
            'processed' => $processed,
            'deactivated' => $deactivated,
            'message' => null,
        ];
    }
}
