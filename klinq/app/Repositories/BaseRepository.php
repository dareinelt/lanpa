<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

abstract class BaseRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Bereitet ein Statement vor und führt es aus. Da native Prepares (ohne Emulation) keine
     * mehrfach verwendeten benannten Platzhalter erlauben, werden Wiederholungen wie ":q … :q"
     * automatisch zu ":q, :q__1, :q__2" expandiert und die Werte entsprechend dupliziert.
     *
     * @param array<int|string,mixed> $params
     */
    protected function run(string $sql, array $params = []): \PDOStatement
    {
        if ($params !== [] && !array_is_list($params)) {
            [$sql, $params] = self::expandRepeatedPlaceholders($sql, $params);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param array<string,mixed> $params
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function expandRepeatedPlaceholders(string $sql, array $params): array
    {
        $counts = [];
        $out = [];
        $sql = (string) preg_replace_callback('/(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/', static function (array $m) use (&$counts, &$out, $params): string {
            $name = $m[1];
            $key = array_key_exists($name, $params) ? $name : (array_key_exists(':' . $name, $params) ? ':' . $name : null);
            if ($key === null) {
                return $m[0];
            }
            $n = $counts[$name] = ($counts[$name] ?? -1) + 1;
            $alias = $n === 0 ? $name : $name . '__' . $n;
            $out[$alias] = $params[$key];

            return ':' . $alias;
        }, $sql);

        return [$sql, $out === [] ? $params : $out];
    }

    /** @param array<int|string,mixed> $params @return array<int,array<string,mixed>> */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    protected function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<int|string,mixed> $params */
    protected function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    protected function insertRow(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->execute($sql, array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    protected function updateRow(string $table, int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($data)));

        return $this->execute("UPDATE {$table} SET {$set} WHERE id = ?", [...array_values($data), $id]);
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            // Verschachtelt: Savepoint, damit ein Fehler nur den inneren Block zurückrollt
            $savepoint = 'sp_' . str_replace('.', '_', uniqid('', true));
            $this->pdo->exec("SAVEPOINT {$savepoint}");
            try {
                $result = $callback();
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");

                return $result;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                }
                throw $e;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    protected function like(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    }
}
