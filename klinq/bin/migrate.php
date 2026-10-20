<?php

declare(strict_types=1);

/**
 * Führt ausstehende SQL-Migrationen (database/migrations) und alle Seeder
 * (database/seeders, idempotent) aus. Legt bei leerer Benutzertabelle den
 * initialen Administrator aus ADMIN_USERNAME / ADMIN_PASSWORD an.
 *
 * Aufruf: php bin/migrate.php [--database=<name>] [--no-seed] [--no-admin]
 */

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Security\PasswordHasher;
use App\Support\ColognePhonetic;

$basePath = dirname(__DIR__);
Env::load($basePath . '/.env');
$config = new Config($basePath . '/config');

$options = getopt('', ['database::', 'no-seed', 'no-admin', 'fresh']);
$database = isset($options['database']) && $options['database'] !== false ? (string) $options['database'] : null;

if ($database !== null) {
    $server = Database::connect($config, 'information_schema');
    if (isset($options['fresh'])) {
        $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $database) . '`');
    }
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $database) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

$pdo = Database::connect($config, $database);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$insert = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');

$files = glob($basePath . '/database/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $migration) {
    $name = basename($migration);
    if (in_array($name, $applied, true)) {
        continue;
    }
    echo "Migration: {$name}\n";
    $pdo->exec((string) file_get_contents($migration));
    $insert->execute([$name]);
}

// Backfill: phonetische Schlüssel für Artikel, die vor Migration 005 angelegt wurden
$missing = $pdo->query("SELECT id, name FROM articles WHERE normalized_name = '' OR phonetic_key = ''")->fetchAll(PDO::FETCH_ASSOC);
if ($missing !== []) {
    $update = $pdo->prepare('UPDATE articles SET normalized_name = ?, phonetic_key = ? WHERE id = ?');
    foreach ($missing as $row) {
        $update->execute([
            mb_substr(ColognePhonetic::normalizedArticleName((string) $row['name']), 0, 200),
            mb_substr(ColognePhonetic::encode((string) $row['name']), 0, 120),
            (int) $row['id'],
        ]);
    }
    echo 'Artikel-Phonetik nachgetragen: ' . count($missing) . "\n";
}

if (!isset($options['no-seed'])) {
    $seeders = glob($basePath . '/database/seeders/*.sql') ?: [];
    sort($seeders);
    foreach ($seeders as $seeder) {
        echo 'Seeder: ' . basename($seeder) . "\n";
        $pdo->exec((string) file_get_contents($seeder));
    }
}

if (!isset($options['no-admin'])) {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $adminUser = (string) $config->get('app.admin.username', 'admin');
    $adminPassword = (string) $config->get('app.admin.password', '');
    if ($count === 0 && $adminPassword !== '') {
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role_id, auth_source) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$adminUser, 'Administrator', (new PasswordHasher())->hash($adminPassword), $roleId, 'local']);
        echo "Initialer Administrator '{$adminUser}' angelegt.\n";
    } elseif ($count === 0) {
        echo "Hinweis: Kein Benutzer vorhanden und ADMIN_PASSWORD nicht gesetzt – bitte .env prüfen.\n";
    }
}

echo "Fertig.\n";
