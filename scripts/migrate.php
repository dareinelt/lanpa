<?php

declare(strict_types=1);

/**
 * Fuehrt alle noch nicht angewendeten SQL-Migrationen aus.
 *
 * Aufruf: php scripts/migrate.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration VARCHAR(190) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_schema_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')?->fetchAll(PDO::FETCH_COLUMN) ?: [];
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);

$count = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    $sql = (string) file_get_contents($file);
    // Kommentarzeilen entfernen, damit sie die Anweisungstrennung nicht stören.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $statements = array_filter(
        array_map('trim', preg_split('/;\s*\R/', $sql) ?: []),
        static fn (string $statement): bool => $statement !== ''
    );

    foreach ($statements as $statement) {
        $statement = rtrim($statement, ";\n\r\t ");
        if ($statement === '') {
            continue;
        }

        $pdo->exec($statement);
    }

    $insert = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
    $insert->execute(['migration' => $name]);

    fwrite(STDOUT, 'Migration angewendet: ' . $name . PHP_EOL);
    $count++;
}

fwrite(STDOUT, $count === 0 ? 'Keine offenen Migrationen.' . PHP_EOL : $count . ' Migration(en) ausgeführt.' . PHP_EOL);
