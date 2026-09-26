<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AdGroupStoreInterface;
use App\Contracts\LdapClientInterface;
use App\Contracts\PhonebookStoreInterface;
use App\Contracts\SyncLogStoreInterface;
use App\Core\Logger;
use Throwable;

/**
 * Synchronisiert ein oder mehrere Active Directorys (Identitaetsquellen) in den
 * lokalen MySQL-Datenbestand.
 *
 * Grundsatz: Bei einem Fehler (AD nicht erreichbar, leeres Ergebnis) bleibt der
 * letzte gueltige Datenbestand der betroffenen Quelle unveraendert erhalten.
 * Die Quellen werden unabhaengig voneinander abgeglichen – faellt eine
 * Zweigstelle aus, werden die uebrigen trotzdem aktualisiert.
 */
final class AdSyncService
{
    /** @var list<array{id:int,label:string,client:LdapClientInterface}> */
    private readonly array $sources;

    /**
     * @param LdapClientInterface|list<array{id:int,label:string,client:LdapClientInterface}> $sources
     *        ein einzelner Client (Hauptquelle) oder die Liste aller Quellen
     */
    public function __construct(
        LdapClientInterface|array $sources,
        private readonly PhonebookStoreInterface $store,
        private readonly SyncLogStoreInterface $syncLog,
        private readonly Logger $logger,
        private readonly ?AdGroupStoreInterface $groups = null
    ) {
        $this->sources = $sources instanceof LdapClientInterface
            ? [['id' => 0, 'label' => 'Active Directory', 'client' => $sources]]
            : array_values($sources);
    }

    /**
     * @return array{status:string,processed:int,deactivated:int,message:?string,groups:?int,sources:list<array{id:int,label:string,status:string,processed:int,deactivated:int,message:?string,groups:?int}>}
     */
    public function run(): array
    {
        $runId = $this->syncLog->start();
        $syncedAt = date('Y-m-d H:i:s');

        $results = [];
        foreach ($this->sources as $source) {
            $results[] = ['id' => $source['id'], 'label' => $source['label']] + $this->syncSource($source, $syncedAt);
        }

        $processed = array_sum(array_column($results, 'processed'));
        $deactivated = array_sum(array_column($results, 'deactivated'));
        $groupCounts = array_filter(array_column($results, 'groups'), static fn (?int $count): bool => $count !== null);
        $groupCount = $groupCounts === [] ? null : array_sum($groupCounts);
        $failed = array_values(array_filter($results, static fn (array $result): bool => $result['status'] !== 'success'));

        if (count($results) === 1) {
            $single = $results[0];
            $this->syncLog->finish($runId, $single['status'], $single['processed'], $single['deactivated'], $single['log']);

            return $this->publicResult($single['status'], $single['processed'], $single['deactivated'], $single['message'], $single['groups'], $results);
        }

        if ($failed === []) {
            $status = 'success';
            $message = null;
        } else {
            // Teilerfolg wird im Protokoll als Fehler gefuehrt, damit die
            // Ueberwachung (SNMP) den Ausfall einer Quelle meldet.
            $status = count($failed) === count($results) ? 'error' : 'partial';
            $message = implode(' ', array_map(
                static fn (array $result): string => sprintf('[%s] %s', $result['label'], (string) $result['log']),
                $failed
            ));
        }

        $this->syncLog->finish($runId, $status === 'success' ? 'success' : 'error', $processed, $deactivated, $message);
        $this->logger->info('AD-Synchronisation aller Identitätsquellen abgeschlossen.', [
            'status' => $status,
            'processed' => $processed,
            'deactivated' => $deactivated,
            'groups' => $groupCount,
        ]);

        return $this->publicResult(
            $status,
            $processed,
            $deactivated,
            $message === null ? null : 'AD-Synchronisation unvollständig: ' . $message,
            $groupCount,
            $results
        );
    }

    /**
     * @param array{id:int,label:string,client:LdapClientInterface} $source
     *
     * @return array{status:string,processed:int,deactivated:int,message:?string,log:?string,groups:?int}
     */
    private function syncSource(array $source, string $syncedAt): array
    {
        $client = $source['client'];
        $sourceId = $source['id'];
        $context = count($this->sources) > 1 ? ' (' . $source['label'] . ')' : '';

        try {
            $users = $client->fetchUsers();
        } catch (Throwable $exception) {
            $message = 'AD-Synchronisation fehlgeschlagen' . $context . ': ' . $exception->getMessage();
            $this->logger->error($message);

            return $this->failure($message, $exception->getMessage());
        }

        if ($users === []) {
            $message = 'AD-Synchronisation' . $context . ' lieferte keine Datensätze – bestehender Datenbestand bleibt unverändert.';
            $this->logger->warning($message);

            return $this->failure($message, 'Keine Datensätze geliefert.');
        }

        // Gruppen sind optional: Schlaegt ihr Abruf fehl, werden die Benutzer
        // trotzdem synchronisiert und der letzte Gruppenbestand bleibt erhalten.
        $groups = null;
        if ($this->groups !== null) {
            try {
                $groups = $client->fetchGroups();
            } catch (Throwable $exception) {
                $this->logger->warning('AD-Gruppen' . $context . ' konnten nicht gelesen werden – bisheriger Gruppenbestand bleibt erhalten: ' . $exception->getMessage());
            }
        }

        try {
            $this->store->beginTransaction();

            $processed = 0;
            foreach ($users as $user) {
                if (!isset($user['external_id']) || (string) $user['external_id'] === '') {
                    continue;
                }

                $user['identity_source_id'] = $sourceId;
                $this->store->upsert($user, $syncedAt);
                $processed++;
            }

            $deactivated = $processed > 0 ? $this->store->deactivateStale($syncedAt, $sourceId) : 0;
            $groupCount = $groups !== null && $this->groups !== null
                ? $this->groups->replaceAll($groups, $syncedAt, $sourceId)
                : null;
            $this->store->commit();
        } catch (Throwable $exception) {
            $this->store->rollBack();
            $message = 'AD-Synchronisation' . $context . ' konnte nicht gespeichert werden: ' . $exception->getMessage();
            $this->logger->error($message);

            return $this->failure($message, $exception->getMessage());
        }

        $this->logger->info('AD-Synchronisation' . $context . ' erfolgreich.', [
            'processed' => $processed,
            'deactivated' => $deactivated,
            'groups' => $groupCount,
        ]);

        return [
            'status' => 'success',
            'processed' => $processed,
            'deactivated' => $deactivated,
            'message' => null,
            'log' => null,
            'groups' => $groupCount,
        ];
    }

    /**
     * @return array{status:string,processed:int,deactivated:int,message:?string,log:?string,groups:?int}
     */
    private function failure(string $message, string $log): array
    {
        return ['status' => 'error', 'processed' => 0, 'deactivated' => 0, 'message' => $message, 'log' => $log, 'groups' => null];
    }

    /**
     * @param list<array<string,mixed>> $results
     *
     * @return array{status:string,processed:int,deactivated:int,message:?string,groups:?int,sources:list<array{id:int,label:string,status:string,processed:int,deactivated:int,message:?string,groups:?int}>}
     */
    private function publicResult(string $status, int $processed, int $deactivated, ?string $message, ?int $groups, array $results): array
    {
        $sources = [];
        foreach ($results as $result) {
            unset($result['log']);
            $sources[] = $result;
        }

        /** @var list<array{id:int,label:string,status:string,processed:int,deactivated:int,message:?string,groups:?int}> $sources */
        return [
            'status' => $status,
            'processed' => $processed,
            'deactivated' => $deactivated,
            'message' => $message,
            'groups' => $groups,
            'sources' => $sources,
        ];
    }
}
