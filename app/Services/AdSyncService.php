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
    /**
     * Konten je Schreibblock. Eine kleinere Einheit als der gesamte Lauf
     * begrenzt Sperr- und Undo-Log-Dauer; siehe syncSource().
     */
    private const BLOCK_SIZE = 200;

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
        private readonly ?AdGroupStoreInterface $groups = null,
        private readonly ?\Closure $onDeactivated = null
    ) {
        $this->sources = $sources instanceof LdapClientInterface
            ? [['id' => 0, 'label' => 'Active Directory', 'client' => $sources]]
            : array_values($sources);
    }

    /**
     * Quellen in der Reihenfolge, in der sie synchronisiert werden.
     *
     * @return list<array{id:int,label:string}>
     */
    public function sources(): array
    {
        return array_map(
            static fn (array $source): array => ['id' => $source['id'], 'label' => $source['label']],
            $this->sources
        );
    }

    /**
     * @param (callable(string,array<string,mixed>):void)|null $onProgress
     *        erhaelt vor jeder Quelle ('start', {id,label}) und danach
     *        ('done', Ergebnis der Quelle) – z. B. fuer die Fortschrittsanzeige
     *
     * @return array{status:string,processed:int,deactivated:int,message:?string,groups:?int,sources:list<array{id:int,label:string,status:string,processed:int,deactivated:int,message:?string,groups:?int,warning:?string}>}
     */
    public function run(?callable $onProgress = null): array
    {
        $runId = $this->syncLog->start();
        $syncedAt = date('Y-m-d H:i:s');

        $results = [];
        foreach ($this->sources as $source) {
            if ($onProgress !== null) {
                $onProgress('start', ['id' => $source['id'], 'label' => $source['label']]);
            }
            $result = ['id' => $source['id'], 'label' => $source['label']] + $this->syncSource($source, $syncedAt);
            $results[] = $result;
            if ($onProgress !== null) {
                unset($result['log']);
                $onProgress('done', $result);
            }
        }

        $processed = array_sum(array_column($results, 'processed'));
        $deactivated = array_sum(array_column($results, 'deactivated'));
        if ($deactivated > 0 && $this->onDeactivated !== null) {
            // Abhaengige Caches (z. B. Mail-Proxy-Zuordnungen) duerfen
            // deaktivierte Benutzer nicht weiter verwenden.
            try {
                ($this->onDeactivated)($deactivated);
            } catch (\Throwable $exception) {
                $this->logger->warning('Nachbearbeitung deaktivierter Benutzer fehlgeschlagen.', ['error' => $exception->getMessage()]);
            }
        }
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
     * @return array{status:string,processed:int,deactivated:int,message:?string,log:?string,groups:?int,warning:?string}
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
        $warning = null;
        if ($this->groups !== null) {
            try {
                $groups = $client->fetchGroups();
            } catch (Throwable $exception) {
                $warning = 'AD-Gruppen konnten nicht gelesen werden – bisheriger Gruppenbestand bleibt erhalten: ' . $exception->getMessage();
                $this->logger->warning('AD-Gruppen' . $context . ' konnten nicht gelesen werden – bisheriger Gruppenbestand bleibt erhalten: ' . $exception->getMessage());
            }
        }

        try {
            // Blockweise schreiben statt alles in einer Transaktion: bei rund
            // 1500 Konten entsteht sonst eine sehr lange offene Transaktion
            // (Undo-Log, Sperren auf phonebook) und im Fehlerfall ist der
            // gesamte Lauf verloren. $syncedAt gilt fuer den ganzen Lauf -
            // nur so erkennt deactivateStale() die bereits geschriebenen
            // Bloecke als aktuell und deaktiviert sie nicht wieder.
            $processed = 0;
            $block = [];
            foreach ($users as $user) {
                if (!isset($user['external_id']) || (string) $user['external_id'] === '') {
                    continue;
                }

                $user['identity_source_id'] = $sourceId;
                $block[] = $user;
                if (count($block) >= self::BLOCK_SIZE) {
                    $this->writeBlock($block, $syncedAt);
                    $processed += count($block);
                    $block = [];
                }
            }
            if ($block !== []) {
                $this->writeBlock($block, $syncedAt);
                $processed += count($block);
            }

            // Abgleich und Gruppen in einer Transaktion: erst nachdem alle
            // Bloecke festgeschrieben sind, werden nicht mehr gelieferte
            // Konten deaktiviert.
            $this->store->beginTransaction();
            try {
                $deactivated = $processed > 0 ? $this->store->deactivateStale($syncedAt, $sourceId) : 0;
                $groupCount = $groups !== null && $this->groups !== null
                    ? $this->groups->replaceAll($groups, $syncedAt, $sourceId)
                    : null;
                $this->store->commit();
            } catch (Throwable $exception) {
                $this->store->rollBack();
                throw $exception;
            }
        } catch (Throwable $exception) {
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
            'warning' => $warning,
        ];
    }

    /**
     * Schreibt einen Block Konten in einer eigenen Transaktion. Bricht der
     * Lauf spaeter ab, bleiben die bereits geschriebenen Bloecke gueltig; der
     * Aufrufer meldet den Lauf als Fehler, und deactivateStale() laeuft nicht -
     * es verschwindet also kein Konto aus dem Telefonbuch.
     *
     * @param list<array<string,string|null>> $block
     */
    private function writeBlock(array $block, string $syncedAt): void
    {
        $this->store->beginTransaction();
        try {
            $this->store->upsertMany($block, $syncedAt);
            $this->store->commit();
        } catch (Throwable $exception) {
            $this->store->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array{status:string,processed:int,deactivated:int,message:?string,log:?string,groups:?int,warning:?string}
     */
    private function failure(string $message, string $log): array
    {
        return ['status' => 'error', 'processed' => 0, 'deactivated' => 0, 'message' => $message, 'log' => $log, 'groups' => null, 'warning' => null];
    }

    /**
     * @param list<array<string,mixed>> $results
     *
     * @return array{status:string,processed:int,deactivated:int,message:?string,groups:?int,sources:list<array{id:int,label:string,status:string,processed:int,deactivated:int,message:?string,groups:?int,warning:?string}>}
     */
    private function publicResult(string $status, int $processed, int $deactivated, ?string $message, ?int $groups, array $results): array
    {
        $sources = [];
        foreach ($results as $result) {
            unset($result['log']);
            $sources[] = $result;
        }

        /** @var list<array{id:int,label:string,status:string,processed:int,deactivated:int,message:?string,groups:?int,warning:?string}> $sources */
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
