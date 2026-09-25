<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * App-Pakete und AD-Gruppen-Freigaben der Office-Apps.
 */
final class OfficeAppRepository extends Repository
{
    /**
     * App-Schluessel, die fuer mindestens eine der Gruppen freigegeben sind
     * (direkt oder ueber ein Paket). Gruppennamen ohne Beachtung der
     * Gross-/Kleinschreibung.
     *
     * @param list<string> $groups
     *
     * @return list<string>
     */
    public function appKeysForGroups(array $groups): array
    {
        $groups = array_values(array_unique(array_filter(array_map(
            static fn (mixed $group): string => mb_strtolower(trim((string) $group)),
            $groups
        ), static fn (string $group): bool => $group !== '')));
        if ($groups === []) {
            return [];
        }

        // Getrennte Platzhalter je Teilabfrage (native Prepares erlauben
        // keine Wiederverwendung benannter Platzhalter).
        $bindings = [];
        $direct = [];
        $viaPackage = [];
        foreach ($groups as $index => $group) {
            $direct[] = 'LOWER(:d' . $index . ')';
            $viaPackage[] = 'LOWER(:p' . $index . ')';
            $bindings['d' . $index] = $group;
            $bindings['p' . $index] = $group;
        }

        $statement = $this->pdo->prepare(
            'SELECT p.app_key AS app_key FROM office_app_permissions p
              WHERE p.app_key IS NOT NULL AND LOWER(p.group_name) IN (' . implode(', ', $direct) . ')
             UNION
             SELECT pa.app_key AS app_key FROM office_app_permissions p
               JOIN office_app_package_apps pa ON pa.package_id = p.package_id
              WHERE p.package_id IS NOT NULL AND LOWER(p.group_name) IN (' . implode(', ', $viaPackage) . ')'
        );
        $statement->execute($bindings);

        return array_values(array_unique(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN) ?: [])));
    }

    /**
     * Direkt einer App zugeordnete Gruppen.
     *
     * @return array<string,list<string>> app_key => Gruppennamen
     */
    public function directGroupsByApp(): array
    {
        $statement = $this->pdo->query(
            'SELECT app_key, group_name FROM office_app_permissions WHERE app_key IS NOT NULL ORDER BY group_name ASC, id ASC'
        );
        $map = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            $map[(string) $row['app_key']][] = (string) $row['group_name'];
        }

        return $map;
    }

    /**
     * Ersetzt die direkten Gruppen-Freigaben einer App.
     *
     * @param list<string> $groups
     */
    public function replaceAppGroups(string $appKey, array $groups): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM office_app_permissions WHERE app_key = :app')->execute(['app' => $appKey]);
            $insert = $this->pdo->prepare('INSERT INTO office_app_permissions (group_name, app_key) VALUES (:group_name, :app)');
            foreach ($groups as $group) {
                $insert->execute(['group_name' => $group, 'app' => $appKey]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }

    /**
     * Alle Pakete inkl. Apps und Gruppen.
     *
     * @return list<array{id:int,name:string,description:string,apps:list<string>,groups:list<string>}>
     */
    public function packages(): array
    {
        $statement = $this->pdo->query('SELECT id, name, description FROM office_app_packages ORDER BY name ASC, id ASC');
        $packages = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            $packages[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'apps' => [],
                'groups' => [],
            ];
        }
        if ($packages === []) {
            return [];
        }

        $apps = $this->pdo->query('SELECT package_id, app_key FROM office_app_package_apps ORDER BY app_key ASC');
        foreach ($apps === false ? [] : $apps->fetchAll() as $row) {
            if (isset($packages[(int) $row['package_id']])) {
                $packages[(int) $row['package_id']]['apps'][] = (string) $row['app_key'];
            }
        }

        $groups = $this->pdo->query(
            'SELECT package_id, group_name FROM office_app_permissions WHERE package_id IS NOT NULL ORDER BY group_name ASC, id ASC'
        );
        foreach ($groups === false ? [] : $groups->fetchAll() as $row) {
            if (isset($packages[(int) $row['package_id']])) {
                $packages[(int) $row['package_id']]['groups'][] = (string) $row['group_name'];
            }
        }

        return array_values($packages);
    }

    /**
     * @return array{id:int,name:string,description:string,apps:list<string>,groups:list<string>}|null
     */
    public function findPackage(int $id): ?array
    {
        foreach ($this->packages() as $package) {
            if ($package['id'] === $id) {
                return $package;
            }
        }

        return null;
    }

    public function packageNameExists(string $name, ?int $exceptId = null): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM office_app_packages WHERE LOWER(name) = LOWER(:name)');
        $statement->execute(['name' => $name]);
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            if ($exceptId === null || (int) $id !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legt ein Paket an bzw. aktualisiert es (Apps und Gruppen werden ersetzt).
     *
     * @param list<string> $apps
     * @param list<string> $groups
     */
    public function savePackage(?int $id, string $name, string $description, array $apps, array $groups): int
    {
        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO office_app_packages (name, description) VALUES (:name, :description)')
                    ->execute(['name' => $name, 'description' => $description]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare('UPDATE office_app_packages SET name = :name, description = :description WHERE id = :id')
                    ->execute(['name' => $name, 'description' => $description, 'id' => $id]);
                $this->pdo->prepare('DELETE FROM office_app_package_apps WHERE package_id = :id')->execute(['id' => $id]);
                $this->pdo->prepare('DELETE FROM office_app_permissions WHERE package_id = :id')->execute(['id' => $id]);
            }

            $insertApp = $this->pdo->prepare('INSERT INTO office_app_package_apps (package_id, app_key) VALUES (:id, :app)');
            foreach ($apps as $app) {
                $insertApp->execute(['id' => $id, 'app' => $app]);
            }

            $insertGroup = $this->pdo->prepare('INSERT INTO office_app_permissions (group_name, package_id) VALUES (:group_name, :id)');
            foreach ($groups as $group) {
                $insertGroup->execute(['group_name' => $group, 'id' => $id]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return $id;
    }

    public function deletePackage(int $id): void
    {
        // Explizit loeschen, falls Fremdschluessel (z. B. SQLite) nicht greifen.
        $this->pdo->prepare('DELETE FROM office_app_permissions WHERE package_id = :id')->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM office_app_package_apps WHERE package_id = :id')->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM office_app_packages WHERE id = :id')->execute(['id' => $id]);
    }
}
