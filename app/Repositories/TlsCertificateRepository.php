<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Zugriff auf die Tabelle tls_certificates (CSRs, importierte Zertifikate,
 * Notfall-Zertifikate).
 */
final class TlsCertificateRepository extends Repository
{
    /**
     * Alle Eintraege, neueste zuerst.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM tls_certificates ORDER BY created_at DESC, id DESC');

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tls_certificates WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * CSR, zu dem ein oeffentlicher Schluessel gehoert (neuester zuerst).
     *
     * @return array<string,mixed>|null
     */
    public function findRequestByPublicKey(string $hash): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM tls_certificates WHERE kind = 'csr' AND public_key_hash = :hash ORDER BY id DESC LIMIT 1"
        );
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findActive(): ?array
    {
        $statement = $this->pdo->query(
            "SELECT * FROM tls_certificates WHERE kind = 'csr' AND active = 1 ORDER BY id DESC LIMIT 1"
        );
        $row = $statement === false ? false : $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latestFallback(): ?array
    {
        $statement = $this->pdo->query(
            "SELECT * FROM tls_certificates WHERE kind = 'fallback' ORDER BY id DESC LIMIT 1"
        );
        $row = $statement === false ? false : $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function insert(array $values): int
    {
        $columns = array_keys($values);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO tls_certificates (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns))
        ));
        $statement->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $values
     */
    public function update(int $id, array $values): void
    {
        if ($values === []) {
            return;
        }

        $assignments = array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($values));
        $statement = $this->pdo->prepare(sprintf('UPDATE tls_certificates SET %s WHERE id = :id', implode(', ', $assignments)));
        $statement->execute($values + ['id' => $id]);
    }

    /**
     * Setzt genau einen Eintrag aktiv (oder keinen bei null).
     */
    public function activate(?int $id, int $now): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("UPDATE tls_certificates SET active = 0 WHERE kind = 'csr' AND active = 1");
            if ($id !== null) {
                $statement = $this->pdo->prepare(
                    "UPDATE tls_certificates SET active = 1, activated_at = :now WHERE id = :id AND kind = 'csr'"
                );
                $statement->execute(['id' => $id, 'now' => $now]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }

    /**
     * Vermerkt, dass der auth-Container diesen Eintrag gerade ausliefert.
     */
    public function markUsed(int $id, int $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tls_certificates SET last_used_at = :now, first_used_at = COALESCE(first_used_at, :first) WHERE id = :id'
        );
        $statement->execute(['id' => $id, 'now' => $now, 'first' => $now]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM tls_certificates WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
