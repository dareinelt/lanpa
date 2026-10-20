<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\ApplicationFactory;
use App\Core\Config;
use App\Core\Container;
use App\Services\SettingsService;
use PDO;

/**
 * Basis für Integrationstests gegen die Test-Datenbank (Standard: <DB_DATABASE>_test).
 * Die Datenbank muss migriert sein: php bin/migrate.php --database=assets_test --fresh --no-admin
 * Jeder Test läuft in einer Transaktion, die anschließend zurückgerollt wird.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static ?Container $container = null;
    private static ?string $skipReason = null;
    protected Container $c;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->c = self::sharedContainer();
        $this->pdo = $this->c->get(PDO::class);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->c)) {
            $this->c->get(SettingsService::class)->refresh(); // Cache überlebt sonst den Rollback
        }
    }

    private function sharedContainer(): Container
    {
        if (self::$skipReason !== null) {
            $this->markSkipped(self::$skipReason);
        }
        if (self::$container === null) {
            $basePath = dirname(__DIR__, 2);
            try {
                $c = ApplicationFactory::container($basePath, null);
                $testDb = (string) $c->get(Config::class)->get('database.test_database');
                $c = ApplicationFactory::container($basePath, $testDb);
                $pdo = $c->get(PDO::class);
                $pdo->query('SELECT 1 FROM schema_migrations LIMIT 1');
                self::$container = $c;
            } catch (\Throwable $e) {
                self::$skipReason = 'Test-Datenbank nicht erreichbar: ' . $e->getMessage();
                $this->markSkipped(self::$skipReason);
            }
        }

        return self::$container;
    }
}
